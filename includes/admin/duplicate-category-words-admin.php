<?php
/**
 * Bulk duplicate words from one category into another category in the same word set.
 *
 * - Copies words (title/content/meta/taxonomies/thumbnail) using existing split-word clone helpers.
 * - Does not copy word_audio children.
 * - Applies optional grammatical overrides in bulk.
 * - Ensures source/new words are linked to one dictionary entry.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE')) {
    define('LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE', '__no_change__');
}
if (!defined('LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR')) {
    define('LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR', '__clear__');
}

add_action('admin_menu', 'll_tools_register_duplicate_category_words_admin_page');
function ll_tools_register_duplicate_category_words_admin_page() {
    add_submenu_page(
        'edit.php?post_type=words',
        __('Duplicate Category Words', 'll-tools-text-domain'),
        __('Duplicate Category', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-tools-duplicate-category-words',
        'll_tools_render_duplicate_category_words_admin_page'
    );
}

/**
 * Add "Duplicate" action to word-category rows.
 *
 * @param array $actions Existing row actions.
 * @param mixed $term    Current term object.
 * @return array
 */
function ll_tools_add_word_category_duplicate_row_action($actions, $term) {
    if (!is_admin()) {
        return $actions;
    }
    if (!current_user_can('view_ll_tools')) {
        return $actions;
    }
    if (!($term instanceof WP_Term) || $term->taxonomy !== 'word-category') {
        return $actions;
    }

    $url = ll_tools_get_duplicate_category_words_page_url([
        'll_source_category_id' => (int) $term->term_id,
    ]);
    $link = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicate', 'll-tools-text-domain') . '</a>';

    if (!is_array($actions)) {
        return ['ll_tools_duplicate' => $link];
    }

    $new_actions = [];
    $inserted = false;
    foreach ($actions as $key => $value) {
        $new_actions[$key] = $value;
        if ($key === 'edit') {
            $new_actions['ll_tools_duplicate'] = $link;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $new_actions['ll_tools_duplicate'] = $link;
    }

    return $new_actions;
}
add_filter('word-category_row_actions', 'll_tools_add_word_category_duplicate_row_action', 10, 2);

/**
 * Build admin page URL for duplicate-category tool.
 *
 * @param array $args Optional additional query args.
 * @return string
 */
function ll_tools_get_duplicate_category_words_page_url(array $args = []): string {
    $base = add_query_arg(
        [
            'post_type' => 'words',
            'page'      => 'll-tools-duplicate-category-words',
        ],
        admin_url('edit.php')
    );

    if (!empty($args)) {
        $base = add_query_arg($args, $base);
    }

    return $base;
}

/**
 * Resolve human-readable messages for redirect error codes.
 *
 * @param string $code Error code.
 * @return string
 */
function ll_tools_duplicate_category_words_get_error_message($code): string {
    $messages = [
        'nonce'                   => __('Security check failed. Please try again.', 'll-tools-text-domain'),
        'invalid_wordset'         => __('Select a valid word set you can access.', 'll-tools-text-domain'),
        'invalid_source_category' => __('Select a valid source category.', 'll-tools-text-domain'),
        'invalid_target_mode'     => __('Select whether to create a new category or use an existing one.', 'll-tools-text-domain'),
        'invalid_target_category' => __('Select a valid target category.', 'll-tools-text-domain'),
        'missing_new_category_name' => __('Enter a name for the new target category.', 'll-tools-text-domain'),
        'new_category_exists'     => __('A category with that name already exists. Use existing-category mode or choose a different name.', 'll-tools-text-domain'),
        'create_target_category_failed' => __('Could not create the target category. Please try a different name or slug.', 'll-tools-text-domain'),
        'same_category'           => __('Source and target categories must be different.', 'll-tools-text-domain'),
        'invalid_gender'          => __('The selected grammatical gender override is invalid for this word set.', 'll-tools-text-domain'),
        'invalid_plurality'       => __('The selected plurality override is invalid for this word set.', 'll-tools-text-domain'),
        'invalid_verb_tense'      => __('The selected verb tense override is invalid for this word set.', 'll-tools-text-domain'),
        'invalid_verb_mood'       => __('The selected verb mood override is invalid for this word set.', 'll-tools-text-domain'),
        'no_words'                => __('No words were found for the selected source category and word set.', 'll-tools-text-domain'),
    ];

    return isset($messages[$code]) ? (string) $messages[$code] : '';
}

/**
 * Check if the current user can operate on this word set.
 *
 * @param int $wordset_id Word set term ID.
 * @return bool
 */
function ll_tools_duplicate_category_words_user_can_access_wordset($wordset_id): bool {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $term = get_term($wordset_id, 'wordset');
    if (!$term || is_wp_error($term)) {
        return false;
    }

    $manager_user_id = (int) get_term_meta($wordset_id, 'manager_user_id', true);
    if ($manager_user_id <= 0) {
        return true;
    }

    return ((int) get_current_user_id()) === $manager_user_id;
}

/**
 * List word sets available to the current user.
 *
 * @return WP_Term[]
 */
function ll_tools_duplicate_category_words_get_accessible_wordsets(): array {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $result = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }
        if (!ll_tools_duplicate_category_words_user_can_access_wordset((int) $term->term_id)) {
            continue;
        }
        $result[] = $term;
    }

    return $result;
}

/**
 * List categories that currently have words in a given word set.
 *
 * @param int $wordset_id Word set term ID.
 * @return WP_Term[]
 */
function ll_tools_duplicate_category_words_get_source_categories_for_wordset($wordset_id): array {
    global $wpdb;

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

    $query_args = array_merge(
        ['words'],
        $statuses,
        ['wordset', $wordset_id, 'word-category']
    );

    $sql = $wpdb->prepare(
        "
        SELECT DISTINCT tt_cat.term_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type = %s
          AND p.post_status IN ({$status_placeholders})
          AND tt_ws.taxonomy = %s
          AND tt_ws.term_id = %d
          AND tt_cat.taxonomy = %s
        ",
        $query_args
    );

    $term_ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($sql)), function ($id) {
        return $id > 0;
    }));
    if (empty($term_ids)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $term_ids,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    return array_values(array_filter($terms, function ($term) {
        return $term instanceof WP_Term;
    }));
}

/**
 * Grammar feature and option settings for a word set.
 *
 * @param int $wordset_id Word set term ID.
 * @return array{
 *   gender_enabled:bool,gender_options:string[],
 *   plurality_enabled:bool,plurality_options:string[],
 *   verb_tense_enabled:bool,verb_tense_options:string[],
 *   verb_mood_enabled:bool,verb_mood_options:string[]
 * }
 */
function ll_tools_duplicate_category_words_get_grammar_config($wordset_id): array {
    $wordset_id = (int) $wordset_id;

    $gender_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? (bool) ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $plurality_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality'))
        ? (bool) ll_tools_wordset_has_plurality($wordset_id)
        : false;
    $verb_tense_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense'))
        ? (bool) ll_tools_wordset_has_verb_tense($wordset_id)
        : false;
    $verb_mood_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood'))
        ? (bool) ll_tools_wordset_has_verb_mood($wordset_id)
        : false;

    // Read options regardless of enabled state so users can enable and set values in one step.
    $gender_options = function_exists('ll_tools_wordset_get_gender_options')
        ? array_values(array_filter(array_map('strval', ll_tools_wordset_get_gender_options($wordset_id))))
        : [];
    $plurality_options = function_exists('ll_tools_wordset_get_plurality_options')
        ? array_values(array_filter(array_map('strval', ll_tools_wordset_get_plurality_options($wordset_id))))
        : [];
    $verb_tense_options = function_exists('ll_tools_wordset_get_verb_tense_options')
        ? array_values(array_filter(array_map('strval', ll_tools_wordset_get_verb_tense_options($wordset_id))))
        : [];
    $verb_mood_options = function_exists('ll_tools_wordset_get_verb_mood_options')
        ? array_values(array_filter(array_map('strval', ll_tools_wordset_get_verb_mood_options($wordset_id))))
        : [];

    return [
        'gender_enabled'    => $gender_enabled,
        'gender_options'    => $gender_options,
        'plurality_enabled' => $plurality_enabled,
        'plurality_options' => $plurality_options,
        'verb_tense_enabled'=> $verb_tense_enabled,
        'verb_tense_options'=> $verb_tense_options,
        'verb_mood_enabled' => $verb_mood_enabled,
        'verb_mood_options' => $verb_mood_options,
    ];
}

/**
 * Copy key source-category settings to a newly created target category.
 *
 * @param int  $source_category_id Source category term ID.
 * @param int  $target_category_id Target category term ID.
 * @param bool $copy_translation   Whether to copy translation meta.
 * @return void
 */
function ll_tools_duplicate_category_words_copy_source_category_settings($source_category_id, $target_category_id, $copy_translation = true): void {
    $source_category_id = (int) $source_category_id;
    $target_category_id = (int) $target_category_id;
    if ($source_category_id <= 0 || $target_category_id <= 0) {
        return;
    }

    $meta_keys = [
        'll_quiz_prompt_type',
        'll_quiz_option_type',
        'use_word_titles_for_audio',
        'll_desired_recording_types',
    ];

    if ($copy_translation) {
        $meta_keys[] = 'term_translation';
    }

    foreach ($meta_keys as $meta_key) {
        $value = get_term_meta($source_category_id, $meta_key, true);
        if ($value === '' || $value === null || $value === []) {
            delete_term_meta($target_category_id, $meta_key);
            continue;
        }
        update_term_meta($target_category_id, $meta_key, $value);
    }
}

/**
 * Enable word-set-level grammar toggles selected on the duplicate page.
 *
 * @param int   $wordset_id Word set term ID.
 * @param array $enable     Map of booleans keyed by feature.
 * @return void
 */
function ll_tools_duplicate_category_words_enable_wordset_flags($wordset_id, array $enable): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    if (!empty($enable['gender'])) {
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
    }
    if (!empty($enable['plurality'])) {
        update_term_meta($wordset_id, 'll_wordset_has_plurality', 1);
    }
    if (!empty($enable['verb_tense'])) {
        update_term_meta($wordset_id, 'll_wordset_has_verb_tense', 1);
    }
    if (!empty($enable['verb_mood'])) {
        update_term_meta($wordset_id, 'll_wordset_has_verb_mood', 1);
    }
}

/**
 * Ensure the image post(s) tied to a word thumbnail also include the target category.
 *
 * This keeps recorder category discovery consistent for duplicated categories.
 *
 * @param int $word_id            Word post ID.
 * @param int $target_category_id Target category term ID.
 * @return void
 */
function ll_tools_duplicate_category_words_sync_word_image_category($word_id, $target_category_id): void {
    $word_id = (int) $word_id;
    $target_category_id = (int) $target_category_id;
    if ($word_id <= 0 || $target_category_id <= 0) {
        return;
    }

    $thumbnail_id = (int) get_post_thumbnail_id($word_id);
    if ($thumbnail_id <= 0) {
        return;
    }

    $image_post_ids = get_posts([
        'post_type'      => 'word_images',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'   => '_thumbnail_id',
            'value' => $thumbnail_id,
        ]],
    ]);
    if (empty($image_post_ids)) {
        return;
    }

    foreach ($image_post_ids as $image_post_id) {
        $image_post_id = (int) $image_post_id;
        if ($image_post_id <= 0) {
            continue;
        }
        wp_set_post_terms($image_post_id, [$target_category_id], 'word-category', true);
    }
}

/**
 * Normalize override input against allowed options.
 *
 * @param string   $raw             Submitted value.
 * @param string[] $allowed_options Allowed values.
 * @return array{valid:bool,value:string}
 */
function ll_tools_duplicate_category_words_normalize_override_value($raw, array $allowed_options): array {
    $value = trim((string) $raw);
    if ($value === '' || $value === LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE) {
        return ['valid' => true, 'value' => LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE];
    }
    if ($value === LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
        return ['valid' => true, 'value' => LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR];
    }

    foreach ($allowed_options as $option) {
        $option = trim((string) $option);
        if ($option === '') {
            continue;
        }
        if (strcasecmp($option, $value) === 0) {
            return ['valid' => true, 'value' => $option];
        }
    }

    return ['valid' => false, 'value' => ''];
}

/**
 * Render one override <select>.
 *
 * @param string   $field_name  Input name.
 * @param string   $field_id    Input id.
 * @param string   $value       Current value.
 * @param string[] $options     Allowed options.
 * @param string   $description Optional description.
 * @return void
 */
function ll_tools_duplicate_category_words_render_override_select($field_name, $field_id, $value, array $options, $description = ''): void {
    $value = trim((string) $value);
    if ($value === '') {
        $value = LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE;
    }
    ?>
    <select name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_id); ?>" class="regular-text">
        <option value="<?php echo esc_attr(LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE); ?>" <?php selected($value, LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE); ?>>
            <?php esc_html_e('No change', 'll-tools-text-domain'); ?>
        </option>
        <option value="<?php echo esc_attr(LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR); ?>" <?php selected($value, LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR); ?>>
            <?php esc_html_e('Clear value', 'll-tools-text-domain'); ?>
        </option>
        <?php foreach ($options as $option) : ?>
            <?php $option = trim((string) $option); ?>
            <?php if ($option === '') { continue; } ?>
            <option value="<?php echo esc_attr($option); ?>" <?php selected(strtolower($value), strtolower($option)); ?>>
                <?php echo esc_html($option); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($description !== '') : ?>
        <p class="description"><?php echo esc_html($description); ?></p>
    <?php endif; ?>
    <?php
}

/**
 * Apply selected overrides to a cloned word.
 *
 * @param int   $word_id    Target word ID.
 * @param array $overrides  Override map.
 * @return void
 */
function ll_tools_duplicate_category_words_apply_overrides_to_word($word_id, array $overrides): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return;
    }

    $meta_fields = [
        'll_grammatical_gender',
        'll_grammatical_plurality',
        'll_verb_tense',
        'll_verb_mood',
    ];

    foreach ($meta_fields as $meta_key) {
        $override = trim((string) ($overrides[$meta_key] ?? LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE));
        if ($override === '' || $override === LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE) {
            continue;
        }
        if ($override === LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
            delete_post_meta($word_id, $meta_key);
            continue;
        }
        update_post_meta($word_id, $meta_key, $override);
    }
}

/**
 * Ensure source and cloned words share one dictionary entry.
 *
 * @param int $source_word_id Source word ID.
 * @param int $new_word_id    New cloned word ID.
 * @return array{entry_created:bool,link_error:bool}
 */
function ll_tools_duplicate_category_words_link_dictionary_entry($source_word_id, $new_word_id): array {
    $source_word_id = (int) $source_word_id;
    $new_word_id = (int) $new_word_id;
    if ($source_word_id <= 0 || $new_word_id <= 0) {
        return ['entry_created' => false, 'link_error' => true];
    }
    if (!function_exists('ll_tools_assign_dictionary_entry_to_word')
        || !function_exists('ll_tools_get_word_dictionary_entry_id')) {
        return ['entry_created' => false, 'link_error' => true];
    }

    $entry_created = false;
    $entry_id = (int) ll_tools_get_word_dictionary_entry_id($source_word_id);

    if ($entry_id <= 0) {
        $entry_title = trim((string) get_the_title($source_word_id));
        if ($entry_title === '') {
            $entry_title = sprintf(
                /* translators: %d: word post ID */
                __('Dictionary Entry %d', 'll-tools-text-domain'),
                $source_word_id
            );
        }

        $source_result = ll_tools_assign_dictionary_entry_to_word($source_word_id, 0, $entry_title);
        if (is_wp_error($source_result)) {
            return ['entry_created' => false, 'link_error' => true];
        }

        $entry_id = isset($source_result['entry_id']) ? (int) $source_result['entry_id'] : 0;
        $entry_created = !empty($source_result['created']);
    }

    if ($entry_id <= 0) {
        return ['entry_created' => $entry_created, 'link_error' => true];
    }

    $new_result = ll_tools_assign_dictionary_entry_to_word($new_word_id, $entry_id, '');
    if (is_wp_error($new_result)) {
        return ['entry_created' => $entry_created, 'link_error' => true];
    }

    return ['entry_created' => $entry_created, 'link_error' => false];
}

/**
 * Render admin page.
 *
 * @return void
 */
function ll_tools_render_duplicate_category_words_admin_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'll-tools-text-domain'), 403);
    }

    $wordsets = ll_tools_duplicate_category_words_get_accessible_wordsets();
    $selected_wordset_id = isset($_GET['ll_wordset_id']) ? absint($_GET['ll_wordset_id']) : 0;
    if ($selected_wordset_id <= 0 && count($wordsets) === 1) {
        $selected_wordset_id = (int) $wordsets[0]->term_id;
    }
    if ($selected_wordset_id > 0 && !ll_tools_duplicate_category_words_user_can_access_wordset($selected_wordset_id)) {
        $selected_wordset_id = 0;
    }

    $source_categories = $selected_wordset_id > 0
        ? ll_tools_duplicate_category_words_get_source_categories_for_wordset($selected_wordset_id)
        : [];
    $target_categories = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($target_categories)) {
        $target_categories = [];
    }

    $grammar_config = ll_tools_duplicate_category_words_get_grammar_config($selected_wordset_id);

    $source_category_prefill = isset($_GET['ll_source_category_id']) ? absint($_GET['ll_source_category_id']) : 0;
    $target_category_prefill = isset($_GET['ll_target_category_id']) ? absint($_GET['ll_target_category_id']) : 0;
    $target_mode_prefill = isset($_GET['ll_target_mode']) ? sanitize_key((string) $_GET['ll_target_mode']) : 'new';
    if (!in_array($target_mode_prefill, ['new', 'existing'], true)) {
        $target_mode_prefill = 'new';
    }
    $new_category_name_prefill = isset($_GET['ll_new_category_name'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_new_category_name']))
        : '';
    $new_category_slug_prefill = isset($_GET['ll_new_category_slug'])
        ? sanitize_title(wp_unslash((string) $_GET['ll_new_category_slug']))
        : '';
    $new_category_translation_prefill = isset($_GET['ll_new_category_translation'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_new_category_translation']))
        : '';
    $copy_source_settings_prefill = !isset($_GET['ll_copy_source_settings']) || (string) $_GET['ll_copy_source_settings'] !== '0';
    $enable_gender_prefill = isset($_GET['ll_enable_gender']) && (string) $_GET['ll_enable_gender'] === '1';
    $enable_plurality_prefill = isset($_GET['ll_enable_plurality']) && (string) $_GET['ll_enable_plurality'] === '1';
    $enable_verb_tense_prefill = isset($_GET['ll_enable_verb_tense']) && (string) $_GET['ll_enable_verb_tense'] === '1';
    $enable_verb_mood_prefill = isset($_GET['ll_enable_verb_mood']) && (string) $_GET['ll_enable_verb_mood'] === '1';
    $category_translation_enabled = function_exists('ll_tools_is_category_translation_enabled')
        ? (bool) ll_tools_is_category_translation_enabled()
        : false;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Duplicate Category Words', 'll-tools-text-domain'); ?></h1>
        <p>
            <?php esc_html_e('Copy words from one category into another category within the same word set. New words keep title, image, and metadata, but start without recordings.', 'll-tools-text-domain'); ?>
        </p>
        <p>
            <?php esc_html_e('Use overrides to bulk-change grammatical flags such as tense, mood, and plurality on the cloned words.', 'll-tools-text-domain'); ?>
        </p>

        <?php
        $error_code = isset($_GET['ll_dup_error']) ? sanitize_key((string) $_GET['ll_dup_error']) : '';
        $error_message = ll_tools_duplicate_category_words_get_error_message($error_code);
        if ($error_message !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }

        if (isset($_GET['ll_dup_done']) && $_GET['ll_dup_done'] === '1') {
            $created = isset($_GET['ll_dup_created']) ? absint($_GET['ll_dup_created']) : 0;
            $failed = isset($_GET['ll_dup_failed']) ? absint($_GET['ll_dup_failed']) : 0;
            $skipped = isset($_GET['ll_dup_skipped']) ? absint($_GET['ll_dup_skipped']) : 0;
            $entry_created = isset($_GET['ll_dup_entry_created']) ? absint($_GET['ll_dup_entry_created']) : 0;
            $entry_errors = isset($_GET['ll_dup_entry_errors']) ? absint($_GET['ll_dup_entry_errors']) : 0;
            $summary_parts = [
                sprintf(
                    _n('%d word duplicated.', '%d words duplicated.', $created, 'll-tools-text-domain'),
                    $created
                ),
            ];
            if ($failed > 0) {
                $summary_parts[] = sprintf(
                    _n('%d clone failed.', '%d clones failed.', $failed, 'll-tools-text-domain'),
                    $failed
                );
            }
            if ($skipped > 0) {
                $summary_parts[] = sprintf(
                    _n('%d word skipped (permission).', '%d words skipped (permission).', $skipped, 'll-tools-text-domain'),
                    $skipped
                );
            }
            if ($entry_created > 0) {
                $summary_parts[] = sprintf(
                    _n('%d dictionary entry was created automatically.', '%d dictionary entries were created automatically.', $entry_created, 'll-tools-text-domain'),
                    $entry_created
                );
            }
            if ($entry_errors > 0) {
                $summary_parts[] = sprintf(
                    _n('%d dictionary link update failed.', '%d dictionary link updates failed.', $entry_errors, 'll-tools-text-domain'),
                    $entry_errors
                );
            }

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(implode(' ', $summary_parts)) . '</p></div>';
        }
        ?>

        <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" id="ll-tools-duplicate-wordset-form">
            <input type="hidden" name="post_type" value="words">
            <input type="hidden" name="page" value="ll-tools-duplicate-category-words">
            <?php if ($source_category_prefill > 0) : ?>
                <input type="hidden" name="ll_source_category_id" value="<?php echo esc_attr((string) $source_category_prefill); ?>">
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ll_wordset_id"><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <select name="ll_wordset_id" id="ll_wordset_id" class="regular-text">
                            <option value="0"><?php esc_html_e('Select word set', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($wordsets as $wordset_term) : ?>
                                <option value="<?php echo esc_attr((string) $wordset_term->term_id); ?>" <?php selected((int) $wordset_term->term_id, $selected_wordset_id); ?>>
                                    <?php echo esc_html((string) $wordset_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="button"><?php esc_html_e('Load', 'll-tools-text-domain'); ?></button>
                        </noscript>
                    </td>
                </tr>
            </table>
        </form>
        <script>
        (function () {
            var form = document.getElementById('ll-tools-duplicate-wordset-form');
            if (!form) {
                return;
            }
            var select = document.getElementById('ll_wordset_id');
            if (!select) {
                return;
            }
            select.addEventListener('change', function () {
                form.submit();
            });
        })();
        </script>

        <?php if ($selected_wordset_id <= 0) : ?>
            <p><?php esc_html_e('Choose a word set to continue.', 'll-tools-text-domain'); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <?php if (empty($source_categories)) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('No source categories with words were found in this word set.', 'll-tools-text-domain'); ?></p>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ll-tools-duplicate-category-form">
            <input type="hidden" name="action" value="ll_tools_duplicate_category_words_save">
            <input type="hidden" name="ll_wordset_id" value="<?php echo esc_attr((string) $selected_wordset_id); ?>">
            <?php wp_nonce_field('ll_tools_duplicate_category_words_save_' . $selected_wordset_id, 'll_tools_duplicate_category_words_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ll_source_category_id"><?php esc_html_e('Source Category', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <select name="ll_source_category_id" id="ll_source_category_id" class="regular-text" required>
                            <option value="0"><?php esc_html_e('Select source category', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($source_categories as $category_term) : ?>
                                <option value="<?php echo esc_attr((string) $category_term->term_id); ?>" <?php selected((int) $category_term->term_id, $source_category_prefill); ?>>
                                    <?php echo esc_html((string) $category_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Target Category', 'll-tools-text-domain'); ?>
                    </th>
                    <td>
                        <fieldset>
                            <label style="display:block; margin-bottom:6px;">
                                <input type="radio" name="ll_target_mode" value="new" <?php checked($target_mode_prefill, 'new'); ?>>
                                <?php esc_html_e('Create new category (default)', 'll-tools-text-domain'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="ll_target_mode" value="existing" <?php checked($target_mode_prefill, 'existing'); ?>>
                                <?php esc_html_e('Use existing category', 'll-tools-text-domain'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr id="ll-target-new-row">
                    <th scope="row">
                        <label for="ll_new_category_name"><?php esc_html_e('New Category Details', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <p>
                            <input
                                type="text"
                                name="ll_new_category_name"
                                id="ll_new_category_name"
                                class="regular-text"
                                value="<?php echo esc_attr($new_category_name_prefill); ?>"
                                placeholder="<?php echo esc_attr__('New category name', 'll-tools-text-domain'); ?>"
                            >
                        </p>
                        <p>
                            <input
                                type="text"
                                name="ll_new_category_slug"
                                id="ll_new_category_slug"
                                class="regular-text"
                                value="<?php echo esc_attr($new_category_slug_prefill); ?>"
                                placeholder="<?php echo esc_attr__('Optional slug', 'll-tools-text-domain'); ?>"
                            >
                        </p>
                        <?php if ($category_translation_enabled) : ?>
                            <p>
                                <input
                                    type="text"
                                    name="ll_new_category_translation"
                                    id="ll_new_category_translation"
                                    class="regular-text"
                                    value="<?php echo esc_attr($new_category_translation_prefill); ?>"
                                    placeholder="<?php echo esc_attr__('Optional translated name', 'll-tools-text-domain'); ?>"
                                >
                            </p>
                        <?php endif; ?>
                        <label style="display:block; margin-top:8px;">
                            <input type="checkbox" name="ll_copy_source_settings" value="1" <?php checked($copy_source_settings_prefill); ?>>
                            <?php esc_html_e('Copy source category quiz/recording settings to the new category', 'll-tools-text-domain'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Cloned words are moved into this new category (not kept in the source category).', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr id="ll-target-existing-row">
                    <th scope="row">
                        <label for="ll_target_category_id"><?php esc_html_e('Existing Target Category', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <select name="ll_target_category_id" id="ll_target_category_id" class="regular-text">
                            <option value="0"><?php esc_html_e('Select target category', 'll-tools-text-domain'); ?></option>
                            <?php foreach ((array) $target_categories as $category_term) : ?>
                                <?php if (!($category_term instanceof WP_Term)) { continue; } ?>
                                <option value="<?php echo esc_attr((string) $category_term->term_id); ?>" <?php selected((int) $category_term->term_id, $target_category_prefill); ?>>
                                    <?php echo esc_html((string) $category_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Gender Override', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        if (empty($grammar_config['gender_enabled'])) {
                            echo '<label style="display:block; margin-bottom:8px;">';
                            echo '<input type="checkbox" name="ll_enable_gender" value="1" ' . checked($enable_gender_prefill, true, false) . '> ';
                            echo esc_html__('Enable grammatical gender for this word set', 'll-tools-text-domain');
                            echo '</label>';
                        }
                        if (!empty($grammar_config['gender_options'])) {
                            ll_tools_duplicate_category_words_render_override_select(
                                'll_override_gender',
                                'll_override_gender',
                                isset($_GET['ll_override_gender']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_override_gender'])) : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE,
                                $grammar_config['gender_options']
                            );
                        } else {
                            echo '<p class="description">' . esc_html__('Grammatical gender is not enabled for this word set.', 'll-tools-text-domain') . '</p>';
                        }
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Plurality Override', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        if (empty($grammar_config['plurality_enabled'])) {
                            echo '<label style="display:block; margin-bottom:8px;">';
                            echo '<input type="checkbox" name="ll_enable_plurality" value="1" ' . checked($enable_plurality_prefill, true, false) . '> ';
                            echo esc_html__('Enable plurality for this word set', 'll-tools-text-domain');
                            echo '</label>';
                        }
                        if (!empty($grammar_config['plurality_options'])) {
                            ll_tools_duplicate_category_words_render_override_select(
                                'll_override_plurality',
                                'll_override_plurality',
                                isset($_GET['ll_override_plurality']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_override_plurality'])) : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE,
                                $grammar_config['plurality_options']
                            );
                        } else {
                            echo '<p class="description">' . esc_html__('Plurality is not enabled for this word set.', 'll-tools-text-domain') . '</p>';
                        }
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Verb Tense Override', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        if (empty($grammar_config['verb_tense_enabled'])) {
                            echo '<label style="display:block; margin-bottom:8px;">';
                            echo '<input type="checkbox" name="ll_enable_verb_tense" value="1" ' . checked($enable_verb_tense_prefill, true, false) . '> ';
                            echo esc_html__('Enable verb tense for this word set', 'll-tools-text-domain');
                            echo '</label>';
                        }
                        if (!empty($grammar_config['verb_tense_options'])) {
                            ll_tools_duplicate_category_words_render_override_select(
                                'll_override_verb_tense',
                                'll_override_verb_tense',
                                isset($_GET['ll_override_verb_tense']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_override_verb_tense'])) : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE,
                                $grammar_config['verb_tense_options']
                            );
                        } else {
                            echo '<p class="description">' . esc_html__('Verb tense tags are not enabled for this word set.', 'll-tools-text-domain') . '</p>';
                        }
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Verb Mood Override', 'll-tools-text-domain'); ?></th>
                    <td>
                        <?php
                        if (empty($grammar_config['verb_mood_enabled'])) {
                            echo '<label style="display:block; margin-bottom:8px;">';
                            echo '<input type="checkbox" name="ll_enable_verb_mood" value="1" ' . checked($enable_verb_mood_prefill, true, false) . '> ';
                            echo esc_html__('Enable verb mood for this word set', 'll-tools-text-domain');
                            echo '</label>';
                        }
                        if (!empty($grammar_config['verb_mood_options'])) {
                            ll_tools_duplicate_category_words_render_override_select(
                                'll_override_verb_mood',
                                'll_override_verb_mood',
                                isset($_GET['ll_override_verb_mood']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_override_verb_mood'])) : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE,
                                $grammar_config['verb_mood_options']
                            );
                        } else {
                            echo '<p class="description">' . esc_html__('Verb mood tags are not enabled for this word set.', 'll-tools-text-domain') . '</p>';
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Duplicate Words', 'll-tools-text-domain'); ?>
                </button>
            </p>
        </form>
        <script>
        (function () {
            var form = document.getElementById('ll-tools-duplicate-category-form');
            if (!form) {
                return;
            }
            var modeFields = form.querySelectorAll('input[name="ll_target_mode"]');
            var newRow = document.getElementById('ll-target-new-row');
            var existingRow = document.getElementById('ll-target-existing-row');
            var newName = document.getElementById('ll_new_category_name');
            var existingSelect = document.getElementById('ll_target_category_id');

            function currentMode() {
                for (var i = 0; i < modeFields.length; i++) {
                    if (modeFields[i].checked) {
                        return modeFields[i].value;
                    }
                }
                return 'new';
            }

            function syncTargetMode() {
                var mode = currentMode();
                var useNew = mode === 'new';

                if (newRow) {
                    newRow.style.display = useNew ? '' : 'none';
                }
                if (existingRow) {
                    existingRow.style.display = useNew ? 'none' : '';
                }
                if (newName) {
                    newName.required = useNew;
                }
                if (existingSelect) {
                    existingSelect.required = !useNew;
                }
            }

            for (var i = 0; i < modeFields.length; i++) {
                modeFields[i].addEventListener('change', syncTargetMode);
            }
            syncTargetMode();
        })();
        </script>
    </div>
    <?php
}

add_action('admin_post_ll_tools_duplicate_category_words_save', 'll_tools_handle_duplicate_category_words_save');
function ll_tools_handle_duplicate_category_words_save() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'll-tools-text-domain'), 403);
    }

    $wordset_id = isset($_POST['ll_wordset_id']) ? absint($_POST['ll_wordset_id']) : 0;
    $source_category_id = isset($_POST['ll_source_category_id']) ? absint($_POST['ll_source_category_id']) : 0;
    $target_mode = isset($_POST['ll_target_mode']) ? sanitize_key((string) wp_unslash($_POST['ll_target_mode'])) : 'new';
    if (!in_array($target_mode, ['new', 'existing'], true)) {
        $target_mode = 'new';
    }

    $target_category_id = isset($_POST['ll_target_category_id']) ? absint($_POST['ll_target_category_id']) : 0;
    $new_category_name = isset($_POST['ll_new_category_name'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_new_category_name']))
        : '';
    $new_category_name = trim($new_category_name);
    $new_category_slug = isset($_POST['ll_new_category_slug'])
        ? sanitize_title(wp_unslash((string) $_POST['ll_new_category_slug']))
        : '';
    $new_category_translation = isset($_POST['ll_new_category_translation'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_new_category_translation']))
        : '';
    $new_category_translation = trim($new_category_translation);
    $copy_source_settings = isset($_POST['ll_copy_source_settings']);

    $enable_gender = isset($_POST['ll_enable_gender']);
    $enable_plurality = isset($_POST['ll_enable_plurality']);
    $enable_verb_tense = isset($_POST['ll_enable_verb_tense']);
    $enable_verb_mood = isset($_POST['ll_enable_verb_mood']);

    $redirect_base_args = [
        'll_wordset_id'           => $wordset_id,
        'll_source_category_id'   => $source_category_id,
        'll_target_mode'          => $target_mode,
        'll_target_category_id'   => $target_category_id,
        'll_new_category_name'    => $new_category_name,
        'll_new_category_slug'    => $new_category_slug,
        'll_new_category_translation' => $new_category_translation,
        'll_copy_source_settings' => $copy_source_settings ? 1 : 0,
        'll_enable_gender'        => $enable_gender ? 1 : 0,
        'll_enable_plurality'     => $enable_plurality ? 1 : 0,
        'll_enable_verb_tense'    => $enable_verb_tense ? 1 : 0,
        'll_enable_verb_mood'     => $enable_verb_mood ? 1 : 0,
    ];

    if (!isset($_POST['ll_tools_duplicate_category_words_nonce'])
        || !wp_verify_nonce($_POST['ll_tools_duplicate_category_words_nonce'], 'll_tools_duplicate_category_words_save_' . $wordset_id)) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'nonce'])));
        exit;
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    if (!$wordset_term || is_wp_error($wordset_term) || !ll_tools_duplicate_category_words_user_can_access_wordset($wordset_id)) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_wordset'])));
        exit;
    }

    $source_category = get_term($source_category_id, 'word-category');
    if (!$source_category || is_wp_error($source_category)) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_source_category'])));
        exit;
    }

    $grammar_config = ll_tools_duplicate_category_words_get_grammar_config($wordset_id);
    $gender_raw = isset($_POST['ll_override_gender'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_override_gender']))
        : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE;
    $plurality_raw = isset($_POST['ll_override_plurality'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_override_plurality']))
        : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE;
    $verb_tense_raw = isset($_POST['ll_override_verb_tense'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_override_verb_tense']))
        : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE;
    $verb_mood_raw = isset($_POST['ll_override_verb_mood'])
        ? sanitize_text_field(wp_unslash((string) $_POST['ll_override_verb_mood']))
        : LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE;

    $redirect_base_args['ll_override_gender'] = $gender_raw;
    $redirect_base_args['ll_override_plurality'] = $plurality_raw;
    $redirect_base_args['ll_override_verb_tense'] = $verb_tense_raw;
    $redirect_base_args['ll_override_verb_mood'] = $verb_mood_raw;

    $gender_override = ll_tools_duplicate_category_words_normalize_override_value($gender_raw, $grammar_config['gender_options']);
    if (empty($gender_override['valid'])) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_gender'])));
        exit;
    }

    $plurality_override = ll_tools_duplicate_category_words_normalize_override_value($plurality_raw, $grammar_config['plurality_options']);
    if (empty($plurality_override['valid'])) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_plurality'])));
        exit;
    }

    $verb_tense_override = ll_tools_duplicate_category_words_normalize_override_value($verb_tense_raw, $grammar_config['verb_tense_options']);
    if (empty($verb_tense_override['valid'])) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_verb_tense'])));
        exit;
    }

    $verb_mood_override = ll_tools_duplicate_category_words_normalize_override_value($verb_mood_raw, $grammar_config['verb_mood_options']);
    if (empty($verb_mood_override['valid'])) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_verb_mood'])));
        exit;
    }

    if (!in_array($target_mode, ['new', 'existing'], true)) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_target_mode'])));
        exit;
    }

    if ($target_mode === 'new') {
        if ($new_category_name === '') {
            wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'missing_new_category_name'])));
            exit;
        }

        $existing_term = term_exists($new_category_name, 'word-category');
        if ($existing_term) {
            wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'new_category_exists'])));
            exit;
        }

        $insert_args = [];
        if ($new_category_slug !== '') {
            $insert_args['slug'] = $new_category_slug;
        }
        $inserted = wp_insert_term($new_category_name, 'word-category', $insert_args);
        if (is_wp_error($inserted) || empty($inserted['term_id'])) {
            wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'create_target_category_failed'])));
            exit;
        }

        $target_category_id = (int) $inserted['term_id'];
        $redirect_base_args['ll_target_category_id'] = $target_category_id;

        $category_translation_enabled = function_exists('ll_tools_is_category_translation_enabled')
            ? (bool) ll_tools_is_category_translation_enabled()
            : false;
        if ($category_translation_enabled && $new_category_translation !== '') {
            update_term_meta($target_category_id, 'term_translation', $new_category_translation);
        }

        if ($copy_source_settings) {
            $copy_translation = !($category_translation_enabled && $new_category_translation !== '');
            ll_tools_duplicate_category_words_copy_source_category_settings($source_category_id, $target_category_id, $copy_translation);
        }
    } else {
        $target_category = get_term($target_category_id, 'word-category');
        if (!$target_category || is_wp_error($target_category)) {
            wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'invalid_target_category'])));
            exit;
        }

        if ($source_category_id === $target_category_id) {
            wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'same_category'])));
            exit;
        }
    }

    if (empty($grammar_config['gender_enabled'])
        && (string) $gender_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE
        && (string) $gender_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
        $enable_gender = true;
    }
    if (empty($grammar_config['plurality_enabled'])
        && (string) $plurality_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE
        && (string) $plurality_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
        $enable_plurality = true;
    }
    if (empty($grammar_config['verb_tense_enabled'])
        && (string) $verb_tense_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE
        && (string) $verb_tense_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
        $enable_verb_tense = true;
    }
    if (empty($grammar_config['verb_mood_enabled'])
        && (string) $verb_mood_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_NO_CHANGE
        && (string) $verb_mood_override['value'] !== LL_TOOLS_DUPLICATE_OVERRIDE_CLEAR) {
        $enable_verb_mood = true;
    }

    ll_tools_duplicate_category_words_enable_wordset_flags($wordset_id, [
        'gender'    => $enable_gender,
        'plurality' => $enable_plurality,
        'verb_tense'=> $enable_verb_tense,
        'verb_mood' => $enable_verb_mood,
    ]);

    $source_word_ids = get_posts([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'tax_query'        => [
            'relation' => 'AND',
            [
                'taxonomy'         => 'wordset',
                'field'            => 'term_id',
                'terms'            => [$wordset_id],
                'include_children' => false,
            ],
            [
                'taxonomy'         => 'word-category',
                'field'            => 'term_id',
                'terms'            => [$source_category_id],
                'include_children' => false,
            ],
        ],
    ]);

    if (empty($source_word_ids)) {
        wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url(array_merge($redirect_base_args, ['ll_dup_error' => 'no_words'])));
        exit;
    }

    $overrides = [
        'll_grammatical_gender' => (string) $gender_override['value'],
        'll_grammatical_plurality' => (string) $plurality_override['value'],
        'll_verb_tense'         => (string) $verb_tense_override['value'],
        'll_verb_mood'          => (string) $verb_mood_override['value'],
    ];

    $created_count = 0;
    $failed_count = 0;
    $skipped_count = 0;
    $entry_created_count = 0;
    $entry_error_count = 0;
    $touched_categories = [];

    foreach ($source_word_ids as $source_word_id) {
        $source_word_id = (int) $source_word_id;
        if ($source_word_id <= 0) {
            continue;
        }

        if (!current_user_can('edit_post', $source_word_id)) {
            $skipped_count++;
            continue;
        }

        $source_word = get_post($source_word_id);
        if (!$source_word || $source_word->post_type !== 'words') {
            $failed_count++;
            continue;
        }

        $new_word_id = ll_tools_create_split_word_clone($source_word, (string) $source_word->post_title);
        if (is_wp_error($new_word_id) || (int) $new_word_id <= 0) {
            $failed_count++;
            continue;
        }
        $new_word_id = (int) $new_word_id;

        // Recording workflow should start from unrecorded clones; keep them as drafts.
        wp_update_post([
            'ID'          => $new_word_id,
            'post_status' => 'draft',
        ]);
        delete_post_meta($new_word_id, '_ll_skip_audio_requirement_once');

        $source_term_ids = wp_get_post_terms($source_word_id, 'word-category', ['fields' => 'ids']);
        $new_category_ids = [];
        if (!is_wp_error($source_term_ids)) {
            foreach ((array) $source_term_ids as $term_id) {
                $term_id = (int) $term_id;
                if ($term_id <= 0 || $term_id === $source_category_id) {
                    continue;
                }
                $new_category_ids[] = $term_id;
            }
        }
        $new_category_ids[] = $target_category_id;
        $new_category_ids = array_values(array_unique(array_filter(array_map('intval', $new_category_ids), function ($id) {
            return $id > 0;
        })));
        $set_categories_result = wp_set_post_terms($new_word_id, $new_category_ids, 'word-category', false);
        if (is_wp_error($set_categories_result)) {
            $fallback_result = wp_set_post_terms($new_word_id, [$target_category_id], 'word-category', false);
            if (is_wp_error($fallback_result)) {
                $failed_count++;
                continue;
            }
            $new_category_ids = [$target_category_id];
        }

        foreach ($new_category_ids as $cat_id) {
            $touched_categories[(int) $cat_id] = true;
        }

        ll_tools_duplicate_category_words_apply_overrides_to_word($new_word_id, $overrides);
        ll_tools_duplicate_category_words_sync_word_image_category($new_word_id, $target_category_id);

        $entry_result = ll_tools_duplicate_category_words_link_dictionary_entry($source_word_id, $new_word_id);
        if (!empty($entry_result['entry_created'])) {
            $entry_created_count++;
        }
        if (!empty($entry_result['link_error'])) {
            $entry_error_count++;
        }

        $created_count++;
    }

    if (!empty($touched_categories) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_keys($touched_categories));
    }

    $success_args = [
        'll_wordset_id'         => $wordset_id,
        'll_source_category_id' => $source_category_id,
        'll_target_mode'        => $target_mode,
        'll_target_category_id' => $target_category_id,
        'll_dup_done'           => '1',
        'll_dup_created'        => $created_count,
        'll_dup_failed'         => $failed_count,
        'll_dup_skipped'        => $skipped_count,
        'll_dup_entry_created'  => $entry_created_count,
        'll_dup_entry_errors'   => $entry_error_count,
    ];

    wp_safe_redirect(ll_tools_get_duplicate_category_words_page_url($success_args));
    exit;
}
