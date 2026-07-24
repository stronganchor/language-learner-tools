<?php
declare(strict_types=1);

final class QuizPagesScopedContentEpochTest extends LL_Tools_TestCase
{
    public function test_scoped_public_consumer_keys_do_not_churn_for_an_unrelated_wordset(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategory('Scoped consumers A');
        [$wordset_b, $category_b] = $this->createOwnedCategory('Scoped consumers B');

        $keys_a_before = $this->scopedConsumerKeys($wordset_a, $category_a);
        $keys_b_before = $this->scopedConsumerKeys($wordset_b, $category_b);
        $unscoped_ai_before = ll_tools_ai_crawler_export_cache_key(['key' => 'wordsets']);
        $unscoped_buttons_before = ll_tools_wordset_buttons_shortcode_cache_key(['hide_empty' => '0'], 'wordset_buttons');

        ll_tools_bump_category_cache_version([$category_b], [$wordset_b], true);

        $this->assertSame($keys_a_before, $this->scopedConsumerKeys($wordset_a, $category_a));
        $this->assertNotSame($keys_b_before, $this->scopedConsumerKeys($wordset_b, $category_b));
        $this->assertNotSame($unscoped_ai_before, ll_tools_ai_crawler_export_cache_key(['key' => 'wordsets']));
        $this->assertNotSame(
            $unscoped_buttons_before,
            ll_tools_wordset_buttons_shortcode_cache_key(['hide_empty' => '0'], 'wordset_buttons')
        );

        ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);
        $this->assertNotSame($keys_a_before, $this->scopedConsumerKeys($wordset_a, $category_a));
    }

    public function test_quiz_page_catalog_keys_follow_only_the_relevant_content_scope(): void
    {
        global $wpdb;

        [$wordset_a, $category_a] = $this->createOwnedCategory('Quiz catalog A');
        [$wordset_b, $category_b] = $this->createOwnedCategory('Quiz catalog B');
        $opts_a = ['wordset' => (string) $wordset_a];
        $opts_b = ['wordset' => (string) $wordset_b];

        $data_key_a_before = ll_tools_quiz_pages_data_cache_key($opts_a, 1);
        $data_key_b_before = ll_tools_quiz_pages_data_cache_key($opts_b, 1);
        $unscoped_key_before = ll_tools_quiz_pages_data_cache_key([], 1);

        ll_collect_wc_ids_for_wordset_term_ids([$wordset_a]);
        $catalog_options_before = $this->categoryCatalogTransientOptions($wpdb);

        ll_tools_bump_category_cache_version([$category_b], [$wordset_b], true);

        $this->assertSame($data_key_a_before, ll_tools_quiz_pages_data_cache_key($opts_a, 1));
        $this->assertNotSame($data_key_b_before, ll_tools_quiz_pages_data_cache_key($opts_b, 1));
        $this->assertNotSame($unscoped_key_before, ll_tools_quiz_pages_data_cache_key([], 1));
        ll_collect_wc_ids_for_wordset_term_ids([$wordset_a]);
        $this->assertSame(
            $catalog_options_before,
            $this->categoryCatalogTransientOptions($wpdb),
            'An unrelated wordset must retain the durable category-ID catalog key.'
        );

        ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);

        $this->assertNotSame($data_key_a_before, ll_tools_quiz_pages_data_cache_key($opts_a, 1));
        ll_collect_wc_ids_for_wordset_term_ids([$wordset_a]);
        $this->assertCount(
            count($catalog_options_before) + 1,
            $this->categoryCatalogTransientOptions($wpdb),
            'The affected wordset must publish a new category-ID catalog generation.'
        );
    }

    public function test_materialized_wordset_caches_rotate_only_for_the_affected_scope(): void
    {
        global $wpdb;

        [$wordset_a, $category_a] = $this->createOwnedCategory('Materialized scope A');
        [$wordset_b, $category_b] = $this->createOwnedCategory('Materialized scope B');
        $prefixes = [
            '_transient_ll_vocab_lesson_deep_counts_',
            '_transient_ll_wsp_category_rows_',
            '_transient_ll_wsp_categories_',
        ];

        $this->warmMaterializedWordsetCaches($wordset_a, $category_a);
        $before = $this->transientPrefixCounts($wpdb, $prefixes);
        $search_generation_before = $this->wordsetCategorySearchPublishedGeneration($wordset_a);
        $this->assertNotSame('', $search_generation_before);

        ll_tools_bump_category_cache_version([$category_b], [$wordset_b], true);
        $this->warmMaterializedWordsetCaches($wordset_a, $category_a);
        $this->assertSame($before, $this->transientPrefixCounts($wpdb, $prefixes));
        $this->assertSame(
            $search_generation_before,
            $this->wordsetCategorySearchPublishedGeneration($wordset_a),
            'An unrelated wordset must retain the durable category-search generation.'
        );

        ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);
        $this->warmMaterializedWordsetCaches($wordset_a, $category_a);
        $after = $this->transientPrefixCounts($wpdb, $prefixes);
        foreach ($prefixes as $prefix) {
            $this->assertGreaterThan(
                (int) ($before[$prefix] ?? 0),
                (int) ($after[$prefix] ?? 0),
                'Affected cache generation did not rotate for ' . $prefix
            );
        }
        $this->assertNotSame(
            $search_generation_before,
            $this->wordsetCategorySearchPublishedGeneration($wordset_a),
            'The affected wordset must publish a new durable category-search generation.'
        );
    }

    /** @return array{0:int,1:int} */
    private function createOwnedCategory(string $label): array
    {
        $wordset = wp_insert_term($label . ' wordset ' . wp_generate_uuid4(), 'wordset');
        $category = wp_insert_term($label . ' category ' . wp_generate_uuid4(), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);
        $this->assertGreaterThan(0, $category_id);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);

        return [$wordset_id, $category_id];
    }

    /** @return string[] */
    private function categoryCatalogTransientOptions(wpdb $wpdb): array
    {
        $prefix = $wpdb->esc_like('_transient_ll_wcids_ws_') . '%';
        $options = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT option_name
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             ORDER BY option_name ASC",
            $prefix
        )));
        return array_values(array_unique($options));
    }

    /** @return array<string,string> */
    private function scopedConsumerKeys(int $wordset_id, int $category_id): array
    {
        return [
            'quiz_catalog' => ll_tools_quiz_pages_data_cache_key(['wordset' => (string) $wordset_id], 1),
            'lesson_grid' => ll_tools_vocab_lesson_grid_public_cache_key(0, $wordset_id, $category_id),
            'lazy_ajax' => ll_tools_wordset_page_lazy_cards_ajax_cache_key([
                'token' => 'shared_' . md5((string) $wordset_id),
                'wordset_id' => $wordset_id,
                'offset' => 18,
            ]),
            'search_ajax' => ll_tools_wordset_page_category_search_ajax_cache_key([
                'token' => 'search_' . md5((string) $wordset_id),
                'wordset_id' => $wordset_id,
                'query' => 'word',
            ]),
        ];
    }

    private function warmMaterializedWordsetCaches(int $wordset_id, int $category_id): void
    {
        ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id);
        ll_tools_get_wordset_page_category_rows($wordset_id, 2, false);
        ll_tools_get_wordset_page_categories($wordset_id, 2, ['defer_previews' => true]);
        ll_tools_get_wordset_page_category_search_index($wordset_id, [$category_id]);
    }

    private function wordsetCategorySearchPublishedGeneration(int $wordset_id): string
    {
        $state = ll_tools_get_wordset_category_search_state($wordset_id);

        return (string) ($state['published_generation'] ?? '');
    }

    /**
     * @param string[] $prefixes
     * @return array<string,int>
     */
    private function transientPrefixCounts(wpdb $wpdb, array $prefixes): array
    {
        $counts = [];
        foreach ($prefixes as $prefix) {
            $counts[$prefix] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));
        }
        return $counts;
    }
}
