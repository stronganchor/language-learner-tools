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

    public function test_login_window_renders_registration_form_when_enabled(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringContainsString('ll_tools_register_learner', $markup);
        $this->assertStringContainsString('name="ll_tools_register_username"', $markup);
        $this->assertStringContainsString('name="ll_tools_register_email"', $markup);
    }

    public function test_login_window_hides_registration_form_when_disabled(): void
    {
        update_option('ll_allow_learner_self_registration', 0);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_login_window([
            'show_registration' => true,
            'show_lost_password' => false,
        ]);

        $this->assertStringNotContainsString('name="ll_tools_register_username"', $markup);
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

        $this->assertStringNotContainsString('name="ll_tools_register_username"', $markup);
        $this->assertStringContainsString('New account registration is currently disabled.', $markup);
    }

    public function test_guest_utility_menu_hides_sign_up_link_when_registration_is_unavailable(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 0);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'wordset',
        ]);

        $this->assertStringContainsString('Log in', $markup);
        $this->assertStringNotContainsString('Sign up', $markup);
    }

    public function test_guest_utility_menu_shows_sign_up_link_when_registration_is_available(): void
    {
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 1);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'wordset',
        ]);

        $this->assertStringContainsString('Log in', $markup);
        $this->assertStringContainsString('Sign up', $markup);
    }
}
