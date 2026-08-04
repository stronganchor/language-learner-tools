<?php
declare(strict_types=1);

final class UserProgressAtomicityTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(ll_tools_install_user_progress_schema());
        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        $this->assertTrue((bool) ll_tools_user_progress_core_engine_status(true)['ready']);
    }

    protected function tearDown(): void
    {
        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        parent::tearDown();
    }

    public function test_event_transaction_starts_a_real_transaction_without_an_outer_owner(): void
    {
        global $wpdb;

        $original_wpdb = $wpdb;
        $isolated_wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $wpdb = $isolated_wpdb;
        try {
            $transaction = ll_tools_user_progress_begin_event_transaction();
            $this->assertIsArray($transaction);
            $this->assertSame('transaction', $transaction['type']);
            ll_tools_user_progress_rollback_event_transaction($transaction, 0);
        } finally {
            $wpdb = $original_wpdb;
        }
    }

    public function test_word_progress_select_error_rolls_back_ledger_and_retry_applies_once(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $word_id = $this->createWord();
        $initial_uuid = 'atomic-select-seed-' . wp_generate_uuid4();
        $retry_uuid = 'atomic-select-retry-' . wp_generate_uuid4();

        $this->assertSame(1, ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($initial_uuid, $word_id)]
        )['processed']);
        $this->assertSame(1, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);

        $words_table = ll_tools_user_progress_table_names()['words'];
        $select_fault = static function (string $query) use ($words_table): string {
            if (
                stripos($query, "FROM {$words_table}") !== false
                && stripos($query, 'FOR UPDATE') !== false
            ) {
                return 'SELECT * FROM ll_tools_missing_progress_select_table';
            }
            return $query;
        };

        add_filter('query', $select_fault);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $failed = ll_tools_process_progress_events_batch(
                $user_id,
                [$this->exposureEvent($retry_uuid, $word_id)]
            );
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $select_fault);
        }

        $this->assertSame(1, $failed['failed']);
        $this->assertSame([$retry_uuid], $failed['failed_event_uuids']);
        $this->assertSame(1, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
        $this->assertSame(0, $this->ledgerCount($retry_uuid));

        $retried = ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($retry_uuid, $word_id)]
        );
        $this->assertSame(1, $retried['processed']);
        $this->assertSame([], $retried['failed_event_uuids']);
        $this->assertSame(2, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
        $this->assertSame(1, $this->ledgerCount($retry_uuid));

        $duplicate = ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($retry_uuid, $word_id)]
        );
        $this->assertSame(1, $duplicate['duplicates']);
        $this->assertSame([], $duplicate['failed_event_uuids']);
        $this->assertSame(2, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
    }

    public function test_word_progress_write_error_rolls_back_ledger_and_retry_applies_once(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $word_id = $this->createWord();
        $initial_uuid = 'atomic-write-seed-' . wp_generate_uuid4();
        $retry_uuid = 'atomic-write-retry-' . wp_generate_uuid4();

        $this->assertSame(1, ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($initial_uuid, $word_id)]
        )['processed']);

        $words_table = ll_tools_user_progress_table_names()['words'];
        $update_fault = static function (string $query) use ($words_table): string {
            if (stripos($query, "UPDATE `{$words_table}` SET") !== false) {
                return 'UPDATE ll_tools_missing_progress_update_table SET broken = 1';
            }
            return $query;
        };

        add_filter('query', $update_fault);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $failed = ll_tools_process_progress_events_batch(
                $user_id,
                [$this->exposureEvent($retry_uuid, $word_id)]
            );
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $update_fault);
        }

        $this->assertSame(1, $failed['failed']);
        $this->assertSame([$retry_uuid], $failed['failed_event_uuids']);
        $this->assertSame(0, $this->ledgerCount($retry_uuid));
        $this->assertSame(1, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);

        $retried = ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($retry_uuid, $word_id)]
        );
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(2, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);

        $duplicate = ll_tools_process_progress_events_batch(
            $user_id,
            [$this->exposureEvent($retry_uuid, $word_id)]
        );
        $this->assertSame(1, $duplicate['duplicates']);
        $this->assertSame(2, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
    }

    public function test_category_meta_write_error_rolls_back_cached_state_and_ledger(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_id = 987654;
        $retry_uuid = 'atomic-meta-retry-' . wp_generate_uuid4();
        $event = [
            'event_uuid' => $retry_uuid,
            'event_type' => 'category_study',
            'mode' => 'listening',
            'category_id' => $category_id,
            'payload' => ['units' => 2],
        ];

        $meta_fault = static function (bool $allowed, int $candidate_user_id, string $meta_key) use ($user_id): bool {
            if ($candidate_user_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                return false;
            }
            return $allowed;
        };

        add_filter('ll_tools_user_progress_meta_write_allowed', $meta_fault, 10, 3);
        try {
            $failed = ll_tools_process_progress_events_batch($user_id, [$event]);
        } finally {
            remove_filter('ll_tools_user_progress_meta_write_allowed', $meta_fault, 10);
        }

        $this->assertSame(1, $failed['failed']);
        $this->assertSame([$retry_uuid], $failed['failed_event_uuids']);
        $this->assertSame(0, $this->ledgerCount($retry_uuid));
        $this->assertSame([], ll_tools_get_user_category_progress($user_id));

        $retried = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame([], $retried['failed_event_uuids']);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(2, (int) $progress[$category_id]['exposure_total']);

        $duplicate = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, $duplicate['duplicates']);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(2, (int) $progress[$category_id]['exposure_total']);
    }

    public function test_prompt_meta_failure_rolls_back_word_progress_and_ledger_together(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $word_id = $this->createWord();
        $prompt_card_id = (int) self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Atomic prompt card',
        ]);
        $retry_uuid = 'atomic-prompt-retry-' . wp_generate_uuid4();
        $event = $this->exposureEvent($retry_uuid, $word_id);
        $event['payload'] = ['prompt_card_id' => $prompt_card_id];

        $meta_fault = static function (bool $allowed, int $candidate_user_id, string $meta_key) use ($user_id): bool {
            if ($candidate_user_id === $user_id && $meta_key === LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META) {
                return false;
            }
            return $allowed;
        };

        add_filter('ll_tools_user_progress_meta_write_allowed', $meta_fault, 10, 3);
        try {
            $failed = ll_tools_process_progress_events_batch($user_id, [$event]);
        } finally {
            remove_filter('ll_tools_user_progress_meta_write_allowed', $meta_fault, 10);
        }

        $this->assertSame(1, $failed['failed']);
        $this->assertSame([$retry_uuid], $failed['failed_event_uuids']);
        $this->assertSame(0, $this->ledgerCount($retry_uuid));
        $this->assertNull($this->findWordRow($user_id, $word_id));
        $this->assertSame([], ll_tools_get_user_prompt_card_progress($user_id));

        $retried = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(1, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
        $prompt_progress = ll_tools_get_user_prompt_card_progress($user_id);
        $this->assertSame(1, (int) $prompt_progress[$prompt_card_id]['exposure_total']);

        $duplicate = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, $duplicate['duplicates']);
        $this->assertSame(1, (int) $this->readWordRow($user_id, $word_id)['total_coverage']);
        $prompt_progress = ll_tools_get_user_prompt_card_progress($user_id);
        $this->assertSame(1, (int) $prompt_progress[$prompt_card_id]['exposure_total']);
    }

    public function test_mode_session_rolls_back_all_categories_when_its_single_meta_write_fails(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_ids = [765431, 765432];
        $retry_uuid = 'atomic-session-retry-' . wp_generate_uuid4();
        $event = [
            'event_uuid' => $retry_uuid,
            'event_type' => 'mode_session_complete',
            'mode' => 'learning',
            'payload' => ['category_ids' => $category_ids],
        ];
        $category_write_count = 0;
        $category_write_fault = static function (bool $allowed, int $candidate_user_id, string $meta_key) use ($user_id, &$category_write_count): bool {
            if ($candidate_user_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                $category_write_count++;
                return false;
            }
            return $allowed;
        };

        add_filter('ll_tools_user_progress_meta_write_allowed', $category_write_fault, 10, 3);
        try {
            $failed = ll_tools_process_progress_events_batch($user_id, [$event]);
        } finally {
            remove_filter('ll_tools_user_progress_meta_write_allowed', $category_write_fault, 10);
        }

        $this->assertSame(1, $failed['failed']);
        $this->assertSame([$retry_uuid], $failed['failed_event_uuids']);
        $this->assertSame(1, $category_write_count);
        $this->assertSame(0, $this->ledgerCount($retry_uuid));
        $this->assertSame([], ll_tools_get_user_category_progress($user_id));

        $retried = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, $retried['processed']);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(1, (int) $progress[$category_ids[0]]['exposure_total']);
        $this->assertSame(1, (int) $progress[$category_ids[1]]['exposure_total']);
    }

    public function test_mode_session_deduplicates_and_caps_categories_with_one_meta_write(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_ids = [765441, 765441, 0, -1, 765442, 765443, 765444];
        $event_uuid = 'atomic-session-bounded-' . wp_generate_uuid4();
        $category_write_count = 0;
        $category_limit = static function (): int {
            return 3;
        };
        $count_category_writes = static function (bool $allowed, int $candidate_user_id, string $meta_key) use ($user_id, &$category_write_count): bool {
            if ($candidate_user_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                $category_write_count++;
            }
            return $allowed;
        };

        add_filter('ll_tools_user_progress_session_category_limit', $category_limit);
        add_filter('ll_tools_user_progress_meta_write_allowed', $count_category_writes, 10, 3);
        try {
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => $event_uuid,
                'event_type' => 'mode_session_complete',
                'mode' => 'learning',
                'payload' => ['category_ids' => $category_ids],
            ]]);
        } finally {
            remove_filter('ll_tools_user_progress_meta_write_allowed', $count_category_writes, 10);
            remove_filter('ll_tools_user_progress_session_category_limit', $category_limit);
        }

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $category_write_count);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame([765441, 765442, 765443], array_keys($progress));
        foreach ($progress as $entry) {
            $this->assertSame(1, (int) $entry['exposure_total']);
            $this->assertSame(1, (int) $entry['exposure_by_mode']['learning']);
        }

        $payload = $this->ledgerPayload($event_uuid);
        $this->assertSame([765441, 765442, 765443], $payload['category_ids']);
    }

    public function test_category_study_units_are_clamped_before_storage_and_application(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_id = 765451;
        $event_uuid = 'atomic-category-units-' . wp_generate_uuid4();
        $units_limit = static function (): int {
            return 3;
        };

        add_filter('ll_tools_user_progress_category_study_units_limit', $units_limit);
        try {
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => $event_uuid,
                'event_type' => 'category_study',
                'mode' => 'listening',
                'category_id' => $category_id,
                'payload' => ['units' => PHP_INT_MAX],
            ]]);
        } finally {
            remove_filter('ll_tools_user_progress_category_study_units_limit', $units_limit);
        }

        $this->assertSame(1, $stats['processed']);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(3, (int) $progress[$category_id]['exposure_total']);
        $this->assertSame(3, (int) $progress[$category_id]['exposure_by_mode']['listening']);
        $payload = $this->ledgerPayload($event_uuid);
        $this->assertSame(3, (int) $payload['units']);
    }

    public function test_user_lock_discards_meta_snapshot_primed_before_a_newer_database_value(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_id = 654321;
        $stale_store = $this->categoryProgressStore($category_id, 1, 'practice');
        $this->assertNotFalse(update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stale_store));
        $this->assertSame(
            1,
            (int) ll_tools_get_user_category_progress($user_id)[$category_id]['exposure_total']
        );

        // Model another transaction committing while this request still owns
        // the earlier object-cache snapshot. Raw SQL intentionally bypasses
        // WordPress cache invalidation.
        $fresh_store = $this->categoryProgressStore($category_id, 5, 'practice');
        $raw_updated = $wpdb->update(
            $wpdb->usermeta,
            ['meta_value' => maybe_serialize($fresh_store)],
            ['user_id' => $user_id, 'meta_key' => LL_TOOLS_USER_CATEGORY_PROGRESS_META],
            ['%s'],
            ['%d', '%s']
        );
        $this->assertSame(1, $raw_updated);

        $stats = ll_tools_process_progress_events_batch($user_id, [[
            'event_uuid' => 'atomic-stale-cache-' . wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => $category_id,
            'payload' => ['units' => 1],
        ]]);

        $this->assertSame(1, $stats['processed']);
        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(6, (int) $progress[$category_id]['exposure_total']);
    }

    public function test_savepoint_success_clears_meta_repopulated_by_update_hook(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $category_id = 654322;
        $this->assertNotFalse(update_user_meta(
            $user_id,
            LL_TOOLS_USER_CATEGORY_PROGRESS_META,
            $this->categoryProgressStore($category_id, 0, 'learning')
        ));

        $savepoint_released = false;
        $query_observer = static function (string $query) use (&$savepoint_released): string {
            if (preg_match('/^RELEASE SAVEPOINT ll_progress_event_[0-9]+_[a-f0-9]{12}$/', trim($query)) === 1) {
                $savepoint_released = true;
            }
            return $query;
        };
        $repopulate_cache = static function ($meta_id, $object_id, $meta_key) use ($user_id): void {
            if ((int) $object_id === $user_id && (string) $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
            }
        };

        add_filter('query', $query_observer);
        add_action('updated_user_meta', $repopulate_cache, 10, 3);
        try {
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => 'atomic-savepoint-cache-' . wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'learning',
                'category_id' => $category_id,
                'payload' => ['units' => 1],
            ]]);
        } finally {
            remove_action('updated_user_meta', $repopulate_cache, 10);
            remove_filter('query', $query_observer);
        }

        $this->assertSame(1, $stats['processed']);
        $this->assertTrue($savepoint_released);

        $fresh_store = $this->categoryProgressStore($category_id, 7, 'learning');
        $raw_updated = $wpdb->update(
            $wpdb->usermeta,
            ['meta_value' => maybe_serialize($fresh_store)],
            ['user_id' => $user_id, 'meta_key' => LL_TOOLS_USER_CATEGORY_PROGRESS_META],
            ['%s'],
            ['%d', '%s']
        );
        $this->assertSame(1, $raw_updated);

        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertSame(7, (int) $progress[$category_id]['exposure_total']);
    }

    public function test_core_usermeta_non_transactional_engine_fails_before_any_event_write(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $event_uuid = 'atomic-engine-usermeta-' . wp_generate_uuid4();
        $category_id = 654323;
        $diagnostics = [];
        $engine_filter = static function (string $engine, string $table_key): string {
            return $table_key === 'usermeta' ? 'MyISAM' : $engine;
        };
        $capture_diagnostic = static function (string $message) use (&$diagnostics): void {
            $diagnostics[] = $message;
        };
        $disable_error_log = static function (): bool {
            return false;
        };
        $transaction_queries = [];
        $observe_transactions = static function (string $query) use (&$transaction_queries): string {
            if (
                stripos($query, 'START TRANSACTION') !== false
                || stripos($query, 'SAVEPOINT ll_progress_event_') !== false
            ) {
                $transaction_queries[] = $query;
            }
            return $query;
        };

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        add_filter('ll_tools_user_progress_core_table_engine', $engine_filter, 10, 2);
        add_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
        add_action('ll_tools_user_progress_core_engine_diagnostic', $capture_diagnostic, 10, 1);
        try {
            $status = ll_tools_user_progress_core_engine_status(true);
            $this->assertFalse((bool) $status['ready']);
            $this->assertSame('MyISAM', (string) $status['engines']['usermeta']);

            add_filter('query', $observe_transactions);
            try {
                $stats = ll_tools_process_progress_events_batch($user_id, [[
                    'event_uuid' => $event_uuid,
                    'event_type' => 'category_study',
                    'mode' => 'practice',
                    'category_id' => $category_id,
                    'payload' => ['units' => 2],
                ]]);
            } finally {
                remove_filter('query', $observe_transactions);
            }
        } finally {
            remove_action('ll_tools_user_progress_core_engine_diagnostic', $capture_diagnostic, 10);
            remove_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
            remove_filter('ll_tools_user_progress_core_table_engine', $engine_filter, 10);
        }

        $this->assertTrue((bool) $stats['retryable']);
        $this->assertSame('progress_transactional_engine_unavailable', $stats['failure_code']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame([$event_uuid], $stats['failed_event_uuids']);
        $this->assertSame([], $transaction_queries);
        $this->assertSame(0, $this->ledgerCount($event_uuid));
        $this->assertSame([], ll_tools_get_user_category_progress($user_id));
        $this->assertCount(1, $diagnostics);
        $this->assertStringContainsString('Core tables were not modified', $diagnostics[0]);

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        $this->assertTrue((bool) ll_tools_user_progress_core_engine_status(true)['ready']);
    }

    public function test_plugin_progress_table_non_transactional_engine_fails_before_any_event_write(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $event_uuid = 'atomic-engine-events-' . wp_generate_uuid4();
        $engine_filter = static function (string $engine, string $table_key): string {
            return $table_key === 'progress_events' ? 'MyISAM' : $engine;
        };
        $disable_error_log = static function (): bool {
            return false;
        };
        $transaction_queries = [];
        $observe_transactions = static function (string $query) use (&$transaction_queries): string {
            if (
                stripos($query, 'START TRANSACTION') !== false
                || stripos($query, 'SAVEPOINT ll_progress_event_') !== false
            ) {
                $transaction_queries[] = $query;
            }
            return $query;
        };

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        add_filter('ll_tools_user_progress_core_table_engine', $engine_filter, 10, 2);
        add_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
        add_filter('query', $observe_transactions);
        try {
            $status = ll_tools_user_progress_core_engine_status(true);
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => $event_uuid,
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 654325,
            ]]);
        } finally {
            remove_filter('query', $observe_transactions);
            remove_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
            remove_filter('ll_tools_user_progress_core_table_engine', $engine_filter, 10);
        }

        $this->assertFalse((bool) $status['ready']);
        $this->assertSame('MyISAM', (string) $status['engines']['progress_events']);
        $this->assertSame('progress_transactional_engine_unavailable', $stats['failure_code']);
        $this->assertSame([$event_uuid], $stats['failed_event_uuids']);
        $this->assertSame([], $transaction_queries);
        $this->assertSame(0, $this->ledgerCount($event_uuid));
        $this->assertSame([], ll_tools_get_user_category_progress($user_id));

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        $this->assertTrue((bool) ll_tools_user_progress_core_engine_status(true)['ready']);
    }

    public function test_failed_event_uuid_index_repair_blocks_batch_and_server_mutations(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $word_id = $this->createWord();
        $batch_uuid = 'atomic-schema-batch-' . wp_generate_uuid4();
        $server_uuid = 'atomic-schema-server-' . wp_generate_uuid4();
        $events_table = ll_tools_user_progress_table_names()['events'];
        $repair_faulted = false;
        $mutation_queries = [];
        $source_queries = [];
        $repair_fault = static function (string $query) use ($events_table, &$repair_faulted): string {
            if (
                stripos($query, "ALTER TABLE {$events_table}") !== false
                && stripos($query, 'DROP INDEX uniq_event_uuid') !== false
                && stripos($query, 'ADD UNIQUE KEY uniq_event_uuid (event_uuid)') !== false
            ) {
                $repair_faulted = true;
                return 'SELECT * FROM ll_tools_missing_event_index_repair_table';
            }
            return $query;
        };
        $observe_runtime_queries = static function (string $query) use ($wpdb, &$mutation_queries, &$source_queries): string {
            if (preg_match(
                '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|START\s+TRANSACTION|SAVEPOINT|RELEASE\s+SAVEPOINT|COMMIT|ROLLBACK)\b/i',
                $query
            ) === 1) {
                $mutation_queries[] = $query;
            }
            foreach ([$wpdb->posts, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships] as $source_table) {
                if (stripos($query, (string) $source_table) !== false) {
                    $source_queries[] = $query;
                    break;
                }
            }
            return $query;
        };

        $this->assertNotFalse($wpdb->query(
            "ALTER TABLE {$events_table} DROP INDEX uniq_event_uuid, "
            . 'ADD UNIQUE KEY uniq_event_uuid (event_uuid(63))'
        ));

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $engine_status = ll_tools_user_progress_core_engine_status(true);
            $this->assertTrue((bool) $engine_status['ready']);

            add_filter('query', $repair_fault);
            try {
                delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
                $installed = ll_tools_install_user_progress_schema();
            } finally {
                remove_filter('query', $repair_fault);
            }

            $this->assertFalse($installed);
            $this->assertTrue($repair_faulted);
            $this->assertSame('', (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, ''));
            $this->assertSame('', (string) get_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION, ''));
            $this->assertFalse((bool) ll_tools_user_progress_runtime_schema_status()['ready']);

            add_filter('query', $observe_runtime_queries);
            try {
                $stats = ll_tools_process_progress_events_batch(
                    $user_id,
                    [$this->exposureEvent($batch_uuid, $word_id)]
                );
                $server_recorded = ll_tools_record_server_progress_event($user_id, [
                    'event_uuid' => $server_uuid,
                    'event_type' => 'stt_api_call',
                    'mode' => 'practice',
                    'word_id' => $word_id,
                    'wordset_id' => 765455,
                    'category_id' => 765456,
                ]);
            } finally {
                remove_filter('query', $observe_runtime_queries);
            }
        } finally {
            remove_filter('query', $repair_fault);
            remove_filter('query', $observe_runtime_queries);
            $wpdb->query("ALTER TABLE {$events_table} DROP INDEX uniq_event_uuid");
            $wpdb->query("ALTER TABLE {$events_table} ADD UNIQUE KEY uniq_event_uuid (event_uuid)");
            delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
            ll_tools_install_user_progress_schema();
            $wpdb->suppress_errors($previous_suppress_errors);
        }

        $this->assertTrue((bool) $stats['retryable']);
        $this->assertSame('progress_schema_unavailable', $stats['failure_code']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame([$batch_uuid], $stats['failed_event_uuids']);
        $this->assertTrue(ll_tools_user_progress_stats_are_retryable_failure($stats));
        $this->assertFalse($server_recorded);
        $this->assertSame([], $mutation_queries);
        $this->assertSame([], $source_queries);
        $this->assertSame(0, $this->ledgerCount($batch_uuid));
        $this->assertSame(0, $this->ledgerCount($server_uuid));
        $this->assertNull($this->findWordRow($user_id, $word_id));
        $this->assertSame([], ll_tools_get_user_category_progress($user_id));
    }

    public function test_healthy_runtime_schema_gate_does_not_repeat_full_schema_inspection(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $event_uuid = 'atomic-schema-hot-path-' . wp_generate_uuid4();
        $show_queries = [];
        $observe_show_queries = static function (string $query) use (&$show_queries): string {
            if (preg_match('/^\s*SHOW\s+(?:COLUMNS|INDEX|TABLE\s+STATUS)\b/i', $query) === 1) {
                $show_queries[] = $query;
            }
            return $query;
        };

        add_filter('query', $observe_show_queries);
        try {
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => $event_uuid,
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 765457,
            ]]);
        } finally {
            remove_filter('query', $observe_show_queries);
        }

        $this->assertSame(1, $stats['processed']);
        $this->assertSame([], $show_queries);
        $this->assertSame(1, $this->ledgerCount($event_uuid));
    }

    public function test_source_failure_requeues_only_failed_event_and_does_not_poison_later_work(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $prompt_card_id = (int) self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Atomic source prompt card',
        ]);
        clean_object_term_cache($prompt_card_id, LL_TOOLS_PROMPT_CARD_POST_TYPE);
        $failed_uuid = 'atomic-source-failed-' . wp_generate_uuid4();
        $successful_uuid = 'atomic-source-success-' . wp_generate_uuid4();
        $category_id = 654326;
        $faulted = false;
        $taxonomy_fault = static function (string $query) use ($wpdb, &$faulted): string {
            if (
                !$faulted
                && stripos($query, (string) $wpdb->term_relationships) !== false
                && stripos($query, 'word-category') !== false
            ) {
                $faulted = true;
                return 'SELECT * FROM ll_tools_missing_progress_taxonomy_table';
            }
            return $query;
        };

        add_filter('query', $taxonomy_fault);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $stats = ll_tools_process_progress_events_batch($user_id, [
                [
                    'event_uuid' => $failed_uuid,
                    'event_type' => 'word_exposure',
                    'mode' => 'practice',
                    'wordset_id' => 654327,
                    'payload' => ['prompt_card_id' => $prompt_card_id],
                ],
                [
                    'event_uuid' => $successful_uuid,
                    'event_type' => 'category_study',
                    'mode' => 'learning',
                    'category_id' => $category_id,
                ],
            ]);
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $taxonomy_fault);
        }

        $this->assertTrue($faulted);
        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['failed']);
        $this->assertTrue((bool) $stats['retryable']);
        $this->assertSame('progress_source_incomplete', $stats['failure_code']);
        $this->assertSame([$failed_uuid], $stats['failed_event_uuids']);
        $this->assertSame(0, $this->ledgerCount($failed_uuid));
        $this->assertSame(1, $this->ledgerCount($successful_uuid));
        $this->assertNull(ll_tools_user_progress_get_source_error());
        $this->assertSame(1, (int) ll_tools_get_user_category_progress($user_id)[$category_id]['exposure_total']);

        $retried = ll_tools_process_progress_events_batch($user_id, [[
            'event_uuid' => $failed_uuid,
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'wordset_id' => 654327,
            'payload' => ['prompt_card_id' => $prompt_card_id],
        ]]);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame([], $retried['failed_event_uuids']);
        $this->assertSame(1, $this->ledgerCount($failed_uuid));
        $this->assertSame(1, (int) ll_tools_get_user_prompt_card_progress($user_id)[$prompt_card_id]['exposure_total']);
    }

    public function test_server_event_source_failure_does_not_poison_later_progress_work(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $word_id = $this->createWord();
        clean_object_term_cache($word_id, 'words');
        $faulted = false;
        $taxonomy_fault = static function (string $query) use ($wpdb, &$faulted): string {
            if (
                !$faulted
                && stripos($query, (string) $wpdb->term_relationships) !== false
                && stripos($query, 'wordset') !== false
            ) {
                $faulted = true;
                return 'SELECT * FROM ll_tools_missing_server_event_taxonomy_table';
            }
            return $query;
        };

        add_filter('query', $taxonomy_fault);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $recorded = ll_tools_record_server_progress_event($user_id, [
                'event_uuid' => 'atomic-server-source-' . wp_generate_uuid4(),
                'event_type' => 'stt_api_call',
                'mode' => 'practice',
                'word_id' => $word_id,
            ]);
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $taxonomy_fault);
        }

        $this->assertTrue($faulted);
        $this->assertFalse($recorded);
        $this->assertNull(ll_tools_user_progress_get_source_error());

        $category_id = 654328;
        $this->assertTrue(ll_tools_record_category_exposure(
            $user_id,
            $category_id,
            'practice',
            0,
            1
        ));
        $this->assertSame(
            1,
            (int) ll_tools_get_user_category_progress($user_id)[$category_id]['exposure_total']
        );
    }

    public function test_healthy_engine_status_uses_fifteen_minute_default_cache_ttl(): void
    {
        $seen_default_ttl = null;
        $capture_ttl = static function (int $ttl, bool $ready) use (&$seen_default_ttl): int {
            if ($ready) {
                $seen_default_ttl = $ttl;
            }
            return $ttl;
        };

        add_filter('ll_tools_user_progress_core_engine_cache_ttl', $capture_ttl, 10, 2);
        try {
            $status = ll_tools_user_progress_core_engine_status();
        } finally {
            remove_filter('ll_tools_user_progress_core_engine_cache_ttl', $capture_ttl, 10);
        }

        $this->assertTrue((bool) $status['ready']);
        $this->assertSame(15 * MINUTE_IN_SECONDS, $seen_default_ttl);
    }

    public function test_missing_core_engine_metadata_is_retryable_and_cached_without_repeated_show_queries(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $event_uuid = 'atomic-engine-missing-' . wp_generate_uuid4();
        $show_queries = [];
        $observe_show_queries = static function (string $query) use (&$show_queries): string {
            if (stripos($query, 'SHOW TABLE STATUS LIKE') !== false) {
                $show_queries[] = $query;
            }
            return $query;
        };
        $missing_engine = static function (string $engine, string $table_key): string {
            return $table_key === 'users' ? '' : $engine;
        };
        $disable_error_log = static function (): bool {
            return false;
        };

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        add_filter('query', $observe_show_queries);
        add_filter('ll_tools_user_progress_core_table_engine', $missing_engine, 10, 2);
        add_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
        try {
            $status = ll_tools_user_progress_core_engine_status();
            $cached_status = ll_tools_user_progress_core_engine_status();
            $stats = ll_tools_process_progress_events_batch($user_id, [[
                'event_uuid' => $event_uuid,
                'event_type' => 'category_study',
                'mode' => 'listening',
                'category_id' => 654324,
            ]]);
        } finally {
            remove_filter('ll_tools_user_progress_log_core_engine_diagnostic', $disable_error_log);
            remove_filter('ll_tools_user_progress_core_table_engine', $missing_engine, 10);
            remove_filter('query', $observe_show_queries);
        }

        $this->assertFalse((bool) $status['ready']);
        $this->assertSame('', (string) $status['engines']['users']);
        $this->assertSame($status, $cached_status);
        $this->assertCount(4, $show_queries);
        $this->assertTrue((bool) $stats['retryable']);
        $this->assertSame([$event_uuid], $stats['failed_event_uuids']);
        $this->assertSame(0, $this->ledgerCount($event_uuid));

        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        $this->assertTrue((bool) ll_tools_user_progress_core_engine_status(true)['ready']);
    }

    private function createWord(): int
    {
        return (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Atomic progress word',
        ]);
    }

    private function categoryProgressStore(int $category_id, int $exposure_total, string $mode): array
    {
        $by_mode = array_fill_keys(ll_tools_progress_modes(), 0);
        $by_mode[ll_tools_normalize_progress_mode($mode)] = max(0, $exposure_total);

        return [
            $category_id => [
                'category_id' => $category_id,
                'wordset_id' => 0,
                'exposure_total' => max(0, $exposure_total),
                'exposure_by_mode' => $by_mode,
                'last_mode' => ll_tools_normalize_progress_mode($mode),
                'last_seen_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];
    }

    private function exposureEvent(string $uuid, int $word_id): array
    {
        return [
            'event_uuid' => $uuid,
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'word_id' => $word_id,
        ];
    }

    private function readWordRow(int $user_id, int $word_id): array
    {
        $row = $this->findWordRow($user_id, $word_id);
        $this->assertIsArray($row);
        return $row;
    }

    private function findWordRow(int $user_id, int $word_id): ?array
    {
        global $wpdb;

        $table = ll_tools_user_progress_table_names()['words'];
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND word_id = %d", $user_id, $word_id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    private function ledgerCount(string $event_uuid): int
    {
        global $wpdb;

        $table = ll_tools_user_progress_table_names()['events'];
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_uuid = %s", $event_uuid)
        );
    }

    /** @return array<string,mixed> */
    private function ledgerPayload(string $event_uuid): array
    {
        global $wpdb;

        $table = ll_tools_user_progress_table_names()['events'];
        $payload_json = $wpdb->get_var(
            $wpdb->prepare("SELECT payload_json FROM {$table} WHERE event_uuid = %s", $event_uuid)
        );
        $payload = json_decode((string) $payload_json, true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
