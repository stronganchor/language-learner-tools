<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_teacher_manage_classes_capability')) {
    function ll_tools_get_teacher_manage_classes_capability(): string {
        return 'll_tools_manage_classes';
    }
}

if (!function_exists('ll_tools_get_teacher_role_slug')) {
    function ll_tools_get_teacher_role_slug(): string {
        return 'll_tools_teacher';
    }
}

if (!function_exists('ll_tools_get_teacher_view_progress_capability')) {
    function ll_tools_get_teacher_view_progress_capability(): string {
        return 'll_tools_view_class_progress';
    }
}

if (!function_exists('ll_tools_register_or_refresh_teacher_role')) {
    function ll_tools_register_or_refresh_teacher_role(): void {
        $caps = [
            'read' => true,
            'view_ll_tools' => true,
            ll_tools_get_teacher_manage_classes_capability() => true,
            ll_tools_get_teacher_view_progress_capability() => true,
        ];

        $teacher_role_slug = ll_tools_get_teacher_role_slug();
        $role = get_role($teacher_role_slug);
        if (!$role) {
            add_role(
                $teacher_role_slug,
                __('Teacher', 'll-tools-text-domain'),
                $caps
            );
            $role = get_role($teacher_role_slug);
        }

        if (!$role) {
            return;
        }

        foreach ($caps as $cap => $grant) {
            if ($grant && !$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }
}
add_action('plugins_loaded', 'll_tools_register_or_refresh_teacher_role', 1);
add_action('init', 'll_tools_register_or_refresh_teacher_role', 1);

if (!function_exists('ll_tools_user_has_teacher_role')) {
    function ll_tools_user_has_teacher_role($user): bool {
        if (is_numeric($user)) {
            $user = get_userdata((int) $user);
        }

        if (!($user instanceof WP_User)) {
            return false;
        }

        return in_array(ll_tools_get_teacher_role_slug(), (array) $user->roles, true);
    }
}

if (!function_exists('ll_tools_assign_teacher_role')) {
    function ll_tools_assign_teacher_role(int $user_id) {
        $user = get_userdata($user_id);
        if (!($user instanceof WP_User)) {
            return new WP_Error('missing_user', __('Select a valid teacher account.', 'll-tools-text-domain'));
        }

        ll_tools_register_or_refresh_teacher_role();

        if (!ll_tools_user_has_teacher_role($user)) {
            $user->add_role(ll_tools_get_teacher_role_slug());
            clean_user_cache($user_id);
            $user = get_userdata($user_id);
        }

        if (!($user instanceof WP_User) || !ll_tools_user_has_teacher_role($user)) {
            return new WP_Error('teacher_role_not_assigned', __('The teacher role could not be assigned to that user.', 'll-tools-text-domain'));
        }

        return $user;
    }
}

if (!function_exists('ll_tools_user_can_manage_classes')) {
    function ll_tools_user_can_manage_classes($user_id = 0): bool {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0) {
            return false;
        }

        return user_can($uid, 'manage_options')
            || user_can($uid, ll_tools_get_teacher_manage_classes_capability());
    }
}

if (!function_exists('ll_tools_user_can_view_class_progress')) {
    function ll_tools_user_can_view_class_progress($user_id = 0): bool {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0) {
            return false;
        }

        return user_can($uid, 'manage_options')
            || user_can($uid, ll_tools_get_teacher_view_progress_capability())
            || user_can($uid, ll_tools_get_teacher_manage_classes_capability());
    }
}

if (!function_exists('ll_tools_grant_teacher_caps_to_administrators')) {
    function ll_tools_grant_teacher_caps_to_administrators($allcaps, $caps, $args, $user) {
        if (!$user instanceof WP_User || !is_array($allcaps) || !is_array($caps)) {
            return $allcaps;
        }

        $requested_caps = [
            ll_tools_get_teacher_manage_classes_capability(),
            ll_tools_get_teacher_view_progress_capability(),
        ];

        $requested = array_intersect($requested_caps, $caps);
        if (empty($requested)) {
            return $allcaps;
        }

        $has_admin_cap = !empty($allcaps['manage_options'])
            || !empty($allcaps['manage_network'])
            || !empty($allcaps['manage_network_options'])
            || !empty($allcaps['manage_network_plugins']);

        if (!$has_admin_cap) {
            return $allcaps;
        }

        foreach ($requested as $cap) {
            $allcaps[$cap] = true;
        }

        return $allcaps;
    }
}
add_filter('user_has_cap', 'll_tools_grant_teacher_caps_to_administrators', 10, 4);
