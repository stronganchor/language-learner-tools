<?php
declare(strict_types=1);

final class WordCategoryChecklistLabelTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_category_checklist_labels_isolated_categories_with_wordset_names_in_visible_sort_order(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_zulu = wp_insert_term('Zulu Scope', 'wordset', ['slug' => 'zulu-scope']);
        $wordset_alpha = wp_insert_term('Alpha Scope', 'wordset', ['slug' => 'alpha-scope']);

        $this->assertIsArray($wordset_zulu);
        $this->assertIsArray($wordset_alpha);

        $zulu_id = (int) $wordset_zulu['term_id'];
        $alpha_id = (int) $wordset_alpha['term_id'];

        $zulu_category_id = ll_tools_create_or_get_wordset_category('Tree Types', $zulu_id);
        $alpha_category_id = ll_tools_create_or_get_wordset_category('Tree Types', $alpha_id);

        $this->assertIsInt($zulu_category_id);
        $this->assertIsInt($alpha_category_id);

        ob_start();
        ll_render_category_selection_field('word_images');
        $html = (string) ob_get_clean();

        $alpha_label = 'Tree Types - Alpha Scope';
        $zulu_label = 'Tree Types - Zulu Scope';

        $this->assertStringContainsString('value="' . $alpha_category_id . '"', $html);
        $this->assertStringContainsString('value="' . $zulu_category_id . '"', $html);
        $this->assertStringContainsString($alpha_label, $html);
        $this->assertStringContainsString($zulu_label, $html);
        $this->assertLessThan(strpos($html, $zulu_label), strpos($html, $alpha_label));
    }

    public function test_image_upload_parent_category_dropdown_uses_wordset_labeled_category_names(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset = wp_insert_term('Parent Scope', 'wordset', ['slug' => 'parent-scope']);
        $this->assertIsArray($wordset);

        $wordset_id = (int) $wordset['term_id'];
        $parent_id = ll_tools_create_or_get_wordset_category('Plants', $wordset_id);
        $this->assertIsInt($parent_id);

        $terms = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
        ]);

        $this->assertIsArray($terms);

        ob_start();
        ll_image_upload_render_parent_category_options($terms);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="' . $parent_id . '"', $html);
        $this->assertStringContainsString('Plants - Parent Scope', $html);
    }
}
