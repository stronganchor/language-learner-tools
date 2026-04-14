<?php

/************************************************************************************
 * [audio_upload_form] Shortcode
 * 
 * Bulk upload audio files & generate new word posts
 ***********************************************************************************/

/**
 * Shortcode handler for [audio_upload_form].
 *
 * Supported attributes:
 * - wordset_id (int): preselect a word set
 * - lock_wordset (0|1): hide the selector and force the provided word set
 * - return_url (string): optional redirect target after processing
 *
 * @return string The HTML form for uploading audio files.
 */
function ll_audio_upload_user_can_assign_other_speakers() {
    return current_user_can('manage_options')
        || current_user_can('promote_users')
        || current_user_can('edit_users')
        || current_user_can('list_users');
}

function ll_audio_upload_get_assignable_speaker_users() {
    if (!ll_audio_upload_user_can_assign_other_speakers()) {
        return [];
    }

    $users = get_users([
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);
    if (empty($users)) {
        return [];
    }

    return array_values(array_filter($users, static function ($user) {
        return $user instanceof WP_User
            && (
                user_can($user, 'manage_options')
                || (user_can($user, 'upload_files') && user_can($user, 'view_ll_tools'))
            );
    }));
}

function ll_audio_upload_format_speaker_user_label(WP_User $user): string {
    $display_name = trim((string) $user->display_name);
    if ($display_name !== '') {
        return $display_name;
    }

    return (string) $user->user_login;
}

function ll_audio_upload_resolve_speaker_user_id($speaker_assignment): int {
    $current_user_id = (int) get_current_user_id();

    if ($speaker_assignment === 'unassigned') {
        return 0;
    }

    if ($speaker_assignment === 'current' || $speaker_assignment === null || $speaker_assignment === '') {
        return $current_user_id;
    }

    if (!is_numeric($speaker_assignment)) {
        return $current_user_id;
    }

    $speaker_user_id = (int) $speaker_assignment;
    if ($speaker_user_id <= 0) {
        return $current_user_id;
    }

    if (!ll_audio_upload_user_can_assign_other_speakers()) {
        return $current_user_id;
    }

    $assignable_users = ll_audio_upload_get_assignable_speaker_users();
    foreach ($assignable_users as $user) {
        if ((int) $user->ID === $speaker_user_id) {
            return $speaker_user_id;
        }
    }

    return $current_user_id;
}

function ll_audio_upload_enqueue_form_assets(): void {
    ll_enqueue_asset_by_timestamp('/js/audio-upload-form-admin.js', 'll-audio-upload-form-admin', ['jquery'], true);
}

/**
 * Sanitize incoming wordset IDs from the audio upload form.
 *
 * @param mixed $raw_ids Raw IDs from request data.
 * @return int[]
 */
function ll_audio_upload_sanitize_wordset_ids($raw_ids): array {
    if (function_exists('ll_image_upload_sanitize_wordset_ids')) {
        return ll_image_upload_sanitize_wordset_ids($raw_ids);
    }

    $ids = array_map('intval', (array) $raw_ids);
    $ids = array_values(array_unique(array_filter($ids, static function ($id) {
        return $id > 0;
    })));

    if (!current_user_can('manage_options') && function_exists('ll_tools_get_user_managed_wordset_ids')) {
        $allowed_ids = array_map('intval', (array) ll_tools_get_user_managed_wordset_ids(get_current_user_id()));
        if (!empty($allowed_ids)) {
            $ids = array_values(array_intersect($ids, $allowed_ids));
        } elseif (in_array('wordset_manager', (array) wp_get_current_user()->roles, true)) {
            $ids = [];
        }
    }

    return $ids;
}

/**
 * Parse selected wordsets from audio-upload form data.
 *
 * Supports the scope-first UI plus legacy single-wordset fields.
 *
 * @param array $post_data Unslashed request-style form data.
 * @return int[]
 */
function ll_audio_upload_get_requested_wordset_ids_from_post_data(array $post_data): array {
    if (isset($post_data['ll_wordset_ids'])) {
        return ll_audio_upload_sanitize_wordset_ids($post_data['ll_wordset_ids']);
    }

    $scope_mode = isset($post_data['ll_wordset_scope_mode'])
        ? sanitize_key((string) $post_data['ll_wordset_scope_mode'])
        : 'single';

    if ($scope_mode === 'multiple') {
        $raw_ids = isset($post_data['ll_multi_wordset_ids']) ? $post_data['ll_multi_wordset_ids'] : [];
        return ll_audio_upload_sanitize_wordset_ids($raw_ids);
    }

    $single_wordset_id = isset($post_data['ll_single_wordset_id'])
        ? (int) $post_data['ll_single_wordset_id']
        : 0;

    if ($single_wordset_id <= 0 && isset($post_data['ll_wordset_id'])) {
        $single_wordset_id = (int) $post_data['ll_wordset_id'];
    }
    if ($single_wordset_id <= 0 && isset($post_data['selected_wordset'])) {
        $single_wordset_id = (int) $post_data['selected_wordset'];
    }
    if ($single_wordset_id <= 0 && function_exists('ll_tools_get_active_wordset_id')) {
        $single_wordset_id = (int) ll_tools_get_active_wordset_id();
    }

    return ll_audio_upload_sanitize_wordset_ids($single_wordset_id > 0 ? [$single_wordset_id] : []);
}

/**
 * Parse selected wordsets from the current request.
 *
 * @return int[]
 */
function ll_audio_upload_get_requested_wordset_ids_from_request(): array {
    return ll_audio_upload_get_requested_wordset_ids_from_post_data((array) wp_unslash($_POST));
}

/**
 * Resolve selected categories from the current request, including new-category creation.
 *
 * @param int[] $requested_wordset_ids Target wordsets chosen in the form.
 * @return int[]|WP_Error
 */
function ll_audio_upload_get_selected_categories_from_request(array $requested_wordset_ids = []) {
    $new_category_title = isset($_POST['ll_new_category_title'])
        ? sanitize_text_field(wp_unslash($_POST['ll_new_category_title']))
        : '';
    $category_mode = isset($_POST['ll_category_mode'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_category_mode']))
        : (($new_category_title !== '') ? 'new' : 'existing');

    $selected_categories = [];
    if ($category_mode === 'new' || $new_category_title !== '') {
        if (!function_exists('ll_image_upload_create_category_from_request')) {
            return new WP_Error(
                'll_audio_upload_category_create_unavailable',
                __('Category creation is not available right now.', 'll-tools-text-domain')
            );
        }

        $created_category_id = ll_image_upload_create_category_from_request();
        if (is_wp_error($created_category_id)) {
            return $created_category_id;
        }
        if ((int) $created_category_id > 0) {
            $selected_categories = [(int) $created_category_id];
        }
    } else {
        if (isset($_POST['ll_existing_category'])) {
            $selected_categories = [(int) wp_unslash((string) $_POST['ll_existing_category'])];
        } else {
            $selected_categories = isset($_POST['ll_word_categories']) ? (array) wp_unslash($_POST['ll_word_categories']) : [];
        }
    }

    $selected_categories = array_values(array_filter(array_map('intval', (array) $selected_categories), static function ($term_id) {
        return $term_id > 0;
    }));

    if (!empty($selected_categories) && !empty($requested_wordset_ids) && function_exists('ll_tools_get_isolated_category_ids_for_wordsets')) {
        $selected_categories = ll_tools_get_isolated_category_ids_for_wordsets($selected_categories, $requested_wordset_ids);
    }

    return $selected_categories;
}

function ll_audio_upload_form_shortcode($atts = []) {
    if (!current_user_can('upload_files') || !current_user_can('view_ll_tools')) {
        return esc_html__('You do not have permission to upload files.', 'll-tools-text-domain');
    }

    $atts = shortcode_atts([
        'wordset_id' => 0,
        'lock_wordset' => '0',
        'return_url' => '',
    ], (array) $atts, 'audio_upload_form');

    $requested_wordset_id = max(0, (int) $atts['wordset_id']);
    $lock_wordset = !empty($atts['lock_wordset']) && $atts['lock_wordset'] !== '0';
    $return_url = '';
    if (!empty($atts['return_url'])) {
        $validated_return = wp_validate_redirect((string) $atts['return_url'], '');
        if (is_string($validated_return)) {
            $return_url = $validated_return;
        }
    }

    ll_audio_upload_enqueue_form_assets();

    // Get recording types
    $recording_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
    ]);

    $users = ll_audio_upload_get_assignable_speaker_users();

    $wordsets = function_exists('ll_image_upload_get_accessible_wordsets')
        ? ll_image_upload_get_accessible_wordsets($requested_wordset_id)
        : [];

    $preselected_wordset = null;
    if ($requested_wordset_id > 0) {
        foreach ((array) $wordsets as $candidate_wordset) {
            if ((int) ($candidate_wordset->term_id ?? 0) === $requested_wordset_id) {
                $preselected_wordset = $candidate_wordset;
                break;
            }
        }
    }
    if ($lock_wordset && !$preselected_wordset) {
        return esc_html__('That word set is not available for audio upload.', 'll-tools-text-domain');
    }
    $available_wordset_ids = array_values(array_filter(array_map('intval', wp_list_pluck((array) $wordsets, 'term_id')), static function ($wordset_id) {
        return $wordset_id > 0;
    }));
    $wordset_selection_locked = $lock_wordset || count($available_wordset_ids) === 1;
    if (!$preselected_wordset && $wordset_selection_locked && !empty($wordsets)) {
        $preselected_wordset = $wordsets[0];
    }
    $preselected_wordset_id = ($preselected_wordset && isset($preselected_wordset->term_id))
        ? (int) $preselected_wordset->term_id
        : 0;
    $default_single_wordset_id = $preselected_wordset_id > 0
        ? $preselected_wordset_id
        : ((count($available_wordset_ids) === 1) ? (int) $available_wordset_ids[0] : 0);

    $logical_category_options = function_exists('ll_image_upload_get_logical_category_options')
        ? ll_image_upload_get_logical_category_options($available_wordset_ids)
        : [];

    $default_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $can_create_categories = current_user_can('manage_categories');
    $translation_context_ids = $default_single_wordset_id > 0 ? [$default_single_wordset_id] : [];
    $show_translation_field = function_exists('ll_tools_is_category_translation_enabled')
        ? ll_tools_is_category_translation_enabled($translation_context_ids)
        : false;
    if (!$show_translation_field && function_exists('ll_tools_should_show_category_translation_ui')) {
        $show_translation_field = ll_tools_should_show_category_translation_ui();
    }

    ob_start();
    ?>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data" data-ll-audio-upload-form="1">
        <!-- only allow audio files -->
        <input type="file" name="ll_audio_files[]" accept="audio/*" multiple /><br>

        <label>
            <input type="checkbox" id="match_existing_posts" name="match_existing_posts" value="1" data-ll-match-existing>
            <?php esc_html_e( 'Match to existing word posts instead of creating new ones', 'll-tools-text-domain' ); ?>
        </label><br>

        <p
            class="description"
            style="display:none; margin:6px 0 0;"
            data-ll-match-mode-note
            hidden
        >
            <?php esc_html_e('Matching existing words only supports one word set at a time.', 'll-tools-text-domain'); ?>
        </p>

        <div style="margin-top:10px;">
            <label><?php esc_html_e( 'Recording Type', 'll-tools-text-domain' ); ?>:</label><br>
            <select name="ll_recording_type" required>
                <?php
                if (!empty($recording_types) && !is_wp_error($recording_types)) {
                    foreach ($recording_types as $type) {
                        $selected = ($type->slug === 'isolation') ? 'selected' : '';
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($type->slug),
                            $selected,
                            esc_html($type->name)
                        );
                    }
                } else {
                    echo '<option value="isolation">' . esc_html__('Isolation', 'll-tools-text-domain') . '</option>';
                }
                ?>
            </select>
        </div>

        <div style="margin-top:10px;">
            <label><?php esc_html_e( 'Speaker Assignment', 'll-tools-text-domain' ); ?>:</label><br>
            <select name="ll_speaker_assignment" required>
                <option value="current"><?php esc_html_e( 'Current User', 'll-tools-text-domain'); ?> (<?php echo esc_html(wp_get_current_user()->display_name); ?>)</option>
                <option value="unassigned"><?php esc_html_e( 'Unassigned', 'll-tools-text-domain' ); ?></option>
                <?php if (!empty($users)): ?>
                    <optgroup label="<?php esc_attr_e('Other Users', 'll-tools-text-domain'); ?>">
                        <?php foreach ($users as $user): ?>
                            <?php if ($user->ID !== get_current_user_id()): ?>
                                <option value="<?php echo esc_attr($user->ID); ?>">
                                    <?php echo esc_html(ll_audio_upload_format_speaker_user_label($user)); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <div style="margin-top:10px;" data-ll-audio-create-only>
            <label>
                <input type="checkbox" id="match_image_on_translation" name="match_image_on_translation" value="1">
                <?php esc_html_e('Match images based on translation instead of original word', 'll-tools-text-domain'); ?>
            </label><br>
        </div>

        <div style="margin-top:10px;" data-ll-wordset-scope-root>
            <label><strong><?php esc_html_e('Target Scope', 'll-tools-text-domain'); ?></strong></label><br>
            <?php if ($wordset_selection_locked && $default_single_wordset_id > 0 && $preselected_wordset instanceof WP_Term) : ?>
                <input type="hidden" name="ll_wordset_scope_mode" value="single">
                <input type="hidden" name="ll_single_wordset_id" value="<?php echo esc_attr($default_single_wordset_id); ?>">
                <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;" data-ll-wordset-scope-locked="1">
                    <strong><?php echo esc_html((string) $preselected_wordset->name); ?></strong>
                    <span class="description" style="margin:0;">
                        <?php
                        echo esc_html(
                            $lock_wordset
                                ? __('Locked to this word set', 'll-tools-text-domain')
                                : __('Only accessible word set', 'll-tools-text-domain')
                        );
                        ?>
                    </span>
                </div>
                <p class="description"><?php esc_html_e('Audio uploads will use this word set automatically.', 'll-tools-text-domain'); ?></p>
            <?php elseif (!empty($wordsets)) : ?>
                <fieldset style="margin:6px 0 0;">
                    <label style="display:inline-block; margin-right:16px;">
                        <input type="radio" name="ll_wordset_scope_mode" value="single" checked data-ll-scope-mode>
                        <?php esc_html_e('One word set', 'll-tools-text-domain'); ?>
                    </label>
                    <label style="display:inline-block;">
                        <input type="radio" name="ll_wordset_scope_mode" value="multiple" data-ll-scope-mode>
                        <?php esc_html_e('Multiple word sets', 'll-tools-text-domain'); ?>
                    </label>
                </fieldset>
                <p class="description"><?php esc_html_e('Choose where this upload should land before selecting a category.', 'll-tools-text-domain'); ?></p>

                <div style="margin-top:8px;" data-ll-single-wordset-wrap>
                    <label for="ll-audio-upload-single-wordset"><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?>:</label><br>
                    <select id="ll-audio-upload-single-wordset" name="ll_single_wordset_id" class="regular-text" data-ll-single-wordset>
                        <option value="0"><?php esc_html_e('— Select —', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($wordsets as $ws) : ?>
                            <option value="<?php echo esc_attr((int) $ws->term_id); ?>" <?php selected($default_single_wordset_id, (int) $ws->term_id); ?>>
                                <?php echo esc_html((string) $ws->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-top:8px; display:none;" data-ll-multi-wordset-wrap>
                    <label><?php esc_html_e('Word Sets', 'll-tools-text-domain'); ?>:</label><br>
                    <div style="max-height:160px; overflow:auto; border:1px solid #ccd0d4; padding:6px;">
                        <?php foreach ($wordsets as $ws) : ?>
                            <label style="display:block; margin:2px 0;">
                                <input
                                    type="checkbox"
                                    name="ll_multi_wordset_ids[]"
                                    value="<?php echo esc_attr((int) $ws->term_id); ?>"
                                    data-ll-multi-wordset
                                    data-ll-wordset-label="<?php echo esc_attr((string) $ws->name); ?>"
                                >
                                <?php echo esc_html((string) $ws->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e('New words will be created in the same logical category across every selected word set.', 'll-tools-text-domain'); ?></p>
                </div>
            <?php else : ?>
                <p class="description"><?php esc_html_e('No word sets are available for audio upload right now.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </div>

        <div style="margin-top:10px;" data-ll-audio-create-only>
            <?php if ($can_create_categories) : ?>
                <label><strong><?php esc_html_e('Category Source', 'll-tools-text-domain'); ?></strong></label><br>
                <fieldset style="margin:6px 0 0;">
                    <label style="display:inline-block; margin-right:16px;">
                        <input type="radio" name="ll_category_mode" value="existing" checked data-ll-category-mode>
                        <?php esc_html_e('Use existing category', 'll-tools-text-domain'); ?>
                    </label>
                    <label style="display:inline-block;">
                        <input type="radio" name="ll_category_mode" value="new" data-ll-category-mode>
                        <?php esc_html_e('Create new category', 'll-tools-text-domain'); ?>
                    </label>
                </fieldset>
            <?php else : ?>
                <input type="hidden" name="ll_category_mode" value="existing">
            <?php endif; ?>
        </div>

        <div style="margin-top:10px;" data-ll-category-existing-wrap data-ll-audio-create-only>
            <label for="ll-audio-existing-category"><?php esc_html_e('Choose Category', 'll-tools-text-domain'); ?>:</label><br>
            <select id="ll-audio-existing-category" name="ll_existing_category" class="regular-text" data-ll-existing-category>
                <option value="0"><?php esc_html_e('— Select —', 'll-tools-text-domain'); ?></option>
                <?php foreach ($logical_category_options as $category_option) : ?>
                    <option
                        value="<?php echo esc_attr((int) $category_option['id']); ?>"
                        data-ll-category-wordsets="<?php echo esc_attr(implode(',', array_map('intval', (array) ($category_option['wordset_ids'] ?? [])))); ?>"
                        data-ll-category-shared="<?php echo !empty($category_option['is_shared']) ? '1' : '0'; ?>"
                    >
                        <?php echo esc_html((string) ($category_option['label'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('The selected logical category will be resolved into the chosen word set scope automatically.', 'll-tools-text-domain'); ?></p>
        </div>

        <?php if ($can_create_categories) : ?>
            <div style="margin-top:10px; display:none;" data-ll-new-category-wrap data-ll-audio-create-only>
                <label for="ll-audio-new-category-title"><?php esc_html_e('New Category Name', 'll-tools-text-domain'); ?>:</label><br>
                <input type="text" id="ll-audio-new-category-title" name="ll_new_category_title" class="regular-text" value="" data-ll-new-category-title>
                <p class="description"><?php esc_html_e('The new category will be created inside the selected word set scope.', 'll-tools-text-domain'); ?></p>
            </div>

            <div style="margin-top:10px; display:none;" data-ll-new-category-advanced data-ll-audio-create-only>
                <label for="ll-audio-new-category-parent"><?php esc_html_e('Parent Category', 'll-tools-text-domain'); ?>:</label><br>
                <select id="ll-audio-new-category-parent" name="ll_new_category_parent" class="regular-text" data-ll-new-category-parent>
                    <option value="0"><?php esc_html_e('None', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($logical_category_options as $category_option) : ?>
                        <option
                            value="<?php echo esc_attr((int) $category_option['id']); ?>"
                            data-ll-category-wordsets="<?php echo esc_attr(implode(',', array_map('intval', (array) ($category_option['wordset_ids'] ?? [])))); ?>"
                            data-ll-category-shared="<?php echo !empty($category_option['is_shared']) ? '1' : '0'; ?>"
                        >
                            <?php echo esc_html((string) ($category_option['label'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($show_translation_field) : ?>
                    <div style="margin-top:10px;">
                        <label for="ll-audio-new-category-translation"><?php esc_html_e('Translated Name', 'll-tools-text-domain'); ?>:</label><br>
                        <input type="text" id="ll-audio-new-category-translation" name="ll_new_category_translation" class="regular-text" value="">
                    </div>
                <?php endif; ?>

                <div style="margin-top:10px;">
                    <label for="ll-audio-new-category-prompt"><?php esc_html_e('Quiz Prompt Type', 'll-tools-text-domain'); ?>:</label><br>
                    <select id="ll-audio-new-category-prompt" name="ll_new_category_prompt_type" class="regular-text" data-ll-new-category-prompt>
                        <option value="audio"><?php esc_html_e('Play audio (default)', 'll-tools-text-domain'); ?></option>
                        <option value="audio_text_translation"><?php esc_html_e('Play audio + show text (translation)', 'll-tools-text-domain'); ?></option>
                        <option value="audio_text_title"><?php esc_html_e('Play audio + show text (title)', 'll-tools-text-domain'); ?></option>
                        <option value="image"><?php esc_html_e('Show image', 'll-tools-text-domain'); ?></option>
                        <option value="image_text_translation"><?php esc_html_e('Show image + text (translation)', 'll-tools-text-domain'); ?></option>
                        <option value="image_text_title"><?php esc_html_e('Show image + text (title)', 'll-tools-text-domain'); ?></option>
                        <option value="text_translation"><?php esc_html_e('Show text (translation)', 'll-tools-text-domain'); ?></option>
                        <option value="text_title"><?php esc_html_e('Show text (title)', 'll-tools-text-domain'); ?></option>
                    </select>
                </div>

                <div style="margin-top:10px;">
                    <label for="ll-audio-new-category-option"><?php esc_html_e('Answer Options', 'll-tools-text-domain'); ?>:</label><br>
                    <select id="ll-audio-new-category-option" name="ll_new_category_option_type" class="regular-text" data-ll-new-category-option>
                        <option value="image"><?php esc_html_e('Images', 'll-tools-text-domain'); ?></option>
                        <option value="text"><?php esc_html_e('Text (opposite prompt)', 'll-tools-text-domain'); ?></option>
                        <option value="text_translation"><?php esc_html_e('Text (translation)', 'll-tools-text-domain'); ?></option>
                        <option value="text_title"><?php esc_html_e('Text (title)', 'll-tools-text-domain'); ?></option>
                        <option value="audio"><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
                        <option value="text_audio"><?php esc_html_e('Text + audio pairs', 'll-tools-text-domain'); ?></option>
                    </select>
                </div>

                <div style="margin-top:10px;">
                    <input type="hidden" name="ll_new_category_desired_recording_types_submitted" value="1">
                    <label><?php esc_html_e('Desired Recording Types', 'll-tools-text-domain'); ?>:</label><br>
                    <div style="max-height:140px; overflow:auto; border:1px solid #ccd0d4; padding:6px;">
                        <?php foreach ($recording_types as $type) : ?>
                            <?php $checked = in_array((string) $type->slug, $default_recording_types, true) ? 'checked' : ''; ?>
                            <label style="display:block; margin:2px 0;">
                                <input type="checkbox" name="ll_new_category_desired_recording_types[]" value="<?php echo esc_attr($type->slug); ?>" <?php echo $checked; ?>>
                                <?php echo esc_html($type->name . ' (' . $type->slug . ')'); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e('Leave all unchecked to disable recording for this category.', 'll-tools-text-domain'); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div
            style="margin-top:10px; display:none; padding:10px 12px; border:1px solid #ccd0d4; border-radius:4px; background:#fff;"
            data-ll-target-preview
            hidden
        >
            <strong><?php esc_html_e('Upload Target', 'll-tools-text-domain'); ?>:</strong>
            <span data-ll-target-preview-category></span>
            <span data-ll-target-preview-separator> -> </span>
            <span data-ll-target-preview-wordsets></span>
        </div>

        <input type="hidden" name="action" value="process_audio_files">
        <?php if ($return_url !== '') : ?>
            <input type="hidden" name="ll_return_url" value="<?php echo esc_url($return_url); ?>">
        <?php endif; ?>
        <?php wp_nonce_field('ll_process_audio_files', 'll_audio_upload_nonce'); ?>
        <input type="submit" class="button button-primary ll-tools-upload-submit" value="<?php esc_attr_e( 'Bulk Add Audio', 'll-tools-text-domain' ); ?>">
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_upload_form', 'll_audio_upload_form_shortcode');

/**
 * Adds bulk audio uploading tool to the 'All Words' page in the admin dashboard.
 */
function ll_add_bulk_audio_upload_tool_admin_page() {
    $screen = get_current_screen();

    // Check if we're on the 'edit.php' page for the 'words' custom post type
    if ( isset($screen->id) && $screen->id === 'edit-words' ) {
        // Directly echo the output of the shortcode function
        echo '<h2>' . esc_html__('Bulk Audio Upload for Words', 'll-tools-text-domain') . '</h2>';
        echo ll_audio_upload_form_shortcode();
    }
}
add_action('admin_notices', 'll_add_bulk_audio_upload_tool_admin_page');

/**
 * Handles the processing of uploaded audio files.
 */
function ll_handle_audio_file_uploads() {
    // Security checks for the admin-post endpoint.
    if (!current_user_can('upload_files') || !current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to upload files.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_process_audio_files', 'll_audio_upload_nonce');

    $match_existing_posts = !empty($_POST['match_existing_posts']);
    $return_url = isset($_POST['ll_return_url']) ? wp_validate_redirect((string) wp_unslash($_POST['ll_return_url']), '') : '';
    $requested_wordset_ids = ll_audio_upload_get_requested_wordset_ids_from_request();
    $submitted_scope_ui = isset($_POST['ll_wordset_scope_mode']) || isset($_POST['ll_single_wordset_id']) || isset($_POST['ll_multi_wordset_ids']);
    if ($submitted_scope_ui && function_exists('ll_image_upload_get_accessible_wordsets') && !empty(ll_image_upload_get_accessible_wordsets()) && empty($requested_wordset_ids)) {
        wp_die(esc_html__('Please choose at least one word set for this upload.', 'll-tools-text-domain'));
    }

    if ($match_existing_posts && count($requested_wordset_ids) > 1) {
        wp_die(esc_html__('Please choose a single word set when matching existing words.', 'll-tools-text-domain'));
    }

    $selected_wordset_id = (count($requested_wordset_ids) === 1) ? (int) $requested_wordset_ids[0] : 0;
    foreach ($requested_wordset_ids as $requested_wordset_id) {
        if (
            (int) $requested_wordset_id > 0
            && function_exists('ll_tools_user_can_manage_wordset_content')
            && !ll_tools_user_can_manage_wordset_content((int) $requested_wordset_id, get_current_user_id())
        ) {
            wp_die(__('You do not have permission to assign uploads to that word set.', 'll-tools-text-domain'));
        }
    }

    $selected_categories = [];
    if (!$match_existing_posts) {
        $selected_categories = ll_audio_upload_get_selected_categories_from_request($requested_wordset_ids);
        if (is_wp_error($selected_categories)) {
            wp_die(esc_html($selected_categories->get_error_message()));
        }
    }

    $upload_dir      = wp_upload_dir();
    $success_matches = [];
    $failed_matches  = [];

    $allowed_audio_types  = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/webm', 'video/webm', 'video/x-matroska'];
    $max_file_size        = 10 * 1024 * 1024; // 10MB

    foreach ($_FILES['ll_audio_files']['tmp_name'] as $key => $tmp_name) {
        $original_name = $_FILES['ll_audio_files']['name'][$key];
        $file_size     = $_FILES['ll_audio_files']['size'][$key];

        $validation_result = ll_validate_uploaded_file($tmp_name, $original_name, $file_size, $allowed_audio_types, $max_file_size);
        if ($validation_result !== true) {
            $failed_matches[] = $validation_result;
            continue;
        }

        $upload_result = ll_upload_file($tmp_name, $original_name, $upload_dir['path']);
        if (is_wp_error($upload_result)) {
            $failed_matches[] = sprintf(
                /* translators: 1: Uploaded file name, 2: Upload error message. */
                __('%1$s (%2$s)', 'll-tools-text-domain'),
                $original_name,
                $upload_result->get_error_message()
            );
            continue;
        }

        $relative_upload_path = ll_get_relative_upload_path($upload_result);
        $formatted_title      = ll_format_title($original_name, $requested_wordset_ids);

        if ($match_existing_posts) {
            $existing_post = null;
            if ($selected_wordset_id > 0 && function_exists('ll_tools_find_existing_word_ids_by_title_in_wordset')) {
                $matching_ids = ll_tools_find_existing_word_ids_by_title_in_wordset($formatted_title, $selected_wordset_id);
                if (!empty($matching_ids)) {
                    $existing_post = get_post((int) $matching_ids[0]);
                    if ($existing_post && $existing_post->post_type !== 'words') {
                        $existing_post = null;
                    }
                }
            }
            if (
                !$existing_post
                && (
                    $selected_wordset_id <= 0
                    || !function_exists('ll_tools_is_wordset_isolation_enabled')
                    || !ll_tools_is_wordset_isolation_enabled()
                )
            ) {
                $existing_post = ll_find_post_by_exact_title($formatted_title);
            }
            if ($existing_post) {
                ll_update_existing_post_audio($existing_post->ID, $relative_upload_path, $_POST);
                $success_matches[] = sprintf(
                    /* translators: 1: Uploaded file name, 2: Existing post ID. */
                    __('%1$s -> Post ID: %2$d', 'll-tools-text-domain'),
                    $original_name,
                    (int) $existing_post->ID
                );
            } else {
                $failed_matches[] = sprintf(
                    /* translators: %s: Uploaded file name. */
                    __('%s (No matching post found)', 'll-tools-text-domain'),
                    $original_name
                );
            }
        } else {
            $post_id = ll_create_new_word_post($formatted_title, $relative_upload_path, $_POST, $selected_categories, $upload_dir);
            if ($post_id && !is_wp_error($post_id)) {
                $success_matches[] = sprintf(
                    /* translators: 1: Uploaded file name, 2: New post ID. */
                    __('%1$s -> New Post ID: %2$d', 'll-tools-text-domain'),
                    $original_name,
                    (int) $post_id
                );
            } else {
                $failed_matches[] = sprintf(
                    /* translators: %s: Uploaded file name. */
                    __('%s (Failed to create post)', 'll-tools-text-domain'),
                    $original_name
                );
            }
        }
    }

    if (is_string($return_url) && $return_url !== '') {
        $success_count = count($success_matches);
        $failed_count = count($failed_matches);
        $status = 'ok';
        if ($success_count === 0 && $failed_count > 0) {
            $status = 'error';
        } elseif ($success_count > 0 && $failed_count > 0) {
            $status = 'partial';
        }

        $redirect_back = add_query_arg([
            'll_wordset_audio_upload' => $status,
            'll_wordset_audio_upload_success' => $success_count,
            'll_wordset_audio_upload_failed' => $failed_count,
            'll_wordset_audio_upload_mode' => $match_existing_posts ? 'match' : 'create',
        ], $return_url);
        wp_safe_redirect($redirect_back);
        exit;
    }

    if (apply_filters('ll_aim_autolaunch_enabled', false)) {
        // If we succeeded on at least one file, try to jump straight into the matcher.
        // Pick the first selected category that has images AND unmatched words.
        $redirect_term_id = 0;
        if (!empty($success_matches) && !empty($selected_categories)) {
            foreach ($selected_categories as $maybe_tid) {
                $maybe_tid = intval($maybe_tid);

                // Check if this category has work to do
                if (function_exists('ll_aim_category_has_unmatched_work')) {
                    if (ll_aim_category_has_unmatched_work($maybe_tid)) {
                        $redirect_term_id = $maybe_tid;
                        break;
                    }
                }
            }
        }

        if ($redirect_term_id && is_user_logged_in()) {
            $key = 'll_aim_autolaunch_' . get_current_user_id();
            set_transient($key, intval($redirect_term_id), 120);

            $url = add_query_arg(
                ['page' => 'll-audio-image-matcher', 'term_id' => intval($redirect_term_id), 'autostart' => 1],
                admin_url('tools.php')
            );
            wp_safe_redirect($url);
            exit;
        }
    }

    // Fallback: show the summary like before if no redirect was possible
    ll_display_upload_results($success_matches, $failed_matches, $match_existing_posts);
    echo '<p><a href="' . esc_url(wp_get_referer()) . '">' . esc_html__('Go back to the previous page', 'll-tools-text-domain') . '</a></p>';
}
add_action('admin_post_process_audio_files', 'll_handle_audio_file_uploads');

/**
 * Validates an uploaded audio file.
 *
 * @param string $tmp_name Temporary file path.
 * @param string $original_name Original file name.
 * @param int    $file_size File size in bytes.
 * @param array  $allowed_types Allowed MIME types.
 * @param int    $max_size Maximum allowed file size in bytes.
 * @return true|string True if valid, otherwise error message.
 */
function ll_validate_uploaded_file($tmp_name, $original_name, $file_size, $allowed_types, $max_size) {
    // Check if the file type is allowed
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $tmp_name);
    finfo_close($finfo);
    if (!in_array($mime_type, $allowed_types)) {
        return sprintf(
            /* translators: 1: Uploaded file name, 2: MIME type. */
            __('%1$s (Invalid file type: %2$s)', 'll-tools-text-domain'),
            $original_name,
            esc_html($mime_type)
        );
    }

    // Check if the file size is within the allowed limit
    if ($file_size > $max_size) {
        return sprintf(
            /* translators: %s: Uploaded file name. */
            __('%s (File size exceeds the limit)', 'll-tools-text-domain'),
            $original_name
        );
    }

    // Perform additional audio file validation using getID3 library
    require_once LL_TOOLS_BASE_PATH . 'vendor/getid3/getid3.php';
    $getID3 = new getID3();
    $file_info = $getID3->analyze($tmp_name);
    if (!isset($file_info['audio'])) {
        return sprintf(
            /* translators: %s: Uploaded file name. */
            __('%s (Invalid audio file)', 'll-tools-text-domain'),
            $original_name
        );
    }

    return true;
}

/**
 * Moves the uploaded file to the uploads directory, handling duplicates.
 *
 * @param string $tmp_name Temporary file path.
 * @param string $original_name Original file name.
 * @param string $upload_path Upload directory path.
 * @return string|WP_Error Destination file path or WP_Error on failure.
 */
function ll_upload_file($tmp_name, $original_name, $upload_path) {
    $sanitized_name = sanitize_file_name(basename($original_name));
    $destination = trailingslashit($upload_path) . $sanitized_name;

    // Check if the file already exists and modify the file name if it does
    $counter = 0;
    $file_info = pathinfo($sanitized_name);
    $original_base_name = $file_info['filename'];
    $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
    while (file_exists($destination)) {
        $sanitized_name = $original_base_name . '_' . $counter . $extension;
        $destination = trailingslashit($upload_path) . $sanitized_name;
        $counter++;
    }

    if (move_uploaded_file($tmp_name, $destination)) {
        return $destination;
    } else {
        return new WP_Error('upload_error', __('Failed to move uploaded file.', 'll-tools-text-domain'));
    }
}

/**
 * Generates a relative upload path from the absolute path.
 *
 * @param string $absolute_path Absolute file path.
 * @return string Relative file path.
 */
function ll_get_relative_upload_path($absolute_path) {
    $upload_dir = wp_upload_dir();
    return str_replace(wp_normalize_path(untrailingslashit(ABSPATH)), '', wp_normalize_path($absolute_path));
}

/**
 * Cleans and formats the title from the original file name.
 * Strips out digits/underscores—but if that leaves under 4 chars,
 * it falls back to the full filename (so mostly-numeric names keep their numbers).
 *
 * @param string $original_name Original file name (with extension).
 * @param array  $wordset_ids Optional word set context for title-language casing rules.
 * @return string Formatted title for matching.
 */
function ll_format_title( $original_name, array $wordset_ids = [] ) {
    // 1) Get filename without its extension
    $filename = pathinfo( $original_name, PATHINFO_FILENAME );

    // 2) Attempt to strip underscores and digits
    $stripped = preg_replace( '/[_0-9]+/', '', $filename );
    $stripped = trim( $stripped );

    // 3) If stripping left us with fewer than 4 characters, keep the numbers
    if ( mb_strlen( $stripped, 'UTF-8' ) < 4 ) {
        $to_use = $filename;
    } else {
        $to_use = $stripped;
    }

    // 4) Normalize case (e.g. Turkish “I”) and sanitize
    return ll_normalize_case( sanitize_text_field( $to_use ), $wordset_ids );
}

/**
 * Creates a word_audio child for the existing word.
 *
 * @param int    $post_id        Parent words post ID.
 * @param string $relative_path  Relative path to the uploaded audio file.
 * @param array  $post_data      (Optional) $_POST from the form for speaker/type.
 */
function ll_update_existing_post_audio($post_id, $relative_path, $post_data = []) {
    $speaker_assignment = isset($post_data['ll_speaker_assignment']) ? $post_data['ll_speaker_assignment'] : 'current';
    $speaker_user_id = ll_audio_upload_resolve_speaker_user_id($speaker_assignment);

    // Recording type (default to isolation)
    $recording_type = isset($post_data['ll_recording_type'])
        ? sanitize_text_field($post_data['ll_recording_type'])
        : 'isolation';

    // Create the word_audio child post
    $audio_post_args = [
        'post_title'  => get_the_title($post_id),
        'post_type'   => 'word_audio',
        'post_status' => 'draft',
        'post_parent' => $post_id,
    ];
    if ($speaker_user_id > 0) {
        $audio_post_args['post_author'] = $speaker_user_id;
    }

    $audio_post_id = wp_insert_post($audio_post_args);
    if (is_wp_error($audio_post_id)) {
        error_log('Audio upload: failed to create word_audio post for word ' . $post_id);
        return;
    }

    // Store file + review flags on the word_audio child
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    if ($speaker_user_id > 0) {
        update_post_meta($audio_post_id, 'speaker_user_id', $speaker_user_id);
    }
    update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
    update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

}

/**
 * Creates a new word post with the provided details.
 *
 * @param string $title Formatted post title.
 * @param string $relative_path Relative path to the audio file.
 * @param array  $post_data POST data from the form.
 * @param array  $selected_categories Selected category IDs.
 * @param array  $upload_dir Upload directory details.
 * @return int|WP_Error New post ID or WP_Error on failure.
 */
function ll_create_new_word_post($title, $relative_path, $post_data, $selected_categories, $upload_dir) {
    $selected_categories = array_values(array_filter(array_map('intval', (array) $selected_categories), static function ($term_id) {
        return $term_id > 0;
    }));
    $wordset_ids = ll_audio_upload_get_requested_wordset_ids_from_post_data((array) $post_data);

    $post_id = wp_insert_post([
        'post_title'    => $title,
        'post_content'  => '',
        'post_status'   => 'draft',
        'post_type'     => 'words',
    ]);

    if ($post_id && !is_wp_error($post_id)) {
        $speaker_assignment = isset($post_data['ll_speaker_assignment']) ? $post_data['ll_speaker_assignment'] : 'current';
        $speaker_user_id = ll_audio_upload_resolve_speaker_user_id($speaker_assignment);

        // Get selected recording type
        $recording_type = isset($post_data['ll_recording_type']) ? sanitize_text_field($post_data['ll_recording_type']) : 'isolation';

        // Create word_audio post
        $audio_post_args = [
            'post_title' => $title,
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $post_id,
        ];

        // Only set post_author if speaker is assigned
        if ($speaker_user_id > 0) {
            $audio_post_args['post_author'] = $speaker_user_id;
        }

        $audio_post_id = wp_insert_post($audio_post_args);

        if (!is_wp_error($audio_post_id)) {
            update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
            update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
            update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');

            // Store speaker_user_id (can be null for unassigned)
            if ($speaker_user_id > 0) {
                update_post_meta($audio_post_id, 'speaker_user_id', $speaker_user_id);
            }

            // Assign recording type taxonomy
            wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
        }

        foreach ($wordset_ids as $wordset_id) {
            if (
                (int) $wordset_id > 0
                && function_exists('ll_tools_user_can_manage_wordset_content')
                && !ll_tools_user_can_manage_wordset_content((int) $wordset_id, get_current_user_id())
            ) {
                wp_die(__('You do not have permission to assign uploads to that word set.', 'll-tools-text-domain'));
            }
        }
        $primary_wordset_id = !empty($wordset_ids) ? (int) $wordset_ids[0] : 0;

        // 3) Assign taxonomy term for 'wordset' (authoritative for scoping)
        if (!empty($wordset_ids)) {
            wp_set_object_terms($post_id, $wordset_ids, 'wordset', false);
        }

        // (Optional) keep any existing meta for compatibility with your older code/UI
        if ($primary_wordset_id > 0 && count($wordset_ids) === 1) {
            update_post_meta($post_id, 'wordset', $primary_wordset_id);
        } else {
            delete_post_meta($post_id, 'wordset');
        }

        // 4) (Existing code) — translations, categories, part of speech, image matching, etc.
        if (!empty($wordset_ids) && !empty($selected_categories) && function_exists('ll_tools_get_isolated_category_ids_for_wordsets')) {
            $selected_categories = ll_tools_get_isolated_category_ids_for_wordsets($selected_categories, $wordset_ids);
        }

        // Assign selected categories to the post
        if (!empty($selected_categories)) {
            $selected_categories = array_map('intval', $selected_categories);
            wp_set_object_terms($post_id, $selected_categories, 'word-category', false);
        }

        // Assign the selected part of speech to the post
        if (isset($post_data['ll_part_of_speech']) && !empty($post_data['ll_part_of_speech'])) {
            $selected_part_of_speech = intval($post_data['ll_part_of_speech']);
            wp_set_object_terms($post_id, $selected_part_of_speech, 'part_of_speech', false);
        }

        // Determine which string to use for image matching (translated or original title)
        $image_search_string = $title;
        if ( isset($post_data['match_image_on_translation']) && $post_data['match_image_on_translation'] == 1 ) {
            $translated_value = get_post_meta($post_id, 'word_english_meaning', true);
            if (!empty($translated_value) && strpos($translated_value, 'Error translating') === false) {
                $image_search_string = $translated_value;
            }
        }

        // Try to find a relevant image and assign it as the featured image
        $matching_image = ll_find_matching_image_conservative($image_search_string, $selected_categories, $wordset_ids);
        if ($matching_image) {
            if ($primary_wordset_id > 0 && count($wordset_ids) === 1 && function_exists('ll_tools_get_effective_word_image_id_for_wordset')) {
                $effective_image_id = (int) ll_tools_get_effective_word_image_id_for_wordset((int) $matching_image->ID, $primary_wordset_id);
                if ($effective_image_id > 0) {
                    $maybe_effective = get_post($effective_image_id);
                    if ($maybe_effective instanceof WP_Post && $maybe_effective->post_type === 'word_images') {
                        $matching_image = $maybe_effective;
                    }
                }
            }
            $matching_image_attachment_id = get_post_thumbnail_id($matching_image->ID);
            if ($matching_image_attachment_id) {
                set_post_thumbnail($post_id, $matching_image_attachment_id);
                ll_mark_image_picked_for_word($matching_image->ID, $post_id);
            }
        }

        return $post_id;
    }

    return new WP_Error('ll_create_word_failed', __('Failed to create the post.', 'll-tools-text-domain'));
}

/**
 * Displays the upload results to the user.
 *
 * @param array  $success_matches Array of successful uploads.
 * @param array  $failed_matches Array of failed uploads.
 * @param bool   $match_existing_posts Whether matching existing posts was enabled.
 */
function ll_display_upload_results($success_matches, $failed_matches, $match_existing_posts) {
    echo '<h3>' . esc_html__('Upload Results:', 'll-tools-text-domain') . '</h3>';
    if (!empty($success_matches)) {
        if ($match_existing_posts) {
            echo '<h4>' . esc_html__('Updated Posts:', 'll-tools-text-domain') . '</h4>';
        } else {
            echo '<h4>' . esc_html__('Created Posts:', 'll-tools-text-domain') . '</h4>';
        }
        echo '<ul>';
        foreach ($success_matches as $match) {
            echo '<li>' . esc_html($match) . '</li>';
        }
        echo '</ul>';
    }

    if (!empty($failed_matches)) {
        if ($match_existing_posts) {
            echo '<h4>' . esc_html__('Failed Updates:', 'll-tools-text-domain') . '</h4>';
        } else {
            echo '<h4>' . esc_html__('Failed Creations:', 'll-tools-text-domain') . '</h4>';
        }
        echo '<ul>';
        foreach ($failed_matches as $match) {
            echo '<li>' . esc_html($match) . '</li>';
        }
        echo '</ul>';
    }
}

/* ==== Conservative auto-match helpers + picker bookkeeping ================== */

if (!function_exists('ll_sim_normalize')) {
    /** Lowercase, trim, replace separators with spaces, collapse whitespace */
    function ll_sim_normalize($s) {
        $s = strtolower( wp_strip_all_tags( (string)$s ) );
        // Treat dot/underscore/dash as separators
        $s = preg_replace('/[._\-]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('ll_sim_tokens')) {
    /** Tokenize to unique alnum tokens (len >= 2) */
    function ll_sim_tokens($s) {
        $s = ll_sim_normalize($s);
        preg_match_all('/[[:alnum:]]{2,}/u', $s, $m);
        return array_values(array_unique($m[0] ?? []));
    }
}

if (!function_exists('ll_sim_jaccard')) {
    /** Jaccard similarity between token sets */
    function ll_sim_jaccard(array $a, array $b) {
        if (!$a || !$b) return 0.0;
        $a = array_unique($a); $b = array_unique($b);
        $inter = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));
        return count($union) ? (count($inter) / count($union)) : 0.0;
    }
}

if (!function_exists('ll_sim_percent')) {
    /** similar_text percentage wrapper on normalized strings */
    function ll_sim_percent($a, $b) {
        similar_text(ll_sim_normalize($a), ll_sim_normalize($b), $pct);
        return (float)$pct;
    }
}

if (!function_exists('ll_is_confident_match')) {
    /**
     * Decide if two names are "confidently" the same thing:
     * - whole-word containment (either direction), OR
     * - token Jaccard >= 0.60, OR
     * - similar_text >= 85%
     * - for very short strings (<=3), require exact equality
     */
    function ll_is_confident_match($a, $b) {
        $a = ll_sim_normalize($a);
        $b = ll_sim_normalize($b);
        if ($a === '' || $b === '') return false;

        if (mb_strlen($a, 'UTF-8') <= 3 || mb_strlen($b, 'UTF-8') <= 3) {
            return $a === $b;
        }

        // whole-word containment
        $pa = '/\b' . preg_quote($a, '/') . '\b/u';
        $pb = '/\b' . preg_quote($b, '/') . '\b/u';
        if (preg_match($pa, $b) || preg_match($pb, $a)) return true;

        // token overlap
        $jac = ll_sim_jaccard(ll_sim_tokens($a), ll_sim_tokens($b));
        if ($jac >= 0.60) return true;

        // character similarity
        $pct = ll_sim_percent($a, $b);
        if ($pct >= 85.0) return true;

        return false;
    }
}

if (!function_exists('ll_find_matching_image_conservative')) {
    /**
     * Conservative image finder scoped to the given categories.
     * Returns a WP_Post (word_images) only if the final confidence gate passes.
     */
    function ll_find_matching_image_conservative($audio_like_name, $categories, $wordset_ids = []) {
        $audio_norm = ll_sim_normalize($audio_like_name);
        if ($audio_norm === '') return null;

        $categories = array_values(array_filter(array_map('intval', (array) $categories), static function ($term_id) {
            return $term_id > 0;
        }));
        $wordset_ids = array_values(array_filter(array_map('intval', (array) $wordset_ids), static function ($term_id) {
            return $term_id > 0;
        }));
        if (!empty($wordset_ids) && function_exists('ll_tools_get_isolated_category_ids_for_wordsets')) {
            $categories = ll_tools_get_isolated_category_ids_for_wordsets($categories, $wordset_ids);
        }
        if (empty($categories)) return null;

        $query_args = [
            'post_type'      => 'word_images',
            'posts_per_page' => -1,
            'tax_query'      => [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => $categories,
            ]],
            'orderby' => 'title',
            'order'   => 'ASC',
        ];
        if (!empty($wordset_ids) && function_exists('ll_tools_get_word_image_owner_meta_query')) {
            $meta_query = ll_tools_get_word_image_owner_meta_query($wordset_ids, true);
            if (!empty($meta_query)) {
                $query_args['meta_query'] = $meta_query;
            }
        }

        $image_posts = get_posts($query_args);
        if (empty($image_posts)) return null;

        $best   = null;
        $bestSc = -1.0;

        foreach ($image_posts as $img) {
            // drop trailing _0, _1 added during bulk imports
            $clean = preg_replace('/_\d+$/', '', (string)$img->post_title);

            // composite ranking (we still gate with ll_is_confident_match at the end)
            $pct  = ll_sim_percent($audio_norm, $clean); // 0..100
            $jac  = ll_sim_jaccard(ll_sim_tokens($audio_norm), ll_sim_tokens($clean)); // 0..1
            $cont = ll_is_confident_match($audio_norm, $clean) ? 1 : 0;

            $score = ($pct / 100.0) * 0.6 + $jac * 0.3 + $cont * 0.1;
            if ($score > $bestSc) { $bestSc = $score; $best = $img; }
        }

        if (!$best) return null;

        $clean_best = preg_replace('/_\d+$/', '', (string)$best->post_title);
        return ll_is_confident_match($audio_norm, $clean_best) ? $best : null;
    }
}

if (!function_exists('ll_mark_image_picked_for_word')) {
    /**
     * Bookkeeping so the matcher UI can show "picked" badges.
     * - bumps _ll_picked_count on the image CPT
     * - sets _ll_picked_last on the image
     * - records _ll_autopicked_image_id on the word (for reference)
     */
    function ll_mark_image_picked_for_word($image_post_id, $word_post_id) {
        $count = (int) get_post_meta($image_post_id, '_ll_picked_count', true);
        update_post_meta($image_post_id, '_ll_picked_count', $count + 1);
        update_post_meta($image_post_id, '_ll_picked_last', time());
        update_post_meta($word_post_id, '_ll_autopicked_image_id', (int)$image_post_id);
    }
}
/* ==== /helpers =============================================================== */

?>
