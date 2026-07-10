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
    }

    public function test_report_stats_query_selects_only_required_progress_columns(): void
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
            return (bool) preg_match('/FROM\s+' . $wordsTable . '\s+WHERE/i', $query);
        }));
        $this->assertNotEmpty($progressQueries);
        $this->assertStringNotContainsString('SELECT *', strtoupper($progressQueries[0]));
        $this->assertStringContainsString('practice_required_recording_types', $progressQueries[0]);
        $this->assertSame($wpdb->prefix . 'll_tools_user_word_progress', ll_tools_user_progress_table_names()['words']);
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
