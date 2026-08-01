<?php
// /includes/lib/media-proxy.php

if (!defined('WPINC')) { die; }

/**
 * Normalize a requested image size to a safe key.
 */
function ll_tools_normalize_image_size($size) {
    $size = sanitize_key($size ?: 'full');
    return $size ?: 'full';
}

/**
 * Parse tri-state flag values used by media proxy mode resolution.
 *
 * @return bool|null True/false when recognized, null when undecidable.
 */
function ll_tools_parse_media_proxy_flag_value($raw): ?bool {
    if (is_bool($raw)) {
        return $raw;
    }

    if (is_int($raw) || is_float($raw)) {
        return ((int) $raw) === 1;
    }

    if (!is_string($raw)) {
        return null;
    }

    $value = strtolower(trim($raw));
    if ($value === '') {
        return null;
    }

    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return null;
}

/**
 * Determine whether a host should be treated as a local development hostname.
 */
function ll_tools_is_local_media_host($host): bool {
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return false;
    }

    if (strpos($host, ':') !== false) {
        $host = (string) preg_replace('/:\d+$/', '', $host);
    }
    $host = trim($host, '.');
    if ($host === '') {
        return false;
    }

    if ($host === 'localhost') {
        return true;
    }
    if (substr($host, -6) === '.local') {
        return true;
    }
    if ($host === 'localsite.io' || substr($host, -13) === '.localsite.io') {
        return true;
    }

    return false;
}

/**
 * Detect Local Live Link tunnel hostname by host alone (no header trust).
 */
function ll_tools_is_localsite_tunnel_host($host): bool {
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return false;
    }

    if (strpos($host, ':') !== false) {
        $host = (string) preg_replace('/:\d+$/', '', $host);
    }
    $host = trim($host, '.');
    if ($host === '') {
        return false;
    }

    return ($host === 'localsite.io' || substr($host, -13) === '.localsite.io');
}

/**
 * Whether forwarded headers should be trusted for media URL rebasing/proxying.
 *
 * Default is intentionally narrow: only trust forwarded headers when the
 * request host is already a Local Live Link hostname. This avoids letting
 * arbitrary requests spoof X-Forwarded-* into rewritten media URLs.
 */
function ll_tools_media_proxy_trust_forwarded_headers(): bool {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $default = ll_tools_is_localsite_tunnel_host($host);
    return (bool) apply_filters('ll_tools_media_proxy_trust_forwarded_headers', $default, $host);
}

/**
 * Whether tunnel-identifying headers (X_TUNNEL_UUID / X_LOCAL_HOST) are trusted.
 *
 * Disabled by default unless the request host is already a Local Live Link host.
 * Sites can opt in (for custom trusted proxy setups) via filter.
 */
function ll_tools_media_proxy_trust_tunnel_headers(): bool {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $default = ll_tools_is_localsite_tunnel_host($host);
    return (bool) apply_filters('ll_tools_media_proxy_trust_tunnel_headers', $default, $host);
}

/**
 * Auto-detect whether this installation/request context should use masked proxy URLs.
 */
function ll_tools_should_use_masked_image_proxy(): bool {
    // Optional hard override from wp-config.php.
    if (defined('LL_TOOLS_USE_MASKED_IMAGE_PROXY')) {
        $constant_choice = ll_tools_parse_media_proxy_flag_value(LL_TOOLS_USE_MASKED_IMAGE_PROXY);
        if ($constant_choice !== null) {
            return (bool) apply_filters('ll_tools_use_masked_image_proxy', $constant_choice);
        }
    }

    // Optional site-level flag (e.g. set via WP-CLI / DB).
    $raw_option = get_option('ll_tools_use_masked_image_proxy', '__ll_tools_proxy_auto__');
    if ($raw_option !== '__ll_tools_proxy_auto__') {
        $option_choice = ll_tools_parse_media_proxy_flag_value($raw_option);
        if ($option_choice !== null) {
            return (bool) apply_filters('ll_tools_use_masked_image_proxy', $option_choice);
        }
    }

    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $request_host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $request_origin = ll_tools_get_request_origin_for_media();

    $auto_enabled = ll_tools_is_local_media_host($home_host)
        || ll_tools_is_local_media_host($request_host)
        || ll_tools_is_local_media_host((string) ($request_origin['host'] ?? ''));

    return (bool) apply_filters('ll_tools_use_masked_image_proxy', $auto_enabled);
}

/**
 * Generate a signed URL that hides the original attachment filename.
 */
function ll_tools_get_masked_image_url($attachment_id, $size = 'full') {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $size = ll_tools_normalize_image_size($size);
    if (!ll_tools_should_use_masked_image_proxy()) {
        return (string) (wp_get_attachment_image_url($attachment_id, $size) ?: '');
    }

    $sig  = hash_hmac(
        'sha256',
        $attachment_id . '|' . $size,
        wp_salt('ll-tools-image-proxy')
    );

    $args = [
        'lltools-img'  => $attachment_id,
        'lltools-size' => $size,
        'lltools-sig'  => $sig,
        // Bump when proxy response behavior changes to bypass stale cached blobs.
        'lltools-v'    => '2',
    ];

    return add_query_arg($args, home_url('/'));
}

/**
 * Resolve the local file path for a specific image size.
 */
function ll_tools_resolve_image_path_for_size($attachment_id, $size = 'full') {
    $uploads = wp_get_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    $base_path = get_attached_file($attachment_id, true);
    if (!$base_path) {
        return '';
    }

    $meta = wp_get_attachment_metadata($attachment_id);
    $size = ll_tools_normalize_image_size($size);
    if ($size !== 'full' && is_array($meta) && !empty($meta['file']) && !empty($meta['sizes'][$size]['file'])) {
        $relative = path_join(dirname($meta['file']), $meta['sizes'][$size]['file']);
        $relative = ltrim((string) $relative, '/');
        return trailingslashit($uploads['basedir']) . $relative;
    }

    return $base_path;
}

/**
 * Resolve the public URL for a specific image size.
 */
function ll_tools_resolve_image_url_for_size($attachment_id, $size = 'full') {
    $src = wp_get_attachment_image_src($attachment_id, ll_tools_normalize_image_size($size));
    return (is_array($src) && !empty($src[0])) ? $src[0] : '';
}

/**
 * Detect a safe image MIME type for proxy responses.
 */
function ll_tools_detect_proxy_image_mime($path, $fallback = 'image/webp') {
    $mime = '';
    if (is_string($path) && $path !== '' && function_exists('wp_get_image_mime')) {
        $mime = (string) wp_get_image_mime($path);
    }
    if ($mime === '' && is_string($path) && $path !== '') {
        $filetype = wp_check_filetype($path);
        $mime = isset($filetype['type']) ? (string) $filetype['type'] : '';
    }
    if ($mime === '' && is_string($fallback)) {
        $mime = trim($fallback);
    }
    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        $mime = 'image/webp';
    }
    return $mime;
}

/**
 * Guess an image MIME type from a URL path.
 */
function ll_tools_guess_proxy_image_mime_from_url($url): string {
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    if ($path === '') {
        return '';
    }
    $filetype = wp_check_filetype($path);
    $mime = isset($filetype['type']) ? (string) $filetype['type'] : '';
    if ($mime !== '' && strpos($mime, 'image/') === 0) {
        return $mime;
    }
    return '';
}

function ll_tools_media_proxy_fallback_url_is_safe($url): bool {
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
        return false;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return false;
    }

    $allow_private = (bool) apply_filters('ll_tools_media_proxy_allow_private_fallback_url', false, $url, $parts);
    if ($allow_private) {
        return true;
    }

    if (function_exists('ll_tools_remote_stt_host_is_restricted') && ll_tools_remote_stt_host_is_restricted($host)) {
        return false;
    }
    if (function_exists('ll_tools_remote_stt_host_resolves_to_restricted_ip') && ll_tools_remote_stt_host_resolves_to_restricted_ip($host)) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }

    return true;
}

/**
 * Resolve the guarded root for cached remote fallback images.
 *
 * @return string|WP_Error
 */
function ll_tools_media_proxy_fallback_cache_root() {
    $uploads = wp_get_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return new WP_Error(
            'media_proxy_cache_uploads',
            __('Media proxy cache storage is unavailable.', 'll-tools-text-domain')
        );
    }

    $uploads_root = untrailingslashit(wp_normalize_path((string) $uploads['basedir']));
    $cache_root = untrailingslashit(wp_normalize_path((string) apply_filters(
        'll_tools_media_proxy_fallback_cache_root',
        trailingslashit($uploads_root) . 'll-tools-media-proxy-cache',
        $uploads_root
    )));
    if (
        $uploads_root === ''
        || $cache_root === ''
        || strpos($cache_root, "\0") !== false
        || preg_match('#/(?:\.{1,2})(?:/|$)#', $cache_root)
        || !ll_tools_media_proxy_path_has_prefix($cache_root, $uploads_root . '/')
    ) {
        return new WP_Error(
            'media_proxy_cache_root',
            __('Remote image cache root is invalid.', 'll-tools-text-domain')
        );
    }

    $real_uploads_root = realpath($uploads_root);
    $real_cache_root = realpath($cache_root);
    if ($real_uploads_root !== false && $real_cache_root !== false) {
        $real_uploads_root = untrailingslashit(wp_normalize_path($real_uploads_root));
        $real_cache_root = untrailingslashit(wp_normalize_path($real_cache_root));
        if (!ll_tools_media_proxy_path_has_prefix($real_cache_root, $real_uploads_root . '/')) {
            return new WP_Error(
                'media_proxy_cache_root',
                __('Remote image cache root is outside uploads.', 'll-tools-text-domain')
            );
        }
    }

    return $cache_root;
}

function ll_tools_media_proxy_path_has_prefix(string $path, string $prefix): bool {
    if (DIRECTORY_SEPARATOR === '\\') {
        return strncasecmp($path, $prefix, strlen($prefix)) === 0;
    }

    return strncmp($path, $prefix, strlen($prefix)) === 0;
}

/**
 * Require a path to stay beneath the resolved cache root without traversing
 * symlinks. Existing paths are also checked after filesystem resolution.
 */
function ll_tools_media_proxy_cache_path_is_safe(string $path, bool $allow_root = false): bool {
    $cache_root = ll_tools_media_proxy_fallback_cache_root();
    if (is_wp_error($cache_root)) {
        return false;
    }

    $cache_root = untrailingslashit(wp_normalize_path($cache_root));
    $path = untrailingslashit(wp_normalize_path($path));
    if (
        $path === ''
        || strpos($path, "\0") !== false
        || preg_match('#/(?:\.{1,2})(?:/|$)#', $path)
        || (!$allow_root && $path === $cache_root)
        || ($path !== $cache_root && !ll_tools_media_proxy_path_has_prefix($path, $cache_root . '/'))
        || is_link($path)
    ) {
        return false;
    }

    $real_cache_root = realpath($cache_root);
    $real_path = realpath($path);
    if ($real_cache_root !== false && $real_path !== false) {
        $real_cache_root = untrailingslashit(wp_normalize_path($real_cache_root));
        $real_path = untrailingslashit(wp_normalize_path($real_path));
        if (
            (!$allow_root && $real_path === $real_cache_root)
            || ($real_path !== $real_cache_root && !ll_tools_media_proxy_path_has_prefix($real_path, $real_cache_root . '/'))
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Resolve the bounded on-disk cache location for a remote fallback image.
 *
 * @return array{root:string,shard_directory:string,shard_index:int,directory:string,path:string,key:string}|WP_Error
 */
function ll_tools_media_proxy_fallback_cache_context(int $attachment_id, string $size, string $url) {
    $attachment_id = max(0, (int) $attachment_id);
    $size = ll_tools_normalize_image_size($size);
    $url = trim($url);
    if ($attachment_id <= 0 || $url === '') {
        return new WP_Error(
            'media_proxy_cache_context',
            __('Invalid media proxy cache context.', 'll-tools-text-domain')
        );
    }

    $cache_root = ll_tools_media_proxy_fallback_cache_root();
    if (is_wp_error($cache_root)) {
        return $cache_root;
    }

    $size_hash = hash('sha256', $size);
    $attachment_shard = substr(hash('sha256', (string) $attachment_id), 0, 3);
    $size_shard = substr($size_hash, 0, 1);
    $shard_directory = trailingslashit($cache_root) . $attachment_shard . '/' . $size_shard;
    $bucket = $attachment_id . '-' . substr($size_hash, 0, 12);
    $directory = trailingslashit($shard_directory) . $bucket;
    $key = hash('sha256', $attachment_id . '|' . $size . '|' . $url);

    return [
        'root' => $cache_root,
        'shard_directory' => $shard_directory,
        'shard_index' => hexdec($attachment_shard . $size_shard),
        'directory' => $directory,
        'path' => trailingslashit($directory) . $key . '.img',
        'key' => $key,
    ];
}

/**
 * Validate the exact filename and bucket structure before any cache mutation.
 */
function ll_tools_media_proxy_fallback_cache_context_is_safe(array $context): bool {
    $cache_root = ll_tools_media_proxy_fallback_cache_root();
    if (is_wp_error($cache_root)) {
        return false;
    }

    $cache_root = untrailingslashit(wp_normalize_path($cache_root));
    $directory = untrailingslashit(wp_normalize_path((string) ($context['directory'] ?? '')));
    $cache_path = wp_normalize_path((string) ($context['path'] ?? ''));
    $cache_key = strtolower(trim((string) ($context['key'] ?? '')));
    $bucket = basename($directory);
    if (preg_match('/^([1-9][0-9]*)-([a-f0-9]{12})$/', $bucket, $bucket_matches) !== 1) {
        return false;
    }

    $attachment_shard = substr(hash('sha256', (string) ((int) $bucket_matches[1])), 0, 3);
    $size_shard = substr((string) $bucket_matches[2], 0, 1);
    $shard_directory = untrailingslashit(wp_normalize_path(dirname($directory)));
    $attachment_shard_directory = untrailingslashit(wp_normalize_path(dirname($shard_directory)));
    $expected_shard_index = hexdec($attachment_shard . $size_shard);

    return (empty($context['root']) || untrailingslashit(wp_normalize_path((string) $context['root'])) === $cache_root)
        && (empty($context['shard_directory']) || untrailingslashit(wp_normalize_path((string) $context['shard_directory'])) === $shard_directory)
        && (!isset($context['shard_index']) || (int) $context['shard_index'] === $expected_shard_index)
        && basename($shard_directory) === $size_shard
        && basename($attachment_shard_directory) === $attachment_shard
        && untrailingslashit(wp_normalize_path(dirname($attachment_shard_directory))) === $cache_root
        && preg_match('/^[a-f0-9]{64}$/', $cache_key) === 1
        && $cache_path === trailingslashit($directory) . $cache_key . '.img'
        && ll_tools_media_proxy_cache_path_is_safe($shard_directory)
        && ll_tools_media_proxy_cache_path_is_safe($directory)
        && ll_tools_media_proxy_cache_path_is_safe($cache_path);
}

/**
 * Validate a cached response as a supported raster image.
 */
function ll_tools_media_proxy_validate_cached_fallback_file(string $path, int $max_bytes, ?string &$mime = null): bool {
    $mime = '';
    $max_bytes = max(1, (int) $max_bytes);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }

    $bytes = @filesize($path);
    if (!is_int($bytes) || $bytes <= 0 || $bytes > $max_bytes) {
        return false;
    }

    $detected = function_exists('wp_get_image_mime') ? (string) wp_get_image_mime($path) : '';
    if ($detected === '' && function_exists('getimagesize')) {
        $details = @getimagesize($path);
        $detected = is_array($details) ? (string) ($details['mime'] ?? '') : '';
    }
    $allowed_mimes = (array) apply_filters('ll_tools_media_proxy_fallback_allowed_mimes', [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ]);
    if ($detected === '' || !in_array($detected, $allowed_mimes, true)) {
        return false;
    }

    $mime = $detected;
    return true;
}

function ll_tools_media_proxy_fallback_max_stale_age(): int {
    $max_stale_age = (int) apply_filters(
        'll_tools_media_proxy_fallback_max_stale_age',
        14 * DAY_IN_SECONDS
    );

    return max(7 * DAY_IN_SECONDS, min(30 * DAY_IN_SECONDS, $max_stale_age));
}

/**
 * Describe whether a validated cache file may be served without contacting the
 * remote origin. "servable" is an absolute maximum age, not an extra stale TTL.
 *
 * @return array{valid:bool,fresh:bool,servable:bool,mime:string,mtime:int,age:int}
 */
function ll_tools_media_proxy_cached_fallback_state(
    string $path,
    int $max_bytes,
    int $cache_ttl,
    int $max_stale_age,
    int $now = 0
): array {
    $state = [
        'valid' => false,
        'fresh' => false,
        'servable' => false,
        'mime' => '',
        'mtime' => 0,
        'age' => PHP_INT_MAX,
    ];
    if (!ll_tools_media_proxy_cache_path_is_safe($path)) {
        return $state;
    }

    clearstatcache(true, $path);
    $mime = '';
    if (!ll_tools_media_proxy_validate_cached_fallback_file($path, $max_bytes, $mime)) {
        return $state;
    }

    $mtime = (int) @filemtime($path);
    if ($mtime <= 0) {
        return $state;
    }

    $now = $now > 0 ? $now : time();
    $cache_ttl = max(1, $cache_ttl);
    $max_stale_age = max($cache_ttl, $max_stale_age);
    $age = max(0, $now - $mtime);

    return [
        'valid' => true,
        'fresh' => $age <= $cache_ttl,
        'servable' => $age <= $max_stale_age,
        'mime' => $mime,
        'mtime' => $mtime,
        'age' => $age,
    ];
}

/**
 * Keep browser/CDN freshness within the remaining server-side cache lifetime.
 */
function ll_tools_media_proxy_fallback_client_max_age(int $cache_ttl, int $age): int {
    return max(0, max(0, $cache_ttl) - max(0, $age));
}

/**
 * Briefly wait for the request holding the cache lease to publish its file.
 *
 * @return array{path:string,mime:string,mtime:int}|WP_Error
 */
function ll_tools_media_proxy_wait_for_fallback_cache_file(
    string $path,
    int $max_bytes,
    int $wait_ms = 0,
    int $poll_ms = 0,
    int $newer_than = 0
) {
    if (!ll_tools_media_proxy_cache_path_is_safe($path)) {
        return new WP_Error(
            'media_proxy_cache_wait_path',
            __('Remote image cache path is invalid.', 'll-tools-text-domain')
        );
    }

    if ($wait_ms <= 0) {
        $wait_ms = (int) apply_filters('ll_tools_media_proxy_fallback_contention_wait_ms', 1200);
    }
    if ($poll_ms <= 0) {
        $poll_ms = (int) apply_filters('ll_tools_media_proxy_fallback_contention_poll_ms', 50);
    }
    $wait_ms = max(25, min(2500, $wait_ms));
    $poll_ms = max(10, min(250, $poll_ms));
    $deadline = microtime(true) + ($wait_ms / 1000);
    $attempt = 0;

    do {
        clearstatcache(true, $path);
        $mime = '';
        if (
            ll_tools_media_proxy_validate_cached_fallback_file($path, $max_bytes, $mime)
            && ($newer_than <= 0 || (int) @filemtime($path) > $newer_than)
        ) {
            return [
                'path' => $path,
                'mime' => $mime,
                'mtime' => (int) @filemtime($path),
            ];
        }

        $remaining_ms = max(0, (int) ceil(($deadline - microtime(true)) * 1000));
        if ($remaining_ms <= 0) {
            break;
        }

        $attempt++;
        do_action('ll_tools_media_proxy_fallback_cache_wait_poll', $path, $attempt, $remaining_ms);
        $sleep_ms = min($poll_ms, $remaining_ms);
        usleep($sleep_ms * 1000);
    } while (microtime(true) < $deadline);

    clearstatcache(true, $path);
    $mime = '';
    if (
        ll_tools_media_proxy_validate_cached_fallback_file($path, $max_bytes, $mime)
        && ($newer_than <= 0 || (int) @filemtime($path) > $newer_than)
    ) {
        return [
            'path' => $path,
            'mime' => $mime,
            'mtime' => (int) @filemtime($path),
        ];
    }

    return new WP_Error(
        'media_proxy_cache_wait_timeout',
        __('Remote image cache is still being prepared.', 'll-tools-text-domain')
    );
}

/**
 * Acquire an exact-owner lease for one remote fallback cache key.
 *
 * @return array{key:string,value:string}|null
 */
function ll_tools_media_proxy_fallback_cache_lock_option_name(string $cache_key): string {
    $cache_key = trim($cache_key);
    return $cache_key === '' ? '' : '_ll_tools_media_fallback_lock_' . substr(md5($cache_key), 0, 24);
}

function ll_tools_media_proxy_acquire_fallback_cache_lock(string $cache_key, int $ttl = 20): ?array {
    $cache_key = trim($cache_key);
    if ($cache_key === '') {
        return null;
    }

    $ttl = max(5, min(60, (int) $ttl));
    $option_name = ll_tools_media_proxy_fallback_cache_lock_option_name($cache_key);
    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(32, false, false);
    $value = $token . ':' . (time() + $ttl);
    if (add_option($option_name, $value, '', false)) {
        return ['key' => $option_name, 'value' => $value];
    }

    $stored = (string) get_option($option_name, '');
    $separator = strrpos($stored, ':');
    $expires_at = $separator === false ? 0 : (int) substr($stored, $separator + 1);
    if ($expires_at > time()) {
        return null;
    }

    global $wpdb;
    $updated = $wpdb->update(
        $wpdb->options,
        ['option_value' => $value],
        ['option_name' => $option_name, 'option_value' => $stored],
        ['%s'],
        ['%s', '%s']
    );
    wp_cache_delete($option_name, 'options');

    return $updated === 1 ? ['key' => $option_name, 'value' => $value] : null;
}

/**
 * Release a fallback cache lease only when this request still owns it.
 *
 * @param array{key:string,value:string} $lease
 */
function ll_tools_media_proxy_release_fallback_cache_lock(array $lease): void {
    $option_name = sanitize_key((string) ($lease['key'] ?? ''));
    $value = (string) ($lease['value'] ?? '');
    if ($option_name === '' || $value === '') {
        return;
    }

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $option_name,
        $value
    ));
    wp_cache_delete($option_name, 'options');
}

function ll_tools_media_proxy_fallback_backoff_path(array $context): string {
    if (!ll_tools_media_proxy_fallback_cache_context_is_safe($context)) {
        return '';
    }

    $path = trailingslashit((string) $context['directory']) . (string) $context['key'] . '.retry';
    return ll_tools_media_proxy_cache_path_is_safe($path) ? $path : '';
}

function ll_tools_media_proxy_fallback_failure_backoff_seconds(): int {
    $seconds = (int) apply_filters('ll_tools_media_proxy_fallback_failure_backoff_seconds', 5 * MINUTE_IN_SECONDS);
    return max(30, min(HOUR_IN_SECONDS, $seconds));
}

/**
 * Return the remaining failure backoff, pruning an expired marker in-place.
 */
function ll_tools_media_proxy_fallback_backoff_remaining(array $context, int $now = 0): int {
    $path = ll_tools_media_proxy_fallback_backoff_path($context);
    if ($path === '' || !is_file($path) || is_link($path)) {
        return 0;
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return 0;
    }
    @flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle, 32);
    @flock($handle, LOCK_UN);
    fclose($handle);

    $expires_at = is_string($raw) && preg_match('/^\s*([0-9]{1,12})\s*$/', $raw, $matches)
        ? (int) $matches[1]
        : 0;
    $now = $now > 0 ? $now : time();
    if ($expires_at <= $now) {
        ll_tools_media_proxy_clear_fallback_backoff($context);
        return 0;
    }

    return min(HOUR_IN_SECONDS, $expires_at - $now);
}

function ll_tools_media_proxy_record_fallback_failure(
    array $context,
    int $now = 0,
    int $backoff_seconds = 0
): bool {
    $path = ll_tools_media_proxy_fallback_backoff_path($context);
    $directory = (string) ($context['directory'] ?? '');
    if (
        $path === ''
        || $directory === ''
        || (!is_dir($directory) && !wp_mkdir_p($directory))
        || !ll_tools_media_proxy_cache_path_is_safe($directory)
        || !ll_tools_media_proxy_cache_path_is_safe($path)
    ) {
        return false;
    }

    $now = $now > 0 ? $now : time();
    $backoff_seconds = $backoff_seconds > 0
        ? max(1, min(HOUR_IN_SECONDS, $backoff_seconds))
        : ll_tools_media_proxy_fallback_failure_backoff_seconds();

    return @file_put_contents($path, (string) ($now + $backoff_seconds), LOCK_EX) !== false;
}

function ll_tools_media_proxy_clear_fallback_backoff(array $context): void {
    $path = ll_tools_media_proxy_fallback_backoff_path($context);
    if ($path !== '' && is_file($path) && !is_link($path)) {
        wp_delete_file($path);
    }
}

function ll_tools_media_proxy_fallback_bucket_max_files(): int {
    $max_files = (int) apply_filters('ll_tools_media_proxy_fallback_bucket_max_files', 4);
    return max(1, min(16, $max_files));
}

function ll_tools_media_proxy_fallback_bucket_max_bytes(): int {
    $max_bytes = (int) apply_filters(
        'll_tools_media_proxy_fallback_bucket_max_bytes',
        32 * MB_IN_BYTES
    );
    return max(MB_IN_BYTES, min(128 * MB_IN_BYTES, $max_bytes));
}

function ll_tools_media_proxy_fallback_cache_file_kind(string $filename): string {
    if (preg_match('/^[a-f0-9]{64}\.img$/', $filename)) {
        return 'image';
    }
    if (preg_match('/^[a-f0-9]{64}\.retry$/', $filename)) {
        return 'retry';
    }
    if (preg_match('/^[a-f0-9]{64}\.[a-zA-Z0-9]{6,32}\.(?:tmp|old)$/', $filename)) {
        return 'temporary';
    }

    return '';
}

/**
 * Bound one attachment/size bucket by age, image count, total image bytes,
 * scanned entries, and deletions. Unknown files and symlinks are never touched.
 *
 * @param array<string,int|string> $limits
 * @return array<string,int>
 */
function ll_tools_media_proxy_prune_fallback_cache_bucket(string $directory, array $limits = []): array {
    $telemetry = [
        'scanned_count' => 0,
        'deleted_file_count' => 0,
        'deleted_byte_count' => 0,
        'remaining_image_count' => 0,
        'remaining_image_bytes' => 0,
        'scan_limit_stop_count' => 0,
        'delete_limit_stop_count' => 0,
    ];
    $directory = untrailingslashit(wp_normalize_path($directory));
    $bucket = basename($directory);
    if (
        preg_match('/^[1-9][0-9]*-[a-f0-9]{12}$/', $bucket) !== 1
        || !ll_tools_media_proxy_cache_path_is_safe($directory)
        || !is_dir($directory)
        || is_link($directory)
    ) {
        return $telemetry;
    }

    $now = max(1, (int) ($limits['now'] ?? time()));
    $max_files = max(1, min(16, (int) ($limits['max_files'] ?? ll_tools_media_proxy_fallback_bucket_max_files())));
    $max_bytes = max(1, min(128 * MB_IN_BYTES, (int) ($limits['max_bytes'] ?? ll_tools_media_proxy_fallback_bucket_max_bytes())));
    $max_stale_age = max(1, min(30 * DAY_IN_SECONDS, (int) ($limits['max_stale_age'] ?? ll_tools_media_proxy_fallback_max_stale_age())));
    $scan_limit = max(1, min(256, (int) ($limits['scan_limit'] ?? 128)));
    $delete_limit = max(1, min(128, (int) ($limits['delete_limit'] ?? 64)));
    $keep_path = isset($limits['keep_path'])
        ? wp_normalize_path((string) $limits['keep_path'])
        : '';
    if (
        $keep_path !== ''
        && (
            !ll_tools_media_proxy_cache_path_is_safe($keep_path)
            || untrailingslashit(wp_normalize_path(dirname($keep_path))) !== $directory
        )
    ) {
        $keep_path = '';
    }

    $images = [];
    try {
        $iterator = new DirectoryIterator($directory);
    } catch (UnexpectedValueException $exception) {
        return $telemetry;
    }

    foreach ($iterator as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($telemetry['scanned_count'] >= $scan_limit) {
            $telemetry['scan_limit_stop_count'] = 1;
            break;
        }
        $telemetry['scanned_count']++;

        $filename = $entry->getFilename();
        $kind = ll_tools_media_proxy_fallback_cache_file_kind($filename);
        $path = wp_normalize_path($entry->getPathname());
        if (
            $kind === ''
            || $entry->isLink()
            || !$entry->isFile()
            || !ll_tools_media_proxy_cache_path_is_safe($path)
        ) {
            continue;
        }

        $mtime = max(0, (int) $entry->getMTime());
        $age = max(0, $now - $mtime);
        $bytes = max(0, (int) $entry->getSize());
        $delete_for_age = ($kind === 'image' && $age > $max_stale_age)
            || ($kind === 'temporary' && $age > HOUR_IN_SECONDS);
        if ($kind === 'retry' && $age > HOUR_IN_SECONDS) {
            $delete_for_age = true;
        }

        if ($delete_for_age && $path !== $keep_path && $telemetry['deleted_file_count'] < $delete_limit) {
            wp_delete_file($path);
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $telemetry['deleted_file_count']++;
                $telemetry['deleted_byte_count'] += $bytes;
                continue;
            }
        }

        if ($kind === 'image') {
            $images[] = [
                'path' => $path,
                'mtime' => $mtime,
                'bytes' => $bytes,
            ];
        }
    }

    usort($images, static function (array $left, array $right): int {
        $mtime_order = ((int) $left['mtime']) <=> ((int) $right['mtime']);
        return $mtime_order !== 0 ? $mtime_order : strcmp((string) $left['path'], (string) $right['path']);
    });
    $remaining_count = count($images);
    $remaining_bytes = array_sum(array_column($images, 'bytes'));

    foreach ($images as $image) {
        if ($remaining_count <= $max_files && $remaining_bytes <= $max_bytes) {
            break;
        }
        if ($telemetry['deleted_file_count'] >= $delete_limit) {
            $telemetry['delete_limit_stop_count'] = 1;
            break;
        }
        $path = (string) $image['path'];
        if ($path === $keep_path || !is_file($path)) {
            continue;
        }

        wp_delete_file($path);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            $remaining_count--;
            $remaining_bytes = max(0, $remaining_bytes - (int) $image['bytes']);
            $telemetry['deleted_file_count']++;
            $telemetry['deleted_byte_count'] += (int) $image['bytes'];
        }
    }

    $telemetry['remaining_image_count'] = $remaining_count;
    $telemetry['remaining_image_bytes'] = $remaining_bytes;
    if (
        $telemetry['deleted_file_count'] >= $delete_limit
        && ($remaining_count > $max_files || $remaining_bytes > $max_bytes)
    ) {
        $telemetry['delete_limit_stop_count'] = 1;
    }

    return $telemetry;
}

/**
 * Download one fallback image directly to a bounded temporary file.
 *
 * @param array{root?:string,directory:string,path:string,key:string} $context
 * @return array{path:string,mime:string}|WP_Error
 */
function ll_tools_media_proxy_download_fallback_to_cache(string $url, array $context, int $max_bytes) {
    $directory = (string) ($context['directory'] ?? '');
    $cache_path = (string) ($context['path'] ?? '');
    $cache_key = (string) ($context['key'] ?? '');
    $max_bytes = max(64 * KB_IN_BYTES, min(32 * MB_IN_BYTES, (int) $max_bytes));
    if (
        $directory === ''
        || $cache_path === ''
        || $cache_key === ''
        || !ll_tools_media_proxy_fallback_cache_context_is_safe($context)
        || !ll_tools_media_proxy_fallback_url_is_safe($url)
        || (!is_dir($directory) && !wp_mkdir_p($directory))
        || !ll_tools_media_proxy_fallback_cache_context_is_safe($context)
    ) {
        return new WP_Error(
            'media_proxy_cache_unavailable',
            __('Remote image cache storage is unavailable.', 'll-tools-text-domain')
        );
    }

    $temporary_path = trailingslashit($directory) . $cache_key . '.' . wp_generate_password(12, false, false) . '.tmp';
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'decompress' => true,
        'reject_unsafe_urls' => true,
        'stream' => true,
        'filename' => $temporary_path,
        'limit_response_size' => $max_bytes + 1,
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        if (is_file($temporary_path)) {
            wp_delete_file($temporary_path);
        }
        return new WP_Error(
            'media_proxy_remote_failed',
            __('Remote image download failed.', 'll-tools-text-domain')
        );
    }

    $declared_bytes = (int) wp_remote_retrieve_header($response, 'content-length');
    $mime = '';
    if (
        ($declared_bytes > 0 && $declared_bytes > $max_bytes)
        || !ll_tools_media_proxy_validate_cached_fallback_file($temporary_path, $max_bytes, $mime)
    ) {
        wp_delete_file($temporary_path);
        return new WP_Error(
            'media_proxy_remote_invalid',
            __('Remote image response was invalid or too large.', 'll-tools-text-domain')
        );
    }

    if (!@rename($temporary_path, $cache_path)) {
        $old_path = trailingslashit($directory) . $cache_key . '.' . wp_generate_password(12, false, false) . '.old';
        $old_moved = is_file($cache_path) && @rename($cache_path, $old_path);
        if (!$old_moved || !@rename($temporary_path, $cache_path)) {
            if ($old_moved && !is_file($cache_path)) {
                @rename($old_path, $cache_path);
            }
            wp_delete_file($temporary_path);
            return new WP_Error(
                'media_proxy_cache_move',
                __('Remote image could not be cached.', 'll-tools-text-domain')
            );
        }
        wp_delete_file($old_path);
    }

    clearstatcache(true, $cache_path);
    ll_tools_media_proxy_prune_fallback_cache_bucket($directory, [
        'keep_path' => $cache_path,
    ]);

    return ['path' => $cache_path, 'mime' => $mime];
}

/**
 * Refresh once unless a recent failure has placed this key in backoff.
 *
 * @param array{root?:string,directory:string,path:string,key:string} $context
 * @return array{path:string,mime:string}|WP_Error
 */
function ll_tools_media_proxy_refresh_fallback_cache(string $url, array $context, int $max_bytes) {
    $retry_after = ll_tools_media_proxy_fallback_backoff_remaining($context);
    if ($retry_after > 0) {
        return new WP_Error(
            'media_proxy_refresh_backoff',
            __('Remote image refresh is temporarily backed off.', 'll-tools-text-domain'),
            ['retry_after' => $retry_after]
        );
    }

    $downloaded = ll_tools_media_proxy_download_fallback_to_cache($url, $context, $max_bytes);
    if (is_wp_error($downloaded)) {
        ll_tools_media_proxy_record_fallback_failure($context);
        return $downloaded;
    }

    ll_tools_media_proxy_clear_fallback_backoff($context);
    return $downloaded;
}

/**
 * Delete only recognized cache artifacts from one guarded bucket.
 *
 * @return array{scanned_count:int,deleted_file_count:int,deleted_byte_count:int,directory_deleted_count:int}
 */
function ll_tools_media_proxy_delete_fallback_cache_bucket(
    string $directory,
    int $scan_limit = 128,
    int $delete_limit = 128
): array {
    $telemetry = [
        'scanned_count' => 0,
        'deleted_file_count' => 0,
        'deleted_byte_count' => 0,
        'directory_deleted_count' => 0,
    ];
    $directory = untrailingslashit(wp_normalize_path($directory));
    if (
        preg_match('/^[1-9][0-9]*-[a-f0-9]{12}$/', basename($directory)) !== 1
        || !ll_tools_media_proxy_cache_path_is_safe($directory)
        || !is_dir($directory)
        || is_link($directory)
    ) {
        return $telemetry;
    }

    $scan_limit = max(1, min(256, $scan_limit));
    $delete_limit = max(1, min(256, $delete_limit));
    try {
        $iterator = new DirectoryIterator($directory);
    } catch (UnexpectedValueException $exception) {
        return $telemetry;
    }

    foreach ($iterator as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if (
            $telemetry['scanned_count'] >= $scan_limit
            || $telemetry['deleted_file_count'] >= $delete_limit
        ) {
            break;
        }
        $telemetry['scanned_count']++;

        $path = wp_normalize_path($entry->getPathname());
        if (
            ll_tools_media_proxy_fallback_cache_file_kind($entry->getFilename()) === ''
            || $entry->isLink()
            || !$entry->isFile()
            || !ll_tools_media_proxy_cache_path_is_safe($path)
        ) {
            continue;
        }

        $bytes = max(0, (int) $entry->getSize());
        wp_delete_file($path);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            $telemetry['deleted_file_count']++;
            $telemetry['deleted_byte_count'] += $bytes;
        }
    }

    if (ll_tools_media_proxy_cache_path_is_safe($directory) && @rmdir($directory)) {
        $telemetry['directory_deleted_count'] = 1;
    }

    return $telemetry;
}

/**
 * Remove cache buckets for a deleted attachment with hard scan/runtime caps.
 * Scheduled maintenance catches any bucket beyond this request-time window.
 *
 * @return array<string,int>
 */
function ll_tools_media_proxy_delete_attachment_cache(int $attachment_id): array {
    $telemetry = [
        'scanned_count' => 0,
        'matched_bucket_count' => 0,
        'deleted_file_count' => 0,
        'deleted_byte_count' => 0,
        'deleted_directory_count' => 0,
        'limit_stop_count' => 0,
    ];
    $attachment_id = max(0, $attachment_id);
    $cache_root = ll_tools_media_proxy_fallback_cache_root();
    if (
        $attachment_id <= 0
        || is_wp_error($cache_root)
        || !is_dir($cache_root)
        || !ll_tools_media_proxy_cache_path_is_safe($cache_root, true)
        || is_link($cache_root)
    ) {
        return $telemetry;
    }

    $scan_limit = max(16, min(
        1024,
        (int) apply_filters('ll_tools_media_proxy_delete_attachment_scan_limit', 512)
    ));
    $bucket_limit = max(1, min(
        64,
        (int) apply_filters('ll_tools_media_proxy_delete_attachment_bucket_limit', 32)
    ));
    $deadline = microtime(true) + 0.5;
    $prefix = $attachment_id . '-';
    $attachment_shard = substr(hash('sha256', (string) $attachment_id), 0, 3);
    $attachment_shard_directory = trailingslashit($cache_root) . $attachment_shard;

    foreach (str_split('0123456789abcdef') as $size_shard) {
        if (
            $telemetry['scanned_count'] >= $scan_limit
            || $telemetry['matched_bucket_count'] >= $bucket_limit
            || microtime(true) >= $deadline
        ) {
            $telemetry['limit_stop_count'] = 1;
            break;
        }

        $shard_directory = trailingslashit($attachment_shard_directory) . $size_shard;
        if (
            !is_dir($shard_directory)
            || is_link($shard_directory)
            || !ll_tools_media_proxy_cache_path_is_safe($shard_directory)
        ) {
            continue;
        }

        try {
            $iterator = new DirectoryIterator($shard_directory);
        } catch (UnexpectedValueException $exception) {
            continue;
        }
        foreach ($iterator as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (
                $telemetry['scanned_count'] >= $scan_limit
                || $telemetry['matched_bucket_count'] >= $bucket_limit
                || microtime(true) >= $deadline
            ) {
                $telemetry['limit_stop_count'] = 1;
                break 2;
            }
            $telemetry['scanned_count']++;

            $bucket = $entry->getFilename();
            if (
                strpos($bucket, $prefix) !== 0
                || preg_match('/^[1-9][0-9]*-[a-f0-9]{12}$/', $bucket) !== 1
                || $entry->isLink()
                || !$entry->isDir()
            ) {
                continue;
            }

            $telemetry['matched_bucket_count']++;
            $deleted = ll_tools_media_proxy_delete_fallback_cache_bucket($entry->getPathname());
            $telemetry['deleted_file_count'] += (int) $deleted['deleted_file_count'];
            $telemetry['deleted_byte_count'] += (int) $deleted['deleted_byte_count'];
            $telemetry['deleted_directory_count'] += (int) $deleted['directory_deleted_count'];
        }

        if (ll_tools_media_proxy_cache_path_is_safe($shard_directory) && @rmdir($shard_directory)) {
            $telemetry['deleted_directory_count']++;
        }
    }

    if (
        is_dir($attachment_shard_directory)
        && ll_tools_media_proxy_cache_path_is_safe($attachment_shard_directory)
        && @rmdir($attachment_shard_directory)
    ) {
        $telemetry['deleted_directory_count']++;
    }

    do_action('ll_tools_media_proxy_delete_attachment_cache_telemetry', $telemetry, $attachment_id);
    return $telemetry;
}

function ll_tools_media_proxy_cache_maintenance_empty_telemetry(): array {
    return [
        'lock_bypass_count' => 0,
        'root_missing_count' => 0,
        'scanned_entry_count' => 0,
        'processed_bucket_count' => 0,
        'orphan_bucket_count' => 0,
        'deleted_file_count' => 0,
        'deleted_byte_count' => 0,
        'deleted_directory_count' => 0,
        'wall_time_stop_count' => 0,
        'scan_limit_stop_count' => 0,
        'shard_probe_count' => 0,
        'next_offset' => 0,
    ];
}

function ll_tools_media_proxy_cache_maintenance_cursor(int $shard_index, int $entry_offset = 0): int {
    $shard_index = max(0, min(0xffff, $shard_index));
    $entry_offset = max(0, min(1024, $entry_offset));
    return ($shard_index * 1025) + $entry_offset;
}

/**
 * Process bounded pages from deterministic attachment/size shards. Any native
 * directory seek is capped at 1,024 entries rather than growing with the site.
 *
 * @param array<string,int|float> $limits
 * @return array<string,int>
 */
function ll_tools_media_proxy_run_cache_maintenance_batch(
    string $cache_root,
    int $offset = 0,
    array $limits = []
): array {
    $telemetry = ll_tools_media_proxy_cache_maintenance_empty_telemetry();
    $resolved_root = ll_tools_media_proxy_fallback_cache_root();
    $cache_root = untrailingslashit(wp_normalize_path($cache_root));
    if (
        is_wp_error($resolved_root)
        || $cache_root !== untrailingslashit(wp_normalize_path($resolved_root))
        || !is_dir($cache_root)
        || !ll_tools_media_proxy_cache_path_is_safe($cache_root, true)
        || is_link($cache_root)
    ) {
        $telemetry['root_missing_count'] = 1;
        return $telemetry;
    }

    $offset = max(0, min(ll_tools_media_proxy_cache_maintenance_cursor(0xffff, 1024), $offset));
    $shard_index = intdiv($offset, 1025);
    $entry_offset = $offset % 1025;
    $scan_limit = max(1, min(512, (int) ($limits['scan_limit'] ?? 256)));
    $bucket_limit = max(1, min(64, (int) ($limits['bucket_limit'] ?? 20)));
    $shard_probe_limit = max(1, min(512, (int) ($limits['shard_probe_limit'] ?? 256)));
    $runtime = max(0.05, min(2.0, (float) ($limits['runtime'] ?? 1.0)));
    $deadline = microtime(true) + $runtime;

    while ($telemetry['shard_probe_count'] < $shard_probe_limit) {
        if ($telemetry['scanned_entry_count'] >= $scan_limit) {
            $telemetry['scan_limit_stop_count'] = 1;
            break;
        }
        if (
            $telemetry['processed_bucket_count'] >= $bucket_limit
            || microtime(true) >= $deadline
        ) {
            $telemetry['wall_time_stop_count'] = microtime(true) >= $deadline ? 1 : 0;
            break;
        }

        $telemetry['shard_probe_count']++;
        $shard_hex = sprintf('%04x', $shard_index);
        $attachment_shard = substr($shard_hex, 0, 3);
        $size_shard = substr($shard_hex, 3, 1);
        $attachment_shard_directory = trailingslashit($cache_root) . $attachment_shard;
        $shard_directory = trailingslashit($attachment_shard_directory) . $size_shard;
        $next_shard_index = ($shard_index + 1) & 0xffff;
        $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor($next_shard_index);

        if (
            !is_dir($shard_directory)
            || is_link($shard_directory)
            || !ll_tools_media_proxy_cache_path_is_safe($shard_directory)
        ) {
            $shard_index = $next_shard_index;
            $entry_offset = 0;
            if ($next_shard_index === 0) {
                break;
            }
            continue;
        }

        try {
            $iterator = new DirectoryIterator($shard_directory);
            if ($entry_offset > 0) {
                $iterator->seek($entry_offset);
            }
        } catch (Throwable $exception) {
            $shard_index = $next_shard_index;
            $entry_offset = 0;
            if ($next_shard_index === 0) {
                break;
            }
            continue;
        }

        $position = $entry_offset;
        while ($iterator->valid()) {
            if ($telemetry['scanned_entry_count'] >= $scan_limit) {
                $telemetry['scan_limit_stop_count'] = 1;
                break 2;
            }
            if (
                $telemetry['processed_bucket_count'] >= $bucket_limit
                || microtime(true) >= $deadline
            ) {
                $telemetry['wall_time_stop_count'] = microtime(true) >= $deadline ? 1 : 0;
                break 2;
            }

            $current_offset = $position;
            $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor(
                $shard_index,
                min(1024, $current_offset + 1)
            );
            $is_dot = $iterator->isDot();
            $bucket = $iterator->getFilename();
            $path = wp_normalize_path($iterator->getPathname());
            $is_link = $iterator->isLink();
            $is_directory = $iterator->isDir();
            $iterator->next();
            $position++;

            if ($is_dot) {
                continue;
            }
            $telemetry['scanned_entry_count']++;
            if (
                preg_match('/^([1-9][0-9]*)-([a-f0-9]{12})$/', $bucket, $matches) !== 1
                || substr((string) $matches[2], 0, 1) !== $size_shard
                || substr(hash('sha256', (string) ((int) $matches[1])), 0, 3) !== $attachment_shard
                || $is_link
                || !$is_directory
                || !ll_tools_media_proxy_cache_path_is_safe($path)
            ) {
                continue;
            }

            $telemetry['processed_bucket_count']++;
            $attachment_id = (int) $matches[1];
            if (get_post_type($attachment_id) !== 'attachment') {
                $telemetry['orphan_bucket_count']++;
                $deleted = ll_tools_media_proxy_delete_fallback_cache_bucket($path);
                $telemetry['deleted_file_count'] += (int) $deleted['deleted_file_count'];
                $telemetry['deleted_byte_count'] += (int) $deleted['deleted_byte_count'];
                $telemetry['deleted_directory_count'] += (int) $deleted['directory_deleted_count'];
                if ((int) $deleted['directory_deleted_count'] > 0) {
                    $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor(
                        $shard_index,
                        min(1024, $current_offset)
                    );
                    $position = max(0, $position - 1);
                }
                continue;
            }

            $pruned = ll_tools_media_proxy_prune_fallback_cache_bucket($path);
            $telemetry['deleted_file_count'] += (int) $pruned['deleted_file_count'];
            $telemetry['deleted_byte_count'] += (int) $pruned['deleted_byte_count'];
            if (ll_tools_media_proxy_cache_path_is_safe($path) && @rmdir($path)) {
                $telemetry['deleted_directory_count']++;
                $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor(
                    $shard_index,
                    min(1024, $current_offset)
                );
                $position = max(0, $position - 1);
            }
        }

        if (!$iterator->valid()) {
            $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor($next_shard_index);
            if (ll_tools_media_proxy_cache_path_is_safe($shard_directory) && @rmdir($shard_directory)) {
                $telemetry['deleted_directory_count']++;
            }
            if (
                is_dir($attachment_shard_directory)
                && ll_tools_media_proxy_cache_path_is_safe($attachment_shard_directory)
                && @rmdir($attachment_shard_directory)
            ) {
                $telemetry['deleted_directory_count']++;
            }
            $shard_index = $next_shard_index;
            $entry_offset = 0;
            if ($next_shard_index === 0) {
                break;
            }
        } else {
            break;
        }
    }

    if ($telemetry['next_offset'] === 0 && ($shard_index !== 0 || $entry_offset !== 0)) {
        $telemetry['next_offset'] = ll_tools_media_proxy_cache_maintenance_cursor(
            $shard_index,
            $entry_offset
        );
    }

    return $telemetry;
}

function ll_tools_run_media_proxy_cache_maintenance(): array {
    $telemetry = ll_tools_media_proxy_cache_maintenance_empty_telemetry();
    $lease = ll_tools_media_proxy_acquire_fallback_cache_lock('global-cache-maintenance', 60);
    if ($lease === null) {
        $telemetry['lock_bypass_count'] = 1;
        do_action('ll_tools_media_proxy_cache_maintenance_telemetry', $telemetry);
        return $telemetry;
    }

    $offset_option = defined('LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION')
        ? LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION
        : '_ll_tools_media_proxy_cache_maintenance_cursor';
    $continuation_hook = defined('LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK')
        ? LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK
        : 'll_tools_media_proxy_cache_maintenance_continuation';

    try {
        $cache_root = ll_tools_media_proxy_fallback_cache_root();
        if (is_wp_error($cache_root) || !is_dir($cache_root)) {
            $telemetry['root_missing_count'] = 1;
        } else {
            $offset = max(0, (int) get_option($offset_option, 0));
            $telemetry = ll_tools_media_proxy_run_cache_maintenance_batch(
                $cache_root,
                $offset,
                [
                    'scan_limit' => (int) apply_filters('ll_tools_media_proxy_cache_maintenance_scan_limit', 256),
                    'bucket_limit' => (int) apply_filters('ll_tools_media_proxy_cache_maintenance_bucket_limit', 20),
                    'shard_probe_limit' => (int) apply_filters('ll_tools_media_proxy_cache_maintenance_shard_probe_limit', 256),
                    'runtime' => (float) apply_filters('ll_tools_media_proxy_cache_maintenance_runtime_seconds', 1.0),
                ]
            );
        }
    } finally {
        ll_tools_media_proxy_release_fallback_cache_lock($lease);
    }

    if ((int) $telemetry['next_offset'] > 0) {
        update_option($offset_option, (int) $telemetry['next_offset'], false);
        if (!wp_next_scheduled($continuation_hook)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, $continuation_hook);
        }
    } else {
        delete_option($offset_option);
        wp_clear_scheduled_hook($continuation_hook);
    }

    do_action('ll_tools_media_proxy_cache_maintenance_telemetry', $telemetry);
    return $telemetry;
}

function ll_tools_media_proxy_clear_cache_maintenance_state(): void {
    $offset_option = defined('LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION')
        ? LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION
        : '_ll_tools_media_proxy_cache_maintenance_cursor';
    delete_option($offset_option);

    $lock_option = ll_tools_media_proxy_fallback_cache_lock_option_name('global-cache-maintenance');
    if ($lock_option !== '') {
        delete_option($lock_option);
        wp_cache_delete($lock_option, 'options');
    }
}

function ll_tools_media_proxy_stream_fallback_file(
    string $path,
    int $attachment_id,
    string $mime,
    int $max_age = 604800
): void {
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];
    $extension = $extensions[$mime] ?? 'img';
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        status_header(503);
        header('Retry-After: 2');
        header('Cache-Control: no-store');
        exit;
    }

    ll_tools_prepare_binary_image_stream();
    status_header(200);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="quiz-image-' . $attachment_id . '.' . $extension . '"');
    $max_age = max(0, $max_age);
    $stale_directive = $max_age >= DAY_IN_SECONDS ? ', stale-while-revalidate=86400' : '';
    header('Cache-Control: public, max-age=' . $max_age . $stale_directive . ', no-transform');
    header('X-Content-Type-Options: nosniff');
    fpassthru($handle);
    fclose($handle);
    exit;
}

/**
 * Resolve current request origin in a proxy-safe way.
 *
 * @return array{scheme:string,host:string,port:int}
 */
function ll_tools_get_request_origin_for_media(): array {
    static $cache = [];
    $cache_key = implode('|', [
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] : '',
        isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '',
        isset($_SERVER['HTTP_X_FORWARDED_PORT']) ? (string) $_SERVER['HTTP_X_FORWARDED_PORT'] : '',
        isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
        isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '',
        isset($_SERVER['HTTPS']) ? (string) $_SERVER['HTTPS'] : '',
    ]);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $scheme = '';
    $host = '';
    $port = 0;

    $trust_forwarded_headers = ll_tools_media_proxy_trust_forwarded_headers();

    $proto_header = $trust_forwarded_headers && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        ? (string) $_SERVER['HTTP_X_FORWARDED_PROTO']
        : '';
    if ($proto_header !== '') {
        $parts = explode(',', $proto_header);
        $candidate = strtolower(trim((string) ($parts[0] ?? '')));
        if ($candidate === 'http' || $candidate === 'https') {
            $scheme = $candidate;
        }
    }
    if ($scheme === '') {
        $scheme = is_ssl() ? 'https' : 'http';
    }

    $host_header = '';
    if ($trust_forwarded_headers && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host_header = (string) $_SERVER['HTTP_X_FORWARDED_HOST'];
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host_header = (string) $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host_header = (string) $_SERVER['SERVER_NAME'];
    }
    if ($host_header !== '') {
        $parts = explode(',', $host_header);
        $host_header = trim((string) ($parts[0] ?? ''));
        $parsed = wp_parse_url($scheme . '://' . $host_header);
        if (is_array($parsed) && !empty($parsed['host'])) {
            $host = strtolower((string) $parsed['host']);
            if (!empty($parsed['port'])) {
                $port = (int) $parsed['port'];
            }
        }
    }

    if ($port <= 0) {
        $port_header = $trust_forwarded_headers && isset($_SERVER['HTTP_X_FORWARDED_PORT'])
            ? (string) $_SERVER['HTTP_X_FORWARDED_PORT']
            : '';
        if ($port_header !== '' && ctype_digit($port_header)) {
            $port = (int) $port_header;
        }
    }
    if ($port <= 0) {
        $port = ($scheme === 'https') ? 443 : 80;
    }

    $resolved = [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
    ];
    $cache[$cache_key] = $resolved;
    return $resolved;
}

/**
 * Whether this request is being served from a different origin than home_url().
 */
function ll_tools_should_rebase_media_urls_to_request_origin(): bool {
    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    // Restrict host rebasing to Local Live Link/tunnel traffic only.
    // Production proxy stacks often set forwarded hosts that don't match the public origin.
    if (!ll_tools_is_live_link_request()) {
        return false;
    }

    $request = ll_tools_get_request_origin_for_media();
    $home = wp_parse_url(home_url('/'));
    if (!is_array($home) || empty($home['host']) || empty($request['host'])) {
        return false;
    }

    $home_scheme = strtolower((string) ($home['scheme'] ?? 'http'));
    $home_host = strtolower((string) $home['host']);
    $home_port = isset($home['port']) ? (int) $home['port'] : (($home_scheme === 'https') ? 443 : 80);

    return !(
        $request['host'] === $home_host
        && (int) $request['port'] === $home_port
        && $request['scheme'] === $home_scheme
    );
}

/**
 * Rebase local media URLs to the current request origin (Live Link-safe).
 */
function ll_tools_rebase_local_media_url_to_request_origin($url): string {
    $url = trim((string) $url);
    if ($url === '' || !ll_tools_should_rebase_media_urls_to_request_origin()) {
        return $url;
    }

    if (strpos($url, '//') === 0) {
        $url = (is_ssl() ? 'https:' : 'http:') . $url;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $parsed = wp_parse_url($url);
    if (!is_array($parsed) || empty($parsed['path'])) {
        return $url;
    }

    $path = '/' . ltrim((string) $parsed['path'], '/');
    $query = isset($parsed['query']) ? (string) $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? (string) $parsed['fragment'] : '';

    $query_args = [];
    if ($query !== '') {
        wp_parse_str($query, $query_args);
    }
    $is_masked_proxy = isset($query_args['lltools-img'], $query_args['lltools-size'], $query_args['lltools-sig']);

    $is_local = false;
    if ($is_masked_proxy) {
        $is_local = true;
    }

    if (!$is_local) {
        $local_path = ABSPATH . ltrim($path, '/');
        $is_local = is_file($local_path) && is_readable($local_path);
    }

    if (!$is_local) {
        $uploads = wp_get_upload_dir();
        $uploads_base_path = '';
        if (empty($uploads['error']) && !empty($uploads['baseurl'])) {
            $uploads_base_path = (string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH);
        }
        if ($uploads_base_path !== '' && strpos($path, $uploads_base_path) === 0) {
            $is_local = true;
        } elseif (strpos($path, '/wp-content/uploads/') === 0) {
            $is_local = true;
        }
    }

    if (!$is_local) {
        return $url;
    }

    $origin = ll_tools_get_request_origin_for_media();
    if (empty($origin['host'])) {
        return $url;
    }
    $out = $origin['scheme'] . '://' . $origin['host'];
    $is_default_port = ($origin['scheme'] === 'https' && (int) $origin['port'] === 443)
        || ($origin['scheme'] === 'http' && (int) $origin['port'] === 80);
    if (!$is_default_port) {
        $out .= ':' . (int) $origin['port'];
    }
    $out .= $path;
    if ($query !== '') {
        $out .= '?' . $query;
    }
    if ($fragment !== '') {
        $out .= '#' . $fragment;
    }

    return $out;
}

/**
 * Rebase attachment URLs when request origin differs from site origin.
 */
function ll_tools_filter_attachment_url_for_request_origin($url): string {
    return ll_tools_rebase_local_media_url_to_request_origin($url);
}

/**
 * Rebase image src URL from wp_get_attachment_image_src().
 */
function ll_tools_filter_attachment_image_src_for_request_origin($image) {
    if (!is_array($image) || empty($image[0])) {
        return $image;
    }
    $image[0] = ll_tools_rebase_local_media_url_to_request_origin((string) $image[0]);
    return $image;
}

/**
 * Rebase srcset candidates for responsive image markup.
 */
function ll_tools_filter_attachment_srcset_for_request_origin($sources) {
    if (!is_array($sources) || empty($sources)) {
        return $sources;
    }
    foreach ($sources as $width => $source) {
        if (!is_array($source) || empty($source['url'])) {
            continue;
        }
        $sources[$width]['url'] = ll_tools_rebase_local_media_url_to_request_origin((string) $source['url']);
    }
    return $sources;
}

/**
 * Detect Local Live Link / tunnel requests that are prone to binary proxy mutation.
 */
function ll_tools_is_live_link_request(): bool {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (ll_tools_is_localsite_tunnel_host($host)) {
        return true;
    }
    if (!ll_tools_media_proxy_trust_tunnel_headers()) {
        return false;
    }
    if (!empty($_SERVER['HTTP_X_TUNNEL_UUID'])) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_LOCAL_HOST'])) {
        return true;
    }
    return false;
}

/**
 * Prepare this response path for raw binary streaming.
 *
 * We intentionally clear all output buffers and disable compression to prevent
 * proxy/PHP double-gzip corruption on Live Link image responses.
 */
function ll_tools_prepare_binary_image_stream(): void {
    // Common cache/minify plugins check these flags.
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
    if (!defined('DONOTMINIFY')) {
        define('DONOTMINIFY', true);
    }
    if (!defined('DONOTROCKETOPTIMIZE')) {
        define('DONOTROCKETOPTIMIZE', true);
    }

    @ini_set('display_errors', '0');
    @ini_set('zlib.output_compression', '0');
    @ini_set('default_charset', '');
    @ini_set('mbstring.http_output', 'pass');
    @ini_set('mbstring.encoding_translation', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    if (function_exists('mb_http_output')) {
        @mb_http_output('pass');
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (function_exists('header_remove')) {
        @header_remove('Content-Encoding');
        @header_remove('Transfer-Encoding');
        @header_remove('Content-Length');
    }
    header('Content-Encoding: identity');
}

/**
 * Stream the masked image when the signed URL is requested.
 */
function ll_tools_maybe_serve_masked_image() {
    if (empty($_GET['lltools-img'])) {
        return;
    }

    $attachment_id = absint($_GET['lltools-img']);
    if ($attachment_id <= 0) {
        return;
    }

    $size = isset($_GET['lltools-size']) ? ll_tools_normalize_image_size(wp_unslash($_GET['lltools-size'])) : 'full';
    $sig  = isset($_GET['lltools-sig']) ? sanitize_text_field(wp_unslash($_GET['lltools-sig'])) : '';
    $expected = hash_hmac('sha256', $attachment_id . '|' . $size, wp_salt('ll-tools-image-proxy'));

    if (!$sig || !hash_equals($expected, $sig)) {
        status_header(403);
        exit;
    }

    $path         = ll_tools_resolve_image_path_for_size($attachment_id, $size);
    $fallback_url = ll_tools_resolve_image_url_for_size($attachment_id, $size);

    // Live Link tunnels can mutate binary bytes from dynamic PHP responses.
    // Redirecting to the static attachment URL avoids corrupt image payloads.
    if (ll_tools_is_live_link_request() && !empty($fallback_url)) {
        $safe_redirect = wp_validate_redirect($fallback_url, '');
        if ($safe_redirect !== '') {
            status_header(302);
            header('Location: ' . $safe_redirect);
            header('Cache-Control: public, max-age=300');
            exit;
        }
    }

    if ($path && file_exists($path) && is_readable($path)) {
        $mime = ll_tools_detect_proxy_image_mime($path, 'image/webp');
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = ($mime === 'image/webp') ? 'webp' : 'jpg';
        }

        ll_tools_prepare_binary_image_stream();
        status_header(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="quiz-image-' . $attachment_id . '.' . $ext . '"');
        header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400, no-transform');
        header('X-Content-Type-Options: nosniff');
        // Do not emit Content-Length for this dynamic endpoint. Some proxies
        // recompress image responses and keep the original length, which
        // corrupts stream framing and causes broken image renders.
        readfile($path);
        exit;
    }

    if (!empty($fallback_url) && ll_tools_media_proxy_fallback_url_is_safe($fallback_url)) {
        $max_bytes = max(64 * KB_IN_BYTES, min(
            32 * MB_IN_BYTES,
            (int) apply_filters('ll_tools_media_proxy_fallback_max_bytes', 8 * MB_IN_BYTES)
        ));
        $cache_ttl = max(5 * MINUTE_IN_SECONDS, min(
            7 * DAY_IN_SECONDS,
            (int) apply_filters('ll_tools_media_proxy_fallback_cache_ttl', DAY_IN_SECONDS)
        ));
        $cache_context = ll_tools_media_proxy_fallback_cache_context($attachment_id, $size, $fallback_url);
        if (!is_wp_error($cache_context)) {
            $cache_path = (string) $cache_context['path'];
            $cache_state = ll_tools_media_proxy_cached_fallback_state(
                $cache_path,
                $max_bytes,
                $cache_ttl,
                ll_tools_media_proxy_fallback_max_stale_age()
            );
            if ($cache_state['fresh']) {
                ll_tools_media_proxy_stream_fallback_file(
                    $cache_path,
                    $attachment_id,
                    (string) $cache_state['mime'],
                    ll_tools_media_proxy_fallback_client_max_age(
                        $cache_ttl,
                        (int) $cache_state['age']
                    )
                );
            }

            $retry_after = ll_tools_media_proxy_fallback_backoff_remaining($cache_context);
            if ($retry_after > 0) {
                if ($cache_state['servable']) {
                    ll_tools_media_proxy_stream_fallback_file(
                        $cache_path,
                        $attachment_id,
                        (string) $cache_state['mime'],
                        60
                    );
                }
                status_header(503);
                header('Retry-After: ' . max(2, $retry_after));
                header('Cache-Control: no-store');
                exit;
            }

            $lease = ll_tools_media_proxy_acquire_fallback_cache_lock((string) $cache_context['key'], 20);
            if ($lease === null) {
                if ($cache_state['servable']) {
                    ll_tools_media_proxy_stream_fallback_file(
                        $cache_path,
                        $attachment_id,
                        (string) $cache_state['mime'],
                        60
                    );
                }

                $waited = ll_tools_media_proxy_wait_for_fallback_cache_file(
                    $cache_path,
                    $max_bytes,
                    0,
                    0,
                    $cache_state['valid'] ? (int) $cache_state['mtime'] : 0
                );
                if (!is_wp_error($waited)) {
                    $waited_mtime = (int) ($waited['mtime'] ?? 0);
                    $waited_age = $waited_mtime > 0
                        ? max(0, time() - $waited_mtime)
                        : $cache_ttl;
                    ll_tools_media_proxy_stream_fallback_file(
                        (string) $waited['path'],
                        $attachment_id,
                        (string) $waited['mime'],
                        ll_tools_media_proxy_fallback_client_max_age($cache_ttl, $waited_age)
                    );
                }

                $retry_after = ll_tools_media_proxy_fallback_backoff_remaining($cache_context);
                if ($retry_after <= 0) {
                    $redirect_url = wp_sanitize_redirect($fallback_url);
                    if ($redirect_url !== '') {
                        status_header(302);
                        header('Location: ' . $redirect_url);
                        header('Cache-Control: no-store');
                        header('X-Content-Type-Options: nosniff');
                        exit;
                    }
                }
                status_header(503);
                header('Retry-After: ' . max(2, $retry_after));
                header('Cache-Control: no-store');
                exit;
            }

            try {
                $downloaded = ll_tools_media_proxy_refresh_fallback_cache(
                    $fallback_url,
                    $cache_context,
                    $max_bytes
                );
            } finally {
                ll_tools_media_proxy_release_fallback_cache_lock($lease);
            }

            if (!is_wp_error($downloaded)) {
                ll_tools_media_proxy_stream_fallback_file(
                    (string) $downloaded['path'],
                    $attachment_id,
                    (string) $downloaded['mime'],
                    $cache_ttl
                );
            }
            $fallback_state = ll_tools_media_proxy_cached_fallback_state(
                $cache_path,
                $max_bytes,
                $cache_ttl,
                ll_tools_media_proxy_fallback_max_stale_age()
            );
            if ($fallback_state['servable']) {
                ll_tools_media_proxy_stream_fallback_file(
                    $cache_path,
                    $attachment_id,
                    (string) $fallback_state['mime'],
                    60
                );
            }

            $retry_after = ll_tools_media_proxy_fallback_backoff_remaining($cache_context);
            status_header(503);
            header('Retry-After: ' . max(2, $retry_after));
            header('Cache-Control: no-store');
            exit;
        }
    }

    status_header(404);
    exit;
}
// Serve masked images on template_redirect so other plugins have a chance
// to register URL filters/mappings during init first (e.g., remote media mappers).
add_action('template_redirect', 'll_tools_maybe_serve_masked_image', 0);
add_action('delete_attachment', 'll_tools_media_proxy_delete_attachment_cache', 10, 1);

// Ensure attachment URLs are request-origin-safe (e.g. Local Live Link host).
add_filter('wp_get_attachment_url', 'll_tools_filter_attachment_url_for_request_origin', 20, 1);
add_filter('wp_get_attachment_image_src', 'll_tools_filter_attachment_image_src_for_request_origin', 20, 1);
add_filter('wp_calculate_image_srcset', 'll_tools_filter_attachment_srcset_for_request_origin', 20, 1);
