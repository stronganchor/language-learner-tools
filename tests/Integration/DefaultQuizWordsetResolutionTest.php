<?php
declare(strict_types=1);

final class DefaultQuizWordsetResolutionTest extends LL_Tools_TestCase
{
    public function test_source_category_uses_isolated_wordset_when_resolving_default_embed_context(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Biblical Hebrew', 'wordset', [
                'slug' => 'biblical-hebrew',
            ]);
            $category = wp_insert_term('Quiz 23.1', 'word-category', [
                'slug' => 'quiz-23-1',
            ]);

            $this->assertIsArray($wordset);
            $this->assertIsArray($category);

            $wordset_id = (int) $wordset['term_id'];
            $source_category_id = (int) $category['term_id'];

            update_term_meta($source_category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($source_category_id, 'll_quiz_option_type', 'text_title');

            $isolated_category_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_id);
            $this->assertGreaterThan(0, $isolated_category_id);
            $this->assertNotSame($source_category_id, $isolated_category_id);

            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'publish',
                'post_title'  => 'Hebrew Word',
            ]);
            wp_set_post_terms($word_id, [$isolated_category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

            $this->assertTrue(
                ll_can_category_generate_quiz($source_category_id, 1, [$wordset_id]),
                'The source category should be quizzable once its isolated copy has words in the target wordset.'
            );

            $this->assertSame($wordset_id, ll_get_default_wordset_id_for_category($source_category_id, 1));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }
}
