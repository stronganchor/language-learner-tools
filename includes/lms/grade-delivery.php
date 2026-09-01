<?php
if (!defined('WPINC')) { die; }

/**
 * Provider-neutral external grade mapping and delivery outbox.
 *
 * This module intentionally registers no network adapters. Provider modules
 * must opt in at runtime with bounded validators and a sender callback. Core
 * rows retain only local IDs, keyed hashes, canonical grade snapshots, and
 * redacted delivery diagnostics; credentials, raw provider identifiers,
 * response bodies, and learner PII do not belong in these tables.
 */

if (!defined('LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION')) {
    define('LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION', '1.0.0');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION')) {
    define('LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION', 'll_tools_grade_delivery_schema_version');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION')) {
    define('LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION', 'll_tools_grade_delivery_schema_verified_version');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT')) {
    define('LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT', 'll_tools_grade_delivery_schema_retry');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK')) {
    define('LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK', 'll_tools_grade_delivery_worker');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT')) {
    define('LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT', 'll_tools_grade_delivery_resume_guard');
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_MAX_ADAPTERS')) {
    define('LL_TOOLS_GRADE_DELIVERY_MAX_ADAPTERS', 16);
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_MAX_DESTINATIONS_PER_GRADE')) {
    define('LL_TOOLS_GRADE_DELIVERY_MAX_DESTINATIONS_PER_GRADE', 32);
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_MAX_WORKER_BATCH')) {
    define('LL_TOOLS_GRADE_DELIVERY_MAX_WORKER_BATCH', 20);
}
if (!defined('LL_TOOLS_GRADE_DELIVERY_MAX_ATTEMPTS')) {
    define('LL_TOOLS_GRADE_DELIVERY_MAX_ATTEMPTS', 8);
}

/** @return array<string,string> */
function ll_tools_grade_delivery_table_names(): array {
    global $wpdb;

    return [
        'identities' => $wpdb->prefix . 'll_tools_external_identities',
        'destinations' => $wpdb->prefix . 'll_tools_grade_destinations',
        'recipients' => $wpdb->prefix . 'll_tools_grade_recipients',
        'deliveries' => $wpdb->prefix . 'll_tools_grade_deliveries',
    ];
}

/**
 * Structural storage contract for full installer readback.
 *
 * @return array<string,array{columns:array<string,array<string,mixed>>,indexes:array<string,array{unique:bool,columns:array<int,string>}>}>
 */
function ll_tools_grade_delivery_schema_contract(): array {
    $unsigned_bigint = '/^bigint(?:\(\d+\))? unsigned$/';
    $unsigned_int = '/^int(?:\(\d+\))? unsigned$/';
    $unsigned_smallint = '/^smallint(?:\(\d+\))? unsigned$/';

    return [
        'identities' => [
            'columns' => [
                'id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'extra' => 'auto_increment'],
                'adapter' => ['type' => '/^varchar\(32\)$/', 'null' => 'NO'],
                'connection_key_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'subject_key_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'learner_user_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'status' => ['type' => '/^varchar\(16\)$/', 'null' => 'NO', 'default' => 'active'],
                'created_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'updated_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_adapter_subject' => ['unique' => true, 'columns' => ['adapter', 'connection_key_hash', 'subject_key_hash']],
                'uniq_adapter_learner' => ['unique' => true, 'columns' => ['adapter', 'connection_key_hash', 'learner_user_id']],
                'idx_learner' => ['unique' => false, 'columns' => ['learner_user_id', 'id']],
            ],
        ],
        'destinations' => [
            'columns' => [
                'id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'extra' => 'auto_increment'],
                'adapter' => ['type' => '/^varchar\(32\)$/', 'null' => 'NO'],
                'connection_key_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'destination_key_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'assignment_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'revision_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'status' => ['type' => '/^varchar\(16\)$/', 'null' => 'NO', 'default' => 'active'],
                'created_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'updated_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_destination' => ['unique' => true, 'columns' => ['adapter', 'connection_key_hash', 'destination_key_hash']],
                'idx_assignment' => ['unique' => false, 'columns' => ['assignment_id', 'revision_id', 'status', 'id']],
                'idx_adapter_status' => ['unique' => false, 'columns' => ['adapter', 'status', 'id']],
            ],
        ],
        'recipients' => [
            'columns' => [
                'id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'extra' => 'auto_increment'],
                'destination_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'external_identity_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'learner_user_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'recipient_key_hash' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'status' => ['type' => '/^varchar\(16\)$/', 'null' => 'NO', 'default' => 'active'],
                'created_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'updated_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_destination_learner' => ['unique' => true, 'columns' => ['destination_id', 'learner_user_id']],
                'uniq_destination_recipient' => ['unique' => true, 'columns' => ['destination_id', 'recipient_key_hash']],
                'idx_identity' => ['unique' => false, 'columns' => ['external_identity_id', 'id']],
                'idx_learner' => ['unique' => false, 'columns' => ['learner_user_id', 'id']],
            ],
        ],
        'deliveries' => [
            'columns' => [
                'id' => ['type' => $unsigned_bigint, 'null' => 'NO', 'extra' => 'auto_increment'],
                'adapter' => ['type' => '/^varchar\(32\)$/', 'null' => 'NO'],
                'destination_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'recipient_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'assignment_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'revision_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'learner_user_id' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'grade_revision' => ['type' => $unsigned_bigint, 'null' => 'NO'],
                'score_given' => ['type' => $unsigned_int, 'null' => 'NO'],
                'score_maximum' => ['type' => $unsigned_int, 'null' => 'NO'],
                'points_given' => ['type' => '/^decimal\(12,4\)$/', 'null' => 'NO'],
                'points_maximum' => ['type' => '/^decimal\(12,4\)$/', 'null' => 'NO'],
                'dedupe_key' => ['type' => '/^char\(64\)$/', 'null' => 'NO'],
                'status' => ['type' => '/^varchar\(24\)$/', 'null' => 'NO', 'default' => 'pending'],
                'available_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'attempt_count' => ['type' => $unsigned_smallint, 'null' => 'NO', 'default' => '0'],
                'lease_token' => ['type' => '/^char\(64\)$/', 'null' => 'NO', 'default' => ''],
                'lease_expires_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'YES', 'default' => null],
                'last_http_status' => ['type' => $unsigned_smallint, 'null' => 'NO', 'default' => '0'],
                'last_error_code' => ['type' => '/^varchar\(64\)$/', 'null' => 'NO', 'default' => ''],
                'last_diagnostic' => ['type' => '/^varchar\(255\)$/', 'null' => 'NO', 'default' => ''],
                'created_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'updated_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'NO'],
                'delivered_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'YES', 'default' => null],
                'superseded_at' => ['type' => '/^datetime(?:\(0\))?$/', 'null' => 'YES', 'default' => null],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_dedupe' => ['unique' => true, 'columns' => ['dedupe_key']],
                'idx_due' => ['unique' => false, 'columns' => ['status', 'available_at', 'id']],
                'idx_lease' => ['unique' => false, 'columns' => ['status', 'lease_expires_at', 'id']],
                'idx_recipient_revision' => ['unique' => false, 'columns' => ['destination_id', 'learner_user_id', 'grade_revision', 'id']],
                'idx_grade' => ['unique' => false, 'columns' => ['assignment_id', 'revision_id', 'learner_user_id', 'grade_revision']],
                'idx_learner' => ['unique' => false, 'columns' => ['learner_user_id', 'id']],
            ],
        ],
    ];
}

/** Full InnoDB/column/index readback for trusted install boundaries. */
function ll_tools_grade_delivery_schema_ready(bool $refresh = false): bool {
    static $cached = null;
    global $wpdb;

    if (!$refresh && is_bool($cached)) {
        return $cached;
    }

    $tables = ll_tools_grade_delivery_table_names();
    foreach (ll_tools_grade_delivery_schema_contract() as $table_key => $requirements) {
        $table = (string) ($tables[$table_key] ?? '');
        if ($table === '') {
            $cached = false;
            return false;
        }

        $wpdb->last_error = '';
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            ARRAY_A
        );
        if (
            !is_array($status)
            || (string) $wpdb->last_error !== ''
            || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0
        ) {
            $cached = false;
            return false;
        }

        $wpdb->last_error = '';
        $column_rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (!is_array($column_rows) || (string) $wpdb->last_error !== '') {
            $cached = false;
            return false;
        }
        $columns = [];
        foreach ($column_rows as $column) {
            $name = (string) ($column['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = $column;
            }
        }
        foreach ($requirements['columns'] as $column_name => $column_contract) {
            $column = $columns[$column_name] ?? null;
            if (!is_array($column)) {
                $cached = false;
                return false;
            }
            $type = strtolower(trim((string) ($column['Type'] ?? '')));
            if (
                preg_match((string) $column_contract['type'], $type) !== 1
                || strtoupper((string) ($column['Null'] ?? '')) !== (string) $column_contract['null']
            ) {
                $cached = false;
                return false;
            }
            if (array_key_exists('default', $column_contract)) {
                $expected_default = $column_contract['default'];
                $actual_default = $column['Default'] ?? null;
                if ($expected_default === null ? $actual_default !== null : (string) $actual_default !== (string) $expected_default) {
                    $cached = false;
                    return false;
                }
            }
            if (
                isset($column_contract['extra'])
                && stripos((string) ($column['Extra'] ?? ''), (string) $column_contract['extra']) === false
            ) {
                $cached = false;
                return false;
            }
        }

        $wpdb->last_error = '';
        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($index_rows) || (string) $wpdb->last_error !== '') {
            $cached = false;
            return false;
        }
        $indexes = [];
        foreach ($index_rows as $index_row) {
            $name = (string) ($index_row['Key_name'] ?? '');
            $column_name = (string) ($index_row['Column_name'] ?? '');
            if ($name === '' || $column_name === '') {
                continue;
            }
            $sequence = max(1, (int) ($index_row['Seq_in_index'] ?? 1));
            $indexes[$name]['unique'] = ((int) ($index_row['Non_unique'] ?? 1)) === 0;
            $indexes[$name]['columns'][$sequence] = $column_name;
            if (isset($index_row['Sub_part']) && $index_row['Sub_part'] !== null) {
                $indexes[$name]['prefixed'] = true;
            }
        }
        foreach ($requirements['indexes'] as $index_name => $index_contract) {
            if (!isset($indexes[$index_name]) || !empty($indexes[$index_name]['prefixed'])) {
                $cached = false;
                return false;
            }
            ksort($indexes[$index_name]['columns'], SORT_NUMERIC);
            if (
                (bool) $indexes[$index_name]['unique'] !== (bool) $index_contract['unique']
                || array_values($indexes[$index_name]['columns']) !== $index_contract['columns']
            ) {
                $cached = false;
                return false;
            }
        }
    }

    $cached = true;
    return true;
}

/** Marker-only runtime gate; normal requests never issue schema inspection. */
function ll_tools_grade_delivery_runtime_schema_status(): array {
    $installed = (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION, '');
    $verified = (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION, '');
    $ready = $installed === LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION
        && $verified === LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION;

    return [
        'ready' => $ready,
        'failure_code' => $ready ? '' : 'grade_delivery_schema_unavailable',
        'installed_version' => $installed,
        'verified_version' => $verified,
    ];
}

/** Install or repair all four provider-neutral tables as one versioned unit. */
function ll_tools_install_grade_delivery_schema(): bool {
    global $wpdb;

    $tables = ll_tools_grade_delivery_table_names();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Concurrent writers must fail closed until all four tables pass readback.
    delete_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION);

    $statements = [];
    $statements[] = "CREATE TABLE {$tables['identities']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        adapter varchar(32) NOT NULL,
        connection_key_hash char(64) NOT NULL,
        subject_key_hash char(64) NOT NULL,
        learner_user_id bigint(20) unsigned NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_adapter_subject (adapter, connection_key_hash, subject_key_hash),
        UNIQUE KEY uniq_adapter_learner (adapter, connection_key_hash, learner_user_id),
        KEY idx_learner (learner_user_id, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $statements[] = "CREATE TABLE {$tables['destinations']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        adapter varchar(32) NOT NULL,
        connection_key_hash char(64) NOT NULL,
        destination_key_hash char(64) NOT NULL,
        assignment_id bigint(20) unsigned NOT NULL,
        revision_id bigint(20) unsigned NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_destination (adapter, connection_key_hash, destination_key_hash),
        KEY idx_assignment (assignment_id, revision_id, status, id),
        KEY idx_adapter_status (adapter, status, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $statements[] = "CREATE TABLE {$tables['recipients']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        destination_id bigint(20) unsigned NOT NULL,
        external_identity_id bigint(20) unsigned NOT NULL,
        learner_user_id bigint(20) unsigned NOT NULL,
        recipient_key_hash char(64) NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_destination_learner (destination_id, learner_user_id),
        UNIQUE KEY uniq_destination_recipient (destination_id, recipient_key_hash),
        KEY idx_identity (external_identity_id, id),
        KEY idx_learner (learner_user_id, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $statements[] = "CREATE TABLE {$tables['deliveries']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        adapter varchar(32) NOT NULL,
        destination_id bigint(20) unsigned NOT NULL,
        recipient_id bigint(20) unsigned NOT NULL,
        assignment_id bigint(20) unsigned NOT NULL,
        revision_id bigint(20) unsigned NOT NULL,
        learner_user_id bigint(20) unsigned NOT NULL,
        grade_revision bigint(20) unsigned NOT NULL,
        score_given int(10) unsigned NOT NULL,
        score_maximum int(10) unsigned NOT NULL,
        points_given decimal(12,4) NOT NULL,
        points_maximum decimal(12,4) NOT NULL,
        dedupe_key char(64) NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'pending',
        available_at datetime NOT NULL,
        attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
        lease_token char(64) NOT NULL DEFAULT '',
        lease_expires_at datetime NULL DEFAULT NULL,
        last_http_status smallint(5) unsigned NOT NULL DEFAULT 0,
        last_error_code varchar(64) NOT NULL DEFAULT '',
        last_diagnostic varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        delivered_at datetime NULL DEFAULT NULL,
        superseded_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_dedupe (dedupe_key),
        KEY idx_due (status, available_at, id),
        KEY idx_lease (status, lease_expires_at, id),
        KEY idx_recipient_revision (destination_id, learner_user_id, grade_revision, id),
        KEY idx_grade (assignment_id, revision_id, learner_user_id, grade_revision),
        KEY idx_learner (learner_user_id, id)
    ) ENGINE=InnoDB {$charset_collate};";

    foreach ($statements as $statement) {
        dbDelta($statement);
    }
    foreach ($tables as $table) {
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            ARRAY_A
        );
        if (is_array($status) && strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB");
        }
    }

    $ready = ll_tools_grade_delivery_schema_ready(true);
    $ready = (bool) apply_filters('ll_tools_grade_delivery_schema_exists_after_install', $ready, $tables);
    if (!$ready) {
        delete_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION);
        delete_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION);
        set_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    update_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION, LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION, false);
    update_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION, LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION, false);
    delete_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT);
    // A prior failed activation/repair may have left durable rows without a
    // cron event. Re-arm only after the schema contract and markers are fully
    // published, outside dbDelta and any caller-owned grade transaction.
    if (function_exists('ll_tools_grade_delivery_schedule_next_due')) {
        ll_tools_grade_delivery_schedule_next_due();
    }
    return true;
}

function ll_tools_maybe_upgrade_grade_delivery_schema(): bool {
    if (!empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return true;
    }
    if (get_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT)) {
        if (function_exists('ll_tools_schedule_schema_maintenance')) {
            ll_tools_schedule_schema_maintenance('grade_delivery', 5 * MINUTE_IN_SECONDS);
        }
        return false;
    }
    if (!function_exists('ll_tools_maybe_run_schema_maintenance')) {
        return false;
    }

    return ll_tools_maybe_run_schema_maintenance(
        'grade_delivery',
        static function (): bool {
            return !empty(ll_tools_grade_delivery_runtime_schema_status()['ready']);
        },
        'll_tools_install_grade_delivery_schema'
    );
}
add_action('init', 'll_tools_maybe_upgrade_grade_delivery_schema', 13);

function ll_tools_grade_delivery_schema_error(): WP_Error {
    return new WP_Error(
        'grade_delivery_schema_unavailable',
        __('External grade delivery is temporarily unavailable while storage is being prepared.', 'll-tools-text-domain'),
        ['status' => 503, 'retryable' => true]
    );
}

function ll_tools_grade_delivery_adapter_id($value): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $value) === 1 ? $value : '';
}

function ll_tools_grade_delivery_mapping_hash($value): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
}

/**
 * Register one explicit provider adapter for this request.
 *
 * All four callbacks are required. Validators must return literal true or a
 * WP_Error. The sender must return the bounded response contract accepted by
 * ll_tools_grade_delivery_classify_adapter_response().
 *
 * @return true|WP_Error
 */
function ll_tools_grade_delivery_register_adapter(string $adapter, array $definition) {
    $adapter = ll_tools_grade_delivery_adapter_id($adapter);
    if ($adapter === '') {
        return new WP_Error('invalid_grade_delivery_adapter', __('The grade delivery adapter identifier is invalid.', 'll-tools-text-domain'));
    }
    $required = ['validate_identity', 'validate_destination', 'validate_recipient', 'send'];
    $keys = array_keys($definition);
    sort($keys, SORT_STRING);
    $expected = $required;
    sort($expected, SORT_STRING);
    if ($keys !== $expected) {
        return new WP_Error('invalid_grade_delivery_adapter_contract', __('The grade delivery adapter contract is invalid.', 'll-tools-text-domain'));
    }
    foreach ($required as $callback_name) {
        if (!is_callable($definition[$callback_name] ?? null)) {
            return new WP_Error('invalid_grade_delivery_adapter_contract', __('The grade delivery adapter contract is invalid.', 'll-tools-text-domain'));
        }
    }

    if (!isset($GLOBALS['ll_tools_grade_delivery_adapters']) || !is_array($GLOBALS['ll_tools_grade_delivery_adapters'])) {
        $GLOBALS['ll_tools_grade_delivery_adapters'] = [];
    }
    if (isset($GLOBALS['ll_tools_grade_delivery_adapters'][$adapter])) {
        return new WP_Error('grade_delivery_adapter_already_registered', __('The grade delivery adapter is already registered.', 'll-tools-text-domain'));
    }
    if (count($GLOBALS['ll_tools_grade_delivery_adapters']) >= LL_TOOLS_GRADE_DELIVERY_MAX_ADAPTERS) {
        return new WP_Error('grade_delivery_adapter_limit', __('Too many grade delivery adapters are registered.', 'll-tools-text-domain'));
    }
    $GLOBALS['ll_tools_grade_delivery_adapters'][$adapter] = $definition;
    return true;
}

function ll_tools_grade_delivery_unregister_adapter(string $adapter): void {
    $adapter = ll_tools_grade_delivery_adapter_id($adapter);
    if ($adapter !== '' && isset($GLOBALS['ll_tools_grade_delivery_adapters'][$adapter])) {
        unset($GLOBALS['ll_tools_grade_delivery_adapters'][$adapter]);
    }
}

/** @return array<string,callable>|null */
function ll_tools_grade_delivery_get_adapter(string $adapter): ?array {
    $adapter = ll_tools_grade_delivery_adapter_id($adapter);
    $registry = $GLOBALS['ll_tools_grade_delivery_adapters'] ?? [];
    return $adapter !== '' && is_array($registry) && isset($registry[$adapter]) && is_array($registry[$adapter])
        ? $registry[$adapter]
        : null;
}

/** @return true|WP_Error */
function ll_tools_grade_delivery_run_mapping_validator(string $adapter, string $callback_name, array $mapping) {
    $definition = ll_tools_grade_delivery_get_adapter($adapter);
    if ($definition === null || !isset($definition[$callback_name]) || !is_callable($definition[$callback_name])) {
        return new WP_Error('grade_delivery_adapter_unavailable', __('The grade delivery adapter is not available.', 'll-tools-text-domain'));
    }
    try {
        $validated = call_user_func($definition[$callback_name], $mapping);
    } catch (Throwable $error) {
        return new WP_Error('grade_delivery_mapping_validation_failed', __('The external grade mapping could not be validated.', 'll-tools-text-domain'));
    }
    if (is_wp_error($validated)) {
        return $validated;
    }
    if ($validated !== true) {
        return new WP_Error('grade_delivery_mapping_validation_failed', __('The external grade mapping could not be validated.', 'll-tools-text-domain'));
    }
    return true;
}

/** @return true|WP_Error */
function ll_tools_grade_delivery_validate_identity_mapping(array $mapping) {
    return ll_tools_grade_delivery_run_mapping_validator((string) ($mapping['adapter'] ?? ''), 'validate_identity', $mapping);
}

/** @return true|WP_Error */
function ll_tools_grade_delivery_validate_destination_mapping(array $mapping) {
    return ll_tools_grade_delivery_run_mapping_validator((string) ($mapping['adapter'] ?? ''), 'validate_destination', $mapping);
}

/** @return true|WP_Error */
function ll_tools_grade_delivery_validate_recipient_mapping(array $mapping) {
    return ll_tools_grade_delivery_run_mapping_validator((string) ($mapping['adapter'] ?? ''), 'validate_recipient', $mapping);
}

function ll_tools_grade_delivery_now(): int {
    return max(1, (int) apply_filters('ll_tools_grade_delivery_now', time()));
}

function ll_tools_grade_delivery_now_mysql(): string {
    return gmdate('Y-m-d H:i:s', ll_tools_grade_delivery_now());
}

function ll_tools_grade_delivery_user_write_allowed(int $user_id): bool {
    return $user_id > 0
        && get_userdata($user_id) instanceof WP_User
        && (
            !function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            || !ll_tools_privacy_user_lms_deletion_is_pending($user_id)
        );
}

/**
 * Recheck both privacy fences with a current read after the learner row lock.
 * A normal SELECT can keep an older repeatable-read snapshot after a concurrent
 * deletion request publishes its fence.
 */
function ll_tools_grade_delivery_user_write_allowed_under_lock(int $user_id): bool {
    global $wpdb;

    if (
        $user_id <= 0
        || !function_exists('ll_tools_privacy_deleted_user_lms_cleanup_option_name')
        || !function_exists('ll_tools_privacy_user_lms_erasure_option_name')
    ) {
        return false;
    }
    $tombstone_name = ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id);
    $erasure_name = ll_tools_privacy_user_lms_erasure_option_name($user_id);
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value
         FROM {$wpdb->options}
         WHERE option_name IN (%s, %s)
         ORDER BY option_name ASC
         FOR UPDATE",
        $tombstone_name,
        $erasure_name
    ), ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== '') {
        return false;
    }

    foreach ($rows as $row) {
        $option_name = (string) ($row['option_name'] ?? '');
        if ($option_name === $tombstone_name) {
            return false;
        }
        if ($option_name !== $erasure_name) {
            continue;
        }
        $value = maybe_unserialize($row['option_value'] ?? 0);
        if (is_array($value)) {
            if ((int) ($value['fence_expires_at'] ?? 0) >= time()) {
                return false;
            }
            continue;
        }
        $started_at = (int) $value;
        $ttl = function_exists('ll_tools_privacy_user_lms_erasure_fence_ttl')
            ? ll_tools_privacy_user_lms_erasure_fence_ttl($user_id)
            : 30 * MINUTE_IN_SECONDS;
        if ($started_at > 0 && $started_at >= time() - $ttl) {
            return false;
        }
    }
    return true;
}

function ll_tools_grade_delivery_write_error(string $code): WP_Error {
    return new WP_Error(
        $code,
        __('External grade delivery is temporarily unavailable while storage is being prepared.', 'll-tools-text-domain'),
        ['status' => 503, 'retryable' => true]
    );
}

/** Reject loosely shaped provider mappings before any callback or write. */
function ll_tools_grade_delivery_has_exact_keys(array $value, array $keys): bool {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    return $actual === $keys;
}

/** @return int|WP_Error */
function ll_tools_grade_delivery_create_external_identity(array $mapping) {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
        return function_exists('ll_tools_lms_assignment_schema_error')
            ? ll_tools_lms_assignment_schema_error()
            : new WP_Error('lms_assignment_schema_unavailable', __('Assignments are temporarily unavailable.', 'll-tools-text-domain'));
    }
    if (!ll_tools_grade_delivery_has_exact_keys($mapping, ['adapter', 'connection_key_hash', 'subject_key_hash', 'learner_user_id'])) {
        return new WP_Error('invalid_external_identity_mapping', __('The external identity mapping is invalid.', 'll-tools-text-domain'));
    }
    $canonical = [
        'adapter' => ll_tools_grade_delivery_adapter_id($mapping['adapter']),
        'connection_key_hash' => ll_tools_grade_delivery_mapping_hash($mapping['connection_key_hash']),
        'subject_key_hash' => ll_tools_grade_delivery_mapping_hash($mapping['subject_key_hash']),
        'learner_user_id' => is_int($mapping['learner_user_id']) ? $mapping['learner_user_id'] : 0,
    ];
    if (
        $canonical['adapter'] === ''
        || $canonical['connection_key_hash'] === ''
        || $canonical['subject_key_hash'] === ''
        || !ll_tools_grade_delivery_user_write_allowed($canonical['learner_user_id'])
    ) {
        return new WP_Error('invalid_external_identity_mapping', __('The external identity mapping is invalid.', 'll-tools-text-domain'));
    }
    $validated = ll_tools_grade_delivery_validate_identity_mapping($canonical);
    if (is_wp_error($validated)) {
        return $validated;
    }

    if (
        !function_exists('ll_tools_lms_assignment_begin_transaction')
        || !function_exists('ll_tools_lms_assignment_commit_transaction')
        || !function_exists('ll_tools_lms_assignment_rollback_transaction')
        || !function_exists('ll_tools_lms_assignment_lock_user')
    ) {
        return ll_tools_grade_delivery_write_error('external_identity_mapping_write_failed');
    }
    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return ll_tools_grade_delivery_write_error('external_identity_mapping_write_failed');
    }

    $table = ll_tools_grade_delivery_table_names()['identities'];
    try {
        if (
            !ll_tools_lms_assignment_lock_user($canonical['learner_user_id'])
            || !ll_tools_grade_delivery_user_write_allowed_under_lock($canonical['learner_user_id'])
        ) {
            throw new DomainException('invalid_external_identity_mapping');
        }

        $wpdb->last_error = '';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, learner_user_id, status FROM {$table} WHERE adapter = %s AND connection_key_hash = %s AND subject_key_hash = %s LIMIT 1 FOR UPDATE",
            $canonical['adapter'],
            $canonical['connection_key_hash'],
            $canonical['subject_key_hash']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('external_identity_mapping_read_failed');
        }
        if (is_array($existing)) {
            if (
                (int) ($existing['learner_user_id'] ?? 0) !== $canonical['learner_user_id']
                || (string) ($existing['status'] ?? '') !== 'active'
            ) {
                throw new DomainException('external_identity_mapping_conflict');
            }
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('external_identity_mapping_commit_failed');
            }
            return (int) $existing['id'];
        }

        $now = ll_tools_grade_delivery_now_mysql();
        $inserted = $wpdb->insert($table, [
            'adapter' => $canonical['adapter'],
            'connection_key_hash' => $canonical['connection_key_hash'],
            'subject_key_hash' => $canonical['subject_key_hash'],
            'learner_user_id' => $canonical['learner_user_id'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        if ($inserted === 1) {
            $identity_id = (int) $wpdb->insert_id;
            if ($identity_id <= 0 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('external_identity_mapping_commit_failed');
            }
            return $identity_id;
        }

        // A writer that does not yet honor the learner lock may have won the
        // unique mapping race. Only accept the exact active mapping.
        $wpdb->last_error = '';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, learner_user_id, status FROM {$table} WHERE adapter = %s AND connection_key_hash = %s AND subject_key_hash = %s LIMIT 1 FOR UPDATE",
            $canonical['adapter'],
            $canonical['connection_key_hash'],
            $canonical['subject_key_hash']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('external_identity_mapping_read_failed');
        }
        if (
            !is_array($existing)
            || (int) ($existing['learner_user_id'] ?? 0) !== $canonical['learner_user_id']
            || (string) ($existing['status'] ?? '') !== 'active'
        ) {
            throw is_array($existing)
                ? new DomainException('external_identity_mapping_conflict')
                : new RuntimeException('external_identity_mapping_write_failed');
        }
        if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('external_identity_mapping_commit_failed');
        }
        return (int) $existing['id'];
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        if ($error->getMessage() === 'external_identity_mapping_conflict') {
            return new WP_Error('external_identity_mapping_conflict', __('The external identity is already mapped differently.', 'll-tools-text-domain'));
        }
        return new WP_Error('invalid_external_identity_mapping', __('The external identity mapping is invalid.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return ll_tools_grade_delivery_write_error('external_identity_mapping_write_failed');
    }
}

/** @return int|WP_Error */
function ll_tools_grade_delivery_create_destination(array $mapping) {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
        return function_exists('ll_tools_lms_assignment_schema_error')
            ? ll_tools_lms_assignment_schema_error()
            : new WP_Error('lms_assignment_schema_unavailable', __('Assignments are temporarily unavailable.', 'll-tools-text-domain'));
    }
    if (!ll_tools_grade_delivery_has_exact_keys($mapping, ['adapter', 'connection_key_hash', 'destination_key_hash', 'assignment_id', 'revision_id'])) {
        return new WP_Error('invalid_grade_destination_mapping', __('The external grade destination mapping is invalid.', 'll-tools-text-domain'));
    }
    $canonical = [
        'adapter' => ll_tools_grade_delivery_adapter_id($mapping['adapter']),
        'connection_key_hash' => ll_tools_grade_delivery_mapping_hash($mapping['connection_key_hash']),
        'destination_key_hash' => ll_tools_grade_delivery_mapping_hash($mapping['destination_key_hash']),
        'assignment_id' => is_int($mapping['assignment_id']) ? $mapping['assignment_id'] : 0,
        'revision_id' => is_int($mapping['revision_id']) ? $mapping['revision_id'] : 0,
    ];
    if (
        $canonical['adapter'] === ''
        || $canonical['connection_key_hash'] === ''
        || $canonical['destination_key_hash'] === ''
        || $canonical['assignment_id'] <= 0
        || $canonical['revision_id'] <= 0
    ) {
        return new WP_Error('invalid_grade_destination_mapping', __('The external grade destination mapping is invalid.', 'll-tools-text-domain'));
    }

    $assignment_tables = ll_tools_lms_assignment_table_names();
    $revision_exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignment_tables['revisions']} WHERE id = %d AND assignment_id = %d",
        $canonical['revision_id'],
        $canonical['assignment_id']
    ));
    if ($revision_exists !== 1) {
        return new WP_Error('grade_destination_assignment_mismatch', __('The grade destination does not match an exact assignment revision.', 'll-tools-text-domain'));
    }
    $validated = ll_tools_grade_delivery_validate_destination_mapping($canonical);
    if (is_wp_error($validated)) {
        return $validated;
    }

    $table = ll_tools_grade_delivery_table_names()['destinations'];
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, assignment_id, revision_id, status FROM {$table} WHERE adapter = %s AND connection_key_hash = %s AND destination_key_hash = %s LIMIT 1",
        $canonical['adapter'],
        $canonical['connection_key_hash'],
        $canonical['destination_key_hash']
    ), ARRAY_A);
    if (is_array($existing)) {
        if (
            (int) ($existing['assignment_id'] ?? 0) === $canonical['assignment_id']
            && (int) ($existing['revision_id'] ?? 0) === $canonical['revision_id']
            && (string) ($existing['status'] ?? '') === 'active'
        ) {
            return (int) $existing['id'];
        }
        return new WP_Error('grade_destination_mapping_conflict', __('The external grade destination is already mapped differently.', 'll-tools-text-domain'));
    }
    $limit = LL_TOOLS_GRADE_DELIVERY_MAX_DESTINATIONS_PER_GRADE;
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE assignment_id = %d AND revision_id = %d AND status = 'active'",
        $canonical['assignment_id'],
        $canonical['revision_id']
    ));
    if ($count >= $limit) {
        return new WP_Error('grade_destination_limit', __('This assignment has too many external grade destinations.', 'll-tools-text-domain'));
    }

    $now = ll_tools_grade_delivery_now_mysql();
    $inserted = $wpdb->insert($table, [
        'adapter' => $canonical['adapter'],
        'connection_key_hash' => $canonical['connection_key_hash'],
        'destination_key_hash' => $canonical['destination_key_hash'],
        'assignment_id' => $canonical['assignment_id'],
        'revision_id' => $canonical['revision_id'],
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
    if ($inserted !== false) {
        return (int) $wpdb->insert_id;
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, assignment_id, revision_id, status FROM {$table} WHERE adapter = %s AND connection_key_hash = %s AND destination_key_hash = %s LIMIT 1",
        $canonical['adapter'],
        $canonical['connection_key_hash'],
        $canonical['destination_key_hash']
    ), ARRAY_A);
    if (
        is_array($existing)
        && (int) ($existing['assignment_id'] ?? 0) === $canonical['assignment_id']
        && (int) ($existing['revision_id'] ?? 0) === $canonical['revision_id']
        && (string) ($existing['status'] ?? '') === 'active'
    ) {
        return (int) $existing['id'];
    }
    return new WP_Error('grade_destination_mapping_conflict', __('The external grade destination is already mapped differently.', 'll-tools-text-domain'));
}

/** @return int|WP_Error */
function ll_tools_grade_delivery_create_recipient(array $mapping) {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
        return function_exists('ll_tools_lms_assignment_schema_error')
            ? ll_tools_lms_assignment_schema_error()
            : new WP_Error('lms_assignment_schema_unavailable', __('Assignments are temporarily unavailable.', 'll-tools-text-domain'));
    }
    if (!ll_tools_grade_delivery_has_exact_keys($mapping, ['destination_id', 'external_identity_id', 'learner_user_id', 'recipient_key_hash'])) {
        return new WP_Error('invalid_grade_recipient_mapping', __('The external grade recipient mapping is invalid.', 'll-tools-text-domain'));
    }
    $canonical = [
        'destination_id' => is_int($mapping['destination_id']) ? $mapping['destination_id'] : 0,
        'external_identity_id' => is_int($mapping['external_identity_id']) ? $mapping['external_identity_id'] : 0,
        'learner_user_id' => is_int($mapping['learner_user_id']) ? $mapping['learner_user_id'] : 0,
        'recipient_key_hash' => ll_tools_grade_delivery_mapping_hash($mapping['recipient_key_hash']),
    ];
    if (
        $canonical['destination_id'] <= 0
        || $canonical['external_identity_id'] <= 0
        || !ll_tools_grade_delivery_user_write_allowed($canonical['learner_user_id'])
        || $canonical['recipient_key_hash'] === ''
    ) {
        return new WP_Error('invalid_grade_recipient_mapping', __('The external grade recipient mapping is invalid.', 'll-tools-text-domain'));
    }

    $tables = ll_tools_grade_delivery_table_names();
    $destination = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['destinations']} WHERE id = %d AND status = 'active' LIMIT 1",
        $canonical['destination_id']
    ), ARRAY_A);
    $identity = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['identities']} WHERE id = %d AND status = 'active' LIMIT 1",
        $canonical['external_identity_id']
    ), ARRAY_A);
    if (!is_array($destination) || !is_array($identity)) {
        return new WP_Error('grade_recipient_mapping_missing', __('The external grade recipient mapping is incomplete.', 'll-tools-text-domain'));
    }
    if (
        (string) ($destination['adapter'] ?? '') !== (string) ($identity['adapter'] ?? '')
        || !hash_equals((string) ($destination['connection_key_hash'] ?? ''), (string) ($identity['connection_key_hash'] ?? ''))
        || (int) ($identity['learner_user_id'] ?? 0) !== $canonical['learner_user_id']
    ) {
        return new WP_Error('grade_recipient_scope_mismatch', __('The external grade recipient does not match the destination scope.', 'll-tools-text-domain'));
    }
    $validation_mapping = $canonical + [
        'adapter' => (string) $destination['adapter'],
        'connection_key_hash' => (string) $destination['connection_key_hash'],
        'destination_key_hash' => (string) $destination['destination_key_hash'],
        'subject_key_hash' => (string) $identity['subject_key_hash'],
        'assignment_id' => (int) $destination['assignment_id'],
        'revision_id' => (int) $destination['revision_id'],
    ];
    $validated = ll_tools_grade_delivery_validate_recipient_mapping($validation_mapping);
    if (is_wp_error($validated)) {
        return $validated;
    }

    if (
        !function_exists('ll_tools_lms_assignment_begin_transaction')
        || !function_exists('ll_tools_lms_assignment_commit_transaction')
        || !function_exists('ll_tools_lms_assignment_rollback_transaction')
        || !function_exists('ll_tools_lms_assignment_lock_user')
    ) {
        return ll_tools_grade_delivery_write_error('grade_recipient_mapping_write_failed');
    }
    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return ll_tools_grade_delivery_write_error('grade_recipient_mapping_write_failed');
    }

    try {
        if (
            !ll_tools_lms_assignment_lock_user($canonical['learner_user_id'])
            || !ll_tools_grade_delivery_user_write_allowed_under_lock($canonical['learner_user_id'])
        ) {
            throw new DomainException('invalid_grade_recipient_mapping');
        }

        // Privacy erasure uses the same learner-row lock before removing these
        // mappings. Re-read them after acquiring it, without invoking the
        // provider validator a second time while the transaction is open.
        $wpdb->last_error = '';
        $locked_destination = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['destinations']} WHERE id = %d AND status = 'active' LIMIT 1 FOR UPDATE",
            $canonical['destination_id']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('grade_recipient_mapping_read_failed');
        }
        $wpdb->last_error = '';
        $locked_identity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['identities']} WHERE id = %d AND status = 'active' LIMIT 1 FOR UPDATE",
            $canonical['external_identity_id']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('grade_recipient_mapping_read_failed');
        }
        if (!is_array($locked_destination) || !is_array($locked_identity)) {
            throw new DomainException('grade_recipient_mapping_missing');
        }
        $locked_validation_mapping = $canonical + [
            'adapter' => (string) $locked_destination['adapter'],
            'connection_key_hash' => (string) $locked_destination['connection_key_hash'],
            'destination_key_hash' => (string) $locked_destination['destination_key_hash'],
            'subject_key_hash' => (string) $locked_identity['subject_key_hash'],
            'assignment_id' => (int) $locked_destination['assignment_id'],
            'revision_id' => (int) $locked_destination['revision_id'],
        ];
        if (
            (string) ($locked_destination['adapter'] ?? '') !== (string) ($locked_identity['adapter'] ?? '')
            || !hash_equals((string) ($locked_destination['connection_key_hash'] ?? ''), (string) ($locked_identity['connection_key_hash'] ?? ''))
            || (int) ($locked_identity['learner_user_id'] ?? 0) !== $canonical['learner_user_id']
            || $locked_validation_mapping !== $validation_mapping
        ) {
            throw new DomainException('grade_recipient_scope_mismatch');
        }

        $wpdb->last_error = '';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, external_identity_id, recipient_key_hash, status FROM {$tables['recipients']} WHERE destination_id = %d AND learner_user_id = %d LIMIT 1 FOR UPDATE",
            $canonical['destination_id'],
            $canonical['learner_user_id']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('grade_recipient_mapping_read_failed');
        }
        if (is_array($existing)) {
            if (
                (int) ($existing['external_identity_id'] ?? 0) !== $canonical['external_identity_id']
                || !hash_equals((string) ($existing['recipient_key_hash'] ?? ''), $canonical['recipient_key_hash'])
                || (string) ($existing['status'] ?? '') !== 'active'
            ) {
                throw new DomainException('grade_recipient_mapping_conflict');
            }
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('grade_recipient_mapping_commit_failed');
            }
            return (int) $existing['id'];
        }

        $now = ll_tools_grade_delivery_now_mysql();
        $inserted = $wpdb->insert($tables['recipients'], [
            'destination_id' => $canonical['destination_id'],
            'external_identity_id' => $canonical['external_identity_id'],
            'learner_user_id' => $canonical['learner_user_id'],
            'recipient_key_hash' => $canonical['recipient_key_hash'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s']);
        if ($inserted === 1) {
            $recipient_id = (int) $wpdb->insert_id;
            if ($recipient_id <= 0 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('grade_recipient_mapping_commit_failed');
            }
            return $recipient_id;
        }

        $wpdb->last_error = '';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, external_identity_id, recipient_key_hash, status FROM {$tables['recipients']} WHERE destination_id = %d AND learner_user_id = %d LIMIT 1 FOR UPDATE",
            $canonical['destination_id'],
            $canonical['learner_user_id']
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('grade_recipient_mapping_read_failed');
        }
        if (
            !is_array($existing)
            || (int) ($existing['external_identity_id'] ?? 0) !== $canonical['external_identity_id']
            || !hash_equals((string) ($existing['recipient_key_hash'] ?? ''), $canonical['recipient_key_hash'])
            || (string) ($existing['status'] ?? '') !== 'active'
        ) {
            throw is_array($existing)
                ? new DomainException('grade_recipient_mapping_conflict')
                : new RuntimeException('grade_recipient_mapping_write_failed');
        }
        if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('grade_recipient_mapping_commit_failed');
        }
        return (int) $existing['id'];
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        $code = $error->getMessage();
        if ($code === 'grade_recipient_mapping_missing') {
            return new WP_Error($code, __('The external grade recipient mapping is incomplete.', 'll-tools-text-domain'));
        }
        if ($code === 'grade_recipient_scope_mismatch') {
            return new WP_Error($code, __('The external grade recipient does not match the destination scope.', 'll-tools-text-domain'));
        }
        if ($code === 'grade_recipient_mapping_conflict') {
            return new WP_Error($code, __('The external grade recipient is already mapped differently.', 'll-tools-text-domain'));
        }
        return new WP_Error('invalid_grade_recipient_mapping', __('The external grade recipient mapping is invalid.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return ll_tools_grade_delivery_write_error('grade_recipient_mapping_write_failed');
    }
}

/** @return array<string,mixed>|WP_Error */
function ll_tools_grade_delivery_normalize_grade_row($row, int $assignment_id, int $revision_id, int $user_id, int $grade_revision) {
    if (!is_array($row)) {
        return new WP_Error('selected_grade_missing', __('The selected assignment grade does not exist.', 'll-tools-text-domain'));
    }
    foreach (['assignment_id' => $assignment_id, 'revision_id' => $revision_id, 'user_id' => $user_id, 'grade_revision' => $grade_revision] as $key => $expected) {
        if ((int) ($row[$key] ?? 0) !== $expected || $expected <= 0) {
            return new WP_Error('selected_grade_mismatch', __('The selected assignment grade changed before delivery could be queued.', 'll-tools-text-domain'));
        }
    }
    foreach (['score_given', 'score_maximum'] as $key) {
        if (!isset($row[$key]) || preg_match('/^\d+$/D', (string) $row[$key]) !== 1) {
            return new WP_Error('invalid_selected_grade', __('The selected assignment grade is invalid.', 'll-tools-text-domain'));
        }
    }
    $score_given = (int) $row['score_given'];
    $score_maximum = (int) $row['score_maximum'];
    $score_limit = defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS')
        ? (int) LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS
        : 200;
    if ($score_maximum <= 0 || $score_maximum > $score_limit || $score_given < 0 || $score_given > $score_maximum) {
        return new WP_Error('invalid_selected_grade', __('The selected assignment grade is invalid.', 'll-tools-text-domain'));
    }
    foreach (['points_given', 'points_maximum'] as $key) {
        if (!isset($row[$key]) || preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D', (string) $row[$key]) !== 1) {
            return new WP_Error('invalid_selected_grade', __('The selected assignment grade is invalid.', 'll-tools-text-domain'));
        }
    }
    $points_given = (float) $row['points_given'];
    $points_maximum = (float) $row['points_maximum'];
    if (
        !is_finite($points_given)
        || !is_finite($points_maximum)
        || $points_maximum <= 0
        || $points_maximum > 1000000
        || $points_given < 0
        || $points_given > $points_maximum
    ) {
        return new WP_Error('invalid_selected_grade', __('The selected assignment grade is invalid.', 'll-tools-text-domain'));
    }

    return [
        'assignment_id' => $assignment_id,
        'revision_id' => $revision_id,
        'user_id' => $user_id,
        'grade_revision' => $grade_revision,
        'score_given' => $score_given,
        'score_maximum' => $score_maximum,
        'points_given' => number_format($points_given, 4, '.', ''),
        'points_maximum' => number_format($points_maximum, 4, '.', ''),
    ];
}

/**
 * Enqueue the current selected grade for every exact active recipient mapping.
 *
 * This function is designed to run inside the assignment finalizer's existing
 * transaction. It performs no cron write; call
 * ll_tools_grade_delivery_schedule_worker() only after that transaction commits.
 * Having no mapped destination or recipient is a successful no-op.
 *
 * @return array{enqueued:int,duplicates:int,superseded:int,delivery_ids:array<int,int>}|WP_Error
 */
function ll_tools_grade_delivery_enqueue_current_grade(
    int $assignment_id,
    int $revision_id,
    int $user_id,
    int $grade_revision
) {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
        return function_exists('ll_tools_lms_assignment_schema_error')
            ? ll_tools_lms_assignment_schema_error()
            : new WP_Error('lms_assignment_schema_unavailable', __('Assignments are temporarily unavailable.', 'll-tools-text-domain'));
    }
    if (
        $assignment_id <= 0
        || $revision_id <= 0
        || $grade_revision <= 0
        || !ll_tools_grade_delivery_user_write_allowed($user_id)
    ) {
        return new WP_Error('invalid_selected_grade_key', __('The selected assignment grade key is invalid.', 'll-tools-text-domain'));
    }

    $assignment_tables = ll_tools_lms_assignment_table_names();
    $wpdb->last_error = '';
    $grade_row = $wpdb->get_row($wpdb->prepare(
        "SELECT assignment_id, revision_id, user_id, grade_revision, score_given, score_maximum, points_given, points_maximum
         FROM {$assignment_tables['grades']}
         WHERE assignment_id = %d AND revision_id = %d AND user_id = %d
         LIMIT 1",
        $assignment_id,
        $revision_id,
        $user_id
    ), ARRAY_A);
    if ((string) $wpdb->last_error !== '') {
        return new WP_Error('selected_grade_read_failed', __('The selected assignment grade could not be read.', 'll-tools-text-domain'));
    }
    $grade = ll_tools_grade_delivery_normalize_grade_row($grade_row, $assignment_id, $revision_id, $user_id, $grade_revision);
    if (is_wp_error($grade)) {
        return $grade;
    }

    $tables = ll_tools_grade_delivery_table_names();
    $limit = LL_TOOLS_GRADE_DELIVERY_MAX_DESTINATIONS_PER_GRADE;
    $wpdb->last_error = '';
    $destinations = $wpdb->get_results($wpdb->prepare(
        "SELECT id, adapter, connection_key_hash, destination_key_hash
         FROM {$tables['destinations']}
         WHERE assignment_id = %d AND revision_id = %d AND status = 'active'
         ORDER BY id ASC
         LIMIT %d",
        $assignment_id,
        $revision_id,
        $limit + 1
    ), ARRAY_A);
    if (!is_array($destinations) || (string) $wpdb->last_error !== '') {
        return new WP_Error('grade_destination_read_failed', __('External grade destinations could not be read.', 'll-tools-text-domain'));
    }
    if (count($destinations) > $limit) {
        return new WP_Error('grade_destination_limit', __('This assignment has too many external grade destinations.', 'll-tools-text-domain'));
    }

    $result = ['enqueued' => 0, 'duplicates' => 0, 'superseded' => 0, 'delivery_ids' => []];
    if ($destinations === []) {
        return $result;
    }
    $now = ll_tools_grade_delivery_now_mysql();
    foreach ($destinations as $destination) {
        $destination_id = (int) ($destination['id'] ?? 0);
        $adapter = ll_tools_grade_delivery_adapter_id($destination['adapter'] ?? null);
        if ($destination_id <= 0 || $adapter === '') {
            return new WP_Error('invalid_grade_destination_row', __('An external grade destination is invalid.', 'll-tools-text-domain'));
        }
        $recipient = $wpdb->get_row($wpdb->prepare(
            "SELECT id, external_identity_id, learner_user_id, recipient_key_hash
             FROM {$tables['recipients']}
             WHERE destination_id = %d AND learner_user_id = %d AND status = 'active'
             LIMIT 1",
            $destination_id,
            $user_id
        ), ARRAY_A);
        if (!is_array($recipient)) {
            continue;
        }

        $highest_revision = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(grade_revision), 0) FROM {$tables['deliveries']} WHERE destination_id = %d AND learner_user_id = %d",
            $destination_id,
            $user_id
        ));
        if ($highest_revision > $grade_revision) {
            return new WP_Error('grade_delivery_revision_regression', __('An older grade revision cannot replace a newer queued grade.', 'll-tools-text-domain'));
        }

        $superseded = $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['deliveries']}
             SET status = 'superseded', superseded_at = %s, updated_at = %s
             WHERE destination_id = %d AND learner_user_id = %d AND grade_revision < %d
               AND status IN ('pending','retry','processing','permanent_failed')",
            $now,
            $now,
            $destination_id,
            $user_id,
            $grade_revision
        ));
        if ($superseded === false) {
            return new WP_Error('grade_delivery_supersede_failed', __('An older external grade delivery could not be superseded.', 'll-tools-text-domain'));
        }
        $result['superseded'] += (int) $superseded;

        $dedupe_key = hash('sha256', implode('|', [
            'll-tools-grade-delivery-v1',
            $adapter,
            (string) $destination_id,
            (string) $grade_revision,
            (string) $user_id,
        ]));
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, adapter, destination_id, learner_user_id, grade_revision FROM {$tables['deliveries']} WHERE dedupe_key = %s LIMIT 1",
            $dedupe_key
        ), ARRAY_A);
        if (is_array($existing)) {
            if (
                (string) ($existing['adapter'] ?? '') !== $adapter
                || (int) ($existing['destination_id'] ?? 0) !== $destination_id
                || (int) ($existing['learner_user_id'] ?? 0) !== $user_id
                || (int) ($existing['grade_revision'] ?? 0) !== $grade_revision
            ) {
                return new WP_Error('grade_delivery_dedupe_collision', __('The external grade delivery key conflicts with another delivery.', 'll-tools-text-domain'));
            }
            $result['duplicates']++;
            $result['delivery_ids'][] = (int) $existing['id'];
            continue;
        }
        $inserted = $wpdb->insert($tables['deliveries'], [
            'adapter' => $adapter,
            'destination_id' => $destination_id,
            'recipient_id' => (int) $recipient['id'],
            'assignment_id' => $assignment_id,
            'revision_id' => $revision_id,
            'learner_user_id' => $user_id,
            'grade_revision' => $grade_revision,
            'score_given' => $grade['score_given'],
            'score_maximum' => $grade['score_maximum'],
            'points_given' => $grade['points_given'],
            'points_maximum' => $grade['points_maximum'],
            'dedupe_key' => $dedupe_key,
            'status' => 'pending',
            'available_at' => $now,
            'attempt_count' => 0,
            'lease_token' => '',
            'last_http_status' => 0,
            'last_error_code' => '',
            'last_diagnostic' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
        if ($inserted !== false) {
            $delivery_id = (int) $wpdb->insert_id;
            $result['enqueued']++;
            $result['delivery_ids'][] = $delivery_id;
            continue;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, adapter, destination_id, learner_user_id, grade_revision FROM {$tables['deliveries']} WHERE dedupe_key = %s LIMIT 1",
            $dedupe_key
        ), ARRAY_A);
        if (
            !is_array($existing)
            || (string) ($existing['adapter'] ?? '') !== $adapter
            || (int) ($existing['destination_id'] ?? 0) !== $destination_id
            || (int) ($existing['learner_user_id'] ?? 0) !== $user_id
            || (int) ($existing['grade_revision'] ?? 0) !== $grade_revision
        ) {
            return new WP_Error('grade_delivery_enqueue_failed', __('The external grade delivery could not be queued.', 'll-tools-text-domain'));
        }
        $result['duplicates']++;
        $result['delivery_ids'][] = (int) $existing['id'];
    }

    return $result;
}

/** Schedule the bounded worker after the caller's grade transaction commits. */
function ll_tools_grade_delivery_schedule_worker(int $delay = 0): bool {
    $delay = max(0, min(DAY_IN_SECONDS, $delay));
    $timestamp = time() + $delay;
    $next = wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
    if ($next !== false && (int) $next <= $timestamp) {
        return true;
    }
    return wp_schedule_single_event($timestamp, LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK) !== false;
}

/**
 * Recover a durable outbox whose earlier post-commit cron write failed.
 * Marker-only readiness and a short throttle keep ordinary requests cheap.
 */
function ll_tools_grade_delivery_maybe_resume_worker(): void {
    if (
        empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])
        || wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK) !== false
        || get_transient(LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT)
    ) {
        return;
    }
    set_transient(LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
    ll_tools_grade_delivery_schedule_next_due();
}

function ll_tools_grade_delivery_lease_seconds(): int {
    return max(30, min(5 * MINUTE_IN_SECONDS, (int) apply_filters('ll_tools_grade_delivery_lease_seconds', 120)));
}

function ll_tools_grade_delivery_lease_token(): string {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        return hash('sha256', wp_generate_uuid4() . '|' . wp_rand() . '|' . microtime(true));
    }
}

/**
 * Atomically acquire one due delivery. An expired processing lease may be
 * taken over, while a live lease for the same destination/learner serializes
 * corrected grades until its exact owner releases or expires.
 *
 * @return array<string,mixed>|WP_Error|null
 */
function ll_tools_grade_delivery_claim_next(string $lease_token, int $lease_seconds = 0) {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    $lease_token = ll_tools_grade_delivery_mapping_hash($lease_token);
    if ($lease_token === '') {
        return new WP_Error('invalid_grade_delivery_lease', __('The grade delivery lease is invalid.', 'll-tools-text-domain'));
    }
    $lease_seconds = $lease_seconds > 0
        ? max(30, min(5 * MINUTE_IN_SECONDS, $lease_seconds))
        : ll_tools_grade_delivery_lease_seconds();
    $now_timestamp = ll_tools_grade_delivery_now();
    $now = gmdate('Y-m-d H:i:s', $now_timestamp);
    $expires_at = gmdate('Y-m-d H:i:s', $now_timestamp + $lease_seconds);
    $table = ll_tools_grade_delivery_table_names()['deliveries'];

    $wpdb->last_error = '';
    if ($wpdb->query('START TRANSACTION') === false) {
        return new WP_Error('grade_delivery_claim_failed', __('A grade delivery could not be claimed.', 'll-tools-text-domain'));
    }
    try {
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT d.*
             FROM {$table} d
             WHERE (
                 (
                     d.status IN ('pending','retry')
                     AND d.available_at <= %s
                     AND (d.lease_token = '' OR d.lease_expires_at IS NULL OR d.lease_expires_at <= %s)
                 )
                 OR (d.status = 'processing' AND d.lease_expires_at IS NOT NULL AND d.lease_expires_at <= %s)
             )
             AND NOT EXISTS (
                 SELECT 1
                 FROM {$table} active
                 WHERE active.destination_id = d.destination_id
                   AND active.learner_user_id = d.learner_user_id
                   AND active.id <> d.id
                   AND active.lease_token <> ''
                   AND active.lease_expires_at IS NOT NULL
                   AND active.lease_expires_at > %s
             )
             ORDER BY d.available_at ASC, d.id ASC
             LIMIT 1
             FOR UPDATE",
            $now,
            $now,
            $now,
            $now
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('claim select failed');
        }
        if (!is_array($candidate)) {
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('claim commit failed');
            }
            return null;
        }

        $delivery_id = (int) ($candidate['id'] ?? 0);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'processing', lease_token = %s, lease_expires_at = %s,
                 attempt_count = attempt_count + 1, updated_at = %s
             WHERE id = %d
               AND (
                   (status IN ('pending','retry') AND available_at <= %s AND (lease_token = '' OR lease_expires_at IS NULL OR lease_expires_at <= %s))
                   OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s)
               )",
            $lease_token,
            $expires_at,
            $now,
            $delivery_id,
            $now,
            $now,
            $now
        ));
        if ($updated !== 1) {
            throw new RuntimeException('claim update failed');
        }
        $claimed = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'processing' AND lease_token = %s LIMIT 1",
            $delivery_id,
            $lease_token
        ), ARRAY_A);
        if (!is_array($claimed) || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('claim readback failed');
        }
        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException('claim commit failed');
        }
        return $claimed;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('grade_delivery_claim_failed', __('A grade delivery could not be claimed.', 'll-tools-text-domain'));
    }
}

/** Clear a lease only when the exact token still owns it. */
function ll_tools_grade_delivery_release_lease(int $delivery_id, string $lease_token): bool {
    global $wpdb;

    $lease_token = ll_tools_grade_delivery_mapping_hash($lease_token);
    if ($delivery_id <= 0 || $lease_token === '') {
        return false;
    }
    $table = ll_tools_grade_delivery_table_names()['deliveries'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d AND lease_token = %s LIMIT 1",
        $delivery_id,
        $lease_token
    ), ARRAY_A);
    if (!is_array($row)) {
        return false;
    }
    $status = (string) ($row['status'] ?? '');
    $next_status = $status === 'processing' ? 'retry' : $status;
    if ($next_status === '') {
        return false;
    }
    $now = ll_tools_grade_delivery_now_mysql();
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = %s, available_at = %s, lease_token = '', lease_expires_at = NULL, updated_at = %s
         WHERE id = %d AND lease_token = %s AND status = %s",
        $next_status,
        $now,
        $now,
        $delivery_id,
        $lease_token,
        $status
    ));
    return $updated === 1;
}

/** Supersede a stale claimed row without allowing a former owner to overwrite it. */
function ll_tools_grade_delivery_supersede_claim(int $delivery_id, string $lease_token): bool {
    global $wpdb;

    $lease_token = ll_tools_grade_delivery_mapping_hash($lease_token);
    if ($delivery_id <= 0 || $lease_token === '') {
        return false;
    }
    $table = ll_tools_grade_delivery_table_names()['deliveries'];
    $now = ll_tools_grade_delivery_now_mysql();
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = 'superseded', superseded_at = %s, updated_at = %s,
             lease_token = '', lease_expires_at = NULL
         WHERE id = %d AND status = 'processing' AND lease_token = %s",
        $now,
        $now,
        $delivery_id,
        $lease_token
    ));
    if ($updated === 1) {
        return true;
    }

    // Enqueue may have immediately changed the status while this owner was in
    // its adapter callback. It deliberately leaves the token as a serialization
    // fence; only that same owner may clear it here.
    $released = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET lease_token = '', lease_expires_at = NULL, updated_at = %s
         WHERE id = %d AND status = 'superseded' AND lease_token = %s",
        $now,
        $delivery_id,
        $lease_token
    ));
    return $released === 1;
}

function ll_tools_grade_delivery_error_code($value, string $fallback): string {
    $value = is_string($value) ? strtolower(trim($value)) : '';
    if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
        $value = $fallback;
    }
    return substr($value, 0, 64);
}

/** Redact common secrets, external identifiers, URLs, and direct PII. */
function ll_tools_grade_delivery_redact_diagnostic($value): string {
    if (!is_string($value) || $value === '') {
        return '';
    }
    $value = substr($value, 0, 4096);
    $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $value);
    $value = preg_replace('/\b(?:authorization|proxy-authorization)\s*:\s*[^\r\n]+/i', 'Authorization: [redacted]', $value);
    $value = preg_replace('/\b(?:bearer|basic)\s+[a-z0-9._~+\/-]+=*/i', '[redacted-authorization]', $value);
    $value = preg_replace('/\b[a-z0-9_-]{10,}\.[a-z0-9_-]{10,}\.[a-z0-9_-]{10,}\b/i', '[redacted-jwt]', $value);
    $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $value);
    $value = preg_replace('~https?://[^\s]+~i', '[redacted-url]', $value);
    $value = preg_replace(
        '/\b(access[_-]?token|refresh[_-]?token|id[_-]?token|api[_-]?key|client[_-]?secret|password|secret)\b\s*[:=]\s*["\']?[^\s,"\'}]+/i',
        '$1=[redacted]',
        $value
    );
    $value = preg_replace('/\b[a-f0-9]{32,}\b/i', '[redacted-value]', $value);
    $value = preg_replace('/\b[A-Za-z0-9_-]{40,}={0,2}\b/', '[redacted-value]', $value);
    $value = preg_replace('/\s+/', ' ', trim((string) $value));
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, 255, 'UTF-8');
    }
    return substr($value, 0, 255);
}

function ll_tools_grade_delivery_retry_cap_seconds(): int {
    return max(MINUTE_IN_SECONDS, min(DAY_IN_SECONDS, (int) apply_filters(
        'll_tools_grade_delivery_retry_cap_seconds',
        6 * HOUR_IN_SECONDS
    )));
}

/** Parse integer seconds or an HTTP-date without permitting an unbounded wait. */
function ll_tools_grade_delivery_parse_retry_after($value, int $now = 0): int {
    $cap = ll_tools_grade_delivery_retry_cap_seconds();
    if (is_int($value)) {
        return max(0, min($cap, $value));
    }
    if (!is_string($value)) {
        return 0;
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > 128) {
        return 0;
    }
    if (preg_match('/^\d{1,10}$/D', $value) === 1) {
        return max(0, min($cap, (int) $value));
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 0;
    }
    $now = $now > 0 ? $now : ll_tools_grade_delivery_now();
    return max(0, min($cap, $timestamp - $now));
}

/**
 * Classify the deliberately body-free adapter response contract.
 *
 * Accepted keys are exactly: type, http_status, error_code, diagnostic, and
 * retry_after. Unknown keys (including body, headers, or tokens) make the
 * result a permanent adapter-contract failure and are never persisted.
 *
 * @return array{outcome:string,http_status:int,error_code:string,diagnostic:string,retry_after:int}
 */
function ll_tools_grade_delivery_classify_adapter_response($response): array {
    $invalid = [
        'outcome' => 'permanent',
        'http_status' => 0,
        'error_code' => 'invalid_adapter_response',
        'diagnostic' => 'The adapter returned an invalid response contract.',
        'retry_after' => 0,
    ];
    if (!is_array($response)) {
        return $invalid;
    }
    $allowed_keys = ['type', 'http_status', 'error_code', 'diagnostic', 'retry_after'];
    foreach (array_keys($response) as $key) {
        if (!is_string($key) || !in_array($key, $allowed_keys, true)) {
            return $invalid;
        }
    }
    if (!isset($response['type']) || !is_string($response['type'])) {
        return $invalid;
    }
    if (
        array_key_exists('error_code', $response)
        && (
            !is_string($response['error_code'])
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $response['error_code']) !== 1
        )
    ) {
        return $invalid;
    }
    if (
        array_key_exists('diagnostic', $response)
        && (!is_string($response['diagnostic']) || strlen($response['diagnostic']) > 4096)
    ) {
        return $invalid;
    }
    if (array_key_exists('retry_after', $response)) {
        $retry_after_value = $response['retry_after'];
        if (!is_int($retry_after_value) && !is_string($retry_after_value)) {
            return $invalid;
        }
        if (is_int($retry_after_value) && $retry_after_value < 0) {
            return $invalid;
        }
        if (is_string($retry_after_value)) {
            $retry_after_value = trim($retry_after_value);
            if (
                $retry_after_value === ''
                || strlen($retry_after_value) > 128
                || (
                    preg_match('/^\d{1,10}$/D', $retry_after_value) !== 1
                    && strtotime($retry_after_value) === false
                )
            ) {
                return $invalid;
            }
        }
    }
    $type = (string) $response['type'];
    if (
        in_array($type, ['network_error', 'permanent_error'], true)
        && array_key_exists('http_status', $response)
    ) {
        return $invalid;
    }
    $diagnostic = ll_tools_grade_delivery_redact_diagnostic($response['diagnostic'] ?? '');
    $retry_after = ll_tools_grade_delivery_parse_retry_after($response['retry_after'] ?? null);

    if ($type === 'network_error') {
        return [
            'outcome' => 'retry',
            'http_status' => 0,
            'error_code' => ll_tools_grade_delivery_error_code($response['error_code'] ?? '', 'network_error'),
            'diagnostic' => $diagnostic,
            'retry_after' => $retry_after,
        ];
    }
    if ($type === 'permanent_error') {
        return [
            'outcome' => 'permanent',
            'http_status' => 0,
            'error_code' => ll_tools_grade_delivery_error_code($response['error_code'] ?? '', 'adapter_permanent_error'),
            'diagnostic' => $diagnostic,
            'retry_after' => 0,
        ];
    }
    if ($type !== 'http' || !is_int($response['http_status'] ?? null)) {
        return $invalid;
    }
    $http_status = (int) $response['http_status'];
    if ($http_status < 100 || $http_status > 599) {
        return $invalid;
    }
    if ($http_status >= 200 && $http_status <= 299) {
        return [
            'outcome' => 'success',
            'http_status' => $http_status,
            'error_code' => '',
            'diagnostic' => '',
            'retry_after' => 0,
        ];
    }
    $retryable = in_array($http_status, [408, 409, 425, 429], true)
        || ($http_status >= 500 && $http_status <= 599);
    $fallback = 'http_' . $http_status;
    return [
        'outcome' => $retryable ? 'retry' : 'permanent',
        'http_status' => $http_status,
        'error_code' => ll_tools_grade_delivery_error_code($response['error_code'] ?? '', $fallback),
        'diagnostic' => $diagnostic,
        'retry_after' => $retryable ? $retry_after : 0,
    ];
}

function ll_tools_grade_delivery_backoff_seconds(int $attempt_count, int $retry_after = 0, int $delivery_id = 0): int {
    $attempt_count = max(1, min(20, $attempt_count));
    $cap = ll_tools_grade_delivery_retry_cap_seconds();
    $base = max(5, min(5 * MINUTE_IN_SECONDS, (int) apply_filters('ll_tools_grade_delivery_retry_base_seconds', 30)));
    $exponent = min(10, $attempt_count - 1);
    $backoff = min($cap, $base * (2 ** $exponent));
    $jitter_ceiling = max(1, (int) floor($backoff * 0.15));
    $jitter = $delivery_id > 0
        ? (int) (abs(crc32($delivery_id . '|' . $attempt_count)) % ($jitter_ceiling + 1))
        : 0;
    $backoff = min($cap, $backoff + $jitter);
    return max($backoff, min($cap, max(0, $retry_after)));
}

/** @return string|WP_Error */
function ll_tools_grade_delivery_apply_claim_result(array $delivery, string $lease_token, array $classification) {
    global $wpdb;

    $delivery_id = (int) ($delivery['id'] ?? 0);
    $lease_token = ll_tools_grade_delivery_mapping_hash($lease_token);
    $outcome = (string) ($classification['outcome'] ?? '');
    if ($delivery_id <= 0 || $lease_token === '' || !in_array($outcome, ['success', 'retry', 'permanent'], true)) {
        return new WP_Error('invalid_grade_delivery_outcome', __('The grade delivery outcome is invalid.', 'll-tools-text-domain'));
    }
    $attempt_count = max(1, (int) ($delivery['attempt_count'] ?? 1));
    $http_status = max(0, min(599, (int) ($classification['http_status'] ?? 0)));
    $error_code = ll_tools_grade_delivery_error_code($classification['error_code'] ?? '', $outcome === 'success' ? 'delivered' : 'delivery_failed');
    $diagnostic = ll_tools_grade_delivery_redact_diagnostic($classification['diagnostic'] ?? '');
    $now_timestamp = ll_tools_grade_delivery_now();
    $now = gmdate('Y-m-d H:i:s', $now_timestamp);
    $table = ll_tools_grade_delivery_table_names()['deliveries'];

    $status = '';
    $available_at = $now;
    $delivered_at = null;
    if ($outcome === 'success') {
        $status = 'succeeded';
        $error_code = '';
        $diagnostic = '';
        $delivered_at = $now;
    } elseif ($outcome === 'retry' && $attempt_count < LL_TOOLS_GRADE_DELIVERY_MAX_ATTEMPTS) {
        $status = 'retry';
        $delay = ll_tools_grade_delivery_backoff_seconds(
            $attempt_count,
            max(0, (int) ($classification['retry_after'] ?? 0)),
            $delivery_id
        );
        $available_at = gmdate('Y-m-d H:i:s', $now_timestamp + $delay);
    } else {
        $status = 'permanent_failed';
        if ($outcome === 'retry') {
            $error_code = 'retry_exhausted';
            $diagnostic = 'The bounded grade delivery retry limit was reached.';
        }
    }

    $delivered_sql = $delivered_at === null
        ? 'NULL'
        : $wpdb->prepare('%s', $delivered_at);
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = %s, available_at = %s, lease_token = '', lease_expires_at = NULL,
             last_http_status = %d, last_error_code = %s, last_diagnostic = %s,
             updated_at = %s, delivered_at = {$delivered_sql}
         WHERE id = %d AND status = 'processing' AND lease_token = %s",
        $status,
        $available_at,
        $http_status,
        $error_code,
        $diagnostic,
        $now,
        $delivery_id,
        $lease_token
    ));
    if ($updated === 1) {
        return $status;
    }

    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT status, lease_token FROM {$table} WHERE id = %d LIMIT 1",
        $delivery_id
    ), ARRAY_A);
    if (
        is_array($current)
        && (string) ($current['status'] ?? '') === 'superseded'
        && hash_equals((string) ($current['lease_token'] ?? ''), $lease_token)
        && ll_tools_grade_delivery_supersede_claim($delivery_id, $lease_token)
    ) {
        return 'superseded';
    }
    return new WP_Error('grade_delivery_lease_lost', __('The grade delivery lease is no longer owned by this worker.', 'll-tools-text-domain'));
}

/** @return array{stale:bool,grade:array<string,mixed>}|WP_Error */
function ll_tools_grade_delivery_current_grade_status(array $delivery) {
    global $wpdb;

    if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
        return new WP_Error('lms_assignment_schema_unavailable', __('Assignments are temporarily unavailable.', 'll-tools-text-domain'));
    }
    $assignment_id = (int) ($delivery['assignment_id'] ?? 0);
    $revision_id = (int) ($delivery['revision_id'] ?? 0);
    $user_id = (int) ($delivery['learner_user_id'] ?? 0);
    $queued_revision = (int) ($delivery['grade_revision'] ?? 0);
    $grades_table = ll_tools_lms_assignment_table_names()['grades'];
    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT assignment_id, revision_id, user_id, grade_revision, score_given, score_maximum, points_given, points_maximum
         FROM {$grades_table}
         WHERE assignment_id = %d AND revision_id = %d AND user_id = %d
         LIMIT 1",
        $assignment_id,
        $revision_id,
        $user_id
    ), ARRAY_A);
    if ((string) $wpdb->last_error !== '') {
        return new WP_Error('selected_grade_read_failed', __('The selected assignment grade could not be read.', 'll-tools-text-domain'));
    }
    if (!is_array($row)) {
        return new WP_Error('selected_grade_missing', __('The selected assignment grade no longer exists.', 'll-tools-text-domain'));
    }
    $current_revision = (int) ($row['grade_revision'] ?? 0);
    $grade = ll_tools_grade_delivery_normalize_grade_row($row, $assignment_id, $revision_id, $user_id, $current_revision);
    if (is_wp_error($grade)) {
        return $grade;
    }
    if ($current_revision !== $queued_revision) {
        return ['stale' => true, 'grade' => $grade];
    }
    if (
        (int) ($delivery['score_given'] ?? -1) !== (int) $grade['score_given']
        || (int) ($delivery['score_maximum'] ?? -1) !== (int) $grade['score_maximum']
        || number_format((float) ($delivery['points_given'] ?? -1), 4, '.', '') !== (string) $grade['points_given']
        || number_format((float) ($delivery['points_maximum'] ?? -1), 4, '.', '') !== (string) $grade['points_maximum']
    ) {
        return new WP_Error('grade_delivery_snapshot_mismatch', __('The queued grade snapshot does not match its selected grade revision.', 'll-tools-text-domain'));
    }
    return ['stale' => false, 'grade' => $grade];
}

/** @return array<string,mixed>|WP_Error */
function ll_tools_grade_delivery_load_exact_mapping(array $delivery) {
    global $wpdb;

    $tables = ll_tools_grade_delivery_table_names();
    $destination = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['destinations']} WHERE id = %d AND status = 'active' LIMIT 1",
        (int) ($delivery['destination_id'] ?? 0)
    ), ARRAY_A);
    $recipient = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['recipients']} WHERE id = %d AND status = 'active' LIMIT 1",
        (int) ($delivery['recipient_id'] ?? 0)
    ), ARRAY_A);
    if (!is_array($destination) || !is_array($recipient)) {
        return new WP_Error('grade_delivery_mapping_missing', __('The external grade mapping no longer exists.', 'll-tools-text-domain'));
    }
    $identity = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['identities']} WHERE id = %d AND status = 'active' LIMIT 1",
        (int) ($recipient['external_identity_id'] ?? 0)
    ), ARRAY_A);
    if (!is_array($identity)) {
        return new WP_Error('grade_delivery_mapping_missing', __('The external grade mapping no longer exists.', 'll-tools-text-domain'));
    }
    $adapter = (string) ($delivery['adapter'] ?? '');
    $user_id = (int) ($delivery['learner_user_id'] ?? 0);
    if (
        ll_tools_grade_delivery_adapter_id($adapter) === ''
        || (string) ($destination['adapter'] ?? '') !== $adapter
        || (int) ($destination['assignment_id'] ?? 0) !== (int) ($delivery['assignment_id'] ?? 0)
        || (int) ($destination['revision_id'] ?? 0) !== (int) ($delivery['revision_id'] ?? 0)
        || (int) ($recipient['destination_id'] ?? 0) !== (int) ($destination['id'] ?? 0)
        || (int) ($recipient['learner_user_id'] ?? 0) !== $user_id
        || (string) ($identity['adapter'] ?? '') !== $adapter
        || (int) ($identity['learner_user_id'] ?? 0) !== $user_id
        || !hash_equals((string) ($destination['connection_key_hash'] ?? ''), (string) ($identity['connection_key_hash'] ?? ''))
    ) {
        return new WP_Error('grade_delivery_mapping_scope_mismatch', __('The external grade mapping does not match the queued grade scope.', 'll-tools-text-domain'));
    }

    return [
        'adapter' => $adapter,
        'destination' => $destination,
        'recipient' => $recipient,
        'identity' => $identity,
    ];
}

/** @return true|WP_Error */
function ll_tools_grade_delivery_revalidate_exact_mapping(array $mapping) {
    $adapter = (string) ($mapping['adapter'] ?? '');
    $destination = (array) ($mapping['destination'] ?? []);
    $recipient = (array) ($mapping['recipient'] ?? []);
    $identity = (array) ($mapping['identity'] ?? []);
    $identity_check = ll_tools_grade_delivery_validate_identity_mapping([
        'adapter' => $adapter,
        'connection_key_hash' => (string) ($identity['connection_key_hash'] ?? ''),
        'subject_key_hash' => (string) ($identity['subject_key_hash'] ?? ''),
        'learner_user_id' => (int) ($identity['learner_user_id'] ?? 0),
    ]);
    if (is_wp_error($identity_check)) {
        return $identity_check;
    }
    $destination_check = ll_tools_grade_delivery_validate_destination_mapping([
        'adapter' => $adapter,
        'connection_key_hash' => (string) ($destination['connection_key_hash'] ?? ''),
        'destination_key_hash' => (string) ($destination['destination_key_hash'] ?? ''),
        'assignment_id' => (int) ($destination['assignment_id'] ?? 0),
        'revision_id' => (int) ($destination['revision_id'] ?? 0),
    ]);
    if (is_wp_error($destination_check)) {
        return $destination_check;
    }
    return ll_tools_grade_delivery_validate_recipient_mapping([
        'destination_id' => (int) ($destination['id'] ?? 0),
        'external_identity_id' => (int) ($identity['id'] ?? 0),
        'learner_user_id' => (int) ($recipient['learner_user_id'] ?? 0),
        'recipient_key_hash' => (string) ($recipient['recipient_key_hash'] ?? ''),
        'adapter' => $adapter,
        'connection_key_hash' => (string) ($destination['connection_key_hash'] ?? ''),
        'destination_key_hash' => (string) ($destination['destination_key_hash'] ?? ''),
        'subject_key_hash' => (string) ($identity['subject_key_hash'] ?? ''),
        'assignment_id' => (int) ($destination['assignment_id'] ?? 0),
        'revision_id' => (int) ($destination['revision_id'] ?? 0),
    ]);
}

/** @return array<string,int|string> */
function ll_tools_grade_delivery_send_context(array $delivery, array $mapping): array {
    return [
        'delivery_id' => (int) ($delivery['id'] ?? 0),
        'adapter' => (string) ($delivery['adapter'] ?? ''),
        'destination_id' => (int) ($delivery['destination_id'] ?? 0),
        'recipient_id' => (int) ($delivery['recipient_id'] ?? 0),
        'external_identity_id' => (int) (($mapping['identity']['id'] ?? 0)),
        'assignment_id' => (int) ($delivery['assignment_id'] ?? 0),
        'revision_id' => (int) ($delivery['revision_id'] ?? 0),
        'grade_revision' => (int) ($delivery['grade_revision'] ?? 0),
        'score_given' => (int) ($delivery['score_given'] ?? 0),
        'score_maximum' => (int) ($delivery['score_maximum'] ?? 0),
        'points_given' => (string) ($delivery['points_given'] ?? ''),
        'points_maximum' => (string) ($delivery['points_maximum'] ?? ''),
    ];
}

function ll_tools_grade_delivery_error_classification(WP_Error $error): array {
    $code = ll_tools_grade_delivery_error_code($error->get_error_code(), 'grade_delivery_mapping_error');
    $retryable = in_array($code, ['grade_delivery_adapter_unavailable', 'lms_assignment_schema_unavailable'], true);
    return [
        'outcome' => $retryable ? 'retry' : 'permanent',
        'http_status' => 0,
        'error_code' => $code,
        // Never persist a provider callback's arbitrary message.
        'diagnostic' => $retryable
            ? 'The required grade delivery component is temporarily unavailable.'
            : 'The exact external grade mapping could not be validated.',
        'retry_after' => 0,
    ];
}

/** Process no more than twenty claimed rows. */
function ll_tools_grade_delivery_run_worker(int $limit = LL_TOOLS_GRADE_DELIVERY_MAX_WORKER_BATCH): array {
    $limit = max(1, min(LL_TOOLS_GRADE_DELIVERY_MAX_WORKER_BATCH, $limit));
    $stats = [
        'claimed' => 0,
        'succeeded' => 0,
        'retried' => 0,
        'permanent_failed' => 0,
        'superseded' => 0,
        'lease_lost' => 0,
        'errors' => 0,
        'error_code' => '',
    ];
    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        $stats['errors'] = 1;
        $stats['error_code'] = 'grade_delivery_schema_unavailable';
        return $stats;
    }

    for ($index = 0; $index < $limit; $index++) {
        $lease_token = ll_tools_grade_delivery_lease_token();
        $delivery = ll_tools_grade_delivery_claim_next($lease_token);
        if (is_wp_error($delivery)) {
            $stats['errors']++;
            $stats['error_code'] = ll_tools_grade_delivery_error_code($delivery->get_error_code(), 'grade_delivery_claim_failed');
            break;
        }
        if ($delivery === null) {
            break;
        }
        $stats['claimed']++;
        $delivery_id = (int) ($delivery['id'] ?? 0);

        $grade_status = ll_tools_grade_delivery_current_grade_status($delivery);
        if (is_array($grade_status) && !empty($grade_status['stale'])) {
            if (ll_tools_grade_delivery_supersede_claim($delivery_id, $lease_token)) {
                $stats['superseded']++;
            } else {
                $stats['lease_lost']++;
            }
            continue;
        }

        $classification = null;
        if (is_wp_error($grade_status)) {
            $classification = ll_tools_grade_delivery_error_classification($grade_status);
        } else {
            $mapping = ll_tools_grade_delivery_load_exact_mapping($delivery);
            if (is_wp_error($mapping)) {
                $classification = ll_tools_grade_delivery_error_classification($mapping);
            } else {
                $mapping_valid = ll_tools_grade_delivery_revalidate_exact_mapping($mapping);
                if (is_wp_error($mapping_valid)) {
                    $classification = ll_tools_grade_delivery_error_classification($mapping_valid);
                } else {
                    $adapter = ll_tools_grade_delivery_get_adapter((string) ($delivery['adapter'] ?? ''));
                    if ($adapter === null || !is_callable($adapter['send'] ?? null)) {
                        $classification = ll_tools_grade_delivery_error_classification(new WP_Error('grade_delivery_adapter_unavailable'));
                    } else {
                        try {
                            $response = call_user_func($adapter['send'], ll_tools_grade_delivery_send_context($delivery, $mapping));
                            $classification = ll_tools_grade_delivery_classify_adapter_response($response);
                        } catch (Throwable $error) {
                            $classification = [
                                'outcome' => 'retry',
                                'http_status' => 0,
                                'error_code' => 'adapter_exception',
                                'diagnostic' => 'The grade delivery adapter stopped unexpectedly.',
                                'retry_after' => 0,
                            ];
                        }
                    }
                }
            }
        }

        $applied = ll_tools_grade_delivery_apply_claim_result($delivery, $lease_token, (array) $classification);
        if (is_wp_error($applied)) {
            $stats['lease_lost']++;
            continue;
        }
        if ($applied === 'succeeded') {
            $stats['succeeded']++;
        } elseif ($applied === 'retry') {
            $stats['retried']++;
        } elseif ($applied === 'permanent_failed') {
            $stats['permanent_failed']++;
        } elseif ($applied === 'superseded') {
            $stats['superseded']++;
        }
    }

    ll_tools_grade_delivery_schedule_next_due();
    return $stats;
}

/** Schedule the next due or lease-takeover boundary without an unbounded scan. */
function ll_tools_grade_delivery_schedule_next_due(): bool {
    global $wpdb;

    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return false;
    }
    $table = ll_tools_grade_delivery_table_names()['deliveries'];
    $now = gmdate('Y-m-d H:i:s');
    $previous_suppress_errors = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    $pending_at = $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(d.available_at)
         FROM {$table} d
         WHERE d.status IN ('pending','retry')
           AND (d.lease_token = '' OR d.lease_expires_at IS NULL OR d.lease_expires_at <= %s)
           AND NOT EXISTS (
               SELECT 1
               FROM {$table} active
               WHERE active.destination_id = d.destination_id
                 AND active.learner_user_id = d.learner_user_id
                 AND active.id <> d.id
                 AND active.lease_token <> ''
                 AND active.lease_expires_at IS NOT NULL
                 AND active.lease_expires_at > %s
           )",
        $now,
        $now
    ));
    $pending_error = (string) $wpdb->last_error;
    $wpdb->last_error = '';
    $lease_at = $wpdb->get_var(
        "SELECT MIN(lease_expires_at) FROM {$table} WHERE status = 'processing' AND lease_expires_at IS NOT NULL"
    );
    $lease_error = (string) $wpdb->last_error;
    $wpdb->last_error = '';
    $wpdb->suppress_errors($previous_suppress_errors);
    if ($pending_error !== '' || $lease_error !== '') {
        // A transient read failure must not be mistaken for an empty outbox.
        // Arm a bounded retry without exposing database details.
        return ll_tools_grade_delivery_schedule_worker(5 * MINUTE_IN_SECONDS);
    }
    $timestamps = [];
    foreach ([$pending_at, $lease_at] as $value) {
        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value . ' UTC');
            if ($timestamp !== false) {
                $timestamps[] = $timestamp;
            }
        }
    }
    if ($timestamps === []) {
        return true;
    }
    $delay = max(1, min(DAY_IN_SECONDS, min($timestamps) - time()));
    return ll_tools_grade_delivery_schedule_worker($delay);
}

add_action(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK, 'll_tools_grade_delivery_run_worker');
add_action('init', 'll_tools_grade_delivery_maybe_resume_worker', 14);

/**
 * Bounded, provider-neutral personal-data export. Hashes, lease tokens,
 * diagnostics, and provider credentials/identifiers are intentionally absent.
 *
 * @return array{items:array<int,array<string,mixed>>,next_after_id:int,done:bool,mapping_counts:array<string,int>}|WP_Error
 */
function ll_tools_grade_delivery_export_user_data(int $user_id, int $after_delivery_id = 0, int $limit = 50) {
    global $wpdb;

    if ($user_id <= 0) {
        return new WP_Error('invalid_grade_delivery_user', __('The grade delivery user is invalid.', 'll-tools-text-domain'));
    }
    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    $after_delivery_id = max(0, $after_delivery_id);
    $limit = max(1, min(100, $limit));
    $tables = ll_tools_grade_delivery_table_names();
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, adapter, assignment_id, revision_id, grade_revision,
                score_given, score_maximum, points_given, points_maximum,
                status, attempt_count, last_http_status, last_error_code,
                created_at, updated_at, delivered_at, superseded_at
         FROM {$tables['deliveries']}
         WHERE learner_user_id = %d AND id > %d
         ORDER BY id ASC
         LIMIT %d",
        $user_id,
        $after_delivery_id,
        $limit + 1
    ), ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== '') {
        return new WP_Error('grade_delivery_export_failed', __('External grade delivery data could not be exported.', 'll-tools-text-domain'));
    }
    $has_more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $items = [];
    $next_after_id = $after_delivery_id;
    foreach ($rows as $row) {
        $next_after_id = max($next_after_id, (int) ($row['id'] ?? 0));
        $items[] = [
            'adapter' => (string) ($row['adapter'] ?? ''),
            'assignment_id' => (int) ($row['assignment_id'] ?? 0),
            'revision_id' => (int) ($row['revision_id'] ?? 0),
            'grade_revision' => (int) ($row['grade_revision'] ?? 0),
            'score_given' => (int) ($row['score_given'] ?? 0),
            'score_maximum' => (int) ($row['score_maximum'] ?? 0),
            'points_given' => (string) ($row['points_given'] ?? ''),
            'points_maximum' => (string) ($row['points_maximum'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'last_http_status' => (int) ($row['last_http_status'] ?? 0),
            'last_error_code' => (string) ($row['last_error_code'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'delivered_at' => isset($row['delivered_at']) ? (string) $row['delivered_at'] : '',
            'superseded_at' => isset($row['superseded_at']) ? (string) $row['superseded_at'] : '',
        ];
    }

    return [
        'items' => $items,
        'next_after_id' => $next_after_id,
        'done' => !$has_more,
        'mapping_counts' => [
            'external_identities' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['identities']} WHERE learner_user_id = %d",
                $user_id
            )),
            'grade_recipients' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['recipients']} WHERE learner_user_id = %d",
                $user_id
            )),
        ],
    ];
}

/**
 * Page-number adapter for WordPress personal-data exporter callbacks.
 *
 * The primary helper remains keyset-based. This wrapper resolves only the
 * preceding page boundary through the learner/id index, then delegates to the
 * same bounded, neutral projection. It never treats a page number as a row ID.
 *
 * @return array{items:array<int,array<string,mixed>>,next_after_id:int,done:bool,mapping_counts:array<string,int>,page:int}|WP_Error
 */
function ll_tools_grade_delivery_export_user_data_page(int $user_id, int $page = 1, int $per_page = 50) {
    global $wpdb;

    if ($user_id <= 0 || $page <= 0) {
        return new WP_Error('invalid_grade_delivery_export_page', __('The grade delivery export page is invalid.', 'll-tools-text-domain'));
    }
    $per_page = max(1, min(100, $per_page));
    $max_page = max(1, min(100000, (int) apply_filters('ll_tools_grade_delivery_export_max_page', 10000)));
    if ($page > $max_page) {
        return new WP_Error('grade_delivery_export_page_limit', __('The grade delivery export page exceeds the supported bound.', 'll-tools-text-domain'));
    }

    $after_delivery_id = 0;
    if ($page > 1) {
        if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
            return ll_tools_grade_delivery_schema_error();
        }
        $offset = (($page - 1) * $per_page) - 1;
        $table = ll_tools_grade_delivery_table_names()['deliveries'];
        $wpdb->last_error = '';
        $boundary = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE learner_user_id = %d
             ORDER BY id ASC
             LIMIT 1 OFFSET %d",
            $user_id,
            $offset
        ));
        if ((string) $wpdb->last_error !== '') {
            return new WP_Error('grade_delivery_export_failed', __('External grade delivery data could not be exported.', 'll-tools-text-domain'));
        }
        if ($boundary === null) {
            $tables = ll_tools_grade_delivery_table_names();
            return [
                'items' => [],
                'next_after_id' => 0,
                'done' => true,
                'mapping_counts' => [
                    'external_identities' => (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$tables['identities']} WHERE learner_user_id = %d",
                        $user_id
                    )),
                    'grade_recipients' => (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$tables['recipients']} WHERE learner_user_id = %d",
                        $user_id
                    )),
                ],
                'page' => $page,
            ];
        }
        $after_delivery_id = (int) $boundary;
    }

    $result = ll_tools_grade_delivery_export_user_data($user_id, $after_delivery_id, $per_page);
    if (is_wp_error($result)) {
        return $result;
    }
    $result['page'] = $page;
    return $result;
}

/**
 * Delete one bounded batch of local user mappings/audit rows only.
 *
 * No adapter callback or outbound deletion is invoked. Call until done=true;
 * deliveries are removed before recipients, then identities.
 *
 * @return array{removed:int,stage:string,done:bool}|WP_Error
 */
function ll_tools_grade_delivery_erase_user_data(int $user_id) {
    global $wpdb;

    if ($user_id <= 0) {
        return new WP_Error('invalid_grade_delivery_user', __('The grade delivery user is invalid.', 'll-tools-text-domain'));
    }
    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
        return ll_tools_grade_delivery_schema_error();
    }
    if (
        !function_exists('ll_tools_lms_assignment_begin_transaction')
        || !function_exists('ll_tools_lms_assignment_commit_transaction')
        || !function_exists('ll_tools_lms_assignment_rollback_transaction')
    ) {
        return new WP_Error('grade_delivery_erasure_failed', __('External grade delivery data could not be erased.', 'll-tools-text-domain'));
    }
    $limit = max(1, min(1000, (int) apply_filters('ll_tools_grade_delivery_erasure_batch_size', 500)));
    $tables = ll_tools_grade_delivery_table_names();
    $stages = [
        'deliveries' => $tables['deliveries'],
        'recipients' => $tables['recipients'],
        'identities' => $tables['identities'],
    ];
    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('grade_delivery_erasure_failed', __('External grade delivery data could not be erased.', 'll-tools-text-domain'));
    }

    try {
        // Serialize pre-delete erasure with a finalizer that already owns the
        // learner row. Post-delete continuation legitimately finds no row.
        $wpdb->last_error = '';
        $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            $user_id
        ));
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('grade_delivery_erasure_user_lock_failed');
        }

        foreach ($stages as $stage => $table) {
            $wpdb->last_error = '';
            $select = $stage === 'deliveries'
                ? "SELECT id, status, lease_token FROM {$table} WHERE learner_user_id = %d ORDER BY id ASC LIMIT %d FOR UPDATE"
                : "SELECT id FROM {$table} WHERE learner_user_id = %d ORDER BY id ASC LIMIT %d FOR UPDATE";
            $rows = $wpdb->get_results($wpdb->prepare($select, $user_id, $limit), ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '') {
                throw new RuntimeException('grade_delivery_erasure_read_failed');
            }
            if ($stage === 'deliveries') {
                foreach ($rows as $row) {
                    if ((string) ($row['status'] ?? '') === 'processing') {
                        ll_tools_lms_assignment_rollback_transaction($transaction);
                        return new WP_Error(
                            'grade_delivery_erasure_in_progress',
                            __('An external grade delivery is currently in progress. Retry erasure after it finishes.', 'll-tools-text-domain')
                        );
                    }
                }
            }
            $ids = array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                $rows
            ), static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                continue;
            }
            $deleted = $wpdb->query(
                "DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ') AND learner_user_id = ' . (int) $user_id
            );
            if ($deleted === false) {
                throw new RuntimeException('grade_delivery_erasure_delete_failed');
            }
            $wpdb->last_error = '';
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE learner_user_id = %d",
                $user_id
            ));
            if ((string) $wpdb->last_error !== '') {
                throw new RuntimeException('grade_delivery_erasure_read_failed');
            }
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('grade_delivery_erasure_commit_failed');
            }
            return [
                'removed' => (int) $deleted,
                'stage' => $stage,
                'done' => $remaining === 0 && $stage === 'identities',
            ];
        }

        if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('grade_delivery_erasure_commit_failed');
        }
        return ['removed' => 0, 'stage' => 'complete', 'done' => true];
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('grade_delivery_erasure_failed', __('External grade delivery data could not be erased.', 'll-tools-text-domain'));
    }
}
