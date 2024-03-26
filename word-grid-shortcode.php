<?php

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

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
