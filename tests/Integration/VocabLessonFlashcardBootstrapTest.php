<?php
declare(strict_types=1);

final class VocabLessonFlashcardBootstrapTest extends LL_Tools_TestCase
{
    public function test_vocab_lesson_page_bootstraps_flashcard_launcher_by_default(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Lesson Launcher Wordset', 'wordset', ['slug' => 'lesson-launcher-wordset']);
            $this->assertIsArray($wordset);
            $wordset_id = (int) $wordset['term_id'];

            $category = wp_insert_term('Lesson Launcher Category', 'word-category', ['slug' => 'lesson-launcher-category']);
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];

            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Merhaba',
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            update_post_meta($word_id, 'word_translation', 'Hello');

            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => 'Lesson Launcher',
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

            $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lesson_id);

            $this->assertTrue(is_singular('ll_vocab_lesson'));

            ll_tools_vocab_lesson_enqueue_assets();

            $this->assertTrue(wp_script_is('ll-flc-main', 'enqueued'));
            $this->assertTrue(wp_script_is('ll-confetti', 'enqueued'));
            $this->assertNotFalse(has_action('wp_footer', 'll_qpg_print_flashcard_shell_once'));

            ob_start();
            ll_qpg_print_flashcard_shell_once();
            $footer = (string) ob_get_clean();

            $this->assertStringContainsString('id="ll-tools-flashcard-container"', $footer);
            $this->assertStringContainsString('window.llOpenFlashcardForCategory = function', $footer);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
