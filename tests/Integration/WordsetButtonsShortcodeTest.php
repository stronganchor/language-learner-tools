<?php
declare(strict_types=1);

final class WordsetButtonsShortcodeTest extends LL_Tools_TestCase
{
    public function test_shortcode_renders_buttons_for_viewable_wordsets_only(): void
    {
        $public_term = wp_insert_term('Buttons Public Wordset', 'wordset');
        $private_term = wp_insert_term('Buttons Private Wordset', 'wordset');

        $this->assertIsArray($public_term);
        $this->assertIsArray($private_term);
        $this->assertFalse(is_wp_error($public_term));
        $this->assertFalse(is_wp_error($private_term));

        $public_term_id = (int) ($public_term['term_id'] ?? 0);
        $private_term_id = (int) ($private_term['term_id'] ?? 0);
        update_term_meta($private_term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $public_wordset = get_term($public_term_id, 'wordset');
        $private_wordset = get_term($private_term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $public_wordset);
        $this->assertInstanceOf(WP_Term::class, $private_wordset);

        $html = do_shortcode('[ll_wordset_buttons]');

        $this->assertStringContainsString('ll-wordset-buttons-shortcode', $html);
        $this->assertStringContainsString('ll-study-btn', $html);
        $this->assertStringContainsString($public_wordset->name, $html);
        $this->assertStringContainsString(
            esc_url(ll_tools_get_wordset_page_view_url($public_wordset)),
            $html
        );
        $this->assertStringNotContainsString($private_wordset->name, $html);

        $this->assertTrue(wp_style_is('ll-wordset-pages-css', 'enqueued'));
        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
    }

    public function test_shortcode_honors_hide_empty_attribute(): void
    {
        $visible_term = wp_insert_term('Buttons Empty Hidden Wordset', 'wordset');
        $filled_term = wp_insert_term('Buttons Filled Wordset', 'wordset');

        $this->assertIsArray($visible_term);
        $this->assertIsArray($filled_term);
        $this->assertFalse(is_wp_error($visible_term));
        $this->assertFalse(is_wp_error($filled_term));

        $filled_term_id = (int) ($filled_term['term_id'] ?? 0);
        $category = wp_insert_term('Buttons Category', 'word-category');
        $this->assertIsArray($category);
        $this->assertFalse(is_wp_error($category));
        $category_id = (int) ($category['term_id'] ?? 0);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Buttons Filled Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$filled_term_id], 'wordset', false);

        $html = do_shortcode('[ll_wordset_buttons hide_empty="1"]');

        $this->assertStringContainsString('Buttons Filled Wordset', $html);
        $this->assertStringNotContainsString('Buttons Empty Hidden Wordset', $html);
    }
}
