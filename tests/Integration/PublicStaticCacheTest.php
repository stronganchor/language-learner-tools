<?php
declare(strict_types=1);

final class PublicStaticCacheTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_public_static_cache_reset_purge_once_state')) {
            ll_tools_public_static_cache_reset_purge_once_state();
        }
        if (function_exists('ll_tools_purge_dictionary_static_cache')) {
            ll_tools_purge_dictionary_static_cache();
        }
        if (function_exists('ll_tools_purge_public_static_cache')) {
            ll_tools_purge_public_static_cache();
        }
        if (function_exists('ll_tools_cloudflare_static_cache_reset_purge_once_state')) {
            ll_tools_cloudflare_static_cache_reset_purge_once_state();
        }
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        set_query_var('ll_wordset_page', '');
        set_query_var('ll_wordset_view', '');
        if (function_exists('ll_tools_purge_public_static_cache')) {
            ll_tools_purge_public_static_cache();
        }
        if (function_exists('ll_tools_purge_dictionary_static_cache')) {
            ll_tools_purge_dictionary_static_cache();
        }
        if (function_exists('ll_tools_public_static_cache_reset_purge_once_state')) {
            ll_tools_public_static_cache_reset_purge_once_state();
        }
        if (function_exists('ll_tools_cloudflare_static_cache_reset_purge_once_state')) {
            ll_tools_cloudflare_static_cache_reset_purge_once_state();
        }
        parent::tearDown();
    }

    public function test_public_static_cache_key_ignores_tracking_and_auth_noise(): void
    {
        $identity = [
            'type' => 'wordset_main',
            'id' => 17,
            'path' => '/dersler',
            'wordset_id' => 17,
        ];

        $first = ll_tools_public_static_cache_key($identity, [
            'll_locale' => 'tr_TR',
            'll_locale_nonce' => 'first',
            'll_tools_auth' => 'login',
            'll_wordset_back' => home_url('/?ll_tools_auth=register'),
            'utm_source' => 'crawler',
            'fbclid' => 'abc',
        ], 'tr_TR');
        $second = ll_tools_public_static_cache_key($identity, [
            'fbclid' => 'def',
            'utm_source' => 'other',
            'll_tools_auth' => 'register',
            'll_wordset_back' => home_url('/?ll_tools_auth=login'),
            'll_locale_nonce' => 'second',
            'll_locale' => 'tr_TR',
        ], 'tr_TR');

        $this->assertSame($first, $second);
        $this->assertNotSame(
            $first,
            ll_tools_public_static_cache_key($identity, ['ll_locale' => 'en_US'], 'en_US')
        );
        $this->assertNotSame(
            $first,
            ll_tools_public_static_cache_key($identity, [
                'll_locale' => 'tr_TR',
                'll_text_view' => 'interlinear',
            ], 'tr_TR')
        );
        $this->assertNotSame(
            $first,
            ll_tools_public_static_cache_key($identity, [
                'll_locale' => 'tr_TR',
                'll_translation' => 'de',
            ], 'tr_TR')
        );
        $this->assertNotSame(
            $first,
            ll_tools_public_static_cache_key($identity, [
                'll_locale' => 'tr_TR',
                'll_book_language' => 'de',
                'll_book_section' => 'intro',
            ], 'tr_TR')
        );
        $this->assertSame([
            'll_book_language' => 'de',
            'll_book_section' => 'intro',
            'll_locale' => 'tr_TR',
            'll_text_view' => 'sources',
            'll_translation' => 'de',
        ], ll_tools_public_static_cache_normalize_query_args([
            'll_book_language' => 'de',
            'll_book_section' => 'intro',
            'll_locale' => 'tr_TR',
            'll_text_view' => 'sources',
            'll_translation' => 'de',
            'utm_campaign' => 'ignored',
            'll_locale_nonce' => 'ignored',
            'll_wordset_back' => home_url('/?ll_tools_auth=register'),
            'unknown' => 'ignored',
        ]));
    }

    public function test_public_static_cache_identifies_only_public_wordset_main_view(): void
    {
        $term = wp_insert_term('Public Static Cache Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        $term_id = (int) ($term['term_id'] ?? 0);
        $wordset = get_term($term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/' . $wordset->slug . '/';
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset->slug);
        set_query_var('ll_wordset_view', '');
        $this->clear404Flag();

        $identity = ll_tools_public_static_cache_request_identity();
        $this->assertIsArray($identity);
        $this->assertSame('wordset_main', $identity['type']);
        $this->assertSame($term_id, $identity['id']);

        set_query_var('ll_wordset_view', 'settings');
        $this->assertNull(ll_tools_public_static_cache_request_identity());

        set_query_var('ll_wordset_view', '');
        update_term_meta($term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $this->assertNull(ll_tools_public_static_cache_request_identity());
    }

    public function test_public_static_cache_refreshes_embedded_public_nonces(): void
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Cached Vocab Lesson',
        ]);
        $identity = [
            'type' => 'vocab_lesson',
            'id' => (int) $lesson_id,
            'path' => '/lesson',
            'wordset_id' => 0,
        ];

        $lazy_nonce = wp_create_nonce('ll_tools_wordset_page_lazy_cards');
        $grid_nonce = wp_create_nonce('ll_vocab_lesson_grid_' . (int) $lesson_id);
        $stored = ll_tools_public_static_cache_prepare_html_for_storage(
            '<!doctype html><html><body><script>' . $lazy_nonce . '</script><div data-nonce="' . $grid_nonce . '"></div></body></html>',
            $identity
        );

        $this->assertStringContainsString(LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER, $stored);
        $this->assertStringContainsString(ll_tools_public_static_cache_vocab_grid_nonce_placeholder((int) $lesson_id), $stored);
        $this->assertStringNotContainsString($lazy_nonce, $stored);
        $this->assertStringNotContainsString($grid_nonce, $stored);

        $output = ll_tools_public_static_cache_prepare_html_for_output($stored, $identity);
        $this->assertStringContainsString($lazy_nonce, $output);
        $this->assertStringContainsString($grid_nonce, $output);
        $this->assertStringNotContainsString(LL_TOOLS_PUBLIC_STATIC_CACHE_WORDSET_LAZY_NONCE_PLACEHOLDER, $output);
        $this->assertStringNotContainsString(ll_tools_public_static_cache_vocab_grid_nonce_placeholder((int) $lesson_id), $output);
    }

    public function test_public_static_cache_status_defaults_unset_php_status_to_success(): void
    {
        $this->assertSame(200, ll_tools_public_static_cache_current_status_code());
        $this->assertSame(404, ll_tools_public_static_cache_status_code_from_headers([
            'Content-Type: text/html',
            'HTTP/1.1 404 Not Found',
        ]));
        $this->assertSame(503, ll_tools_public_static_cache_status_code_from_headers([
            'Status: 503 Service Unavailable',
        ]));
    }

    public function test_public_static_cache_miss_uses_no_cache_until_response_is_known(): void
    {
        $this->assertSame('no-cache, must-revalidate', ll_tools_public_static_cache_cache_control_value(false));
        $this->assertStringStartsWith('public, max-age=', ll_tools_public_static_cache_cache_control_value(true));
    }

    public function test_static_caches_do_not_cache_front_page_requests(): void
    {
        $old_show_on_front = get_option('show_on_front');
        $old_page_on_front = get_option('page_on_front');
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Static Cache Front Page',
            'post_content' => '[ll_dictionary]',
        ]);

        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);
        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        try {
            $this->go_to('/');

            $this->assertTrue(is_front_page());
            $this->assertFalse(ll_tools_is_cacheable_dictionary_request());
            $this->assertFalse(ll_tools_public_static_cache_has_safe_request_shape());
        } finally {
            update_option('show_on_front', $old_show_on_front);
            update_option('page_on_front', $old_page_on_front);
        }
    }

    public function test_public_static_cache_allows_wordset_routes_that_resolve_as_home(): void
    {
        $old_show_on_front = get_option('show_on_front');
        $old_page_on_front = get_option('page_on_front');
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Static Cache Wordset Host',
            'post_content' => '',
        ]);
        $term = wp_insert_term('Public Static Cache Home Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        $wordset = get_term((int) ($term['term_id'] ?? 0), 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);
        wp_set_current_user(0);
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/' . $wordset->slug . '/';

        try {
            $this->go_to('/');
            set_query_var('ll_wordset_page', (string) $wordset->slug);
            set_query_var('ll_wordset_view', '');

            $this->assertTrue(is_front_page() || is_home());
            $this->assertTrue(ll_tools_is_wordset_page_context());
            $this->assertTrue(ll_tools_public_static_cache_has_safe_request_shape());
        } finally {
            update_option('show_on_front', $old_show_on_front);
            update_option('page_on_front', $old_page_on_front);
            set_query_var('ll_wordset_page', '');
            set_query_var('ll_wordset_view', '');
        }
    }

    public function test_public_static_cache_store_writes_when_php_status_is_unset(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = trailingslashit($dir) . 'public-store-test.html';
        @unlink($file);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $buffer_level = ob_get_level();
        $GLOBALS['ll_tools_public_static_cache_request'] = [
            'active' => true,
            'file' => $file,
            'identity' => [
                'type' => 'wordset_main',
                'id' => 17,
                'path' => '/cached-wordset',
                'wordset_id' => 17,
            ],
            'buffer_level' => $buffer_level,
        ];

        ob_start();
        try {
            echo '<!doctype html><html><body>' . str_repeat('public cache store test ', 40) . '</body></html>';
            ll_tools_store_public_static_cache();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            unset($GLOBALS['ll_tools_public_static_cache_request']);
        }

        $this->assertFileExists($file);
        $this->assertStringContainsString('public cache store test', (string) file_get_contents($file));
    }

    public function test_public_static_cache_store_skips_oversized_payloads(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = trailingslashit($dir) . 'public-oversized-store-test.html';
        $meta_file = ll_tools_public_static_cache_meta_file_path($file);
        @unlink($file);
        @unlink($meta_file);

        $max_bytes_filter = static function (): int {
            return 600;
        };

        add_filter('ll_tools_public_static_cache_max_bytes', $max_bytes_filter);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $buffer_level = ob_get_level();
        $GLOBALS['ll_tools_public_static_cache_request'] = [
            'active' => true,
            'file' => $file,
            'identity' => [
                'type' => 'wordset_main',
                'id' => 17,
                'path' => '/oversized-cached-wordset',
                'wordset_id' => 17,
            ],
            'buffer_level' => $buffer_level,
        ];

        ob_start();
        try {
            echo '<!doctype html><html><body>' . str_repeat('oversized public cache payload ', 40) . '</body></html>';
            $this->assertGreaterThan(600, ob_get_length());
            ll_tools_store_public_static_cache();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            unset($GLOBALS['ll_tools_public_static_cache_request']);
            remove_filter('ll_tools_public_static_cache_max_bytes', $max_bytes_filter);
        }

        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($meta_file);
    }

    public function test_public_static_cache_store_runs_before_wordpress_flushes_output_buffers(): void
    {
        $this->assertSame(0, has_action('shutdown', 'll_tools_store_public_static_cache'));
    }

    public function test_public_static_cache_purge_removes_html_files(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = trailingslashit($dir) . 'public-test.html';
        $tmp = trailingslashit($dir) . 'public-test.html.tmp-abc';
        file_put_contents($file, '<!doctype html><html><body>cached</body></html>');
        file_put_contents($tmp, 'tmp');
        $this->assertFileExists($file);
        $this->assertFileExists($tmp);

        ll_tools_purge_public_static_cache();

        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($tmp);
    }

    public function test_static_cache_purge_helper_clears_dictionary_and_public_files(): void
    {
        $dictionary_dir = ll_tools_dictionary_static_cache_dir();
        $public_dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dictionary_dir);
        $this->assertNotSame('', $public_dir);
        $this->assertTrue(wp_mkdir_p($dictionary_dir));
        $this->assertTrue(wp_mkdir_p($public_dir));

        $dictionary_file = trailingslashit($dictionary_dir) . 'dictionary-helper-test.html';
        $public_file = trailingslashit($public_dir) . 'public-helper-test.html';
        file_put_contents($dictionary_file, '<!doctype html><html><body>dictionary</body></html>');
        file_put_contents($public_file, '<!doctype html><html><body>public</body></html>');
        ll_tools_public_static_cache_write_meta($public_file, 'helper-test', [
            'type' => 'wordset_main',
            'id' => 17,
            'path' => '/helper-test',
            'wordset_id' => 17,
        ]);

        $result = ll_tools_purge_static_caches();

        $this->assertSame('all', $result['target']);
        $this->assertSame(2, (int) $result['deleted']);
        $this->assertSame(1, (int) $result['caches']['dictionary']['deleted']);
        $this->assertSame(1, (int) $result['caches']['public']['deleted']);
        $this->assertIsArray($result['edge']['cloudflare'] ?? null);
        $this->assertFalse((bool) ($result['edge']['cloudflare']['attempted'] ?? true));
        $this->assertFileDoesNotExist($dictionary_file);
        $this->assertFileDoesNotExist($public_file);
        $this->assertFileDoesNotExist(ll_tools_public_static_cache_meta_file_path($public_file));
    }

    public function test_static_cache_purge_does_not_call_cloudflare_when_not_configured(): void
    {
        $http_requests = [];
        $http_filter = static function ($preempt, $args, $url) use (&$http_requests) {
            $http_requests[] = [
                'url' => $url,
                'args' => $args,
            ];
            return new WP_Error('unexpected_http', 'Cloudflare purge should not run without configuration.');
        };

        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_purge_static_caches('dictionary');
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
        }

        $edge = $result['edge']['cloudflare'] ?? [];
        $this->assertIsArray($edge);
        $this->assertFalse((bool) ($edge['configured'] ?? true));
        $this->assertFalse((bool) ($edge['attempted'] ?? true));
        $this->assertSame('not_configured', (string) ($edge['error'] ?? ''));
        $this->assertSame([], $http_requests);
    }

    public function test_static_cache_purge_respects_cloudflare_disabled_filter_when_configured(): void
    {
        $zone_filter = static function (): string {
            return 'test-zone-id';
        };
        $token_filter = static function (): string {
            return 'test-api-token';
        };
        $enabled_filter = static function (): bool {
            return false;
        };
        $http_requests = [];
        $http_filter = static function ($preempt, $args, $url) use (&$http_requests) {
            $http_requests[] = [
                'url' => $url,
                'args' => $args,
            ];
            return new WP_Error('unexpected_http', 'Cloudflare purge should not run while disabled.');
        };

        add_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        add_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
        add_filter('ll_tools_cloudflare_static_cache_purge_enabled', $enabled_filter);
        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_purge_static_caches('dictionary');
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_cloudflare_static_cache_purge_enabled', $enabled_filter);
            remove_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
            remove_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        }

        $edge = $result['edge']['cloudflare'] ?? [];
        $this->assertIsArray($edge);
        $this->assertTrue((bool) ($edge['configured'] ?? false));
        $this->assertFalse((bool) ($edge['enabled'] ?? true));
        $this->assertFalse((bool) ($edge['attempted'] ?? true));
        $this->assertSame('disabled', (string) ($edge['error'] ?? ''));
        $this->assertSame([], $http_requests);
    }

    public function test_dictionary_static_cache_purge_sends_dictionary_url_to_cloudflare_when_configured(): void
    {
        $dictionary_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Cloudflare Dictionary',
            'post_content' => '[ll_dictionary]',
        ]);
        $secondary_dictionary_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Cloudflare Secondary Dictionary',
            'post_content' => '<!-- wp:shortcode -->[ll_dictionary wordset="0"]<!-- /wp:shortcode -->',
        ]);
        update_option('ll_default_dictionary_page_id', $dictionary_page_id);
        $dictionary_url = (string) get_permalink($dictionary_page_id);
        $secondary_dictionary_url = (string) get_permalink($secondary_dictionary_page_id);
        $this->assertNotSame('', $dictionary_url);
        $this->assertNotSame('', $secondary_dictionary_url);

        $zone_filter = static function (): string {
            return 'test-zone-id';
        };
        $token_filter = static function (): string {
            return 'test-api-token';
        };
        $http_requests = [];
        $http_filter = static function ($preempt, $args, $url) use (&$http_requests) {
            $http_requests[] = [
                'url' => $url,
                'args' => $args,
            ];

            return [
                'headers' => [],
                'body' => wp_json_encode(['success' => true]),
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'cookies' => [],
                'filename' => null,
            ];
        };

        ll_tools_cloudflare_static_cache_reset_purge_once_state();
        add_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        add_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_purge_static_caches('dictionary');
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
            remove_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        }

        $edge = $result['edge']['cloudflare'] ?? [];
        $this->assertIsArray($edge);
        $this->assertTrue((bool) ($edge['configured'] ?? false));
        $this->assertTrue((bool) ($edge['attempted'] ?? false));
        $this->assertTrue((bool) ($edge['purged'] ?? false));
        $this->assertSame([$dictionary_url, $secondary_dictionary_url], $edge['urls'] ?? []);
        $this->assertCount(1, $http_requests);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/test-zone-id/purge_cache', (string) ($http_requests[0]['url'] ?? ''));

        $args = $http_requests[0]['args'] ?? [];
        $this->assertSame('Bearer test-api-token', (string) ($args['headers']['Authorization'] ?? ''));
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $this->assertSame(['files' => [$dictionary_url, $secondary_dictionary_url]], $body);
    }

    public function test_static_cache_purge_can_request_cloudflare_purge_everything(): void
    {
        $zone_filter = static function (): string {
            return 'test-zone-id';
        };
        $token_filter = static function (): string {
            return 'test-api-token';
        };
        $http_requests = [];
        $http_filter = static function ($preempt, $args, $url) use (&$http_requests) {
            $http_requests[] = [
                'url' => $url,
                'args' => $args,
            ];

            return [
                'headers' => [],
                'body' => wp_json_encode(['success' => true]),
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'cookies' => [],
                'filename' => null,
            ];
        };

        ll_tools_cloudflare_static_cache_reset_purge_once_state();
        add_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        add_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_purge_static_caches('all', [
                'cloudflare_purge_everything' => true,
            ]);
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
            remove_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        }

        $edge = $result['edge']['cloudflare'] ?? [];
        $this->assertIsArray($edge);
        $this->assertTrue((bool) ($edge['purge_everything'] ?? false));
        $this->assertTrue((bool) ($edge['attempted'] ?? false));
        $this->assertTrue((bool) ($edge['purged'] ?? false));
        $this->assertCount(1, $http_requests);

        $args = $http_requests[0]['args'] ?? [];
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $this->assertSame(['purge_everything' => true], $body);
    }

    public function test_static_cache_purge_capability_defaults_to_manage_options(): void
    {
        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);
        wp_set_current_user($viewer_id);

        $this->assertFalse(ll_tools_current_user_can_purge_static_cache());

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->assertTrue(ll_tools_current_user_can_purge_static_cache());
    }

    public function test_public_static_cache_targeted_purge_keeps_unrelated_wordset_files(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $matching = trailingslashit($dir) . 'public-matching.html';
        $unrelated = trailingslashit($dir) . 'public-unrelated.html';
        file_put_contents($matching, '<!doctype html><html><body>matching</body></html>');
        file_put_contents($unrelated, '<!doctype html><html><body>unrelated</body></html>');

        ll_tools_public_static_cache_write_meta($matching, 'matching', [
            'type' => 'wordset_main',
            'id' => 17,
            'path' => '/matching',
            'wordset_id' => 17,
        ]);
        ll_tools_public_static_cache_write_meta($unrelated, 'unrelated', [
            'type' => 'wordset_main',
            'id' => 18,
            'path' => '/unrelated',
            'wordset_id' => 18,
        ]);

        $deleted = ll_tools_purge_public_static_cache(['wordset_ids' => [17]]);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($matching);
        $this->assertFileDoesNotExist(ll_tools_public_static_cache_meta_file_path($matching));
        $this->assertFileExists($unrelated);
        $this->assertFileExists(ll_tools_public_static_cache_meta_file_path($unrelated));
    }

    public function test_public_static_cache_targeted_purge_deletes_legacy_files_without_metadata(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $legacy = trailingslashit($dir) . 'public-legacy.html';
        file_put_contents($legacy, '<!doctype html><html><body>legacy</body></html>');

        $deleted = ll_tools_purge_public_static_cache(['wordset_ids' => [17]]);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($legacy);
    }

    public function test_public_static_cache_draft_save_does_not_purge_public_files(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = $this->writePublicCacheFileForWordset($dir, 17, 'draft-save');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Draft Cache Word',
        ]);

        ll_tools_public_static_cache_purge_on_post_change((int) $word_id);

        $this->assertFileExists($file);
    }

    public function test_public_static_cache_publish_status_transition_purges_public_files(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'draft',
            'post_title' => 'Draft Lesson Becoming Public',
        ]);
        $file = trailingslashit($dir) . 'public-status-purge.html';
        file_put_contents($file, '<!doctype html><html><body>status purge</body></html>');
        ll_tools_public_static_cache_write_meta($file, 'status-purge', [
            'type' => 'vocab_lesson',
            'id' => (int) $lesson_id,
            'path' => '/status-purge',
            'wordset_id' => 0,
        ]);

        ll_tools_public_static_cache_purge_on_status_transition('publish', 'draft', get_post((int) $lesson_id));

        $this->assertFileDoesNotExist($file);
    }

    public function test_public_static_cache_term_move_purges_old_and_new_wordsets_only(): void
    {
        $dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $old = wp_insert_term('Old Public Cache Wordset', 'wordset');
        $new = wp_insert_term('New Public Cache Wordset', 'wordset');
        $unrelated = wp_insert_term('Unrelated Public Cache Wordset', 'wordset');
        $this->assertIsArray($old);
        $this->assertIsArray($new);
        $this->assertIsArray($unrelated);

        $old_id = (int) ($old['term_id'] ?? 0);
        $new_id = (int) ($new['term_id'] ?? 0);
        $unrelated_id = (int) ($unrelated['term_id'] ?? 0);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Moved Cache Word',
        ]);

        $old_term = get_term($old_id, 'wordset');
        $new_term = get_term($new_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $old_term);
        $this->assertInstanceOf(WP_Term::class, $new_term);
        ll_tools_public_static_cache_reset_purge_once_state();
        $old_file = $this->writePublicCacheFileForWordset($dir, $old_id, 'old');
        $new_file = $this->writePublicCacheFileForWordset($dir, $new_id, 'new');
        $unrelated_file = $this->writePublicCacheFileForWordset($dir, $unrelated_id, 'unrelated-term-move');

        ll_tools_public_static_cache_purge_on_object_terms_change(
            (int) $word_id,
            [$new_id],
            [(int) $new_term->term_taxonomy_id],
            'wordset',
            false,
            [(int) $old_term->term_taxonomy_id]
        );

        $this->assertFileDoesNotExist($old_file);
        $this->assertFileDoesNotExist($new_file);
        $this->assertFileExists($unrelated_file);
    }

    private function writePublicCacheFileForWordset(string $dir, int $wordset_id, string $slug): string
    {
        $file = trailingslashit($dir) . 'public-' . sanitize_key($slug) . '.html';
        file_put_contents($file, '<!doctype html><html><body>' . esc_html($slug) . '</body></html>');
        ll_tools_public_static_cache_write_meta($file, sanitize_key($slug), [
            'type' => 'wordset_main',
            'id' => $wordset_id,
            'path' => '/' . sanitize_key($slug),
            'wordset_id' => $wordset_id,
        ]);

        return $file;
    }

    private function clear404Flag(): void
    {
        $wp_query = $GLOBALS['wp_query'] ?? null;
        if ($wp_query instanceof WP_Query) {
            $wp_query->is_404 = false;
        }
    }
}
