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
        $fixture = $this->create_wordset_category_with_words(12);

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
        $fixture = $this->create_wordset_category_with_words(10);

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
        $this->assertContains($recommendation['type'], ['review_chunk', 'priority_focus', 'pipeline']);
        $this->assertSame('self-check', $recommendation['mode']);
        $this->assert_recommendation_word_count_within_bounds($recommendation, 'placement-known');
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

    public function test_recommendation_returns_null_when_scope_cannot_reach_minimum_session_words(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(6);

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

        $this->assertNull($recommendation);
    }

    public function test_recommendations_and_queue_enforce_session_bounds_across_randomized_inputs(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([12, 13, 14, 15, 16, 17]);
        $all_word_ids = [];
        foreach ((array) ($fixture['word_ids_by_category'] ?? []) as $category_word_ids) {
            $all_word_ids = array_merge($all_word_ids, array_values(array_filter(array_map('intval', (array) $category_word_ids), static function ($id): bool {
                return $id > 0;
            })));
        }
        $all_word_ids = array_values(array_unique($all_word_ids));
        sort($all_word_ids, SORT_NUMERIC);

        $starred_word_ids = array_slice($all_word_ids, 0, 5);
        ll_tools_save_user_study_state([
            'wordset_id' => (int) $fixture['wordset_id'],
            'category_ids' => (array) $fixture['category_ids'],
            'starred_word_ids' => $starred_word_ids,
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        foreach ((array) ($fixture['word_ids_by_category'] ?? []) as $cid_raw => $category_word_ids) {
            $category_id = (int) $cid_raw;
            $ids = array_values(array_filter(array_map('intval', (array) $category_word_ids), static function ($id): bool {
                return $id > 0;
            }));
            if (empty($ids)) {
                continue;
            }
            foreach (array_slice($ids, 0, 2) as $word_id) {
                $this->seedWordProgressRow($user_id, (int) $word_id, $category_id, (int) $fixture['wordset_id'], [
                    'total_coverage' => 4,
                    'coverage_practice' => 2,
                    'coverage_learning' => 1,
                    'coverage_listening' => 1,
                    'correct_clean' => 1,
                    'correct_after_retry' => 1,
                    'incorrect' => 2,
                    'lapse_count' => 1,
                    'stage' => 1,
                ]);
            }
            foreach (array_slice($ids, 2, 2) as $word_id) {
                $this->seedWordProgressRow($user_id, (int) $word_id, $category_id, (int) $fixture['wordset_id'], [
                    'total_coverage' => 9,
                    'coverage_practice' => 4,
                    'coverage_learning' => 2,
                    'coverage_listening' => 2,
                    'coverage_self_check' => 1,
                    'correct_clean' => 7,
                    'correct_after_retry' => 1,
                    'incorrect' => 0,
                    'lapse_count' => 0,
                    'stage' => 6,
                ]);
            }
        }

        $focus_options = ['', 'new', 'studied', 'learned', 'starred', 'hard'];
        $mode_options = ['learning', 'practice', 'listening', 'self-check'];

        mt_srand(20260302);
        $iterations = 64;
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $scope_category_ids = $this->pick_random_category_subset((array) $fixture['category_ids']);
            $scope_payload = $this->filter_category_payload((array) $fixture['categories_payload'], $scope_category_ids);

            $enabled_modes = $this->pick_random_mode_subset($mode_options);
            $priority_focus = $focus_options[mt_rand(0, count($focus_options) - 1)];

            ll_tools_save_user_study_goals([
                'enabled_modes' => $enabled_modes,
                'ignored_category_ids' => [],
                'preferred_wordset_ids' => [],
                'placement_known_category_ids' => [],
                'daily_new_word_target' => 2,
                'priority_focus' => $priority_focus,
                'prioritize_new_words' => false,
                'prioritize_studied_words' => false,
                'prioritize_learned_words' => false,
                'prefer_starred_words' => false,
                'prefer_hard_words' => false,
            ], $user_id);

            $preferred_mode = $enabled_modes[array_rand($enabled_modes)];
            $recommendation = ll_tools_build_next_activity_recommendation(
                $user_id,
                (int) $fixture['wordset_id'],
                $scope_category_ids,
                $scope_payload,
                ['preferred_mode' => (string) $preferred_mode]
            );
            $this->assertIsArray($recommendation, 'Expected recommendation for randomized iteration ' . $iteration . '.');
            $this->assert_recommendation_word_count_within_bounds($recommendation, 'recommendation iteration ' . $iteration);

            $queue = ll_tools_refresh_user_recommendation_queue(
                $user_id,
                (int) $fixture['wordset_id'],
                $scope_category_ids,
                $scope_payload,
                8,
                ['preferred_mode' => (string) $preferred_mode]
            );

            $this->assertNotEmpty($queue, 'Expected non-empty queue for randomized iteration ' . $iteration . '.');
            foreach ((array) $queue as $queue_index => $activity) {
                $this->assertIsArray($activity, 'Queue item should be an array at iteration ' . $iteration . ' item ' . $queue_index . '.');
                $this->assert_recommendation_word_count_within_bounds((array) $activity, 'queue iteration ' . $iteration . ' item ' . $queue_index);
            }
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

    private function assert_recommendation_word_count_within_bounds(array $recommendation, string $context = ''): void
    {
        [$min_words, $max_words] = function_exists('ll_tools_recommendation_session_word_bounds')
            ? ll_tools_recommendation_session_word_bounds()
            : [8, 15];
        $session_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($recommendation['session_word_ids'] ?? [])), static function ($id): bool {
            return $id > 0;
        })));
        $message_prefix = $context === '' ? '' : ($context . ': ');
        $this->assertGreaterThanOrEqual($min_words, count($session_word_ids), $message_prefix . 'session word count below minimum.');
        $this->assertLessThanOrEqual($max_words, count($session_word_ids), $message_prefix . 'session word count above maximum.');
    }

    /**
     * @param int[] $category_ids
     * @return int[]
     */
    private function pick_random_category_subset(array $category_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function ($id): bool {
            return $id > 0;
        })));
        $this->assertNotEmpty($ids);
        shuffle($ids);
        $take = mt_rand(1, count($ids));
        return array_slice($ids, 0, $take);
    }

    /**
     * @param array<int,array<string,mixed>> $categories_payload
     * @param int[] $category_ids
     * @return array<int,array<string,mixed>>
     */
    private function filter_category_payload(array $categories_payload, array $category_ids): array
    {
        $lookup = array_fill_keys(array_values(array_filter(array_map('intval', $category_ids), static function ($id): bool {
            return $id > 0;
        })), true);
        return array_values(array_filter($categories_payload, static function ($row) use ($lookup): bool {
            if (!is_array($row)) {
                return false;
            }
            $cid = isset($row['id']) ? (int) $row['id'] : 0;
            return $cid > 0 && !empty($lookup[$cid]);
        }));
    }

    /**
     * @param string[] $modes
     * @return string[]
     */
    private function pick_random_mode_subset(array $modes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $modes), static function ($mode): bool {
            return $mode !== '';
        })));
        $this->assertNotEmpty($normalized);
        shuffle($normalized);
        $take = mt_rand(1, count($normalized));
        return array_slice($normalized, 0, $take);
    }

    private function seedWordProgressRow(int $user_id, int $word_id, int $category_id, int $wordset_id, array $overrides): void
    {
        global $wpdb;
        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];

        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data, [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ]);
        $this->assertNotFalse($inserted);
    }
}
