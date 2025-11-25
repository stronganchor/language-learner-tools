<?php
/************************************************************************************
 * [image_upload_form] Shortcode - Bulk upload image files & generate new word image posts
 ***********************************************************************************/

/**
 * Shortcode handler for [image_upload_form].
 *
 * @return string The HTML form for uploading image files.
 */
function ll_image_upload_form_shortcode() {
    if ( ! current_user_can( 'upload_files' ) ) {
        return 'You do not have permission to upload files.';
    }

    ob_start();
    ?>
    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
        <!-- only allow image files -->
        <input type="file" name="ll_image_files[]" accept="image/*" multiple /><br>

        <div>
            <label><?php esc_html_e( 'Select Categories', 'll-tools-text-domain' ); ?>:</label><br>
            <?php ll_render_category_selection_field( 'word_images' ); ?>
        </div>

        <div style="margin-top:10px;">
            <label><?php esc_html_e('Generate word posts for these word sets (applies only when the category quizzes without audio):', 'll-tools-text-domain'); ?></label><br>
            <?php
            $wordsets = get_terms([
                'taxonomy'   => 'wordset',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            ?>
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
        <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Bulk Add Images', 'll-tools-text-domain' ); ?>">
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
    if (isset($screen->id) && $screen->id === 'edit-word_images') {
        // Directly echo the output of the shortcode function
        echo '<h2>Bulk Image Upload for Word Images</h2>';
        echo ll_image_upload_form_shortcode();
    }
}
add_action('admin_notices', 'll_add_bulk_image_upload_tool_admin_page');

/**
 * Handles the processing of uploaded image files.
 */
function ll_handle_image_file_uploads() {
    // Security check: Ensure the current user can upload files
    if (!current_user_can('upload_files')) {
        wp_die('You do not have permission to upload files.');
    }

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
    $selected_categories = isset($_POST['ll_word_categories']) ? (array) $_POST['ll_word_categories'] : [];
    $upload_dir = wp_upload_dir();
    $success_uploads = [];
    $failed_uploads = [];
    $should_autocreate_words = ll_image_upload_should_autocreate_word($selected_categories);
    $selected_wordsets = $should_autocreate_words ? ll_image_upload_sanitize_wordset_ids(isset($_POST['ll_wordset_ids']) ? $_POST['ll_wordset_ids'] : []) : [];
    $was_audio_requirement_skipped = false;
    if ($should_autocreate_words) {
        $GLOBALS['ll_image_upload_skip_audio_requirement'] = true;
        $was_audio_requirement_skipped = true;
    }

    $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    foreach ($_FILES['ll_image_files']['tmp_name'] as $key => $tmp_name) {
        $original_name = $_FILES['ll_image_files']['name'][$key];
        $file_type = $_FILES['ll_image_files']['type'][$key];
        $file_error = $_FILES['ll_image_files']['error'][$key];

        // Validate the uploaded file
        if (!in_array($file_type, $allowed_image_types)) {
            $failed_uploads[] = $original_name . ' (Invalid file type)';
            continue;
        }
        if ($file_error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_name)) {
            $failed_uploads[] = $original_name . ' (File upload error)';
            continue;
        }

        // Sanitize and prepare file name
        $file_name = sanitize_file_name($original_name);
        $upload_path = trailingslashit($upload_dir['path']) . $file_name;

        // Check if the file already exists and modify the file name if it does
        $counter = 0;
        $file_info = pathinfo($file_name);
        $original_base_name = $file_info['filename'];
        $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
        while (file_exists($upload_path)) {
            $file_name = $original_base_name . '_' . $counter . $extension;
            $upload_path = trailingslashit($upload_dir['path']) . $file_name;
            $counter++;
        }

        if (move_uploaded_file($tmp_name, $upload_path)) {
            // Preserve original (Unicode) base name for titles
            $original_title = pathinfo($original_name, PATHINFO_FILENAME);
            $attachment_id = wp_insert_attachment([
                'guid' => trailingslashit($upload_dir['baseurl']) . $file_name,
                'post_mime_type' => $file_type,
                'post_title' => $original_title,
                'post_content' => '',
                'post_status' => 'inherit'
            ], $upload_path);

            if ($attachment_id && !is_wp_error($attachment_id)) {
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
 * Determine if image uploads should auto-create word posts based on category quiz config.
 *
 * @param array $category_ids Selected category IDs from the upload form.
 * @return bool True when any selected category quizzes with an image prompt and text answers (no audio required).
 */
function ll_image_upload_should_autocreate_word($category_ids) {
    if (
        empty($category_ids)
        || !function_exists('ll_tools_get_category_quiz_config')
        || !function_exists('ll_tools_quiz_requires_audio')
    ) {
        return false;
    }

    foreach ((array) $category_ids as $tid) {
        $tid = (int) $tid;
        if ($tid <= 0) {
            continue;
        }
        $cfg = ll_tools_get_category_quiz_config($tid);
        $requires_audio = ll_tools_quiz_requires_audio($cfg, isset($cfg['option_type']) ? $cfg['option_type'] : '');
        $prompt_is_image = isset($cfg['prompt_type']) && $cfg['prompt_type'] === 'image';
        $answer_is_text = isset($cfg['option_type']) && in_array($cfg['option_type'], ['text_translation', 'text_title'], true);

        if ($prompt_is_image && $answer_is_text && !$requires_audio) {
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
