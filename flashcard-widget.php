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
    ), $atts);
	
    // Get the file paths for the CSS and JS files
    $flashcard_css_file = plugin_dir_path(__FILE__) . 'flashcard-style.css';
    $flashcard_js_file = plugin_dir_path(__FILE__) . 'flashcard-script.js';
	
    // Set the version numbers based on the file's modified timestamp
    $flashcard_css_version = filemtime($flashcard_css_file);
    $js_version = filemtime($flashcard_js_file);

    wp_enqueue_style('ll-tools-flashcard-style', plugins_url('flashcard-style.css', __FILE__), array(), $flashcard_css_version);
	
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
        plugins_url('flashcard-script.js', __FILE__), // Path to the script file, relative to the PHP file.
        array('jquery'), // Dependencies, assuming your script depends on jQuery.
        $js_version, // Version number, null to prevent version query string.
        true  // Whether to enqueue the script in the footer. (Recommended)
    );

    // Query arguments for fetching words
    $args = array(
        'post_type'      => 'words',
        'posts_per_page' => -1, // Adjust if you want to limit the total words loaded at once
		'meta_query'     => array(
            array(
                'key' => '_thumbnail_id', // Checks if the post has a featured image.
                'compare' => 'EXISTS'
            ),
        ),
        'orderby'        => 'rand', // Order by random
    );

    // If a category is specified, add a tax_query
    if (!empty($atts['category'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'word-category',
                'field'    => 'slug',
                'terms'    => explode(',', $atts['category']),
            ),
        );
    }

    $query = new WP_Query($args);
    $words_data = array();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
			$current_id = get_the_ID();
		    // Fetch the primary category
		    $primary_category = get_post_meta($current_id, 'primary_category', true);
		
		    // Fetch all categories
		    $all_categories = wp_get_post_terms($current_id, 'word-category', array('fields' => 'slugs'));

			$deepest_category = null;
			$max_depth = -1;

            // Find the deepest category
			foreach ($all_categories as $category) {
			    $depth = 0;
			    $current_category = get_term_by('slug', $category, 'word-category');
			    while ($current_category && $current_category->parent != 0) {
			        $depth++;
			        $current_category = get_term($current_category->parent, 'word-category');
			    }
			    if ($depth > $max_depth) {
			        $max_depth = $depth;
			        $deepest_category = $category;
			    }
			}
		
            // Add the word data for this word to the words_data array
		    $words_data[] = array(
		        'id' => $current_id,
		        'title' => get_the_title(),
		        'image' => get_the_post_thumbnail_url($current_id, 'full'),
		        'audio' => get_post_meta($current_id, 'word_audio_file', true),
		        'similar_word_id' => get_post_meta($current_id, 'similar_word_id', true),
		        'category' => $primary_category ?: $deepest_category,
		        'all_categories' => $all_categories,
		    );
		}
    }
    wp_reset_postdata();

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
        'words' => $words_data,
        'mode' => $atts['mode'], // Pass the mode to JavaScript
	    'plugin_dir' => plugin_dir_url(__FILE__),
		'translations' => $translation_array,
		'quizState' => $quiz_state,
		'ajaxurl' => admin_url('admin-ajax.php'),
    ));

    // Output the initial setup and the button
    ob_start();
    echo '<div id="ll-tools-flashcard-container" style="">'; // Hide until page is loaded
    echo '<button id="ll-tools-start-flashcard">' . esc_html__('Start', 'll-tools-text-domain') . '</button>';
    echo '<div id="ll-tools-flashcard" class="hidden">
            <audio controls class="hidden"></audio>
          </div>';
    echo '</div>';
	
	// Run a script after the page loads to show the widget with proper CSS formatting
	echo '<script type="text/javascript">
jQuery(document).ready(function($) {
    $("#ll-tools-flashcard-container").removeAttr("style");
});
</script>';
    return ob_get_clean();
}

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