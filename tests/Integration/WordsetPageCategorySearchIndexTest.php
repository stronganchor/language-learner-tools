<?php
declare(strict_types=1);

final class WordsetPageCategorySearchIndexTest extends LL_Tools_TestCase
{
    public function test_category_search_index_limits_sql_to_words_with_allowed_categories_without_changing_deepest_category_rules(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Search Index Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_exists = term_exists($wordset_id, 'wordset');
        $this->assertIsArray($wordset_exists);
        $this->assertSame($wordset_id, (int) $wordset_exists['term_id']);

        $allowed = wp_insert_term('Search Index Allowed ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($allowed));
        $this->assertIsArray($allowed);
        $allowed_id = (int) $allowed['term_id'];

        $child = wp_insert_term('Search Index Child ' . wp_generate_password(4, false), 'word-category', [
            'parent' => $allowed_id,
        ]);
        $this->assertFalse(is_wp_error($child));
        $this->assertIsArray($child);
        $child_id = (int) $child['term_id'];
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        update_term_meta($allowed_id, $owner_meta_key, (string) $wordset_id);
        update_term_meta($child_id, $owner_meta_key, (string) $wordset_id);

        $only_allowed = $this->createSearchWord('Allowed Search Token', 'Allowed Translation Token', $wordset_id, [$allowed_id]);
        $deep_child = $this->createSearchWord('Child Search Token', 'Child Translation Token', $wordset_id, [$allowed_id, $child_id]);

        $this->assertWordHasTerm($only_allowed, 'wordset', $wordset_id);
        $this->assertWordHasTerm($only_allowed, 'word-category', $allowed_id);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            if (strpos($query, 'category_search') === false && strpos($query, 'allowed_category_relationships') === false) {
                return $query;
            }
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$allowed_id]);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertArrayHasKey($allowed_id, $index);
        $search_text = (string) ($index[$allowed_id]['search_text'] ?? '');
        $this->assertStringContainsString('Allowed Search Token', $search_text);
        $this->assertStringContainsString('Allowed Translation Token', $search_text);
        $this->assertStringNotContainsString('Child Search Token', $search_text);
        $this->assertStringNotContainsString('Child Translation Token', $search_text);

        $queries_sql = implode("\n", $captured_queries);
        $this->assertStringContainsString('allowed_category_relationships', $queries_sql);
        $this->assertStringContainsString('allowed_category_taxonomy.term_id IN', $queries_sql);

        $this->assertGreaterThan(0, $only_allowed);
        $this->assertGreaterThan(0, $deep_child);
        $this->assertNotEmpty($wpdb->last_query);
    }

    /**
     * @param int[] $category_ids
     */
    private function createSearchWord(string $title, string $translation, int $wordset_id, array $category_ids): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        $wordset_result = wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        $category_result = wp_set_object_terms($word_id, array_values(array_map('intval', $category_ids)), 'word-category', false);
        $this->assertFalse(is_wp_error($wordset_result));
        $this->assertFalse(is_wp_error($category_result));
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function assertWordHasTerm(int $word_id, string $taxonomy, int $term_id): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM {$wpdb->term_relationships} AS relationships
             INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
                ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
                AND taxonomy.taxonomy = %s
                AND taxonomy.term_id = %d
             WHERE relationships.object_id = %d",
            $taxonomy,
            $term_id,
            $word_id
        ));

        $this->assertSame(1, $count);
    }
}
