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

    public function test_identical_authenticated_payloads_do_not_rewrite_durable_rows_before_refresh_window(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $payloads = [
            [
                'token' => 'private_' . md5(wp_generate_password(20, false, false)),
                'cache_key' => null,
                'payload' => [
                    'cards' => [['id' => 17]],
                    'render_context' => [],
                    'batch_size' => 18,
                    'base_offset' => 18,
                    'total' => 19,
                    'user_id' => $user_id,
                ],
                'store' => 'll_tools_wordset_page_store_lazy_cards_payload',
            ],
            [
                'token' => 'private_' . md5(wp_generate_password(20, false, false)),
                'cache_key' => null,
                'payload' => [
                    'wordset_id' => 7970,
                    'category_ids' => [11, 12],
                    'user_id' => $user_id,
                ],
                'store' => 'll_tools_wordset_page_store_category_search_payload',
            ],
        ];
        $payloads[0]['cache_key'] = ll_tools_wordset_page_lazy_cards_cache_key($payloads[0]['token']);
        $payloads[1]['cache_key'] = ll_tools_wordset_page_category_search_payload_cache_key($payloads[1]['token']);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        try {
            foreach ($payloads as $fixture) {
                $this->assertSame(
                    $fixture['token'],
                    call_user_func($fixture['store'], $fixture['payload'], 5 * MINUTE_IN_SECONDS, $fixture['token'])
                );
            }

            add_filter('query', $capture);
            foreach ($payloads as $fixture) {
                $this->assertSame(
                    $fixture['token'],
                    call_user_func($fixture['store'], $fixture['payload'], 5 * MINUTE_IN_SECONDS, $fixture['token'])
                );
            }
            remove_filter('query', $capture);

            $option_names = [];
            foreach ($payloads as $fixture) {
                $option_names[] = '_transient_' . $fixture['cache_key'];
                $option_names[] = '_transient_timeout_' . $fixture['cache_key'];
            }
            $mutations = array_values(array_filter($captured_queries, static function (string $query) use ($option_names): bool {
                if (!preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $query)) {
                    return false;
                }
                foreach ($option_names as $option_name) {
                    if (strpos($query, $option_name) !== false) {
                        return true;
                    }
                }
                return false;
            }));
            $this->assertSame([], $mutations, 'A warm deterministic private token must not rewrite identical transient rows.');
        } finally {
            remove_filter('query', $capture);
            foreach ($payloads as $fixture) {
                ll_tools_wordset_page_delete_durable_cached_payload($fixture['cache_key']);
            }
        }
    }

    public function test_authenticated_payload_refreshes_when_durable_expiry_enters_refresh_window(): void
    {
        $token = 'private_' . md5(wp_generate_password(20, false, false));
        $cache_key = ll_tools_wordset_page_lazy_cards_cache_key($token);
        $timeout_option = '_transient_timeout_' . $cache_key;
        $payload = [
            'cards' => [['id' => 29]],
            'render_context' => [],
            'batch_size' => 18,
            'base_offset' => 18,
            'total' => 19,
            'user_id' => self::factory()->user->create(['role' => 'subscriber']),
        ];

        try {
            $this->assertSame($token, ll_tools_wordset_page_store_lazy_cards_payload($payload, 5 * MINUTE_IN_SECONDS, $token));
            update_option($timeout_option, time() + 30, false);

            $this->assertSame($token, ll_tools_wordset_page_store_lazy_cards_payload($payload, 5 * MINUTE_IN_SECONDS, $token));
            $this->assertGreaterThanOrEqual(time() + (5 * MINUTE_IN_SECONDS) - 5, (int) get_option($timeout_option, 0));
        } finally {
            ll_tools_wordset_page_delete_durable_cached_payload($cache_key);
        }
    }

    public function test_authenticated_payload_is_not_reused_when_external_cache_hides_remaining_ttl(): void
    {
        $payload = [
            'cards' => [['id' => 41]],
            'user_id' => self::factory()->user->create(['role' => 'subscriber']),
        ];
        $previous = (bool) wp_using_ext_object_cache();

        try {
            wp_using_ext_object_cache(true);
            $this->assertFalse(ll_tools_wordset_page_authenticated_payload_cache_is_reusable(
                $this->uniqueCacheKey('external-cache-ttl'),
                $payload,
                $payload,
                5 * MINUTE_IN_SECONDS
            ));
        } finally {
            wp_using_ext_object_cache($previous);
        }
    }

    public function test_category_preview_completeness_covers_resolvers_and_bounded_hydration_before_cache_store(): void
    {
        $reflection = new ReflectionFunction('ll_tools_get_wordset_category_preview');
        $parameters = $reflection->getParameters();
        $complete_parameter = end($parameters);

        $this->assertInstanceOf(ReflectionParameter::class, $complete_parameter);
        $this->assertSame('complete', $complete_parameter->getName());
        $this->assertTrue($complete_parameter->isPassedByReference());

        $source = $this->getFunctionSource('ll_tools_get_wordset_category_preview');

        $this->assertMatchesRegularExpression(
            '/\$wpdb->last_error\s*=\s*\'\';\s*\$effective_category_id\s*=.*?ll_tools_get_effective_category_id_for_wordset\(\$category_id, \$wordset_id, false\);\s*if \(\$wpdb->last_error !== \'\'\) \{\s*\$sources_complete = false;/s',
            $source
        );
        $this->assertStringContainsString('$quiz_config_complete = true;', $source);
        $this->assertStringContainsString(
            'll_tools_get_category_quiz_config($category_id, $quiz_config_complete)',
            $source
        );
        $this->assertStringContainsString('!$quiz_config_complete', $source);
        $this->assertStringContainsString('$images_complete = true;', $source);
        $this->assertStringContainsString(
            'll_tools_vocab_lesson_category_requires_images($category_id, $wordset_id, $images_complete)',
            $source
        );
        $this->assertStringContainsString(
            '$sources_complete = $sources_complete && $images_complete;',
            $source
        );

        $this->assertStringContainsString('$image_query_limit = min(50, max($query_limit, $limit * 8));', $source);
        preg_match_all('/\'cache_results\'\s*=>\s*false/', $source, $cache_result_matches);
        $this->assertGreaterThanOrEqual(
            2,
            count($cache_result_matches[0]),
            'Both bounded candidate queries must bypass WP_Query result caching so a failed read can be retried.'
        );
        $this->assertStringContainsString(
            '$prime_preview_posts = static function (array $post_ids, bool $prime_terms = true) use (&$sources_complete, $wpdb): void',
            $source
        );
        $this->assertStringContainsString('$sources_complete = $sources_complete && $wpdb->last_error === \'\';', $source);

        $this->assertSourceFragmentsInOrder($source, [
            '$payload = [',
            'if (!$sources_complete) {',
            '$complete = false;',
            'return $payload;',
            'return ll_tools_wordset_page_store_cached_payload($preview_cache_key, $payload, $cache_ttl, $request_cache);',
        ], 'An incomplete category preview must return retryable data before the only durable cache store.');
    }

    public function test_owned_category_term_and_count_queries_treat_wpdb_errors_as_incomplete(): void
    {
        $terms_source = $this->getFunctionSource('ll_tools_wordset_page_get_owned_category_terms');
        $count_source = $this->getFunctionSource('ll_tools_wordset_page_count_owned_category_terms');

        $this->assertSourceFragmentsInOrder($terms_source, [
            '$wpdb->last_error = \'\';',
            '$terms = get_terms($query_args);',
            'if (is_wp_error($terms) || $wpdb->last_error !== \'\') {',
            '$complete = false;',
            'return [];',
        ], 'Owned-term discovery must fail open when WordPress returns a partial term result after a database error.');
        $this->assertStringContainsString(
            '$owner_id = (int) ll_tools_get_category_wordset_owner_id($term);',
            $terms_source
        );
        $this->assertMatchesRegularExpression(
            '/\$owner_id\s*=.*?ll_tools_get_category_wordset_owner_id\(\$term\);\s*if \(\$wpdb->last_error !== \'\'\) \{\s*\$complete = false;\s*continue;/s',
            $terms_source
        );

        $this->assertSourceFragmentsInOrder($count_source, [
            '$wpdb->last_error = \'\';',
            '$count = get_terms($query_args);',
            'if (is_wp_error($count) || $wpdb->last_error !== \'\') {',
            '$complete = false;',
            'return 0;',
        ], 'Owned-term counts must not turn a database failure into a durable zero.');
    }

    public function test_inactive_public_note_completeness_reaches_rows_and_outer_category_cache_boundary(): void
    {
        $note_reflection = new ReflectionFunction('ll_tools_wordset_page_get_inactive_category_public_note');
        $note_parameters = $note_reflection->getParameters();
        $note_complete_parameter = end($note_parameters);
        $this->assertInstanceOf(ReflectionParameter::class, $note_complete_parameter);
        $this->assertSame('complete', $note_complete_parameter->getName());
        $this->assertTrue($note_complete_parameter->isPassedByReference());

        $note_source = $this->getFunctionSource('ll_tools_wordset_page_get_inactive_category_public_note');
        $this->assertStringContainsString('ll_tools_get_category_quiz_config($category, $config_complete)', $note_source);
        $this->assertStringContainsString('!$config_complete', $note_source);
        $this->assertStringContainsString(
            'll_tools_vocab_lesson_category_requires_images($category, $wordset_id, $images_complete)',
            $note_source
        );
        $this->assertStringContainsString('if ($wpdb->last_error !== \'\') {', $note_source);
        $this->assertStringContainsString('$complete = false;', $note_source);

        $rows_source = $this->getFunctionSource('ll_tools_get_wordset_page_category_rows');
        $this->assertSourceFragmentsInOrder($rows_source, [
            '$public_note_complete = true;',
            'll_tools_wordset_page_get_inactive_category_public_note(',
            '$public_note_complete',
            '$sources_complete = $sources_complete && $public_note_complete;',
            'if (!$sources_complete) {',
            '$complete = false;',
            'return $rows;',
            'return ll_tools_wordset_page_store_cached_payload($cache_key, $rows, $cache_ttl, $request_cache);',
        ], 'An incomplete inactive note must make the entire category-row payload non-durable.');

        $categories_source = $this->getFunctionSource('ll_tools_get_wordset_page_categories');
        $this->assertSourceFragmentsInOrder($categories_source, [
            '$rows_complete = true;',
            'll_tools_get_wordset_page_category_rows($wordset_id, $preview_limit, $include_inactive, $rows_complete);',
            '$sources_complete = $sources_complete && $rows_complete;',
            "if (\$category_cache_key !== '' && \$sources_complete) {",
            'return ll_tools_wordset_page_store_cached_payload($category_cache_key, $items, $category_cache_ttl, $category_request_cache);',
        ], 'Incomplete category rows must reach and close the outer durable category-cache gate.');
    }

    public function test_uncategorized_preview_primes_four_ids_and_blocks_incomplete_outer_cache(): void
    {
        $source = $this->getFunctionSource('ll_tools_wordset_page_build_uncategorized_virtual_category');

        $this->assertStringContainsString('$preview_limit = max(1, max(4, (int) $preview_limit));', $source);
        $this->assertSourceFragmentsInOrder($source, [
            '$ids_complete = true;',
            'll_tools_wordset_page_get_uncategorized_word_ids($wordset_id, $preview_limit, $ids_complete);',
            '$complete = $complete && $ids_complete;',
            'if (!empty($word_ids)) {',
            '$wpdb->last_error = \'\';',
            '_prime_post_caches($word_ids, true, true);',
            'if ($wpdb->last_error !== \'\') {',
            '$complete = false;',
            'foreach ($word_ids as $word_id) {',
        ], 'The four-word uncategorized preview must prime its exact hydration set and expose incomplete reads.');
        $this->assertStringNotContainsString('ll_tools_wordset_page_store_cached_payload(', $source);

        $categories_source = $this->getFunctionSource('ll_tools_get_wordset_page_categories');
        $this->assertSourceFragmentsInOrder($categories_source, [
            '$uncategorized_complete = true;',
            'll_tools_wordset_page_build_uncategorized_virtual_category($wordset_id, $preview_limit, $uncategorized_complete);',
            '$sources_complete = $sources_complete && $uncategorized_complete;',
            "if (\$category_cache_key !== '' && \$sources_complete) {",
            'return ll_tools_wordset_page_store_cached_payload($category_cache_key, $items, $category_cache_ttl, $category_request_cache);',
        ], 'An incomplete uncategorized hydration must close the outer durable category-cache gate.');
    }

    private function uniqueCacheKey(string $suffix): string
    {
        return 'll_wsp_test_' . sanitize_key($suffix) . '_' . md5(wp_generate_password(20, false, false));
    }

    private function getFunctionSource(string $function_name): string
    {
        $reflection = new ReflectionFunction($function_name);
        $file_name = $reflection->getFileName();
        $this->assertIsString($file_name);
        $lines = file($file_name);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * @param string[] $fragments
     */
    private function assertSourceFragmentsInOrder(string $source, array $fragments, string $message): void
    {
        $offset = 0;
        foreach ($fragments as $fragment) {
            $position = strpos($source, $fragment, $offset);
            if ($position === false) {
                $this->fail($message . ' Missing or out-of-order fragment: ' . $fragment);
            }
            $offset = $position + strlen($fragment);
        }
    }
}
