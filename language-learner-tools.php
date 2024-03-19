<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 1.5.0
 * Text Domain: ll-tools-text-domain
 * Domain Path: /languages
 *
 * This plugin is designed to enhance the display of vocabulary items in a custom
 * post type called 'words'. It adds the English meaning and an audio file to each post.
 *
 * The plugin includes the following main features:
 * - Custom post types: 'words' for vocabulary items and 'word_images' for associated images
 * - Custom taxonomy: 'word-category' for categorizing vocabulary items
 * - Shortcodes: [word_grid], [flashcard_widget], [audio_upload_form], [image_upload_form]
 * - Integration with the DeepL API for automatic translation
 * - Bulk audio and image upload functionality
 * - Flashcard quiz widget for practicing vocabulary
 * - Audio playback and translation display using the [word_audio] shortcode
 *
 * The plugin is organized into multiple files for better code separation and maintainability:
 * - language-learner-tools.php: Main plugin file, includes other files and registers activation/deactivation hooks
 * - words-post-type.php: Registers the 'words' and 'word_images' post types and the 'word-category' taxonomy
 * - deepl-api.php: Handles the integration with the DeepL API for automatic translation
 * - language-switcher.php: Provides language switching functionality
 * - audio-upload-form.php: Implements the bulk audio upload functionality and the [audio_upload_form] shortcode
 * - image-upload-form.php: Implements the bulk image upload functionality and the [image_upload_form] shortcode
 * - flashcard-widget.php: Implements the flashcard quiz widget and the [flashcard_widget] shortcode
 * - word-audio-shortcode.php: Implements the [word_audio] shortcode for audio playback and translation display
 *
 * The plugin uses a combination of PHP, JavaScript (jQuery), and CSS to provide its functionality.
 * It interacts with the WordPress database to store and retrieve vocabulary items, categories, and user progress.
 *
 * Key concepts and techniques used in the plugin:
 * - Custom post types and taxonomies
 * - Shortcodes for displaying vocabulary grids, flashcard quizzes, and audio playback
 * - AJAX for saving and retrieving user progress in the flashcard quiz
 * - Integration with external APIs (DeepL) for automatic translation
 * - File uploads and handling for bulk audio and image uploads
 * - Custom admin pages and settings for configuring the plugin
 * - Enqueuing custom styles and scripts
 * - Internationalization and localization support
 *
 * The plugin follows WordPress coding standards and best practices, using hooks and filters to extend functionality.
 * It also includes error handling and security measures to ensure safe and reliable operation.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include other PHP files for this plugin
include(plugin_dir_path(__FILE__) . 'words-post-type.php');
include(plugin_dir_path(__FILE__) . 'deepl-api.php');
include(plugin_dir_path(__FILE__) . 'language-switcher.php');
include(plugin_dir_path(__FILE__) . 'audio-upload-form.php');
include(plugin_dir_path(__FILE__) . 'image-upload-form.php');
include(plugin_dir_path(__FILE__) . 'flashcard-widget.php');
include(plugin_dir_path(__FILE__) . 'word-audio-shortcode.php');

function ll_tools_activate() {
    // Code to execute on plugin activation
}
register_activation_hook(__FILE__, 'll_tools_activate');

function ll_tools_deactivate() {
    // Code to execute on plugin deactivation
}
register_deactivation_hook(__FILE__, 'll_tools_deactivate');

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

/**
 * Enqueue plugin styles and scripts.
 */
function ll_tools_enqueue_assets() {
    $css_file = plugins_url('language-learner-tools.css', __FILE__);
    $css_version = filemtime(plugin_dir_path(__FILE__) . 'language-learner-tools.css');
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

/**
 * Register the shortcodes with WordPress.
 */
function ll_tools_register_shortcodes() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
	add_shortcode('flashcard_widget', 'll_tools_flashcard_widget');
}
add_action('init', 'll_tools_register_shortcodes');

/**
 * The callback function for the 'word_grid' shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content to display the grid.
 */
function ll_tools_word_grid_shortcode($atts) {
    // Shortcode attributes with defaults
    $atts = shortcode_atts(array(
        'category' => '', // Default category to empty
    ), $atts);

    // Start output buffering
    ob_start();

    // WP_Query arguments
    $args = array(
        'post_type' => 'words',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_thumbnail_id', // Checks if the post has a featured image.
                'compare' => 'EXISTS'
            ),
        ),
        'orderby' => 'date', // Order by date
        'order' => 'ASC', // Ascending order
    );

    // Check if the category attribute is not empty
    if (!empty($atts['category'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'word-category',
                'field' => 'slug',
                'terms' => $atts['category'],
            ),
        );
    }

    // The Query
    $query = new WP_Query($args);

    // The Loop
    if ($query->have_posts()) {
        echo '<div id="word-grid" class="word-grid">'; // Grid container
        while ($query->have_posts()) {
            $query->the_post();
            $word_audio_file = get_post_meta(get_the_ID(), 'word_audio_file', true);
            $word_english_meaning = get_post_meta(get_the_ID(), 'word_english_meaning', true);
            $word_example_sentence = get_post_meta(get_the_ID(), 'word_example_sentence', true);
            $word_example_translation = get_post_meta(get_the_ID(), 'word_example_sentence_translation', true);

            // Individual item
            echo '<div class="word-item">';
            // Featured image with container
			if (has_post_thumbnail()) {
				echo '<div class="word-image-container">'; // Start new container
				echo get_the_post_thumbnail(get_the_ID(), 'full', array('class' => 'word-image'));
				echo '</div>'; // Close container
			}

            // Word title and meaning
            echo '<h3 class="word-title">' . get_the_title() . ' (' . esc_html($word_english_meaning) . ')</h3>';
            // Example sentences
            if ($word_example_sentence && $word_example_translation) {
                echo '<p class="word-example">' . esc_html($word_example_sentence) . '</p>';
                echo '<p class="word-translation"><em>' . esc_html($word_example_translation) . '</em></p>';
            }
            // Audio file
            if ($word_audio_file) {
                echo '<audio controls src="' . esc_url(home_url($word_audio_file)) . '"></audio>';
            }
            echo '</div>'; // End of word-item
        }
        echo '</div>'; // End of word-grid
    } else {
        // No posts found
        echo '<p>No words found in this category.</p>';
    }

    // Restore original Post Data
    wp_reset_postdata();

    // Get the buffer and return it
    return ob_get_clean();
}

?>
