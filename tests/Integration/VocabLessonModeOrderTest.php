<?php
declare(strict_types=1);

final class VocabLessonModeOrderTest extends LL_Tools_TestCase
{
    public function test_launch_mode_order_without_gender_matches_wordset_cards(): void
    {
        $this->assertSame(
            ['learning', 'practice', 'listening', 'self-check'],
            ll_tools_get_study_launch_mode_order(false)
        );
    }

    public function test_launch_mode_order_with_gender_keeps_gender_before_self_check(): void
    {
        $this->assertSame(
            ['learning', 'practice', 'listening', 'gender', 'self-check'],
            ll_tools_get_study_launch_mode_order(true)
        );
    }
}
