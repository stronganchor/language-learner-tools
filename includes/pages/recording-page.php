<?php
/**
 * Recording Page Management
 * Handles creation and redirect logic for the default audio recording page
 */

if (!defined('WPINC')) { die; }

function ll_tools_get_recording_page_config(): array {
    return [
        'option_key'                 => 'll_default_recording_page_id',
        'force_option_key'           => 'll_tools_force_create_recording_page',
        'creation_attempt_transient' => 'll_recording_page_creation_attempt',
        'created_notice_transient'   => 'll_recording_page_created',
        'shortcode_search'           => '[audio_recording_interface',
        'post_title'                 => __('Record Audio', 'll-tools-text-domain'),
        'post_content'               => '[audio_recording_interface]',
        'error_context'              => 'recording page',
        'notice_title'               => __('Audio Recording Page Created!', 'll-tools-text-domain'),
        /* translators: %s: URL of the created recording page */
        'notice_message'             => __('A default recording page has been created at %s', 'll-tools-text-domain'),
        'settings_label'             => __('Default Recording Page', 'll-tools-text-domain'),
        'none_found_text'            => __('No recording page found.', 'll-tools-text-domain'),
        'create_label'               => __('Create Recording Page', 'll-tools-text-domain'),
        'recreate_label'             => __('Recreate Recording Page', 'll-tools-text-domain'),
        'confirm_text'               => __('Create a new recording page?', 'll-tools-text-domain'),
        'button_id'                  => 'll-create-recording-page',
        'ajax_action'                => 'll_create_recording_page',
        'ajax_nonce_action'          => 'll_create_recording_page',
    ];
}

/**
 * Create default audio recording page if it doesn't exist
 * Runs on admin_init and checks if page needs to be created
 */
function ll_tools_ensure_recording_page() {
    ll_tools_ensure_default_shortcode_page(ll_tools_get_recording_page_config());
}
add_action('admin_init', 'll_tools_ensure_recording_page', 20);

/**
 * Show admin notice when recording page is created
 */
function ll_recording_page_created_notice() {
    ll_tools_render_default_shortcode_page_created_notice(ll_tools_get_recording_page_config());
}
add_action('admin_notices', 'll_recording_page_created_notice');

/**
 * Add manual creation button to settings page
 */
function ll_recording_page_settings_section() {
    ll_tools_render_default_shortcode_page_settings_row(ll_tools_get_recording_page_config());
}
add_action('ll_tools_settings_after_translations', 'll_recording_page_settings_section');

/**
 * AJAX handler to manually create recording page
 */
function ll_ajax_create_recording_page() {
    ll_tools_handle_default_shortcode_page_creation_ajax(ll_tools_get_recording_page_config());
}
add_action('wp_ajax_ll_create_recording_page', 'll_ajax_create_recording_page');

/**
 * Helper: Build best recording page URL for a user
 */
function ll_get_recording_redirect_url($user_id = 0) {
    if ($user_id) {
        $custom_redirect = ll_tools_get_user_custom_page_url((int) $user_id, 'll_recording_page_id', 'll_recording_page_url');
        if ($custom_redirect !== '') {
            return function_exists('ll_tools_append_preferred_locale_to_url')
                ? ll_tools_append_preferred_locale_to_url($custom_redirect, (int) $user_id)
                : $custom_redirect;
        }
    }

    // Default: use the stored recording page
    $recording_page_id = get_option('ll_default_recording_page_id');
    if ($recording_page_id && get_post_status($recording_page_id) === 'publish') {
        $url = get_permalink($recording_page_id);
        return function_exists('ll_tools_append_preferred_locale_to_url')
            ? ll_tools_append_preferred_locale_to_url($url, (int) $user_id)
            : $url;
    }

    // Fallback: try to find any page with the shortcode
    $recording_page = ll_find_recording_page();
    if ($recording_page) {
        $url = get_permalink($recording_page);
        return function_exists('ll_tools_append_preferred_locale_to_url')
            ? ll_tools_append_preferred_locale_to_url($url, (int) $user_id)
            : $url;
    }

    // Final fallback to home page
    $url = home_url();
    return function_exists('ll_tools_append_preferred_locale_to_url')
        ? ll_tools_append_preferred_locale_to_url($url, (int) $user_id)
        : $url;
}

/**
 * Redirect audio_recorder users to their designated recording page on login
 */
function ll_audio_recorder_login_redirect($redirect_to, $request, $user) {
    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }
    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    if (!in_array('audio_recorder', (array) $user->roles, true)) {
        return $redirect_to;
    }

    $requested_redirect = function_exists('ll_tools_get_valid_login_redirect_request')
        ? ll_tools_get_valid_login_redirect_request($request)
        : '';
    if ($requested_redirect !== '') {
        return $requested_redirect;
    }

    return ll_get_recording_redirect_url((int) $user->ID);
}
// High priority to win over other plugins altering login redirects
add_filter('login_redirect', 'll_audio_recorder_login_redirect', 999, 3);

/**
 * Find a page that contains the audio_recording_interface shortcode
 */
function ll_find_recording_page() {
    $page_id = (int) (ll_tools_find_shortcode_page_by_fragment('[audio_recording_interface', 'ids') ?? 0);
    if ($page_id > 0) {
        update_option('ll_default_recording_page_id', $page_id);
        return $page_id;
    }

    return null;
}
