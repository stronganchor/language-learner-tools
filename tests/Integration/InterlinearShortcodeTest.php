<?php
declare(strict_types=1);

final class InterlinearShortcodeTest extends LL_Tools_TestCase
{
    public function test_shortcode_renders_simple_rows_with_shared_interlinear_markup(): void
    {
        $output = do_shortcode(
            "[ll_interlinear text=\"Lac qicik\"]\n"
            . "FORM | lac-&empty; | qicik\n"
            . "GLOSS | son-M.AP.EZ | small\n"
            . '[/ll_interlinear]'
        );

        $this->assertStringContainsString('data-ll-interlinear-shortcode', $output);
        $this->assertStringContainsString('class="ll-interlinear-table"', $output);
        $this->assertStringContainsString('<th scope="row">Sentence</th>', $output);
        $this->assertStringContainsString('<th scope="row">WORD</th>', $output);
        $this->assertStringContainsString('<th scope="row">GLOSS</th>', $output);
        $this->assertStringContainsString('lac-', $output);
        $this->assertStringContainsString('qicik', $output);
        $this->assertStringContainsString('small', $output);
        $this->assertStringContainsString('class="ling-abbr"', $output);
        $this->assertStringNotContainsString('<th scope="row">MORPH</th>', $output);
    }

    public function test_shortcode_renders_sentence_and_free_translation_rows(): void
    {
        add_shortcode('ll_test_audio', static function ($atts = [], $content = ''): string {
            return '<span class="test-audio">' . esc_html((string) $content) . '</span>';
        });

        try {
            $output = do_shortcode(
                "[ll_interlinear]\n"
                . "SENTENCE | [ll_test_audio]Lac qicik | example[/ll_test_audio]\n"
                . "MORPH | lac | ∅ | qicik\n"
                . "GLOSS | son | M.EZ | small\n"
                . "FREE | little boy | literally small son\n"
                . '[/ll_interlinear]'
            );
        } finally {
            remove_shortcode('ll_test_audio');
        }

        $this->assertStringContainsString('<th scope="row">Sentence</th>', $output);
        $this->assertStringContainsString('class="ll-interlinear-shortcode__sentence"', $output);
        $this->assertStringContainsString('<span class="test-audio">Lac qicik | example</span>', $output);
        $this->assertStringContainsString('<th scope="row">MORPH</th>', $output);
        $this->assertStringContainsString('class="ll-interlinear-shortcode__cell"', $output);
        $this->assertStringContainsString('∅', $output);
        $this->assertStringContainsString('<th scope="row">Free translation</th>', $output);
        $this->assertStringContainsString('little boy | literally small son', $output);
        $this->assertStringNotContainsString('colspan=', $output);
        $this->assertStringNotContainsString('[ll_test_audio]', $output);
    }

    public function test_shortcode_can_hide_sentence_row(): void
    {
        $output = do_shortcode(
            "[ll_interlinear show_text=\"0\" text=\"Hidden line\" free_translation=\"little boy\"]\n"
            . "MORPH | lac | ∅ | qicik\n"
            . "GLOSS | son | M.EZ | small\n"
            . '[/ll_interlinear]'
        );

        $this->assertStringNotContainsString('<th scope="row">Sentence</th>', $output);
        $this->assertStringContainsString('<th scope="row">Free translation</th>', $output);
    }

    public function test_shortcode_returns_empty_string_without_rows(): void
    {
        $this->assertSame('', do_shortcode('[ll_interlinear][/ll_interlinear]'));
    }
}
