<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_SCHEMA_MAINTENANCE_HOOK')) {
    define('LL_TOOLS_SCHEMA_MAINTENANCE_HOOK', 'll_tools_schema_maintenance_run');
}

/**
 * Return the schema repair callbacks that may be scheduled by public readers.
 *
 * The callback still owns its marker/retry checks. Keeping this whitelist in
 * one always-loaded module prevents a request value from selecting an
 * arbitrary callable.
 *
 * @return array<string,string>
 */
function ll_tools_schema_maintenance_callbacks(): array {
    $callbacks = [
        'offline_app_sessions' => 'll_tools_maybe_install_offline_app_session_schema',
        'user_progress' => 'll_tools_maybe_upgrade_user_progress_schema',
        'dictionary_lookup' => 'll_tools_maybe_upgrade_dictionary_lookup_schema',
        'wordset_category_search' => 'll_tools_maybe_upgrade_wordset_category_search_schema',
        'image_match_index' => 'll_tools_image_match_index_maybe_upgrade',
    ];

    /**
     * Filter the internal schema-maintenance callback map.
     *
     * This exists primarily so integration tests can exercise the scheduler
     * without mutating a production schema. Callbacks remain trusted plugin
     * code; request values cannot add entries to this map.
     *
     * @param array<string,string> $callbacks
     */
    $callbacks = apply_filters('ll_tools_schema_maintenance_callbacks', $callbacks);

    return is_array($callbacks) ? $callbacks : [];
}

function ll_tools_schema_maintenance_normalize_key(string $schema_key): string {
    $schema_key = sanitize_key($schema_key);
    return array_key_exists($schema_key, ll_tools_schema_maintenance_callbacks())
        ? $schema_key
        : '';
}

/**
 * Schema DDL is permitted only at an explicit maintenance boundary.
 */
function ll_tools_schema_maintenance_upgrade_is_allowed(): bool {
    $allowed = false;
    if (defined('WP_TESTS_DOMAIN')) {
        $allowed = true;
    } elseif (defined('WP_CLI') && WP_CLI) {
        $allowed = true;
    } elseif (function_exists('wp_doing_cron') && wp_doing_cron()) {
        $allowed = true;
    } elseif (is_admin()) {
        $allowed = current_user_can('view_ll_tools')
            || current_user_can('manage_options');
    }

    return (bool) apply_filters('ll_tools_schema_maintenance_upgrade_is_allowed', $allowed);
}

/**
 * Coalesce a schema repair request behind one WP-Cron event per schema.
 */
function ll_tools_schedule_schema_maintenance(string $schema_key, int $delay = 5): bool {
    $schema_key = ll_tools_schema_maintenance_normalize_key($schema_key);
    if ($schema_key === '') {
        return false;
    }

    $args = [$schema_key];
    if (wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, $args)) {
        return true;
    }

    $delay = max(1, min(DAY_IN_SECONDS, $delay));
    return wp_schedule_single_event(
        time() + $delay,
        LL_TOOLS_SCHEMA_MAINTENANCE_HOOK,
        $args
    ) !== false;
}

function ll_tools_schema_maintenance_lock_option(string $schema_key): string {
    return 'll_tools_schema_lock_' . substr(hash('sha256', $schema_key), 0, 24);
}

/**
 * Acquire an expiring exact-owner lease for one schema installer.
 *
 * @return array{acquired:bool,replaced:bool,option_name:string,value:string}
 */
function ll_tools_acquire_schema_maintenance_lease(string $schema_key, int $ttl = 0): array {
    global $wpdb;

    $schema_key = ll_tools_schema_maintenance_normalize_key($schema_key);
    if ($schema_key === '') {
        return [
            'acquired' => false,
            'replaced' => false,
            'option_name' => '',
            'value' => '',
        ];
    }

    if ($ttl <= 0) {
        $ttl = (int) apply_filters(
            'll_tools_schema_maintenance_lease_ttl',
            10 * MINUTE_IN_SECONDS,
            $schema_key
        );
    }
    $ttl = max(MINUTE_IN_SECONDS, min(HOUR_IN_SECONDS, $ttl));
    $option_name = ll_tools_schema_maintenance_lock_option($schema_key);
    $now = time();
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $schema_key . '|' . microtime(true) . '|' . wp_rand());
    $value = ($now + $ttl) . '|' . $token;

    if (add_option($option_name, $value, '', false)) {
        return [
            'acquired' => true,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => $value,
        ];
    }

    $current = (string) get_option($option_name, '');
    $separator = strpos($current, '|');
    $expires_at = $separator === false
        ? (int) $current
        : (int) substr($current, 0, $separator);
    if ($expires_at > $now) {
        return [
            'acquired' => false,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => '',
        ];
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $value,
        $option_name,
        $current
    ));
    wp_cache_delete($option_name, 'options');

    return [
        'acquired' => $updated === 1,
        'replaced' => $updated === 1,
        'option_name' => $option_name,
        'value' => $updated === 1 ? $value : '',
    ];
}

/**
 * Release only the exact lease value acquired by this worker.
 */
function ll_tools_release_schema_maintenance_lease(array $lease): void {
    global $wpdb;

    $option_name = (string) ($lease['option_name'] ?? '');
    $value = (string) ($lease['value'] ?? '');
    if ($option_name === '' || $value === '') {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s AND option_value = %s",
        $option_name,
        $value
    ));
    wp_cache_delete($option_name, 'options');
}

/**
 * Run one installer only after request admission and exact-owner acquisition.
 *
 * @param callable():bool $is_current Marker-only readiness check.
 * @param callable():bool $installer  Full schema installer/readback.
 */
function ll_tools_maybe_run_schema_maintenance(
    string $schema_key,
    callable $is_current,
    callable $installer,
    int $retry_delay = 5 * MINUTE_IN_SECONDS
): bool {
    $schema_key = ll_tools_schema_maintenance_normalize_key($schema_key);
    if ($schema_key === '') {
        return false;
    }
    if ((bool) $is_current()) {
        return true;
    }
    if (!ll_tools_schema_maintenance_upgrade_is_allowed()) {
        ll_tools_schedule_schema_maintenance($schema_key, 5);
        return false;
    }

    $lease = ll_tools_acquire_schema_maintenance_lease($schema_key);
    if (empty($lease['acquired'])) {
        ll_tools_schedule_schema_maintenance($schema_key, 30);
        return false;
    }

    try {
        // Another owner may have completed between the first marker read and
        // this lease acquisition. Avoid repeating its DDL.
        if ((bool) $is_current()) {
            return true;
        }

        $installed = (bool) $installer();
        if (!$installed) {
            ll_tools_schedule_schema_maintenance($schema_key, $retry_delay);
        }
        return $installed;
    } finally {
        ll_tools_release_schema_maintenance_lease($lease);
    }
}

/**
 * Dispatch one trusted scheduled schema callback.
 */
function ll_tools_run_scheduled_schema_maintenance(string $schema_key): void {
    $schema_key = ll_tools_schema_maintenance_normalize_key($schema_key);
    if ($schema_key === '') {
        return;
    }

    $callbacks = ll_tools_schema_maintenance_callbacks();
    $callback = (string) ($callbacks[$schema_key] ?? '');
    if ($callback !== '' && is_callable($callback)) {
        call_user_func($callback);
    }
}
add_action(
    LL_TOOLS_SCHEMA_MAINTENANCE_HOOK,
    'll_tools_run_scheduled_schema_maintenance',
    10,
    1
);
