<?php
if (!defined('WPINC')) { die; }

/**
 * Role & capability: Audio Recorder
 * Grants minimal caps: read + upload_files
 *
 * NOTE:
 * - We DO NOT rely on register_activation_hook here (it only works in the main plugin file).
 * - Instead, we "create or refresh" the role on every load (fast & idempotent).
 */

/**
 * Create the Audio Recorder role if missing, and ensure required caps.
 * Safe to run on every request.
 */
function ll_tools_register_or_refresh_audio_recorder_role() {
    $role = get_role('audio_recorder');

    // Create role if missing
    if (!$role) {
        add_role(
            'audio_recorder',
            __('Audio Recorder', 'll-tools-text-domain'),
            array(
                'read'         => true,
                'upload_files' => true,
                'view_ll_tools' => true,
            )
        );
        $role = get_role('audio_recorder'); // re-fetch for safety
    }

    // Ensure caps exist (guard against other plugins removing them)
    if ($role) {
        if (!$role->has_cap('read')) {
            $role->add_cap('read');
        }
        if (!$role->has_cap('upload_files')) {
            $role->add_cap('upload_files');
        }
        if (!$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
}

/**
 * Run early so the role is always available (front & admin).
 * This replaces the need for an activation hook in the main file.
 */
add_action('plugins_loaded', 'll_tools_register_or_refresh_audio_recorder_role', 1);

/**
 * Optional: If you *also* want to re-ensure on init (e.g., after role manager plugins run),
 * keep this. It’s cheap and idempotent.
 */
add_action('init', 'll_tools_register_or_refresh_audio_recorder_role', 1);

/**
 * Helper: who can record?
 * Recording access requires media upload capability plus LL Tools access
 * (or stricter manage_options access).
 */
function ll_tools_user_can_record() {
    return is_user_logged_in()
        && current_user_can('upload_files')
        && (current_user_can('view_ll_tools') || current_user_can('manage_options'));
}

/**
 * Helper function to set recording configuration for a user
 */
function ll_set_user_recording_config($user_id, $config) {
    $allowed_keys = ['wordset', 'category', 'language', 'include_recording_types', 'exclude_recording_types', 'allow_new_words', 'auto_process_recordings'];
    $filtered_config = [];

    foreach ($allowed_keys as $key) {
        if (isset($config[$key])) {
            $filtered_config[$key] = sanitize_text_field($config[$key]);
        }
    }

    return update_user_meta($user_id, 'll_recording_config', $filtered_config);
}

/**
 * Helper function to get recording configuration for a user
 */
function ll_get_user_recording_config($user_id) {
    return get_user_meta($user_id, 'll_recording_config', true);
}

/**
 * Add recording configuration fields to user profile
 */
function ll_audio_recorder_profile_fields($user) {
    // Only show for users with audio_recorder role or admins editing audio recorders
    if (!in_array('audio_recorder', (array) $user->roles) && !current_user_can('manage_options')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return; // Only admins can edit these settings
    }

    $config = ll_get_user_recording_config($user->ID);
    $custom_url = get_user_meta($user->ID, 'll_recording_page_url', true);

    // Get available wordsets
    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
    ]);

    // Get available recording types
    $recording_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
    ]);

    ?>
    <h2><?php _e('Audio Recording Configuration', 'll-tools-text-domain'); ?></h2>
    <p class="description">
        <?php _e('Configure what this user should record when they use the audio recording interface.', 'll-tools-text-domain'); ?>
    </p>

    <table class="form-table">
        <tr>
            <th><label for="ll_recording_wordset"><?php _e('Word Set', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[wordset]" id="ll_recording_wordset" class="regular-text">
                    <option value=""><?php _e('-- Default --', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($wordsets as $wordset): ?>
                        <option value="<?php echo esc_attr($wordset->slug); ?>"
                                <?php selected(isset($config['wordset']) ? $config['wordset'] : '', $wordset->slug); ?>>
                            <?php echo esc_html($wordset->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Which word set should this user record?', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_include_recording_types"><?php _e('Include Recording Types', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[include_recording_types][]" id="ll_include_recording_types" class="regular-text" multiple size="4">
                    <?php
                    $current_include = isset($config['include_recording_types']) ? explode(',', $config['include_recording_types']) : [];
                    foreach ($recording_types as $type):
                    ?>
                        <option value="<?php echo esc_attr($type->slug); ?>"
                                <?php echo in_array($type->slug, $current_include) ? 'selected' : ''; ?>>
                            <?php echo esc_html($type->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Leave empty to allow all types. Select specific types to limit what they record. Hold Ctrl/Cmd to select multiple.', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_exclude_recording_types"><?php _e('Exclude Recording Types', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[exclude_recording_types][]" id="ll_exclude_recording_types" class="regular-text" multiple size="4">
                    <?php
                    $current_exclude = isset($config['exclude_recording_types']) ? explode(',', $config['exclude_recording_types']) : [];
                    foreach ($recording_types as $type):
                    ?>
                        <option value="<?php echo esc_attr($type->slug); ?>"
                                <?php echo in_array($type->slug, $current_exclude) ? 'selected' : ''; ?>>
                            <?php echo esc_html($type->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Types to exclude from this user. Only used if "Include Types" is empty.', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_recording_page_url"><?php _e('Custom Recording Page URL', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="url" name="ll_recording_page_url" id="ll_recording_page_url"
                       value="<?php echo esc_attr($custom_url); ?>" class="regular-text" />
                <p class="description">
                    <?php _e('Optional: Specify a custom URL to redirect this user to on login. Leave empty to use the default recording page.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="ll_allow_new_words"><?php _e('Allow New Words', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="hidden" name="ll_recording_config[allow_new_words]" value="0" />
                <label>
                    <input type="checkbox" name="ll_recording_config[allow_new_words]" id="ll_allow_new_words" value="1"
                        <?php checked(isset($config['allow_new_words']) ? $config['allow_new_words'] : '', '1'); ?> />
                    <?php _e('Allow this user to record brand new words without existing posts or images.', 'll-tools-text-domain'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="ll_auto_process_recordings"><?php _e('Auto-process Recordings', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="hidden" name="ll_recording_config[auto_process_recordings]" value="0" />
                <label>
                    <input type="checkbox" name="ll_recording_config[auto_process_recordings]" id="ll_auto_process_recordings" value="1"
                        <?php checked(isset($config['auto_process_recordings']) ? $config['auto_process_recordings'] : '', '1'); ?> />
                    <?php _e('Process recordings immediately and publish them after review.', 'll-tools-text-domain'); ?>
                </label>
                <p class="description">
                    <?php _e('Applies trimming, noise reduction, and loudness normalization in the recording interface so the recorder can review and adjust trim boundaries before saving.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'll_audio_recorder_profile_fields');
add_action('edit_user_profile', 'll_audio_recorder_profile_fields');

/**
 * Add recording configuration fields to the "Add New User" page
 */
function ll_audio_recorder_new_user_fields() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get available wordsets
    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
    ]);

    // Get available recording types
    $recording_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
    ]);

    ?>
    <table class="form-table ll-audio-recorder-config" style="display: none;">
        <tr>
            <th colspan="2">
                <h2><?php _e('Audio Recording Configuration', 'll-tools-text-domain'); ?></h2>
                <p class="description">
                    <?php _e('Configure what this user should record when they use the audio recording interface.', 'll-tools-text-domain'); ?>
                </p>
            </th>
        </tr>
        <tr>
            <th><label for="ll_recording_wordset"><?php _e('Word Set', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[wordset]" id="ll_recording_wordset" class="regular-text">
                    <option value=""><?php _e('-- Default --', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($wordsets as $wordset): ?>
                        <option value="<?php echo esc_attr($wordset->slug); ?>">
                            <?php echo esc_html($wordset->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Which word set should this user record?', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_include_recording_types"><?php _e('Include Recording Types', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[include_recording_types][]" id="ll_include_recording_types" class="regular-text" multiple size="4">
                    <?php foreach ($recording_types as $type): ?>
                        <option value="<?php echo esc_attr($type->slug); ?>">
                            <?php echo esc_html($type->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Leave empty to allow all types. Select specific types to limit what they record. Hold Ctrl/Cmd to select multiple.', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_exclude_recording_types"><?php _e('Exclude Recording Types', 'll-tools-text-domain'); ?></label></th>
            <td>
                <select name="ll_recording_config[exclude_recording_types][]" id="ll_exclude_recording_types" class="regular-text" multiple size="4">
                    <?php foreach ($recording_types as $type): ?>
                        <option value="<?php echo esc_attr($type->slug); ?>">
                            <?php echo esc_html($type->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Types to exclude from this user. Only used if "Include Types" is empty.', 'll-tools-text-domain'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ll_recording_page_url"><?php _e('Custom Recording Page URL', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="url" name="ll_recording_page_url" id="ll_recording_page_url" value="" class="regular-text" />
                <p class="description">
                    <?php _e('Optional: Specify a custom URL to redirect this user to on login. Leave empty to use the default recording page.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="ll_allow_new_words"><?php _e('Allow New Words', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="hidden" name="ll_recording_config[allow_new_words]" value="0" />
                <label>
                    <input type="checkbox" name="ll_recording_config[allow_new_words]" id="ll_allow_new_words" value="1" />
                    <?php _e('Allow this user to record brand new words without existing posts or images.', 'll-tools-text-domain'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="ll_auto_process_recordings"><?php _e('Auto-process Recordings', 'll-tools-text-domain'); ?></label></th>
            <td>
                <input type="hidden" name="ll_recording_config[auto_process_recordings]" value="0" />
                <label>
                    <input type="checkbox" name="ll_recording_config[auto_process_recordings]" id="ll_auto_process_recordings" value="1" />
                    <?php _e('Process recordings immediately and publish them after review.', 'll-tools-text-domain'); ?>
                </label>
                <p class="description">
                    <?php _e('Applies trimming, noise reduction, and loudness normalization in the recording interface so the recorder can review and adjust trim boundaries before saving.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
    </table>

    <script>
    jQuery(document).ready(function($) {
        var roleSelect = $('#role');
        var configSection = $('.ll-audio-recorder-config');

        function toggleConfigSection() {
            if (roleSelect.val() === 'audio_recorder') {
                configSection.show();
            } else {
                configSection.hide();
            }
        }

        // Initial check
        toggleConfigSection();

        // Watch for role changes
        roleSelect.on('change', toggleConfigSection);
    });
    </script>
    <?php
}
add_action('user_new_form', 'll_audio_recorder_new_user_fields');

/**
 * Save recording configuration fields
 */
function ll_save_audio_recorder_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save recording config
    if (isset($_POST['ll_recording_config']) && is_array($_POST['ll_recording_config'])) {
        $config = $_POST['ll_recording_config'];

        // Handle multi-select for include/exclude types
        if (isset($config['include_recording_types'])) {
            if (is_array($config['include_recording_types'])) {
                $config['include_recording_types'] = implode(',', array_map('sanitize_text_field', $config['include_recording_types']));
            }
        }

        if (isset($config['exclude_recording_types'])) {
            if (is_array($config['exclude_recording_types'])) {
                $config['exclude_recording_types'] = implode(',', array_map('sanitize_text_field', $config['exclude_recording_types']));
            }
        }

        ll_set_user_recording_config($user_id, $config);
    }

    // Save custom URL
    if (isset($_POST['ll_recording_page_url'])) {
        $url = esc_url_raw($_POST['ll_recording_page_url']);
        update_user_meta($user_id, 'll_recording_page_url', $url);
    }
}
add_action('personal_options_update', 'll_save_audio_recorder_profile_fields');
add_action('edit_user_profile_update', 'll_save_audio_recorder_profile_fields');
add_action('user_register', 'll_save_audio_recorder_profile_fields');

/**
 * Hide admin toolbar for limited front-end focused roles.
 */
function ll_hide_admin_bar_for_ll_tools_limited_roles($show) {
    if (!is_user_logged_in()) {
        return $show;
    }

    $user = wp_get_current_user();
    if (!$user) {
        return $show;
    }
    if (user_can($user, 'manage_options')) {
        return $show;
    }

    $roles = (array) $user->roles;
    $hide_for_roles = ['audio_recorder', 'll_tools_learner', 'll_tools_editor'];
    foreach ($hide_for_roles as $role) {
        if (in_array($role, $roles, true)) {
            return false;
        }
    }

    return $show;
}
add_filter('show_admin_bar', 'll_hide_admin_bar_for_ll_tools_limited_roles', 999);

/**
 * Prevent recorder/learner users from accessing wp-admin and redirect them.
 */
function ll_block_admin_for_recorder_and_learner() {
    if (!is_user_logged_in()) {
        return;
    }

    if (!is_admin() || wp_doing_ajax()) {
        return;
    }

    $user = wp_get_current_user();
    if (!$user) {
        return;
    }
    if (user_can($user, 'manage_options')) {
        return;
    }

    $roles = (array) $user->roles;
    $is_recorder = in_array('audio_recorder', $roles, true);
    $is_learner = in_array('ll_tools_learner', $roles, true);

    if (!$is_recorder && !$is_learner) {
        return;
    }

    $target = home_url('/');
    if ($is_recorder && function_exists('ll_get_recording_redirect_url')) {
        $target = ll_get_recording_redirect_url((int) $user->ID);
    } elseif ($is_learner && function_exists('ll_tools_get_study_dashboard_redirect_url')) {
        $target = ll_tools_get_study_dashboard_redirect_url();
    }

    $target = wp_validate_redirect($target, home_url('/'));
    wp_safe_redirect($target);
    exit;
}
// Priority 1 so it runs early during admin bootstrap.
add_action('admin_init', 'll_block_admin_for_recorder_and_learner', 1);
