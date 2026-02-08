<?php
declare(strict_types=1);

final class FlashcardWidgetFlowTest extends LL_Tools_TestCase
{
    public function test_flashcard_widget_renders_with_initial_word_data(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
            $category = wp_insert_term('Primary Flow Category', 'word-category');
            $this->assertFalse(is_wp_error($category));
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];

            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'publish',
                'post_title'  => 'Flow Word',
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            update_post_meta($word_id, 'word_translation', 'Flow Translation');

            $audio_id = self::factory()->post->create([
                'post_type'   => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title'  => 'Flow Word',
            ]);
            update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/flow-word.mp3');

            $output = do_shortcode('[flashcard_widget category="Primary Flow Category"]');

            $this->assertStringContainsString('id="ll-tools-flashcard-container"', $output);
            $this->assertTrue(wp_script_is('ll-flc-main', 'enqueued'));

            $localized_main = wp_scripts()->get_data('ll-flc-main', 'data');
            $this->assertIsString($localized_main);
            $this->assertStringContainsString('llToolsFlashcardsData', $localized_main);
            $this->assertStringContainsString('Primary Flow Category', $localized_main);
            $this->assertStringContainsString('Flow Word', $localized_main);
            $this->assertStringContainsString('Flow Translation', $localized_main);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}

