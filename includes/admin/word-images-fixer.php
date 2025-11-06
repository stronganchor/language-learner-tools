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
        if (isset($seen_attachment[$att_id])) { continue; }

        // Does a word_images post exist with this attachment as its featured image?
        $candidates = get_posts([
            'post_type'      => 'word_images',
            'post_status'    => ['publish','draft','pending'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [[ 'key' => '_thumbnail_id', 'value' => $att_id ]],
        ]);
        if (empty($candidates)) {
            $missing[] = [ 'word_id' => $wid, 'attachment_id' => $att_id ];
            $seen_attachment[$att_id] = true;
        }
    }
    return $missing;
}

function ll_create_word_image_from_word($word_id, $attachment_id) {
    $word = get_post($word_id);
    if (!$word || $word->post_type !== 'words') { return new WP_Error('invalid_word', 'Invalid word ID'); }

    $title = get_the_title($word_id);
    $image_post_id = wp_insert_post([
        'post_type'   => 'word_images',
        'post_title'  => $title,
        'post_status' => 'draft',
    ]);
    if (is_wp_error($image_post_id)) { return $image_post_id; }

    // Set featured image
    set_post_thumbnail($image_post_id, $attachment_id);

    // Copy categories
    $cats = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($cats) && !empty($cats)) { wp_set_object_terms($image_post_id, $cats, 'word-category'); }

    // Copy translation/meta
    $translation = get_post_meta($word_id, 'word_translation', true);
    if (!empty($translation)) { update_post_meta($image_post_id, 'word_translation', $translation); }
    $meaning = get_post_meta($word_id, 'word_english_meaning', true);
    if (!empty($meaning)) { update_post_meta($image_post_id, 'word_english_meaning', $meaning); }

    return (int) $image_post_id;
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

