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
            return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }

    return apply_filters('ll_tools_wordset_page_categories', $items, $wordset_id);
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
    $classes = ll_tools_wordset_page_sanitize_class_list(array_merge(
        ['ll-wordset-page'],
        (array) ($args['extra_classes'] ?? [])
    ));
    if (empty($classes)) {
        $classes = ['ll-wordset-page'];
    }

    $categories = ll_tools_get_wordset_page_categories((int) $wordset_term->term_id, $preview_limit);

    ob_start();
    ?>
    <<?php echo esc_html($wrapper_tag); ?> class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-ll-wordset-page>
        <?php if ($show_title) : ?>
            <header class="ll-wordset-hero">
                <div class="ll-wordset-hero__icon" aria-hidden="true">
                    <span class="ll-wordset-hero__dot"></span>
                    <span class="ll-wordset-hero__dot"></span>
                    <span class="ll-wordset-hero__dot"></span>
                    <span class="ll-wordset-hero__dot"></span>
                </div>
                <h1 class="ll-wordset-title"><?php echo esc_html($wordset_term->name); ?></h1>
            </header>
        <?php endif; ?>

        <?php if (empty($categories)) : ?>
            <div class="ll-wordset-empty">
                <?php echo esc_html__('No lesson categories yet.', 'll-tools-text-domain'); ?>
            </div>
        <?php else : ?>
            <div class="ll-wordset-grid" role="list">
                <?php foreach ($categories as $cat) : ?>
                    <?php
                    $preview_style = '';
                    if (!empty($cat['preview_aspect_ratio'])) {
                        $preview_style = ' style="--ll-wordset-preview-aspect: ' . esc_attr($cat['preview_aspect_ratio']) . ';"';
                    }
                    ?>
                    <a class="ll-wordset-card" href="<?php echo esc_url($cat['url']); ?>" role="listitem" aria-label="<?php echo esc_attr($cat['name']); ?>">
                        <div class="ll-wordset-card__preview <?php echo $cat['has_images'] ? 'has-images' : 'has-text'; ?>"<?php echo $preview_style; ?>>
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
                                <?php
                                $empty_preview_limit = isset($cat['preview_limit']) ? (int) $cat['preview_limit'] : 2;
                                if ($empty_preview_limit < 1) {
                                    $empty_preview_limit = 1;
                                }
                                ?>
                                <?php for ($i = 0; $i < $empty_preview_limit; $i++) : ?>
                                    <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                        <div class="ll-wordset-card__meta">
                            <h2 class="ll-wordset-card__title"><?php echo esc_html($cat['name']); ?></h2>
                            <span class="ll-wordset-card__count" aria-label="<?php echo esc_attr(sprintf(__('Words: %d', 'll-tools-text-domain'), (int) ($cat['count'] ?? 0))); ?>">
                                <?php echo (int) ($cat['count'] ?? 0); ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </<?php echo esc_html($wrapper_tag); ?>>
    <?php

    return (string) ob_get_clean();
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
    ll_tools_wordset_page_enqueue_styles();
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_page_enqueue_assets');

function ll_tools_wordset_page_enqueue_styles(): void {
    ll_enqueue_asset_by_timestamp('/css/wordset-pages.css', 'll-wordset-pages-css');
}
