<?php
/**
 * Migration script to convert word_audio_file meta to word_audio posts
 */

if (!defined('WPINC')) { die; }

/**
 * Register migration admin page
 */
function ll_register_word_audio_migration_page() {
    add_submenu_page(
        'tools.php',
        'Migrate Audio to Word Audio Posts',
        'LL Audio Migration',
        'manage_options',
        'll-audio-migration',
        'll_render_audio_migration_page'
    );
}
add_action('admin_menu', 'll_register_word_audio_migration_page');

/**
 * Render migration page
 */
function ll_render_audio_migration_page() {
    $stats = ll_get_migration_stats();

    ?>
    <div class="wrap">
        <h1>Migrate Audio Files to Word Audio Posts</h1>
        <p>This tool will convert existing word_audio_file metadata into proper word_audio posts.</p>

        <div class="ll-migration-stats">
            <h2>Current Status</h2>
            <ul>
                <li><strong>Words with audio meta:</strong> <?php echo $stats['words_with_meta']; ?></li>
                <li><strong>Existing word_audio posts:</strong> <?php echo $stats['existing_audio_posts']; ?></li>
                <li><strong>Words needing migration:</strong> <?php echo $stats['needs_migration']; ?></li>
            </ul>
        </div>

        <?php if ($stats['needs_migration'] > 0): ?>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('ll_migrate_audio', 'll_migrate_nonce'); ?>
                <input type="hidden" name="action" value="ll_migrate_audio">
                <p>
                    <input type="submit" class="button button-primary" value="Start Migration">
                </p>
                <p class="description">
                    This will create word_audio posts for all words that have audio files but no word_audio posts yet.
                    The original metadata will be preserved for backward compatibility.
                </p>
            </form>
        <?php else: ?>
            <p><strong>No migration needed.</strong> All audio files have been migrated.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Get migration statistics
 */
function ll_get_migration_stats() {
    global $wpdb;

    // Count words with audio meta
    $words_with_meta = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id)
        FROM {$wpdb->postmeta}
        WHERE meta_key = 'word_audio_file'
        AND meta_value != ''
    ");

    // Count existing word_audio posts
    $existing_audio_posts = wp_count_posts('word_audio');
    $existing_audio_posts = $existing_audio_posts->publish + $existing_audio_posts->draft;

    // Count words that need migration (have audio meta but no word_audio children)
    $needs_migration = $wpdb->get_var("
        SELECT COUNT(DISTINCT pm.post_id)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = 'word_audio_file'
        AND pm.meta_value != ''
        AND p.post_type = 'words'
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->posts} child
            WHERE child.post_parent = pm.post_id
            AND child.post_type = 'word_audio'
        )
    ");

    return [
        'words_with_meta' => (int)$words_with_meta,
        'existing_audio_posts' => (int)$existing_audio_posts,
        'needs_migration' => (int)$needs_migration,
    ];
}

/**
 * Process migration
 */
add_action('admin_post_ll_migrate_audio', 'll_process_audio_migration');
function ll_process_audio_migration() {
    if (!current_user_can('manage_options') ||
        !check_admin_referer('ll_migrate_audio', 'll_migrate_nonce')) {
        wp_die('Permission denied');
    }

    global $wpdb;

    // Get all words with audio that need migration
    $words = $wpdb->get_results("
        SELECT pm.post_id, pm.meta_value as audio_path, p.post_title
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = 'word_audio_file'
        AND pm.meta_value != ''
        AND p.post_type = 'words'
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->posts} child
            WHERE child.post_parent = pm.post_id
            AND child.post_type = 'word_audio'
        )
    ");

    $migrated = 0;
    $failed = 0;

    foreach ($words as $word) {
        try {
            // Create word_audio post
            $audio_post_id = wp_insert_post([
                'post_title' => $word->post_title,
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word->post_id,
            ]);

            if (is_wp_error($audio_post_id)) {
                $failed++;
                continue;
            }

            // Store audio metadata
            update_post_meta($audio_post_id, 'audio_file_path', $word->audio_path);
            update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
            update_post_meta($audio_post_id, '_ll_migrated_from_meta', '1');

            // Set default recording type
            wp_set_object_terms($audio_post_id, 'isolation', 'recording_type');

            $migrated++;

        } catch (Exception $e) {
            $failed++;
            error_log('Audio migration failed for word ' . $word->post_id . ': ' . $e->getMessage());
        }
    }

    $redirect = add_query_arg([
        'page' => 'll-audio-migration',
        'migrated' => $migrated,
        'failed' => $failed,
    ], admin_url('tools.php'));

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Show migration results
 */
add_action('admin_notices', 'll_show_migration_results');
function ll_show_migration_results() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'll-audio-migration') {
        return;
    }

    if (isset($_GET['migrated'])) {
        $migrated = (int)$_GET['migrated'];
        $failed = isset($_GET['failed']) ? (int)$_GET['failed'] : 0;

        if ($migrated > 0) {
            printf(
                '<div class="notice notice-success"><p>Successfully migrated %d audio file(s) to word_audio posts.</p></div>',
                $migrated
            );
        }

        if ($failed > 0) {
            printf(
                '<div class="notice notice-error"><p>Failed to migrate %d audio file(s).</p></div>',
                $failed
            );
        }
    }
}