<?php
// /includes/pages/wordset-pages.php
if (!defined('WPINC')) { die; }

/**
 * Wordset landing pages:
 * - URL pattern: /{wordset}/
 * - Shows a grid of vocab lesson links for that wordset.
 */

function ll_tools_is_wordset_page_context(): bool {
    return (bool) get_query_var('ll_wordset_page');
}

function ll_tools_get_wordset_page_term() {
    $slug = (string) get_query_var('ll_wordset_page');
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return null;
    }
    $term = get_term_by('slug', $slug, 'wordset');
    return ($term && !is_wp_error($term)) ? $term : null;
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
                $image_url = function_exists('ll_tools_get_masked_image_url')
                    ? ll_tools_get_masked_image_url($image_id, $image_size)
                    : '';
                if ($image_url === '') {
                    $image_url = wp_get_attachment_image_url($image_id, $image_size) ?: '';
                }
                if ($image_url === '') {
                    continue;
                }
                $items[] = [
                    'type' => 'image',
                    'url'  => $image_url,
                    'alt'  => get_the_title($word_id),
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
            'name'       => $display_name,
            'count'      => (int) ($row['word_count'] ?? 0),
            'preview'    => $preview['items'],
            'has_images' => (bool) $preview['has_images'],
            'preview_limit' => $preview_limit_for_category,
            'url'        => get_permalink($lesson_post_id),
        ];
    }

    return apply_filters('ll_tools_wordset_page_categories', $items, $wordset_id);
}

function ll_tools_register_wordset_page_query_vars($vars) {
    $vars[] = 'll_wordset_page';
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

        $pattern = '^' . preg_quote($slug, '/') . '/?$';
        $target  = 'index.php?ll_wordset_page=' . $slug;
        add_rewrite_rule($pattern, $target, 'top');
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
    ll_enqueue_asset_by_timestamp('/css/wordset-pages.css', 'll-wordset-pages-css');
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_page_enqueue_assets');
