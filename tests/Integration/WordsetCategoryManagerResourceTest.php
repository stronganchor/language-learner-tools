<?php
declare(strict_types=1);

final class WordsetCategoryManagerResourceTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_category_manager_pages_terms_and_aggregates_lesson_counts(): void
    {
        $wordsetId = $this->createWordset('Paged Category Manager');
        $categoryIds = [];
        for ($index = 1; $index <= 26; $index++) {
            $categoryIds[$index] = $this->createOwnedCategory(
                $wordsetId,
                sprintf('Paged Category %02d', $index)
            );
        }
        update_term_meta($categoryIds[2], 'term_translation', 'Translation Two');
        update_term_meta($categoryIds[24], 'term_translation', 'Translation Twenty Four');
        $this->createLesson($wordsetId, $categoryIds[13]);
        $this->createLesson($wordsetId, $categoryIds[13]);
        $this->createLesson($wordsetId, $categoryIds[14]);

        $capturedTermArgs = [];
        $lessonPostQueryLimits = [];
        $lessonAggregateQueries = [];
        $termWatcher = static function (array $args, array $taxonomies) use (&$capturedTermArgs): array {
            if (in_array('word-category', $taxonomies, true)) {
                $capturedTermArgs[] = $args;
            }
            return $args;
        };
        $postWatcher = static function (WP_Query $query) use (&$lessonPostQueryLimits): void {
            if ($query->get('post_type') === 'll_vocab_lesson') {
                $lessonPostQueryLimits[] = (int) $query->get('posts_per_page');
            }
        };
        $sqlWatcher = static function (string $query) use (&$lessonAggregateQueries): string {
            if (strpos($query, 'AS lesson_count') !== false && strpos($query, 'GROUP BY CAST(category_meta.meta_value AS UNSIGNED)') !== false) {
                $lessonAggregateQueries[] = $query;
            }
            return $query;
        };

        add_filter('get_terms_args', $termWatcher, 10, 2);
        add_action('pre_get_posts', $postWatcher);
        add_filter('query', $sqlWatcher);
        try {
            $page = ll_tools_wordset_page_get_managed_category_page($wordsetId, [], [
                'page' => 2,
                'per_page' => 10,
            ]);
        } finally {
            remove_filter('get_terms_args', $termWatcher, 10);
            remove_action('pre_get_posts', $postWatcher);
            remove_filter('query', $sqlWatcher);
        }

        $this->assertSame(26, (int) $page['summary']['total']);
        $this->assertSame(2, (int) $page['summary']['translated']);
        $this->assertSame(26, (int) $page['total']);
        $this->assertSame(2, (int) $page['page']);
        $this->assertSame(3, (int) $page['total_pages']);
        $this->assertSame(11, (int) $page['from']);
        $this->assertSame(20, (int) $page['to']);
        $this->assertCount(10, $page['rows']);

        $rowsById = [];
        foreach ($page['rows'] as $row) {
            $rowsById[(int) $row['id']] = $row;
        }
        $this->assertSame(2, (int) $rowsById[$categoryIds[13]]['lesson_count']);
        $this->assertSame(1, (int) $rowsById[$categoryIds[14]]['lesson_count']);
        $this->assertSame([], $lessonPostQueryLimits, 'Lesson counts must not hydrate every lesson post.');
        $this->assertCount(1, $lessonAggregateQueries, 'The visible category page should use one grouped lesson-count query.');

        $hydratedTermQueries = array_values(array_filter($capturedTermArgs, static function (array $args): bool {
            return (string) ($args['fields'] ?? 'all') !== 'count'
                && (int) ($args['number'] ?? 0) === 10
                && (int) ($args['offset'] ?? 0) === 10;
        }));
        $this->assertCount(1, $hydratedTermQueries);
        $this->assertSame(10, (int) ($hydratedTermQueries[0]['number'] ?? 0));
        $this->assertSame(10, (int) ($hydratedTermQueries[0]['offset'] ?? 0));

        $searchPage = ll_tools_wordset_page_get_managed_category_page($wordsetId, [], [
            'search' => 'Paged Category 26',
            'page' => 1,
            'per_page' => 10,
        ]);
        $this->assertSame(1, (int) $searchPage['total']);
        $this->assertCount(1, $searchPage['rows']);
        $this->assertSame($categoryIds[26], (int) $searchPage['rows'][0]['id']);

        $wordset = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $html = ll_tools_wordset_page_render_settings_categories_tool($wordset, $wordsetId, '', $page);
        $this->assertStringContainsString('Showing 11-20 of 26 categories.', $html);
        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringContainsString('name="ll_category_search"', $html);
        $this->assertStringContainsString('Paged Category 13', $html);
        $this->assertStringNotContainsString('Paged Category 26', $html);

        $pageSize = static function (): int {
            return 5;
        };
        add_filter('ll_tools_wordset_page_category_manager_page_size', $pageSize);
        try {
            $_GET = [
                'll_wordset_tool' => 'categories',
                'll_category_page' => '2',
            ];
            set_query_var('ll_wordset_page', (string) $wordset->slug);
            set_query_var('ll_wordset_view', 'settings');
            $fullHtml = ll_tools_render_wordset_page_content($wordsetId);
        } finally {
            remove_filter('ll_tools_wordset_page_category_manager_page_size', $pageSize);
        }
        $this->assertStringContainsString('Showing 6-10 of 26 categories.', $fullHtml);
        $this->assertStringContainsString('Paged Category 06', $fullHtml);
        $this->assertStringContainsString('Paged Category 10', $fullHtml);
        $this->assertStringNotContainsString('Paged Category 01', $fullHtml);
        $this->assertStringNotContainsString('Paged Category 11', $fullHtml);
    }

    public function test_category_deletion_is_bounded_durable_and_readable_until_complete(): void
    {
        $wordsetId = $this->createWordset('Bounded Category Deletion');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Delete in Batches');
        $otherCategoryId = $this->createOwnedCategory($wordsetId, 'Keep This Category');
        $lessonIds = [];
        $wordIds = [];
        for ($index = 0; $index < 3; $index++) {
            $lessonIds[] = $this->createLesson($wordsetId, $categoryId);
            $wordId = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Batch Word ' . $index,
            ]);
            wp_set_post_terms($wordId, [$categoryId, $otherCategoryId], 'word-category', false);
            wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
            $wordIds[] = $wordId;
        }

        $lockKey = ll_tools_wordset_page_get_category_delete_lock_key($categoryId, $wordsetId);
        $this->assertTrue(add_option($lockKey, time(), '', false));
        $busyResult = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        $this->assertWPError($busyResult);
        $this->assertSame('category_delete_busy', $busyResult->get_error_code());
        delete_option($lockKey);

        $queryLimits = [];
        $batchSize = static function (): int {
            return 2;
        };
        $queryWatcher = static function (WP_Query $query) use (&$queryLimits, $categoryId, $wordsetId): void {
            $postType = $query->get('post_type');
            if (
                (string) $query->get('fields') !== 'ids'
                || (string) $query->get('orderby') !== 'ID'
                || (string) $query->get('order') !== 'ASC'
            ) {
                return;
            }
            if ($postType === 'll_vocab_lesson') {
                $metaQuery = (array) $query->get('meta_query');
                foreach ($metaQuery as $clause) {
                    if (
                        is_array($clause)
                        && (string) ($clause['key'] ?? '') === LL_TOOLS_VOCAB_LESSON_CATEGORY_META
                        && in_array((string) $categoryId, array_map('strval', (array) ($clause['value'] ?? [])), true)
                    ) {
                        $queryLimits[] = (int) $query->get('posts_per_page');
                        return;
                    }
                }
            }
            if ($postType === 'words') {
                $taxQuery = (array) $query->get('tax_query');
                $hasCategory = false;
                $hasWordset = false;
                foreach ($taxQuery as $clause) {
                    if (!is_array($clause)) {
                        continue;
                    }
                    $termIds = array_map('intval', (array) ($clause['terms'] ?? []));
                    $hasCategory = $hasCategory || ((string) ($clause['taxonomy'] ?? '') === 'word-category' && in_array($categoryId, $termIds, true));
                    $hasWordset = $hasWordset || ((string) ($clause['taxonomy'] ?? '') === 'wordset' && in_array($wordsetId, $termIds, true));
                }
                if ($hasCategory && $hasWordset) {
                    $queryLimits[] = (int) $query->get('posts_per_page');
                }
            }
        };
        add_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        add_action('pre_get_posts', $queryWatcher);
        try {
            $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
            $this->assertIsArray($job);
            $this->assertSame('running', (string) $job['status']);
            $this->assertSame('lessons', (string) $job['phase']);
            $this->assertSame(2, (int) $job['deleted_lesson_count']);
            $this->assertSame(0, (int) $job['detached_word_count']);
            $this->assertSame($job, ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId));
            $this->assertNotFalse(term_exists($categoryId, 'word-category'));

            $page = ll_tools_wordset_page_get_managed_category_page($wordsetId, [], [
                'search' => 'Delete in Batches',
            ]);
            $wordset = get_term($wordsetId, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $wordset);
            $html = ll_tools_wordset_page_render_settings_categories_tool($wordset, $wordsetId, '', $page);
            $this->assertStringContainsString('Deletion in progress: 2 of 6 linked items processed.', $html);
            $this->assertStringContainsString('Continue Deletion', $html);

            for ($attempt = 0; $attempt < 10 && (string) ($job['status'] ?? '') !== 'complete'; $attempt++) {
                $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
                $this->assertIsArray($job);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
            remove_action('pre_get_posts', $queryWatcher);
        }

        $this->assertSame('complete', (string) $job['status']);
        $this->assertSame('complete', (string) $job['phase']);
        $this->assertSame(3, (int) $job['deleted_lesson_count']);
        $this->assertSame(3, (int) $job['detached_word_count']);
        $this->assertSame(6, ll_tools_wordset_page_get_category_delete_progress($job)['processed']);
        $this->assertSame($job, ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId));
        $this->assertFalse((bool) term_exists($categoryId, 'word-category'));
        foreach ($lessonIds as $lessonId) {
            $this->assertNull(get_post($lessonId));
        }
        foreach ($wordIds as $wordId) {
            $this->assertSame('publish', get_post_status($wordId));
            $categoryIds = wp_get_object_terms($wordId, 'word-category', ['fields' => 'ids']);
            $this->assertIsArray($categoryIds);
            $this->assertNotContains($categoryId, array_map('intval', $categoryIds));
            $this->assertContains($otherCategoryId, array_map('intval', $categoryIds));
        }
        $this->assertNotEmpty($queryLimits);
        foreach ($queryLimits as $queryLimit) {
            $this->assertGreaterThan(0, $queryLimit);
            $this->assertLessThanOrEqual(2, $queryLimit);
        }
    }

    private function createWordset(string $name): int
    {
        $created = wp_insert_term($name . ' ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function createOwnedCategory(int $wordsetId, string $name): int
    {
        $created = wp_insert_term($name . ' ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($created);
        $categoryId = (int) $created['term_id'];
        ll_tools_set_category_wordset_owner($categoryId, $wordsetId, $categoryId);
        return $categoryId;
    }

    private function createLesson(int $wordsetId, int $categoryId): int
    {
        $lessonId = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Resource Lesson ' . wp_generate_password(5, false),
        ]);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordsetId);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $categoryId);
        return $lessonId;
    }
}
