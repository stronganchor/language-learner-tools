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

    public function test_pipeline_recommendation_includes_chunk_word_ids(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(18);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['learning', 'practice', 'self-check', 'listening'],
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
        $this->assertSame([$fixture['category_id']], $recommendation['category_ids']);

        $session_word_ids = array_values(array_filter(array_map('intval', (array) ($recommendation['session_word_ids'] ?? [])), static function ($id): bool {
            return $id > 0;
        }));
        $this->assertNotEmpty($session_word_ids);
        $this->assertGreaterThanOrEqual(8, count($session_word_ids));
        $this->assertLessThanOrEqual(15, count($session_word_ids));
    }

    public function test_placement_known_category_biases_recommendation_to_self_check(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(6);

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

    public function test_review_chunk_prefers_full_single_category_when_category_fits_target_range(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([11, 4]);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
        ], $user_id);

        foreach ($fixture['category_ids'] as $category_id) {
            ll_tools_record_category_exposure($user_id, (int) $category_id, 'practice', $fixture['wordset_id'], 1);
        }

        $recommendation = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            $fixture['categories_payload']
        );

        $this->assertIsArray($recommendation);
        $this->assertSame('review_chunk', $recommendation['type']);
        $this->assertCount(1, (array) $recommendation['category_ids']);

        $selected_category_id = (int) $recommendation['category_ids'][0];
        $this->assertArrayHasKey($selected_category_id, $fixture['word_ids_by_category']);

        $expected_word_ids = array_values(array_unique(array_map('intval', (array) $fixture['word_ids_by_category'][$selected_category_id])));
        sort($expected_word_ids, SORT_NUMERIC);

        $session_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($recommendation['session_word_ids'] ?? [])))));
        sort($session_word_ids, SORT_NUMERIC);

        $this->assertGreaterThanOrEqual(8, count($session_word_ids));
        $this->assertLessThanOrEqual(15, count($session_word_ids));
        $this->assertSame($expected_word_ids, $session_word_ids);
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

    /**
     * @param int[] $word_counts
     * @return array{
     *   wordset_id:int,
     *   category_ids:int[],
     *   categories_payload:array<int,array<string,mixed>>,
     *   word_ids_by_category:array<int,int[]>
     * }
     */
    private function create_wordset_with_category_counts(array $word_counts): array
    {
        $wordset = wp_insert_term('Rec Wordset Multi ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_ids = [];
        $categories_payload = [];
        $word_ids_by_category = [];

        foreach (array_values($word_counts) as $index => $count_raw) {
            $count = max(0, (int) $count_raw);
            $category = wp_insert_term('Rec Category Multi ' . ($index + 1) . ' ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category));
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];

            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

            $category_ids[] = $category_id;
            $categories_payload[] = [
                'id' => $category_id,
                'name' => 'Category ' . ($index + 1),
                'slug' => sanitize_title('rec-category-multi-' . $category_id),
                'gender_supported' => false,
                'learning_supported' => true,
            ];
            $word_ids_by_category[$category_id] = [];

            for ($i = 1; $i <= $count; $i++) {
                $word_id = self::factory()->post->create([
                    'post_type'   => 'words',
                    'post_status' => 'publish',
                    'post_title'  => 'Rec Multi Word ' . ($index + 1) . '-' . $i . ' ' . wp_generate_password(4, false),
                ]);
                wp_set_post_terms($word_id, [$category_id], 'word-category', false);
                wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
                update_post_meta($word_id, 'word_translation', 'Rec Multi Translation ' . ($index + 1) . '-' . $i);

                $audio_post_id = self::factory()->post->create([
                    'post_type'   => 'word_audio',
                    'post_status' => 'publish',
                    'post_parent' => $word_id,
                    'post_title'  => 'Rec Multi Audio ' . ($index + 1) . '-' . $i,
                ]);
                update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/rec-multi-audio-' . ($index + 1) . '-' . $i . '.mp3');

                $word_ids_by_category[$category_id][] = (int) $word_id;
            }
        }

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
            'categories_payload' => $categories_payload,
            'word_ids_by_category' => $word_ids_by_category,
        ];
    }
}
