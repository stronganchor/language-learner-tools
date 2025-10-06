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
        "name" => esc_html__( "Words", "astra" ),
        "singular_name" => esc_html__( "Word", "astra" ),
    ];

    $args = [
        "label" => esc_html__( "Words", "astra" ),
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
        'Similar Words', // Title of the meta box
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
    echo '<p>Enter the Post ID of a word that looks similar:</p>';
    echo '<input type="text" id="similar_word_id" name="similar_word_id" value="' . esc_attr($similar_word_id) . '" class="widefat" />';
    echo '<p>Find the Post ID in the list of words. Use numerical ID only.</p>';
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
        $word_audio_file               = get_post_meta($post->ID, 'word_audio_file', true);
        $word_english_meaning          = get_post_meta($post->ID, 'word_english_meaning', true);
        $word_example_sentence         = get_post_meta($post->ID, 'word_example_sentence', true);
        $word_example_translation      = get_post_meta($post->ID, 'word_example_sentence_translation', true);

        // Build the category list, including any parent terms.
        $word_categories_content = '';
        $word_categories = get_the_terms($post->ID, 'word-category');
        if (!empty($word_categories) && !is_wp_error($word_categories)) {
            $word_categories_content .= '<div class="word-categories">Word categories: ';
            $category_links = array();

            foreach ($word_categories as $category) {
                // Decode any HTML entities, then escape for safe output.
                $decoded_name = html_entity_decode($category->name, ENT_QUOTES, 'UTF-8');
                $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">'
                                    . esc_html($decoded_name)
                                    . '</a>';

                // Walk up the parent chain to include ancestors.
                while ($category->parent != 0) {
                    $category = get_term($category->parent, 'word-category');
                    if (is_wp_error($category) || ! $category) {
                        break;
                    }
                    $decoded_parent = html_entity_decode($category->name, ENT_QUOTES, 'UTF-8');
                    $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">'
                                        . esc_html($decoded_parent)
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
            $custom_content .= get_the_post_thumbnail(
                $post->ID,
                'full',
                array('class' => 'vocab-featured-image')
            );
        }

        // Show the English meaning as a heading.
        $custom_content .= "<h2>Meaning: " . esc_html($word_english_meaning) . "</h2>";

        // Show example sentence and its translation, if available.
        if ($word_example_sentence && $word_example_translation) {
            $custom_content .= '<p>' . esc_html($word_example_sentence) . '</p>';
            $custom_content .= '<p><em>' . esc_html($word_example_translation) . '</em></p>';
        }

        // Include an audio player if an audio file URL is provided.
        if ($word_audio_file) {
            $custom_content .= '<audio controls src="' . esc_url($word_audio_file) . '"></audio>';
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
    $columns['audio_file'] = __('Audio File', 'll-tools-text-domain');
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
            $thumbnail = get_the_post_thumbnail($post_id, 'full', array('style' => 'width:50px;height:auto;'));
            echo $thumbnail ? $thumbnail : '—';
            break;

        case 'audio_file':
            $audio_file = get_post_meta($post_id, 'word_audio_file', true);
            echo $audio_file ? basename($audio_file) : '—';
            break;

        case 'translation':
            $translation = get_post_meta($post_id, 'word_english_meaning', true);
            echo $translation ? $translation : '—';
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
    $columns['audio_file'] = 'audio_file';
    $columns['translation'] = 'translation';
    $columns['wordset'] = 'wordset';
    return $columns;
}

// Add dropdown filters for categories and featured image
function ll_add_words_filters() {
    global $typenow;
    if ($typenow === 'words') {
        $selected_category = isset($_GET['word_category']) ? $_GET['word_category'] : '';
        $selected_image = isset($_GET['has_image']) ? $_GET['has_image'] : '';

        // Category filter with accurate counts
        echo '<select name="word_category">';
        echo '<option value="">' . __('All Categories', 'll-tools-text-domain') . '</option>';
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

// Apply the selected filters to the query
function ll_apply_words_filters($query) {
    global $pagenow;

    if (!is_admin() || $pagenow !== 'edit.php' || empty($_GET['post_type']) || $_GET['post_type'] !== 'words') {
        return;
    }

    // Build a combined tax_query so category + wordset can both apply
    $tax_query = array();

    // Filter by category (term_id)
    if (!empty($_GET['word_category'])) {
        $tax_query[] = array(
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => (int) $_GET['word_category'],
        );
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

    // Sort by audio file
    if (!empty($_GET['orderby']) && $_GET['orderby'] === 'audio_file') {
        $query->set('meta_key', 'word_audio_file');
        $query->set('orderby', 'meta_value');
    }

    // Sort by translation
    if (!empty($_GET['orderby']) && $_GET['orderby'] === 'translation') {
        $query->set('meta_key', 'word_english_meaning');
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
            // Fallback: check if the word has legacy audio
            $audio_file = get_post_meta($word_post_id, 'word_audio_file', true);
            if ($audio_file) {
                // Create a word_audio post for the legacy audio
                $audio_post_id = wp_insert_post([
                    'post_title' => get_the_title($word_post_id),
                    'post_type' => 'word_audio',
                    'post_status' => 'draft',
                    'post_parent' => $word_post_id,
                ]);

                if (!is_wp_error($audio_post_id)) {
                    update_post_meta($audio_post_id, 'audio_file_path', $audio_file);
                    update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
                    $processed++;
                }
            } else {
                $skipped++;
            }
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
                    '%d word skipped (no audio file).',
                    '%d words skipped (no audio files).',
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
    if ($hook !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'words') {
        return;
    }

    wp_enqueue_script(
        'll-words-bulk-edit',
        plugins_url('js/words-bulk-edit.js', LL_TOOLS_MAIN_FILE),
        ['jquery', 'inline-edit-post'],
        filemtime(LL_TOOLS_BASE_PATH . 'js/words-bulk-edit.js'),
        true
    );

    wp_localize_script('ll-words-bulk-edit', 'llWordsBulkEdit', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_words_bulk_edit'),
    ]);
}
add_action('admin_enqueue_scripts', 'll_words_enqueue_bulk_edit_script');

/**
 * AJAX handler to get common categories for selected posts
 */
function ll_words_get_common_categories() {
    check_ajax_referer('ll_words_bulk_edit', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied');
    }

    $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];

    if (empty($post_ids)) {
        wp_send_json_error('No posts selected');
    }

    // Get categories for each post
    $all_categories = [];
    foreach ($post_ids as $post_id) {
        $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($terms)) {
            $all_categories[$post_id] = $terms;
        }
    }

    if (empty($all_categories)) {
        wp_send_json_success(['common' => []]);
    }

    // Find categories common to ALL selected posts
    $common = array_shift($all_categories);
    foreach ($all_categories as $post_cats) {
        $common = array_intersect($common, $post_cats);
    }

    wp_send_json_success(['common' => array_values($common)]);
}
add_action('wp_ajax_ll_words_get_common_categories', 'll_words_get_common_categories');

/**
 * Handle bulk edit category removal - runs after WordPress processes bulk edit
 */
function ll_words_handle_bulk_edit_categories($post_id) {
    // Only run if this is part of a bulk edit
    if (!isset($_REQUEST['bulk_edit'])) {
        return;
    }

    // Only for words post type
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'words') {
        return;
    }

    // Security check
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check if we have categories to remove
    if (!isset($_REQUEST['ll_bulk_categories_to_remove']) || empty($_REQUEST['ll_bulk_categories_to_remove'])) {
        return;
    }

    $categories_to_remove = array_map('intval', (array)$_REQUEST['ll_bulk_categories_to_remove']);

    if (empty($categories_to_remove)) {
        return;
    }

    // Get current categories AFTER WordPress has processed the bulk edit
    $current_terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);

    if (is_wp_error($current_terms)) {
        return;
    }

    // Remove the specified categories
    $new_terms = array_diff($current_terms, $categories_to_remove);

    // Only update if something changed
    if (count($new_terms) !== count($current_terms)) {
        wp_set_object_terms($post_id, array_values($new_terms), 'word-category', false);

        // Log for debugging
        error_log("LL Tools: Post $post_id - Removed categories: " . implode(',', $categories_to_remove));
        error_log("LL Tools: Post $post_id - New categories: " . implode(',', $new_terms));
    }
}
add_action('edit_post', 'll_words_handle_bulk_edit_categories', 999, 1);

/**
 * Prevent publishing a words post without at least one published word_audio
 */
add_action('save_post_words', 'll_validate_word_audio_before_publish', 10, 3);
function ll_validate_word_audio_before_publish($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Only intervene if trying to publish
    if ($post->post_status !== 'publish') {
        return;
    }

    // Check if there's at least one published word_audio
    $published_audio = get_posts([
        'post_type' => 'word_audio',
        'post_parent' => $post_id,
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);

    if (empty($published_audio)) {
        // Revert to draft status
        remove_action('save_post_words', 'll_validate_word_audio_before_publish', 10);
        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'draft',
        ]);
        add_action('save_post_words', 'll_validate_word_audio_before_publish', 10, 3);

        // Set a transient to show admin notice
        set_transient('ll_word_publish_blocked_' . get_current_user_id(), $post_id, 60);
    }
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
