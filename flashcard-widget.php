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

    // If no category is specified, get all categories
    if (empty($categories)) {
        $categories = get_terms(array(
            'taxonomy' => 'word-category',
            'fields' => 'names',
            'hide_empty' => false,
        ));
    } else {
        $categories = explode(',', esc_attr($categories));
    }

    $words_data = array();
    $firstCategoryName = '';
    $categoriesToTry = $categories;

    while (!empty($categoriesToTry) && (empty($words_data) || count($words_data) < 3)){
        // Get the word data for a random category
        $random_category = $categoriesToTry[array_rand($categoriesToTry)];
        $categoriesToTry = array_diff($categoriesToTry, array($random_category));

        // Get all category names and their lowercase versions
        $all_categories = get_terms(array(
            'taxonomy' => 'word-category',
            'fields' => 'names',
            'hide_empty' => false,
        ));
        $all_categories_lowercase = array_map('strtolower', $all_categories);

        // Check if the random category exists (case-insensitive)
        $random_category_lowercase = strtolower($random_category);
        $index = array_search($random_category_lowercase, $all_categories_lowercase);

        if ($index !== false) {
            // Get the original (potentially capitalized) version of the category name
            $original_category_name = $all_categories[$index];
            $words_data = ll_get_words_by_category($original_category_name, $atts['display']);
            $firstCategoryName = $original_category_name;
        }
    }

    // Get the file paths for the CSS and JS files
    $flashcard_css_file = plugin_dir_path(__FILE__) . '/css/flashcard-style.css';
    $flashcard_js_file = plugin_dir_path(__FILE__) . '/js/flashcard-script.js';
	
    // Set the version numbers based on the file's modified timestamp
    $flashcard_css_version = filemtime($flashcard_css_file);

    $js_version = filemtime($flashcard_js_file);

    wp_enqueue_style('ll-tools-flashcard-style', plugins_url('/css/flashcard-style.css', __FILE__), array(), $flashcard_css_version);
	
    // Enqueue jQuery
    wp_enqueue_script('jquery');

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
	
	// Enqueue the JavaScript file
    wp_enqueue_script(
        'll-tools-flashcard-script', // Handle for the script.
        plugins_url('/js/flashcard-script.js', __FILE__), // Path to the script file, relative to the PHP file.
        array('jquery'), // Dependencies, assuming your script depends on jQuery.
        $js_version, // Version number, null to prevent version query string.
        true  // Whether to enqueue the script in the footer. (Recommended)
    );

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
        'firstCategoryData' => $words_data,
        'firstCategoryName' => $firstCategoryName,
    ));

    // Output the initial setup and the buttons
    ob_start();
    echo '<div id="ll-tools-flashcard-container">';
    echo '<button id="ll-tools-start-flashcard">' . esc_html__('Start', 'll-tools-text-domain') . '</button>';
    echo '<div id="ll-tools-flashcard-popup" style="display: none;">';
    echo '<div id="ll-tools-flashcard-header">';
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

// Get words by category
function ll_get_words_by_category ($categoryName, $display_mode = 'image') {
    $args = array(
        'post_type' => 'words',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'word-category',
                'field' => 'name',
                'terms' => esc_sql($categoryName),
            ),
        ),
    );

    if ($display_mode === 'image') {
        $args['meta_query'] = array(
            array(
                'key' => '_thumbnail_id',
                'compare' => 'EXISTS',
            ),
        );
    } else {
        $args['meta_query'] = array(
            array(
                'key' => 'word_english_meaning',
                'compare' => 'EXISTS',
            ),
        );
    }

    $query = new WP_Query($args);
    $words_data = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $current_id = get_the_ID();
            $primary_category = get_post_meta($current_id, 'primary_category', true);
            $all_categories = wp_get_post_terms($current_id, 'word-category', array('fields' => 'names'));

            $max_depth = -1;
            $deepest_category = '';

            foreach ($all_categories as $wordCategory) {
                $depth = 0;
                $current_category = get_term_by('name', $wordCategory, 'word-category');
                while ($current_category && $current_category->parent != 0) {
                    $depth++;
                    $current_category = get_term($current_category->parent, 'word-category');
                }
                if ($depth > $max_depth) {
                    $max_depth = $depth;
                    $deepest_category = $wordCategory;
                } else if ($depth === $max_depth && $wordCategory === $primary_category) {
                    // If the primary category is one of the deepest, use it
                    $deepest_category = $wordCategory;
                }
            }

            // If the primary category of this word is the same as the requested category, add the word to the data array
            if ($deepest_category === $categoryName) {
                $words_data[] = array(
                    'id' => $current_id,
                    'title' => get_the_title(),
                    'image' => wp_get_attachment_url(get_post_thumbnail_id($current_id)),
                    'audio' => get_post_meta($current_id, 'word_audio_file', true),
                    'similar_word_id' => get_post_meta($current_id, 'similar_word_id', true),
                    'category' => $primary_category,
                    'all_categories' => $all_categories,
                    'translation' => get_post_meta($current_id, 'word_english_meaning', true),
                );
            }
        }
        wp_reset_postdata();
    }
    return $words_data;
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