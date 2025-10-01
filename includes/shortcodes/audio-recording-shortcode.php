<?php
/**
 * [audio_recording_interface] - Public-facing interface for native speakers
 * to record audio for word images that don't have audio yet.
 */

if (!defined('WPINC')) { die; }

function ll_audio_recording_interface_shortcode($atts) {
    $atts = shortcode_atts([
        'category' => '',  // optional: filter by category slug
        'wordset' => '',   // optional: filter by wordset slug/id/name
        'language' => '',  // optional: show prompts in this language
    ], $atts);

    // Get images that need audio
    $images_needing_audio = ll_get_images_needing_audio($atts['category'], $atts['wordset']);

    if (empty($images_needing_audio)) {
        return '<div class="ll-recording-interface"><p>No images need audio recordings at this time. Thank you!</p></div>';
    }

    // Enqueue assets
    ll_enqueue_recording_assets();

    // Pass data to JavaScript
    wp_localize_script('ll-audio-recorder', 'll_recorder_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_upload_recording'),
        'images' => $images_needing_audio,
        'language' => $atts['language'],
        'wordset' => $atts['wordset'], // pass to AJAX handler
    ]);

    ob_start();
    ?>
    <div class="ll-recording-interface">
        <div class="ll-recording-progress">
            <span class="ll-current-num">1</span> / <span class="ll-total-num"><?php echo count($images_needing_audio); ?></span>
        </div>

        <div class="ll-recording-main">
            <div class="ll-recording-image-container">
                <img id="ll-current-image" src="" alt="">
                <p id="ll-image-title" class="ll-image-title"></p>
            </div>

            <div class="ll-recording-controls">
                <button id="ll-record-btn" class="ll-btn ll-btn-record" title="Record"></button>

                <button id="ll-skip-btn" class="ll-btn ll-btn-skip" title="Skip">
                    <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                </button>

                <div id="ll-recording-indicator" class="ll-recording-indicator" style="display:none;">
                    <span class="ll-recording-dot"></span>
                    <span id="ll-recording-timer">0:00</span>
                </div>

                <div id="ll-playback-controls" style="display:none;">
                    <audio id="ll-playback-audio" controls></audio>
                    <div class="ll-playback-actions">
                        <button id="ll-redo-btn" class="ll-btn ll-btn-secondary" title="Record again"></button>
                        <button id="ll-submit-btn" class="ll-btn ll-btn-primary" title="Save and continue"></button>
                    </div>
                </div>

                <div id="ll-upload-status" class="ll-upload-status"></div>
            </div>
        </div>

        <div class="ll-recording-complete" style="display:none;">
            <h2>✓</h2>
            <p><span class="ll-completed-count"></span> recordings completed</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_recording_interface', 'll_audio_recording_interface_shortcode');

/**
 * Get word images that need audio recordings for a specific wordset
 */
function ll_get_images_needing_audio($category_slug = '', $wordset_spec = '') {
    $args = [
        'post_type' => 'word_images',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'fields' => 'ids',
    ];

    if (!empty($category_slug)) {
        $args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field' => 'slug',
            'terms' => $category_slug,
        ]];
    }

    // Resolve wordset to term_id(s)
    $wordset_term_ids = [];
    if (!empty($wordset_spec)) {
        $wordset_term_ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
    }

    $image_posts = get_posts($args);
    $result = [];

    // Group images by category
    $images_by_category = [];

    foreach ($image_posts as $img_id) {
        // Check if this image already has audio for this wordset
        $has_audio = ll_image_has_audio_for_wordset($img_id, $wordset_term_ids);

        if (!$has_audio) {
            $thumb_url = get_the_post_thumbnail_url($img_id, 'large');
            if ($thumb_url) {
                // Get the categories for this image
                $categories = wp_get_post_terms($img_id, 'word-category');

                if (!empty($categories) && !is_wp_error($categories)) {
                    // Use the first category (or you could use the deepest category)
                    $category = $categories[0];
                    $category_name = $category->name;
                    $category_id = $category->term_id;
                } else {
                    $category_name = 'Uncategorized';
                    $category_id = 0;
                }

                if (!isset($images_by_category[$category_id])) {
                    $images_by_category[$category_id] = [
                        'name' => $category_name,
                        'images' => []
                    ];
                }

                $images_by_category[$category_id]['images'][] = [
                    'id' => $img_id,
                    'title' => get_the_title($img_id),
                    'image_url' => $thumb_url,
                    'category_name' => $category_name,
                ];
            }
        }
    }

    // Sort categories by name
    uasort($images_by_category, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Flatten the array while maintaining category order
    foreach ($images_by_category as $category_data) {
        foreach ($category_data['images'] as $image) {
            $result[] = $image;
        }
    }

    return $result;
}

/**
 * Check if an image has audio for a specific wordset
 */
function ll_image_has_audio_for_wordset($image_post_id, $wordset_term_ids = []) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $query_args = [
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    // If wordset specified, filter by it
    if (!empty($wordset_term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field' => 'term_id',
            'terms' => $wordset_term_ids,
        ]];
    }

    $words = get_posts($query_args);

    if (empty($words)) {
        return false;
    }

    // Check if any have audio
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an image already has a word post with audio (any audio, processed or not)
 */
function ll_image_has_processed_audio($image_post_id) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $words = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ]);

    if (empty($words)) {
        return false;
    }

    // Check if any of these words have audio (processed or not)
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an image already has a word post with audio
 */
function ll_image_has_word_with_audio($image_post_id) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $words = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ]);

    if (empty($words)) {
        return false;
    }

    // Check if any of these words have audio
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Enqueue recording interface assets
 */
function ll_enqueue_recording_assets() {
    wp_enqueue_style(
        'll-recording-interface',
        plugins_url('css/recording-interface.css', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'css/recording-interface.css')
    );

    wp_enqueue_script(
        'll-audio-recorder',
        plugins_url('js/audio-recorder.js', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'js/audio-recorder.js'),
        true
    );
}

/**
 * AJAX handler: Upload recording and create word post
 */
add_action('wp_ajax_ll_upload_recording', 'll_handle_recording_upload');
add_action('wp_ajax_nopriv_ll_upload_recording', 'll_handle_recording_upload');

function ll_handle_recording_upload() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!isset($_FILES['audio']) || !isset($_POST['image_id'])) {
        wp_send_json_error('Missing data');
    }

    $image_id = intval($_POST['image_id']);
    $wordset_spec = isset($_POST['wordset']) ? sanitize_text_field($_POST['wordset']) : '';

    $image_post = get_post($image_id);

    if (!$image_post || $image_post->post_type !== 'word_images') {
        wp_send_json_error('Invalid image ID');
    }

    // Upload the audio file
    $file = $_FILES['audio'];
    $upload_dir = wp_upload_dir();

    // Sanitize filename based on image title
    $image_title = sanitize_file_name($image_post->post_title);
    $timestamp = time();
    $filename = $image_title . '_' . $timestamp . '.webm';
    $filepath = $upload_dir['path'] . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        wp_send_json_error('Failed to save file');
    }

    // Get relative path for storage
    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Create word post
    $word_id = wp_insert_post([
        'post_title' => $image_post->post_title,
        'post_type' => 'words',
        'post_status' => 'publish',
    ]);

    if (is_wp_error($word_id)) {
        wp_send_json_error('Failed to create word post');
    }

    // Set the featured image (same as the word_image)
    $image_attachment_id = get_post_thumbnail_id($image_id);
    if ($image_attachment_id) {
        set_post_thumbnail($word_id, $image_attachment_id);
    }

    // Copy categories from image to word
    $categories = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($categories) && !empty($categories)) {
        wp_set_object_terms($word_id, $categories, 'word-category');
    }

    // Assign to wordset if specified
    if (!empty($wordset_spec)) {
        $wordset_term_ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
        if (!empty($wordset_term_ids)) {
            wp_set_object_terms($word_id, $wordset_term_ids, 'wordset');
        }
    }

    // Store the raw audio file path
    update_post_meta($word_id, 'word_audio_file', $relative_path);

    // Mark as needing processing
    update_post_meta($word_id, '_ll_needs_audio_processing', '1');
    update_post_meta($word_id, '_ll_raw_recording_date', current_time('mysql'));

    wp_send_json_success([
        'word_id' => $word_id,
        'message' => 'Recording uploaded successfully',
    ]);
}