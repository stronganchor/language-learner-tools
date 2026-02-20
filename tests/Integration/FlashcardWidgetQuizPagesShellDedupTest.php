<?php
declare(strict_types=1);

final class FlashcardWidgetQuizPagesShellDedupTest extends LL_Tools_TestCase
{
    public function test_quiz_pages_shell_skips_duplicate_markup_when_widget_already_rendered(): void
    {
        $widget = do_shortcode('[flashcard_widget embed="false" wordset="" wordset_fallback="true" quiz_mode="practice"]');

        ob_start();
        ll_qpg_print_flashcard_shell_once();
        $shell = (string) ob_get_clean();

        $combined = (string) $widget . $shell;

        $this->assertSame(1, substr_count($combined, 'id="ll-tools-flashcard-container"'));
        $this->assertSame(1, substr_count($combined, 'id="ll-tools-flashcard-popup"'));
        $this->assertStringContainsString('window.llOpenFlashcardForCategory = function', $shell);
    }
}
