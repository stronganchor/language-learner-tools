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
    // A responsive wrapper so the iframe scales inside any theme layout.
    $html  = '<div class="ll-tools-quiz-iframe-wrapper" style="position:relative;width:100%;min-height:700px;">';
    $html .= '<iframe src="' . esc_url($src) . '"'
          .  ' style="position:relative;width:100%;height:80vh;min-height:700px;border:0;"'
          .  ' loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>';
    $html .= '</div>';
    return $html;
}

/**
 * Create or update the WP Page for a given category term.
 *
 * @param int $term_id
 * @return int|WP_Error Post ID or error
 */
function ll_tools_get_or_create_quiz_page_for_category($term_id) {
    $term = get_term($term_id, 'word-category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('invalid_term', 'Invalid word-category term.');
    }

    // Try to find an existing page by our meta marker.
    $existing = get_posts(array(
        'post_type'   => 'page',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'meta_key'    => '_ll_tools_word_category_id',
        'meta_value'  => (string) $term->term_id,
        'numberposts' => 1,
        'fields'      => 'ids',
    ));
    $post_id = $existing ? (int)$existing[0] : 0;

    $title   = $term->name;
    $slug    = sanitize_title('quiz-' . $term->slug);
    $content = ll_tools_build_quiz_page_content($term);

    if ($post_id) {
        // Update existing page if the title/content changed (keep slug stable once created).
        $update = array(
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        );
        $post_id = wp_update_post($update, true);
    } else {
        // Ensure slug uniqueness but prefer "quiz-{slug}".
        $unique_slug = wp_unique_post_slug($slug, 0, 'publish', 'page', 0);

        $postarr = array(
            'post_title'   => $title,
            'post_name'    => $unique_slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        );

        $post_id = wp_insert_post($postarr, true);
        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_ll_tools_word_category_id', (string) $term->term_id);
        }
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
    ll_tools_get_or_create_quiz_page_for_category($term_id);
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
    $terms = get_terms(array(
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
    ));
    if (is_wp_error($terms)) {
        return;
    }
    foreach ($terms as $term_id) {
        ll_tools_get_or_create_quiz_page_for_category($term_id);
    }
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

// Optional safety net: ensure pages exist for all categories for admins once per day.
add_action('admin_init', function () {
    $key = 'll_tools_quiz_page_sync_last';
    $last = (int) get_option($key, 0);
    if ($last < (time() - DAY_IN_SECONDS)) {
        ll_tools_sync_quiz_pages();
        update_option($key, time(), false);
    }
});
