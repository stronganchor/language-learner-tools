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
                delete_option(ll_tools_quiz_pages_catalog_option_name('latest', $scope_id));
                delete_option(ll_tools_quiz_pages_catalog_option_name('state', $scope_id));
                delete_option(ll_tools_quiz_pages_catalog_option_name('lock', $scope_id));
                wp_clear_scheduled_hook('ll_tools_quiz_pages_catalog_refresh_event', [$scope_id]);
            }
        }

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
            $this->assertStringNotContainsString('No quizzes found.', $html);
            $this->assertNotFalse(wp_next_scheduled('ll_tools_quiz_pages_catalog_refresh_event', [(string) $scope['id']]));

            $state = get_option(ll_tools_quiz_pages_catalog_option_name('state', (string) $scope['id']), []);
            $this->assertSame((string) $scope['cache_key'], (string) ($state['cache_key'] ?? ''));
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
            ll_tools_quiz_pages_catalog_refresh_event((string) $scope['id']);

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

    private function quizPageCategoryMetaKey(): string
    {
        return defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
            ? (string) LL_TOOLS_QUIZ_PAGE_CATEGORY_META
            : '_ll_tools_word_category_id';
    }
}
