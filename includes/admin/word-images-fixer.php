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

function ll_word_images_fixer_batch_size(): int {
    $batch_size = (int) apply_filters('ll_tools_word_images_fixer_batch_size', 25);
    return max(1, min(100, $batch_size));
}

function ll_word_images_fixer_job_meta_key(): string {
    return 'll_tools_word_images_fixer_job';
}

function ll_word_images_fixer_scan_batch(int $after_id = 0): array {
    $after_id = max(0, $after_id);
    $batch_size = ll_word_images_fixer_batch_size();
    $query_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish','draft','pending'],
        'posts_per_page' => $batch_size + 1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'cache_results'  => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'suppress_filters' => false,
        'meta_query'     => [[ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ]],
    ];

    $cursor_filter = null;
    if ($after_id > 0) {
        $cursor_filter = static function (string $where) use ($after_id): string {
            global $wpdb;
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
        };
        add_filter('posts_where', $cursor_filter);
    }
    try {
        $word_ids = get_posts($query_args);
    } finally {
        if ($cursor_filter !== null) {
            remove_filter('posts_where', $cursor_filter);
        }
    }
    $word_ids = array_values(array_filter(array_map('intval', (array) $word_ids), static function (int $word_id) use ($after_id): bool {
        return $word_id > $after_id;
    }));

    $has_more = count($word_ids) > $batch_size;
    $page_ids = array_slice($word_ids, 0, $batch_size);

    $missing = [];
    $seen_attachment = [];
    foreach ($page_ids as $wid) {
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
    return [
        'candidates' => $missing,
        'scanned' => count($page_ids),
        'next_cursor' => empty($page_ids) ? max(0, $after_id) : max($page_ids),
        'has_more' => $has_more,
    ];
}

function ll_find_words_missing_word_images(int $after_id = 0): array {
    $batch = ll_word_images_fixer_scan_batch($after_id);
    return (array) ($batch['candidates'] ?? []);
}

function ll_create_word_image_from_word($word_id, $attachment_id) {
    if (!function_exists('ll_tools_ensure_word_image_post_for_word')) {
        return new WP_Error('missing_helper', __('Word image helper is not available', 'll-tools-text-domain'));
    }

    return ll_tools_ensure_word_image_post_for_word((int) $word_id);
}

function ll_word_images_fixer_process_batch(int $after_id = 0, int $created_total = 0, int $failed_total = 0): array {
    $batch = ll_word_images_fixer_scan_batch($after_id);
    $created = max(0, $created_total);
    $failed = max(0, $failed_total);
    foreach ((array) ($batch['candidates'] ?? []) as $row) {
        $result = ll_create_word_image_from_word((int) ($row['word_id'] ?? 0), (int) ($row['attachment_id'] ?? 0));
        if (is_wp_error($result)) {
            $failed++;
        } else {
            $created++;
        }
    }

    $batch['created_total'] = $created;
    $batch['failed_total'] = $failed;
    return $batch;
}

function ll_render_word_images_fixer_page() {
    if (!current_user_can('manage_options')) { wp_die(__('Permission denied', 'll-tools-text-domain')); }

    $saved_job = get_user_meta(get_current_user_id(), ll_word_images_fixer_job_meta_key(), true);
    $batch = is_array($saved_job) && !empty($saved_job['has_more']) ? $saved_job : null;
    if (isset($_POST['ll_fix_images_action']) && $_POST['ll_fix_images_action'] === 'create' && check_admin_referer('ll_fix_word_images')) {
        $after_id = isset($_POST['ll_fix_images_after_id']) ? max(0, (int) wp_unslash($_POST['ll_fix_images_after_id'])) : 0;
        $created_total = isset($_POST['ll_fix_images_created_total']) ? max(0, (int) wp_unslash($_POST['ll_fix_images_created_total'])) : 0;
        $failed_total = isset($_POST['ll_fix_images_failed_total']) ? max(0, (int) wp_unslash($_POST['ll_fix_images_failed_total'])) : 0;
        $batch = ll_word_images_fixer_process_batch($after_id, $created_total, $failed_total);
        if (!empty($batch['has_more'])) {
            update_user_meta(get_current_user_id(), ll_word_images_fixer_job_meta_key(), [
                'next_cursor' => (int) ($batch['next_cursor'] ?? 0),
                'created_total' => (int) ($batch['created_total'] ?? 0),
                'failed_total' => (int) ($batch['failed_total'] ?? 0),
                'has_more' => true,
                'updated_at' => time(),
            ]);
        } else {
            delete_user_meta(get_current_user_id(), ll_word_images_fixer_job_meta_key());
        }

        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Created %d word image posts.', 'll-tools-text-domain'), (int) ($batch['created_total'] ?? 0))) . '</p></div>';
        if ((int) ($batch['failed_total'] ?? 0) > 0) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('%d items failed.', 'll-tools-text-domain'), (int) $batch['failed_total'])) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Fix Word Images (Legacy Cleanup)', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Scan for words that have a featured image but no corresponding "word_images" post, and create them.', 'll-tools-text-domain'); ?></p>
        <?php if ($batch === null || !empty($batch['has_more'])) : ?>
            <form method="post">
                <?php wp_nonce_field('ll_fix_word_images'); ?>
                <input type="hidden" name="ll_fix_images_action" value="create">
                <input type="hidden" name="ll_fix_images_after_id" value="<?php echo esc_attr((string) ((int) ($batch['next_cursor'] ?? 0))); ?>">
                <input type="hidden" name="ll_fix_images_created_total" value="<?php echo esc_attr((string) ((int) ($batch['created_total'] ?? 0))); ?>">
                <input type="hidden" name="ll_fix_images_failed_total" value="<?php echo esc_attr((string) ((int) ($batch['failed_total'] ?? 0))); ?>">
                <button class="button button-primary">
                    <?php echo esc_html($batch === null ? __('Create Missing Word-Image Posts', 'll-tools-text-domain') : __('Continue', 'll-tools-text-domain')); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
