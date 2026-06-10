<?php
// /includes/shortcodes/quiz-pages-shortcodes.php
/**
 * Shortcodes to list auto-generated quiz pages:
 *  - [quiz_pages_grid]
 *  - [quiz_pages_dropdown]
 *
 * A "quiz page" is a generated quiz-page record for a word-category. Legacy
 * WP Page records with the same meta key remain supported during migration.
 */

if (!defined('WPINC')) { die; }

/** ------------------------------------------------------------------
 * Shared helpers
 * ------------------------------------------------------------------ */

/**
 * Resolve a term identifier (id | slug | name) to a numeric term_id.
 *
 * @param string       $taxonomy  e.g. 'wordset'
 * @param string|int   $value     id, slug, or name
 * @return int|null
 */
function ll_tools_resolve_term_id_by_slug_name_or_id($taxonomy, $value) {
    if (is_numeric($value)) {
        $t = get_term((int)$value, $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
    }
    if (is_string($value) && $value !== '') {
        // try slug
        $t = get_term_by('slug', sanitize_title($value), $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
        // try name
        $t = get_term_by('name', $value, $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
    }
    return null;
}

/**
 * Resolve a wordset spec (slug/name/id) to one or more raw term_ids
 * directly from the DB, ignoring language filters.
 */
function ll_raw_resolve_wordset_term_ids($spec) {
    global $wpdb;

    $raw_spec = is_scalar($spec) ? (string) $spec : '';
    $normalized_spec = trim($raw_spec);
    $is_numeric_spec = is_numeric($spec);
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? (int) ll_tools_get_wordset_cache_epoch()
        : 1;
    if ($wordset_epoch < 1) {
        $wordset_epoch = 1;
    }

    $cache_key = 'll_raw_ws_ids_' . md5(wp_json_encode([
        'spec' => $normalized_spec,
        'is_numeric' => $is_numeric_spec ? 1 : 0,
        'epoch' => $wordset_epoch,
        'schema' => 1,
    ]));
    $cache_group = 'll_tools_wordset';
    $cache_ttl = HOUR_IN_SECONDS;

    static $request_cache = [];
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }
    if (is_array($cached)) {
        $cached = array_values(array_unique(array_filter(array_map('intval', $cached), function($v){ return $v > 0; })));
        $request_cache[$cache_key] = $cached;
        return $cached;
    }

    if ($is_numeric_spec) {
        $tid = (int) $spec;
        $result = ($tid > 0) ? [$tid] : [];
        $request_cache[$cache_key] = $result;
        wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
        set_transient($cache_key, $result, $cache_ttl);
        return $result;
    }

    $spec = $normalized_spec;
    if ($spec === '') {
        $request_cache[$cache_key] = [];
        wp_cache_set($cache_key, [], $cache_group, $cache_ttl);
        set_transient($cache_key, [], $cache_ttl);
        return [];
    }

    // 1) Exact slug match(es)
    $sql_slug = $wpdb->prepare("
        SELECT tt.term_id
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s AND t.slug = %s
    ", 'wordset', $spec);
    $ids = array_map('intval', (array) $wpdb->get_col($sql_slug));

    // 2) If nothing found by slug, try exact name match
    if (empty($ids)) {
        $sql_name = $wpdb->prepare("
            SELECT tt.term_id
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s AND t.name = %s
        ", 'wordset', $spec);
        $ids = array_map('intval', (array) $wpdb->get_col($sql_name));
    }

    $result = array_values(array_unique(array_filter($ids, function($v){ return $v > 0; })));
    $request_cache[$cache_key] = $result;
    wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
    set_transient($cache_key, $result, $cache_ttl);

    return $result;
}

/**
 * Collect all word-category IDs used by at least MINIMUM published "words" posts
 * that belong to ANY of the provided wordset term IDs. Uses direct SQL.
 * Includes ancestor categories so parents appear.
 */
function ll_collect_wc_ids_for_wordset_term_ids(array $wordset_term_ids) {
    global $wpdb;

    $wordset_term_ids = array_values(array_unique(array_map('intval', $wordset_term_ids)));
    $wordset_term_ids = array_filter($wordset_term_ids, function($v){ return $v > 0; });
    if (empty($wordset_term_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($wordset_term_ids), '%d'));

    // Get minimum word count (default 5)
    $min_words = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $category_cache_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? (int) ll_tools_get_category_cache_epoch()
        : 1;
    if ($category_cache_epoch < 1) {
        $category_cache_epoch = 1;
    }

    // Cache key includes the category cache epoch so membership/count changes invalidate it.
    $cache_key = 'll_wcids_ws_' . md5(wp_json_encode([
        'wordset_ids' => $wordset_term_ids,
        'min_words' => $min_words,
        'epoch' => $category_cache_epoch,
    ]));
    $cache_group = 'll_tools';
    $cache_ttl = HOUR_IN_SECONDS;

    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }
    if (is_array($cached)) {
        return array_values(array_map('intval', $cached));
    }

    // First, get playable item counts per category for this wordset.
    // Prompt cards are first-class quiz items here, so they should surface the
    // category even when the lesson mostly reuses answer words from elsewhere.
    $sql = $wpdb->prepare("
        SELECT tt_cat.term_id, COUNT(DISTINCT p.ID) as word_count
        FROM {$wpdb->posts}                p
        INNER JOIN {$wpdb->term_relationships} tr_ws  ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy}      tt_ws  ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy}      tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type IN (%s, %s)
          AND p.post_status = %s
          AND tt_ws.taxonomy  = %s
          AND tt_ws.term_id  IN ($placeholders)
          AND tt_cat.taxonomy = %s
        GROUP BY tt_cat.term_id
        HAVING word_count >= %d
    ", array_merge(['words', defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') ? LL_TOOLS_PROMPT_CARD_POST_TYPE : 'll_prompt_card', 'publish', 'wordset'], $wordset_term_ids, ['word-category', $min_words]));

    $cat_ids = array_map('intval', (array) $wpdb->get_col($sql));
    if (empty($cat_ids)) {
        wp_cache_set($cache_key, [], $cache_group, $cache_ttl);
        set_transient($cache_key, [], $cache_ttl);
        return [];
    }

    // Include ancestors so parents appear
    $with_anc = [];
    foreach ($cat_ids as $cid) {
        $with_anc[$cid] = true;
        foreach (get_ancestors($cid, 'word-category', 'taxonomy') as $aid) {
            $with_anc[(int) $aid] = true;
        }
    }
    $result = array_values(array_map('intval', array_keys($with_anc)));
    wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
    set_transient($cache_key, $result, $cache_ttl);
    return $result;
}

function ll_tools_quiz_pages_data_cache_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_quiz_pages_data_cache_ttl', DAY_IN_SECONDS);
    return max(MINUTE_IN_SECONDS, $ttl);
}

function ll_tools_quiz_pages_data_cache_key(array $opts, int $min_word_count): string {
    $wordset_spec = isset($opts['wordset']) && is_scalar($opts['wordset'])
        ? trim((string) $opts['wordset'])
        : '';

    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $locale = function_exists('determine_locale')
        ? (string) determine_locale()
        : (function_exists('get_locale') ? (string) get_locale() : '');

    return 'll_qpg_data_' . md5(wp_json_encode([
        'wordset' => $wordset_spec,
        'min_words' => $min_word_count,
        'category_epoch' => $category_epoch,
        'wordset_epoch' => $wordset_epoch,
        'user_id' => (int) get_current_user_id(),
        'locale' => $locale,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'schema' => 4,
    ]));
}

function ll_tools_quiz_pages_data_cache_get(string $cache_key) {
    $cached = wp_cache_get($cache_key, 'll_tools_quiz_pages');
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }

    if (is_array($cached) && isset($cached['__ll_quiz_pages_data_cache']) && is_array($cached['items'] ?? null)) {
        return $cached['items'];
    }

    return null;
}

function ll_tools_quiz_pages_data_cache_set(string $cache_key, array $items): void {
    $payload = [
        '__ll_quiz_pages_data_cache' => 1,
        'items' => $items,
    ];
    $ttl = ll_tools_quiz_pages_data_cache_ttl();

    wp_cache_set($cache_key, $payload, 'll_tools_quiz_pages', $ttl);
    set_transient($cache_key, $payload, $ttl);
}

/**
 * Fetch all published quiz pages and return display data.
 * Optional filter: $opts['wordset'] accepts slug/name/id of a WORDSET term.
 * This version is DB-driven for the filter path so guest/admin see identical results.
 */
function ll_get_all_quiz_pages_data($opts = []) {
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $cache_key = ll_tools_quiz_pages_data_cache_key((array) $opts, $min_word_count);
    $cached_items = ll_tools_quiz_pages_data_cache_get($cache_key);
    if (is_array($cached_items)) {
        return $cached_items;
    }

    // Load all generated quiz pages, including legacy Page records during migration.
    $quiz_page_post_types = function_exists('ll_tools_get_quiz_page_post_types')
        ? ll_tools_get_quiz_page_post_types(true)
        : ['page'];
    $quiz_page_category_meta = defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
        ? LL_TOOLS_QUIZ_PAGE_CATEGORY_META
        : '_ll_tools_word_category_id';

    $pages = get_posts([
        'post_type'        => $quiz_page_post_types,
        'post_status'      => 'publish',
        'has_password'     => false,
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'meta_key'         => $quiz_page_category_meta,
        'orderby'          => 'ID',  // Ensure consistent ordering for deduplication
        'order'            => 'ASC',
    ]);
    if (empty($pages)) {
        ll_tools_quiz_pages_data_cache_set($cache_key, []);
        return [];
    }

    if (defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE')) {
        usort($pages, static function ($a, $b): int {
            $a_is_current = get_post_type((int) $a) === LL_TOOLS_QUIZ_PAGE_POST_TYPE;
            $b_is_current = get_post_type((int) $b) === LL_TOOLS_QUIZ_PAGE_POST_TYPE;
            if ($a_is_current !== $b_is_current) {
                return $a_is_current ? -1 : 1;
            }

            return (int) $a <=> (int) $b;
        });
    }

    $allowed_term_ids = null;

    $ws_ids = [];
    $filtered_wordset_id = 0;
    if (!empty($opts['wordset'])) {
        $ws_ids = ll_raw_resolve_wordset_term_ids($opts['wordset']);
        if (empty($ws_ids)) return []; // nothing by that slug/name/id
        $allowed_term_ids = ll_collect_wc_ids_for_wordset_term_ids($ws_ids);
        if (empty($allowed_term_ids)) return []; // no categories used by that wordset
        $filtered_wordset_id = (int) ($ws_ids[0] ?? 0);
    }

    $items = [];
    $gender_config_cache = [];

    // Build the allowed categories list using the same helper as the widget for consistency
    $allowed_category_ids = [];
    $category_meta_map = [];
    $seen_stored_term_ids = [];
    $use_translations = function_exists('ll_flashcards_should_use_translations')
        ? ll_flashcards_should_use_translations($ws_ids)
        : false;
    if (function_exists('ll_flashcards_build_categories')) {
        [$processed] = ll_flashcards_build_categories('', $use_translations, $ws_ids);
        foreach ($processed as $cat) {
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0) {
                $allowed_category_ids[$cid] = true;
                $category_meta_map[$cid] = $cat;
            }
        }
    }
    $has_processed_category_meta = function_exists('ll_flashcards_build_categories');

    foreach ($pages as $post_id) {
        $stored_term_id = (int) get_post_meta($post_id, $quiz_page_category_meta, true);
        if ($stored_term_id <= 0) continue;
        if (isset($seen_stored_term_ids[$stored_term_id])) {
            continue;
        }
        $seen_stored_term_ids[$stored_term_id] = true;

        $term_id = $stored_term_id;
        if ($filtered_wordset_id > 0 && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $effective_term_id = (int) ll_tools_get_effective_category_id_for_wordset($stored_term_id, $filtered_wordset_id, false);
            if ($effective_term_id > 0) {
                $term_id = $effective_term_id;
            }
        }

        if (is_array($allowed_term_ids) && !in_array($term_id, $allowed_term_ids, true) && !in_array($stored_term_id, $allowed_term_ids, true)) {
            continue;
        }

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) continue;
        if (function_exists('ll_tools_user_can_view_category') && !ll_tools_user_can_view_category($term)) {
            continue;
        }

        $category_meta = $category_meta_map[$term_id] ?? ($category_meta_map[$stored_term_id] ?? null);
        if ($has_processed_category_meta && !is_array($category_meta)) {
            continue;
        }

        // Eligibility should match flashcard widget: use provided wordset scope; otherwise consider all wordsets
        $category_wordset_ids = !empty($ws_ids) ? $ws_ids : [];
        if (!is_array($category_meta) && !ll_can_category_generate_quiz($term, $min_word_count, $category_wordset_ids)) {
            continue;
        }
        $config = is_array($category_meta)
            ? [
                'prompt_type' => $category_meta['prompt_type'] ?? 'audio',
                'option_type' => $category_meta['option_type'] ?? ($category_meta['mode'] ?? 'image'),
                'learning_prompt_type' => $category_meta['learning_prompt_type'] ?? '',
                'learning_option_type' => $category_meta['learning_option_type'] ?? '',
                'learning_supported' => $category_meta['learning_supported'] ?? true,
                'self_check_supported' => $category_meta['self_check_supported'] ?? true,
                'sign_language_mode' => !empty($category_meta['sign_language_mode']),
                'use_titles' => $category_meta['use_titles'] ?? false,
            ]
            : (function_exists('ll_tools_get_category_quiz_config')
                ? ll_tools_get_category_quiz_config($term)
                : ['prompt_type' => 'audio', 'option_type' => 'image', 'learning_supported' => true, 'self_check_supported' => true, 'use_titles' => false]);
        $option_type = $config['option_type'] ?? 'image';
        $prompt_type = $config['prompt_type'] ?? 'audio';

        $name        = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
        $translation = '';
        if ($use_translations) {
            $t = get_term_meta($term_id, 'term_translation', true);
            if (empty($t) && $stored_term_id > 0 && $stored_term_id !== $term_id) {
                $t = get_term_meta($stored_term_id, 'term_translation', true);
            }
            if (!empty($t)) $translation = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }

        // Determine wordset slug / id for this specific item (do NOT leak values across items)
        $wordset_slug = '';
        $wordset_id_for_item = 0;
        if (!empty($opts['wordset'])) {
            // If filtered by wordset, use that slug
            $wordset_slug = sanitize_text_field($opts['wordset']);
            $wordset_id_for_item = $filtered_wordset_id;
        } else {
            // No filter: select default wordset for this category
            $default_ws_id = ll_get_default_wordset_id_for_category($name, $min_word_count);
            // Ensure the chosen default wordset can actually generate a playable quiz for this category.
            // If not, fall back to "no wordset filter" (use words across all wordsets).
            if ($default_ws_id > 0 && function_exists('ll_can_category_generate_quiz')) {
                if (!ll_can_category_generate_quiz($term, $min_word_count, [$default_ws_id])) {
                    $default_ws_id = 0;
                }
            }
            if ($default_ws_id > 0) {
                $default_term = get_term($default_ws_id, 'wordset');
                if ($default_term && !is_wp_error($default_term)) {
                    $wordset_slug = $default_term->slug;
                    $wordset_id_for_item = (int) $default_ws_id;
                    $category_wordset_ids = [$default_ws_id];
                }
            }
        }

        $gender_enabled = false;
        $gender_options = [];
        $gender_visual_config = [];
        $gender_supported = false;
        if ($wordset_id_for_item > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
            if (!isset($gender_config_cache[$wordset_id_for_item])) {
                $enabled = ll_tools_wordset_has_grammatical_gender($wordset_id_for_item);
                $options = ($enabled && function_exists('ll_tools_wordset_get_gender_options'))
                    ? ll_tools_wordset_get_gender_options($wordset_id_for_item)
                    : [];
                $visual_config = ($enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
                    ? ll_tools_wordset_get_gender_visual_config($wordset_id_for_item)
                    : [];
                $options = array_values(array_filter(array_map('strval', (array) $options), function ($val) {
                    return $val !== '';
                }));
                $support_map = [];
                if ($enabled && function_exists('ll_flashcards_build_categories')) {
                    [$ws_categories] = ll_flashcards_build_categories('', $use_translations, [$wordset_id_for_item]);
                    foreach ($ws_categories as $cat_meta) {
                        $cid = isset($cat_meta['id']) ? (int) $cat_meta['id'] : 0;
                        if ($cid > 0) {
                            $support_map[$cid] = !empty($cat_meta['gender_supported']);
                        }
                    }
                }
                $gender_config_cache[$wordset_id_for_item] = [
                    'enabled' => $enabled,
                    'options' => $options,
                    'visual_config' => $visual_config,
                    'support_map' => $support_map,
                ];
            }
            $cached = $gender_config_cache[$wordset_id_for_item];
            $gender_enabled = !empty($cached['enabled']);
            $gender_options = $cached['options'];
            $gender_visual_config = is_array($cached['visual_config'] ?? null) ? $cached['visual_config'] : [];
            $gender_supported = !empty($cached['support_map'][$term_id]);
        }

        $items[] = [
            'post_id'      => $post_id,
            'permalink'    => get_permalink($post_id),
            'slug'         => $term->slug,
            'term_id'      => $term_id,
            'name'         => $name,
            'translation'  => $translation,
            'display_name' => ($translation !== '' ? $translation : $name),
            'wordset_slug' => $wordset_slug,  // Added key
            'wordset_id'   => $wordset_id_for_item,
            'autoplay_text_audio_answer_options' => ($wordset_id_for_item > 0 && function_exists('ll_tools_should_autoplay_text_audio_answer_options'))
                ? ll_tools_should_autoplay_text_audio_answer_options([$wordset_id_for_item])
                : false,
            'display_mode' => $option_type,
            'option_type'  => $option_type,
            'prompt_type'  => $prompt_type,
            'learning_prompt_type' => (string) ($config['learning_prompt_type'] ?? ''),
            'learning_option_type' => (string) ($config['learning_option_type'] ?? ''),
            'learning_supported' => $config['learning_supported'] ?? true,
            'self_check_supported' => $config['self_check_supported'] ?? true,
            'sign_language_mode' => !empty($config['sign_language_mode']),
            'use_titles' => !empty($config['use_titles']),
            'word_count' => is_array($category_meta) ? max(0, (int) ($category_meta['word_count'] ?? 0)) : 0,
            'aspect_bucket' => is_array($category_meta) ? (string) ($category_meta['aspect_bucket'] ?? 'no-image') : 'no-image',
            'gender_enabled' => $gender_enabled,
            'gender_options' => $gender_options,
            'gender_visual_config' => $gender_visual_config,
            'gender_supported' => $gender_supported,
        ];
    }

    usort($items, function ($a, $b) {
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        }
        return strnatcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    ll_tools_quiz_pages_data_cache_set($cache_key, $items);

    return $items;
}

/**
 * Finds the earliest wordset that can still generate a quiz for a category.
 *
 * Source categories may now be isolated into per-wordset copies, so the lookup
 * must respect the effective category for each candidate wordset instead of
 * counting only direct term membership on the source category itself.
 *
 * @param mixed $category
 * @param int   $min_word_count Minimum words required
 * @return int Wordset ID or 0 if none found
 */
function ll_get_default_wordset_id_for_category($category, int $min_word_count = 5): int {
    $cat_term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (!($cat_term instanceof WP_Term) || is_wp_error($cat_term)) {
        return 0;
    }

    $term_id = (int) $cat_term->term_id;
    $category_version = function_exists('ll_tools_get_category_cache_version')
        ? max(1, (int) ll_tools_get_category_cache_version($term_id))
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $cache_key = 'll_default_quiz_ws_' . md5(wp_json_encode([
        'term_id' => $term_id,
        'min_words' => $min_word_count,
        'category_version' => $category_version,
        'wordset_epoch' => $wordset_epoch,
        'user_id' => (int) get_current_user_id(),
        'schema' => 1,
    ]));
    $cache_group = 'll_tools_quiz_pages';
    $cache_ttl = HOUR_IN_SECONDS;

    static $request_cache = [];
    if (array_key_exists($cache_key, $request_cache)) {
        return (int) $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached === false) {
        $cached = get_transient($cache_key);
    }
    if (is_array($cached) && isset($cached['__ll_default_quiz_wordset_cache'])) {
        $default_id = max(0, (int) ($cached['wordset_id'] ?? 0));
        $request_cache[$cache_key] = $default_id;
        return $default_id;
    }

    $store_result = static function (int $wordset_id) use ($cache_key, $cache_group, $cache_ttl, &$request_cache): int {
        $wordset_id = max(0, $wordset_id);
        $payload = [
            '__ll_default_quiz_wordset_cache' => 1,
            'wordset_id' => $wordset_id,
        ];

        $request_cache[$cache_key] = $wordset_id;
        wp_cache_set($cache_key, $payload, $cache_group, $cache_ttl);
        set_transient($cache_key, $payload, $cache_ttl);

        return $wordset_id;
    };

    // If this category is already an isolated copy, keep its owner wordset as
    // the preferred default when it can generate a quiz.
    $owner_wordset_id = function_exists('ll_tools_get_category_wordset_owner_id')
        ? (int) ll_tools_get_category_wordset_owner_id($cat_term)
        : 0;
    if (
        $owner_wordset_id > 0
        && function_exists('ll_can_category_generate_quiz')
        && ll_can_category_generate_quiz($cat_term, $min_word_count, [$owner_wordset_id])
    ) {
        return $store_result($owner_wordset_id);
    }

    // Get all wordset IDs ordered by term_id (assuming lower IDs are older).
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'term_id',
        'order'      => 'ASC',
        'fields'     => 'ids',
    ]);
    if (empty($wordsets) || is_wp_error($wordsets)) {
        return $store_result(0);
    }

    foreach ($wordsets as $ws_id) {
        $ws_id = (int) $ws_id;
        if ($ws_id <= 0 || $ws_id === $owner_wordset_id) {
            continue;
        }

        if (function_exists('ll_can_category_generate_quiz') && ll_can_category_generate_quiz($cat_term, $min_word_count, [$ws_id])) {
            return $store_result($ws_id);
        }
    }

    return $store_result(0);
}

/**
 * Ensures the flashcard overlay shell exists and assets are enqueued.
 * Called only when [quiz_pages_grid popup="yes"] is used.
 *
 * @param string $wordset_spec Optional wordset filter (slug|name|id) to align popup categories/words.
 * @param array  $quiz_items   Optional precomputed quiz-page rows to avoid rebuilding category metadata.
 */
function ll_qpg_bootstrap_flashcards_for_grid($wordset_spec = '', array $quiz_items = []) {
    $wordset_spec = sanitize_text_field((string) $wordset_spec);
    $wordset_ids  = function_exists('ll_flashcards_resolve_wordset_ids')
        ? ll_flashcards_resolve_wordset_ids($wordset_spec, false)
        : [];
    $wordset_ids = array_map('intval', (array) $wordset_ids);
    $wordset_ids = array_values(array_filter(array_unique($wordset_ids), function ($id) { return $id > 0; }));
    $use_translations = function_exists('ll_flashcards_should_use_translations')
        ? ll_flashcards_should_use_translations($wordset_ids)
        : false;

    $categories = ll_qpg_build_flashcard_categories_from_quiz_items($quiz_items);
    if (empty($categories) && function_exists('ll_flashcards_build_categories')) {
        [$categories] = ll_flashcards_build_categories('', $use_translations, $wordset_ids);
    }
    if (empty($categories)) {
        $all_terms = get_terms(['taxonomy' => 'word-category', 'hide_empty' => false]);
        if (is_wp_error($all_terms)) $all_terms = [];
        $categories = array_map(function($t){
            return [
                'id'          => $t->term_id,
                'slug'        => $t->slug,
                'name'        => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'translation' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'mode'        => 'image',
                'option_type' => 'image',
                'prompt_type' => 'audio',
            ];
        }, $all_terms);
    }

    $atts = ['mode' => 'random', 'wordset' => $wordset_spec, 'wordset_fallback' => false];
    $localized_wordset_ids = $wordset_ids;
    ll_flashcards_enqueue_and_localize(array_merge($atts, ['wordset_ids_for_popup' => $localized_wordset_ids]), $categories, false, [], '');

    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');

    echo '<style id="ll-qpg-popup-zfix">
      body.ll-qpg-popup-active #ll-tools-flashcard-container,
      body.ll-qpg-popup-active #ll-tools-flashcard-popup,
      body.ll-qpg-popup-active #ll-tools-flashcard-quiz-popup{position:fixed;inset:0;z-index:999999}
      body.ll-qpg-popup-active #ll-tools-flashcard-content{flex:1 1 auto;min-height:0;height:auto}
    </style>';

    ?>
    <script>
    (function(){
      if (window.__LL_QPG_DELEGATED_BOUND) { return; }
      window.__LL_QPG_DELEGATED_BOUND = true;
      function openFromAnchor(a){
            var cat = a.getAttribute('data-category') || '';
            var wordsetId = a.getAttribute('data-wordset-id') || '';
            var wordsetSlug = a.getAttribute('data-wordset') || '';
            var mode = a.getAttribute('data-mode') || 'practice';
            var displayModeHint = a.getAttribute('data-display-mode') || '';
            var promptTypeHint = a.getAttribute('data-prompt-type') || '';
            var optionTypeHint = a.getAttribute('data-option-type') || '';
            var autoplayTextAudioAnswerOptionsAttr = a.getAttribute('data-autoplay-text-audio-answer-options');
            var selfCheckSupportedAttr = a.getAttribute('data-self-check-supported');
            var genderEnabledAttr = a.getAttribute('data-gender-enabled');
            var genderSupportedAttr = a.getAttribute('data-gender-supported');
            var genderOptionsAttr = a.getAttribute('data-gender-options') || '';
            var genderVisualConfigAttr = a.getAttribute('data-gender-visual-config') || '';
            if (!cat) return;

            var autoplayTextAudioAnswerOptions = (autoplayTextAudioAnswerOptionsAttr === '1' || autoplayTextAudioAnswerOptionsAttr === 'true');
            var selfCheckSupported = (selfCheckSupportedAttr === null)
                ? true
                : (selfCheckSupportedAttr === '1' || selfCheckSupportedAttr === 'true');
            var genderEnabled = (genderEnabledAttr === '1' || genderEnabledAttr === 'true');
            var genderSupported = (genderSupportedAttr === '1' || genderSupportedAttr === 'true');
            var genderOptions = [];
            var genderVisualConfig = null;
            if (genderOptionsAttr) {
                try {
                    var parsed = JSON.parse(genderOptionsAttr);
                    if (Array.isArray(parsed)) {
                        genderOptions = parsed;
                    }
                } catch (_) {}
            }
            if (genderVisualConfigAttr) {
                try {
                    var parsedVisual = JSON.parse(genderVisualConfigAttr);
                    if (parsedVisual && typeof parsedVisual === 'object') {
                        genderVisualConfig = parsedVisual;
                    }
                } catch (_) {}
            }

            try {
                if (window.llToolsFlashcardsData) {
                    var found = null;
                    if (window.llToolsFlashcardsData.categories && window.llToolsFlashcardsData.categories.length) {
                        for (var i=0;i<window.llToolsFlashcardsData.categories.length;i++){
                            var c = window.llToolsFlashcardsData.categories[i];
                            if (c && c.name === cat) { found = c; break; }
                        }
                    }
                    if (!found) {
                        (window.llToolsFlashcardsData.categories || (window.llToolsFlashcardsData.categories = [])).push({
                            id: 0,
                            slug: '',
                            name: cat,
                            translation: cat,
                            mode: displayModeHint || 'image',
                            option_type: optionTypeHint || displayModeHint || 'image',
                            prompt_type: promptTypeHint || 'audio',
                            self_check_supported: selfCheckSupported,
                            gender_supported: genderSupported
                        });
                    } else {
                        if (displayModeHint) { found.mode = displayModeHint; }
                        if (optionTypeHint) { found.option_type = optionTypeHint; }
                        if (promptTypeHint) { found.prompt_type = promptTypeHint; }
                        found.self_check_supported = selfCheckSupported;
                        found.gender_supported = genderSupported;
                    }
                }
            } catch (e) {}

            if (typeof window.llOpenFlashcardForCategory === 'function') {
                var opts = {
                    mode: mode,
                    genderEnabled: genderEnabled,
                    genderSupported: genderSupported,
                    genderOptions: genderOptions,
                    genderVisualConfig: genderVisualConfig,
                    autoplayTextAudioAnswerOptions: autoplayTextAudioAnswerOptions,
                    selfCheckSupported: selfCheckSupported,
                    triggerEl: a
                };
                if (wordsetId) {
                    opts.wordsetId = wordsetId;
                } else if (wordsetSlug) {
                    opts.wordset = wordsetSlug;
                }
                window.llOpenFlashcardForCategory(cat, opts);
            } else {
                console.error('llOpenFlashcardForCategory not found');
            }
      }

      function vanillaBind(){
        document.removeEventListener('click', vanillaHandler, true);
        document.addEventListener('click', vanillaHandler, true);
      }
      function vanillaHandler(e){
        var a = e.target.closest && e.target.closest('.ll-quiz-page-trigger');
        if (!a) return;
        e.preventDefault();
        if (typeof e.stopImmediatePropagation === 'function') {
          e.stopImmediatePropagation();
        } else {
          e.stopPropagation();
        }
        openFromAnchor(a);
      }

      function jqueryBind($){
        $(document).off('click.llqpg', '.ll-quiz-page-trigger')
                   .on('click.llqpg', '.ll-quiz-page-trigger', function(ev){
                      ev.preventDefault(); ev.stopPropagation();
                      openFromAnchor(this);
                   });
        $(document).off('keydown.llqpg', '.ll-quiz-page-trigger')
                   .on('keydown.llqpg', '.ll-quiz-page-trigger', function(ev){
                      if (ev.key === ' ' || ev.key === 'Enter') { ev.preventDefault(); $(this).trigger('click'); }
                   });
      }

      function init(){
        vanillaBind();
        if (window.jQuery) { jqueryBind(window.jQuery); }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
      } else {
        init();
      }
    })();
    </script>
    <?php
}

function ll_qpg_build_flashcard_categories_from_quiz_items(array $quiz_items): array {
    $categories = [];
    $seen = [];

    foreach ($quiz_items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $term_id = isset($item['term_id']) ? (int) $item['term_id'] : 0;
        if ($term_id <= 0 || isset($seen[$term_id])) {
            continue;
        }

        $name = html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($name === '') {
            continue;
        }

        $translation = html_entity_decode((string) ($item['translation'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($translation === '') {
            $translation = $name;
        }

        $option_type = (string) ($item['option_type'] ?? ($item['display_mode'] ?? 'image'));
        if ($option_type === '') {
            $option_type = 'image';
        }

        $aspect_bucket = (string) ($item['aspect_bucket'] ?? 'no-image');
        if ($aspect_bucket === '') {
            $aspect_bucket = 'no-image';
        }

        $categories[] = [
            'id' => $term_id,
            'slug' => (string) ($item['slug'] ?? ''),
            'name' => $name,
            'translation' => $translation,
            'mode' => (string) ($item['display_mode'] ?? $option_type),
            'option_type' => $option_type,
            'prompt_type' => (string) ($item['prompt_type'] ?? 'audio'),
            'learning_prompt_type' => (string) ($item['learning_prompt_type'] ?? ''),
            'learning_option_type' => (string) ($item['learning_option_type'] ?? ''),
            'learning_supported' => !array_key_exists('learning_supported', $item) || !empty($item['learning_supported']),
            'self_check_supported' => !array_key_exists('self_check_supported', $item) || !empty($item['self_check_supported']),
            'sign_language_mode' => !empty($item['sign_language_mode']),
            'use_titles' => !empty($item['use_titles']),
            'word_count' => max(0, (int) ($item['word_count'] ?? 0)),
            'gender_word_count' => 0,
            'gender_supported' => !empty($item['gender_supported']),
            'aspect_bucket' => $aspect_bucket,
        ];
        $seen[$term_id] = true;
    }

    return $categories;
}

function ll_qpg_flashcard_shell_reset_render_guard(): void {
    $GLOBALS['ll_qpg_flashcard_shell_rendered_once'] = false;
}

function ll_qpg_flashcard_shell_is_rendered_once(): bool {
    return !empty($GLOBALS['ll_qpg_flashcard_shell_rendered_once']);
}

function ll_qpg_flashcard_shell_mark_rendered_once(): void {
    $GLOBALS['ll_qpg_flashcard_shell_rendered_once'] = true;
}

ll_qpg_flashcard_shell_reset_render_guard();

/** Prints the flashcard overlay DOM (same IDs the widget expects) once. */
function ll_qpg_print_flashcard_shell_once() {
    if (ll_qpg_flashcard_shell_is_rendered_once()) { return; }
    ll_qpg_flashcard_shell_mark_rendered_once();
    $widget_rendered = function_exists('ll_tools_flashcard_widget_is_rendered_once')
        && ll_tools_flashcard_widget_is_rendered_once();
    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    if (!$widget_rendered) :
    ?>
    <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container" style="display:none;">
      <?php
      ll_tools_render_flashcard_overlay_shell([
          'include_category_selection' => false,
          'include_loading_status' => false,
          'show_category_display' => true,
          'category_label_text' => '',
          'mode_ui' => $mode_ui,
          'gender_mode_visible' => false,
          'mode_order' => ['learning', 'practice', 'listening', 'gender', 'self-check'],
          'listening_results_fallback' => __('Listen', 'll-tools-text-domain'),
      ]);
      ?>
    </div>
    <?php endif; ?>

    <?php ll_tools_render_flashcard_repeat_button_init_script(); ?>
    <script>
    (function($){
        function normalizeWordIdList(raw) {
            var values = [];
            if (Array.isArray(raw)) {
                values = raw;
            } else if (typeof raw === 'string' && raw.trim() !== '') {
                var trimmed = raw.trim();
                if (trimmed.charAt(0) === '[') {
                    try {
                        var parsed = JSON.parse(trimmed);
                        if (Array.isArray(parsed)) {
                            values = parsed;
                        }
                    } catch (_) {
                        values = [];
                    }
                }
                if (!values.length) {
                    values = trimmed.split(/[\s,|]+/);
                }
            }

            var seen = {};
            var ids = [];
            for (var i = 0; i < values.length; i++) {
                var id = parseInt(values[i], 10);
                if (id > 0 && !seen[id]) {
                    seen[id] = true;
                    ids.push(id);
                }
            }
            return ids;
        }

        function parseBooleanFlag(raw, fallback) {
            if (typeof raw === 'boolean') {
                return raw;
            }
            if (typeof raw === 'number') {
                return raw !== 0;
            }
            if (raw === null || typeof raw === 'undefined') {
                return !!fallback;
            }
            var normalized = String(raw || '').trim().toLowerCase();
            if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') {
                return true;
            }
            if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off') {
                return false;
            }
            return !!fallback;
        }

        window.llOpenFlashcardForCategory = function(catName, wordset, mode){
            if (!catName) return;

            var opts = null;
            if (wordset && typeof wordset === 'object') {
                opts = wordset;
                wordset = '';
                mode = (opts && (opts.mode || opts.quiz_mode)) || mode || 'practice';
                if (opts) {
                    wordset = opts.wordsetId || opts.wordset_id || opts.wordset || '';
                    try {
                        if (!wordset && opts.triggerEl && opts.triggerEl.getAttribute) {
                            wordset = opts.triggerEl.getAttribute('data-wordset-id') ||
                                opts.triggerEl.getAttribute('data-wordset') || '';
                        }
                        if ((!mode || mode === 'practice') && opts.triggerEl && opts.triggerEl.getAttribute) {
                            mode = opts.triggerEl.getAttribute('data-mode') || mode;
                        }
                    } catch (_) {}
                }
            }

            mode = mode || 'practice';

            if (wordset && typeof wordset !== 'string' && typeof wordset !== 'number') {
                wordset = '';
            }
            wordset = String(wordset || '');

            var parsedWordsetIds = [];
            var wordsetIsNumeric = wordset !== '' && !isNaN(parseInt(wordset, 10));
            if (wordsetIsNumeric) {
                var wid = parseInt(wordset, 10);
                if (wid > 0) { parsedWordsetIds.push(wid); }
            }

            var previousWordset = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.wordset !== undefined)
                ? String(window.llToolsFlashcardsData.wordset || '')
                : '';
            var currentWordset = wordset;
            var wordsetChanged = (previousWordset !== currentWordset);
            var launchContext = (opts && typeof opts.launchContext === 'string')
                ? String(opts.launchContext || '').toLowerCase()
                : '';
            if (!launchContext && opts && opts.triggerEl) {
                try {
                    var triggerEl = opts.triggerEl;
                    var isVocabLessonTrigger = !!(
                        (triggerEl.classList && triggerEl.classList.contains('ll-vocab-lesson-mode-button')) ||
                        (triggerEl.closest && triggerEl.closest('[data-ll-vocab-lesson], .ll-vocab-lesson-page'))
                    );
                    launchContext = isVocabLessonTrigger ? 'vocab_lesson' : 'quiz_pages';
                } catch (_) {}
            }

            var orderedWordIds = normalizeWordIdList(opts && (opts.orderedWordIds || opts.ordered_word_ids));
            var sessionWordIds = normalizeWordIdList(opts && (opts.sessionWordIds || opts.session_word_ids));
            var preserveWordOrder = parseBooleanFlag(opts && (typeof opts.preserveWordOrder !== 'undefined' ? opts.preserveWordOrder : opts.preserve_word_order), orderedWordIds.length > 0 && mode === 'listening');
            var listeningRapidMode = parseBooleanFlag(opts && (typeof opts.listeningRapidMode !== 'undefined' ? opts.listeningRapidMode : opts.listening_rapid_mode), false);
            if (!orderedWordIds.length && opts && opts.triggerEl && opts.triggerEl.getAttribute) {
                orderedWordIds = normalizeWordIdList(opts.triggerEl.getAttribute('data-ordered-word-ids') || '');
            }
            if (!sessionWordIds.length && orderedWordIds.length) {
                sessionWordIds = orderedWordIds.slice();
            }
            if (opts && opts.triggerEl && opts.triggerEl.getAttribute) {
                var preserveAttr = opts.triggerEl.getAttribute('data-preserve-word-order');
                if (preserveAttr !== null) {
                    preserveWordOrder = parseBooleanFlag(preserveAttr, preserveWordOrder);
                }
            }
            if (orderedWordIds.length && mode === 'listening') {
                preserveWordOrder = true;
            }

            if (window.llToolsFlashcardsData) {
                var previousSessionWordIds = normalizeWordIdList(window.llToolsFlashcardsData.sessionWordIds || window.llToolsFlashcardsData.session_word_ids);
                var previousOrderedWordIds = normalizeWordIdList(window.llToolsFlashcardsData.orderedWordIds || window.llToolsFlashcardsData.ordered_word_ids);
                window.llToolsFlashcardsData.wordset = currentWordset;
                window.llToolsFlashcardsData.wordsetFallback = false;
                window.llToolsFlashcardsData.quiz_mode = mode;
                window.llToolsFlashcardsData.wordsetIds = parsedWordsetIds.length ? parsedWordsetIds : [];
                window.llToolsFlashcardsData.launchContext = launchContext;
                window.llToolsFlashcardsData.launch_context = launchContext;
                window.llToolsFlashcardsData.sessionWordIds = sessionWordIds.slice();
                window.llToolsFlashcardsData.session_word_ids = sessionWordIds.slice();
                window.llToolsFlashcardsData.orderedWordIds = orderedWordIds.slice();
                window.llToolsFlashcardsData.ordered_word_ids = orderedWordIds.slice();
                window.llToolsFlashcardsData.preserveWordOrder = !!preserveWordOrder;
                window.llToolsFlashcardsData.preserve_word_order = !!preserveWordOrder;
                window.llToolsFlashcardsData.preserveCategoryOrder = !!preserveWordOrder;
                window.llToolsFlashcardsData.preserve_category_order = !!preserveWordOrder;
                window.llToolsFlashcardsData.listeningRapidMode = !!listeningRapidMode;
                window.llToolsFlashcardsData.listening_rapid_mode = !!listeningRapidMode;
                if (!window.llToolsFlashcardsData.lastLaunchPlan || typeof window.llToolsFlashcardsData.lastLaunchPlan !== 'object') {
                    window.llToolsFlashcardsData.lastLaunchPlan = {};
                }
                window.llToolsFlashcardsData.lastLaunchPlan.session_word_ids = sessionWordIds.slice();
                window.llToolsFlashcardsData.lastLaunchPlan.ordered_word_ids = orderedWordIds.slice();
                window.llToolsFlashcardsData.lastLaunchPlan.preserve_word_order = !!preserveWordOrder;
                window.llToolsFlashcardsData.lastLaunchPlan.listening_rapid_mode = !!listeningRapidMode;
                if (sessionWordIds.length) {
                    window.llToolsFlashcardsData.lastLaunchPlan.estimated_results_total = sessionWordIds.length;
                } else {
                    delete window.llToolsFlashcardsData.lastLaunchPlan.estimated_results_total;
                }
                window.llToolsFlashcardsData.last_launch_plan = window.llToolsFlashcardsData.lastLaunchPlan;
                var sessionScopeChanged = previousSessionWordIds.join(',') !== sessionWordIds.join(',');
                var orderScopeChanged = previousOrderedWordIds.join(',') !== orderedWordIds.join(',');
                if (opts && typeof opts.autoplayTextAudioAnswerOptions !== 'undefined') {
                    window.llToolsFlashcardsData.autoplayTextAudioAnswerOptions = !!opts.autoplayTextAudioAnswerOptions;
                    window.llToolsFlashcardsData.autoplay_text_audio_answer_options = !!opts.autoplayTextAudioAnswerOptions;
                }
                if (mode === 'gender') {
                    delete window.llToolsFlashcardsData.genderSessionPlan;
                    delete window.llToolsFlashcardsData.genderSessionPlanArmed;
                    delete window.llToolsFlashcardsData.gender_session_plan_armed;
                    window.llToolsFlashcardsData.genderLaunchSource = 'direct';
                }
            }

            var genderEnabled = (opts && typeof opts.genderEnabled !== 'undefined') ? !!opts.genderEnabled : null;
            var genderSupported = (opts && typeof opts.genderSupported !== 'undefined') ? !!opts.genderSupported : null;
            var genderOptions = (opts && Array.isArray(opts.genderOptions)) ? opts.genderOptions : null;
            var genderVisualConfig = (opts && opts.genderVisualConfig && typeof opts.genderVisualConfig === 'object')
                ? opts.genderVisualConfig
                : null;
            var autoplayTextAudioAnswerOptions = (opts && typeof opts.autoplayTextAudioAnswerOptions !== 'undefined')
                ? !!opts.autoplayTextAudioAnswerOptions
                : null;
            var selfCheckSupported = (opts && typeof opts.selfCheckSupported !== 'undefined') ? !!opts.selfCheckSupported : null;
            if (opts && opts.triggerEl && opts.triggerEl.getAttribute) {
                if (autoplayTextAudioAnswerOptions === null) {
                    var ataAttr = opts.triggerEl.getAttribute('data-autoplay-text-audio-answer-options');
                    if (ataAttr !== null) {
                        autoplayTextAudioAnswerOptions = (ataAttr === '1' || ataAttr === 'true');
                    }
                }
                if (genderEnabled === null) {
                    var geAttr = opts.triggerEl.getAttribute('data-gender-enabled');
                    if (geAttr !== null) {
                        genderEnabled = (geAttr === '1' || geAttr === 'true');
                    }
                }
                if (selfCheckSupported === null) {
                    var scAttr = opts.triggerEl.getAttribute('data-self-check-supported');
                    if (scAttr !== null) {
                        selfCheckSupported = (scAttr === '1' || scAttr === 'true');
                    }
                }
                if (genderSupported === null) {
                    var gsAttr = opts.triggerEl.getAttribute('data-gender-supported');
                    if (gsAttr !== null) {
                        genderSupported = (gsAttr === '1' || gsAttr === 'true');
                    }
                }
                if (genderOptions === null) {
                    var goAttr = opts.triggerEl.getAttribute('data-gender-options') || '';
                    if (goAttr) {
                        try {
                            var parsedOpts = JSON.parse(goAttr);
                            if (Array.isArray(parsedOpts)) {
                                genderOptions = parsedOpts;
                            }
                        } catch (_) {}
                    }
                }
                if (genderVisualConfig === null) {
                    var gvAttr = opts.triggerEl.getAttribute('data-gender-visual-config') || '';
                    if (gvAttr) {
                        try {
                            var parsedVisualCfg = JSON.parse(gvAttr);
                            if (parsedVisualCfg && typeof parsedVisualCfg === 'object') {
                                genderVisualConfig = parsedVisualCfg;
                            }
                        } catch (_) {}
                    }
                }
            }
            if (genderEnabled === false && !Array.isArray(genderOptions)) {
                genderOptions = [];
            }

            if (window.llToolsFlashcardsData) {
                if (autoplayTextAudioAnswerOptions !== null) {
                    window.llToolsFlashcardsData.autoplayTextAudioAnswerOptions = autoplayTextAudioAnswerOptions;
                    window.llToolsFlashcardsData.autoplay_text_audio_answer_options = autoplayTextAudioAnswerOptions;
                }
                if (genderEnabled !== null) {
                    window.llToolsFlashcardsData.genderEnabled = genderEnabled;
                    window.llToolsFlashcardsData.genderWordsetId = parsedWordsetIds.length ? parsedWordsetIds[0] : 0;
                }
                if (Array.isArray(genderOptions)) {
                    window.llToolsFlashcardsData.genderOptions = genderOptions;
                }
                if (genderVisualConfig !== null) {
                    window.llToolsFlashcardsData.genderVisualConfig = genderVisualConfig;
                }
                if (genderSupported !== null && window.llToolsFlashcardsData.categories) {
                    for (var i = 0; i < window.llToolsFlashcardsData.categories.length; i++) {
                        var cat = window.llToolsFlashcardsData.categories[i];
                        if (cat && cat.name === catName) {
                            cat.gender_supported = genderSupported;
                            break;
                        }
                    }
                }
                if (selfCheckSupported !== null && window.llToolsFlashcardsData.categories) {
                    for (var i = 0; i < window.llToolsFlashcardsData.categories.length; i++) {
                        var cat = window.llToolsFlashcardsData.categories[i];
                        if (cat && cat.name === catName) {
                            cat.self_check_supported = selfCheckSupported;
                            break;
                        }
                    }
                }
            }

            if ((wordsetChanged || sessionScopeChanged || orderScopeChanged) && window.FlashcardLoader) {
                if (typeof window.FlashcardLoader.resetCacheForNewWordset === 'function') {
                    window.FlashcardLoader.resetCacheForNewWordset();
                } else if (Array.isArray(window.FlashcardLoader.loadedCategories)) {
                    window.FlashcardLoader.loadedCategories.length = 0;
                }
            }

            // Prevent multiple rapid opens triggering multiple sessions
            if (window.__LL_QPG_OPEN_IN_PROGRESS) {
                return;
            }
            window.__LL_QPG_OPEN_IN_PROGRESS = true;

            try { document.body.classList.add('ll-qpg-popup-active'); } catch (_) {}
            try { $('body').addClass('ll-qpg-popup-active'); } catch (_) {}
            $('body').addClass('ll-tools-flashcard-open');
            $('#ll-tools-flashcard-container').show();
            $('#ll-tools-flashcard-popup').show();
            $('#ll-tools-flashcard-quiz-popup').css('display', 'flex');
            try {
                var p = initFlashcardWidget([catName], mode);
                if (p && typeof p.finally === 'function') {
                    p.finally(function(){ window.__LL_QPG_OPEN_IN_PROGRESS = false; });
                } else {
                    setTimeout(function(){ window.__LL_QPG_OPEN_IN_PROGRESS = false; }, 0);
                }
            } catch (e) {
                console.error('initFlashcardWidget failed', e);
                try { document.body.classList.remove('ll-qpg-popup-active'); } catch (_) {}
                try { $('body').removeClass('ll-qpg-popup-active'); } catch (_) {}
                window.__LL_QPG_OPEN_IN_PROGRESS = false;
            }
        };
    })(jQuery);
    </script>
    <?php
}

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_grid]
 * Attributes:
 *   - wordset  (id|slug|name)
 *   - columns
 *   - popup    ("yes" to open flashcard overlay inline)
 *   - order / order_dir (kept for backward compat; ignored)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_grid_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'wordset'   => '',
            'columns'   => '',
            'popup'     => 'no',
            'mode'      => 'practice',
            'order'     => 'title',
            'order_dir' => 'ASC',
        ],
        $atts,
        'quiz_pages_grid'
    );

    $filter = [];
    if (trim($atts['wordset']) !== '') {
        $filter['wordset'] = $atts['wordset'];
    }

    $items = ll_get_all_quiz_pages_data($filter);
    if (empty($items)) {
        return '<p>' . esc_html__('No quizzes found.', 'll-tools-text-domain') . '</p>';
    }

    $use_popup = (strtolower($atts['popup']) === 'yes');
    $grid_id   = 'll-quiz-pages-grid-' . wp_generate_uuid4();
    $quiz_mode = in_array($atts['mode'], ['practice', 'learning', 'self-check'], true) ? $atts['mode'] : 'practice';

    if ($use_popup) {
        if (function_exists('ll_qp_enqueue_popup_assets')) {
            ll_qp_enqueue_popup_assets();
        }
        ll_qpg_bootstrap_flashcards_for_grid($atts['wordset'], $items);
    }

    $style = '';
    if ($atts['columns'] !== '' && is_numeric($atts['columns']) && (int)$atts['columns'] > 0) {
        $cols  = (int) $atts['columns'];
        $style = ' style="grid-template-columns: repeat(' . $cols . ', minmax(220px, 1fr));"';
    }

    ob_start();

    echo '<div id="' . esc_attr($grid_id) . '" class="ll-quiz-pages-grid"' . $style . '>';

    foreach ($items as $it) {
        $title     = $it['display_name'];
        $permalink = $it['permalink'];
        $raw_name  = $it['name'];

        if (!$use_popup) {
            $qs = ($quiz_mode !== 'practice') ? '?mode=' . esc_attr($quiz_mode) : '';
            echo '<a class="ll-quiz-page-card ll-quiz-page-link"'
            . ' href="' . esc_url($permalink . $qs) . '"'
            . ' aria-label="' . esc_attr($title) . '">';
            echo '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        } else {
            // For popup, add wordset and mode data attributes if set
            $ws_attr = (!empty($it['wordset_slug'])) ? ' data-wordset="' . esc_attr($it['wordset_slug']) . '"' : '';
            $ws_id_attr = (!empty($it['wordset_id'])) ? ' data-wordset-id="' . (int) $it['wordset_id'] . '"' : '';
            $autoplay_text_audio_attr = ' data-autoplay-text-audio-answer-options="' . (!empty($it['autoplay_text_audio_answer_options']) ? '1' : '0') . '"';
            $mode_hint = (!empty($it['display_mode'])) ? ' data-display-mode="' . esc_attr($it['display_mode']) . '"' : '';
            $mode_attr = ' data-mode="' . esc_attr($quiz_mode) . '"';
            $prompt_attr = (!empty($it['prompt_type'])) ? ' data-prompt-type="' . esc_attr($it['prompt_type']) . '"' : '';
            $option_attr = (!empty($it['option_type'])) ? ' data-option-type="' . esc_attr($it['option_type']) . '"' : '';
            $self_check_attr = array_key_exists('self_check_supported', $it)
                ? ' data-self-check-supported="' . (!empty($it['self_check_supported']) ? '1' : '0') . '"'
                : '';
            $gender_enabled_attr = ' data-gender-enabled="' . (!empty($it['gender_enabled']) ? '1' : '0') . '"';
            $gender_supported_attr = ' data-gender-supported="' . (!empty($it['gender_supported']) ? '1' : '0') . '"';
            $gender_options_attr = ' data-gender-options="' . esc_attr(wp_json_encode($it['gender_options'] ?? [])) . '"';
            $gender_visual_attr = ' data-gender-visual-config="' . esc_attr(wp_json_encode($it['gender_visual_config'] ?? [])) . '"';
            /* translators: %s: quiz card title */
            $start_label = sprintf(__('Start %s', 'll-tools-text-domain'), $title);
            echo '<a class="ll-quiz-page-card ll-quiz-page-trigger"'
            . ' href="#" role="button"'
            . ' aria-label="' . esc_attr($start_label) . '"'
            . ' data-category="' . esc_attr($raw_name) . '"'
            . ' data-url="' . esc_url($permalink) . '"'
            . $ws_attr
            . $ws_id_attr
            . $autoplay_text_audio_attr
            . $mode_hint
            . $mode_attr
            . $prompt_attr
            . $option_attr
            . $self_check_attr
            . $gender_enabled_attr
            . $gender_supported_attr
            . $gender_options_attr
            . $gender_visual_attr
            . '>';
            echo   '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        }
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_grid', 'll_quiz_pages_grid_shortcode');

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_dropdown]
 * Attributes:
 *   - wordset (id|slug|name)   ← NEW
 *   - placeholder
 *   - button ("yes" to show a Go button; default is navigate on change)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_dropdown_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'wordset'     => '', // NEW
            'placeholder' => __('Select a quiz…', 'll-tools-text-domain'),
            'button'      => 'no',
        ],
        $atts,
        'quiz_pages_dropdown'
    );

    ll_enqueue_asset_by_timestamp('/js/quiz-pages-shortcodes.js', 'll-quiz-pages-shortcodes-js', [], true);

    $filter = [];
    if (trim($atts['wordset']) !== '') {
        $filter['wordset'] = $atts['wordset'];
    }

    $items = ll_get_all_quiz_pages_data($filter);

    ob_start();

    if (empty($items)) {
        echo '<p>' . esc_html__('No quiz pages are available yet.', 'll-tools-text-domain') . '</p>';
        return ob_get_clean();
    }

    $select_id  = 'll-quiz-pages-select-' . wp_generate_uuid4();
    $has_button = strtolower($atts['button']) === 'yes';

    echo '<div class="ll-quiz-pages-dropdown">';
    echo '<label class="screen-reader-text" for="' . esc_attr($select_id) . '">'
        . esc_html__('Quiz selection', 'll-tools-text-domain') . '</label>';

    echo '<select id="' . esc_attr($select_id) . '" class="ll-quiz-pages-select"'
        . ($has_button ? '' : ' data-ll-quiz-pages-auto-go="1"') . '>';

    echo '<option value="">' . esc_html($atts['placeholder']) . '</option>';

    foreach ($items as $it) {
        echo '<option value="' . esc_url($it['permalink']) . '">' . esc_html($it['display_name']) . '</option>';
    }

    echo '</select>';

    if ($has_button) {
        $btn_id = 'll-quiz-pages-go-' . wp_generate_uuid4();
        echo '<button type="button" id="' . esc_attr($btn_id) . '" class="ll-quiz-pages-go" data-ll-quiz-pages-go>' . esc_html__('Go', 'll-tools-text-domain') . '</button>';
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_dropdown', 'll_quiz_pages_dropdown_shortcode');

/**
 * Conditionally enqueue styles used by these shortcodes (grid/dropdown only).
 * The flashcard overlay uses its own stylesheet in popup mode.
 */
function ll_maybe_enqueue_quiz_pages_styles() {
    if (!is_singular()) return;
    $post = get_post(); if (!$post) return;

    if ( has_shortcode($post->post_content, 'quiz_pages_grid') ||
         has_shortcode($post->post_content, 'quiz_pages_dropdown') ) {
        ll_enqueue_asset_by_timestamp('/css/quiz-pages-style.css', 'll-quiz-pages-style');
    }
}
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_quiz_pages_styles');
