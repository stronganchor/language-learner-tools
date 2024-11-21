<?php
/**
 * [flashcard_widget] shortcode
 * 
 * This shortcode displays a flashcard widget for learning and practicing words.
 */
function ll_tools_flashcard_widget($atts) {
    // Default settings for the shortcode
    $atts = shortcode_atts(array(
        'category' => '', // Optional category
        'mode' => 'random', // Default to practice by showing one random word at a time
        'display' => 'image', // Default to displaying the word's image
    ), $atts);

    $categories = $atts['category'];
    $categoriesPreselected = true;

    // Check if translation is enabled and the site's language matches the translation language
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language = strtolower(get_option('ll_translation_language', 'en')); // Normalize case
    $site_language = strtolower(get_locale()); // Normalize case

    // Determine if translations should be used
    $use_translations = $enable_translation && strpos($site_language, $target_language) === 0;

    // If no category is specified, get all categories
    if (empty($categories)) {
        $categoriesPreselected = false;
        $all_categories = get_terms(array(
            'taxonomy' => 'word-category',
            'hide_empty' => false,
        ));

        $categories = array();
        foreach ($all_categories as $category) {
            $word_count = ll_get_deepest_category_word_count($category->term_id);

            // Require categories to have at least 6 words in them in order to be used in the quiz
            if ($word_count >= 6) {
                // Use translation if available and applicable, otherwise fall back to the original name
                $categories[] = $use_translations
                    ? (get_term_meta($category->term_id, 'term_translation', true) ?: $category->name)
                    : $category->name;
            }
        }
    } else {
        $category_attributes = explode(',', esc_attr($categories));
        $categories = [];
        // Look up all categories
        $all_categories = get_terms(array(
            'taxonomy' => 'word-category',
            'fields' => 'all', // Retrieve all fields to get IDs and names
            'hide_empty' => false,
        ));

        foreach ($category_attributes as $attribute) {
            $attribute_found = false;

            // Check if the attribute matches any category name or ID (case-insensitive)
            foreach ($all_categories as $category) {
                $word_count = ll_get_deepest_category_word_count($category->term_id);

                if ($word_count >= 6 && (strcasecmp($attribute, $category->name) === 0 || strcasecmp($attribute, $category->term_id) === 0)) {
                    // Add the exact name of the category to $categories
                    $categories[] = $use_translations
                        ? (get_term_meta($category->term_id, 'term_translation', true) ?: $category->name)
                        : $category->name;
                    $attribute_found = true;
                    break;
                }
            }

            // If no category matched the attribute, issue a warning
            if (!$attribute_found) {
                error_log("Category '$attribute' not found.");
            }
        }
    }
	
    $words_data = array();
    $firstCategoryName = '';
    $categoriesToTry = $categories;

    while (!empty($categoriesToTry) && (empty($words_data) || count($words_data) < 3)){
        // Get the word data for a random category
        $random_category = $categoriesToTry[array_rand($categoriesToTry)];
        $categoriesToTry = array_diff($categoriesToTry, array($random_category));
        $words_data = ll_get_words_by_category($random_category, $atts['display']);
        $firstCategoryName = $random_category;
    }

    // Enqueue jQuery
    wp_enqueue_script('jquery');

    // Enqueue assets used by flashcard widget shortcode
    ll_enqueue_asset_by_timestamp('/css/flashcard-style.css', 'll-tools-flashcard-style');
    ll_enqueue_asset_by_timestamp('/js/flashcard-script.js', 'll-tools-flashcard-script', array('jquery'), true);
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

	// Internationalized strings to use in the user interface
    $translation_array = array(
		'start' => esc_html__('Start', 'll-tools-text-domain'),
        'repeat' => esc_html__('Repeat', 'll-tools-text-domain'),
        'skip' => esc_html__('Skip', 'll-tools-text-domain'),
        'learn' => esc_html__('Learn', 'll-tools-text-domain'),
        'practice' => esc_html__('Practice', 'll-tools-text-domain'),
    );

    // Get the current user's ID
    $user_id = get_current_user_id();

    // Retrieve the user's quiz state
    $quiz_state = ll_get_quiz_state($user_id);
	
    // Preload words data to the page and include the mode
    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsData', array(
        'mode' => $atts['mode'], // Pass the mode to JavaScript
	    'plugin_dir' => plugin_dir_url(__FILE__),
		'translations' => $translation_array,
		'quizState' => $quiz_state,
		'ajaxurl' => admin_url('admin-ajax.php'),
        'categories' => $categories,
        'displayMode' => $atts['display'],
        'isUserLoggedIn' => is_user_logged_in(),
        'categoriesPreselected' => $categoriesPreselected,
        'firstCategoryData' => $words_data,
        'firstCategoryName' => $firstCategoryName,
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


// Save user's progress on the quiz to metadata
function ll_save_quiz_state($user_id, $quiz_state) {
    update_user_meta($user_id, 'll_quiz_state', $quiz_state);
}

// Get user's progress on the quiz from metadata
function ll_get_quiz_state($user_id) {
    return get_user_meta($user_id, 'll_quiz_state', true);
}

// AJAX handler for saving the quiz state
function ll_save_quiz_state_ajax() {
    $user_id = get_current_user_id();
    $quiz_state = isset($_POST['quiz_state']) ? $_POST['quiz_state'] : '';

    $response = array(
        'success' => false,
        'message' => '',
    );

    if ($user_id && !empty($quiz_state)) {
        ll_save_quiz_state($user_id, $quiz_state);
        $response['success'] = true;
        $response['message'] = 'Quiz state saved successfully';
    } else {
        $response['message'] = 'Invalid user ID or empty quiz state';
    }

    wp_send_json($response);
}
add_action('wp_ajax_ll_save_quiz_state', 'll_save_quiz_state_ajax');

?>