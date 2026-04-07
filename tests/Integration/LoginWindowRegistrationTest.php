<?php
declare(strict_types=1);

final class LoginWindowRegistrationTest extends LL_Tools_TestCase
{
    public function test_registration_setting_defaults_to_enabled(): void
    {
        delete_option('ll_allow_learner_self_registration');

        $this->assertTrue(ll_tools_is_learner_self_registration_enabled());
    }

    public function test_generated_registration_password_visibility_defaults_to_enabled(): void
    {
        delete_option('ll_show_generated_registration_password');

        $this->assertTrue(ll_tools_is_generated_registration_password_visible());
    }

    public function test_registration_admin_notification_setting_defaults_to_enabled(): void
    {
        delete_option('ll_tools_send_registration_admin_email');

        $this->assertTrue(ll_tools_is_registration_admin_notification_enabled());
    }

    public function test_registration_setting_can_disable_frontend_signup(): void
    {
        update_option('ll_allow_learner_self_registration', 0);

        $this->assertFalse(ll_tools_is_learner_self_registration_enabled());
    }

    public function test_registration_setting_syncs_wordpress_registration_when_enabled(): void
    {
        update_option('users_can_register', 0);

        update_option('ll_allow_learner_self_registration', 1);

        $this->assertSame(1, (int) get_option('users_can_register', 0));
    }

    public function test_registration_setting_syncs_wordpress_registration_when_disabled(): void
    {
        update_option('users_can_register', 1);

        update_option('ll_allow_learner_self_registration', 0);

        $this->assertSame(0, (int) get_option('users_can_register', 1));
    }

    public function test_admin_notification_recipient_falls_back_to_site_admin_email(): void
    {
        delete_option('ll_tools_recording_notification_email');
        update_option('admin_email', 'site-admin@example.net');

        $this->assertSame('site-admin@example.net', ll_tools_get_admin_notification_recipient());
    }

    public function test_registration_admin_notification_filter_can_disable_core_admin_email(): void
    {
        update_option('ll_tools_send_registration_admin_email', 0);
        $user_id = self::factory()->user->create([
            'user_login' => 'notifyoff',
            'user_email' => 'notifyoff@example.org',
        ]);
        $user = get_userdata($user_id);

        $this->assertInstanceOf(WP_User::class, $user);
        $this->assertFalse(apply_filters('wp_send_new_user_notification_to_admin', true, $user));
    }

    public function test_registration_admin_notification_email_filter_uses_shared_recipient_and_site_domain_sender(): void
    {
        update_option('ll_tools_send_registration_admin_email', 1);
        update_option('ll_tools_recording_notification_email', 'alerts@example.net');
        update_option('blogname', 'Starter English');

        $user_id = self::factory()->user->create([
            'user_login' => 'notifyheaders',
            'user_email' => 'notifyheaders@example.org',
        ]);
        $user = get_userdata($user_id);

        $email = apply_filters('wp_new_user_notification_email_admin', [
            'to' => 'placeholder@example.com',
            'subject' => '[%s] New User Registration',
            'message' => 'Placeholder',
            'headers' => '',
        ], $user, 'Starter English');

        $this->assertSame('alerts@example.net', $email['to']);
        $this->assertStringContainsString(
            'From: Starter English <wordpress@example.org>',
            $this->normalizeMailHeaders($email['headers'])
        );
    }

    public function test_custom_registration_helper_sends_admin_notification_when_enabled(): void
    {
        update_option('ll_tools_send_registration_admin_email', 1);
        update_option('ll_tools_recording_notification_email', 'alerts@example.net');
        update_option('blogname', 'Starter English');

        $user_id = self::factory()->user->create([
            'user_login' => 'newlearner',
            'user_email' => 'newlearner@example.org',
        ]);

        $captured = [];
        $mail_filter = static function ($pre, $atts) use (&$captured) {
            $captured[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mail_filter, 10, 2);

        try {
            $this->assertTrue(ll_tools_maybe_send_registration_admin_notification($user_id));
        } finally {
            remove_filter('pre_wp_mail', $mail_filter, 10);
        }

        $this->assertCount(1, $captured);
        $mail = $captured[0];

        $this->assertSame('alerts@example.net', $mail['to']);
        $this->assertStringContainsString('New User Registration', (string) $mail['subject']);
        $this->assertStringContainsString('newlearner', (string) $mail['message']);
        $this->assertStringContainsString('newlearner@example.org', (string) $mail['message']);
        $this->assertStringContainsString(
            'From: Starter English <wordpress@example.org>',
            $this->normalizeMailHeaders($mail['headers'])
        );
    }

    public function test_custom_registration_helper_skips_admin_notification_when_disabled(): void
    {
        update_option('ll_tools_send_registration_admin_email', 0);

        $user_id = self::factory()->user->create([
            'user_login' => 'skipnotify',
            'user_email' => 'skipnotify@example.org',
        ]);

        $captured = [];
        $mail_filter = static function ($pre, $atts) use (&$captured) {
            $captured[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mail_filter, 10, 2);

        try {
            $this->assertFalse(ll_tools_maybe_send_registration_admin_notification($user_id));
        } finally {
            remove_filter('pre_wp_mail', $mail_filter, 10);
        }

        $this->assertSame([], $captured);
    }

    public function test_login_window_renders_custom_auth_forms_when_enabled(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('ll_show_generated_registration_password', 1);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringContainsString('name="log"', $markup);
        $this->assertStringContainsString('name="pwd"', $markup);
        $this->assertSame(2, substr_count($markup, 'name="rememberme"'));
        $this->assertSame(2, substr_count($markup, 'Keep me signed in'));
        $this->assertStringContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('name="user_login"', $markup);
        $this->assertStringContainsString('name="user_pass"', $markup);
        $this->assertStringContainsString('id="ll-tools-register-remember-', $markup);
        $this->assertStringContainsString('data-ll-register-email="1"', $markup);
        $this->assertStringContainsString('data-ll-register-password="1"', $markup);
        $this->assertStringContainsString('data-ll-register-password-toggle="1"', $markup);
        $this->assertStringContainsString('ll_tools_register_math_answer', $markup);
        $this->assertStringNotContainsString('ll-tools-login-window__register-message', $markup);
        $this->assertMatchesRegularExpression('/type="text"\s+id="ll-tools-register-password-[^"]+"\s+name="user_pass"[\s\S]*?autocomplete="new-password"/', $markup);
        $this->assertMatchesRegularExpression('/>\s*[1-5] \+ [1-5] =\s*<\/label>/', $markup);
        $this->assertStringContainsString('action="http://example.org/wp-admin/admin-post.php"', $markup);
        $this->assertStringContainsString('width="20"', $markup);
        $this->assertStringContainsString('height="20"', $markup);
        $this->assertStringNotContainsString('wp-login.php', $markup);
        $this->assertStringNotContainsString('We suggest a username', $markup);
        $this->assertStringNotContainsString('A strong password', $markup);
    }

    public function test_login_window_can_mask_generated_registration_password_by_default(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('ll_show_generated_registration_password', 0);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertMatchesRegularExpression('/type="password"\s+id="ll-tools-register-password-[^"]+"\s+name="user_pass"[\s\S]*?data-ll-register-password="1"/', $markup);
        $this->assertStringContainsString('data-ll-register-password-toggle="1"', $markup);
        $this->assertMatchesRegularExpression('/<button[^>]*data-ll-register-password-toggle="1"[\s\S]*?>\s*Show\s*<\/button>/', $markup);
    }

    public function test_login_window_hides_registration_form_when_disabled(): void
    {
        update_option('ll_allow_learner_self_registration', 0);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringNotContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('New account registration is currently disabled.', $markup);
    }

    public function test_registration_availability_requires_wordpress_user_registration(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 0);

        $this->assertFalse(ll_tools_is_wordpress_user_registration_enabled());
        $this->assertFalse(ll_tools_is_learner_self_registration_available());

        update_option('users_can_register', 1);

        $this->assertTrue(ll_tools_is_wordpress_user_registration_enabled());
        $this->assertTrue(ll_tools_is_learner_self_registration_available());
    }

    public function test_login_window_hides_registration_form_when_wordpress_registration_is_disabled(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 0);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringNotContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('New account registration is currently disabled.', $markup);
    }

    public function test_register_auth_request_renders_signup_only_screen(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $_GET['ll_tools_auth'] = 'register';

        try {
            $markup = ll_tools_render_login_window([
                'show_registration' => true,
                'show_lost_password' => false,
                'title' => __('Sign in or create an account', 'll-tools-text-domain'),
                'registration_title' => __('Create learner account', 'll-tools-text-domain'),
                'message' => __('Use an account to save your progress and keep learning from this page.', 'll-tools-text-domain'),
            ]);
        } finally {
            unset($_GET['ll_tools_auth']);
        }

        $this->assertStringNotContainsString('name="log"', $markup);
        $this->assertStringNotContainsString('name="pwd"', $markup);
        $this->assertStringContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('name="user_login"', $markup);
        $this->assertStringContainsString('id="ll-tools-register-remember-', $markup);
        $this->assertStringContainsString('Create learner account', $markup);
        $this->assertStringContainsString('Already have an account?', $markup);
        $this->assertStringContainsString('ll_tools_auth=login', $markup);
        $this->assertStringNotContainsString('Use an account to save your progress and keep learning from this page.', $markup);
        $this->assertStringNotContainsString('ll-tools-login-window__divider', $markup);
    }

    public function test_login_auth_request_renders_login_only_screen_with_signup_link(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $_GET['ll_tools_auth'] = 'login';

        try {
            $markup = ll_tools_render_login_window([
                'show_registration' => true,
                'show_lost_password' => false,
            ]);
        } finally {
            unset($_GET['ll_tools_auth']);
        }

        $this->assertStringContainsString('name="log"', $markup);
        $this->assertStringContainsString('name="pwd"', $markup);
        $this->assertStringNotContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('Need an account?', $markup);
        $this->assertStringContainsString('ll_tools_auth=register', $markup);
        $this->assertStringNotContainsString('ll-tools-login-window__divider', $markup);
    }

    public function test_frontend_auth_url_keeps_auth_inside_plugin_ui(): void
    {
        $url = ll_tools_get_frontend_auth_url('http://example.org/learn/?foo=bar', 'register');

        $this->assertSame('http://example.org/learn/?foo=bar&ll_tools_auth=register#ll-tools-auth-window', $url);
    }

    public function test_username_suggestion_uses_email_base_and_numeric_suffix(): void
    {
        self::factory()->user->create([
            'user_login' => 'johndoe',
            'user_email' => 'existing@example.org',
        ]);

        $this->assertSame('janedoe', ll_tools_login_window_available_username_from_email('jane.doe@example.org'));
        $this->assertSame('johndoe1', ll_tools_login_window_available_username_from_email('john.doe@example.org'));
    }

    public function test_registration_email_validation_rejects_addresses_that_only_become_valid_after_sanitization(): void
    {
        $validation = ll_tools_login_window_validate_registration_email(
            '%spinfile-namesdat%_%spinfile-lnamesdat%_%random-10-10000%@bientotmail.com'
        );

        $this->assertSame('', $validation['email']);
        $this->assertSame(
            ['Please enter a valid email address.'],
            array_values(array_map('strval', $validation['errors']))
        );
    }

    public function test_disposable_email_detection_blocks_known_domains_and_subdomains(): void
    {
        $this->assertTrue(ll_tools_login_window_is_blocked_email('new@mailinator.com'));
        $this->assertTrue(ll_tools_login_window_is_blocked_email('new@sub.yopmail.com'));
        $this->assertFalse(ll_tools_login_window_is_blocked_email('new@gmail.com'));
    }

    public function test_frontend_registration_rejects_template_style_email_addresses(): void
    {
        $redirect = $this->runRegistrationRequest([
            'user_login' => 'PeggyRoara',
            'user_email' => '%spinfile-namesdat%_%spinfile-lnamesdat%_%random-10-10000%@bientotmail.com',
        ]);

        $payload = $this->getFeedbackPayloadFromRedirect($redirect);

        $this->assertSame('register', (string) ($payload['form'] ?? ''));
        $this->assertSame('error', (string) ($payload['type'] ?? ''));
        $this->assertContains('Please enter a valid email address.', $payload['messages']);
        $this->assertFalse(username_exists('PeggyRoara'));
    }

    public function test_core_registration_blocks_disposable_email_domains(): void
    {
        $result = register_new_user('tempblockeduser', 'tempblocked@mailinator.com');

        $this->assertWPError($result);
        $this->assertContains('Please use a non-temporary email address.', $result->get_error_messages());
        $this->assertFalse(username_exists('tempblockeduser'));
    }

    public function test_registration_rate_limit_blocks_after_configured_attempts(): void
    {
        $ip = '203.0.113.24';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_registration_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_registration_ip_attempt_window', $window_filter);

        try {
            ll_tools_login_window_reset_registration_attempts($ip);

            $this->assertFalse(ll_tools_login_window_get_registration_rate_limit_status($ip)['limited']);

            ll_tools_login_window_record_registration_attempt($ip);
            $this->assertFalse(ll_tools_login_window_get_registration_rate_limit_status($ip)['limited']);

            ll_tools_login_window_record_registration_attempt($ip);
            $status = ll_tools_login_window_get_registration_rate_limit_status($ip);

            $this->assertTrue($status['limited']);
            $this->assertSame(2, (int) $status['attempts']);
            $this->assertSame(2, (int) $status['limit']);
        } finally {
            ll_tools_login_window_reset_registration_attempts($ip);
            remove_filter('ll_tools_registration_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_registration_ip_attempt_window', $window_filter);
        }
    }

    public function test_guest_utility_menu_hides_sign_up_link_when_registration_is_unavailable(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 0);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'wordset',
        ]);

        $this->assertStringContainsString('ll_tools_auth=login', $markup);
        $this->assertStringNotContainsString('ll_tools_auth=register', $markup);
        $this->assertStringNotContainsString('wp-login.php', $markup);
    }

    public function test_guest_utility_menu_shows_plugin_auth_links_when_registration_is_available(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'wordset',
        ]);

        $this->assertStringContainsString('ll_tools_auth=login', $markup);
        $this->assertStringContainsString('ll_tools_auth=register', $markup);
        $this->assertStringNotContainsString('wp-login.php', $markup);
    }

    private function normalizeMailHeaders($headers): string
    {
        if (is_array($headers)) {
            return implode("\n", array_map('strval', $headers));
        }

        return (string) $headers;
    }

    private function runRegistrationRequest(array $overrides = []): string
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $left = 2;
        $right = 3;
        $timestamp = time() - 5;
        $previous_post = $_POST;
        $previous_server = $_SERVER;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge([
            'action' => 'll_tools_register_learner',
            'redirect_to' => 'http://example.org/learn/',
            'll_tools_register_learner_nonce' => wp_create_nonce('ll_tools_register_learner'),
            'user_email' => 'learner@example.org',
            'user_login' => 'learneruser',
            'user_pass' => 'password123',
            'll_tools_register_username_is_custom' => '1',
            'll_tools_register_rendered_at' => (string) $timestamp,
            'll_tools_register_math_left' => (string) $left,
            'll_tools_register_math_right' => (string) $right,
            'll_tools_register_math_signature' => ll_tools_login_window_sign_registration_challenge($timestamp, $left, $right),
            'll_tools_register_math_answer' => (string) ($left + $right),
        ], $overrides);

        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            ll_tools_handle_frontend_learner_registration();
            $this->fail('Expected frontend registration handler to redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
            $_POST = $previous_post;
            $_SERVER = $previous_server;
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
