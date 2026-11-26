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
        [$hiddenRoot, $middle, $leaf, $otherRoot, $reference] = $categories;
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [
            $middle => [$hiddenRoot],
            $leaf => [$middle, $otherRoot],
        ]);
        update_term_meta($wordset, LL_TOOLS_CURRICULUM_ADDITIONAL_META, [$reference]);

        $this->assertSame([
            'enabled' => true,
            'levels' => [1 => [$otherRoot], 2 => [$middle], 3 => [$leaf]],
            'additional' => [$reference],
        ], ll_tools_wordset_curriculum_groups($wordset, [$leaf, $middle, $reference, $otherRoot, $leaf]));
        $this->assertSame([3 => [$leaf]], ll_tools_wordset_curriculum_groups($wordset, [$leaf])['levels']);
        update_term_meta($wordset, LL_TOOLS_CURRICULUM_ADDITIONAL_META, []);
        $this->assertSame(
            [1 => [$reference, $otherRoot], 3 => [$leaf]],
            ll_tools_wordset_curriculum_groups($wordset, [$leaf, $reference, $otherRoot])['levels']
        );
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [$middle => [$leaf], $leaf => [$middle]]);
        $disabled = ['enabled' => false, 'levels' => [], 'additional' => []];
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot]));
        delete_term_meta($wordset, 'll_wordset_category_prerequisites');
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot]));
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', [$middle => [$otherRoot]]);
        $this->assertSame($disabled, ll_tools_wordset_curriculum_groups($wordset, [$otherRoot, $middle]));
    }

    public function test_turkish_reference_default_is_identity_scoped_and_explicit_empty_overrides_it(): void
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
            $this->assertSame([], ll_tools_wordset_curriculum_additional_ids(8062));
            add_filter('home_url', $turkish_home);
            $this->assertSame([8121], ll_tools_wordset_curriculum_additional_ids(8062));
            $this->assertSame([], ll_tools_wordset_curriculum_additional_ids(8063));
            update_term_meta(8062, LL_TOOLS_CURRICULUM_ADDITIONAL_META, []);
            $this->assertSame([], ll_tools_wordset_curriculum_additional_ids(8062));
        } finally {
            remove_filter('home_url', $turkish_home);
            delete_term_meta(8062, LL_TOOLS_CURRICULUM_ADDITIONAL_META);
            foreach ($cache as $id => $original) {
                wp_cache_delete($id, 'terms');
                if ($original !== false) {
                    wp_cache_set($id, $original, 'terms');
                }
            }
        }
    }

    public function test_bundles_and_templates_remap_additional_categories_and_keep_partial_imports_scoped(): void
    {
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/export-import.php';
        $source = self::factory()->term->create(['taxonomy' => 'wordset']);
        $target = self::factory()->term->create(['taxonomy' => 'wordset']);
        $reference = self::factory()->term->create_and_get(['taxonomy' => 'word-category']);
        $destination = self::factory()->term->create(['taxonomy' => 'word-category']);
        $untouched = self::factory()->term->create(['taxonomy' => 'word-category']);
        update_term_meta($source, LL_TOOLS_CURRICULUM_ADDITIONAL_META, [(int) $reference->term_id]);
        $payload = ll_tools_export_collect_wordsets([$source])[0];
        $this->assertArrayNotHasKey(LL_TOOLS_CURRICULUM_ADDITIONAL_META, $payload['meta']);
        $this->assertSame([$reference->slug], $payload['curriculum_additional_categories']);
        ll_tools_copy_wordset_template_settings($source, $target, [(int) $reference->term_id => $destination]);
        $this->assertSame([$destination], ll_tools_wordset_curriculum_additional_ids($target));

        update_term_meta($target, LL_TOOLS_CURRICULUM_ADDITIONAL_META, [$untouched]);
        $payload['meta'][LL_TOOLS_CURRICULUM_ADDITIONAL_META] = [[(int) $reference->term_id]];
        $this->assertTrue(ll_tools_import_apply_template_wordset_meta($target, $payload, [$reference->slug => $destination]));
        $this->assertSame([$untouched, $destination], ll_tools_wordset_curriculum_additional_ids($target));
        $payload['curriculum_additional_categories'] = [];
        $this->assertTrue(ll_tools_import_apply_template_wordset_meta($target, $payload, [$reference->slug => $destination]));
        $this->assertSame([$untouched], ll_tools_wordset_curriculum_additional_ids($target));
    }
}
