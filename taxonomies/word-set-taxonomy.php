<?php
// Register the "word-set" taxonomy
function ll_tools_register_word_set_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Sets", "astra"),
        "singular_name" => esc_html__("Word Set", "astra"),
        "add_new_item" => esc_html__("Add New Word Set", "astra"),
    ];

    $args = [
        "label" => esc_html__("Word Sets", "astra"),
        "labels" => $labels,
        "public" => true,
        "publicly_queryable" => true,
        "hierarchical" => false,
        "show_ui" => true,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "query_var" => true,
        "rewrite" => ['slug' => 'word-sets', 'with_front' => true,],
        "show_admin_column" => false,
        "show_in_rest" => true,
        "show_tagcloud" => false,
        "rest_base" => "word-sets",
        "rest_controller_class" => "WP_REST_Terms_Controller",
        "rest_namespace" => "wp/v2",
        "show_in_quick_edit" => true,
        "sort" => false,
        "show_in_graphql" => false,
    ];
    register_taxonomy("word-set", ["words", "word_set"], $args);
}
add_action('init', 'll_tools_register_word_set_taxonomy');

// Function to handle creation of a new word set
function ll_create_new_word_set($word_set_name, $language, $user_id) {
    if (empty($word_set_name) || empty($language) || empty($user_id)) {
        return false; // Ensure all information is provided
    }

    // Insert new word set term
    $term = wp_insert_term($word_set_name, 'word_set');

    if (is_wp_error($term)) {
        return false; // Handle errors in term creation
    }

    // Store additional metadata for the term
    add_term_meta($term['term_id'], 'll_language', $language, true);
    add_term_meta($term['term_id'], 'll_created_by', $user_id, true);

    return $term;
}

// Update the "word_set" taxonomy
function ll_update_word_set_taxonomy() {
    $args = array(
        'capabilities' => array(
            'manage_terms' => 'edit_word_sets',
            'edit_terms' => 'edit_word_sets',
            'delete_terms' => 'edit_word_sets',
            'assign_terms' => 'edit_word_sets',
        ),
    );
    register_taxonomy('word_set', array('words'), $args);
}
add_action('init', 'll_update_word_set_taxonomy');

// Return HTML for displaying the names of the word sets this user has created
function ll_get_user_word_sets($user_id) {
    $word_sets = get_terms(array(
        'taxonomy' => 'word_set',
        'hide_empty' => false,
        'meta_query' => array(
            array(
                'key' => 'll_created_by',
                'value' => $user_id,
                'compare' => '='
            )
        )
    ));

    if (is_wp_error($word_sets)) {
        return '<p>You have not created any word sets yet.</p>'; // Handle the error accordingly
    }

    $return_content = '<ul>';
    foreach ($word_sets as $word_set) {
        $return_content .= '<li>' . esc_html($word_set->name) . '</li>';
    }
    $return_content .= '</ul>';
    return $return_content;
}

