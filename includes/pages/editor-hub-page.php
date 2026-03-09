<?php
/**
 * Editor Hub Page Management
 * Handles creation and redirect logic for the default editor hub page.
 */

if (!defined('WPINC')) { die; }

function ll_tools_get_editor_hub_page_config(): array {
    return [
        'option_key'                 => 'll_default_editor_hub_page_id',
        'force_option_key'           => 'll_tools_force_create_editor_hub_page',
        'creation_attempt_transient' => 'll_editor_hub_page_creation_attempt',
        'created_notice_transient'   => 'll_editor_hub_page_created',
        'shortcode_search'           => '[editor_hub',
        'post_title'                 => __('Editor Hub', 'll-tools-text-domain'),
        'post_content'               => '[editor_hub]',
        'error_context'              => 'editor hub page',
        'notice_title'               => __('Editor Hub Page Created!', 'll-tools-text-domain'),
        /* translators: %s: URL of the created editor hub page */
        'notice_message'             => __('A default Editor Hub page has been created at %s', 'll-tools-text-domain'),
        'settings_label'             => __('Default Editor Hub Page', 'll-tools-text-domain'),
        'none_found_text'            => __('No editor hub page found.', 'll-tools-text-domain'),
        'create_label'               => __('Create Editor Hub Page', 'll-tools-text-domain'),
        'recreate_label'             => __('Recreate Editor Hub Page', 'll-tools-text-domain'),
        'confirm_text'               => __('Create a new editor hub page?', 'll-tools-text-domain'),
        'button_id'                  => 'll-create-editor-hub-page',
        'ajax_action'                => 'll_create_editor_hub_page',
        'ajax_nonce_action'          => 'll_create_editor_hub_page',
    ];
}

/**
 * Create default Editor Hub page if it doesn't exist.
 * Runs on admin_init and checks if page needs to be created.
 */
function ll_tools_ensure_editor_hub_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    ll_tools_ensure_default_shortcode_page(ll_tools_get_editor_hub_page_config());
}
add_action('admin_init', 'll_tools_ensure_editor_hub_page', 21);

/**
 * Show admin notice when editor hub page is created.
 */
function ll_editor_hub_page_created_notice() {
    ll_tools_render_default_shortcode_page_created_notice(ll_tools_get_editor_hub_page_config());
}
add_action('admin_notices', 'll_editor_hub_page_created_notice');

/**
 * Add manual creation button to settings page.
 */
function ll_editor_hub_page_settings_section() {
    ll_tools_render_default_shortcode_page_settings_row(ll_tools_get_editor_hub_page_config());
}
add_action('ll_tools_settings_after_translations', 'll_editor_hub_page_settings_section');

/**
 * AJAX handler to manually create editor hub page.
 */
function ll_ajax_create_editor_hub_page() {
    ll_tools_handle_default_shortcode_page_creation_ajax(ll_tools_get_editor_hub_page_config());
}
add_action('wp_ajax_ll_create_editor_hub_page', 'll_ajax_create_editor_hub_page');

/**
 * Helper: Build best editor hub page URL for a user.
 */
function ll_get_editor_hub_redirect_url($user_id = 0) {
    $page_id = (int) get_option('ll_default_editor_hub_page_id');
    if ($page_id > 0 && get_post_status($page_id) === 'publish') {
        return get_permalink($page_id);
    }

    $found_page_id = ll_find_editor_hub_page();
    if ($found_page_id > 0) {
        return get_permalink($found_page_id);
    }

    return home_url('/');
}

/**
 * Redirect ll_tools_editor users to editor hub page on login.
 */
function ll_tools_editor_hub_login_redirect($redirect_to, $request, $user) {
    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }
    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    $roles = (array) $user->roles;
    if (in_array('audio_recorder', $roles, true)) {
        return $redirect_to;
    }

    if (in_array('ll_tools_editor', $roles, true)) {
        $requested_redirect = function_exists('ll_tools_get_valid_login_redirect_request')
            ? ll_tools_get_valid_login_redirect_request($request)
            : '';
        if ($requested_redirect !== '') {
            return $requested_redirect;
        }

        return ll_get_editor_hub_redirect_url((int) $user->ID);
    }

    return $redirect_to;
}
add_filter('login_redirect', 'll_tools_editor_hub_login_redirect', 998, 3);

/**
 * Find a page that contains the editor_hub shortcode.
 */
function ll_find_editor_hub_page() {
    $page_id = (int) (ll_tools_find_shortcode_page_by_fragment('[editor_hub', 'ids') ?? 0);
    if ($page_id > 0) {
        update_option('ll_default_editor_hub_page_id', $page_id);
        return $page_id;
    }

    return 0;
}
