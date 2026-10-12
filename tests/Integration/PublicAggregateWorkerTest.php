<?php
declare(strict_types=1);

final class PublicAggregateWorkerTest extends LL_Tools_TestCase
{
    protected function setUp(): void {
        parent::setUp();
        update_option('cron', []);
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
        wp_set_current_user(0);
    }

    protected function tearDown(): void {
        update_option('cron', []);
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function emptyCountEvent(int $timestamp): array {
        $term = wp_insert_term('Aggregate worker ' . wp_generate_uuid4(), 'wordset');
        $this->assertIsArray($term);
        $args = [[(int) $term['term_id']], 0];
        update_option('cron', []);
        wp_schedule_single_event($timestamp, 'll_tools_refresh_wordset_button_lesson_counts', $args);
        return $args;
    }

    public function test_worker_ignores_unrelated_and_recurring_hooks_and_never_calls_general_actions(): void {
        $called = false;
        $trap = static function () use (&$called): void { $called = true; };
        add_action('ll_worker_test_email', $trap);
        add_action('ll_tools_refresh_wordset_button_lesson_counts', $trap);
        $args = $this->emptyCountEvent(time() - 5);
        wp_schedule_single_event(time() - 20, 'll_worker_test_email', ['recipient']);
        wp_schedule_event(time() - 15, 'hourly', LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        try {
            $result = ll_tools_run_public_aggregate_worker();
        } finally {
            remove_action('ll_worker_test_email', $trap);
            remove_action('ll_tools_refresh_wordset_button_lesson_counts', $trap);
        }
        $this->assertFalse($called);
        $this->assertSame(1, $result['batches']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
        $this->assertNotFalse(wp_next_scheduled('ll_worker_test_email', ['recipient']));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
    }

    public function test_failed_checkpoint_retains_event_even_when_completed_callback_attempts_to_clear_it(): void {
        global $wpdb;
        $timestamp = time() - 10;
        $args = $this->emptyCountEvent($timestamp);
        $fail = static function (string $sql) use ($wpdb): string {
            return str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value")
                && str_contains($sql, "WHERE option_name = 'cron'")
                ? "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0" : $sql;
        };
        add_filter('query', $fail);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $fail); }
        $this->assertSame('cron_checkpoint_failed', $result['errors'][0]['code']);
        $this->assertSame($timestamp, wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
        $this->assertSame([], ll_tools_run_public_aggregate_worker()['errors']);
        $this->assertFalse(wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
    }

    public function test_callback_exception_keeps_exact_scoped_args_retryable(): void {
        $args = $this->emptyCountEvent(time() - 10);
        $throw = static function (): int { throw new RuntimeException('test callback interrupted'); };
        add_filter('ll_tools_quiz_min_words', $throw);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('ll_tools_quiz_min_words', $throw); }
        $this->assertSame(1, $result['batches']);
        $this->assertSame('batch_exception', $result['errors'][0]['code']);
        $this->assertGreaterThanOrEqual(time() + 25, wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
    }

    public function test_checkpoint_preserves_racing_unrelated_cron_write(): void {
        global $wpdb;
        $args = $this->emptyCountEvent(time() - 10);
        $raced = false;
        $race = static function (string $sql) use ($wpdb, &$raced): string {
            if (!$raced && str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value")
                && str_contains($sql, "WHERE option_name = 'cron'")) {
                $raced = true;
                wp_schedule_single_event(time() + 90, 'll_worker_test_concurrent');
            }
            return $sql;
        };
        add_filter('query', $race);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $race); }
        $this->assertTrue($raced);
        $this->assertSame([], $result['errors']);
        $this->assertNotFalse(wp_next_scheduled('ll_worker_test_concurrent'));
        $this->assertFalse(wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
    }

    public function test_failed_dictionary_source_read_keeps_current_work_with_backoff(): void {
        global $wpdb;
        $this->assertTrue(ll_tools_install_dictionary_lookup_schema());
        ll_tools_update_dictionary_lookup_rebuild_state([
            'status' => 'running', 'last_id' => 0, 'processed' => 0,
            'started_at' => current_time('mysql'), 'truncate_pending' => 0,
            'browse_version' => LL_TOOLS_DICTIONARY_BROWSE_LOOKUP_VERSION,
        ]);
        update_option('cron', []);
        wp_schedule_single_event(time() - 10, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        $fail = static function (string $sql): string {
            return str_contains($sql, "WHERE post_type = 'll_dictionary_entry'") && str_contains($sql, 'ID >')
                ? 'SELECT ID FROM missing_worker_dictionary_source' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($previous); }
        $this->assertSame(1, $result['batches']);
        $this->assertSame('dictionary_rebuild_not_advanced', $result['errors'][0]['code']);
        $this->assertSame('running', ll_tools_get_dictionary_lookup_rebuild_state()['status']);
        $this->assertGreaterThanOrEqual(time() + 25, wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
    }

    public function test_failed_recorder_scope_read_keeps_exact_original_arguments(): void {
        global $wpdb;
        $args = [987654321, 987654321, 'isolation', '', 'captured-generation', 12, ['selected-category']];
        wp_schedule_single_event(time() - 10, 'll_tools_wordset_page_warm_recorder_queue_summaries', $args);
        $fail = static function (string $sql): string {
            return str_contains($sql, 'AS wordset_exists') ? 'SELECT * FROM missing_worker_scope' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($previous); }
        $this->assertSame(1, $result['batches']);
        $this->assertSame('recorder_scope_read_failed', $result['errors'][0]['code']);
        $this->assertGreaterThanOrEqual(time() + 25, wp_next_scheduled('ll_tools_wordset_page_warm_recorder_queue_summaries', $args));
    }

    public function test_proven_deleted_recorder_scope_is_retired(): void {
        $args = [987654321, 987654321];
        wp_schedule_single_event(time() - 10, 'll_tools_wordset_page_warm_recorder_queue_summaries', $args);
        $result = ll_tools_run_public_aggregate_worker();
        $this->assertSame([], $result['errors']);
        $this->assertFalse(wp_next_scheduled('ll_tools_wordset_page_warm_recorder_queue_summaries', $args));
    }

    public function test_failed_user_scope_read_does_not_retire_count_job(): void {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $args = $this->emptyCountEvent(time() - 10);
        wp_clear_scheduled_hook('ll_tools_refresh_wordset_button_lesson_counts', $args);
        $args[1] = $user;
        wp_schedule_single_event(time() - 10, 'll_tools_refresh_wordset_button_lesson_counts', $args);
        clean_user_cache($user);
        $fail = static function (string $sql) use ($wpdb): string {
            return str_contains($sql, "FROM {$wpdb->users}") && str_contains($sql, 'WHERE ID')
                ? 'SELECT * FROM missing_worker_user' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($previous); }
        $this->assertSame('user_scope_read_failed', $result['errors'][0]['code']);
        $this->assertGreaterThanOrEqual(time() + 25, wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
    }

    public function test_held_dictionary_lease_preserves_job_without_running_it(): void {
        wp_schedule_single_event(time() - 10, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        set_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY, 1, 60);
        $timestamp = wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        $result = ll_tools_run_public_aggregate_worker();
        $this->assertSame(0, $result['batches']);
        $this->assertSame([], $result['errors']);
        $this->assertSame($timestamp, wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
    }

    public function test_worker_gives_count_family_a_turn_before_dictionary_continuation(): void {
        $this->assertTrue(ll_tools_install_dictionary_lookup_schema());
        for ($index = 0; $index < 26; $index++) {
            self::factory()->post->create([
                'post_type' => 'll_dictionary_entry', 'post_status' => 'publish',
                'post_title' => 'Worker dictionary ' . $index,
            ]);
        }
        ll_tools_update_dictionary_lookup_rebuild_state([
            'status' => 'running', 'last_id' => 0, 'processed' => 0,
            'started_at' => current_time('mysql'), 'truncate_pending' => 0,
            'browse_version' => LL_TOOLS_DICTIONARY_BROWSE_LOOKUP_VERSION,
        ]);
        $args = $this->emptyCountEvent(time() + 2);
        wp_schedule_single_event(time() - 10, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        $small_batch = static fn(): int => 25;
        add_filter('ll_tools_dictionary_lookup_rebuild_batch_size', $small_batch);
        try { $result = ll_tools_run_public_aggregate_worker(8, 2); }
        finally { remove_filter('ll_tools_dictionary_lookup_rebuild_batch_size', $small_batch); }
        $this->assertSame(2, $result['batches']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('running', ll_tools_get_dictionary_lookup_rebuild_state()['status']);
        $this->assertFalse(wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
    }

    public function test_failed_dictionary_checkpoint_read_retains_exact_pending_event(): void {
        global $wpdb;
        $this->assertTrue(ll_tools_install_dictionary_lookup_schema());
        ll_tools_update_dictionary_lookup_rebuild_state([
            'status' => 'completed', 'truncate_pending' => 0,
            'browse_version' => LL_TOOLS_DICTIONARY_BROWSE_LOOKUP_VERSION,
        ]);
        update_option('cron', []);
        wp_schedule_single_event(time() - 10, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        $fail = static function (string $sql): string {
            return str_contains($sql, 'SELECT option_value')
                && str_contains($sql, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION)
                ? 'SELECT * FROM missing_worker_state' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { $result = ll_tools_run_public_aggregate_worker(); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($previous); }
        $this->assertSame('dictionary_state_read_failed', $result['errors'][0]['code']);
        $this->assertGreaterThanOrEqual(time() + 25, wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
    }

    public function test_worker_clamps_batch_budget_and_restores_scoped_user(): void {
        $previous = self::factory()->user->create(['role' => 'administrator']);
        $args = $this->emptyCountEvent(time() - 10);
        wp_schedule_single_event(time() - 5, 'll_tools_wordset_page_warm_recorder_queue_summaries', [999999, 999999]);
        wp_set_current_user($previous);
        $result = ll_tools_run_public_aggregate_worker(999, 0);
        $this->assertSame(1, $result['batches']);
        $this->assertTrue($result['budget_exhausted']);
        $this->assertSame($previous, get_current_user_id());
        $this->assertFalse(wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $args));
    }

    public function test_recorder_uses_stored_public_locale_and_restores_cli_locale(): void {
        $set = wp_insert_term('Locale worker ' . wp_generate_uuid4(), 'wordset');
        $this->assertIsArray($set);
        $recorder = self::factory()->user->create(['role' => 'subscriber']);
        update_user_meta($recorder, LL_TOOLS_PUBLIC_LOCALE_META, 'tr_TR');
        $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
        wp_set_object_terms($word, [(int) $set['term_id']], 'wordset');
        $locales = [];
        $probe = static function ($translation, $text, $domain) use (&$locales) {
            if ($domain === 'll-tools-text-domain' && $text === 'Uncategorized') { $locales[] = get_locale(); }
            return $translation;
        };
        $previous = get_locale();
        update_option('cron', []);
        wp_schedule_single_event(time() - 10, 'll_tools_wordset_page_warm_recorder_queue_summaries', [(int) $set['term_id'], $recorder]);
        add_filter('gettext', $probe, 10, 3);
        try { $result = ll_tools_run_public_aggregate_worker(8, 1); }
        finally { remove_filter('gettext', $probe, 10); }
        $this->assertSame([], $result['errors']);
        $this->assertContains('tr_TR', $locales);
        $this->assertSame($previous, get_locale());
    }
}
