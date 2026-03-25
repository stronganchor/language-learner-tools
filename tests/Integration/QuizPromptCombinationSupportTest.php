<?php
declare(strict_types=1);

final class QuizPromptCombinationSupportTest extends LL_Tools_TestCase
{
    private function createWord(int $category_id, string $title, string $translation = ''): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        if ($translation !== '') {
            update_post_meta($word_id, 'word_translation', $translation);
        }

        return (int) $word_id;
    }

    private function addAudio(int $word_id, string $suffix = ''): void
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $suffix,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/test-audio-' . $word_id . $suffix . '.mp3');
    }

    private function addImage(int $word_id, string $suffix = ''): void
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/03/test-image-' . $word_id . $suffix . '.jpg');
        set_post_thumbnail($word_id, $attachment_id);
    }

    public function test_combo_prompt_same_text_option_is_saved_as_the_opposite_text_variant(): void
    {
        $category = wp_insert_term('Combo Prompt Save Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $original_post = $_POST;
        try {
            $_POST['ll_quiz_prompt_type'] = 'audio_text_translation';
            $_POST['ll_quiz_option_type'] = 'text_translation';
            ll_save_quiz_prompt_option_fields($category_id, 'word-category');

            $this->assertSame('audio_text_translation', get_term_meta($category_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', get_term_meta($category_id, 'll_quiz_option_type', true));
        } finally {
            $_POST = $original_post;
        }
    }

    public function test_audio_text_prompt_requires_audio_and_uses_prompt_translation_label(): void
    {
        $category = wp_insert_term('Audio Text Prompt Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio_text_translation');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $with_audio_id = $this->createWord($category_id, 'Casa', 'House');
        $this->addAudio($with_audio_id, '-with-audio');

        $this->createWord($category_id, 'Perro', 'Dog');

        $config = [
            'prompt_type' => 'audio_text_translation',
            'option_type' => 'text_title',
        ];

        $rows = ll_get_words_by_category('Audio Text Prompt Category', 'text', null, $config);
        $count = ll_get_words_by_category_count('Audio Text Prompt Category', 'text', null, $config);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $count);
        $this->assertTrue(ll_tools_quiz_requires_audio($config, 'text_title'));

        $row = $rows[0];
        $this->assertSame('Casa', (string) ($row['label'] ?? ''));
        $this->assertSame('House', (string) ($row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($row['audio'] ?? ''));
    }

    public function test_image_text_prompt_requires_images_and_disables_learning_for_text_options(): void
    {
        $category = wp_insert_term('Image Text Prompt Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image_text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $with_image_id = $this->createWord($category_id, 'Kitap', 'Book');
        $this->addImage($with_image_id, '-with-image');

        $this->createWord($category_id, 'Defter', 'Notebook');

        $term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $config = ll_tools_get_category_quiz_config($term);
        $rows = ll_get_words_by_category('Image Text Prompt Category', 'text', null, $config);
        $count = ll_get_words_by_category_count('Image Text Prompt Category', 'text', null, $config);

        $this->assertFalse((bool) ($config['learning_supported'] ?? true));
        $this->assertTrue(ll_tools_quiz_requires_image($config, 'text_translation'));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $count);
        $this->assertNotEmpty((string) ($rows[0]['image'] ?? ''));
        $this->assertSame((string) ($rows[0]['title'] ?? ''), (string) ($rows[0]['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($rows[0]['label'] ?? ''));
    }
}
