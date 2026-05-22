<?php
if (!defined('WPINC')) { die; }

/**
 * Registers the "language" taxonomy for the "words" custom post type.
 *
 * @return void
 */
function ll_register_language_taxonomy() {
    $labels = array(
        'name' => __('Languages', 'll-tools-text-domain'),
        'singular_name' => __('Language', 'll-tools-text-domain'),
        // Add other labels as needed
    );

    $args = array(
        'labels' => $labels,
        'hierarchical' => false,
        'public' => true,
        'show_ui' => false,
        'show_admin_column' => false,
        'query_var' => true,
        'rewrite' => array('slug' => 'language'),
    );

    register_taxonomy('language', array('words'), $args);
}
add_action('init', 'll_register_language_taxonomy');

/**
 * Load the ISO language rows used to seed the language taxonomy.
 *
 * @return array<string, array{name:string, slug:string, macrolanguage:string}>
 */
function ll_tools_get_language_seed_rows() {
    $plugin_root_dir = LL_TOOLS_BASE_PATH;
    $languages_table = $plugin_root_dir . 'data/iso-languages/iso-639-3_Name_Index.tab.txt';
    $macrolanguage_mappings_table = $plugin_root_dir . 'data/iso-languages/iso-639-3-macrolanguages.tab.txt';

    $languages = array();

    if (is_readable($languages_table) && ($handle = fopen($languages_table, 'r')) !== false) {
        $row_index = 0;
        while (($data = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            $row_index++;
            if ($row_index === 1 && isset($data[0]) && $data[0] === 'Id') {
                continue;
            }

            $id = isset($data[0]) ? trim((string) $data[0]) : '';
            $print_name = isset($data[1]) ? trim((string) $data[1]) : '';
            if ($id === '' || $print_name === '') {
                continue;
            }

            $languages[$id] = array(
                'name' => $print_name,
                'slug' => $id,
                'macrolanguage' => '',
            );
        }
        fclose($handle);
    }

    if (is_readable($macrolanguage_mappings_table) && ($handle = fopen($macrolanguage_mappings_table, 'r')) !== false) {
        $row_index = 0;
        while (($data = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            $row_index++;
            if ($row_index === 1 && isset($data[0]) && $data[0] === 'M_Id') {
                continue;
            }

            $macrolanguage_id = isset($data[0]) ? trim((string) $data[0]) : '';
            $individual_language_id = isset($data[1]) ? trim((string) $data[1]) : '';
            $status = isset($data[2]) ? trim((string) $data[2]) : '';

            if ($macrolanguage_id === '' || $individual_language_id === '') {
                continue;
            }

            if ($status === 'A' && isset($languages[$individual_language_id])) {
                $languages[$individual_language_id]['macrolanguage'] = $macrolanguage_id;
            }
        }
        fclose($handle);
    }

    $languages = apply_filters('ll_tools_language_seed_rows', $languages);

    return is_array($languages) ? $languages : array();
}

/**
 * Determine whether the language taxonomy still needs to be seeded.
 *
 * @return bool
 */
function ll_tools_language_taxonomy_needs_population() {
    if (!taxonomy_exists('language')) {
        return true;
    }

    $languages_populated = (bool) get_option('ll_languages_populated', false);
    $existing_terms = get_terms(array(
        'taxonomy' => 'language',
        'hide_empty' => false,
        'fields' => 'ids',
        'number' => 1,
    ));

    if (is_wp_error($existing_terms)) {
        return !$languages_populated;
    }

    return !$languages_populated || empty($existing_terms);
}

/**
 * Seed the language taxonomy from the bundled ISO tables.
 *
 * @param bool $force When true, ignore the populated flag and repopulate missing terms.
 * @return array{created:int, existing:int, locked:bool}
 */
function ll_tools_populate_language_taxonomy($force = false) {
    if (!taxonomy_exists('language')) {
        ll_register_language_taxonomy();
    }

    if (!$force && !ll_tools_language_taxonomy_needs_population()) {
        return array(
            'created' => 0,
            'existing' => 0,
            'locked' => false,
        );
    }

    $lock_key = 'll_tools_language_seed_lock';
    if (!$force && get_transient($lock_key)) {
        return array(
            'created' => 0,
            'existing' => 0,
            'locked' => true,
        );
    }

    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

    $existing_terms = get_terms(array(
        'taxonomy' => 'language',
        'hide_empty' => false,
    ));
    $existing_slugs = array();
    if (!is_wp_error($existing_terms)) {
        foreach ($existing_terms as $existing_term) {
            if ($existing_term instanceof WP_Term) {
                $existing_slugs[$existing_term->slug] = true;
            }
        }
    }

    $created = 0;
    $existing = 0;
    $created_term_ids = array();
    $previous_cache_invalidation = wp_suspend_cache_invalidation(true);
    if (function_exists('wp_defer_term_counting')) {
        wp_defer_term_counting(true);
    }

    try {
        foreach (ll_tools_get_language_seed_rows() as $language) {
            $slug = isset($language['slug']) ? trim((string) $language['slug']) : '';
            $name = isset($language['name']) ? trim((string) $language['name']) : '';
            $macrolanguage = isset($language['macrolanguage']) ? trim((string) $language['macrolanguage']) : '';

            if ($slug === '' || $name === '') {
                continue;
            }

            if (isset($existing_slugs[$slug])) {
                $existing++;
                continue;
            }

            $term = wp_insert_term($name, 'language', array(
                'slug' => $slug,
                'description' => $macrolanguage,
            ));

            if (!is_wp_error($term)) {
                $existing_slugs[$slug] = true;
                $created++;
                if (isset($term['term_id'])) {
                    $created_term_ids[] = (int) $term['term_id'];
                }
            }
        }
    } finally {
        if (function_exists('wp_defer_term_counting')) {
            wp_defer_term_counting(false);
        }
        wp_suspend_cache_invalidation($previous_cache_invalidation);
        delete_transient($lock_key);
    }

    if ($created > 0 || $existing > 0) {
        update_option('ll_languages_populated', true);
    }

    if (!empty($created_term_ids)) {
        clean_term_cache($created_term_ids, 'language');
    }
    clean_taxonomy_cache('language');

    return array(
        'created' => $created,
        'existing' => $existing,
        'locked' => false,
    );
}

/**
 * Populate the language taxonomy only when an admin screen explicitly needs the full list.
 *
 * @return void
 */
function ll_tools_ensure_language_taxonomy_terms() {
    if (!ll_tools_language_taxonomy_needs_population()) {
        return;
    }

    ll_tools_populate_language_taxonomy();
}

// Create the Languages admin page
function ll_create_languages_admin_page() {
    add_submenu_page(
        'tools.php',
        __('Language Learner Tools - Languages', 'll-tools-text-domain'),
        __('LL Tools Languages', 'll-tools-text-domain'),
        'manage_options',
        'language-learner-tools-languages',
        'll_render_languages_admin_page',
    );
}
add_action('admin_menu', 'll_create_languages_admin_page');

// Render the Languages admin page
function ll_render_languages_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'll-tools-text-domain'));
    }

    ll_tools_ensure_language_taxonomy_terms();

    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
        'hierarchical' => true,
    ]);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Language Learner Tools - Languages', 'll-tools-text-domain'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('refresh_languages', 'refresh_languages_nonce'); ?>
            <p>
                <input type="submit" name="refresh_languages" class="button button-primary" value="<?php echo esc_attr__('Refresh Language List', 'll-tools-text-domain'); ?>">
            </p>
        </form>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Language ID', 'll-tools-text-domain'); ?></th>
                    <th><?php esc_html_e('Language Name', 'll-tools-text-domain'); ?></th>
                    <th><?php esc_html_e('Macrolanguage', 'll-tools-text-domain'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($languages as $language) : ?>
                    <tr>
                        <td><?php echo esc_html($language->slug); ?></td>
                        <td><?php echo esc_html($language->name); ?></td>
                        <td><?php echo esc_html($language->description); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Delete the languages and reset the flag to repopulate the language data
function ll_refresh_language_list() {
    if (!is_admin() || !isset($_POST['refresh_languages'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $nonce = isset($_POST['refresh_languages_nonce'])
        ? wp_unslash((string) $_POST['refresh_languages_nonce'])
        : '';
    if (!wp_verify_nonce($nonce, 'refresh_languages')) {
        return;
    }

    // Delete all existing language terms
    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    foreach ($languages as $language_id) {
        wp_delete_term($language_id, 'language');
    }

    // Reset the flag to indicate that the language data needs to be repopulated
    update_option('ll_languages_populated', false);

    // Redirect back to the languages admin page
    wp_safe_redirect(admin_url('tools.php?page=language-learner-tools-languages'));
    exit;
}
add_action('admin_init', 'll_refresh_language_list');
