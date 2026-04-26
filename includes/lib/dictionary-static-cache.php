<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_STATIC_CACHE_DIRNAME')) {
    define('LL_TOOLS_DICTIONARY_STATIC_CACHE_DIRNAME', 'll-dictionary-cache');
}

if (!defined('LL_TOOLS_DICTIONARY_STATIC_CACHE_DEFAULT_TTL')) {
    define('LL_TOOLS_DICTIONARY_STATIC_CACHE_DEFAULT_TTL', DAY_IN_SECONDS);
}

if (!defined('LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER')) {
    define('LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER', '%%LL_TOOLS_DICTIONARY_LIVE_SEARCH_NONCE%%');
}

if (!defined('LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER')) {
    define('LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER', '%%LL_TOOLS_LOCALE_SWITCH_NONCE%%');
}

/**
 * Return the anonymous dictionary static-cache TTL.
 */
function ll_tools_dictionary_static_cache_ttl(): int {
    $ttl = defined('LL_TOOLS_DICTIONARY_STATIC_CACHE_TTL')
        ? (int) constant('LL_TOOLS_DICTIONARY_STATIC_CACHE_TTL')
        : (int) LL_TOOLS_DICTIONARY_STATIC_CACHE_DEFAULT_TTL;

    return max(60, (int) apply_filters('ll_tools_dictionary_static_cache_ttl', $ttl));
}

/**
 * Query args that do not change dictionary display and should not survive in links/cache keys.
 *
 * @return string[]
 */
function ll_tools_dictionary_noise_query_keys(): array {
    $keys = [
        'll_locale_nonce',
        'll_tools_auth',
        'll_tools_auth_feedback',
    ];

    $filtered = apply_filters('ll_tools_dictionary_noise_query_keys', $keys);
    if (!is_array($filtered)) {
        return $keys;
    }

    return array_values(array_unique(array_filter(array_map('strval', $filtered))));
}

/**
 * Determine whether one query key is non-display noise for dictionary navigation/cache keys.
 */
function ll_tools_dictionary_is_noise_query_key(string $key): bool {
    $key = trim($key);
    if ($key === '') {
        return true;
    }

    if (in_array($key, ll_tools_dictionary_noise_query_keys(), true)) {
        return true;
    }

    $lower_key = strtolower($key);
    if (strpos($lower_key, 'utm_') === 0) {
        return true;
    }

    return in_array($lower_key, ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true);
}

/**
 * Remove nonce/auth/tracking noise from a URL while preserving meaningful display args.
 */
function ll_tools_dictionary_strip_noise_query_args_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $query = (string) wp_parse_url($url, PHP_URL_QUERY);
    if ($query === '') {
        return $url;
    }

    $args = [];
    wp_parse_str($query, $args);

    $remove_keys = [];
    foreach (array_keys($args) as $key) {
        if (is_string($key) && ll_tools_dictionary_is_noise_query_key($key)) {
            $remove_keys[] = $key;
        }
    }

    if (empty($remove_keys)) {
        return $url;
    }

    return (string) remove_query_arg($remove_keys, $url);
}

/**
 * Display-affecting query args for the public dictionary page.
 *
 * @return string[]
 */
function ll_tools_dictionary_static_cache_query_keys(): array {
    $keys = [
        'letter',
        'll_dictionary_q',
        'll_dictionary_scope',
        'll_dictionary_page',
        'll_dictionary_letter',
        'll_dictionary_pos',
        'll_dictionary_source',
        'll_dictionary_dialect',
        'll_dictionary_entry',
        'll_locale',
    ];

    $filtered = apply_filters('ll_tools_dictionary_static_cache_query_keys', $keys);
    if (!is_array($filtered)) {
        return $keys;
    }

    return array_values(array_unique(array_filter(array_map('strval', $filtered))));
}

/**
 * Normalize one value for stable cache-key JSON.
 *
 * @param mixed $value
 * @return mixed
 */
function ll_tools_dictionary_static_cache_normalize_value($value) {
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalized[$key] = ll_tools_dictionary_static_cache_normalize_value($item);
        }

        $keys = array_keys($normalized);
        $is_list = $keys === range(0, count($normalized) - 1);
        if ($is_list) {
            sort($normalized, SORT_REGULAR);
        } else {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return '';
}

/**
 * Normalize dictionary display query args for cache-key generation.
 *
 * @param array<string,mixed>|null $raw_args
 * @return array<string,mixed>
 */
function ll_tools_dictionary_static_cache_normalize_query_args(?array $raw_args = null): array {
    if ($raw_args === null) {
        $raw_args = $_GET;
    }

    $allowed = array_flip(ll_tools_dictionary_static_cache_query_keys());
    $normalized = [];

    foreach ($raw_args as $key => $value) {
        if (!is_string($key) || !isset($allowed[$key]) || ll_tools_dictionary_is_noise_query_key($key)) {
            continue;
        }

        $value = wp_unslash($value);
        $normalized[$key] = ll_tools_dictionary_static_cache_normalize_value($value);
    }

    if (isset($normalized['ll_dictionary_letter'])) {
        unset($normalized['letter']);
    } elseif (isset($normalized['letter'])) {
        $normalized['ll_dictionary_letter'] = $normalized['letter'];
        unset($normalized['letter']);
    }

    if (!empty($normalized['ll_dictionary_q'])) {
        unset($normalized['ll_dictionary_letter']);
    }

    if (isset($normalized['ll_dictionary_page']) && (int) $normalized['ll_dictionary_page'] <= 1) {
        unset($normalized['ll_dictionary_page']);
    }

    foreach ($normalized as $key => $value) {
        if ($value === '' || $value === []) {
            unset($normalized[$key]);
        }
    }

    ksort($normalized, SORT_STRING);

    return $normalized;
}

/**
 * Return the normalized current request path.
 */
function ll_tools_dictionary_static_cache_request_path(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $path = rawurldecode($path);
    $path = '/' . trim($path, '/');

    return $path === '/' ? '/' : untrailingslashit($path);
}

/**
 * Return dictionary page identity for the current main query, or null when this is not a public dictionary page.
 *
 * @return array{page_id:int,path:string}|null
 */
function ll_tools_dictionary_static_cache_request_identity(): ?array {
    if (is_404() || !is_singular()) {
        return null;
    }

    $post = get_queried_object();
    if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
        return null;
    }
    if (post_password_required($post)) {
        return null;
    }

    $content = (string) $post->post_content;
    $has_dictionary_shortcode = has_shortcode($content, 'll_dictionary')
        || has_shortcode($content, 'dictionary_search')
        || has_shortcode($content, 'dictionary_browser');
    if (!$has_dictionary_shortcode) {
        return null;
    }

    return [
        'page_id' => (int) $post->ID,
        'path' => ll_tools_dictionary_static_cache_request_path(),
    ];
}

/**
 * Determine whether the current request can use the anonymous dictionary static cache.
 */
function ll_tools_is_cacheable_dictionary_request(): bool {
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
    if (is_preview() || (function_exists('is_customize_preview') && is_customize_preview())) {
        return false;
    }

    $skip_query_keys = [
        'preview',
        'preview_id',
        'preview_nonce',
        'customize_changeset_uuid',
        'customize_theme',
        'customize_messenger_channel',
    ];
    foreach ($skip_query_keys as $key) {
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

    return ll_tools_dictionary_static_cache_request_identity() !== null;
}

/**
 * Return the uploads-backed static-cache directory.
 */
function ll_tools_dictionary_static_cache_dir(): string {
    $uploads = wp_upload_dir(null, false, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    return trailingslashit((string) $uploads['basedir']) . LL_TOOLS_DICTIONARY_STATIC_CACHE_DIRNAME;
}

/**
 * Build the current dictionary static cache key.
 *
 * @param array{page_id:int,path:string}|null $identity
 * @param array<string,mixed>|null            $raw_args
 */
function ll_tools_dictionary_static_cache_key(?array $identity = null, ?array $raw_args = null, ?string $locale = null): string {
    if ($identity === null) {
        $identity = ll_tools_dictionary_static_cache_request_identity() ?: ['page_id' => 0, 'path' => ll_tools_dictionary_static_cache_request_path()];
    }

    if ($locale === null) {
        $locale = function_exists('get_locale') ? (string) get_locale() : '';
    }

    $payload = [
        'schema' => 1,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'site' => home_url('/'),
        'identity' => [
            'page_id' => max(0, (int) ($identity['page_id'] ?? 0)),
            'path' => (string) ($identity['path'] ?? ''),
        ],
        'locale' => $locale,
        'args' => ll_tools_dictionary_static_cache_normalize_query_args($raw_args),
    ];

    return md5((string) wp_json_encode($payload));
}

/**
 * Return the final cache file path for a key.
 */
function ll_tools_dictionary_static_cache_file_path(string $key): string {
    $key = preg_replace('/[^a-f0-9]/', '', strtolower($key));
    if (!is_string($key) || $key === '') {
        return '';
    }

    $dir = ll_tools_dictionary_static_cache_dir();
    if ($dir === '') {
        return '';
    }

    return trailingslashit($dir) . 'dictionary-' . $key . '.html';
}

/**
 * Replace short-lived public AJAX nonces before storing or serving cached HTML.
 */
function ll_tools_dictionary_static_cache_prepare_html_for_storage(string $html): string {
    if (!function_exists('wp_create_nonce')) {
        return $html;
    }

    $html = str_replace(
        wp_create_nonce('ll_tools_dictionary_live_search'),
        LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER,
        $html
    );

    if (function_exists('ll_tools_get_locale_switch_nonce_action')) {
        $html = str_replace(
            wp_create_nonce(ll_tools_get_locale_switch_nonce_action()),
            LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER,
            $html
        );
    }

    return $html;
}

/**
 * Restore request-fresh public AJAX nonces into cached HTML.
 */
function ll_tools_dictionary_static_cache_prepare_html_for_output(string $html): string {
    if (!function_exists('wp_create_nonce')) {
        return $html;
    }

    $html = str_replace(
        LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER,
        wp_create_nonce('ll_tools_dictionary_live_search'),
        $html
    );

    if (function_exists('ll_tools_get_locale_switch_nonce_action')) {
        $html = str_replace(
            LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER,
            wp_create_nonce(ll_tools_get_locale_switch_nonce_action()),
            $html
        );
    }

    return $html;
}

/**
 * Send public/debug headers for the anonymous dictionary static cache.
 */
function ll_tools_dictionary_static_cache_send_headers(string $cache_status): void {
    if (headers_sent()) {
        return;
    }

    $ttl = ll_tools_dictionary_static_cache_ttl();
    header('X-LL-Dictionary-Cache: ' . strtoupper($cache_status));
    header('Cache-Control: public, max-age=' . $ttl);
    header('Vary: Accept-Language, Cookie', false);
}

/**
 * Serve or start capturing one anonymous public dictionary page request.
 */
function ll_tools_serve_dictionary_static_cache(): void {
    if (!ll_tools_is_cacheable_dictionary_request()) {
        return;
    }

    $identity = ll_tools_dictionary_static_cache_request_identity();
    if ($identity === null) {
        return;
    }

    $key = ll_tools_dictionary_static_cache_key($identity);
    $file = ll_tools_dictionary_static_cache_file_path($key);
    $ttl = ll_tools_dictionary_static_cache_ttl();
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($file !== '' && is_readable($file) && filemtime($file) !== false && (time() - (int) filemtime($file)) < $ttl) {
        $html = file_get_contents($file);
        if (is_string($html)) {
            $output_html = ll_tools_dictionary_static_cache_prepare_html_for_output($html);
            ll_tools_dictionary_static_cache_send_headers('HIT');
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            header('Content-Length: ' . strlen($output_html));
            if ($method === 'HEAD') {
                exit;
            }
            echo $output_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
    }

    ll_tools_dictionary_static_cache_send_headers('MISS');

    if ($file === '') {
        return;
    }

    $GLOBALS['ll_tools_dictionary_static_cache_request'] = [
        'active' => true,
        'file' => $file,
        'buffer_level' => ob_get_level(),
    ];

    ob_start();
}
add_action('template_redirect', 'll_tools_serve_dictionary_static_cache', 0);

/**
 * Return whether a rendered HTML response is safe to persist.
 */
function ll_tools_dictionary_static_cache_html_is_cacheable(string $html): bool {
    $html = trim($html);
    $min_bytes = max(128, (int) apply_filters('ll_tools_dictionary_static_cache_min_bytes', 512));
    if (strlen($html) < $min_bytes) {
        return false;
    }

    if (stripos($html, '<html') === false && stripos($html, '<!doctype') === false) {
        return false;
    }

    foreach (['wp-die-message', 'Fatal error', 'Parse error', 'Warning:'] as $needle) {
        if (stripos($html, $needle) !== false) {
            return false;
        }
    }

    return true;
}

/**
 * Persist the captured anonymous dictionary HTML with an atomic write.
 */
function ll_tools_store_dictionary_static_cache(): void {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $context = isset($GLOBALS['ll_tools_dictionary_static_cache_request']) && is_array($GLOBALS['ll_tools_dictionary_static_cache_request'])
        ? $GLOBALS['ll_tools_dictionary_static_cache_request']
        : [];
    if (empty($context['active']) || empty($context['file'])) {
        return;
    }

    $status = function_exists('http_response_code') ? (int) http_response_code() : 200;
    if ($status < 200 || $status >= 300) {
        return;
    }

    $headers = function_exists('headers_list') ? headers_list() : [];
    foreach ($headers as $header) {
        if (stripos((string) $header, 'Location:') === 0) {
            return;
        }
    }

    $buffer_level = max(0, (int) ($context['buffer_level'] ?? 0));
    if (ob_get_level() <= $buffer_level) {
        return;
    }

    try {
        $html = ob_get_contents();
        if (!is_string($html) || !ll_tools_dictionary_static_cache_html_is_cacheable($html)) {
            return;
        }

        $file = (string) $context['file'];
        $dir = dirname($file);
        if (!wp_mkdir_p($dir) || !is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        $tmp = $file . '.tmp-' . wp_generate_password(12, false, false);
        $written = @file_put_contents($tmp, ll_tools_dictionary_static_cache_prepare_html_for_storage($html), LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            return;
        }

        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    } finally {
        if ($method === 'HEAD' && ob_get_level() > $buffer_level) {
            ob_clean();
        }
    }
}
add_action('shutdown', 'll_tools_store_dictionary_static_cache', 0);

/**
 * Delete static dictionary HTML cache files.
 */
function ll_tools_purge_dictionary_static_cache(): int {
    $dir = ll_tools_dictionary_static_cache_dir();
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $patterns = [
        trailingslashit($dir) . 'dictionary-*.html',
        trailingslashit($dir) . 'dictionary-*.html.tmp-*',
    ];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}
