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
 * Generate a signed URL that hides the original attachment filename.
 */
function ll_tools_get_masked_image_url($attachment_id, $size = 'full') {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $size = ll_tools_normalize_image_size($size);
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

    $proto_header = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] : '';
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
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
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
        $port_header = isset($_SERVER['HTTP_X_FORWARDED_PORT']) ? (string) $_SERVER['HTTP_X_FORWARDED_PORT'] : '';
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
    if ($host !== '' && (substr($host, -13) === '.localsite.io' || $host === 'localsite.io')) {
        return true;
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

    if (!empty($fallback_url)) {
        $response = wp_remote_get($fallback_url, [
            'timeout' => 10,
            'decompress' => true,
        ]);
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $mime = (string) wp_remote_retrieve_header($response, 'content-type');
            if (($semi = strpos($mime, ';')) !== false) {
                $mime = trim(substr($mime, 0, $semi));
            }
            if ($mime === '') {
                $mime = ll_tools_guess_proxy_image_mime_from_url($fallback_url);
            }
            $mime = ll_tools_detect_proxy_image_mime('', $mime);

            ll_tools_prepare_binary_image_stream();
            status_header(200);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="quiz-image-' . $attachment_id . '"');
            header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400, no-transform');
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }
    }

    status_header(404);
    exit;
}
// Serve masked images on template_redirect so other plugins have a chance
// to register URL filters/mappings during init first (e.g., remote media mappers).
add_action('template_redirect', 'll_tools_maybe_serve_masked_image', 0);

// Ensure attachment URLs are request-origin-safe (e.g. Local Live Link host).
add_filter('wp_get_attachment_url', 'll_tools_filter_attachment_url_for_request_origin', 20, 1);
add_filter('wp_get_attachment_image_src', 'll_tools_filter_attachment_image_src_for_request_origin', 20, 1);
add_filter('wp_calculate_image_srcset', 'll_tools_filter_attachment_srcset_for_request_origin', 20, 1);
