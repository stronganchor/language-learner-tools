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

    public function test_existing_usable_queue_skips_large_wordset_category_hydration(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([8]);
        $category_id = (int) $fixture['category_ids'][0];
        $word_ids = array_slice((array) $fixture['word_ids_by_category'][$category_id], 0, 5);
        $saved_queue = ll_tools_save_user_recommendation_queue([[
            'type' => 'review_chunk',
            'reason_code' => 'existing_queue_fast_path',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => $word_ids,
        ]], $user_id, (int) $fixture['wordset_id']);
        $this->assertCount(1, $saved_queue);
        $expected_queue = ll_tools_get_user_recommendation_queue($user_id, (int) $fixture['wordset_id']);
        $this->assertCount(8, $expected_queue);

        $catalog_builds = 0;
        $capture_catalog_build = static function () use (&$catalog_builds): void {
            $catalog_builds++;
        };
        add_action('ll_tools_user_study_categories_for_wordset_before_build', $capture_catalog_build);
        try {
            $payload = ll_tools_user_study_recommendation_payload(
                $user_id,
                (int) $fixture['wordset_id'],
                [$category_id],
                '',
                false
            );
        } finally {
            remove_action('ll_tools_user_study_categories_for_wordset_before_build', $capture_catalog_build);
        }

        $this->assertSame(0, $catalog_builds);
        $this->assertIsArray($payload['next_activity']);
        $this->assertSame('practice', (string) $payload['next_activity']['mode']);
        $this->assertSame($expected_queue, (array) $payload['recommendation_queue']);
    }

    public function test_missing_queue_refresh_hydrates_only_a_bounded_category_window(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts(array_fill(0, 20, 5));
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
        $this->assertCount(20, $category_ids);

        $full_catalog_builds = 0;
        $bounded_windows = [];
        $capture_full_catalog = static function () use (&$full_catalog_builds): void {
            $full_catalog_builds++;
        };
        $capture_bounded_window = static function (int $captured_wordset_id, array $window_ids, int $captured_user_id) use (&$bounded_windows): void {
            $bounded_windows[] = [
                'wordset_id' => $captured_wordset_id,
                'category_ids' => array_values(array_map('intval', $window_ids)),
                'user_id' => $captured_user_id,
            ];
        };
        $window_size = static function (int $size, int $filter_wordset_id, int $filter_user_id) use ($wordset_id, $user_id): int {
            if ($filter_wordset_id === $wordset_id && $filter_user_id === $user_id) {
                return 4;
            }
            return $size;
        };

        add_action('ll_tools_user_study_categories_for_wordset_before_build', $capture_full_catalog);
        add_action('ll_tools_user_study_recommendation_categories_for_wordset_before_build', $capture_bounded_window, 10, 3);
        add_filter('ll_tools_user_study_recommendation_category_window_size', $window_size, 10, 3);
        try {
            $payload = ll_tools_user_study_recommendation_payload(
                $user_id,
                $wordset_id,
                $category_ids,
                '',
                false
            );
        } finally {
            remove_action('ll_tools_user_study_categories_for_wordset_before_build', $capture_full_catalog);
            remove_action('ll_tools_user_study_recommendation_categories_for_wordset_before_build', $capture_bounded_window, 10);
            remove_filter('ll_tools_user_study_recommendation_category_window_size', $window_size, 10);
        }

        $this->assertSame(0, $full_catalog_builds, 'A cold normal-page refresh must not hydrate the full flashcard study catalog.');
        $this->assertCount(1, $bounded_windows);
        $this->assertSame($wordset_id, (int) $bounded_windows[0]['wordset_id']);
        $this->assertSame($user_id, (int) $bounded_windows[0]['user_id']);
        $this->assertCount(4, (array) $bounded_windows[0]['category_ids']);

        $window_lookup = array_fill_keys(array_map('intval', (array) $bounded_windows[0]['category_ids']), true);
        $queue = (array) ($payload['recommendation_queue'] ?? []);
        $this->assertNotEmpty($queue);
        foreach ($queue as $activity) {
            $this->assertIsArray($activity);
            foreach ((array) ($activity['category_ids'] ?? []) as $activity_category_id) {
                $this->assertArrayHasKey((int) $activity_category_id, $window_lookup);
            }
            $this->assert_recommendation_word_count_within_bounds($activity, 'bounded cold queue');
        }
        $this->assertTrue(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));
    }

    public function test_incomplete_bounded_refresh_does_not_persist_a_recommendation_queue(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([5]);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
        $this->assertCount(1, $category_ids);
        $this->assertFalse(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));

        $fail_renderable_terms = static function ($terms, WP_Term_Query $query) {
            $taxonomies = array_map('strval', (array) ($query->query_vars['taxonomy'] ?? []));
            if (!in_array('word-category', $taxonomies, true)) {
                return $terms;
            }
            $functions = array_values(array_filter(array_map(static function (array $frame): string {
                return isset($frame['function']) ? (string) $frame['function'] : '';
            }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 35))));
            if (in_array('ll_tools_user_study_renderable_word_ids_by_category', $functions, true)) {
                return new WP_Error('forced_recommendation_source_failure', 'Forced recommendation source failure.');
            }
            return $terms;
        };

        add_filter('terms_pre_query', $fail_renderable_terms, 10, 2);
        try {
            $payload = ll_tools_user_study_recommendation_payload(
                $user_id,
                $wordset_id,
                $category_ids,
                '',
                false
            );
        } finally {
            remove_filter('terms_pre_query', $fail_renderable_terms, 10);
        }

        $this->assertNull($payload['next_activity']);
        $this->assertSame([], (array) $payload['recommendation_queue']);
        $this->assertFalse(
            metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META),
            'A partial renderable-word scan must not be persisted as an authoritative empty queue.'
        );
    }

    public function test_visibility_read_failure_does_not_expose_private_category_or_persist_queue(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->create_wordset_with_category_counts([5]);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_ids'][0];
        $privacy_source_complete = true;
        $privacy_category_id = (int) ll_tools_get_category_isolation_source_id(
            $category_id,
            $privacy_source_complete
        );
        $this->assertTrue($privacy_source_complete);
        $this->assertGreaterThan(0, $privacy_category_id);
        update_term_meta($privacy_category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        delete_term_meta($privacy_category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY);
        $this->assertFalse(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));
        clean_term_cache([$category_id, $privacy_category_id], 'word-category');
        wp_cache_delete($category_id, 'term_meta');
        wp_cache_delete($privacy_category_id, 'term_meta');

        $injected = false;
        $missing_table = $wpdb->prefix . 'll_tools_missing_recommendation_visibility_meta';
        $break_visibility_meta = static function (string $query) use (
            $wpdb,
            $category_id,
            $missing_table,
            &$injected
        ): string {
            if (
                !$injected
                && stripos($query, 'FROM ' . $wpdb->termmeta) !== false
                && preg_match('/\\bterm_id\\s+IN\\s*\\([^)]*\\b' . preg_quote((string) $category_id, '/') . '\\b[^)]*\\)/i', $query) === 1
            ) {
                $injected = true;
                return str_replace($wpdb->termmeta, $missing_table, $query);
            }
            return $query;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_visibility_meta, 10, 1);
        try {
            $payload = ll_tools_user_study_recommendation_payload(
                $user_id,
                $wordset_id,
                [$category_id],
                '',
                false
            );
        } finally {
            remove_filter('query', $break_visibility_meta, 10);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected, 'The fixture must interrupt the category visibility read.');
        $this->assertNull($payload['next_activity']);
        $this->assertSame([], (array) $payload['recommendation_queue']);
        $this->assertFalse(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));

        $retry_complete = false;
        $this->assertSame(
            [],
            ll_tools_user_study_recommendation_categories_for_wordset(
                $wordset_id,
                [$category_id],
                $user_id,
                $retry_complete
            )
        );
        $this->assertTrue($retry_complete, 'The retry must distinguish a verified private category from a failed read.');
    }

    public function test_incomplete_isolation_remap_never_persists_source_category_fallback(): void
    {
        global $wpdb;

        $original_isolation_option = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        try {
            $user_id = self::factory()->user->create(['role' => 'subscriber']);
            wp_set_current_user($user_id);
            $fixture = $this->create_wordset_with_category_counts([5]);
            $wordset_id = (int) $fixture['wordset_id'];
            $isolated_category_id = (int) $fixture['category_ids'][0];
            $source_complete = true;
            $source_category_id = (int) ll_tools_get_category_isolation_source_id(
                $isolated_category_id,
                $source_complete
            );
            $this->assertTrue($source_complete);
            $this->assertGreaterThan(0, $source_category_id);
            $this->assertNotSame($source_category_id, $isolated_category_id);

            foreach ((array) ($fixture['word_ids_by_category'][$isolated_category_id] ?? []) as $word_id) {
                wp_set_post_terms((int) $word_id, [$isolated_category_id], 'word-category', false);
            }
            ll_tools_bump_category_cache_version([$source_category_id, $isolated_category_id]);
            $this->assertFalse(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));
            clean_term_cache([$source_category_id, $isolated_category_id], 'word-category');
            wp_cache_delete($source_category_id, 'term_meta');
            wp_cache_delete($isolated_category_id, 'term_meta');

            $injected = false;
            $missing_table = $wpdb->prefix . 'll_tools_missing_recommendation_remap_meta';
            $break_remap_meta = static function (string $query) use (
                $wpdb,
                $source_category_id,
                $missing_table,
                &$injected
            ): string {
                if (
                    !$injected
                    && stripos($query, 'FROM ' . $wpdb->termmeta) !== false
                    && preg_match('/\\bterm_id\\s+IN\\s*\\([^)]*\\b' . preg_quote((string) $source_category_id, '/') . '\\b[^)]*\\)/i', $query) === 1
                ) {
                    $injected = true;
                    return str_replace($wpdb->termmeta, $missing_table, $query);
                }
                return $query;
            };

            $previous_suppress_errors = $wpdb->suppress_errors(true);
            add_filter('query', $break_remap_meta, 10, 1);
            try {
                $failed_payload = ll_tools_user_study_recommendation_payload(
                    $user_id,
                    $wordset_id,
                    [$source_category_id],
                    '',
                    false
                );
            } finally {
                remove_filter('query', $break_remap_meta, 10);
                $wpdb->suppress_errors($previous_suppress_errors);
                $wpdb->last_error = '';
            }

            $this->assertTrue($injected, 'The fixture must interrupt the source-to-owned-category remap.');
            $this->assertNull($failed_payload['next_activity']);
            $this->assertSame([], (array) $failed_payload['recommendation_queue']);
            $this->assertFalse(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));

            $retry_payload = ll_tools_user_study_recommendation_payload(
                $user_id,
                $wordset_id,
                [$source_category_id],
                '',
                false
            );
            $retry_queue = (array) ($retry_payload['recommendation_queue'] ?? []);
            $this->assertNotEmpty($retry_queue);
            foreach ($retry_queue as $activity) {
                $activity_category_ids = array_values(array_map('intval', (array) ($activity['category_ids'] ?? [])));
                $this->assertNotContains($source_category_id, $activity_category_ids);
                $this->assertContains($isolated_category_id, $activity_category_ids);
            }
            $this->assertTrue(metadata_exists('user', $user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META));
        } finally {
            if ($original_isolation_option === null) {
                delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
            } else {
                update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $original_isolation_option, false);
            }
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
        $this->assert_recommendation_word_count_within_bounds($recommendation, 'pipeline');
    }

    public function test_pipeline_recommendation_scope_does_not_materialize_word_audio_posts(): void
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

        $word_audio_queries = 0;
        $capture_word_audio_query = static function (WP_Query $query) use (&$word_audio_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? array_map('strval', $post_type) : [(string) $post_type];
            if (in_array('word_audio', $post_types, true)) {
                $word_audio_queries++;
            }
        };

        add_action('pre_get_posts', $capture_word_audio_query);
        try {
            $recommendation = ll_tools_build_next_activity_recommendation(
                $user_id,
                $fixture['wordset_id'],
                [$fixture['category_id']],
                [$fixture['category_payload']]
            );
        } finally {
            remove_action('pre_get_posts', $capture_word_audio_query);
        }

        $this->assertIsArray($recommendation);
        $this->assertSame('pipeline', $recommendation['type']);
        $this->assert_recommendation_word_count_within_bounds($recommendation, 'pipeline no audio rows');
        $this->assertSame(0, $word_audio_queries, 'Recommendation scope loading should not hydrate word_audio post rows.');
    }

    public function test_completion_map_counts_outcome_only_progress_as_studied(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([5, 5]);
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $this->assertCount(2, $category_ids);

        $studied_category_id = (int) $category_ids[0];
        $new_category_id = (int) $category_ids[1];
        $studied_word_ids = array_values(array_map('intval', (array) ($fixture['word_ids_by_category'][$studied_category_id] ?? [])));
        $this->assertCount(5, $studied_word_ids);

        foreach ($studied_word_ids as $index => $word_id) {
            $this->seedWordProgressRow($user_id, $word_id, $studied_category_id, (int) $fixture['wordset_id'], [
                'total_coverage' => 0,
                'correct_clean' => ($index % 2 === 0) ? 1 : 0,
                'incorrect' => ($index % 2 === 0) ? 0 : 1,
            ]);
        }

        $completion = ll_tools_recommendation_category_completion_map(
            $user_id,
            (int) $fixture['wordset_id'],
            [$studied_category_id, $new_category_id]
        );

        $this->assertSame(5, (int) ($completion[$studied_category_id]['total_words'] ?? 0));
        $this->assertSame(5, (int) ($completion[$studied_category_id]['studied_words'] ?? 0));
        $this->assertSame(0, (int) ($completion[$studied_category_id]['unstudied_words'] ?? -1));
        $this->assertTrue((bool) ($completion[$studied_category_id]['is_fully_studied'] ?? false));
        $this->assertTrue((bool) ($completion[$studied_category_id]['has_any_studied'] ?? false));

        $this->assertSame(5, (int) ($completion[$new_category_id]['total_words'] ?? 0));
        $this->assertSame(0, (int) ($completion[$new_category_id]['studied_words'] ?? -1));
        $this->assertSame(5, (int) ($completion[$new_category_id]['unstudied_words'] ?? 0));
        $this->assertFalse((bool) ($completion[$new_category_id]['is_fully_studied'] ?? true));
        $this->assertFalse((bool) ($completion[$new_category_id]['has_any_studied'] ?? true));
    }

    public function test_pipeline_recommendation_rotates_to_next_category_after_last_recommendation(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([12, 12, 12]);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['learning', 'practice', 'self-check', 'listening'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
        ], $user_id);

        $first = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            $fixture['categories_payload']
        );

        $this->assertIsArray($first);
        $this->assertSame('pipeline', $first['type']);
        $this->assertSame('learning', $first['mode']);

        ll_tools_save_user_last_recommendation_activity($first, $user_id, (int) $fixture['wordset_id']);

        $second = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            $fixture['categories_payload']
        );

        $this->assertIsArray($second);
        $this->assertSame('pipeline', $second['type']);

        $first_category_id = (int) (($first['category_ids'][0] ?? 0));
        $second_category_id = (int) (($second['category_ids'][0] ?? 0));

        $this->assertGreaterThan(0, $first_category_id);
        $this->assertGreaterThan(0, $second_category_id);
        $this->assertNotSame($first_category_id, $second_category_id);
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

        $this->assert_recommendation_word_count_within_bounds($recommendation, 'single-category');
        $this->assertSame($expected_word_ids, $session_word_ids);
    }

    public function test_review_chunk_spreads_equal_score_words_across_multiple_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_with_category_counts([20, 20, 20]);

        ll_tools_save_user_study_goals([
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [],
            'daily_new_word_target' => 2,
        ], $user_id);

        foreach ($fixture['category_ids'] as $category_id) {
            ll_tools_record_category_exposure($user_id, (int) $category_id, 'practice', (int) $fixture['wordset_id'], 1);
        }

        $recommendation = ll_tools_build_next_activity_recommendation(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            $fixture['categories_payload']
        );

        $this->assertIsArray($recommendation);
        $this->assertSame('review_chunk', $recommendation['type']);
        $this->assert_recommendation_word_count_within_bounds($recommendation, 'balanced-multi-category');

        $session_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($recommendation['session_word_ids'] ?? [])), static function ($id): bool {
            return $id > 0;
        })));
        $session_category_lookup = [];
        foreach ((array) ($fixture['word_ids_by_category'] ?? []) as $cid_raw => $category_word_ids) {
            $category_id = (int) $cid_raw;
            $category_word_lookup = array_fill_keys(array_values(array_filter(array_map('intval', (array) $category_word_ids), static function ($id): bool {
                return $id > 0;
            })), true);
            foreach ($session_word_ids as $word_id) {
                if (!empty($category_word_lookup[$word_id])) {
                    $session_category_lookup[$category_id] = true;
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, count($session_category_lookup));
        $this->assertGreaterThanOrEqual(2, count((array) ($recommendation['category_ids'] ?? [])));
    }

    public function test_recommendation_returns_null_when_scope_cannot_reach_minimum_session_words(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->create_wordset_category_with_words(4);

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

        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : 0;
        if ($effective_category_id <= 0) {
            $effective_category_id = $category_id;
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'category_payload' => [
                'id' => $effective_category_id,
                'name' => 'Category',
                'slug' => sanitize_title('rec-category-' . $effective_category_id),
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

            $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
                ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
                : 0;
            if ($effective_category_id <= 0) {
                $effective_category_id = $category_id;
            }

            $category_ids[] = $effective_category_id;
            $categories_payload[] = [
                'id' => $effective_category_id,
                'name' => 'Category ' . ($index + 1),
                'slug' => sanitize_title('rec-category-multi-' . $effective_category_id),
                'gender_supported' => false,
                'learning_supported' => true,
            ];
            $word_ids_by_category[$effective_category_id] = [];

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

                $word_ids_by_category[$effective_category_id][] = (int) $word_id;
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
            : [5, 15];
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
