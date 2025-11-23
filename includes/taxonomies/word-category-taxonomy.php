<?php

/**
 * Registers the "word-category" taxonomy for "words" and "word_images" post types.
 *
 * @return void
 */
function ll_tools_register_word_category_taxonomy() {
    $labels = [
        "name" => esc_html__("Word Categories", "astra"),
        "singular_name" => esc_html__("Word Category", "astra"),
    ];

    $args = [
        "label" => esc_html__("Word Categories", "astra"),
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

    // Checkbox for matching titles instead of translations
    add_action('word-category_add_form_fields', 'll_add_use_word_titles_field');
    add_action('word-category_edit_form_fields', 'll_edit_use_word_titles_field');
    add_action('created_word-category', 'll_save_use_word_titles_field', 10, 2);
    add_action('edited_word-category', 'll_save_use_word_titles_field', 10, 2);

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
    return apply_filters('ll_tools_category_display_name', $display, $term, $opts);
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
 * Field to mark a category as "use titles" for audio matching.
 */
function ll_add_use_word_titles_field($term) {
    ?>
    <div class="form-field term-use-word-titles-wrap">
        <label for="use_word_titles_for_audio">
            <input type="checkbox" name="use_word_titles_for_audio" id="use_word_titles_for_audio" value="1">
            <?php esc_html_e('For this category, match audio to word titles instead of translations', 'll-tools-text-domain'); ?>
        </label>
    </div>
    <?php
}

/**
 * Field to mark a category as "use titles" when editing.
 *
 * @param WP_Term $term Term object.
 */
function ll_edit_use_word_titles_field($term) {
    $checkbox_value = get_term_meta($term->term_id, 'use_word_titles_for_audio', true);
    $checked = $checkbox_value === '1' ? 'checked' : '';
    ?>
    <tr class="form-field term-use-word-titles-wrap">
        <th scope="row" valign="top">
            <label for="use_word_titles_for_audio"><?php esc_html_e('Match audio to word titles?', 'll-tools-text-domain'); ?></label>
        </th>
        <td>
            <input type="checkbox" name="use_word_titles_for_audio" id="use_word_titles_for_audio" value="1" <?php echo $checked; ?>>
            <p class="description"><?php esc_html_e('If enabled, the quiz will treat this category as text-only and use the word post title as the correct match.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <?php
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
    return ll_tools_get_main_recording_types();
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
        'fields'         => 'ids',
    ]);
    if (empty($audio_posts)) { return 0; }
    $by_speaker = [];
    foreach ($audio_posts as $pid) {
        $speaker = (int) get_post_meta($pid, 'speaker_user_id', true);
        if (!$speaker) { $post = get_post($pid); $speaker = $post ? (int) $post->post_author : 0; }
        if (!$speaker) { continue; }
        $types = wp_get_post_terms($pid, 'recording_type', ['fields' => 'slugs']);
        if (is_wp_error($types) || empty($types)) { continue; }
        foreach ($types as $t) {
            $by_speaker[$speaker][$t] = true;
        }
    }
    foreach ($by_speaker as $uid => $typeMap) {
        $has_all = !array_diff($main, array_keys($typeMap));
        if ($has_all) { return (int) $uid; }
    }
    return 0;
}
/**
 * Saves the "use titles" checkbox for a term.
 *
 * @param int    $term_id Term ID.
 * @param string $taxonomy Taxonomy name.
 */
function ll_save_use_word_titles_field($term_id, $taxonomy) {
    if (isset($_POST['use_word_titles_for_audio'])) {
        update_term_meta($term_id, 'use_word_titles_for_audio', '1');
    } else {
        delete_term_meta($term_id, 'use_word_titles_for_audio');
    }
}

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

function ll_get_words_by_category($categoryName, $displayMode = 'image', $wordset_id = null) {
    $term = get_term_by('name', $categoryName, 'word-category');
    $use_titles = false;
    if ($term && !is_wp_error($term)) {
        $use_titles = (get_term_meta($term->term_id, 'use_word_titles_for_audio', true) === '1');
    }
    $wordset_terms = [];
    if (!empty($wordset_id)) {
        $wordset_terms = is_array($wordset_id) ? array_map('intval', $wordset_id) : [(int) $wordset_id];
        $wordset_terms = array_filter($wordset_terms, function ($id) { return $id > 0; });
    }

    $args = [
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'name',
            'terms'    => $categoryName,
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
    $words = [];

    foreach ($query->posts as $post) {
        if (get_post_status($post->ID) !== 'publish') {
            continue;
        }

        $word_id = $post->ID;
        $image   = get_the_post_thumbnail_url($word_id, 'full');

        $audio_files = [];
        $audio_posts = get_posts([
            'post_type'      => 'word_audio',
            'post_parent'    => $word_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $preferred_speaker = ll_tools_get_preferred_speaker_for_word($word_id);
        foreach ($audio_posts as $audio_post) {
            $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
            if ($audio_path) {
                $audio_url       = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
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

        $prioritized_audio = ll_get_prioritized_audio($audio_posts);
        $primary_audio = '';
        if ($prioritized_audio) {
            $audio_path = get_post_meta($prioritized_audio->ID, 'audio_file_path', true);
            if ($audio_path) {
                $primary_audio = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
            }
        }

        // Require actual audio to be present for inclusion in quizzes. Do NOT fall back to legacy meta here.
        $has_audio = !empty($primary_audio) || !empty($audio_files);

        $title = html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8');

        $label = $title;
        if (!$use_titles && $displayMode === 'text') {
            $candidate_keys = [
                'word_english_meaning',
                'word_translation',
                'translation',
                'meaning',
            ];
            $translation = '';
            foreach ($candidate_keys as $key) {
                $val = trim((string) get_post_meta($word_id, $key, true));
                if ($val !== '') { $translation = $val; break; }
            }
            if ($translation !== '') {
                $label = html_entity_decode($translation, ENT_QUOTES, 'UTF-8');
            }
        }

        $all_categories  = wp_get_post_terms($word_id, 'word-category', ['fields' => 'names']);
        $similar_word_id = get_post_meta($word_id, '_ll_similar_word_id', true);

        $word_data = [
            'id'              => $word_id,
            'title'           => $title,
            'label'           => $label,
            'audio'           => $primary_audio,
            'audio_files'     => $audio_files,
            'preferred_speaker_user_id' => $preferred_speaker,
            'image'           => $image ?: '',
            'all_categories'  => $all_categories,
            'similar_word_id' => $similar_word_id ?: '',
        ];

        // Only include words that have playable audio
        if ($has_audio) {
            if ($displayMode === 'image' && !empty($image)) {
                $words[] = $word_data;
            } elseif ($displayMode === 'text') {
                $words[] = $word_data;
            } elseif ($displayMode === 'random') {
                $words[] = $word_data;
            }
        }
    }

    return $words;
}

/**
 * Get audio URL for a word - prioritizes by recording type
 * Priority: question > introduction > isolation > in sentence > any other
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
                return (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
            }
        }
    }

    // Fallback to legacy meta
    $legacy_audio = get_post_meta($word_id, 'word_audio_file', true);
    if ($legacy_audio) {
        return (0 === strpos($legacy_audio, 'http')) ? $legacy_audio : site_url($legacy_audio);
    }

    return '';
}

/**
 * Select the highest priority audio from an array of word_audio posts
 * Priority: question > introduction > isolation > in sentence > any other
 *
 * @param array $audio_posts Array of word_audio post objects
 * @return WP_Post|null The highest priority audio post or null
 */
function ll_get_prioritized_audio($audio_posts) {
    if (empty($audio_posts)) {
        return null;
    }

    $priority_order = ['question', 'introduction', 'isolation', 'in sentence'];

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

    // Attempt to prefer a speaker who has all main types
    $preferred_speaker = 0;
    if (!empty($audio_posts)) {
        $parent_id = (int) (get_post($audio_posts[0])->post_parent ?? 0);
        if ($parent_id) { $preferred_speaker = ll_tools_get_preferred_speaker_for_word($parent_id); }
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
        // Natural, case-insensitive sort of the names array (preserves keys).
        natcasesort( $terms );
        return $terms;
    }

    // Handle associative map of id => name.
    if ( $fields === 'id=>name' ) {
        uasort( $terms, static function( $a, $b ) {
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

    // If this category is set to "match audio to titles", check text mode specifically
    $use_titles = get_term_meta($term->term_id, 'use_word_titles_for_audio', true) === '1';
    if ($use_titles) {
        // Check if there are enough words for text mode
        $text_count = count(ll_get_words_by_category($term->name, 'text', $wordset_ids));
        return $text_count >= $min_word_count;
    }

    // Otherwise, use the same logic as ll_determine_display_mode
    $image_count = count(ll_get_words_by_category($term->name, 'image', $wordset_ids));
    $text_count = count(ll_get_words_by_category($term->name, 'text', $wordset_ids));

    // If both image and text counts are below the minimum, can't generate quiz
    return !($image_count < $min_word_count && $text_count < $min_word_count);
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
 */
function ll_enqueue_bulk_category_edit_script($post_type, $script_handle, $script_path) {
    global $pagenow, $typenow;

    if ($pagenow !== 'edit.php' || $typenow !== $post_type) {
        return;
    }

    wp_enqueue_script(
        $script_handle,
        plugins_url($script_path, LL_TOOLS_MAIN_FILE),
        ['jquery', 'inline-edit-post'],
        filemtime(LL_TOOLS_BASE_PATH . $script_path),
        true
    );

    wp_localize_script($script_handle, 'llBulkEditData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_bulk_category_edit_' . $post_type),
        'postType' => $post_type,
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
        wp_send_json_error('Permission denied');
    }

    $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];

    if (empty($post_ids)) {
        wp_send_json_error('No posts selected');
    }

    // Get categories for each post
    $all_categories = [];
    foreach ($post_ids as $post_id) {
        $terms = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($terms)) {
            $all_categories[$post_id] = $terms;
        }
    }

    if (empty($all_categories)) {
        wp_send_json_success(['common' => []]);
    }

    // Find categories common to ALL selected posts
    $common = array_shift($all_categories);
    foreach ($all_categories as $post_cats) {
        $common = array_intersect($common, $post_cats);
    }

    wp_send_json_success(['common' => array_values($common)]);
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

        // Log for debugging
        error_log("LL Tools: $post_type post $post_id - Removed categories: " . implode(',', $categories_to_remove));
        error_log("LL Tools: $post_type post $post_id - New categories: " . implode(',', $new_terms));
    }
}
