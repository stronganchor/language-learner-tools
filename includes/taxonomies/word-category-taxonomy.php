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
}

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
    // Resolve the term to check the per-category matching rule
    $term = get_term_by('name', $categoryName, 'word-category');
    $use_titles = false;
    if ($term && !is_wp_error($term)) {
        $use_titles = (get_term_meta($term->term_id, 'use_word_titles_for_audio', true) === '1');
    }

    // Fetch ONLY published words in this category (and wordset if provided)
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

    if ($wordset_id) {
        $args['tax_query'][] = [
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => $wordset_id,
        ];
        $args['tax_query']['relation'] = 'AND';
    }

    $query = new WP_Query($args);
    $words = [];

    foreach ($query->posts as $post) {
        // Guard (belt-and-suspenders): only proceed if the word itself is published
        if (get_post_status($post->ID) !== 'publish') {
            continue;
        }

        $word_id = $post->ID;
        $image   = get_the_post_thumbnail_url($word_id, 'full');

        // Collect published child audio posts only
        $audio_files = [];
        $audio_posts = get_posts([
            'post_type'      => 'word_audio',
            'post_parent'    => $word_id,
            'post_status'    => 'publish', // <-- exclude draft/trashed audio
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($audio_posts as $audio_post) {
            $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
            if ($audio_path) {
                $audio_url       = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
                $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
                $audio_files[]   = [
                    'url'            => $audio_url,
                    'recording_type' => !empty($recording_types) ? $recording_types[0] : 'unknown',
                ];
            }
        }

        $primary_audio = !empty($audio_files) ? $audio_files[0]['url'] : '';

        // Compute label:
        // - If the category is explicitly set to "use titles", label = title.
        // - Otherwise, in text mode prefer translation meta; fall back to title if missing.
        $title = html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8');

        $label = $title; // default
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
            'image'           => $image ?: '',
            'all_categories'  => $all_categories,
            'similar_word_id' => $similar_word_id ?: '',
        ];

        // Include word depending on requested display mode
        if ($displayMode === 'image' && !empty($image)) {
            $words[] = $word_data;
        } elseif ($displayMode === 'text') {
            $words[] = $word_data;
        } elseif ($displayMode === 'random') {
            $words[] = $word_data;
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
        }
    }

    // Check each priority level in order
    foreach ($priority_order as $type) {
        if (!empty($audio_by_type[$type])) {
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
 * @return bool True if the category can generate a quiz, false otherwise.
 */
function ll_can_category_generate_quiz($category, $min_word_count = 5) {
    // Get the term object if we received an ID
    if (is_numeric($category)) {
        $term = get_term($category, 'word-category');
        if (!$term || is_wp_error($term)) {
            return false;
        }
    } else {
        $term = $category;
    }

    // If this category is set to "match audio to titles", check text mode specifically
    $use_titles = get_term_meta($term->term_id, 'use_word_titles_for_audio', true) === '1';
    if ($use_titles) {
        // Check if there are enough words for text mode
        $text_count = count(ll_get_words_by_category($term->name, 'text'));
        return $text_count >= $min_word_count;
    }

    // Otherwise, use the same logic as ll_determine_display_mode
    $image_count = count(ll_get_words_by_category($term->name, 'image'));
    $text_count = count(ll_get_words_by_category($term->name, 'text'));

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