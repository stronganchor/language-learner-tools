<?php
declare(strict_types=1);

final class FlashcardShellRendererTest extends LL_Tools_TestCase
{
    public function test_shared_renderer_outputs_widget_shell_contract(): void
    {
        ob_start();
        ll_tools_render_flashcard_overlay_shell([
            'include_category_selection' => true,
            'include_loading_status' => true,
            'show_category_display' => true,
            'category_label_text' => 'Animals',
            'mode_ui' => [],
            'listening_results_fallback' => __('Replay Listening', 'll-tools-text-domain'),
        ]);
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, 'id="ll-tools-flashcard-popup"'));
        $this->assertSame(1, substr_count($html, 'id="ll-tools-flashcard-quiz-popup"'));
        $this->assertSame(1, substr_count($html, 'id="ll-tools-category-selection-popup"'));
        $this->assertStringContainsString('class="ll-tools-category-selection-title"', $html);
        $this->assertSame(2, substr_count($html, 'role="dialog"'));
        $this->assertSame(2, substr_count($html, 'aria-modal="true"'));
        $this->assertStringContainsString('aria-labelledby="ll-tools-category-selection-title"', $html);
        $this->assertStringContainsString('aria-labelledby="ll-tools-flashcard-dialog-title"', $html);
        $this->assertStringContainsString('id="ll-tools-flashcard-dialog-title"', $html);
        $this->assertStringContainsString('id="ll-tools-category-display"', $html);
        $this->assertStringContainsString('Animals', $html);
        $this->assertStringContainsString('id="ll-tools-loading-status"', $html);
        $this->assertStringContainsString('id="ll-tools-repeat-flashcard"', $html);
        $this->assertStringContainsString('type="button"', $html);
        foreach (['practice', 'learning', 'self-check', 'gender', 'listening'] as $mode) {
            $this->assertStringContainsString('id="restart-' . $mode . '-mode"', $html);
        }
        $this->assertStringNotContainsString('ll-tools-mode-switcher', $html);
        $this->assertStringNotContainsString('ll-tools-mode-menu', $html);
        $this->assertStringNotContainsString('ll-tools-mode-option', $html);
        $this->assertStringContainsString('id="ll-gender-results-progress"', $html);
        $this->assertStringContainsString('Replay Listening', $html);
    }

    public function test_shared_renderer_can_match_quiz_page_shell_variant(): void
    {
        ob_start();
        ll_tools_render_flashcard_overlay_shell([
            'include_category_selection' => false,
            'include_loading_status' => false,
            'show_category_display' => true,
            'category_label_text' => '',
            'mode_ui' => [],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('id="ll-tools-category-selection-popup"', $html);
        $this->assertStringNotContainsString('id="ll-tools-loading-status"', $html);
        $this->assertStringContainsString('id="restart-self-check-mode"', $html);
        $this->assertStringContainsString('id="restart-listening-mode"', $html);
        $this->assertStringNotContainsString('ll-tools-mode-switcher', $html);
        $this->assertStringNotContainsString('ll-tools-mode-menu', $html);
    }

    public function test_repeat_button_initializer_is_globally_guarded(): void
    {
        ob_start();
        ll_tools_render_flashcard_repeat_button_init_script();
        $script = (string) ob_get_clean();

        $this->assertStringContainsString('__LL_FLASHCARD_REPEAT_ICON_INIT_BOUND', $script);
        $this->assertStringContainsString('LLFlashcards.Dom.setRepeatButton', $script);
    }
}
