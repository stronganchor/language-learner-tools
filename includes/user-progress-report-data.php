<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_user_progress_report_stats_for_users')) {
    function ll_tools_user_progress_report_stats_for_users(array $user_ids, int $wordset_id = 0): array {
        global $wpdb;

        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function (int $user_id): bool {
            return $user_id > 0;
        })));
        if (empty($user_ids) || !function_exists('ll_tools_user_progress_table_names')) {
            return [];
        }

        $tables = ll_tools_user_progress_table_names();
        $placeholders = implode(', ', array_fill(0, count($user_ids), '%d'));
        $where_sql = "user_id IN ({$placeholders})";
        $params = $user_ids;

        if ($wordset_id > 0) {
            $where_sql .= ' AND wordset_id = %d';
            $params[] = $wordset_id;
        }

        $rows_sql = "SELECT * FROM {$tables['words']} WHERE {$where_sql}";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params), ARRAY_A);

        $stats = [];
        foreach ($user_ids as $user_id) {
            $stats[$user_id] = [
                'tracked_words' => 0,
                'studied_words' => 0,
                'mastered_words' => 0,
                'hard_words' => 0,
                'last_progress_at' => '',
                'last_event_at' => '',
                'last_stt_api_call_at' => '',
                'rounds_30d' => 0,
                'outcomes_30d' => 0,
                'sessions_30d' => 0,
                'stt_calls_total' => 0,
                'stt_calls_7d' => 0,
                'stt_calls_30d' => 0,
            ];
        }

        foreach ((array) $rows as $row) {
            $user_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($user_id <= 0 || !isset($stats[$user_id]) || !is_array($row)) {
                continue;
            }

            $stats[$user_id]['tracked_words']++;
            if (function_exists('ll_tools_user_progress_word_is_studied') && ll_tools_user_progress_word_is_studied($row)) {
                $stats[$user_id]['studied_words']++;
            }
            if (function_exists('ll_tools_user_progress_word_is_mastered') && ll_tools_user_progress_word_is_mastered($row)) {
                $stats[$user_id]['mastered_words']++;
            }
            if (function_exists('ll_tools_user_progress_word_is_hard') && ll_tools_user_progress_word_is_hard($row)) {
                $stats[$user_id]['hard_words']++;
            }

            $last_seen_at = isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : '';
            if ($last_seen_at !== '' && (
                $stats[$user_id]['last_progress_at'] === ''
                || strcmp($last_seen_at, (string) $stats[$user_id]['last_progress_at']) > 0
            )) {
                $stats[$user_id]['last_progress_at'] = $last_seen_at;
            }
        }

        $events_where_sql = "user_id IN ({$placeholders})";
        if ($wordset_id > 0) {
            $events_where_sql .= ' AND wordset_id = %d';
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $cutoff_7d = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));

        $events_sql = "
            SELECT
                user_id,
                MAX(created_at) AS last_event_at,
                MAX(CASE WHEN event_type = 'stt_api_call' THEN created_at ELSE NULL END) AS last_stt_api_call_at,
                SUM(CASE WHEN created_at >= %s AND event_type = 'word_exposure' THEN 1 ELSE 0 END) AS rounds_30d,
                SUM(CASE WHEN created_at >= %s AND event_type = 'word_outcome' THEN 1 ELSE 0 END) AS outcomes_30d,
                SUM(CASE WHEN created_at >= %s AND event_type = 'mode_session_complete' THEN 1 ELSE 0 END) AS sessions_30d,
                SUM(CASE WHEN event_type = 'stt_api_call' THEN 1 ELSE 0 END) AS stt_calls_total,
                SUM(CASE WHEN created_at >= %s AND event_type = 'stt_api_call' THEN 1 ELSE 0 END) AS stt_calls_7d,
                SUM(CASE WHEN created_at >= %s AND event_type = 'stt_api_call' THEN 1 ELSE 0 END) AS stt_calls_30d
            FROM {$tables['events']}
            WHERE {$events_where_sql}
            GROUP BY user_id
        ";

        $events_query_params = array_merge([$cutoff, $cutoff, $cutoff, $cutoff_7d, $cutoff], $user_ids);
        if ($wordset_id > 0) {
            $events_query_params[] = $wordset_id;
        }

        $event_rows = $wpdb->get_results($wpdb->prepare($events_sql, $events_query_params), ARRAY_A);
        foreach ((array) $event_rows as $row) {
            $user_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($user_id <= 0 || !isset($stats[$user_id])) {
                continue;
            }

            $stats[$user_id]['last_event_at'] = isset($row['last_event_at']) ? (string) $row['last_event_at'] : '';
            $stats[$user_id]['last_stt_api_call_at'] = isset($row['last_stt_api_call_at']) ? (string) $row['last_stt_api_call_at'] : '';
            $stats[$user_id]['rounds_30d'] = max(0, (int) ($row['rounds_30d'] ?? 0));
            $stats[$user_id]['outcomes_30d'] = max(0, (int) ($row['outcomes_30d'] ?? 0));
            $stats[$user_id]['sessions_30d'] = max(0, (int) ($row['sessions_30d'] ?? 0));
            $stats[$user_id]['stt_calls_total'] = max(0, (int) ($row['stt_calls_total'] ?? 0));
            $stats[$user_id]['stt_calls_7d'] = max(0, (int) ($row['stt_calls_7d'] ?? 0));
            $stats[$user_id]['stt_calls_30d'] = max(0, (int) ($row['stt_calls_30d'] ?? 0));
        }

        return $stats;
    }
}

if (!function_exists('ll_tools_user_progress_report_user_wordset_id')) {
    function ll_tools_user_progress_report_user_wordset_id(int $user_id): int {
        $state = function_exists('ll_tools_get_user_study_state')
            ? ll_tools_get_user_study_state($user_id)
            : [];

        return max(0, (int) ($state['wordset_id'] ?? 0));
    }
}

if (!function_exists('ll_tools_user_progress_report_wordset_name')) {
    function ll_tools_user_progress_report_wordset_name(int $wordset_id): string {
        if ($wordset_id <= 0) {
            return '';
        }

        $term = get_term($wordset_id, 'wordset');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return '';
        }

        return sanitize_text_field((string) $term->name);
    }
}

if (!function_exists('ll_tools_user_progress_report_last_activity')) {
    function ll_tools_user_progress_report_last_activity(array $stats): string {
        $last_progress = isset($stats['last_progress_at']) ? (string) $stats['last_progress_at'] : '';
        $last_event = isset($stats['last_event_at']) ? (string) $stats['last_event_at'] : '';

        if ($last_progress === '') {
            return $last_event;
        }
        if ($last_event === '') {
            return $last_progress;
        }

        return (strcmp($last_event, $last_progress) > 0) ? $last_event : $last_progress;
    }
}
