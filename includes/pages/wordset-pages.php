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

    $ordering_sig = function_exists('ll_tools_wordset_get_category_ordering_cache_signature')
        ? ll_tools_wordset_get_category_ordering_cache_signature($wordset_id)
        : 'none';
    $translation_enabled = function_exists('ll_tools_is_category_translation_enabled')
        ? (ll_tools_is_category_translation_enabled([$wordset_id]) ? '1' : '0')
        : ((bool) get_option('ll_enable_category_translation', 0) ? '1' : '0');
    $translation_target = function_exists('ll_tools_get_wordset_translation_language')
        ? sanitize_key((string) ll_tools_get_wordset_translation_language([$wordset_id]))
        : sanitize_key((string) get_option('ll_translation_language', ''));
    $label_locale_sig = sanitize_key((string) get_locale());
    $cache_context_sig = substr(md5($label_locale_sig . '|' . $translation_enabled . '|' . $translation_target), 0, 8);
    $cache_key = 'll_wordset_page_cats_' . $wordset_id . '_' . $min_words . '_' . $ordering_sig . '_' . $cache_context_sig;
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

    if (!empty($rows) && function_exists('ll_tools_wordset_sort_category_ids')) {
        $row_lookup = [];
        $row_ids = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['term_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $row_lookup[$cid] = $row;
            $row_ids[] = $cid;
        }
        $ordered_ids = ll_tools_wordset_sort_category_ids($row_ids, $wordset_id);
        $ordered_rows = [];
        foreach ($ordered_ids as $cid) {
            if (isset($row_lookup[$cid])) {
                $ordered_rows[] = $row_lookup[$cid];
            }
        }
        if (!empty($ordered_rows)) {
            $rows = $ordered_rows;
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

function ll_tools_get_wordset_preview_attachment_file_hash(int $attachment_id): string {
    static $cache = [];

    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }
    if (array_key_exists($attachment_id, $cache)) {
        return $cache[$attachment_id];
    }

    $path = get_attached_file($attachment_id);
    if (!is_string($path) || $path === '' || !is_readable($path)) {
        $cache[$attachment_id] = '';
        return '';
    }

    $hash = hash_file('sha1', $path);
    if (!is_string($hash) || $hash === '') {
        $cache[$attachment_id] = '';
        return '';
    }

    $cache[$attachment_id] = strtolower($hash);
    return $cache[$attachment_id];
}

function ll_tools_get_wordset_preview_attachment_visual_hash(int $attachment_id): string {
    static $cache = [];

    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }
    if (array_key_exists($attachment_id, $cache)) {
        return $cache[$attachment_id];
    }
    if (!function_exists('ll_tools_get_attachment_image_hash')) {
        $cache[$attachment_id] = '';
        return '';
    }

    $hash = strtolower(trim((string) ll_tools_get_attachment_image_hash($attachment_id)));
    if ($hash === '') {
        $cache[$attachment_id] = '';
        return '';
    }

    $cache[$attachment_id] = $hash;
    return $cache[$attachment_id];
}

function ll_tools_get_wordset_category_preview(int $wordset_id, int $category_id, int $limit = 2, ?bool $requires_images = null): array {
    $limit = max(1, (int) $limit);
    $items = [];
    $seen_preview_word_ids = [];
    $query_limit = max($limit * 3, $limit);
    $deepest_only = function_exists('ll_get_deepest_categories');
    $use_images = ($requires_images !== null) ? (bool) $requires_images : true;
    if ($requires_images === null && function_exists('ll_tools_vocab_lesson_category_requires_images')) {
        $use_images = ll_tools_vocab_lesson_category_requires_images($category_id);
    } elseif ($requires_images === null && function_exists('ll_tools_get_category_quiz_config')) {
        $config = ll_tools_get_category_quiz_config($category_id);
        $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
        $option_type = (string) ($config['option_type'] ?? '');
        $use_images = function_exists('ll_tools_quiz_requires_image')
            ? ll_tools_quiz_requires_image(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : (($prompt_type === 'image') || ($option_type === 'image'));
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

        $image_query_limit = min(50, max($query_limit, $limit * 8));
        $image_query = new WP_Query([
            'post_type'      => 'words',
            'post_status'    => 'publish',
            'posts_per_page' => $image_query_limit,
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
            $seen_preview_image_keys = [];
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

                $image_dedupe_keys = ['id:' . (int) $image_id, 'url:' . $image_url];
                $attached_file = trim((string) get_post_meta($image_id, '_wp_attached_file', true));
                if ($attached_file !== '') {
                    $image_dedupe_keys[] = 'file:' . wp_normalize_path($attached_file);
                }
                $file_hash = ll_tools_get_wordset_preview_attachment_file_hash($image_id);
                if ($file_hash !== '') {
                    $image_dedupe_keys[] = 'sha1:' . $file_hash;
                }
                $visual_hash = ll_tools_get_wordset_preview_attachment_visual_hash($image_id);
                if ($visual_hash !== '') {
                    $image_dedupe_keys[] = 'dhash:' . $visual_hash;
                }

                $is_duplicate_preview_image = false;
                foreach ($image_dedupe_keys as $dedupe_key) {
                    if ($dedupe_key !== '' && isset($seen_preview_image_keys[$dedupe_key])) {
                        $is_duplicate_preview_image = true;
                        break;
                    }
                }
                if ($is_duplicate_preview_image) {
                    continue;
                }
                foreach ($image_dedupe_keys as $dedupe_key) {
                    if ($dedupe_key !== '') {
                        $seen_preview_image_keys[$dedupe_key] = true;
                    }
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
                $item = [
                    'type' => 'image',
                    'url'  => $image_url,
                    'alt'  => get_the_title($word_id),
                    'ratio' => $ratio,
                    'width' => $width,
                    'height' => $height,
                ];
                $items[] = $item;
                $seen_preview_word_ids[(int) $word_id] = true;

                if (count($items) >= $limit) {
                    break;
                }
            }
        }
    }

    if (count($items) < $limit) {
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
            if (isset($seen_preview_word_ids[(int) $word_id])) {
                continue;
            }
            $label = get_the_title($word_id);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'type'  => 'text',
                'label' => $label,
            ];
            $seen_preview_word_ids[(int) $word_id] = true;
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

    $gender_support_map = [];
    if (
        function_exists('ll_tools_wordset_has_grammatical_gender')
        && ll_tools_wordset_has_grammatical_gender($wordset_id)
        && function_exists('ll_flashcards_build_categories')
    ) {
        $use_translations = function_exists('ll_flashcards_should_use_translations')
            ? ll_flashcards_should_use_translations([$wordset_id])
            : false;
        [$flashcard_categories] = ll_flashcards_build_categories('', $use_translations, [$wordset_id]);
        foreach ((array) $flashcard_categories as $flashcard_category) {
            if (!is_array($flashcard_category)) {
                continue;
            }
            $category_id = isset($flashcard_category['id']) ? (int) $flashcard_category['id'] : 0;
            if ($category_id <= 0) {
                continue;
            }
            $gender_support_map[$category_id] = !empty($flashcard_category['gender_supported']);
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $category = get_term((int) $row['term_id'], 'word-category');
        if (!$category || is_wp_error($category)) {
            continue;
        }
        if (function_exists('ll_tools_user_can_view_category') && !ll_tools_user_can_view_category($category)) {
            continue;
        }

        $lesson_post_id = (int) ($lesson_map[(int) $category->term_id] ?? 0);
        if ($lesson_post_id <= 0) {
            continue;
        }

        $display_name = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($category, ['wordset_ids' => [$wordset_id]])
            : $category->name;

        $quiz_config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($category)
            : [];
        $prompt_type = (string) ($quiz_config['prompt_type'] ?? 'audio');
        $option_type = (string) ($quiz_config['option_type'] ?? 'image');
        $learning_supported = !array_key_exists('learning_supported', $quiz_config) || !empty($quiz_config['learning_supported']);
        $aspect_bucket = function_exists('ll_tools_get_category_aspect_bucket_key')
            ? (string) ll_tools_get_category_aspect_bucket_key((int) $category->term_id)
            : '';
        if ($aspect_bucket === '') {
            $aspect_bucket = 'no-image';
        }

        $requires_images = true;
        if (function_exists('ll_tools_vocab_lesson_category_requires_images')) {
            $requires_images = ll_tools_vocab_lesson_category_requires_images($category);
        } else {
            $requires_images = function_exists('ll_tools_quiz_requires_image')
                ? ll_tools_quiz_requires_image(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
                : (($prompt_type === 'image') || ($option_type === 'image'));
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
            'mode'       => $option_type,
            'prompt_type' => $prompt_type,
            'option_type' => $option_type,
            'learning_supported' => $learning_supported,
            'gender_supported' => !empty($gender_support_map[(int) $category->term_id]),
            'aspect_bucket' => $aspect_bucket,
            'count'      => (int) ($row['word_count'] ?? 0),
            'preview'    => $preview['items'],
            'has_images' => (bool) $preview['has_images'],
            'preview_aspect_ratio' => $preview['preview_aspect_ratio'] ?? '',
            'preview_limit' => $preview_limit_for_category,
            'url'        => get_permalink($lesson_post_id),
        ];
    }

    return apply_filters('ll_tools_wordset_page_categories', $items, $wordset_id);
}

function ll_tools_wordset_page_normalize_search_text(string $value): string {
    $text = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return is_string($text) ? trim($text) : '';
}

function ll_tools_wordset_page_get_category_depth_cached(int $category_id): int {
    static $depth_cache = [];

    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return 0;
    }
    if (array_key_exists($category_id, $depth_cache)) {
        return $depth_cache[$category_id];
    }

    if (function_exists('ll_get_category_depth')) {
        $depth_cache[$category_id] = (int) ll_get_category_depth($category_id);
        return $depth_cache[$category_id];
    }

    $depth = 0;
    $seen = [];
    $current_id = $category_id;
    while ($current_id > 0 && empty($seen[$current_id])) {
        $seen[$current_id] = true;
        $parent_id = (int) get_term_field('parent', $current_id, 'word-category');
        if ($parent_id <= 0) {
            break;
        }
        $depth++;
        $current_id = $parent_id;
    }

    $depth_cache[$category_id] = $depth;
    return $depth;
}

function ll_tools_get_wordset_page_category_search_index(int $wordset_id, array $allowed_category_ids = []): array {
    global $wpdb;

    $wordset_id = (int) $wordset_id;
    $allowed_category_ids = array_values(array_filter(array_unique(array_map('intval', $allowed_category_ids)), static function ($id): bool {
        return $id > 0;
    }));
    if ($wordset_id <= 0 || empty($allowed_category_ids)) {
        return [];
    }

    $cache_key = 'll_wordset_page_cat_search_' . $wordset_id . '_' . md5(implode(',', $allowed_category_ids));
    $cached = wp_cache_get($cache_key, 'll_tools');
    if (is_array($cached)) {
        return $cached;
    }

    $posts_table = $wpdb->posts;
    $term_relationships_table = $wpdb->term_relationships;
    $term_taxonomy_table = $wpdb->term_taxonomy;
    $postmeta_table = $wpdb->postmeta;

    $sql = "
        SELECT
            posts.ID AS word_id,
            posts.post_title AS word_title,
            category_taxonomy.term_id AS category_id,
            translation_meta.meta_value AS translation_value,
            legacy_translation_meta.meta_value AS legacy_translation_value
        FROM {$posts_table} AS posts
        INNER JOIN {$term_relationships_table} AS wordset_relationships
            ON wordset_relationships.object_id = posts.ID
        INNER JOIN {$term_taxonomy_table} AS wordset_taxonomy
            ON wordset_taxonomy.term_taxonomy_id = wordset_relationships.term_taxonomy_id
            AND wordset_taxonomy.taxonomy = 'wordset'
        INNER JOIN {$term_relationships_table} AS category_relationships
            ON category_relationships.object_id = posts.ID
        INNER JOIN {$term_taxonomy_table} AS category_taxonomy
            ON category_taxonomy.term_taxonomy_id = category_relationships.term_taxonomy_id
            AND category_taxonomy.taxonomy = 'word-category'
        LEFT JOIN {$postmeta_table} AS translation_meta
            ON translation_meta.post_id = posts.ID
            AND translation_meta.meta_key = 'word_translation'
        LEFT JOIN {$postmeta_table} AS legacy_translation_meta
            ON legacy_translation_meta.post_id = posts.ID
            AND legacy_translation_meta.meta_key = 'word_english_meaning'
        WHERE posts.post_type = 'words'
            AND posts.post_status = 'publish'
            AND wordset_taxonomy.term_id = %d
        ORDER BY posts.ID ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $wordset_id), ARRAY_A);
    if (empty($rows)) {
        wp_cache_set($cache_key, [], 'll_tools', 10 * MINUTE_IN_SECONDS);
        return [];
    }

    $allowed_lookup = array_fill_keys($allowed_category_ids, true);
    $word_terms = [];
    $word_strings = [];
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $word_id = isset($row['word_id']) ? (int) $row['word_id'] : 0;
        $category_id = isset($row['category_id']) ? (int) $row['category_id'] : 0;
        if ($word_id <= 0 || $category_id <= 0) {
            continue;
        }

        $word_terms[$word_id][$category_id] = true;
        if (!isset($word_strings[$word_id])) {
            $word_title = ll_tools_wordset_page_normalize_search_text((string) ($row['word_title'] ?? ''));
            $translation = ll_tools_wordset_page_normalize_search_text((string) ($row['translation_value'] ?? ''));
            if ($translation === '') {
                $translation = ll_tools_wordset_page_normalize_search_text((string) ($row['legacy_translation_value'] ?? ''));
            }
            $word_strings[$word_id] = array_values(array_filter([$word_title, $translation], static function ($value): bool {
                return is_string($value) && $value !== '';
            }));
        }
    }

    $category_tokens = [];
    foreach ($word_terms as $word_id => $term_lookup) {
        $term_ids = array_values(array_filter(array_map('intval', array_keys((array) $term_lookup)), static function ($id): bool {
            return $id > 0;
        }));
        if (empty($term_ids)) {
            continue;
        }

        $deepest_term_ids = [];
        $max_depth = -1;
        foreach ($term_ids as $term_id) {
            $depth = ll_tools_wordset_page_get_category_depth_cached($term_id);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest_term_ids = [$term_id];
            } elseif ($depth === $max_depth) {
                $deepest_term_ids[] = $term_id;
            }
        }

        $search_terms = isset($word_strings[$word_id]) && is_array($word_strings[$word_id]) ? $word_strings[$word_id] : [];
        if (empty($search_terms)) {
            continue;
        }

        foreach ($deepest_term_ids as $term_id) {
            if (empty($allowed_lookup[$term_id])) {
                continue;
            }
            if (!isset($category_tokens[$term_id])) {
                $category_tokens[$term_id] = [];
            }
            foreach ($search_terms as $search_term) {
                $normalized_search_term = ll_tools_wordset_page_normalize_search_text((string) $search_term);
                if ($normalized_search_term === '') {
                    continue;
                }
                $category_tokens[$term_id][$normalized_search_term] = true;
            }
        }
    }

    $search_index = [];
    foreach ($allowed_category_ids as $category_id) {
        $token_map = isset($category_tokens[$category_id]) && is_array($category_tokens[$category_id])
            ? $category_tokens[$category_id]
            : [];
        $search_index[$category_id] = [
            'search_text' => implode("\n", array_keys($token_map)),
        ];
    }

    wp_cache_set($cache_key, $search_index, 'll_tools', 10 * MINUTE_IN_SECONDS);
    return $search_index;
}

function ll_tools_get_wordset_page_view(): string {
    $raw = ll_tools_get_requested_wordset_page_view_raw();
    $view = sanitize_key($raw);
    if (!in_array($view, ll_tools_get_wordset_page_allowed_views(), true)) {
        return '';
    }
    return $view;
}

function ll_tools_get_wordset_page_allowed_views(): array {
    return ['progress', 'hidden-categories', 'settings', 'games'];
}

function ll_tools_wordset_page_rewrite_rule_matches_target(string $target, array $expected_query_args): bool {
    $query_start = strpos($target, '?');
    if ($query_start === false) {
        return false;
    }

    $query = substr($target, $query_start + 1);
    if (!is_string($query) || $query === '') {
        return false;
    }

    $parsed = [];
    wp_parse_str($query, $parsed);
    if (empty($parsed) || !is_array($parsed)) {
        return false;
    }

    $expected_slug = sanitize_title((string) ($expected_query_args['ll_wordset_page'] ?? ''));
    if ($expected_slug === '') {
        return false;
    }

    $actual_slug = sanitize_title((string) ($parsed['ll_wordset_page'] ?? ''));
    if ($actual_slug !== $expected_slug) {
        return false;
    }

    $expected_view = sanitize_key((string) ($expected_query_args['ll_wordset_view'] ?? ''));
    $actual_view = sanitize_key((string) ($parsed['ll_wordset_view'] ?? ''));

    return $actual_view === $expected_view;
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
        '^' . $quoted . '/?$' => [
            'll_wordset_page' => $slug,
        ],
        '^' . $quoted . '/progress/?$' => [
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'progress',
        ],
        '^' . $quoted . '/hidden-categories/?$' => [
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'hidden-categories',
        ],
        '^' . $quoted . '/settings/?$' => [
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'settings',
        ],
        '^' . $quoted . '/games/?$' => [
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'games',
        ],
    ];

    foreach ($required_patterns as $pattern => $expected_query_args) {
        if (!array_key_exists($pattern, $rules)) {
            $cache[$slug] = false;
            return false;
        }

        $target = $rules[$pattern];
        if (!is_string($target) || !ll_tools_wordset_page_rewrite_rule_matches_target($target, $expected_query_args)) {
            $cache[$slug] = false;
            return false;
        }
    }

    $cache[$slug] = true;
    return true;
}

function ll_tools_wordset_page_get_pretty_view_url(WP_Term $wordset_term, string $view = ''): string {
    $slug = sanitize_title($wordset_term->slug ?? '');
    if ($slug === '') {
        return home_url('/');
    }

    $view = sanitize_key($view);
    if (!in_array($view, ll_tools_get_wordset_page_allowed_views(), true)) {
        $view = '';
    }

    $path = $slug;
    if ($view !== '') {
        $path .= '/' . $view;
    }

    return home_url(user_trailingslashit($path));
}

function ll_tools_get_wordset_page_view_url(WP_Term $wordset_term, string $view = ''): string {
    $slug = sanitize_title($wordset_term->slug ?? '');
    if ($slug === '') {
        return home_url('/');
    }

    $view = sanitize_key($view);
    $query_args = ['ll_wordset_page' => $slug];
    if ($view !== '' && in_array($view, ll_tools_get_wordset_page_allowed_views(), true)) {
        $query_args['ll_wordset_view'] = $view;
    }
    $query_fallback_url = add_query_arg($query_args, home_url('/'));

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

    return ll_tools_wordset_page_get_pretty_view_url($wordset_term, $view);
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

function ll_tools_wordset_page_get_query_request_redirect_url(): string {
    if (!ll_tools_is_wordset_page_context()) {
        return '';
    }

    $request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($request_method, ['GET', 'HEAD'], true)) {
        return '';
    }

    if (!isset($_GET['ll_wordset_page']) && !isset($_GET['ll_wordset_view'])) {
        return '';
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        return '';
    }

    $view = ll_tools_get_wordset_page_view();
    $redirect_url = ll_tools_get_wordset_page_view_url($wordset_term, $view);
    if ($redirect_url === '' || strpos($redirect_url, 'll_wordset_page=') !== false) {
        return '';
    }

    $query_args = isset($_GET) && is_array($_GET) ? wp_unslash($_GET) : [];
    unset($query_args['ll_wordset_page'], $query_args['ll_wordset_view']);

    if (!empty($query_args)) {
        $redirect_url = (string) add_query_arg($query_args, $redirect_url);
    }

    $current_url = ll_tools_wordset_page_normalize_same_origin_url(ll_tools_wordset_page_current_url());
    $normalized_redirect = ll_tools_wordset_page_normalize_same_origin_url($redirect_url);
    if ($current_url !== '' && $normalized_redirect !== '' && untrailingslashit($current_url) === untrailingslashit($normalized_redirect)) {
        return '';
    }

    return $redirect_url;
}

function ll_tools_wordset_page_get_subpage_return_url(WP_Term $wordset_term): string {
    $fallback = ll_tools_get_wordset_page_view_url($wordset_term);

    // Preserve the original return target when moving between sibling subpages.
    if (isset($_GET['ll_wordset_back'])) {
        $requested_back = ll_tools_wordset_page_normalize_same_origin_url(
            wp_unslash((string) $_GET['ll_wordset_back'])
        );
        if ($requested_back !== '') {
            return $requested_back;
        }
    }

    $current = ll_tools_wordset_page_current_url();
    if ($current === '') {
        return $fallback;
    }

    $current = remove_query_arg('ll_wordset_back', $current);
    $current = ll_tools_wordset_page_normalize_same_origin_url($current);
    if ($current === '') {
        return $fallback;
    }

    if (ll_tools_get_wordset_page_view() !== '') {
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

function ll_tools_wordset_page_progress_reset_category_options(array $categories): array {
    $options = [];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $category_id = isset($category['id']) ? (int) $category['id'] : 0;
        if ($category_id <= 0) {
            continue;
        }
        $label = isset($category['translation']) && trim((string) $category['translation']) !== ''
            ? (string) $category['translation']
            : (string) ($category['name'] ?? '');
        if ($label === '') {
            continue;
        }
        $options[] = [
            'id' => $category_id,
            'label' => $label,
        ];
    }

    if (!empty($options)) {
        usort($options, static function (array $left, array $right): int {
            if (function_exists('ll_tools_locale_compare_strings')) {
                return ll_tools_locale_compare_strings((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
            }
            return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });
    }

    return $options;
}

function ll_tools_wordset_page_category_has_recorded_progress(array $category_progress_row): bool {
    $studied_words = max(0, (int) ($category_progress_row['studied_words'] ?? 0));
    $mastered_words = max(0, (int) ($category_progress_row['mastered_words'] ?? 0));
    $exposure_total = max(0, (int) ($category_progress_row['exposure_total'] ?? 0));
    $last_seen_at = isset($category_progress_row['last_seen_at']) ? trim((string) $category_progress_row['last_seen_at']) : '';

    if ($studied_words > 0 || $mastered_words > 0 || $exposure_total > 0) {
        return true;
    }

    return $last_seen_at !== '';
}

function ll_tools_wordset_page_progress_resettable_category_ids(array $analytics_category_rows): array {
    $resettable_lookup = [];
    foreach ($analytics_category_rows as $analytics_category_row) {
        if (!is_array($analytics_category_row)) {
            continue;
        }
        $category_id = isset($analytics_category_row['id']) ? (int) $analytics_category_row['id'] : 0;
        if ($category_id <= 0) {
            continue;
        }
        if (!ll_tools_wordset_page_category_has_recorded_progress($analytics_category_row)) {
            continue;
        }
        $resettable_lookup[$category_id] = true;
    }

    $resettable_ids = array_keys($resettable_lookup);
    sort($resettable_ids, SORT_NUMERIC);
    return array_values(array_map('intval', $resettable_ids));
}

function ll_tools_wordset_page_progress_reset_notice(array $category_options): ?array {
    $status = isset($_GET['ll_wordset_progress_reset'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_progress_reset']))
        : '';
    if ($status === '') {
        return null;
    }

    $scope = isset($_GET['ll_wordset_progress_reset_scope'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_progress_reset_scope']))
        : '';
    $error = isset($_GET['ll_wordset_progress_reset_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_progress_reset_error']))
        : '';
    $category_id = isset($_GET['ll_wordset_progress_reset_category'])
        ? (int) wp_unslash((string) $_GET['ll_wordset_progress_reset_category'])
        : 0;

    $category_labels = [];
    foreach ($category_options as $category_option) {
        if (!is_array($category_option)) {
            continue;
        }
        $cid = isset($category_option['id']) ? (int) $category_option['id'] : 0;
        if ($cid <= 0) {
            continue;
        }
        $category_labels[$cid] = (string) ($category_option['label'] ?? '');
    }

    if ($status === 'ok') {
        if ($scope === 'category') {
            $category_label = isset($category_labels[$category_id]) ? $category_labels[$category_id] : '';
            if ($category_label === '') {
                $category_label = __('the selected category', 'll-tools-text-domain');
            }
            return [
                'type' => 'success',
                'message' => sprintf(__('Progress was reset for %s.', 'll-tools-text-domain'), $category_label),
            ];
        }

        if ($scope === 'all') {
            return [
                'type' => 'success',
                'message' => __('Progress was reset for all categories in this word set.', 'll-tools-text-domain'),
            ];
        }

        return [
            'type' => 'success',
            'message' => __('Progress was reset.', 'll-tools-text-domain'),
        ];
    }

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to reset progress.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'category') {
        return [
            'type' => 'error',
            'message' => __('Please choose a valid category with recorded progress.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'scope') {
        return [
            'type' => 'error',
            'message' => __('Reset all categories requires progress in at least two categories.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to reset progress right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_lesson_enable_notice(): ?array {
    $status = isset($_GET['ll_wordset_lesson_enable'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_lesson_enable']))
        : '';
    if ($status === '') {
        return null;
    }

    if ($status === 'ok') {
        return [
            'type' => 'success',
            'message' => __('Lesson pages are now enabled for this word set.', 'll-tools-text-domain'),
        ];
    }

    $error = isset($_GET['ll_wordset_lesson_enable_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_lesson_enable_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to enable lesson pages.', 'll-tools-text-domain'),
        ];
    }

    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }

    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that word set.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to enable lesson pages right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_manager_settings_notice(): ?array {
    $status = isset($_GET['ll_wordset_manager_settings'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_settings']))
        : '';
    if ($status === '') {
        return null;
    }

    if ($status === 'ok') {
        return [
            'type' => 'success',
            'message' => __('Word set settings were updated.', 'll-tools-text-domain'),
        ];
    }

    $error = isset($_GET['ll_wordset_manager_settings_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_settings_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to manage this word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that word set.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to update word set settings right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_manager_offline_export_notice(): ?array {
    $status = isset($_GET['ll_wordset_manager_offline_export'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_offline_export']))
        : '';
    if ($status === '') {
        return null;
    }

    if ($status === 'ok') {
        return [
            'type' => 'success',
            'message' => __('Offline app export is ready to download.', 'll-tools-text-domain'),
        ];
    }

    $message = isset($_GET['ll_wordset_manager_offline_export_message'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_wordset_manager_offline_export_message']))
        : '';
    if ($message !== '') {
        return [
            'type' => 'error',
            'message' => $message,
        ];
    }

    $error = isset($_GET['ll_wordset_manager_offline_export_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_offline_export_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to export offline apps for this word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'ziparchive') {
        return [
            'type' => 'error',
            'message' => __('ZipArchive is not available on this server.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to export an offline app right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_handle_manager_settings_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_settings_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_settings_action']))
        : '';
    if ($action !== 'save') {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_settings_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_settings_wordset_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_manager_settings_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_settings_nonce'])
        : '';
    $visibility = isset($_POST['ll_wordset_visibility'])
        ? ll_tools_normalize_wordset_visibility(wp_unslash((string) $_POST['ll_wordset_visibility']))
        : 'public';
    $autoplay_text_audio_answer_options = isset($_POST['ll_wordset_autoplay_text_audio_answer_options']) ? 1 : 0;
    $hide_lesson_text_for_non_text_quiz = isset($_POST['ll_wordset_hide_lesson_text_for_non_text_quiz']) ? 1 : 0;
    $submitted_tool = ll_tools_get_wordset_settings_tool();

    $base_redirect = ll_tools_get_wordset_settings_tool_url(
        $wordset_term,
        $submitted_tool !== '' ? $submitted_tool : 'visibility',
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );

    $redirect_error = static function (string $error) use ($base_redirect): void {
        wp_safe_redirect(add_query_arg([
            'll_wordset_manager_settings' => 'error',
            'll_wordset_manager_settings_error' => $error,
        ], $base_redirect));
        exit;
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }

    if (!function_exists('ll_tools_current_user_can_manage_wordset_content') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        $redirect_error('permission');
    }

    if (!wp_verify_nonce($nonce, 'll_wordset_manager_settings_' . $wordset_id)) {
        $redirect_error('nonce');
    }

    if ($submitted_tool === 'language') {
        $target_language = isset($_POST['wordset_language'])
            ? ll_tools_sanitize_wordset_language_setting(wp_unslash((string) $_POST['wordset_language']))
            : '';
        $translation_language = isset($_POST['ll_wordset_translation_language'])
            ? ll_tools_sanitize_wordset_language_setting(wp_unslash((string) $_POST['ll_wordset_translation_language']))
            : '';
        $category_translation_enabled = isset($_POST['ll_wordset_enable_category_translation']) ? 1 : 0;
        $category_translation_source = isset($_POST['ll_wordset_category_translation_source'])
            ? ll_tools_sanitize_wordset_category_translation_source(wp_unslash((string) $_POST['ll_wordset_category_translation_source']))
            : 'target';
        $word_title_language_role = isset($_POST['ll_wordset_word_title_language_role'])
            ? ll_tools_sanitize_wordset_title_language_role(wp_unslash((string) $_POST['ll_wordset_word_title_language_role']))
            : 'target';
        $recording_transcription_mode = isset($_POST['ll_wordset_recording_transcription_mode'])
            ? ll_tools_sanitize_wordset_recording_transcription_mode(wp_unslash((string) $_POST['ll_wordset_recording_transcription_mode']))
            : 'ipa';
        $previous_category_translation_enabled = function_exists('ll_tools_is_wordset_category_translation_enabled')
            ? (bool) ll_tools_is_wordset_category_translation_enabled([$wordset_id])
            : false;

        update_term_meta($wordset_id, 'll_language', $target_language);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, $translation_language);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, $category_translation_enabled);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, $category_translation_source);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, $word_title_language_role);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY, $recording_transcription_mode);

        if (!$previous_category_translation_enabled && $category_translation_enabled === 1 && function_exists('ll_tools_auto_translate_categories_for_wordset')) {
            ll_tools_auto_translate_categories_for_wordset($wordset_id);
        }
    } elseif ($submitted_tool === 'transcription') {
        $provider = isset($_POST['ll_wordset_transcription_provider'])
            ? ll_tools_sanitize_wordset_transcription_provider(wp_unslash((string) $_POST['ll_wordset_transcription_provider']))
            : '';
        $target = isset($_POST['ll_wordset_local_transcription_target'])
            ? ll_tools_sanitize_wordset_local_transcription_target(wp_unslash((string) $_POST['ll_wordset_local_transcription_target']))
            : 'recording_ipa';
        $endpoint = isset($_POST['ll_wordset_local_transcription_endpoint'])
            ? ll_tools_sanitize_wordset_local_transcription_endpoint(wp_unslash((string) $_POST['ll_wordset_local_transcription_endpoint']))
            : '';
        $api_token = isset($_POST['ll_wordset_transcription_api_token'])
            ? ll_tools_sanitize_wordset_transcription_api_token(wp_unslash((string) $_POST['ll_wordset_transcription_api_token']))
            : '';
        $offline_stt_bundle_path = isset($_POST['ll_wordset_offline_stt_bundle_path'])
            ? ll_tools_sanitize_wordset_offline_stt_bundle_path(wp_unslash((string) $_POST['ll_wordset_offline_stt_bundle_path']))
            : '';
        $speaking_enabled = isset($_POST['ll_wordset_speaking_game_enabled']) ? 1 : 0;
        $speaking_provider = isset($_POST['ll_wordset_speaking_game_provider'])
            ? ll_tools_sanitize_wordset_speaking_game_provider(wp_unslash((string) $_POST['ll_wordset_speaking_game_provider']))
            : '';
        $speaking_access = isset($_POST['ll_wordset_speaking_game_access'])
            ? ll_tools_sanitize_wordset_speaking_game_access(wp_unslash((string) $_POST['ll_wordset_speaking_game_access']))
            : 'learners';
        $speaking_target = isset($_POST['ll_wordset_speaking_game_target'])
            ? ll_tools_sanitize_wordset_speaking_game_target(wp_unslash((string) $_POST['ll_wordset_speaking_game_target']))
            : 'recording_text';
        $speaking_assemblyai_profile = isset($_POST['ll_wordset_speaking_game_assemblyai_profile'])
            ? ll_tools_sanitize_wordset_speaking_game_assemblyai_profile(wp_unslash((string) $_POST['ll_wordset_speaking_game_assemblyai_profile']))
            : 'wordset_language';

        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY, $provider);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, $target);
        if ($endpoint !== '') {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, $endpoint);
        } else {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY);
        }
        if ($api_token !== '') {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSCRIPTION_API_TOKEN_META_KEY, $api_token);
        } else {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSCRIPTION_API_TOKEN_META_KEY);
        }
        if ($offline_stt_bundle_path !== '') {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY, $offline_stt_bundle_path);
        } else {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY);
        }
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, $speaking_enabled);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, $speaking_provider);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ACCESS_META_KEY, $speaking_access);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, $speaking_target);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ASSEMBLYAI_PROFILE_META_KEY, $speaking_assemblyai_profile);
    } elseif ($submitted_tool === 'study') {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_AUTOPLAY_TEXT_AUDIO_ANSWER_OPTIONS_META_KEY, $autoplay_text_audio_answer_options);
        update_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', $hide_lesson_text_for_non_text_quiz);
    } else {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, $visibility);
    }

    wp_safe_redirect(add_query_arg([
        'll_wordset_manager_settings' => 'ok',
    ], $base_redirect));
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_settings_action', 6);

function ll_tools_wordset_page_handle_manager_offline_app_export_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_offline_export_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_offline_export_action']))
        : '';
    if ($action !== 'export') {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_offline_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_offline_wordset_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_manager_offline_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_offline_nonce'])
        : '';
    $base_redirect = ll_tools_get_wordset_settings_tool_url(
        $wordset_term,
        'offline-app',
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );
    $redirect_error = static function (string $error, string $message = '') use ($base_redirect): void {
        $args = [
            'll_wordset_manager_offline_export' => 'error',
            'll_wordset_manager_offline_export_error' => $error,
        ];
        if ($message !== '') {
            $args['ll_wordset_manager_offline_export_message'] = $message;
        }
        wp_safe_redirect(add_query_arg($args, $base_redirect));
        exit;
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }

    $can_manage_wordset = function_exists('ll_tools_current_user_can_manage_wordset_content')
        ? ll_tools_current_user_can_manage_wordset_content($wordset_id)
        : current_user_can('manage_options');
    $can_export_offline_app = function_exists('ll_tools_current_user_can_offline_app_export')
        ? ll_tools_current_user_can_offline_app_export()
        : current_user_can('manage_options');

    if (!$can_manage_wordset || !$can_export_offline_app) {
        $redirect_error('permission');
    }

    if (!wp_verify_nonce($nonce, 'll_wordset_manager_offline_export_' . $wordset_id)) {
        $redirect_error('nonce');
    }

    if (!class_exists('ZipArchive')) {
        $redirect_error('ziparchive');
    }

    $bundle_options = function_exists('ll_tools_offline_app_parse_export_request')
        ? ll_tools_offline_app_parse_export_request($_POST)
        : new WP_Error('ll_tools_offline_app_missing_parser', __('Offline app export is not available right now.', 'll-tools-text-domain'));
    if (is_wp_error($bundle_options)) {
        $redirect_error(sanitize_key($bundle_options->get_error_code()), $bundle_options->get_error_message());
    }

    $bundle = function_exists('ll_tools_build_offline_app_bundle')
        ? ll_tools_build_offline_app_bundle($bundle_options)
        : new WP_Error('ll_tools_offline_app_missing_builder', __('Offline app export is not available right now.', 'll-tools-text-domain'));
    if (is_wp_error($bundle)) {
        $redirect_error(sanitize_key($bundle->get_error_code()), $bundle->get_error_message());
    }

    $zip_path = (string) ($bundle['zip_path'] ?? '');
    $staging_dir = (string) ($bundle['staging_dir'] ?? '');
    $filename = (string) ($bundle['filename'] ?? 'll-tools-offline-app.zip');

    if ($zip_path === '' || !is_file($zip_path)) {
        if ($staging_dir !== '' && is_dir($staging_dir)) {
            ll_tools_rrmdir($staging_dir);
        }
        $redirect_error('missing_zip', __('Offline app export did not produce a zip file.', 'll-tools-text-domain'));
    }

    register_shutdown_function(static function () use ($zip_path, $staging_dir): void {
        if ($zip_path !== '' && is_file($zip_path)) {
            @unlink($zip_path);
        }
        if ($staging_dir !== '' && is_dir($staging_dir)) {
            ll_tools_rrmdir($staging_dir);
        }
    });

    ll_tools_stream_download_file($zip_path, $filename, 'application/zip');
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_offline_app_export_action', 6);

function ll_tools_wordset_page_manager_import_notice(): ?array {
    $status = isset($_GET['ll_wordset_manager_import'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_import']))
        : '';
    if ($status === '') {
        return null;
    }

    $created = isset($_GET['ll_wordset_manager_import_created'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_created']))
        : 0;
    $skipped_existing = isset($_GET['ll_wordset_manager_import_skipped_existing'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_skipped_existing']))
        : 0;
    $created_duplicates = isset($_GET['ll_wordset_manager_import_created_duplicates'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_created_duplicates']))
        : 0;
    $skipped_empty = isset($_GET['ll_wordset_manager_import_skipped_empty'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_skipped_empty']))
        : 0;
    $skipped_invalid = isset($_GET['ll_wordset_manager_import_skipped_invalid'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_skipped_invalid']))
        : 0;
    $failed = isset($_GET['ll_wordset_manager_import_failed'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_import_failed']))
        : 0;

    if ($status === 'ok' || $status === 'partial') {
        $message_parts = [];

        if ($created > 0) {
            $message_parts[] = sprintf(
                _n('%d word was imported.', '%d words were imported.', $created, 'll-tools-text-domain'),
                $created
            );
        }
        if ($skipped_existing > 0) {
            $message_parts[] = sprintf(
                _n('%d duplicate was skipped.', '%d duplicates were skipped.', $skipped_existing, 'll-tools-text-domain'),
                $skipped_existing
            );
            $message_parts[] = __('Re-run with "Allow duplicate titles" to import them anyway.', 'll-tools-text-domain');
        }
        if ($created_duplicates > 0) {
            $message_parts[] = sprintf(
                _n('%d duplicate title was imported because duplicate override was enabled.', '%d duplicate titles were imported because duplicate override was enabled.', $created_duplicates, 'll-tools-text-domain'),
                $created_duplicates
            );
        }
        if ($skipped_invalid > 0) {
            $message_parts[] = sprintf(
                _n('%d line was skipped because it did not include a prompt and answer pair.', '%d lines were skipped because they did not include prompt and answer pairs.', $skipped_invalid, 'll-tools-text-domain'),
                $skipped_invalid
            );
        }
        if ($skipped_empty > 0) {
            $message_parts[] = sprintf(
                _n('%d empty line was ignored.', '%d empty lines were ignored.', $skipped_empty, 'll-tools-text-domain'),
                $skipped_empty
            );
        }
        if ($failed > 0) {
            $message_parts[] = sprintf(
                _n('%d item failed during creation.', '%d items failed during creation.', $failed, 'll-tools-text-domain'),
                $failed
            );
        }
        if (empty($message_parts)) {
            $message_parts[] = __('No words were imported.', 'll-tools-text-domain');
        }

        return [
            'type' => $status === 'ok' ? 'success' : 'error',
            'message' => implode(' ', $message_parts),
        ];
    }

    $error = isset($_GET['ll_wordset_manager_import_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_import_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to import into this word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'category') {
        return [
            'type' => 'error',
            'message' => __('Unable to use that category.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'empty') {
        return [
            'type' => 'error',
            'message' => __('Paste at least one prompt and answer pair to import.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'format') {
        return [
            'type' => 'error',
            'message' => __('No valid prompt and answer pairs were found. Use one pair per line (tab-separated is recommended).', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to import words right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_manager_template_notice(): ?array {
    $status = isset($_GET['ll_wordset_manager_template'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_template']))
        : '';
    if ($status === '') {
        return null;
    }

    $categories_created = isset($_GET['ll_wordset_manager_template_categories'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_template_categories']))
        : 0;
    $images_created = isset($_GET['ll_wordset_manager_template_images'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_template_images']))
        : 0;
    $failed_categories = isset($_GET['ll_wordset_manager_template_failed_categories'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_template_failed_categories']))
        : 0;
    $failed_images = isset($_GET['ll_wordset_manager_template_failed_images'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_template_failed_images']))
        : 0;
    $copied_settings = isset($_GET['ll_wordset_manager_template_settings'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_manager_template_settings']))
        : 0;

    if ($status === 'ok' || $status === 'partial') {
        $message_parts = [
            __('New word set created from this template.', 'll-tools-text-domain'),
        ];

        if ($categories_created > 0) {
            $message_parts[] = sprintf(
                _n('%d category copied.', '%d categories copied.', $categories_created, 'll-tools-text-domain'),
                $categories_created
            );
        }
        if ($images_created > 0) {
            $message_parts[] = sprintf(
                _n('%d image copied.', '%d images copied.', $images_created, 'll-tools-text-domain'),
                $images_created
            );
        }
        if ($copied_settings > 0) {
            $message_parts[] = __('Non-language word set settings were copied too.', 'll-tools-text-domain');
        }
        if ($failed_categories > 0) {
            $message_parts[] = sprintf(
                _n('%d category could not be copied.', '%d categories could not be copied.', $failed_categories, 'll-tools-text-domain'),
                $failed_categories
            );
        }
        if ($failed_images > 0) {
            $message_parts[] = sprintf(
                _n('%d image could not be copied.', '%d images could not be copied.', $failed_images, 'll-tools-text-domain'),
                $failed_images
            );
        }
        if ($status === 'ok') {
            $message_parts[] = __('Set the new word set language next.', 'll-tools-text-domain');
        }

        return [
            'type' => $status === 'ok' ? 'success' : 'error',
            'message' => implode(' ', $message_parts),
        ];
    }

    $message = isset($_GET['ll_wordset_manager_template_message'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_wordset_manager_template_message']))
        : '';
    if ($message !== '') {
        return [
            'type' => 'error',
            'message' => $message,
        ];
    }

    $error = isset($_GET['ll_wordset_manager_template_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_template_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to create a new word set from this template.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that template word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'name') {
        return [
            'type' => 'error',
            'message' => __('Enter a name for the new word set.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to create a word set from this template right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_parse_manager_import_pairs(string $raw_pairs): array {
    $rows = [];
    $skipped_empty = 0;
    $skipped_invalid = 0;

    $lines = preg_split('/\r\n|\n|\r/', $raw_pairs);
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            $skipped_empty++;
            continue;
        }

        $parts = [];
        if (strpos($line, "\t") !== false) {
            $parts = explode("\t", $line, 2);
        } else {
            $parts = preg_split('/\s*(?:=>|::|\|)\s*/u', $line, 2);
            if (!is_array($parts)) {
                $parts = [];
            }
        }

        if (count($parts) < 2) {
            $skipped_invalid++;
            continue;
        }

        $prompt = trim((string) $parts[0]);
        $answer = trim((string) $parts[1]);
        if ($prompt === '' || $answer === '') {
            $skipped_invalid++;
            continue;
        }

        $rows[] = [
            'prompt' => $prompt,
            'answer' => $answer,
        ];
    }

    return [
        'rows' => $rows,
        'skipped_empty' => $skipped_empty,
        'skipped_invalid' => $skipped_invalid,
    ];
}

function ll_tools_wordset_page_maybe_set_manager_import_text_quiz_defaults(int $category_id): void {
    if ($category_id <= 0) {
        return;
    }

    $category_term = get_term($category_id, 'word-category');
    if (!($category_term instanceof WP_Term)) {
        return;
    }

    $stored_prompt_type = (string) get_term_meta($category_id, 'll_quiz_prompt_type', true);
    $stored_option_type = (string) get_term_meta($category_id, 'll_quiz_option_type', true);
    if ($stored_prompt_type !== '' || $stored_option_type !== '') {
        return;
    }

    $prompt_type = 'text_translation';
    $option_type = 'text_title';
    if (function_exists('ll_tools_normalize_quiz_prompt_type')) {
        $prompt_type = ll_tools_normalize_quiz_prompt_type($prompt_type);
    }
    if (function_exists('ll_tools_normalize_quiz_option_type')) {
        $option_type = ll_tools_normalize_quiz_option_type($option_type, false, $prompt_type);
    }

    update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
    update_term_meta($category_id, 'll_quiz_option_type', $option_type);
    if ($option_type === 'text_title') {
        update_term_meta($category_id, 'use_word_titles_for_audio', '1');
    } else {
        delete_term_meta($category_id, 'use_word_titles_for_audio');
    }
}

function ll_tools_wordset_page_handle_manager_import_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_import_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_import_action']))
        : '';
    if ($action !== 'import_pairs') {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_import_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_import_wordset_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_manager_import_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_import_nonce'])
        : '';
    $raw_pairs = isset($_POST['ll_wordset_manager_import_pairs'])
        ? (string) wp_unslash($_POST['ll_wordset_manager_import_pairs'])
        : '';
    $selected_category_id = isset($_POST['ll_wordset_manager_import_existing_category'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_import_existing_category'])
        : 0;
    $new_category_name = isset($_POST['ll_wordset_manager_import_new_category'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_manager_import_new_category']))
        : '';
    $allow_duplicate_titles = !empty($_POST['ll_wordset_manager_import_allow_duplicates']);
    $submitted_tool = ll_tools_get_wordset_settings_tool();

    $base_redirect = ll_tools_get_wordset_settings_tool_url(
        $wordset_term,
        $submitted_tool !== '' ? $submitted_tool : 'import',
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );

    $redirect_error = static function (string $error, array $extra_args = []) use ($base_redirect): void {
        $args = array_merge([
            'll_wordset_manager_import' => 'error',
            'll_wordset_manager_import_error' => $error,
        ], $extra_args);
        wp_safe_redirect(add_query_arg($args, $base_redirect));
        exit;
    };

    $redirect_result = static function (string $status, array $counts) use ($base_redirect): void {
        $args = [
            'll_wordset_manager_import' => $status,
            'll_wordset_manager_import_created' => max(0, (int) ($counts['created'] ?? 0)),
            'll_wordset_manager_import_created_duplicates' => max(0, (int) ($counts['created_duplicates'] ?? 0)),
            'll_wordset_manager_import_skipped_existing' => max(0, (int) ($counts['skipped_existing'] ?? 0)),
            'll_wordset_manager_import_skipped_empty' => max(0, (int) ($counts['skipped_empty'] ?? 0)),
            'll_wordset_manager_import_skipped_invalid' => max(0, (int) ($counts['skipped_invalid'] ?? 0)),
            'll_wordset_manager_import_failed' => max(0, (int) ($counts['failed'] ?? 0)),
        ];
        wp_safe_redirect(add_query_arg($args, $base_redirect));
        exit;
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }
    if (!function_exists('ll_tools_current_user_can_manage_wordset_content') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        $redirect_error('permission');
    }
    if (!wp_verify_nonce($nonce, 'll_wordset_manager_import_' . $wordset_id)) {
        $redirect_error('nonce');
    }

    if (trim($raw_pairs) === '') {
        $redirect_error('empty');
    }

    $category_id = 0;
    if ($new_category_name !== '') {
        if (function_exists('ll_tools_create_or_get_wordset_category')) {
            $created_category = ll_tools_create_or_get_wordset_category($new_category_name, $wordset_id);
            if (is_wp_error($created_category) || (int) $created_category <= 0) {
                $redirect_error('category');
            }
            $category_id = (int) $created_category;
        } else {
            $existing = term_exists($new_category_name, 'word-category');
            if ($existing) {
                $category_id = (int) (is_array($existing) ? ($existing['term_id'] ?? 0) : $existing);
            } else {
                $inserted_category = wp_insert_term($new_category_name, 'word-category');
                if (is_wp_error($inserted_category)) {
                    $redirect_error('category');
                }
                $category_id = (int) ($inserted_category['term_id'] ?? 0);
            }
        }
        if ($category_id <= 0) {
            $redirect_error('category');
        }
    } elseif ($selected_category_id > 0) {
        $category_term = get_term($selected_category_id, 'word-category');
        if (!$category_term || is_wp_error($category_term)) {
            $redirect_error('category');
        }
        $category_id = (int) $selected_category_id;
    }

    $parsed = ll_tools_wordset_page_parse_manager_import_pairs($raw_pairs);
    $rows = (isset($parsed['rows']) && is_array($parsed['rows'])) ? $parsed['rows'] : [];
    $skipped_empty = max(0, (int) ($parsed['skipped_empty'] ?? 0));
    $skipped_invalid = max(0, (int) ($parsed['skipped_invalid'] ?? 0));
    if (empty($rows)) {
        $redirect_error('format', [
            'll_wordset_manager_import_skipped_empty' => $skipped_empty,
            'll_wordset_manager_import_skipped_invalid' => $skipped_invalid,
        ]);
    }

    ll_tools_wordset_page_maybe_set_manager_import_text_quiz_defaults($category_id);
    $created = 0;
    $created_duplicates = 0;
    $skipped_existing = 0;
    $failed = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $skipped_invalid++;
            continue;
        }

        $prompt_text = trim((string) ($row['prompt'] ?? ''));
        $answer_text = trim((string) ($row['answer'] ?? ''));
        if ($prompt_text === '' || $answer_text === '') {
            $skipped_invalid++;
            continue;
        }

        // Bulk import pairs are treated as text->text by default:
        // prompt => word_translation, answer => post_title.
        $raw_title_text = $answer_text;
        $normalized_title = function_exists('ll_tools_import_capitalize_word')
            ? ll_tools_import_capitalize_word($raw_title_text, $wordset_id)
            : ucfirst($raw_title_text);
        if ($normalized_title === '') {
            $skipped_invalid++;
            continue;
        }

        $existing_ids = function_exists('ll_tools_find_existing_word_ids_by_title_in_wordset')
            ? ll_tools_find_existing_word_ids_by_title_in_wordset($normalized_title, $wordset_id)
            : [];
        if (!empty($existing_ids) && !$allow_duplicate_titles) {
            $skipped_existing++;
            continue;
        }
        $is_duplicate_title = !empty($existing_ids);

        $post_id = wp_insert_post([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => $normalized_title,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            $failed++;
            continue;
        }
        $post_id = (int) $post_id;

        $wordset_result = wp_set_object_terms($post_id, [$wordset_id], 'wordset', false);
        if (is_wp_error($wordset_result)) {
            wp_delete_post($post_id, true);
            $failed++;
            continue;
        }

        if ($category_id > 0) {
            $category_result = wp_set_post_terms($post_id, [$category_id], 'word-category', false);
            if (is_wp_error($category_result)) {
                wp_delete_post($post_id, true);
                $failed++;
                continue;
            }
        }

        $target_language_text = $prompt_text;
        $helper_text = $answer_text;
        update_post_meta($post_id, 'word_translation', $target_language_text);
        if ($helper_text !== '') {
            update_post_meta($post_id, 'word_english_meaning', $helper_text);
        } else {
            delete_post_meta($post_id, 'word_english_meaning');
        }

        if ($category_id <= 0) {
            update_post_meta($post_id, '_ll_skip_audio_requirement_once', '1');
        }

        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish',
        ]);
        if (get_post_status($post_id) !== 'publish' && $category_id <= 0) {
            delete_post_meta($post_id, '_ll_skip_audio_requirement_once');
        }

        if ($is_duplicate_title) {
            $created_duplicates++;
        }
        $created++;
    }

    $status = ($failed > 0) ? 'partial' : 'ok';
    $redirect_result($status, [
        'created' => $created,
        'created_duplicates' => $created_duplicates,
        'skipped_existing' => $skipped_existing,
        'skipped_empty' => $skipped_empty,
        'skipped_invalid' => $skipped_invalid,
        'failed' => $failed,
    ]);
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_import_action', 6);

function ll_tools_wordset_page_handle_manager_template_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_template_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_template_action']))
        : '';
    if ($action !== 'create') {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_template_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_template_wordset_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_manager_template_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_template_nonce'])
        : '';
    $submitted_tool = ll_tools_get_wordset_settings_tool();
    $new_wordset_name = isset($_POST['ll_wordset_manager_template_name'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_manager_template_name']))
        : '';
    $new_wordset_slug = isset($_POST['ll_wordset_manager_template_slug'])
        ? sanitize_title(wp_unslash((string) $_POST['ll_wordset_manager_template_slug']))
        : '';
    $copy_settings = !empty($_POST['ll_wordset_manager_template_copy_settings']);

    $base_redirect = ll_tools_get_wordset_settings_tool_url(
        $wordset_term,
        $submitted_tool !== '' ? $submitted_tool : 'template',
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );

    $redirect_error = static function (string $error, string $message = '') use ($base_redirect): void {
        $args = [
            'll_wordset_manager_template' => 'error',
            'll_wordset_manager_template_error' => $error,
        ];
        if ($message !== '') {
            $args['ll_wordset_manager_template_message'] = $message;
        }
        wp_safe_redirect(add_query_arg($args, $base_redirect));
        exit;
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }
    if (!function_exists('ll_tools_current_user_can_manage_wordset_content') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        $redirect_error('permission');
    }
    if (!wp_verify_nonce($nonce, 'll_wordset_manager_template_' . $wordset_id)) {
        $redirect_error('nonce');
    }
    if (trim($new_wordset_name) === '') {
        $redirect_error('name');
    }
    if (!function_exists('ll_tools_create_wordset_from_template')) {
        $redirect_error('', __('Template creation is not available right now.', 'll-tools-text-domain'));
    }

    $created = ll_tools_create_wordset_from_template($wordset_id, [
        'name' => $new_wordset_name,
        'slug' => $new_wordset_slug,
        'copy_settings' => $copy_settings,
        'manager_user_id' => get_current_user_id(),
    ]);
    if (is_wp_error($created)) {
        $error_code = sanitize_key((string) $created->get_error_code());
        if ($error_code === 'll_wordset_template_missing_name') {
            $redirect_error('name');
        }

        $redirect_error($error_code !== '' ? $error_code : 'create', $created->get_error_message());
    }

    $target_wordset_term = (isset($created['wordset_term']) && $created['wordset_term'] instanceof WP_Term)
        ? $created['wordset_term']
        : get_term((int) ($created['wordset_id'] ?? 0), 'wordset');
    if (!($target_wordset_term instanceof WP_Term) || is_wp_error($target_wordset_term)) {
        $redirect_error('create', __('The new word set was created, but it could not be opened automatically.', 'll-tools-text-domain'));
    }

    $failed_categories = max(0, (int) ($created['failed_categories'] ?? 0));
    $failed_images = max(0, (int) ($created['failed_images'] ?? 0));
    $status = ($failed_categories > 0 || $failed_images > 0) ? 'partial' : 'ok';
    $target_redirect = ll_tools_get_wordset_settings_tool_url($target_wordset_term, 'language');

    wp_safe_redirect(add_query_arg([
        'll_wordset_manager_template' => $status,
        'll_wordset_manager_template_categories' => max(0, (int) ($created['categories_created'] ?? 0)),
        'll_wordset_manager_template_images' => max(0, (int) ($created['images_created'] ?? 0)),
        'll_wordset_manager_template_failed_categories' => $failed_categories,
        'll_wordset_manager_template_failed_images' => $failed_images,
        'll_wordset_manager_template_settings' => max(0, (int) ($created['copied_settings'] ?? 0)),
    ], $target_redirect));
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_template_action', 6);

function ll_tools_wordset_page_manager_recorder_notice(): ?array {
    $status = isset($_GET['ll_wordset_manager_recorder'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_recorder']))
        : '';
    if ($status === '') {
        return null;
    }

    if ($status === 'ok') {
        $result = isset($_GET['ll_wordset_manager_recorder_result'])
            ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_recorder_result']))
            : '';
        if ($result === 'unassigned') {
            return [
                'type' => 'success',
                'message' => __('Recorder assignment removed for this word set.', 'll-tools-text-domain'),
            ];
        }

        return [
            'type' => 'success',
            'message' => __('Recorder assignment updated for this word set.', 'll-tools-text-domain'),
        ];
    }

    $error = isset($_GET['ll_wordset_manager_recorder_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_recorder_error']))
        : '';

    if ($error === 'permission') {
        return [
            'type' => 'error',
            'message' => __('You do not have permission to manage recorder access for this word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'nonce') {
        return [
            'type' => 'error',
            'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'wordset') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that word set.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'user') {
        return [
            'type' => 'error',
            'message' => __('Unable to find that recorder user.', 'll-tools-text-domain'),
        ];
    }
    if ($error === 'role') {
        return [
            'type' => 'error',
            'message' => __('The selected user does not have the Audio Recorder role.', 'll-tools-text-domain'),
        ];
    }

    return [
        'type' => 'error',
        'message' => __('Unable to update recorder access right now.', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_audio_upload_notice(): ?array {
    $status = isset($_GET['ll_wordset_audio_upload'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_audio_upload']))
        : '';
    if ($status === '') {
        return null;
    }

    $success_count = isset($_GET['ll_wordset_audio_upload_success'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_audio_upload_success']))
        : 0;
    $failed_count = isset($_GET['ll_wordset_audio_upload_failed'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_audio_upload_failed']))
        : 0;
    $mode = isset($_GET['ll_wordset_audio_upload_mode'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_audio_upload_mode']))
        : 'create';
    $mode_label = ($mode === 'match')
        ? __('Audio upload (match existing words)', 'll-tools-text-domain')
        : __('Audio upload', 'll-tools-text-domain');

    $parts = [];
    if ($success_count > 0) {
        $parts[] = sprintf(
            _n('%1$s: %2$d file succeeded.', '%1$s: %2$d files succeeded.', $success_count, 'll-tools-text-domain'),
            $mode_label,
            $success_count
        );
    }
    if ($failed_count > 0) {
        $parts[] = sprintf(
            _n('%d file failed.', '%d files failed.', $failed_count, 'll-tools-text-domain'),
            $failed_count
        );
    }
    if (empty($parts)) {
        $parts[] = __('Audio upload completed with no processed files.', 'll-tools-text-domain');
    }

    return [
        'type' => ($status === 'ok') ? 'success' : 'error',
        'message' => implode(' ', $parts),
    ];
}

function ll_tools_wordset_page_image_upload_notice(): ?array {
    $status = isset($_GET['ll_wordset_image_upload'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_image_upload']))
        : '';
    if ($status === '') {
        return null;
    }

    $success_count = isset($_GET['ll_wordset_image_upload_success'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_image_upload_success']))
        : 0;
    $failed_count = isset($_GET['ll_wordset_image_upload_failed'])
        ? max(0, (int) wp_unslash((string) $_GET['ll_wordset_image_upload_failed']))
        : 0;

    $parts = [];
    if ($success_count > 0) {
        $parts[] = sprintf(
            _n('Image upload: %d file succeeded.', 'Image upload: %d files succeeded.', $success_count, 'll-tools-text-domain'),
            $success_count
        );
    }
    if ($failed_count > 0) {
        $parts[] = sprintf(
            _n('%d file failed.', '%d files failed.', $failed_count, 'll-tools-text-domain'),
            $failed_count
        );
    }
    if (empty($parts)) {
        $parts[] = __('Image upload completed with no processed files.', 'll-tools-text-domain');
    }

    return [
        'type' => ($status === 'ok') ? 'success' : 'error',
        'message' => implode(' ', $parts),
    ];
}

function ll_tools_wordset_page_handle_manager_recorder_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_recorder_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_recorder_action']))
        : '';
    if (!in_array($action, ['assign', 'unassign'], true)) {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $wordset_slug = (string) $wordset_term->slug;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_recorder_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_recorder_wordset_id'])
        : 0;
    $recorder_user_id = isset($_POST['ll_wordset_manager_recorder_user_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_manager_recorder_user_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_manager_recorder_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_recorder_nonce'])
        : '';
    $submitted_tool = ll_tools_get_wordset_settings_tool();

    $base_redirect = ll_tools_get_wordset_settings_tool_url(
        $wordset_term,
        $submitted_tool !== '' ? $submitted_tool : 'recorder',
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );

    $redirect_error = static function (string $error) use ($base_redirect): void {
        wp_safe_redirect(add_query_arg([
            'll_wordset_manager_recorder' => 'error',
            'll_wordset_manager_recorder_error' => $error,
        ], $base_redirect));
        exit;
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }
    if (!function_exists('ll_tools_current_user_can_manage_wordset_content') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        $redirect_error('permission');
    }
    if (!wp_verify_nonce($nonce, 'll_wordset_manager_recorder_' . $wordset_id)) {
        $redirect_error('nonce');
    }
    if ($recorder_user_id <= 0) {
        $redirect_error('user');
    }

    $recorder_user = get_userdata($recorder_user_id);
    if (!$recorder_user) {
        $redirect_error('user');
    }
    if (!in_array('audio_recorder', (array) $recorder_user->roles, true)) {
        $redirect_error('role');
    }

    $config = function_exists('ll_get_user_recording_config')
        ? ll_get_user_recording_config($recorder_user_id)
        : get_user_meta($recorder_user_id, 'll_recording_config', true);
    if (!is_array($config)) {
        $config = [];
    }

    if ($action === 'assign') {
        $config['wordset'] = $wordset_slug;
        // Reset category so a stale category slug from another wordset doesn't hide work.
        $config['category'] = '';
    } else {
        $current_config_wordset = isset($config['wordset']) ? sanitize_title((string) $config['wordset']) : '';
        if ($current_config_wordset === sanitize_title($wordset_slug)) {
            $config['wordset'] = '';
            $config['category'] = '';
        }
    }

    update_user_meta($recorder_user_id, 'll_recording_config', $config);

    wp_safe_redirect(add_query_arg([
        'll_wordset_manager_recorder' => 'ok',
        'll_wordset_manager_recorder_result' => ($action === 'assign') ? 'assigned' : 'unassigned',
    ], $base_redirect));
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_recorder_action', 6);

function ll_tools_wordset_page_handle_progress_reset_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!ll_tools_is_wordset_page_context()) {
        return;
    }

    $reset_scope = isset($_POST['ll_wordset_progress_reset_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_progress_reset_action']))
        : '';
    if (!in_array($reset_scope, ['category', 'all'], true)) {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_progress_reset_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_progress_reset_wordset_id'])
        : 0;
    $submitted_category_id = isset($_POST['ll_wordset_progress_reset_category_id'])
        ? (int) wp_unslash((string) $_POST['ll_wordset_progress_reset_category_id'])
        : 0;
    $nonce = isset($_POST['ll_wordset_progress_reset_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_progress_reset_nonce'])
        : '';

    $base_redirect = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'progress'),
        ll_tools_wordset_page_resolve_back_url($wordset_term)
    );

    $redirect_params = [
        'll_wordset_progress_reset_scope' => $reset_scope,
    ];
    if ($submitted_category_id > 0) {
        $redirect_params['ll_wordset_progress_reset_category'] = $submitted_category_id;
    }

    if ($submitted_wordset_id !== $wordset_id) {
        wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
            'll_wordset_progress_reset' => 'error',
            'll_wordset_progress_reset_error' => 'wordset',
        ]), $base_redirect));
        exit;
    }

    if (!is_user_logged_in() || (function_exists('ll_tools_user_study_can_access') && !ll_tools_user_study_can_access())) {
        wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
            'll_wordset_progress_reset' => 'error',
            'll_wordset_progress_reset_error' => 'permission',
        ]), $base_redirect));
        exit;
    }

    if (!wp_verify_nonce($nonce, 'll_wordset_progress_reset_' . $wordset_id)) {
        wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
            'll_wordset_progress_reset' => 'error',
            'll_wordset_progress_reset_error' => 'nonce',
        ]), $base_redirect));
        exit;
    }

    $scope_category_ids = [];
    if (function_exists('ll_tools_build_user_study_analytics_payload')) {
        $scope_analytics = ll_tools_build_user_study_analytics_payload(get_current_user_id(), $wordset_id, [], 14, true);
        $scope_analytics_categories = (isset($scope_analytics['categories']) && is_array($scope_analytics['categories']))
            ? $scope_analytics['categories']
            : [];
        $scope_category_ids = ll_tools_wordset_page_progress_resettable_category_ids($scope_analytics_categories);
    }
    if (empty($scope_category_ids)) {
        wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
            'll_wordset_progress_reset' => 'error',
            'll_wordset_progress_reset_error' => ($reset_scope === 'all') ? 'scope' : 'category',
        ]), $base_redirect));
        exit;
    }

    $scope_lookup = array_fill_keys($scope_category_ids, true);

    $category_ids_to_reset = [];
    if ($reset_scope === 'category') {
        if ($submitted_category_id <= 0 || empty($scope_lookup[$submitted_category_id])) {
            wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
                'll_wordset_progress_reset' => 'error',
                'll_wordset_progress_reset_error' => 'category',
            ]), $base_redirect));
            exit;
        }
        $category_ids_to_reset = [$submitted_category_id];
    } else {
        if (count($scope_category_ids) <= 1) {
            wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
                'll_wordset_progress_reset' => 'error',
                'll_wordset_progress_reset_error' => 'scope',
            ]), $base_redirect));
            exit;
        }
        $category_ids_to_reset = $scope_category_ids;
    }

    if (function_exists('ll_tools_reset_user_progress')) {
        ll_tools_reset_user_progress(get_current_user_id(), [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids_to_reset,
        ]);
    }

    wp_safe_redirect(add_query_arg(array_merge($redirect_params, [
        'll_wordset_progress_reset' => 'ok',
    ]), $base_redirect));
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_handle_progress_reset_action', 7);

function ll_tools_wordset_page_render_mode_icon(string $mode, array $mode_ui, string $fallback, string $class = 'll-vocab-lesson-mode-icon'): string {
    $cfg = (isset($mode_ui[$mode]) && is_array($mode_ui[$mode])) ? $mode_ui[$mode] : [];
    if (!empty($cfg['svg'])) {
        return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $cfg['svg'] . '</span>';
    }
    $icon = !empty($cfg['icon']) ? (string) $cfg['icon'] : $fallback;
    return '<span class="' . esc_attr($class) . '" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
}

function ll_tools_wordset_page_render_hide_icon(string $class = 'll-wordset-hide-icon'): string {
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 64 64" width="16" height="16" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="32" cy="32" r="7" fill="currentColor"/>'
        . '<path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_unhide_icon(string $class = 'll-wordset-unhide-icon'): string {
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true" focusable="false">'
        . '<path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_reset_icon(string $class = 'll-wordset-progress-reset-icon-svg'): string {
    return '<svg class="' . esc_attr($class) . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="9"/>'
        . '<path d="M9 9l6 6"/>'
        . '<path d="M15 9l-6 6"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_record_icon(string $class = 'll-wordset-speaking-stage__record-icon-svg'): string {
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="24" height="24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="8"/>'
        . '</svg>';
}

function ll_tools_get_wordset_games_frontend_config(int $wordset_id = 0): array {
    $round_options = function_exists('ll_tools_wordset_games_round_options')
        ? ll_tools_wordset_games_round_options()
        : [20, 50, 100, 'all'];
    $default_round_option = function_exists('ll_tools_wordset_games_default_round_option')
        ? ll_tools_wordset_games_default_round_option()
        : 50;
    $image_game_card_count = function_exists('ll_tools_wordset_get_image_game_card_count')
        ? ll_tools_wordset_get_image_game_card_count($wordset_id)
        : 4;

    return [
        'bootstrapAction' => 'll_wordset_games_bootstrap',
        'launchAction' => 'll_wordset_games_launch',
        'transcribeAttemptAction' => 'll_wordset_speaking_game_transcribe_attempt',
        'scoreAttemptAction' => 'll_wordset_speaking_game_score_attempt',
        'matchAttemptAction' => 'll_wordset_speaking_game_match_attempt',
        'roundOptions' => array_values($round_options),
        'defaultRoundOption' => $default_round_option,
        'minimumWordCount' => function_exists('ll_tools_wordset_games_min_word_count')
            ? ll_tools_wordset_games_min_word_count()
            : 5,
        'spaceShooter' => [
            'slug' => 'space-shooter',
            'lives' => 3,
            'cardCount' => $image_game_card_count,
            'maxLoadedWords' => function_exists('ll_tools_wordset_games_space_shooter_launch_word_cap')
                ? ll_tools_wordset_games_space_shooter_launch_word_cap()
                : 60,
            'fireIntervalMs' => 165,
            'correctCoinReward' => 1,
            'wrongHitCoinPenalty' => 0,
            'wrongHitLifePenalty' => 1,
            'timeoutCoinPenalty' => 1,
            'timeoutLifePenalty' => 1,
            'audioSafeLineRatio' => 0.6,
            'cardEntryRevealMs' => 560,
            'promptAutoReplayGapMs' => 420,
            'promptAudioVolume' => 1,
            'correctHitVolume' => 0.28,
            'wrongHitVolume' => 0.2,
            'correctHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/space-shooter-correct-hit.mp3',
                LL_TOOLS_BASE_URL . 'media/space-shooter-correct-hit.ogg',
            ],
            'wrongHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.mp3',
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.ogg',
            ],
        ],
        'bubblePop' => [
            'slug' => 'bubble-pop',
            'lives' => 3,
            'cardCount' => $image_game_card_count,
            'maxLoadedWords' => function_exists('ll_tools_wordset_games_bubble_pop_launch_word_cap')
                ? ll_tools_wordset_games_bubble_pop_launch_word_cap()
                : 60,
            'correctCoinReward' => 1,
            'wrongHitLifePenalty' => 1,
            'timeoutCoinPenalty' => 1,
            'timeoutLifePenalty' => 1,
            'audioSafeLineRatio' => 0.58,
            'cardEntryRevealMs' => 520,
            'promptAutoReplayGapMs' => 420,
            'promptAudioVolume' => 1,
            'correctHitVolume' => 0.28,
            'wrongHitVolume' => 0.2,
            'assetPreloadTimeoutMs' => 8000,
            'correctHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/bubble-pop.mp3',
            ],
            'wrongHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.mp3',
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.ogg',
            ],
        ],
        'lineUp' => [
            'slug' => 'line-up',
            'minimumSequenceLength' => function_exists('ll_tools_wordset_games_lineup_min_sequence_length')
                ? ll_tools_wordset_games_lineup_min_sequence_length()
                : 3,
            'maxLoadedSequences' => 60,
            'shuffleRetries' => 6,
        ],
        'speakingPractice' => [
            'slug' => 'speaking-practice',
            'maxLoadedWords' => function_exists('ll_tools_wordset_games_speaking_practice_launch_word_cap')
                ? ll_tools_wordset_games_speaking_practice_launch_word_cap()
                : 60,
            'autoStartDelayMs' => 280,
            'maxRecordingMs' => 8000,
            'silenceWindowMs' => 1050,
            'silenceThreshold' => 0.034,
            'speechStartThreshold' => 0.06,
            'minSpeechMs' => 160,
            'apiCheckTimeoutMs' => 1500,
            'correctHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/right-answer.mp3',
            ],
            'wrongHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/wrong-answer.mp3',
            ],
        ],
        'speakingStack' => [
            'slug' => 'speaking-stack',
            'cardCount' => 4,
            'maxLoadedWords' => function_exists('ll_tools_wordset_games_speaking_stack_launch_word_cap')
                ? ll_tools_wordset_games_speaking_stack_launch_word_cap()
                : 60,
            'initialSpawnCount' => 3,
            'initialSpawnDelayMs' => 5200,
            'spawnGapMs' => 4500,
            'fallSpeed' => 176,
            'stackGapPx' => 12,
            'groundPaddingPx' => 34,
            'topDangerPaddingPx' => 14,
            'finalSilenceMs' => 10000,
            'matchThresholdScore' => 65,
            'maxRecordingMs' => 6000,
            'silenceWindowMs' => 820,
            'silenceThreshold' => 0.03,
            'speechStartThreshold' => 0.055,
            'minSpeechMs' => 120,
            'apiCheckTimeoutMs' => 1500,
            'thinkPaddingStartMs' => 1900,
            'thinkPaddingEndMs' => 1200,
            'correctHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/space-shooter-correct-hit.mp3',
                LL_TOOLS_BASE_URL . 'media/space-shooter-correct-hit.ogg',
            ],
            'wrongHitAudioSources' => [
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.mp3',
                LL_TOOLS_BASE_URL . 'media/space-shooter-wrong-hit.ogg',
            ],
        ],
    ];
}

function ll_tools_get_wordset_games_i18n_messages(): array {
    return [
        'gamesLoading' => __('Checking game availability...', 'll-tools-text-domain'),
        'gamesPreparingRun' => __('Preparing game...', 'll-tools-text-domain'),
        'gamesLoginRequired' => __('Sign in to play with your in-progress words.', 'll-tools-text-domain'),
        'gamesLoadError' => __('Unable to load games right now.', 'll-tools-text-domain'),
        'gamesReadyCount' => __('%d words ready', 'll-tools-text-domain'),
        'gamesReadySequences' => __('%d sequences ready', 'll-tools-text-domain'),
        'gamesNeedWords' => __('Need %1$d more words to unlock this game.', 'll-tools-text-domain'),
        'gamesNeedLearnedWords' => __('Need %1$d more learned words to unlock this game.', 'll-tools-text-domain'),
        'gamesNeedCompatibleWords' => __('This word set does not have a playable mix of picture cards yet.', 'll-tools-text-domain'),
        'gamesLineupNeedItems' => __('Each Line-Up sequence needs at least %d cards.', 'll-tools-text-domain'),
        'gamesPlay' => _x('Play', 'launch game action', 'll-tools-text-domain'),
        'gamesLocked' => __('Locked', 'll-tools-text-domain'),
        'gamesSpeakingHiddenConnection' => __('Speaking games are hidden because the speaking service for this word set is not responding on this device.', 'll-tools-text-domain'),
        'gamesSpeakingHiddenGeneric' => __('Speaking games are hidden because this word set speaking setup is not available right now.', 'll-tools-text-domain'),
        'gamesSpeakingOpenSettings' => __('Open speaking settings', 'll-tools-text-domain'),
        'gamesBack' => __('Games', 'll-tools-text-domain'),
        'gamesReplayAudio' => __('Replay prompt', 'll-tools-text-domain'),
        'gamesPauseRun' => __('Pause run', 'll-tools-text-domain'),
        'gamesResumeRun' => __('Resume', 'll-tools-text-domain'),
        'gamesPaused' => __('Paused', 'll-tools-text-domain'),
        'gamesInactivePauseSummary' => __('Paused after %d rounds without input.', 'll-tools-text-domain'),
        'gamesCoins' => __('Coins', 'll-tools-text-domain'),
        'gamesLives' => __('Lives', 'll-tools-text-domain'),
        'gamesControlLeft' => __('Move left', 'll-tools-text-domain'),
        'gamesControlRight' => __('Move right', 'll-tools-text-domain'),
        'gamesControlFire' => __('Fire', 'll-tools-text-domain'),
        'gamesLengthLabel' => __('Words per game', 'll-tools-text-domain'),
        'gamesLengthAll' => __('All', 'll-tools-text-domain'),
        'gamesLengthAllAria' => __('All available words', 'll-tools-text-domain'),
        'gamesGameOver' => __('Run Complete', 'll-tools-text-domain'),
        'gamesSummary' => __('Coins: %1$d · Prompts: %2$d', 'll-tools-text-domain'),
        'gamesWinTitle' => __('You win', 'll-tools-text-domain'),
        'gamesWinSummary' => __('Completed: %1$d of %2$d · Coins: %3$d', 'll-tools-text-domain'),
        'gamesReplayRun' => __('Replay', 'll-tools-text-domain'),
        'gamesNewGame' => __('New game', 'll-tools-text-domain'),
        'gamesBackToCatalog' => __('Back to games', 'll-tools-text-domain'),
        'gamesCloseConfirm' => __('Leave this game? Your current run will be lost.', 'll-tools-text-domain'),
        'gamesBoardLabelDefault' => __('Wordset game board', 'll-tools-text-domain'),
        'gamesBoardLabelSpaceShooter' => __('Space Shooter game board', 'll-tools-text-domain'),
        'gamesBoardLabelBubblePop' => __('Bubble Pop game board', 'll-tools-text-domain'),
        'gamesBoardLabelLineup' => __('Line-Up sequence board', 'll-tools-text-domain'),
        'gamesBoardLabelSpeakingPractice' => __('Speaking practice panel', 'll-tools-text-domain'),
        'gamesBoardLabelSpeakingStack' => __('Word Stack game board', 'll-tools-text-domain'),
        'gamesLineupInstruction' => __('Put the cards in the correct order.', 'll-tools-text-domain'),
        'gamesLineupProgress' => __('Sequence %1$d of %2$d', 'll-tools-text-domain'),
        'gamesLineupMoveEarlier' => __('Move earlier', 'll-tools-text-domain'),
        'gamesLineupMoveLater' => __('Move later', 'll-tools-text-domain'),
        'gamesLineupShuffle' => __('Shuffle', 'll-tools-text-domain'),
        'gamesLineupCheck' => __('Check', 'll-tools-text-domain'),
        'gamesLineupNext' => __('Next', 'll-tools-text-domain'),
        'gamesLineupFinish' => __('Finish', 'll-tools-text-domain'),
        'gamesLineupCorrect' => __('Correct order.', 'll-tools-text-domain'),
        'gamesLineupTryAgain' => __('Not quite yet. %1$d of %2$d are in the right place.', 'll-tools-text-domain'),
        'gamesLineupDoneTitle' => __('Line-Up complete', 'll-tools-text-domain'),
        'gamesLineupSummary' => __('Perfect: %1$d of %2$d · Retries: %3$d', 'll-tools-text-domain'),
        'gamesSpeakingCheckingApi' => __('Checking speaking game connection...', 'll-tools-text-domain'),
        'gamesSpeakingApiUnavailable' => __('Speaking practice is unavailable on this device right now.', 'll-tools-text-domain'),
        'gamesSpeakingRound' => __('Word %1$d of %2$d', 'll-tools-text-domain'),
        'gamesSpeakingPromptImage' => __('Say the word for this picture.', 'll-tools-text-domain'),
        'gamesSpeakingPromptText' => __('Say the word for this prompt.', 'll-tools-text-domain'),
        'gamesSpeakingReady' => __('Get ready...', 'll-tools-text-domain'),
        'gamesSpeakingListening' => __('Listening...', 'll-tools-text-domain'),
        'gamesSpeakingProcessing' => __('Transcribing...', 'll-tools-text-domain'),
        'gamesSpeakingMatching' => __('Matching your audio...', 'll-tools-text-domain'),
        'gamesSpeakingStartButton' => __('Start', 'll-tools-text-domain'),
        'gamesSpeakingResultRight' => __('Correct', 'll-tools-text-domain'),
        'gamesSpeakingResultClose' => __('Close', 'll-tools-text-domain'),
        'gamesSpeakingResultWrong' => __('Try again', 'll-tools-text-domain'),
        'gamesSpeakingTranscriptLabel' => __('You said', 'll-tools-text-domain'),
        'gamesSpeakingTargetLabel' => __('Target', 'll-tools-text-domain'),
        'gamesSpeakingTitleLabel' => __('Word', 'll-tools-text-domain'),
        'gamesSpeakingIpaLabel' => __('IPA', 'll-tools-text-domain'),
        'gamesSpeakingScoreLabel' => __('Similarity', 'll-tools-text-domain'),
        'gamesSpeakingRetry' => __('Retry', 'll-tools-text-domain'),
        'gamesSpeakingNext' => __('Next', 'll-tools-text-domain'),
        'gamesSpeakingPlayCorrect' => __('Hear correct audio', 'll-tools-text-domain'),
        'gamesSpeakingMicError' => __('Microphone access failed.', 'll-tools-text-domain'),
        'gamesSpeakingSttError' => __('Transcription failed. Try again.', 'll-tools-text-domain'),
        'gamesSpeakingTooQuiet' => __('That was too quiet. Try again.', 'll-tools-text-domain'),
        'gamesSpeakingNotSupported' => __('This browser cannot record audio for speaking practice.', 'll-tools-text-domain'),
        'gamesSpeakingDoneTitle' => __('Speaking round complete', 'll-tools-text-domain'),
        'gamesSpeakingDoneSummary' => __('Right: %1$d · Close: %2$d · Wrong: %3$d', 'll-tools-text-domain'),
        'gamesSpeakingStackProgress' => __('%1$d left', 'll-tools-text-domain'),
        'gamesSpeakingStackReady' => __('Mic ready', 'll-tools-text-domain'),
        'gamesSpeakingStackListening' => __('Listening for the next word...', 'll-tools-text-domain'),
        'gamesSpeakingStackProcessing' => __('Checking your word...', 'll-tools-text-domain'),
        'gamesSpeakingStackMatching' => __('Matching your audio...', 'll-tools-text-domain'),
        'gamesSpeakingStackTooQuiet' => __('No clear word detected.', 'll-tools-text-domain'),
        'gamesSpeakingStackNoMatch' => __('No match yet.', 'll-tools-text-domain'),
        'gamesSpeakingStackMicError' => __('Microphone access failed.', 'll-tools-text-domain'),
        'gamesSpeakingStackHeardLabel' => __('Heard', 'll-tools-text-domain'),
        'gamesSpeakingStackWinTitle' => __('You cleared the stack', 'll-tools-text-domain'),
        'gamesSpeakingStackLoseStackedTitle' => __('The stack reached the top', 'll-tools-text-domain'),
        'gamesSpeakingStackLoseSilenceTitle' => __('Time ran out', 'll-tools-text-domain'),
        'gamesSpeakingStackSummary' => __('Cleared: %1$d of %2$d', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_page_render_hard_words_icon(string $class = ''): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M12 3.5L2.5 20.5H21.5L12 3.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path>'
        . '<line x1="12" y1="9" x2="12" y2="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>'
        . '<circle cx="12" cy="17" r="1.2" fill="currentColor"></circle>'
        . '</svg>';
}

function ll_tools_wordset_page_render_progress_icon(string $status, string $class = 'll-wordset-progress-pill__icon'): string {
    $status = sanitize_key($status);
    if ($status === 'starred') {
        return '<span class="' . esc_attr(trim($class . ' ll-wordset-progress-star-glyph-icon')) . '" aria-hidden="true"></span>';
    }

    $svg = '';
    if ($status === 'mastered') {
        $svg = '<svg viewBox="0 0 64 64" width="16" height="16" xmlns="http://www.w3.org/2000/svg">'
            . '<polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline>'
            . '</svg>';
    } elseif ($status === 'studied') {
        $svg = '<svg viewBox="0 0 64 64" width="16" height="16" xmlns="http://www.w3.org/2000/svg">'
            . '<circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle>'
            . '<path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path>'
            . '</svg>';
    } elseif ($status === 'new') {
        $svg = '<svg viewBox="0 0 64 64" width="16" height="16" xmlns="http://www.w3.org/2000/svg">'
            . '<line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>'
            . '<line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>'
            . '</svg>';
    } elseif ($status === 'hard') {
        $svg = '<svg viewBox="0 0 64 64" width="16" height="16" xmlns="http://www.w3.org/2000/svg">'
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

function ll_tools_wordset_page_render_progress_mobile_legend(): string {
    $items = [
        [
            'key' => 'mastered',
            'icon' => ll_tools_wordset_page_render_progress_icon('mastered', 'll-wordset-progress-mobile-legend__icon'),
            'label' => __('Learned', 'll-tools-text-domain'),
        ],
        [
            'key' => 'studied',
            'icon' => ll_tools_wordset_page_render_progress_icon('studied', 'll-wordset-progress-mobile-legend__icon'),
            'label' => __('In progress', 'll-tools-text-domain'),
        ],
        [
            'key' => 'new',
            'icon' => ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-mobile-legend__icon'),
            'label' => __('New', 'll-tools-text-domain'),
        ],
        [
            'key' => 'hard',
            'icon' => ll_tools_wordset_page_render_progress_icon('hard', 'll-wordset-progress-mobile-legend__icon'),
            'label' => __('Hard', 'll-tools-text-domain'),
        ],
        [
            'key' => 'seen',
            'icon' => ll_tools_wordset_page_render_unhide_icon('ll-wordset-progress-mobile-legend__icon'),
            'label' => __('Seen', 'll-tools-text-domain'),
        ],
        [
            'key' => 'wrong',
            'icon' => ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-mobile-legend__icon'),
            'label' => __('Wrong', 'll-tools-text-domain'),
        ],
    ];

    ob_start();
    ?>
    <div
        class="ll-wordset-progress-mobile-legend"
        data-ll-wordset-progress-mobile-legend
        aria-label="<?php echo esc_attr__('Word table key', 'll-tools-text-domain'); ?>">
        <span class="ll-wordset-progress-mobile-legend__title"><?php echo esc_html__('Key', 'll-tools-text-domain'); ?></span>
        <ul class="ll-wordset-progress-mobile-legend__items" role="list">
            <?php foreach ($items as $item) : ?>
                <li class="<?php echo esc_attr('ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--' . $item['key']); ?>">
                    <?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span class="ll-wordset-progress-mobile-legend__text"><?php echo esc_html($item['label']); ?></span>
                </li>
            <?php endforeach; ?>
            <li
                class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--part-of-speech"
                data-ll-wordset-progress-mobile-legend-pos
                hidden>
                <span class="ll-wordset-progress-mobile-legend__pos-badge" aria-hidden="true"><?php echo esc_html__('POS', 'll-tools-text-domain'); ?></span>
                <span class="ll-wordset-progress-mobile-legend__text" data-ll-wordset-progress-mobile-legend-pos-text></span>
            </li>
        </ul>
    </div>
    <?php

    return (string) ob_get_clean();
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

function ll_tools_wordset_page_selection_priority_focus(string $focus): string {
    $focus = sanitize_key($focus);
    return in_array($focus, ['new', 'studied', 'learned'], true) ? $focus : '';
}

function ll_tools_wordset_page_selection_priority_only_label(string $focus): string {
    $focus = ll_tools_wordset_page_selection_priority_focus($focus);
    if ($focus === 'new') {
        return __('New words only', 'll-tools-text-domain');
    }
    if ($focus === 'studied') {
        return __('In progress only', 'll-tools-text-domain');
    }
    if ($focus === 'learned') {
        return __('Learned only', 'll-tools-text-domain');
    }
    return __('Priority words only', 'll-tools-text-domain');
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

function ll_tools_wordset_page_collect_category_starred_lookup_from_analytics_words(array $analytics_word_rows): array {
    $category_starred_word_lookup = [];
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

    $category_starred_lookup = [];
    foreach ($category_starred_word_lookup as $word_category_id => $word_lookup) {
        $category_starred_lookup[(int) $word_category_id] = count((array) $word_lookup);
    }

    return $category_starred_lookup;
}

function ll_tools_wordset_page_collect_category_starred_lookup_from_study_state(array $study_state, int $wordset_id, array $allowed_category_ids = []): array {
    global $wpdb;

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || !($wpdb instanceof wpdb)) {
        return [];
    }

    $starred_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($study_state['starred_word_ids'] ?? [])), static function ($id): bool {
        return $id > 0;
    })));
    if (empty($starred_word_ids)) {
        return [];
    }

    $allowed_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $allowed_category_ids), static function ($id): bool {
        return $id > 0;
    })));
    if (empty($allowed_category_ids)) {
        return [];
    }

    $word_placeholders = implode(',', array_fill(0, count($starred_word_ids), '%d'));
    $category_placeholders = implode(',', array_fill(0, count($allowed_category_ids), '%d'));
    $sql = "SELECT DISTINCT c.object_id AS word_id, ctt.term_id AS category_id
        FROM {$wpdb->term_relationships} AS c
        INNER JOIN {$wpdb->term_taxonomy} AS ctt ON ctt.term_taxonomy_id = c.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} AS w ON w.object_id = c.object_id
        INNER JOIN {$wpdb->term_taxonomy} AS wtt ON wtt.term_taxonomy_id = w.term_taxonomy_id
        INNER JOIN {$wpdb->posts} AS p ON p.ID = c.object_id
        WHERE ctt.taxonomy = 'word-category'
            AND wtt.taxonomy = 'wordset'
            AND wtt.term_id = %d
            AND c.object_id IN ($word_placeholders)
            AND ctt.term_id IN ($category_placeholders)
            AND p.post_type = 'words'
            AND p.post_status = 'publish'";

    $prepared = $wpdb->prepare(
        $sql,
        array_merge([$wordset_id], $starred_word_ids, $allowed_category_ids)
    );
    if (!is_string($prepared) || $prepared === '') {
        return [];
    }

    $rows = $wpdb->get_results($prepared, ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $category_starred_lookup = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $category_id = isset($row['category_id']) ? (int) $row['category_id'] : 0;
        if ($category_id <= 0) {
            continue;
        }
        $category_starred_lookup[$category_id] = ($category_starred_lookup[$category_id] ?? 0) + 1;
    }

    return $category_starred_lookup;
}

function ll_tools_wordset_page_collect_category_starred_lookup(array $analytics, array $study_state, int $wordset_id, array $allowed_category_ids = []): array {
    $analytics_words_loaded = array_key_exists('words', $analytics) && is_array($analytics['words']);
    if ($analytics_words_loaded) {
        return ll_tools_wordset_page_collect_category_starred_lookup_from_analytics_words((array) $analytics['words']);
    }

    return ll_tools_wordset_page_collect_category_starred_lookup_from_study_state($study_state, $wordset_id, $allowed_category_ids);
}

function ll_tools_wordset_page_enqueue_scripts(): void {
    if (function_exists('ll_tools_enqueue_confetti_asset')) {
        ll_tools_enqueue_confetti_asset();
    }
    $view = ll_tools_get_wordset_page_view();
    $deps = ['jquery'];

    if ($view === 'games') {
        ll_enqueue_asset_by_timestamp('/js/flashcard-widget/option-conflicts.js', 'll-tools-option-conflicts', [], true);
        ll_enqueue_asset_by_timestamp('/js/flashcard-widget/audio.js', 'll-wordset-games-audio', ['jquery'], true);
        ll_enqueue_asset_by_timestamp('/js/flashcard-widget/progress-tracker.js', 'll-wordset-games-progress-tracker', ['jquery'], true);
        ll_enqueue_asset_by_timestamp(
            '/js/wordset-games.js',
            'll-wordset-games-js',
            ['jquery', 'll-tools-option-conflicts', 'll-wordset-games-audio', 'll-wordset-games-progress-tracker'],
            true
        );
        $deps[] = 'll-wordset-games-js';
    }

    ll_enqueue_asset_by_timestamp('/js/locale-sort.js', 'll-tools-locale-sort', [], true);
    $deps[] = 'll-tools-locale-sort';
    ll_enqueue_asset_by_timestamp('/js/wordset-pages.js', 'll-wordset-pages-js', $deps, true);
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

/**
 * Shared front-end utility navigation used across wordset/recorder/editor pages.
 *
 * @param array $args {
 *     @type string       $current_area  Active area key: wordset|wordset_settings|editor_hub|recorder.
 *     @type int|WP_Term  $wordset       Optional wordset context for Word Set / Manage Word Set links.
 *     @type string       $current_url   Optional current URL for login/logout redirect.
 * }
 * @return string
 */
function ll_tools_render_frontend_user_utility_menu(array $args = []): string {
    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/css/frontend-utility-menu.css', 'll-tools-frontend-utility-menu-css');
        ll_enqueue_asset_by_timestamp('/js/frontend-utility-menu.js', 'll-tools-frontend-utility-menu-js', [], true);
    }

    $plugin_update_check_flash_arg_provided = array_key_exists('plugin_update_check_flash', $args);
    $plugin_update_check_flash_arg = $plugin_update_check_flash_arg_provided
        ? trim((string) $args['plugin_update_check_flash'])
        : '';

    $args = wp_parse_args($args, [
        'current_area' => '',
        'wordset' => null,
        'current_url' => '',
    ]);

    $current_area = sanitize_key((string) $args['current_area']);
    $wordset_term = null;
    if (!empty($args['wordset']) && function_exists('ll_tools_resolve_wordset_term')) {
        $resolved_wordset = ll_tools_resolve_wordset_term($args['wordset']);
        if ($resolved_wordset && !is_wp_error($resolved_wordset)) {
            $wordset_term = $resolved_wordset;
        }
    }

    $current_url = (string) $args['current_url'];
    if ($current_url === '' && function_exists('ll_tools_get_current_request_url')) {
        $current_url = (string) ll_tools_get_current_request_url();
    }
    if ($current_url === '' && function_exists('ll_tools_wordset_page_current_url')) {
        $current_url = (string) ll_tools_wordset_page_current_url();
    }
    if ($current_url === '') {
        $current_url = home_url('/');
    }

    $user = is_user_logged_in() ? wp_get_current_user() : null;
    $user_display = '';
    $user_login = '';
    $user_roles = [];
    $is_admin_user = false;
    if ($user instanceof WP_User) {
        $user_display = trim((string) $user->display_name);
        if ($user_display === '') {
            $user_display = (string) $user->user_login;
        }
        $user_login = (string) $user->user_login;
        $user_roles = array_map('strval', (array) $user->roles);
        $is_admin_user = user_can($user, 'manage_options');
    }

    $current_wordset_term = $wordset_term;
    $scoped_wordset_term = null;
    $managed_wordset_term = null;
    $plugin_update_check_flash = $plugin_update_check_flash_arg;
    if (
        !$plugin_update_check_flash_arg_provided
        && function_exists('ll_tools_user_can_manage_plugin_updates')
        && ll_tools_user_can_manage_plugin_updates()
        && function_exists('ll_tools_consume_plugin_update_check_flash')
    ) {
        $plugin_update_check_flash = (string) ll_tools_consume_plugin_update_check_flash();
    }
    if ($user instanceof WP_User) {
        $resolve_first_wordset_term = static function (array $term_ids) {
            foreach ($term_ids as $term_id) {
                $term_id = (int) $term_id;
                if ($term_id <= 0) {
                    continue;
                }
                $term = get_term($term_id, 'wordset');
                if ($term instanceof WP_Term && !is_wp_error($term)) {
                    return $term;
                }
            }
            return null;
        };

        if (function_exists('ll_tools_get_user_managed_wordset_ids')) {
            $managed_ids = ll_tools_get_user_managed_wordset_ids((int) $user->ID);
            $managed_wordset_term = $resolve_first_wordset_term((array) $managed_ids);
        }

        if (
            !($managed_wordset_term instanceof WP_Term)
            && $current_wordset_term instanceof WP_Term
            && in_array('wordset_manager', $user_roles, true)
        ) {
            $can_manage_current_wordset = function_exists('ll_tools_user_can_manage_wordset_content')
                ? ll_tools_user_can_manage_wordset_content((int) $current_wordset_term->term_id, (int) $user->ID)
                : false;
            if ($can_manage_current_wordset) {
                $managed_wordset_term = $current_wordset_term;
            }
        }

        if ($managed_wordset_term instanceof WP_Term) {
            $scoped_wordset_term = $managed_wordset_term;
        } elseif (function_exists('ll_tools_get_assigned_recorder_wordset_ids_for_user')) {
            $recorder_assigned_ids = ll_tools_get_assigned_recorder_wordset_ids_for_user((int) $user->ID);
            $scoped_wordset_term = $resolve_first_wordset_term((array) $recorder_assigned_ids);
        }
    }

    $links = [];
    if ($user instanceof WP_User) {
        if (function_exists('ll_tools_editor_hub_user_can_access') && ll_tools_editor_hub_user_can_access() && function_exists('ll_get_editor_hub_redirect_url')) {
            $editor_hub_url = (string) ll_get_editor_hub_redirect_url((int) $user->ID);
            if ($editor_hub_url !== '') {
                $links[] = [
                    'label' => __('Editor Hub', 'll-tools-text-domain'),
                    'url' => $editor_hub_url,
                    'is_active' => ($current_area === 'editor_hub'),
                ];
            }
        }

        $show_recorder_menu_link = $is_admin_user || in_array('audio_recorder', $user_roles, true);
        if ($show_recorder_menu_link && function_exists('ll_tools_user_can_record') && ll_tools_user_can_record() && function_exists('ll_get_recording_redirect_url')) {
            $recording_url = (string) ll_get_recording_redirect_url((int) $user->ID);
            if ($recording_url !== '') {
                $links[] = [
                    'label' => __('Recorder', 'll-tools-text-domain'),
                    'url' => $recording_url,
                    'is_active' => ($current_area === 'recorder'),
                ];
            }
        }

        if (
            $is_admin_user
            && function_exists('ll_tools_user_can_manage_plugin_updates')
            && ll_tools_user_can_manage_plugin_updates()
            && function_exists('ll_tools_get_plugin_update_check_action_url')
        ) {
            $plugin_update_check_nav_url = (string) ll_tools_get_plugin_update_check_action_url($current_url);
            if ($plugin_update_check_nav_url !== '') {
                $links[] = [
                    'label' => $plugin_update_check_flash === 'up_to_date'
                        ? __('Up to date', 'll-tools-text-domain')
                        : __('Check updates', 'll-tools-text-domain'),
                    'url' => $plugin_update_check_nav_url,
                    'is_active' => false,
                    'force_hard_nav' => true,
                    'is_success' => ($plugin_update_check_flash === 'up_to_date'),
                    'aria_label' => $plugin_update_check_flash === 'up_to_date'
                        ? __('Plugin is up to date', 'll-tools-text-domain')
                        : '',
                ];
            }
        }

        if ($scoped_wordset_term instanceof WP_Term && function_exists('ll_tools_get_wordset_page_view_url')) {
            $wordset_url = (string) ll_tools_get_wordset_page_view_url($scoped_wordset_term);
            $wordset_button_active = in_array($current_area, ['wordset', 'wordset_progress', 'wordset_hidden', 'wordset_games'], true)
                && ($current_wordset_term instanceof WP_Term)
                && ((int) $current_wordset_term->term_id === (int) $scoped_wordset_term->term_id);
            if ($wordset_url !== '') {
                $links[] = [
                    'label' => $scoped_wordset_term->name,
                    'url' => $wordset_url,
                    'is_active' => $wordset_button_active,
                ];
            }
        }

        $manage_link_term = null;
        if ($managed_wordset_term instanceof WP_Term) {
            $manage_link_term = $managed_wordset_term;
        } elseif ($current_wordset_term instanceof WP_Term) {
            $can_manage_current_wordset = function_exists('ll_tools_user_can_manage_wordset_content')
                ? ll_tools_user_can_manage_wordset_content((int) $current_wordset_term->term_id, (int) $user->ID)
                : user_can($user, 'manage_options');
            if ($can_manage_current_wordset) {
                $manage_link_term = $current_wordset_term;
            }
        }

        if ($manage_link_term instanceof WP_Term && function_exists('ll_tools_get_wordset_page_view_url')) {
            $wordset_settings_url = (string) ll_tools_get_wordset_page_view_url($manage_link_term, 'settings');
            $manage_link_active = ($current_area === 'wordset_settings')
                && ($current_wordset_term instanceof WP_Term)
                && ((int) $current_wordset_term->term_id === (int) $manage_link_term->term_id);
            if ($wordset_settings_url !== '') {
                $links[] = [
                    'label' => sprintf(
                        /* translators: %s: word set name */
                        __('Manage %s', 'll-tools-text-domain'),
                        $manage_link_term->name
                    ),
                    'url' => $wordset_settings_url,
                    'is_active' => $manage_link_active,
                ];
            }
        }
    }

    $login_url = function_exists('ll_tools_get_frontend_auth_url')
        ? ll_tools_get_frontend_auth_url($current_url, 'login')
        : wp_login_url($current_url);
    $signup_available = function_exists('ll_tools_is_learner_self_registration_available')
        ? ll_tools_is_learner_self_registration_available()
        : false;
    $signup_url = ($signup_available && function_exists('ll_tools_get_frontend_auth_url'))
        ? ll_tools_get_frontend_auth_url($current_url, 'register')
        : '';
    $logout_url = wp_logout_url($current_url);

    $context_class = '';
    if ($current_area !== '') {
        $context_class = ' ll-wordset-utility-bar--context-' . sanitize_html_class($current_area);
    }

    $guest_class = '';
    if (!($user instanceof WP_User)) {
        $guest_class = ' ll-wordset-utility-bar--guest';
    }

    ob_start();
    ?>
    <nav class="<?php echo esc_attr('ll-wordset-utility-bar ll-wordset-utility-bar--shared' . $context_class . $guest_class); ?>" aria-label="<?php echo esc_attr__('User menu', 'll-tools-text-domain'); ?>">
        <div class="ll-wordset-utility-bar__identity">
            <?php if ($user instanceof WP_User) : ?>
                <span class="ll-wordset-utility-bar__identity-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" focusable="false" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-3.5 3.5-6 8-6s8 2.5 8 6v1H4v-1z"/>
                    </svg>
                </span>
                <span class="screen-reader-text"><?php echo esc_html__('Signed in', 'll-tools-text-domain'); ?></span>
                <span class="ll-wordset-utility-bar__identity-name"><?php echo esc_html($user_display); ?></span>
                <?php if ($user_login !== '' && $user_login !== $user_display) : ?>
                    <span class="ll-wordset-utility-bar__identity-login"><?php echo esc_html('@' . $user_login); ?></span>
                <?php endif; ?>
                <a class="ll-wordset-utility-bar__identity-logout" href="<?php echo esc_url($logout_url); ?>" aria-label="<?php echo esc_attr__('Log out', 'll-tools-text-domain'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M14 7V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11 12h9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M17 9l3 3-3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="screen-reader-text"><?php echo esc_html__('Log out', 'll-tools-text-domain'); ?></span>
                </a>
            <?php else : ?>
                <span class="ll-wordset-utility-bar__identity-label"><?php echo esc_html__('Guest', 'll-tools-text-domain'); ?></span>
            <?php endif; ?>
        </div>

        <div class="ll-wordset-utility-bar__links">
            <?php if ($user instanceof WP_User) : ?>
                <?php foreach ($links as $link) : ?>
                    <?php
                    if (!is_array($link)) {
                        continue;
                    }
                    $link_label = (string) ($link['label'] ?? '');
                    $link_url = (string) ($link['url'] ?? '');
                    if ($link_label === '' || $link_url === '') {
                        continue;
                    }
                    $is_active = !empty($link['is_active']);
                    $force_hard_nav = !empty($link['force_hard_nav']);
                    $is_success = !empty($link['is_success']);
                    $aria_label = isset($link['aria_label']) ? (string) $link['aria_label'] : '';
                    ?>
                    <a
                        class="ll-wordset-utility-bar__link<?php echo $is_active ? ' is-active' : ''; ?><?php echo $is_success ? ' is-success' : ''; ?>"
                        href="<?php echo esc_url($link_url); ?>"
                        <?php if ($force_hard_nav) : ?>data-ll-force-hard-nav="1"<?php endif; ?>
                        <?php if ($aria_label !== '') : ?>aria-label="<?php echo esc_attr($aria_label); ?>"<?php endif; ?>
                        <?php if ($is_active) : ?>aria-current="page"<?php endif; ?>>
                        <?php if ($is_success) : ?>
                            <span class="ll-wordset-utility-bar__link-icon ll-wordset-utility-bar__link-icon--check" aria-hidden="true">
                                <svg viewBox="0 0 16 16" width="10" height="10" focusable="false" aria-hidden="true">
                                    <path d="M3.5 8.25 6.4 11.1 12.5 5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                            </span>
                        <?php endif; ?>
                        <?php echo esc_html($link_label); ?>
                    </a>
                <?php endforeach; ?>
            <?php else : ?>
                <a class="ll-wordset-utility-bar__link" href="<?php echo esc_url($login_url); ?>">
                    <?php echo esc_html__('Log in', 'll-tools-text-domain'); ?>
                </a>
                <?php if ($signup_url !== '') : ?>
                    <a class="ll-wordset-utility-bar__link" href="<?php echo esc_url($signup_url); ?>">
                        <?php echo esc_html__('Sign up', 'll-tools-text-domain'); ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_get_wordset_settings_tool_keys(): array {
    return ['study', 'language', 'visibility', 'import', 'template', 'recorder', 'transcription', 'offline-app', 'image-upload', 'audio-upload'];
}

function ll_tools_get_wordset_settings_tool(): string {
    $raw = '';
    if (isset($_GET['ll_wordset_tool'])) {
        $raw = (string) wp_unslash($_GET['ll_wordset_tool']);
    } elseif (isset($_POST['ll_wordset_tool'])) {
        $raw = (string) wp_unslash($_POST['ll_wordset_tool']);
    }

    $tool = sanitize_key($raw);
    return in_array($tool, ll_tools_get_wordset_settings_tool_keys(), true) ? $tool : '';
}

function ll_tools_get_wordset_settings_tool_url(WP_Term $wordset_term, string $tool = '', string $back_url = ''): string {
    $url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'settings'),
        $back_url
    );

    $tool = sanitize_key($tool);
    if ($tool !== '' && in_array($tool, ll_tools_get_wordset_settings_tool_keys(), true)) {
        return (string) add_query_arg('ll_wordset_tool', $tool, $url);
    }

    return (string) remove_query_arg('ll_wordset_tool', $url);
}

function ll_tools_wordset_settings_tool_label(string $tool): string {
    $tool = sanitize_key($tool);
    if ($tool === 'study') {
        return __('Study', 'll-tools-text-domain');
    }
    if ($tool === 'language') {
        return __('Language', 'll-tools-text-domain');
    }
    if ($tool === 'visibility') {
        return __('Word Set', 'll-tools-text-domain');
    }
    if ($tool === 'import') {
        return __('Import', 'll-tools-text-domain');
    }
    if ($tool === 'template') {
        return __('Template', 'll-tools-text-domain');
    }
    if ($tool === 'recorder') {
        return __('Recorder', 'll-tools-text-domain');
    }
    if ($tool === 'transcription') {
        return __('Transcription', 'll-tools-text-domain');
    }
    if ($tool === 'offline-app') {
        return __('Offline App', 'll-tools-text-domain');
    }
    if ($tool === 'image-upload') {
        return __('Images', 'll-tools-text-domain');
    }
    if ($tool === 'audio-upload') {
        return __('Audio', 'll-tools-text-domain');
    }

    return __('Settings', 'll-tools-text-domain');
}

function ll_tools_wordset_settings_tool_title(string $tool): string {
    $tool = sanitize_key($tool);
    if ($tool === 'study') {
        return __('Study Settings', 'll-tools-text-domain');
    }
    if ($tool === 'language') {
        return __('Language Settings', 'll-tools-text-domain');
    }
    if ($tool === 'visibility') {
        return __('Word Set Settings', 'll-tools-text-domain');
    }
    if ($tool === 'import') {
        return __('Import Words', 'll-tools-text-domain');
    }
    if ($tool === 'template') {
        return __('Create Word Set From Template', 'll-tools-text-domain');
    }
    if ($tool === 'recorder') {
        return __('Recorder Access', 'll-tools-text-domain');
    }
    if ($tool === 'transcription') {
        return __('Transcription Settings', 'll-tools-text-domain');
    }
    if ($tool === 'offline-app') {
        return __('Offline App Export', 'll-tools-text-domain');
    }
    if ($tool === 'image-upload') {
        return __('Image Upload', 'll-tools-text-domain');
    }
    if ($tool === 'audio-upload') {
        return __('Audio Upload', 'll-tools-text-domain');
    }

    return __('Word Set Tools', 'll-tools-text-domain');
}

function ll_tools_wordset_settings_tool_description(string $tool): string {
    $tool = sanitize_key($tool);
    if ($tool === 'study') {
        return __('Practice preferences, learning modes, and next activities.', 'll-tools-text-domain');
    }
    if ($tool === 'language') {
        return __('Target language, translation labels, and recording text behavior.', 'll-tools-text-domain');
    }
    if ($tool === 'visibility') {
        return __('Public or private access for this word set.', 'll-tools-text-domain');
    }
    if ($tool === 'import') {
        return __('Create words quickly from pasted prompt and answer pairs.', 'll-tools-text-domain');
    }
    if ($tool === 'template') {
        return __('Copy this word set\'s isolated categories and images into a new word set.', 'll-tools-text-domain');
    }
    if ($tool === 'recorder') {
        return __('Assign and review audio recorder access for this word set.', 'll-tools-text-domain');
    }
    if ($tool === 'transcription') {
        return __('Choose whether lesson transcription uses AssemblyAI or a localhost model in your browser.', 'll-tools-text-domain');
    }
    if ($tool === 'offline-app') {
        return __('Export this word set as a standalone offline learner app bundle.', 'll-tools-text-domain');
    }
    if ($tool === 'image-upload') {
        return __('Upload images and optionally create draft words here.', 'll-tools-text-domain');
    }
    if ($tool === 'audio-upload') {
        return __('Upload audio and create or match word records.', 'll-tools-text-domain');
    }

    return '';
}

function ll_tools_wordset_page_render_settings_notice(array $notice): string {
    if (empty($notice['message'])) {
        return '';
    }

    $type = (($notice['type'] ?? 'error') === 'success') ? 'success' : 'error';
    $role = ($type === 'success') ? 'status' : 'alert';

    return '<div class="ll-wordset-progress-reset-notice ll-wordset-progress-reset-notice--' . esc_attr($type) . '" role="' . esc_attr($role) . '">' .
        esc_html((string) $notice['message']) .
        '</div>';
}

function ll_tools_wordset_page_render_settings_tool_icon(string $tool, string $class = 'll-wordset-settings-tool-card__icon-svg'): string {
    $tool = sanitize_key($tool);

    if ($tool === 'study') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M5 6.5A2.5 2.5 0 0 1 7.5 4H19v14H7.5A2.5 2.5 0 0 0 5 20.5v-14Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
            . '<path d="M8.5 8.5h7M8.5 12h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M5 20.5A2.5 2.5 0 0 1 7.5 18H19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';
    }
    if ($tool === 'language') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M12 3.75a8.25 8.25 0 1 0 0 16.5 8.25 8.25 0 0 0 0-16.5Z" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M8.75 7.25h6.5M8.75 16.75h6.5M6.75 12h10.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M11.75 3.95c-1.7 2.1-2.5 5.05-2.5 8.05 0 3.02.8 5.95 2.5 8.05M12.25 3.95c1.7 2.1 2.5 5.05 2.5 8.05 0 3.02-.8 5.95-2.5 8.05" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';
    }
    if ($tool === 'visibility') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M12 3.75 4.5 7v4.9c0 4.4 3 8.5 7.5 9.85 4.5-1.35 7.5-5.45 7.5-9.85V7L12 3.75Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
            . '<path d="M9.25 12 11 13.75 14.75 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }
    if ($tool === 'import') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M12 4v10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="m8.5 10.5 3.5 3.5 3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M5 16.5v1.75A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V16.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }
    if ($tool === 'template') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M8 5.75h8a2.25 2.25 0 0 1 2.25 2.25v8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<rect x="5.75" y="8" width="10.5" height="10.25" rx="2" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M17.5 5v4.5M15.25 7.25h4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';
    }
    if ($tool === 'recorder') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<rect x="9" y="3.75" width="6" height="10.5" rx="3" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M6.75 10.5a5.25 5.25 0 1 0 10.5 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M12 15.75V20.25" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M9 20.25h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';
    }
    if ($tool === 'transcription') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M4.5 12.5h2.5M8.5 9.5h2.5M8.5 15.5h2.5M12.5 6.5h2.5M12.5 12.5h2.5M12.5 18.5h2.5M16.5 9.5h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<rect x="3.75" y="4.25" width="16.5" height="15.5" rx="3" stroke="currentColor" stroke-width="1.8"/>'
            . '</svg>';
    }
    if ($tool === 'offline-app') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<rect x="7.25" y="3.75" width="9.5" height="16.5" rx="2.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M10 6.75h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M12 15.25V9.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="m9.75 13 2.25 2.25L14.25 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="12" cy="18" r="0.9" fill="currentColor"/>'
            . '</svg>';
    }
    if ($tool === 'image-upload') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<rect x="3.75" y="4.75" width="16.5" height="14.5" rx="2.25" stroke="currentColor" stroke-width="1.8"/>'
            . '<circle cx="9" cy="10" r="1.5" fill="currentColor"/>'
            . '<path d="m6.5 17 3.5-3.5 2.25 2.25 2.75-3 2.5 4.25" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }
    if ($tool === 'audio-upload') {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M6 9.5v5M9 7.5v9M12 5.5v13M15 7.5v9M18 9.5v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M4.5 19.25h15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';
    }

    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/>'
        . '</svg>';
}

function ll_tools_wordset_page_render_settings_hub(array $cards): string {
    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--hub" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-lead">
            <p class="ll-wordset-settings-lead__text"><?php echo esc_html__('Choose a tool for this word set.', 'll-tools-text-domain'); ?></p>
        </div>
        <div class="ll-wordset-settings-tool-grid">
            <?php foreach ($cards as $card) : ?>
                <?php
                if (!is_array($card)) {
                    continue;
                }
                $tool = sanitize_key((string) ($card['tool'] ?? ''));
                $label = (string) ($card['label'] ?? '');
                $description = (string) ($card['description'] ?? '');
                $status = (string) ($card['status'] ?? '');
                $url = (string) ($card['url'] ?? '');
                $enabled = !empty($card['enabled']) && $url !== '';
                $card_classes = 'll-wordset-settings-tool-card';
                if ($tool !== '') {
                    $card_classes .= ' ll-wordset-settings-tool-card--' . sanitize_html_class($tool);
                }
                if (!$enabled) {
                    $card_classes .= ' is-disabled';
                }
                ?>
                <?php if ($enabled) : ?>
                    <a class="<?php echo esc_attr($card_classes); ?>" href="<?php echo esc_url($url); ?>">
                        <span class="ll-wordset-settings-tool-card__icon" aria-hidden="true">
                            <?php echo ll_tools_wordset_page_render_settings_tool_icon($tool); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                        <span class="ll-wordset-settings-tool-card__content">
                            <span class="ll-wordset-settings-tool-card__title"><?php echo esc_html($label); ?></span>
                            <?php if ($description !== '') : ?>
                                <span class="ll-wordset-settings-tool-card__description"><?php echo esc_html($description); ?></span>
                            <?php endif; ?>
                            <?php if ($status !== '') : ?>
                                <span class="ll-wordset-settings-tool-card__status"><?php echo esc_html($status); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ll-wordset-settings-tool-card__arrow" aria-hidden="true">
                            <svg viewBox="0 0 16 16" width="16" height="16" focusable="false" aria-hidden="true">
                                <path d="M5.5 3.25 10.25 8 5.5 12.75" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                <?php else : ?>
                    <div class="<?php echo esc_attr($card_classes); ?>" aria-disabled="true">
                        <span class="ll-wordset-settings-tool-card__icon" aria-hidden="true">
                            <?php echo ll_tools_wordset_page_render_settings_tool_icon($tool); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                        <span class="ll-wordset-settings-tool-card__content">
                            <span class="ll-wordset-settings-tool-card__title"><?php echo esc_html($label); ?></span>
                            <?php if ($description !== '') : ?>
                                <span class="ll-wordset-settings-tool-card__description"><?php echo esc_html($description); ?></span>
                            <?php endif; ?>
                            <?php if ($status !== '') : ?>
                                <span class="ll-wordset-settings-tool-card__status"><?php echo esc_html($status); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_get_category_translation_source_labels(string $language, string $translation_language): array {
    $target_language_name = $language !== '' ? $language : __('target language', 'll-tools-text-domain');
    $translation_language_name = $translation_language !== '' ? $translation_language : __('translation language', 'll-tools-text-domain');

    return [
        /* translators: 1: source language label, 2: destination language label */
        'target' => sprintf(__('%1$s to %2$s', 'll-tools-text-domain'), $target_language_name, $translation_language_name),
        /* translators: 1: source language label, 2: destination language label */
        'translation' => sprintf(__('%1$s to %2$s', 'll-tools-text-domain'), $translation_language_name, $target_language_name),
    ];
}

function ll_tools_wordset_page_render_settings_language_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, array $settings): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'language', $back_url);
    $language = (string) ($settings['language'] ?? '');
    $translation_language = (string) ($settings['translation_language'] ?? '');
    $category_translation_enabled = !empty($settings['category_translation_enabled']);
    $category_translation_source = function_exists('ll_tools_sanitize_wordset_category_translation_source')
        ? ll_tools_sanitize_wordset_category_translation_source((string) ($settings['category_translation_source'] ?? 'target'))
        : sanitize_key((string) ($settings['category_translation_source'] ?? 'target'));
    $word_title_language_role = function_exists('ll_tools_sanitize_wordset_title_language_role')
        ? ll_tools_sanitize_wordset_title_language_role((string) ($settings['word_title_language_role'] ?? 'target'))
        : sanitize_key((string) ($settings['word_title_language_role'] ?? 'target'));
    $recording_transcription_mode = function_exists('ll_tools_sanitize_wordset_recording_transcription_mode')
        ? ll_tools_sanitize_wordset_recording_transcription_mode((string) ($settings['recording_transcription_mode'] ?? 'ipa'))
        : sanitize_key((string) ($settings['recording_transcription_mode'] ?? 'ipa'));
    $translation_source_labels = ll_tools_wordset_page_get_category_translation_source_labels($language, $translation_language);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Language and labels', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_settings_action" value="save" />
                <input type="hidden" name="ll_wordset_manager_settings_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="language" />
                <?php wp_nonce_field('ll_wordset_manager_settings_' . $wordset_id, 'll_wordset_manager_settings_nonce'); ?>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Word set languages', 'll-tools-text-domain'); ?></h3>
                    <div class="ll-wordset-settings-card__field-grid">
                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-language">
                                <span><?php echo esc_html__('Target language', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-language"
                                    type="text"
                                    name="wordset_language"
                                    class="regular-text ll-tools-settings-input"
                                    value="<?php echo esc_attr($language); ?>"
                                    required
                                />
                            </label>
                            <p class="description"><?php echo esc_html__('Enter the language being learned in this word set.', 'll-tools-text-domain'); ?></p>
                        </div>

                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-translation-language">
                                <span><?php echo esc_html__('Translation language', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-translation-language"
                                    type="text"
                                    name="ll_wordset_translation_language"
                                    class="regular-text ll-tools-settings-input"
                                    value="<?php echo esc_attr($translation_language); ?>"
                                />
                            </label>
                            <p class="description"><?php echo esc_html__('Enter the helper or known language used for translations in this word set.', 'll-tools-text-domain'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Storage and recording text', 'll-tools-text-domain'); ?></h3>
                    <div class="ll-wordset-settings-card__field-grid">
                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-title-language-role">
                                <span><?php echo esc_html__('Word title language', 'll-tools-text-domain'); ?></span>
                                <select id="ll-wordset-settings-title-language-role" name="ll_wordset_word_title_language_role" class="ll-tools-settings-select">
                                    <option value="target" <?php selected($word_title_language_role, 'target'); ?>><?php echo esc_html__('Target (language being learned)', 'll-tools-text-domain'); ?></option>
                                    <option value="translation" <?php selected($word_title_language_role, 'translation'); ?>><?php echo esc_html__('Translation (helper or known language)', 'll-tools-text-domain'); ?></option>
                                </select>
                            </label>
                            <p class="description"><?php echo esc_html__('Controls whether word post titles are stored in the target language or the translation language for this word set.', 'll-tools-text-domain'); ?></p>
                        </div>

                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-recording-transcription-mode">
                                <span><?php echo esc_html__('Recording pronunciation text', 'll-tools-text-domain'); ?></span>
                                <select id="ll-wordset-settings-recording-transcription-mode" name="ll_wordset_recording_transcription_mode" class="ll-tools-settings-select">
                                    <option value="ipa" <?php selected($recording_transcription_mode, 'ipa'); ?>><?php echo esc_html__('IPA (phonetic)', 'll-tools-text-domain'); ?></option>
                                    <option value="transliteration" <?php selected($recording_transcription_mode, 'transliteration'); ?>><?php echo esc_html__('Transliteration (romanized text)', 'll-tools-text-domain'); ?></option>
                                    <option value="transcription" <?php selected($recording_transcription_mode, 'transcription'); ?>><?php echo esc_html__('Transcription (other notation)', 'll-tools-text-domain'); ?></option>
                                </select>
                            </label>
                            <p class="description"><?php echo esc_html__('Choose how the secondary recording text field behaves in this word set. Transliteration is better for romanized systems that do not need IPA formatting.', 'll-tools-text-domain'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Category labels', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-enable-category-translation" class="ll-wordset-settings-card__checkbox-item">
                        <input
                            type="checkbox"
                            id="ll-wordset-settings-enable-category-translation"
                            name="ll_wordset_enable_category_translation"
                            value="1"
                            <?php checked($category_translation_enabled, true); ?>
                        />
                        <span><?php echo esc_html__('Enable translated category names for this word set.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <p class="description"><?php echo esc_html__('When enabled, learners can see category names from the translated category field.', 'll-tools-text-domain'); ?></p>

                    <div class="ll-wordset-settings-card__field">
                        <label for="ll-wordset-settings-category-translation-source">
                            <span><?php echo esc_html__('Translate category names from', 'll-tools-text-domain'); ?></span>
                            <select id="ll-wordset-settings-category-translation-source" name="ll_wordset_category_translation_source" class="ll-tools-settings-select">
                                <option value="target" <?php selected($category_translation_source, 'target'); ?>><?php echo esc_html($translation_source_labels['target']); ?></option>
                                <option value="translation" <?php selected($category_translation_source, 'translation'); ?>><?php echo esc_html($translation_source_labels['translation']); ?></option>
                            </select>
                        </label>
                        <p class="description"><?php echo esc_html__('Choose whether this word set’s category titles start in the target language or the translation language.', 'll-tools-text-domain'); ?></p>
                    </div>

                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Save Language Settings', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_visibility_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, string $wordset_visibility, bool $wordset_is_private): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility', $back_url);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Word Set', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_settings_action" value="save" />
                <input type="hidden" name="ll_wordset_manager_settings_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="visibility" />
                <?php wp_nonce_field('ll_wordset_manager_settings_' . $wordset_id, 'll_wordset_manager_settings_nonce'); ?>
                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Visibility', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-visibility" class="screen-reader-text"><?php echo esc_html__('Word set visibility', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-visibility" name="ll_wordset_visibility" class="ll-tools-settings-select" style="max-width: 260px;">
                        <option value="public" <?php selected($wordset_visibility, 'public'); ?>><?php echo esc_html__('Public', 'll-tools-text-domain'); ?></option>
                        <option value="private" <?php selected($wordset_visibility, 'private'); ?>><?php echo esc_html__('Private', 'll-tools-text-domain'); ?></option>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Private word sets are only visible to the assigned manager and administrators in this first pass.', 'll-tools-text-domain'); ?>
                    </p>
                    <p class="description" style="margin-top:0;">
                        <?php
                        echo esc_html(
                            $wordset_is_private
                                ? __('This word set is currently private.', 'll-tools-text-domain')
                                : __('This word set is currently public.', 'll-tools-text-domain')
                        );
                        ?>
                    </p>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Save Word Set Settings', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_transcription_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, array $transcription_settings, array $secondary_transcription_config): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'transcription', $back_url);
    $provider = ll_tools_sanitize_wordset_transcription_provider((string) ($transcription_settings['provider'] ?? ''));
    $local_endpoint = (string) ($transcription_settings['local_endpoint'] ?? ll_tools_get_default_local_transcription_endpoint());
    $local_target = ll_tools_sanitize_wordset_local_transcription_target((string) ($transcription_settings['target_field'] ?? 'recording_ipa'));
    $api_token = function_exists('ll_tools_get_wordset_transcription_api_token')
        ? ll_tools_get_wordset_transcription_api_token([$wordset_id], true)
        : '';
    $offline_stt_bundle_path = function_exists('ll_tools_get_wordset_offline_stt_bundle_path')
        ? ll_tools_get_wordset_offline_stt_bundle_path([$wordset_id], true)
        : '';
    $speaking_game_config = function_exists('ll_tools_get_wordset_speaking_game_config')
        ? ll_tools_get_wordset_speaking_game_config([$wordset_id], true)
        : [
            'enabled_flag' => false,
            'provider' => '',
            'target' => 'recording_text',
            'target_options' => [
                'recording_text' => ['label' => __('Written text', 'll-tools-text-domain')],
            ],
            'compatible' => true,
            'compatibility_message' => '',
        ];
    $speaking_enabled = !empty($speaking_game_config['enabled_flag']);
    $speaking_provider = ll_tools_sanitize_wordset_speaking_game_provider((string) ($speaking_game_config['provider'] ?? ''));
    $speaking_access = function_exists('ll_tools_get_wordset_speaking_game_access')
        ? ll_tools_get_wordset_speaking_game_access([$wordset_id], true)
        : 'learners';
    $speaking_access_options = function_exists('ll_tools_get_wordset_speaking_game_access_options')
        ? ll_tools_get_wordset_speaking_game_access_options()
        : [
            'learners' => ['label' => __('Any learner who can view this word set', 'll-tools-text-domain')],
            'managers' => ['label' => __('Managers and admins only', 'll-tools-text-domain')],
        ];
    $speaking_target = ll_tools_sanitize_wordset_speaking_game_target((string) ($speaking_game_config['target'] ?? 'recording_text'));
    $speaking_target_options = is_array($speaking_game_config['target_options'] ?? null)
        ? $speaking_game_config['target_options']
        : [];
    $speaking_assemblyai_profile = function_exists('ll_tools_get_wordset_speaking_game_assemblyai_profile')
        ? ll_tools_get_wordset_speaking_game_assemblyai_profile([$wordset_id], true)
        : 'wordset_language';
    $speaking_assemblyai_profile_options = function_exists('ll_tools_get_wordset_speaking_game_assemblyai_profile_options')
        ? ll_tools_get_wordset_speaking_game_assemblyai_profile_options()
        : [];
    $speaking_is_compatible = !isset($speaking_game_config['compatible']) || !empty($speaking_game_config['compatible']);
    $speaking_compatibility_message = trim((string) ($speaking_game_config['compatibility_message'] ?? ''));
    $secondary_label = trim((string) ($secondary_transcription_config['label'] ?? ''));
    if ($secondary_label === '') {
        $secondary_label = __('Secondary transcription field', 'll-tools-text-domain');
    }

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Transcription', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_settings_action" value="save" />
                <input type="hidden" name="ll_wordset_manager_settings_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="transcription" />
                <?php wp_nonce_field('ll_wordset_manager_settings_' . $wordset_id, 'll_wordset_manager_settings_nonce'); ?>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Lesson Transcription Provider', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-transcription-provider" class="screen-reader-text"><?php echo esc_html__('Lesson transcription provider', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-transcription-provider" name="ll_wordset_transcription_provider" class="ll-tools-settings-select" style="max-width: 320px;">
                        <option value="" <?php selected($provider, ''); ?>><?php echo esc_html__('Disabled', 'll-tools-text-domain'); ?></option>
                        <option value="assemblyai" <?php selected($provider, 'assemblyai'); ?>><?php echo esc_html__('AssemblyAI', 'll-tools-text-domain'); ?></option>
                        <option value="local_browser" <?php selected($provider, 'local_browser'); ?>><?php echo esc_html__('Local browser model', 'll-tools-text-domain'); ?></option>
                        <option value="hosted_api" <?php selected($provider, 'hosted_api'); ?>><?php echo esc_html__('Hosted STT API', 'll-tools-text-domain'); ?></option>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('AssemblyAI keeps the current server-side captions workflow. Local browser model sends lesson audio from this browser to a localhost service on the computer you are using right now. Hosted STT API sends lesson audio from WordPress to your own model endpoint.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Custom STT Output', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-local-target" class="screen-reader-text"><?php echo esc_html__('Local model output field', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-local-target" name="ll_wordset_local_transcription_target" class="ll-tools-settings-select" style="max-width: 320px;">
                        <option value="recording_ipa" <?php selected($local_target, 'recording_ipa'); ?>><?php echo esc_html(sprintf(__('Secondary transcription field (%s)', 'll-tools-text-domain'), $secondary_label)); ?></option>
                        <option value="recording_text" <?php selected($local_target, 'recording_text'); ?>><?php echo esc_html__('Recording text', 'll-tools-text-domain'); ?></option>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Use the secondary field when your local or hosted model returns IPA, transliteration, or another alternate transcription instead of normal word text.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Custom STT Endpoint', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-local-endpoint" class="screen-reader-text"><?php echo esc_html__('Local transcription endpoint URL', 'll-tools-text-domain'); ?></label>
                    <input
                        id="ll-wordset-settings-local-endpoint"
                        type="url"
                        name="ll_wordset_local_transcription_endpoint"
                        class="regular-text ll-tools-settings-input"
                        style="max-width: 420px;"
                        value="<?php echo esc_attr($local_endpoint); ?>"
                        placeholder="<?php echo esc_attr(ll_tools_get_default_local_transcription_endpoint()); ?>"
                        inputmode="url"
                        spellcheck="false"
                        autocomplete="off"
                    />
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Expected request: POST multipart/form-data with an "audio" file field. Expected response: JSON containing one of predicted_ipa, ipa, transcript, or text.', 'll-tools-text-domain'); ?>
                    </p>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('Local browser mode calls this URL from the browser. Hosted STT API mode calls this URL from WordPress server-side, which keeps any API token off the page.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Hosted API Token', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-api-token" class="screen-reader-text"><?php echo esc_html__('Hosted STT API token', 'll-tools-text-domain'); ?></label>
                    <input
                        id="ll-wordset-settings-api-token"
                        type="password"
                        name="ll_wordset_transcription_api_token"
                        class="regular-text ll-tools-settings-input"
                        style="max-width: 420px;"
                        value="<?php echo esc_attr($api_token); ?>"
                        placeholder="<?php echo esc_attr__('Optional bearer token for your hosted STT endpoint', 'll-tools-text-domain'); ?>"
                        spellcheck="false"
                        autocomplete="new-password"
                    />
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Optional but recommended for Hosted STT API mode. WordPress will send this token to your endpoint as both Authorization: Bearer and X-LL-Tools-Token.', 'll-tools-text-domain'); ?>
                    </p>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Save Transcription Settings', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Offline App STT Bundle', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-offline-stt-bundle" class="screen-reader-text"><?php echo esc_html__('Offline app STT bundle path', 'll-tools-text-domain'); ?></label>
                    <input
                        id="ll-wordset-settings-offline-stt-bundle"
                        type="text"
                        name="ll_wordset_offline_stt_bundle_path"
                        class="regular-text ll-tools-settings-input"
                        style="max-width: 520px;"
                        value="<?php echo esc_attr($offline_stt_bundle_path); ?>"
                        placeholder="<?php echo esc_attr__('Absolute path to the mobile-ready STT model folder or file', 'll-tools-text-domain'); ?>"
                        spellcheck="false"
                        autocomplete="off"
                    />
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Optional. When set, offline app exports will copy this model bundle into the Android app for this word set.', 'll-tools-text-domain'); ?>
                    </p>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('Use a mobile-ready model bundle here. Desktop training checkpoints can still be stored elsewhere, but Android needs a runtime-compatible model format.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Speaking Practice Game', 'll-tools-text-domain'); ?></h3>
                    <label style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <input type="checkbox" name="ll_wordset_speaking_game_enabled" value="1" <?php checked($speaking_enabled); ?> />
                        <span><?php echo esc_html__('Enable speaking practice in Games', 'll-tools-text-domain'); ?></span>
                    </label>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('This game shows a picture or prompt text, listens for the learner to say the word, runs STT, and compares the result to the chosen target.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Speaking Games Access', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-speaking-access" class="screen-reader-text"><?php echo esc_html__('Who can view speaking games', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-speaking-access" name="ll_wordset_speaking_game_access" class="ll-tools-settings-select" style="max-width: 360px;">
                        <?php foreach ($speaking_access_options as $access_key => $access_row) : ?>
                            <?php $access_label = is_array($access_row) ? (string) ($access_row['label'] ?? $access_key) : (string) $access_row; ?>
                            <option value="<?php echo esc_attr((string) $access_key); ?>" <?php selected($speaking_access, (string) $access_key); ?>><?php echo esc_html($access_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Users who do not match this access setting will not see speaking games on the Games page.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Speaking Game STT Provider', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-speaking-provider" class="screen-reader-text"><?php echo esc_html__('Speaking game STT provider', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-speaking-provider" name="ll_wordset_speaking_game_provider" class="ll-tools-settings-select" style="max-width: 320px;">
                        <option value="" <?php selected($speaking_provider, ''); ?>><?php echo esc_html__('Disabled', 'll-tools-text-domain'); ?></option>
                        <option value="audio_matcher" <?php selected($speaking_provider, 'audio_matcher'); ?>><?php echo esc_html__('Built-in audio matcher', 'll-tools-text-domain'); ?></option>
                        <option value="assemblyai" <?php selected($speaking_provider, 'assemblyai'); ?>><?php echo esc_html__('AssemblyAI', 'll-tools-text-domain'); ?></option>
                        <option value="local_browser" <?php selected($speaking_provider, 'local_browser'); ?>><?php echo esc_html__('Local browser model', 'll-tools-text-domain'); ?></option>
                        <option value="hosted_api" <?php selected($speaking_provider, 'hosted_api'); ?>><?php echo esc_html__('Hosted STT API', 'll-tools-text-domain'); ?></option>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Built-in audio matcher compares learner audio directly to each word\'s isolation recording (no STT transcript required). AssemblyAI transcribes both the learner audio and each word\'s saved isolation recording, then caches the saved recording transcript for reuse. Local browser model reuses the endpoint and output format above. Hosted STT API reuses the same endpoint, output format, and token, but routes the audio through WordPress server-side.', 'll-tools-text-domain'); ?>
                    </p>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('If speaking practice is enabled and no provider is selected, LL Tools now defaults to the built-in audio matcher.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Speaking Game AssemblyAI Language', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-speaking-assemblyai-profile" class="screen-reader-text"><?php echo esc_html__('Speaking game AssemblyAI language or model profile', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-speaking-assemblyai-profile" name="ll_wordset_speaking_game_assemblyai_profile" class="ll-tools-settings-select" style="max-width: 420px;">
                        <?php
                        $current_profile_group = '';
                        foreach ($speaking_assemblyai_profile_options as $profile_key => $profile_row) :
                            $profile_group = is_array($profile_row) ? trim((string) ($profile_row['group'] ?? '')) : '';
                            $profile_label = is_array($profile_row) ? (string) ($profile_row['label'] ?? $profile_key) : (string) $profile_row;
                            if ($profile_group !== $current_profile_group) {
                                if ($current_profile_group !== '') {
                                    echo '</optgroup>';
                                }
                                if ($profile_group !== '') {
                                    echo '<optgroup label="' . esc_attr($profile_group) . '">';
                                }
                                $current_profile_group = $profile_group;
                            }
                            ?>
                            <option value="<?php echo esc_attr((string) $profile_key); ?>" <?php selected($speaking_assemblyai_profile, (string) $profile_key); ?>><?php echo esc_html($profile_label); ?></option>
                        <?php endforeach; ?>
                        <?php if ($current_profile_group !== '') : ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('This only applies when the speaking game provider is AssemblyAI. LL Tools will use the same AssemblyAI mode for both the learner recording and the saved reference recording, then cache the saved transcript on the audio post for later attempts.', 'll-tools-text-domain'); ?>
                    </p>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('For short isolation clips, choosing a specific language is usually faster and more reliable than multilingual auto-detection.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Speaking Game Comparison Target', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-speaking-target" class="screen-reader-text"><?php echo esc_html__('Speaking game comparison target', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-settings-speaking-target" name="ll_wordset_speaking_game_target" class="ll-tools-settings-select" style="max-width: 320px;">
                        <?php foreach ($speaking_target_options as $target_key => $target_row) : ?>
                            <?php $target_label = is_array($target_row) ? (string) ($target_row['label'] ?? $target_key) : (string) $target_row; ?>
                            <option value="<?php echo esc_attr((string) $target_key); ?>" <?php selected($speaking_target, (string) $target_key); ?>><?php echo esc_html($target_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Written text matches the saved text on the word\'s isolation recording. IPA works with a model that returns the secondary transcription field. Cached reference STT compares the learner transcript to a cached transcript generated from the saved isolation audio with the same server-side STT provider.', 'll-tools-text-domain'); ?>
                    </p>
                    <?php if ($speaking_enabled && !$speaking_is_compatible && $speaking_compatibility_message !== '') : ?>
                        <p class="description" style="margin-top:0; color:#a12622;">
                            <?php echo esc_html($speaking_compatibility_message); ?>
                        </p>
                    <?php endif; ?>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Save Transcription Settings', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_import_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, array $enhanced_categories): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'import', $back_url);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Bulk Import', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_import_action" value="import_pairs" />
                <input type="hidden" name="ll_wordset_manager_import_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="import" />
                <?php wp_nonce_field('ll_wordset_manager_import_' . $wordset_id, 'll_wordset_manager_import_nonce'); ?>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Paste Prompt + Answer Pairs', 'll-tools-text-domain'); ?></h3>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('Quizlet-style tab-separated lines work best. Supported separators: tab, =>, ::, |', 'll-tools-text-domain'); ?>
                    </p>
                    <label for="ll-wordset-manager-import-pairs" class="screen-reader-text"><?php echo esc_html__('Prompt and answer pairs', 'll-tools-text-domain'); ?></label>
                    <textarea
                        id="ll-wordset-manager-import-pairs"
                        name="ll_wordset_manager_import_pairs"
                        rows="10"
                        style="width:100%;min-height:180px;"
                        placeholder="<?php echo esc_attr__("merhaba[TAB]hello\nnasilsin[TAB]how are you", 'll-tools-text-domain'); ?>"></textarea>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-manager-import-existing-category" class="screen-reader-text"><?php echo esc_html__('Existing category', 'll-tools-text-domain'); ?></label>
                    <select id="ll-wordset-manager-import-existing-category" name="ll_wordset_manager_import_existing_category" class="ll-tools-settings-select" style="max-width: 420px;">
                        <option value="0"><?php echo esc_html__('No category (optional)', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($enhanced_categories as $import_category) : ?>
                            <?php
                            if (!is_array($import_category)) {
                                continue;
                            }
                            $import_category_id = isset($import_category['id']) ? (int) $import_category['id'] : 0;
                            if ($import_category_id <= 0) {
                                continue;
                            }
                            $import_category_name = isset($import_category['translation']) && (string) $import_category['translation'] !== ''
                                ? (string) $import_category['translation']
                                : (string) ($import_category['name'] ?? '');
                            $import_category_hidden = !empty($import_category['hidden']);
                            $import_category_label = $import_category_name;
                            if ($import_category_hidden) {
                                $import_category_label .= ' (' . __('Hidden', 'll-tools-text-domain') . ')';
                            }
                            ?>
                            <option value="<?php echo esc_attr($import_category_id); ?>"><?php echo esc_html($import_category_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Or create a new category (new name takes priority):', 'll-tools-text-domain'); ?>
                    </p>
                    <label for="ll-wordset-manager-import-new-category" class="screen-reader-text"><?php echo esc_html__('New category name', 'll-tools-text-domain'); ?></label>
                    <input
                        type="text"
                        id="ll-wordset-manager-import-new-category"
                        name="ll_wordset_manager_import_new_category"
                        class="regular-text"
                        style="max-width:420px;width:100%;"
                        placeholder="<?php echo esc_attr__('New category name', 'll-tools-text-domain'); ?>" />
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Bulk import assumes text-to-text (prompt -> translation, answer -> word title) and publishes items by default.', 'll-tools-text-domain'); ?>
                    </p>
                    <label for="ll-wordset-manager-import-allow-duplicates" style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                        <input
                            type="checkbox"
                            id="ll-wordset-manager-import-allow-duplicates"
                            name="ll_wordset_manager_import_allow_duplicates"
                            value="1" />
                        <span><?php echo esc_html__('Allow duplicate titles in this word set (do not skip matches)', 'll-tools-text-domain'); ?></span>
                    </label>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Leave this off to skip duplicates and review the import count first.', 'll-tools-text-domain'); ?>
                    </p>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Import Words', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_template_tool(
    WP_Term $wordset_term,
    int $wordset_id,
    string $back_url,
    int $template_category_count,
    int $template_image_count
): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'template', $back_url);
    $has_template_content = ($template_category_count > 0 || $template_image_count > 0);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Create New Word Set', 'll-tools-text-domain'); ?></h2>
            <p class="description">
                <?php
                echo esc_html(sprintf(
                    __('This template currently includes %1$d categories and %2$d images. Words, audio, and learner progress are not copied.', 'll-tools-text-domain'),
                    $template_category_count,
                    $template_image_count
                ));
                ?>
            </p>

            <?php if (!$has_template_content) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('Add isolated categories or word images to this word set before using it as a template.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_template_action" value="create" />
                <input type="hidden" name="ll_wordset_manager_template_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="template" />
                <?php wp_nonce_field('ll_wordset_manager_template_' . $wordset_id, 'll_wordset_manager_template_nonce'); ?>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('New word set details', 'll-tools-text-domain'); ?></h3>
                    <div class="ll-wordset-settings-card__field-grid">
                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-manager-template-name">
                                <span><?php echo esc_html__('Word set name', 'll-tools-text-domain'); ?></span>
                                <input
                                    type="text"
                                    id="ll-wordset-manager-template-name"
                                    name="ll_wordset_manager_template_name"
                                    class="regular-text"
                                    style="max-width:420px;width:100%;"
                                    required
                                />
                            </label>
                        </div>
                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-manager-template-slug">
                                <span><?php echo esc_html__('Slug (optional)', 'll-tools-text-domain'); ?></span>
                                <input
                                    type="text"
                                    id="ll-wordset-manager-template-slug"
                                    name="ll_wordset_manager_template_slug"
                                    class="regular-text"
                                    style="max-width:420px;width:100%;"
                                    placeholder="<?php echo esc_attr__('new-word-set', 'll-tools-text-domain'); ?>"
                                />
                            </label>
                        </div>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Copy options', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-manager-template-copy-settings" style="display:flex;align-items:flex-start;gap:8px;">
                        <input
                            type="checkbox"
                            id="ll-wordset-manager-template-copy-settings"
                            name="ll_wordset_manager_template_copy_settings"
                            value="1"
                            checked
                        />
                        <span><?php echo esc_html__('Copy non-language word set settings too (visibility, study tools, category order, and transcription settings).', 'll-tools-text-domain'); ?></span>
                    </label>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Language, translation labels, and category translation settings are intentionally left blank on the new word set.', 'll-tools-text-domain'); ?>
                    </p>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button" <?php disabled(!$has_template_content); ?>><?php echo esc_html__('Create Word Set From Template', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_recorder_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, array $assigned_audio_recorders, array $available_audio_recorders): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder', $back_url);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Recorder Access', 'll-tools-text-domain'); ?></h2>

            <div class="ll-wordset-settings-card__group">
                <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Assigned Tutors / Recorders', 'll-tools-text-domain'); ?></h3>
                <?php if (empty($assigned_audio_recorders)) : ?>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('No audio recorder users are currently assigned to this word set.', 'll-tools-text-domain'); ?>
                    </p>
                <?php else : ?>
                    <div style="display:grid;gap:10px;">
                        <?php foreach ($assigned_audio_recorders as $assigned_recorder_user) : ?>
                            <?php
                            if (!$assigned_recorder_user instanceof WP_User) {
                                continue;
                            }
                            $assigned_user_id = (int) $assigned_recorder_user->ID;
                            $assigned_label = (string) $assigned_recorder_user->display_name;
                            if ($assigned_label === '') {
                                $assigned_label = (string) $assigned_recorder_user->user_login;
                            }
                            ?>
                            <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
                                <div>
                                    <strong><?php echo esc_html($assigned_label); ?></strong>
                                    <?php if (!empty($assigned_recorder_user->user_email)) : ?>
                                        <div class="description" style="margin-top:2px;"><?php echo esc_html((string) $assigned_recorder_user->user_email); ?></div>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin:0;">
                                    <input type="hidden" name="ll_wordset_manager_recorder_action" value="unassign" />
                                    <input type="hidden" name="ll_wordset_manager_recorder_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                                    <input type="hidden" name="ll_wordset_manager_recorder_user_id" value="<?php echo esc_attr($assigned_user_id); ?>" />
                                    <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                    <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                                    <input type="hidden" name="ll_wordset_view" value="settings" />
                                    <input type="hidden" name="ll_wordset_tool" value="recorder" />
                                    <?php wp_nonce_field('ll_wordset_manager_recorder_' . $wordset_id, 'll_wordset_manager_recorder_nonce'); ?>
                                    <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Unassign', 'll-tools-text-domain'); ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_recorder_action" value="assign" />
                <input type="hidden" name="ll_wordset_manager_recorder_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="recorder" />
                <?php wp_nonce_field('ll_wordset_manager_recorder_' . $wordset_id, 'll_wordset_manager_recorder_nonce'); ?>
                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Assign Audio Recorder User', 'll-tools-text-domain'); ?></h3>
                    <?php if (empty($available_audio_recorders)) : ?>
                        <p class="description" style="margin-top:0;">
                            <?php echo esc_html__('No users currently have the Audio Recorder role. Add the role to a user account first.', 'll-tools-text-domain'); ?>
                        </p>
                    <?php else : ?>
                        <label for="ll-wordset-manager-recorder-user" class="screen-reader-text"><?php echo esc_html__('Audio recorder user', 'll-tools-text-domain'); ?></label>
                        <select id="ll-wordset-manager-recorder-user" name="ll_wordset_manager_recorder_user_id" class="ll-tools-settings-select" style="max-width:420px;">
                            <option value=""><?php echo esc_html__('Select a recorder user', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($available_audio_recorders as $audio_recorder_user) : ?>
                                <?php
                                if (!$audio_recorder_user instanceof WP_User) {
                                    continue;
                                }
                                $audio_recorder_user_id = (int) $audio_recorder_user->ID;
                                $audio_recorder_label = (string) $audio_recorder_user->display_name;
                                if ($audio_recorder_label === '') {
                                    $audio_recorder_label = (string) $audio_recorder_user->user_login;
                                }
                                if (!empty($audio_recorder_user->user_email)) {
                                    $audio_recorder_label .= ' (' . (string) $audio_recorder_user->user_email . ')';
                                }
                                ?>
                                <option value="<?php echo esc_attr($audio_recorder_user_id); ?>"><?php echo esc_html($audio_recorder_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" style="margin-top:8px;">
                            <?php echo esc_html__('Assigning a recorder locks their recording interface to this word set (existing category filter is reset).', 'll-tools-text-domain'); ?>
                        </p>
                        <div style="margin-top:10px;">
                            <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Assign Recorder', 'll-tools-text-domain'); ?></button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_upload_tool(string $title, string $description, string $shortcode): string {
    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html($title); ?></h2>
            <div class="ll-wordset-settings-card__group">
                <p class="description" style="margin-top:0;">
                    <?php echo esc_html($description); ?>
                </p>
                <?php if ($shortcode !== '') : ?>
                    <?php echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php else : ?>
                    <p class="description" style="margin-top:0;">
                        <?php echo esc_html__('This upload tool is unavailable right now.', 'll-tools-text-domain'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_offline_app_tool(
    WP_Term $wordset_term,
    int $wordset_id,
    string $back_url,
    array $category_options,
    bool $zip_available
): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'offline-app', $back_url);
    $plugin_version = function_exists('ll_tools_get_plugin_version_string')
        ? ll_tools_get_plugin_version_string()
        : '1.0.0';
    $default_app_name = trim((string) get_bloginfo('name'));
    if ($default_app_name === '') {
        $default_app_name = (string) $wordset_term->name;
    } elseif (stripos($default_app_name, (string) $wordset_term->name) === false) {
        $default_app_name .= ' - ' . (string) $wordset_term->name;
    }
    $site_icon_payload = function_exists('ll_tools_offline_app_get_attachment_icon_payload')
        ? ll_tools_offline_app_get_attachment_icon_payload(
            function_exists('ll_tools_offline_app_get_site_icon_attachment_id')
                ? ll_tools_offline_app_get_site_icon_attachment_id()
                : 0
        )
        : [];
    $offline_stt_bundle_path = function_exists('ll_tools_get_wordset_offline_stt_bundle_path')
        ? ll_tools_get_wordset_offline_stt_bundle_path([$wordset_id], true)
        : '';
    $has_exportable_categories = !empty($category_options);
    $can_submit = ($zip_available && $has_exportable_categories);

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Offline app bundle', 'll-tools-text-domain'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Export this word set as a standalone learner app bundle with local study content, games, and optional offline speaking support.', 'll-tools-text-domain'); ?>
            </p>

            <?php if (!$zip_available) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('ZipArchive is not available on this server, so offline app exports cannot run yet.', 'll-tools-text-domain'); ?></p>
            <?php elseif (!$has_exportable_categories) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('This word set does not have any categories with published words available for offline export yet.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_offline_export_action" value="export" />
                <input type="hidden" name="ll_wordset_manager_offline_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="offline-app" />
                <input type="hidden" name="ll_offline_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <?php wp_nonce_field('ll_wordset_manager_offline_export_' . $wordset_id, 'll_wordset_manager_offline_nonce'); ?>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Categories', 'll-tools-text-domain'); ?></h3>
                    <p class="description"><?php echo esc_html__('All exportable categories are selected by default. Uncheck any categories you do not want to include in this offline app.', 'll-tools-text-domain'); ?></p>
                    <div class="ll-wordset-settings-card__checkbox-list" aria-label="<?php echo esc_attr__('Offline export categories', 'll-tools-text-domain'); ?>">
                        <?php foreach ($category_options as $category_option) : ?>
                            <?php
                            if (!is_array($category_option)) {
                                continue;
                            }
                            $category_id = (int) ($category_option['id'] ?? 0);
                            $category_name = (string) ($category_option['name'] ?? '');
                            if ($category_id <= 0 || $category_name === '') {
                                continue;
                            }
                            ?>
                            <label class="ll-wordset-settings-card__checkbox-item" for="<?php echo esc_attr('ll-wordset-offline-category-' . $category_id); ?>">
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr('ll-wordset-offline-category-' . $category_id); ?>"
                                    name="ll_offline_category_ids[]"
                                    value="<?php echo esc_attr($category_id); ?>"
                                    checked
                                />
                                <span><?php echo esc_html($category_name); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Bundle details', 'll-tools-text-domain'); ?></h3>
                    <div class="ll-wordset-settings-card__field-grid">
                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-offline-app-name">
                                <span><?php echo esc_html__('App name', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-offline-app-name"
                                    type="text"
                                    name="ll_offline_app_name"
                                    class="regular-text ll-tools-settings-input"
                                    value="<?php echo esc_attr($default_app_name); ?>"
                                    required
                                />
                            </label>
                            <p class="description"><?php echo esc_html__('Used in the offline app shell and Android build metadata.', 'll-tools-text-domain'); ?></p>
                        </div>

                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-offline-app-id-suffix">
                                <span><?php echo esc_html__('Android app ID suffix', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-offline-app-id-suffix"
                                    type="text"
                                    name="ll_offline_app_id_suffix"
                                    class="regular-text ll-tools-settings-input"
                                    value="offline.quiz"
                                />
                            </label>
                            <p class="description"><?php echo esc_html__('Letters, numbers, underscores, and dots are allowed. The exporter sanitizes this automatically.', 'll-tools-text-domain'); ?></p>
                        </div>

                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-offline-version-name">
                                <span><?php echo esc_html__('Version name', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-offline-version-name"
                                    type="text"
                                    name="ll_offline_version_name"
                                    class="regular-text ll-tools-settings-input"
                                    value="<?php echo esc_attr($plugin_version); ?>"
                                    required
                                />
                            </label>
                        </div>

                        <div class="ll-wordset-settings-card__field">
                            <label for="ll-wordset-settings-offline-version-code">
                                <span><?php echo esc_html__('Version code', 'll-tools-text-domain'); ?></span>
                                <input
                                    id="ll-wordset-settings-offline-version-code"
                                    type="number"
                                    name="ll_offline_version_code"
                                    class="small-text ll-tools-settings-input"
                                    min="1"
                                    step="1"
                                    value="1"
                                    required
                                />
                            </label>
                            <p class="description"><?php echo esc_html__('Increase this integer when distributing an updated Android build.', 'll-tools-text-domain'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Export notes', 'll-tools-text-domain'); ?></h3>
                    <div class="ll-wordset-settings-card__meta">
                        <span class="ll-wordset-settings-card__pill"><?php echo esc_html(!empty($site_icon_payload) ? __('Uses current site icon', 'll-tools-text-domain') : __('No site icon set', 'll-tools-text-domain')); ?></span>
                        <span class="ll-wordset-settings-card__pill"><?php echo esc_html($offline_stt_bundle_path !== '' ? __('Offline STT bundle configured', 'll-tools-text-domain') : __('No offline STT bundle configured', 'll-tools-text-domain')); ?></span>
                    </div>
                    <p class="description">
                        <?php
                        echo esc_html(
                            !empty($site_icon_payload)
                                ? __('This export uses the current site icon automatically. Advanced one-off icon overrides can stay in the dashboard exporter until we migrate that piece too.', 'll-tools-text-domain')
                                : __('No site icon is set for this site, so the Android builder keeps its default launcher icon unless you add one later.', 'll-tools-text-domain')
                        );
                        ?>
                    </p>
                    <p class="description"><?php echo esc_html__('The offline bundle includes the standalone shell plus bundled media for supported quiz modes. Learners can keep using the app locally, then link a site account later to sync study state and progress.', 'll-tools-text-domain'); ?></p>
                    <div style="margin-top:10px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button" <?php disabled(!$can_submit); ?>><?php echo esc_html__('Export Offline App', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_queue(array $enhanced_categories, array $recommendation_queue, array $mode_labels, array $mode_ui, array $mode_fallback_icons): string {
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

    ob_start();
    ?>
    <div class="ll-wordset-settings-card">
        <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Upcoming activities', 'll-tools-text-domain'); ?></h2>
        <ul class="ll-wordset-queue-list" data-ll-wordset-queue-list>
            <?php foreach ((array) $recommendation_queue as $queue_item) : ?>
                <?php
                if (!is_array($queue_item)) {
                    continue;
                }
                $queue_mode = isset($queue_item['mode']) ? (string) $queue_item['mode'] : 'practice';
                $queue_mode = in_array($queue_mode, ['learning', 'practice', 'listening', 'gender', 'self-check'], true) ? $queue_mode : 'practice';
                $queue_mode_label = $mode_labels[$queue_mode] ?? ucfirst($queue_mode);
                $queue_ids = array_values(array_filter(array_map('intval', (array) ($queue_item['category_ids'] ?? [])), static function ($id) {
                    return $id > 0;
                }));
                $queue_category_labels = [];
                $queue_preview_items = [];
                $queue_preview_seen = [];
                foreach ($queue_ids as $queue_cid) {
                    if (!isset($queue_category_lookup[$queue_cid]) || !is_array($queue_category_lookup[$queue_cid])) {
                        continue;
                    }
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
                $queue_word_count = count(array_values(array_filter(array_map('intval', (array) ($queue_item['session_word_ids'] ?? [])), static function ($id) {
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
                                    <img src="<?php echo esc_url($queue_preview_item['url']); ?>" alt="" loading="lazy" decoding="async" fetchpriority="low" />
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
    <?php

    return (string) ob_get_clean();
}

function ll_tools_wordset_page_render_settings_study_tool(array $args): string {
    $mode_ui = (isset($args['mode_ui']) && is_array($args['mode_ui'])) ? $args['mode_ui'] : [];
    $mode_fallback_icons = (isset($args['mode_fallback_icons']) && is_array($args['mode_fallback_icons'])) ? $args['mode_fallback_icons'] : [];
    $mode_labels = (isset($args['mode_labels']) && is_array($args['mode_labels'])) ? $args['mode_labels'] : [];
    $gender_mode_available = !empty($args['gender_mode_available']);
    $enhanced_categories = (isset($args['enhanced_categories']) && is_array($args['enhanced_categories'])) ? $args['enhanced_categories'] : [];
    $recommendation_queue = (isset($args['recommendation_queue']) && is_array($args['recommendation_queue'])) ? $args['recommendation_queue'] : [];
    $wordset_term = (isset($args['wordset_term']) && $args['wordset_term'] instanceof WP_Term) ? $args['wordset_term'] : null;
    $wordset_id = isset($args['wordset_id']) ? (int) $args['wordset_id'] : 0;
    $back_url = isset($args['back_url']) ? (string) $args['back_url'] : '';
    $can_manage_wordset_content = !empty($args['can_manage_wordset_content']);
    $autoplay_text_audio_answer_options = !empty($args['autoplay_text_audio_answer_options']);
    $hide_lesson_text_for_non_text_quiz = !empty($args['hide_lesson_text_for_non_text_quiz']);
    $study_settings_action_url = ($can_manage_wordset_content && $wordset_term instanceof WP_Term && $wordset_id > 0)
        ? ll_tools_get_wordset_settings_tool_url($wordset_term, 'study', $back_url)
        : '';

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
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
                if ($gender_mode_available) {
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

        <?php if ($study_settings_action_url !== '') : ?>
        <div class="ll-wordset-settings-card">
            <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Quiz display', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url($study_settings_action_url); ?>">
                <input type="hidden" name="ll_wordset_manager_settings_action" value="save" />
                <input type="hidden" name="ll_wordset_manager_settings_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <input type="hidden" name="ll_wordset_page" value="<?php echo esc_attr((string) $wordset_term->slug); ?>" />
                <input type="hidden" name="ll_wordset_view" value="settings" />
                <input type="hidden" name="ll_wordset_tool" value="study" />
                <?php wp_nonce_field('ll_wordset_manager_settings_' . $wordset_id, 'll_wordset_manager_settings_nonce'); ?>
                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Text + audio pairs', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-autoplay-text-audio-options">
                        <input
                            type="checkbox"
                            id="ll-wordset-settings-autoplay-text-audio-options"
                            name="ll_wordset_autoplay_text_audio_answer_options"
                            value="1"
                            <?php checked($autoplay_text_audio_answer_options, true); ?>
                        />
                        <?php echo esc_html__('Automatically play the audio for text + audio answer options during quizzes.', 'll-tools-text-domain'); ?>
                    </label>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('When this is off, learners can play text + audio answer options manually. Audio-only answer options still auto-play.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <h3 class="ll-wordset-settings-card__subtitle"><?php echo esc_html__('Lesson and word-grid text', 'll-tools-text-domain'); ?></h3>
                    <label for="ll-wordset-settings-hide-lesson-text" class="ll-wordset-settings-card__checkbox-item">
                        <input
                            type="checkbox"
                            id="ll-wordset-settings-hide-lesson-text"
                            name="ll_wordset_hide_lesson_text_for_non_text_quiz"
                            value="1"
                            <?php checked($hide_lesson_text_for_non_text_quiz, true); ?>
                        />
                        <span><?php echo esc_html__('Hide word text on lesson pages and word grids when the category quiz uses only images or audio.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <p class="description" style="margin-top:8px;">
                        <?php echo esc_html__('Categories can override this in their quiz settings.', 'll-tools-text-domain'); ?>
                    </p>
                </div>

                <div class="ll-wordset-settings-card__group">
                    <div style="margin-top:2px;">
                        <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button"><?php echo esc_html__('Save Study Settings', 'll-tools-text-domain'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php echo ll_tools_wordset_page_render_settings_queue($enhanced_categories, $recommendation_queue, $mode_labels, $mode_ui, $mode_fallback_icons); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </section>
    <?php

    return (string) ob_get_clean();
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
    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_term)) {
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
    $settings_navigation_back_url = $subpage_return_url;
    $progress_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'progress'),
        $subpage_return_url
    );
    $games_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'games'),
        $subpage_return_url
    );
    $hidden_categories_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'hidden-categories'),
        $subpage_return_url
    );
    $settings_url = ll_tools_wordset_page_with_back_url(
        ll_tools_get_wordset_page_view_url($wordset_term, 'settings'),
        $settings_navigation_back_url
    );
    $settings_tool = ($view === 'settings') ? ll_tools_get_wordset_settings_tool() : '';
    $back_url = ll_tools_wordset_page_resolve_back_url($wordset_term);
    $is_study_user = is_user_logged_in() && (!function_exists('ll_tools_user_study_can_access') || ll_tools_user_study_can_access());
    $can_manage_wordset_content = function_exists('ll_tools_current_user_can_manage_wordset_content')
        ? ll_tools_current_user_can_manage_wordset_content($wordset_id)
        : current_user_can('manage_options');
    $can_manage_wordset_uploads = $can_manage_wordset_content && current_user_can('upload_files');
    $can_manage_offline_app_export = $can_manage_wordset_content && (
        function_exists('ll_tools_current_user_can_offline_app_export')
            ? ll_tools_current_user_can_offline_app_export()
            : current_user_can('manage_options')
    );
    $speaking_settings_url = $can_manage_wordset_content
        ? ll_tools_get_wordset_settings_tool_url($wordset_term, 'transcription', $games_url)
        : '';
    $speaking_hidden_notice = function_exists('ll_tools_wordset_games_get_speaking_hidden_notice')
        ? ll_tools_wordset_games_get_speaking_hidden_notice($wordset_id, get_current_user_id(), [
            'settings_url' => $speaking_settings_url,
        ])
        : [];
    if (!$can_manage_wordset_content && in_array($settings_tool, ['language', 'visibility', 'import', 'template', 'recorder', 'transcription', 'offline-app', 'image-upload', 'audio-upload'], true)) {
        $settings_tool = '';
    }
    if (!$can_manage_offline_app_export && $settings_tool === 'offline-app') {
        $settings_tool = '';
    }
    if (!$can_manage_wordset_uploads && in_array($settings_tool, ['image-upload', 'audio-upload'], true)) {
        $settings_tool = '';
    }
    if (!$is_study_user && $settings_tool === 'study') {
        $settings_tool = '';
    }
    $settings_tool_urls = [
        'hub' => $settings_url,
        'study' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'study', $settings_navigation_back_url),
        'language' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'language', $settings_navigation_back_url),
        'visibility' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility', $settings_navigation_back_url),
        'import' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'import', $settings_navigation_back_url),
        'template' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'template', $settings_navigation_back_url),
        'recorder' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder', $settings_navigation_back_url),
        'transcription' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'transcription', $settings_navigation_back_url),
        'offline-app' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'offline-app', $settings_navigation_back_url),
        'image-upload' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'image-upload', $settings_navigation_back_url),
        'audio-upload' => ll_tools_get_wordset_settings_tool_url($wordset_term, 'audio-upload', $settings_navigation_back_url),
    ];
    $utility_current_url = function_exists('ll_tools_get_current_request_url')
        ? (string) ll_tools_get_current_request_url()
        : ll_tools_wordset_page_current_url();
    if ($utility_current_url === '') {
        $utility_current_url = home_url('/');
    }
    $utility_user = is_user_logged_in() ? wp_get_current_user() : null;
    $utility_user_display = '';
    $utility_user_login = '';
    if ($utility_user instanceof WP_User) {
        $utility_user_display = trim((string) $utility_user->display_name);
        if ($utility_user_display === '') {
            $utility_user_display = (string) $utility_user->user_login;
        }
        $utility_user_login = (string) $utility_user->user_login;
    }
    $utility_links = [];
    if ($utility_user instanceof WP_User) {
        if (function_exists('ll_tools_editor_hub_user_can_access') && ll_tools_editor_hub_user_can_access() && function_exists('ll_get_editor_hub_redirect_url')) {
            $editor_hub_url = (string) ll_get_editor_hub_redirect_url((int) $utility_user->ID);
            if ($editor_hub_url !== '') {
                $utility_links[] = [
                    'label' => __('Editor Hub', 'll-tools-text-domain'),
                    'url' => $editor_hub_url,
                    'is_active' => false,
                ];
            }
        }
        if (function_exists('ll_tools_user_can_record') && ll_tools_user_can_record() && function_exists('ll_get_recording_redirect_url')) {
            $recording_url = (string) ll_get_recording_redirect_url((int) $utility_user->ID);
            if ($recording_url !== '') {
                $utility_links[] = [
                    'label' => __('Recorder', 'll-tools-text-domain'),
                    'url' => $recording_url,
                    'is_active' => false,
                ];
            }
        }
        if ($can_manage_wordset_content) {
            $utility_links[] = [
                'label' => __('Word Set Tools', 'll-tools-text-domain'),
                'url' => $settings_url,
                'is_active' => ($view === 'settings'),
            ];
        }
        $utility_links[] = [
            'label' => __('Word Set', 'll-tools-text-domain'),
            'url' => $wordset_url,
            'is_active' => ($view === ''),
        ];
    }
    $wordset_visibility = function_exists('ll_tools_get_wordset_visibility')
        ? ll_tools_get_wordset_visibility($wordset_id)
        : 'public';
    $target_language = function_exists('ll_tools_get_wordset_target_language')
        ? (string) ll_tools_get_wordset_target_language([$wordset_id])
        : (string) get_term_meta($wordset_id, 'll_language', true);
    $translation_language = function_exists('ll_tools_get_wordset_translation_language')
        ? (string) ll_tools_get_wordset_translation_language([$wordset_id], true)
        : (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true);
    $category_translation_enabled = function_exists('ll_tools_is_wordset_category_translation_enabled')
        ? (bool) ll_tools_is_wordset_category_translation_enabled([$wordset_id], true)
        : (bool) get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true);
    $category_translation_source = function_exists('ll_tools_get_wordset_category_translation_source')
        ? (string) ll_tools_get_wordset_category_translation_source([$wordset_id], true)
        : 'target';
    $word_title_language_role = function_exists('ll_tools_get_wordset_title_language_role')
        ? (string) ll_tools_get_wordset_title_language_role([$wordset_id], true)
        : 'target';
    $recording_transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $autoplay_text_audio_answer_options = function_exists('ll_tools_should_autoplay_text_audio_answer_options')
        ? ll_tools_should_autoplay_text_audio_answer_options([$wordset_id])
        : false;
    $hide_lesson_text_for_non_text_quiz = (bool) get_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', true);
    $wordset_is_private = ($wordset_visibility === 'private');
    $plugin_update_status = function_exists('ll_tools_get_plugin_update_status_details')
        ? ll_tools_get_plugin_update_status_details()
        : ['status' => 'unknown', 'version' => '', 'raw' => null];
    $plugin_update_status_name = is_array($plugin_update_status) ? (string) ($plugin_update_status['status'] ?? 'unknown') : 'unknown';
    $plugin_update_version = is_array($plugin_update_status) ? (string) ($plugin_update_status['version'] ?? '') : '';
    $can_manage_plugin_updates = function_exists('ll_tools_user_can_manage_plugin_updates') && ll_tools_user_can_manage_plugin_updates();
    $show_plugin_update_link = $show_title
        && $can_manage_plugin_updates
        && $plugin_update_status_name === 'available'
        && $plugin_update_version !== '';
    $plugin_update_url = ($show_plugin_update_link && function_exists('ll_tools_get_plugin_update_action_url'))
        ? (string) ll_tools_get_plugin_update_action_url()
        : '';
    if ($plugin_update_url === '') {
        $show_plugin_update_link = false;
        $plugin_update_version = '';
    }
    $plugin_update_check_flash = $can_manage_plugin_updates && function_exists('ll_tools_consume_plugin_update_check_flash')
        ? (string) ll_tools_consume_plugin_update_check_flash()
        : '';
    $show_plugin_up_to_date_flash = $show_title
        && $can_manage_plugin_updates
        && !$show_plugin_update_link
        && $plugin_update_check_flash === 'up_to_date';
    $show_plugin_update_check_link = $show_title
        && $can_manage_plugin_updates
        && !$show_plugin_update_link
        && !$show_plugin_up_to_date_flash;
    $plugin_update_check_url = ($show_plugin_update_check_link && function_exists('ll_tools_get_plugin_update_check_action_url'))
        ? (string) ll_tools_get_plugin_update_check_action_url($wordset_url)
        : '';
    if ($plugin_update_check_url === '') {
        $show_plugin_update_check_link = false;
    }
    // The shared utility menu now displays the "up to date" feedback in-place.
    $show_plugin_up_to_date_hero_flash = $show_plugin_up_to_date_flash && !$show_title;

    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $games_catalog = function_exists('ll_tools_wordset_games_default_catalog')
        ? ll_tools_wordset_games_default_catalog()
        : [];
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
    // Full analytics payloads (especially analytics.words) can be very large on big wordsets
    // and are only required for the dedicated progress view. Avoid building them during the
    // main wordset page render to prevent white-screen failures from memory/time exhaustion.
    $should_bootstrap_analytics = ($view === 'progress');
    $estimated_category_word_total = 0;
    foreach ((array) $categories as $category_row) {
        $estimated_category_word_total += max(0, (int) ($category_row['count'] ?? 0));
    }
    $should_bootstrap_analytics = (bool) apply_filters(
        'll_tools_wordset_page_bootstrap_analytics',
        $should_bootstrap_analytics,
        $view === '' ? 'main' : $view,
        $wordset_id,
        $estimated_category_word_total
    );
    if ($is_study_user) {
        if (function_exists('ll_tools_get_user_study_goals')) {
            $goals = ll_tools_get_user_study_goals(get_current_user_id());
        }
        if (function_exists('ll_tools_get_user_study_state')) {
            $study_state = array_merge($study_state, ll_tools_get_user_study_state(get_current_user_id()));
        }
        $study_state['wordset_id'] = $wordset_id;
        if ($should_bootstrap_analytics && function_exists('ll_tools_build_user_study_analytics_payload')) {
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
    $gender_visual_config = ($gender_enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
        ? ll_tools_wordset_get_gender_visual_config($wordset_id)
        : [];
    $gender_progress_analytics = (isset($analytics['gender_progress']) && is_array($analytics['gender_progress']))
        ? $analytics['gender_progress']
        : [];
    $render_progress_gender_section = (
        $view === 'progress'
        && $is_study_user
        && !empty($gender_progress_analytics['enabled'])
        && max(0, (int) ($gender_progress_analytics['tracked_word_total'] ?? 0)) > 0
    );

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
        $enhanced['mode'] = isset($study_cat['mode']) && (string) $study_cat['mode'] !== ''
            ? (string) $study_cat['mode']
            : (string) ($cat['mode'] ?? 'image');
        $enhanced['prompt_type'] = isset($study_cat['prompt_type']) && (string) $study_cat['prompt_type'] !== ''
            ? (string) $study_cat['prompt_type']
            : (string) ($cat['prompt_type'] ?? 'audio');
        $enhanced['option_type'] = isset($study_cat['option_type']) && (string) $study_cat['option_type'] !== ''
            ? (string) $study_cat['option_type']
            : (string) ($cat['option_type'] ?? 'image');
        if (array_key_exists('learning_supported', $study_cat)) {
            $enhanced['learning_supported'] = !empty($study_cat['learning_supported']);
        } elseif (array_key_exists('learning_supported', $cat)) {
            $enhanced['learning_supported'] = !empty($cat['learning_supported']);
        } else {
            $enhanced['learning_supported'] = true;
        }
        $enhanced['gender_supported'] = array_key_exists('gender_supported', $study_cat)
            ? !empty($study_cat['gender_supported'])
            : !empty($cat['gender_supported']);
        $enhanced['aspect_bucket'] = (isset($study_cat['aspect_bucket']) && (string) $study_cat['aspect_bucket'] !== '')
            ? (string) $study_cat['aspect_bucket']
            : (string) ($cat['aspect_bucket'] ?? '');
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
    if (!$is_study_user && !$next_activity) {
        $starter_mode = 'learning';
        $starter_category_id = 0;
        foreach ($visible_categories as $visible_cat) {
            $candidate_id = isset($visible_cat['id']) ? (int) $visible_cat['id'] : 0;
            if ($candidate_id <= 0) {
                continue;
            }
            $learning_supported = !array_key_exists('learning_supported', $visible_cat) || !empty($visible_cat['learning_supported']);
            if (!$learning_supported) {
                continue;
            }
            $starter_category_id = $candidate_id;
            break;
        }
        if ($starter_category_id <= 0 && !empty($visible_category_ids)) {
            $starter_category_id = (int) $visible_category_ids[0];
            $starter_mode = 'practice';
        }
        if ($starter_category_id > 0) {
            $starter_activity = [
                'mode' => $starter_mode,
                'category_ids' => [$starter_category_id],
                'session_word_ids' => [],
                'type' => 'starter',
                'reason_code' => 'starter_first_activity',
                'details' => [
                    'starter' => true,
                ],
            ];
            if (function_exists('ll_tools_normalize_recommendation_activity')) {
                $normalized_starter = ll_tools_normalize_recommendation_activity($starter_activity);
                $next_activity = is_array($normalized_starter) ? $normalized_starter : $starter_activity;
            } else {
                $next_activity = $starter_activity;
            }
        }
    }
    $summary_counts = ll_tools_wordset_page_summary_counts($analytics);
    $summary_counts_deferred = ($is_study_user && ($view === '' || $view === 'main') && !$should_bootstrap_analytics);
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
    $category_starred_lookup = ll_tools_wordset_page_collect_category_starred_lookup(
        $analytics,
        $study_state,
        $wordset_id,
        $visible_category_ids
    );

    $progress_reset_category_options = [];
    $progress_reset_notice = null;
    $progress_reset_nonce = '';
    $progress_reset_all_confirm = '';
    $progress_reset_category_confirm_template = '';
    $progress_reset_category_aria_template = '';
    $progress_reset_all_enabled = false;
    if ($view === 'progress') {
        $all_progress_reset_category_options = ll_tools_wordset_page_progress_reset_category_options($enhanced_categories);
        $progress_reset_notice = ll_tools_wordset_page_progress_reset_notice($all_progress_reset_category_options);

        $resettable_category_ids = ll_tools_wordset_page_progress_resettable_category_ids(
            (isset($analytics['categories']) && is_array($analytics['categories'])) ? $analytics['categories'] : []
        );
        if (!empty($all_progress_reset_category_options) && !empty($resettable_category_ids)) {
            $resettable_category_lookup = array_fill_keys($resettable_category_ids, true);
            $progress_reset_category_options = array_values(array_filter(
                $all_progress_reset_category_options,
                static function (array $category_option) use ($resettable_category_lookup): bool {
                    $category_id = isset($category_option['id']) ? (int) $category_option['id'] : 0;
                    return $category_id > 0 && !empty($resettable_category_lookup[$category_id]);
                }
            ));
        }

        $progress_reset_all_enabled = count($progress_reset_category_options) > 1;
        if ($is_study_user && !empty($progress_reset_category_options)) {
            $progress_reset_nonce = wp_create_nonce('ll_wordset_progress_reset_' . $wordset_id);
            $progress_reset_all_confirm = __('This will permanently delete your progress for all categories in this word set. This cannot be undone. Continue?', 'll-tools-text-domain');
            $progress_reset_category_confirm_template = __('This will permanently delete your progress for %s. This cannot be undone. Continue?', 'll-tools-text-domain');
            $progress_reset_category_aria_template = __('Reset progress for %s', 'll-tools-text-domain');
        }
    }

    $lesson_enable_notice = ($view === '') ? ll_tools_wordset_page_lesson_enable_notice() : null;
    $manager_settings_notice = ($view === 'settings') ? ll_tools_wordset_page_manager_settings_notice() : null;
    $manager_offline_export_notice = ($view === 'settings') ? ll_tools_wordset_page_manager_offline_export_notice() : null;
    $manager_import_notice = ($view === 'settings') ? ll_tools_wordset_page_manager_import_notice() : null;
    $manager_template_notice = ($view === 'settings') ? ll_tools_wordset_page_manager_template_notice() : null;
    $manager_recorder_notice = ($view === 'settings') ? ll_tools_wordset_page_manager_recorder_notice() : null;
    $manager_audio_upload_notice = ($view === 'settings') ? ll_tools_wordset_page_audio_upload_notice() : null;
    $manager_image_upload_notice = ($view === 'settings') ? ll_tools_wordset_page_image_upload_notice() : null;
    $offline_export_category_options = [];
    $offline_export_zip_available = class_exists('ZipArchive');
    if ($view === 'settings' && $can_manage_wordset_content && function_exists('ll_tools_offline_app_get_wordset_category_options')) {
        $offline_export_category_options = ll_tools_offline_app_get_wordset_category_options($wordset_id);
    }
    $template_category_count = ($view === 'settings' && function_exists('ll_tools_get_wordset_template_category_ids'))
        ? count(ll_tools_get_wordset_template_category_ids($wordset_id))
        : 0;
    $template_image_count = ($view === 'settings' && function_exists('ll_tools_get_wordset_template_word_image_ids'))
        ? count(ll_tools_get_wordset_template_word_image_ids($wordset_id))
        : 0;
    $manager_audio_upload_shortcode = '';
    $manager_image_upload_shortcode = '';
    if ($view === 'settings' && $can_manage_wordset_uploads) {
        $manager_audio_return_shortcode_url = str_replace('"', '&quot;', esc_url_raw($settings_tool_urls['audio-upload']));
        $manager_image_return_shortcode_url = str_replace('"', '&quot;', esc_url_raw($settings_tool_urls['image-upload']));
        $manager_audio_upload_shortcode = sprintf(
            '[audio_upload_form wordset_id="%1$d" lock_wordset="1" return_url="%2$s"]',
            (int) $wordset_id,
            $manager_audio_return_shortcode_url
        );
        $manager_image_upload_shortcode = sprintf(
            '[image_upload_form wordset_id="%1$d" lock_wordset="1" return_url="%2$s"]',
            (int) $wordset_id,
            $manager_image_return_shortcode_url
        );
    }
    $available_audio_recorders = [];
    $assigned_audio_recorders = [];
    if ($view === 'settings' && $can_manage_wordset_content) {
        $available_audio_recorders = get_users([
            'role' => 'audio_recorder',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        $wordset_slug_sanitized = sanitize_title((string) $wordset_term->slug);
        foreach ((array) $available_audio_recorders as $audio_recorder_user) {
            if (!$audio_recorder_user instanceof WP_User) {
                continue;
            }
            $audio_recorder_config = function_exists('ll_get_user_recording_config')
                ? ll_get_user_recording_config((int) $audio_recorder_user->ID)
                : get_user_meta((int) $audio_recorder_user->ID, 'll_recording_config', true);
            $audio_recorder_wordset = is_array($audio_recorder_config)
                ? sanitize_title((string) ($audio_recorder_config['wordset'] ?? ''))
                : '';
            if ($audio_recorder_wordset !== '' && $audio_recorder_wordset === $wordset_slug_sanitized) {
                $assigned_audio_recorders[] = $audio_recorder_user;
            }
        }
    }
    $settings_hub_cards = [];
    $transcription_settings = function_exists('ll_tools_get_wordset_transcription_service_config')
        ? ll_tools_get_wordset_transcription_service_config([$wordset_id], true)
        : [
            'provider' => '',
            'provider_label' => __('Disabled', 'll-tools-text-domain'),
            'uses_local_browser' => false,
            'target_field' => 'recording_text',
            'target_label' => __('Recording text', 'll-tools-text-domain'),
            'local_endpoint' => '',
            'enabled' => false,
        ];
    $secondary_transcription_config = function_exists('ll_tools_get_wordset_recording_transcription_config')
        ? ll_tools_get_wordset_recording_transcription_config([$wordset_id], true)
        : [
            'label' => __('IPA', 'll-tools-text-domain'),
        ];
    if ($view === 'settings' && $is_study_user) {
        $enabled_goal_modes = array_values(array_filter(array_map('sanitize_key', (array) ($goals['enabled_modes'] ?? [])), static function ($mode): bool {
            return $mode !== '';
        }));
        $enabled_goal_mode_count = count(array_values(array_unique($enabled_goal_modes)));
        $study_card_status = '';
        if ($enabled_goal_mode_count > 0) {
            $study_card_status = sprintf(
                _n('%d mode enabled', '%d modes enabled', $enabled_goal_mode_count, 'll-tools-text-domain'),
                $enabled_goal_mode_count
            );
        }
        $queue_count = count($recommendation_queue);
        if ($queue_count > 0) {
            $queue_status = sprintf(
                _n('%d activity queued', '%d activities queued', $queue_count, 'll-tools-text-domain'),
                $queue_count
            );
            $study_card_status = $study_card_status !== ''
                ? $study_card_status . ' · ' . $queue_status
                : $queue_status;
        }
        $settings_hub_cards[] = [
            'tool' => 'study',
            'label' => ll_tools_wordset_settings_tool_label('study'),
            'description' => ll_tools_wordset_settings_tool_description('study'),
            'status' => $study_card_status,
            'url' => $settings_tool_urls['study'],
            'enabled' => true,
        ];
    }
    if ($view === 'settings' && $can_manage_wordset_content) {
        $language_card_status = $target_language !== '' ? $target_language : __('Add target language', 'll-tools-text-domain');
        if ($translation_language !== '') {
            $language_card_status .= ' · ' . $translation_language;
        }
        if ($category_translation_enabled) {
            $language_card_status .= ' · ' . __('Category translations', 'll-tools-text-domain');
        }
        $settings_hub_cards[] = [
            'tool' => 'language',
            'label' => ll_tools_wordset_settings_tool_label('language'),
            'description' => ll_tools_wordset_settings_tool_description('language'),
            'status' => $language_card_status,
            'url' => $settings_tool_urls['language'],
            'enabled' => true,
        ];

        $settings_hub_cards[] = [
            'tool' => 'visibility',
            'label' => ll_tools_wordset_settings_tool_label('visibility'),
            'description' => ll_tools_wordset_settings_tool_description('visibility'),
            'status' => $wordset_is_private ? __('Private', 'll-tools-text-domain') : __('Public', 'll-tools-text-domain'),
            'url' => $settings_tool_urls['visibility'],
            'enabled' => true,
        ];

        $settings_hub_cards[] = [
            'tool' => 'import',
            'label' => ll_tools_wordset_settings_tool_label('import'),
            'description' => ll_tools_wordset_settings_tool_description('import'),
            'status' => !empty($enhanced_categories)
                ? sprintf(
                    _n('%d category ready', '%d categories ready', count($enhanced_categories), 'll-tools-text-domain'),
                    count($enhanced_categories)
                )
                : __('No categories yet', 'll-tools-text-domain'),
            'url' => $settings_tool_urls['import'],
            'enabled' => true,
        ];

        $template_status_parts = [];
        if ($template_category_count > 0) {
            $template_status_parts[] = sprintf(
                _n('%d category', '%d categories', $template_category_count, 'll-tools-text-domain'),
                $template_category_count
            );
        }
        if ($template_image_count > 0) {
            $template_status_parts[] = sprintf(
                _n('%d image', '%d images', $template_image_count, 'll-tools-text-domain'),
                $template_image_count
            );
        }
        $settings_hub_cards[] = [
            'tool' => 'template',
            'label' => ll_tools_wordset_settings_tool_label('template'),
            'description' => ll_tools_wordset_settings_tool_description('template'),
            'status' => !empty($template_status_parts)
                ? implode(' · ', $template_status_parts)
                : __('No template content yet', 'll-tools-text-domain'),
            'url' => ($template_category_count > 0 || $template_image_count > 0) ? $settings_tool_urls['template'] : '',
            'enabled' => ($template_category_count > 0 || $template_image_count > 0),
        ];

        $assigned_recorder_count = count($assigned_audio_recorders);
        $settings_hub_cards[] = [
            'tool' => 'recorder',
            'label' => ll_tools_wordset_settings_tool_label('recorder'),
            'description' => ll_tools_wordset_settings_tool_description('recorder'),
            'status' => $assigned_recorder_count > 0
                ? sprintf(
                    _n('%d recorder assigned', '%d recorders assigned', $assigned_recorder_count, 'll-tools-text-domain'),
                    $assigned_recorder_count
                )
                : __('No recorders assigned', 'll-tools-text-domain'),
            'url' => $settings_tool_urls['recorder'],
            'enabled' => true,
        ];

        $transcription_card_status = trim((string) ($transcription_settings['provider_label'] ?? ''));
        $transcription_target_label = trim((string) ($transcription_settings['target_label'] ?? ''));
        if (!empty($transcription_settings['uses_local_browser']) && $transcription_target_label !== '') {
            $transcription_card_status = $transcription_card_status !== ''
                ? $transcription_card_status . ' · ' . $transcription_target_label
                : $transcription_target_label;
        }
        if (empty($transcription_settings['enabled'])) {
            $transcription_card_status = $transcription_card_status !== ''
                ? $transcription_card_status . ' · ' . __('Not ready', 'll-tools-text-domain')
                : __('Not ready', 'll-tools-text-domain');
        }
        $settings_hub_cards[] = [
            'tool' => 'transcription',
            'label' => ll_tools_wordset_settings_tool_label('transcription'),
            'description' => ll_tools_wordset_settings_tool_description('transcription'),
            'status' => $transcription_card_status,
            'url' => $settings_tool_urls['transcription'],
            'enabled' => true,
        ];

        $offline_export_category_count = count($offline_export_category_options);
        $offline_export_status = $can_manage_offline_app_export
            ? (
                !$offline_export_zip_available
                    ? __('Zip unavailable', 'll-tools-text-domain')
                    : (
                        $offline_export_category_count > 0
                            ? sprintf(
                                _n('%d category ready', '%d categories ready', $offline_export_category_count, 'll-tools-text-domain'),
                                $offline_export_category_count
                            )
                            : __('No exportable categories', 'll-tools-text-domain')
                    )
            )
            : __('Admin permission required', 'll-tools-text-domain');
        $offline_export_enabled = $can_manage_offline_app_export && $offline_export_zip_available && $offline_export_category_count > 0;
        $settings_hub_cards[] = [
            'tool' => 'offline-app',
            'label' => ll_tools_wordset_settings_tool_label('offline-app'),
            'description' => ll_tools_wordset_settings_tool_description('offline-app'),
            'status' => $offline_export_status,
            'url' => $offline_export_enabled ? $settings_tool_urls['offline-app'] : '',
            'enabled' => $offline_export_enabled,
        ];

        $settings_hub_cards[] = [
            'tool' => 'image-upload',
            'label' => ll_tools_wordset_settings_tool_label('image-upload'),
            'description' => ll_tools_wordset_settings_tool_description('image-upload'),
            'status' => $can_manage_wordset_uploads
                ? __('Ready', 'll-tools-text-domain')
                : __('Upload permission required', 'll-tools-text-domain'),
            'url' => $can_manage_wordset_uploads ? $settings_tool_urls['image-upload'] : '',
            'enabled' => $can_manage_wordset_uploads,
        ];

        $settings_hub_cards[] = [
            'tool' => 'audio-upload',
            'label' => ll_tools_wordset_settings_tool_label('audio-upload'),
            'description' => ll_tools_wordset_settings_tool_description('audio-upload'),
            'status' => $can_manage_wordset_uploads
                ? __('Ready', 'll-tools-text-domain')
                : __('Upload permission required', 'll-tools-text-domain'),
            'url' => $can_manage_wordset_uploads ? $settings_tool_urls['audio-upload'] : '',
            'enabled' => $can_manage_wordset_uploads,
        ];
    }
    $can_manage_vocab_lessons = $can_manage_wordset_content;
    $show_enable_lessons_button = false;
    $enable_lessons_button_label = __('Enable lesson pages', 'll-tools-text-domain');
    if ($view === '' && $can_manage_vocab_lessons && empty($categories)) {
        $candidate_rows = ll_tools_get_wordset_page_category_rows($wordset_id);
        if (!empty($candidate_rows)) {
            $show_enable_lessons_button = true;
            if (function_exists('ll_tools_get_vocab_lesson_wordset_ids')) {
                $enabled_wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
                if (in_array($wordset_id, $enabled_wordset_ids, true)) {
                    $enable_lessons_button_label = __('Generate lesson pages', 'll-tools-text-domain');
                }
            }
        }
    }

    $category_search_index = ll_tools_get_wordset_page_category_search_index(
        $wordset_id,
        array_values(array_map('intval', wp_list_pluck($enhanced_categories, 'id')))
    );

    $script_categories = array_map(function (array $cat) use ($category_search_index): array {
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
            'search_text' => (string) ($category_search_index[(int) ($cat['id'] ?? 0)]['search_text'] ?? ''),
        ];
    }, $enhanced_categories);

    $games_frontend_config = function_exists('ll_tools_get_wordset_games_frontend_config')
        ? ll_tools_get_wordset_games_frontend_config($wordset_id)
        : [];
    $games_i18n = function_exists('ll_tools_get_wordset_games_i18n_messages')
        ? ll_tools_get_wordset_games_i18n_messages()
        : [];

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
            'games' => $games_url,
            'hidden' => $hidden_categories_url,
            'settings' => $settings_url,
        ],
        'progressReset' => [
            'enabled' => ($view === 'progress' && $is_study_user && !empty($progress_reset_category_options) && $progress_reset_nonce !== ''),
            'actionUrl' => $progress_url,
            'nonce' => $progress_reset_nonce,
            'wordsetId' => $wordset_id,
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
            'visual_config' => is_array($gender_visual_config) ? $gender_visual_config : [],
            'min_count' => (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ),
        ],
        'games' => array_merge($games_frontend_config, [
            'catalog' => $games_catalog,
            'canManageSettings' => $can_manage_wordset_content,
            'speakingSettingsUrl' => $speaking_settings_url,
            'speakingHiddenNotice' => $speaking_hidden_notice,
        ]),
        'summaryCounts' => $summary_counts,
        'summaryCountsDeferred' => $summary_counts_deferred,
        'learningMinChunkSize' => 8,
        'hardWordDifficultyThreshold' => function_exists('ll_tools_user_progress_hard_difficulty_threshold')
            ? ll_tools_user_progress_hard_difficulty_threshold()
            : 4,
        'i18n' => [
            'nextNone' => __('Loading next recommendation...', 'll-tools-text-domain'),
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
            'selectionNewOnly' => __('New words only', 'll-tools-text-domain'),
            'selectionStudiedOnly' => __('In progress only', 'll-tools-text-domain'),
            'selectionLearnedOnly' => __('Learned only', 'll-tools-text-domain'),
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
            'noNewWordsInSelection' => __('No new words are available for this selection.', 'll-tools-text-domain'),
            'noStudiedWordsInSelection' => __('No in progress words are available for this selection.', 'll-tools-text-domain'),
            'noLearnedWordsInSelection' => __('No learned words are available for this selection.', 'll-tools-text-domain'),
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
            'analyticsWord' => __('Word', 'll-tools-text-domain'),
            'analyticsCategory' => __('Category', 'll-tools-text-domain'),
            'analyticsPartOfSpeech' => __('Part of speech', 'll-tools-text-domain'),
            'analyticsPartOfSpeechShort' => __('POS', 'll-tools-text-domain'),
            'analyticsActivity' => __('Activity', 'll-tools-text-domain'),
            'analyticsWordProgress' => __('Word Progress', 'll-tools-text-domain'),
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
            'analyticsFilterPartOfSpeech' => __('Filter part of speech', 'll-tools-text-domain'),
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
            'analyticsDayEvents' => __('%1$d rounds, %2$d words', 'll-tools-text-domain'),
            'analyticsGenderTitle' => __('Gender', 'll-tools-text-domain'),
            'analyticsGenderNote' => __('Only words with marked gender are counted.', 'll-tools-text-domain'),
            'analyticsGenderTrackedWords' => __('%d tracked words', 'll-tools-text-domain'),
            'analyticsGenderTracked' => __('Tracked', 'll-tools-text-domain'),
            'analyticsGenderNotStarted' => __('Not started', 'll-tools-text-domain'),
            'analyticsGenderLevel1' => __('Level 1', 'll-tools-text-domain'),
            'analyticsGenderLevel2' => __('Level 2', 'll-tools-text-domain'),
            'analyticsGenderLevel3' => __('Level 3', 'll-tools-text-domain'),
            'analyticsGenderLastPracticed' => __('Last practiced', 'll-tools-text-domain'),
            'analyticsGenderTableProgress' => __('Gender progress', 'll-tools-text-domain'),
            'analyticsGenderTableGender' => __('Gender', 'll-tools-text-domain'),
            'analyticsGenderTableLevel' => __('Level', 'll-tools-text-domain'),
            'analyticsGenderToggleAria' => __('Show gender progress in the tables', 'll-tools-text-domain'),
            'analyticsGenderToggleAriaActive' => __('Show general progress in the tables', 'll-tools-text-domain'),
            'analyticsGenderFilterGender' => __('Filter gender', 'll-tools-text-domain'),
            'analyticsGenderFilterLevel' => __('Filter level', 'll-tools-text-domain'),
            'modePractice' => __('Practice', 'll-tools-text-domain'),
            'modeLearning' => __('Learn', 'll-tools-text-domain'),
            'modeListening' => __('Listen', 'll-tools-text-domain'),
            'modeGender' => __('Gender', 'll-tools-text-domain'),
            'modeSelfCheck' => __('Self Check', 'll-tools-text-domain'),
            ...$games_i18n,
            'progressResetCategoryConfirm' => $progress_reset_category_confirm_template,
            'progressResetCategoryAria' => $progress_reset_category_aria_template,
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
        <?php if ($show_title) : ?>
            <?php
            $utility_current_area = 'wordset';
            if ($view === 'settings') {
                $utility_current_area = 'wordset_settings';
            } elseif ($view === 'progress') {
                $utility_current_area = 'wordset_progress';
            } elseif ($view === 'hidden-categories') {
                $utility_current_area = 'wordset_hidden';
            } elseif ($view === 'games') {
                $utility_current_area = 'wordset_games';
            }
            echo ll_tools_render_frontend_user_utility_menu([
                'current_area' => $utility_current_area,
                'wordset' => $wordset_term,
                'current_url' => $utility_current_url,
                'plugin_update_check_flash' => $plugin_update_check_flash,
            ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        <?php endif; ?>
        <?php if (function_exists('ll_tools_teacher_class_render_frontend_notice')) : ?>
            <?php echo ll_tools_teacher_class_render_frontend_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endif; ?>
        <?php if (!($utility_user instanceof WP_User) && $view !== 'progress' && $view !== 'games' && function_exists('ll_tools_login_window_requested_mode') && ll_tools_login_window_requested_mode() !== '') : ?>
            <?php
            echo ll_tools_render_login_window([
                'container_class' => 'll-wordset-empty ll-wordset-login-window',
                'title' => __('Sign in or create an account', 'll-tools-text-domain'),
                'message' => __('Use an account to save your progress and keep learning from this page.', 'll-tools-text-domain'),
                'submit_label' => __('Continue', 'll-tools-text-domain'),
                'redirect_to' => $utility_current_url,
                'show_registration' => true,
                'registration_title' => __('Create learner account', 'll-tools-text-domain'),
                'registration_submit_label' => __('Create account', 'll-tools-text-domain'),
            ]);
            ?>
        <?php endif; ?>
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
                <?php
                echo ll_tools_render_login_window([
                    'container_class' => 'll-wordset-empty ll-wordset-login-window',
                    'title' => __('Sign in to view progress', 'll-tools-text-domain'),
                    'message' => __('Use your account to view wordset progress and keep your learning history.', 'll-tools-text-domain'),
                    'submit_label' => __('Open progress', 'll-tools-text-domain'),
                    'redirect_to' => ll_tools_get_current_request_url(),
                    'show_registration' => true,
                    'registration_title' => __('Create learner account', 'll-tools-text-domain'),
                    'registration_submit_label' => __('Create account', 'll-tools-text-domain'),
                ]);
                ?>
            <?php else : ?>
                <?php if (is_array($progress_reset_notice) && !empty($progress_reset_notice['message'])) : ?>
                    <div
                        class="ll-wordset-progress-reset-notice ll-wordset-progress-reset-notice--<?php echo esc_attr(($progress_reset_notice['type'] ?? 'error') === 'success' ? 'success' : 'error'); ?>"
                        role="<?php echo esc_attr(($progress_reset_notice['type'] ?? 'error') === 'success' ? 'status' : 'alert'); ?>">
                        <?php echo esc_html((string) $progress_reset_notice['message']); ?>
                    </div>
                <?php endif; ?>
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

                    <?php if ($render_progress_gender_section) : ?>
                        <section
                            class="ll-wordset-progress-gender"
                            data-ll-wordset-progress-gender
                            data-ll-wordset-progress-gender-toggle
                            role="button"
                            tabindex="0"
                            aria-pressed="false">
                            <div class="ll-wordset-progress-gender__head">
                                <div class="ll-wordset-progress-gender__copy">
                                    <h2 class="ll-wordset-progress-gender__title"><?php echo esc_html__('Gender', 'll-tools-text-domain'); ?></h2>
                                    <p class="ll-wordset-progress-gender__note"><?php echo esc_html__('Only words with marked gender are counted.', 'll-tools-text-domain'); ?></p>
                                </div>
                            </div>
                            <div class="ll-wordset-progress-gender__cards" data-ll-wordset-progress-gender-cards></div>
                            <div class="ll-wordset-progress-gender__overview" data-ll-wordset-progress-gender-overview></div>
                        </section>
                    <?php endif; ?>

                    <div class="ll-wordset-progress-tabs" role="tablist" aria-label="<?php echo esc_attr__('Progress', 'll-tools-text-domain'); ?>">
                        <button type="button" class="ll-wordset-progress-tab active" data-ll-wordset-progress-tab="categories" aria-selected="true"><?php echo esc_html__('Categories', 'll-tools-text-domain'); ?></button>
                        <button type="button" class="ll-wordset-progress-tab" data-ll-wordset-progress-tab="words" aria-selected="false"><?php echo esc_html__('Words', 'll-tools-text-domain'); ?></button>
                    </div>

                    <div class="ll-wordset-progress-panel" data-ll-wordset-progress-panel="categories">
                        <div class="ll-wordset-progress-category-tools">
                            <?php if ($progress_reset_nonce !== '' && $progress_reset_all_enabled) : ?>
                                <div class="ll-wordset-progress-reset-actions">
                                    <form
                                        class="ll-wordset-progress-reset-all-form"
                                        method="post"
                                        action="<?php echo esc_url($progress_url); ?>"
                                        data-ll-wordset-progress-reset-form
                                        data-confirm="<?php echo esc_attr($progress_reset_all_confirm); ?>">
                                        <input type="hidden" name="ll_wordset_progress_reset_action" value="all" />
                                        <input type="hidden" name="ll_wordset_progress_reset_nonce" value="<?php echo esc_attr($progress_reset_nonce); ?>" />
                                        <input type="hidden" name="ll_wordset_progress_reset_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                                        <button type="submit" class="ll-wordset-progress-reset-all-button">
                                            <span class="ll-wordset-progress-reset-all-button__icon" aria-hidden="true">
                                                <?php echo ll_tools_wordset_page_render_reset_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            </span>
                                            <span class="ll-wordset-progress-reset-all-button__label"><?php echo esc_html__('Reset all categories', 'll-tools-text-domain'); ?></span>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
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
                        </div>
                        <div class="ll-wordset-progress-table-wrap">
                            <table class="ll-wordset-progress-table ll-wordset-progress-table--categories">
                                <thead>
                                    <tr>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="category" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="category">
                                                <span data-ll-wordset-progress-category-header-label="category"><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="progress" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="progress">
                                                <span class="ll-wordset-progress-sort-label ll-wordset-progress-sort-label--progress" data-ll-wordset-progress-category-header-label="progress" data-mobile-label="<?php echo esc_attr__('Progress', 'll-tools-text-domain'); ?>"><?php echo esc_html__('Word Progress', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="activity" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="activity">
                                                <span data-ll-wordset-progress-category-header-label="activity"><?php echo esc_html__('Activity', 'll-tools-text-domain'); ?></span>
                                                <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                            </button>
                                        </th>
                                        <th scope="col" data-ll-wordset-progress-category-sort-th="last" aria-sort="none">
                                            <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="last">
                                                <span data-ll-wordset-progress-category-header-label="last"><?php echo esc_html__('Last', 'll-tools-text-domain'); ?></span>
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
                        <?php echo ll_tools_wordset_page_render_progress_mobile_legend(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
                                                <span data-ll-wordset-progress-word-header-label="word"><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></span>
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
                                                    <span data-ll-wordset-progress-word-header-label="category"><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
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
                                        <th scope="col" data-ll-wordset-progress-sort-th="part_of_speech" data-mobile-label="<?php echo esc_attr__('POS', 'll-tools-text-domain'); ?>" aria-sort="none">
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="part_of_speech" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter part of speech', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="part_of_speech">
                                                    <span data-ll-wordset-progress-word-header-label="part_of_speech"><?php echo esc_html__('Part of speech', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="part_of_speech" hidden>
                                                <fieldset class="ll-wordset-progress-filter-fieldset">
                                                    <legend class="screen-reader-text"><?php echo esc_html__('Filter part of speech', 'll-tools-text-domain'); ?></legend>
                                                    <div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="part_of_speech"></div>
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
                                                    <span data-ll-wordset-progress-word-header-label="status"><?php echo esc_html__('Status', 'll-tools-text-domain'); ?></span>
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
                                            <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--difficulty" aria-hidden="true">
                                                <?php echo ll_tools_wordset_page_render_hard_words_icon('ll-wordset-progress-mobile-header-icon-svg'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            </span>
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="difficulty" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter difficulty score', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="difficulty">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Difficulty Score', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="difficulty"><?php echo esc_html__('Difficulty', 'll-tools-text-domain'); ?></span>
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
                                            <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--seen" aria-hidden="true">
                                                <?php echo ll_tools_wordset_page_render_unhide_icon('ll-wordset-progress-mobile-header-icon-svg'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            </span>
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="seen" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter seen count', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="seen">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Seen', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="seen"><?php echo esc_html__('Seen', 'll-tools-text-domain'); ?></span>
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
                                            <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--wrong" aria-hidden="true">
                                                <?php echo ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-mobile-header-icon-svg'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            </span>
                                            <div class="ll-wordset-progress-th-controls">
                                                <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="wrong" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Filter wrong count', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                                    </span>
                                                </button>
                                                <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="wrong">
                                                    <span class="screen-reader-text"><?php echo esc_html__('Wrong', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="wrong"><?php echo esc_html__('Wrong', 'll-tools-text-domain'); ?></span>
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
                                                    <span data-ll-wordset-progress-word-header-label="last"><?php echo esc_html__('Last', 'll-tools-text-domain'); ?></span>
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
                                        <td colspan="9"><?php echo esc_html__('No data yet.', 'll-tools-text-domain'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="ll-wordset-selection-bar ll-wordset-progress-selection-bar" data-ll-wordset-progress-selection-bar hidden>
                            <span class="ll-wordset-selection-bar__text" data-ll-wordset-progress-selection-count><?php echo esc_html__('0 selected words', 'll-tools-text-domain'); ?></span>
                            <div class="ll-wordset-selection-bar__actions">
                                <?php
                                $progress_selection_modes = ll_tools_get_study_launch_mode_order($gender_enabled);
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
        <?php elseif ($view === 'games') : ?>
            <?php
            echo ll_tools_render_wordset_games_shell([
                'wordset_term' => $wordset_term,
                'games_catalog' => $games_catalog,
                'speaking_hidden_notice' => $speaking_hidden_notice,
                'is_study_user' => $is_study_user,
                'back_url' => $back_url,
            ]);
            ?>
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
            <?php
            $settings_header_back_url = ($settings_tool !== '') ? $settings_tool_urls['hub'] : $back_url;
            $settings_header_back_label = ($settings_tool !== '') ? __('Word Set Tools', 'll-tools-text-domain') : $wordset_term->name;
            $settings_header_back_aria = ($settings_tool !== '')
                ? __('Back to word set tools', 'll-tools-text-domain')
                : sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_term->name);
            $settings_page_title = ll_tools_wordset_settings_tool_title($settings_tool);
            $settings_notices = [];
            if (is_array($manager_settings_notice) && !empty($manager_settings_notice['message']) && ($settings_tool === '' || in_array($settings_tool, ['study', 'language', 'visibility', 'transcription'], true))) {
                $settings_notices[] = $manager_settings_notice;
            }
            if (is_array($manager_template_notice) && !empty($manager_template_notice['message']) && ($settings_tool === '' || in_array($settings_tool, ['template', 'language'], true))) {
                $settings_notices[] = $manager_template_notice;
            }
            if (is_array($manager_import_notice) && !empty($manager_import_notice['message']) && ($settings_tool === '' || $settings_tool === 'import')) {
                $settings_notices[] = $manager_import_notice;
            }
            if (is_array($manager_recorder_notice) && !empty($manager_recorder_notice['message']) && ($settings_tool === '' || $settings_tool === 'recorder')) {
                $settings_notices[] = $manager_recorder_notice;
            }
            if (is_array($manager_offline_export_notice) && !empty($manager_offline_export_notice['message']) && ($settings_tool === '' || $settings_tool === 'offline-app')) {
                $settings_notices[] = $manager_offline_export_notice;
            }
            if (is_array($manager_audio_upload_notice) && !empty($manager_audio_upload_notice['message']) && ($settings_tool === '' || $settings_tool === 'audio-upload')) {
                $settings_notices[] = $manager_audio_upload_notice;
            }
            if (is_array($manager_image_upload_notice) && !empty($manager_image_upload_notice['message']) && ($settings_tool === '' || $settings_tool === 'image-upload')) {
                $settings_notices[] = $manager_image_upload_notice;
            }
            ?>
            <header class="ll-wordset-subpage-head">
                <a class="ll-wordset-back ll-vocab-lesson-back" href="<?php echo esc_url($settings_header_back_url); ?>" aria-label="<?php echo esc_attr($settings_header_back_aria); ?>">
                    <span class="ll-wordset-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                            <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="ll-wordset-back__label"><?php echo esc_html($settings_header_back_label); ?></span>
                </a>
                <h1 class="ll-wordset-title"><?php echo esc_html($settings_page_title); ?></h1>
            </header>
            <?php foreach ($settings_notices as $settings_notice) : ?>
                <?php echo ll_tools_wordset_page_render_settings_notice($settings_notice); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
            <?php if (!$is_study_user) : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('Sign in to open word set tools.', 'll-tools-text-domain'); ?>
                </div>
            <?php elseif ($settings_tool === 'study') : ?>
                <?php
                echo ll_tools_wordset_page_render_settings_study_tool([
                    'mode_ui' => $mode_ui,
                    'mode_fallback_icons' => $mode_fallback_icons,
                    'mode_labels' => $mode_labels,
                    'gender_mode_available' => $gender_mode_available,
                    'enhanced_categories' => $enhanced_categories,
                    'recommendation_queue' => $recommendation_queue,
                    'wordset_term' => $wordset_term,
                    'wordset_id' => $wordset_id,
                    'back_url' => $back_url,
                    'can_manage_wordset_content' => $can_manage_wordset_content,
                    'autoplay_text_audio_answer_options' => $autoplay_text_audio_answer_options,
                    'hide_lesson_text_for_non_text_quiz' => $hide_lesson_text_for_non_text_quiz,
                ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php elseif ($settings_tool === 'language' && $can_manage_wordset_content) : ?>
                <?php
                echo ll_tools_wordset_page_render_settings_language_tool($wordset_term, $wordset_id, $back_url, [
                    'language' => $target_language,
                    'translation_language' => $translation_language,
                    'category_translation_enabled' => $category_translation_enabled,
                    'category_translation_source' => $category_translation_source,
                    'word_title_language_role' => $word_title_language_role,
                    'recording_transcription_mode' => $recording_transcription_mode,
                ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php elseif ($settings_tool === 'visibility' && $can_manage_wordset_content) : ?>
                <?php echo ll_tools_wordset_page_render_settings_visibility_tool($wordset_term, $wordset_id, $back_url, $wordset_visibility, $wordset_is_private); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'transcription' && $can_manage_wordset_content) : ?>
                <?php echo ll_tools_wordset_page_render_settings_transcription_tool($wordset_term, $wordset_id, $back_url, $transcription_settings, $secondary_transcription_config); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'import' && $can_manage_wordset_content) : ?>
                <?php echo ll_tools_wordset_page_render_settings_import_tool($wordset_term, $wordset_id, $back_url, $enhanced_categories); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'template' && $can_manage_wordset_content) : ?>
                <?php echo ll_tools_wordset_page_render_settings_template_tool($wordset_term, $wordset_id, $back_url, $template_category_count, $template_image_count); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'recorder' && $can_manage_wordset_content) : ?>
                <?php echo ll_tools_wordset_page_render_settings_recorder_tool($wordset_term, $wordset_id, $back_url, $assigned_audio_recorders, $available_audio_recorders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'offline-app' && $can_manage_offline_app_export) : ?>
                <?php echo ll_tools_wordset_page_render_settings_offline_app_tool($wordset_term, $wordset_id, $back_url, $offline_export_category_options, $offline_export_zip_available); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'image-upload' && $can_manage_wordset_uploads) : ?>
                <?php echo ll_tools_wordset_page_render_settings_upload_tool(__('Image Upload', 'll-tools-text-domain'), __('Upload images and optionally auto-create draft words in this word set when the category supports image-to-text quizzes.', 'll-tools-text-domain'), $manager_image_upload_shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($settings_tool === 'audio-upload' && $can_manage_wordset_uploads) : ?>
                <?php echo ll_tools_wordset_page_render_settings_upload_tool(__('Audio Upload', 'll-tools-text-domain'), __('Bulk upload audio files and create or match draft words in this word set.', 'll-tools-text-domain'), $manager_audio_upload_shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif (!empty($settings_hub_cards)) : ?>
                <?php echo ll_tools_wordset_page_render_settings_hub($settings_hub_cards); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <div class="ll-wordset-empty">
                    <?php echo esc_html__('No settings tools are available for this word set right now.', 'll-tools-text-domain'); ?>
                </div>
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
                        <div class="ll-wordset-hero__action-links">
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
                            <a
                                class="ll-wordset-link-chip ll-wordset-link-chip--games"
                                href="<?php echo esc_url($games_url); ?>"
                                aria-label="<?php echo esc_attr__('Open games', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-link-chip__icon" aria-hidden="true">
                                    <?php echo function_exists('ll_tools_wordset_games_render_page_icon') ? ll_tools_wordset_games_render_page_icon('ll-wordset-games-link-icon') : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </span>
                                <span class="ll-wordset-link-chip__label"><?php echo esc_html__('Games', 'll-tools-text-domain'); ?></span>
                            </a>
                            <?php if ($show_plugin_update_link) : ?>
                                <a
                                    class="ll-wordset-link-chip ll-wordset-update-link"
                                    href="<?php echo esc_url($plugin_update_url); ?>"
                                    aria-label="<?php echo esc_attr(sprintf(__('Update Language Learner Tools to version %s', 'll-tools-text-domain'), $plugin_update_version)); ?>">
                                    <span class="ll-wordset-update-link__dot" aria-hidden="true"></span>
                                    <span class="ll-wordset-update-link__label">
                                        <?php echo esc_html(sprintf(__('Update to %s', 'll-tools-text-domain'), $plugin_update_version)); ?>
                                    </span>
                                </a>
                            <?php elseif ($show_plugin_up_to_date_hero_flash) : ?>
                                <span
                                    class="ll-wordset-link-chip ll-wordset-check-updates-link ll-wordset-check-updates-link--success"
                                    role="status"
                                    aria-label="<?php echo esc_attr__('Plugin is up to date', 'll-tools-text-domain'); ?>">
                                    <span class="ll-wordset-check-updates-link__check" aria-hidden="true">
                                        <svg viewBox="0 0 16 16" width="10" height="10" focusable="false" aria-hidden="true">
                                            <path d="M3.5 8.25 6.4 11.1 12.5 5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                                        </svg>
                                    </span>
                                    <span class="ll-wordset-check-updates-link__label">
                                        <?php echo esc_html__('Up to date', 'll-tools-text-domain'); ?>
                                    </span>
                                </span>
                            <?php elseif ($show_plugin_update_check_link) : ?>
                                <?php /* Moved to the shared top user menu (admin only). */ ?>
                            <?php endif; ?>
                            <?php if ($is_study_user) : ?>
                                <a class="ll-wordset-settings-link ll-tools-settings-button" href="<?php echo esc_url($settings_url); ?>" aria-label="<?php echo esc_attr__('Word set tools', 'll-tools-text-domain'); ?>">
                                    <span class="mode-icon" aria-hidden="true">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <a
                            class="ll-wordset-progress-mini<?php echo $summary_counts_deferred ? ' is-loading' : ''; ?>"
                            data-ll-wordset-progress-mini-root
                            href="<?php echo esc_url($progress_url); ?>"
                            aria-label="<?php echo esc_attr__('Open progress', 'll-tools-text-domain'); ?>"
                            <?php if ($summary_counts_deferred) : ?>aria-busy="true"<?php endif; ?>>
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
                    </div>
                </header>
            <?php endif; ?>

            <?php if (is_array($lesson_enable_notice) && !empty($lesson_enable_notice['message'])) : ?>
                <div
                    class="ll-wordset-progress-reset-notice ll-wordset-progress-reset-notice--<?php echo esc_attr(($lesson_enable_notice['type'] ?? 'error') === 'success' ? 'success' : 'error'); ?>"
                    role="<?php echo esc_attr(($lesson_enable_notice['type'] ?? 'error') === 'success' ? 'status' : 'alert'); ?>">
                    <?php echo esc_html((string) $lesson_enable_notice['message']); ?>
                </div>
            <?php endif; ?>

            <section class="ll-wordset-top-actions">
                <div class="ll-wordset-mode-buttons" role="group" aria-label="<?php echo esc_attr__('Quiz modes', 'll-tools-text-domain'); ?>">
                    <?php
                    $top_modes = ll_tools_get_study_launch_mode_order($gender_mode_available);
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
                                <span class="ll-wordset-next-card__text" data-ll-wordset-next-text><?php echo esc_html__('Loading next recommendation...', 'll-tools-text-domain'); ?></span>
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
                    <?php if ($show_enable_lessons_button) : ?>
                        <p class="ll-wordset-empty__message">
                            <?php echo esc_html__('Lesson pages are not enabled for this word set yet.', 'll-tools-text-domain'); ?>
                        </p>
                        <div class="ll-wordset-empty__actions">
                            <form
                                class="ll-wordset-enable-lessons-form"
                                method="post"
                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ll_tools_enable_vocab_lessons_for_wordset" />
                                <input type="hidden" name="ll_tools_enable_vocab_lesson_wordset_id" value="<?php echo esc_attr($wordset_id); ?>" />
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($wordset_url); ?>" />
                                <?php wp_nonce_field('ll_tools_enable_vocab_lessons_for_wordset_' . $wordset_id, 'll_tools_enable_vocab_lessons_nonce'); ?>
                                <button type="submit" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-enable-lessons-button">
                                    <?php echo esc_html($enable_lessons_button_label); ?>
                                </button>
                            </form>
                        </div>
                    <?php else : ?>
                        <?php echo esc_html__('No lesson categories yet.', 'll-tools-text-domain'); ?>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <?php if ($visible_category_count > 1) : ?>
                    <div class="ll-wordset-grid-tools">
                        <div class="ll-wordset-progress-search ll-wordset-progress-search--wordset-page">
                            <label class="screen-reader-text" for="ll-wordset-page-search-input"><?php echo esc_html__('Search words or translations', 'll-tools-text-domain'); ?></label>
                            <input
                                id="ll-wordset-page-search-input"
                                class="ll-wordset-progress-search__input"
                                type="search"
                                data-ll-wordset-page-search
                                placeholder="<?php echo esc_attr__('Search words or translations', 'll-tools-text-domain'); ?>"
                                autocomplete="off"
                            />
                            <span class="ll-wordset-progress-search__loading" data-ll-wordset-page-search-loading hidden aria-hidden="true"></span>
                        </div>
                        <button
                            type="button"
                            class="ll-wordset-select-all ll-wordset-progress-select-all<?php echo $summary_counts_deferred ? ' is-loading' : ''; ?>"
                            data-ll-wordset-select-all
                            aria-pressed="false"
                            <?php if ($summary_counts_deferred) : ?>disabled aria-disabled="true" aria-busy="true"<?php endif; ?>>
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
                                    <?php
                                    $current_preview_limit = isset($cat['preview_limit']) ? (int) $cat['preview_limit'] : 2;
                                    if ($current_preview_limit < 1) {
                                        $current_preview_limit = 1;
                                    }
                                    ?>
                                    <?php if (!empty($cat['preview'])) : ?>
                                        <?php
                                        $preview_items = array_values((array) $cat['preview']);
                                        $preview_count = count($preview_items);
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
                                                    <img src="<?php echo esc_url($preview['url']); ?>" alt="<?php echo esc_attr($preview['alt'] ?? ''); ?>"<?php echo $preview_width_attr . $preview_height_attr; ?> loading="lazy" decoding="async" fetchpriority="low" />
                                                </span>
                                            <?php else : ?>
                                                <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                                                    <span class="ll-wordset-preview-text" dir="auto"><?php echo esc_html($preview['label'] ?? ''); ?></span>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php for ($i = $displayed_count; $i < $current_preview_limit; $i++) : ?>
                                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                                        <?php endfor; ?>
                                    <?php else : ?>
                                        <?php for ($i = 0; $i < $current_preview_limit; $i++) : ?>
                                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="ll-wordset-card__progress" aria-hidden="true">
                                <span class="ll-wordset-card__progress-track<?php echo $summary_counts_deferred ? ' is-loading' : ''; ?>">
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered" style="width: <?php echo esc_attr(number_format((float) $card_mastered_pct, 2, '.', '')); ?>%;">
                                        <?php echo ll_tools_wordset_page_render_progress_icon('mastered', 'll-wordset-progress-pill__icon ll-wordset-card__progress-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied" style="width: <?php echo esc_attr(number_format((float) $card_studied_pct, 2, '.', '')); ?>%;">
                                        <?php echo ll_tools_wordset_page_render_progress_icon('studied', 'll-wordset-progress-pill__icon ll-wordset-card__progress-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: <?php echo esc_attr(number_format((float) $card_new_pct, 2, '.', '')); ?>%;">
                                        <?php echo ll_tools_wordset_page_render_progress_icon('new', 'll-wordset-progress-pill__icon ll-wordset-card__progress-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                </span>
                            </div>
                            <div class="ll-wordset-card__quiz-actions" role="group" aria-label="<?php echo esc_attr(sprintf(__('Quiz modes for %s', 'll-tools-text-domain'), $cat['name'])); ?>">
                                <?php
                                $card_modes = ll_tools_get_study_launch_mode_order($gender_enabled && !empty($cat['gender_supported']));
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
                <?php if ($visible_category_count > 1) : ?>
                    <div class="ll-wordset-empty ll-wordset-empty--search" data-ll-wordset-page-search-empty hidden>
                        <?php echo esc_html__('No categories match this search.', 'll-tools-text-domain'); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            $selection_priority_focus = function_exists('ll_tools_wordset_page_selection_priority_focus')
                ? ll_tools_wordset_page_selection_priority_focus((string) ($goals['priority_focus'] ?? ''))
                : '';
            $selection_priority_label = function_exists('ll_tools_wordset_page_selection_priority_only_label')
                ? ll_tools_wordset_page_selection_priority_only_label($selection_priority_focus)
                : esc_html__('Priority words only', 'll-tools-text-domain');
            ?>
            <div class="ll-wordset-selection-bar" data-ll-wordset-selection-bar hidden>
                <span class="ll-wordset-selection-bar__text" data-ll-wordset-selection-text><?php echo esc_html__('Select categories to study together', 'll-tools-text-domain'); ?></span>
                <label class="ll-wordset-selection-bar__priority-toggle<?php echo $selection_priority_focus !== '' ? ' ll-wordset-selection-bar__priority-toggle--' . esc_attr($selection_priority_focus) : ''; ?>" aria-label="<?php echo esc_attr($selection_priority_label); ?>" hidden>
                    <input type="checkbox" data-ll-wordset-selection-priority-only />
                    <span class="ll-wordset-selection-bar__priority-icon" data-ll-wordset-selection-priority-icon aria-hidden="true"><?php echo ll_tools_wordset_page_render_priority_focus_icon($selection_priority_focus, 'll-wordset-selection-bar__priority-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="ll-wordset-selection-bar__priority-text" data-ll-wordset-selection-priority-label><?php echo esc_html($selection_priority_label); ?></span>
                </label>
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
                    $selection_modes = ll_tools_get_study_launch_mode_order($gender_mode_available);
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

        $games_pattern = '^' . preg_quote($slug, '/') . '/games/?$';
        $games_target = 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=games';
        add_rewrite_rule($games_pattern, $games_target, 'top');
    }

    if (get_transient('ll_tools_vocab_lesson_flush_rewrite')) {
        flush_rewrite_rules(false);
        delete_transient('ll_tools_vocab_lesson_flush_rewrite');
    }
}
add_action('init', 'll_tools_register_wordset_page_rewrite_rules', 19);

function ll_tools_wordset_page_maybe_redirect_query_request(): void {
    $redirect_url = ll_tools_wordset_page_get_query_request_redirect_url();
    if ($redirect_url === '') {
        return;
    }

    wp_safe_redirect($redirect_url, 301, 'LL Tools Wordset Page');
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_maybe_redirect_query_request', 1);

add_filter('redirect_canonical', function ($redirect_url) {
    if (ll_tools_is_wordset_page_context()) {
        return false;
    }
    return $redirect_url;
});

function ll_tools_wordset_page_body_class(array $classes): array {
    if (!ll_tools_is_wordset_page_context()) {
        return $classes;
    }

    $view = sanitize_key(ll_tools_get_requested_wordset_page_view_raw());
    if ($view === '') {
        $view = 'main';
    }

    $blocked_layout_classes = [
        'ast-left-sidebar',
        'ast-right-sidebar',
        'ast-both-sidebar',
        'left-sidebar',
        'right-sidebar',
        'both-sidebar',
    ];
    $classes = array_values(array_filter($classes, static function ($class) use ($blocked_layout_classes) {
        return !in_array($class, $blocked_layout_classes, true);
    }));

    $classes[] = 'ast-no-sidebar';
    $classes[] = 'll-tools-wordset-page-context';
    $classes[] = 'll-tools-wordset-view-' . sanitize_html_class($view);
    return array_values(array_unique($classes));
}
add_filter('body_class', 'll_tools_wordset_page_body_class', 99);

function ll_tools_wordset_page_force_astra_sidebar_layout($layout) {
    if (!ll_tools_is_wordset_page_context()) {
        return $layout;
    }
    return 'no-sidebar';
}
add_filter('astra_page_layout', 'll_tools_wordset_page_force_astra_sidebar_layout', 20);

function ll_tools_wordset_page_force_astra_content_layout($layout) {
    if (!ll_tools_is_wordset_page_context()) {
        return $layout;
    }
    return 'full-width-container';
}
add_filter('astra_get_content_layout', 'll_tools_wordset_page_force_astra_content_layout', 20);

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
    if (ll_tools_get_wordset_page_view() === 'games') {
        ll_enqueue_asset_by_timestamp('/css/wordset-games.css', 'll-wordset-games-css', ['ll-wordset-pages-css']);
    }
}

function ll_tools_render_wordset_games_shell(array $args): string {
    $wordset_term = $args['wordset_term'] ?? null;
    if (!$wordset_term instanceof WP_Term) {
        return '';
    }

    $games_catalog = is_array($args['games_catalog'] ?? null) ? $args['games_catalog'] : [];
    $speaking_hidden_notice = is_array($args['speaking_hidden_notice'] ?? null) ? $args['speaking_hidden_notice'] : [];
    $is_study_user = !empty($args['is_study_user']);
    $back_url = isset($args['back_url']) ? (string) $args['back_url'] : '';
    $as_modal = !empty($args['as_modal']);
    $is_open = !empty($args['is_open']);
    $title_id = 'll-wordset-games-title-' . (int) $wordset_term->term_id . ($as_modal ? '-modal' : '-page');
    $show_speaking_notice = !empty($speaking_hidden_notice['show']) && trim((string) ($speaking_hidden_notice['message'] ?? '')) !== '';
    $speaking_notice_message = trim((string) ($speaking_hidden_notice['message'] ?? ''));
    $speaking_notice_url = trim((string) ($speaking_hidden_notice['settings_url'] ?? ''));
    $speaking_notice_label = trim((string) ($speaking_hidden_notice['settings_label'] ?? ''));
    $round_options = function_exists('ll_tools_wordset_games_round_options')
        ? ll_tools_wordset_games_round_options()
        : [20, 50, 100, 'all'];
    $default_round_option = function_exists('ll_tools_wordset_games_default_round_option')
        ? ll_tools_wordset_games_default_round_option()
        : 50;
    if ($speaking_notice_label === '') {
        $speaking_notice_label = __('Open speaking settings', 'll-tools-text-domain');
    }

    ob_start();
    if ($as_modal) :
    ?>
        <div class="ll-wordset-games-modal" data-ll-wordset-games-modal <?php if (!$is_open) : ?>hidden<?php endif; ?>>
            <div class="ll-wordset-games-modal__backdrop" data-ll-wordset-games-modal-dismiss aria-hidden="true"></div>
            <section class="ll-wordset-games-modal__dialog" data-ll-wordset-games-modal-dialog role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>">
    <?php endif; ?>
        <header class="ll-wordset-subpage-head">
            <a class="ll-wordset-back ll-vocab-lesson-back" data-ll-wordset-games-back href="<?php echo esc_url($back_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_term->name)); ?>">
                <span class="ll-wordset-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                    <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                        <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="ll-wordset-back__label" data-ll-wordset-games-back-label><?php echo esc_html($wordset_term->name); ?></span>
            </a>
            <h1 class="ll-wordset-title" id="<?php echo esc_attr($title_id); ?>" data-ll-wordset-games-page-title><?php echo esc_html__('Games', 'll-tools-text-domain'); ?></h1>
        </header>

        <section class="ll-wordset-games-page" data-ll-wordset-games-root>
            <section
                class="ll-wordset-games-length"
                data-ll-wordset-game-length-picker
                aria-label="<?php echo esc_attr__('Words per game', 'll-tools-text-domain'); ?>">
                <p class="ll-wordset-games-length__label"><?php echo esc_html__('Words per game', 'll-tools-text-domain'); ?></p>
                <div class="ll-wordset-games-length__options" role="group" aria-label="<?php echo esc_attr__('Words per game', 'll-tools-text-domain'); ?>">
                    <?php foreach ($round_options as $round_option) : ?>
                        <?php
                        $is_all_option = is_string($round_option) && strtolower(trim($round_option)) === 'all';
                        $option_value = $is_all_option ? 'all' : (string) ((int) $round_option);
                        $is_selected = (string) $default_round_option === $option_value;
                        $option_label = $is_all_option
                            ? __('All', 'll-tools-text-domain')
                            : (string) ((int) $round_option);
                        $option_aria_label = $is_all_option
                            ? __('All available words', 'll-tools-text-domain')
                            : sprintf(
                                /* translators: %s: number of words per game */
                                __('%s words per game', 'll-tools-text-domain'),
                                (string) ((int) $round_option)
                            );
                        ?>
                        <button
                            type="button"
                            class="ll-wordset-games-length__option"
                            data-ll-wordset-game-length-option
                            data-word-count="<?php echo esc_attr($option_value); ?>"
                            aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
                            <span class="screen-reader-text"><?php echo esc_html($option_aria_label); ?></span>
                            <span aria-hidden="true"><?php echo esc_html($option_label); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="ll-wordset-games-catalog" data-ll-wordset-games-catalog>
                <?php foreach ($games_catalog as $game_slug => $game_row) : ?>
                    <?php if (!is_array($game_row)) { continue; } ?>
                    <?php $initially_hidden = in_array((string) $game_slug, ['speaking-practice', 'speaking-stack'], true); ?>
                    <article class="ll-wordset-game-card" data-ll-wordset-game-card data-game-slug="<?php echo esc_attr((string) $game_slug); ?>"<?php if ($initially_hidden) : ?> hidden<?php endif; ?>>
                        <div class="ll-wordset-game-card__icon" aria-hidden="true">
                            <?php
                            echo function_exists('ll_tools_wordset_games_render_game_icon')
                                ? ll_tools_wordset_games_render_game_icon((string) $game_slug, 'll-wordset-game-card__icon-svg')
                                : (function_exists('ll_tools_wordset_games_render_icon') ? ll_tools_wordset_games_render_icon('ll-wordset-game-card__icon-svg') : ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </div>
                        <div class="ll-wordset-game-card__body">
                            <h2 class="ll-wordset-game-card__title"><?php echo esc_html((string) ($game_row['title'] ?? '')); ?></h2>
                            <p class="ll-wordset-game-card__description"><?php echo esc_html((string) ($game_row['description'] ?? '')); ?></p>
                            <p class="ll-wordset-game-card__status" data-ll-wordset-game-status>
                                <?php
                                echo esc_html(
                                    $is_study_user
                                        ? __('Checking game availability...', 'll-tools-text-domain')
                                        : __('Sign in to play with your in-progress words.', 'll-tools-text-domain')
                                );
                                ?>
                            </p>
                        </div>
                        <div class="ll-wordset-game-card__actions">
                            <span class="ll-wordset-game-card__count" data-ll-wordset-game-count aria-label="<?php echo esc_attr__('Eligible words', 'll-tools-text-domain'); ?>">&#8212;</span>
                            <button
                                type="button"
                                class="ll-wordset-game-card__launch"
                                data-ll-wordset-game-launch
                                disabled>
                                <?php echo esc_html($is_study_user ? _x('Play', 'launch game action', 'll-tools-text-domain') : __('Sign in', 'll-tools-text-domain')); ?>
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="ll-wordset-games-notice" data-ll-wordset-games-speaking-notice<?php if (!$show_speaking_notice) : ?> hidden<?php endif; ?>>
                <p class="ll-wordset-games-notice__text" data-ll-wordset-games-speaking-notice-text><?php echo esc_html($speaking_notice_message); ?></p>
                <a
                    class="ll-wordset-games-notice__link"
                    data-ll-wordset-games-speaking-notice-link
                    href="<?php echo esc_url($speaking_notice_url !== '' ? $speaking_notice_url : '#'); ?>"
                    <?php if ($speaking_notice_url === '') : ?>hidden<?php endif; ?>>
                    <?php echo esc_html($speaking_notice_label); ?>
                </a>
            </div>

            <?php if (!$is_study_user) : ?>
                <?php
                echo ll_tools_render_login_window([
                    'container_class' => 'll-wordset-empty ll-wordset-login-window ll-wordset-games-login-window',
                    'title' => __('Sign in to play games', 'll-tools-text-domain'),
                    'message' => __('Games use your in-progress words and save results to your practice history.', 'll-tools-text-domain'),
                    'submit_label' => __('Continue', 'll-tools-text-domain'),
                    'redirect_to' => ll_tools_get_current_request_url(),
                    'show_registration' => true,
                    'registration_title' => __('Create learner account', 'll-tools-text-domain'),
                    'registration_submit_label' => __('Create account', 'll-tools-text-domain'),
                ]);
                ?>
            <?php endif; ?>

            <div class="ll-wordset-game-run-modal" data-ll-wordset-game-run-modal hidden>
                <div class="ll-wordset-game-run-modal__backdrop" data-ll-wordset-game-run-dismiss aria-hidden="true"></div>
                <section class="ll-wordset-game-run-modal__dialog" data-ll-wordset-game-run-dialog role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Wordset game', 'll-tools-text-domain'); ?>">
                    <section class="ll-wordset-game-stage" data-ll-wordset-game-stage data-ll-wordset-active-game="" hidden>
                        <div class="ll-wordset-game-stage__hud">
                            <div class="ll-wordset-game-stage__stats">
                                <span class="ll-wordset-game-stage__stat">
                                    <span class="screen-reader-text"><?php echo esc_html__('Coins', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-wordset-game-stage__stat-icon" aria-hidden="true">◎</span>
                                    <span data-ll-wordset-game-coins>0</span>
                                </span>
                                <span class="ll-wordset-game-stage__stat">
                                    <span class="screen-reader-text"><?php echo esc_html__('Lives', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-wordset-game-stage__stat-icon" aria-hidden="true">✦</span>
                                    <span data-ll-wordset-game-lives>3</span>
                                </span>
                            </div>
                            <div class="ll-wordset-game-stage__hud-actions">
                                <button
                                    type="button"
                                    class="ll-wordset-game-stage__nav ll-wordset-game-stage__nav--replay ll-prompt-audio-button"
                                    data-ll-wordset-game-replay-audio
                                    aria-label="<?php echo esc_attr__('Replay prompt', 'll-tools-text-domain'); ?>">
                                    <span class="ll-repeat-audio-ui">
                                        <span class="ll-repeat-icon-wrap" aria-hidden="true">
                                            <span class="ll-audio-play-icon" aria-hidden="true">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true">
                                                    <path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-audio-mini-visualizer" aria-hidden="true">
                                            <span class="bar" data-bar="1"></span>
                                            <span class="bar" data-bar="2"></span>
                                            <span class="bar" data-bar="3"></span>
                                            <span class="bar" data-bar="4"></span>
                                            <span class="bar" data-bar="5"></span>
                                            <span class="bar" data-bar="6"></span>
                                        </span>
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    class="ll-wordset-game-stage__nav ll-wordset-game-stage__nav--pause"
                                    data-ll-wordset-game-pause-toggle
                                    aria-label="<?php echo esc_attr__('Pause run', 'll-tools-text-domain'); ?>">
                                    <span aria-hidden="true" data-ll-wordset-game-pause-icon">&#10074;&#10074;</span>
                                </button>
                            </div>
                        </div>

                        <div class="ll-wordset-game-stage__canvas-wrap">
                            <canvas
                                class="ll-wordset-game-stage__canvas"
                                data-ll-wordset-game-canvas
                                width="720"
                                height="960"
                                aria-label="<?php echo esc_attr__('Wordset game board', 'll-tools-text-domain'); ?>"></canvas>
                            <section class="ll-wordset-speaking-stack-stage" data-ll-wordset-speaking-stack-stage hidden aria-live="polite">
                                <div class="ll-wordset-speaking-stack-stage__topline">
                                    <span class="ll-wordset-speaking-stack-stage__progress" data-ll-wordset-speaking-stack-progress><?php echo esc_html__('0 left', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-wordset-speaking-stack-stage__status" data-ll-wordset-speaking-stack-status><?php echo esc_html__('Mic ready', 'll-tools-text-domain'); ?></span>
                                </div>
                                <div class="ll-wordset-speaking-stack-stage__heard-row" data-ll-wordset-speaking-stack-heard-row hidden>
                                    <span class="ll-wordset-speaking-stack-stage__heard-label"><?php echo esc_html__('Heard', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-wordset-speaking-stack-stage__heard" data-ll-wordset-speaking-stack-heard></span>
                                </div>
                                <div class="ll-wordset-speaking-stack-stage__meter" data-ll-wordset-speaking-stack-meter aria-hidden="true">
                                    <?php for ($stack_meter_index = 0; $stack_meter_index < 12; $stack_meter_index++) : ?>
                                        <span class="ll-wordset-speaking-stack-stage__meter-bar"></span>
                                    <?php endfor; ?>
                                </div>
                            </section>
                            <section class="ll-wordset-lineup-stage" data-ll-wordset-lineup-stage hidden aria-live="polite">
                                <div class="ll-wordset-lineup-stage__topline">
                                    <span class="ll-wordset-lineup-stage__progress" data-ll-wordset-lineup-progress><?php echo esc_html__('Sequence 0 of 0', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-wordset-lineup-stage__category" data-ll-wordset-lineup-category></span>
                                </div>
                                <p class="ll-wordset-lineup-stage__instruction" data-ll-wordset-lineup-instruction><?php echo esc_html__('Put the cards in the correct order.', 'll-tools-text-domain'); ?></p>
                                <p class="ll-wordset-lineup-stage__status" data-ll-wordset-lineup-status hidden></p>
                                <ol class="ll-wordset-lineup-stage__cards" data-ll-wordset-lineup-cards></ol>
                                <div class="ll-wordset-lineup-stage__actions">
                                    <button type="button" class="ll-wordset-lineup-stage__action ll-wordset-lineup-stage__action--ghost" data-ll-wordset-lineup-shuffle><?php echo esc_html__('Shuffle', 'll-tools-text-domain'); ?></button>
                                    <button type="button" class="ll-wordset-lineup-stage__action ll-wordset-lineup-stage__action--primary" data-ll-wordset-lineup-check><?php echo esc_html__('Check', 'll-tools-text-domain'); ?></button>
                                    <button type="button" class="ll-wordset-lineup-stage__action ll-wordset-lineup-stage__action--primary" data-ll-wordset-lineup-next hidden><?php echo esc_html__('Next', 'll-tools-text-domain'); ?></button>
                                </div>
                            </section>
                        </div>

                            <section class="ll-wordset-speaking-stage" data-ll-wordset-speaking-stage hidden aria-live="polite">
                                <div class="ll-wordset-speaking-stage__topline">
                                    <span class="ll-wordset-speaking-stage__round" data-ll-wordset-speaking-round></span>
                                    <span class="ll-wordset-speaking-stage__status" data-ll-wordset-speaking-status><?php echo esc_html__('Get ready...', 'll-tools-text-domain'); ?></span>
                                </div>

                            <div class="ll-wordset-speaking-stage__prompt-shell">
                                <div class="ll-wordset-speaking-stage__prompt-card" data-ll-wordset-speaking-prompt-card>
                                    <figure class="ll-wordset-speaking-stage__image-frame" data-ll-wordset-speaking-image-wrap hidden>
                                        <img class="ll-wordset-speaking-stage__image" data-ll-wordset-speaking-image alt="" />
                                    </figure>
                                    <div class="ll-wordset-speaking-stage__text-prompt" data-ll-wordset-speaking-text-wrap hidden>
                                        <p class="ll-wordset-speaking-stage__text" data-ll-wordset-speaking-text></p>
                                    </div>
                                </div>
                            </div>

                            <div class="ll-wordset-speaking-stage__meter" data-ll-wordset-speaking-meter aria-hidden="true">
                                <?php for ($meter_index = 0; $meter_index < 12; $meter_index++) : ?>
                                    <span class="ll-wordset-speaking-stage__meter-bar"></span>
                                <?php endfor; ?>
                            </div>

                            <div class="ll-wordset-speaking-stage__actions" data-ll-wordset-speaking-actions>
                                <button
                                    type="button"
                                    class="ll-wordset-speaking-stage__action ll-wordset-speaking-stage__action--record"
                                    data-ll-wordset-speaking-record
                                    data-speaking-state="idle"
                                    aria-label="<?php echo esc_attr__('Start recording', 'll-tools-text-domain'); ?>">
                                    <span class="ll-wordset-speaking-stage__record-icon" aria-hidden="true">
                                        <?php echo ll_tools_wordset_page_render_record_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="screen-reader-text" data-ll-wordset-speaking-record-label><?php echo esc_html__('Start recording', 'll-tools-text-domain'); ?></span>
                                </button>
                            </div>

                            <section class="ll-wordset-speaking-stage__result" data-ll-wordset-speaking-result hidden>
                                <div class="ll-wordset-speaking-stage__comparison">
                                    <div class="ll-wordset-speaking-stage__comparison-card">
                                        <div class="ll-wordset-speaking-stage__comparison-head">
                                            <p class="ll-wordset-speaking-stage__comparison-label"><?php echo esc_html__('You said', 'll-tools-text-domain'); ?></p>
                                            <button
                                                type="button"
                                                class="ll-wordset-speaking-stage__audio-button ll-prompt-audio-button"
                                                data-ll-wordset-speaking-play-attempt
                                                aria-label="<?php echo esc_attr__('Play your recording', 'll-tools-text-domain'); ?>"
                                                hidden>
                                                <span class="ll-repeat-audio-ui">
                                                    <span class="ll-repeat-icon-wrap" aria-hidden="true">
                                                        <span class="ll-audio-play-icon" aria-hidden="true">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true">
                                                                <path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/>
                                                            </svg>
                                                        </span>
                                                    </span>
                                                    <span class="ll-audio-mini-visualizer" aria-hidden="true">
                                                        <span class="bar" data-bar="1"></span>
                                                        <span class="bar" data-bar="2"></span>
                                                        <span class="bar" data-bar="3"></span>
                                                        <span class="bar" data-bar="4"></span>
                                                        <span class="bar" data-bar="5"></span>
                                                        <span class="bar" data-bar="6"></span>
                                                    </span>
                                                </span>
                                            </button>
                                        </div>
                                        <p class="ll-wordset-speaking-stage__comparison-value" data-ll-wordset-speaking-transcript></p>
                                    </div>
                                    <div class="ll-wordset-speaking-stage__comparison-card" data-ll-wordset-speaking-target-row hidden>
                                        <div class="ll-wordset-speaking-stage__comparison-head">
                                            <p class="ll-wordset-speaking-stage__comparison-label"><?php echo esc_html__('Correct audio', 'll-tools-text-domain'); ?></p>
                                            <button
                                                type="button"
                                                class="ll-wordset-speaking-stage__audio-button ll-prompt-audio-button"
                                                data-ll-wordset-speaking-play-correct
                                                aria-label="<?php echo esc_attr__('Play correct audio', 'll-tools-text-domain'); ?>"
                                                hidden>
                                                <span class="ll-repeat-audio-ui">
                                                    <span class="ll-repeat-icon-wrap" aria-hidden="true">
                                                        <span class="ll-audio-play-icon" aria-hidden="true">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true">
                                                                <path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/>
                                                            </svg>
                                                        </span>
                                                    </span>
                                                    <span class="ll-audio-mini-visualizer" aria-hidden="true">
                                                        <span class="bar" data-bar="1"></span>
                                                        <span class="bar" data-bar="2"></span>
                                                        <span class="bar" data-bar="3"></span>
                                                        <span class="bar" data-bar="4"></span>
                                                        <span class="bar" data-bar="5"></span>
                                                        <span class="bar" data-bar="6"></span>
                                                    </span>
                                                </span>
                                            </button>
                                        </div>
                                        <p class="ll-wordset-speaking-stage__comparison-meta" data-ll-wordset-speaking-target-label><?php echo esc_html__('Target', 'll-tools-text-domain'); ?></p>
                                        <p class="ll-wordset-speaking-stage__comparison-value" data-ll-wordset-speaking-target></p>
                                        <dl class="ll-wordset-speaking-stage__details">
                                            <div class="ll-wordset-speaking-stage__detail" data-ll-wordset-speaking-title-row hidden>
                                                <dt><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></dt>
                                                <dd data-ll-wordset-speaking-title></dd>
                                            </div>
                                            <div class="ll-wordset-speaking-stage__detail" data-ll-wordset-speaking-ipa-row hidden>
                                                <dt><?php echo esc_html__('IPA', 'll-tools-text-domain'); ?></dt>
                                                <dd data-ll-wordset-speaking-ipa></dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                                <div class="ll-wordset-speaking-stage__scoreline">
                                    <span class="ll-wordset-speaking-stage__bucket" data-ll-wordset-speaking-bucket></span>
                                    <span class="ll-wordset-speaking-stage__score" data-ll-wordset-speaking-score></span>
                                </div>
                                <div class="ll-wordset-speaking-stage__bar-track" aria-hidden="true">
                                    <span class="ll-wordset-speaking-stage__bar-fill" data-ll-wordset-speaking-bar></span>
                                </div>
                                <audio data-ll-wordset-speaking-attempt-audio preload="none"></audio>
                                <audio data-ll-wordset-speaking-correct-audio preload="none"></audio>
                                <div class="ll-wordset-speaking-stage__result-actions" data-ll-wordset-speaking-result-actions>
                                    <button type="button" class="ll-wordset-speaking-stage__icon-action ll-wordset-speaking-stage__icon-action--ghost" data-ll-wordset-speaking-retry aria-label="<?php echo esc_attr__('Retry', 'll-tools-text-domain'); ?>">
                                        <?php echo ll_tools_wordset_page_render_reset_icon('ll-wordset-speaking-stage__icon-action-svg'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </button>
                                    <button type="button" class="ll-wordset-speaking-stage__icon-action" data-ll-wordset-speaking-next aria-label="<?php echo esc_attr__('Next', 'll-tools-text-domain'); ?>">
                                        <svg class="ll-wordset-speaking-stage__icon-action-svg" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                                            <path d="M9.5 5.5L16 12l-6.5 6.5"></path>
                                            <path d="M15.5 12H4.5"></path>
                                        </svg>
                                    </button>
                                </div>
                            </section>
                        </section>

                        <div class="ll-wordset-game-stage__controls" data-ll-wordset-game-controls>
                            <button type="button" class="ll-wordset-game-stage__control" data-ll-wordset-game-control="left" aria-label="<?php echo esc_attr__('Move left', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-game-stage__control-icon" aria-hidden="true">
                                    <svg class="ll-wordset-game-stage__control-arrow" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                        <path d="M14.5 5.5L8 12l6.5 6.5"></path>
                                        <path d="M8.5 12H19.5"></path>
                                    </svg>
                                </span>
                            </button>
                            <button type="button" class="ll-wordset-game-stage__control ll-wordset-game-stage__control--fire" data-ll-wordset-game-control="fire" aria-label="<?php echo esc_attr__('Fire or press space bar', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-game-stage__control-fire-stack" aria-hidden="true">
                                    <svg class="ll-wordset-game-stage__control-burst" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                        <path d="M12 2.75L13.98 8.02L19.25 6.04L16.98 11.31L22.25 13.29L16.98 15.27L19.25 20.54L13.98 18.56L12 23.25L10.02 18.56L4.75 20.54L7.02 15.27L1.75 13.29L7.02 11.31L4.75 6.04L10.02 8.02L12 2.75Z"></path>
                                    </svg>
                                    <span class="ll-wordset-game-stage__control-keycap ll-wordset-game-stage__control-keycap--space" data-ll-wordset-game-fire-keycap>
                                        <span class="ll-wordset-game-stage__control-keycap-bar"></span>
                                    </span>
                                </span>
                            </button>
                            <button type="button" class="ll-wordset-game-stage__control" data-ll-wordset-game-control="right" aria-label="<?php echo esc_attr__('Move right', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-game-stage__control-icon" aria-hidden="true">
                                    <svg class="ll-wordset-game-stage__control-arrow" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                        <path d="M9.5 5.5L16 12l-6.5 6.5"></path>
                                        <path d="M15.5 12H4.5"></path>
                                    </svg>
                                </span>
                            </button>
                        </div>

                        <div class="ll-wordset-game-stage__overlay" data-ll-wordset-game-overlay hidden>
                            <div class="ll-wordset-game-stage__loading" data-ll-wordset-game-loading hidden role="status" aria-live="polite">
                                <div class="ll-tools-loading-animation ll-wordset-game-stage__loading-animation" aria-hidden="true"></div>
                                <span class="screen-reader-text" data-ll-wordset-game-loading-text><?php echo esc_html__('Preparing game...', 'll-tools-text-domain'); ?></span>
                            </div>
                            <div class="ll-wordset-game-stage__overlay-card" data-ll-wordset-game-overlay-card>
                                <h2 data-ll-wordset-game-overlay-title></h2>
                                <p data-ll-wordset-game-overlay-summary></p>
                                <button type="button" class="ll-wordset-game-stage__overlay-button" data-ll-wordset-game-replay><?php echo esc_html__('Replay', 'll-tools-text-domain'); ?></button>
                                <button type="button" class="ll-wordset-game-stage__overlay-button ll-wordset-game-stage__overlay-button--ghost" data-ll-wordset-game-return><?php echo esc_html__('Back to games', 'll-tools-text-domain'); ?></button>
                            </div>
                        </div>
                    </section>
                </section>
            </div>
        </section>
    <?php if ($as_modal) : ?>
            </section>
        </div>
    <?php
    endif;

    return (string) ob_get_clean();
}
