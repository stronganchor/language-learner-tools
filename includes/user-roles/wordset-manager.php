<?php

/**
 * Creates the "Word Set Manager" user role with specific capabilities.
 *
 * @return void
 */
function ll_create_wordset_manager_role() {
    add_role(
        'wordset_manager',
        'Word Set Manager',
        array(
            'read' => true,
            'upload_files' => true,
            'edit_posts' => true,
            'edit_published_posts' => true,
            'delete_posts' => true,
            'delete_published_posts' => true,
            'edit_wordsets' => true,
            'manage_wordsets' => true,
        )
    );
}
add_action('init', 'll_create_wordset_manager_role');

/**
 * Customizes the admin menu for users with the "Word Set Manager" role.
 *
 * @return void
 */
function customize_admin_menu_for_wordset_manager() {
    if (current_user_can('wordset_manager')) { 
        global $menu;
        global $submenu;

        $dashboard_slug = function_exists('ll_tools_get_admin_menu_slug')
            ? ll_tools_get_admin_menu_slug()
            : 'll-tools-dashboard-home';
        $allowed_menus = ['profile.php', $dashboard_slug];

        foreach ($menu as $menu_key => $menu_item) {
            if (!in_array($menu_item[2], $allowed_menus)) {
                unset($menu[$menu_key]);
            }
        }

        // Optionally, refine submenus by iterating over $submenu and applying similar logic
    }
}
add_action('admin_menu', 'customize_admin_menu_for_wordset_manager', 999);

/**
 * Hides the admin bar for non-administrator users.
 *
 * @return bool False to hide the admin bar for non-admins.
 */
function hide_admin_bar_for_non_admins() {
    if (!current_user_can('administrator')) {
        return false;
    }
    return true; 
}
add_filter('show_admin_bar', 'hide_admin_bar_for_non_admins');

// Adds [manage_word_sets] shortcode to display the Word Sets page within a post or page
function manage_word_sets_shortcode() {
    if (!current_user_can('manage_options') && !current_user_can('manage_wordsets')) {
        return 'You do not have permission to view this content.';
    }

    $iframe_url = admin_url('edit-tags.php?taxonomy=wordset&post_type=words');
    return '<div class="custom-admin-page"><iframe src="' . $iframe_url . '" style="width:100%; height:800px; border:none;"></iframe></div>';
}
add_shortcode('manage_word_sets', 'manage_word_sets_shortcode');
