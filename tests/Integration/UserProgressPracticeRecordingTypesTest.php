<?php
declare(strict_types=1);

final class UserProgressPracticeRecordingTypesTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_practice_mastery_requires_all_recording_types_once_tracking_is_present(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
            'isolation' => 'Isolation text',
        ]);

        $stage_filter = static function (): int {
            return 1;
        };
        $clean_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
        add_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);

        try {
            $first_event = [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $word_id,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'recording_type' => 'question',
                    'available_recording_types' => ['question', 'isolation'],
                ],
            ];

            $stats = ll_tools_process_progress_events_batch($user_id, [$first_event]);
            $this->assertSame(1, (int) ($stats['processed'] ?? 0));

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $after_first = $rows[$word_id];

            $this->assertSame(['question'], $after_first['practice_correct_recording_types'] ?? []);
            $this->assertSame(['question', 'isolation'], $after_first['practice_required_recording_types_resolved'] ?? []);
            $this->assertFalse(ll_tools_user_progress_word_is_mastered($after_first));

            $second_event = [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $word_id,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'recording_type' => 'isolation',
                    'available_recording_types' => ['question', 'isolation'],
                ],
            ];

            $stats = ll_tools_process_progress_events_batch($user_id, [$second_event]);
            $this->assertSame(1, (int) ($stats['processed'] ?? 0));

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $after_second = $rows[$word_id];

            $this->assertSame(['question', 'isolation'], $after_second['practice_correct_recording_types'] ?? []);
            $this->assertTrue(ll_tools_user_progress_word_is_mastered($after_second));
            $this->assertSame('mastered', ll_tools_user_progress_word_status($after_second));
        } finally {
            remove_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
            remove_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);
        }
    }

    public function test_legacy_mastered_rows_without_practice_tracking_remain_mastered(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
            'isolation' => 'Isolation text',
        ]);

        $stage_filter = static function (): int {
            return 1;
        };
        $clean_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
        add_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);

        try {
            $tables = ll_tools_user_progress_table_names();
            $table = $tables['words'];
            $now = gmdate('Y-m-d H:i:s');

            $inserted = $wpdb->replace($table, [
                'user_id' => $user_id,
                'word_id' => $word_id,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_mode' => 'practice',
                'total_coverage' => 2,
                'coverage_learning' => 0,
                'coverage_practice' => 2,
                'coverage_listening' => 0,
                'coverage_gender' => 0,
                'coverage_self_check' => 0,
                'correct_clean' => 2,
                'correct_after_retry' => 0,
                'incorrect' => 0,
                'lapse_count' => 0,
                'stage' => 2,
                'due_at' => $now,
                'updated_at' => $now,
            ], [
                '%d', '%d', '%d', '%d', '%s', '%s', '%s',
                '%d', '%d', '%d', '%d', '%d', '%d',
                '%d', '%d', '%d', '%d', '%d', '%s', '%s',
            ]);

            $this->assertNotFalse($inserted);

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $row = $rows[$word_id];

            $this->assertFalse(ll_tools_progress_row_has_practice_recording_tracking($row));
            $this->assertTrue(ll_tools_user_progress_word_is_mastered($row));
        } finally {
            remove_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
            remove_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);
        }
    }

    public function test_space_shooter_incorrect_outcomes_track_incorrect_without_stage_drop(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
            'isolation' => 'Isolation text',
            'introduction' => 'Introduction text',
        ]);

        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->replace($table, [
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 4,
            'coverage_learning' => 0,
            'coverage_practice' => 4,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 2,
            'correct_after_retry' => 0,
            'incorrect' => 1,
            'lapse_count' => 1,
            'stage' => 3,
            'due_at' => $now,
            'practice_required_recording_types' => ll_tools_encode_practice_recording_types(['question', 'isolation', 'introduction']),
            'practice_correct_recording_types' => ll_tools_encode_practice_recording_types(['question']),
            'updated_at' => $now,
        ]);
        $this->assertNotFalse($inserted);

        $event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'practice',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'is_correct' => false,
            'had_wrong_before' => false,
            'payload' => [
                'recording_type' => 'question',
                'available_recording_types' => ['question', 'isolation', 'introduction'],
                'event_source' => 'space_shooter',
                'wrong_hit' => true,
            ],
        ];

        $stats = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
        $this->assertArrayHasKey($word_id, $rows);
        $row = $rows[$word_id];

        $this->assertSame(2, (int) ($row['incorrect'] ?? 0));
        $this->assertSame(1, (int) ($row['lapse_count'] ?? 0));
        $this->assertSame(3, (int) ($row['stage'] ?? 0));
        $this->assertSame(['question', 'isolation', 'introduction'], $row['practice_required_recording_types_resolved'] ?? []);
        $this->assertSame(['question'], $row['practice_correct_recording_types'] ?? []);
    }

    public function test_mastery_unlock_returns_after_hard_state_clears_without_remeeting_threshold(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
        ]);

        $stage_filter = static function (): int {
            return 3;
        };
        $clean_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
        add_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);

        try {
            $base_payload = [
                'recording_type' => 'question',
                'available_recording_types' => ['question'],
            ];

            $mastery_events = [];
            for ($i = 0; $i < 3; $i++) {
                $mastery_events[] = $this->buildPracticeOutcomeEvent(
                    $word_id,
                    $category_id,
                    $wordset_id,
                    true,
                    false,
                    $base_payload
                );
            }
            $stats = ll_tools_process_progress_events_batch($user_id, $mastery_events);
            $this->assertSame(3, (int) ($stats['processed'] ?? 0));

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $mastered = $rows[$word_id];
            $this->assertSame('mastered', ll_tools_user_progress_word_status($mastered));
            $this->assertSame(1, (int) ($mastered['mastery_unlocked'] ?? 0));

            $hardening_events = [
                $this->buildPracticeOutcomeEvent($word_id, $category_id, $wordset_id, false, false, $base_payload),
                $this->buildPracticeOutcomeEvent($word_id, $category_id, $wordset_id, false, false, $base_payload),
            ];
            $stats = ll_tools_process_progress_events_batch($user_id, $hardening_events);
            $this->assertSame(2, (int) ($stats['processed'] ?? 0));

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $hard = $rows[$word_id];
            $this->assertTrue(ll_tools_user_progress_word_is_hard($hard));
            $this->assertSame('studied', ll_tools_user_progress_word_status($hard));
            $this->assertSame(1, (int) ($hard['mastery_unlocked'] ?? 0));

            $recovery_events = [
                $this->buildPracticeOutcomeEvent($word_id, $category_id, $wordset_id, true, true, $base_payload),
                $this->buildPracticeOutcomeEvent($word_id, $category_id, $wordset_id, true, true, $base_payload),
            ];
            $stats = ll_tools_process_progress_events_batch($user_id, $recovery_events);
            $this->assertSame(2, (int) ($stats['processed'] ?? 0));

            $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
            $this->assertArrayHasKey($word_id, $rows);
            $recovered = $rows[$word_id];
            $this->assertSame(1, (int) ($recovered['stage'] ?? 0));
            $this->assertFalse(ll_tools_user_progress_row_meets_mastery_requirements($recovered));
            $this->assertFalse(ll_tools_user_progress_word_is_hard($recovered));
            $this->assertSame('mastered', ll_tools_user_progress_word_status($recovered));
        } finally {
            remove_filter('ll_tools_user_progress_mastered_stage_threshold', $stage_filter);
            remove_filter('ll_tools_user_progress_mastered_clean_threshold', $clean_filter);
        }
    }

    public function test_long_correct_streak_can_offset_deep_error_history_in_difficulty_score(): void
    {
        $base_row = [
            'total_coverage' => 1,
            'incorrect' => 8,
            'lapse_count' => 8,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'stage' => 0,
            'current_correct_streak' => 0,
        ];
        $recovered_row = $base_row;
        $recovered_row['current_correct_streak'] = 9;

        $hard_threshold = ll_tools_user_progress_hard_difficulty_threshold();

        $this->assertGreaterThanOrEqual($hard_threshold, ll_tools_user_progress_word_difficulty_score($base_row));
        $this->assertLessThan($hard_threshold, ll_tools_user_progress_word_difficulty_score($recovered_row));
    }

    public function test_practice_audio_timing_rewards_early_corrects_and_softens_early_wrongs(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$fast_correct_word, $fast_correct_category, $fast_correct_wordset] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
        ]);
        [$normal_correct_word, $normal_correct_category, $normal_correct_wordset] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
        ]);
        [$fast_wrong_word, $fast_wrong_category, $fast_wrong_wordset] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
        ]);
        [$normal_wrong_word, $normal_wrong_category, $normal_wrong_wordset] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
        ]);

        $base_payload = [
            'recording_type' => 'question',
            'available_recording_types' => ['question'],
        ];

        $stats = ll_tools_process_progress_events_batch($user_id, [
            $this->buildPracticeOutcomeEvent(
                $fast_correct_word,
                $fast_correct_category,
                $fast_correct_wordset,
                true,
                false,
                array_merge($base_payload, [
                    'audio_progress_ratio' => 0.25,
                    'answered_before_audio_end' => true,
                ])
            ),
            $this->buildPracticeOutcomeEvent(
                $normal_correct_word,
                $normal_correct_category,
                $normal_correct_wordset,
                true,
                false,
                array_merge($base_payload, [
                    'audio_progress_ratio' => 1.0,
                    'answered_before_audio_end' => false,
                ])
            ),
        ]);
        $this->assertSame(2, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$fast_correct_word, $normal_correct_word]);
        $fast_correct = $rows[$fast_correct_word] ?? [];
        $normal_correct = $rows[$normal_correct_word] ?? [];

        $this->assertSame(2, (int) ($fast_correct['current_correct_streak'] ?? 0));
        $this->assertSame(1, (int) ($normal_correct['current_correct_streak'] ?? 0));
        $this->assertSame(2, (int) ($fast_correct['stage'] ?? 0));
        $this->assertSame(1, (int) ($normal_correct['stage'] ?? 0));

        $seed_stats = ll_tools_process_progress_events_batch($user_id, [
            $this->buildPracticeOutcomeEvent($fast_wrong_word, $fast_wrong_category, $fast_wrong_wordset, true, false, $base_payload),
            $this->buildPracticeOutcomeEvent($fast_wrong_word, $fast_wrong_category, $fast_wrong_wordset, true, false, $base_payload),
            $this->buildPracticeOutcomeEvent($normal_wrong_word, $normal_wrong_category, $normal_wrong_wordset, true, false, $base_payload),
            $this->buildPracticeOutcomeEvent($normal_wrong_word, $normal_wrong_category, $normal_wrong_wordset, true, false, $base_payload),
        ]);
        $this->assertSame(4, (int) ($seed_stats['processed'] ?? 0));

        $wrong_stats = ll_tools_process_progress_events_batch($user_id, [
            $this->buildPracticeOutcomeEvent(
                $fast_wrong_word,
                $fast_wrong_category,
                $fast_wrong_wordset,
                false,
                false,
                array_merge($base_payload, [
                    'audio_progress_ratio' => 0.20,
                    'answered_before_audio_end' => true,
                ])
            ),
            $this->buildPracticeOutcomeEvent(
                $normal_wrong_word,
                $normal_wrong_category,
                $normal_wrong_wordset,
                false,
                false,
                array_merge($base_payload, [
                    'audio_progress_ratio' => 1.0,
                    'answered_before_audio_end' => false,
                ])
            ),
        ]);
        $this->assertSame(2, (int) ($wrong_stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$fast_wrong_word, $normal_wrong_word]);
        $fast_wrong = $rows[$fast_wrong_word] ?? [];
        $normal_wrong = $rows[$normal_wrong_word] ?? [];

        $this->assertSame(1, (int) ($fast_wrong['incorrect'] ?? 0));
        $this->assertSame(1, (int) ($normal_wrong['incorrect'] ?? 0));
        $this->assertSame(0, (int) ($fast_wrong['lapse_count'] ?? 0));
        $this->assertSame(1, (int) ($normal_wrong['lapse_count'] ?? 0));
        $this->assertSame(2, (int) ($fast_wrong['stage'] ?? 0));
        $this->assertSame(1, (int) ($normal_wrong['stage'] ?? 0));
        $this->assertSame(0, (int) ($fast_wrong['current_correct_streak'] ?? 0));
        $this->assertSame(0, (int) ($normal_wrong['current_correct_streak'] ?? 0));
    }

    public function test_attach_user_practice_progress_to_words_includes_progress_snapshot(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWordWithRecordingTypes([
            'question' => 'Question text',
            'isolation' => 'Isolation text',
        ]);

        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->replace($table, [
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 3,
            'coverage_learning' => 0,
            'coverage_practice' => 3,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'practice_required_recording_types' => wp_json_encode(['question', 'isolation']),
            'practice_correct_recording_types' => wp_json_encode(['question']),
            'correct_clean' => 1,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 1,
            'due_at' => $now,
            'updated_at' => $now,
        ], [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ]);

        $this->assertNotFalse($inserted);

        $words = ll_tools_attach_user_practice_progress_to_words([
            ['id' => $word_id],
        ], $user_id);

        $this->assertCount(1, $words);
        $this->assertSame(['question'], $words[0]['practice_correct_recording_types'] ?? []);
        $this->assertSame(3, (int) ($words[0]['practice_exposure_count'] ?? 0));
        $this->assertSame('studied', (string) ($words[0]['progress_status'] ?? ''));
        $this->assertSame(3, (int) ($words[0]['progress_total_coverage'] ?? 0));
    }

    /**
     * @param array<string,string> $recording_types
     * @return array{0:int,1:int,2:int}
     */
    private function createScopedWordWithRecordingTypes(array $recording_types): array
    {
        $wordset = wp_insert_term('Practice Types Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Practice Types Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Practice Types Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Practice Types Translation');

        foreach ($recording_types as $slug => $recording_text) {
            $term = term_exists($slug, 'recording_type');
            if (!$term) {
                $term = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'recording_type', ['slug' => $slug]);
            }

            $this->assertFalse(is_wp_error($term));
            $this->assertIsArray($term);
            $term_id = (int) $term['term_id'];

            $audio_post_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title' => 'Audio ' . $slug,
            ]);

            update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $slug . '-' . wp_generate_password(6, false) . '.mp3');
            update_post_meta($audio_post_id, 'recording_text', $recording_text);
            wp_set_post_terms($audio_post_id, [$term_id], 'recording_type', false);
        }

        return [$word_id, $category_id, $wordset_id];
    }

    private function buildPracticeOutcomeEvent(
        int $word_id,
        int $category_id,
        int $wordset_id,
        bool $is_correct,
        bool $had_wrong_before,
        array $payload
    ): array {
        return [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'practice',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'is_correct' => $is_correct,
            'had_wrong_before' => $had_wrong_before,
            'payload' => $payload,
        ];
    }
}
