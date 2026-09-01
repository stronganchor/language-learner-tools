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
                'query_failed' => false,
                'words_query_failed' => false,
                'events_query_failed' => false,
            ];
        }

        $mark_query_failed = static function (array $batch, string $kind) use (&$stats): void {
            $kind_key = $kind . '_query_failed';
            foreach ($batch as $user_id) {
                if (!isset($stats[$user_id])) {
                    continue;
                }
                $stats[$user_id]['query_failed'] = true;
                $stats[$user_id][$kind_key] = true;
            }
        };
        $discard_word_stats = static function (int $user_id) use (&$stats): void {
            if (!isset($stats[$user_id])) {
                return;
            }

            $stats[$user_id]['tracked_words'] = 0;
            $stats[$user_id]['studied_words'] = 0;
            $stats[$user_id]['mastered_words'] = 0;
            $stats[$user_id]['hard_words'] = 0;
            $stats[$user_id]['last_progress_at'] = '';
        };

        $can_aggregate_word_stats = !function_exists('has_filter')
            || has_filter('ll_tools_user_progress_streak_difficulty_relief') === false;
        $stage_threshold = function_exists('ll_tools_user_progress_mastered_stage_threshold')
            ? ll_tools_user_progress_mastered_stage_threshold()
            : 5;
        $clean_threshold = function_exists('ll_tools_user_progress_mastered_clean_threshold')
            ? ll_tools_user_progress_mastered_clean_threshold()
            : 3;
        $hard_threshold = function_exists('ll_tools_user_progress_hard_difficulty_threshold')
            ? ll_tools_user_progress_hard_difficulty_threshold()
            : 4;
        $stage_threshold = max(1, min(6, (int) $stage_threshold));
        $clean_threshold = max(1, (int) $clean_threshold);
        $hard_threshold = max(1, (int) $hard_threshold);

        $raw_word_scan_limit = (int) apply_filters('ll_tools_user_progress_report_raw_word_scan_limit', 10000);
        $raw_word_scan_limit = max(1, min(20000, $raw_word_scan_limit));
        $raw_word_page_size = (int) apply_filters('ll_tools_user_progress_report_raw_word_page_size', 1000);
        $raw_word_page_size = max(1, min(1000, $raw_word_page_size));
        $classifier_columns_sql = 'progress.word_id,
            progress.last_seen_at,
            progress.total_coverage,
            progress.correct_clean,
            progress.correct_after_retry,
            progress.current_correct_streak,
            progress.mastery_unlocked,
            progress.incorrect,
            progress.lapse_count,
            progress.stage,
            progress.practice_required_recording_types,
            progress.practice_correct_recording_types';

        /**
         * Page one learner's rows through the canonical PHP classifiers.
         *
         * Counts remain local until the keyset walk completes. A failed query,
         * recording-type lookup, or cumulative-cap overflow therefore cannot
         * leak a plausible-looking partial summary into the report.
         *
         * @return array{complete:bool,tracked_words:int,studied_words:int,mastered_words:int,hard_words:int,last_progress_at:string}
         */
        $classify_word_rows_for_user = static function (
            int $user_id,
            string $additional_where_sql,
            bool $classify_all_metrics
        ) use (
            $wpdb,
            $tables,
            $wordset_id,
            $raw_word_scan_limit,
            $raw_word_page_size,
            $classifier_columns_sql
        ): array {
            $result = [
                'complete' => false,
                'tracked_words' => 0,
                'studied_words' => 0,
                'mastered_words' => 0,
                'hard_words' => 0,
                'last_progress_at' => '',
            ];
            $last_word_id = 0;
            $scanned_rows = 0;

            while ($scanned_rows < $raw_word_scan_limit) {
                $remaining = $raw_word_scan_limit - $scanned_rows;
                $page_capacity = min($raw_word_page_size, $remaining);
                $query_limit = $page_capacity + 1;
                $where_sql = 'progress.user_id = %d';
                $params = [$user_id];
                if ($wordset_id > 0) {
                    $where_sql .= ' AND progress.wordset_id = %d';
                    $params[] = $wordset_id;
                }
                $where_sql .= ' AND progress.word_id > %d';
                $params[] = $last_word_id;
                if ($additional_where_sql !== '') {
                    $where_sql .= ' AND (' . $additional_where_sql . ')';
                }
                $params[] = $query_limit;

                $rows_sql = "SELECT {$classifier_columns_sql}
                    FROM {$tables['words']} progress
                    WHERE {$where_sql}
                    ORDER BY progress.word_id ASC
                    LIMIT %d";
                $wpdb->last_error = '';
                $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params), ARRAY_A);
                if (!is_array($rows) || (string) $wpdb->last_error !== '') {
                    return $result;
                }

                $has_more = count($rows) > $page_capacity;
                if ($has_more) {
                    $rows = array_slice($rows, 0, $page_capacity);
                }
                if (empty($rows)) {
                    $result['complete'] = true;
                    return $result;
                }

                $recording_type_word_ids = [];
                $previous_word_id = $last_word_id;
                foreach ($rows as $row) {
                    $row_word_id = is_array($row) && isset($row['word_id']) ? (int) $row['word_id'] : 0;
                    if ($row_word_id <= $previous_word_id) {
                        return $result;
                    }
                    $previous_word_id = $row_word_id;

                    if (
                        function_exists('ll_tools_progress_row_has_practice_recording_tracking')
                        && ll_tools_progress_row_has_practice_recording_tracking((array) $row)
                        && function_exists('ll_tools_decode_practice_recording_types')
                        && empty(ll_tools_decode_practice_recording_types($row['practice_required_recording_types'] ?? ''))
                    ) {
                        $recording_type_word_ids[] = $row_word_id;
                    }
                }

                $recording_types_map = [];
                if (!empty($recording_type_word_ids)) {
                    $recording_types_complete = true;
                    $recording_types_map = function_exists('ll_tools_get_word_practice_recording_types_map')
                        ? ll_tools_get_word_practice_recording_types_map($recording_type_word_ids, $recording_types_complete)
                        : [];
                    if (!$recording_types_complete) {
                        return $result;
                    }
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        return $result;
                    }
                    $row_word_id = isset($row['word_id']) ? (int) $row['word_id'] : 0;
                    if (array_key_exists($row_word_id, $recording_types_map)) {
                        $row['practice_required_recording_types_resolved'] = $recording_types_map[$row_word_id];
                    }

                    if ($classify_all_metrics) {
                        $result['tracked_words']++;
                        if (function_exists('ll_tools_user_progress_word_is_studied') && ll_tools_user_progress_word_is_studied($row)) {
                            $result['studied_words']++;
                        }
                        if (function_exists('ll_tools_user_progress_word_is_hard') && ll_tools_user_progress_word_is_hard($row)) {
                            $result['hard_words']++;
                        }
                        $last_seen_at = isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : '';
                        if ($last_seen_at !== '' && (
                            $result['last_progress_at'] === ''
                            || strcmp($last_seen_at, (string) $result['last_progress_at']) > 0
                        )) {
                            $result['last_progress_at'] = $last_seen_at;
                        }
                    }
                    if (function_exists('ll_tools_user_progress_word_is_mastered') && ll_tools_user_progress_word_is_mastered($row)) {
                        $result['mastered_words']++;
                    }
                }

                $scanned_rows += count($rows);
                $last_word_id = $previous_word_id;
                if (!$has_more) {
                    $result['complete'] = true;
                    return $result;
                }
                if ($scanned_rows >= $raw_word_scan_limit) {
                    return $result;
                }
            }

            return $result;
        };

        foreach (array_chunk($user_ids, 100) as $user_batch) {
            $placeholders = implode(', ', array_fill(0, count($user_batch), '%d'));
            $where_sql = "progress.user_id IN ({$placeholders})";
            $params = $user_batch;
            if ($wordset_id > 0) {
                $where_sql .= ' AND progress.wordset_id = %d';
                $params[] = $wordset_id;
            }

            $studied_sql = '(
                    progress.total_coverage > 0
                    OR progress.correct_clean > 0
                    OR progress.correct_after_retry > 0
                    OR progress.incorrect > 0
                )';
            $difficulty_sql = '(
                    (CAST(progress.incorrect AS SIGNED) * 3)
                    + (CAST(progress.lapse_count AS SIGNED) * 2)
                    + GREATEST(0, 2 - CAST(progress.stage AS SIGNED))
                    - LEAST(4, CAST(progress.correct_clean AS SIGNED))
                    - LEAST(2, CAST(progress.correct_after_retry AS SIGNED))
                    - FLOOR((CAST(progress.current_correct_streak AS SIGNED) * (CAST(progress.current_correct_streak AS SIGNED) + 1)) / 2)
                )';
            $legacy_recording_types_sql = "(
                    progress.practice_required_recording_types IS NOT NULL
                    AND progress.practice_required_recording_types <> ''
                    AND CASE
                        WHEN JSON_VALID(progress.practice_required_recording_types) = 0 THEN 1
                        WHEN progress.practice_correct_recording_types IS NULL OR progress.practice_correct_recording_types = '' THEN 1
                        WHEN JSON_VALID(progress.practice_correct_recording_types) = 0 THEN 1
                        WHEN JSON_TYPE(progress.practice_required_recording_types) <> 'ARRAY' THEN 1
                        WHEN JSON_TYPE(progress.practice_correct_recording_types) <> 'ARRAY' THEN 1
                        ELSE 0
                    END = 1
                )";
            $empty_required_types_sql = "(
                    CASE
                        WHEN progress.practice_required_recording_types IS NULL OR progress.practice_required_recording_types = '' THEN 1
                        WHEN JSON_VALID(progress.practice_required_recording_types) = 0 THEN 0
                        WHEN JSON_TYPE(progress.practice_required_recording_types) <> 'ARRAY' THEN 0
                        WHEN JSON_LENGTH(progress.practice_required_recording_types) = 0 THEN 1
                        ELSE 0
                    END = 1
                )";
            $nonempty_correct_types_sql = "(
                    progress.practice_correct_recording_types IS NOT NULL
                    AND progress.practice_correct_recording_types <> ''
                    AND CASE
                        WHEN JSON_VALID(progress.practice_correct_recording_types) = 0 THEN 1
                        WHEN JSON_TYPE(progress.practice_correct_recording_types) <> 'ARRAY' THEN 1
                        WHEN JSON_LENGTH(progress.practice_correct_recording_types) > 0 THEN 1
                        ELSE 0
                    END = 1
                )";
            $canonical_classifier_sql = "(
                    {$legacy_recording_types_sql}
                    OR ({$empty_required_types_sql} AND {$nonempty_correct_types_sql})
                )";

            if (!$can_aggregate_word_stats) {
                // Preserve custom streak-relief filter semantics without an unbounded multi-learner hydration.
                foreach ($user_batch as $user_id) {
                    $classified = $classify_word_rows_for_user((int) $user_id, '', true);
                    if (empty($classified['complete'])) {
                        $discard_word_stats((int) $user_id);
                        $mark_query_failed([(int) $user_id], 'words');
                        continue;
                    }
                    foreach (['tracked_words', 'studied_words', 'mastered_words', 'hard_words', 'last_progress_at'] as $key) {
                        $stats[(int) $user_id][$key] = $classified[$key];
                    }
                }
                continue;
            }

            if ($can_aggregate_word_stats) {
                $mastery_sql = "(
                    {$studied_sql}
                    AND {$difficulty_sql} < {$hard_threshold}
                    AND (
                        progress.mastery_unlocked = 1
                        OR (
                            progress.stage >= {$stage_threshold}
                            AND progress.correct_clean >= {$clean_threshold}
                            AND NOT {$canonical_classifier_sql}
                            AND (
                                progress.practice_required_recording_types IS NULL
                                OR progress.practice_required_recording_types = ''
                                OR CASE
                                    WHEN JSON_VALID(progress.practice_required_recording_types) = 0 THEN 0
                                    WHEN progress.practice_correct_recording_types IS NULL OR progress.practice_correct_recording_types = '' THEN 0
                                    WHEN JSON_VALID(progress.practice_correct_recording_types) = 0 THEN 0
                                    WHEN JSON_TYPE(progress.practice_required_recording_types) <> 'ARRAY' THEN 0
                                    WHEN JSON_TYPE(progress.practice_correct_recording_types) <> 'ARRAY' THEN 0
                                    ELSE JSON_CONTAINS(
                                        progress.practice_correct_recording_types,
                                        progress.practice_required_recording_types
                                    )
                                END = 1
                            )
                        )
                    )
                )";
                $rows_sql = "SELECT
                        progress.user_id,
                        COUNT(*) AS tracked_words,
                        SUM(CASE WHEN {$studied_sql} THEN 1 ELSE 0 END) AS studied_words,
                        SUM(CASE WHEN {$mastery_sql} THEN 1 ELSE 0 END) AS mastered_words,
                        SUM(CASE WHEN {$studied_sql} AND {$difficulty_sql} >= {$hard_threshold} THEN 1 ELSE 0 END) AS hard_words,
                        SUM(CASE WHEN {$canonical_classifier_sql} AND progress.mastery_unlocked <> 1 THEN 1 ELSE 0 END) AS canonical_classifier_rows,
                        MAX(progress.last_seen_at) AS last_progress_at
                    FROM {$tables['words']} progress
                    WHERE {$where_sql}
                    GROUP BY progress.user_id";
            }

            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params), ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '') {
                $mark_query_failed($user_batch, 'words');
                continue;
            }

            foreach ($rows as $row) {
                $user_id = is_array($row) && isset($row['user_id']) ? (int) $row['user_id'] : 0;
                if ($user_id <= 0 || !isset($stats[$user_id])) {
                    continue;
                }

                $stats[$user_id]['tracked_words'] = max(0, (int) ($row['tracked_words'] ?? 0));
                $stats[$user_id]['studied_words'] = max(0, (int) ($row['studied_words'] ?? 0));
                $stats[$user_id]['mastered_words'] = max(0, (int) ($row['mastered_words'] ?? 0));
                $stats[$user_id]['hard_words'] = max(0, (int) ($row['hard_words'] ?? 0));
                $stats[$user_id]['last_progress_at'] = isset($row['last_progress_at'])
                    ? (string) $row['last_progress_at']
                    : '';
            }

            foreach ($rows as $row) {
                $user_id = is_array($row) && isset($row['user_id']) ? (int) $row['user_id'] : 0;
                if (
                    $user_id <= 0
                    || !isset($stats[$user_id])
                    || max(0, (int) ($row['canonical_classifier_rows'] ?? 0)) === 0
                ) {
                    continue;
                }

                $classified = $classify_word_rows_for_user(
                    $user_id,
                    "{$canonical_classifier_sql} AND progress.mastery_unlocked <> 1",
                    false
                );
                if (empty($classified['complete'])) {
                    $discard_word_stats($user_id);
                    $mark_query_failed([$user_id], 'words');
                    continue;
                }
                $stats[$user_id]['mastered_words'] += max(0, (int) ($classified['mastered_words'] ?? 0));
            }
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $cutoff_7d = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));

        foreach (array_chunk($user_ids, 100) as $user_batch) {
            $placeholders = implode(', ', array_fill(0, count($user_batch), '%d'));
            $events_where_sql = "user_id IN ({$placeholders})";
            if ($wordset_id > 0) {
                $events_where_sql .= ' AND wordset_id = %d';
            }
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
            $events_query_params = array_merge([$cutoff, $cutoff, $cutoff, $cutoff_7d, $cutoff], $user_batch);
            if ($wordset_id > 0) {
                $events_query_params[] = $wordset_id;
            }

            $wpdb->last_error = '';
            $event_rows = $wpdb->get_results($wpdb->prepare($events_sql, $events_query_params), ARRAY_A);
            if (!is_array($event_rows) || (string) $wpdb->last_error !== '') {
                $mark_query_failed($user_batch, 'events');
                continue;
            }

            foreach ($event_rows as $row) {
                $user_id = is_array($row) && isset($row['user_id']) ? (int) $row['user_id'] : 0;
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
        if (!empty($stats['query_failed'])) {
            return '';
        }

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

if (!function_exists('ll_tools_user_progress_report_stat_display_data')) {
    /**
     * Return a report-safe label and sort value for one activity metric.
     *
     * Query failures intentionally carry an empty sort value so unavailable
     * data is never treated as a genuine zero or empty activity result.
     *
     * @return array{label:string,sort_value:string,query_failed:bool}
     */
    function ll_tools_user_progress_report_stat_display_data(array $stats, string $metric): array {
        if (!empty($stats['query_failed'])) {
            return [
                'label' => __('Unavailable', 'll-tools-text-domain'),
                'sort_value' => '',
                'query_failed' => true,
            ];
        }

        if ($metric === 'last_activity') {
            $value = ll_tools_user_progress_report_last_activity($stats);
        } elseif (in_array($metric, ['last_progress_at', 'last_event_at', 'last_stt_api_call_at'], true)) {
            $value = isset($stats[$metric]) ? (string) $stats[$metric] : '';
        } else {
            $value = (string) max(0, (int) ($stats[$metric] ?? 0));
        }

        return [
            'label' => $value,
            'sort_value' => $value,
            'query_failed' => false,
        ];
    }
}
