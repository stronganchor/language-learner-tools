<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION', '1');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION', 'll_tools_dictionary_lookup_version');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION', 'll_tools_dictionary_lookup_rebuild_state');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK', 'll_tools_dictionary_lookup_rebuild_batch');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY', 'll_tools_dictionary_lookup_rebuild_lock');
}

/**
 * Return the dictionary lookup table name.
 */
function ll_tools_dictionary_lookup_table_name(): string {
    global $wpdb;

    return $wpdb->prefix . 'll_dictionary_lookup';
}

/**
 * Determine whether the dictionary lookup table exists.
 */
function ll_tools_dictionary_lookup_table_exists(bool $refresh = false): bool {
    static $cached = null;
    global $wpdb;

    if (!$refresh && is_bool($cached)) {
        return $cached;
    }

    $table = ll_tools_dictionary_lookup_table_name();
    $cached = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) === $table;

    return $cached;
}

/**
 * Install or upgrade the dictionary lookup table schema.
 */
function ll_tools_install_dictionary_lookup_schema(): void {
    global $wpdb;

    $table = ll_tools_dictionary_lookup_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        entry_id bigint(20) unsigned NOT NULL,
        lookup_kind varchar(20) NOT NULL,
        lookup_value varchar(191) NOT NULL,
        value_length smallint(5) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_entry_lookup (entry_id, lookup_kind, lookup_value),
        KEY idx_kind_value (lookup_kind, lookup_value),
        KEY idx_value_kind (lookup_value, lookup_kind),
        KEY idx_entry (entry_id)
    ) {$charset_collate};";

    dbDelta($sql);
    ll_tools_dictionary_lookup_table_exists(true);
    update_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION, LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION, false);
}

/**
 * Return sanitized rebuild-state data for the lookup table.
 *
 * @return array{status:string,last_id:int,processed:int,started_at:string,completed_at:string,truncate_pending:int}
 */
function ll_tools_get_dictionary_lookup_rebuild_state(): array {
    $raw = get_option(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION, []);

    return [
        'status' => in_array((string) ($raw['status'] ?? ''), ['pending', 'running', 'completed'], true)
            ? (string) $raw['status']
            : 'pending',
        'last_id' => max(0, (int) ($raw['last_id'] ?? 0)),
        'processed' => max(0, (int) ($raw['processed'] ?? 0)),
        'started_at' => trim((string) ($raw['started_at'] ?? '')),
        'completed_at' => trim((string) ($raw['completed_at'] ?? '')),
        'truncate_pending' => !empty($raw['truncate_pending']) ? 1 : 0,
    ];
}

/**
 * Persist lookup rebuild-state data.
 *
 * @param array<string,mixed> $state
 */
function ll_tools_update_dictionary_lookup_rebuild_state(array $state): array {
    $sanitized = [
        'status' => in_array((string) ($state['status'] ?? ''), ['pending', 'running', 'completed'], true)
            ? (string) $state['status']
            : 'pending',
        'last_id' => max(0, (int) ($state['last_id'] ?? 0)),
        'processed' => max(0, (int) ($state['processed'] ?? 0)),
        'started_at' => trim((string) ($state['started_at'] ?? '')),
        'completed_at' => trim((string) ($state['completed_at'] ?? '')),
        'truncate_pending' => !empty($state['truncate_pending']) ? 1 : 0,
    ];

    update_option(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION, $sanitized, false);

    return $sanitized;
}

/**
 * Mark the lookup table for a full rebuild and queue the next batch.
 */
function ll_tools_schedule_dictionary_lookup_rebuild(bool $reset = false): void {
    if (!ll_tools_dictionary_lookup_table_exists()) {
        ll_tools_install_dictionary_lookup_schema();
    }

    $state = ll_tools_get_dictionary_lookup_rebuild_state();
    if ($reset) {
        $state = [
            'status' => 'pending',
            'last_id' => 0,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
            'truncate_pending' => 1,
        ];
    } elseif ($state['status'] === 'completed') {
        $state['status'] = 'pending';
        $state['completed_at'] = '';
    }

    ll_tools_update_dictionary_lookup_rebuild_state($state);

    if (!wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK)) {
        wp_schedule_single_event(time() + 5, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
    }
}

/**
 * Determine whether the lookup table is ready for fast searches.
 */
function ll_tools_dictionary_lookup_is_ready(): bool {
    if (!ll_tools_dictionary_lookup_table_exists()) {
        return false;
    }

    $state = ll_tools_get_dictionary_lookup_rebuild_state();
    return $state['status'] === 'completed' && $state['truncate_pending'] === 0;
}

/**
 * Install/upgrade the lookup table and ensure a backfill is queued.
 */
function ll_tools_maybe_upgrade_dictionary_lookup_schema(): void {
    $installed = (string) get_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION, '');
    if ($installed === LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION && ll_tools_dictionary_lookup_table_exists()) {
        return;
    }

    ll_tools_install_dictionary_lookup_schema();
    ll_tools_schedule_dictionary_lookup_rebuild(true);
}
add_action('init', 'll_tools_maybe_upgrade_dictionary_lookup_schema', 13);

/**
 * Normalize one lookup-table value and cap it to the indexed column width.
 */
function ll_tools_dictionary_prepare_lookup_value(string $value): string {
    $value = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
        ? ll_tools_dictionary_entry_normalize_lookup_value($value)
        : trim(strtolower($value));

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, 191, 'UTF-8');
    } else {
        $value = substr($value, 0, 191);
    }

    return trim((string) $value);
}

/**
 * Build lookup rows for one dictionary entry.
 *
 * @return array<int,array{entry_id:int,lookup_kind:string,lookup_value:string,value_length:int}>
 */
function ll_tools_dictionary_build_lookup_rows_for_entry(int $entry_id): array {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return [];
    }

    $senses = function_exists('ll_tools_get_dictionary_entry_senses')
        ? ll_tools_get_dictionary_entry_senses($entry_id)
        : [];
    $headwords = function_exists('ll_tools_dictionary_get_entry_headword_candidates')
        ? ll_tools_dictionary_get_entry_headword_candidates($entry_id, $senses)
        : [trim((string) get_the_title($entry_id))];
    $translations = function_exists('ll_tools_dictionary_get_entry_translation_candidates')
        ? ll_tools_dictionary_get_entry_translation_candidates($senses)
        : [];

    $rows = [];
    $seen = [];
    $append = static function (string $kind, string $candidate) use ($entry_id, &$rows, &$seen): void {
        $candidate = trim((string) $candidate);
        $lookup_value = ll_tools_dictionary_prepare_lookup_value($candidate);
        if ($candidate === '' || $lookup_value === '') {
            return;
        }

        $lookup_key = $kind . ':' . $lookup_value;
        if (isset($seen[$lookup_key])) {
            return;
        }

        $seen[$lookup_key] = true;
        $rows[] = [
            'entry_id' => $entry_id,
            'lookup_kind' => $kind,
            'lookup_value' => $lookup_value,
            'value_length' => function_exists('mb_strlen')
                ? (int) mb_strlen($candidate, 'UTF-8')
                : strlen($candidate),
        ];
    };

    foreach ($headwords as $candidate) {
        $append('headword', (string) $candidate);
    }
    foreach ($translations as $candidate) {
        $append('translation', (string) $candidate);
    }

    return $rows;
}

/**
 * Delete all lookup rows for one entry.
 */
function ll_tools_dictionary_delete_lookup_rows_for_entry(int $entry_id, bool $bump_cache = true): void {
    global $wpdb;

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !ll_tools_dictionary_lookup_table_exists()) {
        return;
    }

    $wpdb->delete(ll_tools_dictionary_lookup_table_name(), ['entry_id' => $entry_id], ['%d']);

    if ($bump_cache && function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
        ll_tools_bump_dictionary_browser_cache_version();
    }
}

/**
 * Upsert lookup rows for one dictionary entry.
 */
function ll_tools_dictionary_sync_lookup_rows_for_entry(int $entry_id, bool $bump_cache = true): void {
    global $wpdb;

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !ll_tools_dictionary_lookup_table_exists()) {
        return;
    }

    $wpdb->delete(ll_tools_dictionary_lookup_table_name(), ['entry_id' => $entry_id], ['%d']);

    if (wp_is_post_revision($entry_id) || get_post_type($entry_id) !== 'll_dictionary_entry') {
        if ($bump_cache && function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }
        return;
    }

    $rows = ll_tools_dictionary_build_lookup_rows_for_entry($entry_id);
    if (!empty($rows)) {
        $table = ll_tools_dictionary_lookup_table_name();
        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(%d, %s, %s, %d)';
            $params[] = (int) $row['entry_id'];
            $params[] = (string) $row['lookup_kind'];
            $params[] = (string) $row['lookup_value'];
            $params[] = max(0, (int) $row['value_length']);
        }

        $sql = "INSERT INTO {$table} (entry_id, lookup_kind, lookup_value, value_length) VALUES "
            . implode(', ', $placeholders);
        $wpdb->query($wpdb->prepare($sql, $params));
    }

    if ($bump_cache && function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
        ll_tools_bump_dictionary_browser_cache_version();
    }
}

/**
 * Keep lookup rows in sync when dictionary entries change.
 */
function ll_tools_dictionary_sync_lookup_rows_on_save($post_id, $post, $update): void {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }

    ll_tools_dictionary_sync_lookup_rows_for_entry((int) $post_id);
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_sync_lookup_rows_on_save', 60, 3);

/**
 * Remove lookup rows when dictionary entries are deleted.
 */
function ll_tools_dictionary_delete_lookup_rows_before_delete($post_id): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0 || get_post_type($post_id) !== 'll_dictionary_entry') {
        return;
    }

    ll_tools_dictionary_delete_lookup_rows_for_entry($post_id);
}
add_action('before_delete_post', 'll_tools_dictionary_delete_lookup_rows_before_delete');

/**
 * Process one lookup-table rebuild batch.
 */
function ll_tools_dictionary_lookup_process_rebuild_batch(): void {
    global $wpdb;

    if (get_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY)) {
        return;
    }

    set_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY, 1, 60);

    try {
        if (!ll_tools_dictionary_lookup_table_exists()) {
            ll_tools_install_dictionary_lookup_schema();
        }

        $table = ll_tools_dictionary_lookup_table_name();
        $state = ll_tools_get_dictionary_lookup_rebuild_state();
        if ($state['status'] === 'completed' && $state['truncate_pending'] === 0) {
            return;
        }

        if ($state['truncate_pending'] === 1) {
            $wpdb->query("TRUNCATE TABLE {$table}");
            $state['last_id'] = 0;
            $state['processed'] = 0;
            $state['truncate_pending'] = 0;
            $state['status'] = 'running';
            $state['started_at'] = current_time('mysql');
            $state['completed_at'] = '';
        } elseif ($state['started_at'] === '') {
            $state['started_at'] = current_time('mysql');
            $state['status'] = 'running';
        }

        $batch_size = (int) apply_filters('ll_tools_dictionary_lookup_rebuild_batch_size', 250);
        $batch_size = max(25, min(1000, $batch_size));

        $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'll_dictionary_entry'
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            (int) $state['last_id'],
            $batch_size
        )))));

        if (empty($ids)) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
            ll_tools_update_dictionary_lookup_rebuild_state($state);
            if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
                ll_tools_bump_dictionary_browser_cache_version();
            }
            return;
        }

        foreach ($ids as $entry_id) {
            ll_tools_dictionary_sync_lookup_rows_for_entry((int) $entry_id, false);
        }

        $state['last_id'] = (int) end($ids);
        $state['processed'] += count($ids);

        if (count($ids) < $batch_size) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
        } else {
            $state['status'] = 'running';
            if (!wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK)) {
                wp_schedule_single_event(time() + 1, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
            }
        }

        ll_tools_update_dictionary_lookup_rebuild_state($state);
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }
    } finally {
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
    }
}
add_action(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK, 'll_tools_dictionary_lookup_process_rebuild_batch');

/**
 * Nudge the rebuild forward during admin requests when a backfill is pending.
 */
function ll_tools_dictionary_lookup_maybe_process_admin_batch(): void {
    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }

    $state = ll_tools_get_dictionary_lookup_rebuild_state();
    if (in_array($state['status'], ['pending', 'running'], true)) {
        ll_tools_dictionary_lookup_process_rebuild_batch();
    }
}
add_action('admin_init', 'll_tools_dictionary_lookup_maybe_process_admin_batch', 20);

/**
 * Query entry IDs from the indexed lookup table.
 *
 * @param string[] $statuses Allowed post statuses.
 * @return int[]
 */
function ll_tools_dictionary_query_entry_ids_from_lookup_table(string $search, array $statuses, string $search_scope = 'all'): array {
    static $request_cache = [];
    global $wpdb;

    $lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
        ? ll_tools_dictionary_entry_normalize_lookup_value($search)
        : trim(strtolower($search));
    $search_scope = function_exists('ll_tools_dictionary_normalize_search_scope')
        ? ll_tools_dictionary_normalize_search_scope($search_scope)
        : trim(strtolower($search_scope));
    if ($lookup === '' || empty($statuses) || !ll_tools_dictionary_lookup_is_ready()) {
        return [];
    }

    $cache_args = [
        'search' => $lookup,
        'search_scope' => $search_scope,
        'statuses' => array_values($statuses),
    ];
    $cached = function_exists('ll_tools_dictionary_browser_get_cached_payload')
        ? ll_tools_dictionary_browser_get_cached_payload('lookup_entry_ids', $cache_args, $request_cache)
        : null;
    if (is_array($cached)) {
        return array_values(array_filter(array_map('intval', $cached)));
    }

    $table = ll_tools_dictionary_lookup_table_name();
    if (!ll_tools_dictionary_lookup_table_exists()) {
        return [];
    }

    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $prefix_lookup = $wpdb->esc_like($lookup) . '%';
    $contains_lookup = '%' . $wpdb->esc_like($lookup) . '%';
    $lookup_length = function_exists('mb_strlen') ? mb_strlen($lookup, 'UTF-8') : strlen($lookup);
    $use_contains = ($lookup_length >= 3);
    $kind_where = '';
    if ($search_scope === 'headword') {
        $kind_where = " AND l.lookup_kind = 'headword'";
    } elseif ($search_scope !== '' && $search_scope !== 'all') {
        $kind_where = " AND l.lookup_kind = 'translation'";
    }

    $case_sql = "
        CASE
            WHEN l.lookup_kind = 'headword' AND l.lookup_value = %s THEN 0
            WHEN l.lookup_kind = 'translation' AND l.lookup_value = %s THEN 1
            WHEN l.lookup_kind = 'headword' AND l.lookup_value LIKE %s THEN 2
            WHEN l.lookup_kind = 'translation' AND l.lookup_value LIKE %s THEN 3
    ";
    $case_params = [
        $lookup,
        $lookup,
        $prefix_lookup,
        $prefix_lookup,
    ];

    $where_sql = '(l.lookup_value = %s OR l.lookup_value LIKE %s';
    $where_params = [
        $lookup,
        $prefix_lookup,
    ];

    if ($use_contains) {
        $case_sql .= "
            WHEN l.lookup_kind = 'headword' AND l.lookup_value LIKE %s THEN 4
            WHEN l.lookup_kind = 'translation' AND l.lookup_value LIKE %s THEN 5
        ";
        $case_params[] = $contains_lookup;
        $case_params[] = $contains_lookup;
        $where_sql .= ' OR l.lookup_value LIKE %s';
        $where_params[] = $contains_lookup;
    }

    $case_sql .= '
            ELSE 9
        END
    ';
    $where_sql .= ')';

    $sql = "
        SELECT l.entry_id
        FROM {$table} l
        INNER JOIN {$wpdb->posts} p
                ON p.ID = l.entry_id
        WHERE p.post_type = 'll_dictionary_entry'
          AND p.post_status IN ({$status_placeholders})
          {$kind_where}
          AND {$where_sql}
        GROUP BY l.entry_id, p.post_title
        ORDER BY
            MIN({$case_sql}) ASC,
            MIN(l.value_length) ASC,
            p.post_title ASC,
            l.entry_id ASC
    ";

    $params = array_merge($statuses, $where_params, $case_params);
    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));

    if (function_exists('ll_tools_dictionary_browser_store_cached_payload')) {
        return ll_tools_dictionary_browser_store_cached_payload(
            'lookup_entry_ids',
            $cache_args,
            $ids,
            10 * MINUTE_IN_SECONDS,
            $request_cache
        );
    }

    return $ids;
}
