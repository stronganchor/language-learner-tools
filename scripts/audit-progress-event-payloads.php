<?php
/**
 * Read-only inventory for LL Tools progress-event payload keys.
 *
 * Run with:
 *   wp eval-file scripts/audit-progress-event-payloads.php
 *
 * The report intentionally omits payload values and learner identifiers.
 * Unknown key names are hashed unless they match a conservative schema-key
 * pattern and LL_TOOLS_AUDIT_SHOW_SAFE_UNKNOWN_KEYS=1 is set in the process
 * environment.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

global $wpdb;

$fail_source_read = static function (string $stage): void {
    fwrite(STDERR, wp_json_encode([
        'schema' => 1,
        'complete' => false,
        'error_code' => 'progress_event_audit_source_read_failed',
        'stage' => sanitize_key($stage),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
};

$guarded_read = static function (string $stage, callable $callback) use ($wpdb, $fail_source_read) {
    $wpdb->last_error = '';
    $previous_suppress_errors = $wpdb->suppress_errors(true);
    try {
        $value = $callback();
        $last_error = (string) $wpdb->last_error;
    } finally {
        $wpdb->suppress_errors($previous_suppress_errors);
    }
    if ($last_error !== '') {
        $fail_source_read($stage);
    }
    return $value;
};

$table = $wpdb->prefix . 'll_tools_user_progress_events';
$table_lookup_sql = $wpdb->prepare('SHOW TABLES LIKE %s', $table);
$table_exists = $guarded_read('table_lookup', static function () use ($wpdb, $table_lookup_sql) {
    return $wpdb->get_var($table_lookup_sql);
});
if ((string) $table_exists !== $table) {
    echo wp_json_encode([
        'schema' => 1,
        'table_exists' => false,
        'table' => $table,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

$batch_size = 500;
$scan_limit = 50000;
$start_id = max(0, (int) getenv('LL_TOOLS_AUDIT_START_ID'));
$requested_high_water = max(0, (int) getenv('LL_TOOLS_AUDIT_HIGH_WATER_ID'));
$current_high_water_value = $guarded_read('high_water', static function () use ($wpdb, $table) {
    return $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$table}");
});
if ($current_high_water_value === null) {
    $fail_source_read('high_water');
}
$current_high_water = (int) $current_high_water_value;
$high_water = $requested_high_water > 0
    ? min($requested_high_water, $current_high_water)
    : $current_high_water;
$total_rows_sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE id <= %d",
    $high_water
);
$total_rows_value = $guarded_read('total_count', static function () use ($wpdb, $total_rows_sql) {
    return $wpdb->get_var($total_rows_sql);
});
if ($total_rows_value === null) {
    $fail_source_read('total_count');
}
$total_rows = (int) $total_rows_value;

$window_rows_sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE id > %d AND id <= %d",
    $start_id,
    $high_water
);
$window_rows_value = $guarded_read('window_count', static function () use ($wpdb, $window_rows_sql) {
    return $wpdb->get_var($window_rows_sql);
});
if ($window_rows_value === null) {
    $fail_source_read('window_count');
}
$window_rows = (int) $window_rows_value;
$show_safe_unknown_keys = getenv('LL_TOOLS_AUDIT_SHOW_SAFE_UNKNOWN_KEYS') === '1';

$allowed_payload_keys = [
    'word_exposure' => [
        'prompt_card_id',
        'recording_type',
        'available_recording_types',
        'game_slug',
        'event_source',
        'speaking_target_field',
        'sequence_direction',
        'sequence_length',
        'prompt_type',
        'tile_count',
    ],
    'word_outcome' => [
        'prompt_card_id',
        'recording_type',
        'available_recording_types',
        'audio_progress_ratio',
        'answered_before_audio_end',
        'gender',
        'gender_answer_timing',
        'gender_dont_know',
        'self_check_confidence',
        'self_check_result',
        'self_check_bucket',
        'self_check_group_key',
        'self_check_group_size',
        'forced_prompt',
        'game_slug',
        'event_source',
        'wrong_hit',
        'timeout',
        'stack_end_reason',
        'sequence_category_id',
        'sequence_category_name',
        'sequence_direction',
        'sequence_length',
        'sequence_attempts',
        'prompt_type',
        'tile_count',
        'moves',
        'needed_retry',
        'speaking_game_bucket',
        'speaking_score',
        'stt_provider',
        'speaking_target_field',
    ],
    'category_study' => ['units'],
    'mode_session_complete' => ['category_ids', 'result'],
    'stt_api_call' => ['source', 'provider', 'target_field'],
];

$allowed_nested_keys = [
    'gender' => [
        'level',
        'confidence',
        'intro_seen',
        'quick_correct_streak',
        'level1_passes',
        'level1_failures',
        'level2_correct',
        'level2_wrong',
        'level3_correct',
        'level3_wrong',
        'dont_know_count',
        'seen_total',
        'category_name',
        'last_seen_at',
        'updated_at',
        'updated_at_ms',
    ],
    'result' => [
        'schema',
        'kind',
        'score_given',
        'score_maximum',
        'score_basis',
    ],
];

$allowed_lookup = [];
foreach ($allowed_payload_keys as $event_type => $keys) {
    $allowed_lookup[$event_type] = array_fill_keys($keys, true);
}
$nested_lookup = [];
foreach ($allowed_nested_keys as $nested_name => $keys) {
    $nested_lookup[$nested_name] = array_fill_keys($keys, true);
}

$report = [
    'schema' => 1,
    'table_exists' => true,
    'table' => $table,
    'start_id' => $start_id,
    'high_water_id' => $high_water,
    'rows_at_high_water' => $total_rows,
    'rows_in_scan_window' => $window_rows,
    'scan_limit' => $scan_limit,
    'batch_size' => $batch_size,
    'rows_scanned' => 0,
    'last_scanned_id' => $start_id,
    'complete' => true,
    'malformed_payloads' => 0,
    'non_object_payloads' => 0,
    'max_payload_bytes' => 0,
    'event_type_counts' => [],
    'event_type_mode_counts' => [],
    'payload_key_counts' => [],
    'nested_key_counts' => [
        'gender' => [],
        'result' => [],
    ],
    'unknown_event_type_counts' => [],
    'unknown_payload_key_hashes' => [],
    'unknown_nested_key_hashes' => [
        'gender' => [],
        'result' => [],
    ],
    'safe_unknown_payload_key_names' => [],
    'safe_unknown_nested_key_names' => [
        'gender' => [],
        'result' => [],
    ],
    'payload_identity_key_counts' => [
        'device_id' => 0,
        'profile_id' => 0,
    ],
    'invalid_prompt_card_id_count' => 0,
];

$record_unknown_key = static function (
    array &$hash_counts,
    array &$safe_name_counts,
    string $key
) use ($show_safe_unknown_keys): void {
    $hash = hash('sha256', $key);
    $hash_counts[$hash] = (int) ($hash_counts[$hash] ?? 0) + 1;
    if ($show_safe_unknown_keys && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)) {
        $safe_name_counts[$key] = (int) ($safe_name_counts[$key] ?? 0) + 1;
    }
};

$cursor = $start_id;
while ($cursor < $high_water && $report['rows_scanned'] < $scan_limit) {
    $remaining = $scan_limit - $report['rows_scanned'];
    $limit = min($batch_size, $remaining);
    $batch_sql = $wpdb->prepare(
        "SELECT id, event_type, mode, OCTET_LENGTH(payload_json) AS payload_bytes, payload_json
         FROM {$table}
         WHERE id > %d AND id <= %d
         ORDER BY id ASC
         LIMIT %d",
        $cursor,
        $high_water,
        $limit
    );
    $rows = $guarded_read('batch', static function () use ($wpdb, $batch_sql) {
        return $wpdb->get_results($batch_sql, ARRAY_A);
    });

    if (!is_array($rows)) {
        $fail_source_read('batch');
    }
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $row_id = (int) ($row['id'] ?? 0);
        $cursor = max($cursor, $row_id);
        $report['last_scanned_id'] = $cursor;
        $report['rows_scanned']++;
        $report['max_payload_bytes'] = max(
            $report['max_payload_bytes'],
            max(0, (int) ($row['payload_bytes'] ?? 0))
        );

        $event_type = strtolower(trim((string) ($row['event_type'] ?? '')));
        $mode = strtolower(trim((string) ($row['mode'] ?? '')));
        $report['event_type_counts'][$event_type] = (int) ($report['event_type_counts'][$event_type] ?? 0) + 1;
        $type_mode_key = $event_type . '|' . $mode;
        $report['event_type_mode_counts'][$type_mode_key] = (int) ($report['event_type_mode_counts'][$type_mode_key] ?? 0) + 1;

        if (!isset($allowed_lookup[$event_type])) {
            $report['unknown_event_type_counts'][$event_type] = (int) ($report['unknown_event_type_counts'][$event_type] ?? 0) + 1;
        }

        $payload_json = (string) ($row['payload_json'] ?? '');
        if ($payload_json === '') {
            $payload = [];
        } else {
            $payload = json_decode($payload_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $report['malformed_payloads']++;
                continue;
            }
            if (!is_array($payload) || ll_tools_array_is_list($payload)) {
                $report['non_object_payloads']++;
                continue;
            }
        }

        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if ($key === 'device_id' || $key === 'profile_id') {
                $report['payload_identity_key_counts'][$key]++;
            }

            if (!isset($allowed_lookup[$event_type][$key])) {
                $record_unknown_key(
                    $report['unknown_payload_key_hashes'],
                    $report['safe_unknown_payload_key_names'],
                    $key
                );
                continue;
            }

            if (!isset($report['payload_key_counts'][$event_type])) {
                $report['payload_key_counts'][$event_type] = [];
            }
            $report['payload_key_counts'][$event_type][$key] = (int) ($report['payload_key_counts'][$event_type][$key] ?? 0) + 1;

            if ($event_type === 'word_outcome' && $key === 'gender' && is_array($value) && !ll_tools_array_is_list($value)) {
                foreach ($value as $nested_key => $_nested_value) {
                    $nested_key = (string) $nested_key;
                    if (isset($nested_lookup['gender'][$nested_key])) {
                        $report['nested_key_counts']['gender'][$nested_key] = (int) ($report['nested_key_counts']['gender'][$nested_key] ?? 0) + 1;
                    } else {
                        $record_unknown_key(
                            $report['unknown_nested_key_hashes']['gender'],
                            $report['safe_unknown_nested_key_names']['gender'],
                            $nested_key
                        );
                    }
                }
            }

            if ($event_type === 'mode_session_complete' && $key === 'result' && is_array($value) && !ll_tools_array_is_list($value)) {
                foreach ($value as $nested_key => $_nested_value) {
                    $nested_key = (string) $nested_key;
                    if (isset($nested_lookup['result'][$nested_key])) {
                        $report['nested_key_counts']['result'][$nested_key] = (int) ($report['nested_key_counts']['result'][$nested_key] ?? 0) + 1;
                    } else {
                        $record_unknown_key(
                            $report['unknown_nested_key_hashes']['result'],
                            $report['safe_unknown_nested_key_names']['result'],
                            $nested_key
                        );
                    }
                }
            }
        }

        if (array_key_exists('prompt_card_id', $payload) && (int) $payload['prompt_card_id'] <= 0) {
            $report['invalid_prompt_card_id_count']++;
        }
    }

    if (count($rows) < $limit) {
        break;
    }
}

$report['complete'] = $cursor >= $high_water || $window_rows <= $report['rows_scanned'];

$sort_recursive = static function (&$value) use (&$sort_recursive): void {
    if (!is_array($value)) {
        return;
    }
    foreach ($value as &$child) {
        $sort_recursive($child);
    }
    unset($child);
    if (!ll_tools_array_is_list($value)) {
        ksort($value);
    }
};
$sort_recursive($report);

echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
