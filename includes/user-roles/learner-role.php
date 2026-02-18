<?php
if (!defined('WPINC')) { die; }

/**
 * Create/update a minimal learner role used for study-only access.
 */
function ll_tools_register_or_refresh_learner_role() {
    $role = get_role('ll_tools_learner');

    if (!$role) {
        add_role(
            'll_tools_learner',
            __('Learner', 'll-tools-text-domain'),
            [
                'read' => true,
            ]
        );
        $role = get_role('ll_tools_learner');
    }

    if (!$role) {
        return;
    }

    if (!$role->has_cap('read')) {
        $role->add_cap('read');
    }

    $restricted_caps = [
        'upload_files',
        'view_ll_tools',
        'edit_posts',
        'edit_published_posts',
        'delete_posts',
        'delete_published_posts',
        'manage_categories',
        'edit_wordsets',
        'manage_wordsets',
        'assign_wordsets',
        'delete_wordsets',
    ];

    foreach ($restricted_caps as $cap) {
        if ($role->has_cap($cap)) {
            $role->remove_cap($cap);
        }
    }
}
add_action('plugins_loaded', 'll_tools_register_or_refresh_learner_role', 1);
add_action('init', 'll_tools_register_or_refresh_learner_role', 1);

/**
 * Find the first published page that contains the user study dashboard shortcode.
 */
function ll_tools_find_study_dashboard_page_id(): int {
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        's'              => '[ll_user_study_dashboard',
    ]);

    if (empty($pages)) {
        return 0;
    }

    return (int) $pages[0];
}

/**
 * Resolve the default study dashboard URL for learner redirects.
 */
function ll_tools_get_study_dashboard_redirect_url(): string {
    $page_id = ll_tools_find_study_dashboard_page_id();
    if ($page_id > 0 && get_post_status($page_id) === 'publish') {
        $url = get_permalink($page_id);
        if (is_string($url) && $url !== '') {
            return $url;
        }
    }

    return home_url('/');
}

/**
 * Keep learners out of wp-admin by default when there is no explicit redirect target.
 */
function ll_tools_learner_login_redirect($redirect_to, $request, $user) {
    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }
    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    if (!in_array('ll_tools_learner', (array) $user->roles, true)) {
        return $redirect_to;
    }

    $requested_redirect = function_exists('ll_tools_get_valid_login_redirect_request')
        ? ll_tools_get_valid_login_redirect_request($request)
        : '';
    if ($requested_redirect !== '') {
        return $requested_redirect;
    }

    return ll_tools_get_study_dashboard_redirect_url();
}
add_filter('login_redirect', 'll_tools_learner_login_redirect', 997, 3);
