<?php
/**
 * Settings Page for Language Learner Tools
 *
 * This file handles the settings page for configuring the plugin options,
 * including language settings and other customizable features.
 */

function ll_tools_get_settings_maintenance_capability(): string {
    return (string) apply_filters('ll_tools_settings_maintenance_capability', 'manage_options');
}

function ll_tools_current_user_can_settings_maintenance(): bool {
    return current_user_can(ll_tools_get_settings_maintenance_capability());
}

function ll_register_settings_page() {
    $settings_slug = function_exists('ll_tools_get_admin_settings_page_slug')
        ? ll_tools_get_admin_settings_page_slug()
        : (defined('LL_TOOLS_SETTINGS_SLUG') ? (string) LL_TOOLS_SETTINGS_SLUG : 'language-learning-tools-settings');

    add_options_page(
        __('Language Learning Tools Settings', 'll-tools-text-domain'),
        __('Language Learning Tools', 'll-tools-text-domain'),
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
    register_setting('language-learning-tools-options', 'll_enable_browser_language_autoswitch', array(
        'type' => 'boolean',
        'sanitize_callback' => 'll_sanitize_browser_language_autoswitch_setting',
        'default' => 1,
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
        'sanitize_callback' => 'll_sanitize_learner_registration_setting',
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

function ll_sanitize_learner_registration_setting($value) {
    return ll_tools_normalize_learner_registration_setting_value($value);
}

function ll_sanitize_browser_language_autoswitch_setting($value) {
    if (function_exists('ll_tools_normalize_browser_language_autoswitch_setting_value')) {
        return ll_tools_normalize_browser_language_autoswitch_setting_value($value);
    }

    return absint($value) === 1 ? 1 : 0;
}

function ll_tools_normalize_learner_registration_setting_value($value) {
    return (absint($value) === 1) ? 1 : 0;
}

function ll_tools_sync_wordpress_registration_setting($value) {
    $enabled = ll_tools_normalize_learner_registration_setting_value($value);

    if (is_multisite()) {
        $current = (string) get_site_option('registration', 'none');

        if ($enabled === 1) {
            $next = in_array($current, ['blog', 'all'], true) ? 'all' : 'user';
        } else {
            $next = in_array($current, ['blog', 'all'], true) ? 'blog' : 'none';
        }

        if ($current !== $next) {
            update_site_option('registration', $next);
        }

        return;
    }

    if ((int) get_option('users_can_register', 0) !== $enabled) {
        update_option('users_can_register', $enabled);
    }
}

function ll_tools_sync_wordpress_registration_from_learner_setting($value, $old_value, $option) {
    $normalized = ll_tools_normalize_learner_registration_setting_value($value);
    ll_tools_sync_wordpress_registration_setting($normalized);
    return $normalized;
}
add_filter('pre_update_option_ll_allow_learner_self_registration', 'll_tools_sync_wordpress_registration_from_learner_setting', 10, 3);

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

    $can_run_maintenance = ll_tools_current_user_can_settings_maintenance();
    $purge_notice = '';
    $purge_result = null;
    $cache_notice = '';
    $cache_result = null;

    if ( isset( $_POST['ll_tools_purge_legacy_audio'] ) ) {
        if ( ! $can_run_maintenance ) {
            $purge_notice = '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to run maintenance actions.', 'll-tools-text-domain') . '</p></div>';
        } elseif ( ! isset( $_POST['ll_tools_purge_legacy_audio_nonce'] ) || ! wp_verify_nonce( $_POST['ll_tools_purge_legacy_audio_nonce'], 'll_tools_purge_legacy_audio' ) ) {
            $purge_notice = '<div class="notice notice-error"><p>' . esc_html__('Purge failed security check. Please try again.', 'll-tools-text-domain') . '</p></div>';
        } else {
            $purge_result = ll_tools_purge_legacy_word_audio_meta();
            $bumped = ll_tools_bump_word_category_cache();
            $purge_notice = '<div class="notice notice-success"><p>'
                . sprintf(
                    /* translators: %d: number of deleted rows */
                    esc_html__('Purged %d legacy meta rows.', 'll-tools-text-domain'),
                    (int) $purge_result['deleted']
                )
                . '</p></div>';
            if ( $bumped > 0 ) {
                $purge_notice .= '<div class="notice notice-success"><p>'
                    . sprintf(
                        /* translators: %d: number of word categories */
                        esc_html__('Cache bumped for %d word categories.', 'll-tools-text-domain'),
                        (int) $bumped
                    )
                    . '</p></div>';
            }
        }
    }

    if ( isset( $_POST['ll_tools_flush_quiz_cache'] ) ) {
        if ( ! $can_run_maintenance ) {
            $cache_notice = '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to run maintenance actions.', 'll-tools-text-domain') . '</p></div>';
        } elseif ( ! isset( $_POST['ll_tools_flush_quiz_cache_nonce'] ) || ! wp_verify_nonce( $_POST['ll_tools_flush_quiz_cache_nonce'], 'll_tools_flush_quiz_cache' ) ) {
            $cache_notice = '<div class="notice notice-error"><p>' . esc_html__('Cache flush failed security check. Please try again.', 'll-tools-text-domain') . '</p></div>';
        } else {
            $cache_result = ll_tools_flush_quiz_word_caches();
            $cache_notice = '<div class="notice notice-success"><p>' . esc_html__('Flushed quiz caches and bumped category cache versions.', 'll-tools-text-domain') . '</p></div>';
        }
    }

    $update_branch = get_option('ll_update_branch', 'main');
    $quiz_font_url = get_option('ll_quiz_font_url');
    $enable_browser_language_autoswitch = (int) get_option('ll_enable_browser_language_autoswitch', 1);
    $allow_learner_self_registration = (int) get_option('ll_allow_learner_self_registration', 1);
    $learner_registration_enabled = function_exists('ll_tools_is_learner_self_registration_available')
        ? ll_tools_is_learner_self_registration_available()
        : ($allow_learner_self_registration === 1);

    $wordset_admin_url = add_query_arg([
        'taxonomy' => 'wordset',
        'post_type' => 'words',
    ], admin_url('edit-tags.php'));
    ?>
    <div class="wrap">
        <h2><?php esc_html_e('Language Learning Tools Settings', 'll-tools-text-domain'); ?></h2>
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
                    <th scope="row"><?php esc_html_e('Word set language settings:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <p class="description">
                            <?php esc_html_e('Target language, translation language, category-name translation settings, and word-title language now live on each word set.', 'll-tools-text-domain'); ?>
                        </p>
                        <p class="description">
                            <a href="<?php echo esc_url($wordset_admin_url); ?>"><?php esc_html_e('Open Word Sets', 'll-tools-text-domain'); ?></a>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Auto-detect Browser Language:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <input
                            type="checkbox"
                            name="ll_enable_browser_language_autoswitch"
                            id="ll_enable_browser_language_autoswitch"
                            value="1"
                            <?php checked(1, $enable_browser_language_autoswitch, true); ?> />
                        <p class="description"><?php esc_html_e('Use a visitor\'s browser language on the front end until they explicitly choose a different language. Logged-in user locale preferences and the language switcher still take priority.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Max Number of Options:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <input type="number" name="ll_max_options_override" id="ll_max_options_override" value="<?php echo esc_attr(get_option('ll_max_options_override', 9)); ?>" min="2" />
                        <p class="description"><?php esc_html_e('Set the maximum number of options in flashcards. Minimum is 2.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Flashcard Image Size:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        $flashcard_image_size = get_option('ll_flashcard_image_size', 'small');
                        ?>
                        <select name="ll_flashcard_image_size" id="ll_flashcard_image_size">
                            <option value="small" <?php selected($flashcard_image_size, 'small'); ?>><?php esc_html_e('Small (150×150)', 'll-tools-text-domain'); ?></option>
                            <option value="medium" <?php selected($flashcard_image_size, 'medium'); ?>><?php esc_html_e('Medium (200×200)', 'll-tools-text-domain'); ?></option>
                            <option value="large" <?php selected($flashcard_image_size, 'large'); ?>><?php esc_html_e('Large (250×250)', 'll-tools-text-domain'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Hide Word Titles in Recording Interface:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <input type="checkbox" name="ll_hide_recording_titles" id="ll_hide_recording_titles" value="1" <?php checked(1, get_option('ll_hide_recording_titles', 0), true); ?> />
                        <p class="description"><?php esc_html_e('Check this box to hide word titles from audio recorders by default. This helps prevent pronunciation bias from reading the word.', 'll-tools-text-domain'); ?></p>
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
                            <?php checked(1, (int) $learner_registration_enabled, true); ?> />
                        <p class="description"><?php esc_html_e('Allow new users to create learner accounts from learner-facing progress sign-in screens. This also turns WordPress user registration on or off for the site.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Quiz Font (for text mode):', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        $available_fonts = ll_get_site_available_fonts();
                        $selected_font = get_option('ll_quiz_font');
                        if ( !empty( $available_fonts ) ) {
                            echo '<select name="ll_quiz_font" id="ll_quiz_font">';
                            echo '<option value="">' . esc_html__( '-- Select a Font --', 'll-tools-text-domain' ) . '</option>';
                            foreach ( $available_fonts as $font ) {
                                echo '<option value="' . esc_attr($font) . '" ' . selected( $selected_font, $font, false ) . '>' . esc_html( $font ) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<p>' . esc_html__('No fonts found. Please ensure fonts are enqueued by your theme or the Use Any Font plugin.', 'll-tools-text-domain') . '</p>';
                        }
                        ?>
                        <p class="description">
                            <?php esc_html_e('Choose one of the fonts that are already loaded on your site. If you want to add a custom font, add it with the Use Any Font plugin or enqueue it manually.', 'll-tools-text-domain'); ?>
                        </p>
                        <p style="margin:12px 0 6px 0;">
                            <label for="ll_quiz_font_url"><strong><?php echo esc_html__('Font stylesheet URL (Google Fonts or local CSS)', 'll-tools-text-domain'); ?></strong></label>
                        </p>
                        <input
                            type="url"
                            class="regular-text code"
                            style="width:min(100%, 720px);"
                            name="ll_quiz_font_url"
                            id="ll_quiz_font_url"
                            value="<?php echo esc_attr((string) $quiz_font_url); ?>"
                            placeholder="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;800&display=swap"
                        />
                        <p class="description">
                            <?php echo esc_html__('Paste a Google Fonts CSS URL (or another font stylesheet URL) to make that font available to LL Tools admin previews, quizzes, and wordset font overrides.', 'll-tools-text-domain'); ?>
                        </p>
                        <p class="description">
                            <?php echo esc_html__('Example (Heebo): https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;800&display=swap', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Update Branch:', 'll-tools-text-domain'); ?></th>
                    <td>
                        <select name="ll_update_branch" id="ll_update_branch">
                            <option value="main" <?php selected($update_branch, 'main'); ?>><?php esc_html_e('Main (stable)', 'll-tools-text-domain'); ?></option>
                            <option value="dev" <?php selected($update_branch, 'dev'); ?>><?php esc_html_e('Dev (testing)', 'll-tools-text-domain'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Use Main for packaged production releases. Switch to Dev only to pull the GitHub dev branch for testing.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <?php do_action('ll_tools_settings_after_translations'); ?>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr />

        <?php if ( $can_run_maintenance ) : ?>
            <h2><?php esc_html_e('Flush quiz caches', 'll-tools-text-domain'); ?></h2>
            <p>
                <?php esc_html_e('Clears cached quiz word payload transients and increments cache versions for all word-category terms.', 'll-tools-text-domain'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( 'll_tools_flush_quiz_cache', 'll_tools_flush_quiz_cache_nonce' ); ?>
                <p class="submit">
                    <button type="submit" name="ll_tools_flush_quiz_cache" class="button button-secondary">
                        <?php esc_html_e('Flush Quiz Caches', 'll-tools-text-domain'); ?>
                    </button>
                </p>
                <?php if ( is_array( $cache_result ) ) : ?>
                    <p><strong><?php esc_html_e('Transient rows deleted:', 'll-tools-text-domain'); ?></strong> <?php echo esc_html( (string) $cache_result['deleted'] ); ?> |
                       <strong><?php esc_html_e('Categories bumped:', 'll-tools-text-domain'); ?></strong> <?php echo esc_html( (string) $cache_result['bumped'] ); ?> |
                       <strong><?php esc_html_e('Object cache flushed:', 'll-tools-text-domain'); ?></strong> <?php echo ! empty( $cache_result['object_cache_flushed'] ) ? esc_html__('yes', 'll-tools-text-domain') : esc_html__('no', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Purge legacy word audio meta', 'll-tools-text-domain'); ?></h2>
            <p>
                <?php esc_html_e('Removes word_audio_file meta from words posts now that audio is stored in word_audio children. This is irreversible. Make a backup first.', 'll-tools-text-domain'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( 'll_tools_purge_legacy_audio', 'll_tools_purge_legacy_audio_nonce' ); ?>
                <p class="submit">
                    <button type="submit" name="ll_tools_purge_legacy_audio" class="button button-secondary">
                        <?php esc_html_e('Purge Legacy Meta', 'll-tools-text-domain'); ?>
                    </button>
                </p>
                <?php if ( is_array( $purge_result ) ) : ?>
                    <p><strong><?php esc_html_e('Legacy rows found:', 'll-tools-text-domain'); ?></strong> <?php echo esc_html( (string) $purge_result['count'] ); ?> |
                       <strong><?php esc_html_e('Deleted:', 'll-tools-text-domain'); ?></strong> <?php echo esc_html( (string) $purge_result['deleted'] ); ?></p>
                <?php endif; ?>
            </form>
        <?php else : ?>
            <h2><?php esc_html_e('Maintenance actions', 'll-tools-text-domain'); ?></h2>
            <p><?php esc_html_e('Quiz cache flushing and legacy meta purges require administrator-level access.', 'll-tools-text-domain'); ?></p>
        <?php endif; ?>
    </div>

    <?php
}

function ll_auto_translate_existing_categories($old_value, $new_value, $option_name) {
    if (!$old_value && $new_value) { // Only run when the setting is enabled
        $translation_source = function_exists('ll_tools_get_legacy_category_translation_source_setting')
            ? ll_tools_get_legacy_category_translation_source_setting()
            : get_option('ll_category_translation_source', 'target');
        $source_language = function_exists('ll_tools_get_legacy_target_language_setting')
            ? ll_tools_get_legacy_target_language_setting()
            : (string) get_option('ll_target_language', '');
        $target_language = function_exists('ll_tools_get_legacy_translation_language_setting')
            ? ll_tools_get_legacy_translation_language_setting()
            : (string) get_option('ll_translation_language', '');

        if ($translation_source === 'translation') {
            $source_language = function_exists('ll_tools_get_legacy_translation_language_setting')
                ? ll_tools_get_legacy_translation_language_setting()
                : (string) get_option('ll_translation_language', '');
            $target_language = function_exists('ll_tools_get_legacy_target_language_setting')
                ? ll_tools_get_legacy_target_language_setting()
                : (string) get_option('ll_target_language', '');
        }

        $results = function_exists('ll_tools_translate_missing_category_names')
            ? ll_tools_translate_missing_category_names((string) $source_language, (string) $target_language)
            : [
                'success' => [],
                'errors' => [],
                'source_language' => (string) $source_language,
                'target_language' => (string) $target_language,
            ];

        if (function_exists('ll_tools_store_category_translation_results_notice')) {
            ll_tools_store_category_translation_results_notice($results);
        } else {
            set_transient('ll_translation_results', $results, 60);
        }
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
        echo '<p><strong>' . esc_html__('Category Translations:', 'll-tools-text-domain') . '</strong> '
            . sprintf(
                /* translators: 1: source language code, 2: target language code */
                esc_html__('Translating from %1$s to %2$s.', 'll-tools-text-domain'),
                '<strong>' . esc_html($source_language) . '</strong>',
                '<strong>' . esc_html($target_language) . '</strong>'
            )
            . '</p>';
        echo '</div>';

        // Display success results
        if (!empty($results['success'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . esc_html__('Translation Results:', 'll-tools-text-domain') . '</strong></p>';
            echo '<ul>';
            foreach ($results['success'] as $success) {
                echo '<li><strong>' . esc_html__('Original:', 'll-tools-text-domain') . '</strong> ' . esc_html($success['original'])
                     . ' <strong>' . esc_html__('Translated:', 'll-tools-text-domain') . '</strong> ' . esc_html($success['translated']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Display error results
        if (!empty($results['errors'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . esc_html__('Translation Errors:', 'll-tools-text-domain') . '</strong></p>';
            echo '<ul>';
            foreach ($results['errors'] as $error) {
                echo '<li><strong>' . esc_html__('Original:', 'll-tools-text-domain') . '</strong> ' . esc_html($error['original'])
                     . ' <strong>' . esc_html__('Error:', 'll-tools-text-domain') . '</strong> ' . esc_html($error['error']) . '</li>';
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
