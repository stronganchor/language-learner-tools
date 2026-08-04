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
function ll_tools_user_study_wordsets(int $required_wordset_id = 0): array {
    $limit = max(10, min(200, (int) apply_filters(
        'll_tools_user_study_wordset_catalog_limit',
        100
    )));
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'number'      => $limit,
    ]);
    if (is_wp_error($terms)) {
        return [];
    }
    $required_wordset_id = max(0, $required_wordset_id);
    if ($required_wordset_id > 0) {
        $visible_required_ids = function_exists('ll_tools_filter_viewable_wordset_ids')
            ? ll_tools_filter_viewable_wordset_ids(
                [$required_wordset_id],
                (int) get_current_user_id()
            )
            : [$required_wordset_id];
        $required_term = in_array($required_wordset_id, array_map('intval', (array) $visible_required_ids), true)
            ? get_term($required_wordset_id, 'wordset')
            : null;
        if ($required_term instanceof WP_Term) {
            $terms_by_id = [];
            foreach ((array) $terms as $term) {
                if ($term instanceof WP_Term) {
                    $terms_by_id[(int) $term->term_id] = $term;
                }
            }
            $terms_by_id[$required_wordset_id] = $required_term;
            $terms = array_values($terms_by_id);
        }
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
function ll_tools_user_study_categories_for_wordset(
    $wordset_id,
    ?bool &$complete = null,
    bool $bounded_catalog = false,
    array $required_category_ids = [],
    ?array &$catalog_page = null
): array {
    global $wpdb;

    $complete = true;
    do_action('ll_tools_user_study_categories_for_wordset_before_build', (int) $wordset_id);
    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $use_translations = function_exists('ll_flashcards_should_use_translations') ? ll_flashcards_should_use_translations($wordset_ids) : false;
    if (!function_exists('ll_flashcards_build_categories')) {
        $complete = false;
        return [];
    }
    $build_complete = true;
    $wpdb->last_error = '';
    [$categories, , $catalog_page] = ll_flashcards_build_categories(
        '',
        $use_translations,
        $wordset_ids,
        0,
        $bounded_catalog,
        $build_complete
    );
    if ($bounded_catalog && !empty($required_category_ids)) {
        $required_category_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            $required_category_ids
        ), static function (int $category_id): bool {
            return $category_id > 0;
        })));
        if (!empty($required_category_ids)) {
            $required_complete = true;
            [$required_categories] = ll_flashcards_build_categories(
                implode(',', $required_category_ids),
                $use_translations,
                $wordset_ids,
                0,
                true,
                $required_complete
            );
            $build_complete = $build_complete && $required_complete;
            $categories_by_id = [];
            foreach (array_merge((array) $categories, (array) $required_categories) as $category) {
                if (is_array($category) && (int) ($category['id'] ?? 0) > 0) {
                    $categories_by_id[(int) $category['id']] = $category;
                }
            }
            $categories = array_values($categories_by_id);
        }
    }
    $complete = $build_complete && $wpdb->last_error === '';
    if (!$complete) {
        return [];
    }
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
function ll_tools_user_study_filter_quizzable_category_ids(
    array $category_ids,
    $wordset_id,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $category_ids = ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids');
    $wordset_id = (int) $wordset_id;
    if ($wordset_id > 0 && !empty($category_ids)) {
        if (!function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete')) {
            $complete = false;
            return [];
        }
        $wpdb->last_error = '';
        $repaired_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete(
            $category_ids,
            $wordset_id,
            true
        );
        if ($repaired_category_ids === null || $wpdb->last_error !== '') {
            $complete = false;
            foreach ($category_ids as $category_id) {
                wp_cache_delete((int) $category_id, 'terms');
                wp_cache_delete((int) $category_id, 'term_meta');
            }
            return [];
        }
        $category_ids = ll_tools_user_study_sanitize_state_id_array($repaired_category_ids, 'category_ids');
    }
    if (empty($category_ids)) {
        return [];
    }

    if (function_exists('ll_tools_filter_category_ids_for_user')) {
        $visibility_complete = true;
        $visible_source_ids = $category_ids;
        $wpdb->last_error = '';
        $category_ids = ll_tools_filter_category_ids_for_user(
            $category_ids,
            (int) get_current_user_id(),
            $visibility_complete
        );
        if (!$visibility_complete || $wpdb->last_error !== '') {
            $complete = false;
            foreach ($visible_source_ids as $category_id) {
                wp_cache_delete((int) $category_id, 'term_meta');
            }
            return [];
        }
        if (empty($category_ids)) {
            return [];
        }
    }

    $wpdb->last_error = '';
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms) || $wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    if (empty($terms)) {
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

    if (function_exists('ll_tools_get_category_cache_versions')) {
        ll_tools_get_category_cache_versions(array_keys($terms_by_id));
    }

    foreach ($category_ids as $category_id) {
        if (!isset($terms_by_id[$category_id])) {
            continue;
        }

        $category_complete = true;
        if (!function_exists('ll_can_category_generate_quiz')
            || ll_can_category_generate_quiz(
                $terms_by_id[$category_id],
                $min_word_count,
                $wordset_ids,
                $category_complete
            )) {
            $allowed[] = (int) $category_id;
        }
        $complete = $complete && $category_complete;
    }

    return $complete ? $allowed : [];
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

    $category_versions = function_exists('ll_tools_get_category_cache_versions')
        ? ll_tools_get_category_cache_versions($category_ids)
        : array_fill_keys($category_ids, 1);

    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? (string) ll_tools_get_quiz_content_cache_epoch($wordset_id > 0 ? [$wordset_id] : [])
        : 'qce-unavailable';

    $payload = [
        'schema' => 3,
        'wordset_id' => max(0, $wordset_id),
        'category_ids' => $category_ids,
        'category_versions' => $category_versions,
        'category_epoch' => $category_epoch,
        'wordset_epoch' => $wordset_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
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

/**
 * Build the small category payload needed to regenerate a recommendation queue.
 *
 * The normal wordset page already submits its ordered visible category IDs. A
 * recommendation needs only a few of those categories, so do not rebuild the
 * full flashcard catalog (and every category's quiz projection) when a new user
 * has no persisted queue yet. The wordset category-ID aggregate remains the
 * authoritative materialized scope; only a bounded window is hydrated.
 *
 * @param int[] $requested_category_ids
 * @return array<int,array<string,mixed>>
 */
function ll_tools_user_study_recommendation_categories_for_wordset(
    int $wordset_id,
    array $requested_category_ids = [],
    int $user_id = 0,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, $wordset_id);
    $user_id = max(0, $user_id ?: (int) get_current_user_id());
    if ($wordset_id <= 0 || !function_exists('ll_flashcards_build_categories')) {
        $complete = false;
        return [];
    }

    $requested_category_ids = array_values(array_unique(array_filter(array_map('intval', $requested_category_ids), static function (int $category_id): bool {
        return $category_id > 0;
    })));
    if (!empty($requested_category_ids) && function_exists('ll_tools_recommendation_remap_category_ids_for_wordset')) {
        $remap_complete = true;
        $requested_category_ids = ll_tools_recommendation_remap_category_ids_for_wordset(
            $requested_category_ids,
            $wordset_id,
            $remap_complete
        );
        if (!$remap_complete) {
            $complete = false;
            return [];
        }
    }

    $allowed_complete = true;
    $allowed_category_ids = function_exists('ll_collect_wc_ids_for_wordset_term_ids')
        ? ll_collect_wc_ids_for_wordset_term_ids([$wordset_id], $allowed_complete)
        : $requested_category_ids;
    $allowed_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $allowed_category_ids), static function (int $category_id): bool {
        return $category_id > 0;
    })));
    if (!$allowed_complete) {
        $complete = false;
        return [];
    }

    $allowed_lookup = array_fill_keys($allowed_category_ids, true);
    $category_ids = [];
    foreach ($requested_category_ids as $category_id) {
        if (isset($allowed_lookup[$category_id])) {
            $category_ids[] = $category_id;
        }
    }
    if (empty($requested_category_ids)) {
        $category_ids = $allowed_category_ids;
        if (count($category_ids) > 1 && function_exists('ll_tools_wordset_sort_category_ids')) {
            $wpdb->last_error = '';
            $category_ids = ll_tools_wordset_sort_category_ids($category_ids, $wordset_id);
            if ($wpdb->last_error !== '') {
                $complete = false;
                return [];
            }
        }
    }
    if (empty($category_ids)) {
        return [];
    }

    $wpdb->last_error = '';
    $goals = ($user_id > 0 && function_exists('ll_tools_get_user_study_goals'))
        ? ll_tools_get_user_study_goals($user_id)
        : [];
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_category_id) {
        $ignored_category_id = (int) $ignored_category_id;
        if ($ignored_category_id > 0) {
            $ignored_lookup[$ignored_category_id] = true;
        }
    }
    if (!empty($ignored_lookup)) {
        $category_ids = array_values(array_filter($category_ids, static function (int $category_id) use ($ignored_lookup): bool {
            return empty($ignored_lookup[$category_id]);
        }));
    }
    if (empty($category_ids)) {
        return [];
    }

    $wpdb->last_error = '';
    $ordering_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? (string) ll_tools_wordset_get_category_ordering_mode($wordset_id)
        : 'none';
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    if ($ordering_mode === 'none' && function_exists('ll_tools_recommendation_rotate_category_ids')) {
        $wpdb->last_error = '';
        $category_ids = ll_tools_recommendation_rotate_category_ids($category_ids, $user_id, $wordset_id);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
    } elseif ($user_id > 0 && function_exists('ll_tools_get_user_last_recommendation_activity')) {
        // Keep the logical-order anchor in the window. The recommendation
        // planner can then decide whether it still needs study or whether the
        // following categories have become the next frontier.
        $wpdb->last_error = '';
        $last_activity = ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $anchor_ids = is_array($last_activity) && function_exists('ll_tools_recommendation_activity_category_ids')
            ? ll_tools_recommendation_activity_category_ids($last_activity)
            : [];
        $anchor_index = null;
        $index_lookup = array_flip($category_ids);
        foreach ($anchor_ids as $anchor_id) {
            $anchor_id = (int) $anchor_id;
            if (isset($index_lookup[$anchor_id])) {
                $anchor_index = (int) $index_lookup[$anchor_id];
                break;
            }
        }
        if ($anchor_index !== null && $anchor_index > 0) {
            $category_ids = array_merge(
                array_slice($category_ids, $anchor_index),
                array_slice($category_ids, 0, $anchor_index)
            );
        }
    }

    $window_size = (int) apply_filters(
        'll_tools_user_study_recommendation_category_window_size',
        12,
        $wordset_id,
        $user_id
    );
    $window_size = max(1, min(24, $window_size));
    $category_ids = array_slice($category_ids, 0, $window_size);

    if (function_exists('ll_tools_filter_category_ids_for_user')) {
        $visibility_complete = true;
        $visibility_source_ids = $category_ids;
        $wpdb->last_error = '';
        $category_ids = ll_tools_filter_category_ids_for_user(
            $category_ids,
            $user_id,
            $visibility_complete
        );
        if (!$visibility_complete || $wpdb->last_error !== '') {
            $complete = false;
            foreach ($visibility_source_ids as $category_id) {
                wp_cache_delete((int) $category_id, 'terms');
                wp_cache_delete((int) $category_id, 'term_meta');
            }
            return [];
        }
    }
    if (empty($category_ids)) {
        return [];
    }

    do_action(
        'll_tools_user_study_recommendation_categories_for_wordset_before_build',
        $wordset_id,
        $category_ids,
        $user_id
    );

    $wordset_ids = [$wordset_id];
    $use_translations = function_exists('ll_flashcards_should_use_translations')
        ? ll_flashcards_should_use_translations($wordset_ids)
        : false;
    $build_complete = true;
    $wpdb->last_error = '';
    [$categories] = ll_flashcards_build_categories(
        implode(',', $category_ids),
        $use_translations,
        $wordset_ids,
        0,
        false,
        $build_complete
    );
    $complete = $build_complete && $wpdb->last_error === '';
    if (!$complete) {
        return [];
    }

    return array_values(array_filter((array) $categories, static function ($category): bool {
        return is_array($category) && (int) ($category['id'] ?? 0) > 0;
    }));
}

function ll_tools_user_study_renderable_word_ids_by_category(
    array $category_ids,
    $wordset_id,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $eligibility_complete = true;
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids(
        $category_ids,
        (int) $wordset_id,
        $eligibility_complete
    );
    $complete = $complete && $eligibility_complete;
    if (empty($category_ids) || !function_exists('ll_tools_get_renderable_category_item_ids')) {
        return [];
    }

    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $wpdb->last_error = '';
    $cache_key = ll_tools_user_study_renderable_word_ids_cache_key($category_ids, (int) $wordset_id, $min_word_count);
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    $cache_group = 'll_tools_user_study';
    // Keep the renderable membership warm for at least as long as the outer
    // analytics ID map. Both keys already include category/content epochs, so
    // correctness does not depend on the shorter TTL.
    $cache_ttl = HOUR_IN_SECONDS;

    static $request_cache = [];
    if ($cache_key !== '' && array_key_exists($cache_key, $request_cache)) {
        return ll_tools_user_study_order_ids_by_category($request_cache[$cache_key], $category_ids);
    }

    if ($cache_key !== '') {
        $wpdb->last_error = '';
        $cached = wp_cache_get($cache_key, $cache_group);
        if ($cached === false) {
            $cached = get_transient($cache_key);
        }
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $cached_ids_by_category = ll_tools_user_study_normalize_cached_renderable_word_ids($cached);
        if (is_array($cached_ids_by_category)) {
            $request_cache[$cache_key] = $cached_ids_by_category;
            return ll_tools_user_study_order_ids_by_category($cached_ids_by_category, $category_ids);
        }
    }

    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $wpdb->last_error = '';
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms) || $wpdb->last_error !== '') {
        $complete = false;
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
        if (function_exists('ll_tools_resolve_effective_category_quiz_config')) {
            $config_complete = true;
            $wpdb->last_error = '';
            $config = ll_tools_resolve_effective_category_quiz_config(
                $term,
                $min_word_count,
                $wordset_ids,
                $config_complete
            );
            $complete = $complete && $config_complete && $wpdb->last_error === '';
        } elseif (function_exists('ll_tools_get_category_quiz_config')) {
            $config_complete = true;
            $wpdb->last_error = '';
            $config = ll_tools_get_category_quiz_config($term, $config_complete);
            $complete = $complete && $config_complete && $wpdb->last_error === '';
        } else {
            $config = ['prompt_type' => 'audio', 'option_type' => 'image'];
        }
        $option_type = isset($config['option_type']) ? (string) $config['option_type'] : 'image';
        $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
        $merged_config = array_merge((array) $config, [
            'option_type' => $option_type,
            'prompt_type' => $prompt_type,
        ]);

        $ids_complete = true;
        $wpdb->last_error = '';
        $ids = ll_tools_get_renderable_category_item_ids(
            $term,
            $option_type,
            $wordset_ids,
            $merged_config,
            $ids_complete
        );
        $complete = $complete && $ids_complete && $wpdb->last_error === '';
        $result[$cid] = array_values(array_unique(array_filter(array_map('intval', (array) $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    if (!$complete) {
        foreach ($category_ids as $category_id) {
            wp_cache_delete((int) $category_id, 'terms');
            wp_cache_delete((int) $category_id, 'term_meta');
        }
        return [];
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
    $requested_category_limit = max(1, min(20, (int) apply_filters(
        'll_tools_user_study_bootstrap_category_limit',
        8,
        $uid
    )));
    $requested_categories = array_slice(
        ll_tools_user_study_sanitize_state_id_array(
            (array) $requested_categories,
            'category_ids'
        ),
        0,
        $requested_category_limit
    );
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

    $desired_category_ids = !empty($requested_categories)
        ? $requested_categories
        : array_slice(
            ll_tools_user_study_sanitize_state_id_array(
                (array) ($state['category_ids'] ?? []),
                'category_ids'
            ),
            0,
            $requested_category_limit
        );
    $wordsets = ll_tools_user_study_wordsets($wordset_id);
    $category_catalog_page = [];
    $categories_complete = true;
    $categories = ll_tools_user_study_categories_for_wordset(
        $wordset_id,
        $categories_complete,
        true,
        $desired_category_ids,
        $category_catalog_page
    );
    $category_lookup = [];
    foreach ($categories as $cat) {
        $category_lookup[(int) $cat['id']] = true;
    }
    $ignored_category_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $ignored_category_lookup[(int) $ignored_id] = true;
    }

    $selected_category_ids = $desired_category_ids;
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

    $include_words = !empty($options['include_words']);
    $defer_words = !$include_words || !empty($options['defer_words']);
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
    $next_activity = null;
    if ($defer_words) {
        $saved_recommendation_queue = function_exists('ll_tools_get_user_recommendation_queue')
            ? ll_tools_get_user_recommendation_queue($uid, $wordset_id)
            : [];
        if (function_exists('ll_tools_recommendation_queue_pick_next')) {
            $next_activity = ll_tools_recommendation_queue_pick_next($saved_recommendation_queue);
        }
    } elseif (function_exists('ll_tools_build_next_activity_recommendation')) {
        $next_activity = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $selected_category_ids, $categories);
    }

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
        $payload['recommendation_refresh_deferred'] = true;
        $payload['words_by_category_meta'] = $words_by_category_meta;
    }
    if (!empty($category_catalog_page['has_more'])) {
        $payload['categories_deferred'] = true;
        $payload['category_catalog'] = [
            'has_more' => true,
            'next_offset' => max(0, (int) ($category_catalog_page['next_offset'] ?? 0)),
            'page_size' => max(0, (int) ($category_catalog_page['page_size'] ?? 0)),
        ];
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
    $include_words_requested = isset($_POST['include_words'])
        ? filter_var(wp_unslash((string) $_POST['include_words']), FILTER_VALIDATE_BOOLEAN)
        : false;
    $allow_legacy_full_bootstrap = $include_words_requested && (bool) apply_filters(
        'll_tools_user_study_allow_legacy_full_bootstrap',
        false,
        get_current_user_id(),
        $wordset_id
    );
    $payload_options = [
        'defer_words' => !$allow_legacy_full_bootstrap,
        'include_words' => $allow_legacy_full_bootstrap,
    ];
    if (!$allow_legacy_full_bootstrap) {
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
    $category_limit = max(1, min(8, (int) apply_filters(
        'll_tools_user_study_fetch_category_limit',
        3,
        $wordset_id
    )));
    $category_ids = isset($_POST['category_ids'])
        ? array_slice((array) $_POST['category_ids'], 0, $category_limit)
        : [];
    $category_ids = array_slice(
        ll_tools_user_study_sanitize_state_id_array($category_ids, 'category_ids'),
        0,
        $category_limit
    );
    $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, $wordset_id);
    $candidate_word_limit = max(1, min(200, (int) apply_filters(
        'll_tools_user_study_fetch_candidate_word_id_limit',
        200,
        $wordset_id,
        $category_ids
    )));
    $candidate_word_ids_raw = $_POST['candidate_word_ids'] ?? [];
    if (is_array($candidate_word_ids_raw)) {
        $candidate_word_ids_raw = array_slice(
            $candidate_word_ids_raw,
            0,
            $candidate_word_limit
        );
    } elseif (is_scalar($candidate_word_ids_raw)) {
        $candidate_word_ids_raw = substr(
            (string) $candidate_word_ids_raw,
            0,
            max(1024, min(32768, $candidate_word_limit * 24))
        );
    } else {
        $candidate_word_ids_raw = [];
    }
    $candidate_word_ids = ll_tools_parse_request_id_list(
        $candidate_word_ids_raw,
        $candidate_word_limit
    );
    if (empty($candidate_word_ids)) {
        wp_send_json_error([
            'code' => 'paged_payload_required',
            'message' => __('Flashcard words must be requested with a bounded candidate list or the paged flashcard endpoint.', 'll-tools-text-domain'),
        ], 409);
    }
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
