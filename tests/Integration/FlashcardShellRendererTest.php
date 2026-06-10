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
            'gender_mode_visible' => false,
            'listening_results_fallback' => __('Replay Listening', 'll-tools-text-domain'),
        ]);
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, 'id="ll-tools-flashcard-popup"'));
        $this->assertSame(1, substr_count($html, 'id="ll-tools-flashcard-quiz-popup"'));
        $this->assertSame(1, substr_count($html, 'id="ll-tools-category-selection-popup"'));
        $this->assertStringContainsString('class="ll-tools-category-selection-title"', $html);
        $this->assertStringContainsString('id="ll-tools-category-display"', $html);
        $this->assertStringContainsString('Animals', $html);
        $this->assertStringContainsString('id="ll-tools-loading-status"', $html);
        $this->assertStringContainsString('id="ll-tools-repeat-flashcard"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-mode="self-check"', $html);
        $this->assertStringContainsString('ll-tools-mode-option gender hidden', $html);
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
            'gender_mode_visible' => false,
            'mode_order' => ['learning', 'practice', 'listening', 'gender', 'self-check'],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('id="ll-tools-category-selection-popup"', $html);
        $this->assertStringNotContainsString('id="ll-tools-loading-status"', $html);
        $this->assertLessThan(
            strpos($html, 'data-mode="self-check"'),
            strpos($html, 'data-mode="listening"')
        );
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
