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

    public function test_document_title_parts_fill_in_wordset_name_without_touching_site_name(): void
    {
        $wordset = $this->upsertDefaultWordset('Spanish Lessons');
        $site_name = trim(wp_strip_all_tags((string) get_bloginfo('name')));

        $parts = ll_tools_filter_wordset_page_document_title_parts([
            'title' => '',
            'site' => $site_name,
        ], $wordset);

        $this->assertSame('Spanish Lessons', $parts['title']);
        $this->assertSame($site_name, $parts['site']);
        $this->assertNotFalse(has_filter('document_title_parts', 'll_tools_filter_wordset_page_document_title_parts'));
    }

    public function test_document_title_parts_do_not_override_existing_manual_title(): void
    {
        $wordset = $this->upsertDefaultWordset('Spanish Lessons');

        $parts = ll_tools_filter_wordset_page_document_title_parts([
            'title' => 'Custom Browser Title',
            'site' => 'Example Site',
        ], $wordset);

        $this->assertSame('Custom Browser Title', $parts['title']);
        $this->assertSame('Example Site', $parts['site']);
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
