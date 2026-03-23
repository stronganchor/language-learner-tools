<?php
declare(strict_types=1);

final class WordsetProgressMobileLegendTest extends LL_Tools_TestCase
{
    public function test_progress_view_renders_mobile_words_table_legend(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset_id = $this->createWordsetFixture();
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'progress');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('data-ll-wordset-progress-mobile-legend', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--starred', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--mastered', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--studied', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--new', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--hard', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--seen', $html);
        $this->assertStringContainsString('ll-wordset-progress-mobile-legend__item--wrong', $html);
        $this->assertStringContainsString('>Key<', $html);
    }

    private function createWordsetFixture(): int
    {
        $wordset = wp_insert_term('Progress Legend Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_term = wp_insert_term('Progress Legend Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Legend Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Legend Translation');

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Legend Audio',
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/legend-word.mp3');

        return $wordset_id;
    }
}
