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
}

/**
 * Adjust the columns we show in the Word Images table.
 *
 * @param array $columns Default columns
 * @return array Modified columns
 */
function ll_modify_word_images_columns($columns) {
    // We could unset unwanted ones, keep as you see fit:
    // unset($columns['date']); // Example: if you want to remove the "Date" column

    // Insert our new columns after the 'title' column
    $new_columns = [];
    foreach ($columns as $col_key => $col_label) {
        $new_columns[$col_key] = $col_label;
        if ('title' === $col_key) {
            // Our new column for "Copyright Info"
            $new_columns['copyright_info'] = __('Copyright Info', 'll-tools-text-domain');
            // Example: Add any other columns you want (like categories or attachments)
            $new_columns['attached_image'] = __('Featured Image', 'll-tools-text-domain');
        }
    }

    // Return the modified array
    return $new_columns;
}

/**
 * Render the content for our custom columns in the Word Images table.
 *
 * @param string $column  Current column slug
 * @param int    $post_id Current post ID
 */
function ll_render_word_images_columns($column, $post_id) {
    switch ($column) {
        case 'copyright_info':
            // Show the "copyright_info" meta field
            $val = get_post_meta($post_id, 'copyright_info', true);
            echo $val ? esc_html($val) : '—';
            break;

        case 'attached_image':
            $thumb = get_the_post_thumbnail($post_id, 'thumbnail');
            echo $thumb ?: '—';
            break;
    }
}
