<?php
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
    // Get counts
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
        'post_type'      => 'words',
        'post_status'    => 'draft',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'word_audio_file',
                'compare' => 'EXISTS',
            ],
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
        'post_type'      => 'words',
        'post_status'    => 'draft',
        'posts_per_page' => 1,
        'post__not_in'   => $exclude,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => 'word_audio_file',
                'compare' => 'EXISTS',
            ],
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

    $post_id = $query->posts[0]->ID;
    $audio_path = get_post_meta($post_id, 'word_audio_file', true);
    $audio_url = $audio_path ? ((0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path)) : '';

    $categories = wp_get_post_terms($post_id, 'word-category', ['fields' => 'names']);
    $wordsets = wp_get_post_terms($post_id, 'wordset', ['fields' => 'names']);

    $item = [
        'id'          => $post_id,
        'title'       => get_the_title($post_id),
        'audio_url'   => $audio_url,
        'image_url'   => get_the_post_thumbnail_url($post_id, 'medium'),
        'translation' => get_post_meta($post_id, 'word_english_meaning', true),
        'categories'  => $categories ? implode(', ', $categories) : '',
        'wordsets'    => $wordsets ? implode(', ', $wordsets) : '',
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

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$post_id) {
        wp_send_json_error('Invalid post ID');
    }

    // Publish the post
    wp_update_post([
        'ID'          => $post_id,
        'post_status' => 'publish',
    ]);

    // Remove review flag
    delete_post_meta($post_id, '_ll_needs_audio_review');

    wp_send_json_success(['message' => 'Audio approved and post published']);
});