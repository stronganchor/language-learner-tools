<?php

// Create the "Word Set Manager" user role
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

// Only allow access to certain admin menus for the "Word Set Manager" role
function customize_admin_menu_for_wordset_manager() {
    if (current_user_can('wordset_manager')) { // Replace with the correct capability if different
        global $menu;
        global $submenu;

        $allowed_menus = ['profile.php', 'edit.php?post_type=words', 'edit.php?post_type=word_images'];

        foreach ($menu as $menu_key => $menu_item) {
            if (!in_array($menu_item[2], $allowed_menus)) {
                unset($menu[$menu_key]);
            }
        }

        // Optionally, refine submenus by iterating over $submenu and applying similar logic
    }
}
add_action('admin_menu', 'customize_admin_menu_for_wordset_manager', 999);

function hide_admin_bar_for_non_admins() {
    if (!current_user_can('administrator')) {
        return false;
    }
    return true; 
}
add_filter('show_admin_bar', 'hide_admin_bar_for_non_admins');

function customize_admin_toolbar_for_wordset_manager($wp_admin_bar) {
    if (current_user_can('wordset_manager')) { // Use the appropriate capability
        // IDs of the toolbar items you want to keep, adjust as needed
        $allowed_nodes = ['new-words', 'menu-toggle', 'site-name', 'view-site', 'new-word_images', 'new-content'];

        // Remove unwanted nodes
        foreach ($wp_admin_bar->get_nodes() as $node) {
            if (!in_array($node->id, $allowed_nodes)) {
                $wp_admin_bar->remove_node($node->id);
            }
        }
    }
}
add_action('admin_bar_menu', 'customize_admin_toolbar_for_wordset_manager', 999);

function hide_admin_bar_on_admin_pages() {
    if (!current_user_can('administrator')) {
        // add display:none to the admin bar
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('wpadminbar').hide();
            });
        </script>
        <?php
    }
}
