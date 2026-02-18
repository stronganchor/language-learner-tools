<?php
// File: includes/post-types/dictionary-entry-post-type.php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY')) {
    define('LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY', 'll_dictionary_entry_id');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY', 'll_dictionary_entry_wordset_id');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY', 'll_dictionary_entry_pos_slug');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY', 'll_dictionary_entry_translation');
}

/**
 * Resolve the primary wordset assigned to a word.
 *
 * @param int $word_id Word post ID.
 * @return int
 */
function ll_tools_get_word_primary_wordset_id($word_id) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return 0;
    }

    $term_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return 0;
    }

    return (int) $term_ids[0];
}

/**
 * Resolve primary part-of-speech data for a word.
 *
 * @param int $word_id Word post ID.
 * @return array{slug:string,label:string}
 */
function ll_tools_get_word_primary_pos_data($word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return ['slug' => '', 'label' => ''];
    }

    $terms = wp_get_post_terms($word_id, 'part_of_speech', ['orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms) || empty($terms)) {
        return ['slug' => '', 'label' => ''];
    }

    $term = $terms[0];
    if (!($term instanceof WP_Term)) {
        return ['slug' => '', 'label' => ''];
    }

    return [
        'slug'  => (string) $term->slug,
        'label' => (string) $term->name,
    ];
}

/**
 * Resolve and cache dictionary entry wordset ID.
 *
 * @param int $entry_id Dictionary entry ID.
 * @return int
 */
function ll_tools_get_dictionary_entry_wordset_id($entry_id) {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return 0;
    }

    $stored = (int) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, true);

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, 1);
    if (!empty($word_ids)) {
        $resolved = ll_tools_get_word_primary_wordset_id((int) $word_ids[0]);
        if ($resolved > 0) {
            if ($stored !== $resolved) {
                update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $resolved);
            }
            return $resolved;
        }
    }

    if ($stored > 0) {
        return $stored;
    }

    return 0;
}

/**
 * Resolve and cache one part-of-speech slug for a dictionary entry.
 *
 * @param int $entry_id Dictionary entry ID.
 * @return string
 */
function ll_tools_get_dictionary_entry_primary_pos_slug($entry_id): string {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return '';
    }

    $stored = sanitize_title((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, true));
    if ($stored !== '') {
        return $stored;
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, 25);
    if (empty($word_ids)) {
        return '';
    }

    foreach ($word_ids as $word_id) {
        $pos_data = ll_tools_get_word_primary_pos_data((int) $word_id);
        $slug = sanitize_title((string) ($pos_data['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, $slug);
        return $slug;
    }

    return '';
}

/**
 * Return unique part-of-speech labels for linked words.
 *
 * @param int $entry_id Dictionary entry ID.
 * @return string[]
 */
function ll_tools_get_dictionary_entry_pos_labels($entry_id): array {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return [];
    }

    $stored_slug = ll_tools_get_dictionary_entry_primary_pos_slug($entry_id);
    if ($stored_slug !== '') {
        $stored_term = get_term_by('slug', $stored_slug, 'part_of_speech');
        if ($stored_term && !is_wp_error($stored_term)) {
            return [(string) $stored_term->name];
        }
        return [$stored_slug];
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, 50);
    if (!empty($word_ids)) {
        $terms = wp_get_object_terms($word_ids, 'part_of_speech', [
            'fields'  => 'all_with_object_id',
            'orderby' => 'name',
            'order'   => 'ASC',
        ]);
        if (!is_wp_error($terms) && !empty($terms)) {
            $labels = [];
            $seen = [];
            foreach ($terms as $term) {
                $slug = sanitize_title((string) ($term->slug ?? ''));
                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $labels[] = (string) ($term->name ?? '');
            }
            if (!empty($labels)) {
                return $labels;
            }
        }
    }

    return [];
}

/**
 * Normalize a lookup value for case-insensitive title comparison.
 *
 * @param string $value Raw title.
 * @return string
 */
function ll_tools_dictionary_entry_normalize_lookup_value($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Resolve dictionary entry translation text.
 *
 * @param int $entry_id Dictionary entry ID.
 * @return string
 */
function ll_tools_get_dictionary_entry_translation($entry_id): string {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return '';
    }

    return trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
}

/**
 * Resolve word and translation display text similar to word grids.
 *
 * @param int $word_id Word post ID.
 * @return array{word_text:string,translation_text:string}
 */
function ll_tools_get_word_display_with_translation($word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return [
            'word_text' => '',
            'translation_text' => '',
        ];
    }

    if (function_exists('ll_tools_word_grid_resolve_display_text')) {
        $values = ll_tools_word_grid_resolve_display_text($word_id);
        return [
            'word_text' => trim((string) ($values['word_text'] ?? '')),
            'translation_text' => trim((string) ($values['translation_text'] ?? '')),
        ];
    }

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? (bool) ll_tools_should_store_word_in_title($word_id)
        : true;
    $word_title = trim((string) get_the_title($word_id));
    $word_translation = trim((string) get_post_meta($word_id, 'word_translation', true));
    if ($store_in_title && $word_translation === '') {
        $word_translation = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
    }

    if ($store_in_title) {
        $word_text = $word_title;
        $translation_text = $word_translation;
    } else {
        $word_text = $word_translation;
        $translation_text = $word_title;
    }

    if ($word_text === '') {
        $word_text = $word_title;
    }

    return [
        'word_text' => trim((string) $word_text),
        'translation_text' => trim((string) $translation_text),
    ];
}

/**
 * Render linked words list markup with optional thumbnails.
 *
 * @param int  $entry_id            Dictionary entry ID.
 * @param int  $limit               Maximum words to render.
 * @param bool $include_view_all    Whether to include "view all" link.
 * @return string
 */
function ll_tools_get_dictionary_entry_linked_words_markup($entry_id, $limit = 25, $include_view_all = true): string {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return '';
    }

    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 25;
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, $limit);
    if (empty($word_ids)) {
        return '<span class="ll-dictionary-entry-empty">—</span>';
    }

    $all_count = ll_tools_count_dictionary_entry_words($entry_id);
    $html = '<ul class="ll-dictionary-entry-linked-list" style="margin:0;padding:0;list-style:none;">';
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $display = ll_tools_get_word_display_with_translation($word_id);
        $title = trim((string) ($display['word_text'] ?? ''));
        $translation_text = trim((string) ($display['translation_text'] ?? ''));
        if ($title === '') {
            $title = trim((string) get_the_title($word_id));
        }
        if ($title === '') {
            $title = __('(no title)', 'll-tools-text-domain');
        }
        $edit_link = get_edit_post_link($word_id);

        $thumb_html = '';
        if (has_post_thumbnail($word_id)) {
            if (function_exists('ll_tools_get_post_thumbnail_html_with_repair')) {
                $thumb_html = ll_tools_get_post_thumbnail_html_with_repair(
                    $word_id,
                    [28, 28],
                    [
                        'class' => 'll-dictionary-entry-linked-thumb',
                        'style' => 'width:28px;height:28px;display:block;border-radius:4px;object-fit:cover;',
                    ]
                );
            } else {
                $thumb_html = get_the_post_thumbnail(
                    $word_id,
                    [28, 28],
                    [
                        'class' => 'll-dictionary-entry-linked-thumb',
                        'style' => 'width:28px;height:28px;display:block;border-radius:4px;object-fit:cover;',
                    ]
                );
            }
        }

        if ($thumb_html === '') {
            $thumb_html = '<span class="ll-dictionary-entry-linked-thumb ll-dictionary-entry-linked-thumb--empty" style="display:block;width:28px;height:28px;border-radius:4px;background:#f0f0f1;"></span>';
        }

        $html .= '<li class="ll-dictionary-entry-linked-item" style="display:flex;align-items:center;gap:8px;margin:0 0 6px;">';
        $html .= '<span class="ll-dictionary-entry-linked-thumb-wrap" style="flex:0 0 28px;">' . $thumb_html . '</span>';
        if ($edit_link) {
            $html .= '<a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a>';
        } else {
            $html .= '<span>' . esc_html($title) . '</span>';
        }
        if ($translation_text !== '') {
            $html .= ' <span class="ll-dictionary-entry-linked-translation" style="opacity:.78;">(' . esc_html($translation_text) . ')</span>';
        }
        $html .= ' <span style="opacity:.7;">#' . esc_html((string) $word_id) . '</span>';
        $html .= '</li>';
    }
    $html .= '</ul>';

    if ($include_view_all && $all_count > $limit) {
        $all_link = add_query_arg(
            [
                'post_type'           => 'words',
                'dictionary_entry_id' => $entry_id,
            ],
            admin_url('edit.php')
        );
        $remaining = $all_count - $limit;
        $html .= '<p style="margin:6px 0 0;">';
        $html .= '<a href="' . esc_url($all_link) . '">' . sprintf(
            /* translators: %d number of additional linked words. */
            esc_html__('View %d more', 'll-tools-text-domain'),
            (int) $remaining
        ) . '</a>';
        $html .= '</p>';
    }

    return $html;
}

/**
 * Register umbrella dictionary entries that words can link to.
 */
function ll_tools_register_dictionary_entry_post_type() {
    $labels = [
        'name'               => esc_html__('Dictionary Entries', 'll-tools-text-domain'),
        'singular_name'      => esc_html__('Dictionary Entry', 'll-tools-text-domain'),
        'add_new_item'       => esc_html__('Add New Dictionary Entry', 'll-tools-text-domain'),
        'edit_item'          => esc_html__('Edit Dictionary Entry', 'll-tools-text-domain'),
        'new_item'           => esc_html__('New Dictionary Entry', 'll-tools-text-domain'),
        'view_item'          => esc_html__('View Dictionary Entry', 'll-tools-text-domain'),
        'search_items'       => esc_html__('Search Dictionary Entries', 'll-tools-text-domain'),
        'not_found'          => esc_html__('No dictionary entries found', 'll-tools-text-domain'),
        'not_found_in_trash' => esc_html__('No dictionary entries found in Trash', 'll-tools-text-domain'),
        'menu_name'          => esc_html__('Dictionary Entries', 'll-tools-text-domain'),
    ];

    $args = [
        'label'               => esc_html__('Dictionary Entries', 'll-tools-text-domain'),
        'labels'              => $labels,
        'description'         => '',
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => 'edit.php?post_type=words',
        'show_in_nav_menus'   => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'show_in_rest'        => true,
        'rewrite'             => false,
        'query_var'           => 'll_dictionary_entry',
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'supports'            => ['title', 'editor', 'custom-fields'],
    ];

    register_post_type('ll_dictionary_entry', $args);
}
add_action('init', 'll_tools_register_dictionary_entry_post_type', 0);

/**
 * Restrict dictionary entries admin screens to LL Tools users.
 *
 * @param WP_Screen $screen Current admin screen.
 * @return void
 */
function ll_tools_dictionary_entry_restrict_admin_screen($screen) {
    if (!is_admin() || !($screen instanceof WP_Screen)) {
        return;
    }
    if ($screen->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (current_user_can('view_ll_tools')) {
        return;
    }

    wp_die(esc_html__('You do not have permission to access dictionary entries.', 'll-tools-text-domain'), 403);
}
add_action('current_screen', 'll_tools_dictionary_entry_restrict_admin_screen');

/**
 * Validate dictionary entry post type.
 *
 * @param int $entry_id Candidate dictionary entry ID.
 * @return bool
 */
function ll_tools_is_dictionary_entry_id($entry_id) {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return false;
    }

    $post = get_post($entry_id);
    if (!$post || $post->post_type !== 'll_dictionary_entry') {
        return false;
    }

    return true;
}

/**
 * Resolve dictionary entry ID linked to a word post.
 *
 * @param int $word_id Word post ID.
 * @return int
 */
function ll_tools_get_word_dictionary_entry_id($word_id) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return 0;
    }

    $entry_id = (int) get_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, true);
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return 0;
    }

    return $entry_id;
}

/**
 * Return words linked to a dictionary entry.
 *
 * @param int $entry_id Dictionary entry post ID.
 * @param int $limit    Max words to fetch. -1 for all.
 * @return int[] Word IDs.
 */
function ll_tools_get_dictionary_entry_word_ids($entry_id, $limit = -1) {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return [];
    }

    $limit = (int) $limit;
    if ($limit === 0) {
        $limit = -1;
    }

    $word_ids = get_posts([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => $limit,
        'fields'           => 'ids',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'meta_query'       => [[
            'key'     => LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
            'value'   => (string) $entry_id,
            'compare' => '=',
        ]],
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);

    return array_map('intval', (array) $word_ids);
}

/**
 * Count words linked to a dictionary entry.
 *
 * @param int $entry_id Dictionary entry post ID.
 * @return int
 */
function ll_tools_count_dictionary_entry_words($entry_id) {
    $entry_id = (int) $entry_id;
    if (!ll_tools_is_dictionary_entry_id($entry_id)) {
        return 0;
    }

    $query = new WP_Query([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'meta_query'       => [[
            'key'     => LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
            'value'   => (string) $entry_id,
            'compare' => '=',
        ]],
        'suppress_filters' => true,
    ]);

    $count = (int) $query->found_posts;
    wp_reset_postdata();
    return $count;
}

/**
 * Search dictionary entries by title text.
 *
 * @param string $search     Search text.
 * @param int    $limit      Maximum number of entries.
 * @param int    $wordset_id Optional wordset restriction.
 * @return array<int,array{id:int,label:string}>
 */
function ll_tools_search_dictionary_entries($search = '', $limit = 20, $wordset_id = 0): array {
    $search = trim((string) $search);
    $limit = (int) $limit;
    $wordset_id = (int) $wordset_id;
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = min(100, $limit);

    $query_limit = $limit;
    if ($wordset_id > 0) {
        $query_limit = min(200, max($limit * 4, $limit));
    }

    $args = [
        'post_type'        => 'll_dictionary_entry',
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => $query_limit,
        'fields'           => 'ids',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ];
    if ($search !== '') {
        $args['s'] = $search;
    }

    $ids = get_posts($args);
    $entries = [];
    foreach ((array) $ids as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        if ($wordset_id > 0) {
            $entry_wordset_id = ll_tools_get_dictionary_entry_wordset_id($id);
            if ($entry_wordset_id !== $wordset_id) {
                continue;
            }
        }
        $title = trim((string) get_the_title($id));
        if ($title === '') {
            $title = __('(no title)', 'll-tools-text-domain');
        }
        $entries[] = [
            'id'    => $id,
            'label' => $title,
        ];
        if (count($entries) >= $limit) {
            break;
        }
    }

    return $entries;
}

/**
 * Link a word to a dictionary entry, optionally creating a new entry by title.
 *
 * @param int    $word_id    Word post ID.
 * @param int    $entry_id   Existing dictionary entry ID to link.
 * @param string $new_title  New dictionary entry title when no ID is provided.
 * @return array|WP_Error Payload with resolved entry_id and entry_title.
 */
function ll_tools_assign_dictionary_entry_to_word($word_id, $entry_id = 0, $new_title = '') {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return new WP_Error('invalid_word', __('Invalid word ID.', 'll-tools-text-domain'));
    }
    if (get_post_type($word_id) !== 'words') {
        return new WP_Error('invalid_word', __('Dictionary entries can only be linked to words.', 'll-tools-text-domain'));
    }

    $entry_id = (int) $entry_id;
    $new_title = trim((string) $new_title);
    $wordset_id = ll_tools_get_word_primary_wordset_id($word_id);
    $pos_data = ll_tools_get_word_primary_pos_data($word_id);
    $word_pos_slug = sanitize_title((string) ($pos_data['slug'] ?? ''));

    if ($wordset_id <= 0 && ($entry_id > 0 || $new_title !== '')) {
        return new WP_Error(
            'dictionary_entry_missing_wordset',
            __('Assign this word to a word set before linking a dictionary entry.', 'll-tools-text-domain')
        );
    }

    if ($entry_id > 0) {
        if (!ll_tools_is_dictionary_entry_id($entry_id)) {
            return new WP_Error('invalid_dictionary_entry', __('Invalid dictionary entry.', 'll-tools-text-domain'));
        }

        $entry_wordset_id = ll_tools_get_dictionary_entry_wordset_id($entry_id);
        if ($wordset_id > 0 && $entry_wordset_id > 0 && $entry_wordset_id !== $wordset_id) {
            $linked_count = ll_tools_count_dictionary_entry_words($entry_id);
            if ($linked_count > 0) {
                return new WP_Error(
                    'dictionary_entry_wordset_mismatch',
                    __('This dictionary entry belongs to a different word set.', 'll-tools-text-domain')
                );
            }
        }

        if ($wordset_id > 0) {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
        }
        if ($word_pos_slug !== '') {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, $word_pos_slug);
        }

        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
        return [
            'entry_id'    => $entry_id,
            'entry_title' => (string) get_the_title($entry_id),
            'created'     => false,
        ];
    }

    if ($new_title !== '') {
        $normalized_target = ll_tools_dictionary_entry_normalize_lookup_value($new_title);
        if ($normalized_target === '') {
            return new WP_Error('invalid_dictionary_entry_title', __('Dictionary entry title cannot be empty.', 'll-tools-text-domain'));
        }

        $matching_entries = ll_tools_search_dictionary_entries($new_title, 50, $wordset_id);
        foreach ($matching_entries as $entry) {
            $candidate_id = isset($entry['id']) ? (int) $entry['id'] : 0;
            $candidate_title = isset($entry['label']) ? (string) $entry['label'] : '';
            if ($candidate_id <= 0 || $candidate_title === '') {
                continue;
            }
            $normalized_candidate = ll_tools_dictionary_entry_normalize_lookup_value($candidate_title);
            if ($normalized_candidate !== $normalized_target) {
                continue;
            }

            if (!ll_tools_is_dictionary_entry_id($candidate_id)) {
                continue;
            }

            if ($wordset_id > 0) {
                update_post_meta($candidate_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
            }
            if ($word_pos_slug !== '') {
                update_post_meta($candidate_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, $word_pos_slug);
            }

            update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $candidate_id);
            return [
                'entry_id'    => $candidate_id,
                'entry_title' => (string) get_the_title($candidate_id),
                'created'     => false,
            ];
        }

        $new_entry_id = wp_insert_post([
            'post_type'   => 'll_dictionary_entry',
            'post_title'  => $new_title,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($new_entry_id) || (int) $new_entry_id <= 0) {
            return new WP_Error('dictionary_entry_create_failed', __('Unable to create dictionary entry.', 'll-tools-text-domain'));
        }

        $new_entry_id = (int) $new_entry_id;
        if ($wordset_id > 0) {
            update_post_meta($new_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
        }
        if ($word_pos_slug !== '') {
            update_post_meta($new_entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, $word_pos_slug);
        }

        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $new_entry_id);
        return [
            'entry_id'    => $new_entry_id,
            'entry_title' => (string) get_the_title($new_entry_id),
            'created'     => true,
        ];
    }

    delete_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY);
    return [
        'entry_id'    => 0,
        'entry_title' => '',
        'created'     => false,
    ];
}

/**
 * Add dictionary entry selection UI on words edit screen.
 */
function ll_tools_add_word_dictionary_entry_metabox() {
    add_meta_box(
        'll-tools-word-dictionary-entry',
        __('Dictionary Entry', 'll-tools-text-domain'),
        'll_tools_render_word_dictionary_entry_metabox',
        'words',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_words', 'll_tools_add_word_dictionary_entry_metabox');

/**
 * Render words -> dictionary entry link metabox.
 *
 * @param WP_Post $post Word post.
 * @return void
 */
function ll_tools_render_word_dictionary_entry_metabox($post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    wp_nonce_field('ll_tools_word_dictionary_entry_save', 'll_tools_word_dictionary_entry_nonce');

    $entry_id = ll_tools_get_word_dictionary_entry_id((int) $post->ID);
    $entry_title = $entry_id > 0 ? get_the_title($entry_id) : '';
    $entry_edit_link = $entry_id > 0 ? get_edit_post_link($entry_id) : '';
    ?>
    <p class="description">
        <?php esc_html_e('Link this word to an umbrella dictionary entry.', 'll-tools-text-domain'); ?>
    </p>

    <?php if ($entry_id > 0) : ?>
        <p>
            <strong><?php esc_html_e('Current', 'll-tools-text-domain'); ?>:</strong><br>
            <?php if ($entry_edit_link) : ?>
                <a href="<?php echo esc_url($entry_edit_link); ?>">
                    <?php echo esc_html($entry_title); ?>
                </a>
            <?php else : ?>
                <?php echo esc_html($entry_title); ?>
            <?php endif; ?>
            <span style="opacity:.7;">#<?php echo esc_html((string) $entry_id); ?></span>
        </p>
    <?php endif; ?>

    <p>
        <label for="ll_dictionary_entry_id" style="display:block;font-weight:600;">
            <?php esc_html_e('Dictionary entry ID', 'll-tools-text-domain'); ?>
        </label>
        <input
            type="number"
            min="0"
            step="1"
            id="ll_dictionary_entry_id"
            name="ll_dictionary_entry_id"
            value="<?php echo esc_attr((string) $entry_id); ?>"
            class="widefat"
        >
        <span class="description">
            <?php esc_html_e('Enter 0 to unlink.', 'll-tools-text-domain'); ?>
        </span>
    </p>

    <p>
        <label for="ll_dictionary_entry_new_title" style="display:block;font-weight:600;">
            <?php esc_html_e('Create and link new dictionary entry', 'll-tools-text-domain'); ?>
        </label>
        <input
            type="text"
            id="ll_dictionary_entry_new_title"
            name="ll_dictionary_entry_new_title"
            value=""
            class="widefat"
            placeholder="<?php echo esc_attr__('Optional title', 'll-tools-text-domain'); ?>"
        >
        <span class="description">
            <?php esc_html_e('If an ID is set above, the ID takes priority.', 'll-tools-text-domain'); ?>
        </span>
    </p>
    <?php
}

/**
 * Persist words -> dictionary entry link.
 *
 * @param int     $post_id Word post ID.
 * @param WP_Post $post    Word post object.
 * @return void
 */
function ll_tools_save_word_dictionary_entry_metabox($post_id, $post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return;
    }
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!isset($_POST['ll_tools_word_dictionary_entry_nonce'])
        || !wp_verify_nonce($_POST['ll_tools_word_dictionary_entry_nonce'], 'll_tools_word_dictionary_entry_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $entry_raw = isset($_POST['ll_dictionary_entry_id'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_id']))
        : '';
    $entry_id = absint($entry_raw);

    $new_title = isset($_POST['ll_dictionary_entry_new_title'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_new_title']))
        : '';
    $new_title = trim($new_title);

    if ($entry_id > 0 && !ll_tools_is_dictionary_entry_id($entry_id)) {
        // Keep current link unchanged when an invalid ID is submitted.
        return;
    }

    $result = ll_tools_assign_dictionary_entry_to_word($post_id, $entry_id, $new_title);
    if (is_wp_error($result) && $entry_id <= 0 && $new_title === '') {
        delete_post_meta($post_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY);
    }
}
add_action('save_post_words', 'll_tools_save_word_dictionary_entry_metabox', 20, 2);

/**
 * Add translation input to dictionary entry edit screen.
 */
function ll_tools_dictionary_entry_add_translation_metabox() {
    add_meta_box(
        'll-tools-dictionary-entry-translation',
        __('Translation', 'll-tools-text-domain'),
        'll_tools_dictionary_entry_render_translation_metabox',
        'll_dictionary_entry',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_ll_dictionary_entry', 'll_tools_dictionary_entry_add_translation_metabox');

/**
 * Render dictionary entry translation metabox.
 *
 * @param WP_Post $post Dictionary entry post.
 * @return void
 */
function ll_tools_dictionary_entry_render_translation_metabox($post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    wp_nonce_field('ll_tools_dictionary_entry_translation_save', 'll_tools_dictionary_entry_translation_nonce');

    $translation = ll_tools_get_dictionary_entry_translation((int) $post->ID);
    ?>
    <p>
        <label for="ll_dictionary_entry_translation" style="display:block;font-weight:600;">
            <?php esc_html_e('Dictionary entry translation', 'll-tools-text-domain'); ?>
        </label>
        <input
            type="text"
            id="ll_dictionary_entry_translation"
            name="ll_dictionary_entry_translation"
            value="<?php echo esc_attr($translation); ?>"
            class="widefat"
            autocomplete="off"
        >
        <span class="description">
            <?php esc_html_e('Shown in dictionary entry listings and dictionary views.', 'll-tools-text-domain'); ?>
        </span>
    </p>
    <?php
}

/**
 * Save dictionary entry translation meta.
 *
 * @param int     $post_id Dictionary entry post ID.
 * @param WP_Post $post    Dictionary entry post object.
 * @return void
 */
function ll_tools_dictionary_entry_save_translation_metabox($post_id, $post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!isset($_POST['ll_tools_dictionary_entry_translation_nonce'])
        || !wp_verify_nonce($_POST['ll_tools_dictionary_entry_translation_nonce'], 'll_tools_dictionary_entry_translation_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $translation = isset($_POST['ll_dictionary_entry_translation'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_translation']))
        : '';
    $translation = trim($translation);

    if ($translation !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_entry_save_translation_metabox', 20, 2);

/**
 * Add quick-edit fields for dictionary entries.
 *
 * @param string $column_name Column slug.
 * @param string $post_type   Current post type.
 * @param string $taxonomy    Current taxonomy (unused).
 * @return void
 */
function ll_tools_dictionary_entry_quick_edit_fields($column_name, $post_type, $taxonomy = '') {
    if ($post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $eligible_columns = ['translation', 'wordset', 'part_of_speech', 'entry_id', 'linked_words'];
    if (!in_array((string) $column_name, $eligible_columns, true)) {
        return;
    }
    $rendered = true;

    $terms = get_terms([
        'taxonomy'   => 'part_of_speech',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }
    ?>
    <fieldset class="inline-edit-col-right ll-dictionary-entry-quick-edit">
        <div class="inline-edit-col">
            <label>
                <span class="title"><?php esc_html_e('Translation', 'll-tools-text-domain'); ?></span>
                <span class="input-text-wrap">
                    <input type="text" name="ll_dictionary_entry_translation_quick" value="">
                </span>
            </label>
            <label>
                <span class="title"><?php esc_html_e('Part of speech', 'll-tools-text-domain'); ?></span>
                <select name="ll_dictionary_entry_pos_quick">
                    <option value=""><?php esc_html_e('None', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($terms as $term) : ?>
                        <?php
                        $slug = isset($term->slug) ? (string) $term->slug : '';
                        $name = isset($term->name) ? (string) $term->name : '';
                        if ($slug === '') {
                            continue;
                        }
                        ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>
    <?php
}
add_action('quick_edit_custom_box', 'll_tools_dictionary_entry_quick_edit_fields', 10, 3);

/**
 * Persist dictionary entry quick-edit fields.
 *
 * @param int     $post_id Dictionary entry post ID.
 * @param WP_Post $post    Dictionary entry post object.
 * @return void
 */
function ll_tools_dictionary_entry_save_quick_edit($post_id, $post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!isset($_POST['_inline_edit']) || !wp_verify_nonce(wp_unslash((string) $_POST['_inline_edit']), 'inlineeditnonce')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    if (array_key_exists('ll_dictionary_entry_translation_quick', $_POST)) {
        $translation = sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_translation_quick']));
        $translation = trim($translation);
        if ($translation !== '') {
            update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
        } else {
            delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
        }
    }

    if (array_key_exists('ll_dictionary_entry_pos_quick', $_POST)) {
        $pos_slug = sanitize_title(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_pos_quick'])));
        if ($pos_slug === '') {
            delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY);
        } else {
            $term = get_term_by('slug', $pos_slug, 'part_of_speech');
            if ($term && !is_wp_error($term)) {
                update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, (string) $term->slug);
            }
        }
    }
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_entry_save_quick_edit', 30, 2);

/**
 * Load quick-edit prefill script for dictionary entries list table.
 *
 * @param string $hook_suffix Current admin hook suffix.
 * @return void
 */
function ll_tools_dictionary_entry_enqueue_quick_edit_script($hook_suffix) {
    if ($hook_suffix !== 'edit.php') {
        return;
    }
    $post_type = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : 'post';
    if ($post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $script = <<<'JS'
jQuery(function ($) {
    if (typeof inlineEditPost === 'undefined' || typeof inlineEditPost.edit !== 'function') {
        return;
    }
    var wpInlineEdit = inlineEditPost.edit;
    inlineEditPost.edit = function (id) {
        wpInlineEdit.apply(this, arguments);

        var postId = 0;
        if (typeof id === 'object') {
            postId = parseInt(this.getId(id), 10) || 0;
        } else {
            postId = parseInt(id, 10) || 0;
        }
        if (!postId) {
            return;
        }

        var $postRow = $('#post-' + postId);
        var $inlineData = $postRow.find('.ll-dictionary-entry-inline-data').first();
        if (!$inlineData.length) {
            return;
        }

        var translation = ($inlineData.attr('data-translation') || '').toString();
        var pos = ($inlineData.attr('data-pos') || '').toString();
        var $editRow = $('#edit-' + postId);
        if (!$editRow.length) {
            return;
        }

        $editRow.find('input[name="ll_dictionary_entry_translation_quick"]').val(translation);
        $editRow.find('select[name="ll_dictionary_entry_pos_quick"]').val(pos);
    };
});
JS;
    wp_add_inline_script('inline-edit-post', $script);
}
add_action('admin_enqueue_scripts', 'll_tools_dictionary_entry_enqueue_quick_edit_script');

/**
 * Add dictionary entry column on words list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function ll_tools_words_add_dictionary_entry_column($columns) {
    if (!is_array($columns)) {
        return $columns;
    }

    $new_columns = [];
    $inserted = false;
    foreach ($columns as $key => $label) {
        if (!$inserted && ($key === 'wordset' || $key === 'date')) {
            $new_columns['dictionary_entry'] = __('Dictionary Entry', 'll-tools-text-domain');
            $inserted = true;
        }
        $new_columns[$key] = $label;
    }

    if (!$inserted) {
        $new_columns['dictionary_entry'] = __('Dictionary Entry', 'll-tools-text-domain');
    }

    return $new_columns;
}
add_filter('manage_words_posts_columns', 'll_tools_words_add_dictionary_entry_column', 25);

/**
 * Render words dictionary entry column.
 *
 * @param string $column  Column slug.
 * @param int    $post_id Word post ID.
 * @return void
 */
function ll_tools_words_render_dictionary_entry_column($column, $post_id) {
    if ($column !== 'dictionary_entry') {
        return;
    }

    $entry_id = ll_tools_get_word_dictionary_entry_id((int) $post_id);
    if ($entry_id <= 0) {
        echo '—';
        return;
    }

    $title = get_the_title($entry_id);
    if ($title === '') {
        $title = __('(no title)', 'll-tools-text-domain');
    }

    $entry_link = get_edit_post_link($entry_id);
    $filter_link = add_query_arg(
        [
            'post_type'           => 'words',
            'dictionary_entry_id' => (int) $entry_id,
        ],
        admin_url('edit.php')
    );

    if ($entry_link) {
        echo '<a href="' . esc_url($entry_link) . '">' . esc_html($title) . '</a>';
    } else {
        echo esc_html($title);
    }

    echo '<br><a href="' . esc_url($filter_link) . '">' . esc_html__('View linked words', 'll-tools-text-domain') . '</a>';
}
add_action('manage_words_posts_custom_column', 'll_tools_words_render_dictionary_entry_column', 20, 2);

/**
 * Add dictionary entry filter to words list table.
 */
function ll_tools_words_add_dictionary_entry_filter() {
    global $typenow;

    if ($typenow !== 'words') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $selected = isset($_GET['dictionary_entry_id'])
        ? sanitize_text_field(wp_unslash((string) $_GET['dictionary_entry_id']))
        : '';

    $entries = get_posts([
        'post_type'        => 'll_dictionary_entry',
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);

    echo '<select name="dictionary_entry_id">';
    echo '<option value="">' . esc_html__('All Dictionary Entries', 'll-tools-text-domain') . '</option>';
    echo '<option value="__none__"' . selected($selected, '__none__', false) . '>' . esc_html__('No Dictionary Entry', 'll-tools-text-domain') . '</option>';

    foreach ($entries as $entry_id) {
        $entry_id = (int) $entry_id;
        if ($entry_id <= 0) {
            continue;
        }
        $title = trim((string) get_the_title($entry_id));
        if ($title === '') {
            $title = __('(no title)', 'll-tools-text-domain');
        }
        echo '<option value="' . esc_attr((string) $entry_id) . '"' . selected($selected, (string) $entry_id, false) . '>' . esc_html($title) . '</option>';
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'll_tools_words_add_dictionary_entry_filter');

/**
 * Apply dictionary entry filter to words list query.
 *
 * @param WP_Query $query Admin list query.
 * @return void
 */
function ll_tools_words_apply_dictionary_entry_filter($query) {
    if (!($query instanceof WP_Query) || !is_admin() || !$query->is_main_query()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }

    $post_type = (string) $query->get('post_type');
    if ($post_type !== 'words') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $raw_value = isset($_GET['dictionary_entry_id'])
        ? sanitize_text_field(wp_unslash((string) $_GET['dictionary_entry_id']))
        : '';
    if ($raw_value === '') {
        return;
    }

    $meta_query = (array) $query->get('meta_query');
    if ($raw_value === '__none__') {
        $meta_query[] = [
            'key'     => LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
            'compare' => 'NOT EXISTS',
        ];
        $query->set('meta_query', $meta_query);
        return;
    }

    $entry_id = absint($raw_value);
    if ($entry_id <= 0 || !ll_tools_is_dictionary_entry_id($entry_id)) {
        return;
    }

    $meta_query[] = [
        'key'     => LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
        'value'   => (string) $entry_id,
        'compare' => '=',
    ];
    $query->set('meta_query', $meta_query);
}
add_action('pre_get_posts', 'll_tools_words_apply_dictionary_entry_filter', 20);

/**
 * Add helpful columns to dictionary entries list table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function ll_tools_dictionary_entry_columns($columns) {
    $new_columns = [];
    $new_columns['cb'] = $columns['cb'] ?? '';
    $new_columns['title'] = __('Dictionary Entry', 'll-tools-text-domain');
    $new_columns['translation'] = __('Translation', 'll-tools-text-domain');
    $new_columns['wordset'] = __('Word Set', 'll-tools-text-domain');
    $new_columns['part_of_speech'] = __('Part of Speech', 'll-tools-text-domain');
    $new_columns['entry_id'] = __('ID', 'll-tools-text-domain');
    $new_columns['linked_words'] = __('Linked Words', 'll-tools-text-domain');
    $new_columns['date'] = __('Date', 'll-tools-text-domain');
    return $new_columns;
}
add_filter('manage_ll_dictionary_entry_posts_columns', 'll_tools_dictionary_entry_columns');

/**
 * Render dictionary entry custom columns.
 *
 * @param string $column  Column slug.
 * @param int    $post_id Dictionary entry post ID.
 * @return void
 */
function ll_tools_dictionary_entry_column_content($column, $post_id) {
    if ($column === 'entry_id') {
        $translation = ll_tools_get_dictionary_entry_translation((int) $post_id);
        $pos_slug = ll_tools_get_dictionary_entry_primary_pos_slug((int) $post_id);
        echo esc_html((string) ((int) $post_id));
        echo '<span class="hidden ll-dictionary-entry-inline-data" data-translation="' . esc_attr($translation) . '" data-pos="' . esc_attr($pos_slug) . '"></span>';
        return;
    }

    if ($column === 'translation') {
        $translation = ll_tools_get_dictionary_entry_translation((int) $post_id);
        if ($translation === '') {
            echo '—';
            return;
        }
        echo esc_html($translation);
        return;
    }

    if ($column === 'wordset') {
        $wordset_id = ll_tools_get_dictionary_entry_wordset_id((int) $post_id);
        if ($wordset_id <= 0) {
            echo '—';
            return;
        }

        $term = get_term($wordset_id, 'wordset');
        if (!$term || is_wp_error($term)) {
            echo esc_html((string) $wordset_id);
            return;
        }

        $link = add_query_arg(
            [
                'post_type' => 'words',
                'wordset'   => (string) $term->slug,
            ],
            admin_url('edit.php')
        );

        echo '<a href="' . esc_url($link) . '">' . esc_html((string) $term->name) . '</a>';
        return;
    }

    if ($column === 'part_of_speech') {
        $labels = ll_tools_get_dictionary_entry_pos_labels((int) $post_id);
        if (empty($labels)) {
            echo '—';
            return;
        }
        echo esc_html(implode(', ', array_filter(array_map('strval', $labels))));
        return;
    }

    if ($column !== 'linked_words') {
        return;
    }

    echo ll_tools_get_dictionary_entry_linked_words_markup((int) $post_id, 6, true);
}
add_action('manage_ll_dictionary_entry_posts_custom_column', 'll_tools_dictionary_entry_column_content', 10, 2);

/**
 * Read-only metabox on dictionary entries: show linked words.
 */
function ll_tools_dictionary_entry_add_linked_words_metabox() {
    add_meta_box(
        'll-tools-dictionary-entry-linked-words',
        __('Linked Words', 'll-tools-text-domain'),
        'll_tools_dictionary_entry_render_linked_words_metabox',
        'll_dictionary_entry',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes_ll_dictionary_entry', 'll_tools_dictionary_entry_add_linked_words_metabox');

/**
 * Render linked words metabox.
 *
 * @param WP_Post $post Dictionary entry post object.
 * @return void
 */
function ll_tools_dictionary_entry_render_linked_words_metabox($post) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids((int) $post->ID, 1);
    if (empty($word_ids)) {
        echo '<p>' . esc_html__('No words are linked to this dictionary entry yet.', 'll-tools-text-domain') . '</p>';
        return;
    }

    echo ll_tools_get_dictionary_entry_linked_words_markup((int) $post->ID, 25, false);

    $all_linked_url = add_query_arg(
        [
            'post_type'           => 'words',
            'dictionary_entry_id' => (int) $post->ID,
        ],
        admin_url('edit.php')
    );
    echo '<p><a href="' . esc_url($all_linked_url) . '">' . esc_html__('View all linked words', 'll-tools-text-domain') . '</a></p>';
}

/**
 * Clear words->dictionary link if dictionary entry is deleted.
 *
 * @param int $post_id Deleted post ID.
 * @return void
 */
function ll_tools_dictionary_entry_cleanup_links_on_delete($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0 || get_post_type($post_id) !== 'll_dictionary_entry') {
        return;
    }

    $linked_word_ids = ll_tools_get_dictionary_entry_word_ids($post_id, -1);
    if (empty($linked_word_ids)) {
        return;
    }

    foreach ($linked_word_ids as $word_id) {
        delete_post_meta((int) $word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY);
    }
}
add_action('before_delete_post', 'll_tools_dictionary_entry_cleanup_links_on_delete');
