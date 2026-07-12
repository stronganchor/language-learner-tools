<?php
declare(strict_types=1);

final class WordsetPageDurableCacheTest extends LL_Tools_TestCase
{
    public function test_durable_cache_envelope_round_trips_multilingual_and_four_byte_text(): void
    {
        $key = $this->uniqueCacheKey('roundtrip');
        $payload = [
            'title' => 'Genç-Palu',
            'translation' => 'İş, görev',
            'icons' => ['🎓', '🎧'],
            'categories' => array_map(
                static fn (int $index): array => [
                    'id' => $index,
                    'name' => 'Kategori ' . $index . ' – Çalışma',
                ],
                range(1, 200)
            ),
        ];
        $request_cache = [];
        $durable_stored = false;

        try {
            ll_tools_wordset_page_store_cached_payload(
                $key,
                $payload,
                5 * MINUTE_IN_SECONDS,
                $request_cache,
                'll_tools',
                $durable_stored
            );

            $this->assertTrue($durable_stored);
            $raw = get_transient($key);
            $this->assertIsString($raw);
            $this->assertStringStartsWith(ll_tools_wordset_page_durable_cache_envelope_prefix(), $raw);
            $this->assertSame(1, preg_match('/^[\x00-\x7F]+$/', $raw));

            $read_request_cache = [];
            $this->assertSame(
                $payload,
                ll_tools_wordset_page_get_cached_payload($key, $read_request_cache)
            );
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    public function test_durable_cache_reader_accepts_valid_legacy_transient_values(): void
    {
        $key = $this->uniqueCacheKey('legacy');
        $payload = ['legacy' => true, 'label' => 'Geçerli'];
        $request_cache = [];

        try {
            set_transient($key, $payload, 5 * MINUTE_IN_SECONDS);

            $this->assertSame(
                $payload,
                ll_tools_wordset_page_get_cached_payload($key, $request_cache)
            );
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    public function test_malformed_object_cache_envelope_falls_back_to_valid_transient(): void
    {
        $key = $this->uniqueCacheKey('malformed');
        $payload = ['fallback' => 'valid'];
        $request_cache = [];

        try {
            set_transient($key, $payload, 5 * MINUTE_IN_SECONDS);
            wp_cache_set(
                $key,
                ll_tools_wordset_page_durable_cache_envelope_prefix() . 'not-valid-base64!',
                'll_tools',
                5 * MINUTE_IN_SECONDS
            );

            $this->assertSame(
                $payload,
                ll_tools_wordset_page_get_cached_payload($key, $request_cache)
            );
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    public function test_durable_cache_decoder_rejects_noncanonical_and_trailing_data(): void
    {
        $payload = ['safe' => true];
        $serialized = serialize($payload);
        $prefix = ll_tools_wordset_page_durable_cache_envelope_prefix();

        $valid = true;
        $this->assertNull(ll_tools_wordset_page_decode_durable_cache_payload(
            $prefix . base64_encode($serialized . 'junk'),
            $valid
        ));
        $this->assertFalse($valid);

        $valid = true;
        $canonical = base64_encode($serialized);
        $this->assertNull(ll_tools_wordset_page_decode_durable_cache_payload(
            $prefix . substr($canonical, 0, 4) . "\n" . substr($canonical, 4),
            $valid
        ));
        $this->assertFalse($valid);
    }

    public function test_shared_lazy_payload_repairs_orphan_timeout_and_refreshes_expiry(): void
    {
        $token = 'shared_' . md5(wp_generate_password(20, false, false));
        $key = ll_tools_wordset_page_lazy_cards_cache_key($token);
        $timeout_option = '_transient_timeout_' . $key;
        $old_timeout = time() + 5;
        $payload = [
            'cards' => [],
            'render_context' => [
                'mode_ui' => ['learning' => ['icon' => '🎓']],
            ],
            'batch_size' => 18,
            'base_offset' => 18,
            'total' => 18,
            'user_id' => 0,
        ];

        ll_tools_wordset_page_delete_durable_cached_payload($key);
        add_option($timeout_option, (string) $old_timeout, '', false);
        wp_cache_set($key, $payload, 'll_tools', 120);

        try {
            $this->assertSame(
                $token,
                ll_tools_wordset_page_store_lazy_cards_payload($payload, 120, $token)
            );
            $raw = get_transient($key);
            $this->assertIsString($raw);
            $this->assertStringStartsWith(ll_tools_wordset_page_durable_cache_envelope_prefix(), $raw);
            $this->assertSame($payload, ll_tools_wordset_page_get_lazy_cards_payload($token));
            $dependency_floor = ll_tools_wordset_page_public_static_dependency_ttl(
                30 * MINUTE_IN_SECONDS,
                'lazy_cards'
            );
            $this->assertGreaterThanOrEqual(
                time() + $dependency_floor - 5,
                (int) get_option($timeout_option, 0)
            );
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    public function test_shared_category_search_payload_uses_same_durable_contract(): void
    {
        $token = 'search_' . md5(wp_generate_password(20, false, false));
        $key = ll_tools_wordset_page_category_search_payload_cache_key($token);
        $payload = [
            'wordset_id' => 7970,
            'categories' => [['id' => 1, 'name' => 'Öğren 🎧']],
        ];

        try {
            $this->assertSame(
                $token,
                ll_tools_wordset_page_store_category_search_payload($payload, 120, $token)
            );
            $raw = get_transient($key);
            $this->assertIsString($raw);
            $this->assertStringStartsWith(ll_tools_wordset_page_durable_cache_envelope_prefix(), $raw);
            $this->assertSame($payload, ll_tools_wordset_page_get_category_search_payload($token));
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    public function test_failed_durable_readback_returns_no_lazy_token_and_cleans_orphan_rows(): void
    {
        $token = 'shared_' . md5(wp_generate_password(20, false, false));
        $key = ll_tools_wordset_page_lazy_cards_cache_key($token);
        $value_option = '_transient_' . $key;
        $timeout_option = '_transient_timeout_' . $key;
        $payload = [
            'cards' => [],
            'render_context' => ['mode' => '🎓'],
            'batch_size' => 18,
            'base_offset' => 18,
            'total' => 18,
            'user_id' => 0,
        ];
        $delete_added_value = static function (string $option_name) use ($value_option): void {
            if ($option_name !== $value_option) {
                return;
            }

            global $wpdb;
            $wpdb->delete($wpdb->options, ['option_name' => $value_option], ['%s']);
            wp_cache_delete($value_option, 'options');
        };

        ll_tools_wordset_page_delete_durable_cached_payload($key);
        add_action('added_option', $delete_added_value, 10, 1);

        try {
            $this->assertSame('', ll_tools_wordset_page_store_lazy_cards_payload($payload, 120, $token));
        } finally {
            remove_action('added_option', $delete_added_value, 10);
        }

        global $wpdb;
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->options}
             WHERE option_name IN (%s, %s)",
            $value_option,
            $timeout_option
        ));
        $this->assertSame(0, $remaining);

        ll_tools_wordset_page_delete_durable_cached_payload($key);
    }

    public function test_shared_dependency_default_ttl_outlives_public_static_file(): void
    {
        $static_ttl = function_exists('ll_tools_public_static_cache_ttl')
            ? ll_tools_public_static_cache_ttl()
            : DAY_IN_SECONDS;

        $this->assertGreaterThan(
            $static_ttl,
            ll_tools_wordset_page_public_static_dependency_ttl(30 * MINUTE_IN_SECONDS, 'lazy_cards')
        );

        $unbounded_grace_filter = static function (): int {
            return PHP_INT_MAX;
        };
        add_filter('ll_tools_wordset_page_public_static_dependency_cache_grace', $unbounded_grace_filter);
        try {
            $this->assertSame(
                $static_ttl + HOUR_IN_SECONDS,
                ll_tools_wordset_page_public_static_dependency_ttl(30 * MINUTE_IN_SECONDS, 'lazy_cards')
            );
        } finally {
            remove_filter('ll_tools_wordset_page_public_static_dependency_cache_grace', $unbounded_grace_filter);
        }
    }

    public function test_shared_lazy_payload_filter_cannot_shorten_static_dependency_floor(): void
    {
        $token = 'shared_' . md5(wp_generate_password(20, false, false));
        $key = ll_tools_wordset_page_lazy_cards_cache_key($token);
        $timeout_option = '_transient_timeout_' . $key;
        $payload = [
            'cards' => [],
            'render_context' => [],
            'batch_size' => 18,
            'base_offset' => 18,
            'total' => 18,
            'user_id' => 0,
        ];
        $short_ttl_filter = static function (): int {
            return MINUTE_IN_SECONDS;
        };
        add_filter('ll_tools_wordset_page_lazy_cards_cache_ttl', $short_ttl_filter);

        try {
            $this->assertSame($token, ll_tools_wordset_page_store_lazy_cards_payload($payload, 0, $token));
            $floor = ll_tools_wordset_page_public_static_dependency_ttl(
                30 * MINUTE_IN_SECONDS,
                'lazy_cards'
            );
            $this->assertGreaterThanOrEqual(time() + $floor - 5, (int) get_option($timeout_option, 0));
        } finally {
            remove_filter('ll_tools_wordset_page_lazy_cards_cache_ttl', $short_ttl_filter);
            ll_tools_wordset_page_delete_durable_cached_payload($key);
        }
    }

    private function uniqueCacheKey(string $suffix): string
    {
        return 'll_wsp_test_' . sanitize_key($suffix) . '_' . md5(wp_generate_password(20, false, false));
    }
}
