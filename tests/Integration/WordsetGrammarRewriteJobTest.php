<?php
declare(strict_types=1);

final class WordsetGrammarRewriteJobTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_STATE_OPTION);
        delete_transient(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_HOOK);
    }

    protected function tearDown(): void
    {
        delete_option(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_STATE_OPTION);
        delete_transient(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_HOOK);
        parent::tearDown();
    }

    public function test_gender_sync_queues_then_runs_in_bounded_cursor_batches(): void
    {
        $wordset_id = $this->ensureTerm('wordset', 'Grammar Rewrite', 'grammar-rewrite');
        $category_id = $this->ensureTerm('word-category', 'Grammar Category', 'grammar-category');
        $word_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Grammar Word ' . $index,
            ]);
            wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_object_terms($word_id, [$category_id], 'word-category', false);
            update_post_meta($word_id, 'll_grammatical_gender', 'Old label');
            $word_ids[] = $word_id;
        }

        $candidate_queries = [];
        $query_filter = static function (string $query) use (&$candidate_queries): string {
            if (stripos($query, 'SELECT DISTINCT p.ID') !== false
                && stripos($query, "pm.meta_key = 'll_grammatical_gender'") !== false) {
                $candidate_queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $query_filter);
        ll_tools_wordset_sync_gender_values($wordset_id, ['Old label'], ['New label']);
        remove_filter('query', $query_filter);

        $queued_state = ll_tools_wordset_get_grammar_rewrite_state($wordset_id);
        $this->assertSame('queued', (string) ($queued_state['status'] ?? ''));
        $this->assertCount(1, (array) ($queued_state['tasks'] ?? []));
        $this->assertSame([], $candidate_queries);
        foreach ($word_ids as $word_id) {
            $this->assertSame('Old label', (string) get_post_meta($word_id, 'll_grammatical_gender', true));
        }

        $batch_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter);
        add_filter('query', $query_filter);
        try {
            $first = ll_tools_wordset_run_grammar_rewrite_batch();
            $second = ll_tools_wordset_run_grammar_rewrite_batch();
            $third = ll_tools_wordset_run_grammar_rewrite_batch();
        } finally {
            remove_filter('query', $query_filter);
            remove_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter);
        }

        $this->assertSame('queued', (string) ($first['status'] ?? ''));
        $this->assertSame(2, (int) ($first['processed'] ?? 0));
        $this->assertSame('queued', (string) ($second['status'] ?? ''));
        $this->assertSame(4, (int) ($second['processed'] ?? 0));
        $this->assertSame('completed', (string) ($third['status'] ?? ''));
        $this->assertSame(5, (int) ($third['processed'] ?? 0));
        $this->assertSame(5, (int) ($third['updated'] ?? 0));
        $this->assertCount(3, $candidate_queries);
        foreach ($candidate_queries as $candidate_query) {
            $this->assertMatchesRegularExpression('/LIMIT\s+3\s*$/i', trim($candidate_query));
        }
        foreach ($word_ids as $word_id) {
            $this->assertSame('New label', (string) get_post_meta($word_id, 'll_grammatical_gender', true));
        }
    }

    public function test_consecutive_rewrites_run_in_save_order(): void
    {
        $wordset_id = $this->ensureTerm('wordset', 'Grammar Rewrite Order', 'grammar-rewrite-order');
        $word_ids = [];
        for ($index = 1; $index <= 3; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Ordered Grammar Word ' . $index,
            ]);
            wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
            update_post_meta($word_id, 'll_grammatical_plurality', 'First');
            $word_ids[] = $word_id;
        }

        ll_tools_wordset_sync_plurality_values($wordset_id, ['First'], ['Second']);
        ll_tools_wordset_sync_plurality_values($wordset_id, ['Second'], ['Third']);
        $queued_state = ll_tools_wordset_get_grammar_rewrite_state($wordset_id);
        $this->assertCount(2, (array) ($queued_state['tasks'] ?? []));
        foreach ($word_ids as $word_id) {
            $this->assertSame('First', (string) get_post_meta($word_id, 'll_grammatical_plurality', true));
        }

        $batch_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter);
        try {
            $state = [];
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $state = ll_tools_wordset_run_grammar_rewrite_batch();
                if ((string) ($state['status'] ?? '') === 'completed') {
                    break;
                }
            }
        } finally {
            remove_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter);
        }

        $this->assertSame('completed', (string) ($state['status'] ?? ''));
        $this->assertSame(6, (int) ($state['processed'] ?? 0));
        $this->assertSame(6, (int) ($state['updated'] ?? 0));
        foreach ($word_ids as $word_id) {
            $this->assertSame('Third', (string) get_post_meta($word_id, 'll_grammatical_plurality', true));
        }
    }

    public function test_rewrite_queued_during_a_running_batch_is_retained(): void
    {
        $wordset_id = $this->ensureTerm('wordset', 'Grammar Rewrite Concurrent', 'grammar-rewrite-concurrent');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Concurrent Grammar Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'll_verb_tense', 'First');
        ll_tools_wordset_sync_verb_tense_values($wordset_id, ['First'], ['Second']);

        $queued_during_batch = false;
        $batch_filter = static function (int $batch_size) use ($wordset_id, &$queued_during_batch): int {
            if (!$queued_during_batch) {
                $queued_during_batch = true;
                ll_tools_wordset_sync_verb_tense_values($wordset_id, ['Second'], ['Third']);
            }
            return min(2, $batch_size);
        };
        add_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter, 10, 1);
        try {
            $first = ll_tools_wordset_run_grammar_rewrite_batch();
        } finally {
            remove_filter('ll_tools_wordset_grammar_rewrite_batch_size', $batch_filter, 10);
        }

        $this->assertTrue($queued_during_batch);
        $this->assertSame('queued', (string) ($first['status'] ?? ''));
        $this->assertCount(2, (array) ($first['tasks'] ?? []));
        $this->assertSame('Second', (string) get_post_meta($word_id, 'll_verb_tense', true));

        $second = ll_tools_wordset_run_grammar_rewrite_batch();
        $this->assertSame('completed', (string) ($second['status'] ?? ''));
        $this->assertSame(2, (int) ($second['processed'] ?? 0));
        $this->assertSame(2, (int) ($second['updated'] ?? 0));
        $this->assertSame('Third', (string) get_post_meta($word_id, 'll_verb_tense', true));
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }
        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertNotWPError($created);
        return (int) $created['term_id'];
    }
}
