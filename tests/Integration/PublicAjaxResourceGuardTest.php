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

    public function test_atomic_counter_status_and_refund_preserve_other_reservations(): void
    {
        $prefix = 'll_tools_test_ajax_refund_';
        $identifier = '203.0.113.84';
        $now = 2000000000;

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        try {
            $failure = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, 60, 1, $now);
            $success = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, 60, 1, $now);

            $this->assertTrue(ll_tools_public_ajax_refund_counter($success));
            $status = ll_tools_public_ajax_counter_status($prefix, $identifier, 3, 60, 1, $now);

            $this->assertTrue($failure['reserved']);
            $this->assertSame(1, $status['count']);
            $this->assertTrue($status['allowed']);
        } finally {
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }
    }

    public function test_atomic_counter_retries_when_refund_cleanup_deletes_the_observed_bucket(): void
    {
        global $wpdb;

        $prefix = 'll_tools_test_ajax_cleanup_race_';
        $identifier = '203.0.113.86';
        $now = 2000000000;
        $names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, 60, $now);
        $injected = false;
        $injecting = false;
        $delete_before_update = static function (string $query) use (
            $wpdb,
            $names,
            &$injected,
            &$injecting
        ): string {
            if (
                !$injecting
                && !$injected
                && stripos($query, "UPDATE {$wpdb->options}") !== false
                && strpos($query, 'CAST(option_value AS UNSIGNED)') !== false
                && strpos($query, $names['value']) !== false
            ) {
                $injecting = true;
                $injected = true;
                try {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                        $names['value'],
                        $names['timeout']
                    ));
                    wp_cache_delete($names['value'], 'options');
                    wp_cache_delete($names['timeout'], 'options');
                } finally {
                    $injecting = false;
                }
            }

            return $query;
        };

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        add_option($names['value'], '0', '', false);
        add_option($names['timeout'], (string) $names['expires_at'], '', false);
        add_filter('query', $delete_before_update);
        try {
            $reservation = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 1, 60, 1, $now);
            $blocked = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 1, 60, 1, $now);

            $this->assertTrue($injected, 'Expected cleanup to delete the counter before its conditional increment.');
            $this->assertTrue($reservation['allowed']);
            $this->assertTrue($reservation['reserved']);
            $this->assertSame(1, $reservation['count']);
            $this->assertSame('1', get_option($names['value']));
            $this->assertSame((string) $names['expires_at'], get_option($names['timeout']));
            $this->assertFalse($blocked['allowed']);
            $this->assertSame(1, $blocked['count']);
        } finally {
            remove_filter('query', $delete_before_update);
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }
    }

    public function test_atomic_counter_does_not_overwrite_a_concurrent_weighted_first_reservation(): void
    {
        global $wpdb;

        $prefix = 'll_tools_test_ajax_weighted_create_';
        $identifier = '203.0.113.88';
        $now = 2000000000;
        $names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, 60, $now);
        $injected = false;
        $injecting = false;
        $create_competing_reservation = static function (string $query) use (
            $wpdb,
            $names,
            &$injected,
            &$injecting
        ): string {
            if (
                !$injecting
                && !$injected
                && stripos($query, "INSERT IGNORE INTO {$wpdb->options}") !== false
                && strpos($query, $names['value']) !== false
            ) {
                $injecting = true;
                $injected = true;
                try {
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                        $names['value'],
                        '2',
                        'no'
                    ));
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                        $names['timeout'],
                        (string) $names['expires_at'],
                        'no'
                    ));
                    wp_cache_delete($names['value'], 'options');
                    wp_cache_delete($names['timeout'], 'options');
                } finally {
                    $injecting = false;
                }
            }

            return $query;
        };

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        add_filter('query', $create_competing_reservation);
        try {
            $reservation = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 5, 60, 3, $now);
            $blocked = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 5, 60, 1, $now);

            $this->assertTrue($injected, 'Expected a competing weighted reservation before the first insert.');
            $this->assertTrue($reservation['allowed']);
            $this->assertTrue($reservation['reserved']);
            $this->assertSame(5, $reservation['count']);
            $this->assertSame('5', get_option($names['value']));
            $this->assertFalse($blocked['allowed']);
            $this->assertSame(5, $blocked['count']);
        } finally {
            remove_filter('query', $create_competing_reservation);
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }
    }

    public function test_atomic_counter_does_not_retry_a_missing_bucket_after_an_update_error(): void
    {
        global $wpdb;

        $prefix = 'll_tools_test_ajax_cleanup_error_';
        $identifier = '203.0.113.87';
        $now = 2000000000;
        $names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, 60, $now);
        $injected = false;
        $injecting = false;
        $fail_update_after_delete = static function (string $query) use (
            $wpdb,
            $names,
            &$injected,
            &$injecting
        ): string {
            if (
                !$injecting
                && !$injected
                && stripos($query, "UPDATE {$wpdb->options}") !== false
                && strpos($query, 'CAST(option_value AS UNSIGNED)') !== false
                && strpos($query, $names['value']) !== false
            ) {
                $injecting = true;
                $injected = true;
                try {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                        $names['value'],
                        $names['timeout']
                    ));
                    wp_cache_delete($names['value'], 'options');
                    wp_cache_delete($names['timeout'], 'options');
                } finally {
                    $injecting = false;
                }

                return "UPDATE {$wpdb->options} SET ll_tools_missing_counter_column = 1";
            }

            return $query;
        };

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        add_option($names['value'], '0', '', false);
        add_option($names['timeout'], (string) $names['expires_at'], '', false);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $fail_update_after_delete);
        try {
            $reservation = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 1, 60, 1, $now);

            $this->assertTrue($injected, 'Expected the conditional increment query failure to be injected.');
            $this->assertFalse($reservation['allowed']);
            $this->assertFalse($reservation['reserved']);
            $this->assertFalse(get_option($names['value'], false));
        } finally {
            remove_filter('query', $fail_update_after_delete);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }
    }

    public function test_delayed_previous_bucket_cannot_delete_the_active_next_bucket(): void
    {
        $prefix = 'll_tools_test_ajax_boundary_';
        $identifier = '203.0.113.85';
        $window = 60;
        $previous_now = 6000;
        $next_now = $previous_now + $window;

        ll_tools_public_ajax_reset_counter($prefix, $identifier);
        try {
            $next = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, $window, 1, $next_now);
            $previous = ll_tools_public_ajax_reserve_counter($prefix, $identifier, 3, $window, 1, $previous_now);
            $next_names = ll_tools_public_ajax_counter_option_names($prefix, $identifier, $window, $next_now);

            $this->assertTrue($next['allowed']);
            $this->assertTrue($previous['allowed']);
            $this->assertSame('1', get_option($next_names['value']));
            $this->assertSame(1, ll_tools_public_ajax_counter_status(
                $prefix,
                $identifier,
                3,
                $window,
                1,
                $next_now
            )['count']);
        } finally {
            ll_tools_public_ajax_reset_counter($prefix, $identifier);
        }
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
            $this->assertFalse(get_option((string) $first['option_name'], false));
            $this->assertFalse(get_option((string) $first['timeout_option_name'], false));
            $replacement = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 2, 30, $now);
            $this->assertTrue($replacement['acquired']);
            $this->assertSame(
                (string) $replacement['lease_value'],
                (string) get_option((string) $replacement['option_name'])
            );

            ll_tools_public_ajax_release_client_lease($second);
            ll_tools_public_ajax_release_client_lease($replacement);
        } finally {
            ll_tools_public_ajax_reset_client_leases($prefix, $identifier);
        }
    }

    public function test_client_lease_release_cannot_delete_a_successor_owner(): void
    {
        $prefix = 'll_tools_test_ajax_owner_';
        $identifier = 'shared-cache-key';
        $lease = ll_tools_public_ajax_acquire_client_lease($prefix, $identifier, 1, 30);
        $this->assertTrue($lease['acquired']);

        $option_name = (string) ($lease['option_name'] ?? '');
        $timeout_option_name = (string) ($lease['timeout_option_name'] ?? '');
        $successor_value = (time() + 30) . '|successor-owner';
        $successor_timeout = (string) (time() + 30);
        update_option($option_name, $successor_value, false);
        update_option($timeout_option_name, $successor_timeout, false);

        try {
            ll_tools_public_ajax_release_client_lease($lease);
            $this->assertSame($successor_value, get_option($option_name));
            $this->assertSame($successor_timeout, get_option($timeout_option_name));
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
