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

// Add language field to the word set taxonomy admin page
function ll_add_wordset_language_field($term) {
    $language = '';
    $has_gender = false;
    $has_plurality = false;
    $gender_options = function_exists('ll_tools_wordset_get_gender_default_options')
        ? ll_tools_wordset_get_gender_default_options()
        : ['Masculine', 'Feminine'];
    $plurality_options = function_exists('ll_tools_wordset_get_plurality_default_options')
        ? ll_tools_wordset_get_plurality_default_options()
        : ['Singular', 'Plural'];
    if (!is_string($term) && $term) { // Check if we are editing an existing term
        $language = get_term_meta($term->term_id, 'll_language', true);
        $has_gender = (bool) get_term_meta($term->term_id, 'll_wordset_has_gender', true);
        $has_plurality = (bool) get_term_meta($term->term_id, 'll_wordset_has_plurality', true);
        if (function_exists('ll_tools_wordset_get_gender_options')) {
            $gender_options = ll_tools_wordset_get_gender_options($term->term_id);
        }
        if (function_exists('ll_tools_wordset_get_plurality_options')) {
            $plurality_options = ll_tools_wordset_get_plurality_options($term->term_id);
        }
    }
    $gender_options_display = implode("\n", array_map('strval', $gender_options));
    $plurality_options_display = implode("\n", array_map('strval', $plurality_options));
    ?>
    <div class="form-field term-language-wrap">
        <label for="wordset-language"><?php _e('Language', 'll-tools-text-domain'); ?></label>
        <input type="text" id="wordset-language" name="wordset_language" value="<?php echo esc_attr($language); ?>" required>
        <p><?php _e('Enter the language for this word set.', 'll-tools-text-domain'); ?></p>
    </div>
    <?php wp_nonce_field('ll_wordset_meta', 'll_wordset_meta_nonce'); ?>
    <div class="form-field term-grammatical-gender-wrap">
        <label for="ll-wordset-grammatical-gender"><?php _e('Grammatical gender', 'll-tools-text-domain'); ?></label>
        <label>
            <input type="checkbox" id="ll-wordset-grammatical-gender" name="ll_wordset_has_gender" value="1" <?php checked($has_gender); ?> />
            <?php _e('Enable grammatical gender for this word set.', 'll-tools-text-domain'); ?>
        </label>
    </div>
    <div class="form-field term-grammatical-gender-options-wrap">
        <label for="ll-wordset-gender-options"><?php _e('Gender options', 'll-tools-text-domain'); ?></label>
        <textarea id="ll-wordset-gender-options" name="ll_wordset_gender_options" rows="4"><?php echo esc_textarea($gender_options_display); ?></textarea>
        <p><?php _e('One option per line (for example: ♂, ♀, neuter). Use custom text if you prefer.', 'll-tools-text-domain'); ?></p>
    </div>
    <div class="form-field term-plurality-wrap">
        <label for="ll-wordset-plurality"><?php _e('Plurality', 'll-tools-text-domain'); ?></label>
        <label>
            <input type="checkbox" id="ll-wordset-plurality" name="ll_wordset_has_plurality" value="1" <?php checked($has_plurality); ?> />
            <?php _e('Enable plurality for this word set.', 'll-tools-text-domain'); ?>
        </label>
    </div>
    <div class="form-field term-plurality-options-wrap">
        <label for="ll-wordset-plurality-options"><?php _e('Plurality options', 'll-tools-text-domain'); ?></label>
        <textarea id="ll-wordset-plurality-options" name="ll_wordset_plurality_options" rows="4"><?php echo esc_textarea($plurality_options_display); ?></textarea>
        <p><?php _e('One option per line (for example: singular, plural, dual).', 'll-tools-text-domain'); ?></p>
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
    $has_meta_input = isset($_POST['wordset_language'])
        || isset($_POST['ll_wordset_has_gender'])
        || isset($_POST['ll_wordset_gender_options'])
        || isset($_POST['ll_wordset_has_plurality'])
        || isset($_POST['ll_wordset_plurality_options']);
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
    if ($key === 'masculine' || $key === 'feminine') {
        $symbol = ($key === 'masculine') ? '♂' : '♀';
        $symbol_key = strtolower($symbol);
        if (isset($lookup[$symbol_key])) {
            return $lookup[$symbol_key];
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
