<?php
// /includes/admin/audio-image-matcher.php
if (!defined('WPINC')) { die; }

/**
 * Admin submenu: Tools → LL Tools Audio/Image Matcher
 * Capability: view_ll_tools (same as other LL Tools admin pages)
 */
function ll_register_audio_image_matcher_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Audio/Image Matcher',
        'LL Tools Audio/Image Matcher',
        'view_ll_tools',
        'll-audio-image-matcher',
        'll_render_audio_image_matcher_page'
    );
}
add_action('admin_menu', 'll_register_audio_image_matcher_admin_page');

/**
 * Render the matching UI page.
 * - Select a word-category term
 * - Step through "words" posts that HAVE audio but LACK a featured image
 * - Play audio and choose one image from all word_images in that category
 */
function ll_render_audio_image_matcher_page() {
    // Enqueue our JS (cache-busted by mtime)
    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/js/audio-image-matcher.js', 'll-audio-image-matcher', ['jquery'], true);
    }

    // Prepare categories (word-category)
    $cats = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);
    ?>
    <div class="wrap">
        <h1>Audio ↔ Image Matcher</h1>
        <p>Select a category, then click <em>Start Matching</em>. You’ll hear one audio at a time and choose the correct image.</p>

        <div id="ll-aim-controls" style="margin:16px 0;">
            <label for="ll-aim-category"><strong>Category:</strong></label>
            <select id="ll-aim-category">
                <option value="">— Select —</option>
                <?php foreach ($cats as $t): ?>
                    <option value="<?php echo esc_attr($t->term_id); ?>">
                        <?php echo esc_html($t->name . ' ('.$t->slug.')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button button-primary" id="ll-aim-start">Start Matching</button>
            <button class="button" id="ll-aim-skip" disabled>Skip</button>
        </div>

        <div id="ll-aim-stage" style="display:none;">
            <div id="ll-aim-current" style="margin-bottom:12px;">
                <h2 id="ll-aim-word-title" style="margin:8px 0;">&nbsp;</h2>
                <audio id="ll-aim-audio" controls preload="auto" style="max-width:480px; display:block;"></audio>
                <p id="ll-aim-extra" style="color:#666; margin:6px 0 0;"></p>
            </div>

            <div id="ll-aim-images" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px;"></div>

            <div id="ll-aim-status" style="margin-top:14px; color:#444;"></div>
        </div>

        <style>
            .ll-aim-card {
                border:1px solid #ddd; border-radius:10px; padding:8px; background:#fff;
                display:flex; flex-direction:column; align-items:center; gap:6px; cursor:pointer;
                box-shadow:0 2px 6px rgba(0,0,0,.05);
                transition:transform .08s ease, box-shadow .08s ease;
                text-align:center;
            }
            .ll-aim-card:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(0,0,0,.10); }
            .ll-aim-card img { width:100%; height:120px; object-fit:cover; border-radius:8px; }
            .ll-aim-title { font-size:.92rem; font-weight:600; line-height:1.2; }
            .ll-aim-small { font-size:.8rem; color:#777; }
        </style>
    </div>
    <?php
}

/**
 * AJAX: Fetch all candidate images for a category (word_images posts)
 */
add_action('wp_ajax_ll_aim_get_images', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    if (!$term_id) wp_send_json_success(['images' => []]);

    $images = get_posts([
        'post_type'      => 'word_images',
        'posts_per_page' => -1,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'orderby' => 'title',
        'order'   => 'ASC',
    ]);

    $out = [];
    foreach ($images as $img_post) {
        $thumb_id  = get_post_thumbnail_id($img_post->ID);
        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
        $out[] = [
            'id'    => $img_post->ID,
            'title' => $img_post->post_title,
            'thumb' => $thumb_url,
        ];
    }
    wp_send_json_success(['images' => $out]);
});

/**
 * AJAX: Fetch next "words" post in the category that has audio but no featured image
 */
add_action('wp_ajax_ll_aim_get_next', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $term_id   = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    $exclude   = isset($_GET['exclude']) ? array_map('intval', (array) $_GET['exclude']) : [];

    if (!$term_id) wp_send_json_success(['item' => null]);

    $q = new WP_Query([
        'post_type'      => 'words',
        'posts_per_page' => 1,
        'post__not_in'   => $exclude,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'meta_query'     => [[ // must have audio meta
            'key'     => 'word_audio_file',
            'compare' => 'EXISTS',
        ]],
    ]);

    $item = null;
    if ($q->have_posts()) {
        while ($q->have_posts()) { $q->the_post();
            $pid = get_the_ID();

            // Skip if already has a featured image
            if (has_post_thumbnail($pid)) continue;

            $audio_rel = get_post_meta($pid, 'word_audio_file', true);
            $audio_url = $audio_rel ? site_url($audio_rel) : '';

            $item = [
                'id'         => $pid,
                'title'      => get_the_title($pid),
                'translation'=> get_post_meta($pid, 'word_english_meaning', true),
                'audio_url'  => $audio_url,
                'edit_link'  => get_edit_post_link($pid, 'raw'),
            ];
            break;
        }
        wp_reset_postdata();
    }

    wp_send_json_success(['item' => $item]);
});

/**
 * AJAX: Save the choice (assign chosen image’s thumbnail to the "words" post)
 */
add_action('wp_ajax_ll_aim_assign', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $word_id  = isset($_POST['word_id']) ? intval($_POST['word_id']) : 0;
    $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

    if (!$word_id || !$image_id) wp_send_json_error('missing params', 400);

    // Use the word_images post’s featured image attachment as the words post thumbnail
    $attachment_id = get_post_thumbnail_id($image_id);
    if (!$attachment_id) wp_send_json_error('image has no thumbnail', 400);

    set_post_thumbnail($word_id, $attachment_id);

    wp_send_json_success(['ok' => true]);
});
