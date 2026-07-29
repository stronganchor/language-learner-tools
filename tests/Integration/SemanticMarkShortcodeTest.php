<?php
declare(strict_types=1);

final class SemanticMarkShortcodeTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['ll_tools_public_assets_needed']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ll_tools_public_assets_needed']);
        parent::tearDown();
    }

    public function test_canonical_shortcode_renders_class_only_markup_for_each_tone(): void
    {
        foreach (['orange', 'blue', 'green'] as $tone) {
            $output = do_shortcode('[ll_mark tone="' . $tone . '"]Marked[/ll_mark]');

            $this->assertSame(
                '<span class="ll-tools-mark ll-tools-mark--' . $tone
                    . ' ll-tools-mark--semantic">Marked</span>',
                $output
            );
            $this->assertStringNotContainsString(' style=', $output);
        }
    }

    public function test_legacy_aliases_preserve_their_canonical_tones_and_ignore_attributes(): void
    {
        $expected = [
            'color1' => 'orange',
            'color2' => 'blue',
            'color3' => 'green',
        ];

        foreach ($expected as $tag => $tone) {
            $output = do_shortcode(
                '[' . $tag . ' tone="green" class="hostile"]Legacy[/' . $tag . ']'
            );

            $this->assertSame(
                '<span class="ll-tools-mark ll-tools-mark--' . $tone . '">Legacy</span>',
                $output
            );
        }
    }

    public function test_invalid_tone_falls_back_without_reflecting_attribute_content(): void
    {
        $output = do_shortcode(
            '[ll_mark tone="blue&quot; onclick=&quot;alert(1)"]Safe[/ll_mark]'
        );

        $this->assertSame(
            '<span class="ll-tools-mark ll-tools-mark--orange ll-tools-mark--semantic">Safe</span>',
            $output
        );
        $this->assertStringNotContainsString('onclick', $output);
    }

    public function test_nested_shortcodes_render_and_their_html_is_sanitized(): void
    {
        add_shortcode('ll_test_unsafe_mark_content', static function (): string {
            return '<em>nested</em><script>alert(1)</script>'
                . '<a href="javascript:alert(2)" onclick="alert(3)">link</a>';
        });

        try {
            $output = do_shortcode(
                '[ll_mark tone="blue"]Outer [color3]'
                . '[ll_test_unsafe_mark_content]'
                . '[/color3][/ll_mark]'
            );
        } finally {
            remove_shortcode('ll_test_unsafe_mark_content');
        }

        $this->assertStringContainsString(
            '<span class="ll-tools-mark ll-tools-mark--blue ll-tools-mark--semantic">Outer '
                . '<span class="ll-tools-mark ll-tools-mark--green">',
            $output
        );
        $this->assertStringContainsString('<em>nested</em>', $output);
        $this->assertStringContainsString('>link</a>', $output);
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringNotContainsString('onclick', $output);
    }

    public function test_empty_shortcode_does_not_render_an_empty_wrapper(): void
    {
        $this->assertSame('', do_shortcode('[ll_mark tone="blue"][/ll_mark]'));
        $this->assertSame('', do_shortcode('[color1][/color1]'));
    }

    public function test_block_markup_is_flattened_to_valid_inline_content(): void
    {
        add_shortcode('ll_test_block_mark_content', static function (): string {
            return '<div>Block <p><strong>content</strong></p></div><table><tr><td>cell</td></tr></table>';
        });
        try {
            $output = do_shortcode(
                '[ll_mark tone="green"][ll_test_block_mark_content][/ll_mark]'
            );
        } finally {
            remove_shortcode('ll_test_block_mark_content');
        }

        $this->assertStringContainsString('Block <strong>content</strong>cell', $output);
        $this->assertStringNotContainsString('<div', $output);
        $this->assertStringNotContainsString('<p', $output);
        $this->assertStringNotContainsString('<table', $output);
        $this->assertStringNotContainsString('<tr', $output);
        $this->assertStringNotContainsString('<td', $output);
    }

    public function test_all_tags_are_registered_for_ordinary_page_asset_detection(): void
    {
        $registered_tags = ll_tools_get_public_assets_shortcode_tags();

        foreach (ll_tools_semantic_mark_shortcode_tags() as $tag) {
            $this->assertTrue(shortcode_exists($tag));
            $this->assertContains($tag, $registered_tags);
        }

        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Semantic mark asset page',
            'post_content' => '[color2]Marked[/color2]',
        ]);

        $this->go_to(get_permalink($page_id));

        $this->assertTrue(ll_tools_content_has_public_assets_shortcodes('[color2]Marked[/color2]'));
        $this->assertTrue(ll_tools_request_needs_public_assets());

        wp_dequeue_style('ll-tools-style');
        do_shortcode('[ll_mark tone="green"]Dynamic mark[/ll_mark]');
        $this->assertTrue(ll_tools_public_assets_marked());
        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
    }

    public function test_legacy_visual_contract_and_public_api_are_documented(): void
    {
        $css = (string) file_get_contents(LL_TOOLS_BASE_PATH . 'css/language-learner-tools.css');
        $readme = (string) file_get_contents(LL_TOOLS_BASE_PATH . 'README.md');
        $architecture = (string) file_get_contents(LL_TOOLS_BASE_PATH . 'CODEBASE_ARCHITECTURE.md');

        $this->assertStringContainsString('#ff6600 !important', $css);
        $this->assertStringContainsString('#0066ff !important', $css);
        $this->assertStringContainsString('#77b300 !important', $css);
        $this->assertStringContainsString('font-weight: 700 !important', $css);
        $this->assertStringContainsString('.ll-tools-mark.ll-tools-mark--orange', $css);
        $this->assertStringContainsString('.ll-tools-mark.ll-tools-mark--semantic.ll-tools-mark--orange', $css);
        $this->assertStringContainsString('#9c3d00 !important', $css);
        $this->assertStringContainsString('#0057b8 !important', $css);
        $this->assertStringContainsString('#416900 !important', $css);
        $this->assertStringContainsString('[ll_mark tone="orange"]', $readme);
        $this->assertStringContainsString('[color1]', $readme);
        $this->assertStringContainsString('semantic-mark-shortcode.php', $architecture);
    }
}
