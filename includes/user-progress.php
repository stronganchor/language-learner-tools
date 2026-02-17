<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION')) {
    define('LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION', '1.0.0');
}
if (!defined('LL_TOOLS_USER_PROGRESS_VERSION_OPTION')) {
    define('LL_TOOLS_USER_PROGRESS_VERSION_OPTION', 'll_tools_user_progress_schema_version');
}
if (!defined('LL_TOOLS_USER_GOALS_META')) {
    define('LL_TOOLS_USER_GOALS_META', 'll_user_study_goals');
}
if (!defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META')) {
    define('LL_TOOLS_USER_CATEGORY_PROGRESS_META', 'll_user_study_category_progress');
}

/**
 * Minimal access helper shared by dashboard AJAX endpoints.
 */
if (!function_exists('ll_tools_user_study_can_access')) {
    function ll_tools_user_study_can_access($user_id = 0): bool {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0) {
            return false;
        }

        if (current_user_can('manage_options') || current_user_can('view_ll_tools')) {
            return true;
        }

        // Keep learner-facing study tools available to normal logged-in users unless overridden.
        return (bool) apply_filters('ll_tools_allow_basic_user_study_access', current_user_can('read'), $uid);
    }
}

function ll_tools_user_progress_table_names(): array {
    global $wpdb;
    return [
        'words'  => $wpdb->prefix . 'll_tools_user_word_progress',
        'events' => $wpdb->prefix . 'll_tools_user_progress_events',
    ];
}

function ll_tools_progress_modes(): array {
    return ['learning', 'practice', 'listening', 'gender', 'self-check'];
}

function ll_tools_normalize_progress_mode($mode): string {
    $raw = is_string($mode) ? strtolower(trim($mode)) : '';
    if ($raw === 'self_check') {
        $raw = 'self-check';
    }
    return in_array($raw, ll_tools_progress_modes(), true) ? $raw : 'practice';
}

function ll_tools_progress_mode_column(string $mode): string {
    $map = [
        'learning'   => 'coverage_learning',
        'practice'   => 'coverage_practice',
        'listening'  => 'coverage_listening',
        'gender'     => 'coverage_gender',
        'self-check' => 'coverage_self_check',
    ];
    return $map[$mode] ?? 'coverage_practice';
}

function ll_tools_install_user_progress_schema(): void {
    global $wpdb;
    $tables = ll_tools_user_progress_table_names();
    $words_table = $tables['words'];
    $events_table = $tables['events'];

    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_words = "CREATE TABLE {$words_table} (
        user_id bigint(20) unsigned NOT NULL,
        word_id bigint(20) unsigned NOT NULL,
        category_id bigint(20) unsigned NOT NULL DEFAULT 0,
        wordset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        first_seen_at datetime NOT NULL,
        last_seen_at datetime NOT NULL,
        last_mode varchar(32) NOT NULL DEFAULT '',
        total_coverage int(10) unsigned NOT NULL DEFAULT 0,
        coverage_learning int(10) unsigned NOT NULL DEFAULT 0,
        coverage_practice int(10) unsigned NOT NULL DEFAULT 0,
        coverage_listening int(10) unsigned NOT NULL DEFAULT 0,
        coverage_gender int(10) unsigned NOT NULL DEFAULT 0,
        coverage_self_check int(10) unsigned NOT NULL DEFAULT 0,
        correct_clean int(10) unsigned NOT NULL DEFAULT 0,
        correct_after_retry int(10) unsigned NOT NULL DEFAULT 0,
        incorrect int(10) unsigned NOT NULL DEFAULT 0,
        lapse_count int(10) unsigned NOT NULL DEFAULT 0,
        stage smallint(5) unsigned NOT NULL DEFAULT 0,
        due_at datetime NULL DEFAULT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (user_id, word_id),
        KEY idx_user_due (user_id, due_at),
        KEY idx_user_category (user_id, category_id),
        KEY idx_user_wordset (user_id, wordset_id),
        KEY idx_word (word_id)
    ) {$charset_collate};";

    $sql_events = "CREATE TABLE {$events_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        event_uuid varchar(64) NOT NULL,
        event_type varchar(40) NOT NULL,
        mode varchar(32) NOT NULL DEFAULT '',
        word_id bigint(20) unsigned NOT NULL DEFAULT 0,
        category_id bigint(20) unsigned NOT NULL DEFAULT 0,
        wordset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        is_correct tinyint(1) NULL DEFAULT NULL,
        had_wrong_before tinyint(1) NOT NULL DEFAULT 0,
        payload_json longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_event_uuid (event_uuid),
        KEY idx_user_created (user_id, created_at),
        KEY idx_user_word (user_id, word_id),
        KEY idx_user_category (user_id, category_id)
    ) {$charset_collate};";

    dbDelta($sql_words);
    dbDelta($sql_events);
    update_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION, false);
}

function ll_tools_maybe_upgrade_user_progress_schema(): void {
    $installed = (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, '');
    if ($installed === LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION) {
        return;
    }
    ll_tools_install_user_progress_schema();
}
add_action('init', 'll_tools_maybe_upgrade_user_progress_schema', 12);

function ll_tools_default_user_study_goals(): array {
    return [
        'enabled_modes' => ll_tools_progress_modes(),
        'ignored_category_ids' => [],
        'preferred_wordset_ids' => [],
        'placement_known_category_ids' => [],
        'daily_new_word_target' => 2,
    ];
}

function ll_tools_sanitize_user_study_goals(array $raw): array {
    $defaults = ll_tools_default_user_study_goals();

    $enabled = isset($raw['enabled_modes']) ? (array) $raw['enabled_modes'] : $defaults['enabled_modes'];
    $enabled = array_values(array_unique(array_filter(array_map('ll_tools_normalize_progress_mode', $enabled))));
    if (empty($enabled)) {
        $enabled = $defaults['enabled_modes'];
    }

    $ignored = isset($raw['ignored_category_ids']) ? (array) $raw['ignored_category_ids'] : [];
    $ignored = array_values(array_unique(array_filter(array_map('intval', $ignored), function ($id) {
        return $id > 0;
    })));

    $preferred_wordsets = isset($raw['preferred_wordset_ids']) ? (array) $raw['preferred_wordset_ids'] : [];
    $preferred_wordsets = array_values(array_unique(array_filter(array_map('intval', $preferred_wordsets), function ($id) {
        return $id > 0;
    })));

    $placement = isset($raw['placement_known_category_ids']) ? (array) $raw['placement_known_category_ids'] : [];
    $placement = array_values(array_unique(array_filter(array_map('intval', $placement), function ($id) {
        return $id > 0;
    })));

    $daily = isset($raw['daily_new_word_target']) ? (int) $raw['daily_new_word_target'] : (int) $defaults['daily_new_word_target'];
    $daily = max(0, min(12, $daily));

    return [
        'enabled_modes' => $enabled,
        'ignored_category_ids' => $ignored,
        'preferred_wordset_ids' => $preferred_wordsets,
        'placement_known_category_ids' => $placement,
        'daily_new_word_target' => $daily,
    ];
}

function ll_tools_get_user_study_goals($user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return ll_tools_default_user_study_goals();
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_GOALS_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    return ll_tools_sanitize_user_study_goals($raw);
}

function ll_tools_save_user_study_goals(array $goals, $user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return ll_tools_default_user_study_goals();
    }
    $normalized = ll_tools_sanitize_user_study_goals($goals);
    update_user_meta($uid, LL_TOOLS_USER_GOALS_META, $normalized);
    return $normalized;
}

function ll_tools_get_user_category_progress($user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return [];
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
    if (!is_array($raw)) {
        return [];
    }

    $modes = ll_tools_progress_modes();
    $out = [];
    foreach ($raw as $cid => $entry) {
        $category_id = (int) $cid;
        if ($category_id <= 0 || !is_array($entry)) {
            continue;
        }
        $by_mode_raw = isset($entry['exposure_by_mode']) && is_array($entry['exposure_by_mode']) ? $entry['exposure_by_mode'] : [];
        $by_mode = [];
        foreach ($modes as $mode) {
            $by_mode[$mode] = max(0, (int) ($by_mode_raw[$mode] ?? 0));
        }
        $out[$category_id] = [
            'category_id'      => $category_id,
            'wordset_id'       => max(0, (int) ($entry['wordset_id'] ?? 0)),
            'exposure_total'   => max(0, (int) ($entry['exposure_total'] ?? 0)),
            'exposure_by_mode' => $by_mode,
            'last_mode'        => ll_tools_normalize_progress_mode((string) ($entry['last_mode'] ?? 'practice')),
            'last_seen_at'     => isset($entry['last_seen_at']) ? (string) $entry['last_seen_at'] : '',
        ];
    }

    return $out;
}

function ll_tools_record_category_exposure($user_id, int $category_id, string $mode, int $wordset_id = 0, int $delta = 1): void {
    $uid = (int) $user_id;
    $category_id = (int) $category_id;
    $delta = max(1, (int) $delta);
    if ($uid <= 0 || $category_id <= 0) {
        return;
    }

    $mode = ll_tools_normalize_progress_mode($mode);
    $progress = ll_tools_get_user_category_progress($uid);
    if (!isset($progress[$category_id])) {
        $progress[$category_id] = [
            'category_id'      => $category_id,
            'wordset_id'       => max(0, $wordset_id),
            'exposure_total'   => 0,
            'exposure_by_mode' => array_fill_keys(ll_tools_progress_modes(), 0),
            'last_mode'        => $mode,
            'last_seen_at'     => '',
        ];
    }

    $entry = $progress[$category_id];
    $entry['exposure_total'] = max(0, (int) $entry['exposure_total']) + $delta;
    $entry['exposure_by_mode'][$mode] = max(0, (int) ($entry['exposure_by_mode'][$mode] ?? 0)) + $delta;
    $entry['last_mode'] = $mode;
    $entry['last_seen_at'] = gmdate('Y-m-d H:i:s');
    if ($wordset_id > 0) {
        $entry['wordset_id'] = $wordset_id;
    }

    $progress[$category_id] = $entry;
    update_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $progress);
}

function ll_tools_progress_due_intervals_days(): array {
    return [
        0 => 0,
        1 => 1,
        2 => 2,
        3 => 4,
        4 => 7,
        5 => 14,
        6 => 30,
    ];
}

function ll_tools_progress_due_at_for_stage(int $stage, int $base_ts): string {
    $intervals = ll_tools_progress_due_intervals_days();
    $clamped_stage = max(0, min(6, $stage));
    $days = (int) ($intervals[$clamped_stage] ?? 0);
    if ($days <= 0) {
        return gmdate('Y-m-d H:i:s', $base_ts + 12 * HOUR_IN_SECONDS);
    }
    return gmdate('Y-m-d H:i:s', $base_ts + ($days * DAY_IN_SECONDS));
}

/**
 * Apply stronger self-check-specific outcome weighting when payload provides a bucket.
 * Returns true when a self-check bucket was recognized and applied.
 */
function ll_tools_apply_self_check_outcome_signal(array &$data, array $event, int $base_ts): bool {
    $mode = ll_tools_normalize_progress_mode((string) ($event['mode'] ?? 'practice'));
    if ($mode !== 'self-check') {
        return false;
    }
    $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    $bucket = isset($payload['self_check_bucket']) ? strtolower(trim((string) $payload['self_check_bucket'])) : '';
    if (!in_array($bucket, ['idk', 'wrong', 'close', 'right'], true)) {
        return false;
    }

    $stage = max(0, min(6, (int) ($data['stage'] ?? 0)));

    if ($bucket === 'idk') {
        $data['incorrect'] = max(0, (int) $data['incorrect']) + 2;
        $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 2;
        $data['stage'] = 0;
        $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (4 * HOUR_IN_SECONDS));
        return true;
    }

    if ($bucket === 'wrong') {
        $data['incorrect'] = max(0, (int) $data['incorrect']) + 1;
        $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 1;
        $data['stage'] = max(0, $stage - 1);
        $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (8 * HOUR_IN_SECONDS));
        return true;
    }

    if ($bucket === 'close') {
        $data['correct_after_retry'] = max(0, (int) $data['correct_after_retry']) + 1;
        $data['stage'] = max(1, $stage);
        $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
        return true;
    }

    $data['correct_clean'] = max(0, (int) $data['correct_clean']) + 1;
    $data['stage'] = max(0, min(6, max($stage + 2, 3)));
    $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
    return true;
}

function ll_tools_resolve_wordset_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $terms = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) {
        return 0;
    }
    return max(0, (int) $terms[0]);
}

function ll_tools_resolve_category_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $terms = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) {
        return 0;
    }
    return max(0, (int) $terms[0]);
}

function ll_tools_resolve_category_id_from_event(array $event): int {
    if (!empty($event['category_id'])) {
        return (int) $event['category_id'];
    }
    $name = isset($event['category_name']) ? trim((string) $event['category_name']) : '';
    if ($name !== '') {
        $term = get_term_by('name', $name, 'word-category');
        if (!$term || is_wp_error($term)) {
            $term = get_term_by('slug', sanitize_title($name), 'word-category');
        }
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }
    if (!empty($event['word_id'])) {
        return ll_tools_resolve_category_id_for_word((int) $event['word_id']);
    }
    return 0;
}

function ll_tools_sanitize_progress_event(array $raw): ?array {
    $type = isset($raw['event_type']) ? (string) $raw['event_type'] : (string) ($raw['type'] ?? '');
    $type = strtolower(trim($type));
    $allowed_types = ['word_outcome', 'word_exposure', 'category_study', 'mode_session_complete'];
    if (!in_array($type, $allowed_types, true)) {
        return null;
    }

    $uuid = isset($raw['event_uuid']) ? (string) $raw['event_uuid'] : (string) ($raw['uuid'] ?? '');
    $uuid = sanitize_text_field(substr($uuid, 0, 64));
    if ($uuid === '') {
        $uuid = wp_generate_uuid4();
    }

    $mode = ll_tools_normalize_progress_mode((string) ($raw['mode'] ?? 'practice'));
    $word_id = isset($raw['word_id']) ? (int) $raw['word_id'] : 0;
    $category_id = isset($raw['category_id']) ? (int) $raw['category_id'] : 0;
    $wordset_id = isset($raw['wordset_id']) ? (int) $raw['wordset_id'] : 0;

    $is_correct = null;
    if (array_key_exists('is_correct', $raw)) {
        $is_correct = filter_var($raw['is_correct'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
    $had_wrong_before = filter_var(($raw['had_wrong_before'] ?? false), FILTER_VALIDATE_BOOLEAN);

    $payload = isset($raw['payload']) && is_array($raw['payload']) ? $raw['payload'] : [];
    $category_name = isset($raw['category_name']) ? sanitize_text_field((string) $raw['category_name']) : '';

    return [
        'event_uuid'       => $uuid,
        'event_type'       => $type,
        'mode'             => $mode,
        'word_id'          => max(0, $word_id),
        'category_id'      => max(0, $category_id),
        'wordset_id'       => max(0, $wordset_id),
        'is_correct'       => $is_correct,
        'had_wrong_before' => !empty($had_wrong_before),
        'payload'          => $payload,
        'category_name'    => $category_name,
    ];
}

function ll_tools_apply_word_progress_event(int $user_id, array $event, string $now_mysql): bool {
    global $wpdb;
    $tables = ll_tools_user_progress_table_names();
    $table = $tables['words'];

    $word_id = (int) ($event['word_id'] ?? 0);
    if ($word_id <= 0) {
        return false;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND word_id = %d", $user_id, $word_id),
        ARRAY_A
    );

    $base_ts = time();
    $mode = ll_tools_normalize_progress_mode((string) ($event['mode'] ?? 'practice'));
    $category_id = max(0, (int) ($event['category_id'] ?? 0));
    $wordset_id = max(0, (int) ($event['wordset_id'] ?? 0));

    if ($category_id <= 0) {
        $category_id = ll_tools_resolve_category_id_for_word($word_id);
    }
    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_resolve_wordset_id_for_word($word_id);
    }

    $data = [
        'user_id'              => $user_id,
        'word_id'              => $word_id,
        'category_id'          => $category_id,
        'wordset_id'           => $wordset_id,
        'first_seen_at'        => $now_mysql,
        'last_seen_at'         => $now_mysql,
        'last_mode'            => $mode,
        'total_coverage'       => 0,
        'coverage_learning'    => 0,
        'coverage_practice'    => 0,
        'coverage_listening'   => 0,
        'coverage_gender'      => 0,
        'coverage_self_check'  => 0,
        'correct_clean'        => 0,
        'correct_after_retry'  => 0,
        'incorrect'            => 0,
        'lapse_count'          => 0,
        'stage'                => 0,
        'due_at'               => null,
        'updated_at'           => $now_mysql,
    ];

    if (is_array($row)) {
        foreach ($data as $key => $default_val) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if (is_numeric($default_val)) {
                $data[$key] = (int) $row[$key];
            } elseif ($key === 'due_at') {
                $data[$key] = !empty($row[$key]) ? (string) $row[$key] : null;
            } else {
                $data[$key] = (string) $row[$key];
            }
        }
    }

    $data['last_seen_at'] = $now_mysql;
    $data['updated_at'] = $now_mysql;
    $data['last_mode'] = $mode;
    if ($category_id > 0) {
        $data['category_id'] = $category_id;
    }
    if ($wordset_id > 0) {
        $data['wordset_id'] = $wordset_id;
    }

    $event_type = (string) ($event['event_type'] ?? 'word_exposure');

    if ($event_type === 'word_exposure') {
        $data['total_coverage'] = max(0, (int) $data['total_coverage']) + 1;
        $mode_column = ll_tools_progress_mode_column($mode);
        $data[$mode_column] = max(0, (int) $data[$mode_column]) + 1;
    }

    if ($event_type === 'word_outcome') {
        $handled_self_check = ll_tools_apply_self_check_outcome_signal($data, $event, $base_ts);
        if (!$handled_self_check) {
            $is_correct = $event['is_correct'];
            $had_wrong_before = !empty($event['had_wrong_before']);
            if ($is_correct === true) {
                if ($had_wrong_before) {
                    $data['correct_after_retry'] = max(0, (int) $data['correct_after_retry']) + 1;
                    $data['stage'] = max(1, min(6, (int) $data['stage']));
                } else {
                    $data['correct_clean'] = max(0, (int) $data['correct_clean']) + 1;
                    $data['stage'] = max(0, min(6, (int) $data['stage'] + 1));
                }
                $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
            } elseif ($is_correct === false) {
                $data['incorrect'] = max(0, (int) $data['incorrect']) + 1;
                $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 1;
                $data['stage'] = max(0, min(6, (int) $data['stage'] - 1));
                $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (12 * HOUR_IN_SECONDS));
            }
        }
    }

    $formats = [
        '%d', // user_id
        '%d', // word_id
        '%d', // category_id
        '%d', // wordset_id
        '%s', // first_seen_at
        '%s', // last_seen_at
        '%s', // last_mode
        '%d', // total_coverage
        '%d', // coverage_learning
        '%d', // coverage_practice
        '%d', // coverage_listening
        '%d', // coverage_gender
        '%d', // coverage_self_check
        '%d', // correct_clean
        '%d', // correct_after_retry
        '%d', // incorrect
        '%d', // lapse_count
        '%d', // stage
        '%s', // due_at
        '%s', // updated_at
    ];

    $saved = $wpdb->replace($table, $data, $formats);
    if ($saved === false) {
        return false;
    }

    if ($category_id > 0 && $event_type === 'word_exposure') {
        ll_tools_record_category_exposure($user_id, $category_id, $mode, $wordset_id, 1);
    }

    return true;
}

function ll_tools_process_progress_events_batch(int $user_id, array $events): array {
    global $wpdb;

    $tables = ll_tools_user_progress_table_names();
    $events_table = $tables['events'];

    $stats = [
        'received' => count($events),
        'processed' => 0,
        'duplicates' => 0,
        'invalid' => 0,
        'failed' => 0,
    ];

    $now = gmdate('Y-m-d H:i:s');

    foreach ($events as $raw) {
        if (!is_array($raw)) {
            $stats['invalid']++;
            continue;
        }

        $event = ll_tools_sanitize_progress_event($raw);
        if (!$event) {
            $stats['invalid']++;
            continue;
        }

        $event['category_id'] = ll_tools_resolve_category_id_from_event($event);
        if ($event['wordset_id'] <= 0 && !empty($event['word_id'])) {
            $event['wordset_id'] = ll_tools_resolve_wordset_id_for_word((int) $event['word_id']);
        }

        $inserted = $wpdb->insert(
            $events_table,
            [
                'user_id' => $user_id,
                'event_uuid' => $event['event_uuid'],
                'event_type' => $event['event_type'],
                'mode' => $event['mode'],
                'word_id' => (int) $event['word_id'],
                'category_id' => (int) $event['category_id'],
                'wordset_id' => (int) $event['wordset_id'],
                'is_correct' => is_null($event['is_correct']) ? null : ($event['is_correct'] ? 1 : 0),
                'had_wrong_before' => !empty($event['had_wrong_before']) ? 1 : 0,
                'payload_json' => !empty($event['payload']) ? wp_json_encode($event['payload']) : null,
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            $last_error = (string) $wpdb->last_error;
            if (stripos($last_error, 'duplicate') !== false) {
                $stats['duplicates']++;
            } else {
                $stats['failed']++;
            }
            continue;
        }

        $ok = true;
        if ($event['event_type'] === 'word_outcome' || $event['event_type'] === 'word_exposure') {
            $ok = ll_tools_apply_word_progress_event($user_id, $event, $now);
        } elseif ($event['event_type'] === 'category_study') {
            if (!empty($event['category_id'])) {
                $delta = isset($event['payload']['units']) ? max(1, (int) $event['payload']['units']) : 1;
                ll_tools_record_category_exposure($user_id, (int) $event['category_id'], $event['mode'], (int) $event['wordset_id'], $delta);
            }
        } elseif ($event['event_type'] === 'mode_session_complete') {
            $payload_categories = isset($event['payload']['category_ids']) ? (array) $event['payload']['category_ids'] : [];
            $payload_categories = array_values(array_filter(array_map('intval', $payload_categories), function ($id) {
                return $id > 0;
            }));
            foreach ($payload_categories as $cid) {
                ll_tools_record_category_exposure($user_id, $cid, $event['mode'], (int) $event['wordset_id'], 1);
            }
        }

        if ($ok) {
            $stats['processed']++;
        } else {
            $stats['failed']++;
        }
    }

    return $stats;
}

function ll_tools_get_user_word_progress_rows(int $user_id, array $word_ids): array {
    global $wpdb;
    if ($user_id <= 0 || empty($word_ids)) {
        return [];
    }

    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $table = ll_tools_user_progress_table_names()['words'];
    $out = [];
    $chunks = array_chunk($word_ids, 200);
    foreach ($chunks as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
        $sql = "SELECT * FROM {$table} WHERE user_id = %d AND word_id IN ({$placeholders})";
        $params = array_merge([$user_id], $chunk);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ((array) $rows as $row) {
            $wid = isset($row['word_id']) ? (int) $row['word_id'] : 0;
            if ($wid > 0) {
                $out[$wid] = $row;
            }
        }
    }

    return $out;
}

function ll_tools_user_progress_mastered_stage_threshold(): int {
    return max(1, min(6, (int) apply_filters('ll_tools_user_progress_mastered_stage_threshold', 5)));
}

function ll_tools_user_progress_mastered_clean_threshold(): int {
    return max(1, (int) apply_filters('ll_tools_user_progress_mastered_clean_threshold', 3));
}

function ll_tools_user_progress_word_is_studied(array $row): bool {
    $coverage = max(0, (int) ($row['total_coverage'] ?? 0));
    $correct_clean = max(0, (int) ($row['correct_clean'] ?? 0));
    $correct_retry = max(0, (int) ($row['correct_after_retry'] ?? 0));
    $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
    return ($coverage > 0) || ($correct_clean > 0) || ($correct_retry > 0) || ($incorrect > 0);
}

function ll_tools_user_progress_word_is_mastered(array $row): bool {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return false;
    }
    $stage_threshold = ll_tools_user_progress_mastered_stage_threshold();
    $clean_threshold = ll_tools_user_progress_mastered_clean_threshold();
    $stage = max(0, (int) ($row['stage'] ?? 0));
    $clean = max(0, (int) ($row['correct_clean'] ?? 0));
    return ($stage >= $stage_threshold) && ($clean >= $clean_threshold);
}

function ll_tools_user_progress_word_status(array $row): string {
    if (ll_tools_user_progress_word_is_mastered($row)) {
        return 'mastered';
    }
    if (ll_tools_user_progress_word_is_studied($row)) {
        return 'studied';
    }
    return 'new';
}

function ll_tools_user_progress_word_difficulty_score(array $row): int {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return -1000;
    }
    $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
    $lapses = max(0, (int) ($row['lapse_count'] ?? 0));
    $clean = max(0, (int) ($row['correct_clean'] ?? 0));
    $retry = max(0, (int) ($row['correct_after_retry'] ?? 0));
    $stage = max(0, (int) ($row['stage'] ?? 0));

    return ($incorrect * 3)
        + ($lapses * 2)
        + max(0, 2 - $stage)
        - min(4, $clean)
        - min(2, $retry);
}

function ll_tools_user_progress_category_ids_in_scope(array $categories_payload, array $requested_category_ids, array $goals): array {
    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid > 0) {
            $category_lookup[$cid] = true;
        }
    }

    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $iid = (int) $ignored_id;
        if ($iid > 0) {
            $ignored_lookup[$iid] = true;
        }
    }

    $requested = array_values(array_filter(array_map('intval', (array) $requested_category_ids), function ($id) use ($category_lookup, $ignored_lookup) {
        return $id > 0 && isset($category_lookup[$id]) && empty($ignored_lookup[$id]);
    }));

    if (!empty($requested)) {
        return $requested;
    }

    $all_available = array_values(array_filter(array_map('intval', array_keys($category_lookup)), function ($id) use ($ignored_lookup) {
        return $id > 0 && empty($ignored_lookup[$id]);
    }));
    return $all_available;
}

/**
 * @return array{
 *   days:array<int,array{date:string,events:int,unique_words:int,outcomes:int}>,
 *   max_events:int,
 *   window_days:int
 * }
 */
function ll_tools_user_study_daily_activity_series(int $user_id, int $wordset_id, array $category_ids, int $days = 14): array {
    global $wpdb;

    if ($user_id <= 0) {
        return [
            'days' => [],
            'max_events' => 0,
            'window_days' => 0,
        ];
    }

    $window_days = max(7, min(60, (int) $days));
    $start_ts = strtotime(gmdate('Y-m-d 00:00:00')) - (($window_days - 1) * DAY_IN_SECONDS);
    $start_mysql = gmdate('Y-m-d H:i:s', $start_ts);

    $tables = ll_tools_user_progress_table_names();
    $table = $tables['events'];

    $where_parts = [
        'user_id = %d',
        'created_at >= %s',
    ];
    $params = [$user_id, $start_mysql];

    if ($wordset_id > 0) {
        $where_parts[] = 'wordset_id = %d';
        $params[] = $wordset_id;
    }

    $scoped_categories = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));
    if (!empty($scoped_categories)) {
        $placeholders = implode(', ', array_fill(0, count($scoped_categories), '%d'));
        $where_parts[] = "category_id IN ({$placeholders})";
        $params = array_merge($params, $scoped_categories);
    }

    $where_sql = implode(' AND ', $where_parts);
    $sql = "
        SELECT
            DATE(created_at) AS activity_date,
            COUNT(*) AS events_count,
            COUNT(DISTINCT CASE WHEN word_id > 0 THEN word_id END) AS unique_words_count,
            SUM(CASE WHEN event_type = 'word_outcome' THEN 1 ELSE 0 END) AS outcomes_count
        FROM {$table}
        WHERE {$where_sql}
        GROUP BY DATE(created_at)
        ORDER BY activity_date ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    $by_date = [];
    foreach ((array) $rows as $row) {
        $date = isset($row['activity_date']) ? (string) $row['activity_date'] : '';
        if ($date === '') {
            continue;
        }
        $by_date[$date] = [
            'events' => max(0, (int) ($row['events_count'] ?? 0)),
            'unique_words' => max(0, (int) ($row['unique_words_count'] ?? 0)),
            'outcomes' => max(0, (int) ($row['outcomes_count'] ?? 0)),
        ];
    }

    $series = [];
    $max_events = 0;
    for ($offset = 0; $offset < $window_days; $offset++) {
        $day_ts = $start_ts + ($offset * DAY_IN_SECONDS);
        $date = gmdate('Y-m-d', $day_ts);
        $entry = $by_date[$date] ?? ['events' => 0, 'unique_words' => 0, 'outcomes' => 0];
        $events = max(0, (int) ($entry['events'] ?? 0));
        $max_events = max($max_events, $events);
        $series[] = [
            'date' => $date,
            'events' => $events,
            'unique_words' => max(0, (int) ($entry['unique_words'] ?? 0)),
            'outcomes' => max(0, (int) ($entry['outcomes'] ?? 0)),
        ];
    }

    return [
        'days' => $series,
        'max_events' => $max_events,
        'window_days' => $window_days,
    ];
}

/**
 * Build analytics used by the user study dashboard.
 *
 * @return array<string,mixed>
 */
function ll_tools_build_user_study_analytics_payload($user_id = 0, $wordset_id = 0, $category_ids = [], $days = 14): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $scope_wordset_id = max(0, (int) $wordset_id);
    if ($uid <= 0) {
        return [
            'scope' => [
                'wordset_id' => $scope_wordset_id,
                'category_ids' => [],
                'category_count' => 0,
                'mode' => 'all',
            ],
            'summary' => [
                'total_words' => 0,
                'mastered_words' => 0,
                'studied_words' => 0,
                'new_words' => 0,
                'hard_words' => 0,
                'starred_words' => 0,
            ],
            'daily_activity' => [
                'days' => [],
                'max_events' => 0,
                'window_days' => 0,
            ],
            'categories' => [],
            'words' => [],
            'generated_at' => gmdate('c'),
        ];
    }

    $goals = ll_tools_get_user_study_goals($uid);
    $categories_payload = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($scope_wordset_id)
        : [];

    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid <= 0) {
            continue;
        }
        $category_lookup[$cid] = $cat;
    }

    $requested_scope_category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($category_lookup) {
        return $id > 0 && isset($category_lookup[$id]);
    }));

    $scope_category_ids = ll_tools_user_progress_category_ids_in_scope($categories_payload, (array) $category_ids, $goals);
    $requested_scope_included = !empty(array_intersect($requested_scope_category_ids, $scope_category_ids));
    $scope_mode = $requested_scope_included ? 'selected' : 'all';

    $words_by_category = function_exists('ll_tools_user_study_words')
        ? ll_tools_user_study_words($scope_category_ids, $scope_wordset_id)
        : [];

    $word_map = [];
    $category_word_ids = [];

    foreach ($scope_category_ids as $cid) {
        $category_word_ids[$cid] = [];
        $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
        foreach ($rows as $word) {
            if (!is_array($word)) {
                continue;
            }
            $wid = isset($word['id']) ? (int) $word['id'] : 0;
            if ($wid <= 0) {
                continue;
            }
            $category_word_ids[$cid][$wid] = true;

            if (!isset($word_map[$wid])) {
                $title = isset($word['title']) ? (string) $word['title'] : '';
                $translation = isset($word['translation']) ? (string) $word['translation'] : '';
                $word_map[$wid] = [
                    'id' => $wid,
                    'title' => $title,
                    'translation' => $translation,
                    'label' => isset($word['label']) ? (string) $word['label'] : '',
                    'image' => isset($word['image']) ? (string) $word['image'] : '',
                    'audio_files_count' => isset($word['audio_files']) && is_array($word['audio_files']) ? count($word['audio_files']) : 0,
                    'category_ids' => [],
                ];
            }
            $word_map[$wid]['category_ids'][$cid] = true;
        }
    }

    $all_word_ids = array_values(array_filter(array_map('intval', array_keys($word_map)), function ($id) {
        return $id > 0;
    }));
    $progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
    $category_progress = ll_tools_get_user_category_progress($uid);

    $study_state = function_exists('ll_tools_get_user_study_state') ? ll_tools_get_user_study_state($uid) : [];
    $starred_lookup = [];
    foreach ((array) ($study_state['starred_word_ids'] ?? []) as $starred_id) {
        $sid = (int) $starred_id;
        if ($sid > 0) {
            $starred_lookup[$sid] = true;
        }
    }

    $word_rows = [];
    $summary_total = 0;
    $summary_mastered = 0;
    $summary_studied = 0;
    $summary_hard = 0;
    $summary_starred = 0;
    $category_studied_lookup = [];
    $category_mastered_lookup = [];
    $category_last_word_seen = [];

    foreach ($word_map as $wid => $word) {
        $progress = isset($progress_rows[$wid]) && is_array($progress_rows[$wid]) ? $progress_rows[$wid] : [];
        $status = ll_tools_user_progress_word_status($progress);
        $difficulty = ll_tools_user_progress_word_difficulty_score($progress);
        $coverage = max(0, (int) ($progress['total_coverage'] ?? 0));
        $stage = max(0, (int) ($progress['stage'] ?? 0));
        $correct_clean = max(0, (int) ($progress['correct_clean'] ?? 0));
        $correct_retry = max(0, (int) ($progress['correct_after_retry'] ?? 0));
        $incorrect = max(0, (int) ($progress['incorrect'] ?? 0));
        $lapses = max(0, (int) ($progress['lapse_count'] ?? 0));
        $total_outcomes = $correct_clean + $correct_retry + $incorrect;
        $accuracy = ($total_outcomes > 0)
            ? round((($correct_clean + $correct_retry) / $total_outcomes) * 100, 1)
            : null;
        $last_seen_at = isset($progress['last_seen_at']) ? (string) $progress['last_seen_at'] : '';
        $last_mode = isset($progress['last_mode']) ? ll_tools_normalize_progress_mode((string) $progress['last_mode']) : '';
        $due_at = isset($progress['due_at']) ? (string) $progress['due_at'] : '';

        $word_category_ids = array_values(array_filter(array_map('intval', array_keys((array) ($word['category_ids'] ?? []))), function ($id) {
            return $id > 0;
        }));
        sort($word_category_ids, SORT_NUMERIC);

        $word_category_labels = [];
        foreach ($word_category_ids as $cid) {
            $meta = $category_lookup[$cid] ?? [];
            $label = '';
            if (is_array($meta)) {
                $label = isset($meta['translation']) && (string) $meta['translation'] !== ''
                    ? (string) $meta['translation']
                    : (string) ($meta['name'] ?? '');
            }
            if ($label === '') {
                $label = (string) ll_tools_get_category_display_name($cid);
            }
            $word_category_labels[] = $label;

            if ($status !== 'new') {
                if (!isset($category_studied_lookup[$cid])) {
                    $category_studied_lookup[$cid] = [];
                }
                $category_studied_lookup[$cid][$wid] = true;
            }
            if ($status === 'mastered') {
                if (!isset($category_mastered_lookup[$cid])) {
                    $category_mastered_lookup[$cid] = [];
                }
                $category_mastered_lookup[$cid][$wid] = true;
            }
            if ($last_seen_at !== '') {
                if (!isset($category_last_word_seen[$cid]) || strcmp($last_seen_at, (string) $category_last_word_seen[$cid]) > 0) {
                    $category_last_word_seen[$cid] = $last_seen_at;
                }
            }
        }

        $word_rows[] = [
            'id' => (int) $wid,
            'title' => (string) ($word['title'] ?? ''),
            'translation' => (string) ($word['translation'] ?? ''),
            'label' => (string) ($word['label'] ?? ''),
            'image' => (string) ($word['image'] ?? ''),
            'audio_files_count' => max(0, (int) ($word['audio_files_count'] ?? 0)),
            'category_ids' => $word_category_ids,
            'category_labels' => $word_category_labels,
            'category_id' => !empty($word_category_ids) ? (int) $word_category_ids[0] : 0,
            'category_label' => !empty($word_category_labels) ? (string) $word_category_labels[0] : '',
            'status' => $status,
            'difficulty_score' => $difficulty,
            'total_coverage' => $coverage,
            'stage' => $stage,
            'correct_clean' => $correct_clean,
            'correct_after_retry' => $correct_retry,
            'incorrect' => $incorrect,
            'lapse_count' => $lapses,
            'accuracy_percent' => $accuracy,
            'last_mode' => $last_mode,
            'last_seen_at' => $last_seen_at,
            'due_at' => $due_at,
            'is_starred' => !empty($starred_lookup[$wid]),
        ];

        $summary_total++;
        if ($status !== 'new') {
            $summary_studied++;
        }
        if ($status === 'mastered') {
            $summary_mastered++;
        }
        if ($status === 'studied' && $difficulty >= 4) {
            $summary_hard++;
        }
        if (!empty($starred_lookup[$wid])) {
            $summary_starred++;
        }
    }

    usort($word_rows, function ($left, $right) {
        $left_status = isset($left['status']) ? (string) $left['status'] : 'new';
        $right_status = isset($right['status']) ? (string) $right['status'] : 'new';
        if ($left_status !== $right_status) {
            if ($left_status === 'new') {
                return 1;
            }
            if ($right_status === 'new') {
                return -1;
            }
        }

        $left_score = (int) ($left['difficulty_score'] ?? 0);
        $right_score = (int) ($right['difficulty_score'] ?? 0);
        if ($left_score !== $right_score) {
            return $right_score <=> $left_score;
        }

        $left_incorrect = (int) ($left['incorrect'] ?? 0);
        $right_incorrect = (int) ($right['incorrect'] ?? 0);
        if ($left_incorrect !== $right_incorrect) {
            return $right_incorrect <=> $left_incorrect;
        }

        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });

    $category_rows = [];
    $progress_modes = ll_tools_progress_modes();
    foreach ($scope_category_ids as $cid) {
        $cat_meta = isset($category_lookup[$cid]) && is_array($category_lookup[$cid]) ? $category_lookup[$cid] : [];
        $cat_label = isset($cat_meta['translation']) && (string) $cat_meta['translation'] !== ''
            ? (string) $cat_meta['translation']
            : (string) ($cat_meta['name'] ?? '');
        if ($cat_label === '') {
            $cat_label = (string) ll_tools_get_category_display_name($cid);
        }

        $cat_word_total = isset($category_word_ids[$cid]) && is_array($category_word_ids[$cid]) ? count($category_word_ids[$cid]) : 0;
        $cat_studied = isset($category_studied_lookup[$cid]) && is_array($category_studied_lookup[$cid]) ? count($category_studied_lookup[$cid]) : 0;
        $cat_mastered = isset($category_mastered_lookup[$cid]) && is_array($category_mastered_lookup[$cid]) ? count($category_mastered_lookup[$cid]) : 0;
        $cat_progress = isset($category_progress[$cid]) && is_array($category_progress[$cid]) ? $category_progress[$cid] : [];
        $exposure_by_mode_raw = isset($cat_progress['exposure_by_mode']) && is_array($cat_progress['exposure_by_mode'])
            ? $cat_progress['exposure_by_mode']
            : [];
        $exposure_by_mode = [];
        foreach ($progress_modes as $mode) {
            $exposure_by_mode[$mode] = max(0, (int) ($exposure_by_mode_raw[$mode] ?? 0));
        }
        $last_seen_at = isset($cat_progress['last_seen_at']) ? (string) $cat_progress['last_seen_at'] : '';
        if ($last_seen_at === '' && !empty($category_last_word_seen[$cid])) {
            $last_seen_at = (string) $category_last_word_seen[$cid];
        } elseif ($last_seen_at !== '' && !empty($category_last_word_seen[$cid]) && strcmp((string) $category_last_word_seen[$cid], $last_seen_at) > 0) {
            $last_seen_at = (string) $category_last_word_seen[$cid];
        }

        $exposure_total = max(0, (int) ($cat_progress['exposure_total'] ?? 0));
        if ($exposure_total === 0) {
            $exposure_total = array_sum($exposure_by_mode);
        }

        $category_rows[] = [
            'id' => $cid,
            'label' => $cat_label,
            'word_count' => $cat_word_total,
            'studied_words' => $cat_studied,
            'mastered_words' => $cat_mastered,
            'new_words' => max(0, $cat_word_total - $cat_studied),
            'exposure_total' => $exposure_total,
            'exposure_by_mode' => $exposure_by_mode,
            'last_mode' => isset($cat_progress['last_mode']) ? ll_tools_normalize_progress_mode((string) $cat_progress['last_mode']) : 'practice',
            'last_seen_at' => $last_seen_at,
        ];
    }

    usort($category_rows, function ($left, $right) {
        return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    $daily_activity = ll_tools_user_study_daily_activity_series($uid, $scope_wordset_id, $scope_category_ids, (int) $days);

    return [
        'scope' => [
            'wordset_id' => $scope_wordset_id,
            'category_ids' => $scope_category_ids,
            'category_count' => count($scope_category_ids),
            'mode' => $scope_mode,
        ],
        'summary' => [
            'total_words' => $summary_total,
            'mastered_words' => $summary_mastered,
            'studied_words' => $summary_studied,
            'new_words' => max(0, $summary_total - $summary_studied),
            'hard_words' => $summary_hard,
            'starred_words' => $summary_starred,
        ],
        'daily_activity' => $daily_activity,
        'categories' => $category_rows,
        'words' => $word_rows,
        'generated_at' => gmdate('c'),
    ];
}

function ll_tools_pick_recommendation_mode(array $enabled_modes, array $category_ids, array $category_lookup): string {
    $enabled_lookup = array_flip($enabled_modes);

    if (isset($enabled_lookup['practice'])) {
        return 'practice';
    }
    if (isset($enabled_lookup['learning'])) {
        return 'learning';
    }
    if (isset($enabled_lookup['self-check'])) {
        return 'self-check';
    }
    if (isset($enabled_lookup['listening'])) {
        return 'listening';
    }

    if (isset($enabled_lookup['gender'])) {
        foreach ($category_ids as $cid) {
            if (!empty($category_lookup[$cid]['gender_supported'])) {
                return 'gender';
            }
        }
    }

    return !empty($enabled_modes) ? $enabled_modes[0] : 'practice';
}

function ll_tools_category_pipeline_sequence(array $category_meta): array {
    $sequence = ['learning', 'listening', 'practice'];
    if (!empty($category_meta['gender_supported'])) {
        $sequence[] = 'gender';
    }
    $sequence[] = 'self-check';
    return $sequence;
}

function ll_tools_recommendation_chunk_size_for_pool(int $pool_size, int $preferred = 12): int {
    $pool = max(0, (int) $pool_size);
    if ($pool <= 0) {
        return 0;
    }

    if ($pool <= 15) {
        return $pool;
    }

    $target = max(8, min(15, (int) $preferred));
    if ($pool >= 60) {
        $target = 15;
    } elseif ($pool >= 40) {
        $target = 13;
    } elseif ($pool >= 24) {
        $target = 12;
    } else {
        $target = 10;
    }

    return max(8, min(15, min($pool, $target)));
}

/**
 * Pick a balanced review chunk using existing progress rows.
 *
 * @return array{
 *   rows:array<int,array<string,mixed>>,
 *   word_ids:array<int>,
 *   counts:array{weak:int,new:int,due:int}
 * }
 */
function ll_tools_select_review_chunk_rows(array $all_word_ids, array $progress_rows, array $goals, int $chunk_size = 12): array {
    $all_word_ids = array_values(array_unique(array_filter(array_map('intval', $all_word_ids), function ($id) {
        return $id > 0;
    })));

    $size = max(1, min(15, (int) $chunk_size));
    if (empty($all_word_ids)) {
        return [
            'rows' => [],
            'word_ids' => [],
            'counts' => ['weak' => 0, 'new' => 0, 'due' => 0],
        ];
    }

    $now = time();
    $weak = [];
    $new = [];
    $stable = [];

    foreach ($all_word_ids as $wid) {
        $row = $progress_rows[$wid] ?? null;
        if (!$row) {
            $new[] = [
                'word_id' => $wid,
                'score' => 7,
                'is_due' => false,
                'is_weak' => false,
                'is_new' => true,
            ];
            continue;
        }

        $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
        $lapses = max(0, (int) ($row['lapse_count'] ?? 0));
        $correct_clean = max(0, (int) ($row['correct_clean'] ?? 0));
        $stage = max(0, (int) ($row['stage'] ?? 0));
        $due_at_raw = isset($row['due_at']) ? (string) $row['due_at'] : '';
        $due_ts = $due_at_raw !== '' ? strtotime($due_at_raw . ' UTC') : false;
        $is_due = ($due_ts !== false) && ($due_ts <= $now);

        $score = ($incorrect * 3) + ($lapses * 2) + max(0, 2 - $stage) - min(3, $correct_clean);
        if ($is_due) {
            $score += 8;
        }

        $is_weak = $is_due || $incorrect > $correct_clean || $stage <= 1;
        $entry = [
            'word_id' => $wid,
            'score' => $score,
            'is_due' => $is_due,
            'is_weak' => $is_weak,
            'is_new' => false,
        ];

        if ($is_weak) {
            $weak[] = $entry;
        } else {
            $stable[] = $entry;
        }
    }

    usort($weak, function ($a, $b) {
        return ($b['score'] <=> $a['score']);
    });
    usort($stable, function ($a, $b) {
        return ($b['score'] <=> $a['score']);
    });
    shuffle($new);

    $daily_new_target = max(0, min(4, (int) ($goals['daily_new_word_target'] ?? 2)));
    $due_weak_count = count(array_filter($weak, function ($row) {
        return !empty($row['is_due']);
    }));
    $weak_take = min(count($weak), max(0, $size - 4));
    $new_take = min(count($new), $daily_new_target);
    if ($due_weak_count >= max(4, $size - 4)) {
        $new_take = 0;
    }

    $selected_rows = array_slice($weak, 0, $weak_take);
    $selected_rows = array_merge($selected_rows, array_slice($new, 0, $new_take));

    $taken_lookup = [];
    foreach ($selected_rows as $row) {
        $taken_lookup[(int) $row['word_id']] = true;
    }

    foreach (array_merge($weak, $new, $stable) as $row) {
        if (count($selected_rows) >= $size) {
            break;
        }
        $wid = (int) $row['word_id'];
        if ($wid <= 0 || !empty($taken_lookup[$wid])) {
            continue;
        }
        $selected_rows[] = $row;
        $taken_lookup[$wid] = true;
    }

    $word_ids = array_values(array_map(function ($row) {
        return (int) $row['word_id'];
    }, $selected_rows));

    $counts = ['weak' => 0, 'new' => 0, 'due' => 0];
    foreach ($selected_rows as $row) {
        if (!empty($row['is_weak'])) {
            $counts['weak']++;
        }
        if (!empty($row['is_new'])) {
            $counts['new']++;
        }
        if (!empty($row['is_due'])) {
            $counts['due']++;
        }
    }

    return [
        'rows' => $selected_rows,
        'word_ids' => $word_ids,
        'counts' => $counts,
    ];
}

function ll_tools_build_next_activity_recommendation($user_id = 0, $wordset_id = 0, $category_ids = [], $categories_payload = []): ?array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return null;
    }

    $goals = ll_tools_get_user_study_goals($uid);
    $enabled_modes = (array) ($goals['enabled_modes'] ?? ll_tools_progress_modes());
    if (empty($enabled_modes)) {
        $enabled_modes = ll_tools_progress_modes();
    }
    $enabled_lookup = array_flip($enabled_modes);

    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $ignored_lookup[(int) $ignored_id] = true;
    }

    $placement_lookup = [];
    foreach ((array) ($goals['placement_known_category_ids'] ?? []) as $known_id) {
        $placement_lookup[(int) $known_id] = true;
    }

    $selected = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($ignored_lookup) {
        return $id > 0 && empty($ignored_lookup[$id]);
    }));

    if (empty($selected) && !empty($categories_payload) && is_array($categories_payload)) {
        foreach ($categories_payload as $cat) {
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0 && empty($ignored_lookup[$cid])) {
                $selected[] = $cid;
            }
        }
    }

    if (empty($selected)) {
        return null;
    }

    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid > 0) {
            $category_lookup[$cid] = $cat;
        }
    }

    $category_progress = ll_tools_get_user_category_progress($uid);

    // 1) Pipeline recommendation for categories with no exposure in one or more enabled modes.
    foreach ($selected as $cid) {
        if (!empty($placement_lookup[$cid])) {
            continue;
        }

        $meta = $category_lookup[$cid] ?? [];
        $pipeline = ll_tools_category_pipeline_sequence($meta);
        $progress_for_cat = $category_progress[$cid]['exposure_by_mode'] ?? [];

        foreach ($pipeline as $mode) {
            if (empty($enabled_lookup[$mode])) {
                continue;
            }
            if ($mode === 'gender' && empty($meta['gender_supported'])) {
                continue;
            }
            if ($mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                continue;
            }

            $seen = max(0, (int) ($progress_for_cat[$mode] ?? 0));
            if ($seen === 0) {
                $pipeline_chunk_word_ids = [];
                if (function_exists('ll_tools_user_study_words')) {
                    $pipeline_words_by_category = ll_tools_user_study_words([$cid], (int) $wordset_id);
                    $pipeline_rows = isset($pipeline_words_by_category[$cid]) && is_array($pipeline_words_by_category[$cid])
                        ? $pipeline_words_by_category[$cid]
                        : [];
                    $pipeline_word_ids = [];
                    foreach ($pipeline_rows as $word) {
                        $wid = isset($word['id']) ? (int) $word['id'] : 0;
                        if ($wid > 0) {
                            $pipeline_word_ids[] = $wid;
                        }
                    }
                    $pipeline_word_ids = array_values(array_unique($pipeline_word_ids));
                    if (!empty($pipeline_word_ids)) {
                        $pipeline_progress_rows = ll_tools_get_user_word_progress_rows($uid, $pipeline_word_ids);
                        $pipeline_chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($pipeline_word_ids));
                        $pipeline_chunk = ll_tools_select_review_chunk_rows($pipeline_word_ids, $pipeline_progress_rows, $goals, $pipeline_chunk_size);
                        $pipeline_chunk_word_ids = $pipeline_chunk['word_ids'];
                        if (empty($pipeline_chunk_word_ids)) {
                            $pipeline_chunk_word_ids = array_slice($pipeline_word_ids, 0, $pipeline_chunk_size);
                        }
                    }
                }
                return [
                    'type'             => 'pipeline',
                    'reason_code'      => 'pipeline_unseen_mode',
                    'mode'             => $mode,
                    'category_ids'     => [$cid],
                    'session_word_ids' => $pipeline_chunk_word_ids,
                    'details'          => [
                        'pipeline' => $pipeline,
                        'seen_mode_count' => 0,
                        'chunk_size' => count($pipeline_chunk_word_ids),
                    ],
                ];
            }
        }
    }

    // 2) Review chunk recommendation (typically 8-15 words) biased to weaker/due items.
    if (!function_exists('ll_tools_user_study_words')) {
        $fallback_mode = ll_tools_pick_recommendation_mode($enabled_modes, $selected, $category_lookup);
        return [
            'type'             => 'fallback',
            'reason_code'      => 'no_word_loader',
            'mode'             => $fallback_mode,
            'category_ids'     => array_slice($selected, 0, 3),
            'session_word_ids' => [],
            'details'          => [],
        ];
    }

    $words_by_category = ll_tools_user_study_words($selected, (int) $wordset_id);
    $all_word_ids = [];
    $word_category_lookup = [];
    $category_word_ids_lookup = [];
    foreach ($selected as $cid) {
        $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
        if (!isset($category_word_ids_lookup[$cid])) {
            $category_word_ids_lookup[$cid] = [];
        }
        foreach ($rows as $word) {
            $wid = isset($word['id']) ? (int) $word['id'] : 0;
            if ($wid <= 0) {
                continue;
            }
            $all_word_ids[] = $wid;
            $category_word_ids_lookup[$cid][] = $wid;
            if (!isset($word_category_lookup[$wid])) {
                $word_category_lookup[$wid] = $cid;
            }
        }
    }

    $all_word_ids = array_values(array_unique($all_word_ids));
    if (empty($all_word_ids)) {
        $fallback_mode = ll_tools_pick_recommendation_mode($enabled_modes, $selected, $category_lookup);
        return [
            'type'             => 'fallback',
            'reason_code'      => 'no_words_in_scope',
            'mode'             => $fallback_mode,
            'category_ids'     => array_slice($selected, 0, 3),
            'session_word_ids' => [],
            'details'          => [],
        ];
    }

    $total_pool_count = count($all_word_ids);

    // Prefer a full single-category chunk when that category naturally fits the target range.
    // This avoids mixed chunks like "11 from one category + 1 from another" unless required.
    $single_category_candidates = [];
    foreach ($selected as $cid) {
        $ids = isset($category_word_ids_lookup[$cid]) && is_array($category_word_ids_lookup[$cid])
            ? $category_word_ids_lookup[$cid]
            : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        $count = count($ids);
        if ($count >= 8 && $count <= 15) {
            $single_category_candidates[$cid] = $ids;
        }
    }
    if (!empty($single_category_candidates)) {
        $best_cid = 0;
        $best_score = null;
        foreach ($single_category_candidates as $cid => $ids) {
            $meta = $category_lookup[$cid] ?? [];
            $progress_for_cat = isset($category_progress[$cid]['exposure_by_mode']) && is_array($category_progress[$cid]['exposure_by_mode'])
                ? $category_progress[$cid]['exposure_by_mode']
                : [];
            $exposure_total = max(0, (int) ($category_progress[$cid]['exposure_total'] ?? 0));
            $missing_modes = 0;
            foreach ($enabled_modes as $mode_raw) {
                $mode = ll_tools_normalize_progress_mode($mode_raw);
                if ($mode === 'gender' && empty($meta['gender_supported'])) {
                    continue;
                }
                if ($mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                    continue;
                }
                $seen = max(0, (int) ($progress_for_cat[$mode] ?? 0));
                if ($seen === 0) {
                    $missing_modes++;
                }
            }

            // Prefer categories that are less exposed and still missing enabled modes.
            $score = ($missing_modes * 1000) - $exposure_total + count($ids);
            if ($best_score === null || $score > $best_score) {
                $best_score = $score;
                $best_cid = (int) $cid;
            }
        }

        if ($best_cid <= 0) {
            $candidate_ids = array_keys($single_category_candidates);
            $best_cid = !empty($candidate_ids) ? (int) $candidate_ids[0] : 0;
        }

        if ($best_cid > 0 && !empty($single_category_candidates[$best_cid])) {
            $all_word_ids = $single_category_candidates[$best_cid];
            $word_category_lookup = [];
            foreach ($all_word_ids as $wid) {
                $word_category_lookup[(int) $wid] = $best_cid;
            }
        }
    }

    $progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
    $chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($all_word_ids));
    $chunk_selection = ll_tools_select_review_chunk_rows($all_word_ids, $progress_rows, $goals, $chunk_size);
    $selected_rows = $chunk_selection['rows'];
    $session_word_ids = $chunk_selection['word_ids'];
    $weak_selected = (int) ($chunk_selection['counts']['weak'] ?? 0);
    $new_selected = (int) ($chunk_selection['counts']['new'] ?? 0);
    $due_selected = (int) ($chunk_selection['counts']['due'] ?? 0);

    if (empty($session_word_ids)) {
        $session_word_ids = array_slice($all_word_ids, 0, $chunk_size);
    }

    $session_categories = [];
    foreach ($selected_rows as $row) {
        $wid = (int) $row['word_id'];
        if (isset($word_category_lookup[$wid])) {
            $session_categories[$word_category_lookup[$wid]] = true;
        }
    }

    $session_category_ids = array_values(array_keys($session_categories));
    if (empty($session_category_ids)) {
        $session_category_ids = array_slice($selected, 0, 3);
    }

    $all_session_categories_placement_known = !empty($session_category_ids);
    foreach ($session_category_ids as $cid) {
        if (empty($placement_lookup[(int) $cid])) {
            $all_session_categories_placement_known = false;
            break;
        }
    }

    $mode = ll_tools_pick_recommendation_mode($enabled_modes, $session_category_ids, $category_lookup);
    if ($all_session_categories_placement_known && isset($enabled_lookup['self-check'])) {
        // When categories are marked known via placement, bias first recommendation to recall checks.
        $mode = 'self-check';
    }
    if ($mode === 'gender') {
        $supports_gender = false;
        foreach ($session_category_ids as $cid) {
            if (!empty($category_lookup[$cid]['gender_supported'])) {
                $supports_gender = true;
                break;
            }
        }
        if (!$supports_gender) {
            $mode = isset($enabled_lookup['practice']) ? 'practice' : ll_tools_pick_recommendation_mode($enabled_modes, $session_category_ids, $category_lookup);
        }
    }

    return [
        'type'             => 'review_chunk',
        'reason_code'      => 'review_chunk_balanced',
        'mode'             => $mode,
        'category_ids'     => $session_category_ids,
        'session_word_ids' => $session_word_ids,
        'details'          => [
            'chunk_size'   => count($session_word_ids),
            'weak_count'   => $weak_selected,
            'new_count'    => $new_selected,
            'due_count'    => $due_selected,
            'total_pool'   => $total_pool_count,
        ],
    ];
}

function ll_tools_user_progress_batch_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $events_raw = $_POST['events'] ?? '[]';
    if (is_array($events_raw)) {
        $events = $events_raw;
    } else {
        $decoded = json_decode(wp_unslash((string) $events_raw), true);
        $events = is_array($decoded) ? $decoded : [];
    }

    $events = array_slice($events, 0, 200);
    $stats = ll_tools_process_progress_events_batch(get_current_user_id(), $events);

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories);

    wp_send_json_success([
        'stats' => $stats,
        'next_activity' => $recommendation,
    ]);
}
add_action('wp_ajax_ll_user_study_progress_batch', 'll_tools_user_progress_batch_ajax');

function ll_tools_user_study_save_goals_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $goals_raw = isset($_POST['goals']) ? $_POST['goals'] : [];
    if (!is_array($goals_raw)) {
        $decoded = json_decode(wp_unslash((string) $goals_raw), true);
        $goals_raw = is_array($decoded) ? $decoded : [];
    }

    $goals = ll_tools_save_user_study_goals($goals_raw, get_current_user_id());

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories);

    wp_send_json_success([
        'goals' => $goals,
        'next_activity' => $recommendation,
    ]);
}
add_action('wp_ajax_ll_user_study_save_goals', 'll_tools_user_study_save_goals_ajax');

function ll_tools_user_study_recommendation_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];

    $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories);

    wp_send_json_success([
        'next_activity' => $recommendation,
    ]);
}
add_action('wp_ajax_ll_user_study_recommendation', 'll_tools_user_study_recommendation_ajax');

function ll_tools_user_study_analytics_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $days = isset($_POST['days']) ? (int) $_POST['days'] : 14;
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $analytics = ll_tools_build_user_study_analytics_payload(get_current_user_id(), $wordset_id, $category_ids, $days);

    wp_send_json_success([
        'analytics' => $analytics,
    ]);
}
add_action('wp_ajax_ll_user_study_analytics', 'll_tools_user_study_analytics_ajax');

function ll_tools_cleanup_user_progress_for_deleted_user($user_id): void {
    global $wpdb;
    $uid = (int) $user_id;
    if ($uid <= 0) {
        return;
    }

    $tables = ll_tools_user_progress_table_names();
    $wpdb->delete($tables['words'], ['user_id' => $uid], ['%d']);
    $wpdb->delete($tables['events'], ['user_id' => $uid], ['%d']);
}
add_action('delete_user', 'll_tools_cleanup_user_progress_for_deleted_user');

function ll_tools_cleanup_user_progress_for_deleted_word($post_id): void {
    if (get_post_type($post_id) !== 'words') {
        return;
    }

    global $wpdb;
    $wid = (int) $post_id;
    if ($wid <= 0) {
        return;
    }

    $tables = ll_tools_user_progress_table_names();
    $wpdb->delete($tables['words'], ['word_id' => $wid], ['%d']);
    $wpdb->delete($tables['events'], ['word_id' => $wid], ['%d']);
}
add_action('before_delete_post', 'll_tools_cleanup_user_progress_for_deleted_word');
