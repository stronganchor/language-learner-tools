<?php
/**
 * Settings Page for Language Learner Tools
 *
 * This file handles the settings page for configuring the plugin options,
 * including language settings and other customizable features.
 */

function ll_register_settings_page() {
    add_options_page(
        'Language Learning Tools Settings', // Page title
        'Language Learning Tools', // Menu title
        'manage_options', // Capability required to see the page
        'language-learning-tools-settings', // Menu slug
        'll_render_settings_page' // Function to display the settings page
    );
}
add_action('admin_menu', 'll_register_settings_page');

function ll_register_settings() {
    register_setting('language-learning-tools-options', 'll_target_language');
    register_setting('language-learning-tools-options', 'll_translation_language');
    register_setting('language-learning-tools-options', 'll_enable_category_translation');
    register_setting('language-learning-tools-options', 'll_category_translation_source');
}
add_action('admin_init', 'll_register_settings');

function ll_render_settings_page() {
    $target_language = get_option('ll_target_language', '');
    $translation_language = get_option('ll_translation_language', '');
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $translation_source = get_option('ll_category_translation_source', 'target');

    // Default to showing placeholders if languages are not set
    $target_language_name = $target_language ? ucfirst($target_language) : __('Target Language', 'll-tools-text-domain');
    $translation_language_name = $translation_language ? ucfirst($translation_language) : __('Translation Language', 'll-tools-text-domain');

    // Options for dropdown
    $options = array(
        'target' => sprintf(__('%s to %s', 'll-tools-text-domain'), $target_language_name, $translation_language_name),
        'translation' => sprintf(__('%s to %s', 'll-tools-text-domain'), $translation_language_name, $target_language_name),
    );
    ?>
    <div class="wrap">
        <h2>Language Learning Tools Settings</h2>
        <form action="options.php" method="post">
            <?php settings_fields('language-learning-tools-options'); ?>
            <?php do_settings_sections('language-learning-tools-settings'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Target Language (e.g., "TR" for Turkish):</th>
                    <td>
                        <input type="text" name="ll_target_language" id="ll_target_language" value="<?php echo esc_attr($target_language); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Translation Language (e.g., "EN" for English):</th>
                    <td>
                        <input type="text" name="ll_translation_language" id="ll_translation_language" value="<?php echo esc_attr($translation_language); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Category Name Translations:</th>
                    <td>
                        <input type="checkbox" name="ll_enable_category_translation" id="ll_enable_category_translation" value="1" <?php checked(1, $enable_translation, true); ?> />
                        <p class="description">Check this box to enable automatic or manual translations of category names.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Translate Category Names From:</th>
                    <td>
                        <select name="ll_category_translation_source" id="ll_category_translation_source" <?php echo ($enable_translation && $target_language && $translation_language) ? '' : 'disabled'; ?>>
                            <option value="target" <?php selected($translation_source, 'target'); ?>>
                                <?php echo esc_html($options['target']); ?>
                            </option>
                            <option value="translation" <?php selected($translation_source, 'translation'); ?>>
                                <?php echo esc_html($options['translation']); ?>
                            </option>
                        </select>
                        <p class="description">Choose whether category names are originally written in <strong><?php echo esc_html($target_language_name); ?></strong> or <strong><?php echo esc_html($translation_language_name); ?></strong>.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <script>
        (function($) {
            // Dynamically enable/disable the dropdown based on settings
            const enableCheckbox = $('#ll_enable_category_translation');
            const targetLangInput = $('#ll_target_language');
            const translationLangInput = $('#ll_translation_language');
            const dropdown = $('#ll_category_translation_source');

            function updateDropdownState() {
                const isEnabled = enableCheckbox.is(':checked');
                const hasLanguages = targetLangInput.val().trim() !== '' && translationLangInput.val().trim() !== '';
                dropdown.prop('disabled', !(isEnabled && hasLanguages));
            }

            // Initial state
            updateDropdownState();

            // Attach event listeners
            enableCheckbox.on('change', updateDropdownState);
            targetLangInput.on('input', updateDropdownState);
            translationLangInput.on('input', updateDropdownState);
        })(jQuery);
    </script>
    <?php
}

add_action('update_option_ll_enable_category_translation', 'll_auto_translate_existing_categories', 10, 3);

function ll_auto_translate_existing_categories($old_value, $new_value, $option_name) {
    if (!$old_value && $new_value) { // Only run when the setting is enabled
        // Fetch all categories in the taxonomy
        $categories = get_terms(array(
            'taxonomy' => 'word-category',
            'hide_empty' => false,
        ));

        // Get translation settings
		$translation_source = get_option('ll_category_translation_source', 'target'); // 'target' or 'translation'

		// Determine source and target languages based on the setting
		$source_language = get_option('ll_target_language');
		$target_language = get_option('ll_translation_language');
		
		if ($translation_source === 'translation') {
			// Translate from the translation language to the target language
			$source_language = get_option('ll_translation_language');
			$target_language = get_option('ll_target_language');
		}

        // Initialize result tracking
        $results = [
            'success' => [],
            'errors' => [],
			'source_language' => $source_language,
            'target_language' => $target_language,
        ];

        // Loop through categories and translate them if necessary
        foreach ($categories as $category) {
            // Check if the category already has a translation
            $translation = get_term_meta($category->term_id, 'term_translation', true);
            if (empty($translation)) {
                // Translate using DeepL
                $translated_name = translate_with_deepl($category->name, $target_language, $source_language);

                if ($translated_name) {
                    update_term_meta($category->term_id, 'term_translation', $translated_name);
                    $results['success'][] = [
                        'original' => $category->name,
                        'translated' => $translated_name,
                    ];
                } else {
                    $results['errors'][] = [
                        'original' => $category->name,
                        'error' => "Translation failed for category ID {$category->term_id} ({$category->name}).",
                    ];
                }
            }
        }

        // Store results in a transient for displaying in admin notices
        set_transient('ll_translation_results', $results, 60);
    }
}
add_action('update_option_ll_enable_category_translation', 'll_auto_translate_existing_categories', 10, 3);

add_action('admin_notices', 'll_display_translation_results');
function ll_display_translation_results() {
    $results = get_transient('ll_translation_results');
    if ($results) {
        delete_transient('ll_translation_results');

        $source_language = strtoupper($results['source_language']); // Capitalize language codes for clarity
        $target_language = strtoupper($results['target_language']);

        // Display translation direction
        echo '<div class="notice notice-info is-dismissible">';
        echo "<p><strong>Category Translations:</strong> Translating from <strong>$source_language</strong> to <strong>$target_language</strong>.</p>";
        echo '</div>';

        // Display success results
        if (!empty($results['success'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Translation Results:</strong></p>';
            echo '<ul>';
            foreach ($results['success'] as $success) {
                echo '<li><strong>Original:</strong> ' . esc_html($success['original']) . 
                     ' <strong>Translated:</strong> ' . esc_html($success['translated']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Display error results
        if (!empty($results['errors'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Translation Errors:</strong></p>';
            echo '<ul>';
            foreach ($results['errors'] as $error) {
                echo '<li><strong>Original:</strong> ' . esc_html($error['original']) . 
                     ' <strong>Error:</strong> ' . esc_html($error['error']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }
}

?>