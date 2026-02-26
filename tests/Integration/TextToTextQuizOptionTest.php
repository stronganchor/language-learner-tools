<?php
declare(strict_types=1);

final class TextToTextQuizOptionTest extends LL_Tools_TestCase
{
    public function test_text_alias_option_resolves_to_opposite_prompt_text_variant_on_save(): void
    {
        $category = wp_insert_term('Text Match Prompt Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $original_post = $_POST;
        try {
            $_POST['ll_quiz_prompt_type'] = 'text_title';
            $_POST['ll_quiz_option_type'] = 'text';
            ll_save_quiz_prompt_option_fields($category_id, 'word-category');

            $this->assertSame('text_title', get_term_meta($category_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_translation', get_term_meta($category_id, 'll_quiz_option_type', true));

            $_POST['ll_quiz_prompt_type'] = 'text_translation';
            $_POST['ll_quiz_option_type'] = 'text';
            ll_save_quiz_prompt_option_fields($category_id, 'word-category');

            $this->assertSame('text_translation', get_term_meta($category_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', get_term_meta($category_id, 'll_quiz_option_type', true));
        } finally {
            $_POST = $original_post;
        }
    }

    public function test_text_to_text_quiz_words_do_not_require_audio_or_images(): void
    {
        $category = wp_insert_term('Text Only Quiz Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_a = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Casa',
        ]);
        update_post_meta($word_a, 'word_translation', 'House');
        wp_set_post_terms($word_a, [$category_id], 'word-category', false);

        $word_b = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Perro',
        ]);
        update_post_meta($word_b, 'word_translation', 'Dog');
        wp_set_post_terms($word_b, [$category_id], 'word-category', false);

        $rows = ll_get_words_by_category(
            'Text Only Quiz Category',
            'text',
            null,
            [
                'prompt_type' => 'text_translation',
                'option_type' => 'text_translation',
            ]
        );

        $this->assertCount(2, $rows);

        $by_id = [];
        foreach ($rows as $row) {
            $by_id[(int) ($row['id'] ?? 0)] = $row;
        }

        $this->assertArrayHasKey($word_a, $by_id);
        $this->assertArrayHasKey($word_b, $by_id);

        $this->assertSame('House', (string) ($by_id[$word_a]['label'] ?? ''));
        $this->assertSame('House', (string) ($by_id[$word_a]['prompt_label'] ?? ''));
        $this->assertFalse((bool) ($by_id[$word_a]['has_audio'] ?? true));
        $this->assertFalse((bool) ($by_id[$word_a]['has_image'] ?? true));

        $this->assertSame('Dog', (string) ($by_id[$word_b]['label'] ?? ''));
        $this->assertSame('Dog', (string) ($by_id[$word_b]['prompt_label'] ?? ''));
        $this->assertFalse((bool) ($by_id[$word_b]['has_audio'] ?? true));
        $this->assertFalse((bool) ($by_id[$word_b]['has_image'] ?? true));
    }
}
