<?php
declare(strict_types=1);

final class UserStudyAnalyticsTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
        if (function_exists('ll_register_part_of_speech_taxonomy')) {
            ll_register_part_of_speech_taxonomy();
        }
    }

    public function test_build_analytics_payload_includes_summary_categories_and_words(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a, $word_b, $word_c] = $fixture['word_ids'];
        [$cat_a, $cat_b] = $fixture['category_ids'];

        $this->seedWordProgressRow($user_id, $word_a, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 6,
            'correct_clean' => 4,
            'correct_after_retry' => 0,
            'incorrect' => 1,
            'lapse_count' => 0,
            'stage' => 6,
        ]);
        $this->seedWordProgressRow($user_id, $word_b, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 5,
            'correct_clean' => 1,
            'correct_after_retry' => 1,
            'incorrect' => 4,
            'lapse_count' => 3,
            'stage' => 1,
        ]);
        // Leave $word_c without a progress row so it remains "new".

        ll_tools_record_category_exposure($user_id, $cat_a, 'practice', $fixture['wordset_id'], 8);
        ll_tools_record_category_exposure($user_id, $cat_a, 'learning', $fixture['wordset_id'], 2);
        ll_tools_record_category_exposure($user_id, $cat_b, 'learning', $fixture['wordset_id'], 1);

        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => [$word_b],
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $stats = ll_tools_process_progress_events_batch($user_id, [[
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'word_id' => $word_a,
            'category_id' => $cat_a,
            'wordset_id' => $fixture['wordset_id'],
            'payload' => [],
        ]]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            14
        );

        $this->assertSame(10, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertSame(1, (int) ($analytics['summary']['mastered_words'] ?? 0));
        $this->assertSame(2, (int) ($analytics['summary']['studied_words'] ?? 0));
        $this->assertSame(8, (int) ($analytics['summary']['new_words'] ?? 0));
        $this->assertSame(1, (int) ($analytics['summary']['starred_words'] ?? 0));
        $this->assertNotEmpty($analytics['categories']);
        $this->assertCount(2, (array) ($analytics['categories'] ?? []));
        $this->assertCount(10, (array) ($analytics['words'] ?? []));
        $this->assertCount(14, (array) ($analytics['daily_activity']['days'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($analytics['daily_activity']['max_rounds'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($analytics['daily_activity']['max_events'] ?? 0));

        $words_by_id = [];
        foreach ((array) ($analytics['words'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wid = isset($row['id']) ? (int) $row['id'] : 0;
            if ($wid > 0) {
                $words_by_id[$wid] = $row;
            }
        }

        $this->assertSame('mastered', (string) ($words_by_id[$word_a]['status'] ?? ''));
        $this->assertSame('studied', (string) ($words_by_id[$word_b]['status'] ?? ''));
        $this->assertSame('new', (string) ($words_by_id[$word_c]['status'] ?? ''));
        $this->assertNotSame('', (string) ($words_by_id[$word_a]['audio_url'] ?? ''));
        $this->assertSame('isolation', (string) ($words_by_id[$word_a]['audio_recording_type'] ?? ''));
    }

    public function test_user_progress_events_schema_includes_scoped_daily_activity_indexes(): void
    {
        global $wpdb;
        $events_table = ll_tools_user_progress_table_names()['events'];
        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$events_table}", ARRAY_A);
        $index_names = [];
        foreach ((array) $index_rows as $row) {
            $key_name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($key_name !== '') {
                $index_names[$key_name] = true;
            }
        }

        $this->assertArrayHasKey('idx_user_wordset_created', $index_names);
        $this->assertArrayHasKey('idx_user_category_created', $index_names);
    }

    public function test_build_analytics_payload_does_not_hydrate_word_audio_posts(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_audio_queries = 0;
        $capture_word_audio_query = static function (WP_Query $query) use (&$word_audio_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? $post_type : [$post_type];
            if (in_array('word_audio', array_map('strval', $post_types), true)) {
                $word_audio_queries++;
            }
        };

        add_action('pre_get_posts', $capture_word_audio_query);
        try {
            $analytics = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $fixture['wordset_id'],
                $fixture['category_ids'],
                14
            );
        } finally {
            remove_action('pre_get_posts', $capture_word_audio_query);
        }

        $this->assertSame(10, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertCount(10, (array) ($analytics['words'] ?? []));
        $this->assertSame(0, $word_audio_queries, 'Analytics should use the lightweight audio summary map instead of hydrating word_audio posts.');

        $rows_with_audio = array_values(array_filter((array) ($analytics['words'] ?? []), static function ($row): bool {
            return is_array($row) && trim((string) ($row['audio_url'] ?? '')) !== '';
        }));
        $this->assertNotEmpty($rows_with_audio);
        $this->assertSame('isolation', (string) ($rows_with_audio[0]['audio_recording_type'] ?? ''));
    }

    public function test_build_analytics_payload_can_omit_word_rows_for_summary_refresh(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a, $word_b] = $fixture['word_ids'];
        [$cat_a] = $fixture['category_ids'];

        $this->seedWordProgressRow($user_id, $word_a, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 6,
            'correct_clean' => 4,
            'stage' => 6,
        ]);
        $this->seedWordProgressRow($user_id, $word_b, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 2,
            'incorrect' => 2,
            'lapse_count' => 2,
            'stage' => 1,
        ]);
        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => [$word_b],
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $full = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            14
        );
        $summary_queries = [];
        $query_capture = static function (string $query) use (&$summary_queries): string {
            $summary_queries[] = $query;
            return $query;
        };
        add_filter('query', $query_capture);
        try {
            $summary_only = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $fixture['wordset_id'],
                $fixture['category_ids'],
                14,
                false,
                ['summary_only' => true]
            );
        } finally {
            remove_filter('query', $query_capture);
        }

        $this->assertFalse((bool) ($full['words_omitted'] ?? false));
        $this->assertSame(10, (int) ($summary_only['summary']['total_words'] ?? 0));
        $this->assertSame(1, (int) ($summary_only['summary']['mastered_words'] ?? 0));
        $this->assertSame(1, (int) ($summary_only['summary']['hard_words'] ?? 0));
        $this->assertSame(1, (int) ($summary_only['summary']['starred_words'] ?? 0));
        $this->assertSame($full['scope'], $summary_only['scope']);
        $this->assertSame($full['summary'], $summary_only['summary']);
        $this->assertSame($full['gender_progress'], $summary_only['gender_progress']);
        $this->assertCount(2, (array) ($summary_only['categories'] ?? []));
        $this->assertSame($full['categories'], $summary_only['categories']);
        $this->assertSame([], (array) ($summary_only['words'] ?? []));
        $this->assertTrue((bool) ($summary_only['words_omitted'] ?? false));
        $this->assertSame($full['daily_activity'], $summary_only['daily_activity']);

        $progress_table = ll_tools_user_progress_table_names()['words'];
        $joined_queries = implode("\n", $summary_queries);
        $this->assertStringNotContainsStringIgnoringCase('SELECT * FROM ' . $progress_table, $joined_queries);
        $this->assertStringNotContainsString('word_translation', $joined_queries);
        $this->assertStringNotContainsString('_ll_autopicked_image_id', $joined_queries);
        global $wpdb;
        $this->assertStringNotContainsString("SELECT ID, post_type FROM {$wpdb->posts}", $joined_queries);
    }

    public function test_analytics_membership_batches_fail_closed_on_an_early_query_error(): void
    {
        global $wpdb;

        $membership_queries = 0;
        $break_first_membership_query = static function (string $query) use (&$membership_queries, $wpdb): string {
            if (stripos($query, "SELECT ID, post_type FROM {$wpdb->posts}") === false) {
                return $query;
            }
            $membership_queries++;
            if ($membership_queries === 1) {
                return "SELECT ID, post_type FROM {$wpdb->prefix}ll_tools_missing_membership_table";
            }
            return $query;
        };
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_first_membership_query);
        try {
            $complete = true;
            $post_types = ll_tools_user_progress_get_post_type_map(
                range(900000001, 900000501),
                $complete
            );
        } finally {
            remove_filter('query', $break_first_membership_query);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertFalse($complete);
        $this->assertSame([], $post_types);
        $this->assertSame(1, $membership_queries, 'Membership reads should stop at the first failed batch.');
    }

    public function test_progress_summary_batches_fail_closed_on_an_early_query_error(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $progress_table = ll_tools_user_progress_table_names()['words'];
        $progress_queries = 0;
        $break_first_progress_query = static function (string $query) use (&$progress_queries, $progress_table, $wpdb): string {
            if (stripos($query, "FROM {$progress_table}") === false || stripos($query, 'word_id IN') === false) {
                return $query;
            }
            $progress_queries++;
            if ($progress_queries === 1) {
                return "SELECT word_id FROM {$wpdb->prefix}ll_tools_missing_progress_summary_table";
            }
            return $query;
        };
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_first_progress_query);
        try {
            $complete = true;
            $rows = ll_tools_get_user_word_progress_summary_rows(
                $user_id,
                range(910000001, 910000501),
                $complete
            );
        } finally {
            remove_filter('query', $break_first_progress_query);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertFalse($complete);
        $this->assertSame([], $rows);
        $this->assertSame(1, $progress_queries, 'Progress reads should stop at the first failed batch.');
    }

    public function test_selection_launch_plan_propagates_membership_query_failure(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $membership_queries = 0;
        $break_membership_query = static function (string $query) use (&$membership_queries, $wpdb): string {
            if (stripos($query, "SELECT ID, post_type FROM {$wpdb->posts}") === false) {
                return $query;
            }
            $membership_queries++;
            return "SELECT ID, post_type FROM {$wpdb->prefix}ll_tools_missing_plan_membership_table";
        };
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_membership_query);
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                (int) $fixture['wordset_id'],
                (array) $fixture['category_ids'],
                'studied',
                'practice'
            );
        } finally {
            remove_filter('query', $break_membership_query);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertGreaterThanOrEqual(1, $membership_queries);
        $this->assertWPError($plan);
        $this->assertSame('selection_query_failed', $plan->get_error_code());
    }

    public function test_analytics_membership_fails_closed_when_wrong_answer_owner_map_is_incomplete(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) $fixture['word_ids']));
        $this->assertGreaterThanOrEqual(2, count($word_ids));

        update_post_meta($word_ids[0], LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$word_ids[1]]);
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, [], false);
        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
            'dirty:test-' . wp_generate_uuid4(),
            false
        );
        wp_cache_delete(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, 'options');
        wp_cache_delete(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, 'options');

        try {
            $complete = true;
            $word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category(
                (array) $fixture['category_ids'],
                (int) $fixture['wordset_id'],
                $complete
            );
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                (int) $fixture['wordset_id'],
                (array) $fixture['category_ids'],
                '',
                'practice'
            );
        } finally {
            ll_tools_rebuild_specific_wrong_answer_owner_map();
        }

        $this->assertFalse($complete);
        $this->assertSame([], $word_ids_by_category);
        $this->assertWPError($plan);
        $this->assertSame('selection_query_failed', $plan->get_error_code());
    }

    public function test_summary_only_analytics_word_id_cache_hits_for_repeated_scope(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $cache_events = [];
        $capture_cache_event = static function ($status) use (&$cache_events): void {
            $cache_events[] = (string) $status;
        };

        add_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_event, 10, 1);
        try {
            $first = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $fixture['wordset_id'],
                $fixture['category_ids'],
                14,
                false,
                ['summary_only' => true]
            );
            $second = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $fixture['wordset_id'],
                $fixture['category_ids'],
                14,
                false,
                ['summary_only' => true]
            );
            $reversed_word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category(
                array_reverse($fixture['category_ids']),
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_event, 10);
        }

        $this->assertSame($first['summary'], $second['summary']);
        $this->assertSame($first['categories'], $second['categories']);
        $this->assertSame(array_reverse($fixture['category_ids']), array_map('intval', array_keys($reversed_word_ids_by_category)));
        $this->assertSame([], (array) ($second['words'] ?? []));
        $this->assertTrue((bool) ($second['words_omitted'] ?? false));
        $this->assertContains('miss', $cache_events);
        $this->assertContains('store', $cache_events);
        $this->assertContains('request_hit', $cache_events);
    }

    public function test_summary_only_analytics_word_id_cache_misses_after_category_version_bump(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$category_id] = $fixture['category_ids'];

        $first = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            [$category_id],
            14,
            false,
            ['summary_only' => true]
        );

        ll_tools_bump_category_cache_version([$category_id]);

        $cache_events = [];
        $capture_cache_event = static function ($status) use (&$cache_events): void {
            $cache_events[] = (string) $status;
        };

        add_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_event, 10, 1);
        try {
            $second = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $fixture['wordset_id'],
                [$category_id],
                14,
                false,
                ['summary_only' => true]
            );
        } finally {
            remove_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_event, 10);
        }

        $this->assertSame($first['summary'], $second['summary']);
        $this->assertSame($first['categories'], $second['categories']);
        $this->assertContains('miss', $cache_events);
        $this->assertContains('store', $cache_events);
    }

    public function test_summary_only_analytics_scope_cache_refreshes_when_word_moves_out_of_category(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$category_a_id, $category_b_id] = $fixture['category_ids'];
        $wordset_id = (int) $fixture['wordset_id'];
        $moved_word_id = $this->createWordWithAudio(
            'Analytics Moving Word',
            'Analytics Moving Translation',
            $category_a_id,
            $wordset_id,
            'analytics-moving.mp3'
        );

        $before = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$category_a_id],
            14,
            false,
            ['summary_only' => true]
        );
        $this->assertSame(6, (int) ($before['summary']['total_words'] ?? 0));

        wp_set_post_terms($moved_word_id, [$category_b_id], 'word-category', false);

        $after_a = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$category_a_id],
            14,
            false,
            ['summary_only' => true]
        );
        $after_b = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$category_b_id],
            14,
            false,
            ['summary_only' => true]
        );

        $this->assertSame(5, (int) ($after_a['summary']['total_words'] ?? 0));
        $this->assertSame(6, (int) ($after_b['summary']['total_words'] ?? 0));
    }

    public function test_summary_only_analytics_prompt_card_answer_cache_refreshes_after_answer_change(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Analytics Prompt Card Wordset ' . wp_generate_password(6, false), 'wordset');
            $asset_category = wp_insert_term('Analytics Prompt Card Assets ' . wp_generate_password(6, false), 'word-category');
            $prompt_category = wp_insert_term('Analytics Prompt Card Questions ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($wordset));
            $this->assertFalse(is_wp_error($asset_category));
            $this->assertFalse(is_wp_error($prompt_category));
            $this->assertIsArray($wordset);
            $this->assertIsArray($asset_category);
            $this->assertIsArray($prompt_category);

            $wordset_id = (int) $wordset['term_id'];
            $asset_category_id = (int) $asset_category['term_id'];
            $prompt_category_id = (int) $prompt_category['term_id'];
            update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($prompt_category_id, 'll_quiz_option_type', 'text_title');
            $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

            $answer_one_id = $this->createWordWithoutAudio('Analytics Prompt Answer One', $asset_category_id, $wordset_id);
            $answer_two_id = $this->createWordWithoutAudio('Analytics Prompt Answer Two', $asset_category_id, $wordset_id);
            $prompt_card_id = $this->createPromptCardForAnalytics($effective_prompt_category_id, $wordset_id, [
                'title' => 'Analytics Prompt Card',
                'prompt_text' => 'Choose the analytics answer.',
                'correct_answer_word_id' => $answer_one_id,
                'wrong_answer_word_ids' => [$answer_two_id],
                'track_answer_word_progress' => true,
            ]);

            $this->seedWordProgressRow($user_id, $answer_one_id, $effective_prompt_category_id, $wordset_id, [
                'total_coverage' => 2,
                'stage' => 1,
            ]);

            $before_word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category([$effective_prompt_category_id], $wordset_id);
            $this->assertSame([$answer_one_id], array_values((array) ($before_word_ids_by_category[$effective_prompt_category_id] ?? [])));

            $before = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $wordset_id,
                [$effective_prompt_category_id],
                14,
                false,
                ['summary_only' => true]
            );
            $this->assertSame(1, (int) ($before['summary']['total_words'] ?? 0));
            $this->assertSame(1, (int) ($before['summary']['studied_words'] ?? 0));

            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin_id);
            $category_version_before_save = (int) ll_tools_get_category_cache_version($effective_prompt_category_id);
            $post = get_post($prompt_card_id);
            $this->assertInstanceOf(WP_Post::class, $post);
            $post_backup = $_POST;
            try {
                $_POST = [
                    'll_tools_prompt_card_nonce' => wp_create_nonce('ll_tools_prompt_card_save'),
                    'll_prompt_card_prompt_text' => 'Choose the updated analytics answer.',
                    'll_prompt_card_prompt_audio_attachment_id' => '0',
                    'll_prompt_card_prompt_audio_url' => '',
                    'll_prompt_card_prompt_image_word_id' => '0',
                    'll_prompt_card_correct_answer_word_id' => (string) $answer_two_id,
                    'll_prompt_card_wrong_answer_word_ids' => (string) $answer_one_id,
                    'll_prompt_card_track_answer_word_progress' => '1',
                ];
                ll_tools_prompt_card_save_post($prompt_card_id, $post);
            } finally {
                $_POST = $post_backup;
                wp_set_current_user($user_id);
            }

            $this->assertGreaterThan($category_version_before_save, (int) ll_tools_get_category_cache_version($effective_prompt_category_id));
            $after_word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category([$effective_prompt_category_id], $wordset_id);
            $this->assertSame([$answer_two_id], array_values((array) ($after_word_ids_by_category[$effective_prompt_category_id] ?? [])));

            $after = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $wordset_id,
                [$effective_prompt_category_id],
                14,
                false,
                ['summary_only' => true]
            );

            $this->assertSame(1, (int) ($after['summary']['total_words'] ?? 0));
            $this->assertSame(0, (int) ($after['summary']['studied_words'] ?? 0));
            $this->assertSame(1, (int) ($after['summary']['new_words'] ?? 0));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_summary_only_analytics_excludes_prompt_cards_that_do_not_track_answer_word_progress(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Analytics Nontracking Prompt Wordset ' . wp_generate_password(6, false), 'wordset');
            $asset_category = wp_insert_term('Analytics Nontracking Prompt Assets ' . wp_generate_password(6, false), 'word-category');
            $prompt_category = wp_insert_term('Analytics Nontracking Prompt Questions ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($wordset));
            $this->assertFalse(is_wp_error($asset_category));
            $this->assertFalse(is_wp_error($prompt_category));
            $this->assertIsArray($wordset);
            $this->assertIsArray($asset_category);
            $this->assertIsArray($prompt_category);

            $wordset_id = (int) $wordset['term_id'];
            $asset_category_id = (int) $asset_category['term_id'];
            $prompt_category_id = (int) $prompt_category['term_id'];
            update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($prompt_category_id, 'll_quiz_option_type', 'text_title');
            $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

            $answer_id = $this->createWordWithoutAudio('Analytics Nontracking Answer', $asset_category_id, $wordset_id);
            $wrong_id = $this->createWordWithoutAudio('Analytics Nontracking Wrong', $asset_category_id, $wordset_id);
            $this->createPromptCardForAnalytics($effective_prompt_category_id, $wordset_id, [
                'title' => 'Analytics Nontracking Prompt Card',
                'prompt_text' => 'Choose the nontracking answer.',
                'correct_answer_word_id' => $answer_id,
                'wrong_answer_word_ids' => [$wrong_id],
                'track_answer_word_progress' => false,
            ]);

            $word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category([$effective_prompt_category_id], $wordset_id);
            $this->assertSame([], array_values((array) ($word_ids_by_category[$effective_prompt_category_id] ?? [])));

            $summary = ll_tools_build_user_study_analytics_payload(
                $user_id,
                $wordset_id,
                [$effective_prompt_category_id],
                14,
                false,
                ['summary_only' => true]
            );

            $this->assertSame(0, (int) ($summary['summary']['total_words'] ?? -1));
            $this->assertSame(0, (int) ($summary['summary']['new_words'] ?? -1));
            $this->assertSame(0, (int) ($summary['summary']['studied_words'] ?? -1));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_analytics_payload_includes_vocab_lesson_urls_for_private_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Private Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Analytics Private Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$user_id]);

        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                'Analytics Private Word ' . $index,
                'Analytics Private Translation ' . $index,
                $category_id,
                $wordset_id,
                'analytics-private-' . $index . '.mp3'
            );
        }

        $category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$user_id]);

        $lesson = ll_tools_get_or_create_vocab_lesson_page($category_id, $wordset_id);
        $this->assertIsArray($lesson);
        $lesson_id = (int) ($lesson['post_id'] ?? 0);
        $this->assertGreaterThan(0, $lesson_id);
        $lesson_url = (string) get_permalink($lesson_id);
        $this->assertNotSame('', $lesson_url);

        $analytics = ll_tools_build_user_study_analytics_payload($user_id, $wordset_id, [$category_id], 14, true);

        $category_rows_by_id = [];
        foreach ((array) ($analytics['categories'] ?? []) as $row) {
            if (is_array($row)) {
                $category_rows_by_id[(int) ($row['id'] ?? 0)] = $row;
            }
        }
        $this->assertArrayHasKey($category_id, $category_rows_by_id);
        $this->assertSame($lesson_url, (string) ($category_rows_by_id[$category_id]['url'] ?? ''));

        $word_rows = (array) ($analytics['words'] ?? []);
        $this->assertNotEmpty($word_rows);
        $first_word_row = null;
        foreach ($word_rows as $row) {
            if (is_array($row) && in_array($category_id, array_map('intval', (array) ($row['category_ids'] ?? [])), true)) {
                $first_word_row = $row;
                break;
            }
        }
        $this->assertIsArray($first_word_row);
        $this->assertSame($lesson_url, (string) ($first_word_row['category_url'] ?? ''));
        $this->assertContains($lesson_url, array_map('strval', (array) ($first_word_row['category_urls'] ?? [])));
    }

    public function test_daily_activity_counts_answered_rounds_instead_of_all_logged_events(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a, $word_b] = $fixture['word_ids'];
        $category_id = (int) $fixture['category_ids'][0];
        $wordset_id = (int) $fixture['wordset_id'];

        $stats = ll_tools_process_progress_events_batch($user_id, [
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => $word_a,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $word_a,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'is_correct' => false,
                'had_wrong_before' => false,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $word_a,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'is_correct' => true,
                'had_wrong_before' => true,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => $word_b,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $word_b,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'mode_session_complete',
                'mode' => 'practice',
                'wordset_id' => $wordset_id,
                'payload' => [
                    'category_ids' => [$category_id],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'listening',
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'payload' => [
                    'units' => 1,
                ],
            ],
        ]);
        $this->assertSame(7, (int) ($stats['processed'] ?? 0));

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$category_id],
            14
        );

        $today = gmdate('Y-m-d');
        $today_row = null;
        foreach ((array) ($analytics['daily_activity']['days'] ?? []) as $row) {
            if (is_array($row) && (($row['date'] ?? '') === $today)) {
                $today_row = $row;
                break;
            }
        }

        $this->assertIsArray($today_row);
        $this->assertSame(2, (int) ($today_row['rounds'] ?? 0));
        $this->assertSame(2, (int) ($today_row['events'] ?? 0));
        $this->assertSame(2, (int) ($today_row['unique_words'] ?? 0));
        $this->assertSame(3, (int) ($today_row['outcomes'] ?? 0));
        $this->assertSame(2, (int) ($analytics['daily_activity']['max_rounds'] ?? 0));
        $this->assertSame(2, (int) ($analytics['daily_activity']['max_events'] ?? 0));
    }

    public function test_analytics_filters_out_non_quizzable_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id, $non_quizzable_category_id],
            14
        );

        $this->assertSame(5, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertCount(1, (array) ($analytics['categories'] ?? []));

        $category_ids = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($analytics['categories'] ?? []));
        $this->assertContains($quizzable_category_id, $category_ids);
        $this->assertNotContains($non_quizzable_category_id, $category_ids);

        $summary_only = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id, $non_quizzable_category_id],
            14,
            false,
            ['summary_only' => true]
        );

        $this->assertSame(5, (int) ($summary_only['summary']['total_words'] ?? 0));
        $this->assertCount(1, (array) ($summary_only['categories'] ?? []));
        $summary_only_category_ids = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($summary_only['categories'] ?? []));
        $this->assertContains($quizzable_category_id, $summary_only_category_ids);
        $this->assertNotContains($non_quizzable_category_id, $summary_only_category_ids);
        $this->assertSame([], (array) ($summary_only['words'] ?? []));
        $this->assertTrue((bool) ($summary_only['words_omitted'] ?? false));
    }

    public function test_user_study_words_filters_out_non_quizzable_requested_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $words_by_category = ll_tools_user_study_words(
            [$quizzable_category_id, $non_quizzable_category_id],
            $wordset_id
        );

        $this->assertArrayHasKey($quizzable_category_id, $words_by_category);
        $this->assertArrayNotHasKey($non_quizzable_category_id, $words_by_category);
        $this->assertCount(5, (array) ($words_by_category[$quizzable_category_id] ?? []));
    }

    public function test_user_study_fetch_words_ajax_filters_out_non_quizzable_requested_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $wordset_id,
            'category_ids' => [$quizzable_category_id, $non_quizzable_category_id],
            'candidate_word_ids' => implode(
                ',',
                array_map('intval', (array) $fixture['quizzable_word_ids'])
            ),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_fetch_words_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $words_by_category = (array) ($response['data']['words_by_category'] ?? []);
        $this->assertArrayHasKey((string) $quizzable_category_id, $words_by_category);
        $this->assertArrayNotHasKey((string) $non_quizzable_category_id, $words_by_category);
    }

    public function test_user_study_fetch_words_ajax_honors_candidate_word_ids(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $candidate_ids = array_slice(array_map('intval', (array) ($fixture['quizzable_word_ids'] ?? [])), 0, 2);
        $this->assertCount(2, $candidate_ids);

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $wordset_id,
            'category_ids' => [$quizzable_category_id],
            'candidate_word_ids' => implode(',', $candidate_ids),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_fetch_words_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $words_by_category = (array) ($response['data']['words_by_category'] ?? []);
        $this->assertArrayHasKey((string) $quizzable_category_id, $words_by_category);
        $returned_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) $words_by_category[$quizzable_category_id])));
        sort($candidate_ids);
        sort($returned_ids);

        $this->assertSame($candidate_ids, $returned_ids);
    }

    public function test_user_study_bootstrap_payload_defers_words_by_default_with_metadata(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $renderable_cache_key = ll_tools_user_study_renderable_word_ids_cache_key(
            [$quizzable_category_id],
            $wordset_id,
            (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)
        );
        delete_transient($renderable_cache_key);

        $payload = ll_tools_build_user_study_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id],
            [
                'candidate_word_limit' => 2,
            ]
        );

        $this->assertTrue((bool) ($payload['words_deferred'] ?? false));
        $this->assertTrue((bool) ($payload['recommendation_refresh_deferred'] ?? false));
        $this->assertSame([], (array) ($payload['words_by_category'] ?? []));
        $this->assertNull($payload['next_activity'] ?? null);
        $this->assertFalse(get_transient($renderable_cache_key));

        $meta = (array) ($payload['words_by_category_meta'][$quizzable_category_id] ?? []);
        $this->assertSame($quizzable_category_id, (int) ($meta['category_id'] ?? 0));
        $this->assertSame(5, (int) ($meta['available_word_count'] ?? 0));
        $this->assertSame(2, (int) ($meta['candidate_count'] ?? 0));
        $this->assertSame(2, (int) ($meta['loaded_count'] ?? 0));
        $this->assertCount(2, (array) ($meta['candidate_word_ids'] ?? []));
        $this->assertFalse((bool) ($meta['fully_loaded'] ?? true));
        $this->assertFalse((bool) ($meta['complete'] ?? true));
        $this->assertTrue((bool) ($meta['has_more'] ?? false));
    }

    public function test_user_study_deferred_bootstrap_reuses_saved_recommendation_without_pool_refresh(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['quizzable_category_id'];
        $word_ids = array_values(array_map('intval', (array) $fixture['quizzable_word_ids']));
        $saved_queue = ll_tools_save_user_recommendation_queue([[
            'type' => 'review_chunk',
            'reason_code' => 'saved_test',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => $word_ids,
        ]], $user_id, $wordset_id);
        $this->assertCount(1, $saved_queue);

        $renderable_cache_key = ll_tools_user_study_renderable_word_ids_cache_key(
            [$category_id],
            $wordset_id,
            (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)
        );
        delete_transient($renderable_cache_key);

        $payload = ll_tools_build_user_study_payload(
            $user_id,
            $wordset_id,
            [$category_id],
            [
                'defer_words' => true,
                'candidate_word_limit' => 2,
            ]
        );

        $this->assertTrue((bool) ($payload['recommendation_refresh_deferred'] ?? false));
        $this->assertSame(
            (string) ($saved_queue[0]['queue_id'] ?? ''),
            (string) ($payload['next_activity']['queue_id'] ?? '')
        );
        $this->assertFalse(get_transient($renderable_cache_key));
    }

    public function test_user_study_deferred_metadata_reports_complete_candidate_slice(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];

        $payload = ll_tools_build_user_study_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id],
            [
                'defer_words' => true,
                'candidate_word_limit' => 20,
            ]
        );

        $this->assertTrue((bool) ($payload['words_deferred'] ?? false));
        $meta = (array) ($payload['words_by_category_meta'][$quizzable_category_id] ?? []);
        $this->assertSame(5, (int) ($meta['available_word_count'] ?? 0));
        $this->assertSame(5, (int) ($meta['candidate_count'] ?? 0));
        $this->assertSame(5, (int) ($meta['loaded_count'] ?? 0));
        $this->assertTrue((bool) ($meta['fully_loaded'] ?? false));
        $this->assertTrue((bool) ($meta['complete'] ?? false));
        $this->assertFalse((bool) ($meta['has_more'] ?? true));
    }

    public function test_user_study_fetch_words_ajax_rejects_unbounded_request_after_deferred_bootstrap(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];

        $payload = ll_tools_build_user_study_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id],
            [
                'defer_words' => true,
                'candidate_word_limit' => 2,
            ]
        );
        $this->assertTrue((bool) ($payload['words_deferred'] ?? false));

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $wordset_id,
            'category_ids' => [$quizzable_category_id],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_fetch_words_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('paged_payload_required', (string) ($response['data']['code'] ?? ''));
    }

    public function test_user_study_bootstrap_ajax_defaults_to_deferred_metadata(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $wordset_id,
            'category_ids' => [$quizzable_category_id],
            'include_words' => '1',
            'candidate_word_limit' => '2',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertTrue((bool) ($response['data']['words_deferred'] ?? false));
        $this->assertSame([], (array) ($response['data']['words_by_category'] ?? []));

        $meta = (array) ($response['data']['words_by_category_meta'][$quizzable_category_id] ?? []);
        $this->assertSame(5, (int) ($meta['available_word_count'] ?? 0));
        $this->assertSame(2, (int) ($meta['candidate_count'] ?? 0));
    }

    public function test_analytics_ajax_returns_payload(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $nonce = wp_create_nonce('ll_user_study');

        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'days' => 14,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data']['analytics'] ?? null);
        $this->assertArrayHasKey('summary', $response['data']['analytics']);
        $this->assertArrayHasKey('categories', $response['data']['analytics']);
        $this->assertArrayHasKey('words', $response['data']['analytics']);
        $this->assertNotEmpty((array) ($response['data']['analytics']['words'] ?? []));
        $this->assertFalse((bool) ($response['data']['analytics']['words_omitted'] ?? false));
    }

    public function test_analytics_ajax_uses_the_bounded_server_default_when_word_limit_is_missing_or_zero(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $nonce = wp_create_nonce('ll_user_study');
        $limit_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_user_progress_analytics_word_page_max', $limit_filter);

        $request = function (?string $word_limit) use ($fixture, $nonce): array {
            $_POST = [
                'nonce' => $nonce,
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => $fixture['category_ids'],
                'days' => 14,
            ];
            if ($word_limit !== null) {
                $_POST['word_limit'] = $word_limit;
            }
            $_REQUEST = $_POST;

            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_user_study_analytics_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            return (array) ($response['data']['analytics'] ?? []);
        };

        try {
            $missing_limit = $request(null);
            $zero_limit = $request('0');
        } finally {
            remove_filter('ll_tools_user_progress_analytics_word_page_max', $limit_filter);
        }

        foreach ([$missing_limit, $zero_limit] as $analytics) {
            $this->assertCount(3, (array) ($analytics['words'] ?? []));
            $pagination = (array) ($analytics['words_pagination'] ?? []);
            $this->assertTrue((bool) ($pagination['enabled'] ?? false));
            $this->assertSame(10, (int) ($pagination['total'] ?? 0));
            $this->assertSame(3, (int) ($pagination['limit'] ?? 0));
            $this->assertSame(3, (int) ($pagination['next_offset'] ?? 0));
            $this->assertTrue((bool) ($pagination['has_more'] ?? false));
        }
    }

    public function test_analytics_ajax_can_return_bounded_word_pages(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $nonce = wp_create_nonce('ll_user_study');

        $request_page = function (int $offset) use ($fixture, $nonce): array {
            $_POST = [
                'nonce' => $nonce,
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => $fixture['category_ids'],
                'days' => 14,
                'include_words' => '1',
                'word_limit' => '3',
                'word_offset' => (string) $offset,
            ];
            $_REQUEST = $_POST;

            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_user_study_analytics_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            return (array) ($response['data']['analytics'] ?? []);
        };

        $first_page = $request_page(0);
        $second_page = $request_page(3);

        $this->assertSame(10, (int) ($first_page['summary']['total_words'] ?? 0));
        $this->assertFalse((bool) ($first_page['words_omitted'] ?? true));
        $this->assertCount(3, (array) ($first_page['words'] ?? []));
        $this->assertCount(3, (array) ($second_page['words'] ?? []));

        $first_pagination = (array) ($first_page['words_pagination'] ?? []);
        $second_pagination = (array) ($second_page['words_pagination'] ?? []);
        $this->assertTrue((bool) ($first_pagination['enabled'] ?? false));
        $this->assertSame(10, (int) ($first_pagination['total'] ?? 0));
        $this->assertSame(0, (int) ($first_pagination['offset'] ?? -1));
        $this->assertSame(3, (int) ($first_pagination['limit'] ?? 0));
        $this->assertSame(3, (int) ($first_pagination['loaded'] ?? 0));
        $this->assertSame(3, (int) ($first_pagination['next_offset'] ?? 0));
        $this->assertTrue((bool) ($first_pagination['has_more'] ?? false));

        $this->assertTrue((bool) ($second_pagination['enabled'] ?? false));
        $this->assertSame(10, (int) ($second_pagination['total'] ?? 0));
        $this->assertSame(3, (int) ($second_pagination['offset'] ?? -1));
        $this->assertSame(6, (int) ($second_pagination['loaded'] ?? 0));
        $this->assertSame(6, (int) ($second_pagination['next_offset'] ?? 0));
        $this->assertTrue((bool) ($second_pagination['has_more'] ?? false));

        $first_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($first_page['words'] ?? []))));
        $second_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($second_page['words'] ?? []))));

        $this->assertCount(3, $first_ids);
        $this->assertCount(3, $second_ids);
        $this->assertSame([], array_values(array_intersect($first_ids, $second_ids)));
        foreach ((array) ($first_page['words'] ?? []) as $row) {
            $this->assertNotSame('', trim((string) ($row['title'] ?? '')));
        }
    }

    public function test_analytics_ajax_pages_starred_word_filter_before_hydrating_rows(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        $this->assertGreaterThanOrEqual(8, count($word_ids));

        $starred_word_ids = [$word_ids[1], $word_ids[3], $word_ids[6], $word_ids[7]];
        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => $starred_word_ids,
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $nonce = wp_create_nonce('ll_user_study');
        $request_page = function (int $offset) use ($fixture, $nonce): array {
            $_POST = [
                'nonce' => $nonce,
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => $fixture['category_ids'],
                'days' => 14,
                'include_words' => '1',
                'word_limit' => '2',
                'word_offset' => (string) $offset,
                'word_filter' => wp_json_encode([
                    'summary' => 'starred',
                    'category_ids' => [],
                    'column_filters' => [],
                ]),
            ];
            $_REQUEST = $_POST;

            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_user_study_analytics_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            return (array) ($response['data']['analytics'] ?? []);
        };

        $first_page = $request_page(0);
        $second_page = $request_page(2);

        $this->assertCount(2, (array) ($first_page['words'] ?? []));
        $this->assertCount(2, (array) ($second_page['words'] ?? []));
        $this->assertSame(10, (int) ($first_page['summary']['total_words'] ?? 0));
        $this->assertSame(4, (int) ($first_page['summary']['starred_words'] ?? 0));

        $first_pagination = (array) ($first_page['words_pagination'] ?? []);
        $second_pagination = (array) ($second_page['words_pagination'] ?? []);
        $this->assertTrue((bool) ($first_pagination['enabled'] ?? false));
        $this->assertTrue((bool) ($first_pagination['filtered'] ?? false));
        $this->assertSame(4, (int) ($first_pagination['total'] ?? 0));
        $this->assertSame(10, (int) ($first_pagination['unfiltered_total'] ?? 0));
        $this->assertSame(2, (int) ($first_pagination['next_offset'] ?? 0));
        $this->assertTrue((bool) ($first_pagination['has_more'] ?? false));

        $this->assertTrue((bool) ($second_pagination['filtered'] ?? false));
        $this->assertSame(4, (int) ($second_pagination['total'] ?? 0));
        $this->assertNull($second_pagination['next_offset'] ?? null);
        $this->assertFalse((bool) ($second_pagination['has_more'] ?? true));

        foreach (array_merge((array) ($first_page['words'] ?? []), (array) ($second_page['words'] ?? [])) as $row) {
            $this->assertIsArray($row);
            $this->assertTrue((bool) ($row['is_starred'] ?? false));
            $this->assertContains((int) ($row['id'] ?? 0), $starred_word_ids);
            $this->assertNotSame('', trim((string) ($row['title'] ?? '')));
        }
    }

    public function test_analytics_filter_counts_cover_full_filtered_set_not_loaded_page(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        [$cat_a, $cat_b] = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $this->assertGreaterThanOrEqual(8, count($word_ids));

        $starred_word_ids = [$word_ids[1], $word_ids[3], $word_ids[6], $word_ids[7]];
        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => $starred_word_ids,
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $this->seedWordProgressRow($user_id, $word_ids[1], $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 2,
            'correct_clean' => 0,
            'correct_after_retry' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            14,
            false,
            [
                'include_words' => true,
                'word_limit' => 2,
                'word_offset' => 0,
                'word_filter' => [
                    'summary' => 'starred',
                    'category_ids' => [],
                    'column_filters' => [],
                ],
            ]
        );

        $this->assertCount(2, (array) ($analytics['words'] ?? []));
        $this->assertSame(4, (int) ($analytics['words_pagination']['total'] ?? 0));

        $counts = (array) ($analytics['word_filter_counts'] ?? []);
        $status_counts = (array) ($counts['status'] ?? []);
        $star_counts = (array) ($counts['star'] ?? []);
        $category_counts = (array) ($counts['category'] ?? []);

        $this->assertSame(0, (int) ($status_counts['mastered'] ?? -1));
        $this->assertSame(1, (int) ($status_counts['studied'] ?? 0));
        $this->assertSame(3, (int) ($status_counts['new'] ?? 0));
        $this->assertSame(4, (int) ($star_counts['starred'] ?? 0));
        $this->assertSame(0, (int) ($star_counts['unstarred'] ?? -1));
        $this->assertSame(2, (int) ($category_counts[(string) $cat_a] ?? 0));
        $this->assertSame(2, (int) ($category_counts[(string) $cat_b] ?? 0));
    }

    public function test_analytics_ajax_returns_filtered_word_ids_without_hydrating_rows(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        $this->assertGreaterThanOrEqual(8, count($word_ids));

        $starred_word_ids = [$word_ids[1], $word_ids[3], $word_ids[6], $word_ids[7]];
        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => $starred_word_ids,
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $catalog_build_count = 0;
        $capture_catalog_build = static function (int $wordset_id) use (&$catalog_build_count): void {
            $catalog_build_count++;
        };
        add_action(
            'll_tools_user_study_categories_for_wordset_before_build',
            $capture_catalog_build,
            10,
            1
        );

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'days' => 14,
            'include_words' => '0',
            'include_word_ids' => '1',
            'selection_ids_only' => '1',
            'word_limit' => '0',
            'word_offset' => '0',
            'word_filter' => wp_json_encode([
                'summary' => 'starred',
                'category_ids' => [],
                'column_filters' => [],
            ]),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_action(
                'll_tools_user_study_categories_for_wordset_before_build',
                $capture_catalog_build,
                10
            );
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $analytics = (array) ($response['data']['analytics'] ?? []);

        $returned_ids = array_values(array_map('intval', (array) ($analytics['word_ids'] ?? [])));
        sort($returned_ids);
        sort($starred_word_ids);

        $this->assertSame(0, $catalog_build_count, 'ID-only analytics must not build the full learner category catalog.');
        $this->assertSame($starred_word_ids, $returned_ids);
        $this->assertSame([], (array) ($analytics['words'] ?? []));
        $this->assertTrue((bool) ($analytics['words_omitted'] ?? false));
        $pagination = (array) ($analytics['words_pagination'] ?? []);
        $this->assertFalse((bool) ($pagination['enabled'] ?? true));
        $this->assertTrue((bool) ($pagination['filtered'] ?? false));
        $this->assertSame(4, (int) ($pagination['total'] ?? 0));
        $this->assertSame(10, (int) ($pagination['unfiltered_total'] ?? 0));
        $this->assertSame(0, (int) ($pagination['offset'] ?? -1));
        $this->assertSame(0, (int) ($pagination['limit'] ?? -1));
        $this->assertSame(0, (int) ($pagination['loaded'] ?? -1));
        $this->assertArrayHasKey('next_offset', $pagination);
        $this->assertNull($pagination['next_offset'] ?? null);
        $this->assertFalse((bool) ($pagination['has_more'] ?? true));
    }

    public function test_selection_id_analytics_stays_query_bounded_with_large_saved_category_state(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
        $word_ids = range(9000001, 9002714);
        $word_ids_by_category = [
            $category_ids[0] => $word_ids,
            $category_ids[1] => [],
        ];
        $membership_cache_key = ll_tools_user_progress_analytics_word_ids_cache_key(
            $category_ids,
            (int) $fixture['wordset_id']
        );
        set_transient($membership_cache_key, [
            '__ll_user_progress_analytics_word_ids_cache_format' => 1,
            'word_ids_by_category' => $word_ids_by_category,
        ], HOUR_IN_SECONDS);
        wp_cache_delete($membership_cache_key, 'll_tools_user_progress');

        // Simulate the 200+ category saved state seen on Zazacaogren. These IDs
        // are intentionally outside the requested analytics scope: an ID-only
        // launch must not repair or hydrate them merely to read starred words.
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, (int) $fixture['wordset_id']);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, range(900001, 900210));
        update_user_meta($user_id, LL_TOOLS_USER_STARRED_META, [$word_ids[0]]);

        $cache_statuses = [];
        $capture_cache_status = static function (string $status) use (&$cache_statuses): void {
            $cache_statuses[] = $status;
        };
        add_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_status, 10, 1);
        $queries_before = get_num_queries();
        try {
            $analytics = ll_tools_build_user_study_analytics_payload(
                $user_id,
                (int) $fixture['wordset_id'],
                $category_ids,
                14,
                false,
                [
                    'include_words' => false,
                    'include_word_ids' => true,
                    'selection_ids_only' => true,
                    'word_filter' => [
                        'summary' => 'new',
                        'category_ids' => [],
                        'column_filters' => [],
                    ],
                ]
            );
        } finally {
            remove_action('ll_tools_user_progress_analytics_word_ids_cache_status', $capture_cache_status, 10);
        }
        $query_count = get_num_queries() - $queries_before;

        $this->assertContains('persistent_hit', $cache_statuses);
        $this->assertLessThanOrEqual(
            20,
            $query_count,
            'Selection-ID analytics must not issue term/meta queries per saved category.'
        );
        $this->assertSame(
            $word_ids,
            array_values(array_map('intval', (array) ($analytics['word_ids'] ?? [])))
        );
    }

    public function test_word_id_analytics_without_selection_fast_path_preserves_the_existing_contract(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $catalog_build_count = 0;
        $capture_catalog_build = static function (int $wordset_id) use (&$catalog_build_count): void {
            $catalog_build_count++;
        };
        add_action(
            'll_tools_user_study_categories_for_wordset_before_build',
            $capture_catalog_build,
            10,
            1
        );
        try {
            $analytics = ll_tools_build_user_study_analytics_payload(
                $user_id,
                (int) $fixture['wordset_id'],
                (array) $fixture['category_ids'],
                14,
                false,
                [
                    'include_words' => false,
                    'include_word_ids' => true,
                ]
            );
        } finally {
            remove_action(
                'll_tools_user_study_categories_for_wordset_before_build',
                $capture_catalog_build,
                10
            );
        }

        $this->assertGreaterThanOrEqual(1, $catalog_build_count);
        $this->assertCount(10, (array) ($analytics['word_ids'] ?? []));
        $this->assertSame([], (array) ($analytics['words'] ?? []));
        $this->assertCount(2, (array) ($analytics['categories'] ?? []));
        $this->assertCount(14, (array) ($analytics['daily_activity']['days'] ?? []));
    }

    public function test_selection_id_analytics_ajax_propagates_membership_query_failure(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $membership_queries = 0;
        $break_membership_query = static function (string $query) use (&$membership_queries, $wpdb): string {
            if (stripos($query, "SELECT ID, post_type FROM {$wpdb->posts}") === false) {
                return $query;
            }
            $membership_queries++;
            return "SELECT ID, post_type FROM {$wpdb->prefix}ll_tools_missing_selection_membership_table";
        };

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'include_words' => '0',
            'include_word_ids' => '1',
            'selection_ids_only' => '1',
        ];
        $_REQUEST = $_POST;
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_membership_query);
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            remove_filter('query', $break_membership_query);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertGreaterThanOrEqual(1, $membership_queries);
        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('selection_query_failed', (string) ($response['data']['code'] ?? ''));
    }

    public function test_selection_id_analytics_ajax_propagates_progress_query_failure(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $progress_table = ll_tools_user_progress_table_names()['words'];
        $progress_queries = 0;
        $break_progress_query = static function (string $query) use (&$progress_queries, $progress_table, $wpdb): string {
            if (stripos($query, "FROM {$progress_table}") === false || stripos($query, 'word_id IN') === false) {
                return $query;
            }
            $progress_queries++;
            return "SELECT word_id FROM {$wpdb->prefix}ll_tools_missing_selection_progress_table";
        };

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'include_words' => '0',
            'include_word_ids' => '1',
            'selection_ids_only' => '1',
        ];
        $_REQUEST = $_POST;
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_progress_query);
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            remove_filter('query', $break_progress_query);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertGreaterThanOrEqual(1, $progress_queries);
        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('selection_query_failed', (string) ($response['data']['code'] ?? ''));
    }

    public function test_selection_launch_plan_ajax_returns_complete_in_progress_plan_with_first_chunk_aliases(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $this->assertCount(10, $word_ids);
        $this->assertCount(2, $category_ids);

        $studied_by_category = [
            $category_ids[0] => [$word_ids[0], $word_ids[1], $word_ids[3], $word_ids[4]],
            $category_ids[1] => [$word_ids[2], $word_ids[6], $word_ids[7]],
        ];
        foreach ($studied_by_category as $category_id => $studied_word_ids) {
            foreach ($studied_word_ids as $word_id) {
                $this->seedWordProgressRow($user_id, $word_id, $category_id, (int) $fixture['wordset_id'], [
                    'total_coverage' => 2,
                    'coverage_practice' => 2,
                    'correct_clean' => 1,
                    'stage' => 2,
                ]);
            }
        }

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $category_ids,
            'criteria' => 'studied',
            'mode' => 'practice',
        ];
        $_REQUEST = $_POST;
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_selection_launch_plan_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $plan = (array) ($response['data']['plan'] ?? []);
        $returned_word_ids = array_values(array_map('intval', (array) ($plan['word_ids'] ?? [])));
        $expected_word_ids = array_values(array_merge(
            (array) $studied_by_category[$category_ids[0]],
            (array) $studied_by_category[$category_ids[1]]
        ));
        sort($returned_word_ids);
        sort($expected_word_ids);
        $this->assertSame($expected_word_ids, $returned_word_ids);
        $this->assertSame(
            [$category_ids[0], $category_ids[1]],
            array_values(array_map('intval', (array) ($plan['category_ids'] ?? []))),
            'The largest owned queue should lead the launch batch to minimize startup hydration.'
        );
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(1, $chunks);
        $this->assertSame($plan['category_ids'], $chunks[0]['category_ids'] ?? []);
        $this->assertSame($plan['word_ids'], $chunks[0]['word_ids'] ?? []);
        $this->assertSame(7, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(7, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(1, (int) ($plan['chunk_count'] ?? 0));
        $this->assertFalse((bool) ($plan['truncated'] ?? true));
        $this->assertSame('studied', (string) ($plan['criteria'] ?? ''));
        $this->assertSame('practice', (string) ($plan['mode'] ?? ''));
    }

    public function test_selection_launch_plan_ajax_preserves_exact_candidates_across_bounded_chunks(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $outside_word_id = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Outside exact selection ' . wp_generate_password(4, false),
        ]);

        $five_words = static function (): int {
            return 5;
        };
        $catalog_build_count = 0;
        $capture_catalog_build = static function (int $wordset_id) use (&$catalog_build_count): void {
            $catalog_build_count++;
        };
        add_filter('ll_tools_user_study_selection_launch_max_words', $five_words);
        add_action(
            'll_tools_user_study_categories_for_wordset_before_build',
            $capture_catalog_build,
            10,
            1
        );
        try {
            $_POST = [
                'nonce' => wp_create_nonce('ll_user_study'),
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => $category_ids,
                'days' => 14,
                'include_words' => '0',
                'include_word_ids' => '1',
                'selection_ids_only' => '1',
                'word_limit' => '0',
                'word_offset' => '0',
            ];
            $_REQUEST = $_POST;
            $analytics_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
            $this->assertTrue((bool) ($analytics_response['success'] ?? false));
            $analytics = (array) ($analytics_response['data']['analytics'] ?? []);
            $primed_word_ids = array_values(array_map('intval', (array) ($analytics['word_ids'] ?? [])));
            $expected_primed_word_ids = $word_ids;
            sort($primed_word_ids);
            sort($expected_primed_word_ids);
            $this->assertSame($expected_primed_word_ids, $primed_word_ids);
            $this->assertSame(0, $catalog_build_count, 'Priming exact IDs must not build the full learner category catalog.');

            $requested_word_ids = array_merge(array_reverse($primed_word_ids), [$outside_word_id]);
            $_POST = [
                'nonce' => wp_create_nonce('ll_user_study'),
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => $category_ids,
                'candidate_word_ids' => implode(',', $requested_word_ids),
                // No progress is seeded. Exact candidates must remain authoritative
                // even though this criterion would otherwise return no matches.
                'criteria' => 'learned',
                'mode' => 'practice',
            ];
            $_REQUEST = $_POST;
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_selection_launch_plan_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_user_study_selection_launch_max_words', $five_words);
            remove_action(
                'll_tools_user_study_categories_for_wordset_before_build',
                $capture_catalog_build,
                10
            );
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame(0, $catalog_build_count, 'Exact-candidate Practice planning must reuse lightweight membership data.');
        $plan = (array) ($response['data']['plan'] ?? []);
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(2, $chunks);

        $planned_word_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $this->assertCount(5, $chunk_word_ids);
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
        }

        sort($word_ids);
        $unique_planned_word_ids = array_values(array_unique($planned_word_ids));
        sort($unique_planned_word_ids);
        $this->assertSame($word_ids, $unique_planned_word_ids);
        $this->assertNotContains($outside_word_id, $planned_word_ids);
        $this->assertSame(10, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(10, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(2, (int) ($plan['chunk_count'] ?? 0));
        $this->assertSame('learned', (string) ($plan['criteria'] ?? ''));
    }

    public function test_exact_candidate_planner_stays_bounded_at_zazaca_scale(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Zazaca Scale Launch Plan ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_ids = range(700001, 700207);
        $candidate_word_ids = range(9100001, 9102714);
        $word_ids_by_category = [
            $category_ids[0] => array_slice($candidate_word_ids, 0, 2508),
        ];
        foreach (array_slice($category_ids, 1) as $offset => $category_id) {
            $word_ids_by_category[$category_id] = [$candidate_word_ids[2508 + $offset]];
        }

        $membership_cache_key = ll_tools_user_progress_analytics_word_ids_cache_key(
            $category_ids,
            $wordset_id
        );
        set_transient($membership_cache_key, [
            '__ll_user_progress_analytics_word_ids_cache_format' => 1,
            'word_ids_by_category' => $word_ids_by_category,
        ], HOUR_IN_SECONDS);
        wp_cache_delete($membership_cache_key, 'll_tools_user_progress');

        $catalog_build_count = 0;
        $capture_catalog_build = static function () use (&$catalog_build_count): void {
            $catalog_build_count++;
        };
        add_action(
            'll_tools_user_study_categories_for_wordset_before_build',
            $capture_catalog_build,
            10,
            0
        );
        $queries_before = get_num_queries();
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                $category_ids,
                'new',
                'practice',
                $candidate_word_ids
            );
        } finally {
            remove_action(
                'll_tools_user_study_categories_for_wordset_before_build',
                $capture_catalog_build,
                10
            );
        }
        $query_count = get_num_queries() - $queries_before;

        $this->assertNotWPError($plan);
        $this->assertIsArray($plan);
        $this->assertSame(0, $catalog_build_count, 'Exact-candidate planning must not rebuild the full category catalog.');
        $this->assertLessThanOrEqual(12, $query_count, 'A 207-category exact launch plan must stay O(1) in SQL queries.');
        $this->assertSame(2714, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(2714, (int) ($plan['planned_count'] ?? 0));

        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertNotEmpty($chunks);
        $this->assertSame(
            [$category_ids[0]],
            array_values(array_map('intval', (array) ($chunks[0]['category_ids'] ?? []))),
            'The first Zazaca-scale chunk should need one category hydration request.'
        );
        $this->assertLessThanOrEqual(15, count((array) ($chunks[0]['word_ids'] ?? [])));

        $planned_word_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $this->assertGreaterThanOrEqual(5, count($chunk_word_ids));
            $this->assertLessThanOrEqual(15, count($chunk_word_ids));
            $this->assertLessThanOrEqual(8, count((array) ($chunk['category_ids'] ?? [])));
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
        }
        $this->assertCount(2714, $planned_word_ids);
        $this->assertCount(2714, array_unique($planned_word_ids));
        sort($planned_word_ids);
        $this->assertSame($candidate_word_ids, $planned_word_ids);
    }

    public function test_selection_launch_plan_ajax_rejects_an_empty_exact_candidate_scope(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'candidate_word_ids' => '',
            'criteria' => '',
            'mode' => 'practice',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_selection_launch_plan_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('Invalid request.', (string) ($response['data']['message'] ?? ''));
    }

    public function test_selection_membership_excludes_reserved_distractor_targets_but_keeps_prompt_answers(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $fixture = $this->createAnalyticsFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
        $word_ids = array_values(array_map('intval', (array) $fixture['word_ids']));
        $direct_category_id = (int) $category_ids[0];
        $owner_word_id = (int) $word_ids[0];
        $reserved_word_id = (int) $word_ids[1];

        update_post_meta($owner_word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_word_id]);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $prompt_category = wp_insert_term(
            'Analytics Reserved Prompt Category ' . wp_generate_password(6, false),
            'word-category'
        );
        $this->assertFalse(is_wp_error($prompt_category));
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'text_title');
        $prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);
        $prompt_card_id = $this->createPromptCardForAnalytics($prompt_category_id, $wordset_id, [
            'title' => 'Analytics Reserved Answer Prompt',
            'prompt_text' => 'Choose the reserved answer.',
            'correct_answer_word_id' => $reserved_word_id,
            'wrong_answer_word_ids' => [(int) $word_ids[2]],
            'track_answer_word_progress' => true,
        ]);

        $minimum_words = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $minimum_words);
        try {
            $renderable_complete = true;
            $renderable_ids_by_category = ll_tools_user_study_renderable_word_ids_by_category(
                [$direct_category_id],
                $wordset_id,
                $renderable_complete
            );
            $membership_complete = true;
            $word_ids_by_category = ll_tools_user_progress_analytics_word_ids_by_category(
                [$direct_category_id, $prompt_category_id],
                $wordset_id,
                $membership_complete
            );
            $direct_plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                [$direct_category_id],
                '',
                'practice'
            );
            $prompt_plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                [$prompt_category_id],
                '',
                'practice'
            );
        } finally {
            remove_filter('ll_tools_quiz_min_words', $minimum_words);
        }

        $this->assertTrue($renderable_complete);
        $this->assertContains(
            $reserved_word_id,
            array_values(array_map('intval', (array) ($renderable_ids_by_category[$direct_category_id] ?? []))),
            'The reserved distractor must remain in renderable payload membership for answer options.'
        );
        $this->assertTrue($membership_complete);
        $this->assertNotContains(
            $reserved_word_id,
            array_values(array_map('intval', (array) ($word_ids_by_category[$direct_category_id] ?? []))),
            'A specific-wrong-only word must not become a direct quiz target.'
        );
        $this->assertSame(
            [$reserved_word_id],
            array_values(array_map('intval', (array) ($word_ids_by_category[$prompt_category_id] ?? []))),
            'A real tracking prompt card must retain its canonical answer even when that word is reserved elsewhere.'
        );

        $this->assertIsArray($direct_plan);
        $this->assertSame(4, (int) ($direct_plan['matched_count'] ?? 0));
        $direct_planned_ids = [];
        foreach ((array) ($direct_plan['chunks'] ?? []) as $chunk) {
            $direct_planned_ids = array_merge($direct_planned_ids, array_map('intval', (array) ($chunk['word_ids'] ?? [])));
        }
        $this->assertNotContains($reserved_word_id, $direct_planned_ids);
        $this->assertContains($owner_word_id, $direct_planned_ids);

        $this->assertIsArray($prompt_plan);
        $this->assertSame(1, (int) ($prompt_plan['matched_count'] ?? 0));
        $this->assertSame([$reserved_word_id], array_values(array_map('intval', (array) ($prompt_plan['word_ids'] ?? []))));
        $this->assertGreaterThan(0, $prompt_card_id);
    }

    public function test_selection_launch_plan_preserves_every_match_across_bounded_chunks(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $word_ids = array_values(array_map('intval', (array) ($fixture['word_ids'] ?? [])));
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        foreach ($word_ids as $index => $word_id) {
            $category_id = in_array($index, [2, 6, 7, 8, 9], true) ? $category_ids[1] : $category_ids[0];
            $this->seedWordProgressRow($user_id, $word_id, $category_id, (int) $fixture['wordset_id'], [
                'total_coverage' => 1,
                'coverage_practice' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
        }

        $five_words = static function (): int {
            return 5;
        };
        $one_category = static function (): int {
            return 1;
        };
        add_filter('ll_tools_user_study_selection_launch_max_words', $five_words);
        add_filter('ll_tools_user_study_selection_launch_preferred_categories', $one_category);
        add_filter('ll_tools_user_study_selection_launch_max_categories', $one_category);
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                (int) $fixture['wordset_id'],
                $category_ids,
                'studied',
                'practice'
            );
        } finally {
            remove_filter('ll_tools_user_study_selection_launch_max_words', $five_words);
            remove_filter('ll_tools_user_study_selection_launch_preferred_categories', $one_category);
            remove_filter('ll_tools_user_study_selection_launch_max_categories', $one_category);
        }

        $this->assertIsArray($plan);
        $this->assertCount(5, (array) ($plan['word_ids'] ?? []));
        $this->assertCount(1, (array) ($plan['category_ids'] ?? []));
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(2, $chunks);
        $planned_word_ids = [];
        $planned_category_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $chunk_category_ids = array_values(array_map('intval', (array) ($chunk['category_ids'] ?? [])));
            $this->assertCount(5, $chunk_word_ids);
            $this->assertCount(1, $chunk_category_ids);
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
            $planned_category_ids = array_merge($planned_category_ids, $chunk_category_ids);
        }
        $expected_word_ids = $word_ids;
        sort($expected_word_ids);
        $unique_planned_word_ids = array_values(array_unique($planned_word_ids));
        sort($unique_planned_word_ids);
        $this->assertSame($expected_word_ids, $unique_planned_word_ids);
        $this->assertCount(count($planned_word_ids), $unique_planned_word_ids, 'A matching word must appear in exactly one launch chunk.');
        $expected_category_ids = $category_ids;
        sort($expected_category_ids);
        $planned_category_ids = array_values(array_unique($planned_category_ids));
        sort($planned_category_ids);
        $this->assertSame($expected_category_ids, $planned_category_ids);
        $this->assertSame($chunks[0]['category_ids'] ?? [], (array) ($plan['category_ids'] ?? []));
        $this->assertSame($chunks[0]['word_ids'] ?? [], (array) ($plan['word_ids'] ?? []));
        $this->assertSame(10, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(10, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(2, (int) ($plan['chunk_count'] ?? 0));
        $this->assertFalse((bool) ($plan['truncated'] ?? true));
    }

    public function test_selection_launch_plan_preserves_more_than_thirty_words_across_more_than_eight_categories(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Chunk Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_ids = [];
        $word_ids = [];
        $word_category_lookup = [];
        for ($category_index = 1; $category_index <= 10; $category_index++) {
            $category = wp_insert_term(
                'Analytics Chunk Category ' . $category_index . ' ' . wp_generate_password(6, false),
                'word-category'
            );
            $this->assertFalse(is_wp_error($category));
            $this->assertIsArray($category);
            $source_category_id = (int) $category['term_id'];
            update_term_meta($source_category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($source_category_id, 'll_quiz_option_type', 'text_title');

            $category_word_ids = [];
            for ($word_index = 1; $word_index <= 4; $word_index++) {
                $word_id = $this->createWordWithAudio(
                    'Analytics Chunk Word ' . $category_index . '-' . $word_index,
                    'Analytics Chunk Translation ' . $category_index . '-' . $word_index,
                    $source_category_id,
                    $wordset_id,
                    'analytics-chunk-' . $category_index . '-' . $word_index . '.mp3'
                );
                $category_word_ids[] = $word_id;
                $word_ids[] = $word_id;
            }

            $effective_category_id = $this->resolveEffectiveCategoryId($source_category_id, $wordset_id);
            $category_ids[] = $effective_category_id;
            foreach ($category_word_ids as $word_id) {
                $word_category_lookup[$word_id] = $effective_category_id;
                $this->seedWordProgressRow($user_id, $word_id, $effective_category_id, $wordset_id, [
                    'total_coverage' => 2,
                    'coverage_practice' => 2,
                    'correct_clean' => 1,
                    'stage' => 2,
                ]);
            }
        }
        $this->assertGreaterThan(30, count($word_ids));
        $this->assertGreaterThan(8, count($category_ids));

        $minimum_quiz_words = static function (): int {
            return 1;
        };
        $ten_words = static function (): int {
            return 10;
        };
        $three_categories = static function (): int {
            return 3;
        };
        $queries = [];
        $capture_query = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('ll_tools_quiz_min_words', $minimum_quiz_words);
        add_filter('ll_tools_user_study_selection_launch_max_words', $ten_words);
        add_filter('ll_tools_user_study_selection_launch_preferred_categories', $three_categories);
        add_filter('ll_tools_user_study_selection_launch_max_categories', $three_categories);
        add_filter('query', $capture_query);
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                $category_ids,
                'studied',
                'practice'
            );
        } finally {
            remove_filter('query', $capture_query);
            remove_filter('ll_tools_user_study_selection_launch_max_categories', $three_categories);
            remove_filter('ll_tools_user_study_selection_launch_preferred_categories', $three_categories);
            remove_filter('ll_tools_user_study_selection_launch_max_words', $ten_words);
            remove_filter('ll_tools_quiz_min_words', $minimum_quiz_words);
        }

        $this->assertIsArray($plan);
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertNotEmpty($chunks);
        $planned_word_ids = [];
        $planned_category_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $chunk_category_ids = array_values(array_map('intval', (array) ($chunk['category_ids'] ?? [])));
            $this->assertNotEmpty($chunk_word_ids);
            $this->assertNotEmpty($chunk_category_ids);
            $this->assertLessThanOrEqual(10, count($chunk_word_ids));
            $this->assertLessThanOrEqual(3, count($chunk_category_ids));
            $this->assertGreaterThanOrEqual(5, count($chunk_word_ids), 'A balanced plan should avoid a sub-minimum tail when enough words exist.');
            foreach ($chunk_word_ids as $word_id) {
                $this->assertContains((int) ($word_category_lookup[$word_id] ?? 0), $chunk_category_ids);
            }
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
            $planned_category_ids = array_merge($planned_category_ids, $chunk_category_ids);
        }

        $expected_word_ids = $word_ids;
        sort($expected_word_ids);
        $unique_planned_word_ids = array_values(array_unique($planned_word_ids));
        sort($unique_planned_word_ids);
        $this->assertSame($expected_word_ids, $unique_planned_word_ids);
        $this->assertCount(count($planned_word_ids), $unique_planned_word_ids, 'Chunks must not duplicate matching words.');
        $expected_category_ids = $category_ids;
        sort($expected_category_ids);
        $planned_category_ids = array_values(array_unique($planned_category_ids));
        sort($planned_category_ids);
        $this->assertSame($expected_category_ids, $planned_category_ids);
        $this->assertSame($chunks[0]['category_ids'] ?? [], (array) ($plan['category_ids'] ?? []));
        $this->assertSame($chunks[0]['word_ids'] ?? [], (array) ($plan['word_ids'] ?? []));
        $this->assertSame(40, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(40, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(count($chunks), (int) ($plan['chunk_count'] ?? 0));
        $this->assertFalse((bool) ($plan['truncated'] ?? true));

        $progress_table = ll_tools_user_progress_table_names()['words'];
        $this->assertStringNotContainsStringIgnoringCase(
            'SELECT * FROM ' . $progress_table,
            implode("\n", $queries),
            'Selection planning should use projected progress rows rather than SELECT *.'
        );
    }

    public function test_learning_selection_launch_plan_separates_compatibility_groups_and_adds_only_compatible_fillers(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Learning Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $audio_text = $this->createLearningSelectionCategory(
            $wordset_id,
            'Audio Text',
            'audio',
            'text_title',
            8
        );
        $text_text = $this->createLearningSelectionCategory(
            $wordset_id,
            'Text Text',
            'text_title',
            'text_title',
            8
        );
        $target_word_ids = array_merge(
            array_slice($audio_text['word_ids'], 0, 5),
            array_slice($text_text['word_ids'], 0, 5)
        );

        $plan = ll_tools_build_user_study_selection_launch_plan(
            $user_id,
            $wordset_id,
            [$audio_text['category_id'], $text_text['category_id']],
            '',
            'learning',
            $target_word_ids
        );

        $this->assertIsArray($plan);
        $this->assertSame('learning', (string) ($plan['mode'] ?? ''));
        $this->assertSame(10, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(16, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(6, (int) ($plan['expanded_count'] ?? 0));
        $this->assertSame(8, (int) ($plan['minimum_words'] ?? 0));
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(2, $chunks);

        $expected_keys = ['no-image|audio->text', 'no-image|text->text'];
        $actual_keys = [];
        $planned_word_ids = [];
        $planned_target_word_ids = [];
        $all_words_by_category = [
            (int) $audio_text['category_id'] => array_values(array_map('intval', $audio_text['word_ids'])),
            (int) $text_text['category_id'] => array_values(array_map('intval', $text_text['word_ids'])),
        ];
        foreach ($chunks as $chunk) {
            $chunk_category_ids = array_values(array_map('intval', (array) ($chunk['category_ids'] ?? [])));
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $chunk_target_word_ids = array_values(array_map('intval', (array) ($chunk['target_word_ids'] ?? [])));
            $compatibility_key = (string) ($chunk['compatibility_key'] ?? '');
            $this->assertCount(1, $chunk_category_ids);
            $this->assertCount(8, $chunk_word_ids);
            $this->assertCount(5, $chunk_target_word_ids);
            $this->assertSame([], array_values(array_diff($chunk_target_word_ids, $chunk_word_ids)));
            $this->assertContains($compatibility_key, $expected_keys);
            foreach ($chunk_word_ids as $word_id) {
                $this->assertContains($word_id, $all_words_by_category[$chunk_category_ids[0]] ?? []);
            }
            $actual_keys[] = $compatibility_key;
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
            $planned_target_word_ids = array_merge($planned_target_word_ids, $chunk_target_word_ids);
        }
        sort($expected_keys);
        sort($actual_keys);
        $this->assertSame($expected_keys, $actual_keys);
        $this->assertCount(16, array_unique($planned_word_ids));
        sort($target_word_ids);
        $planned_target_word_ids = array_values(array_unique($planned_target_word_ids));
        sort($planned_target_word_ids);
        $this->assertSame($target_word_ids, $planned_target_word_ids);
        $this->assertSame($chunks[0]['category_ids'], $plan['category_ids']);
        $this->assertSame($chunks[0]['word_ids'], $plan['word_ids']);
        $this->assertSame($chunks[0]['target_word_ids'], $plan['target_word_ids']);
        $this->assertSame($chunks[0]['compatibility_key'], $plan['compatibility_key']);
    }

    public function test_selection_category_payload_cache_serves_zazaca_scale_map_without_per_category_resolution(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Presentation Cache Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_rows = [];
        $category_ids = [];
        $id_base = 1000000 + ($wordset_id * 1000);
        for ($index = 1; $index <= 210; $index++) {
            $category_id = $id_base + $index;
            $category_ids[] = $category_id;
            $category_rows[$category_id] = [
                'id' => $category_id,
                'mode' => 'text_translation',
                'prompt_type' => 'audio',
                'option_type' => 'text_translation',
                'learning_prompt_type' => 'audio_text_title',
                'learning_option_type' => 'text_translation',
                'learning_supported' => true,
                'self_check_supported' => true,
                'sign_language_mode' => false,
                'aspect_bucket' => 'no-image',
            ];
        }

        $cache_statuses = [];
        $resolved_category_ids = [];
        $capture_cache_status = static function (string $status) use (&$cache_statuses): void {
            $cache_statuses[] = $status;
        };
        $capture_resolve = static function (int $category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_cache_status', $capture_cache_status, 10, 1);
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, $category_rows);
            $queries_before_lookup = get_num_queries();
            $payload = ll_tools_user_progress_selection_category_payload_map($category_ids, $wordset_id);
            $lookup_query_count = get_num_queries() - $queries_before_lookup;
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_cache_status', $capture_cache_status, 10);
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $this->assertCount(210, $payload);
        $this->assertContains('store', $cache_statuses);
        $this->assertContains('hit', $cache_statuses);
        $this->assertSame([], $resolved_category_ids, 'A primed aggregate must not resolve 210 categories again.');
        $this->assertLessThanOrEqual(2, $lookup_query_count, 'The warm aggregate lookup must stay O(1) in SQL queries.');
        $this->assertSame('audio_text_title', (string) ($payload[$category_ids[0]]['learning_prompt_type'] ?? ''));
        $this->assertSame('no-image', (string) ($payload[$category_ids[209]]['aspect_bucket'] ?? ''));
    }

    public function test_selection_category_payload_cache_key_is_shared_across_users(): void
    {
        $wordset = wp_insert_term(
            'Analytics Shared Presentation Cache ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $first_user_id = self::factory()->user->create(['role' => 'subscriber']);
        $second_user_id = self::factory()->user->create(['role' => 'subscriber']);

        wp_set_current_user($first_user_id);
        $first_key = ll_tools_user_progress_selection_category_payload_cache_key($wordset_id);
        wp_set_current_user($second_user_id);
        $second_key = ll_tools_user_progress_selection_category_payload_cache_key($wordset_id);

        $this->assertSame(
            $first_key,
            $second_key,
            'Presentation metadata is wordset content, not learner-specific state.'
        );
    }

    public function test_selection_category_payload_partial_prime_does_not_relabel_an_untouched_stale_row(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Partial Presentation Cache Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category_a = wp_insert_term(
            'Analytics Partial Presentation A ' . wp_generate_password(6, false),
            'word-category'
        );
        $category_b = wp_insert_term(
            'Analytics Partial Presentation B ' . wp_generate_password(6, false),
            'word-category'
        );
        $this->assertFalse(is_wp_error($category_a));
        $this->assertFalse(is_wp_error($category_b));
        $category_a_id = (int) $category_a['term_id'];
        $category_b_id = (int) $category_b['term_id'];

        $row_for = static function (int $category_id, string $prompt_type): array {
            return [
                'id' => $category_id,
                'mode' => 'text_translation',
                'prompt_type' => $prompt_type,
                'option_type' => 'text_translation',
                'learning_supported' => true,
                'self_check_supported' => true,
                'aspect_bucket' => 'no-image',
            ];
        };
        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, [
            $row_for($category_a_id, 'audio'),
            $row_for($category_b_id, 'audio'),
        ]);
        $cached_before = ll_tools_user_progress_get_cached_selection_category_payload($wordset_id);
        $stored_a_version = (int) ($cached_before['category_versions'][$category_a_id] ?? 0);
        $this->assertContains(
            $category_a_id,
            ll_tools_bump_category_cache_versions_only([$category_a_id])
        );

        ll_tools_user_progress_prime_selection_category_payload_cache(
            $wordset_id,
            [$row_for($category_b_id, 'text_title')]
        );
        $cached_after_partial_prime = ll_tools_user_progress_get_cached_selection_category_payload($wordset_id);
        $this->assertSame(
            $stored_a_version,
            (int) ($cached_after_partial_prime['category_versions'][$category_a_id] ?? 0),
            'Updating B must not stamp A\'s stale payload with A\'s new version.'
        );

        $resolved_category_ids = [];
        $capture_resolve = static function (int $category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            $payload = ll_tools_user_progress_selection_category_payload_map(
                [$category_a_id, $category_b_id],
                $wordset_id
            );
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $this->assertSame([$category_a_id], $resolved_category_ids);
        $this->assertArrayHasKey($category_a_id, $payload);
        $this->assertSame('text_title', (string) ($payload[$category_b_id]['prompt_type'] ?? ''));
    }

    public function test_selection_category_payload_re_resolves_when_only_the_aspect_version_changes(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Aspect Presentation Cache Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Aspect Presentation Cache',
            'text_title',
            'text_translation',
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $category_id = (int) $category['category_id'];

        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, [[
            'id' => $category_id,
            'mode' => 'text_translation',
            'prompt_type' => 'text_title',
            'option_type' => 'text_translation',
            'learning_supported' => true,
            'self_check_supported' => true,
            'aspect_bucket' => 'stale-aspect',
        ]]);
        $cached_before = ll_tools_user_progress_get_cached_selection_category_payload($wordset_id);
        $stored_category_version = (int) ($cached_before['category_versions'][$category_id] ?? 0);
        $stored_aspect_version = (int) ($cached_before['aspect_versions'][$category_id] ?? 0);
        update_term_meta(
            $category_id,
            LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY,
            $stored_aspect_version + 1
        );

        $resolved_category_ids = [];
        $capture_resolve = static function (int $resolved_category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $resolved_category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            $payload = ll_tools_user_progress_selection_category_payload_map([$category_id], $wordset_id);
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $cached_after = ll_tools_user_progress_get_cached_selection_category_payload($wordset_id);
        $this->assertSame(
            $stored_category_version,
            (int) ($cached_after['category_versions'][$category_id] ?? 0),
            'The dedicated aspect version must invalidate the aggregate even when the category version is unchanged.'
        );
        $this->assertSame([$category_id], $resolved_category_ids);
        $this->assertArrayHasKey($category_id, $payload);
        $this->assertSame('no-image', (string) ($payload[$category_id]['aspect_bucket'] ?? ''));
        $this->assertSame(
            $stored_aspect_version + 1,
            (int) ($cached_after['aspect_versions'][$category_id] ?? 0)
        );
    }

    public function test_processed_category_cache_cannot_reprime_a_stale_aspect_bucket(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Processed Aspect Cache Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Processed Aspect Cache',
            'audio',
            'image',
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $category_id = (int) $category['category_id'];
        $term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        update_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, '4:3');
        ll_tools_clear_category_aspect_cache($category_id);
        $first_complete = true;
        $first_catalog = ll_flashcards_get_processed_categories_cached(
            [$term],
            false,
            0,
            [$wordset_id],
            $first_complete
        );
        $this->assertTrue($first_complete);
        $this->assertCount(1, $first_catalog);
        $this->assertSame('ratio:4:3', (string) ($first_catalog[0]['aspect_bucket'] ?? ''));

        update_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, '16:9');
        ll_tools_clear_category_aspect_cache($category_id);
        $second_complete = true;
        $second_catalog = ll_flashcards_get_processed_categories_cached(
            [$term],
            false,
            0,
            [$wordset_id],
            $second_complete
        );
        $this->assertTrue($second_complete);
        $this->assertCount(1, $second_catalog);
        $this->assertSame('ratio:16:9', (string) ($second_catalog[0]['aspect_bucket'] ?? ''));

        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, $second_catalog);
        $resolved_category_ids = [];
        $capture_resolve = static function (int $resolved_category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $resolved_category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            $payload = ll_tools_user_progress_selection_category_payload_map([$category_id], $wordset_id);
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $this->assertSame([], $resolved_category_ids);
        $this->assertSame('ratio:16:9', (string) ($payload[$category_id]['aspect_bucket'] ?? ''));
    }

    public function test_aspect_version_snapshot_cannot_stamp_a_concurrently_stale_row_as_current(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Concurrent Aspect Cache Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Concurrent Aspect Cache',
            'text_title',
            'text_translation',
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $category_id = (int) $category['category_id'];

        update_term_meta($category_id, LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY, 3);
        $captured_versions = ll_tools_get_category_aspect_cache_versions([$category_id]);
        $this->assertSame(3, (int) ($captured_versions[$category_id] ?? 0));
        $updated = $wpdb->update(
            $wpdb->termmeta,
            ['meta_value' => '4'],
            [
                'term_id' => $category_id,
                'meta_key' => LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY,
            ],
            ['%s'],
            ['%d', '%s']
        );
        $this->assertSame(1, $updated);

        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, [[
            'id' => $category_id,
            'mode' => 'text_translation',
            'prompt_type' => 'text_title',
            'option_type' => 'text_translation',
            'learning_supported' => true,
            'self_check_supported' => true,
            'aspect_bucket' => 'stale-concurrent-aspect',
        ]]);
        $cached = ll_tools_user_progress_get_cached_selection_category_payload($wordset_id);
        $this->assertSame(
            3,
            (int) ($cached['aspect_versions'][$category_id] ?? 0),
            'The prime must retain the version captured with its authoritative source rows.'
        );

        ll_tools_epoch_request_cache_reset(
            ll_tools_epoch_request_cache_key(
                'term',
                $category_id,
                LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY
            )
        );
        $resolved_category_ids = [];
        $capture_resolve = static function (int $resolved_category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $resolved_category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            $payload = ll_tools_user_progress_selection_category_payload_map([$category_id], $wordset_id);
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $this->assertSame([$category_id], $resolved_category_ids);
        $this->assertArrayHasKey($category_id, $payload);
        $this->assertSame('no-image', (string) ($payload[$category_id]['aspect_bucket'] ?? ''));
    }

    public function test_selection_category_payload_cache_write_failure_does_not_fail_the_current_resolution(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $wordset = wp_insert_term(
            'Analytics Presentation Write Failure Wordset ' . wp_generate_password(6, false),
            'wordset'
        );
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Presentation Write Failure',
            'text_title',
            'text_translation',
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $category_id = (int) $category['category_id'];
        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, [[
            'id' => $category_id,
            'mode' => 'text_translation',
            'prompt_type' => 'text_title',
            'option_type' => 'text_translation',
            'learning_supported' => true,
            'self_check_supported' => true,
            'aspect_bucket' => 'no-image',
        ]]);
        $this->assertContains(
            $category_id,
            ll_tools_bump_category_cache_versions_only([$category_id])
        );

        $cache_key = ll_tools_user_progress_selection_category_payload_cache_key($wordset_id);
        $inject_write_error = static function ($value, $old_value) use ($wpdb) {
            $wpdb->last_error = 'simulated selection payload cache write failure';
            return $old_value;
        };
        $cache_statuses = [];
        $capture_cache_status = static function (string $status) use (&$cache_statuses): void {
            $cache_statuses[] = $status;
        };
        $transient_option_filter = 'pre_update_option__transient_' . $cache_key;
        add_filter($transient_option_filter, $inject_write_error, 10, 2);
        add_action('ll_tools_user_progress_selection_category_payload_cache_status', $capture_cache_status, 10, 1);
        try {
            $complete = true;
            $payload = ll_tools_user_progress_selection_category_payload_map(
                [$category_id],
                $wordset_id,
                $complete
            );
        } finally {
            remove_filter($transient_option_filter, $inject_write_error, 10);
            remove_action('ll_tools_user_progress_selection_category_payload_cache_status', $capture_cache_status, 10);
        }

        $this->assertTrue($complete);
        $this->assertArrayHasKey($category_id, $payload);
        $this->assertContains('store_failed', $cache_statuses);
        $this->assertSame('', $wpdb->last_error);
    }

    public function test_learning_selection_launch_plan_balances_large_exact_scope_into_eight_to_fifteen_word_chunks(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Large Learning Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Large Learning',
            'audio',
            'text_translation',
            24
        );

        $catalog_build_count = 0;
        $capture_catalog_build = static function (int $requested_wordset_id) use (&$catalog_build_count): void {
            $catalog_build_count++;
        };
        add_action(
            'll_tools_user_study_categories_for_wordset_before_build',
            $capture_catalog_build,
            10,
            1
        );
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                [$category['category_id']],
                '',
                'learning',
                $category['word_ids']
            );
        } finally {
            remove_action(
                'll_tools_user_study_categories_for_wordset_before_build',
                $capture_catalog_build,
                10
            );
        }

        $this->assertIsArray($plan);
        $this->assertSame(0, $catalog_build_count, 'Exact Learning planning must use lightweight presentation metadata.');
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(2, $chunks);
        $planned_word_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $chunk_target_word_ids = array_values(array_map('intval', (array) ($chunk['target_word_ids'] ?? [])));
            $this->assertCount(12, $chunk_word_ids);
            $this->assertSame($chunk_word_ids, $chunk_target_word_ids);
            $this->assertSame('no-image|audio->text', (string) ($chunk['compatibility_key'] ?? ''));
            $this->assertLessThanOrEqual(15, count($chunk_word_ids));
            $this->assertGreaterThanOrEqual(8, count($chunk_word_ids));
            $this->assertLessThanOrEqual(8, count((array) ($chunk['category_ids'] ?? [])));
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
        }
        $expected_word_ids = array_values(array_map('intval', $category['word_ids']));
        sort($expected_word_ids);
        sort($planned_word_ids);
        $this->assertSame($expected_word_ids, $planned_word_ids);
        $this->assertSame(24, (int) ($plan['matched_count'] ?? 0));
        $this->assertSame(24, (int) ($plan['planned_count'] ?? 0));
        $this->assertSame(0, (int) ($plan['expanded_count'] ?? -1));
        $this->assertSame('no-image|audio->text', (string) ($plan['compatibility_key'] ?? ''));
    }

    public function test_learning_selection_launch_plan_uses_effective_audio_to_text_fallback_metadata(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Fallback Learning Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = wp_insert_term(
            'Analytics Fallback Learning Category ' . wp_generate_password(6, false),
            'word-category'
        );
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $source_category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($source_category_id, $wordset_id, $source_category_id);
        update_term_meta($source_category_id, 'll_quiz_prompt_type', 'text_title');
        // Keep publication permissive while creating the six no-audio words;
        // switch to audio afterward so the effective resolver must fall back.
        update_term_meta($source_category_id, 'll_quiz_option_type', 'text_title');

        $word_ids = [];
        for ($index = 1; $index <= 4; $index++) {
            $word_ids[] = $this->createWordWithAudio(
                'Analytics Fallback Audio Word ' . $index,
                'Analytics Fallback Translation ' . $index,
                $source_category_id,
                $wordset_id,
                'analytics-fallback-' . $index . '.mp3'
            );
        }
        for ($index = 5; $index <= 10; $index++) {
            $word_id = $this->createWordWithoutAudio(
                'Analytics Fallback Text Word ' . $index,
                $source_category_id,
                $wordset_id
            );
            update_post_meta($word_id, 'word_translation', 'Analytics Fallback Translation ' . $index);
            $word_ids[] = $word_id;
        }

        $category_id = $this->resolveEffectiveCategoryId($source_category_id, $wordset_id);
        foreach (array_values(array_unique([$source_category_id, $category_id])) as $configured_category_id) {
            update_term_meta($configured_category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($configured_category_id, 'll_quiz_option_type', 'audio');
        }
        $effective_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $effective_term);
        $audio_count_complete = true;
        $audio_count = ll_get_words_by_category_count(
            $effective_term,
            'audio',
            [$wordset_id],
            [
                'prompt_type' => 'text_title',
                'option_type' => 'audio',
            ],
            $audio_count_complete,
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $text_count_complete = true;
        $text_count = ll_get_words_by_category_count(
            $effective_term,
            'text',
            [$wordset_id],
            [
                'prompt_type' => 'text_title',
                'option_type' => 'text_translation',
            ],
            $text_count_complete,
            LL_TOOLS_MIN_WORDS_PER_QUIZ
        );
        $this->assertTrue($audio_count_complete);
        $this->assertTrue($text_count_complete);
        $this->assertSame(
            [4, LL_TOOLS_MIN_WORDS_PER_QUIZ],
            [$audio_count, $text_count],
            'The fixture must leave audio below threshold while text reaches it.'
        );
        $config_complete = true;
        $effective_config = ll_tools_resolve_effective_category_quiz_config(
            $effective_term,
            LL_TOOLS_MIN_WORDS_PER_QUIZ,
            [$wordset_id],
            $config_complete
        );
        $this->assertTrue($config_complete);
        $this->assertSame('text_translation', (string) ($effective_config['option_type'] ?? ''));

        ll_tools_user_progress_prime_selection_category_payload_cache($wordset_id, [[
            'id' => $category_id,
            'mode' => 'text_translation',
            'prompt_type' => (string) ($effective_config['prompt_type'] ?? 'text_title'),
            'option_type' => (string) ($effective_config['option_type'] ?? 'text_translation'),
            'learning_prompt_type' => (string) ($effective_config['learning_prompt_type'] ?? ''),
            'learning_option_type' => (string) ($effective_config['learning_option_type'] ?? ''),
            'learning_supported' => !empty($effective_config['learning_supported']),
            'self_check_supported' => !empty($effective_config['self_check_supported']),
            'sign_language_mode' => !empty($effective_config['sign_language_mode']),
            'aspect_bucket' => 'no-image',
        ]]);
        $resolved_category_ids = [];
        $capture_resolve = static function (int $resolved_category_id) use (&$resolved_category_ids): void {
            $resolved_category_ids[] = $resolved_category_id;
        };
        add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
        try {
            $plan = ll_tools_build_user_study_selection_launch_plan(
                $user_id,
                $wordset_id,
                [$category_id],
                '',
                'learning',
                $word_ids
            );
        } finally {
            remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
        }

        $this->assertIsArray($plan);
        $this->assertSame([], $resolved_category_ids, 'The effective fallback metadata should come from the primed aggregate.');
        $this->assertSame('no-image|text->text', (string) ($plan['compatibility_key'] ?? ''));
        $chunks = array_values((array) ($plan['chunks'] ?? []));
        $this->assertCount(1, $chunks);
        $this->assertSame('no-image|text->text', (string) ($chunks[0]['compatibility_key'] ?? ''));
        $this->assertCount(10, (array) ($chunks[0]['target_word_ids'] ?? []));
    }

    public function test_learning_selection_launch_plan_fails_closed_when_a_compatibility_group_cannot_reach_eight_words(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Short Learning Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = $this->createLearningSelectionCategory(
            $wordset_id,
            'Short Learning',
            'audio',
            'text_title',
            7
        );

        $plan = ll_tools_build_user_study_selection_launch_plan(
            $user_id,
            $wordset_id,
            [$category['category_id']],
            '',
            'learning',
            array_slice($category['word_ids'], 0, 5)
        );

        $this->assertWPError($plan);
        $this->assertSame('learning_selection_unlaunchable', $plan->get_error_code());
    }

    public function test_learning_selection_chunk_builder_rehomes_shared_fillers_for_constrained_groups(): void
    {
        $audio_target_ids = range(1001, 1006);
        $text_target_ids = range(2001, 2007);
        $shared_filler_ids = [9001, 9002];
        $audio_only_filler_id = 9003;
        $chunks = ll_tools_build_user_study_learning_selection_launch_chunks(
            [
                101 => $audio_target_ids,
                201 => $text_target_ids,
            ],
            [
                101 => array_merge($audio_target_ids, $shared_filler_ids, [$audio_only_filler_id]),
                201 => array_merge($text_target_ids, $shared_filler_ids),
            ],
            [
                101 => [
                    'aspect_bucket' => 'no-image',
                    'prompt_type' => 'audio',
                    'option_type' => 'text_title',
                    'learning_supported' => true,
                ],
                201 => [
                    'aspect_bucket' => 'no-image',
                    'prompt_type' => 'text_title',
                    'option_type' => 'text_title',
                    'learning_supported' => true,
                ],
            ],
            15,
            8
        );

        $this->assertIsArray($chunks);
        $this->assertCount(2, $chunks);
        $chunks_by_key = [];
        foreach ($chunks as $chunk) {
            $chunks_by_key[(string) ($chunk['compatibility_key'] ?? '')] = $chunk;
        }
        $audio_filler_ids = array_values(array_diff(
            array_map('intval', (array) ($chunks_by_key['no-image|audio->text']['word_ids'] ?? [])),
            $audio_target_ids
        ));
        $text_filler_ids = array_values(array_diff(
            array_map('intval', (array) ($chunks_by_key['no-image|text->text']['word_ids'] ?? [])),
            $text_target_ids
        ));
        $audio_shared_filler_ids = array_values(array_intersect($audio_filler_ids, $shared_filler_ids));
        $text_shared_filler_ids = array_values(array_intersect($text_filler_ids, $shared_filler_ids));
        $this->assertCount(2, $audio_filler_ids);
        $this->assertContains(
            $audio_only_filler_id,
            $audio_filler_ids,
            'The first group must rehome one of its shared assignments to the group-specific filler.'
        );
        $this->assertCount(1, $audio_shared_filler_ids);
        $this->assertCount(1, $text_filler_ids);
        $this->assertCount(
            1,
            $text_shared_filler_ids,
            'The later group must receive a shared filler after the augmenting path reassigns the earlier slots.'
        );
        $this->assertNotSame($audio_shared_filler_ids[0], $text_shared_filler_ids[0]);
        $planned_word_ids = array_merge(
            (array) ($chunks_by_key['no-image|audio->text']['word_ids'] ?? []),
            (array) ($chunks_by_key['no-image|text->text']['word_ids'] ?? [])
        );
        $this->assertCount(16, array_unique(array_map('intval', $planned_word_ids)));
    }

    public function test_learning_selection_chunk_builder_keeps_sparse_category_groups_bounded(): void
    {
        $matched_by_category = [];
        $word_ids_by_category = [];
        $category_payload_lookup = [];
        $expected_target_ids = [];
        for ($index = 1; $index <= 9; $index++) {
            $category_id = 100 + $index;
            $target_word_id = 1000 + $index;
            $filler_word_id = 2000 + $index;
            $matched_by_category[$category_id] = [$target_word_id];
            $word_ids_by_category[$category_id] = [$target_word_id, $filler_word_id];
            $category_payload_lookup[$category_id] = [
                'aspect_bucket' => 'ratio:4:3',
                'prompt_type' => 'audio_text_title',
                'option_type' => 'text_translation',
                'learning_supported' => true,
            ];
            $expected_target_ids[] = $target_word_id;
        }

        $chunks = ll_tools_build_user_study_learning_selection_launch_chunks(
            $matched_by_category,
            $word_ids_by_category,
            $category_payload_lookup,
            15,
            8
        );

        $this->assertIsArray($chunks);
        $this->assertCount(2, $chunks);
        $planned_word_ids = [];
        $planned_target_ids = [];
        foreach ($chunks as $chunk) {
            $chunk_word_ids = array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
            $this->assertCount(8, $chunk_word_ids);
            $this->assertLessThanOrEqual(8, count((array) ($chunk['category_ids'] ?? [])));
            $this->assertSame('ratio:4:3|audio_text->text', (string) ($chunk['compatibility_key'] ?? ''));
            $planned_word_ids = array_merge($planned_word_ids, $chunk_word_ids);
            $planned_target_ids = array_merge(
                $planned_target_ids,
                array_map('intval', (array) ($chunk['target_word_ids'] ?? []))
            );
        }
        $this->assertCount(16, array_unique($planned_word_ids));
        sort($expected_target_ids);
        sort($planned_target_ids);
        $this->assertSame($expected_target_ids, $planned_target_ids);
    }

    public function test_selection_launch_chunk_balancing_avoids_a_one_word_tail(): void
    {
        $chunks = ll_tools_build_user_study_selection_launch_chunks([
            101 => range(1, 31),
        ], 15, 5, 5);

        $this->assertSame([11, 10, 10], array_values(array_map(static function (array $chunk): int {
            return count((array) ($chunk['word_ids'] ?? []));
        }, $chunks)));
        $this->assertSame([[101], [101], [101]], array_values(array_map(static function (array $chunk): array {
            return array_values(array_map('intval', (array) ($chunk['category_ids'] ?? [])));
        }, $chunks)));
    }

    public function test_selection_launch_starts_with_one_dominant_category_for_fast_hydration(): void
    {
        $chunks = ll_tools_build_user_study_selection_launch_chunks([
            101 => range(1001, 1031),
            102 => range(2001, 2005),
            103 => range(3001, 3005),
        ], 15, 3, 5);

        $this->assertCount(3, $chunks);
        $this->assertSame([14, 14, 13], array_values(array_map(static function (array $chunk): int {
            return count((array) ($chunk['word_ids'] ?? []));
        }, $chunks)));
        $this->assertSame(
            [101],
            array_values(array_map('intval', (array) ($chunks[0]['category_ids'] ?? []))),
            'The first runnable chunk should require only one category payload request.'
        );
        $this->assertSame(41, count(array_unique(array_merge(...array_map(static function (array $chunk): array {
            return array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
        }, $chunks)))));
        $this->assertSame(5, array_sum(array_map(static function (array $chunk): int {
            return count((array) ($chunk['category_ids'] ?? []));
        }, $chunks)), 'Startup-first ordering must move, not multiply, category hydration requests.');
    }

    public function test_selection_launch_keeps_equal_category_queues_contiguous_for_request_efficiency(): void
    {
        $matched_by_category = [];
        for ($offset = 0; $offset < 8; $offset++) {
            $category_id = 201 + $offset;
            $matched_by_category[$category_id] = range(4001 + ($offset * 15), 4015 + ($offset * 15));
        }

        $chunks = ll_tools_build_user_study_selection_launch_chunks($matched_by_category, 15, 8, 5);

        $this->assertCount(8, $chunks);
        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1], array_values(array_map(static function (array $chunk): int {
            return count((array) ($chunk['category_ids'] ?? []));
        }, $chunks)), 'Equal category queues should require one candidate request per chunk, not eight.');
        $this->assertSame(120, count(array_unique(array_merge(...array_map(static function (array $chunk): array {
            return array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
        }, $chunks)))));
    }

    public function test_selection_launch_places_an_isolated_catch_all_before_diverse_light_batches(): void
    {
        $matched_by_category = [
            100 => range(10001, 10093),
        ];
        for ($offset = 0; $offset < 78; $offset++) {
            $category_id = 201 + $offset;
            $first_word_id = 20001 + ($offset * 2);
            $matched_by_category[$category_id] = [$first_word_id, $first_word_id + 1];
        }

        $chunks = ll_tools_build_user_study_selection_launch_chunks($matched_by_category, 15, 3, 5);

        $this->assertCount(33, $chunks);
        $this->assertGreaterThanOrEqual(5, count((array) ($chunks[0]['word_ids'] ?? [])));
        $this->assertCount(1, (array) ($chunks[0]['category_ids'] ?? []));
        $this->assertSame([100], array_values(array_map('intval', (array) ($chunks[0]['category_ids'] ?? []))));
        $this->assertSame(249, count(array_unique(array_merge(...array_map(static function (array $chunk): array {
            return array_values(array_map('intval', (array) ($chunk['word_ids'] ?? [])));
        }, $chunks)))));
        $this->assertSame(85, array_sum(array_map(static function (array $chunk): int {
            return count((array) ($chunk['category_ids'] ?? []));
        }, $chunks)), 'Batch ordering must move, not multiply, category hydration requests.');
    }

    public function test_selection_launch_uses_the_preferred_category_soft_cap_when_valid(): void
    {
        $matched_by_category = [];
        for ($index = 1; $index <= 5; $index++) {
            $matched_by_category[300 + $index] = [3000 + ($index * 2) - 1, 3000 + ($index * 2)];
        }

        $chunks = ll_tools_build_launchable_user_study_selection_chunks(
            $matched_by_category,
            15,
            5,
            8,
            5,
            3
        );

        $this->assertIsArray($chunks);
        $this->assertCount(2, $chunks, 'A valid preferred-cap plan should not widen to the configured maximum.');
        $planned_word_ids = [];
        foreach ($chunks as $chunk) {
            $this->assertCount(5, (array) ($chunk['word_ids'] ?? []));
            $this->assertLessThanOrEqual(3, count((array) ($chunk['category_ids'] ?? [])));
            $planned_word_ids = array_merge($planned_word_ids, (array) ($chunk['word_ids'] ?? []));
        }
        sort($planned_word_ids);
        $this->assertSame(range(3001, 3010), $planned_word_ids);
    }

    public function test_selection_launch_sparse_singletons_widen_to_the_hard_category_cap(): void
    {
        $matched_by_category = [];
        for ($index = 1; $index <= 6; $index++) {
            $matched_by_category[100 + $index] = [1000 + $index];
        }

        $chunks = ll_tools_build_launchable_user_study_selection_chunks(
            $matched_by_category,
            15,
            5,
            8,
            5
        );

        $this->assertIsArray($chunks);
        $this->assertCount(1, $chunks);
        $this->assertSame(range(101, 106), array_values(array_map('intval', (array) ($chunks[0]['category_ids'] ?? []))));
        $this->assertSame(range(1001, 1006), array_values(array_map('intval', (array) ($chunks[0]['word_ids'] ?? []))));
        $this->assertSame(8, (int) (ll_tools_user_study_selection_launch_limits()['hard_max_categories'] ?? 0));
    }

    public function test_selection_launch_impossible_sparse_singletons_return_typed_error(): void
    {
        $matched_by_category = [];
        for ($index = 1; $index <= 9; $index++) {
            $matched_by_category[200 + $index] = [2000 + $index];
        }

        $plan = ll_tools_build_launchable_user_study_selection_chunks(
            $matched_by_category,
            15,
            5,
            8,
            5
        );

        $this->assertWPError($plan);
        $this->assertSame('selection_plan_unlaunchable', $plan->get_error_code());
    }

    public function test_analytics_ajax_can_return_summary_without_word_rows(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $nonce = wp_create_nonce('ll_user_study');

        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'days' => 14,
            'summary_only' => '1',
            'include_words' => '0',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $analytics = (array) ($response['data']['analytics'] ?? []);
        $this->assertSame(10, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertCount(2, (array) ($analytics['categories'] ?? []));
        $this->assertSame([], (array) ($analytics['words'] ?? []));
        $this->assertTrue((bool) ($analytics['words_omitted'] ?? false));
    }

    public function test_build_analytics_payload_includes_part_of_speech_details_when_available(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a, $word_b, $word_c] = $fixture['word_ids'];
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');

        wp_set_post_terms($word_a, [$noun_term_id], 'part_of_speech', false);
        wp_set_post_terms($word_b, [$adjective_term_id], 'part_of_speech', false);

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            14
        );

        $words_by_id = [];
        foreach ((array) ($analytics['words'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wid = isset($row['id']) ? (int) $row['id'] : 0;
            if ($wid > 0) {
                $words_by_id[$wid] = $row;
            }
        }

        $this->assertSame('noun', (string) ($words_by_id[$word_a]['part_of_speech_slug'] ?? ''));
        $this->assertSame('Noun', (string) ($words_by_id[$word_a]['part_of_speech_label'] ?? ''));
        $this->assertSame('n', (string) ($words_by_id[$word_a]['part_of_speech_abbreviation'] ?? ''));

        $this->assertSame('adjective', (string) ($words_by_id[$word_b]['part_of_speech_slug'] ?? ''));
        $this->assertSame('Adjective', (string) ($words_by_id[$word_b]['part_of_speech_label'] ?? ''));
        $this->assertSame('adj', (string) ($words_by_id[$word_b]['part_of_speech_abbreviation'] ?? ''));

        $this->assertSame('', (string) ($words_by_id[$word_c]['part_of_speech_slug'] ?? ''));
        $this->assertSame('', (string) ($words_by_id[$word_c]['part_of_speech_label'] ?? ''));
        $this->assertSame('', (string) ($words_by_id[$word_c]['part_of_speech_abbreviation'] ?? ''));
    }

    public function test_summary_only_analytics_uses_current_taxonomy_membership_for_category_counts(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a] = $fixture['word_ids'];
        [$cat_a, $cat_b] = $fixture['category_ids'];
        $wordset_id = (int) $fixture['wordset_id'];

        wp_set_post_terms($word_a, [$cat_a, $cat_b], 'word-category', false);
        $this->seedWordProgressRow($user_id, $word_a, $cat_b, 0, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'last_mode' => 'practice',
            'stage' => 1,
        ]);

        $full = ll_tools_build_user_study_analytics_payload($user_id, $wordset_id, [$cat_a, $cat_b], 14);
        $summary_only = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$cat_a, $cat_b],
            14,
            false,
            ['summary_only' => true]
        );

        $this->assertSame($full['summary'], $summary_only['summary']);
        $this->assertSame(10, (int) ($summary_only['summary']['total_words'] ?? 0));
        $this->assertSame(1, (int) ($summary_only['summary']['studied_words'] ?? 0));
        $this->assertSame($full['categories'], $summary_only['categories']);

        $categories_by_id = [];
        foreach ((array) ($summary_only['categories'] ?? []) as $row) {
            if (is_array($row)) {
                $categories_by_id[(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $this->assertSame(1, (int) ($categories_by_id[$cat_a]['studied_words'] ?? 0));
        $this->assertSame(1, (int) ($categories_by_id[$cat_b]['studied_words'] ?? 0));
        $this->assertSame([], (array) ($summary_only['words'] ?? []));
        $this->assertTrue((bool) ($summary_only['words_omitted'] ?? false));
    }

    public function test_reset_user_progress_clears_scope_when_stored_row_scope_is_stale(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Reset Scope Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $target_category_term = wp_insert_term('Reset Scope Category ' . wp_generate_password(6, false), 'word-category');
        $other_category_term = wp_insert_term('Reset Scope Other Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($target_category_term));
        $this->assertFalse(is_wp_error($other_category_term));
        $this->assertIsArray($target_category_term);
        $this->assertIsArray($other_category_term);
        $target_category_id = (int) $target_category_term['term_id'];
        $other_category_id = (int) $other_category_term['term_id'];

        update_term_meta($target_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($target_category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($other_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($other_category_id, 'll_quiz_option_type', 'text_title');

        $word_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $word_ids[] = $this->createWordWithAudio(
                'Reset Scope Word ' . $index,
                'Reset Scope Translation ' . $index,
                $target_category_id,
                $wordset_id,
                'reset-scope-' . $index . '.mp3'
            );
        }
        $target_category_id = $this->resolveEffectiveCategoryId($target_category_id, $wordset_id);
        $other_category_id = $this->resolveEffectiveCategoryId($other_category_id, $wordset_id);
        $studied_word_id = (int) $word_ids[0];

        // Give the studied word multiple categories so analytics category membership
        // can diverge from the row's single stored category_id.
        wp_set_post_terms($studied_word_id, [$target_category_id, $other_category_id], 'word-category', false);

        // Persist a stale row that does not match the current reset scope metadata.
        $this->seedWordProgressRow($user_id, $studied_word_id, $other_category_id, 0, [
            'total_coverage' => 3,
            'coverage_practice' => 3,
            'correct_clean' => 1,
            'incorrect' => 1,
            'lapse_count' => 1,
            'stage' => 1,
        ]);

        $before = ll_tools_build_user_study_analytics_payload($user_id, $wordset_id, [$target_category_id], 14, true);
        $this->assertSame(1, (int) ($before['summary']['studied_words'] ?? 0));

        $observed_scope_query_limits = [];
        $scope_query_guard = static function (WP_Query $query) use (&$observed_scope_query_limits): void {
            $post_types = array_map('strval', (array) $query->get('post_type'));
            if (
                in_array('words', $post_types, true)
                && $query->get('fields') === 'ids'
                && $query->get('orderby') === 'ID'
            ) {
                $observed_scope_query_limits[] = (int) $query->get('posts_per_page');
            }
        };
        $scope_batch_filter = static function (): int {
            return 2;
        };
        add_action('pre_get_posts', $scope_query_guard);
        add_filter('ll_tools_user_progress_reset_scope_batch_size', $scope_batch_filter);
        add_filter('ll_tools_user_progress_reset_scope_batch_max', $scope_batch_filter);
        try {
            $result = ll_tools_reset_user_progress($user_id, [
                'wordset_id' => $wordset_id,
                'category_ids' => [$target_category_id],
            ]);
        } finally {
            remove_action('pre_get_posts', $scope_query_guard);
            remove_filter('ll_tools_user_progress_reset_scope_batch_size', $scope_batch_filter);
            remove_filter('ll_tools_user_progress_reset_scope_batch_max', $scope_batch_filter);
        }

        $this->assertGreaterThanOrEqual(1, (int) ($result['deleted_word_rows'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($result['scope_word_batches'] ?? 0));
        $this->assertNotEmpty($observed_scope_query_limits);
        $this->assertNotContains(-1, $observed_scope_query_limits);
        $this->assertSame([2], array_values(array_unique($observed_scope_query_limits)));
        $remaining_rows = ll_tools_get_user_word_progress_rows($user_id, [$studied_word_id]);
        $this->assertArrayNotHasKey($studied_word_id, $remaining_rows);

        $after = ll_tools_build_user_study_analytics_payload($user_id, $wordset_id, [$target_category_id], 14, true);
        $this->assertSame(0, (int) ($after['summary']['studied_words'] ?? 0));
        $this->assertSame(5, (int) ($after['summary']['new_words'] ?? 0));
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   category_ids:int[],
     *   word_ids:int[]
     * }
     */
    private function createAnalyticsFixture(): array
    {
        $wordset = wp_insert_term('Analytics Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $cat_a_term = wp_insert_term('Analytics Category A ' . wp_generate_password(6, false), 'word-category');
        $cat_b_term = wp_insert_term('Analytics Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($cat_a_term));
        $this->assertFalse(is_wp_error($cat_b_term));
        $this->assertIsArray($cat_a_term);
        $this->assertIsArray($cat_b_term);
        $cat_a = (int) $cat_a_term['term_id'];
        $cat_b = (int) $cat_b_term['term_id'];

        // Use text options so image requirements do not block test words.
        update_term_meta($cat_a, 'll_quiz_prompt_type', 'audio');
        update_term_meta($cat_a, 'll_quiz_option_type', 'text_title');
        update_term_meta($cat_b, 'll_quiz_prompt_type', 'audio');
        update_term_meta($cat_b, 'll_quiz_option_type', 'text_title');

        $word_a = $this->createWordWithAudio('Analytics Word A', 'Analytics Translation A', $cat_a, $wordset_id, 'analytics-a.mp3');
        $word_b = $this->createWordWithAudio('Analytics Word B', 'Analytics Translation B', $cat_a, $wordset_id, 'analytics-b.mp3');
        $word_c = $this->createWordWithAudio('Analytics Word C', 'Analytics Translation C', $cat_b, $wordset_id, 'analytics-c.mp3');
        $word_d = $this->createWordWithAudio('Analytics Word D', 'Analytics Translation D', $cat_a, $wordset_id, 'analytics-d.mp3');
        $word_e = $this->createWordWithAudio('Analytics Word E', 'Analytics Translation E', $cat_a, $wordset_id, 'analytics-e.mp3');
        $word_f = $this->createWordWithAudio('Analytics Word F', 'Analytics Translation F', $cat_a, $wordset_id, 'analytics-f.mp3');
        $word_g = $this->createWordWithAudio('Analytics Word G', 'Analytics Translation G', $cat_b, $wordset_id, 'analytics-g.mp3');
        $word_h = $this->createWordWithAudio('Analytics Word H', 'Analytics Translation H', $cat_b, $wordset_id, 'analytics-h.mp3');
        $word_i = $this->createWordWithAudio('Analytics Word I', 'Analytics Translation I', $cat_b, $wordset_id, 'analytics-i.mp3');
        $word_j = $this->createWordWithAudio('Analytics Word J', 'Analytics Translation J', $cat_b, $wordset_id, 'analytics-j.mp3');

        $cat_a = $this->resolveEffectiveCategoryId($cat_a, $wordset_id);
        $cat_b = $this->resolveEffectiveCategoryId($cat_b, $wordset_id);

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => [$cat_a, $cat_b],
            'word_ids' => [$word_a, $word_b, $word_c, $word_d, $word_e, $word_f, $word_g, $word_h, $word_i, $word_j],
        ];
    }

    /**
     * @return array{category_id:int,word_ids:int[]}
     */
    private function createLearningSelectionCategory(
        int $wordset_id,
        string $label,
        string $prompt_type,
        string $option_type,
        int $word_count
    ): array {
        $category = wp_insert_term(
            'Analytics ' . $label . ' Category ' . wp_generate_password(6, false),
            'word-category'
        );
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $source_category_id = (int) $category['term_id'];
        update_term_meta($source_category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($source_category_id, 'll_quiz_option_type', $option_type);

        $word_ids = [];
        for ($index = 1; $index <= $word_count; $index++) {
            $word_ids[] = $this->createWordWithAudio(
                'Analytics ' . $label . ' Word ' . $index,
                'Analytics ' . $label . ' Translation ' . $index,
                $source_category_id,
                $wordset_id,
                sanitize_title($label) . '-' . $index . '.mp3'
            );
        }

        return [
            'category_id' => $this->resolveEffectiveCategoryId($source_category_id, $wordset_id),
            'word_ids' => $word_ids,
        ];
    }

    /**
     * @return array{wordset_id:int,quizzable_category_id:int,non_quizzable_category_id:int}
     */
    private function createMixedQuizzableFixture(): array
    {
        $wordset = wp_insert_term('Analytics Scope Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $quizzable_term = wp_insert_term('Analytics Quizzable Category ' . wp_generate_password(6, false), 'word-category');
        $non_quizzable_term = wp_insert_term('Analytics Nonquizzable Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($quizzable_term));
        $this->assertFalse(is_wp_error($non_quizzable_term));
        $this->assertIsArray($quizzable_term);
        $this->assertIsArray($non_quizzable_term);
        $quizzable_category_id = (int) $quizzable_term['term_id'];
        $non_quizzable_category_id = (int) $non_quizzable_term['term_id'];

        update_term_meta($quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($quizzable_category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($non_quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($non_quizzable_category_id, 'll_quiz_option_type', 'text_title');

        $quizzable_word_ids = [];
        $non_quizzable_word_ids = [];

        // Quizzable category: meets default minimum of 5 words.
        for ($index = 1; $index <= 5; $index++) {
            $quizzable_word_ids[] = $this->createWordWithAudio(
                'Analytics Quizzable Word ' . $index,
                'Analytics Quizzable Translation ' . $index,
                $quizzable_category_id,
                $wordset_id,
                'analytics-quizzable-' . $index . '.mp3'
            );
        }

        // Non-quizzable category: intentionally below minimum threshold.
        for ($index = 1; $index <= 2; $index++) {
            $non_quizzable_word_ids[] = $this->createWordWithAudio(
                'Analytics Nonquizzable Word ' . $index,
                'Analytics Nonquizzable Translation ' . $index,
                $non_quizzable_category_id,
                $wordset_id,
                'analytics-nonquizzable-' . $index . '.mp3'
            );
        }

        $quizzable_category_id = $this->resolveEffectiveCategoryId($quizzable_category_id, $wordset_id);
        $non_quizzable_category_id = $this->resolveEffectiveCategoryId($non_quizzable_category_id, $wordset_id);

        return [
            'wordset_id' => $wordset_id,
            'quizzable_category_id' => $quizzable_category_id,
            'non_quizzable_category_id' => $non_quizzable_category_id,
            'quizzable_word_ids' => $quizzable_word_ids,
            'non_quizzable_word_ids' => $non_quizzable_word_ids,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    private function createWordWithoutAudio(string $title, int $category_id, int $wordset_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return (int) $word_id;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function createPromptCardForAnalytics(int $category_id, int $wordset_id, array $args): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => (string) ($args['title'] ?? 'Analytics Prompt Card'),
        ]);

        wp_set_post_terms($post_id, [$category_id], 'word-category', false);
        wp_set_post_terms($post_id, [$wordset_id], 'wordset', false);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, (string) ($args['prompt_text'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, (string) ($args['prompt_audio_url'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, !empty($args['track_answer_word_progress']) ? 1 : 0);

        return (int) $post_id;
    }

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : 0;

        return ($effective_category_id > 0) ? $effective_category_id : $category_id;
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): int
    {
        $existing = term_exists($slug, 'part_of_speech');
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $created = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        if (is_wp_error($created)) {
            $term = get_term_by('slug', $slug, 'part_of_speech');
            $this->assertInstanceOf(WP_Term::class, $term);
            return (int) $term->term_id;
        }

        $this->assertIsArray($created);
        return (int) $created['term_id'];
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

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
