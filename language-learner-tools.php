<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 1.1.1
 *
 * This plugin is designed to enhance the display of vocabulary items in a custom
 * post type called 'words'. It adds the English meaning and an audio file to each post.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Enqueue plugin styles and scripts.
 */
function ll_tools_enqueue_assets() {
    // Set the version numbers for the CSS file. (This needs to be incremented whenever the file changes)
    $css_version = '1.0.18'; 

    // Enqueue the CSS file
    wp_enqueue_style('ll-tools-style', plugins_url('language-learner-tools.css', __FILE__), array(), $css_version);
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_assets');

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
        'posts_per_page' => -1, // Get all posts
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
            // Featured image
            if (has_post_thumbnail()) {
                echo get_the_post_thumbnail(get_the_ID(), 'full', array('class' => 'word-image'));
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

/*
 * The shortcode function that sets up the flashcard widget
 */
function ll_tools_flashcard_widget($atts) {
	// Set the version numbers for the CSS and JS files. (This needs to be incremented whenever the file changes)
	$js_version = '1.0.6';
	$css_version = '1.0.2';
	
    wp_enqueue_style('ll-tools-flashcard-style', plugins_url('/css/flashcard-style.css', __FILE__), array(), $css_version);

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
	
    $atts = shortcode_atts(array(
        'category' => '', // Optional category
    ), $atts);

    // Query arguments for fetching words
    $args = array(
        'post_type'      => 'words',
        'posts_per_page' => -1, // Adjust if you want to limit the total words loaded at once
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
            $words_data[] = array(
                'title' => get_the_title(),
                'image' => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                'audio' => get_post_meta(get_the_ID(), 'word_audio_file', true),
            );
        }
    }
    wp_reset_postdata();

    // Preload words data to the page
    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsData', $words_data);

    // Output the initial setup and the button
    ob_start();
    echo '<div id="ll-tools-flashcard-container">';
    echo '<button id="ll-tools-start-flashcard">Next Word</button>';
    echo '<div id="ll-tools-flashcard" class="hidden">
            <img src="/wp-content/uploads/2024/02/question-mark.png" alt="" />
            <audio controls class="hidden"></audio>
          </div>';
    echo '</div>';
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
        
        <?php
        echo 'Select Categories:<br>';
        ll_display_categories_checklist('word-category');
        ?>
        
        <input type="hidden" name="action" value="process_audio_files">
        <input type="submit" value="Upload Audio Files">
    </form>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
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

    // Prepare for file upload handling
    $selected_categories = isset($_POST['ll_word_categories']) ? (array) $_POST['ll_word_categories'] : [];
    foreach ($_FILES['ll_audio_files']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['ll_audio_files']['error'][$key] === UPLOAD_ERR_OK && is_uploaded_file($tmp_name)) {
            // Capture the original filename
            $original_name = $_FILES['ll_audio_files']['name'][$key];

            // Remove the file extension and sanitize
            $title_without_extension = pathinfo($original_name, PATHINFO_FILENAME);

            // Normalize the case of the title, special handling for Turkish characters
            $formatted_title = ll_normalize_case(sanitize_text_field($title_without_extension));

            // Proceed with file upload and other operations
            $file_name = sanitize_file_name($original_name);
            $upload_dir = wp_upload_dir();
            $upload_path = $upload_dir['path'] . '/' . $file_name;

            // Check if the file exists and append a suffix if it does
            $counter = 1;
            $file_info = pathinfo($file_name);
            $original_basename = $file_info['filename']; // Filename without extension
            $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';

            // Loop to find a unique filename
            while (file_exists($upload_path)) {
                $new_basename = $original_basename . '-' . $counter;
                $file_name = $new_basename . $extension;
                $upload_path = $upload_dir['path'] . '/' . $file_name;
                $counter++;
            }

            if (move_uploaded_file($tmp_name, $upload_path)) {
                // Use the normalized title as the post title
                $post_id = wp_insert_post([
                    'post_title'    => $formatted_title,
                    'post_content'  => '',
                    'post_status'   => 'publish',
                    'post_type'     => 'words',
                ]);

                if ($post_id) {
                    // Save the relative path of the audio file and full transcription as post meta
                    $relative_upload_path = '/wp-content/uploads' . str_replace($upload_dir['basedir'], '', $upload_path);
                    update_post_meta($post_id, 'word_audio_file', $relative_upload_path);

                    // Translate the title from Turkish to English and save if successful
                    $english_title = translate_with_deepl($formatted_title, 'EN', 'TR');
                    if (!is_null($english_title)) {
                        update_post_meta($post_id, 'word_english_meaning', $english_title);
                    }

                    // Assign selected categories to the post
                    if (!empty($selected_categories)) {
                        $selected_categories = array_map('intval', $selected_categories);
                        wp_set_object_terms($post_id, $selected_categories, 'word-category', false);
                    }
                }
            }
        }
    }

    // Redirect to prevent resubmissions and indicate process completion
    wp_redirect(admin_url('edit.php?post_type=words'));
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
        'translate' => 'yes', // Default is to translate
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
	
    $post = ll_find_post_by_exact_title($normalized_content, 'words');

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

?>
