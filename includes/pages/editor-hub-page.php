<?php
/**
 * Editor Hub Page Management
 * Handles creation and redirect logic for the default editor hub page.
 */

if (!defined('WPINC')) { die; }

/**
 * Create default Editor Hub page if it doesn't exist.
 * Runs on admin_init and checks if page needs to be created.
 */
function ll_tools_ensure_editor_hub_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    ll_tools_editor_hub_migrate_legacy_page_option();

    $existing_page_id = (int) get_option('ll_default_editor_hub_page_id');
    if ($existing_page_id > 0 && get_post_status($existing_page_id) === 'publish') {
        return;
    }

    if ($existing_page_id > 0) {
        delete_option('ll_default_editor_hub_page_id');
    }

    $found_page_id = ll_find_editor_hub_page();
    if ($found_page_id > 0) {
        update_option('ll_default_editor_hub_page_id', $found_page_id);
        return;
    }

    $creation_attempt = get_transient('ll_editor_hub_page_creation_attempt');
    if ($creation_attempt) {
        return;
    }

    set_transient('ll_editor_hub_page_creation_attempt', time(), 5 * MINUTE_IN_SECONDS);

    $page_id = wp_insert_post([
        'post_title' => __('Editor Hub', 'll-tools-text-domain'),
        'post_content' => '[editor_hub]',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_author' => get_current_user_id() ?: 1,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ]);

    if (!is_wp_error($page_id) && (int) $page_id > 0) {
        $page_id = (int) $page_id;
        update_option('ll_default_editor_hub_page_id', $page_id);
        set_transient('ll_editor_hub_page_created', $page_id, 60);
        return;
    }

    error_log('LL Tools: Failed to create editor hub page. Error: ' . ($page_id instanceof WP_Error ? $page_id->get_error_message() : 'Unknown'));
}
add_action('admin_init', 'll_tools_ensure_editor_hub_page', 21);

/**
 * Show admin notice when editor hub page is created.
 */
function ll_editor_hub_page_created_notice() {
    $page_id = (int) get_transient('ll_editor_hub_page_created');
    if ($page_id <= 0) {
        return;
    }

    delete_transient('ll_editor_hub_page_created');

    $edit_link = get_edit_post_link($page_id);
    $view_link = get_permalink($page_id);

    ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <strong><?php esc_html_e('Editor Hub Page Created!', 'll-tools-text-domain'); ?></strong><br>
            <?php
            printf(
                /* translators: %s: linked page title */
                esc_html__('A default Editor Hub page has been created at %s', 'll-tools-text-domain'),
                '<a href="' . esc_url($view_link) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($page_id)) . '</a>'
            );
            ?>
            |
            <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Edit Page', 'll-tools-text-domain'); ?></a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'll_editor_hub_page_created_notice');

/**
 * Add manual creation button to settings page.
 */
function ll_editor_hub_page_settings_section() {
    $page_id = (int) get_option('ll_default_editor_hub_page_id');
    $page_exists = ($page_id > 0 && get_post_status($page_id) === 'publish');
    ?>
    <tr valign="top">
        <th scope="row"><?php esc_html_e('Default Editor Hub Page', 'll-tools-text-domain'); ?></th>
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
            <?php else: ?>
                <p class="description"><?php esc_html_e('No editor hub page found.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>

            <p>
                <button type="button" class="button" id="ll-create-editor-hub-page">
                    <?php echo $page_exists ? esc_html__('Recreate Editor Hub Page', 'll-tools-text-domain') : esc_html__('Create Editor Hub Page', 'll-tools-text-domain'); ?>
                </button>
            </p>

            <script>
            jQuery(function($) {
                $('#ll-create-editor-hub-page').on('click', function() {
                    if (!window.confirm('<?php echo esc_js(__('Create a new editor hub page?', 'll-tools-text-domain')); ?>')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'll_create_editor_hub_page',
                        nonce: '<?php echo esc_js(wp_create_nonce('ll_create_editor_hub_page')); ?>'
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
add_action('ll_tools_settings_after_translations', 'll_editor_hub_page_settings_section');

/**
 * AJAX handler to manually create editor hub page.
 */
function ll_ajax_create_editor_hub_page() {
    check_ajax_referer('ll_create_editor_hub_page', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    delete_option('ll_default_editor_hub_page_id');
    ll_tools_ensure_editor_hub_page();

    $page_id = (int) get_option('ll_default_editor_hub_page_id');
    if ($page_id > 0) {
        wp_send_json_success([
            'page_id' => $page_id,
            'edit_link' => get_edit_post_link($page_id),
            'view_link' => get_permalink($page_id),
        ]);
        return;
    }

    wp_send_json_error(__('Failed to create page', 'll-tools-text-domain'));
}
add_action('wp_ajax_ll_create_editor_hub_page', 'll_ajax_create_editor_hub_page');

/**
 * Helper: Build best editor hub page URL for a user.
 */
function ll_get_editor_hub_redirect_url($user_id = 0) {
    $user_id = (int) $user_id;
    if ($user_id > 0) {
        $custom_redirect = get_user_meta($user_id, 'll_editor_hub_page_url', true);
        if (!empty($custom_redirect)) {
            return $custom_redirect;
        }
    }

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
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => '[editor_hub',
        'fields' => 'ids',
    ]);

    if (!empty($pages)) {
        $page_id = (int) $pages[0];
        if ($page_id > 0) {
            update_option('ll_default_editor_hub_page_id', $page_id);
            return $page_id;
        }
    }

    $legacy_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => '[missing_text_interface',
        'fields' => 'ids',
    ]);
    if (!empty($legacy_pages)) {
        $page_id = (int) $legacy_pages[0];
        if ($page_id > 0) {
            $content = (string) get_post_field('post_content', $page_id);
            if (strpos($content, '[missing_text_interface') !== false) {
                $updated = str_replace('[missing_text_interface', '[editor_hub', $content);
                wp_update_post([
                    'ID' => $page_id,
                    'post_content' => $updated,
                ]);
            }

            update_option('ll_default_editor_hub_page_id', $page_id);
            return $page_id;
        }
    }

    return 0;
}

/**
 * Migrate legacy Missing Text option key to Editor Hub.
 */
function ll_tools_editor_hub_migrate_legacy_page_option() {
    $existing_page_id = (int) get_option('ll_default_editor_hub_page_id');
    if ($existing_page_id > 0) {
        return;
    }

    $legacy_page_id = (int) get_option('ll_default_missing_text_page_id');
    if ($legacy_page_id > 0) {
        update_option('ll_default_editor_hub_page_id', $legacy_page_id);
        delete_option('ll_default_missing_text_page_id');
    }
}
