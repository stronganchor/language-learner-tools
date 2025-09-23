<?php
/**
 * Sets up the "Manage Word Sets" admin page template.
 *
 * @return void
 */
function ll_manage_wordsets_page_template() {
    $page_slug = 'manage-word-sets';
    $existing_page = get_page_by_path($page_slug, OBJECT, 'page');
	
    // Update this version number when you need to update the page content
    $latest_page_version = 11;

    if (!$existing_page) {
        // Page doesn't exist, so create it.
        $page_id = wp_insert_post(
            array(
                'post_title'    => 'Manage Word Sets',
                'post_name'     => $page_slug,
                'post_content'  => ll_manage_wordsets_page_content(), 
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => get_current_user_id(),
                'ping_status'   => 'closed'
            )
        );
        // Set the initial version of the page.
        if ($page_id != 0) {
            update_post_meta($page_id, '_page_version', $latest_page_version);
            flush_rewrite_rules();
        }
    } else {
        // Page exists, check if the version needs updating.
        $existing_page_version = get_post_meta($existing_page->ID, '_page_version', true);

        if (!$existing_page_version || $existing_page_version < $latest_page_version) {
            // Update the content and the version meta.
            wp_update_post(
                array(
                    'ID'           => $existing_page->ID,
                    'post_content' => ll_manage_wordsets_page_content(),
                )
            );
            update_post_meta($existing_page->ID, '_page_version', $latest_page_version);
        }
    }
}
add_action('init', 'll_manage_wordsets_page_template');

// Function to return the content for the Manage Word Sets page
function ll_manage_wordsets_page_content() {
    $iframe_url = admin_url('edit-tags.php?taxonomy=wordset&post_type=words');
    return '<div class="custom-admin-page"><iframe src="' . $iframe_url . '" style="width:100%; height:800px; border:none;"></iframe></div>';
}


