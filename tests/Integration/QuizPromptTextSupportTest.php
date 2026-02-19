<?php
declare(strict_types=1);

final class QuizPromptTextSupportTest extends LL_Tools_TestCase
{
    public function test_text_prompt_with_image_options_uses_prompt_label_independently(): void
    {
        $category = wp_insert_term('Text Prompt Image Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Casa',
        ]);
        update_post_meta($word_id, 'word_translation', 'House');
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $attachment_id = self::factory()->post->create([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_title'     => 'Casa Image',
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/02/casa-image.jpg');
        set_post_thumbnail($word_id, $attachment_id);

        $rows = ll_get_words_by_category(
            'Text Prompt Image Category',
            'image',
            null,
            [
                'prompt_type' => 'text_translation',
                'option_type' => 'image',
            ]
        );

        $this->assertNotEmpty($rows);
        $row = $rows[0];

        $this->assertSame('Casa', $row['label']);
        $this->assertSame('House', $row['prompt_label']);
    }
}
