<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME', 'll-public-static-cache');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_TTL')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_TTL', DAY_IN_SECONDS);
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_BROWSER_MAX_AGE')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_BROWSER_MAX_AGE', 5 * MINUTE_IN_SECONDS);
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_MAX_BYTES')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_MAX_BYTES', 5 * 1024 * 1024);
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER', '%%LL_TOOLS_WORDSET_LAZY_CARDS_NONCE%%');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_CATEGORY_SEARCH_NONCE_PLACEHOLDER')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_CATEGORY_SEARCH_NONCE_PLACEHOLDER', '%%LL_TOOLS_WORDSET_CATEGORY_SEARCH_NONCE%%');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX', '%%LL_TOOLS_VOCAB_LESSON_GRID_NONCE_');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_REBUILD_LOCK_OPTION_PREFIX')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_REBUILD_LOCK_OPTION_PREFIX', '_ll_tools_public_static_cache_lock_');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_OPTION')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_OPTION', 'll_tools_public_static_cache_generation');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA', 3);
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION', 'll_tools_public_static_cache_schema');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX', 'll_tools_public_static_cache_generation_');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_FILENAME')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_FILENAME', '.ll-tools-public-static-cache-quarantine');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_LOCK_FILENAME')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_LOCK_FILENAME', '.ll-tools-public-static-cache-quarantine.lock');
}

/**
 * Return the anonymous public-page static-cache TTL.
 */
function ll_tools_public_static_cache_ttl(): int {
    $ttl = defined('LL_TOOLS_PUBLIC_STATIC_CACHE_TTL')
        ? (int) constant('LL_TOOLS_PUBLIC_STATIC_CACHE_TTL')
        : (int) LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_TTL;

    return max(60, (int) apply_filters('ll_tools_public_static_cache_ttl', $ttl));
}

/**
 * Return a nonce-safe browser/downstream lifetime for cached public HTML.
 */
function ll_tools_public_static_cache_browser_max_age(): int {
    $max_age = defined('LL_TOOLS_PUBLIC_STATIC_CACHE_BROWSER_MAX_AGE')
        ? (int) constant('LL_TOOLS_PUBLIC_STATIC_CACHE_BROWSER_MAX_AGE')
        : (int) LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_BROWSER_MAX_AGE;
    $max_age = max(0, (int) apply_filters('ll_tools_public_static_cache_browser_max_age', $max_age));

    // Cached HTML contains request nonces refreshed on every file-cache HIT.
    // Keep downstream freshness comfortably below the shortest valid nonce
    // window, including sites that customize WordPress' nonce lifetime.
    $nonce_life = max(MINUTE_IN_SECONDS, (int) apply_filters(
        'nonce_life',
        DAY_IN_SECONDS,
        'll_tools_public_static_cache'
    ));
    $nonce_safe_max_age = max(0, (int) floor($nonce_life / 4));

    return min($max_age, $nonce_safe_max_age);
}

/**
 * Return the maximum public-page static-cache payload size in bytes.
 *
 * A value of 0 disables the max-byte guard.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 */
function ll_tools_public_static_cache_max_bytes(?array $identity = null): int {
    $max_bytes = defined('LL_TOOLS_PUBLIC_STATIC_CACHE_MAX_BYTES')
        ? (int) constant('LL_TOOLS_PUBLIC_STATIC_CACHE_MAX_BYTES')
        : (int) LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_MAX_BYTES;

    return max(0, (int) apply_filters('ll_tools_public_static_cache_max_bytes', $max_bytes, $identity));
}

/**
 * Return the uploads-backed public static-cache directory.
 */
function ll_tools_public_static_cache_dir(): string {
    $uploads = wp_upload_dir(null, false, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    return trailingslashit((string) $uploads['basedir']) . LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME;
}

/**
 * Return the local fail-closed marker used while a purge is incomplete.
 */
function ll_tools_public_static_cache_quarantine_file(): string {
    $dir = ll_tools_public_static_cache_dir();

    return $dir !== '' ? trailingslashit($dir) . LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_FILENAME : '';
}

/**
 * Open the stable lock shared by quarantine marker writers and clearers.
 *
 * @return resource|null
 */
function ll_tools_public_static_cache_open_quarantine_lock() {
    $dir = ll_tools_public_static_cache_dir();
    if ($dir === '' || !wp_mkdir_p($dir)) {
        return null;
    }

    $handle = @fopen(trailingslashit($dir) . LL_TOOLS_PUBLIC_STATIC_CACHE_QUARANTINE_LOCK_FILENAME, 'c+');
    if (!is_resource($handle)) {
        return null;
    }
    if (!@flock($handle, LOCK_EX)) {
        @fclose($handle);
        return null;
    }

    return $handle;
}

/**
 * @param resource $handle
 */
function ll_tools_public_static_cache_close_quarantine_lock($handle): void {
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

/**
 * Disable public-cache reads and publications across PHP workers.
 */
function ll_tools_public_static_cache_quarantine(string $owner_token = ''): bool {
    if ($owner_token === '') {
        $owner_token = wp_generate_password(32, false, false);
    }
    $GLOBALS['ll_tools_public_static_cache_quarantined'] = true;
    $GLOBALS['ll_tools_public_static_cache_quarantine_owner'] = $owner_token;
    $file = ll_tools_public_static_cache_quarantine_file();
    $lock = ll_tools_public_static_cache_open_quarantine_lock();
    if ($file === '' || !is_resource($lock)) {
        return false;
    }

    try {
        $state = ll_tools_public_static_cache_read_quarantine_state($file);
        if ($state === false) {
            return false;
        }
        $state['failure'] = $owner_token;
        return ll_tools_public_static_cache_write_quarantine_state($file, $state);
    } finally {
        ll_tools_public_static_cache_close_quarantine_lock($lock);
    }
}

function ll_tools_public_static_cache_is_quarantined(): bool {
    if (!empty($GLOBALS['ll_tools_public_static_cache_quarantined'])) {
        return true;
    }

    $file = ll_tools_public_static_cache_quarantine_file();
    if ($file !== '') { clearstatcache(true, $file); }
    return $file !== '' && is_file($file);
}

/**
 * Re-enable the cache only after every requested purge fence has succeeded.
 */
function ll_tools_public_static_cache_clear_quarantine(string $owner_token): bool {
    $file = ll_tools_public_static_cache_quarantine_file();
    $lock = ll_tools_public_static_cache_open_quarantine_lock();
    if ($file === '' || !is_resource($lock)) {
        return false;
    }

    try {
        if (is_file($file)) {
            $state = ll_tools_public_static_cache_read_quarantine_state($file);
            if ($state === false || !empty($state['active']) || !hash_equals($owner_token, $state['failure']) || !@unlink($file)) {
                return false;
            }
        }
    } finally {
        ll_tools_public_static_cache_close_quarantine_lock($lock);
    }

    if (hash_equals($owner_token, (string) ($GLOBALS['ll_tools_public_static_cache_quarantine_owner'] ?? ''))) {
        unset(
            $GLOBALS['ll_tools_public_static_cache_quarantined'],
            $GLOBALS['ll_tools_public_static_cache_quarantine_owner']
        );
    }
    return true;
}

/** @return array{active:array<string,int>,failure:string}|false */
function ll_tools_public_static_cache_read_quarantine_state(string $file) {
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return ['active' => [], 'failure' => ''];
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        return false;
    }
    $state = json_decode($raw, true);
    if (is_array($state) && isset($state['active'], $state['failure']) && is_array($state['active']) && is_string($state['failure'])) {
        return $state;
    }
    // Preserve markers written by the previous plugin version or a failed
    // publisher. Only a successful full purge may recover this failure.
    return ['active' => [], 'failure' => $raw !== '' ? $raw : 'unreadable-owner'];
}

function ll_tools_public_static_cache_write_quarantine_state(string $file, array $state): bool {
    $value = empty($state['active']) ? (string) $state['failure'] : wp_json_encode($state);
    if ($value === '') {
        return !is_file($file) || @unlink($file);
    }
    return is_string($value) && @file_put_contents($file, $value, LOCK_EX) === strlen($value);
}

/**
 * Register each overlapping purge without overwriting another worker's owner.
 * Expired owners become a retained failure until a full recovery purge succeeds.
 *
 * @return array|false
 */
function ll_tools_public_static_cache_begin_purge(string $owner) {
    $file = ll_tools_public_static_cache_quarantine_file();
    $lock = ll_tools_public_static_cache_open_quarantine_lock();
    if ($file === '' || !is_resource($lock)) {
        return false;
    }
    try {
        $state = ll_tools_public_static_cache_read_quarantine_state($file);
        if ($state === false) {
            return false;
        }
        foreach ($state['active'] as $token => $started_at) {
            if ((int) $started_at <= time() - 10 * MINUTE_IN_SECONDS) {
                unset($state['active'][$token]);
                $state['failure'] = 'expired-' . $token;
            }
        }
        if (empty($state['failure']) && !empty($GLOBALS['ll_tools_public_static_cache_quarantined'])) {
            $state['failure'] = (string) ($GLOBALS['ll_tools_public_static_cache_quarantine_owner'] ?? 'failed-marker-write');
        }
        $previous_failure = $state['failure'];
        $state['active'][$owner] = time();
        return ll_tools_public_static_cache_write_quarantine_state($file, $state)
            ? ['previous_failure' => $previous_failure]
            : false;
    } finally {
        ll_tools_public_static_cache_close_quarantine_lock($lock);
    }
}

/**
 * Remove only this purge's owner. Full recovery may clear the failure observed
 * at admission, but never a later publisher/purge failure or an active owner.
 */
function ll_tools_public_static_cache_finish_purge(string $owner, bool $success, ?string $recover_failure = null): bool {
    $file = ll_tools_public_static_cache_quarantine_file();
    $lock = ll_tools_public_static_cache_open_quarantine_lock();
    if ($file === '' || !is_resource($lock)) {
        return false;
    }
    try {
        $state = ll_tools_public_static_cache_read_quarantine_state($file);
        if ($state === false || !isset($state['active'][$owner])) {
            return false;
        }
        unset($state['active'][$owner]);
        if (!$success) {
            $state['failure'] = $owner;
        } elseif ($recover_failure !== null && hash_equals($recover_failure, $state['failure'])) {
            $state['failure'] = '';
        }
        if (!ll_tools_public_static_cache_write_quarantine_state($file, $state)) {
            return false;
        }
        if (empty($state['active']) && $state['failure'] === '') {
            unset($GLOBALS['ll_tools_public_static_cache_quarantined'], $GLOBALS['ll_tools_public_static_cache_quarantine_owner']);
        }
        return true;
    } finally {
        ll_tools_public_static_cache_close_quarantine_lock($lock);
    }
}

/**
 * Return the normalized current request path.
 */
function ll_tools_public_static_cache_request_path(): string {
    if (function_exists('ll_tools_dictionary_static_cache_request_path')) {
        return ll_tools_dictionary_static_cache_request_path();
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $path = rawurldecode($path);
    $path = '/' . trim($path, '/');

    return $path === '/' ? '/' : untrailingslashit($path);
}

/**
 * Query args that are meaningful for anonymous public read-only pages.
 *
 * @return string[]
 */
function ll_tools_public_static_cache_query_keys(): array {
    $keys = ['ll_locale', 'll_text_view', 'll_translation', 'll_book_language', 'll_book_section'];
    $filtered = apply_filters('ll_tools_public_static_cache_query_keys', $keys);
    if (!is_array($filtered)) {
        return $keys;
    }

    return array_values(array_unique(array_filter(array_map('strval', $filtered))));
}

/**
 * Return whether the request carries front-end authentication state.
 *
 * These arguments intentionally do not participate in public cache keys: the
 * rendered form can contain one-time feedback, prefilled identifiers, nonces,
 * and a registration challenge. Their presence therefore has to bypass both
 * public-cache reads and captures instead of sharing the ordinary page key.
 *
 * @param array<string,mixed>|null $raw_args
 */
function ll_tools_public_static_cache_has_frontend_auth_args(?array $raw_args = null): bool {
    if ($raw_args === null) {
        $raw_args = $_GET;
    }

    foreach (['ll_tools_auth', 'll_tools_auth_feedback'] as $key) {
        if (array_key_exists($key, $raw_args)) {
            return true;
        }
    }

    return false;
}

/**
 * Return whether this request is the one-shot category-search recovery load.
 *
 * The marker is deliberately exact: other values remain ordinary query noise.
 *
 * @param array<string,mixed>|null $raw_args
 */
function ll_tools_public_static_cache_has_category_search_recovery_arg(?array $raw_args = null): bool {
    if ($raw_args === null) {
        $raw_args = $_GET;
    }
    if (!array_key_exists('ll_category_search_recovery', $raw_args)) {
        return false;
    }

    $value = wp_unslash($raw_args['ll_category_search_recovery']);
    return is_scalar($value) && (string) $value === '1';
}

/**
 * Return the downstream cache policy for an authentication-bearing response.
 */
function ll_tools_public_static_cache_auth_cache_control_value(): string {
    return 'private, no-store, no-cache, must-revalidate, max-age=0';
}

function ll_tools_public_static_cache_category_search_recovery_cache_control_value(): string {
    return ll_tools_public_static_cache_auth_cache_control_value();
}

/**
 * Keep authentication and one-time feedback responses out of browser/CDN
 * caches as well as the plugin's own public static cache.
 */
function ll_tools_public_static_cache_send_auth_bypass_headers(): void {
    if (headers_sent()) {
        return;
    }

    header('X-LL-Public-Static-Cache: BYPASS');
    header('X-LL-Public-Static-Cache-Reason: frontend-auth');
    header('Cache-Control: ' . ll_tools_public_static_cache_auth_cache_control_value());
    header('Pragma: no-cache');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Vary: Accept-Language, Cookie', false);
}

/**
 * Keep the one-shot search recovery response out of browser/CDN caches so it
 * must mint and render a fresh category-search payload before JS removes the
 * marker from the visible URL.
 */
function ll_tools_public_static_cache_send_category_search_recovery_bypass_headers(): void {
    if (headers_sent()) {
        return;
    }

    header('X-LL-Public-Static-Cache: BYPASS');
    header('X-LL-Public-Static-Cache-Reason: category-search-recovery');
    header('Cache-Control: ' . ll_tools_public_static_cache_category_search_recovery_cache_control_value());
    header('Pragma: no-cache');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Vary: Accept-Language, Cookie', false);
}

/**
 * Normalize public-page display query args for cache-key generation.
 *
 * @param array<string,mixed>|null $raw_args
 * @return array<string,mixed>
 */
function ll_tools_public_static_cache_normalize_query_args(?array $raw_args = null, ?array $identity = null): array {
    if ($raw_args === null) {
        $raw_args = $_GET;
    }

    $identity = $identity ?? ll_tools_public_static_cache_request_identity();
    // The effective locale already varies the key. Translation/book language
    // selectors no longer affect rendering. Wordset/vocabulary routes consume
    // none of these display args; do not create files for ignored query noise.
    if (($identity['type'] ?? '') !== 'content_lesson') {
        return [];
    }

    $view = $raw_args['ll_text_view'] ?? '';
    $view = is_scalar($view) ? sanitize_key(trim((string) wp_unslash($view))) : '';
    $view = $view === 'linguist' ? 'interlinear' : $view;
    return in_array($view, ['interlinear', 'sources'], true)
        ? ['ll_text_view' => $view]
        : [];
}

/**
 * Only finite built-in display variants may read/publish static HTML.
 * Book sections and extension-defined selectors keep their normal dynamic
 * rendering without parsing an entire text document on a cache HIT.
 */
function ll_tools_public_static_cache_has_supported_display_args(?array $raw_args = null): bool {
    $raw_args = $raw_args ?? $_GET;
    $built_in = ['ll_locale', 'll_text_view', 'll_translation', 'll_book_language', 'll_book_section'];
    foreach (ll_tools_public_static_cache_query_keys() as $key) {
        if (!array_key_exists($key, $raw_args)) {
            continue;
        }
        $value = wp_unslash($raw_args[$key]);
        if (!is_scalar($value) || strlen((string) $value) > 128) {
            return false;
        }
        if (trim((string) $value) !== '' && (!in_array($key, $built_in, true) || $key === 'll_book_section')) {
            return false;
        }
    }
    return true;
}

/**
 * Admit cold anonymous work across cache keys; cached responses spend no budget.
 * Existing atomic fixed-window counters avoid one ledger per attacker variant.
 *
 * @return array{allowed:bool,retry_after:int}
 */
function ll_tools_public_static_cache_reserve_miss(): array {
    $defaults = ['window' => MINUTE_IN_SECONDS, 'ip_limit' => 30, 'site_limit' => 120];
    $config = apply_filters('ll_tools_public_static_cache_miss_throttle', $defaults);
    $config = is_array($config) ? array_merge($defaults, $config) : $defaults;
    $window = max(1, min(HOUR_IN_SECONDS, (int) $config['window']));
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
        ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    $reservations = [];
    foreach ([['ip', $ip, (int) $config['ip_limit']], ['site', 'site', (int) $config['site_limit']]] as $target) {
        $reservation = ll_tools_public_ajax_reserve_counter('ll_public_static_miss_' . $target[0] . '_', $target[1], max(0, $target[2]), $window);
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

function ll_tools_public_static_cache_admit_cold_request(string $key = '', ?array $identity = null): void {
    $reservation = ll_tools_public_static_cache_reserve_miss();
    if (!empty($reservation['allowed'])) {
        return;
    }
    if ($key !== '') {
        ll_tools_public_static_cache_release_rebuild_lock($key);
        $cached = ll_tools_public_static_cache_read_prepared_file(ll_tools_public_static_cache_file_path($key), $identity);
        if (is_string($cached)) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            ll_tools_public_static_cache_send_prepared_file_response($cached, 'STALE', 'cold-budget', false, $method);
        }
    }
    if (!headers_sent()) {
        header('Retry-After: ' . max(1, (int) $reservation['retry_after']));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-LL-Public-Static-Cache: RATE_LIMITED');
    }
    wp_die(__('Too many requests. Please wait a moment and try again.', 'll-tools-text-domain'), '', ['response' => 429]);
}

function ll_tools_public_static_cache_status_code_from_headers(array $headers): int {
    for ($index = count($headers) - 1; $index >= 0; $index--) {
        $header = trim((string) $headers[$index]);
        if ($header === '') {
            continue;
        }

        if (preg_match('/^HTTP\/\S+\s+([1-5][0-9]{2})\b/i', $header, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^Status:\s*([1-5][0-9]{2})\b/i', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function ll_tools_public_static_cache_current_status_code(): int {
    $status = function_exists('http_response_code') ? http_response_code() : 200;
    if (is_numeric($status)) {
        $status_code = (int) $status;
        if ($status_code >= 100 && $status_code <= 599) {
            return $status_code;
        }
    }

    $header_status = function_exists('headers_list')
        ? ll_tools_public_static_cache_status_code_from_headers(headers_list())
        : 0;
    if ($header_status >= 100 && $header_status <= 599) {
        return $header_status;
    }

    return 200;
}

function ll_tools_public_static_cache_debug_enabled(): bool {
    $enabled = defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DEBUG') && (bool) constant('LL_TOOLS_PUBLIC_STATIC_CACHE_DEBUG');
    return (bool) apply_filters('ll_tools_public_static_cache_debug_enabled', $enabled);
}

function ll_tools_public_static_cache_debug_log(string $event, array $context = []): void {
    if (!ll_tools_public_static_cache_debug_enabled() || !function_exists('error_log')) {
        return;
    }

    $safe_context = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safe_context[(string) $key] = $value;
        }
    }

    error_log('[ll-tools-public-static-cache] ' . $event . ' ' . wp_json_encode($safe_context)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

function ll_tools_public_static_cache_miss_reason(string $file, int $ttl): string {
    if ($file === '') {
        return 'no-cache-path';
    }

    if (!file_exists($file)) {
        return 'missing';
    }

    if (!is_readable($file)) {
        return 'unreadable';
    }

    $mtime = filemtime($file);
    if ($mtime === false) {
        return 'no-mtime';
    }

    if ((time() - (int) $mtime) >= $ttl) {
        return 'stale';
    }

    return 'unusable';
}

/**
 * Determine whether a request is a baseline cacheable anonymous HTML request.
 */
function ll_tools_public_static_cache_has_safe_request_shape(): bool {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return false;
    }
    if (is_admin()) {
        return false;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }
    if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return false;
    }
    if (is_user_logged_in()) {
        return false;
    }
    if (ll_tools_public_static_cache_has_frontend_auth_args()) {
        return false;
    }
    if (ll_tools_public_static_cache_has_category_search_recovery_arg()) {
        return false;
    }
    $is_wordset_page_request = function_exists('ll_tools_is_wordset_page_context') && ll_tools_is_wordset_page_context();
    if (!$is_wordset_page_request && (is_front_page() || is_home())) {
        return false;
    }
    if (is_preview() || (function_exists('is_customize_preview') && is_customize_preview())) {
        return false;
    }

    foreach (['preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_theme', 'customize_messenger_channel'] as $key) {
        if (isset($_GET[$key])) {
            return false;
        }
    }

    // Every locale switch, including invalid/unsigned values, must reach the
    // priority-10 switch handler's clean redirect before any cached HTML.
    if (array_key_exists('ll_locale', $_GET)) {
        return false;
    }

    return true;
}

/**
 * Return the public-cache identity for the current request.
 *
 * @return array{type:string,id:int,path:string,wordset_id?:int}|null
 */
function ll_tools_public_static_cache_request_identity(): ?array {
    if (is_404()) {
        return null;
    }

    if (function_exists('ll_tools_is_wordset_page_context') && ll_tools_is_wordset_page_context()) {
        $view = function_exists('ll_tools_get_requested_wordset_page_view_raw')
            ? trim((string) ll_tools_get_requested_wordset_page_view_raw())
            : trim((string) get_query_var('ll_wordset_view'));
        if ($view !== '') {
            return null;
        }

        $term = function_exists('ll_tools_get_wordset_page_term') ? ll_tools_get_wordset_page_term() : null;
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return null;
        }
        if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($term, 0)) {
            return null;
        }

        return [
            'type' => 'wordset_main',
            'id' => (int) $term->term_id,
            'path' => ll_tools_public_static_cache_request_path(),
            'wordset_id' => (int) $term->term_id,
        ];
    }

    if (is_singular('ll_vocab_lesson')) {
        $post_id = (int) get_queried_object_id();
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (
            !($post instanceof WP_Post)
            || $post->post_status !== 'publish'
            || (string) $post->post_password !== ''
        ) {
            return null;
        }
        if (function_exists('ll_tools_is_vocab_lesson_print_request') && ll_tools_is_vocab_lesson_print_request()) {
            return null;
        }

        $wordset_id = defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
            ? (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true)
            : 0;
        if ($wordset_id > 0 && function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id, 0)) {
            return null;
        }

        return [
            'type' => 'vocab_lesson',
            'id' => $post_id,
            'path' => ll_tools_public_static_cache_request_path(),
            'wordset_id' => $wordset_id,
        ];
    }

    if (is_singular('ll_content_lesson')) {
        $post_id = (int) get_queried_object_id();
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (
            !($post instanceof WP_Post)
            || $post->post_status !== 'publish'
            || (string) $post->post_password !== ''
        ) {
            return null;
        }

        $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
            ? ll_tools_get_content_lesson_wordset_id($post_id)
            : 0;
        if ($wordset_id > 0 && function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id, 0)) {
            return null;
        }

        return [
            'type' => 'content_lesson',
            'id' => $post_id,
            'path' => ll_tools_public_static_cache_request_path(),
            'wordset_id' => $wordset_id,
        ];
    }

    return null;
}

/**
 * Determine whether the current request can use the anonymous public static cache.
 */
function ll_tools_is_cacheable_public_static_request(): bool {
    if ((int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, 0) < LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA
        || ll_tools_public_static_cache_is_quarantined()) {
        return false;
    }

    if (!ll_tools_public_static_cache_has_safe_request_shape()) {
        return false;
    }

    $identity = ll_tools_public_static_cache_request_identity();
    if ($identity === null) {
        return false;
    }

    if (!ll_tools_public_static_cache_has_supported_display_args()) {
        return false;
    }

    return (bool) apply_filters('ll_tools_public_static_cache_is_cacheable_request', true, $identity);
}

/**
 * Build a public static cache key.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 * @param array<string,mixed>|null                                  $raw_args
 */
function ll_tools_public_static_cache_key(?array $identity = null, ?array $raw_args = null, ?string $locale = null): string {
    if ($identity === null) {
        $identity = ll_tools_public_static_cache_request_identity() ?: [
            'type' => 'unknown',
            'id' => 0,
            'path' => ll_tools_public_static_cache_request_path(),
        ];
    }

    if ($locale === null) {
        $locale = function_exists('get_locale') ? (string) get_locale() : '';
    }

    $payload = [
        'schema' => LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'site' => home_url('/'),
        'identity' => [
            'type' => sanitize_key((string) ($identity['type'] ?? 'unknown')),
            'id' => max(0, (int) ($identity['id'] ?? 0)),
            'path' => (string) ($identity['path'] ?? ''),
            'wordset_id' => max(0, (int) ($identity['wordset_id'] ?? 0)),
        ],
        'locale' => $locale,
        'args' => ll_tools_public_static_cache_normalize_query_args($raw_args, $identity),
    ];

    return md5((string) wp_json_encode($payload));
}

/**
 * Return the final cache file path for a key.
 */
function ll_tools_public_static_cache_file_path(string $key): string {
    $key = preg_replace('/[^a-f0-9]/', '', strtolower($key));
    if (!is_string($key) || $key === '') {
        return '';
    }

    $dir = ll_tools_public_static_cache_dir();
    if ($dir === '') {
        return '';
    }

    return trailingslashit($dir) . 'public-' . $key . '.html';
}

/**
 * Open one stable bounded-stripe lock for cache publication or reading.
 *
 * The 256 lock stripes prevent bot-controlled cache keys from creating an
 * unbounded number of sidecars. A non-blocking lock keeps requests bounded;
 * losing a stripe collision simply leaves this response uncached.
 *
 * @return resource|null
 */
function ll_tools_public_static_cache_open_publication_lock(string $file, int $operation = LOCK_EX) {
    $dir = ll_tools_public_static_cache_dir();
    if ($file === '' || $dir === '' || !wp_mkdir_p($dir)) {
        return null;
    }

    $operation = $operation === LOCK_SH ? LOCK_SH : LOCK_EX;
    $stripe = substr(hash('sha256', wp_normalize_path($file)), 0, 2);
    $lock_file = trailingslashit($dir) . '.ll-tools-public-static-publish-' . $stripe . '.lock';
    $handle = @fopen($lock_file, 'c+');
    if (!is_resource($handle)) {
        return null;
    }
    if (!@flock($handle, $operation | LOCK_NB)) {
        @fclose($handle);
        return null;
    }

    return $handle;
}

/**
 * @param resource $handle
 */
function ll_tools_public_static_cache_close_publication_lock($handle): void {
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function ll_tools_public_static_cache_rebuild_lock_option(string $key): string {
    $normalized_key = preg_replace('/[^a-f0-9]/', '', strtolower($key));
    if (!is_string($normalized_key) || $normalized_key === '') {
        $normalized_key = md5($key);
    }

    return LL_TOOLS_PUBLIC_STATIC_CACHE_REBUILD_LOCK_OPTION_PREFIX . $normalized_key;
}

function ll_tools_public_static_cache_rebuild_lock_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_public_static_cache_rebuild_lock_ttl', 30);
    return max(5, min(300, $ttl));
}

function ll_tools_public_static_cache_acquire_rebuild_lock(string $key): bool {
    global $wpdb;

    $option_name = ll_tools_public_static_cache_rebuild_lock_option($key);
    $now = time();
    $expires_at = $now + ll_tools_public_static_cache_rebuild_lock_ttl();
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $key . '|' . microtime(true) . '|' . wp_rand());
    $lease_value = $expires_at . '|' . $token;

    $wpdb->last_error = '';
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
         VALUES (%s, %s, 'no')",
        $option_name,
        $lease_value
    ));
    wp_cache_delete($option_name, 'options');
    if ($inserted === false || (string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return false;
    }
    if ($inserted === 1) {
        if (!isset($GLOBALS['ll_tools_public_static_cache_rebuild_leases']) || !is_array($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])) {
            $GLOBALS['ll_tools_public_static_cache_rebuild_leases'] = [];
        }
        $GLOBALS['ll_tools_public_static_cache_rebuild_leases'][$key] = [
            'option_name' => $option_name,
            'lease_value' => $lease_value,
        ];
        return true;
    }

    $wpdb->last_error = '';
    $current_value = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $option_name
    ));
    if ((string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return false;
    }
    $separator = strpos($current_value, '|');
    $current_expires_at = $separator === false
        ? (int) $current_value
        : (int) substr($current_value, 0, $separator);
    if ($current_expires_at > $now) {
        return false;
    }

    $wpdb->last_error = '';
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $lease_value,
        $option_name,
        $current_value
    ));
    wp_cache_delete($option_name, 'options');
    if ($updated !== 1 || (string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return false;
    }

    if (!isset($GLOBALS['ll_tools_public_static_cache_rebuild_leases']) || !is_array($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])) {
        $GLOBALS['ll_tools_public_static_cache_rebuild_leases'] = [];
    }
    $GLOBALS['ll_tools_public_static_cache_rebuild_leases'][$key] = [
        'option_name' => $option_name,
        'lease_value' => $lease_value,
    ];

    return true;
}

function ll_tools_public_static_cache_release_rebuild_lock(string $key): void {
    global $wpdb;

    $leases = isset($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])
        && is_array($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])
        ? $GLOBALS['ll_tools_public_static_cache_rebuild_leases']
        : [];
    $lease = isset($leases[$key]) && is_array($leases[$key]) ? $leases[$key] : [];
    $option_name = (string) ($lease['option_name'] ?? '');
    $lease_value = (string) ($lease['lease_value'] ?? '');
    if ($option_name === '' || $lease_value === '') {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s AND option_value = %s",
        $option_name,
        $lease_value
    ));
    wp_cache_delete($option_name, 'options');
    unset($GLOBALS['ll_tools_public_static_cache_rebuild_leases'][$key]);
}

function ll_tools_public_static_cache_get_rebuild_lease_value(string $key): string {
    $leases = isset($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])
        && is_array($GLOBALS['ll_tools_public_static_cache_rebuild_leases'])
        ? $GLOBALS['ll_tools_public_static_cache_rebuild_leases']
        : [];
    $lease = isset($leases[$key]) && is_array($leases[$key]) ? $leases[$key] : [];

    return (string) ($lease['lease_value'] ?? '');
}

function ll_tools_public_static_cache_owns_rebuild_lock(string $key, string $lease_value): bool {
    global $wpdb;

    if ($key === '' || $lease_value === '') {
        return false;
    }

    $option_name = ll_tools_public_static_cache_rebuild_lock_option($key);
    $wpdb->last_error = '';
    $stored_value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $option_name
    ));
    if ((string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return false;
    }

    return is_scalar($stored_value) && hash_equals($lease_value, (string) $stored_value);
}

function ll_tools_public_static_cache_new_generation(): string {
    return function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', microtime(true) . '|' . wp_rand());
}

function ll_tools_public_static_cache_read_generation_option(string $option_name, bool $initialize = false): string {
    global $wpdb;

    $wpdb->last_error = '';
    $generation = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $option_name
    ));
    if ((string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return '';
    }
    if (is_scalar($generation) && (string) $generation !== '') {
        return (string) $generation;
    }

    if (!$initialize) {
        return '0';
    }

    $generation = ll_tools_public_static_cache_new_generation();
    $wpdb->last_error = '';
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
         VALUES (%s, %s, 'no')",
        $option_name,
        $generation
    ));
    wp_cache_delete($option_name, 'options');
    if ($inserted === 1) {
        return $generation;
    }
    if ($inserted === false || (string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return '';
    }

    $wpdb->last_error = '';
    $stored_generation = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $option_name
    ));

    if ((string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return '';
    }

    return is_scalar($stored_generation) ? (string) $stored_generation : '';
}

/**
 * Build the generation fence for one cache identity.
 *
 * Targeted purges rotate only the affected resource components so unrelated
 * cached pages stay reachable.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 */
function ll_tools_public_static_cache_generation(?array $identity = null): string {
    $components = [
        'global' => ll_tools_public_static_cache_read_generation_option(
            LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_OPTION,
            true
        ),
    ];
    if ($components['global'] === '') {
        return '';
    }

    $identity = is_array($identity) ? $identity : [];
    $wordset_id = max(0, (int) ($identity['wordset_id'] ?? 0));
    $post_id = max(0, (int) ($identity['id'] ?? 0));
    $type = sanitize_key((string) ($identity['type'] ?? ''));
    if ($wordset_id > 0) {
        $components['wordset'] = ll_tools_public_static_cache_read_generation_option(
            LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'wordset_' . $wordset_id
        );
    }
    if ($post_id > 0 && $type !== 'wordset_main') {
        $components['post'] = ll_tools_public_static_cache_read_generation_option(
            LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'post_' . $post_id
        );
    }
    if ($type !== '') {
        $components['type'] = ll_tools_public_static_cache_read_generation_option(
            LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'type_' . $type
        );
    }
    if (in_array('', $components, true)) {
        return '';
    }

    return hash('sha256', (string) wp_json_encode($components));
}

function ll_tools_public_static_cache_write_generation_option(string $option_name, string $generation): bool {
    global $wpdb;

    $wpdb->last_error = '';
    $updated = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
         VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
        $option_name,
        $generation
    ));
    wp_cache_delete($option_name, 'options');
    if ($updated === false || (string) $wpdb->last_error !== '') {
        $wpdb->last_error = '';
        return false;
    }

    return true;
}

function ll_tools_public_static_cache_rotate_generation(array $criteria = []): string {
    $criteria = ll_tools_public_static_cache_normalize_purge_criteria($criteria);
    $option_names = [];
    foreach ((array) ($criteria['wordset_ids'] ?? []) as $wordset_id) {
        $option_names[] = LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'wordset_' . (int) $wordset_id;
    }
    foreach ((array) ($criteria['post_ids'] ?? []) as $post_id) {
        $option_names[] = LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'post_' . (int) $post_id;
    }
    foreach ((array) ($criteria['types'] ?? []) as $type) {
        $option_names[] = LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_SCOPE_PREFIX . 'type_' . sanitize_key((string) $type);
    }
    if (empty($option_names)) {
        $option_names[] = LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_OPTION;
    }

    $generation = ll_tools_public_static_cache_new_generation();
    foreach (array_values(array_unique($option_names)) as $option_name) {
        if (!ll_tools_public_static_cache_write_generation_option((string) $option_name, $generation)) {
            // A global rotation is the safe fallback after any partial scoped
            // write: it makes every pre-purge capture unreadable.
            $fallback = ll_tools_public_static_cache_new_generation();
            return ll_tools_public_static_cache_write_generation_option(
                LL_TOOLS_PUBLIC_STATIC_CACHE_GENERATION_OPTION,
                $fallback
            ) ? $fallback : '';
        }
    }

    return $generation;
}

/**
 * Verify both fences that authorize a captured response to publish.
 *
 * @param array<string,mixed> $context
 */
function ll_tools_public_static_cache_publication_is_current(array $context): bool {
    $key = (string) ($context['key'] ?? '');
    $lease_value = (string) ($context['lease_value'] ?? '');
    $generation = (string) ($context['generation'] ?? '');

    return (int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, 0) >= LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA
        && !ll_tools_public_static_cache_is_quarantined()
        && $generation !== ''
        && hash_equals(
            $generation,
            ll_tools_public_static_cache_generation(
                isset($context['identity']) && is_array($context['identity']) ? $context['identity'] : null
            )
        )
        && ll_tools_public_static_cache_owns_rebuild_lock($key, $lease_value);
}

function ll_tools_public_static_cache_delete_rebuild_locks(): void {
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->options)) {
        return;
    }

    $like = $wpdb->esc_like(LL_TOOLS_PUBLIC_STATIC_CACHE_REBUILD_LOCK_OPTION_PREFIX) . '%';
    $option_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    foreach ((array) $option_names as $option_name) {
        delete_option((string) $option_name);
    }
    $GLOBALS['ll_tools_public_static_cache_rebuild_leases'] = [];
}

function ll_tools_public_static_cache_file_is_fresh(string $file, int $ttl): bool {
    if ($file === '' || !is_readable($file)) {
        return false;
    }

    $mtime = filemtime($file);
    return $mtime !== false && (time() - (int) $mtime) < $ttl;
}

function ll_tools_public_static_cache_file_is_stale(string $file, int $ttl): bool {
    if ($file === '' || !is_readable($file)) {
        return false;
    }

    $mtime = filemtime($file);
    return $mtime !== false && (time() - (int) $mtime) >= $ttl;
}

/**
 * Return an exact signature for the file observed by a rebuild owner.
 */
function ll_tools_public_static_cache_file_signature(string $file): string {
    if ($file === '' || !is_readable($file)) {
        return '';
    }

    $hash = hash_file('sha256', $file);
    return is_string($hash) ? $hash : '';
}

function ll_tools_public_static_cache_rebuild_wait_ms(): int {
    $wait_ms = (int) apply_filters('ll_tools_public_static_cache_rebuild_wait_ms', 0);

    return max(0, min(2000, $wait_ms));
}

/**
 * Give the rebuild owner a short bounded window to publish a cold response.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 */
function ll_tools_public_static_cache_wait_for_rebuild(string $file, int $ttl, ?array $identity): ?string {
    $wait_ms = ll_tools_public_static_cache_rebuild_wait_ms();
    if ($file === '' || $wait_ms <= 0) {
        return null;
    }

    $deadline = microtime(true) + ($wait_ms / 1000);
    do {
        clearstatcache(true, $file);
        if (ll_tools_public_static_cache_file_is_fresh($file, $ttl)) {
            $output_html = ll_tools_public_static_cache_read_prepared_file($file, $identity);
            if (is_string($output_html)) {
                return $output_html;
            }
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    return null;
}

function ll_tools_public_static_cache_meta_file_path(string $file): string {
    $file = trim($file);
    if ($file === '' || !preg_match('/^(.+)\.html$/', $file, $matches)) {
        return '';
    }

    return (string) $matches[1] . '.meta.json';
}

function ll_tools_public_static_cache_normalize_id_list($ids): array {
    $normalized = [];
    foreach ((array) $ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $normalized[$id] = true;
        }
    }

    $result = array_values(array_map('intval', array_keys($normalized)));
    sort($result, SORT_NUMERIC);
    return $result;
}

function ll_tools_public_static_cache_normalize_purge_criteria(array $criteria = []): array {
    $normalized = [];

    $wordset_ids = ll_tools_public_static_cache_normalize_id_list($criteria['wordset_ids'] ?? ($criteria['wordset_id'] ?? []));
    if (!empty($wordset_ids)) {
        $normalized['wordset_ids'] = $wordset_ids;
    }

    $post_ids = ll_tools_public_static_cache_normalize_id_list($criteria['post_ids'] ?? ($criteria['post_id'] ?? []));
    if (!empty($post_ids)) {
        $normalized['post_ids'] = $post_ids;
    }

    $types = [];
    foreach ((array) ($criteria['types'] ?? ($criteria['type'] ?? [])) as $type) {
        $type = sanitize_key((string) $type);
        if ($type !== '') {
            $types[$type] = true;
        }
    }
    if (!empty($types)) {
        $normalized['types'] = array_values(array_keys($types));
    }

    return $normalized;
}

function ll_tools_public_static_cache_read_meta(string $file): ?array {
    $meta_file = ll_tools_public_static_cache_meta_file_path($file);
    if ($meta_file === '' || !is_readable($meta_file)) {
        return null;
    }

    $raw = file_get_contents($meta_file);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $meta = json_decode($raw, true);
    return is_array($meta) ? $meta : null;
}

function ll_tools_public_static_cache_meta_matches_criteria(?array $meta, array $criteria): bool {
    $criteria = ll_tools_public_static_cache_normalize_purge_criteria($criteria);
    if (empty($criteria)) {
        return true;
    }

    if (!is_array($meta)) {
        return true;
    }

    $identity = isset($meta['identity']) && is_array($meta['identity']) ? $meta['identity'] : [];
    if (empty($identity)) {
        return true;
    }

    $identity_type = sanitize_key((string) ($identity['type'] ?? ''));
    if (!empty($criteria['types']) && !in_array($identity_type, $criteria['types'], true)) {
        return false;
    }

    if (!empty($criteria['post_ids'])) {
        $identity_id = (int) ($identity['id'] ?? 0);
        if ($identity_id > 0 && in_array($identity_id, $criteria['post_ids'], true)) {
            return true;
        }
    }

    if (!empty($criteria['wordset_ids'])) {
        $identity_wordset_id = (int) ($identity['wordset_id'] ?? 0);
        $identity_id = (int) ($identity['id'] ?? 0);
        if ($identity_wordset_id > 0 && in_array($identity_wordset_id, $criteria['wordset_ids'], true)) {
            return true;
        }
        if ($identity_type === 'wordset_main' && $identity_id > 0 && in_array($identity_id, $criteria['wordset_ids'], true)) {
            return true;
        }
    }

    return empty($criteria['post_ids']) && empty($criteria['wordset_ids']);
}

function ll_tools_public_static_cache_write_meta(string $file, string $key, ?array $identity): void {
    $meta_file = ll_tools_public_static_cache_meta_file_path($file);
    if ($meta_file === '') {
        return;
    }

    $identity = is_array($identity) ? $identity : [];
    $safe_identity = [
        'type' => sanitize_key((string) ($identity['type'] ?? '')),
        'id' => max(0, (int) ($identity['id'] ?? 0)),
        'path' => (string) ($identity['path'] ?? ''),
        'wordset_id' => max(0, (int) ($identity['wordset_id'] ?? 0)),
    ];

    $payload = [
        'schema' => 1,
        'key' => preg_replace('/[^a-f0-9]/', '', strtolower($key)),
        'created_at' => time(),
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'identity' => $safe_identity,
    ];

    $tmp = $meta_file . '.tmp-' . wp_generate_password(12, false, false);
    $written = @file_put_contents($tmp, wp_json_encode($payload), LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        return;
    }

    @chmod($tmp, 0644);
    if (!@rename($tmp, $meta_file)) {
        @unlink($tmp);
    }
}

/**
 * Replace request-short public AJAX nonces before storing cached HTML.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 */
function ll_tools_public_static_cache_prepare_html_for_storage(string $html, ?array $identity = null, string $generation = ''): string {
    if (function_exists('ll_tools_dictionary_static_cache_prepare_html_for_storage')) {
        $html = ll_tools_dictionary_static_cache_prepare_html_for_storage($html);
    }
    if (function_exists('wp_create_nonce')) {
        $html = str_replace(
            wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
            LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER,
            $html
        );
        $html = str_replace(
            wp_create_nonce('ll_tools_wordset_page_category_search'),
            LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_CATEGORY_SEARCH_NONCE_PLACEHOLDER,
            $html
        );

        $lesson_id = ($identity !== null && ($identity['type'] ?? '') === 'vocab_lesson') ? (int) ($identity['id'] ?? 0) : 0;
        if ($lesson_id > 0) {
            $html = str_replace(
                wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
                ll_tools_public_static_cache_vocab_grid_nonce_placeholder($lesson_id),
                $html
            );
        }
    }

    $generation = $generation !== '' ? $generation : ll_tools_public_static_cache_generation($identity);
    if ($generation === '') {
        return $html;
    }

    return '<!-- ll-tools-public-static-generation:' . esc_html($generation) . " -->\n" . $html;
}

/**
 * Restore request-fresh public AJAX nonces into cached HTML.
 *
 * @param array{type:string,id:int,path:string,wordset_id?:int}|null $identity
 */
function ll_tools_public_static_cache_prepare_html_for_output(string $html, ?array $identity = null): string {
    if (function_exists('ll_tools_dictionary_static_cache_prepare_html_for_output')) {
        $html = ll_tools_dictionary_static_cache_prepare_html_for_output($html);
    }
    if (!function_exists('wp_create_nonce')) {
        return $html;
    }

    $html = str_replace(
        LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER,
        wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
        $html
    );
    $html = str_replace(
        LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_CATEGORY_SEARCH_NONCE_PLACEHOLDER,
        wp_create_nonce('ll_tools_wordset_page_category_search'),
        $html
    );

    $lesson_id = ($identity !== null && ($identity['type'] ?? '') === 'vocab_lesson') ? (int) ($identity['id'] ?? 0) : 0;
    if ($lesson_id > 0) {
        $html = str_replace(
            ll_tools_public_static_cache_vocab_grid_nonce_placeholder($lesson_id),
            wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
            $html
        );
    }

    return $html;
}

function ll_tools_public_static_cache_read_prepared_file_unlocked(string $file, ?array $identity = null): ?string {
    if (ll_tools_public_static_cache_is_quarantined() || $file === '' || !is_readable($file)) {
        return null;
    }

    $html = file_get_contents($file);
    if (!is_string($html)) {
        return null;
    }

    if (preg_match('/^<!-- ll-tools-public-static-generation:([a-zA-Z0-9-]+) -->\R?/', $html, $matches) !== 1) {
        return null;
    }
    $stored_generation = (string) ($matches[1] ?? '');
    $current_generation = ll_tools_public_static_cache_generation($identity);
    if ($current_generation === '' || !hash_equals($current_generation, $stored_generation)) {
        return null;
    }
    $html = (string) preg_replace('/^<!-- ll-tools-public-static-generation:[a-zA-Z0-9-]+ -->\R?/', '', $html, 1);

    $prepared_html = ll_tools_public_static_cache_prepare_html_for_output($html, $identity);

    return ll_tools_public_static_cache_is_quarantined() ? null : $prepared_html;
}

function ll_tools_public_static_cache_read_prepared_file(string $file, ?array $identity = null): ?string {
    $publication_lock = ll_tools_public_static_cache_open_publication_lock($file, LOCK_SH);
    if (!is_resource($publication_lock)) {
        return null;
    }

    try {
        return ll_tools_public_static_cache_read_prepared_file_unlocked($file, $identity);
    } finally {
        ll_tools_public_static_cache_close_publication_lock($publication_lock);
    }
}

function ll_tools_public_static_cache_send_prepared_file_response(string $output_html, string $cache_status, string $reason, bool $public_cache, string $method, ?int $public_max_age = null): void {
    ll_tools_public_static_cache_send_headers($cache_status, $reason, $public_cache, $public_max_age);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    header('Content-Length: ' . strlen($output_html));
    if ($method === 'HEAD') {
        exit;
    }

    echo $output_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

/**
 * Return the nonce placeholder for one vocab lesson grid AJAX action.
 */
function ll_tools_public_static_cache_vocab_grid_nonce_placeholder(int $lesson_id): string {
    return LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX . max(0, $lesson_id) . '%%';
}

/**
 * Return the browser-cache lifetime still available for a static-cache file.
 *
 * The result is capped to the internal cache TTL so a future-dated file cannot
 * extend downstream freshness beyond the server-side freshness window.
 */
function ll_tools_public_static_cache_remaining_max_age(int $file_mtime, int $ttl, int $now = 0): int {
    $ttl = max(0, $ttl);
    if ($file_mtime <= 0 || $ttl === 0) {
        return 0;
    }

    $now = $now > 0 ? $now : time();
    $remaining = ($file_mtime + $ttl) - $now;

    return max(0, min($ttl, $remaining));
}

/**
 * Build the browser/downstream cache policy for static-cache responses.
 */
function ll_tools_public_static_cache_cache_control_value(bool $public, ?int $public_max_age = null): string {
    if (!$public) {
        return 'no-cache, must-revalidate';
    }

    $max_age = ll_tools_public_static_cache_browser_max_age();
    if ($public_max_age !== null) {
        $max_age = max(0, min($max_age, $public_max_age));
    }

    $value = 'public, max-age=' . $max_age;
    if ($max_age === 0) {
        $value .= ', must-revalidate';
    }

    return $value;
}

/**
 * Send public/debug headers for the anonymous public static cache.
 */
function ll_tools_public_static_cache_send_headers(string $cache_status, string $reason = '', bool $public_cache = true, ?int $public_max_age = null): void {
    if (headers_sent()) {
        return;
    }

    header('X-LL-Public-Static-Cache: ' . strtoupper($cache_status));
    if ($reason !== '') {
        header('X-LL-Public-Static-Cache-Reason: ' . sanitize_key($reason));
    }
    header('Cache-Control: ' . ll_tools_public_static_cache_cache_control_value($public_cache, $public_max_age));
    header('Vary: Accept-Language, Cookie', false);
}

/**
 * Serve or start capturing one anonymous public read-only page request.
 */
function ll_tools_serve_public_static_cache(): void {
    if (array_key_exists('ll_locale', $_GET)) {
        nocache_headers();
        return;
    }
    if (ll_tools_public_static_cache_has_category_search_recovery_arg()) {
        ll_tools_public_static_cache_send_category_search_recovery_bypass_headers();
        return;
    }
    if (ll_tools_public_static_cache_has_frontend_auth_args()) {
        ll_tools_public_static_cache_send_auth_bypass_headers();
        return;
    }

    if (ll_tools_public_static_cache_has_safe_request_shape()
        && !ll_tools_public_static_cache_has_supported_display_args()
        && ll_tools_public_static_cache_request_identity() !== null) {
        ll_tools_public_static_cache_admit_cold_request();
        ll_tools_public_static_cache_send_headers('BYPASS', 'display-variant', false);
        return;
    }
    if (!ll_tools_is_cacheable_public_static_request()) {
        if (ll_tools_public_static_cache_has_safe_request_shape()
            && ll_tools_public_static_cache_request_identity() !== null) {
            ll_tools_public_static_cache_admit_cold_request();
        }
        return;
    }

    $identity = ll_tools_public_static_cache_request_identity();
    if ($identity === null) {
        return;
    }

    $key = ll_tools_public_static_cache_key($identity);
    $file = ll_tools_public_static_cache_file_path($key);
    $ttl = ll_tools_public_static_cache_ttl();
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if (ll_tools_public_static_cache_file_is_fresh($file, $ttl)) {
        $file_mtime = filemtime($file);
        $output_html = ll_tools_public_static_cache_read_prepared_file($file, $identity);
        if (is_string($output_html)) {
            $public_max_age = ll_tools_public_static_cache_remaining_max_age(
                $file_mtime !== false ? (int) $file_mtime : 0,
                $ttl
            );
            ll_tools_public_static_cache_debug_log('hit', [
                'key' => $key,
                'file' => basename($file),
            ]);
            ll_tools_public_static_cache_send_prepared_file_response($output_html, 'HIT', 'fresh', true, $method, $public_max_age);
        }
    }

    $lock_acquired = false;
    if ($file !== '') {
        $lock_acquired = ll_tools_public_static_cache_acquire_rebuild_lock($key);
        if (!$lock_acquired && ll_tools_public_static_cache_file_is_stale($file, $ttl)) {
            $output_html = ll_tools_public_static_cache_read_prepared_file($file, $identity);
            if (is_string($output_html)) {
                ll_tools_public_static_cache_debug_log('stale', [
                    'key' => $key,
                    'reason' => 'rebuild_lock',
                    'file' => basename($file),
                ]);
                ll_tools_public_static_cache_send_prepared_file_response($output_html, 'STALE', 'rebuild-lock', false, $method);
            }
        }
        if (!$lock_acquired && !is_readable($file)) {
            $output_html = ll_tools_public_static_cache_wait_for_rebuild($file, $ttl, $identity);
            if (is_string($output_html)) {
                $file_mtime = filemtime($file);
                $public_max_age = ll_tools_public_static_cache_remaining_max_age(
                    $file_mtime !== false ? (int) $file_mtime : 0,
                    $ttl
                );
                ll_tools_public_static_cache_debug_log('wait_hit', [
                    'key' => $key,
                    'file' => basename($file),
                ]);
                ll_tools_public_static_cache_send_prepared_file_response($output_html, 'HIT', 'rebuild-wait', true, $method, $public_max_age);
            }
        }
    }

    // Completed/stale/waited responses above spend no budget. Admission also
    // covers an uncached fallback when another worker owns this exact key.
    ll_tools_public_static_cache_admit_cold_request($key, $identity);
    $miss_reason = ll_tools_public_static_cache_miss_reason($file, $ttl);
    ll_tools_public_static_cache_send_headers('MISS', $miss_reason, false);
    ll_tools_public_static_cache_debug_log('miss', [
        'key' => $key,
        'reason' => $miss_reason,
        'file' => $file !== '' ? basename($file) : '',
        'lock_acquired' => $lock_acquired ? 1 : 0,
    ]);

    if ($file === '' || !$lock_acquired) {
        return;
    }

    $GLOBALS['ll_tools_public_static_cache_request'] = [
        'active' => true,
        'key' => $key,
        'file' => $file,
        'identity' => $identity,
        'lock_acquired' => true,
        'lease_value' => ll_tools_public_static_cache_get_rebuild_lease_value($key),
        'generation' => ll_tools_public_static_cache_generation($identity),
        'original_file_signature' => ll_tools_public_static_cache_file_signature($file),
        'buffer_level' => ob_get_level(),
    ];

    ob_start();
}
add_action('template_redirect', 'll_tools_serve_public_static_cache', 1);

/**
 * Return whether a rendered HTML response is safe to persist.
 */
function ll_tools_public_static_cache_html_is_cacheable(string $html): bool {
    if (function_exists('ll_tools_dictionary_static_cache_html_is_cacheable')) {
        return ll_tools_dictionary_static_cache_html_is_cacheable($html);
    }

    $html = trim($html);
    if (strlen($html) < max(128, (int) apply_filters('ll_tools_public_static_cache_min_bytes', 512))) {
        return false;
    }

    return (stripos($html, '<html') !== false || stripos($html, '<!doctype') !== false)
        && stripos($html, 'wp-die-message') === false
        && stripos($html, 'Fatal error') === false
        && stripos($html, 'Parse error') === false;
}

/**
 * Persist the captured anonymous public HTML with an atomic write.
 */
function ll_tools_store_public_static_cache(): void {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $context = isset($GLOBALS['ll_tools_public_static_cache_request']) && is_array($GLOBALS['ll_tools_public_static_cache_request'])
        ? $GLOBALS['ll_tools_public_static_cache_request']
        : [];
    if (empty($context['active']) || empty($context['file'])) {
        return;
    }

    $buffer_level = max(0, (int) ($context['buffer_level'] ?? 0));
    $release_lock = !empty($context['lock_acquired']) && !empty($context['key']);

    try {
        if (array_key_exists('ll_locale', $_GET)) {
            nocache_headers();
            return;
        }
        if (ll_tools_public_static_cache_has_category_search_recovery_arg()) {
            ll_tools_public_static_cache_debug_log('skip_category_search_recovery');
            ll_tools_public_static_cache_send_category_search_recovery_bypass_headers();
            return;
        }
        if (ll_tools_public_static_cache_has_frontend_auth_args()) {
            ll_tools_public_static_cache_debug_log('skip_frontend_auth');
            ll_tools_public_static_cache_send_auth_bypass_headers();
            return;
        }

        if (!ll_tools_public_static_cache_has_supported_display_args()) {
            return;
        }

        $status = ll_tools_public_static_cache_current_status_code();
        if ($status < 200 || $status >= 300) {
            ll_tools_public_static_cache_debug_log('skip_status', [
                'status' => $status,
            ]);
            return;
        }

        $headers = function_exists('headers_list') ? headers_list() : [];
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Location:') === 0) {
                ll_tools_public_static_cache_debug_log('skip_redirect', [
                    'header' => 'Location',
                ]);
                return;
            }
        }

        if (ob_get_level() <= $buffer_level) {
            ll_tools_public_static_cache_debug_log('skip_buffer_closed', [
                'buffer_level' => $buffer_level,
                'current_level' => ob_get_level(),
            ]);
            return;
        }

        $html = ob_get_contents();
        if (!is_string($html) || !ll_tools_public_static_cache_html_is_cacheable($html)) {
            ll_tools_public_static_cache_debug_log('skip_uncacheable_html', [
                'bytes' => is_string($html) ? strlen($html) : 0,
            ]);
            return;
        }

        $file = (string) $context['file'];
        $identity = (isset($context['identity']) && is_array($context['identity'])) ? $context['identity'] : null;
        $stored_html = ll_tools_public_static_cache_prepare_html_for_storage(
            $html,
            $identity,
            (string) ($context['generation'] ?? '')
        );
        $stored_bytes = strlen($stored_html);
        $max_bytes = ll_tools_public_static_cache_max_bytes($identity);
        if ($max_bytes > 0 && $stored_bytes > $max_bytes) {
            ll_tools_public_static_cache_debug_log('skip_oversized_payload', [
                'bytes' => $stored_bytes,
                'max_bytes' => $max_bytes,
            ]);
            return;
        }

        $dir = dirname($file);
        if (!ll_tools_public_static_cache_publication_is_current($context)) {
            ll_tools_public_static_cache_debug_log('skip_stale_publication');
            return;
        }
        if (!wp_mkdir_p($dir) || !is_dir($dir) || !is_writable($dir)) {
            ll_tools_public_static_cache_debug_log('skip_unwritable_dir', [
                'dir' => basename($dir),
            ]);
            return;
        }

        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        $tmp = $file . '.tmp-' . wp_generate_password(12, false, false);
        $written = @file_put_contents($tmp, $stored_html, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            ll_tools_public_static_cache_debug_log('write_failed', [
                'file' => basename($file),
            ]);
            return;
        }

        @chmod($tmp, 0644);
        $publication_lock = ll_tools_public_static_cache_open_publication_lock($file, LOCK_EX);
        if (!is_resource($publication_lock)) {
            @unlink($tmp);
            ll_tools_public_static_cache_debug_log('skip_publication_lock');
            return;
        }

        try {
            if (!ll_tools_public_static_cache_publication_is_current($context)) {
                @unlink($tmp);
                ll_tools_public_static_cache_debug_log('skip_stale_publication');
                return;
            }
            $original_file_signature = (string) ($context['original_file_signature'] ?? '');
            $current_file_signature = ll_tools_public_static_cache_file_signature($file);
            if (
                $current_file_signature !== $original_file_signature
                && ll_tools_public_static_cache_read_prepared_file_unlocked($file, $identity) !== null
            ) {
                @unlink($tmp);
                ll_tools_public_static_cache_debug_log('skip_successor_publication', [
                    'file' => basename($file),
                ]);
                return;
            }

            /**
             * Internal concurrency seam for forcing lease turnover between the
             * final precheck and rename in regression tests.
             */
            do_action('ll_tools_public_static_cache_before_publish_rename', $context, $file);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                ll_tools_public_static_cache_debug_log('rename_failed', [
                    'file' => basename($file),
                ]);
            } elseif (!ll_tools_public_static_cache_publication_is_current($context)) {
                $published_signature = ll_tools_public_static_cache_file_signature($file);
                $expected_signature = hash('sha256', $stored_html);
                $discarded = false;
                if ($published_signature !== '' && hash_equals($expected_signature, $published_signature)) {
                    $discarded = @unlink($file);
                }
                if (!$discarded) {
                    ll_tools_public_static_cache_quarantine();
                }
                ll_tools_public_static_cache_debug_log('discard_stale_publication');
            } else {
                if (!headers_sent()) {
                    header('Cache-Control: ' . ll_tools_public_static_cache_cache_control_value(true));
                }
                ll_tools_public_static_cache_write_meta($file, (string) ($context['key'] ?? ''), $identity);
                ll_tools_public_static_cache_debug_log('stored', [
                    'file' => basename($file),
                    'bytes' => (int) $written,
                ]);
            }
        } finally {
            ll_tools_public_static_cache_close_publication_lock($publication_lock);
        }
    } finally {
        if ($method === 'HEAD' && ob_get_level() > $buffer_level) {
            ob_clean();
        }
        if ($release_lock) {
            ll_tools_public_static_cache_release_rebuild_lock((string) $context['key']);
        }
    }
}
add_action('shutdown', 'll_tools_store_public_static_cache', 0);

/**
 * Delete static public HTML cache files.
 */
function ll_tools_purge_public_static_cache(array $criteria = []): int {
    $dir = ll_tools_public_static_cache_dir();
    $deleted = 0;
    $criteria = ll_tools_public_static_cache_normalize_purge_criteria($criteria);
    $quarantine_owner = wp_generate_password(32, false, false);
    $purge_state = ll_tools_public_static_cache_begin_purge($quarantine_owner);
    if ($purge_state === false) {
        // A broken marker must not leave old generated HTML on disk. Continue
        // deletion and the database generation fence, retaining failure state.
        ll_tools_public_static_cache_quarantine($quarantine_owner);
        ll_tools_schedule_schema_maintenance('public_static_cache', 30);
    }
    $purge_failed = $purge_state === false;
    // Retain a recovery transport if this worker is killed before completion.
    ll_tools_schedule_schema_maintenance('public_static_cache', 10 * MINUTE_IN_SECONDS);
    if (ll_tools_public_static_cache_rotate_generation($criteria) === '') {
        $purge_failed = true;
    }
    ll_tools_public_static_cache_delete_rebuild_locks();

    if ($dir !== '' && is_dir($dir)) {
        $files = ll_tools_static_cache_generated_files(trailingslashit($dir) . 'public-*.html');
        $purge_failed = $purge_failed || $files === false;
        foreach ($files ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $meta = ll_tools_public_static_cache_read_meta($file);
            if (!ll_tools_public_static_cache_meta_matches_criteria($meta, $criteria)) {
                continue;
            }

            if (@unlink($file)) {
                $deleted++;
            } else {
                $purge_failed = true;
            }

            $meta_file = ll_tools_public_static_cache_meta_file_path($file);
            if ($meta_file !== '' && is_file($meta_file)) {
                if (!@unlink($meta_file)) {
                    $purge_failed = true;
                }
            }
        }

        if (empty($criteria)) {
            $files = ll_tools_static_cache_generated_files(trailingslashit($dir) . 'public-*.meta.json');
            $purge_failed = $purge_failed || $files === false;
            foreach ($files ?: [] as $meta_file) {
                if (is_file($meta_file)) {
                    if (!@unlink($meta_file)) {
                        $purge_failed = true;
                    }
                }
            }
        }

        $files = ll_tools_static_cache_generated_files(trailingslashit($dir) . 'public-*.html.tmp-*');
        $purge_failed = $purge_failed || $files === false;
        foreach ($files ?: [] as $tmp_file) {
            if (is_file($tmp_file)) {
                if (@unlink($tmp_file)) {
                    $deleted++;
                } else {
                    $purge_failed = true;
                }
            }
        }

        $files = ll_tools_static_cache_generated_files(trailingslashit($dir) . 'public-*.meta.json.tmp-*');
        $purge_failed = $purge_failed || $files === false;
        foreach ($files ?: [] as $tmp_file) {
            if (is_file($tmp_file)) {
                if (!@unlink($tmp_file)) {
                    $purge_failed = true;
                }
            }
        }
    }

    if ($purge_state !== false && !ll_tools_public_static_cache_finish_purge(
        $quarantine_owner,
        !$purge_failed,
        empty($criteria) ? $purge_state['previous_failure'] : null
    )) {
        $purge_failed = true;
        ll_tools_public_static_cache_quarantine($quarantine_owner);
    }
    if ($purge_failed || ll_tools_public_static_cache_is_quarantined()) {
        ll_tools_schedule_schema_maintenance('public_static_cache', 5 * MINUTE_IN_SECONDS);
        ll_tools_public_static_cache_debug_log('purge_quarantined', [
            'criteria' => empty($criteria) ? 'all' : md5((string) wp_json_encode($criteria)),
        ]);
    }

    if (
        empty($GLOBALS['ll_tools_static_cache_suppress_edge_purge'])
        && function_exists('ll_tools_cloudflare_static_cache_purge_once')
    ) {
        ll_tools_cloudflare_static_cache_purge_once('public');
    }

    return $deleted;
}

/**
 * Remove only generated public-cache files from older security schemas.
 */
function ll_tools_public_static_cache_maybe_purge_legacy_schema(): void {
    ll_tools_maybe_run_static_cache_maintenance('public_static_cache', static function (): bool {
        return (int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, 0) >= LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA
            && !ll_tools_public_static_cache_is_quarantined();
    }, static function (): bool {
        ll_tools_purge_public_static_cache();
        $dir = ll_tools_public_static_cache_dir();
        if ($dir === '') {
            return false;
        }
        if (is_dir($dir)) {
            foreach (['public-*.html', 'public-*.html.tmp-*', 'public-*.meta.json', 'public-*.meta.json.tmp-*'] as $pattern) {
                $remaining = ll_tools_static_cache_generated_files(trailingslashit($dir) . $pattern);
                if ($remaining === false || !empty($remaining)) {
                    return false;
                }
            }
        }
        if (ll_tools_public_static_cache_is_quarantined()) {
            return false;
        }
        update_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA, false);
        return (int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, 0) >= LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA;
    });
}
add_action('init', 'll_tools_public_static_cache_maybe_purge_legacy_schema', 2);

/**
 * Purge public static cache once per mutating request.
 */
function ll_tools_purge_public_static_cache_once(array $criteria = []): int {
    if (!isset($GLOBALS['ll_tools_public_static_cache_purged_keys']) || !is_array($GLOBALS['ll_tools_public_static_cache_purged_keys'])) {
        $GLOBALS['ll_tools_public_static_cache_purged_keys'] = [];
    }
    $purged_keys =& $GLOBALS['ll_tools_public_static_cache_purged_keys'];

    $criteria = ll_tools_public_static_cache_normalize_purge_criteria($criteria);
    $key = empty($criteria) ? 'all' : md5((string) wp_json_encode($criteria));
    if (!empty($purged_keys['all']) || !empty($purged_keys[$key])) {
        return 0;
    }

    $purged_keys[$key] = true;
    if ($key === 'all') {
        $purged_keys = ['all' => true];
    }

    return ll_tools_purge_public_static_cache($criteria);
}

function ll_tools_public_static_cache_reset_purge_once_state(): void {
    $GLOBALS['ll_tools_public_static_cache_purged_keys'] = [];
}

function ll_tools_public_static_cache_wordset_ids_for_categories($category_ids): array {
    $wordset_ids = [];
    if (!defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
        return [];
    }

    foreach (ll_tools_public_static_cache_normalize_id_list($category_ids) as $category_id) {
        $owner_id = (int) get_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true);
        if ($owner_id > 0) {
            $wordset_ids[] = $owner_id;
        }
    }

    return ll_tools_public_static_cache_normalize_id_list($wordset_ids);
}

function ll_tools_public_static_cache_wordset_ids_for_post(int $post_id): array {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }

    $post = get_post($post_id);
    if (!($post instanceof WP_Post)) {
        return [];
    }

    $wordset_ids = [];
    if ($post->post_type === 'word_audio') {
        $parent_id = (int) wp_get_post_parent_id($post_id);
        if ($parent_id > 0) {
            $wordset_ids = array_merge($wordset_ids, ll_tools_public_static_cache_wordset_ids_for_post($parent_id));
        }
    }

    if ($post->post_type === 'll_vocab_lesson' && defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')) {
        $wordset_ids[] = (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    }

    if ($post->post_type === 'll_content_lesson' && function_exists('ll_tools_get_content_lesson_wordset_id')) {
        $wordset_ids[] = (int) ll_tools_get_content_lesson_wordset_id($post_id);
    }

    if ($post->post_type === 'word_images' && defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')) {
        $wordset_ids[] = (int) get_post_meta($post_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, true);
    }

    if (function_exists('ll_tools_get_post_wordset_ids')) {
        $wordset_ids = array_merge($wordset_ids, ll_tools_get_post_wordset_ids($post_id));
    } else {
        $terms = wp_get_post_terms($post_id, 'wordset', ['fields' => 'ids']);
        if (!is_wp_error($terms)) {
            $wordset_ids = array_merge($wordset_ids, (array) $terms);
        }
    }

    $category_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($category_ids) && !empty($category_ids)) {
        $wordset_ids = array_merge(
            $wordset_ids,
            ll_tools_public_static_cache_wordset_ids_for_categories($category_ids)
        );
    }

    return ll_tools_public_static_cache_normalize_id_list($wordset_ids);
}

function ll_tools_public_static_cache_relevant_post_type(string $post_type): bool {
    return in_array($post_type, ['words', 'word_images', 'word_audio', 'll_vocab_lesson', 'll_content_lesson', 'll_prompt_card'], true);
}

function ll_tools_public_static_cache_term_ids_from_term_taxonomy_ids($term_taxonomy_ids, string $taxonomy): array {
    $term_taxonomy_ids = ll_tools_public_static_cache_normalize_id_list($term_taxonomy_ids);
    $taxonomy = sanitize_key($taxonomy);
    if (empty($term_taxonomy_ids) || $taxonomy === '') {
        return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($term_taxonomy_ids), '%d'));
    $sql = "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_taxonomy_id IN ({$placeholders})";
    $prepared = (string) call_user_func_array([$wpdb, 'prepare'], array_merge([$sql, $taxonomy], $term_taxonomy_ids));
    $ids = $wpdb->get_col($prepared);

    return ll_tools_public_static_cache_normalize_id_list($ids);
}

function ll_tools_public_static_cache_purge_criteria_for_post(int $post_id): array {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }

    $post = get_post($post_id);
    if (!($post instanceof WP_Post)) {
        return [];
    }

    $criteria = [
        'post_ids' => [$post_id],
    ];

    $wordset_ids = ll_tools_public_static_cache_wordset_ids_for_post($post_id);
    if (!empty($wordset_ids)) {
        $criteria['wordset_ids'] = $wordset_ids;
    }

    return ll_tools_public_static_cache_normalize_purge_criteria($criteria);
}

/**
 * Broad invalidation for content that can appear on public wordset/lesson pages.
 */
function ll_tools_public_static_cache_purge_on_post_change($post_id = 0): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0 || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $post_type = get_post_type($post_id);
    if (!is_string($post_type) || !ll_tools_public_static_cache_relevant_post_type($post_type)) {
        return;
    }

    if ((string) get_post_status($post_id) !== 'publish') {
        return;
    }

    $criteria = ll_tools_public_static_cache_purge_criteria_for_post($post_id);
    ll_tools_purge_public_static_cache_once($criteria);
}
add_action('save_post', 'll_tools_public_static_cache_purge_on_post_change', 30, 1);
add_action('before_delete_post', 'll_tools_public_static_cache_purge_on_post_change', 30, 1);

function ll_tools_public_static_cache_purge_on_status_transition(string $new_status, string $old_status, $post): void {
    if (!($post instanceof WP_Post) || !ll_tools_public_static_cache_relevant_post_type((string) $post->post_type)) {
        return;
    }

    if ($new_status !== 'publish' && $old_status !== 'publish') {
        return;
    }

    ll_tools_purge_public_static_cache_once(
        ll_tools_public_static_cache_purge_criteria_for_post((int) $post->ID)
    );
}
add_action('transition_post_status', 'll_tools_public_static_cache_purge_on_status_transition', 30, 3);

function ll_tools_public_static_cache_purge_on_object_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    $object_id = (int) $object_id;
    $taxonomy = sanitize_key((string) $taxonomy);
    if ($object_id <= 0 || !in_array($taxonomy, ['wordset', 'word-category'], true)) {
        return;
    }

    $post_type = get_post_type($object_id);
    if (!is_string($post_type) || !ll_tools_public_static_cache_relevant_post_type($post_type)) {
        return;
    }

    $term_ids = array_merge(
        ll_tools_public_static_cache_normalize_id_list($terms),
        ll_tools_public_static_cache_term_ids_from_term_taxonomy_ids($old_tt_ids, $taxonomy),
        ll_tools_public_static_cache_term_ids_from_term_taxonomy_ids($tt_ids, $taxonomy)
    );
    if ($taxonomy === 'wordset') {
        $term_ids = array_merge(
            $term_ids,
            ll_tools_public_static_cache_normalize_id_list($old_tt_ids),
            ll_tools_public_static_cache_normalize_id_list($tt_ids)
        );
    }
    if ($taxonomy === 'word-category') {
        $wordset_ids = ll_tools_public_static_cache_wordset_ids_for_categories($term_ids);
    } else {
        $wordset_ids = ll_tools_public_static_cache_normalize_id_list($term_ids);
    }

    if (empty($wordset_ids)) {
        ll_tools_purge_public_static_cache_once();
        return;
    }

    ll_tools_purge_public_static_cache_once(['wordset_ids' => $wordset_ids]);
}
add_action('set_object_terms', 'll_tools_public_static_cache_purge_on_object_terms_change', 30, 6);

function ll_tools_public_static_cache_purge_on_term_change($term_id = 0): void {
    $term_id = (int) $term_id;
    if ($term_id <= 0) {
        ll_tools_purge_public_static_cache_once();
        return;
    }

    $taxonomy = (string) current_filter();
    if (strpos($taxonomy, 'wordset') !== false) {
        ll_tools_purge_public_static_cache_once(['wordset_ids' => [$term_id]]);
        return;
    }

    $wordset_ids = ll_tools_public_static_cache_wordset_ids_for_categories([$term_id]);
    if (empty($wordset_ids)) {
        ll_tools_purge_public_static_cache_once();
        return;
    }

    ll_tools_purge_public_static_cache_once(['wordset_ids' => $wordset_ids]);
}
add_action('created_wordset', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);
add_action('edited_wordset', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);
add_action('delete_wordset', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);
add_action('created_word-category', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);
add_action('edited_word-category', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);
add_action('delete_word-category', 'll_tools_public_static_cache_purge_on_term_change', 30, 1);

function ll_tools_static_cache_purge_capability(): string {
    return (string) apply_filters('ll_tools_static_cache_purge_capability', 'manage_options');
}

function ll_tools_current_user_can_purge_static_cache(): bool {
    return current_user_can(ll_tools_static_cache_purge_capability());
}

/**
 * @return string[]
 */
function ll_tools_static_cache_purge_targets(): array {
    return ['all', 'dictionary', 'public'];
}

function ll_tools_cloudflare_static_cache_zone_id(): string {
    $zone_id = defined('LL_TOOLS_CLOUDFLARE_ZONE_ID')
        ? (string) constant('LL_TOOLS_CLOUDFLARE_ZONE_ID')
        : '';

    $zone_id = (string) apply_filters('ll_tools_cloudflare_static_cache_zone_id', $zone_id);
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim($zone_id)) ?: '';
}

function ll_tools_cloudflare_static_cache_api_token(): string {
    $token = defined('LL_TOOLS_CLOUDFLARE_API_TOKEN')
        ? (string) constant('LL_TOOLS_CLOUDFLARE_API_TOKEN')
        : '';

    $token = (string) apply_filters('ll_tools_cloudflare_static_cache_api_token', $token);
    return trim($token);
}

function ll_tools_cloudflare_static_cache_purge_enabled(): bool {
    $configured = ll_tools_cloudflare_static_cache_zone_id() !== ''
        && ll_tools_cloudflare_static_cache_api_token() !== '';
    $enabled = $configured;

    return $configured && (bool) apply_filters('ll_tools_cloudflare_static_cache_purge_enabled', $enabled);
}

/**
 * Return known public dictionary URLs that can have matching edge HTML.
 *
 * @return string[]
 */
function ll_tools_cloudflare_static_cache_dictionary_urls(): array {
    $urls = [];
    $page_ids = [];
    $page_id = (int) get_option('ll_default_dictionary_page_id');

    if ($page_id > 0) {
        $page_ids[] = $page_id;
    }

    if (function_exists('ll_tools_find_shortcode_pages_by_fragment')) {
        foreach (['[ll_dictionary', '[dictionary_search', '[dictionary_browser'] as $fragment) {
            $matched_ids = ll_tools_find_shortcode_pages_by_fragment($fragment, 'ids', 20);
            foreach ($matched_ids as $matched_id) {
                $matched_id = (int) $matched_id;
                if ($matched_id > 0) {
                    $page_ids[] = $matched_id;
                }
            }
        }
    } elseif ($page_id <= 0 && function_exists('ll_tools_find_dictionary_page_id')) {
        $page_ids[] = (int) ll_tools_find_dictionary_page_id();
    }

    foreach (array_values(array_unique(array_filter(array_map('intval', $page_ids)))) as $page_id) {
        $permalink = function_exists('ll_tools_get_published_page_permalink')
            ? ll_tools_get_published_page_permalink($page_id)
            : (string) get_permalink($page_id);
        if ($permalink !== '') {
            $urls[] = $permalink;
        }
    }

    /**
     * Filter dictionary URLs purged from Cloudflare after LL dictionary cache invalidation.
     *
     * Sites with multiple public dictionary pages can add exact canonical URLs here.
     *
     * @param string[] $urls
     */
    $urls = apply_filters('ll_tools_cloudflare_static_cache_dictionary_urls', $urls);

    return ll_tools_cloudflare_static_cache_normalize_urls($urls);
}

/**
 * @param mixed $urls
 * @return string[]
 */
function ll_tools_cloudflare_static_cache_normalize_urls($urls): array {
    if (!is_array($urls)) {
        return [];
    }

    $normalized = [];
    foreach ($urls as $url) {
        if (!is_scalar($url)) {
            continue;
        }
        $url = trim((string) $url);
        if ($url === '') {
            continue;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            continue;
        }

        $normalized[] = esc_url_raw($url);
    }

    return array_values(array_unique(array_filter($normalized)));
}

/**
 * Return edge URLs to purge for one LL static-cache target.
 *
 * @return string[]
 */
function ll_tools_cloudflare_static_cache_purge_urls(string $target): array {
    $target = ll_tools_normalize_static_cache_purge_target($target);
    if ($target === '') {
        $target = 'all';
    }

    $urls = [];
    if ($target === 'all' || $target === 'dictionary') {
        $urls = array_merge($urls, ll_tools_cloudflare_static_cache_dictionary_urls());
    }

    /**
     * Filter exact URLs purged from Cloudflare after LL static-cache invalidation.
     *
     * The default only includes discovered dictionary pages. Public wordset or
     * lesson URLs are intentionally site-specific and should be added here by
     * sites that edge-cache those pages.
     *
     * @param string[] $urls
     * @param string   $target Normalized purge target: all, dictionary, or public.
     */
    $urls = apply_filters('ll_tools_cloudflare_static_cache_purge_urls', $urls, $target);

    return ll_tools_cloudflare_static_cache_normalize_urls($urls);
}

function ll_tools_cloudflare_static_cache_purge(string $target = 'all', bool $purge_everything = false): array {
    $target = ll_tools_normalize_static_cache_purge_target($target);
    if ($target === '') {
        $target = 'all';
    }

    $zone_id = ll_tools_cloudflare_static_cache_zone_id();
    $token = ll_tools_cloudflare_static_cache_api_token();
    $enabled = ll_tools_cloudflare_static_cache_purge_enabled();
    $urls = $enabled ? ll_tools_cloudflare_static_cache_purge_urls($target) : [];

    $result = [
        'provider' => 'cloudflare',
        'target' => $target,
        'configured' => $zone_id !== '' && $token !== '',
        'enabled' => $enabled,
        'attempted' => false,
        'purged' => false,
        'purge_everything' => $purge_everything,
        'urls' => $urls,
        'batches' => [],
        'error' => '',
    ];

    if (!$result['configured']) {
        $result['error'] = 'not_configured';
        return $result;
    }

    if (!$enabled) {
        $result['error'] = 'disabled';
        return $result;
    }

    if (!$purge_everything && empty($urls)) {
        $result['error'] = 'no_urls';
        return $result;
    }

    $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id) . '/purge_cache';
    $all_success = true;
    $batches = $purge_everything ? [[]] : array_chunk($urls, 30);

    foreach ($batches as $batch) {
        $result['attempted'] = true;
        $body = $purge_everything
            ? ['purge_everything' => true]
            : ['files' => array_values($batch)];
        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        $batch_result = [
            'count' => $purge_everything ? 0 : count($batch),
            'success' => false,
            'status' => 0,
            'error' => '',
        ];

        if ($response instanceof WP_Error) {
            $batch_result['error'] = $response->get_error_code();
            $all_success = false;
        } else {
            $batch_result['status'] = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $batch_result['success'] = is_array($body) && !empty($body['success']);
            if (!$batch_result['success']) {
                $batch_result['error'] = 'api_error';
                $all_success = false;
            }
        }

        $result['batches'][] = $batch_result;
    }

    $result['purged'] = $result['attempted'] && $all_success;
    if (!$result['purged'] && $result['error'] === '') {
        $result['error'] = 'purge_failed';
    }

    return $result;
}

function ll_tools_cloudflare_static_cache_purge_once(string $target = 'all', bool $purge_everything = false): array {
    $target = ll_tools_normalize_static_cache_purge_target($target);
    if ($target === '') {
        $target = 'all';
    }
    $cache_key = $target . ':' . ($purge_everything ? 'everything' : 'urls');

    if (!isset($GLOBALS['ll_tools_cloudflare_static_cache_purge_results']) || !is_array($GLOBALS['ll_tools_cloudflare_static_cache_purge_results'])) {
        $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'] = [];
    }

    if (isset($GLOBALS['ll_tools_cloudflare_static_cache_purge_results'][$cache_key])) {
        return $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'][$cache_key];
    }

    $result = ll_tools_cloudflare_static_cache_purge($target, $purge_everything);
    $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'][$cache_key] = $result;

    return $result;
}

function ll_tools_cloudflare_static_cache_reset_purge_once_state(): void {
    $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'] = [];
}

function ll_tools_normalize_static_cache_purge_target($target): string {
    $target = sanitize_key((string) $target);
    if ($target === '') {
        return 'all';
    }

    return in_array($target, ll_tools_static_cache_purge_targets(), true) ? $target : '';
}

function ll_tools_purge_static_caches($target = 'all', array $options = []): array {
    $target = ll_tools_normalize_static_cache_purge_target($target);
    if ($target === '') {
        $target = 'all';
    }
    $cloudflare_purge_everything = !empty($options['cloudflare_purge_everything']);

    $result = [
        'target' => $target,
        'deleted' => 0,
        'caches' => [
            'dictionary' => [
                'available' => function_exists('ll_tools_purge_dictionary_static_cache'),
                'deleted' => 0,
            ],
            'public' => [
                'available' => function_exists('ll_tools_purge_public_static_cache'),
                'deleted' => 0,
            ],
        ],
    ];

    $previous_suppress_edge_purge = !empty($GLOBALS['ll_tools_static_cache_suppress_edge_purge']);
    $GLOBALS['ll_tools_static_cache_suppress_edge_purge'] = true;
    try {
        if (($target === 'all' || $target === 'dictionary') && function_exists('ll_tools_purge_dictionary_static_cache')) {
            $result['caches']['dictionary']['deleted'] = ll_tools_purge_dictionary_static_cache();
        }

        if (($target === 'all' || $target === 'public') && function_exists('ll_tools_purge_public_static_cache')) {
            $result['caches']['public']['deleted'] = ll_tools_purge_public_static_cache();
        }
    } finally {
        if ($previous_suppress_edge_purge) {
            $GLOBALS['ll_tools_static_cache_suppress_edge_purge'] = true;
        } else {
            unset($GLOBALS['ll_tools_static_cache_suppress_edge_purge']);
        }
    }

    $result['deleted'] = (int) $result['caches']['dictionary']['deleted'] + (int) $result['caches']['public']['deleted'];
    $result['edge'] = [
        'cloudflare' => function_exists('ll_tools_cloudflare_static_cache_purge_once')
            ? ll_tools_cloudflare_static_cache_purge_once($target, $cloudflare_purge_everything)
            : [
                'provider' => 'cloudflare',
                'target' => $target,
                'configured' => false,
                'enabled' => false,
                'attempted' => false,
                'purged' => false,
                'purge_everything' => $cloudflare_purge_everything,
                'urls' => [],
                'batches' => [],
                'error' => 'unavailable',
            ],
    ];

    return $result;
}

function ll_tools_static_cache_current_url(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    if ($request_uri === '') {
        $request_uri = '/';
    }

    return home_url($request_uri);
}

function ll_tools_static_cache_strip_notice_args(string $url): string {
    return remove_query_arg(
        [
            'll_tools_static_cache_purged',
            'll_tools_static_cache_deleted',
        ],
        $url
    );
}

function ll_tools_static_cache_admin_bar_purge_url(): string {
    $redirect_to = ll_tools_static_cache_strip_notice_args(ll_tools_static_cache_current_url());
    $url = add_query_arg(
        [
            'action' => 'll_tools_purge_static_cache',
            'redirect_to' => $redirect_to,
        ],
        admin_url('admin-post.php')
    );

    return wp_nonce_url($url, 'll_tools_purge_static_cache');
}

function ll_tools_add_static_cache_admin_bar_node(WP_Admin_Bar $wp_admin_bar): void {
    if (!is_user_logged_in() || !ll_tools_current_user_can_purge_static_cache()) {
        return;
    }

    $title = __('Clear LL cache', 'll-tools-text-domain');
    if (isset($_GET['ll_tools_static_cache_purged'])) {
        $deleted = isset($_GET['ll_tools_static_cache_deleted']) ? max(0, (int) $_GET['ll_tools_static_cache_deleted']) : 0;
        $title = sprintf(
            /* translators: %d: number of deleted cache files */
            __('LL cache cleared (%d)', 'll-tools-text-domain'),
            $deleted
        );
    }

    $wp_admin_bar->add_node([
        'id' => 'll-tools-clear-static-cache',
        'title' => esc_html($title),
        'href' => ll_tools_static_cache_admin_bar_purge_url(),
        'meta' => [
            'class' => 'll-tools-clear-static-cache',
        ],
    ]);
}
add_action('admin_bar_menu', 'll_tools_add_static_cache_admin_bar_node', 100);

function ll_tools_handle_static_cache_admin_bar_purge(): void {
    if (!ll_tools_current_user_can_purge_static_cache()) {
        wp_die(
            esc_html__('You are not allowed to clear LL Tools caches.', 'll-tools-text-domain'),
            '',
            ['response' => 403]
        );
    }

    check_admin_referer('ll_tools_purge_static_cache');

    $redirect_to = '';
    if (isset($_GET['redirect_to']) && is_scalar($_GET['redirect_to'])) {
        $redirect_to = rawurldecode((string) wp_unslash($_GET['redirect_to']));
    }
    if ($redirect_to === '') {
        $redirect_to = (string) wp_get_referer();
    }
    $redirect_to = ll_tools_static_cache_strip_notice_args($redirect_to !== '' ? $redirect_to : admin_url());
    $redirect_to = wp_validate_redirect($redirect_to, admin_url());

    $result = ll_tools_purge_static_caches('all');

    wp_safe_redirect(add_query_arg([
        'll_tools_static_cache_purged' => '1',
        'll_tools_static_cache_deleted' => max(0, (int) ($result['deleted'] ?? 0)),
    ], $redirect_to));
    exit;
}
add_action('admin_post_ll_tools_purge_static_cache', 'll_tools_handle_static_cache_admin_bar_purge');

function ll_tools_static_cache_admin_notice(): void {
    if (!is_admin() || !ll_tools_current_user_can_purge_static_cache() || !isset($_GET['ll_tools_static_cache_purged'])) {
        return;
    }

    $deleted = isset($_GET['ll_tools_static_cache_deleted']) ? max(0, (int) $_GET['ll_tools_static_cache_deleted']) : 0;
    $message = sprintf(
        /* translators: %d: number of deleted cache files */
        _n(
            'Cleared LL Tools static caches. %d cache file was removed.',
            'Cleared LL Tools static caches. %d cache files were removed.',
            $deleted,
            'll-tools-text-domain'
        ),
        $deleted
    );

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'll_tools_static_cache_admin_notice');
