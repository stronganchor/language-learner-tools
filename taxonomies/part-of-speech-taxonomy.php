<?php

// Register the "part_of_speech" taxonomy for the "words" custom post type
function ll_register_part_of_speech_taxonomy() {
    $labels = array(
        'name' => __('Parts of Speech', 'll-tools-text-domain'),
        'singular_name' => __('Part of Speech', 'll-tools-text-domain'),
        // Add other labels as needed
    );

    $args = array(
        'labels' => $labels,
        'hierarchical' => false,
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => array('slug' => 'part-of-speech'),
    );

    register_taxonomy('part_of_speech', array('words'), $args);

    // Insert pre-defined part of speech terms
    $parts_of_speech = array(
        'noun' => 'Noun',
        'verb' => 'Verb',
        // Add more parts of speech as needed
    );

    foreach ($parts_of_speech as $part_of_speech_slug => $part_of_speech_name) {
        wp_insert_term($part_of_speech_name, 'part_of_speech', array('slug' => $part_of_speech_slug));
    }
}
add_action('init', 'll_register_part_of_speech_taxonomy');