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
            __('Audio Recorder', 'll-tools'),
            array(
                'read'         => true,
                'upload_files' => true,
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
 * You’re gating on `upload_files`, so centralize the check.
 */
function ll_tools_user_can_record() {
    return is_user_logged_in() && current_user_can('upload_files');
}

/**
 * Helper function to set recording configuration for a user
 */
function ll_set_user_recording_config($user_id, $config) {
    $allowed_keys = ['wordset', 'category', 'language', 'include_recording_types', 'exclude_recording_types'];
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