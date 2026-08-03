<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION', '1');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_BUILDER_SCHEMA')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_BUILDER_SCHEMA', 1);
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION', 'll_tools_flashcard_payload_table_version');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_EXISTS_OPTION')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_EXISTS_OPTION', 'll_tools_flashcard_payload_table_exists');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK', 'll_tools_flashcard_payload_rebuild_batch');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK', 'll_tools_flashcard_payload_cleanup');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION', 'll_tools_fc_payload_cleanup_cursor');
}
if (!defined('LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION')) {
    define('LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION', 'll_tools_fc_payload_orphan_cursor');
}

/**
 * Durable, generation-scoped flashcard rows.
 *
 * Interactive readers never build a whole category. A request may advance one
 * fixed-size materializer batch and otherwise receives a retryable warming
 * response. Completed generations are exposed through signed, byte-bounded
 * keyset pages.
 */
function ll_tools_flashcard_payload_table_name(): string {
    global $wpdb;

    return $wpdb->prefix . 'll_flashcard_payload_rows';
}

function ll_tools_flashcard_payload_table_exists(bool $refresh = false): bool {
    static $cached = null;
    global $wpdb;

    if (!$refresh && is_bool($cached)) {
        if (
            $cached
            && (string) get_option(LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION, '')
                !== LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION
        ) {
            return false;
        }
        return $cached;
    }

    if (!$refresh) {
        $stored = get_option(LL_TOOLS_FLASHCARD_PAYLOAD_EXISTS_OPTION, '');
        if (
            $stored === '1'
            && (string) get_option(LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION, '')
                === LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION
        ) {
            $cached = true;
            return true;
        }
        if ($stored === '0') {
            $cached = false;
            return false;
        }
    }

    $table = ll_tools_flashcard_payload_table_name();
    $cached = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) === $table;
    update_option(LL_TOOLS_FLASHCARD_PAYLOAD_EXISTS_OPTION, $cached ? '1' : '0', false);

    return $cached;
}

function ll_tools_install_flashcard_payload_schema(): bool {
    global $wpdb;

    $table = ll_tools_flashcard_payload_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        scope_hash char(64) NOT NULL,
        generation char(64) NOT NULL,
        category_id bigint(20) unsigned NOT NULL,
        viewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        row_kind varchar(12) NOT NULL,
        row_id bigint(20) unsigned NOT NULL,
        sort_group tinyint(3) unsigned NOT NULL DEFAULT 0,
        payload longtext NOT NULL,
        payload_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (scope_hash, generation, row_kind, row_id),
        KEY idx_scope_page (scope_hash, generation, sort_group, row_id),
        KEY idx_category (category_id),
        KEY idx_updated (updated_at)
    ) {$charset_collate};";

    dbDelta($sql);
    $exists = ll_tools_flashcard_payload_table_exists(true);
    if ($exists) {
        $primary_rows = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        );
        $primary_columns = [];
        foreach ((array) $primary_rows as $primary_row) {
            $sequence = max(1, (int) ($primary_row['Seq_in_index'] ?? 1));
            $primary_columns[$sequence] = (string) ($primary_row['Column_name'] ?? '');
        }
        ksort($primary_columns);
        if (
            array_values($primary_columns)
            !== ['scope_hash', 'generation', 'row_kind', 'row_id']
        ) {
            $primary_change = empty($primary_columns)
                ? 'ADD PRIMARY KEY (scope_hash, generation, row_kind, row_id)'
                : 'DROP PRIMARY KEY, ADD PRIMARY KEY (scope_hash, generation, row_kind, row_id)';
            $wpdb->query("ALTER TABLE {$table} {$primary_change}");
        }
    }

    $column_rows = $exists
        ? $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A)
        : [];
    $columns = [];
    foreach ((array) $column_rows as $column_row) {
        $column_name = (string) ($column_row['Field'] ?? '');
        if ($column_name !== '') {
            $columns[$column_name] = $column_row;
        }
    }
    $column_contracts = [
        'scope_hash' => ['type' => '/^char\(64\)$/'],
        'generation' => ['type' => '/^char\(64\)$/'],
        'category_id' => ['type' => '/^bigint(?:\(20\))? unsigned$/'],
        'viewer_user_id' => [
            'type' => '/^bigint(?:\(20\))? unsigned$/',
            'default' => '0',
        ],
        'row_kind' => ['type' => '/^varchar\(12\)$/'],
        'row_id' => ['type' => '/^bigint(?:\(20\))? unsigned$/'],
        'sort_group' => [
            'type' => '/^tinyint(?:\(3\))? unsigned$/',
            'default' => '0',
        ],
        'payload' => ['type' => '/^longtext$/'],
        'payload_bytes' => [
            'type' => '/^bigint(?:\(20\))? unsigned$/',
            'default' => '0',
        ],
        'updated_at' => ['type' => '/^datetime$/'],
    ];
    $columns_valid = true;
    foreach ($column_contracts as $column_name => $contract) {
        $column = $columns[$column_name] ?? null;
        $type = is_array($column)
            ? strtolower(trim((string) ($column['Type'] ?? '')))
            : '';
        if (
            !is_array($column)
            || strtoupper((string) ($column['Null'] ?? '')) !== 'NO'
            || preg_match((string) $contract['type'], $type) !== 1
            || (
                array_key_exists('default', $contract)
                && (string) ($column['Default'] ?? '') !== (string) $contract['default']
            )
        ) {
            $columns_valid = false;
            break;
        }
    }
    $primary_rows = $exists
        ? $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        )
        : [];
    $page_rows = $exists
        ? $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_scope_page'",
            ARRAY_A
        )
        : [];
    $index_columns = static function (array $rows): array {
        $columns = [];
        foreach ($rows as $row) {
            $sequence = max(1, (int) ($row['Seq_in_index'] ?? 1));
            $columns[$sequence] = (string) ($row['Column_name'] ?? '');
        }
        ksort($columns);
        return array_values($columns);
    };
    $exists = $exists
        && $columns_valid
        && $index_columns((array) $primary_rows)
            === ['scope_hash', 'generation', 'row_kind', 'row_id']
        && $index_columns((array) $page_rows)
            === ['scope_hash', 'generation', 'sort_group', 'row_id'];
    $exists = (bool) apply_filters(
        'll_tools_flashcard_payload_schema_exists_after_install',
        $exists
    );
    update_option(LL_TOOLS_FLASHCARD_PAYLOAD_EXISTS_OPTION, $exists ? '1' : '0', false);
    if (!$exists) {
        delete_option(LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION);
        set_transient('ll_tools_flashcard_payload_schema_retry', 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    update_option(
        LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION,
        LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION,
        false
    );
    delete_transient('ll_tools_flashcard_payload_schema_retry');

    return true;
}

function ll_tools_flashcard_payload_schema_upgrade_is_allowed(): bool {
    if (defined('WP_TESTS_DOMAIN')) {
        return true;
    }
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return true;
    }
    if (!is_admin()) {
        return false;
    }

    return current_user_can('view_ll_tools')
        || current_user_can('manage_options');
}

function ll_tools_maybe_upgrade_flashcard_payload_schema(): void {
    /*
     * Never let an anonymous page or public admin-ajax request become the
     * process that runs dbDelta()/ALTER. Activation, cron, CLI, and an
     * authenticated maintenance request are safe upgrade boundaries.
     */
    if (!ll_tools_flashcard_payload_schema_upgrade_is_allowed()) {
        return;
    }
    $installed = (string) get_option(LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION, '');
    if (
        $installed === LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION
        && ll_tools_flashcard_payload_table_exists()
    ) {
        return;
    }
    if (get_transient('ll_tools_flashcard_payload_schema_retry')) {
        return;
    }

    $install_lock = ll_tools_acquire_flashcard_payload_lock('schema_install', 120);
    if (empty($install_lock['acquired'])) {
        return;
    }
    try {
        $installed = (string) get_option(LL_TOOLS_FLASHCARD_PAYLOAD_VERSION_OPTION, '');
        if (
            $installed === LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION
            && ll_tools_flashcard_payload_table_exists(true)
        ) {
            return;
        }
        set_transient(
            'll_tools_flashcard_payload_schema_retry',
            1,
            5 * MINUTE_IN_SECONDS
        );
        ll_tools_install_flashcard_payload_schema();
    } finally {
        ll_tools_release_flashcard_payload_lock($install_lock);
    }
}
add_action('init', 'll_tools_maybe_upgrade_flashcard_payload_schema', 13);

function ll_tools_flashcard_payload_sanitize_hash($value): string {
    $value = preg_replace('/[^a-f0-9]/', '', strtolower((string) $value));

    return strlen((string) $value) === 64 ? (string) $value : '';
}

function ll_tools_flashcard_payload_generate_generation(string $scope_hash): string {
    $entropy = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : (string) wp_rand();

    return hash('sha256', $scope_hash . '|' . $entropy . '|' . microtime(true));
}

function ll_tools_flashcard_payload_normalize_config(array $config): array {
    $use_titles = !empty($config['use_titles']);
    $prompt_type = function_exists('ll_tools_normalize_quiz_prompt_type')
        ? ll_tools_normalize_quiz_prompt_type(
            (string) ($config['prompt_type'] ?? 'audio'),
            $use_titles
        )
        : sanitize_key((string) ($config['prompt_type'] ?? 'audio'));
    $option_type = function_exists('ll_tools_normalize_quiz_option_type')
        ? ll_tools_normalize_quiz_option_type(
            (string) ($config['option_type'] ?? 'image'),
            $use_titles,
            $prompt_type
        )
        : sanitize_key((string) ($config['option_type'] ?? 'image'));

    return [
        'prompt_type' => $prompt_type !== '' ? $prompt_type : 'audio',
        'option_type' => $option_type !== '' ? $option_type : 'image',
        'use_titles' => $use_titles,
        'sign_language_mode' => !empty($config['sign_language_mode']),
    ];
}

function ll_tools_flashcard_payload_normalize_locale($locale): string {
    $locale = (string) $locale;
    if (function_exists('sanitize_locale_name')) {
        $locale = sanitize_locale_name($locale);
    } else {
        $locale = preg_replace('/[^A-Za-z0-9_-]/', '', $locale);
    }

    return $locale !== '' ? substr($locale, 0, 40) : 'en_US';
}

function ll_tools_flashcard_payload_normalize_scope(array $scope): array {
    $wordset_ids = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($scope['wordset_ids'] ?? [])
    ), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    })));
    sort($wordset_ids, SORT_NUMERIC);

    return [
        'schema' => 1,
        'category_id' => max(0, (int) ($scope['category_id'] ?? 0)),
        'query_category_id' => max(
            0,
            (int) ($scope['query_category_id'] ?? ($scope['category_id'] ?? 0))
        ),
        'category_slug' => sanitize_title((string) ($scope['category_slug'] ?? '')),
        'wordset_ids' => $wordset_ids,
        'viewer_user_id' => max(0, (int) ($scope['viewer_user_id'] ?? 0)),
        'locale' => ll_tools_flashcard_payload_normalize_locale(
            $scope['locale'] ?? get_locale()
        ),
        'config' => ll_tools_flashcard_payload_normalize_config(
            isset($scope['config']) && is_array($scope['config']) ? $scope['config'] : []
        ),
    ];
}

function ll_tools_flashcard_payload_scope_hash(array $scope): string {
    return hash('sha256', (string) wp_json_encode(ll_tools_flashcard_payload_normalize_scope($scope)));
}

function ll_tools_flashcard_payload_category_matches_wordsets(
    int $category_id,
    array $wordset_ids,
    ?bool &$complete = null
): bool {
    global $wpdb;

    $complete = true;
    $category_id = max(0, $category_id);
    $wordset_ids = array_values(array_unique(array_filter(array_map(
        'intval',
        $wordset_ids
    ), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    })));
    if ($category_id <= 0 || empty($wordset_ids)) {
        return true;
    }

    $placeholders = implode(',', array_fill(0, count($wordset_ids), '%d'));
    $prompt_card_type = defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
        ? LL_TOOLS_PROMPT_CARD_POST_TYPE
        : 'll_prompt_card';
    $params = array_merge(
        [
            'words',
            $prompt_card_type,
            'publish',
            'wordset',
        ],
        $wordset_ids,
        [
            'word-category',
            $category_id,
        ]
    );
    $wpdb->last_error = '';
    $matched = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT tt_ws.term_id)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} tr_ws
                 ON tr_ws.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt_ws
                 ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
         INNER JOIN {$wpdb->term_relationships} tr_cat
                 ON tr_cat.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt_cat
                 ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
         WHERE p.post_type IN (%s, %s)
           AND p.post_status = %s
           AND tt_ws.taxonomy = %s
           AND tt_ws.term_id IN ({$placeholders})
           AND tt_cat.taxonomy = %s
           AND tt_cat.term_id = %d
        ",
        $params
    ));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return false;
    }

    return (int) $matched === count($wordset_ids);
}

/**
 * Unscoped public generations are allowed only when the category does not
 * intersect a private wordset. Large or ambiguous intersections require an
 * explicit wordset instead of broadening the query.
 */
function ll_tools_flashcard_payload_unscoped_category_is_public_safe(
    int $category_id,
    ?bool &$complete = null
): bool {
    global $wpdb;

    $complete = true;
    $category_id = max(0, $category_id);
    if ($category_id <= 0) {
        $complete = false;
        return false;
    }
    $category_ids = [$category_id];
    if (defined('LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY')) {
        $wpdb->last_error = '';
        $isolated_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id
             FROM {$wpdb->termmeta}
             WHERE meta_key = %s
               AND meta_value = %s
             ORDER BY term_id ASC
             LIMIT 101",
            LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
            (string) $category_id
        ));
        if (!is_array($isolated_ids) || $wpdb->last_error !== '') {
            $complete = false;
            return false;
        }
        $category_ids = ll_tools_flashcard_payload_unique_ints(
            $category_ids,
            $isolated_ids
        );
        if (count($category_ids) > 100) {
            return false;
        }
    }
    $prompt_card_type = defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
        ? LL_TOOLS_PROMPT_CARD_POST_TYPE
        : 'll_prompt_card';
    $category_placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
    $params = array_merge(
        [
            'words',
            $prompt_card_type,
            'publish',
            'word-category',
        ],
        $category_ids,
        ['wordset']
    );
    $wpdb->last_error = '';
    $wordset_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT tt_ws.term_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} tr_cat
                 ON tr_cat.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt_cat
                 ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
         INNER JOIN {$wpdb->term_relationships} tr_ws
                 ON tr_ws.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt_ws
                 ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
         WHERE p.post_type IN (%s, %s)
           AND p.post_status = %s
           AND tt_cat.taxonomy = %s
           AND tt_cat.term_id IN ({$category_placeholders})
           AND tt_ws.taxonomy = %s
         ORDER BY tt_ws.term_id ASC
         LIMIT 101",
        $params
    ));
    if (!is_array($wordset_ids) || $wpdb->last_error !== '') {
        $complete = false;
        return false;
    }
    $wordset_ids = ll_tools_flashcard_payload_unique_ints($wordset_ids);
    if (count($wordset_ids) > 100) {
        return false;
    }
    foreach ($wordset_ids as $wordset_id) {
        if (!function_exists('ll_tools_is_wordset_private')) {
            continue;
        }
        $wordset_complete = true;
        if (ll_tools_is_wordset_private($wordset_id, $wordset_complete)) {
            return false;
        }
        if (!$wordset_complete) {
            $complete = false;
            return false;
        }
    }

    return true;
}

/**
 * Resolve wordsets visible to an exact viewer without treating viewer 0 as the
 * current signed-in user.
 *
 * @return int[]
 */
function ll_tools_flashcard_payload_filter_accessible_wordsets(
    array $wordset_ids,
    int $viewer_user_id,
    ?bool &$complete = null
): array {
    $complete = true;
    $wordset_ids = ll_tools_flashcard_payload_unique_ints($wordset_ids);
    if (
        $viewer_user_id > 0
        && function_exists('ll_tools_filter_viewable_wordset_ids')
    ) {
        return ll_tools_flashcard_payload_unique_ints(
            ll_tools_filter_viewable_wordset_ids(
                $wordset_ids,
                $viewer_user_id,
                $complete
            )
        );
    }

    $visible = [];
    foreach ($wordset_ids as $wordset_id) {
        if (!function_exists('ll_tools_is_wordset_private')) {
            $visible[] = $wordset_id;
            continue;
        }
        $wordset_complete = true;
        if (!ll_tools_is_wordset_private($wordset_id, $wordset_complete)) {
            $visible[] = $wordset_id;
        }
        if (!$wordset_complete) {
            $complete = false;
            return [];
        }
    }

    return $visible;
}

function ll_tools_flashcard_payload_support_words_are_accessible(
    array $word_ids,
    int $viewer_user_id,
    ?bool &$complete = null
): bool {
    $complete = true;
    $word_ids = ll_tools_flashcard_payload_unique_ints($word_ids);
    if (empty($word_ids) || !function_exists('ll_tools_get_object_term_ids_map')) {
        return true;
    }
    $map_complete = true;
    $wordsets_by_word = ll_tools_get_object_term_ids_map(
        $word_ids,
        'wordset',
        $map_complete
    );
    if (!$map_complete) {
        $complete = false;
        return false;
    }
    $category_map_complete = true;
    $categories_by_word = ll_tools_get_object_term_ids_map(
        $word_ids,
        'word-category',
        $category_map_complete
    );
    if (!$category_map_complete) {
        $complete = false;
        return false;
    }
    foreach ($word_ids as $word_id) {
        $assigned = ll_tools_flashcard_payload_unique_ints(
            (array) ($wordsets_by_word[$word_id] ?? [])
        );
        if (!empty($assigned)) {
            $visibility_complete = true;
            $visible = ll_tools_flashcard_payload_filter_accessible_wordsets(
                $assigned,
                $viewer_user_id,
                $visibility_complete
            );
            if (!$visibility_complete) {
                $complete = false;
                return false;
            }
            if (empty($visible)) {
                return false;
            }
        }

        $assigned_categories = ll_tools_flashcard_payload_unique_ints(
            (array) ($categories_by_word[$word_id] ?? [])
        );
        if (empty($assigned_categories)) {
            continue;
        }
        $category_visible = false;
        foreach ($assigned_categories as $category_id) {
            $category_complete = true;
            if ($viewer_user_id > 0 && function_exists('ll_tools_user_can_view_category')) {
                $can_view = ll_tools_user_can_view_category(
                    $category_id,
                    $viewer_user_id,
                    $category_complete
                );
            } elseif (function_exists('ll_tools_is_category_private')) {
                $can_view = !ll_tools_is_category_private(
                    $category_id,
                    $category_complete
                );
            } else {
                $can_view = true;
            }
            if (!$category_complete) {
                $complete = false;
                return false;
            }
            if ($can_view) {
                $category_visible = true;
                break;
            }
        }
        if (!$category_visible) {
            return false;
        }
    }

    return true;
}

/**
 * Build a storage-safe materializer scope. Public scopes are shared between
 * visitors; content requiring a signed-in viewer is isolated by user ID.
 *
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_flashcard_payload_build_scope(
    WP_Term $term,
    array $wordset_ids,
    array $config
) {
    $wordset_ids = array_values(array_unique(array_filter(array_map(
        'intval',
        $wordset_ids
    ), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    })));
    sort($wordset_ids, SORT_NUMERIC);

    $hard_wordset_limit = max(1, min(20, (int) apply_filters(
        'll_tools_flashcard_payload_scope_wordset_limit',
        1,
        (int) get_current_user_id()
    )));
    if (count($wordset_ids) > $hard_wordset_limit) {
        return new WP_Error(
            'flashcard_payload_scope_too_large',
            __('This flashcard selection includes too many wordsets.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }
    $query_category_id = (int) $term->term_id;
    if (
        count($wordset_ids) === 1
        && function_exists('ll_tools_get_effective_category_id_for_wordset')
    ) {
        $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset(
            (int) $term->term_id,
            (int) $wordset_ids[0],
            false
        );
        if ($effective_category_id > 0) {
            $query_category_id = $effective_category_id;
        }
    }
    $membership_complete = true;
    if (!ll_tools_flashcard_payload_category_matches_wordsets(
        $query_category_id,
        $wordset_ids,
        $membership_complete
    )) {
        return new WP_Error(
            $membership_complete
                ? 'flashcard_payload_scope_not_found'
                : 'flashcard_payload_scope_unavailable',
            $membership_complete
                ? __('No flashcard content is available for that category and wordset.', 'll-tools-text-domain')
                : __('Flashcard scope could not be verified.', 'll-tools-text-domain'),
            ['status' => $membership_complete ? 404 : 503]
        );
    }
    if (empty($wordset_ids)) {
        $unscoped_complete = true;
        if (!ll_tools_flashcard_payload_unscoped_category_is_public_safe(
            (int) $term->term_id,
            $unscoped_complete
        )) {
            return new WP_Error(
                $unscoped_complete
                    ? 'flashcard_payload_wordset_required'
                    : 'flashcard_payload_scope_unavailable',
                $unscoped_complete
                    ? __('Choose a wordset before loading this flashcard category.', 'll-tools-text-domain')
                    : __('Flashcard scope could not be verified.', 'll-tools-text-domain'),
                ['status' => $unscoped_complete ? 400 : 503]
            );
        }
    }

    $visibility_complete = true;
    $category_public = true;
    if (function_exists('ll_tools_is_category_private')) {
        $category_complete = true;
        $category_public = !ll_tools_is_category_private($term, $category_complete);
        $visibility_complete = $visibility_complete && $category_complete;
    }
    $wordsets_public = true;
    if (function_exists('ll_tools_is_wordset_private')) {
        foreach ($wordset_ids as $wordset_id) {
            $wordset_complete = true;
            if (ll_tools_is_wordset_private($wordset_id, $wordset_complete)) {
                $wordsets_public = false;
            }
            $visibility_complete = $visibility_complete && $wordset_complete;
        }
    }
    if (!$visibility_complete) {
        return new WP_Error(
            'flashcard_payload_scope_unavailable',
            __('Flashcard scope could not be verified.', 'll-tools-text-domain'),
            ['status' => 503]
        );
    }
    $is_public_scope = $category_public && $wordsets_public;
    $public_wordset_limit = max(1, min(3, (int) apply_filters(
        'll_tools_flashcard_payload_public_wordset_limit',
        1
    )));
    if ($is_public_scope && count($wordset_ids) > $public_wordset_limit) {
        return new WP_Error(
            'flashcard_payload_scope_too_large',
            __('Public flashcard pages support one wordset at a time.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }
    $viewer_user_id = $is_public_scope ? 0 : (int) get_current_user_id();
    if (!$is_public_scope && $viewer_user_id <= 0) {
        return new WP_Error(
            'flashcard_payload_forbidden',
            __('You do not have permission to view this flashcard content.', 'll-tools-text-domain'),
            ['status' => 403]
        );
    }
    if (!$is_public_scope) {
        $access_complete = true;
        $category_accessible = !function_exists('ll_tools_user_can_view_category')
            || ll_tools_user_can_view_category($term, $viewer_user_id, $access_complete);
        $viewable_wordsets = $wordset_ids;
        if (!empty($wordset_ids) && function_exists('ll_tools_filter_viewable_wordset_ids')) {
            $viewable_wordsets = ll_tools_filter_viewable_wordset_ids(
                $wordset_ids,
                $viewer_user_id,
                $access_complete
            );
            $viewable_wordsets = array_values(array_unique(array_map(
                'intval',
                (array) $viewable_wordsets
            )));
            sort($viewable_wordsets, SORT_NUMERIC);
        }
        if (!$access_complete) {
            return new WP_Error(
                'flashcard_payload_scope_unavailable',
                __('Flashcard scope could not be verified.', 'll-tools-text-domain'),
                ['status' => 503]
            );
        }
        if (!$category_accessible || $viewable_wordsets !== $wordset_ids) {
            return new WP_Error(
                'flashcard_payload_forbidden',
                __('You do not have permission to view this flashcard content.', 'll-tools-text-domain'),
                ['status' => 403]
            );
        }
    }

    $scope = ll_tools_flashcard_payload_normalize_scope([
        'category_id' => (int) $term->term_id,
        'query_category_id' => $query_category_id,
        'category_slug' => (string) $term->slug,
        'wordset_ids' => $wordset_ids,
        'viewer_user_id' => $viewer_user_id,
        'locale' => get_locale(),
        'config' => $config,
    ]);
    $scope['scope_hash'] = ll_tools_flashcard_payload_scope_hash($scope);

    return $scope;
}

function ll_tools_flashcard_payload_dependency_signature(array $scope): string {
    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? (string) ll_tools_get_quiz_content_cache_epoch($scope['wordset_ids'])
        : (string) $category_epoch;
    $category_version = function_exists('ll_tools_get_category_cache_version')
        ? max(1, (int) ll_tools_get_category_cache_version((int) $scope['category_id']))
        : 1;
    $query_category_version = function_exists('ll_tools_get_category_cache_version')
        ? max(1, (int) ll_tools_get_category_cache_version(
            (int) ($scope['query_category_id'] ?? $scope['category_id'])
        ))
        : 1;
    $specific_wrong_source_epoch = function_exists('ll_tools_specific_wrong_answer_owner_map_source_epoch')
        ? max(-1, (int) ll_tools_specific_wrong_answer_owner_map_source_epoch())
        : 0;
    $specific_wrong_integrity = function_exists('ll_tools_specific_wrong_answer_owner_map_integrity')
        ? (string) ll_tools_specific_wrong_answer_owner_map_integrity()
        : '';

    return hash('sha256', (string) wp_json_encode([
        'table_schema' => LL_TOOLS_FLASHCARD_PAYLOAD_TABLE_VERSION,
        'payload_schema' => 2,
        'builder_schema' => LL_TOOLS_FLASHCARD_PAYLOAD_BUILDER_SCHEMA,
        'plugin_version' => defined('LL_TOOLS_VERSION')
            ? (string) LL_TOOLS_VERSION
            : '',
        'scope' => $scope,
        'category_epoch' => $category_epoch,
        'category_version' => $category_version,
        'query_category_version' => $query_category_version,
        'wordset_epoch' => $wordset_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
        'specific_wrong_source_epoch' => $specific_wrong_source_epoch,
        'specific_wrong_integrity' => $specific_wrong_integrity,
        'masked_image_proxy' => function_exists('ll_tools_should_use_masked_image_proxy')
            ? (bool) ll_tools_should_use_masked_image_proxy()
            : true,
        'image_hash_threshold' => function_exists('ll_tools_get_image_hash_threshold')
            ? max(0, (int) ll_tools_get_image_hash_threshold())
            : 5,
    ]));
}

function ll_tools_flashcard_payload_state_option(string $scope_hash): string {
    return 'll_tools_fc_payload_state_' . ll_tools_flashcard_payload_sanitize_hash($scope_hash);
}

function ll_tools_flashcard_payload_lock_option(string $lock_key): string {
    return 'll_tools_fc_payload_lock_' . substr(hash('sha256', $lock_key), 0, 40);
}

function ll_tools_flashcard_payload_sanitize_state(array $raw): array {
    $status = sanitize_key((string) ($raw['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'running', 'completed', 'failed', 'retiring'], true)) {
        $status = 'pending';
    }
    $phase = sanitize_key((string) ($raw['phase'] ?? 'primary'));
    if (!in_array($phase, ['primary', 'prompt', 'cleanup'], true)) {
        $phase = 'primary';
    }
    $scope = ll_tools_flashcard_payload_normalize_scope(
        isset($raw['scope']) && is_array($raw['scope']) ? $raw['scope'] : []
    );

    return [
        'status' => $status,
        'scope_hash' => ll_tools_flashcard_payload_sanitize_hash($raw['scope_hash'] ?? ''),
        'scope' => $scope,
        'signature' => ll_tools_flashcard_payload_sanitize_hash($raw['signature'] ?? ''),
        'generation' => ll_tools_flashcard_payload_sanitize_hash($raw['generation'] ?? ''),
        'published_generation' => ll_tools_flashcard_payload_sanitize_hash(
            $raw['published_generation'] ?? ''
        ),
        'phase' => $phase,
        'primary_cursor' => max(0, (int) ($raw['primary_cursor'] ?? 0)),
        'prompt_cursor' => max(0, (int) ($raw['prompt_cursor'] ?? 0)),
        'processed' => max(0, (int) ($raw['processed'] ?? 0)),
        'row_count' => max(0, (int) ($raw['row_count'] ?? 0)),
        'started_at' => trim((string) ($raw['started_at'] ?? '')),
        'updated_at' => trim((string) ($raw['updated_at'] ?? '')),
        'last_access_at' => trim((string) ($raw['last_access_at'] ?? '')),
        'completed_at' => trim((string) ($raw['completed_at'] ?? '')),
        'last_error' => sanitize_key((string) ($raw['last_error'] ?? '')),
        'retry_count' => max(0, min(20, (int) ($raw['retry_count'] ?? 0))),
        'next_retry_at' => max(0, (int) ($raw['next_retry_at'] ?? 0)),
        'terminal' => !empty($raw['terminal']) ? 1 : 0,
    ];
}

function ll_tools_get_flashcard_payload_state(string $scope_hash): array {
    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    $raw = $scope_hash !== ''
        ? get_option(ll_tools_flashcard_payload_state_option($scope_hash), [])
        : [];

    return ll_tools_flashcard_payload_sanitize_state(is_array($raw) ? $raw : []);
}

function ll_tools_update_flashcard_payload_state(
    string $scope_hash,
    array $state,
    ?string $expected_generation = null,
    ?bool &$updated = null
): array {
    global $wpdb;

    $updated = false;
    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    if ($scope_hash === '') {
        return ll_tools_flashcard_payload_sanitize_state([]);
    }
    $state['scope_hash'] = $scope_hash;
    $state['updated_at'] = current_time('mysql', true);
    $sanitized = ll_tools_flashcard_payload_sanitize_state($state);
    $option_name = ll_tools_flashcard_payload_state_option($scope_hash);

    if ($expected_generation === null) {
        update_option($option_name, $sanitized, false);
        $updated = true;
        return $sanitized;
    }

    $expected_generation = ll_tools_flashcard_payload_sanitize_hash($expected_generation);
    $stored_value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value
         FROM {$wpdb->options}
         WHERE option_name = %s
         LIMIT 1",
        $option_name
    ));
    if ($stored_value === null) {
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }
    $stored_state = maybe_unserialize((string) $stored_value);
    $stored_state = is_array($stored_state) ? $stored_state : [];
    $stored_generation = ll_tools_flashcard_payload_sanitize_hash(
        $stored_state['generation'] ?? ''
    );
    if (!hash_equals($expected_generation, $stored_generation)) {
        wp_cache_delete($option_name, 'options');
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }

    $replacement = maybe_serialize($sanitized);
    if (hash_equals((string) $stored_value, $replacement)) {
        $updated = true;
        return $sanitized;
    }
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $replacement,
        $option_name,
        (string) $stored_value
    ));
    wp_cache_delete($option_name, 'options');
    $updated = $result === 1;

    return $updated ? $sanitized : ll_tools_get_flashcard_payload_state($scope_hash);
}

/**
 * Acquire an exact-owner option lease.
 *
 * @return array{acquired:bool,replaced:bool,option_name:string,value:string}
 */
function ll_tools_acquire_flashcard_payload_lock(string $lock_key, int $ttl = 90): array {
    global $wpdb;

    $ttl = max(15, min(300, $ttl));
    $option_name = ll_tools_flashcard_payload_lock_option($lock_key);
    $now = time();
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $lock_key . '|' . microtime(true) . '|' . wp_rand());
    $value = ($now + $ttl) . '|' . $token;
    if (add_option($option_name, $value, '', false)) {
        return [
            'acquired' => true,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => $value,
        ];
    }

    $current = (string) get_option($option_name, '');
    $separator = strpos($current, '|');
    $expires_at = $separator === false ? (int) $current : (int) substr($current, 0, $separator);
    if ($expires_at > $now) {
        return [
            'acquired' => false,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => '',
        ];
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $value,
        $option_name,
        $current
    ));
    wp_cache_delete($option_name, 'options');

    return [
        'acquired' => $updated === 1,
        'replaced' => $updated === 1,
        'option_name' => $option_name,
        'value' => $updated === 1 ? $value : '',
    ];
}

function ll_tools_renew_flashcard_payload_lock(array &$lock, int $ttl = 90): bool {
    global $wpdb;

    $option_name = (string) ($lock['option_name'] ?? '');
    $current_value = (string) ($lock['value'] ?? '');
    $separator = strpos($current_value, '|');
    $token = $separator === false ? '' : substr($current_value, $separator + 1);
    if ($option_name === '' || $current_value === '' || $token === '') {
        return false;
    }

    $ttl = max(15, min(300, $ttl));
    $current_expiry = (int) substr($current_value, 0, $separator);
    $replacement = max(time() + $ttl, $current_expiry + 1) . '|' . $token;
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $replacement,
        $option_name,
        $current_value
    ));
    wp_cache_delete($option_name, 'options');
    if ($updated !== 1) {
        return false;
    }

    $lock['value'] = $replacement;
    return true;
}

function ll_tools_release_flashcard_payload_lock(array $lock): void {
    global $wpdb;

    $option_name = (string) ($lock['option_name'] ?? '');
    $value = (string) ($lock['value'] ?? '');
    if ($option_name === '' || $value === '') {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s AND option_value = %s",
        $option_name,
        $value
    ));
    wp_cache_delete($option_name, 'options');
}

function ll_tools_flashcard_payload_lock_is_active(string $lock_key): bool {
    $value = (string) get_option(
        ll_tools_flashcard_payload_lock_option($lock_key),
        ''
    );
    $separator = strpos($value, '|');
    $expires_at = $separator === false
        ? (int) $value
        : (int) substr($value, 0, $separator);

    return $expires_at > time();
}

function ll_tools_flashcard_payload_renew_locks(array &$locks): bool {
    foreach ($locks as &$lock) {
        if (!ll_tools_renew_flashcard_payload_lock($lock)) {
            unset($lock);
            return false;
        }
    }
    unset($lock);

    return true;
}

function ll_tools_flashcard_payload_begin_generation(
    array $scope,
    string $signature,
    array &$locks
): array {
    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $option_name = ll_tools_flashcard_payload_state_option($scope_hash);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        if (!ll_tools_flashcard_payload_renew_locks($locks)) {
            return ll_tools_get_flashcard_payload_state($scope_hash);
        }
        $current = ll_tools_get_flashcard_payload_state($scope_hash);
        $replacement = [
            'status' => 'running',
            'scope_hash' => $scope_hash,
            'scope' => $scope,
            'signature' => $signature,
            'generation' => ll_tools_flashcard_payload_generate_generation($scope_hash),
            'published_generation' => '',
            'phase' => 'primary',
            'primary_cursor' => 0,
            'prompt_cursor' => 0,
            'processed' => 0,
            'row_count' => 0,
            'started_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
            'last_access_at' => '',
            'completed_at' => '',
            'last_error' => '',
            'retry_count' => 0,
            'next_retry_at' => 0,
            'terminal' => 0,
        ];

        if (get_option($option_name, null) === null) {
            if (add_option($option_name, ll_tools_flashcard_payload_sanitize_state($replacement), '', false)) {
                return ll_tools_get_flashcard_payload_state($scope_hash);
            }
            wp_cache_delete($option_name, 'options');
            continue;
        }

        $did_update = false;
        $result = ll_tools_update_flashcard_payload_state(
            $scope_hash,
            $replacement,
            (string) ($current['generation'] ?? ''),
            $did_update
        );
        if ($did_update) {
            return $result;
        }
    }

    return ll_tools_get_flashcard_payload_state($scope_hash);
}

function ll_tools_flashcard_payload_update_owned_state(
    string $scope_hash,
    array $state,
    string $generation,
    array &$locks,
    ?bool &$updated = null
): array {
    $updated = false;
    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }

    return ll_tools_update_flashcard_payload_state(
        $scope_hash,
        $state,
        $generation,
        $updated
    );
}

function ll_tools_flashcard_payload_schedule_rebuild(string $scope_hash, int $delay = 1): void {
    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    if ($scope_hash === '') {
        return;
    }
    $args = [$scope_hash];
    if (!wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, $args)) {
        wp_schedule_single_event(
            time() + max(1, min(300, $delay)),
            LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
            $args
        );
    }
}

function ll_tools_flashcard_payload_primary_batch_size(): int {
    return max(20, min(200, (int) apply_filters(
        'll_tools_flashcard_payload_primary_batch_size',
        100
    )));
}

function ll_tools_flashcard_payload_prompt_batch_size(): int {
    return max(1, min(25, (int) apply_filters(
        'll_tools_flashcard_payload_prompt_batch_size',
        5
    )));
}

function ll_tools_flashcard_payload_image_primary_batch_size(): int {
    return max(5, min(50, (int) apply_filters(
        'll_tools_flashcard_payload_image_primary_batch_size',
        20
    )));
}

function ll_tools_flashcard_payload_prompt_support_limit(): int {
    return max(25, min(1000, (int) apply_filters(
        'll_tools_flashcard_payload_prompt_support_limit',
        300
    )));
}

function ll_tools_flashcard_payload_row_byte_limit(): int {
    return max(64 * 1024, min(1024 * 1024, (int) apply_filters(
        'll_tools_flashcard_payload_row_byte_limit',
        512 * 1024
    )));
}

function ll_tools_flashcard_payload_sql_chunk_byte_limit(): int {
    $configured = max(256 * 1024, min(2 * 1024 * 1024, (int) apply_filters(
        'll_tools_flashcard_payload_sql_chunk_byte_limit',
        1024 * 1024
    )));

    return max(ll_tools_flashcard_payload_row_byte_limit(), $configured);
}

/**
 * Split metadata/records under both row and serialized-payload ceilings.
 *
 * @return array<int,array<int,array<string,mixed>>>
 */
function ll_tools_flashcard_payload_byte_chunks(
    array $records,
    int $row_limit = 25,
    ?int $byte_limit = null
): array {
    $row_limit = max(1, min(100, $row_limit));
    $byte_limit = $byte_limit === null
        ? ll_tools_flashcard_payload_sql_chunk_byte_limit()
        : max(1, $byte_limit);
    $chunks = [];
    $chunk = [];
    $chunk_bytes = 0;
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $record_bytes = max(0, (int) ($record['payload_bytes'] ?? 0));
        if (
            !empty($chunk)
            && (
                count($chunk) >= $row_limit
                || $chunk_bytes + $record_bytes > $byte_limit
            )
        ) {
            $chunks[] = $chunk;
            $chunk = [];
            $chunk_bytes = 0;
        }
        $chunk[] = $record;
        $chunk_bytes += $record_bytes;
    }
    if (!empty($chunk)) {
        $chunks[] = $chunk;
    }

    return $chunks;
}

function ll_tools_flashcard_payload_unique_ints(...$lists): array {
    $lookup = [];
    foreach ($lists as $list) {
        foreach ((array) $list as $value) {
            $value = (int) $value;
            if ($value > 0) {
                $lookup[$value] = true;
            }
        }
    }
    $values = array_map('intval', array_keys($lookup));
    sort($values, SORT_NUMERIC);

    return $values;
}

function ll_tools_flashcard_payload_unique_strings(...$lists): array {
    $lookup = [];
    foreach ($lists as $list) {
        foreach ((array) $list as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lookup[$value] = true;
            }
        }
    }
    $values = array_keys($lookup);
    sort($values, SORT_STRING);

    return array_values($values);
}

function ll_tools_flashcard_payload_merge_rows(array $existing, array $incoming): array {
    if (empty($existing)) {
        return $incoming;
    }
    $merged = array_replace($existing, $incoming);
    foreach ([
        'specific_wrong_answer_ids',
        'specific_wrong_answer_owner_ids',
        'prompt_card_support_owner_ids',
        'wordset_ids',
        'option_blocked_ids',
        'option_similar_image_allowed_ids',
    ] as $field) {
        $merged[$field] = ll_tools_flashcard_payload_unique_ints(
            $existing[$field] ?? [],
            $incoming[$field] ?? []
        );
    }
    foreach ([
        'specific_wrong_answer_texts',
        'prompt_card_support_roles',
        'all_categories',
        'part_of_speech',
        'option_groups',
        'practice_recording_types',
    ] as $field) {
        $merged[$field] = ll_tools_flashcard_payload_unique_strings(
            $existing[$field] ?? [],
            $incoming[$field] ?? []
        );
    }
    $recording_maps = [];
    foreach ([$existing['option_blocked_ids_by_recording_type'] ?? [], $incoming['option_blocked_ids_by_recording_type'] ?? []] as $map) {
        foreach ((array) $map as $recording_type => $ids) {
            $key = sanitize_key((string) $recording_type);
            if ($key === '') {
                continue;
            }
            $recording_maps[$key] = ll_tools_flashcard_payload_unique_ints(
                $recording_maps[$key] ?? [],
                $ids
            );
        }
    }
    $merged['option_blocked_ids_by_recording_type'] = $recording_maps;
    foreach ([
        'is_specific_wrong_answer_only',
        'is_prompt_card_support_only',
        'is_prompt_card_answer_option_support',
        'is_prompt_card_prompt_image_support',
    ] as $flag) {
        $merged[$flag] = !empty($existing[$flag]) || !empty($incoming[$flag]);
    }

    return $merged;
}

/**
 * Strip internal speaker user IDs before any quiz row reaches durable storage.
 */
function ll_tools_flashcard_payload_redact_speaker_ids(array $rows): array {
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[$index]['preferred_speaker_user_id'] = 0;
        foreach ((array) ($row['audio_files'] ?? []) as $audio_index => $audio_file) {
            if (is_array($audio_file)) {
                $rows[$index]['audio_files'][$audio_index]['speaker_user_id'] = 0;
            }
        }
    }

    return $rows;
}

/**
 * Upsert one already-bounded hydration batch.
 *
 * @return true|WP_Error
 */
function ll_tools_flashcard_payload_upsert_rows(
    array $scope,
    string $generation,
    array $rows,
    string $phase,
    array &$locks
) {
    global $wpdb;

    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $generation = ll_tools_flashcard_payload_sanitize_hash($generation);
    if ($generation === '' || empty($rows)) {
        return true;
    }
    $scope_term = get_term((int) ($scope['category_id'] ?? 0), 'word-category');
    $scope_category_name = $scope_term instanceof WP_Term
        ? (string) $scope_term->name
        : '';
    $scope_wordset_ids = ll_tools_flashcard_payload_unique_ints(
        (array) ($scope['wordset_ids'] ?? [])
    );
    $viewer_user_id = max(0, (int) ($scope['viewer_user_id'] ?? 0));
    foreach ($rows as $row_index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw_wordset_ids = ll_tools_flashcard_payload_unique_ints(
            (array) ($row['wordset_ids'] ?? [])
        );
        $visibility_complete = true;
        $visible_wordset_ids = ll_tools_flashcard_payload_filter_accessible_wordsets(
            $raw_wordset_ids,
            $viewer_user_id,
            $visibility_complete
        );
        if (!$visibility_complete) {
            return new WP_Error('flashcard_payload_scope_visibility');
        }
        if (!empty($raw_wordset_ids) && empty($visible_wordset_ids)) {
            return new WP_Error('flashcard_payload_scope_forbidden');
        }
        $rows[$row_index]['wordset_ids'] = !empty($scope_wordset_ids)
            ? $scope_wordset_ids
            : $visible_wordset_ids;
        $rows[$row_index]['all_categories'] = $scope_category_name !== ''
            ? [$scope_category_name]
            : [];
    }
    $rows = ll_tools_flashcard_payload_redact_speaker_ids($rows);
    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return new WP_Error('flashcard_payload_lease_lost');
    }
    $stored_state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (!hash_equals($generation, (string) ($stored_state['generation'] ?? ''))) {
        return new WP_Error('flashcard_payload_generation_replaced');
    }
    $row_byte_limit = ll_tools_flashcard_payload_row_byte_limit();

    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row_id = max(0, (int) ($row['id'] ?? 0));
        if ($row_id <= 0) {
            continue;
        }
        $row_kind = !empty($row['is_prompt_card']) ? 'prompt' : 'word';
        $key = $row_kind . ':' . $row_id;
        if (isset($normalized[$key])) {
            $normalized[$key]['payload'] = ll_tools_flashcard_payload_merge_rows(
                $normalized[$key]['payload'],
                $row
            );
            continue;
        }
        $normalized[$key] = [
            'row_kind' => $row_kind,
            'row_id' => $row_id,
            'sort_group' => $row_kind === 'prompt' ? 1 : ($phase === 'primary' ? 0 : 2),
            'payload' => $row,
        ];
    }
    if (empty($normalized)) {
        return true;
    }

    $word_ids = [];
    $prompt_ids = [];
    foreach ($normalized as $entry) {
        if ($entry['row_kind'] === 'prompt') {
            $prompt_ids[] = (int) $entry['row_id'];
        } else {
            $word_ids[] = (int) $entry['row_id'];
        }
    }
    $existing = [];
    foreach (['word' => $word_ids, 'prompt' => $prompt_ids] as $row_kind => $ids) {
        $ids = ll_tools_flashcard_payload_unique_ints($ids);
        if (empty($ids)) {
            continue;
        }
        $metadata = [];
        foreach (array_chunk($ids, 100) as $id_chunk) {
            $placeholders = implode(',', array_fill(0, count($id_chunk), '%d'));
            $sql = "SELECT row_kind, row_id, sort_group, payload_bytes
                    FROM " . ll_tools_flashcard_payload_table_name() . "
                    WHERE scope_hash = %s
                      AND generation = %s
                      AND row_kind = %s
                      AND row_id IN ({$placeholders})";
            $params = array_merge(
                [$scope_hash, $generation, $row_kind],
                $id_chunk
            );
            $wpdb->last_error = '';
            $metadata_rows = $wpdb->get_results(
                $wpdb->prepare($sql, $params),
                ARRAY_A
            );
            if ($wpdb->last_error !== '') {
                return new WP_Error('flashcard_payload_existing_rows_failed');
            }
            foreach ((array) $metadata_rows as $metadata_row) {
                $stored_bytes = max(0, (int) ($metadata_row['payload_bytes'] ?? 0));
                if ($stored_bytes <= 0 || $stored_bytes > $row_byte_limit) {
                    return new WP_Error('flashcard_payload_corrupt_row');
                }
                $metadata[] = $metadata_row;
            }
        }
        foreach (ll_tools_flashcard_payload_byte_chunks($metadata, 25) as $metadata_chunk) {
            if (!ll_tools_flashcard_payload_renew_locks($locks)) {
                return new WP_Error('flashcard_payload_lease_lost');
            }
            $chunk_ids = array_values(array_map(
                static function (array $row): int {
                    return max(0, (int) ($row['row_id'] ?? 0));
                },
                $metadata_chunk
            ));
            $placeholders = implode(',', array_fill(0, count($chunk_ids), '%d'));
            $sql = "SELECT row_kind, row_id, sort_group, payload, payload_bytes
                    FROM " . ll_tools_flashcard_payload_table_name() . "
                    WHERE scope_hash = %s
                      AND generation = %s
                      AND row_kind = %s
                      AND row_id IN ({$placeholders})";
            $params = array_merge(
                [$scope_hash, $generation, $row_kind],
                $chunk_ids
            );
            $wpdb->last_error = '';
            $existing_rows = $wpdb->get_results(
                $wpdb->prepare($sql, $params),
                ARRAY_A
            );
            if (
                $wpdb->last_error !== ''
                || count((array) $existing_rows) !== count($metadata_chunk)
            ) {
                return new WP_Error('flashcard_payload_existing_rows_failed');
            }
            $metadata_lookup = [];
            foreach ($metadata_chunk as $metadata_row) {
                $metadata_lookup[(int) ($metadata_row['row_id'] ?? 0)] = $metadata_row;
            }
            foreach ((array) $existing_rows as $existing_row) {
                $row_id = (int) ($existing_row['row_id'] ?? 0);
                $metadata_row = $metadata_lookup[$row_id] ?? [];
                if (
                    (int) ($existing_row['payload_bytes'] ?? 0)
                    !== (int) ($metadata_row['payload_bytes'] ?? -1)
                    || strlen((string) ($existing_row['payload'] ?? ''))
                        !== (int) ($metadata_row['payload_bytes'] ?? -1)
                ) {
                    return new WP_Error('flashcard_payload_existing_rows_failed');
                }
                $decoded = json_decode((string) ($existing_row['payload'] ?? ''), true);
                if (!is_array($decoded)) {
                    return new WP_Error('flashcard_payload_corrupt_row');
                }
                $key = (string) ($existing_row['row_kind'] ?? '') . ':' . $row_id;
                $existing[$key] = [
                    'sort_group' => max(0, (int) ($existing_row['sort_group'] ?? 2)),
                    'payload' => $decoded,
                ];
            }
            if (!ll_tools_flashcard_payload_renew_locks($locks)) {
                return new WP_Error('flashcard_payload_lease_lost');
            }
        }
    }

    $records = [];
    foreach ($normalized as $key => $entry) {
        if (isset($existing[$key])) {
            $entry['sort_group'] = min(
                (int) $entry['sort_group'],
                (int) $existing[$key]['sort_group']
            );
            $entry['payload'] = ll_tools_flashcard_payload_merge_rows(
                $existing[$key]['payload'],
                $entry['payload']
            );
        }
        $payload_json = wp_json_encode($entry['payload']);
        $payload_bytes = is_string($payload_json) ? strlen($payload_json) : 0;
        if ($payload_bytes <= 0 || $payload_bytes > $row_byte_limit) {
            return new WP_Error('flashcard_payload_row_too_large');
        }
        $entry['payload_json'] = $payload_json;
        $entry['payload_bytes'] = $payload_bytes;
        $records[] = $entry;
    }

    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return new WP_Error('flashcard_payload_lease_lost');
    }
    $stored_state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (!hash_equals($generation, (string) ($stored_state['generation'] ?? ''))) {
        return new WP_Error('flashcard_payload_generation_replaced');
    }

    foreach (ll_tools_flashcard_payload_byte_chunks($records, 25) as $chunk) {
        $values = [];
        $params = [];
        $updated_at = current_time('mysql', true);
        foreach ($chunk as $entry) {
            $values[] = '(%s,%s,%d,%d,%s,%d,%d,%s,%d,%s)';
            array_push(
                $params,
                $scope_hash,
                $generation,
                (int) $scope['category_id'],
                (int) $scope['viewer_user_id'],
                (string) $entry['row_kind'],
                (int) $entry['row_id'],
                (int) $entry['sort_group'],
                (string) $entry['payload_json'],
                (int) $entry['payload_bytes'],
                $updated_at
            );
        }
        $sql = "INSERT INTO " . ll_tools_flashcard_payload_table_name() . "
            (scope_hash, generation, category_id, viewer_user_id, row_kind, row_id,
             sort_group, payload, payload_bytes, updated_at)
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                sort_group = LEAST(sort_group, VALUES(sort_group)),
                payload = VALUES(payload),
                payload_bytes = VALUES(payload_bytes),
                updated_at = VALUES(updated_at)";
        $wpdb->last_error = '';
        $result = $wpdb->query($wpdb->prepare($sql, $params));
        if ($result === false || $wpdb->last_error !== '') {
            return new WP_Error('flashcard_payload_insert_failed');
        }
        if (!ll_tools_flashcard_payload_renew_locks($locks)) {
            $latest_state = ll_tools_get_flashcard_payload_state($scope_hash);
            if (
                ($latest_state['status'] ?? '') === 'retiring'
                || !hash_equals(
                    $generation,
                    (string) ($latest_state['generation'] ?? '')
                )
                || !hash_equals(
                    $scope_hash,
                    (string) ($latest_state['scope_hash'] ?? '')
                )
            ) {
                ll_tools_flashcard_payload_delete_exact_row_chunk(
                    $scope_hash,
                    $generation,
                    $chunk
                );
            }
            ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 5);
            ll_tools_flashcard_payload_schedule_cleanup();
            return new WP_Error('flashcard_payload_lease_lost');
        }
    }

    return true;
}

/**
 * Best-effort rollback for one bounded chunk written after lease takeover.
 *
 * @param array<int,array<string,mixed>> $chunk
 */
function ll_tools_flashcard_payload_delete_exact_row_chunk(
    string $scope_hash,
    string $generation,
    array $chunk
): void {
    global $wpdb;

    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    $generation = ll_tools_flashcard_payload_sanitize_hash($generation);
    if ($scope_hash === '' || $generation === '' || empty($chunk)) {
        return;
    }

    $clauses = [];
    $params = [$scope_hash, $generation];
    foreach (array_slice($chunk, 0, 25) as $entry) {
        $row_kind = (string) ($entry['row_kind'] ?? '');
        $row_id = max(0, (int) ($entry['row_id'] ?? 0));
        if (!in_array($row_kind, ['word', 'prompt'], true) || $row_id <= 0) {
            continue;
        }
        $clauses[] = '(row_kind = %s AND row_id = %d)';
        $params[] = $row_kind;
        $params[] = $row_id;
    }
    if (empty($clauses)) {
        return;
    }

    $sql = "DELETE FROM " . ll_tools_flashcard_payload_table_name() . "
            WHERE scope_hash = %s
              AND generation = %s
              AND (" . implode(' OR ', $clauses) . ")
            LIMIT " . count($clauses);
    $wpdb->query($wpdb->prepare($sql, $params));
}

function ll_tools_flashcard_payload_record_failure(
    string $scope_hash,
    array $state,
    string $reason,
    string $generation,
    array &$locks,
    bool $terminal = false
): array {
    $retry_count = max(0, (int) ($state['retry_count'] ?? 0)) + 1;
    $state['status'] = 'failed';
    $state['last_error'] = sanitize_key($reason);
    $state['retry_count'] = $retry_count;
    $state['terminal'] = $terminal ? 1 : 0;
    $state['next_retry_at'] = $terminal
        ? 0
        : time() + min(300, (int) pow(2, min(8, $retry_count)));
    $did_update = false;
    $state = ll_tools_flashcard_payload_update_owned_state(
        $scope_hash,
        $state,
        $generation,
        $locks,
        $did_update
    );
    if ($did_update && !$terminal) {
        ll_tools_flashcard_payload_schedule_rebuild(
            $scope_hash,
            max(1, (int) $state['next_retry_at'] - time())
        );
    }

    return $state;
}

function ll_tools_flashcard_payload_cleanup_old_generations(
    string $scope_hash,
    string $published_generation,
    array &$locks
): bool {
    global $wpdb;

    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    $published_generation = ll_tools_flashcard_payload_sanitize_hash($published_generation);
    if ($scope_hash === '' || $published_generation === '') {
        return false;
    }
    $limit = max(50, min(2000, (int) apply_filters(
        'll_tools_flashcard_payload_cleanup_row_limit',
        500
    )));
    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return true;
    }
    $state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (
        ($state['status'] ?? '') !== 'completed'
        || !hash_equals(
            $published_generation,
            (string) ($state['published_generation'] ?? '')
        )
    ) {
        return false;
    }

    /*
     * Capture one immutable generation while this owner still holds both
     * leases. Never use a broad "generation <>" DELETE: a stalled cleanup
     * could otherwise wake after takeover and delete a future generation.
     */
    $wpdb->last_error = '';
    $target_generation = $wpdb->get_var($wpdb->prepare(
        "SELECT generation
         FROM " . ll_tools_flashcard_payload_table_name() . "
         WHERE scope_hash = %s
           AND generation <> %s
         ORDER BY generation ASC
         LIMIT 1",
        $scope_hash,
        $published_generation
    ));
    if ($wpdb->last_error !== '') {
        return true;
    }
    $target_generation = ll_tools_flashcard_payload_sanitize_hash(
        (string) $target_generation
    );
    if ($target_generation === '') {
        return false;
    }
    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return true;
    }
    $state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (
        ($state['status'] ?? '') !== 'completed'
        || !hash_equals(
            $published_generation,
            (string) ($state['published_generation'] ?? '')
        )
    ) {
        return false;
    }

    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM " . ll_tools_flashcard_payload_table_name() . "
         WHERE scope_hash = %s
           AND generation = %s
         LIMIT %d",
        $scope_hash,
        $target_generation,
        $limit
    ));
    if ($deleted === false || $wpdb->last_error !== '') {
        return true;
    }
    if ($deleted === $limit) {
        return true;
    }
    if (!ll_tools_flashcard_payload_renew_locks($locks)) {
        return true;
    }

    $wpdb->last_error = '';
    $remaining = $wpdb->get_var($wpdb->prepare(
        "SELECT 1
         FROM " . ll_tools_flashcard_payload_table_name() . "
         WHERE scope_hash = %s
           AND generation <> %s
         LIMIT 1",
        $scope_hash,
        $published_generation
    ));

    return $wpdb->last_error !== '' || $remaining !== null;
}

function ll_tools_flashcard_payload_state_timestamp(array $state): int {
    $timestamps = [];
    foreach (['last_access_at', 'updated_at', 'completed_at', 'started_at'] as $field) {
        $value = trim((string) ($state[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp !== false && $timestamp > 0) {
            $timestamps[] = $timestamp;
        }
    }

    return empty($timestamps) ? 0 : max($timestamps);
}

function ll_tools_flashcard_payload_state_is_stale_for_cleanup(
    array $state,
    ?int $now = null
): bool {
    if (($state['status'] ?? '') === 'retiring') {
        return true;
    }
    $now = $now ?? time();
    $last_activity = ll_tools_flashcard_payload_state_timestamp($state);
    if ($last_activity <= 0) {
        return false;
    }
    $completed = ($state['status'] ?? '') === 'completed';
    $ttl = $completed
        ? max(DAY_IN_SECONDS, (int) apply_filters(
            'll_tools_flashcard_payload_completed_scope_ttl',
            30 * DAY_IN_SECONDS
        ))
        : max(HOUR_IN_SECONDS, (int) apply_filters(
            'll_tools_flashcard_payload_incomplete_scope_ttl',
            7 * DAY_IN_SECONDS
        ));

    return $last_activity < ($now - $ttl);
}

function ll_tools_flashcard_payload_touch_access(
    string $scope_hash,
    array $state,
    string $signature = ''
): void {
    $last_access = ll_tools_flashcard_payload_state_timestamp([
        'last_access_at' => (string) ($state['last_access_at'] ?? ''),
    ]);
    $touch_interval = max(HOUR_IN_SECONDS, (int) apply_filters(
        'll_tools_flashcard_payload_access_touch_interval',
        6 * HOUR_IN_SECONDS
    ));
    if ($last_access > time() - $touch_interval) {
        return;
    }
    $generation = (string) ($state['generation'] ?? '');
    if ($generation === '') {
        return;
    }
    $scope_lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 30);
    if (empty($scope_lock['acquired'])) {
        return;
    }

    try {
        ll_tools_flashcard_payload_touch_access_locked(
            $scope_hash,
            $state,
            $signature,
            $scope_lock
        );
    } finally {
        ll_tools_release_flashcard_payload_lock($scope_lock);
    }
}

function ll_tools_flashcard_payload_touch_access_locked(
    string $scope_hash,
    array $state,
    string $signature,
    array &$scope_lock
): bool {
    $generation = (string) ($state['generation'] ?? '');
    if ($generation === '') {
        return false;
    }
    if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
        return false;
    }

    $fresh_state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (
        ($fresh_state['status'] ?? '') !== 'completed'
        || !hash_equals($generation, (string) ($fresh_state['generation'] ?? ''))
        || !hash_equals(
            $generation,
            (string) ($fresh_state['published_generation'] ?? '')
        )
        || (
            $signature !== ''
            && !hash_equals($signature, (string) ($fresh_state['signature'] ?? ''))
        )
    ) {
        return false;
    }

    $last_access = ll_tools_flashcard_payload_state_timestamp([
        'last_access_at' => (string) ($fresh_state['last_access_at'] ?? ''),
    ]);
    $touch_interval = max(HOUR_IN_SECONDS, (int) apply_filters(
        'll_tools_flashcard_payload_access_touch_interval',
        6 * HOUR_IN_SECONDS
    ));
    if ($last_access > time() - $touch_interval) {
        return true;
    }

    $fresh_state['last_access_at'] = current_time('mysql', true);
    $updated = false;
    ll_tools_update_flashcard_payload_state(
        $scope_hash,
        $fresh_state,
        $generation,
        $updated
    );

    return $updated;
}

function ll_tools_flashcard_payload_schedule_cleanup(): void {
    if (!wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK)) {
        wp_schedule_event(
            time() + HOUR_IN_SECONDS,
            'hourly',
            LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK
        );
    }
}
add_action('init', 'll_tools_flashcard_payload_schedule_cleanup', 14);

/**
 * Delete one bounded generation whose coordinator option no longer exists.
 */
function ll_tools_flashcard_payload_cleanup_orphan_generation(
    array &$global_lock,
    int $row_limit
): void {
    global $wpdb;

    if (!ll_tools_renew_flashcard_payload_lock($global_lock)) {
        return;
    }
    $scan_limit = max(25, min(250, (int) apply_filters(
        'll_tools_flashcard_payload_orphan_scan_limit',
        100
    )));
    $cutoff = gmdate('Y-m-d H:i:s', time() - (15 * MINUTE_IN_SECONDS));
    $raw_cursor = get_option(LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION, []);
    $cursor = is_array($raw_cursor) ? $raw_cursor : [];
    $cursor_scope = ll_tools_flashcard_payload_sanitize_hash(
        (string) ($cursor['scope_hash'] ?? '')
    );
    $cursor_generation = ll_tools_flashcard_payload_sanitize_hash(
        (string) ($cursor['generation'] ?? '')
    );
    $cursor_kind = (string) ($cursor['row_kind'] ?? '');
    $cursor_id = max(0, (int) ($cursor['row_id'] ?? 0));
    $has_cursor = $cursor_scope !== ''
        && $cursor_generation !== ''
        && in_array($cursor_kind, ['word', 'prompt'], true)
        && $cursor_id > 0;

    $wpdb->last_error = '';
    if ($has_cursor) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT scope_hash, generation, row_kind, row_id, updated_at
             FROM " . ll_tools_flashcard_payload_table_name() . "
             WHERE scope_hash > %s
                OR (scope_hash = %s AND generation > %s)
                OR (scope_hash = %s AND generation = %s AND row_kind > %s)
                OR (
                    scope_hash = %s
                    AND generation = %s
                    AND row_kind = %s
                    AND row_id > %d
                )
             ORDER BY scope_hash ASC, generation ASC, row_kind ASC, row_id ASC
             LIMIT %d",
            $cursor_scope,
            $cursor_scope,
            $cursor_generation,
            $cursor_scope,
            $cursor_generation,
            $cursor_kind,
            $cursor_scope,
            $cursor_generation,
            $cursor_kind,
            $cursor_id,
            $scan_limit
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT scope_hash, generation, row_kind, row_id, updated_at
             FROM " . ll_tools_flashcard_payload_table_name() . "
             ORDER BY scope_hash ASC, generation ASC, row_kind ASC, row_id ASC
             LIMIT %d",
            $scan_limit
        ), ARRAY_A);
    }
    if ($wpdb->last_error !== '') {
        return;
    }
    if (empty($rows)) {
        if (ll_tools_renew_flashcard_payload_lock($global_lock)) {
            update_option(
                LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION,
                [],
                false
            );
        }
        return;
    }

    $last_row = end($rows);
    $next_cursor = count($rows) < $scan_limit
        ? []
        : [
            'scope_hash' => (string) ($last_row['scope_hash'] ?? ''),
            'generation' => (string) ($last_row['generation'] ?? ''),
            'row_kind' => (string) ($last_row['row_kind'] ?? ''),
            'row_id' => max(0, (int) ($last_row['row_id'] ?? 0)),
        ];
    $scope_hashes = array_values(array_unique(array_filter(array_map(
        static function (array $row): string {
            return ll_tools_flashcard_payload_sanitize_hash(
                (string) ($row['scope_hash'] ?? '')
            );
        },
        $rows
    ))));
    $existing_state_options = [];
    if (!empty($scope_hashes)) {
        $option_names = array_map(
            'll_tools_flashcard_payload_state_option',
            $scope_hashes
        );
        $placeholders = implode(',', array_fill(0, count($option_names), '%s'));
        $wpdb->last_error = '';
        $existing_state_options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name
             FROM {$wpdb->options}
             WHERE option_name IN ({$placeholders})",
            $option_names
        ));
        if ($wpdb->last_error !== '') {
            return;
        }
    }
    $existing_lookup = array_fill_keys(
        array_map('strval', (array) $existing_state_options),
        true
    );
    $candidate = null;
    foreach ($rows as $row) {
        $scope_hash = ll_tools_flashcard_payload_sanitize_hash(
            (string) ($row['scope_hash'] ?? '')
        );
        $generation = ll_tools_flashcard_payload_sanitize_hash(
            (string) ($row['generation'] ?? '')
        );
        $updated_at = strtotime((string) ($row['updated_at'] ?? '') . ' UTC');
        if (
            $scope_hash === ''
            || $generation === ''
            || $updated_at === false
            || $updated_at >= strtotime($cutoff . ' UTC')
            || isset($existing_lookup[ll_tools_flashcard_payload_state_option($scope_hash)])
        ) {
            continue;
        }
        $candidate = [
            'scope_hash' => $scope_hash,
            'generation' => $generation,
        ];
        break;
    }

    if (!ll_tools_renew_flashcard_payload_lock($global_lock)) {
        return;
    }
    update_option(
        LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION,
        $next_cursor,
        false
    );
    if (is_array($candidate)) {
        $scope_hash = (string) $candidate['scope_hash'];
        $generation = (string) $candidate['generation'];
        $wpdb->last_error = '';
        $state_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id
             FROM {$wpdb->options}
             WHERE option_name = %s
             LIMIT 1",
            ll_tools_flashcard_payload_state_option($scope_hash)
        ));
        if ($wpdb->last_error === '' && $state_exists === null) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM " . ll_tools_flashcard_payload_table_name() . "
                 WHERE scope_hash = %s
                   AND generation = %s
                 LIMIT %d",
                $scope_hash,
                $generation,
                max(1, $row_limit)
            ));
        }
    }
}

/**
 * Remove a small page of abandoned scopes and their durable rows.
 */
function ll_tools_flashcard_payload_cleanup_stale_scopes(): void {
    global $wpdb;

    if (!ll_tools_flashcard_payload_table_exists()) {
        return;
    }
    $global_lock = ll_tools_acquire_flashcard_payload_lock('global', 90);
    if (empty($global_lock['acquired'])) {
        return;
    }

    try {
        $batch_size = max(5, min(50, (int) apply_filters(
            'll_tools_flashcard_payload_cleanup_scope_limit',
            20
        )));
        $row_limit = max(50, min(2000, (int) apply_filters(
            'll_tools_flashcard_payload_cleanup_row_limit',
            500
        )));
        ll_tools_flashcard_payload_cleanup_orphan_generation(
            $global_lock,
            $row_limit
        );
        $cursor = max(0, (int) get_option(
            LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION,
            0
        ));
        $prefix = $wpdb->esc_like('ll_tools_fc_payload_state_') . '%';
        $option_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND option_id > %d
             ORDER BY option_id ASC
             LIMIT %d",
            $prefix,
            $cursor,
            $batch_size
        ), ARRAY_A);
        if (empty($option_rows)) {
            if (!ll_tools_renew_flashcard_payload_lock($global_lock)) {
                return;
            }
            update_option(
                LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION,
                0,
                false
            );
            return;
        }

        $last_option_id = $cursor;
        foreach ((array) $option_rows as $option_row) {
            $last_option_id = max(
                $last_option_id,
                (int) ($option_row['option_id'] ?? 0)
            );
            $option_name = (string) ($option_row['option_name'] ?? '');
            $scope_hash = ll_tools_flashcard_payload_sanitize_hash(
                substr($option_name, strlen('ll_tools_fc_payload_state_'))
            );
            $raw_state = maybe_unserialize((string) ($option_row['option_value'] ?? ''));
            $state = ll_tools_flashcard_payload_sanitize_state(
                is_array($raw_state) ? $raw_state : []
            );
            if (
                $scope_hash === ''
                || !hash_equals($scope_hash, (string) ($state['scope_hash'] ?? ''))
                || !ll_tools_flashcard_payload_state_is_stale_for_cleanup($state)
            ) {
                continue;
            }

            $scope_lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 90);
            if (empty($scope_lock['acquired'])) {
                continue;
            }
            try {
                $cleanup_locks = [&$global_lock, &$scope_lock];
                if (!ll_tools_flashcard_payload_renew_locks($cleanup_locks)) {
                    continue;
                }
                $fresh_raw = get_option($option_name, null);
                $fresh_state = ll_tools_flashcard_payload_sanitize_state(
                    is_array($fresh_raw) ? $fresh_raw : []
                );
                if (
                    !is_array($fresh_raw)
                    || !hash_equals($scope_hash, (string) ($fresh_state['scope_hash'] ?? ''))
                    || !ll_tools_flashcard_payload_state_is_stale_for_cleanup($fresh_state)
                ) {
                    continue;
                }

                if (($fresh_state['status'] ?? '') !== 'retiring') {
                    $generation = (string) ($fresh_state['generation'] ?? '');
                    $fresh_state['status'] = 'retiring';
                    $fresh_state['published_generation'] = '';
                    $fresh_state['phase'] = 'cleanup';
                    $fresh_state['terminal'] = 0;
                    $fresh_state['last_error'] = '';
                    $fresh_state['next_retry_at'] = 0;
                    $did_retire = false;
                    $fresh_state = ll_tools_update_flashcard_payload_state(
                        $scope_hash,
                        $fresh_state,
                        $generation,
                        $did_retire
                    );
                    if (!$did_retire || ($fresh_state['status'] ?? '') !== 'retiring') {
                        continue;
                    }
                    $fresh_raw = $fresh_state;
                }

                /*
                 * Retiring is unpublished before any row deletion. Clear queued
                 * workers at the same boundary; an already-running callback
                 * separately refuses to revive retiring state.
                 */
                wp_clear_scheduled_hook(
                    LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
                    [$scope_hash]
                );
                if (!ll_tools_flashcard_payload_renew_locks($cleanup_locks)) {
                    continue;
                }
                $fresh_raw = get_option($option_name, null);
                $fresh_state = ll_tools_flashcard_payload_sanitize_state(
                    is_array($fresh_raw) ? $fresh_raw : []
                );
                if (
                    !is_array($fresh_raw)
                    || ($fresh_state['status'] ?? '') !== 'retiring'
                    || !hash_equals(
                        $scope_hash,
                        (string) ($fresh_state['scope_hash'] ?? '')
                    )
                ) {
                    continue;
                }

                /*
                 * Capture one exact generation while the leases are current.
                 * If this process stalls past lease expiry, its later DELETE
                 * cannot match a generation created by a takeover.
                 */
                $wpdb->last_error = '';
                $target_generation = $wpdb->get_var($wpdb->prepare(
                    "SELECT generation
                     FROM " . ll_tools_flashcard_payload_table_name() . "
                     WHERE scope_hash = %s
                     ORDER BY generation ASC
                     LIMIT 1",
                    $scope_hash
                ));
                if ($wpdb->last_error !== '') {
                    continue;
                }
                if ($target_generation !== null) {
                    $target_generation = (string) $target_generation;
                    if (!ll_tools_flashcard_payload_renew_locks($cleanup_locks)) {
                        continue;
                    }
                    $latest_raw = get_option($option_name, null);
                    $latest_state = ll_tools_flashcard_payload_sanitize_state(
                        is_array($latest_raw) ? $latest_raw : []
                    );
                    if (
                        !is_array($latest_raw)
                        || ($latest_state['status'] ?? '') !== 'retiring'
                        || !hash_equals(
                            $scope_hash,
                            (string) ($latest_state['scope_hash'] ?? '')
                        )
                    ) {
                        continue;
                    }
                    $fresh_raw = $latest_raw;

                    $wpdb->last_error = '';
                    $deleted = $wpdb->query($wpdb->prepare(
                        "DELETE FROM " . ll_tools_flashcard_payload_table_name() . "
                         WHERE scope_hash = %s
                           AND generation = %s
                         LIMIT %d",
                        $scope_hash,
                        $target_generation,
                        $row_limit
                    ));
                    if ($deleted === false || $wpdb->last_error !== '') {
                        continue;
                    }
                    if ($deleted === $row_limit) {
                        continue;
                    }
                }

                if (!ll_tools_flashcard_payload_renew_locks($cleanup_locks)) {
                    continue;
                }
                $latest_raw = get_option($option_name, null);
                $latest_state = ll_tools_flashcard_payload_sanitize_state(
                    is_array($latest_raw) ? $latest_raw : []
                );
                if (
                    !is_array($latest_raw)
                    || ($latest_state['status'] ?? '') !== 'retiring'
                    || !hash_equals(
                        $scope_hash,
                        (string) ($latest_state['scope_hash'] ?? '')
                    )
                ) {
                    continue;
                }
                $fresh_raw = $latest_raw;
                $wpdb->last_error = '';
                $remaining = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1
                     FROM " . ll_tools_flashcard_payload_table_name() . "
                     WHERE scope_hash = %s
                     LIMIT 1",
                    $scope_hash
                ));
                if ($wpdb->last_error !== '' || $remaining !== null) {
                    continue;
                }

                $serialized = maybe_serialize($fresh_raw);
                $state_deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name = %s AND option_value = %s",
                    $option_name,
                    $serialized
                ));
                wp_cache_delete($option_name, 'options');
                if ($state_deleted === 1) {
                    wp_clear_scheduled_hook(
                        LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
                        [$scope_hash]
                    );
                }
            } finally {
                ll_tools_release_flashcard_payload_lock($scope_lock);
            }
        }
        if (!ll_tools_renew_flashcard_payload_lock($global_lock)) {
            return;
        }
        update_option(
            LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION,
            count($option_rows) < $batch_size ? 0 : $last_option_id,
            false
        );
    } finally {
        ll_tools_release_flashcard_payload_lock($global_lock);
    }
}
add_action(
    LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK,
    'll_tools_flashcard_payload_cleanup_stale_scopes'
);

function ll_tools_flashcard_payload_scope_is_accessible(
    array $scope,
    ?bool &$complete = null
): bool {
    $complete = true;
    $term = get_term((int) ($scope['category_id'] ?? 0), 'word-category');
    if (!($term instanceof WP_Term)) {
        return false;
    }
    $viewer_user_id = max(0, (int) ($scope['viewer_user_id'] ?? 0));
    if (function_exists('ll_tools_user_can_view_category')) {
        $category_complete = true;
        $category_accessible = ll_tools_user_can_view_category(
            $term,
            $viewer_user_id,
            $category_complete
        );
        if (!$category_complete) {
            $complete = false;
            return false;
        }
        if (!$category_accessible) {
            return false;
        }
    }
    $wordset_ids = (array) ($scope['wordset_ids'] ?? []);
    if (!empty($wordset_ids) && function_exists('ll_tools_filter_viewable_wordset_ids')) {
        $wordset_complete = true;
        $viewable = ll_tools_filter_viewable_wordset_ids(
            $wordset_ids,
            $viewer_user_id,
            $wordset_complete
        );
        if (!$wordset_complete) {
            $complete = false;
            return false;
        }
        $viewable = array_values(array_unique(array_map('intval', (array) $viewable)));
        sort($viewable, SORT_NUMERIC);
        $expected = array_values(array_unique(array_map('intval', $wordset_ids)));
        sort($expected, SORT_NUMERIC);
        if ($viewable !== $expected) {
            return false;
        }
    }

    return true;
}

/**
 * Process one fixed-size build step.
 *
 * @return array<string,mixed>
 */
function ll_tools_flashcard_payload_process_rebuild_batch(
    string $scope_hash,
    bool $allow_retiring_revival = true
): array {
    global $wpdb;

    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    if ($scope_hash === '' || !ll_tools_flashcard_payload_table_exists()) {
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }

    $global_lock = ll_tools_acquire_flashcard_payload_lock('global', 90);
    if (empty($global_lock['acquired'])) {
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }
    $scope_lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 90);
    if (empty($scope_lock['acquired'])) {
        ll_tools_release_flashcard_payload_lock($global_lock);
        return ll_tools_get_flashcard_payload_state($scope_hash);
    }
    $locks = [&$global_lock, &$scope_lock];

    $previous_user_id = (int) get_current_user_id();
    $locale_switched = false;
    try {
        $state = ll_tools_get_flashcard_payload_state($scope_hash);
        if (
            !$allow_retiring_revival
            && ($state['status'] ?? '') === 'retiring'
        ) {
            return $state;
        }
        $scope = isset($state['scope']) && is_array($state['scope'])
            ? ll_tools_flashcard_payload_normalize_scope($state['scope'])
            : [];
        if (
            (int) ($scope['category_id'] ?? 0) <= 0
            || !hash_equals($scope_hash, ll_tools_flashcard_payload_scope_hash($scope))
        ) {
            return $state;
        }

        $viewer_user_id = max(0, (int) ($scope['viewer_user_id'] ?? 0));
        if ($viewer_user_id > 0 && !get_user_by('id', $viewer_user_id)) {
            $generation = (string) ($state['generation'] ?? '');
            if ($generation !== '') {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    'viewer_missing',
                    $generation,
                    $locks,
                    true
                );
            }
            return $state;
        }
        wp_set_current_user($viewer_user_id);
        $visibility_complete = true;
        if (!ll_tools_flashcard_payload_scope_is_accessible($scope, $visibility_complete)) {
            $generation = (string) ($state['generation'] ?? '');
            if ($generation !== '') {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    $visibility_complete ? 'scope_forbidden' : 'scope_visibility',
                    $generation,
                    $locks,
                    $visibility_complete
                );
            }
            return $state;
        }

        $signature = ll_tools_flashcard_payload_dependency_signature($scope);
        $state_ready = empty($scope_lock['replaced'])
            && $state['status'] === 'completed'
            && (string) ($state['signature'] ?? '') === $signature
            && (string) ($state['generation'] ?? '') !== ''
            && hash_equals(
                (string) ($state['generation'] ?? ''),
                (string) ($state['published_generation'] ?? '')
            );
        if ($state_ready) {
            if (ll_tools_flashcard_payload_cleanup_old_generations(
                $scope_hash,
                (string) $state['published_generation'],
                $locks
            )) {
                ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 5);
            }
            return $state;
        }

        $needs_generation = !empty($scope_lock['replaced'])
            || ($state['status'] ?? '') === 'retiring'
            || (string) ($state['generation'] ?? '') === ''
            || (string) ($state['signature'] ?? '') !== $signature
            || !hash_equals($scope_hash, (string) ($state['scope_hash'] ?? ''));
        if ($needs_generation) {
            $state = ll_tools_flashcard_payload_begin_generation($scope, $signature, $locks);
        }
        $generation = (string) ($state['generation'] ?? '');
        if ($generation === '') {
            return $state;
        }
        if (
            $state['status'] === 'failed'
            && !empty($state['terminal'])
            && (string) ($state['signature'] ?? '') === $signature
        ) {
            return $state;
        }
        if (
            $state['status'] === 'failed'
            && (int) ($state['next_retry_at'] ?? 0) > time()
        ) {
            ll_tools_flashcard_payload_schedule_rebuild(
                $scope_hash,
                max(1, (int) $state['next_retry_at'] - time())
            );
            return $state;
        }
        $scope_locale = (string) ($scope['locale'] ?? '');
        if (
            $scope_locale !== ''
            && function_exists('switch_to_locale')
            && function_exists('restore_previous_locale')
            && $scope_locale !== get_locale()
        ) {
            $locale_switched = switch_to_locale($scope_locale);
            if (!$locale_switched) {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    'locale_switch',
                    $generation,
                    $locks
                );
            }
        }
        $state['status'] = 'running';
        $state['last_error'] = '';
        $state['next_retry_at'] = 0;

        $term = get_term(
            (int) ($scope['query_category_id'] ?? $scope['category_id']),
            'word-category'
        );
        if (!($term instanceof WP_Term)) {
            return ll_tools_flashcard_payload_record_failure(
                $scope_hash,
                $state,
                'category_missing',
                $generation,
                $locks,
                true
            );
        }
        $config = (array) $scope['config'];
        $config['__skip_quiz_config_merge'] = true;
        $config['__skip_image_similarity_pairs'] = true;
        $wordset_ids = (array) $scope['wordset_ids'];
        $phase = (string) ($state['phase'] ?? 'primary');

        if ($phase === 'primary') {
            $batch_size = ll_tools_flashcard_payload_primary_batch_size();
            if (ll_tools_quiz_option_type_has_image((string) $config['option_type'])) {
                $batch_size = min(
                    $batch_size,
                    ll_tools_flashcard_payload_image_primary_batch_size()
                );
            }
            $source_complete = true;
            $candidate_ids = function_exists('ll_tools_get_quiz_eligibility_raw_word_id_batch')
                ? ll_tools_get_quiz_eligibility_raw_word_id_batch(
                    (int) $term->term_id,
                    $wordset_ids,
                    (int) $state['primary_cursor'],
                    $batch_size,
                    $source_complete
                )
                : [];
            if (!$source_complete) {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    'primary_query',
                    $generation,
                    $locks
                );
            }
            $candidate_ids = ll_tools_flashcard_payload_unique_ints($candidate_ids);
            if (!empty($candidate_ids)) {
                $config['__candidate_word_ids'] = $candidate_ids;
                $config['__prompt_card_reference_rows'] = [];
                $rows_complete = true;
                $rows = ll_get_words_by_category(
                    $term,
                    (string) $config['option_type'],
                    $wordset_ids,
                    $config,
                    $rows_complete
                );
                if (!$rows_complete) {
                    return ll_tools_flashcard_payload_record_failure(
                        $scope_hash,
                        $state,
                        'primary_hydration',
                        $generation,
                        $locks
                    );
                }
                $stored = ll_tools_flashcard_payload_upsert_rows(
                    $scope,
                    $generation,
                    (array) $rows,
                    'primary',
                    $locks
                );
                if (is_wp_error($stored)) {
                    return ll_tools_flashcard_payload_record_failure(
                        $scope_hash,
                        $state,
                        (string) $stored->get_error_code(),
                        $generation,
                        $locks,
                        $stored->get_error_code() === 'flashcard_payload_row_too_large'
                    );
                }
                $state['primary_cursor'] = max($candidate_ids);
                $state['processed'] += count($candidate_ids);
            }
            if (count($candidate_ids) < $batch_size) {
                $state['phase'] = 'prompt';
                $state['prompt_cursor'] = 0;
            }
            $state['retry_count'] = 0;
            $state['terminal'] = 0;
            $did_update = false;
            $state = ll_tools_flashcard_payload_update_owned_state(
                $scope_hash,
                $state,
                $generation,
                $locks,
                $did_update
            );
            if ($did_update) {
                ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 1);
            }
            return $state;
        }

        if ($phase === 'prompt') {
            $batch_size = ll_tools_flashcard_payload_prompt_batch_size();
            $source_complete = true;
            $prompt_ids = function_exists('ll_tools_get_quiz_eligibility_prompt_card_id_batch')
                ? ll_tools_get_quiz_eligibility_prompt_card_id_batch(
                    (int) $term->term_id,
                    $wordset_ids,
                    (int) $state['prompt_cursor'],
                    $batch_size,
                    $source_complete
                )
                : [];
            if (!$source_complete) {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    'prompt_query',
                    $generation,
                    $locks
                );
            }
            $prompt_ids = ll_tools_flashcard_payload_unique_ints($prompt_ids);
            if (!empty($prompt_ids)) {
                $prompt_data_complete = true;
                $reference_rows = function_exists('ll_tools_get_prompt_card_reference_data_for_ids')
                    ? ll_tools_get_prompt_card_reference_data_for_ids(
                        $prompt_ids,
                        true,
                        $prompt_data_complete
                    )
                    : [];
                if (!$prompt_data_complete) {
                    return ll_tools_flashcard_payload_record_failure(
                        $scope_hash,
                        $state,
                        'prompt_data',
                        $generation,
                        $locks
                    );
                }
                $reference_by_id = [];
                foreach ((array) $reference_rows as $card) {
                    if (is_array($card) && (int) ($card['id'] ?? 0) > 0) {
                        $reference_by_id[(int) $card['id']] = $card;
                    }
                }
                $selected_rows = [];
                $support_ids = [];
                $processed_prompt_ids = [];
                $support_limit = ll_tools_flashcard_payload_prompt_support_limit();
                foreach ($prompt_ids as $prompt_id) {
                    $card = $reference_by_id[$prompt_id] ?? null;
                    $candidate_support = [];
                    if (is_array($card)) {
                        $candidate_support = ll_tools_flashcard_payload_unique_ints(
                            [(int) ($card['correct_answer_word_id'] ?? 0)],
                            [(int) ($card['prompt_image_word_id'] ?? 0)],
                            (array) ($card['wrong_answer_word_ids'] ?? [])
                        );
                    }
                    $next_support = ll_tools_flashcard_payload_unique_ints(
                        $support_ids,
                        $candidate_support
                    );
                    if (count($next_support) > $support_limit) {
                        if (empty($processed_prompt_ids)) {
                            return ll_tools_flashcard_payload_record_failure(
                                $scope_hash,
                                $state,
                                'prompt_support_limit',
                                $generation,
                                $locks,
                                true
                            );
                        }
                        break;
                    }
                    $processed_prompt_ids[] = $prompt_id;
                    $support_ids = $next_support;
                    if (is_array($card)) {
                        $selected_rows[] = $card;
                    }
                }
                if (!empty($selected_rows) && !empty($support_ids)) {
                    $support_access_complete = true;
                    if (!ll_tools_flashcard_payload_support_words_are_accessible(
                        $support_ids,
                        $viewer_user_id,
                        $support_access_complete
                    )) {
                        return ll_tools_flashcard_payload_record_failure(
                            $scope_hash,
                            $state,
                            $support_access_complete
                                ? 'prompt_support_forbidden'
                                : 'prompt_support_visibility',
                            $generation,
                            $locks,
                            $support_access_complete
                        );
                    }
                    $config['__candidate_word_ids'] = $support_ids;
                    $config['__prompt_card_reference_rows'] = $selected_rows;
                    $rows_complete = true;
                    $rows = ll_get_words_by_category(
                        $term,
                        (string) $config['option_type'],
                        $wordset_ids,
                        $config,
                        $rows_complete
                    );
                    if (!$rows_complete) {
                        return ll_tools_flashcard_payload_record_failure(
                            $scope_hash,
                            $state,
                            'prompt_hydration',
                            $generation,
                            $locks
                        );
                    }
                    $stored = ll_tools_flashcard_payload_upsert_rows(
                        $scope,
                        $generation,
                        (array) $rows,
                        'prompt',
                        $locks
                    );
                    if (is_wp_error($stored)) {
                        return ll_tools_flashcard_payload_record_failure(
                            $scope_hash,
                            $state,
                            (string) $stored->get_error_code(),
                            $generation,
                            $locks,
                            $stored->get_error_code() === 'flashcard_payload_row_too_large'
                        );
                    }
                }
                if (empty($processed_prompt_ids)) {
                    $processed_prompt_ids = $prompt_ids;
                }
                $state['prompt_cursor'] = max($processed_prompt_ids);
                $state['processed'] += count($processed_prompt_ids);
                $prompt_page_complete = count($processed_prompt_ids) === count($prompt_ids)
                    && count($prompt_ids) < $batch_size;
            } else {
                $prompt_page_complete = true;
            }
            $state['retry_count'] = 0;
            $state['terminal'] = 0;

            if (empty($prompt_page_complete)) {
                $did_update = false;
                $state = ll_tools_flashcard_payload_update_owned_state(
                    $scope_hash,
                    $state,
                    $generation,
                    $locks,
                    $did_update
                );
                if ($did_update) {
                    ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 1);
                }
                return $state;
            }

            if (ll_tools_flashcard_payload_dependency_signature($scope) !== $signature) {
                $next_signature = ll_tools_flashcard_payload_dependency_signature($scope);
                $state = ll_tools_flashcard_payload_begin_generation($scope, $next_signature, $locks);
                ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 1);
                return $state;
            }
            $wpdb->last_error = '';
            $row_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM " . ll_tools_flashcard_payload_table_name() . "
                 WHERE scope_hash = %s AND generation = %s",
                $scope_hash,
                $generation
            ));
            if ($wpdb->last_error !== '') {
                return ll_tools_flashcard_payload_record_failure(
                    $scope_hash,
                    $state,
                    'publish_count',
                    $generation,
                    $locks
                );
            }
            $state['status'] = 'completed';
            $state['published_generation'] = $generation;
            $state['row_count'] = max(0, $row_count);
            $state['completed_at'] = current_time('mysql', true);
            $state['last_error'] = '';
            $state['retry_count'] = 0;
            $state['next_retry_at'] = 0;
            $state['terminal'] = 0;
            $state['phase'] = 'cleanup';
            $did_publish = false;
            $state = ll_tools_flashcard_payload_update_owned_state(
                $scope_hash,
                $state,
                $generation,
                $locks,
                $did_publish
            );
            if (
                $did_publish
                && ll_tools_flashcard_payload_cleanup_old_generations(
                    $scope_hash,
                    $generation,
                    $locks
                )
            ) {
                ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 5);
            }
            return $state;
        }

        return $state;
    } finally {
        if ($locale_switched) {
            restore_previous_locale();
        }
        wp_set_current_user($previous_user_id);
        ll_tools_release_flashcard_payload_lock($scope_lock);
        ll_tools_release_flashcard_payload_lock($global_lock);
    }
}

function ll_tools_flashcard_payload_run_scheduled_rebuild($scope_hash): void {
    $scope_hash = ll_tools_flashcard_payload_sanitize_hash($scope_hash);
    if ($scope_hash === '') {
        return;
    }
    $raw_state = get_option(
        ll_tools_flashcard_payload_state_option($scope_hash),
        null
    );
    $state = ll_tools_flashcard_payload_sanitize_state(
        is_array($raw_state) ? $raw_state : []
    );
    if (
        !is_array($raw_state)
        || !hash_equals($scope_hash, (string) ($state['scope_hash'] ?? ''))
        || ($state['status'] ?? '') === 'retiring'
    ) {
        wp_clear_scheduled_hook(
            LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
            [$scope_hash]
        );
        return;
    }

    $state = ll_tools_flashcard_payload_process_rebuild_batch(
        $scope_hash,
        false
    );
    if (
        !hash_equals($scope_hash, (string) ($state['scope_hash'] ?? ''))
        || ($state['status'] ?? '') === 'retiring'
    ) {
        wp_clear_scheduled_hook(
            LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
            [$scope_hash]
        );
        return;
    }
    if (
        ($state['status'] ?? '') !== 'completed'
        && empty($state['terminal'])
    ) {
        ll_tools_flashcard_payload_schedule_rebuild(
            $scope_hash,
            max(1, (int) ($state['next_retry_at'] ?? 0) - time())
        );
    }
}
add_action(
    LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
    'll_tools_flashcard_payload_run_scheduled_rebuild',
    10,
    1
);

function ll_tools_flashcard_payload_state_is_ready(
    array $scope,
    string $signature,
    array $state,
    bool $require_unlocked = true
): bool {
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $generation = (string) ($state['generation'] ?? '');

    return ($state['status'] ?? '') === 'completed'
        && hash_equals($scope_hash, (string) ($state['scope_hash'] ?? ''))
        && hash_equals($signature, (string) ($state['signature'] ?? ''))
        && $generation !== ''
        && hash_equals($generation, (string) ($state['published_generation'] ?? ''))
        && (
            !$require_unlocked
            || !ll_tools_flashcard_payload_lock_is_active($scope_hash)
        );
}

/**
 * Advance at most one batch and report readiness.
 *
 * @return true|false|WP_Error
 */
function ll_tools_flashcard_payload_ensure_ready(array $scope) {
    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    if (!ll_tools_flashcard_payload_table_exists()) {
        return new WP_Error('flashcard_payload_schema_unavailable');
    }

    $signature = ll_tools_flashcard_payload_dependency_signature($scope);
    $state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (ll_tools_flashcard_payload_state_is_ready($scope, $signature, $state)) {
        return true;
    }
    if (
        ($state['status'] ?? '') === 'failed'
        && !empty($state['terminal'])
        && hash_equals($signature, (string) ($state['signature'] ?? ''))
    ) {
        return new WP_Error(
            'flashcard_payload_build_failed',
            __('Flashcard data could not be prepared.', 'll-tools-text-domain'),
            ['reason' => (string) ($state['last_error'] ?? '')]
        );
    }

    if ((string) ($state['scope_hash'] ?? '') === '') {
        $initial = [
            'status' => 'pending',
            'scope_hash' => $scope_hash,
            'scope' => $scope,
            'signature' => '',
            'generation' => '',
            'updated_at' => current_time('mysql', true),
        ];
        $option_name = ll_tools_flashcard_payload_state_option($scope_hash);
        add_option(
            $option_name,
            ll_tools_flashcard_payload_sanitize_state($initial),
            '',
            false
        );
        wp_cache_delete($option_name, 'options');
    }

    $state = ll_tools_flashcard_payload_process_rebuild_batch($scope_hash);
    if (ll_tools_flashcard_payload_state_is_ready($scope, $signature, $state)) {
        return true;
    }
    if (!empty($state['terminal'])) {
        return new WP_Error(
            'flashcard_payload_build_failed',
            __('Flashcard data could not be prepared.', 'll-tools-text-domain'),
            ['reason' => (string) ($state['last_error'] ?? '')]
        );
    }
    ll_tools_flashcard_payload_schedule_rebuild($scope_hash, 1);

    return false;
}

function ll_tools_flashcard_payload_cursor_secret(): string {
    if (function_exists('wp_salt')) {
        return wp_salt('nonce');
    }
    if (defined('AUTH_SALT')) {
        return (string) AUTH_SALT;
    }

    return (string) site_url('/');
}

function ll_tools_flashcard_payload_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ll_tools_flashcard_payload_base64url_decode(string $value): string {
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    return is_string($decoded) ? $decoded : '';
}

function ll_tools_flashcard_payload_encode_cursor(
    string $scope_hash,
    string $generation,
    int $sort_group,
    int $row_id
): string {
    $body = ll_tools_flashcard_payload_base64url_encode((string) wp_json_encode([
        'v' => 1,
        's' => $scope_hash,
        'g' => $generation,
        'o' => max(0, $sort_group),
        'i' => max(0, $row_id),
    ]));
    $mac = hash_hmac('sha256', $body, ll_tools_flashcard_payload_cursor_secret());

    return $body . '.' . $mac;
}

/**
 * @return array{sort_group:int,row_id:int}|WP_Error
 */
function ll_tools_flashcard_payload_decode_cursor(
    string $cursor,
    string $scope_hash,
    string $generation
) {
    $parts = explode('.', trim($cursor), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return new WP_Error('invalid_flashcard_payload_cursor');
    }
    $expected = hash_hmac(
        'sha256',
        $parts[0],
        ll_tools_flashcard_payload_cursor_secret()
    );
    if (!hash_equals($expected, strtolower((string) $parts[1]))) {
        return new WP_Error('invalid_flashcard_payload_cursor');
    }
    $decoded = json_decode(
        ll_tools_flashcard_payload_base64url_decode($parts[0]),
        true
    );
    if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== 1) {
        return new WP_Error('invalid_flashcard_payload_cursor');
    }
    if (
        !hash_equals($scope_hash, (string) ($decoded['s'] ?? ''))
        || !hash_equals($generation, (string) ($decoded['g'] ?? ''))
    ) {
        return new WP_Error('stale_flashcard_payload_cursor');
    }

    return [
        'sort_group' => max(0, (int) ($decoded['o'] ?? 0)),
        'row_id' => max(0, (int) ($decoded['i'] ?? 0)),
    ];
}

/**
 * Return every canonical identity carried by one materialized quiz row.
 *
 * Prompt-card rows use their post ID for the row identity while their answer
 * and progress identities remain attached separately. Option-pool exclusions
 * must honor all three so a target cannot return through a support alias.
 *
 * @return int[]
 */
function ll_tools_flashcard_payload_option_row_canonical_ids(array $row): array {
    return ll_tools_flashcard_payload_unique_ints([
        (int) ($row['id'] ?? 0),
        (int) ($row['answer_word_id'] ?? 0),
        (int) ($row['progress_word_id'] ?? 0),
    ]);
}

/**
 * Decide whether a durable word row can support an option for the targets that
 * were excluded from the materialized option pool.
 */
function ll_tools_flashcard_payload_option_row_is_useful(
    array $row,
    array $excluded_canonical_lookup
): bool {
    if ((int) ($row['id'] ?? 0) <= 0) {
        return false;
    }

    foreach (ll_tools_flashcard_payload_option_row_canonical_ids($row) as $canonical_id) {
        if (isset($excluded_canonical_lookup[$canonical_id])) {
            return false;
        }
    }

    // A prompt row carries a complete rendered answer and is a valid option
    // identity for another target. Its separately materialized support word,
    // when present, is deduplicated later by answer_word_id.
    if (!empty($row['is_prompt_card'])) {
        return true;
    }

    $support_roles = ll_tools_flashcard_payload_unique_strings(
        (array) ($row['prompt_card_support_roles'] ?? [])
    );
    $is_prompt_image_only = !empty($row['is_prompt_card_prompt_image_support'])
        || (
            in_array('prompt', $support_roles, true)
            && !in_array('correct', $support_roles, true)
        );
    if ($is_prompt_image_only) {
        return false;
    }

    $owner_ids = ll_tools_flashcard_payload_unique_ints(
        (array) ($row['specific_wrong_answer_owner_ids'] ?? [])
    );
    if (!empty($owner_ids)) {
        $owner_matches_target = false;
        foreach ($owner_ids as $owner_id) {
            if (isset($excluded_canonical_lookup[$owner_id])) {
                $owner_matches_target = true;
                break;
            }
        }
        if (!$owner_matches_target) {
            return false;
        }
    } elseif (!empty($row['is_specific_wrong_answer_only'])) {
        return false;
    }

    if (!empty($row['is_prompt_card_support_only'])) {
        $is_answer_support = !empty($row['is_prompt_card_answer_option_support'])
            || in_array('correct', $support_roles, true)
            || in_array('wrong', $support_roles, true);
        if (!$is_answer_support && empty($owner_ids)) {
            return false;
        }
    }

    return true;
}

/**
 * Read a fixed set of option-support rows from one completed generation.
 *
 * Primary word rows are considered first, then prompt rows, then prompt-only
 * support words. That order prevents support rows from hiding an independently
 * eligible word while still retaining prompt-card answer identities when no
 * standalone word row exists. The reader inspects at most four stored rows per
 * requested useful option and never exposes or follows a continuation cursor.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function ll_tools_flashcard_payload_read_option_rows(
    array $scope,
    array $exclude_canonical_ids = [],
    int $limit = 12
) {
    global $wpdb;

    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $useful_limit = max(1, min(12, $limit));
    $scan_limit = min(48, max($useful_limit, $useful_limit * 4));
    $exclude_canonical_ids = ll_tools_flashcard_payload_unique_ints(
        $exclude_canonical_ids
    );
    $excluded_canonical_lookup = array_fill_keys($exclude_canonical_ids, true);

    $ready = ll_tools_flashcard_payload_ensure_ready($scope);
    if (is_wp_error($ready)) {
        return $ready;
    }
    if (!$ready) {
        return new WP_Error(
            'flashcard_payload_warming',
            __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
            ['retry_after' => 1]
        );
    }

    $scope_lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 90);
    if (empty($scope_lock['acquired'])) {
        return new WP_Error(
            'flashcard_payload_warming',
            __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
            ['retry_after' => 1]
        );
    }

    try {
        $signature = ll_tools_flashcard_payload_dependency_signature($scope);
        $state = ll_tools_get_flashcard_payload_state($scope_hash);
        if (!ll_tools_flashcard_payload_state_is_ready($scope, $signature, $state, false)) {
            return new WP_Error(
                'flashcard_payload_warming',
                __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
                ['retry_after' => 1]
            );
        }
        $generation = (string) $state['published_generation'];

        if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }

        $exclude_sql = '';
        $metadata_args = [$scope_hash, $generation];
        if (!empty($exclude_canonical_ids)) {
            $exclude_sql = ' AND row_id NOT IN ('
                . implode(',', array_fill(0, count($exclude_canonical_ids), '%d'))
                . ')';
            $metadata_args = array_merge($metadata_args, $exclude_canonical_ids);
        }
        $metadata_args[] = $scan_limit;
        $metadata_sql = "SELECT row_kind, row_id, sort_group, payload_bytes,
                               OCTET_LENGTH(payload) AS actual_payload_bytes
                        FROM " . ll_tools_flashcard_payload_table_name() . "
                        WHERE scope_hash = %s
                          AND generation = %s
                          AND row_kind IN ('word', 'prompt')
                          {$exclude_sql}
                        ORDER BY sort_group ASC, row_id ASC
                        LIMIT %d";
        $wpdb->last_error = '';
        $row_metadata = $wpdb->get_results(
            $wpdb->prepare($metadata_sql, $metadata_args),
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            return new WP_Error('flashcard_payload_read_failed');
        }

        $byte_limit = max(256 * 1024, min(4 * 1024 * 1024, (int) apply_filters(
            'll_tools_flashcard_payload_page_byte_limit',
            2 * 1024 * 1024
        )));
        $row_byte_limit = ll_tools_flashcard_payload_row_byte_limit();
        $selected_metadata = [];
        $bytes = 0;
        foreach ((array) $row_metadata as $metadata) {
            $row_bytes = max(0, (int) ($metadata['payload_bytes'] ?? 0));
            $actual_row_bytes = max(0, (int) ($metadata['actual_payload_bytes'] ?? 0));
            if (
                $row_bytes <= 0
                || $row_bytes !== $actual_row_bytes
                || $row_bytes > $row_byte_limit
            ) {
                return new WP_Error('flashcard_payload_corrupt_row');
            }
            if ($bytes + $row_bytes > $byte_limit) {
                if (empty($selected_metadata)) {
                    return new WP_Error('flashcard_payload_page_row_too_large');
                }
                break;
            }
            $selected_metadata[] = $metadata;
            $bytes += $row_bytes;
        }

        $payload_rows = [];
        if (!empty($selected_metadata)) {
            if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
                return new WP_Error('stale_flashcard_payload_cursor');
            }

            $payload_args = [$scope_hash, $generation];
            if (!empty($exclude_canonical_ids)) {
                $payload_args = array_merge($payload_args, $exclude_canonical_ids);
            }
            $payload_args[] = count($selected_metadata);
            $payload_sql = "SELECT row_kind, row_id, sort_group, payload, payload_bytes
                            FROM " . ll_tools_flashcard_payload_table_name() . "
                            WHERE scope_hash = %s
                              AND generation = %s
                              AND row_kind IN ('word', 'prompt')
                              {$exclude_sql}
                            ORDER BY sort_group ASC, row_id ASC
                            LIMIT %d";
            $wpdb->last_error = '';
            $payload_records = $wpdb->get_results(
                $wpdb->prepare($payload_sql, $payload_args),
                ARRAY_A
            );
            if (
                $wpdb->last_error !== ''
                || count((array) $payload_records) !== count($selected_metadata)
            ) {
                return new WP_Error('flashcard_payload_read_failed');
            }

            $seen_option_identities = [];
            foreach ((array) $payload_records as $index => $record) {
                $metadata = $selected_metadata[$index] ?? [];
                if (
                    !in_array((string) ($record['row_kind'] ?? ''), ['word', 'prompt'], true)
                    || (string) ($record['row_kind'] ?? '') !== (string) ($metadata['row_kind'] ?? '')
                    || (int) ($record['row_id'] ?? 0) !== (int) ($metadata['row_id'] ?? 0)
                    || (int) ($record['sort_group'] ?? 0) !== (int) ($metadata['sort_group'] ?? 0)
                    || (int) ($record['payload_bytes'] ?? 0) !== (int) ($metadata['payload_bytes'] ?? 0)
                    || strlen((string) ($record['payload'] ?? ''))
                        !== (int) ($metadata['payload_bytes'] ?? 0)
                ) {
                    return new WP_Error('flashcard_payload_read_failed');
                }

                $decoded = json_decode((string) ($record['payload'] ?? ''), true);
                if (
                    !is_array($decoded)
                    || (int) ($decoded['id'] ?? 0) !== (int) ($record['row_id'] ?? 0)
                    || (!empty($decoded['is_prompt_card']) !== ((string) $record['row_kind'] === 'prompt'))
                ) {
                    return new WP_Error('flashcard_payload_corrupt_row');
                }
                if (!ll_tools_flashcard_payload_option_row_is_useful(
                    $decoded,
                    $excluded_canonical_lookup
                )) {
                    continue;
                }

                $option_identity = (int) ($decoded['answer_word_id'] ?? 0);
                if ($option_identity <= 0) {
                    $option_identity = (int) ($decoded['id'] ?? 0);
                }
                if ($option_identity <= 0 || isset($seen_option_identities[$option_identity])) {
                    continue;
                }
                $seen_option_identities[$option_identity] = true;
                $payload_rows[] = $decoded;
                if (count($payload_rows) >= $useful_limit) {
                    break;
                }
            }
        }

        if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }
        $final_state = ll_tools_get_flashcard_payload_state($scope_hash);
        if (
            !ll_tools_flashcard_payload_state_is_ready(
                $scope,
                $signature,
                $final_state,
                false
            )
            || !hash_equals(
                $generation,
                (string) ($final_state['published_generation'] ?? '')
            )
            || !hash_equals(
                $signature,
                ll_tools_flashcard_payload_dependency_signature($scope)
            )
        ) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }
        if (!ll_tools_flashcard_payload_touch_access_locked(
            $scope_hash,
            $final_state,
            $signature,
            $scope_lock
        )) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }

        return $payload_rows;
    } finally {
        ll_tools_release_flashcard_payload_lock($scope_lock);
    }
}

/**
 * Read one generation-stable, byte-bounded page.
 *
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_flashcard_payload_read_page(
    array $scope,
    string $cursor = '',
    int $limit = 200
) {
    global $wpdb;

    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $ready = ll_tools_flashcard_payload_ensure_ready($scope);
    if (is_wp_error($ready)) {
        return $ready;
    }
    if (!$ready) {
        return new WP_Error(
            'flashcard_payload_warming',
            __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
            ['retry_after' => 1]
        );
    }

    $scope_lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 90);
    if (empty($scope_lock['acquired'])) {
        return new WP_Error(
            'flashcard_payload_warming',
            __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
            ['retry_after' => 1]
        );
    }

    try {
    $signature = ll_tools_flashcard_payload_dependency_signature($scope);
    $state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (!ll_tools_flashcard_payload_state_is_ready($scope, $signature, $state, false)) {
        return new WP_Error(
            'flashcard_payload_warming',
            __('Flashcard data is still being prepared.', 'll-tools-text-domain'),
            ['retry_after' => 1]
        );
    }
    $generation = (string) $state['published_generation'];
    $after_group = 0;
    $after_id = 0;
    if (trim($cursor) !== '') {
        $decoded = ll_tools_flashcard_payload_decode_cursor(
            $cursor,
            $scope_hash,
            $generation
        );
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        $after_group = (int) $decoded['sort_group'];
        $after_id = (int) $decoded['row_id'];
    }

    $limit = max(10, min(200, $limit));
    $query_limit = $limit + 1;
    if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
        return new WP_Error('stale_flashcard_payload_cursor');
    }
    $row_metadata = $wpdb->get_results($wpdb->prepare(
        "SELECT row_kind, row_id, sort_group, payload_bytes,
                OCTET_LENGTH(payload) AS actual_payload_bytes
         FROM " . ll_tools_flashcard_payload_table_name() . "
         WHERE scope_hash = %s
           AND generation = %s
           AND (
               sort_group > %d
               OR (sort_group = %d AND row_id > %d)
           )
         ORDER BY sort_group ASC, row_id ASC
         LIMIT %d",
        $scope_hash,
        $generation,
        $after_group,
        $after_group,
        $after_id,
        $query_limit
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        return new WP_Error('flashcard_payload_read_failed');
    }

    $byte_limit = max(256 * 1024, min(4 * 1024 * 1024, (int) apply_filters(
        'll_tools_flashcard_payload_page_byte_limit',
        2 * 1024 * 1024
    )));
    $row_byte_limit = ll_tools_flashcard_payload_row_byte_limit();
    $selected_metadata = [];
    $bytes = 0;
    foreach ((array) $row_metadata as $row) {
        if (count($selected_metadata) >= $limit) {
            break;
        }
        $row_bytes = max(0, (int) ($row['payload_bytes'] ?? 0));
        $actual_row_bytes = max(0, (int) ($row['actual_payload_bytes'] ?? 0));
        if (
            $row_bytes <= 0
            || $row_bytes !== $actual_row_bytes
            || $row_bytes > $row_byte_limit
        ) {
            return new WP_Error('flashcard_payload_corrupt_row');
        }
        if ($bytes + $row_bytes > $byte_limit) {
            if (empty($selected_metadata)) {
                return new WP_Error('flashcard_payload_page_row_too_large');
            }
            break;
        }
        $selected_metadata[] = $row;
        $bytes += $row_bytes;
    }
    $has_more = count($row_metadata) > count($selected_metadata);
    if (empty($selected_metadata)) {
        $payload_rows = [];
        $last_group = $after_group;
        $last_id = $after_id;
    } else {
        $payload_rows = [];
        $last_metadata = end($selected_metadata);
        $last_group = max(0, (int) ($last_metadata['sort_group'] ?? 0));
        $last_id = max(0, (int) ($last_metadata['row_id'] ?? 0));
        $selected_count = count($selected_metadata);
        if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }
        $payload_records = $wpdb->get_results($wpdb->prepare(
            "SELECT row_kind, row_id, sort_group, payload, payload_bytes
             FROM " . ll_tools_flashcard_payload_table_name() . "
             WHERE scope_hash = %s
               AND generation = %s
               AND (
                   sort_group > %d
                   OR (sort_group = %d AND row_id > %d)
               )
             ORDER BY sort_group ASC, row_id ASC
             LIMIT %d",
            $scope_hash,
            $generation,
            $after_group,
            $after_group,
            $after_id,
            $selected_count
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || count((array) $payload_records) !== $selected_count) {
            return new WP_Error('flashcard_payload_read_failed');
        }
        foreach ((array) $payload_records as $index => $row) {
            $metadata = $selected_metadata[$index] ?? [];
            if (
                (string) ($row['row_kind'] ?? '') !== (string) ($metadata['row_kind'] ?? '')
                || (int) ($row['row_id'] ?? 0) !== (int) ($metadata['row_id'] ?? 0)
                || (int) ($row['sort_group'] ?? 0) !== (int) ($metadata['sort_group'] ?? 0)
                || (int) ($row['payload_bytes'] ?? 0) !== (int) ($metadata['payload_bytes'] ?? 0)
                || strlen((string) ($row['payload'] ?? ''))
                    !== (int) ($metadata['payload_bytes'] ?? 0)
            ) {
                return new WP_Error('flashcard_payload_read_failed');
            }
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($decoded)) {
                return new WP_Error('flashcard_payload_corrupt_row');
            }
            $payload_rows[] = $decoded;
        }
    }

    if (!ll_tools_renew_flashcard_payload_lock($scope_lock)) {
        return new WP_Error('stale_flashcard_payload_cursor');
    }
    $final_state = ll_tools_get_flashcard_payload_state($scope_hash);
    if (
        !ll_tools_flashcard_payload_state_is_ready(
            $scope,
            $signature,
            $final_state,
            false
        )
        || !hash_equals(
            $generation,
            (string) ($final_state['published_generation'] ?? '')
        )
        || !hash_equals(
            $signature,
            ll_tools_flashcard_payload_dependency_signature($scope)
        )
    ) {
        return new WP_Error('stale_flashcard_payload_cursor');
    }
    if (!ll_tools_flashcard_payload_touch_access_locked(
        $scope_hash,
        $final_state,
        $signature,
        $scope_lock
    )) {
        return new WP_Error('stale_flashcard_payload_cursor');
    }

    return [
        'schema' => 1,
        'rows' => $payload_rows,
        'next_cursor' => $has_more
            ? ll_tools_flashcard_payload_encode_cursor(
                $scope_hash,
                $generation,
                $last_group,
                $last_id
            )
            : '',
        'complete' => !$has_more,
        'generation' => $generation,
        'total_rows' => max(0, (int) ($state['row_count'] ?? 0)),
        'page_rows' => count($payload_rows),
        'page_bytes' => $bytes,
    ];
    } finally {
        ll_tools_release_flashcard_payload_lock($scope_lock);
    }
}
