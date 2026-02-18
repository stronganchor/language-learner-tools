<?php
/**
 * LL Tools Editor role + enhancements to existing wordset_manager.
 * Places this file in user-roles/ and ensure it's included by the main plugin bootstrap
 * (e.g., language-learner-tools.php already includes wordset-manager.php; you can require this alongside it).
 */

/**
 * Ensure existing wordset_manager has the gateway capability and enhance it slightly.
 */
function ll_ensure_wordset_manager_has_view_ll_tools_cap() {
    $role = get_role('wordset_manager');
    if ($role) {
        $role->add_cap('view_ll_tools'); // lightweight gate for LL Tools admin pages
        // Ensure taxonomy-related caps if they were missing
        $role->add_cap('assign_wordsets');
        $role->add_cap('edit_wordsets');
        $role->add_cap('manage_wordsets');
    }
}
add_action('init', 'll_ensure_wordset_manager_has_view_ll_tools_cap');

/**
 * Create the LL Tools Editor role with limited but sufficient privileges.
 */
function ll_create_ll_tools_editor_role() {
    $caps = [
        'read' => true,
        'upload_files' => true,
        'edit_posts' => true,
        'edit_published_posts' => true,
        'delete_posts' => true,
        'delete_published_posts' => true,
        'manage_categories' => true, // needed to add/edit word-category terms
        // Wordset taxonomy
        'edit_wordsets' => true,
        'manage_wordsets' => true,
        'assign_wordsets' => true,
        'delete_wordsets' => true,
        // Gate for LL Tools admin pages/settings
        'view_ll_tools' => true,
    ];

    if (null === get_role('ll_tools_editor')) {
        add_role('ll_tools_editor', 'LL Tools Editor', $caps);
    } else {
        $role = get_role('ll_tools_editor');
        foreach ($caps as $cap => $grant) {
            if ($grant && ! $role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }
}
add_action('init', 'll_create_ll_tools_editor_role');

/**
 * Trim the admin menu for both wordset_manager and ll_tools_editor.
 */
function ll_trim_admin_menu_for_ll_tools_roles() {
    $user = wp_get_current_user();
    $allowed_roles = ['wordset_manager', 'll_tools_editor'];
    $has = false;
    foreach ($allowed_roles as $r) {
        if (in_array($r, (array) $user->roles, true)) {
            $has = true;
            break;
        }
    }
    if (! $has) {
        return;
    }

    global $menu;
    $dashboard_slug = function_exists('ll_tools_get_admin_menu_slug')
        ? ll_tools_get_admin_menu_slug()
        : 'll-tools-dashboard-home';

    // Keep only the relevant top-level items
    $allowed = [
        'profile.php',
        $dashboard_slug,
        'upload.php',
    ];

    foreach ($menu as $key => $item) {
        if (! in_array($item[2], $allowed, true)) {
            unset($menu[$key]);
        }
    }
}
add_action('admin_menu', 'll_trim_admin_menu_for_ll_tools_roles', 999);

/**
 * Improved [manage_word_sets] shortcode that also allows view_ll_tools capability.
 * If the existing shortcode lives elsewhere, you can remove the duplicate from wordset-manager.php.
 */
function ll_manage_word_sets_shortcode() {
    if (
        ! current_user_can('manage_options') &&
        ! current_user_can('manage_wordsets') &&
        ! current_user_can('view_ll_tools')
    ) {
        return 'You do not have permission to view this content.';
    }

    $iframe_url = admin_url('edit-tags.php?taxonomy=wordset&post_type=words');
    return '<div class="custom-admin-page"><iframe src="' . esc_url($iframe_url) . '" style="width:100%; height:800px; border:none;"></iframe></div>';
}
remove_shortcode('manage_word_sets');
add_shortcode('manage_word_sets', 'll_manage_word_sets_shortcode');
