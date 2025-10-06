<?php // File: includes/taxonomies/recording-type-taxonomy.php
/**
 * Register the "recording_type" taxonomy for word_audio
 */

if (!defined('WPINC')) { die; }

function ll_tools_register_recording_type_taxonomy() {
    $labels = [
        'name' => __('Recording Types', 'll-tools-text-domain'),
        'singular_name' => __('Recording Type', 'll-tools-text-domain'),
        'search_items' => __('Search Recording Types', 'll-tools-text-domain'),
        'all_items' => __('All Recording Types', 'll-tools-text-domain'),
        'edit_item' => __('Edit Recording Type', 'll-tools-text-domain'),
        'update_item' => __('Update Recording Type', 'll-tools-text-domain'),
        'add_new_item' => __('Add New Recording Type', 'll-tools-text-domain'),
        'new_item_name' => __('New Recording Type Name', 'll-tools-text-domain'),
        'menu_name' => __('Recording Types', 'll-tools-text-domain'),
    ];

    $args = [
        'labels' => $labels,
        'hierarchical' => false,
        'public' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_nav_menus' => false,
        'show_in_rest' => true,
        'query_var' => true,
        'rewrite' => false,
    ];

    register_taxonomy('recording_type', ['word_audio'], $args);

    // Only seed defaults once
    if (get_option('ll_recording_types_seeded')) {
        return;
    }

    $default_types = [
        'isolation' => __('Isolation', 'll-tools-text-domain'),
        'question' => __('Question', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
        'sentence' => __('In Sentence', 'll-tools-text-domain'),
    ];

    foreach ($default_types as $slug => $name) {
        if (!term_exists($slug, 'recording_type')) {
            wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        }
    }

    update_option('ll_recording_types_seeded', true);
}
add_action('init', 'll_tools_register_recording_type_taxonomy');