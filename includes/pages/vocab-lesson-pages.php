<?php
// /includes/pages/vocab-lesson-pages.php
if (!defined('WPINC')) { die; }

/**
 * Auto-generated vocab lesson pages:
 * - One lesson per wordset + category pair
 * - Uses [word_grid] shortcode scoped to wordset + category
 * - URL pattern: /{wordset-slug}/{category-slug}
 */

if (!defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
    define('LL_TOOLS_VOCAB_LESSON_CATEGORY_META', '_ll_tools_vocab_category_id');
}
if (!defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')) {
    define('LL_TOOLS_VOCAB_LESSON_WORDSET_META', '_ll_tools_vocab_wordset_id');
}
if (!defined('LL_TOOLS_VOCAB_LESSON_SEPARATOR')) {
    define('LL_TOOLS_VOCAB_LESSON_SEPARATOR', '-');
}

function ll_tools_vocab_lessons_enabled(): bool {
    $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    $enabled = !empty($wordset_ids);
    return (bool) apply_filters('ll_tools_vocab_lessons_enabled', $enabled, $wordset_ids);
}

function ll_tools_get_vocab_lesson_wordset_ids(): array {
    $raw = get_option('ll_vocab_lesson_wordsets', []);
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)));
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $ids = array_map('intval', $raw);
    $ids = array_values(array_unique(array_filter($ids, function ($id) { return $id > 0; })));
    return $ids;
}

function ll_tools_vocab_lesson_build_slug(string $wordset_slug, string $category_slug): string {
    $wordset_slug  = sanitize_title($wordset_slug);
    $category_slug = sanitize_title($category_slug);
    return sanitize_title($wordset_slug . LL_TOOLS_VOCAB_LESSON_SEPARATOR . $category_slug);
}

function ll_tools_get_vocab_lesson_title(WP_Term $category, WP_Term $wordset): string {
    $display_name = function_exists('ll_tools_get_category_display_name')
        ? ll_tools_get_category_display_name($category)
        : $category->name;
    $title = sprintf(
        __('Vocab: %1$s (%2$s)', 'll-tools-text-domain'),
        $display_name,
        $wordset->name
    );

    /**
     * Filter the generated vocab lesson title.
     *
     * @param string  $title
     * @param string  $display_name
     * @param WP_Term $category
     * @param WP_Term $wordset
     */
    return (string) apply_filters('ll_tools_vocab_lesson_title', $title, $display_name, $category, $wordset);
}

function ll_tools_build_vocab_lesson_content(WP_Term $category, WP_Term $wordset): string {
    $category_slug = sanitize_title($category->slug);
    $wordset_slug  = sanitize_title($wordset->slug);
    $content = sprintf('[word_grid category="%s" wordset="%s" deepest_only="1"]', $category_slug, $wordset_slug);

    /**
     * Filter the generated vocab lesson content.
     *
     * @param string  $content
     * @param WP_Term $category
     * @param WP_Term $wordset
     */
    return (string) apply_filters('ll_tools_vocab_lesson_content', $content, $category, $wordset);
}

function ll_tools_get_vocab_lesson_quiz_min_word_count(): int {
    $default_quiz_min = defined('LL_TOOLS_MIN_WORDS_PER_QUIZ') ? (int) LL_TOOLS_MIN_WORDS_PER_QUIZ : 5;
    $quiz_min = (int) apply_filters('ll_tools_quiz_min_words', $default_quiz_min);
    if ($quiz_min < 1) {
        $quiz_min = 1;
    }
    return $quiz_min;
}

function ll_tools_get_vocab_lesson_min_word_count($category = null, int $wordset_id = 0): int {
    $lesson_min = (int) apply_filters('ll_tools_vocab_lesson_min_words', 1, $category, $wordset_id);
    if ($lesson_min < 1) {
        $lesson_min = 1;
    }
    $quiz_min = ll_tools_get_vocab_lesson_quiz_min_word_count();
    return max($lesson_min, $quiz_min);
}

function ll_tools_vocab_lesson_category_requires_images($category): bool {
    if (!function_exists('ll_tools_get_category_quiz_config')) {
        return true;
    }
    $config = ll_tools_get_category_quiz_config($category);
    $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
    $option_type = (string) ($config['option_type'] ?? '');
    $prompt_requires_image = ($prompt_type === 'image');
    $option_requires_image = ($option_type === 'image');
    return $prompt_requires_image || $option_requires_image;
}

function ll_tools_get_vocab_lesson_deepest_counts_for_wordset(int $wordset_id, bool $force_refresh = false): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return ['all' => [], 'with_images' => []];
    }

    $cache_key = 'll_vocab_lesson_deep_counts_' . $wordset_id;
    if ($force_refresh) {
        wp_cache_delete($cache_key, 'll_tools');
    } else {
        $cached = wp_cache_get($cache_key, 'll_tools');
        if ($cached !== false) {
            return $cached;
        }
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', (array) $word_ids), function ($id) { return $id > 0; }));
    if (function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids($word_ids);
    }
    if (empty($word_ids)) {
        $result = ['all' => [], 'with_images' => []];
        wp_cache_set($cache_key, $result, 'll_tools', HOUR_IN_SECONDS);
        return $result;
    }

    $terms = wp_get_object_terms($word_ids, 'word-category', ['fields' => 'all_with_object_id']);
    if (is_wp_error($terms)) {
        $result = ['all' => [], 'with_images' => []];
        wp_cache_set($cache_key, $result, 'll_tools', HOUR_IN_SECONDS);
        return $result;
    }

    $terms_by_word = [];
    foreach ($terms as $term) {
        $word_id = isset($term->object_id) ? (int) $term->object_id : 0;
        if ($word_id <= 0) {
            continue;
        }
        $terms_by_word[$word_id][] = $term;
    }

    update_meta_cache('post', $word_ids);

    $depth_cache = [];
    $get_depth = function (int $term_id) use (&$depth_cache, &$get_depth): int {
        if (isset($depth_cache[$term_id])) {
            return $depth_cache[$term_id];
        }
        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) {
            $depth_cache[$term_id] = 0;
            return 0;
        }
        $parent_id = (int) $term->parent;
        $depth = $parent_id > 0 ? ($get_depth($parent_id) + 1) : 0;
        $depth_cache[$term_id] = $depth;
        return $depth;
    };

    $all_counts = [];
    $image_counts = [];

    foreach ($word_ids as $word_id) {
        $word_terms = $terms_by_word[$word_id] ?? [];
        if (empty($word_terms)) {
            continue;
        }

        $max_depth = -1;
        $deepest_ids = [];
        foreach ($word_terms as $term) {
            $term_id = (int) $term->term_id;
            if ($term_id <= 0) {
                continue;
            }
            $depth = $get_depth($term_id);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest_ids = [$term_id];
            } elseif ($depth === $max_depth) {
                $deepest_ids[] = $term_id;
            }
        }
        if (empty($deepest_ids)) {
            continue;
        }

        $has_image = has_post_thumbnail($word_id);
        foreach ($deepest_ids as $term_id) {
            $all_counts[$term_id] = ($all_counts[$term_id] ?? 0) + 1;
            if ($has_image) {
                $image_counts[$term_id] = ($image_counts[$term_id] ?? 0) + 1;
            }
        }
    }

    $result = ['all' => $all_counts, 'with_images' => $image_counts];
    wp_cache_set($cache_key, $result, 'll_tools', HOUR_IN_SECONDS);
    return $result;
}

function ll_tools_get_vocab_lesson_category_word_count($category, int $wordset_id, array $counts = null): int {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return 0;
    }
    if (!($category instanceof WP_Term)) {
        $category = get_term($category, 'word-category');
    }
    if (!($category instanceof WP_Term) || is_wp_error($category)) {
        return 0;
    }

    if ($counts === null) {
        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id);
    }

    $cat_id = (int) $category->term_id;
    $requires_images = ll_tools_vocab_lesson_category_requires_images($category);
    if ($requires_images) {
        return (int) ($counts['with_images'][$cat_id] ?? 0);
    }
    return (int) ($counts['all'][$cat_id] ?? 0);
}

function ll_tools_can_generate_vocab_lesson($category, int $wordset_id): bool {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return false;
    }

    if (!($category instanceof WP_Term)) {
        $category = get_term($category, 'word-category');
    }
    if (!($category instanceof WP_Term) || is_wp_error($category)) {
        return false;
    }

    if (isset($category->slug) && $category->slug === 'uncategorized') {
        return false;
    }

    $min_words = ll_tools_get_vocab_lesson_min_word_count($category, $wordset_id);
    $word_count = ll_tools_get_vocab_lesson_category_word_count($category, $wordset_id);
    if ($word_count < $min_words) {
        return false;
    }

    if (function_exists('ll_can_category_generate_quiz')) {
        $quiz_min_words = ll_tools_get_vocab_lesson_quiz_min_word_count();
        if (!ll_can_category_generate_quiz($category, $quiz_min_words, [$wordset_id])) {
            return false;
        }
    }

    return true;
}

function ll_tools_get_vocab_lesson_category_ids_for_wordset(int $wordset_id, bool $force_refresh = false): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $min_words = ll_tools_get_vocab_lesson_min_word_count(null, $wordset_id);

    $cache_key = 'll_vocab_lesson_cats_' . $wordset_id . '_' . $min_words;
    if (!$force_refresh) {
        $cached = wp_cache_get($cache_key, 'll_tools');
        if ($cached !== false) {
            return $cached;
        }
    } else {
        wp_cache_delete($cache_key, 'll_tools');
    }

    $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id, $force_refresh);
    $category_ids = array_unique(array_merge(array_keys($counts['all']), array_keys($counts['with_images'])));
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) { return $id > 0; }));

    $ids = [];
    foreach ($category_ids as $category_id) {
        if (ll_tools_can_generate_vocab_lesson($category_id, $wordset_id)) {
            $ids[] = (int) $category_id;
        }
    }

    wp_cache_set($cache_key, $ids, 'll_tools', HOUR_IN_SECONDS);
    return $ids;
}

function ll_tools_get_or_create_vocab_lesson_page(int $category_id, int $wordset_id) {
    $category = get_term($category_id, 'word-category');
    $wordset  = get_term($wordset_id, 'wordset');

    if (!($category instanceof WP_Term) || is_wp_error($category)) {
        return new WP_Error('invalid_category', 'Invalid word-category term.');
    }
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error('invalid_wordset', 'Invalid wordset term.');
    }

    $slug    = ll_tools_vocab_lesson_build_slug($wordset->slug, $category->slug);
    $title   = ll_tools_get_vocab_lesson_title($category, $wordset);
    $content = ll_tools_build_vocab_lesson_content($category, $wordset);

    $meta_query = [
        'relation' => 'AND',
        [
            'key'   => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
            'value' => (string) $category->term_id,
        ],
        [
            'key'   => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
            'value' => (string) $wordset->term_id,
        ],
    ];

    $active_posts = get_posts([
        'post_type'   => 'll_vocab_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'meta_query'  => $meta_query,
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    $trashed_posts = get_posts([
        'post_type'   => 'll_vocab_lesson',
        'post_status' => 'trash',
        'meta_query'  => $meta_query,
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    $post_id = 0;
    $status  = 'created';

    if (!empty($active_posts)) {
        $post_id = (int) $active_posts[0];
        $existing = get_post($post_id);
        if ($existing) {
            $update = [
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
            ];
            if ($existing->post_name !== $slug) {
                $update['post_name'] = wp_unique_post_slug(
                    $slug,
                    $post_id,
                    $existing->post_status,
                    'll_vocab_lesson',
                    0
                );
            }
            wp_update_post($update);
        }

        foreach (array_slice($active_posts, 1) as $dup_id) {
            wp_trash_post((int) $dup_id);
        }
        foreach ($trashed_posts as $trash_id) {
            wp_delete_post((int) $trash_id, true);
        }
        $status = 'updated';
    } elseif (!empty($trashed_posts)) {
        $post_id = (int) $trashed_posts[0];
        wp_untrash_post($post_id);
        $restored_slug = wp_unique_post_slug($slug, $post_id, 'publish', 'll_vocab_lesson', 0);
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_name'    => $restored_slug,
            'post_content' => $content,
            'post_status'  => 'publish',
        ]);
        foreach (array_slice($trashed_posts, 1) as $trash_id) {
            wp_delete_post((int) $trash_id, true);
        }
        $status = 'restored';
    } else {
        $unique_slug = wp_unique_post_slug($slug, 0, 'publish', 'll_vocab_lesson', 0);
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $unique_slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'll_vocab_lesson',
            'post_parent'  => 0,
        ], true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $status = 'created';
    }

    if ($post_id && !is_wp_error($post_id)) {
        update_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category->term_id);
        update_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset->term_id);
    }

    return [
        'post_id' => (int) $post_id,
        'status'  => $status,
    ];
}

function ll_tools_trash_vocab_lesson_page(int $category_id, int $wordset_id): int {
    $meta_query = [
        'relation' => 'AND',
        [
            'key'   => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
            'value' => (string) $category_id,
        ],
        [
            'key'   => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
            'value' => (string) $wordset_id,
        ],
    ];

    $posts = get_posts([
        'post_type'   => 'll_vocab_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'meta_query'  => $meta_query,
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    $removed = 0;
    foreach ($posts as $pid) {
        wp_trash_post((int) $pid);
        $removed++;
    }
    return $removed;
}

function ll_tools_cleanup_invalid_vocab_lesson_pages(array $enabled_wordset_ids): int {
    $removed = 0;
    $enabled_wordset_ids = array_values(array_filter(array_map('intval', $enabled_wordset_ids), function ($id) { return $id > 0; }));

    $posts = get_posts([
        'post_type'   => 'll_vocab_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => -1,
        'fields'      => 'all',
    ]);

    foreach ($posts as $post) {
        $wordset_id = (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
        $category_id = (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);

        if ($wordset_id <= 0 || $category_id <= 0) {
            continue;
        }

        if (!in_array($wordset_id, $enabled_wordset_ids, true)) {
            wp_trash_post($post->ID);
            $removed++;
            continue;
        }

        $wordset = get_term($wordset_id, 'wordset');
        $category = get_term($category_id, 'word-category');
        if (!$wordset || is_wp_error($wordset) || !$category || is_wp_error($category)) {
            wp_trash_post($post->ID);
            $removed++;
            continue;
        }

        if (!ll_tools_can_generate_vocab_lesson($category, $wordset_id)) {
            wp_trash_post($post->ID);
            $removed++;
        }
    }

    return $removed;
}

function ll_tools_sync_vocab_lesson_pages($wordset_ids = null): array {
    $result = [
        'created' => 0,
        'updated' => 0,
        'removed' => 0,
    ];

    if ($wordset_ids === null) {
        $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    }
    $wordset_ids = array_values(array_filter(array_map('intval', (array) $wordset_ids), function ($id) { return $id > 0; }));
    $result['removed'] = ll_tools_cleanup_invalid_vocab_lesson_pages($wordset_ids);
    if (empty($wordset_ids)) {
        update_option('ll_tools_vocab_lesson_sync_last', time(), false);
        return $result;
    }

    foreach ($wordset_ids as $wordset_id) {
        $category_ids = ll_tools_get_vocab_lesson_category_ids_for_wordset($wordset_id, true);
        foreach ($category_ids as $category_id) {
            $created = ll_tools_get_or_create_vocab_lesson_page((int) $category_id, (int) $wordset_id);
            if (is_wp_error($created)) {
                continue;
            }
            if (($created['status'] ?? '') === 'created') {
                $result['created']++;
            } else {
                $result['updated']++;
            }
        }
    }

    update_option('ll_tools_vocab_lesson_sync_last', time(), false);
    return $result;
}

function ll_tools_sync_vocab_lessons_for_category(int $category_id) {
    $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    if (empty($wordset_ids)) {
        return;
    }

    foreach ($wordset_ids as $wordset_id) {
        ll_tools_get_vocab_lesson_deepest_counts_for_wordset((int) $wordset_id, true);
        if (ll_tools_can_generate_vocab_lesson($category_id, (int) $wordset_id)) {
            ll_tools_get_or_create_vocab_lesson_page($category_id, (int) $wordset_id);
        } else {
            ll_tools_trash_vocab_lesson_page($category_id, (int) $wordset_id);
        }
    }
}

function ll_tools_sync_vocab_lessons_for_word_post($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!$post || $post->post_type !== 'words' || $post->post_status !== 'publish') {
        return;
    }

    if (function_exists('ll_get_deepest_categories')) {
        $category_ids = wp_list_pluck((array) ll_get_deepest_categories($post_id), 'term_id');
    } else {
        $category_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    }
    $wordset_ids  = wp_get_post_terms($post_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($category_ids) || is_wp_error($wordset_ids)) {
        return;
    }

    $enabled_wordsets = ll_tools_get_vocab_lesson_wordset_ids();
    $wordset_ids = array_values(array_intersect($enabled_wordsets, array_map('intval', $wordset_ids)));
    $category_ids = array_values(array_map('intval', (array) $category_ids));

    foreach ($wordset_ids as $wordset_id) {
        ll_tools_get_vocab_lesson_deepest_counts_for_wordset((int) $wordset_id, true);
        foreach ($category_ids as $category_id) {
            if (ll_tools_can_generate_vocab_lesson($category_id, (int) $wordset_id)) {
                ll_tools_get_or_create_vocab_lesson_page($category_id, (int) $wordset_id);
            } else {
                ll_tools_trash_vocab_lesson_page($category_id, (int) $wordset_id);
            }
        }
    }
}
add_action('save_post_words', 'll_tools_sync_vocab_lessons_for_word_post', 10, 3);

function ll_tools_sync_vocab_lessons_on_term_set($object_id, $terms, $tt_ids, $taxonomy) {
    $post = get_post($object_id);
    if (!$post || $post->post_type !== 'words') {
        return;
    }
    if (!in_array($taxonomy, ['word-category', 'wordset'], true)) {
        return;
    }

    if (function_exists('ll_get_deepest_categories')) {
        $category_ids = wp_list_pluck((array) ll_get_deepest_categories($object_id), 'term_id');
    } else {
        $category_ids = wp_get_post_terms($object_id, 'word-category', ['fields' => 'ids']);
    }
    $wordset_ids  = wp_get_post_terms($object_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($category_ids) || is_wp_error($wordset_ids)) {
        return;
    }

    $enabled_wordsets = ll_tools_get_vocab_lesson_wordset_ids();
    $wordset_ids = array_values(array_intersect($enabled_wordsets, array_map('intval', $wordset_ids)));
    $category_ids = array_values(array_map('intval', (array) $category_ids));

    foreach ($wordset_ids as $wordset_id) {
        ll_tools_get_vocab_lesson_deepest_counts_for_wordset((int) $wordset_id, true);
        foreach ($category_ids as $category_id) {
            if (ll_tools_can_generate_vocab_lesson($category_id, (int) $wordset_id)) {
                ll_tools_get_or_create_vocab_lesson_page($category_id, (int) $wordset_id);
            } else {
                ll_tools_trash_vocab_lesson_page($category_id, (int) $wordset_id);
            }
        }
    }
}
add_action('set_object_terms', 'll_tools_sync_vocab_lessons_on_term_set', 10, 4);

function ll_tools_sync_vocab_lessons_before_delete($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'words') {
        return;
    }

    if (function_exists('ll_get_deepest_categories')) {
        $category_ids = wp_list_pluck((array) ll_get_deepest_categories($post_id), 'term_id');
    } else {
        $category_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    }
    $wordset_ids  = wp_get_post_terms($post_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($category_ids) || is_wp_error($wordset_ids)) {
        return;
    }

    $enabled_wordsets = ll_tools_get_vocab_lesson_wordset_ids();
    $wordset_ids = array_values(array_intersect($enabled_wordsets, array_map('intval', $wordset_ids)));
    $category_ids = array_values(array_map('intval', (array) $category_ids));

    foreach ($wordset_ids as $wordset_id) {
        ll_tools_get_vocab_lesson_deepest_counts_for_wordset((int) $wordset_id, true);
        foreach ($category_ids as $category_id) {
            if (!ll_tools_can_generate_vocab_lesson($category_id, (int) $wordset_id)) {
                ll_tools_trash_vocab_lesson_page($category_id, (int) $wordset_id);
            }
        }
    }
}
add_action('before_delete_post', 'll_tools_sync_vocab_lessons_before_delete');

function ll_tools_handle_vocab_lesson_category_sync($term_id) {
    ll_tools_sync_vocab_lessons_for_category((int) $term_id);
}
add_action('created_word-category', 'll_tools_handle_vocab_lesson_category_sync', 10, 1);
add_action('edited_word-category', 'll_tools_handle_vocab_lesson_category_sync', 10, 1);

function ll_tools_handle_vocab_lesson_category_delete($term_id) {
    $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    foreach ($wordset_ids as $wordset_id) {
        ll_tools_trash_vocab_lesson_page((int) $term_id, (int) $wordset_id);
    }
}
add_action('delete_word-category', 'll_tools_handle_vocab_lesson_category_delete', 10, 1);

function ll_tools_handle_vocab_lesson_wordset_sync($term_id) {
    $enabled_wordsets = ll_tools_get_vocab_lesson_wordset_ids();
    if (!in_array((int) $term_id, $enabled_wordsets, true)) {
        return;
    }
    ll_tools_sync_vocab_lesson_pages($enabled_wordsets);
    set_transient('ll_tools_vocab_lesson_flush_rewrite', 1, 5 * MINUTE_IN_SECONDS);
}
add_action('edited_wordset', 'll_tools_handle_vocab_lesson_wordset_sync', 10, 1);
add_action('delete_wordset', 'll_tools_handle_vocab_lesson_wordset_sync', 10, 1);

add_action('admin_init', function () {
    if (!ll_tools_vocab_lessons_enabled()) {
        return;
    }
    $last = (int) get_option('ll_tools_vocab_lesson_sync_last', 0);
    if ($last < (time() - DAY_IN_SECONDS)) {
        ll_tools_sync_vocab_lesson_pages();
        update_option('ll_tools_vocab_lesson_sync_last', time(), false);
    }
});

function ll_tools_register_vocab_lesson_query_vars($vars) {
    $vars[] = 'll_vocab_lesson_wordset';
    $vars[] = 'll_vocab_lesson_category';
    return $vars;
}
add_filter('query_vars', 'll_tools_register_vocab_lesson_query_vars');

function ll_tools_register_vocab_lesson_rewrite_rules() {
    $should_flush = (bool) get_transient('ll_tools_vocab_lesson_flush_rewrite');

    $wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    if (empty($wordset_ids)) {
        if ($should_flush) {
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
        $pattern = '^' . preg_quote($slug, '/') . '/([^/]+)/?$';
        $target  = 'index.php?ll_vocab_lesson_wordset=' . $slug . '&ll_vocab_lesson_category=$matches[1]';
        add_rewrite_rule($pattern, $target, 'top');
    }

    if ($should_flush) {
        flush_rewrite_rules(false);
        delete_transient('ll_tools_vocab_lesson_flush_rewrite');
    }
}
add_action('init', 'll_tools_register_vocab_lesson_rewrite_rules', 20);

function ll_tools_find_vocab_lesson_post_id(string $wordset_slug, string $category_slug): int {
    $wordset_slug = sanitize_title($wordset_slug);
    $category_slug = sanitize_title($category_slug);
    if ($wordset_slug === '' || $category_slug === '') {
        return 0;
    }

    $wordset = get_term_by('slug', $wordset_slug, 'wordset');
    $category = get_term_by('slug', $category_slug, 'word-category');
    if (!$wordset || is_wp_error($wordset) || !$category || is_wp_error($category)) {
        return 0;
    }

    $posts = get_posts([
        'post_type'      => 'll_vocab_lesson',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
                'value' => (string) $category->term_id,
            ],
            [
                'key'   => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                'value' => (string) $wordset->term_id,
            ],
        ],
    ]);

    return !empty($posts) ? (int) $posts[0] : 0;
}

function ll_tools_route_vocab_lesson_request($query_vars) {
    if (is_admin()) {
        return $query_vars;
    }

    $wordset_slug = $query_vars['ll_vocab_lesson_wordset'] ?? '';
    $category_slug = $query_vars['ll_vocab_lesson_category'] ?? '';
    if ($wordset_slug === '' || $category_slug === '') {
        $legacy_slug = $query_vars['ll_vocab_lesson'] ?? '';
        if ($legacy_slug !== '') {
            $GLOBALS['ll_tools_vocab_lesson_request'] = true;
            $legacy_slug = sanitize_title($legacy_slug);
            $legacy_post = get_page_by_path($legacy_slug, OBJECT, 'll_vocab_lesson');
            if ($legacy_post instanceof WP_Post) {
                return [
                    'p' => (int) $legacy_post->ID,
                    'post_type' => 'll_vocab_lesson',
                ];
            }
            $query_vars['post_type'] = 'll_vocab_lesson';
            $query_vars['error'] = '404';
            return $query_vars;
        }
        return $query_vars;
    }

    $GLOBALS['ll_tools_vocab_lesson_request'] = true;
    $post_id = ll_tools_find_vocab_lesson_post_id($wordset_slug, $category_slug);
    if ($post_id > 0) {
        $query_vars = [
            'p' => $post_id,
            'post_type' => 'll_vocab_lesson',
        ];
        return $query_vars;
    }

    $query_vars['post_type'] = 'll_vocab_lesson';
    $query_vars['error'] = '404';
    return $query_vars;
}
add_filter('request', 'll_tools_route_vocab_lesson_request');

add_filter('redirect_canonical', function ($redirect_url) {
    if (!empty($GLOBALS['ll_tools_vocab_lesson_request'])) {
        return false;
    }
    return $redirect_url;
});

function ll_tools_vocab_lesson_template_include($template) {
    if (!is_singular('ll_vocab_lesson')) {
        return $template;
    }
    if (!function_exists('ll_tools_locate_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }
    $located = ll_tools_locate_template('vocab-lesson-template.php');
    if ($located !== '') {
        return $located;
    }
    return $template;
}
add_filter('template_include', 'll_tools_vocab_lesson_template_include', 20);

function ll_tools_vocab_lesson_bootstrap_flashcards() {
    static $bootstrapped = false;
    if ($bootstrapped || !is_singular('ll_vocab_lesson')) {
        return;
    }
    $bootstrapped = true;

    if (!function_exists('ll_flashcards_enqueue_and_localize') || !function_exists('ll_qpg_print_flashcard_shell_once')) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return;
    }

    $wordset_id = (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $wordset_slug = '';
    if ($wordset_id > 0) {
        $wordset = get_term($wordset_id, 'wordset');
        if ($wordset && !is_wp_error($wordset)) {
            $wordset_slug = $wordset->slug;
        }
    }

    $wordset_spec = $wordset_slug;
    $wordset_ids = function_exists('ll_flashcards_resolve_wordset_ids')
        ? ll_flashcards_resolve_wordset_ids($wordset_spec, false)
        : [];

    $use_translations = function_exists('ll_flashcards_should_use_translations')
        ? ll_flashcards_should_use_translations()
        : false;

    if (!function_exists('ll_flashcards_build_categories') || !function_exists('ll_flashcards_pick_initial_batch')) {
        return;
    }

    [$categories, $preselected] = ll_flashcards_build_categories('', $use_translations, $wordset_ids);
    [$selected_category_data, $firstCategoryName, $words_data] = ll_flashcards_pick_initial_batch(
        $categories,
        $wordset_ids,
        $wordset_spec !== ''
    );

    $atts = [
        'mode'                 => 'random',
        'wordset'              => $wordset_spec,
        'wordset_fallback'     => false,
        'quiz_mode'            => 'practice',
        'wordset_ids_for_popup' => $wordset_ids,
    ];

    ll_flashcards_enqueue_and_localize($atts, $categories, $preselected, $words_data, $firstCategoryName);

    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once', 5);
}

function ll_tools_vocab_lesson_enqueue_assets() {
    if (!is_singular('ll_vocab_lesson')) {
        return;
    }
    ll_tools_vocab_lesson_bootstrap_flashcards();
    ll_enqueue_asset_by_timestamp('/css/vocab-lesson-pages.css', 'll-vocab-lesson-pages-css', ['ll-tools-flashcard-style']);
}
add_action('wp_enqueue_scripts', 'll_tools_vocab_lesson_enqueue_assets');

function ll_tools_vocab_lesson_post_link($post_link, $post) {
    if (!$post || $post->post_type !== 'll_vocab_lesson') {
        return $post_link;
    }
    if (!ll_tools_vocab_lessons_enabled()) {
        return $post_link;
    }

    $wordset_id = (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    if ($wordset_id <= 0 || $category_id <= 0) {
        return $post_link;
    }

    $wordset = get_term($wordset_id, 'wordset');
    $category = get_term($category_id, 'word-category');
    if (!$wordset || is_wp_error($wordset) || !$category || is_wp_error($category)) {
        return $post_link;
    }

    $url = trailingslashit(home_url($wordset->slug . '/' . $category->slug));
    return (string) apply_filters('ll_tools_vocab_lesson_permalink', $url, $post, $wordset, $category);
}
add_filter('post_type_link', 'll_tools_vocab_lesson_post_link', 10, 2);

function ll_tools_handle_enable_vocab_lessons_for_wordset_request() {
    $wordset_id = isset($_POST['ll_tools_enable_vocab_lesson_wordset_id'])
        ? (int) wp_unslash((string) $_POST['ll_tools_enable_vocab_lesson_wordset_id'])
        : 0;
    $nonce = isset($_POST['ll_tools_enable_vocab_lessons_nonce'])
        ? wp_unslash((string) $_POST['ll_tools_enable_vocab_lessons_nonce'])
        : '';
    $redirect_to = isset($_POST['redirect_to'])
        ? wp_unslash((string) $_POST['redirect_to'])
        : '';

    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    $default_redirect = admin_url('edit.php?post_type=ll_vocab_lesson');
    if ($wordset instanceof WP_Term && !is_wp_error($wordset) && function_exists('ll_tools_get_wordset_page_view_url')) {
        $default_redirect = ll_tools_get_wordset_page_view_url($wordset);
    }

    $redirect_to = wp_validate_redirect($redirect_to, '');
    if (!is_string($redirect_to) || $redirect_to === '') {
        $redirect_to = $default_redirect;
    }

    $redirect_with_status = static function (string $status, string $error = '') use ($redirect_to): void {
        $args = ['ll_wordset_lesson_enable' => $status];
        if ($error !== '') {
            $args['ll_wordset_lesson_enable_error'] = $error;
        }
        wp_safe_redirect(add_query_arg($args, $redirect_to));
        exit;
    };

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        $redirect_with_status('error', 'request');
    }

    if (!current_user_can('view_ll_tools')) {
        $redirect_with_status('error', 'permission');
    }

    if ($wordset_id <= 0 || !($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        $redirect_with_status('error', 'wordset');
    }

    if (!wp_verify_nonce($nonce, 'll_tools_enable_vocab_lessons_for_wordset_' . $wordset_id)) {
        $redirect_with_status('error', 'nonce');
    }

    $enabled_wordset_ids = ll_tools_get_vocab_lesson_wordset_ids();
    if (!in_array($wordset_id, $enabled_wordset_ids, true)) {
        $enabled_wordset_ids[] = $wordset_id;
        sort($enabled_wordset_ids, SORT_NUMERIC);
    }

    $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
    update_option('ll_vocab_lesson_wordsets', array_values($enabled_wordset_ids), false);
    $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = false;

    set_transient('ll_tools_vocab_lesson_flush_rewrite', 1, 5 * MINUTE_IN_SECONDS);
    $result = ll_tools_sync_vocab_lesson_pages($enabled_wordset_ids);
    set_transient('ll_tools_vocab_lesson_sync_notice', $result, 30);

    $redirect_with_status('ok');
}
add_action('admin_post_ll_tools_enable_vocab_lessons_for_wordset', 'll_tools_handle_enable_vocab_lessons_for_wordset_request');

function ll_tools_handle_vocab_lesson_settings_submit() {
    if (!is_admin()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php' || (($_GET['post_type'] ?? '') !== 'll_vocab_lesson')) {
        return;
    }

    if (!isset($_POST['ll_vocab_lesson_settings_nonce'])) {
        return;
    }

    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to update vocab lesson settings.', 'll-tools-text-domain'));
    }

    if (!wp_verify_nonce($_POST['ll_vocab_lesson_settings_nonce'], 'll_vocab_lesson_settings')) {
        wp_die(esc_html__('Invalid vocab lesson settings request.', 'll-tools-text-domain'));
    }

    if (isset($_POST['ll_vocab_lesson_save'])) {
        $raw_ids = isset($_POST['ll_vocab_lesson_wordsets']) ? (array) $_POST['ll_vocab_lesson_wordsets'] : [];
        $selected = array_map('intval', $raw_ids);
        $selected = array_values(array_unique(array_filter($selected, function ($id) { return $id > 0; })));

        $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
        update_option('ll_vocab_lesson_wordsets', $selected, false);
        $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = false;

        set_transient('ll_tools_vocab_lesson_flush_rewrite', 1, 5 * MINUTE_IN_SECONDS);

        $result = ll_tools_sync_vocab_lesson_pages($selected);
        set_transient('ll_tools_vocab_lesson_sync_notice', $result, 30);
    } elseif (isset($_POST['ll_vocab_lesson_sync'])) {
        $result = ll_tools_sync_vocab_lesson_pages();
        set_transient('ll_tools_vocab_lesson_sync_notice', $result, 30);
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=ll_vocab_lesson'));
    exit;
}
add_action('admin_init', 'll_tools_handle_vocab_lesson_settings_submit');

function ll_tools_render_vocab_lesson_admin_panel() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-ll_vocab_lesson') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $notice = get_transient('ll_tools_vocab_lesson_sync_notice');
    if ($notice) {
        delete_transient('ll_tools_vocab_lesson_sync_notice');
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                __('Vocab lesson sync completed. %d created, %d updated, %d removed.', 'll-tools-text-domain'),
                (int) ($notice['created'] ?? 0),
                (int) ($notice['updated'] ?? 0),
                (int) ($notice['removed'] ?? 0)
            ))
        );
    }

    $wordset_terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
    ]);
    $selected_wordsets = ll_tools_get_vocab_lesson_wordset_ids();

    echo '<div class="wrap" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">';
    echo '<h2>' . esc_html__('Vocab Lesson Settings', 'll-tools-text-domain') . '</h2>';
    echo '<p>' . esc_html__('Choose which word sets should get vocab lesson pages at /{wordset}/{category} (and a wordset landing page at /{wordset}). Saving will sync pages automatically.', 'll-tools-text-domain') . '</p>';
    echo '<form method="post">';
    wp_nonce_field('ll_vocab_lesson_settings', 'll_vocab_lesson_settings_nonce');

    if (!is_wp_error($wordset_terms) && !empty($wordset_terms)) {
        echo '<fieldset>';
        foreach ($wordset_terms as $term) {
            $checked = in_array((int) $term->term_id, $selected_wordsets, true);
            echo '<label style="display:block; margin: 6px 0;">';
            echo '<input type="checkbox" name="ll_vocab_lesson_wordsets[]" value="' . esc_attr($term->term_id) . '" ' . checked($checked, true, false) . ' /> ';
            echo esc_html($term->name);
            echo '</label>';
        }
        echo '</fieldset>';
    } else {
        echo '<p>' . esc_html__('No word sets found yet.', 'll-tools-text-domain') . '</p>';
    }

    if (empty($selected_wordsets)) {
        echo '<p style="margin-top: 10px;"><em>' . esc_html__('No word sets are enabled yet, so no vocab lesson pages will be generated.', 'll-tools-text-domain') . '</em></p>';
    }

    echo '<p style="margin-top: 12px;">';
    echo '<button type="submit" name="ll_vocab_lesson_save" class="button button-primary">' . esc_html__('Save Settings & Sync', 'll-tools-text-domain') . '</button> ';
    echo '<button type="submit" name="ll_vocab_lesson_sync" class="button button-secondary">' . esc_html__('Sync Now', 'll-tools-text-domain') . '</button>';
    echo '</p>';
    echo '</form></div>';
}
add_action('admin_notices', 'll_tools_render_vocab_lesson_admin_panel');

add_action('update_option_ll_vocab_lesson_wordsets', function ($old_value, $value) {
    if (!empty($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'])) {
        return;
    }
    $result = ll_tools_sync_vocab_lesson_pages();
    set_transient('ll_tools_vocab_lesson_sync_notice', $result, 30);
    set_transient('ll_tools_vocab_lesson_flush_rewrite', 1, 5 * MINUTE_IN_SECONDS);
}, 10, 2);
