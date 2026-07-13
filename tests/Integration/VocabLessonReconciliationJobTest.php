<?php
declare(strict_types=1);

final class VocabLessonReconciliationJobTest extends LL_Tools_TestCase
{
    /** @var callable */
    private $batchSizeFilter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batchSizeFilter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_vocab_lesson_reconciliation_batch_size', $this->batchSizeFilter);
        $this->resetJobState();
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_vocab_lesson_reconciliation_batch_size', $this->batchSizeFilter);
        $this->resetJobState();
        parent::tearDown();
    }

    public function test_cleanup_start_is_write_light_and_continuations_are_bounded(): void
    {
        $lesson_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => 'Durable Cleanup Lesson ' . $index,
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) (900000 + $index));
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) (800000 + $index));
            $lesson_ids[] = (int) $lesson_id;
        }

        $cleanup_queries = [];
        $query_filter = static function (string $query) use (&$cleanup_queries): string {
            if (strpos($query, "post_type = 'll_vocab_lesson'") !== false && strpos($query, 'ORDER BY ID ASC') !== false) {
                $cleanup_queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $query_filter);

        try {
            $queued = ll_tools_queue_vocab_lesson_reconciliation([], [
                'manual' => true,
                'cleanup_invalid' => true,
            ], true);
            ll_tools_schedule_vocab_lesson_full_sync(1);

            $this->assertSame('queued', $queued['status']);
            $this->assertSame('cleanup', $queued['phase']);
            $this->assertSame(0, (int) $queued['removed']);
            foreach ($lesson_ids as $lesson_id) {
                $this->assertSame('publish', get_post_status($lesson_id));
            }
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));

            $first = ll_tools_run_vocab_lesson_reconciliation_batch();
            $second = ll_tools_run_vocab_lesson_reconciliation_batch();
            $third = ll_tools_run_vocab_lesson_reconciliation_batch();
            $complete = ll_tools_run_vocab_lesson_reconciliation_batch();
        } finally {
            remove_filter('query', $query_filter);
        }

        $this->assertSame(2, (int) $first['cleanup_processed']);
        $this->assertSame(2, (int) $first['removed']);
        $this->assertSame(4, (int) $second['cleanup_processed']);
        $this->assertSame(4, (int) $second['removed']);
        $this->assertSame(5, (int) $third['cleanup_processed']);
        $this->assertSame(5, (int) $third['removed']);
        $this->assertSame('sync', $third['phase']);
        $this->assertSame('completed', $complete['status']);
        $this->assertSame('complete', $complete['phase']);
        $this->assertCount(3, $cleanup_queries);
        foreach ($cleanup_queries as $query) {
            $this->assertMatchesRegularExpression('/LIMIT\s+3\b/i', $query);
        }
        foreach ($lesson_ids as $lesson_id) {
            $this->assertSame('trash', get_post_status($lesson_id));
        }
    }

    public function test_sync_processes_one_wordset_and_at_most_two_categories_per_continuation(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        $candidate_queries = [];
        $query_filter = static function (string $query) use (&$candidate_queries): string {
            if (strpos($query, 'SELECT DISTINCT category_taxonomy.term_id') !== false) {
                $candidate_queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $query_filter);

        try {
            $wordset_ids = [];
            for ($wordset_index = 1; $wordset_index <= 2; $wordset_index++) {
                $wordset_id = $this->createTerm('Durable Sync Wordset ' . $wordset_index, 'wordset');
                $wordset_ids[] = $wordset_id;
                for ($category_index = 1; $category_index <= 3; $category_index++) {
                    $category_id = $this->createTerm(
                        'Durable Sync Category ' . $wordset_index . '-' . $category_index,
                        'word-category'
                    );
                    update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
                    update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
                    $word_id = self::factory()->post->create([
                        'post_type' => 'words',
                        'post_status' => 'publish',
                        'post_title' => 'Durable Sync Word ' . $wordset_index . '-' . $category_index,
                    ]);
                    update_post_meta($word_id, 'word_translation', 'Translation ' . $wordset_index . '-' . $category_index);
                    wp_set_post_terms($word_id, [$category_id], 'word-category', false);
                    wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
                }
            }

            $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
            update_option('ll_vocab_lesson_wordsets', $wordset_ids, false);
            unset($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);
            $setup_lesson_ids = get_posts([
                'post_type' => 'll_vocab_lesson',
                'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
                'numberposts' => -1,
                'fields' => 'ids',
            ]);
            foreach ((array) $setup_lesson_ids as $setup_lesson_id) {
                wp_delete_post((int) $setup_lesson_id, true);
            }
            foreach ($wordset_ids as $wordset_id) {
                $candidate_ids = ll_tools_get_vocab_lesson_reconciliation_candidate_ids($wordset_id, 0, 10);
                $this->assertCount(3, $candidate_ids);
                $counts = ll_tools_get_vocab_lesson_reconciliation_counts($wordset_id, $candidate_ids);
                foreach ($candidate_ids as $category_id) {
                    $this->assertSame(1, (int) ($counts['all'][$category_id] ?? 0));
                    $this->assertTrue(
                        ll_tools_can_generate_vocab_lesson($category_id, $wordset_id, $counts),
                        'Expected each durable sync fixture category to be generatable.'
                    );
                }
            }
            $candidate_queries = [];
            ll_tools_queue_vocab_lesson_reconciliation($wordset_ids, ['manual' => true], true);
            $states = [];
            do {
                $states[] = ll_tools_run_vocab_lesson_reconciliation_batch();
                $state = end($states);
            } while (($state['status'] ?? '') === 'queued' && count($states) < 10);
        } finally {
            remove_filter('query', $query_filter);
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }

        $this->assertCount(4, $states);
        $this->assertSame([2, 3, 5, 6], array_map(static function (array $state): int {
            return (int) ($state['categories_processed'] ?? 0);
        }, $states));
        $this->assertSame([2, 3, 5, 6], array_map(static function (array $state): int {
            return (int) ($state['created'] ?? 0);
        }, $states));
        $this->assertSame([0, 1, 1, 2], array_map(static function (array $state): int {
            return (int) ($state['wordsets_processed'] ?? 0);
        }, $states));
        $this->assertSame('completed', (string) ($states[3]['status'] ?? ''));
        $this->assertSame('complete', (string) ($states[3]['phase'] ?? ''));
        $this->assertCount(4, $candidate_queries);
        foreach ($candidate_queries as $query) {
            $this->assertMatchesRegularExpression('/LIMIT\s+3\b/i', $query);
        }
    }

    public function test_failed_job_can_be_restarted_without_reusing_error_state(): void
    {
        $wordset_id = $this->createTerm('Durable Retry Wordset', 'wordset');
        ll_tools_queue_vocab_lesson_reconciliation([$wordset_id], ['manual' => true], true);

        $failure_filter = static function (): int {
            throw new RuntimeException('Synthetic durable sync failure');
        };
        add_filter('ll_tools_vocab_lesson_reconciliation_batch_size', $failure_filter, 20);
        try {
            $failed = ll_tools_run_vocab_lesson_reconciliation_batch();
        } finally {
            remove_filter('ll_tools_vocab_lesson_reconciliation_batch_size', $failure_filter, 20);
        }

        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('Synthetic durable sync failure', (string) $failed['message']);

        $restarted = ll_tools_queue_vocab_lesson_reconciliation([$wordset_id], ['manual' => true], true);
        $this->assertSame('queued', $restarted['status']);
        $this->assertSame('', $restarted['message']);
        $this->assertSame(0, (int) $restarted['started_at']);
    }

    private function createTerm(string $name, string $taxonomy): int
    {
        $created = wp_insert_term($name, $taxonomy, ['slug' => sanitize_title($name)]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function resetJobState(): void
    {
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        delete_transient(LL_TOOLS_VOCAB_LESSON_SYNC_LOCK);
        delete_transient('ll_tools_vocab_lesson_sync_notice');
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        delete_option('ll_vocab_lesson_wordsets');
        unset($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);
    }
}
