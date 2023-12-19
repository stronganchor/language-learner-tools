<?php
/**
 * Plugin Name: * Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 *
 * This plugin is designed to enhance the display of vocabulary items in a custom
 * post type called 'words'. It adds the English meaning and an audio file to each post.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Enqueue plugin styles.
 */
function ll_tools_enqueue_styles() {
    // Set the version number as a variable. This should be updated each time you make changes to the CSS file.
    $css_version = '1.0.12'; // Increment this number with every update to the CSS file

    // Enqueue the CSS file with the version number set
    wp_enqueue_style('ll-tools-style', plugins_url('language-learner-tools.css', __FILE__), array(), $css_version);
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_styles');

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

        // Build the custom output
        $custom_content = "<div class='vocab-item'>";

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
 * Register the shortcode with WordPress.
 */
function ll_tools_register_shortcodes() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
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
        echo '<div class="word-grid">'; // Grid container
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
