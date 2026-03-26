<?php
if (!defined('WPINC')) { die; }

/**
 * Detect whether a local file is an animated WebP.
 */
function ll_tools_is_animated_webp_file(string $path): bool {
    if (function_exists('ll_tools_webp_optimizer_is_animated_webp_file')) {
        return ll_tools_webp_optimizer_is_animated_webp_file($path);
    }

    static $cache = [];

    $path = trim($path);
    if ($path === '' || !file_exists($path) || !is_readable($path)) {
        return false;
    }

    clearstatcache(true, $path);
    $size = @filesize($path);
    $mtime = @filemtime($path);
    $cache_key = $path . '|' . (string) (($size === false) ? 0 : (int) $size) . '|' . (string) (($mtime === false) ? 0 : (int) $mtime);
    if (array_key_exists($cache_key, $cache)) {
        return (bool) $cache[$cache_key];
    }

    $fh = @fopen($path, 'rb');
    if (!is_resource($fh)) {
        $cache[$cache_key] = false;
        return false;
    }

    $is_animated = false;
    try {
        $header = (string) fread($fh, 12);
        if (strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            $cache[$cache_key] = false;
            return false;
        }

        $max_chunks = 64;
        $chunk_count = 0;
        while (!feof($fh) && $chunk_count < $max_chunks) {
            $chunk_header = (string) fread($fh, 8);
            if (strlen($chunk_header) < 8) {
                break;
            }

            $chunk_count++;
            $chunk_type = substr($chunk_header, 0, 4);
            $chunk_size_data = unpack('Vsize', substr($chunk_header, 4, 4));
            $chunk_size = isset($chunk_size_data['size']) ? max(0, (int) $chunk_size_data['size']) : 0;

            if ($chunk_type === 'ANIM' || $chunk_type === 'ANMF') {
                $is_animated = true;
                break;
            }

            if ($chunk_size > 0) {
                $skip = $chunk_size + ($chunk_size % 2);
                if (@fseek($fh, $skip, SEEK_CUR) !== 0) {
                    break;
                }
            }
        }
    } finally {
        fclose($fh);
    }

    $cache[$cache_key] = $is_animated;
    return $is_animated;
}

/**
 * Detect whether an attachment is an animated WebP.
 */
function ll_tools_is_attachment_animated_webp(int $attachment_id): bool {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return false;
    }

    $mime = strtolower(trim((string) get_post_mime_type($attachment_id)));
    $attached_file = trim((string) get_post_meta($attachment_id, '_wp_attached_file', true));
    $is_webp = ($mime === 'image/webp');

    if (!$is_webp && $attached_file !== '') {
        $is_webp = (strtolower((string) pathinfo($attached_file, PATHINFO_EXTENSION)) === 'webp');
    }

    if (!$is_webp) {
        return false;
    }

    $path = (string) get_attached_file($attachment_id, true);
    if ($path === '' && function_exists('ll_tools_resolve_image_path_for_size')) {
        $path = (string) ll_tools_resolve_image_path_for_size($attachment_id, 'full');
    }

    return ll_tools_is_animated_webp_file($path);
}
