<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_teacher_manage_classes_capability')) {
    function ll_tools_get_teacher_manage_classes_capability(): string {
        return 'll_tools_manage_classes';
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

        $role = get_role('ll_tools_teacher');
        if (!$role) {
            add_role(
                'll_tools_teacher',
                __('Teacher', 'll-tools-text-domain'),
                $caps
            );
            $role = get_role('ll_tools_teacher');
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
