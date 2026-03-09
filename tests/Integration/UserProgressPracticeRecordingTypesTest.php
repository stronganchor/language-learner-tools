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
}
