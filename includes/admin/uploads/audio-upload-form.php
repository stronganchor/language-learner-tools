<?php

/************************************************************************************
 * [audio_upload_form] Shortcode
 * 
 * Bulk upload audio files & generate new word posts
 ***********************************************************************************/

/**
 * Shortcode handler for [audio_upload_form].
 *
 * @return string The HTML form for uploading audio files.
 */
function ll_audio_upload_form_shortcode() {
    if ( ! current_user_can( 'upload_files' ) ) {
        return 'You do not have permission to upload files.';
    }

    // Get recording types
    $recording_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
    ]);

    // Get users for speaker assignment
    $users = get_users([
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);

    ob_start();
    ?>
    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
        <!-- only allow audio files -->
        <input type="file" name="ll_audio_files[]" accept="audio/*" multiple /><br>

        <label>
            <input type="checkbox" id="match_existing_posts" name="match_existing_posts" value="1">
            <?php esc_html_e( 'Match to existing word posts instead of creating new ones', 'll-tools-text-domain' ); ?>
        </label><br>

        <label>
            <input type="checkbox" id="match_image_on_translation" name="match_image_on_translation" value="1">
            <?php esc_html_e( 'Match images based on translation instead of original word', 'll-tools-text-domain' ); ?>
        </label><br>

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
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <div>
            <label><?php esc_html_e( 'Select Categories', 'll-tools-text-domain' ); ?>:</label><br>
            <?php ll_render_category_selection_field( 'words' ); ?>
        </div>

        <div style="margin-top:10px;">
            <label><?php esc_html_e( 'Word Set', 'll-tools-text-domain' ); ?>:</label><br>
            <select name="ll_wordset_id" required>
                <?php
                $wsets = get_terms(['taxonomy' => 'wordset', 'hide_empty' => false]);
                if (!is_wp_error($wsets)) {
                    echo '<option value="">' . esc_html__('— Select —', 'll-tools-text-domain') . '</option>';
                    foreach ($wsets as $ws) {
                        printf('<option value="%d">%s</option>',
                            (int) $ws->term_id,
                            esc_html($ws->name)
                        );
                    }
                }
                ?>
            </select>
        </div>

        <input type="hidden" name="action" value="process_audio_files">
        <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Bulk Add Audio', 'll-tools-text-domain' ); ?>">
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
        echo '<h2>Bulk Audio Upload for Words</h2>';
        echo ll_audio_upload_form_shortcode();
    }
}
add_action('admin_notices', 'll_add_bulk_audio_upload_tool_admin_page');

/**
 * Displays a dropdown of available word sets based on user role.
 */
function ll_display_wordsets_dropdown() {
    $user = wp_get_current_user();
    $wordsets = array();

    if (in_array('administrator', $user->roles)) {
        // If the user is an administrator, get all word sets
        $wordsets = get_terms('wordset', array('hide_empty' => false));
    } elseif (in_array('wordset_manager', $user->roles)) {
        // If the user is a word set manager, get only the word sets they manage
        $managed_wordsets = get_user_meta($user->ID, 'managed_wordsets', true);
        if (!empty($managed_wordsets)) {
            $wordsets = get_terms(array(
                'taxonomy' => 'wordset',
                'hide_empty' => false,
                'include' => $managed_wordsets,
            ));
        }
    }

    if (!empty($wordsets)) {
        echo '<select name="selected_wordset">';
        echo '<option value="">Select a word set</option>';
        foreach ($wordsets as $wordset) {
            echo '<option value="' . esc_attr($wordset->term_id) . '">' . esc_html($wordset->name) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<p>No word sets available.</p>';
    }
}

/**
 * Handles the processing of uploaded audio files.
 */
function ll_handle_audio_file_uploads() {
    // Security check: Ensure the current user can upload files
    if (!current_user_can('upload_files')) {
        wp_die('You do not have permission to upload files.');
    }

    $match_existing_posts = !empty($_POST['match_existing_posts']);
    $selected_categories  = isset($_POST['ll_word_categories']) ? (array) $_POST['ll_word_categories'] : [];
    $upload_dir           = wp_upload_dir();
    $success_matches      = [];
    $failed_matches       = [];

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
            $failed_matches[] = $original_name . ' (' . $upload_result->get_error_message() . ')';
            continue;
        }

        $relative_upload_path = ll_get_relative_upload_path($upload_result);
        $formatted_title      = ll_format_title($original_name);

        if ($match_existing_posts) {
            $existing_post = ll_find_post_by_exact_title($formatted_title);
            if ($existing_post) {
                ll_update_existing_post_audio($existing_post->ID, $relative_upload_path, $_POST);
                $success_matches[] = $original_name . ' -> Post ID: ' . $existing_post->ID;
            } else {
                $failed_matches[] = $original_name . ' (No matching post found)';
            }
        } else {
            $post_id = ll_create_new_word_post($formatted_title, $relative_upload_path, $_POST, $selected_categories, $upload_dir);
            if ($post_id && !is_wp_error($post_id)) {
                $success_matches[] = $original_name . ' -> New Post ID: ' . $post_id;
            } else {
                $failed_matches[] = $original_name . ' (Failed to create post)';
            }
        }
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
    echo '<p><a href="' . esc_url(wp_get_referer()) . '">Go back to the previous page</a></p>';
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
        return $original_name . ' (Invalid file type: ' . esc_html($mime_type) . ')';
    }

    // Check if the file size is within the allowed limit
    if ($file_size > $max_size) {
        return $original_name . ' (File size exceeds the limit)';
    }

    // Perform additional audio file validation using getID3 library
    require_once LL_TOOLS_BASE_PATH . 'vendor/getid3/getid3.php';
    $getID3 = new getID3();
    $file_info = $getID3->analyze($tmp_name);
    if (!isset($file_info['audio'])) {
        return $original_name . ' (Invalid audio file)';
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
        return new WP_Error('upload_error', 'Failed to move uploaded file.');
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
 * @return string Formatted title for matching.
 */
function ll_format_title( $original_name ) {
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
    return ll_normalize_case( sanitize_text_field( $to_use ) );
}

/**
 * Creates a word_audio child for the existing word.
 *
 * @param int    $post_id        Parent words post ID.
 * @param string $relative_path  Relative path to the uploaded audio file.
 * @param array  $post_data      (Optional) $_POST from the form for speaker/type.
 */
function ll_update_existing_post_audio($post_id, $relative_path, $post_data = []) {
    // Speaker assignment (same logic as create-new path)
    $speaker_assignment = isset($post_data['ll_speaker_assignment']) ? $post_data['ll_speaker_assignment'] : 'current';
    $speaker_user_id = null;
    if ($speaker_assignment === 'current') {
        $speaker_user_id = get_current_user_id();
    } elseif ($speaker_assignment === 'unassigned') {
        $speaker_user_id = null;
    } elseif (is_numeric($speaker_assignment)) {
        $speaker_user_id = (int) $speaker_assignment;
    }

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
    if ($speaker_user_id) {
        $audio_post_args['post_author'] = $speaker_user_id;
    }

    $audio_post_id = wp_insert_post($audio_post_args);
    if (is_wp_error($audio_post_id)) {
        error_log('Audio upload: failed to create word_audio post for word ' . $post_id);
        return;
    }

    // Store file + review flags on the word_audio child
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    if ($speaker_user_id) {
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
    $post_id = wp_insert_post([
        'post_title'    => $title,
        'post_content'  => '',
        'post_status'   => 'draft',
        'post_type'     => 'words',
    ]);

    if ($post_id && !is_wp_error($post_id)) {
        // Determine speaker assignment
        $speaker_assignment = isset($post_data['ll_speaker_assignment']) ? $post_data['ll_speaker_assignment'] : 'current';
        $speaker_user_id = null;

        if ($speaker_assignment === 'current') {
            $speaker_user_id = get_current_user_id();
        } elseif ($speaker_assignment === 'unassigned') {
            $speaker_user_id = null;
        } elseif (is_numeric($speaker_assignment)) {
            $speaker_user_id = (int) $speaker_assignment;
        }

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
        if ($speaker_user_id) {
            $audio_post_args['post_author'] = $speaker_user_id;
        }

        $audio_post_id = wp_insert_post($audio_post_args);

        if (!is_wp_error($audio_post_id)) {
            update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
            update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
            update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');

            // Store speaker_user_id (can be null for unassigned)
            if ($speaker_user_id) {
                update_post_meta($audio_post_id, 'speaker_user_id', $speaker_user_id);
            }

            // Assign recording type taxonomy
            wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
        }

        if (!is_wp_error($audio_post_id)) {
            update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
            update_post_meta($audio_post_id, 'speaker_user_id', get_current_user_id());
            update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
            update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');

            // Assign default recording type
            wp_set_object_terms($audio_post_id, 'isolation', 'recording_type');
        }

        // Determine the chosen word-set ID (prefer the new <select name="ll_wordset_id">)
        $wordset_id = isset($post_data['ll_wordset_id']) ? (int) $post_data['ll_wordset_id'] : 0;

        // Back-compat: if older field exists, allow it as a fallback
        if ($wordset_id <= 0 && isset($post_data['selected_wordset'])) {
            $wordset_id = (int) $post_data['selected_wordset'];
        }

        // Final fallback: use the active/default word-set helper if available
        if ($wordset_id <= 0 && function_exists('ll_tools_get_active_wordset_id')) {
            $wordset_id = (int) ll_tools_get_active_wordset_id();
        }

        // 3) Assign taxonomy term for 'wordset' (authoritative for scoping)
        if ($wordset_id > 0) {
            wp_set_object_terms($post_id, [$wordset_id], 'wordset', /*append*/ true);
        }

        // (Optional) keep any existing meta for compatibility with your older code/UI
        if ($wordset_id > 0) {
            update_post_meta($post_id, 'wordset', $wordset_id);
        }

        // 4) (Existing code) — translations, categories, part of speech, image matching, etc.

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
        $matching_image = ll_find_matching_image_conservative($image_search_string, $selected_categories);
        if ($matching_image) {
            $matching_image_attachment_id = get_post_thumbnail_id($matching_image->ID);
            if ($matching_image_attachment_id) {
                set_post_thumbnail($post_id, $matching_image_attachment_id);
                ll_mark_image_picked_for_word($matching_image->ID, $post_id);
            }
        }

        return $post_id;
    }

    return new WP_Error('ll_create_word_failed', 'Failed to create the post.');
}

/**
 * Displays the upload results to the user.
 *
 * @param array  $success_matches Array of successful uploads.
 * @param array  $failed_matches Array of failed uploads.
 * @param bool   $match_existing_posts Whether matching existing posts was enabled.
 */
function ll_display_upload_results($success_matches, $failed_matches, $match_existing_posts) {
    echo '<h3>Upload Results:</h3>';
    if (!empty($success_matches)) {
        if ($match_existing_posts) {
            echo '<h4>Updated Posts:</h4>';
        } else {
            echo '<h4>Created Posts:</h4>';
        }
        echo '<ul>';
        foreach ($success_matches as $match) {
            echo '<li>' . esc_html($match) . '</li>';
        }
        echo '</ul>';
    }

    if (!empty($failed_matches)) {
        if ($match_existing_posts) {
            echo '<h4>Failed Updates:</h4>';
        } else {
            echo '<h4>Failed Creations:</h4>';
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
    function ll_find_matching_image_conservative($audio_like_name, $categories) {
        $audio_norm = ll_sim_normalize($audio_like_name);
        if ($audio_norm === '') return null;

        $image_posts = get_posts([
            'post_type'      => 'word_images',
            'posts_per_page' => -1,
            'tax_query'      => [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => $categories,
            ]],
            'orderby' => 'title',
            'order'   => 'ASC',
        ]);
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
