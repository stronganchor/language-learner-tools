<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY')) {
    define('LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY', 'll_category_canonical_aspect_ratio');
}
if (!defined('LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY')) {
    define('LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY', 'll_category_aspect_cache_version');
}

/**
 * Per-category cache version for aspect analytics.
 */
function ll_tools_get_category_aspect_cache_version($category_id): int {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return 1;
    }

    $current = (int) get_term_meta($category_id, LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY, true);
    if ($current <= 0) {
        $current = 1;
    }

    return $current;
}

/**
 * Greatest common divisor helper.
 */
function ll_tools_aspect_gcd($left, $right): int {
    $a = abs((int) $left);
    $b = abs((int) $right);

    if ($a === 0) {
        return ($b > 0) ? $b : 1;
    }
    if ($b === 0) {
        return ($a > 0) ? $a : 1;
    }

    while ($b !== 0) {
        $tmp = $b;
        $b = $a % $b;
        $a = $tmp;
    }

    return ($a > 0) ? $a : 1;
}

/**
 * Build a reduced ratio key like "4:3" from dimensions.
 */
function ll_tools_aspect_ratio_key_from_dimensions($width, $height): string {
    $width = (int) $width;
    $height = (int) $height;
    if ($width <= 0 || $height <= 0) {
        return '';
    }

    $gcd = ll_tools_aspect_gcd($width, $height);
    $num = (int) round($width / max(1, $gcd));
    $den = (int) round($height / max(1, $gcd));

    if ($num <= 0 || $den <= 0) {
        return '';
    }

    return $num . ':' . $den;
}

/**
 * Parse "W:H" into [W, H].
 *
 * @return int[]
 */
function ll_tools_aspect_ratio_dimensions_from_key($ratio_key): array {
    $ratio_key = trim((string) $ratio_key);
    if ($ratio_key === '') {
        return [0, 0];
    }

    if (!preg_match('/^(\d+)\s*:\s*(\d+)$/', $ratio_key, $matches)) {
        return [0, 0];
    }

    $width = (int) ($matches[1] ?? 0);
    $height = (int) ($matches[2] ?? 0);

    if ($width <= 0 || $height <= 0) {
        return [0, 0];
    }

    return [$width, $height];
}

/**
 * Ratio float from "W:H".
 */
function ll_tools_aspect_ratio_value_from_key($ratio_key): float {
    [$width, $height] = ll_tools_aspect_ratio_dimensions_from_key($ratio_key);
    if ($width <= 0 || $height <= 0) {
        return 0.0;
    }

    return (float) $width / (float) $height;
}

/**
 * Human-readable ratio label.
 */
function ll_tools_aspect_ratio_label_from_key($ratio_key): string {
    [$width, $height] = ll_tools_aspect_ratio_dimensions_from_key($ratio_key);
    if ($width <= 0 || $height <= 0) {
        return '';
    }

    return $width . ':' . $height;
}

/**
 * Relative tolerance used when deciding if an image differs from canonical ratio.
 */
function ll_tools_aspect_ratio_tolerance(): float {
    $raw = apply_filters('ll_tools_aspect_ratio_tolerance', 0.025);
    $value = (float) $raw;
    if (!is_finite($value)) {
        $value = 0.025;
    }

    return max(0.001, min(0.30, $value));
}

/**
 * Get full-size dimensions for an attachment.
 *
 * @return array{width:int,height:int}
 */
function ll_tools_get_attachment_full_dimensions($attachment_id): array {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return ['width' => 0, 'height' => 0];
    }

    $width = 0;
    $height = 0;

    $meta = wp_get_attachment_metadata($attachment_id);
    if (is_array($meta)) {
        $width = isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = isset($meta['height']) ? (int) $meta['height'] : 0;
    }

    if ($width <= 0 || $height <= 0) {
        $src = wp_get_attachment_image_src($attachment_id, 'full');
        if (is_array($src) && !empty($src[1]) && !empty($src[2])) {
            $width = (int) $src[1];
            $height = (int) $src[2];
        }
    }

    if (($width <= 0 || $height <= 0) && function_exists('ll_tools_resolve_image_path_for_size')) {
        $path = ll_tools_resolve_image_path_for_size($attachment_id, 'full');
        if ($path && file_exists($path) && is_readable($path)) {
            $size_data = @getimagesize($path);
            if (is_array($size_data) && !empty($size_data[0]) && !empty($size_data[1])) {
                $width = (int) $size_data[0];
                $height = (int) $size_data[1];
            }
        }
    }

    return [
        'width' => max(0, (int) $width),
        'height' => max(0, (int) $height),
    ];
}

/**
 * Get normalized aspect data for an attachment.
 *
 * @return array{attachment_id:int,width:int,height:int,ratio_key:string,ratio_label:string,ratio_value:float,url:string}
 */
function ll_tools_get_attachment_aspect_data($attachment_id): array {
    $attachment_id = (int) $attachment_id;
    $dimensions = ll_tools_get_attachment_full_dimensions($attachment_id);
    $width = (int) ($dimensions['width'] ?? 0);
    $height = (int) ($dimensions['height'] ?? 0);
    $ratio_key = ll_tools_aspect_ratio_key_from_dimensions($width, $height);

    return [
        'attachment_id' => $attachment_id,
        'width' => $width,
        'height' => $height,
        'ratio_key' => $ratio_key,
        'ratio_label' => ll_tools_aspect_ratio_label_from_key($ratio_key),
        'ratio_value' => ll_tools_aspect_ratio_value_from_key($ratio_key),
        'url' => wp_get_attachment_image_url($attachment_id, 'full') ?: '',
    ];
}

/**
 * Collect attachment usage for a category from words + word_images posts.
 *
 * @param int   $category_id
 * @param array $args {
 *     @type string[] $post_statuses
 *     @type bool     $include_word_images
 * }
 * @return array<int,array<string,mixed>>
 */
function ll_tools_collect_category_attachment_usage($category_id, array $args = []): array {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return [];
    }

    $defaults = [
        'post_statuses' => ['publish'],
        'include_word_images' => true,
    ];
    $args = array_merge($defaults, $args);

    $post_statuses = array_values(array_filter(array_map('sanitize_key', (array) $args['post_statuses'])));
    if (empty($post_statuses)) {
        $post_statuses = ['publish'];
    }

    $include_word_images = !empty($args['include_word_images']);
    $cache_version = ll_tools_get_category_aspect_cache_version($category_id);

    $cache_key = 'll_tools_aspect_usage_' . $category_id . '_' . md5(wp_json_encode([
        'cache_version' => $cache_version,
        'post_statuses' => $post_statuses,
        'include_word_images' => $include_word_images,
    ]));

    static $request_cache = [];
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, 'll_tools');
    if (is_array($cached)) {
        $request_cache[$cache_key] = $cached;
        return $cached;
    }

    $usage = [];

    $word_ids = get_posts([
        'post_type' => 'words',
        'post_status' => $post_statuses,
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'tax_query' => [[
            'taxonomy' => 'word-category',
            'field' => 'term_id',
            'terms' => [$category_id],
        ]],
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'compare' => 'EXISTS',
        ]],
    ]);

    foreach ((array) $word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $attachment_id = (int) get_post_thumbnail_id($word_id);
        if ($attachment_id <= 0) {
            continue;
        }
        if (!isset($usage[$attachment_id])) {
            $usage[$attachment_id] = [
                'attachment_id' => $attachment_id,
                'word_ids' => [],
                'word_image_ids' => [],
            ];
        }
        $usage[$attachment_id]['word_ids'][] = $word_id;
    }

    if ($include_word_images) {
        $word_image_ids = get_posts([
            'post_type' => 'word_images',
            'post_status' => $post_statuses,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [$category_id],
            ]],
            'meta_query' => [[
                'key' => '_thumbnail_id',
                'compare' => 'EXISTS',
            ]],
        ]);

        foreach ((array) $word_image_ids as $word_image_id) {
            $word_image_id = (int) $word_image_id;
            if ($word_image_id <= 0) {
                continue;
            }
            $attachment_id = (int) get_post_thumbnail_id($word_image_id);
            if ($attachment_id <= 0) {
                continue;
            }
            if (!isset($usage[$attachment_id])) {
                $usage[$attachment_id] = [
                    'attachment_id' => $attachment_id,
                    'word_ids' => [],
                    'word_image_ids' => [],
                ];
            }
            $usage[$attachment_id]['word_image_ids'][] = $word_image_id;
        }
    }

    foreach ($usage as $attachment_id => $row) {
        $usage[$attachment_id]['word_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $row['word_ids']), function ($id) {
            return $id > 0;
        })));
        $usage[$attachment_id]['word_image_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $row['word_image_ids']), function ($id) {
            return $id > 0;
        })));
    }

    wp_cache_set($cache_key, $usage, 'll_tools', 10 * MINUTE_IN_SECONDS);
    $request_cache[$cache_key] = $usage;
    return $usage;
}

/**
 * Build aspect stats for a category.
 *
 * @param int   $category_id
 * @param array $args {
 *     @type string[] $post_statuses
 *     @type bool     $include_word_images
 *     @type string   $canonical_ratio_key Override canonical ratio for this read.
 * }
 * @return array<string,mixed>
 */
function ll_tools_get_category_image_aspect_stats($category_id, array $args = []): array {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return [
            'category_id' => 0,
            'attachments' => [],
            'ratios' => [],
            'canonical' => ['key' => '', 'label' => '', 'value' => 0.0, 'source' => 'none'],
            'offending_attachment_ids' => [],
            'offending_count' => 0,
            'total_attachments' => 0,
            'total_words' => 0,
            'total_word_images' => 0,
        ];
    }

    $defaults = [
        'post_statuses' => ['publish'],
        'include_word_images' => true,
        'canonical_ratio_key' => '',
    ];
    $args = array_merge($defaults, $args);

    $post_statuses = array_values(array_filter(array_map('sanitize_key', (array) $args['post_statuses'])));
    if (empty($post_statuses)) {
        $post_statuses = ['publish'];
    }

    $include_word_images = !empty($args['include_word_images']);
    $forced_canonical_key = trim((string) $args['canonical_ratio_key']);
    [$forced_width, $forced_height] = ll_tools_aspect_ratio_dimensions_from_key($forced_canonical_key);
    $forced_canonical_key = ($forced_width > 0 && $forced_height > 0)
        ? ll_tools_aspect_ratio_key_from_dimensions($forced_width, $forced_height)
        : '';
    $cache_version = ll_tools_get_category_aspect_cache_version($category_id);

    $cache_key = 'll_tools_aspect_stats_' . $category_id . '_' . md5(wp_json_encode([
        'cache_version' => $cache_version,
        'post_statuses' => $post_statuses,
        'include_word_images' => $include_word_images,
        'forced_canonical_key' => $forced_canonical_key,
    ]));

    static $request_cache = [];
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, 'll_tools');
    if (is_array($cached)) {
        $request_cache[$cache_key] = $cached;
        return $cached;
    }

    $usage = ll_tools_collect_category_attachment_usage($category_id, [
        'post_statuses' => $post_statuses,
        'include_word_images' => $include_word_images,
    ]);

    $attachments = [];
    $ratios = [];
    $total_words = 0;
    $total_word_images = 0;

    foreach ($usage as $attachment_id => $row) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            continue;
        }

        $aspect = ll_tools_get_attachment_aspect_data($attachment_id);
        $ratio_key = ll_tools_aspect_ratio_key_from_dimensions((int) ($aspect['width'] ?? 0), (int) ($aspect['height'] ?? 0));
        if ($ratio_key === '') {
            [$ratio_width, $ratio_height] = ll_tools_aspect_ratio_dimensions_from_key((string) ($aspect['ratio_key'] ?? ''));
            if ($ratio_width > 0 && $ratio_height > 0) {
                $ratio_key = ll_tools_aspect_ratio_key_from_dimensions($ratio_width, $ratio_height);
            }
        }
        $aspect['ratio_key'] = $ratio_key;
        $aspect['ratio_label'] = ll_tools_aspect_ratio_label_from_key($ratio_key);
        $aspect['ratio_value'] = ll_tools_aspect_ratio_value_from_key($ratio_key);

        $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($row['word_ids'] ?? [])), function ($id) {
            return $id > 0;
        })));
        $word_image_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($row['word_image_ids'] ?? [])), function ($id) {
            return $id > 0;
        })));

        $aspect['word_ids'] = $word_ids;
        $aspect['word_image_ids'] = $word_image_ids;
        $aspect['word_count'] = count($word_ids);
        $aspect['word_image_count'] = count($word_image_ids);
        $aspect['usage_count'] = (int) $aspect['word_count'] + (int) $aspect['word_image_count'];
        $aspect['title'] = (string) get_the_title($attachment_id);

        $attachments[$attachment_id] = $aspect;

        $ratio_key = (string) ($aspect['ratio_key'] ?? '');
        if ($ratio_key !== '') {
            if (!isset($ratios[$ratio_key])) {
                $ratios[$ratio_key] = [
                    'key' => $ratio_key,
                    'label' => ll_tools_aspect_ratio_label_from_key($ratio_key),
                    'value' => ll_tools_aspect_ratio_value_from_key($ratio_key),
                    'attachment_count' => 0,
                    'word_count' => 0,
                    'word_image_count' => 0,
                ];
            }
            $ratios[$ratio_key]['attachment_count']++;
            $ratios[$ratio_key]['word_count'] += (int) $aspect['word_count'];
            $ratios[$ratio_key]['word_image_count'] += (int) $aspect['word_image_count'];
        }

        $total_words += (int) $aspect['word_count'];
        $total_word_images += (int) $aspect['word_image_count'];
    }

    $stored_canonical = trim((string) get_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, true));
    if ($stored_canonical !== '') {
        [$stored_width, $stored_height] = ll_tools_aspect_ratio_dimensions_from_key($stored_canonical);
        $stored_canonical = ($stored_width > 0 && $stored_height > 0)
            ? ll_tools_aspect_ratio_key_from_dimensions($stored_width, $stored_height)
            : '';
    }

    $canonical_key = '';
    $canonical_source = 'none';

    if ($forced_canonical_key !== '') {
        $canonical_key = $forced_canonical_key;
        $canonical_source = 'override';
    } elseif ($stored_canonical !== '') {
        $canonical_key = $stored_canonical;
        $canonical_source = 'saved';
    } elseif (!empty($ratios)) {
        uasort($ratios, static function ($left, $right) {
            $left_attach = (int) ($left['attachment_count'] ?? 0);
            $right_attach = (int) ($right['attachment_count'] ?? 0);
            if ($left_attach !== $right_attach) {
                return ($right_attach <=> $left_attach);
            }
            $left_words = (int) ($left['word_count'] ?? 0);
            $right_words = (int) ($right['word_count'] ?? 0);
            if ($left_words !== $right_words) {
                return ($right_words <=> $left_words);
            }
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });
        $first = reset($ratios);
        if (is_array($first) && !empty($first['key'])) {
            $canonical_key = (string) $first['key'];
            $canonical_source = 'detected';
        }
    }

    $canonical_value = ll_tools_aspect_ratio_value_from_key($canonical_key);
    $tolerance = ll_tools_aspect_ratio_tolerance();
    $offending_attachment_ids = [];

    foreach ($attachments as $attachment_id => $aspect) {
        $ratio_value = (float) ($aspect['ratio_value'] ?? 0);
        $is_offending = false;
        if ($canonical_value > 0.0 && $ratio_value > 0.0) {
            $diff = abs($ratio_value - $canonical_value) / $canonical_value;
            $is_offending = ($diff > $tolerance);
        }
        $attachments[$attachment_id]['offending'] = $is_offending;
        if ($is_offending) {
            $offending_attachment_ids[] = (int) $attachment_id;
        }
    }

    $ratio_rows = array_values($ratios);
    if (!empty($ratio_rows)) {
        usort($ratio_rows, static function ($left, $right) {
            $left_attach = (int) ($left['attachment_count'] ?? 0);
            $right_attach = (int) ($right['attachment_count'] ?? 0);
            if ($left_attach !== $right_attach) {
                return ($right_attach <=> $left_attach);
            }
            $left_words = (int) ($left['word_count'] ?? 0);
            $right_words = (int) ($right['word_count'] ?? 0);
            if ($left_words !== $right_words) {
                return ($right_words <=> $left_words);
            }
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });
    }

    $result = [
        'category_id' => $category_id,
        'attachments' => $attachments,
        'ratios' => $ratio_rows,
        'canonical' => [
            'key' => $canonical_key,
            'label' => ll_tools_aspect_ratio_label_from_key($canonical_key),
            'value' => $canonical_value,
            'source' => $canonical_source,
        ],
        'offending_attachment_ids' => array_values(array_unique(array_filter(array_map('intval', $offending_attachment_ids), function ($id) {
            return $id > 0;
        }))),
        'offending_count' => count($offending_attachment_ids),
        'total_attachments' => count($attachments),
        'total_words' => $total_words,
        'total_word_images' => $total_word_images,
    ];

    wp_cache_set($cache_key, $result, 'll_tools', 10 * MINUTE_IN_SECONDS);
    $request_cache[$cache_key] = $result;
    return $result;
}

/**
 * True when category has mixed image ratios and at least one non-canonical image.
 */
function ll_tools_category_needs_aspect_normalization($category_id, array $args = []): bool {
    $stats = ll_tools_get_category_image_aspect_stats((int) $category_id, $args);
    $ratio_count = count((array) ($stats['ratios'] ?? []));
    $offending_count = (int) ($stats['offending_count'] ?? 0);

    return ($ratio_count > 1) && ($offending_count > 0);
}

/**
 * Category bucket used for safe cross-category mixing.
 */
function ll_tools_get_category_aspect_bucket_key($category_id, array $args = []): string {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return '';
    }

    $stats = ll_tools_get_category_image_aspect_stats($category_id, $args);
    $canonical_key = (string) (($stats['canonical']['key'] ?? '') ?: '');

    if ($canonical_key !== '') {
        return 'ratio:' . $canonical_key;
    }

    return 'no-image';
}

/**
 * Filter a category list down to a single compatible aspect bucket.
 *
 * @param int[] $category_ids
 * @param array $args {
 *     @type array $categories_payload Optional category metadata payload array.
 *     @type int   $prefer_category_id Category ID whose bucket should be preferred.
 * }
 * @return int[]
 */
function ll_tools_filter_category_ids_by_aspect_bucket(array $category_ids, array $args = []): array {
    $normalized_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));
    if (count($normalized_ids) < 2) {
        return $normalized_ids;
    }

    $payload_by_id = [];
    if (!empty($args['categories_payload']) && is_array($args['categories_payload'])) {
        foreach ($args['categories_payload'] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0) {
                $payload_by_id[$cid] = $cat;
            }
        }
    }

    $bucket_by_id = [];
    $bucket_groups = [];
    foreach ($normalized_ids as $category_id) {
        $bucket = '';
        if (isset($payload_by_id[$category_id]['aspect_bucket'])) {
            $bucket = trim((string) $payload_by_id[$category_id]['aspect_bucket']);
        }
        if ($bucket === '') {
            $bucket = ll_tools_get_category_aspect_bucket_key($category_id);
        }
        if ($bucket === '') {
            $bucket = 'no-image';
        }

        $bucket_by_id[$category_id] = $bucket;
        if (!isset($bucket_groups[$bucket])) {
            $bucket_groups[$bucket] = [];
        }
        $bucket_groups[$bucket][] = $category_id;
    }

    if (count($bucket_groups) <= 1) {
        return $normalized_ids;
    }

    $preferred_category_id = isset($args['prefer_category_id']) ? (int) $args['prefer_category_id'] : 0;
    $preferred_bucket = '';
    if ($preferred_category_id > 0 && isset($bucket_by_id[$preferred_category_id])) {
        $preferred_bucket = $bucket_by_id[$preferred_category_id];
    }
    if ($preferred_bucket === '' && !empty($normalized_ids)) {
        $first_id = (int) $normalized_ids[0];
        if ($first_id > 0 && isset($bucket_by_id[$first_id])) {
            $preferred_bucket = $bucket_by_id[$first_id];
        }
    }

    if ($preferred_bucket === '' || empty($bucket_groups[$preferred_bucket])) {
        $first_bucket = array_key_first($bucket_groups);
        $preferred_bucket = is_string($first_bucket) ? $first_bucket : '';
    }

    $filtered = [];
    foreach ($normalized_ids as $category_id) {
        if (($bucket_by_id[$category_id] ?? '') !== $preferred_bucket) {
            continue;
        }
        $filtered[] = $category_id;
    }

    return !empty($filtered) ? $filtered : $normalized_ids;
}

/**
 * Get category IDs that currently need aspect normalization.
 *
 * @param int[] $category_ids Optional subset.
 * @return int[]
 */
function ll_tools_get_categories_needing_aspect_normalization(array $category_ids = []): array {
    $ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    if (empty($ids)) {
        $ids = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'fields' => 'ids',
        ]);
        if (is_wp_error($ids)) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', (array) $ids), function ($id) {
            return $id > 0;
        }));
    }

    $out = [];
    foreach ($ids as $category_id) {
        if (ll_tools_category_needs_aspect_normalization($category_id)) {
            $out[] = $category_id;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Best-effort cache clear after updates.
 */
function ll_tools_clear_category_aspect_cache($category_id): void {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return;
    }

    $current = ll_tools_get_category_aspect_cache_version($category_id);
    update_term_meta($category_id, LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY, $current + 1);
    clean_term_cache($category_id, 'word-category');
}
