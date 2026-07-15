<?php
declare(strict_types=1);

final class SpecificWrongAnswersPayloadTest extends LL_Tools_TestCase
{
    private function createCategory(string $name): int
    {
        $term = wp_insert_term($name, 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $category_id = (int) $term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        return $category_id;
    }

    private function createWord(int $categoryId, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);
        wp_set_post_terms($word_id, [$categoryId], 'word-category', false);
        return (int) $word_id;
    }

    private function ensureWordsetId(): int
    {
        $wordset_id = function_exists('ll_tools_get_active_wordset_id')
            ? (int) ll_tools_get_active_wordset_id()
            : 0;
        if ($wordset_id > 0 && term_exists($wordset_id, 'wordset')) {
            return $wordset_id;
        }

        $name = 'Specific Wrong Wordset ' . (string) wp_rand(1000, 9999);
        $term = wp_insert_term($name, 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function indexRowsByWordId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = $row;
        }
        return $out;
    }

    private function normalizeIds(array $values): array
    {
        $ids = array_values(array_filter(array_map('intval', $values), static function ($id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return $category_id;
    }

    public function test_word_payload_marks_specific_wrong_answer_relationships(): void
    {
        $category_name = 'Specific Wrong Payload ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name);

        $owner_id = $this->createWord($category_id, 'Owner Word');
        $reserved_id = $this->createWord($category_id, 'Reserved Wrong Word');
        $other_id = $this->createWord($category_id, 'Other Word');

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Typed Wrong']);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $rows = ll_get_words_by_category(
            $category_name,
            'text_title',
            null,
            [
                'prompt_type' => 'text_title',
                'option_type' => 'text_title',
            ]
        );

        $this->assertNotEmpty($rows);
        $by_id = $this->indexRowsByWordId((array) $rows);

        $this->assertArrayHasKey($owner_id, $by_id);
        $this->assertArrayHasKey($reserved_id, $by_id);
        $this->assertArrayHasKey($other_id, $by_id);

        $owner_row = $by_id[$owner_id];
        $this->assertSame([$reserved_id], $this->normalizeIds((array) ($owner_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame(['Typed Wrong'], array_values(array_map('strval', (array) ($owner_row['specific_wrong_answer_texts'] ?? []))));
        $this->assertSame([], $this->normalizeIds((array) ($owner_row['specific_wrong_answer_owner_ids'] ?? [])));
        $this->assertFalse((bool) ($owner_row['is_specific_wrong_answer_only'] ?? false));

        $reserved_row = $by_id[$reserved_id];
        $this->assertSame([], $this->normalizeIds((array) ($reserved_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame([$owner_id], $this->normalizeIds((array) ($reserved_row['specific_wrong_answer_owner_ids'] ?? [])));
        $this->assertTrue((bool) ($reserved_row['is_specific_wrong_answer_only'] ?? false));

        $other_row = $by_id[$other_id];
        $this->assertSame([], $this->normalizeIds((array) ($other_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame([], $this->normalizeIds((array) ($other_row['specific_wrong_answer_owner_ids'] ?? [])));
        $this->assertFalse((bool) ($other_row['is_specific_wrong_answer_only'] ?? false));
    }

    public function test_user_study_word_payload_preserves_specific_wrong_answer_fields(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
        $category_name = 'Specific Wrong User Study ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name);

        $owner_id = $this->createWord($category_id, 'Owner User Study Word');
        $reserved_id = $this->createWord($category_id, 'Reserved User Study Word');
        $wordset_id = $this->ensureWordsetId();
        wp_set_post_terms($owner_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($reserved_id, [$wordset_id], 'wordset', false);

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Typed Wrong']);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $words_by_category = ll_tools_user_study_words([$category_id], $wordset_id);
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $this->assertArrayHasKey($effective_category_id, $words_by_category);
        $this->assertIsArray($words_by_category[$effective_category_id]);

        $by_id = $this->indexRowsByWordId($words_by_category[$effective_category_id]);
        $this->assertArrayHasKey($owner_id, $by_id);
        $this->assertArrayHasKey($reserved_id, $by_id);

        $owner_row = $by_id[$owner_id];
        $this->assertSame([$reserved_id], $this->normalizeIds((array) ($owner_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame(['Typed Wrong'], array_values(array_map('strval', (array) ($owner_row['specific_wrong_answer_texts'] ?? []))));
        $this->assertSame([], $this->normalizeIds((array) ($owner_row['specific_wrong_answer_owner_ids'] ?? [])));
        $this->assertFalse((bool) ($owner_row['is_specific_wrong_answer_only'] ?? false));

        $reserved_row = $by_id[$reserved_id];
        $this->assertSame([], $this->normalizeIds((array) ($reserved_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame([$owner_id], $this->normalizeIds((array) ($reserved_row['specific_wrong_answer_owner_ids'] ?? [])));
        $this->assertTrue((bool) ($reserved_row['is_specific_wrong_answer_only'] ?? false));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_rebuild_owner_map_invalidates_cached_category_rows(): void
    {
        $category_name = 'Specific Wrong Cache Bust ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name);

        $owner_id = $this->createWord($category_id, 'Owner Cached Word');
        $reserved_id = $this->createWord($category_id, 'Reserved Cached Word');

        $config = [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
        ];

        $primed_rows = ll_get_words_by_category($category_name, 'text_title', null, $config);
        $primed_by_id = $this->indexRowsByWordId((array) $primed_rows);
        $this->assertArrayHasKey($owner_id, $primed_by_id);
        $this->assertArrayHasKey($reserved_id, $primed_by_id);
        $this->assertSame([], $this->normalizeIds((array) ($primed_by_id[$owner_id]['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame([], $this->normalizeIds((array) ($primed_by_id[$reserved_id]['specific_wrong_answer_owner_ids'] ?? [])));

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Typed Wrong']);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $updated_rows = ll_get_words_by_category($category_name, 'text_title', null, $config);
        $updated_by_id = $this->indexRowsByWordId((array) $updated_rows);

        $this->assertSame([$reserved_id], $this->normalizeIds((array) ($updated_by_id[$owner_id]['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame(['Typed Wrong'], array_values(array_map('strval', (array) ($updated_by_id[$owner_id]['specific_wrong_answer_texts'] ?? []))));
        $this->assertSame([$owner_id], $this->normalizeIds((array) ($updated_by_id[$reserved_id]['specific_wrong_answer_owner_ids'] ?? [])));
    }

    public function test_wrong_answer_only_lookup_primes_candidate_posts_and_meta_in_batches(): void
    {
        global $wpdb;

        $category_id = $this->createCategory('Specific Wrong Batch ' . (string) wp_rand(1000, 9999));
        $owner_id = $this->createWord($category_id, 'Batch Owner Word');
        $candidate_ids = [];
        for ($index = 1; $index <= 8; $index++) {
            $candidate_ids[] = $this->createWord($category_id, 'Batch Reserved Word ' . $index);
        }
        $unrequested_id = $this->createWord($category_id, 'Unrequested Reserved Word');

        update_post_meta($candidate_ids[0], LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$candidate_ids[7]]);
        update_post_meta($candidate_ids[1], LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Nested typed distractor']);

        $owner_option = LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION;
        $integrity_option = LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION;
        $original_owner_map = get_option($owner_option, null);
        $original_integrity = get_option($integrity_option, null);
        $owner_map = [];
        foreach ($candidate_ids as $candidate_id) {
            $owner_map[$candidate_id] = [$owner_id];
            clean_post_cache($candidate_id);
            wp_cache_delete($candidate_id, 'post_meta');
        }
        $owner_map[$unrequested_id] = [$owner_id];
        clean_post_cache($unrequested_id);
        wp_cache_delete($unrequested_id, 'post_meta');
        $generation = 'payload-batch-' . wp_generate_uuid4();
        update_option(
            $owner_option,
            ll_tools_specific_wrong_answer_owner_map_pack($owner_map, $generation),
            false
        );
        update_option($integrity_option, 'v2:' . $generation, false);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $lookup = ll_tools_get_specific_wrong_answer_only_word_lookup($candidate_ids);
        } finally {
            remove_filter('query', $capture);
            if ($original_owner_map === null) {
                delete_option($owner_option);
            } else {
                update_option($owner_option, $original_owner_map, false);
            }
            if ($original_integrity === null) {
                delete_option($integrity_option);
            } else {
                update_option($integrity_option, $original_integrity, false);
            }
        }

        $this->assertArrayNotHasKey($candidate_ids[0], $lookup);
        $this->assertArrayNotHasKey($candidate_ids[1], $lookup);
        foreach (array_slice($candidate_ids, 2) as $candidate_id) {
            $this->assertArrayHasKey($candidate_id, $lookup);
        }
        $this->assertArrayNotHasKey($unrequested_id, $lookup);

        $post_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->posts) !== false;
        }));
        $post_meta_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->postmeta) !== false;
        }));
        $single_post_queries = array_values(array_filter($post_queries, static function (string $query): bool {
            return (bool) preg_match('/\\bWHERE ID = \\d+\\b/', $query);
        }));
        $batch_post_queries = array_values(array_filter($post_queries, static function (string $query): bool {
            return strpos($query, 'WHERE ID IN (') !== false;
        }));

        $this->assertSame([], $single_post_queries, 'Candidate titles must not trigger one post-object query per distractor.');
        $this->assertCount(1, $batch_post_queries, 'Candidate post objects should be primed in one exact-ID query.');
        $this->assertLessThanOrEqual(1, count($post_meta_queries), 'Candidate metadata should be primed in one exact-ID query.');
        $this->assertDoesNotMatchRegularExpression(
            '/\\b' . preg_quote((string) $unrequested_id, '/') . '\\b/',
            implode("\n", $batch_post_queries),
            'The bounded lookup must not hydrate distractors outside the requested category/page.'
        );
    }

    public function test_empty_candidate_lookup_does_not_read_the_global_owner_option(): void
    {
        $option_reads = 0;
        $filter = static function ($value) use (&$option_reads) {
            $option_reads++;
            return $value;
        };

        add_filter('pre_option_' . LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $filter);
        try {
            $this->assertSame([], ll_tools_get_specific_wrong_answer_only_word_lookup([]));
            $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_map([]));
        } finally {
            remove_filter('pre_option_' . LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $filter);
        }

        $this->assertSame(0, $option_reads);
    }
}
