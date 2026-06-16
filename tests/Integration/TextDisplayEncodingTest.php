<?php
declare(strict_types=1);

final class TextDisplayEncodingTest extends LL_Tools_TestCase
{
    public function test_decode_display_entities_handles_nested_entities_and_special_characters(): void
    {
        $input = 'She can&amp;#8217;t open &amp;amp; close &Ccedil;ay &amp;lt;ok&amp;gt;';
        $expected = html_entity_decode(
            'She can&#8217;t open &amp; close &Ccedil;ay &lt;ok&gt;',
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $this->assertSame($expected, ll_tools_decode_display_entities($input));
    }

    public function test_display_escape_decodes_entities_without_rendering_markup(): void
    {
        $html = ll_tools_esc_html_display('She can&#8217;t <b>&amp; learn</b>');

        $this->assertStringNotContainsString('&amp;#8217;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;&amp; learn&lt;/b&gt;', $html);
        $this->assertSame(
            html_entity_decode('She can&#8217;t <b>&amp; learn</b>', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    public function test_search_normalization_folds_turkish_dotted_capital_i(): void
    {
        $this->assertSame('insanlar', ll_tools_normalize_text_for_search("\u{0130}nsanlar"));
        $this->assertTrue(ll_tools_text_matches_search("\u{0130}nsanlar 2", 'insanlar'));
        $this->assertTrue(ll_tools_text_matches_search("I\u{015F}\u{0131}k", "\u{0131}\u{015F}\u{0131}k"));
        $this->assertTrue(ll_tools_text_matches_search('Image', 'image'));
    }
}
