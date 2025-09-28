<?php
// /includes/admin/audio-image-matcher.php
if (!defined('WPINC')) { die; }

/**
 * Admin submenu: Tools → LL Tools Audio/Image Matcher
 * Capability: view_ll_tools
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
 * Enqueue JS (cache-busted) on our page
 */
function ll_aim_enqueue_admin_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-image-matcher') return;
    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/js/audio-image-matcher.js', 'll-audio-image-matcher', ['jquery'], true);
    } else {
        wp_enqueue_script(
            'll-audio-image-matcher',
            plugins_url('../../js/audio-image-matcher.js', __FILE__),
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . '../../js/audio-image-matcher.js'),
            true
        );
    }
    // Ensure ajaxurl exists
    wp_add_inline_script('ll-audio-image-matcher', 'window.ajaxurl = window.ajaxurl || "'.admin_url('admin-ajax.php').'";', 'before');
}
add_action('admin_enqueue_scripts', 'll_aim_enqueue_admin_assets');

/**
 * Render UI
 */
function ll_render_audio_image_matcher_page() {
    $cats = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);

    $pre_term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    $pre_rematch = isset($_GET['rematch']) ? (intval($_GET['rematch']) === 1) : false;
    ?>
    <div class="wrap">
        <h1>Audio ↔ Image Matcher</h1>
        <p>Select a category, then click <em>Start Matching</em>. In <strong>Rematch mode</strong>, already-matched words are included and picking an image will replace the current featured image.</p>

        <div id="ll-aim-controls" style="margin:16px 0; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <label for="ll-aim-category"><strong>Category:</strong></label>
            <select id="ll-aim-category">
                <option value="">— Select —</option>
                <?php foreach ($cats as $t): ?>
                    <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($pre_term_id, $t->term_id); ?>>
                        <?php echo esc_html($t->name . ' ('.$t->slug.')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" id="ll-aim-rematch" <?php checked($pre_rematch, true); ?> />
                Rematch mode (include already-matched words)
            </label>

            <button class="button button-primary" id="ll-aim-start">Start Matching</button>
            <button class="button" id="ll-aim-skip" disabled>Skip</button>
        </div>

        <div id="ll-aim-stage" style="display:none;">
            <div id="ll-aim-current" style="margin-bottom:12px;">
                <h2 id="ll-aim-word-title" style="margin:8px 0;">&nbsp;</h2>
                <audio id="ll-aim-audio" controls preload="auto" style="max-width:520px; display:block;"></audio>
                <p id="ll-aim-extra" style="color:#666; margin:6px 0 10px;"></p>

                <div id="ll-aim-current-thumb" style="display:none; max-width:520px;">
                    <img src="" alt="" style="width:100%; height:auto; border:1px solid #ddd; border-radius:8px;">
                    <div class="ll-aim-cap" style="font-size:.9rem; color:#555; margin-top:6px;"></div>
                </div>
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
 * AJAX: Fetch candidate images for a category (word_images posts)
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
 * AJAX: Next "words" post with audio; include/skip existing thumbnails based on rematch flag
 */
add_action('wp_ajax_ll_aim_get_next', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    $rematch = isset($_GET['rematch']) ? (intval($_GET['rematch']) === 1) : false;

    // Accept exclude as array "exclude[]" or scalar/comma string
    $exclude = [];
    if (isset($_GET['exclude'])) {
        $raw = $_GET['exclude'];
        if (is_array($raw)) {
            $exclude = array_map('intval', $raw);
        } else {
            $parts = array_filter(array_map('trim', explode(',', (string)$raw)));
            $exclude = array_map('intval', $parts);
        }
    }

    if (!$term_id) wp_send_json_success(['item' => null]);

    $q = new WP_Query([
        'post_type'      => 'words',
        'posts_per_page' => 10,
        'post__not_in'   => $exclude,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'meta_query'     => [[
            'key'     => 'word_audio_file',
            'compare' => 'EXISTS',
        ]],
        'orderby' => 'ID',
        'order'   => 'ASC',
        'no_found_rows' => true,
        'fields' => 'ids',
    ]);

    $item = null;
    if ($q->have_posts()) {
        foreach ($q->posts as $pid) {
            $has_thumb = has_post_thumbnail($pid);
            if (!$rematch && $has_thumb) { continue; }

            $audio_rel = get_post_meta($pid, 'word_audio_file', true);
            $audio_url = $audio_rel ? ( (0 === strpos($audio_rel, 'http')) ? $audio_rel : site_url($audio_rel) ) : '';

            $item = [
                'id'            => $pid,
                'title'         => get_the_title($pid),
                'translation'   => get_post_meta($pid, 'word_english_meaning', true),
                'audio_url'     => $audio_url,
                'edit_link'     => get_edit_post_link($pid, 'raw'),
                'current_thumb' => $has_thumb ? get_the_post_thumbnail_url($pid, 'medium') : '',
            ];
            break;
        }
    }

    wp_send_json_success(['item' => $item]);
});

/**
 * AJAX: Assign chosen image’s thumbnail to the "words" post (replaces existing if present)
 */
add_action('wp_ajax_ll_aim_assign', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $word_id  = isset($_POST['word_id']) ? intval($_POST['word_id']) : 0;
    $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

    if (!$word_id || !$image_id) wp_send_json_error('missing params', 400);

    $attachment_id = get_post_thumbnail_id($image_id);
    if (!$attachment_id) wp_send_json_error('image has no thumbnail', 400);

    // This overwrites any existing thumbnail on the words post
    set_post_thumbnail($word_id, $attachment_id);

    wp_send_json_success(['ok' => true]);
});

/* ----------------------------------------------------------------------
 * AUTO-LAUNCH LOGIC (unchanged)
 * --------------------------------------------------------------------*/

function ll_aim_term_has_posttype($term_id, $post_type) {
    $q = new WP_Query([
        'post_type'      => $post_type,
        'posts_per_page' => 1,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    $has = $q->have_posts();
    wp_reset_postdata();
    return $has;
}

function ll_aim_maybe_queue_autolaunch_on_words_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || $post->post_type !== 'words') return;
    $audio = get_post_meta($post_id, 'word_audio_file', true);
    if (!$audio) return;

    $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (empty($terms)) return;

    foreach ($terms as $tid) {
        if (ll_aim_term_has_posttype($tid, 'word_images')) {
            if (is_user_logged_in()) {
                $key = 'll_aim_autolaunch_' . get_current_user_id();
                set_transient($key, intval($tid), 120);
            }
            break;
        }
    }
}
add_action('save_post', 'll_aim_maybe_queue_autolaunch_on_words_save', 10, 3);

function ll_aim_maybe_queue_autolaunch_on_images_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || $post->post_type !== 'word_images') return;

    $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (empty($terms)) return;

    foreach ($terms as $tid) {
        $q = new WP_Query([
            'post_type'      => 'words',
            'posts_per_page' => 1,
            'tax_query'      => [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => [$tid],
            ]],
            'meta_query'     => [[ 'key' => 'word_audio_file', 'compare' => 'EXISTS' ]],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $has_words_with_audio = $q->have_posts();
        wp_reset_postdata();

        if ($has_words_with_audio) {
            if (is_user_logged_in()) {
                $key = 'll_aim_autolaunch_' . get_current_user_id();
                set_transient($key, intval($tid), 120);
            }
            break;
        }
    }
}
add_action('save_post', 'll_aim_maybe_queue_autolaunch_on_images_save', 10, 3);

function ll_aim_admin_autolaunch_redirect() {
    if (!is_user_logged_in()) return;
    if (wp_doing_ajax()) return;

    $key = 'll_aim_autolaunch_' . get_current_user_id();
    $tid = get_transient($key);
    if ($tid) {
        delete_transient($key);
        $url = add_query_arg(
            ['page' => 'll-audio-image-matcher', 'term_id' => intval($tid), 'autostart' => 1],
            admin_url('tools.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
add_action('admin_init', 'll_aim_admin_autolaunch_redirect');

// in audio-image-matcher.php
add_action('wp_ajax_ll_aim_assign', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $word_id  = isset($_POST['word_id']) ? intval($_POST['word_id']) : 0;
    $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
    if (!$word_id || !$image_id) wp_send_json_error('missing params', 400);

    $attachment_id = get_post_thumbnail_id($image_id);
    if (!$attachment_id) wp_send_json_error('image has no thumbnail', 400);

    // Optional: if you maintain meta counts, decrement the old image’s _ll_picked_count
    $old_attachment = get_post_thumbnail_id($word_id);
    if ($old_attachment && function_exists('attachment_url_to_postid')) {
        // If your image CPT keeps the thumbnail as its featured image,
        // we can try to resolve the parent image post for the old attachment.
        $old_image_post = get_posts([
            'post_type' => 'word_images',
            'posts_per_page' => 1,
            'meta_query' => [[ 'key' => '_thumbnail_id', 'value' => $old_attachment, 'compare' => '=' ]],
            'fields' => 'ids',
        ]);
        if (!empty($old_image_post)) {
            $old_id = (int)$old_image_post[0];
            $count = max(0, (int)get_post_meta($old_id, '_ll_picked_count', true) - 1);
            update_post_meta($old_id, '_ll_picked_count', $count);
        }
    }

    // Overwrite any existing thumbnail on the word
    set_post_thumbnail($word_id, $attachment_id);

    if (function_exists('ll_mark_image_picked_for_word')) {
        ll_mark_image_picked_for_word($image_id, $word_id);
    }

    wp_send_json_success(['ok' => true]);
});
