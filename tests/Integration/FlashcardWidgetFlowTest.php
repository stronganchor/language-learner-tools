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
            $this->assertStringContainsString('id="ll-tools-loading-status"', $output);
            $this->assertStringContainsString('id="ll-tools-load-more-categories"', $output);
            $this->assertStringContainsString('Loading quiz...', $output);
            $this->assertTrue(wp_script_is('ll-flc-main', 'enqueued'));
            $close_pos = strpos($output, 'id="ll-tools-close-flashcard"');
            $header_pos = strpos($output, 'id="ll-tools-flashcard-header"');
            $this->assertNotFalse($close_pos);
            $this->assertNotFalse($header_pos);
            $this->assertLessThan($header_pos, $close_pos, 'Close button should render before the popup header so results do not depend on header visibility.');

            $localized_data = wp_scripts()->get_data('ll-tools-flashcard-audio', 'data');
            $this->assertIsString($localized_data);
            $this->assertStringContainsString('llToolsFlashcardsData', $localized_data);
            $this->assertStringContainsString('Primary Flow Category', $localized_data);
            $this->assertStringContainsString('Flow Word', $localized_data);
            $this->assertStringContainsString('Flow Translation', $localized_data);
            $this->assertStringContainsString('"listeningCategoryLoadWindow":3', $localized_data);

            $localized_messages = wp_scripts()->get_data('ll-flc-util', 'data');
            $this->assertIsString($localized_messages);
            $this->assertStringContainsString('llToolsFlashcardsMessages', $localized_messages);
            $this->assertStringContainsString('closeQuizConfirm', $localized_messages);
            $this->assertStringContainsString('playAudio', $localized_messages);
            $this->assertStringContainsString('playWordAudio', $localized_messages);
            $this->assertStringContainsString('playOptionAudio', $localized_messages);
            $this->assertStringContainsString('pauseAudio', $localized_messages);
            $this->assertStringContainsString('starWord', $localized_messages);
            $this->assertStringContainsString('practiceSwitchLabel', $localized_messages);
            $this->assertStringContainsString('learningModeText', $localized_messages);
            $this->assertStringContainsString('practiceModeShort', $localized_messages);

            $this->assertStringContainsString('selfCheckSwitchLabel', $localized_messages);

            $scripts = wp_scripts();
            foreach (['ll-tools-flashcard-loader', 'll-tools-flashcard-options', 'll-flc-util'] as $handle) {
                $this->assertContains(
                    'll-tools-flashcard-audio',
                    (array) ($scripts->registered[$handle]->deps ?? []),
                    $handle . ' must load after the single-owner flashcard data bootstrap.'
                );
            }

            $registered_data = '';
            foreach ((array) $scripts->registered as $dependency) {
                if (is_object($dependency) && isset($dependency->extra['data'])) {
                    $registered_data .= "\n" . (string) $dependency->extra['data'];
                }
            }
            $this->assertSame(1, substr_count($registered_data, 'var llToolsFlashcardsData ='));
            $this->assertSame(1, substr_count($registered_data, 'var llToolsFlashcardsMessages ='));
            $this->assertFalse($scripts->get_data('ll-flc-main', 'data'));
            $this->assertFalse($scripts->get_data('ll-flc-mode-config', 'data'));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
