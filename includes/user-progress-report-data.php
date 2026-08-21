<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_user_progress_report_practice_result_payload_byte_limit')) {
    /**
     * Maximum stored event payload that the teacher Practice report will hydrate.
     */
    function ll_tools_user_progress_report_practice_result_payload_byte_limit(): int {
        return 16 * 1024;
    }
}

if (!function_exists('ll_tools_user_progress_report_practice_result_query_batch_size')) {
    /**
     * Derive a bounded learner batch from the configured scan depth.
     *
     * The estimate includes the complete accepted payload plus approximately
     * 1 KiB for the other selected columns and result-row bookkeeping. Keeping
     * the aggregate response near 24 MiB prevents a high scan-limit filter from
     * multiplying the per-query hydration cost by the configured batch size.
     */
    function ll_tools_user_progress_report_practice_result_query_batch_size(int $scan_limit): int {
        $configured_batch_size = (int) apply_filters('ll_tools_user_progress_report_practice_result_query_batch_size', 2);
        $configured_batch_size = max(1, min(5, $configured_batch_size));

        $query_limit = max(1, $scan_limit + 1);
        $estimated_row_bytes = ll_tools_user_progress_report_practice_result_payload_byte_limit() + 1024;
        $aggregate_query_byte_budget = 24 * 1024 * 1024;
        $budget_batch_size = (int) floor($aggregate_query_byte_budget / ($query_limit * $estimated_row_bytes));

        return max(1, min($configured_batch_size, $budget_batch_size));
    }
}

if (!function_exists('ll_tools_user_progress_report_parse_practice_result')) {
    /**
     * Parse the canonical, learner-facing Practice result stored in an event payload.
     *
     * @return array|null
     */
    function ll_tools_user_progress_report_parse_practice_result($payload_json): ?array {
        if (
            !is_string($payload_json)
            || $payload_json === ''
            || strlen($payload_json) > ll_tools_user_progress_report_practice_result_payload_byte_limit()
        ) {
            return null;
        }

        $payload = json_decode($payload_json, true, 8);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        if (
            ($result['schema'] ?? null) !== 1
            || ($result['kind'] ?? null) !== 'practice_first_try'
            || ($result['score_basis'] ?? null) !== 'first_try_distinct_words'
            || !isset($result['score_given'], $result['score_maximum'])
            || !is_int($result['score_given'])
            || !is_int($result['score_maximum'])
        ) {
            return null;
        }

        $score_given = $result['score_given'];
        $score_maximum = $result['score_maximum'];
        $score_limit = function_exists('ll_tools_user_progress_practice_result_score_limit')
            ? ll_tools_user_progress_practice_result_score_limit()
            : 100000;
        if (
            $score_maximum <= 0
            || $score_maximum > $score_limit
            || $score_given < 0
            || $score_given > $score_maximum
        ) {
            return null;
        }

        return [
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => $score_given,
            'score_maximum' => $score_maximum,
            'score_basis' => 'first_try_distinct_words',
            'percentage' => round(($score_given / $score_maximum) * 100, 1),
        ];
    }
}

if (!function_exists('ll_tools_user_progress_report_practice_results_for_users')) {
    /**
     * Return bounded Practice-result summaries for an already-paged learner list.
     *
     * Bounded UNION batches retain the event ledger's user/wordset/created index
     * and the per-learner hard row ceiling without issuing one round trip for
     * every learner. A trailing "+" can be shown when unusually dense recent
     * activity reaches that ceiling, rather than hydrating an unbounded history.
     */
    function ll_tools_user_progress_report_practice_results_for_users(array $user_ids, int $wordset_id): array {
        global $wpdb;

        $user_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $user_ids), static function (int $user_id): bool {
            return $user_id > 0;
        }))), 0, 101);
        if ($wordset_id <= 0 || empty($user_ids) || !function_exists('ll_tools_user_progress_table_names')) {
            return [];
        }

        $scan_limit = (int) apply_filters('ll_tools_user_progress_report_practice_result_scan_limit', 500);
        $scan_limit = max(25, min(1000, $scan_limit));
        $query_limit = $scan_limit + 1;
        $query_batch_size = ll_tools_user_progress_report_practice_result_query_batch_size($scan_limit);
        $payload_byte_limit = ll_tools_user_progress_report_practice_result_payload_byte_limit();
        $cutoff_30d = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $events_table = ll_tools_user_progress_table_names()['events'];
        $summaries = [];

        foreach ($user_ids as $user_id) {
            $summaries[$user_id] = [
                'latest_result' => null,
                'attempts_30d' => 0,
                'attempts_30d_truncated' => false,
                'query_failed' => false,
            ];
        }

        foreach (array_chunk($user_ids, $query_batch_size) as $user_batch) {
            $query_parts = [];
            foreach ($user_batch as $user_id) {
                $query_parts[] = $wpdb->prepare(
                    "(SELECT user_id, id, payload_json, created_at
                    FROM {$events_table}
                    WHERE user_id = %d
                        AND wordset_id = %d
                        AND event_type = %s
                        AND mode = %s
                        AND payload_json IS NOT NULL
                        AND OCTET_LENGTH(payload_json) <= %d
                    ORDER BY created_at DESC, id DESC
                    LIMIT %d)",
                    $user_id,
                    $wordset_id,
                    'mode_session_complete',
                    'practice',
                    $payload_byte_limit,
                    $query_limit
                );
            }

            $wpdb->last_error = '';
            $rows = $wpdb->get_results(
                implode(" UNION ALL\n", $query_parts) . ' ORDER BY user_id ASC, created_at DESC, id DESC',
                ARRAY_A
            );
            if (!is_array($rows) || (string) $wpdb->last_error !== '') {
                foreach ($user_batch as $user_id) {
                    $summaries[$user_id]['query_failed'] = true;
                }
                continue;
            }
            if (empty($rows)) {
                continue;
            }

            $rows_by_user = array_fill_keys($user_batch, []);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row_user_id = max(0, (int) ($row['user_id'] ?? 0));
                if (isset($rows_by_user[$row_user_id])) {
                    $rows_by_user[$row_user_id][] = $row;
                }
            }

            foreach ($user_batch as $user_id) {
                $user_rows = (array) ($rows_by_user[$user_id] ?? []);
                $has_more = count($user_rows) > $scan_limit;
                $overflow_row = $has_more ? $user_rows[$scan_limit] : null;
                $user_rows = array_slice($user_rows, 0, $scan_limit);

                foreach ($user_rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $result = ll_tools_user_progress_report_parse_practice_result($row['payload_json'] ?? null);
                    if ($result === null) {
                        continue;
                    }

                    $created_at = isset($row['created_at']) ? (string) $row['created_at'] : '';
                    if ($summaries[$user_id]['latest_result'] === null) {
                        $result['event_id'] = max(0, (int) ($row['id'] ?? 0));
                        $result['created_at'] = $created_at;
                        $summaries[$user_id]['latest_result'] = $result;
                    }

                    if ($created_at !== '' && strcmp($created_at, $cutoff_30d) >= 0) {
                        $summaries[$user_id]['attempts_30d']++;
                    }
                }

                if (
                    $has_more
                    && is_array($overflow_row)
                    && isset($overflow_row['created_at'])
                    && strcmp((string) $overflow_row['created_at'], $cutoff_30d) >= 0
                ) {
                    $summaries[$user_id]['attempts_30d_truncated'] = true;
                }
            }
        }

        return $summaries;
    }
}

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

        $rows_sql = "SELECT
                user_id,
                last_seen_at,
                total_coverage,
                correct_clean,
                correct_after_retry,
                current_correct_streak,
                mastery_unlocked,
                incorrect,
                lapse_count,
                stage,
                practice_required_recording_types,
                practice_correct_recording_types
            FROM {$tables['words']}
            WHERE {$where_sql}";
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
