<?php
declare(strict_types=1);

final class WordsetCurriculumGroupsTest extends LL_Tools_TestCase
{
    public function test_grouping_uses_full_depth_and_returns_only_visible_ids_in_display_order(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $categories = [];
        for ($i = 0; $i < 5; $i++) {
            $id = self::factory()->term->create(['taxonomy' => 'word-category']);
            ll_tools_set_category_wordset_owner($id, $wordset);
            $categories[] = $id;
        }
        [$hiddenRoot, $middle, $leaf, $otherRoot, $independent] = $categories;
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [
            $middle => [$hiddenRoot],
            $leaf => [$middle, $otherRoot],
        ]);

        $this->assertSame([
            'enabled' => true,
            'levels' => [1 => [$independent, $otherRoot], 2 => [$middle], 3 => [$leaf]],
        ], ll_tools_wordset_curriculum_groups($wordset, [$leaf, $middle, $independent, $otherRoot, $leaf]));
        $this->assertSame([3 => [$leaf]], ll_tools_wordset_curriculum_groups($wordset, [$leaf])['levels']);
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [$middle => [$leaf], $leaf => [$middle]]);
        $disabled = ['enabled' => false, 'levels' => []];
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot]));
        delete_term_meta($wordset, 'll_wordset_category_prerequisites');
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot]));
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [$middle => [$otherRoot]]);
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot, $middle]));
    }

    public function test_turkish_reference_requires_the_published_site_wordset_and_category(): void
    {
        // Fixed-ID cached terms model the deployment without inserting or reassigning database IDs.
        $cache = [];
        foreach ([8062 => ['vocab', 'wordset'], 8121 => ['most-common-words-vocab', 'word-category']] as $id => [$slug, $taxonomy]) {
            $cache[$id] = wp_cache_get($id, 'terms');
            $term = self::factory()->term->create_and_get(['taxonomy' => $taxonomy]);
            $term->term_id = $id;
            $term->slug = $slug;
            wp_cache_set($id, $term, 'terms');
        }
        $turkish_home = static function (): string { return 'https://www.turkishtextbook.com/'; };
        try {
            $this->assertFalse(ll_tools_wordset_curriculum_is_turkish_reference(8062, 8121));
            add_filter('home_url', $turkish_home);
            $this->assertTrue(ll_tools_wordset_curriculum_is_turkish_reference(8062, 8121));
            $this->assertFalse(ll_tools_wordset_curriculum_is_turkish_reference(8063, 8121));
            $this->assertFalse(ll_tools_wordset_curriculum_is_turkish_reference(8062, 8122));
            $term = get_term(8121, 'word-category');
            $term->slug = 'another-category';
            wp_cache_set(8121, $term, 'terms');
            $this->assertFalse(ll_tools_wordset_curriculum_is_turkish_reference(8062, 8121));
        } finally {
            remove_filter('home_url', $turkish_home);
            foreach ($cache as $id => $original) {
                wp_cache_delete($id, 'terms');
                if ($original !== false) {
                    wp_cache_set($id, $original, 'terms');
                }
            }
        }
    }

    public function test_level_cards_include_only_actual_categories_and_content_with_a_matching_level(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $root = self::factory()->term->create(['taxonomy' => 'word-category']);
        $leaf = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($root, $wordset);
        ll_tools_set_category_wordset_owner($leaf, $wordset);
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [$leaf => [$root]]);
        $catalog = ll_tools_wordset_level_page_catalog($wordset, [
            ['id' => $root, 'is_public' => true],
            ['id' => $leaf, 'is_public' => true],
            ['id' => 987, 'is_public' => true, 'is_virtual_category' => true],
        ], [['id' => 23, 'menu_order' => 2], ['id' => 39, 'menu_order' => 0]]);
        $this->assertSame([1 => [$root], 2 => [$leaf]], array_column($catalog['public']['levels'], 'categoryIds', 'level'));
        $this->assertSame([1 => [], 2 => [23]], array_column($catalog['public']['levels'], 'contentIds', 'level'));
        $this->assertSame([1, 2], array_keys($catalog['scope']));
    }
}
