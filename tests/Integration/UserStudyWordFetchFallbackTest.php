<?php
declare(strict_types=1);

final class UserStudyWordFetchFallbackTest extends LL_Tools_TestCase
{
    private function resolveEffectiveCategoryId(int $categoryId, int $wordsetId): int
    {
        if ($categoryId <= 0 || $wordsetId <= 0 || !function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            return $categoryId;
        }

        $effectiveCategoryId = (int) ll_tools_get_effective_category_id_for_wordset($categoryId, $wordsetId, false);
        return $effectiveCategoryId > 0 ? $effectiveCategoryId : $categoryId;
    }

    private function createCategory(string $name, string $promptType = 'text_title', string $optionType = 'text_title'): int
    {
        $term = wp_insert_term($name, 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', $promptType);
        update_term_meta($term_id, 'll_quiz_option_type', $optionType);

        return $term_id;
    }

    private function createWord(int $categoryId, int $wordsetId, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, [$categoryId], 'word-category', false);
        wp_set_post_terms($word_id, [$wordsetId], 'wordset', false);

        return (int) $word_id;
    }

    private function createWordset(string $name): int
    {
        $term = wp_insert_term($name, 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        return (int) $term['term_id'];
    }

    private function seedWordProgressRow(int $user_id, int $word_id, int $category_id, int $wordset_id, array $overrides): void
    {
        global $wpdb;
        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];

        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data, [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ]);
        $this->assertNotFalse($inserted);
    }

    public function test_user_study_words_use_effective_option_type_after_audio_fallback(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset_id = $this->createWordset('Fallback Study Wordset ' . (string) wp_rand(1000, 9999));
            $category_name = 'Fallback Study Category ' . (string) wp_rand(1000, 9999);
            $category_id = $this->createCategory($category_name, 'text_title', 'audio');
            $word_id = $this->createWord(
                $category_id,
                $wordset_id,
                'Fallback Study Word',
                'Fallback Study Translation'
            );
            $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);

            $study_categories = ll_tools_user_study_categories_for_wordset($wordset_id);
            $study_category = null;
            foreach ($study_categories as $row) {
                if ((int) ($row['id'] ?? 0) === $effective_category_id) {
                    $study_category = $row;
                    break;
                }
            }

            $this->assertIsArray($study_category);
            $this->assertSame('text_translation', (string) ($study_category['option_type'] ?? ''));
            $this->assertSame(1, (int) ($study_category['word_count'] ?? 0));

            $words_by_category = ll_tools_user_study_words([$category_id], $wordset_id);

            $this->assertArrayHasKey($effective_category_id, $words_by_category);
            $this->assertCount(1, (array) $words_by_category[$effective_category_id]);
            $this->assertSame($word_id, (int) ($words_by_category[$effective_category_id][0]['id'] ?? 0));
            $this->assertSame('Fallback Study Translation', (string) ($words_by_category[$effective_category_id][0]['translation'] ?? ''));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_user_study_words_include_progress_status_and_difficulty_score(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset_id = $this->createWordset('Progress Study Wordset ' . (string) wp_rand(1000, 9999));
            $category_id = $this->createCategory('Progress Study Category ' . (string) wp_rand(1000, 9999));
            $word_id = $this->createWord(
                $category_id,
                $wordset_id,
                'Progress Study Word',
                'Progress Study Translation'
            );
            $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);

            $this->seedWordProgressRow($user_id, $word_id, $category_id, $wordset_id, [
                'total_coverage' => 2,
                'coverage_practice' => 2,
                'incorrect' => 1,
                'lapse_count' => 0,
                'stage' => 0,
            ]);

            $words_by_category = ll_tools_user_study_words([$category_id], $wordset_id);

            $this->assertArrayHasKey($effective_category_id, $words_by_category);
            $this->assertCount(1, (array) $words_by_category[$effective_category_id]);
            $this->assertSame('studied', (string) ($words_by_category[$effective_category_id][0]['status'] ?? ''));
            $this->assertSame(5, (int) ($words_by_category[$effective_category_id][0]['difficulty_score'] ?? 0));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
