<?php
/**
 * [flashcard_widget] shortcode
 * 
 * This shortcode displays a flashcard widget for learning and practicing words.
 */

/**
 * Shortcode handler for [flashcard_widget].
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content for the flashcard widget.
 */
function ll_tools_flashcard_widget($atts) {
    // Default settings for the shortcode
    $atts = shortcode_atts(array(
        'category' => '', // Optional category
        'mode' => 'random' // Default to practice by showing one random word at a time
    ), $atts);

    $categories = $atts['category'];
    $categoriesPreselected = true;

    // Check if translation is enabled and the site's language matches the translation language
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language = strtolower(get_option('ll_translation_language', 'en')); // Normalize case
    $site_language = strtolower(get_locale()); // Normalize case

    // Determine if translations should be used
    $use_translations = $enable_translation && strpos($site_language, $target_language) === 0;

    // Retrieve all categories if none are specified
    $all_categories = get_terms(array(
        'taxonomy' => 'word-category',
        'hide_empty' => false,
    ));

    if (empty($categories)) {
        $categoriesPreselected = false;

        // Process all categories
        $categories = ll_process_categories($all_categories, $use_translations);
    } else {
        // Process specified categories
        $category_attributes = explode(',', esc_attr($categories));
        $categories = [];
        $all_categories = ll_process_categories($all_categories, $use_translations);

        foreach ($category_attributes as $attribute) {
            $attribute_found = false;

            foreach ($all_categories as $category) {
                if (strcasecmp($attribute, $category['name']) === 0 || strcasecmp($attribute, $category['id']) === 0) {
                    $categories[] = $category;
                    $attribute_found = true;
                    break;
                }
            }

            if (!$attribute_found) {
                error_log("Category '$attribute' not found.");
            }
        }
    }

    // Process category data for the quiz
    $words_data = [];
    $firstCategoryName = '';
    $categoriesToTry = array_column($categories, 'name');

    while (!empty($categoriesToTry) && (empty($words_data) || count($words_data) < 3)) {
        $random_category = $categoriesToTry[array_rand($categoriesToTry)];
        $categoriesToTry = array_diff($categoriesToTry, [$random_category]);
    
        // Find the category data in $categories
        $selected_category_data = null;
        foreach ($categories as $cat) {
            if ($cat['name'] === $random_category) {
                $selected_category_data = $cat;
                break;
            }
        }
    
        // If for some reason we don't find it, default to image
        $mode = $selected_category_data ? $selected_category_data['mode'] : 'image';
    
        $words_data = ll_get_words_by_category($random_category, $mode);
        $firstCategoryName = $random_category;
    }

    // Enqueue scripts and styles
    wp_enqueue_script('jquery');
    ll_enqueue_asset_by_timestamp('/css/flashcard-style.css', 'll-tools-flashcard-style');
    ll_enqueue_asset_by_timestamp('/js/flashcard-audio.js', 'll-tools-flashcard-audio', array('jquery'), true);
    ll_enqueue_asset_by_timestamp('/js/flashcard-loader.js', 'll-tools-flashcard-loader', array('jquery'), true);
    ll_enqueue_asset_by_timestamp('/js/flashcard-options.js', 'll-tools-flashcard-options', array('jquery'), true);
    wp_localize_script('ll-tools-flashcard-options', 'llToolsFlashcardsData', array(
        'maxOptionsOverride' => get_option('ll_max_options_override', 9),
    ));
    ll_enqueue_asset_by_timestamp('/js/flashcard-script.js', 'll-tools-flashcard-script', array('jquery', 'll-tools-flashcard-audio', 'll-tools-flashcard-loader', 'll-tools-flashcard-options'), true);
    ll_enqueue_asset_by_timestamp('/js/category-selection.js', 'll-tools-category-selection-script', array('jquery', 'll-tools-flashcard-script'), true);

    // Add inline script for managing audio playback
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            var audios = $("#word-grid audio");

            audios.on("play", function() {
                var currentAudio = this;
                audios.each(function() {
                    if (this !== currentAudio) {
                        this.pause();
                    }
                });
            });
        });
    ');

    // Get the current user's ID and quiz state
    $user_id = get_current_user_id();

    // Preload words data to the page and include the mode
    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsData', array(
        'mode' => $atts['mode'], // Pass the mode to JavaScript
        'plugin_dir' => plugin_dir_url(__FILE__),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'categories' => $categories,
        'isUserLoggedIn' => is_user_logged_in(),
        'categoriesPreselected' => $categoriesPreselected,
        'firstCategoryData' => $words_data,
        'firstCategoryName' => $firstCategoryName,
    ));

	// Localize translatable strings for results messages
    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsMessages', array(
        'perfect' => __('Perfect!', 'll-tools-text-domain'),
        'goodJob' => __('Good job!', 'll-tools-text-domain'),
        'keepPracticingTitle' => __('Keep practicing!', 'll-tools-text-domain'),
        'keepPracticingMessage' => __('You\'re on the right track to get a higher score next time!', 'll-tools-text-domain'),
        'somethingWentWrong' => __('Something went wrong, try again later.', 'll-tools-text-domain'),
    ));

    // Output the initial setup and the buttons
    ob_start();
    echo '<div id="ll-tools-flashcard-container">';
    echo '<button id="ll-tools-start-flashcard">' . esc_html__('Start', 'll-tools-text-domain') . '</button>';
    echo '<div id="ll-tools-flashcard-popup" style="display: none;">';
    echo '<div id="ll-tools-category-selection-popup" style="display: none;">';
    echo '<h3>' . esc_html__('Select Categories', 'll-tools-text-domain') . '</h3>';
    echo '<div class="ll-tools-category-selection-buttons">';
    echo '<button id="ll-tools-uncheck-all">' . esc_html__('Uncheck All', 'll-tools-text-domain') . '</button>';
    echo '<button id="ll-tools-check-all">' . esc_html__('Check All', 'll-tools-text-domain') . '</button>';
    echo '</div>';
    echo '<div id="ll-tools-category-checkboxes-container">';
    echo '<div id="ll-tools-category-checkboxes"></div>';
    echo '</div>';
    echo '<button id="ll-tools-start-selected-quiz">' . esc_html__('Start Quiz', 'll-tools-text-domain') . '</button>';
    echo '<button id="ll-tools-close-category-selection">&times;</button>';
    echo '</div>';
    echo '<div id="ll-tools-flashcard-quiz-popup" style="display: none;">';
    echo '<div id="ll-tools-flashcard-header" style="display: none;">';
    echo '<div id="ll-tools-loading-animation" class="ll-tools-loading-animation"></div>';
    echo '<button id="ll-tools-repeat-flashcard">' . esc_html__('Repeat', 'll-tools-text-domain') . '</button>';
    echo '<button id="ll-tools-skip-flashcard">' . esc_html__('Skip', 'll-tools-text-domain') . '</button>';
    echo '<button id="ll-tools-close-flashcard">&times;</button>';
    echo '</div>';
    echo '<div id="ll-tools-flashcard-content">';
    echo '<div id="ll-tools-flashcard"></div>';
    echo '<audio controls class="hidden"></audio>';
    echo '</div>';

    // Add quiz results section here (hidden initially, will be updated by JS)
    echo '<div id="quiz-results" style="display: none;">';
    echo '<h2 id="quiz-results-title">' . esc_html__('Quiz Results', 'll-tools-text-domain') . '</h2>'; // Dynamic title
    echo '<p id="quiz-results-message" style="display: none;"></p>'; 
    echo '<p><strong>' . esc_html__('Correct:', 'll-tools-text-domain') . '</strong> <span id="correct-count">0</span> / <span id="total-questions">0</span></p>';
    echo '<p><strong>' . esc_html__('Skipped:', 'll-tools-text-domain') . '</strong> <span id="skipped-count">0</span></p>';
    echo '<button id="restart-quiz" class="quiz-button" style="display: none;">' . esc_html__('Restart Quiz', 'll-tools-text-domain') . '</button>';
    echo '</div>';

    echo '</div>';
    echo '</div>';


    // Run a script after the page loads to show the widget with proper CSS formatting
    echo '<script type="text/javascript">
    jQuery(document).ready(function($) {
        $("#ll-tools-flashcard-container").removeAttr("style");
    });
    </script>';
    return ob_get_clean();
}

/**
 * Determines the display mode for a category based on word counts.
 *
 * @param string $categoryName The name of the category.
 * @param int $min_word_count The minimum number of words required.
 * @return string|null The display mode ('image' or 'text') or null if not determined.
 */
function ll_determine_display_mode($categoryName, $min_word_count = 6) {
    $image_count = count(ll_get_words_by_category($categoryName, 'image'));
    $text_count = count(ll_get_words_by_category($categoryName, 'text'));

    // If both image and text counts are below the minimum, return null
    if ($image_count < $min_word_count && $text_count < $min_word_count) {
        return null;
    }

    // If only image_count is below the minimum, default to text
    if ($image_count < $min_word_count) {
        return 'text';
    }

    // If only text_count is below the minimum, default to image
    if ($text_count < $min_word_count) {
        return 'image';
    }

    // Both exceed the minimum; pick whichever has more words
    return ($image_count >= $text_count) ? 'image' : 'text';
}

/**
 * Processes categories by filtering based on translations and word counts.
 *
 * @param array $categories The array of category terms.
 * @param bool $use_translations Whether to use translations.
 * @param int $min_word_count The minimum number of words required.
 * @return array The processed categories.
 */
function ll_process_categories($categories, $use_translations, $min_word_count = 6) {
    $processed_categories = [];

    foreach ($categories as $category) {
        $mode = ll_determine_display_mode($category->name, $min_word_count);

        // If no mode could be determined, skip this category
        if ($mode === null) {
            continue;
        }

        $translation = $use_translations
            ? (get_term_meta($category->term_id, 'term_translation', true) ?: $category->name)
            : $category->name;

        $processed_categories[] = [
            'id' => $category->term_id,
            'slug' => $category->slug,
            'name' => $category->name,
            'translation' => $translation,
            'mode' => $mode,
        ];
    }

    return $processed_categories;
}


// AJAX handler for fetching words by category
add_action('wp_ajax_ll_get_words_by_category', 'll_get_words_by_category_ajax');
add_action('wp_ajax_nopriv_ll_get_words_by_category', 'll_get_words_by_category_ajax');
function ll_get_words_by_category_ajax() {
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $display_mode = isset($_POST['display_mode']) ? sanitize_text_field($_POST['display_mode']) : 'image';

    if (!empty($category)) {
        $words_data = ll_get_words_by_category($category, $display_mode);
        wp_send_json_success($words_data);
    } else {
        wp_send_json_error('Invalid category.');
    }
}

// Register the [flashcard_widget] shortcode
function ll_tools_register_flashcard_widget_shortcode() {
	add_shortcode('flashcard_widget', 'll_tools_flashcard_widget');
}
add_action('init', 'll_tools_register_flashcard_widget_shortcode');

?>
