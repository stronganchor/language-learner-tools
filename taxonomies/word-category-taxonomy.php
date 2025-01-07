<?php

/**
 * Registers the "word-category" taxonomy for "words" and "word_images" post types.
 *
 * @return void
 */
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

    // Initialize translation meta fields
    ll_tools_initialize_word_category_meta_fields();
}
add_action('init', 'll_tools_register_word_category_taxonomy');

/**
 * Initializes custom meta fields for the "word-category" taxonomy.
 *
 * @return void
 */
function ll_tools_initialize_word_category_meta_fields() {
    // Add 'Translated Name' field for adding new categories
    add_action('word-category_add_form_fields', 'll_add_translation_field');
    // Add 'Translated Name' field for editing existing categories
    add_action('word-category_edit_form_fields', 'll_edit_translation_field');
    // Save the 'Translated Name' meta field
    add_action('created_word-category', 'll_save_translation_field', 10, 2);
    add_action('edited_word-category', 'll_save_translation_field', 10, 2);
}

/**
 * Adds the 'Translated Name' field to the add new category form.
 *
 * @param WP_Term $term Term object.
 */
function ll_add_translation_field($term) {
    if (!ll_tools_is_category_translation_enabled()) {
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

/**
 * Adds the 'Translated Name' field to the edit category form.
 *
 * @param WP_Term $term Term object.
 */
function ll_edit_translation_field($term) {
    if (!ll_tools_is_category_translation_enabled()) {
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

/**
 * Saves the 'Translated Name' meta field for a term.
 *
 * @param int    $term_id Term ID.
 * @param string $taxonomy Taxonomy name.
 */
function ll_save_translation_field($term_id, $taxonomy) {
    if (isset($_POST['term_translation'])) {
        $similar_word_id = sanitize_text_field($_POST['term_translation']);
        update_term_meta($term_id, 'term_translation', $similar_word_id);
    }
}

/**
 * Retrieves the translated name of a category.
 *
 * @param int $term_id Term ID.
 * @return string Translated name or default name if not translated.
 */
function ll_get_translated_category_name($term_id) {
    $translation = get_term_meta($term_id, 'term_translation', true);
    if ($translation) {
        return $translation;
    }

    $term = get_term($term_id);
    return $term ? $term->name : '';
}

/**
 * Checks if category translation is enabled.
 *
 * @return bool True if enabled, false otherwise.
 */
function ll_tools_is_category_translation_enabled() {
    return (bool) get_option('ll_enable_category_translation', 0);
}

/**
 * Determines the deepest-level categories for a given post.
 *
 * @param int $post_id The post ID.
 * @return array An array of deepest-level category objects.
 */
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

/**
 * Recursively determines the depth of a category in the category hierarchy.
 * 
 * @param int $category_id The category ID.
 * @param int $depth The current depth.
 * @return int The depth of the category.
 */
function ll_get_category_depth($category_id, $depth = 0) {
    $parent_id = get_term_field('parent', $category_id, 'word-category');
    if ($parent_id != 0) {
        $depth = ll_get_category_depth($parent_id, $depth + 1);
    }
    return $depth;
}

/**
 * Retrieves words by category name.
 * 
 * @param string $category_name The name of the category.
 * @param string $display_mode The display mode ('image' or 'text').
 * @return array An array of word data.
 */
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
