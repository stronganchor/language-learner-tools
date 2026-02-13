<?php
declare(strict_types=1);

final class UserProgressSelfCheckSignalTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_self_check_buckets_apply_stronger_progress_weighting(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createScopedWord();

        $idk_event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'self-check',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'is_correct' => false,
            'had_wrong_before' => true,
            'payload' => [
                'self_check_bucket' => 'idk',
                'self_check_confidence' => 'idk',
                'self_check_result' => 'idk',
            ],
        ];
        $stats = ll_tools_process_progress_events_batch($user_id, [$idk_event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
        $this->assertArrayHasKey($word_id, $rows);
        $after_idk = $rows[$word_id];
        $this->assertSame(2, (int) ($after_idk['incorrect'] ?? 0));
        $this->assertSame(2, (int) ($after_idk['lapse_count'] ?? 0));
        $this->assertSame(0, (int) ($after_idk['stage'] ?? 0));

        $right_event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'self-check',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'is_correct' => true,
            'had_wrong_before' => false,
            'payload' => [
                'self_check_bucket' => 'right',
                'self_check_confidence' => 'know',
                'self_check_result' => 'right',
            ],
        ];
        $stats = ll_tools_process_progress_events_batch($user_id, [$right_event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
        $this->assertArrayHasKey($word_id, $rows);
        $after_right = $rows[$word_id];
        $this->assertSame(1, (int) ($after_right['correct_clean'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($after_right['stage'] ?? 0));
        $this->assertNotEmpty($after_right['due_at']);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function createScopedWord(): array
    {
        $wordset = wp_insert_term('SelfCheck Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('SelfCheck Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Signal Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Signal Translation');

        return [$word_id, $category_id, $wordset_id];
    }
}
