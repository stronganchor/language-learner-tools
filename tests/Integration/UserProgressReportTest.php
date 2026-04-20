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

    private function createWordset(string $label): int
    {
        $wordset = wp_insert_term($label . ' ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        return (int) $wordset['term_id'];
    }
}
