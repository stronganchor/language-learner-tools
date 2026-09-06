<?php
if (!defined('WPINC')) { die; }

/**
 * Normalize a transient-key prefix owned by LL Tools.
 */
function ll_tools_public_ajax_guard_prefix(string $prefix): string {
    $prefix = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix));
    return is_string($prefix) ? substr($prefix, 0, 80) : '';
}

/**
 * Return the fixed-window option names used by an atomic request counter.
 *
 * @return array{value:string,timeout:string,key_prefix:string,expires_at:int}
 */
function ll_tools_public_ajax_counter_option_names(
    string $prefix,
    string $identifier,
    int $window,
    int $now = 0
): array {
    $prefix = ll_tools_public_ajax_guard_prefix($prefix);
    $window = max(1, $window);
    $now = $now > 0 ? $now : time();
    $bucket = (int) floor($now / $window);
    $key_prefix = $prefix . substr(hash('sha256', $identifier), 0, 24) . '_';
    $key = $key_prefix . $bucket;

    return [
        'value' => '_transient_' . $key,
        'timeout' => '_transient_timeout_' . $key,
        'key_prefix' => $key_prefix,
        'expires_at' => ($bucket + 1) * $window,
    ];
}

/**
 * Atomically reserve capacity in a fixed-window request counter.
 *
 * The insert-if-missing or conditional SQL increment is the admission
 * decision. Concurrent PHP workers therefore cannot all observe the same
 * stale transient value and independently admit work beyond the limit.
 *
 * @return array{allowed:bool,count:int,limit:int,retry_after:int,reserved:bool,option_name?:string,cost?:int}
 */
function ll_tools_public_ajax_reserve_counter(
    string $prefix,
    string $identifier,
    int $limit,
    int $window,
    int $cost = 1,
    int $now = 0
): array {
    global $wpdb;

    $identifier = trim($identifier);
    $limit = max(0, $limit);
    $cost = max(1, $cost);
    $window = max(1, $window);
    $now = $now > 0 ? $now : time();
    if ($identifier === '' || $limit <= 0) {
        return [
            'allowed' => true,
            'count' => 0,
            'limit' => $limit,
            'retry_after' => 0,
            'reserved' => false,
        ];
    }

    $names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, $window, $now);
    $retry_after = max(1, (int) $names['expires_at'] - $now);
    if ($cost > $limit) {
        return [
            'allowed' => false,
            'count' => $cost,
            'limit' => $limit,
            'retry_after' => $retry_after,
            'reserved' => false,
        ];
    }

    $counter_result = static function (bool $allowed, int $count, bool $reserved) use (
        $limit,
        $retry_after,
        $names,
        $cost
    ): array {
        return [
            'allowed' => $allowed,
            'count' => max(0, $count),
            'limit' => $limit,
            'retry_after' => $retry_after,
            'reserved' => $reserved,
            'option_name' => $names['value'],
            'cost' => $cost,
        ];
    };
    $serialized_cost = maybe_serialize((string) $cost);
    $autoload = function_exists('wp_determine_option_autoload_value')
        ? wp_determine_option_autoload_value($names['value'], (string) $cost, $serialized_cost, false)
        : 'no';
    $try_create = static function () use ($wpdb, $names, $serialized_cost, $autoload): array {
        $wpdb->last_error = '';
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, %s)",
            $names['value'],
            $serialized_cost,
            $autoload
        ));
        $create_error = (string) $wpdb->last_error;

        // Direct SQL must invalidate both the value cache and any remembered
        // miss. This is needed whether this worker inserted or lost the race.
        wp_cache_delete($names['value'], 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$names['value']])) {
            unset($notoptions[$names['value']]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }

        return [
            'created' => ($inserted === 1 && $create_error === ''),
            'error' => ($inserted === false || $create_error !== ''),
        ];
    };

    $creation = $try_create();
    if ($creation['error']) {
        return $counter_result(false, 0, false);
    }
    if ($creation['created']) {
        update_option($names['timeout'], (string) $names['expires_at'], false);
        return $counter_result(true, $cost, true);
    }

    // A fully refunded counter may be deleted after the insert observes it
    // but before the conditional increment reaches the database. Retry that
    // exact missing-row race once; all database errors remain fail-closed.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $wpdb->last_error = '';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CAST(option_value AS UNSIGNED) + %d
             WHERE option_name = %s
               AND CAST(option_value AS UNSIGNED) + %d <= %d",
            $cost,
            $names['value'],
            $cost,
            $limit
        ));
        $update_error = (string) $wpdb->last_error;
        wp_cache_delete($names['value'], 'options');
        if ($updated === false || $update_error !== '') {
            return $counter_result(false, 0, false);
        }

        $wpdb->last_error = '';
        $raw_count = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $names['value']
        ));
        $read_error = (string) $wpdb->last_error;
        if ($read_error !== '') {
            return $counter_result(false, 0, false);
        }

        $count = max(0, (int) $raw_count);
        if ($updated === 1 || $raw_count !== null || $attempt > 0) {
            return $counter_result($updated === 1, $count, $updated === 1);
        }

        $creation = $try_create();
        if ($creation['error']) {
            return $counter_result(false, 0, false);
        }
        if ($creation['created']) {
            update_option($names['timeout'], (string) $names['expires_at'], false);
            return $counter_result(true, $cost, true);
        }
    }

    return $counter_result(false, 0, false);
}

/**
 * Inspect the current fixed-window bucket without consuming capacity.
 *
 * @return array{allowed:bool,count:int,limit:int,retry_after:int,reserved:bool}
 */
function ll_tools_public_ajax_counter_status(
    string $prefix,
    string $identifier,
    int $limit,
    int $window,
    int $cost = 1,
    int $now = 0
): array {
    $identifier = trim($identifier);
    $limit = max(0, $limit);
    $cost = max(1, $cost);
    $window = max(1, $window);
    $now = $now > 0 ? $now : time();
    if ($identifier === '' || $limit <= 0) {
        return [
            'allowed' => true,
            'count' => 0,
            'limit' => $limit,
            'retry_after' => 0,
            'reserved' => false,
        ];
    }

    $names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, $window, $now);
    $count = max(0, (int) get_option($names['value'], 0));

    return [
        'allowed' => ($cost <= $limit && ($count + $cost) <= $limit),
        'count' => $count,
        'limit' => $limit,
        'retry_after' => max(1, (int) $names['expires_at'] - $now),
        'reserved' => false,
    ];
}

/**
 * Refund capacity from the exact fixed-window bucket returned by reserve_counter().
 *
 * A successful authentication refunds only its own reservation. It must not
 * reset the whole IP bucket because another worker may have recorded a real
 * failure for the same shared connection in the meantime.
 */
function ll_tools_public_ajax_refund_counter(array $reservation): bool {
    global $wpdb;

    if (empty($reservation['reserved'])) {
        return false;
    }

    $option_name = (string) ($reservation['option_name'] ?? '');
    $cost = max(0, (int) ($reservation['cost'] ?? 0));
    if ($cost <= 0 || strpos($option_name, '_transient_') !== 0) {
        return false;
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = CAST(option_value AS UNSIGNED) - %d
         WHERE option_name = %s
           AND CAST(option_value AS UNSIGNED) >= %d",
        $cost,
        $option_name,
        $cost
    ));
    wp_cache_delete($option_name, 'options');

    return $updated === 1;
}

/**
 * Delete fixed-window counters for one exact client identifier.
 */
function ll_tools_public_ajax_reset_counter(string $prefix, string $identifier): void {
    global $wpdb;

    $prefix = ll_tools_public_ajax_guard_prefix($prefix);
    $identifier = trim($identifier);
    if ($prefix === '' || $identifier === '') {
        return;
    }

    $key_prefix = $prefix . substr(hash('sha256', $identifier), 0, 24) . '_';
    $option_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_' . $key_prefix) . '%',
        $wpdb->esc_like('_transient_timeout_' . $key_prefix) . '%'
    ));
    foreach ((array) $option_names as $option_name) {
        delete_option((string) $option_name);
    }
}

/**
 * Acquire one of a bounded number of atomic, expiring client leases.
 *
 * @return array{
 *   acquired:bool,
 *   retry_after:int,
 *   option_name?:string,
 *   timeout_option_name?:string,
 *   lease_value?:string,
 *   released_value?:string,
 *   expires_at?:int
 * }
 */
function ll_tools_public_ajax_acquire_client_lease(
    string $prefix,
    string $identifier,
    int $limit,
    int $ttl,
    int $now = 0
): array {
    global $wpdb;

    $prefix = ll_tools_public_ajax_guard_prefix($prefix);
    $identifier = trim($identifier);
    $limit = max(0, min(20, $limit));
    $ttl = max(1, min(300, $ttl));
    $now = $now > 0 ? $now : time();
    if ($prefix === '' || $identifier === '' || $limit <= 0) {
        return [
            'acquired' => true,
            'retry_after' => 0,
        ];
    }

    $expires_at = $now + $ttl;
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $identifier . '|' . microtime(true) . '|' . wp_rand());
    $lease_value = $expires_at . '|' . $token;
    $released_value = '0|' . $token;
    $earliest_expiry = 0;

    for ($slot = 1; $slot <= $limit; $slot++) {
        $names = ll_tools_public_ajax_client_lease_option_names($prefix, $identifier, $slot);
        $option_name = $names['value'];
        $timeout_option_name = $names['timeout'];
        if (add_option($option_name, $lease_value, '', false)) {
            update_option($timeout_option_name, (string) $expires_at, false);
            return [
                'acquired' => true,
                'retry_after' => 0,
                'option_name' => $option_name,
                'timeout_option_name' => $timeout_option_name,
                'lease_value' => $lease_value,
                'released_value' => $released_value,
                'expires_at' => $expires_at,
            ];
        }

        $current_value = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));
        $separator = strpos($current_value, '|');
        $current_expiry = $separator === false
            ? (ctype_digit($current_value)
                ? (int) $current_value
                : (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                    $timeout_option_name
                )))
            : (int) substr($current_value, 0, $separator);
        if ($current_expiry > $now) {
            $earliest_expiry = $earliest_expiry > 0
                ? min($earliest_expiry, $current_expiry)
                : $current_expiry;
            continue;
        }

        // Take over an expired/released slot only if its exact value is still
        // the value observed above. This avoids deleting or replacing a lease
        // that another request acquired concurrently.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s AND option_value = %s",
            $lease_value,
            $option_name,
            $current_value
        ));
        wp_cache_delete($option_name, 'options');
        if ($updated === 1) {
            update_option($timeout_option_name, (string) $expires_at, false);
            return [
                'acquired' => true,
                'retry_after' => 0,
                'option_name' => $option_name,
                'timeout_option_name' => $timeout_option_name,
                'lease_value' => $lease_value,
                'released_value' => $released_value,
                'expires_at' => $expires_at,
            ];
        }

        // The prior owner may have released and atomically removed the pair
        // after we observed it but before our compare-and-swap. Retry the
        // vacant-slot insert once so that narrow interleave does not create a
        // false busy response.
        if (add_option($option_name, $lease_value, '', false)) {
            update_option($timeout_option_name, (string) $expires_at, false);
            return [
                'acquired' => true,
                'retry_after' => 0,
                'option_name' => $option_name,
                'timeout_option_name' => $timeout_option_name,
                'lease_value' => $lease_value,
                'released_value' => $released_value,
                'expires_at' => $expires_at,
            ];
        }
    }

    return [
        'acquired' => false,
        'retry_after' => max(1, ($earliest_expiry > 0 ? $earliest_expiry : ($now + $ttl)) - $now),
    ];
}

/**
 * Return the stable option names for one client-lease slot.
 *
 * @return array{value:string,timeout:string}
 */
function ll_tools_public_ajax_client_lease_option_names(string $prefix, string $identifier, int $slot = 1): array {
    $prefix = ll_tools_public_ajax_guard_prefix($prefix);
    $slot = max(1, min(20, $slot));
    $key = $prefix . substr(hash('sha256', trim($identifier)), 0, 24) . '_' . $slot;

    return [
        'value' => '_transient_' . $key,
        'timeout' => '_transient_timeout_' . $key,
    ];
}

/**
 * Release only the exact lease acquired by this request.
 */
function ll_tools_public_ajax_release_client_lease(array $lease): void {
    global $wpdb;

    $option_name = (string) ($lease['option_name'] ?? '');
    $lease_value = (string) ($lease['lease_value'] ?? '');
    $released_value = (string) ($lease['released_value'] ?? '');
    if ($option_name === '' || $lease_value === '' || $released_value === '') {
        return;
    }

    $timeout_option_name = (string) ($lease['timeout_option_name'] ?? '');
    if ($timeout_option_name === '') {
        return;
    }

    // Delete the value and timeout rows in one exact-owner statement. A
    // successor has a different value token, so an old owner cannot delete
    // either row from the successor pair. This also avoids relying on Core's
    // expired-transient cleanup, which is skipped with an external object
    // cache even though these guard rows live directly in wp_options.
    $wpdb->query($wpdb->prepare(
        "DELETE lease_value, lease_timeout
         FROM {$wpdb->options} AS lease_value
         LEFT JOIN {$wpdb->options} AS lease_timeout
           ON lease_timeout.option_name = %s
         WHERE lease_value.option_name = %s
           AND lease_value.option_value = %s",
        $timeout_option_name,
        $option_name,
        $lease_value
    ));
    wp_cache_delete($option_name, 'options');
    wp_cache_delete($timeout_option_name, 'options');
}

/**
 * Remove all client leases for one identifier. Intended for focused cleanup
 * and tests, not request-path global cleanup.
 */
function ll_tools_public_ajax_reset_client_leases(string $prefix, string $identifier): void {
    global $wpdb;

    $prefix = ll_tools_public_ajax_guard_prefix($prefix);
    $identifier = trim($identifier);
    if ($prefix === '' || $identifier === '') {
        return;
    }

    $client_key = $prefix . substr(hash('sha256', $identifier), 0, 24) . '_';
    $option_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_' . $client_key) . '%',
        $wpdb->esc_like('_transient_timeout_' . $client_key) . '%'
    ));
    foreach ((array) $option_names as $option_name) {
        delete_option((string) $option_name);
    }
}

/**
 * Poll briefly for a cache value produced by another request.
 *
 * @return mixed
 */
function ll_tools_public_ajax_wait_for_value(callable $reader, int $wait_ms, int $poll_ms = 75) {
    $wait_ms = max(0, min(5000, $wait_ms));
    $poll_ms = max(10, min(500, $poll_ms));
    $deadline = microtime(true) + ($wait_ms / 1000);

    do {
        $value = $reader();
        if ($value !== null && $value !== false) {
            return $value;
        }
        if ($wait_ms <= 0 || microtime(true) >= $deadline) {
            break;
        }
        usleep($poll_ms * 1000);
    } while (microtime(true) < $deadline);

    return null;
}
