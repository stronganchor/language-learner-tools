<?php
declare(strict_types=1);

final class WordCategoryCountHelperRegressionTest extends LL_Tools_TestCase
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

    private function createWord(int $categoryId, string $title, string $translation = ''): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$categoryId], 'word-category', false);
        if ($translation !== '') {
            update_post_meta($word_id, 'word_translation', $translation);
        }

        return (int) $word_id;
    }

    private function addAudio(int $wordId, string $suffix = ''): int
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $wordId,
            'post_title' => 'Audio ' . $suffix,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/test-audio-' . $wordId . $suffix . '.mp3');
        return (int) $audio_id;
    }

    private function addImage(int $wordId, string $suffix = ''): int
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/02/test-image-' . $wordId . $suffix . '.jpg');
        set_post_thumbnail($wordId, $attachment_id);
        return (int) $attachment_id;
    }

    private function createWordset(string $name): int
    {
        $term = wp_insert_term($name, 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    public function test_count_helper_matches_rows_for_text_and_image_quiz_filters(): void
    {
        $category_name = 'Count Helper Filters ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name, 'text_title', 'text_translation');

        $with_image_a = $this->createWord($category_id, 'Word A', 'Translation A');
        $this->addImage($with_image_a, '-a');

        $this->createWord($category_id, 'Word B', 'Translation B');

        $with_image_c = $this->createWord($category_id, 'Word C', 'Translation C');
        $this->addImage($with_image_c, '-c');

        $text_config = [
            'prompt_type' => 'text_title',
            'option_type' => 'text_translation',
        ];
        $text_rows = ll_get_words_by_category($category_name, 'text', null, $text_config);
        $text_count = ll_get_words_by_category_count($category_name, 'text', null, $text_config);
        $this->assertSame(count($text_rows), $text_count);
        $this->assertSame(3, $text_count);

        $image_config = [
            'prompt_type' => 'image',
            'option_type' => 'image',
        ];
        $image_rows = ll_get_words_by_category($category_name, 'image', null, $image_config);
        $image_count = ll_get_words_by_category_count($category_name, 'image', null, $image_config);
        $this->assertSame(count($image_rows), $image_count);
        $this->assertSame(2, $image_count);
    }

    public function test_count_helper_matches_rows_for_wrong_answer_only_audio_prompt_exception(): void
    {
        $category_name = 'Count Helper Specific Wrong ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name, 'audio', 'text_title');

        $owner_id = $this->createWord($category_id, 'Owner', 'Owner Translation');
        $this->addAudio($owner_id, '-owner');

        $reserved_id = $this->createWord($category_id, 'Reserved', 'Reserved Translation');
        $plain_no_audio_id = $this->createWord($category_id, 'Plain No Audio', 'Plain Translation');

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Typed Wrong']);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $config = [
            'prompt_type' => 'audio',
            'option_type' => 'text_title',
        ];

        $rows = ll_get_words_by_category($category_name, 'text', null, $config);
        $count = ll_get_words_by_category_count($category_name, 'text', null, $config);

        $row_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) $rows), static function (int $id): bool {
            return $id > 0;
        }));
        sort($row_ids, SORT_NUMERIC);

        $this->assertSame(count($rows), $count);
        $this->assertSame([$owner_id, $reserved_id], $row_ids);
        $this->assertNotContains($plain_no_audio_id, $row_ids);
        $this->assertSame(2, $count);
    }

    public function test_count_helper_matches_rows_for_audio_prompt_text_title_with_wordset_scope(): void
    {
        $category_name = 'Count Helper Audio Text Title ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name, 'audio', 'text_title');
        $wordset_id = $this->createWordset('Count Helper Wordset ' . (string) wp_rand(1000, 9999));

        for ($index = 1; $index <= 5; $index++) {
            $word_id = $this->createWord($category_id, 'Audio Text Title Word ' . $index, 'Audio Text Title Translation ' . $index);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $this->addAudio($word_id, '-' . (string) $index);
        }

        $config = [
            'prompt_type' => 'audio',
            'option_type' => 'text_title',
        ];

        $rows = ll_get_words_by_category($category_name, 'text', [$wordset_id], $config);
        $count = ll_get_words_by_category_count($category_name, 'text', [$wordset_id], $config);
        $term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);
        $resolved_config = ll_tools_get_category_quiz_config($term);
        $this->assertSame('audio', (string) ($resolved_config['prompt_type'] ?? ''));
        $this->assertSame('text_title', (string) ($resolved_config['option_type'] ?? ''));
        $primary_count_via_ll_can_inputs = ll_get_words_by_category_count(
            $category_name,
            (string) ($resolved_config['option_type'] ?? 'image'),
            [$wordset_id],
            $resolved_config
        );

        $this->assertSame(5, count($rows), 'Expected full rows helper to return all five words.');
        $this->assertSame(count($rows), $count, 'Count helper should match full rows helper for audio+text_title wordset-scoped quizzes.');
        $this->assertSame(5, $primary_count_via_ll_can_inputs, 'Count helper should match ll_can_category_generate_quiz primary count inputs.');
        $this->assertTrue(ll_can_category_generate_quiz(get_term($category_id, 'word-category'), 5, [$wordset_id]));
    }

    public function test_count_helper_regression_preserves_mode_and_category_quiz_fallbacks(): void
    {
        $category_name = 'Count Helper Fallback ' . (string) wp_rand(1000, 9999);
        $category_id = $this->createCategory($category_name, 'image', 'audio');

        $word_id = $this->createWord($category_id, 'Fallback Word', 'Fallback Translation');
        $this->addImage($word_id, '-fallback');

        $term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $this->assertTrue(ll_can_category_generate_quiz($term, 1, []));
        $this->assertSame('text_translation', ll_determine_display_mode($category_name, 1, []));
    }
}
