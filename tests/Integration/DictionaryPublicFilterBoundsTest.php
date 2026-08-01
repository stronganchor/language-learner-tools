<?php
declare(strict_types=1);

final class DictionaryPublicFilterBoundsTest extends LL_Tools_TestCase
{
    public function test_public_filter_limits_accept_exact_boundaries_and_reject_the_next_value(): void
    {
        $smallLimits = static function (array $limits): array {
            $limits['ll_dictionary_scope'] = [
                'max_raw_bytes' => 10,
                'max_values' => 2,
                'max_value_bytes' => 8,
            ];
            $limits['ll_dictionary_pos'] = [
                'max_raw_bytes' => 16,
                'max_values' => 2,
                'max_value_bytes' => 8,
            ];
            $limits['ll_dictionary_source'] = [
                'max_raw_bytes' => 10,
                'max_values' => 2,
                'max_value_bytes' => 5,
            ];
            return $limits;
        };
        add_filter('ll_tools_dictionary_public_filter_limits', $smallLimits);

        try {
            $scopeBoundary = [
                'll_dictionary_scope' => ['headword', 'tr'],
            ];
            $sourceBoundary = [
                'll_dictionary_source' => 'alpha_beta',
            ];
            $posBoundary = [
                'll_dictionary_pos' => 'noun_verb',
            ];

            $this->assertNull(ll_tools_dictionary_public_filter_request_error($scopeBoundary));
            $this->assertNull(ll_tools_dictionary_public_filter_request_error($sourceBoundary));
            $this->assertNull(ll_tools_dictionary_public_filter_request_error($posBoundary));
            $this->assertSame(
                ['headword', 'tr'],
                ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($scopeBoundary)
            );
            $this->assertSame(
                'noun_verb',
                ll_tools_dictionary_shortcode_build_pos_query_value(
                    ll_tools_dictionary_shortcode_resolve_pos_slugs_from_request($posBoundary)
                )
            );

            $tooManyScopes = [
                'll_dictionary_scope' => ['headword', 'tr', 'en'],
            ];
            $tooManyCompactPosValues = [
                'll_dictionary_pos' => 'noun_verb_adjective',
            ];
            $tooManySourceBytes = [
                'll_dictionary_source' => 'alpha_betas',
            ];

            $this->assertWPError(ll_tools_dictionary_public_filter_request_error($tooManyScopes));
            $this->assertWPError(ll_tools_dictionary_public_filter_request_error($tooManyCompactPosValues));
            $this->assertWPError(ll_tools_dictionary_public_filter_request_error($tooManySourceBytes));
            $this->assertSame(
                ll_tools_dictionary_shortcode_get_available_search_scopes(),
                ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($tooManyScopes)
            );
            $this->assertSame([], ll_tools_dictionary_shortcode_resolve_pos_slugs_from_request($tooManyCompactPosValues));
            $this->assertSame([], ll_tools_dictionary_shortcode_resolve_source_ids_from_request($tooManySourceBytes));
        } finally {
            remove_filter('ll_tools_dictionary_public_filter_limits', $smallLimits);
        }

        $unboundedFilter = static function (array $limits): array {
            foreach ($limits as $key => $limit) {
                $limits[$key] = [
                    'max_raw_bytes' => PHP_INT_MAX,
                    'max_values' => PHP_INT_MAX,
                    'max_value_bytes' => PHP_INT_MAX,
                ];
            }
            return $limits;
        };
        add_filter('ll_tools_dictionary_public_filter_limits', $unboundedFilter);
        try {
            foreach (ll_tools_dictionary_public_filter_limits() as $limit) {
                $this->assertSame(
                    LL_TOOLS_DICTIONARY_PUBLIC_FILTER_MAX_RAW_BYTES_HARD,
                    (int) ($limit['max_raw_bytes'] ?? 0)
                );
                $this->assertSame(
                    LL_TOOLS_DICTIONARY_PUBLIC_FILTER_MAX_VALUES_HARD,
                    (int) ($limit['max_values'] ?? 0)
                );
                $this->assertSame(
                    LL_TOOLS_DICTIONARY_PUBLIC_FILTER_MAX_VALUE_BYTES_HARD,
                    (int) ($limit['max_value_bytes'] ?? 0)
                );
            }
        } finally {
            remove_filter('ll_tools_dictionary_public_filter_limits', $unboundedFilter);
        }
    }

    public function test_oversized_live_search_filter_is_rejected_before_rate_limit_cache_or_sql(): void
    {
        wp_set_current_user(0);
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.91';
        $oneRequest = static fn (): int => 1;
        add_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $oneRequest);
        ll_tools_dictionary_live_search_reset_rate_limit();

        $sourceLimit = ll_tools_dictionary_public_filter_limits()['ll_dictionary_source']['max_values'];
        $oversizedSources = [];
        for ($index = 0; $index <= $sourceLimit; $index++) {
            $oversizedSources[] = 'oversized-source-' . $index;
        }
        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/dictionary/',
            'wordset_id' => 0,
            'll_dictionary_q' => 'bounded-filter-probe',
            'll_dictionary_source' => $oversizedSources,
        ];
        $_REQUEST = $_POST;
        $queries = [];
        $queryWatcher = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $queryWatcher);

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
            $firstBudgetReservation = ll_tools_dictionary_live_search_rate_limit_status();
        } finally {
            remove_filter('query', $queryWatcher);
            ll_tools_dictionary_live_search_reset_rate_limit();
            remove_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $oneRequest);
            $_POST = [];
            $_REQUEST = [];
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame(
            'll_tools_dictionary_filter_input_too_large',
            (string) ($response['data']['code'] ?? '')
        );
        $this->assertSame('ll_dictionary_source', (string) ($response['data']['parameter'] ?? ''));
        $this->assertTrue((bool) ($firstBudgetReservation['allowed'] ?? false));
        $querySql = implode("\n", $queries);
        $this->assertStringNotContainsString('oversized-source-', $querySql);
        $this->assertStringNotContainsString('ll_dictionary_lookup', $querySql);
        $this->assertStringNotContainsString("post_type = 'll_dictionary_entry'", $querySql);
    }

    public function test_normal_bounded_filters_still_return_anonymous_cache_hits_before_rate_limiting(): void
    {
        wp_set_current_user(0);
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.92';
        $search = 'bounded-cache-' . strtolower(wp_generate_password(8, false, false));
        $_POST = [
            'action' => 'll_tools_dictionary_live_search',
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'base_url' => 'https://example.com/dictionary/',
            'wordset_id' => 0,
            'll_dictionary_q' => $search,
            'll_dictionary_scope' => ['headword', 'tr'],
            'll_dictionary_page' => '1',
        ];
        $_REQUEST = $_POST;
        $searchScopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_POST);
        $cacheArgs = [
            'wordset_id' => 0,
            'per_page' => 20,
            'sense_limit' => 3,
            'linked_word_limit' => 4,
            'gloss_lang' => '',
            'base_url' => ll_tools_dictionary_resolve_live_base_url('https://example.com/dictionary/'),
            'search' => $search,
            'search_scopes' => $searchScopes,
            'letter' => '',
            'page' => 1,
            'pos_slug' => '',
            'source_ids' => [],
            'dialect' => '',
            'preferred_languages' => ll_tools_dictionary_shortcode_resolve_display_languages($searchScopes, 0, ''),
            'title_language' => ll_tools_dictionary_get_effective_title_language_code(0),
            'browse_letter_schema' => 7,
            'has_active_query' => true,
            'query_limits' => [
                'result_depth_limit' => ll_tools_dictionary_anonymous_live_search_result_depth_cap(),
                'candidate_scan_limit' => ll_tools_dictionary_anonymous_live_search_candidate_scan_cap(),
            ],
        ];
        $payload = [
            'html' => '<article>Bounded cache hit</article>',
            'has_active_query' => true,
            'is_limited' => false,
            'url' => 'https://example.com/dictionary/?ll_dictionary_q=' . rawurlencode($search),
        ];
        ll_tools_dictionary_ajax_cache_set('live_search', $cacheArgs, $payload);
        $this->assertNull(ll_tools_dictionary_public_filter_request_error($_POST));

        $oneRequest = static fn (): int => 1;
        add_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $oneRequest);
        ll_tools_dictionary_live_search_reset_rate_limit();
        $this->assertTrue((bool) (ll_tools_dictionary_live_search_rate_limit_status()['allowed'] ?? false));

        $persistentKey = ll_tools_dictionary_browser_build_cache_key(
            'ajax_live_search',
            ll_tools_dictionary_ajax_cache_args($cacheArgs)
        );
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_dictionary_handle_live_search();
            });
        } finally {
            ll_tools_dictionary_live_search_reset_rate_limit();
            remove_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $oneRequest);
            delete_transient($persistentKey);
            wp_cache_delete($persistentKey, LL_TOOLS_DICTIONARY_BROWSER_CACHE_GROUP);
            $_POST = [];
            $_REQUEST = [];
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame($payload, $response['data'] ?? null);
    }

    public function test_static_cache_defaults_oversized_filters_before_key_normalization(): void
    {
        $sourceLimit = ll_tools_dictionary_public_filter_limits()['ll_dictionary_source']['max_values'];
        $rawArgs = [
            'll_dictionary_q' => 'normal-search',
            'll_dictionary_scope' => ['headword'],
            'll_dictionary_pos' => ['noun'],
            'll_dictionary_source' => array_fill(0, $sourceLimit + 1, 'oversized-source'),
        ];

        $normalized = ll_tools_dictionary_static_cache_normalize_query_args($rawArgs);

        $this->assertSame('normal-search', (string) ($normalized['ll_dictionary_q'] ?? ''));
        $this->assertSame('headword', (string) ($normalized['ll_dictionary_scope'] ?? ''));
        $this->assertSame('noun', (string) ($normalized['ll_dictionary_pos'] ?? ''));
        $this->assertArrayNotHasKey('ll_dictionary_source', $normalized);
    }

    public function test_scalar_query_arguments_reject_arrays_and_oversized_values_before_normalization(): void
    {
        $limits = ll_tools_dictionary_public_filter_limits();
        $scalarKeys = [
            'll_dictionary_q',
            'll_dictionary_page',
            'll_dictionary_letter',
            'letter',
            'll_dictionary_dialect',
            'll_dictionary_entry',
        ];

        foreach ($scalarKeys as $key) {
            $shapeError = ll_tools_dictionary_public_filter_input_error($key, [['nested-value']]);
            $this->assertWPError($shapeError, $key . ' should reject nested arrays.');
            $this->assertSame('shape', (string) (($shapeError->get_error_data()['reason'] ?? '')));

            $oversized = str_repeat('x', ((int) $limits[$key]['max_raw_bytes']) + 1);
            $sizeError = ll_tools_dictionary_public_filter_input_error($key, $oversized);
            $this->assertWPError($sizeError, $key . ' should reject oversized scalar input.');
            $this->assertSame('raw_bytes', (string) (($sizeError->get_error_data()['reason'] ?? '')));
        }
    }

    public function test_static_cache_drops_invalid_scalar_shapes_before_recursive_value_helpers(): void
    {
        $queryLimit = ll_tools_dictionary_public_filter_limits()['ll_dictionary_q']['max_raw_bytes'];
        $rawArgs = [
            'll_dictionary_q' => [['nested-search']],
            'll_dictionary_page' => ['2'],
            'll_dictionary_letter' => [['a']],
            'letter' => ['b'],
            'll_dictionary_dialect' => [['nested-dialect']],
            'll_dictionary_entry' => ['123'],
        ];

        $normalized = ll_tools_dictionary_static_cache_normalize_query_args($rawArgs);
        $this->assertSame([], $normalized);

        $oversized = ll_tools_dictionary_static_cache_normalize_query_args([
            'll_dictionary_q' => str_repeat('q', $queryLimit + 1),
            'll_dictionary_dialect' => str_repeat(
                'd',
                ll_tools_dictionary_public_filter_limits()['ll_dictionary_dialect']['max_raw_bytes'] + 1
            ),
        ]);
        $this->assertSame([], $oversized);
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static fn (): bool => true;

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
