<?php
declare(strict_types=1);

final class WordImagesAdminCategoryCountsTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        if (function_exists('set_current_screen')) {
            set_current_screen('front');
        }

        parent::tearDown();
    }

    public function test_category_count_helper_uses_admin_visible_word_images_only(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        set_current_screen('edit-word_images');

        $cat_a = $this->createCategory('Word Image Count A');
        $cat_b = $this->createCategory('Word Image Count B');
        $empty = $this->createCategory('Word Image Count Empty');

        $this->createWordImage('Count Published A', [$cat_a], 'publish', $admin_id);
        $this->createWordImage('Count Draft A', [$cat_a], 'draft', $admin_id);
        $this->createWordImage('Count Private A', [$cat_a], 'private', $admin_id);
        $this->createWordImage('Count Trashed A', [$cat_a], 'trash', $admin_id);
        $this->createWordImage('Count Published B', [$cat_b], 'publish', $admin_id);
        $this->createWord('Count Word A', [$cat_a], $admin_id);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'COUNT(DISTINCT p.ID) AS image_count') !== false) {
                $queries[] = $query;
            }

            return $query;
        };

        add_filter('query', $capture);
        try {
            $counts = ll_tools_get_word_images_category_counts([$cat_a, $cat_b, $empty]);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame(3, (int) ($counts[$cat_a] ?? 0));
        $this->assertSame(1, (int) ($counts[$cat_b] ?? 0));
        $this->assertSame(0, (int) ($counts[$empty] ?? 0));
        $this->assertSame($this->legacyWordImageCategoryCount($cat_a), (int) ($counts[$cat_a] ?? 0));
        $this->assertSame($this->legacyWordImageCategoryCount($cat_b), (int) ($counts[$cat_b] ?? 0));
        $this->assertCount(1, $queries, 'Word Image category counts should come from one aggregate query.');
    }

    public function test_category_filter_dropdown_renders_counts_without_per_category_post_queries(): void
    {
        global $typenow;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        set_current_screen('edit-word_images');

        $cat_a = $this->createCategory('Word Image Filter A');
        $cat_b = $this->createCategory('Word Image Filter B');
        $empty = $this->createCategory('Word Image Filter Empty');

        $this->createWordImage('Filter Published A', [$cat_a], 'publish', $admin_id);
        $this->createWordImage('Filter Draft A', [$cat_a], 'draft', $admin_id);
        $this->createWordImage('Filter Private A', [$cat_a], 'private', $admin_id);
        $this->createWordImage('Filter Trashed A', [$cat_a], 'trash', $admin_id);
        $this->createWordImage('Filter Published B', [$cat_b], 'publish', $admin_id);
        $this->createWord('Filter Word A', [$cat_a], $admin_id);

        $previous_typenow = $typenow ?? null;
        $previous_get = $_GET;
        $typenow = 'word_images';
        $_GET['word_category_filter'] = (string) $cat_b;

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        $buffer_level = ob_get_level();
        ob_start();
        try {
            ll_add_word_images_category_filter();
            $html = (string) ob_get_clean();
        } finally {
            if (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            remove_filter('query', $capture);
            $_GET = $previous_get;
            $typenow = $previous_typenow;
        }

        $this->assertStringContainsString('id="word_category_filter"', $html);
        $this->assertMatchesRegularExpression('/Word Image Filter A [^<]* \(3\)/', $html);
        $this->assertMatchesRegularExpression('/Word Image Filter B [^<]* \(1\)/', $html);
        $this->assertMatchesRegularExpression('/Word Image Filter Empty [^<]* \(0\)/', $html);
        $this->assertMatchesRegularExpression('/value="' . preg_quote((string) $cat_b, '/') . '"\s+selected=/', $html);

        $aggregate_queries = array_values(array_filter($queries, static function (string $query): bool {
            return strpos($query, 'COUNT(DISTINCT p.ID) AS image_count') !== false;
        }));
        $this->assertCount(1, $aggregate_queries, 'The dropdown should load all category counts in one aggregate query.');

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('SQL_CALC_FOUND_ROWS', $query);
        }
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        return (int) $term['term_id'];
    }

    /**
     * @param int[] $category_ids
     */
    private function createWordImage(string $title, array $category_ids, string $status, int $author_id): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => $status,
            'post_title' => $title,
            'post_author' => $author_id,
        ]);

        wp_set_post_terms($post_id, array_map('intval', $category_ids), 'word-category', false);

        return (int) $post_id;
    }

    /**
     * @param int[] $category_ids
     */
    private function createWord(string $title, array $category_ids, int $author_id): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => $author_id,
        ]);

        wp_set_post_terms($post_id, array_map('intval', $category_ids), 'word-category', false);

        return (int) $post_id;
    }

    private function legacyWordImageCategoryCount(int $category_id): int
    {
        $query = new WP_Query([
            'post_type' => 'word_images',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'word-category',
                    'field' => 'term_id',
                    'terms' => $category_id,
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }
}
