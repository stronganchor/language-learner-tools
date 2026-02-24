<?php
/**
 * Recording Page Management
 * Handles creation and redirect logic for the default audio recording page
 */

if (!defined('WPINC')) { die; }

/**
 * Create default audio recording page if it doesn't exist
 * Runs on admin_init and checks if page needs to be created
 */
function ll_tools_ensure_recording_page() {
    ll_tools_ensure_default_shortcode_page([
        'option_key'                 => 'll_default_recording_page_id',
        'force_option_key'           => 'll_tools_force_create_recording_page',
        'creation_attempt_transient' => 'll_recording_page_creation_attempt',
        'created_notice_transient'   => 'll_recording_page_created',
        'shortcode_search'           => '[audio_recording_interface',
        'post_title'                 => __('Record Audio', 'll-tools-text-domain'),
        'post_content'               => '[audio_recording_interface]',
        'error_context'              => 'recording page',
    ]);
}
add_action('admin_init', 'll_tools_ensure_recording_page', 20);

/**
 * Show admin notice when recording page is created
 */
function ll_recording_page_created_notice() {
    $page_id = get_transient('ll_recording_page_created');
    if (!$page_id) {
        return;
    }

    delete_transient('ll_recording_page_created');

    $edit_link = get_edit_post_link($page_id);
    $view_link = get_permalink($page_id);

    ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <strong><?php _e('Audio Recording Page Created!', 'll-tools-text-domain'); ?></strong><br>
            <?php printf(
                __('A default recording page has been created at %s', 'll-tools-text-domain'),
                '<a href="' . esc_url($view_link) . '" target="_blank">' . esc_html(get_the_title($page_id)) . '</a>'
            ); ?>
            |
            <a href="<?php echo esc_url($edit_link); ?>"><?php _e('Edit Page', 'll-tools-text-domain'); ?></a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'll_recording_page_created_notice');

/**
 * Add manual creation button to settings page
 */
function ll_recording_page_settings_section() {
    $page_id = get_option('ll_default_recording_page_id');
    $page_exists = $page_id && get_post_status($page_id) === 'publish';

    ?>
    <tr valign="top">
        <th scope="row"><?php _e('Default Recording Page', 'll-tools-text-domain'); ?></th>
        <td>
            <?php if ($page_exists): ?>
                <p>
                    <strong><?php _e('Current:', 'll-tools-text-domain'); ?></strong>
                    <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank">
                        <?php echo esc_html(get_the_title($page_id)); ?>
                    </a>
                    |
                    <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">
                        <?php _e('Edit', 'll-tools-text-domain'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p class="description">
                    <?php _e('No recording page found.', 'll-tools-text-domain'); ?>
                </p>
            <?php endif; ?>

            <p>
                <button type="button" class="button" id="ll-create-recording-page">
                    <?php echo $page_exists ? __('Recreate Recording Page', 'll-tools-text-domain') : __('Create Recording Page', 'll-tools-text-domain'); ?>
                </button>
            </p>

            <script>
            jQuery(document).ready(function($) {
                $('#ll-create-recording-page').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('Create a new recording page?', 'll-tools-text-domain')); ?>')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'll_create_recording_page',
                        nonce: '<?php echo esc_js(wp_create_nonce('ll_create_recording_page')); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            window.alert('<?php echo esc_js(__('Error:', 'll-tools-text-domain')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error.', 'll-tools-text-domain')); ?>'));
                        }
                    });
                });
            });
            </script>
        </td>
    </tr>
    <?php
}
add_action('ll_tools_settings_after_translations', 'll_recording_page_settings_section');

/**
 * AJAX handler to manually create recording page
 */
function ll_ajax_create_recording_page() {
    check_ajax_referer('ll_create_recording_page', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    // Clear the old page ID
    delete_option('ll_default_recording_page_id');
    delete_transient('ll_recording_page_creation_attempt');

    // Set flag to create page
    update_option('ll_tools_force_create_recording_page', 1);

    // Trigger creation
    ll_tools_ensure_recording_page();

    $page_id = get_option('ll_default_recording_page_id');

    if ($page_id) {
        wp_send_json_success([
            'page_id' => $page_id,
            'edit_link' => get_edit_post_link($page_id),
            'view_link' => get_permalink($page_id),
        ]);
    } else {
        wp_send_json_error(__('Failed to create page', 'll-tools-text-domain'));
    }
}
add_action('wp_ajax_ll_create_recording_page', 'll_ajax_create_recording_page');

/**
 * Helper: Build best recording page URL for a user
 */
function ll_get_recording_redirect_url($user_id = 0) {
    if ($user_id) {
        $custom_redirect = get_user_meta($user_id, 'll_recording_page_url', true);
        if (!empty($custom_redirect)) {
            return $custom_redirect;
        }
    }

    // Default: use the stored recording page
    $recording_page_id = get_option('ll_default_recording_page_id');
    if ($recording_page_id && get_post_status($recording_page_id) === 'publish') {
        return get_permalink($recording_page_id);
    }

    // Fallback: try to find any page with the shortcode
    $recording_page = ll_find_recording_page();
    if ($recording_page) {
        return get_permalink($recording_page);
    }

    // Final fallback to home page
    return home_url();
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
