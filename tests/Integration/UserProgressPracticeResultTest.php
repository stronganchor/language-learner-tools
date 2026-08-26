<?php
declare(strict_types=1);

final class UserProgressPracticeResultTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_install_user_progress_schema();
    }

    public function test_practice_completion_persists_only_the_canonical_result_contract(): void
    {
        global $wpdb;

        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term(
            'Practice Result Persistence ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        $eventUuid = 'practice-result-' . wp_generate_uuid4();

        $events = [[
            'event_uuid' => $eventUuid,
            'event_type' => 'mode_session_complete',
            'mode' => 'practice',
            'wordset_id' => $wordsetId,
            'payload' => [
                'category_ids' => [],
                'result' => [
                    'schema' => 1,
                    'kind' => 'practice_first_try',
                    'score_given' => 7,
                    'score_maximum' => 10,
                    'score_basis' => 'first_try_distinct_words',
                    'percentage' => 999,
                    'teacher_id' => 123,
                ],
            ],
        ]];
        $stats = ll_tools_process_progress_events_batch($userId, $events);

        $this->assertSame(1, (int) ($stats['processed'] ?? 0));
        $retryStats = ll_tools_process_progress_events_batch($userId, $events);
        $this->assertSame(0, (int) ($retryStats['processed'] ?? -1));
        $this->assertSame(1, (int) ($retryStats['duplicates'] ?? 0));
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT user_id, wordset_id, mode, payload_json FROM ' . ll_tools_user_progress_table_names()['events'] . ' WHERE event_uuid = %s',
                $eventUuid
            ),
            ARRAY_A
        );
        $this->assertIsArray($row);
        $this->assertSame($userId, (int) ($row['user_id'] ?? 0));
        $this->assertSame($wordsetId, (int) ($row['wordset_id'] ?? 0));
        $this->assertSame('practice', (string) ($row['mode'] ?? ''));
        $this->assertSame(1, (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_user_progress_table_names()['events'] . ' WHERE event_uuid = %s',
                $eventUuid
            )
        ));

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame([
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => 7,
            'score_maximum' => 10,
            'score_basis' => 'first_try_distinct_words',
        ], $payload['result'] ?? null);

        $user = get_userdata($userId);
        $this->assertInstanceOf(WP_User::class, $user);
        $export = ll_tools_privacy_export_study_event_rows((string) $user->user_email, 1);
        $exportedPayload = null;
        foreach ((array) ($export['data'] ?? []) as $item) {
            foreach ((array) ($item['data'] ?? []) as $pair) {
                if (($pair['name'] ?? '') !== __('Payload', 'll-tools-text-domain')) {
                    continue;
                }
                $candidate = json_decode((string) ($pair['value'] ?? ''), true);
                if (is_array($candidate) && isset($candidate['result'])) {
                    $exportedPayload = $candidate;
                    break 2;
                }
            }
        }
        $this->assertSame($payload['result'], $exportedPayload['result'] ?? null);

        $erasure = ll_tools_privacy_erase_personal_data((string) $user->user_email, 1);
        $this->assertTrue((bool) ($erasure['items_removed'] ?? false));
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_user_progress_table_names()['events'] . ' WHERE event_uuid = %s',
                $eventUuid
            )
        );
        $this->assertSame(0, $remaining);
    }

    public function test_practice_result_sanitizer_omits_invalid_or_nonpractice_results(): void
    {
        $validResult = [
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => 7,
            'score_maximum' => 10,
            'score_basis' => 'first_try_distinct_words',
        ];
        $invalidCases = [
            'wrong schema' => ['mode' => 'practice', 'result' => array_merge($validResult, ['schema' => 2])],
            'wrong kind' => ['mode' => 'practice', 'result' => array_merge($validResult, ['kind' => 'official_grade'])],
            'wrong basis' => ['mode' => 'practice', 'result' => array_merge($validResult, ['score_basis' => 'submitted_percent'])],
            'score above maximum' => ['mode' => 'practice', 'result' => array_merge($validResult, ['score_given' => 11])],
            'empty result' => ['mode' => 'practice', 'result' => array_merge($validResult, ['score_given' => 0, 'score_maximum' => 0])],
            'fractional score' => ['mode' => 'practice', 'result' => array_merge($validResult, ['score_given' => 7.5])],
            'oversized maximum' => ['mode' => 'practice', 'result' => array_merge($validResult, ['score_maximum' => ll_tools_user_progress_practice_result_score_limit() + 1])],
            'nonpractice mode' => ['mode' => 'learning', 'result' => $validResult],
        ];

        foreach ($invalidCases as $label => $case) {
            ll_tools_user_progress_clear_source_error();
            $event = ll_tools_sanitize_progress_event([
                'event_uuid' => 'invalid-practice-result-' . wp_generate_uuid4(),
                'event_type' => 'mode_session_complete',
                'mode' => $case['mode'],
                'payload' => [
                    'category_ids' => [],
                    'result' => $case['result'],
                ],
            ]);

            $this->assertIsArray($event, $label);
            $this->assertArrayNotHasKey('result', (array) ($event['payload'] ?? []), $label);
        }
    }

    public function test_report_parser_accepts_only_the_canonical_stored_result_contract(): void
    {
        $payload = wp_json_encode([
            'category_ids' => [17],
            'result' => array_merge($this->canonicalResult(7, 10), [
                'percentage' => 999,
                'teacher_id' => 123,
            ]),
        ]);
        $this->assertIsString($payload);

        $this->assertSame([
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => 7,
            'score_maximum' => 10,
            'score_basis' => 'first_try_distinct_words',
            'percentage' => 70.0,
        ], ll_tools_user_progress_report_parse_practice_result($payload));

        $invalidPayloads = [
            'non-string payload' => ['result' => $this->canonicalResult(7, 10)],
            'empty payload' => '',
            'malformed JSON' => '{',
            'oversized payload' => str_repeat('x', (16 * 1024) + 1),
            'missing result' => wp_json_encode(['category_ids' => [17]]),
            'non-array result' => wp_json_encode(['result' => '7/10']),
            'wrong schema type' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['schema' => '1'])]),
            'wrong schema value' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['schema' => 2])]),
            'wrong kind' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['kind' => 'official_grade'])]),
            'wrong basis' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['score_basis' => 'submitted_percent'])]),
            'missing score given' => wp_json_encode(['result' => array_diff_key($this->canonicalResult(7, 10), ['score_given' => true])]),
            'missing score maximum' => wp_json_encode(['result' => array_diff_key($this->canonicalResult(7, 10), ['score_maximum' => true])]),
            'string score' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['score_given' => '7'])]),
            'fractional score' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['score_given' => 7.5])]),
            'negative score' => wp_json_encode(['result' => array_merge($this->canonicalResult(7, 10), ['score_given' => -1])]),
            'zero maximum' => wp_json_encode(['result' => array_merge($this->canonicalResult(0, 10), ['score_maximum' => 0])]),
            'score above maximum' => wp_json_encode(['result' => $this->canonicalResult(11, 10)]),
            'maximum above bound' => wp_json_encode(['result' => $this->canonicalResult(1, ll_tools_user_progress_practice_result_score_limit() + 1)]),
        ];

        foreach ($invalidPayloads as $label => $invalidPayload) {
            $this->assertNull(
                ll_tools_user_progress_report_parse_practice_result($invalidPayload),
                $label
            );
        }
    }

    public function test_report_keeps_a_canonical_result_with_one_thousand_ten_digit_category_ids(): void
    {
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Large Category Practice Result');
        $createdAt = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $categoryIds = range(1000000000, 1000000999);
        $payload = [
            'category_ids' => $categoryIds,
            'result' => $this->canonicalResult(87, 100),
        ];
        $payloadJson = wp_json_encode($payload);

        $this->assertIsString($payloadJson);
        $this->assertLessThanOrEqual(16 * 1024, strlen($payloadJson));
        $eventId = $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            $payload,
            $createdAt
        );

        $summaries = ll_tools_user_progress_report_practice_results_for_users([$learnerId], $wordsetId);

        $this->assertFalse((bool) ($summaries[$learnerId]['query_failed'] ?? true));
        $this->assertSame(1, (int) ($summaries[$learnerId]['attempts_30d'] ?? 0));
        $this->assertSame($eventId, (int) ($summaries[$learnerId]['latest_result']['event_id'] ?? 0));
        $this->assertSame(87, (int) ($summaries[$learnerId]['latest_result']['score_given'] ?? 0));
        $this->assertSame(100, (int) ($summaries[$learnerId]['latest_result']['score_maximum'] ?? 0));
    }

    public function test_report_selects_latest_valid_result_and_counts_recent_attempts_with_exact_scope(): void
    {
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $otherLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $oldOnlyLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Practice Result Report');
        $otherWordsetId = $this->createWordset('Other Practice Result Report');

        $latestCreatedAt = gmdate('Y-m-d H:i:s', time() - (2 * DAY_IN_SECONDS));
        $olderRecentCreatedAt = gmdate('Y-m-d H:i:s', time() - (12 * DAY_IN_SECONDS));
        $outsideWindowCreatedAt = gmdate('Y-m-d H:i:s', time() - (40 * DAY_IN_SECONDS));
        $newerInvalidCreatedAt = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(3, 5)],
            $olderRecentCreatedAt
        );
        $latestEventId = $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(8, 10)],
            $latestCreatedAt
        );
        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => array_merge($this->canonicalResult(9, 10), ['schema' => 2])],
            $newerInvalidCreatedAt
        );
        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(10, 10)],
            $outsideWindowCreatedAt
        );

        // Each of these rows differs on one report boundary and must not affect
        // the target learner/wordset Practice summary.
        $otherWordsetEventId = $this->insertProgressEvent(
            $learnerId,
            $otherWordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(1, 1)],
            $newerInvalidCreatedAt
        );
        $otherLearnerEventId = $this->insertProgressEvent(
            $otherLearnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(0, 1)],
            $newerInvalidCreatedAt
        );
        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'learning',
            ['result' => $this->canonicalResult(10, 10)],
            $newerInvalidCreatedAt
        );
        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'word_outcome',
            'practice',
            ['result' => $this->canonicalResult(10, 10)],
            $newerInvalidCreatedAt
        );
        $oldOnlyEventId = $this->insertProgressEvent(
            $oldOnlyLearnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(4, 4)],
            $outsideWindowCreatedAt
        );

        $summaries = ll_tools_user_progress_report_practice_results_for_users(
            [$learnerId, $otherLearnerId, $oldOnlyLearnerId],
            $wordsetId
        );

        $this->assertSame(2, (int) ($summaries[$learnerId]['attempts_30d'] ?? -1));
        $this->assertFalse((bool) ($summaries[$learnerId]['attempts_30d_truncated'] ?? true));
        $this->assertSame($latestEventId, (int) ($summaries[$learnerId]['latest_result']['event_id'] ?? 0));
        $this->assertSame($latestCreatedAt, (string) ($summaries[$learnerId]['latest_result']['created_at'] ?? ''));
        $this->assertSame(8, (int) ($summaries[$learnerId]['latest_result']['score_given'] ?? -1));
        $this->assertSame(10, (int) ($summaries[$learnerId]['latest_result']['score_maximum'] ?? -1));
        $this->assertSame(80.0, $summaries[$learnerId]['latest_result']['percentage'] ?? null);

        $this->assertSame(1, (int) ($summaries[$otherLearnerId]['attempts_30d'] ?? -1));
        $this->assertSame($otherLearnerEventId, (int) ($summaries[$otherLearnerId]['latest_result']['event_id'] ?? 0));
        $this->assertSame(0, (int) ($summaries[$otherLearnerId]['latest_result']['score_given'] ?? -1));

        $this->assertSame(0, (int) ($summaries[$oldOnlyLearnerId]['attempts_30d'] ?? -1));
        $this->assertSame($oldOnlyEventId, (int) ($summaries[$oldOnlyLearnerId]['latest_result']['event_id'] ?? 0));
        $this->assertSame($outsideWindowCreatedAt, (string) ($summaries[$oldOnlyLearnerId]['latest_result']['created_at'] ?? ''));

        $otherWordsetSummaries = ll_tools_user_progress_report_practice_results_for_users(
            [$learnerId],
            $otherWordsetId
        );
        $this->assertSame(1, (int) ($otherWordsetSummaries[$learnerId]['attempts_30d'] ?? -1));
        $this->assertSame($otherWordsetEventId, (int) ($otherWordsetSummaries[$learnerId]['latest_result']['event_id'] ?? 0));
    }

    public function test_teacher_progress_row_and_display_expose_the_practice_result_shape(): void
    {
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'display_name' => 'Practice Result Learner',
            'user_email' => 'practice-result-learner@example.org',
        ]);
        $wordsetId = $this->createWordset('Teacher Practice Result');
        $createdAt = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $eventId = $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(7, 9)],
            $createdAt
        );

        $rows = ll_tools_teacher_class_student_progress_rows([$learnerId], $wordsetId);
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertInstanceOf(WP_User::class, $row['user'] ?? null);
        $this->assertSame($learnerId, (int) $row['user']->ID);
        $this->assertIsArray($row['stats'] ?? null);
        $this->assertNotSame('', (string) ($row['wordset_name'] ?? ''));
        $this->assertSame(1, (int) ($row['practice_attempts_30d'] ?? -1));
        $this->assertFalse((bool) ($row['practice_attempts_30d_truncated'] ?? true));
        $this->assertFalse((bool) ($row['practice_query_failed'] ?? true));
        $this->assertSame([
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => 7,
            'score_maximum' => 9,
            'score_basis' => 'first_try_distinct_words',
            'percentage' => 77.8,
            'event_id' => $eventId,
            'created_at' => $createdAt,
        ], $row['latest_practice_result'] ?? null);

        $display = ll_tools_teacher_class_practice_result_display_data($row);
        $this->assertSame(1, $display['attempts_30d'] ?? null);
        $this->assertSame(number_format_i18n(1), $display['attempts_30d_label'] ?? null);
        $this->assertSame('1', $display['attempts_sort_value'] ?? null);
        $this->assertSame('77.8', $display['sort_value'] ?? null);
        $this->assertStringContainsString('7 / 9', (string) ($display['score_label'] ?? ''));
        $this->assertStringContainsString('77.8%', (string) ($display['score_label'] ?? ''));
        $this->assertSame(gmdate('c', strtotime($createdAt . ' UTC')), $display['datetime'] ?? null);
        $this->assertStringContainsString(gmdate('Y-m-d H:i', strtotime($createdAt . ' UTC')), (string) ($display['date_label'] ?? ''));

        $emptyDisplay = ll_tools_teacher_class_practice_result_display_data([]);
        $this->assertSame('', $emptyDisplay['score_label'] ?? null);
        $this->assertSame(number_format_i18n(0), $emptyDisplay['attempts_30d_label'] ?? null);
        $this->assertSame('0', $emptyDisplay['attempts_sort_value'] ?? null);

        $truncatedDisplay = ll_tools_teacher_class_practice_result_display_data([
            'practice_attempts_30d' => 25,
            'practice_attempts_30d_truncated' => true,
        ]);
        $this->assertSame('25+', $truncatedDisplay['attempts_30d_label'] ?? null);
    }

    public function test_report_caps_each_learner_scan_and_marks_a_recent_count_as_truncated(): void
    {
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Bounded Practice Result Scan');
        $createdAt = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        for ($attempt = 1; $attempt <= 26; $attempt++) {
            $this->insertProgressEvent(
                $learnerId,
                $wordsetId,
                'mode_session_complete',
                'practice',
                ['result' => $this->canonicalResult($attempt % 2, 1)],
                $createdAt
            );
        }

        $scanLimit = static function (): int {
            return 25;
        };
        add_filter('ll_tools_user_progress_report_practice_result_scan_limit', $scanLimit);
        try {
            $summaries = ll_tools_user_progress_report_practice_results_for_users([$learnerId], $wordsetId);
        } finally {
            remove_filter('ll_tools_user_progress_report_practice_result_scan_limit', $scanLimit);
        }

        $this->assertSame(25, (int) ($summaries[$learnerId]['attempts_30d'] ?? -1));
        $this->assertTrue((bool) ($summaries[$learnerId]['attempts_30d_truncated'] ?? false));
        $display = ll_tools_teacher_class_practice_result_display_data([
            'practice_attempts_30d' => $summaries[$learnerId]['attempts_30d'] ?? 0,
            'practice_attempts_30d_truncated' => $summaries[$learnerId]['attempts_30d_truncated'] ?? false,
        ]);
        $this->assertSame('25+', $display['attempts_30d_label'] ?? null);
    }

    public function test_practice_report_batches_queries_with_per_user_and_payload_bounds(): void
    {
        global $wpdb;

        $wordsetId = $this->createWordset('Batched Practice Result Queries');
        $learnerIds = [];
        for ($index = 0; $index < 5; $index++) {
            $learnerIds[] = self::factory()->user->create(['role' => 'subscriber']);
        }

        $eventsTable = ll_tools_user_progress_table_names()['events'];
        $queries = [];
        $capture = static function (string $query) use (&$queries, $eventsTable): string {
            if (strpos($query, "FROM {$eventsTable}") !== false && strpos($query, 'mode_session_complete') !== false) {
                $queries[] = $query;
            }
            return $query;
        };
        $batchSize = static function (): int {
            return 2;
        };

        add_filter('query', $capture);
        add_filter('ll_tools_user_progress_report_practice_result_query_batch_size', $batchSize);
        try {
            $summaries = ll_tools_user_progress_report_practice_results_for_users($learnerIds, $wordsetId);
        } finally {
            remove_filter('ll_tools_user_progress_report_practice_result_query_batch_size', $batchSize);
            remove_filter('query', $capture);
        }

        $this->assertCount(5, $summaries);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(1, substr_count($query, 'UNION ALL'));
            $this->assertStringContainsString('OCTET_LENGTH(payload_json) <= 16384', $query);
            $this->assertStringContainsString('LIMIT 501', $query);
        }
    }

    public function test_practice_report_reduces_a_deep_scan_to_one_learner_per_query(): void
    {
        $wordsetId = $this->createWordset('Memory-Bounded Practice Result Queries');
        $learnerIds = [];
        for ($index = 0; $index < 3; $index++) {
            $learnerIds[] = self::factory()->user->create(['role' => 'subscriber']);
        }

        $eventsTable = ll_tools_user_progress_table_names()['events'];
        $queries = [];
        $capture = static function (string $query) use (&$queries, $eventsTable): string {
            if (strpos($query, "FROM {$eventsTable}") !== false && strpos($query, 'mode_session_complete') !== false) {
                $queries[] = $query;
            }
            return $query;
        };
        $scanLimit = static function (): int {
            return 1000;
        };
        $configuredBatchSize = static function (): int {
            return 5;
        };

        add_filter('query', $capture);
        add_filter('ll_tools_user_progress_report_practice_result_scan_limit', $scanLimit);
        add_filter('ll_tools_user_progress_report_practice_result_query_batch_size', $configuredBatchSize);
        try {
            $summaries = ll_tools_user_progress_report_practice_results_for_users($learnerIds, $wordsetId);
        } finally {
            remove_filter('ll_tools_user_progress_report_practice_result_query_batch_size', $configuredBatchSize);
            remove_filter('ll_tools_user_progress_report_practice_result_scan_limit', $scanLimit);
            remove_filter('query', $capture);
        }

        $this->assertCount(3, $summaries);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('UNION ALL', $query);
            $this->assertStringContainsString('OCTET_LENGTH(payload_json) <= 16384', $query);
            $this->assertStringContainsString('LIMIT 1001', $query);
        }
    }

    public function test_practice_report_marks_query_failure_without_exposing_database_diagnostics(): void
    {
        global $wpdb;

        $wordsetId = $this->createWordset('Failed Practice Result Query');
        $learnerIds = [
            self::factory()->user->create(['role' => 'subscriber']),
            self::factory()->user->create(['role' => 'subscriber']),
        ];
        $eventsTable = ll_tools_user_progress_table_names()['events'];
        $failReportQuery = static function (string $query) use ($eventsTable): string {
            if (strpos($query, "FROM {$eventsTable}") !== false && strpos($query, 'OCTET_LENGTH(payload_json)') !== false) {
                return "SELECT ll_tools_missing_column FROM {$eventsTable}";
            }
            return $query;
        };
        $previousSuppressErrors = $wpdb->suppress_errors(true);

        add_filter('query', $failReportQuery);
        try {
            $failedSummaries = ll_tools_user_progress_report_practice_results_for_users($learnerIds, $wordsetId);
        } finally {
            remove_filter('query', $failReportQuery);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        foreach ($learnerIds as $learnerId) {
            $this->assertTrue((bool) ($failedSummaries[$learnerId]['query_failed'] ?? false));
            $this->assertNull($failedSummaries[$learnerId]['latest_result'] ?? null);
            $this->assertSame(0, (int) ($failedSummaries[$learnerId]['attempts_30d'] ?? -1));
            $this->assertArrayNotHasKey('query_error', $failedSummaries[$learnerId]);
        }

        $emptySummaries = ll_tools_user_progress_report_practice_results_for_users($learnerIds, $wordsetId);
        foreach ($learnerIds as $learnerId) {
            $this->assertFalse((bool) ($emptySummaries[$learnerId]['query_failed'] ?? true));
        }

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failReportQuery);
        try {
            $studentRows = ll_tools_teacher_class_student_progress_rows($learnerIds, $wordsetId);
        } finally {
            remove_filter('query', $failReportQuery);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        $this->assertCount(2, $studentRows);
        foreach ($studentRows as $studentRow) {
            $this->assertTrue((bool) ($studentRow['practice_query_failed'] ?? false));
            $this->assertNull($studentRow['latest_practice_result'] ?? null);
            $this->assertSame(0, (int) ($studentRow['practice_attempts_30d'] ?? -1));
        }

        $unavailableDisplay = ll_tools_teacher_class_practice_result_display_data($studentRows[0]);
        $this->assertTrue((bool) ($unavailableDisplay['query_failed'] ?? false));
        $this->assertSame('', $unavailableDisplay['sort_value'] ?? null);
        $this->assertSame('', $unavailableDisplay['attempts_sort_value'] ?? null);
        $this->assertSame(__('Unavailable', 'll-tools-text-domain'), $unavailableDisplay['attempts_30d_label'] ?? null);

        ob_start();
        ll_tools_teacher_class_render_frontend_practice_cells($studentRows[0]);
        $unavailableHtml = (string) ob_get_clean();
        $this->assertSame(2, substr_count($unavailableHtml, 'data-sort-value=""'));
        $this->assertSame(2, substr_count($unavailableHtml, '>Unavailable</span>'));
        $this->assertSame(2, substr_count($unavailableHtml, 'aria-label="Practice data is temporarily unavailable."'));
        $this->assertStringNotContainsString('No practice result', $unavailableHtml);
    }

    public function test_frontend_class_view_limits_practice_results_to_the_owned_class_and_wordset(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacherId = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $otherTeacherId = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $learnerId = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'display_name' => 'Owned Practice Learner',
            'user_email' => 'owned-practice-learner@example.org',
        ]);
        $otherLearnerId = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'display_name' => 'Other Practice Learner',
            'user_email' => 'other-practice-learner@example.org',
        ]);
        $wordsetId = $this->createWordset('Owned Class Practice Results');
        $otherWordsetId = $this->createWordset('Other Wordset Practice Results');
        $wordset = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $classId = ll_tools_teacher_class_create($teacherId, 'Owned Practice Class', $wordsetId);
        $otherClassId = ll_tools_teacher_class_create($otherTeacherId, 'Other Practice Class', $wordsetId);
        $this->assertIsInt($classId);
        $this->assertIsInt($otherClassId);
        $this->assertTrue(ll_tools_teacher_class_add_student($classId, $learnerId));
        $this->assertTrue(ll_tools_teacher_class_add_student($otherClassId, $otherLearnerId));

        $createdAt = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $this->insertProgressEvent(
            $learnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(7, 10)],
            $createdAt
        );
        $this->insertProgressEvent(
            $learnerId,
            $otherWordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(10, 10)],
            $createdAt
        );
        $this->insertProgressEvent(
            $otherLearnerId,
            $wordsetId,
            'mode_session_complete',
            'practice',
            ['result' => $this->canonicalResult(9, 10)],
            $createdAt
        );

        $originalGet = $_GET;
        wp_set_current_user($teacherId);
        try {
            $_GET = ['class_id' => (string) $classId];
            $html = ll_tools_wordset_page_render_teacher_classes_view($wordset, 'https://example.org/back');

            $_GET = ['class_id' => (string) $otherClassId];
            $inaccessibleHtml = ll_tools_wordset_page_render_teacher_classes_view($wordset, 'https://example.org/back');
        } finally {
            $_GET = $originalGet;
        }

        $this->assertStringContainsString('Latest practice', $html);
        $this->assertStringContainsString('30d attempts', $html);
        $this->assertStringContainsString('owned-practice-learner@example.org', $html);
        $this->assertStringContainsString('7 / 10 (70%)', $html);
        $this->assertStringNotContainsString('10 / 10 (100%)', $html);
        $this->assertStringNotContainsString('other-practice-learner@example.org', $html);
        $this->assertStringNotContainsString('9 / 10 (90%)', $html);
        $this->assertStringNotContainsString('other-practice-learner@example.org', $inaccessibleHtml);
        $this->assertStringNotContainsString('9 / 10 (90%)', $inaccessibleHtml);
    }

    /** @return array<string,int|string> */
    private function canonicalResult(int $scoreGiven, int $scoreMaximum): array
    {
        return [
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => $scoreGiven,
            'score_maximum' => $scoreMaximum,
            'score_basis' => 'first_try_distinct_words',
        ];
    }

    private function createWordset(string $label): int
    {
        $wordset = wp_insert_term(
            $label . ' ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        return (int) $wordset['term_id'];
    }

    private function insertProgressEvent(
        int $userId,
        int $wordsetId,
        string $eventType,
        string $mode,
        array $payload,
        string $createdAt
    ): int {
        global $wpdb;

        $payloadJson = wp_json_encode($payload);
        $this->assertIsString($payloadJson);
        $inserted = $wpdb->insert(
            ll_tools_user_progress_table_names()['events'],
            [
                'user_id' => $userId,
                'event_uuid' => 'practice-result-report-' . wp_generate_uuid4(),
                'event_type' => $eventType,
                'mode' => $mode,
                'wordset_id' => $wordsetId,
                'payload_json' => $payloadJson,
                'created_at' => $createdAt,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        $this->assertSame(1, $inserted, (string) $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }
}
