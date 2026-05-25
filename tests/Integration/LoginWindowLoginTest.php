<?php
declare(strict_types=1);

final class LoginWindowLoginTest extends LL_Tools_TestCase
{
    public function test_frontend_login_rate_limit_defaults_to_ten_attempts_for_ten_minutes(): void
    {
        $config = ll_tools_login_window_login_attempt_limit_config();

        $this->assertSame(10, (int) $config['limit']);
        $this->assertSame(10 * MINUTE_IN_SECONDS, (int) $config['window']);
    }

    public function test_rendered_auth_forms_carry_signed_frontend_locale(): void
    {
        update_option('users_can_register', 1);
        update_option('ll_allow_learner_self_registration', 1);

        $locale_filter = static function (): string {
            return 'tr_TR';
        };
        add_filter('locale', $locale_filter, 999);

        try {
            $html = ll_tools_render_login_window([
                'show_registration' => true,
                'screen_mode' => 'combined',
                'redirect_to' => 'http://example.org/learn/',
            ]);

            $this->assertSame(2, substr_count($html, 'name="ll_locale" value="tr_TR"'));
            $this->assertSame(2, substr_count($html, 'name="ll_locale_nonce"'));
        } finally {
            remove_filter('locale', $locale_filter, 999);
        }
    }

    public function test_frontend_login_rate_limit_blocks_after_configured_failed_attempts(): void
    {
        $ip = '203.0.113.24';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_login_ip_attempt_window', $window_filter);

        try {
            ll_tools_login_window_reset_login_attempts($ip);

            $first_redirect = $this->runLoginRequest($ip, [
                'log' => 'frontlogin@example.org',
                'pwd' => '',
            ]);
            $first_payload = $this->getFeedbackPayloadFromRedirect($first_redirect);

            $this->assertSame('login', (string) ($first_payload['form'] ?? ''));
            $this->assertSame('error', (string) ($first_payload['type'] ?? ''));
            $this->assertContains('Please enter your password.', $first_payload['messages']);
            $this->assertSame(1, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);

            $second_redirect = $this->runLoginRequest($ip, [
                'log' => 'frontlogin@example.org',
                'pwd' => '',
            ]);
            $second_payload = $this->getFeedbackPayloadFromRedirect($second_redirect);

            $this->assertContains('Please enter your password.', $second_payload['messages']);
            $this->assertSame(2, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);

            $third_redirect = $this->runLoginRequest($ip, [
                'log' => 'frontlogin@example.org',
                'pwd' => '',
            ]);
            $third_payload = $this->getFeedbackPayloadFromRedirect($third_redirect);

            $this->assertContains(ll_tools_login_window_login_rate_limit_message(), $third_payload['messages']);
            $this->assertSame('login', (string) ($third_payload['form'] ?? ''));
            $this->assertSame(2, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);
            $this->assertTrue((bool) ll_tools_login_window_get_login_rate_limit_status($ip)['limited']);
        } finally {
            ll_tools_login_window_reset_login_attempts($ip);
            remove_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_login_ip_attempt_window', $window_filter);
        }
    }

    public function test_frontend_login_rate_limit_message_uses_signed_frontend_locale(): void
    {
        $ip = '203.0.113.28';
        $limit_filter = static function (): int {
            return 1;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };
        $english_message = 'Too many login attempts from this connection. Please try again in a few minutes.';

        ll_tools_login_window_load_textdomain_for_locale('tr_TR');
        $expected_message = ll_tools_login_window_login_rate_limit_message();
        $this->reloadPluginTextdomainForCurrentLocale();

        $this->assertNotSame($english_message, $expected_message);

        add_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_login_ip_attempt_window', $window_filter);

        try {
            ll_tools_login_window_reset_login_attempts($ip);

            $locale_fields = [
                'll_locale' => 'tr_TR',
                'll_locale_nonce' => wp_create_nonce(ll_tools_get_locale_switch_nonce_action()),
            ];

            $this->runLoginRequest($ip, array_merge($locale_fields, [
                'log' => 'frontlogin@example.org',
                'pwd' => '',
            ]));

            $blocked_redirect = $this->runLoginRequest($ip, array_merge($locale_fields, [
                'log' => 'frontlogin@example.org',
                'pwd' => '',
            ]));
            $blocked_payload = $this->getFeedbackPayloadFromRedirect($blocked_redirect);

            $this->assertContains($expected_message, $blocked_payload['messages']);
            $this->assertNotContains($english_message, $blocked_payload['messages']);
        } finally {
            ll_tools_login_window_reset_login_attempts($ip);
            remove_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_login_ip_attempt_window', $window_filter);
            $this->reloadPluginTextdomainForCurrentLocale();
        }
    }

    public function test_tracked_frontend_login_block_can_be_released_for_ip(): void
    {
        $ip = '203.0.113.27';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_login_ip_attempt_window', $window_filter);

        try {
            ll_tools_login_window_reset_login_attempts($ip);

            ll_tools_login_window_record_login_attempt($ip);
            ll_tools_login_window_record_login_attempt($ip);

            $status = ll_tools_login_window_get_login_rate_limit_status($ip);
            $this->assertSame(2, (int) $status['attempts']);
            $this->assertTrue((bool) $status['limited']);

            $tracked_rows = ll_tools_login_window_get_tracked_rate_limits(true);
            $matching_rows = array_values(array_filter($tracked_rows, static function (array $row) use ($ip): bool {
                return (string) ($row['type'] ?? '') === 'login' && (string) ($row['ip'] ?? '') === $ip;
            }));

            $this->assertCount(1, $matching_rows);
            $this->assertSame(2, (int) $matching_rows[0]['attempts']);

            $release = ll_tools_login_window_release_rate_limits_for_ip($ip);

            $this->assertSame($ip, (string) $release['ip']);
            $this->assertSame(2, (int) ($release['before']['login']['attempts'] ?? 0));
            $this->assertSame(0, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);
            $this->assertFalse((bool) ll_tools_login_window_get_login_rate_limit_status($ip)['limited']);

            $post_release_rows = array_values(array_filter(ll_tools_login_window_get_tracked_rate_limits(true), static function (array $row) use ($ip): bool {
                return (string) ($row['ip'] ?? '') === $ip;
            }));
            $this->assertSame([], $post_release_rows);
        } finally {
            ll_tools_login_window_reset_login_attempts($ip);
            remove_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_login_ip_attempt_window', $window_filter);
        }
    }

    public function test_frontend_login_failure_uses_plain_plugin_message_for_invalid_credentials(): void
    {
        $ip = '203.0.113.26';

        self::factory()->user->create([
            'user_login' => 'invalidcredentialuser',
            'user_email' => 'invalidcredential@example.org',
            'user_pass' => 'CorrectHorse1!',
        ]);

        try {
            ll_tools_login_window_reset_login_attempts($ip);

            $redirect = $this->runLoginRequest($ip, [
                'log' => 'invalidcredential@example.org',
                'pwd' => 'WrongHorse1!',
            ]);
            $payload = $this->getFeedbackPayloadFromRedirect($redirect);

            $this->assertSame('login', (string) ($payload['form'] ?? ''));
            $this->assertSame('error', (string) ($payload['type'] ?? ''));
            $this->assertSame(
                ['The username, email, or password you entered is incorrect.'],
                $payload['messages']
            );

            $message = (string) ($payload['messages'][0] ?? '');
            $this->assertStringNotContainsString('<strong>', $message);
            $this->assertStringNotContainsString('Lost your password', $message);

            $notice = ll_tools_render_login_window_notice($payload['messages'], (string) ($payload['type'] ?? 'error'));
            $this->assertStringContainsString('The username, email, or password you entered is incorrect.', $notice);
            $this->assertStringNotContainsString('&lt;strong&gt;', $notice);
            $this->assertStringNotContainsString('lostpassword', $notice);
        } finally {
            ll_tools_login_window_reset_login_attempts($ip);
        }
    }

    public function test_frontend_login_success_resets_rate_limit_attempts(): void
    {
        $ip = '203.0.113.25';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_login_ip_attempt_window', $window_filter);

        self::factory()->user->create([
            'user_login' => 'loginreset',
            'user_email' => 'loginreset@example.org',
            'user_pass' => 'CorrectHorse1!',
        ]);

        try {
            ll_tools_login_window_reset_login_attempts($ip);

            $failed_redirect = $this->runLoginRequest($ip, [
                'log' => 'loginreset@example.org',
                'pwd' => '',
            ]);
            $failed_payload = $this->getFeedbackPayloadFromRedirect($failed_redirect);

            $this->assertContains('Please enter your password.', $failed_payload['messages']);
            $this->assertSame(1, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);

            $success_redirect = $this->runLoginRequest($ip, [
                'log' => 'loginreset@example.org',
                'pwd' => 'CorrectHorse1!',
            ]);

            $this->assertSame('http://example.org/learn/', $success_redirect);
            $this->assertSame(0, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);
            $this->assertFalse((bool) ll_tools_login_window_get_login_rate_limit_status($ip)['limited']);

            $post_success_redirect = $this->runLoginRequest($ip, [
                'log' => 'loginreset@example.org',
                'pwd' => '',
            ]);
            $post_success_payload = $this->getFeedbackPayloadFromRedirect($post_success_redirect);

            $this->assertContains('Please enter your password.', $post_success_payload['messages']);
            $this->assertNotContains(ll_tools_login_window_login_rate_limit_message(), $post_success_payload['messages']);
            $this->assertSame(1, (int) ll_tools_login_window_get_login_rate_limit_status($ip)['attempts']);
        } finally {
            ll_tools_login_window_reset_login_attempts($ip);
            remove_filter('ll_tools_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_login_ip_attempt_window', $window_filter);

            wp_set_current_user(0);
            if (function_exists('wp_logout')) {
                wp_logout();
            }
        }
    }

    public function test_keep_me_signed_in_extends_remembered_auth_cookie_expiration(): void
    {
        $user_id = self::factory()->user->create([
            'user_login' => 'rememberduration',
            'user_email' => 'rememberduration@example.org',
        ]);
        $default_expiration = 14 * DAY_IN_SECONDS;

        $this->assertSame(
            $default_expiration,
            apply_filters('auth_cookie_expiration', $default_expiration, $user_id, false)
        );
        $this->assertSame(
            90 * DAY_IN_SECONDS,
            apply_filters('auth_cookie_expiration', $default_expiration, $user_id, true)
        );

        $custom_expiration = static function (): int {
            return 45 * DAY_IN_SECONDS;
        };
        add_filter('ll_tools_remember_login_expiration', $custom_expiration);

        try {
            $this->assertSame(
                45 * DAY_IN_SECONDS,
                apply_filters('auth_cookie_expiration', $default_expiration, $user_id, true)
            );
        } finally {
            remove_filter('ll_tools_remember_login_expiration', $custom_expiration);
        }
    }

    private function runLoginRequest(string $ip, array $overrides = []): string
    {
        $previous_post = $_POST;
        $previous_request = $_REQUEST;
        $previous_server = $_SERVER;
        $previous_locale = function_exists('get_locale') ? (string) get_locale() : '';
        $redirect_url = '';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_POST = array_merge([
            'action' => 'll_tools_login',
            'redirect_to' => 'http://example.org/learn/',
            'll_tools_login_nonce' => wp_create_nonce('ll_tools_login'),
            'log' => 'loginreset@example.org',
            'pwd' => 'CorrectHorse1!',
            'rememberme' => '1',
        ], $overrides);
        $_REQUEST = array_merge($_GET, $_POST);

        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            ll_tools_handle_frontend_login();
            $this->fail('Expected frontend login handler to redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
            $_POST = $previous_post;
            $_REQUEST = $previous_request;
            $_SERVER = $previous_server;
            while (
                $previous_locale !== ''
                && function_exists('get_locale')
                && (string) get_locale() !== $previous_locale
                && function_exists('restore_current_locale')
                && restore_current_locale()
            ) {
                // Restore any locale stack entries left behind by intercepted redirects.
            }
            $this->reloadPluginTextdomainForCurrentLocale();
            wp_set_current_user(0);
            if (function_exists('wp_logout')) {
                wp_logout();
            }
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }

    private function getFeedbackPayloadFromRedirect(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $args = [];
        parse_str($query, $args);

        $token = ll_tools_login_window_sanitize_feedback_token($args['ll_tools_auth_feedback'] ?? '');
        $this->assertNotSame('', $token);

        $payload = get_transient(ll_tools_login_window_feedback_storage_key($token));
        $this->assertIsArray($payload);

        return $payload;
    }

    private function reloadPluginTextdomainForCurrentLocale(): void
    {
        if (function_exists('unload_textdomain')) {
            unload_textdomain('ll-tools-text-domain');
        }
        if (function_exists('ll_tools_load_textdomain')) {
            ll_tools_load_textdomain();
        }
    }
}
