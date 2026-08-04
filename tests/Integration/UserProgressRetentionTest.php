<?php
declare(strict_types=1);

final class UserProgressRetentionTest extends LL_Tools_TestCase
{
    /** @var array<int,int> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(ll_tools_install_user_progress_schema());
        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if (!empty($this->eventIds)) {
            $placeholders = implode(', ', array_fill(0, count($this->eventIds), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . ll_tools_user_progress_table_names()['events'] . " WHERE id IN ({$placeholders})",
                    ...$this->eventIds
                )
            );
        }

        remove_all_filters('ll_tools_user_progress_retention_batch_limit');
        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);
        parent::tearDown();
    }

    public function test_retention_cleanup_scans_a_bounded_primary_key_batch_and_continues(): void
    {
        $expired = gmdate('Y-m-d H:i:s', time() - (400 * DAY_IN_SECONDS));
        $recent = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $firstExpiredId = $this->insertEvent($expired);
        $recentId = $this->insertEvent($recent);
        $secondExpiredId = $this->insertEvent($expired);
        $thirdExpiredId = $this->insertEvent($expired);
        $fourthExpiredId = $this->insertEvent($expired);

        add_filter('ll_tools_user_progress_retention_batch_limit', static fn (): int => 2);

        $this->assertSame(1, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($firstExpiredId));
        $this->assertTrue($this->eventExists($recentId));
        $this->assertTrue($this->eventExists($secondExpiredId));
        $this->assertSame($recentId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 0));
        $this->assertSame($fourthExpiredId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK));

        $this->assertSame(2, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($secondExpiredId));
        $this->assertFalse($this->eventExists($thirdExpiredId));
        $this->assertTrue($this->eventExists($fourthExpiredId));
        $this->assertSame($thirdExpiredId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 0));
        $this->assertSame($fourthExpiredId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));

        $this->assertSame(1, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($fourthExpiredId));
        $this->assertTrue($this->eventExists($recentId));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));
    }

    public function test_fixed_generation_revisits_aged_rows_despite_continuous_inserts(): void
    {
        global $wpdb;

        $expired = gmdate('Y-m-d H:i:s', time() - (400 * DAY_IN_SECONDS));
        $recent = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $firstExpiredId = $this->insertEvent($expired);
        $agingId = $this->insertEvent($recent);
        $thirdExpiredId = $this->insertEvent($expired);
        $passCeilingId = $this->insertEvent($expired);

        add_filter('ll_tools_user_progress_retention_batch_limit', static fn (): int => 2);

        $this->assertSame(1, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($firstExpiredId));
        $this->assertTrue($this->eventExists($agingId));
        $this->assertSame($agingId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 0));
        $this->assertSame($passCeilingId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));

        $updated = $wpdb->update(
            ll_tools_user_progress_table_names()['events'],
            ['created_at' => $expired],
            ['id' => $agingId],
            ['%s'],
            ['%d']
        );
        $this->assertSame(1, $updated);

        $appendedRecentId = $this->insertEvent($recent);
        $secondAppendedRecentId = $this->insertEvent($recent);

        $this->assertSame(2, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($thirdExpiredId));
        $this->assertFalse($this->eventExists($passCeilingId));
        $this->assertTrue($this->eventExists($appendedRecentId));
        $this->assertTrue($this->eventExists($secondAppendedRecentId));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));

        // The next fixed pass starts from zero, so the row that aged behind the
        // former cursor is revisited even though newer IDs were appended.
        $this->assertSame(1, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($agingId));
        $this->assertTrue($this->eventExists($appendedRecentId));
        $this->assertTrue($this->eventExists($secondAppendedRecentId));
    }

    public function test_retention_cleanup_does_not_advance_after_a_database_read_error(): void
    {
        global $wpdb;

        $expired = gmdate('Y-m-d H:i:s', time() - (400 * DAY_IN_SECONDS));
        $eventId = $this->insertEvent($expired);
        $eventsTable = ll_tools_user_progress_table_names()['events'];

        $failRead = static function (string $query) use ($eventsTable): string {
            if (stripos($query, "SELECT id FROM {$eventsTable}") !== false) {
                return 'SELECT id FROM ll_tools_missing_retention_events_table';
            }

            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failRead);
        try {
            $this->assertSame(0, ll_tools_run_user_progress_retention_cleanup());
        } finally {
            remove_filter('query', $failRead);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        $this->assertTrue($this->eventExists($eventId));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertSame($eventId, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK));
    }

    public function test_retention_cleanup_does_not_publish_a_ceiling_after_a_ceiling_read_error(): void
    {
        global $wpdb;

        $expired = gmdate('Y-m-d H:i:s', time() - (400 * DAY_IN_SECONDS));
        $eventId = $this->insertEvent($expired);
        $eventsTable = ll_tools_user_progress_table_names()['events'];

        $failCeilingRead = static function (string $query) use ($eventsTable): string {
            if (stripos($query, "SELECT MAX(id) FROM {$eventsTable}") !== false) {
                return 'SELECT MAX(id) FROM ll_tools_missing_retention_events_table';
            }

            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failCeilingRead);
        try {
            $this->assertSame(0, ll_tools_run_user_progress_retention_cleanup());
        } finally {
            remove_filter('query', $failCeilingRead);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        $this->assertTrue($this->eventExists($eventId));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK));
    }

    public function test_retention_cleanup_fails_closed_when_another_runner_owns_the_lock(): void
    {
        $expired = gmdate('Y-m-d H:i:s', time() - (400 * DAY_IN_SECONDS));
        $eventId = $this->insertEvent($expired);

        $denyLock = static function (string $query): string {
            if (stripos($query, 'SELECT GET_LOCK(') !== false) {
                return 'SELECT 0';
            }

            return $query;
        };

        add_filter('query', $denyLock);
        try {
            $this->assertSame(0, ll_tools_run_user_progress_retention_cleanup());
        } finally {
            remove_filter('query', $denyLock);
        }

        $this->assertTrue($this->eventExists($eventId));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));

        // The same batch succeeds after the competing owner is gone.
        $this->assertSame(1, ll_tools_run_user_progress_retention_cleanup());
        $this->assertFalse($this->eventExists($eventId));
    }

    public function test_retention_lock_name_is_database_and_site_scoped_without_leaking_names(): void
    {
        global $wpdb;

        $databaseName = defined('DB_NAME') ? (string) DB_NAME : '';
        $expected = 'll_tools_retention_' . substr(
            hash('sha256', $databaseName . '|' . (string) $wpdb->options),
            0,
            32
        );
        $lockName = ll_tools_user_progress_retention_lock_name();

        $this->assertSame($expected, $lockName);
        $this->assertLessThanOrEqual(64, strlen($lockName));
        $this->assertMatchesRegularExpression('/^ll_tools_retention_[a-f0-9]{32}$/', $lockName);
    }

    public function test_retention_schedule_cleanup_clears_generation_state(): void
    {
        update_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 12, false);
        update_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 34, false);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);

        ll_tools_clear_user_progress_retention_schedule();

        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK));
    }

    public function test_retention_batch_limit_has_a_hard_cap(): void
    {
        add_filter('ll_tools_user_progress_retention_batch_limit', static fn (): int => 0);
        $this->assertSame(1, ll_tools_user_progress_retention_batch_limit());
        remove_all_filters('ll_tools_user_progress_retention_batch_limit');

        add_filter('ll_tools_user_progress_retention_batch_limit', static fn (): int => PHP_INT_MAX);
        $this->assertSame(
            LL_TOOLS_USER_PROGRESS_RETENTION_HARD_BATCH_LIMIT,
            ll_tools_user_progress_retention_batch_limit()
        );
    }

    private function insertEvent(string $createdAt): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            ll_tools_user_progress_table_names()['events'],
            [
                'user_id' => 1,
                'event_uuid' => 'retention-' . wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'created_at' => $createdAt,
            ],
            ['%d', '%s', '%s', '%s']
        );
        $this->assertSame(1, $inserted);

        $eventId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $eventId);
        $this->eventIds[] = $eventId;
        return $eventId;
    }

    private function eventExists(int $eventId): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_user_progress_table_names()['events'] . ' WHERE id = %d',
                $eventId
            )
        ) === 1;
    }
}
