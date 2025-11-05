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

                    $success_uploads[] = $original_name . ' -> New Post ID: ' . $post_id;
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
