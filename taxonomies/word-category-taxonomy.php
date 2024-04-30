<?php

// Register the "word-category" taxonomy
function ll_tools_register_word_category_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Categories", "astra"),
        "singular_name" => esc_html__("Word Category", "astra"),
    ];

    $args = [
        "label" => esc_html__("Word Categories", "astra"),
        "labels" => $labels,
        "public" => true,
        "publicly_queryable" => true,
        "hierarchical" => true,
        "show_ui" => true,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "query_var" => true,
        "rewrite" => ['slug' => 'word-category', 'with_front' => true,],
        "show_admin_column" => false,
        "show_in_rest" => true,
        "show_tagcloud" => false,
        "rest_base" => "word-category",
        "rest_controller_class" => "WP_REST_Terms_Controller",
        "rest_namespace" => "wp/v2",
        "show_in_quick_edit" => true,
        "sort" => false,
        "show_in_graphql" => false,
    ];
    register_taxonomy("word-category", ["words", "word_images"], $args);
}
add_action('init', 'll_tools_register_word_category_taxonomy');

function ll_get_deepest_category_word_count($category_id) {
    $args = array(
        'post_type' => 'words',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => $category_id,
                'include_children' => true,
            ),
        ),
    );

    $query = new WP_Query($args);
    $word_count = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $deepest_categories = ll_get_deepest_categories($post_id);

            foreach ($deepest_categories as $deepest_category) {
                if ($deepest_category->term_id == $category_id) {
                    $word_count++;
                    break;
                }
            }
        }
        wp_reset_postdata();
    }

    return $word_count;
}

function ll_get_deepest_categories($post_id) {
    $categories = wp_get_post_terms($post_id, 'word-category');
    $deepest_categories = array();
    $max_depth = -1;

    foreach ($categories as $category) {
        $depth = ll_get_category_depth($category->term_id);
        if ($depth > $max_depth) {
            $max_depth = $depth;
            $deepest_categories = array($category);
        } elseif ($depth == $max_depth) {
            $deepest_categories[] = $category;
        }
    }

    return $deepest_categories;
}

function ll_get_category_depth($category_id, $depth = 0) {
    $parent_id = get_term_field('parent', $category_id, 'word-category');
    if ($parent_id != 0) {
        $depth = ll_get_category_depth($parent_id, $depth + 1);
    }
    return $depth;
}