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
    $user = wp_get_current_user();
    if ($user && in_array('wordset_manager', (array) $user->roles, true)) {
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

/**
 * Redirect word set managers to a custom front-end page after login.
 * Keep wp-admin accessible for now because some manager tools still live there.
 */
function ll_tools_wordset_manager_login_redirect($redirect_to, $request, $user) {
    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }
    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    $roles = (array) $user->roles;
    if (!in_array('wordset_manager', $roles, true)) {
        return $redirect_to;
    }

    $requested_redirect = function_exists('ll_tools_get_valid_login_redirect_request')
        ? ll_tools_get_valid_login_redirect_request($request)
        : '';
    if ($requested_redirect !== '') {
        return $requested_redirect;
    }

    if (function_exists('ll_tools_get_user_managed_wordset_ids') && function_exists('ll_tools_get_wordset_page_view_url')) {
        $managed_wordset_ids = ll_tools_get_user_managed_wordset_ids((int) $user->ID);
        foreach ($managed_wordset_ids as $wordset_id) {
            $term = get_term((int) $wordset_id, 'wordset');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return ll_tools_get_wordset_page_view_url($term);
            }
        }
    }

    return $redirect_to;
}
add_filter('login_redirect', 'll_tools_wordset_manager_login_redirect', 996, 3);

/**
 * Return all wordsets for admin assignment UI.
 *
 * @return WP_Term[]
 */
function ll_tools_get_wordset_manager_assignment_wordsets(): array {
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
    ]);

    if (is_wp_error($wordsets) || !is_array($wordsets)) {
        return [];
    }

    return array_values(array_filter($wordsets, static function ($term) {
        return ($term instanceof WP_Term) && !is_wp_error($term);
    }));
}

/**
 * Get the primary managed wordset assignment for a user.
 */
function ll_tools_get_primary_managed_wordset_id_for_user(int $user_id): int {
    if ($user_id <= 0) {
        return 0;
    }

    if (function_exists('ll_tools_get_user_managed_wordset_ids')) {
        $managed_ids = ll_tools_get_user_managed_wordset_ids($user_id);
        foreach ((array) $managed_ids as $managed_id) {
            $managed_id = (int) $managed_id;
            if ($managed_id > 0) {
                return $managed_id;
            }
        }
    }

    $legacy = get_user_meta($user_id, 'managed_wordsets', true);
    foreach ((array) $legacy as $managed_id) {
        $managed_id = (int) $managed_id;
        if ($managed_id > 0) {
            return $managed_id;
        }
    }

    return 0;
}

/**
 * Render the Word Set Manager assignment field (shared for add/edit user screens).
 *
 * @param WP_User|null $user
 * @param bool         $is_new_user
 * @return void
 */
function ll_tools_render_wordset_manager_assignment_fields($user = null, bool $is_new_user = false): void {
    if (!current_user_can('promote_users') && !current_user_can('manage_options')) {
        return;
    }

    $selected_wordset_id = 0;
    $show_by_default = false;
    if ($user instanceof WP_User) {
        $selected_wordset_id = ll_tools_get_primary_managed_wordset_id_for_user((int) $user->ID);
        $show_by_default = in_array('wordset_manager', (array) $user->roles, true);
    }

    if (isset($_POST['ll_wordset_manager_assigned_wordset_id'])) {
        $selected_wordset_id = max(0, (int) wp_unslash((string) $_POST['ll_wordset_manager_assigned_wordset_id']));
    }
    if (isset($_POST['role'])) {
        $posted_role = sanitize_key(wp_unslash((string) $_POST['role']));
        if ($posted_role !== '') {
            $show_by_default = ($posted_role === 'wordset_manager');
        }
    }

    $wordsets = ll_tools_get_wordset_manager_assignment_wordsets();
    ?>
    <table
        class="form-table ll-wordset-manager-assignment-config"
        style="<?php echo $show_by_default ? '' : 'display: none;'; ?>"
        data-show-by-default="<?php echo $show_by_default ? '1' : '0'; ?>">
        <tr>
            <th colspan="2">
                <h2><?php echo esc_html__('Word Set Manager Assignment', 'll-tools-text-domain'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Choose the word set this user is allowed to manage. This is required when assigning the Word Set Manager role.', 'll-tools-text-domain'); ?>
                </p>
            </th>
        </tr>
        <tr>
            <th><label for="ll_wordset_manager_assigned_wordset_id"><?php echo esc_html__('Managed Word Set', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select
                    name="ll_wordset_manager_assigned_wordset_id"
                    id="ll_wordset_manager_assigned_wordset_id"
                    class="regular-text"
                    <?php echo $show_by_default ? 'required' : ''; ?>>
                    <option value=""><?php echo esc_html__('-- Select word set --', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($wordsets as $wordset) : ?>
                        <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected($selected_wordset_id, (int) $wordset->term_id); ?>>
                            <?php echo esc_html($wordset->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php echo esc_html__('If this role is selected, one word set must be chosen.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
    </table>
    <script>
    jQuery(function($) {
        var roleSelect = $('#role');
        var section = $('.ll-wordset-manager-assignment-config');
        var select = $('#ll_wordset_manager_assigned_wordset_id');

        function toggleWordsetManagerAssignment() {
            var shouldShow = false;
            if (roleSelect.length) {
                shouldShow = roleSelect.val() === 'wordset_manager';
            } else {
                shouldShow = section.data('show-by-default') === 1 || section.data('show-by-default') === '1';
            }

            section.toggle(shouldShow);
            if (shouldShow) {
                select.attr('required', 'required');
            } else {
                select.removeAttr('required');
            }
        }

        toggleWordsetManagerAssignment();
        roleSelect.on('change', toggleWordsetManagerAssignment);
    });
    </script>
    <?php
}

/**
 * Add Word Set Manager assignment fields to the Add New User screen.
 */
function ll_tools_wordset_manager_new_user_fields($operation = ''): void {
    unset($operation);
    ll_tools_render_wordset_manager_assignment_fields(null, true);
}
add_action('user_new_form', 'll_tools_wordset_manager_new_user_fields');

/**
 * Add Word Set Manager assignment fields to the Edit User screen.
 */
function ll_tools_wordset_manager_edit_user_fields($user): void {
    if (!($user instanceof WP_User)) {
        return;
    }
    ll_tools_render_wordset_manager_assignment_fields($user, false);
}
add_action('edit_user_profile', 'll_tools_wordset_manager_edit_user_fields');

/**
 * Validate that a wordset is selected when assigning the Word Set Manager role.
 */
function ll_tools_validate_wordset_manager_assignment($errors, $update, $user): void {
    unset($update, $user);
    if (!($errors instanceof WP_Error)) {
        return;
    }
    if (!current_user_can('promote_users') && !current_user_can('manage_options')) {
        return;
    }

    $posted_role = isset($_POST['role']) ? sanitize_key(wp_unslash((string) $_POST['role'])) : '';
    if ($posted_role !== 'wordset_manager') {
        return;
    }

    $wordset_id = isset($_POST['ll_wordset_manager_assigned_wordset_id'])
        ? max(0, (int) wp_unslash((string) $_POST['ll_wordset_manager_assigned_wordset_id']))
        : 0;

    if ($wordset_id <= 0) {
        $errors->add(
            'll_wordset_manager_missing_wordset',
            __('Please choose a managed word set when assigning the Word Set Manager role.', 'll-tools-text-domain')
        );
        return;
    }

    $term = get_term($wordset_id, 'wordset');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        $errors->add(
            'll_wordset_manager_invalid_wordset',
            __('The selected managed word set is invalid. Please choose a different word set.', 'll-tools-text-domain')
        );
    }
}
add_action('user_profile_update_errors', 'll_tools_validate_wordset_manager_assignment', 10, 3);

/**
 * Save the selected Word Set Manager assignment and sync term meta ownership.
 */
function ll_tools_save_wordset_manager_assignment($user_id): void {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return;
    }
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }
    if (!current_user_can('promote_users') && !current_user_can('manage_options')) {
        return;
    }
    if (!isset($_POST['ll_wordset_manager_assigned_wordset_id'])) {
        return;
    }

    $user = get_userdata($user_id);
    if (!($user instanceof WP_User)) {
        return;
    }

    $posted_role = isset($_POST['role']) ? sanitize_key(wp_unslash((string) $_POST['role'])) : '';
    $is_wordset_manager = ($posted_role !== '')
        ? ($posted_role === 'wordset_manager')
        : in_array('wordset_manager', (array) $user->roles, true);
    if (!$is_wordset_manager) {
        return;
    }

    $wordset_id = max(0, (int) wp_unslash((string) $_POST['ll_wordset_manager_assigned_wordset_id']));
    if ($wordset_id <= 0) {
        return;
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        return;
    }

    if (function_exists('ll_tools_get_user_managed_wordset_ids')) {
        $existing_managed_ids = ll_tools_get_user_managed_wordset_ids($user_id);
        foreach ((array) $existing_managed_ids as $existing_id) {
            $existing_id = (int) $existing_id;
            if ($existing_id <= 0 || $existing_id === $wordset_id) {
                continue;
            }

            $existing_manager_user_id = (int) get_term_meta($existing_id, 'manager_user_id', true);
            if ($existing_manager_user_id === $user_id) {
                delete_term_meta($existing_id, 'manager_user_id');
            }
        }
    }

    $previous_manager_user_id = (int) get_term_meta($wordset_id, 'manager_user_id', true);
    if ($previous_manager_user_id > 0 && $previous_manager_user_id !== $user_id) {
        $previous_legacy = array_values(array_filter(array_map('intval', (array) get_user_meta($previous_manager_user_id, 'managed_wordsets', true))));
        if (!empty($previous_legacy)) {
            $previous_legacy = array_values(array_diff($previous_legacy, [$wordset_id]));
            if (empty($previous_legacy)) {
                delete_user_meta($previous_manager_user_id, 'managed_wordsets');
            } else {
                update_user_meta($previous_manager_user_id, 'managed_wordsets', array_map('intval', $previous_legacy));
            }
        }
    }

    update_term_meta($wordset_id, 'manager_user_id', $user_id);
    update_user_meta($user_id, 'managed_wordsets', [$wordset_id]);
}
add_action('edit_user_profile_update', 'll_tools_save_wordset_manager_assignment');
add_action('user_register', 'll_tools_save_wordset_manager_assignment');
