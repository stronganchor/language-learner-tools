<?php

// Register the "language" taxonomy for the "words" custom post type
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
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => array('slug' => 'language'),
    );

    register_taxonomy('language', array('words'), $args);

    // Check if the language data has already been populated
    $languages_populated = get_option('ll_languages_populated', false);

    if (!$languages_populated) {
        // Load language and macrolanguage data from SIL tables
        $plugin_root_dir = plugin_dir_path(__DIR__ . '/../language-learner-tools.php');
        $languages_table = $plugin_root_dir . 'iso-languages/iso-639-3_Name_Index.tab.txt';
        $macrolanguage_mappings_table = $plugin_root_dir . 'iso-languages/iso-639-3-macrolanguages.tab.txt';

        $languages = array();
        $macrolanguage_mappings = array();

        // Parse language and macrolanguage table
        if (($handle = fopen($languages_table, 'r')) !== false) {
            while (($data = fgetcsv($handle, 0, "\t")) !== false) {
                $id = $data[0];
                $print_name = mb_convert_encoding($data[1], 'UTF-8', 'UTF-8');

                $languages[$id] = array(
                    'name' => $print_name,
                    'slug' => $id,
                    'macrolanguage' => '',
                );
            }
            fclose($handle);
        }

        // Parse macrolanguage mappings table
        if (($handle = fopen($macrolanguage_mappings_table, 'r')) !== false) {
            while (($data = fgetcsv($handle, 0, "\t")) !== false) {
                $macrolanguage_id = $data[0];
                $individual_language_id = $data[1];
                $status = $data[2];

                if ($status === 'A' && isset($languages[$individual_language_id])) {
                    $languages[$individual_language_id]['macrolanguage'] = $macrolanguage_id;
                }
            }
            fclose($handle);
        }

        // Insert language terms
        foreach ($languages as $id => $language) {
            // Check if the language term already exists
            $existing_term = get_term_by('slug', $language['slug'], 'language');

            if (!$existing_term) {
                $term_args = array(
                    'slug' => $language['slug'],
                    'description' => $language['macrolanguage'],
                );
                $term_id = wp_insert_term($language['name'], 'language', $term_args);
            }
        }

        update_option('ll_languages_populated', true);
    }
}
add_action('init', 'll_register_language_taxonomy');

// Create the Languages admin page
function ll_create_languages_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Languages',
        'LL Tools Languages',
        'manage_options',
        'language-learner-tools-languages',
        'll_render_languages_admin_page',
    );
}
add_action('admin_menu', 'll_create_languages_admin_page');

// Render the Languages admin page
function ll_render_languages_admin_page() {
    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
        'hierarchical' => true,
    ]);
    ?>
    <div class="wrap">
        <h1>Language Learner Tools - Languages</h1>
        <form method="post" action="">
            <?php wp_nonce_field('refresh_languages', 'refresh_languages_nonce'); ?>
            <p>
                <input type="submit" name="refresh_languages" class="button button-primary" value="Refresh Language List">
            </p>
        </form>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Language ID</th>
                    <th>Language Name</th>
                    <th>Macrolanguage</th>
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
    if (isset($_POST['refresh_languages']) && wp_verify_nonce($_POST['refresh_languages_nonce'], 'refresh_languages')) {
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
        wp_redirect(admin_url('tools.php?page=language-learner-tools-languages'));
        exit;
    }
}
add_action('admin_init', 'll_refresh_language_list');

