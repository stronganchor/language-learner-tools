<?php
if (!defined('WPINC')) { die; }

/** Compare canonical filesystem boundaries, including case-insensitive Windows paths. */
function ll_tools_static_cache_path_is_within(string $path, string $root): bool {
    $path = rtrim(wp_normalize_path($path), '/');
    $root = rtrim(wp_normalize_path($root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

/** Return a private per-site directory or let WordPress render without a file cache. */
function ll_tools_static_cache_private_dir(string $leaf): string {
    global $wpdb;

    if (preg_match('/^[a-z0-9-]+$/D', $leaf) !== 1) {
        return '';
    }

    $configured = defined('LL_TOOLS_STATIC_CACHE_PRIVATE_DIR');
    $base = $configured ? constant('LL_TOOLS_STATIC_CACHE_PRIVATE_DIR') : sys_get_temp_dir();
    if (!is_string($base) || $base === '' || strpos($base, "\0") !== false) {
        return '';
    }
    $absolute_path = wp_normalize_path($base);
    if (!str_starts_with($absolute_path, '/') && preg_match('/^[a-z]:\//i', $absolute_path) !== 1) {
        return '';
    }
    $base = @realpath($base);
    if (!is_string($base) || !is_dir($base)) {
        return '';
    }

    $uploads = wp_upload_dir(null, false, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }
    $web_roots = [ABSPATH, WP_CONTENT_DIR, (string) $uploads['basedir']];
    $document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($document_root !== '') {
        if (!is_string($document_root) || strpos($document_root, "\0") !== false) {
            return '';
        }
        $document_root = @realpath($document_root);
        if (!is_string($document_root)) {
            return '';
        }
        $web_roots[] = $document_root;
    } elseif (PHP_SAPI !== 'cli' && !$configured) {
        // An HTTP worker with an unknown document root needs an explicit
        // operator-verified path to enable disk caching.
        return '';
    }

    $installation = implode("\0", [@realpath(ABSPATH) ?: ABSPATH, DB_NAME, $wpdb->prefix, get_current_blog_id()]);
    $site_dir = trailingslashit($base) . 'll-tools-html-' . hash_hmac('sha256', $installation, wp_salt('auth'));
    $cache_dir = trailingslashit($site_dir) . $leaf;
    foreach ($web_roots as $root) {
        if (ll_tools_static_cache_path_is_within($cache_dir, $root)
            || ll_tools_static_cache_path_is_within($cache_dir, @realpath($root) ?: $root)) {
            return '';
        }
    }
    foreach ([$site_dir, $cache_dir] as $directory) {
        if (@is_link($directory)
            || (!@is_dir($directory) && !@mkdir($directory, 0700) && !@is_dir($directory))) {
            return '';
        }
        $actual = @realpath($directory);
        if (!is_string($actual)
            || !ll_tools_static_cache_path_is_within($actual, $directory)
            || !ll_tools_static_cache_path_is_within($directory, $actual)) {
            return '';
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            $mode = @fileperms($actual);
            if ($mode === false || ($mode & 0077) !== 0) {
                return '';
            }
        }
    }
    return is_writable($cache_dir) ? $cache_dir : '';
}

/** Find an old cache directory solely for retirement. False means unsafe or unavailable. */
function ll_tools_static_cache_legacy_upload_dir(string $leaf): string|false {
    if (preg_match('/^[a-z0-9-]+$/D', $leaf) !== 1) {
        return false;
    }
    $uploads = wp_upload_dir(null, false, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return false;
    }
    $upload_path = (string) $uploads['basedir'];
    $base = @realpath($upload_path);
    if ($base === false) {
        // A restricted path can look absent even when it exists.
        $parent = @realpath(dirname($upload_path));
        return (string) ini_get('open_basedir') === '' && is_string($parent) && is_dir($parent)
            && is_readable($parent) && !file_exists($upload_path) && !is_link($upload_path) ? '' : false;
    }
    if (!is_dir($base) || !is_readable($base)) {
        return false;
    }
    $path = trailingslashit($base) . $leaf;
    if (is_link($path)) {
        return false;
    }
    if (!file_exists($path)) {
        return '';
    }
    $actual = @realpath($path);
    return is_string($actual) && is_dir($actual)
        && ll_tools_static_cache_path_is_within($actual, $path)
        && ll_tools_static_cache_path_is_within($path, $actual) ? $actual : false;
}

/** Retire exact generated files in a bounded maintenance pass, without adopting old HTML. */
function ll_tools_static_cache_retire_legacy_files(string $leaf, string $prefix, bool $sharded = false): bool {
    $root = ll_tools_static_cache_legacy_upload_dir($leaf);
    if ($root === false || $root === '') {
        return $root === '';
    }

    $layout = trailingslashit($root) . 'files';
    $directories = [$root];
    if ($sharded && (file_exists($layout) || is_link($layout))) {
        $actual = @realpath($layout);
        if (is_link($layout) || !is_dir($layout) || !is_string($actual)
            || !ll_tools_static_cache_path_is_within($actual, $layout)
            || !ll_tools_static_cache_path_is_within($layout, $actual)) {
            return false;
        }
        for ($stripe = 0; $stripe < 256; $stripe++) {
            $directories[] = trailingslashit($layout) . sprintf('%02x', $stripe);
        }
    }
    $pattern = '/^' . preg_quote($prefix, '/') . '-(?:[a-f0-9]{32}|[a-f0-9]{64})'
        . '\.(?:html|meta\.json)(?:\.tmp-[a-zA-Z0-9]+)?$/D';
    $visits = 0;
    $deadline = microtime(true) + 1.0;
    $complete = true;
    foreach ($directories as $directory) {
        if (microtime(true) >= $deadline) {
            return false;
        }
        if (is_link($directory)) {
            $complete = false;
            continue;
        }
        if (!file_exists($directory)) {
            continue;
        }
        $actual = @realpath($directory);
        $stream = is_string($actual)
            && ll_tools_static_cache_path_is_within($actual, $directory)
            && ll_tools_static_cache_path_is_within($directory, $actual)
            ? @opendir($directory)
            : false;
        if (!is_resource($stream)) {
            $complete = false;
            continue;
        }
        try {
            while (($name = readdir($stream)) !== false) {
                if (++$visits > 4096 || microtime(true) >= $deadline) {
                    return false;
                }
                if (preg_match($pattern, $name) !== 1) {
                    continue;
                }
                $file = trailingslashit($directory) . $name;
                if (is_link($file) || !is_file($file) || !@unlink($file)) {
                    $complete = false;
                }
            }
        } finally {
            closedir($stream);
        }
    }
    return $complete;
}

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
