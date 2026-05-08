<?php
declare(strict_types=1);

final class PublicStaticCacheTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        set_query_var('ll_wordset_page', '');
        set_query_var('ll_wordset_view', '');
        if (function_exists('ll_tools_purge_public_static_cache')) {
            ll_tools_purge_public_static_cache();
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
            'utm_source' => 'crawler',
            'fbclid' => 'abc',
        ], 'tr_TR');
        $second = ll_tools_public_static_cache_key($identity, [
            'fbclid' => 'def',
            'utm_source' => 'other',
            'll_tools_auth' => 'register',
            'll_locale_nonce' => 'second',
            'll_locale' => 'tr_TR',
        ], 'tr_TR');

        $this->assertSame($first, $second);
        $this->assertNotSame(
            $first,
            ll_tools_public_static_cache_key($identity, ['ll_locale' => 'en_US'], 'en_US')
        );
        $this->assertSame(['ll_locale' => 'tr_TR'], ll_tools_public_static_cache_normalize_query_args([
            'll_locale' => 'tr_TR',
            'utm_campaign' => 'ignored',
            'll_locale_nonce' => 'ignored',
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

    private function clear404Flag(): void
    {
        $wp_query = $GLOBALS['wp_query'] ?? null;
        if ($wp_query instanceof WP_Query) {
            $wp_query->is_404 = false;
        }
    }
}
