<?php
declare(strict_types=1);

final class QuizPagesShortcodeWordsetQueryTest extends LL_Tools_TestCase
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

    public function test_wordset_scoped_quiz_pages_query_is_bounded_to_wordset_categories(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset_a_id = $this->createTerm('Quiz Shell Wordset A', 'wordset');
            $wordset_b_id = $this->createTerm('Quiz Shell Wordset B', 'wordset');
            $category_a_id = $this->createQuizzableCategory('Quiz Shell Category A');
            $category_b_id = $this->createQuizzableCategory('Quiz Shell Category B');
            $page_a_id = $this->createQuizShellForCategory($category_a_id, 'Quiz Shell Page A');
            $page_b_id = $this->createQuizShellForCategory($category_b_id, 'Quiz Shell Page B');

            $this->createWord($wordset_a_id, $category_a_id, 'Quiz Shell Word A');
            $this->createWord($wordset_b_id, $category_b_id, 'Quiz Shell Word B');

            $wordset_a = get_term($wordset_a_id, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $wordset_a);

            $captured_queries = [];
            $touched_page_ids = [];
            $capture_query = $this->captureQuizPageShellQueries($captured_queries);
            $capture_meta = $this->captureQuizPageCategoryMetaReads($touched_page_ids);
            add_action('pre_get_posts', $capture_query, 10, 1);
            add_filter('get_post_metadata', $capture_meta, 10, 4);

            try {
                $items = ll_get_all_quiz_pages_data([
                    'wordset' => $wordset_a->slug,
                ]);
            } finally {
                remove_filter('get_post_metadata', $capture_meta, 10);
                remove_action('pre_get_posts', $capture_query, 10);
            }

            $this->assertSame([$page_a_id], array_values(array_map(static function (array $item): int {
                return (int) ($item['post_id'] ?? 0);
            }, $items)));

            $query_vars = $this->findCapturedCategoryMetaQuery($captured_queries);
            $queried_category_ids = $this->extractCategoryMetaQueryValues($query_vars);

            $this->assertSame('', (string) ($query_vars['meta_key'] ?? ''));
            $this->assertContains($category_a_id, $queried_category_ids);
            $this->assertNotContains($category_b_id, $queried_category_ids);
            $this->assertArrayHasKey($page_a_id, $touched_page_ids);
            $this->assertArrayNotHasKey($page_b_id, $touched_page_ids);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_wordset_scoped_quiz_pages_query_preserves_source_shell_for_isolated_category(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        try {
            $this->assertTrue(function_exists('ll_tools_get_or_create_isolated_category_copy'));

            $wordset_id = $this->createTerm('Quiz Shell Isolated Wordset', 'wordset');
            $source_category_id = $this->createQuizzableCategory('Quiz Shell Source Category');
            $isolated_category_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_id);
            $this->assertGreaterThan(0, $isolated_category_id);
            $this->assertNotSame($source_category_id, $isolated_category_id);
            $this->setCategoryQuizMode($isolated_category_id);

            $page_id = $this->createQuizShellForCategory($source_category_id, 'Quiz Shell Source Page');
            $this->createWord($wordset_id, $isolated_category_id, 'Quiz Shell Isolated Word');

            $wordset = get_term($wordset_id, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $wordset);

            $captured_queries = [];
            $capture_query = $this->captureQuizPageShellQueries($captured_queries);
            add_action('pre_get_posts', $capture_query, 10, 1);

            try {
                $items = ll_get_all_quiz_pages_data([
                    'wordset' => $wordset->slug,
                ]);
            } finally {
                remove_action('pre_get_posts', $capture_query, 10);
            }

            $this->assertCount(1, $items);
            $this->assertSame($page_id, (int) ($items[0]['post_id'] ?? 0));
            $this->assertSame($isolated_category_id, (int) ($items[0]['term_id'] ?? 0));

            $query_vars = $this->findCapturedCategoryMetaQuery($captured_queries);
            $queried_category_ids = $this->extractCategoryMetaQueryValues($query_vars);

            $this->assertContains($source_category_id, $queried_category_ids);
            $this->assertContains($isolated_category_id, $queried_category_ids);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    private function createTerm(string $name, string $taxonomy): int
    {
        $created = wp_insert_term($name . ' ' . wp_generate_password(6, false), $taxonomy);
        $this->assertIsArray($created);

        $term_id = (int) ($created['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);

        return $term_id;
    }

    private function createQuizzableCategory(string $name): int
    {
        $term_id = $this->createTerm($name, 'word-category');
        $this->setCategoryQuizMode($term_id);

        return $term_id;
    }

    private function setCategoryQuizMode(int $category_id): void
    {
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
    }

    private function createQuizShellForCategory(int $category_id, string $title): int
    {
        $post_type = defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE') && post_type_exists(LL_TOOLS_QUIZ_PAGE_POST_TYPE)
            ? LL_TOOLS_QUIZ_PAGE_POST_TYPE
            : 'page';

        $post_id = self::factory()->post->create([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(6, false),
        ]);
        $this->assertIsInt($post_id);
        $this->assertGreaterThan(0, $post_id);

        update_post_meta($post_id, $this->quizPageCategoryMetaKey(), (string) $category_id);

        return (int) $post_id;
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

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $title . ' Translation');

        return (int) $word_id;
    }

    private function captureQuizPageShellQueries(array &$captured_queries): callable
    {
        $quiz_page_post_types = function_exists('ll_tools_get_quiz_page_post_types')
            ? ll_tools_get_quiz_page_post_types(true)
            : ['page'];
        $quiz_page_category_meta = $this->quizPageCategoryMetaKey();

        return static function (WP_Query $query) use (&$captured_queries, $quiz_page_post_types, $quiz_page_category_meta): void {
            $query_post_types = array_values(array_filter(array_map('strval', (array) $query->get('post_type'))));
            if (empty(array_intersect($query_post_types, $quiz_page_post_types))) {
                return;
            }

            if ($query->get('fields') !== 'ids') {
                return;
            }

            $has_category_meta_constraint = (string) $query->get('meta_key') === $quiz_page_category_meta;
            foreach ((array) $query->get('meta_query') as $clause) {
                if (is_array($clause) && (string) ($clause['key'] ?? '') === $quiz_page_category_meta) {
                    $has_category_meta_constraint = true;
                    break;
                }
            }

            if ($has_category_meta_constraint) {
                $captured_queries[] = $query->query_vars;
            }
        };
    }

    private function captureQuizPageCategoryMetaReads(array &$touched_page_ids): callable
    {
        $quiz_page_category_meta = $this->quizPageCategoryMetaKey();

        return static function ($value, $object_id, $meta_key, $single) use (&$touched_page_ids, $quiz_page_category_meta) {
            unset($single);
            if ((string) $meta_key === $quiz_page_category_meta) {
                $touched_page_ids[(int) $object_id] = true;
            }

            return $value;
        };
    }

    private function findCapturedCategoryMetaQuery(array $captured_queries): array
    {
        foreach ($captured_queries as $query_vars) {
            if (!empty($this->extractCategoryMetaQueryValues((array) $query_vars))) {
                return (array) $query_vars;
            }
        }

        $this->fail('Expected a quiz page shell query bounded by category meta values.');
    }

    /**
     * @return int[]
     */
    private function extractCategoryMetaQueryValues(array $query_vars): array
    {
        $quiz_page_category_meta = $this->quizPageCategoryMetaKey();
        $values = [];

        foreach ((array) ($query_vars['meta_query'] ?? []) as $clause) {
            if (!is_array($clause) || (string) ($clause['key'] ?? '') !== $quiz_page_category_meta) {
                continue;
            }

            if (strtoupper((string) ($clause['compare'] ?? '')) !== 'IN') {
                continue;
            }

            foreach ((array) ($clause['value'] ?? []) as $category_id) {
                $category_id = (int) $category_id;
                if ($category_id > 0) {
                    $values[$category_id] = $category_id;
                }
            }
        }

        return array_values($values);
    }

    private function quizPageCategoryMetaKey(): string
    {
        return defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')
            ? (string) LL_TOOLS_QUIZ_PAGE_CATEGORY_META
            : '_ll_tools_word_category_id';
    }
}
