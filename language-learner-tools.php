<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 1.0.1
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
            <img src="" alt="Word Image" />
            <audio controls></audio>
          </div>';
    echo '</div>';
    return ob_get_clean();
}

/*
 * The AJAX Handler function to fetch a random word
 */
function ll_tools_get_random_word() {
    // Check the nonce for security
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'll_tools_flashcard_nonce')) {
        wp_send_json_error('Nonce verification failed', 403);
    }

    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';

    // Set up the query arguments
    $args = array(
        'post_type'      => 'words',
        'posts_per_page' => -1,
        'orderby'        => 'rand',
        'tax_query'      => array()
    );

    // Add category to query if specified
    if (!empty($category)) {
        $args['tax_query'][] = array(
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category
        );
    }

    // Fetch the posts
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $data = array(
                'title' => get_the_title(),
                'image' => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                'audio' => get_post_meta(get_the_ID(), 'word_audio_file', true)
            );
            wp_send_json_success($data);
        }
    } else {
        wp_send_json_error('No words found', 404);
    }

    // Always reset postdata after a custom query
    wp_reset_postdata();
}
add_action('wp_ajax_get_random_word', 'll_tools_get_random_word');
add_action('wp_ajax_nopriv_get_random_word', 'll_tools_get_random_word');

