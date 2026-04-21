<?php
declare(strict_types=1);

final class WordsetPageTitleTest extends LL_Tools_TestCase
{
    public function test_seeded_default_placeholder_title_renders_as_lessons(): void
    {
        $wordset = $this->upsertDefaultWordset('Default Word Set');

        $this->assertSame(
            'Lessons',
            ll_tools_get_wordset_page_display_title($wordset)
        );

        $html = ll_tools_render_wordset_page_content((int) $wordset->term_id);

        $this->assertStringContainsString('<h1 class="ll-wordset-title">Lessons</h1>', $html);
        $this->assertStringNotContainsString('<h1 class="ll-wordset-title">Default Word Set</h1>', $html);
    }

    public function test_renamed_default_wordset_keeps_its_custom_title(): void
    {
        $wordset = $this->upsertDefaultWordset('Spanish Lessons');

        $this->assertSame(
            'Spanish Lessons',
            ll_tools_get_wordset_page_display_title($wordset)
        );

        $html = ll_tools_render_wordset_page_content((int) $wordset->term_id);

        $this->assertStringContainsString('<h1 class="ll-wordset-title">Spanish Lessons</h1>', $html);
    }

    private function upsertDefaultWordset(string $name): WP_Term
    {
        $existing = get_term_by('slug', 'default-word-set', 'wordset');
        if ($existing instanceof WP_Term) {
            wp_update_term($existing->term_id, 'wordset', [
                'name' => $name,
            ]);

            $term = get_term((int) $existing->term_id, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $term);

            return $term;
        }

        $created = wp_insert_term($name, 'wordset', [
            'slug' => 'default-word-set',
        ]);

        $this->assertIsArray($created);
        $this->assertFalse(is_wp_error($created));

        $term = get_term((int) ($created['term_id'] ?? 0), 'wordset');
        $this->assertInstanceOf(WP_Term::class, $term);

        return $term;
    }
}
