<?php
/**
 * Shared helpers for managing plugin-owned default pages that host a shortcode.
 */

if (!defined('WPINC')) { die; }

/**
 * Find a published page containing a shortcode fragment.
 *
 * @param string $shortcode_fragment Example: '[audio_recording_interface'
 * @param string $fields             WP_Query fields ('ids' or 'all')
 * @return int|null Page ID when found, otherwise null.
 */
function ll_tools_find_shortcode_page_by_fragment($shortcode_fragment, $fields = 'ids') {
    $shortcode_fragment = trim((string) $shortcode_fragment);
    if ($shortcode_fragment === '') {
        return null;
    }

    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        's'              => $shortcode_fragment,
        'fields'         => $fields === 'all' ? 'all' : 'ids',
    ]);

    if (empty($pages)) {
        return null;
    }

    if ($fields === 'all') {
        $page = $pages[0] ?? null;
        return ($page instanceof WP_Post) ? (int) $page->ID : null;
    }

    $page_id = (int) ($pages[0] ?? 0);
    return $page_id > 0 ? $page_id : null;
}

/**
 * Ensure a plugin-owned default page exists for a shortcode.
 *
 * @param array{
 *   option_key:string,
 *   force_option_key?:string,
 *   creation_attempt_transient:string,
 *   created_notice_transient:string,
 *   shortcode_search:string,
 *   post_title:string,
 *   post_content:string,
 *   error_context?:string
 * } $config
 * @return int Page ID if ensured/found, otherwise 0.
 */
function ll_tools_ensure_default_shortcode_page(array $config): int {
    if (!current_user_can('manage_options')) {
        return 0;
    }

    $option_key = isset($config['option_key']) ? (string) $config['option_key'] : '';
    $creation_attempt_transient = isset($config['creation_attempt_transient']) ? (string) $config['creation_attempt_transient'] : '';
    $created_notice_transient = isset($config['created_notice_transient']) ? (string) $config['created_notice_transient'] : '';
    $shortcode_search = isset($config['shortcode_search']) ? (string) $config['shortcode_search'] : '';
    $post_title = isset($config['post_title']) ? (string) $config['post_title'] : '';
    $post_content = isset($config['post_content']) ? (string) $config['post_content'] : '';
    $error_context = isset($config['error_context']) ? (string) $config['error_context'] : 'default shortcode page';

    if ($option_key === '' || $creation_attempt_transient === '' || $created_notice_transient === '' || $shortcode_search === '' || $post_content === '') {
        return 0;
    }

    $force_create = false;
    $force_option_key = isset($config['force_option_key']) ? (string) $config['force_option_key'] : '';
    if ($force_option_key !== '') {
        $force_create = (bool) get_option($force_option_key, false);
        if ($force_create) {
            delete_option($force_option_key);
        }
    }

    $existing_page_id = (int) get_option($option_key);
    if (!$force_create && $existing_page_id > 0 && get_post_status($existing_page_id) === 'publish') {
        return $existing_page_id;
    }

    if ($existing_page_id > 0) {
        delete_option($option_key);
    }

    if (!$force_create) {
        $found_page_id = (int) (ll_tools_find_shortcode_page_by_fragment($shortcode_search, 'ids') ?? 0);
        if ($found_page_id > 0) {
            update_option($option_key, $found_page_id);
            return $found_page_id;
        }
    }

    if (!$force_create && get_transient($creation_attempt_transient)) {
        return 0;
    }

    set_transient($creation_attempt_transient, time(), 5 * MINUTE_IN_SECONDS);

    $page_id = wp_insert_post([
        'post_title'      => $post_title,
        'post_content'    => $post_content,
        'post_status'     => 'publish',
        'post_type'       => 'page',
        'post_author'     => get_current_user_id() ?: 1,
        'comment_status'  => 'closed',
        'ping_status'     => 'closed',
    ]);

    if (!is_wp_error($page_id) && (int) $page_id > 0) {
        $page_id = (int) $page_id;
        update_option($option_key, $page_id);
        set_transient($created_notice_transient, $page_id, 60);
        return $page_id;
    }

    error_log(
        'LL Tools: Failed to create ' . $error_context . '. Error: ' .
        ($page_id instanceof WP_Error ? $page_id->get_error_message() : 'Unknown')
    );

    return 0;
}
