<?php
// Register the "wordset" taxonomy
function ll_tools_register_wordset_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Sets"),
        "singular_name" => esc_html__("Word Set"),
        "add_new_item" => esc_html__("Add New Word Set"),
        "name_field_description" => esc_html__("Enter a name for the word set."),
        "edit_item" => esc_html__("Edit Word Set"),
        "go_to_word_sets" => esc_html__("Go to Word Sets"),
        "view_item" => esc_html__("View Word Set"),
        "update_item" => esc_html__("Update Word Set"),
        "add_or_remove_items" => esc_html__("Add or remove word sets"),
        "choose_from_most_used" => esc_html__("Choose from the most used word sets"),
        "popular_items" => esc_html__("Popular Word Sets"),
        "search_items" => esc_html__("Search Word Sets"),
        "not_found" => esc_html__("No word sets found"),
        "no_terms" => esc_html__("No word sets"),
        "items_list_navigation" => esc_html__("Word sets list navigation"),
        "items_list" => esc_html__("Word sets list"),
        "back_to_items" => esc_html__("← Back to Word Sets"),
        "menu_name" => esc_html__("Word Sets"),
        "all_items" => esc_html__("All Word Sets"),
        "parent_item" => esc_html__("Parent Word Set"),
        "parent_item_colon" => esc_html__("Parent Word Set:"),
    ];

    $args = [
        "label" => esc_html__("Word Sets"),
        "labels" => $labels,
        "public" => true,
        "publicly_queryable" => true,
        'exclude_from_search'=> false,
        'has_archive'        => true,
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

// Edit the columns on the word set taxonomy admin page
function modify_wordset_columns($columns) {
    $columns['ll_language'] = __('Language', 'textdomain');
    
    // Don't remove columns if user is an administrator
    if (current_user_can('manage_options')) {
        $columns['manager_user_id'] = __('Manager', 'textdomain');
        return $columns;
    }

    unset($columns['description']);
    unset($columns['slug']);
    return $columns;
}
add_filter('manage_edit-wordset_columns', 'modify_wordset_columns');

// Display custom columns in the word set taxonomy admin page
function display_wordset_columns($content, $column_name, $term_id) {
    // switch case statement for column name
    switch ($column_name) {
        case 'll_language':
            $language = get_term_meta($term_id, 'll_language', true);
            if (!empty($language)) {
                $content = esc_html($language);
            } else {
                $content = '—';
            }
            break;
        case 'manager_user_id':
            $user_id = get_term_meta($term_id, 'manager_user_id', true);
            if (!empty($user_id)) {
                $user = get_user_by('ID', $user_id);
                $content = esc_html($user->display_name);
            } else {
                $content = '—';
            }
            break;
    }
    return $content;
}
add_filter('manage_wordset_custom_column', 'display_wordset_columns', 10, 3);

// Only show the word sets that the user created or is managing
function filter_wordset_by_user($query) {
    // Only filter for non-admins
    if (!current_user_can('manage_options')) {
        $user_id = get_current_user_id();
        $query->query_vars['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key' => 'manager_user_id',
                'compare' => 'NOT EXISTS', // If the field doesn't exist, it's probably not the wordset taxonomy
            ),
            array(
                'key' => 'manager_user_id',
                'value' => $user_id,
                'compare' => '=',
            ),
        );
    }
}
add_action('pre_get_terms', 'filter_wordset_by_user');

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

// Enqueue the script for the wordset taxonomy
function ll_enqueue_wordsets_script() {
    // Assuming this function is in a file located in /pages/ directory of your plugin
    // Go up one level to the plugin root, then into the /js/ directory
    $js_path = LL_TOOLS_BASE_PATH . 'js/manage-wordsets.js';
    $version = filemtime($js_path); // File modification time as version
    $userId = get_current_user_id();

    wp_enqueue_script('manage-wordsets-script', LL_TOOLS_BASE_URL . 'js/manage-wordsets.js', array('jquery', 'jquery-ui-autocomplete'), $version, true);

    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
    ]);
    $language_data = array_map(function($language) {
        return array('label' => esc_html($language->name), 'value' => esc_attr($language->term_id));
    }, $languages);

    wp_localize_script('manage-wordsets-script', 'manageWordSetData', array(
        'availableLanguages' => $language_data,
		'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('create_wordset_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'll_enqueue_wordsets_script');

// AJAX handler for language suggestions
function ll_suggest_languages() {
    $search = isset($_REQUEST['q']) ? sanitize_text_field($_REQUEST['q']) : '';
    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
        'search' => $search,
    ]);

    $suggestions = [];
    foreach ($languages as $language) {
        $suggestions[] = [
            'label' => $language->name,
            'value' => $language->term_id,
        ];
    }

    wp_send_json($suggestions);
}
add_action('wp_ajax_ll_suggest_languages', 'll_suggest_languages');

// Save the language when a new word set is created or edited
function ll_save_wordset_language($term_id) {
    if (isset($_POST['wordset_language'])) {
        $language = sanitize_text_field($_POST['wordset_language']);
        update_term_meta($term_id, 'll_language', $language);
    }

    // save the user who created the wordset
    $user_id = get_current_user_id();
    update_term_meta($term_id, 'manager_user_id', $user_id);
}
add_action('created_wordset', 'll_save_wordset_language');
add_action('edited_wordset', 'll_save_wordset_language');

// Get the language of a word set
function ll_get_wordset_language($term_id) {
    return get_term_meta($term_id, 'll_language', true);
}

/**
 * Active word set resolution.
 * - If an explicit ID is provided, use it (when admins pass it).
 * - Else if a default is saved in options, use it.
 * - Else if exactly one word set exists, use that.
 * - Else return 0 (caller can decide to warn or require selection).
 */
function ll_tools_get_active_wordset_id($explicit = 0): int {
    $explicit = (int) $explicit;
    if ($explicit > 0 && term_exists($explicit, 'wordset')) {
        return $explicit;
    }
    $opt = (int) get_option('ll_default_wordset_id', 0);
    if ($opt > 0 && term_exists($opt, 'wordset')) {
        return $opt;
    }
    $all = get_terms(['taxonomy' => 'wordset', 'hide_empty' => false, 'fields' => 'ids']);
    if (!is_wp_error($all) && is_array($all) && count($all) === 1) {
        return (int) $all[0];
    }
    return 0;
}

/**
 * On first request after activation:
 *  - Ensure at least one 'wordset' term exists (create "Default Word Set" if none).
 *  - If exactly one wordset exists, assign it to all 'words' posts that have none.
 */
function ll_tools_maybe_seed_default_wordset_and_assign() {
    // Only run if activation set the transient.
    if (!get_transient('ll_tools_seed_default_wordset')) {
        return;
    }
    delete_transient('ll_tools_seed_default_wordset');

    // Safety: make sure taxonomy/post type exist.
    if (!taxonomy_exists('wordset') || !post_type_exists('words')) {
        return;
    }

    // Collect existing wordset terms.
    $term_ids = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if (is_wp_error($term_ids)) {
        return;
    }

    // If none, create "Default Word Set".
    if (empty($term_ids)) {
        $created = wp_insert_term(
            __('Default Word Set', 'll-tools-text-domain'),
            'wordset',
            ['slug' => 'default-word-set']
        );
        if (is_wp_error($created) || !isset($created['term_id'])) {
            return; // Couldn’t create; bail quietly.
        }
        $term_ids = [(int) $created['term_id']];
    }

    // Only auto-assign when exactly one wordset exists.
    if (count($term_ids) !== 1) {
        return;
    }
    $the_only_wordset_id = (int) array_values($term_ids)[0];

    // Find all "words" posts with no wordset term.
    $orphans = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'operator' => 'NOT EXISTS',
        ]],
    ]);

    if (!is_wp_error($orphans) && !empty($orphans->posts)) {
        foreach ($orphans->posts as $post_id) {
            wp_set_object_terms((int) $post_id, $the_only_wordset_id, 'wordset', false);
        }
    }
}
