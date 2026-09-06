<?php
declare(strict_types=1);

final class UserProgressReportTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    public function test_user_progress_report_stats_include_stt_request_counts(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetOneId = $this->createWordset('Report Stats One');
        $wordsetTwoId = $this->createWordset('Report Stats Two');

        $this->assertTrue(ll_tools_record_server_progress_event($userId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetOneId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));
        $this->assertTrue(ll_tools_record_server_progress_event($userId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetOneId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'assemblyai'],
        ]));
        $this->assertTrue(ll_tools_record_server_progress_event($userId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetTwoId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));

        $allStats = ll_tools_user_progress_report_stats_for_users([$userId], 0);
        $this->assertSame(3, (int) ($allStats[$userId]['stt_calls_total'] ?? 0));
        $this->assertSame(3, (int) ($allStats[$userId]['stt_calls_7d'] ?? 0));
        $this->assertSame(3, (int) ($allStats[$userId]['stt_calls_30d'] ?? 0));
        $this->assertNotSame('', (string) ($allStats[$userId]['last_stt_api_call_at'] ?? ''));

        $filteredStats = ll_tools_user_progress_report_stats_for_users([$userId], $wordsetOneId);
        $this->assertSame(2, (int) ($filteredStats[$userId]['stt_calls_total'] ?? 0));
        $this->assertSame(2, (int) ($filteredStats[$userId]['stt_calls_30d'] ?? 0));
    }

    public function test_render_user_progress_report_page_shows_stt_request_counts(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'display_name' => 'Speaking Counter Learner',
            'user_email' => 'stt-counter@example.test',
        ]);
        $wordsetId = $this->createWordset('Report Render Wordset');

        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));
        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));

        wp_set_current_user($adminId);
        $_GET = [
            'page' => ll_tools_get_user_progress_report_page_slug(),
            'user_id' => (string) $learnerId,
        ];

        ob_start();
        try {
            ll_tools_render_user_progress_report_page();
            $output = (string) ob_get_clean();
        } finally {
            $_GET = $this->getBackup;
        }

        $this->assertStringContainsString('STT Calls', $output);
        $this->assertStringContainsString('30d STT', $output);
        $this->assertStringContainsString('STT calls', $output);
        $this->assertStringContainsString('7d STT calls', $output);
        $this->assertStringContainsString('Last STT call (UTC)', $output);
        $this->assertStringContainsString('Speaking Counter Learner', $output);
        $this->assertStringContainsString('>2<', $output);
    }

    public function test_report_user_query_pages_progress_users_in_sql(): void
    {
        $wordsetId = $this->createWordset('Paged Report Wordset');
        $learnerIds = [];
        for ($index = 1; $index <= 45; $index++) {
            $learnerId = self::factory()->user->create([
                'role' => 'subscriber',
                'display_name' => sprintf('Paged Learner %02d', $index),
                'user_email' => sprintf('paged-learner-%02d@example.test', $index),
            ]);
            $learnerIds[] = $learnerId;
            $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
                'event_type' => 'stt_api_call',
                'wordset_id' => $wordsetId,
                'payload' => ['source' => 'report_paging_test', 'provider' => 'hosted_api'],
            ]));
        }

        $queries = [];
        $captureQuery = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $captureQuery);
        try {
            $pageOne = ll_tools_user_progress_report_query_users($wordsetId, '', 1, 20);
            $pageTwo = ll_tools_user_progress_report_query_users($wordsetId, '', 2, 20);
            $pageThree = ll_tools_user_progress_report_query_users($wordsetId, '', 3, 20);
            $searchPage = ll_tools_user_progress_report_query_users($wordsetId, 'Paged Learner 37', 1, 20);
        } finally {
            remove_filter('query', $captureQuery);
        }

        $this->assertSame(45, (int) ($pageOne['total'] ?? 0));
        $this->assertCount(20, (array) ($pageOne['user_ids'] ?? []));
        $this->assertCount(20, (array) ($pageTwo['user_ids'] ?? []));
        $this->assertCount(5, (array) ($pageThree['user_ids'] ?? []));
        $this->assertSame([$learnerIds[36]], array_values(array_map('intval', (array) ($searchPage['user_ids'] ?? []))));
        $this->assertEmpty(array_intersect(
            array_map('intval', (array) ($pageOne['user_ids'] ?? [])),
            array_map('intval', (array) ($pageTwo['user_ids'] ?? []))
        ));

        $queryLog = implode("\n", $queries);
        $this->assertStringContainsString('LIMIT 20 OFFSET 20', $queryLog);
        $this->assertDoesNotMatchRegularExpression('/SELECT\s+DISTINCT\s+user_id\s+FROM/i', $queryLog);
        $this->assertFalse((bool) ($pageOne['query_failed'] ?? true));
    }

    public function test_report_user_count_and_id_query_failures_return_typed_page_states(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Failed Page Queries Wordset');
        $this->insertProgressRow($learnerId, $wordsetId, ['total_coverage' => 1]);

        $failCountQuery = static function (string $query) use ($wpdb): string {
            if (preg_match('/SELECT\s+COUNT\(\*\).*INNER\s+JOIN/is', $query)) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_report_count";
            }
            return $query;
        };
        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failCountQuery);
        try {
            $failedCountPage = ll_tools_user_progress_report_query_users($wordsetId, '', 1, 20);
        } finally {
            remove_filter('query', $failCountQuery);
            $wpdb->suppress_errors($previousSuppress);
        }

        $this->assertTrue((bool) ($failedCountPage['query_failed'] ?? false));
        $this->assertSame('count', $failedCountPage['failure_stage'] ?? null);
        $this->assertSame([], $failedCountPage['user_ids'] ?? null);

        $failIdsQuery = static function (string $query) use ($wpdb): string {
            if (preg_match('/SELECT\s+users\.ID.*INNER\s+JOIN/is', $query)) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_report_ids";
            }
            return $query;
        };
        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failIdsQuery);
        try {
            $failedIdsPage = ll_tools_user_progress_report_query_users($wordsetId, '', 1, 20);
        } finally {
            remove_filter('query', $failIdsQuery);
            $wpdb->suppress_errors($previousSuppress);
        }

        $this->assertTrue((bool) ($failedIdsPage['query_failed'] ?? false));
        $this->assertSame('ids', $failedIdsPage['failure_stage'] ?? null);
        $this->assertSame(1, (int) ($failedIdsPage['total'] ?? 0));
        $this->assertSame([], $failedIdsPage['users'] ?? null);
    }

    public function test_report_render_uses_unavailable_cells_when_stats_query_fails(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'display_name' => 'Unavailable Stats Learner',
            'user_email' => 'unavailable-stats@example.test',
        ]);
        $wordsetId = $this->createWordset('Unavailable Render Wordset');
        $this->insertProgressRow($learnerId, $wordsetId, ['total_coverage' => 1]);
        $tableNames = ll_tools_user_progress_table_names();
        $wordsTable = preg_quote($tableNames['words'], '/');
        $eventsTable = preg_quote($tableNames['events'], '/');
        $failStatsQueries = static function (string $query) use ($wordsTable, $eventsTable, $wpdb): string {
            if (
                preg_match('/FROM\s+' . $wordsTable . '\s+progress\s+WHERE/i', $query)
                || preg_match('/FROM\s+' . $eventsTable . '\s+WHERE/i', $query)
            ) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_render_stats";
            }
            return $query;
        };

        wp_set_current_user($adminId);
        $_GET = [
            'page' => ll_tools_get_user_progress_report_page_slug(),
            'wordset_id' => (string) $wordsetId,
            'user_id' => (string) $learnerId,
        ];
        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failStatsQueries);
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            ll_tools_render_user_progress_report_page();
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            remove_filter('query', $failStatsQueries);
            $wpdb->suppress_errors($previousSuppress);
            $_GET = $this->getBackup;
        }

        $this->assertStringContainsString('Unavailable Stats Learner', $output);
        $this->assertGreaterThanOrEqual(8, substr_count($output, 'data-sort-value="">Unavailable</td>'));
        $this->assertSame(5, preg_match_all('/data-ll-tools-detail-word-stat="[^"]+">Unavailable<\/td>/', $output));
        $this->assertStringContainsString('data-ll-tools-detail-rounds-stat="1">Unavailable</td>', $output);
        $this->assertStringContainsString('data-ll-tools-detail-categories-unavailable="1">Unavailable</p>', $output);
        $this->assertStringContainsString('data-ll-tools-detail-attention-unavailable="1">Unavailable</p>', $output);
        $this->assertStringNotContainsString('No category analytics are available for this learner and wordset.', $output);
        $this->assertStringNotContainsString('No non-mastered or difficult words are currently flagged.', $output);
        $this->assertStringNotContainsString('No learner progress data matched the current filters.', $output);
    }

    public function test_report_stats_query_aggregates_progress_words_without_row_hydration(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Projected Report Stats');
        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'report_projection_test', 'provider' => 'hosted_api'],
        ]));

        $queries = [];
        $captureQuery = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $captureQuery);
        try {
            ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        } finally {
            remove_filter('query', $captureQuery);
        }

        $wordsTable = preg_quote(ll_tools_user_progress_table_names()['words'], '/');
        $progressQueries = array_values(array_filter($queries, static function (string $query) use ($wordsTable): bool {
            return (bool) preg_match('/FROM\s+' . $wordsTable . '\s+progress\s+WHERE/i', $query);
        }));
        $this->assertCount(1, $progressQueries);
        $this->assertStringNotContainsString('SELECT *', strtoupper($progressQueries[0]));
        $this->assertStringContainsString('COUNT(*) AS tracked_words', $progressQueries[0]);
        $this->assertStringContainsString('SUM(CASE WHEN', $progressQueries[0]);
        $this->assertStringContainsString('MAX(progress.last_seen_at) AS last_progress_at', $progressQueries[0]);
        $this->assertStringContainsString('GROUP BY progress.user_id', $progressQueries[0]);
        $this->assertStringContainsString('practice_required_recording_types', $progressQueries[0]);
        $this->assertSame($wpdb->prefix . 'll_tools_user_word_progress', ll_tools_user_progress_table_names()['words']);
    }

    public function test_report_stats_sql_aggregation_matches_canonical_word_classifiers(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Aggregate Parity Wordset');
        $baseTime = strtotime('2026-08-30 12:00:00 UTC');
        $rows = [
            [
                'total_coverage' => 0,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime),
            ],
            [
                'total_coverage' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 60),
            ],
            [
                'total_coverage' => 1,
                'incorrect' => 2,
                'lapse_count' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 120),
            ],
            [
                'total_coverage' => 3,
                'correct_clean' => 3,
                'current_correct_streak' => 2,
                'stage' => 5,
                'practice_required_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation']),
                'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation']),
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 180),
            ],
            [
                'total_coverage' => 3,
                'correct_clean' => 3,
                'stage' => 5,
                'practice_required_recording_types' => ll_tools_encode_practice_recording_types(['question']),
                'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation']),
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 240),
            ],
            [
                'total_coverage' => 3,
                'correct_clean' => 3,
                'stage' => 5,
                'practice_required_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation']),
                'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question']),
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 300),
            ],
            [
                'total_coverage' => 3,
                'correct_clean' => 3,
                'stage' => 5,
                'practice_required_recording_types' => 'question,isolation',
                'practice_correct_recording_types' => 'question,isolation,word',
                'last_seen_at' => gmdate('Y-m-d H:i:s', $baseTime + 360),
            ],
        ];

        foreach ($rows as $row) {
            $this->insertProgressRow($learnerId, $wordsetId, $row);
        }

        $table = ll_tools_user_progress_table_names()['words'];
        $storedRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND wordset_id = %d ORDER BY word_id ASC",
            $learnerId,
            $wordsetId
        ), ARRAY_A);
        $expected = [
            'tracked_words' => count($storedRows),
            'studied_words' => count(array_filter($storedRows, 'll_tools_user_progress_word_is_studied')),
            'mastered_words' => count(array_filter($storedRows, 'll_tools_user_progress_word_is_mastered')),
            'hard_words' => count(array_filter($storedRows, 'll_tools_user_progress_word_is_hard')),
            'last_progress_at' => gmdate('Y-m-d H:i:s', $baseTime + 360),
        ];

        $stats = ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        $actual = (array) ($stats[$learnerId] ?? []);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $actual[$key] ?? null, $key);
        }
        $this->assertFalse((bool) ($actual['query_failed'] ?? true));
    }

    public function test_report_stats_uses_current_required_audio_for_empty_stored_requirements_without_double_counting(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Current Audio Mastery Wordset');
        $incompleteWordId = $this->insertProgressRow($learnerId, $wordsetId, [
            'total_coverage' => 3,
            'correct_clean' => 3,
            'stage' => 5,
            'practice_required_recording_types' => '',
            'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question']),
        ]);
        $completeWordId = $this->insertProgressRow($learnerId, $wordsetId, [
            'total_coverage' => 3,
            'correct_clean' => 3,
            'stage' => 5,
            'practice_required_recording_types' => '',
            'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation']),
        ]);
        $unlockedWordId = $this->insertProgressRow($learnerId, $wordsetId, [
            'total_coverage' => 3,
            'correct_clean' => 3,
            'stage' => 5,
            'mastery_unlocked' => 1,
            'practice_required_recording_types' => '',
            'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question']),
        ]);

        foreach ([$incompleteWordId, $completeWordId, $unlockedWordId] as $wordId) {
            foreach (['question', 'isolation'] as $recordingType) {
                $audioId = self::factory()->post->create([
                    'post_type' => 'word_audio',
                    'post_status' => 'publish',
                    'post_parent' => $wordId,
                    'post_title' => sprintf('Report %s audio for %d', $recordingType, $wordId),
                ]);
                update_post_meta($audioId, 'audio_file_path', sprintf('/report-audio/%d-%s.mp3', $wordId, $recordingType));
                $assigned = wp_set_object_terms($audioId, [$recordingType], 'recording_type', false);
                $this->assertNotWPError($assigned);
            }
        }

        $queries = [];
        $captureQuery = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $captureQuery);
        try {
            $stats = ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        } finally {
            remove_filter('query', $captureQuery);
        }

        $actual = (array) ($stats[$learnerId] ?? []);
        $this->assertFalse((bool) ($actual['query_failed'] ?? true));
        $this->assertSame(3, (int) ($actual['tracked_words'] ?? 0));
        $this->assertSame(2, (int) ($actual['mastered_words'] ?? 0));

        $table = ll_tools_user_progress_table_names()['words'];
        $storedRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND wordset_id = %d ORDER BY word_id ASC",
            $learnerId,
            $wordsetId
        ), ARRAY_A);
        $masteredWordIds = array_values(array_map(static function (array $row): int {
            return (int) ($row['word_id'] ?? 0);
        }, array_filter($storedRows, 'll_tools_user_progress_word_is_mastered')));
        $this->assertNotContains($incompleteWordId, $masteredWordIds);
        $this->assertContains($completeWordId, $masteredWordIds);
        $this->assertContains($unlockedWordId, $masteredWordIds);

        $queryLog = implode("\n", $queries);
        $this->assertMatchesRegularExpression('/SELECT\s+progress\.word_id,.*ORDER BY\s+progress\.word_id\s+ASC.*LIMIT\s+1001/is', $queryLog);
        $this->assertMatchesRegularExpression('/p\.post_parent\s+IN\s*\([^)]*' . $incompleteWordId . '[^)]*' . $completeWordId . '[^)]*\)/is', $queryLog);
    }

    public function test_raw_classifier_fallback_is_per_learner_keyset_paged_and_fails_closed_at_the_cap(): void
    {
        global $wpdb;

        $overCapLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $queryFailureLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $healthyLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Raw Classifier Guard Wordset');
        for ($index = 0; $index < 3; $index++) {
            $this->insertProgressRow($overCapLearnerId, $wordsetId, ['total_coverage' => 1]);
        }
        $this->insertProgressRow($queryFailureLearnerId, $wordsetId, ['total_coverage' => 1]);
        $this->insertProgressRow($healthyLearnerId, $wordsetId, ['total_coverage' => 1]);

        $streakReliefFilter = static function (int $relief, int $streak): int {
            return $relief + ($streak > 100000 ? 1 : 0);
        };
        $scanLimitFilter = static function (int $limit): int {
            return 2;
        };
        $pageSizeFilter = static function (int $pageSize): int {
            return 1;
        };
        $wordsTable = preg_quote(ll_tools_user_progress_table_names()['words'], '/');
        $rawQueries = [];
        $captureAndFailQuery = static function (string $query) use (
            &$rawQueries,
            $wordsTable,
            $queryFailureLearnerId,
            $wpdb
        ): string {
            if (!preg_match('/FROM\s+' . $wordsTable . '\s+progress\s+WHERE.*ORDER BY\s+progress\.word_id\s+ASC.*LIMIT/is', $query)) {
                return $query;
            }
            $rawQueries[] = $query;
            if (preg_match('/progress\.user_id\s*=\s*' . $queryFailureLearnerId . '\b/i', $query)) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_raw_report_words";
            }
            return $query;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('ll_tools_user_progress_streak_difficulty_relief', $streakReliefFilter, 10, 2);
        add_filter('ll_tools_user_progress_report_raw_word_scan_limit', $scanLimitFilter);
        add_filter('ll_tools_user_progress_report_raw_word_page_size', $pageSizeFilter);
        add_filter('query', $captureAndFailQuery);
        try {
            $stats = ll_tools_user_progress_report_stats_for_users(
                [$overCapLearnerId, $queryFailureLearnerId, $healthyLearnerId],
                $wordsetId
            );
        } finally {
            remove_filter('query', $captureAndFailQuery);
            remove_filter('ll_tools_user_progress_report_raw_word_page_size', $pageSizeFilter);
            remove_filter('ll_tools_user_progress_report_raw_word_scan_limit', $scanLimitFilter);
            remove_filter('ll_tools_user_progress_streak_difficulty_relief', $streakReliefFilter, 10);
            $wpdb->suppress_errors($previousSuppress);
        }

        $overCap = (array) ($stats[$overCapLearnerId] ?? []);
        $queryFailure = (array) ($stats[$queryFailureLearnerId] ?? []);
        $healthy = (array) ($stats[$healthyLearnerId] ?? []);
        foreach ([$overCap, $queryFailure] as $failed) {
            $this->assertTrue((bool) ($failed['query_failed'] ?? false));
            $this->assertTrue((bool) ($failed['words_query_failed'] ?? false));
            $this->assertSame(0, (int) ($failed['tracked_words'] ?? -1));
            $this->assertSame(0, (int) ($failed['studied_words'] ?? -1));
            $this->assertSame('', (string) ($failed['last_progress_at'] ?? 'not-empty'));
        }
        $this->assertFalse((bool) ($healthy['query_failed'] ?? true));
        $this->assertFalse((bool) ($healthy['words_query_failed'] ?? true));
        $this->assertSame(1, (int) ($healthy['tracked_words'] ?? 0));
        $this->assertSame(1, (int) ($healthy['studied_words'] ?? 0));

        $this->assertGreaterThanOrEqual(4, count($rawQueries));
        foreach ($rawQueries as $query) {
            $this->assertMatchesRegularExpression('/SELECT\s+progress\.word_id,/i', $query);
            $this->assertMatchesRegularExpression('/progress\.user_id\s*=\s*\d+/i', $query);
            $this->assertMatchesRegularExpression('/progress\.word_id\s*>\s*\d+/i', $query);
            $this->assertMatchesRegularExpression('/ORDER BY\s+progress\.word_id\s+ASC/i', $query);
            $this->assertMatchesRegularExpression('/LIMIT\s+2\s*$/i', trim($query));
            $this->assertStringNotContainsString('progress.user_id IN', $query);
        }
    }

    public function test_report_stats_queries_are_chunked_and_return_one_summary_per_requested_user(): void
    {
        $userIds = range(900001, 900205);
        $wordsTable = preg_quote(ll_tools_user_progress_table_names()['words'], '/');
        $queries = [];
        $captureQuery = static function (string $query) use (&$queries, $wordsTable): string {
            if (preg_match('/FROM\s+' . $wordsTable . '\s+progress\s+WHERE/i', $query)) {
                $queries[] = $query;
            }
            return $query;
        };

        add_filter('query', $captureQuery);
        try {
            $stats = ll_tools_user_progress_report_stats_for_users($userIds, 0);
        } finally {
            remove_filter('query', $captureQuery);
        }

        $this->assertCount(205, $stats);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/GROUP BY\s+progress\.user_id/i', $query);
            $this->assertMatchesRegularExpression('/progress\.user_id\s+IN\s*\(([^)]+)\)/i', $query);
            preg_match('/progress\.user_id\s+IN\s*\(([^)]+)\)/i', $query, $matches);
            $this->assertLessThanOrEqual(100, count(explode(',', (string) ($matches[1] ?? ''))));
        }
    }

    public function test_report_stats_query_failures_are_typed_and_displayed_as_unavailable(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Failed Stats Wordset');
        $this->insertProgressRow($learnerId, $wordsetId, ['total_coverage' => 1]);
        $wordsTable = preg_quote(ll_tools_user_progress_table_names()['words'], '/');
        $failWordsQuery = static function (string $query) use ($wordsTable, $wpdb): string {
            if (preg_match('/FROM\s+' . $wordsTable . '\s+progress\s+WHERE/i', $query)) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_progress_words";
            }
            return $query;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failWordsQuery);
        try {
            $stats = ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        } finally {
            remove_filter('query', $failWordsQuery);
            $wpdb->suppress_errors($previousSuppress);
        }

        $row = (array) ($stats[$learnerId] ?? []);
        $this->assertTrue((bool) ($row['query_failed'] ?? false));
        $this->assertTrue((bool) ($row['words_query_failed'] ?? false));
        $this->assertFalse((bool) ($row['events_query_failed'] ?? true));
        $display = ll_tools_user_progress_report_stat_display_data($row, 'studied_words');
        $this->assertTrue((bool) ($display['query_failed'] ?? false));
        $this->assertSame(__('Unavailable', 'll-tools-text-domain'), $display['label'] ?? null);
        $this->assertSame('', $display['sort_value'] ?? null);
        $this->assertSame('', ll_tools_user_progress_report_last_activity($row));
    }

    public function test_report_event_query_failures_are_typed_instead_of_zero_activity(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordsetId = $this->createWordset('Failed Events Wordset');
        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'failed_event_query_test', 'provider' => 'hosted_api'],
        ]));
        $eventsTable = preg_quote(ll_tools_user_progress_table_names()['events'], '/');
        $failEventsQuery = static function (string $query) use ($eventsTable, $wpdb): string {
            if (preg_match('/FROM\s+' . $eventsTable . '\s+WHERE/i', $query)) {
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_progress_events";
            }
            return $query;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failEventsQuery);
        try {
            $stats = ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        } finally {
            remove_filter('query', $failEventsQuery);
            $wpdb->suppress_errors($previousSuppress);
        }

        $row = (array) ($stats[$learnerId] ?? []);
        $this->assertTrue((bool) ($row['query_failed'] ?? false));
        $this->assertTrue((bool) ($row['events_query_failed'] ?? false));
        $this->assertFalse((bool) ($row['words_query_failed'] ?? true));
        $this->assertSame(__('Unavailable', 'll-tools-text-domain'), ll_tools_user_progress_report_stat_display_data($row, 'rounds_30d')['label']);
    }

    public function test_bot_risk_assessment_flags_spammy_profile_and_activity(): void
    {
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'user_login' => 'seo-bot-778899',
            'display_name' => 'SEO Bot 778899',
            'user_email' => 'backlinks@mailinator.com',
            'user_registered' => gmdate('Y-m-d H:i:s'),
        ]);
        $wordsetId = $this->createWordset('Bot Risk Wordset');

        $this->recordSttCalls($learnerId, $wordsetId, 40);

        $stats = ll_tools_user_progress_report_stats_for_users([$learnerId], $wordsetId);
        $risk = ll_tools_user_progress_report_assess_bot_risk(
            new WP_User($learnerId),
            (array) ($stats[$learnerId] ?? [])
        );

        $this->assertTrue((bool) ($risk['flagged'] ?? false));
        $this->assertSame('high', (string) ($risk['level'] ?? ''));
        $this->assertContains('Disposable email domain: mailinator.com', (array) ($risk['reasons'] ?? []));
        $this->assertContains('Speech-to-text usage is far higher than quiz outcomes.', (array) ($risk['reasons'] ?? []));
    }

    public function test_render_user_progress_report_page_shows_bot_risk_and_delete_button(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'display_name' => 'Spammy Learner',
            'user_login' => 'casino-bot-552211',
            'user_email' => 'promo@mailinator.com',
        ]);
        $wordsetId = $this->createWordset('Render Bot Risk Wordset');

        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));

        wp_set_current_user($adminId);
        $_GET = [
            'page' => ll_tools_get_user_progress_report_page_slug(),
        ];

        ob_start();
        try {
            ll_tools_render_user_progress_report_page();
            $output = (string) ob_get_clean();
        } finally {
            $_GET = $this->getBackup;
        }

        $this->assertStringContainsString('Bot Risk', $output);
        $this->assertStringContainsString('Review', $output);
        $this->assertStringContainsString('mailinator.com', $output);
        $this->assertStringContainsString('Delete User', $output);
    }

    public function test_delete_request_result_deletes_basic_learner_account(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create([
            'role' => 'subscriber',
            'display_name' => 'Delete Me Learner',
            'user_email' => 'delete-me@example.test',
        ]);
        $wordsetId = $this->createWordset('Delete Request Wordset');

        $this->assertTrue(ll_tools_record_server_progress_event($learnerId, [
            'event_type' => 'stt_api_call',
            'wordset_id' => $wordsetId,
            'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
        ]));

        wp_set_current_user($adminId);
        $result = ll_tools_user_progress_report_delete_request_result([
            'll_tools_user_id' => (string) $learnerId,
            'll_tools_delete_user_nonce' => wp_create_nonce('ll_tools_delete_progress_user_' . $learnerId),
            'll_tools_return_search' => 'delete',
            'll_tools_return_paged' => '2',
            'll_tools_return_user_id' => (string) $learnerId,
        ]);

        $this->assertSame('deleted', (string) ($result['notice'] ?? ''));
        $this->assertFalse(get_userdata($learnerId));
        $this->assertSame('delete', (string) (($result['redirect_args'] ?? [])['s'] ?? ''));
        $this->assertSame(2, (int) (($result['redirect_args'] ?? [])['paged'] ?? 0));
        $this->assertArrayNotHasKey('user_id', (array) ($result['redirect_args'] ?? []));
        $this->assertNotContains($learnerId, ll_tools_user_progress_report_tracked_user_ids(0));
    }

    public function test_delete_request_result_blocks_privileged_accounts(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $staffId = self::factory()->user->create(['role' => 'author']);
        $staffUser = get_userdata($staffId);

        $this->assertInstanceOf(WP_User::class, $staffUser);
        $staffUser->add_cap('view_ll_tools');
        clean_user_cache($staffId);

        wp_set_current_user($adminId);
        $result = ll_tools_user_progress_report_delete_request_result([
            'll_tools_user_id' => (string) $staffId,
            'll_tools_delete_user_nonce' => wp_create_nonce('ll_tools_delete_progress_user_' . $staffId),
        ]);

        $this->assertSame('delete-privileged', (string) ($result['notice'] ?? ''));
        $this->assertInstanceOf(WP_User::class, get_userdata($staffId));
    }

    public function test_teacher_progress_consumers_expose_query_failures_instead_of_zeroes(): void
    {
        $healthyRow = [
            'stats' => [
                'rounds_30d' => 4,
                'studied_words' => 7,
                'mastered_words' => 3,
                'hard_words' => 2,
                'query_failed' => false,
            ],
            'last_activity' => '2026-08-31 12:00:00',
        ];
        $failedRow = [
            'stats' => [
                'rounds_30d' => 0,
                'studied_words' => 0,
                'mastered_words' => 0,
                'hard_words' => 0,
                'query_failed' => true,
            ],
            'stats_query_failed' => true,
            'last_activity' => '',
        ];

        $summary = ll_tools_teacher_class_progress_summary([$healthyRow, $failedRow]);
        $this->assertSame(2, $summary['students']);
        $this->assertSame(1, $summary['stats_available_students']);
        $this->assertSame(1, $summary['stats_unavailable_students']);
        $this->assertTrue($summary['query_failed']);
        $this->assertSame(4, $summary['rounds_30d']);
        $this->assertSame(7, $summary['studied_words']);
        $this->assertSame(3, $summary['mastered_words']);
        $this->assertSame(2, $summary['hard_words']);

        $display = ll_tools_teacher_class_progress_stat_display_data($failedRow, 'studied_words');
        $this->assertTrue($display['query_failed']);
        $this->assertSame(__('Unavailable', 'll-tools-text-domain'), $display['label']);
        $this->assertSame('', $display['sort_value']);

        ob_start();
        ll_tools_teacher_class_render_frontend_progress_cells($failedRow);
        $html = (string) ob_get_clean();
        $this->assertSame(5, substr_count($html, 'data-sort-value=""'));
        $this->assertSame(5, substr_count($html, '>Unavailable</span>'));
        $this->assertSame(5, substr_count($html, 'aria-label="Unavailable"'));
    }

    private function insertProgressRow(int $userId, int $wordsetId, array $overrides = []): int
    {
        global $wpdb;

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Report aggregate word ' . wp_generate_password(6, false),
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $row = array_merge([
            'user_id' => $userId,
            'word_id' => $wordId,
            'wordset_id' => $wordsetId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace(ll_tools_user_progress_table_names()['words'], $row);
        $this->assertNotFalse($inserted);

        return $wordId;
    }

    private function createWordset(string $label): int
    {
        $wordset = wp_insert_term($label . ' ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        return (int) $wordset['term_id'];
    }

    private function recordSttCalls(int $userId, int $wordsetId, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->assertTrue(ll_tools_record_server_progress_event($userId, [
                'event_type' => 'stt_api_call',
                'wordset_id' => $wordsetId,
                'payload' => ['source' => 'wordset_speaking_game', 'provider' => 'hosted_api'],
            ]));
        }
    }
}
