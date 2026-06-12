<?php
// /includes/user-study.php
if (!defined('WPINC')) { die; }

define('LL_TOOLS_USER_WORDSET_META', 'll_user_study_wordset');
define('LL_TOOLS_USER_CATEGORY_META', 'll_user_study_categories');
define('LL_TOOLS_USER_STARRED_META', 'll_user_study_starred');
define('LL_TOOLS_USER_FAST_TRANSITIONS_META', 'll_user_fast_transitions');

if (!function_exists('ll_tools_normalize_star_mode')) {
    function ll_tools_normalize_star_mode($mode): string {
        $mode = is_string($mode) ? $mode : '';
        $allowed = ['weighted', 'only', 'normal'];
        return in_array($mode, $allowed, true) ? $mode : 'normal';
    }
}

if (!function_exists('ll_tools_user_study_state_array_limit')) {
    function ll_tools_user_study_state_array_limit(string $array_key): int {
        $defaults = [
            'category_ids' => 1000,
            'starred_word_ids' => 5000,
        ];
        $default = $defaults[$array_key] ?? 1000;

        /**
         * Filter the maximum stored IDs for a user study state array.
         *
         * Supported keys are category_ids and starred_word_ids.
         */
        return max(0, (int) apply_filters("ll_tools_user_study_{$array_key}_limit", $default, $array_key));
    }
}

if (!function_exists('ll_tools_user_study_sanitize_state_id_array')) {
    function ll_tools_user_study_sanitize_state_id_array($values, string $array_key): array {
        $clean = [];
        foreach ((array) $values as $value) {
            $id = (int) $value;
            if ($id <= 0) {
                continue;
            }
            $clean[$id] = $id;
        }

        $clean = array_values($clean);
        $limit = ll_tools_user_study_state_array_limit($array_key);
        if ($limit <= 0) {
            return [];
        }

        return array_slice($clean, 0, $limit);
    }
}

if (!function_exists('ll_tools_parse_request_id_list')) {
    function ll_tools_parse_request_id_list($value, int $limit = 0): array {
        $raw_values = [];
        $value = wp_unslash($value);

        if (is_array($value)) {
            $raw_values = $value;
        } elseif (is_scalar($value)) {
            $raw = trim((string) $value);
            if ($raw !== '') {
                if ($raw[0] === '[') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $raw_values = $decoded;
                    }
                }

                if (empty($raw_values)) {
                    $raw_values = preg_split('/[\s,|]+/', $raw) ?: [];
                }
            }
        }

        $ids = [];
        foreach ((array) $raw_values as $raw_value) {
            $id = (int) $raw_value;
            if ($id <= 0 || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = $id;
        }

        $ids = array_values($ids);
        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
        }

        return $ids;
    }
}

/**
 * Read the saved study state for a user.
 */
function ll_tools_get_user_study_state($user_id = 0): array {
    $uid = $user_id ?: get_current_user_id();
    $wordset_id = (int) get_user_meta($uid, LL_TOOLS_USER_WORDSET_META, true);
    $category_ids = (array) get_user_meta($uid, LL_TOOLS_USER_CATEGORY_META, true);
    $category_ids = ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids');
    if ($wordset_id > 0 && !empty($category_ids) && function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset')) {
        $repaired_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($category_ids, $wordset_id, true);
        if (!empty($repaired_category_ids) && $repaired_category_ids !== $category_ids) {
            $category_ids = ll_tools_user_study_sanitize_state_id_array($repaired_category_ids, 'category_ids');
            update_user_meta($uid, LL_TOOLS_USER_CATEGORY_META, $category_ids);
        }
    }
    $starred_word_ids = (array) get_user_meta($uid, LL_TOOLS_USER_STARRED_META, true);
    $starred_word_ids = ll_tools_user_study_sanitize_state_id_array($starred_word_ids, 'starred_word_ids');
    if ($uid > 0 && metadata_exists('user', $uid, 'll_user_star_mode')) {
        // Star mode is no longer a remembered cross-session preference.
        delete_user_meta($uid, 'll_user_star_mode');
    }
    $star_mode = 'normal';
    $fast_raw = get_user_meta($uid, LL_TOOLS_USER_FAST_TRANSITIONS_META, true);
    $fast_transitions = filter_var($fast_raw, FILTER_VALIDATE_BOOLEAN);

    return [
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_word_ids,
        'star_mode'        => $star_mode,
        'fast_transitions' => $fast_transitions,
    ];
}

/**
 * Save the study state for a user.
 */
function ll_tools_save_user_study_state(array $state, $user_id = 0): array {
    $uid = $user_id ?: get_current_user_id();
    $wordset_id   = isset($state['wordset_id']) ? (int) $state['wordset_id'] : 0;
    $category_ids = isset($state['category_ids']) ? (array) $state['category_ids'] : [];
    $starred_ids  = isset($state['starred_word_ids']) ? (array) $state['starred_word_ids'] : [];
    $star_mode    = 'normal';
    $fast_raw     = isset($state['fast_transitions']) ? $state['fast_transitions'] : false;
    $fast_transitions = filter_var($fast_raw, FILTER_VALIDATE_BOOLEAN);

    $category_ids = ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids');
    if ($wordset_id > 0 && !empty($category_ids) && function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset')) {
        $repaired_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($category_ids, $wordset_id, true);
        if (!empty($repaired_category_ids)) {
            $category_ids = ll_tools_user_study_sanitize_state_id_array($repaired_category_ids, 'category_ids');
        }
    }
    $starred_ids  = ll_tools_user_study_sanitize_state_id_array($starred_ids, 'starred_word_ids');

    update_user_meta($uid, LL_TOOLS_USER_WORDSET_META, $wordset_id);
    update_user_meta($uid, LL_TOOLS_USER_CATEGORY_META, $category_ids);
    update_user_meta($uid, LL_TOOLS_USER_STARRED_META, $starred_ids);
    delete_user_meta($uid, 'll_user_star_mode');
    update_user_meta($uid, LL_TOOLS_USER_FAST_TRANSITIONS_META, $fast_transitions ? 1 : 0);

    return [
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_ids,
        'star_mode'        => $star_mode,
        'fast_transitions' => $fast_transitions,
    ];
}

/**
 * List available wordsets for selection.
 */
function ll_tools_user_study_wordsets(): array {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms)) {
        return [];
    }

    $out = [];
    foreach ($terms as $term) {
        $out[] = [
            'id'   => (int) $term->term_id,
            'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'slug' => (string) $term->slug,
        ];
    }
    if (!empty($out)) {
        usort($out, static function ($left, $right) {
            if (function_exists('ll_tools_locale_compare_strings')) {
                return ll_tools_locale_compare_strings((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }
            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
    }
    return $out;
}

/**
 * Build category data (mirrors flashcard widget structure) for a wordset scope.
 */
function ll_tools_user_study_categories_for_wordset($wordset_id): array {
    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $use_translations = function_exists('ll_flashcards_should_use_translations') ? ll_flashcards_should_use_translations($wordset_ids) : false;
    if (!function_exists('ll_flashcards_build_categories')) {
        return [];
    }
    [$categories] = ll_flashcards_build_categories('', $use_translations, $wordset_ids);
    $normalized = array_map(function ($cat) {
        $cat['id']    = (int) $cat['id'];
        $cat['name']  = (string) $cat['name'];
        $cat['slug']  = (string) $cat['slug'];
        $cat['word_count'] = isset($cat['word_count']) ? (int) $cat['word_count'] : 0;
        $cat['gender_supported'] = !empty($cat['gender_supported']);
        $cat['aspect_bucket'] = isset($cat['aspect_bucket']) ? (string) $cat['aspect_bucket'] : '';
        if ($cat['aspect_bucket'] === '') {
            $cat['aspect_bucket'] = 'no-image';
        }
        return $cat;
    }, $categories);

    $wordset_term_id = (int) $wordset_id;
    if ($wordset_term_id <= 0 || empty($normalized) || !function_exists('ll_tools_wordset_sort_category_ids')) {
        return $normalized;
    }

    $category_ids = [];
    $category_name_map = [];
    $by_id = [];
    foreach ($normalized as $category_row) {
        if (!is_array($category_row)) {
            continue;
        }
        $cid = (int) ($category_row['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $category_ids[] = $cid;
        $category_name_map[$cid] = (string) ($category_row['name'] ?? (string) $cid);
        $by_id[$cid] = $category_row;
    }

    $ordered_ids = ll_tools_wordset_sort_category_ids(
        $category_ids,
        $wordset_term_id,
        ['category_name_map' => $category_name_map]
    );

    $level_info = function_exists('ll_tools_wordset_get_prereq_level_info')
        ? ll_tools_wordset_get_prereq_level_info($wordset_term_id, $category_ids)
        : ['has_cycle' => false, 'levels' => []];
    $levels = (is_array($level_info) && isset($level_info['levels']) && is_array($level_info['levels']))
        ? $level_info['levels']
        : [];
    $prereq_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? (ll_tools_wordset_get_category_ordering_mode($wordset_term_id) === 'prerequisite')
        : false;

    $ordered = [];
    foreach ($ordered_ids as $position => $cid) {
        if (!isset($by_id[$cid])) {
            continue;
        }
        $row = $by_id[$cid];
        $row['logical_order_position'] = (int) $position;
        if ($prereq_mode && empty($level_info['has_cycle'])) {
            $row['logical_order_level'] = (int) ($levels[$cid] ?? 0);
        }
        $ordered[] = $row;
    }

    return !empty($ordered) ? $ordered : $normalized;
}

/**
 * Keep only category IDs that are currently quizzable for the selected wordset.
 *
 * @param int[] $category_ids
 * @return int[]
 */
function ll_tools_user_study_filter_quizzable_category_ids(array $category_ids, $wordset_id): array {
    $category_ids = ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids');
    $wordset_id = (int) $wordset_id;
    if ($wordset_id > 0 && !empty($category_ids) && function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset')) {
        $repaired_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($category_ids, $wordset_id, true);
        if (!empty($repaired_category_ids)) {
            $category_ids = ll_tools_user_study_sanitize_state_id_array($repaired_category_ids, 'category_ids');
        }
    }
    if (empty($category_ids)) {
        return [];
    }

    if (function_exists('ll_tools_filter_category_ids_for_user')) {
        $category_ids = ll_tools_filter_category_ids_for_user($category_ids);
        if (empty($category_ids)) {
            return [];
        }
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $terms_by_id = [];
    foreach ($terms as $term) {
        $category_id = isset($term->term_id) ? (int) $term->term_id : 0;
        if ($category_id > 0) {
            $terms_by_id[$category_id] = $term;
        }
    }

    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $wordset_ids = ($wordset_id > 0) ? [$wordset_id] : [];
    $allowed = [];

    foreach ($category_ids as $category_id) {
        if (!isset($terms_by_id[$category_id])) {
            continue;
        }

        if (!function_exists('ll_can_category_generate_quiz') || ll_can_category_generate_quiz($terms_by_id[$category_id], $min_word_count, $wordset_ids)) {
            $allowed[] = (int) $category_id;
        }
    }

    return $allowed;
}

/**
 * Fetch renderable item IDs for a set of category IDs, scoped to a wordset if provided.
 *
 * This mirrors ll_tools_user_study_words() category/config resolution without
 * hydrating labels, media URLs, audio rows, or full progress payloads.
 *
 * @param int[] $category_ids
 * @return array<int,int[]>
 */
function ll_tools_user_study_normalize_positive_ids(array $ids): array {
    $normalized = [];
    $seen = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $normalized[] = $id;
    }

    return $normalized;
}

function ll_tools_user_study_renderable_word_ids_cache_key(array $category_ids, int $wordset_id, int $min_word_count): string {
    $category_ids = ll_tools_user_study_normalize_positive_ids($category_ids);
    if (empty($category_ids)) {
        return '';
    }

    sort($category_ids, SORT_NUMERIC);

    $category_versions = [];
    foreach ($category_ids as $category_id) {
        $version = function_exists('ll_tools_get_category_cache_version')
            ? (int) ll_tools_get_category_cache_version($category_id)
            : 1;
        $category_versions[$category_id] = max(1, $version);
    }

    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;

    $payload = [
        'schema' => 1,
        'wordset_id' => max(0, $wordset_id),
        'category_ids' => $category_ids,
        'category_versions' => $category_versions,
        'category_epoch' => $category_epoch,
        'wordset_epoch' => $wordset_epoch,
        'min_word_count' => max(0, $min_word_count),
        'user_id' => function_exists('get_current_user_id') ? max(0, (int) get_current_user_id()) : 0,
    ];

    return 'll_us_renderable_word_ids_' . md5(wp_json_encode($payload));
}

function ll_tools_user_study_normalize_cached_renderable_word_ids($cached): ?array {
    if (
        !is_array($cached)
        || !isset($cached['__ll_user_study_renderable_word_ids_cache_format'])
        || (int) $cached['__ll_user_study_renderable_word_ids_cache_format'] !== 1
        || !isset($cached['ids_by_category'])
        || !is_array($cached['ids_by_category'])
    ) {
        return null;
    }

    $ids_by_category = [];
    foreach ($cached['ids_by_category'] as $category_id => $ids) {
        $category_id = (int) $category_id;
        if ($category_id <= 0 || !is_array($ids)) {
            continue;
        }
        $ids_by_category[$category_id] = ll_tools_user_study_normalize_positive_ids($ids);
    }

    return $ids_by_category;
}

function ll_tools_user_study_order_ids_by_category(array $ids_by_category, array $category_ids): array {
    $ordered = [];
    $seen = [];
    foreach (ll_tools_user_study_normalize_positive_ids($category_ids) as $category_id) {
        $seen[$category_id] = true;
        if (isset($ids_by_category[$category_id]) && is_array($ids_by_category[$category_id])) {
            $ordered[$category_id] = ll_tools_user_study_normalize_positive_ids($ids_by_category[$category_id]);
        }
    }

    foreach ($ids_by_category as $category_id => $ids) {
        $category_id = (int) $category_id;
        if ($category_id <= 0 || isset($seen[$category_id]) || !is_array($ids)) {
            continue;
        }
        $ordered[$category_id] = ll_tools_user_study_normalize_positive_ids($ids);
    }

    return $ordered;
}

function ll_tools_user_study_renderable_word_ids_by_category(array $category_ids, $wordset_id): array {
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, (int) $wordset_id);
    if (empty($category_ids) || !function_exists('ll_tools_get_renderable_category_item_ids')) {
        return [];
    }

    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $cache_key = ll_tools_user_study_renderable_word_ids_cache_key($category_ids, (int) $wordset_id, $min_word_count);
    $cache_group = 'll_tools_user_study';
    $cache_ttl = 30 * MINUTE_IN_SECONDS;

    static $request_cache = [];
    if ($cache_key !== '' && array_key_exists($cache_key, $request_cache)) {
        return ll_tools_user_study_order_ids_by_category($request_cache[$cache_key], $category_ids);
    }

    if ($cache_key !== '') {
        $cached = wp_cache_get($cache_key, $cache_group);
        if ($cached === false) {
            $cached = get_transient($cache_key);
        }
        $cached_ids_by_category = ll_tools_user_study_normalize_cached_renderable_word_ids($cached);
        if (is_array($cached_ids_by_category)) {
            $request_cache[$cache_key] = $cached_ids_by_category;
            return ll_tools_user_study_order_ids_by_category($cached_ids_by_category, $category_ids);
        }
    }

    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }

    $by_id = [];
    foreach ($terms as $term) {
        if ($term instanceof WP_Term && (int) $term->term_id > 0) {
            $by_id[(int) $term->term_id] = $term;
        }
    }

    $result = [];
    foreach ($category_ids as $cid) {
        $cid = (int) $cid;
        if ($cid <= 0 || !isset($by_id[$cid])) {
            continue;
        }

        $term = $by_id[$cid];
        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'audio', 'option_type' => 'image'];
        if (function_exists('ll_tools_resolve_effective_category_quiz_config')) {
            $config = ll_tools_resolve_effective_category_quiz_config($term, $min_word_count, $wordset_ids);
        }
        $option_type = isset($config['option_type']) ? (string) $config['option_type'] : 'image';
        $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
        $merged_config = array_merge((array) $config, [
            'option_type' => $option_type,
            'prompt_type' => $prompt_type,
        ]);

        $ids = ll_tools_get_renderable_category_item_ids($term, $option_type, $wordset_ids, $merged_config);
        $result[$cid] = array_values(array_unique(array_filter(array_map('intval', (array) $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    if ($cache_key !== '') {
        $payload = [
            '__ll_user_study_renderable_word_ids_cache_format' => 1,
            'ids_by_category' => $result,
        ];
        $request_cache[$cache_key] = $result;
        wp_cache_set($cache_key, $payload, $cache_group, $cache_ttl);
        set_transient($cache_key, $payload, $cache_ttl);
    }

    return $result;
}

function ll_tools_user_study_category_available_count_lookup(array $categories): array {
    $lookup = [];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }

        $category_id = (int) ($category['id'] ?? 0);
        if ($category_id <= 0) {
            continue;
        }

        $count = isset($category['word_count'])
            ? (int) $category['word_count']
            : (isset($category['count']) ? (int) $category['count'] : -1);
        if ($count >= 0) {
            $lookup[$category_id] = max(0, $count);
        }
    }

    return $lookup;
}

function ll_tools_user_study_limited_candidate_word_ids_by_category(array $category_ids, $wordset_id, int $limit_per_category, array $available_counts_by_category = []): array {
    $limit_per_category = max(1, $limit_per_category);
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, (int) $wordset_id);
    $wordset_terms = $wordset_id ? [(int) $wordset_id] : [];
    if (!empty($wordset_terms) && function_exists('ll_tools_filter_viewable_wordset_ids')) {
        $wordset_terms = ll_tools_filter_viewable_wordset_ids($wordset_terms, (int) get_current_user_id());
        if (empty($wordset_terms)) {
            return [
                'candidate_word_ids_by_category' => [],
                'meta' => [],
            ];
        }
    }

    $candidate_word_ids_by_category = [];
    $meta = [];

    foreach ($category_ids as $category_id) {
        $category_id = (int) $category_id;
        if ($category_id <= 0) {
            continue;
        }

        $tax_query = [[
            'taxonomy' => 'word-category',
            'field' => 'term_id',
            'terms' => [$category_id],
        ]];
        if (!empty($wordset_terms)) {
            $tax_query[] = [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => $wordset_terms,
            ];
            $tax_query['relation'] = 'AND';
        }

        $has_available_count = array_key_exists($category_id, $available_counts_by_category);
        $query = new WP_Query([
            'post_type' => 'words',
            'post_status' => 'publish',
            'posts_per_page' => $limit_per_category,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => $has_available_count,
            'suppress_filters' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query' => $tax_query,
        ]);

        $candidate_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $query->posts), static function (int $word_id): bool {
            return $word_id > 0;
        })));
        $loaded_count = count($candidate_word_ids);
        $total_count = $has_available_count
            ? max(0, (int) $available_counts_by_category[$category_id])
            : max($loaded_count, (int) $query->found_posts);
        if ($total_count <= 0 && $loaded_count > 0) {
            $total_count = $loaded_count;
        }

        $candidate_word_ids_by_category[$category_id] = $candidate_word_ids;
        $meta[$category_id] = [
            'complete' => $loaded_count >= $total_count,
            'has_more' => $loaded_count < $total_count,
            'loaded_count' => $loaded_count,
            'total_count' => $total_count,
            'limit' => $limit_per_category,
            'candidate_word_ids' => $candidate_word_ids,
        ];
    }

    return [
        'candidate_word_ids_by_category' => $candidate_word_ids_by_category,
        'meta' => $meta,
    ];
}

/**
 * Fetch words for a set of category IDs, scoped to a wordset if provided.
 */
function ll_tools_user_study_words(array $category_ids, $wordset_id, array $candidate_word_ids = []): array {
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, (int) $wordset_id);
    if (empty($category_ids)) {
        return [];
    }

    $uid = (int) get_current_user_id();
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }
    $candidate_word_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    $by_id = [];
    foreach ($terms as $t) {
        $by_id[(int) $t->term_id] = $t;
    }

    $result = [];
    foreach ($category_ids as $cid) {
        if (!isset($by_id[$cid])) {
            continue;
        }
        $term = $by_id[$cid];
        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'audio', 'option_type' => 'image'];
        if (function_exists('ll_tools_resolve_effective_category_quiz_config')) {
            $config = ll_tools_resolve_effective_category_quiz_config($term, $min_word_count, $wordset_ids);
        }
        $option_type = isset($config['option_type']) ? $config['option_type'] : 'image';
        $prompt_type = isset($config['prompt_type']) ? $config['prompt_type'] : 'audio';
        $merged_config = array_merge($config, [
            'option_type' => $option_type,
            'prompt_type' => $prompt_type,
        ]);
        if (!empty($candidate_word_ids)) {
            $merged_config['__candidate_word_ids'] = $candidate_word_ids;
        }
        $words_raw = ll_get_words_by_category($term, $option_type, $wordset_ids, $merged_config);
        $word_ids = array_values(array_filter(array_map(function ($w) {
            return (int) ($w['id'] ?? 0);
        }, (array) $words_raw), function ($id) {
            return $id > 0;
        }));
        $progress_rows = [];
        if ($uid > 0 && !empty($word_ids) && function_exists('ll_tools_get_user_word_progress_rows')) {
            $progress_rows = ll_tools_get_user_word_progress_rows($uid, $word_ids);
        }

        $result[$cid] = array_map(function ($w) use ($term, $progress_rows, $wordset_id, $merged_config) {
            $word_id = (int) ($w['id'] ?? 0);
            $title = isset($w['title']) ? (string) $w['title'] : '';
            $translation = '';
            $progress = ($word_id > 0 && isset($progress_rows[$word_id]) && is_array($progress_rows[$word_id]))
                ? $progress_rows[$word_id]
                : [];
            $gender_support = function_exists('ll_tools_get_word_gender_support_snapshot')
                ? ll_tools_get_word_gender_support_snapshot((array) $w, (int) $wordset_id, [
                    'category_id' => (int) $term->term_id,
                    'category_name' => (string) $term->name,
                    'quiz_config' => (array) $merged_config,
                ])
                : [
                    'normalized_gender' => '',
                    'gender_marked' => false,
                    'gender_progress_tracked' => false,
                    'gender_eligible' => false,
                ];
            if ($word_id > 0) {
                $translation = trim((string) get_post_meta($word_id, 'word_translation', true));
                if ($translation === '') {
                    $translation = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
                }
            }
            return [
                'id'             => $word_id,
                'title'          => $title,
                'translation'    => html_entity_decode((string) $translation, ENT_QUOTES, 'UTF-8'),
                'label'          => isset($w['label']) ? (string) $w['label'] : '',
                'prompt_label'   => isset($w['prompt_label']) ? (string) $w['prompt_label'] : '',
                'specific_wrong_answer_ids' => isset($w['specific_wrong_answer_ids']) ? array_values(array_map('intval', (array) $w['specific_wrong_answer_ids'])) : [],
                'specific_wrong_answer_texts' => isset($w['specific_wrong_answer_texts']) ? array_values(array_map('strval', (array) $w['specific_wrong_answer_texts'])) : [],
                'specific_wrong_answer_owner_ids' => isset($w['specific_wrong_answer_owner_ids']) ? array_values(array_map('intval', (array) $w['specific_wrong_answer_owner_ids'])) : [],
                'is_specific_wrong_answer_only' => !empty($w['is_specific_wrong_answer_only']),
                'image'          => isset($w['image']) ? (string) $w['image'] : '',
                'audio'          => isset($w['audio']) ? (string) $w['audio'] : '',
                'audio_files'    => isset($w['audio_files']) ? (array) $w['audio_files'] : [],
                'preferred_speaker_user_id' => isset($w['preferred_speaker_user_id']) ? (int) $w['preferred_speaker_user_id'] : 0,
                'all_categories' => isset($w['all_categories']) ? (array) $w['all_categories'] : [$term->name],
                'part_of_speech' => isset($w['part_of_speech']) ? (array) $w['part_of_speech'] : [],
                'grammatical_gender' => isset($w['grammatical_gender']) ? (string) $w['grammatical_gender'] : '',
                'normalized_grammatical_gender' => (string) ($gender_support['normalized_gender'] ?? ''),
                'gender_marked' => !empty($gender_support['gender_marked']),
                'gender_progress_tracked' => !empty($gender_support['gender_progress_tracked']),
                'gender_eligible' => !empty($gender_support['gender_eligible']),
                'gender_progress' => function_exists('ll_tools_get_progress_row_gender_progress')
                    ? ll_tools_get_progress_row_gender_progress($progress)
                    : [],
                'status' => function_exists('ll_tools_user_progress_word_status')
                    ? (string) ll_tools_user_progress_word_status($progress)
                    : (!empty($progress) ? 'studied' : 'new'),
                'difficulty_score' => function_exists('ll_tools_user_progress_word_difficulty_score')
                    ? max(0, (int) ll_tools_user_progress_word_difficulty_score($progress))
                    : 0,
                'wordset_ids'    => isset($w['wordset_ids']) ? (array) $w['wordset_ids'] : [],
                'progress_total_coverage' => max(0, (int) ($progress['total_coverage'] ?? 0)),
                'progress_stage' => max(0, (int) ($progress['stage'] ?? 0)),
                'progress_last_mode' => isset($progress['last_mode']) ? (string) $progress['last_mode'] : '',
                'progress_last_seen_at' => isset($progress['last_seen_at']) ? (string) $progress['last_seen_at'] : '',
            ];
        }, $words_raw);
    }

    return $result;
}

/**
 * Build a payload for bootstrapping the dashboard.
 */
function ll_tools_build_user_study_payload($user_id = 0, $requested_wordset_id = 0, $requested_categories = [], array $options = []) {
    $uid = $user_id ?: get_current_user_id();
    $state = ll_tools_get_user_study_state($uid);
    $goals = function_exists('ll_tools_get_user_study_goals')
        ? ll_tools_get_user_study_goals($uid)
        : [
            'enabled_modes' => ['learning', 'practice', 'listening', 'gender', 'self-check'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
            'priority_focus' => '',
            'prioritize_new_words' => false,
            'prioritize_studied_words' => false,
            'prioritize_learned_words' => false,
            'prefer_starred_words' => false,
            'prefer_hard_words' => false,
        ];
    $wordset_id = $requested_wordset_id ? (int) $requested_wordset_id : (int) $state['wordset_id'];
    if ($wordset_id <= 0 && function_exists('ll_tools_get_active_wordset_id')) {
        $wordset_id = (int) ll_tools_get_active_wordset_id();
    }

    $wordsets = ll_tools_user_study_wordsets();
    $categories = ll_tools_user_study_categories_for_wordset($wordset_id);
    $category_lookup = [];
    foreach ($categories as $cat) {
        $category_lookup[(int) $cat['id']] = true;
    }
    $ignored_category_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $ignored_category_lookup[(int) $ignored_id] = true;
    }

    $selected_category_ids = $requested_categories ? (array) $requested_categories : $state['category_ids'];
    $selected_category_ids = ll_tools_user_study_sanitize_state_id_array($selected_category_ids, 'category_ids');
    $selected_category_ids = array_values(array_filter($selected_category_ids, function ($id) use ($category_lookup, $ignored_category_lookup) {
        return $id > 0 && isset($category_lookup[$id]) && empty($ignored_category_lookup[$id]);
    }));
    if (empty($selected_category_ids) && !empty($categories)) {
        $selected_category_ids = array_values(array_filter(array_map('intval', array_column($categories, 'id')), function ($id) use ($ignored_category_lookup) {
            return $id > 0 && empty($ignored_category_lookup[$id]);
        }));
        $selected_category_ids = array_slice($selected_category_ids, 0, 3);
    }

    $defer_words = !empty($options['defer_words']);
    $words_by_category = [];
    $words_by_category_meta = [];
    if ($defer_words) {
        $candidate_limit = isset($options['candidate_word_limit'])
            ? (int) $options['candidate_word_limit']
            : (int) apply_filters('ll_tools_user_study_deferred_candidate_word_limit', 20, $wordset_id, $selected_category_ids);
        $candidate_limit = max(1, min(200, $candidate_limit));
        $category_available_counts = ll_tools_user_study_category_available_count_lookup($categories);
        $limited_candidates = ll_tools_user_study_limited_candidate_word_ids_by_category($selected_category_ids, $wordset_id, $candidate_limit, $category_available_counts);
        foreach ((array) ($limited_candidates['meta'] ?? []) as $category_id => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $category_id = (int) $category_id;
            if ($category_id <= 0) {
                continue;
            }
            $candidate_word_ids = isset($meta['candidate_word_ids']) && is_array($meta['candidate_word_ids'])
                ? array_values(array_filter(array_map('intval', $meta['candidate_word_ids']), static function (int $word_id): bool {
                    return $word_id > 0;
                }))
                : [];
            $available_word_count = max(0, (int) ($meta['total_count'] ?? 0));
            $words_by_category_meta[$category_id] = [
                'category_id' => $category_id,
                'available_word_count' => $available_word_count,
                'candidate_word_ids' => $candidate_word_ids,
                'candidate_count' => count($candidate_word_ids),
                'loaded_count' => max(0, (int) ($meta['loaded_count'] ?? count($candidate_word_ids))),
                'fully_loaded' => !empty($meta['complete']),
                'complete' => !empty($meta['complete']),
                'has_more' => !empty($meta['has_more']),
            ];
        }
    } else {
        $words_by_category = ll_tools_user_study_words($selected_category_ids, $wordset_id);
    }
    $category_progress = function_exists('ll_tools_get_user_category_progress')
        ? ll_tools_get_user_category_progress($uid)
        : [];
    $next_activity = function_exists('ll_tools_build_next_activity_recommendation')
        ? ll_tools_build_next_activity_recommendation($uid, $wordset_id, $selected_category_ids, $categories)
        : null;

    $gender_enabled = false;
    $gender_options = [];
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
        $gender_enabled = ll_tools_wordset_has_grammatical_gender($wordset_id);
    }
    if ($gender_enabled && function_exists('ll_tools_wordset_get_gender_options')) {
        $gender_options = ll_tools_wordset_get_gender_options($wordset_id);
    }
    $gender_visual_config = ($gender_enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
        ? ll_tools_wordset_get_gender_visual_config($wordset_id)
        : [];
    $gender_options = array_values(array_filter(array_map('strval', (array) $gender_options), function ($val) {
        return $val !== '';
    }));
    $gender_min_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);

    $payload = [
        'wordsets'          => $wordsets,
        'categories'        => $categories,
        'gender'            => [
            'enabled'   => (bool) $gender_enabled,
            'options'   => $gender_options,
            'visual_config' => $gender_visual_config,
            'min_count' => $gender_min_count,
        ],
        'state'             => [
            'wordset_id'       => $wordset_id,
            'category_ids'     => $selected_category_ids,
            'starred_word_ids' => $state['starred_word_ids'],
            'star_mode'        => ll_tools_normalize_star_mode($state['star_mode'] ?? 'normal'),
            'fast_transitions' => !empty($state['fast_transitions']),
        ],
        'goals'             => $goals,
        'category_progress' => $category_progress,
        'next_activity'     => $next_activity,
        'words_by_category' => $words_by_category,
    ];

    if ($defer_words) {
        $payload['words_deferred'] = true;
        $payload['words_by_category_meta'] = $words_by_category_meta;
    }

    return $payload;
}

/**
 * AJAX: bootstrap data.
 */
function ll_tools_user_study_bootstrap_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    if (function_exists('ll_tools_user_study_can_access') && !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $include_words = isset($_POST['include_words'])
        ? filter_var(wp_unslash((string) $_POST['include_words']), FILTER_VALIDATE_BOOLEAN)
        : false;
    $defer_words_raw = $_POST['defer_words'] ?? ($_POST['partial_words'] ?? false);
    $defer_words = !$include_words && filter_var(wp_unslash((string) $defer_words_raw), FILTER_VALIDATE_BOOLEAN);
    $payload_options = [];
    if ($defer_words) {
        $payload_options['defer_words'] = true;
        if (isset($_POST['candidate_word_limit'])) {
            $payload_options['candidate_word_limit'] = (int) $_POST['candidate_word_limit'];
        }
    }

    $payload = ll_tools_build_user_study_payload(get_current_user_id(), $wordset_id, $category_ids, $payload_options);
    wp_send_json_success($payload);
}
add_action('wp_ajax_ll_user_study_bootstrap', 'll_tools_user_study_bootstrap_ajax');

/**
 * AJAX: fetch words for specific categories (used when user toggles selections).
 */
function ll_tools_user_study_fetch_words_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    if (function_exists('ll_tools_user_study_can_access') && !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, $wordset_id);
    $candidate_word_limit = max(0, (int) apply_filters('ll_tools_user_study_fetch_candidate_word_id_limit', 1000, $wordset_id, $category_ids));
    $candidate_word_ids = isset($_POST['candidate_word_ids'])
        ? ll_tools_parse_request_id_list($_POST['candidate_word_ids'], $candidate_word_limit)
        : [];
    $words = ll_tools_user_study_words($category_ids, $wordset_id, $candidate_word_ids);
    wp_send_json_success(['words_by_category' => $words]);
}
add_action('wp_ajax_ll_user_study_fetch_words', 'll_tools_user_study_fetch_words_ajax');

/**
 * AJAX: save selections (wordset, categories, starred words).
 */
function ll_tools_user_study_save_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    if (function_exists('ll_tools_user_study_can_access') && !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id   = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $starred_ids  = isset($_POST['starred_word_ids']) ? (array) $_POST['starred_word_ids'] : [];
    $fast_transitions = filter_var($_POST['fast_transitions'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $category_ids = ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids');
    $starred_ids = ll_tools_user_study_sanitize_state_id_array($starred_ids, 'starred_word_ids');

    if (function_exists('ll_tools_get_user_study_goals')) {
        $goals = ll_tools_get_user_study_goals(get_current_user_id());
        $ignored_lookup = [];
        foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
            $ignored_lookup[(int) $ignored_id] = true;
        }
        $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($ignored_lookup) {
            return $id > 0 && empty($ignored_lookup[$id]);
        }));
    }

    $category_ids = ll_tools_user_study_filter_quizzable_category_ids((array) $category_ids, $wordset_id);

    $payload = ll_tools_save_user_study_state([
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_ids,
        'fast_transitions' => $fast_transitions,
    ]);

    $categories = ll_tools_user_study_categories_for_wordset($wordset_id);
    $recommendation_queue = [];
    if (function_exists('ll_tools_refresh_user_recommendation_queue')) {
        $recommendation_queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $payload['category_ids'], $categories, 8);
    }
    $next_activity = function_exists('ll_tools_recommendation_queue_pick_next')
        ? ll_tools_recommendation_queue_pick_next($recommendation_queue)
        : null;
    if (!$next_activity && function_exists('ll_tools_build_next_activity_recommendation')) {
        $next_activity = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $payload['category_ids'], $categories);
    }

    wp_send_json_success([
        'state' => $payload,
        'next_activity' => $next_activity,
        'recommendation_queue' => $recommendation_queue,
    ]);
}
add_action('wp_ajax_ll_user_study_save', 'll_tools_user_study_save_ajax');
