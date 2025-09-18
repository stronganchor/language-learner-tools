<?php
/**
 * Auto-create normal WP Pages for each "word-category" term,
 * embedding the existing minimal quiz view (/embed/{slug}) in an iframe.
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Build the page content that embeds the category's embeddable quiz page.
 *
 * @param WP_Term $term
 * @return string
 */
function ll_tools_build_quiz_page_content($term) {
    $src = home_url('/embed/' . $term->slug);

    // Allow overrides via filters (defaults: 400px min, 70vh height)
    $min_px = (int) apply_filters('ll_tools_quiz_iframe_min_height', 400);
    $vh     = (int) apply_filters('ll_tools_quiz_iframe_vh', 95);

    $html  = '<div class="ll-tools-quiz-iframe-wrapper" style="position:relative;width:100%;min-height:' . $min_px . 'px;">';
    $html .= '<iframe src="' . esc_url($src) . '"'
          .  ' style="position:relative;width:100%;height:' . $vh . 'vh;min-height:' . $min_px . 'px;border:0;"'
          .  ' loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>';
    $html .= '</div>';

    return $html;
}

/**
 * Ensure the parent "quiz" page exists and return its ID.
 * Allows overrides using the 'll_tools_quiz_parent_slug' filter.
 *
 * @return int Parent page ID (0 if not available).
 */
function ll_tools_get_or_create_quiz_parent_page() {
    $parent_slug = sanitize_title(apply_filters('ll_tools_quiz_parent_slug', 'quiz'));

    // Try exact root-level page by slug
    $parent = get_page_by_path($parent_slug);
    if ($parent instanceof WP_Post && $parent->post_type === 'page') {
        return (int) $parent->ID;
    }

    // Fallback: query strictly for a root-level page named "quiz"
    $candidates = get_posts(array(
        'name'        => $parent_slug,
        'post_type'   => 'page',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'numberposts' => 1,
        'post_parent' => 0,
        'fields'      => 'all',
    ));
    if (!empty($candidates) && $candidates[0] instanceof WP_Post) {
        return (int) $candidates[0]->ID;
    }

    // Create it if missing
    $parent_id = wp_insert_post(array(
        'post_title'   => ucfirst($parent_slug),
        'post_name'    => $parent_slug,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => 0,
    ), true);

    return is_wp_error($parent_id) ? 0 : (int) $parent_id;
}

/**
 * Create or update the WP Page for a given category term as a *child* of /quiz/.
 *
 * @param int $term_id
 * @return int|WP_Error Post ID or error
 */
function ll_tools_get_or_create_quiz_page_for_category($term_id) {
    $term = get_term($term_id, 'word-category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('invalid_term', 'Invalid word-category term.');
    }

    $parent_id  = ll_tools_get_or_create_quiz_parent_page();
    $child_slug = apply_filters('ll_tools_quiz_child_slug', sanitize_title($term->slug), $term);

    // Find by our meta marker
    $existing = get_posts(array(
        'post_type'   => 'page',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'meta_key'    => '_ll_tools_word_category_id',
        'meta_value'  => (string) $term->term_id,
        'numberposts' => 1,
        'fields'      => 'ids',
    ));
    $post_id = $existing ? (int) $existing[0] : 0;

    $title   = $term->name;
    $content = ll_tools_build_quiz_page_content($term);

    if ($post_id) {
        $existing_post = get_post($post_id);
        if (!$existing_post) {
            return new WP_Error('missing_post', 'Could not load existing quiz page.');
        }

        // Ensure correct parent and slug
        $needs_parent = ((int) $existing_post->post_parent !== (int) $parent_id);
        $needs_slug   = ($existing_post->post_name !== $child_slug);

        $update = array(
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        );

        if ($needs_parent) {
            $update['post_parent'] = $parent_id;
        }
        if ($needs_slug || $needs_parent) {
            $update['post_name'] = wp_unique_post_slug(
                $child_slug,
                $post_id,
                $existing_post->post_status,
                'page',
                $parent_id
            );
        }

        return wp_update_post($update, true);
    }

    // New page under /quiz/
    $unique_slug = wp_unique_post_slug($child_slug, 0, 'publish', 'page', $parent_id);
    $postarr = array(
        'post_title'   => $title,
        'post_name'    => $unique_slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
    );

    $post_id = wp_insert_post($postarr, true);
    if (!is_wp_error($post_id)) {
        update_post_meta($post_id, '_ll_tools_word_category_id', (string) $term->term_id);
    }

    return $post_id;
}

/**
 * Handle category create/edit to sync its page.
 *
 * @param int $term_id
 * @return void
 */
function ll_tools_handle_category_sync($term_id) {
    // Only operate on our taxonomy
    $term = get_term($term_id);
    if (!$term || is_wp_error($term) || $term->taxonomy !== 'word-category') {
        return;
    }

    if (ll_can_category_generate_quiz($term)) {
        // Create/update page for valid categories
        ll_tools_get_or_create_quiz_page_for_category($term_id);
    } else {
        // Remove page if category can no longer generate a quiz
        $existing = get_posts(array(
            'post_type'   => 'page',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'meta_key'    => '_ll_tools_word_category_id',
            'meta_value'  => (string) $term_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ));
        if ($existing) {
            wp_trash_post((int)$existing[0]);
        }
    }
}

/**
 * When a category is deleted, trash the associated Page if present.
 *
 * @param int $term_id
 * @return void
 */
function ll_tools_handle_category_delete($term_id) {
    $existing = get_posts(array(
        'post_type'   => 'page',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'meta_key'    => '_ll_tools_word_category_id',
        'meta_value'  => (string) $term_id,
        'numberposts' => 1,
        'fields'      => 'ids',
    ));
    if ($existing) {
        $post_id = (int)$existing[0];
        wp_trash_post($post_id);
    }
}

/**
 * Backfill all pages for current categories.
 *
 * @return void
 */
function ll_tools_sync_quiz_pages() {
    // First, clean up invalid pages (more aggressively)
    $removed = ll_tools_cleanup_invalid_quiz_pages();

    // Log the cleanup for debugging
    error_log("LL Tools: Cleaned up $removed invalid quiz pages");

    // Then create/update pages for valid categories
    $terms = get_terms(array(
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'all',
    ));
    if (is_wp_error($terms)) {
        return;
    }
    foreach ($terms as $term) {
        // Only create pages for categories that can generate valid quizzes
        if (ll_can_category_generate_quiz($term)) {
            ll_tools_get_or_create_quiz_page_for_category($term->term_id);
        }
    }

    // Force update the sync timestamp to ensure it runs
    update_option('ll_tools_quiz_page_sync_last', time(), false);
}

/**
 * Register hooks for taxonomy changes and (optionally) plugin activation.
 *
 * @param string $main_file  Path to the main plugin file for activation hook.
 * @return void
 */
function ll_tools_register_autopage_activation($main_file) {
    // Sync once on plugin activation so existing categories get pages.
    if (function_exists('register_activation_hook')) {
        register_activation_hook($main_file, 'll_tools_sync_quiz_pages');
    }
}

// Sync when categories are created/edited/deleted.
add_action('created_word-category', 'll_tools_handle_category_sync', 10, 1);
add_action('edited_word-category',  'll_tools_handle_category_sync', 10, 1);
add_action('delete_word-category',  'll_tools_handle_category_delete', 10, 1);

// Optional safety net: ensure pages exist and migrate mis-parented ones (admin-only, once/day).
add_action('admin_init', function () {
    $key = 'll_tools_quiz_page_sync_last';
    $last = (int) get_option($key, 0);
    if ($last < (time() - DAY_IN_SECONDS)) {
        ll_tools_sync_quiz_pages();
        update_option($key, time(), false);
    }
});

/**
 * Suppress the page title display for auto-generated quiz pages.
 */
add_filter('the_title', function ($title, $post_id) {
    if (is_admin()) {
        return $title;
    }
    if (get_post_type($post_id) === 'page' && get_post_meta($post_id, '_ll_tools_word_category_id', true)) {
        // Return empty string so themes won’t render the title.
        return '';
    }
    return $title;
}, 10, 2);

/**
 * Removes pages for categories that can no longer generate valid quizzes.
 *
 * @return int Number of pages removed.
 */
function ll_tools_cleanup_invalid_quiz_pages() {
    $removed_count = 0;

    // Find all existing quiz pages
    $existing_pages = get_posts(array(
        'post_type'   => 'page',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'meta_key'    => '_ll_tools_word_category_id',
        'numberposts' => -1,
        'fields'      => 'all',
    ));

    foreach ($existing_pages as $page) {
        $term_id = get_post_meta($page->ID, '_ll_tools_word_category_id', true);
        $term = get_term($term_id, 'word-category');

        // If the term doesn't exist or can't generate a quiz, remove the page
        if (!$term || is_wp_error($term) || !ll_can_category_generate_quiz($term)) {
            wp_trash_post($page->ID);
            $removed_count++;
        }
    }

    return $removed_count;
}

/**
 * Adds a manual cleanup button to the Word Categories admin page.
 */
function ll_tools_add_manual_cleanup_button() {
    $screen = get_current_screen();
    if ('edit-word-category' !== $screen->id) {
        return;
    }

    // Handle cleanup action
    if (isset($_POST['ll_cleanup_quiz_pages']) && wp_verify_nonce($_POST['ll_cleanup_nonce'], 'll_cleanup_quiz_pages')) {
        $removed_count = ll_tools_cleanup_invalid_quiz_pages();
        ll_tools_sync_quiz_pages(); // Also sync valid pages

        printf(
            '<div class="notice notice-success"><p>Quiz page cleanup completed. %d invalid pages removed and valid pages synced.</p></div>',
            $removed_count
        );
    }

    // Display the cleanup form
    ?>
    <div class="wrap">
        <h2>Quiz Page Management</h2>
        <p>Clean up quiz pages for categories that can no longer generate valid quizzes and sync pages for valid categories.</p>
        <form method="post" action="">
            <?php wp_nonce_field('ll_cleanup_quiz_pages', 'll_cleanup_nonce'); ?>
            <input type="submit" name="ll_cleanup_quiz_pages" class="button button-secondary" value="Clean Up & Sync Quiz Pages">
        </form>
    </div>
    <?php
}
add_action('admin_notices', 'll_tools_add_manual_cleanup_button');

/**
 * Force immediate cleanup of invalid quiz pages.
 * Can be triggered by visiting: /wp-admin/?ll_force_quiz_cleanup=1
 */
function ll_tools_force_quiz_cleanup() {
    if (is_admin() && isset($_GET['ll_force_quiz_cleanup']) && current_user_can('manage_options')) {
        $removed = ll_tools_cleanup_invalid_quiz_pages();
        ll_tools_sync_quiz_pages();

        wp_die(
            sprintf(
                'Quiz page cleanup completed. %d invalid pages removed. <a href="%s">Go to Pages</a>',
                $removed,
                admin_url('edit.php?post_type=page')
            )
        );
    }
}
add_action('admin_init', 'll_tools_force_quiz_cleanup', 5);