<?php
/**
 * Site Tools Page Management
 * Handles creation and lookup logic for the default site tools page.
 */

if (!defined('WPINC')) { die; }

function ll_tools_get_site_tools_page_config(): array {
    return [
        'option_key'                 => 'll_default_site_tools_page_id',
        'force_option_key'           => 'll_tools_force_create_site_tools_page',
        'creation_attempt_transient' => 'll_site_tools_page_creation_attempt',
        'created_notice_transient'   => 'll_site_tools_page_created',
        'shortcode_search'           => '[ll_site_tools',
        'post_title'                 => __('Site Tools', 'll-tools-text-domain'),
        'post_content'               => '[ll_site_tools]',
        'error_context'              => 'site tools page',
        'notice_title'               => __('Site Tools Page Created!', 'll-tools-text-domain'),
        /* translators: %s: URL of the created site tools page */
        'notice_message'             => __('A default Site Tools page has been created at %s', 'll-tools-text-domain'),
        'settings_label'             => __('Default Site Tools Page', 'll-tools-text-domain'),
        'none_found_text'            => __('No site tools page found.', 'll-tools-text-domain'),
        'create_label'               => __('Create Site Tools Page', 'll-tools-text-domain'),
        'recreate_label'             => __('Recreate Site Tools Page', 'll-tools-text-domain'),
        'confirm_text'               => __('Create a new site tools page?', 'll-tools-text-domain'),
        'button_id'                  => 'll-create-site-tools-page',
        'ajax_action'                => 'll_create_site_tools_page',
        'ajax_nonce_action'          => 'll_create_site_tools_page',
    ];
}

function ll_tools_find_site_tools_page_id(): int {
    $page_id = (int) (ll_tools_find_shortcode_page_by_fragment('[ll_site_tools', 'ids') ?? 0);
    if ($page_id > 0) {
        update_option('ll_default_site_tools_page_id', $page_id);
        return $page_id;
    }

    return 0;
}

function ll_tools_ensure_site_tools_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (ll_tools_find_site_tools_page_id() > 0) {
        return;
    }

    ll_tools_ensure_default_shortcode_page(ll_tools_get_site_tools_page_config());
}
add_action('admin_init', 'll_tools_ensure_site_tools_page', 23);

function ll_tools_site_tools_page_created_notice(): void {
    ll_tools_render_default_shortcode_page_created_notice(ll_tools_get_site_tools_page_config());
}
add_action('admin_notices', 'll_tools_site_tools_page_created_notice');

function ll_tools_site_tools_page_settings_section(): void {
    ll_tools_render_default_shortcode_page_settings_row(ll_tools_get_site_tools_page_config());
}
add_action('ll_tools_settings_after_translations', 'll_tools_site_tools_page_settings_section', 50);

function ll_ajax_create_site_tools_page(): void {
    ll_tools_handle_default_shortcode_page_creation_ajax(ll_tools_get_site_tools_page_config());
}
add_action('wp_ajax_ll_create_site_tools_page', 'll_ajax_create_site_tools_page');

function ll_tools_get_site_tools_page_url(int $user_id = 0): string {
    $page_id = (int) get_option('ll_default_site_tools_page_id');
    if ($page_id <= 0 || get_post_status($page_id) !== 'publish') {
        $page_id = ll_tools_find_site_tools_page_id();
    }

    if ($page_id <= 0) {
        return '';
    }

    $url = (string) get_permalink($page_id);
    if ($url === '') {
        return '';
    }

    return function_exists('ll_tools_append_preferred_locale_to_url')
        ? (string) ll_tools_append_preferred_locale_to_url($url, $user_id)
        : $url;
}
