<?php

// Register the "word_set" taxonomy for the "words" custom post type
function ll_register_word_set_taxonomy() {
    $labels = array(
        'name' => __('Word Sets', 'll-tools-text-domain'),
        'singular_name' => __('Word Set', 'll-tools-text-domain'),
        // Add other labels as needed
    );

    $args = array(
        'labels' => $labels,
        'hierarchical' => true,
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => array('slug' => 'word-set'),
    );

    register_taxonomy('word_set', array('words'), $args);
}
add_action('init', 'll_register_word_set_taxonomy');