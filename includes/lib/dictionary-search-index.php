<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION', '5');
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
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_EXISTS_OPTION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_EXISTS_OPTION', 'll_tools_dictionary_lookup_table_exists');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION', 'll_tools_dictionary_lookup_verified_version');
}
if (!defined('LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT')) {
    define('LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT', 'll_tools_dictionary_lookup_schema_retry');
}

/**
 * Return the dictionary lookup table name.
 */
function ll_tools_dictionary_lookup_table_name(): string {
    global $wpdb;

    return $wpdb->prefix . 'll_dictionary_lookup';
}

/**
 * Reset request-local availability/schema memoization.
 *
 * Production requests naturally start with empty memoization. Keeping the
 * reset explicit also lets maintenance code and tests model a later request
 * after external table changes without performing schema DDL inline.
 */
function ll_tools_dictionary_lookup_reset_request_schema_cache(): void {
    unset(
        $GLOBALS['ll_tools_dictionary_lookup_table_exists_request_cache'],
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache']
    );
}

/**
 * Determine whether the dictionary lookup table exists.
 */
function ll_tools_dictionary_lookup_table_exists(bool $refresh = false): bool {
    global $wpdb;

    $cached = $GLOBALS['ll_tools_dictionary_lookup_table_exists_request_cache'] ?? null;
    if (!$refresh && is_bool($cached)) {
        return $cached;
    }

    $table = ll_tools_dictionary_lookup_table_name();
    $wpdb->last_error = '';
    $found_table = (string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    );
    if ((string) $wpdb->last_error !== '') {
        // A transient probe failure is not durable evidence that the table is
        // absent. Fail this request closed without poisoning the option used
        // by later repair/diagnostic paths.
        $GLOBALS['ll_tools_dictionary_lookup_table_exists_request_cache'] = false;
        return false;
    }

    $cached = $found_table === $table;
    $GLOBALS['ll_tools_dictionary_lookup_table_exists_request_cache'] = $cached;
    update_option(LL_TOOLS_DICTIONARY_LOOKUP_EXISTS_OPTION, $cached ? '1' : '0', false);

    return $cached;
}

/**
 * Verify the lookup table contract before treating its version marker as durable.
 */
function ll_tools_dictionary_lookup_schema_is_ready(bool $refresh = false): bool {
    global $wpdb;

    $cached = $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] ?? null;
    if (!$refresh && is_bool($cached)) {
        return $cached;
    }

    if (
        !$refresh
        && (string) get_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION, '') === LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION
        && (string) get_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION, '') === LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION
    ) {
        $cached = ll_tools_dictionary_lookup_table_exists();
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = $cached;
        return $cached;
    }

    $table = ll_tools_dictionary_lookup_table_name();
    if (!ll_tools_dictionary_lookup_table_exists(true)) {
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
        return false;
    }

    $wpdb->last_error = '';
    $table_status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table))
    );
    if (
        !is_object($table_status)
        || strtoupper((string) ($table_status->Engine ?? '')) !== 'INNODB'
        || (string) $wpdb->last_error !== ''
    ) {
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
        return false;
    }

    $wpdb->last_error = '';
    $column_rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
    if (!is_array($column_rows) || (string) $wpdb->last_error !== '') {
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
        return false;
    }

    $columns = [];
    foreach ($column_rows as $column_row) {
        $column_name = (string) ($column_row['Field'] ?? '');
        if ($column_name !== '') {
            $columns[$column_name] = $column_row;
        }
    }
    $column_contracts = [
        'id' => [
            'type' => '/^bigint(?:\(\d+\))? unsigned$/',
            'default' => null,
        ],
        'entry_id' => [
            'type' => '/^bigint(?:\(\d+\))? unsigned$/',
            'default' => null,
        ],
        'lookup_kind' => [
            'type' => '/^varchar\(20\)$/',
            'default' => null,
        ],
        'lookup_value' => [
            'type' => '/^varchar\(191\)$/',
            'default' => null,
        ],
        'value_length' => [
            'type' => '/^smallint(?:\(\d+\))? unsigned$/',
            'default' => '0',
        ],
    ];
    foreach ($column_contracts as $column_name => $contract) {
        $column = $columns[$column_name] ?? null;
        $type = is_array($column)
            ? strtolower(trim((string) ($column['Type'] ?? '')))
            : '';
        $default = is_array($column) && array_key_exists('Default', $column)
            ? $column['Default']
            : null;
        if (
            !is_array($column)
            || strtoupper((string) ($column['Null'] ?? '')) !== 'NO'
            || preg_match((string) $contract['type'], $type) !== 1
            || ($contract['default'] !== null && (string) $default !== (string) $contract['default'])
        ) {
            $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
            return false;
        }
    }
    if (stripos((string) ($columns['id']['Extra'] ?? ''), 'auto_increment') === false) {
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
        return false;
    }

    $wpdb->last_error = '';
    $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    if (!is_array($index_rows) || (string) $wpdb->last_error !== '') {
        $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
        return false;
    }

    $indexes = [];
    $index_uniqueness = [];
    $index_has_prefix = [];
    foreach ($index_rows as $index_row) {
        $key_name = (string) ($index_row['Key_name'] ?? '');
        $column_name = (string) ($index_row['Column_name'] ?? '');
        if ($key_name === '' || $column_name === '') {
            continue;
        }
        $sequence = max(1, (int) ($index_row['Seq_in_index'] ?? 1));
        $indexes[$key_name][$sequence] = $column_name;
        $index_uniqueness[$key_name] = (int) ($index_row['Non_unique'] ?? 1);
        if (isset($index_row['Sub_part']) && (int) $index_row['Sub_part'] > 0) {
            $index_has_prefix[$key_name] = true;
        }
    }
    foreach ($indexes as &$index_columns) {
        ksort($index_columns, SORT_NUMERIC);
        $index_columns = array_values($index_columns);
    }
    unset($index_columns);

    $required_indexes = [
        'PRIMARY' => ['columns' => ['id'], 'non_unique' => 0],
        'uniq_entry_lookup' => ['columns' => ['entry_id', 'lookup_kind', 'lookup_value'], 'non_unique' => 0],
        'idx_kind_value' => ['columns' => ['lookup_kind', 'lookup_value'], 'non_unique' => 1],
        'idx_value_kind' => ['columns' => ['lookup_value', 'lookup_kind'], 'non_unique' => 1],
        'idx_entry' => ['columns' => ['entry_id'], 'non_unique' => 1],
    ];
    foreach ($required_indexes as $index_name => $contract) {
        if (
            ($indexes[$index_name] ?? []) !== $contract['columns']
            || (int) ($index_uniqueness[$index_name] ?? -1) !== $contract['non_unique']
            || !empty($index_has_prefix[$index_name])
        ) {
            $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = false;
            return false;
        }
    }

    $GLOBALS['ll_tools_dictionary_lookup_schema_ready_request_cache'] = true;
    return true;
}

/**
 * Remove same-named prefix indexes that dbDelta intentionally treats as
 * equivalent to full-length indexes. The next dbDelta pass recreates the
 * canonical definitions.
 *
 * @return int Number of indexes removed, or -1 when metadata/DDL failed.
 */
function ll_tools_dictionary_lookup_remove_prefixed_indexes(): int {
    global $wpdb;

    $table = ll_tools_dictionary_lookup_table_name();
    if (!ll_tools_dictionary_lookup_table_exists(true)) {
        return -1;
    }

    $wpdb->last_error = '';
    $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    if (!is_array($index_rows) || (string) $wpdb->last_error !== '') {
        return -1;
    }

    $repairable_names = [
        'uniq_entry_lookup' => true,
        'idx_kind_value' => true,
        'idx_value_kind' => true,
        'idx_entry' => true,
    ];
    $prefixed_names = [];
    foreach ($index_rows as $index_row) {
        $index_name = (string) ($index_row['Key_name'] ?? '');
        if (
            isset($repairable_names[$index_name])
            && isset($index_row['Sub_part'])
            && (int) $index_row['Sub_part'] > 0
        ) {
            $prefixed_names[$index_name] = true;
        }
    }
    if ($prefixed_names === []) {
        return 0;
    }

    $drop_clauses = array_map(
        static function (string $index_name): string {
            return 'DROP INDEX `' . $index_name . '`';
        },
        array_keys($prefixed_names)
    );
    $wpdb->last_error = '';
    $dropped = $wpdb->query("ALTER TABLE {$table} " . implode(', ', $drop_clauses));
    if ($dropped === false || (string) $wpdb->last_error !== '') {
        return -1;
    }

    return count($prefixed_names);
}

/**
 * Install or upgrade the dictionary lookup table schema.
 */
function ll_tools_install_dictionary_lookup_schema(): bool {
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
    ) ENGINE=InnoDB {$charset_collate};";

    dbDelta($sql);
    if (ll_tools_dictionary_lookup_remove_prefixed_indexes() > 0) {
        dbDelta($sql);
    }
    $table_status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table))
    );
    if (
        is_object($table_status)
        && strtoupper((string) ($table_status->Engine ?? '')) !== 'INNODB'
    ) {
        $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB");
    }
    $ready = ll_tools_dictionary_lookup_schema_is_ready(true);
    $ready = (bool) apply_filters('ll_tools_dictionary_lookup_schema_exists_after_install', $ready);
    update_option(LL_TOOLS_DICTIONARY_LOOKUP_EXISTS_OPTION, $ready ? '1' : '0', false);
    if (!$ready) {
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION);
        set_transient(LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    update_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION, LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION, false);
    update_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION, LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION, false);
    delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT);

    return true;
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
 * Queue one bounded lookup rebuild attempt.
 */
function ll_tools_schedule_dictionary_lookup_rebuild_event(int $delay = 5): void {
    if (!wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK)) {
        wp_schedule_single_event(time() + max(1, $delay), LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
    }
}

/**
 * Fail lookup reads closed and queue standalone schema/generation repair.
 *
 * This function intentionally performs no DDL. It is safe from ordinary read
 * and caller-owned transaction paths; the scheduled rebuild performs the
 * expensive contract check and repair in bounded maintenance work.
 */
function ll_tools_dictionary_mark_lookup_unavailable_for_repair(int $delay = 30): void {
    $had_verified_marker = (string) get_option(
        LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION,
        ''
    ) !== '';
    delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION);

    $state = ll_tools_get_dictionary_lookup_rebuild_state();
    $needs_reset = $state['status'] !== 'pending'
        || (int) $state['last_id'] !== 0
        || (int) $state['processed'] !== 0
        || (string) $state['started_at'] !== ''
        || (string) $state['completed_at'] !== ''
        || (int) $state['truncate_pending'] !== 1;
    if ($needs_reset) {
        ll_tools_update_dictionary_lookup_rebuild_state([
            'status' => 'pending',
            'last_id' => 0,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
            'truncate_pending' => 1,
        ]);
    }

    ll_tools_schedule_dictionary_lookup_rebuild_event($delay);
    if (
        ($had_verified_marker || $needs_reset)
        && function_exists('ll_tools_bump_dictionary_browser_cache_version')
    ) {
        ll_tools_bump_dictionary_browser_cache_version();
    }
}

/**
 * Mark the lookup table for a full rebuild and queue the next batch.
 */
function ll_tools_schedule_dictionary_lookup_rebuild(bool $reset = false): void {
    if (!ll_tools_dictionary_lookup_schema_is_ready()) {
        if (get_transient(LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT)) {
            ll_tools_schedule_dictionary_lookup_rebuild_event(5 * MINUTE_IN_SECONDS);
            return;
        }
        if (!ll_tools_install_dictionary_lookup_schema()) {
            ll_tools_schedule_dictionary_lookup_rebuild_event(5 * MINUTE_IN_SECONDS);
            return;
        }
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

    ll_tools_schedule_dictionary_lookup_rebuild_event(5);
}

/**
 * Determine whether the lookup table is ready for fast searches.
 */
function ll_tools_dictionary_lookup_is_ready(): bool {
    if (!ll_tools_dictionary_lookup_schema_is_ready()) {
        ll_tools_dictionary_mark_lookup_unavailable_for_repair(30);
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
    if (
        $installed === LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION
        && (string) get_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION, '') === LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION
    ) {
        // The verified marker is written only after the full table contract has
        // passed. Do not issue SHOW queries on every unrelated WordPress init;
        // mutation/rebuild paths still force a full contract check, while
        // reads verify table availability and fail closed on query errors.
        return;
    }
    if (get_transient(LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT)) {
        return;
    }

    if (!ll_tools_install_dictionary_lookup_schema()) {
        return;
    }
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
 * Return the indexed lookup kind used for optional-apostrophe rows.
 */
function ll_tools_dictionary_optional_apostrophe_lookup_kind(string $kind): string {
    if ($kind === 'headword') {
        return 'headword_apos';
    }
    if ($kind === 'translation') {
        return 'translation_apos';
    }

    return '';
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
    $sources = function_exists('ll_tools_dictionary_collect_sources')
        ? ll_tools_dictionary_collect_sources($senses)
        : [];
    $dialects = function_exists('ll_tools_dictionary_collect_dialects')
        ? ll_tools_dictionary_collect_dialects($senses)
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
            'value_length' => min(65535, function_exists('mb_strlen')
                ? (int) mb_strlen($candidate, 'UTF-8')
                : strlen($candidate)),
        ];

    };

    foreach ($headwords as $candidate) {
        $candidate = (string) $candidate;
        $append('headword', $candidate);
        $lookup_value = ll_tools_dictionary_prepare_lookup_value($candidate);
        $stripped = function_exists('ll_tools_dictionary_strip_optional_search_apostrophes')
            ? ll_tools_dictionary_prepare_lookup_value(ll_tools_dictionary_strip_optional_search_apostrophes($lookup_value))
            : $lookup_value;
        if ($stripped !== '' && $stripped !== $lookup_value) {
            $append('headword_apos', $stripped);
        }
    }
    foreach ($translations as $candidate) {
        $candidate = (string) $candidate;
        $append('translation', $candidate);
        $lookup_value = ll_tools_dictionary_prepare_lookup_value($candidate);
        $stripped = function_exists('ll_tools_dictionary_strip_optional_search_apostrophes')
            ? ll_tools_dictionary_prepare_lookup_value(ll_tools_dictionary_strip_optional_search_apostrophes($lookup_value))
            : $lookup_value;
        if ($stripped !== '' && $stripped !== $lookup_value) {
            $append('translation_apos', $stripped);
        }
    }
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $append('source', (string) ($source['id'] ?? ''));
        $append('source', (string) ($source['label'] ?? ''));
    }
    foreach ($dialects as $dialect) {
        $append('dialect', (string) $dialect);
    }

    return $rows;
}

/**
 * Delete all lookup rows for one entry.
 */
function ll_tools_dictionary_delete_lookup_rows_for_entry(int $entry_id, bool $bump_cache = true): void {
    global $wpdb;

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !ll_tools_dictionary_lookup_schema_is_ready(true)) {
        return;
    }

    $wpdb->delete(ll_tools_dictionary_lookup_table_name(), ['entry_id' => $entry_id], ['%d']);

    if ($bump_cache && function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
        ll_tools_bump_dictionary_browser_cache_version();
    }
}

/**
 * Build a collision-resistant, identifier-safe savepoint name for one call.
 */
function ll_tools_dictionary_lookup_unique_savepoint_name(string $purpose, int $entry_id = 0): string {
    static $sequence = 0;

    $sequence++;
    $purpose = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', $purpose));
    $purpose = substr(trim($purpose, '_'), 0, 8);
    if ($purpose === '') {
        $purpose = 'scope';
    }
    $entropy = substr(hash(
        'sha256',
        $purpose . '|' . $entry_id . '|' . $sequence . '|' . microtime(true) . '|' . wp_rand()
    ), 0, 12);

    return substr('ll_dict_' . $purpose . '_' . max(0, $entry_id) . '_' . $sequence . '_' . $entropy, 0, 64);
}

/**
 * Detect an enclosing database transaction without changing its ownership.
 */
function ll_tools_dictionary_lookup_connection_in_transaction(): bool {
    global $wpdb;

    $probe = ll_tools_dictionary_lookup_unique_savepoint_name('probe');
    $previous_suppress_errors = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    $created = $wpdb->query("SAVEPOINT {$probe}");
    $active = $created !== false && (string) $wpdb->last_error === '';
    if ($active) {
        // Some servers accept SAVEPOINT outside a useful transaction. A
        // no-op rollback proves that the savepoint is actually active.
        $wpdb->last_error = '';
        $verified = $wpdb->query("ROLLBACK TO SAVEPOINT {$probe}");
        $active = $verified !== false && (string) $wpdb->last_error === '';
        $wpdb->query("RELEASE SAVEPOINT {$probe}");
    }
    $wpdb->last_error = '';
    $wpdb->suppress_errors($previous_suppress_errors);

    return $active;
}

/**
 * Atomically replace lookup rows for one dictionary entry.
 */
function ll_tools_dictionary_sync_lookup_rows_for_entry(
    int $entry_id,
    bool $bump_cache = true,
    bool $schema_prevalidated = false
): bool {
    global $wpdb;

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return false;
    }
    if (!$schema_prevalidated && !ll_tools_dictionary_lookup_schema_is_ready(true)) {
        // Never run schema DDL from a save-time mutation: it could implicitly
        // commit a transaction owned by the caller. Mark reads unready and let
        // the standalone rebuild repair the contract.
        ll_tools_dictionary_mark_lookup_unavailable_for_repair(30);
        return false;
    }

    // A failed source read must not look like a confirmed deletion or type
    // change. Prime the complete post/meta source before replacing any rows so
    // a later successful cache read cannot mask an earlier database failure.
    $wpdb->last_error = '';
    $post = get_post($entry_id);
    if ((string) $wpdb->last_error !== '') {
        return false;
    }

    $is_revision = false;
    if ($post instanceof WP_Post) {
        $wpdb->last_error = '';
        $is_revision = (bool) wp_is_post_revision($entry_id);
        if ((string) $wpdb->last_error !== '') {
            return false;
        }
    }
    $is_dictionary_entry = $post instanceof WP_Post
        && !$is_revision
        && $post->post_type === 'll_dictionary_entry';
    $rows = [];
    if ($is_dictionary_entry) {
        wp_cache_delete($entry_id, 'post_meta');
        $wpdb->last_error = '';
        $all_meta = get_post_meta($entry_id);
        if (!is_array($all_meta) || (string) $wpdb->last_error !== '') {
            wp_cache_delete($entry_id, 'post_meta');
            return false;
        }

        $wpdb->last_error = '';
        $rows = ll_tools_dictionary_build_lookup_rows_for_entry($entry_id);
        if ((string) $wpdb->last_error !== '') {
            return false;
        }
    }
    $table = ll_tools_dictionary_lookup_table_name();
    $use_savepoint = ll_tools_dictionary_lookup_connection_in_transaction();
    $savepoint = ll_tools_dictionary_lookup_unique_savepoint_name('sync', $entry_id);

    $wpdb->last_error = '';
    $transaction_started = $use_savepoint
        ? $wpdb->query("SAVEPOINT {$savepoint}")
        : $wpdb->query('START TRANSACTION');
    if ($transaction_started === false || (string) $wpdb->last_error !== '') {
        return false;
    }

    $rollback = static function () use ($wpdb, $use_savepoint, $savepoint): void {
        if ($use_savepoint) {
            $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
            $wpdb->query("RELEASE SAVEPOINT {$savepoint}");
            return;
        }
        $wpdb->query('ROLLBACK');
    };

    $wpdb->last_error = '';
    $deleted = $wpdb->delete($table, ['entry_id' => $entry_id], ['%d']);
    if ($deleted === false || (string) $wpdb->last_error !== '') {
        $rollback();
        return false;
    }

    if (!empty($rows)) {
        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(%d, %s, %s, %d)';
            $params[] = (int) $row['entry_id'];
            $params[] = (string) $row['lookup_kind'];
            $params[] = (string) $row['lookup_value'];
            $params[] = max(0, (int) $row['value_length']);
        }

        // lookup_value follows the site's normal WordPress collation. Distinct
        // normalized PHP strings can therefore collide legitimately (for
        // example, accent-equivalent values), so IGNORE remains intentional.
        // Validate every warning immediately below so truncation or coercion
        // can never publish a partial replacement.
        $sql = "INSERT IGNORE INTO {$table} /* ll_tools_dictionary_lookup_sync */ (entry_id, lookup_kind, lookup_value, value_length) VALUES "
            . implode(', ', $placeholders);
        $wpdb->last_error = '';
        $inserted = $wpdb->query($wpdb->prepare($sql, $params));
        $insert_error = (string) $wpdb->last_error;
        if ($inserted === false || $insert_error !== '') {
            $rollback();
            return false;
        }

        $wpdb->last_error = '';
        $warning_rows = $wpdb->get_results('SHOW WARNINGS', ARRAY_A);
        $warning_error = (string) $wpdb->last_error;
        if (!is_array($warning_rows) || $warning_error !== '') {
            $rollback();
            return false;
        }
        foreach ($warning_rows as $warning_row) {
            // 1062 is the expected unique-key collision for values that the
            // table collation considers equivalent. Every other warning can
            // indicate coercion or truncation and invalidates the replacement.
            if ((int) ($warning_row['Code'] ?? 0) !== 1062) {
                $rollback();
                return false;
            }
        }

        $wpdb->last_error = '';
        $stored_row_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE entry_id = %d",
            $entry_id
        ));
        if (
            !is_numeric($stored_row_count)
            || (string) $wpdb->last_error !== ''
            || (int) $stored_row_count !== (int) $inserted
            || (int) $stored_row_count <= 0
        ) {
            $rollback();
            return false;
        }
    }

    $wpdb->last_error = '';
    $committed = $use_savepoint
        ? $wpdb->query("RELEASE SAVEPOINT {$savepoint}")
        : $wpdb->query('COMMIT');
    if ($committed === false || (string) $wpdb->last_error !== '') {
        $rollback();
        return false;
    }

    if ($bump_cache && function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
        ll_tools_bump_dictionary_browser_cache_version();
    }

    return true;
}

/**
 * Keep lookup rows in sync when dictionary entries change.
 */
function ll_tools_dictionary_sync_lookup_rows_on_save($post_id, $post, $update): void {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }

    if (!ll_tools_dictionary_sync_lookup_rows_for_entry((int) $post_id)) {
        ll_tools_dictionary_mark_lookup_unavailable_for_repair(30);
    }
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
        if (!ll_tools_dictionary_lookup_schema_is_ready(true)) {
            if (!ll_tools_install_dictionary_lookup_schema()) {
                ll_tools_schedule_dictionary_lookup_rebuild_event(5 * MINUTE_IN_SECONDS);
                return;
            }
        }

        $table = ll_tools_dictionary_lookup_table_name();
        $state = ll_tools_get_dictionary_lookup_rebuild_state();
        if ($state['status'] === 'completed' && $state['truncate_pending'] === 0) {
            return;
        }

        if ($state['truncate_pending'] === 1) {
            $wpdb->last_error = '';
            $truncated = $wpdb->query("TRUNCATE TABLE {$table}");
            if ($truncated === false || (string) $wpdb->last_error !== '') {
                ll_tools_schedule_dictionary_lookup_rebuild_event(30);
                return;
            }
            $state['last_id'] = 0;
            $state['processed'] = 0;
            $state['truncate_pending'] = 0;
            $state['status'] = 'running';
            $state['started_at'] = current_time('mysql');
            $state['completed_at'] = '';
            ll_tools_update_dictionary_lookup_rebuild_state($state);
        } elseif ($state['started_at'] === '') {
            $state['started_at'] = current_time('mysql');
            $state['status'] = 'running';
        }

        $batch_size = (int) apply_filters('ll_tools_dictionary_lookup_rebuild_batch_size', 250);
        $batch_size = max(25, min(1000, $batch_size));

        $wpdb->last_error = '';
        $raw_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'll_dictionary_entry'
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            (int) $state['last_id'],
            $batch_size
        ));
        if (!is_array($raw_ids) || (string) $wpdb->last_error !== '') {
            ll_tools_update_dictionary_lookup_rebuild_state($state);
            ll_tools_schedule_dictionary_lookup_rebuild_event(30);
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $raw_ids)));

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
            if (!ll_tools_dictionary_sync_lookup_rows_for_entry((int) $entry_id, false, true)) {
                ll_tools_update_dictionary_lookup_rebuild_state($state);
                ll_tools_schedule_dictionary_lookup_rebuild_event(30);
                return;
            }
        }

        $state['last_id'] = (int) end($ids);
        $state['processed'] += count($ids);

        if (count($ids) < $batch_size) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
        } else {
            $state['status'] = 'running';
            ll_tools_schedule_dictionary_lookup_rebuild_event(1);
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
 * Split a UTF-8 string into searchable characters.
 *
 * @return string[]
 */
function ll_tools_dictionary_lookup_split_chars(string $value): array {
    if ($value === '') {
        return [];
    }

    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars)) {
        return array_values(array_map('strval', $chars));
    }

    return str_split($value);
}

/**
 * Build lookup-table variants for a wordset's configured close-character groups.
 *
 * @return string[]
 */
function ll_tools_dictionary_build_close_lookup_variants(string $lookup, int $wordset_id = 0): array {
    $lookup = ll_tools_dictionary_prepare_lookup_value($lookup);
    $wordset_id = max(0, (int) $wordset_id);
    if ($lookup === '' || !function_exists('ll_tools_dictionary_get_close_match_character_groups')) {
        return $lookup !== '' ? [$lookup] : [];
    }

    $max_variants = (int) apply_filters('ll_tools_dictionary_close_lookup_variant_limit', 128, $lookup, $wordset_id);
    $max_variants = max(8, min(512, $max_variants));
    $variant_map = [];
    foreach (ll_tools_dictionary_get_close_match_character_groups($wordset_id) as $group) {
        $group_variants = [];
        foreach ($group as $char) {
            $char_lookup = ll_tools_dictionary_prepare_lookup_value((string) $char);
            if ($char_lookup !== '') {
                $group_variants[$char_lookup] = true;
            }
        }
        if (count($group_variants) < 2) {
            continue;
        }
        foreach (array_keys($group_variants) as $char_lookup) {
            $variant_map[$char_lookup] = array_keys($group_variants);
        }
    }

    $variants = ['' => true];
    foreach (ll_tools_dictionary_lookup_split_chars($lookup) as $char) {
        $choices = $variant_map[$char] ?? [$char];
        $next = [];
        foreach (array_keys($variants) as $prefix) {
            foreach ($choices as $choice) {
                $candidate = $prefix . $choice;
                $next[$candidate] = true;
                if (count($next) >= $max_variants) {
                    break 2;
                }
            }
        }
        $variants = $next;
        if (count($variants) >= $max_variants) {
            break;
        }
    }

    if (function_exists('ll_tools_dictionary_uses_optional_search_apostrophes')
        && ll_tools_dictionary_uses_optional_search_apostrophes($wordset_id)
        && function_exists('ll_tools_dictionary_strip_optional_search_apostrophes')
    ) {
        foreach (array_keys($variants) as $variant) {
            $stripped = ll_tools_dictionary_prepare_lookup_value(
                ll_tools_dictionary_strip_optional_search_apostrophes($variant)
            );
            if ($stripped !== '') {
                $variants[$stripped] = true;
            }
            if (count($variants) >= $max_variants) {
                break;
            }
        }
    }

    return array_values(array_filter(array_keys($variants), static function (string $variant): bool {
        return $variant !== '';
    }));
}

/**
 * Return close variants that differ from the strict lookup value.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_non_strict_close_lookup_variants(string $lookup, int $wordset_id = 0): array {
    $lookup = ll_tools_dictionary_prepare_lookup_value($lookup);
    $variants = ll_tools_dictionary_build_close_lookup_variants($lookup, $wordset_id);

    return array_values(array_filter($variants, static function (string $variant) use ($lookup): bool {
        return $variant !== '' && $variant !== $lookup;
    }));
}

/**
 * Return the minimum normalized length before lookup-table contains fallback may run.
 */
function ll_tools_dictionary_lookup_contains_fallback_min_chars(): int {
    $min_chars = (int) apply_filters('ll_tools_dictionary_lookup_contains_fallback_min_chars', 3);

    return max(3, $min_chars);
}

/**
 * Determine whether the lookup table may use broad contains matching.
 *
 * Indexed exact/prefix lookup is the public path. Leading-wildcard contains
 * searches are reserved for staff/debug contexts or explicit site opt-in
 * because they can scan the whole dictionary lookup table.
 *
 * @param string[] $statuses Allowed post statuses.
 */
function ll_tools_dictionary_allow_lookup_contains_fallback(
    string $search,
    array $statuses,
    string $search_scope = 'all',
    int $wordset_id = 0,
    int $limit = 0
): bool {
    $lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
        ? ll_tools_dictionary_entry_normalize_lookup_value($search)
        : strtolower(trim($search));
    $lookup_length = function_exists('mb_strlen') ? mb_strlen($lookup, 'UTF-8') : strlen($lookup);
    if ($lookup_length < ll_tools_dictionary_lookup_contains_fallback_min_chars()) {
        return false;
    }

    $allowed = function_exists('current_user_can')
        && (current_user_can('view_ll_tools') || current_user_can('edit_posts'));

    return (bool) apply_filters(
        'll_tools_dictionary_allow_lookup_contains_fallback',
        $allowed,
        $search,
        $statuses,
        $search_scope,
        $wordset_id,
        $limit
    );
}

/**
 * Return the maximum normalized length that should use exact/prefix-only search.
 */
function ll_tools_dictionary_short_search_max_chars(): int {
    $max_chars = (int) apply_filters('ll_tools_dictionary_short_search_max_chars', 2);

    return max(0, min(4, $max_chars));
}

/**
 * Determine whether a search should skip close/apostrophe/contains matching.
 */
function ll_tools_dictionary_is_short_search_lookup(string $lookup): bool {
    $lookup = ll_tools_dictionary_prepare_lookup_value($lookup);
    if ($lookup === '') {
        return false;
    }

    $max_chars = ll_tools_dictionary_short_search_max_chars();
    if ($max_chars <= 0) {
        return false;
    }

    $lookup_length = function_exists('mb_strlen') ? mb_strlen($lookup, 'UTF-8') : strlen($lookup);

    return $lookup_length <= $max_chars;
}

/**
 * Return a bounded fallback limit for uncapped short lookup-table searches.
 */
function ll_tools_dictionary_short_lookup_uncapped_limit(): int {
    $limit = (int) apply_filters('ll_tools_dictionary_short_lookup_uncapped_limit', 1000);

    return max(100, min(5000, $limit));
}

/**
 * Fail closed after a lookup-table query error and serve the bounded legacy path.
 *
 * @param string[] $statuses
 * @return int[]
 */
function ll_tools_dictionary_lookup_handle_query_error(
    string $search,
    array $statuses,
    string $search_scope,
    int $limit,
    int $wordset_id
): array {
    ll_tools_dictionary_mark_lookup_unavailable_for_repair(30);

    if (function_exists('ll_tools_dictionary_query_entry_ids_from_search_meta')) {
        $fallback_limit = $limit > 0
            ? $limit
            : ll_tools_dictionary_short_lookup_uncapped_limit();
        return ll_tools_dictionary_query_entry_ids_from_search_meta(
            $search,
            $statuses,
            $search_scope,
            $wordset_id,
            '',
            $fallback_limit
        );
    }

    return [];
}

/**
 * Query entry IDs from the indexed lookup table.
 *
 * @param string[] $statuses Allowed post statuses.
 * @param int      $limit      Optional maximum number of candidate IDs to return.
 * @return int[]
 */
function ll_tools_dictionary_query_entry_ids_from_lookup_table(string $search, array $statuses, string $search_scope = 'all', int $limit = 0, int $wordset_id = 0): array {
    static $request_cache = [];
    global $wpdb;

    $limit = max(0, $limit);
    $wordset_id = max(0, (int) $wordset_id);
    $lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
        ? ll_tools_dictionary_entry_normalize_lookup_value($search)
        : trim(strtolower($search));
    $search_scope = function_exists('ll_tools_dictionary_normalize_search_scope')
        ? ll_tools_dictionary_normalize_search_scope($search_scope)
        : trim(strtolower($search_scope));
    if ($lookup === '' || empty($statuses) || !ll_tools_dictionary_lookup_is_ready()) {
        return [];
    }

    $is_short_query = ll_tools_dictionary_is_short_search_lookup($lookup);
    $has_close_config = !$is_short_query
        && function_exists('ll_tools_dictionary_wordset_has_close_search_config')
        && ll_tools_dictionary_wordset_has_close_search_config($wordset_id);
    $close_variants = $has_close_config
        ? ll_tools_dictionary_get_non_strict_close_lookup_variants($lookup, $wordset_id)
        : [];
    $apostrophe_variants = ($has_close_config && function_exists('ll_tools_dictionary_get_optional_apostrophe_lookup_variants'))
        ? array_values(array_filter(array_unique(array_map(
            'll_tools_dictionary_prepare_lookup_value',
            ll_tools_dictionary_get_optional_apostrophe_lookup_variants($lookup, $wordset_id)
        ))))
        : [];
    $allow_contains = !$is_short_query && ll_tools_dictionary_allow_lookup_contains_fallback(
        $search,
        $statuses,
        $search_scope,
        $wordset_id,
        $limit
    );
    $cache_args = [
        'search' => $lookup,
        'search_scope' => $search_scope,
        'wordset_id' => $wordset_id,
        'statuses' => array_values($statuses),
        'limit' => $limit,
        'allow_contains' => $allow_contains ? 1 : 0,
        'short_query_mode' => $is_short_query ? 1 : 0,
        'lookup_table_version' => LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION,
        'close_config' => function_exists('ll_tools_dictionary_get_close_match_config_hash')
            ? ll_tools_dictionary_get_close_match_config_hash($wordset_id)
            : '',
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
    $lookup_length = function_exists('mb_strlen') ? mb_strlen($lookup, 'UTF-8') : strlen($lookup);
    $use_contains = $allow_contains && $lookup_length >= ll_tools_dictionary_lookup_contains_fallback_min_chars();

    $normal_kinds = [];
    if ($search_scope === 'headword') {
        $normal_kinds = ['headword'];
    } elseif ($search_scope !== '' && $search_scope !== 'all') {
        $normal_kinds = ['translation'];
    } else {
        $normal_kinds = ['headword', 'translation'];
    }

    $run_short_lookup_query = static function () use (
        $wpdb,
        $table,
        $status_placeholders,
        $statuses,
        $lookup,
        $normal_kinds,
        $limit
    ): ?array {
        $target_limit = $limit > 0 ? $limit : ll_tools_dictionary_short_lookup_uncapped_limit();
        $target_limit = max(1, $target_limit);
        $prefix_lookup = $wpdb->esc_like($lookup) . '%';
        $seen = [];
        $ids = [];
        $query_failed = false;

        $append_results = static function (string $kind, string $operator, string $value) use (
            $wpdb,
            $table,
            $status_placeholders,
            $statuses,
            $target_limit,
            &$seen,
            &$ids,
            &$query_failed
        ): void {
            if ($query_failed || count($ids) >= $target_limit) {
                return;
            }

            $remaining = max(1, $target_limit - count($ids));
            $title_exact_order_sql = '';
            $title_exact_params = [];
            if ($kind === 'headword' && $operator === '=') {
                $title_exact_order_sql = "CASE WHEN LOWER(TRIM(p.post_title)) = %s THEN 0 ELSE 1 END,";
                $title_exact_params[] = $value;
            }

            $sql = "
                SELECT l.entry_id
                FROM {$table} l
                INNER JOIN {$wpdb->posts} p
                        ON p.ID = l.entry_id
                WHERE p.post_type = 'll_dictionary_entry'
                  AND p.post_status IN ({$status_placeholders})
                  AND l.lookup_kind = %s
                  AND l.lookup_value {$operator} %s
                ORDER BY
                    {$title_exact_order_sql}
                    l.lookup_value ASC,
                    l.value_length ASC,
                    l.entry_id ASC
                LIMIT %d
            ";
            $params = array_merge($statuses, [$kind, $value], $title_exact_params, [$remaining]);
            $wpdb->last_error = '';
            $raw_rows = $wpdb->get_col($wpdb->prepare($sql, $params));
            if (!is_array($raw_rows) || (string) $wpdb->last_error !== '') {
                $query_failed = true;
                return;
            }
            $rows = array_values(array_filter(array_map('intval', $raw_rows)));
            foreach ($rows as $entry_id) {
                if ($entry_id <= 0 || isset($seen[$entry_id])) {
                    continue;
                }

                $seen[$entry_id] = true;
                $ids[] = $entry_id;
                if (count($ids) >= $target_limit) {
                    break;
                }
            }
        };

        foreach ($normal_kinds as $kind) {
            $append_results((string) $kind, '=', $lookup);
        }
        foreach ($normal_kinds as $kind) {
            $append_results((string) $kind, 'LIKE', $prefix_lookup);
        }

        return $query_failed ? null : $ids;
    };

    if ($is_short_query) {
        $ids = $run_short_lookup_query();
        if ($ids === null) {
            return ll_tools_dictionary_lookup_handle_query_error(
                $search,
                $statuses,
                $search_scope,
                $limit,
                $wordset_id
            );
        }
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

    $run_lookup_query = static function (bool $contains_only) use (
        $wpdb,
        $table,
        $status_placeholders,
        $statuses,
        $lookup,
        $close_variants,
        $apostrophe_variants,
        $normal_kinds,
        $limit
    ): ?array {
        $search_clauses = [];
        $where_params = [];
        $case_sql = [];
        $case_params = [];

        $add_clause = static function (
            string $kind,
            string $expression,
            string $operator,
            string $value,
            int $rank
        ) use (&$search_clauses, &$where_params, &$case_sql, &$case_params): void {
            if ($value === '') {
                return;
            }

            $search_clauses[] = "(l.lookup_kind = %s AND {$expression} {$operator} %s)";
            $where_params[] = $kind;
            $where_params[] = $value;
            $case_sql[] = "WHEN l.lookup_kind = %s AND {$expression} {$operator} %s THEN {$rank}";
            $case_params[] = $kind;
            $case_params[] = $value;
        };

        if ($contains_only) {
            $contains_lookup = '%' . $wpdb->esc_like($lookup) . '%';
            foreach ($normal_kinds as $kind) {
                $rank = ($kind === 'headword') ? 4 : 5;
                $add_clause((string) $kind, 'l.lookup_value', 'LIKE', $contains_lookup, $rank);
            }
        } else {
            $prefix_lookup = $wpdb->esc_like($lookup) . '%';
            foreach ($normal_kinds as $kind) {
                $exact_rank = ($kind === 'headword') ? 0 : 1;
                $prefix_rank = ($kind === 'headword') ? 2 : 3;
                $add_clause((string) $kind, 'l.lookup_value', '=', $lookup, $exact_rank);
                $add_clause((string) $kind, 'l.lookup_value', 'LIKE', $prefix_lookup, $prefix_rank);
            }

            foreach ($close_variants as $variant) {
                $prefix_variant = $wpdb->esc_like((string) $variant) . '%';
                foreach ($normal_kinds as $kind) {
                    $exact_rank = ($kind === 'headword') ? 6 : 7;
                    $prefix_rank = ($kind === 'headword') ? 8 : 9;
                    $add_clause((string) $kind, 'l.lookup_value', '=', (string) $variant, $exact_rank);
                    $add_clause((string) $kind, 'l.lookup_value', 'LIKE', $prefix_variant, $prefix_rank);
                }
            }

            foreach ($apostrophe_variants as $variant) {
                $prefix_variant = $wpdb->esc_like((string) $variant) . '%';
                foreach ($normal_kinds as $kind) {
                    $apostrophe_kind = ll_tools_dictionary_optional_apostrophe_lookup_kind((string) $kind);
                    if ($apostrophe_kind === '') {
                        continue;
                    }
                    $exact_rank = ($kind === 'headword') ? 6 : 7;
                    $prefix_rank = ($kind === 'headword') ? 8 : 9;
                    $add_clause($apostrophe_kind, 'l.lookup_value', '=', (string) $variant, $exact_rank);
                    $add_clause($apostrophe_kind, 'l.lookup_value', 'LIKE', $prefix_variant, $prefix_rank);
                }
            }
        }

        if (empty($search_clauses)) {
            return [];
        }

        $where_sql = '(' . implode(' OR ', $search_clauses) . ')';
        $case_sql = "
            CASE
                " . implode("\n                ", $case_sql) . "
                ELSE 99
            END
        ";

        $sql = "
            SELECT l.entry_id
            FROM {$table} l
            INNER JOIN {$wpdb->posts} p
                    ON p.ID = l.entry_id
            WHERE p.post_type = 'll_dictionary_entry'
              AND p.post_status IN ({$status_placeholders})
              AND {$where_sql}
            GROUP BY l.entry_id, p.post_title
            ORDER BY
                MIN({$case_sql}) ASC,
                MIN(CASE WHEN l.lookup_kind = 'headword' AND l.lookup_value = %s AND LOWER(TRIM(p.post_title)) = %s THEN 0 ELSE 1 END) ASC,
                MIN(l.value_length) ASC,
                p.post_title ASC,
                l.entry_id ASC
        ";

        $params = array_merge($statuses, $where_params, $case_params, [$lookup, $lookup]);
        if ($limit > 0) {
            $sql .= "\nLIMIT %d";
            $params[] = $limit;
        }

        $wpdb->last_error = '';
        $raw_ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        if (!is_array($raw_ids) || (string) $wpdb->last_error !== '') {
            return null;
        }

        return array_values(array_filter(array_map('intval', $raw_ids)));
    };

    $ids = $run_lookup_query(false);
    if ($ids === null) {
        return ll_tools_dictionary_lookup_handle_query_error(
            $search,
            $statuses,
            $search_scope,
            $limit,
            $wordset_id
        );
    }
    if (empty($ids) && $use_contains) {
        $ids = $run_lookup_query(true);
        if ($ids === null) {
            return ll_tools_dictionary_lookup_handle_query_error(
                $search,
                $statuses,
                $search_scope,
                $limit,
                $wordset_id
            );
        }
    }

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
