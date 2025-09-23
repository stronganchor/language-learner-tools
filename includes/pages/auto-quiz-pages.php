<?php
// /includes/pages/quiz-pages.php
if (!defined('WPINC')) { die; }

/**
 * This single module handles:
 * - Building quiz page HTML (via a template)
 * - Creating/updating/removing pages per word-category
 * - Daily + on-change resync
 * - Admin UI (manual cleanup button / forced cleanup)
 * - Targeted asset enqueue for quiz pages
 */

/** Helpers */
function ll_qp_is_quiz_page_context() : bool {
    if (!is_singular('page')) return false;
    $post = get_post();
    return $post ? (bool) get_post_meta($post->ID, '_ll_tools_word_category_id', true) : false;
}

/** Ensure the parent "/quiz" page exists and return its ID */
function ll_tools_get_or_create_quiz_parent_page() {
    $parent_slug = sanitize_title(apply_filters('ll_tools_quiz_parent_slug', 'quiz'));

    // Try exact root-level page by slug
    $parent = get_page_by_path($parent_slug);
    if ($parent instanceof WP_Post && $parent->post_type === 'page') {
        return (int) $parent->ID;
    }

    // Strict root-level lookup
    $candidates = get_posts([
        'name'        => $parent_slug,
        'post_type'   => 'page',
        'post_status' => ['publish','draft','pending','private'],
        'numberposts' => 1,
        'post_parent' => 0,
        'fields'      => 'all',
    ]);
    if (!empty($candidates) && $candidates[0] instanceof WP_Post) {
        return (int) $candidates[0]->ID;
    }

    // Create it if missing
    $parent_id = wp_insert_post([
        'post_title'   => ucfirst($parent_slug),
        'post_name'    => $parent_slug,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => 0,
    ], true);

    return is_wp_error($parent_id) ? 0 : (int) $parent_id;
}

/** Build HTML via template (fallback inline if loader missing) */
function ll_tools_build_quiz_page_content(WP_Term $term) : string {
    $vh           = (int) apply_filters('ll_tools_quiz_iframe_vh', 95);
    $src          = home_url('/embed/' . $term->slug);
    $display_name = function_exists('ll_tools_get_category_display_name')
        ? ll_tools_get_category_display_name($term)
        : $term->name;

    if (function_exists('ll_tools_render_template')) {
        ob_start();
        ll_tools_render_template('quiz_pages/page.php', [
            'vh'           => $vh,
            'src'          => $src,
            'display_name' => $display_name,
            'slug'         => $term->slug,
        ]);
        return (string) ob_get_clean();
    }

    // Fallback inline rendering (keeps things working if template loader missing)
    ob_start(); ?>
    <div class="ll-tools-quiz-wrapper" style="width:100%;">
      <h1 class="ll-quiz-page-title" style="text-align:center;margin:0 0 10px;"><?php echo esc_html($display_name); ?></h1>
      <div class="ll-tools-quiz-iframe-wrapper" data-quiz-slug="<?php echo esc_attr($term->slug); ?>">
        <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
        <iframe class="ll-tools-quiz-iframe"
                src="<?php echo esc_url($src); ?>"
                loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
    </div>
    <?php return (string) ob_get_clean();
}

/** Create or update the page for a category */
function ll_tools_get_or_create_quiz_page_for_category($term_id) {
    $term = get_term($term_id, 'word-category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('invalid_term', 'Invalid word-category term.');
    }

    $parent_id  = ll_tools_get_or_create_quiz_parent_page();
    $child_slug = apply_filters('ll_tools_quiz_child_slug', sanitize_title($term->slug), $term);

    // Find by our meta marker
    $existing = get_posts([
        'post_type'   => 'page',
        'post_status' => ['publish','draft','pending','private'],
        'meta_key'    => '_ll_tools_word_category_id',
        'meta_value'  => (string) $term->term_id,
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);
    $post_id = $existing ? (int) $existing[0] : 0;

    $title   = $term->name;
    $content = ll_tools_build_quiz_page_content($term);

    if ($post_id) {
        $existing_post = get_post($post_id);
        if (!$existing_post) return new WP_Error('missing_post', 'Could not load existing quiz page.');

        $needs_parent = ((int) $existing_post->post_parent !== (int) $parent_id);
        $needs_slug   = ($existing_post->post_name !== $child_slug);

        $update = [
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ];
        if ($needs_parent) $update['post_parent'] = $parent_id;
        if ($needs_slug || $needs_parent) {
            $update['post_name'] = wp_unique_post_slug(
                $child_slug, $post_id, $existing_post->post_status, 'page', $parent_id
            );
        }
        return wp_update_post($update, true);
    }

    // New under /quiz/
    $unique_slug = wp_unique_post_slug($child_slug, 0, 'publish', 'page', $parent_id);
    $postarr = [
        'post_title'   => $title,
        'post_name'    => $unique_slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
    ];
    $post_id = wp_insert_post($postarr, true);
    if (!is_wp_error($post_id)) {
        update_post_meta($post_id, '_ll_tools_word_category_id', (string) $term->term_id);
    }
    return $post_id;
}

/** Create/update or remove a page when a category changes */
function ll_tools_handle_category_sync($term_id) {
    $term = get_term($term_id);
    if (!$term || is_wp_error($term) || $term->taxonomy !== 'word-category') return;

    $ok = function_exists('ll_can_category_generate_quiz') ? ll_can_category_generate_quiz($term) : true;
    if ($ok) {
        ll_tools_get_or_create_quiz_page_for_category($term_id);
    } else {
        $existing = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish','draft','pending','private'],
            'meta_key'    => '_ll_tools_word_category_id',
            'meta_value'  => (string) $term_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if ($existing) wp_trash_post((int) $existing[0]);
    }
}

function ll_tools_handle_category_delete($term_id) {
    $existing = get_posts([
        'post_type'   => 'page',
        'post_status' => ['publish','draft','pending','private'],
        'meta_key'    => '_ll_tools_word_category_id',
        'meta_value'  => (string) $term_id,
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);
    if ($existing) wp_trash_post((int) $existing[0]);
}

/** Remove pages for categories that can no longer generate valid quizzes. */
function ll_tools_cleanup_invalid_quiz_pages() : int {
    $removed = 0;
    $pages = get_posts([
        'post_type'   => 'page',
        'post_status' => ['publish','draft','pending','private'],
        'meta_key'    => '_ll_tools_word_category_id',
        'numberposts' => -1,
        'fields'      => 'all',
    ]);
    foreach ($pages as $p) {
        $term_id = get_post_meta($p->ID, '_ll_tools_word_category_id', true);
        $term    = get_term($term_id, 'word-category');
        $ok      = $term && !is_wp_error($term) && (function_exists('ll_can_category_generate_quiz') ? ll_can_category_generate_quiz($term) : true);
        if (!$ok) { wp_trash_post($p->ID); $removed++; }
    }
    return $removed;
}

/** Full backfill of all pages */
function ll_tools_sync_quiz_pages() {
    $removed = ll_tools_cleanup_invalid_quiz_pages();
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("LL Tools: Cleaned up $removed invalid quiz pages");
    }

    $terms = get_terms(['taxonomy' => 'word-category', 'hide_empty' => false, 'fields' => 'all']);
    if (is_wp_error($terms)) return;

    foreach ($terms as $term) {
        $ok = function_exists('ll_can_category_generate_quiz') ? ll_can_category_generate_quiz($term) : true;
        if ($ok) ll_tools_get_or_create_quiz_page_for_category($term->term_id);
    }
    update_option('ll_tools_quiz_page_sync_last', time(), false);
}

/** Wire term create/edit/delete to sync */
add_action('created_word-category', 'll_tools_handle_category_sync', 10, 1);
add_action('edited_word-category',  'll_tools_handle_category_sync', 10, 1);
add_action('delete_word-category',  'll_tools_handle_category_delete', 10, 1);

/** Daily safety net (admin only) */
add_action('admin_init', function () {
    $last = (int) get_option('ll_tools_quiz_page_sync_last', 0);
    if ($last < (time() - DAY_IN_SECONDS)) {
        ll_tools_sync_quiz_pages();
        update_option('ll_tools_quiz_page_sync_last', time(), false);
    }
});

/** Keep pages in sync when content in those categories changes */
function ll_tools_sync_categories_for_post($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!in_array($post->post_type, ['words','word_images'], true)) return;
    if ($post->post_status !== 'publish') return;

    $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) return;
    foreach ($term_ids as $tid) ll_tools_handle_category_sync((int) $tid);
}
add_action('save_post_words',       'll_tools_sync_categories_for_post', 10, 3);
add_action('save_post_word_images', 'll_tools_sync_categories_for_post', 10, 3);

function ll_tools_sync_categories_on_term_set($object_id, $terms, $tt_ids, $taxonomy) {
    if ($taxonomy !== 'word-category') return;
    $post = get_post($object_id);
    if (!$post || !in_array($post->post_type, ['words','word_images'], true)) return;
    foreach (array_map('intval', (array) $terms) as $tid) ll_tools_handle_category_sync($tid);
}
add_action('set_object_terms', 'll_tools_sync_categories_on_term_set', 10, 4);

function ll_tools_sync_categories_before_delete($post_id) {
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['words','word_images'], true)) return;
    $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids)) return;
    foreach ($term_ids as $tid) ll_tools_handle_category_sync((int) $tid);
}
add_action('before_delete_post', 'll_tools_sync_categories_before_delete');

/** Hide the title on these auto pages */
add_filter('the_title', function ($title, $post_id) {
    if (is_admin()) return $title;
    return (get_post_type($post_id) === 'page' && get_post_meta($post_id, '_ll_tools_word_category_id', true)) ? '' : $title;
}, 10, 2);

/** Enqueue assets only on quiz pages */
function ll_qp_enqueue_assets() {
    if (!ll_qp_is_quiz_page_context()) return;

    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/css/quiz-pages.css', 'll-quiz-pages-css');
        ll_enqueue_asset_by_timestamp('/js/quiz-pages.js',   'll-quiz-pages-js', [], true);
    } else {
        wp_enqueue_style('ll-quiz-pages-css', plugins_url('css/quiz-pages.css', LL_TOOLS_MAIN_FILE), [], null);
        wp_enqueue_script('ll-quiz-pages-js', plugins_url('js/quiz-pages.js', LL_TOOLS_MAIN_FILE), [], null, true);
    }
    wp_localize_script('ll-quiz-pages-js', 'llQuizPages', [
        'vh' => (int) apply_filters('ll_tools_quiz_iframe_vh', 95),
    ]);
}
add_action('wp_enqueue_scripts', 'll_qp_enqueue_assets');

/** Manual cleanup UI on the word-category admin screen */
function ll_tools_add_manual_cleanup_button() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'edit-word-category') return;

    if (isset($_POST['ll_cleanup_quiz_pages']) && wp_verify_nonce($_POST['ll_cleanup_nonce'] ?? '', 'll_cleanup_quiz_pages')) {
        $removed = ll_tools_cleanup_invalid_quiz_pages();
        ll_tools_sync_quiz_pages();
        printf('<div class="notice notice-success"><p>Quiz page cleanup completed. %d invalid pages removed and valid pages synced.</p></div>', $removed);
    }

    echo '<div class="wrap"><h2>Quiz Page Management</h2>
          <p>Clean up quiz pages for categories that can no longer generate valid quizzes and sync pages for valid categories.</p>
          <form method="post">';
    wp_nonce_field('ll_cleanup_quiz_pages', 'll_cleanup_nonce');
    echo '<input type="submit" name="ll_cleanup_quiz_pages" class="button button-secondary" value="Clean Up & Sync Quiz Pages"></form></div>';
}
add_action('admin_notices', 'll_tools_add_manual_cleanup_button');

/** Force cleanup via query param (admin only) */
function ll_tools_force_quiz_cleanup() {
    if (!is_admin() || !isset($_GET['ll_force_quiz_cleanup']) || !current_user_can('manage_options')) return;
    $removed = ll_tools_cleanup_invalid_quiz_pages();
    ll_tools_sync_quiz_pages();
    wp_die(sprintf(
        'Quiz page cleanup completed. %d invalid pages removed. <a href="%s">Go to Pages</a>',
        $removed,
        admin_url('edit.php?post_type=page')
    ));
}
add_action('admin_init', 'll_tools_force_quiz_cleanup', 5);

/** Resync when source files change (this file, template, JS, CSS) */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    $watch = [
        __FILE__,
        LL_TOOLS_BASE_PATH . 'templates/quiz_pages/page.php',
        LL_TOOLS_BASE_PATH . 'js/quiz-pages.js',
        LL_TOOLS_BASE_PATH . 'css/quiz-pages.css',
    ];
    $current_mtime = 0;
    foreach ($watch as $f) { if (file_exists($f)) { $t = (int) @filemtime($f); if ($t > $current_mtime) $current_mtime = $t; } }
    if (!$current_mtime) return;

    $opt_key    = 'll_tools_autopage_source_mtime';
    $last_mtime = (int) get_option($opt_key, 0);
    $force = ( isset($_GET['lltools-resync']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lltools-resync') );

    if (!$force && $current_mtime === $last_mtime) return;
    if (get_transient('ll_tools_autopage_resync_running')) return;

    set_transient('ll_tools_autopage_resync_running', 1, 5 * MINUTE_IN_SECONDS);

    // Run sync
    $terms = get_terms(['taxonomy' => 'word-category','hide_empty' => false]);
    if (!is_wp_error($terms)) foreach ($terms as $t) ll_tools_handle_category_sync($t->term_id);

    // Remove orphaned pages
    $orphan_pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish','draft','pending','private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_ll_tools_word_category_id',
    ]);
    foreach ($orphan_pages as $pid) {
        $term_id = (int) get_post_meta($pid, '_ll_tools_word_category_id', true);
        $term    = $term_id ? get_term($term_id, 'word-category') : null;
        if (!$term || is_wp_error($term)) wp_delete_post($pid, true);
    }

    update_option($opt_key, $current_mtime, true);
    delete_transient('ll_tools_autopage_resync_running');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[LL Tools] Auto-quiz pages re-synced after source change (mtime=' . $current_mtime . ').');
    }
});

/** Allow bootstrap to register an activation hook */
function ll_tools_register_autopage_activation($main_file) {
    if (function_exists('register_activation_hook')) {
        register_activation_hook($main_file, 'll_tools_sync_quiz_pages');
    }
}
