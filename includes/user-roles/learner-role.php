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

function ll_tools_get_learner_redirect_wordset_term() {
    $wordset_id = function_exists('ll_tools_get_active_wordset_id')
        ? (int) ll_tools_get_active_wordset_id()
        : 0;
    if ($wordset_id > 0) {
        $term = get_term($wordset_id, 'wordset');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            return $term;
        }
    }

    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'number'     => 1,
        'orderby'    => 'term_id',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms) || !($terms[0] instanceof WP_Term)) {
        return null;
    }

    return $terms[0];
}

/**
 * Resolve the default learner URL.
 */
function ll_tools_get_learner_redirect_url(): string {
    if (!function_exists('ll_tools_get_wordset_page_view_url')) {
        return home_url('/');
    }

    $wordset_term = ll_tools_get_learner_redirect_wordset_term();
    if ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term)) {
        return ll_tools_get_wordset_page_view_url($wordset_term);
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

    return ll_tools_get_learner_redirect_url();
}
add_filter('login_redirect', 'll_tools_learner_login_redirect', 997, 3);
