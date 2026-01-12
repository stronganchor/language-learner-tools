<?php
// /includes/lib/image-hash.php
if (!defined('WPINC')) { die; }

function ll_tools_get_image_hash_threshold(): int {
    $threshold = (int) apply_filters('ll_tools_image_hash_threshold', 5);
    return max(0, $threshold);
}

function ll_tools_get_image_hash_meta_key(): string {
    return '_ll_tools_image_hash';
}

function ll_tools_get_attachment_image_hash(int $attachment_id): string {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $path = get_attached_file($attachment_id);
    if (!$path || !file_exists($path) || !is_readable($path)) {
        return '';
    }

    $mtime = @filemtime($path);
    $meta_key = ll_tools_get_image_hash_meta_key();
    $cached = get_post_meta($attachment_id, $meta_key, true);
    if (is_array($cached) && !empty($cached['hash']) && isset($cached['mtime']) && (int) $cached['mtime'] === (int) $mtime) {
        return (string) $cached['hash'];
    }

    $hash = ll_tools_compute_dhash($path);
    if ($hash !== '') {
        update_post_meta($attachment_id, $meta_key, [
            'hash' => $hash,
            'mtime' => (int) $mtime,
            'algo' => 'dhash',
        ]);
    }

    return $hash;
}

function ll_tools_compute_dhash(string $path): string {
    if (!function_exists('imagecreatefromstring')) {
        return '';
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }

    $img = @imagecreatefromstring($raw);
    if (!$img) {
        return '';
    }

    $scaled = $img;
    if (function_exists('imagescale')) {
        $scaled = @imagescale($img, 9, 8, IMG_BILINEAR_FIXED);
    }
    if (!$scaled) {
        imagedestroy($img);
        return '';
    }
    if ($scaled !== $img) {
        imagedestroy($img);
    }

    $hash = '';
    $nibble = 0;
    $nibble_bits = 0;

    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $left = imagecolorat($scaled, $x, $y);
            $right = imagecolorat($scaled, $x + 1, $y);
            $left_lum = ll_tools_image_luminance_from_color($left);
            $right_lum = ll_tools_image_luminance_from_color($right);
            $bit = ($left_lum > $right_lum) ? 1 : 0;
            $nibble = ($nibble << 1) | $bit;
            $nibble_bits++;
            if ($nibble_bits === 4) {
                $hash .= dechex($nibble);
                $nibble = 0;
                $nibble_bits = 0;
            }
        }
    }

    if ($nibble_bits > 0) {
        $nibble = $nibble << (4 - $nibble_bits);
        $hash .= dechex($nibble);
    }

    imagedestroy($scaled);

    return str_pad(strtolower($hash), 16, '0', STR_PAD_LEFT);
}

function ll_tools_image_luminance_from_color(int $color): float {
    $r = ($color >> 16) & 0xFF;
    $g = ($color >> 8) & 0xFF;
    $b = $color & 0xFF;
    return (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
}

function ll_tools_image_hash_hamming(string $hash_a, string $hash_b): int {
    $hash_a = strtolower(trim($hash_a));
    $hash_b = strtolower(trim($hash_b));
    if ($hash_a === '' || $hash_b === '' || strlen($hash_a) !== strlen($hash_b)) {
        return PHP_INT_MAX;
    }

    static $popcount = [0,1,1,2,1,2,2,3,1,2,2,3,2,3,3,4];
    $len = strlen($hash_a);
    $dist = 0;
    for ($i = 0; $i < $len; $i++) {
        $a = hexdec($hash_a[$i]);
        $b = hexdec($hash_b[$i]);
        $dist += $popcount[$a ^ $b] ?? 0;
    }

    return $dist;
}

function ll_tools_image_hash_is_similar(string $hash_a, string $hash_b, ?int $threshold = null): bool {
    $threshold = $threshold === null ? ll_tools_get_image_hash_threshold() : (int) $threshold;
    if ($threshold < 0) {
        $threshold = 0;
    }
    return ll_tools_image_hash_hamming($hash_a, $hash_b) <= $threshold;
}

function ll_tools_collect_word_image_hashes(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $hashes = [];
    foreach ($word_ids as $word_id) {
        $attachment_id = (int) get_post_thumbnail_id($word_id);
        if ($attachment_id <= 0) {
            continue;
        }
        $hash = ll_tools_get_attachment_image_hash($attachment_id);
        if ($hash === '') {
            continue;
        }
        $hashes[$word_id] = [
            'hash' => $hash,
            'attachment_id' => $attachment_id,
        ];
    }

    return $hashes;
}

function ll_tools_find_similar_image_pairs(array $hashes, ?int $threshold = null): array {
    $threshold = $threshold === null ? ll_tools_get_image_hash_threshold() : (int) $threshold;
    if ($threshold < 0) {
        $threshold = 0;
    }

    $pairs = [];
    $word_ids = array_keys($hashes);
    sort($word_ids, SORT_NUMERIC);
    $count = count($word_ids);
    for ($i = 0; $i < $count - 1; $i++) {
        $word_a = (int) $word_ids[$i];
        $hash_a = $hashes[$word_a]['hash'] ?? '';
        if ($hash_a === '') {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $word_b = (int) $word_ids[$j];
            $hash_b = $hashes[$word_b]['hash'] ?? '';
            if ($hash_b === '') {
                continue;
            }
            if (!ll_tools_image_hash_is_similar($hash_a, $hash_b, $threshold)) {
                continue;
            }
            $key = $word_a . '|' . $word_b;
            $pairs[$key] = [
                'a' => $word_a,
                'b' => $word_b,
                'distance' => ll_tools_image_hash_hamming($hash_a, $hash_b),
            ];
        }
    }

    return $pairs;
}
