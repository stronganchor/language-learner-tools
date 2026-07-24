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
 * The conditional SQL increment is the admission decision. Concurrent PHP
 * workers therefore cannot all observe the same stale transient value and
 * independently admit work beyond the configured limit.
 *
 * @return array{allowed:bool,count:int,limit:int,retry_after:int}
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
        ];
    }

    if (add_option($names['value'], (string) $cost, '', false)) {
        update_option($names['timeout'], (string) $names['expires_at'], false);

        // A recurring visitor needs only the current fixed-window bucket.
        // One-off buckets retain a normal transient timeout for cron cleanup.
        $value_prefix = '_transient_' . $names['key_prefix'];
        $timeout_prefix = '_transient_timeout_' . $names['key_prefix'];
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE (option_name LIKE %s OR option_name LIKE %s)
               AND option_name NOT IN (%s, %s)",
            $wpdb->esc_like($value_prefix) . '%',
            $wpdb->esc_like($timeout_prefix) . '%',
            $names['value'],
            $names['timeout']
        ));

        return [
            'allowed' => true,
            'count' => $cost,
            'limit' => $limit,
            'retry_after' => $retry_after,
        ];
    }

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
    wp_cache_delete($names['value'], 'options');
    $count = max(0, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $names['value']
    )));

    return [
        'allowed' => ($updated === 1),
        'count' => $count,
        'limit' => $limit,
        'retry_after' => $retry_after,
    ];
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

    $client_key = $prefix . substr(hash('sha256', $identifier), 0, 24);
    $expires_at = $now + $ttl;
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $identifier . '|' . microtime(true) . '|' . wp_rand());
    $lease_value = $expires_at . '|' . $token;
    $released_value = '0|' . $token;
    $earliest_expiry = 0;

    for ($slot = 1; $slot <= $limit; $slot++) {
        $key = $client_key . '_' . $slot;
        $option_name = '_transient_' . $key;
        $timeout_option_name = '_transient_timeout_' . $key;
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
            ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_option_name
            ))
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
    }

    return [
        'acquired' => false,
        'retry_after' => max(1, ($earliest_expiry > 0 ? $earliest_expiry : ($now + $ttl)) - $now),
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

    // Marking the slot expired avoids a delete/reacquire race with a following
    // request. Its existing timeout row lets normal transient cleanup reclaim
    // an idle released slot.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $released_value,
        $option_name,
        $lease_value
    ));
    wp_cache_delete($option_name, 'options');
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
