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

    public function test_category_delete_lock_is_wordset_scoped_and_fenced(): void
    {
        $wordsetId = $this->createWordset('Serialized Category Deletion');
        $firstCategoryId = $this->createOwnedCategory($wordsetId, 'First Serialized Category');
        $secondCategoryId = $this->createOwnedCategory($wordsetId, 'Second Serialized Category');
        $this->assertSame(
            ll_tools_wordset_page_get_category_delete_lock_key($firstCategoryId, $wordsetId),
            ll_tools_wordset_page_get_category_delete_lock_key($secondCategoryId, $wordsetId)
        );

        $lease = ll_tools_wordset_page_acquire_category_delete_lock($firstCategoryId, $wordsetId);
        $this->assertNotEmpty($lease);
        $legacyBridgeValue = (string) get_option((string) $lease['legacy_key'], '');
        $this->assertMatchesRegularExpression('/^[1-9][0-9]*:v2:[A-Za-z0-9-]+$/', $legacyBridgeValue);
        $this->assertGreaterThan(0, (int) $legacyBridgeValue);
        $this->assertLessThanOrEqual(time(), (int) $legacyBridgeValue);
        $this->assertGreaterThan(time() - (5 * MINUTE_IN_SECONDS), (int) $legacyBridgeValue);
        $otherWordsetId = $this->createWordset('Independent Serialized Category Deletion');
        $otherWordsetCategoryId = $this->createOwnedCategory($otherWordsetId, 'Independent Serialized Category');
        $otherWordsetResult = ll_tools_wordset_page_run_category_delete_batch($otherWordsetCategoryId, $otherWordsetId);
        $this->assertIsArray($otherWordsetResult);
        $this->assertSame('complete', (string) ($otherWordsetResult['status'] ?? ''));
        $busyResult = ll_tools_wordset_page_run_category_delete_batch($secondCategoryId, $wordsetId);
        $this->assertWPError($busyResult);
        $this->assertSame('category_delete_busy', $busyResult->get_error_code());

        $successorValue = [
            'token' => 'successor-token',
            'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
        ];
        $this->assertTrue(ll_tools_wordset_page_replace_category_delete_lock_value(
            (string) $lease['key'],
            $lease['value'],
            $successorValue
        ));
        ll_tools_wordset_page_release_category_delete_lock($lease);
        $this->assertSame($successorValue, get_option((string) $lease['key']));
        delete_option((string) $lease['key']);
    }

    public function test_category_delete_takes_over_expired_legacy_lock(): void
    {
        $wordsetId = $this->createWordset('Expired Category Deletion Lock');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Expired Lock Category');
        $lockKey = ll_tools_wordset_page_get_category_delete_legacy_lock_key($categoryId, $wordsetId);
        $this->assertTrue(add_option($lockKey, time() - (6 * MINUTE_IN_SECONDS), '', false));

        $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);

        $this->assertIsArray($job);
        $this->assertSame('complete', (string) ($job['status'] ?? ''));
        $this->assertFalse((bool) term_exists($categoryId, 'word-category'));
        $this->assertFalse(get_option($lockKey, false));
    }

    public function test_category_delete_honors_an_active_previous_version_lock(): void
    {
        $wordsetId = $this->createWordset('Active Legacy Category Deletion Lock');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Active Legacy Lock Category');
        $lessonId = $this->createLesson($wordsetId, $categoryId);
        $legacyLockKey = ll_tools_wordset_page_get_category_delete_legacy_lock_key($categoryId, $wordsetId);
        $this->assertTrue(add_option($legacyLockKey, time(), '', false));

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            delete_option($legacyLockKey);
        }

        $this->assertWPError($result);
        $this->assertSame('category_delete_busy', $result->get_error_code());
        $this->assertInstanceOf(WP_Post::class, get_post($lessonId));
        $this->assertNotFalse(term_exists($categoryId, 'word-category'));
        $this->assertSame([], ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId));
    }

    public function test_category_delete_state_writes_lock_and_revalidate_the_lease_transactionally(): void
    {
        $wordsetId = $this->createWordset('Transactional Category State');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Transactional Category');
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertIsArray($job);
        $this->assertSame('complete', (string) ($job['status'] ?? ''));
        $sql = implode("\n", $queries);
        $this->assertStringContainsString('START TRANSACTION', $sql);
        $this->assertStringContainsString('SELECT option_value', $sql);
        $this->assertStringContainsString('FOR UPDATE', $sql);
        $this->assertStringContainsString('FORCE INDEX (option_name)', $sql);
        $this->assertStringContainsString('ORDER BY option_name ASC', $sql);
        $this->assertStringContainsString('COMMIT', $sql);
        $transactionStartIndex = null;
        $globalLockIndex = null;
        $leaseLockIndex = null;
        foreach ($queries as $index => $query) {
            if ($transactionStartIndex === null && trim($query) === 'START TRANSACTION') {
                $transactionStartIndex = $index;
                continue;
            }
            if ($transactionStartIndex === null || $index <= $transactionStartIndex) {
                continue;
            }
            if ($globalLockIndex === null && strpos($query, 'SELECT option_name, option_value') !== false) {
                $globalLockIndex = $index;
            }
            if ($leaseLockIndex === null && strpos($query, 'SELECT option_value FROM') !== false) {
                $leaseLockIndex = $index;
            }
        }
        $this->assertIsInt($globalLockIndex);
        $this->assertIsInt($leaseLockIndex);
        $this->assertLessThan($leaseLockIndex, $globalLockIndex);
    }

    public function test_current_wordset_leases_do_not_consume_the_legacy_scan_budget(): void
    {
        $wordsetId = $this->createWordset('Separated Wordset Lease Namespace');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Separated Lease Category');
        $optionNames = [];
        for ($index = 0; $index < 101; $index++) {
            $optionName = 'll_tools_category_delete_wordset_lock_fixture_' . $index;
            $optionNames[] = $optionName;
            add_option($optionName, [
                'token' => 'fixture-' . $index,
                'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
            ], '', false);
        }

        try {
            $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            foreach ($optionNames as $optionName) {
                delete_option($optionName);
            }
        }

        $this->assertIsArray($job);
        $this->assertSame('complete', (string) ($job['status'] ?? ''));
    }

    public function test_category_delete_fails_closed_when_a_state_lock_query_fails(): void
    {
        global $wpdb;

        $wordsetId = $this->createWordset('Failed State Lock Query');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Failed State Lock Category');
        $lessonId = $this->createLesson($wordsetId, $categoryId);
        $breakTermMetaLock = static function (string $query): string {
            if (
                strpos($query, 'SELECT meta_id FROM') !== false
                && strpos($query, 'll_wordset_category_delete_jobs') !== false
                && strpos($query, 'FOR UPDATE') !== false
            ) {
                return 'SELECT * FROM ll_tools_missing_lock_table';
            }
            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $breakTermMetaLock);
        try {
            $result = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            remove_filter('query', $breakTermMetaLock);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        $this->assertWPError($result);
        $this->assertSame('category_delete_state', $result->get_error_code());
        $this->assertInstanceOf(WP_Post::class, get_post($lessonId));
        $this->assertNotFalse(term_exists($categoryId, 'word-category'));
        $this->assertSame([], ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId));
    }

    public function test_category_delete_does_not_mutate_when_initial_state_cannot_be_saved(): void
    {
        $wordsetId = $this->createWordset('Category State Save Guard');
        $categoryId = $this->createOwnedCategory($wordsetId, 'State Save Guard Category');
        $lessonId = $this->createLesson($wordsetId, $categoryId);
        $failStateSave = static function ($check, int $objectId, string $metaKey) use ($wordsetId) {
            if ($objectId === $wordsetId && $metaKey === 'll_wordset_category_delete_jobs') {
                return false;
            }
            return $check;
        };

        add_filter('update_term_metadata', $failStateSave, 10, 3);
        try {
            $result = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            remove_filter('update_term_metadata', $failStateSave, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('category_delete_state', $result->get_error_code());
        $this->assertInstanceOf(WP_Post::class, get_post($lessonId));
        $this->assertNotFalse(term_exists($categoryId, 'word-category'));
        $this->assertSame([], ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId));
    }

    public function test_category_delete_reconciles_mutation_after_state_save_failure(): void
    {
        $wordsetId = $this->createWordset('Category State Reconciliation');
        $categoryId = $this->createOwnedCategory($wordsetId, 'State Reconciliation Category');
        $firstLessonId = $this->createLesson($wordsetId, $categoryId);
        $secondLessonId = $this->createLesson($wordsetId, $categoryId);
        $stateSaveCount = 0;
        $failSecondStateSave = static function ($check, int $objectId, string $metaKey) use ($wordsetId, &$stateSaveCount) {
            if ($objectId === $wordsetId && $metaKey === 'll_wordset_category_delete_jobs') {
                $stateSaveCount++;
                if ($stateSaveCount === 2) {
                    return false;
                }
            }
            return $check;
        };
        $batchSize = static function (): int {
            return 1;
        };

        add_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        add_filter('update_term_metadata', $failSecondStateSave, 10, 3);
        try {
            $failedResult = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            remove_filter('update_term_metadata', $failSecondStateSave, 10);
        }
        $this->assertWPError($failedResult);
        $this->assertSame('category_delete_state', $failedResult->get_error_code());
        $this->assertNull(get_post($firstLessonId));
        $this->assertInstanceOf(WP_Post::class, get_post($secondLessonId));
        $this->assertSame(0, (int) (ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId)['deleted_lesson_count'] ?? -1));

        try {
            $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
            $this->assertIsArray($job);
            $this->assertSame(2, (int) ($job['deleted_lesson_count'] ?? 0));
            for ($attempt = 0; $attempt < 5 && (string) ($job['status'] ?? '') !== 'complete'; $attempt++) {
                $job = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
                $this->assertIsArray($job);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        }

        $this->assertSame('complete', (string) ($job['status'] ?? ''));
        $this->assertNull(get_post($secondLessonId));
        $this->assertFalse((bool) term_exists($categoryId, 'word-category'));
    }

    public function test_category_delete_recovers_when_final_state_save_fails_after_term_deletion(): void
    {
        $wordsetId = $this->createWordset('Final Category State Recovery');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Final State Recovery Category');
        $stateSaveCount = 0;
        $failFinalStateSave = static function ($check, int $objectId, string $metaKey) use ($wordsetId, &$stateSaveCount) {
            if ($objectId === $wordsetId && $metaKey === 'll_wordset_category_delete_jobs') {
                $stateSaveCount++;
                if ($stateSaveCount === 4) {
                    return false;
                }
            }
            return $check;
        };
        add_filter('update_term_metadata', $failFinalStateSave, 10, 3);
        try {
            $failedResult = ll_tools_wordset_page_run_category_delete_batch($categoryId, $wordsetId);
        } finally {
            remove_filter('update_term_metadata', $failFinalStateSave, 10);
        }

        $this->assertIsArray($failedResult);
        $this->assertSame('complete', (string) ($failedResult['status'] ?? ''));
        $this->assertFalse((bool) term_exists($categoryId, 'word-category'));
        $storedJob = ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId);
        $this->assertSame('complete', (string) ($storedJob['status'] ?? ''));
        $this->assertSame('complete', (string) ($storedJob['phase'] ?? ''));
    }

    public function test_category_manager_can_finalize_a_missing_term_job(): void
    {
        $wordsetId = $this->createWordset('Missing Term Manager Recovery');
        $categoryId = $this->createOwnedCategory($wordsetId, 'Missing Term Manager Category');
        update_term_meta($wordsetId, 'll_wordset_category_delete_jobs', [
            $categoryId => [
                'version' => 2,
                'revision' => 1,
                'wordset_id' => $wordsetId,
                'category_id' => $categoryId,
                'category_name' => 'Missing Term Manager Category',
                'status' => 'running',
                'phase' => 'term',
                'lesson_total' => 0,
                'word_total' => 0,
                'deleted_lesson_count' => 0,
                'detached_word_count' => 0,
            ],
        ]);
        wp_delete_term($categoryId, 'word-category');

        $postBackup = $_POST;
        $_POST = [
            'll_wordset_categories_action' => 'delete',
            'll_wordset_category_id' => (string) $categoryId,
        ];
        try {
            $result = ll_tools_wordset_page_save_categories_settings($wordsetId);
        } finally {
            $_POST = $postBackup;
        }

        $this->assertIsArray($result);
        $this->assertSame('Category deleted.', (string) ($result['message'] ?? ''));
        $job = ll_tools_wordset_page_get_category_delete_job($categoryId, $wordsetId);
        $this->assertSame('complete', (string) ($job['status'] ?? ''));
        $this->assertSame('complete', (string) ($job['phase'] ?? ''));
    }

    public function test_interleaved_category_jobs_preserve_the_shared_wordset_job_map(): void
    {
        $wordsetId = $this->createWordset('Interleaved Category Jobs');
        $firstCategoryId = $this->createOwnedCategory($wordsetId, 'Interleaved First Category');
        $secondCategoryId = $this->createOwnedCategory($wordsetId, 'Interleaved Second Category');
        $this->createLesson($wordsetId, $firstCategoryId);
        $this->createLesson($wordsetId, $firstCategoryId);
        $this->createLesson($wordsetId, $secondCategoryId);
        $this->createLesson($wordsetId, $secondCategoryId);
        $batchSize = static function (): int {
            return 1;
        };
        add_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        try {
            $firstJob = ll_tools_wordset_page_run_category_delete_batch($firstCategoryId, $wordsetId);
            $this->assertIsArray($firstJob);
            $this->assertSame('running', (string) ($firstJob['status'] ?? ''));
            $secondJob = ll_tools_wordset_page_run_category_delete_batch($secondCategoryId, $wordsetId);
            $this->assertIsArray($secondJob);
            $this->assertSame('running', (string) ($secondJob['status'] ?? ''));
        } finally {
            remove_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        }

        $jobs = ll_tools_wordset_page_get_category_delete_jobs($wordsetId);
        $this->assertArrayHasKey($firstCategoryId, $jobs);
        $this->assertArrayHasKey($secondCategoryId, $jobs);
        $this->assertSame(1, (int) ($jobs[$firstCategoryId]['deleted_lesson_count'] ?? 0));
        $this->assertSame(1, (int) ($jobs[$secondCategoryId]['deleted_lesson_count'] ?? 0));
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
