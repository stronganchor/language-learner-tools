<?php
// Admin tool to create missing word_images posts for legacy words
if (!defined('WPINC')) { die; }

function ll_register_word_images_fixer_page() {
    add_submenu_page(
        'tools.php',
        __('LL Tools — Fix Word Images', 'll-tools-text-domain'),
        __('LL Fix Word Images', 'll-tools-text-domain'),
        'manage_options',
        'll-fix-word-images',
        'll_render_word_images_fixer_page'
    );
}
add_action('admin_menu', 'll_register_word_images_fixer_page');

function ll_find_words_missing_word_images(): array {
    // Words that have a featured image
    $words = get_posts([
        'post_type'      => 'words',
        'post_status'    => ['publish','draft','pending'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [[ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ]],
    ]);
    if (empty($words)) { return []; }

    $missing = [];
    $seen_attachment = [];
    foreach ($words as $wid) {
        $att_id = (int) get_post_thumbnail_id($wid);
        if (!$att_id) { continue; }
        $primary_wordset_id = function_exists('ll_tools_get_primary_wordset_id_for_post')
            ? (int) ll_tools_get_primary_wordset_id_for_post((int) $wid)
            : 0;
        $dedupe_key = $att_id . '|' . $primary_wordset_id;
        if (isset($seen_attachment[$dedupe_key])) { continue; }

        $linked_image_id = function_exists('ll_tools_get_linked_word_image_post_id_for_word')
            ? (int) ll_tools_get_linked_word_image_post_id_for_word((int) $wid)
            : 0;
        if ($linked_image_id <= 0) {
            $missing[] = [ 'word_id' => $wid, 'attachment_id' => $att_id ];
            $seen_attachment[$dedupe_key] = true;
        }
    }
    return $missing;
}

function ll_create_word_image_from_word($word_id, $attachment_id) {
    if (!function_exists('ll_tools_ensure_word_image_post_for_word')) {
        return new WP_Error('missing_helper', 'Word image helper is not available');
    }

    return ll_tools_ensure_word_image_post_for_word((int) $word_id);
}

function ll_render_word_images_fixer_page() {
    if (!current_user_can('manage_options')) { wp_die(__('Permission denied', 'll-tools-text-domain')); }

    $created = 0; $errors = [];
    if (isset($_POST['ll_fix_images_action']) && $_POST['ll_fix_images_action'] === 'create' && check_admin_referer('ll_fix_word_images')) {
        $candidates = ll_find_words_missing_word_images();
        foreach ($candidates as $row) {
            $res = ll_create_word_image_from_word((int) $row['word_id'], (int) $row['attachment_id']);
            if (is_wp_error($res)) { $errors[] = $res->get_error_message(); }
            else { $created++; }
        }
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Created %d word image posts.', 'll-tools-text-domain'), $created)) . '</p></div>';
        if (!empty($errors)) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('%d items failed.', 'll-tools-text-domain'), count($errors))) . '</p></div>';
        }
    }

    $missing = ll_find_words_missing_word_images();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Fix Word Images (Legacy Cleanup)', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Scan for words that have a featured image but no corresponding "word_images" post, and create them.', 'll-tools-text-domain'); ?></p>
        <p>
            <?php echo esc_html(sprintf(_n('%d word needs an image post.', '%d words need image posts.', count($missing), 'll-tools-text-domain'), count($missing))); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field('ll_fix_word_images'); ?>
            <input type="hidden" name="ll_fix_images_action" value="create">
            <button class="button button-primary" <?php disabled(empty($missing)); ?>>
                <?php esc_html_e('Create Missing Word-Image Posts', 'll-tools-text-domain'); ?>
            </button>
        </form>
    </div>
    <?php
}
