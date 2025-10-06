<?php // File: includes/admin/audio-review-page.php
/**
 * Audio Review Admin Page
 * Allows admins to review and approve draft word posts with audio
 */

if (!defined('WPINC')) { die; }

/**
 * Register the Audio Review admin page
 */
function ll_register_audio_review_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Audio Review',
        'LL Tools Audio Review',
        'view_ll_tools',
        'll-audio-review',
        'll_render_audio_review_page'
    );
}
add_action('admin_menu', 'll_register_audio_review_page');

/**
 * Enqueue assets for the audio review page
 */
function ll_audio_review_enqueue_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-review') return;

    wp_enqueue_style(
        'll-audio-review-style',
        plugins_url('css/audio-review.css', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'css/audio-review.css')
    );

    wp_enqueue_script(
        'll-audio-review-script',
        plugins_url('js/audio-review.js', LL_TOOLS_MAIN_FILE),
        ['jquery'],
        filemtime(LL_TOOLS_BASE_PATH . 'js/audio-review.js'),
        true
    );

    wp_localize_script('ll-audio-review-script', 'llAudioReview', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ll_audio_review'),
    ]);
}
add_action('admin_enqueue_scripts', 'll_audio_review_enqueue_assets');

/**
 * Render the audio review page
 */
function ll_render_audio_review_page() {
    $pending_count = ll_get_pending_audio_review_count();

    ?>
    <div class="wrap ll-audio-review-wrap">
        <h1>Audio Review Queue</h1>
        <p>Review and approve word posts with uploaded audio. Posts remain as drafts until approved.</p>

        <div id="ll-review-stats" class="ll-review-stats">
            <span class="ll-stat">
                <strong>Pending Review:</strong>
                <span id="ll-pending-count"><?php echo esc_html($pending_count); ?></span>
            </span>
        </div>

        <div id="ll-review-stage" style="display:none;">
            <div id="ll-review-current">
                <div class="ll-review-header">
                    <h2 id="ll-review-title"></h2>
                    <div class="ll-review-meta">
                        <span id="ll-review-category"></span>
                        <span id="ll-review-wordset"></span>
                        <span id="ll-review-recording-type" style="color: #2271b1; font-weight: bold;"></span>
                    </div>
                    <div class="ll-review-debug">
                        <small>Audio Post ID: <span id="ll-review-post-id"></span> | Path: <span id="ll-review-audio-path"></span></small>
                    </div>
                </div>

                <div class="ll-review-content">
                    <div class="ll-review-image">
                        <img id="ll-review-img" src="" alt="" />
                    </div>

                    <div class="ll-review-audio-section">
                        <audio id="ll-review-audio" controls preload="auto"></audio>
                        <p class="ll-review-hint">Listen to the audio and verify quality</p>
                    </div>

                    <div class="ll-review-translation">
                        <strong>Translation:</strong>
                        <span id="ll-review-translation-text"></span>
                    </div>
                </div>

                <div class="ll-review-actions">
                    <button class="button button-primary button-large" id="ll-approve-btn">
                        ✓ Approve & Publish
                    </button>
                    <button class="button button-secondary button-large" id="ll-reprocess-btn">
                        ⟲ Mark for Reprocessing
                    </button>
                    <button class="button button-large" id="ll-skip-btn">
                        Skip for Now
                    </button>
                </div>

                <div id="ll-review-status"></div>
            </div>
        </div>

        <div id="ll-review-complete" style="display:none;">
            <div class="ll-review-complete-message">
                <h2>✓ All Caught Up!</h2>
                <p>No more audio files pending review.</p>
                <button class="button" id="ll-review-refresh">Refresh</button>
            </div>
        </div>

        <div id="ll-review-start">
            <?php if ($pending_count > 0): ?>
                <button class="button button-primary button-hero" id="ll-start-review">
                    Start Reviewing (<?php echo esc_html($pending_count); ?> pending)
                </button>
            <?php else: ?>
                <p>No audio files pending review.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Get count of posts pending audio review
 */
function ll_get_pending_audio_review_count() {
    $query = new WP_Query([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_ll_needs_audio_review',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ]);

    return $query->found_posts;
}

/**
 * AJAX: Get next word to review
 */
add_action('wp_ajax_ll_get_next_audio_review', function() {
    check_ajax_referer('ll_audio_review', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Permission denied');
    }

    $exclude = isset($_POST['exclude']) ? array_map('intval', (array)$_POST['exclude']) : [];

    $query = new WP_Query([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => 1,
        'post__not_in'   => $exclude,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => '_ll_needs_audio_review',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ]);

    if (!$query->have_posts()) {
        wp_send_json_success(['item' => null]);
    }

    $audio_post_id = $query->posts[0]->ID;
    $audio_path = get_post_meta($audio_post_id, 'audio_file_path', true);
    $audio_url = $audio_path ? ((0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path)) : '';

    $parent_word_id = wp_get_post_parent_id($audio_post_id);
    $word_title = $parent_word_id ? get_the_title($parent_word_id) : get_the_title($audio_post_id);

    $categories = $parent_word_id ? wp_get_post_terms($parent_word_id, 'word-category', ['fields' => 'names']) : [];
    $wordsets = $parent_word_id ? wp_get_post_terms($parent_word_id, 'wordset', ['fields' => 'names']) : [];
    $translation = $parent_word_id ? get_post_meta($parent_word_id, 'word_english_meaning', true) : '';
    $image_url = $parent_word_id ? get_the_post_thumbnail_url($parent_word_id, 'medium') : '';

    // Get recording type for this specific audio post
    $recording_types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'names']);
    $recording_type_label = !is_wp_error($recording_types) && !empty($recording_types) ? implode(', ', $recording_types) : 'Unknown';

    $item = [
        'id'             => $audio_post_id,
        'title'          => $word_title,
        'audio_url'      => $audio_url,
        'audio_path'     => $audio_path, // Include raw path for debugging
        'image_url'      => $image_url,
        'translation'    => $translation,
        'categories'     => $categories ? implode(', ', $categories) : '',
        'wordsets'       => $wordsets ? implode(', ', $wordsets) : '',
        'recording_type' => $recording_type_label,
    ];

    wp_send_json_success(['item' => $item]);
});

/**
 * AJAX: Approve word and publish
 */
add_action('wp_ajax_ll_approve_audio', function() {
    check_ajax_referer('ll_audio_review', 'nonce');

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

    // Publish the word_audio post
    wp_update_post([
        'ID'          => $audio_post_id,
        'post_status' => 'publish',
    ]);

    // Remove review flag
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');

    // Publish the parent word post if it's still a draft
    $parent_word_id = wp_get_post_parent_id($audio_post_id);
    if ($parent_word_id) {
        $parent_word = get_post($parent_word_id);
        if ($parent_word && $parent_word->post_status === 'draft') {
            wp_update_post([
                'ID'          => $parent_word_id,
                'post_status' => 'publish',
            ]);
        }
    }

    wp_send_json_success(['message' => 'Audio approved and post published']);
});

/**
 * AJAX: Mark audio for reprocessing
 */
add_action('wp_ajax_ll_mark_for_reprocessing', function() {
    check_ajax_referer('ll_audio_review', 'nonce');

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

    // Mark the audio_post for processing
    update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');

    wp_send_json_success(['message' => 'Audio marked for reprocessing']);
});