<?php

/**
 * Registers the "word-category" taxonomy for "words" and "word_images" post types.
 *
 * @return void
 */
function ll_tools_register_word_category_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Categories", "ll-tools-text-domain"),
        "singular_name" => esc_html__("Word Category", "ll-tools-text-domain"),
    ];

    $args = [
        "label" => esc_html__("Word Categories", "ll-tools-text-domain"),
        "labels" => $labels,
        "public" => true,
        "publicly_queryable" => true,
        "hierarchical" => true,
        "show_ui" => true,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "query_var" => true,
        "rewrite" => ['slug' => 'word-category', 'with_front' => true],
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

    // Initialize translation meta fields and bulk‐add hooks
    ll_tools_initialize_word_category_meta_fields();
}
add_action('init', 'll_tools_register_word_category_taxonomy');

// Sentinel to mark a category as "do not record"
if (!defined('LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED')) {
    define('LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED', '__none__');
}

/**
 * Override the term count for word-category to show only published words.
 * This runs before the terms are displayed in the admin table.
 *
 * @param array $terms Array of term objects.
 * @param array $taxonomies Array of taxonomy names.
 * @param array $args Query arguments.
 * @return array Modified terms with accurate counts.
 */
function ll_fix_word_category_counts_in_admin($terms, $taxonomies, $args) {
    // Only apply to word-category taxonomy in admin
    if (!is_admin() || !in_array('word-category', (array)$taxonomies, true)) {
        return $terms;
    }

    // Only fix counts on the edit-tags.php page
    global $pagenow;
    if ($pagenow !== 'edit-tags.php' && $pagenow !== 'term.php') {
        return $terms;
    }

    foreach ($terms as $term) {
        if (!is_object($term) || !isset($term->term_id)) {
            continue;
        }

        // Count only published words in this category
        $q = new WP_Query([
            'post_type'      => 'words',
            'post_status'    => 'publish',
            'tax_query'      => [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => $term->term_id,
            ]],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        $term->count = $q->found_posts;
        wp_reset_postdata();
    }

    return $terms;
}
add_filter('get_terms', 'll_fix_word_category_counts_in_admin', 10, 3);

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

    // Prompt/answer presentation settings (category-level)
    add_action('word-category_add_form_fields', 'll_add_quiz_prompt_option_fields');
    add_action('word-category_edit_form_fields', 'll_edit_quiz_prompt_option_fields');
    add_action('created_word-category', 'll_save_quiz_prompt_option_fields', 10, 2);
    add_action('edited_word-category', 'll_save_quiz_prompt_option_fields', 10, 2);

    // Bulk‑add form display and processing hooks
    add_action('admin_notices', 'll_render_bulk_add_categories_form');
    add_action('admin_post_ll_word_category_bulk_add', 'll_process_bulk_add_categories');

    // Desired recording types meta (category-level)
    add_action('word-category_add_form_fields', 'll_add_desired_recording_types_field');
    add_action('word-category_edit_form_fields', 'll_edit_desired_recording_types_field');
    add_action('created_word-category', 'll_save_desired_recording_types_field', 10, 2);
    add_action('edited_word-category', 'll_save_desired_recording_types_field', 10, 2);
}

/**
 * One-time seeding of desired recording types for existing categories.
 * Uses the main defaults (isolation, question, introduction) and does not
 * overwrite categories that already have a selection.
 */
function ll_tools_seed_existing_categories_desired_types() {
    if (!is_admin()) { return; }
    // Run once; if you need to re-run, delete this option.
    if (get_option('ll_seeded_category_desired_types', false)) { return; }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        update_option('ll_seeded_category_desired_types', 1);
        return;
    }

    $defaults = ll_tools_get_main_recording_types();
    foreach ($terms as $tid) {
        $existing = get_term_meta($tid, 'll_desired_recording_types', true);
        if (ll_tools_is_category_recording_disabled($tid)) { continue; }
        // Only set if nothing exists yet; for text-only categories seed isolation-only
        if (empty($existing)) {
            $is_text_only = get_term_meta((int) $tid, 'use_word_titles_for_audio', true) === '1';
            $to_seed = $is_text_only ? ['isolation'] : $defaults;
            update_term_meta($tid, 'll_desired_recording_types', $to_seed);
        }
    }

    update_option('ll_seeded_category_desired_types', 1);
}
add_action('admin_init', 'll_tools_seed_existing_categories_desired_types');

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
        $translation = sanitize_text_field($_POST['term_translation']);
        update_term_meta($term_id, 'term_translation', $translation);
    }
}

/**
 * Resolve the user-facing display name for a word-category term.
 *
 * @param int|WP_Term $term  Term ID or object (taxonomy: word-category)
 * @param array $args {
 *   @type bool|null   $enable_translation  Default: get_option('ll_enable_category_translation', 0)
 *   @type string|null $target_language     Default: get_option('ll_translation_language', 'en') (e.g., 'en', 'tr')
 *   @type string|null $site_language       Default: get_locale() (e.g., 'en_US', 'tr_TR')
 *   @type string      $meta_key            Default: 'term_translation'
 * }
 * @return string
 */
function ll_tools_get_category_display_name($term, array $args = []) {
    $tax = 'word-category';
    if (!($term instanceof WP_Term)) {
        $term = get_term($term, $tax);
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return '';
    }

    $defaults = [
        'enable_translation' => (bool) get_option('ll_enable_category_translation', 0),
        'target_language'    => strtolower((string) get_option('ll_translation_language', 'en')),
        'site_language'      => strtolower((string) get_locale()),
        'meta_key'           => 'term_translation',
    ];
    $opts = array_merge($defaults, $args);
    $cacheable = !has_filter('ll_tools_category_display_name');

    if ($cacheable) {
        $term_id = (int) $term->term_id;
        $category_version = function_exists('ll_tools_get_category_cache_version')
            ? (int) ll_tools_get_category_cache_version($term_id)
            : 1;
        if ($category_version < 1) {
            $category_version = 1;
        }

        $cache_key = 'll_wc_display_name_' . md5(wp_json_encode([
            'term_id' => $term_id,
            'version' => $category_version,
            'enable_translation' => !empty($opts['enable_translation']) ? 1 : 0,
            'target_language' => (string) ($opts['target_language'] ?? ''),
            'site_language' => (string) ($opts['site_language'] ?? ''),
            'meta_key' => (string) ($opts['meta_key'] ?? 'term_translation'),
            'schema' => 1,
        ]));
        $cache_group = 'll_tools_quiz_category';
        $cache_ttl = 6 * HOUR_IN_SECONDS;

        static $request_cache = [];
        if (isset($request_cache[$cache_key]) && is_string($request_cache[$cache_key])) {
            return $request_cache[$cache_key];
        }

        $cached = wp_cache_get($cache_key, $cache_group);
        if ($cached === false) {
            $cached = get_transient($cache_key);
        }
        if (is_string($cached)) {
            $request_cache[$cache_key] = $cached;
            return $cached;
        }
    }

    $display = $term->name;

    $use_translations = $opts['enable_translation']
        && $opts['target_language'] !== ''
        && strpos($opts['site_language'], $opts['target_language']) === 0;

    if ($use_translations) {
        $maybe = get_term_meta($term->term_id, $opts['meta_key'], true);
        if (is_string($maybe) && $maybe !== '') {
            $display = $maybe;
        }
    }

    /**
     * Filter the resolved display name for a category term.
     *
     * @param string  $display
     * @param WP_Term $term
     * @param array   $opts
     */
    $result = apply_filters('ll_tools_category_display_name', $display, $term, $opts);
    $result = is_string($result) ? $result : (string) $result;

    if ($cacheable) {
        $request_cache[$cache_key] = $result;
        wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
        set_transient($cache_key, $result, $cache_ttl);
    }

    return $result;
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
 * Allowed prompt + answer option types for quizzes.
 */
function ll_tools_get_quiz_prompt_types(): array {
    return ['audio', 'image', 'text_translation', 'text_title'];
}
function ll_tools_get_quiz_option_types(): array {
    return ['image', 'text_translation', 'text_title', 'audio', 'text_audio'];
}

function ll_tools_is_text_quiz_type($value): bool {
    $val = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($val, ['text_translation', 'text_title'], true);
}

/**
 * Normalize stored prompt/option values with safe fallbacks.
 */
function ll_tools_normalize_quiz_prompt_type($value, bool $use_titles = false): string {
    $val = is_string($value) ? strtolower($value) : '';
    // Map legacy value "text" to the appropriate new variant
    if ($val === 'text') {
        return $use_titles ? 'text_title' : 'text_translation';
    }
    return in_array($val, ll_tools_get_quiz_prompt_types(), true) ? $val : 'audio';
}
/**
 * Resolve a text option type so text-only quizzes use the complementary text side.
 *
 * @param string $prompt_type
 * @param bool   $use_titles
 * @return string
 */
function ll_tools_resolve_text_option_type_for_prompt($prompt_type, bool $use_titles = false): string {
    $normalized_prompt = ll_tools_normalize_quiz_prompt_type($prompt_type, $use_titles);
    if ($normalized_prompt === 'text_title') {
        return 'text_translation';
    }
    if ($normalized_prompt === 'text_translation') {
        return 'text_title';
    }
    return $use_titles ? 'text_title' : 'text_translation';
}

function ll_tools_normalize_quiz_option_type($value, bool $use_titles = false, string $prompt_type = ''): string {
    $val = is_string($value) ? strtolower(trim($value)) : '';
    // Map legacy value "text" and UI aliases to the appropriate text variant.
    if (in_array($val, ['text', 'text_match_prompt', 'text_only'], true)) {
        return ll_tools_resolve_text_option_type_for_prompt($prompt_type, $use_titles);
    }
    $normalized = in_array($val, ll_tools_get_quiz_option_types(), true)
        ? $val
        : ($use_titles ? 'text_title' : 'image');

    $normalized_prompt = ll_tools_normalize_quiz_prompt_type($prompt_type, $use_titles);
    if (ll_tools_is_text_quiz_type($normalized_prompt) && $normalized === $normalized_prompt) {
        return ll_tools_resolve_text_option_type_for_prompt($normalized_prompt, $use_titles);
    }

    return $normalized;
}

/**
 * Resolve defaults + persisted settings for how a category should quiz.
 *
 * @param int|WP_Term $term Term id or object.
 * @return array { prompt_type, option_type, use_titles, learning_supported }
 */
function ll_tools_get_category_quiz_config($term): array {
    $fallback = [
        'prompt_type'        => 'audio',
        'option_type'        => 'image',
        'use_titles'         => false,
        'learning_supported' => true,
    ];

    if (!($term instanceof WP_Term)) {
        $term = get_term($term, 'word-category');
    }
    if (!($term instanceof WP_Term)) {
        return $fallback;
    }

    $term_id = (int) $term->term_id;
    $use_titles_legacy = get_term_meta($term_id, 'use_word_titles_for_audio', true) === '1';
    $stored_option_type_raw = get_term_meta($term_id, 'll_quiz_option_type', true);
    $stored_option_type = is_string($stored_option_type_raw) ? $stored_option_type_raw : (string) $stored_option_type_raw;
    $stored_prompt_type_raw = get_term_meta($term_id, 'll_quiz_prompt_type', true);
    $stored_prompt_type = is_string($stored_prompt_type_raw) ? $stored_prompt_type_raw : (string) $stored_prompt_type_raw;
    $prompt_type = ll_tools_normalize_quiz_prompt_type($stored_prompt_type, $use_titles_legacy);

    $category_version = function_exists('ll_tools_get_category_cache_version')
        ? (int) ll_tools_get_category_cache_version($term_id)
        : 1;
    if ($category_version < 1) {
        $category_version = 1;
    }

    $cache_key = 'll_wc_quiz_cfg_' . md5(wp_json_encode([
        'term_id' => $term_id,
        'version' => $category_version,
        'use_titles_legacy' => $use_titles_legacy ? 1 : 0,
        'stored_option_type' => $stored_option_type,
        'stored_prompt_type' => $stored_prompt_type,
        'schema' => 1,
    ]));
    $cache_group = 'll_tools_quiz_category';
    $cache_ttl = 6 * HOUR_IN_SECONDS;

    static $request_cache = [];
    if (isset($request_cache[$cache_key]) && is_array($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }
    if (is_array($cached)) {
        $request_cache[$cache_key] = $cached;
        return $cached;
    }

    // Back-compat: derive an option type if none stored yet (older categories)
    $option_type = $stored_option_type !== ''
        ? ll_tools_normalize_quiz_option_type($stored_option_type, $use_titles_legacy, $prompt_type)
        : ll_tools_normalize_quiz_option_type(
            ll_tools_default_option_type_for_category($term),
            $use_titles_legacy,
            $prompt_type
        );

    // If legacy flag is present with legacy/empty option storage, prefer title-based text option.
    $stored_option_type_normalized = is_string($stored_option_type) ? strtolower(trim($stored_option_type)) : '';
    if ($option_type === 'text_translation' && $use_titles_legacy && in_array($stored_option_type_normalized, ['', 'text'], true)) {
        $option_type = 'text_title';
    }

    // Learning mode is tricky when prompting with an image but only text answers exist.
    $learning_supported = !($prompt_type === 'image' && in_array($option_type, ['text_title', 'text_translation'], true));

    $result = [
        'prompt_type'        => $prompt_type,
        'option_type'        => $option_type,
        'use_titles'         => ($option_type === 'text_title') || $use_titles_legacy,
        'learning_supported' => $learning_supported,
    ];

    $request_cache[$cache_key] = $result;
    wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
    set_transient($cache_key, $result, $cache_ttl);

    return $result;
}

/**
 * Helpers to evaluate resource requirements for a quiz configuration.
 */
function ll_tools_quiz_requires_audio(array $config, string $display_mode = ''): bool {
    $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
    $option_type = isset($config['option_type']) ? (string) $config['option_type'] : $display_mode;
    $needs_option_audio = in_array($option_type, ['audio', 'text_audio'], true);
    $needs_prompt_audio = ($prompt_type === 'audio');
    return $needs_option_audio || $needs_prompt_audio;
}

function ll_tools_get_category_lesson_grid_text_visibility_override($term): string {
    if (!($term instanceof WP_Term)) {
        $term = get_term($term, 'word-category');
    }
    if (!($term instanceof WP_Term)) {
        return 'inherit';
    }

    $value = sanitize_key((string) get_term_meta($term->term_id, 'll_lesson_grid_text_visibility_override', true));
    if (in_array($value, ['show', 'hide'], true)) {
        return $value;
    }

    return 'inherit';
}

function ll_tools_category_quiz_shows_text($term): bool {
    $config = ll_tools_get_category_quiz_config($term);
    $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
    $option_type = (string) ($config['option_type'] ?? '');

    if (strpos($prompt_type, 'text') === 0) {
        return true;
    }
    if (strpos($option_type, 'text') === 0) {
        return true;
    }

    return false;
}

function ll_tools_should_hide_lesson_grid_text($term, int $wordset_id = 0): bool {
    $override = ll_tools_get_category_lesson_grid_text_visibility_override($term);
    if ($override === 'show') {
        return false;
    }
    if ($override === 'hide') {
        return true;
    }

    if ($wordset_id <= 0 || !function_exists('ll_tools_wordset_hide_lesson_text_for_non_text_quiz')) {
        return false;
    }
    if (!ll_tools_wordset_hide_lesson_text_for_non_text_quiz($wordset_id)) {
        return false;
    }

    return !ll_tools_category_quiz_shows_text($term);
}

/**
 * Field to capture prompt + answer option preferences (add screen)
 */
function ll_add_quiz_prompt_option_fields($term) {
    $defaults = [
        'prompt_type' => 'audio',
        'option_type' => 'image',
    ];
    ?>
    <div class="form-field term-quiz-prompt-wrap">
        <label for="ll_quiz_prompt_type"><?php esc_html_e('Quiz Prompt Type', 'll-tools-text-domain'); ?></label>
        <select name="ll_quiz_prompt_type" id="ll_quiz_prompt_type">
            <option value="audio" <?php selected($defaults['prompt_type'], 'audio'); ?>><?php esc_html_e('Play audio (default)', 'll-tools-text-domain'); ?></option>
            <option value="image" <?php selected($defaults['prompt_type'], 'image'); ?>><?php esc_html_e('Show image', 'll-tools-text-domain'); ?></option>
            <option value="text_translation" <?php selected($defaults['prompt_type'], 'text_translation'); ?>><?php esc_html_e('Show text (translation)', 'll-tools-text-domain'); ?></option>
            <option value="text_title" <?php selected($defaults['prompt_type'], 'text_title'); ?>><?php esc_html_e('Show text (title)', 'll-tools-text-domain'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Choose whether the quiz starts with audio, an image, or text for this category.', 'll-tools-text-domain'); ?></p>
    </div>
    <div class="form-field term-quiz-option-wrap">
        <label for="ll_quiz_option_type"><?php esc_html_e('Answer Options', 'll-tools-text-domain'); ?></label>
        <select name="ll_quiz_option_type" id="ll_quiz_option_type">
            <option value="image" <?php selected($defaults['option_type'], 'image'); ?>><?php esc_html_e('Images', 'll-tools-text-domain'); ?></option>
            <option value="text"><?php esc_html_e('Text (opposite prompt)', 'll-tools-text-domain'); ?></option>
            <option value="text_translation"><?php esc_html_e('Text (translation)', 'll-tools-text-domain'); ?></option>
            <option value="text_title"><?php esc_html_e('Text (title)', 'll-tools-text-domain'); ?></option>
            <option value="audio"><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
            <option value="text_audio"><?php esc_html_e('Text + audio pairs', 'll-tools-text-domain'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Use text options for text-only quizzes (no audio/images), or choose audio options to play recordings.', 'll-tools-text-domain'); ?></p>
    </div>
    <div class="form-field term-lesson-grid-text-visibility-wrap">
        <label for="ll_lesson_grid_text_visibility_override"><?php esc_html_e('Lesson/Grid Text Visibility', 'll-tools-text-domain'); ?></label>
        <select name="ll_lesson_grid_text_visibility_override" id="ll_lesson_grid_text_visibility_override">
            <option value="inherit"><?php esc_html_e('Use word set default', 'll-tools-text-domain'); ?></option>
            <option value="show"><?php esc_html_e('Always show text', 'll-tools-text-domain'); ?></option>
            <option value="hide"><?php esc_html_e('Always hide text', 'll-tools-text-domain'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Controls lesson pages and [word_grid] displays. "Hide" shows images/audio buttons only. "Use word set default" follows the word set setting for non-text quiz categories.', 'll-tools-text-domain'); ?></p>
    </div>
    <script>
      (function(){
        const prompt = document.getElementById('ll_quiz_prompt_type');
        const option = document.getElementById('ll_quiz_option_type');
        if (!prompt || !option) return;
        function syncDisables(){
          Array.from(option.options).forEach(o => { o.disabled = false; });
          if (prompt.value === 'image') {
            const opt = option.querySelector('option[value="image"]');
            if (opt) { opt.disabled = true; if (option.value === 'image') { option.value = 'text_translation'; } }
          }
          if (prompt.value === 'audio') {
            const opt = option.querySelector('option[value="audio"]');
            if (opt) { opt.disabled = true; if (option.value === 'audio') { option.value = 'text_translation'; } }
          }
          if (prompt.value === 'text_title' || prompt.value === 'text_translation') {
            const opposite = prompt.value === 'text_title' ? 'text_translation' : 'text_title';
            const sameTextOpt = option.querySelector('option[value="' + prompt.value + '"]');
            if (sameTextOpt) {
              sameTextOpt.disabled = true;
              if (option.value === prompt.value) { option.value = opposite; }
            }
          }
        }
        prompt.addEventListener('change', syncDisables);
        syncDisables();
      })();
    </script>
    <?php
}

/**
 * Field to capture prompt + answer option preferences (edit screen)
 */
function ll_edit_quiz_prompt_option_fields($term) {
    $config = ll_tools_get_category_quiz_config($term);
    $lesson_grid_text_visibility_override = ll_tools_get_category_lesson_grid_text_visibility_override($term);
    ?>
    <tr class="form-field term-quiz-prompt-wrap">
        <th scope="row" valign="top">
            <label for="ll_quiz_prompt_type"><?php esc_html_e('Quiz Prompt Type', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <select name="ll_quiz_prompt_type" id="ll_quiz_prompt_type">
                <option value="audio" <?php selected($config['prompt_type'], 'audio'); ?>><?php esc_html_e('Play audio (default)', 'll-tools-text-domain'); ?></option>
                <option value="image" <?php selected($config['prompt_type'], 'image'); ?>><?php esc_html_e('Show image', 'll-tools-text-domain'); ?></option>
                <option value="text_translation" <?php selected($config['prompt_type'], 'text_translation'); ?>><?php esc_html_e('Show text (translation)', 'll-tools-text-domain'); ?></option>
                <option value="text_title" <?php selected($config['prompt_type'], 'text_title'); ?>><?php esc_html_e('Show text (title)', 'll-tools-text-domain'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Whether to start rounds with audio, with the word image, or with text.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <tr class="form-field term-quiz-option-wrap">
        <th scope="row" valign="top">
            <label for="ll_quiz_option_type"><?php esc_html_e('Answer Options', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <select name="ll_quiz_option_type" id="ll_quiz_option_type">
                <option value="image" <?php selected($config['option_type'], 'image'); ?>><?php esc_html_e('Images', 'll-tools-text-domain'); ?></option>
                <option value="text"><?php esc_html_e('Text (opposite prompt)', 'll-tools-text-domain'); ?></option>
                <option value="text_translation" <?php selected($config['option_type'], 'text_translation'); ?>><?php esc_html_e('Text (translation)', 'll-tools-text-domain'); ?></option>
                <option value="text_title" <?php selected($config['option_type'], 'text_title'); ?>><?php esc_html_e('Text (title)', 'll-tools-text-domain'); ?></option>
                <option value="audio" <?php selected($config['option_type'], 'audio'); ?>><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
                <option value="text_audio" <?php selected($config['option_type'], 'text_audio'); ?>><?php esc_html_e('Text + audio pairs', 'll-tools-text-domain'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Use text options for text-only quizzes (no audio/images), or choose audio options to play recordings.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <tr class="form-field term-lesson-grid-text-visibility-wrap">
        <th scope="row" valign="top">
            <label for="ll_lesson_grid_text_visibility_override"><?php esc_html_e('Lesson/Grid Text Visibility', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <select name="ll_lesson_grid_text_visibility_override" id="ll_lesson_grid_text_visibility_override">
                <option value="inherit" <?php selected($lesson_grid_text_visibility_override, 'inherit'); ?>><?php esc_html_e('Use word set default', 'll-tools-text-domain'); ?></option>
                <option value="show" <?php selected($lesson_grid_text_visibility_override, 'show'); ?>><?php esc_html_e('Always show text', 'll-tools-text-domain'); ?></option>
                <option value="hide" <?php selected($lesson_grid_text_visibility_override, 'hide'); ?>><?php esc_html_e('Always hide text', 'll-tools-text-domain'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Controls lesson pages and [word_grid] displays. "Hide" shows images/audio buttons only. "Use word set default" follows the word set setting for non-text quiz categories.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <script>
      (function(){
        const prompt = document.getElementById('ll_quiz_prompt_type');
        const option = document.getElementById('ll_quiz_option_type');
        if (!prompt || !option) return;
        function syncDisables(){
          Array.from(option.options).forEach(o => { o.disabled = false; });
          if (prompt.value === 'image') {
            const opt = option.querySelector('option[value="image"]');
            if (opt) { opt.disabled = true; if (option.value === 'image') { option.value = 'text_translation'; } }
          }
          if (prompt.value === 'audio') {
            const opt = option.querySelector('option[value="audio"]');
            if (opt) { opt.disabled = true; if (option.value === 'audio') { option.value = 'text_translation'; } }
          }
          if (prompt.value === 'text_title' || prompt.value === 'text_translation') {
            const opposite = prompt.value === 'text_title' ? 'text_translation' : 'text_title';
            const sameTextOpt = option.querySelector('option[value="' + prompt.value + '"]');
            if (sameTextOpt) {
              sameTextOpt.disabled = true;
              if (option.value === prompt.value) { option.value = opposite; }
            }
          }
        }
        prompt.addEventListener('change', syncDisables);
        syncDisables();
      })();
    </script>
    <?php
}

/**
 * Save prompt + answer option preferences.
 */
function ll_save_quiz_prompt_option_fields($term_id, $taxonomy) {
    $use_titles = get_term_meta($term_id, 'use_word_titles_for_audio', true) === '1';
    $prompt_for_option = ll_tools_normalize_quiz_prompt_type(
        get_term_meta($term_id, 'll_quiz_prompt_type', true),
        $use_titles
    );
    if (isset($_POST['ll_quiz_prompt_type'])) {
        $prompt = ll_tools_normalize_quiz_prompt_type(sanitize_text_field($_POST['ll_quiz_prompt_type']), $use_titles);
        update_term_meta($term_id, 'll_quiz_prompt_type', $prompt);
        $prompt_for_option = $prompt;
    }
    if (isset($_POST['ll_quiz_option_type'])) {
        $option = ll_tools_normalize_quiz_option_type(
            sanitize_text_field($_POST['ll_quiz_option_type']),
            $use_titles,
            $prompt_for_option
        );
        update_term_meta($term_id, 'll_quiz_option_type', $option);
        // Keep legacy meta in sync for compatibility
        if ($option === 'text_title') {
            update_term_meta($term_id, 'use_word_titles_for_audio', '1');
        } else {
            delete_term_meta($term_id, 'use_word_titles_for_audio');
        }
    }
    if (isset($_POST['ll_lesson_grid_text_visibility_override'])) {
        $visibility_override = sanitize_key((string) $_POST['ll_lesson_grid_text_visibility_override']);
        if (!in_array($visibility_override, ['show', 'hide'], true)) {
            delete_term_meta($term_id, 'll_lesson_grid_text_visibility_override');
        } else {
            update_term_meta($term_id, 'll_lesson_grid_text_visibility_override', $visibility_override);
        }
    }
}

/**
 * Category meta UI: Desired Recording Types (add screen)
 */
function ll_add_desired_recording_types_field($term) {
    $types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);
    if (is_wp_error($types)) { $types = []; }
    $default_checked = ll_tools_get_main_recording_types();
    ?>
    <input type="hidden" name="ll_desired_recording_types_submitted" value="1">
    <div class="form-field term-desired-recording-types-wrap">
        <label><?php esc_html_e('Desired Recording Types', 'll-tools-text-domain'); ?></label>
        <div style="max-height:140px; overflow:auto; border:1px solid #ccd0d4; padding:6px;">
            <?php foreach ($types as $type): $checked = in_array($type->slug, $default_checked, true) ? 'checked' : ''; ?>
                <label style="display:block; margin:2px 0;">
                    <input type="checkbox" name="ll_desired_recording_types[]" value="<?php echo esc_attr($type->slug); ?>" <?php echo $checked; ?>>
                    <?php echo esc_html($type->name . ' (' . $type->slug . ')'); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="description"><?php esc_html_e('Select the recording types that make sense for words in this category. Leave all unchecked to disable recording for this category.', 'll-tools-text-domain'); ?></p>
    </div>
    <?php
}

/**
 * Category meta UI: Desired Recording Types (edit screen)
 */
function ll_edit_desired_recording_types_field($term) {
    $raw_selected = get_term_meta($term->term_id, 'll_desired_recording_types', true);
    $disabled = ll_tools_is_category_recording_disabled($term->term_id);
    $selected = array_filter(array_map('sanitize_text_field', (array) $raw_selected));
    if ($disabled) {
        $selected = [];
    } elseif (empty($selected)) {
        $selected = ll_tools_get_main_recording_types();
    }
    $types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);
    if (is_wp_error($types)) { $types = []; }
    ?>
    <input type="hidden" name="ll_desired_recording_types_submitted" value="1">
    <tr class="form-field term-desired-recording-types-wrap">
        <th scope="row" valign="top">
            <label><?php esc_html_e('Desired Recording Types', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <div style="max-height:180px; overflow:auto; border:1px solid #ccd0d4; padding:6px;">
                <?php foreach ($types as $type): $checked = in_array($type->slug, $selected, true) ? 'checked' : ''; ?>
                    <label style="display:block; margin:2px 0;">
                        <input type="checkbox" name="ll_desired_recording_types[]" value="<?php echo esc_attr($type->slug); ?>" <?php echo $checked; ?>>
                        <?php echo esc_html($type->name . ' (' . $type->slug . ')'); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description">
                <?php
                echo esc_html__('If none selected, recording is disabled for this category.', 'll-tools-text-domain');
                if ($disabled) {
                    echo ' ' . esc_html__('Currently disabled (no recording types selected).', 'll-tools-text-domain');
                }
                ?>
            </p>
        </td>
    </tr>
    <?php
}

/**
 * Save category meta: Desired Recording Types
 */
function ll_save_desired_recording_types_field($term_id, $maybe_tt_id = null) {
    // Hooked to created_word-category / edited_word-category which pass ($term_id, $tt_id)
    // Do not rely on taxonomy parameter here.
    $submitted = isset($_POST['ll_desired_recording_types_submitted']);
    if (!$submitted) { return; }

    $incoming = isset($_POST['ll_desired_recording_types']) ? (array) $_POST['ll_desired_recording_types'] : [];
    $sanitized = array_values(array_unique(array_filter(array_map('sanitize_text_field', $incoming))));

    if (empty($sanitized)) {
        // Explicitly disable recording when nothing is selected
        update_term_meta($term_id, 'll_desired_recording_types', [LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED]);
    } else {
        update_term_meta($term_id, 'll_desired_recording_types', $sanitized);
    }
}

/**
 * Helper: main/core recording types
 */
function ll_tools_get_main_recording_types(): array {
    return ['isolation', 'question', 'introduction'];
}

/**
 * Helper: desired recording types when a word has no categories assigned.
 */
function ll_tools_get_uncategorized_desired_recording_types(): array {
    $selected = get_option('ll_uncategorized_desired_recording_types', []);
    if (!is_array($selected)) {
        $selected = [];
    }
    $sanitized = array_values(array_unique(array_map('sanitize_text_field', $selected)));
    if (!empty($sanitized)) {
        return $sanitized;
    }
    // Default to the simplest prompt for uncategorized/text-only cases
    return ['isolation'];
}

/**
 * Helper: get desired recording types for a category term (slugs)
 */
function ll_tools_get_desired_recording_types_for_category($term_id): array {
    // Normalize stored meta; an unset or empty meta entry can come back as [''], so treat that as empty.
    $selected_raw = get_term_meta((int) $term_id, 'll_desired_recording_types', true);
    $selected = array_filter(array_map('sanitize_text_field', (array) $selected_raw));

    if (in_array(LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED, $selected, true)) {
        return [];
    }

    if (!empty($selected)) { return array_values(array_unique($selected)); }

    $term = get_term((int) $term_id, 'word-category');
    if ($term && !is_wp_error($term) && $term->slug === 'uncategorized') {
        return ll_tools_get_uncategorized_desired_recording_types();
    }
    // If category is flagged as text-only, default to isolation-only for prompts
    $is_text_only = get_term_meta((int) $term_id, 'use_word_titles_for_audio', true) === '1';
    if ($is_text_only) { return ['isolation']; }
    return ll_tools_get_main_recording_types();
}

/**
 * Helper: determine if recording is explicitly disabled for a category.
 */
function ll_tools_is_category_recording_disabled($term_id): bool {
    $selected_raw = get_term_meta((int) $term_id, 'll_desired_recording_types', true);
    $selected = array_filter(array_map('sanitize_text_field', (array) $selected_raw));
    return in_array(LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED, $selected, true);
}

/**
 * Helper: get desired recording types for a word (union across its categories)
 */
function ll_tools_get_desired_recording_types_for_word($word_id): array {
    $cats = wp_get_post_terms((int) $word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($cats) || empty($cats)) { return ll_tools_get_uncategorized_desired_recording_types(); }
    $acc = [];
    $has_enabled_category = false;
    $has_disabled_category = false;
    foreach ($cats as $tid) {
        if (ll_tools_is_category_recording_disabled($tid)) {
            $has_disabled_category = true;
            continue;
        }
        $has_enabled_category = true;
        $acc = array_merge($acc, ll_tools_get_desired_recording_types_for_category($tid));
    }
    if (!empty($acc)) { return array_values(array_unique($acc)); }
    if ($has_disabled_category && !$has_enabled_category) { return []; }
    if ($has_enabled_category) { return ll_tools_get_uncategorized_desired_recording_types(); }
    return ll_tools_get_uncategorized_desired_recording_types();
}

/**
 * Helper: find a preferred speaker for a word — a user who recorded all 3 main types
 * Returns user_id or 0 if none.
 */
function ll_tools_get_preferred_speaker_for_word($word_id): int {
    $main = ll_tools_get_main_recording_types();
    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_parent'    => (int) $word_id,
        'post_status'    => ['publish','pending','draft'],
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'fields'         => 'ids',
    ]);
    if (empty($audio_posts)) { return 0; }
    $by_speaker = ll_tools_get_speaker_recording_type_map_from_audio_posts($audio_posts);
    foreach ($by_speaker as $uid => $typeMap) {
        $has_all = !array_diff($main, array_keys($typeMap));
        if ($has_all) { return (int) $uid; }
    }
    return 0;
}

/**
 * Build speaker => recording_type lookup for a list of audio posts or IDs.
 *
 * @param array $audio_posts
 * @return array<int,array<string,bool>>
 */
function ll_tools_get_speaker_recording_type_map_from_audio_posts(array $audio_posts): array {
    $by_speaker = [];

    foreach ($audio_posts as $audio_post_or_id) {
        $audio_post = $audio_post_or_id instanceof WP_Post ? $audio_post_or_id : get_post((int) $audio_post_or_id);
        if (!($audio_post instanceof WP_Post) || $audio_post->post_type !== 'word_audio') {
            continue;
        }

        $speaker = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
        if (!$speaker) {
            $speaker = (int) $audio_post->post_author;
        }
        if (!$speaker) {
            continue;
        }

        $types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        if (is_wp_error($types) || empty($types)) {
            continue;
        }
        foreach ((array) $types as $type_slug) {
            $type_slug = sanitize_key((string) $type_slug);
            if ($type_slug === '') {
                continue;
            }
            $by_speaker[$speaker][$type_slug] = true;
        }
    }

    return $by_speaker;
}

/**
 * Find a preferred speaker from already-loaded word_audio posts for a word.
 *
 * @param array $audio_posts
 * @return int
 */
function ll_tools_get_preferred_speaker_from_audio_posts(array $audio_posts): int {
    if (empty($audio_posts)) {
        return 0;
    }

    $main = ll_tools_get_main_recording_types();
    $by_speaker = ll_tools_get_speaker_recording_type_map_from_audio_posts($audio_posts);
    foreach ($by_speaker as $uid => $typeMap) {
        $has_all = !array_diff($main, array_keys((array) $typeMap));
        if ($has_all) {
            return (int) $uid;
        }
    }

    return 0;
}
/**
 * Saves the "use titles" checkbox for a term.
 *
 * @param int    $term_id Term ID.
 * @param string $taxonomy Taxonomy name.
 */
/**
 * Determines the deepest-level categories for a given post.
 *
 * @param int $post_id The post ID.
 * @return array An array of deepest-level category objects.
 */
function ll_get_deepest_categories($post_id) {
    $categories = wp_get_post_terms($post_id, 'word-category');
    $deepest_categories = [];
    $max_depth = -1;

    foreach ($categories as $category) {
        $depth = ll_get_category_depth($category->term_id);
        if ($depth > $max_depth) {
            $max_depth = $depth;
            $deepest_categories = [$category];
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
 * Determine a sensible default answer option for legacy categories.
 */
function ll_tools_get_category_cache_version($term_id) {
    $version = (int) get_term_meta($term_id, '_ll_wc_cache_version', true);
    if ($version < 1) {
        $version = 1;
        update_term_meta($term_id, '_ll_wc_cache_version', $version);
    }
    return $version;
}

/**
 * Global epoch used by higher-level caches derived from category quiz data.
 */
function ll_tools_get_category_cache_epoch() {
    $epoch = (int) get_option('ll_tools_wc_cache_epoch', 1);
    if ($epoch < 1) {
        $epoch = 1;
        update_option('ll_tools_wc_cache_epoch', $epoch, false);
    }
    return $epoch;
}

function ll_tools_bump_category_cache_epoch() {
    $epoch = ll_tools_get_category_cache_epoch();
    update_option('ll_tools_wc_cache_epoch', $epoch + 1, false);
}

/**
 * Increment the cache version for one or more word-category terms.
 */
function ll_tools_bump_category_cache_version($term_ids) {
    $term_ids = array_map('intval', (array) $term_ids);
    $did_bump_any = false;
    foreach ($term_ids as $term_id) {
        if ($term_id <= 0) {
            continue;
        }
        $current = ll_tools_get_category_cache_version($term_id);
        update_term_meta($term_id, '_ll_wc_cache_version', $current + 1);
        $did_bump_any = true;
    }

    if ($did_bump_any) {
        ll_tools_bump_category_cache_epoch();
    }
}

/**
 * Build a cache key for storing words for a category and quiz configuration.
 */
function ll_tools_get_words_cache_key($term_id, array $wordset_terms, $prompt_type, $option_type, array $flags = []) {
    sort($wordset_terms, SORT_NUMERIC);
    $wordset_key = $wordset_terms ? md5(implode(',', $wordset_terms)) : 'all';
    $version     = $term_id ? ll_tools_get_category_cache_version($term_id) : 1;
    $signature   = md5(json_encode([
        'prompt' => $prompt_type,
        'option' => $option_type,
        'flags'  => $flags,
    ]));

    return "ll_wc_words_{$term_id}_{$wordset_key}_{$signature}_v{$version}";
}

function ll_tools_bump_single_category_cache_version($term_id) {
    ll_tools_bump_category_cache_version([(int) $term_id]);
}
add_action('created_word-category', 'll_tools_bump_single_category_cache_version', 20, 1);
add_action('edited_word-category',  'll_tools_bump_single_category_cache_version', 20, 1);
add_action('delete_word-category',  'll_tools_bump_single_category_cache_version', 20, 1);

function ll_tools_default_option_type_for_category($term, $min_word_count = LL_TOOLS_MIN_WORDS_PER_QUIZ, $wordset_ids = []): string {
    if (!($term instanceof WP_Term)) {
        $term = get_term($term, 'word-category');
    }
    if (!($term instanceof WP_Term)) {
        return 'image';
    }

    $use_titles = get_term_meta($term->term_id, 'use_word_titles_for_audio', true) === '1';
    $term_id = (int) $term->term_id;

    static $resolving_term_ids = [];
    if ($term_id > 0 && !empty($resolving_term_ids[$term_id])) {
        // Avoid recursive default-resolution loops triggered by display-text
        // resolution inside ll_get_words_by_category().
        return $use_titles ? 'text_title' : 'image';
    }
    if ($term_id > 0) {
        $resolving_term_ids[$term_id] = true;
    }

    $base = ['prompt_type' => 'audio', '__skip_quiz_config_merge' => true];
    $cfg_image = array_merge($base, ['option_type' => 'image']);
    $cfg_text_translation = array_merge($base, ['option_type' => 'text_translation']);
    $cfg_text_title = array_merge($base, ['option_type' => 'text_title']);

    $image_count = ll_get_words_by_category_count($term->name, 'image', $wordset_ids, $cfg_image);
    $text_title_count = ll_get_words_by_category_count($term->name, 'text', $wordset_ids, $cfg_text_title);
    $text_translation_count = ll_get_words_by_category_count($term->name, 'text', $wordset_ids, $cfg_text_translation);

    try {
        if ($use_titles && $text_title_count >= $min_word_count) {
            return 'text_title';
        }

        if ($image_count >= $min_word_count && $image_count >= $text_translation_count) {
            return 'image';
        }
        if ($text_translation_count >= $min_word_count) {
            return 'text_translation';
        }

        // Fallback to whichever has more entries (or image if tied/empty)
        if ($text_translation_count > $image_count) {
            return 'text_translation';
        }
        return 'image';
    } finally {
        if ($term_id > 0) {
            unset($resolving_term_ids[$term_id]);
        }
    }
}

/**
 * Resolve a stored audio path/URL to a browser-safe URL for the current site origin.
 *
 * Handles legacy absolute URLs that still point to old local hosts/ports (e.g. Local WP),
 * while leaving truly external URLs untouched.
 */
function ll_tools_resolve_audio_file_url($audio_path): string {
    $audio_path = trim((string) $audio_path);
    if ($audio_path === '') {
        return '';
    }

    if (strpos($audio_path, '//') === 0) {
        $audio_path = (is_ssl() ? 'https:' : 'http:') . $audio_path;
    }

    static $resolved_cache = [];
    if (isset($resolved_cache[$audio_path])) {
        return $resolved_cache[$audio_path];
    }

    // Relative path stored in meta (canonical plugin format).
    if (!preg_match('#^https?://#i', $audio_path)) {
        $resolved_cache[$audio_path] = site_url($audio_path);
        return $resolved_cache[$audio_path];
    }

    $parsed = wp_parse_url($audio_path);
    $path   = is_array($parsed) && !empty($parsed['path']) ? '/' . ltrim((string) $parsed['path'], '/') : '';
    if ($path === '') {
        $resolved_cache[$audio_path] = $audio_path;
        return $resolved_cache[$audio_path];
    }

    $home = wp_parse_url(home_url('/'));
    if (!is_array($home) || empty($home['host'])) {
        $resolved_cache[$audio_path] = $audio_path;
        return $resolved_cache[$audio_path];
    }

    $url_host = strtolower((string) ($parsed['host'] ?? ''));
    $home_host = strtolower((string) $home['host']);
    $url_scheme = strtolower((string) ($parsed['scheme'] ?? 'http'));
    $home_scheme = strtolower((string) ($home['scheme'] ?? 'http'));
    $url_port = isset($parsed['port']) ? (int) $parsed['port'] : (($url_scheme === 'https') ? 443 : 80);
    $home_port = isset($home['port']) ? (int) $home['port'] : (($home_scheme === 'https') ? 443 : 80);

    if ($url_host !== '' && $url_host === $home_host && $url_port === $home_port && $url_scheme === $home_scheme) {
        $resolved_cache[$audio_path] = $audio_path;
        return $resolved_cache[$audio_path];
    }

    // If the path maps to a local file, force current-site origin to avoid cross-origin media requests.
    $local_path = ABSPATH . ltrim($path, '/');
    if (file_exists($local_path) && is_readable($local_path)) {
        $resolved_cache[$audio_path] = home_url($path);
        return $resolved_cache[$audio_path];
    }

    // Fallback: if the URL path is under uploads, prefer current uploads origin.
    $uploads = wp_get_upload_dir();
    if (empty($uploads['error']) && !empty($uploads['baseurl'])) {
        $uploads_base_path = (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        if ($uploads_base_path !== '' && strpos($path, $uploads_base_path) === 0) {
            $relative = ltrim(substr($path, strlen($uploads_base_path)), '/');
            $resolved_cache[$audio_path] = trailingslashit($uploads['baseurl']) . $relative;
            return $resolved_cache[$audio_path];
        }
    }

    $resolved_cache[$audio_path] = $audio_path;
    return $resolved_cache[$audio_path];
}

/**
 * Resolve a stored image URL to a browser-safe URL for the current site origin.
 *
 * Handles cached absolute URLs that point to an old host/port and keeps
 * query arguments for masked image proxy links intact.
 */
function ll_tools_resolve_image_file_url($image_url): string {
    $image_url = trim((string) $image_url);
    if ($image_url === '') {
        return '';
    }

    if (strpos($image_url, '//') === 0) {
        $image_url = (is_ssl() ? 'https:' : 'http:') . $image_url;
    }

    static $resolved_cache = [];
    if (isset($resolved_cache[$image_url])) {
        return $resolved_cache[$image_url];
    }

    // Already relative; keep it as-is.
    if (!preg_match('#^https?://#i', $image_url)) {
        $resolved_cache[$image_url] = $image_url;
        return $resolved_cache[$image_url];
    }

    $parsed = wp_parse_url($image_url);
    $path   = is_array($parsed) && !empty($parsed['path']) ? '/' . ltrim((string) $parsed['path'], '/') : '';
    if ($path === '') {
        $resolved_cache[$image_url] = $image_url;
        return $resolved_cache[$image_url];
    }

    $query = (is_array($parsed) && isset($parsed['query'])) ? (string) $parsed['query'] : '';
    $fragment = (is_array($parsed) && isset($parsed['fragment'])) ? (string) $parsed['fragment'] : '';
    $append_query_fragment = static function (string $base_url) use ($query, $fragment): string {
        if ($query !== '') {
            $base_url .= (strpos($base_url, '?') === false ? '?' : '&') . $query;
        }
        if ($fragment !== '') {
            $base_url .= '#' . $fragment;
        }
        return $base_url;
    };

    $query_args = [];
    if ($query !== '') {
        wp_parse_str($query, $query_args);
    }
    $is_masked_proxy = isset($query_args['lltools-img'], $query_args['lltools-size'], $query_args['lltools-sig']);
    if ($is_masked_proxy) {
        $use_masked_proxy = function_exists('ll_tools_should_use_masked_image_proxy')
            ? ll_tools_should_use_masked_image_proxy()
            : true;
        if (!$use_masked_proxy) {
            $attachment_id = absint($query_args['lltools-img']);
            $proxy_size = isset($query_args['lltools-size'])
                ? sanitize_key((string) $query_args['lltools-size'])
                : 'full';
            if ($proxy_size === '') {
                $proxy_size = 'full';
            }
            if ($attachment_id > 0) {
                $direct_url = wp_get_attachment_image_url($attachment_id, $proxy_size);
                if (!empty($direct_url)) {
                    $resolved_cache[$image_url] = (string) $direct_url;
                    return $resolved_cache[$image_url];
                }
            }
        }
    }

    $home = wp_parse_url(home_url('/'));
    if (!is_array($home) || empty($home['host'])) {
        $resolved_cache[$image_url] = $image_url;
        return $resolved_cache[$image_url];
    }

    $url_host = strtolower((string) ($parsed['host'] ?? ''));
    $home_host = strtolower((string) $home['host']);
    $url_scheme = strtolower((string) ($parsed['scheme'] ?? 'http'));
    $home_scheme = strtolower((string) ($home['scheme'] ?? 'http'));
    $url_port = isset($parsed['port']) ? (int) $parsed['port'] : (($url_scheme === 'https') ? 443 : 80);
    $home_port = isset($home['port']) ? (int) $home['port'] : (($home_scheme === 'https') ? 443 : 80);

    if ($url_host !== '' && $url_host === $home_host && $url_port === $home_port && $url_scheme === $home_scheme) {
        $resolved_cache[$image_url] = $image_url;
        return $resolved_cache[$image_url];
    }
    if ($is_masked_proxy) {
        $resolved_cache[$image_url] = $append_query_fragment(home_url($path));
        return $resolved_cache[$image_url];
    }

    // If the path maps to a local file, force current-site origin.
    $local_path = ABSPATH . ltrim($path, '/');
    if (is_file($local_path) && is_readable($local_path)) {
        $resolved_cache[$image_url] = $append_query_fragment(home_url($path));
        return $resolved_cache[$image_url];
    }

    // Fallback: if the URL path is under uploads, prefer current uploads origin.
    $uploads = wp_get_upload_dir();
    if (empty($uploads['error']) && !empty($uploads['baseurl'])) {
        $uploads_base_path = (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        if ($uploads_base_path !== '' && strpos($path, $uploads_base_path) === 0) {
            $relative = ltrim(substr($path, strlen($uploads_base_path)), '/');
            $rebased = trailingslashit((string) $uploads['baseurl']) . $relative;
            $resolved_cache[$image_url] = $append_query_fragment($rebased);
            return $resolved_cache[$image_url];
        }
    }

    $resolved_cache[$image_url] = $image_url;
    return $resolved_cache[$image_url];
}

/**
 * Normalize media URLs in cached word payload rows.
 *
 * @param array $rows Array of quiz word rows.
 * @return array
 */
function ll_tools_normalize_words_audio_urls(array $rows): array {
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }

        if (!empty($row['audio'])) {
            $rows[$idx]['audio'] = ll_tools_resolve_audio_file_url($row['audio']);
        }

        if (!empty($row['audio_files']) && is_array($row['audio_files'])) {
            foreach ($row['audio_files'] as $aidx => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $url = isset($entry['url']) ? (string) $entry['url'] : '';
                if ($url === '') {
                    continue;
                }
                $rows[$idx]['audio_files'][$aidx]['url'] = ll_tools_resolve_audio_file_url($url);
            }
        }

        if (!empty($row['image'])) {
            $rows[$idx]['image'] = ll_tools_resolve_image_file_url($row['image']);
        }
    }

    return $rows;
}

/**
 * Return an exact count of quiz-eligible words for a category/config without
 * building the full flashcard payload rows.
 *
 * This is primarily used by category list / mode-selection code paths that only
 * need counts to decide eligibility or fallback modes.
 */
function ll_get_words_by_category_count($categoryName, $displayMode = 'image', $wordset_id = null, $quiz_config = []) {
    $term = get_term_by('name', $categoryName, 'word-category');
    $config = $quiz_config;
    $skip_merge = !empty($quiz_config['__skip_quiz_config_merge']);
    if ($term && !is_wp_error($term) && !$skip_merge) {
        $config = array_merge(ll_tools_get_category_quiz_config($term), (array) $quiz_config);
    }

    $use_titles  = !empty($config['use_titles']);
    $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
    $option_type = isset($config['option_type']) ? (string) $config['option_type'] : $displayMode;
    $require_audio = ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type);
    $option_requires_audio = in_array($option_type, ['audio', 'text_audio'], true);
    $require_prompt_image = ($prompt_type === 'image');
    $require_option_image = ($option_type === 'image');
    $wordset_terms = [];
    if (!empty($wordset_id)) {
        $wordset_terms = is_array($wordset_id) ? array_map('intval', $wordset_id) : [(int) $wordset_id];
        $wordset_terms = array_values(array_filter($wordset_terms, static function ($id): bool {
            return $id > 0;
        }));
    }

    $term_id = ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
    $cache_flags = [
        'require_audio'        => $require_audio,
        'require_prompt_image' => $require_prompt_image,
        'require_option_image' => $require_option_image,
        'use_titles'           => $use_titles,
        'term_slug'            => ($term && !is_wp_error($term)) ? (string) $term->slug : '',
        'text_label_schema'    => 3,
        'masked_image_url'     => function_exists('ll_tools_should_use_masked_image_proxy')
            ? ll_tools_should_use_masked_image_proxy()
            : true,
        'include_pos'          => true,
        'include_gender'       => true,
        'include_plurality'    => true,
    ];

    $rows_cache_key = ll_tools_get_words_cache_key($term_id, $wordset_terms, $prompt_type, $option_type, $cache_flags);
    $count_cache_key = 'll_wc_words_count_' . md5($rows_cache_key . '|v1');
    $cache_ttl = 6 * HOUR_IN_SECONDS;
    $count_cache_group = 'll_tools_words_count';

    static $request_cache = [];
    if (array_key_exists($count_cache_key, $request_cache)) {
        return (int) $request_cache[$count_cache_key];
    }

    $cached_count = wp_cache_get($count_cache_key, $count_cache_group);
    if ($cached_count === false) {
        $cached_count = get_transient($count_cache_key);
    }
    if (is_array($cached_count) && isset($cached_count['__ll_words_count_cache_format']) && (int) $cached_count['__ll_words_count_cache_format'] === 1) {
        $count = isset($cached_count['count']) ? max(0, (int) $cached_count['count']) : 0;
        $request_cache[$count_cache_key] = $count;
        return $count;
    }

    // If the full row cache is already warm, derive the count from it.
    $cached_rows = wp_cache_get($rows_cache_key, 'll_tools_words');
    if ($cached_rows === false) {
        $cached_rows = get_transient($rows_cache_key);
    }
    if ($cached_rows !== false) {
        if (
            is_array($cached_rows)
            && isset($cached_rows['__ll_words_cache_format'])
            && (int) $cached_rows['__ll_words_cache_format'] === 2
            && isset($cached_rows['rows'])
            && is_array($cached_rows['rows'])
        ) {
            $count = count($cached_rows['rows']);
        } else {
            $count = is_array($cached_rows) ? count($cached_rows) : 0;
        }

        $payload = [
            '__ll_words_count_cache_format' => 1,
            'count' => (int) $count,
        ];
        $request_cache[$count_cache_key] = (int) $count;
        wp_cache_set($count_cache_key, $payload, $count_cache_group, $cache_ttl);
        set_transient($count_cache_key, $payload, $cache_ttl);
        return (int) $count;
    }

    $category_tax_field = ($term_id > 0) ? 'term_id' : 'name';
    $category_tax_terms = ($term_id > 0) ? [$term_id] : $categoryName;

    $args = [
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => $category_tax_field,
            'terms'    => $category_tax_terms,
        ]],
        'fields'         => 'all',
        'no_found_rows'  => true,
    ];

    if (!empty($wordset_terms)) {
        $args['tax_query'][] = [
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => $wordset_terms,
        ];
        $args['tax_query']['relation'] = 'AND';
    }

    $query = new WP_Query($args);
    $posts = is_array($query->posts) ? $query->posts : [];
    if (empty($posts)) {
        $payload = [
            '__ll_words_count_cache_format' => 1,
            'count' => 0,
        ];
        $request_cache[$count_cache_key] = 0;
        wp_cache_set($count_cache_key, $payload, $count_cache_group, $cache_ttl);
        set_transient($count_cache_key, $payload, $cache_ttl);
        return 0;
    }

    $word_ids = array_values(array_filter(array_map(static function ($post): int {
        return ($post instanceof WP_Post) ? (int) $post->ID : 0;
    }, $posts), static function (int $post_id): bool {
        return $post_id > 0;
    }));

    $has_audio_by_word = [];
    if ($require_audio && !empty($word_ids)) {
        $audio_posts = get_posts([
            'post_type'      => 'word_audio',
            'post_parent__in' => $word_ids,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'suppress_filters' => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        foreach ((array) $audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }
            $parent_id = (int) $audio_post->post_parent;
            if ($parent_id <= 0 || !empty($has_audio_by_word[$parent_id])) {
                continue;
            }
            $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
            if (trim((string) $audio_path) !== '') {
                $has_audio_by_word[$parent_id] = true;
            }
        }
    }

    $needs_option_text = in_array($option_type, ['text_translation', 'text_title', 'text_audio'], true);
    $needs_prompt_text = in_array($prompt_type, ['text_translation', 'text_title'], true);
    $needs_text_values = $needs_option_text || $needs_prompt_text;

    $specific_wrong_owner_map = [];
    $needs_wrong_answer_only_exception_check = ($require_audio && !$option_requires_audio);
    if ($needs_wrong_answer_only_exception_check && function_exists('ll_tools_get_specific_wrong_answer_owner_map')) {
        $specific_wrong_owner_map = ll_tools_get_specific_wrong_answer_owner_map();
    }

    $count = 0;
    foreach ($posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $word_id = (int) $post->ID;
        if ($word_id <= 0) {
            continue;
        }

        $has_audio = !empty($has_audio_by_word[$word_id]);
        if ($require_audio && !$has_audio) {
            $is_specific_wrong_answer_only = false;
            if ($needs_wrong_answer_only_exception_check) {
                $specific_wrong_answer_owner_ids = isset($specific_wrong_owner_map[$word_id]) && is_array($specific_wrong_owner_map[$word_id])
                    ? array_values(array_filter(array_map('intval', $specific_wrong_owner_map[$word_id]), static function ($id): bool {
                        return $id > 0;
                    }))
                    : [];
                $specific_wrong_answer_ids = function_exists('ll_tools_get_word_specific_wrong_answer_ids')
                    ? ll_tools_get_word_specific_wrong_answer_ids($word_id)
                    : [];
                $specific_wrong_answer_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
                    ? ll_tools_get_word_specific_wrong_answer_texts($word_id)
                    : [];
                $is_specific_wrong_answer_only = !empty($specific_wrong_answer_owner_ids)
                    && empty($specific_wrong_answer_ids)
                    && empty($specific_wrong_answer_texts);
            }

            if (!$is_specific_wrong_answer_only || $option_requires_audio) {
                continue;
            }
        }

        if ($require_prompt_image || $require_option_image) {
            $image_id = get_post_thumbnail_id($word_id);
            $image = '';
            if ($image_id) {
                $image_size = apply_filters('ll_tools_quiz_image_size', 'full', $word_id, $term_id, $option_type);
                $image_size = $image_size ? sanitize_key($image_size) : 'full';
                if ($image_size === '') {
                    $image_size = 'full';
                }
                $image = ll_tools_get_masked_image_url($image_id, $image_size);
                if (empty($image)) {
                    $image = wp_get_attachment_image_url($image_id, $image_size) ?: '';
                }
            }

            if (!$image_id || empty($image)) {
                continue;
            }
        }

        $label = '';
        $prompt_label = '';
        if ($needs_text_values) {
            $raw_post_title = html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8');
            $title = $raw_post_title;
            $translation_label = '';

            if (function_exists('ll_tools_word_grid_resolve_display_text')) {
                $display_values = ll_tools_word_grid_resolve_display_text($word_id);
                $word_text = trim((string) ($display_values['word_text'] ?? ''));
                $translation_text = trim((string) ($display_values['translation_text'] ?? ''));

                if ($word_text !== '') {
                    $title = html_entity_decode($word_text, ENT_QUOTES, 'UTF-8');
                }
                if ($translation_text !== '') {
                    $translation_label = html_entity_decode($translation_text, ENT_QUOTES, 'UTF-8');
                }
            } else {
                $word_title_role = sanitize_key((string) get_option('ll_word_title_language_role', 'target'));
                $candidate_keys = ($word_title_role === 'translation')
                    ? ['word_translation', 'translation', 'meaning', 'word_english_meaning']
                    : ['word_english_meaning', 'word_translation', 'translation', 'meaning'];
                $translation = '';
                foreach ($candidate_keys as $key) {
                    $val = trim((string) get_post_meta($word_id, $key, true));
                    if ($val !== '') {
                        $translation = $val;
                        break;
                    }
                }
                $translation_label = ($translation !== '') ? html_entity_decode($translation, ENT_QUOTES, 'UTF-8') : '';

                if ($word_title_role === 'translation' && $translation_label !== '') {
                    $title = $translation_label;
                    $translation_label = $raw_post_title;
                }
            }

            $label = $title;
            if (in_array($option_type, ['text_translation', 'text_audio'], true) && $translation_label !== '') {
                $label = $translation_label;
            }

            $prompt_label = $title;
            if ($prompt_type === 'text_translation' && $translation_label !== '') {
                $prompt_label = $translation_label;
            } elseif ($prompt_type === 'text_title') {
                $prompt_label = $title;
            }
        }

        if ($needs_option_text && $label === '') {
            continue;
        }
        if ($needs_prompt_text && $prompt_label === '') {
            continue;
        }

        $count++;
    }

    $payload = [
        '__ll_words_count_cache_format' => 1,
        'count' => (int) $count,
    ];
    $request_cache[$count_cache_key] = (int) $count;
    wp_cache_set($count_cache_key, $payload, $count_cache_group, $cache_ttl);
    set_transient($count_cache_key, $payload, $cache_ttl);

    return (int) $count;
}

function ll_get_words_by_category($categoryName, $displayMode = 'image', $wordset_id = null, $quiz_config = []) {
    $term = get_term_by('name', $categoryName, 'word-category');
    $config = $quiz_config;
    $skip_merge = !empty($quiz_config['__skip_quiz_config_merge']);
    if ($term && !is_wp_error($term) && !$skip_merge) {
        $config = array_merge(ll_tools_get_category_quiz_config($term), (array) $quiz_config);
    }

    $use_titles  = !empty($config['use_titles']);
    $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
    $option_type = isset($config['option_type']) ? (string) $config['option_type'] : $displayMode;
    $require_audio = ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type);
    $option_requires_audio = in_array($option_type, ['audio', 'text_audio'], true);
    $require_prompt_image = ($prompt_type === 'image');
    $require_option_image = ($option_type === 'image');
    $wordset_terms = [];
    if (!empty($wordset_id)) {
        $wordset_terms = is_array($wordset_id) ? array_map('intval', $wordset_id) : [(int) $wordset_id];
        $wordset_terms = array_filter($wordset_terms, function ($id) { return $id > 0; });
    }

    $term_id = ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
    $cache_flags = [
        'require_audio'        => $require_audio,
        'require_prompt_image' => $require_prompt_image,
        'require_option_image' => $require_option_image,
        'use_titles'           => $use_titles,
        // Include term identity so in-request static cache rows do not bleed across
        // test cases when term IDs are recycled after DB resets.
        'term_slug'            => ($term && !is_wp_error($term)) ? (string) $term->slug : '',
        // Bump when text label source-selection logic changes so stale cached rows are bypassed.
        'text_label_schema'    => 3,
        'masked_image_url'     => function_exists('ll_tools_should_use_masked_image_proxy')
            ? ll_tools_should_use_masked_image_proxy()
            : true,
        'include_pos'          => true,
        'include_gender'       => true,
        'include_plurality'    => true,
    ];
    $cache_key = ll_tools_get_words_cache_key($term_id, $wordset_terms, $prompt_type, $option_type, $cache_flags);
    $cache_ttl = 6 * HOUR_IN_SECONDS;

    static $request_cache = [];
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, 'll_tools_words');
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }
    if ($cached !== false) {
        if (
            is_array($cached)
            && isset($cached['__ll_words_cache_format'])
            && (int) $cached['__ll_words_cache_format'] === 2
            && isset($cached['rows'])
            && is_array($cached['rows'])
        ) {
            $request_cache[$cache_key] = $cached['rows'];
            return $cached['rows'];
        }

        $cached_rows = is_array($cached) ? $cached : [];
        $normalized_cached_rows = ll_tools_normalize_words_audio_urls($cached_rows);
        if ($normalized_cached_rows !== $cached_rows) {
            $cache_payload = [
                '__ll_words_cache_format' => 2,
                'rows' => $normalized_cached_rows,
            ];
            wp_cache_set($cache_key, $cache_payload, 'll_tools_words', $cache_ttl);
            set_transient($cache_key, $cache_payload, $cache_ttl);
        }
        $request_cache[$cache_key] = $normalized_cached_rows;
        return $normalized_cached_rows;
    }

    $category_tax_field = ($term_id > 0) ? 'term_id' : 'name';
    $category_tax_terms = ($term_id > 0) ? [$term_id] : $categoryName;

    $args = [
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => $category_tax_field,
            'terms'    => $category_tax_terms,
        ]],
        'fields'         => 'all',
        'no_found_rows'  => true,
    ];

    if (!empty($wordset_terms)) {
        $args['tax_query'][] = [
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => $wordset_terms,
        ];
        $args['tax_query']['relation'] = 'AND';
    }

    $query = new WP_Query($args);
    $word_ids = array_values(array_filter(array_map(static function ($post): int {
        return ($post instanceof WP_Post) ? (int) $post->ID : 0;
    }, (array) $query->posts), static function (int $post_id): bool {
        return $post_id > 0;
    }));

    $audio_posts_by_word = [];
    if (!empty($word_ids)) {
        $all_audio_posts = get_posts([
            'post_type'      => 'word_audio',
            'post_parent__in' => $word_ids,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'suppress_filters' => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        if (!empty($all_audio_posts)) {
            $audio_ids = array_values(array_filter(array_map(static function ($post): int {
                return ($post instanceof WP_Post) ? (int) $post->ID : 0;
            }, (array) $all_audio_posts), static function (int $post_id): bool {
                return $post_id > 0;
            }));

            if (!empty($audio_ids)) {
                update_postmeta_cache($audio_ids);
                update_object_term_cache($audio_ids, 'word_audio');
            }

            foreach ($all_audio_posts as $audio_post) {
                if (!($audio_post instanceof WP_Post)) {
                    continue;
                }
                $parent_id = (int) $audio_post->post_parent;
                if ($parent_id <= 0) {
                    continue;
                }
                if (!isset($audio_posts_by_word[$parent_id])) {
                    $audio_posts_by_word[$parent_id] = [];
                }
                $audio_posts_by_word[$parent_id][] = $audio_post;
            }
        }
    }

    $words = [];
    $group_maps = [
        'group_map' => [],
        'blocked_map' => [],
    ];
    $rules_wordset_id = (count($wordset_terms) === 1) ? (int) $wordset_terms[0] : 0;
    if ($rules_wordset_id > 0 && $term_id > 0 && function_exists('ll_tools_get_word_option_maps')) {
        $group_maps = ll_tools_get_word_option_maps($rules_wordset_id, $term_id);
    }
    $group_map = $group_maps['group_map'] ?? [];
    $blocked_map = $group_maps['blocked_map'] ?? [];
    $specific_wrong_owner_map = function_exists('ll_tools_get_specific_wrong_answer_owner_map')
        ? ll_tools_get_specific_wrong_answer_owner_map()
        : [];

    foreach ($query->posts as $post) {
        $word_id = $post->ID;
        $image_id = get_post_thumbnail_id($word_id);
        $image_size = apply_filters('ll_tools_quiz_image_size', 'full', $word_id, $term_id, $option_type);
        $image_size = $image_size ? sanitize_key($image_size) : 'full';
        if ($image_size === '') { $image_size = 'full'; }
        $image   = '';
        if ($image_id) {
            $image = ll_tools_get_masked_image_url($image_id, $image_size);
            if (empty($image)) {
                $image = wp_get_attachment_image_url($image_id, $image_size) ?: '';
            }
        }

        $audio_files = [];
        $audio_posts = isset($audio_posts_by_word[$word_id]) && is_array($audio_posts_by_word[$word_id])
            ? $audio_posts_by_word[$word_id]
            : [];

        $preferred_speaker = ll_tools_get_preferred_speaker_from_audio_posts($audio_posts);
        foreach ($audio_posts as $audio_post) {
            $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
            if ($audio_path) {
                $audio_url       = ll_tools_resolve_audio_file_url($audio_path);
                $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
                $speaker_uid     = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
                if (!$speaker_uid) { $speaker_uid = (int) $audio_post->post_author; }
                $audio_files[]   = [
                    'url'            => $audio_url,
                    'recording_type' => !empty($recording_types) ? $recording_types[0] : 'unknown',
                    'speaker_user_id'=> $speaker_uid,
                ];
            }
        }

        $prioritized_audio = ll_get_prioritized_audio($audio_posts, $preferred_speaker);
        $primary_audio = '';
        if ($prioritized_audio) {
            $audio_path = get_post_meta($prioritized_audio->ID, 'audio_file_path', true);
            if ($audio_path) {
                $primary_audio = ll_tools_resolve_audio_file_url($audio_path);
            }
        }

        // Require actual audio to be present for inclusion in quizzes. Do NOT fall back to legacy meta here.
        $has_audio = !empty($primary_audio) || !empty($audio_files);

        $raw_post_title = html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8');

        // Match quiz text labels to the sitewide "word title language role" semantics used by the word grid.
        $title = $raw_post_title;
        $translation_label = '';
        if (function_exists('ll_tools_word_grid_resolve_display_text')) {
            $display_values = ll_tools_word_grid_resolve_display_text($word_id);
            $word_text = trim((string) ($display_values['word_text'] ?? ''));
            $translation_text = trim((string) ($display_values['translation_text'] ?? ''));

            if ($word_text !== '') {
                $title = html_entity_decode($word_text, ENT_QUOTES, 'UTF-8');
            }
            if ($translation_text !== '') {
                $translation_label = html_entity_decode($translation_text, ENT_QUOTES, 'UTF-8');
            }
        } else {
            $word_title_role = sanitize_key((string) get_option('ll_word_title_language_role', 'target'));
            $candidate_keys = ($word_title_role === 'translation')
                ? ['word_translation', 'translation', 'meaning', 'word_english_meaning']
                : ['word_english_meaning', 'word_translation', 'translation', 'meaning'];
            $translation = '';
            foreach ($candidate_keys as $key) {
                $val = trim((string) get_post_meta($word_id, $key, true));
                if ($val !== '') { $translation = $val; break; }
            }
            $translation_label = ($translation !== '') ? html_entity_decode($translation, ENT_QUOTES, 'UTF-8') : '';

            if ($word_title_role === 'translation' && $translation_label !== '') {
                // In translation-title mode, "word title" is the translation/meta side and the opposite is post_title.
                $title = $translation_label;
                $translation_label = $raw_post_title;
            }
        }

        $label = $title;
        $use_translation_label = in_array($option_type, ['text_translation', 'text_audio'], true);
        if ($use_translation_label && $translation_label !== '') {
            $label = $translation_label;
        }

        $prompt_label = $title;
        if ($prompt_type === 'text_translation' && $translation_label !== '') {
            $prompt_label = $translation_label;
        } elseif ($prompt_type === 'text_title') {
            $prompt_label = $title;
        }

        $all_categories  = wp_get_post_terms($word_id, 'word-category', ['fields' => 'names']);
        $wordset_ids_for_word = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
        $wordset_ids_for_word = array_values(array_filter(array_map('intval', (array) $wordset_ids_for_word), function ($id) { return $id > 0; }));
        $part_of_speech = wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'slugs']);
        if (is_wp_error($part_of_speech)) {
            $part_of_speech = [];
        }
        $part_of_speech = array_values(array_filter(array_map('sanitize_key', (array) $part_of_speech)));
        $grammatical_gender = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
        $grammatical_plurality = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
        $similar_word_id = get_post_meta($word_id, '_ll_similar_word_id', true);
        if ($similar_word_id === '' || $similar_word_id === null) {
            $similar_word_id = get_post_meta($word_id, 'similar_word_id', true);
        }

        $group_labels = isset($group_map[$word_id]) && is_array($group_map[$word_id]) ? $group_map[$word_id] : [];
        $option_groups = [];
        if ($term_id > 0 && !empty($group_labels)) {
            foreach ($group_labels as $group_label) {
                $group_label = trim((string) $group_label);
                if ($group_label === '') {
                    continue;
                }
                $option_groups[] = $term_id . ':' . $group_label;
            }
        }

        $specific_wrong_answer_ids = function_exists('ll_tools_get_word_specific_wrong_answer_ids')
            ? ll_tools_get_word_specific_wrong_answer_ids($word_id)
            : [];
        $specific_wrong_answer_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
            ? ll_tools_get_word_specific_wrong_answer_texts($word_id)
            : [];
        $specific_wrong_answer_owner_ids = isset($specific_wrong_owner_map[$word_id]) && is_array($specific_wrong_owner_map[$word_id])
            ? array_values(array_filter(array_map('intval', $specific_wrong_owner_map[$word_id]), function ($id) { return $id > 0; }))
            : [];
        $is_specific_wrong_answer_only = !empty($specific_wrong_answer_owner_ids)
            && empty($specific_wrong_answer_ids)
            && empty($specific_wrong_answer_texts);

        $word_data = [
            'id'              => $word_id,
            'title'           => $title,
            'label'           => $label,
            'prompt_label'    => $prompt_label,
            'specific_wrong_answer_ids' => $specific_wrong_answer_ids,
            'specific_wrong_answer_texts' => $specific_wrong_answer_texts,
            'specific_wrong_answer_owner_ids' => $specific_wrong_answer_owner_ids,
            'is_specific_wrong_answer_only' => $is_specific_wrong_answer_only,
            'audio'           => $primary_audio,
            'audio_files'     => $audio_files,
            'preferred_speaker_user_id' => $preferred_speaker,
            'image'           => $image ?: '',
            'all_categories'  => $all_categories,
            'part_of_speech'  => $part_of_speech,
            'grammatical_gender' => $grammatical_gender,
            'grammatical_plurality' => $grammatical_plurality,
            'similar_word_id' => $similar_word_id ?: '',
            'wordset_ids'     => $wordset_ids_for_word,
            'has_audio'       => $has_audio,
            'has_image'       => ($image_id && !empty($image)),
            'option_groups'   => $option_groups,
            'option_blocked_ids' => isset($blocked_map[$word_id]) ? array_values(array_map('intval', (array) $blocked_map[$word_id])) : [],
        ];

        if (function_exists('ll_tools_protect_maqqef_for_display')) {
            $word_data['title'] = ll_tools_protect_maqqef_for_display((string) $word_data['title']);
            $word_data['label'] = ll_tools_protect_maqqef_for_display((string) $word_data['label']);
            $word_data['prompt_label'] = ll_tools_protect_maqqef_for_display((string) $word_data['prompt_label']);

            if (!empty($word_data['specific_wrong_answer_texts']) && is_array($word_data['specific_wrong_answer_texts'])) {
                $word_data['specific_wrong_answer_texts'] = array_values(array_map(
                    static function ($value): string {
                        return ll_tools_protect_maqqef_for_display((string) $value);
                    },
                    $word_data['specific_wrong_answer_texts']
                ));
            }
        }

        // Enforce required assets based on prompt + option selections
        if ($require_audio && !$has_audio) {
            // Wrong-answer-only words can still be used in audio-prompt quizzes when options are text-only.
            if (!$is_specific_wrong_answer_only || $option_requires_audio) {
                continue;
            }
        }
        if (($require_prompt_image || $require_option_image) && (!$image_id || empty($image))) {
            continue;
        }
        if (in_array($option_type, ['text_translation', 'text_title', 'text_audio'], true) && $label === '') {
            continue;
        }
        if (in_array($prompt_type, ['text_translation', 'text_title'], true) && $prompt_label === '') {
            continue;
        }

        if ($option_type === 'image' && !empty($image)) {
            $words[] = $word_data;
        } else {
            $words[] = $word_data;
        }
    }

    if (!empty($words) && $option_type === 'image' && function_exists('ll_tools_collect_word_image_hashes') && function_exists('ll_tools_find_similar_image_pairs')) {
        $word_ids = array_values(array_unique(array_map(function ($row) {
            return isset($row['id']) ? (int) $row['id'] : 0;
        }, $words)));
        $word_ids = array_values(array_filter($word_ids, function ($id) { return $id > 0; }));
        if (!empty($word_ids)) {
            $hashes = ll_tools_collect_word_image_hashes($word_ids);
            $pairs = ll_tools_find_similar_image_pairs($hashes);
            if (!empty($pairs)) {
                $image_blocked = [];
                foreach ($pairs as $pair) {
                    $a = (int) ($pair['a'] ?? 0);
                    $b = (int) ($pair['b'] ?? 0);
                    if ($a <= 0 || $b <= 0) {
                        continue;
                    }
                    if (!isset($image_blocked[$a])) {
                        $image_blocked[$a] = [];
                    }
                    if (!isset($image_blocked[$b])) {
                        $image_blocked[$b] = [];
                    }
                    $image_blocked[$a][$b] = true;
                    $image_blocked[$b][$a] = true;
                }
                foreach ($words as $idx => $row) {
                    $word_id = isset($row['id']) ? (int) $row['id'] : 0;
                    if ($word_id <= 0 || empty($image_blocked[$word_id])) {
                        continue;
                    }
                    $blocked_ids = isset($row['option_blocked_ids']) && is_array($row['option_blocked_ids'])
                        ? $row['option_blocked_ids']
                        : [];
                    $blocked_ids = array_merge($blocked_ids, array_keys($image_blocked[$word_id]));
                    $blocked_ids = array_values(array_unique(array_filter(array_map('intval', $blocked_ids), function ($id) use ($word_id) {
                        return $id > 0 && $id !== $word_id;
                    })));
                    $words[$idx]['option_blocked_ids'] = $blocked_ids;
                }
            }
        }
    }

    $words = ll_tools_normalize_words_audio_urls($words);
    $request_cache[$cache_key] = $words;
    $cache_payload = [
        '__ll_words_cache_format' => 2,
        'rows' => $words,
    ];
    wp_cache_set($cache_key, $cache_payload, 'll_tools_words', $cache_ttl);
    set_transient($cache_key, $cache_payload, $cache_ttl);

    return $words;
}

/**
 * Check whether a word has at least one child audio post in the given statuses.
 */
function ll_tools_word_has_audio($word_id, $statuses = ['publish']) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return false;
    }

    if (is_string($statuses)) {
        $post_status = $statuses;
    } else {
        $post_status = array_values(array_filter(array_map('sanitize_key', (array) $statuses)));
        if (empty($post_status)) {
            $post_status = ['publish'];
        }
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_parent'    => $word_id,
        'post_status'    => $post_status,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
    ]);

    return !empty($audio_posts);
}

/**
 * Get a default audio URL for a word.
 *
 * Default priority is isolation-first for non-practice flows:
 * isolation > introduction > question > in sentence > any other.
 *
 * Practice prompt audio ordering is handled in the flashcard JS mode layer.
 */
function ll_get_word_audio_url($word_id) {
    // Get all word_audio child posts
    $audio_posts = get_posts([
        'post_type' => 'word_audio',
        'post_parent' => $word_id,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if (!empty($audio_posts)) {
        $prioritized_audio = ll_get_prioritized_audio($audio_posts);
        if ($prioritized_audio) {
            $audio_path = get_post_meta($prioritized_audio->ID, 'audio_file_path', true);
            if ($audio_path) {
                return ll_tools_resolve_audio_file_url($audio_path);
            }
        }
    }

    return '';
}

/**
 * Select the highest priority audio from an array of word_audio posts
 *
 * Default priority is isolation-first for non-practice flows:
 * isolation > introduction > question > in sentence > any other.
 *
 * Practice prompt audio ordering is handled in the flashcard JS mode layer.
 *
 * @param array    $audio_posts Array of word_audio post objects
 * @param int|null $preferred_speaker Preferred speaker ID (optional to avoid duplicate lookup)
 * @return WP_Post|null The highest priority audio post or null
 */
function ll_get_prioritized_audio($audio_posts, ?int $preferred_speaker = null) {
    if (empty($audio_posts)) {
        return null;
    }

    $priority_order = ['isolation', 'introduction', 'question', 'in sentence'];

    // Build a map of recording type => audio posts
    $audio_by_type = [];
    $audio_without_type = [];
    $speakers_by_type = [];

    foreach ($audio_posts as $audio_post) {
        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);

        if (is_wp_error($recording_types) || empty($recording_types)) {
            $audio_without_type[] = $audio_post;
            continue;
        }

        foreach ($recording_types as $type_slug) {
            if (!isset($audio_by_type[$type_slug])) {
                $audio_by_type[$type_slug] = [];
            }
            $audio_by_type[$type_slug][] = $audio_post;
            $speaker_uid = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
            if (!$speaker_uid) { $speaker_uid = (int) $audio_post->post_author; }
            if ($speaker_uid) { $speakers_by_type[$type_slug][$speaker_uid] = true; }
        }
    }

    // Attempt to prefer a speaker who has all main types.
    if ($preferred_speaker === null) {
        $preferred_speaker = 0;
        if (!empty($audio_posts)) {
            $parent_id = (int) (get_post($audio_posts[0])->post_parent ?? 0);
            if ($parent_id) { $preferred_speaker = ll_tools_get_preferred_speaker_for_word($parent_id); }
        }
    } else {
        $preferred_speaker = max(0, (int) $preferred_speaker);
    }

    // Check each priority level in order
    foreach ($priority_order as $type) {
        if (!empty($audio_by_type[$type])) {
            if ($preferred_speaker) {
                foreach ($audio_by_type[$type] as $ap) {
                    $uid = (int) get_post_meta($ap->ID, 'speaker_user_id', true);
                    if (!$uid) { $uid = (int) $ap->post_author; }
                    if ($uid === $preferred_speaker) { return $ap; }
                }
            }
            return $audio_by_type[$type][0];
        }
    }

    // If no priority types found, check for any other typed audio
    foreach ($audio_by_type as $type => $posts) {
        if (!empty($posts)) {
            return $posts[0];
        }
    }

    // Last resort: return first audio without type
    if (!empty($audio_without_type)) {
        return $audio_without_type[0];
    }

    // Fallback: return the first audio post
    return $audio_posts[0];
}

/**
 * Renders a separate "Bulk Add Categories" form at the top of the Word Categories page.
 */
function ll_render_bulk_add_categories_form() {
    $screen = get_current_screen();
    if ('edit-word-category' !== $screen->id) {
        return;
    }

    // Display summary notices after processing
    if (isset($_GET['bulk_added'])) {
        $added  = intval($_GET['bulk_added']);
        $failed = intval($_GET['bulk_failed']);
        if ($added) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html(sprintf(_n('Successfully added %d category.', 'Successfully added %d categories.', $added, 'll-tools-text-domain'), $added))
            );
        }
        if ($failed) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(_n('%d entry failed.', '%d entries failed.', $failed, 'll-tools-text-domain'), $failed))
            );
        }
    }

    $action = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="wrap term-bulk-add-wrap">
        <h2><?php esc_html_e('Bulk Add Categories', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo $action; ?>">
            <?php wp_nonce_field('ll_bulk_add_categories'); ?>
            <input type="hidden" name="action" value="ll_word_category_bulk_add">
            <textarea name="bulk_categories" rows="5" style="width:60%;" placeholder="<?php esc_attr_e('Enter names separated by commas, tabs or new lines…', 'll-tools-text-domain'); ?>"></textarea>
            <p>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Bulk Add Categories', 'll-tools-text-domain'); ?>">
            </p>
        </form>
    </div>
    <?php
}

/**
 * Processes the bulk‑add submission and creates categories.
 */
function ll_process_bulk_add_categories() {
    if (!current_user_can('manage_categories') || !check_admin_referer('ll_bulk_add_categories')) {
        wp_die(__('Permission denied or invalid nonce.', 'll-tools-text-domain'));
    }

    $raw    = isset($_POST['bulk_categories']) ? wp_unslash($_POST['bulk_categories']) : '';
    $names  = preg_split('/[\r\n\t,]+/', $raw);
    $added  = 0;
    $failed = 0;

    foreach ($names as $name) {
        $name = sanitize_text_field(trim($name));
        if ('' === $name || term_exists($name, 'word-category')) {
            $failed++;
            continue;
        }
        $result = wp_insert_term($name, 'word-category');
        if (!is_wp_error($result)) {
            $added++;
        } else {
            $failed++;
        }
    }

    $redirect = add_query_arg(
        [
            'taxonomy'    => 'word-category',
            'bulk_added'  => $added,
            'bulk_failed' => $failed,
        ],
        admin_url('edit-tags.php')
    );
    wp_safe_redirect($redirect);
    exit;
}

/**
 * Apply a natural (numeric-aware) name sort whenever "word-category" terms are fetched.
 *
 * @param array           $terms       Array of results from get_terms (may be WP_Term[], strings, ints, or maps).
 * @param string|string[] $taxonomies  The taxonomy slug or array of slugs.
 * @param array           $args        The get_terms() arguments.
 * @return array                      Possibly sorted array, or original if not applicable.
 */
function ll_tools_nat_sort_word_category_terms( $terms, $taxonomies, $args ) {
    // Only touch our taxonomy.
    $is_word_cat = ( is_array( $taxonomies ) )
        ? in_array( 'word-category', $taxonomies, true )
        : ( $taxonomies === 'word-category' );

    if ( ! $is_word_cat || ! is_array( $terms ) || empty( $terms ) ) {
        return $terms;
    }

    // If the caller did NOT request full objects, don't access ->name.
    // Common values: 'all' (default/objects), 'ids', 'id=>parent', 'names', 'id=>name'
    $fields = isset( $args['fields'] ) ? $args['fields'] : '';

    // Handle string-only responses safely.
    if ( $fields === 'names' ) {
        uasort( $terms, static function ( $a, $b ) {
            if ( function_exists( 'll_tools_locale_compare_strings' ) ) {
                return ll_tools_locale_compare_strings( (string) $a, (string) $b );
            }
            return strnatcasecmp( (string) $a, (string) $b );
        } );
        return $terms;
    }

    // Handle associative map of id => name.
    if ( $fields === 'id=>name' ) {
        uasort( $terms, static function( $a, $b ) {
            if ( function_exists( 'll_tools_locale_compare_strings' ) ) {
                return ll_tools_locale_compare_strings( (string) $a, (string) $b );
            }
            return strnatcasecmp( (string) $a, (string) $b );
        } );
        return $terms;
    }

    // For ids or id=>parent or anything else non-object, do nothing (avoid warnings).
    if ( $fields && $fields !== 'all' ) {
        return $terms;
    }

    // From here on, we expect WP_Term objects.
    $first = reset( $terms );
    if ( ! is_object( $first ) || ! isset( $first->name ) ) {
        return $terms;
    }

    usort( $terms, static function( $a, $b ) {
        $an = isset( $a->name ) ? (string) $a->name : '';
        $bn = isset( $b->name ) ? (string) $b->name : '';
        if ( function_exists( 'll_tools_locale_compare_strings' ) ) {
            return ll_tools_locale_compare_strings( $an, $bn );
        }
        return strnatcasecmp( $an, $bn );
    } );

    return $terms;
}

/**
 * Renders a scrollable category‐checkbox list (with post counts) for the given post type.
 *
 * @param string $post_type Post type slug ('words' or 'word_images').
 */
function ll_render_category_selection_field( $post_type ) {
    echo '<div style="max-height:200px; overflow-y:auto; border:1px solid #ccc; padding:5px;">';
    ll_display_categories_checklist( 'word-category', $post_type );
    echo '</div>';
}

/**
 * Recursively outputs category checkboxes, indenting child terms and showing a per–post_type count.
 *
 * @param string $taxonomy  Taxonomy slug (always 'word-category').
 * @param string $post_type Post type to count (e.g. 'words' or 'word_images').
 * @param int    $parent    Parent term ID for recursion.
 * @param int    $level     Depth level for indentation.
 */
function ll_display_categories_checklist( $taxonomy, $post_type, $parent = 0, $level = 0 ) {
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => $parent,
    ]);
    if ( is_wp_error( $terms ) ) {
        return;
    }

    foreach ( $terms as $term ) {
        // Count posts of this type in this term
        $q = new WP_Query([
            'post_type'      => $post_type,
            'tax_query'      => [[
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term->term_id,
            ]],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);
        $count = $q->found_posts;

        $indent = str_repeat( '&nbsp;&nbsp;&nbsp;', $level );
        printf(
            '%s<input type="checkbox" name="ll_word_categories[]" value="%d" data-parent-id="%d"> <label>%s (%d)</label><br>',
            $indent,
            esc_attr( $term->term_id ),
            esc_attr( $term->parent ),
            esc_html( $term->name ),
            intval( $count )
        );

        // Recurse into children
        ll_display_categories_checklist( $taxonomy, $post_type, $term->term_id, $level + 1 );
    }
}

/**
 * Determines if a category can generate a valid quiz.
 *
 * @param WP_Term|int $category The category term object or term ID.
 * @param int $min_word_count The minimum number of words required.
 * @param array|int $wordset_ids Optional wordset term IDs to scope counts.
 * @return bool True if the category can generate a quiz, false otherwise.
 */
function ll_can_category_generate_quiz($category, $min_word_count = 5, $wordset_ids = []) {
    // Get the term object if we received an ID
    if (is_numeric($category)) {
        $term = get_term($category, 'word-category');
        if (!$term || is_wp_error($term)) {
            return false;
        }
    } else {
        $term = $category;
    }

    // Never generate quizzes for the default "uncategorized" bucket.
    if (isset($term->slug) && $term->slug === 'uncategorized') {
        return false;
    }

    $term_id = isset($term->term_id) ? (int) $term->term_id : 0;
    $wordset_key_parts = array_values(array_filter(array_map('intval', (array) $wordset_ids), static function ($id): bool {
        return $id > 0;
    }));
    sort($wordset_key_parts, SORT_NUMERIC);

    $category_version = ($term_id > 0 && function_exists('ll_tools_get_category_cache_version'))
        ? (int) ll_tools_get_category_cache_version($term_id)
        : 1;
    if ($category_version < 1) {
        $category_version = 1;
    }

    static $request_cache = [];
    $request_cache_key = md5(wp_json_encode([
        'term_id' => $term_id,
        'term_slug' => isset($term->slug) ? (string) $term->slug : '',
        'min' => (int) $min_word_count,
        'wordsets' => $wordset_key_parts,
        'v' => $category_version,
    ]));
    if (array_key_exists($request_cache_key, $request_cache)) {
        return (bool) $request_cache[$request_cache_key];
    }

    $persistent_cache_key = 'll_can_quiz_' . md5(wp_json_encode([
        'term_id' => $term_id,
        'term_slug' => isset($term->slug) ? (string) $term->slug : '',
        'min' => (int) $min_word_count,
        'wordsets' => $wordset_key_parts,
        'v' => $category_version,
    ]));
    $persistent_cache_group = 'll_tools_quiz_category';
    $persistent_cached = wp_cache_get($persistent_cache_key, $persistent_cache_group);
    if ($persistent_cached === false) {
        $persistent_cached = get_transient($persistent_cache_key);
    }
    if (is_array($persistent_cached) && array_key_exists('can_generate', $persistent_cached)) {
        $result = !empty($persistent_cached['can_generate']);
        $request_cache[$request_cache_key] = $result;
        return $result;
    }

    $config = ll_tools_get_category_quiz_config($term);
    $option_type = isset($config['option_type']) ? (string) $config['option_type'] : 'image';
    if ($option_type === '') {
        $option_type = ll_tools_default_option_type_for_category($term, $min_word_count, $wordset_ids);
    }

    $primary_count = ll_get_words_by_category_count($term->name, $option_type, $wordset_ids, $config);
    if ($primary_count >= $min_word_count) {
        $request_cache[$request_cache_key] = true;
        $payload = ['can_generate' => true];
        wp_cache_set($persistent_cache_key, $payload, $persistent_cache_group, HOUR_IN_SECONDS);
        set_transient($persistent_cache_key, $payload, HOUR_IN_SECONDS);
        return true;
    }

    // Graceful fallback: if audio-heavy options don't have enough data, try text options
    if (in_array($option_type, ['audio', 'text_audio'], true)) {
        $fallback_config = $config;
        $fallback_config['option_type'] = 'text_translation';
        $text_count = ll_get_words_by_category_count($term->name, 'text', $wordset_ids, $fallback_config);
        if ($text_count >= $min_word_count) {
            $request_cache[$request_cache_key] = true;
            $payload = ['can_generate' => true];
            wp_cache_set($persistent_cache_key, $payload, $persistent_cache_group, HOUR_IN_SECONDS);
            set_transient($persistent_cache_key, $payload, HOUR_IN_SECONDS);
            return true;
        }
    }

    $request_cache[$request_cache_key] = false;
    $payload = ['can_generate' => false];
    wp_cache_set($persistent_cache_key, $payload, $persistent_cache_group, HOUR_IN_SECONDS);
    set_transient($persistent_cache_key, $payload, HOUR_IN_SECONDS);
    return false;
}

/**
 * SHARED BULK EDIT FUNCTIONS FOR WORD-CATEGORY TAXONOMY
 * Used by both 'words' and 'word_images' post types
 */

/**
 * Enqueue bulk edit script for a specific post type
 *
 * @param string $post_type The post type slug ('words' or 'word_images')
 * @param string $script_handle The script handle
 * @param string $script_path Relative path to the JS file
 * @param string $ajax_action AJAX action used to fetch common categories
 */
function ll_enqueue_bulk_category_edit_script($post_type, $script_handle, $script_path, $ajax_action = '') {
    global $pagenow, $typenow;

    if ($pagenow !== 'edit.php' || $typenow !== $post_type) {
        return;
    }

    if ($ajax_action === '') {
        $ajax_action = ($post_type === 'word_images')
            ? 'll_word_images_get_common_categories'
            : 'll_words_get_common_categories';
    }

    ll_enqueue_asset_by_timestamp($script_path, $script_handle, ['jquery', 'inline-edit-post'], true);

    wp_localize_script($script_handle, 'llBulkEditData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_bulk_category_edit_' . $post_type),
        'postType' => $post_type,
        'actionName' => sanitize_key($ajax_action),
    ]);
}

/**
 * AJAX handler to get common categories for selected posts
 *
 * @param string $post_type The post type to check ('words' or 'word_images')
 */
function ll_get_common_categories_for_post_type($post_type) {
    check_ajax_referer('ll_bulk_category_edit_' . $post_type, 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    $post_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), function ($post_id) {
        return $post_id > 0;
    })));

    if (empty($post_ids)) {
        wp_send_json_error(__('No posts selected', 'll-tools-text-domain'));
    }

    $common = ll_tools_get_common_word_category_ids_for_posts($post_ids, (string) $post_type);
    wp_send_json_success(['common' => array_values($common)]);
}

/**
 * Return word-category IDs common to all eligible posts in the given set.
 * A post is eligible when it exists, matches the requested post type, and
 * the current user can edit it.
 *
 * @param int[]  $post_ids
 * @param string $post_type
 * @return int[]
 */
function ll_tools_get_common_word_category_ids_for_posts(array $post_ids, string $post_type): array {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), function ($post_id) {
        return $post_id > 0;
    })));
    if (empty($post_ids) || $post_type === '') {
        return [];
    }

    $all_categories = [];

    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== $post_type) {
            continue;
        }
        if (!current_user_can('edit_post', $post_id)) {
            continue;
        }

        $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($terms)) {
            continue;
        }

        $all_categories[] = array_values(array_unique(array_filter(array_map('intval', (array) $terms), function ($term_id) {
            return $term_id > 0;
        })));
    }

    if (empty($all_categories)) {
        return [];
    }

    $common = array_shift($all_categories);
    foreach ($all_categories as $post_categories) {
        $common = array_values(array_intersect($common, $post_categories));
        if (empty($common)) {
            break;
        }
    }

    sort($common, SORT_NUMERIC);
    return $common;
}

/**
 * Handle bulk edit category removal for a specific post type
 *
 * @param int $post_id The post ID being edited
 * @param string $post_type The post type to handle ('words' or 'word_images')
 */
function ll_handle_bulk_category_edit($post_id, $post_type) {
    // Only run if this is part of a bulk edit
    if (!isset($_REQUEST['bulk_edit'])) {
        return;
    }

    // Only for specified post type
    $post = get_post($post_id);
    if (!$post || $post->post_type !== $post_type) {
        return;
    }

    // Security check
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check if we have categories to remove
    if (!isset($_REQUEST['ll_bulk_categories_to_remove']) || empty($_REQUEST['ll_bulk_categories_to_remove'])) {
        return;
    }

    $categories_to_remove = array_map('intval', (array)$_REQUEST['ll_bulk_categories_to_remove']);

    if (empty($categories_to_remove)) {
        return;
    }

    // Get current categories AFTER WordPress has processed the bulk edit
    $current_terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);

    if (is_wp_error($current_terms)) {
        return;
    }

    // Remove the specified categories
    $new_terms = array_diff($current_terms, $categories_to_remove);

    // Only update if something changed
    if (count($new_terms) !== count($current_terms)) {
        wp_set_object_terms($post_id, array_values($new_terms), 'word-category', false);
    }
}
