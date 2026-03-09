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
 * Return the permalink for a published page, otherwise an empty string.
 */
function ll_tools_get_published_page_permalink(int $page_id): string {
    if ($page_id <= 0) {
        return '';
    }

    $page = get_post($page_id);
    if (!($page instanceof WP_Post) || $page->post_type !== 'page' || get_post_status($page_id) !== 'publish') {
        return '';
    }

    $permalink = get_permalink($page_id);
    return is_string($permalink) ? $permalink : '';
}

/**
 * Resolve a same-site page permalink into a published page ID.
 */
function ll_tools_resolve_internal_page_id_from_url($url): int {
    $url = is_string($url) ? trim($url) : '';
    if ($url === '') {
        return 0;
    }

    $validated = (string) wp_validate_redirect($url, '');
    if ($validated === '') {
        return 0;
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $target_host = strtolower((string) wp_parse_url($validated, PHP_URL_HOST));
    if ($home_host !== '' && $target_host !== '' && $home_host !== $target_host) {
        return 0;
    }

    $page_id = url_to_postid($validated);
    if ($page_id <= 0) {
        $without_locale = (string) remove_query_arg(['ll_locale', 'll_locale_nonce'], $validated);
        if ($without_locale !== $validated) {
            $page_id = url_to_postid($without_locale);
        }
    }

    return ll_tools_get_published_page_permalink((int) $page_id) !== '' ? (int) $page_id : 0;
}

/**
 * Resolve a per-user custom page redirect, migrating supported legacy URL meta.
 */
function ll_tools_get_user_custom_page_url(int $user_id, string $page_id_meta_key, string $legacy_url_meta_key = ''): string {
    if ($user_id <= 0 || $page_id_meta_key === '') {
        return '';
    }

    $page_id = (int) get_user_meta($user_id, $page_id_meta_key, true);
    $page_url = ll_tools_get_published_page_permalink($page_id);
    if ($page_url !== '') {
        return $page_url;
    }
    if ($page_id > 0) {
        delete_user_meta($user_id, $page_id_meta_key);
    }

    if ($legacy_url_meta_key === '') {
        return '';
    }

    $legacy_url = (string) get_user_meta($user_id, $legacy_url_meta_key, true);
    if ($legacy_url === '') {
        return '';
    }

    $validated = (string) wp_validate_redirect($legacy_url, '');
    if ($validated === '') {
        delete_user_meta($user_id, $legacy_url_meta_key);
        return '';
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $target_host = strtolower((string) wp_parse_url($validated, PHP_URL_HOST));
    if ($home_host !== '' && $target_host !== '' && $home_host !== $target_host) {
        delete_user_meta($user_id, $legacy_url_meta_key);
        return '';
    }

    $resolved_page_id = ll_tools_resolve_internal_page_id_from_url($legacy_url);
    if ($resolved_page_id <= 0) {
        return '';
    }

    update_user_meta($user_id, $page_id_meta_key, $resolved_page_id);
    delete_user_meta($user_id, $legacy_url_meta_key);

    return ll_tools_get_published_page_permalink($resolved_page_id);
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

/**
 * Render the admin notice for a newly created shortcode page.
 */
function ll_tools_render_default_shortcode_page_created_notice(array $config): void {
    $created_notice_transient = isset($config['created_notice_transient']) ? (string) $config['created_notice_transient'] : '';
    if ($created_notice_transient === '') {
        return;
    }

    $page_id = (int) get_transient($created_notice_transient);
    if ($page_id <= 0) {
        return;
    }

    delete_transient($created_notice_transient);

    $edit_link = get_edit_post_link($page_id);
    $view_link = get_permalink($page_id);
    $notice_title = isset($config['notice_title']) ? (string) $config['notice_title'] : '';
    $notice_message = isset($config['notice_message']) ? (string) $config['notice_message'] : '';
    ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <?php if ($notice_title !== '') : ?>
                <strong><?php echo esc_html($notice_title); ?></strong><br>
            <?php endif; ?>
            <?php
            if ($notice_message !== '') {
                printf(
                    /* translators: %s: linked page title */
                    esc_html($notice_message),
                    '<a href="' . esc_url($view_link) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($page_id)) . '</a>'
                );
            }
            ?>
            |
            <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Edit Page', 'll-tools-text-domain'); ?></a>
        </p>
    </div>
    <?php
}

/**
 * Render the settings-row UI for creating/recreating a shortcode page.
 */
function ll_tools_render_default_shortcode_page_settings_row(array $config): void {
    $option_key = isset($config['option_key']) ? (string) $config['option_key'] : '';
    $page_label = isset($config['settings_label']) ? (string) $config['settings_label'] : '';
    $empty_message = isset($config['none_found_text']) ? (string) $config['none_found_text'] : '';
    $create_label = isset($config['create_label']) ? (string) $config['create_label'] : '';
    $recreate_label = isset($config['recreate_label']) ? (string) $config['recreate_label'] : '';
    $confirm_message = isset($config['confirm_text']) ? (string) $config['confirm_text'] : '';
    $button_id = isset($config['button_id']) ? (string) $config['button_id'] : '';
    $ajax_action = isset($config['ajax_action']) ? (string) $config['ajax_action'] : '';
    $ajax_nonce_action = isset($config['ajax_nonce_action']) ? (string) $config['ajax_nonce_action'] : '';

    if ($option_key === '' || $page_label === '' || $button_id === '' || $ajax_action === '' || $ajax_nonce_action === '') {
        return;
    }

    $page_id = (int) get_option($option_key);
    $page_exists = ($page_id > 0 && get_post_status($page_id) === 'publish');
    ?>
    <tr valign="top">
        <th scope="row"><?php echo esc_html($page_label); ?></th>
        <td>
            <?php if ($page_exists): ?>
                <p>
                    <strong><?php esc_html_e('Current:', 'll-tools-text-domain'); ?></strong>
                    <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html(get_the_title($page_id)); ?>
                    </a>
                    |
                    <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">
                        <?php esc_html_e('Edit', 'll-tools-text-domain'); ?>
                    </a>
                </p>
            <?php elseif ($empty_message !== ''): ?>
                <p class="description"><?php echo esc_html($empty_message); ?></p>
            <?php endif; ?>

            <p>
                <button type="button" class="button" id="<?php echo esc_attr($button_id); ?>">
                    <?php echo esc_html($page_exists ? $recreate_label : $create_label); ?>
                </button>
            </p>

            <script>
            jQuery(function($) {
                $('#<?php echo esc_js($button_id); ?>').on('click', function() {
                    if (!window.confirm('<?php echo esc_js($confirm_message); ?>')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: '<?php echo esc_js($ajax_action); ?>',
                        nonce: '<?php echo esc_js(wp_create_nonce($ajax_nonce_action)); ?>'
                    }, function(response) {
                        if (response && response.success) {
                            window.location.reload();
                            return;
                        }

                        var message = (response && response.data) ? response.data : '<?php echo esc_js(__('Unknown error.', 'll-tools-text-domain')); ?>';
                        window.alert('<?php echo esc_js(__('Error:', 'll-tools-text-domain')); ?> ' + message);
                    });
                });
            });
            </script>
        </td>
    </tr>
    <?php
}

/**
 * Shared AJAX handler for creating/recreating a shortcode page on demand.
 */
function ll_tools_handle_default_shortcode_page_creation_ajax(array $config): void {
    $ajax_nonce_action = isset($config['ajax_nonce_action']) ? (string) $config['ajax_nonce_action'] : '';
    $option_key = isset($config['option_key']) ? (string) $config['option_key'] : '';
    $creation_attempt_transient = isset($config['creation_attempt_transient']) ? (string) $config['creation_attempt_transient'] : '';
    $force_option_key = isset($config['force_option_key']) ? (string) $config['force_option_key'] : '';

    if ($ajax_nonce_action === '' || $option_key === '' || $creation_attempt_transient === '' || $force_option_key === '') {
        wp_send_json_error(__('Invalid shortcode page configuration.', 'll-tools-text-domain'));
    }

    check_ajax_referer($ajax_nonce_action, 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    delete_option($option_key);
    delete_transient($creation_attempt_transient);
    update_option($force_option_key, 1);
    ll_tools_ensure_default_shortcode_page($config);

    $page_id = (int) get_option($option_key);
    if ($page_id <= 0) {
        wp_send_json_error(__('Failed to create page', 'll-tools-text-domain'));
    }

    wp_send_json_success([
        'page_id' => $page_id,
        'edit_link' => get_edit_post_link($page_id),
        'view_link' => get_permalink($page_id),
    ]);
}
