<?php
declare(strict_types=1);

final class WordsetPageDeferredStudyCatalogResourceTest extends LL_Tools_TestCase
{
    public function test_level_summary_uses_the_rendered_pages_locale_when_the_staff_dashboard_is_english(): void
    {
        global $wpdb;
        ll_tools_install_user_progress_schema();
        $minimum = static function (): int { return 1; };
        $turkish_site = static function (): string { return 'tr_TR'; };
        add_filter('ll_tools_quiz_min_words', $minimum);
        try {
            $fixture = $this->createWordsetFixture(3);
            $wordset = $fixture['wordset_id'];
            $categories = $fixture['category_ids'];
            update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'prerequisite');
            update_term_meta($wordset, 'll_wordset_category_prerequisites', [$categories[2] => [$categories[0]]]);
            $user_id = self::factory()->user->create(['role' => 'administrator']);
            update_user_meta($user_id, 'locale', 'en_US');
            update_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, 'tr_TR');
            wp_set_current_user($user_id);
            $now = current_time('mysql', true);
            foreach (array_slice($categories, 0, 2) as $category) {
                $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
                update_post_meta($word, 'word_translation', 'Translation ' . $word);
                wp_set_post_terms($word, [$wordset], 'wordset');
                wp_set_post_terms($word, [$category], 'word-category');
                $this->assertNotFalse($wpdb->insert(ll_tools_user_progress_table_names()['words'], [
                    'user_id' => $user_id, 'word_id' => $word, 'category_id' => $category, 'wordset_id' => $wordset,
                    'last_seen_at' => $now, 'first_seen_at' => $now, 'updated_at' => $now, 'total_coverage' => 1,
                ]));
            }
            $this->completeLlToolsSimulatedRequest();
            set_current_screen('front');
            $this->assertSame('tr_TR', determine_locale());
            $this->renderWordsetView($wordset, '');
            $token = $this->extractLocalizedConfig()['curriculumLevels']['token'];
            $this->assertSame('tr_TR', ll_tools_wordset_page_get_category_search_payload($token)['level_locale']);

            // admin-ajax uses the staff preference, even on a site whose own language is Turkish.
            set_current_screen('dashboard');
            add_filter('locale', $turkish_site, 1);
            $this->assertSame('tr_TR', get_locale());
            $this->assertSame('en_US', determine_locale());
            $summary = ll_tools_wordset_level_request($user_id, [
                'wordset_id' => $wordset, 'operation' => 'summary', 'token' => $token, 'locale' => 'en_US',
            ]);
            $this->assertIsArray($summary);
            $this->assertSame('öğrenmekte olduğun 2 kelime', $summary['levels'][1]['practice_activity']['label']);
            $this->assertSame('en_US', determine_locale());
            $this->assertSame('2 words in progress', ll_tools_wordset_level_practice_label(['working' => 2]));
            $this->assertSame('en_US', get_user_meta($user_id, 'locale', true));
            $this->assertSame('tr_TR', get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $minimum);
            remove_filter('locale', $turkish_site, 1);
            set_current_screen('front');
        }
    }

    public function test_shortcode_projects_the_full_curriculum_before_pagination_for_guests_and_learners(): void
    {
        $minimum = static function (): int { return 1; };
        $batch = static function (): int { return 6; };
        add_filter('ll_tools_quiz_min_words', $minimum);
        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch);
        try {
            $fixture = $this->createWordsetFixture(10);
            $wordset_id = $fixture['wordset_id'];
            $ids = $fixture['category_ids'];
            update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'prerequisite');
            update_term_meta($wordset_id, 'll_wordset_category_prerequisites', [$ids[7] => [$ids[0]], $ids[8] => [$ids[7]]]);
            $learner = self::factory()->user->create(['role' => 'subscriber']);
            ll_tools_save_user_study_goals(['ignored_category_ids' => [$ids[0]]], $learner);
            foreach ([0, $learner] as $user_id) {
                $this->completeLlToolsSimulatedRequest();
                wp_set_current_user($user_id);
                ll_tools_flashcard_widget_reset_render_guard();
                $html = do_shortcode('[wordset_page id="' . $wordset_id . '"]');
                $data = $this->extractLocalizedConfig();
                $curriculum = $data['curriculumLevels'];
                $levels = array_column($curriculum['levels'], 'categoryIds', 'level');
                $this->assertSame([1, 2, 3], array_keys($levels));
                $this->assertSame([$ids[7]], $levels[2]);
                $this->assertSame([$ids[8]], $levels[3]);
                $this->assertContains($ids[9], $levels[1]);
                $this->assertSame($user_id === 0, in_array($ids[0], $levels[1], true));
                $this->assertSame(6, $data['lazyCards']['initialCount']);
                $this->assertTrue($data['lazyCards']['enabled']);
                $this->assertSame($user_id !== 0, (bool) $data['isLoggedIn']);
                // Guests receive a public scope for launching level quizzes. Progress stays learner-only.
                $this->assertNotSame('', $curriculum['token']);
                $this->assertNotFalse(wp_verify_nonce($curriculum['nonce'], 'll_tools_wordset_levels'));
                $scope = ll_tools_wordset_page_get_category_search_payload($curriculum['token']);
                $this->assertSame($user_id, $scope['user_id']);
                $this->assertSame([$ids[8]], array_column($scope['level_scope'][3], 'id'));
                $this->assertStringContainsString('data-ll-wordset-levels', $html);
                $this->assertStringContainsString('data-ll-level-initial', $html);
                $this->assertSame(1, preg_match('/<section data-ll-wordset-levels[^>]*>(.*?)<\/section>/s', $html, $shell));
                foreach ($levels as $level => $_) {
                    $this->assertStringContainsString('data-ll-level-card="' . $level . '"', $shell[1]);
                    $this->assertStringContainsString(esc_html(sprintf($curriculum['i18n']['level'], $level)), $shell[1]);
                }
                $this->assertStringNotContainsString('<button', $shell[1]);
                $this->assertSame($user_id !== 0, strpos($shell[1], 'll-level-review-placeholder') !== false);
                $this->assertStringContainsString('data-ll-level-badge="1"', $html);
                $this->assertTrue(wp_script_is('ll-wordset-levels-js', 'enqueued'));
                $this->assertContains('ll-wordset-levels-js', wp_scripts()->registered['ll-wordset-pages-js']->deps);
                $this->assertTrue(wp_style_is('ll-wordset-levels-css', 'enqueued'));
            }
        } finally {
            wp_set_current_user(0);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch);
            remove_filter('ll_tools_quiz_min_words', $minimum);
        }
    }

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
            $localized_categories = (array) ($config['categories'] ?? []);
            $diagnostic_message = count($localized_categories) === count($category_ids)
                ? ''
                : $this->buildDeferredCatalogFailureDiagnostic($wordset_id, $category_ids, $config);
            $this->assertCount(count($category_ids), $localized_categories, $diagnostic_message);
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

    public function test_progress_render_primes_selection_category_payload_for_a_later_request(): void
    {
        $quiz_min_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $quiz_min_filter);

        try {
            $fixture = $this->createWordsetFixture(3);
            $wordset_id = (int) $fixture['wordset_id'];
            $category_ids = array_values(array_map('intval', (array) $fixture['category_ids']));
            $learner_id = self::factory()->user->create(['role' => 'subscriber']);
            wp_set_current_user($learner_id);

            $render_cache_statuses = [];
            $capture_render_cache_status = static function (string $status) use (&$render_cache_statuses): void {
                $render_cache_statuses[] = $status;
            };
            add_action(
                'll_tools_user_progress_selection_category_payload_cache_status',
                $capture_render_cache_status,
                10,
                1
            );
            try {
                $this->renderWordsetView($wordset_id, 'progress');
            } finally {
                remove_action(
                    'll_tools_user_progress_selection_category_payload_cache_status',
                    $capture_render_cache_status,
                    10
                );
            }
            $this->assertContains('store', $render_cache_statuses, 'The Progress render must prime the launch cache.');

            $render_cache_key = ll_tools_user_progress_selection_category_payload_cache_key($wordset_id);
            wp_cache_delete($render_cache_key, 'll_tools_user_progress');
            $had_epoch_request_cache = array_key_exists('ll_tools_epoch_request_cache', $GLOBALS);
            $original_epoch_request_cache = $GLOBALS['ll_tools_epoch_request_cache'] ?? null;
            ll_tools_epoch_request_cache_reset();
            $lookup_cache_key = ll_tools_user_progress_selection_category_payload_cache_key($wordset_id);
            $this->assertSame($render_cache_key, $lookup_cache_key);
            $this->assertFalse(
                wp_cache_get($lookup_cache_key, 'll_tools_user_progress'),
                'The launch lookup must cross the persistent-transient boundary, not reuse the request object cache.'
            );

            $lookup_cache_statuses = [];
            $resolved_category_ids = [];
            $capture_lookup_cache_status = static function (string $status) use (&$lookup_cache_statuses): void {
                $lookup_cache_statuses[] = $status;
            };
            $capture_resolve = static function (int $category_id) use (&$resolved_category_ids): void {
                $resolved_category_ids[] = $category_id;
            };
            add_action(
                'll_tools_user_progress_selection_category_payload_cache_status',
                $capture_lookup_cache_status,
                10,
                1
            );
            add_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10, 1);
            try {
                $queries_before_lookup = get_num_queries();
                $payload = ll_tools_user_progress_selection_category_payload_map(
                    $category_ids,
                    $wordset_id,
                    $complete
                );
                $lookup_query_count = get_num_queries() - $queries_before_lookup;
            } finally {
                remove_action(
                    'll_tools_user_progress_selection_category_payload_cache_status',
                    $capture_lookup_cache_status,
                    10
                );
                remove_action('ll_tools_user_progress_selection_category_payload_resolve', $capture_resolve, 10);
                if ($had_epoch_request_cache) {
                    $GLOBALS['ll_tools_epoch_request_cache'] = $original_epoch_request_cache;
                } else {
                    unset($GLOBALS['ll_tools_epoch_request_cache']);
                }
            }

            $this->assertTrue($complete);
            $this->assertCount(3, $payload);
            $this->assertContains('hit', $lookup_cache_statuses);
            $this->assertSame([], $resolved_category_ids, 'The later launch request must not resolve categories one by one.');
            $this->assertLessThanOrEqual(3, $lookup_query_count, 'The transient-backed launch lookup must remain O(1).');
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

        // Fixture writes represent an import request. Publish its final cache
        // generation before the page-render request reads the completed catalog.
        $this->completeLlToolsSimulatedRequest();

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
     * Build a compact, failure-only snapshot without changing the render path.
     *
     * @param int[] $category_ids
     * @param array<string,mixed> $config
     */
    private function buildDeferredCatalogFailureDiagnostic(int $wordset_id, array $category_ids, array $config): string
    {
        global $wpdb;

        $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
        $raw_tt_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
            $wordset_id,
            'wordset'
        ));
        $published_words = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} ws
                ON ws.object_id = p.ID AND ws.term_taxonomy_id = %d
             WHERE p.post_type = %s AND p.post_status = %s",
            $raw_tt_id,
            'words',
            'publish'
        ));
        $related_categories = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT category_tt.term_id)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} ws
                ON ws.object_id = p.ID AND ws.term_taxonomy_id = %d
             INNER JOIN {$wpdb->term_relationships} category_rel
                ON category_rel.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} category_tt
                ON category_tt.term_taxonomy_id = category_rel.term_taxonomy_id
                AND category_tt.taxonomy = %s
             WHERE p.post_type = %s AND p.post_status = %s",
            $raw_tt_id,
            'word-category',
            'words',
            'publish'
        ));
        $lesson_counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) AS lessons,
                    COUNT(DISTINCT CAST(category_meta.meta_value AS UNSIGNED)) AS categories
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} wordset_meta
                ON wordset_meta.post_id = p.ID
                AND wordset_meta.meta_key = %s
                AND wordset_meta.meta_value = %s
             INNER JOIN {$wpdb->postmeta} category_meta
                ON category_meta.post_id = p.ID
                AND category_meta.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s",
            LL_TOOLS_VOCAB_LESSON_WORDSET_META,
            (string) $wordset_id,
            LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
            'll_vocab_lesson',
            'publish'
        ), ARRAY_A);

        $tt_static_variables = (new ReflectionFunction('ll_tools_vocab_lesson_get_wordset_term_taxonomy_id'))->getStaticVariables();
        $tt_static_cache = (array) ($tt_static_variables['request_cache'] ?? []);
        $tt_static_entry = is_array($tt_static_cache[$wordset_id] ?? null)
            ? $tt_static_cache[$wordset_id]
            : [];
        $tt_complete = true;
        $helper_tt_id = ll_tools_vocab_lesson_get_wordset_term_taxonomy_id($wordset_id, $tt_complete);

        $dependencies = ll_tools_wordset_page_published_lesson_map_dependencies();
        $map_key = ll_tools_wordset_page_build_cache_key('published_lesson_map', [
            'schema' => 3,
            'wordset_id' => $wordset_id,
            'category_epoch' => $dependencies['category_epoch'],
            'wordset_epoch' => $dependencies['wordset_epoch'],
            'content_fallback_epoch' => $dependencies['content_fallback_epoch'],
        ]);
        $map_static_variables = (new ReflectionFunction('ll_tools_wordset_page_get_published_vocab_lesson_category_map'))->getStaticVariables();
        $map_static_cache = (array) ($map_static_variables['request_cache'] ?? []);
        $map_static_present = array_key_exists($map_key, $map_static_cache);
        $map_static_payload = $map_static_present ? $map_static_cache[$map_key] : null;
        $map_payload = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);

        $category_epoch = ll_tools_get_category_cache_epoch();
        $wordset_epoch = ll_tools_get_wordset_cache_epoch();
        $quiz_content_epoch = ll_tools_get_quiz_content_cache_epoch([$wordset_id]);
        $deep_key = 'll_vocab_lesson_deep_counts_' . md5((string) wp_json_encode([
            'schema' => 6,
            'wordset_id' => $wordset_id,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'quiz_content_epoch' => $quiz_content_epoch,
        ]));
        $deep_static_variables = (new ReflectionFunction('ll_tools_get_vocab_lesson_deepest_counts_for_wordset'))->getStaticVariables();
        $deep_static_cache = (array) ($deep_static_variables['request_cache'] ?? []);
        $deep_static_present = array_key_exists($deep_key, $deep_static_cache);
        $deep_static_payload = $deep_static_present ? $deep_static_cache[$deep_key] : null;
        $deep_payload = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id);

        $wordset_min = max(1, (int) apply_filters('ll_tools_wordset_page_min_words', 1, $wordset_id));
        $lesson_min = ll_tools_get_vocab_lesson_min_word_count(null, $wordset_id);
        $translation_enabled = ll_tools_is_category_translation_enabled([$wordset_id]) ? '1' : '0';
        $translation_target = sanitize_key((string) ll_tools_get_wordset_translation_language([$wordset_id]));
        $cache_context = substr(md5(sanitize_key((string) get_locale()) . '|' . $translation_enabled . '|' . $translation_target), 0, 8);
        $ordering_signature = ll_tools_wordset_get_category_ordering_cache_signature($wordset_id);
        $row_key = ll_tools_wordset_page_build_cache_key('category_rows', [
            'wordset_id' => $wordset_id,
            'min_words' => max($wordset_min, $lesson_min),
            'preview_limit' => 2,
            'ordering_sig' => $ordering_signature,
            'cache_context' => $cache_context,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'quiz_content_epoch' => $quiz_content_epoch,
            'lesson_sig' => $category_epoch . ':' . $wordset_epoch,
            'preview_schema' => 5,
            'inactive' => 0,
            'inactive_user_id' => 0,
        ]);
        $row_static_variables = (new ReflectionFunction('ll_tools_get_wordset_page_category_rows'))->getStaticVariables();
        $row_static_cache = (array) ($row_static_variables['request_cache'] ?? []);
        $row_static_present = array_key_exists($row_key, $row_static_cache);
        $row_static_payload = $row_static_present ? $row_static_cache[$row_key] : null;
        $rows_complete = true;
        $rows = ll_tools_get_wordset_page_category_rows($wordset_id, 2, false, $rows_complete);

        $user_id = max(0, (int) get_current_user_id());
        $category_key = ll_tools_wordset_page_build_cache_key('categories_user', [
            'schema' => 10,
            'wordset_id' => $wordset_id,
            'preview_limit' => 2,
            'preview_mode' => 'deferred',
            'locale' => sanitize_key((string) get_locale()),
            'translation_enabled' => $translation_enabled,
            'translation_target' => $translation_target,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'quiz_content_epoch' => $quiz_content_epoch,
            'user_id' => $user_id,
            'include_inactive' => 0,
        ]);
        $category_static_variables = (new ReflectionFunction('ll_tools_get_wordset_page_categories'))->getStaticVariables();
        $category_static_cache = (array) ($category_static_variables['category_request_cache'] ?? []);
        $category_static_present = array_key_exists($category_key, $category_static_cache);
        $category_static_payload = $category_static_present ? $category_static_cache[$category_key] : null;

        $summarize_map = static function ($payload): array {
            $payload = is_array($payload) ? $payload : [];
            return [
                'present' => !empty($payload),
                'complete' => !empty($payload['complete']),
                'map_count' => count((array) ($payload['map'] ?? [])),
                'signature' => (string) ($payload['signature'] ?? ''),
                'stale' => !empty($payload['stale']),
            ];
        };
        $summarize_deep = static function ($payload) use ($category_ids): array {
            $payload = is_array($payload) ? $payload : [];
            $all = (array) ($payload['all'] ?? []);
            return [
                'present' => !empty($payload),
                'complete' => !empty($payload['complete']),
                'all_key_count' => count($all),
                'all_sum' => array_sum(array_map('intval', $all)),
                'expected_key_count' => count(array_intersect($category_ids, array_map('intval', array_keys($all)))),
            ];
        };

        $script_data = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        preg_match_all('/var llWordsetPageData = (\{.*?\});/s', $script_data, $localized_matches);

        return 'Deferred catalog mismatch: ' . (string) wp_json_encode([
            'db' => [
                'raw_tt_id' => $raw_tt_id,
                'published_words' => $published_words,
                'related_categories' => $related_categories,
                'lessons' => (int) ($lesson_counts['lessons'] ?? 0),
                'lesson_categories' => (int) ($lesson_counts['categories'] ?? 0),
                'last_error' => (string) $wpdb->last_error,
            ],
            'tt' => [
                'static_present' => !empty($tt_static_entry),
                'static_id' => (int) ($tt_static_entry['term_taxonomy_id'] ?? 0),
                'static_complete' => !empty($tt_static_entry['complete']),
                'helper_id' => (int) $helper_tt_id,
                'helper_complete' => $tt_complete,
            ],
            'epochs' => [
                'category' => $category_epoch,
                'wordset' => $wordset_epoch,
                'quiz_content' => (string) $quiz_content_epoch,
                'fallback' => (string) ($dependencies['content_fallback_epoch'] ?? ''),
            ],
            'map' => [
                'static_key_present' => $map_static_present,
                'static' => $summarize_map($map_static_payload),
                'current' => $summarize_map($map_payload),
                'lock_present' => get_option(ll_tools_wordset_page_cache_rebuild_lock_option($map_key), false) !== false,
            ],
            'deep' => [
                'static_key_present' => $deep_static_present,
                'static' => $summarize_deep($deep_static_payload),
                'current' => $summarize_deep($deep_payload),
            ],
            'rows' => [
                'static_key_present' => $row_static_present,
                'static_count' => is_array($row_static_payload) ? count($row_static_payload) : null,
                'current_count' => count($rows),
                'current_complete' => $rows_complete,
            ],
            'categories' => [
                'static_key_present' => $category_static_present,
                'static_count' => is_array($category_static_payload) ? count($category_static_payload) : null,
                'localized_count' => count((array) ($config['categories'] ?? [])),
            ],
            'scripts' => [
                'registered' => wp_script_is('ll-wordset-pages-js', 'registered'),
                'enqueued' => wp_script_is('ll-wordset-pages-js', 'enqueued'),
                'localized_blocks' => count((array) ($localized_matches[1] ?? [])),
                'data_bytes' => strlen($script_data),
            ],
        ], JSON_UNESCAPED_SLASHES);
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
