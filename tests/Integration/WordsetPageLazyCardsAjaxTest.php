<?php
declare(strict_types=1);

final class WordsetPageLazyCardsAjaxTest extends LL_Tools_TestCase
{
    public function test_main_wordset_route_localizes_lazy_cards_for_empty_view(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $this->assertStringContainsString('data-ll-wordset-lazy-root', $html);

            $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
            $this->assertNotSame('', $localized);
            $this->assertStringContainsString('var llWordsetPageData = ', $localized);

            $config = $this->extractLocalizedConfig($localized);
            $this->assertSame('main', (string) ($config['view'] ?? ''));
            $this->assertIsArray($config['lazyCards'] ?? null);
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));
            $this->assertSame(6, (int) ($config['lazyCards']['batchSize'] ?? 0));
            $this->assertGreaterThan(0, (int) ($config['lazyCards']['loaded'] ?? 0));
            $this->assertGreaterThan((int) ($config['lazyCards']['loaded'] ?? 0), (int) ($config['lazyCards']['total'] ?? 0));
            $this->assertNotSame('', (string) ($config['lazyCards']['token'] ?? ''));
            $this->assertSame(6, (int) ($config['lazyCards']['shellBaseOffset'] ?? 0));
            $this->assertIsArray($config['lazyCards']['shells'] ?? null);
            $this->assertNotEmpty($config['lazyCards']['shells']);

            $shells = array_values((array) ($config['lazyCards']['shells'] ?? []));
            $first_shell = isset($shells[0]) && is_array($shells[0]) ? $shells[0] : [];
            $this->assertSame('category', (string) ($first_shell['type'] ?? ''));
            $this->assertSame(['type', 'id'], array_keys($first_shell));
            $category_lookup = [];
            foreach ((array) ($config['categories'] ?? []) as $category_config) {
                if (is_array($category_config) && (int) ($category_config['id'] ?? 0) > 0) {
                    $category_lookup[(int) $category_config['id']] = $category_config;
                }
            }
            $referenced_category = $category_lookup[(int) ($first_shell['id'] ?? 0)] ?? [];
            $this->assertStringContainsString('Lazy Ajax Category G', (string) ($referenced_category['name'] ?? ''));
            $this->assertSame(5, (int) ($referenced_category['count'] ?? 0));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_runtime_category_payload_omits_defaults_without_losing_explicit_state(): void
    {
        $base = [
            'id' => 123,
            'wordset_id' => 77,
            'default_order' => 4,
            'slug' => 'sparse-category',
            'name' => 'Sparse Category',
            'translation' => 'Sparse Category',
            'count' => 12,
            'url' => 'https://example.test/wordset/sparse-category/',
            'card_reference_url' => '',
            'card_reference_label' => '',
            'mode' => 'image',
            'prompt_type' => 'audio',
            'option_type' => 'image',
            'learning_prompt_type' => '',
            'learning_option_type' => '',
            'learning_supported' => true,
            'self_check_supported' => true,
            'sign_language_mode' => false,
            'gender_supported' => false,
            'is_public' => true,
            'public_note' => '',
            'public_note_label' => '',
            'word_image_count' => 0,
            'prompt_card_count' => 0,
            'content_count' => 0,
            'can_manage_inactive' => false,
            'can_hide' => false,
            'can_preview' => false,
            'can_delete' => false,
            'delete_reason' => '',
            'deletion_status' => '',
            'deletion_progress' => [],
            'deletion_message' => '',
            'inactive_action_nonce' => '',
            'inactive_action_url' => '',
            'inactive_preview_url' => '',
            'inactive_link_allowed' => false,
            'is_virtual_category' => false,
            'virtual_category_type' => '',
            'aspect_bucket' => 'no-image',
            'hidden' => false,
            'has_images' => false,
            'preview_deferred' => true,
            'preview_limit' => 2,
            'preview_requires_images' => true,
            'preview_aspect_ratio' => '',
            'preview' => [],
            'mastered_words' => 0,
            'studied_words' => 0,
            'new_words' => 12,
            'last_seen_at' => '',
        ];

        $compact = ll_tools_wordset_page_compact_runtime_category_payload($base, 77);
        $this->assertSame(123, (int) ($compact['id'] ?? 0));
        $this->assertSame(4, (int) ($compact['default_order'] ?? -1));
        $this->assertSame('Sparse Category', (string) ($compact['name'] ?? ''));
        $this->assertTrue((bool) ($compact['preview_deferred'] ?? false));
        $this->assertTrue((bool) ($compact['preview_requires_images'] ?? false));
        foreach ([
            'wordset_id', 'translation', 'mode', 'prompt_type', 'option_type',
            'card_reference_url', 'card_reference_label',
            'learning_supported', 'self_check_supported', 'is_public',
            'word_image_count', 'prompt_card_count', 'content_count',
            'can_delete', 'deletion_progress', 'preview_limit', 'preview',
            'mastered_words', 'studied_words', 'new_words', 'last_seen_at',
        ] as $omitted_key) {
            $this->assertArrayNotHasKey($omitted_key, $compact, $omitted_key);
        }

        $explicit = array_merge($base, [
            'translation' => 'Translated Category',
            'learning_supported' => false,
            'self_check_supported' => false,
            'is_public' => false,
            'can_delete' => true,
            'deletion_status' => 'running',
            'deletion_progress' => ['processed' => 3, 'total' => 12, 'percent' => 25],
            'deletion_message' => 'Deleting category',
            'inactive_action_nonce' => 'nonce-value',
            'inactive_action_url' => 'https://example.test/action',
            'card_reference_url' => '/extended-reference/',
            'card_reference_label' => 'Extended reference',
            'preview' => [['type' => 'text', 'label' => 'Preview']],
            'mastered_words' => 2,
            'studied_words' => 5,
            'new_words' => 0,
            'last_seen_at' => '2026-07-14 08:00:00',
        ]);
        $explicit_compact = ll_tools_wordset_page_compact_runtime_category_payload($explicit, 77);

        $this->assertSame('Translated Category', $explicit_compact['translation'] ?? '');
        $this->assertFalse((bool) ($explicit_compact['learning_supported'] ?? true));
        $this->assertFalse((bool) ($explicit_compact['self_check_supported'] ?? true));
        $this->assertFalse((bool) ($explicit_compact['is_public'] ?? true));
        $this->assertTrue((bool) ($explicit_compact['can_delete'] ?? false));
        $this->assertSame('running', $explicit_compact['deletion_status'] ?? '');
        $this->assertSame(25, (int) ($explicit_compact['deletion_progress']['percent'] ?? 0));
        $this->assertSame(0, (int) ($explicit_compact['new_words'] ?? -1));
        $this->assertSame('/extended-reference/', $explicit_compact['card_reference_url'] ?? '');
        $this->assertSame('Extended reference', $explicit_compact['card_reference_label'] ?? '');
        $this->assertNotEmpty($explicit_compact['preview'] ?? []);

        $foreign_scope = ll_tools_wordset_page_compact_runtime_category_payload($base, 88);
        $this->assertSame(77, (int) ($foreign_scope['wordset_id'] ?? 0));

        $empty_fallbacks = ll_tools_wordset_page_compact_runtime_category_payload(array_merge($base, [
            'wordset_id' => 0,
            'translation' => '',
            'deletion_progress' => ['processed' => 0, 'total' => 0, 'percent' => 0],
        ]), 77);
        $this->assertArrayNotHasKey('wordset_id', $empty_fallbacks);
        $this->assertArrayNotHasKey('translation', $empty_fallbacks);
        $this->assertArrayNotHasKey('deletion_progress', $empty_fallbacks);
    }

    public function test_large_runtime_category_registry_stays_within_sparse_payload_budget(): void
    {
        $categories = [];
        for ($index = 0; $index < 209; $index++) {
            $count = 5 + ($index % 20);
            $categories[] = ll_tools_wordset_page_compact_runtime_category_payload([
                'id' => 1000 + $index,
                'wordset_id' => 77,
                'default_order' => $index,
                'slug' => 'large-category-' . $index,
                'name' => 'Large Category ' . $index,
                'translation' => 'Large Category ' . $index,
                'count' => $count,
                'url' => 'https://example.test/wordset/large-category-' . $index . '/',
                'mode' => 'image',
                'prompt_type' => 'audio',
                'option_type' => 'image',
                'learning_supported' => true,
                'self_check_supported' => true,
                'is_public' => true,
                'preview_deferred' => true,
                'preview_limit' => 2,
                'preview_requires_images' => true,
                'preview' => [],
                'mastered_words' => 0,
                'studied_words' => 0,
                'new_words' => $count,
            ], 77);
        }

        $json = wp_json_encode($categories);
        $this->assertIsString($json);
        $this->assertLessThan(100000, strlen((string) $json));
    }

    public function test_main_wordset_route_renders_all_deferred_cards_when_lazy_payload_cannot_persist(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
            return 6;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };
        $delete_lazy_value = static function (string $option_name): void {
            if (strpos($option_name, '_transient_ll_wsp_lazy_cards_') !== 0) {
                return;
            }

            global $wpdb;
            $wpdb->delete($wpdb->options, ['option_name' => $option_name], ['%s']);
            wp_cache_delete($option_name, 'options');
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);
        add_action('added_option', $delete_lazy_value, 10, 1);

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));

            $this->assertFalse((bool) ($config['lazyCards']['enabled'] ?? true));
            $this->assertSame('', (string) ($config['lazyCards']['token'] ?? 'missing'));
            $this->assertSame(7, (int) ($config['lazyCards']['initialCount'] ?? 0));
            $this->assertSame(7, (int) ($config['lazyCards']['loaded'] ?? 0));
            $this->assertSame(7, (int) ($config['lazyCards']['total'] ?? 0));
            $this->assertSame(0, (int) ($config['lazyCards']['remaining'] ?? -1));
            $this->assertStringContainsString('Lazy Ajax Category G', $html);
            $this->assertGreaterThanOrEqual(7, substr_count($html, 'data-cat-id="'));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
            remove_action('added_option', $delete_lazy_value, 10);
        }
    }

    public function test_ajax_rebuilds_lazy_cards_when_cached_payload_is_missing(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = [
            'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
            'token' => 'missing-token',
            'wordset_id' => $wordset_id,
            'preview_limit' => 2,
            'offset' => 1,
            'count' => 1,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }

        $this->assertArrayHasKey('success', $response);
        $this->assertTrue((bool) $response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertIsArray($response['data']);

        $data = $response['data'];
        $this->assertSame(2, (int) ($data['loaded'] ?? 0));
        $this->assertSame(2, (int) ($data['nextOffset'] ?? 0));
        $this->assertFalse((bool) ($data['hasMore'] ?? true));
        $this->assertStringContainsString('Lazy Ajax Category B', (string) ($data['html'] ?? ''));
        $this->assertStringContainsString('ll-wordset-preview-item--text', (string) ($data['html'] ?? ''));
    }

    public function test_deferred_category_card_renders_preview_loading_slots(): void
    {
        $html = ll_tools_wordset_page_render_category_card([
            'id' => 12345,
            'name' => 'Deferred Preview Category',
            'count' => 6,
            'url' => 'https://example.test/deferred-preview/',
            'preview' => [],
            'preview_limit' => 2,
            'preview_deferred' => true,
            'preview_requires_images' => true,
            'has_images' => false,
            'learning_supported' => true,
            'self_check_supported' => true,
            'gender_supported' => false,
        ]);

        $this->assertStringContainsString('Deferred Preview Category', $html);
        $this->assertSame(2, substr_count($html, 'll-wordset-preview-item--lazy-skeleton'));
        $this->assertStringNotContainsString('ll-wordset-preview-item--empty', $html);
        $this->assertStringContainsString('ll-wordset-card__quiz-btn', $html);
    }

    public function test_category_card_reference_is_safe_sparse_and_not_rendered_for_virtual_cards(): void
    {
        $this->assertSame(
            '/most-common-words/?view=reference#top',
            ll_tools_sanitize_category_card_reference_url('/most-common-words/?view=reference#top')
        );
        $this->assertSame(
            'https://example.test/reference/',
            ll_tools_sanitize_category_card_reference_url('https://example.test/reference/')
        );
        $this->assertSame('', ll_tools_sanitize_category_card_reference_url('//example.test/reference/'));
        $this->assertSame('', ll_tools_sanitize_category_card_reference_url('javascript:alert(1)'));
        $this->assertSame('', ll_tools_sanitize_category_card_reference_url('mailto:editor@example.test'));

        $category = wp_insert_term('Reference Card ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        update_term_meta(
            $category_id,
            LL_TOOLS_CATEGORY_CARD_REFERENCE_URL_META_KEY,
            '/most-common-words/'
        );
        update_term_meta(
            $category_id,
            LL_TOOLS_CATEGORY_CARD_REFERENCE_LABEL_META_KEY,
            '1000+ word reference'
        );
        $reference = ll_tools_get_category_card_reference_link($category_id);
        $this->assertSame('/most-common-words/', $reference['url']);
        $this->assertSame('1000+ word reference', $reference['label']);

        $card = [
            'id' => $category_id,
            'name' => 'Most Common Words',
            'count' => 6,
            'url' => 'https://example.test/vocab/most-common-words/',
            'card_reference_url' => $reference['url'],
            'card_reference_label' => $reference['label'],
            'preview' => [],
            'preview_limit' => 2,
            'learning_supported' => true,
            'self_check_supported' => true,
        ];
        $html = ll_tools_wordset_page_render_category_card($card);
        $this->assertSame(1, substr_count($html, 'll-wordset-card__reference-link'));
        $this->assertStringContainsString('href="/most-common-words/"', $html);
        $this->assertStringContainsString('1000+ word reference', $html);

        $virtual_html = ll_tools_wordset_page_render_category_card(array_merge($card, [
            'is_virtual_category' => true,
            'virtual_category_type' => 'uncategorized',
        ]));
        $this->assertStringNotContainsString('ll-wordset-card__reference-link', $virtual_html);

        $unsafe_html = ll_tools_wordset_page_render_category_card(array_merge($card, [
            'card_reference_url' => 'javascript:alert(1)',
        ]));
        $this->assertStringNotContainsString('ll-wordset-card__reference-link', $unsafe_html);
    }

    public function test_guest_ajax_rejects_missing_lazy_cards_payload_instead_of_rebuilding_by_wordset_id(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        wp_set_current_user(0);

        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = [
            'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
            'token' => 'missing-token',
            'wordset_id' => $wordset_id,
            'preview_limit' => 99,
            'offset' => 1,
            'count' => 1,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }

        $this->assertArrayHasKey('success', $response);
        $this->assertFalse((bool) $response['success']);
        $this->assertStringContainsString('Could not load more cards', (string) ($response['data']['message'] ?? ''));
    }

    public function test_guest_main_view_reuses_shared_payload_tokens_for_same_context(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $first_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $first_token = (string) ($first_config['lazyCards']['token'] ?? '');
            $first_search_token = (string) ($first_config['categorySearch']['token'] ?? '');

            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $second_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $second_token = (string) ($second_config['lazyCards']['token'] ?? '');
            $second_search_token = (string) ($second_config['categorySearch']['token'] ?? '');

            $this->assertStringStartsWith('shared_', $first_token);
            $this->assertSame($first_token, $second_token);
            $this->assertStringStartsWith('search_', $first_search_token);
            $this->assertSame($first_search_token, $second_search_token);
            $this->assertTrue(ll_tools_wordset_page_lazy_cards_ajax_cache_enabled($first_token));
            $this->assertTrue(ll_tools_wordset_page_category_search_ajax_cache_enabled($first_search_token));

            $payload = ll_tools_wordset_page_get_lazy_cards_payload($first_token);
            $this->assertIsArray($payload);
            $this->assertSame(0, (int) ($payload['user_id'] ?? -1));
            $this->assertSame(6, (int) ($payload['base_offset'] ?? 0));
            $this->assertSame(7, (int) ($payload['total'] ?? 0));
            $this->assertCount(1, (array) ($payload['cards'] ?? []));

            $payload_cards = array_values((array) ($payload['cards'] ?? []));
            $payload_card = isset($payload_cards[0]) && is_array($payload_cards[0]) ? $payload_cards[0] : [];
            $payload_category = isset($payload_card['data']) && is_array($payload_card['data']) ? $payload_card['data'] : [];
            $this->assertTrue((bool) ($payload_category['preview_deferred'] ?? false));
            $this->assertSame([], (array) ($payload_category['preview'] ?? []));

            $search_payload = ll_tools_wordset_page_get_category_search_payload($first_search_token);
            $this->assertIsArray($search_payload);
            $this->assertSame(0, (int) ($search_payload['user_id'] ?? -1));
            $this->assertSame($wordset_id, (int) ($search_payload['wordset_id'] ?? 0));

            $lazy_dependency_floor = ll_tools_wordset_page_public_static_dependency_ttl(
                30 * MINUTE_IN_SECONDS,
                'lazy_cards'
            );
            $search_dependency_floor = ll_tools_wordset_page_public_static_dependency_ttl(
                30 * MINUTE_IN_SECONDS,
                'category_search'
            );
            $this->assertGreaterThanOrEqual(
                time() + $lazy_dependency_floor - 5,
                (int) get_option('_transient_timeout_' . ll_tools_wordset_page_lazy_cards_cache_key($first_token), 0)
            );
            $this->assertGreaterThanOrEqual(
                time() + $search_dependency_floor - 5,
                (int) get_option('_transient_timeout_' . ll_tools_wordset_page_category_search_payload_cache_key($first_search_token), 0)
            );

            $original_post = $_POST;
            $original_request = $_REQUEST;
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $first_token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 1,
            ];
            $_REQUEST = $_POST;
            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_wordset_page_handle_lazy_cards_ajax();
                });
            } finally {
                $_POST = $original_post;
                $_REQUEST = $original_request;
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertStringContainsString('Lazy Ajax Category G', (string) ($response['data']['html'] ?? ''));
            $this->assertStringContainsString('ll-wordset-preview-item--text', (string) ($response['data']['html'] ?? ''));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_authenticated_main_view_reuses_one_private_transient_per_payload_context(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
            return 6;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };
        $short_ttl_filter = static function (): int {
            return MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);
        add_filter('ll_tools_wordset_page_lazy_cards_cache_ttl', $short_ttl_filter);
        add_filter('ll_tools_wordset_page_category_search_payload_cache_ttl', $short_ttl_filter);

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $lazy_options_before = $this->getTransientOptionNamesForNamespace('lazy_cards');
            $search_options_before = $this->getTransientOptionNamesForNamespace('category_search_payload');

            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $first_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $first_lazy_token = (string) ($first_config['lazyCards']['token'] ?? '');
            $first_search_token = (string) ($first_config['categorySearch']['token'] ?? '');
            $lazy_options_after_first = $this->getTransientOptionNamesForNamespace('lazy_cards');
            $search_options_after_first = $this->getTransientOptionNamesForNamespace('category_search_payload');

            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $second_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $second_lazy_token = (string) ($second_config['lazyCards']['token'] ?? '');
            $second_search_token = (string) ($second_config['categorySearch']['token'] ?? '');
            $lazy_options_after_second = $this->getTransientOptionNamesForNamespace('lazy_cards');
            $search_options_after_second = $this->getTransientOptionNamesForNamespace('category_search_payload');

            $this->assertStringStartsWith('private_', $first_lazy_token);
            $this->assertStringStartsWith('private_', $first_search_token);
            $this->assertSame($first_lazy_token, $second_lazy_token);
            $this->assertSame($first_search_token, $second_search_token);
            $this->assertCount(2, array_values(array_diff($lazy_options_after_first, $lazy_options_before)));
            $this->assertCount(2, array_values(array_diff($search_options_after_first, $search_options_before)));
            $this->assertSame($lazy_options_after_first, $lazy_options_after_second);
            $this->assertSame($search_options_after_first, $search_options_after_second);

            $lazy_payload = ll_tools_wordset_page_get_lazy_cards_payload($first_lazy_token);
            $search_payload = ll_tools_wordset_page_get_category_search_payload($first_search_token);
            $this->assertIsArray($lazy_payload);
            $this->assertIsArray($search_payload);
            $this->assertSame($user_id, (int) ($lazy_payload['user_id'] ?? 0));
            $this->assertSame($user_id, (int) ($search_payload['user_id'] ?? 0));
            $this->assertFalse(ll_tools_wordset_page_lazy_cards_ajax_cache_enabled($first_lazy_token));
            $this->assertFalse(ll_tools_wordset_page_category_search_ajax_cache_enabled($first_search_token));

            $lazy_timeout = (int) get_option(
                '_transient_timeout_' . ll_tools_wordset_page_lazy_cards_cache_key($first_lazy_token),
                0
            );
            $search_timeout = (int) get_option(
                '_transient_timeout_' . ll_tools_wordset_page_category_search_payload_cache_key($first_search_token),
                0
            );
            $this->assertGreaterThan(time(), $lazy_timeout);
            $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS + 5, $lazy_timeout);
            $this->assertGreaterThan(time(), $search_timeout);
            $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS + 5, $search_timeout);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
            remove_filter('ll_tools_wordset_page_lazy_cards_cache_ttl', $short_ttl_filter);
            remove_filter('ll_tools_wordset_page_category_search_payload_cache_ttl', $short_ttl_filter);
        }
    }

    public function test_authenticated_payload_tokens_are_scoped_to_user_and_wordset_and_reject_cross_user_ajax(): void
    {
        $first_fixture = $this->createWordsetFixture(7);
        $second_fixture = $this->createWordsetFixture(7);
        $first_wordset_id = (int) $first_fixture['wordset_id'];
        $second_wordset_id = (int) $second_fixture['wordset_id'];
        $first_wordset_term = get_term($first_wordset_id, 'wordset');
        $second_wordset_term = get_term($second_wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $first_wordset_term);
        $this->assertInstanceOf(WP_Term::class, $second_wordset_term);

        $first_user_id = self::factory()->user->create(['role' => 'administrator']);
        $second_user_id = self::factory()->user->create(['role' => 'administrator']);
        $original_get = $_GET;
        $original_cookie = $_COOKIE;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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
        $_GET = [];
        set_query_var('ll_wordset_view', '');

        try {
            wp_set_current_user($first_user_id);
            set_query_var('ll_wordset_page', (string) $first_wordset_term->slug);
            ll_tools_render_wordset_page_content($first_wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $first_user_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $first_user_lazy_token = (string) ($first_user_config['lazyCards']['token'] ?? '');
            $first_user_search_token = (string) ($first_user_config['categorySearch']['token'] ?? '');

            $_COOKIE[ll_tools_wordset_page_get_main_sort_cookie_name($first_wordset_id)] = 'alpha-desc';
            ll_tools_render_wordset_page_content($first_wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $sorted_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $sorted_lazy_token = (string) ($sorted_config['lazyCards']['token'] ?? '');
            $sorted_search_token = (string) ($sorted_config['categorySearch']['token'] ?? '');
            $this->assertNotSame($first_user_lazy_token, $sorted_lazy_token);
            $this->assertSame($first_user_search_token, $sorted_search_token);
            unset($_COOKIE[ll_tools_wordset_page_get_main_sort_cookie_name($first_wordset_id)]);

            wp_set_current_user($second_user_id);
            $lazy_response = $this->postLazyCardsAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $first_user_lazy_token,
                'wordset_id' => $first_wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 1,
            ]);
            $search_response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $first_user_search_token,
                'wordset_id' => $first_wordset_id,
                'query' => 'lazy',
            ]);
            $this->assertFalse((bool) ($lazy_response['success'] ?? true));
            $this->assertFalse((bool) ($search_response['success'] ?? true));
            $this->assertStringContainsString('Could not load more cards', (string) ($lazy_response['data']['message'] ?? ''));
            $this->assertStringContainsString('Could not search this word set', (string) ($search_response['data']['message'] ?? ''));

            ll_tools_render_wordset_page_content($first_wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $second_user_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $second_user_lazy_token = (string) ($second_user_config['lazyCards']['token'] ?? '');
            $second_user_search_token = (string) ($second_user_config['categorySearch']['token'] ?? '');

            $this->assertNotSame($first_user_lazy_token, $second_user_lazy_token);
            $this->assertNotSame($first_user_search_token, $second_user_search_token);

            wp_set_current_user($first_user_id);
            set_query_var('ll_wordset_page', (string) $second_wordset_term->slug);
            ll_tools_render_wordset_page_content($second_wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $second_wordset_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $second_wordset_lazy_token = (string) ($second_wordset_config['lazyCards']['token'] ?? '');
            $second_wordset_search_token = (string) ($second_wordset_config['categorySearch']['token'] ?? '');

            $this->assertNotSame($first_user_lazy_token, $second_wordset_lazy_token);
            $this->assertNotSame($first_user_search_token, $second_wordset_search_token);
        } finally {
            $_GET = $original_get;
            $_COOKIE = $original_cookie;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_ajax_caps_requested_lazy_card_count_to_configured_batch_size(): void
    {
        $fixture = $this->createWordsetFixture(14);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));

            $original_post = $_POST;
            $original_request = $_REQUEST;
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => (string) ($config['lazyCards']['token'] ?? ''),
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 96,
            ];
            $_REQUEST = $_POST;
            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_wordset_page_handle_lazy_cards_ajax();
                });
            } finally {
                $_POST = $original_post;
                $_REQUEST = $original_request;
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertSame(12, (int) ($response['data']['loaded'] ?? 0));
            $this->assertSame(12, (int) ($response['data']['nextOffset'] ?? 0));
            $this->assertTrue((bool) ($response['data']['hasMore'] ?? false));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_ajax_hydrates_lazy_content_lesson_by_requested_id(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->assertGreaterThanOrEqual(7, count($category_ids));

        $content_lesson_id = $this->createContentLesson(
            $wordset_id,
            'Lazy Content Search Story',
            [(int) $category_ids[6]],
            [(int) $category_ids[6]]
        );

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $this->assertStringNotContainsString('Lazy Content Search Story', $html);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));
            $this->assertIsArray($config['lazyCards']['contentShells'] ?? null);
            $this->assertContains(
                $content_lesson_id,
                array_map('intval', wp_list_pluck((array) ($config['lazyCards']['contentShells'] ?? []), 'id'))
            );

            $original_post = $_POST;
            $original_request = $_REQUEST;
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => (string) ($config['lazyCards']['token'] ?? ''),
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'content_ids' => (string) $content_lesson_id,
            ];
            $_REQUEST = $_POST;
            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_wordset_page_handle_lazy_cards_ajax();
                });
            } finally {
                $_POST = $original_post;
                $_REQUEST = $original_request;
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertSame([$content_lesson_id], array_map('intval', (array) ($response['data']['contentIds'] ?? [])));
            $this->assertStringContainsString('Lazy Content Search Story', (string) ($response['data']['html'] ?? ''));
            $this->assertStringContainsString('data-lesson-id="' . $content_lesson_id . '"', (string) ($response['data']['html'] ?? ''));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_guest_ajax_canonicalizes_requested_ids_before_public_cache_lookup(): void
    {
        $fixture = $this->createWordsetFixture(10);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->assertGreaterThanOrEqual(10, count($category_ids));

        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $token = (string) ($config['lazyCards']['token'] ?? '');
            $shell_ids = array_values(array_map('intval', wp_list_pluck((array) ($config['lazyCards']['shells'] ?? []), 'id')));
            $this->assertGreaterThanOrEqual(3, count($shell_ids));
            $requested_ids = [
                (int) $shell_ids[2],
                999999,
                (int) $shell_ids[0],
                (int) $shell_ids[2],
            ];
            $expected_ids = [
                (int) $shell_ids[0],
                (int) $shell_ids[2],
            ];

            $original_post = $_POST;
            $original_request = $_REQUEST;
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'category_ids' => implode(',', $requested_ids),
                'content_ids' => '777777,777777',
            ];
            $_REQUEST = $_POST;
            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_wordset_page_handle_lazy_cards_ajax();
                });
            } finally {
                $_POST = $original_post;
                $_REQUEST = $original_request;
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertSame($expected_ids, array_map('intval', (array) ($response['data']['categoryIds'] ?? [])));
            $this->assertSame([], array_map('intval', (array) ($response['data']['contentIds'] ?? [])));
            $this->assertStringContainsString('Lazy Ajax Category G', (string) ($response['data']['html'] ?? ''));
            $this->assertStringContainsString('Lazy Ajax Category I', (string) ($response['data']['html'] ?? ''));

            $canonical_cache_args = [
                'mode' => 'ids',
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 0,
                'count' => 0,
                'category_ids' => $expected_ids,
                'content_ids' => [],
            ];
            $this->assertSame($response['data'], ll_tools_wordset_page_lazy_cards_ajax_cache_get($canonical_cache_args));
            $this->assertFalse(get_option(ll_tools_wordset_page_lazy_cards_ajax_cache_lock_option($canonical_cache_args), false));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_guest_ajax_caps_requested_id_lists_before_hydration(): void
    {
        $fixture = $this->createWordsetFixture(10);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_ids = array_values(array_map('intval', (array) ($fixture['category_ids'] ?? [])));
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->assertGreaterThanOrEqual(10, count($category_ids));

        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
            return 6;
        };
        $request_cap_filter = static function (): int {
            return 2;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_lazy_cards_request_id_cap', $request_cap_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $token = (string) ($config['lazyCards']['token'] ?? '');
            $shell_ids = array_values(array_map('intval', wp_list_pluck((array) ($config['lazyCards']['shells'] ?? []), 'id')));
            $this->assertGreaterThanOrEqual(4, count($shell_ids));
            $requested_ids = [
                (int) $shell_ids[3],
                (int) $shell_ids[0],
                (int) $shell_ids[2],
                (int) $shell_ids[1],
            ];
            $expected_ids = [
                (int) $shell_ids[0],
                (int) $shell_ids[1],
            ];

            $original_post = $_POST;
            $original_request = $_REQUEST;
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'category_ids' => implode(',', $requested_ids),
            ];
            $_REQUEST = $_POST;
            try {
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_wordset_page_handle_lazy_cards_ajax();
                });
            } finally {
                $_POST = $original_post;
                $_REQUEST = $original_request;
            }

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertSame($expected_ids, array_map('intval', (array) ($response['data']['categoryIds'] ?? [])));
            $this->assertStringContainsString('Lazy Ajax Category G', (string) ($response['data']['html'] ?? ''));
            $this->assertStringContainsString('Lazy Ajax Category H', (string) ($response['data']['html'] ?? ''));
            $this->assertStringNotContainsString('Lazy Ajax Category I', (string) ($response['data']['html'] ?? ''));
            $this->assertStringNotContainsString('Lazy Ajax Category J', (string) ($response['data']['html'] ?? ''));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_lazy_cards_request_id_cap', $request_cap_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_guest_ajax_throttles_repeated_public_cache_misses(): void
    {
        $fixture = $this->createWordsetFixture(10);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        $batch_size_filter = static function (): int {
            return 6;
        };
        $token_limit_filter = static function (): int {
            return 1;
        };
        $ip_limit_filter = static function (): int {
            return 100;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_lazy_cards_cache_miss_token_limit', $token_limit_filter);
        add_filter('ll_tools_wordset_page_lazy_cards_cache_miss_ip_limit', $ip_limit_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $token = (string) ($config['lazyCards']['token'] ?? '');

            $first_response = $this->postLazyCardsAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 1,
            ]);
            $this->assertTrue((bool) ($first_response['success'] ?? false));

            $second_response = $this->postLazyCardsAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 2,
            ]);

            $this->assertFalse((bool) ($second_response['success'] ?? true));
            $this->assertStringContainsString('Too many card loading requests', (string) ($second_response['data']['message'] ?? ''));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            if ($original_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
            }
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_lazy_cards_cache_miss_token_limit', $token_limit_filter);
            remove_filter('ll_tools_wordset_page_lazy_cards_cache_miss_ip_limit', $ip_limit_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_guest_ajax_skips_expensive_lazy_card_rebuild_when_response_cache_lock_is_held(): void
    {
        $fixture = $this->createWordsetFixture(10);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        wp_set_current_user(0);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');

        $batch_size_filter = static function (): int {
            return 6;
        };
        $wait_filter = static function (): int {
            return 0;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_lazy_cards_ajax_cache_build_wait_ms', $wait_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        $cache_args = [];
        $cache_key = '';
        $lock_option = '';

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $token = (string) ($config['lazyCards']['token'] ?? '');

            $cache_args = [
                'mode' => 'offset',
                'token' => $token,
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 2,
                'category_ids' => [],
                'content_ids' => [],
            ];
            $cache_key = ll_tools_wordset_page_lazy_cards_ajax_cache_key($cache_args);
            $lock_option = ll_tools_wordset_page_lazy_cards_ajax_cache_lock_option($cache_args);

            wp_cache_delete($cache_key, 'll_tools');
            delete_transient($cache_key);
            delete_option($lock_option);
            add_option($lock_option, (string) (time() + 30), '', false);

            $word_preview_query_count = 0;
            $query_counter = static function (WP_Query $query) use (&$word_preview_query_count): void {
                $post_type = $query->get('post_type');
                $post_types = is_array($post_type) ? $post_type : [$post_type];
                if (in_array('words', array_map('strval', $post_types), true)) {
                    $word_preview_query_count++;
                }
            };

            add_action('pre_get_posts', $query_counter);
            try {
                $response = $this->postLazyCardsAjax([
                    'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
                    'token' => $token,
                    'wordset_id' => $wordset_id,
                    'preview_limit' => 2,
                    'offset' => 6,
                    'count' => 2,
                ]);
            } finally {
                remove_action('pre_get_posts', $query_counter);
            }

            $this->assertFalse((bool) ($response['success'] ?? true));
            $this->assertStringContainsString('Card previews are still being prepared', (string) ($response['data']['message'] ?? ''));
            $this->assertNull(ll_tools_wordset_page_lazy_cards_ajax_cache_get($cache_args));
            $this->assertSame(0, $word_preview_query_count);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            if ($cache_key !== '') {
                wp_cache_delete($cache_key, 'll_tools');
                delete_transient($cache_key);
            }
            if ($lock_option !== '') {
                delete_option($lock_option);
            }
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_lazy_cards_ajax_cache_build_wait_ms', $wait_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_guest_lazy_cards_ajax_response_cache_uses_shared_token_and_epochs(): void
    {
        wp_set_current_user(0);

        $args = [
            'token' => 'shared_' . str_repeat('a', 32),
            'wordset_id' => 123,
            'preview_limit' => 2,
            'offset' => 6,
            'count' => 6,
        ];
        $payload = [
            'html' => '<article>Cached lazy card</article>',
            'loaded' => 12,
            'nextOffset' => 12,
            'hasMore' => false,
        ];

        $this->assertNull(ll_tools_wordset_page_lazy_cards_ajax_cache_get($args));

        ll_tools_wordset_page_lazy_cards_ajax_cache_set($args, $payload);
        $this->assertSame($payload, ll_tools_wordset_page_lazy_cards_ajax_cache_get($args));

        ll_tools_bump_category_cache_epoch();
        $this->assertNull(ll_tools_wordset_page_lazy_cards_ajax_cache_get($args));

        $uncacheable_args = array_merge($args, ['token' => 'missing-token']);
        ll_tools_wordset_page_lazy_cards_ajax_cache_set($uncacheable_args, $payload);
        $this->assertNull(ll_tools_wordset_page_lazy_cards_ajax_cache_get($uncacheable_args));
    }

    /**
     * @return array{wordset_id:int}
     */
    private function createWordsetFixture(int $category_count = 2): array
    {
        $category_count = max(2, $category_count);
        $wordset = wp_insert_term('Lazy Ajax Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category_ids = [];

        for ($category_index = 0; $category_index < $category_count; $category_index++) {
            $letter = chr(ord('A') + $category_index);
            $category_term = wp_insert_term('Lazy Ajax Category ' . $letter . ' ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);

            $category_id = (int) $category_term['term_id'];
            $category_ids[] = $category_id;
            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

            for ($word_index = 1; $word_index <= 5; $word_index++) {
                $slug = strtolower($letter);
                $this->createWordWithAudio(
                    'Lazy Ajax ' . $letter . ' Word ' . $word_index,
                    'Lazy Ajax ' . $letter . ' Translation ' . $word_index,
                    $category_id,
                    $wordset_id,
                    'lazy-ajax-' . $slug . '-' . $word_index . '.mp3'
                );
            }

            $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
                ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
                : $category_id;

            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => 'Lazy Ajax Lesson ' . $letter . ' ' . wp_generate_password(4, false),
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);
        }

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
        ];
    }

    private function createContentLesson(int $wordset_id, string $title, array $category_ids, array $prereq_category_ids = []): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_excerpt' => 'A searchable lazy content lesson.',
        ]);

        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'standard');
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, array_values(array_map('intval', $category_ids)));
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
        if (!empty($prereq_category_ids)) {
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META, array_values(array_map('intval', $prereq_category_ids)));
        }

        return (int) $lesson_id;
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

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        preg_match_all('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertNotEmpty($matches[1]);

        $latest_config = end($matches[1]);
        $this->assertIsString($latest_config);

        $decoded = json_decode($latest_config, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return string[]
     */
    private function getTransientOptionNamesForNamespace(string $namespace): array
    {
        global $wpdb;

        $cache_prefix = 'll_wsp_' . sanitize_key($namespace) . '_';
        $value_pattern = $wpdb->esc_like('_transient_' . $cache_prefix) . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_' . $cache_prefix) . '%';
        $option_names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
                OR option_name LIKE %s
             ORDER BY option_name ASC",
            $value_pattern,
            $timeout_pattern
        ));

        return array_values(array_map('strval', (array) $option_names));
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postLazyCardsAjax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $_POST;

        try {
            return $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postCategorySearchAjax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $_POST;

        try {
            return $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_category_search_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
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
