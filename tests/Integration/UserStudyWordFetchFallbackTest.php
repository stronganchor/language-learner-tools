<?php
declare(strict_types=1);

final class UserStudyWordFetchFallbackTest extends LL_Tools_TestCase
{
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

            $study_categories = ll_tools_user_study_categories_for_wordset($wordset_id);
            $study_category = null;
            foreach ($study_categories as $row) {
                if ((int) ($row['id'] ?? 0) === $category_id) {
                    $study_category = $row;
                    break;
                }
            }

            $this->assertIsArray($study_category);
            $this->assertSame('text_translation', (string) ($study_category['option_type'] ?? ''));
            $this->assertSame(1, (int) ($study_category['word_count'] ?? 0));

            $words_by_category = ll_tools_user_study_words([$category_id], $wordset_id);

            $this->assertArrayHasKey($category_id, $words_by_category);
            $this->assertCount(1, (array) $words_by_category[$category_id]);
            $this->assertSame($word_id, (int) ($words_by_category[$category_id][0]['id'] ?? 0));
            $this->assertSame('Fallback Study Translation', (string) ($words_by_category[$category_id][0]['translation'] ?? ''));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
