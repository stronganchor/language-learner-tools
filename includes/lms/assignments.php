<?php
if (!defined('WPINC')) { die; }

/**
 * Provider-neutral, server-authoritative assignment and attempt storage.
 *
 * This module deliberately has no Google Classroom or LTI knowledge. External
 * transports consume only the selected grade record produced here.
 */

if (!defined('LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION', '1.0.0');
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_VERSION_OPTION')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_VERSION_OPTION', 'll_tools_lms_assignment_schema_version');
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION', 'll_tools_lms_assignment_schema_verified_version');
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT', 'll_tools_lms_assignment_schema_retry');
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA', 1);
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS', 5);
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_DEFAULT_MAX_ITEMS')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_DEFAULT_MAX_ITEMS', 100);
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS', 200);
}
if (!defined('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES')) {
    define('LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES', 256 * 1024);
}

/** @return array<string,string> */
function ll_tools_lms_assignment_table_names(): array {
    global $wpdb;

    return [
        'assignments' => $wpdb->prefix . 'll_tools_lms_assignments',
        'revisions' => $wpdb->prefix . 'll_tools_lms_assignment_revisions',
        'attempts' => $wpdb->prefix . 'll_tools_lms_assignment_attempts',
        'answers' => $wpdb->prefix . 'll_tools_lms_attempt_answers',
        'grades' => $wpdb->prefix . 'll_tools_lms_assignment_grades',
    ];
}

/**
 * Return the structural contract used by the installer readback.
 *
 * Additional columns and indexes are tolerated for forward-compatible repair,
 * but every required column, shape, and ordered index must remain present.
 *
 * @return array<string,array{columns:array<string,string>,indexes:array<string,array{unique:bool,columns:array<int,string>}>}>
 */
function ll_tools_lms_assignment_schema_contract(): array {
    $unsigned_bigint = '/^bigint(?:\(\d+\))? unsigned$/';
    $unsigned_int = '/^int(?:\(\d+\))? unsigned$/';
    $unsigned_smallint = '/^smallint(?:\(\d+\))? unsigned$/';

    return [
        'assignments' => [
            'columns' => [
                'id' => $unsigned_bigint,
                'assignment_uuid' => '/^char\(36\)$/',
                'class_id' => $unsigned_bigint,
                'wordset_id' => $unsigned_bigint,
                'created_by_user_id' => $unsigned_bigint,
                'title' => '/^varchar\(191\)$/',
                'status' => '/^varchar\(16\)$/',
                'current_revision_id' => $unsigned_bigint,
                'created_at' => '/^datetime$/',
                'updated_at' => '/^datetime$/',
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_assignment_uuid' => ['unique' => true, 'columns' => ['assignment_uuid']],
                'idx_class_status' => ['unique' => false, 'columns' => ['class_id', 'status', 'id']],
                'idx_wordset_status' => ['unique' => false, 'columns' => ['wordset_id', 'status', 'id']],
            ],
        ],
        'revisions' => [
            'columns' => [
                'id' => $unsigned_bigint,
                'assignment_id' => $unsigned_bigint,
                'revision_number' => $unsigned_int,
                'manifest_schema' => $unsigned_smallint,
                'manifest_json' => '/^longtext$/',
                'manifest_hash' => '/^char\(64\)$/',
                'question_count' => $unsigned_smallint,
                'points_maximum' => '/^decimal\(12,4\)$/',
                'attempt_limit' => $unsigned_smallint,
                'grade_policy' => '/^varchar\(8\)$/',
                'available_at' => '/^datetime$/',
                'due_at' => '/^datetime$/',
                'created_by_user_id' => $unsigned_bigint,
                'created_at' => '/^datetime$/',
                'published_at' => '/^datetime$/',
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_assignment_revision' => ['unique' => true, 'columns' => ['assignment_id', 'revision_number']],
                'idx_assignment_published' => ['unique' => false, 'columns' => ['assignment_id', 'published_at', 'id']],
            ],
        ],
        'attempts' => [
            'columns' => [
                'id' => $unsigned_bigint,
                'attempt_uuid' => '/^char\(36\)$/',
                'assignment_id' => $unsigned_bigint,
                'revision_id' => $unsigned_bigint,
                'user_id' => $unsigned_bigint,
                'attempt_number' => $unsigned_int,
                'status' => '/^varchar\(16\)$/',
                'score_given' => $unsigned_int,
                'score_maximum' => $unsigned_int,
                'points_given' => '/^decimal\(12,4\)$/',
                'points_maximum' => '/^decimal\(12,4\)$/',
                'started_at' => '/^datetime$/',
                'expires_at' => '/^datetime$/',
                'finalized_at' => '/^datetime$/',
                'updated_at' => '/^datetime$/',
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_attempt_uuid' => ['unique' => true, 'columns' => ['attempt_uuid']],
                'uniq_attempt_number' => ['unique' => true, 'columns' => ['assignment_id', 'revision_id', 'user_id', 'attempt_number']],
                'idx_grade_selection' => ['unique' => false, 'columns' => ['assignment_id', 'revision_id', 'user_id', 'status', 'finalized_at', 'id']],
                'idx_user_updated' => ['unique' => false, 'columns' => ['user_id', 'updated_at', 'id']],
            ],
        ],
        'answers' => [
            'columns' => [
                'id' => $unsigned_bigint,
                'attempt_id' => $unsigned_bigint,
                'answer_uuid' => '/^varchar\(64\)$/',
                'item_key' => '/^varchar\(64\)$/',
                'option_key' => '/^varchar\(64\)$/',
                'is_correct' => '/^tinyint(?:\(\d+\))? unsigned$/',
                'answered_at' => '/^datetime$/',
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uniq_attempt_answer_uuid' => ['unique' => true, 'columns' => ['attempt_id', 'answer_uuid']],
                'uniq_attempt_item' => ['unique' => true, 'columns' => ['attempt_id', 'item_key']],
                'idx_attempt_correct' => ['unique' => false, 'columns' => ['attempt_id', 'is_correct', 'id']],
            ],
        ],
        'grades' => [
            'columns' => [
                'assignment_id' => $unsigned_bigint,
                'revision_id' => $unsigned_bigint,
                'user_id' => $unsigned_bigint,
                'selected_attempt_id' => $unsigned_bigint,
                'grade_revision' => $unsigned_bigint,
                'score_given' => $unsigned_int,
                'score_maximum' => $unsigned_int,
                'points_given' => '/^decimal\(12,4\)$/',
                'points_maximum' => '/^decimal\(12,4\)$/',
                'grade_policy' => '/^varchar\(8\)$/',
                'updated_at' => '/^datetime$/',
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['assignment_id', 'revision_id', 'user_id']],
                'idx_user_updated' => ['unique' => false, 'columns' => ['user_id', 'updated_at', 'assignment_id']],
                'idx_assignment_revision' => ['unique' => false, 'columns' => ['assignment_id', 'revision_id', 'grade_revision']],
            ],
        ],
    ];
}

/**
 * Perform a full database readback. Interactive mutations should use the
 * marker-only availability helper below; this full check belongs to trusted
 * installation and maintenance boundaries.
 */
function ll_tools_lms_assignment_schema_is_ready(): bool {
    global $wpdb;

    $tables = ll_tools_lms_assignment_table_names();
    $contract = ll_tools_lms_assignment_schema_contract();

    // Attempt numbering and finalization serialize on the learner's core user
    // row. Refuse admission if that lock cannot participate transactionally;
    // the plugin must never alter WordPress core table engines itself.
    $users_status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->users)),
        ARRAY_A
    );
    if (!is_array($users_status) || strcasecmp((string) ($users_status['Engine'] ?? ''), 'InnoDB') !== 0) {
        return false;
    }

    $nullable_columns = [
        'assignments' => [],
        'revisions' => ['available_at', 'due_at', 'published_at'],
        'attempts' => ['finalized_at'],
        'answers' => [],
        'grades' => [],
    ];
    $column_defaults = [
        'assignments' => ['status' => 'draft', 'current_revision_id' => '0'],
        'revisions' => [
            'manifest_schema' => '1',
            'attempt_limit' => '1',
            'grade_policy' => 'latest',
            'available_at' => null,
            'due_at' => null,
            'published_at' => null,
        ],
        'attempts' => [
            'status' => 'started',
            'score_given' => '0',
            'score_maximum' => '0',
            'points_given' => '0.0000',
            'points_maximum' => '0.0000',
            'finalized_at' => null,
        ],
        'answers' => ['is_correct' => '0'],
        'grades' => [],
    ];

    foreach ($contract as $table_key => $requirements) {
        $table = $tables[$table_key] ?? '';
        if ($table === '') {
            return false;
        }

        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            ARRAY_A
        );
        if (!is_array($status) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            return false;
        }

        $columns = [];
        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A) as $column) {
            $columns[(string) ($column['Field'] ?? '')] = $column;
        }
        foreach ($requirements['columns'] as $column_name => $type_pattern) {
            $actual_type = strtolower((string) ($columns[$column_name]['Type'] ?? ''));
            $expected_null = in_array($column_name, $nullable_columns[$table_key] ?? [], true) ? 'YES' : 'NO';
            if (
                $actual_type === ''
                || preg_match($type_pattern, $actual_type) !== 1
                || strtoupper((string) ($columns[$column_name]['Null'] ?? '')) !== $expected_null
            ) {
                return false;
            }
            if (array_key_exists($column_name, $column_defaults[$table_key] ?? [])) {
                $expected_default = $column_defaults[$table_key][$column_name];
                $actual_default = $columns[$column_name]['Default'] ?? null;
                if ($expected_default === null ? $actual_default !== null : (string) $actual_default !== $expected_default) {
                    return false;
                }
            }
        }
        if (
            !isset($columns['id']) && $table_key !== 'grades'
            || ($table_key !== 'grades' && stripos((string) ($columns['id']['Extra'] ?? ''), 'auto_increment') === false)
        ) {
            return false;
        }

        $index_rows = (array) $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexes = [];
        foreach ($index_rows as $index_row) {
            $name = (string) ($index_row['Key_name'] ?? '');
            $sequence = max(1, (int) ($index_row['Seq_in_index'] ?? 1));
            if ($name === '') {
                continue;
            }
            $indexes[$name]['unique'] = ((int) ($index_row['Non_unique'] ?? 1)) === 0;
            $indexes[$name]['columns'][$sequence] = (string) ($index_row['Column_name'] ?? '');
            if (!empty($index_row['Sub_part'])) {
                $indexes[$name]['prefixed'] = true;
            }
        }
        foreach ($requirements['indexes'] as $index_name => $index_requirement) {
            if (!isset($indexes[$index_name]) || !empty($indexes[$index_name]['prefixed'])) {
                return false;
            }
            ksort($indexes[$index_name]['columns']);
            if (
                (bool) $indexes[$index_name]['unique'] !== (bool) $index_requirement['unique']
                || array_values($indexes[$index_name]['columns']) !== $index_requirement['columns']
            ) {
                return false;
            }
        }
    }

    return true;
}

/** Marker-only runtime gate. */
function ll_tools_lms_assignment_schema_is_available(): bool {
    return (string) get_option(LL_TOOLS_LMS_ASSIGNMENT_VERSION_OPTION, '') === LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION
        && (string) get_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION, '') === LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION;
}

function ll_tools_install_lms_assignment_schema(): bool {
    global $wpdb;

    $tables = ll_tools_lms_assignment_table_names();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Invalidate the runtime admission marker before any partial DDL can land.
    delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);

    $sql = [];
    $sql[] = "CREATE TABLE {$tables['assignments']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        assignment_uuid char(36) NOT NULL,
        class_id bigint(20) unsigned NOT NULL,
        wordset_id bigint(20) unsigned NOT NULL,
        created_by_user_id bigint(20) unsigned NOT NULL,
        title varchar(191) NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'draft',
        current_revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_assignment_uuid (assignment_uuid),
        KEY idx_class_status (class_id, status, id),
        KEY idx_wordset_status (wordset_id, status, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $sql[] = "CREATE TABLE {$tables['revisions']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        assignment_id bigint(20) unsigned NOT NULL,
        revision_number int(10) unsigned NOT NULL,
        manifest_schema smallint(5) unsigned NOT NULL DEFAULT 1,
        manifest_json longtext NOT NULL,
        manifest_hash char(64) NOT NULL,
        question_count smallint(5) unsigned NOT NULL,
        points_maximum decimal(12,4) NOT NULL,
        attempt_limit smallint(5) unsigned NOT NULL DEFAULT 1,
        grade_policy varchar(8) NOT NULL DEFAULT 'latest',
        available_at datetime NULL DEFAULT NULL,
        due_at datetime NULL DEFAULT NULL,
        created_by_user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        published_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_assignment_revision (assignment_id, revision_number),
        KEY idx_assignment_published (assignment_id, published_at, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $sql[] = "CREATE TABLE {$tables['attempts']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        attempt_uuid char(36) NOT NULL,
        assignment_id bigint(20) unsigned NOT NULL,
        revision_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        attempt_number int(10) unsigned NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'started',
        score_given int(10) unsigned NOT NULL DEFAULT 0,
        score_maximum int(10) unsigned NOT NULL DEFAULT 0,
        points_given decimal(12,4) NOT NULL DEFAULT 0,
        points_maximum decimal(12,4) NOT NULL DEFAULT 0,
        started_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        finalized_at datetime NULL DEFAULT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_attempt_uuid (attempt_uuid),
        UNIQUE KEY uniq_attempt_number (assignment_id, revision_id, user_id, attempt_number),
        KEY idx_grade_selection (assignment_id, revision_id, user_id, status, finalized_at, id),
        KEY idx_user_updated (user_id, updated_at, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $sql[] = "CREATE TABLE {$tables['answers']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        attempt_id bigint(20) unsigned NOT NULL,
        answer_uuid varchar(64) NOT NULL,
        item_key varchar(64) NOT NULL,
        option_key varchar(64) NOT NULL,
        is_correct tinyint(1) unsigned NOT NULL DEFAULT 0,
        answered_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_attempt_answer_uuid (attempt_id, answer_uuid),
        UNIQUE KEY uniq_attempt_item (attempt_id, item_key),
        KEY idx_attempt_correct (attempt_id, is_correct, id)
    ) ENGINE=InnoDB {$charset_collate};";

    $sql[] = "CREATE TABLE {$tables['grades']} (
        assignment_id bigint(20) unsigned NOT NULL,
        revision_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        selected_attempt_id bigint(20) unsigned NOT NULL,
        grade_revision bigint(20) unsigned NOT NULL,
        score_given int(10) unsigned NOT NULL,
        score_maximum int(10) unsigned NOT NULL,
        points_given decimal(12,4) NOT NULL,
        points_maximum decimal(12,4) NOT NULL,
        grade_policy varchar(8) NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (assignment_id, revision_id, user_id),
        KEY idx_user_updated (user_id, updated_at, assignment_id),
        KEY idx_assignment_revision (assignment_id, revision_id, grade_revision)
    ) ENGINE=InnoDB {$charset_collate};";

    foreach ($sql as $statement) {
        dbDelta($statement);
    }

    // dbDelta does not consistently repair an existing non-transactional
    // engine. The authoritative finalize/outbox boundary requires InnoDB.
    foreach ($tables as $table) {
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            ARRAY_A
        );
        if (is_array($status) && strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB");
        }
    }

    $ready = ll_tools_lms_assignment_schema_is_ready();
    $ready = (bool) apply_filters('ll_tools_lms_assignment_schema_exists_after_install', $ready, $tables);
    if (!$ready) {
        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERSION_OPTION);
        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);
        set_transient(LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    update_option(LL_TOOLS_LMS_ASSIGNMENT_VERSION_OPTION, LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION, false);
    update_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION, LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION, false);
    delete_transient(LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT);
    return true;
}

function ll_tools_maybe_upgrade_lms_assignment_schema(): bool {
    if (ll_tools_lms_assignment_schema_is_available()) {
        return true;
    }
    if (get_transient(LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT)) {
        if (function_exists('ll_tools_schedule_schema_maintenance')) {
            ll_tools_schedule_schema_maintenance('lms_assignments', 5 * MINUTE_IN_SECONDS);
        }
        return false;
    }

    if (function_exists('ll_tools_maybe_run_schema_maintenance')) {
        return ll_tools_maybe_run_schema_maintenance(
            'lms_assignments',
            'll_tools_lms_assignment_schema_is_available',
            'll_tools_install_lms_assignment_schema'
        );
    }

    return false;
}
add_action('init', 'll_tools_maybe_upgrade_lms_assignment_schema', 12);

/** The normal manifest ceiling is filterable but never exceeds 200 items. */
function ll_tools_lms_assignment_manifest_item_limit(): int {
    $limit = (int) apply_filters(
        'll_tools_lms_assignment_manifest_item_limit',
        LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_DEFAULT_MAX_ITEMS
    );
    return max(
        LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS,
        min(LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS, $limit)
    );
}

function ll_tools_lms_assignment_normalize_key($value): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $value) === 1 ? $value : '';
}

/**
 * Normalize one trusted, server-built closed-response manifest.
 *
 * Attempt endpoints never receive this structure and never accept a client
 * correctness bit. The only stored truth is the canonical manifest returned
 * here.
 *
 * @return array|WP_Error
 */
function ll_tools_lms_assignment_normalize_manifest($manifest) {
    if (!is_array($manifest)) {
        return new WP_Error('invalid_assignment_manifest', __('The assignment manifest is invalid.', 'll-tools-text-domain'));
    }
    $input_json = wp_json_encode($manifest);
    if (!is_string($input_json) || strlen($input_json) > LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES) {
        return new WP_Error('assignment_manifest_too_large', __('The assignment manifest is too large.', 'll-tools-text-domain'));
    }
    if (($manifest['schema'] ?? null) !== LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA) {
        return new WP_Error('unsupported_assignment_manifest', __('The assignment manifest version is not supported.', 'll-tools-text-domain'));
    }
    if (($manifest['kind'] ?? null) !== 'closed_response') {
        return new WP_Error('unsupported_assignment_manifest_kind', __('Only closed-response assignments are supported.', 'll-tools-text-domain'));
    }

    $items = $manifest['items'] ?? null;
    $item_limit = ll_tools_lms_assignment_manifest_item_limit();
    if (!is_array($items) || count($items) < LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS || count($items) > $item_limit) {
        return new WP_Error(
            'assignment_manifest_item_count',
            sprintf(
                /* translators: 1: minimum questions, 2: maximum questions */
                __('Assignments must contain between %1$d and %2$d questions.', 'll-tools-text-domain'),
                LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS,
                $item_limit
            )
        );
    }

    $normalized_items = [];
    $seen_items = [];
    foreach (array_values($items) as $item) {
        if (!is_array($item)) {
            return new WP_Error('invalid_assignment_item', __('An assignment question is invalid.', 'll-tools-text-domain'));
        }
        $item_key = ll_tools_lms_assignment_normalize_key($item['key'] ?? null);
        if ($item_key === '' || isset($seen_items[$item_key])) {
            return new WP_Error('invalid_assignment_item_key', __('Assignment question keys must be unique and URL-safe.', 'll-tools-text-domain'));
        }
        $seen_items[$item_key] = true;

        $options = $item['options'] ?? null;
        if (!is_array($options) || count($options) < 2 || count($options) > 20) {
            return new WP_Error('assignment_option_count', __('Each assignment question must have between 2 and 20 choices.', 'll-tools-text-domain'));
        }

        $normalized_options = [];
        $seen_options = [];
        $correct_count = 0;
        foreach (array_values($options) as $option) {
            if (!is_array($option)) {
                return new WP_Error('invalid_assignment_option', __('An assignment choice is invalid.', 'll-tools-text-domain'));
            }
            $option_key = ll_tools_lms_assignment_normalize_key($option['key'] ?? null);
            if ($option_key === '' || isset($seen_options[$option_key]) || !array_key_exists('correct', $option) || !is_bool($option['correct'])) {
                return new WP_Error('invalid_assignment_option_key', __('Assignment choice keys and correctness values are invalid.', 'll-tools-text-domain'));
            }
            $seen_options[$option_key] = true;
            $is_correct = $option['correct'] === true;
            $correct_count += $is_correct ? 1 : 0;
            $normalized_options[] = [
                'key' => $option_key,
                'correct' => $is_correct,
            ];
        }
        if ($correct_count !== 1) {
            return new WP_Error('assignment_correct_option_count', __('Each assignment question must have exactly one correct choice.', 'll-tools-text-domain'));
        }

        $normalized_items[] = [
            'key' => $item_key,
            'options' => $normalized_options,
        ];
    }

    $normalized = [
        'schema' => LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA,
        'kind' => 'closed_response',
        'items' => $normalized_items,
    ];
    $encoded = wp_json_encode($normalized);
    if (!is_string($encoded) || strlen($encoded) > LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES) {
        return new WP_Error('assignment_manifest_too_large', __('The assignment manifest is too large.', 'll-tools-text-domain'));
    }

    return $normalized;
}

/** Strip the server-only answer key before a manifest reaches a learner. */
function ll_tools_lms_assignment_public_manifest(array $manifest): array {
    $public_items = [];
    foreach ((array) ($manifest['items'] ?? []) as $item) {
        $public_options = [];
        foreach ((array) ($item['options'] ?? []) as $option) {
            $public_options[] = ['key' => (string) ($option['key'] ?? '')];
        }
        $public_items[] = [
            'key' => (string) ($item['key'] ?? ''),
            'options' => $public_options,
        ];
    }
    return [
        'schema' => LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA,
        'kind' => 'closed_response',
        'items' => $public_items,
    ];
}

/** @return string|WP_Error|null */
function ll_tools_lms_assignment_normalize_datetime($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        return new WP_Error('invalid_assignment_datetime', __('The assignment date is invalid.', 'll-tools-text-domain'));
    }
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !($date instanceof DateTimeImmutable)
        || (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0))
        || $date->format('Y-m-d H:i:s') !== $value
    ) {
        return new WP_Error('invalid_assignment_datetime', __('The assignment date is invalid.', 'll-tools-text-domain'));
    }
    return $value;
}

/** @return string|WP_Error */
function ll_tools_lms_assignment_normalize_points($value) {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        return new WP_Error('invalid_assignment_points', __('The assignment points value is invalid.', 'll-tools-text-domain'));
    }
    $points = (float) $value;
    if (!is_finite($points) || $points <= 0 || $points > 1000000) {
        return new WP_Error('invalid_assignment_points', __('The assignment points value is invalid.', 'll-tools-text-domain'));
    }
    return number_format($points, 4, '.', '');
}

/** @return array|WP_Error */
function ll_tools_lms_assignment_normalize_revision_input(array $input) {
    $manifest = ll_tools_lms_assignment_normalize_manifest($input['manifest'] ?? null);
    if (is_wp_error($manifest)) {
        return $manifest;
    }
    $question_count = count($manifest['items']);

    $points_maximum = ll_tools_lms_assignment_normalize_points($input['points_maximum'] ?? $question_count);
    if (is_wp_error($points_maximum)) {
        return $points_maximum;
    }

    $attempt_limit_input = $input['attempt_limit'] ?? 1;
    if (
        !is_int($attempt_limit_input)
        && !(is_string($attempt_limit_input) && preg_match('/^\d{1,3}$/D', $attempt_limit_input) === 1)
    ) {
        return new WP_Error('invalid_assignment_attempt_limit', __('The assignment attempt limit must be between 1 and 100.', 'll-tools-text-domain'));
    }
    $attempt_limit = (int) $attempt_limit_input;
    if ($attempt_limit < 1 || $attempt_limit > 100) {
        return new WP_Error('invalid_assignment_attempt_limit', __('The assignment attempt limit must be between 1 and 100.', 'll-tools-text-domain'));
    }

    $grade_policy_input = $input['grade_policy'] ?? 'latest';
    if (!is_string($grade_policy_input)) {
        return new WP_Error('invalid_assignment_grade_policy', __('The assignment grading policy is invalid.', 'll-tools-text-domain'));
    }
    $grade_policy = sanitize_key($grade_policy_input);
    if (!in_array($grade_policy, ['first', 'latest', 'best'], true)) {
        return new WP_Error('invalid_assignment_grade_policy', __('The assignment grading policy is invalid.', 'll-tools-text-domain'));
    }

    $available_at = ll_tools_lms_assignment_normalize_datetime($input['available_at'] ?? null);
    if (is_wp_error($available_at)) {
        return $available_at;
    }
    $due_at = ll_tools_lms_assignment_normalize_datetime($input['due_at'] ?? null);
    if (is_wp_error($due_at)) {
        return $due_at;
    }
    if ($available_at !== null && $due_at !== null && strcmp($due_at, $available_at) <= 0) {
        return new WP_Error('invalid_assignment_window', __('The assignment due date must be after its availability date.', 'll-tools-text-domain'));
    }

    $manifest_json = wp_json_encode($manifest);
    if (!is_string($manifest_json) || strlen($manifest_json) > LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES) {
        return new WP_Error('assignment_manifest_too_large', __('The assignment manifest is too large.', 'll-tools-text-domain'));
    }

    return [
        'manifest' => $manifest,
        'manifest_json' => $manifest_json,
        'manifest_hash' => hash('sha256', $manifest_json),
        'question_count' => $question_count,
        'points_maximum' => $points_maximum,
        'attempt_limit' => $attempt_limit,
        'grade_policy' => $grade_policy,
        'available_at' => $available_at,
        'due_at' => $due_at,
    ];
}

function ll_tools_lms_assignment_schema_error(): WP_Error {
    return new WP_Error(
        'lms_assignment_schema_unavailable',
        __('Assignments are temporarily unavailable while storage is being prepared.', 'll-tools-text-domain'),
        ['status' => 503, 'retryable' => true]
    );
}

/** @return array{type:string,name:string}|null */
function ll_tools_lms_assignment_begin_transaction(): ?array {
    global $wpdb;

    static $sequence = 0;
    $sequence++;
    $name = 'll_lms_assignment_' . $sequence . '_' . substr(hash('sha256', microtime(true) . '|' . wp_rand()), 0, 10);
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
    // autocommit=1 establishes the normal idle WordPress connection without
    // relying on MariaDB-only @@in_transaction. Any other failure is ambiguous
    // and must not risk implicitly committing a caller-owned transaction.
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

function ll_tools_lms_assignment_commit_transaction(array $transaction): bool {
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

function ll_tools_lms_assignment_rollback_transaction(array $transaction): void {
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

function ll_tools_lms_assignment_lock_user(int $user_id): bool {
    global $wpdb;

    if (
        $user_id <= 0
        || (
            function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            && ll_tools_privacy_user_lms_deletion_is_pending($user_id)
        )
    ) {
        return false;
    }
    $wpdb->last_error = '';
    $locked = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $user_id
    ));
    if ((int) $locked !== $user_id || (string) $wpdb->last_error !== '') {
        return false;
    }
    return !function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
        || !ll_tools_privacy_user_lms_deletion_is_pending($user_id);
}

function ll_tools_lms_assignment_user_can_manage_class(int $class_id, int $user_id = 0): bool {
    $user_id = (int) ($user_id ?: get_current_user_id());
    if (
        $class_id <= 0
        || $user_id <= 0
        || (
            function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
            && ll_tools_privacy_user_lms_deletion_is_pending($user_id)
        )
        || !function_exists('ll_tools_user_can_manage_classes')
        || !ll_tools_user_can_manage_classes($user_id)
        || !function_exists('ll_tools_teacher_class_user_can_access')
    ) {
        return false;
    }
    return ll_tools_teacher_class_user_can_access($class_id, $user_id);
}

function ll_tools_lms_assignment_user_is_current_member(array $assignment, int $user_id): bool {
    $class_id = max(0, (int) ($assignment['class_id'] ?? 0));
    $wordset_id = max(0, (int) ($assignment['wordset_id'] ?? 0));
    if (
        $class_id <= 0
        || $wordset_id <= 0
        || $user_id <= 0
        || !function_exists('ll_tools_teacher_class_user_is_student')
        || !ll_tools_teacher_class_user_is_student($class_id, $user_id)
        || !function_exists('ll_tools_teacher_class_get_wordset_id')
    ) {
        return false;
    }
    return ll_tools_teacher_class_get_wordset_id($class_id) === $wordset_id;
}

/** @return array|null */
function ll_tools_lms_assignment_get($identifier, bool $for_update = false): ?array {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return null;
    }
    $table = ll_tools_lms_assignment_table_names()['assignments'];
    $lock = $for_update ? ' FOR UPDATE' : '';
    if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
        $id = (int) $identifier;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d{$lock}", $id), ARRAY_A);
    } elseif (is_string($identifier) && ll_tools_lms_assignment_normalize_uuid($identifier) !== '') {
        $uuid = ll_tools_lms_assignment_normalize_uuid($identifier);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE assignment_uuid = %s{$lock}", $uuid), ARRAY_A);
    } else {
        return null;
    }
    return is_array($row) ? $row : null;
}

/** @return array|null */
function ll_tools_lms_assignment_get_revision(int $revision_id, bool $for_update = false): ?array {
    global $wpdb;

    if ($revision_id <= 0 || !ll_tools_lms_assignment_schema_is_available()) {
        return null;
    }
    $table = ll_tools_lms_assignment_table_names()['revisions'];
    $lock = $for_update ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d{$lock}", $revision_id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** @return array|WP_Error */
function ll_tools_lms_assignment_revision_manifest(array $revision) {
    $json = (string) ($revision['manifest_json'] ?? '');
    $expected_hash = (string) ($revision['manifest_hash'] ?? '');
    if (
        (int) ($revision['manifest_schema'] ?? 0) !== LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA
        || $json === ''
        || strlen($json) > LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES
        || preg_match('/^[a-f0-9]{64}$/D', $expected_hash) !== 1
    ) {
        return new WP_Error('invalid_stored_assignment_manifest', __('The stored assignment manifest is unavailable.', 'll-tools-text-domain'));
    }
    if (!hash_equals($expected_hash, hash('sha256', $json))) {
        return new WP_Error('invalid_stored_assignment_manifest', __('The stored assignment manifest failed verification.', 'll-tools-text-domain'));
    }
    $decoded = json_decode($json, true, 8);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('invalid_stored_assignment_manifest', __('The stored assignment manifest is unavailable.', 'll-tools-text-domain'));
    }
    $normalized = ll_tools_lms_assignment_normalize_manifest($decoded);
    if (is_wp_error($normalized)) {
        return new WP_Error('invalid_stored_assignment_manifest', __('The stored assignment manifest is unavailable.', 'll-tools-text-domain'));
    }
    $canonical_json = wp_json_encode($normalized);
    $points = ll_tools_lms_assignment_normalize_points($revision['points_maximum'] ?? null);
    $available_at = ll_tools_lms_assignment_normalize_datetime($revision['available_at'] ?? null);
    $due_at = ll_tools_lms_assignment_normalize_datetime($revision['due_at'] ?? null);
    if (
        !is_string($canonical_json)
        || !hash_equals($json, $canonical_json)
        || count($normalized['items']) !== (int) ($revision['question_count'] ?? 0)
        || is_wp_error($points)
        || $points !== (string) ($revision['points_maximum'] ?? '')
        || (int) ($revision['attempt_limit'] ?? 0) < 1
        || (int) ($revision['attempt_limit'] ?? 0) > 100
        || !in_array((string) ($revision['grade_policy'] ?? ''), ['first', 'latest', 'best'], true)
        || is_wp_error($available_at)
        || is_wp_error($due_at)
        || (is_string($available_at) && is_string($due_at) && strcmp($due_at, $available_at) <= 0)
    ) {
        return new WP_Error('invalid_stored_assignment_manifest', __('The stored assignment manifest failed verification.', 'll-tools-text-domain'));
    }
    return $normalized;
}

/** @return int|WP_Error */
function ll_tools_lms_assignment_insert_revision(int $assignment_id, int $revision_number, array $normalized, int $actor_user_id) {
    global $wpdb;

    $table = ll_tools_lms_assignment_table_names()['revisions'];
    $inserted = $wpdb->insert(
        $table,
        [
            'assignment_id' => $assignment_id,
            'revision_number' => $revision_number,
            'manifest_schema' => LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA,
            'manifest_json' => $normalized['manifest_json'],
            'manifest_hash' => $normalized['manifest_hash'],
            'question_count' => $normalized['question_count'],
            'points_maximum' => $normalized['points_maximum'],
            'attempt_limit' => $normalized['attempt_limit'],
            'grade_policy' => $normalized['grade_policy'],
            'available_at' => $normalized['available_at'],
            'due_at' => $normalized['due_at'],
            'created_by_user_id' => $actor_user_id,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'published_at' => null,
        ],
        ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
    );
    if ($inserted !== 1) {
        return new WP_Error('assignment_revision_write_failed', __('The assignment revision could not be saved.', 'll-tools-text-domain'));
    }
    return (int) $wpdb->insert_id;
}

/**
 * Create a draft assignment and its first immutable revision.
 *
 * @return int|WP_Error Assignment database ID.
 */
function ll_tools_lms_assignment_create(int $class_id, array $input, int $actor_user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    if (!ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) {
        return new WP_Error('assignment_forbidden', __('You cannot create assignments for this class.', 'll-tools-text-domain'));
    }
    if (!function_exists('ll_tools_teacher_class_get_wordset_id')) {
        return new WP_Error('assignment_class_unavailable', __('The assignment class is unavailable.', 'll-tools-text-domain'));
    }
    $wordset_id = ll_tools_teacher_class_get_wordset_id($class_id);
    if ($wordset_id <= 0) {
        return new WP_Error('assignment_wordset_unavailable', __('The class word set is unavailable.', 'll-tools-text-domain'));
    }
    if (!isset($input['title']) || !is_string($input['title'])) {
        return new WP_Error('invalid_assignment_title', __('Enter an assignment title no longer than 191 characters.', 'll-tools-text-domain'));
    }
    $title = sanitize_text_field($input['title']);
    if ($title === '' || strlen($title) > 191) {
        return new WP_Error('invalid_assignment_title', __('Enter an assignment title no longer than 191 characters.', 'll-tools-text-domain'));
    }
    $normalized = ll_tools_lms_assignment_normalize_revision_input($input);
    if (is_wp_error($normalized)) {
        return $normalized;
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The assignment could not be saved right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    $now = gmdate('Y-m-d H:i:s');
    try {
        if (
            !ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)
            || ll_tools_teacher_class_get_wordset_id($class_id) !== $wordset_id
        ) {
            throw new RuntimeException('assignment_class_scope_changed');
        }
        $inserted = $wpdb->insert(
            $tables['assignments'],
            [
                'assignment_uuid' => wp_generate_uuid4(),
                'class_id' => $class_id,
                'wordset_id' => $wordset_id,
                'created_by_user_id' => $actor_user_id,
                'title' => $title,
                'status' => 'draft',
                'current_revision_id' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        if ($inserted !== 1) {
            throw new RuntimeException('assignment_insert_failed');
        }
        $assignment_id = (int) $wpdb->insert_id;
        $revision_id = ll_tools_lms_assignment_insert_revision($assignment_id, 1, $normalized, $actor_user_id);
        if (is_wp_error($revision_id)) {
            throw new RuntimeException($revision_id->get_error_code());
        }
        $updated = $wpdb->update(
            $tables['assignments'],
            ['current_revision_id' => $revision_id, 'updated_at' => $now],
            ['id' => $assignment_id],
            ['%d', '%s'],
            ['%d']
        );
        if ($updated !== 1 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_revision_link_failed');
        }
        return $assignment_id;
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_write_failed', __('The assignment could not be saved right now.', 'll-tools-text-domain'));
    }
}

/** Add a new immutable revision to a draft. @return int|WP_Error */
function ll_tools_lms_assignment_create_revision(int $assignment_id, array $input, int $actor_user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    $assignment = ll_tools_lms_assignment_get($assignment_id);
    if (!is_array($assignment) || !ll_tools_lms_assignment_user_can_manage_class((int) $assignment['class_id'], $actor_user_id)) {
        return new WP_Error('assignment_forbidden', __('You cannot edit this assignment.', 'll-tools-text-domain'));
    }
    $normalized = ll_tools_lms_assignment_normalize_revision_input($input);
    if (is_wp_error($normalized)) {
        return $normalized;
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The assignment could not be saved right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    try {
        $assignment = ll_tools_lms_assignment_get($assignment_id, true);
        if (
            !is_array($assignment)
            || !ll_tools_lms_assignment_user_can_manage_class((int) $assignment['class_id'], $actor_user_id)
            || (string) $assignment['status'] !== 'draft'
        ) {
            throw new DomainException('assignment_revision_immutable');
        }
        $revision_number = 1 + (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(revision_number), 0) FROM {$tables['revisions']} WHERE assignment_id = %d",
            $assignment_id
        ));
        $revision_id = ll_tools_lms_assignment_insert_revision($assignment_id, $revision_number, $normalized, $actor_user_id);
        if (is_wp_error($revision_id)) {
            throw new RuntimeException($revision_id->get_error_code());
        }
        $updated = $wpdb->update(
            $tables['assignments'],
            ['current_revision_id' => $revision_id, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $assignment_id, 'status' => 'draft'],
            ['%d', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_revision_link_failed');
        }
        return $revision_id;
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_revision_immutable', __('Published and archived assignment revisions cannot be changed.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_write_failed', __('The assignment could not be saved right now.', 'll-tools-text-domain'));
    }
}

function ll_tools_lms_assignment_publish(int $assignment_id, int $actor_user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    $assignment = ll_tools_lms_assignment_get($assignment_id);
    if (!is_array($assignment) || !ll_tools_lms_assignment_user_can_manage_class((int) $assignment['class_id'], $actor_user_id)) {
        return new WP_Error('assignment_forbidden', __('You cannot publish this assignment.', 'll-tools-text-domain'));
    }
    if ((string) $assignment['status'] === 'published') {
        return true;
    }
    if ((string) $assignment['status'] !== 'draft') {
        return new WP_Error('assignment_not_draft', __('Only draft assignments can be published.', 'll-tools-text-domain'));
    }
    $revision = ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']);
    $manifest = is_array($revision) ? ll_tools_lms_assignment_revision_manifest($revision) : null;
    if (!is_array($manifest)) {
        return is_wp_error($manifest) ? $manifest : new WP_Error('assignment_revision_missing', __('The assignment revision is unavailable.', 'll-tools-text-domain'));
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The assignment could not be published right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    $now = gmdate('Y-m-d H:i:s');
    try {
        $assignment = ll_tools_lms_assignment_get($assignment_id, true);
        if (
            !is_array($assignment)
            || !ll_tools_lms_assignment_user_can_manage_class((int) $assignment['class_id'], $actor_user_id)
            || (string) $assignment['status'] !== 'draft'
        ) {
            throw new DomainException('assignment_not_draft');
        }
        $revision_id = (int) $assignment['current_revision_id'];
        $revision_updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['revisions']} SET published_at = %s WHERE id = %d AND assignment_id = %d AND published_at IS NULL",
            $now,
            $revision_id,
            $assignment_id
        ));
        $assignment_updated = $wpdb->update(
            $tables['assignments'],
            ['status' => 'published', 'updated_at' => $now],
            ['id' => $assignment_id, 'status' => 'draft'],
            ['%s', '%s'],
            ['%d', '%s']
        );
        if ($revision_updated !== 1 || $assignment_updated !== 1 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_publish_failed');
        }
        return true;
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_not_draft', __('Only draft assignments can be published.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_publish_failed', __('The assignment could not be published right now.', 'll-tools-text-domain'));
    }
}

function ll_tools_lms_assignment_archive(int $assignment_id, int $actor_user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    $assignment = ll_tools_lms_assignment_get($assignment_id);
    if (!is_array($assignment) || !ll_tools_lms_assignment_user_can_manage_class((int) $assignment['class_id'], $actor_user_id)) {
        return new WP_Error('assignment_forbidden', __('You cannot archive this assignment.', 'll-tools-text-domain'));
    }
    if ((string) $assignment['status'] === 'archived') {
        return true;
    }
    $updated = $wpdb->update(
        ll_tools_lms_assignment_table_names()['assignments'],
        ['status' => 'archived', 'updated_at' => gmdate('Y-m-d H:i:s')],
        ['id' => $assignment_id],
        ['%s', '%s'],
        ['%d']
    );
    return $updated === 1 ? true : new WP_Error('assignment_archive_failed', __('The assignment could not be archived.', 'll-tools-text-domain'));
}

/** Return one bounded, keyset-paged class assignment list. @return array|WP_Error */
function ll_tools_lms_assignments_for_class(int $class_id, int $actor_user_id = 0, array $args = []) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    if (!ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) {
        return new WP_Error('assignment_forbidden', __('You cannot view assignments for this class.', 'll-tools-text-domain'));
    }
    $number = max(1, min(101, (int) ($args['number'] ?? 21)));
    $after_id = max(0, (int) ($args['after_id'] ?? 0));
    $status = sanitize_key((string) ($args['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['draft', 'published', 'archived'], true)) {
        return new WP_Error('invalid_assignment_status', __('The assignment status filter is invalid.', 'll-tools-text-domain'));
    }

    $table = ll_tools_lms_assignment_table_names()['assignments'];
    $where = 'class_id = %d';
    $params = [$class_id];
    if ($after_id > 0) {
        $where .= ' AND id > %d';
        $params[] = $after_id;
    }
    if ($status !== '') {
        $where .= ' AND status = %s';
        $params[] = $status;
    }
    $params[] = $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d", $params),
        ARRAY_A
    );
    return is_array($rows) ? $rows : [];
}

/** A filterable clock keeps window enforcement deterministic in tests. */
function ll_tools_lms_assignment_now_timestamp(): int {
    return max(1, (int) apply_filters('ll_tools_lms_assignment_now', time()));
}

function ll_tools_lms_assignment_now_mysql(): string {
    return gmdate('Y-m-d H:i:s', ll_tools_lms_assignment_now_timestamp());
}

function ll_tools_lms_assignment_normalize_uuid($value): string {
    if (!is_string($value)) {
        return '';
    }
    $value = strtolower(trim($value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1
        ? $value
        : '';
}

/** @return array|null */
function ll_tools_lms_assignment_get_attempt($identifier, bool $for_update = false): ?array {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return null;
    }
    $table = ll_tools_lms_assignment_table_names()['attempts'];
    $lock = $for_update ? ' FOR UPDATE' : '';
    if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
        $id = (int) $identifier;
        $row = $id > 0
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d{$lock}", $id), ARRAY_A)
            : null;
    } else {
        $uuid = ll_tools_lms_assignment_normalize_uuid($identifier);
        $row = $uuid !== ''
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE attempt_uuid = %s{$lock}", $uuid), ARRAY_A)
            : null;
    }
    return is_array($row) ? $row : null;
}

/** @return array|null */
function ll_tools_lms_assignment_get_grade(int $assignment_id, int $revision_id, int $user_id, bool $for_update = false): ?array {
    global $wpdb;

    if ($assignment_id <= 0 || $revision_id <= 0 || $user_id <= 0 || !ll_tools_lms_assignment_schema_is_available()) {
        return null;
    }
    $table = ll_tools_lms_assignment_table_names()['grades'];
    $lock = $for_update ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE assignment_id = %d AND revision_id = %d AND user_id = %d{$lock}",
        $assignment_id,
        $revision_id,
        $user_id
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** Return public attempt fields with stable scalar types. */
function ll_tools_lms_assignment_public_attempt(array $attempt): array {
    return [
        'attempt_uuid' => (string) ($attempt['attempt_uuid'] ?? ''),
        'assignment_id' => max(0, (int) ($attempt['assignment_id'] ?? 0)),
        'revision_id' => max(0, (int) ($attempt['revision_id'] ?? 0)),
        'attempt_number' => max(0, (int) ($attempt['attempt_number'] ?? 0)),
        'status' => (string) ($attempt['status'] ?? ''),
        'score_given' => max(0, (int) ($attempt['score_given'] ?? 0)),
        'score_maximum' => max(0, (int) ($attempt['score_maximum'] ?? 0)),
        'points_given' => number_format((float) ($attempt['points_given'] ?? 0), 4, '.', ''),
        'points_maximum' => number_format((float) ($attempt['points_maximum'] ?? 0), 4, '.', ''),
        'started_at' => (string) ($attempt['started_at'] ?? ''),
        'expires_at' => (string) ($attempt['expires_at'] ?? ''),
        'finalized_at' => isset($attempt['finalized_at']) ? (string) $attempt['finalized_at'] : null,
    ];
}

/** Return selected-grade fields with stable scalar types. */
function ll_tools_lms_assignment_public_grade(?array $grade): ?array {
    if (!is_array($grade)) {
        return null;
    }
    return [
        'assignment_id' => max(0, (int) ($grade['assignment_id'] ?? 0)),
        'revision_id' => max(0, (int) ($grade['revision_id'] ?? 0)),
        'selected_attempt_id' => max(0, (int) ($grade['selected_attempt_id'] ?? 0)),
        'grade_revision' => max(0, (int) ($grade['grade_revision'] ?? 0)),
        'score_given' => max(0, (int) ($grade['score_given'] ?? 0)),
        'score_maximum' => max(0, (int) ($grade['score_maximum'] ?? 0)),
        'points_given' => number_format((float) ($grade['points_given'] ?? 0), 4, '.', ''),
        'points_maximum' => number_format((float) ($grade['points_maximum'] ?? 0), 4, '.', ''),
        'grade_policy' => (string) ($grade['grade_policy'] ?? ''),
        'updated_at' => (string) ($grade['updated_at'] ?? ''),
    ];
}

/** @return true|WP_Error */
function ll_tools_lms_assignment_revision_window_is_open(array $revision, int $now_timestamp) {
    if (empty($revision['published_at'])) {
        return new WP_Error('assignment_not_published', __('This assignment is not published.', 'll-tools-text-domain'));
    }
    $available_at = ll_tools_lms_assignment_normalize_datetime($revision['available_at'] ?? null);
    $due_at = ll_tools_lms_assignment_normalize_datetime($revision['due_at'] ?? null);
    if (
        (!empty($revision['available_at']) && !is_string($available_at))
        || (!empty($revision['due_at']) && !is_string($due_at))
    ) {
        return new WP_Error('invalid_stored_assignment_window', __('The assignment window is unavailable.', 'll-tools-text-domain'));
    }
    if (is_string($available_at) && $now_timestamp < (int) strtotime($available_at . ' UTC')) {
        return new WP_Error('assignment_not_available', __('This assignment is not available yet.', 'll-tools-text-domain'));
    }
    if (is_string($due_at) && $now_timestamp >= (int) strtotime($due_at . ' UTC')) {
        return new WP_Error('assignment_window_closed', __('This assignment is closed.', 'll-tools-text-domain'));
    }
    return true;
}

/** Locate a bounded canonical item in a previously verified manifest. */
function ll_tools_lms_assignment_manifest_item(array $manifest, string $item_key): ?array {
    foreach ((array) ($manifest['items'] ?? []) as $item) {
        if (is_array($item) && (string) ($item['key'] ?? '') === $item_key) {
            return $item;
        }
    }
    return null;
}

/**
 * Start one server-numbered attempt for a current member.
 *
 * @return array|WP_Error
 */
function ll_tools_lms_assignment_start_attempt($assignment_identifier, int $user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $user_id = (int) ($user_id ?: get_current_user_id());
    if ($user_id <= 0 || !get_userdata($user_id)) {
        return new WP_Error('assignment_learner_required', __('Sign in to start this assignment.', 'll-tools-text-domain'));
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The assignment could not be started right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    try {
        if (!ll_tools_lms_assignment_lock_user($user_id)) {
            throw new RuntimeException('assignment_learner_unavailable');
        }
        $assignment = ll_tools_lms_assignment_get($assignment_identifier, true);
        if (!is_array($assignment)) {
            throw new DomainException('assignment_not_found');
        }
        if ((string) $assignment['status'] !== 'published') {
            throw new DomainException('assignment_not_published');
        }
        if (!ll_tools_lms_assignment_user_is_current_member($assignment, $user_id)) {
            throw new DomainException('assignment_membership_required');
        }
        $revision_id = (int) $assignment['current_revision_id'];
        $revision = ll_tools_lms_assignment_get_revision($revision_id, true);
        if (!is_array($revision) || (int) $revision['assignment_id'] !== (int) $assignment['id']) {
            throw new RuntimeException('assignment_revision_missing');
        }
        $manifest = ll_tools_lms_assignment_revision_manifest($revision);
        if (is_wp_error($manifest)) {
            throw new RuntimeException('invalid_stored_assignment_manifest');
        }
        $now_timestamp = ll_tools_lms_assignment_now_timestamp();
        $window = ll_tools_lms_assignment_revision_window_is_open($revision, $now_timestamp);
        if (is_wp_error($window)) {
            throw new DomainException($window->get_error_code());
        }

        $attempt_state = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS attempt_count, COALESCE(MAX(attempt_number), 0) AS last_number
             FROM {$tables['attempts']}
             WHERE assignment_id = %d AND revision_id = %d AND user_id = %d",
            (int) $assignment['id'],
            $revision_id,
            $user_id
        ), ARRAY_A);
        $attempt_count = max(0, (int) ($attempt_state['attempt_count'] ?? 0));
        $attempt_limit = max(1, (int) $revision['attempt_limit']);
        if ($attempt_count >= $attempt_limit) {
            throw new DomainException('assignment_attempt_limit_reached');
        }
        $attempt_number = 1 + max(0, (int) ($attempt_state['last_number'] ?? 0));

        $ttl = (int) apply_filters('ll_tools_lms_assignment_attempt_ttl_seconds', HOUR_IN_SECONDS, $assignment, $revision, $user_id);
        $ttl = max(5 * MINUTE_IN_SECONDS, min(DAY_IN_SECONDS, $ttl));
        $expires_timestamp = $now_timestamp + $ttl;
        $due_at = ll_tools_lms_assignment_normalize_datetime($revision['due_at'] ?? null);
        if (is_string($due_at)) {
            $expires_timestamp = min($expires_timestamp, (int) strtotime($due_at . ' UTC'));
        }
        if ($expires_timestamp <= $now_timestamp) {
            throw new DomainException('assignment_window_closed');
        }
        $now = gmdate('Y-m-d H:i:s', $now_timestamp);
        $expires_at = gmdate('Y-m-d H:i:s', $expires_timestamp);
        $inserted = $wpdb->insert(
            $tables['attempts'],
            [
                'attempt_uuid' => wp_generate_uuid4(),
                'assignment_id' => (int) $assignment['id'],
                'revision_id' => $revision_id,
                'user_id' => $user_id,
                'attempt_number' => $attempt_number,
                'status' => 'started',
                'score_given' => 0,
                'score_maximum' => (int) $revision['question_count'],
                'points_given' => '0.0000',
                'points_maximum' => (string) $revision['points_maximum'],
                'started_at' => $now,
                'expires_at' => $expires_at,
                'finalized_at' => null,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($inserted !== 1) {
            throw new RuntimeException('assignment_attempt_write_failed');
        }
        $attempt = ll_tools_lms_assignment_get_attempt((int) $wpdb->insert_id, true);
        if (!is_array($attempt) || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_attempt_write_failed');
        }
        return [
            'attempt' => ll_tools_lms_assignment_public_attempt($attempt),
            'assignment' => [
                'assignment_uuid' => (string) $assignment['assignment_uuid'],
                'title' => (string) $assignment['title'],
            ],
            'manifest' => ll_tools_lms_assignment_public_manifest($manifest),
        ];
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        $messages = [
            'assignment_not_found' => __('The assignment was not found.', 'll-tools-text-domain'),
            'assignment_not_published' => __('This assignment is not published.', 'll-tools-text-domain'),
            'assignment_membership_required' => __('You are not a current member of this class.', 'll-tools-text-domain'),
            'assignment_not_available' => __('This assignment is not available yet.', 'll-tools-text-domain'),
            'assignment_window_closed' => __('This assignment is closed.', 'll-tools-text-domain'),
            'assignment_attempt_limit_reached' => __('You have reached the attempt limit for this assignment.', 'll-tools-text-domain'),
        ];
        $code = $error->getMessage();
        return new WP_Error($code, $messages[$code] ?? __('The assignment could not be started.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_attempt_write_failed', __('The assignment could not be started right now.', 'll-tools-text-domain'));
    }
}

/**
 * Append one idempotent answer. Correctness is derived only from the verified
 * server manifest; the public contract intentionally has no score/correct arg.
 *
 * @return array|WP_Error
 */
function ll_tools_lms_assignment_submit_answer(
    string $attempt_uuid,
    string $answer_uuid,
    string $item_key,
    string $option_key,
    int $user_id = 0
) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $user_id = (int) ($user_id ?: get_current_user_id());
    $attempt_uuid = ll_tools_lms_assignment_normalize_uuid($attempt_uuid);
    $answer_uuid = ll_tools_lms_assignment_normalize_uuid($answer_uuid);
    $item_key = ll_tools_lms_assignment_normalize_key($item_key);
    $option_key = ll_tools_lms_assignment_normalize_key($option_key);
    if ($user_id <= 0 || $attempt_uuid === '' || $answer_uuid === '' || $item_key === '' || $option_key === '') {
        return new WP_Error('invalid_assignment_answer', __('The assignment answer is invalid.', 'll-tools-text-domain'));
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The answer could not be saved right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    try {
        if (!ll_tools_lms_assignment_lock_user($user_id)) {
            throw new RuntimeException('assignment_learner_unavailable');
        }
        $attempt = ll_tools_lms_assignment_get_attempt($attempt_uuid, true);
        if (!is_array($attempt) || (int) $attempt['user_id'] !== $user_id) {
            throw new DomainException('assignment_attempt_not_found');
        }

        // Resolve an exact idempotency replay before checking mutable window,
        // membership, publication, or attempt state. It performs no new write
        // and must remain stable after the original answer was accepted.
        $existing_uuid = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['answers']} WHERE attempt_id = %d AND answer_uuid = %s",
            (int) $attempt['id'],
            $answer_uuid
        ), ARRAY_A);
        if (is_array($existing_uuid)) {
            if ((string) $existing_uuid['item_key'] !== $item_key || (string) $existing_uuid['option_key'] !== $option_key) {
                throw new DomainException('assignment_answer_replay_conflict');
            }
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('assignment_answer_write_failed');
            }
            return [
                'answer_uuid' => $answer_uuid,
                'item_key' => $item_key,
                'option_key' => $option_key,
                'is_correct' => ((int) $existing_uuid['is_correct']) === 1,
                'answered_at' => (string) $existing_uuid['answered_at'],
                'replayed' => true,
            ];
        }
        if ((string) $attempt['status'] !== 'started' || !empty($attempt['finalized_at'])) {
            throw new DomainException('assignment_attempt_finalized');
        }
        $assignment = ll_tools_lms_assignment_get((int) $attempt['assignment_id'], true);
        if (
            !is_array($assignment)
            || (string) $assignment['status'] !== 'published'
            || (int) $assignment['current_revision_id'] !== (int) $attempt['revision_id']
        ) {
            throw new DomainException('assignment_not_published');
        }
        if (!ll_tools_lms_assignment_user_is_current_member($assignment, $user_id)) {
            throw new DomainException('assignment_membership_required');
        }
        $revision = ll_tools_lms_assignment_get_revision((int) $attempt['revision_id'], true);
        if (!is_array($revision) || (int) $revision['assignment_id'] !== (int) $assignment['id']) {
            throw new RuntimeException('assignment_revision_missing');
        }
        $manifest = ll_tools_lms_assignment_revision_manifest($revision);
        if (is_wp_error($manifest)) {
            throw new RuntimeException('invalid_stored_assignment_manifest');
        }
        $now_timestamp = ll_tools_lms_assignment_now_timestamp();
        $window = ll_tools_lms_assignment_revision_window_is_open($revision, $now_timestamp);
        if (is_wp_error($window)) {
            throw new DomainException($window->get_error_code());
        }
        $expires_at = ll_tools_lms_assignment_normalize_datetime($attempt['expires_at'] ?? null);
        if (!is_string($expires_at) || $now_timestamp >= (int) strtotime($expires_at . ' UTC')) {
            throw new DomainException('assignment_attempt_expired');
        }

        $item = ll_tools_lms_assignment_manifest_item($manifest, $item_key);
        if (!is_array($item)) {
            throw new DomainException('assignment_item_not_found');
        }
        $option_found = false;
        $is_correct = false;
        foreach ((array) ($item['options'] ?? []) as $option) {
            if (is_array($option) && (string) ($option['key'] ?? '') === $option_key) {
                $option_found = true;
                $is_correct = ($option['correct'] ?? null) === true;
                break;
            }
        }
        if (!$option_found) {
            throw new DomainException('assignment_option_not_found');
        }

        $existing_item = $wpdb->get_row($wpdb->prepare(
            "SELECT answer_uuid, option_key FROM {$tables['answers']} WHERE attempt_id = %d AND item_key = %s",
            (int) $attempt['id'],
            $item_key
        ), ARRAY_A);
        if (is_array($existing_item)) {
            throw new DomainException('assignment_item_already_answered');
        }

        $answered_at = gmdate('Y-m-d H:i:s', $now_timestamp);
        $inserted = $wpdb->insert(
            $tables['answers'],
            [
                'attempt_id' => (int) $attempt['id'],
                'answer_uuid' => $answer_uuid,
                'item_key' => $item_key,
                'option_key' => $option_key,
                'is_correct' => $is_correct ? 1 : 0,
                'answered_at' => $answered_at,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
        if ($inserted !== 1 || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_answer_write_failed');
        }
        return [
            'answer_uuid' => $answer_uuid,
            'item_key' => $item_key,
            'option_key' => $option_key,
            'is_correct' => $is_correct,
            'answered_at' => $answered_at,
            'replayed' => false,
        ];
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        $messages = [
            'assignment_attempt_not_found' => __('The assignment attempt was not found.', 'll-tools-text-domain'),
            'assignment_attempt_finalized' => __('This assignment attempt is already finalized.', 'll-tools-text-domain'),
            'assignment_not_published' => __('This assignment is not published.', 'll-tools-text-domain'),
            'assignment_membership_required' => __('You are not a current member of this class.', 'll-tools-text-domain'),
            'assignment_not_available' => __('This assignment is not available yet.', 'll-tools-text-domain'),
            'assignment_window_closed' => __('This assignment is closed.', 'll-tools-text-domain'),
            'assignment_attempt_expired' => __('This assignment attempt has expired.', 'll-tools-text-domain'),
            'assignment_item_not_found' => __('The assignment item was not found.', 'll-tools-text-domain'),
            'assignment_option_not_found' => __('The assignment option was not found.', 'll-tools-text-domain'),
            'assignment_answer_replay_conflict' => __('That answer identifier was already used for different answer data.', 'll-tools-text-domain'),
            'assignment_item_already_answered' => __('This assignment item has already been answered.', 'll-tools-text-domain'),
        ];
        $code = $error->getMessage();
        return new WP_Error($code, $messages[$code] ?? __('The answer could not be saved.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_answer_write_failed', __('The answer could not be saved right now.', 'll-tools-text-domain'));
    }
}

/** @return array|null */
function ll_tools_lms_assignment_select_grade_attempt(
    int $assignment_id,
    int $revision_id,
    int $user_id,
    string $grade_policy
): ?array {
    global $wpdb;

    if (!in_array($grade_policy, ['first', 'latest', 'best'], true)) {
        return null;
    }
    if ($grade_policy === 'first') {
        $order_by = 'attempt_number ASC, id ASC';
    } elseif ($grade_policy === 'latest') {
        $order_by = 'attempt_number DESC, id DESC';
    } else {
        // Every attempt for a revision shares the same denominator, so the
        // integer score orders the ratio without floating-point comparison.
        $order_by = 'score_given DESC, attempt_number ASC, id ASC';
    }
    $table = ll_tools_lms_assignment_table_names()['attempts'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE assignment_id = %d AND revision_id = %d AND user_id = %d AND status = 'finalized'
         ORDER BY {$order_by}
         LIMIT 1",
        $assignment_id,
        $revision_id,
        $user_id
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

/**
 * Finalize and score an attempt exactly once, then select the provider-neutral
 * grade according to the immutable revision policy.
 *
 * @return array|WP_Error
 */
function ll_tools_lms_assignment_finalize_attempt(string $attempt_uuid, int $user_id = 0) {
    global $wpdb;

    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $user_id = (int) ($user_id ?: get_current_user_id());
    $attempt_uuid = ll_tools_lms_assignment_normalize_uuid($attempt_uuid);
    if ($user_id <= 0 || $attempt_uuid === '') {
        return new WP_Error('invalid_assignment_attempt', __('The assignment attempt is invalid.', 'll-tools-text-domain'));
    }

    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_transaction_unavailable', __('The assignment could not be finalized right now.', 'll-tools-text-domain'));
    }
    $tables = ll_tools_lms_assignment_table_names();
    $delivery_enqueued = 0;
    try {
        if (!ll_tools_lms_assignment_lock_user($user_id)) {
            throw new RuntimeException('assignment_learner_unavailable');
        }
        $attempt = ll_tools_lms_assignment_get_attempt($attempt_uuid, true);
        if (!is_array($attempt) || (int) $attempt['user_id'] !== $user_id) {
            throw new DomainException('assignment_attempt_not_found');
        }

        // A completed attempt is immutable. Return the persisted selection
        // without re-scoring, bumping the grade revision, or re-enqueueing.
        if ((string) $attempt['status'] === 'finalized' && !empty($attempt['finalized_at'])) {
            $grade = ll_tools_lms_assignment_get_grade(
                (int) $attempt['assignment_id'],
                (int) $attempt['revision_id'],
                $user_id,
                true
            );
            if (!is_array($grade) || !ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('assignment_grade_missing');
            }
            return [
                'attempt' => ll_tools_lms_assignment_public_attempt($attempt),
                'grade' => ll_tools_lms_assignment_public_grade($grade),
                'grade_changed' => false,
                'replayed' => true,
                'delivery_enqueued' => 0,
                'delivery_scheduled' => true,
                'delivery_schedule_required' => false,
            ];
        }
        if ((string) $attempt['status'] !== 'started' || !empty($attempt['finalized_at'])) {
            throw new DomainException('assignment_attempt_unavailable');
        }

        $assignment = ll_tools_lms_assignment_get((int) $attempt['assignment_id'], true);
        if (
            !is_array($assignment)
            || (string) $assignment['status'] !== 'published'
            || (int) $assignment['current_revision_id'] !== (int) $attempt['revision_id']
        ) {
            throw new DomainException('assignment_not_published');
        }
        if (!ll_tools_lms_assignment_user_is_current_member($assignment, $user_id)) {
            throw new DomainException('assignment_membership_required');
        }
        $revision = ll_tools_lms_assignment_get_revision((int) $attempt['revision_id'], true);
        if (!is_array($revision) || (int) $revision['assignment_id'] !== (int) $assignment['id']) {
            throw new RuntimeException('assignment_revision_missing');
        }
        $manifest = ll_tools_lms_assignment_revision_manifest($revision);
        if (is_wp_error($manifest)) {
            throw new RuntimeException('invalid_stored_assignment_manifest');
        }
        $question_count = count((array) ($manifest['items'] ?? []));
        if ($question_count < LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MIN_ITEMS || $question_count !== (int) $revision['question_count']) {
            throw new RuntimeException('invalid_stored_assignment_manifest');
        }
        $now_timestamp = ll_tools_lms_assignment_now_timestamp();
        $window = ll_tools_lms_assignment_revision_window_is_open($revision, $now_timestamp);
        if (is_wp_error($window)) {
            throw new DomainException($window->get_error_code());
        }
        $expires_at = ll_tools_lms_assignment_normalize_datetime($attempt['expires_at'] ?? null);
        if (!is_string($expires_at) || $now_timestamp >= (int) strtotime($expires_at . ' UTC')) {
            throw new DomainException('assignment_attempt_expired');
        }

        $wpdb->last_error = '';
        $score_value = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['answers']} WHERE attempt_id = %d AND is_correct = 1",
            (int) $attempt['id']
        ));
        if ($score_value === null || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('assignment_score_read_failed');
        }
        $score_given = max(0, (int) $score_value);
        $score_given = min($question_count, $score_given);
        $points_maximum = number_format((float) $revision['points_maximum'], 4, '.', '');
        $maximum_units = max(1, (int) round(((float) $points_maximum) * 10000));
        $given_units = (int) floor((($maximum_units * $score_given) / $question_count) + 0.5);
        $points_given = number_format($given_units / 10000, 4, '.', '');
        $now = gmdate('Y-m-d H:i:s', $now_timestamp);

        $attempt_updated = $wpdb->update(
            $tables['attempts'],
            [
                'status' => 'finalized',
                'score_given' => $score_given,
                'score_maximum' => $question_count,
                'points_given' => $points_given,
                'points_maximum' => $points_maximum,
                'finalized_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => (int) $attempt['id'], 'user_id' => $user_id, 'status' => 'started'],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s'],
            ['%d', '%d', '%s']
        );
        if ($attempt_updated !== 1) {
            throw new RuntimeException('assignment_finalize_write_failed');
        }

        $grade_policy = (string) $revision['grade_policy'];
        $selected_attempt = ll_tools_lms_assignment_select_grade_attempt(
            (int) $assignment['id'],
            (int) $revision['id'],
            $user_id,
            $grade_policy
        );
        if (!is_array($selected_attempt)) {
            throw new RuntimeException('assignment_grade_selection_failed');
        }
        $existing_grade = ll_tools_lms_assignment_get_grade(
            (int) $assignment['id'],
            (int) $revision['id'],
            $user_id,
            true
        );
        $grade_changed = !is_array($existing_grade)
            || (int) $existing_grade['selected_attempt_id'] !== (int) $selected_attempt['id']
            || (string) $existing_grade['grade_policy'] !== $grade_policy;
        $grade_revision = is_array($existing_grade)
            ? max(1, (int) $existing_grade['grade_revision'])
            : 0;

        if ($grade_changed) {
            $grade_revision++;
            $grade_values = [
                'selected_attempt_id' => (int) $selected_attempt['id'],
                'grade_revision' => $grade_revision,
                'score_given' => (int) $selected_attempt['score_given'],
                'score_maximum' => (int) $selected_attempt['score_maximum'],
                'points_given' => (string) $selected_attempt['points_given'],
                'points_maximum' => (string) $selected_attempt['points_maximum'],
                'grade_policy' => $grade_policy,
                'updated_at' => $now,
            ];
            if (is_array($existing_grade)) {
                $grade_written = $wpdb->update(
                    $tables['grades'],
                    $grade_values,
                    [
                        'assignment_id' => (int) $assignment['id'],
                        'revision_id' => (int) $revision['id'],
                        'user_id' => $user_id,
                    ],
                    ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
                    ['%d', '%d', '%d']
                );
            } else {
                $grade_written = $wpdb->insert(
                    $tables['grades'],
                    array_merge(
                        [
                            'assignment_id' => (int) $assignment['id'],
                            'revision_id' => (int) $revision['id'],
                            'user_id' => $user_id,
                        ],
                        $grade_values
                    ),
                    ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
                );
            }
            if ($grade_written !== 1) {
                throw new RuntimeException('assignment_grade_write_failed');
            }

            if (function_exists('ll_tools_grade_delivery_enqueue_current_grade')) {
                $delivery_result = ll_tools_grade_delivery_enqueue_current_grade(
                    (int) $assignment['id'],
                    (int) $revision['id'],
                    $user_id,
                    $grade_revision
                );
                if (is_wp_error($delivery_result) || $delivery_result === false) {
                    throw new RuntimeException('assignment_grade_delivery_enqueue_failed');
                }
                if (is_array($delivery_result)) {
                    $delivery_enqueued = max(0, (int) ($delivery_result['enqueued'] ?? 0));
                } elseif (is_numeric($delivery_result)) {
                    $delivery_enqueued = max(0, (int) $delivery_result);
                }
            }
        }

        $attempt = ll_tools_lms_assignment_get_attempt((int) $attempt['id'], true);
        $grade = ll_tools_lms_assignment_get_grade((int) $assignment['id'], (int) $revision['id'], $user_id, true);
        if (!is_array($attempt) || !is_array($grade) || !ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_finalize_write_failed');
        }

        // Releasing a savepoint does not commit its caller-owned transaction.
        // Never publish cron state before the authoritative grade/outbox rows
        // are durable; an embedding caller must schedule after its own COMMIT.
        $delivery_schedule_required = $delivery_enqueued > 0
            && ($transaction['type'] ?? '') === 'savepoint';
        $delivery_scheduled = $delivery_enqueued === 0;
        if ($delivery_enqueued > 0 && !$delivery_schedule_required) {
            if (function_exists('ll_tools_grade_delivery_schedule_worker')) {
                try {
                    $delivery_scheduled = ll_tools_grade_delivery_schedule_worker();
                } catch (Throwable $schedule_error) {
                    // The authoritative grade and outbox rows are already
                    // durable. The bounded runtime resumer can retry safely.
                    $delivery_scheduled = false;
                }
            } else {
                $delivery_scheduled = false;
            }
        }
        return [
            'attempt' => ll_tools_lms_assignment_public_attempt($attempt),
            'grade' => ll_tools_lms_assignment_public_grade($grade),
            'grade_changed' => $grade_changed,
            'replayed' => false,
            'delivery_enqueued' => $delivery_enqueued,
            'delivery_scheduled' => $delivery_scheduled,
            'delivery_schedule_required' => $delivery_schedule_required,
        ];
    } catch (DomainException $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        $messages = [
            'assignment_attempt_not_found' => __('The assignment attempt was not found.', 'll-tools-text-domain'),
            'assignment_attempt_unavailable' => __('This assignment attempt is unavailable.', 'll-tools-text-domain'),
            'assignment_not_published' => __('This assignment is not published.', 'll-tools-text-domain'),
            'assignment_membership_required' => __('You are not a current member of this class.', 'll-tools-text-domain'),
            'assignment_not_available' => __('This assignment is not available yet.', 'll-tools-text-domain'),
            'assignment_window_closed' => __('This assignment is closed.', 'll-tools-text-domain'),
            'assignment_attempt_expired' => __('This assignment attempt has expired.', 'll-tools-text-domain'),
        ];
        $code = $error->getMessage();
        return new WP_Error($code, $messages[$code] ?? __('The assignment could not be finalized.', 'll-tools-text-domain'));
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_finalize_write_failed', __('The assignment could not be finalized right now.', 'll-tools-text-domain'));
    }
}

/**
 * Return one bounded WordPress-exporter page of learner attempts and answers.
 * Correct answer keys and the private server manifest are never exported.
 *
 * @return array{data:array<int,array<string,mixed>>,done:bool}|WP_Error
 */
function ll_tools_lms_assignment_privacy_export_items(int $user_id, int $page, int $per_page = 25) {
    global $wpdb;

    if ($user_id <= 0) {
        return new WP_Error('invalid_assignment_privacy_user', __('The assignment learner is invalid.', 'll-tools-text-domain'));
    }
    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $page = max(1, min(100000, $page));
    $per_page = max(1, min(25, $per_page));
    $offset = ($page - 1) * $per_page;
    $tables = ll_tools_lms_assignment_table_names();
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT at.id, at.attempt_uuid, at.assignment_id, at.revision_id,
                at.attempt_number, at.status, at.score_given, at.score_maximum,
                at.points_given, at.points_maximum, at.started_at, at.expires_at,
                at.finalized_at, at.updated_at, ass.title, rev.revision_number,
                grade.selected_attempt_id, grade.grade_revision
         FROM {$tables['attempts']} at
         INNER JOIN {$tables['assignments']} ass ON ass.id = at.assignment_id
         INNER JOIN {$tables['revisions']} rev ON rev.id = at.revision_id AND rev.assignment_id = at.assignment_id
         LEFT JOIN {$tables['grades']} grade
           ON grade.assignment_id = at.assignment_id
          AND grade.revision_id = at.revision_id
          AND grade.user_id = at.user_id
         WHERE at.user_id = %d
         ORDER BY at.updated_at ASC, at.id ASC
         LIMIT %d OFFSET %d",
        $user_id,
        $per_page + 1,
        $offset
    ), ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== '') {
        return new WP_Error('assignment_privacy_export_failed', __('Assignment data could not be exported.', 'll-tools-text-domain'));
    }
    $has_more = count($rows) > $per_page;
    $rows = array_slice($rows, 0, $per_page);
    $attempt_ids = array_values(array_filter(array_map(static function (array $row): int {
        return max(0, (int) ($row['id'] ?? 0));
    }, $rows)));
    $answers_by_attempt = [];
    if ($attempt_ids !== []) {
        $answer_limit = count($attempt_ids) * LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_HARD_MAX_ITEMS;
        $answer_rows = $wpdb->get_results(
            "SELECT attempt_id, item_key, option_key, answered_at
             FROM {$tables['answers']}
             WHERE attempt_id IN (" . implode(',', $attempt_ids) . ')
             ORDER BY attempt_id ASC, id ASC
             LIMIT ' . (int) $answer_limit,
            ARRAY_A
        );
        if (!is_array($answer_rows) || (string) $wpdb->last_error !== '') {
            return new WP_Error('assignment_privacy_export_failed', __('Assignment data could not be exported.', 'll-tools-text-domain'));
        }
        foreach ($answer_rows as $answer_row) {
            $answer_attempt_id = max(0, (int) ($answer_row['attempt_id'] ?? 0));
            if ($answer_attempt_id > 0) {
                $answers_by_attempt[$answer_attempt_id][] = $answer_row;
            }
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $attempt_id = (int) $row['id'];
        $data = [
            ['name' => __('Assignment', 'll-tools-text-domain'), 'value' => (string) $row['title']],
            ['name' => __('Assignment revision', 'll-tools-text-domain'), 'value' => (int) $row['revision_number']],
            ['name' => __('Attempt number', 'll-tools-text-domain'), 'value' => (int) $row['attempt_number']],
            ['name' => __('Status', 'll-tools-text-domain'), 'value' => (string) $row['status']],
            ['name' => __('Score', 'll-tools-text-domain'), 'value' => (int) $row['score_given'] . ' / ' . (int) $row['score_maximum']],
            ['name' => __('Points', 'll-tools-text-domain'), 'value' => (string) $row['points_given'] . ' / ' . (string) $row['points_maximum']],
            ['name' => __('Started at', 'll-tools-text-domain'), 'value' => (string) $row['started_at']],
            ['name' => __('Expires at', 'll-tools-text-domain'), 'value' => (string) $row['expires_at']],
            ['name' => __('Finalized at', 'll-tools-text-domain'), 'value' => (string) ($row['finalized_at'] ?? '')],
            [
                'name' => __('Selected grade', 'll-tools-text-domain'),
                'value' => (int) ($row['selected_attempt_id'] ?? 0) === $attempt_id
                    ? __('Yes', 'll-tools-text-domain')
                    : __('No', 'll-tools-text-domain'),
            ],
            ['name' => __('Grade revision', 'll-tools-text-domain'), 'value' => max(0, (int) ($row['grade_revision'] ?? 0))],
        ];
        foreach ((array) ($answers_by_attempt[$attempt_id] ?? []) as $answer_row) {
            $data[] = [
                'name' => sprintf(
                    /* translators: %s is the stable assignment item key. */
                    __('Response to %s', 'll-tools-text-domain'),
                    (string) ($answer_row['item_key'] ?? '')
                ),
                'value' => (string) ($answer_row['option_key'] ?? '') . ' @ ' . (string) ($answer_row['answered_at'] ?? ''),
            ];
        }
        $items[] = [
            'group_id' => 'll-tools-lms-assignments',
            'group_label' => __('LL Tools Assignments', 'll-tools-text-domain'),
            'item_id' => 'll-tools-lms-attempt-' . $attempt_id,
            'data' => $data,
        ];
    }

    return ['data' => $items, 'done' => !$has_more];
}

/**
 * Transactionally erase one bounded learner-data batch. Call until done=true.
 * Shared assignments and immutable revision manifests are never deleted.
 *
 * @return array{removed:int,stage:string,done:bool}|WP_Error
 */
function ll_tools_lms_assignment_erase_user_data(int $user_id) {
    global $wpdb;

    if ($user_id <= 0) {
        return new WP_Error('invalid_assignment_privacy_user', __('The assignment learner is invalid.', 'll-tools-text-domain'));
    }
    if (!ll_tools_lms_assignment_schema_is_available()) {
        return ll_tools_lms_assignment_schema_error();
    }
    $batch_size = (int) apply_filters('ll_tools_lms_assignment_erasure_batch_size', 25);
    $batch_size = max(1, min(100, $batch_size));
    $tables = ll_tools_lms_assignment_table_names();
    $transaction = ll_tools_lms_assignment_begin_transaction();
    if ($transaction === null) {
        return new WP_Error('assignment_privacy_transaction_unavailable', __('Assignment data could not be erased right now.', 'll-tools-text-domain'));
    }

    try {
        // Erasure owns the deletion/privacy fence, so it must bypass the
        // normal write-admission helper while still serializing against any
        // request that acquired the learner row before the fence was set.
        if (get_userdata($user_id)) {
            $wpdb->last_error = '';
            $locked_user_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
                $user_id
            ));
            if ($locked_user_id !== $user_id || (string) $wpdb->last_error !== '') {
                throw new RuntimeException('assignment_privacy_user_lock_failed');
            }
        }

        // Grades reference attempts, so remove selected-grade rows first.
        $grade_keys = $wpdb->get_results($wpdb->prepare(
            "SELECT assignment_id, revision_id
             FROM {$tables['grades']}
             WHERE user_id = %d
             ORDER BY updated_at ASC, assignment_id ASC
             LIMIT %d
             FOR UPDATE",
            $user_id,
            $batch_size
        ), ARRAY_A);
        if (!is_array($grade_keys) || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('assignment_privacy_grade_read_failed');
        }
        if ($grade_keys !== []) {
            $removed = 0;
            foreach ($grade_keys as $grade_key) {
                $deleted = $wpdb->delete(
                    $tables['grades'],
                    [
                        'assignment_id' => (int) $grade_key['assignment_id'],
                        'revision_id' => (int) $grade_key['revision_id'],
                        'user_id' => $user_id,
                    ],
                    ['%d', '%d', '%d']
                );
                if ($deleted === false) {
                    throw new RuntimeException('assignment_privacy_grade_delete_failed');
                }
                $removed += (int) $deleted;
            }
            $wpdb->last_error = '';
            $grades_remain = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$tables['grades']} WHERE user_id = %d LIMIT 1",
                $user_id
            )) > 0;
            if ((string) $wpdb->last_error !== '') {
                throw new RuntimeException('assignment_privacy_grade_read_failed');
            }
            $wpdb->last_error = '';
            $attempts_remain = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$tables['attempts']} WHERE user_id = %d LIMIT 1",
                $user_id
            )) > 0;
            if ((string) $wpdb->last_error !== '') {
                throw new RuntimeException('assignment_privacy_attempt_read_failed');
            }
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('assignment_privacy_commit_failed');
            }
            return [
                'removed' => $removed,
                'stage' => 'grades',
                'done' => !$grades_remain && !$attempts_remain,
            ];
        }

        $wpdb->last_error = '';
        $attempt_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$tables['attempts']}
             WHERE user_id = %d
             ORDER BY updated_at ASC, id ASC
             LIMIT %d
             FOR UPDATE",
            $user_id,
            $batch_size
        ));
        if (!is_array($attempt_ids) || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('assignment_privacy_attempt_read_failed');
        }
        $attempt_ids = array_values(array_filter(array_map('intval', $attempt_ids), static function (int $attempt_id): bool {
            return $attempt_id > 0;
        }));
        if ($attempt_ids === []) {
            if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
                throw new RuntimeException('assignment_privacy_commit_failed');
            }
            return ['removed' => 0, 'stage' => 'complete', 'done' => true];
        }

        $id_list = implode(',', $attempt_ids);
        $answers_deleted = $wpdb->query(
            "DELETE FROM {$tables['answers']} WHERE attempt_id IN ({$id_list})"
        );
        $attempts_deleted = $wpdb->query(
            "DELETE FROM {$tables['attempts']} WHERE user_id = " . (int) $user_id . " AND id IN ({$id_list})"
        );
        if ($answers_deleted === false || $attempts_deleted === false) {
            throw new RuntimeException('assignment_privacy_attempt_delete_failed');
        }
        $wpdb->last_error = '';
        $attempts_remain = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$tables['attempts']} WHERE user_id = %d LIMIT 1",
            $user_id
        )) > 0;
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('assignment_privacy_attempt_read_failed');
        }
        if (!ll_tools_lms_assignment_commit_transaction($transaction)) {
            throw new RuntimeException('assignment_privacy_commit_failed');
        }
        return [
            'removed' => (int) $answers_deleted + (int) $attempts_deleted,
            'stage' => 'attempts',
            'done' => !$attempts_remain,
        ];
    } catch (Throwable $error) {
        ll_tools_lms_assignment_rollback_transaction($transaction);
        return new WP_Error('assignment_privacy_erasure_failed', __('Assignment data could not be erased right now.', 'll-tools-text-domain'));
    }
}
