<?php
declare(strict_types=1);

final class FlashcardWidgetSingleRenderTest extends LL_Tools_TestCase
{
    public function test_flashcard_widget_renders_container_only_once_per_request(): void
    {
        $first = do_shortcode('[flashcard_widget embed="false" wordset="" wordset_fallback="true" quiz_mode="practice"]');
        $second = do_shortcode('[flashcard_widget embed="false" wordset="" wordset_fallback="true" quiz_mode="practice"]');

        $combined = (string) $first . (string) $second;

        $this->assertSame(1, substr_count($combined, 'id="ll-tools-flashcard-container"'));
        $this->assertSame('', trim((string) $second));
    }
}

