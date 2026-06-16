<?php
declare(strict_types=1);

final class UserProgressSearchTest extends LL_Tools_TestCase
{
    public function test_progress_word_search_matches_turkish_dotted_capital_i(): void
    {
        $matched_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => "\u{0130}nsanlar Progress",
        ]);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Travel Progress',
        ]);

        $matches = ll_tools_user_progress_filter_analytics_word_ids_by_search(
            [$matched_word_id, $other_word_id],
            'insanlar'
        );

        $this->assertSame([(int) $matched_word_id], array_values(array_map('intval', $matches)));
    }
}
