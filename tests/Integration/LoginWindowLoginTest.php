<?php
declare(strict_types=1);

final class LoginWindowLoginTest extends LL_Tools_TestCase
{
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

    private function runLoginRequest(string $ip, array $overrides = []): string
    {
        $previous_post = $_POST;
        $previous_server = $_SERVER;
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
            $_SERVER = $previous_server;
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
}
