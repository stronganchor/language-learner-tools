<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 1.6.9
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
 * - Integration with external APIs (DeepL and OpenAI) for automatic translation and transcription
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
include(plugin_dir_path(__FILE__) . 'post-types/words-post-type.php'); // Registers the 'words' post type
include(plugin_dir_path(__FILE__) . 'post-types/word-image-post-type.php'); // Registers the 'word_images' post type to store images associated with words

// Include taxonomies
include(plugin_dir_path(__FILE__) . 'taxonomies/word-category-taxonomy.php'); // Registers the 'word-category' taxonomy for the 'words' post type
include(plugin_dir_path(__FILE__) . 'taxonomies/word-set-taxonomy.php'); // Registers the 'word_set' taxonomy for the 'words' post type
include(plugin_dir_path(__FILE__) . 'taxonomies/language-taxonomy.php'); // Registers the 'language' taxonomy for the 'words' post type and handles language data import
include(plugin_dir_path(__FILE__) . 'taxonomies/part-of-speech-taxonomy.php'); // Registers the 'part_of_speech' taxonomy for the 'words' post type

// Include user roles
include(plugin_dir_path(__FILE__) . 'user-roles/word-set-manager.php'); // Creates the 'word_set_manager' role for managing word sets

// Include pages
include(plugin_dir_path(__FILE__) . 'pages/manage-word-sets.php'); // Creates the "Manage Word Sets" page for Word Set Managers

// Include other plugin files
include(plugin_dir_path(__FILE__) . 'language-switcher.php'); // Provides site language switching functionality
include(plugin_dir_path(__FILE__) . 'audio-upload-form.php'); // Creates a form for admins to upload audio files in bulk and generate word posts
include(plugin_dir_path(__FILE__) . 'image-upload-form.php'); // Creates a form for admins to upload images in bulk and generate word_image posts
include(plugin_dir_path(__FILE__) . 'flashcard-widget.php'); // Implements the [flashcard_widget] shortcode
include(plugin_dir_path(__FILE__) . 'word-audio-shortcode.php'); // Implements the [word_audio] shortcode for audio playback and translation display
include(plugin_dir_path(__FILE__) . 'audio-recorder.php'); // Enables user to record audio and save it to the server along with a transcription
include(plugin_dir_path(__FILE__) . 'word-grid-shortcode.php'); // Implements the [word_grid] shortcode for displaying words along with associated images and audio

// Include API files
include(plugin_dir_path(__FILE__) . 'api/deepl-api.php'); // Handles the integration with the DeepL API for automatic translation
if (!function_exists('transcribe_audio_to_text')) {
    include(plugin_dir_path(__FILE__) . 'api/openai-api.php'); // Handles the integration with the OpenAI API
}

// Include the plugin update checker
require plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/stronganchor/language-learner-tools',
	__FILE__,
	'language-learner-tools'
);
$myUpdateChecker->setBranch('main');
//Optional: If you're using a private repository, specify the access token like this:
//$myUpdateChecker->setAuthentication('your-token-here');


// Enqueue plugin styles and scripts.
function ll_tools_enqueue_assets() {
    $css_file = plugins_url('/css/language-learner-tools.css', __FILE__);
    $css_version = filemtime(plugin_dir_path(__FILE__) . '/css/language-learner-tools.css');
    wp_enqueue_style('ll-tools-style', $css_file, array(), $css_version);
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_assets');

// Load translations for internationalization
function ll_tools_load_textdomain() {
    load_plugin_textdomain('ll-tools-text-domain', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'll_tools_load_textdomain');

function ll_register_settings_page() {
    add_options_page(
        'Language Learning Tools Settings', // Page title
        'Language Learning Tools', // Menu title
        'manage_options', // Capability required to see the page
        'language-learning-tools-settings', // Menu slug
        'll_render_settings_page' // Function to display the settings page
    );
}
add_action('admin_menu', 'll_register_settings_page');

function ll_register_settings() {
    register_setting('language-learning-tools-options', 'll_target_language');
    register_setting('language-learning-tools-options', 'll_translation_language');
}
add_action('admin_init', 'll_register_settings');

function ll_render_settings_page() {
    ?>
    <div class="wrap">
        <h2>Language Learning Tools Settings</h2>
        <form action="options.php" method="post">
            <?php settings_fields('language-learning-tools-options'); ?>
            <?php do_settings_sections('language-learning-tools-settings'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Target Language (e.g., "TR" for Turkish):</th>
                    <td>
                        <input type="text" name="ll_target_language" value="<?php echo esc_attr(get_option('ll_target_language')); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Translation Language (e.g., "EN" for English):</th>
                    <td>
                        <input type="text" name="ll_translation_language" value="<?php echo esc_attr(get_option('ll_translation_language')); ?>" />
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

?>
