<?php
// /includes/pages/wordset-pages.php
if (!defined('WPINC')) { die; }

/**
 * Wordset landing pages:
 * - URL pattern: /{wordset}/
 * - Shows a grid of vocab lesson links for that wordset.
 */

function ll_tools_get_requested_wordset_page_slug(): string {
    $slug = sanitize_title((string) get_query_var('ll_wordset_page'));
    if ($slug !== '') {
        return $slug;
    }

    if (!isset($_GET['ll_wordset_page'])) {
        return '';
    }

    $raw = wp_unslash((string) $_GET['ll_wordset_page']);
    $slug = sanitize_title($raw);
    if ($slug !== '') {
        set_query_var('ll_wordset_page', $slug);
    }
    return $slug;
}

function ll_tools_get_requested_wordset_page_view_raw(): string {
    $raw = (string) get_query_var('ll_wordset_view');
    if ($raw !== '') {
        return $raw;
    }

    if (!isset($_GET['ll_wordset_view'])) {
        return '';
    }

    $raw = wp_unslash((string) $_GET['ll_wordset_view']);
    if ($raw !== '') {
        set_query_var('ll_wordset_view', $raw);
    }
    return $raw;
}

function ll_tools_is_wordset_page_context(): bool {
    return ll_tools_get_requested_wordset_page_slug() !== '';
}

function ll_tools_get_wordset_page_term() {
    $slug = ll_tools_get_requested_wordset_page_slug();
    if ($slug === '') {
        return null;
    }
    $term = get_term_by('slug', $slug, 'wordset');
    return ($term && !is_wp_error($term)) ? $term : null;
}

function ll_tools_resolve_wordset_term($wordset) {
    if ($wordset instanceof WP_Term) {
        if ($wordset->taxonomy === 'wordset') {
            return $wordset;
        }
        return null;
    }

    if (is_numeric($wordset)) {
        $term = get_term((int) $wordset, 'wordset');
        return ($term && !is_wp_error($term)) ? $term : null;
    }

    if (!is_string($wordset)) {
        return null;
    }

    $wordset = trim($wordset);
    if ($wordset === '') {
        return null;
    }

    $term = get_term_by('slug', sanitize_title($wordset), 'wordset');
    if ($term && !is_wp_error($term)) {
        return $term;
    }

    $term = get_term_by('name', $wordset, 'wordset');
    return ($term && !is_wp_error($term)) ? $term : null;
}

function ll_tools_wordset_page_sanitize_class_list(array $classes): array {
    $normalized = [];
    foreach ($classes as $class) {
        if (!is_string($class) || $class === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($class));
        if (!is_array($parts)) {
            continue;
        }
        foreach ($parts as $part) {
            $part = sanitize_html_class($part);
            if ($part !== '') {
                $normalized[$part] = true;
            }
        }
    }
    return array_keys($normalized);
}

function ll_tools_get_wordset_page_category_rows(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_min = (int) apply_filters('ll_tools_wordset_page_min_words', 1, $wordset_id);
    if ($wordset_min < 1) {
        $wordset_min = 1;
    }
    $lesson_min = function_exists('ll_tools_get_vocab_lesson_min_word_count')
        ? ll_tools_get_vocab_lesson_min_word_count(null, $wordset_id)
        : $wordset_min;
    $min_words = max($wordset_min, $lesson_min);

    $cache_key = 'll_wordset_page_cats_' . $wordset_id . '_' . $min_words;
    $cached = wp_cache_get($cache_key, 'll_tools');
    if ($cached !== false) {
        return $cached;
    }

    $counts = function_exists('ll_tools_get_vocab_lesson_deepest_counts_for_wordset')
        ? ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id)
        : ['all' => [], 'with_images' => []];

    $category_ids = array_unique(array_merge(array_keys($counts['all']), array_keys($counts['with_images'])));
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) { return $id > 0; }));

    $eligible_ids = [];
    $counts_map = [];
    foreach ($category_ids as $category_id) {
        if (!function_exists('ll_tools_get_vocab_lesson_category_word_count')) {
            continue;
        }
        $count = ll_tools_get_vocab_lesson_category_word_count($category_id, $wordset_id, $counts);
        if ($count < $min_words) {
            continue;
        }
        if (function_exists('ll_tools_can_generate_vocab_lesson') && !ll_tools_can_generate_vocab_lesson($category_id, $wordset_id)) {
            continue;
        }
        $eligible_ids[] = $category_id;
        $counts_map[$category_id] = $count;
    }

    if (empty($eligible_ids)) {
        wp_cache_set($cache_key, [], 'll_tools', HOUR_IN_SECONDS);
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'include'    => $eligible_ids,
        'hide_empty' => false,
        'orderby'    => 'name',
    ]);

    $rows = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;
            $count = (int) ($counts_map[$term_id] ?? 0);
            if ($term_id <= 0 || $count <= 0) {
                continue;
            }
            $rows[] = [
                'term_id' => $term_id,
                'word_count' => $count,
            ];
        }
    }

    wp_cache_set($cache_key, $rows, 'll_tools', HOUR_IN_SECONDS);
    return $rows;
}

function ll_tools_get_image_dimensions_for_size(int $attachment_id, string $size = 'full'): array {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return [
            'width' => 0,
            'height' => 0,
            'ratio' => '',
        ];
    }

    $size = sanitize_key($size ?: 'full');
    $width = 0;
    $height = 0;
    $meta = wp_get_attachment_metadata($attachment_id);
    if (is_array($meta)) {
        if ($size !== 'full' && !empty($meta['sizes'][$size]['width']) && !empty($meta['sizes'][$size]['height'])) {
            $width = (int) $meta['sizes'][$size]['width'];
            $height = (int) $meta['sizes'][$size]['height'];
        }
        if (($width <= 0 || $height <= 0) && !empty($meta['width']) && !empty($meta['height'])) {
            $width = (int) $meta['width'];
            $height = (int) $meta['height'];
        }
    }

    if ($width <= 0 || $height <= 0) {
        $src = wp_get_attachment_image_src($attachment_id, $size);
        if (is_array($src) && !empty($src[1]) && !empty($src[2])) {
            $width = (int) $src[1];
            $height = (int) $src[2];
        }
    }

    if (($width <= 0 || $height <= 0) && function_exists('ll_tools_resolve_image_path_for_size')) {
        $path = ll_tools_resolve_image_path_for_size($attachment_id, $size);
        if ($path && file_exists($path) && is_readable($path)) {
            $image_size = @getimagesize($path);
            if (is_array($image_size) && !empty($image_size[0]) && !empty($image_size[1])) {
                $width = (int) $image_size[0];
                $height = (int) $image_size[1];
            }
        }
    }

    if (($width <= 0 || $height <= 0) && $size !== 'full') {
        $src = wp_get_attachment_image_src($attachment_id, 'full');
        if (is_array($src) && !empty($src[1]) && !empty($src[2])) {
            $width = (int) $src[1];
            $height = (int) $src[2];
        }
    }

    $ratio = ($width > 0 && $height > 0) ? ($width . ' / ' . $height) : '';

    return [
        'width' => $width,
        'height' => $height,
        'ratio' => $ratio,
    ];
}

function ll_tools_get_image_ratio_value(array $dimensions): float {
    $width = (int) ($dimensions['width'] ?? 0);
    $height = (int) ($dimensions['height'] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return 0.0;
    }
    return $width / $height;
}

function ll_tools_select_wordset_preview_image_size(int $attachment_id, string $preferred_size): array {
    $preferred_size = sanitize_key($preferred_size ?: 'full');
    $preferred_dimensions = ll_tools_get_image_dimensions_for_size($attachment_id, $preferred_size);
    $full_dimensions = ll_tools_get_image_dimensions_for_size($attachment_id, 'full');
    $full_ratio = ll_tools_get_image_ratio_value($full_dimensions);
    $tolerance = 0.02;

    $candidates = array_values(array_unique(array_filter([
        $preferred_size,
        'medium_large',
        'large',
        'full',
    ])));

    foreach ($candidates as $size) {
        $dimensions = $preferred_dimensions;
        if ($size === 'full') {
            $dimensions = $full_dimensions;
        } elseif ($size !== $preferred_size) {
            $dimensions = ll_tools_get_image_dimensions_for_size($attachment_id, $size);
        }

        $ratio = ll_tools_get_image_ratio_value($dimensions);
        if ($ratio <= 0) {
            continue;
        }
        if ($full_ratio <= 0) {
            return [
                'size' => $size,
                'dimensions' => $dimensions,
                'full_dimensions' => $full_dimensions,
            ];
        }

        $diff = abs($ratio - $full_ratio) / $full_ratio;
        if ($diff <= $tolerance) {
            return [
                'size' => $size,
                'dimensions' => $dimensions,
                'full_dimensions' => $full_dimensions,
            ];
        }
    }

    return [
        'size' => $preferred_size,
        'dimensions' => $preferred_dimensions,
        'full_dimensions' => $full_dimensions,
    ];
}

function ll_tools_get_image_aspect_ratio_for_size(int $attachment_id, string $size = 'full'): string {
    $dimensions = ll_tools_get_image_dimensions_for_size($attachment_id, $size);
    return $dimensions['ratio'] ?? '';
}

function ll_tools_get_wordset_category_preview(int $wordset_id, int $category_id, int $limit = 2, ?bool $requires_images = null): array {
    $limit = max(1, (int) $limit);
    $items = [];
    $query_limit = max($limit * 3, $limit);
    $deepest_only = function_exists('ll_get_deepest_categories');
    $use_images = ($requires_images !== null) ? (bool) $requires_images : true;
    if ($requires_images === null && function_exists('ll_tools_vocab_lesson_category_requires_images')) {
        $use_images = ll_tools_vocab_lesson_category_requires_images($category_id);
    } elseif ($requires_images === null && function_exists('ll_tools_get_category_quiz_config')) {
        $config = ll_tools_get_category_quiz_config($category_id);
        $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
        $option_type = (string) ($config['option_type'] ?? '');
        $use_images = ($prompt_type === 'image') || ($option_type === 'image');
    }

    $image_size = 'medium';
    $preview_aspect_ratio = '';
    $ratio_cache_key = '';
    if ($use_images) {
        $image_size = apply_filters('ll_tools_wordset_preview_image_size', 'medium', 0, $category_id, $wordset_id);
        if (function_exists('ll_tools_normalize_image_size')) {
            $image_size = ll_tools_normalize_image_size($image_size);
        } else {
            $image_size = sanitize_key($image_size ?: 'medium');
            if ($image_size === '') {
                $image_size = 'medium';
            }
        }

        $image_query = new WP_Query([
            'post_type'      => 'words',
            'post_status'    => 'publish',
            'posts_per_page' => $query_limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy' => 'word-category',
                    'field'    => 'term_id',
                    'terms'    => [$category_id],
                ],
                [
                    'taxonomy' => 'wordset',
                    'field'    => 'term_id',
                    'terms'    => [$wordset_id],
                ],
            ],
            'meta_query' => [
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (!empty($image_query->posts)) {
            $did_set_ratio = false;
            $did_select_size = false;
            $first_dimensions = [];
            foreach ($image_query->posts as $word_id) {
                if ($deepest_only) {
                    $deepest_terms = ll_get_deepest_categories($word_id);
                    $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
                    if (!in_array((int) $category_id, $deepest_ids, true)) {
                        continue;
                    }
                }
                $image_id = get_post_thumbnail_id($word_id);
                if (!$image_id) {
                    continue;
                }

                if (!$did_select_size) {
                    $size_info = ll_tools_select_wordset_preview_image_size($image_id, $image_size);
                    $image_size = (string) ($size_info['size'] ?? $image_size);
                    $first_dimensions = (array) ($size_info['dimensions'] ?? []);
                    $did_select_size = true;

                    $ratio_cache_key = 'll_wordset_preview_ratio_v2_' . $wordset_id . '_' . $category_id . '_' . $image_size;
                    $cached_ratio = wp_cache_get($ratio_cache_key, 'll_tools');
                    if ($cached_ratio === false) {
                        $cached_ratio = get_transient($ratio_cache_key);
                    }
                    if ($cached_ratio !== false && $cached_ratio !== '') {
                        $preview_aspect_ratio = (string) $cached_ratio;
                    }
                }

                $image_url = function_exists('ll_tools_get_masked_image_url')
                    ? ll_tools_get_masked_image_url($image_id, $image_size)
                    : '';
                if ($image_url === '') {
                    $image_url = wp_get_attachment_image_url($image_id, $image_size) ?: '';
                }
                if ($image_url === '') {
                    continue;
                }
                $dimensions = $first_dimensions;
                if (empty($dimensions)) {
                    $dimensions = ll_tools_get_image_dimensions_for_size($image_id, $image_size);
                }
                $first_dimensions = [];
                $ratio = (string) ($dimensions['ratio'] ?? '');
                $width = (int) ($dimensions['width'] ?? 0);
                $height = (int) ($dimensions['height'] ?? 0);
                if ($ratio !== '' && !$did_set_ratio) {
                    if ($preview_aspect_ratio !== $ratio) {
                        $preview_aspect_ratio = $ratio;
                        if ($ratio_cache_key !== '') {
                            wp_cache_set($ratio_cache_key, $preview_aspect_ratio, 'll_tools', DAY_IN_SECONDS);
                            set_transient($ratio_cache_key, $preview_aspect_ratio, DAY_IN_SECONDS);
                        }
                    }
                    $did_set_ratio = true;
                }
                if ($ratio === '' && $preview_aspect_ratio !== '') {
                    $ratio = $preview_aspect_ratio;
                }
                $items[] = [
                    'type' => 'image',
                    'url'  => $image_url,
                    'alt'  => get_the_title($word_id),
                    'ratio' => $ratio,
                    'width' => $width,
                    'height' => $height,
                ];
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
    }

    if (empty($items)) {
        $text_query = new WP_Query([
            'post_type'      => 'words',
            'post_status'    => 'publish',
            'posts_per_page' => $query_limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy' => 'word-category',
                    'field'    => 'term_id',
                    'terms'    => [$category_id],
                ],
                [
                    'taxonomy' => 'wordset',
                    'field'    => 'term_id',
                    'terms'    => [$wordset_id],
                ],
            ],
        ]);

        foreach ($text_query->posts as $word_id) {
            if ($deepest_only) {
                $deepest_terms = ll_get_deepest_categories($word_id);
                $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
                if (!in_array((int) $category_id, $deepest_ids, true)) {
                    continue;
                }
            }
            $label = get_the_title($word_id);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'type'  => 'text',
                'label' => $label,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
    }

    return [
        'items' => $items,
        'has_images' => $use_images && !empty($items) && ($items[0]['type'] ?? '') === 'image',
        'preview_aspect_ratio' => $preview_aspect_ratio,
    ];
}

function ll_tools_get_wordset_page_categories(int $wordset_id, int $preview_limit = 2): array {
    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
        return [];
    }

    $rows = ll_tools_get_wordset_page_category_rows($wordset_id);
    if (empty($rows)) {
        return [];
    }

    $lesson_map = [];
    $lesson_posts = get_posts([
        'post_type'      => 'll_vocab_lesson',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'   => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
        ],
    ]);
    foreach ($lesson_posts as $lesson_id) {
        $cat_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        if ($cat_id > 0) {
            $lesson_map[$cat_id] = (int) $lesson_id;
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $category = get_term((int) $row['term_id'], 'word-category');
        if (!$category || is_wp_error($category)) {
            continue;
        }

        $lesson_post_id = (int) ($lesson_map[(int) $category->term_id] ?? 0);
        if ($lesson_post_id <= 0) {
            continue;
        }

        $display_name = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($category)
            : $category->name;

        $requires_images = true;
        if (function_exists('ll_tools_vocab_lesson_category_requires_images')) {
            $requires_images = ll_tools_vocab_lesson_category_requires_images($category);
        } elseif (function_exists('ll_tools_get_category_quiz_config')) {
            $config = ll_tools_get_category_quiz_config($category);
            $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
            $option_type = (string) ($config['option_type'] ?? '');
            $requires_images = ($prompt_type === 'image') || ($option_type === 'image');
        }

        $image_preview_limit = max(1, (int) $preview_limit);
        $text_preview_limit = max(4, $image_preview_limit);
        $preview_limit_for_category = $requires_images ? $image_preview_limit : $text_preview_limit;

        $preview = ll_tools_get_wordset_category_preview(
            $wordset_id,
            (int) $category->term_id,
            $preview_limit_for_category,
            $requires_images
        );

        $items[] = [
            'id'         => (int) $category->term_id,
            'slug'       => $category->slug,
            'raw_name'   => html_entity_decode((string) $category->name, ENT_QUOTES, 'UTF-8'),
            'name'       => $display_name,
            'count'      => (int) ($row['word_count'] ?? 0),
            'preview'    => $preview['items'],
            'has_images' => (bool) $preview['has_images'],
            'preview_aspect_ratio' => $preview['preview_aspect_ratio'] ?? '',
            'preview_limit' => $preview_limit_for_category,
            'url'        => get_permalink($lesson_post_id),
        ];
    }

    if (!empty($items)) {
        usort($items, static function ($a, $b) {
            if (function_exists('ll_tools_locale_compare_strings')) {
                return ll_tools_locale_compare_strings((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
            return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }

    return apply_filters('ll_tools_wordset_page_categories', $items, $wordset_id);
}

function ll_tools_get_wordset_page_view(): string {
    $raw = ll_tools_get_requested_wordset_page_view_raw();
    $view = sanitize_key($raw);
    if (!in_array($view, ['progress', 'hidden-categories', 'settings'], true)) {
        return '';
    }
    return $view;
}

function ll_tools_wordset_page_has_rewrite_routes(string $slug): bool {
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return false;
    }

    static $cache = [];
    if (array_key_exists($slug, $cache)) {
        return (bool) $cache[$slug];
    }

    $rules = get_option('rewrite_rules');
    if (!is_array($rules) || empty($rules)) {
        $cache[$slug] = false;
        return false;
    }

    $quoted = preg_quote($slug, '/');
    $required_patterns = [
        '^' . $quoted . '/?$',
        '^' . $quoted . '/progress/?$',
        '^' . $quoted . '/hidden-categories/?$',
        '^' . $quoted . '/settings/?$',
    ];

    foreach ($required_patterns as $pattern) {
        if (!array_key_exists($pattern, $rules)) {
            $cache[$slug] = false;
            return false;
        }
    }

    $cache[$slug] = true;
    return true;
}

function ll_tools_get_wordset_page_view_url(WP_Term $wordset_term, string $view = ''): string {
    $slug = sanitize_title($wordset_term->slug ?? '');
    if ($slug === '') {
        return home_url('/');
    }

    $view = sanitize_key($view);

    $allowed_views = ['progress', 'hidden-categories', 'settings'];
    $query_args = ['ll_wordset_page' => $slug];
    if ($view !== '' && in_array($view, $allowed_views, true)) {
        $query_args['ll_wordset_view'] = $view;
    }
    $query_fallback_url = add_query_arg($query_args, home_url('/'));

    // Subpage links must be reliable even if rewrite rules are stale or unavailable.
    if ($view !== '' && in_array($view, $allowed_views, true)) {
        return $query_fallback_url;
    }

    $permalink_structure = (string) get_option('permalink_structure', '');
    if ($permalink_structure === '') {
        return $query_fallback_url;
    }

    $conflict = get_page_by_path($slug, OBJECT, 'page');
    $allow_pretty = (bool) apply_filters('ll_tools_wordset_page_allow_conflict', !$conflict, $slug, $wordset_term);
    if (!$allow_pretty) {
        return $query_fallback_url;
    }

    if (!ll_tools_wordset_page_has_rewrite_routes($slug)) {
        return $query_fallback_url;
    }

    return trailingslashit(home_url($slug));
}

function ll_tools_wordset_page_current_url(): string {
    if (empty($_SERVER['REQUEST_URI'])) {
        return '';
    }

    $request_uri = wp_unslash((string) $_SERVER['REQUEST_URI']);
    if ($request_uri === '') {
        return '';
    }

    $url = home_url($request_uri);
    return is_string($url) ? $url : '';
}

function ll_tools_wordset_page_normalize_same_origin_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $validated = wp_validate_redirect($url, '');
    if (!is_string($validated) || $validated === '') {
        return '';
    }

    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $target_host = (string) wp_parse_url($validated, PHP_URL_HOST);
    if ($home_host !== '' && $target_host !== '' && strtolower($home_host) !== strtolower($target_host)) {
        return '';
    }

    return $validated;
}

function ll_tools_wordset_page_get_subpage_return_url(WP_Term $wordset_term): string {
    $fallback = ll_tools_get_wordset_page_view_url($wordset_term);
    $current = ll_tools_wordset_page_current_url();
    if ($current === '') {
        return $fallback;
    }

    $current = remove_query_arg('ll_wordset_back', $current);
    $current = ll_tools_wordset_page_normalize_same_origin_url($current);
    if ($current === '') {
        return $fallback;
    }

    return $current;
}

function ll_tools_wordset_page_with_back_url(string $url, string $back_url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $back_url = ll_tools_wordset_page_normalize_same_origin_url($back_url);
    if ($back_url === '') {
        return $url;
    }

    return (string) add_query_arg('ll_wordset_back', $back_url, $url);
}

function ll_tools_wordset_page_resolve_back_url(WP_Term $wordset_term): string {
    $fallback = ll_tools_get_wordset_page_view_url($wordset_term);
    $current = ll_tools_wordset_page_current_url();
    $candidates = [];

    if (isset($_GET['ll_wordset_back'])) {
        $candidates[] = wp_unslash((string) $_GET['ll_wordset_back']);
    }

    $referer = wp_get_referer();
    if (is_string($referer) && $referer !== '') {
        $candidates[] = $referer;
    }

    foreach ($candidates as $candidate) {
        $candidate = ll_tools_wordset_page_normalize_same_origin_url((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        if ($current !== '' && untrailingslashit($candidate) === untrailingslashit($current)) {
            continue;
        }
        return $candidate;
    }

    return $fallback;
}

function ll_tools_wordset_page_render_mode_icon(string $mode, array $mode_ui, string $fallback, string $class = 'll-vocab-lesson-mode-icon'): string {
    $cfg = (isset($mode_ui[$mode]) && is_array($mode_ui[$mode])) ? $mode_ui[$mode] : [];
    if (!empty($cfg['svg'])) {
        return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $cfg['svg'] . '</span>';
    }
    $icon = !empty($cfg['icon']) ? (string) $cfg['icon'] : $fallback;
    return '<span class="' . esc_attr($class) . '" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
}

function ll_tools_wordset_page_render_hide_icon(string $class = 'll-wordset-hide-icon'): string {
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="32" cy="32" r="7" fill="currentColor"/>'
        . '<path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_unhide_icon(string $class = 'll-wordset-unhide-icon'): string {
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true" focusable="false">'
        . '<path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_hard_words_icon(string $class = ''): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M12 3.5L2.5 20.5H21.5L12 3.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path>'
        . '<line x1="12" y1="9" x2="12" y2="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>'
        . '<circle cx="12" cy="17" r="1.2" fill="currentColor"></circle>'
        . '</svg>';
}

function ll_tools_wordset_page_render_progress_icon(string $status, string $class = 'll-wordset-progress-pill__icon'): string {
    $status = sanitize_key($status);
    if ($status === 'starred') {
        return '<span class="' . esc_attr(trim($class . ' ll-wordset-progress-star-glyph-icon')) . '" aria-hidden="true">★</span>';
    }

    $svg = '';
    if ($status === 'mastered') {
        $svg = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">'
            . '<polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline>'
            . '</svg>';
    } elseif ($status === 'studied') {
        $svg = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">'
            . '<circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle>'
            . '<path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path>'
            . '</svg>';
    } elseif ($status === 'new') {
        $svg = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">'
            . '<line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>'
            . '<line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>'
            . '</svg>';
    } elseif ($status === 'hard') {
        $svg = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M32 8 L58 52 H6 Z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"></path>'
            . '<line x1="32" y1="23" x2="32" y2="37" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line>'
            . '<circle cx="32" cy="45" r="3.2" fill="currentColor"></circle>'
            . '</svg>';
    }

    if ($svg === '') {
        return '';
    }

    return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $svg . '</span>';
}

function ll_tools_wordset_page_render_priority_focus_icon(string $focus, string $class = 'll-wordset-priority-option__icon'): string {
    $focus = sanitize_key($focus);
    if ($focus === 'new') {
        return ll_tools_wordset_page_render_progress_icon('new', $class);
    }
    if ($focus === 'studied') {
        return ll_tools_wordset_page_render_progress_icon('studied', $class);
    }
    if ($focus === 'learned') {
        return ll_tools_wordset_page_render_progress_icon('mastered', $class);
    }
    if ($focus === 'starred') {
        return ll_tools_wordset_page_render_progress_icon('starred', $class);
    }
    if ($focus === 'hard') {
        return ll_tools_wordset_page_render_progress_icon('hard', $class);
    }
    return '';
}

function ll_tools_wordset_page_summary_counts(array $analytics): array {
    $summary = (isset($analytics['summary']) && is_array($analytics['summary'])) ? $analytics['summary'] : [];
    $mastered = max(0, (int) ($summary['mastered_words'] ?? 0));
    $studied_total = max(0, (int) ($summary['studied_words'] ?? 0));
    $new_words = max(0, (int) ($summary['new_words'] ?? 0));
    $starred_words = max(0, (int) ($summary['starred_words'] ?? 0));
    $hard_words = max(0, (int) ($summary['hard_words'] ?? 0));
    $studied_only = max(0, $studied_total - $mastered);
    return [
        'mastered' => $mastered,
        'studied' => $studied_only,
        'new' => $new_words,
        'starred' => $starred_words,
        'hard' => $hard_words,
    ];
}

function ll_tools_wordset_page_enqueue_scripts(): void {
    ll_enqueue_asset_by_timestamp('/js/wordset-pages.js', 'll-wordset-pages-js', ['jquery'], true);
}

function ll_tools_render_wordset_page_missing_content(array $extra_classes = [], string $wrapper_tag = 'main'): string {
    $classes = ll_tools_wordset_page_sanitize_class_list(array_merge(
        ['ll-wordset-page', 'll-wordset-page--missing'],
        $extra_classes
    ));

    if (empty($classes)) {
        $classes = ['ll-wordset-page', 'll-wordset-page--missing'];
    }

    $wrapper_tag = strtolower(sanitize_key($wrapper_tag));
    if (!in_array($wrapper_tag, ['main', 'div', 'section'], true)) {
        $wrapper_tag = 'main';
    }

    return '<' . $wrapper_tag . ' class="' . esc_attr(implode(' ', $classes)) . '"><div class="ll-wordset-empty">' .
        esc_html__('Word set not found.', 'll-tools-text-domain') .
        '</div></' . $wrapper_tag . '>';
}

function ll_tools_render_wordset_page_content($wordset, array $args = []): string {
    $defaults = [
        'show_title' => true,
        'preview_limit' => 2,
        'extra_classes' => [],
        'wrapper_tag' => 'main',
    ];
    $args = wp_parse_args($args, $defaults);

    $wrapper_tag = strtolower(sanitize_key((string) $args['wrapper_tag']));
    if (!in_array($wrapper_tag, ['main', 'div', 'section'], true)) {
        $wrapper_tag = 'main';
    }

    $wordset_term = ll_tools_resolve_wordset_term($wordset);
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return ll_tools_render_wordset_page_missing_content((array) ($args['extra_classes'] ?? []), $wrapper_tag);
    }

    $show_title = (bool) $args['show_title'];
    $preview_limit = max(1, (int) $args['preview_limit']);
    $view = ll_tools_get_wordset_page_view();
    $classes = ll_tools_wordset_page_sanitize_class_list(array_merge(
        ['ll-wordset-page'],
        (array) ($args['extra_classes'] ?? [])
    ));
    $classes[] = $view === '' ? 'll-wordset-page--main' : ('ll-wordset-page--' . sanitize_html_class($view));
    if (empty($classes)) {
        $classes = ['ll-wordset-page'];
    }

    $wordset_id = (int) $wordset_term->term_id;
    $categories = ll_tools_get_wordset_page_categories($wordset_id, $preview_limit);
    $wordset_url = ll_tools_get_wordset_page_view_url($wordset_term);
    $subpage_return_url = ll_tools_wordset_page_get_subpage_return_url($wordset_term);
    $progress_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'progress'),
        $subpage_return_url
    );
    $hidden_categories_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'hidden-categories'),
        $subpage_return_url
    );
    $settings_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'settings'),
        $subpage_return_url
    );
    $back_url = ll_tools_wordset_page_resolve_back_url($wordset_term);
    $is_study_user = is_user_logged_in() && (!function_exists('ll_tools_user_study_can_access') || ll_tools_user_study_can_access());

    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $mode_labels = [
        'practice' => __('Practice', 'll-tools-text-domain'),
        'learning' => __('Learn', 'll-tools-text-domain'),
        'listening' => __('Listen', 'll-tools-text-domain'),
        'self-check' => __('Self check', 'll-tools-text-domain'),
        'gender' => __('Gender', 'll-tools-text-domain'),
    ];
    $mode_fallback_icons = [
        'practice' => '❓',
        'learning' => '🎓',
        'listening' => '🎧',
        'self-check' => '✔✖',
        'gender' => '⚥',
    ];

    $default_goals = function_exists('ll_tools_default_user_study_goals')
        ? ll_tools_default_user_study_goals()
        : [
            'enabled_modes' => ['learning', 'practice', 'listening', 'gender', 'self-check'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
            'priority_focus' => '',
            'prioritize_new_words' => false,
            'prioritize_studied_words' => false,
            'prioritize_learned_words' => false,
            'prefer_starred_words' => false,
            'prefer_hard_words' => false,
        ];
    $goals = $default_goals;
    $study_state = [
        'wordset_id' => $wordset_id,
        'category_ids' => [],
        'starred_word_ids' => [],
        'star_mode' => 'normal',
        'fast_transitions' => false,
    ];
    $analytics = [];
    $next_activity = null;
    $recommendation_queue = [];
    $study_categories = [];
    $gender_options = [];
    $gender_enabled = false;
    if ($is_study_user) {
        if (function_exists('ll_tools_get_user_study_goals')) {
            $goals = ll_tools_get_user_study_goals(get_current_user_id());
        }
        if (function_exists('ll_tools_get_user_study_state')) {
            $study_state = array_merge($study_state, ll_tools_get_user_study_state(get_current_user_id()));
        }
        $study_state['wordset_id'] = $wordset_id;
        if (function_exists('ll_tools_build_user_study_analytics_payload')) {
            $analytics_include_ignored = ($view === 'progress');
            $analytics = ll_tools_build_user_study_analytics_payload(get_current_user_id(), $wordset_id, [], 14, $analytics_include_ignored);
        }
        if (function_exists('ll_tools_user_study_categories_for_wordset')) {
            $study_categories = ll_tools_user_study_categories_for_wordset($wordset_id);
        }
    }
    if (function_exists('ll_tools_wordset_has_grammatical_gender')) {
        $gender_enabled = ll_tools_wordset_has_grammatical_gender($wordset_id);
    }
    if ($gender_enabled && function_exists('ll_tools_wordset_get_gender_options')) {
        $gender_options = ll_tools_wordset_get_gender_options($wordset_id);
    }

    $study_by_id = [];
    foreach ((array) $study_categories as $study_cat) {
        $cid = isset($study_cat['id']) ? (int) $study_cat['id'] : 0;
        if ($cid > 0) {
            $study_by_id[$cid] = $study_cat;
        }
    }

    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $iid = (int) $ignored_id;
        if ($iid > 0) {
            $ignored_lookup[$iid] = true;
        }
    }

    $enhanced_categories = [];
    $visible_categories = [];
    $hidden_categories = [];
    foreach ($categories as $cat) {
        $cid = (int) ($cat['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $study_cat = isset($study_by_id[$cid]) && is_array($study_by_id[$cid]) ? $study_by_id[$cid] : [];
        $enhanced = $cat;
        $enhanced['raw_name'] = isset($study_cat['name']) && (string) $study_cat['name'] !== ''
            ? (string) $study_cat['name']
            : (string) ($cat['raw_name'] ?? $cat['name'] ?? '');
        $enhanced['translation'] = isset($study_cat['translation']) && (string) $study_cat['translation'] !== ''
            ? (string) $study_cat['translation']
            : (string) ($cat['name'] ?? '');
        $enhanced['mode'] = (string) ($study_cat['mode'] ?? 'image');
        $enhanced['prompt_type'] = (string) ($study_cat['prompt_type'] ?? 'audio');
        $enhanced['option_type'] = (string) ($study_cat['option_type'] ?? 'image');
        $enhanced['learning_supported'] = !array_key_exists('learning_supported', $study_cat) || !empty($study_cat['learning_supported']);
        $enhanced['gender_supported'] = !empty($study_cat['gender_supported']);
        $enhanced['aspect_bucket'] = isset($study_cat['aspect_bucket']) ? (string) $study_cat['aspect_bucket'] : '';
        if ($enhanced['aspect_bucket'] === '' && function_exists('ll_tools_get_category_aspect_bucket_key')) {
            $enhanced['aspect_bucket'] = (string) ll_tools_get_category_aspect_bucket_key($cid);
        }
        if ($enhanced['aspect_bucket'] === '') {
            $enhanced['aspect_bucket'] = 'no-image';
        }
        $enhanced['hidden'] = !empty($ignored_lookup[$cid]);

        $enhanced_categories[] = $enhanced;
        if ($enhanced['hidden']) {
            $hidden_categories[] = $enhanced;
        } else {
            $visible_categories[] = $enhanced;
        }
    }
    $hidden_category_count = count($hidden_categories);

    $visible_category_ids = array_values(array_map('intval', wp_list_pluck($visible_categories, 'id')));
    $visible_category_count = count($visible_category_ids);
    $has_visible_gender_supported_category = false;
    foreach ($visible_categories as $visible_cat) {
        if (!empty($visible_cat['gender_supported'])) {
            $has_visible_gender_supported_category = true;
            break;
        }
    }
    $gender_mode_available = $gender_enabled && $has_visible_gender_supported_category;
    if ($view === '' && $visible_category_count === 1) {
        $classes[] = 'll-wordset-page--single-category';
    }
    $classes = ll_tools_wordset_page_sanitize_class_list($classes);
    if ($is_study_user) {
        if (function_exists('ll_tools_get_user_recommendation_queue')) {
            $recommendation_queue = ll_tools_get_user_recommendation_queue(get_current_user_id(), $wordset_id);
        }
        if (empty($recommendation_queue) && function_exists('ll_tools_refresh_user_recommendation_queue')) {
            $recommendation_queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $visible_category_ids, $study_categories, 8);
        }
        if (function_exists('ll_tools_recommendation_queue_pick_next')) {
            $next_activity = ll_tools_recommendation_queue_pick_next($recommendation_queue);
        }
        if (!$next_activity && function_exists('ll_tools_build_next_activity_recommendation')) {
            $next_activity = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $visible_category_ids, $study_categories);
        }
    }
    $summary_counts = ll_tools_wordset_page_summary_counts($analytics);
    $category_progress_lookup = [];
    $analytics_category_rows = (isset($analytics['categories']) && is_array($analytics['categories'])) ? $analytics['categories'] : [];
    foreach ($analytics_category_rows as $analytics_category_row) {
        if (!is_array($analytics_category_row)) {
            continue;
        }
        $cid = isset($analytics_category_row['id']) ? (int) $analytics_category_row['id'] : 0;
        if ($cid <= 0) {
            continue;
        }
        $word_total = max(0, (int) ($analytics_category_row['word_count'] ?? 0));
        $mastered_words = max(0, (int) ($analytics_category_row['mastered_words'] ?? 0));
        $studied_total = max(0, (int) ($analytics_category_row['studied_words'] ?? 0));
        $studied_words = max(0, $studied_total - $mastered_words);
        $new_words = max(0, (int) ($analytics_category_row['new_words'] ?? max(0, $word_total - $studied_total)));
        if ($word_total <= 0) {
            $word_total = $mastered_words + $studied_words + $new_words;
        }
        $category_progress_lookup[$cid] = [
            'total' => max(0, $word_total),
            'mastered' => $mastered_words,
            'studied' => $studied_words,
            'new' => $new_words,
        ];
    }
    $category_starred_lookup = [];
    $category_starred_word_lookup = [];
    $analytics_word_rows = (isset($analytics['words']) && is_array($analytics['words'])) ? $analytics['words'] : [];
    foreach ($analytics_word_rows as $analytics_word_row) {
        if (!is_array($analytics_word_row) || empty($analytics_word_row['is_starred'])) {
            continue;
        }

        $word_id = isset($analytics_word_row['id']) ? (int) $analytics_word_row['id'] : 0;
        $word_category_ids = [];
        foreach ((array) ($analytics_word_row['category_ids'] ?? []) as $word_category_id) {
            $cid = (int) $word_category_id;
            if ($cid > 0) {
                $word_category_ids[] = $cid;
            }
        }
        if (empty($word_category_ids)) {
            $fallback_category_id = isset($analytics_word_row['category_id']) ? (int) $analytics_word_row['category_id'] : 0;
            if ($fallback_category_id > 0) {
                $word_category_ids[] = $fallback_category_id;
            }
        }
        $word_category_ids = array_values(array_unique($word_category_ids));

        foreach ($word_category_ids as $word_category_id) {
            if (!isset($category_starred_word_lookup[$word_category_id]) || !is_array($category_starred_word_lookup[$word_category_id])) {
                $category_starred_word_lookup[$word_category_id] = [];
            }
            if ($word_id > 0) {
                $category_starred_word_lookup[$word_category_id][$word_id] = true;
            } else {
                $category_starred_word_lookup[$word_category_id][] = true;
            }
        }
    }
    foreach ($category_starred_word_lookup as $word_category_id => $word_lookup) {
        $category_starred_lookup[(int) $word_category_id] = count((array) $word_lookup);
    }

    $script_categories = array_map(function (array $cat): array {
        $preview_items = array_values((array) ($cat['preview'] ?? []));
        $preview_items = array_slice($preview_items, 0, 2);
        $preview_payload = [];
        foreach ($preview_items as $preview_item) {
            if (!is_array($preview_item)) {
                continue;
            }
            $item_type = (string) ($preview_item['type'] ?? 'text');
            if ($item_type === 'image') {
                $preview_payload[] = [
                    'type' => 'image',
                    'url' => (string) ($preview_item['url'] ?? ''),
                    'alt' => (string) ($preview_item['alt'] ?? ''),
                ];
            } else {
                $preview_payload[] = [
                    'type' => 'text',
                    'label' => (string) ($preview_item['label'] ?? ''),
                ];
            }
        }

        return [
            'id' => (int) ($cat['id'] ?? 0),
            'slug' => (string) ($cat['slug'] ?? ''),
            'name' => (string) ($cat['raw_name'] ?? $cat['name'] ?? ''),
            'translation' => (string) ($cat['translation'] ?? ($cat['name'] ?? '')),
            'count' => (int) ($cat['count'] ?? 0),
            'url' => (string) ($cat['url'] ?? ''),
            'mode' => (string) ($cat['mode'] ?? ''),
            'prompt_type' => (string) ($cat['prompt_type'] ?? ''),
            'option_type' => (string) ($cat['option_type'] ?? ''),
            'learning_supported' => !empty($cat['learning_supported']),
            'gender_supported' => !empty($cat['gender_supported']),
            'aspect_bucket' => (string) ($cat['aspect_bucket'] ?? 'no-image'),
            'hidden' => !empty($cat['hidden']),
            'preview' => $preview_payload,
            'has_images' => !empty($cat['has_images']),
        ];
    }, $enhanced_categories);

    ll_tools_wordset_page_enqueue_styles();
    ll_tools_wordset_page_enqueue_scripts();
    wp_localize_script('ll-wordset-pages-js', 'llWordsetPageData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => $is_study_user ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => $is_study_user,
        'sortLocale' => get_locale(),
        'view' => $view === '' ? 'main' : $view,
        'wordsetId' => $wordset_id,
        'wordsetSlug' => (string) $wordset_term->slug,
        'wordsetName' => (string) $wordset_term->name,
        'links' => [
            'base' => $wordset_url,
            'progress' => $progress_url,
            'hidden' => $hidden_categories_url,
            'settings' => $settings_url,
        ],
        'progressIncludeHidden' => ($view === 'progress'),
        'categories' => $script_categories,
        'visibleCategoryIds' => $visible_category_ids,
        'hiddenCategoryIds' => array_values(array_map('intval', wp_list_pluck($hidden_categories, 'id'))),
        'state' => $study_state,
        'goals' => $goals,
        'nextActivity' => $next_activity,
        'recommendationQueue' => $recommendation_queue,
        'analytics' => $analytics,
        'modeUi' => $mode_ui,
        'gender' => [
            'enabled' => $gender_enabled,
            'options' => array_values(array_filter(array_map('strval', (array) $gender_options), function ($opt) {
                return $opt !== '';
            })),
            'min_count' => (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ),
        ],
        'summaryCounts' => $summary_counts,
        'hardWordDifficultyThreshold' => 4,
        'i18n' => [
            'nextNone' => __('No recommendation yet. Do one round first.', 'll-tools-text-domain'),
            'nextLoading' => __('Loading next recommendation...', 'll-tools-text-domain'),
            'nextReady' => __('Recommended: %1$s in %2$s (%3$d words).', 'll-tools-text-domain'),
            'nextReadyNoCount' => __('Recommended: %1$s in %2$s.', 'll-tools-text-domain'),
            'categoriesLabel' => __('Categories', 'll-tools-text-domain'),
            'starredWordsLabel' => __('Starred words', 'll-tools-text-domain'),
            'repeatLabel' => __('Repeat', 'll-tools-text-domain'),
            'continueLabel' => __('Continue', 'll-tools-text-domain'),
            'resultsDifferentChunk' => __('Categories', 'll-tools-text-domain'),
            'resultsDifferentChunkCount' => __('Categories (%2$d)', 'll-tools-text-domain'),
            'resultsRecommendedActivity' => __('Recommended', 'll-tools-text-domain'),
            'resultsRecommendedLoading' => __('Loading recommendation...', 'll-tools-text-domain'),
            'resultsRecommendedActivityCount' => __('Recommended (%2$d)', 'll-tools-text-domain'),
            'selectionLabel' => __('Select categories to study together', 'll-tools-text-domain'),
            'selectionCount' => __('%d selected', 'll-tools-text-domain'),
            'selectionCountWords' => __('%1$d selected · %2$d words', 'll-tools-text-domain'),
            'selectionWordsOnly' => __('%d words', 'll-tools-text-domain'),
            'selectionStarredOnly' => __('Starred only', 'll-tools-text-domain'),
            'selectionHardOnly' => __('Hard words only', 'll-tools-text-domain'),
            'selectAll' => __('Select all', 'll-tools-text-domain'),
            'deselectAll' => __('Deselect all', 'll-tools-text-domain'),
            'priorityFocusNew' => __('New words', 'll-tools-text-domain'),
            'priorityFocusStudied' => __('In progress words', 'll-tools-text-domain'),
            'priorityFocusLearned' => __('Learned words', 'll-tools-text-domain'),
            'priorityFocusStarred' => __('Starred words', 'll-tools-text-domain'),
            'priorityFocusHard' => __('Hard words', 'll-tools-text-domain'),
            'clearSelection' => __('Clear', 'll-tools-text-domain'),
            'saveError' => __('Unable to save right now.', 'll-tools-text-domain'),
            'noCategoriesSelected' => __('Select at least one category.', 'll-tools-text-domain'),
            'noWordsInSelection' => __('No quiz words are available for this selection.', 'll-tools-text-domain'),
            'noStarredWordsInSelection' => __('No starred words are available for this selection.', 'll-tools-text-domain'),
            'noHardWordsInSelection' => __('No hard words are available for this selection.', 'll-tools-text-domain'),
            'noStarredHardWordsInSelection' => __('No starred hard words are available for this selection.', 'll-tools-text-domain'),
            'hiddenEmpty' => __('No hidden categories in this word set.', 'll-tools-text-domain'),
            'hiddenCountLabel' => __('Hidden categories: %d', 'll-tools-text-domain'),
            'queueEmpty' => __('No upcoming activities yet.', 'll-tools-text-domain'),
            'queueRemove' => __('Remove activity', 'll-tools-text-domain'),
            'queueWordCount' => __('%d words', 'll-tools-text-domain'),
            'analyticsLabel' => __('Progress', 'll-tools-text-domain'),
            'analyticsLoading' => __('Loading progress...', 'll-tools-text-domain'),
            'analyticsUnavailable' => __('Progress is unavailable right now.', 'll-tools-text-domain'),
            'analyticsScopeSelected' => __('Selected categories (%d)', 'll-tools-text-domain'),
            'analyticsScopeAll' => __('All categories (%d)', 'll-tools-text-domain'),
            'analyticsMastered' => __('Learned', 'll-tools-text-domain'),
            'analyticsStudied' => __('In progress', 'll-tools-text-domain'),
            'analyticsNew' => __('New', 'll-tools-text-domain'),
            'analyticsStarred' => __('Starred', 'll-tools-text-domain'),
            'analyticsHard' => __('Hard', 'll-tools-text-domain'),
            'analyticsDaily' => __('Last 14 days', 'll-tools-text-domain'),
            'analyticsDailyEmpty' => __('No activity yet.', 'll-tools-text-domain'),
            'analyticsTabCategories' => __('Categories', 'll-tools-text-domain'),
            'analyticsTabWords' => __('Words', 'll-tools-text-domain'),
            'analyticsNoRows' => __('No data yet.', 'll-tools-text-domain'),
            'analyticsWordFilterAll' => __('All', 'll-tools-text-domain'),
            'analyticsWordFilterHard' => __('Hardest', 'll-tools-text-domain'),
            'analyticsWordFilterNew' => __('New', 'll-tools-text-domain'),
            'analyticsUnseen' => __('New', 'll-tools-text-domain'),
            'analyticsWordStatusMastered' => __('Learned', 'll-tools-text-domain'),
            'analyticsWordStatusStudied' => __('In progress', 'll-tools-text-domain'),
            'analyticsWordStatusNew' => __('New', 'll-tools-text-domain'),
            'analyticsStarWord' => __('Star word', 'll-tools-text-domain'),
            'analyticsUnstarWord' => __('Unstar word', 'll-tools-text-domain'),
            'analyticsFilterAny' => __('Any', 'll-tools-text-domain'),
            'analyticsFilterStar' => __('Starred', 'll-tools-text-domain'),
            'analyticsFilterStatus' => __('Status', 'll-tools-text-domain'),
            'analyticsFilterLast' => __('Last', 'll-tools-text-domain'),
            'analyticsFilterDifficulty' => __('Difficulty Score', 'll-tools-text-domain'),
            'analyticsFilterSeen' => __('Seen', 'll-tools-text-domain'),
            'analyticsFilterWrong' => __('Wrong', 'll-tools-text-domain'),
            'analyticsFilterStarredOnly' => __('Starred only', 'll-tools-text-domain'),
            'analyticsFilterUnstarredOnly' => __('Unstarred only', 'll-tools-text-domain'),
            'analyticsFilterDifficultyHard' => __('Hard words', 'll-tools-text-domain'),
            'analyticsClearFilters' => __('Clear filters', 'll-tools-text-domain'),
            'analyticsFilterLast24h' => __('Last 24h', 'll-tools-text-domain'),
            'analyticsFilterLast7d' => __('Last 7d', 'll-tools-text-domain'),
            'analyticsFilterLast30d' => __('Last 30d', 'll-tools-text-domain'),
            'analyticsFilterLastOlder' => __('Older', 'll-tools-text-domain'),
            'analyticsFilterLastNever' => __('Never', 'll-tools-text-domain'),
            'analyticsSortAsc' => __('Sort ascending', 'll-tools-text-domain'),
            'analyticsSortDesc' => __('Sort descending', 'll-tools-text-domain'),
            'analyticsSelectAllShown' => __('Select all', 'll-tools-text-domain'),
            'analyticsDeselectAllShown' => __('Deselect all', 'll-tools-text-domain'),
            'analyticsSelectAllWithContext' => __('Select all: %1$s', 'll-tools-text-domain'),
            'analyticsDeselectAllWithContext' => __('Deselect all: %1$s', 'll-tools-text-domain'),
            'analyticsSelectAllContextFiltered' => __('Filtered words', 'll-tools-text-domain'),
            'analyticsSelectionCount' => __('%d selected words', 'll-tools-text-domain'),
            'analyticsLast' => __('Last', 'll-tools-text-domain'),
            'analyticsNever' => __('Never', 'll-tools-text-domain'),
            'analyticsDayEvents' => __('%1$d events, %2$d words', 'll-tools-text-domain'),
            'modePractice' => __('Practice', 'll-tools-text-domain'),
            'modeLearning' => __('Learn', 'll-tools-text-domain'),
            'modeListening' => __('Listen', 'll-tools-text-domain'),
            'modeGender' => __('Gender', 'll-tools-text-domain'),
            'modeSelfCheck' => __('Self Check', 'll-tools-text-domain'),
        ],
    ]);

    ob_start();
    ?>
    <<?php echo esc_html($wrapper_tag); ?>
        class="<?php echo esc_attr(implode(' ', $classes)); ?>"
        data-ll-wordset-page
        data-ll-wordset-view="<?php echo esc_attr($view === '' ? 'main' : $view); ?>"
        data-ll-visible-category-count="<?php echo esc_attr($visible_category_count); ?>"
        data-ll-wordset-id="<?php echo esc_attr($wordset_id); ?>">
        <?php if ($view === 'progress') : ?>
            <header class="ll-wordset-subpage-head">
                <a class="ll-wordset-back ll-vocab-lesson-back" href="<?php echo esc_url($back_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_term->name)); ?>">
                    <span class="ll-wordset-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                            <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="ll-wordset-back__label"><?php echo esc_html($wordset_term->name); ?></span>
                </a>
                <h1 class="ll-wordset-title"><?php echo esc_html__('Progress', 'll-tools-text-domain'); ?></h1>
            </header>
            <?php if (!$is_study_user) : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('Sign in to view wordset progress.', 'll-tools-text-domain'); ?>
                </div>
            <?php else : ?>
                <section class="ll-wordset-progress-view" data-ll-wordset-progress-root>
                    <div class="ll-wordset-progress-head">
                        <span class="ll-wordset-progress-scope" data-ll-wordset-progress-scope></span>
                        <p class="ll-wordset-progress-status" data-ll-wordset-progress-status><?php echo esc_html__('Loading progress...', 'll-tools-text-domain'); ?></p>
                    </div>

                    <div class="ll-wordset-progress-graph-wrap">
                        <span class="ll-wordset-progress-subtitle"><?php echo esc_html__('Last 14 days', 'll-tools-text-domain'); ?></span>
                        <div class="ll-wordset-progress-graph" data-ll-wordset-progress-graph></div>
                    </div>

                    <div class="ll-wordset-progress-summary" data-ll-wordset-progress-summary>
                        <div class="ll-wordset-progress-kpi ll-wordset-progress-kpi--mastered">
                            <span class="ll-wordset-progress-kpi-icon-wrap">
                                <?php echo ll_tools_wordset_page_render_progress_icon('mastered', 'll-wordset-progress-kpi-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                            <span class="ll-wordset-progress-kpi-value"><?php echo (int) $summary_counts['mastered']; ?></span>
                            <span class="ll-wordset-progress-kpi-label"><?php echo esc_html__('Learned', 'll-tools-text-domain'); ?></span>
                        </div>
                        <div class="ll-wordset-progress-kpi ll-wordset-progress-kpi--studied">
                            <span class="ll-wordset-progress-kpi-icon-wrap">
                                <?php echo ll_tools_wordset_page_render_progress_icon('studied', 'll-wordset-progress-kpi-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                            <span class="ll-wordset-progress-kpi-value"><?php echo (int) $summary_counts['studied']; ?></span>
                            <span class="ll-wordset-progress-kpi-label"><?php echo esc_html__('In progress', 'll-tools-text-domain'); ?></span>
                        </div>
                        <div class="ll-wordset-progress-kpi ll-wordset-progress-kpi--new">
                            <span class="ll-wordset-progress-kpi-icon-wrap">
                                <?php echo ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-kpi-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                            <span class="ll-wordset-progress-kpi-value"><?php echo (int) $summary_counts['new']; ?></span>
                            <span class="ll-wordset-progress-kpi-label"><?php echo esc_html__('New', 'll-tools-text-domain'); ?></span>
                        </div>
                    </div>

                    <div class="ll-wordset-progress-tabs" role="tablist" aria-label="<?php echo esc_attr__('Progress', 'll-tools-text-domain'); ?>">
                        <button type="button" class="ll-wordset-progress-tab active" data-ll-wordset-progress-tab="categories" aria-selected="true"><?php echo esc_html__('Categories', 'll-tools-text-domain'); ?></button>
                        <button type="button" class="ll-wordset-progress-tab" data-ll-wordset-progress-tab="words" aria-selected="false"><?php echo esc_html__('Words', 'll-tools-text-domain'); ?></button>
                    </div>

                    <div class="ll-wordset-progress-panel" data-ll-wordset-progress-panel="categories">
                        <div class="ll-wordset-progress-search ll-wordset-progress-search--categories">
                            <label class="screen-reader-text" for="ll-wordset-progress-category-search-input"><?php echo esc_html__('Search categories', 'll-tools-text-domain'); ?></label>
                            <input
                                id="ll-wordset-progress-category-search-input"
                                class="ll-wordset-progress-search__input"
                                type="search"
                                data-ll-wordset-progress-category-search
                                placeholder="<?php echo esc_attr__('Search categories', 'll-tools-text-domain'); ?>"
                                autocomplete="off"
                            />
                            <span class="ll-wordset-progress-search__loading" data-ll-wordset-progress-category-search-loading hidden aria-hidden="true"></span>
                        </div>
                        <div class="ll-wordset-progress-table-wrap">
                            <table class="ll-wordset-progress-table ll-wordset-progress-table--categories">
                                <thead>
                                    <tr>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="category" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="category">
                                                <span><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="progress" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="progress">
                                                <span class="ll-wordset-progress-sort-label ll-wordset-progress-sort-label--progress" data-mobile-label="<?php echo esc_attr__('Progress', 'll-tools-text-domain'); ?>"><?php echo esc_html__('Word Progress', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="activity" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="activity">
                                                <span><?php echo esc_html__('Activity', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="last" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="last">
                                                <span><?php echo esc_html__('Last', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody data-ll-wordset-progress-categories-body>
                                    <tr>
                                        <td colspan="4"><?php echo esc_html__('No data yet.', 'll-tools-text-domain'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="ll-wordset-progress-panel" data-ll-wordset-progress-panel="words" hidden>
                        <div class="ll-wordset-progress-search-tools">
                            <div class="ll-wordset-progress-search">
                                <label class="screen-reader-text" for="ll-wordset-progress-search-input"><?php echo esc_html__('Search words', 'll-tools-text-domain'); ?></label>
                                <input
                                    id="ll-wordset-progress-search-input"
                                    class="ll-wordset-progress-search__input"
                                    type="search"
                                    data-ll-wordset-progress-search
                                    placeholder="<?php echo esc_attr__('Search words or translations', 'll-tools-text-domain'); ?>"
                                    autocomplete="off"
                                />
                                <span class="ll-wordset-progress-search__loading" data-ll-wordset-progress-search-loading hidden aria-hidden="true"></span>
                            </div>
                            <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-progress-select-all aria-pressed="false">
                                <?php echo esc_html__('Select all', 'll-tools-text-domain'); ?>
                            </button>
                            <button type="button" class="ll-wordset-progress-clear-filters" data-ll-wordset-progress-clear-filters hidden>
                                <?php echo esc_html__('Clear filters', 'll-tools-text-domain'); ?>
                            </button>
                        </div>
                        <div class="ll-wordset-progress-table-wrap">
                            <table class="ll-wordset-progress-table ll-wordset-progress-table--words">
                                <thead>
                                    <tr>
                                        <th scope="col">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="star" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter star status', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <span class="screen-reader-text"><?php echo esc_html__('Starred', 'll-tools-text-domain'); ?></span>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="star" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter star status', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="star"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="word" data-mobile-label="<?php echo esc_attr__('Word', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="word">
                                                <span><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="category" data-mobile-label="<?php echo esc_attr__('Category', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="category" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter category', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="category">
                                                    <span><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop ll-wordset-progress-filter-pop--category" data-ll-wordset-progress-filter-pop="category" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset ll-wordset-progress-category-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter category', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options ll-wordset-progress-category-filter-options" data-ll-wordset-progress-category-filter-options></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="status" data-mobile-label="<?php echo esc_attr__('Status', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="status" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter status', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="status">
                                                    <span><?php echo esc_html__('Status', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="status" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter status', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="status"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="difficulty" data-mobile-label="<?php echo esc_attr__('Difficulty', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="difficulty" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter difficulty score', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="difficulty">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Difficulty Score', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-difficulty-icon-wrap" aria-hidden="true">
                                                        <?php echo ll_tools_wordset_page_render_hard_words_icon('ll-wordset-progress-sort-difficulty-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                    </span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="difficulty" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter difficulty score', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="difficulty"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="seen" data-mobile-label="<?php echo esc_attr__('Seen', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="seen" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter seen count', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="seen">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Seen', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-seen-icon-wrap" aria-hidden="true">
                                                        <?php echo ll_tools_wordset_page_render_unhide_icon('ll-wordset-progress-sort-seen-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                    </span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="seen" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter seen count', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="seen"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="wrong" data-mobile-label="<?php echo esc_attr__('Wrong', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="wrong" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter wrong count', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="wrong">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Wrong', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-wrong-icon-wrap" aria-hidden="true">
                                                        <?php echo ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-sort-wrong-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                    </span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="wrong" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter wrong count', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="wrong"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-sort-th="last" data-mobile-label="<?php echo esc_attr__('Last', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="last" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter last seen', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="last">
                                                    <span><?php echo esc_html__('Last', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="last" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter last seen', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="last"></div>
                                                </fieldset>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody data-ll-wordset-progress-words-body>
                                    <tr>
                                        <td colspan="8"><?php echo esc_html__('No data yet.', 'll-tools-text-domain'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="ll-wordset-selection-bar ll-wordset-progress-selection-bar" data-ll-wordset-progress-selection-bar hidden>
                            <span class="ll-wordset-selection-bar__text" data-ll-wordset-progress-selection-count><?php echo esc_html__('0 selected words', 'll-tools-text-domain'); ?></span>
                            <div class="ll-wordset-selection-bar__actions">
                                <?php
                                $progress_selection_modes = ['learning', 'practice', 'listening'];
                                if ($gender_enabled) {
                                    $progress_selection_modes[] = 'gender';
                                }
                                $progress_selection_modes[] = 'self-check';
                                foreach ($progress_selection_modes as $mode) :
                                ?>
                                    <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-wordset-progress-selection-mode data-mode="<?php echo esc_attr($mode); ?>">
                                        <?php echo ll_tools_wordset_page_render_mode_icon($mode, $mode_ui, $mode_fallback_icons[$mode] ?? '❓'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_labels[$mode] ?? ucfirst($mode)); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="ll-wordset-selection-bar__clear" data-ll-wordset-progress-selection-clear aria-label="<?php echo esc_attr__('Clear selection', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-selection-bar__clear-icon" aria-hidden="true">x</span>
                            </button>
                        </div>
                    </div>
                </section>
                <div class="ll-wordset-flashcard-host" data-ll-wordset-flashcard-host>
                    <?php
                    echo do_shortcode(sprintf(
                        '[flashcard_widget embed="false" wordset="%s" wordset_fallback="false" quiz_mode="practice" launch_context="dashboard"]',
                        esc_attr($wordset_term->slug)
                    ));
                    ?>
                </div>
            <?php endif; ?>
        <?php elseif ($view === 'hidden-categories') : ?>
            <header class="ll-wordset-subpage-head">
                <a class="ll-wordset-back ll-vocab-lesson-back" href="<?php echo esc_url($back_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_term->name)); ?>">
                    <span class="ll-wordset-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                            <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="ll-wordset-back__label"><?php echo esc_html($wordset_term->name); ?></span>
                </a>
                <h1 class="ll-wordset-title"><?php echo esc_html__('Hidden Categories', 'll-tools-text-domain'); ?></h1>
            </header>
            <?php if (!$is_study_user) : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('Sign in to manage hidden categories.', 'll-tools-text-domain'); ?>
                </div>
            <?php else : ?>
                <section class="ll-wordset-hidden-categories" data-ll-wordset-hidden-list>
                    <?php if (empty($hidden_categories)) : ?>
                        <div class="ll-wordset-empty">
                            <?php echo esc_html__('No hidden categories in this word set.', 'll-tools-text-domain'); ?>
                        </div>
                    <?php else : ?>
                        <ul class="ll-wordset-hidden-list">
                            <?php foreach ($hidden_categories as $hidden_cat) : ?>
                                <li class="ll-wordset-hidden-item" data-ll-hidden-row data-cat-id="<?php echo esc_attr((int) $hidden_cat['id']); ?>">
                                    <span class="ll-wordset-hidden-item__name"><?php echo esc_html($hidden_cat['name']); ?></span>
                                    <button type="button" class="ll-wordset-hidden-item__btn" data-ll-wordset-unhide data-cat-id="<?php echo esc_attr((int) $hidden_cat['id']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Unhide %s', 'll-tools-text-domain'), $hidden_cat['name'])); ?>">
                                        <?php echo ll_tools_wordset_page_render_unhide_icon('ll-wordset-unhide-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php elseif ($view === 'settings') : ?>
            <header class="ll-wordset-subpage-head">
                <a class="ll-wordset-back ll-vocab-lesson-back" href="<?php echo esc_url($back_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_term->name)); ?>">
                    <span class="ll-wordset-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                            <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="ll-wordset-back__label"><?php echo esc_html($wordset_term->name); ?></span>
                </a>
                <h1 class="ll-wordset-title"><?php echo esc_html__('Study Settings', 'll-tools-text-domain'); ?></h1>
            </header>
            <?php if (!$is_study_user) : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('Sign in to manage study settings.', 'll-tools-text-domain'); ?>
                </div>
            <?php else : ?>
                <section class="ll-wordset-settings-page" data-ll-wordset-settings-page>
                    <div class="ll-wordset-settings-card">
                        <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Study priorities', 'll-tools-text-domain'); ?></h2>
                        <div class="ll-wordset-settings-card__group">
                            <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Select one focus (optional)', 'll-tools-text-domain'); ?></h3>
                            <div class="ll-wordset-settings-card__options ll-wordset-settings-card__options--priority" role="group" aria-label="<?php echo esc_attr__('Study priority focus', 'll-tools-text-domain'); ?>">
                                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option ll-wordset-priority-option ll-wordset-priority-option--new" data-ll-wordset-priority-focus="new">
                                    <?php echo ll_tools_wordset_page_render_priority_focus_icon('new', 'll-wordset-priority-option__icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-wordset-priority-option__label"><?php echo esc_html__('New words', 'll-tools-text-domain'); ?></span>
                                </button>
                                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option ll-wordset-priority-option ll-wordset-priority-option--studied" data-ll-wordset-priority-focus="studied">
                                    <?php echo ll_tools_wordset_page_render_priority_focus_icon('studied', 'll-wordset-priority-option__icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-wordset-priority-option__label"><?php echo esc_html__('In progress words', 'll-tools-text-domain'); ?></span>
                                </button>
                                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option ll-wordset-priority-option ll-wordset-priority-option--learned" data-ll-wordset-priority-focus="learned">
                                    <?php echo ll_tools_wordset_page_render_priority_focus_icon('learned', 'll-wordset-priority-option__icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-wordset-priority-option__label"><?php echo esc_html__('Learned words', 'll-tools-text-domain'); ?></span>
                                </button>
                                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option ll-wordset-priority-option ll-wordset-priority-option--starred" data-ll-wordset-priority-focus="starred">
                                    <?php echo ll_tools_wordset_page_render_priority_focus_icon('starred', 'll-wordset-priority-option__icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-wordset-priority-option__label"><?php echo esc_html__('Starred words', 'll-tools-text-domain'); ?></span>
                                </button>
                                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option ll-wordset-priority-option ll-wordset-priority-option--hard" data-ll-wordset-priority-focus="hard">
                                    <?php echo ll_tools_wordset_page_render_priority_focus_icon('hard', 'll-wordset-priority-option__icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-wordset-priority-option__label"><?php echo esc_html__('Hard words', 'll-tools-text-domain'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="ll-wordset-settings-card">
                        <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Transition speed', 'll-tools-text-domain'); ?></h2>
                        <div class="ll-wordset-settings-card__options" role="group" aria-label="<?php echo esc_attr__('Transition speed', 'll-tools-text-domain'); ?>">
                            <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option" data-ll-wordset-transition="slow"><?php echo esc_html__('Standard', 'll-tools-text-domain'); ?></button>
                            <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option" data-ll-wordset-transition="fast"><?php echo esc_html__('Faster', 'll-tools-text-domain'); ?></button>
                        </div>
                    </div>

                    <div class="ll-wordset-settings-card">
                        <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Learning goals', 'll-tools-text-domain'); ?></h2>
                        <div class="ll-wordset-settings-card__options" role="group" aria-label="<?php echo esc_attr__('Enabled modes', 'll-tools-text-domain'); ?>">
                            <?php
                            $goal_modes = ['learning', 'practice', 'listening'];
                            if ($gender_enabled) {
                                $goal_modes[] = 'gender';
                            }
                            $goal_modes[] = 'self-check';
                            foreach ($goal_modes as $mode) :
                            ?>
                                <button
                                    type="button"
                                    class="ll-study-btn ll-vocab-lesson-mode-button ll-tools-settings-option"
                                    data-ll-wordset-goal-mode="<?php echo esc_attr($mode); ?>"
                                    aria-pressed="false">
                                    <?php echo ll_tools_wordset_page_render_mode_icon($mode, $mode_ui, $mode_fallback_icons[$mode] ?? '❓'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_labels[$mode] ?? ucfirst($mode)); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ll-wordset-settings-card">
                        <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Upcoming activities', 'll-tools-text-domain'); ?></h2>
                        <?php
                        $queue_category_lookup = [];
                        foreach ((array) $enhanced_categories as $queue_cat) {
                            if (!is_array($queue_cat)) {
                                continue;
                            }
                            $queue_cid = isset($queue_cat['id']) ? (int) $queue_cat['id'] : 0;
                            if ($queue_cid <= 0) {
                                continue;
                            }
                            $queue_label = isset($queue_cat['translation']) && (string) $queue_cat['translation'] !== ''
                                ? (string) $queue_cat['translation']
                                : (string) ($queue_cat['name'] ?? '');
                            $queue_preview = [];
                            foreach (array_slice((array) ($queue_cat['preview'] ?? []), 0, 2) as $queue_preview_item) {
                                if (!is_array($queue_preview_item)) {
                                    continue;
                                }
                                $queue_preview_type = ((string) ($queue_preview_item['type'] ?? '') === 'image' && !empty($queue_preview_item['url']))
                                    ? 'image'
                                    : 'text';
                                if ($queue_preview_type === 'image') {
                                    $queue_preview[] = [
                                        'type' => 'image',
                                        'url' => (string) ($queue_preview_item['url'] ?? ''),
                                        'alt' => (string) ($queue_preview_item['alt'] ?? ''),
                                    ];
                                    continue;
                                }
                                $queue_preview_label = trim((string) ($queue_preview_item['label'] ?? ''));
                                if ($queue_preview_label === '') {
                                    continue;
                                }
                                $queue_preview[] = [
                                    'type' => 'text',
                                    'label' => $queue_preview_label,
                                ];
                            }
                            $queue_category_lookup[$queue_cid] = [
                                'label' => $queue_label,
                                'preview' => $queue_preview,
                            ];
                        }
                        $priority_focus_labels = [
                            'new' => __('New words', 'll-tools-text-domain'),
                            'studied' => __('In progress words', 'll-tools-text-domain'),
                            'learned' => __('Learned words', 'll-tools-text-domain'),
                            'starred' => __('Starred words', 'll-tools-text-domain'),
                            'hard' => __('Hard words', 'll-tools-text-domain'),
                        ];
                        ?>
                        <ul class="ll-wordset-queue-list" data-ll-wordset-queue-list>
                            <?php foreach ((array) $recommendation_queue as $queue_item) : ?>
                                <?php
                                if (!is_array($queue_item)) {
                                    continue;
                                }
                                $queue_mode = isset($queue_item['mode']) ? (string) $queue_item['mode'] : 'practice';
                                $queue_mode = in_array($queue_mode, ['learning', 'practice', 'listening', 'gender', 'self-check'], true) ? $queue_mode : 'practice';
                                $queue_mode_label = $mode_labels[$queue_mode] ?? ucfirst($queue_mode);
                                $queue_ids = array_values(array_filter(array_map('intval', (array) ($queue_item['category_ids'] ?? [])), function ($id) {
                                    return $id > 0;
                                }));
                                $queue_category_labels = [];
                                $queue_preview_items = [];
                                $queue_preview_seen = [];
                                foreach ($queue_ids as $queue_cid) {
                                    if (isset($queue_category_lookup[$queue_cid]) && is_array($queue_category_lookup[$queue_cid])) {
                                        $queue_meta = $queue_category_lookup[$queue_cid];
                                        $queue_label = (string) ($queue_meta['label'] ?? '');
                                        if ($queue_label !== '') {
                                            $queue_category_labels[] = $queue_label;
                                        }
                                        foreach ((array) ($queue_meta['preview'] ?? []) as $queue_preview_item) {
                                            if (count($queue_preview_items) >= 2 || !is_array($queue_preview_item)) {
                                                continue;
                                            }
                                            $queue_preview_type = ((string) ($queue_preview_item['type'] ?? '') === 'image' && !empty($queue_preview_item['url']))
                                                ? 'image'
                                                : 'text';
                                            if ($queue_preview_type === 'image') {
                                                $queue_preview_url = (string) ($queue_preview_item['url'] ?? '');
                                                if ($queue_preview_url === '') {
                                                    continue;
                                                }
                                                $queue_preview_key = 'img:' . $queue_preview_url;
                                                if (isset($queue_preview_seen[$queue_preview_key])) {
                                                    continue;
                                                }
                                                $queue_preview_seen[$queue_preview_key] = true;
                                                $queue_preview_items[] = [
                                                    'type' => 'image',
                                                    'url' => $queue_preview_url,
                                                    'alt' => (string) ($queue_preview_item['alt'] ?? ''),
                                                ];
                                                continue;
                                            }
                                            $queue_preview_label = trim((string) ($queue_preview_item['label'] ?? ''));
                                            if ($queue_preview_label === '') {
                                                continue;
                                            }
                                            $queue_preview_key = 'txt:' . $queue_preview_label;
                                            if (isset($queue_preview_seen[$queue_preview_key])) {
                                                continue;
                                            }
                                            $queue_preview_seen[$queue_preview_key] = true;
                                            $queue_preview_items[] = [
                                                'type' => 'text',
                                                'label' => $queue_preview_label,
                                            ];
                                        }
                                    }
                                }
                                $queue_details = isset($queue_item['details']) && is_array($queue_item['details'])
                                    ? $queue_item['details']
                                    : [];
                                $queue_focus_key = sanitize_key((string) ($queue_details['priority_focus'] ?? ''));
                                if ($queue_focus_key !== '' && isset($priority_focus_labels[$queue_focus_key])) {
                                    $queue_category_text = (string) $priority_focus_labels[$queue_focus_key];
                                } else {
                                    $queue_category_text = !empty($queue_category_labels)
                                        ? implode(', ', $queue_category_labels)
                                        : esc_html__('Categories', 'll-tools-text-domain');
                                }
                                $queue_word_count = count(array_values(array_filter(array_map('intval', (array) ($queue_item['session_word_ids'] ?? [])), function ($id) {
                                    return $id > 0;
                                })));
                                $queue_id = isset($queue_item['queue_id']) ? sanitize_key((string) $queue_item['queue_id']) : '';
                                if ($queue_id === '') {
                                    continue;
                                }
                                ?>
                                <li class="ll-wordset-queue-item" data-ll-wordset-queue-item data-queue-id="<?php echo esc_attr($queue_id); ?>">
                                    <span class="ll-wordset-queue-item__mode ll-wordset-card__quiz-btn">
                                        <?php echo ll_tools_wordset_page_render_mode_icon($queue_mode, $mode_ui, $mode_fallback_icons[$queue_mode] ?? '❓', 'll-wordset-card__quiz-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="ll-wordset-queue-item__preview" aria-hidden="true">
                                        <?php for ($queue_preview_idx = 0; $queue_preview_idx < 2; $queue_preview_idx++) : ?>
                                            <?php $queue_preview_item = isset($queue_preview_items[$queue_preview_idx]) && is_array($queue_preview_items[$queue_preview_idx]) ? $queue_preview_items[$queue_preview_idx] : null; ?>
                                            <?php if ($queue_preview_item && ($queue_preview_item['type'] ?? '') === 'image' && !empty($queue_preview_item['url'])) : ?>
                                                <span class="ll-wordset-queue-thumb ll-wordset-queue-thumb--image">
                                                    <img src="<?php echo esc_url($queue_preview_item['url']); ?>" alt="" loading="lazy" />
                                                </span>
                                            <?php elseif ($queue_preview_item && ($queue_preview_item['type'] ?? '') === 'text' && !empty($queue_preview_item['label'])) : ?>
                                                <span class="ll-wordset-queue-thumb ll-wordset-queue-thumb--text">
                                                    <span class="ll-wordset-queue-thumb__text"><?php echo esc_html((string) $queue_preview_item['label']); ?></span>
                                                </span>
                                            <?php else : ?>
                                                <span class="ll-wordset-queue-thumb ll-wordset-queue-thumb--empty"></span>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </span>
                                    <span class="ll-wordset-queue-item__text">
                                        <span class="ll-wordset-queue-item__line"><?php echo esc_html($queue_mode_label); ?></span>
                                        <span class="ll-wordset-queue-item__line ll-wordset-queue-item__line--muted"><?php echo esc_html($queue_category_text); ?></span>
                                    </span>
                                    <?php if ($queue_word_count > 0) : ?>
                                        <span class="ll-wordset-queue-item__count"><?php echo (int) $queue_word_count; ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="ll-wordset-queue-item__remove" data-ll-wordset-queue-remove data-queue-id="<?php echo esc_attr($queue_id); ?>" aria-label="<?php echo esc_attr__('Remove activity', 'll-tools-text-domain'); ?>">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="ll-wordset-settings-empty" data-ll-wordset-queue-empty <?php if (!empty($recommendation_queue)) : ?>hidden<?php endif; ?>>
                            <?php echo esc_html__('No upcoming activities yet.', 'll-tools-text-domain'); ?>
                        </p>
                    </div>
                </section>
            <?php endif; ?>
        <?php else : ?>
            <?php if ($show_title) : ?>
                <header class="ll-wordset-hero">
                    <div class="ll-wordset-hero__title-wrap">
                        <div class="ll-wordset-hero__icon" aria-hidden="true">
                            <span class="ll-wordset-hero__dot"></span>
                            <span class="ll-wordset-hero__dot"></span>
                            <span class="ll-wordset-hero__dot"></span>
                            <span class="ll-wordset-hero__dot"></span>
                        </div>
                        <h1 class="ll-wordset-title"><?php echo esc_html($wordset_term->name); ?></h1>
                    </div>
                    <div class="ll-wordset-hero__tools">
                        <a
                            class="ll-wordset-link-chip ll-wordset-link-chip--hidden"
                            data-ll-wordset-hidden-link
                            href="<?php echo esc_url($hidden_categories_url); ?>"
                            aria-label="<?php echo esc_attr(sprintf(__('Hidden categories: %d', 'll-tools-text-domain'), $hidden_category_count)); ?>"
                            <?php if ($hidden_category_count < 1) : ?>hidden<?php endif; ?>>
                            <span class="ll-wordset-link-chip__icon" aria-hidden="true">
                                <?php echo ll_tools_wordset_page_render_hide_icon('ll-wordset-hidden-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                            <span class="ll-wordset-link-chip__count" data-ll-wordset-hidden-count><?php echo (int) $hidden_category_count; ?></span>
                        </a>
                        <a class="ll-wordset-progress-mini" data-ll-wordset-progress-mini-root href="<?php echo esc_url($progress_url); ?>" aria-label="<?php echo esc_attr__('Open progress', 'll-tools-text-domain'); ?>">
                            <span class="ll-wordset-progress-pill ll-wordset-progress-pill--mastered">
                                <?php echo ll_tools_wordset_page_render_progress_icon('mastered'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-mastered><?php echo (int) $summary_counts['mastered']; ?></span>
                            </span>
                            <span class="ll-wordset-progress-pill ll-wordset-progress-pill--studied">
                                <?php echo ll_tools_wordset_page_render_progress_icon('studied'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-studied><?php echo (int) $summary_counts['studied']; ?></span>
                            </span>
                            <span class="ll-wordset-progress-pill ll-wordset-progress-pill--new">
                                <?php echo ll_tools_wordset_page_render_progress_icon('new'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-new><?php echo (int) $summary_counts['new']; ?></span>
                            </span>
                            <span class="ll-wordset-progress-pill ll-wordset-progress-pill--starred">
                                <?php echo ll_tools_wordset_page_render_progress_icon('starred'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-starred><?php echo (int) $summary_counts['starred']; ?></span>
                            </span>
                            <span class="ll-wordset-progress-pill ll-wordset-progress-pill--hard">
                                <?php echo ll_tools_wordset_page_render_progress_icon('hard'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-hard><?php echo (int) $summary_counts['hard']; ?></span>
                            </span>
                        </a>
                        <?php if ($is_study_user) : ?>
                            <a class="ll-wordset-settings-link ll-tools-settings-button" href="<?php echo esc_url($settings_url); ?>" aria-label="<?php echo esc_attr__('Study settings', 'll-tools-text-domain'); ?>">
                                <span class="mode-icon" aria-hidden="true">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                            </a>
                        <?php endif; ?>
                    </div>
                </header>
            <?php endif; ?>

            <section class="ll-wordset-top-actions">
                <div class="ll-wordset-mode-buttons" role="group" aria-label="<?php echo esc_attr__('Quiz modes', 'll-tools-text-domain'); ?>">
                    <?php
                    $top_modes = ['learning', 'practice', 'listening'];
                    if ($gender_mode_available) {
                        $top_modes[] = 'gender';
                    }
                    $top_modes[] = 'self-check';
                    foreach ($top_modes as $mode) :
                    ?>
                        <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button" data-ll-wordset-start-mode data-mode="<?php echo esc_attr($mode); ?>">
                            <?php echo ll_tools_wordset_page_render_mode_icon($mode, $mode_ui, $mode_fallback_icons[$mode] ?? '❓'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_labels[$mode] ?? ucfirst($mode)); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="ll-wordset-next-wrap">
                    <p class="ll-wordset-next-heading"><?php echo esc_html__('Recommended:', 'll-tools-text-domain'); ?></p>
                    <div class="ll-wordset-next-shell" data-ll-wordset-next-shell>
                        <button type="button" class="ll-wordset-next-card" data-ll-wordset-next aria-live="polite">
                            <span class="ll-wordset-next-card__main">
                                <span class="ll-wordset-next-card__icon" data-ll-wordset-next-icon aria-hidden="true"></span>
                                <span class="ll-wordset-next-card__preview" data-ll-wordset-next-preview aria-hidden="true"></span>
                                <span class="ll-wordset-next-card__text" data-ll-wordset-next-text><?php echo esc_html__('No recommendation yet. Do one round first.', 'll-tools-text-domain'); ?></span>
                            </span>
                        </button>
                        <span class="ll-wordset-next-card__meta">
                            <span class="ll-wordset-queue-item__count ll-wordset-next-card__count" data-ll-wordset-next-count hidden></span>
                            <button type="button" class="ll-wordset-queue-item__remove ll-wordset-next-remove" data-ll-wordset-next-remove hidden aria-label="<?php echo esc_attr__('Remove recommendation', 'll-tools-text-domain'); ?>">
                                <span aria-hidden="true">×</span>
                            </button>
                        </span>
                    </div>
                </div>
            </section>

            <?php if (empty($visible_categories)) : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('No lesson categories yet.', 'll-tools-text-domain'); ?>
                </div>
            <?php else : ?>
                <?php if ($visible_category_count > 1) : ?>
                    <div class="ll-wordset-grid-tools">
                        <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-select-all aria-pressed="false">
                            <?php echo esc_html__('Select all', 'll-tools-text-domain'); ?>
                        </button>
                    </div>
                <?php endif; ?>
                <div class="ll-wordset-grid" role="list">
                    <?php foreach ($visible_categories as $cat) : ?>
                        <?php
                        $cat_id = (int) ($cat['id'] ?? 0);
                        $preview_style = '';
                        if (!empty($cat['preview_aspect_ratio'])) {
                            $preview_style = ' style="--ll-wordset-preview-aspect: ' . esc_attr($cat['preview_aspect_ratio']) . ';"';
                        }
                        $card_word_total = max(0, (int) ($cat['count'] ?? 0));
                        $card_progress = $category_progress_lookup[$cat_id] ?? [
                            'total' => $card_word_total,
                            'mastered' => 0,
                            'studied' => 0,
                            'new' => $card_word_total,
                        ];
                        $card_progress_total = max(1, (int) ($card_progress['total'] ?? $card_word_total));
                        $card_mastered_pct = max(0, min(100, (($card_progress['mastered'] ?? 0) * 100) / $card_progress_total));
                        $card_studied_pct = max(0, min(100 - $card_mastered_pct, (($card_progress['studied'] ?? 0) * 100) / $card_progress_total));
                        $card_new_pct = max(0, 100 - $card_mastered_pct - $card_studied_pct);
                        $card_starred_count = max(0, (int) ($category_starred_lookup[$cat_id] ?? 0));
                        ?>
                        <article class="ll-wordset-card" role="listitem" data-cat-id="<?php echo esc_attr($cat_id); ?>" data-word-count="<?php echo esc_attr((int) ($cat['count'] ?? 0)); ?>">
                            <div class="ll-wordset-card__top">
                                <label class="ll-wordset-card__select" aria-label="<?php echo esc_attr(sprintf(__('Select %s', 'll-tools-text-domain'), $cat['name'])); ?>">
                                    <input type="checkbox" value="<?php echo esc_attr($cat_id); ?>" data-ll-wordset-select />
                                    <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
                                </label>
                                <a class="ll-wordset-card__heading" href="<?php echo esc_url($cat['url']); ?>" aria-label="<?php echo esc_attr($cat['name']); ?>">
                                    <h2 class="ll-wordset-card__title"><?php echo esc_html($cat['name']); ?></h2>
                                </a>
                                <?php if ($is_study_user) : ?>
                                    <button type="button" class="ll-wordset-card__hide" data-ll-wordset-hide data-cat-id="<?php echo esc_attr($cat_id); ?>" aria-label="<?php echo esc_attr(sprintf(__('Hide %s', 'll-tools-text-domain'), $cat['name'])); ?>">
                                        <?php echo ll_tools_wordset_page_render_hide_icon('ll-wordset-hide-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </button>
                                <?php else : ?>
                                    <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
                                <?php endif; ?>
                            </div>
                            <a class="ll-wordset-card__lesson-link" href="<?php echo esc_url($cat['url']); ?>" aria-label="<?php echo esc_attr($cat['name']); ?>">
                                <div class="ll-wordset-card__preview <?php echo $cat['has_images'] ? 'has-images' : 'has-text'; ?>"<?php echo $preview_style; ?>>
                                    <?php if ($card_starred_count > 0) : ?>
                                        <span
                                            class="ll-wordset-card__starred-pill ll-wordset-progress-pill ll-wordset-progress-pill--starred"
                                            aria-label="<?php echo esc_attr(sprintf(_n('%1$d starred word in %2$s', '%1$d starred words in %2$s', $card_starred_count, 'll-tools-text-domain'), $card_starred_count, $cat['name'])); ?>">
                                            <?php echo ll_tools_wordset_page_render_progress_icon('starred', 'll-wordset-progress-pill__icon ll-wordset-card__starred-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            <span class="ll-wordset-progress-pill__value ll-wordset-card__starred-value"><?php echo (int) $card_starred_count; ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($cat['preview'])) : ?>
                                        <?php
                                        $preview_items = array_values((array) $cat['preview']);
                                        $preview_count = count($preview_items);
                                        $current_preview_limit = isset($cat['preview_limit']) ? (int) $cat['preview_limit'] : 2;
                                        if ($current_preview_limit < 1) {
                                            $current_preview_limit = 1;
                                        }
                                        $displayed_count = min($preview_count, $current_preview_limit);
                                        ?>
                                        <?php foreach (array_slice($preview_items, 0, $current_preview_limit) as $preview) : ?>
                                            <?php if (($preview['type'] ?? '') === 'image') : ?>
                                                <?php
                                                $preview_ratio_style = '';
                                                if (!empty($preview['ratio'])) {
                                                    $ratio_value = esc_attr($preview['ratio']);
                                                    $preview_ratio_style = ' style="aspect-ratio: ' . $ratio_value . ' !important;"';
                                                }
                                                $preview_width = !empty($preview['width']) ? (int) $preview['width'] : 0;
                                                $preview_height = !empty($preview['height']) ? (int) $preview['height'] : 0;
                                                $preview_width_attr = $preview_width > 0 ? ' width="' . esc_attr($preview_width) . '"' : '';
                                                $preview_height_attr = $preview_height > 0 ? ' height="' . esc_attr($preview_height) . '"' : '';
                                                ?>
                                                <span class="ll-wordset-preview-item ll-wordset-preview-item--image"<?php echo $preview_ratio_style; ?>>
                                                    <img src="<?php echo esc_url($preview['url']); ?>" alt="<?php echo esc_attr($preview['alt'] ?? ''); ?>"<?php echo $preview_width_attr . $preview_height_attr; ?> loading="lazy" />
                                                </span>
                                            <?php else : ?>
                                                <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                                                    <span class="ll-wordset-preview-text"><?php echo esc_html($preview['label'] ?? ''); ?></span>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php for ($i = $displayed_count; $i < $current_preview_limit; $i++) : ?>
                                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                                        <?php endfor; ?>
                                    <?php else : ?>
                                        <?php for ($i = 0; $i < 2; $i++) : ?>
                                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="ll-wordset-card__progress" aria-hidden="true">
                                <span class="ll-wordset-card__progress-track">
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered" style="width: <?php echo esc_attr(number_format((float) $card_mastered_pct, 2, '.', '')); ?>%;"></span>
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied" style="width: <?php echo esc_attr(number_format((float) $card_studied_pct, 2, '.', '')); ?>%;"></span>
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: <?php echo esc_attr(number_format((float) $card_new_pct, 2, '.', '')); ?>%;"></span>
                                </span>
                            </div>
                            <div class="ll-wordset-card__quiz-actions" role="group" aria-label="<?php echo esc_attr(sprintf(__('Quiz modes for %s', 'll-tools-text-domain'), $cat['name'])); ?>">
                                <?php
                                $card_modes = ['learning', 'practice', 'listening'];
                                if ($gender_enabled && !empty($cat['gender_supported'])) {
                                    $card_modes[] = 'gender';
                                }
                                $card_modes[] = 'self-check';
                                foreach ($card_modes as $mode) :
                                ?>
                                    <button type="button" class="ll-wordset-card__quiz-btn" data-ll-wordset-category-mode data-mode="<?php echo esc_attr($mode); ?>" data-cat-id="<?php echo esc_attr($cat_id); ?>" aria-label="<?php echo esc_attr(sprintf(__('%1$s: %2$s', 'll-tools-text-domain'), $mode_labels[$mode] ?? ucfirst($mode), $cat['name'])); ?>">
                                        <?php echo ll_tools_wordset_page_render_mode_icon($mode, $mode_ui, $mode_fallback_icons[$mode] ?? '❓', 'll-wordset-card__quiz-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ll-wordset-selection-bar" data-ll-wordset-selection-bar hidden>
                <span class="ll-wordset-selection-bar__text" data-ll-wordset-selection-text><?php echo esc_html__('Select categories to study together', 'll-tools-text-domain'); ?></span>
                <label class="ll-wordset-selection-bar__starred-toggle" aria-label="<?php echo esc_attr__('Starred only', 'll-tools-text-domain'); ?>" hidden>
                    <input type="checkbox" data-ll-wordset-selection-starred-only />
                    <span class="ll-wordset-selection-bar__starred-icon" data-ll-wordset-selection-starred-icon aria-hidden="true">☆</span>
                    <span class="ll-wordset-selection-bar__starred-text" data-ll-wordset-selection-starred-label><?php echo esc_html__('Starred only', 'll-tools-text-domain'); ?></span>
                </label>
                <label class="ll-wordset-selection-bar__hard-toggle" aria-label="<?php echo esc_attr__('Hard words only', 'll-tools-text-domain'); ?>" hidden>
                    <input type="checkbox" data-ll-wordset-selection-hard-only />
                    <span class="ll-wordset-selection-bar__hard-icon" data-ll-wordset-selection-hard-icon aria-hidden="true"><?php echo ll_tools_wordset_page_render_hard_words_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="ll-wordset-selection-bar__hard-text" data-ll-wordset-selection-hard-label><?php echo esc_html__('Hard words only', 'll-tools-text-domain'); ?></span>
                </label>
                <div class="ll-wordset-selection-bar__actions">
                    <?php
                    $selection_modes = ['learning', 'practice', 'listening'];
                    if ($gender_mode_available) {
                        $selection_modes[] = 'gender';
                    }
                    $selection_modes[] = 'self-check';
                    foreach ($selection_modes as $mode) :
                    ?>
                        <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-wordset-selection-mode data-mode="<?php echo esc_attr($mode); ?>">
                            <?php echo ll_tools_wordset_page_render_mode_icon($mode, $mode_ui, $mode_fallback_icons[$mode] ?? '❓'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_labels[$mode] ?? ucfirst($mode)); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="ll-wordset-selection-bar__clear" data-ll-wordset-selection-clear aria-label="<?php echo esc_attr__('Clear selection', 'll-tools-text-domain'); ?>">
                    <span class="ll-wordset-selection-bar__clear-icon" aria-hidden="true">x</span>
                </button>
            </div>

            <div class="ll-wordset-flashcard-host" data-ll-wordset-flashcard-host>
                <?php
                echo do_shortcode(sprintf(
                    '[flashcard_widget embed="false" wordset="%s" wordset_fallback="false" quiz_mode="practice" launch_context="dashboard"]',
                    esc_attr($wordset_term->slug)
                ));
                ?>
            </div>
        <?php endif; ?>
    </<?php echo esc_html($wrapper_tag); ?>>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_register_wordset_page_query_vars($vars) {
    $vars[] = 'll_wordset_page';
    $vars[] = 'll_wordset_view';
    return $vars;
}
add_filter('query_vars', 'll_tools_register_wordset_page_query_vars');

function ll_tools_register_wordset_page_rewrite_rules() {
    if (!function_exists('ll_tools_get_vocab_lesson_wordset_ids')) {
        return;
    }
    $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    if (empty($wordset_ids)) {
        if (get_transient('ll_tools_vocab_lesson_flush_rewrite')) {
            flush_rewrite_rules(false);
            delete_transient('ll_tools_vocab_lesson_flush_rewrite');
        }
        return;
    }

    foreach ($wordset_ids as $wordset_id) {
        $term = get_term((int) $wordset_id, 'wordset');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $slug = sanitize_title($term->slug);
        if ($slug === '') {
            continue;
        }

        $conflict = get_page_by_path($slug, OBJECT, 'page');
        $allow = (bool) apply_filters('ll_tools_wordset_page_allow_conflict', !$conflict, $slug, $term);
        if (!$allow) {
            continue;
        }

        $base_pattern = '^' . preg_quote($slug, '/') . '/?$';
        $base_target  = 'index.php?ll_wordset_page=' . $slug;
        add_rewrite_rule($base_pattern, $base_target, 'top');

        $progress_pattern = '^' . preg_quote($slug, '/') . '/progress/?$';
        $progress_target = 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress';
        add_rewrite_rule($progress_pattern, $progress_target, 'top');

        $hidden_pattern = '^' . preg_quote($slug, '/') . '/hidden-categories/?$';
        $hidden_target = 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories';
        add_rewrite_rule($hidden_pattern, $hidden_target, 'top');

        $settings_pattern = '^' . preg_quote($slug, '/') . '/settings/?$';
        $settings_target = 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings';
        add_rewrite_rule($settings_pattern, $settings_target, 'top');
    }

    if (get_transient('ll_tools_vocab_lesson_flush_rewrite')) {
        flush_rewrite_rules(false);
        delete_transient('ll_tools_vocab_lesson_flush_rewrite');
    }
}
add_action('init', 'll_tools_register_wordset_page_rewrite_rules', 21);

add_filter('redirect_canonical', function ($redirect_url) {
    if (ll_tools_is_wordset_page_context()) {
        return false;
    }
    return $redirect_url;
});

function ll_tools_wordset_page_template_include($template) {
    if (!ll_tools_is_wordset_page_context()) {
        return $template;
    }
    if (!function_exists('ll_tools_locate_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }
    $located = ll_tools_locate_template('wordset-page-template.php');
    if ($located !== '') {
        return $located;
    }
    return $template;
}
add_filter('template_include', 'll_tools_wordset_page_template_include', 20);

function ll_tools_wordset_page_enqueue_assets() {
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }
    ll_tools_wordset_page_enqueue_styles();
    ll_tools_wordset_page_enqueue_scripts();
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_page_enqueue_assets');

function ll_tools_wordset_page_enqueue_styles(): void {
    ll_enqueue_asset_by_timestamp('/css/wordset-pages.css', 'll-wordset-pages-css');
}
