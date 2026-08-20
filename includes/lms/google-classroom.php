<?php
/**
 * Secure Google Classroom OAuth, connection storage, and typed REST client.
 *
 * Classroom writes are deliberately disabled unless deployment code explicitly
 * certifies that the server-authoritative assignment/attempt subsystem is
 * ready. The formative Practice event contract is never a grade source here.
 */

if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_lms_seal_secret')) {
    require_once __DIR__ . '/credential-store.php';
}

if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION', '1.0.0');
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION', 'll_tools_google_classroom_schema_version');
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION', 'll_tools_google_classroom_schema_verified');
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_STATE_TTL')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_STATE_TTL', 10 * MINUTE_IN_SECONDS);
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_HTTP_TIMEOUT')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_HTTP_TIMEOUT', 10);
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_HTTP_BODY_LIMIT')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_HTTP_BODY_LIMIT', 262144);
}
if (!defined('LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK')) {
    define('LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK', 'll_tools_google_classroom_oauth_state_cleanup');
}
/** Hard server-side account ceiling shared by admission and the admin UI. */
function ll_tools_google_classroom_connection_limit(): int {
    return 20;
}

/** Hard per-teacher ceiling for every nonexpired authorization generation. */
function ll_tools_google_classroom_pending_oauth_state_limit(): int {
    return 5;
}

function ll_tools_google_classroom_table_names(): array {
    global $wpdb;

    return [
        'connections' => $wpdb->prefix . 'll_tools_google_classroom_connections',
        'oauth_states' => $wpdb->prefix . 'll_tools_google_classroom_oauth_states',
    ];
}

function ll_tools_google_classroom_schema_markers_current(): bool {
    return (string) get_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION, '') === LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION
        && (string) get_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION, '') === LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION;
}

/** Record full-readback drift without attempting DDL in the feature request. */
function ll_tools_google_classroom_schedule_schema_drift_repair(): bool {
    if (function_exists('ll_tools_schedule_schema_maintenance')) {
        ll_tools_schedule_schema_maintenance('google_classroom', 5 * MINUTE_IN_SECONDS);
    }
    return false;
}

/**
 * Full readback on init is reserved for trusted maintenance-like contexts.
 * Normal public and admin init stays on the marker-only fast path.
 */
function ll_tools_google_classroom_init_full_readback_is_allowed(): bool {
    $allowed = defined('WP_TESTS_DOMAIN')
        || (defined('WP_CLI') && WP_CLI)
        || (function_exists('wp_doing_cron') && wp_doing_cron());
    return (bool) apply_filters(
        'll_tools_google_classroom_schema_init_full_readback_allowed',
        $allowed
    );
}

/**
 * Exact semantic storage contract. MySQL 8 may omit integer display widths,
 * so the type regexes accept only the equivalent width/no-width spellings.
 */
function ll_tools_google_classroom_schema_contract(): array {
    $unsigned_bigint = '/^bigint(?:\(20\))? unsigned$/';

    return [
        'connections' => [
            'columns' => [
                'id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'default' => null, 'auto_increment' => true, 'definition' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT'],
                'teacher_user_id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'default' => null, 'definition' => 'bigint(20) unsigned NOT NULL'],
                'google_sub_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO', 'default' => null, 'definition' => 'char(64) NOT NULL'],
                'credential_envelope' => ['type' => '/^longtext$/', 'null' => 'NO', 'default' => null, 'definition' => 'longtext NOT NULL'],
                'scopes_json' => ['type' => '/^text$/', 'null' => 'NO', 'default' => null, 'definition' => 'text NOT NULL'],
                'status' => ['type' => '/^varchar\(24\)$/', 'null' => 'NO', 'default' => 'connected', 'definition' => "varchar(24) NOT NULL DEFAULT 'connected'"],
                'token_version' => ['type' => $unsigned_bigint, 'null' => 'NO', 'default' => '1', 'definition' => 'bigint(20) unsigned NOT NULL DEFAULT 1'],
                'connected_at' => ['type' => '/^datetime$/', 'null' => 'NO', 'default' => null, 'definition' => 'datetime NOT NULL'],
                'updated_at' => ['type' => '/^datetime$/', 'null' => 'NO', 'default' => null, 'definition' => 'datetime NOT NULL'],
                'disconnected_at' => ['type' => '/^datetime$/', 'null' => 'YES', 'default' => null, 'definition' => 'datetime NULL DEFAULT NULL'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_teacher_sub' => ['unique' => true, 'columns' => ['teacher_user_id', 'google_sub_hash']],
                'idx_teacher_status' => ['unique' => false, 'columns' => ['teacher_user_id', 'status', 'updated_at']],
            ],
        ],
        'oauth_states' => [
            'columns' => [
                'state_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO', 'default' => null, 'definition' => 'char(64) NOT NULL'],
                'teacher_user_id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'default' => null, 'definition' => 'bigint(20) unsigned NOT NULL'],
                'pkce_envelope' => ['type' => '/^text$/', 'null' => 'NO', 'default' => null, 'definition' => 'text NOT NULL'],
                'callback_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO', 'default' => null, 'definition' => 'char(64) NOT NULL'],
                'expires_at' => ['type' => '/^datetime$/', 'null' => 'NO', 'default' => null, 'definition' => 'datetime NOT NULL'],
                'consumed_at' => ['type' => '/^datetime$/', 'null' => 'YES', 'default' => null, 'definition' => 'datetime NULL DEFAULT NULL'],
                'created_at' => ['type' => '/^datetime$/', 'null' => 'NO', 'default' => null, 'definition' => 'datetime NOT NULL'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['state_hash']],
                'idx_teacher_expires' => ['unique' => false, 'columns' => ['teacher_user_id', 'expires_at']],
                'idx_expires' => ['unique' => false, 'columns' => ['expires_at']],
            ],
        ],
    ];
}

/**
 * Full table/engine/column/index readback. Runtime callers fail closed when
 * this is false; results are cached only within the current PHP request.
 */
function ll_tools_google_classroom_schema_is_ready(bool $refresh = false): bool {
    global $wpdb;

    static $cached = null;
    if (!ll_tools_google_classroom_schema_markers_current()) {
        $cached = false;
        return false;
    }
    if (!$refresh && is_bool($cached)) {
        return $cached;
    }
    $cached = false;

    $contracts = ll_tools_google_classroom_schema_contract();
    $tables = ll_tools_google_classroom_table_names();

    foreach ($contracts as $key => $contract) {
        $table = $tables[$key];
        $wpdb->last_error = '';
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table),
            ARRAY_A
        );
        if (
            !is_array($status)
            || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0
            || (string) $wpdb->last_error !== ''
        ) {
            return ll_tools_google_classroom_schedule_schema_drift_repair();
        }

        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM {$table}", ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            return ll_tools_google_classroom_schedule_schema_drift_repair();
        }
        $present = [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field === '' || isset($present[$field])) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
            $present[$field] = $row;
        }
        foreach ($contract['columns'] as $column => $requirements) {
            if (!isset($present[$column])) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
            $row = $present[$column];
            $type = strtolower(trim((string) ($row['Type'] ?? '')));
            $null = strtoupper(trim((string) ($row['Null'] ?? '')));
            $default = array_key_exists('Default', $row) ? $row['Default'] : null;
            $extra = strtolower(trim((string) ($row['Extra'] ?? '')));
            $expects_auto_increment = !empty($requirements['auto_increment']);
            $has_auto_increment = str_contains($extra, 'auto_increment');
            if (
                preg_match((string) $requirements['type'], $type) !== 1
                || $null !== (string) $requirements['null']
                || $default !== $requirements['default']
                || $has_auto_increment !== $expects_auto_increment
            ) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
        }
        foreach ($present as $column => $row) {
            if (isset($contract['columns'][$column])) {
                continue;
            }
            $null = strtoupper(trim((string) ($row['Null'] ?? '')));
            $default = array_key_exists('Default', $row) ? $row['Default'] : null;
            $extra = strtolower(trim((string) ($row['Extra'] ?? '')));
            if ($null === 'NO' && $default === null && !str_contains($extra, 'auto_increment')) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
        }

        $wpdb->last_error = '';
        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($index_rows) || (string) $wpdb->last_error !== '') {
            return ll_tools_google_classroom_schedule_schema_drift_repair();
        }
        $indexes = [];
        foreach ($index_rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            $column = (string) ($row['Column_name'] ?? '');
            if ($name === '' || $sequence <= 0 || $column === '') {
                continue;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'columns' => [],
                    'full_columns' => true,
                ];
            } elseif ($indexes[$name]['unique'] !== ((int) ($row['Non_unique'] ?? 1) === 0)) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
            if (isset($indexes[$name]['columns'][$sequence])) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
            $indexes[$name]['columns'][$sequence] = $column;
            if (!empty($row['Sub_part'])) {
                $indexes[$name]['full_columns'] = false;
            }
        }
        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        foreach ($contract['indexes'] as $name => $requirements) {
            if (
                !isset($indexes[$name])
                || $indexes[$name]['unique'] !== $requirements['unique']
                || $indexes[$name]['columns'] !== $requirements['columns']
                || !$indexes[$name]['full_columns']
            ) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
        }
        foreach ($indexes as $name => $index) {
            if (!isset($contract['indexes'][$name]) && !empty($index['unique'])) {
                return ll_tools_google_classroom_schedule_schema_drift_repair();
            }
        }
    }

    // Credential writes serialize against account deletion on the core user
    // row, so that row must participate in the same transaction. Never alter
    // a WordPress core table here; fail closed and let the host repair it.
    $wpdb->last_error = '';
    $users_status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->users),
        ARRAY_A
    );
    if (
        !is_array($users_status)
        || strcasecmp((string) ($users_status['Engine'] ?? ''), 'InnoDB') !== 0
        || (string) $wpdb->last_error !== ''
    ) {
        return ll_tools_google_classroom_schedule_schema_drift_repair();
    }

    $cached = true;
    return $cached;
}

/** Force a fresh readback for schema-maintenance admission. */
function ll_tools_google_classroom_schema_readback_current(): bool {
    return ll_tools_google_classroom_schema_is_ready(true);
}

/** Repair exact required column semantics at an admitted maintenance boundary. */
function ll_tools_google_classroom_repair_required_columns(array $tables): bool {
    global $wpdb;

    $contracts = ll_tools_google_classroom_schema_contract();
    foreach ($contracts as $table_key => $contract) {
        $table = (string) ($tables[$table_key] ?? '');
        if ($table === '') {
            return false;
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM {$table}", ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            return false;
        }
        $present = [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $present[$field] = $row;
            }
        }

        foreach ($contract['columns'] as $column => $requirements) {
            if (!isset($present[$column])) {
                // dbDelta is responsible for additive changes. If it could not
                // add a required column, do not invent its position or data.
                return false;
            }
            $row = $present[$column];
            $type = strtolower(trim((string) ($row['Type'] ?? '')));
            $null = strtoupper(trim((string) ($row['Null'] ?? '')));
            $default = array_key_exists('Default', $row) ? $row['Default'] : null;
            $extra = strtolower(trim((string) ($row['Extra'] ?? '')));
            $expects_auto_increment = !empty($requirements['auto_increment']);
            $has_auto_increment = str_contains($extra, 'auto_increment');
            $matches = preg_match((string) $requirements['type'], $type) === 1
                && $null === (string) $requirements['null']
                && $default === $requirements['default']
                && $has_auto_increment === $expects_auto_increment;
            if ($matches) {
                continue;
            }
            if (
                preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1
                || !is_string($requirements['definition'] ?? null)
                || $requirements['definition'] === ''
            ) {
                return false;
            }
            $wpdb->last_error = '';
            $alter = "ALTER TABLE {$table} MODIFY COLUMN `{$column}` {$requirements['definition']}";
            if ($wpdb->query($alter) === false || (string) $wpdb->last_error !== '') {
                return false;
            }
        }
    }

    return true;
}

/** Repair only required index definitions at an admitted maintenance boundary. */
function ll_tools_google_classroom_repair_required_indexes(array $tables): bool {
    global $wpdb;

    $contracts = ll_tools_google_classroom_schema_contract();
    foreach ($contracts as $table_key => $contract) {
        $table = (string) ($tables[$table_key] ?? '');
        if ($table === '') {
            return false;
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            return false;
        }
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'columns' => [],
                    'full_columns' => true,
                ];
            }
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            $column = (string) ($row['Column_name'] ?? '');
            if ($sequence > 0 && $column !== '') {
                $indexes[$name]['columns'][$sequence] = $column;
            }
            if (!empty($row['Sub_part'])) {
                $indexes[$name]['full_columns'] = false;
            }
        }
        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        foreach ($contract['indexes'] as $name => $requirements) {
            $matches = isset($indexes[$name])
                && $indexes[$name]['unique'] === $requirements['unique']
                && $indexes[$name]['columns'] === $requirements['columns']
                && $indexes[$name]['full_columns'];
            if ($matches) {
                continue;
            }
            if (
                preg_match('/^(?:PRIMARY|[A-Za-z0-9_]{1,64})$/D', $name) !== 1
                || array_filter(
                    $requirements['columns'],
                    static fn($column): bool => !is_string($column)
                        || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1
                ) !== []
            ) {
                return false;
            }
            $columns_sql = implode(', ', array_map(
                static fn(string $column): string => '`' . $column . '`',
                $requirements['columns']
            ));
            if ($name === 'PRIMARY') {
                $alter = isset($indexes['PRIMARY'])
                    ? "ALTER TABLE {$table} DROP PRIMARY KEY, ADD PRIMARY KEY ({$columns_sql})"
                    : "ALTER TABLE {$table} ADD PRIMARY KEY ({$columns_sql})";
            } else {
                $drop = isset($indexes[$name]) ? "DROP INDEX `{$name}`, " : '';
                $kind = $requirements['unique'] ? 'UNIQUE KEY' : 'KEY';
                $alter = "ALTER TABLE {$table} {$drop}ADD {$kind} `{$name}` ({$columns_sql})";
            }
            $wpdb->last_error = '';
            if ($wpdb->query($alter) === false || (string) $wpdb->last_error !== '') {
                return false;
            }
        }
    }
    return true;
}

/**
 * Remove a same-named non-primary index whose semantics no longer match.
 *
 * dbDelta otherwise attempts to add the required index before the explicit
 * repair pass and MySQL rejects it as a duplicate name. Missing tables and
 * missing indexes are left for dbDelta; primary-key repair remains in the
 * admitted post-dbDelta pass.
 */
function ll_tools_google_classroom_drop_conflicting_named_indexes(array $tables): bool {
    global $wpdb;

    foreach (ll_tools_google_classroom_schema_contract() as $table_key => $contract) {
        $table = (string) ($tables[$table_key] ?? '');
        if ($table === '') {
            return false;
        }
        $wpdb->last_error = '';
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table),
            ARRAY_A
        );
        if ((string) $wpdb->last_error !== '') {
            return false;
        }
        if (!is_array($status)) {
            continue;
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            return false;
        }
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            $column = (string) ($row['Column_name'] ?? '');
            if ($name === '' || $sequence <= 0 || $column === '') {
                continue;
            }
            $indexes[$name]['unique'] = (int) ($row['Non_unique'] ?? 1) === 0;
            $indexes[$name]['columns'][$sequence] = $column;
            if (!empty($row['Sub_part'])) {
                $indexes[$name]['full_columns'] = false;
            } elseif (!isset($indexes[$name]['full_columns'])) {
                $indexes[$name]['full_columns'] = true;
            }
        }
        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        foreach ($contract['indexes'] as $name => $requirements) {
            if ($name === 'PRIMARY' || !isset($indexes[$name])) {
                continue;
            }
            $matches = $indexes[$name]['unique'] === $requirements['unique']
                && $indexes[$name]['columns'] === $requirements['columns']
                && !empty($indexes[$name]['full_columns']);
            if ($matches) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
                return false;
            }
            $wpdb->last_error = '';
            if ($wpdb->query("ALTER TABLE {$table} DROP INDEX `{$name}`") === false || (string) $wpdb->last_error !== '') {
                return false;
            }
        }
    }
    return true;
}

function ll_tools_install_google_classroom_schema(): bool {
    global $wpdb;

    $tables = ll_tools_google_classroom_table_names();
    $charset_collate = $wpdb->get_charset_collate();
    delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $connections_sql = "CREATE TABLE {$tables['connections']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        teacher_user_id bigint(20) unsigned NOT NULL,
        google_sub_hash char(64) NOT NULL,
        credential_envelope longtext NOT NULL,
        scopes_json text NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'connected',
        token_version bigint(20) unsigned NOT NULL DEFAULT 1,
        connected_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        disconnected_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_teacher_sub (teacher_user_id, google_sub_hash),
        KEY idx_teacher_status (teacher_user_id, status, updated_at)
    ) ENGINE=InnoDB {$charset_collate};";

    $states_sql = "CREATE TABLE {$tables['oauth_states']} (
        state_hash char(64) NOT NULL,
        teacher_user_id bigint(20) unsigned NOT NULL,
        pkce_envelope text NOT NULL,
        callback_hash char(64) NOT NULL,
        expires_at datetime NOT NULL,
        consumed_at datetime NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (state_hash),
        KEY idx_teacher_expires (teacher_user_id, expires_at),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB {$charset_collate};";

    if (!ll_tools_google_classroom_drop_conflicting_named_indexes($tables)) {
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION);
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
        return false;
    }

    dbDelta($connections_sql);
    dbDelta($states_sql);

    // dbDelta does not reliably change an existing table's storage engine.
    // This installer runs only behind activation/schema-maintenance admission,
    // where an explicit repair to the required transactional engine is safe.
    foreach ($tables as $table) {
        $wpdb->last_error = '';
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table),
            ARRAY_A
        );
        if (!is_array($status) || (string) $wpdb->last_error !== '') {
            delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION);
            delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
            return false;
        }
        if (strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            $wpdb->last_error = '';
            if ($wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB") === false || (string) $wpdb->last_error !== '') {
                delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION);
                delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
                return false;
            }
        }
    }
    // Establish indexes before modifying AUTO_INCREMENT semantics, then
    // re-read/repair them after column normalization.
    if (
        !ll_tools_google_classroom_repair_required_indexes($tables)
        || !ll_tools_google_classroom_repair_required_columns($tables)
        || !ll_tools_google_classroom_repair_required_indexes($tables)
    ) {
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION);
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
        return false;
    }

    update_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION, LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION, false);
    update_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION, LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION, false);
    if (!ll_tools_google_classroom_schema_is_ready(true)) {
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERSION_OPTION);
        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
        return false;
    }

    return true;
}

function ll_tools_maybe_upgrade_google_classroom_schema(): bool {
    $markers_current = ll_tools_google_classroom_schema_markers_current();
    if ($markers_current && !ll_tools_google_classroom_init_full_readback_is_allowed()) {
        return true;
    }

    if ($markers_current && ll_tools_google_classroom_schema_is_ready(true)) {
        return true;
    }

    if (function_exists('ll_tools_maybe_run_schema_maintenance')) {
        return ll_tools_maybe_run_schema_maintenance(
            'google_classroom',
            $markers_current
                ? 'll_tools_google_classroom_schema_readback_current'
                : 'll_tools_google_classroom_schema_markers_current',
            'll_tools_install_google_classroom_schema'
        );
    }

    return false;
}
add_action('init', 'll_tools_maybe_upgrade_google_classroom_schema', 12);

function ll_tools_google_classroom_client_id(): string {
    $value = defined('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_ID')
        ? constant('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_ID')
        : '';
    $value = apply_filters('ll_tools_google_classroom_client_id', $value);
    return is_string($value) ? trim($value) : '';
}

function ll_tools_google_classroom_client_secret(): string {
    $value = defined('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_SECRET')
        ? constant('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_SECRET')
        : '';
    $value = apply_filters('ll_tools_google_classroom_client_secret', $value);
    return is_string($value) ? trim($value) : '';
}

function ll_tools_google_classroom_callback_url(): string {
    $url = admin_url('admin-post.php?action=ll_tools_google_classroom_oauth_callback');
    $url = apply_filters('ll_tools_google_classroom_callback_url', $url);
    return is_string($url) ? $url : '';
}

function ll_tools_google_classroom_required_scopes(): array {
    return [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/classroom.courses.readonly',
    ];
}

/** Scopes requested only in the future mapped grade-publication step-up. */
function ll_tools_google_classroom_write_scopes(): array {
    return [
        'https://www.googleapis.com/auth/classroom.coursework.students',
    ];
}

function ll_tools_google_classroom_normalize_scopes($scopes): array {
    if (is_string($scopes)) {
        $scopes = preg_split('/\s+/', trim($scopes));
    }
    if (!is_array($scopes)) {
        return [];
    }

    $normalized = [];
    foreach ($scopes as $scope) {
        if (!is_string($scope)) {
            continue;
        }
        $scope = trim($scope);
        if ($scope === '' || strlen($scope) > 255 || preg_match('/[\x00-\x20]/', $scope)) {
            continue;
        }
        $normalized[$scope] = true;
    }
    $normalized = array_keys($normalized);
    sort($normalized, SORT_STRING);
    return $normalized;
}

function ll_tools_google_classroom_missing_scopes($scopes): array {
    $granted = array_fill_keys(ll_tools_google_classroom_normalize_scopes($scopes), true);
    return array_values(array_filter(
        ll_tools_google_classroom_required_scopes(),
        static fn(string $scope): bool => !isset($granted[$scope])
    ));
}

function ll_tools_google_classroom_missing_write_scopes($scopes): array {
    $granted = array_fill_keys(ll_tools_google_classroom_normalize_scopes($scopes), true);
    return array_values(array_filter(
        ll_tools_google_classroom_write_scopes(),
        static fn(string $scope): bool => !isset($granted[$scope])
    ));
}

/**
 * Return configuration diagnostics without ever returning the client secret or key.
 */
function ll_tools_google_classroom_config_status(): array {
    $errors = [];
    $client_id = ll_tools_google_classroom_client_id();
    $client_secret = ll_tools_google_classroom_client_secret();
    $callback_url = ll_tools_google_classroom_callback_url();
    $callback = wp_parse_url($callback_url);
    $expected_callback = wp_parse_url(admin_url('admin-post.php'));
    $callback_query = [];
    if (is_array($callback) && isset($callback['query']) && is_string($callback['query'])) {
        parse_str($callback['query'], $callback_query);
    }

    if ($client_id === '' || strlen($client_id) > 2048) {
        $errors[] = 'client_id_missing';
    }
    if ($client_secret === '' || strlen($client_secret) > 4096) {
        $errors[] = 'client_secret_missing';
    }
    if (!ll_tools_lms_credential_store_is_available()) {
        $errors[] = 'credential_store_unavailable';
    }
    if (
        !is_array($callback)
        || !is_array($expected_callback)
        || empty($callback['host'])
        || empty($callback['path'])
        || strcasecmp((string) $callback['host'], (string) ($expected_callback['host'] ?? '')) !== 0
        || (int) ($callback['port'] ?? 0) !== (int) ($expected_callback['port'] ?? 0)
        || (string) $callback['path'] !== (string) ($expected_callback['path'] ?? '')
        || isset($callback['fragment'])
        || $callback_query !== ['action' => 'll_tools_google_classroom_oauth_callback']
    ) {
        $errors[] = 'callback_invalid';
    } elseif (
        strtolower((string) ($callback['scheme'] ?? '')) !== 'https'
        && !(bool) apply_filters('ll_tools_google_classroom_allow_insecure_callback', false, $callback_url)
    ) {
        $errors[] = 'callback_not_https';
    }

    return [
        'ready' => $errors === [],
        'errors' => $errors,
        'callback_url' => $callback_url,
        'client_id_configured' => $client_id !== '',
        'client_secret_configured' => $client_secret !== '',
        'credential_store_configured' => ll_tools_lms_credential_store_is_available(),
    ];
}

function ll_tools_google_classroom_is_configured(): bool {
    $status = ll_tools_google_classroom_config_status();
    return !empty($status['ready']);
}

/**
 * Enabling this gate is an assertion that grades come from finalized,
 * server-authoritative assignment attempts. It must remain false for Phase 1.
 */
function ll_tools_google_classroom_writes_ready(): bool {
    $ready = defined('LL_TOOLS_GOOGLE_CLASSROOM_WRITES_READY')
        && constant('LL_TOOLS_GOOGLE_CLASSROOM_WRITES_READY') === true;
    $ready = (bool) apply_filters('ll_tools_google_classroom_writes_ready', $ready);

    $adapter_ready = function_exists('ll_tools_grade_delivery_get_adapter')
        && ll_tools_grade_delivery_get_adapter('google_classroom') !== null
        && (bool) apply_filters('ll_tools_google_classroom_write_adapter_ready', false);

    return $ready
        && $adapter_ready
        && ll_tools_google_classroom_is_configured()
        && ll_tools_google_classroom_schema_is_ready();
}

function ll_tools_google_classroom_allowed_hosts(): array {
    return [
        'accounts.google.com',
        'oauth2.googleapis.com',
        'openidconnect.googleapis.com',
        'classroom.googleapis.com',
    ];
}

function ll_tools_google_classroom_validate_fixed_url(string $url): bool {
    $parts = wp_parse_url($url);
    return is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && in_array(strtolower((string) ($parts['host'] ?? '')), ll_tools_google_classroom_allowed_hosts(), true)
        && (!isset($parts['port']) || (int) $parts['port'] === 443)
        && empty($parts['user'])
        && empty($parts['pass']);
}

/**
 * Fixed-origin, bounded JSON request with deliberately redacted errors.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_http_json(string $method, string $url, array $args = []) {
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PATCH'], true) || !ll_tools_google_classroom_validate_fixed_url($url)) {
        return new WP_Error(
            'll_tools_google_classroom_url_rejected',
            __('The Google Classroom request target was rejected.', 'll-tools-text-domain')
        );
    }

    $request_args = array_merge([
        'method' => $method,
        'timeout' => max(2, min(20, (int) LL_TOOLS_GOOGLE_CLASSROOM_HTTP_TIMEOUT)),
        'redirection' => 0,
        'sslverify' => true,
        'reject_unsafe_urls' => true,
        'limit_response_size' => max(32768, min(1048576, (int) LL_TOOLS_GOOGLE_CLASSROOM_HTTP_BODY_LIMIT)),
        'headers' => ['Accept' => 'application/json'],
    ], $args);
    $request_args['method'] = $method;
    $request_args['timeout'] = max(2, min(20, (int) ($request_args['timeout'] ?? LL_TOOLS_GOOGLE_CLASSROOM_HTTP_TIMEOUT)));
    $request_args['limit_response_size'] = max(
        32768,
        min(1048576, (int) ($request_args['limit_response_size'] ?? LL_TOOLS_GOOGLE_CLASSROOM_HTTP_BODY_LIMIT))
    );
    $request_args['redirection'] = 0;
    $request_args['sslverify'] = true;
    $request_args['reject_unsafe_urls'] = true;

    $response = wp_safe_remote_request($url, $request_args);
    if (is_wp_error($response)) {
        return new WP_Error(
            'll_tools_google_classroom_network_error',
            __('Google Classroom is temporarily unavailable.', 'll-tools-text-domain'),
            ['retryable' => true]
        );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = $body === '' ? [] : json_decode($body, true);
    $json_valid = $body === '' || (is_array($decoded) && json_last_error() === JSON_ERROR_NONE);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status < 200 || $status >= 300) {
        $google_status = '';
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            $google_status = sanitize_key($decoded['error']);
        } elseif (isset($decoded['error']['status']) && is_string($decoded['error']['status'])) {
            $google_status = sanitize_key(strtolower($decoded['error']['status']));
        }
        $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
        $retryable = $status === 429 || $status >= 500;

        return new WP_Error(
            'll_tools_google_classroom_http_error',
            __('Google Classroom rejected the request.', 'll-tools-text-domain'),
            [
                'http_status' => $status,
                'google_status' => $google_status,
                'retryable' => $retryable,
                'retry_after' => max(0, min(DAY_IN_SECONDS, $retry_after)),
            ]
        );
    }

    if (!$json_valid) {
        return new WP_Error(
            'll_tools_google_classroom_response_invalid',
            __('Google Classroom returned an invalid response.', 'll-tools-text-domain'),
            ['retryable' => true]
        );
    }

    return $decoded;
}

function ll_tools_google_classroom_external_id($value, int $max_length = 256): string {
    if (!is_string($value) && !is_int($value)) {
        return '';
    }
    $value = trim((string) $value);
    if (
        $value === ''
        || strlen($value) > $max_length
        || preg_match('/[\x00-\x20\x7f]/', $value)
    ) {
        return '';
    }
    return $value;
}

function ll_tools_google_classroom_profile($profile): array {
    if (!is_array($profile)) {
        return [];
    }
    $sub = ll_tools_google_classroom_external_id($profile['sub'] ?? '', 255);
    if ($sub === '') {
        return [];
    }

    return [
        'sub' => $sub,
        'email' => isset($profile['email']) && is_string($profile['email'])
            ? sanitize_email($profile['email'])
            : '',
        'email_verified' => !empty($profile['email_verified']),
        'name' => isset($profile['name']) && is_string($profile['name'])
            ? sanitize_text_field(substr($profile['name'], 0, 255))
            : '',
        'hd' => isset($profile['hd']) && is_string($profile['hd'])
            ? sanitize_text_field(substr($profile['hd'], 0, 255))
            : '',
    ];
}

function ll_tools_google_classroom_sub_hash(string $sub) {
    $sub = ll_tools_google_classroom_external_id($sub, 255);
    $master_key = ll_tools_lms_credential_master_key();
    if ($sub === '' || is_wp_error($master_key)) {
        return is_wp_error($master_key)
            ? $master_key
            : new WP_Error('ll_tools_google_classroom_sub_invalid', __('The Google account identifier is invalid.', 'll-tools-text-domain'));
    }

    return hash_hmac('sha256', 'google-classroom-sub|' . $sub, $master_key);
}

function ll_tools_google_classroom_connection_context(int $teacher_user_id, string $sub_hash): string {
    return 'google-classroom-connection|site:' . get_current_blog_id()
        . '|teacher:' . $teacher_user_id
        . '|sub:' . $sub_hash;
}

function ll_tools_google_classroom_state_context(string $state_hash, int $teacher_user_id): string {
    return 'google-classroom-oauth-state|site:' . get_current_blog_id()
        . '|teacher:' . $teacher_user_id
        . '|state:' . $state_hash;
}

function ll_tools_google_classroom_schedule_oauth_state_cleanup(): bool {
    if (!ll_tools_google_classroom_schema_markers_current()) {
        return false;
    }
    if (wp_next_scheduled(LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK) !== false) {
        return true;
    }
    return wp_schedule_event(
        time() + HOUR_IN_SECONDS,
        'hourly',
        LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK
    ) !== false;
}

/** Delete at most 100 expired state envelopes and continue if needed. */
function ll_tools_google_classroom_cleanup_oauth_states(string $context = ''): int {
    global $wpdb;

    if (!ll_tools_google_classroom_schema_is_ready()) {
        return 0;
    }
    $table = ll_tools_google_classroom_table_names()['oauth_states'];
    $cutoff = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
    $wpdb->last_error = '';
    $hashes = $wpdb->get_col($wpdb->prepare(
        "SELECT state_hash FROM {$table} WHERE expires_at < %s ORDER BY expires_at ASC, state_hash ASC LIMIT 101",
        $cutoff
    ));
    if (!is_array($hashes) || (string) $wpdb->last_error !== '') {
        return 0;
    }
    $has_more = count($hashes) > 100;
    $hashes = array_slice(array_values(array_filter($hashes, static function ($hash): bool {
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    })), 0, 100);
    if ($hashes === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
    $query = "DELETE FROM {$table} WHERE state_hash IN ({$placeholders}) AND expires_at < %s";
    $deleted = $wpdb->query($wpdb->prepare($query, ...array_merge($hashes, [$cutoff])));
    if ($deleted === false) {
        return 0;
    }
    if (
        $has_more
        && wp_next_scheduled(LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK, ['continuation']) === false
    ) {
        wp_schedule_single_event(
            time() + MINUTE_IN_SECONDS,
            LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK,
            ['continuation']
        );
    }
    return (int) $deleted;
}
add_action(LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK, 'll_tools_google_classroom_cleanup_oauth_states', 10, 1);
add_action('init', 'll_tools_google_classroom_schedule_oauth_state_cleanup', 14);

/**
 * Create a one-time OAuth state and PKCE verifier record.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_create_oauth_state(int $teacher_user_id) {
    global $wpdb;

    if (
        $teacher_user_id <= 0
        || (
            function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            && ll_tools_privacy_user_lms_deletion_is_pending($teacher_user_id)
        )
        || !ll_tools_google_classroom_is_configured()
        || !ll_tools_google_classroom_schema_is_ready()
    ) {
        return new WP_Error(
            'll_tools_google_classroom_not_ready',
            __('Google Classroom is not configured.', 'll-tools-text-domain')
        );
    }

    try {
        $state = ll_tools_lms_base64url_encode(random_bytes(32));
        $verifier = ll_tools_lms_base64url_encode(random_bytes(64));
    } catch (Throwable $error) {
        return new WP_Error(
            'll_tools_google_classroom_random_failed',
            __('Google Classroom authorization could not be started.', 'll-tools-text-domain')
        );
    }
    $state_hash = hash('sha256', $state);
    $callback_url = ll_tools_google_classroom_callback_url();
    $callback_hash = hash('sha256', $callback_url);
    $sealed = ll_tools_lms_seal_secret(
        wp_json_encode(['code_verifier' => $verifier, 'callback_url' => $callback_url], JSON_UNESCAPED_SLASHES),
        ll_tools_google_classroom_state_context($state_hash, $teacher_user_id)
    );
    if (is_wp_error($sealed)) {
        return $sealed;
    }

    $ttl = (int) apply_filters(
        'll_tools_google_classroom_oauth_state_ttl',
        LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_STATE_TTL
    );
    $ttl = max(2 * MINUTE_IN_SECONDS, min(15 * MINUTE_IN_SECONDS, $ttl));
    $now = time();
    $table = ll_tools_google_classroom_table_names()['oauth_states'];

    // Keep request-path cleanup bounded; scheduled retention also runs hourly.
    ll_tools_google_classroom_cleanup_oauth_states('oauth_start');

    $transaction = ll_tools_google_classroom_begin_transaction();
    if ($transaction === null) {
        return new WP_Error(
            'll_tools_google_classroom_state_store_failed',
            __('Google Classroom authorization could not be started.', 'll-tools-text-domain')
        );
    }
    try {
        $wpdb->last_error = '';
        $locked_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            $teacher_user_id
        ));
        if (
            $locked_user_id !== $teacher_user_id
            || (string) $wpdb->last_error !== ''
            || (
                function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
                && ll_tools_privacy_user_lms_deletion_is_pending($teacher_user_id)
            )
        ) {
            throw new RuntimeException('google_classroom_teacher_unavailable');
        }

        // The core user-row lock serializes every authorization generation for
        // this teacher, so a reusable admin nonce cannot race past the hard
        // live-state ceiling. A consumed generation continues to count until
        // connection storage deletes it, preventing failed callbacks from
        // accumulating around an unconsumed-only limit.
        $now_mysql = gmdate('Y-m-d H:i:s', $now);
        $wpdb->last_error = '';
        $pending_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE teacher_user_id = %d AND expires_at >= %s",
            $teacher_user_id,
            $now_mysql
        ));
        if ($pending_count === null || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('google_classroom_state_count_failed');
        }
        if ((int) $pending_count >= ll_tools_google_classroom_pending_oauth_state_limit()) {
            throw new DomainException('google_classroom_pending_state_limit_reached');
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'state_hash' => $state_hash,
                'teacher_user_id' => $teacher_user_id,
                'pkce_envelope' => $sealed,
                'callback_hash' => $callback_hash,
                'expires_at' => gmdate('Y-m-d H:i:s', $now + $ttl),
                'consumed_at' => null,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        if ($inserted !== 1 || !ll_tools_google_classroom_commit_transaction($transaction)) {
            throw new RuntimeException('google_classroom_state_store_failed');
        }
    } catch (DomainException $error) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return new WP_Error(
            'll_tools_google_classroom_pending_state_limit_reached',
            __('Too many Google Classroom authorization attempts are still active. Complete or wait for an existing attempt before trying again.', 'll-tools-text-domain')
        );
    } catch (Throwable $error) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return new WP_Error(
            'll_tools_google_classroom_state_store_failed',
            __('Google Classroom authorization could not be started.', 'll-tools-text-domain')
        );
    }

    return [
        'state' => $state,
        'code_verifier' => $verifier,
        'code_challenge' => ll_tools_lms_base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'expires_at' => $now + $ttl,
    ];
}

function ll_tools_google_classroom_authorization_url(string $state, string $code_challenge, string $login_hint = '') {
    if (
        !ll_tools_google_classroom_is_configured()
        || ll_tools_google_classroom_external_id($state, 256) === ''
        || ll_tools_google_classroom_external_id($code_challenge, 256) === ''
    ) {
        return new WP_Error(
            'll_tools_google_classroom_authorization_invalid',
            __('Google Classroom authorization could not be started.', 'll-tools-text-domain')
        );
    }

    $params = [
        'client_id' => ll_tools_google_classroom_client_id(),
        'redirect_uri' => ll_tools_google_classroom_callback_url(),
        'response_type' => 'code',
        'scope' => implode(' ', ll_tools_google_classroom_required_scopes()),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent',
        'state' => $state,
        'code_challenge' => $code_challenge,
        'code_challenge_method' => 'S256',
    ];
    if ($login_hint !== '' && strlen($login_hint) <= 255 && !preg_match('/[\x00-\x20]/', $login_hint)) {
        $params['login_hint'] = $login_hint;
    }

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Atomically consume OAuth state and return its PKCE verifier.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_consume_oauth_state(string $state, int $teacher_user_id) {
    global $wpdb;

    if ($teacher_user_id <= 0 || strlen($state) > 256 || !ll_tools_google_classroom_schema_is_ready()) {
        return new WP_Error('ll_tools_google_classroom_state_invalid', __('The Google authorization session is invalid or expired.', 'll-tools-text-domain'));
    }
    $state_hash = hash('sha256', $state);
    $table = ll_tools_google_classroom_table_names()['oauth_states'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE state_hash = %s AND teacher_user_id = %d LIMIT 1",
        $state_hash,
        $teacher_user_id
    ), ARRAY_A);
    $now_mysql = gmdate('Y-m-d H:i:s');
    if (
        !is_array($row)
        || !empty($row['consumed_at'])
        || (string) ($row['pkce_envelope'] ?? '') === ''
        || (string) ($row['expires_at'] ?? '') < $now_mysql
        || !hash_equals(hash('sha256', ll_tools_google_classroom_callback_url()), (string) ($row['callback_hash'] ?? ''))
    ) {
        return new WP_Error('ll_tools_google_classroom_state_invalid', __('The Google authorization session is invalid or expired.', 'll-tools-text-domain'));
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET consumed_at = %s, pkce_envelope = ''
         WHERE state_hash = %s AND teacher_user_id = %d
           AND consumed_at IS NULL AND pkce_envelope = %s AND expires_at >= %s",
        $now_mysql,
        $state_hash,
        $teacher_user_id,
        (string) $row['pkce_envelope'],
        $now_mysql
    ));
    if ($updated !== 1) {
        return new WP_Error('ll_tools_google_classroom_state_invalid', __('The Google authorization session is invalid or expired.', 'll-tools-text-domain'));
    }

    $opened = ll_tools_lms_open_secret(
        (string) $row['pkce_envelope'],
        ll_tools_google_classroom_state_context($state_hash, $teacher_user_id)
    );
    if (is_wp_error($opened)) {
        return new WP_Error('ll_tools_google_classroom_state_invalid', __('The Google authorization session is invalid or expired.', 'll-tools-text-domain'));
    }
    $payload = json_decode($opened, true);
    if (
        !is_array($payload)
        || !isset($payload['code_verifier'], $payload['callback_url'])
        || !is_string($payload['code_verifier'])
        || !is_string($payload['callback_url'])
        || !hash_equals(ll_tools_google_classroom_callback_url(), $payload['callback_url'])
    ) {
        return new WP_Error('ll_tools_google_classroom_state_invalid', __('The Google authorization session is invalid or expired.', 'll-tools-text-domain'));
    }

    return [
        'code_verifier' => $payload['code_verifier'],
        'callback_url' => $payload['callback_url'],
        'state_hash' => $state_hash,
    ];
}

/**
 * Validate the short-lived token fields used only for an outbound request.
 */
function ll_tools_google_classroom_token_value($value, int $maximum_length = 8192): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if (
        $value === ''
        || strlen($value) > $maximum_length
        || preg_match('/[\x00-\x20\x7f]/', $value)
    ) {
        return '';
    }
    return $value;
}

/**
 * Normalize and verify a Google token response without retaining an ID token.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_normalize_token_response(array $response, array $fallback_scopes = [], bool $require_refresh_token = false) {
    $access_token = ll_tools_google_classroom_token_value($response['access_token'] ?? '');
    $refresh_token = ll_tools_google_classroom_token_value($response['refresh_token'] ?? '');
    $token_type = isset($response['token_type']) && is_string($response['token_type'])
        ? strtolower(trim($response['token_type']))
        : '';
    $expires_in = isset($response['expires_in']) && is_numeric($response['expires_in'])
        ? (int) $response['expires_in']
        : 0;
    $scopes = array_key_exists('scope', $response)
        ? ll_tools_google_classroom_normalize_scopes($response['scope'])
        : ll_tools_google_classroom_normalize_scopes($fallback_scopes);

    if (
        $access_token === ''
        || ($token_type !== '' && $token_type !== 'bearer')
        || $expires_in < 1
        || $expires_in > 7 * DAY_IN_SECONDS
        || ($require_refresh_token && $refresh_token === '')
        || ll_tools_google_classroom_missing_scopes($scopes) !== []
    ) {
        return new WP_Error(
            'll_tools_google_classroom_token_invalid',
            __('Google did not return a usable authorization grant.', 'll-tools-text-domain')
        );
    }

    return [
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'token_type' => 'Bearer',
        'expires_in' => $expires_in,
        'scopes' => $scopes,
    ];
}

/**
 * Exchange the one-time authorization code using its PKCE verifier.
 *
 * Access tokens and authorization codes are returned to the immediate caller
 * only. This module never stores them in WordPress or includes them in errors.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_exchange_code(string $code, string $code_verifier) {
    if (!ll_tools_google_classroom_is_configured()) {
        return new WP_Error('ll_tools_google_classroom_not_ready', __('Google Classroom is not configured.', 'll-tools-text-domain'));
    }
    $code = ll_tools_google_classroom_token_value($code, 4096);
    $code_verifier = ll_tools_google_classroom_token_value($code_verifier, 128);
    if (
        $code === ''
        || strlen($code_verifier) < 43
        || preg_match('/^[A-Za-z0-9._~-]+$/', $code_verifier) !== 1
    ) {
        return new WP_Error('ll_tools_google_classroom_oauth_input_invalid', __('The Google authorization response is invalid.', 'll-tools-text-domain'));
    }

    $response = ll_tools_google_classroom_http_json(
        'POST',
        'https://oauth2.googleapis.com/token',
        [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'client_id' => ll_tools_google_classroom_client_id(),
                'client_secret' => ll_tools_google_classroom_client_secret(),
                'code' => $code,
                'code_verifier' => $code_verifier,
                'grant_type' => 'authorization_code',
                'redirect_uri' => ll_tools_google_classroom_callback_url(),
            ], '', '&', PHP_QUERY_RFC3986),
        ]
    );
    if (is_wp_error($response)) {
        return $response;
    }

    // A first connection must yield a refresh token and an explicit scope readback.
    if (!array_key_exists('scope', $response)) {
        return new WP_Error('ll_tools_google_classroom_scope_readback_missing', __('Google did not confirm the granted Classroom permissions.', 'll-tools-text-domain'));
    }
    return ll_tools_google_classroom_normalize_token_response($response, [], true);
}

/**
 * Refresh a short-lived access token. The refresh token remains caller-owned.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_refresh_access_token(string $refresh_token, array $stored_scopes) {
    if (!ll_tools_google_classroom_is_configured()) {
        return new WP_Error('ll_tools_google_classroom_not_ready', __('Google Classroom is not configured.', 'll-tools-text-domain'));
    }
    $refresh_token = ll_tools_google_classroom_token_value($refresh_token);
    $stored_scopes = ll_tools_google_classroom_normalize_scopes($stored_scopes);
    if ($refresh_token === '' || ll_tools_google_classroom_missing_scopes($stored_scopes) !== []) {
        return new WP_Error('ll_tools_google_classroom_refresh_invalid', __('The stored Google authorization is incomplete.', 'll-tools-text-domain'));
    }

    $response = ll_tools_google_classroom_http_json(
        'POST',
        'https://oauth2.googleapis.com/token',
        [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'client_id' => ll_tools_google_classroom_client_id(),
                'client_secret' => ll_tools_google_classroom_client_secret(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
            ], '', '&', PHP_QUERY_RFC3986),
        ]
    );
    if (is_wp_error($response)) {
        return $response;
    }

    return ll_tools_google_classroom_normalize_token_response($response, $stored_scopes, false);
}

/**
 * Read the OpenID Connect user profile used to bind a connection.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_fetch_userinfo(string $access_token) {
    $access_token = ll_tools_google_classroom_token_value($access_token);
    if ($access_token === '') {
        return new WP_Error('ll_tools_google_classroom_access_token_invalid', __('The Google access token is invalid.', 'll-tools-text-domain'));
    }

    $response = ll_tools_google_classroom_http_json(
        'GET',
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $access_token]]
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $profile = ll_tools_google_classroom_profile($response);
    if ($profile === [] || empty($profile['email_verified'])) {
        return new WP_Error('ll_tools_google_classroom_profile_invalid', __('Google did not return a verified account profile.', 'll-tools-text-domain'));
    }
    return $profile;
}

/**
 * Persist only the refresh token and bounded verified profile in an AEAD envelope.
 *
 * @return array|WP_Error Safe connection summary (never credentials).
 */
function ll_tools_google_classroom_store_connection(
    int $teacher_user_id,
    string $refresh_token,
    array $profile,
    array $scopes,
    string $consumed_state_hash
) {
    global $wpdb;

    $profile = ll_tools_google_classroom_profile($profile);
    $refresh_token = ll_tools_google_classroom_token_value($refresh_token);
    $scopes = ll_tools_google_classroom_normalize_scopes($scopes);
    $consumed_state_hash = strtolower(trim($consumed_state_hash));
    if (
        $teacher_user_id <= 0
        || !(get_userdata($teacher_user_id) instanceof WP_User)
        || (
            function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            && ll_tools_privacy_user_lms_deletion_is_pending($teacher_user_id)
        )
        || $refresh_token === ''
        || $profile === []
        || empty($profile['email_verified'])
        || preg_match('/^[a-f0-9]{64}$/D', $consumed_state_hash) !== 1
        || ll_tools_google_classroom_missing_scopes($scopes) !== []
        || !ll_tools_google_classroom_schema_is_ready()
    ) {
        return new WP_Error('ll_tools_google_classroom_connection_invalid', __('The Google Classroom connection could not be saved.', 'll-tools-text-domain'));
    }

    $sub_hash = ll_tools_google_classroom_sub_hash($profile['sub']);
    if (is_wp_error($sub_hash)) {
        return $sub_hash;
    }
    $plaintext = wp_json_encode([
        'schema' => 1,
        'refresh_token' => $refresh_token,
        'profile' => $profile,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($plaintext)) {
        return new WP_Error('ll_tools_google_classroom_connection_invalid', __('The Google Classroom connection could not be saved.', 'll-tools-text-domain'));
    }
    $envelope = ll_tools_lms_seal_secret(
        $plaintext,
        ll_tools_google_classroom_connection_context($teacher_user_id, $sub_hash)
    );
    if (is_wp_error($envelope)) {
        return $envelope;
    }
    $scopes_json = wp_json_encode($scopes, JSON_UNESCAPED_SLASHES);
    if (!is_string($scopes_json)) {
        return new WP_Error('ll_tools_google_classroom_connection_invalid', __('The Google Classroom connection could not be saved.', 'll-tools-text-domain'));
    }

    $tables = ll_tools_google_classroom_table_names();
    $table = $tables['connections'];
    $transaction = ll_tools_google_classroom_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('ll_tools_google_classroom_connection_store_failed', __('The Google Classroom connection could not be saved.', 'll-tools-text-domain'));
    }
    try {
        $wpdb->last_error = '';
        $locked_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            $teacher_user_id
        ));
        if (
            $locked_user_id !== $teacher_user_id
            || (string) $wpdb->last_error !== ''
            || (
                function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
                && ll_tools_privacy_user_lms_deletion_is_pending($teacher_user_id)
            )
        ) {
            throw new RuntimeException('google_classroom_teacher_unavailable');
        }

        $state_row = $wpdb->get_row($wpdb->prepare(
            "SELECT state_hash FROM {$tables['oauth_states']}
             WHERE state_hash = %s AND teacher_user_id = %d
               AND consumed_at IS NOT NULL AND pkce_envelope = ''
             LIMIT 1 FOR UPDATE",
            $consumed_state_hash,
            $teacher_user_id
        ), ARRAY_A);
        if (!is_array($state_row)) {
            throw new RuntimeException('google_classroom_oauth_generation_stale');
        }

        $wpdb->last_error = '';
        $existing_value = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE teacher_user_id = %d AND google_sub_hash = %s LIMIT 1",
            $teacher_user_id,
            $sub_hash
        ));
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('google_classroom_connection_count_failed');
        }
        $existing_id = (int) $existing_value;
        if ($existing_id <= 0) {
            // The same user-row lock serializes distinct-subject inserts. An
            // exact existing subject may rotate its token while at the cap,
            // but a new account cannot bypass the UI ceiling by posting the
            // OAuth action directly or racing another callback.
            $wpdb->last_error = '';
            $connection_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE teacher_user_id = %d",
                $teacher_user_id
            ));
            if ($connection_count === null || (string) $wpdb->last_error !== '') {
                throw new RuntimeException('google_classroom_connection_count_failed');
            }
            if ((int) $connection_count >= ll_tools_google_classroom_connection_limit()) {
                throw new DomainException('google_classroom_connection_limit_reached');
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($existing_id > 0) {
            $stored = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET credential_envelope = %s, scopes_json = %s, status = 'connected',
                     token_version = token_version + 1, connected_at = %s,
                     updated_at = %s, disconnected_at = NULL
                 WHERE id = %d AND teacher_user_id = %d AND google_sub_hash = %s",
                $envelope,
                $scopes_json,
                $now,
                $now,
                $existing_id,
                $teacher_user_id,
                $sub_hash
            ));
            $connection_id = $existing_id;
        } else {
            $stored = $wpdb->insert(
                $table,
                [
                    'teacher_user_id' => $teacher_user_id,
                    'google_sub_hash' => $sub_hash,
                    'credential_envelope' => $envelope,
                    'scopes_json' => $scopes_json,
                    'status' => 'connected',
                    'token_version' => 1,
                    'connected_at' => $now,
                    'updated_at' => $now,
                    'disconnected_at' => null,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );
            $connection_id = (int) $wpdb->insert_id;
        }

        if (
            $stored === false
            || $connection_id <= 0
        ) {
            throw new RuntimeException('google_classroom_connection_store_failed');
        }
        $state_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tables['oauth_states']}
             WHERE state_hash = %s AND teacher_user_id = %d
               AND consumed_at IS NOT NULL AND pkce_envelope = ''",
            $consumed_state_hash,
            $teacher_user_id
        ));
        if ($state_deleted !== 1 || !ll_tools_google_classroom_commit_transaction($transaction)) {
            throw new RuntimeException('google_classroom_connection_store_failed');
        }
    } catch (DomainException $error) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return new WP_Error(
            'll_tools_google_classroom_connection_limit_reached',
            __('This teacher has reached the Google Classroom account limit.', 'll-tools-text-domain')
        );
    } catch (Throwable $error) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return new WP_Error('ll_tools_google_classroom_connection_store_failed', __('The Google Classroom connection could not be saved.', 'll-tools-text-domain'));
    }
    return ll_tools_google_classroom_get_connection_summary($connection_id, $teacher_user_id);
}

/**
 * Internal credential loader. Callers must not log or persist the result.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_load_connection_credentials(int $connection_id, int $teacher_user_id) {
    global $wpdb;

    if (
        $connection_id <= 0
        || $teacher_user_id <= 0
        || !(get_userdata($teacher_user_id) instanceof WP_User)
        || (
            function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            && ll_tools_privacy_user_lms_deletion_is_pending($teacher_user_id)
        )
        || !ll_tools_google_classroom_schema_is_ready()
    ) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }
    $table = ll_tools_google_classroom_table_names()['connections'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND teacher_user_id = %d AND status = 'connected' LIMIT 1",
        $connection_id,
        $teacher_user_id
    ), ARRAY_A);
    if (!is_array($row)) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }

    $opened = ll_tools_lms_open_secret(
        (string) $row['credential_envelope'],
        ll_tools_google_classroom_connection_context($teacher_user_id, (string) $row['google_sub_hash'])
    );
    if (is_wp_error($opened)) {
        return $opened;
    }
    $payload = json_decode($opened, true);
    $profile = ll_tools_google_classroom_profile(is_array($payload) ? ($payload['profile'] ?? null) : null);
    $refresh_token = ll_tools_google_classroom_token_value(is_array($payload) ? ($payload['refresh_token'] ?? '') : '');
    $scopes = ll_tools_google_classroom_normalize_scopes(json_decode((string) $row['scopes_json'], true));
    if (
        !is_array($payload)
        || (int) ($payload['schema'] ?? 0) !== 1
        || $profile === []
        || empty($profile['email_verified'])
        || $refresh_token === ''
        || ll_tools_google_classroom_missing_scopes($scopes) !== []
    ) {
        return new WP_Error('ll_tools_google_classroom_connection_invalid', __('The stored Google Classroom connection is invalid.', 'll-tools-text-domain'));
    }
    $verified_hash = ll_tools_google_classroom_sub_hash($profile['sub']);
    if (is_wp_error($verified_hash) || !hash_equals((string) $row['google_sub_hash'], $verified_hash)) {
        return new WP_Error('ll_tools_google_classroom_connection_invalid', __('The stored Google Classroom connection is invalid.', 'll-tools-text-domain'));
    }

    return [
        'id' => (int) $row['id'],
        'teacher_user_id' => (int) $row['teacher_user_id'],
        'refresh_token' => $refresh_token,
        'profile' => $profile,
        'scopes' => $scopes,
        'token_version' => (int) $row['token_version'],
    ];
}

/**
 * Decrypt the bounded profile for a privacy export of one owned connection.
 *
 * The output contract intentionally excludes the refresh token, any access
 * token, the raw Google subject, and the stored subject hash.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_privacy_profile_for_connection(
    int $connection_id,
    int $teacher_user_id
) {
    $connection = ll_tools_google_classroom_load_connection_credentials($connection_id, $teacher_user_id);
    if (is_wp_error($connection)) {
        return $connection;
    }
    $profile = (array) ($connection['profile'] ?? []);
    return [
        'email' => (string) ($profile['email'] ?? ''),
        'name' => (string) ($profile['name'] ?? ''),
        'hd' => (string) ($profile['hd'] ?? ''),
        'email_verified' => !empty($profile['email_verified']),
    ];
}

/**
 * Return one privacy-safe connection summary. No encrypted envelope, token,
 * raw Google subject, or OAuth transient is returned.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_get_connection_summary(int $connection_id, int $teacher_user_id = 0) {
    global $wpdb;

    if ($connection_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }
    $table = ll_tools_google_classroom_table_names()['connections'];
    $sql = "SELECT id, teacher_user_id, scopes_json, status, connected_at, updated_at, disconnected_at
            FROM {$table} WHERE id = %d";
    $args = [$connection_id];
    if ($teacher_user_id > 0) {
        $sql .= ' AND teacher_user_id = %d';
        $args[] = $teacher_user_id;
    }
    $sql .= ' LIMIT 1';
    $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
    if (!is_array($row)) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }

    return [
        'id' => (int) $row['id'],
        'teacher_user_id' => (int) $row['teacher_user_id'],
        'provider' => 'google_classroom',
        'status' => sanitize_key((string) $row['status']),
        'scopes' => ll_tools_google_classroom_normalize_scopes(json_decode((string) $row['scopes_json'], true)),
        'connected_at' => (string) $row['connected_at'],
        'updated_at' => (string) $row['updated_at'],
        'disconnected_at' => $row['disconnected_at'] === null ? null : (string) $row['disconnected_at'],
    ];
}

/**
 * Privacy-export helper for one WordPress user. Results are bounded and safe.
 */
function ll_tools_google_classroom_connection_summary_for_user(int $user_id): array {
    global $wpdb;

    if ($user_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return [];
    }
    $table = ll_tools_google_classroom_table_names()['connections'];
    $limit = ll_tools_google_classroom_connection_limit();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table} WHERE teacher_user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d",
        $user_id,
        $limit
    ));
    $summaries = [];
    foreach ($ids as $id) {
        $summary = ll_tools_google_classroom_get_connection_summary((int) $id, $user_id);
        if (!is_wp_error($summary)) {
            $summaries[] = $summary;
        }
    }
    return $summaries;
}

/**
 * Return every bounded, exact owned connection or a typed read error.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function ll_tools_google_classroom_owned_connections(int $user_id, int $limit = 20) {
    global $wpdb;

    if ($user_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }
    $limit = max(1, min(ll_tools_google_classroom_connection_limit(), $limit));
    $table = ll_tools_google_classroom_table_names()['connections'];
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE teacher_user_id = %d AND status = 'connected'
         ORDER BY updated_at DESC, id DESC LIMIT %d",
        $user_id,
        $limit
    ));
    if (!is_array($ids) || (string) $wpdb->last_error !== '') {
        return new WP_Error('ll_tools_google_classroom_connection_read_failed', __('Google Classroom connections could not be loaded.', 'll-tools-text-domain'));
    }
    $summaries = [];
    foreach ($ids as $id) {
        $summary = ll_tools_google_classroom_get_connection_summary((int) $id, $user_id);
        if (is_wp_error($summary)) {
            return new WP_Error('ll_tools_google_classroom_connection_read_failed', __('Google Classroom connections could not be loaded.', 'll-tools-text-domain'));
        }
        $summaries[] = $summary;
    }
    return $summaries;
}

/**
 * Return the most recently updated connected account for a teacher.
 *
 * @return array|WP_Error Safe summary only.
 */
function ll_tools_google_classroom_latest_connection_for_user(int $teacher_user_id) {
    global $wpdb;

    if ($teacher_user_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }
    $table = ll_tools_google_classroom_table_names()['connections'];
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE teacher_user_id = %d AND status = 'connected'
         ORDER BY updated_at DESC, id DESC LIMIT 1",
        $teacher_user_id
    ));
    if ($id <= 0) {
        return new WP_Error('ll_tools_google_classroom_connection_not_found', __('The Google Classroom connection was not found.', 'll-tools-text-domain'));
    }
    return ll_tools_google_classroom_get_connection_summary($id, $teacher_user_id);
}

/**
 * Begin a Google Classroom local-data transaction without committing a
 * transaction owned by the caller (including the WordPress test harness).
 *
 * @return array{type:string,name:string}|null
 */
function ll_tools_google_classroom_begin_transaction(): ?array {
    global $wpdb;

    static $sequence = 0;
    $sequence++;
    $name = 'll_google_classroom_' . $sequence . '_' . substr(hash('sha256', microtime(true) . '|' . wp_rand()), 0, 10);
    $previous_suppress_errors = $wpdb->suppress_errors(true);

    $wpdb->last_error = '';
    $savepoint = $wpdb->query("SAVEPOINT {$name}");
    if ($savepoint === false || (string) $wpdb->last_error !== '') {
        $wpdb->suppress_errors($previous_suppress_errors);
        return null;
    }

    $wpdb->last_error = '';
    $verified = $wpdb->query("ROLLBACK TO SAVEPOINT {$name}");
    if ($verified !== false && (string) $wpdb->last_error === '') {
        $wpdb->suppress_errors($previous_suppress_errors);
        return ['type' => 'savepoint', 'name' => $name];
    }
    $rollback_error = strtolower((string) $wpdb->last_error);
    $rollback_errno = function_exists('mysqli_errno') && $wpdb->dbh instanceof mysqli
        ? (int) mysqli_errno($wpdb->dbh)
        : 0;
    $wpdb->query("RELEASE SAVEPOINT {$name}");

    // MySQL accepts SAVEPOINT in autocommit mode but immediately discards it,
    // so ROLLBACK reports ER_SP_DOES_NOT_EXIST (1305). That exact result plus
    // autocommit=1 establishes an idle connection without the MariaDB-only
    // @@in_transaction variable. Fail closed on every ambiguous result.
    if (
        $rollback_errno !== 1305
        && !(str_contains($rollback_error, 'savepoint') && str_contains($rollback_error, 'does not exist'))
    ) {
        $wpdb->suppress_errors($previous_suppress_errors);
        return null;
    }
    $wpdb->last_error = '';
    $autocommit = $wpdb->get_var('SELECT @@session.autocommit');
    if ((string) $wpdb->last_error !== '' || (string) $autocommit !== '1') {
        $wpdb->suppress_errors($previous_suppress_errors);
        return null;
    }

    $wpdb->last_error = '';
    $started = $wpdb->query('START TRANSACTION');
    $error = (string) $wpdb->last_error;
    $wpdb->suppress_errors($previous_suppress_errors);
    if ($started === false || $error !== '') {
        return null;
    }
    return ['type' => 'transaction', 'name' => ''];
}

/** @param array{type:string,name:string} $transaction */
function ll_tools_google_classroom_commit_transaction(array $transaction): bool {
    global $wpdb;

    $wpdb->last_error = '';
    if (($transaction['type'] ?? '') === 'savepoint') {
        $name = (string) ($transaction['name'] ?? '');
        $result = $name !== '' ? $wpdb->query("RELEASE SAVEPOINT {$name}") : false;
    } else {
        $result = $wpdb->query('COMMIT');
    }
    return $result !== false && (string) $wpdb->last_error === '';
}

/** @param array{type:string,name:string} $transaction */
function ll_tools_google_classroom_rollback_transaction(array $transaction): void {
    global $wpdb;

    $previous_suppress_errors = $wpdb->suppress_errors(true);
    if (($transaction['type'] ?? '') === 'savepoint') {
        $name = (string) ($transaction['name'] ?? '');
        if ($name !== '') {
            $wpdb->query("ROLLBACK TO SAVEPOINT {$name}");
            $wpdb->query("RELEASE SAVEPOINT {$name}");
        }
    } else {
        $wpdb->query('ROLLBACK');
    }
    $wpdb->suppress_errors($previous_suppress_errors);
    $wpdb->last_error = '';
}

/**
 * Permanently erase local Google credentials/mappings without any network call.
 * Intended for privacy erasure and guaranteed local disconnect cleanup.
 */
function ll_tools_google_classroom_erase_connection_for_user(int $user_id): bool {
    global $wpdb;

    if ($user_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return false;
    }
    $tables = ll_tools_google_classroom_table_names();
    $transaction = ll_tools_google_classroom_begin_transaction();
    if ($transaction === null) {
        return false;
    }
    $wpdb->last_error = '';
    $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $user_id
    ));
    if ((string) $wpdb->last_error !== '') {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    $states_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$tables['oauth_states']} WHERE teacher_user_id = %d",
        $user_id
    ));
    $connections_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$tables['connections']} WHERE teacher_user_id = %d",
        $user_id
    ));
    if ($states_deleted === false || $connections_deleted === false) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    if (!ll_tools_google_classroom_commit_transaction($transaction)) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    return true;
}

/**
 * Delete one exact owned connection. Unlike privacy erasure, this does not
 * remove a teacher's other provider connections.
 */
function ll_tools_google_classroom_delete_connection(int $connection_id, int $teacher_user_id): bool {
    global $wpdb;

    if ($connection_id <= 0 || $teacher_user_id <= 0 || !ll_tools_google_classroom_schema_is_ready()) {
        return false;
    }
    $tables = ll_tools_google_classroom_table_names();
    $transaction = ll_tools_google_classroom_begin_transaction();
    if ($transaction === null) {
        return false;
    }
    $wpdb->last_error = '';
    $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $teacher_user_id
    ));
    if ((string) $wpdb->last_error !== '') {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    // Pending OAuth state is teacher-bound rather than connection-bound. Clear
    // it when the teacher explicitly disconnects, but preserve other accounts.
    $states_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$tables['oauth_states']} WHERE teacher_user_id = %d",
        $teacher_user_id
    ));
    $connection_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$tables['connections']} WHERE id = %d AND teacher_user_id = %d",
        $connection_id,
        $teacher_user_id
    ));
    if ($states_deleted === false || $connection_deleted !== 1) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    if (!ll_tools_google_classroom_commit_transaction($transaction)) {
        ll_tools_google_classroom_rollback_transaction($transaction);
        return false;
    }
    return true;
}

/**
 * Revoke one refresh token if possible, then always erase all local Google
 * connection material for that teacher. Revocation failure is reported safely.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_disconnect_connection(int $connection_id, int $teacher_user_id) {
    $connection = ll_tools_google_classroom_load_connection_credentials($connection_id, $teacher_user_id);
    $revoked = false;
    $revocation_error = false;
    if (!is_wp_error($connection)) {
        $response = ll_tools_google_classroom_http_json(
            'POST',
            'https://oauth2.googleapis.com/revoke',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query(['token' => $connection['refresh_token']], '', '&', PHP_QUERY_RFC3986),
            ]
        );
        $revoked = !is_wp_error($response);
        $revocation_error = is_wp_error($response);
    }

    $erased = ll_tools_google_classroom_delete_connection($connection_id, $teacher_user_id);
    if (!$erased) {
        return new WP_Error('ll_tools_google_classroom_disconnect_failed', __('The local Google Classroom connection could not be removed.', 'll-tools-text-domain'));
    }
    return [
        'erased' => true,
        'revoked' => $revoked,
        'revocation_failed' => $revocation_error || is_wp_error($connection),
    ];
}

/**
 * Obtain an ephemeral access context for one owned connection.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_access_context(int $connection_id, int $teacher_user_id) {
    $connection = ll_tools_google_classroom_load_connection_credentials($connection_id, $teacher_user_id);
    if (is_wp_error($connection)) {
        return $connection;
    }
    $token = ll_tools_google_classroom_refresh_access_token($connection['refresh_token'], $connection['scopes']);
    if (is_wp_error($token)) {
        return $token;
    }
    return [
        'connection_id' => $connection_id,
        'teacher_user_id' => $teacher_user_id,
        'access_token' => $token['access_token'],
        'scopes' => $token['scopes'],
        'expires_in' => $token['expires_in'],
    ];
}

/**
 * Fixed-origin Classroom API request with bearer authentication.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_api_request(
    string $method,
    string $path,
    string $access_token,
    array $query = [],
    ?array $body = null
) {
    $access_token = ll_tools_google_classroom_token_value($access_token);
    if (
        $access_token === ''
        || preg_match('#^/v1/[A-Za-z0-9%._~!$&\'()*+,;=:@/-]+$#', $path) !== 1
        || str_contains($path, '..')
        || str_contains($path, '//')
    ) {
        return new WP_Error('ll_tools_google_classroom_api_input_invalid', __('The Google Classroom request is invalid.', 'll-tools-text-domain'));
    }
    $clean_query = [];
    foreach ($query as $key => $value) {
        if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $key) !== 1) {
            return new WP_Error('ll_tools_google_classroom_api_input_invalid', __('The Google Classroom request is invalid.', 'll-tools-text-domain'));
        }
        if (!is_scalar($value) || strlen((string) $value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
            return new WP_Error('ll_tools_google_classroom_api_input_invalid', __('The Google Classroom request is invalid.', 'll-tools-text-domain'));
        }
        $clean_query[$key] = (string) $value;
    }
    $url = 'https://classroom.googleapis.com' . $path;
    if ($clean_query !== []) {
        $url .= '?' . http_build_query($clean_query, '', '&', PHP_QUERY_RFC3986);
    }
    $headers = [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $access_token,
    ];
    $args = ['headers' => $headers];
    if ($body !== null) {
        $encoded = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > 131072) {
            return new WP_Error('ll_tools_google_classroom_api_input_invalid', __('The Google Classroom request is invalid.', 'll-tools-text-domain'));
        }
        $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $args['body'] = $encoded;
    }
    return ll_tools_google_classroom_http_json($method, $url, $args);
}

function ll_tools_google_classroom_course_summary($course): array {
    if (!is_array($course)) {
        return [];
    }
    $id = ll_tools_google_classroom_external_id($course['id'] ?? '', 256);
    $name = isset($course['name']) && is_string($course['name'])
        ? sanitize_text_field($course['name'])
        : '';
    $state = isset($course['courseState']) && is_string($course['courseState'])
        ? strtoupper(sanitize_key($course['courseState']))
        : '';
    if ($id === '' || $name === '' || $state !== 'ACTIVE') {
        return [];
    }
    return [
        'id' => $id,
        'name' => $name,
        'section' => isset($course['section']) && is_string($course['section'])
            ? sanitize_text_field($course['section'])
            : '',
        'course_state' => 'ACTIVE',
        'alternate_link' => isset($course['alternateLink']) && is_string($course['alternateLink'])
            ? esc_url_raw($course['alternateLink'], ['https'])
            : '',
    ];
}

/**
 * List a bounded number of active courses using at most five API pages.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_list_active_courses_with_access_token(string $access_token, int $limit = 50) {
    $limit = max(1, min(100, $limit));
    $courses = [];
    $seen_courses = [];
    $seen_page_tokens = [];
    $page_token = '';

    for ($page = 0; $page < 5 && count($courses) < $limit; $page++) {
        $query = [
            'teacherId' => 'me',
            'courseStates' => 'ACTIVE',
            'pageSize' => (string) min(100, $limit - count($courses)),
            'fields' => 'nextPageToken,courses(id,name,section,courseState,alternateLink)',
        ];
        if ($page_token !== '') {
            $query['pageToken'] = $page_token;
        }
        $response = ll_tools_google_classroom_api_request('GET', '/v1/courses', $access_token, $query);
        if (is_wp_error($response)) {
            return $response;
        }
        if (array_key_exists('courses', $response) && !is_array($response['courses'])) {
            return new WP_Error('ll_tools_google_classroom_courses_invalid', __('Google Classroom returned an invalid course list.', 'll-tools-text-domain'));
        }
        if (array_key_exists('nextPageToken', $response) && !is_string($response['nextPageToken'])) {
            return new WP_Error('ll_tools_google_classroom_pagination_invalid', __('Google Classroom returned an invalid course page sequence.', 'll-tools-text-domain'));
        }
        $rows = isset($response['courses']) ? $response['courses'] : [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $summary = ll_tools_google_classroom_course_summary($row);
            if ($summary === [] || isset($seen_courses[$summary['id']])) {
                continue;
            }
            $seen_courses[$summary['id']] = true;
            $courses[] = $summary;
            if (count($courses) >= $limit) {
                break;
            }
        }

        $next = ll_tools_google_classroom_external_id($response['nextPageToken'] ?? '', 2048);
        if ($next === '') {
            break;
        }
        if (isset($seen_page_tokens[$next])) {
            return new WP_Error('ll_tools_google_classroom_pagination_invalid', __('Google Classroom returned an invalid course page sequence.', 'll-tools-text-domain'));
        }
        $seen_page_tokens[$next] = true;
        $page_token = $next;
    }

    return $courses;
}

/**
 * List courses for an owned encrypted connection.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_list_active_courses(int $connection_id, int $teacher_user_id, int $limit = 50) {
    $access = ll_tools_google_classroom_access_context($connection_id, $teacher_user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    return ll_tools_google_classroom_list_active_courses_with_access_token($access['access_token'], $limit);
}

function ll_tools_google_classroom_coursework_summary($coursework): array {
    if (!is_array($coursework)) {
        return [];
    }
    $id = ll_tools_google_classroom_external_id($coursework['id'] ?? '', 256);
    $course_id = ll_tools_google_classroom_external_id($coursework['courseId'] ?? '', 256);
    $title = isset($coursework['title']) && is_string($coursework['title'])
        ? sanitize_text_field($coursework['title'])
        : '';
    $state = isset($coursework['state']) && is_string($coursework['state'])
        ? strtoupper(sanitize_key($coursework['state']))
        : '';
    if ($id === '' || $course_id === '' || $title === '' || !is_numeric($coursework['maxPoints'] ?? null)) {
        return [];
    }
    return [
        'id' => $id,
        'course_id' => $course_id,
        'title' => $title,
        'state' => $state,
        'max_points' => (float) $coursework['maxPoints'],
        'work_type' => isset($coursework['workType']) && is_string($coursework['workType'])
            ? strtoupper(sanitize_key($coursework['workType']))
            : '',
        'associated_with_developer' => !empty($coursework['associatedWithDeveloper']),
        'alternate_link' => isset($coursework['alternateLink']) && is_string($coursework['alternateLink'])
            ? esc_url_raw($coursework['alternateLink'], ['https'])
            : '',
        'creation_time' => isset($coursework['creationTime']) && is_string($coursework['creationTime'])
            ? sanitize_text_field($coursework['creationTime'])
            : '',
        'update_time' => isset($coursework['updateTime']) && is_string($coursework['updateTime'])
            ? sanitize_text_field($coursework['updateTime'])
            : '',
    ];
}

/**
 * Read back one exact CourseWork resource.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_read_coursework_with_access_token(
    string $access_token,
    string $course_id,
    string $coursework_id
) {
    $course_id = ll_tools_google_classroom_external_id($course_id);
    $coursework_id = ll_tools_google_classroom_external_id($coursework_id);
    if ($course_id === '' || $coursework_id === '') {
        return new WP_Error('ll_tools_google_classroom_coursework_invalid', __('The Google Classroom assignment identifier is invalid.', 'll-tools-text-domain'));
    }
    $response = ll_tools_google_classroom_api_request(
        'GET',
        '/v1/courses/' . rawurlencode($course_id) . '/courseWork/' . rawurlencode($coursework_id),
        $access_token
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $summary = ll_tools_google_classroom_coursework_summary($response);
    if ($summary === [] || !hash_equals($course_id, $summary['course_id']) || !hash_equals($coursework_id, $summary['id'])) {
        return new WP_Error('ll_tools_google_classroom_coursework_mismatch', __('Google Classroom returned a different assignment.', 'll-tools-text-domain'));
    }
    return $summary;
}

/**
 * Read back CourseWork for one owned connection.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_read_coursework(
    int $connection_id,
    int $teacher_user_id,
    string $course_id,
    string $coursework_id
) {
    $access = ll_tools_google_classroom_access_context($connection_id, $teacher_user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    return ll_tools_google_classroom_read_coursework_with_access_token(
        $access['access_token'],
        $course_id,
        $coursework_id
    );
}

/**
 * Resolve a published immutable revision from authoritative local tables.
 *
 * The caller supplies identifiers only. Title, denominator, and points are
 * always reloaded from the database and the stored manifest is authenticated
 * before any external write.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_resolve_assignment_authority(array $assignment, int $teacher_user_id) {
    $keys = array_keys($assignment);
    sort($keys, SORT_STRING);
    if (
        $keys !== ['assignment_id', 'revision_id']
        || !is_int($assignment['assignment_id'])
        || !is_int($assignment['revision_id'])
        || $assignment['assignment_id'] <= 0
        || $assignment['revision_id'] <= 0
        || $teacher_user_id <= 0
        || !function_exists('ll_tools_lms_assignment_schema_is_available')
        || !ll_tools_lms_assignment_schema_is_available()
        || !function_exists('ll_tools_lms_assignment_get')
        || !function_exists('ll_tools_lms_assignment_get_revision')
        || !function_exists('ll_tools_lms_assignment_revision_manifest')
        || !function_exists('ll_tools_lms_assignment_user_can_manage_class')
    ) {
        return new WP_Error('ll_tools_google_classroom_assignment_invalid', __('The server-authoritative assignment is invalid.', 'll-tools-text-domain'));
    }

    $stored_assignment = ll_tools_lms_assignment_get($assignment['assignment_id']);
    $revision = ll_tools_lms_assignment_get_revision($assignment['revision_id']);
    if (
        !is_array($stored_assignment)
        || !is_array($revision)
        || (string) ($stored_assignment['status'] ?? '') !== 'published'
        || (int) ($stored_assignment['current_revision_id'] ?? 0) !== $assignment['revision_id']
        || (int) ($revision['assignment_id'] ?? 0) !== $assignment['assignment_id']
        || empty($revision['published_at'])
        || !ll_tools_lms_assignment_user_can_manage_class((int) ($stored_assignment['class_id'] ?? 0), $teacher_user_id)
    ) {
        return new WP_Error('ll_tools_google_classroom_assignment_invalid', __('The server-authoritative assignment is invalid.', 'll-tools-text-domain'));
    }
    $manifest = ll_tools_lms_assignment_revision_manifest($revision);
    $question_count = is_array($manifest) ? count((array) ($manifest['items'] ?? [])) : 0;
    $points_maximum = function_exists('ll_tools_lms_assignment_normalize_points')
        ? ll_tools_lms_assignment_normalize_points($revision['points_maximum'] ?? null)
        : new WP_Error('ll_tools_google_classroom_assignment_invalid');
    if (
        is_wp_error($manifest)
        || is_wp_error($points_maximum)
        || $question_count <= 0
        || $question_count !== (int) ($revision['question_count'] ?? 0)
        || (string) $points_maximum !== number_format((float) ($revision['points_maximum'] ?? 0), 4, '.', '')
        || abs((float) $points_maximum - round((float) $points_maximum)) > 0.00001
    ) {
        return new WP_Error('ll_tools_google_classroom_assignment_invalid', __('The server-authoritative assignment is invalid.', 'll-tools-text-domain'));
    }
    $title = isset($stored_assignment['title']) && is_string($stored_assignment['title'])
        ? trim(wp_strip_all_tags($stored_assignment['title']))
        : '';
    if ($title === '' || strlen($title) > 3000) {
        return new WP_Error('ll_tools_google_classroom_assignment_invalid', __('The server-authoritative assignment is invalid.', 'll-tools-text-domain'));
    }

    return [
        'assignment_id' => (int) $stored_assignment['id'],
        'revision_id' => (int) $revision['id'],
        'class_id' => (int) $stored_assignment['class_id'],
        'title' => $title,
        'question_count' => $question_count,
        'points_maximum' => (string) $points_maximum,
        'google_points_maximum' => (int) round((float) $points_maximum),
        'manifest_hash' => (string) $revision['manifest_hash'],
        'published_at' => (string) $revision['published_at'],
    ];
}

/**
 * Create explicit DRAFT CourseWork and immediately verify its readback.
 * This helper is intentionally not exposed in the current admin UI.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_create_draft_coursework_with_access_token(
    string $access_token,
    int $teacher_user_id,
    string $course_id,
    array $assignment
) {
    if (!ll_tools_google_classroom_writes_ready()) {
        return new WP_Error('ll_tools_google_classroom_writes_disabled', __('Google Classroom grade writes are not enabled.', 'll-tools-text-domain'));
    }
    $course_id = ll_tools_google_classroom_external_id($course_id);
    $authority = ll_tools_google_classroom_resolve_assignment_authority($assignment, $teacher_user_id);
    if ($course_id === '' || is_wp_error($authority)) {
        return new WP_Error('ll_tools_google_classroom_assignment_invalid', __('The server-authoritative assignment is invalid.', 'll-tools-text-domain'));
    }
    $maximum = (int) $authority['google_points_maximum'];
    $body = [
        'title' => $authority['title'],
        'workType' => 'ASSIGNMENT',
        'state' => 'DRAFT',
        'maxPoints' => $maximum,
    ];
    $created = ll_tools_google_classroom_api_request(
        'POST',
        '/v1/courses/' . rawurlencode($course_id) . '/courseWork',
        $access_token,
        [],
        $body
    );
    if (is_wp_error($created)) {
        return $created;
    }
    $created_summary = ll_tools_google_classroom_coursework_summary($created);
    if ($created_summary === [] || !hash_equals($course_id, $created_summary['course_id'])) {
        return new WP_Error('ll_tools_google_classroom_coursework_create_mismatch', __('Google Classroom did not confirm the draft assignment.', 'll-tools-text-domain'));
    }
    $readback = ll_tools_google_classroom_read_coursework_with_access_token(
        $access_token,
        $course_id,
        $created_summary['id']
    );
    if (
        is_wp_error($readback)
        || $readback['state'] !== 'DRAFT'
        || $readback['work_type'] !== 'ASSIGNMENT'
        || !$readback['associated_with_developer']
        || (int) $readback['max_points'] !== $maximum
    ) {
        return is_wp_error($readback)
            ? $readback
            : new WP_Error('ll_tools_google_classroom_coursework_create_mismatch', __('Google Classroom did not confirm the draft assignment.', 'll-tools-text-domain'));
    }
    return $readback;
}

/**
 * Connection-backed DRAFT CourseWork helper; not registered as an admin action.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_create_draft_coursework(
    int $connection_id,
    int $teacher_user_id,
    string $course_id,
    array $assignment
) {
    if (!ll_tools_google_classroom_writes_ready()) {
        return new WP_Error('ll_tools_google_classroom_writes_disabled', __('Google Classroom grade writes are not enabled.', 'll-tools-text-domain'));
    }
    $access = ll_tools_google_classroom_access_context($connection_id, $teacher_user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    if (ll_tools_google_classroom_missing_write_scopes($access['scopes'] ?? []) !== []) {
        return new WP_Error('ll_tools_google_classroom_write_scope_missing', __('Google Classroom grade permission has not been granted.', 'll-tools-text-domain'));
    }
    return ll_tools_google_classroom_create_draft_coursework_with_access_token(
        $access['access_token'],
        $teacher_user_id,
        $course_id,
        $assignment
    );
}

function ll_tools_google_classroom_submission_summary($submission): array {
    if (!is_array($submission)) {
        return [];
    }
    $id = ll_tools_google_classroom_external_id($submission['id'] ?? '', 256);
    $course_id = ll_tools_google_classroom_external_id($submission['courseId'] ?? '', 256);
    $coursework_id = ll_tools_google_classroom_external_id($submission['courseWorkId'] ?? '', 256);
    $user_id = ll_tools_google_classroom_external_id($submission['userId'] ?? '', 256);
    if ($id === '' || $course_id === '' || $coursework_id === '' || $user_id === '') {
        return [];
    }
    return [
        'id' => $id,
        'course_id' => $course_id,
        'coursework_id' => $coursework_id,
        'user_id' => $user_id,
        'state' => isset($submission['state']) && is_string($submission['state'])
            ? strtoupper(sanitize_key($submission['state']))
            : '',
        'draft_grade' => isset($submission['draftGrade']) && is_numeric($submission['draftGrade'])
            ? (float) $submission['draftGrade']
            : null,
        'assigned_grade' => isset($submission['assignedGrade']) && is_numeric($submission['assignedGrade'])
            ? (float) $submission['assignedGrade']
            : null,
        'update_time' => isset($submission['updateTime']) && is_string($submission['updateTime'])
            ? sanitize_text_field($submission['updateTime'])
            : '',
    ];
}

/**
 * Resolve exactly one submission using explicit course, CourseWork, and Google
 * user IDs. Email addresses and display names are never matching keys.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_lookup_student_submission_with_access_token(
    string $access_token,
    string $course_id,
    string $coursework_id,
    string $google_user_id
) {
    $course_id = ll_tools_google_classroom_external_id($course_id);
    $coursework_id = ll_tools_google_classroom_external_id($coursework_id);
    $google_user_id = ll_tools_google_classroom_external_id($google_user_id);
    if ($course_id === '' || $coursework_id === '' || $google_user_id === '') {
        return new WP_Error('ll_tools_google_classroom_submission_invalid', __('The Google Classroom submission mapping is invalid.', 'll-tools-text-domain'));
    }
    $response = ll_tools_google_classroom_api_request(
        'GET',
        '/v1/courses/' . rawurlencode($course_id)
            . '/courseWork/' . rawurlencode($coursework_id)
            . '/studentSubmissions',
        $access_token,
        ['userId' => $google_user_id, 'pageSize' => '2']
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $rows = isset($response['studentSubmissions']) && is_array($response['studentSubmissions'])
        ? array_values($response['studentSubmissions'])
        : [];
    if (count($rows) !== 1 || ll_tools_google_classroom_external_id($response['nextPageToken'] ?? '', 2048) !== '') {
        return new WP_Error('ll_tools_google_classroom_submission_ambiguous', __('Google Classroom did not return exactly one matching submission.', 'll-tools-text-domain'));
    }
    $summary = ll_tools_google_classroom_submission_summary($rows[0]);
    if (
        $summary === []
        || !hash_equals($course_id, $summary['course_id'])
        || !hash_equals($coursework_id, $summary['coursework_id'])
        || !hash_equals($google_user_id, $summary['user_id'])
    ) {
        return new WP_Error('ll_tools_google_classroom_submission_mismatch', __('Google Classroom returned a different submission.', 'll-tools-text-domain'));
    }
    return $summary;
}

/**
 * Connection-backed unambiguous submission lookup.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_lookup_student_submission(
    int $connection_id,
    int $teacher_user_id,
    string $course_id,
    string $coursework_id,
    string $google_user_id
) {
    $access = ll_tools_google_classroom_access_context($connection_id, $teacher_user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    return ll_tools_google_classroom_lookup_student_submission_with_access_token(
        $access['access_token'],
        $course_id,
        $coursework_id,
        $google_user_id
    );
}

function ll_tools_google_classroom_grade_points($value, bool $maximum = false): string {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        return '';
    }
    $points = (float) $value;
    if (
        !is_finite($points)
        || ($maximum ? $points <= 0 : $points < 0)
        || $points > 1000000
    ) {
        return '';
    }
    return number_format($points, 4, '.', '');
}

/**
 * Reload and compare the current selected grade and its finalized selected
 * attempt. A caller-supplied marker can never confer write authority.
 *
 * @return array|WP_Error Canonical DB-backed grade snapshot.
 */
function ll_tools_google_classroom_resolve_grade_authority(array $grade, int $teacher_user_id) {
    $expected_keys = [
        'assignment_id', 'revision_id', 'user_id', 'selected_attempt_id',
        'grade_revision', 'score_given', 'score_maximum', 'points_given',
        'points_maximum',
    ];
    $actual_keys = array_keys($grade);
    sort($expected_keys, SORT_STRING);
    sort($actual_keys, SORT_STRING);
    if ($actual_keys !== $expected_keys || $teacher_user_id <= 0) {
        return new WP_Error('ll_tools_google_classroom_grade_invalid', __('The server-authoritative grade is invalid.', 'll-tools-text-domain'));
    }
    foreach (['assignment_id', 'revision_id', 'user_id', 'selected_attempt_id', 'grade_revision', 'score_given', 'score_maximum'] as $field) {
        if (!is_int($grade[$field]) || $grade[$field] < 0) {
            return new WP_Error('ll_tools_google_classroom_grade_invalid', __('The server-authoritative grade is invalid.', 'll-tools-text-domain'));
        }
    }
    if (
        $grade['assignment_id'] <= 0
        || $grade['revision_id'] <= 0
        || $grade['user_id'] <= 0
        || $grade['selected_attempt_id'] <= 0
        || $grade['grade_revision'] <= 0
        || $grade['score_maximum'] <= 0
        || $grade['score_given'] > $grade['score_maximum']
        || !function_exists('ll_tools_lms_assignment_schema_is_available')
        || !ll_tools_lms_assignment_schema_is_available()
        || !function_exists('ll_tools_lms_assignment_get')
        || !function_exists('ll_tools_lms_assignment_get_revision')
        || !function_exists('ll_tools_lms_assignment_get_grade')
        || !function_exists('ll_tools_lms_assignment_get_attempt')
        || !function_exists('ll_tools_lms_assignment_revision_manifest')
        || !function_exists('ll_tools_lms_assignment_user_can_manage_class')
    ) {
        return new WP_Error('ll_tools_google_classroom_grade_invalid', __('The server-authoritative grade is invalid.', 'll-tools-text-domain'));
    }

    $assignment = ll_tools_lms_assignment_get($grade['assignment_id']);
    $revision = ll_tools_lms_assignment_get_revision($grade['revision_id']);
    $stored_grade = ll_tools_lms_assignment_get_grade(
        $grade['assignment_id'],
        $grade['revision_id'],
        $grade['user_id']
    );
    $attempt = ll_tools_lms_assignment_get_attempt($grade['selected_attempt_id']);
    $manifest = is_array($revision) ? ll_tools_lms_assignment_revision_manifest($revision) : null;
    $manifest_question_count = is_array($manifest) ? count((array) ($manifest['items'] ?? [])) : 0;
    $revision_points_maximum = is_array($revision)
        ? ll_tools_google_classroom_grade_points($revision['points_maximum'] ?? null, true)
        : '';
    if (
        !is_array($assignment)
        || !is_array($revision)
        || !is_array($stored_grade)
        || !is_array($attempt)
        || !is_array($manifest)
        || (string) ($assignment['status'] ?? '') !== 'published'
        || (int) ($assignment['current_revision_id'] ?? 0) !== $grade['revision_id']
        || (int) ($revision['assignment_id'] ?? 0) !== $grade['assignment_id']
        || empty($revision['published_at'])
        || $manifest_question_count <= 0
        || $manifest_question_count !== (int) ($revision['question_count'] ?? 0)
        || $revision_points_maximum === ''
        || !ll_tools_lms_assignment_user_can_manage_class((int) ($assignment['class_id'] ?? 0), $teacher_user_id)
        || (string) ($attempt['status'] ?? '') !== 'finalized'
        || empty($attempt['finalized_at'])
        || (int) ($attempt['assignment_id'] ?? 0) !== $grade['assignment_id']
        || (int) ($attempt['revision_id'] ?? 0) !== $grade['revision_id']
        || (int) ($attempt['user_id'] ?? 0) !== $grade['user_id']
        || (int) ($stored_grade['selected_attempt_id'] ?? 0) !== $grade['selected_attempt_id']
        || (string) ($stored_grade['grade_policy'] ?? '') !== (string) ($revision['grade_policy'] ?? '')
    ) {
        return new WP_Error('ll_tools_google_classroom_grade_invalid', __('The server-authoritative grade is invalid.', 'll-tools-text-domain'));
    }

    $canonical = [
        'assignment_id' => (int) $stored_grade['assignment_id'],
        'revision_id' => (int) $stored_grade['revision_id'],
        'user_id' => (int) $stored_grade['user_id'],
        'selected_attempt_id' => (int) $stored_grade['selected_attempt_id'],
        'grade_revision' => (int) $stored_grade['grade_revision'],
        'score_given' => (int) $stored_grade['score_given'],
        'score_maximum' => (int) $stored_grade['score_maximum'],
        'points_given' => ll_tools_google_classroom_grade_points($stored_grade['points_given'] ?? null),
        'points_maximum' => ll_tools_google_classroom_grade_points($stored_grade['points_maximum'] ?? null, true),
    ];
    $requested_points_given = ll_tools_google_classroom_grade_points($grade['points_given']);
    $requested_points_maximum = ll_tools_google_classroom_grade_points($grade['points_maximum'], true);
    if (
        $canonical['points_given'] === ''
        || $canonical['points_maximum'] === ''
        || $requested_points_given === ''
        || $requested_points_maximum === ''
        || $canonical['assignment_id'] !== $grade['assignment_id']
        || $canonical['revision_id'] !== $grade['revision_id']
        || $canonical['user_id'] !== $grade['user_id']
        || $canonical['selected_attempt_id'] !== $grade['selected_attempt_id']
        || $canonical['grade_revision'] !== $grade['grade_revision']
        || $canonical['score_given'] !== $grade['score_given']
        || $canonical['score_maximum'] !== $grade['score_maximum']
        || $canonical['score_maximum'] !== $manifest_question_count
        || !hash_equals($canonical['points_given'], $requested_points_given)
        || !hash_equals($canonical['points_maximum'], $requested_points_maximum)
        || !hash_equals($canonical['points_maximum'], $revision_points_maximum)
        || (int) ($attempt['score_given'] ?? -1) !== $canonical['score_given']
        || (int) ($attempt['score_maximum'] ?? -1) !== $canonical['score_maximum']
        || ll_tools_google_classroom_grade_points($attempt['points_given'] ?? null) !== $canonical['points_given']
        || ll_tools_google_classroom_grade_points($attempt['points_maximum'] ?? null, true) !== $canonical['points_maximum']
    ) {
        return new WP_Error('ll_tools_google_classroom_grade_stale', __('The selected assignment grade changed before it could be sent.', 'll-tools-text-domain'));
    }
    $maximum = (float) $canonical['points_maximum'];
    if (abs($maximum - round($maximum)) > 0.00001) {
        return new WP_Error('ll_tools_google_classroom_grade_invalid', __('Google Classroom requires a whole-number assignment maximum.', 'll-tools-text-domain'));
    }
    $canonical['google_points_maximum'] = (int) round($maximum);
    $canonical['draft_grade'] = round((float) $canonical['points_given'], 2);
    return $canonical;
}

/**
 * PATCH only draftGrade for an exact mapped submission. No return/publish
 * transition is performed here.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_patch_draft_grade_with_access_token(
    string $access_token,
    int $teacher_user_id,
    string $course_id,
    string $coursework_id,
    string $google_user_id,
    string $submission_id,
    array $grade
) {
    if (!ll_tools_google_classroom_writes_ready()) {
        return new WP_Error('ll_tools_google_classroom_writes_disabled', __('Google Classroom grade writes are not enabled.', 'll-tools-text-domain'));
    }
    $course_id = ll_tools_google_classroom_external_id($course_id);
    $coursework_id = ll_tools_google_classroom_external_id($coursework_id);
    $google_user_id = ll_tools_google_classroom_external_id($google_user_id);
    $submission_id = ll_tools_google_classroom_external_id($submission_id);
    $authority = ll_tools_google_classroom_resolve_grade_authority($grade, $teacher_user_id);
    if (
        $course_id === ''
        || $coursework_id === ''
        || $google_user_id === ''
        || $submission_id === ''
        || is_wp_error($authority)
    ) {
        return is_wp_error($authority)
            ? $authority
            : new WP_Error('ll_tools_google_classroom_grade_invalid', __('The server-authoritative grade is invalid.', 'll-tools-text-domain'));
    }
    $coursework = ll_tools_google_classroom_read_coursework_with_access_token($access_token, $course_id, $coursework_id);
    if (
        is_wp_error($coursework)
        || !$coursework['associated_with_developer']
        || $coursework['state'] !== 'PUBLISHED'
        || (int) $coursework['max_points'] !== $authority['google_points_maximum']
    ) {
        return is_wp_error($coursework)
            ? $coursework
            : new WP_Error('ll_tools_google_classroom_grade_destination_mismatch', __('The Google Classroom assignment does not match the selected grade.', 'll-tools-text-domain'));
    }
    $mapped_submission = ll_tools_google_classroom_lookup_student_submission_with_access_token(
        $access_token,
        $course_id,
        $coursework_id,
        $google_user_id
    );
    if (
        is_wp_error($mapped_submission)
        || !hash_equals($submission_id, (string) ($mapped_submission['id'] ?? ''))
    ) {
        return is_wp_error($mapped_submission)
            ? $mapped_submission
            : new WP_Error('ll_tools_google_classroom_grade_destination_mismatch', __('The Google Classroom submission does not match the selected learner.', 'll-tools-text-domain'));
    }

    $response = ll_tools_google_classroom_api_request(
        'PATCH',
        '/v1/courses/' . rawurlencode($course_id)
            . '/courseWork/' . rawurlencode($coursework_id)
            . '/studentSubmissions/' . rawurlencode($submission_id),
        $access_token,
        ['updateMask' => 'draftGrade'],
        ['draftGrade' => $authority['draft_grade']]
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $summary = ll_tools_google_classroom_submission_summary($response);
    if (
        $summary === []
        || !hash_equals($course_id, $summary['course_id'])
        || !hash_equals($coursework_id, $summary['coursework_id'])
        || !hash_equals($submission_id, $summary['id'])
        || !hash_equals($google_user_id, $summary['user_id'])
        || $summary['draft_grade'] === null
        || number_format((float) $summary['draft_grade'], 2, '.', '') !== number_format((float) $authority['draft_grade'], 2, '.', '')
    ) {
        return new WP_Error('ll_tools_google_classroom_grade_readback_mismatch', __('Google Classroom did not confirm the draft grade.', 'll-tools-text-domain'));
    }
    return $summary;
}

/**
 * Connection-backed draftGrade helper; intentionally not exposed in the UI.
 *
 * @return array|WP_Error
 */
function ll_tools_google_classroom_patch_draft_grade(
    int $connection_id,
    int $teacher_user_id,
    string $course_id,
    string $coursework_id,
    string $google_user_id,
    string $submission_id,
    array $grade
) {
    if (!ll_tools_google_classroom_writes_ready()) {
        return new WP_Error('ll_tools_google_classroom_writes_disabled', __('Google Classroom grade writes are not enabled.', 'll-tools-text-domain'));
    }
    $access = ll_tools_google_classroom_access_context($connection_id, $teacher_user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    if (ll_tools_google_classroom_missing_write_scopes($access['scopes'] ?? []) !== []) {
        return new WP_Error('ll_tools_google_classroom_write_scope_missing', __('Google Classroom grade permission has not been granted.', 'll-tools-text-domain'));
    }
    return ll_tools_google_classroom_patch_draft_grade_with_access_token(
        $access['access_token'],
        $teacher_user_id,
        $course_id,
        $coursework_id,
        $google_user_id,
        $submission_id,
        $grade
    );
}
