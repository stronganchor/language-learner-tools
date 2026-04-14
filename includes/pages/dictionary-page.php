<?php
if (!defined('WPINC')) { die; }

function ll_tools_get_dictionary_page_config(): array {
    return [
        'option_key' => 'll_default_dictionary_page_id',
        'force_option_key' => 'll_tools_force_create_dictionary_page',
        'creation_attempt_transient' => 'll_dictionary_page_creation_attempt',
        'created_notice_transient' => 'll_dictionary_page_created',
        'shortcode_search' => '[ll_dictionary',
        'post_title' => __('Dictionary', 'll-tools-text-domain'),
        'post_content' => '[ll_dictionary]',
        'error_context' => 'dictionary page',
        'notice_title' => __('Dictionary Page Created!', 'll-tools-text-domain'),
        /* translators: %s: URL of the created dictionary page */
        'notice_message' => __('A default dictionary page has been created at %s', 'll-tools-text-domain'),
        'settings_label' => __('Default Dictionary Page', 'll-tools-text-domain'),
        'none_found_text' => __('No dictionary page found.', 'll-tools-text-domain'),
        'create_label' => __('Create Dictionary Page', 'll-tools-text-domain'),
        'recreate_label' => __('Recreate Dictionary Page', 'll-tools-text-domain'),
        'confirm_text' => __('Create a new dictionary page?', 'll-tools-text-domain'),
        'button_id' => 'll-create-dictionary-page',
        'ajax_action' => 'll_create_dictionary_page',
        'ajax_nonce_action' => 'll_create_dictionary_page',
    ];
}

function ll_tools_find_dictionary_page_id(): int {
    $fragments = [
        '[ll_dictionary',
        '[dictionary_search',
        '[dictionary_browser',
    ];

    foreach ($fragments as $fragment) {
        $page_id = (int) (ll_tools_find_shortcode_page_by_fragment($fragment, 'ids') ?? 0);
        if ($page_id > 0) {
            update_option('ll_default_dictionary_page_id', $page_id);
            return $page_id;
        }
    }

    return 0;
}

function ll_tools_ensure_dictionary_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (ll_tools_find_dictionary_page_id() > 0) {
        return;
    }

    ll_tools_ensure_default_shortcode_page(ll_tools_get_dictionary_page_config());
}
add_action('admin_init', 'll_tools_ensure_dictionary_page', 22);

function ll_tools_dictionary_page_created_notice(): void {
    ll_tools_render_default_shortcode_page_created_notice(ll_tools_get_dictionary_page_config());
}
add_action('admin_notices', 'll_tools_dictionary_page_created_notice');

function ll_tools_dictionary_page_settings_section(): void {
    ll_tools_render_default_shortcode_page_settings_row(ll_tools_get_dictionary_page_config());
}
add_action('ll_tools_settings_after_translations', 'll_tools_dictionary_page_settings_section');

function ll_ajax_create_dictionary_page(): void {
    ll_tools_handle_default_shortcode_page_creation_ajax(ll_tools_get_dictionary_page_config());
}
add_action('wp_ajax_ll_create_dictionary_page', 'll_ajax_create_dictionary_page');

function ll_tools_get_dictionary_page_url(): string {
    $page_id = (int) get_option('ll_default_dictionary_page_id');
    if ($page_id > 0 && get_post_status($page_id) === 'publish') {
        return (string) get_permalink($page_id);
    }

    $fallback_id = ll_tools_find_dictionary_page_id();
    if ($fallback_id > 0) {
        update_option('ll_default_dictionary_page_id', $fallback_id);
        return (string) get_permalink($fallback_id);
    }

    return home_url('/');
}
