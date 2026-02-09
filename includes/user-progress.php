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
                return [
                    'type'             => 'pipeline',
                    'reason_code'      => 'pipeline_unseen_mode',
                    'mode'             => $mode,
                    'category_ids'     => [$cid],
                    'session_word_ids' => [],
                    'details'          => [
                        'pipeline' => $pipeline,
                        'seen_mode_count' => 0,
                    ],
                ];
            }
        }
    }

    // 2) Review chunk recommendation (~12 words) biased to weaker/due items.
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
    foreach ($selected as $cid) {
        $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
        foreach ($rows as $word) {
            $wid = isset($word['id']) ? (int) $word['id'] : 0;
            if ($wid <= 0) {
                continue;
            }
            $all_word_ids[] = $wid;
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

    $progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
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

    $chunk_size = 12;
    $daily_new_target = max(0, min(4, (int) ($goals['daily_new_word_target'] ?? 2)));
    $due_weak_count = count(array_filter($weak, function ($row) {
        return !empty($row['is_due']);
    }));

    $weak_take = min(count($weak), 8);
    $new_take = min(count($new), $daily_new_target);
    if ($due_weak_count >= 8) {
        $new_take = 0;
    }

    $selected_rows = array_slice($weak, 0, $weak_take);
    $selected_rows = array_merge($selected_rows, array_slice($new, 0, $new_take));

    $taken_lookup = [];
    foreach ($selected_rows as $row) {
        $taken_lookup[(int) $row['word_id']] = true;
    }

    foreach (array_merge($weak, $new, $stable) as $row) {
        if (count($selected_rows) >= $chunk_size) {
            break;
        }
        $wid = (int) $row['word_id'];
        if ($wid <= 0 || !empty($taken_lookup[$wid])) {
            continue;
        }
        $selected_rows[] = $row;
        $taken_lookup[$wid] = true;
    }

    $session_word_ids = array_values(array_map(function ($row) {
        return (int) $row['word_id'];
    }, $selected_rows));

    if (empty($session_word_ids)) {
        $session_word_ids = array_slice($all_word_ids, 0, $chunk_size);
    }

    $session_categories = [];
    $weak_selected = 0;
    $new_selected = 0;
    $due_selected = 0;
    foreach ($selected_rows as $row) {
        $wid = (int) $row['word_id'];
        if (isset($word_category_lookup[$wid])) {
            $session_categories[$word_category_lookup[$wid]] = true;
        }
        if (!empty($row['is_weak'])) {
            $weak_selected++;
        }
        if (!empty($row['is_new'])) {
            $new_selected++;
        }
        if (!empty($row['is_due'])) {
            $due_selected++;
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
            'total_pool'   => count($all_word_ids),
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
