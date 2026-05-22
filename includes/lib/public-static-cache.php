<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME', 'll-public-static-cache');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_TTL')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_DEFAULT_TTL', DAY_IN_SECONDS);
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER', '%%LL_TOOLS_WORDSET_LAZY_CARDS_NONCE%%');
}

if (!defined('LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX')) {
    define('LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX', '%%LL_TOOLS_VOCAB_LESSON_GRID_NONCE_');
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
 * Normalize public-page display query args for cache-key generation.
 *
 * @param array<string,mixed>|null $raw_args
 * @return array<string,mixed>
 */
function ll_tools_public_static_cache_normalize_query_args(?array $raw_args = null): array {
    if ($raw_args === null) {
        $raw_args = $_GET;
    }

    $allowed = array_flip(ll_tools_public_static_cache_query_keys());
    $normalized = [];

    foreach ($raw_args as $key => $value) {
        if (!is_string($key) || !isset($allowed[$key])) {
            continue;
        }
        if (function_exists('ll_tools_dictionary_is_noise_query_key') && ll_tools_dictionary_is_noise_query_key($key)) {
            continue;
        }

        $value = wp_unslash($value);
        $normalized[$key] = function_exists('ll_tools_dictionary_static_cache_normalize_value')
            ? ll_tools_dictionary_static_cache_normalize_value($value)
            : trim((string) (is_scalar($value) ? $value : ''));
    }

    foreach ($normalized as $key => $value) {
        if ($value === '' || $value === []) {
            unset($normalized[$key]);
        }
    }

    ksort($normalized, SORT_STRING);
    return $normalized;
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
    if (is_front_page() || is_home()) {
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

    if (
        isset($_GET['ll_locale'])
        && function_exists('ll_tools_verify_locale_switch_request_nonce')
        && ll_tools_verify_locale_switch_request_nonce()
    ) {
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
        if (!($post instanceof WP_Post) || $post->post_status !== 'publish' || post_password_required($post)) {
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
        if (!($post instanceof WP_Post) || $post->post_status !== 'publish' || post_password_required($post)) {
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
    if (!ll_tools_public_static_cache_has_safe_request_shape()) {
        return false;
    }

    $identity = ll_tools_public_static_cache_request_identity();
    if ($identity === null) {
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
        'schema' => 1,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'site' => home_url('/'),
        'identity' => [
            'type' => sanitize_key((string) ($identity['type'] ?? 'unknown')),
            'id' => max(0, (int) ($identity['id'] ?? 0)),
            'path' => (string) ($identity['path'] ?? ''),
            'wordset_id' => max(0, (int) ($identity['wordset_id'] ?? 0)),
        ],
        'locale' => $locale,
        'args' => ll_tools_public_static_cache_normalize_query_args($raw_args),
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
function ll_tools_public_static_cache_prepare_html_for_storage(string $html, ?array $identity = null): string {
    if (function_exists('ll_tools_dictionary_static_cache_prepare_html_for_storage')) {
        $html = ll_tools_dictionary_static_cache_prepare_html_for_storage($html);
    }
    if (!function_exists('wp_create_nonce')) {
        return $html;
    }

    $html = str_replace(
        wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
        LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER,
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

    return $html;
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

/**
 * Return the nonce placeholder for one vocab lesson grid AJAX action.
 */
function ll_tools_public_static_cache_vocab_grid_nonce_placeholder(int $lesson_id): string {
    return LL_TOOLS_PUBLIC_STATIC_CACHE_VOCAB_GRID_NONCE_PLACEHOLDER_PREFIX . max(0, $lesson_id) . '%%';
}

/**
 * Build the browser/downstream cache policy for static-cache responses.
 */
function ll_tools_public_static_cache_cache_control_value(bool $public): string {
    if (!$public) {
        return 'no-cache, must-revalidate';
    }

    return 'public, max-age=' . ll_tools_public_static_cache_ttl();
}

/**
 * Send public/debug headers for the anonymous public static cache.
 */
function ll_tools_public_static_cache_send_headers(string $cache_status, string $reason = '', bool $public_cache = true): void {
    if (headers_sent()) {
        return;
    }

    header('X-LL-Public-Static-Cache: ' . strtoupper($cache_status));
    if ($reason !== '') {
        header('X-LL-Public-Static-Cache-Reason: ' . sanitize_key($reason));
    }
    header('Cache-Control: ' . ll_tools_public_static_cache_cache_control_value($public_cache));
    header('Vary: Accept-Language, Cookie', false);
}

/**
 * Serve or start capturing one anonymous public read-only page request.
 */
function ll_tools_serve_public_static_cache(): void {
    if (!ll_tools_is_cacheable_public_static_request()) {
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

    if ($file !== '' && is_readable($file) && filemtime($file) !== false && (time() - (int) filemtime($file)) < $ttl) {
        $html = file_get_contents($file);
        if (is_string($html)) {
            $output_html = ll_tools_public_static_cache_prepare_html_for_output($html, $identity);
            ll_tools_public_static_cache_send_headers('HIT', 'fresh');
            ll_tools_public_static_cache_debug_log('hit', [
                'key' => $key,
                'file' => basename($file),
            ]);
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            header('Content-Length: ' . strlen($output_html));
            if ($method === 'HEAD') {
                exit;
            }
            echo $output_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
    }

    $miss_reason = ll_tools_public_static_cache_miss_reason($file, $ttl);
    ll_tools_public_static_cache_send_headers('MISS', $miss_reason, false);
    ll_tools_public_static_cache_debug_log('miss', [
        'key' => $key,
        'reason' => $miss_reason,
        'file' => $file !== '' ? basename($file) : '',
    ]);

    if ($file === '') {
        return;
    }

    $GLOBALS['ll_tools_public_static_cache_request'] = [
        'active' => true,
        'key' => $key,
        'file' => $file,
        'identity' => $identity,
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

    $buffer_level = max(0, (int) ($context['buffer_level'] ?? 0));
    if (ob_get_level() <= $buffer_level) {
        ll_tools_public_static_cache_debug_log('skip_buffer_closed', [
            'buffer_level' => $buffer_level,
            'current_level' => ob_get_level(),
        ]);
        return;
    }

    try {
        $html = ob_get_contents();
        if (!is_string($html) || !ll_tools_public_static_cache_html_is_cacheable($html)) {
            ll_tools_public_static_cache_debug_log('skip_uncacheable_html', [
                'bytes' => is_string($html) ? strlen($html) : 0,
            ]);
            return;
        }

        $file = (string) $context['file'];
        $dir = dirname($file);
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
        $identity = (isset($context['identity']) && is_array($context['identity'])) ? $context['identity'] : null;
        $stored_html = ll_tools_public_static_cache_prepare_html_for_storage($html, $identity);
        $written = @file_put_contents($tmp, $stored_html, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            ll_tools_public_static_cache_debug_log('write_failed', [
                'file' => basename($file),
            ]);
            return;
        }

        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            ll_tools_public_static_cache_debug_log('rename_failed', [
                'file' => basename($file),
            ]);
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
        if ($method === 'HEAD' && ob_get_level() > $buffer_level) {
            ob_clean();
        }
    }
}
add_action('shutdown', 'll_tools_store_public_static_cache', 0);

/**
 * Delete static public HTML cache files.
 */
function ll_tools_purge_public_static_cache(array $criteria = []): int {
    $dir = ll_tools_public_static_cache_dir();
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $criteria = ll_tools_public_static_cache_normalize_purge_criteria($criteria);

    foreach (glob(trailingslashit($dir) . 'public-*.html') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $meta = ll_tools_public_static_cache_read_meta($file);
        if (!ll_tools_public_static_cache_meta_matches_criteria($meta, $criteria)) {
            continue;
        }

        if (@unlink($file)) {
            $deleted++;
        }

        $meta_file = ll_tools_public_static_cache_meta_file_path($file);
        if ($meta_file !== '' && is_file($meta_file)) {
            @unlink($meta_file);
        }
    }

    if (empty($criteria)) {
        foreach (glob(trailingslashit($dir) . 'public-*.meta.json') ?: [] as $meta_file) {
            if (is_file($meta_file)) {
                @unlink($meta_file);
            }
        }
    }

    foreach (glob(trailingslashit($dir) . 'public-*.html.tmp-*') ?: [] as $tmp_file) {
        if (is_file($tmp_file) && @unlink($tmp_file)) {
            $deleted++;
        }
    }

    foreach (glob(trailingslashit($dir) . 'public-*.meta.json.tmp-*') ?: [] as $tmp_file) {
        if (is_file($tmp_file)) {
            @unlink($tmp_file);
        }
    }

    return $deleted;
}

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

function ll_tools_normalize_static_cache_purge_target($target): string {
    $target = sanitize_key((string) $target);
    if ($target === '') {
        return 'all';
    }

    return in_array($target, ll_tools_static_cache_purge_targets(), true) ? $target : '';
}

function ll_tools_purge_static_caches($target = 'all'): array {
    $target = ll_tools_normalize_static_cache_purge_target($target);
    if ($target === '') {
        $target = 'all';
    }

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

    if (($target === 'all' || $target === 'dictionary') && function_exists('ll_tools_purge_dictionary_static_cache')) {
        $result['caches']['dictionary']['deleted'] = ll_tools_purge_dictionary_static_cache();
    }

    if (($target === 'all' || $target === 'public') && function_exists('ll_tools_purge_public_static_cache')) {
        $result['caches']['public']['deleted'] = ll_tools_purge_public_static_cache();
    }

    $result['deleted'] = (int) $result['caches']['dictionary']['deleted'] + (int) $result['caches']['public']['deleted'];

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
