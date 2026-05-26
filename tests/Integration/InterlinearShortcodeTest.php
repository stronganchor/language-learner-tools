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
        $this->assertStringContainsString('<th scope="row">WORD</th>', $output);
        $this->assertStringContainsString('<th scope="row">GLOSS</th>', $output);
        $this->assertStringContainsString('lac-', $output);
        $this->assertStringContainsString('qicik', $output);
        $this->assertStringContainsString('small', $output);
        $this->assertStringContainsString('class="ling-abbr"', $output);
        $this->assertStringNotContainsString('<th scope="row">MORPH</th>', $output);
    }

    public function test_shortcode_returns_empty_string_without_rows(): void
    {
        $this->assertSame('', do_shortcode('[ll_interlinear][/ll_interlinear]'));
    }
}
