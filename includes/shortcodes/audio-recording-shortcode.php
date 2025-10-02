<?php
/**
 * [audio_recording_interface] - Public-facing interface for native speakers
 * to record audio for word images that don't have audio yet.
 */

if (!defined('WPINC')) { die; }

function ll_audio_recording_interface_shortcode($atts) {
    $atts = shortcode_atts([
        'category' => '',
        'wordset'  => '',
        'language' => '',
    ], $atts);

    // Resolve wordset term IDs (fallback to default if missing)
    $wordset_term_ids = ll_resolve_wordset_term_ids_or_default($atts['wordset']);

    // Get images that need audio FOR THIS WORDSET
    $images_needing_audio = ll_get_images_needing_audio($atts['category'], $wordset_term_ids);

    if (empty($images_needing_audio)) {
        return '<div class="ll-recording-interface"><p>No images need audio recordings at this time. Thank you!</p></div>';
    }

    // Enqueue assets
    ll_enqueue_recording_assets();

    // Pass data to JavaScript (include canonical wordset_ids)
    wp_localize_script('ll-audio-recorder', 'll_recorder_data', [
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('ll_upload_recording'),
        'images'      => $images_needing_audio,
        'language'    => $atts['language'],
        'wordset'     => $atts['wordset'],         // keep for compatibility
        'wordset_ids' => $wordset_term_ids,        // canonical + enforced on server
        'hide_name'   => get_option('ll_hide_recording_titles', 0), 
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
 * Return the earliest-created wordset term_id (approximate via lowest term_id).
 */
function ll_get_default_wordset_term_id() {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'id',   // lowest term_id first
        'order'      => 'ASC',
        'number'     => 1,
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
        return (int) $terms[0]->term_id;
    }
    return 0;
}

/**
 * Resolve explicit wordset spec to term IDs, otherwise fall back to default wordset.
 */
function ll_resolve_wordset_term_ids_or_default($wordset_spec) {
    $ids = [];
    if (!empty($wordset_spec) && function_exists('ll_raw_resolve_wordset_term_ids')) {
        $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
    }
    if (empty($ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $ids = [$default_id];
        }
    }
    return array_map('intval', $ids);
}

/**
 * Get word images that need audio recordings for a specific wordset (by term IDs).
 * @param string $category_slug
 * @param array  $wordset_term_ids  One or more wordset term IDs. If empty, falls back to default.
 */
function ll_get_images_needing_audio($category_slug = '', $wordset_term_ids = []) {
    // If nothing provided, fall back to default wordset so guests never see "all images"
    if (empty($wordset_term_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_term_ids = [$default_id];
        }
    }

    $args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ];

    if (!empty($category_slug)) {
        $args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ]];
    }

    $image_posts = get_posts($args);
    $result = [];

    // Group images by category
    $images_by_category = [];

    foreach ($image_posts as $img_id) {
        // Check if this image already has audio for THIS wordset
        $has_audio = ll_image_has_audio_for_wordset($img_id, $wordset_term_ids);

        if (!$has_audio) {
            $thumb_url = get_the_post_thumbnail_url($img_id, 'large');
            if ($thumb_url) {
                $categories = wp_get_post_terms($img_id, 'word-category');
                if (!empty($categories) && !is_wp_error($categories)) {
                    $category = $categories[0];
                    $category_name = $category->name;
                    $category_id   = $category->term_id;
                } else {
                    $category_name = 'Uncategorized';
                    $category_id   = 0;
                }

                if (!isset($images_by_category[$category_id])) {
                    $images_by_category[$category_id] = [
                        'name'   => $category_name,
                        'images' => [],
                    ];
                }

                $images_by_category[$category_id]['images'][] = [
                    'id'           => $img_id,
                    'title'        => get_the_title($img_id),
                    'image_url'    => $thumb_url,
                    'category_name'=> $category_name,
                ];
            }
        }
    }

    uasort($images_by_category, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

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

    // Prefer explicit wordset_ids from client; else resolve from 'wordset'; else default
    $posted_ids = [];
    if (isset($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) {
            $posted_ids = array_map('intval', $decoded);
        }
    }

    $wordset_spec = isset($_POST['wordset']) ? sanitize_text_field($_POST['wordset']) : '';
    if (empty($posted_ids)) {
        $posted_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    $image_post = get_post($image_id);
    if (!$image_post || $image_post->post_type !== 'word_images') {
        wp_send_json_error('Invalid image ID');
    }

    // Upload the audio file
    $file = $_FILES['audio'];
    $upload_dir = wp_upload_dir();

    $image_title = sanitize_file_name($image_post->post_title);
    $timestamp   = time();

    // Determine file extension based on uploaded type
    $mime_type = $file['type'];
    $extension = '.webm'; // default
    if (strpos($mime_type, 'wav') !== false) {
        $extension = '.wav';
    } elseif (strpos($mime_type, 'mp3') !== false) {
        $extension = '.mp3';
    } elseif (strpos($mime_type, 'webm') !== false && strpos($mime_type, 'pcm') !== false) {
        $extension = '.wav'; // PCM WebM is essentially WAV
    }

    $filename    = $image_title . '_' . $timestamp . $extension;
    $filepath    = trailingslashit($upload_dir['path']) . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        wp_send_json_error('Failed to save file');
    }

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Create word post
    $word_id = wp_insert_post([
        'post_title'  => $image_post->post_title,
        'post_type'   => 'words',
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

    // ALWAYS assign to a wordset (posted_ids is guaranteed to have fallback if needed)
    if (!empty($posted_ids)) {
        wp_set_object_terms($word_id, $posted_ids, 'wordset');
    }

    // Store the raw audio file path + processing flags
    update_post_meta($word_id, 'word_audio_file', $relative_path);
    update_post_meta($word_id, '_ll_needs_audio_processing', '1');
    update_post_meta($word_id, '_ll_raw_recording_date', current_time('mysql'));
    update_post_meta($word_id, '_ll_raw_recording_format', $extension);

    wp_send_json_success([
        'word_id' => $word_id,
        'message' => 'Recording uploaded successfully - pending processing',
    ]);
}
