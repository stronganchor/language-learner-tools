<?php
declare(strict_types=1);

final class DictionaryFeatureTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        if (function_exists('ll_tools_cloudflare_static_cache_reset_purge_once_state')) {
            ll_tools_cloudflare_static_cache_reset_purge_once_state();
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if (function_exists('ll_tools_dictionary_import_get_job_option_key')) {
            $like = $wpdb->esc_like(LL_TOOLS_DICTIONARY_IMPORT_JOB_OPTION_PREFIX) . '%';
            $option_names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ));
            foreach ((array) $option_names as $option_name) {
                $job = get_option((string) $option_name);
                if (is_array($job) && function_exists('ll_tools_dictionary_import_delete_path')) {
                    ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
                }
                delete_option((string) $option_name);
            }
        }
        if (defined('LL_TOOLS_DICTIONARY_IMPORT_LOCK_OPTION_PREFIX')) {
            $lock_like = $wpdb->esc_like(LL_TOOLS_DICTIONARY_IMPORT_LOCK_OPTION_PREFIX) . '%';
            $lock_option_names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $lock_like
            ));
            foreach ((array) $lock_option_names as $option_name) {
                delete_option((string) $option_name);
            }
        }
        if (function_exists('ll_tools_dictionary_import_clear_active_job_id')) {
            ll_tools_dictionary_import_clear_active_job_id();
        }
        if (defined('LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY')) {
            $wpdb->delete(
                $wpdb->usermeta,
                ['meta_key' => LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY],
                ['%s']
            );
        }
        if (function_exists('ll_tools_dictionary_import_read_history')) {
            foreach (ll_tools_dictionary_import_read_history() as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $backup_path = (string) ($entry['backup_snapshot_path'] ?? '');
                if ($backup_path !== '' && file_exists($backup_path)) {
                    @unlink($backup_path);
                }
            }
        }
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        unset($_COOKIE[LL_TOOLS_I18N_COOKIE]);
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        delete_option(LL_TOOLS_DICTIONARY_SOURCES_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION);
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
        wp_clear_scheduled_hook(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        foreach (get_posts([
            'post_type' => ['ll_dictionary_entry', 'words'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]) as $post_id) {
            wp_delete_post((int) $post_id, true);
        }
        if (function_exists('ll_tools_dictionary_lookup_table_name') && function_exists('ll_tools_dictionary_lookup_table_exists') && ll_tools_dictionary_lookup_table_exists()) {
            $wpdb->query('TRUNCATE TABLE ' . ll_tools_dictionary_lookup_table_name());
        }
        unset($GLOBALS['ll_tools_dictionary_source_registry_cache']);
        unset($GLOBALS['ll_tools_dictionary_browser_cache_version']);
        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        ll_tools_bump_dictionary_browser_cache_version();
        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        if (function_exists('ll_tools_cloudflare_static_cache_reset_purge_once_state')) {
            ll_tools_cloudflare_static_cache_reset_purge_once_state();
        }
        parent::tearDown();
    }

    public function test_dictionary_shortcode_defaults_to_unscoped_page_configuration(): void
    {
        $this->assertSame(0, ll_tools_dictionary_shortcode_resolve_wordset_id(''));
        $this->assertSame('[ll_dictionary wordset="0"]', ll_tools_get_dictionary_page_config()['post_content']);
    }

    public function test_dictionary_part_of_speech_labels_are_translatable(): void
    {
        $filter = static function ($translation, $text, $domain) {
            if ($domain !== 'll-tools-text-domain') {
                return $translation;
            }
            if ($text === 'Adjective') {
                return 'Sıfat';
            }
            if ($text === 'Noun') {
                return 'İsim';
            }

            return $translation;
        };

        add_filter('gettext', $filter, 10, 3);
        try {
            $this->assertSame('Sıfat', ll_tools_dictionary_get_part_of_speech_label('adjective', 'Adjective'));
            $this->assertSame('İsim', ll_tools_dictionary_get_part_of_speech_label('noun', 'Noun'));
            $this->assertSame('Custom POS', ll_tools_dictionary_get_part_of_speech_label('custom-pos', 'Custom POS'));
        } finally {
            remove_filter('gettext', $filter, 10);
        }
    }

    public function test_dictionary_language_key_normalizes_common_labels_for_browse_alphabets(): void
    {
        $this->assertSame('zza', ll_tools_dictionary_normalize_language_key('Zazaki'));
        $this->assertSame('zza', ll_tools_dictionary_normalize_language_key('Kirmanckî'));
        $this->assertSame('zza', ll_tools_dictionary_normalize_language_key('Dımılki'));
        $this->assertSame('tr', ll_tools_dictionary_normalize_language_key('Türkçe'));
        $this->assertSame('en', ll_tools_dictionary_normalize_language_key('English'));

        $zazaki_alphabet = ll_tools_dictionary_get_language_browse_alphabet('Zazaki');
        $this->assertContains('Ç', $zazaki_alphabet);
        $this->assertContains('Ş', $zazaki_alphabet);
        $this->assertContains('Ê', $zazaki_alphabet);
        $this->assertContains('Û', $zazaki_alphabet);
    }

    public function test_dictionary_static_cache_key_normalizes_display_args_and_ignores_nonce_noise(): void
    {
        $identity = [
            'page_id' => 123,
            'path' => '/sozluk',
        ];
        $first_key = ll_tools_dictionary_static_cache_key($identity, [
            'letter' => 'H',
            'll_dictionary_entry' => '70456',
            'll_dictionary_letter' => 'I',
            'll_dictionary_page' => '1',
            'll_locale' => 'tr_TR',
            'll_locale_nonce' => 'first',
            'll_tools_auth' => 'register',
            'utm_source' => 'crawler',
        ], 'tr_TR');
        $second_key = ll_tools_dictionary_static_cache_key($identity, [
            'utm_source' => 'other',
            'll_tools_auth' => 'login',
            'll_locale_nonce' => 'second',
            'll_locale' => 'tr_TR',
            'll_dictionary_letter' => 'J',
            'll_dictionary_entry' => '70456',
        ], 'tr_TR');

        $this->assertSame($first_key, $second_key);
        $this->assertNotSame(
            $first_key,
            ll_tools_dictionary_static_cache_key($identity, [
                'll_dictionary_entry' => '70457',
            ], 'tr_TR')
        );

        $normalized = ll_tools_dictionary_static_cache_normalize_query_args([
            'letter' => 'H',
            'll_dictionary_entry' => '70456',
            'll_dictionary_letter' => 'I',
            'll_dictionary_page' => '9',
            'll_locale_nonce' => 'ignored',
            'll_tools_auth' => 'ignored',
            'll_locale' => 'tr_TR',
        ]);
        $this->assertSame([
            'll_dictionary_entry' => '70456',
        ], $normalized);

        $legacy_normalized = ll_tools_dictionary_static_cache_normalize_query_args(['letter' => 'H']);
        $this->assertSame(['ll_dictionary_letter' => 'H'], $legacy_normalized);

        $search_normalized = ll_tools_dictionary_static_cache_normalize_query_args([
            'll_dictionary_q' => 'ro',
            'll_dictionary_letter' => 'B',
        ]);
        $this->assertSame(['ll_dictionary_q' => 'ro'], $search_normalized);
    }

    public function test_dictionary_static_cache_canonical_url_drops_conflicting_and_unsigned_args(): void
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Canonical Dar',
        ]);
        $identity = [
            'page_id' => 123,
            'path' => '/sozluk',
        ];

        $canonical = ll_tools_dictionary_static_cache_canonical_url($identity, [
            'letter' => 'J',
            'll_dictionary_entry' => (string) $entry_id,
            'll_dictionary_letter' => 'Ü',
            'll_dictionary_page' => '9',
            'll_locale' => 'tr_TR',
            'll_locale_nonce' => 'nonce',
            'foo' => 'bar',
        ]);
        $query = [];
        wp_parse_str((string) wp_parse_url($canonical, PHP_URL_QUERY), $query);

        $this->assertStringContainsString('/sozluk/', $canonical);
        $this->assertSame([
            'll_dictionary_entry' => (string) $entry_id,
        ], $query);

        $invalid = ll_tools_dictionary_static_cache_canonical_url($identity, [
            'll_dictionary_entry' => '999999',
            'll_dictionary_letter' => 'A',
            'll_locale_nonce' => 'nonce',
        ]);

        $this->assertSame('', (string) wp_parse_url($invalid, PHP_URL_QUERY));
    }

    public function test_dictionary_static_cache_debug_logging_is_filterable(): void
    {
        $disabled = static function (): bool {
            return false;
        };
        $enabled = static function (): bool {
            return true;
        };

        add_filter('ll_tools_dictionary_static_cache_debug_enabled', $disabled);
        $this->assertFalse(ll_tools_dictionary_static_cache_debug_enabled());
        remove_filter('ll_tools_dictionary_static_cache_debug_enabled', $disabled);

        add_filter('ll_tools_dictionary_static_cache_debug_enabled', $enabled);
        $this->assertTrue(ll_tools_dictionary_static_cache_debug_enabled());
        remove_filter('ll_tools_dictionary_static_cache_debug_enabled', $enabled);
    }

    public function test_dictionary_static_cache_status_defaults_unset_php_status_to_success(): void
    {
        $this->assertSame(200, ll_tools_dictionary_static_cache_current_status_code());
        $this->assertSame(404, ll_tools_dictionary_static_cache_status_code_from_headers([
            'Content-Type: text/html',
            'HTTP/1.1 404 Not Found',
        ]));
        $this->assertSame(503, ll_tools_dictionary_static_cache_status_code_from_headers([
            'Status: 503 Service Unavailable',
        ]));
    }

    public function test_dictionary_static_cache_miss_uses_no_cache_until_response_is_known(): void
    {
        $this->assertSame('no-cache, must-revalidate', ll_tools_dictionary_static_cache_cache_control_value(false));
        $this->assertStringStartsWith('public, max-age=', ll_tools_dictionary_static_cache_cache_control_value(true));
    }

    public function test_dictionary_static_cache_storage_ttl_is_longer_than_browser_max_age(): void
    {
        $this->assertSame(7 * DAY_IN_SECONDS, ll_tools_dictionary_static_cache_ttl());
        $this->assertSame(DAY_IN_SECONDS, ll_tools_dictionary_static_cache_browser_max_age());
        $this->assertSame('public, max-age=' . DAY_IN_SECONDS, ll_tools_dictionary_static_cache_cache_control_value(true));
        $this->assertSame('no-cache, must-revalidate', ll_tools_dictionary_static_cache_cache_control_value(false));
    }

    public function test_dictionary_static_cache_ttls_are_filterable_independently(): void
    {
        $ttl_filter = static function (): int {
            return 2 * DAY_IN_SECONDS;
        };
        $browser_filter = static function (): int {
            return 2 * HOUR_IN_SECONDS;
        };

        add_filter('ll_tools_dictionary_static_cache_ttl', $ttl_filter);
        add_filter('ll_tools_dictionary_static_cache_browser_max_age', $browser_filter);

        try {
            $this->assertSame(2 * DAY_IN_SECONDS, ll_tools_dictionary_static_cache_ttl());
            $this->assertSame(2 * HOUR_IN_SECONDS, ll_tools_dictionary_static_cache_browser_max_age());
            $this->assertSame('public, max-age=' . (2 * HOUR_IN_SECONDS), ll_tools_dictionary_static_cache_cache_control_value(true));
        } finally {
            remove_filter('ll_tools_dictionary_static_cache_ttl', $ttl_filter);
            remove_filter('ll_tools_dictionary_static_cache_browser_max_age', $browser_filter);
        }
    }

    public function test_dictionary_static_cache_locale_strategy_allows_only_site_default_locale(): void
    {
        if (function_exists('ll_tools_get_plugin_locales') && !in_array('tr_TR', ll_tools_get_plugin_locales(), true)) {
            $this->markTestSkipped('Turkish locale is not available in this test environment.');
        }

        $old_wplang = get_option('WPLANG');
        $old_autoswitch = get_option('ll_enable_browser_language_autoswitch');

        try {
            update_option('WPLANG', '');
            update_option('ll_enable_browser_language_autoswitch', 1);
            unset($_COOKIE[LL_TOOLS_I18N_COOKIE], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $_GET = [];
            $_REQUEST = [];

            $this->assertSame('en_US', ll_tools_dictionary_static_cache_default_locale());
            $this->assertSame('', ll_tools_dictionary_static_cache_locale_bypass_reason());

            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';

            $this->assertSame('tr_TR', ll_tools_dictionary_static_cache_current_public_locale());
            $this->assertSame('browser_locale', ll_tools_dictionary_static_cache_locale_bypass_reason());
            $this->assertSame('private, no-store, max-age=0', ll_tools_dictionary_static_cache_bypass_cache_control_value());

            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $_COOKIE[LL_TOOLS_I18N_COOKIE] = 'tr_TR';

            $this->assertSame('tr_TR', ll_tools_dictionary_static_cache_current_public_locale());
            $this->assertSame('locale_cookie', ll_tools_dictionary_static_cache_locale_bypass_reason());
        } finally {
            update_option('WPLANG', $old_wplang);
            update_option('ll_enable_browser_language_autoswitch', $old_autoswitch);
            unset($_COOKIE[LL_TOOLS_I18N_COOKIE], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $_GET = [];
            $_REQUEST = [];
        }
    }

    public function test_cacheable_dictionary_request_rejects_browser_locale_variant(): void
    {
        if (function_exists('ll_tools_get_plugin_locales') && !in_array('tr_TR', ll_tools_get_plugin_locales(), true)) {
            $this->markTestSkipped('Turkish locale is not available in this test environment.');
        }

        $old_wplang = get_option('WPLANG');
        $old_autoswitch = get_option('ll_enable_browser_language_autoswitch');
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Cacheable Dictionary Locale',
            'post_name' => 'sozluk',
            'post_content' => '[ll_dictionary]',
        ]);
        $url = (string) get_permalink($page_id);

        try {
            update_option('WPLANG', '');
            update_option('ll_enable_browser_language_autoswitch', 1);
            wp_set_current_user(0);
            $_GET = [];
            $_REQUEST = [];
            unset($_COOKIE[LL_TOOLS_I18N_COOKIE]);

            $this->go_to($url);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
            $this->assertTrue(ll_tools_is_cacheable_dictionary_request());

            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';
            $this->assertFalse(ll_tools_is_cacheable_dictionary_request());
            $this->assertSame('browser_locale', ll_tools_dictionary_static_cache_locale_bypass_reason());
        } finally {
            update_option('WPLANG', $old_wplang);
            update_option('ll_enable_browser_language_autoswitch', $old_autoswitch);
            unset($_COOKIE[LL_TOOLS_I18N_COOKIE], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $_GET = [];
            $_REQUEST = [];
        }
    }

    public function test_dictionary_static_cache_store_writes_when_php_status_is_unset(): void
    {
        $dir = ll_tools_dictionary_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = trailingslashit($dir) . 'dictionary-store-test.html';
        @unlink($file);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $buffer_level = ob_get_level();
        $GLOBALS['ll_tools_dictionary_static_cache_request'] = [
            'active' => true,
            'file' => $file,
            'buffer_level' => $buffer_level,
        ];

        ob_start();
        try {
            echo '<!doctype html><html><body>' . str_repeat('dictionary cache store test ', 40) . '</body></html>';
            ll_tools_store_dictionary_static_cache();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            unset($GLOBALS['ll_tools_dictionary_static_cache_request']);
        }

        $this->assertFileExists($file);
        $this->assertStringContainsString('dictionary cache store test', (string) file_get_contents($file));
    }

    public function test_dictionary_public_navigation_drops_nonce_auth_and_tracking_noise(): void
    {
        $clean_url = ll_tools_dictionary_strip_noise_query_args_from_url(
            'https://example.com/sozluk/?ll_locale=tr_TR&ll_locale_nonce=abc&ll_tools_auth=login&utm_source=news&fbclid=1&foo=bar'
        );

        $this->assertStringContainsString('ll_locale=tr_TR', $clean_url);
        $this->assertStringContainsString('foo=bar', $clean_url);
        $this->assertStringNotContainsString('ll_locale_nonce', $clean_url);
        $this->assertStringNotContainsString('ll_tools_auth', $clean_url);
        $this->assertStringNotContainsString('utm_source', $clean_url);
        $this->assertStringNotContainsString('fbclid', $clean_url);

        $_GET = [
            'll_dictionary_q' => 'ro',
            'll_locale' => 'tr_TR',
            'll_locale_nonce' => 'abc',
            'll_tools_auth' => 'register',
            'utm_source' => 'crawler',
            'foo' => 'bar',
        ];
        $hidden_inputs = ll_tools_dictionary_preserve_non_dictionary_query_inputs();

        $this->assertStringContainsString('name="ll_locale"', $hidden_inputs);
        $this->assertStringContainsString('name="foo"', $hidden_inputs);
        $this->assertStringNotContainsString('ll_dictionary_q', $hidden_inputs);
        $this->assertStringNotContainsString('ll_locale_nonce', $hidden_inputs);
        $this->assertStringNotContainsString('ll_tools_auth', $hidden_inputs);
        $this->assertStringNotContainsString('utm_source', $hidden_inputs);
    }

    public function test_dictionary_browser_cache_bump_purges_static_html_files(): void
    {
        $dir = ll_tools_dictionary_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $file = trailingslashit($dir) . 'dictionary-test.html';
        file_put_contents($file, '<!doctype html><html><body>cached</body></html>');
        $this->assertFileExists($file);

        ll_tools_bump_dictionary_browser_cache_version();

        $this->assertFileDoesNotExist($file);
    }

    public function test_dictionary_browser_cache_bump_purges_cloudflare_dictionary_url_when_configured(): void
    {
        $dictionary_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Cloudflare Dictionary',
            'post_content' => '[ll_dictionary]',
        ]);
        update_option('ll_default_dictionary_page_id', $dictionary_page_id);
        $dictionary_url = (string) get_permalink($dictionary_page_id);
        $this->assertNotSame('', $dictionary_url);

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
        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        add_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        add_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            ll_tools_bump_dictionary_browser_cache_version();
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_cloudflare_static_cache_api_token', $token_filter);
            remove_filter('ll_tools_cloudflare_static_cache_zone_id', $zone_filter);
        }

        $this->assertCount(1, $http_requests);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/test-zone-id/purge_cache', (string) ($http_requests[0]['url'] ?? ''));
        $body = json_decode((string) ($http_requests[0]['args']['body'] ?? ''), true);
        $this->assertSame(['files' => [$dictionary_url]], $body);
    }

    public function test_dictionary_browser_cache_bump_purges_static_html_once_per_request(): void
    {
        $dir = ll_tools_dictionary_static_cache_dir();
        $this->assertNotSame('', $dir);
        $this->assertTrue(wp_mkdir_p($dir));

        $first_file = trailingslashit($dir) . 'dictionary-once-first.html';
        $second_file = trailingslashit($dir) . 'dictionary-once-second.html';
        @unlink($first_file);
        @unlink($second_file);

        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }

        file_put_contents($first_file, '<!doctype html><html><body>first</body></html>');
        $this->assertFileExists($first_file);

        ll_tools_bump_dictionary_browser_cache_version();
        $this->assertFileDoesNotExist($first_file);

        file_put_contents($second_file, '<!doctype html><html><body>second</body></html>');
        $this->assertFileExists($second_file);

        ll_tools_bump_dictionary_browser_cache_version();
        $this->assertFileExists($second_file);

        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        @unlink($second_file);
    }

    public function test_dictionary_browser_word_invalidation_skips_unlinked_words(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Unlinked Cache Word',
        ]);
        $post = get_post($word_id);
        $this->assertInstanceOf(WP_Post::class, $post);

        $version = ll_tools_get_dictionary_browser_cache_version();
        ll_tools_dictionary_browser_invalidate_on_post_save($word_id, $post);
        $this->assertSame($version, ll_tools_get_dictionary_browser_cache_version());

        ll_tools_dictionary_browser_invalidate_on_terms_change($word_id, [], [], 'wordset');
        $this->assertSame($version, ll_tools_get_dictionary_browser_cache_version());

        ll_tools_dictionary_browser_invalidate_on_post_delete($word_id);
        $this->assertSame($version, ll_tools_get_dictionary_browser_cache_version());
    }

    public function test_dictionary_browser_word_invalidation_keeps_linked_words_fresh(): void
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Linked Cache Entry',
        ]);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Linked Cache Word',
        ]);
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
        $post = get_post($word_id);
        $this->assertInstanceOf(WP_Post::class, $post);

        $version = ll_tools_get_dictionary_browser_cache_version();
        ll_tools_dictionary_browser_invalidate_on_post_save($word_id, $post);
        $this->assertGreaterThan($version, ll_tools_get_dictionary_browser_cache_version());

        $version = ll_tools_get_dictionary_browser_cache_version();
        ll_tools_dictionary_browser_invalidate_on_terms_change($word_id, [], [], 'wordset');
        $this->assertGreaterThan($version, ll_tools_get_dictionary_browser_cache_version());

        $version = ll_tools_get_dictionary_browser_cache_version();
        ll_tools_dictionary_browser_invalidate_on_post_delete($word_id);
        $this->assertGreaterThan($version, ll_tools_get_dictionary_browser_cache_version());
    }

    public function test_dictionary_browser_link_meta_changes_invalidate_without_word_save(): void
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Meta Cache Entry',
        ]);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Meta Cache Word',
        ]);

        $version = ll_tools_get_dictionary_browser_cache_version();
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
        $this->assertGreaterThan($version, ll_tools_get_dictionary_browser_cache_version());

        $version = ll_tools_get_dictionary_browser_cache_version();
        update_post_meta($word_id, 'll_dictionary_unrelated_test_meta', '1');
        $this->assertSame($version, ll_tools_get_dictionary_browser_cache_version());

        $version = ll_tools_get_dictionary_browser_cache_version();
        delete_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
        $this->assertGreaterThan($version, ll_tools_get_dictionary_browser_cache_version());
    }

    public function test_dictionary_static_cache_refreshes_embedded_public_nonces(): void
    {
        $dictionary_nonce = wp_create_nonce('ll_tools_dictionary_live_search');
        $locale_nonce = wp_create_nonce(ll_tools_get_locale_switch_nonce_action());
        $stored = ll_tools_dictionary_static_cache_prepare_html_for_storage(
            '<script>' . $dictionary_nonce . '</script><a href="?ll_locale_nonce=' . $locale_nonce . '">Locale</a>'
        );

        $this->assertStringContainsString(LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER, $stored);
        $this->assertStringContainsString(LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER, $stored);
        $this->assertStringNotContainsString($dictionary_nonce, $stored);
        $this->assertStringNotContainsString($locale_nonce, $stored);

        $output = ll_tools_dictionary_static_cache_prepare_html_for_output($stored);
        $this->assertStringContainsString($dictionary_nonce, $output);
        $this->assertStringContainsString($locale_nonce, $output);
        $this->assertStringNotContainsString(LL_TOOLS_DICTIONARY_STATIC_CACHE_NONCE_PLACEHOLDER, $output);
        $this->assertStringNotContainsString(LL_TOOLS_DICTIONARY_STATIC_CACHE_LOCALE_NONCE_PLACEHOLDER, $output);
    }

    public function test_dictionary_ajax_cache_refreshes_with_browser_cache_version(): void
    {
        wp_set_current_user(0);

        $args = [
            'wordset_id' => 0,
            'search' => 'roc',
            'page' => 1,
        ];
        $payload = [
            'html' => '<article>Cached</article>',
            'has_active_query' => true,
            'url' => 'https://example.com/sozluk/?ll_dictionary_q=roc',
        ];

        ll_tools_dictionary_ajax_cache_set('live_search', $args, $payload);

        $this->assertSame($payload, ll_tools_dictionary_ajax_cache_get('live_search', $args));

        ll_tools_bump_dictionary_browser_cache_version();

        $this->assertNull(ll_tools_dictionary_ajax_cache_get('live_search', $args));
    }

    public function test_dictionary_live_search_ignores_direct_one_character_ajax_search_without_filters(): void
    {
        wp_set_current_user(0);

        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'll_dictionary_q' => 'a',
            'll_dictionary_page' => '1',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertSame('', (string) ($data['html'] ?? 'missing'));
        $this->assertFalse((bool) ($data['has_active_query'] ?? true));
        $this->assertSame('https://example.com/sozluk/', (string) ($data['url'] ?? ''));
    }

    public function test_dictionary_shortcode_ignores_direct_one_character_search_without_filters(): void
    {
        wp_set_current_user(0);

        $_GET = [
            'll_dictionary_q' => 'a',
        ];

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $html = do_shortcode('[ll_dictionary]');
        } finally {
            remove_filter('query', $capture);
            $_GET = [];
        }

        $this->assertStringNotContainsString('No entries found.', $html);
        $queries_sql = implode("\n", $queries);
        $this->assertStringNotContainsString('lookup_title.meta_value', $queries_sql);
        $this->assertStringNotContainsString('lookup_translation.meta_value', $queries_sql);
        $this->assertStringNotContainsString('search_index.meta_value', $queries_sql);
    }

    public function test_dictionary_live_search_clamps_anonymous_page_cache_keys(): void
    {
        wp_set_current_user(0);
        $cap_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_dictionary_anonymous_live_search_page_cap', $cap_filter);

        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'll_dictionary_q' => 'a',
            'll_dictionary_page' => '999',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_dictionary_anonymous_live_search_page_cap', $cap_filter);
        }

        $this->assertTrue((bool) ($response['success'] ?? false));

        $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request([]);
        $base_cache_args = [
            'wordset_id' => 0,
            'per_page' => 20,
            'sense_limit' => 3,
            'linked_word_limit' => 4,
            'gloss_lang' => '',
            'base_url' => 'https://example.com/sozluk/',
            'search' => '',
            'search_scopes' => $search_scopes,
            'letter' => '',
            'pos_slug' => '',
            'source_ids' => [],
            'dialect' => '',
            'preferred_languages' => ll_tools_dictionary_shortcode_resolve_display_languages($search_scopes, 0, ''),
            'title_language' => ll_tools_dictionary_get_effective_title_language_code(0),
            'browse_letter_schema' => 2,
            'has_active_query' => false,
            'query_limits' => [
                'result_depth_limit' => ll_tools_dictionary_anonymous_live_search_result_depth_cap(),
                'candidate_scan_limit' => ll_tools_dictionary_anonymous_live_search_candidate_scan_cap(),
            ],
        ];

        $this->assertIsArray(ll_tools_dictionary_ajax_cache_get('live_search', array_merge($base_cache_args, [
            'page' => 3,
        ])));
        $this->assertNull(ll_tools_dictionary_ajax_cache_get('live_search', array_merge($base_cache_args, [
            'page' => 999,
        ])));
    }

    public function test_dictionary_live_search_caps_anonymous_candidate_scan_depth(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        foreach (['Cap Alpha', 'Cap Beta', 'Cap Gamma'] as $title) {
            $result = ll_tools_dictionary_upsert_entry_from_rows([
                [
                    'entry' => $title,
                    'definition' => strtolower($title),
                    'entry_type' => 'noun',
                    'entry_lang' => 'Zazaki',
                    'def_lang' => 'English',
                ],
            ], [
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ]);
            $this->assertIsArray($result);
        }
        ll_tools_bump_dictionary_browser_cache_version();

        wp_set_current_user(0);
        $depth_cap_filter = static function (): int {
            return 2;
        };
        $candidate_cap_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_dictionary_anonymous_live_search_result_depth_cap', $depth_cap_filter);
        add_filter('ll_tools_dictionary_anonymous_live_search_candidate_scan_cap', $candidate_cap_filter);

        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'll_dictionary_q' => 'cap',
            'll_dictionary_page' => '999',
            'per_page' => '5',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_dictionary_anonymous_live_search_result_depth_cap', $depth_cap_filter);
            remove_filter('ll_tools_dictionary_anonymous_live_search_candidate_scan_cap', $candidate_cap_filter);
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $html = (string) ($data['html'] ?? '');

        $this->assertTrue((bool) ($data['is_limited'] ?? false));
        $this->assertStringContainsString('Showing 1-2 of 2', $html);
        $this->assertStringContainsString('Cap Alpha', $html);
        $this->assertStringContainsString('Cap Beta', $html);
        $this->assertStringNotContainsString('Cap Gamma', $html);
    }

    public function test_dictionary_ajax_rejects_private_wordset_scope_for_logged_out_users(): void
    {
        $wordset = wp_insert_term('Private Dictionary Ajax ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        wp_set_current_user(0);

        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'wordset_id' => $wordset_id,
            'll_dictionary_q' => 'secret',
        ];
        $_REQUEST = $_POST;

        try {
            $live_search = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($live_search['success'] ?? true));
        $this->assertStringContainsString('permission', (string) ($live_search['data']['message'] ?? ''));

        $_POST = [
            'action' => 'll_tools_dictionary_toolbar_bootstrap',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'wordset_id' => $wordset_id,
        ];
        $_REQUEST = $_POST;

        try {
            $toolbar = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_toolbar_bootstrap();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($toolbar['success'] ?? true));
        $this->assertStringContainsString('permission', (string) ($toolbar['data']['message'] ?? ''));
    }

    public function test_unscoped_dictionary_hides_entries_explicitly_assigned_to_private_wordsets(): void
    {
        $wordset = wp_insert_term('Private Dictionary Entry Scope ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Private Dictionary Secret ' . wp_generate_password(4, false),
        ]);
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($entry_id);
        ll_tools_dictionary_refresh_entry_search_meta($entry_id);

        wp_set_current_user(0);

        $this->assertNotContains($entry_id, ll_tools_dictionary_get_published_entry_ids_for_scope(0));

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];
        try {
            $this->assertSame(0, ll_tools_dictionary_shortcode_resolve_requested_entry_id(0));
        } finally {
            $_GET = [];
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->assertContains($entry_id, ll_tools_dictionary_get_published_entry_ids_for_scope(0));

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];
        try {
            $this->assertSame($entry_id, ll_tools_dictionary_shortcode_resolve_requested_entry_id(0));
        } finally {
            $_GET = [];
        }
    }

    public function test_import_groups_duplicate_headwords_and_shortcode_paginates_results(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $suffix = wp_generate_password(6, false, false);
        $wordset = wp_insert_term(
            'Dictionary Test Wordset ' . $suffix,
            'wordset',
            ['slug' => 'dictionary-test-wordset-' . $suffix]
        );
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Roce',
                'definition' => 'day',
                'entry_type' => 'noun',
                'page_number' => '12',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Roce',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'page_number' => '12',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Bari',
                'definition' => 'shore',
                'entry_type' => 'noun',
                'page_number' => '17',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Biya',
                'definition' => 'come',
                'entry_type' => 'verb',
                'page_number' => '18',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'page_number' => '6',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'Turkish',
        ]);

        $this->assertSame(4, (int) ($summary['rows_grouped'] ?? 0));
        $this->assertSame(4, (int) ($summary['entries_created'] ?? 0));

        $roce_entry_id = ll_tools_dictionary_find_entry_by_title('Roce', $wordset_id);
        $this->assertGreaterThan(0, $roce_entry_id);
        $this->assertSame(2, count(ll_tools_get_dictionary_entry_senses($roce_entry_id)));
        $this->assertSame('noun', ll_tools_get_dictionary_entry_primary_pos_slug($roce_entry_id));
        $this->assertSame('day; sun', ll_tools_get_dictionary_entry_translation($roce_entry_id));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Roce',
        ]);
        update_post_meta($word_id, 'word_translation', 'day');
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $roce_entry_id);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $_GET = [
            'll_dictionary_q' => 'Ro',
            'll_dictionary_page' => '1',
        ];

        $search_html = do_shortcode(sprintf('[ll_dictionary wordset="%d" per_page="2" linked_word_limit="2"]', $wordset_id));
        $this->assertStringContainsString('Roce', $search_html);
        $this->assertStringContainsString('day; sun', $search_html);
        $this->assertStringContainsString('Showing 1-1 of 1', $search_html);
        $this->assertStringContainsString('linked word', strtolower($search_html));

        $_GET = [
            'll_dictionary_letter' => 'B',
            'll_dictionary_page' => '2',
        ];

        $paged_html = do_shortcode(sprintf('[ll_dictionary wordset="%d" per_page="1"]', $wordset_id));
        $this->assertStringContainsString('Showing 2-2 of 2', $paged_html);
        $this->assertStringContainsString('Biya', $paged_html);
    }

    public function test_import_keeps_review_flagged_rows_after_skip_option_removal(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Rae',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'needs_review' => 'manual-check',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(1, (int) ($summary['rows_grouped'] ?? 0));
        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Rae', 0);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(1, $senses);
        $this->assertSame('manual-check', (string) ($senses[0]['needs_review'] ?? ''));
    }

    public function test_bulk_translation_lookup_prefers_imported_dictionary_entries(): void
    {
        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Mij',
                'definition' => 'moon',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $this->assertSame('moon', ll_tools_dictionary_lookup_best('Mij', 'Zazaki', 'English', false));
        $this->assertSame('Mij', ll_tools_dictionary_lookup_best('moon', 'English', 'Zazaki', true));
        $this->assertSame('moon', ll_dictionary_lookup_best('Mij', 'Zazaki', 'English', false));
    }

    public function test_dictionary_lookup_rebuild_populates_index_rows_for_existing_entries(): void
    {
        global $wpdb;

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        ll_tools_install_dictionary_lookup_schema();

        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Roj, Ruec',
            'post_content' => 'sun',
        ]);
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => 'sun',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
                'raw_headword' => 'ruec',
                'title_keys' => 'ruec|roj',
                'translations' => [
                    'en' => 'sun',
                    'tr' => 'gunes',
                ],
            ],
        ]);
        ll_tools_dictionary_refresh_entry_search_meta($entry_id);

        ll_tools_schedule_dictionary_lookup_rebuild(true);
        ll_tools_dictionary_lookup_process_rebuild_batch();

        $table = ll_tools_dictionary_lookup_table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lookup_kind, lookup_value
             FROM {$table}
             WHERE entry_id = %d
             ORDER BY lookup_kind ASC, lookup_value ASC",
            $entry_id
        ), ARRAY_A);

        $this->assertNotEmpty($rows);
        $pairs = array_map(static function (array $row): string {
            return (string) ($row['lookup_kind'] ?? '') . ':' . (string) ($row['lookup_value'] ?? '');
        }, $rows);

        $this->assertContains('headword:roj, ruec', $pairs);
        $this->assertContains('headword:roj', $pairs);
        $this->assertContains('headword:ruec', $pairs);
        $this->assertContains('translation:sun', $pairs);
        $this->assertContains('translation:gunes', $pairs);
        $this->assertTrue(ll_tools_dictionary_lookup_is_ready());
    }

    public function test_dictionary_search_uses_lookup_table_when_ready(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        ll_tools_install_dictionary_lookup_schema();

        $exact_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Roj, Ruec',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'raw_headword' => 'ruec',
                'title_keys' => 'ruec|roj',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $prefix_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Ruecarek',
                'definition' => 'sunlight',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($exact_result);
        $this->assertIsArray($prefix_result);
        ll_tools_schedule_dictionary_lookup_rebuild(true);
        ll_tools_dictionary_lookup_process_rebuild_batch();

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $results = ll_tools_dictionary_query_entries([
                'search' => 'ruec',
                'page' => 1,
                'per_page' => 10,
                'sense_limit' => 1,
                'linked_word_limit' => 0,
                'post_status' => ['publish'],
            ]);
        } finally {
            remove_filter('query', $capture);
        }

        $titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($results['items'] ?? [])));

        $this->assertSame('Roj, Ruec', $titles[0] ?? '');

        $queries_sql = implode("\n", $queries);
        $this->assertStringContainsString(ll_tools_dictionary_lookup_table_name(), $queries_sql);
        $this->assertStringNotContainsString('lookup_title.meta_value', $queries_sql);
        $this->assertStringNotContainsString('lookup_translation.meta_value', $queries_sql);
        $this->assertStringNotContainsString('search_index.meta_value', $queries_sql);

        $lookup_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ll_tools_dictionary_lookup_table_name());
        $this->assertGreaterThan(0, $lookup_count);

        $limited_lookup_ids = ll_tools_dictionary_query_entry_ids_from_lookup_table('ruec', ['publish'], 'all', 1);
        $this->assertCount(1, $limited_lookup_ids);
    }

    public function test_dictionary_postmeta_search_fallback_avoids_contains_search_by_default(): void
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Roj, Ruecarek',
        ]);
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, 'roj, ruecarek');
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY, 'sunlight');
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, 'roj ruecarek sunlight');
        ll_tools_bump_dictionary_browser_cache_version();
        wp_set_current_user(0);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $ids = ll_tools_dictionary_query_entry_ids_from_search_meta('uecar', ['publish'], 'all', 0, '', 10);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame([], $ids);
        $queries_sql = implode("\n", $queries);
        $this->assertStringContainsString('lookup_title.meta_value LIKE', $queries_sql);
        $this->assertStringContainsString("LIKE 'uecar%'", $queries_sql);
        $this->assertStringNotContainsString("LIKE '%uecar%'", $queries_sql);
        $this->assertStringNotContainsString('search_index.meta_value', $queries_sql);
    }

    public function test_dictionary_postmeta_contains_search_requires_explicit_opt_in(): void
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Roj, Ruecarek',
        ]);
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, 'roj, ruecarek');
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY, 'sunlight');
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, 'roj ruecarek sunlight');
        ll_tools_bump_dictionary_browser_cache_version();
        wp_set_current_user(0);

        $allow_contains = static function (): bool {
            return true;
        };
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('ll_tools_dictionary_allow_postmeta_contains_fallback', $allow_contains);
        add_filter('query', $capture);
        try {
            $ids = ll_tools_dictionary_query_entry_ids_from_search_meta('uecar', ['publish'], 'all', 0, '', 10);
        } finally {
            remove_filter('query', $capture);
            remove_filter('ll_tools_dictionary_allow_postmeta_contains_fallback', $allow_contains);
        }

        $this->assertSame([$entry_id], $ids);
        $queries_sql = implode("\n", $queries);
        $this->assertStringContainsString("LIKE 'uecar%'", $queries_sql);
        $this->assertStringContainsString("LIKE '%uecar%'", $queries_sql);
        $this->assertStringContainsString('search_index.meta_value', $queries_sql);
    }

    public function test_dictionary_search_scope_limits_headwords_and_language_specific_translations(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        ll_tools_install_dictionary_lookup_schema();

        $translation_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'translations' => [
                    'tr' => 'su',
                    'en' => 'water',
                    'de' => 'Wasser',
                ],
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $headword_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Su',
                'definition' => 'spring',
                'entry_type' => 'noun',
                'translations' => [
                    'tr' => 'kaynak',
                    'en' => 'spring',
                    'de' => 'Quelle',
                ],
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($translation_result);
        $this->assertIsArray($headword_result);

        ll_tools_schedule_dictionary_lookup_rebuild(true);
        ll_tools_dictionary_lookup_process_rebuild_batch();

        $all_query = ll_tools_dictionary_query_entries([
            'search' => 'su',
            'search_scope' => 'all',
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);
        $translation_query = ll_tools_dictionary_query_entries([
            'search' => 'su',
            'search_scope' => 'tr',
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);
        $headword_query = ll_tools_dictionary_query_entries([
            'search' => 'su',
            'search_scope' => 'headword',
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);
        $mixed_scope_query = ll_tools_dictionary_query_entries([
            'search' => 'su',
            'search_scopes' => ['headword', 'tr'],
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);

        $all_titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($all_query['items'] ?? [])));
        $translation_titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($translation_query['items'] ?? [])));
        $headword_titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($headword_query['items'] ?? [])));
        $mixed_scope_titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($mixed_scope_query['items'] ?? [])));

        $this->assertSame(['Su', 'Ava'], array_slice($all_titles, 0, 2));
        $this->assertSame(['Ava'], $translation_titles);
        $this->assertSame(['Su'], $headword_titles);
        $this->assertSame(['Su', 'Ava'], array_slice($mixed_scope_titles, 0, 2));

        $_GET = [
            'll_dictionary_q' => 'su',
            'll_dictionary_scope' => ['headword', 'tr'],
        ];
        $html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('name="ll_dictionary_scope[]"', $html);
        $this->assertMatchesRegularExpression('/value="headword"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/value="tr"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="en"[^>]*checked/', $html);
        $this->assertStringContainsString('ll-dictionary__translation-label">TR<', $html);
        $this->assertStringNotContainsString('ll-dictionary__translation-label">EN<', $html);
        $this->assertStringContainsString('kaynak', $html);
        $this->assertStringNotContainsString('spring', $html);
    }

    public function test_dictionary_import_job_processes_rows_in_batches_with_resume_snapshot(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $job = ll_tools_dictionary_import_create_tsv_job_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Roce',
                'definition' => 'day',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ], 'harun.tsv');

        $this->assertIsArray($job);
        $this->assertSame('running', $job['status']);
        $this->assertNotSame('', ll_tools_dictionary_import_get_active_job_id());

        $runningSnapshot = ll_tools_dictionary_import_get_job_snapshot($job);
        $this->assertSame('running', $runningSnapshot['status']);
        $this->assertStringContainsString('Keep', (string) $runningSnapshot['advice_title']);

        $processedJob = ll_tools_dictionary_import_process_job($job);
        $this->assertIsArray($processedJob);
        $this->assertSame('completed', $processedJob['status']);
        $this->assertSame('', ll_tools_dictionary_import_get_active_job_id());
        $this->assertSame(3, (int) ($processedJob['summary']['entries_created'] ?? 0));

        $completedSnapshot = ll_tools_dictionary_import_get_job_snapshot($processedJob);
        $this->assertSame('completed', $completedSnapshot['status']);
        $this->assertStringContainsString('Safe', (string) $completedSnapshot['advice_title']);
        $this->assertStringContainsString('Processed 3 rows into 3 dictionary headwords', (string) $completedSnapshot['summary_html']);

        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Dar', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Roce', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));
    }

    public function test_dictionary_import_job_can_skip_backup_snapshot_when_requested(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $job = ll_tools_dictionary_import_create_tsv_job_from_rows([
            [
                'entry' => 'Veng',
                'definition' => 'voice',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_backup_snapshot' => true,
        ], 'large-local.tsv');

        $this->assertIsArray($job);
        $this->assertSame('', (string) ($job['backup_snapshot_path'] ?? ''));
        $this->assertStringContainsString('skipped', (string) ($job['backup_snapshot_error'] ?? ''));
        $this->assertSame(0, (int) ($job['backup_entry_count'] ?? -1));

        $processedJob = ll_tools_dictionary_import_process_job($job);
        $this->assertIsArray($processedJob);
        $this->assertSame('completed', $processedJob['status']);
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Veng', 0));
    }

    public function test_dictionary_import_save_job_trims_large_tracking_arrays(): void
    {
        $summary = ll_tools_dictionary_import_default_summary(250);
        $summary['entries_created'] = LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ENTRY_IDS + 12;
        $summary['entry_ids'] = range(1, LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ENTRY_IDS + 40);
        $summary['errors'] = array_map(
            static fn (int $index): string => 'Import error ' . $index,
            range(1, LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ERRORS + 10)
        );
        $summary['error_count'] = count($summary['errors']);

        $saved = ll_tools_dictionary_import_save_job('trim-test-job', [
            'status' => 'running',
            'type' => 'tsv',
            'summary' => $summary,
        ]);

        $this->assertCount(LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ENTRY_IDS, (array) ($saved['summary']['entry_ids'] ?? []));
        $this->assertCount(LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ERRORS, (array) ($saved['summary']['errors'] ?? []));
        $this->assertSame(LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ERRORS + 10, (int) ($saved['summary']['error_count'] ?? 0));

        $summary_html = ll_tools_get_dictionary_import_summary_html((array) ($saved['summary'] ?? []), 'Trimmed');
        $this->assertStringContainsString(
            (string) (LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ENTRY_IDS + 12) . ' dictionary entries were touched.',
            $summary_html
        );
        $this->assertStringContainsString(
            (string) (LL_TOOLS_DICTIONARY_IMPORT_MAX_TRACKED_ERRORS + 2) . ' more errors not shown.',
            $summary_html
        );
    }

    public function test_dictionary_import_job_lock_blocks_parallel_processing_until_released(): void
    {
        $this->assertTrue(ll_tools_dictionary_import_acquire_job_lock('lock-test-job'));
        $this->assertFalse(ll_tools_dictionary_import_acquire_job_lock('lock-test-job'));

        ll_tools_dictionary_import_release_job_lock('lock-test-job');

        $this->assertTrue(ll_tools_dictionary_import_acquire_job_lock('lock-test-job'));
    }

    public function test_dictionary_import_recovers_orphaned_running_job_when_active_pointer_is_missing(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $job = ll_tools_dictionary_import_create_tsv_job_from_rows([
            [
                'entry' => 'Hew',
                'definition' => 'cloud',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ], 'orphaned.tsv');

        $this->assertIsArray($job);
        $job_id = (string) ($job['id'] ?? '');
        $this->assertNotSame('', $job_id);

        ll_tools_dictionary_import_clear_active_job_id($job_id);
        delete_user_meta($admin_id, LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY);

        $recovered_job = ll_tools_dictionary_import_get_relevant_job();

        $this->assertIsArray($recovered_job);
        $this->assertSame($job_id, (string) ($recovered_job['id'] ?? ''));
        $this->assertSame($job_id, ll_tools_dictionary_import_get_active_job_id());
        $this->assertSame($job_id, ll_tools_dictionary_import_get_last_job_id($admin_id));
    }

    public function test_header_tsv_import_supports_multilingual_gloss_columns(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('DEZD Wordset', 'wordset', ['slug' => 'dezd-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $temp_file = tempnam(sys_get_temp_dir(), 'lltd_');
        $this->assertNotFalse($temp_file);

        $tsv = implode("\n", [
            "entry\tdefinition\tgender_number\tentry_type\tparent\tneeds_review\tpage_number\tsource_dictionary\tsource_row_idx\traw_headword\ttitle_keys\tdefinition_full_tr\tdefinition_full_de\tdefinition_full_en",
            "Ava\tsu | Wasser | water\t\tnoun\t\t0\t6\tDEZD\t42\tava\tava|aw\tsu\tWasser\twater",
            "Ava rê\tsu phrase\t\tnoun\t\t0\t7\tDEZD\t43\tava rê\tava-re\tsulu ifade\tWasserphrase\twater phrase",
        ]);
        $this->assertNotFalse(file_put_contents($temp_file, $tsv));

        try {
            $rows = ll_tools_dictionary_parse_tsv_file($temp_file);
            $this->assertIsArray($rows);
            $this->assertSame('Wasser', $rows[0]['definition_full_de'] ?? '');

            $summary = ll_tools_dictionary_import_rows($rows, [
                'wordset_id' => $wordset_id,
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ]);
        } finally {
            @unlink($temp_file);
        }

        $this->assertSame(2, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Ava', $wordset_id);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(1, $senses);
        $this->assertSame('water', $senses[0]['definition']);
        $this->assertSame([
            'tr' => 'su',
            'de' => 'Wasser',
            'en' => 'water',
        ], $senses[0]['translations']);
        $this->assertSame('water', ll_tools_get_dictionary_entry_translation($entry_id));

        $this->assertSame('Wasser', ll_tools_dictionary_lookup_best('Ava', 'Zazaki', 'German', false));
        $this->assertSame('Ava', ll_tools_dictionary_lookup_best('water', 'English', 'Zazaki', true));
        $this->assertSame('Ava', ll_tools_dictionary_lookup_best('Wasser', 'German', 'Zazaki', true));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ava',
        ]);
        update_post_meta($word_id, 'word_translation', 'water');
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        $link_result = ll_tools_assign_dictionary_entry_to_word($word_id, $entry_id, '');
        $this->assertIsArray($link_result);
        $this->assertSame($entry_id, ll_tools_get_word_dictionary_entry_id($word_id));
        $this->assertContains($word_id, ll_tools_get_dictionary_entry_word_ids($entry_id, -1));

        $_COOKIE[LL_TOOLS_I18N_COOKIE] = 'de_DE';

        $_GET = [
            'll_dictionary_q' => 'Ava',
        ];

        $html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Wasser', $html);
        $this->assertStringContainsString('water', $html);
        $this->assertStringContainsString('su', $html);
        $this->assertStringContainsString('ll_dictionary_entry=' . $entry_id, $html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Back to dictionary', $detail_html);
        $this->assertStringContainsString('Definitions', $detail_html);
        $this->assertStringContainsString('Related Entries', $detail_html);
        $this->assertStringContainsString('Ava rê', $detail_html);
        $this->assertStringNotContainsString('ll-dictionary__detail-summary', $detail_html);
        $this->assertSame(1, substr_count($detail_html, 'll-dictionary__translation-label">DE<'));
        $this->assertSame(1, substr_count($detail_html, 'll-dictionary__translation-label">EN<'));
        $this->assertSame(1, substr_count($detail_html, 'll-dictionary__translation-label">TR<'));

        $de_position = strpos($detail_html, '>DE<');
        $en_position = strpos($detail_html, '>EN<');
        $tr_position = strpos($detail_html, '>TR<');
        $this->assertIsInt($de_position);
        $this->assertIsInt($en_position);
        $this->assertIsInt($tr_position);
        $this->assertLessThan($en_position, $de_position);
        $this->assertLessThan($tr_position, $de_position);
    }

    public function test_header_tsv_import_supports_short_gloss_column_names(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('Harun Gloss Wordset', 'wordset', ['slug' => 'harun-gloss-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $temp_file = tempnam(sys_get_temp_dir(), 'lltd_');
        $this->assertNotFalse($temp_file);

        $tsv = implode("\n", [
            "entry\tdefinition\tentry_type\tdefinition_tr\tgloss_de\ttranslation_en",
            "Ruec\t\tnoun\tgüneş\tSonne\tsun",
        ]);
        $this->assertNotFalse(file_put_contents($temp_file, $tsv));

        try {
            $rows = ll_tools_dictionary_parse_tsv_file($temp_file);
            $this->assertIsArray($rows);

            $summary = ll_tools_dictionary_import_rows($rows, [
                'wordset_id' => $wordset_id,
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ]);
        } finally {
            @unlink($temp_file);
        }

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Ruec', $wordset_id);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(1, $senses);
        $this->assertSame('güneş', $senses[0]['definition']);
        $this->assertSame('tr', ll_tools_dictionary_normalize_language_key((string) $senses[0]['def_lang']));
        $this->assertSame([
            'tr' => 'güneş',
            'de' => 'Sonne',
            'en' => 'sun',
        ], $senses[0]['translations']);
        $this->assertSame('güneş', ll_tools_get_dictionary_entry_translation($entry_id));

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Definitions', $detail_html);
        $this->assertGreaterThanOrEqual(1, substr_count($detail_html, 'güneş'));
        $this->assertStringContainsString('>TR<', $detail_html);
    }

    public function test_flex_style_tsv_import_preserves_examples_and_source_metadata(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('FLEx Import Wordset', 'wordset', ['slug' => 'flex-import-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $temp_file = tempnam(sys_get_temp_dir(), 'lltf_');
        $this->assertNotFalse($temp_file);

        $tsv = implode("\n", [
            "entry_guid\tsense_guid\tsense_index\theadword_zza\tall_forms_zza\tpart_of_speech_abbr_tr\tgloss_tr\treversal_tr\texamples_zza\texample_translations_tr\tsemantic_domains\tsource_id\tsource_dictionary\tsource_entry\tsource_attribution_text\tsource_default_dialects\tmorph_type\thomograph_number",
            "entry-guid-1\tsense-guid-1\t1\tViri\tViri || Vire\tis.d. || n.f\tlonging\tmemory\tThis is a sample sentence.\tBu ornek bir cumledir.\t3.4.2: Feelings\thayig-werner\tHayıg/Werner\trh\tProvided with permission for publication.\tÇermik\tstem\t0",
        ]);
        $this->assertNotFalse(file_put_contents($temp_file, $tsv));

        try {
            $rows = ll_tools_dictionary_parse_tsv_file($temp_file);
            $this->assertIsArray($rows);
            $this->assertSame('Viri', $rows[0]['headword_zza'] ?? '');

            $summary = ll_tools_dictionary_import_rows($rows, [
                'wordset_id' => $wordset_id,
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ]);
        } finally {
            @unlink($temp_file);
        }

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));
        $this->assertSame(1, (int) ($summary['sources_updated'] ?? 0));

        $source_registry = ll_tools_get_dictionary_source_registry();
        $this->assertArrayHasKey('hayig-werner', $source_registry);
        $this->assertSame('Provided with permission for publication.', $source_registry['hayig-werner']['attribution_text']);
        $this->assertSame(['Çermik'], $source_registry['hayig-werner']['default_dialects']);

        $entry_id = ll_tools_dictionary_find_entry_by_title('Viri', $wordset_id);
        $this->assertGreaterThan(0, $entry_id);
        $this->assertSame('noun', (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, true));

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(1, $senses);
        $this->assertSame('longing', $senses[0]['definition']);
        $this->assertSame(['tr' => 'longing'], $senses[0]['translations']);
        $this->assertSame(['Viri', 'Vire'], $senses[0]['headword_forms']);
        $this->assertSame(['memory'], $senses[0]['reversal_terms']);
        $this->assertSame(['This is a sample sentence.'], $senses[0]['examples']);
        $this->assertSame(['Bu ornek bir cumledir.'], $senses[0]['example_translations']);
        $this->assertSame(['3.4.2: Feelings'], $senses[0]['semantic_domains']);
        $this->assertSame('rh', $senses[0]['source_entry']);
        $this->assertSame('entry-guid-1:sense-guid-1', $senses[0]['source_row_idx']);

        $search_index = (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, true);
        $this->assertStringNotContainsString('sample sentence', $search_index);
        $this->assertStringContainsString('feelings', $search_index);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Examples', $detail_html);
        $this->assertStringContainsString('This is a sample sentence.', $detail_html);
        $this->assertStringContainsString('Bu ornek bir cumledir.', $detail_html);
        $this->assertStringContainsString('Hayıg/Werner', $detail_html);
    }

    public function test_import_strips_visible_provenance_prefixes_from_glosses(): void
    {
        $prepared = ll_tools_dictionary_prepare_import_row([
            'entry' => 'Zur',
            'definition' => 'harun@p399: zuma || dezd: zurna',
            'translation_en' => 'harun@p399: shawm || dezd: oboe',
            'source_id' => 'harun | dezd',
            'source_dictionary' => 'Palu - Bingöl Harun Turgut | DEZD - Kirmancki Dictionary',
            'source_row_idx' => 'harun:harun_p399_b0034:entry | dezd:dezd:19662:zurna',
            'entry_lang' => 'Zazaki',
            'def_lang' => 'Turkish',
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'Turkish',
        ]);

        $this->assertSame('Zur', $prepared['entry']);
        $this->assertSame('zuma; zurna', $prepared['definition']);
        $this->assertSame([
            'en' => 'shawm; oboe',
            'tr' => 'zuma; zurna',
        ], $prepared['translations']);
    }

    public function test_dictionary_search_prioritizes_exact_aliases_and_hides_duplicate_entry_type_badges(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $exact_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Roj, Ruec',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'raw_headword' => 'ruec',
                'title_keys' => 'ruec|roj',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $prefix_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Ruecarek',
                'definition' => 'sunlight',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $contains_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Mij û ruec',
                'definition' => 'moon and sun',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($exact_result);
        $this->assertIsArray($prefix_result);
        $this->assertIsArray($contains_result);

        $query = ll_tools_dictionary_query_entries([
            'search' => 'ruec',
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);

        $titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($query['items'] ?? [])));

        $this->assertSame('Roj, Ruec', $titles[0] ?? '');

        $entry_id = (int) ($exact_result['entry_id'] ?? 0);
        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('ll-dictionary__badge--pos', $detail_html);
        $this->assertStringNotContainsString('ll-dictionary__badge--type', $detail_html);
    }

    public function test_dictionary_search_results_clamp_long_summaries_with_expand_toggle(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $long_definition = 'The way of God, the way of truth, authentic faith of the Alevi Zonê Ma/Kirmancki speakers. '
            . 'A religion that follows the Alevi Four Gates and Forty Level Doctrine. '
            . 'Within this gradual development, the human being can move from ham to insan-i kamil. '
            . 'A humanistic, nature-loving religious community with shared social ethics and mutual aid.';

        $result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Raa haqi',
                'definition' => $long_definition,
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $this->assertIsArray($result);

        $_GET = [
            'll_dictionary_q' => 'Raa',
        ];

        $html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Show more', $html);
        $this->assertStringContainsString('data-ll-dictionary-text-block', $html);
        $this->assertStringContainsString('Raa haqi', $html);
    }

    public function test_dictionary_sources_apply_defaults_and_render_attribution_filters(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Used with permission from the DEZD glossary.',
                'attribution_url' => 'https://example.com/dezd-license',
                'default_dialects' => [],
            ],
            [
                'id' => 'harun-turgut',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Used with permission from Harun Turgut.',
                'attribution_url' => 'https://example.com/harun-license',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'source_dictionary' => 'Harun Turgut',
                'page_number' => '400',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Dar',
                'definition' => 'tree trunk',
                'entry_type' => 'noun',
                'source_dictionary' => 'DEZD',
                'dialects' => 'Siverek',
                'page_number' => '52',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Av',
                'definition' => 'water',
                'entry_type' => 'noun',
                'source_dictionary' => 'DEZD',
                'dialects' => 'Siverek',
                'page_number' => '53',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Zel',
                'definition' => 'grass',
                'entry_type' => 'noun',
                'source_id' => 'dezd-kirmancki-dictionary',
                'source_dictionary' => 'DEZD - Kirmancki Dictionary',
                'dialects' => 'Siverek',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Roj',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'source_id' => 'palu-bingol-harun-turgut',
                'source_dictionary' => 'Palu - Bingöl Harun Turgut',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Miv',
                'definition' => 'fruit',
                'entry_type' => 'noun',
                'source_id' => 'other-dictionary',
                'source_dictionary' => 'Other Dictionary',
                'source_attribution_text' => 'Other test source.',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(5, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Dar', 0);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(2, $senses);
        $source_ids = array_values(array_filter(array_map(static function (array $sense): string {
            return (string) ($sense['source_id'] ?? '');
        }, $senses)));
        $this->assertContains('dezd', $source_ids);
        $this->assertContains('harun-turgut', $source_ids);

        $dialects = ll_tools_dictionary_collect_dialects($senses);
        $this->assertContains('Palu - Bingöl', $dialects);
        $this->assertContains('Siverek', $dialects);

        $_GET = [
            'll_dictionary_source' => 'harun-turgut',
        ];

        $filtered_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('name="ll_dictionary_source[]"', $filtered_html);
        $this->assertStringNotContainsString('name="ll_dictionary_dialect"', $filtered_html);
        $this->assertStringContainsString('Source dictionaries', $filtered_html);
        $this->assertStringContainsString('Dialect not marked', $filtered_html);
        $this->assertStringContainsString('Palu - Bing', $filtered_html);
        $this->assertStringContainsString('Dar', $filtered_html);
        $this->assertStringContainsString('sun', $filtered_html);
        $this->assertStringNotContainsString('water', $filtered_html);
        $this->assertStringNotContainsString('grass', $filtered_html);
        $this->assertStringContainsString('Harun Turgut', $filtered_html);
        $this->assertStringContainsString('ll-dictionary__badge--external', $filtered_html);
        $this->assertStringContainsString('aria-label="Open source page for Harun Turgut"', $filtered_html);

        $_GET = [
            'll_dictionary_source' => 'dezd',
        ];

        $dezd_filtered_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('water', $dezd_filtered_html);
        $this->assertStringContainsString('grass', $dezd_filtered_html);
        $this->assertStringNotContainsString('sun', $dezd_filtered_html);

        $_GET = [
            'll_dictionary_source' => ['harun-turgut', 'dezd'],
        ];

        $multi_filtered_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Dar', $multi_filtered_html);
        $this->assertStringContainsString('water', $multi_filtered_html);
        $this->assertStringContainsString('grass', $multi_filtered_html);
        $this->assertStringContainsString('sun', $multi_filtered_html);
        $this->assertStringNotContainsString('fruit', $multi_filtered_html);

        $source_filter_url = ll_tools_dictionary_build_url('https://example.com/sozluk/', [
            'll_dictionary_source' => ['harun-turgut', 'dezd'],
        ]);
        $this->assertStringContainsString('ll_dictionary_source=harun-turgut_dezd', $source_filter_url);

        $_GET = [
            'll_dictionary_source' => 'harun-turgut_dezd',
        ];

        $canonical_filtered_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Dar', $canonical_filtered_html);
        $this->assertStringContainsString('water', $canonical_filtered_html);
        $this->assertStringContainsString('grass', $canonical_filtered_html);
        $this->assertStringContainsString('sun', $canonical_filtered_html);
        $this->assertStringNotContainsString('fruit', $canonical_filtered_html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('DEZD', $detail_html);
        $this->assertStringContainsString('Harun Turgut', $detail_html);
        $this->assertStringContainsString('Palu - Bingöl', $detail_html);
        $this->assertStringContainsString('Siverek', $detail_html);
        $this->assertStringContainsString('View source page', $detail_html);
        $this->assertStringContainsString('https://example.com/dezd-license', $detail_html);
        $this->assertStringContainsString('https://example.com/harun-license', $detail_html);
        $this->assertStringNotContainsString('Page 400', $detail_html);
        $this->assertStringNotContainsString('Page 52', $detail_html);
    }

    public function test_dictionary_search_matches_flex_apostrophe_headwords_with_ascii_input(): void
    {
        $headword = "\xE2\x80\x98sa";

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => $headword,
                'definition' => 'apple',
                'source_id' => 'hayig-werner',
                'source_dictionary' => 'Hayıg/Werner',
                'dialects' => 'Çermik',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $_GET = [
            'll_dictionary_q' => "'sa",
            'll_dictionary_source' => 'hayig-werner',
        ];

        $html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString($headword, $html);
        $this->assertStringContainsString('apple', $html);
        $this->assertStringContainsString('Hayıg/Werner', $html);
        $this->assertStringContainsString('Çermik', $html);
    }

    public function test_dictionary_admin_pages_render_source_navigation_and_stacked_source_fields(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Used with permission from Harun Turgut.',
                'attribution_url' => 'https://example.com/harun-license',
                'default_dialects' => ['Palu - Bingol'],
            ],
        ]);

        $previous_server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        ll_tools_render_dictionary_import_page();
        $manager_html = (string) ob_get_clean();

        ob_start();
        ll_tools_render_dictionary_sources_page();
        $sources_html = (string) ob_get_clean();

        $_SERVER = $previous_server;

        $this->assertStringContainsString('Manage Dictionary Sources', $manager_html);
        $this->assertStringContainsString('tools.php?page=ll-dictionary-sources', $manager_html);
        $this->assertStringNotContainsString('ll_dictionary_skip_review_rows', $manager_html);
        $this->assertStringNotContainsString('Legacy Migration', $manager_html);
        $this->assertStringNotContainsString('Migrate Legacy Dictionary Table', $manager_html);
        $this->assertStringContainsString('Back to Dictionary Manager', $sources_html);
        $this->assertStringContainsString('ll-dictionary-sources-admin__rows', $sources_html);
        $this->assertStringContainsString('ll-dictionary-sources-admin__field--full', $sources_html);
        $this->assertStringContainsString('ll_dictionary_sources[0][attribution_url]', $sources_html);
        $this->assertStringContainsString('Harun Turgut', $sources_html);
    }

    public function test_dictionary_source_filter_matches_registered_label_against_short_import_source_id(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun-turgut',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Used with permission from Harun Turgut.',
                'attribution_url' => 'https://example.com/harun-license',
                'default_dialects' => ['Palu - Bingol'],
            ],
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Used with permission from DEZD.',
                'attribution_url' => 'https://example.com/dezd-license',
                'default_dialects' => [],
            ],
        ]);

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Ava Alias Harun',
                'definition' => 'harun alias water',
                'entry_type' => 'noun',
                'source_id' => 'harun',
                'source_dictionary' => 'Palu - Bingol Harun Turgut',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Ava Alias Dezd',
                'definition' => 'dezd alias water',
                'entry_type' => 'noun',
                'source_id' => 'dezd',
                'source_dictionary' => 'DEZD',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(2, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Ava Alias Harun', 0);
        $this->assertGreaterThan(0, $entry_id);
        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertSame('harun', (string) ($senses[0]['source_id'] ?? ''));

        $results = ll_tools_dictionary_query_entries([
            'source_ids' => ['harun-turgut'],
            'page' => 1,
            'per_page' => 10,
            'sense_limit' => 3,
            'linked_word_limit' => 0,
            'post_status' => ['publish'],
        ]);

        $this->assertSame(1, (int) ($results['total'] ?? 0));
        $this->assertSame('Ava Alias Harun', (string) ($results['items'][0]['title'] ?? ''));

        $_GET = [
            'll_dictionary_source' => 'harun-turgut',
        ];

        $html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Ava Alias Harun', $html);
        $this->assertStringContainsString('harun alias water', $html);
        $this->assertStringNotContainsString('Ava Alias Dezd', $html);
        $this->assertStringNotContainsString('No entries matched this filter yet.', $html);
    }

    public function test_dictionary_scope_filter_index_refreshes_after_source_and_entry_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('Dictionary Cache Wordset', 'wordset', ['slug' => 'dictionary-cache-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_language', 'zza');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Original attribution.',
                'attribution_url' => 'https://example.com/harun',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'source_id' => 'harun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $initial_index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
        $this->assertContains('A', (array) ($initial_index['letters'] ?? []));
        $this->assertContains('Palu - Bingöl', (array) ($initial_index['dialect_options'] ?? []));
        $this->assertSame('noun', (string) ($initial_index['pos_options'][0]['slug'] ?? ''));
        $this->assertSame('Harun Turgut', (string) ($initial_index['source_options'][0]['label'] ?? ''));

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun',
                'label' => 'Harun Updated',
                'attribution_text' => 'Updated attribution.',
                'attribution_url' => 'https://example.com/harun-updated',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $updated_index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
        $this->assertSame('Harun Updated', (string) ($updated_index['source_options'][0]['label'] ?? ''));

        $follow_up_summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Êvar',
                'definition' => 'evening',
                'entry_type' => 'noun',
                'source_id' => 'harun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(1, (int) ($follow_up_summary['entries_created'] ?? 0));
        $this->assertContains('Ê', ll_tools_dictionary_get_available_letters($wordset_id));
    }

    public function test_dictionary_query_cache_refreshes_after_linked_word_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $entry_id = (int) ($entry_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $entry_id);

        $palu_wordset = wp_insert_term('Palu Cache Wordset', 'wordset', ['slug' => 'palu-cache-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Cache Wordset', 'wordset', ['slug' => 'bingol-cache-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($palu_word_id, 'word_translation', 'tree');
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);
        $this->assertIsArray(ll_tools_assign_dictionary_entry_to_word($palu_word_id, $entry_id, ''));

        $first_query = ll_tools_dictionary_query_entries([
            'search' => 'Dar',
            'page' => 1,
            'per_page' => 5,
            'sense_limit' => 1,
            'linked_word_limit' => 4,
            'post_status' => ['publish'],
        ]);

        $this->assertSame(1, (int) ($first_query['total'] ?? 0));
        $first_item = (array) (($first_query['items'][0] ?? []));
        $this->assertSame(1, (int) ($first_item['linked_word_count'] ?? 0));
        $this->assertSame(['Palu Cache Wordset'], array_values(array_map('strval', (array) ($first_item['wordset_names'] ?? []))));

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($bingol_word_id, 'word_translation', 'tree');
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);
        $this->assertIsArray(ll_tools_assign_dictionary_entry_to_word($bingol_word_id, $entry_id, ''));

        $second_query = ll_tools_dictionary_query_entries([
            'search' => 'Dar',
            'page' => 1,
            'per_page' => 5,
            'sense_limit' => 1,
            'linked_word_limit' => 4,
            'post_status' => ['publish'],
        ]);

        $this->assertSame(1, (int) ($second_query['total'] ?? 0));
        $second_item = (array) (($second_query['items'][0] ?? []));
        $wordset_names = array_values(array_map('strval', (array) ($second_item['wordset_names'] ?? [])));
        sort($wordset_names);
        $this->assertSame(['Bingol Cache Wordset', 'Palu Cache Wordset'], $wordset_names);
        $this->assertSame(2, (int) ($second_item['linked_word_count'] ?? 0));
    }

    public function test_find_entry_by_title_backfills_lookup_meta_without_wordset_join_regression(): void
    {
        $wordset = wp_insert_term('Legacy Dictionary Wordset', 'wordset', ['slug' => 'legacy-dictionary-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Legacy Dar',
        ]);
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
        delete_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $resolved_id = ll_tools_dictionary_find_entry_by_title('Legacy Dar', $wordset_id);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame($entry_id, $resolved_id);
        $this->assertSame('legacy dar', (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, true));

        $queries_sql = implode("\n", $queries);
        $this->assertStringContainsString('ll_dictionary_entry_lookup_title', $queries_sql);
        $this->assertStringNotContainsString('ll_dictionary_entry_wordset_id', $queries_sql);
        $this->assertStringNotContainsString('CAST(COALESCE', $queries_sql);
        $this->assertStringNotContainsString('ORDER BY p.ID ASC LIMIT 1', $queries_sql);
    }

    public function test_dictionary_letter_browse_avoids_search_meta_join_regression(): void
    {
        global $wpdb;

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $first = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $second = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dımılki',
                'definition' => 'Zazaki',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        ll_tools_bump_dictionary_browser_cache_version();

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $results = ll_tools_dictionary_query_entries([
                'letter' => 'D',
                'page' => 1,
                'per_page' => 10,
                'sense_limit' => 1,
                'linked_word_limit' => 0,
                'post_status' => ['publish'],
            ]);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame(2, (int) ($results['total'] ?? 0));
        $browse_queries_sql = implode("\n", array_filter($queries, static function (string $query) use ($wpdb): bool {
            return strpos($query, "FROM {$wpdb->posts} p") !== false;
        }));
        $this->assertStringContainsString('BINARY LEFT(TRIM(p.post_title)', $browse_queries_sql);
        $this->assertStringContainsString("p.post_type = 'll_dictionary_entry'", $browse_queries_sql);
        $this->assertStringNotContainsString('ll_dictionary_entry_lookup_title', $browse_queries_sql);
        $this->assertStringNotContainsString('lookup_title.meta_value LIKE', $browse_queries_sql);
        $this->assertStringNotContainsString('lookup_translation.meta_value', $browse_queries_sql);
        $this->assertStringNotContainsString('search_index.meta_value', $browse_queries_sql);
    }

    public function test_dictionary_entry_detail_spans_multiple_wordsets_when_words_share_one_entry(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $entry_id = (int) ($entry_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $entry_id);

        $palu_wordset = wp_insert_term('Palu Wordset', 'wordset', ['slug' => 'palu-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Wordset', 'wordset', ['slug' => 'bingol-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($palu_word_id, 'word_translation', 'tree');
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($bingol_word_id, 'word_translation', 'tree');
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);

        $first_link_result = ll_tools_assign_dictionary_entry_to_word($palu_word_id, $entry_id, '');
        $second_link_result = ll_tools_assign_dictionary_entry_to_word($bingol_word_id, $entry_id, '');
        $this->assertIsArray($first_link_result);
        $this->assertIsArray($second_link_result);

        $scope_wordset_ids = ll_tools_get_dictionary_entry_scope_wordset_ids($entry_id);
        sort($scope_wordset_ids);
        $expected_scope_ids = [$palu_wordset_id, $bingol_wordset_id];
        sort($expected_scope_ids);
        $this->assertSame($expected_scope_ids, $scope_wordset_ids);
        $this->assertSame(0, ll_tools_get_dictionary_entry_wordset_id($entry_id));

        $_GET = [
            'll_dictionary_q' => 'Dar',
        ];

        $search_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Palu Wordset', $search_html);
        $this->assertStringContainsString('Bingol Wordset', $search_html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Palu Wordset', $detail_html);
        $this->assertStringContainsString('Bingol Wordset', $detail_html);
    }

    public function test_dictionary_snapshot_override_and_undo_preserve_linked_words(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Original DEZD attribution.',
                'attribution_url' => 'https://example.com/dezd',
                'default_dialects' => [],
            ],
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Original Harun attribution.',
                'attribution_url' => 'https://example.com/harun',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $dar_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'source_dictionary' => 'Harun Turgut',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $this->assertIsArray($dar_result);

        $roc_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Roc',
                'definition' => 'day',
                'entry_type' => 'noun',
                'source_dictionary' => 'DEZD',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $this->assertIsArray($roc_result);

        $dar_entry_id = (int) ($dar_result['entry_id'] ?? 0);
        $roc_entry_id = (int) ($roc_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $dar_entry_id);
        $this->assertGreaterThan(0, $roc_entry_id);

        $dar_import_key = ll_tools_get_dictionary_entry_import_key($dar_entry_id, true);
        $this->assertNotSame('', $dar_import_key);

        $palu_wordset = wp_insert_term('Palu Snapshot Wordset', 'wordset', ['slug' => 'palu-snapshot-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Snapshot Wordset', 'wordset', ['slug' => 'bingol-snapshot-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);
        update_post_meta($palu_word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $dar_entry_id);

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);
        update_post_meta($bingol_word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $dar_entry_id);

        $snapshot = ll_tools_dictionary_build_snapshot();
        $this->assertSame(2, (int) ($snapshot['entry_count'] ?? 0));
        $this->assertSame('ll-tools-dictionary-snapshot', $snapshot['format'] ?? '');

        $snapshot['sources'] = [
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Updated DEZD attribution.',
                'attribution_url' => 'https://example.com/dezd-updated',
                'default_dialects' => ['Siverek'],
            ],
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Updated Harun attribution.',
                'attribution_url' => 'https://example.com/harun-updated',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ];

        $snapshot['entries'] = array_values(array_filter(array_map(static function (array $entry): ?array {
            if (($entry['title'] ?? '') === 'Roc') {
                return null;
            }
            if (($entry['title'] ?? '') === 'Dar') {
                $entry['translation'] = 'tree updated';
                $entry['senses'][0]['definition'] = 'tree updated';
                $entry['senses'][0]['source_id'] = 'harun';
                $entry['senses'][0]['source_dictionary'] = 'Harun Turgut';
                return $entry;
            }

            return $entry;
        }, (array) ($snapshot['entries'] ?? []))));

        $snapshot['entries'][] = [
            'import_key' => ll_tools_dictionary_generate_import_key(),
            'title' => 'Ava',
            'status' => 'publish',
            'translation' => 'water',
            'wordset' => null,
            'senses' => [
                [
                    'definition' => 'water',
                    'entry_type' => 'noun',
                    'entry_lang' => 'Zazaki',
                    'def_lang' => 'English',
                    'source_id' => 'dezd',
                    'source_dictionary' => 'DEZD',
                    'dialects' => ['Siverek'],
                    'translations' => [
                        'en' => 'water',
                    ],
                ],
            ],
        ];
        $snapshot['entry_count'] = count($snapshot['entries']);

        $job = ll_tools_dictionary_import_create_snapshot_job_from_snapshot($snapshot, [
            'snapshot_mode' => 'override',
        ], 'dictionary-site.json');
        $this->assertIsArray($job);
        $this->assertSame('running', $job['status']);
        $this->assertNotSame('', (string) ($job['backup_snapshot_path'] ?? ''));
        $this->assertFileExists((string) $job['backup_snapshot_path']);

        while (is_array($job) && (string) ($job['status'] ?? '') === 'running') {
            $job = ll_tools_dictionary_import_process_job($job);
        }

        $this->assertIsArray($job);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(1, (int) ($job['summary']['entries_created'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($job['summary']['entries_updated'] ?? 0));
        $this->assertSame(1, (int) ($job['summary']['entries_deleted'] ?? 0));

        $dar_entry_after_id = ll_tools_dictionary_find_entry_by_import_key($dar_import_key);
        $this->assertSame($dar_entry_id, $dar_entry_after_id);
        $this->assertContains($palu_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_after_id, -1));
        $this->assertContains($bingol_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_after_id, -1));
        $this->assertSame('tree updated', ll_tools_get_dictionary_entry_translation($dar_entry_after_id));
        $this->assertSame(0, ll_tools_dictionary_find_entry_by_title('Roc', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));

        $sources_after_override = ll_tools_get_dictionary_source_registry();
        $this->assertSame('Updated DEZD attribution.', $sources_after_override['dezd']['attribution_text'] ?? '');
        $this->assertSame('https://example.com/harun-updated', $sources_after_override['harun']['attribution_url'] ?? '');

        $history = ll_tools_dictionary_import_read_history();
        $this->assertNotEmpty($history);
        $latest_history = $history[0];
        $this->assertSame('snapshot', $latest_history['type'] ?? '');
        $this->assertSame('dictionary-site.json', $latest_history['source_label'] ?? '');

        $undo_job = ll_tools_dictionary_import_create_undo_job((string) ($latest_history['id'] ?? ''));
        $this->assertIsArray($undo_job);

        while (is_array($undo_job) && (string) ($undo_job['status'] ?? '') === 'running') {
            $undo_job = ll_tools_dictionary_import_process_job($undo_job);
        }

        $this->assertIsArray($undo_job);
        $this->assertSame('completed', $undo_job['status']);
        $this->assertSame($dar_entry_id, ll_tools_dictionary_find_entry_by_import_key($dar_import_key));
        $this->assertSame('tree', ll_tools_get_dictionary_entry_translation($dar_entry_id));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Roc', 0));
        $this->assertSame(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));
        $this->assertContains($palu_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_id, -1));
        $this->assertContains($bingol_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_id, -1));

        $history_after_undo = ll_tools_dictionary_import_read_history();
        $this->assertNotEmpty($history_after_undo);
        $this->assertSame('undo', $history_after_undo[0]['history_mode'] ?? '');
        $this->assertNotEmpty($history_after_undo[1]['undone_at'] ?? 0);

        $sources_after_undo = ll_tools_get_dictionary_source_registry();
        $this->assertSame('Original DEZD attribution.', $sources_after_undo['dezd']['attribution_text'] ?? '');
        $this->assertSame('https://example.com/harun', $sources_after_undo['harun']['attribution_url'] ?? '');
    }

    public function test_shortcode_starts_compact_and_exposes_language_specific_letter_browse(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('Zazaki Browse Wordset', 'wordset', ['slug' => 'zazaki-browse-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_language', 'zza');

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Êvar',
                'definition' => 'evening',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'İnce',
                'definition' => 'thin',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Ûsiv',
                'definition' => 'something',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(4, (int) ($summary['entries_created'] ?? 0));

        $_GET = [];
        $idle_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('ll-dictionary__toolbar is-collapsed', $idle_html);
        $this->assertStringContainsString('name="ll_dictionary_q"', $idle_html);
        $this->assertStringNotContainsString('ll-dictionary__results', $idle_html);
        $this->assertStringNotContainsString('Showing 1-20', $idle_html);
        $this->assertStringContainsString('data-ll-dictionary-toolbar-deferred="0"', $idle_html);
        $this->assertStringNotContainsString('ll-dictionary__toolbar-panel--deferred', $idle_html);
        $this->assertStringContainsString('name="ll_dictionary_scope[]"', $idle_html);
        $this->assertStringContainsString('Search settings', $idle_html);
        $this->assertStringContainsString('Search in languages', $idle_html);
        $this->assertStringNotContainsString('name="ll_dictionary_pos[]"', $idle_html);
        $this->assertStringNotContainsString('ll-dictionary__hint', $idle_html);
        $this->assertStringContainsString('ll-dictionary__letters', $idle_html);
        $this->assertStringContainsString('ll_dictionary_letter=', $idle_html);

        $_GET = [
            'll_dictionary_letter' => 'Ê',
        ];
        $letter_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Êvar', $letter_html);
        $this->assertStringNotContainsString('Ava', $letter_html);
        $this->assertStringContainsString('Showing 1-1 of 1', $letter_html);
    }

    public function test_unscoped_dictionary_infers_zazaki_letters_and_keeps_i_buckets_distinct(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Lapik',
                'definition' => 'glove',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Işık',
                'definition' => 'light',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'İnce',
                'definition' => 'thin',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'ire',
                'definition' => 'this',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertSame(4, (int) ($summary['entries_created'] ?? 0));
        $this->assertSame('zza', ll_tools_dictionary_get_effective_title_language_code(0));

        $_GET = [];
        $idle_html = do_shortcode('[ll_dictionary]');
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Ç\s*<\/a>/u', $idle_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Ş\s*<\/a>/u', $idle_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*İ\s*<\/a>/u', $idle_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Û\s*<\/a>/u', $idle_html);

        wp_set_current_user(0);
        $_POST = [
            'action' => 'll_tools_dictionary_toolbar_bootstrap',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/sozluk/',
            'wordset_id' => 0,
        ];
        $_REQUEST = $_POST;

        try {
            $toolbar = $this->run_json_endpoint(static function (): void {
                ll_tools_dictionary_handle_toolbar_bootstrap();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($toolbar['success'] ?? false));
        $toolbar_html = (string) ($toolbar['data']['html'] ?? '');
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Ç\s*<\/a>/u', $toolbar_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Ş\s*<\/a>/u', $toolbar_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*İ\s*<\/a>/u', $toolbar_html);
        $this->assertMatchesRegularExpression('/ll-dictionary__letter[^>]*>\s*Û\s*<\/a>/u', $toolbar_html);

        $_GET = [
            'll_dictionary_letter' => 'I',
        ];
        $dotless_i_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Işık', $dotless_i_html);
        $this->assertStringNotContainsString('Lapik', $dotless_i_html);
        $this->assertStringNotContainsString('İnce', $dotless_i_html);
        $this->assertStringNotContainsString('ire', $dotless_i_html);
        $this->assertStringContainsString('Showing 1-1 of 1', $dotless_i_html);

        $_GET = [
            'll_dictionary_letter' => 'İ',
        ];
        $dotted_i_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('İnce', $dotted_i_html);
        $this->assertStringContainsString('ire', $dotted_i_html);
        $this->assertStringNotContainsString('Işık', $dotted_i_html);
        $this->assertStringNotContainsString('Lapik', $dotted_i_html);
        $this->assertStringContainsString('Showing 1-2 of 2', $dotted_i_html);
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): void
    {
        $existing = get_term_by('slug', $slug, 'part_of_speech');
        if ($existing && !is_wp_error($existing)) {
            return;
        }

        $result = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        $this->assertTrue(is_array($result) || is_wp_error($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
