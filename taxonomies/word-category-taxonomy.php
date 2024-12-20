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

// Add custom fields for translated name
add_action('word-category_add_form_fields', 'll_add_translation_field');
add_action('word-category_edit_form_fields', 'll_edit_translation_field');
add_action('created_word-category', 'll_save_translation_field', 10, 2);
add_action('edited_word-category', 'll_save_translation_field', 10, 2);

// Add the 'Translated Name' field for adding a new category
function ll_add_translation_field() {
	// Check if category translation is enabled
    $enable_translation = get_option('ll_enable_category_translation', 0);

    if (!$enable_translation) {
        return;
    }
	
    ?>
    <div class="form-field term-translation-wrap">
        <label for="term-translation"><?php esc_html_e('Translated Name', 'll-tools-text-domain'); ?></label>
        <input type="text" name="term_translation" id="term-translation" value="" />
        <p class="description"><?php esc_html_e('Enter the translated name for this category.', 'll-tools-text-domain'); ?></p>
    </div>
    <?php
}

// Add the 'Translated Name' field for editing an existing category
function ll_edit_translation_field($term) {
	// Check if category translation is enabled
    $enable_translation = get_option('ll_enable_category_translation', 0);

    if (!$enable_translation) {
        return;
    }
	
    $translation = get_term_meta($term->term_id, 'term_translation', true);
    ?>
    <tr class="form-field term-translation-wrap">
        <th scope="row">
            <label for="term-translation"><?php esc_html_e('Translated Name', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <input type="text" name="term_translation" id="term-translation" value="<?php echo esc_attr($translation); ?>" />
            <p class="description"><?php esc_html_e('Enter the translated name for this category.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <?php
}

// Save the translated name
function ll_save_translation_field($term_id, $taxonomy) {
    if (isset($_POST['term_translation'])) {
        update_term_meta($term_id, 'term_translation', sanitize_text_field($_POST['term_translation']));
    }
}

// Retrieve the translated name of a category
function ll_get_translated_category_name($term_id) {
    $translation = get_term_meta($term_id, 'term_translation', true);
    if ($translation) {
        return $translation;
    }

    $term = get_term($term_id);
    return $term ? $term->name : '';
}

// Deprecated.  Consider removing if we aren't going to use it.
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

function ll_get_words_by_category($category_name, $display_mode = 'image') {
    $args = array(
        'post_type' => 'words',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'word-category',
                'field' => 'name',
                'terms' => $category_name,
            ),
        ),
    );

    if ($display_mode === 'image') {
        $args['meta_query'] = array(
            array(
                'key' => '_thumbnail_id',
                'compare' => 'EXISTS',
            ),
        );
    } else {
        $args['meta_query'] = array(
            array(
                'key' => 'word_english_meaning',
                'compare' => 'EXISTS',
                ),
            );
        }

    $query = new WP_Query($args);
    $words_data = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $deepest_categories = ll_get_deepest_categories($post_id);

            // Extract category names from the deepest categories
            $deepest_category_names = array_map(function($cat) {
                return $cat->name;
            }, $deepest_categories);

            foreach ($deepest_category_names as $deepest_category_name) {
                if ($deepest_category_name === $category_name) {
                    $words_data[] = array(
                        'id' => $post_id,
                        'title' => get_the_title(),
                        'image' => wp_get_attachment_url(get_post_thumbnail_id($post_id)),
                        'audio' => get_post_meta($post_id, 'word_audio_file', true),
                        'similar_word_id' => get_post_meta($post_id, 'similar_word_id', true),
                        'category' => $deepest_category_name,
                        'all_categories' => $deepest_category_names,
                        'translation' => get_post_meta($post_id, 'word_english_meaning', true),
                    );
                    break;
                }
            }
        }
        wp_reset_postdata();
    }

    return $words_data;
}
