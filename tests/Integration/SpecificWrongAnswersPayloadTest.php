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
        if ($wordset_id > 0) {
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

    public function test_word_payload_marks_specific_wrong_answer_relationships(): void
    {
        $category_name = 'Specific Wrong Payload ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name);

        $owner_id = $this->createWord($category_id, 'Owner Word');
        $reserved_id = $this->createWord($category_id, 'Reserved Wrong Word');
        $other_id = $this->createWord($category_id, 'Other Word');

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
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
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $words_by_category = ll_tools_user_study_words([$category_id], $wordset_id);
        $this->assertArrayHasKey($category_id, $words_by_category);
        $this->assertIsArray($words_by_category[$category_id]);

        $by_id = $this->indexRowsByWordId($words_by_category[$category_id]);
        $this->assertArrayHasKey($owner_id, $by_id);
        $this->assertArrayHasKey($reserved_id, $by_id);

        $owner_row = $by_id[$owner_id];
        $this->assertSame([$reserved_id], $this->normalizeIds((array) ($owner_row['specific_wrong_answer_ids'] ?? [])));
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
}
