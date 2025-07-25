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

        <div>
            <label><?php esc_html_e( 'Select Categories', 'll-tools-text-domain' ); ?>:</label><br>
            <?php ll_render_category_selection_field( 'words' ); ?>
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

    // Check if we're matching existing posts or creating new ones
    $match_existing_posts = isset($_POST['match_existing_posts']) && $_POST['match_existing_posts'];

    // Prepare for file upload handling
    $selected_categories = isset($_POST['ll_word_categories']) ? (array) $_POST['ll_word_categories'] : [];
    $upload_dir = wp_upload_dir();
    $success_matches = [];
    $failed_matches = [];

    $allowed_audio_types = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a'];
    $max_file_size = 10 * 1024 * 1024; // 10MB

    foreach ($_FILES['ll_audio_files']['tmp_name'] as $key => $tmp_name) {
        $original_name = $_FILES['ll_audio_files']['name'][$key];
        $file_size = $_FILES['ll_audio_files']['size'][$key];

        // Validate the uploaded file
        $validation_result = ll_validate_uploaded_file($tmp_name, $original_name, $file_size, $allowed_audio_types, $max_file_size);
        if ($validation_result !== true) {
            $failed_matches[] = $validation_result;
            continue;
        }

        // Move the uploaded file
        $upload_result = ll_upload_file($tmp_name, $original_name, $upload_dir['path']);
        if (is_wp_error($upload_result)) {
            $failed_matches[] = $original_name . ' (' . $upload_result->get_error_message() . ')';
            continue;
        }

        $relative_upload_path = ll_get_relative_upload_path($upload_result);

        $formatted_title = ll_format_title($original_name);

        if ($match_existing_posts) {
            // Try to find and update an existing post
            $existing_post = ll_find_post_by_exact_title($formatted_title);
            if ($existing_post) {
                ll_update_existing_post_audio($existing_post->ID, $relative_upload_path);
                $success_matches[] = $original_name . ' -> Post ID: ' . $existing_post->ID;
            } else {
                $failed_matches[] = $original_name . ' (No matching post found)';
            }
        } else {
            // Create a new post
            $post_id = ll_create_new_word_post($formatted_title, $relative_upload_path, $_POST, $selected_categories, $upload_dir);
            if ($post_id && !is_wp_error($post_id)) {
                $success_matches[] = $original_name . ' -> New Post ID: ' . $post_id;
            } else {
                $failed_matches[] = $original_name . ' (Failed to create post)';
            }
        }
    }

    // Display success and failure messages on the same page    
    ll_display_upload_results($success_matches, $failed_matches, $match_existing_posts);

    // Add a link to go back to the previous page
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
        return $original_name . ' (Invalid file type)';
    }

    // Check if the file size is within the allowed limit
    if ($file_size > $max_size) {
        return $original_name . ' (File size exceeds the limit)';
    }

    // Perform additional audio file validation using getID3 library
    require_once 'getid3/getid3.php';
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
 * Updates the audio file metadata for an existing post.
 *
 * @param int    $post_id Post ID.
 * @param string $relative_path Relative path to the audio file.
 */
function ll_update_existing_post_audio($post_id, $relative_path) {
    update_post_meta($post_id, 'word_audio_file', $relative_path);
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
        'post_status'   => 'publish',
        'post_type'     => 'words',
    ]);

    if ($post_id && !is_wp_error($post_id)) {
        // Save the relative path of the audio file
        update_post_meta($post_id, 'word_audio_file', $relative_path);

        // Assign the selected word set
        $selected_wordset = isset($post_data['selected_wordset']) ? intval($post_data['selected_wordset']) : 0;
        update_post_meta($post_id, 'wordset', $selected_wordset);

        // Handle translations
        $deepl_language_codes = get_deepl_language_codes();
        if ($deepl_language_codes) {  
            $target_language_name = ll_get_wordset_language($selected_wordset);

            // Determine target language code
            $target_language_code = '';
            if (!$target_language_name) {
                $target_language_code = get_option('ll_target_language');
            } else {                      
                $target_language_code = array_search($target_language_name, $deepl_language_codes);
            }

            $translation_language_code = get_option('ll_translation_language');
            $translated_title = ''; 

            // If the languages are supported by DeepL, translate the title
            if ($target_language_code && $translation_language_code) {
                $translated_title = translate_with_deepl($title, $translation_language_code, $target_language_code);
                if (!is_null($translated_title)) {
                    update_post_meta($post_id, 'word_english_meaning', $translated_title);
                } else {
                    update_post_meta($post_id, 'word_english_meaning', 'Error translating ' . $title . ' to ' . $translation_language_code . ' from ' . $target_language_code);
                }
            } else {
                update_post_meta($post_id, 'word_english_meaning', 'Error translating to ' . $translation_language_code . ' from ' . $target_language_code);
            }
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
        $matching_image = ll_find_matching_image($image_search_string, $selected_categories);
        if ($matching_image) {
            $matching_image_attachment_id = get_post_thumbnail_id($matching_image->ID);
            if ($matching_image_attachment_id) {
                set_post_thumbnail($post_id, $matching_image_attachment_id);
            }
        }

        return $post_id;
    }

    return new WP_Error('post_creation_failed', 'Failed to create new word post.');
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

/**
 * Finds the best matching image for a given audio file name and category.
 *
 * 1. If an image post title exactly equals the audio title (case‐insensitive),
 *    return that immediately.
 * 2. Otherwise, pick the post with the most token‐by‐token overlaps.
 * 3. If still zero overlaps, fall back to a fuzzy similar_text scan.
 *
 * @param string $audio_file_name  The (formatted) audio‐search string.
 * @param array  $categories       Array of category IDs to limit the search.
 * @return WP_Post|null            The best matching image post or null if none found.
 */
function ll_find_matching_image( $audio_file_name, $categories ) {
    // Get all word_image posts in these categories
    $image_posts = get_posts( array(
        'post_type'      => 'word_images',
        'posts_per_page' => -1,
        'tax_query'      => array(
            array(
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => $categories,
            ),
        ),
    ) );

    if ( empty( $image_posts ) ) {
        return null;
    }

    $audio_lower  = mb_strtolower( $audio_file_name, 'UTF-8' );
    // split on punctuation/whitespace into tokens
    $audio_words  = preg_split( '/[\s\-\.,;:!?\(\)]+/', $audio_lower, -1, PREG_SPLIT_NO_EMPTY );

    $best_match         = null;
    $max_exact_matches  = -1;
    $best_fuzzy_score   = 0;

    foreach ( $image_posts as $image_post ) {
        $image_title      = pathinfo( $image_post->post_title, PATHINFO_FILENAME );
        $image_lower      = mb_strtolower( $image_title, 'UTF-8' );

        // 1) immediate exact full‐string match
        if ( 0 === mb_strcasecmp( $audio_lower, $image_lower, 'UTF-8' ) ) {
            return $image_post;
        }

        // 2) count token overlaps
        $image_words      = preg_split( '/[\s\-\.,;:!?\(\)]+/', $image_lower, -1, PREG_SPLIT_NO_EMPTY );
        $exact_matches    = count( array_intersect( $audio_words, $image_words ) );

        if ( $exact_matches > $max_exact_matches ) {
            $max_exact_matches = $exact_matches;
            $best_match        = $image_post;
        }
    }

    // 3) if we got zero overlaps, fall back to fuzzy scoring
    if ( 0 === $max_exact_matches ) {
        foreach ( $image_posts as $image_post ) {
            $image_title    = pathinfo( $image_post->post_title, PATHINFO_FILENAME );
            similar_text( $audio_file_name, $image_title, $score );
            if ( $score > $best_fuzzy_score ) {
                $best_fuzzy_score = $score;
                $best_match       = $image_post;
            }
        }
    }

    return $best_match;
}
?>
