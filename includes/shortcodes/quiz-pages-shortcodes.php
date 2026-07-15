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
function ll_raw_resolve_wordset_term_ids($spec, ?bool &$complete = null) {
    global $wpdb;

    $complete = true;
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
    $wpdb->last_error = '';
    $sql_slug = $wpdb->prepare("
        SELECT tt.term_id
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s AND t.slug = %s
    ", 'wordset', $spec);
    $ids = array_map('intval', (array) $wpdb->get_col($sql_slug));
    $complete = $wpdb->last_error === '';

    // 2) If nothing found by slug, try exact name match
    if ($complete && empty($ids)) {
        $wpdb->last_error = '';
        $sql_name = $wpdb->prepare("
            SELECT tt.term_id
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s AND t.name = %s
        ", 'wordset', $spec);
        $ids = array_map('intval', (array) $wpdb->get_col($sql_name));
        $complete = $wpdb->last_error === '';
    }

    $result = array_values(array_unique(array_filter($ids, function($v){ return $v > 0; })));
    if ($complete) {
        $request_cache[$cache_key] = $result;
        wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
        set_transient($cache_key, $result, $cache_ttl);
    }

    return $result;
}

/**
 * Collect all word-category IDs used by at least MINIMUM published quiz items
 * that belong to ANY of the provided wordset term IDs. Uses direct SQL and
 * intentionally ignores category parent/child relationships.
 */
function ll_collect_wc_ids_for_wordset_term_ids(array $wordset_term_ids, ?bool &$complete = null) {
    global $wpdb;

    $complete = true;
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
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? ll_tools_get_quiz_content_cache_epoch($wordset_term_ids)
        : (string) $category_cache_epoch;

    // Structural category edits still use the broad category epoch. Ordinary
    // word/audio eligibility changes use the narrower wordset content token so
    // an unrelated large wordset does not evict this category-ID catalog.
    $cache_key = 'll_wcids_ws_' . md5(wp_json_encode([
        'wordset_ids' => $wordset_term_ids,
        'min_words' => $min_words,
        'category_epoch' => $category_cache_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
        'schema' => 3,
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

    $wpdb->last_error = '';
    $cat_ids = array_map('intval', (array) $wpdb->get_col($sql));
    $complete = $wpdb->last_error === '';
    if (!$complete) {
        return [];
    }
    if (empty($cat_ids)) {
        wp_cache_set($cache_key, [], $cache_group, $cache_ttl);
        set_transient($cache_key, [], $cache_ttl);
        return [];
    }

    $result = array_values(array_unique(array_filter($cat_ids, static function (int $category_id): bool {
        return $category_id > 0;
    })));
    wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);
    set_transient($cache_key, $result, $cache_ttl);
    return $result;
}

function ll_tools_quiz_pages_data_cache_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_quiz_pages_data_cache_ttl', DAY_IN_SECONDS);
    return max(MINUTE_IN_SECONDS, $ttl);
}

function ll_tools_quiz_pages_data_cache_key(array $opts, int $min_word_count, ?bool &$complete = null): string {
    $complete = true;
    $wordset_spec = isset($opts['wordset']) && is_scalar($opts['wordset'])
        ? trim((string) $opts['wordset'])
        : '';

    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $content_fallback_epoch = function_exists('ll_tools_get_quiz_content_fallback_epoch')
        ? ll_tools_get_quiz_content_fallback_epoch()
        : 'qcf-unavailable';
    $resolution_complete = true;
    $wordset_ids = $wordset_spec !== ''
        ? ll_raw_resolve_wordset_term_ids($wordset_spec, $resolution_complete)
        : [];
    $complete = $resolution_complete;
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? ll_tools_get_quiz_content_cache_epoch($wordset_ids)
        : (string) $category_epoch;
    $locale = function_exists('determine_locale')
        ? (string) determine_locale()
        : (function_exists('get_locale') ? (string) get_locale() : '');

    static $failure_tokens = [];
    $failure_token = '';
    if (!$resolution_complete) {
        if (!isset($failure_tokens[$wordset_spec])) {
            $failure_tokens[$wordset_spec] = wp_generate_uuid4();
        }
        $failure_token = (string) $failure_tokens[$wordset_spec];
    }

    return 'll_qpg_data_' . md5(wp_json_encode([
        'wordset' => $wordset_spec,
        'min_words' => $min_word_count,
        'category_epoch' => $category_epoch,
        'wordset_epoch' => $wordset_epoch,
        'content_fallback_epoch' => $content_fallback_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
        'resolution_complete' => $resolution_complete,
        'resolution_failure_token' => $failure_token,
        'user_id' => (int) get_current_user_id(),
        'locale' => $locale,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'schema' => 5,
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

function ll_tools_quiz_pages_catalog_stale_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_quiz_pages_catalog_stale_ttl', 7 * DAY_IN_SECONDS);
    return max(DAY_IN_SECONDS, min(30 * DAY_IN_SECONDS, $ttl));
}

function ll_tools_quiz_pages_catalog_builder_token(): string {
    $plugin_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    return hash('sha256', 'll-tools-quiz-catalog-builder-v1|' . $plugin_version);
}

function ll_tools_quiz_pages_catalog_scopes_share_builder(array $left, array $right): bool {
    $left_token = (string) ($left['builder_token'] ?? '');
    $right_token = (string) ($right['builder_token'] ?? '');
    return $left_token !== ''
        && $right_token !== ''
        && hash_equals($left_token, $right_token);
}

function ll_tools_quiz_pages_catalog_scope(array $opts, int $min_word_count): array {
    $wordset_spec = isset($opts['wordset']) && is_scalar($opts['wordset'])
        ? trim((string) $opts['wordset'])
        : '';
    $locale = function_exists('determine_locale')
        ? (string) determine_locale()
        : (function_exists('get_locale') ? (string) get_locale() : '');
    $identity = [
        'wordset' => $wordset_spec,
        'min_words' => $min_word_count,
        'user_id' => (int) get_current_user_id(),
        'locale' => $locale,
        'schema' => 2,
    ];

    $cache_key_complete = true;
    $cache_key = ll_tools_quiz_pages_data_cache_key($opts, $min_word_count, $cache_key_complete);

    return [
        'id' => md5(wp_json_encode($identity)),
        'opts' => $wordset_spec === '' ? [] : ['wordset' => $wordset_spec],
        'min_word_count' => $min_word_count,
        'user_id' => (int) $identity['user_id'],
        'locale' => $locale,
        'cache_key' => $cache_key,
        'source_complete' => $cache_key_complete,
        'builder_token' => ll_tools_quiz_pages_catalog_builder_token(),
    ];
}

function ll_tools_quiz_pages_catalog_option_name(string $type, string $scope_id): string {
    return 'll_qpg_catalog_' . $type . '_' . preg_replace('/[^a-f0-9]/', '', strtolower($scope_id));
}

function ll_tools_quiz_pages_catalog_sort_items(array $items): array {
    usort($items, static function ($a, $b): int {
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        }
        return strnatcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    return $items;
}

function ll_tools_quiz_pages_catalog_chunk_option_name(string $scope_id, string $generation, int $chunk_index): string {
    $scope_id = preg_replace('/[^a-f0-9]/', '', strtolower($scope_id));
    $generation = substr(preg_replace('/[^a-f0-9]/', '', strtolower($generation)), 0, 12);
    return 'll_qpg_catalog_chunk_' . $scope_id . '_' . $generation . '_' . max(0, $chunk_index);
}

function ll_tools_quiz_pages_catalog_is_chunk_option_name(string $option_name): bool {
    return preg_match('/^ll_qpg_catalog_chunk_[a-f0-9]{32}_[a-f0-9]{12}_[0-9]+$/D', $option_name) === 1;
}

function ll_tools_quiz_pages_catalog_cleanup_chunk_options(array $option_names, array $preserve_option_names = []): void {
    $preserve_lookup = array_fill_keys(array_map('strval', $preserve_option_names), true);
    foreach (array_values(array_unique(array_map('strval', $option_names))) as $option_name) {
        if (!isset($preserve_lookup[$option_name]) && ll_tools_quiz_pages_catalog_is_chunk_option_name($option_name)) {
            delete_option($option_name);
        }
    }
}

function ll_tools_quiz_pages_catalog_snapshot_chunk_options($payload): array {
    if (!is_array($payload)) {
        return [];
    }

    return array_values(array_unique(array_merge(
        array_map('strval', (array) ($payload['chunks'] ?? [])),
        array_map('strval', (array) ($payload['retired_chunks'] ?? []))
    )));
}

function ll_tools_quiz_pages_catalog_cleanup_snapshot_payload($payload, array $preserve_option_names = []): void {
    ll_tools_quiz_pages_catalog_cleanup_chunk_options(
        ll_tools_quiz_pages_catalog_snapshot_chunk_options($payload),
        $preserve_option_names
    );
}

function ll_tools_quiz_pages_catalog_snapshot_items(array $payload) {
    if (is_array($payload['items'] ?? null)) {
        return $payload['items'];
    }
    if (
        empty($payload['__ll_quiz_pages_catalog_manifest'])
        || !is_array($payload['chunks'] ?? null)
        || !isset($payload['generation'])
    ) {
        return null;
    }

    $generation = (string) $payload['generation'];
    $cache_key = (string) ($payload['cache_key'] ?? '');
    $items = [];
    foreach ($payload['chunks'] as $option_name) {
        $option_name = (string) $option_name;
        if (!ll_tools_quiz_pages_catalog_is_chunk_option_name($option_name)) {
            return null;
        }
        $chunk = get_option($option_name, null);
        if (
            !is_array($chunk)
            || empty($chunk['__ll_quiz_pages_catalog_chunk'])
            || (string) ($chunk['generation'] ?? '') !== $generation
            || (string) ($chunk['cache_key'] ?? '') !== $cache_key
            || !is_array($chunk['items'] ?? null)
        ) {
            return null;
        }
        foreach ($chunk['items'] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
    }

    if (count($items) !== max(0, (int) ($payload['item_count'] ?? 0))) {
        return null;
    }

    return ll_tools_quiz_pages_catalog_sort_items($items);
}

function ll_tools_quiz_pages_catalog_latest_payload(array $scope) {
    $payload = get_option(ll_tools_quiz_pages_catalog_option_name('latest', (string) $scope['id']), null);
    if (
        !is_array($payload)
        || empty($payload['__ll_quiz_pages_catalog'])
        || (!is_array($payload['items'] ?? null) && empty($payload['__ll_quiz_pages_catalog_manifest']))
    ) {
        return null;
    }

    $generated_at = max(0, (int) ($payload['generated_at'] ?? 0));
    if ($generated_at <= 0 || $generated_at + ll_tools_quiz_pages_catalog_stale_ttl() < time()) {
        return null;
    }

    return $payload;
}

function ll_tools_quiz_pages_catalog_latest_get(array $scope) {
    $payload = ll_tools_quiz_pages_catalog_latest_payload($scope);
    return is_array($payload) ? ll_tools_quiz_pages_catalog_snapshot_items($payload) : null;
}

function ll_tools_quiz_pages_catalog_latest_set(array $scope, array $items, bool $replace = false): void {
    $option_name = ll_tools_quiz_pages_catalog_option_name('latest', (string) $scope['id']);
    $existing = get_option($option_name, null);
    if (
        !$replace
        && is_array($existing)
        && !empty($existing['__ll_quiz_pages_catalog'])
        && (string) ($existing['cache_key'] ?? '') === (string) $scope['cache_key']
    ) {
        return;
    }

    $payload = [
        '__ll_quiz_pages_catalog' => 1,
        'cache_key' => (string) $scope['cache_key'],
        'generated_at' => time(),
        'items' => $items,
    ];
    $retired_chunks = ll_tools_quiz_pages_catalog_snapshot_chunk_options($existing);
    if ($retired_chunks !== []) {
        $payload['retired_chunks'] = $retired_chunks;
    }
    update_option($option_name, $payload, false);
    $stored = get_option($option_name, null);
    if (is_array($stored) && is_array($stored['items'] ?? null)) {
        ll_tools_quiz_pages_catalog_cleanup_snapshot_payload($existing);
        if ($retired_chunks !== []) {
            unset($payload['retired_chunks']);
            update_option($option_name, $payload, false);
        }
    }
}

function ll_tools_quiz_pages_catalog_latest_set_manifest(
    array $scope,
    string $generation,
    array $chunk_options,
    int $item_count
): bool {
    $option_name = ll_tools_quiz_pages_catalog_option_name('latest', (string) $scope['id']);
    $existing = get_option($option_name, null);
    $chunk_options = array_values(array_unique(array_map('strval', $chunk_options)));
    $retired_chunks = array_values(array_diff(
        ll_tools_quiz_pages_catalog_snapshot_chunk_options($existing),
        $chunk_options
    ));
    $payload = [
        '__ll_quiz_pages_catalog' => 1,
        '__ll_quiz_pages_catalog_manifest' => 1,
        'cache_key' => (string) $scope['cache_key'],
        'generated_at' => time(),
        'generation' => $generation,
        'item_count' => max(0, $item_count),
        'chunks' => $chunk_options,
    ];
    if ($retired_chunks !== []) {
        $payload['retired_chunks'] = $retired_chunks;
    }
    update_option($option_name, $payload, false);
    $stored = get_option($option_name, null);
    if (
        !is_array($stored)
        || empty($stored['__ll_quiz_pages_catalog_manifest'])
        || (string) ($stored['generation'] ?? '') !== $generation
        || (string) ($stored['cache_key'] ?? '') !== (string) $scope['cache_key']
    ) {
        return false;
    }

    ll_tools_quiz_pages_catalog_cleanup_snapshot_payload($existing, $chunk_options);
    if ($retired_chunks !== []) {
        unset($payload['retired_chunks']);
        update_option($option_name, $payload, false);
    }
    return true;
}

function ll_tools_quiz_pages_catalog_set_status(array $status): void {
    $GLOBALS['ll_tools_quiz_pages_catalog_status'] = array_merge([
        'refreshing' => false,
        'has_snapshot' => false,
        'needs_loading_notice' => false,
        'scope_id' => '',
    ], $status);
}

function ll_tools_quiz_pages_catalog_needs_loading_notice(): bool {
    $status = $GLOBALS['ll_tools_quiz_pages_catalog_status'] ?? [];
    return !empty($status['needs_loading_notice']);
}

function ll_tools_quiz_pages_catalog_filter_visible_items(array $items, array $scope): array {
    $has_explicit_wordset = !empty($scope['opts']['wordset']);
    $explicit_wordset_map = [];
    if ($has_explicit_wordset) {
        $resolution_complete = true;
        $explicit_wordset_ids = ll_raw_resolve_wordset_term_ids((string) $scope['opts']['wordset'], $resolution_complete);
        if (!$resolution_complete) {
            return [];
        }
        $explicit_wordset_map = array_fill_keys(array_map('intval', $explicit_wordset_ids), true);
        if (empty($explicit_wordset_map)) {
            return [];
        }
    }

    $wordset_ids = [];
    foreach ($items as $item) {
        $wordset_id = is_array($item) ? (int) ($item['wordset_id'] ?? 0) : 0;
        if ($wordset_id > 0) {
            $wordset_ids[$wordset_id] = $wordset_id;
        }
    }

    $viewable_wordset_ids = array_values($wordset_ids);
    if (!empty($viewable_wordset_ids) && function_exists('ll_tools_filter_viewable_wordset_ids')) {
        $viewable_wordset_ids = ll_tools_filter_viewable_wordset_ids(
            $viewable_wordset_ids,
            (int) get_current_user_id()
        );
    }
    $viewable_wordset_map = array_fill_keys(array_map('intval', (array) $viewable_wordset_ids), true);
    $visible = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $wordset_id = (int) ($item['wordset_id'] ?? 0);
        if ($has_explicit_wordset && !isset($explicit_wordset_map[$wordset_id])) {
            continue;
        }
        if ($wordset_id > 0 && !isset($viewable_wordset_map[$wordset_id])) {
            if ($has_explicit_wordset) {
                continue;
            }

            $item['wordset_id'] = 0;
            $item['wordset_slug'] = '';
            $item['autoplay_text_audio_answer_options'] = false;
            $item['gender_enabled'] = false;
            $item['gender_options'] = [];
            $item['gender_visual_config'] = [];
            $item['gender_supported'] = false;
        }

        $visible[] = $item;
    }

    return $visible;
}

function ll_tools_quiz_pages_catalog_lock_acquire(string $scope_id): string {
    $option_name = ll_tools_quiz_pages_catalog_option_name('lock', $scope_id);
    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ll-qpg-', true);
    $payload = [
        'token' => $token,
        'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
    ];

    if (add_option($option_name, $payload, '', false)) {
        return $token;
    }

    $existing = get_option($option_name, []);
    if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) < time()) {
        delete_option($option_name);
        if (add_option($option_name, $payload, '', false)) {
            return $token;
        }
    }

    return '';
}

function ll_tools_quiz_pages_catalog_lock_release(string $scope_id, string $token): void {
    if ($token === '') {
        return;
    }

    $option_name = ll_tools_quiz_pages_catalog_option_name('lock', $scope_id);
    $existing = get_option($option_name, []);
    if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
        delete_option($option_name);
    }
}

function ll_tools_quiz_pages_catalog_lock_is_owned(string $scope_id, string $token): bool {
    if ($token === '') {
        return false;
    }

    $existing = get_option(ll_tools_quiz_pages_catalog_option_name('lock', $scope_id), []);
    return is_array($existing)
        && (int) ($existing['expires_at'] ?? 0) >= time()
        && hash_equals((string) ($existing['token'] ?? ''), $token);
}

function ll_tools_quiz_pages_catalog_snapshot_payload_ready(array $payload): bool {
    if (is_array($payload['items'] ?? null)) {
        return true;
    }
    if (
        empty($payload['__ll_quiz_pages_catalog_manifest'])
        || !is_array($payload['chunks'] ?? null)
        || !isset($payload['generation'], $payload['cache_key'], $payload['item_count'])
    ) {
        return false;
    }

    global $wpdb;

    $generation = (string) $payload['generation'];
    $cache_key = (string) $payload['cache_key'];
    $expected_count = max(0, (int) $payload['item_count']);
    $actual_count = 0;
    $seen_options = [];
    foreach ($payload['chunks'] as $option_name) {
        $option_name = (string) $option_name;
        if (
            isset($seen_options[$option_name])
            || !ll_tools_quiz_pages_catalog_is_chunk_option_name($option_name)
        ) {
            return false;
        }
        $seen_options[$option_name] = true;
        $raw_chunk = $wpdb->get_var($wpdb->prepare(
            "/* ll_tools_quiz_pages_catalog_chunk_ready */ SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ));
        if ($raw_chunk === null) {
            return false;
        }
        $chunk = maybe_unserialize($raw_chunk);
        if (
            !is_array($chunk)
            || empty($chunk['__ll_quiz_pages_catalog_chunk'])
            || (string) ($chunk['generation'] ?? '') !== $generation
            || (string) ($chunk['cache_key'] ?? '') !== $cache_key
            || !is_array($chunk['items'] ?? null)
        ) {
            return false;
        }
        foreach ($chunk['items'] as $item) {
            if (!is_array($item)) {
                return false;
            }
            $actual_count++;
            if ($actual_count > $expected_count) {
                return false;
            }
        }
    }

    return $actual_count === $expected_count;
}

function ll_tools_quiz_pages_catalog_snapshot_usable_for_scope($payload, array $scope): bool {
    if (!is_array($payload) || !ll_tools_quiz_pages_catalog_snapshot_payload_ready($payload)) {
        return false;
    }

    $items = ll_tools_quiz_pages_catalog_snapshot_items($payload);
    if (!is_array($items)) {
        return false;
    }

    // An empty stale snapshot cannot prove that the current epoch is still
    // empty. Content may have appeared since it was published, so it is not a
    // sufficient reason to discard the only advancing generation.
    if ($items === []) {
        return false;
    }

    // An explicit wordset must not treat a nonempty snapshot whose rows all
    // belong to an obsolete or no-longer-viewable wordset as useful fallback.
    // This mirrors the foreground loading-shell decision.
    if (!empty($scope['opts']['wordset'])) {
        return ll_tools_quiz_pages_catalog_filter_visible_items($items, $scope) !== [];
    }

    return true;
}

function ll_tools_quiz_pages_catalog_snapshot_ready(string $scope_id): bool {
    if (!preg_match('/^[a-f0-9]{32}$/D', $scope_id)) {
        return false;
    }

    $payload = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null);
    if (
        !is_array($payload)
        || empty($payload['__ll_quiz_pages_catalog'])
        || (!is_array($payload['items'] ?? null) && empty($payload['__ll_quiz_pages_catalog_manifest']))
    ) {
        return false;
    }

    $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), []);
    if (
        !empty($state['__ll_quiz_pages_catalog_state'])
        && (
            (string) ($state['cache_key'] ?? '') !== (string) ($payload['cache_key'] ?? '')
            || (
                !empty($payload['__ll_quiz_pages_catalog_manifest'])
                && (string) ($state['generation'] ?? '') !== (string) ($payload['generation'] ?? '')
            )
        )
    ) {
        return false;
    }

    $generated_at = max(0, (int) ($payload['generated_at'] ?? 0));
    if ($generated_at <= 0 || $generated_at + ll_tools_quiz_pages_catalog_stale_ttl() < time()) {
        return false;
    }

    return ll_tools_quiz_pages_catalog_snapshot_payload_ready($payload);
}

function ll_tools_quiz_pages_catalog_rebuild_batch_size(): int {
    $batch_size = (int) apply_filters('ll_tools_quiz_pages_catalog_rebuild_batch_size', 100);
    return max(1, min(250, $batch_size));
}

function ll_tools_quiz_pages_catalog_warmup_max_attempts(): int {
    $max_attempts = (int) apply_filters('ll_tools_quiz_pages_catalog_warmup_max_attempts', 120);
    return max(1, min(600, $max_attempts));
}

function ll_tools_quiz_pages_catalog_new_build_state(array $scope): array {
    $seed = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ll-qpg-', true);
    return [
        '__ll_quiz_pages_catalog_state' => 1,
        'scope' => $scope,
        'cache_key' => (string) $scope['cache_key'],
        'generation' => substr(md5((string) $seed), 0, 12),
        'status' => 'queued',
        'source_index' => 0,
        'cursor' => 0,
        'processed' => 0,
        'item_count' => 0,
        'chunk_index' => 0,
        'chunks' => [],
        'requested_at' => time(),
        'updated_at' => time(),
        'last_error' => '',
    ];
}

function ll_tools_quiz_pages_catalog_cleanup_build_state($state, array $preserve_option_names = []): void {
    if (!is_array($state)) {
        return;
    }
    ll_tools_quiz_pages_catalog_cleanup_chunk_options(
        (array) ($state['chunks'] ?? []),
        $preserve_option_names
    );
}

function ll_tools_quiz_pages_catalog_build_state_is_current(string $state_option, array $state): bool {
    $current = get_option($state_option, []);
    return is_array($current)
        && !empty($current['__ll_quiz_pages_catalog_state'])
        && (string) ($current['generation'] ?? '') === (string) ($state['generation'] ?? '')
        && (string) ($current['cache_key'] ?? '') === (string) ($state['cache_key'] ?? '');
}

function ll_tools_quiz_pages_catalog_worker_can_persist(
    string $state_option,
    array $state,
    string $scope_id,
    string $scope_token,
    string $global_lock_id,
    string $global_token
): bool {
    return ll_tools_quiz_pages_catalog_lock_is_owned($scope_id, $scope_token)
        && ll_tools_quiz_pages_catalog_lock_is_owned($global_lock_id, $global_token)
        && ll_tools_quiz_pages_catalog_build_state_is_current($state_option, $state);
}

/**
 * Expand stored quiz-page category IDs across a wordset isolation boundary.
 *
 * Generated quiz shells may still reference the source category while words
 * use the owned copy. The durable worker must prove every remap/source read
 * before using that expanded list; otherwise an incomplete list could look
 * like an exhausted page source and be published as a complete snapshot.
 *
 * @param int[] $category_ids
 * @return int[]
 */
function ll_tools_quiz_pages_catalog_category_query_scope_for_wordset(
    array $category_ids,
    int $wordset_id,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function (int $category_id): bool {
        return $category_id > 0;
    })));
    if ($category_ids === []) {
        return [];
    }
    if ($wordset_id <= 0) {
        return $category_ids;
    }
    if (
        !function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete')
        || !function_exists('ll_tools_get_category_isolation_source_id')
    ) {
        $complete = false;
        return [];
    }

    $clear_term_caches = static function (array $term_ids): void {
        foreach (array_values(array_unique(array_filter(array_map('intval', $term_ids)))) as $term_id) {
            if ($term_id <= 0) {
                continue;
            }
            wp_cache_delete($term_id, 'terms');
            wp_cache_delete($term_id, 'term_meta');
        }
    };

    $isolation_enabled = function_exists('ll_tools_is_wordset_isolation_enabled')
        && ll_tools_is_wordset_isolation_enabled();
    $verified_source_ids = [];
    foreach ($category_ids as $category_id) {
        if ($isolation_enabled && function_exists('ll_tools_get_category_wordset_owner_id')) {
            $owner_complete = true;
            $wpdb->last_error = '';
            ll_tools_get_category_wordset_owner_id($category_id, $owner_complete);
            if (!$owner_complete || $wpdb->last_error !== '') {
                $complete = false;
                $clear_term_caches($category_ids);
                return [];
            }
        }

        $source_complete = true;
        $wpdb->last_error = '';
        $source_category_id = (int) ll_tools_get_category_isolation_source_id(
            $category_id,
            $source_complete
        );
        if (!$source_complete || $wpdb->last_error !== '' || $source_category_id <= 0) {
            $complete = false;
            $clear_term_caches($category_ids);
            return [];
        }
        $verified_source_ids[$category_id] = $source_category_id;
    }

    $wpdb->last_error = '';
    $remapped_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete(
        $category_ids,
        $wordset_id,
        false
    );
    if ($remapped_ids === null || $wpdb->last_error !== '') {
        $complete = false;
        $clear_term_caches($category_ids);
        return [];
    }

    $scope = [];
    foreach (array_merge($category_ids, array_map('intval', $remapped_ids)) as $category_id) {
        $category_id = (int) $category_id;
        if ($category_id > 0) {
            $scope[$category_id] = true;
        }
    }

    foreach (array_keys($scope) as $category_id) {
        if (isset($verified_source_ids[$category_id])) {
            $scope[(int) $verified_source_ids[$category_id]] = true;
            continue;
        }
        $source_complete = true;
        $wpdb->last_error = '';
        $source_category_id = (int) ll_tools_get_category_isolation_source_id(
            (int) $category_id,
            $source_complete
        );
        if (!$source_complete || $wpdb->last_error !== '') {
            $complete = false;
            $clear_term_caches(array_merge($category_ids, array_map('intval', $remapped_ids), array_keys($scope)));
            return [];
        }
        if ($source_category_id > 0) {
            $scope[$source_category_id] = true;
        }
    }

    return array_map('intval', array_keys($scope));
}

function ll_tools_quiz_pages_catalog_build_context(array $scope): array {
    $opts = is_array($scope['opts'] ?? null) ? $scope['opts'] : [];
    $min_word_count = max(0, (int) ($scope['min_word_count'] ?? LL_TOOLS_MIN_WORDS_PER_QUIZ));
    $post_types = function_exists('ll_tools_get_quiz_page_post_types')
        ? ll_tools_get_quiz_page_post_types(true)
        : ['page'];
    $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $post_types))));
    if (defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE')) {
        $current_post_type = (string) LL_TOOLS_QUIZ_PAGE_POST_TYPE;
        usort($post_types, static function (string $a, string $b) use ($current_post_type): int {
            if (($a === $current_post_type) !== ($b === $current_post_type)) {
                return $a === $current_post_type ? -1 : 1;
            }
            return strcmp($a, $b);
        });
    }

    $allowed_term_ids = null;
    $wordset_ids = [];
    $filtered_wordset_id = 0;
    $meta_category_ids = [];
    $valid = true;
    $source_complete = !array_key_exists('source_complete', $scope) || !empty($scope['source_complete']);
    if (!empty($opts['wordset'])) {
        $wordset_resolution_complete = true;
        $wordset_ids = array_values(array_filter(array_map('intval', (array) ll_raw_resolve_wordset_term_ids($opts['wordset'], $wordset_resolution_complete))));
        $source_complete = $source_complete && $wordset_resolution_complete;
        if ($wordset_ids === []) {
            $valid = false;
        } else {
            $category_resolution_complete = true;
            $allowed_term_ids = array_values(array_unique(array_filter(array_map('intval', (array) ll_collect_wc_ids_for_wordset_term_ids($wordset_ids, $category_resolution_complete)))));
            $source_complete = $source_complete && $category_resolution_complete;
            if ($allowed_term_ids === []) {
                $valid = false;
            }
        }
        $filtered_wordset_id = (int) ($wordset_ids[0] ?? 0);
        $meta_category_ids = array_map('intval', (array) $allowed_term_ids);
        if ($valid) {
            $scoped_ids = [];
            foreach ($wordset_ids as $wordset_id) {
                $isolation_scope_complete = true;
                $wordset_scoped_ids = ll_tools_quiz_pages_catalog_category_query_scope_for_wordset(
                    $meta_category_ids,
                    (int) $wordset_id,
                    $isolation_scope_complete
                );
                if (!$isolation_scope_complete) {
                    $source_complete = false;
                    $scoped_ids = [];
                    break;
                }
                foreach ($wordset_scoped_ids as $category_id) {
                    $scoped_ids[] = (int) $category_id;
                }
            }
            if ($source_complete && $scoped_ids !== []) {
                $meta_category_ids = $scoped_ids;
            }
        }
        $meta_category_ids = array_values(array_unique(array_filter(array_map('intval', $meta_category_ids))));
        if ($meta_category_ids === []) {
            $valid = false;
        }
    }

    return [
        'valid' => $valid,
        'source_complete' => $source_complete,
        'opts' => $opts,
        'min_word_count' => $min_word_count,
        'post_types' => $post_types,
        'category_meta_key' => defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
            ? (string) LL_TOOLS_QUIZ_PAGE_CATEGORY_META
            : '_ll_tools_word_category_id',
        'wordset_ids' => $wordset_ids,
        'filtered_wordset_id' => $filtered_wordset_id,
        'allowed_term_ids' => $allowed_term_ids,
        'meta_category_ids' => $meta_category_ids,
        'use_translations' => function_exists('ll_flashcards_should_use_translations')
            ? ll_flashcards_should_use_translations($wordset_ids)
            : false,
    ];
}

/**
 * @return array{rows:array<int,array{post_id:int,category_id:int}>,has_more:bool,complete:bool}
 */
function ll_tools_quiz_pages_catalog_query_page_batch(
    array $context,
    int $source_index,
    int $after_post_id,
    int $batch_size
): array {
    global $wpdb;

    $post_types = array_values(array_map('strval', (array) ($context['post_types'] ?? [])));
    if (!isset($post_types[$source_index])) {
        return ['rows' => [], 'has_more' => false, 'complete' => true];
    }
    $post_type = sanitize_key($post_types[$source_index]);
    $earlier_post_types = array_slice($post_types, 0, $source_index);
    $meta_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($context['meta_category_ids'] ?? [])))));
    $category_filter_sql = '';
    if (!empty($context['opts']['wordset'])) {
        if ($meta_category_ids === []) {
            return ['rows' => [], 'has_more' => false, 'complete' => true];
        }
        $category_filter_sql = 'AND CAST(category_meta.meta_value AS UNSIGNED) IN ('
            . implode(',', array_fill(0, count($meta_category_ids), '%d')) . ')';
    }

    $priority_sql = '(better_posts.post_type = %s AND better_posts.ID < posts.ID)';
    $priority_params = [$post_type];
    if ($earlier_post_types !== []) {
        $priority_sql = '(better_posts.post_type IN ('
            . implode(',', array_fill(0, count($earlier_post_types), '%s'))
            . ') OR (better_posts.post_type = %s AND better_posts.ID < posts.ID))';
        $priority_params = array_merge($earlier_post_types, [$post_type]);
    }

    $sql = "/* ll_tools_quiz_pages_catalog_batch */
        SELECT posts.ID AS post_id, MIN(CAST(category_meta.meta_value AS UNSIGNED)) AS category_id
        FROM {$wpdb->posts} AS posts
        INNER JOIN {$wpdb->postmeta} AS category_meta
            ON category_meta.post_id = posts.ID
           AND category_meta.meta_key = %s
           AND category_meta.meta_value <> ''
           AND CAST(category_meta.meta_value AS UNSIGNED) > 0
        WHERE posts.post_type = %s
          AND posts.post_status = %s
          AND posts.post_password = ''
          AND posts.ID > %d
          {$category_filter_sql}
          AND NOT EXISTS (
              SELECT 1
              FROM {$wpdb->posts} AS better_posts
              INNER JOIN {$wpdb->postmeta} AS better_meta
                  ON better_meta.post_id = better_posts.ID
                 AND better_meta.meta_key = %s
                 AND CAST(better_meta.meta_value AS UNSIGNED) = CAST(category_meta.meta_value AS UNSIGNED)
              WHERE better_posts.post_status = %s
                AND better_posts.post_password = ''
                AND {$priority_sql}
          )
        GROUP BY posts.ID
        ORDER BY posts.ID ASC
        LIMIT %d";
    $params = [
        (string) ($context['category_meta_key'] ?? ''),
        $post_type,
        'publish',
        max(0, $after_post_id),
    ];
    $params = array_merge(
        $params,
        $meta_category_ids,
        [(string) ($context['category_meta_key'] ?? ''), 'publish'],
        $priority_params,
        [max(1, $batch_size) + 1]
    );
    $wpdb->last_error = '';
    $raw_rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    $complete = $wpdb->last_error === '';
    $has_more = count($raw_rows) > $batch_size;
    $rows = [];
    foreach (array_slice($raw_rows, 0, $batch_size) as $row) {
        $post_id = is_array($row) ? (int) ($row['post_id'] ?? 0) : 0;
        $category_id = is_array($row) ? (int) ($row['category_id'] ?? 0) : 0;
        if ($post_id > 0 && $category_id > 0) {
            $rows[] = ['post_id' => $post_id, 'category_id' => $category_id];
        }
    }

    return ['rows' => $rows, 'has_more' => $has_more, 'complete' => $complete];
}

/**
 * Materialize one bounded quiz-page row batch.
 *
 * The returned items are usable only when `complete` remains true. A source
 * read can fail after earlier rows in the same batch have already been built,
 * so the durable worker must treat the whole batch as atomic and refuse to
 * append the partial item list or advance its cursor.
 *
 * @return array{items:array<int,array<string,mixed>>,complete:bool}
 */
function ll_tools_quiz_pages_catalog_build_items_for_page_rows(array $rows, array $context): array {
    global $wpdb;

    $complete = true;
    $allowed_term_ids = $context['allowed_term_ids'] ?? null;
    $allowed_lookup = is_array($allowed_term_ids)
        ? array_fill_keys(array_map('intval', $allowed_term_ids), true)
        : null;
    $filtered_wordset_id = max(0, (int) ($context['filtered_wordset_id'] ?? 0));
    $wordset_ids = array_values(array_map('intval', (array) ($context['wordset_ids'] ?? [])));
    $min_word_count = max(0, (int) ($context['min_word_count'] ?? LL_TOOLS_MIN_WORDS_PER_QUIZ));
    $use_translations = !empty($context['use_translations']);
    $opts = is_array($context['opts'] ?? null) ? $context['opts'] : [];
    $candidates = [];
    $category_ids_for_meta = [];
    $read_term_ids = [];
    $read_post_ids = [];
    foreach ($wordset_ids as $read_wordset_id) {
        if ($read_wordset_id > 0) {
            $read_term_ids[$read_wordset_id] = true;
        }
    }
    $clear_incomplete_object_caches = static function () use (&$read_term_ids, &$read_post_ids): void {
        foreach (array_keys($read_term_ids) as $read_term_id) {
            $read_term_id = (int) $read_term_id;
            if ($read_term_id > 0) {
                wp_cache_delete($read_term_id, 'terms');
                wp_cache_delete($read_term_id, 'term_meta');
            }
        }
        foreach (array_keys($read_post_ids) as $read_post_id) {
            $read_post_id = (int) $read_post_id;
            if ($read_post_id > 0) {
                wp_cache_delete($read_post_id, 'posts');
                wp_cache_delete($read_post_id, 'post_meta');
            }
        }
    };

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $post_id = (int) ($row['post_id'] ?? 0);
        $stored_term_id = (int) ($row['category_id'] ?? 0);
        if ($post_id <= 0 || $stored_term_id <= 0) {
            continue;
        }
        $read_post_ids[$post_id] = true;
        $read_term_ids[$stored_term_id] = true;

        $term_id = $stored_term_id;
        if ($filtered_wordset_id > 0 && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $wpdb->last_error = '';
            $effective_term_id = (int) ll_tools_get_effective_category_id_for_wordset($stored_term_id, $filtered_wordset_id, false);
            if ($wpdb->last_error !== '') {
                $complete = false;
                continue;
            }
            if ($effective_term_id > 0) {
                $term_id = $effective_term_id;
                $read_term_ids[$term_id] = true;
            }
        }
        if (
            is_array($allowed_lookup)
            && !isset($allowed_lookup[$term_id])
            && !isset($allowed_lookup[$stored_term_id])
        ) {
            continue;
        }

        $wpdb->last_error = '';
        $term = get_term($term_id, 'word-category');
        if (is_wp_error($term) || $wpdb->last_error !== '') {
            $complete = false;
            continue;
        }
        if (!($term instanceof WP_Term)) {
            continue;
        }
        if (function_exists('ll_tools_user_can_view_category')) {
            $wpdb->last_error = '';
            $can_view_category = ll_tools_user_can_view_category($term);
            if ($wpdb->last_error !== '') {
                $complete = false;
                continue;
            }
            if (!$can_view_category) {
                continue;
            }
        }

        $candidates[] = [
            'post_id' => $post_id,
            'stored_term_id' => $stored_term_id,
            'term_id' => $term_id,
            'term' => $term,
        ];
        $category_ids_for_meta[$term_id] = true;
        $category_ids_for_meta[$stored_term_id] = true;
    }
    if ($candidates === []) {
        if (!$complete) {
            $clear_incomplete_object_caches();
        }
        return ['items' => [], 'complete' => $complete];
    }

    $category_meta_map = [];
    $has_processed_category_meta = function_exists('ll_flashcards_build_categories');
    if ($has_processed_category_meta) {
        $processed_categories_complete = true;
        $wpdb->last_error = '';
        [$processed_categories] = ll_flashcards_build_categories(
            implode(',', array_map('strval', array_keys($category_ids_for_meta))),
            $use_translations,
            $wordset_ids,
            0,
            false,
            $processed_categories_complete
        );
        $complete = $complete && $processed_categories_complete && $wpdb->last_error === '';
        foreach ((array) $processed_categories as $category_meta) {
            $category_id = is_array($category_meta) ? (int) ($category_meta['id'] ?? 0) : 0;
            if ($category_id > 0) {
                $category_meta_map[$category_id] = $category_meta;
            }
        }
    }

    $base_items = [];
    $gender_category_ids_by_wordset = [];
    foreach ($candidates as $candidate) {
        $post_id = (int) $candidate['post_id'];
        $stored_term_id = (int) $candidate['stored_term_id'];
        $term_id = (int) $candidate['term_id'];
        $term = $candidate['term'];
        $category_meta = $category_meta_map[$term_id] ?? ($category_meta_map[$stored_term_id] ?? null);
        if ($has_processed_category_meta && !is_array($category_meta)) {
            continue;
        }
        if (!is_array($category_meta)) {
            $eligibility_complete = true;
            $can_generate_quiz = ll_can_category_generate_quiz(
                $term,
                $min_word_count,
                $wordset_ids,
                $eligibility_complete
            );
            $complete = $complete && $eligibility_complete;
            if (!$can_generate_quiz) {
                continue;
            }
        }

        if (is_array($category_meta)) {
            $config = [
                'prompt_type' => $category_meta['prompt_type'] ?? 'audio',
                'option_type' => $category_meta['option_type'] ?? ($category_meta['mode'] ?? 'image'),
                'learning_prompt_type' => $category_meta['learning_prompt_type'] ?? '',
                'learning_option_type' => $category_meta['learning_option_type'] ?? '',
                'learning_supported' => $category_meta['learning_supported'] ?? true,
                'self_check_supported' => $category_meta['self_check_supported'] ?? true,
                'sign_language_mode' => !empty($category_meta['sign_language_mode']),
                'use_titles' => $category_meta['use_titles'] ?? false,
            ];
        } elseif (function_exists('ll_tools_get_category_quiz_config')) {
            $config_complete = true;
            $config = ll_tools_get_category_quiz_config($term, $config_complete);
            $complete = $complete && $config_complete;
        } else {
            $config = ['prompt_type' => 'audio', 'option_type' => 'image', 'learning_supported' => true, 'self_check_supported' => true, 'use_titles' => false];
        }
        $name = html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8');
        $translation = '';
        if ($use_translations) {
            $wpdb->last_error = '';
            $translation_value = get_term_meta($term_id, 'term_translation', true);
            $complete = $complete && $wpdb->last_error === '';
            if (empty($translation_value) && $stored_term_id !== $term_id) {
                $wpdb->last_error = '';
                $translation_value = get_term_meta($stored_term_id, 'term_translation', true);
                $complete = $complete && $wpdb->last_error === '';
            }
            if (!empty($translation_value)) {
                $translation = html_entity_decode((string) $translation_value, ENT_QUOTES, 'UTF-8');
            }
        }

        $wordset_slug = '';
        $wordset_id_for_item = 0;
        if (!empty($opts['wordset'])) {
            $wordset_slug = sanitize_text_field((string) $opts['wordset']);
            $wordset_id_for_item = $filtered_wordset_id;
        } else {
            $default_wordset_complete = true;
            $default_wordset_id = (int) ll_get_default_wordset_id_for_category(
                $term,
                $min_word_count,
                $default_wordset_complete
            );
            $complete = $complete && $default_wordset_complete;
            if ($default_wordset_id > 0) {
                $read_term_ids[$default_wordset_id] = true;
            }
            if (
                $default_wordset_id > 0
                && function_exists('ll_can_category_generate_quiz')
            ) {
                $default_eligibility_complete = true;
                $default_is_eligible = ll_can_category_generate_quiz(
                    $term,
                    $min_word_count,
                    [$default_wordset_id],
                    $default_eligibility_complete
                );
                $complete = $complete && $default_eligibility_complete;
                if (!$default_is_eligible) {
                    $default_wordset_id = 0;
                }
            }
            if ($default_wordset_id > 0) {
                $wpdb->last_error = '';
                $default_wordset = get_term($default_wordset_id, 'wordset');
                if ($default_wordset instanceof WP_Term && !is_wp_error($default_wordset)) {
                    $wordset_slug = (string) $default_wordset->slug;
                    $wordset_id_for_item = $default_wordset_id;
                } else {
                    $complete = false;
                }
                $complete = $complete && $wpdb->last_error === '';
            }
        }
        if ($wordset_id_for_item > 0) {
            $gender_category_ids_by_wordset[$wordset_id_for_item][$term_id] = true;
        }

        $option_type = (string) ($config['option_type'] ?? 'image');
        $wpdb->last_error = '';
        $permalink = get_permalink($post_id);
        $complete = $complete && $wpdb->last_error === '' && is_string($permalink) && $permalink !== '';
        $autoplay_text_audio_answer_options = false;
        if ($wordset_id_for_item > 0 && function_exists('ll_tools_should_autoplay_text_audio_answer_options')) {
            $wpdb->last_error = '';
            $autoplay_text_audio_answer_options = ll_tools_should_autoplay_text_audio_answer_options([$wordset_id_for_item]);
            $complete = $complete && $wpdb->last_error === '';
        }
        $base_items[] = [
            'post_id' => $post_id,
            'permalink' => is_string($permalink) ? $permalink : '',
            'slug' => (string) $term->slug,
            'term_id' => $term_id,
            'name' => $name,
            'translation' => $translation,
            'display_name' => $translation !== '' ? $translation : $name,
            'wordset_slug' => $wordset_slug,
            'wordset_id' => $wordset_id_for_item,
            'autoplay_text_audio_answer_options' => $autoplay_text_audio_answer_options,
            'display_mode' => $option_type,
            'option_type' => $option_type,
            'prompt_type' => (string) ($config['prompt_type'] ?? 'audio'),
            'learning_prompt_type' => (string) ($config['learning_prompt_type'] ?? ''),
            'learning_option_type' => (string) ($config['learning_option_type'] ?? ''),
            'learning_supported' => $config['learning_supported'] ?? true,
            'self_check_supported' => $config['self_check_supported'] ?? true,
            'sign_language_mode' => !empty($config['sign_language_mode']),
            'use_titles' => !empty($config['use_titles']),
            'word_count' => is_array($category_meta) ? max(0, (int) ($category_meta['word_count'] ?? 0)) : 0,
            'aspect_bucket' => is_array($category_meta) ? (string) ($category_meta['aspect_bucket'] ?? 'no-image') : 'no-image',
        ];
    }

    $gender_config_by_wordset = [];
    foreach ($gender_category_ids_by_wordset as $wordset_id => $category_id_map) {
        $wordset_id = (int) $wordset_id;
        $wpdb->last_error = '';
        $enabled = function_exists('ll_tools_wordset_has_grammatical_gender')
            && ll_tools_wordset_has_grammatical_gender($wordset_id);
        $complete = $complete && $wpdb->last_error === '';
        $wpdb->last_error = '';
        $options = ($enabled && function_exists('ll_tools_wordset_get_gender_options'))
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        $complete = $complete && $wpdb->last_error === '';
        $options = array_values(array_filter(array_map('strval', (array) $options), static function (string $value): bool {
            return $value !== '';
        }));
        $wpdb->last_error = '';
        $visual_config = ($enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
            ? ll_tools_wordset_get_gender_visual_config($wordset_id)
            : [];
        $complete = $complete && $wpdb->last_error === '';
        $support_map = [];
        if ($enabled && $has_processed_category_meta) {
            $gender_categories_complete = true;
            $wpdb->last_error = '';
            [$gender_categories] = ll_flashcards_build_categories(
                implode(',', array_map('strval', array_keys($category_id_map))),
                $use_translations,
                [$wordset_id],
                0,
                false,
                $gender_categories_complete
            );
            $complete = $complete && $gender_categories_complete && $wpdb->last_error === '';
            foreach ((array) $gender_categories as $gender_category) {
                $category_id = is_array($gender_category) ? (int) ($gender_category['id'] ?? 0) : 0;
                if ($category_id > 0) {
                    $support_map[$category_id] = !empty($gender_category['gender_supported']);
                }
            }
        }
        $gender_config_by_wordset[$wordset_id] = [
            'enabled' => $enabled,
            'options' => $options,
            'visual_config' => is_array($visual_config) ? $visual_config : [],
            'support_map' => $support_map,
        ];
    }

    foreach ($base_items as &$item) {
        $wordset_id = (int) ($item['wordset_id'] ?? 0);
        $term_id = (int) ($item['term_id'] ?? 0);
        $gender_config = $gender_config_by_wordset[$wordset_id] ?? [];
        $item['gender_enabled'] = !empty($gender_config['enabled']);
        $item['gender_options'] = array_values((array) ($gender_config['options'] ?? []));
        $item['gender_visual_config'] = is_array($gender_config['visual_config'] ?? null)
            ? $gender_config['visual_config']
            : [];
        $item['gender_supported'] = !empty($gender_config['support_map'][$term_id]);
    }
    unset($item);

    if (!$complete) {
        // WordPress metadata priming can cache an empty array after a failed
        // query. Evict only this bounded batch's objects so the next worker
        // request cannot mistake that failure artifact for complete metadata.
        $clear_incomplete_object_caches();
    }
    return ['items' => $base_items, 'complete' => $complete];
}

function ll_tools_quiz_pages_catalog_schedule_refresh(array $scope): void {
    $scope_id = (string) $scope['id'];
    $state_option = ll_tools_quiz_pages_catalog_option_name('state', $scope_id);
    $state = get_option($state_option, []);
    $state_is_valid = is_array($state)
        && !empty($state['__ll_quiz_pages_catalog_state'])
        && is_array($state['scope'] ?? null)
        && (string) ($state['scope']['id'] ?? '') === $scope_id
        && preg_match('/^[a-f0-9]{12}$/D', (string) ($state['generation'] ?? '')) === 1
        && is_array($state['chunks'] ?? null);
    $cache_key_changed = $state_is_valid
        && (string) ($state['cache_key'] ?? '') !== (string) $scope['cache_key'];
    $builder_is_compatible = $state_is_valid
        && ll_tools_quiz_pages_catalog_scopes_share_builder((array) $state['scope'], $scope);
    $latest_payload = $cache_key_changed
        ? ll_tools_quiz_pages_catalog_latest_payload($scope)
        : null;
    $needs_reset = !$state_is_valid || !$builder_is_compatible || (
        $cache_key_changed
        && ll_tools_quiz_pages_catalog_snapshot_usable_for_scope($latest_payload, $scope)
    );
    if ($needs_reset) {
        $state_token = ll_tools_quiz_pages_catalog_lock_acquire($scope_id);
        if ($state_token !== '') {
            try {
                $state = get_option($state_option, []);
                $state_is_valid = is_array($state)
                    && !empty($state['__ll_quiz_pages_catalog_state'])
                    && is_array($state['scope'] ?? null)
                    && (string) ($state['scope']['id'] ?? '') === $scope_id
                    && preg_match('/^[a-f0-9]{12}$/D', (string) ($state['generation'] ?? '')) === 1
                    && is_array($state['chunks'] ?? null);
                $cache_key_changed = $state_is_valid
                    && (string) ($state['cache_key'] ?? '') !== (string) $scope['cache_key'];
                $builder_is_compatible = $state_is_valid
                    && ll_tools_quiz_pages_catalog_scopes_share_builder((array) $state['scope'], $scope);
                $latest_payload = $cache_key_changed
                    ? ll_tools_quiz_pages_catalog_latest_payload($scope)
                    : null;
                $needs_reset = !$state_is_valid || !$builder_is_compatible || (
                    $cache_key_changed
                    && ll_tools_quiz_pages_catalog_snapshot_usable_for_scope($latest_payload, $scope)
                );
                if ($needs_reset) {
                    $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null);
                    ll_tools_quiz_pages_catalog_cleanup_build_state(
                        $state,
                        ll_tools_quiz_pages_catalog_snapshot_chunk_options($latest)
                    );
                    update_option($state_option, ll_tools_quiz_pages_catalog_new_build_state($scope), false);
                }
            } finally {
                ll_tools_quiz_pages_catalog_lock_release($scope_id, $state_token);
            }
        }
    }

    $hook = 'll_tools_quiz_pages_catalog_refresh_event';
    $args = [$scope_id];
    if (!wp_next_scheduled($hook, $args)) {
        wp_schedule_single_event(time() + 1, $hook, $args);
    }
}

function ll_tools_quiz_pages_catalog_refresh_event(string $scope_id): void {
    $state_option = ll_tools_quiz_pages_catalog_option_name('state', $scope_id);
    $state = get_option($state_option, []);
    if (empty($state['__ll_quiz_pages_catalog_state']) || !is_array($state['scope'] ?? null)) {
        return;
    }
    $token = ll_tools_quiz_pages_catalog_lock_acquire($scope_id);
    if ($token === '') {
        $scope = (array) $state['scope'];
        ll_tools_quiz_pages_catalog_schedule_refresh($scope);
        return;
    }

    $state = get_option($state_option, []);
    if (empty($state['__ll_quiz_pages_catalog_state']) || !is_array($state['scope'] ?? null)) {
        ll_tools_quiz_pages_catalog_lock_release($scope_id, $token);
        return;
    }
    if (
        preg_match('/^[a-f0-9]{12}$/D', (string) ($state['generation'] ?? '')) !== 1
        || !is_array($state['chunks'] ?? null)
    ) {
        $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null);
        ll_tools_quiz_pages_catalog_cleanup_build_state(
            $state,
            ll_tools_quiz_pages_catalog_snapshot_chunk_options($latest)
        );
        $state = ll_tools_quiz_pages_catalog_new_build_state((array) $state['scope']);
        update_option($state_option, $state, false);
    }

    $global_lock_id = md5('ll-tools-quiz-pages-catalog-global-refresh');
    $global_token = ll_tools_quiz_pages_catalog_lock_acquire($global_lock_id);
    if ($global_token === '') {
        ll_tools_quiz_pages_catalog_schedule_refresh((array) $state['scope']);
        ll_tools_quiz_pages_catalog_lock_release($scope_id, $token);
        return;
    }

    // Leave a continuation queued before doing work so a fatal interruption can retry the durable state.
    ll_tools_quiz_pages_catalog_schedule_refresh((array) $state['scope']);

    $old_user_id = (int) get_current_user_id();
    $switched_locale = false;
    $reschedule_scope = null;
    try {
        $stored_scope = (array) $state['scope'];
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user((int) ($stored_scope['user_id'] ?? 0));
        }
        $locale = (string) ($stored_scope['locale'] ?? '');
        if ($locale !== '' && function_exists('switch_to_locale')) {
            $switched_locale = switch_to_locale($locale);
        }

        $opts = is_array($stored_scope['opts'] ?? null) ? $stored_scope['opts'] : [];
        $min_word_count = (int) ($stored_scope['min_word_count'] ?? LL_TOOLS_MIN_WORDS_PER_QUIZ);
        $current_scope = ll_tools_quiz_pages_catalog_scope($opts, $min_word_count);
        if (empty($current_scope['source_complete'])) {
            throw new RuntimeException('Quiz catalog source resolution failed.');
        }
        $working_scope = $current_scope;
        $builder_is_compatible = ll_tools_quiz_pages_catalog_scopes_share_builder(
            $stored_scope,
            $current_scope
        );
        $scope_cache_changed = (string) ($state['cache_key'] ?? '') !== (string) $current_scope['cache_key'];
        $has_usable_latest = false;
        if ($scope_cache_changed && $builder_is_compatible) {
            $latest_payload = ll_tools_quiz_pages_catalog_latest_payload($stored_scope);
            $has_usable_latest = is_array($latest_payload)
                && ll_tools_quiz_pages_catalog_snapshot_usable_for_scope($latest_payload, $current_scope);
            if (!$has_usable_latest) {
                // A cold catalog must be allowed to finish one durable snapshot
                // even while unrelated background maintenance advances a global
                // cache epoch. The next foreground read will serve this snapshot
                // stale-while-refresh and queue the newer generation.
                $working_scope = $stored_scope;
            }
        }

        if (!$builder_is_compatible || ($scope_cache_changed && $has_usable_latest)) {
            if (!ll_tools_quiz_pages_catalog_worker_can_persist(
                $state_option,
                $state,
                $scope_id,
                $token,
                $global_lock_id,
                $global_token
            )) {
                throw new RuntimeException('Quiz catalog refresh lock expired.');
            }
            $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null);
            ll_tools_quiz_pages_catalog_cleanup_build_state(
                $state,
                ll_tools_quiz_pages_catalog_snapshot_chunk_options($latest)
            );
            $state = ll_tools_quiz_pages_catalog_new_build_state($current_scope);
            update_option($state_option, $state, false);
            $reschedule_scope = $current_scope;
        } else {
            $context = ll_tools_quiz_pages_catalog_build_context($working_scope);
            if (empty($context['source_complete'])) {
                throw new RuntimeException('Quiz catalog source query failed.');
            }
            $source_index = max(0, (int) ($state['source_index'] ?? 0));
            $post_types = array_values((array) ($context['post_types'] ?? []));
            $batch_size = ll_tools_quiz_pages_catalog_rebuild_batch_size();
            $completed = empty($context['valid']) || $source_index >= count($post_types);

            if (!$completed) {
                $batch = ll_tools_quiz_pages_catalog_query_page_batch(
                    $context,
                    $source_index,
                    max(0, (int) ($state['cursor'] ?? 0)),
                    $batch_size
                );
                if (empty($batch['complete'])) {
                    throw new RuntimeException('Quiz catalog page query failed.');
                }
                $rows = array_values((array) ($batch['rows'] ?? []));
                // The completed catalog chunk is the durable cache for this
                // maintenance flow. Avoid amplifying a cold batch into hundreds
                // of short-lived wp_options writes on sites without a persistent
                // object cache; lower-level request/object caches are still used.
                $skip_derived_transient = static function (): bool {
                    return false;
                };
                add_filter('ll_tools_flashcard_categories_persist_transient', $skip_derived_transient);
                add_filter('ll_tools_words_count_persist_transient', $skip_derived_transient);
                add_filter('ll_tools_category_aspect_persist_transient', $skip_derived_transient);
                add_filter('ll_tools_default_quiz_wordset_persist_transient', $skip_derived_transient);
                add_filter('ll_tools_can_category_generate_quiz_persist_transient', $skip_derived_transient);
                try {
                    $build_result = ll_tools_quiz_pages_catalog_build_items_for_page_rows($rows, $context);
                } finally {
                    remove_filter('ll_tools_can_category_generate_quiz_persist_transient', $skip_derived_transient);
                    remove_filter('ll_tools_default_quiz_wordset_persist_transient', $skip_derived_transient);
                    remove_filter('ll_tools_category_aspect_persist_transient', $skip_derived_transient);
                    remove_filter('ll_tools_words_count_persist_transient', $skip_derived_transient);
                    remove_filter('ll_tools_flashcard_categories_persist_transient', $skip_derived_transient);
                }
                if (empty($build_result['complete'])) {
                    throw new RuntimeException('Quiz catalog item materialization was incomplete.');
                }
                $items = array_values((array) ($build_result['items'] ?? []));
                if (!ll_tools_quiz_pages_catalog_worker_can_persist(
                    $state_option,
                    $state,
                    $scope_id,
                    $token,
                    $global_lock_id,
                    $global_token
                )) {
                    throw new RuntimeException('Quiz catalog refresh lock expired.');
                }
                if ($items !== []) {
                    $chunk_index = max(0, (int) ($state['chunk_index'] ?? 0));
                    $chunk_option = ll_tools_quiz_pages_catalog_chunk_option_name(
                        $scope_id,
                        (string) ($state['generation'] ?? ''),
                        $chunk_index
                    );
                    $chunk_payload = [
                        '__ll_quiz_pages_catalog_chunk' => 1,
                        'generation' => (string) ($state['generation'] ?? ''),
                        'cache_key' => (string) $working_scope['cache_key'],
                        'items' => $items,
                    ];
                    update_option($chunk_option, $chunk_payload, false);
                    $stored_chunk = get_option($chunk_option, null);
                    if (
                        !is_array($stored_chunk)
                        || empty($stored_chunk['__ll_quiz_pages_catalog_chunk'])
                        || (string) ($stored_chunk['generation'] ?? '') !== (string) ($state['generation'] ?? '')
                    ) {
                        throw new RuntimeException('Could not persist the quiz catalog build chunk.');
                    }
                    $state['chunks'][] = $chunk_option;
                    $state['chunk_index'] = $chunk_index + 1;
                    $state['item_count'] = max(0, (int) ($state['item_count'] ?? 0)) + count($items);
                }

                $state['processed'] = max(0, (int) ($state['processed'] ?? 0)) + count($rows);
                if ($rows !== []) {
                    $last_row = end($rows);
                    $state['cursor'] = is_array($last_row) ? max(0, (int) ($last_row['post_id'] ?? 0)) : 0;
                }
                if (empty($batch['has_more'])) {
                    $state['source_index'] = $source_index + 1;
                    $state['cursor'] = 0;
                }
                $state['status'] = 'queued';
                $state['updated_at'] = time();
                $state['last_error'] = '';
                $completed = (int) ($state['source_index'] ?? 0) >= count($post_types);
            }

            if ($completed) {
                if (!ll_tools_quiz_pages_catalog_worker_can_persist(
                    $state_option,
                    $state,
                    $scope_id,
                    $token,
                    $global_lock_id,
                    $global_token
                )) {
                    throw new RuntimeException('Quiz catalog refresh lock expired.');
                }
                $publish_scope = ll_tools_quiz_pages_catalog_scope($opts, $min_word_count);
                if (empty($publish_scope['source_complete'])) {
                    throw new RuntimeException('Quiz catalog publish scope resolution failed.');
                }
                if ((string) $publish_scope['cache_key'] !== (string) ($state['cache_key'] ?? '')) {
                    if (!$has_usable_latest && ll_tools_quiz_pages_catalog_latest_set_manifest(
                        $working_scope,
                        (string) ($state['generation'] ?? ''),
                        (array) ($state['chunks'] ?? []),
                        (int) ($state['item_count'] ?? 0)
                    )) {
                        delete_option($state_option);
                        wp_clear_scheduled_hook('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]);
                    } else {
                        $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null);
                        ll_tools_quiz_pages_catalog_cleanup_build_state(
                            $state,
                            ll_tools_quiz_pages_catalog_snapshot_chunk_options($latest)
                        );
                        $state = ll_tools_quiz_pages_catalog_new_build_state($publish_scope);
                        update_option($state_option, $state, false);
                        $reschedule_scope = $publish_scope;
                    }
                } elseif (ll_tools_quiz_pages_catalog_latest_set_manifest(
                    $publish_scope,
                    (string) ($state['generation'] ?? ''),
                    (array) ($state['chunks'] ?? []),
                    (int) ($state['item_count'] ?? 0)
                )) {
                    delete_option($state_option);
                    wp_clear_scheduled_hook('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]);
                } else {
                    throw new RuntimeException('Could not publish the quiz catalog snapshot.');
                }
            } else {
                if (!ll_tools_quiz_pages_catalog_worker_can_persist(
                    $state_option,
                    $state,
                    $scope_id,
                    $token,
                    $global_lock_id,
                    $global_token
                )) {
                    throw new RuntimeException('Quiz catalog refresh lock expired.');
                }
                update_option($state_option, $state, false);
                $reschedule_scope = $working_scope;
            }
        }
    } catch (Throwable $error) {
        if (ll_tools_quiz_pages_catalog_worker_can_persist(
            $state_option,
            $state,
            $scope_id,
            $token,
            $global_lock_id,
            $global_token
        )) {
            $latest_state = get_option($state_option, $state);
            if (!is_array($latest_state)) {
                $latest_state = $state;
            }
            $latest_state['status'] = 'queued';
            $latest_state['updated_at'] = time();
            $latest_state['last_error'] = sanitize_text_field($error->getMessage());
            update_option($state_option, $latest_state, false);
        }
        $current_state = get_option($state_option, []);
        $reschedule_scope = is_array($current_state['scope'] ?? null) ? $current_state['scope'] : null;
    } finally {
        if ($switched_locale && function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($old_user_id);
        }
        ll_tools_quiz_pages_catalog_lock_release($global_lock_id, $global_token);
        ll_tools_quiz_pages_catalog_lock_release($scope_id, $token);
    }
    if (is_array($reschedule_scope)) {
        ll_tools_quiz_pages_catalog_schedule_refresh($reschedule_scope);
    }
}
add_action('ll_tools_quiz_pages_catalog_refresh_event', 'll_tools_quiz_pages_catalog_refresh_event', 10, 1);

function ll_tools_quiz_pages_catalog_warmup_status(string $scope_id) {
    $scope_id = strtolower(trim($scope_id));
    if (!preg_match('/^[a-f0-9]{32}$/D', $scope_id)) {
        return new WP_Error('ll_quiz_catalog_invalid_scope', __('Invalid request.', 'll-tools-text-domain'));
    }

    if (ll_tools_quiz_pages_catalog_snapshot_ready($scope_id)) {
        return ['ready' => true, 'retry_after_ms' => 0];
    }

    $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), []);
    if (empty($state['__ll_quiz_pages_catalog_state']) || !is_array($state['scope'] ?? null)) {
        return new WP_Error('ll_quiz_catalog_not_pending', __('Something went wrong. Please try again.', 'll-tools-text-domain'));
    }

    ll_tools_quiz_pages_catalog_refresh_event($scope_id);

    return [
        'ready' => ll_tools_quiz_pages_catalog_snapshot_ready($scope_id),
        'retry_after_ms' => 1200,
    ];
}

function ll_tools_quiz_pages_catalog_warmup_ajax(): void {
    $scope_id = isset($_POST['scope_id']) && is_scalar($_POST['scope_id'])
        ? strtolower(sanitize_text_field(wp_unslash($_POST['scope_id'])))
        : '';
    if (
        !preg_match('/^[a-f0-9]{32}$/D', $scope_id)
        || !check_ajax_referer('ll_quiz_catalog_warmup_' . $scope_id, 'nonce', false)
    ) {
        wp_send_json_error(['message' => __('Invalid request.', 'll-tools-text-domain')], 403);
    }

    $status = ll_tools_quiz_pages_catalog_warmup_status($scope_id);
    if (is_wp_error($status)) {
        wp_send_json_error(['message' => $status->get_error_message()], 409);
    }

    wp_send_json_success($status);
}
add_action('wp_ajax_ll_quiz_pages_catalog_warmup', 'll_tools_quiz_pages_catalog_warmup_ajax');
add_action('wp_ajax_nopriv_ll_quiz_pages_catalog_warmup', 'll_tools_quiz_pages_catalog_warmup_ajax');

function ll_tools_quiz_pages_catalog_remove_cron_spawn_callbacks(): void {
    // WordPress 6.9+ registers the callback on shutdown; older versions and
    // ALTERNATE_WP_CRON use wp_loaded instead.
    remove_action('shutdown', '_wp_cron', 10);
    remove_action('wp_loaded', '_wp_cron', 20);
}

function ll_tools_quiz_pages_catalog_manual_refresh_scope_id(): string {
    $scope_id = isset($_GET['ll_quiz_catalog_refresh']) && is_scalar($_GET['ll_quiz_catalog_refresh'])
        ? strtolower(sanitize_text_field(wp_unslash((string) $_GET['ll_quiz_catalog_refresh'])))
        : '';
    $nonce = isset($_GET['ll_quiz_catalog_nonce']) && is_scalar($_GET['ll_quiz_catalog_nonce'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_quiz_catalog_nonce']))
        : '';
    if (
        !preg_match('/^[a-f0-9]{32}$/D', $scope_id)
        || !wp_verify_nonce($nonce, 'll_quiz_catalog_manual_refresh_' . $scope_id)
    ) {
        return '';
    }

    return $scope_id;
}

/**
 * The warmup request already advances one catalog batch synchronously. Do not
 * also launch unrelated due WP-Cron jobs during its shutdown, because large
 * maintenance queues can contend with the foreground batch and turn a bounded
 * poll into a long user-facing request.
 */
function ll_tools_quiz_pages_catalog_avoid_cron_contention(): void {
    $is_ajax_warmup = false;
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
            ? sanitize_key(wp_unslash((string) $_REQUEST['action']))
            : '';
        $is_ajax_warmup = $action === 'll_quiz_pages_catalog_warmup';
    }
    if (!$is_ajax_warmup && ll_tools_quiz_pages_catalog_manual_refresh_scope_id() === '') {
        return;
    }

    ll_tools_quiz_pages_catalog_remove_cron_spawn_callbacks();
}
add_action('init', 'll_tools_quiz_pages_catalog_avoid_cron_contention', 11);

/**
 * Rebuild the complete materialized quiz-page catalog synchronously.
 * Optional filter: $opts['wordset'] accepts slug/name/id of a WORDSET term.
 * Production cold reads and refresh workers use the chunked generation above.
 * Keep this helper limited to explicit maintenance and compatibility tests.
 */
function ll_tools_quiz_pages_rebuild_catalog_data($opts = []) {
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $cache_key_complete = true;
    $cache_key = ll_tools_quiz_pages_data_cache_key((array) $opts, $min_word_count, $cache_key_complete);
    if (!$cache_key_complete) {
        return [];
    }
    $cached_items = ll_tools_quiz_pages_data_cache_get($cache_key);
    if (is_array($cached_items)) {
        return $cached_items;
    }

    $quiz_page_post_types = function_exists('ll_tools_get_quiz_page_post_types')
        ? ll_tools_get_quiz_page_post_types(true)
        : ['page'];
    $quiz_page_category_meta = defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
        ? LL_TOOLS_QUIZ_PAGE_CATEGORY_META
        : '_ll_tools_word_category_id';

    $allowed_term_ids = null;
    $ws_ids = [];
    $filtered_wordset_id = 0;
    $quiz_page_meta_category_ids = [];
    if (!empty($opts['wordset'])) {
        $wordset_resolution_complete = true;
        $ws_ids = ll_raw_resolve_wordset_term_ids($opts['wordset'], $wordset_resolution_complete);
        if (!$wordset_resolution_complete) return [];
        if (empty($ws_ids)) return []; // nothing by that slug/name/id
        $category_resolution_complete = true;
        $allowed_term_ids = ll_collect_wc_ids_for_wordset_term_ids($ws_ids, $category_resolution_complete);
        if (!$category_resolution_complete) return [];
        if (empty($allowed_term_ids)) return []; // no categories used by that wordset
        $filtered_wordset_id = (int) ($ws_ids[0] ?? 0);

        $quiz_page_meta_category_ids = array_map('intval', (array) $allowed_term_ids);
        $scoped_meta_category_ids = [];
        foreach ($ws_ids as $scope_wordset_id) {
            $scope_wordset_id = (int) $scope_wordset_id;
            if ($scope_wordset_id <= 0) {
                continue;
            }
            $isolation_scope_complete = true;
            $wordset_scoped_ids = ll_tools_quiz_pages_catalog_category_query_scope_for_wordset(
                $quiz_page_meta_category_ids,
                $scope_wordset_id,
                $isolation_scope_complete
            );
            if (!$isolation_scope_complete) {
                return [];
            }
            foreach ($wordset_scoped_ids as $scope_category_id) {
                $scoped_meta_category_ids[] = (int) $scope_category_id;
            }
        }
        if (!empty($scoped_meta_category_ids)) {
            $quiz_page_meta_category_ids = $scoped_meta_category_ids;
        }
        $quiz_page_meta_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $quiz_page_meta_category_ids), static function (int $id): bool {
            return $id > 0;
        })));
        if (empty($quiz_page_meta_category_ids)) return [];
    }

    // Load generated quiz pages, including legacy Page records during migration.
    $page_query_args = [
        'post_type'        => $quiz_page_post_types,
        'post_status'      => 'publish',
        'has_password'     => false,
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'ID',  // Ensure consistent ordering for deduplication
        'order'            => 'ASC',
    ];
    if (!empty($quiz_page_meta_category_ids)) {
        $page_query_args['meta_query'] = [
            [
                'key'     => $quiz_page_category_meta,
                'value'   => $quiz_page_meta_category_ids,
                'compare' => 'IN',
                'type'    => 'NUMERIC',
            ],
        ];
    } else {
        $page_query_args['meta_key'] = $quiz_page_category_meta;
    }

    global $wpdb;
    $wpdb->last_error = '';
    $pages = get_posts($page_query_args);
    $pages_complete = $wpdb->last_error === '';
    if (empty($pages)) {
        if ($pages_complete) {
            ll_tools_quiz_pages_data_cache_set($cache_key, []);
        }
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
 * Read the materialized quiz-page catalog and schedule stale-while-refresh work.
 */
function ll_get_all_quiz_pages_data($opts = []) {
    $opts = is_array($opts) ? $opts : [];
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $scope = ll_tools_quiz_pages_catalog_scope($opts, $min_word_count);
    if (empty($scope['source_complete'])) {
        ll_tools_quiz_pages_catalog_set_status([
            'refreshing' => false,
            'has_snapshot' => false,
            'needs_loading_notice' => true,
            'scope_id' => (string) $scope['id'],
        ]);
        return [];
    }
    $cached_items = ll_tools_quiz_pages_data_cache_get((string) $scope['cache_key']);
    if (is_array($cached_items)) {
        ll_tools_quiz_pages_catalog_latest_set($scope, $cached_items);
        ll_tools_quiz_pages_catalog_set_status([
            'refreshing' => false,
            'has_snapshot' => true,
            'scope_id' => (string) $scope['id'],
        ]);
        return ll_tools_quiz_pages_catalog_filter_visible_items($cached_items, $scope);
    }

    $latest_payload = ll_tools_quiz_pages_catalog_latest_payload($scope);
    $stale_items = is_array($latest_payload)
        ? ll_tools_quiz_pages_catalog_snapshot_items($latest_payload)
        : null;
    if (
        is_array($stale_items)
        && (string) ($latest_payload['cache_key'] ?? '') === (string) $scope['cache_key']
    ) {
        ll_tools_quiz_pages_data_cache_set((string) $scope['cache_key'], $stale_items);
        ll_tools_quiz_pages_catalog_set_status([
            'refreshing' => false,
            'has_snapshot' => true,
            'scope_id' => (string) $scope['id'],
        ]);
        return ll_tools_quiz_pages_catalog_filter_visible_items($stale_items, $scope);
    }
    ll_tools_quiz_pages_catalog_schedule_refresh($scope);

    if (is_array($stale_items)) {
        $visible_stale_items = ll_tools_quiz_pages_catalog_filter_visible_items($stale_items, $scope);
        if (!empty($scope['opts']['wordset']) && !empty($stale_items) && empty($visible_stale_items)) {
            ll_tools_quiz_pages_catalog_set_status([
                'refreshing' => true,
                'has_snapshot' => false,
                'needs_loading_notice' => true,
                'scope_id' => (string) $scope['id'],
            ]);
            return [];
        }
        ll_tools_quiz_pages_catalog_set_status([
            'refreshing' => true,
            'has_snapshot' => true,
            'scope_id' => (string) $scope['id'],
        ]);
        return $visible_stale_items;
    }

    ll_tools_quiz_pages_catalog_set_status([
        'refreshing' => true,
        'has_snapshot' => false,
        'needs_loading_notice' => true,
        'scope_id' => (string) $scope['id'],
    ]);
    return [];
}

function ll_tools_quiz_pages_catalog_loading_notice(): string {
    if (!ll_tools_quiz_pages_catalog_needs_loading_notice()) {
        return '';
    }

    $refresh_url = get_permalink();
    if (!is_string($refresh_url) || $refresh_url === '') {
        $refresh_url = home_url('/');
    }

    $status = $GLOBALS['ll_tools_quiz_pages_catalog_status'] ?? [];
    $scope_id = strtolower((string) ($status['scope_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/D', $scope_id)) {
        return '';
    }

    $manual_scope_id = ll_tools_quiz_pages_catalog_manual_refresh_scope_id();
    if (
        hash_equals($scope_id, $manual_scope_id)
    ) {
        // JavaScript-free fallback: each signed refresh advances one bounded
        // worker batch directly, so disabling traffic-driven cron below cannot
        // strand a cold catalog.
        static $manual_refreshes = [];
        if (empty($manual_refreshes[$scope_id])) {
            $manual_refreshes[$scope_id] = true;
            ll_tools_quiz_pages_catalog_warmup_status($scope_id);
        }
    }

    $manual_refresh_url = add_query_arg([
        'll_quiz_catalog_refresh' => $scope_id,
        'll_quiz_catalog_nonce' => wp_create_nonce('ll_quiz_catalog_manual_refresh_' . $scope_id),
    ], $refresh_url);

    // The browser warmup loop owns this cold generation. Do not launch the
    // site's unrelated due maintenance queue when the loading shell exits.
    ll_tools_quiz_pages_catalog_remove_cron_spawn_callbacks();
    ll_enqueue_asset_by_timestamp('/js/quiz-pages-shortcodes.js', 'll-quiz-pages-shortcodes-js', [], true);

    return '<p class="ll-quiz-pages-catalog-status" role="status" aria-live="polite"'
        . ' data-ll-quiz-catalog-status="1"'
        . ' data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
        . ' data-action="ll_quiz_pages_catalog_warmup"'
        . ' data-nonce="' . esc_attr(wp_create_nonce('ll_quiz_catalog_warmup_' . $scope_id)) . '"'
        . ' data-scope-id="' . esc_attr($scope_id) . '"'
        . ' data-refresh-url="' . esc_url($refresh_url) . '"'
        . ' data-retry-ms="1200" data-max-attempts="' . esc_attr((string) ll_tools_quiz_pages_catalog_warmup_max_attempts()) . '">'
        . esc_html__('Loading quiz...', 'll-tools-text-domain')
        . ' <a href="' . esc_url($manual_refresh_url) . '">'
        . esc_html__('Refresh', 'll-tools-text-domain')
        . '</a></p>';
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
 * @param bool|null $complete Set false when a source read fails. Incomplete
 *                            results are never cached, including request-local 0.
 * @return int Wordset ID or 0 if none found
 */
function ll_get_default_wordset_id_for_category($category, int $min_word_count = 5, ?bool &$complete = null): int {
    global $wpdb;

    $complete = true;
    $wpdb->last_error = '';
    $cat_term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (is_wp_error($cat_term) || $wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    if (!($cat_term instanceof WP_Term)) {
        return 0;
    }

    $term_id = (int) $cat_term->term_id;
    $wpdb->last_error = '';
    $category_version = function_exists('ll_tools_get_category_cache_version')
        ? max(1, (int) ll_tools_get_category_cache_version($term_id))
        : 1;
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    $wpdb->last_error = '';
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    $wpdb->last_error = '';
    $content_fallback_epoch = function_exists('ll_tools_get_quiz_content_fallback_epoch')
        ? ll_tools_get_quiz_content_fallback_epoch()
        : 'qcf-unavailable';
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    $cache_key = 'll_default_quiz_ws_' . md5(wp_json_encode([
        'term_id' => $term_id,
        'min_words' => $min_word_count,
        'category_version' => $category_version,
        'wordset_epoch' => $wordset_epoch,
        'content_fallback_epoch' => $content_fallback_epoch,
        'user_id' => (int) get_current_user_id(),
        'schema' => 3,
    ]));
    $cache_group = 'll_tools_quiz_pages';
    $cache_ttl = HOUR_IN_SECONDS;

    static $request_cache = [];
    if (array_key_exists($cache_key, $request_cache)) {
        return (int) $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached === false) {
        $wpdb->last_error = '';
        $cached = get_transient($cache_key);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return 0;
        }
    }
    if (is_array($cached) && isset($cached['__ll_default_quiz_wordset_cache'])) {
        $default_id = max(0, (int) ($cached['wordset_id'] ?? 0));
        $request_cache[$cache_key] = $default_id;
        return $default_id;
    }

    $store_result = static function (int $wordset_id) use ($cache_key, $cache_group, $cache_ttl, $term_id, &$request_cache): int {
        $wordset_id = max(0, $wordset_id);
        $payload = [
            '__ll_default_quiz_wordset_cache' => 1,
            'wordset_id' => $wordset_id,
        ];

        $request_cache[$cache_key] = $wordset_id;
        wp_cache_set($cache_key, $payload, $cache_group, $cache_ttl);
        if ((bool) apply_filters(
            'll_tools_default_quiz_wordset_persist_transient',
            true,
            $cache_key,
            $term_id,
            $wordset_id
        )) {
            set_transient($cache_key, $payload, $cache_ttl);
        }

        return $wordset_id;
    };

    // If this category is already an isolated copy, keep its owner wordset as
    // the preferred default when it can generate a quiz.
    $wpdb->last_error = '';
    $owner_wordset_id = function_exists('ll_tools_get_category_wordset_owner_id')
        ? (int) ll_tools_get_category_wordset_owner_id($cat_term)
        : 0;
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    if ($owner_wordset_id > 0 && function_exists('ll_can_category_generate_quiz')) {
        $owner_eligibility_complete = true;
        $owner_is_eligible = ll_can_category_generate_quiz(
            $cat_term,
            $min_word_count,
            [$owner_wordset_id],
            $owner_eligibility_complete
        );
        if (!$owner_eligibility_complete) {
            $complete = false;
            return 0;
        }
        if ($owner_is_eligible) {
            return $store_result($owner_wordset_id);
        }
    }

    // Get all wordset IDs ordered by term_id (assuming lower IDs are older).
    $wpdb->last_error = '';
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'term_id',
        'order'      => 'ASC',
        'fields'     => 'ids',
        'cache_results' => false,
    ]);
    if (is_wp_error($wordsets) || $wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    if (empty($wordsets)) {
        return $store_result(0);
    }

    foreach ($wordsets as $ws_id) {
        $ws_id = (int) $ws_id;
        if ($ws_id <= 0 || $ws_id === $owner_wordset_id) {
            continue;
        }

        if (function_exists('ll_can_category_generate_quiz')) {
            $wordset_eligibility_complete = true;
            $wordset_is_eligible = ll_can_category_generate_quiz(
                $cat_term,
                $min_word_count,
                [$ws_id],
                $wordset_eligibility_complete
            );
            if (!$wordset_eligibility_complete) {
                $complete = false;
                return 0;
            }
            if ($wordset_is_eligible) {
                return $store_result($ws_id);
            }
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

        function normalizeQuizTypeHint(raw) {
            return String(raw || '').trim().toLowerCase();
        }

        function readLaunchTriggerAttr(opts, attrName) {
            try {
                if (opts && opts.triggerEl && opts.triggerEl.getAttribute) {
                    return opts.triggerEl.getAttribute(attrName) || '';
                }
            } catch (_) {}
            return '';
        }

        function normalizeCategoryLookupKey(value) {
            return String(value || '').trim().toLowerCase();
        }

        function syncLaunchCategoryPresentation(catName, opts) {
            if (!window.llToolsFlashcardsData || !catName) {
                return;
            }

            var displayModeHint = normalizeQuizTypeHint(
                (opts && (opts.displayMode || opts.display_mode)) ||
                readLaunchTriggerAttr(opts, 'data-display-mode')
            );
            var promptTypeHint = normalizeQuizTypeHint(
                (opts && (opts.promptType || opts.prompt_type)) ||
                readLaunchTriggerAttr(opts, 'data-prompt-type')
            );
            var optionTypeHint = normalizeQuizTypeHint(
                (opts && (opts.optionType || opts.option_type)) ||
                readLaunchTriggerAttr(opts, 'data-option-type')
            );
            var learningPromptTypeHint = normalizeQuizTypeHint(
                (opts && (opts.learningPromptType || opts.learning_prompt_type)) ||
                readLaunchTriggerAttr(opts, 'data-learning-prompt-type')
            );
            var learningOptionTypeHint = normalizeQuizTypeHint(
                (opts && (opts.learningOptionType || opts.learning_option_type)) ||
                readLaunchTriggerAttr(opts, 'data-learning-option-type')
            );

            if (!displayModeHint && !promptTypeHint && !optionTypeHint && !learningPromptTypeHint && !learningOptionTypeHint) {
                return;
            }

            var categories = Array.isArray(window.llToolsFlashcardsData.categories)
                ? window.llToolsFlashcardsData.categories
                : (window.llToolsFlashcardsData.categories = []);
            var targetKey = normalizeCategoryLookupKey(catName);
            var found = null;
            for (var i = 0; i < categories.length; i++) {
                var c = categories[i];
                if (!c || typeof c !== 'object') {
                    continue;
                }
                if (c.name === catName || normalizeCategoryLookupKey(c.name) === targetKey || normalizeCategoryLookupKey(c.slug) === targetKey) {
                    found = c;
                    break;
                }
            }

            if (!found) {
                found = {
                    id: 0,
                    slug: '',
                    name: catName,
                    translation: catName,
                    mode: displayModeHint || optionTypeHint || 'image',
                    option_type: optionTypeHint || displayModeHint || 'image',
                    prompt_type: promptTypeHint || 'audio'
                };
                categories.push(found);
            }

            if (displayModeHint) {
                found.mode = displayModeHint;
            }
            if (optionTypeHint) {
                found.option_type = optionTypeHint;
            }
            if (promptTypeHint) {
                found.prompt_type = promptTypeHint;
            }
            if (learningPromptTypeHint) {
                found.learning_prompt_type = learningPromptTypeHint;
            }
            if (learningOptionTypeHint) {
                found.learning_option_type = learningOptionTypeHint;
            }
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
                syncLaunchCategoryPresentation(catName, opts || {});
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
    $loading_notice = ll_tools_quiz_pages_catalog_loading_notice();
    if (empty($items)) {
        if ($loading_notice !== '') {
            return $loading_notice;
        }
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
    echo $loading_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.

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
    $loading_notice = ll_tools_quiz_pages_catalog_loading_notice();

    ob_start();

    if (empty($items)) {
        if ($loading_notice !== '') {
            echo $loading_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
            return ob_get_clean();
        }
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
    echo $loading_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.

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
