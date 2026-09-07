<?php
declare(strict_types=1);

final class WordsetCategorySearchMaintenanceTest extends LL_Tools_TestCase
{
    private function term(string $taxonomy): int
    {
        $result = wp_insert_term('Search maintenance ' . wp_generate_uuid4(), $taxonomy);
        $this->assertNotWPError($result);
        return (int) $result['term_id'];
    }

    private function word(int $wordset, int $category, string $title = 'Original title'): int
    {
        $id = (int) self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => $title,
        ]);
        wp_set_object_terms($id, [$wordset], 'wordset');
        wp_set_object_terms($id, [$category], 'word-category');
        update_post_meta($id, 'word_translation', 'Original translation');
        return $id;
    }

    private function warm(int $wordset): array
    {
        for ($i = 0; $i < 30; $i++) {
            $state = ll_tools_wordset_category_search_process_rebuild_batch($wordset);
            if ($state['status'] === 'completed') {
                return $state;
            }
        }
        $this->fail('Fixture index did not complete.');
    }

    private function rows(int $wordset, ?int $word = null): array
    {
        global $wpdb;
        $table = ll_tools_wordset_category_search_table_name();
        $generation = ll_tools_get_wordset_category_search_state($wordset)['published_generation'];
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE wordset_id = %d AND generation = %s",
            $wordset, $generation);
        if ($word !== null) {
            $sql .= $wpdb->prepare(' AND word_id = %d', $word);
        }
        return $wpdb->get_results($sql . ' ORDER BY word_id, category_id', ARRAY_A);
    }

    private function assertReadyGeneration(int $wordset, string $generation): void
    {
        $state = ll_tools_get_wordset_category_search_state($wordset);
        $this->assertSame($generation, $state['generation']);
        $this->assertTrue(ll_tools_wordset_category_search_state_is_ready(
            $wordset, ll_tools_wordset_category_search_fresh_signature($wordset), $state
        ));
    }

    private function onlyWorkerQueue(int $wordset): void
    {
        // The fixture bootstrap also queues global migrations. Isolate the
        // worker's queue so batch counts measure this test's workload.
        update_option('cron', []);
        ll_tools_schedule_wordset_category_search_rebuild($wordset);
    }

    public function test_edits_replace_only_one_words_rows_and_preserve_generation(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $cat = $this->term('word-category');
        $edited = $this->word($set, $cat);
        $other = $this->word($set, $cat, 'Unrelated');
        $generation = $this->warm($set)['generation'];
        $other_before = $this->rows($set, $other);
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string { $queries[] = $sql; return $sql; };
        add_filter('query', $capture);
        try {
            update_post_meta($edited, 'word_translation', 'Çiçek translation');
            wp_update_post(['ID' => $edited, 'post_title' => 'Changed title']);
        } finally {
            remove_filter('query', $capture);
        }
        $this->assertReadyGeneration($set, $generation);
        $this->assertSame($other_before, $this->rows($set, $other));
        $rows = $this->rows($set, $edited);
        $this->assertSame('Changed title', $rows[0]['title_value']);
        $this->assertSame('cicek translation', $rows[0]['translation_normalized']);
        $this->assertSame(2, ll_tools_get_wordset_category_search_state($set)['incremental_updates']);
        foreach ($queries as $sql) {
            if (str_contains($sql, 'posts.ID AS word_id') && str_contains($sql, 'AS wordset_relationships')) {
                $this->assertStringContainsString('AND posts.ID = ' . $edited, $sql);
            }
            if (str_starts_with($sql, 'DELETE FROM ' . ll_tools_wordset_category_search_table_name())) {
                $this->assertStringContainsString('AND word_id = ' . $edited . ' LIMIT ', $sql);
            }
        }
    }

    public function test_category_and_wordset_moves_update_old_and_new_scopes(): void
    {
        $old = $this->term('wordset');
        $new = $this->term('wordset');
        $cat = $this->term('word-category');
        $new_cat = $this->term('word-category');
        update_term_meta($cat, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $old);
        update_term_meta($new_cat, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $old);
        $word = $this->word($old, $cat);
        $old_gen = $this->warm($old)['generation'];
        $new_gen = $this->warm($new)['generation'];
        wp_set_object_terms($word, [$new_cat], 'word-category');
        $this->assertReadyGeneration($old, $old_gen);
        $this->assertSame([$new_cat], array_map('intval', array_column($this->rows($old), 'category_id')));
        wp_set_object_terms($word, [$new], 'wordset');
        $this->assertReadyGeneration($old, $old_gen);
        $this->assertReadyGeneration($new, $new_gen);
        $this->assertSame([], $this->rows($old));
        $this->assertSame([$word], array_map('intval', array_column($this->rows($new), 'word_id')));
        $current_categories = wp_get_object_terms($word, 'word-category', ['fields' => 'ids']);
        wp_remove_object_terms($word, $current_categories, 'word-category');
        $this->assertReadyGeneration($new, $new_gen);
        $this->assertSame([], $this->rows($new));
    }

    public function test_status_type_and_hard_deletion_remove_rows_without_rebuild(): void
    {
        $set = $this->term('wordset');
        $cat = $this->term('word-category');
        $word = $this->word($set, $cat);
        $gen = $this->warm($set)['generation'];
        foreach ([
            ['post_status' => 'draft'], ['post_status' => 'publish'],
            ['post_type' => 'post'], ['post_type' => 'words'],
        ] as $change) {
            wp_update_post(['ID' => $word] + $change);
            $this->assertReadyGeneration($set, $gen);
            $visible = get_post_status($word) === 'publish' && get_post_type($word) === 'words';
            $this->assertCount($visible ? 1 : 0, $this->rows($set));
        }
        wp_delete_post($word, true);
        $this->assertReadyGeneration($set, $gen);
        $this->assertSame([], $this->rows($set));
    }

    public function test_busy_writer_defers_edit_and_background_worker_repairs_it(): void
    {
        $set = $this->term('wordset');
        $word = $this->word($set, $this->term('word-category'));
        $gen = $this->warm($set)['generation'];
        $this->onlyWorkerQueue($set);
        $lock = ll_tools_acquire_wordset_category_search_lock($set);
        try {
            update_post_meta($word, 'word_translation', 'After contention');
            update_option('ll_tools_wordset_category_search_background_only', 1);
            $this->assertFalse(ll_tools_wordset_category_search_ensure_ready($set));
            $result = ll_tools_run_wordset_category_search_worker();
            $this->assertSame(0, $result['batches']);
        } finally {
            ll_tools_release_wordset_category_search_lock($lock);
        }
        ll_tools_run_wordset_category_search_worker();
        $state = ll_tools_get_wordset_category_search_state($set);
        $this->assertNotSame($gen, $state['generation']);
        $this->assertReadyGeneration($set, $state['generation']);
        $this->assertSame('After contention', $this->rows($set)[0]['translation_value']);
    }

    public function test_failed_row_write_never_publishes_partial_search_and_worker_repairs(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $word = $this->word($set, $this->term('word-category'));
        $this->warm($set);
        $table = ll_tools_wordset_category_search_table_name();
        $fail = static function (string $sql) use ($table): string {
            return str_starts_with($sql, 'INSERT INTO ' . $table)
                ? 'INSERT INTO missing_search_test_table VALUES (1)' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try {
            update_post_meta($word, 'word_translation', 'Recover me');
        } finally {
            remove_filter('query', $fail);
            $wpdb->suppress_errors($previous);
        }
        update_option('ll_tools_wordset_category_search_background_only', 1);
        $this->assertFalse(ll_tools_wordset_category_search_ensure_ready($set));
        ll_tools_run_wordset_category_search_worker();
        $this->assertTrue(ll_tools_wordset_category_search_ensure_ready($set));
        $this->assertSame('Recover me', $this->rows($set)[0]['translation_value']);
    }

    public function test_interleaved_edit_cannot_be_adopted_by_another_words_incremental_plan(): void
    {
        $set = $this->term('wordset');
        $cat = $this->term('word-category');
        $one = $this->word($set, $cat);
        $two = $this->word($set, $cat);
        $this->warm($set);
        $plan = ll_tools_wordset_category_search_record_word_change($one, [
            'wordset_ids' => [$set], 'complete' => true,
        ], true);
        update_post_meta($two, 'word_translation', 'Concurrent edit');
        $this->assertFalse(ll_tools_wordset_category_search_refresh_word($set, $one, $plan[$set]));
        update_option('ll_tools_wordset_category_search_background_only', 1);
        $this->assertFalse(ll_tools_wordset_category_search_ensure_ready($set));
        ll_tools_run_wordset_category_search_worker();
        $this->assertTrue(ll_tools_wordset_category_search_ensure_ready($set));
        $this->assertSame('Concurrent edit', $this->rows($set, $two)[0]['translation_value']);
    }

    public function test_request_cached_epoch_does_not_hide_an_external_edit(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $state = $this->warm($set);
        $signature = ll_tools_wordset_category_search_dependency_signature($set);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
            ll_tools_wordset_category_search_source_epoch_option($set)
        ));
        $this->assertFalse(ll_tools_wordset_category_search_state_is_ready($set, $signature, $state));
    }

    public function test_many_searches_cannot_build_or_schedule_work_in_background_mode(): void
    {
        $set = $this->term('wordset');
        $cat = $this->term('word-category');
        $this->word($set, $cat);
        update_option('ll_tools_wordset_category_search_background_only', 1);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$set]);
        $before = ll_tools_get_wordset_category_search_state($set);
        $cron = _get_cron_array();
        for ($i = 0; $i < 100; $i++) {
            $complete = true;
            $this->assertSame([], ll_tools_wordset_category_search_query_matches($set, [$cat], 'botquery' . $i, 1, $complete));
            $this->assertFalse($complete);
        }
        $this->assertSame($before, ll_tools_get_wordset_category_search_state($set));
        $this->assertSame($cron, _get_cron_array());
    }

    public function test_worker_advances_bounded_batches_without_searches_and_ignores_other_cron(): void
    {
        $set = $this->term('wordset');
        $cat = $this->term('word-category');
        for ($i = 0; $i < 25; $i++) { $this->word($set, $cat); }
        $this->onlyWorkerQueue($set);
        update_option('ll_tools_wordset_category_search_background_only', 1);
        $batch_size = static fn(): int => 10;
        add_filter('ll_tools_wordset_category_search_rebuild_batch_size', $batch_size);
        $other_ran = false;
        $other = static function () use (&$other_ran): void { $other_ran = true; };
        add_action('ll_test_unrelated_overdue', $other);
        wp_schedule_single_event(time() - 86400, 'll_test_unrelated_overdue');
        try {
            $first = ll_tools_run_wordset_category_search_worker(10, 1);
            $this->assertSame(1, $first['batches']);
            $this->assertTrue($first['budget_exhausted']);
            $this->assertSame(10, ll_tools_get_wordset_category_search_state($set)['processed']);
            $this->assertFalse(ll_tools_wordset_category_search_ensure_ready($set));
            $second = ll_tools_run_wordset_category_search_worker(10, 10);
            $this->assertGreaterThanOrEqual(2, $second['batches']);
            $this->assertTrue(ll_tools_wordset_category_search_ensure_ready($set));
            $this->assertCount(25, $this->rows($set));
            $this->assertFalse($other_ran);
            $this->assertNotFalse(wp_next_scheduled('ll_test_unrelated_overdue'));
        } finally {
            remove_filter('ll_tools_wordset_category_search_rebuild_batch_size', $batch_size);
            remove_action('ll_test_unrelated_overdue', $other);
        }
    }

    public function test_worker_respects_retry_time_instead_of_busy_looping(): void
    {
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $state = ll_tools_get_wordset_category_search_state($set);
        $state['signature'] = ll_tools_wordset_category_search_fresh_signature($set);
        $state['next_retry_at'] = time() + 60;
        ll_tools_update_wordset_category_search_state($set, $state);
        $this->onlyWorkerQueue($set);
        $this->assertSame(0, ll_tools_run_wordset_category_search_worker()['batches']);
        $this->assertSame(0, ll_tools_get_wordset_category_search_state($set)['processed']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$set]));
    }

    public function test_worker_recovers_expired_owner_by_rotating_generation(): void
    {
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $old = $this->warm($set)['generation'];
        update_option(ll_tools_wordset_category_search_lock_option($set), (time() - 60) . '|stalled-owner', false);
        $this->onlyWorkerQueue($set);
        ll_tools_run_wordset_category_search_worker();
        $new = ll_tools_get_wordset_category_search_state($set)['generation'];
        $this->assertNotSame($old, $new);
        $this->assertReadyGeneration($set, $new);
    }

    public function test_fresh_epoch_storage_failure_cannot_mark_index_ready(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $state = $this->warm($set);
        $fail = static function (string $sql): string {
            return str_contains($sql, 'SELECT option_name, option_value')
                && str_contains($sql, 'll_tools_wcs_source_')
                    ? 'SELECT * FROM missing_search_epoch_table' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try {
            $this->assertFalse(ll_tools_wordset_category_search_state_is_ready($set, $state['signature'], $state));
        } finally {
            remove_filter('query', $fail);
            $wpdb->suppress_errors($previous);
        }
        $this->assertReadyGeneration($set, $state['generation']);
    }

    public function test_failed_sweep_keeps_delayed_retry_instead_of_losing_event(): void
    {
        global $wpdb;
        update_option('cron', []);
        $generation = ll_tools_wordset_category_search_sweep_generation();
        $args = [$generation, 0];
        wp_schedule_single_event(time() - 10, LL_TOOLS_WORDSET_CATEGORY_SEARCH_SWEEP_HOOK, $args);
        $fail = static function (string $sql): string {
            return str_contains($sql, "taxonomy = 'wordset'") && str_contains($sql, 'term_id >')
                ? 'SELECT * FROM missing_search_sweep_table' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try {
            $result = ll_tools_run_wordset_category_search_worker();
            $this->assertSame(1, $result['batches']);
        } finally {
            remove_filter('query', $fail);
            $wpdb->suppress_errors($previous);
        }
        $this->assertGreaterThan(time() + 40, wp_next_scheduled(LL_TOOLS_WORDSET_CATEGORY_SEARCH_SWEEP_HOOK, $args));
    }

    public function test_failed_cron_checkpoint_leaves_original_event_retryable(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $this->onlyWorkerQueue($set);
        $before = wp_next_scheduled(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$set]);
        $fail = static function (string $sql) use ($wpdb): string {
            return str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value")
                && str_contains($sql, "WHERE option_name = 'cron'")
                    ? "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0" : $sql;
        };
        add_filter('query', $fail);
        try {
            $result = ll_tools_run_wordset_category_search_worker();
        } finally {
            remove_filter('query', $fail);
        }
        $this->assertSame('cron_checkpoint_failed', $result['errors'][0]['code']);
        $this->assertSame($before, wp_next_scheduled(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$set]));
        $this->assertSame([], ll_tools_run_wordset_category_search_worker()['errors']);
    }

    public function test_cron_checkpoint_preserves_a_concurrent_unrelated_event(): void
    {
        global $wpdb;
        $set = $this->term('wordset');
        $this->word($set, $this->term('word-category'));
        $this->onlyWorkerQueue($set);
        $raced = false;
        $race = static function (string $sql) use ($wpdb, &$raced): string {
            if (!$raced && str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value")
                && str_contains($sql, "WHERE option_name = 'cron'")) {
                $raced = true;
                wp_schedule_single_event(time() + 90, 'll_search_test_concurrent_cron');
            }
            return $sql;
        };
        add_filter('query', $race);
        try {
            $result = ll_tools_run_wordset_category_search_worker();
        } finally {
            remove_filter('query', $race);
        }
        $this->assertTrue($raced);
        $this->assertSame([], $result['errors']);
        $this->assertNotFalse(wp_next_scheduled('ll_search_test_concurrent_cron'));
    }
}
