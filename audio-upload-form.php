<?php

/************************************************************************************
 * [audio_upload_form] Shortcode
 * 
 * Bulk upload audio files & generate new word posts
 ***********************************************************************************/
function ll_audio_upload_form_shortcode() {
    if (!current_user_can('upload_files')) {
        return 'You do not have permission to upload files.';
    }

    ob_start();
    ?>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
        <input type="file" name="ll_audio_files[]" multiple="multiple"><br>
        
        <input type="checkbox" id="match_existing_posts" name="match_existing_posts" value="1">
        <label for="match_existing_posts">Match to existing word posts instead of creating new ones</label><br>
        
        <div id="wordsets_section">
        Select Word Set:<br>
        <?php
            ll_display_wordsets_dropdown();
        ?>
        </div>

        <div id="parts_of_speech_section">
            Select Part of Speech:<br>
            <select name="ll_part_of_speech">
                <option value="">-- Select Part of Speech --</option>
                <?php
                $parts_of_speech = get_terms([
                    'taxonomy' => 'part_of_speech',
                    'hide_empty' => false,
                ]);
                foreach ($parts_of_speech as $part_of_speech) {
                    echo '<option value="' . esc_attr($part_of_speech->term_id) . '">' . esc_html($part_of_speech->name) . '</option>';
                }
                ?>
            </select>
        </div>

        <div id="categories_section">
            <?php
            echo 'Select Categories:<br>';
            echo '<div style="max-height: 200px; overflow-y: scroll; border: 1px solid #ccc; padding: 5px;">';
            ll_display_categories_checklist('word-category');
            echo '</div>';
            ?>
        </div>
        
        <input type="hidden" name="action" value="process_audio_files">
        <input type="submit" value="Upload Audio Files">
    </form>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // When the 'Match to existing word posts' checkbox changes
        $('#match_existing_posts').change(function() {
            // Toggle the visibility of the categories section
            $('#categories_section').toggle(!this.checked);
            // Toggle the visibility of the word set section
            $('#wordsets_section').toggle(!this.checked);
            // Toggle the visibility of the part of speech section
            $('#parts_of_speech_section').toggle(!this.checked);
        });

        // When a category checkbox changes
        $('input[name="ll_word_categories[]"]').on('change', function() {
            let parentId = $(this).data('parent-id');
            // Loop through all parents and check them
            while (parentId) {
                const parentCheckbox = $('input[name="ll_word_categories[]"][value="' + parentId + '"]');
                parentCheckbox.prop('checked', true);
                parentId = parentCheckbox.data('parent-id'); // Move to the next parent
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_upload_form', 'll_audio_upload_form_shortcode');

// Add bulk audio uploading tool to the 'All Words' page in the admin dashboard
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

// Function to display a dropdown of available word sets based on user role
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
            echo '<option value="' . $wordset->term_id . '">' . $wordset->name . '</option>';
        }
        echo '</select>';
    } else {
        echo '<p>No word sets available.</p>';
    }
}

// Function to display categories with indentation
function ll_display_categories_checklist($taxonomy, $parent = 0, $level = 0) {
    $categories = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => $parent,
    ]);

    foreach ($categories as $category) {
        $indent = str_repeat("&nbsp;&nbsp;&nbsp;", $level);
        echo $indent . '<input type="checkbox" name="ll_word_categories[]" value="' . esc_attr($category->term_id) . '" data-parent-id="' . esc_attr($category->parent) . '"> ' . esc_html($category->name) . '<br>';

        // Recursive call to get child categories
        ll_display_categories_checklist($taxonomy, $category->term_id, $level + 1);
    }
}

// Hook into the admin_post action for our form's submission
add_action('admin_post_process_audio_files', 'll_handle_audio_file_uploads');
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

        // Check if the file type is allowed
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);
        if (!in_array($mime_type, $allowed_audio_types)) {
            $failed_matches[] = $original_name . ' (Invalid file type)';
            continue;
        }

        // Check if the file size is within the allowed limit
        if ($file_size > $max_file_size) {
            $failed_matches[] = $original_name . ' (File size exceeds the limit)';
            continue;
        }

        // Perform additional audio file validation using getID3 library
        require_once 'getid3/getid3.php';
        $getID3 = new getID3();
        $file_info = $getID3->analyze($tmp_name);
        if (!isset($file_info['audio'])) {
            $failed_matches[] = $original_name . ' (Invalid audio file)';
            continue;
        }

        if ($_FILES['ll_audio_files']['error'][$key] === UPLOAD_ERR_OK && is_uploaded_file($tmp_name)) {
            $sanitized_name = sanitize_file_name(basename($original_name));
            $upload_path = $upload_dir['path'] . '/' . $sanitized_name;

            // Check if the file already exists and modify the file name if it does
            $counter = 0;
            $file_info = pathinfo($sanitized_name);
            $original_base_name = $file_info['filename'];
            $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
            while (file_exists($upload_path)) {
                $sanitized_name = $original_base_name . '_' . $counter . $extension;
                $upload_path = $upload_dir['path'] . '/' . $sanitized_name;
                $counter++;
            }

            if (move_uploaded_file($tmp_name, $upload_path)) {
                $relative_upload_path = str_replace(wp_normalize_path(untrailingslashit(ABSPATH)), '', wp_normalize_path($upload_path));

                $title_without_extension = pathinfo($original_name, PATHINFO_FILENAME);
                $title_cleaned = preg_replace('/[_0-9]+/', '', $title_without_extension);
                $formatted_title = ll_normalize_case(sanitize_text_field($title_cleaned));

                if ($match_existing_posts) {
                    // Try to find an existing post with a matching title
                    $existing_post = ll_find_post_by_exact_title($formatted_title);
                    if ($existing_post) {
                        // Update the existing post's audio file metadata
                        update_post_meta($existing_post->ID, 'word_audio_file', $relative_upload_path);
                        $success_matches[] = $original_name . ' -> Post ID: ' . $existing_post->ID;
                    } else {
                        $failed_matches[] = $original_name;
                    }
                } else {
                    // Use the normalized title as the post title
                    $post_id = wp_insert_post([
                        'post_title'    => $formatted_title,
                        'post_content'  => '',
                        'post_status'   => 'publish',
                        'post_type'     => 'words',
                    ]);

                    $selected_wordset = isset($_POST['selected_wordset']) ? intval($_POST['selected_wordset']) : 0;

                    if ($post_id && !is_wp_error($post_id)) {
                        // Save the relative path of the audio file and full transcription as post meta
                        $relative_upload_path = '/wp-content/uploads' . str_replace($upload_dir['basedir'], '', $upload_path);
                        update_post_meta($post_id, 'word_audio_file', $relative_upload_path);
                        
                        // Retrieve language settings from the WordPress database
                        $translation_language = get_option('ll_translation_language');
                        
                        update_post_meta($post_id, 'wordset', $selected_wordset);
                        $target_language = ll_get_wordset_language($selected_wordset);
                        
                        // Retrieve the names of languages supported by DeepL
                        $deepl_languages = get_deepl_language_names(true);

                        // If the target language is supported by DeepL, translate the title
                        if ($deepl_languages && in_array($target_language, $deepl_languages)) {
                            $translated_title = translate_with_deepl($formatted_title, $translation_language, $target_language);
                            if (!is_null($translated_title)) {
                                update_post_meta($post_id, 'word_english_meaning', $translated_title);
                            }
                        }

                        // Assign selected categories to the post
                        if (!empty($selected_categories)) {
                            $selected_categories = array_map('intval', $selected_categories);
                            wp_set_object_terms($post_id, $selected_categories, 'word-category', false);
                        }
                        
                        // Assign the selected part of speech to the post
                        if (isset($_POST['ll_part_of_speech']) && !empty($_POST['ll_part_of_speech'])) {
                            $selected_part_of_speech = intval($_POST['ll_part_of_speech']);
                            wp_set_object_terms($post_id, $selected_part_of_speech, 'part_of_speech', false);
                        }

                        // Try to find a relevant image and assign it as the featured image
                        $matching_image = ll_find_matching_image($title_without_extension, $selected_categories);
                        if ($matching_image) {
                            $matching_image_attachment_id = get_post_thumbnail_id($matching_image->ID);
                            if ($matching_image_attachment_id) {
                                set_post_thumbnail($post_id, $matching_image_attachment_id);
                            }
                        }

                        // Add to success matches list for feedback
                        $success_matches[] = $original_name . ' -> New Post ID: ' . $post_id;
                    } else {
                        // Add to failed matches list if there was an issue creating the post
                        $failed_matches[] = $original_name . ' (Failed to create post)';
                    }
                }
            }
        }
    }
    // Display success and failure messages on the same page    
    echo '<h3>Upload Results:</h3>';
    if (!empty($success_matches)) {
        if($match_existing_posts){
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
        if($match_existing_posts){
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

    // Add a link to go back to the previous page
    echo '<p><a href="' . esc_url(wp_get_referer()) . '">Go back to the previous page</a></p>';

}

// Find the best matching image for a given audio file name and category
function ll_find_matching_image($audio_file_name, $categories) {
    $image_posts = get_posts(array(
        'post_type' => 'word_images',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => $categories,
            ),
        )
    ));

    $best_match = null;
    $max_exact_matches = 0;
    $max_score = 0;

    // Split the audio file name into words
    $audio_words = preg_split('/[\s\-.,;:!?]+/', strtolower($audio_file_name));

    foreach ($image_posts as $image_post) {
        $image_file_name = pathinfo($image_post->post_title, PATHINFO_FILENAME);
        
        // Split the image post name into words
        $image_words = preg_split('/[\s\-.,;:!?]+/', strtolower($image_file_name));

        // Count the number of exact word matches
        $exact_matches = count(array_intersect($audio_words, $image_words));

        if ($exact_matches > $max_exact_matches) {
            $max_exact_matches = $exact_matches;
            $best_match = $image_post;
        }
    }

    // If no exact matches were found, fall back to the existing logic
    if ($max_exact_matches === 0) {
        foreach ($image_posts as $image_post) {
            $image_file_name = pathinfo($image_post->post_title, PATHINFO_FILENAME);
            similar_text(strtolower($audio_file_name), strtolower($image_file_name), $score);

            if ($score > $max_score || $best_match === null) {
                $max_score = $score;
                $best_match = $image_post;
            }
        }
    }

    return $best_match;
}
?>