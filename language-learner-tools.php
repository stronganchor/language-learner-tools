<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 1.2.8
 * Text Domain: ll-tools-text-domain
 * Domain Path: /languages
 *
 * This plugin is designed to enhance the display of vocabulary items in a custom
 * post type called 'words'. It adds the English meaning and an audio file to each post.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the language switcher feature
include(plugin_dir_path(__FILE__) . 'language-switcher.php');

/**
 * Enqueue plugin styles and scripts.
 */
function ll_tools_enqueue_assets() {
    // Set the version numbers for the CSS file. (This needs to be incremented whenever the file changes)
    $lltools_css_version = '1.1.5'; 

    // Enqueue the CSS file
    wp_enqueue_style('ll-tools-style', plugins_url('language-learner-tools.css', __FILE__), array(), $lltools_css_version);
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
 * Display the content of custom fields on the "words" posts.
 *
 * @param string $content The original content of the post.
 * @return string Modified content with custom fields.
 */
function ll_tools_display_vocab_content($content) {
    // Check if we're inside the main loop in a single 'words' post
    if (is_singular('words') && in_the_loop() && is_main_query()) {
        global $post;

        // Retrieve custom field values
        $word_audio_file = get_post_meta($post->ID, 'word_audio_file', true);
        $word_english_meaning = get_post_meta($post->ID, 'word_english_meaning', true);
        $word_example_sentence = get_post_meta($post->ID, 'word_example_sentence', true);
        $word_example_translation = get_post_meta($post->ID, 'word_example_sentence_translation', true);

        // Fetch and format the word categories, including parent categories
        $word_categories_content = '';
        $word_categories = get_the_terms($post->ID, 'word-category');
        if (!empty($word_categories)) {
            $word_categories_content .= '<div class="word-categories">Word categories: ';
            $category_links = array();
            foreach ($word_categories as $category) {
                // Add the current category
                $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">' . esc_html($category->name) . '</a>';

                // Check and add parent category if it exists
                while ($category->parent != 0) {
                    $category = get_term($category->parent, 'word-category');
                    $category_links[] = '<a href="' . esc_url(get_term_link($category)) . '">' . esc_html($category->name) . '</a>';
                }
            }

            // Remove duplicate category links and implode
            $category_links = array_unique($category_links);
            $word_categories_content .= implode(', ', $category_links);
            $word_categories_content .= '</div>';
        }

        // Build the custom output
        $custom_content = "<div class='vocab-item'>";

        // Add word categories at the top
        $custom_content .= $word_categories_content;

        // Add featured image with a custom class
        if (has_post_thumbnail($post->ID)) {
            $custom_content .= get_the_post_thumbnail($post->ID, 'full', array('class' => 'vocab-featured-image'));
        }

        $custom_content .= "<h2>Meaning: $word_english_meaning</h2>";

        if ($word_example_sentence && $word_example_translation) {
            $custom_content .= "<p>$word_example_sentence</p>";
            $custom_content .= "<p><em>$word_example_translation</em></p>";
        }
        if ($word_audio_file) {
            $custom_content .= "<audio controls src='".esc_url(home_url($word_audio_file))."'></audio>";
        }

        $custom_content .= "</div>";

        // Append the custom content to the original content
        $content = $custom_content . $content;
    }
    return $content;
}
add_filter('the_content', 'll_tools_display_vocab_content');

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
        'post_type'      => 'words',
        'posts_per_page' => -1,
		'meta_query'     => array(
            array(
                'key' => '_thumbnail_id', // Checks if the post has a featured image.
                'compare' => 'EXISTS'
            ),
        ),
        'orderby'        => 'date', // Order by date
        'order'          => 'ASC', // Ascending order
        'tax_query'      => array(
            array(
                'taxonomy' => 'word-category',
                'field'    => 'slug',
                'terms'    => $atts['category'],
            ),
        ),
    );

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

/**
 * Actions to perform on plugin activation.
 */
function ll_tools_activate() {
    // Code to execute on plugin activation
}
register_activation_hook(__FILE__, 'll_tools_activate');

/**
 * Actions to perform on plugin deactivation.
 */
function ll_tools_deactivate() {
    // Code to execute on plugin deactivation
}
register_deactivation_hook(__FILE__, 'll_tools_deactivate');

// Hook to add the meta boxes
add_action('add_meta_boxes', 'll_tools_add_similar_words_metabox');

// Function to add the meta box
function ll_tools_add_similar_words_metabox() {
    add_meta_box(
        'similar_words_meta', // ID of the meta box
        'Similar Words', // Title of the meta box
        'll_tools_similar_words_metabox_callback', // Callback function
        'words', // Post type
        'side', // Context
        'default' // Priority
    );
}

// The callback function to display the meta box content
function ll_tools_similar_words_metabox_callback($post) {
    // Add a nonce field for security
    wp_nonce_field('similar_words_meta', 'similar_words_meta_nonce');

    // Retrieve the current value if it exists
    $similar_word_id = get_post_meta($post->ID, 'similar_word_id', true);

    // Display the meta box HTML
    echo '<p>Enter the Post ID of a word that looks similar:</p>';
    echo '<input type="text" id="similar_word_id" name="similar_word_id" value="' . esc_attr($similar_word_id) . '" class="widefat" />';
    echo '<p>Find the Post ID in the list of words. Use numerical ID only.</p>';
}

// Hook to save the post metadata
add_action('save_post', 'll_tools_save_similar_words_metadata');

// Function to save the metadata
function ll_tools_save_similar_words_metadata($post_id) {
    // Check if the nonce is set and valid
    if (!isset($_POST['similar_words_meta_nonce']) || !wp_verify_nonce($_POST['similar_words_meta_nonce'], 'similar_words_meta')) {
        return;
    }

    // Check if the current user has permission to edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check if the similar word ID is set and save it
    if (isset($_POST['similar_word_id'])) {
        $similar_word_id = sanitize_text_field($_POST['similar_word_id']);
        update_post_meta($post_id, 'similar_word_id', $similar_word_id);
    }
}

/*
 * The shortcode function that sets up the flashcard widget
 */
function ll_tools_flashcard_widget($atts) {
	// Default settings for the shortcode
    $atts = shortcode_atts(array(
        'category' => '', // Optional category
        'mode' => 'random', // Default to practice by showing one random word at a time
    ), $atts);
	
	// Set the version numbers for the CSS and JS files. (This needs to be incremented whenever the file changes)
	$js_version = '1.8.9';
	$flashcard_css_version = '1.1.9';
	
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

			$deepest_category = null;
		    $max_depth = -1;
			$categories = get_the_terms($current_id, 'word-category');
		
		    foreach ($categories as $category) {
		        $depth = 0;
		        $current_category = $category;
		        while ($parent_id = get_category($current_category)->parent) { // Assuming get_category() and ->parent gets the parent category
		            $depth++;
		            $current_category = $parent_id;
		        }
		
		        if ($depth > $max_depth) {
		            $max_depth = $depth;
		            $deepest_category = $category;
		        }
		    }
			
            $words_data[] = array(
				'id' => $current_id,
                'title' => get_the_title(),
                'image' => get_the_post_thumbnail_url($current_id, 'full'),
                'audio' => get_post_meta($current_id, 'word_audio_file', true),
				'similar_word_id' => get_post_meta($current_id, 'similar_word_id', true),
	            'category' => $deepest_category ? $deepest_category->slug : '', // Save the deepest category slug
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
	
    // Preload words data to the page and include the mode
    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsData', array(
        'words' => $words_data,
        'mode' => $atts['mode'], // Pass the mode to JavaScript
	    'plugin_dir' => plugin_dir_url(__FILE__),
		'translations' => $translation_array,
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

/************************************************************************************
 * [audio_upload_form] Shortcode - Bulk upload audio files & generate new word posts
 ***********************************************************************************/
function ll_audio_upload_form_shortcode() {
    if (!current_user_can('upload_files')) {
        return 'You do not have permission to upload files.';
    }

    ob_start();
    ?>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
        <input type="file" name="ll_audio_files[]" multiple="multiple"><br>
        
        <input type="checkbox" id="match_existing_posts" name="match_existing_posts" value="1">
        <label for="match_existing_posts">Match to existing word posts instead of creating new ones</label><br>
        
        <div id="categories_section">
            <?php
            echo 'Select Categories:<br>';
            ll_display_categories_checklist('word-category');
            ?>
        </div>
        
        <input type="hidden" name="action" value="process_audio_files">
        <input type="submit" value="Upload Audio Files">
    </form>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // When the 'Match to existing word posts' checkbox changes
        $('#match_existing_posts').change(function() {
            // Toggle the visibility of the categories section
            $('#categories_section').toggle(!this.checked);
        });

        // When a category checkbox changes
        $('input[name="ll_word_categories[]"]').on('change', function() {
            let parentId = $(this).data('parent-id');
            // Loop through all parents and check them
            while (parentId) {
                const parentCheckbox = $('input[name="ll_word_categories[]"][value="' + parentId + '"]');
                parentCheckbox.prop('checked', true);
                parentId = parentCheckbox.data('parent-id'); // Move to the next parent
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_upload_form', 'll_audio_upload_form_shortcode');

// Add bulk audio uploading tool to the 'All Words' page in the admin dashboard
function ll_add_bulk_audio_upload_tool_admin_page() {
    $screen = get_current_screen();

    // Check if we're on the 'edit.php' page for the 'words' custom post type
    if ( isset($screen->id) && $screen->id === 'edit-words' ) {
        // Directly echo the output of the shortcode function
        echo '<h2>Bulk Audio Upload for Words</h2>';
        echo ll_audio_upload_form_shortcode();
    }
}
add_action('admin_notices', 'll_add_bulk_audio_upload_tool_admin_page');

// Function to display categories with indentation
function ll_display_categories_checklist($taxonomy, $parent = 0, $level = 0) {
    $categories = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => $parent,
    ]);

    foreach ($categories as $category) {
        $indent = str_repeat("&nbsp;&nbsp;&nbsp;", $level);
        echo $indent . '<input type="checkbox" name="ll_word_categories[]" value="' . esc_attr($category->term_id) . '" data-parent-id="' . esc_attr($category->parent) . '"> ' . esc_html($category->name) . '<br>';

        // Recursive call to get child categories
        ll_display_categories_checklist($taxonomy, $category->term_id, $level + 1);
    }
}

// Hook into the admin_post action for our form's submission
add_action('admin_post_process_audio_files', 'll_handle_audio_file_uploads');
function ll_handle_audio_file_uploads() {
    // Security check: Ensure the current user can upload files
    if (!current_user_can('upload_files')) {
        wp_die('You do not have permission to upload files.');
    }

    // Check if we're matching existing posts or creating new ones
    $match_existing_posts = isset($_POST['match_existing_posts']) && $_POST['match_existing_posts'];

    // Prepare for file upload handling
    $selected_categories = isset($_POST['ll_word_categories']) ? (array) $_POST['ll_word_categories'] : [];
    $upload_dir = wp_upload_dir();
    $success_matches = [];
    $failed_matches = [];
	
	foreach ($_FILES['ll_audio_files']['tmp_name'] as $key => $tmp_name) {
	    if ($_FILES['ll_audio_files']['error'][$key] === UPLOAD_ERR_OK && is_uploaded_file($tmp_name)) {
	        $original_name = $_FILES['ll_audio_files']['name'][$key];
	        $file_name = sanitize_file_name($original_name);
	        $upload_path = $upload_dir['path'] . '/' . $file_name;
	
	        // Check if the file already exists and modify the file name if it does
	        $counter = 0; // Initialize a counter for the filename suffix
	        $file_info = pathinfo($file_name);
	        $original_base_name = $file_info['filename']; // Filename without extension
	        $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : ''; // Include the dot
	        // Loop to find a new filename if the current one already exists
	        while (file_exists($upload_path)) {
	            $file_name = $original_base_name . '_' . $counter . $extension;
	            $upload_path = $upload_dir['path'] . '/' . $file_name;
	            $counter++; // Increment the counter for the next round if needed
	        }
	
	        if (move_uploaded_file($tmp_name, $upload_path)) {
	            // This will construct the path from /wp-content/uploads onwards
	            $relative_upload_path = str_replace(wp_normalize_path(untrailingslashit(ABSPATH)), '', wp_normalize_path($upload_path));

                $title_without_extension = pathinfo($original_name, PATHINFO_FILENAME);
				
			    // Remove underscores and numbers from the title
			    $title_cleaned = preg_replace('/[_0-9]+/', '', $title_without_extension);
			    
			    // Then apply your existing normalization and sanitization
			    $formatted_title = ll_normalize_case(sanitize_text_field($title_cleaned));

                if ($match_existing_posts) {
                    // Try to find an existing post with a matching title
                    $existing_post = ll_find_post_by_exact_title($formatted_title);
                    if ($existing_post) {
                        // Update the existing post's audio file metadata
                        update_post_meta($existing_post->ID, 'word_audio_file', $relative_upload_path);
                        $success_matches[] = $original_name . ' -> Post ID: ' . $existing_post->ID;
                    } else {
                        $failed_matches[] = $original_name;
                    }
                } else {
                    // Use the normalized title as the post title
                    $post_id = wp_insert_post([
                        'post_title'    => $formatted_title,
                        'post_content'  => '',
                        'post_status'   => 'publish',
                        'post_type'     => 'words',
                    ]);

                    if ($post_id && !is_wp_error($post_id)) {
                        // Save the relative path of the audio file and full transcription as post meta
                        $relative_upload_path = '/wp-content/uploads' . str_replace($upload_dir['basedir'], '', $upload_path);
                        update_post_meta($post_id, 'word_audio_file', $relative_upload_path);
						
						// Retrieve language settings from the WordPress database
						$target_language = get_option('ll_target_language');
						$translation_language = get_option('ll_translation_language');
						
						// Translate the title using the specified languages and save if successful
						$translated_title = translate_with_deepl($formatted_title, $translation_language, $target_language);

                        if (!is_null($translated_title)) {
                            update_post_meta($post_id, 'word_english_meaning', $translated_title);
                        }

                        // Assign selected categories to the post
                        if (!empty($selected_categories)) {
                            $selected_categories = array_map('intval', $selected_categories);
                            wp_set_object_terms($post_id, $selected_categories, 'word-category', false);
                        }
                        
                        // Add to success matches list for feedback
                        $success_matches[] = $original_name . ' -> New Post ID: ' . $post_id;
                    } else {
                        // Add to failed matches list if there was an issue creating the post
                        $failed_matches[] = $original_name . ' (Failed to create post)';
                    }
                }
            }
        }
    }

    // Redirect with success and failure messages
    $redirect_url = add_query_arg([
        'post_type' => 'words',
        'success_matches' => implode(',', $success_matches),
        'failed_matches' => implode(',', $failed_matches),
    ], admin_url('edit.php'));
    
    wp_redirect($redirect_url);
    exit;
}

/***************************
 * [word_audio] Shortcode
 **************************/
function ll_word_audio_shortcode($atts = [], $content = null) {
    // Ensure content is not empty
    if (empty($content)) {
        return '';
    }
	
	$attributes = shortcode_atts(array(
        'translate' => 'yes', // Default is to provide a translation in parentheses
		'id' => null, // If set, it will look up the word post by ID
    ), $atts);

    // Store the unmodified content for later
    $original_content = $content;

    // Strip nested shortcodes temporarily
    $stripped_content = preg_replace('/\[.*?\]/', '', $content);
	
	$parentheses_regex = '/\(([^)]+)\)/'; 

	// Use preg_match to capture the English meaning inside parentheses
	$has_parenthesis = preg_match($parentheses_regex, $stripped_content, $matches);

	// Now remove the matched pattern from the content
	$without_parentheses = preg_replace($parentheses_regex, '', $stripped_content);
	
    $normalized_content = ll_normalize_case($without_parentheses);
	
	if (!empty($attributes['id'])) {
    	$post_id = intval($attributes['id']); // Ensure the ID is an integer
    	$post = get_post($post_id); // Retrieve the post by ID
	} else {
		$post = ll_find_post_by_exact_title($normalized_content, 'words');
	}
	
    // If no posts found, return the original content processed for nested shortcodes
    if (empty($post)) {
        return do_shortcode($original_content);
    }
	
	$english_meaning = '';
	
    // Retrieve the English meaning if needed
    if (!$has_parenthesis && $attributes['translate'] !== 'no') {
    	$english_meaning = get_post_meta($post->ID, 'word_english_meaning', true);
	}
	
	// Retrieve the audio file for this word
    $audio_file = get_post_meta($post->ID, 'word_audio_file', true);

    // Generate unique ID for the audio element
    $audio_id = uniqid('audio_');

	$play_icon = '<img src="/wp-content/uploads/2024/02/play-symbol.svg" width="10" height="10" alt="Play" data-no-lazy="1"/>';
	
    // Construct the output with an interactive audio player icon
    $output = '<span class="ll-word-audio">';
    if (!empty($audio_file)) {
        $output .= "<div id='{$audio_id}_icon' class='ll-audio-icon' style='width: 20px; display: inline-flex; cursor: pointer;' onclick='ll_toggleAudio(\"{$audio_id}\")'>". $play_icon . "</div>";
        $output .= "<audio id='{$audio_id}' onplay='ll_audioPlaying(\"{$audio_id}\")' onended='ll_audioEnded(\"{$audio_id}\")' style='display:none;'><source src='" . esc_url($audio_file) . "' type='audio/mpeg'></audio>";
    }
    $output .= do_shortcode($original_content);
    if (!empty($english_meaning)) {
        $output .= ' (' . esc_html($english_meaning) . ')';
    }
    $output .= '</span>';

    // Include JavaScript for toggling play/stop
    $output .= "
    <script>
	var play_icon = '<img src=\"/wp-content/uploads/2024/02/play-symbol.svg\" width=\"10\" height=\"10\" alt=\"Play\" data-no-lazy=\"1\">';
	var stop_icon = '<img src=\"/wp-content/uploads/2024/02/stop-symbol.svg\" width=\"9\" height=\"9\" alt=\"Stop\" data-no-lazy=\"1\">';
	
    function ll_toggleAudio(audioId) {
        var audio = document.getElementById(audioId);
        var icon = document.getElementById(audioId + '_icon');
        if (!audio.paused) {
            audio.pause();
            audio.currentTime = 0; // Stop the audio
			icon.innerHTML = play_icon;
        } else {
            audio.play();
        }
    }

    function ll_audioPlaying(audioId) {
        var icon = document.getElementById(audioId + '_icon');
        icon.innerHTML = stop_icon;
    }

    function ll_audioEnded(audioId) {
        var icon = document.getElementById(audioId + '_icon');
        icon.innerHTML = play_icon;
    }
    </script>
    ";

    return $output;
}
add_shortcode('word_audio', 'll_word_audio_shortcode');

// Look up word post by the exact title, being sensitive of special characters
function ll_find_post_by_exact_title($title, $post_type = 'words') {
    global $wpdb;

    // Sanitize the title to prevent SQL injection
    $title = sanitize_text_field($title);

	// Normalize the case of the content
    $title = ll_normalize_case($title);
	
    // Prepare the SQL query using prepared statements for security
    $query = $wpdb->prepare(
        "SELECT * FROM $wpdb->posts WHERE post_title = BINARY %s AND post_type = %s AND post_status = 'publish' LIMIT 1",
        $title,
        $post_type
    );

    // Execute the query
    $post = $wpdb->get_row($query);

    // Return the post if found
    return $post;
}

// Set a Turkish text to only have the first character capitalized.
function ll_normalize_case($text) {
	if (function_exists('mb_strtolower') && function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        // Normalize the encoding to UTF-8 if not already
        $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
		 
		// Special handling for Turkish dotless I and dotted İ
        $firstChar = mb_substr($text, 0, 1, 'UTF-8');
        if ($firstChar === 'i' || $firstChar === "\xC4\xB0" || $firstChar == 'İ') {
			return 'İ' . mb_substr($text, 1, null, 'UTF-8');
        } elseif ($firstChar === 'ı' || $firstChar === 'I') {
            return 'I' . mb_substr($text, 1, null, 'UTF-8');
        } else {
            $firstChar = mb_strtoupper($firstChar, 'UTF-8');
			$text = mb_strtolower($text, 'UTF-8');
       		return $firstChar . mb_substr($text, 1, null, 'UTF-8');
        }
    }

    // Just return the original text if Multibyte String functions aren't available
	return $text;
}


/*****************************
 * DeepL API Interface
 *****************************/

// Add an admin page under Tools for entering the API key (https://www.deepl.com/pro-api)
function ll_add_deepl_api_key_page() {
    add_management_page(
        'DeepL API Key',
        'DeepL API Key',
        'manage_options',
        'deepl-api-key',
        'll_deepl_api_key_page_content'
    );
}
add_action('admin_menu', 'll_add_deepl_api_key_page');

// Add content to the DeepL API page
function ll_deepl_api_key_page_content() {
    ?>
    <div class="wrap">
        <h1>Enter Your DeepL API Key</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ll-deepl-api-key-group');
            do_settings_sections('ll-deepl-api-key-group');
            ?>
            <table class="form-table">
                <tr valign="top">
                <th scope="row">DeepL API Key</th>
                <td><input type="text" name="ll_deepl_api_key" value="<?php echo esc_attr(get_option('ll_deepl_api_key')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register the DeepL API key setting
function ll_register_deepl_api_key_setting() {
    register_setting('ll-deepl-api-key-group', 'll_deepl_api_key');
}
add_action('admin_init', 'll_register_deepl_api_key_setting');

// Perform translation with DeepL API
function translate_with_deepl($text, $target_lang = 'EN', $source_lang = 'TR') {
    $api_key = get_option('ll_deepl_api_key'); // Retrieve the API key from WordPress options
    if (empty($api_key)) {
        return null;
    }

    $endpoint = 'https://api-free.deepl.com/v2/translate';
    $data = http_build_query([
        'auth_key' => $api_key,
        'text' => $text,
        'target_lang' => $target_lang,
        'source_lang' => $source_lang,
    ]);

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $data,
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($endpoint, false, $context);

    if ($result === FALSE) {
        return null; // return null if translation failed
    }

    $json = json_decode($result, true);
	if (!is_array($json) || !isset($json['translations'][0]['text'])) {
        // Handle unexpected JSON structure or missing translation
        return null; // Return null to indicate an unexpected error occurred
    }
    return $json['translations'][0]['text'] ?? $text; // Return the translation or original text if something goes wrong
}

function ll_tools_register_words_post_type() {

	/**
	 * Post Type: Words.
	 */

	$labels = [
		"name" => esc_html__( "Words", "astra" ),
		"singular_name" => esc_html__( "Word", "astra" ),
	];

	$args = [
		"label" => esc_html__( "Words", "astra" ),
		"labels" => $labels,
		"description" => "",
		"public" => true,
		"publicly_queryable" => true,
		"show_ui" => true,
		"show_in_rest" => true,
		"rest_base" => "",
		"rest_controller_class" => "WP_REST_Posts_Controller",
		"rest_namespace" => "wp/v2",
		"has_archive" => false,
		"show_in_menu" => true,
		"show_in_nav_menus" => true,
		"delete_with_user" => false,
		"exclude_from_search" => false,
		"capability_type" => "post",
		"map_meta_cap" => true,
		"hierarchical" => false,
		"can_export" => false,
		"rewrite" => [ "slug" => "words", "with_front" => true ],
		"query_var" => true,
		"supports" => [ "title", "editor", "thumbnail", "custom-fields" ],
		"show_in_graphql" => false,
	];

	register_post_type( "words", $args );
}

add_action( 'init', 'll_tools_register_words_post_type' );

function ll_tools_register_word_category_taxonomy() {

	/**
	 * Taxonomy: Word Categories.
	 */

	$labels = [
		"name" => esc_html__( "Word Categories", "astra" ),
		"singular_name" => esc_html__( "Word Category", "astra" ),
	];

	
	$args = [
		"label" => esc_html__( "Word Categories", "astra" ),
		"labels" => $labels,
		"public" => true,
		"publicly_queryable" => true,
		"hierarchical" => true,
		"show_ui" => true,
		"show_in_menu" => true,
		"show_in_nav_menus" => true,
		"query_var" => true,
		"rewrite" => [ 'slug' => 'word-category', 'with_front' => true, ],
		"show_admin_column" => false,
		"show_in_rest" => true,
		"show_tagcloud" => false,
		"rest_base" => "word-category",
		"rest_controller_class" => "WP_REST_Terms_Controller",
		"rest_namespace" => "wp/v2",
		"show_in_quick_edit" => false,
		"sort" => false,
		"show_in_graphql" => false,
	];
	register_taxonomy( "word-category", [ "words" ], $args );
}
add_action( 'init', 'll_tools_register_word_category_taxonomy' );

?>
