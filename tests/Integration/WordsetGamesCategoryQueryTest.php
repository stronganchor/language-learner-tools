<?php
declare(strict_types=1);

final class WordsetGamesCategoryQueryTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
    }

    protected function tearDown(): void
    {
        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_wordset_game_categories_do_not_hydrate_all_word_ids(): void
    {
        $wordset_a_id = $this->createTerm('Games Category Query Wordset A', 'wordset');
        $wordset_b_id = $this->createTerm('Games Category Query Wordset B', 'wordset');
        $category_a_id = $this->createTerm('Games Category Query Category A', 'word-category');
        $category_b_id = $this->createTerm('Games Category Query Category B', 'word-category');

        $this->createWord($wordset_a_id, $category_a_id, 'Games Category Query Word A1');
        $this->createWord($wordset_a_id, $category_a_id, 'Games Category Query Word A2');
        $this->createWord($wordset_b_id, $category_b_id, 'Games Category Query Word B');

        $captured_word_queries = [];
        $captured_category_term_queries = [];
        $capture_word_queries = $this->captureWordQueries($captured_word_queries);
        $capture_category_term_queries = $this->captureWordCategoryTermQueries($captured_category_term_queries);

        add_action('pre_get_posts', $capture_word_queries, 10, 1);
        add_filter('get_terms_args', $capture_category_term_queries, 10, 2);

        try {
            $categories = ll_tools_wordset_games_categories_for_wordset($wordset_a_id);
        } finally {
            remove_filter('get_terms_args', $capture_category_term_queries, 10);
            remove_action('pre_get_posts', $capture_word_queries, 10);
        }

        $this->assertSame([$category_a_id], array_values(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $categories)));

        $this->assertSame([], $captured_word_queries, 'Wordset game category discovery should not query every word ID in the wordset.');
        $this->assertTrue(
            $this->capturedCategoryQueryIncludesOnly($captured_category_term_queries, [$category_a_id]),
            'Word-category term lookup should be bounded to the discovered category IDs.'
        );
        $this->assertFalse(
            $this->capturedCategoryQueryIncludesOnly($captured_category_term_queries, [$category_b_id]),
            'The category query should not include categories from another wordset.'
        );
    }

    private function createTerm(string $name, string $taxonomy): int
    {
        $created = wp_insert_term($name . ' ' . wp_generate_password(6, false), $taxonomy);
        $this->assertIsArray($created);

        $term_id = (int) ($created['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);

        return $term_id;
    }

    private function createWord(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(6, false),
        ]);
        $this->assertIsInt($word_id);
        $this->assertGreaterThan(0, $word_id);

        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    private function captureWordQueries(array &$captured_word_queries): callable
    {
        return static function (WP_Query $query) use (&$captured_word_queries): void {
            if ((string) $query->get('post_type') !== 'words') {
                return;
            }

            if ((int) $query->get('posts_per_page') !== -1) {
                return;
            }

            $captured_word_queries[] = $query->query_vars;
        };
    }

    private function captureWordCategoryTermQueries(array &$captured_category_term_queries): callable
    {
        return static function (array $args, array $taxonomies) use (&$captured_category_term_queries): array {
            $all_taxonomies = array_map('strval', array_merge((array) $taxonomies, (array) ($args['taxonomy'] ?? [])));
            if (in_array('word-category', $all_taxonomies, true)) {
                $captured_category_term_queries[] = $args;
            }

            return $args;
        };
    }

    /**
     * @param int[] $expected_ids
     */
    private function capturedCategoryQueryIncludesOnly(array $captured_category_term_queries, array $expected_ids): bool
    {
        $expected_ids = array_values(array_unique(array_filter(array_map('intval', $expected_ids), static function (int $id): bool {
            return $id > 0;
        })));
        sort($expected_ids);

        foreach ($captured_category_term_queries as $args) {
            $include = array_values(array_unique(array_filter(array_map('intval', (array) ($args['include'] ?? [])), static function (int $id): bool {
                return $id > 0;
            })));
            sort($include);

            if ($include === $expected_ids) {
                return true;
            }
        }

        return false;
    }
}
