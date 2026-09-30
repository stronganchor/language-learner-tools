<?php
declare(strict_types=1);

final class PrivacyCleanupHealthTest extends LL_Tools_TestCase
{
    private array $queuedUsers = [];

    public function setUp(): void
    {
        parent::setUp();
        foreach (ll_tools_privacy_deleted_user_lms_cleanup_queue() as $userId => $queuedAt) {
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup((int) $userId);
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [(int) $userId]);
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void
    {
        foreach ($this->queuedUsers as $userId) {
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId);
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function queue(int $userId, array $fields = []): void
    {
        $this->assertTrue(add_option(
            ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId),
            array_merge(['queued_at' => time(), 'post_seen_at' => time()], $fields),
            '',
            false
        ));
        $this->queuedUsers[] = $userId;
    }

    public function test_site_health_is_administrator_only_and_never_exposes_job_identities(): void
    {
        $this->queue(900001, ['blocked_reason' => 'll_tools_privacy_transactional_engine_unavailable', 'blocked_details' => ['wp_postmeta: MyISAM']]);
        $tests = apply_filters('site_status_tests', []);
        $this->assertSame('ll_tools_privacy_account_cleanup_site_health_test', $tests['direct']['ll_tools_account_cleanup']['test']);

        $summary = ll_tools_privacy_deleted_user_lms_cleanup_health_summary();
        $this->assertSame(1, $summary['blocked_count']);
        $this->assertStringNotContainsString('900001', wp_json_encode($summary));

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertArrayNotHasKey('ll_tools_account_cleanup', apply_filters('site_status_tests', [])['direct'] ?? []);
        $result = ll_tools_privacy_account_cleanup_site_health_test();
        $this->assertSame('', $result['description']);
        $this->assertStringNotContainsString('wp_postmeta', wp_json_encode($result));
    }

    public function test_blocked_tables_are_actionable_and_html_escaped(): void
    {
        $this->queue(900002, [
            'blocked_reason' => 'll_tools_privacy_transactional_engine_unavailable',
            'blocked_details' => ['wp_postmeta: MyISAM <script>unsafe</script>'],
            'next_attempt_at' => time() + 900,
        ]);
        $result = ll_tools_privacy_account_cleanup_site_health_test();
        $this->assertSame('critical', $result['status']);
        $this->assertStringContainsString('wp_postmeta: MyISAM', $result['description']);
        $this->assertStringContainsString('InnoDB', $result['description']);
        $this->assertStringNotContainsString('<script>', $result['description']);
        $this->assertStringContainsString('&lt;script&gt;', $result['description']);
    }

    public function test_summary_distinguishes_due_and_deferred_jobs(): void
    {
        $future = time() + 1800;
        $this->queue(900003, ['next_attempt_at' => $future]);
        $this->queue(900004, ['next_attempt_at' => time() - 1]);
        $summary = ll_tools_privacy_deleted_user_lms_cleanup_health_summary();
        $this->assertFalse($summary['read_error']);
        $this->assertSame(2, $summary['pending_count']);
        $this->assertSame(1, $summary['due_count']);
        $this->assertSame(1, $summary['deferred_count']);
        $this->assertSame($future, $summary['earliest_next_attempt_at']);
        $this->assertSame('recommended', ll_tools_privacy_account_cleanup_site_health_test()['status']);
    }

    public function test_summary_limits_work_to_fifty_jobs_and_reports_overflow(): void
    {
        for ($index = 0; $index < 51; $index++) {
            $this->queue(901000 + $index);
        }
        $summary = ll_tools_privacy_deleted_user_lms_cleanup_health_summary();
        $this->assertSame(50, $summary['pending_count']);
        $this->assertTrue($summary['overflow']);
        $this->assertSame('recommended', ll_tools_privacy_account_cleanup_site_health_test()['status']);
    }

    public function test_failed_queue_read_is_not_reported_as_successful_cleanup(): void
    {
        global $wpdb;
        $fault = static function (string $sql): string {
            if (strpos($sql, 'SELECT option_id, option_name, option_value') !== false) {
                return 'SELECT ll_tools_missing_cleanup_column FROM ' . $GLOBALS['wpdb']->options;
            }
            return $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fault);
        try {
            $summary = ll_tools_privacy_deleted_user_lms_cleanup_health_summary();
            $result = ll_tools_privacy_account_cleanup_site_health_test();
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($previous);
        }
        $this->assertTrue($summary['read_error']);
        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('could not be checked', $result['label']);
    }

    public function test_empty_queue_reports_success(): void
    {
        $this->assertSame(0, ll_tools_privacy_deleted_user_lms_cleanup_health_summary()['pending_count']);
        $this->assertSame('good', ll_tools_privacy_account_cleanup_site_health_test()['status']);
    }
}
