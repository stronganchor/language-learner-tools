<?php
declare(strict_types=1);

final class WordsetPageDeferredStudyCatalogResourceTest extends LL_Tools_TestCase
{
    public function test_deferred_authenticated_main_view_skips_the_genc_sized_full_study_catalog(): void
    {
        $quiz_min_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $quiz_min_filter);

        try {
            $fixture = $this->createWordsetFixture(209);
            $wordset_id = (int) $fixture['wordset_id'];
            $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
            $this->assertCount(209, $category_ids);

            $learner_id = self::factory()->user->create(['role' => 'subscriber']);
            wp_set_current_user($learner_id);

            $full_study_catalog_queries = 0;
            $bounded_flashcard_catalog_queries = 0;
            $full_study_word_queries = [];
            $defer_filter_calls = 0;

            $capture_terms = static function (WP_Term_Query $query) use (&$full_study_catalog_queries, &$bounded_flashcard_catalog_queries): void {
                $taxonomies = array_map('strval', (array) ($query->query_vars['taxonomy'] ?? []));
                if (!in_array('word-category', $taxonomies, true)) {
                    return;
                }

                $functions = array_values(array_filter(array_map(static function (array $frame): string {
                    return isset($frame['function']) ? (string) $frame['function'] : '';
                }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))));
                if (!in_array('ll_flashcards_build_categories', $functions, true)) {
                    return;
                }

                if (in_array('ll_tools_user_study_categories_for_wordset', $functions, true)) {
                    $full_study_catalog_queries++;
                    return;
                }

                $bounded_flashcard_catalog_queries++;
            };
            $capture_posts = static function (WP_Query $query) use (&$full_study_word_queries): void {
                $post_types = array_map('strval', (array) $query->get('post_type'));
                if (
                    !in_array('words', $post_types, true)
                    || (int) $query->get('posts_per_page') !== -1
                    || (string) $query->get('fields') !== 'ids'
                ) {
                    return;
                }

                $functions = array_values(array_filter(array_map(static function (array $frame): string {
                    return isset($frame['function']) ? (string) $frame['function'] : '';
                }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))));
                if (in_array('ll_tools_user_study_categories_for_wordset', $functions, true)) {
                    $full_study_word_queries[] = $query->query_vars;
                }
            };
            $defer_filter = static function ($defer, int $filter_wordset_id) use ($wordset_id, &$defer_filter_calls): bool {
                if ($filter_wordset_id === $wordset_id) {
                    $defer_filter_calls++;
                    return true;
                }

                return (bool) $defer;
            };
            $flashcard_page_size_filter = static function (): int {
                return 6;
            };
            $disable_persist_filter = static function (): bool {
                return false;
            };

            add_action('pre_get_terms', $capture_terms, 10, 1);
            add_action('pre_get_posts', $capture_posts, 10, 1);
            add_filter('ll_tools_wordset_page_defer_main_recommendation_refresh', $defer_filter, 10, 2);
            add_filter('ll_tools_flashcard_catalog_page_size', $flashcard_page_size_filter);
            add_filter('ll_tools_flashcard_categories_persist_transient', $disable_persist_filter);
            add_filter('ll_tools_words_count_persist_transient', $disable_persist_filter);

            try {
                $html = $this->renderWordsetView($wordset_id, '');
            } finally {
                remove_action('pre_get_terms', $capture_terms, 10);
                remove_action('pre_get_posts', $capture_posts, 10);
                remove_filter('ll_tools_wordset_page_defer_main_recommendation_refresh', $defer_filter, 10);
                remove_filter('ll_tools_flashcard_catalog_page_size', $flashcard_page_size_filter);
                remove_filter('ll_tools_flashcard_categories_persist_transient', $disable_persist_filter);
                remove_filter('ll_tools_words_count_persist_transient', $disable_persist_filter);
            }

            $this->assertSame(1, $defer_filter_calls, 'The main-view recommendation deferral decision should be evaluated once.');
            $this->assertSame(0, $full_study_catalog_queries, 'Deferred main views must not invoke the full study-category flashcard catalog.');
            $this->assertSame([], $full_study_word_queries, 'Deferred main views must not issue one unbounded word-ID query per study category.');
            $this->assertGreaterThan(0, $bounded_flashcard_catalog_queries, 'The independently bounded flashcard launcher catalog must remain available.');

            $config = $this->extractLocalizedConfig();
            $this->assertSame('main', (string) ($config['view'] ?? ''));
            $this->assertCount(209, (array) ($config['categories'] ?? []));
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));
            $this->assertSame(18, (int) ($config['lazyCards']['initialCount'] ?? 0));
            $this->assertSame(18, (int) ($config['lazyCards']['loaded'] ?? 0));
            $this->assertSame(209, (int) ($config['lazyCards']['total'] ?? 0));
            $this->assertSame(191, (int) ($config['lazyCards']['remaining'] ?? 0));

            preg_match_all('/<article class="ll-wordset-card[^"]*" role="listitem" data-cat-id="/', $html, $card_matches);
            $this->assertCount(18, (array) ($card_matches[0] ?? []));

            $category_lookup = [];
            foreach ((array) ($config['categories'] ?? []) as $category) {
                if (is_array($category) && (int) ($category['id'] ?? 0) > 0) {
                    $category_lookup[(int) $category['id']] = $category;
                }
            }
            $first_category = $category_lookup[$category_ids[0]] ?? [];
            $this->assertSame('Deferred Study Category 001', (string) ($first_category['name'] ?? ''));
            $this->assertSame(1, (int) ($first_category['count'] ?? 0));
            $this->assertSame('text_title', (string) ($first_category['prompt_type'] ?? ''));
            $this->assertSame('text_translation', (string) ($first_category['option_type'] ?? ''));
            $this->assertSame('text_translation', (string) ($first_category['mode'] ?? ''));
            $this->assertTrue((bool) ($first_category['preview_deferred'] ?? false));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $quiz_min_filter);
        }
    }

    public function test_non_deferred_main_progress_and_study_settings_keep_the_full_study_catalog(): void
    {
        $quiz_min_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $quiz_min_filter);

        try {
            $fixture = $this->createWordsetFixture(3);
            $wordset_id = (int) $fixture['wordset_id'];
            $learner_id = self::factory()->user->create(['role' => 'subscriber']);
            wp_set_current_user($learner_id);

            $force_eager_filter = static function ($defer, int $filter_wordset_id) use ($wordset_id): bool {
                return $filter_wordset_id === $wordset_id ? false : (bool) $defer;
            };
            add_filter('ll_tools_wordset_page_defer_main_recommendation_refresh', $force_eager_filter, 10, 2);
            try {
                $main_queries = $this->countFullStudyCatalogQueries(function () use ($wordset_id): void {
                    $this->renderWordsetView($wordset_id, '');
                });
            } finally {
                remove_filter('ll_tools_wordset_page_defer_main_recommendation_refresh', $force_eager_filter, 10);
            }

            $progress_queries = $this->countFullStudyCatalogQueries(function () use ($wordset_id): void {
                $this->renderWordsetView($wordset_id, 'progress');
            });
            $study_settings_queries = $this->countFullStudyCatalogQueries(function () use ($wordset_id): void {
                $this->renderWordsetView($wordset_id, 'settings', ['ll_wordset_tool' => 'study']);
            });

            $this->assertGreaterThan(0, $main_queries, 'An explicit eager main-view recommendation request must retain the full study catalog.');
            $this->assertGreaterThan(0, $progress_queries, 'The progress view must retain the full study catalog.');
            $this->assertGreaterThan(0, $study_settings_queries, 'Study settings must retain the full study catalog.');
        } finally {
            remove_filter('ll_tools_quiz_min_words', $quiz_min_filter);
        }
    }

    /**
     * @return array{wordset_id:int,category_ids:int[]}
     */
    private function createWordsetFixture(int $category_count): array
    {
        $category_count = max(1, $category_count);
        $suffix = strtolower(wp_generate_password(8, false));
        $wordset = wp_insert_term('Deferred Study Wordset ' . $suffix, 'wordset', [
            'slug' => 'deferred-study-wordset-' . $suffix,
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category_ids = [];

        ll_tools_begin_deferred_category_maintenance('deferred-study-catalog-resource-test');
        try {
            for ($index = 1; $index <= $category_count; $index++) {
                $label = 'Deferred Study Category ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                $created_category = ll_tools_create_or_get_wordset_category($label, $wordset_id, [
                    'slug' => 'deferred-study-category-' . $suffix . '-' . $index,
                ]);
                $this->assertNotWPError($created_category);
                $category_id = (int) $created_category;
                $this->assertGreaterThan(0, $category_id);
                $category_ids[] = $category_id;

                update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
                update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

                $lesson_id = wp_insert_post([
                    'post_type' => 'll_vocab_lesson',
                    'post_status' => 'publish',
                    'post_title' => $label . ' Lesson',
                    'post_name' => 'deferred-study-lesson-' . $suffix . '-' . $index,
                    'meta_input' => [
                        LL_TOOLS_VOCAB_LESSON_WORDSET_META => (string) $wordset_id,
                        LL_TOOLS_VOCAB_LESSON_CATEGORY_META => (string) $category_id,
                    ],
                ], true);
                $this->assertIsInt($lesson_id);
                $this->assertGreaterThan(0, $lesson_id);
            }

            $word_id = wp_insert_post([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Deferred Study Shared Word ' . $suffix,
                'post_name' => 'deferred-study-shared-word-' . $suffix,
                'meta_input' => [
                    'word_translation' => 'Shared translation',
                ],
            ], true);
            $this->assertIsInt($word_id);
            $this->assertGreaterThan(0, $word_id);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, $category_ids, 'word-category', false);
        } finally {
            ll_tools_end_deferred_category_maintenance(false);
        }

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
        ];
    }

    private function renderWordsetView(int $wordset_id, string $view, array $get = []): string
    {
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = $get;
        set_query_var('ll_wordset_page', (string) $wordset->slug);
        set_query_var('ll_wordset_view', $view);
        if (function_exists('ll_tools_flashcard_widget_reset_render_guard')) {
            ll_tools_flashcard_widget_reset_render_guard();
        }

        try {
            return ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }
    }

    private function countFullStudyCatalogQueries(callable $callback): int
    {
        $queries = 0;
        $capture_terms = static function (WP_Term_Query $query) use (&$queries): void {
            $taxonomies = array_map('strval', (array) ($query->query_vars['taxonomy'] ?? []));
            if (!in_array('word-category', $taxonomies, true)) {
                return;
            }

            $functions = array_values(array_filter(array_map(static function (array $frame): string {
                return isset($frame['function']) ? (string) $frame['function'] : '';
            }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))));
            if (in_array('ll_tools_user_study_categories_for_wordset', $functions, true)) {
                $queries++;
            }
        };

        add_action('pre_get_terms', $capture_terms, 10, 1);
        try {
            $callback();
        } finally {
            remove_action('pre_get_terms', $capture_terms, 10);
        }

        return $queries;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(): array
    {
        $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        preg_match_all('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertNotEmpty($matches[1]);

        $latest = end($matches[1]);
        $this->assertIsString($latest);
        $decoded = json_decode($latest, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
