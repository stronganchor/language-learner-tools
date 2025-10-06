<?php
// includes/admin/migrate-word-audio-workqueue.php
if (!defined('WPINC')) { die; }

/**
 * Tools → LL Audio Migration (Work Queue)
 * Lists words with legacy 'word_audio_file' meta but no 'word_audio' child.
 * Allows selecting some/all and converting to child posts with a chosen recording_type.
 */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        __('LL Audio Migration (Work Queue)', 'll-tools'),
        __('LL Audio Queue', 'll-tools'),
        'manage_options',
        'll-audio-migration-queue',
        'll_render_audio_migration_queue_page'
    );
});

/**
 * Render the work queue page
 */
function ll_render_audio_migration_queue_page() {
    if (!current_user_can('manage_options')) { wp_die('Permission denied'); }

    global $wpdb;

    // Exact same detection as your existing migrator: 'word_audio_file' and NO child 'word_audio'.
    // (See: includes/admin/migrate-word-audio.php)
    $rows = $wpdb->get_results("
        SELECT pm.post_id, pm.meta_value AS legacy_path, p.post_title
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = 'word_audio_file'
          AND pm.meta_value <> ''
          AND p.post_type = 'words'
          AND p.post_status = 'publish'
          AND NOT EXISTS (
              SELECT 1 FROM {$wpdb->posts} child
              WHERE child.post_parent = pm.post_id
                AND child.post_type = 'word_audio'
          )
        ORDER BY p.post_date DESC
        LIMIT 500
    ");

    // Recording type terms (use your real taxonomy)
    $rec_terms = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);
    if (is_wp_error($rec_terms)) $rec_terms = [];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Audio Migration (Work Queue)', 'll-tools'); ?></h1>
        <p><?php esc_html_e('Select words with legacy audio to convert into child "word_audio" posts.', 'll-tools'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('ll_migrate_audio_queue', 'll_migrate_audio_queue_nonce'); ?>
            <input type="hidden" name="action" value="ll_migrate_audio_queue" />

            <p>
                <label for="ll-recording-type"><strong><?php esc_html_e('Recording type for new audio posts:', 'll-tools'); ?></strong></label>
                <select id="ll-recording-type" name="recording_type" required>
                    <?php
                    // Fall back to known slugs if terms are missing
                    $fallback = ['question','introduction','isolation','sentence'];
                    if (!empty($rec_terms)) {
                        foreach ($rec_terms as $t) {
                            printf('<option value="%s">%s</option>', esc_attr($t->slug), esc_html($t->name . " ({$t->slug})"));
                        }
                    } else {
                        foreach ($fallback as $slug) {
                            printf('<option value="%s">%s</option>', esc_attr($slug), esc_html($slug));
                        }
                    }
                    ?>
                </select>
            </p>

            <?php if (empty($rows)) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('No items found that need migration.', 'll-tools'); ?></p></div>
            <?php else: ?>
                <p>
                    <label><input type="checkbox" id="ll-select-all" /> <?php esc_html_e('Select all on this page', 'll-tools'); ?></label>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:32px;"></th>
                            <th><?php esc_html_e('Word', 'll-tools'); ?></th>
                            <th><?php esc_html_e('Legacy audio path/URL', 'll-tools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><input type="checkbox" class="ll-row-check" name="ids[]" value="<?php echo (int)$r->post_id; ?>" /></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($r->post_id)); ?>">
                                    <?php echo esc_html($r->post_title ?: "(ID {$r->post_id})"); ?>
                                </a>
                            </td>
                            <td><code><?php echo esc_html($r->legacy_path); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:12px;">
                    <button class="button button-primary"><?php esc_html_e('Convert selected', 'll-tools'); ?></button>
                </p>
            <?php endif; ?>
        </form>
    </div>

    <script>
    (function(){
        var selAll = document.getElementById('ll-select-all');
        if (!selAll) return;
        selAll.addEventListener('change', function(){
            document.querySelectorAll('.ll-row-check').forEach(function(cb){ cb.checked = selAll.checked; });
        });
    })();
    </script>
    <?php
}

/**
 * POST handler: create child word_audio for selected IDs.
 */
add_action('admin_post_ll_migrate_audio_queue', function () {
    if (!current_user_can('manage_options') ||
        !check_admin_referer('ll_migrate_audio_queue', 'll_migrate_audio_queue_nonce')) {
        wp_die('Permission denied');
    }

    $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : [];
    $recording_type = isset($_POST['recording_type']) ? sanitize_key($_POST['recording_type']) : 'isolation';

    $migrated = 0; $skipped = 0; $failed = 0;

    foreach ($ids as $word_id) {
        // Skip if a child already exists (matches listing logic)
        $child = get_posts([
            'post_type'      => 'word_audio',
            'post_parent'    => $word_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if (!empty($child)) { $skipped++; continue; }

        $legacy_path = get_post_meta($word_id, 'word_audio_file', true);
        if (!$legacy_path) { $skipped++; continue; }

        $title = get_the_title($word_id);
        $audio_post_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
        ]);

        if (is_wp_error($audio_post_id) || !$audio_post_id) { $failed++; continue; }

        update_post_meta($audio_post_id, 'audio_file_path', $legacy_path);
        update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
        update_post_meta($audio_post_id, '_ll_migrated_from_meta', '1');

        // Assign recording type using your real taxonomy slugs
        if ($recording_type) {
            wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
        }

        $migrated++;
    }

    $url = add_query_arg([
        'page'     => 'll-audio-migration-queue',
        'migrated' => $migrated,
        'skipped'  => $skipped,
        'failed'   => $failed,
    ], admin_url('tools.php'));

    wp_safe_redirect($url);
    exit;
});

/**
 * Show results after redirect
 */
add_action('admin_notices', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'll-audio-migration-queue') return;

    foreach (['migrated' => 'success', 'skipped' => 'warning', 'failed' => 'error'] as $key => $class) {
        if (!isset($_GET[$key])) continue;
        $val = (int) $_GET[$key];
        if ($val > 0) {
            printf('<div class="notice notice-%s"><p>%s: %d</p></div>',
                esc_attr($class),
                esc_html(ucfirst($key)),
                $val
            );
        }
    }
});
