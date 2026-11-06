<?php
if (!defined('WPINC')) { die; }

/**
 * Enumerate only plugin-generated files. An enumeration error is not an empty
 * directory and must never acknowledge a security-schema migration.
 *
 * @return array|false
 */
function ll_tools_static_cache_generated_files(string $pattern) {
    $files = apply_filters('ll_tools_static_cache_generated_files', glob($pattern), $pattern);
    return is_array($files) ? $files : false;
}

/** Reserve both client and site capacity without retaining a partial charge. */
function ll_tools_reserve_static_render_budget(string $prefix, array $config): array {
    $window = max(1, min(HOUR_IN_SECONDS, (int) $config['window']));
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
        ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    $reservations = [];
    foreach ([['ip', $ip, (int) $config['ip_limit']], ['site', 'site', (int) $config['site_limit']]] as $target) {
        $reservation = ll_tools_public_ajax_reserve_counter($prefix . $target[0] . '_', $target[1], max(0, $target[2]), $window);
        if (empty($reservation['allowed'])) {
            foreach ($reservations as $previous) {
                ll_tools_public_ajax_refund_counter($previous);
            }
            return ['allowed' => false, 'retry_after' => max(1, (int) ($reservation['retry_after'] ?? $window))];
        }
        if (!empty($reservation['reserved'])) {
            $reservations[] = $reservation;
        }
    }
    return ['allowed' => true, 'retry_after' => 0];
}

/**
 * Admit static-cache cleanup only at a maintenance boundary, with a durable
 * cooldown even after an interrupted attempt. Public readers only schedule it.
 *
 * @param callable():bool $is_current
 * @param callable():bool $installer
 */
function ll_tools_maybe_run_static_cache_maintenance(string $key, callable $is_current, callable $installer): bool {
    if ($is_current()) {
        return true;
    }

    $retry_option = 'll_tools_' . $key . '_retry_after';
    $retry_after = (int) get_option($retry_option, 0);
    if ($retry_after > time()) {
        ll_tools_schedule_schema_maintenance($key, $retry_after - time());
        return false;
    }

    return ll_tools_maybe_run_schema_maintenance($key, $is_current, static function () use ($retry_option, $installer): bool {
        // Persist before touching files/providers so a killed worker also backs
        // off. The shared exact-owner lease admits only one cleanup worker.
        $retry_after = time() + 5 * MINUTE_IN_SECONDS;
        update_option($retry_option, $retry_after, false);
        if ((int) get_option($retry_option, 0) !== $retry_after) {
            return false;
        }
        if (!$installer()) {
            return false;
        }
        delete_option($retry_option);
        return true;
    });
}
