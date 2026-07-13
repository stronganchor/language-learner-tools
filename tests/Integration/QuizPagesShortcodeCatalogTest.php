<?php
declare(strict_types=1);

final class QuizPagesShortcodeCatalogTest extends LL_Tools_TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $catalogScopes = [];

    /** @var mixed */
    private $originalIsolationOption = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        foreach ($this->catalogScopes as $scope) {
            $scope_id = (string) ($scope['id'] ?? '');
            $cache_key = (string) ($scope['cache_key'] ?? '');
            if ($cache_key !== '') {
                wp_cache_delete($cache_key, 'll_tools_quiz_pages');
                delete_transient($cache_key);
            }
            if ($scope_id !== '') {
                ll_tools_quiz_pages_catalog_cleanup_snapshot_payload(
                    get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null)
                );
                ll_tools_quiz_pages_catalog_cleanup_build_state(
                    get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), null)
                );
                delete_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id));
                delete_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id));
                delete_option(ll_tools_quiz_pages_catalog_option_name('lock', $scope_id));
                wp_clear_scheduled_hook('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]);
            }
        }
        delete_option(ll_tools_quiz_pages_catalog_option_name('lock', md5('ll-tools-quiz-pages-catalog-global-refresh')));

        unset($GLOBALS['ll_tools_quiz_pages_catalog_status']);

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_anonymous_cold_catalog_read_queues_refresh_without_catalog_queries(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            for ($index = 0; $index < 8; $index++) {
                $this->createCatalogFixture('Cold Catalog ' . $index);
            }

            $scope = $this->trackAndClearScope([], 1);
            $quiz_queries = [];
            $term_queries = [];
            $capture_posts = $this->captureQuizPageQueries($quiz_queries);
            $capture_terms = static function (WP_Term_Query $query) use (&$term_queries): void {
                $taxonomies = array_map('strval', (array) ($query->query_vars['taxonomy'] ?? []));
                if (array_intersect($taxonomies, ['word-category', 'wordset'])) {
                    $term_queries[] = $query->query_vars;
                }
            };
            add_action('pre_get_posts', $capture_posts, 10, 1);
            add_action('pre_get_terms', $capture_terms, 10, 1);

            try {
                $items = ll_get_all_quiz_pages_data([]);
                $html = ll_quiz_pages_grid_shortcode([]);
            } finally {
                remove_action('pre_get_terms', $capture_terms, 10);
                remove_action('pre_get_posts', $capture_posts, 10);
            }

            $this->assertSame([], $items);
            $this->assertSame([], $quiz_queries, 'A cold public read must not query every quiz-page shell.');
            $this->assertSame([], $term_queries, 'A cold public read must not rebuild category or wordset metadata.');
            $this->assertStringContainsString('role="status"', $html);
            $this->assertStringContainsString('Loading quiz...', $html);
            $this->assertStringContainsString('Refresh', $html);
            $this->assertStringContainsString('data-ll-quiz-catalog-status="1"', $html);
            $this->assertStringContainsString('data-scope-id="' . esc_attr((string) $scope['id']) . '"', $html);
            $this->assertStringContainsString('data-max-attempts="120"', $html);
            $this->assertStringContainsString('admin-ajax.php', $html);
            $this->assertTrue(wp_script_is('ll-quiz-pages-shortcodes-js', 'enqueued'));
            $this->assertStringNotContainsString('No quizzes found.', $html);
            $this->assertNotFalse(wp_next_scheduled('ll_tools_quiz_pages_catalog_refresh_event', [(string) $scope['id']]));

            $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', (string) $scope['id']), []);
            $this->assertSame((string) $scope['cache_key'], (string) ($state['cache_key'] ?? ''));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_public_warmup_request_materializes_a_cold_catalog_for_reload(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Warmup Catalog');
            $opts = ['wordset' => $fixture['wordset_slug']];
            $scope = $this->trackAndClearScope($opts, 1);

            $this->assertSame([], ll_get_all_quiz_pages_data($opts));
            $this->assertFalse(ll_tools_quiz_pages_catalog_snapshot_ready((string) $scope['id']));

            $status = $this->runWarmupUntilReady((string) $scope['id']);
            $this->assertIsArray($status);
            $this->assertTrue((bool) ($status['ready'] ?? false));
            $this->assertTrue(ll_tools_quiz_pages_catalog_snapshot_ready((string) $scope['id']));
            $this->assertSame([], get_option(ll_tools_quiz_pages_catalog_option_name('state', (string) $scope['id']), []));

            $items = ll_get_all_quiz_pages_data($opts);
            $this->assertCount(1, $items);
            $this->assertSame($fixture['page_id'], (int) ($items[0]['post_id'] ?? 0));

            $invalid = ll_tools_quiz_pages_catalog_warmup_status('not-a-scope');
            $this->assertWPError($invalid);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_refresh_materializes_existing_rows_and_preserves_metadata_and_links(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Materialized Catalog', true);
            $this->createEmptyQuizShell('Ineligible Catalog');
            $opts = ['wordset' => $fixture['wordset_slug']];
            $scope = $this->trackAndClearScope($opts, 1);

            $this->assertSame([], ll_get_all_quiz_pages_data($opts));
            $this->runWarmupUntilReady((string) $scope['id']);

            $items = ll_get_all_quiz_pages_data($opts);
            $this->assertCount(1, $items);
            $item = $items[0];
            $this->assertSame($fixture['page_id'], (int) ($item['post_id'] ?? 0));
            $this->assertSame($fixture['category_id'], (int) ($item['term_id'] ?? 0));
            $this->assertSame($fixture['wordset_id'], (int) ($item['wordset_id'] ?? 0));
            $this->assertSame($fixture['wordset_slug'], (string) ($item['wordset_slug'] ?? ''));
            $this->assertSame('text_translation', (string) ($item['option_type'] ?? ''));
            $this->assertSame('text_title', (string) ($item['prompt_type'] ?? ''));
            $this->assertTrue((bool) ($item['gender_enabled'] ?? false));
            $this->assertSame(['Masculine', 'Feminine'], array_values((array) ($item['gender_options'] ?? [])));
            $this->assertTrue((bool) ($item['gender_supported'] ?? false));

            $grid_html = ll_quiz_pages_grid_shortcode([
                'wordset' => $fixture['wordset_slug'],
                'popup' => 'no',
            ]);
            $this->assertStringContainsString('href="' . esc_url((string) $item['permalink']) . '"', $grid_html);
            $this->assertStringContainsString(esc_html((string) $item['display_name']), $grid_html);
            $this->assertStringNotContainsString('Loading quiz...', $grid_html);

            $dropdown_html = ll_quiz_pages_dropdown_shortcode([
                'wordset' => $fixture['wordset_slug'],
            ]);
            $this->assertStringContainsString('<option value="' . esc_url((string) $item['permalink']) . '">', $dropdown_html);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_completed_refresh_replaces_the_durable_snapshot_under_the_same_epoch(): void
    {
        $scope = $this->trackAndClearScope([], LL_TOOLS_MIN_WORDS_PER_QUIZ);
        ll_tools_quiz_pages_catalog_latest_set($scope, [['post_id' => 101]]);
        $this->assertSame(101, (int) (ll_tools_quiz_pages_catalog_latest_get($scope)[0]['post_id'] ?? 0));

        ll_tools_quiz_pages_catalog_latest_set($scope, [['post_id' => 202]], true);
        $this->assertSame(202, (int) (ll_tools_quiz_pages_catalog_latest_get($scope)[0]['post_id'] ?? 0));
    }

    public function test_missing_manifest_chunk_is_not_ready_and_is_rebuilt(): void
    {
        $min_words_filter = static fn (): int => 1;
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Corrupt Manifest Catalog');
            $scope = $this->trackAndClearScope([], 1);
            $scope_id = (string) $scope['id'];
            $cache_key = (string) $scope['cache_key'];

            $this->assertSame([], ll_get_all_quiz_pages_data([]));
            $this->assertTrue((bool) ($this->runWarmupUntilReady($scope_id)['ready'] ?? false));
            $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), []);
            $chunk_option = (string) ($latest['chunks'][0] ?? '');
            $this->assertNotSame('', $chunk_option);
            delete_option($chunk_option);
            wp_cache_delete($cache_key, 'll_tools_quiz_pages');
            delete_transient($cache_key);

            $readiness_queries = [];
            $capture_readiness = static function (string $query) use (&$readiness_queries): string {
                if (strpos($query, 'll_tools_quiz_pages_catalog_chunk_ready') !== false) {
                    $readiness_queries[] = $query;
                }
                return $query;
            };
            add_filter('query', $capture_readiness, 10, 1);
            try {
                $this->assertFalse(ll_tools_quiz_pages_catalog_snapshot_ready($scope_id));
            } finally {
                remove_filter('query', $capture_readiness, 10);
            }
            $this->assertCount(1, $readiness_queries);
            $this->assertSame([], ll_get_all_quiz_pages_data([]));
            $this->assertNotFalse(wp_next_scheduled('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]));

            $status = $this->runWarmupUntilReady($scope_id);
            $this->assertTrue((bool) ($status['ready'] ?? false));
            $items = ll_tools_quiz_pages_catalog_latest_get($scope);
            $this->assertCount(1, $items);
            $this->assertSame($fixture['page_id'], (int) ($items[0]['post_id'] ?? 0));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_refresh_uses_bounded_chunks_and_publishes_only_after_completion(): void
    {
        $min_words_filter = static fn (): int => 1;
        $batch_filter = static fn (): int => 2;
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        add_filter('ll_tools_quiz_pages_catalog_rebuild_batch_size', $batch_filter);

        try {
            $expected_page_ids = [];
            for ($index = 0; $index < 5; $index++) {
                $fixture = $this->createCatalogFixture('Bounded Catalog ' . $index);
                $expected_page_ids[] = (int) $fixture['page_id'];
            }
            $scope = $this->trackAndClearScope([], 1);
            $scope_id = (string) $scope['id'];
            $captured_queries = [];
            $capture_query = static function (string $query) use (&$captured_queries): string {
                if (strpos($query, 'll_tools_quiz_pages_catalog_batch') !== false) {
                    $captured_queries[] = $query;
                }
                return $query;
            };
            add_filter('query', $capture_query, 10, 1);

            try {
                $this->assertSame([], ll_get_all_quiz_pages_data([]));
                ll_tools_quiz_pages_catalog_refresh_event($scope_id);

                $this->assertFalse(get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), false));
                $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), []);
                $this->assertSame(2, (int) ($state['processed'] ?? 0));
                $this->assertCount(1, (array) ($state['chunks'] ?? []));
                $first_chunk = get_option((string) ($state['chunks'][0] ?? ''), []);
                $this->assertLessThanOrEqual(2, count((array) ($first_chunk['items'] ?? [])));

                $status = $this->runWarmupUntilReady($scope_id, 12);
            } finally {
                remove_filter('query', $capture_query, 10);
            }

            $this->assertIsArray($status);
            $this->assertTrue((bool) ($status['ready'] ?? false));
            $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), []);
            $this->assertNotEmpty($latest['__ll_quiz_pages_catalog_manifest']);
            $this->assertSame(5, (int) ($latest['item_count'] ?? 0));
            $this->assertGreaterThanOrEqual(3, count((array) ($latest['chunks'] ?? [])));
            foreach ((array) ($latest['chunks'] ?? []) as $chunk_option) {
                $chunk = get_option((string) $chunk_option, []);
                $this->assertLessThanOrEqual(2, count((array) ($chunk['items'] ?? [])));
            }

            $this->assertTrue(ll_tools_quiz_pages_catalog_latest_set_manifest(
                $scope,
                (string) ($latest['generation'] ?? ''),
                (array) ($latest['chunks'] ?? []),
                (int) ($latest['item_count'] ?? 0)
            ));
            $this->assertCount(5, ll_tools_quiz_pages_catalog_latest_get($scope));

            $items = ll_tools_quiz_pages_catalog_latest_get($scope);
            $this->assertIsArray($items);
            $actual_page_ids = array_map(static fn (array $item): int => (int) ($item['post_id'] ?? 0), $items);
            $this->assertSame($expected_page_ids, $actual_page_ids);
            $this->assertNotEmpty($captured_queries);
            foreach ($captured_queries as $query) {
                $this->assertMatchesRegularExpression('/LIMIT\s+3\b/i', $query);
            }
        } finally {
            remove_filter('ll_tools_quiz_pages_catalog_rebuild_batch_size', $batch_filter);
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_interrupted_manifest_replacement_retains_and_cleans_old_chunks_on_retry(): void
    {
        $scope = $this->trackAndClearScope([], LL_TOOLS_MIN_WORDS_PER_QUIZ);
        $scope_id = (string) $scope['id'];
        $latest_option = ll_tools_quiz_pages_catalog_option_name('latest', $scope_id);
        $old_generation = 'aaaaaaaaaaaa';
        $new_generation = 'bbbbbbbbbbbb';
        $old_chunk = ll_tools_quiz_pages_catalog_chunk_option_name($scope_id, $old_generation, 0);
        $new_chunk = ll_tools_quiz_pages_catalog_chunk_option_name($scope_id, $new_generation, 0);
        update_option($old_chunk, [
            '__ll_quiz_pages_catalog_chunk' => 1,
            'generation' => $old_generation,
            'cache_key' => (string) $scope['cache_key'],
            'items' => [['post_id' => 101, 'display_name' => 'Old']],
        ], false);
        update_option($new_chunk, [
            '__ll_quiz_pages_catalog_chunk' => 1,
            'generation' => $new_generation,
            'cache_key' => (string) $scope['cache_key'],
            'items' => [['post_id' => 202, 'display_name' => 'New']],
        ], false);
        $this->assertTrue(ll_tools_quiz_pages_catalog_latest_set_manifest($scope, $old_generation, [$old_chunk], 1));

        $interrupted = false;
        $interrupt_after_pointer = static function (string $option, $old_value, $value) use (
            $latest_option,
            &$interrupted
        ): void {
            if ($option === $latest_option && !$interrupted && !empty($value['retired_chunks'])) {
                $interrupted = true;
                throw new RuntimeException('Simulated manifest publication interruption.');
            }
        };
        add_action('updated_option', $interrupt_after_pointer, 10, 3);
        try {
            ll_tools_quiz_pages_catalog_latest_set_manifest($scope, $new_generation, [$new_chunk], 1);
            $this->fail('Expected the simulated publication interruption.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated manifest publication interruption.', $error->getMessage());
        } finally {
            remove_action('updated_option', $interrupt_after_pointer, 10);
        }

        $interrupted_payload = get_option($latest_option, []);
        $this->assertTrue($interrupted);
        $this->assertContains($old_chunk, (array) ($interrupted_payload['retired_chunks'] ?? []));
        $this->assertNotFalse(get_option($old_chunk, false));

        $this->assertTrue(ll_tools_quiz_pages_catalog_latest_set_manifest($scope, $new_generation, [$new_chunk], 1));
        $this->assertFalse(get_option($old_chunk, false));
        $this->assertNotFalse(get_option($new_chunk, false));
        $final_payload = get_option($latest_option, []);
        $this->assertArrayNotHasKey('retired_chunks', $final_payload);
        $this->assertSame(202, (int) (ll_tools_quiz_pages_catalog_latest_get($scope)[0]['post_id'] ?? 0));
    }

    public function test_refresh_prefers_one_current_shell_over_current_and_legacy_duplicates(): void
    {
        $min_words_filter = static fn (): int => 1;
        $batch_filter = static fn (): int => 1;
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        add_filter('ll_tools_quiz_pages_catalog_rebuild_batch_size', $batch_filter);

        try {
            $fixture = $this->createCatalogFixture('Duplicate Shell Catalog');
            $current_post_type = defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE')
                ? (string) LL_TOOLS_QUIZ_PAGE_POST_TYPE
                : 'page';
            $this->assertNotSame('page', $current_post_type);

            foreach ([$current_post_type, 'page'] as $post_type) {
                $duplicate_id = self::factory()->post->create([
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'post_title' => 'Duplicate Quiz Shell',
                ]);
                $this->assertIsInt($duplicate_id);
                update_post_meta($duplicate_id, $this->quizPageCategoryMetaKey(), (string) $fixture['category_id']);
            }

            $scope = $this->trackAndClearScope([], 1);
            $scope_id = (string) $scope['id'];
            $captured_queries = [];
            $capture_query = static function (string $query) use (&$captured_queries): string {
                if (strpos($query, 'll_tools_quiz_pages_catalog_batch') !== false) {
                    $captured_queries[] = $query;
                }
                return $query;
            };
            add_filter('query', $capture_query, 10, 1);
            try {
                $this->assertSame([], ll_get_all_quiz_pages_data([]));
                $status = $this->runWarmupUntilReady($scope_id);
            } finally {
                remove_filter('query', $capture_query, 10);
            }

            $this->assertTrue((bool) ($status['ready'] ?? false));
            $items = ll_tools_quiz_pages_catalog_latest_get($scope);
            $this->assertCount(1, $items);
            $this->assertSame($fixture['page_id'], (int) ($items[0]['post_id'] ?? 0));
            $this->assertGreaterThanOrEqual(2, count($captured_queries));
            foreach ($captured_queries as $query) {
                $this->assertMatchesRegularExpression('/LIMIT\s+2\b/i', $query);
            }
        } finally {
            remove_filter('ll_tools_quiz_pages_catalog_rebuild_batch_size', $batch_filter);
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_worker_that_loses_its_lock_does_not_persist_progress(): void
    {
        $min_words_filter = static fn (): int => 1;
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Lock Takeover Catalog');
            $scope = $this->trackAndClearScope([], 1);
            $scope_id = (string) $scope['id'];
            $this->assertSame([], ll_get_all_quiz_pages_data([]));
            $initial_state = get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), []);

            $replaced_lock = false;
            $replacement_scope = null;
            $replacement_generation = '';
            $replace_lock = static function (string $query) use (
                $fixture,
                $scope_id,
                &$replaced_lock,
                &$replacement_scope,
                &$replacement_generation
            ): string {
                if (!$replaced_lock && strpos($query, 'll_tools_quiz_pages_catalog_batch') !== false) {
                    $replaced_lock = true;
                    ll_tools_bump_category_cache_epoch([(int) $fixture['wordset_id']]);
                    $replacement_scope = ll_tools_quiz_pages_catalog_scope([], 1);
                    $replacement_state = ll_tools_quiz_pages_catalog_new_build_state($replacement_scope);
                    $replacement_generation = (string) $replacement_state['generation'];
                    update_option(
                        ll_tools_quiz_pages_catalog_option_name('state', $scope_id),
                        $replacement_state,
                        false
                    );
                    update_option(ll_tools_quiz_pages_catalog_option_name('lock', $scope_id), [
                        'token' => 'replacement-worker',
                        'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
                    ], false);
                }
                return $query;
            };
            add_filter('query', $replace_lock, 10, 1);
            try {
                ll_tools_quiz_pages_catalog_refresh_event($scope_id);
            } finally {
                remove_filter('query', $replace_lock, 10);
            }

            $this->assertTrue($replaced_lock);
            $this->assertIsArray($replacement_scope);
            $this->assertNotSame((string) ($initial_state['cache_key'] ?? ''), (string) $replacement_scope['cache_key']);
            $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), []);
            $this->assertSame($replacement_generation, (string) ($state['generation'] ?? ''));
            $this->assertSame((string) $replacement_scope['cache_key'], (string) ($state['cache_key'] ?? ''));
            $this->assertSame(0, (int) ($state['processed'] ?? -1));
            $this->assertSame([], (array) ($state['chunks'] ?? []));
            $this->assertFalse(get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), false));
            $this->assertNotFalse(wp_next_scheduled('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_stale_snapshot_is_served_without_rebuild_during_lock_contention(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Stale Catalog');
            $opts = ['wordset' => $fixture['wordset_slug']];
            $old_scope = $this->trackAndClearScope($opts, 1);
            $built_items = ll_tools_quiz_pages_rebuild_catalog_data($opts);
            $this->assertCount(1, $built_items);
            ll_tools_quiz_pages_catalog_latest_set($old_scope, $built_items);

            ll_tools_bump_category_cache_epoch([$fixture['wordset_id']]);
            $new_scope = $this->trackAndClearCurrentCacheOnly($opts, 1);
            $this->assertSame((string) $old_scope['id'], (string) $new_scope['id']);
            $this->assertNotSame((string) $old_scope['cache_key'], (string) $new_scope['cache_key']);

            $quiz_queries = [];
            $capture_posts = $this->captureQuizPageQueries($quiz_queries);
            add_action('pre_get_posts', $capture_posts, 10, 1);
            try {
                $stale_items = ll_get_all_quiz_pages_data($opts);
                $token = ll_tools_quiz_pages_catalog_lock_acquire((string) $new_scope['id']);
                $this->assertNotSame('', $token);
                try {
                    ll_tools_quiz_pages_catalog_refresh_event((string) $new_scope['id']);
                } finally {
                    ll_tools_quiz_pages_catalog_lock_release((string) $new_scope['id'], $token);
                }
            } finally {
                remove_action('pre_get_posts', $capture_posts, 10);
            }

            $this->assertCount(1, $stale_items);
            $this->assertSame($fixture['page_id'], (int) ($stale_items[0]['post_id'] ?? 0));
            $this->assertSame([], $quiz_queries, 'Concurrent stale readers and a contended worker must not rebuild the catalog.');
            $this->assertNotFalse(wp_next_scheduled('ll_tools_quiz_pages_catalog_refresh_event', [(string) $new_scope['id']]));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_explicit_scope_rejects_a_stale_snapshot_with_an_obsolete_wordset_id(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createCatalogFixture('Obsolete Wordset Catalog');
            $opts = ['wordset' => $fixture['wordset_slug']];
            $old_scope = $this->trackAndClearScope($opts, 1);
            $stale_items = ll_tools_quiz_pages_rebuild_catalog_data($opts);
            $this->assertCount(1, $stale_items);
            $stale_items[0]['wordset_id'] = $fixture['wordset_id'] + 1000000;
            ll_tools_quiz_pages_catalog_latest_set($old_scope, $stale_items, true);

            ll_tools_bump_category_cache_epoch([$fixture['wordset_id']]);
            $current_scope = $this->trackAndClearCurrentCacheOnly($opts, 1);
            $this->assertSame((string) $old_scope['id'], (string) $current_scope['id']);

            $this->assertSame([], ll_get_all_quiz_pages_data($opts));
            $this->assertTrue(ll_tools_quiz_pages_catalog_needs_loading_notice());
            $this->assertStringContainsString('data-ll-quiz-catalog-status="1"', ll_tools_quiz_pages_catalog_loading_notice());
            $this->assertFalse(ll_tools_quiz_pages_catalog_snapshot_ready((string) $current_scope['id']));

            $status = $this->runWarmupUntilReady((string) $current_scope['id']);
            $this->assertIsArray($status);
            $this->assertTrue((bool) ($status['ready'] ?? false));
            $this->assertTrue(ll_tools_quiz_pages_catalog_snapshot_ready((string) $current_scope['id']));

            $latest = get_option(ll_tools_quiz_pages_catalog_option_name('latest', (string) $current_scope['id']), []);
            $this->assertSame((string) $current_scope['cache_key'], (string) ($latest['cache_key'] ?? ''));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    /**
     * @return array{wordset_id:int,wordset_slug:string,category_id:int,page_id:int}
     */
    private function createCatalogFixture(string $base_name, bool $with_gender = false): array
    {
        $wordset = wp_insert_term($base_name . ' Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term($base_name . ' Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        if ($with_gender) {
            update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
            update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        }

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $base_name . ' Word',
        ]);
        $this->assertIsInt($word_id);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', $base_name . ' Translation');

        if ($with_gender) {
            $noun = term_exists('noun', 'part_of_speech');
            if (!$noun) {
                $noun = wp_insert_term('Noun', 'part_of_speech', ['slug' => 'noun']);
            }
            $noun_id = is_array($noun) ? (int) ($noun['term_id'] ?? 0) : (int) $noun;
            $this->assertGreaterThan(0, $noun_id);
            wp_set_post_terms($word_id, [$noun_id], 'part_of_speech', false);
            update_post_meta($word_id, 'll_grammatical_gender', 'Masculine');
        }

        $post_type = defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE') && post_type_exists(LL_TOOLS_QUIZ_PAGE_POST_TYPE)
            ? LL_TOOLS_QUIZ_PAGE_POST_TYPE
            : 'page';
        $page_id = self::factory()->post->create([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'post_title' => $base_name . ' Quiz',
        ]);
        $this->assertIsInt($page_id);
        update_post_meta($page_id, $this->quizPageCategoryMetaKey(), (string) $category_id);

        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        return [
            'wordset_id' => $wordset_id,
            'wordset_slug' => (string) $wordset_term->slug,
            'category_id' => $category_id,
            'page_id' => (int) $page_id,
        ];
    }

    private function createEmptyQuizShell(string $base_name): void
    {
        $category = wp_insert_term($base_name . ' ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($category);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $post_type = defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE') && post_type_exists(LL_TOOLS_QUIZ_PAGE_POST_TYPE)
            ? LL_TOOLS_QUIZ_PAGE_POST_TYPE
            : 'page';
        $page_id = self::factory()->post->create([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'post_title' => $base_name . ' Quiz',
        ]);
        $this->assertIsInt($page_id);
        update_post_meta($page_id, $this->quizPageCategoryMetaKey(), (string) $category_id);
    }

    private function trackAndClearScope(array $opts, int $min_word_count): array
    {
        $scope = ll_tools_quiz_pages_catalog_scope($opts, $min_word_count);
        $this->catalogScopes[] = $scope;
        $scope_id = (string) $scope['id'];
        $cache_key = (string) $scope['cache_key'];

        wp_cache_delete($cache_key, 'll_tools_quiz_pages');
        delete_transient($cache_key);
        ll_tools_quiz_pages_catalog_cleanup_snapshot_payload(
            get_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id), null)
        );
        ll_tools_quiz_pages_catalog_cleanup_build_state(
            get_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id), null)
        );
        delete_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id));
        delete_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id));
        delete_option(ll_tools_quiz_pages_catalog_option_name('lock', $scope_id));
        wp_clear_scheduled_hook('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]);

        return $scope;
    }

    private function trackAndClearCurrentCacheOnly(array $opts, int $min_word_count): array
    {
        $scope = ll_tools_quiz_pages_catalog_scope($opts, $min_word_count);
        $this->catalogScopes[] = $scope;
        $cache_key = (string) $scope['cache_key'];
        wp_cache_delete($cache_key, 'll_tools_quiz_pages');
        delete_transient($cache_key);

        return $scope;
    }

    private function captureQuizPageQueries(array &$captured_queries): callable
    {
        $quiz_page_post_types = function_exists('ll_tools_get_quiz_page_post_types')
            ? ll_tools_get_quiz_page_post_types(true)
            : ['page'];

        return static function (WP_Query $query) use (&$captured_queries, $quiz_page_post_types): void {
            $post_types = array_map('strval', (array) $query->get('post_type'));
            if (array_intersect($post_types, $quiz_page_post_types)) {
                $captured_queries[] = $query->query_vars;
            }
        };
    }

    private function runWarmupUntilReady(string $scope_id, int $max_steps = 8)
    {
        $status = null;
        for ($step = 0; $step < $max_steps; $step++) {
            $status = ll_tools_quiz_pages_catalog_warmup_status($scope_id);
            if (is_wp_error($status) || !empty($status['ready'])) {
                return $status;
            }
        }

        return $status;
    }

    private function quizPageCategoryMetaKey(): string
    {
        return defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
            ? (string) LL_TOOLS_QUIZ_PAGE_CATEGORY_META
            : '_ll_tools_word_category_id';
    }
}
