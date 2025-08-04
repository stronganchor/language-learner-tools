<?php
/**
 * Plugin Name: Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 2.8.3
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

// Include custom post types
require_once(plugin_dir_path(__FILE__) . 'post-types/words-post-type.php'); // Registers the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'post-types/word-image-post-type.php'); // Registers the 'word_images' post type to store images associated with words

// Include taxonomies
require_once(plugin_dir_path(__FILE__) . 'taxonomies/word-category-taxonomy.php'); // Registers the 'word-category' taxonomy for the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'taxonomies/wordset-taxonomy.php'); // Registers the 'wordset' taxonomy for the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'taxonomies/language-taxonomy.php'); // Registers the 'language' taxonomy for the 'words' post type and handles language data import
require_once(plugin_dir_path(__FILE__) . 'taxonomies/part-of-speech-taxonomy.php'); // Registers the 'part_of_speech' taxonomy for the 'words' post type

// Include user roles
require_once(plugin_dir_path(__FILE__) . 'user-roles/wordset-manager.php'); // Creates the 'wordset_manager' role for managing word sets
require_once(plugin_dir_path(__FILE__) . 'user-roles/ll-tools-editor.php'); // Creates the 'll_tools_editor' role for managing the plugin's content

// Include pages
require_once(plugin_dir_path(__FILE__) . 'pages/manage-wordsets.php'); // Creates the "Manage Word Sets" page for Word Set Managers
require_once(plugin_dir_path(__FILE__) . 'pages/missing-audio-admin-page.php'); // Creates the "Missing Audio" admin page

// Include other plugin files
require_once(plugin_dir_path(__FILE__) . 'language-switcher.php'); // Provides site language switching functionality
require_once(plugin_dir_path(__FILE__) . 'audio-upload-form.php'); // Creates a form for admins to upload audio files in bulk and generate word posts
require_once(plugin_dir_path(__FILE__) . 'image-upload-form.php'); // Creates a form for admins to upload images in bulk and generate word_image posts
require_once(plugin_dir_path(__FILE__) . 'flashcard-widget.php'); // Implements the [flashcard_widget] shortcode
require_once(plugin_dir_path(__FILE__) . 'word-audio-shortcode.php'); // Implements the [word_audio] shortcode for audio playback and translation display
require_once(plugin_dir_path(__FILE__) . 'word-grid-shortcode.php'); // Implements the [word_grid] shortcode for displaying words along with associated images and audio
require_once plugin_dir_path(__FILE__) . 'image-copyright-grid-shortcode.php';
require_once(plugin_dir_path(__FILE__) . 'settings.php'); // WP Admin settings page for the plugin

// Include API files
require_once(plugin_dir_path(__FILE__) . 'api/deepl-api.php'); // Handles the integration with the DeepL API for automatic translation

// Include the plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/language-learner-tools',
    __FILE__,
    'language-learner-tools'
);
$myUpdateChecker->setBranch('main');
//Optional: If you're using a private repository, specify the access token like this:
//$myUpdateChecker->setAuthentication('your-token-here');

    
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
    $asset_version = filemtime(plugin_dir_path(__FILE__) . $relative_path);

    if ($extension === 'js') {
        wp_enqueue_script($handle, $asset_file, $dependencies, $asset_version, $include_in_footer);
    } elseif ($extension === 'css') {
        wp_enqueue_style($handle, $asset_file, $dependencies, $asset_version);
    } else {
        // Unsupported file type, do something (optional)
        // For example, you could log a warning or handle it differently
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
    // Ensure the user does not have the Administrator role or the LL Tools Editor role (both of which have access to the admin pages)
    if (current_user_can('manage_options') || current_user_can('view_ll_tools')) {
        return;
    }
    ll_enqueue_asset_by_timestamp('/css/non-admin-style.css', 'll-tools-style');
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_styles_for_non_admins');

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
    // Print the canvas-confetti script tag in the header
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
        return plugin_dir_path(__FILE__) . 'pages/embed-page.php';
    }
    return $template;
}
add_filter('template_include', 'll_embed_template_include');

?>
