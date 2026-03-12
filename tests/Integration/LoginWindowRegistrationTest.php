<?php
declare(strict_types=1);

final class LoginWindowRegistrationTest extends LL_Tools_TestCase
{
    public function test_registration_setting_defaults_to_enabled(): void
    {
        delete_option('ll_allow_learner_self_registration');

        $this->assertTrue(ll_tools_is_learner_self_registration_enabled());
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

    public function test_login_window_renders_custom_auth_forms_when_enabled(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringContainsString('name="log"', $markup);
        $this->assertStringContainsString('name="pwd"', $markup);
        $this->assertStringContainsString('name="rememberme"', $markup);
        $this->assertStringContainsString('checked', $markup);
        $this->assertStringContainsString('name="user_email"', $markup);
        $this->assertStringContainsString('name="user_login"', $markup);
        $this->assertStringContainsString('name="user_pass"', $markup);
        $this->assertStringContainsString('data-ll-register-email="1"', $markup);
        $this->assertStringContainsString('data-ll-register-password="1"', $markup);
        $this->assertStringContainsString('ll_tools_register_math_answer', $markup);
        $this->assertMatchesRegularExpression('/type="text"\s+id="ll-tools-register-password-[^"]+"\s+name="user_pass"[\s\S]*?autocomplete="new-password"/', $markup);
        $this->assertMatchesRegularExpression('/>\s*[1-5] \+ [1-5] =\s*<\/label>/', $markup);
        $this->assertStringContainsString('action="http://example.org/wp-admin/admin-post.php"', $markup);
        $this->assertStringContainsString('width="20"', $markup);
        $this->assertStringContainsString('height="20"', $markup);
        $this->assertStringNotContainsString('wp-login.php', $markup);
        $this->assertStringNotContainsString('We suggest a username', $markup);
        $this->assertStringNotContainsString('A strong password', $markup);
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

    public function test_disposable_email_detection_blocks_known_domains_and_subdomains(): void
    {
        $this->assertTrue(ll_tools_login_window_is_blocked_email('new@mailinator.com'));
        $this->assertTrue(ll_tools_login_window_is_blocked_email('new@sub.yopmail.com'));
        $this->assertFalse(ll_tools_login_window_is_blocked_email('new@gmail.com'));
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
}
