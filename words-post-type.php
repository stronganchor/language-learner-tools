<?php
/** 
 * Register the "words" custom post type and the "word-category" custom taxonomy.
 * 
 * This file contains the code to define and register the "words" custom post type, which is used to manage vocabulary words.
 * It also registers the "word-category" custom taxonomy, which is used to categorize the vocabulary words.
 */


// Register the "words" custom post type
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

add_action( 'init', 'll_tools_register_words_post_type' );

// Register the "word_images" custom post type
function ll_tools_register_word_images_post_type() {
    $labels = [
        "name" => esc_html__("Word Images", "astra"),
        "singular_name" => esc_html__("Word Image", "astra"),
    ];

    $args = [
        "label" => esc_html__("Word Images", "astra"),
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
        "rewrite" => ["slug" => "word-images", "with_front" => true],
        "query_var" => true,
        "supports" => ["title", "thumbnail"],
        "show_in_graphql" => false,
    ];

    register_post_type("word_images", $args);
}
add_action('init', 'll_tools_register_word_images_post_type');

// Update the "word-category" taxonomy registration
function ll_tools_register_word_category_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Categories", "astra"),
        "singular_name" => esc_html__("Word Category", "astra"),
    ];

    $args = [
        "label" => esc_html__("Word Categories", "astra"),
        "labels" => $labels,
        "public" => true,
        "publicly_queryable" => true,
        "hierarchical" => true,
        "show_ui" => true,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "query_var" => true,
        "rewrite" => ['slug' => 'word-category', 'with_front' => true,],
        "show_admin_column" => false,
        "show_in_rest" => true,
        "show_tagcloud" => false,
        "rest_base" => "word-category",
        "rest_controller_class" => "WP_REST_Terms_Controller",
        "rest_namespace" => "wp/v2",
        "show_in_quick_edit" => false,
        "sort" => false,
        "show_in_graphql" => false,
    ];
    register_taxonomy("word-category", ["words", "word_images"], $args);
}
add_action('init', 'll_tools_register_word_category_taxonomy');

/**
 *  Words metadata functions
 */

// Hook to add the meta boxes
add_action('add_meta_boxes', 'll_tools_add_similar_words_metabox');

// Function to add the meta box
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

// Display the content of custom fields on the "words" posts.
function ll_tools_display_vocab_content($content) {
    // Check if we're inside the main loop in a single 'words' post
    if (is_singular('words') && in_the_loop() && is_main_query()) {
        global $post;

        // Retrieve custom field values
        $word_audio_file = get_post_meta($post->ID, 'word_audio_file', true);
        $word_english_meaning = get_post_meta($post->ID, 'word_english_meaning', true);
        $word_example_sentence = get_post_meta($post->ID, 'word_example_sentence', true);
        $word_example_translation = get_post_meta($post->ID, 'word_example_sentence_translation', true);

        // Fetch and format the word categories, including parent categories
        $word_categories_content = '';
        $word_categories = get_the_terms($post->ID, 'word-category');
        if (!empty($word_categories)) {
            $word_categories_content .= '<div class="word-categories">Word categories: ';
            $category_links = array();
            foreach ($word_categories as $category) {
                // Add the current category
                $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">' . esc_html($category->name) . '</a>';

                // Check and add parent category if it exists
                while ($category->parent != 0) {
                    $category = get_term($category->parent, 'word-category');
                    $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">' . esc_html($category->name) . '</a>';
                }
            }

            // Remove duplicate category links and implode
            $category_links = array_unique($category_links);
            $word_categories_content .= implode(', ', $category_links);
            $word_categories_content .= '</div>';
        }

        // Build the custom output
        $custom_content = "<div class='vocab-item'>";

        // Add word categories at the top
        $custom_content .= $word_categories_content;

        // Add featured image with a custom class
        if (has_post_thumbnail($post->ID)) {
            $custom_content .= get_the_post_thumbnail($post->ID, 'full', array('class' => 'vocab-featured-image'));
        }

        $custom_content .= "<h2>Meaning: $word_english_meaning</h2>";

        if ($word_example_sentence && $word_example_translation) {
            $custom_content .= "<p>$word_example_sentence</p>";
            $custom_content .= "<p><em>$word_example_translation</em></p>";
        }
        if ($word_audio_file) {
            $custom_content .= "<audio controls src='".esc_url(home_url($word_audio_file))."'></audio>";
        }

        $custom_content .= "</div>";

        // Append the custom content to the original content
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
    $columns['word_categories'] = __('Categories', 'll-tools-text-domain');
    $columns['featured_image'] = __('Featured Image', 'll-tools-text-domain');
    $columns['audio_file'] = __('Audio File', 'll-tools-text-domain');
    $columns['translation'] = __('Translation', 'll-tools-text-domain');
    $columns['date'] = __('Date', 'll-tools-text-domain');
    return $columns;
}

// Render the content for custom columns
function ll_render_words_columns($column, $post_id) {
    switch ($column) {
        case 'word_categories':
            $categories = get_the_terms($post_id, 'word-category');
            if ($categories) {
                $category_names = array();
                foreach ($categories as $category) {
                    $category_names[] = $category->name;
                }
                echo implode(', ', $category_names);
            } else {
                echo '-';
            }
            break;
        case 'featured_image':
            $thumbnail = get_the_post_thumbnail($post_id, 'full', array('style' => 'width:50px;height:50px;'));
            if ($thumbnail) {
                echo $thumbnail;
            } else {
                echo '-';
            }
            break;
        case 'audio_file':
            $audio_file = get_post_meta($post_id, 'word_audio_file', true);
            if ($audio_file) {
                echo basename($audio_file);
            } else {
                echo '-';
            }
            break;
        case 'translation':
            $translation = get_post_meta($post_id, 'word_english_meaning', true);
            if ($translation) {
                echo $translation;
            } else {
                echo '-';
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
    return $columns;
}

// Add dropdown filters for categories and featured image
function ll_add_words_filters() {
    global $typenow;
    if ($typenow === 'words') {
        $selected_category = isset($_GET['word_category']) ? $_GET['word_category'] : '';
        $selected_image = isset($_GET['has_image']) ? $_GET['has_image'] : '';

        // Category filter
        wp_dropdown_categories(array(
            'show_option_all' => __('All Categories', 'll-tools-text-domain'),
            'taxonomy' => 'word-category',
            'name' => 'word_category',
            'selected' => $selected_category,
            'hierarchical' => true,
            'depth' => 3,
            'show_count' => true,
            'hide_empty' => false,
        ));

        // Featured image filter
        echo '<select name="has_image">';
        echo '<option value="">' . __('Has Featured Image', 'll-tools-text-domain') . '</option>';
        echo '<option value="yes"' . selected($selected_image, 'yes', false) . '>' . __('Yes', 'll-tools-text-domain') . '</option>';
        echo '<option value="no"' . selected($selected_image, 'no', false) . '>' . __('No', 'll-tools-text-domain') . '</option>';
        echo '</select>';
    }
}

// Apply the selected filters to the query
function ll_apply_words_filters($query) {
    global $pagenow;
    if (is_admin() && $pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'words') {
        // Filter by category
        if (isset($_GET['word_category']) && !empty($_GET['word_category'])) {
            $query->query_vars['tax_query'] = array(
                array(
                    'taxonomy' => 'word-category',
                    'field' => 'slug',
                    'terms' => $_GET['word_category'],
                ),
            );
        }

        // Filter by featured image
        if (isset($_GET['has_image']) && !empty($_GET['has_image'])) {
            $image_query = ($_GET['has_image'] === 'yes') ? 'EXISTS' : 'NOT EXISTS';
            $query->query_vars['meta_query'] = array(
                array(
                    'key' => '_thumbnail_id',
                    'compare' => $image_query,
                ),
            );
        }

        // Sort by audio file
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'audio_file') {
            $query->query_vars['meta_key'] = 'word_audio_file';
            $query->query_vars['orderby'] = 'meta_value';
        }

        // Sort by translation
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'translation') {
            $query->query_vars['meta_key'] = 'word_english_meaning';
            $query->query_vars['orderby'] = 'meta_value';
        }
    }
}

?>
