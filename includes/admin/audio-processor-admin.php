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
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_audio_processor_assets');

function ll_get_unprocessed_recordings() {
    $args = [
        'post_type' => 'words',
        'post_status' => 'publish',
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
            $post_id = get_the_ID();
            $audio_file = get_post_meta($post_id, 'word_audio_file', true);

            if ($audio_file) {
                $recordings[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'audioUrl' => site_url($audio_file),
                    'uploadDate' => get_post_meta($post_id, '_ll_raw_recording_date', true),
                    'categories' => wp_get_post_terms($post_id, 'word-category', ['fields' => 'names']),
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
        <p>Process uploaded audio recordings with noise reduction, loudness normalization, and silence trimming.</p>

        <?php if (empty($recordings)): ?>
            <div class="notice notice-info">
                <p>No unprocessed audio recordings found.</p>
            </div>
        <?php else: ?>
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

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$post_id || !isset($_FILES['audio'])) {
        wp_send_json_error('Missing required data');
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'words') {
        wp_send_json_error('Invalid post');
    }

    $file = $_FILES['audio'];
    $upload_dir = wp_upload_dir();

    // Generate filename
    $title = sanitize_file_name($post->post_title);
    $filename = $title . '_processed_' . time() . '.mp3';
    $filepath = trailingslashit($upload_dir['path']) . $filename;

    // Save file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        wp_send_json_error('Failed to save file');
    }

    // Get relative path
    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Update post meta
    update_post_meta($post_id, 'word_audio_file', $relative_path);
    delete_post_meta($post_id, '_ll_needs_audio_processing');
    update_post_meta($post_id, '_ll_processed_audio_date', current_time('mysql'));

    wp_send_json_success([
        'message' => 'Audio processed successfully',
        'file_path' => $relative_path
    ]);
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
        return; // Don't show on the processor page itself
    }

    $count = wp_count_posts('words');
    $args = [
        'post_type' => 'words',
        'post_status' => 'publish',
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