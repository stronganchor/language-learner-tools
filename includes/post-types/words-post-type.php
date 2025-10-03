<?php
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

    foreach ($post_ids as $post_id) {
        // Only process if the word has an audio file
        $audio_file = get_post_meta($post_id, 'word_audio_file', true);

        if ($audio_file) {
            // Mark for reprocessing
            update_post_meta($post_id, '_ll_needs_audio_processing', '1');
            $processed++;
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