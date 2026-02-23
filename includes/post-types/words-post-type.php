<?php // File: includes/post-types/words-post-type.php
/** 
 * Register the "words" custom post type and the "word-category" custom taxonomy.
 * 
 * This file contains the code to define and register the "words" custom post type, which is used to manage vocabulary words.
 * It also registers the "word-category" custom taxonomy, which is used to categorize the vocabulary words.
 */


/**
 * Registers the "words" custom post type.
 *
 * @return void
 */
function ll_tools_register_words_post_type() {

    $labels = [
        "name" => esc_html__( "Words", "ll-tools-text-domain" ),
        "singular_name" => esc_html__( "Word", "ll-tools-text-domain" ),
    ];

    $args = [
        "label" => esc_html__( "Words", "ll-tools-text-domain" ),
        "labels" => $labels,
        "description" => "",
        "public" => true,
        "publicly_queryable" => true,
        "show_ui" => true,
        "show_in_rest" => true,
        "rest_base" => "",
        "rest_controller_class" => "WP_REST_Posts_Controller",
        "rest_namespace" => "wp/v2",
        "has_archive" => false,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "delete_with_user" => false,
        "exclude_from_search" => false,
        "capability_type" => "post",
        "map_meta_cap" => true,
        "hierarchical" => false,
        "can_export" => false,
        "rewrite" => [ "slug" => "words", "with_front" => true ],
        "query_var" => true,
        "supports" => [ "title", "editor", "thumbnail", "custom-fields" ],
        "show_in_graphql" => false,
    ];

    register_post_type( "words", $args );
}

add_action( 'init', 'll_tools_register_words_post_type', 0 );

/**
 *  Words metadata functions
 */

if (!defined('LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY')) {
    define('LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY', '_ll_specific_wrong_answer_ids');
}
if (!defined('LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY')) {
    define('LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY', '_ll_specific_wrong_answer_texts');
}
if (!defined('LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION')) {
    define('LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION', 'll_tools_specific_wrong_answer_owner_map');
}

// Hook to add the meta boxes
add_action('add_meta_boxes', 'll_tools_add_similar_words_metabox');

/**
 * Adds the Similar Words meta box to the "words" post type.
 *
 * @return void
 */
function ll_tools_add_similar_words_metabox() {
    add_meta_box(
        'similar_words_meta', // ID of the meta box
        __('Similar Words', 'll-tools-text-domain'), // Title of the meta box
        'll_tools_similar_words_metabox_callback', // Callback function
        'words', // Post type
        'side', // Context
        'default' // Priority
    );
}

// The callback function to display the meta box content
function ll_tools_similar_words_metabox_callback($post) {
    // Add a nonce field for security
    wp_nonce_field('similar_words_meta', 'similar_words_meta_nonce');

    // Retrieve the current value if it exists
    $similar_word_id = get_post_meta($post->ID, 'similar_word_id', true);

    // Display the meta box HTML
    echo '<p>' . esc_html__('Enter the Post ID of a word that looks similar:', 'll-tools-text-domain') . '</p>';
    echo '<input type="text" id="similar_word_id" name="similar_word_id" value="' . esc_attr($similar_word_id) . '" class="widefat" />';
    echo '<p>' . esc_html__('Find the Post ID in the list of words. Use numerical ID only.', 'll-tools-text-domain') . '</p>';
}

// Hook to save the post metadata
add_action('save_post', 'll_tools_save_similar_words_metadata');

// Function to save the metadata
function ll_tools_save_similar_words_metadata($post_id) {
    // Check if the nonce is set and valid
    if (!isset($_POST['similar_words_meta_nonce']) || !wp_verify_nonce($_POST['similar_words_meta_nonce'], 'similar_words_meta')) {
        return;
    }

    // Check if the current user has permission to edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check if the similar word ID is set and save it
    if (isset($_POST['similar_word_id'])) {
        $similar_word_id = sanitize_text_field($_POST['similar_word_id']);
        update_post_meta($post_id, 'similar_word_id', $similar_word_id);
    }
}

/**
 * Normalize the configured per-word specific wrong-answer IDs.
 *
 * @param mixed $raw_ids Raw IDs from meta or form input.
 * @param int $self_word_id Word ID the list belongs to (to avoid self-references).
 * @param bool $validate_existing_words Whether to keep only existing `words` posts.
 * @return array
 */
function ll_tools_normalize_specific_wrong_answer_ids($raw_ids, $self_word_id = 0, bool $validate_existing_words = false): array {
    $tokens = [];
    if (is_string($raw_ids)) {
        $tokens = preg_split('/[\s,]+/', $raw_ids);
    } elseif (is_array($raw_ids)) {
        foreach ($raw_ids as $raw) {
            if (is_string($raw) && (strpos($raw, ',') !== false || strpos($raw, "\n") !== false || strpos($raw, "\r") !== false || strpos($raw, "\t") !== false || strpos($raw, ' ') !== false)) {
                $parts = preg_split('/[\s,]+/', $raw);
                if (is_array($parts)) {
                    $tokens = array_merge($tokens, $parts);
                }
            } else {
                $tokens[] = $raw;
            }
        }
    } elseif ($raw_ids !== null && $raw_ids !== '') {
        $tokens = [$raw_ids];
    }

    $self_word_id = (int) $self_word_id;
    $ids = [];
    foreach ((array) $tokens as $token) {
        $id = (int) $token;
        if ($id <= 0) {
            continue;
        }
        if ($self_word_id > 0 && $id === $self_word_id) {
            continue;
        }
        $ids[$id] = true;
    }

    $ids = array_map('intval', array_keys($ids));
    if (empty($ids) || !$validate_existing_words) {
        return $ids;
    }

    $existing = get_posts([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'post__in'         => $ids,
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'post__in',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    $existing_lookup = [];
    foreach ((array) $existing as $existing_id) {
        $existing_lookup[(int) $existing_id] = true;
    }

    return array_values(array_filter($ids, function ($id) use ($existing_lookup) {
        return isset($existing_lookup[(int) $id]);
    }));
}

/**
 * Build a normalized comparison key for specific wrong-answer text.
 *
 * @param string $text
 * @return string
 */
function ll_tools_specific_wrong_answer_text_key(string $text): string {
    $text = trim((string) wp_strip_all_tags($text));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string) $text, 'UTF-8');
    }
    return strtolower((string) $text);
}

/**
 * Normalize configured per-word specific wrong-answer texts.
 *
 * @param mixed $raw_texts Raw text list from meta or form input.
 * @param string $exclude_text Text to exclude (usually the correct answer title).
 * @return array<int,string>
 */
function ll_tools_normalize_specific_wrong_answer_texts($raw_texts, string $exclude_text = ''): array {
    $tokens = [];
    if (is_string($raw_texts)) {
        $tokens = preg_split('/[\r\n]+/', $raw_texts);
    } elseif (is_array($raw_texts)) {
        foreach ($raw_texts as $raw) {
            if (is_string($raw) && (strpos($raw, "\n") !== false || strpos($raw, "\r") !== false)) {
                $parts = preg_split('/[\r\n]+/', $raw);
                if (is_array($parts)) {
                    $tokens = array_merge($tokens, $parts);
                }
            } else {
                $tokens[] = $raw;
            }
        }
    } elseif ($raw_texts !== null && $raw_texts !== '') {
        $tokens = [$raw_texts];
    }

    $exclude_key = ll_tools_specific_wrong_answer_text_key($exclude_text);
    $normalized = [];
    foreach ((array) $tokens as $token) {
        $text = sanitize_text_field((string) $token);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            continue;
        }
        $key = ll_tools_specific_wrong_answer_text_key($text);
        if ($key === '' || $key === $exclude_key) {
            continue;
        }
        if (!isset($normalized[$key])) {
            $normalized[$key] = $text;
        }
    }

    return array_values($normalized);
}

/**
 * Get specific wrong-answer texts configured on a single word.
 *
 * @param int $word_id
 * @return array<int,string>
 */
function ll_tools_get_word_specific_wrong_answer_texts($word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }
    $raw = get_post_meta($word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, true);
    $exclude = (string) get_the_title($word_id);
    return ll_tools_normalize_specific_wrong_answer_texts($raw, $exclude);
}

/**
 * Get specific wrong-answer IDs configured on a single word.
 *
 * @param int $word_id
 * @return array
 */
function ll_tools_get_word_specific_wrong_answer_ids($word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }
    $raw = get_post_meta($word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, true);
    return ll_tools_normalize_specific_wrong_answer_ids($raw, $word_id, false);
}

/**
 * Build and persist reverse ownership map: wrong-answer word ID => owner word IDs.
 *
 * @return array
 */
function ll_tools_rebuild_specific_wrong_answer_owner_map(): array {
    $owner_ids = get_posts([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'meta_query'       => [
            [
                'key'     => LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY,
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    $owner_to_wrong_ids = [];
    $all_wrong_ids = [];
    foreach ((array) $owner_ids as $owner_id_raw) {
        $owner_id = (int) $owner_id_raw;
        if ($owner_id <= 0) {
            continue;
        }
        $wrong_ids = ll_tools_get_word_specific_wrong_answer_ids($owner_id);
        if (empty($wrong_ids)) {
            continue;
        }
        $owner_to_wrong_ids[$owner_id] = $wrong_ids;
        foreach ($wrong_ids as $wrong_id) {
            $all_wrong_ids[(int) $wrong_id] = true;
        }
    }

    $existing_wrong_lookup = [];
    if (!empty($all_wrong_ids)) {
        $existing_wrong_ids = get_posts([
            'post_type'        => 'words',
            'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
            'post__in'         => array_map('intval', array_keys($all_wrong_ids)),
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'post__in',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        foreach ((array) $existing_wrong_ids as $wrong_id_raw) {
            $existing_wrong_lookup[(int) $wrong_id_raw] = true;
        }
    }

    $map = [];
    foreach ($owner_to_wrong_ids as $owner_id => $wrong_ids) {
        foreach ((array) $wrong_ids as $wrong_id_raw) {
            $wrong_id = (int) $wrong_id_raw;
            if ($wrong_id <= 0 || !isset($existing_wrong_lookup[$wrong_id])) {
                continue;
            }
            if (!isset($map[$wrong_id])) {
                $map[$wrong_id] = [];
            }
            $map[$wrong_id][$owner_id] = true;
        }
    }

    $normalized = [];
    foreach ($map as $wrong_id => $owners_lookup) {
        $owners = array_map('intval', array_keys((array) $owners_lookup));
        sort($owners, SORT_NUMERIC);
        if (!empty($owners)) {
            $normalized[(int) $wrong_id] = $owners;
        }
    }
    ksort($normalized, SORT_NUMERIC);

    update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $normalized, false);
    return $normalized;
}

/**
 * Read reverse ownership map for specific wrong answers.
 *
 * @return array
 */
function ll_tools_get_specific_wrong_answer_owner_map(): array {
    $raw = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null);
    if (!is_array($raw)) {
        return ll_tools_rebuild_specific_wrong_answer_owner_map();
    }

    $normalized = [];
    foreach ($raw as $wrong_id_raw => $owners_raw) {
        $wrong_id = (int) $wrong_id_raw;
        if ($wrong_id <= 0 || !is_array($owners_raw)) {
            continue;
        }
        $owners_lookup = [];
        foreach ($owners_raw as $owner_id_raw) {
            $owner_id = (int) $owner_id_raw;
            if ($owner_id > 0) {
                $owners_lookup[$owner_id] = true;
            }
        }
        if (empty($owners_lookup)) {
            continue;
        }
        $owners = array_map('intval', array_keys($owners_lookup));
        sort($owners, SORT_NUMERIC);
        $normalized[$wrong_id] = $owners;
    }
    ksort($normalized, SORT_NUMERIC);
    return $normalized;
}

/**
 * Build a lookup of words that are configured as wrong-answer-only.
 *
 * @return array<int,bool> Map of word_id => true.
 */
function ll_tools_get_specific_wrong_answer_only_word_lookup(): array {
    $owner_map = ll_tools_get_specific_wrong_answer_owner_map();
    if (empty($owner_map)) {
        return [];
    }

    $lookup = [];
    foreach ($owner_map as $wrong_id_raw => $owners_raw) {
        $wrong_id = (int) $wrong_id_raw;
        if ($wrong_id <= 0) {
            continue;
        }
        $owner_ids = array_values(array_filter(array_map('intval', (array) $owners_raw), static function ($owner_id): bool {
            return $owner_id > 0;
        }));
        if (empty($owner_ids)) {
            continue;
        }

        // A word is "wrong-answer-only" only when it has owners but does not define
        // its own specific wrong answers (reserved distractor pattern).
        $own_specific_ids = ll_tools_get_word_specific_wrong_answer_ids($wrong_id);
        $own_specific_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
            ? ll_tools_get_word_specific_wrong_answer_texts($wrong_id)
            : [];
        if (empty($own_specific_ids) && empty($own_specific_texts)) {
            $lookup[$wrong_id] = true;
        }
    }

    return $lookup;
}

/**
 * Filter out words that are configured as wrong-answer-only.
 *
 * @param array $word_ids
 * @return array<int,int>
 */
function ll_tools_filter_specific_wrong_answer_only_word_ids(array $word_ids): array {
    $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $word_ids), static function ($word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $wrong_only_lookup = ll_tools_get_specific_wrong_answer_only_word_lookup();
    if (empty($wrong_only_lookup)) {
        return $word_ids;
    }

    return array_values(array_filter($word_ids, static function ($word_id) use ($wrong_only_lookup): bool {
        return !isset($wrong_only_lookup[(int) $word_id]);
    }));
}

/**
 * Collect related category IDs for cache invalidation.
 *
 * @param int $owner_word_id
 * @param array $related_word_ids
 * @return array
 */
function ll_tools_collect_specific_wrong_answer_related_category_ids($owner_word_id, array $related_word_ids = []): array {
    $word_ids = array_values(array_unique(array_filter(array_map('intval', array_merge([(int) $owner_word_id], $related_word_ids)), function ($id) {
        return $id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $category_lookup = [];
    foreach ($word_ids as $word_id) {
        $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($term_ids) || empty($term_ids)) {
            continue;
        }
        foreach ((array) $term_ids as $term_id_raw) {
            $term_id = (int) $term_id_raw;
            if ($term_id > 0) {
                $category_lookup[$term_id] = true;
            }
        }
    }

    return array_map('intval', array_keys($category_lookup));
}

/**
 * Add metabox for configuring per-word specific wrong answers.
 */
function ll_tools_add_specific_wrong_answers_metabox() {
    add_meta_box(
        'll-tools-specific-wrong-answers',
        __('Specific Wrong Answers', 'll-tools-text-domain'),
        'll_tools_render_specific_wrong_answers_metabox',
        'words',
        'side',
        'default'
    );
}
add_action('add_meta_boxes_words', 'll_tools_add_specific_wrong_answers_metabox');

/**
 * Render metabox UI for specific wrong answers.
 *
 * @param WP_Post $post
 * @return void
 */
function ll_tools_render_specific_wrong_answers_metabox($post): void {
    if (!$post || $post->post_type !== 'words') {
        return;
    }

    wp_nonce_field('ll_tools_specific_wrong_answers_meta', 'll_tools_specific_wrong_answers_meta_nonce');

    $selected_ids = ll_tools_get_word_specific_wrong_answer_ids((int) $post->ID);
    $selected_lookup = array_fill_keys($selected_ids, true);
    $selected_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
        ? ll_tools_get_word_specific_wrong_answer_texts((int) $post->ID)
        : [];

    $category_ids = wp_get_post_terms((int) $post->ID, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($category_ids)) {
        $category_ids = [];
    }
    $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) {
        return $id > 0;
    }));

    $candidate_args = [
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => 250,
        'fields'           => 'ids',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'post__not_in'     => [(int) $post->ID],
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ];
    if (!empty($category_ids)) {
        $candidate_args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => $category_ids,
        ]];
    }

    $candidate_ids = get_posts($candidate_args);
    $candidate_lookup = [];
    foreach ((array) $candidate_ids as $candidate_id_raw) {
        $candidate_id = (int) $candidate_id_raw;
        if ($candidate_id > 0) {
            $candidate_lookup[$candidate_id] = true;
        }
    }
    foreach ($selected_ids as $selected_id) {
        $candidate_lookup[(int) $selected_id] = true;
    }

    $all_candidate_ids = array_map('intval', array_keys($candidate_lookup));
    sort($all_candidate_ids, SORT_NUMERIC);

    echo '<p>' . esc_html__('Choose words that should appear only as wrong answers for this word.', 'll-tools-text-domain') . '</p>';
    echo '<select name="ll_specific_wrong_answer_ids[]" multiple size="10" class="widefat">';

    foreach ($all_candidate_ids as $candidate_id) {
        if ($candidate_id <= 0 || $candidate_id === (int) $post->ID) {
            continue;
        }

        $display = '';
        if (function_exists('ll_tools_word_grid_resolve_display_text')) {
            $resolved = ll_tools_word_grid_resolve_display_text($candidate_id);
            $word_text = trim((string) ($resolved['word_text'] ?? ''));
            $translation_text = trim((string) ($resolved['translation_text'] ?? ''));
            if ($word_text !== '' && $translation_text !== '') {
                $display = $word_text . ' - ' . $translation_text;
            } elseif ($word_text !== '') {
                $display = $word_text;
            } elseif ($translation_text !== '') {
                $display = $translation_text;
            }
        }
        if ($display === '') {
            $title = get_the_title($candidate_id);
            $display = $title !== '' ? $title : sprintf(__('Word #%d', 'll-tools-text-domain'), $candidate_id);
        }

        echo '<option value="' . esc_attr($candidate_id) . '" ' . selected(isset($selected_lookup[$candidate_id]), true, false) . '>';
        echo esc_html($display . ' (#' . $candidate_id . ')');
        echo '</option>';
    }

    echo '</select>';
    echo '<p class="description">' . esc_html__('Selected words are excluded from prompt rounds across quiz modes and only appear as wrong answers for this word.', 'll-tools-text-domain') . '</p>';
    echo '<p>' . esc_html__('Or enter custom wrong-answer text (one per line):', 'll-tools-text-domain') . '</p>';
    echo '<textarea name="ll_specific_wrong_answer_texts" rows="4" class="widefat" dir="auto">'
        . esc_textarea(implode("\n", $selected_texts))
        . '</textarea>';
    echo '<p class="description">' . esc_html__('Text entries are used directly for text-based quiz options and do not need separate word posts.', 'll-tools-text-domain') . '</p>';
}

/**
 * Save specific wrong-answer selections from the word edit screen.
 *
 * @param int $post_id
 * @param WP_Post $post
 * @param bool $update
 * @return void
 */
function ll_tools_save_specific_wrong_answers_metabox($post_id, $post, $update): void {
    if (!$post || $post->post_type !== 'words') {
        return;
    }
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    if (!isset($_POST['ll_tools_specific_wrong_answers_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ll_tools_specific_wrong_answers_meta_nonce'])), 'll_tools_specific_wrong_answers_meta')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $previous_ids = ll_tools_get_word_specific_wrong_answer_ids((int) $post_id);
    $previous_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
        ? ll_tools_get_word_specific_wrong_answer_texts((int) $post_id)
        : [];
    $incoming = isset($_POST['ll_specific_wrong_answer_ids']) ? (array) wp_unslash($_POST['ll_specific_wrong_answer_ids']) : [];
    $next_ids = ll_tools_normalize_specific_wrong_answer_ids($incoming, (int) $post_id, true);
    $incoming_text = isset($_POST['ll_specific_wrong_answer_texts']) ? wp_unslash($_POST['ll_specific_wrong_answer_texts']) : '';
    $exclude_text = (string) get_the_title((int) $post_id);
    $next_texts = function_exists('ll_tools_normalize_specific_wrong_answer_texts')
        ? ll_tools_normalize_specific_wrong_answer_texts($incoming_text, $exclude_text)
        : [];

    if (!empty($next_ids)) {
        update_post_meta($post_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, $next_ids);
    } else {
        delete_post_meta($post_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY);
    }
    if (!empty($next_texts)) {
        update_post_meta($post_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, $next_texts);
    } else {
        delete_post_meta($post_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY);
    }

    $changed = (count($previous_ids) !== count($next_ids));
    if (!$changed) {
        foreach ($previous_ids as $idx => $value) {
            if (!isset($next_ids[$idx]) || (int) $next_ids[$idx] !== (int) $value) {
                $changed = true;
                break;
            }
        }
    }
    if (!$changed && count($previous_texts) !== count($next_texts)) {
        $changed = true;
    }
    if (!$changed) {
        foreach ($previous_texts as $idx => $value) {
            if (!isset($next_texts[$idx]) || (string) $next_texts[$idx] !== (string) $value) {
                $changed = true;
                break;
            }
        }
    }
    if (!$changed) {
        return;
    }

    ll_tools_rebuild_specific_wrong_answer_owner_map();
    if (function_exists('ll_tools_bump_category_cache_version')) {
        $related_category_ids = ll_tools_collect_specific_wrong_answer_related_category_ids((int) $post_id, array_merge($previous_ids, $next_ids));
        if (!empty($related_category_ids)) {
            ll_tools_bump_category_cache_version($related_category_ids);
        }
    }
}
add_action('save_post_words', 'll_tools_save_specific_wrong_answers_metabox', 20, 3);

/**
 * Capture specific wrong-answer relationships before deleting a word.
 *
 * @param int $post_id
 * @return void
 */
function ll_tools_before_delete_word_specific_wrong_answers($post_id): void {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'words') {
        return;
    }

    $related_word_ids = ll_tools_get_word_specific_wrong_answer_ids((int) $post_id);
    $category_ids = ll_tools_collect_specific_wrong_answer_related_category_ids((int) $post_id, $related_word_ids);
    $GLOBALS['ll_tools_specific_wrong_answer_delete_ctx'][(int) $post_id] = [
        'category_ids' => $category_ids,
    ];
}
add_action('before_delete_post', 'll_tools_before_delete_word_specific_wrong_answers', 10, 1);

/**
 * Rebuild reverse map and invalidate related category caches after a word is deleted.
 *
 * @param int $post_id
 * @return void
 */
function ll_tools_after_delete_word_specific_wrong_answers($post_id): void {
    $post_id = (int) $post_id;
    $ctx_all = isset($GLOBALS['ll_tools_specific_wrong_answer_delete_ctx']) && is_array($GLOBALS['ll_tools_specific_wrong_answer_delete_ctx'])
        ? $GLOBALS['ll_tools_specific_wrong_answer_delete_ctx']
        : [];
    if (!isset($ctx_all[$post_id]) || !is_array($ctx_all[$post_id])) {
        return;
    }
    $ctx = $ctx_all[$post_id];
    unset($ctx_all[$post_id]);
    $GLOBALS['ll_tools_specific_wrong_answer_delete_ctx'] = $ctx_all;

    ll_tools_rebuild_specific_wrong_answer_owner_map();
    if (!function_exists('ll_tools_bump_category_cache_version')) {
        return;
    }

    $category_ids = isset($ctx['category_ids']) && is_array($ctx['category_ids'])
        ? array_values(array_filter(array_map('intval', $ctx['category_ids']), function ($id) { return $id > 0; }))
        : [];
    if (!empty($category_ids)) {
        ll_tools_bump_category_cache_version($category_ids);
    }
}
add_action('deleted_post', 'll_tools_after_delete_word_specific_wrong_answers', 10, 1);

/**
 * Displays the content of custom fields on the "words" posts.
 *
 * @param string $content The original post content.
 * @return string Modified content with vocab details prepended.
 */
function ll_tools_display_vocab_content($content) {
    // Only modify output on single 'words' posts inside the main loop.
    if (is_singular('words') && in_the_loop() && is_main_query()) {
        global $post;

        // Retrieve custom field values for this word.
        $word_audio_url                = function_exists('ll_get_word_audio_url') ? ll_get_word_audio_url($post->ID) : '';
        $word_english_meaning          = get_post_meta($post->ID, 'word_english_meaning', true);
        $word_example_sentence         = get_post_meta($post->ID, 'word_example_sentence', true);
        $word_example_translation      = get_post_meta($post->ID, 'word_example_sentence_translation', true);

        // Build the category list, including any parent terms.
        $word_categories_content = '';
        $word_categories = get_the_terms($post->ID, 'word-category');
        if (!empty($word_categories) && !is_wp_error($word_categories)) {
            $word_categories_content .= '<div class="word-categories">' . esc_html__('Word categories:', 'll-tools-text-domain') . ' ';
            $category_links = array();

            foreach ($word_categories as $category) {
                // Decode any HTML entities, then escape for safe output.
                $decoded_name = html_entity_decode($category->name, ENT_QUOTES, 'UTF-8');
                $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">'
                                    . ll_tools_esc_html_display($decoded_name)
                                    . '</a>';

                // Walk up the parent chain to include ancestors.
                while ($category->parent != 0) {
                    $category = get_term($category->parent, 'word-category');
                    if (is_wp_error($category) || ! $category) {
                        break;
                    }
                    $decoded_parent = html_entity_decode($category->name, ENT_QUOTES, 'UTF-8');
                    $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">'
                                        . ll_tools_esc_html_display($decoded_parent)
                                        . '</a>';
                }
            }

            // Remove duplicates and join with commas.
            $category_links = array_unique($category_links);
            $word_categories_content .= implode(', ', $category_links);
            $word_categories_content .= '</div>';
        }

        // Begin assembling the custom vocab display.
        $custom_content = "<div class='vocab-item'>";

        // Prepend the category list.
        $custom_content .= $word_categories_content;

        // Display the featured image if one is set.
        if (has_post_thumbnail($post->ID)) {
            if (function_exists('ll_tools_get_post_thumbnail_html_with_repair')) {
                $custom_content .= ll_tools_get_post_thumbnail_html_with_repair(
                    $post->ID,
                    'full',
                    array('class' => 'vocab-featured-image')
                );
            } else {
                $custom_content .= get_the_post_thumbnail(
                    $post->ID,
                    'full',
                    array('class' => 'vocab-featured-image')
                );
            }
        }

        // Show the English meaning as a heading.
        $custom_content .= '<h2>' . esc_html__('Meaning:', 'll-tools-text-domain') . ' ' . ll_tools_esc_html_display($word_english_meaning) . '</h2>';

        // Show example sentence and its translation, if available.
        if ($word_example_sentence && $word_example_translation) {
            $custom_content .= '<p>' . ll_tools_esc_html_display($word_example_sentence) . '</p>';
            $custom_content .= '<p><em>' . ll_tools_esc_html_display($word_example_translation) . '</em></p>';
        }

        // Include an audio player if an audio file URL is provided.
        if ($word_audio_url) {
            $custom_content .= '<audio controls src="' . esc_url($word_audio_url) . '"></audio>';
        }

        $custom_content .= "</div>";

        // Prepend our custom vocab block to the existing post content.
        $content = $custom_content . $content;
    }

    return $content;
}
add_filter('the_content', 'll_tools_display_vocab_content');

/**
 * Words admin page functions
 */

// Modify the "Words" admin page
function ll_modify_words_admin_page() {
    add_filter('manage_words_posts_columns', 'll_modify_words_columns');
    add_action('manage_words_posts_custom_column', 'll_render_words_columns', 10, 2);
    add_filter('manage_edit-words_sortable_columns', 'll_make_words_columns_sortable');
    add_action('restrict_manage_posts', 'll_add_words_filters');
    add_action('pre_get_posts', 'll_apply_words_filters');
}
add_action('admin_init', 'll_modify_words_admin_page');

// Modify the columns in the "Words" table
function ll_modify_words_columns($columns) {
    unset($columns['date']);
    $columns['translation'] = __('Translation', 'll-tools-text-domain');
    $columns['word_categories'] = __('Categories', 'll-tools-text-domain');
    $columns['featured_image'] = __('Featured Image', 'll-tools-text-domain');
    $columns['wordset'] = __('Word Set', 'll-tools-text-domain');
    $columns['audio_file'] = __('Audio Recordings', 'll-tools-text-domain');
    $columns['date'] = __('Date', 'll-tools-text-domain');
    return $columns;
}

// Render the content for custom columns
function ll_render_words_columns($column, $post_id) {
    switch ($column) {
        case 'word_categories':
            $categories = get_the_terms($post_id, 'word-category');
            if ($categories && !is_wp_error($categories)) {
                $names = array();
                foreach ($categories as $category) {
                    $names[] = $category->name;
                }
                echo implode(', ', $names);
            } else {
                echo '—';
            }
            break;

        case 'featured_image':
            if (function_exists('ll_tools_get_post_thumbnail_html_with_repair')) {
                $thumbnail = ll_tools_get_post_thumbnail_html_with_repair($post_id, 'full', array('style' => 'width:50px;height:auto;'));
            } else {
                $thumbnail = get_the_post_thumbnail($post_id, 'full', array('style' => 'width:50px;height:auto;'));
            }
            echo $thumbnail ? $thumbnail : '—';
            break;

        case 'audio_file':
            $audio_posts = get_posts([
                'post_type'      => 'word_audio',
                'post_parent'    => $post_id,
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'suppress_filters' => true,
            ]);

            if (empty($audio_posts)) {
                echo '—';
                break;
            }

            echo '<div class="ll-tools-word-audio-list">';
            foreach ($audio_posts as $audio_post_id) {
                $audio_post_id = (int) $audio_post_id;
                $edit_link = get_edit_post_link($audio_post_id);
                $types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'names']);
                $type_label = (!is_wp_error($types) && !empty($types)) ? implode(', ', $types) : __('Recording', 'll-tools-text-domain');
                $status = get_post_status($audio_post_id);
                $status_suffix = ($status && $status !== 'publish') ? ' (' . $status . ')' : '';
                $link_label = trim($type_label) !== '' ? $type_label : __('Recording', 'll-tools-text-domain');
                $link_label .= ' #' . $audio_post_id . $status_suffix;

                echo '<div class="ll-tools-word-audio-item" style="margin:0 0 6px 0;">';
                if ($edit_link) {
                    echo '<a href="' . esc_url($edit_link) . '">' . esc_html($link_label) . '</a>';
                } else {
                    echo esc_html($link_label);
                }

                $audio_path = get_post_meta($audio_post_id, 'audio_file_path', true);
                if ($audio_path) {
                    $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
                    echo '<br><audio controls preload="none" style="height:30px;max-width:220px;width:100%;" src="' . esc_url($audio_url) . '"></audio>';
                }
                echo '</div>';
            }
            echo '</div>';
            break;

        case 'translation':
            // New key first; fallback to legacy for visibility if present
            $translation = get_post_meta($post_id, 'word_translation', true);
            if ($translation === '') {
                $translation = get_post_meta($post_id, 'word_english_meaning', true);
            }
            echo $translation ? esc_html($translation) : '—';
            break;

        case 'wordset':
            // Show assigned taxonomy term names (not the legacy meta ID)
            $terms = get_the_terms($post_id, 'wordset');
            if ($terms && !is_wp_error($terms)) {
                $links = array();
                foreach ($terms as $t) {
                    // Link to filter the list by this wordset
                    $url = add_query_arg(
                        array('post_type' => 'words', 'wordset' => $t->slug),
                        admin_url('edit.php')
                    );
                    $links[] = '<a href="' . esc_url($url) . '">' . esc_html($t->name) . '</a>';
                }
                echo implode(', ', $links);
            } else {
                echo '—';
            }
            break;
    }
}

// Make custom columns sortable
function ll_make_words_columns_sortable($columns) {
    $columns['word_categories'] = 'word_categories';
    $columns['featured_image'] = 'featured_image';
    $columns['translation'] = 'translation';
    $columns['wordset'] = 'wordset';
    return $columns;
}

// Add dropdown filters for categories and featured image
function ll_add_words_filters() {
    global $typenow;
    if ($typenow === 'words') {
        $selected_category = isset($_GET['word_category']) ? (string) $_GET['word_category'] : '';
        $selected_image = isset($_GET['has_image']) ? $_GET['has_image'] : '';
        $uncategorized_value = 'll_uncategorized';

        // Category filter with accurate counts
        echo '<select name="word_category">';
        echo '<option value="">' . __('All Categories', 'll-tools-text-domain') . '</option>';
        $uncategorized_count = ll_get_uncategorized_term_count('word-category', 'words');
        printf(
            '<option value="%s"%s>%s (%d)</option>',
            esc_attr($uncategorized_value),
            selected($selected_category, $uncategorized_value, false),
            esc_html__('Uncategorized', 'll-tools-text-domain'),
            intval($uncategorized_count)
        );
        ll_render_category_dropdown_with_counts('word-category', 'words', $selected_category);
        echo '</select>';

        // Word set filter
        $selected_wordset = isset($_GET['wordset']) ? $_GET['wordset'] : '';
        $wordsets = get_terms(array(
            'taxonomy' => 'wordset',
            'hide_empty' => false,
        ));

        echo '<select name="wordset">';
        echo '<option value="">' . __('All Word Sets', 'll-tools-text-domain') . '</option>';
        foreach ($wordsets as $wordset) {
            echo '<option value="' . $wordset->slug . '"' . selected($selected_wordset, $wordset->slug, false) . '>' . $wordset->name . '</option>';
        }
        echo '</select>';

        // Featured image filter
        echo '<select name="has_image">';
        echo '<option value="">' . __('Has Featured Image', 'll-tools-text-domain') . '</option>';
        echo '<option value="yes"' . selected($selected_image, 'yes', false) . '>' . __('Yes', 'll-tools-text-domain') . '</option>';
        echo '<option value="no"' . selected($selected_image, 'no', false) . '>' . __('No', 'll-tools-text-domain') . '</option>';
        echo '</select>';
    }
}

/**
 * Renders category dropdown options with accurate published post counts
 *
 * @param string $taxonomy  Taxonomy slug (always 'word-category').
 * @param string $post_type Post type to count (e.g. 'words').
 * @param string $selected  Currently selected term ID.
 * @param int    $parent    Parent term ID for recursion.
 * @param int    $level     Depth level for indentation.
 */
function ll_render_category_dropdown_with_counts($taxonomy, $post_type, $selected = '', $parent = 0, $level = 0) {
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => $parent,
    ]);
    if (is_wp_error($terms)) {
        return;
    }

    foreach ($terms as $term) {
        // Count only published posts of this type in this term
        $q = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'tax_query'      => [[
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term->term_id,
            ]],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);
        $count = $q->found_posts;
        wp_reset_postdata();

        $indent = str_repeat('&nbsp;&nbsp;', $level);
        $is_selected = selected($selected, $term->term_id, false);

        printf(
            '<option value="%d"%s>%s%s (%d)</option>',
            esc_attr($term->term_id),
            $is_selected,
            $indent,
            esc_html($term->name),
            intval($count)
        );

        // Recurse into children
        ll_render_category_dropdown_with_counts($taxonomy, $post_type, $selected, $term->term_id, $level + 1);
    }
}

/**
 * Count published posts that have no assigned terms in a taxonomy.
 *
 * @param string $taxonomy  Taxonomy slug.
 * @param string $post_type Post type to count (e.g. 'words').
 * @return int
 */
function ll_get_uncategorized_term_count($taxonomy, $post_type) {
    $q = new WP_Query([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'tax_query'      => [[
            'taxonomy' => $taxonomy,
            'operator' => 'NOT EXISTS',
        ]],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ]);
    $count = $q->found_posts;
    wp_reset_postdata();

    return (int) $count;
}

// Apply the selected filters to the query
function ll_apply_words_filters($query) {
    global $pagenow;

    if (!is_admin() || $pagenow !== 'edit.php' || empty($_GET['post_type']) || $_GET['post_type'] !== 'words') {
        return;
    }

    if (!$query->is_main_query()) {
        return;
    }

    // Build a combined tax_query so category + wordset can both apply
    $tax_query = array();

    // Filter by category (term_id)
    if (!empty($_GET['word_category'])) {
        $category_filter = (string) $_GET['word_category'];
        if ($category_filter === 'll_uncategorized') {
            $tax_query[] = array(
                'taxonomy' => 'word-category',
                'operator' => 'NOT EXISTS',
            );
        } else {
            $tax_query[] = array(
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => (int) $category_filter,
            );
        }
    }

    // Filter by word set (slug from the dropdown)
    if (!empty($_GET['wordset'])) {
        $tax_query[] = array(
            'taxonomy' => 'wordset',
            'field'    => 'slug',
            'terms'    => sanitize_text_field($_GET['wordset']),
        );
    }

    if (!empty($tax_query)) {
        $query->set('tax_query', $tax_query);
    }

    // Filter by featured image
    if (!empty($_GET['has_image'])) {
        $compare = ($_GET['has_image'] === 'yes') ? 'EXISTS' : 'NOT EXISTS';
        $query->set('meta_query', array(
            array(
                'key'     => '_thumbnail_id',
                'compare' => $compare,
            ),
        ));
    }

    // Sort by translation (new key)
    if (!empty($_GET['orderby']) && $_GET['orderby'] === 'translation') {
        $query->set('meta_key', 'word_translation');
        $query->set('orderby', 'meta_value');
    }
}

/**
 * Register bulk action for marking words for reprocessing
 */
function ll_words_register_bulk_reprocess_action($bulk_actions) {
    $bulk_actions['ll_mark_reprocess'] = __('Mark for Audio Reprocessing', 'll-tools-text-domain');
    return $bulk_actions;
}
add_filter('bulk_actions-edit-words', 'll_words_register_bulk_reprocess_action');

/**
 * Handle bulk action for marking words for reprocessing
 */
function ll_words_handle_bulk_reprocess_action($redirect_to, $doaction, $post_ids) {
    if ($doaction !== 'll_mark_reprocess') {
        return $redirect_to;
    }

    $processed = 0;
    $skipped = 0;

    foreach ($post_ids as $word_post_id) {
        // Find all word_audio children of this word
        $audio_posts = get_posts([
            'post_type' => 'word_audio',
            'post_parent' => $word_post_id,
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if (!empty($audio_posts)) {
            foreach ($audio_posts as $audio_post_id) {
                update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
                $processed++;
            }
        } else {
            $skipped++;
        }
    }

    $redirect_to = add_query_arg([
        'll_reprocess_marked' => $processed,
        'll_reprocess_skipped' => $skipped
    ], $redirect_to);

    return $redirect_to;
}
add_filter('handle_bulk_actions-edit-words', 'll_words_handle_bulk_reprocess_action', 10, 3);

/**
 * Display admin notice after bulk reprocessing action
 */
function ll_words_bulk_reprocess_admin_notice() {
    if (!isset($_GET['ll_reprocess_marked'])) {
        return;
    }

    $processed = intval($_GET['ll_reprocess_marked']);
    $skipped = isset($_GET['ll_reprocess_skipped']) ? intval($_GET['ll_reprocess_skipped']) : 0;

    if ($processed > 0) {
        $message = sprintf(
            _n(
                '%d word marked for audio reprocessing.',
                '%d words marked for audio reprocessing.',
                $processed,
                'll-tools-text-domain'
            ),
            $processed
        );

        if ($skipped > 0) {
            $message .= ' ' . sprintf(
                _n(
                    '%d word skipped (no audio file).',
                    '%d words skipped (no audio file).',
                    $skipped,
                    'll-tools-text-domain'
                ),
                $skipped
            );
        }

        $processor_url = admin_url('tools.php?page=ll-audio-processor');
        $message .= sprintf(
            ' <a href="%s">%s</a>',
            esc_url($processor_url),
            __('Go to Audio Processor', 'll-tools-text-domain')
        );

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $message);
    } elseif ($skipped > 0) {
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            sprintf(
                _n(
                    '%d word skipped (no audio recordings).',
                    '%d words skipped (no audio recordings).',
                    $skipped,
                    'll-tools-text-domain'
                ),
                $skipped
            )
        );
    }
}
add_action('admin_notices', 'll_words_bulk_reprocess_admin_notice');

/**
 * Enqueue admin script for bulk edit category handling
 */
function ll_words_enqueue_bulk_edit_script($hook) {
    ll_enqueue_bulk_category_edit_script('words', 'll-words-bulk-edit', 'js/bulk-category-edit.js', 'll_words_get_common_categories');
}
add_action('admin_enqueue_scripts', 'll_words_enqueue_bulk_edit_script');

/**
 * AJAX handler to get common categories for selected posts
 */
function ll_words_get_common_categories() {
    ll_get_common_categories_for_post_type('words');
}
add_action('wp_ajax_ll_words_get_common_categories', 'll_words_get_common_categories');

/**
 * Handle bulk edit category removal - runs after WordPress processes bulk edit
 */
function ll_words_handle_bulk_edit_categories($post_id) {
    ll_handle_bulk_category_edit($post_id, 'words');
}
add_action('edit_post', 'll_words_handle_bulk_edit_categories', 999, 1);

/**
 * Prevent publishing a words post without at least one published word_audio
 */
add_action('save_post_words', 'll_validate_word_audio_before_publish', 10, 3);
add_action('rest_after_insert_words', 'll_validate_word_audio_after_rest_insert', 10, 3);
add_filter('rest_pre_dispatch', 'll_tools_track_rest_dispatch_start', 10, 3);
add_filter('rest_post_dispatch', 'll_tools_track_rest_dispatch_end', 10, 3);

/**
 * Mark active REST dispatch scope.
 *
 * @param mixed           $result
 * @param WP_REST_Server  $server
 * @param WP_REST_Request $request
 * @return mixed
 */
function ll_tools_track_rest_dispatch_start($result, $server, $request) {
    $depth = isset($GLOBALS['ll_tools_active_rest_request_depth'])
        ? (int) $GLOBALS['ll_tools_active_rest_request_depth']
        : 0;
    $GLOBALS['ll_tools_active_rest_request_depth'] = $depth + 1;
    $GLOBALS['ll_tools_active_rest_request'] = true;
    return $result;
}

/**
 * Clear active REST dispatch scope.
 *
 * @param mixed           $response
 * @param WP_REST_Server  $server
 * @param WP_REST_Request $request
 * @return mixed
 */
function ll_tools_track_rest_dispatch_end($response, $server, $request) {
    $depth = isset($GLOBALS['ll_tools_active_rest_request_depth'])
        ? (int) $GLOBALS['ll_tools_active_rest_request_depth']
        : 0;
    $depth = max(0, $depth - 1);
    $GLOBALS['ll_tools_active_rest_request_depth'] = $depth;
    $GLOBALS['ll_tools_active_rest_request'] = ($depth > 0);
    return $response;
}

/**
 * Detect whether current execution is serving a REST request.
 *
 * @return bool
 */
function ll_tools_is_rest_request_context() {
    if (!empty($GLOBALS['ll_tools_active_rest_request'])) {
        return true;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    if (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request()) {
        return true;
    }

    return false;
}

function ll_validate_word_audio_before_publish($post_id, $post, $update) {
    // REST saves (Gutenberg) set terms after save_post, so enforce in rest_after_insert_words instead.
    if (ll_tools_is_rest_request_context()) {
        return;
    }

    ll_enforce_word_audio_publish_requirement((int) $post_id, $post, (bool) $update);
}

/**
 * REST counterpart of the publish requirement guard.
 *
 * @param WP_Post         $post
 * @param WP_REST_Request $request
 * @param bool            $creating
 * @return void
 */
function ll_validate_word_audio_after_rest_insert($post, $request, $creating) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return;
    }

    $blocked = ll_enforce_word_audio_publish_requirement((int) $post->ID, $post, !$creating);

    // keep REST response payload aligned with persisted status when we block publish
    if ($blocked) {
        $post->post_status = 'draft';
    }
}

/**
 * Shared enforcement for the word-audio publish requirement.
 *
 * @param int     $post_id
 * @param WP_Post $post
 * @param bool    $update
 * @return bool True when publish was blocked.
 */
function ll_enforce_word_audio_publish_requirement($post_id, $post, $update) {
    static $is_enforcing = false;

    if ($is_enforcing || !($post instanceof WP_Post) || $post->post_type !== 'words') {
        return false;
    }

    if ($post_id <= 0 || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return false;
    }

    // Allow one-time skip set during programmatic creation (e.g., non-audio image imports)
    if (get_post_meta($post_id, '_ll_skip_audio_requirement_once', true) === '1') {
        delete_post_meta($post_id, '_ll_skip_audio_requirement_once');
        return false;
    }

    // Filter hook for programmatic opt-outs
    if (apply_filters('ll_tools_skip_audio_requirement', false, $post_id, $post, $update)) {
        return false;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return false;
    }

    // Only intervene if trying to publish
    if ($post->post_status !== 'publish') {
        return false;
    }

    // Skip enforcement for categories whose quiz config does not require audio
    if (!ll_word_requires_audio_to_publish($post_id)) {
        return false;
    }

    // Check if there's at least one published word_audio
    $published_audio = get_posts([
        'post_type' => 'word_audio',
        'post_parent' => $post_id,
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);

    if (!empty($published_audio)) {
        return false;
    }

    $is_enforcing = true;
    try {
        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'draft',
        ]);
    } finally {
        $is_enforcing = false;
    }

    // Set a transient to show admin notice
    set_transient('ll_word_publish_blocked_' . get_current_user_id(), $post_id, 60);
    return true;
}

/**
 * Determine whether the word needs audio based on its categories' quiz config.
 *
 * @param int $post_id Word post ID.
 * @return bool True when audio is required; false when all categories quiz without audio.
 */
function ll_word_requires_audio_to_publish($post_id) {
    // Default to requiring audio if quiz helpers aren't available
    if (!function_exists('ll_tools_get_category_quiz_config') || !function_exists('ll_tools_quiz_requires_audio')) {
        return true;
    }

    $cat_ids = wp_get_post_terms((int) $post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($cat_ids) || empty($cat_ids)) {
        return true;
    }

    $has_non_audio_category = false;
    foreach ($cat_ids as $tid) {
        $cfg = ll_tools_get_category_quiz_config((int) $tid);
        $needs_audio = ll_tools_quiz_requires_audio($cfg, isset($cfg['option_type']) ? $cfg['option_type'] : '');

        // If recording is explicitly disabled for the category, treat it as non-audio.
        if (function_exists('ll_tools_is_category_recording_disabled') && ll_tools_is_category_recording_disabled((int) $tid)) {
            $has_non_audio_category = true;
            continue;
        }

        // Legacy/text-only flag also signals no audio requirement.
        $is_text_only = get_term_meta((int) $tid, 'use_word_titles_for_audio', true) === '1';

        if (!$needs_audio || $is_text_only) {
            $has_non_audio_category = true;
        }
    }

    // If any assigned category can quiz without audio, allow publish without audio.
    // Audio remains required only when every assigned category needs it.
    return $has_non_audio_category ? false : true;
}

/**
 * Show admin notice when word publishing is blocked
 */
add_action('admin_notices', 'll_show_word_publish_blocked_notice');
function ll_show_word_publish_blocked_notice() {
    $post_id = get_transient('ll_word_publish_blocked_' . get_current_user_id());
    if (!$post_id) {
        return;
    }

    delete_transient('ll_word_publish_blocked_' . get_current_user_id());

    $edit_url = get_edit_post_link($post_id);
    $title = get_the_title($post_id);

    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>Publishing Blocked:</strong> "%s" cannot be published until it has at least one approved audio recording. The post has been saved as a draft. <a href="%s">Edit post</a></p></div>',
        esc_html($title),
        esc_url($edit_url)
    );
}

/**
 * Filter words queries to only include posts with published audio
 * This ensures unpublished words don't appear in quizzes/frontend
 */
add_action('pre_get_posts', 'll_filter_words_by_audio_status');
function ll_filter_words_by_audio_status($query) {
    // Only filter frontend queries for words post type
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') !== 'words') {
        return;
    }

    // Add a meta query to check for published audio
    $existing_meta_query = $query->get('meta_query') ?: [];

    // We can't directly join to word_audio in meta_query, so we'll use a different approach
    // We'll filter in a more direct way using posts_where
    add_filter('posts_where', 'll_filter_words_with_audio_where', 10, 2);
}

/**
 * SQL WHERE clause to filter words with published audio
 */
function ll_filter_words_with_audio_where($where, $query) {
    global $wpdb;

    // Only apply to our specific query
    if (is_admin() || $query->get('post_type') !== 'words') {
        return $where;
    }

    // Remove this filter after use to prevent affecting other queries
    remove_filter('posts_where', 'll_filter_words_with_audio_where', 10);

    // Add condition: words post must have at least one published word_audio child
    $where .= " AND {$wpdb->posts}.ID IN (
        SELECT DISTINCT post_parent
        FROM {$wpdb->posts}
        WHERE post_type = 'word_audio'
        AND post_status = 'publish'
        AND post_parent IS NOT NULL
        AND post_parent > 0
    )";

    return $where;
}
