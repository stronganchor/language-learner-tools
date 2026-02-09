<?php
declare(strict_types=1);

final class UserProgressRecommendationTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_unseen_category_recommends_pipeline_learning_first(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(2);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['learning', 'listening', 'practice', 'self-check'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
        ], $user_id);

        $recommendation = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            [$fixture['category_id']],
            [$fixture['category_payload']]
        );

        $this->assertIsArray($recommendation);
        $this->assertSame('pipeline', $recommendation['type']);
        $this->assertContains($recommendation['mode'], ['learning', 'listening', 'practice', 'self-check']);
        $this->assertSame('pipeline_unseen_mode', $recommendation['reason_code']);
        $this->assertSame([$fixture['category_id']], $recommendation['category_ids']);
    }

    public function test_placement_known_category_biases_recommendation_to_self_check(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(4);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['learning', 'practice', 'self-check', 'listening'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [$fixture['category_id']],
            'daily_new_word_target' => 2,
        ], $user_id);

        $recommendation = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            [$fixture['category_id']],
            [$fixture['category_payload']]
        );

        $this->assertIsArray($recommendation);
        $this->assertContains($recommendation['type'], ['review_chunk', 'fallback']);
        $this->assertSame('self-check', $recommendation['mode']);
        if ($recommendation['type'] === 'review_chunk') {
            $this->assertNotEmpty($recommendation['session_word_ids']);
        }
    }

    /**
     * @return array{wordset_id:int,category_id:int,category_payload:array<string,mixed>}
     */
    private function create_wordset_category_with_words(int $word_count): array
    {
        $wordset = wp_insert_term('Rec Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Rec Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        // Keep test data independent from audio availability.
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        for ($i = 1; $i <= $word_count; $i++) {
            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'publish',
                'post_title'  => 'Rec Word ' . $i . ' ' . wp_generate_password(4, false),
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            update_post_meta($word_id, 'word_translation', 'Rec Translation ' . $i);

            $audio_post_id = self::factory()->post->create([
                'post_type'   => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title'  => 'Rec Audio ' . $i,
            ]);
            update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/rec-audio-' . $i . '.mp3');
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'category_payload' => [
                'id' => $category_id,
                'name' => 'Category',
                'slug' => sanitize_title('rec-category-' . $category_id),
                'gender_supported' => false,
                'learning_supported' => true,
            ],
        ];
    }
}
