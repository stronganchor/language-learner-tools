<?php
declare(strict_types=1);

final class OfflineAppSyncTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_offline_app_token_login_sync_dedupes_and_logout_revokes_token(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
            $username = 'offline_sync_' . strtolower(wp_generate_password(6, false, false));
            $email = $username . '@example.com';
            $password = 'Pass!' . wp_generate_password(12, false, false);
            $user_id = wp_create_user($username, $password, $email);
            $this->assertIsInt($user_id);

            $fixture = $this->createOfflineSyncFixture();
            $client_seen_at = '2026-02-03T04:05:06Z';
            $word_event_uuid = wp_generate_uuid4();
            $category_event_uuid = wp_generate_uuid4();
            $events = [
                [
                    'event_uuid' => $word_event_uuid,
                    'event_type' => 'word_exposure',
                    'mode' => 'practice',
                    'word_id' => $fixture['word_id'],
                    'category_id' => $fixture['category_id'],
                    'wordset_id' => $fixture['wordset_id'],
                    'client_created_at' => $client_seen_at,
                    'payload' => [],
                ],
                [
                    'event_uuid' => $category_event_uuid,
                    'event_type' => 'category_study',
                    'mode' => 'practice',
                    'category_id' => $fixture['category_id'],
                    'wordset_id' => $fixture['wordset_id'],
                    'payload' => [
                        'units' => 2,
                    ],
                ],
            ];
            $state_payload = [
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => [$fixture['category_id']],
                'starred_word_ids' => [$fixture['word_id']],
                'star_mode' => 'only',
                'fast_transitions' => true,
            ];

            wp_set_current_user(0);

            $_POST = [
                'identifier' => $username,
                'password' => $password,
                'device_id' => 'device-a',
                'profile_id' => 'profile-a',
            ];
            $_REQUEST = $_POST;

            try {
                $login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($login['success'] ?? false));
            $login_data = is_array($login['data'] ?? null) ? $login['data'] : [];
            $token = (string) ($login_data['auth_token'] ?? '');
            $this->assertNotSame('', $token);
            $this->assertSame($user_id, (int) (($login_data['user'] ?? [])['id'] ?? 0));

            $sessions = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
            $this->assertIsArray($sessions);
            $this->assertNotEmpty($sessions);

            $_POST = [
                'auth_token' => $token,
                'state' => wp_json_encode($state_payload),
                'events' => wp_json_encode($events),
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $sync = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($sync['success'] ?? false));
            $sync_data = is_array($sync['data'] ?? null) ? $sync['data'] : [];
            $this->assertSame(2, (int) (($sync_data['stats'] ?? [])['processed'] ?? 0));
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) ($sync_data['scope_word_ids'] ?? []))));
            $effective_category_id = $fixture['effective_category_id'];
            $synced_category_ids = array_values(array_map('intval', (array) (($sync_data['state'] ?? [])['category_ids'] ?? [])));
            $this->assertContains($effective_category_id, $synced_category_ids);
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) (($sync_data['state'] ?? [])['starred_word_ids'] ?? []))));
            $this->assertSame('only', (string) (($sync_data['state'] ?? [])['star_mode'] ?? ''));
            $this->assertTrue((bool) (($sync_data['state'] ?? [])['fast_transitions'] ?? false));
            $progress_words = (array) ($sync_data['progress_words'] ?? []);
            $this->assertTrue(
                array_key_exists($fixture['word_id'], $progress_words) || array_key_exists((string) $fixture['word_id'], $progress_words)
            );

            $progress_rows = ll_tools_get_user_word_progress_rows($user_id, [$fixture['word_id']]);
            $this->assertArrayHasKey($fixture['word_id'], $progress_rows);
            $row = $progress_rows[$fixture['word_id']];
            $this->assertSame(1, (int) ($row['total_coverage'] ?? 0));
            $this->assertSame('2026-02-03 04:05:06', (string) ($row['last_seen_at'] ?? ''));

            $saved_state = ll_tools_get_user_study_state($user_id);
            $this->assertNotEmpty(array_values(array_map('intval', (array) ($saved_state['category_ids'] ?? []))));
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) ($saved_state['starred_word_ids'] ?? []))));
            $this->assertSame('only', (string) ($saved_state['star_mode'] ?? ''));
            $this->assertTrue((bool) ($saved_state['fast_transitions'] ?? false));

            $category_progress = (array) ($sync_data['category_progress'] ?? []);
            $this->assertTrue(
                array_key_exists($effective_category_id, $category_progress) || array_key_exists((string) $effective_category_id, $category_progress)
            );
            $this->assertArrayHasKey('recommendation_queue', $sync_data);
            $this->assertArrayHasKey('next_activity', $sync_data);
            $this->assertArrayHasKey('server_time', $sync_data);

            $_POST = [
                'auth_token' => $token,
                'state' => wp_json_encode($state_payload),
                'events' => wp_json_encode($events),
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $repeat_sync = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($repeat_sync['success'] ?? false));
            $progress_rows_after_repeat = ll_tools_get_user_word_progress_rows($user_id, [$fixture['word_id']]);
            $this->assertSame(1, (int) (($progress_rows_after_repeat[$fixture['word_id']] ?? [])['total_coverage'] ?? 0));

            $_POST = [
                'auth_token' => $token,
            ];
            $_REQUEST = $_POST;

            try {
                $logout = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_logout_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($logout['success'] ?? false));

            $_POST = [
                'auth_token' => $token,
                'events' => '[]',
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $rejected = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($rejected['success'] ?? true));
            $this->assertSame('Sign in required.', (string) (($rejected['data'] ?? [])['message'] ?? ''));
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_offline_app_login_rate_limit_blocks_after_configured_attempts(): void
    {
        $ip = '203.0.113.44';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_offline_app_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_offline_app_login_ip_attempt_window', $window_filter);

        $username = 'offline_rate_' . strtolower(wp_generate_password(6, false, false));
        $email = $username . '@example.com';
        $password = 'Pass!' . wp_generate_password(12, false, false);
        $user_id = wp_create_user($username, $password, $email);
        $this->assertIsInt($user_id);

        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        try {
            ll_tools_offline_app_reset_login_attempts($ip);

            $_POST = [
                'identifier' => $username,
                'password' => $password,
                'device_id' => 'rate-device',
                'profile_id' => 'rate-profile',
            ];
            $_REQUEST = $_POST;

            try {
                $first_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($first_login['success'] ?? false));
            $first_login_data = is_array($first_login['data'] ?? null) ? $first_login['data'] : [];
            $this->assertNotSame('', (string) ($first_login_data['auth_token'] ?? ''));
            $this->assertSame($user_id, (int) (($first_login_data['user'] ?? [])['id'] ?? 0));

            $_POST = [
                'identifier' => $username,
                'password' => 'wrong-password',
            ];
            $_REQUEST = $_POST;

            try {
                $second_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($second_login['success'] ?? true));
            $this->assertSame('Invalid login.', (string) (($second_login['data'] ?? [])['message'] ?? ''));

            $_POST = [
                'identifier' => $username,
                'password' => $password,
            ];
            $_REQUEST = $_POST;

            try {
                $third_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($third_login['success'] ?? true));
            $this->assertSame(429, (int) ($third_login['status'] ?? 0));
            $this->assertSame(
                'Too many login attempts. Please try again in a few minutes.',
                (string) (($third_login['data'] ?? [])['message'] ?? '')
            );
        } finally {
            ll_tools_offline_app_reset_login_attempts($ip);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
            remove_filter('ll_tools_offline_app_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_offline_app_login_ip_attempt_window', $window_filter);
        }
    }

    private function createOfflineSyncFixture(): array
    {
        $wordset = wp_insert_term('Offline Sync Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Offline Sync Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $effective_category_id = $category_id;
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved_category_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved_category_id > 0) {
                $effective_category_id = $resolved_category_id;
            }
        }
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Offline Sync Word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', 'Offline Sync Translation');

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'effective_category_id' => $effective_category_id,
            'word_id' => $word_id,
        ];
    }

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
        $headers_before = function_exists('headers_list') ? headers_list() : [];

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
        $status = 0;
        if (function_exists('headers_list')) {
            $headers_after = headers_list();
            $new_headers = array_values(array_diff($headers_after, $headers_before));
            foreach ($new_headers as $header_line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header_line, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
                if (preg_match('/^Status:\s+(\d{3})\b/', (string) $header_line, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }
        if ($status === 0) {
            $status = (int) http_response_code();
        }
        $decoded['status'] = $status;
        return $decoded;
    }
}
