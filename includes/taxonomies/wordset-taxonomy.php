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
    $columns['ll_language'] = __('Language', 'll-tools-text-domain');
    
    // Don't remove columns if user is an administrator
    if (current_user_can('manage_options')) {
        $columns['manager_user_id'] = __('Manager', 'll-tools-text-domain');
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
    // Don't filter for administrators
    if (current_user_can('manage_options')) {
        return;
    }

    // Don't filter for AJAX requests (public quizzes)
    if (wp_doing_ajax()) {
        return;
    }

    // Don't filter for frontend queries (public quizzes)
    if (!is_admin()) {
        return;
    }

    // For non-admin users in the admin area, filter by their managed wordsets
    $user_id = get_current_user_id();
    $query->query_vars['meta_query'] = array(
        'relation' => 'OR',
        array(
            'key' => 'manager_user_id',
            'compare' => 'NOT EXISTS',
        ),
        array(
            'key' => 'manager_user_id',
            'value' => $user_id,
            'compare' => '=',
        ),
    );
}
add_action('pre_get_terms', 'filter_wordset_by_user');

function ll_tools_wordset_render_admin_field(bool $is_edit, string $wrap_class, string $label, string $field_html, string $field_id = '', string $description = ''): void {
    if ($is_edit) {
        echo '<tr class="form-field ' . esc_attr($wrap_class) . '">';
        echo '<th scope="row">';
        if ($field_id !== '') {
            echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . '</label>';
        } else {
            echo esc_html($label);
        }
        echo '</th><td>';
        echo $field_html;
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
        return;
    }

    echo '<div class="form-field ' . esc_attr($wrap_class) . '">';
    if ($field_id !== '') {
        echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . '</label>';
    } else {
        echo '<label>' . esc_html($label) . '</label>';
    }
    echo $field_html;
    if ($description !== '') {
        echo '<p>' . esc_html($description) . '</p>';
    }
    echo '</div>';
}

// Add language field to the word set taxonomy admin page
function ll_add_wordset_language_field($term) {
    $is_edit = ($term instanceof WP_Term);
    $term_id = $is_edit ? (int) $term->term_id : 0;

    $language = '';
    $hide_lesson_text_for_non_text_quiz = false;
    $has_gender = false;
    $has_plurality = false;
    $has_verb_tense = false;
    $has_verb_mood = false;
    $gender_options = function_exists('ll_tools_wordset_get_gender_default_options')
        ? ll_tools_wordset_get_gender_default_options()
        : ['Masculine', 'Feminine'];
    $plurality_options = function_exists('ll_tools_wordset_get_plurality_default_options')
        ? ll_tools_wordset_get_plurality_default_options()
        : ['Singular', 'Plural'];
    $verb_tense_options = function_exists('ll_tools_wordset_get_verb_tense_default_options')
        ? ll_tools_wordset_get_verb_tense_default_options()
        : ['Present', 'Past', 'Future'];
    $verb_mood_options = function_exists('ll_tools_wordset_get_verb_mood_default_options')
        ? ll_tools_wordset_get_verb_mood_default_options()
        : ['Indicative', 'Imperative', 'Subjunctive'];
    $masculine_symbol = '';
    $feminine_symbol = '';
    $gender_colors = function_exists('ll_tools_wordset_get_gender_color_defaults')
        ? ll_tools_wordset_get_gender_color_defaults()
        : [
            'masculine' => '#1D4D99',
            'feminine' => '#EC4899',
            'other' => '#6B7280',
        ];

    if ($term_id > 0) {
        $language = (string) get_term_meta($term_id, 'll_language', true);
        $hide_lesson_text_for_non_text_quiz = (bool) get_term_meta($term_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', true);
        $has_gender = (bool) get_term_meta($term_id, 'll_wordset_has_gender', true);
        $has_plurality = (bool) get_term_meta($term_id, 'll_wordset_has_plurality', true);
        $has_verb_tense = (bool) get_term_meta($term_id, 'll_wordset_has_verb_tense', true);
        $has_verb_mood = (bool) get_term_meta($term_id, 'll_wordset_has_verb_mood', true);
        if (function_exists('ll_tools_wordset_get_gender_options')) {
            $gender_options = ll_tools_wordset_get_gender_options($term_id);
        }
        if (function_exists('ll_tools_wordset_get_plurality_options')) {
            $plurality_options = ll_tools_wordset_get_plurality_options($term_id);
        }
        if (function_exists('ll_tools_wordset_get_verb_tense_options')) {
            $verb_tense_options = ll_tools_wordset_get_verb_tense_options($term_id);
        }
        if (function_exists('ll_tools_wordset_get_verb_mood_options')) {
            $verb_mood_options = ll_tools_wordset_get_verb_mood_options($term_id);
        }
        if (function_exists('ll_tools_wordset_get_gender_symbol_meta_key')) {
            $masculine_symbol = (string) get_term_meta($term_id, ll_tools_wordset_get_gender_symbol_meta_key('masculine'), true);
            $feminine_symbol = (string) get_term_meta($term_id, ll_tools_wordset_get_gender_symbol_meta_key('feminine'), true);
        }
        if (function_exists('ll_tools_wordset_get_gender_colors')) {
            $gender_colors = ll_tools_wordset_get_gender_colors($term_id);
        }
    }

    $gender_options_display = implode("\n", array_map('strval', $gender_options));
    $plurality_options_display = implode("\n", array_map('strval', $plurality_options));
    $verb_tense_options_display = implode("\n", array_map('strval', $verb_tense_options));
    $verb_mood_options_display = implode("\n", array_map('strval', $verb_mood_options));

    wp_nonce_field('ll_wordset_meta', 'll_wordset_meta_nonce');

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-language-wrap',
        __('Language', 'll-tools-text-domain'),
        '<input type="text" id="wordset-language" name="wordset_language" value="' . esc_attr($language) . '" required>',
        'wordset-language',
        __('Enter the language for this word set.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-hide-lesson-text-wrap',
        __('Lesson/grid text visibility', 'll-tools-text-domain'),
        '<label><input type="checkbox" id="ll-wordset-hide-lesson-text" name="ll_wordset_hide_lesson_text_for_non_text_quiz" value="1" ' . checked($hide_lesson_text_for_non_text_quiz, true, false) . ' /> ' . esc_html__('Hide word text on lesson pages/word grids when the category quiz shows only images/audio.', 'll-tools-text-domain') . '</label>',
        'll-wordset-hide-lesson-text',
        __('Categories can override this in their quiz settings.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-wrap',
        __('Grammatical gender', 'll-tools-text-domain'),
        '<label><input type="checkbox" id="ll-wordset-grammatical-gender" name="ll_wordset_has_gender" value="1" ' . checked($has_gender, true, false) . ' /> ' . esc_html__('Enable grammatical gender for this word set.', 'll-tools-text-domain') . '</label>',
        'll-wordset-grammatical-gender'
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-options-wrap',
        __('Gender options', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-gender-options" name="ll_wordset_gender_options" rows="4">' . esc_textarea($gender_options_display) . '</textarea>',
        'll-wordset-gender-options',
        __('One option per line (for example: masculine, feminine, neuter).', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-symbol-masculine-wrap',
        __('Masculine symbol', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-gender-symbol-masculine" name="ll_wordset_gender_symbol_masculine" rows="4">' . esc_textarea($masculine_symbol) . '</textarea>',
        'll-wordset-gender-symbol-masculine',
        __('Paste an SVG, emoji, or text. Leave empty to use the default masculine icon.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-symbol-feminine-wrap',
        __('Feminine symbol', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-gender-symbol-feminine" name="ll_wordset_gender_symbol_feminine" rows="4">' . esc_textarea($feminine_symbol) . '</textarea>',
        'll-wordset-gender-symbol-feminine',
        __('Paste an SVG, emoji, or text. Leave empty to use the default feminine icon.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-color-masculine-wrap',
        __('Masculine color', 'll-tools-text-domain'),
        '<input type="color" id="ll-wordset-gender-color-masculine" name="ll_wordset_gender_color_masculine" value="' . esc_attr((string) ($gender_colors['masculine'] ?? '#1D4D99')) . '">',
        'll-wordset-gender-color-masculine',
        __('Color used for masculine gender badges.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-color-feminine-wrap',
        __('Feminine color', 'll-tools-text-domain'),
        '<input type="color" id="ll-wordset-gender-color-feminine" name="ll_wordset_gender_color_feminine" value="' . esc_attr((string) ($gender_colors['feminine'] ?? '#EC4899')) . '">',
        'll-wordset-gender-color-feminine',
        __('Color used for feminine gender badges.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-grammatical-gender-color-other-wrap',
        __('Additional gender color', 'll-tools-text-domain'),
        '<input type="color" id="ll-wordset-gender-color-other" name="ll_wordset_gender_color_other" value="' . esc_attr((string) ($gender_colors['other'] ?? '#6B7280')) . '">',
        'll-wordset-gender-color-other',
        __('Color used for custom gender options beyond masculine and feminine.', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-plurality-wrap',
        __('Plurality', 'll-tools-text-domain'),
        '<label><input type="checkbox" id="ll-wordset-plurality" name="ll_wordset_has_plurality" value="1" ' . checked($has_plurality, true, false) . ' /> ' . esc_html__('Enable plurality for this word set.', 'll-tools-text-domain') . '</label>',
        'll-wordset-plurality'
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-plurality-options-wrap',
        __('Plurality options', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-plurality-options" name="ll_wordset_plurality_options" rows="4">' . esc_textarea($plurality_options_display) . '</textarea>',
        'll-wordset-plurality-options',
        __('One option per line (for example: singular, plural, dual).', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-verb-tense-wrap',
        __('Verb tense', 'll-tools-text-domain'),
        '<label><input type="checkbox" id="ll-wordset-verb-tense" name="ll_wordset_has_verb_tense" value="1" ' . checked($has_verb_tense, true, false) . ' /> ' . esc_html__('Enable verb tense tags for this word set.', 'll-tools-text-domain') . '</label>',
        'll-wordset-verb-tense'
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-verb-tense-options-wrap',
        __('Verb tense options', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-verb-tense-options" name="ll_wordset_verb_tense_options" rows="4">' . esc_textarea($verb_tense_options_display) . '</textarea>',
        'll-wordset-verb-tense-options',
        __('One option per line (for example: present, past, future).', 'll-tools-text-domain')
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-verb-mood-wrap',
        __('Verb mood', 'll-tools-text-domain'),
        '<label><input type="checkbox" id="ll-wordset-verb-mood" name="ll_wordset_has_verb_mood" value="1" ' . checked($has_verb_mood, true, false) . ' /> ' . esc_html__('Enable verb mood tags for this word set.', 'll-tools-text-domain') . '</label>',
        'll-wordset-verb-mood'
    );

    ll_tools_wordset_render_admin_field(
        $is_edit,
        'term-verb-mood-options-wrap',
        __('Verb mood options', 'll-tools-text-domain'),
        '<textarea id="ll-wordset-verb-mood-options" name="ll_wordset_verb_mood_options" rows="4">' . esc_textarea($verb_mood_options_display) . '</textarea>',
        'll-wordset-verb-mood-options',
        __('One option per line (for example: indicative, subjunctive, imperative).', 'll-tools-text-domain')
    );
}
add_action('wordset_add_form_fields', 'll_add_wordset_language_field');
add_action('wordset_edit_form_fields', 'll_add_wordset_language_field');

// Enqueue the script for the wordset taxonomy
function ll_enqueue_wordsets_script() {
    ll_enqueue_asset_by_timestamp('/js/manage-wordsets.js', 'manage-wordsets-script', array('jquery', 'jquery-ui-autocomplete'), true);

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
    if (!current_user_can('edit_wordsets')) {
        wp_send_json_error(['message' => __('Forbidden', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('create_wordset_nonce', 'nonce');

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
    $has_meta_input = isset($_POST['wordset_language'])
        || isset($_POST['ll_wordset_hide_lesson_text_for_non_text_quiz'])
        || isset($_POST['ll_wordset_has_gender'])
        || isset($_POST['ll_wordset_gender_options'])
        || isset($_POST['ll_wordset_gender_symbol_masculine'])
        || isset($_POST['ll_wordset_gender_symbol_feminine'])
        || isset($_POST['ll_wordset_gender_color_masculine'])
        || isset($_POST['ll_wordset_gender_color_feminine'])
        || isset($_POST['ll_wordset_gender_color_other'])
        || isset($_POST['ll_wordset_has_plurality'])
        || isset($_POST['ll_wordset_plurality_options'])
        || isset($_POST['ll_wordset_has_verb_tense'])
        || isset($_POST['ll_wordset_verb_tense_options'])
        || isset($_POST['ll_wordset_has_verb_mood'])
        || isset($_POST['ll_wordset_verb_mood_options']);
    if ($has_meta_input) {
        if (!isset($_POST['ll_wordset_meta_nonce']) || !wp_verify_nonce($_POST['ll_wordset_meta_nonce'], 'll_wordset_meta')) {
            return;
        }
        if (!current_user_can('edit_wordsets')) {
            return;
        }
    }

    if (isset($_POST['wordset_language'])) {
        $language = sanitize_text_field($_POST['wordset_language']);
        update_term_meta($term_id, 'll_language', $language);
    }

    if ($has_meta_input) {
        $hide_lesson_text_for_non_text_quiz = isset($_POST['ll_wordset_hide_lesson_text_for_non_text_quiz']) ? 1 : 0;
        update_term_meta($term_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', $hide_lesson_text_for_non_text_quiz);

        $has_gender = isset($_POST['ll_wordset_has_gender']) ? 1 : 0;
        update_term_meta($term_id, 'll_wordset_has_gender', $has_gender);

        $existing_raw = get_term_meta($term_id, 'll_wordset_gender_options', true);
        $existing_options = function_exists('ll_tools_wordset_normalize_gender_options')
            ? ll_tools_wordset_normalize_gender_options($existing_raw)
            : [];
        $legacy_options = [];
        if (empty($existing_options) && function_exists('ll_tools_wordset_get_gender_default_options')) {
            $existing_options = ll_tools_wordset_get_gender_default_options();
            if (function_exists('ll_tools_wordset_get_gender_legacy_default_options')) {
                $legacy_options = ll_tools_wordset_get_gender_legacy_default_options();
            }
            if (!empty($legacy_options)
                && function_exists('ll_tools_wordset_gender_options_equal')
                && ll_tools_wordset_gender_options_equal($legacy_options, $existing_options)) {
                $legacy_options = [];
            }
        }

        $raw_options = '';
        if (isset($_POST['ll_wordset_gender_options'])) {
            $raw_options = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_gender_options'])
                : $_POST['ll_wordset_gender_options'];
        }
        $options = function_exists('ll_tools_wordset_normalize_gender_options')
            ? ll_tools_wordset_normalize_gender_options($raw_options)
            : [];
        $raw_options_trimmed = trim((string) $raw_options);
        if ($raw_options_trimmed !== '' && empty($options)) {
            // Keep existing values when submitted content cannot be normalized on this environment.
            $options = $existing_options;
        }
        $resolved_options = $options;
        if (empty($resolved_options) && function_exists('ll_tools_wordset_get_gender_default_options')) {
            $resolved_options = ll_tools_wordset_get_gender_default_options();
        }

        $options_changed = function_exists('ll_tools_wordset_gender_options_equal')
            ? !ll_tools_wordset_gender_options_equal($existing_options, $resolved_options)
            : ($existing_options !== $resolved_options);
        $legacy_sync_needed = !empty($legacy_options)
            && (function_exists('ll_tools_wordset_gender_options_equal')
                ? !ll_tools_wordset_gender_options_equal($legacy_options, $resolved_options)
                : ($legacy_options !== $resolved_options));

        if ($options_changed || $legacy_sync_needed) {
            if (function_exists('ll_tools_wordset_sync_gender_values')) {
                ll_tools_wordset_sync_gender_values($term_id, $existing_options, $resolved_options, $legacy_options);
            }
        }

        if (empty($options)) {
            delete_term_meta($term_id, 'll_wordset_gender_options');
        } else {
            update_term_meta($term_id, 'll_wordset_gender_options', $options);
        }

        $masculine_symbol_raw = '';
        if (isset($_POST['ll_wordset_gender_symbol_masculine'])) {
            $masculine_symbol_raw = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_gender_symbol_masculine'])
                : $_POST['ll_wordset_gender_symbol_masculine'];
        }
        $masculine_symbol = function_exists('ll_tools_wordset_sanitize_gender_symbol_raw')
            ? ll_tools_wordset_sanitize_gender_symbol_raw($masculine_symbol_raw)
            : trim((string) $masculine_symbol_raw);
        if (function_exists('ll_tools_wordset_get_gender_symbol_meta_key')) {
            $meta_key = ll_tools_wordset_get_gender_symbol_meta_key('masculine');
            if ($masculine_symbol === '') {
                delete_term_meta($term_id, $meta_key);
            } else {
                update_term_meta($term_id, $meta_key, $masculine_symbol);
            }
        }

        $feminine_symbol_raw = '';
        if (isset($_POST['ll_wordset_gender_symbol_feminine'])) {
            $feminine_symbol_raw = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_gender_symbol_feminine'])
                : $_POST['ll_wordset_gender_symbol_feminine'];
        }
        $feminine_symbol = function_exists('ll_tools_wordset_sanitize_gender_symbol_raw')
            ? ll_tools_wordset_sanitize_gender_symbol_raw($feminine_symbol_raw)
            : trim((string) $feminine_symbol_raw);
        if (function_exists('ll_tools_wordset_get_gender_symbol_meta_key')) {
            $meta_key = ll_tools_wordset_get_gender_symbol_meta_key('feminine');
            if ($feminine_symbol === '') {
                delete_term_meta($term_id, $meta_key);
            } else {
                update_term_meta($term_id, $meta_key, $feminine_symbol);
            }
        }

        if (function_exists('ll_tools_wordset_get_gender_color_defaults')) {
            $defaults = ll_tools_wordset_get_gender_color_defaults();
            $color_keys = [
                'masculine' => 'll_wordset_gender_color_masculine',
                'feminine' => 'll_wordset_gender_color_feminine',
                'other' => 'll_wordset_gender_color_other',
            ];
            foreach ($color_keys as $role => $field_key) {
                $raw_color = isset($_POST[$field_key]) ? (string) $_POST[$field_key] : '';
                $color = sanitize_hex_color($raw_color);
                if (!$color) {
                    $color = $defaults[$role] ?? '';
                }
                $meta_key = 'll_wordset_gender_color_' . $role;
                if ($color === '' || $color === ($defaults[$role] ?? '')) {
                    delete_term_meta($term_id, $meta_key);
                } else {
                    update_term_meta($term_id, $meta_key, $color);
                }
            }
        }

        $has_plurality = isset($_POST['ll_wordset_has_plurality']) ? 1 : 0;
        update_term_meta($term_id, 'll_wordset_has_plurality', $has_plurality);

        $existing_plurality_raw = get_term_meta($term_id, 'll_wordset_plurality_options', true);
        $existing_plurality_options = function_exists('ll_tools_wordset_normalize_plurality_options')
            ? ll_tools_wordset_normalize_plurality_options($existing_plurality_raw)
            : [];
        if (empty($existing_plurality_options) && function_exists('ll_tools_wordset_get_plurality_default_options')) {
            $existing_plurality_options = ll_tools_wordset_get_plurality_default_options();
        }

        $raw_plurality_options = '';
        if (isset($_POST['ll_wordset_plurality_options'])) {
            $raw_plurality_options = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_plurality_options'])
                : $_POST['ll_wordset_plurality_options'];
        }
        $plurality_options = function_exists('ll_tools_wordset_normalize_plurality_options')
            ? ll_tools_wordset_normalize_plurality_options($raw_plurality_options)
            : [];
        $resolved_plurality_options = $plurality_options;
        if (empty($resolved_plurality_options) && function_exists('ll_tools_wordset_get_plurality_default_options')) {
            $resolved_plurality_options = ll_tools_wordset_get_plurality_default_options();
        }

        $plurality_changed = function_exists('ll_tools_wordset_plurality_options_equal')
            ? !ll_tools_wordset_plurality_options_equal($existing_plurality_options, $resolved_plurality_options)
            : ($existing_plurality_options !== $resolved_plurality_options);
        if ($plurality_changed && function_exists('ll_tools_wordset_sync_plurality_values')) {
            ll_tools_wordset_sync_plurality_values($term_id, $existing_plurality_options, $resolved_plurality_options);
        }

        if (empty($plurality_options)) {
            delete_term_meta($term_id, 'll_wordset_plurality_options');
        } else {
            update_term_meta($term_id, 'll_wordset_plurality_options', $plurality_options);
        }

        $has_verb_tense = isset($_POST['ll_wordset_has_verb_tense']) ? 1 : 0;
        update_term_meta($term_id, 'll_wordset_has_verb_tense', $has_verb_tense);

        $existing_verb_tense_raw = get_term_meta($term_id, 'll_wordset_verb_tense_options', true);
        $existing_verb_tense_options = function_exists('ll_tools_wordset_normalize_verb_tense_options')
            ? ll_tools_wordset_normalize_verb_tense_options($existing_verb_tense_raw)
            : [];
        if (empty($existing_verb_tense_options) && function_exists('ll_tools_wordset_get_verb_tense_default_options')) {
            $existing_verb_tense_options = ll_tools_wordset_get_verb_tense_default_options();
        }

        $raw_verb_tense_options = '';
        if (isset($_POST['ll_wordset_verb_tense_options'])) {
            $raw_verb_tense_options = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_verb_tense_options'])
                : $_POST['ll_wordset_verb_tense_options'];
        }
        $verb_tense_options = function_exists('ll_tools_wordset_normalize_verb_tense_options')
            ? ll_tools_wordset_normalize_verb_tense_options($raw_verb_tense_options)
            : [];
        $resolved_verb_tense_options = $verb_tense_options;
        if (empty($resolved_verb_tense_options) && function_exists('ll_tools_wordset_get_verb_tense_default_options')) {
            $resolved_verb_tense_options = ll_tools_wordset_get_verb_tense_default_options();
        }

        $verb_tense_changed = function_exists('ll_tools_wordset_verb_tense_options_equal')
            ? !ll_tools_wordset_verb_tense_options_equal($existing_verb_tense_options, $resolved_verb_tense_options)
            : ($existing_verb_tense_options !== $resolved_verb_tense_options);
        if ($verb_tense_changed && function_exists('ll_tools_wordset_sync_verb_tense_values')) {
            ll_tools_wordset_sync_verb_tense_values($term_id, $existing_verb_tense_options, $resolved_verb_tense_options);
        }

        if (empty($verb_tense_options)) {
            delete_term_meta($term_id, 'll_wordset_verb_tense_options');
        } else {
            update_term_meta($term_id, 'll_wordset_verb_tense_options', $verb_tense_options);
        }

        $has_verb_mood = isset($_POST['ll_wordset_has_verb_mood']) ? 1 : 0;
        update_term_meta($term_id, 'll_wordset_has_verb_mood', $has_verb_mood);

        $existing_verb_mood_raw = get_term_meta($term_id, 'll_wordset_verb_mood_options', true);
        $existing_verb_mood_options = function_exists('ll_tools_wordset_normalize_verb_mood_options')
            ? ll_tools_wordset_normalize_verb_mood_options($existing_verb_mood_raw)
            : [];
        if (empty($existing_verb_mood_options) && function_exists('ll_tools_wordset_get_verb_mood_default_options')) {
            $existing_verb_mood_options = ll_tools_wordset_get_verb_mood_default_options();
        }

        $raw_verb_mood_options = '';
        if (isset($_POST['ll_wordset_verb_mood_options'])) {
            $raw_verb_mood_options = function_exists('wp_unslash')
                ? wp_unslash($_POST['ll_wordset_verb_mood_options'])
                : $_POST['ll_wordset_verb_mood_options'];
        }
        $verb_mood_options = function_exists('ll_tools_wordset_normalize_verb_mood_options')
            ? ll_tools_wordset_normalize_verb_mood_options($raw_verb_mood_options)
            : [];
        $resolved_verb_mood_options = $verb_mood_options;
        if (empty($resolved_verb_mood_options) && function_exists('ll_tools_wordset_get_verb_mood_default_options')) {
            $resolved_verb_mood_options = ll_tools_wordset_get_verb_mood_default_options();
        }

        $verb_mood_changed = function_exists('ll_tools_wordset_verb_mood_options_equal')
            ? !ll_tools_wordset_verb_mood_options_equal($existing_verb_mood_options, $resolved_verb_mood_options)
            : ($existing_verb_mood_options !== $resolved_verb_mood_options);
        if ($verb_mood_changed && function_exists('ll_tools_wordset_sync_verb_mood_values')) {
            ll_tools_wordset_sync_verb_mood_values($term_id, $existing_verb_mood_options, $resolved_verb_mood_options);
        }

        if (empty($verb_mood_options)) {
            delete_term_meta($term_id, 'll_wordset_verb_mood_options');
        } else {
            update_term_meta($term_id, 'll_wordset_verb_mood_options', $verb_mood_options);
        }
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

function ll_tools_wordset_strip_variation_selectors(string $value): string {
    return str_replace(["\u{FE0E}", "\u{FE0F}"], '', $value);
}

function ll_tools_wordset_get_gender_legacy_default_options(): array {
    return ['Masculine', 'Feminine'];
}

function ll_tools_wordset_get_gender_default_options(): array {
    return ['♂', '♀'];
}

function ll_tools_wordset_get_gender_color_defaults(): array {
    return [
        'masculine' => '#1D4D99',
        'feminine' => '#EC4899',
        'other' => '#6B7280',
    ];
}

function ll_tools_wordset_get_gender_colors(int $wordset_id): array {
    $defaults = ll_tools_wordset_get_gender_color_defaults();
    if ($wordset_id <= 0) {
        return $defaults;
    }

    $out = $defaults;
    foreach (array_keys($defaults) as $role) {
        $raw = (string) get_term_meta($wordset_id, 'll_wordset_gender_color_' . $role, true);
        $color = sanitize_hex_color($raw);
        if ($color) {
            $out[$role] = strtoupper($color);
        }
    }
    return $out;
}

function ll_tools_wordset_get_gender_symbol_meta_key(string $role): string {
    $role = strtolower(trim($role));
    if ($role !== 'masculine' && $role !== 'feminine') {
        return '';
    }
    return 'll_wordset_gender_symbol_' . $role;
}

function ll_tools_wordset_get_gender_symbol_allowed_html(): array {
    return [
        'svg' => [
            'xmlns' => true,
            'viewbox' => true,
            'viewBox' => true,
            'width' => true,
            'height' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'role' => true,
            'aria-label' => true,
            'focusable' => true,
            'class' => true,
            'style' => true,
        ],
        'g' => [
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'transform' => true,
            'class' => true,
            'style' => true,
        ],
        'path' => [
            'd' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'transform' => true,
            'class' => true,
            'style' => true,
        ],
        'circle' => [
            'cx' => true,
            'cy' => true,
            'r' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'class' => true,
            'style' => true,
        ],
        'ellipse' => [
            'cx' => true,
            'cy' => true,
            'rx' => true,
            'ry' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'class' => true,
            'style' => true,
        ],
        'rect' => [
            'x' => true,
            'y' => true,
            'width' => true,
            'height' => true,
            'rx' => true,
            'ry' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'class' => true,
            'style' => true,
            'transform' => true,
        ],
        'line' => [
            'x1' => true,
            'y1' => true,
            'x2' => true,
            'y2' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'class' => true,
            'style' => true,
            'transform' => true,
        ],
        'polyline' => [
            'points' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'class' => true,
            'style' => true,
            'transform' => true,
        ],
        'polygon' => [
            'points' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'class' => true,
            'style' => true,
            'transform' => true,
        ],
        'defs' => [],
        'title' => [],
        'desc' => [],
    ];
}

function ll_tools_wordset_gender_symbol_is_svg(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $lc = strtolower($value);
    return (strpos($lc, '<svg') !== false) && (strpos($lc, '</svg>') !== false);
}

function ll_tools_wordset_sanitize_gender_symbol_raw($raw): string {
    if (!is_string($raw)) {
        return '';
    }
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '<') !== false) {
        $svg = wp_kses($raw, ll_tools_wordset_get_gender_symbol_allowed_html());
        $svg = trim($svg);
        if (ll_tools_wordset_gender_symbol_is_svg($svg)) {
            return $svg;
        }
    }

    $text = wp_strip_all_tags($raw, true);
    $text = preg_replace('/[\r\n\t]+/u', ' ', (string) $text);
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    return ll_tools_wordset_strip_variation_selectors($text);
}

function ll_tools_wordset_get_default_gender_symbol(string $role): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }

    $role = strtolower(trim($role));
    if ($role !== 'masculine' && $role !== 'feminine') {
        return '';
    }
    if (isset($cache[$role])) {
        return $cache[$role];
    }

    $path = ($role === 'masculine')
        ? LL_TOOLS_BASE_PATH . 'media/gender-masculine-default.svg'
        : LL_TOOLS_BASE_PATH . 'media/gender-feminine-default.svg';
    $svg = '';
    if (file_exists($path)) {
        $svg = trim((string) file_get_contents($path));
    }
    $svg = ll_tools_wordset_sanitize_gender_symbol_raw($svg);
    if (!ll_tools_wordset_gender_symbol_is_svg($svg)) {
        if ($role === 'masculine') {
            $svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="9" cy="15" r="5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M13 11l6-6M19 5h-3M19 5v3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        } else {
            $svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="9" r="5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 14v7M9 18h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        }
    }
    $cache[$role] = $svg;
    return $cache[$role];
}

function ll_tools_wordset_get_gender_symbol(int $wordset_id, string $role): string {
    $role = strtolower(trim($role));
    if ($role !== 'masculine' && $role !== 'feminine') {
        return '';
    }
    if ($wordset_id <= 0) {
        return ll_tools_wordset_get_default_gender_symbol($role);
    }

    $meta_key = ll_tools_wordset_get_gender_symbol_meta_key($role);
    if ($meta_key === '') {
        return ll_tools_wordset_get_default_gender_symbol($role);
    }
    $raw = (string) get_term_meta($wordset_id, $meta_key, true);
    $sanitized = ll_tools_wordset_sanitize_gender_symbol_raw($raw);
    if ($sanitized === '') {
        return ll_tools_wordset_get_default_gender_symbol($role);
    }
    return $sanitized;
}

function ll_tools_wordset_hex_to_rgb(string $hex): array {
    $hex = trim($hex);
    if ($hex === '') {
        return [107, 114, 128];
    }
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return [107, 114, 128];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function ll_tools_wordset_build_gender_style_string(string $color): string {
    $color = strtoupper((string) sanitize_hex_color($color));
    if ($color === '') {
        $color = '#6B7280';
    }
    [$r, $g, $b] = ll_tools_wordset_hex_to_rgb($color);
    $bg = sprintf('rgba(%d,%d,%d,0.14)', $r, $g, $b);
    $border = sprintf('rgba(%d,%d,%d,0.38)', $r, $g, $b);

    return '--ll-gender-accent:' . $color . ';--ll-gender-bg:' . $bg . ';--ll-gender-border:' . $border . ';';
}

function ll_tools_wordset_get_gender_role_aliases(): array {
    return [
        'masculine' => ['masculine', 'masc', 'male', 'm', '♂'],
        'feminine' => ['feminine', 'fem', 'female', 'f', '♀'],
    ];
}

function ll_tools_wordset_get_gender_role_for_option(string $option, int $index = -1, array $options = []): string {
    $clean = ll_tools_wordset_strip_variation_selectors(trim($option));
    $key = strtolower($clean);
    if ($key !== '') {
        $aliases = ll_tools_wordset_get_gender_role_aliases();
        foreach ($aliases as $role => $variants) {
            foreach ($variants as $variant) {
                if ($key === strtolower(ll_tools_wordset_strip_variation_selectors((string) $variant))) {
                    return $role;
                }
            }
        }
    }

    if ($index === 0) {
        return 'masculine';
    }
    if ($index === 1) {
        return 'feminine';
    }
    return 'other';
}

function ll_tools_wordset_normalize_gender_options($raw): array {
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $lines = preg_split("/[\r\n]+/", $raw);
        if ($lines && count($lines) === 1 && strpos($lines[0], ',') !== false) {
            $lines = preg_split('/\s*,\s*/', $lines[0]);
        }
        $raw = $lines ?: [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $options = [];
    $seen = [];
    foreach ($raw as $option) {
        $option = sanitize_text_field($option);
        $option = ll_tools_wordset_strip_variation_selectors($option);
        $option = trim($option);
        if ($option === '') {
            continue;
        }
        $key = strtolower($option);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $options[] = $option;
    }

    return $options;
}

function ll_tools_wordset_gender_options_equal(array $left, array $right): bool {
    if (count($left) !== count($right)) {
        return false;
    }
    foreach ($left as $index => $value) {
        if (!array_key_exists($index, $right)) {
            return false;
        }
        if ((string) $value !== (string) $right[$index]) {
            return false;
        }
    }
    return true;
}

function ll_tools_wordset_build_gender_update_map(array $old_options, array $new_options): array {
    $map = [];
    $lookup = [];
    foreach ($new_options as $index => $value) {
        $normalized = ll_tools_wordset_strip_variation_selectors((string) $value);
        $lookup[strtolower($normalized)] = $index;
    }
    foreach ($old_options as $index => $value) {
        $normalized = ll_tools_wordset_strip_variation_selectors((string) $value);
        $key = strtolower($normalized);
        if (isset($lookup[$key])) {
            $map[$key] = $new_options[$lookup[$key]];
        } elseif (array_key_exists($index, $new_options)) {
            $map[$key] = $new_options[$index];
        } else {
            $map[$key] = '';
        }
    }
    return $map;
}

function ll_tools_wordset_sync_gender_values(int $wordset_id, array $old_options, array $new_options, array $legacy_options = []): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    $old_options = ll_tools_wordset_normalize_gender_options($old_options);
    $new_options = ll_tools_wordset_normalize_gender_options($new_options);
    if (empty($new_options)) {
        $new_options = ll_tools_wordset_get_gender_default_options();
    }

    $map = ll_tools_wordset_build_gender_update_map($old_options, $new_options);
    if (!empty($legacy_options)) {
        $legacy_map = ll_tools_wordset_build_gender_update_map($legacy_options, $new_options);
        foreach ($legacy_map as $key => $value) {
            if (!array_key_exists($key, $map)) {
                $map[$key] = $value;
            }
        }
    }
    if (empty($map)) {
        return;
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [$wordset_id],
        ]],
    ]);
    if (empty($word_ids)) {
        return;
    }

    $touched_categories = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $current = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
        if ($current === '') {
            continue;
        }
        $key = strtolower(ll_tools_wordset_strip_variation_selectors($current));
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $new_value = $map[$key];
        $changed = false;
        if ($new_value === '') {
            delete_post_meta($word_id, 'll_grammatical_gender');
            $changed = true;
        } elseif ($new_value !== $current) {
            update_post_meta($word_id, 'll_grammatical_gender', $new_value);
            $changed = true;
        }

        if ($changed) {
            $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $term_id = (int) $term_id;
                    if ($term_id > 0) {
                        $touched_categories[$term_id] = true;
                    }
                }
            }
        }
    }

    if (!empty($touched_categories) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_keys($touched_categories));
    }
}

function ll_tools_wordset_has_grammatical_gender(int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return false;
    }
    return (bool) get_term_meta($wordset_id, 'll_wordset_has_gender', true);
}

function ll_tools_wordset_hide_lesson_text_for_non_text_quiz(int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return false;
    }
    return (bool) get_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', true);
}

function ll_tools_wordset_get_gender_options(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $raw = $wordset_id > 0 ? get_term_meta($wordset_id, 'll_wordset_gender_options', true) : [];
    $options = ll_tools_wordset_normalize_gender_options($raw);
    if (empty($options)) {
        $options = ll_tools_wordset_get_gender_default_options();
    }
    return $options;
}

function ll_tools_wordset_normalize_gender_value_for_options(string $value, array $options): string {
    $clean = ll_tools_wordset_strip_variation_selectors(trim($value));
    if ($clean === '') {
        return '';
    }

    $lookup = [];
    foreach ($options as $option) {
        $opt_clean = ll_tools_wordset_strip_variation_selectors(trim((string) $option));
        if ($opt_clean === '') {
            continue;
        }
        $lookup[strtolower($opt_clean)] = $opt_clean;
    }
    $key = strtolower($clean);
    if (isset($lookup[$key])) {
        return $lookup[$key];
    }

    $desired_role = '';
    $aliases = ll_tools_wordset_get_gender_role_aliases();
    foreach ($aliases as $role => $variants) {
        foreach ($variants as $variant) {
            $variant_key = strtolower(ll_tools_wordset_strip_variation_selectors((string) $variant));
            if ($variant_key !== '' && $key === $variant_key) {
                $desired_role = $role;
                break 2;
            }
        }
    }

    if ($desired_role !== '') {
        foreach ($options as $index => $option) {
            $role = ll_tools_wordset_get_gender_role_for_option((string) $option, (int) $index, $options);
            if ($role === $desired_role) {
                $opt_clean = ll_tools_wordset_strip_variation_selectors(trim((string) $option));
                if ($opt_clean !== '') {
                    return $opt_clean;
                }
            }
        }
    }

    return $clean;
}

function ll_tools_wordset_format_gender_display_label(string $value): string {
    $clean = ll_tools_wordset_strip_variation_selectors(trim($value));
    if ($clean === '♂' || $clean === '♀') {
        return $clean . "\u{FE0E}";
    }
    return $clean;
}

function ll_tools_wordset_get_gender_label(int $wordset_id, string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $options = ll_tools_wordset_get_gender_options($wordset_id);
    $normalized = ll_tools_wordset_normalize_gender_value_for_options($value, $options);
    if ($normalized === '') {
        return '';
    }
    return ll_tools_wordset_format_gender_display_label($normalized);
}

function ll_tools_wordset_get_gender_role_for_value(int $wordset_id, string $value, array $options = []): string {
    $value = ll_tools_wordset_strip_variation_selectors(trim($value));
    if ($value === '') {
        return 'other';
    }

    if (empty($options)) {
        $options = ll_tools_wordset_get_gender_options($wordset_id);
    }
    $normalized = ll_tools_wordset_normalize_gender_value_for_options($value, $options);
    $needle = strtolower(ll_tools_wordset_strip_variation_selectors(trim($normalized !== '' ? $normalized : $value)));
    if ($needle === '') {
        return 'other';
    }

    foreach ($options as $index => $option) {
        $opt_clean = strtolower(ll_tools_wordset_strip_variation_selectors(trim((string) $option)));
        if ($opt_clean === $needle) {
            return ll_tools_wordset_get_gender_role_for_option((string) $option, (int) $index, $options);
        }
    }

    return ll_tools_wordset_get_gender_role_for_option($value, -1, $options);
}

function ll_tools_wordset_get_gender_visual_config(int $wordset_id): array {
    $options = ll_tools_wordset_get_gender_options($wordset_id);
    $colors = ll_tools_wordset_get_gender_colors($wordset_id);
    $masculine_symbol = ll_tools_wordset_get_gender_symbol($wordset_id, 'masculine');
    $feminine_symbol = ll_tools_wordset_get_gender_symbol($wordset_id, 'feminine');

    $options_out = [];
    foreach ($options as $index => $option) {
        $option = (string) $option;
        if ($option === '') {
            continue;
        }
        $normalized = ll_tools_wordset_normalize_gender_value_for_options($option, $options);
        if ($normalized === '') {
            $normalized = ll_tools_wordset_strip_variation_selectors($option);
        }
        $role = ll_tools_wordset_get_gender_role_for_option($option, (int) $index, $options);
        $color = (string) ($colors[$role] ?? $colors['other']);

        if ($role === 'masculine' || $role === 'feminine') {
            $symbol = ($role === 'masculine') ? $masculine_symbol : $feminine_symbol;
            $symbol_type = ll_tools_wordset_gender_symbol_is_svg($symbol) ? 'svg' : 'text';
            $symbol_value = ($symbol_type === 'svg')
                ? $symbol
                : ll_tools_wordset_format_gender_display_label($symbol);
        } else {
            $symbol_type = 'text';
            $symbol_value = ll_tools_wordset_format_gender_display_label($option);
        }

        $options_out[] = [
            'value' => $option,
            'normalized' => strtolower(ll_tools_wordset_strip_variation_selectors(trim((string) $normalized))),
            'label' => ll_tools_wordset_format_gender_display_label($option),
            'role' => $role,
            'color' => $color,
            'style' => ll_tools_wordset_build_gender_style_string($color),
            'symbol' => [
                'type' => $symbol_type,
                'value' => $symbol_value,
            ],
        ];
    }

    return [
        'colors' => $colors,
        'symbols' => [
            'masculine' => [
                'type' => ll_tools_wordset_gender_symbol_is_svg($masculine_symbol) ? 'svg' : 'text',
                'value' => ll_tools_wordset_gender_symbol_is_svg($masculine_symbol)
                    ? $masculine_symbol
                    : ll_tools_wordset_format_gender_display_label($masculine_symbol),
            ],
            'feminine' => [
                'type' => ll_tools_wordset_gender_symbol_is_svg($feminine_symbol) ? 'svg' : 'text',
                'value' => ll_tools_wordset_gender_symbol_is_svg($feminine_symbol)
                    ? $feminine_symbol
                    : ll_tools_wordset_format_gender_display_label($feminine_symbol),
            ],
        ],
        'options' => $options_out,
    ];
}

function ll_tools_wordset_get_gender_display_data(int $wordset_id, string $value): array {
    $value = trim($value);
    if ($value === '') {
        return [
            'value' => '',
            'label' => '',
            'role' => 'other',
            'color' => ll_tools_wordset_get_gender_color_defaults()['other'],
            'style' => '',
            'html' => '',
        ];
    }

    $options = ll_tools_wordset_get_gender_options($wordset_id);
    $normalized = ll_tools_wordset_normalize_gender_value_for_options($value, $options);
    if ($normalized === '') {
        $normalized = ll_tools_wordset_strip_variation_selectors($value);
    }

    $label = ll_tools_wordset_format_gender_display_label($normalized);
    $role = ll_tools_wordset_get_gender_role_for_value($wordset_id, $normalized, $options);
    $colors = ll_tools_wordset_get_gender_colors($wordset_id);
    $color = (string) ($colors[$role] ?? $colors['other']);
    $style = ll_tools_wordset_build_gender_style_string($color);

    $symbol_html = '';
    if ($role === 'masculine' || $role === 'feminine') {
        $symbol_raw = ll_tools_wordset_get_gender_symbol($wordset_id, $role);
        if (ll_tools_wordset_gender_symbol_is_svg($symbol_raw)) {
            $symbol_html = '<span class="ll-gender-symbol ll-gender-symbol--svg" aria-hidden="true">' . $symbol_raw . '</span>';
        } else {
            $symbol_text = ll_tools_wordset_format_gender_display_label($symbol_raw);
            if ($symbol_text === '') {
                $symbol_text = $label;
            }
            $symbol_html = '<span class="ll-gender-symbol" aria-hidden="true">' . esc_html($symbol_text) . '</span>';
        }
    } else {
        $symbol_html = '<span class="ll-gender-symbol" aria-hidden="true">' . esc_html($label) . '</span>';
    }

    $symbol_html .= '<span class="screen-reader-text">' . esc_html($label) . '</span>';

    return [
        'value' => $normalized,
        'label' => $label,
        'role' => $role,
        'color' => $color,
        'style' => $style,
        'html' => $symbol_html,
    ];
}

function ll_tools_wordset_get_plurality_default_options(): array {
    return ['Singular', 'Plural'];
}

function ll_tools_wordset_normalize_plurality_options($raw): array {
    return ll_tools_wordset_normalize_gender_options($raw);
}

function ll_tools_wordset_plurality_options_equal(array $left, array $right): bool {
    return ll_tools_wordset_gender_options_equal($left, $right);
}

function ll_tools_wordset_build_plurality_update_map(array $old_options, array $new_options): array {
    return ll_tools_wordset_build_gender_update_map($old_options, $new_options);
}

function ll_tools_wordset_sync_plurality_values(int $wordset_id, array $old_options, array $new_options): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    $old_options = ll_tools_wordset_normalize_plurality_options($old_options);
    $new_options = ll_tools_wordset_normalize_plurality_options($new_options);
    if (empty($new_options)) {
        $new_options = ll_tools_wordset_get_plurality_default_options();
    }

    $map = ll_tools_wordset_build_plurality_update_map($old_options, $new_options);
    if (empty($map)) {
        return;
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [$wordset_id],
        ]],
    ]);
    if (empty($word_ids)) {
        return;
    }

    $touched_categories = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $current = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
        if ($current === '') {
            continue;
        }
        $key = strtolower($current);
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $new_value = $map[$key];
        $changed = false;
        if ($new_value === '') {
            delete_post_meta($word_id, 'll_grammatical_plurality');
            $changed = true;
        } elseif ($new_value !== $current) {
            update_post_meta($word_id, 'll_grammatical_plurality', $new_value);
            $changed = true;
        }

        if ($changed) {
            $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $term_id = (int) $term_id;
                    if ($term_id > 0) {
                        $touched_categories[$term_id] = true;
                    }
                }
            }
        }
    }

    if (!empty($touched_categories) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_keys($touched_categories));
    }
}

function ll_tools_wordset_has_plurality(int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return false;
    }
    return (bool) get_term_meta($wordset_id, 'll_wordset_has_plurality', true);
}

function ll_tools_wordset_get_plurality_options(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $raw = $wordset_id > 0 ? get_term_meta($wordset_id, 'll_wordset_plurality_options', true) : [];
    $options = ll_tools_wordset_normalize_plurality_options($raw);
    if (empty($options)) {
        $options = ll_tools_wordset_get_plurality_default_options();
    }
    return $options;
}

function ll_tools_wordset_get_plurality_label(int $wordset_id, string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $options = ll_tools_wordset_get_plurality_options($wordset_id);
    foreach ($options as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }
    return $value;
}

function ll_tools_wordset_get_verb_tense_default_options(): array {
    return ['Present', 'Past', 'Future'];
}

function ll_tools_wordset_normalize_verb_tense_options($raw): array {
    return ll_tools_wordset_normalize_gender_options($raw);
}

function ll_tools_wordset_verb_tense_options_equal(array $left, array $right): bool {
    return ll_tools_wordset_gender_options_equal($left, $right);
}

function ll_tools_wordset_build_verb_tense_update_map(array $old_options, array $new_options): array {
    return ll_tools_wordset_build_gender_update_map($old_options, $new_options);
}

function ll_tools_wordset_sync_verb_tense_values(int $wordset_id, array $old_options, array $new_options): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    $old_options = ll_tools_wordset_normalize_verb_tense_options($old_options);
    $new_options = ll_tools_wordset_normalize_verb_tense_options($new_options);
    if (empty($new_options)) {
        $new_options = ll_tools_wordset_get_verb_tense_default_options();
    }

    $map = ll_tools_wordset_build_verb_tense_update_map($old_options, $new_options);
    if (empty($map)) {
        return;
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [$wordset_id],
        ]],
    ]);
    if (empty($word_ids)) {
        return;
    }

    $touched_categories = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $current = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
        if ($current === '') {
            continue;
        }
        $key = strtolower($current);
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $new_value = $map[$key];
        $changed = false;
        if ($new_value === '') {
            delete_post_meta($word_id, 'll_verb_tense');
            $changed = true;
        } elseif ($new_value !== $current) {
            update_post_meta($word_id, 'll_verb_tense', $new_value);
            $changed = true;
        }

        if ($changed) {
            $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $term_id = (int) $term_id;
                    if ($term_id > 0) {
                        $touched_categories[$term_id] = true;
                    }
                }
            }
        }
    }

    if (!empty($touched_categories) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_keys($touched_categories));
    }
}

function ll_tools_wordset_has_verb_tense(int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return false;
    }
    return (bool) get_term_meta($wordset_id, 'll_wordset_has_verb_tense', true);
}

function ll_tools_wordset_get_verb_tense_options(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $raw = $wordset_id > 0 ? get_term_meta($wordset_id, 'll_wordset_verb_tense_options', true) : [];
    $options = ll_tools_wordset_normalize_verb_tense_options($raw);
    if (empty($options)) {
        $options = ll_tools_wordset_get_verb_tense_default_options();
    }
    return $options;
}

function ll_tools_wordset_get_verb_tense_label(int $wordset_id, string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $options = ll_tools_wordset_get_verb_tense_options($wordset_id);
    foreach ($options as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }
    return $value;
}

function ll_tools_wordset_get_verb_mood_default_options(): array {
    return ['Indicative', 'Imperative', 'Subjunctive'];
}

function ll_tools_wordset_normalize_verb_mood_options($raw): array {
    return ll_tools_wordset_normalize_gender_options($raw);
}

function ll_tools_wordset_verb_mood_options_equal(array $left, array $right): bool {
    return ll_tools_wordset_gender_options_equal($left, $right);
}

function ll_tools_wordset_build_verb_mood_update_map(array $old_options, array $new_options): array {
    return ll_tools_wordset_build_gender_update_map($old_options, $new_options);
}

function ll_tools_wordset_sync_verb_mood_values(int $wordset_id, array $old_options, array $new_options): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    $old_options = ll_tools_wordset_normalize_verb_mood_options($old_options);
    $new_options = ll_tools_wordset_normalize_verb_mood_options($new_options);
    if (empty($new_options)) {
        $new_options = ll_tools_wordset_get_verb_mood_default_options();
    }

    $map = ll_tools_wordset_build_verb_mood_update_map($old_options, $new_options);
    if (empty($map)) {
        return;
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [$wordset_id],
        ]],
    ]);
    if (empty($word_ids)) {
        return;
    }

    $touched_categories = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $current = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
        if ($current === '') {
            continue;
        }
        $key = strtolower($current);
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $new_value = $map[$key];
        $changed = false;
        if ($new_value === '') {
            delete_post_meta($word_id, 'll_verb_mood');
            $changed = true;
        } elseif ($new_value !== $current) {
            update_post_meta($word_id, 'll_verb_mood', $new_value);
            $changed = true;
        }

        if ($changed) {
            $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $term_id = (int) $term_id;
                    if ($term_id > 0) {
                        $touched_categories[$term_id] = true;
                    }
                }
            }
        }
    }

    if (!empty($touched_categories) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_keys($touched_categories));
    }
}

function ll_tools_wordset_has_verb_mood(int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return false;
    }
    return (bool) get_term_meta($wordset_id, 'll_wordset_has_verb_mood', true);
}

function ll_tools_wordset_get_verb_mood_options(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $raw = $wordset_id > 0 ? get_term_meta($wordset_id, 'll_wordset_verb_mood_options', true) : [];
    $options = ll_tools_wordset_normalize_verb_mood_options($raw);
    if (empty($options)) {
        $options = ll_tools_wordset_get_verb_mood_default_options();
    }
    return $options;
}

function ll_tools_wordset_get_verb_mood_label(int $wordset_id, string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $options = ll_tools_wordset_get_verb_mood_options($wordset_id);
    foreach ($options as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }
    return $value;
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
 * On first request after activation/update:
 *  - Ensure at least one 'wordset' term exists (create "Default Word Set" if none).
 *  - Schedule a background backfill to assign the default wordset (only one if it exists, otherwise the oldest).
 */
function ll_tools_maybe_seed_default_wordset_and_assign() {
    // Only run if activation/update set the transient.
    if (!get_transient('ll_tools_seed_default_wordset')) {
        return;
    }
    delete_transient('ll_tools_seed_default_wordset');

    // Safety: make sure taxonomy/post type exist.
    if (!taxonomy_exists('wordset') || !post_type_exists('words')) {
        return;
    }

    // Collect existing wordset terms (oldest first).
    $term_ids = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
        'orderby'    => 'term_id',
        'order'      => 'ASC',
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

    if (empty($term_ids)) {
        return;
    }
    $default_wordset_id = (int) array_values($term_ids)[0];

    ll_tools_schedule_wordset_backfill($default_wordset_id);
}

function ll_tools_schedule_wordset_backfill($wordset_id) {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return;
    }
    if (!ll_tools_wordset_backfill_has_orphans()) {
        delete_option('ll_tools_wordset_backfill_id');
        return;
    }

    update_option('ll_tools_wordset_backfill_id', $wordset_id, false);
    if (!wp_next_scheduled('ll_tools_backfill_wordset_batch')) {
        wp_schedule_single_event(time() + 10, 'll_tools_backfill_wordset_batch');
    }
}

function ll_tools_wordset_backfill_has_orphans() {
    $ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'operator' => 'NOT EXISTS',
        ]],
    ]);

    return !empty($ids);
}

function ll_tools_maybe_resume_wordset_backfill() {
    $wordset_id = (int) get_option('ll_tools_wordset_backfill_id', 0);
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return;
    }
    if (!wp_next_scheduled('ll_tools_backfill_wordset_batch')) {
        wp_schedule_single_event(time() + 10, 'll_tools_backfill_wordset_batch');
    }
}
add_action('init', 'll_tools_maybe_resume_wordset_backfill', 25);

function ll_tools_backfill_wordset_batch() {
    if (!taxonomy_exists('wordset') || !post_type_exists('words')) {
        return;
    }

    $wordset_id = (int) get_option('ll_tools_wordset_backfill_id', 0);
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        delete_option('ll_tools_wordset_backfill_id');
        return;
    }

    if (get_transient('ll_tools_wordset_backfill_running')) {
        if (!wp_next_scheduled('ll_tools_backfill_wordset_batch')) {
            wp_schedule_single_event(time() + 60, 'll_tools_backfill_wordset_batch');
        }
        return;
    }
    set_transient('ll_tools_wordset_backfill_running', 1, 2 * MINUTE_IN_SECONDS);

    $batch_size = (int) apply_filters('ll_tools_wordset_backfill_batch_size', 200);
    if ($batch_size < 1) {
        $batch_size = 200;
    }

    $post_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => $batch_size,
        'no_found_rows'  => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'operator' => 'NOT EXISTS',
        ]],
    ]);

    if (empty($post_ids)) {
        delete_transient('ll_tools_wordset_backfill_running');
        delete_option('ll_tools_wordset_backfill_id');
        wp_update_term_count_now([$wordset_id], 'wordset');
        return;
    }

    $category_ids = [];
    wp_defer_term_counting(true);
    foreach ($post_ids as $post_id) {
        wp_set_object_terms((int) $post_id, $wordset_id, 'wordset', false);
        $post_categories = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($post_categories) && !empty($post_categories)) {
            $category_ids = array_merge($category_ids, $post_categories);
        }
    }
    wp_defer_term_counting(false);

    delete_transient('ll_tools_wordset_backfill_running');

    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        $category_ids = array_values(array_unique(array_map('intval', $category_ids)));
        ll_tools_bump_category_cache_version($category_ids);
    }

    if (ll_tools_wordset_backfill_has_orphans()) {
        if (!wp_next_scheduled('ll_tools_backfill_wordset_batch')) {
            wp_schedule_single_event(time() + 10, 'll_tools_backfill_wordset_batch');
        }
        return;
    }

    delete_option('ll_tools_wordset_backfill_id');
    wp_update_term_count_now([$wordset_id], 'wordset');
}
add_action('ll_tools_backfill_wordset_batch', 'll_tools_backfill_wordset_batch');
