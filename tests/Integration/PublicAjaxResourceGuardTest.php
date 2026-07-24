<?php
declare(strict_types=1);

final class PublicAjaxResourceGuardTest extends LL_Tools_TestCase
{
    public function test_atomic_counter_reserves_weighted_capacity_without_overshooting_limit(): void
    {
        global $wpdb;

        $prefix = 'll_tools_test_ajax_guard_';
        $identifier = '203.0.113.80';
        $now = 2000000000;
        $updates = [];
        $capture = static function (string $query) use (&$updates): string {
            if (
                strpos($query, 'CAST(option_value AS UNSIGNED)') !== false
                && strpos($query, 'UPDATE') !== false
            ) {
                $updates[] = $query;
            }
            return $query;
        };

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        add_filter('query', $capture);
        try {
            $first = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, 60, 2, $now);
            $second = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, 60, 2, $now);
        } finally {
            remove_filter('query', $capture);
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }

        $this->assertTrue($first['allowed']);
        $this->assertSame(2, $first['count']);
        $this->assertFalse($second['allowed']);
        $this->assertSame(2, $second['count']);
        $this->assertSame(3, $second['limit']);
        $this->assertNotEmpty($updates);
        $this->assertStringContainsString($wpdb->options, $updates[0]);
        $this->assertStringContainsString('<= 3', $updates[0]);
    }

    public function test_client_leases_bound_distinct_request_keys_and_release_exact_slots(): void
    {
        $prefix = 'll_tools_test_ajax_inflight_';
        $identifier = '203.0.113.81';
        $now = 2000000000;

        ll_tools_public_ajax_reset_client_leases($prefix, $identifier);
        try {
            $first = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 2, 30, $now);
            $second = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 2, 30, $now);
            $blocked = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 2, 30, $now);

            $this->assertTrue($first['acquired']);
            $this->assertTrue($second['acquired']);
            $this->assertFalse($blocked['acquired']);
            $this->assertSame(30, $blocked['retry_after']);

            ll_tools_public_ajax_release_client_lease($first);
            $replacement = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 2, 30, $now);
            $this->assertTrue($replacement['acquired']);

            ll_tools_public_ajax_release_client_lease($second);
            ll_tools_public_ajax_release_client_lease($replacement);
        } finally {
            ll_tools_public_ajax_reset_client_leases($prefix, $identifier);
        }
    }

    public function test_flashcard_candidate_cost_preserves_large_sessions_but_charges_large_misses(): void
    {
        $this->assertSame(1, ll_tools_flashcards_public_ajax_candidate_request_cost(0));
        $this->assertSame(1, ll_tools_flashcards_public_ajax_candidate_request_cost(20));
        $this->assertSame(3, ll_tools_flashcards_public_ajax_candidate_request_cost(250));
        $this->assertSame(10, ll_tools_flashcards_public_ajax_candidate_request_cost(1000));

        $bounded_ids = ll_tools_flashcards_public_ajax_candidate_word_ids(
            implode(',', range(1, 1500)),
            1000
        );
        $this->assertCount(1000, $bounded_ids);
        $this->assertSame(1, $bounded_ids[0]);
        $this->assertSame(1000, $bounded_ids[999]);
    }

    public function test_flashcard_client_inflight_guard_is_independent_of_cache_key(): void
    {
        wp_set_current_user(0);
        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.82';
        $limit_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_flashcards_public_ajax_client_inflight_limit', $limit_filter);
        ll_tools_flashcards_public_ajax_reset_client_inflight();

        try {
            $first = ll_tools_flashcards_public_ajax_acquire_client_inflight();
            $blocked = ll_tools_flashcards_public_ajax_acquire_client_inflight();

            $this->assertTrue($first['acquired']);
            $this->assertFalse($blocked['acquired']);

            ll_tools_public_ajax_release_client_lease($first);
            $replacement = ll_tools_flashcards_public_ajax_acquire_client_inflight();
            $this->assertTrue($replacement['acquired']);
            ll_tools_public_ajax_release_client_lease($replacement);
        } finally {
            ll_tools_flashcards_public_ajax_reset_client_inflight();
            remove_filter('ll_tools_flashcards_public_ajax_client_inflight_limit', $limit_filter);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
        }
    }

    public function test_dictionary_rate_limit_is_atomic_and_client_inflight_is_key_independent(): void
    {
        wp_set_current_user(0);
        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.83';
        $limit_filter = static function (): int {
            return 1;
        };
        $window_filter = static function (): int {
            return 60;
        };
        add_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $limit_filter);
        add_filter('ll_tools_dictionary_live_search_rate_limit_window', $window_filter);
        add_filter('ll_tools_dictionary_live_search_client_inflight_limit', $limit_filter);
        ll_tools_dictionary_live_search_reset_rate_limit();
        ll_tools_dictionary_live_search_reset_client_inflight();

        try {
            $first_status = ll_tools_dictionary_live_search_rate_limit_status();
            $second_status = ll_tools_dictionary_live_search_rate_limit_status();
            $first_lease = ll_tools_dictionary_live_search_acquire_client_inflight();
            $blocked_lease = ll_tools_dictionary_live_search_acquire_client_inflight();

            $this->assertTrue($first_status['allowed']);
            $this->assertFalse($second_status['allowed']);
            $this->assertTrue($first_lease['acquired']);
            $this->assertFalse($blocked_lease['acquired']);

            ll_tools_public_ajax_release_client_lease($first_lease);
        } finally {
            ll_tools_dictionary_live_search_reset_rate_limit();
            ll_tools_dictionary_live_search_reset_client_inflight();
            remove_filter('ll_tools_dictionary_live_search_client_inflight_limit', $limit_filter);
            remove_filter('ll_tools_dictionary_live_search_rate_limit_window', $window_filter);
            remove_filter('ll_tools_dictionary_live_search_rate_limit_max_requests', $limit_filter);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
        }
    }

    public function test_dictionary_build_lock_release_cannot_delete_a_successor_lease(): void
    {
        wp_set_current_user(0);
        $key = ll_tools_dictionary_ajax_cache_build_lock_key('live_search', [
            'search' => 'owner-safe-lock',
            'page' => 1,
        ]);
        $option_name = ll_tools_dictionary_ajax_cache_build_lock_option($key);
        delete_option($option_name);
        unset($GLOBALS['ll_tools_dictionary_ajax_cache_build_leases'][$key]);

        try {
            $this->assertTrue(ll_tools_dictionary_ajax_cache_acquire_build_lock($key));
            $successor_value = (time() + 30) . '|successor-owner';
            update_option($option_name, $successor_value, false);

            ll_tools_dictionary_ajax_cache_release_build_lock($key);

            $this->assertSame($successor_value, get_option($option_name));
        } finally {
            delete_option($option_name);
            unset($GLOBALS['ll_tools_dictionary_ajax_cache_build_leases'][$key]);
        }
    }
}
