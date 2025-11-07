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
    // Only run for admins to avoid unnecessary queries on every page load
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if a recording page already exists and is valid
    $existing_page_id = get_option('ll_default_recording_page_id');
    if ($existing_page_id && get_post_status($existing_page_id) === 'publish') {
        return; // Page exists and is published
    }

    // If we had a page ID but it's not published anymore, clear it
    if ($existing_page_id) {
        delete_option('ll_default_recording_page_id');
    }

    // Search for existing page with the shortcode
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => '[audio_recording_interface',
        'fields' => 'ids',
    ]);

    if (!empty($pages)) {
        // Found existing page, just save the ID
        update_option('ll_default_recording_page_id', $pages[0]);
        return;
    }

    // Check if we've already tried to create recently (avoid duplicate creation)
    $creation_attempt = get_transient('ll_recording_page_creation_attempt');
    if ($creation_attempt) {
        return; // Already tried recently, don't spam
    }

    // Set transient to prevent duplicate attempts (5 minute cooldown)
    set_transient('ll_recording_page_creation_attempt', time(), 5 * MINUTE_IN_SECONDS);

    // Create new recording page
    $page_id = wp_insert_post([
        'post_title' => __('Record Audio', 'll-tools-text-domain'),
        'post_content' => '[audio_recording_interface]',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_author' => get_current_user_id() ?: 1,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ]);

    if (!is_wp_error($page_id) && $page_id > 0) {
        update_option('ll_default_recording_page_id', $page_id);

        // Add admin notice
        set_transient('ll_recording_page_created', $page_id, 60);
    } else {
        // Log error for debugging
        error_log('LL Tools: Failed to create recording page. Error: ' . ($page_id instanceof WP_Error ? $page_id->get_error_message() : 'Unknown'));
    }
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
                        nonce: '<?php echo wp_create_nonce('ll_create_recording_page'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
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
        wp_send_json_error('Permission denied');
    }

    // Clear the old page ID
    delete_option('ll_default_recording_page_id');

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
        wp_send_json_error('Failed to create page');
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
    // Only redirect if it's a WP_User object and has audio_recorder role
    if (isset($user->roles) && is_array($user->roles) && in_array('audio_recorder', $user->roles)) {
        return ll_get_recording_redirect_url($user->ID);
    }

    return $redirect_to;
}
// High priority to win over other plugins altering login redirects
add_filter('login_redirect', 'll_audio_recorder_login_redirect', 999, 3);

/**
 * Find a page that contains the audio_recording_interface shortcode
 */
function ll_find_recording_page() {
    // Search for pages with the shortcode
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => '[audio_recording_interface',
    ]);

    if (!empty($pages)) {
        $page_id = $pages[0]->ID;
        update_option('ll_default_recording_page_id', $page_id);
        return $page_id;
    }

    return null;
}
