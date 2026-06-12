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

    public function test_user_study_bootstrap_payload_can_defer_words_with_metadata(): void
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
        $this->assertSame([], (array) ($payload['words_by_category'] ?? []));

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

    public function test_user_study_fetch_words_ajax_remains_complete_after_deferred_bootstrap(): void
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

        $this->assertTrue((bool) ($response['success'] ?? false));
        $words_by_category = (array) ($response['data']['words_by_category'] ?? []);
        $this->assertArrayHasKey((string) $quizzable_category_id, $words_by_category);
        $this->assertCount(5, (array) $words_by_category[$quizzable_category_id]);
    }

    public function test_user_study_bootstrap_ajax_returns_deferred_metadata_when_requested(): void
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
            'defer_words' => '1',
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

        $result = ll_tools_reset_user_progress($user_id, [
            'wordset_id' => $wordset_id,
            'category_ids' => [$target_category_id],
        ]);

        $this->assertGreaterThanOrEqual(1, (int) ($result['deleted_word_rows'] ?? 0));
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
        $this->createWordWithAudio('Analytics Word D', 'Analytics Translation D', $cat_a, $wordset_id, 'analytics-d.mp3');
        $this->createWordWithAudio('Analytics Word E', 'Analytics Translation E', $cat_a, $wordset_id, 'analytics-e.mp3');
        $this->createWordWithAudio('Analytics Word F', 'Analytics Translation F', $cat_a, $wordset_id, 'analytics-f.mp3');
        $this->createWordWithAudio('Analytics Word G', 'Analytics Translation G', $cat_b, $wordset_id, 'analytics-g.mp3');
        $this->createWordWithAudio('Analytics Word H', 'Analytics Translation H', $cat_b, $wordset_id, 'analytics-h.mp3');
        $this->createWordWithAudio('Analytics Word I', 'Analytics Translation I', $cat_b, $wordset_id, 'analytics-i.mp3');
        $this->createWordWithAudio('Analytics Word J', 'Analytics Translation J', $cat_b, $wordset_id, 'analytics-j.mp3');

        $cat_a = $this->resolveEffectiveCategoryId($cat_a, $wordset_id);
        $cat_b = $this->resolveEffectiveCategoryId($cat_b, $wordset_id);

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => [$cat_a, $cat_b],
            'word_ids' => [$word_a, $word_b, $word_c],
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
