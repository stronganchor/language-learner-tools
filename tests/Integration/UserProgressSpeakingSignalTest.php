<?php
declare(strict_types=1);

final class UserProgressSpeakingSignalTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_speaking_practice_right_bucket_uses_speaking_progress_weighting(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWord();

        $event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'practice',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'is_correct' => true,
            'had_wrong_before' => false,
            'payload' => [
                'game_slug' => 'speaking-practice',
                'speaking_game_bucket' => 'right',
                'speaking_score' => 100,
            ],
        ];

        $stats = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
        $this->assertArrayHasKey($word_id, $rows);

        $row = $rows[$word_id];
        $this->assertSame(1, (int) ($row['correct_clean'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($row['stage'] ?? 0));
        $this->assertNotEmpty($row['due_at'] ?? '');
    }

    public function test_speaking_practice_close_and_wrong_buckets_apply_soft_penalties(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$close_word_id, $close_category_id, $close_wordset_id] = $this->createScopedWord();
        [$wrong_word_id, $wrong_category_id, $wrong_wordset_id] = $this->createScopedWord();

        $close_event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'practice',
            'word_id' => $close_word_id,
            'category_id' => $close_category_id,
            'wordset_id' => $close_wordset_id,
            'is_correct' => true,
            'had_wrong_before' => true,
            'payload' => [
                'game_slug' => 'speaking-practice',
                'speaking_game_bucket' => 'close',
                'speaking_score' => 68,
            ],
        ];
        $stats = ll_tools_process_progress_events_batch($user_id, [$close_event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $close_rows = ll_tools_get_user_word_progress_rows($user_id, [$close_word_id]);
        $this->assertArrayHasKey($close_word_id, $close_rows);

        $close_row = $close_rows[$close_word_id];
        $this->assertSame(1, (int) ($close_row['correct_after_retry'] ?? 0));
        $this->assertSame(1, (int) ($close_row['stage'] ?? 0));
        $this->assertNotEmpty($close_row['due_at'] ?? '');

        $wrong_event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'practice',
            'word_id' => $wrong_word_id,
            'category_id' => $wrong_category_id,
            'wordset_id' => $wrong_wordset_id,
            'is_correct' => false,
            'had_wrong_before' => true,
            'payload' => [
                'game_slug' => 'speaking-practice',
                'speaking_game_bucket' => 'wrong',
                'speaking_score' => 12,
            ],
        ];
        $stats = ll_tools_process_progress_events_batch($user_id, [$wrong_event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $wrong_rows = ll_tools_get_user_word_progress_rows($user_id, [$wrong_word_id]);
        $this->assertArrayHasKey($wrong_word_id, $wrong_rows);

        $wrong_row = $wrong_rows[$wrong_word_id];
        $this->assertSame(1, (int) ($wrong_row['incorrect'] ?? 0));
        $this->assertSame(0, (int) ($wrong_row['stage'] ?? 0));
        $this->assertNotEmpty($wrong_row['due_at'] ?? '');
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function createScopedWord(): array
    {
        $wordset = wp_insert_term('Speaking Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Speaking Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Speaking Signal Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Speaking Translation');

        return [$word_id, $category_id, $wordset_id];
    }
}
