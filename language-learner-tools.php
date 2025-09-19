<?php
/**
 * Plugin Name: Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 2.9.3
 * Text Domain: ll-tools-text-domain
 * Domain Path: /languages
 *
 * This plugin is designed to display vocabulary items in a custom post type called 'words'.
 * It adds the English meaning, an audio file, and associated images to each post.
 *
 * The plugin also includes activities for practicing vocabulary.
 *
 * The plugin uses a combination of PHP, JavaScript (jQuery), and CSS to provide its functionality.
 * It interacts with the WordPress database to store and retrieve vocabulary items, categories, and user progress.
 *
 * Key concepts and techniques used in the plugin:
 * - Custom post types and taxonomies
 * - Shortcodes for displaying vocabulary grids, flashcard quizzes, and audio playback
 * - AJAX for saving and retrieving user progress in the flashcard quiz
 * - Integration with DeepL for automatic translation
 * - Handling for bulk audio and image uploads and generating posts from uploaded files
 * - Custom admin pages and settings for configuring the plugin
 * - Enqueuing custom styles and scripts
 * - Internationalization and localization support
 *
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Base URL/path for this plugin (safe to use from nested files)
if (!defined('LL_TOOLS_BASE_URL')) {
    define('LL_TOOLS_BASE_URL', plugin_dir_url(__FILE__));
}
if (!defined('LL_TOOLS_BASE_PATH')) {
    define('LL_TOOLS_BASE_PATH', plugin_dir_path(__FILE__));
}

// Include custom post types
require_once(LL_TOOLS_BASE_PATH . 'post-types/words-post-type.php');
require_once(LL_TOOLS_BASE_PATH . 'post-types/word-image-post-type.php');

// Include taxonomies
require_once(LL_TOOLS_BASE_PATH . 'taxonomies/word-category-taxonomy.php');
require_once(LL_TOOLS_BASE_PATH . 'taxonomies/wordset-taxonomy.php');
require_once(LL_TOOLS_BASE_PATH . 'taxonomies/language-taxonomy.php');
require_once(LL_TOOLS_BASE_PATH . 'taxonomies/part-of-speech-taxonomy.php');

// Include user roles
require_once(LL_TOOLS_BASE_PATH . 'user-roles/wordset-manager.php');
require_once(LL_TOOLS_BASE_PATH . 'user-roles/ll-tools-editor.php');

// Include admin functionality
require_once(LL_TOOLS_BASE_PATH . 'admin/uploads/audio-upload-form.php');
require_once(LL_TOOLS_BASE_PATH . 'admin/uploads/image-upload-form.php');
require_once(LL_TOOLS_BASE_PATH . 'admin/manage-wordsets.php');
require_once(LL_TOOLS_BASE_PATH . 'admin/missing-audio-admin-page.php');
require_once(LL_TOOLS_BASE_PATH . 'admin/settings.php');

// Include API integrations
require_once(LL_TOOLS_BASE_PATH . 'admin/api/deepl-api.php');

// Include pages
require_once(LL_TOOLS_BASE_PATH . 'pages/auto-quiz-pages.php');
if (function_exists('ll_tools_register_autopage_activation')) {
    ll_tools_register_autopage_activation(__FILE__);
}
// Note: embed-page.php is loaded via template_include filter, not require

// Include shortcodes
require_once(LL_TOOLS_BASE_PATH . 'shortcodes/flashcard-widget.php');
require_once(LL_TOOLS_BASE_PATH . 'shortcodes/word-audio-shortcode.php');
require_once(LL_TOOLS_BASE_PATH . 'shortcodes/word-grid-shortcode.php');
require_once(LL_TOOLS_BASE_PATH . 'shortcodes/image-copyright-grid-shortcode.php');
require_once(LL_TOOLS_BASE_PATH . 'shortcodes/quiz-pages-shortcodes.php');

// Include other utility files
require_once(LL_TOOLS_BASE_PATH . 'language-switcher.php');

// Include the plugin update checker
require_once LL_TOOLS_BASE_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/language-learner-tools',
    __FILE__,
    'language-learner-tools'
);
$myUpdateChecker->setBranch('main');

/**
 * Enqueues the jQuery UI scripts and styles.
 */
function ll_enqueue_jquery_ui() {
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
}

/**
 * Helper function for enqueueing assets using the file timestamp as the version.
 *
 * @param string $relative_path The relative path to the asset file.
 * @param string $handle The unique handle for the asset.
 * @param array $dependencies An array of dependencies.
 * @param bool $include_in_footer Whether to include the script in the footer.
 */
function ll_enqueue_asset_by_timestamp($relative_path, $handle, $dependencies = array(), $include_in_footer = false) {
    $extension = pathinfo($relative_path, PATHINFO_EXTENSION);
    $asset_file = plugins_url($relative_path, __FILE__);
    $asset_version = filemtime(LL_TOOLS_BASE_PATH . $relative_path);

    if ($extension === 'js') {
        wp_enqueue_script($handle, $asset_file, $dependencies, $asset_version, $include_in_footer);
    } elseif ($extension === 'css') {
        wp_enqueue_style($handle, $asset_file, $dependencies, $asset_version);
    } else {
        error_log('Unsupported file type for asset: ' . $relative_path);
    }
}

/**
 * Enqueues the main plugin styles.
 */
function ll_tools_enqueue_assets() {
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style');
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_assets');

/**
 * Enqueues dashboard styles for non-admin users.
 */
function ll_tools_enqueue_styles_for_non_admins() {
    if (current_user_can('manage_options') || current_user_can('view_ll_tools')) {
        return;
    }
    ll_enqueue_asset_by_timestamp('/css/non-admin-style.css', 'll-tools-style');
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_styles_for_non_admins');

/**
 * Adds the 'view_ll_tools' capability to the Administrator role on plugin activation.
 */
register_activation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role && !$role->has_cap('view_ll_tools')) {
        $role->add_cap('view_ll_tools');
    }
});

add_action('admin_init', function () {
    if (current_user_can('manage_options')) {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
});

/**
 * Removes 'view_ll_tools' capability on deactivation
 */
register_deactivation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('view_ll_tools');
    }
});

/**
 * Loads the plugin text domain for internationalization.
 */
function ll_tools_load_textdomain() {
    load_plugin_textdomain('ll-tools-text-domain', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'll_tools_load_textdomain');

/**
 * Enqueues the confetti script in the header.
 */
function ll_tools_enqueue_confetti_script() {
    ?>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    <?php
}
add_action('wp_head', 'll_tools_enqueue_confetti_script');

/* Embed Flashcard Pages - create embeddable quiz pages for each category */
function ll_embed_rewrite_rule() {
    add_rewrite_rule('^embed/([^/]+)/?$', 'index.php?embed_category=$matches[1]', 'top');
}
add_action('init', 'll_embed_rewrite_rule');

function ll_embed_query_vars($vars) {
    $vars[] = 'embed_category';
    return $vars;
}
add_filter('query_vars', 'll_embed_query_vars');

function ll_embed_template_include($template) {
    if (get_query_var('embed_category')) {
        return LL_TOOLS_BASE_PATH . 'pages/embed-page.php';
    }
    return $template;
}
add_filter('template_include', 'll_embed_template_include');

const LL_TOOLS_SETTINGS_SLUG = 'language-learning-tools-settings';

/**
 * Add a "Settings" link on the Plugins screen row for this plugin.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (is_network_admin()) {
        return $links;
    }

    $url = admin_url('options-general.php?page=' . LL_TOOLS_SETTINGS_SLUG);
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'll-tools') . '</a>';

    array_unshift($links, $settings_link);
    return $links;
});

// Auto-resync quiz pages when the builder source changes
add_action('admin_init', function () {
    if ( ! current_user_can('manage_options') ) return;

    $file = LL_TOOLS_BASE_PATH . 'pages/auto-quiz-pages.php';
    if ( ! file_exists($file) ) return;

    $current_mtime = (int) filemtime($file);
    if ( ! $current_mtime ) return;

    $opt_key   = 'll_tools_autopage_source_mtime';
    $last_mtime = (int) get_option($opt_key, 0);

    $force = ( isset($_GET['lltools-resync']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lltools-resync') );

    if ( ! $force && $current_mtime === $last_mtime ) return;

    if ( get_transient('ll_tools_autopage_resync_running') ) return;
    set_transient('ll_tools_autopage_resync_running', 1, 5 * MINUTE_IN_SECONDS);

    if ( ! function_exists('ll_tools_handle_category_sync') ) {
        delete_transient('ll_tools_autopage_resync_running');
        return;
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);
    if ( ! is_wp_error($terms) ) {
        foreach ($terms as $t) {
            ll_tools_handle_category_sync($t->term_id);
        }
    }

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
        if ( ! $term || is_wp_error($term) ) {
            wp_delete_post($pid, true);
        }
    }

    update_option($opt_key, $current_mtime, true);
    delete_transient('ll_tools_autopage_resync_running');

    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[LL Tools] Auto-quiz pages re-synced using handler after source change (mtime=' . $current_mtime . ').');
    }
});

?>