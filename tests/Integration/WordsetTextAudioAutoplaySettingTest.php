<?php
declare(strict_types=1);

final class WordsetTextAudioAutoplaySettingTest extends LL_Tools_TestCase
{
    public function test_wordset_text_audio_autoplay_setting_defaults_off_and_requires_single_wordset(): void
    {
        $wordset_a = wp_insert_term('Text Audio Autoplay A ' . wp_generate_password(6, false), 'wordset');
        $wordset_b = wp_insert_term('Text Audio Autoplay B ' . wp_generate_password(6, false), 'wordset');

        $this->assertIsArray($wordset_a);
        $this->assertIsArray($wordset_b);

        $wordset_a_id = (int) $wordset_a['term_id'];
        $wordset_b_id = (int) $wordset_b['term_id'];

        $this->assertFalse(ll_tools_should_autoplay_text_audio_answer_options([$wordset_a_id]));

        update_term_meta($wordset_a_id, LL_TOOLS_WORDSET_AUTOPLAY_TEXT_AUDIO_ANSWER_OPTIONS_META_KEY, 1);

        $this->assertTrue(ll_tools_should_autoplay_text_audio_answer_options([$wordset_a_id]));
        $this->assertFalse(
            ll_tools_should_autoplay_text_audio_answer_options([$wordset_a_id, $wordset_b_id]),
            'Mixed wordset scopes should keep text + audio answer autoplay off.'
        );
    }

    public function test_flashcard_widget_localizes_text_audio_autoplay_flag_for_wordset(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Widget Autoplay Wordset ' . wp_generate_password(6, false), 'wordset');
            $category = wp_insert_term('Widget Autoplay Category ' . wp_generate_password(6, false), 'word-category');

            $this->assertIsArray($wordset);
            $this->assertIsArray($category);

            $wordset_id = (int) $wordset['term_id'];
            $wordset_term = get_term($wordset_id, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $wordset_term);

            $category_id = (int) $category['term_id'];
            $category_term = get_term($category_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category_term);

            update_term_meta($wordset_id, LL_TOOLS_WORDSET_AUTOPLAY_TEXT_AUDIO_ANSWER_OPTIONS_META_KEY, 1);
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_audio');

            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'publish',
                'post_title'  => 'Widget Autoplay Word',
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            update_post_meta($word_id, 'word_translation', 'Widget Autoplay Translation');

            $audio_id = self::factory()->post->create([
                'post_type'   => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title'  => 'Widget Autoplay Word Audio',
            ]);
            update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/widget-autoplay-word.mp3');

            do_shortcode(sprintf(
                '[flashcard_widget category="%s" wordset="%s" wordset_fallback="false"]',
                $category_term->name,
                $wordset_term->slug
            ));

            $localized_main = wp_scripts()->get_data('ll-flc-main', 'data');
            $this->assertIsString($localized_main);
            $this->assertStringContainsString('"autoplayTextAudioAnswerOptions":"1"', $localized_main);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
