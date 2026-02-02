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
 * Enqueue admin assets (CSS + JS) for the Audio/Image Matcher
 */
function ll_aim_enqueue_admin_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-image-matcher') return;

    // Enqueue flashcard styles so we can reuse those classes
    ll_enqueue_asset_by_timestamp('/css/flashcard/base.css', 'll-tools-flashcard-style', [], false);
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-practice.css',
        'll-tools-flashcard-mode-practice',
        ['ll-tools-flashcard-style'],
        false
    );
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-learning.css',
        'll-tools-flashcard-mode-learning',
        ['ll-tools-flashcard-style'],
        false
    );
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-listening.css',
        'll-tools-flashcard-mode-listening',
        ['ll-tools-flashcard-style'],
        false
    );

    // Then our matcher-specific overrides
    ll_enqueue_asset_by_timestamp(
        '/css/audio-image-matcher.css',
        'll-aim-admin-css',
        [
            'll-tools-flashcard-style',
            'll-tools-flashcard-mode-practice',
            'll-tools-flashcard-mode-learning',
            'll-tools-flashcard-mode-listening'
        ],
        false
    );

    ll_enqueue_asset_by_timestamp('/js/audio-image-matcher.js', 'll-audio-image-matcher', ['jquery'], true);

    wp_add_inline_script(
        'll-audio-image-matcher',
        'window.ajaxurl = window.ajaxurl || "'.esc_js(admin_url('admin-ajax.php')).'";',
        'before'
    );
}
add_action('admin_enqueue_scripts', 'll_aim_enqueue_admin_assets');

/**
 * Render UI
 */
function ll_render_audio_image_matcher_page() {
    // Categories for the Category select
    $cats = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);

    // Wordsets for the new Word Set select
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
    ]);

    // Preselects (allow URL override for auto-launch use cases)
    $pre_term_id      = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    $explicit_ws_id   = isset($_GET['wordset_id']) ? intval($_GET['wordset_id']) : 0;
    $pre_wordset_id   = function_exists('ll_tools_get_active_wordset_id')
        ? ll_tools_get_active_wordset_id($explicit_ws_id)
        : ( $explicit_ws_id ?: 0 );

    $pre_rematch = isset($_GET['rematch']) ? (intval($_GET['rematch']) === 1) : false;

    // Include template (now expects $wordsets and $pre_wordset_id as well)
    $template = LL_TOOLS_BASE_PATH . '/templates/audio-image-matcher-template.php';
    if (file_exists($template)) {
        include $template; // expects $cats, $wordsets, $pre_term_id, $pre_wordset_id, $pre_rematch
    } else {
        echo '<div class="notice notice-error"><p>Template not found: <code>' . esc_html($template) . '</code></p></div>';
    }
}

// AJAX: Fetch candidate images for a category (word_images posts)
add_action('wp_ajax_ll_aim_get_images', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $term_id  = isset($_GET['term_id'])   ? intval($_GET['term_id'])   : 0;
    $hide_used = isset($_GET['hide_used']) ? (intval($_GET['hide_used']) === 1) : false;
    if (!$term_id) wp_send_json_success(['images' => []]);

    $images = get_posts([
        'post_type'      => 'word_images',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
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

        // Always count actual published words using this image (ignore cached meta)
        $used_count = 0;
        if ($thumb_id) {
            $q = new WP_Query([
                'post_type'      => 'words',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => [[ 'key' => '_thumbnail_id', 'value' => $thumb_id, 'compare' => '=' ]],
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]);
            $used_count = $q->found_posts;
            wp_reset_postdata();
        }

        if ($hide_used && $used_count > 0) {
            continue;
        }

        $out[] = [
            'id'         => $img_post->ID,
            'title'      => $img_post->post_title,
            'thumb'      => $thumb_url,
            'used_count' => $used_count,
        ];
    }
    wp_send_json_success(['images' => $out]);
});

/**
 * AJAX: Next "words" post with audio; include/skip existing thumbnails based on rematch flag
 */
add_action('wp_ajax_ll_aim_get_next', function() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error('forbidden', 403);

    $term_id    = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
    $rematch    = isset($_GET['rematch']) ? (intval($_GET['rematch']) === 1) : false;

    // NEW: capture/resolve wordset
    $explicit_ws_id = isset($_GET['wordset_id']) ? intval($_GET['wordset_id']) : 0;
    $wordset_id = function_exists('ll_tools_get_active_wordset_id')
        ? ll_tools_get_active_wordset_id($explicit_ws_id) // uses default if 0, else respects explicit
        : ( $explicit_ws_id ?: 0 );

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

    $word_ids = ll_aim_get_word_ids_with_audio($term_id, $wordset_id, $exclude, 10, false);

    $item = null;
    if (!empty($word_ids)) {
        foreach ($word_ids as $pid) {
            $pid = (int) $pid;
            $has_thumb = has_post_thumbnail($pid);
            if (!$rematch && $has_thumb) { continue; }

            $audio_url = function_exists('ll_get_word_audio_url') ? ll_get_word_audio_url($pid) : '';

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
 * AUTO-LAUNCH LOGIC (disabled by default)
 * --------------------------------------------------------------------*/

function ll_aim_autolaunch_enabled() {
    return (bool) apply_filters('ll_aim_autolaunch_enabled', false);
}

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

/**
 * Helper: fetch word IDs with published audio children for a category/wordset.
 *
 * @param int   $term_id
 * @param int   $wordset_id
 * @param array $exclude
 * @param int   $limit
 * @param bool  $require_no_thumb
 * @return array
 */
function ll_aim_get_word_ids_with_audio($term_id, $wordset_id = 0, $exclude = [], $limit = 10, $require_no_thumb = false) {
    global $wpdb;

    $term_id = (int) $term_id;
    if ($term_id <= 0) {
        return [];
    }

    $wordset_id = (int) $wordset_id;
    $limit = max(1, min(100, (int) $limit));
    $exclude = array_values(array_filter(array_map('intval', (array) $exclude), function ($id) {
        return $id > 0;
    }));

    $joins = "
        INNER JOIN {$wpdb->posts} wa
            ON wa.post_parent = p.ID
           AND wa.post_type = 'word_audio'
           AND wa.post_status = 'publish'
        INNER JOIN {$wpdb->term_relationships} tr_cat
            ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat
            ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
           AND tt_cat.taxonomy = 'word-category'
           AND tt_cat.term_id = %d
    ";

    $params = [ $term_id ];

    if ($wordset_id > 0) {
        $joins .= "
        INNER JOIN {$wpdb->term_relationships} tr_ws
            ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws
            ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
           AND tt_ws.taxonomy = 'wordset'
           AND tt_ws.term_id = %d
        ";
        $params[] = $wordset_id;
    }

    $where = "p.post_type = 'words' AND p.post_status = 'publish'";

    if (!empty($exclude)) {
        $placeholders = implode(',', array_fill(0, count($exclude), '%d'));
        $where .= " AND p.ID NOT IN ({$placeholders})";
        $params = array_merge($params, $exclude);
    }

    if ($require_no_thumb) {
        $where .= " AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm
            WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
        )";
    }

    $sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        {$joins}
        WHERE {$where}
        ORDER BY p.ID ASC
        LIMIT %d
    ";

    $params[] = $limit;

    return $wpdb->get_col($wpdb->prepare($sql, $params));
}

/**
 * Helper: Check if a category has unmatched work (words with audio but no thumbnail).
 */
function ll_aim_category_has_unmatched_work($term_id) {
    // 1) Must have at least one word_images post
    if (!ll_aim_term_has_posttype($term_id, 'word_images')) {
        return false;
    }

    // 2) Must have at least one words post with audio but no thumbnail
    $word_ids = ll_aim_get_word_ids_with_audio($term_id, 0, [], 1, true);
    return !empty($word_ids);
}

function ll_aim_maybe_queue_autolaunch_on_words_save($post_id, $post, $update) {
    if (!ll_aim_autolaunch_enabled()) return;
    if ($post->post_type !== 'words') return;
    if (wp_is_post_revision($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $status = get_post_status($post_id);
    if (in_array($status, ['trash','auto-draft','inherit'], true)) return;

    if (!function_exists('ll_tools_word_has_audio') || !ll_tools_word_has_audio($post_id, ['publish'])) {
        return;
    }

    $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (empty($terms)) return;

    foreach ($terms as $tid) {
        if (ll_aim_category_has_unmatched_work($tid)) {
            if (is_user_logged_in()) {
                $key = 'll_aim_autolaunch_' . get_current_user_id();
                set_transient($key, (int) $tid, 120);
            }
            break;
        }
    }
}
add_action('save_post', 'll_aim_maybe_queue_autolaunch_on_words_save', 10, 3);

function ll_aim_maybe_queue_autolaunch_on_images_save($post_id, $post, $update) {
    if (!ll_aim_autolaunch_enabled()) return;
    if ($post->post_type !== 'word_images') return;
    if (wp_is_post_revision($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $status = get_post_status($post_id);
    if (in_array($status, ['trash','auto-draft','inherit'], true)) return;

    $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (empty($terms)) return;

    foreach ($terms as $tid) {
        if (ll_aim_category_has_unmatched_work($tid)) {
            if (is_user_logged_in()) {
                $key = 'll_aim_autolaunch_' . get_current_user_id();
                set_transient($key, (int) $tid, 120);
            }
            break;
        }
    }
}
add_action('save_post', 'll_aim_maybe_queue_autolaunch_on_images_save', 10, 3);

function ll_aim_admin_autolaunch_redirect() {
    if (!ll_aim_autolaunch_enabled()) return;
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
