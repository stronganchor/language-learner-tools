<?php
// File: includes/post-types/vocab-lesson-post-type.php
if (!defined('WPINC')) { die; }

/**
 * Register the vocab lesson custom post type.
 */
function ll_tools_register_vocab_lesson_post_type() {
    $labels = [
        'name'               => esc_html__('Vocab Lessons', 'll-tools-text-domain'),
        'singular_name'      => esc_html__('Vocab Lesson', 'll-tools-text-domain'),
        'add_new_item'       => esc_html__('Add New Vocab Lesson', 'll-tools-text-domain'),
        'edit_item'          => esc_html__('Edit Vocab Lesson', 'll-tools-text-domain'),
        'new_item'           => esc_html__('New Vocab Lesson', 'll-tools-text-domain'),
        'view_item'          => esc_html__('View Vocab Lesson', 'll-tools-text-domain'),
        'search_items'       => esc_html__('Search Vocab Lessons', 'll-tools-text-domain'),
        'not_found'          => esc_html__('No vocab lessons found', 'll-tools-text-domain'),
        'not_found_in_trash' => esc_html__('No vocab lessons found in Trash', 'll-tools-text-domain'),
        'menu_name'          => esc_html__('Vocab Lessons', 'll-tools-text-domain'),
    ];

    $args = [
        'label'               => esc_html__('Vocab Lessons', 'll-tools-text-domain'),
        'labels'              => $labels,
        'description'         => '',
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'show_in_rest'        => false,
        'rewrite'             => false, // Custom rewrite rules handle /{wordset}/{category}.
        'query_var'           => 'll_vocab_lesson',
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'supports'            => ['title', 'editor'],
    ];

    register_post_type('ll_vocab_lesson', $args);
}
add_action('init', 'll_tools_register_vocab_lesson_post_type', 0);

