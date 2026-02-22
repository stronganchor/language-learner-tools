<?php
/**
 * Settings Page for Language Learner Tools
 *
 * This file handles the settings page for configuring the plugin options,
 * including language settings and other customizable features.
 */

function ll_register_settings_page() {
    $settings_slug = function_exists('ll_tools_get_admin_settings_page_slug')
        ? ll_tools_get_admin_settings_page_slug()
        : (defined('LL_TOOLS_SETTINGS_SLUG') ? (string) LL_TOOLS_SETTINGS_SLUG : 'language-learning-tools-settings');

    add_options_page(
        'Language Learning Tools Settings', // Page title
        'Language Learning Tools', // Menu title
        'view_ll_tools', // Capability required to see the page
        $settings_slug, // Menu slug
        'll_render_settings_page' // Function to display the settings page
    );
}
add_action('admin_menu', 'll_register_settings_page');

function ll_register_settings() {
    $args = array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => false,
        'default' => ''
    );

    register_setting('language-learning-tools-options', 'll_target_language', $args);
    register_setting('language-learning-tools-options', 'll_translation_language', $args);
    register_setting('language-learning-tools-options', 'll_enable_category_translation', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0
    ));
    register_setting('language-learning-tools-options', 'll_category_translation_source', $args);
    register_setting('language-learning-tools-options', 'll_max_options_override', [
        'type' => 'integer',
        'sanitize_callback' => 'll_sanitize_max_options_override',
        'default' => 9,
    ]);
    register_setting('language-learning-tools-options', 'll_flashcard_image_size', [
        'type' => 'string',
        'default' => 'small',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('language-learning-tools-options', 'll_hide_recording_titles', [
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0,
    ]);
    register_setting('language-learning-tools-options', 'll_allow_learner_self_registration', [
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 1,
    ]);
    // Settings for quiz font name and URL.
    register_setting('language-learning-tools-options', 'll_quiz_font', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field'
    ));
    register_setting('language-learning-tools-options', 'll_quiz_font_url', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw'
    ));
    register_setting('language-learning-tools-options', 'll_update_branch', array(
        'type' => 'string',
        'sanitize_callback' => 'll_sanitize_update_branch',
        'default' => 'main',
    ));

    // Word title language role (sitewide setting informing direction and UI defaults)
    register_setting('language-learning-tools-options', 'll_word_title_language_role', array(
        'type' => 'string',
        'sanitize_callback' => 'll_sanitize_title_language_role',
        'default' => 'target'
    ));
}
add_action('admin_init', 'll_register_settings');

function ll_sanitize_max_options_override($value) {
    $value = absint($value);
    if ($value < 2) {
        $value = 9;
    }
    return $value;
}

function ll_sanitize_title_language_role($value) {
    return in_array($value, array('target','translation'), true) ? $value : 'target';
}

function ll_sanitize_update_branch($value) {
    return ($value === 'dev') ? 'dev' : 'main';
}

function ll_tools_purge_legacy_word_audio_meta() {
    global $wpdb;

    $meta_key = 'word_audio_file';

    $count_sql = "
        SELECT COUNT(*)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = %s
          AND p.post_type = 'words'
    ";

    $count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $meta_key ) );

    $delete_sql = "
        DELETE pm FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = %s
          AND p.post_type = 'words'
    ";

    $deleted = $wpdb->query( $wpdb->prepare( $delete_sql, $meta_key ) );

    return array(
        'count'   => $count,
        'deleted' => is_numeric( $deleted ) ? (int) $deleted : 0,
    );
}

function ll_tools_bump_word_category_cache() {
    if ( ! function_exists( 'll_tools_bump_category_cache_version' ) ) {
        return 0;
    }

    $terms = get_terms( array(
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
    ) );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return 0;
    }

    ll_tools_bump_category_cache_version( $terms );

    return count( $terms );
}

function ll_tools_flush_quiz_word_caches() {
    global $wpdb;

    $patterns = array(
        '_transient_ll_wc_words_%',
        '_transient_timeout_ll_wc_words_%',
    );

    $deleted = 0;
    foreach ( $patterns as $pattern ) {
        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $pattern
        );
        $result = $wpdb->query( $sql );
        if ( is_numeric( $result ) ) {
            $deleted += (int) $result;
        }
    }

    $bumped = ll_tools_bump_word_category_cache();
    $object_cache_flushed = false;
    if ( function_exists( 'wp_cache_flush' ) ) {
        $object_cache_flushed = (bool) wp_cache_flush();
    }

    return array(
        'deleted'              => $deleted,
        'bumped'               => $bumped,
        'object_cache_flushed' => $object_cache_flushed,
    );
}

function ll_render_settings_page() {
    if ( ! current_user_can( 'view_ll_tools' ) ) {
        return;
    }

    $purge_notice = '';
    $purge_result = null;
    $cache_notice = '';
    $cache_result = null;

    if ( isset( $_POST['ll_tools_purge_legacy_audio'] ) ) {
        if ( ! isset( $_POST['ll_tools_purge_legacy_audio_nonce'] ) || ! wp_verify_nonce( $_POST['ll_tools_purge_legacy_audio_nonce'], 'll_tools_purge_legacy_audio' ) ) {
            $purge_notice = '<div class="notice notice-error"><p>Purge failed security check. Please try again.</p></div>';
        } else {
            $purge_result = ll_tools_purge_legacy_word_audio_meta();
            $bumped = ll_tools_bump_word_category_cache();
            $purge_notice = '<div class="notice notice-success"><p>Purged ' . esc_html( (string) $purge_result['deleted'] ) . ' legacy meta rows.</p></div>';
            if ( $bumped > 0 ) {
                $purge_notice .= '<div class="notice notice-success"><p>Cache bumped for ' . esc_html( (string) $bumped ) . ' word categories.</p></div>';
            }
        }
    }

    if ( isset( $_POST['ll_tools_flush_quiz_cache'] ) ) {
        if ( ! isset( $_POST['ll_tools_flush_quiz_cache_nonce'] ) || ! wp_verify_nonce( $_POST['ll_tools_flush_quiz_cache_nonce'], 'll_tools_flush_quiz_cache' ) ) {
            $cache_notice = '<div class="notice notice-error"><p>Cache flush failed security check. Please try again.</p></div>';
        } else {
            $cache_result = ll_tools_flush_quiz_word_caches();
            $cache_notice = '<div class="notice notice-success"><p>Flushed quiz caches and bumped category cache versions.</p></div>';
        }
    }

    $target_language = get_option('ll_target_language', '');
    $translation_language = get_option('ll_translation_language', '');
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $translation_source = get_option('ll_category_translation_source', 'target');
    $word_title_role = get_option('ll_word_title_language_role', 'target');
    $update_branch = get_option('ll_update_branch', 'main');
    $quiz_font = get_option('ll_quiz_font');
    $quiz_font_url = get_option('ll_quiz_font_url');
    $allow_learner_self_registration = (int) get_option('ll_allow_learner_self_registration', 1);

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
        <?php echo $purge_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php echo $cache_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <form action="options.php" method="post">
            <?php settings_fields('language-learning-tools-options'); ?>
            <?php
            $settings_slug = function_exists('ll_tools_get_admin_settings_page_slug')
                ? ll_tools_get_admin_settings_page_slug()
                : (defined('LL_TOOLS_SETTINGS_SLUG') ? (string) LL_TOOLS_SETTINGS_SLUG : 'language-learning-tools-settings');
            do_settings_sections($settings_slug);
            ?>
            
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
                    <th scope="row">Word Title Language:</th>
                    <td>
                        <select name="ll_word_title_language_role" id="ll_word_title_language_role">
                            <option value="target" <?php selected($word_title_role, 'target'); ?>>Target (language being learned)</option>
                            <option value="translation" <?php selected($word_title_role, 'translation'); ?>>Translation (helper/known language)</option>
                        </select>
                        <p class="description">If set to Translation, tools and lookups treat post titles as being in the translation language and <code>word_translation</code> meta as the language being learned.</p>
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
                    <th scope="row">Max Number of Options:</th>
                    <td>
                        <input type="number" name="ll_max_options_override" id="ll_max_options_override" value="<?php echo esc_attr(get_option('ll_max_options_override', 9)); ?>" min="2" />
                        <p class="description">Set the maximum number of options in flashcards. Minimum is 2.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Flashcard Image Size:</th>
                    <td>
                        <?php
                        $flashcard_image_size = get_option('ll_flashcard_image_size', 'small');
                        ?>
                        <select name="ll_flashcard_image_size" id="ll_flashcard_image_size">
                            <option value="small" <?php selected($flashcard_image_size, 'small'); ?>>Small (150×150)</option>
                            <option value="medium" <?php selected($flashcard_image_size, 'medium'); ?>>Medium (200×200)</option>
                            <option value="large" <?php selected($flashcard_image_size, 'large'); ?>>Large (250×250)</option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Hide Word Titles in Recording Interface:</th>
                    <td>
                        <input type="checkbox" name="ll_hide_recording_titles" id="ll_hide_recording_titles" value="1" <?php checked(1, get_option('ll_hide_recording_titles', 0), true); ?> />
                        <p class="description">Check this box to hide word titles from audio recorders by default. This helps prevent pronunciation bias from reading the word.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Allow Learner Registration:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <input
                            type="checkbox"
                            name="ll_allow_learner_self_registration"
                            id="ll_allow_learner_self_registration"
                            value="1"
                            <?php checked(1, $allow_learner_self_registration, true); ?> />
                        <p class="description"><?php esc_html_e('Allow new users to create learner accounts from learner-facing progress sign-in screens.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Quiz Font (for text mode):</th>
                    <td>
                        <?php
                        $available_fonts = ll_get_site_available_fonts();
                        $selected_font = get_option('ll_quiz_font');
                        if ( !empty( $available_fonts ) ) {
                            echo '<select name="ll_quiz_font" id="ll_quiz_font">';
                            echo '<option value="">-- Select a Font --</option>';
                            foreach ( $available_fonts as $font ) {
                                echo '<option value="' . esc_attr($font) . '" ' . selected( $selected_font, $font, false ) . '>' . esc_html( $font ) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<p>No fonts found. Please ensure fonts are enqueued by your theme or the Use Any Font plugin.</p>';
                        }
                        ?>
                        <p class="description">
                            Choose one of the fonts that are already loaded on your site.
                            If you want to add a custom font, add it with the Use Any Font plugin or enqueue it manually.
                        </p>
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
                <tr valign="top">
                    <th scope="row">Update Branch:</th>
                    <td>
                        <select name="ll_update_branch" id="ll_update_branch">
                            <option value="main" <?php selected($update_branch, 'main'); ?>>Main (stable)</option>
                            <option value="dev" <?php selected($update_branch, 'dev'); ?>>Dev (testing)</option>
                        </select>
                        <p class="description">Switch to Dev to have this site pull plugin updates from the GitHub dev branch for testing. Use Main for normal production updates.</p>
                    </td>
                </tr>
                <?php do_action('ll_tools_settings_after_translations'); ?>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr />

        <h2>Flush quiz caches</h2>
        <p>
            Clears cached quiz word payload transients and increments cache versions for all
            <code>word-category</code> terms.
        </p>

        <form method="post">
            <?php wp_nonce_field( 'll_tools_flush_quiz_cache', 'll_tools_flush_quiz_cache_nonce' ); ?>
            <p class="submit">
                <button type="submit" name="ll_tools_flush_quiz_cache" class="button button-secondary">
                    Flush Quiz Caches
                </button>
            </p>
            <?php if ( is_array( $cache_result ) ) : ?>
                <p><strong>Transient rows deleted:</strong> <?php echo esc_html( (string) $cache_result['deleted'] ); ?> |
                   <strong>Categories bumped:</strong> <?php echo esc_html( (string) $cache_result['bumped'] ); ?> |
                   <strong>Object cache flushed:</strong> <?php echo ! empty( $cache_result['object_cache_flushed'] ) ? 'yes' : 'no'; ?></p>
            <?php endif; ?>
        </form>

        <hr />

        <h2>Purge legacy word audio meta</h2>
        <p>
            Removes <code>word_audio_file</code> meta from <code>words</code> posts now that audio is stored in
            <code>word_audio</code> children. This is irreversible. Make a backup first.
        </p>

        <form method="post">
            <?php wp_nonce_field( 'll_tools_purge_legacy_audio', 'll_tools_purge_legacy_audio_nonce' ); ?>
            <p class="submit">
                <button type="submit" name="ll_tools_purge_legacy_audio" class="button button-secondary">
                    Purge Legacy Meta
                </button>
            </p>
            <?php if ( is_array( $purge_result ) ) : ?>
                <p><strong>Legacy rows found:</strong> <?php echo esc_html( (string) $purge_result['count'] ); ?> |
                   <strong>Deleted:</strong> <?php echo esc_html( (string) $purge_result['deleted'] ); ?></p>
            <?php endif; ?>
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

/**
 * Returns an array of font family names that appear to be loaded on the site.
 * It looks for enqueued styles whose src contains either “fonts.googleapis.com”
 * or a file extension like .woff, .woff2, .ttf, .otf, or .eot.
 *
 * @return array List of font names.
 */
function ll_get_site_available_fonts() {
    global $wp_styles;
    $fonts = array();

    if ( isset( $wp_styles ) && is_object( $wp_styles ) && !empty( $wp_styles->registered ) ) {
        foreach ( $wp_styles->registered as $handle => $style ) {
            if ( isset( $style->src ) ) {
                // Look for Google Fonts
                if ( false !== strpos( $style->src, 'fonts.googleapis.com' ) ) {
                    $url_parts = wp_parse_url( $style->src );
                    if ( isset( $url_parts['query'] ) ) {
                        parse_str( $url_parts['query'], $query );
                        if ( ! empty( $query['family'] ) ) {
                            $families = explode( '|', $query['family'] );
                            foreach ( $families as $family ) {
                                $font = explode( ':', $family )[0];
                                $font = str_replace( '+', ' ', $font );
                                $fonts[] = $font;
                            }
                        }
                    }
                }
                // Look for locally enqueued fonts
                elseif ( preg_match( '/\.(woff2?|ttf|otf|eot)(\?.*)?$/i', $style->src ) ) {
                    $path_parts = pathinfo( $style->src );
                    if ( ! empty( $path_parts['filename'] ) ) {
                        $font = ucwords( str_replace( array('-', '_'), ' ', $path_parts['filename'] ) );
                        $fonts[] = $font;
                    }
                }
            }
        }
    }
    // Merge with fonts registered by Use Any Font plugin
    if ( function_exists('uaf_get_font_families') ) {
        $custom_fonts = uaf_get_font_families(); // This should return an array of font names.
        if ( is_array( $custom_fonts ) ) {
            $fonts = array_merge( $fonts, $custom_fonts );
        }
    }
    $fonts = array_unique( $fonts );
    sort( $fonts );
    return apply_filters( 'll_site_available_fonts', $fonts );
}

?>
