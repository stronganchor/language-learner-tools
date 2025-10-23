<?php

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


/**
 * 1) ADD A META BOX FOR WORD IMAGE METADATA
 *    (So we can see & edit "copyright_info" in the Edit Word Image screen)
 */

// Hook: Add our meta box to the Edit screen for Word Images
add_action('add_meta_boxes_word_images', 'll_add_word_image_meta_box');
function ll_add_word_image_meta_box() {
    add_meta_box(
        'll_word_image_metadata_box',   // Unique ID for the meta box
        'Word Image Metadata',          // Box title
        'll_word_image_metadata_cb',    // Callback to render the box’s HTML
        'word_images',                  // Post type
        'side',                         // Context (side, normal, advanced)
        'default'                       // Priority
    );
}

/**
 * Callback to display the custom fields (e.g., "copyright_info") in the meta box.
 *
 * @param WP_Post $post Current post object.
 */
function ll_word_image_metadata_cb($post) {
    // Security: Add a nonce for form validation later
    wp_nonce_field('ll_word_image_metadata_save', 'll_word_image_metadata_nonce');

    // Retrieve the current value of copyright_info
    $copyright_info = get_post_meta($post->ID, 'copyright_info', true);

    echo '<p><label for="ll_copyright_info"><strong>Copyright Info:</strong></label></p>';
    echo '<textarea id="ll_copyright_info" name="ll_copyright_info" rows="3" style="width:100%;">';
    echo esc_textarea($copyright_info);
    echo '</textarea>';
    echo '<p class="howto">Edit or add any copyright or source info for this image.</p>';
}

/**
 * Hook: Save the field when the post is saved/updated.
 */
add_action('save_post_word_images', 'll_save_word_image_metadata');
function ll_save_word_image_metadata($post_id) {
    // Security check: Was our nonce present and valid?
    if (!isset($_POST['ll_word_image_metadata_nonce']) ||
        !wp_verify_nonce($_POST['ll_word_image_metadata_nonce'], 'll_word_image_metadata_save')) {
        return;
    }

    // Stop if it's an autosave or if the user lacks permissions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Finally, save the posted "copyright_info" field
    if (isset($_POST['ll_copyright_info'])) {
        $clean_value = sanitize_textarea_field($_POST['ll_copyright_info']);
        update_post_meta($post_id, 'copyright_info', $clean_value);
    }
}


/**
 * 2) SHOW METADATA IN THE WORD IMAGES TABLE (edit.php?post_type=word_images)
 */

// Hook: Register our modifications to the Word Images admin page columns
add_action('admin_init', 'll_modify_word_images_admin_page');
function ll_modify_word_images_admin_page() {
    // Filter: Modify the columns shown in the Word Images table
    add_filter('manage_word_images_posts_columns', 'll_modify_word_images_columns');

    // Action: Render the custom column content
    add_action('manage_word_images_posts_custom_column', 'll_render_word_images_columns', 10, 2);

    // Add filter dropdown for categories
    add_action('restrict_manage_posts', 'll_add_word_images_category_filter');

    // Apply the category filter to the query
    add_filter('parse_query', 'll_filter_word_images_by_category');
}

/**
 * Add a category filter dropdown to the Word Images admin page
 */
function ll_add_word_images_category_filter() {
    global $typenow, $wpdb;

    if ($typenow === 'word_images') {
        $selected_category = isset($_GET['word_category_filter']) ? $_GET['word_category_filter'] : '';

        // Get categories with accurate counts for word_images only
        $categories = get_terms(array(
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'hierarchical' => true,
        ));

        echo '<select name="word_category_filter" id="word_category_filter">';
        echo '<option value="">' . __('All Categories', 'll-tools-text-domain') . '</option>';

        foreach ($categories as $category) {
            // Count word_images posts in this category
            $count_query = new WP_Query(array(
                'post_type' => 'word_images',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'word-category',
                        'field' => 'term_id',
                        'terms' => $category->term_id,
                    )
                ),
            ));
            $count = $count_query->found_posts;

            $indent = str_repeat('&nbsp;&nbsp;', ll_get_category_depth($category->term_id));
            printf(
                '<option value="%d" %s>%s%s (%d)</option>',
                $category->term_id,
                selected($selected_category, $category->term_id, false),
                $indent,
                esc_html($category->name),
                $count
            );
        }

        echo '</select>';
    }
}

/**
 * Filter Word Images by selected category
 */
function ll_filter_word_images_by_category($query) {
    global $pagenow, $typenow;

    if ($pagenow === 'edit.php' && $typenow === 'word_images' && is_admin() && $query->is_main_query()) {
        // Only apply filter if a specific category is selected (not empty string or 0)
        if (isset($_GET['word_category_filter']) && $_GET['word_category_filter'] != '' && $_GET['word_category_filter'] != '0') {
            $category_id = intval($_GET['word_category_filter']);

            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'word-category',
                    'field' => 'term_id',
                    'terms' => $category_id,
                )
            ));
        }
    }
}

/**
 * Adjust the columns we show in the Word Images table.
 *
 * @param array $columns Default columns
 * @return array Modified columns
 */
function ll_modify_word_images_columns($columns) {
    // Insert our new columns after the 'title' column
    $new_columns = [];
    foreach ($columns as $col_key => $col_label) {
        $new_columns[$col_key] = $col_label;
        if ('title' === $col_key) {
            $new_columns['word_categories'] = __('Categories', 'll-tools-text-domain');
            $new_columns['copyright_info'] = __('Copyright Info', 'll-tools-text-domain');
            $new_columns['attached_image'] = __('Featured Image', 'll-tools-text-domain');
        }
    }

    return $new_columns;
}

/**
 * Make the category column sortable
 */
add_filter('manage_edit-word_images_sortable_columns', 'll_word_images_sortable_columns');
function ll_word_images_sortable_columns($columns) {
    $columns['word_categories'] = 'word_categories';
    return $columns;
}

/**
 * Handle sorting by category
 */
add_action('pre_get_posts', 'll_word_images_sort_by_category');
function ll_word_images_sort_by_category($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    // Check if we're on the word_images post type list
    if ($query->get('post_type') !== 'word_images') {
        return;
    }

    // Check if sorting by categories (WordPress passes this from the URL)
    $orderby = $query->get('orderby');
    if ($orderby === 'word_categories') {
        // Don't set orderby here, let the clauses filter handle it
        add_filter('posts_clauses', 'll_sort_word_images_by_category_clauses', 10, 2);
    }
}

function ll_sort_word_images_by_category_clauses($clauses, $query) {
    global $wpdb;

    // Only apply if we're sorting by categories
    if (!is_admin() || $query->get('orderby') !== 'word_categories') {
        return $clauses;
    }

    // Join with term tables
    $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
    $clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'word-category'";
    $clauses['join'] .= " LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id";

    // Group by post ID to avoid duplicates
    $clauses['groupby'] = "{$wpdb->posts}.ID";

    // Order by the minimum (alphabetically first) category name
    $order = ($query->get('order') === 'DESC' || strtoupper($query->get('order')) === 'DESC') ? 'DESC' : 'ASC';
    $clauses['orderby'] = "MIN(t.name) " . $order;

    // Remove this filter after it runs once
    remove_filter('posts_clauses', 'll_sort_word_images_by_category_clauses', 10);

    return $clauses;
}

/**
 * Render the content for our custom columns in the Word Images table.
 *
 * @param string $column  Current column slug
 * @param int    $post_id Current post ID
 */
function ll_render_word_images_columns($column, $post_id) {
    switch ($column) {
        case 'word_categories':
            // Show assigned 'word-category' terms for this Word Image
            $terms = get_the_terms($post_id, 'word-category');
            if ($terms && !is_wp_error($terms)) {
                $links = [];
                foreach ($terms as $t) {
                    // Link to filter Word Images by this category
                    $url = add_query_arg(
                        ['post_type' => 'word_images', 'word-category' => $t->slug],
                        admin_url('edit.php')
                    );
                    $links[] = '<a href="' . esc_url($url) . '">' . esc_html($t->name) . '</a>';
                }
                echo implode(', ', $links);
            } else {
                echo '—';
            }
            break;
        case 'copyright_info':
            // Show the "copyright_info" meta field
            $val = get_post_meta($post_id, 'copyright_info', true);
            echo $val ? esc_html($val) : '—';
            break;

        case 'attached_image':
            $thumbnail = get_the_post_thumbnail($post_id, 'full', array('style' => 'width:50px;height:auto;'));
            if ($thumbnail) {
                echo $thumbnail;
            } else {
                echo '-';
            }
            break;
    }
}

/**
 * BULK EDIT CATEGORY FUNCTIONALITY FOR WORD IMAGES
 */

/**
 * Enqueue admin script for bulk edit category handling
 */
function ll_word_images_enqueue_bulk_edit_script($hook) {
    ll_enqueue_bulk_category_edit_script('word_images', 'll-word-images-bulk-edit', 'js/word-images-bulk-edit.js');
}
add_action('admin_enqueue_scripts', 'll_word_images_enqueue_bulk_edit_script');

/**
 * AJAX handler to get common categories for selected word images
 */
function ll_word_images_get_common_categories() {
    ll_get_common_categories_for_post_type('word_images');
}
add_action('wp_ajax_ll_word_images_get_common_categories', 'll_word_images_get_common_categories');

/**
 * Handle bulk edit category removal for word images
 */
function ll_word_images_handle_bulk_edit_categories($post_id) {
    ll_handle_bulk_category_edit($post_id, 'word_images');
}
add_action('edit_post', 'll_word_images_handle_bulk_edit_categories', 999, 1);