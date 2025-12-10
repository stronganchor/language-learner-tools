<?php
/**
 * Admin page for bulk processing uploaded audio recordings
 */

if (!defined('WPINC')) { die; }

function ll_register_audio_processor_page() {
    add_submenu_page(
        'tools.php',
        'Audio Processor - Language Learner Tools',
        'LL Audio Processor',
        'view_ll_tools',
        'll-audio-processor',
        'll_render_audio_processor_page'
    );
}
add_action('admin_menu', 'll_register_audio_processor_page');

function ll_enqueue_audio_processor_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-processor') return;

    $recording_type_terms = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);

    $recording_type_choices = [];
    if (!is_wp_error($recording_type_terms)) {
        foreach ($recording_type_terms as $term) {
            $recording_type_choices[] = [
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }
    }

    wp_enqueue_style(
        'll-audio-processor-css',
        plugins_url('css/audio-processor.css', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'css/audio-processor.css')
    );

    wp_enqueue_script(
        'll-audio-processor-js',
        plugins_url('js/audio-processor.js', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'js/audio-processor.js'),
        true
    );

    // Get all unprocessed recordings
    $recordings = ll_get_unprocessed_recordings();

    wp_localize_script('ll-audio-processor-js', 'llAudioProcessor', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_audio_processor'),
        'recordings' => $recordings,
        'recordingTypes' => $recording_type_choices,
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_audio_processor_assets');

function ll_get_unprocessed_recordings() {
    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $query = new WP_Query($args);
    $recordings = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $audio_post_id = get_the_ID();
            $audio_file = get_post_meta($audio_post_id, 'audio_file_path', true);
            $parent_word_id = wp_get_post_parent_id($audio_post_id);

            if ($audio_file && $parent_word_id) {
                $word_title = get_the_title($parent_word_id);
                $categories = wp_get_post_terms($parent_word_id, 'word-category', ['fields' => 'names']);

                // Get wordset names
                $wordsets = wp_get_post_terms($parent_word_id, 'wordset', ['fields' => 'names']);
                $wordset_names = (!is_wp_error($wordsets) && !empty($wordsets)) ? $wordsets : [];

                // Get recording type names
                $recording_type_terms = wp_get_post_terms($audio_post_id, 'recording_type');
                $recording_type_names = (!is_wp_error($recording_type_terms) && !empty($recording_type_terms)) ? wp_list_pluck($recording_type_terms, 'name') : [];
                $recording_type_slugs = (!is_wp_error($recording_type_terms) && !empty($recording_type_terms)) ? wp_list_pluck($recording_type_terms, 'slug') : [];

                // Get image thumbnail
                $image_url = get_the_post_thumbnail_url($parent_word_id, 'thumbnail');

                $recordings[] = [
                    'id' => $audio_post_id,
                    'title' => $word_title,
                    'audioUrl' => site_url($audio_file),
                    'uploadDate' => get_post_meta($audio_post_id, 'recording_date', true),
                    'categories' => is_array($categories) && !is_wp_error($categories) ? $categories : [],
                    'wordsets' => $wordset_names,
                    'recordingTypes' => $recording_type_names,
                    'recordingType' => !empty($recording_type_slugs) ? $recording_type_slugs[0] : '',
                    'imageUrl' => $image_url ?: '',
                ];
            }
        }
        wp_reset_postdata();
    }

    return $recordings;
}

function ll_render_audio_processor_page() {
    $recordings = ll_get_unprocessed_recordings();
    ?>
    <div class="wrap ll-audio-processor-wrap">
        <h1>Audio Processor</h1>
        <p>Process uploaded audio recordings with configurable noise reduction, loudness normalization, and silence trimming.</p>

        <?php if (empty($recordings)): ?>
            <div class="notice notice-info">
                <p>No unprocessed audio recordings found.</p>
            </div>
        <?php else: ?>
            <div class="ll-processing-options">
                <h3>Processing Options</h3>
                <label>
                    <input type="checkbox" id="ll-enable-trim" checked>
                    <span>Trim silence from start and end</span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-noise" checked>
                    <span>Apply noise reduction</span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-loudness" checked>
                    <span>Normalize loudness</span>
                </label>
            </div>

            <div class="ll-processor-controls">
                <button id="ll-select-all" class="button">Select All</button>
                <button id="ll-deselect-all" class="button">Deselect All</button>
                <button id="ll-process-selected" class="button button-primary" disabled>
                    Process Selected (<span id="ll-selected-count">0</span>)
                </button>
            </div>

            <div id="ll-processor-status" class="ll-processor-status" style="display:none;">
                <div class="ll-progress-bar">
                    <div class="ll-progress-fill" style="width: 0%"></div>
                </div>
                <p class="ll-status-text">Processing...</p>
            </div>

            <div class="ll-recordings-list">
                <?php foreach ($recordings as $recording): ?>
                    <div class="ll-recording-item" data-id="<?php echo esc_attr($recording['id']); ?>">
                        <label class="ll-recording-label">
                            <input type="checkbox" class="ll-recording-checkbox" value="<?php echo esc_attr($recording['id']); ?>">
                            <div class="ll-recording-info">
                                <strong><?php echo esc_html($recording['title']); ?></strong>
                                <?php if (!empty($recording['categories'])): ?>
                                    <span class="ll-recording-categories">
                                        <?php echo esc_html(implode(', ', $recording['categories'])); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="ll-recording-date">
                                    <?php echo esc_html(date('Y-m-d H:i', strtotime($recording['uploadDate']))); ?>
                                </span>
                            </div>
                        </label>
                        <audio controls preload="none" src="<?php echo esc_url($recording['audioUrl']); ?>"></audio>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Review Interface (shown after processing) -->
            <div id="ll-review-interface" class="ll-review-interface">
                <h2>Review Processed Audio</h2>
                <div id="ll-review-files-container"></div>
                <div class="ll-review-actions">
                    <button id="ll-save-all" class="ll-btn-save-all">Save All Changes</button>
                    <button id="ll-delete-all-review" class="button button-link-delete">Delete All</button>
                    <button id="ll-cancel-review" class="ll-btn-cancel">Cancel</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * AJAX handler to save processed audio file
 */
add_action('wp_ajax_ll_save_processed_audio', 'll_save_processed_audio_handler');

function ll_save_processed_audio_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Permission denied');
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $recording_type = isset($_POST['recording_type']) ? sanitize_text_field($_POST['recording_type']) : '';

    if (!$audio_post_id || !isset($_FILES['audio'])) {
        wp_send_json_error('Missing required data');
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error('Invalid audio post');
    }

    $parent_word_id = wp_get_post_parent_id($audio_post_id);
    if (!$parent_word_id) {
        wp_send_json_error('No parent word post found');
    }

    $parent_word = get_post($parent_word_id);
    if (!$parent_word || $parent_word->post_type !== 'words') {
        wp_send_json_error('Invalid parent word post');
    }

    $file = $_FILES['audio'];
    $upload_dir = wp_upload_dir();

    $title = sanitize_file_name($parent_word->post_title);

    // Get recording type (selected override falls back to current term) to make filename unique
    $existing_recording_types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'slugs']);
    $type_for_filename = $recording_type ?: (!is_wp_error($existing_recording_types) && !empty($existing_recording_types) ? $existing_recording_types[0] : '');
    $type_suffix = $type_for_filename ? '_' . $type_for_filename : '';

    // Include audio_post_id to ensure absolute uniqueness
    $filename = $title . $type_suffix . '_' . $audio_post_id . '_' . time() . '.mp3';
    $filepath = trailingslashit($upload_dir['path']) . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        wp_send_json_error('Failed to save file');
    }

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Update this specific word_audio post
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    delete_post_meta($audio_post_id, '_ll_needs_audio_processing');
    update_post_meta($audio_post_id, '_ll_processed_audio_date', current_time('mysql'));
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    wp_update_post([
        'ID'          => $audio_post_id,
        'post_status' => 'publish',
    ]);

    // Also publish the parent word post if it's still a draft
    if ($parent_word->post_status === 'draft') {
        wp_update_post([
            'ID'          => $parent_word_id,
            'post_status' => 'publish',
        ]);
    }

    wp_send_json_success([
        'message' => 'Audio processed and published successfully',
        'file_path' => $relative_path,
        'audio_post_id' => $audio_post_id,
        'recording_type' => $recording_type,
    ]);
}

/**
 * AJAX handler to delete an audio recording
 */
add_action('wp_ajax_ll_delete_audio_recording', 'll_delete_audio_recording_handler');

function ll_delete_audio_recording_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Permission denied');
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$audio_post_id) {
        wp_send_json_error('Invalid post ID');
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error('Invalid audio post');
    }

    // Resolve parent word before deletion
    $parent_word_id = (int) $audio_post->post_parent;

    // Delete the audio file from filesystem
    $audio_file_path = get_post_meta($audio_post_id, 'audio_file_path', true);
    if ($audio_file_path) {
        $full_path = ABSPATH . ltrim($audio_file_path, '/');
        if (file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    // Delete the post
    $deleted = wp_delete_post($audio_post_id, true);

    if ($deleted) {
        // If parent exists, and it now has no published audio posts, set it to draft and clean legacy meta
        if ($parent_word_id) {
            $remaining = get_posts([
                'post_type'      => 'word_audio',
                'post_parent'    => $parent_word_id,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (empty($remaining)) {
                $parent = get_post($parent_word_id);
                if ($parent && $parent->post_status === 'publish') {
                    wp_update_post([
                        'ID'          => $parent_word_id,
                        'post_status' => 'draft',
                    ]);
                }
                // Remove legacy meta to prevent stale fallbacks elsewhere
                delete_post_meta($parent_word_id, 'word_audio_file');
            }
        }

        wp_send_json_success(['message' => 'Audio recording deleted']);
    } else {
        wp_send_json_error('Failed to delete audio recording');
    }
}

/**
 * Show admin notice if there are unprocessed recordings
 */
add_action('admin_notices', 'll_audio_processor_admin_notice');

function ll_audio_processor_admin_notice() {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_ll-audio-processor') {
        return;
    }

    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'fields' => 'ids'
    ];

    $query = new WP_Query($args);
    $unprocessed_count = $query->found_posts;
    wp_reset_postdata();

    if ($unprocessed_count > 0) {
        $url = admin_url('tools.php?page=ll-audio-processor');
        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>Audio Processor:</strong> You have %d unprocessed audio recording%s. <a href="%s">Process them now</a></p></div>',
            $unprocessed_count,
            $unprocessed_count === 1 ? '' : 's',
            esc_url($url)
        );
    }
}
