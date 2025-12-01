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

    if ($path && file_exists($path) && is_readable($path)) {
        $filetype = wp_check_filetype($path);
        $mime     = $filetype['type'] ?: 'image/jpeg';
        $ext      = $filetype['ext'] ?: 'jpg';

        status_header(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="quiz-image-' . $attachment_id . '.' . $ext . '"');
        header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
        return;
    }

    if (!empty($fallback_url)) {
        $response = wp_remote_get($fallback_url, ['timeout' => 10]);
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $mime = wp_remote_retrieve_header($response, 'content-type');
            status_header(200);
            header('Content-Type: ' . ($mime ?: 'image/jpeg'));
            header('Content-Disposition: inline; filename="quiz-image-' . $attachment_id . '"');
            header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }
    }

    status_header(404);
    exit;
}
add_action('init', 'll_tools_maybe_serve_masked_image', 0);
add_action('template_redirect', 'll_tools_maybe_serve_masked_image', 0);
