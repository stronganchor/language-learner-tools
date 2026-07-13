<?php
declare(strict_types=1);

final class VocabLessonWrongAnswerScopingTest extends LL_Tools_TestCase
{
    public function test_wordset_count_queries_do_not_include_unrelated_wrong_answer_ids(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $target_category_id = $this->createCategory('Scoped target category');
        $other_category_id = $this->createCategory('Scoped other category');
        $target_wordset_id = $this->createWordset('Scoped target wordset');
        $other_wordset_id = $this->createWordset('Scoped other wordset');

        $target_owner_id = $this->createWord($target_category_id, $target_wordset_id, 'Scoped target owner');
        $target_reserved_id = $this->createWord($target_category_id, $target_wordset_id, 'Scoped target reserved');
        $other_owner_id = $this->createWord($other_category_id, $other_wordset_id, 'Scoped other owner');
        $other_reserved_id = $this->createWord($other_category_id, $other_wordset_id, 'Scoped other reserved');

        update_post_meta($target_owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$target_reserved_id]);
        update_post_meta($other_owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$other_reserved_id]);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $this->assertSame(
            [$target_reserved_id, $other_reserved_id],
            ll_tools_get_specific_wrong_answer_owner_candidate_word_ids()
        );
        $this->assertSame(
            [$target_reserved_id],
            ll_tools_vocab_lesson_get_wrong_answer_candidate_word_ids($target_wordset_id)
        );
        $this->assertSame(
            [$target_reserved_id],
            ll_tools_vocab_lesson_get_specific_wrong_answer_only_word_ids($target_wordset_id)
        );
        $category_scoped_ids = ll_tools_vocab_lesson_get_specific_wrong_answer_only_word_ids($target_wordset_id, [$target_category_id]);
        $this->assertSame([$target_reserved_id], $category_scoped_ids);

        $deepest_queries = $this->captureQueries(static function () use ($target_wordset_id): void {
            ll_tools_get_vocab_lesson_deepest_counts_for_wordset($target_wordset_id, true);
        });
        $this->assertExclusionQueriesAreScoped($deepest_queries, $target_reserved_id, $other_reserved_id);

        $reconciliation_queries = $this->captureQueries(static function () use ($target_wordset_id, $target_category_id): void {
            ll_tools_get_vocab_lesson_reconciliation_counts($target_wordset_id, [$target_category_id]);
        });
        $this->assertExclusionQueriesAreScoped($reconciliation_queries, $target_reserved_id, $other_reserved_id);
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $category_id = (int) $term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        return $category_id;
    }

    private function createWordset(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createWord(int $category_id, int $wordset_id, string $title): int
    {
        $word_id = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        return $word_id;
    }

    /**
     * @param callable():void $callback
     * @return string[]
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $callback();
        } finally {
            remove_filter('query', $capture);
        }

        return $queries;
    }

    /** @param string[] $queries */
    private function assertExclusionQueriesAreScoped(array $queries, int $expected_id, int $unrelated_id): void
    {
        $exclusion_sets = [];
        foreach ($queries as $query) {
            if (preg_match('/posts\\.ID NOT IN \\(([^)]*)\\)/', $query, $matches) !== 1) {
                continue;
            }
            $exclusion_sets[] = array_values(array_filter(array_map('intval', preg_split('/\\s*,\\s*/', $matches[1]) ?: [])));
        }

        $this->assertNotEmpty($exclusion_sets, 'Expected word count SQL to exclude the scoped wrong-answer-only word.');
        foreach ($exclusion_sets as $excluded_ids) {
            $this->assertContains($expected_id, $excluded_ids);
            $this->assertNotContains($unrelated_id, $excluded_ids);
        }
    }
}
