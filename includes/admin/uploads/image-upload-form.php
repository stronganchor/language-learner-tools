<?php
/************************************************************************************
 * [image_upload_form] Shortcode - Bulk upload image files & generate new word image posts
 ***********************************************************************************/

function ll_image_upload_user_can_access() {
    return current_user_can('upload_files');
}

function ll_image_upload_user_can_access_admin_tool() {
    return ll_image_upload_user_can_access() && current_user_can('view_ll_tools');
}

/**
 * Validate an uploaded image using server-side checks.
 *
 * @param string $tmp_name Temporary uploaded file path.
 * @param string $original_name Original client filename.
 * @param int    $file_error PHP upload error code.
 * @param array  $allowed_image_types Allowed image mime types.
 * @param array  $allowed_image_extensions Allowed image extensions.
 * @param bool   $require_uploaded_file When true, require is_uploaded_file() to pass.
 * @return array{valid:bool,error:string,mime:string,ext:string,safe_name:string}
 */
function ll_image_upload_validate_uploaded_image(
    $tmp_name,
    $original_name,
    $file_error,
    array $allowed_image_types,
    array $allowed_image_extensions,
    $require_uploaded_file = true
) {
    $result = [
        'valid' => false,
        'error' => '',
        'mime' => '',
        'ext' => '',
        'safe_name' => '',
    ];

    if ((int) $file_error !== UPLOAD_ERR_OK) {
        $result['error'] = 'File upload error';
        return $result;
    }
    if ($require_uploaded_file && !is_uploaded_file((string) $tmp_name)) {
        $result['error'] = 'File upload error';
        return $result;
    }

    $ft = wp_check_filetype_and_ext((string) $tmp_name, (string) $original_name);
    $validated_mime = isset($ft['type']) ? (string) $ft['type'] : '';
    $validated_ext = isset($ft['ext']) ? strtolower((string) $ft['ext']) : '';

    if ($validated_mime === '' || $validated_ext === ''
        || !in_array($validated_mime, $allowed_image_types, true)
        || !in_array($validated_ext, $allowed_image_extensions, true)) {
        $result['error'] = 'Invalid image type';
        return $result;
    }

    $image_size = @getimagesize((string) $tmp_name);
    if ($image_size === false) {
        $result['error'] = 'Invalid image data';
        return $result;
    }

    $normalized_original = !empty($ft['proper_filename']) ? (string) $ft['proper_filename'] : (string) $original_name;
    $base_without_ext = pathinfo($normalized_original, PATHINFO_FILENAME);
    if ($base_without_ext === '') {
        $base_without_ext = 'image';
    }
    $safe_file_name = sanitize_file_name($base_without_ext . '.' . $validated_ext);
    if ($safe_file_name === '') {
        $safe_file_name = 'image.' . $validated_ext;
    }

    $result['valid'] = true;
    $result['mime'] = $validated_mime;
    $result['ext'] = $validated_ext;
    $result['safe_name'] = $safe_file_name;

    return $result;
}

function ll_image_upload_enqueue_form_assets() {
    ll_enqueue_asset_by_timestamp('/js/image-upload-form-admin.js', 'll-image-upload-form-admin', ['jquery'], true);

    $warning_threshold_bytes = (int) apply_filters('ll_image_upload_large_file_warning_bytes', 500 * 1024);
    if ($warning_threshold_bytes < 1) {
        $warning_threshold_bytes = 500 * 1024;
    }

    $warning_threshold_label = size_format($warning_threshold_bytes, 1);

    wp_localize_script('ll-image-upload-form-admin', 'llImageUploadFormData', [
        'autoCreateCategoryIds'   => ll_image_upload_get_autocreate_category_ids(),
        'largeImageWarningBytes'  => $warning_threshold_bytes,
        'largeImageWarningMessage' => sprintf(
            __('Some selected images are larger than %s. They may fail to upload or load slowly in quizzes.', 'll-tools-text-domain'),
            $warning_threshold_label
        ),
        'largeImageWarningFilesLabel' => __('Large files:', 'll-tools-text-domain'),
    ]);
}

/**
 * Category IDs that support image-upload word auto-creation.
 *
 * @return array
 */
function ll_image_upload_get_autocreate_category_ids() {
    $term_ids = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return [];
    }

    $eligible = [];
    foreach ((array) $term_ids as $term_id) {
        $term_id = (int) $term_id;
        if ($term_id > 0 && ll_image_upload_category_supports_autocreate($term_id)) {
            $eligible[] = $term_id;
        }
    }

    return array_values(array_unique($eligible));
}

/**
 * Render hierarchical category options for a <select>.
 *
 * @param array $terms       WP_Term list.
 * @param int   $selected_id Selected term ID.
 * @param int   $parent_id   Current parent ID.
 * @param int   $depth       Current depth level.
 */
function ll_image_upload_render_parent_category_options($terms, $selected_id = 0, $parent_id = 0, $depth = 0) {
    $children = array_filter((array) $terms, function ($term) use ($parent_id) {
        return isset($term->parent) && (int) $term->parent === (int) $parent_id;
    });
    if (empty($children)) {
        return;
    }

    usort($children, function ($a, $b) {
        return strnatcasecmp((string) $a->name, (string) $b->name);
    });

    foreach ($children as $term) {
        printf(
            '<option value="%1$d" %2$s>%3$s%4$s</option>',
            (int) $term->term_id,
            selected((int) $selected_id, (int) $term->term_id, false),
            esc_html(str_repeat('— ', $depth)),
            esc_html($term->name)
        );
        ll_image_upload_render_parent_category_options($terms, $selected_id, (int) $term->term_id, $depth + 1);
    }
}

/**
 * Shortcode handler for [image_upload_form].
 *
 * @return string The HTML form for uploading image files.
 */
function ll_image_upload_form_shortcode() {
    if (!ll_image_upload_user_can_access()) {
        return esc_html__('You do not have permission to upload files.', 'll-tools-text-domain');
    }

    ll_image_upload_enqueue_form_assets();

    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }

    $category_terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($category_terms)) {
        $category_terms = [];
    }

    $recording_types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($recording_types)) {
        $recording_types = [];
    }

    $default_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];

    $can_create_categories = current_user_can('manage_categories');
    $show_translation_field = function_exists('ll_tools_is_category_translation_enabled')
        && ll_tools_is_category_translation_enabled();

    ob_start();
    ?>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data" data-ll-image-upload-form="1">
        <?php wp_nonce_field('ll_process_image_files', 'll_image_upload_nonce'); ?>

        <!-- only allow image files -->
        <input type="file" name="ll_image_files[]" accept="image/*" multiple data-ll-image-file-input /><br>

        <div
            class="ll-tools-image-upload-warning"
            data-ll-image-size-warning
            role="status"
            aria-live="polite"
            hidden
            style="display:none; margin-top:8px; max-width:760px; border:1px solid #d63638; background:#fff5f5; color:#8a1f11; border-radius:4px; padding:10px 12px; font-size:13px; line-height:1.4;"
        >
            <strong class="ll-tools-image-upload-warning__message" data-ll-image-size-warning-message></strong>
            <div class="ll-tools-image-upload-warning__files" data-ll-image-size-warning-files style="margin-top:4px;"></div>
        </div>

        <div style="margin-top:10px;" data-ll-category-existing-wrap>
            <label><?php esc_html_e('Select Categories', 'll-tools-text-domain'); ?>:</label><br>
            <?php ll_render_category_selection_field('word_images'); ?>
        </div>

        <?php if ($can_create_categories) : ?>
            <div style="margin-top:10px;">
                <label for="ll-new-category-title"><?php esc_html_e('Create New Category Instead (enter title)', 'll-tools-text-domain'); ?>:</label><br>
                <input type="text" id="ll-new-category-title" name="ll_new_category_title" class="regular-text" value="" data-ll-new-category-title>
                <p class="description"><?php esc_html_e('Optional. Enter a title to create a new category instead of using the selected categories above. Start typing to reveal additional category options.', 'll-tools-text-domain'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($can_create_categories) : ?>
            <div style="margin-top:10px; display:none;" data-ll-new-category-advanced>
                <label for="ll-new-category-parent"><?php esc_html_e('Parent Category', 'll-tools-text-domain'); ?>:</label><br>
                <select id="ll-new-category-parent" name="ll_new_category_parent" class="regular-text">
                    <option value="0"><?php esc_html_e('None', 'll-tools-text-domain'); ?></option>
                    <?php ll_image_upload_render_parent_category_options($category_terms); ?>
                </select>

                <?php if ($show_translation_field) : ?>
                    <div style="margin-top:10px;">
                        <label for="ll-new-category-translation"><?php esc_html_e('Translated Name', 'll-tools-text-domain'); ?>:</label><br>
                        <input type="text" id="ll-new-category-translation" name="ll_new_category_translation" class="regular-text" value="">
                    </div>
                <?php endif; ?>

                <div style="margin-top:10px;">
                    <label for="ll-new-category-prompt"><?php esc_html_e('Quiz Prompt Type', 'll-tools-text-domain'); ?>:</label><br>
                    <select id="ll-new-category-prompt" name="ll_new_category_prompt_type" class="regular-text" data-ll-new-category-prompt>
                        <option value="audio"><?php esc_html_e('Play audio (default)', 'll-tools-text-domain'); ?></option>
                        <option value="image"><?php esc_html_e('Show image', 'll-tools-text-domain'); ?></option>
                        <option value="text_translation"><?php esc_html_e('Show text (translation)', 'll-tools-text-domain'); ?></option>
                        <option value="text_title"><?php esc_html_e('Show text (title)', 'll-tools-text-domain'); ?></option>
                    </select>
                </div>

                <div style="margin-top:10px;">
                    <label for="ll-new-category-option"><?php esc_html_e('Answer Options', 'll-tools-text-domain'); ?>:</label><br>
                    <select id="ll-new-category-option" name="ll_new_category_option_type" class="regular-text" data-ll-new-category-option>
                        <option value="image"><?php esc_html_e('Images', 'll-tools-text-domain'); ?></option>
                        <option value="text"><?php esc_html_e('Text (match prompt)', 'll-tools-text-domain'); ?></option>
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

        <div style="margin-top:10px; display:none;" data-ll-wordset-wrap>
            <label><?php esc_html_e('Generate word posts for these word sets (applies only when the category quizzes without audio):', 'll-tools-text-domain'); ?></label><br>
            <?php if (!empty($wordsets) && !is_wp_error($wordsets)) : ?>
                <select name="ll_wordset_ids[]" multiple size="5" style="min-width:240px;">
                    <?php foreach ($wordsets as $ws) : ?>
                        <option value="<?php echo esc_attr($ws->term_id); ?>"><?php echo esc_html($ws->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('If left empty, generated word posts will not be assigned to a word set.', 'll-tools-text-domain'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('No word sets available.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </div>

        <input type="hidden" name="action" value="process_image_files">
        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Bulk Add Images', 'll-tools-text-domain'); ?>">
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('image_upload_form', 'll_image_upload_form_shortcode');

/**
 * Adds bulk image uploading tool to the 'All Word Images' page in the admin dashboard.
 */
function ll_add_bulk_image_upload_tool_admin_page() {
    $screen = get_current_screen();

    // Check if we're on the 'edit.php' page for the 'word_images' custom post type
    if (isset($screen->id) && $screen->id === 'edit-word_images' && ll_image_upload_user_can_access_admin_tool()) {
        // Directly echo the output of the shortcode function
        echo '<h2>' . esc_html__('Bulk Image Upload for Word Images', 'll-tools-text-domain') . '</h2>';
        echo ll_image_upload_form_shortcode();
    }
}
add_action('admin_notices', 'll_add_bulk_image_upload_tool_admin_page');

/**
 * Handles the processing of uploaded image files.
 */
function ll_handle_image_file_uploads() {
    // Security check: Ensure the current user can access this tool
    if (!ll_image_upload_user_can_access_admin_tool()) {
        wp_die(esc_html__('You do not have permission to upload files.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_process_image_files', 'll_image_upload_nonce');

    /**
     * Extracts copyright information from EXIF and from any XMP packet data.
     *
     * @param string $file_path The path to the uploaded file on the server.
     * @return string The combined copyright information.
     */
    function ll_extract_copyright_info($file_path) {
        $extracted = '';
        $exif_data = @exif_read_data($file_path, 'ANY_TAG', true);
        if (!empty($exif_data['IFD0']['Copyright'])) {
            $extracted = trim($exif_data['IFD0']['Copyright']);
        }
        $contents = @file_get_contents($file_path);
        if ($contents !== false) {
            $xmp_start = strpos($contents, '<?xpacket begin=');
            if ($xmp_start !== false) {
                $xmp_end = strpos($contents, '<?xpacket end="', $xmp_start);
                if ($xmp_end !== false) {
                    $xmp_data = substr($contents, $xmp_start, $xmp_end - $xmp_start);
                    if (preg_match('/<dc:rights>.*?<rdf:li[^>]*>(.*?)<\/rdf:li>.*?<\/dc:rights>/s', $xmp_data, $m)) {
                        $in_xmp = trim($m[1]);
                        if ($in_xmp !== '') {
                            $extracted = $extracted ? $extracted . ' | ' . $in_xmp : $in_xmp;
                        }
                    }
                }
            }
        }
        return $extracted;
    }

    // Prepare for file upload handling
    $new_category_title = isset($_POST['ll_new_category_title'])
        ? sanitize_text_field(wp_unslash($_POST['ll_new_category_title']))
        : '';
    $selected_categories = [];
    if ($new_category_title !== '') {
        $created_category_id = ll_image_upload_create_category_from_request();
        if (is_wp_error($created_category_id)) {
            wp_die(esc_html($created_category_id->get_error_message()));
        }
        if ((int) $created_category_id > 0) {
            $selected_categories = [(int) $created_category_id];
        }
    } else {
        $selected_categories = isset($_POST['ll_word_categories']) ? (array) wp_unslash($_POST['ll_word_categories']) : [];
    }
    $selected_categories = array_values(array_filter(array_map('intval', (array) $selected_categories), function ($id) {
        return $id > 0;
    }));

    $upload_dir = wp_upload_dir();
    $success_uploads = [];
    $failed_uploads = [];
    $should_autocreate_words = ll_image_upload_should_autocreate_word($selected_categories);
    $selected_wordsets = $should_autocreate_words
        ? ll_image_upload_sanitize_wordset_ids(isset($_POST['ll_wordset_ids']) ? wp_unslash($_POST['ll_wordset_ids']) : [])
        : [];
    $was_audio_requirement_skipped = false;
    if ($should_autocreate_words) {
        $GLOBALS['ll_image_upload_skip_audio_requirement'] = true;
        $was_audio_requirement_skipped = true;
    }

    $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_image_extensions = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'];

    foreach ($_FILES['ll_image_files']['tmp_name'] as $key => $tmp_name) {
        $original_name = $_FILES['ll_image_files']['name'][$key];
        $file_error = $_FILES['ll_image_files']['error'][$key];

        $validation = ll_image_upload_validate_uploaded_image(
            (string) $tmp_name,
            (string) $original_name,
            (int) $file_error,
            $allowed_image_types,
            $allowed_image_extensions
        );
        if (empty($validation['valid'])) {
            $message = isset($validation['error']) ? (string) $validation['error'] : 'Invalid image file';
            $failed_uploads[] = $original_name . ' (' . $message . ')';
            continue;
        }

        // Sanitize and prepare a unique filename using the validated extension.
        $safe_file_name = (string) ($validation['safe_name'] ?? '');
        $validated_mime = (string) ($validation['mime'] ?? '');
        $file_name = wp_unique_filename($upload_dir['path'], $safe_file_name);
        $upload_path = trailingslashit($upload_dir['path']) . $file_name;

        if (move_uploaded_file($tmp_name, $upload_path)) {
            // Preserve original (Unicode) base name for titles
            $original_title = pathinfo($original_name, PATHINFO_FILENAME);
            $attachment_id = wp_insert_attachment([
                'guid' => trailingslashit($upload_dir['baseurl']) . $file_name,
                'post_mime_type' => $validated_mime,
                'post_title' => $original_title,
                'post_content' => '',
                'post_status' => 'inherit'
            ], $upload_path);

            if ($attachment_id && !is_wp_error($attachment_id)) {
                if (function_exists('ll_tools_maybe_regenerate_attachment_metadata')) {
                    ll_tools_maybe_regenerate_attachment_metadata((int) $attachment_id);
                } else {
                    if (!function_exists('wp_generate_attachment_metadata')) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                    }
                    $metadata = wp_generate_attachment_metadata((int) $attachment_id, $upload_path);
                    if (is_array($metadata) && !empty($metadata)) {
                        wp_update_attachment_metadata((int) $attachment_id, $metadata);
                    }
                }

                $post_id = wp_insert_post([
                    'post_title' => $original_title,
                    'post_content' => '',
                    'post_status' => 'publish',
                    'post_type' => 'word_images',
                ]);

                if ($post_id && !is_wp_error($post_id)) {
                    // Extract and store any discovered copyright info
                    $copyright_data = ll_extract_copyright_info($upload_path);
                    if (!empty($copyright_data)) {
                        update_post_meta(
                            $post_id,
                            'copyright_info',
                            sanitize_text_field($copyright_data)
                        );
                    }

                    set_post_thumbnail($post_id, $attachment_id);

                    // Assign selected categories to the post
                    if (!empty($selected_categories)) {
                        $selected_categories = array_map('intval', $selected_categories);
                        wp_set_object_terms($post_id, $selected_categories, 'word-category', false);
                    }

                    // Auto-create a words post when the category quizzes with image->text (no audio required)
                    $word_note = '';
                    if ($should_autocreate_words) {
                        $word_result = ll_maybe_create_word_from_image_upload($original_title, $attachment_id, $selected_categories, $post_id, $selected_wordsets);
                        if (is_wp_error($word_result)) {
                            $word_note = ' (Word creation failed: ' . $word_result->get_error_message() . ')';
                        } elseif (!empty($word_result)) {
                            $word_note = ' (Word ID: ' . $word_result . ')';
                        }
                    }

                    $success_uploads[] = $original_name . ' -> New Post ID: ' . $post_id . $word_note;
                } else {
                    $failed_uploads[] = $original_name . ' (Failed to create post)';
                }
            } else {
                $failed_uploads[] = $original_name . ' (Failed to create attachment)';
            }
        } else {
            $failed_uploads[] = $original_name . ' (Failed to move file)';
        }
    }

    if ($was_audio_requirement_skipped) {
        unset($GLOBALS['ll_image_upload_skip_audio_requirement']);
    }

    // Redirect with success and failure messages
    $redirect_url = add_query_arg([
        'post_type' => 'word_images',
        'success_uploads' => implode(',', $success_uploads),
        'failed_uploads' => implode(',', $failed_uploads),
    ], admin_url('edit.php'));

    wp_redirect($redirect_url);
    exit;
}
add_action('admin_post_process_image_files', 'll_handle_image_file_uploads');

/**
 * Create a new word category from uploader form input.
 *
 * @return int|WP_Error New or existing term ID, or WP_Error when validation fails.
 */
function ll_image_upload_create_category_from_request() {
    if (!current_user_can('manage_categories')) {
        return new WP_Error('ll_image_upload_category_permission', __('You do not have permission to create categories.', 'll-tools-text-domain'));
    }

    $title = isset($_POST['ll_new_category_title'])
        ? sanitize_text_field(wp_unslash($_POST['ll_new_category_title']))
        : '';
    if ($title === '') {
        return new WP_Error('ll_image_upload_category_title_required', __('Please enter a title for the new category.', 'll-tools-text-domain'));
    }

    $parent_id = isset($_POST['ll_new_category_parent']) ? (int) wp_unslash($_POST['ll_new_category_parent']) : 0;
    if ($parent_id > 0) {
        $parent_term = get_term($parent_id, 'word-category');
        if (!$parent_term || is_wp_error($parent_term)) {
            $parent_id = 0;
        }
    }

    $insert_args = [];
    if ($parent_id > 0) {
        $insert_args['parent'] = $parent_id;
    }

    $insert = wp_insert_term($title, 'word-category', $insert_args);
    if (is_wp_error($insert)) {
        if ($insert->get_error_code() === 'term_exists') {
            $existing_id = (int) $insert->get_error_data('term_exists');
            if ($existing_id > 0) {
                return $existing_id;
            }
        }
        $reason = $insert->get_error_message();
        return new WP_Error(
            'll_image_upload_category_create_failed',
            sprintf(
                __('Category "%1$s" could not be created: %2$s', 'll-tools-text-domain'),
                $title,
                $reason
            )
        );
    }
    if (empty($insert['term_id'])) {
        return new WP_Error(
            'll_image_upload_category_create_failed',
            __('Category could not be created.', 'll-tools-text-domain')
        );
    }

    $term_id = (int) $insert['term_id'];

    if (function_exists('ll_tools_is_category_translation_enabled') && ll_tools_is_category_translation_enabled()) {
        $translation = isset($_POST['ll_new_category_translation'])
            ? sanitize_text_field(wp_unslash($_POST['ll_new_category_translation']))
            : '';
        if ($translation !== '') {
            update_term_meta($term_id, 'term_translation', $translation);
        }
    }

    $prompt_raw = isset($_POST['ll_new_category_prompt_type'])
        ? sanitize_text_field(wp_unslash($_POST['ll_new_category_prompt_type']))
        : 'audio';
    $prompt = function_exists('ll_tools_normalize_quiz_prompt_type')
        ? ll_tools_normalize_quiz_prompt_type($prompt_raw)
        : (
            in_array($prompt_raw, ['audio', 'image', 'text_translation', 'text_title'], true)
                ? $prompt_raw
                : 'audio'
        );

    $option_raw = isset($_POST['ll_new_category_option_type'])
        ? sanitize_text_field(wp_unslash($_POST['ll_new_category_option_type']))
        : 'image';
    if (function_exists('ll_tools_normalize_quiz_option_type')) {
        $option = ll_tools_normalize_quiz_option_type($option_raw, false, $prompt);
    } else {
        $allowed_options = ['image', 'text', 'text_translation', 'text_title', 'audio', 'text_audio'];
        $option = in_array($option_raw, $allowed_options, true) ? $option_raw : 'image';
        if ($option === 'text') {
            $option = ($prompt === 'text_title') ? 'text_title' : 'text_translation';
        }
    }

    // Keep invalid combinations aligned with the category add/edit screen behavior.
    if ($prompt === 'image' && $option === 'image') {
        $option = 'text_translation';
    }
    if ($prompt === 'audio' && $option === 'audio') {
        $option = 'text_translation';
    }

    update_term_meta($term_id, 'll_quiz_prompt_type', $prompt);
    update_term_meta($term_id, 'll_quiz_option_type', $option);
    if ($option === 'text_title') {
        update_term_meta($term_id, 'use_word_titles_for_audio', '1');
    } else {
        delete_term_meta($term_id, 'use_word_titles_for_audio');
    }

    $default_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $default_types = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $default_types))));

    if (isset($_POST['ll_new_category_desired_recording_types_submitted'])) {
        $incoming = isset($_POST['ll_new_category_desired_recording_types'])
            ? (array) wp_unslash($_POST['ll_new_category_desired_recording_types'])
            : [];
        $incoming = array_values(array_unique(array_filter(array_map('sanitize_text_field', $incoming))));

        $allowed_types = get_terms([
            'taxonomy'   => 'recording_type',
            'hide_empty' => false,
            'fields'     => 'slugs',
        ]);
        if (is_wp_error($allowed_types)) {
            $allowed_types = [];
        }
        $allowed_types = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $allowed_types))));

        $selected_types = !empty($allowed_types)
            ? array_values(array_intersect($incoming, $allowed_types))
            : $incoming;

        if (empty($selected_types)) {
            $disabled_sentinel = defined('LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED')
                ? LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED
                : '__none__';
            update_term_meta($term_id, 'll_desired_recording_types', [$disabled_sentinel]);
        } else {
            update_term_meta($term_id, 'll_desired_recording_types', $selected_types);
        }
    } elseif (!empty($default_types)) {
        update_term_meta($term_id, 'll_desired_recording_types', $default_types);
    }

    return $term_id;
}

/**
 * Check if a single category supports auto-created words for image uploads.
 *
 * @param int $term_id Category term ID.
 * @return bool
 */
function ll_image_upload_category_supports_autocreate($term_id) {
    $term_id = (int) $term_id;
    if (
        $term_id <= 0
        || !function_exists('ll_tools_get_category_quiz_config')
        || !function_exists('ll_tools_quiz_requires_audio')
    ) {
        return false;
    }

    $cfg = ll_tools_get_category_quiz_config($term_id);
    $requires_audio = ll_tools_quiz_requires_audio($cfg, isset($cfg['option_type']) ? (string) $cfg['option_type'] : '');
    $prompt_type = isset($cfg['prompt_type']) ? (string) $cfg['prompt_type'] : '';
    $option_type = isset($cfg['option_type']) ? (string) $cfg['option_type'] : '';
    $prompt_is_image = ($prompt_type === 'image');
    $prompt_is_text = in_array($prompt_type, ['text_translation', 'text_title'], true);
    $answer_is_image = ($option_type === 'image');
    $answer_is_text = in_array($option_type, ['text_translation', 'text_title'], true);

    return !$requires_audio && (
        ($prompt_is_image && $answer_is_text)
        || ($prompt_is_text && $answer_is_image)
    );
}

/**
 * Determine if image uploads should auto-create word posts based on category quiz config.
 *
 * @param array $category_ids Selected category IDs from the upload form.
 * @return bool True when any selected category quizzes without audio and mixes text/image between prompt and options.
 */
function ll_image_upload_should_autocreate_word($category_ids) {
    if (empty($category_ids)) {
        return false;
    }

    foreach ((array) $category_ids as $tid) {
        if (ll_image_upload_category_supports_autocreate((int) $tid)) {
            return true;
        }
    }

    return false;
}

/**
 * Sanitize incoming wordset IDs from the upload form.
 *
 * @param array $raw_ids Raw IDs from $_POST.
 * @return array Sanitized integer IDs.
 */
function ll_image_upload_sanitize_wordset_ids($raw_ids) {
    $ids = array_map('intval', (array) $raw_ids);
    $ids = array_filter($ids, function ($id) { return $id > 0; });
    return array_values(array_unique($ids));
}

/**
 * Find an existing word post that exactly matches the provided title (any status).
 *
 * @param string $title Candidate post title.
 * @return WP_Post|null Matched post or null if not found.
 */
function ll_image_upload_find_existing_word($title) {
    $query = new WP_Query([
        'post_type'      => 'words',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
        'title'          => sanitize_text_field($title),
        'exact'          => true,
        'no_found_rows'  => true,
    ]);

    $match = null;
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post = get_post();
            $titles_match = $post && (
                (function_exists('ll_strcmp') && ll_strcmp($post->post_title, $title))
                || (!function_exists('ll_strcmp') && strcmp($post->post_title, $title) === 0)
            );
            if ($titles_match) {
                $match = $post;
                break;
            }
        }
        wp_reset_postdata();
    }

    return $match;
}

/**
 * Create a word post for an uploaded image when no matching word exists.
 *
 * @param string $title               Title derived from the image filename.
 * @param int    $attachment_id       Attachment ID for the uploaded image file.
 * @param array  $category_ids        Category IDs selected during upload.
 * @param int    $word_image_post_id  The word_images post ID for bookkeeping.
 * @param array  $wordset_ids         Word set IDs to assign to generated word posts.
 * @return int|WP_Error Word ID on success, 0 when skipped, or WP_Error on failure.
 */
function ll_maybe_create_word_from_image_upload($title, $attachment_id, $category_ids, $word_image_post_id = 0, $wordset_ids = []) {
    $normalized_title = sanitize_text_field(wp_strip_all_tags((string) $title));
    if ($normalized_title === '') {
        return 0;
    }

    $existing = ll_image_upload_find_existing_word($normalized_title);
    if ($existing instanceof WP_Post) {
        return 0;
    }

    $category_ids = array_filter(array_map('intval', (array) $category_ids), function ($id) {
        return $id > 0;
    });
    $wordset_ids = array_filter(array_map('intval', (array) $wordset_ids), function ($id) {
        return $id > 0;
    });

    // Insert with tax_input + a one-time skip flag so publish validation doesn't fire before terms exist.
    $word_id = wp_insert_post([
        'post_title'   => $normalized_title,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_type'    => 'words',
        'tax_input'    => [
            'word-category' => $category_ids,
            'wordset'       => $wordset_ids,
        ],
        'meta_input'   => [
            '_ll_skip_audio_requirement_once' => '1',
        ],
    ]);

    if (is_wp_error($word_id) || !$word_id) {
        return is_wp_error($word_id) ? $word_id : new WP_Error('ll_image_word_create_failed', 'Failed to create word post.');
    }

    if (!empty($category_ids)) {
        wp_set_object_terms($word_id, $category_ids, 'word-category', false);
    }

    if ($attachment_id) {
        set_post_thumbnail($word_id, (int) $attachment_id);
    }

    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset', false);
    }

    if ($word_image_post_id && function_exists('ll_mark_image_picked_for_word')) {
        ll_mark_image_picked_for_word((int) $word_image_post_id, (int) $word_id);
    }

    return $word_id;
}

/**
 * Allow image uploads to temporarily bypass the audio publishing requirement.
 */
add_filter('ll_tools_skip_audio_requirement', function ($skip, $post_id) {
    if (!empty($GLOBALS['ll_image_upload_skip_audio_requirement'])) {
        return true;
    }
    return $skip;
}, 10, 2);
