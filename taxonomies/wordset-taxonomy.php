<?php
// Register the "wordset" taxonomy
function ll_tools_register_wordset_taxonomy() {
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
        "rewrite" => ['slug' => 'wordsets', 'with_front' => true,],
        "show_admin_column" => false,
        "show_in_rest" => true,
        "show_tagcloud" => false,
        "rest_base" => "wordsets",
        "rest_controller_class" => "WP_REST_Terms_Controller",
        "rest_namespace" => "wp/v2",
        "show_in_quick_edit" => true,
        "sort" => false,
        "show_in_graphql" => false,
        "capabilities" => [
            'manage_terms' => 'edit_wordsets',
            'edit_terms' => 'edit_wordsets',
            'delete_terms' => 'edit_wordsets',
            'assign_terms' => 'edit_wordsets',
        ],
    ];
    register_taxonomy("wordset", ["words"], $args);
}
add_action('init', 'll_tools_register_wordset_taxonomy');

// Add custom capabilities to the administrator role for the "wordset" taxonomy
function ll_add_admin_caps_for_wordsets() {
    // Get the administrator role
    $role = get_role('administrator');

    // Check if the role exists
    if ($role) {
        // Add custom capabilities related to the 'wordset' taxonomy
        $role->add_cap('edit_wordsets');
        $role->add_cap('manage_wordsets');
        $role->add_cap('delete_wordsets');
        $role->add_cap('assign_wordsets');
    }
}
// Hook this function to run after your taxonomy has been registered
add_action('init', 'll_add_admin_caps_for_wordsets', 11);

// Add language field to the word set taxonomy admin page
function ll_add_wordset_language_field($term) {
    $language = '';
    if (!is_string($term) && $term) { // Check if we are editing an existing term
        $language = get_term_meta($term->term_id, 'll_language', true);
    }
    ?>
    <div class="form-field term-language-wrap">
        <label for="wordset-language"><?php _e('Language', 'll-tools-text-domain'); ?></label>
        <input type="text" id="wordset-language" name="wordset_language" value="<?php echo esc_attr($language); ?>" required>
        <p><?php _e('Enter the language for this word set.', 'll-tools-text-domain'); ?></p>
    </div>
    <?php
}
add_action('wordset_add_form_fields', 'll_add_wordset_language_field');
add_action('wordset_edit_form_fields', 'll_add_wordset_language_field');

// Save the language when a new word set is created or edited
function ll_save_wordset_language($term_id) {
    if (isset($_POST['wordset_language'])) {
        $language = sanitize_text_field($_POST['wordset_language']);
        update_term_meta($term_id, 'll_language', $language);
    }
}
add_action('created_wordset', 'll_save_wordset_language');
add_action('edited_wordset', 'll_save_wordset_language');

// Function to handle creation of a new word set
function ll_create_new_wordset($wordset_name, $language, $user_id) {
    if (empty($wordset_name) || empty($language) || empty($user_id)) {
        // return an error message understandable by is_wp_error()
        return new WP_Error('missing_data', 'Missing required data');
    }

    // Insert new word set term
    $term = wp_insert_term($wordset_name, 'wordset');

    if (is_wp_error($term)) {
        return new WP_Error('missing_data', 'Error creating word set: ' . $term->get_error_message());
    }

    // Store additional metadata for the term
    add_term_meta($term['term_id'], 'll_language', $language, true);
    add_term_meta($term['term_id'], 'll_created_by', $user_id, true);

    return $term;
}

// Return HTML for displaying the names of the word sets this user has created
function ll_get_user_wordsets($user_id) {
    $wordsets = get_terms(array(
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'meta_query' => array(
            array(
                'key' => 'll_created_by',
                'value' => $user_id,
                'compare' => '='
            )
        )
    ));

    if (is_wp_error($wordsets)) {
        return '<p>You have not created any word sets yet.</p>'; // Handle the error accordingly
    }

    $return_content = '<ul>';
    foreach ($wordsets as $wordset) {
        $language_name = get_term_meta($wordset->term_id, 'll_language', true);
        $return_content .= '<li>' . esc_html($wordset->name) . ' (' . esc_html($language_name) . ')</li>';
    }
    $return_content .= '</ul>';
    return $return_content;
}

