<?php

// Register the "language" taxonomy for the "words" custom post type
function ll_register_language_taxonomy() {
    $labels = array(
        'name' => __('Languages', 'll-tools-text-domain'),
        'singular_name' => __('Language', 'll-tools-text-domain'),
        // Add other labels as needed
    );

    $args = array(
        'labels' => $labels,
        'hierarchical' => false,
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => array('slug' => 'language'),
    );

    register_taxonomy('language', array('words'), $args);

    // Insert pre-defined language terms
    $languages = array(
        'en_us' => 'English (U.S.)',
        'en_uk' => 'English (U.K.)',
        // Add more languages as needed
    );

    foreach ($languages as $language_code => $language_name) {
        wp_insert_term($language_name, 'language', array('slug' => $language_code));
    }
}
add_action('init', 'll_register_language_taxonomy');