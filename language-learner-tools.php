<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 2.1.5
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
require_once(plugin_dir_path(__FILE__) . 'post-types/words-post-type.php'); // Registers the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'post-types/word-image-post-type.php'); // Registers the 'word_images' post type to store images associated with words

// Include taxonomies
require_once(plugin_dir_path(__FILE__) . 'taxonomies/word-category-taxonomy.php'); // Registers the 'word-category' taxonomy for the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'taxonomies/wordset-taxonomy.php'); // Registers the 'wordset' taxonomy for the 'words' post type
require_once(plugin_dir_path(__FILE__) . 'taxonomies/language-taxonomy.php'); // Registers the 'language' taxonomy for the 'words' post type and handles language data import
require_once(plugin_dir_path(__FILE__) . 'taxonomies/part-of-speech-taxonomy.php'); // Registers the 'part_of_speech' taxonomy for the 'words' post type

// Include user roles
require_once(plugin_dir_path(__FILE__) . 'user-roles/wordset-manager.php'); // Creates the 'wordset_manager' role for managing word sets

// Include pages
require_once(plugin_dir_path(__FILE__) . 'pages/manage-wordsets.php'); // Creates the "Manage Word Sets" page for Word Set Managers
require_once(plugin_dir_path(__FILE__) . 'pages/missing-audio-admin-page.php'); // Creates the "Missing Audio" admin page

// Include other plugin files
require_once(plugin_dir_path(__FILE__) . 'language-switcher.php'); // Provides site language switching functionality
require_once(plugin_dir_path(__FILE__) . 'audio-upload-form.php'); // Creates a form for admins to upload audio files in bulk and generate word posts
require_once(plugin_dir_path(__FILE__) . 'image-upload-form.php'); // Creates a form for admins to upload images in bulk and generate word_image posts
require_once(plugin_dir_path(__FILE__) . 'flashcard-widget.php'); // Implements the [flashcard_widget] shortcode
require_once(plugin_dir_path(__FILE__) . 'word-audio-shortcode.php'); // Implements the [word_audio] shortcode for audio playback and translation display
require_once(plugin_dir_path(__FILE__) . 'audio-recorder.php'); // Enables user to record audio and save it to the server along with a transcription
require_once(plugin_dir_path(__FILE__) . 'word-grid-shortcode.php'); // Implements the [word_grid] shortcode for displaying words along with associated images and audio

// Include API files
require_once(plugin_dir_path(__FILE__) . 'api/deepl-api.php'); // Handles the integration with the DeepL API for automatic translation
if (!function_exists('transcribe_audio_to_text')) {  // Only include if the function does not already exist (e.g., in a separate OpenAI API plugin)
    require_once(plugin_dir_path(__FILE__) . 'api/openai-api.php'); // Handles the integration with the OpenAI API
}

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

// Include jQuery UI library for autocomplete
function ll_enqueue_scripts() {
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
}
add_action('wp_enqueue_scripts', 'll_enqueue_scripts');

// Helper function for enqueueing assets using the file timestamp as the version
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

// Enqueue plugin styles
function ll_tools_enqueue_assets() {
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style');
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_assets');

// Enqueue dashboard style for non-admins
function ll_tools_enqueue_styles_for_non_admins() {
    // Ensure the user does not have the Administrator role
    if (current_user_can('manage_options')) {
        return;
    }
    ll_enqueue_asset_by_timestamp('/css/non-admin-style.css', 'll-tools-style');
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_styles_for_non_admins');


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
    register_setting('language-learning-tools-options', 'll_enable_category_translation');
    register_setting('language-learning-tools-options', 'll_category_translation_source');
}
add_action('admin_init', 'll_register_settings');

function ll_render_settings_page() {
    $target_language = get_option('ll_target_language', '');
    $translation_language = get_option('ll_translation_language', '');
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $translation_source = get_option('ll_category_translation_source', 'target');

    // Default to showing placeholders if languages are not set
    $target_language_name = $target_language ? ucfirst($target_language) : __('Target Language', 'll-tools-text-domain');
    $translation_language_name = $translation_language ? ucfirst($translation_language) : __('Translation Language', 'll-tools-text-domain');

    // Options for dropdown
    $options = array(
        'target' => sprintf(__('%s to %s', 'll-tools-text-domain'), $target_language_name, $translation_language_name),
        'translation' => sprintf(__('%s to %s', 'll-tools-text-domain'), $translation_language_name, $target_language_name),
    );
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
                        <input type="text" name="ll_target_language" id="ll_target_language" value="<?php echo esc_attr($target_language); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Translation Language (e.g., "EN" for English):</th>
                    <td>
                        <input type="text" name="ll_translation_language" id="ll_translation_language" value="<?php echo esc_attr($translation_language); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Category Name Translations:</th>
                    <td>
                        <input type="checkbox" name="ll_enable_category_translation" id="ll_enable_category_translation" value="1" <?php checked(1, $enable_translation, true); ?> />
                        <p class="description">Check this box to enable automatic or manual translations of category names.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Translate Category Names From:</th>
                    <td>
                        <select name="ll_category_translation_source" id="ll_category_translation_source" <?php echo ($enable_translation && $target_language && $translation_language) ? '' : 'disabled'; ?>>
                            <option value="target" <?php selected($translation_source, 'target'); ?>>
                                <?php echo esc_html($options['target']); ?>
                            </option>
                            <option value="translation" <?php selected($translation_source, 'translation'); ?>>
                                <?php echo esc_html($options['translation']); ?>
                            </option>
                        </select>
                        <p class="description">Choose whether category names are originally written in <strong><?php echo esc_html($target_language_name); ?></strong> or <strong><?php echo esc_html($translation_language_name); ?></strong>.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <script>
        (function($) {
            // Dynamically enable/disable the dropdown based on settings
            const enableCheckbox = $('#ll_enable_category_translation');
            const targetLangInput = $('#ll_target_language');
            const translationLangInput = $('#ll_translation_language');
            const dropdown = $('#ll_category_translation_source');

            function updateDropdownState() {
                const isEnabled = enableCheckbox.is(':checked');
                const hasLanguages = targetLangInput.val().trim() !== '' && translationLangInput.val().trim() !== '';
                dropdown.prop('disabled', !(isEnabled && hasLanguages));
            }

            // Initial state
            updateDropdownState();

            // Attach event listeners
            enableCheckbox.on('change', updateDropdownState);
            targetLangInput.on('input', updateDropdownState);
            translationLangInput.on('input', updateDropdownState);
        })(jQuery);
    </script>
    <?php
}

add_action('update_option_ll_enable_category_translation', 'll_auto_translate_existing_categories', 10, 3);

function ll_auto_translate_existing_categories($old_value, $new_value, $option_name) {
    if (!$old_value && $new_value) { // Only run when the setting is enabled
        // Fetch all categories in the taxonomy
        $categories = get_terms(array(
            'taxonomy' => 'word-category',
            'hide_empty' => false,
        ));

        // Get translation settings
		$translation_source = get_option('ll_category_translation_source', 'target'); // 'target' or 'translation'

		// Determine source and target languages based on the setting
		$source_language = get_option('ll_target_language');
		$target_language = get_option('ll_translation_language');
		
		if ($translation_source === 'translation') {
			// Translate from the translation language to the target language
			$source_language = get_option('ll_translation_language');
			$target_language = get_option('ll_target_language');
		}

        // Initialize result tracking
        $results = [
            'success' => [],
            'errors' => [],
			'source_language' => $source_language,
            'target_language' => $target_language,
        ];

        // Loop through categories and translate them if necessary
        foreach ($categories as $category) {
            // Check if the category already has a translation
            $translation = get_term_meta($category->term_id, 'term_translation', true);
            if (empty($translation)) {
                // Translate using DeepL
                $translated_name = translate_with_deepl($category->name, $target_language, $source_language);

                if ($translated_name) {
                    update_term_meta($category->term_id, 'term_translation', $translated_name);
                    $results['success'][] = [
                        'original' => $category->name,
                        'translated' => $translated_name,
                    ];
                } else {
                    $results['errors'][] = [
                        'original' => $category->name,
                        'error' => "Translation failed for category ID {$category->term_id} ({$category->name}).",
                    ];
                }
            }
        }

        // Store results in a transient for displaying in admin notices
        set_transient('ll_translation_results', $results, 60);
    }
}
add_action('update_option_ll_enable_category_translation', 'll_auto_translate_existing_categories', 10, 3);

add_action('admin_notices', 'll_display_translation_results');
function ll_display_translation_results() {
    $results = get_transient('ll_translation_results');
    if ($results) {
        delete_transient('ll_translation_results');

        $source_language = strtoupper($results['source_language']); // Capitalize language codes for clarity
        $target_language = strtoupper($results['target_language']);

        // Display translation direction
        echo '<div class="notice notice-info is-dismissible">';
        echo "<p><strong>Category Translations:</strong> Translating from <strong>$source_language</strong> to <strong>$target_language</strong>.</p>";
        echo '</div>';

        // Display success results
        if (!empty($results['success'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Translation Results:</strong></p>';
            echo '<ul>';
            foreach ($results['success'] as $success) {
                echo '<li><strong>Original:</strong> ' . esc_html($success['original']) . 
                     ' <strong>Translated:</strong> ' . esc_html($success['translated']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Display error results
        if (!empty($results['errors'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Translation Errors:</strong></p>';
            echo '<ul>';
            foreach ($results['errors'] as $error) {
                echo '<li><strong>Original:</strong> ' . esc_html($error['original']) . 
                     ' <strong>Error:</strong> ' . esc_html($error['error']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }
}



?>
