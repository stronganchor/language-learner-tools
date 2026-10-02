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
