<?php
declare(strict_types=1);

final class WordsetPageSavedSortInitialChunkTest extends LL_Tools_TestCase
{
    public function test_saved_alpha_sort_orders_initial_chunk_and_lazy_payload_from_server(): void
    {
        $fixture = $this->createManualWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $cookie_name = ll_tools_wordset_page_get_main_sort_cookie_name($wordset_id);
        $original_cookie = $_COOKIE;
        $original_get = $_GET;
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');

        $batch_size_filter = static function ($batch_size): int {
            return 6;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $_COOKIE[$cookie_name] = 'alpha-asc';
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            preg_match_all('/<h2 class="ll-wordset-card__title">([^<]+)<\\/h2>/', $html, $matches);
            $titles = array_map('html_entity_decode', $matches[1] ?? []);
            $this->assertCount(6, $titles);
            $this->assertStringStartsWith('Animals', (string) $titles[0]);
            $this->assertStringStartsWith('Body', (string) $titles[1]);
            $this->assertStringStartsWith('Colors', (string) $titles[2]);
            $this->assertStringStartsWith('Fruit', (string) $titles[3]);
            $this->assertStringStartsWith('Numbers', (string) $titles[4]);
            $this->assertStringStartsWith('School', (string) $titles[5]);

            $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
            $this->assertNotSame('', $localized);

            $config = $this->extractLocalizedConfig($localized);
            $this->assertSame('alpha-asc', (string) ($config['initialMainCategorySort'] ?? ''));
            $this->assertSame($cookie_name, (string) ($config['mainCategorySortCookieName'] ?? ''));
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));

            $_POST = [
                'nonce' => (string) ($config['lazyCards']['nonce'] ?? ''),
                'token' => (string) ($config['lazyCards']['token'] ?? ''),
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 2,
            ];
            $_REQUEST = $_POST;

            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertIsArray($response['data'] ?? null);
            $this->assertStringContainsString('Travel', (string) (($response['data']['html'] ?? '')));
            $this->assertStringContainsString('Weather', (string) (($response['data']['html'] ?? '')));
        } finally {
            $_COOKIE = $original_cookie;
            $_GET = $original_get;
            $_POST = $original_post;
            $_REQUEST = $original_request;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_genc_scale_saved_progress_sort_defers_full_wordset_metrics_and_keeps_lazy_offsets_canonical(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createManualWordsetFixture([
            'prompt_type' => 'audio',
            'option_type' => 'text_title',
        ]);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $category_ids = (array) ($fixture['category_ids'] ?? []);
        $word_ids_by_label = (array) ($fixture['word_ids_by_label'] ?? []);
        $animals_category_id = (int) ($category_ids['Animals'] ?? 0);
        $body_category_id = (int) ($category_ids['Body'] ?? 0);
        $this->assertGreaterThan(0, $animals_category_id);
        $this->assertGreaterThan(0, $body_category_id);

        foreach (array_slice(array_values((array) ($word_ids_by_label['Animals'] ?? [])), 0, 3) as $word_id) {
            $this->seedWordProgressRow($user_id, (int) $word_id, $animals_category_id, $wordset_id, [
                'total_coverage' => 6,
                'coverage_practice' => 6,
                'correct_clean' => 6,
                'current_correct_streak' => 6,
                'mastery_unlocked' => 1,
                'stage' => 6,
                'last_seen_at' => '2026-06-01 12:00:00',
            ]);
        }
        foreach (array_slice(array_values((array) ($word_ids_by_label['Body'] ?? [])), 0, 2) as $word_id) {
            $this->seedWordProgressRow($user_id, (int) $word_id, $body_category_id, $wordset_id, [
                'total_coverage' => 1,
                'coverage_practice' => 1,
                'correct_clean' => 1,
                'current_correct_streak' => 1,
                'stage' => 1,
                'last_seen_at' => '2026-06-02 12:00:00',
            ]);
        }

        $cookie_name = ll_tools_wordset_page_get_main_sort_cookie_name($wordset_id);
        $original_cookie = $_COOKIE;
        $original_get = $_GET;
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $word_audio_queries = 0;
        $collector_queries = [];
        $capture_word_audio_query = static function (WP_Query $query) use (&$word_audio_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? array_map('strval', $post_type) : [(string) $post_type];
            if (in_array('word_audio', $post_types, true)) {
                $word_audio_queries++;
            }
        };
        $capture_collector_queries = static function (string $query) use (&$collector_queries): string {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                if (($frame['function'] ?? '') !== 'll_tools_wordset_page_collect_category_metrics_for_user') {
                    continue;
                }

                $collector_queries[] = $query;
                break;
            }

            return $query;
        };
        $batch_size_filter = static function (): int {
            return 6;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id) use ($wordset_id): bool {
            if ((int) $filter_wordset_id === $wordset_id && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        $disable_user_category_cache = static function ($enabled, $filter_wordset_id) use ($wordset_id): bool {
            return (int) $filter_wordset_id === $wordset_id ? false : (bool) $enabled;
        };
        $expand_to_genc_scale = static function (array $categories, $filter_wordset_id) use ($wordset_id): array {
            if ((int) $filter_wordset_id !== $wordset_id || empty($categories)) {
                return $categories;
            }

            $target_count = 227;
            $base_categories = array_values($categories);
            $synthetic_index = 1;
            while (count($categories) < $target_count) {
                $source = $base_categories[($synthetic_index - 1) % count($base_categories)];
                $category_id = 900000 + $synthetic_index;
                $label = sprintf('Synthetic Category %03d', $synthetic_index);
                $source['id'] = $category_id;
                $source['slug'] = 'synthetic-category-' . $synthetic_index;
                $source['raw_name'] = $label;
                $source['name'] = $label;
                $source['translation'] = $label;
                $source['wordset_id'] = $wordset_id;
                $source['count'] = 5;
                $source['url'] = home_url('/synthetic-category-' . $synthetic_index . '/');
                $source['is_public'] = true;
                $source['hidden'] = false;
                $source['aspect_bucket'] = 'no-image';
                $source['has_images'] = false;
                $source['preview_deferred'] = false;
                $source['preview_requires_images'] = false;
                $source['preview'] = [[
                    'type' => 'text',
                    'label' => $label . ' preview',
                ]];
                $categories[] = $source;
                $synthetic_index++;
            }

            return array_values($categories);
        };

        add_action('pre_get_posts', $capture_word_audio_query);
        add_filter('query', $capture_collector_queries);
        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);
        add_filter('ll_tools_wordset_page_user_categories_cache_enabled', $disable_user_category_cache, 10, 2);
        add_filter('ll_tools_wordset_page_categories', $expand_to_genc_scale, 10, 2);

        $_COOKIE[$cookie_name] = 'progress-desc';
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            preg_match_all('/<h2 class="ll-wordset-card__title">([^<]+)<\\/h2>/', $html, $matches);
            $titles = array_map('html_entity_decode', $matches[1] ?? []);
            $this->assertCount(6, $titles);
            $this->assertStringStartsWith(
                'Travel',
                (string) $titles[0],
                'Metric ordering should wait for the deferred analytics aggregate instead of scanning the full wordset before first paint.'
            );

            $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
            $config = $this->extractLocalizedConfig($localized);
            $this->assertSame('progress-desc', (string) ($config['initialMainCategorySort'] ?? ''));
            $this->assertTrue((bool) ($config['summaryCountsDeferred'] ?? false));
            $this->assertCount(227, (array) ($config['categories'] ?? []));
            $this->assertLessThan(
                100000,
                strlen((string) wp_json_encode($config)),
                'The Genç-scale inline runtime config should remain sparse instead of repeating full category rows in lazy shells.'
            );
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));
            $this->assertSame(6, (int) ($config['lazyCards']['initialCount'] ?? 0));
            $this->assertSame(227, (int) ($config['lazyCards']['total'] ?? 0));

            $_POST = [
                'nonce' => (string) ($config['lazyCards']['nonce'] ?? ''),
                'token' => (string) ($config['lazyCards']['token'] ?? ''),
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 2,
            ];
            $_REQUEST = $_POST;

            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertStringContainsString('School', (string) (($response['data']['html'] ?? '')));
            $this->assertStringContainsString('Weather', (string) (($response['data']['html'] ?? '')));
        } finally {
            $_COOKIE = $original_cookie;
            $_GET = $original_get;
            $_POST = $original_post;
            $_REQUEST = $original_request;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_action('pre_get_posts', $capture_word_audio_query);
            remove_filter('query', $capture_collector_queries);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
            remove_filter('ll_tools_wordset_page_user_categories_cache_enabled', $disable_user_category_cache, 10);
            remove_filter('ll_tools_wordset_page_categories', $expand_to_genc_scale, 10);
        }

        $this->assertSame(0, $word_audio_queries, 'Saved progress-sort metrics should not hydrate word_audio post rows.');
        $this->assertSame([], $collector_queries, 'Deferred metric sorting must not enter the renderable-ID/full-wordset metrics collector.');
        $this->assertSame('default', ll_tools_wordset_page_get_server_main_sort('progress-desc', false));
        $this->assertSame('progress-desc', ll_tools_wordset_page_get_server_main_sort('progress-desc', true));
    }

    public function test_category_row_eligibility_primes_candidate_terms_and_meta_in_batches(): void
    {
        global $wpdb;

        $fixture = $this->createManualWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map(static function ($category_id) use ($wordset_id): int {
            $category_id = (int) $category_id;
            if (!function_exists('ll_tools_get_effective_category_id_for_wordset')) {
                return $category_id;
            }
            $effective_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
            return $effective_id > 0 ? $effective_id : $category_id;
        }, (array) $fixture['category_ids']));

        clean_term_cache($category_ids, 'word-category');
        foreach ($category_ids as $category_id) {
            wp_cache_delete($category_id, 'term_meta');
        }

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            ll_tools_wordset_page_prime_category_eligibility_terms($category_ids);
            foreach ($category_ids as $category_id) {
                $this->assertInstanceOf(WP_Term::class, get_term($category_id, 'word-category'));
                get_term_meta($category_id);
            }
        } finally {
            remove_filter('query', $capture);
        }

        $term_object_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->terms . ' AS t') !== false
                && strpos($query, $wpdb->term_taxonomy) !== false;
        }));
        $single_term_queries = array_values(array_filter($term_object_queries, static function (string $query): bool {
            return (bool) preg_match('/\\bWHERE t\\.term_id = \\d+\\b/', $query);
        }));
        $batched_term_object_queries = array_values(array_filter($term_object_queries, static function (string $query): bool {
            return (bool) preg_match('/\\bt\\.term_id IN \\(\\s*[^)]*,[^)]*\\)/', $query);
        }));
        $term_meta_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->termmeta) !== false;
        }));
        $batched_term_meta_queries = array_values(array_filter($term_meta_queries, static function (string $query): bool {
            return (bool) preg_match('/\\bterm_id IN \\([^)]*,[^)]*\\)/', $query);
        }));

        $this->assertSame([], $single_term_queries, 'Eligibility checks must not fetch each category term separately.');
        $this->assertNotEmpty($batched_term_object_queries, 'The candidate term set should be represented by a multi-ID query.');
        $this->assertLessThanOrEqual(1, count($term_meta_queries), 'Candidate quiz configuration metadata should be primed in one batch.');
        $this->assertNotEmpty($batched_term_meta_queries, 'The cold candidate set should be represented by one multi-ID term-meta query.');
    }

    public function test_published_lesson_map_primes_lesson_posts_and_meta_in_batches(): void
    {
        global $wpdb;

        $fixture = $this->createManualWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $lesson_ids = array_values(array_map('intval', get_posts([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
            'meta_value' => (string) $wordset_id,
        ])));
        $this->assertGreaterThanOrEqual(
            count($fixture['category_ids']),
            count($lesson_ids),
            'Each fixture category should have a published lesson, including installations that auto-provision lessons.'
        );

        foreach ($lesson_ids as $lesson_id) {
            clean_post_cache($lesson_id);
            wp_cache_delete($lesson_id, 'post_meta');
        }

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $payload = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertCount(8, array_unique(array_map('intval', (array) ($payload['map'] ?? []))));
        $post_batches = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->posts . ' WHERE ID IN (') !== false
                && substr_count($query, ',') >= 7;
        }));
        $post_meta_batches = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->postmeta . ' WHERE post_id IN (') !== false
                && substr_count($query, ',') >= 7;
        }));
        $single_post_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->posts . ' WHERE ID = ') !== false;
        }));

        $this->assertSame([], $single_post_queries, 'Lesson metadata and permalinks must not reload one lesson post at a time.');
        $this->assertCount(1, $post_batches, 'Lesson post objects should be primed in one exact-ID batch.');
        $this->assertCount(1, $post_meta_batches, 'Lesson metadata should be primed in one exact-ID batch.');
    }

    public function test_category_preview_does_not_requery_membership_for_each_candidate_word(): void
    {
        global $wpdb;

        $fixture = $this->createManualWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $source_category_id = (int) reset($fixture['category_ids']);
        $category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($source_category_id, $wordset_id, false)
            : $source_category_id;
        $this->assertGreaterThan(0, $category_id);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $preview = ll_tools_get_wordset_category_preview($wordset_id, $category_id, 2, false);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertCount(2, (array) ($preview['items'] ?? []));
        $per_word_category_queries = array_values(array_filter($captured_queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, 'FROM ' . $wpdb->terms . ' AS t') !== false
                && strpos($query, $wpdb->term_relationships . ' AS tr') !== false
                && strpos($query, "tt.taxonomy IN ('word-category')") !== false
                && strpos($query, 'tr.object_id IN (') !== false;
        }));
        $this->assertSame([], $per_word_category_queries, 'The category-scoped candidate query already proves membership.');
    }

    /**
     * @param array{prompt_type?:string,option_type?:string} $args
     * @return array{wordset_id:int,category_ids:array<string,int>,word_ids_by_label:array<string,int[]>}
     */
    private function createManualWordsetFixture(array $args = []): array
    {
        $wordset = wp_insert_term('Saved Sort Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $prompt_type = (string) ($args['prompt_type'] ?? 'text_title');
        $option_type = (string) ($args['option_type'] ?? 'text_title');

        $categories = [
            'Travel',
            'Numbers',
            'Fruit',
            'Animals',
            'Body',
            'Colors',
            'School',
            'Weather',
        ];
        $category_ids = [];
        $word_ids_by_label = [];

        foreach ($categories as $label) {
            $category_term = wp_insert_term($label . ' ' . wp_generate_password(4, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);

            $category_id = (int) $category_term['term_id'];
            $category_ids[$label] = $category_id;
            $word_ids_by_label[$label] = [];
            update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
            update_term_meta($category_id, 'll_quiz_option_type', $option_type);

            for ($word_index = 1; $word_index <= 5; $word_index++) {
                $word_ids_by_label[$label][] = $this->createWordWithAudio(
                    $label . ' Word ' . $word_index,
                    $label . ' Translation ' . $word_index,
                    $category_id,
                    $wordset_id,
                    sanitize_title($label) . '-' . $word_index . '.mp3'
                );
            }

            $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
                ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
                : $category_id;

            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => $label . ' Lesson ' . wp_generate_password(4, false),
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);
        }

        update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordset_id, 'll_wordset_category_manual_order', [
            $category_ids['Travel'],
            $category_ids['Numbers'],
            $category_ids['Fruit'],
            $category_ids['Animals'],
            $category_ids['Body'],
            $category_ids['Colors'],
            $category_ids['School'],
            $category_ids['Weather'],
        ]);

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
            'word_ids_by_label' => $word_ids_by_label,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    private function seedWordProgressRow(int $user_id, int $word_id, int $category_id, int $wordset_id, array $overrides): void
    {
        global $wpdb;

        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'current_correct_streak' => 0,
            'mastery_unlocked' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data, [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%d', '%d',
            '%s', '%s',
        ]);

        $this->assertNotFalse($inserted);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        preg_match('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode((string) $matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
