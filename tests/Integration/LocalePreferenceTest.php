<?php
declare(strict_types=1);

final class LocalePreferenceTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[LL_TOOLS_I18N_COOKIE], $_REQUEST['ll_locale'], $_GET['ll_locale'], $_REQUEST['ll_locale_nonce'], $_GET['ll_locale_nonce']);
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        delete_option('ll_enable_browser_language_autoswitch');
        remove_all_filters('ll_tools_tier2_public_locales');
        if (function_exists('set_current_screen')) {
            set_current_screen('front');
        }
        parent::tearDown();
    }

    public function test_filter_locale_prefers_logged_in_user_locale_over_conflicting_cookie(): void
    {
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'locale', 'tr_TR');
        wp_set_current_user($user_id);
        $_COOKIE[LL_TOOLS_I18N_COOKIE] = 'en_US';

        $this->assertSame('tr_TR', ll_tools_filter_locale('en_US'));
    }

    public function test_persist_locale_preference_updates_current_user_locale_and_cookie(): void
    {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);

        $this->assertTrue(ll_tools_persist_locale_preference('tr_TR'));
        $this->assertSame('tr_TR', (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true));
        $this->assertSame('tr_TR', (string) get_user_meta($user_id, 'locale', true));
        $this->assertSame('tr_TR', $_COOKIE[LL_TOOLS_I18N_COOKIE] ?? '');
    }

    public function test_public_only_locale_preference_is_stored_separately_from_staff_wp_locale(): void
    {
        add_filter('ll_tools_tier2_public_locales', static function (array $locales, bool $active_only): array {
            $locales[] = 'ru_RU';
            return array_values(array_unique($locales));
        }, 10, 2);
        $user_id = self::factory()->user->create();
        $user = get_user_by('id', $user_id);
        $user->add_cap('view_ll_tools');
        wp_set_current_user($user_id);
        update_user_meta($user_id, 'locale', 'de_DE');

        $this->assertTrue(ll_tools_persist_locale_preference('ru_RU'));

        $this->assertSame('ru_RU', (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true));
        $this->assertSame('de_DE', (string) get_user_meta($user_id, 'locale', true));
        $this->assertSame('ru_RU', ll_tools_get_user_locale_preference($user_id, false));
    }

    public function test_inactive_tier2_locale_preference_is_not_accepted_by_default(): void
    {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);

        $this->assertFalse(ll_tools_persist_locale_preference('ru_RU'));
        $this->assertSame('', (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true));
        update_user_meta($user_id, 'locale', 'ru_RU');
        $this->assertSame('', ll_tools_get_user_locale_preference($user_id, false));
        $this->assertNotContains('ru_RU', ll_tools_get_plugin_locales());
    }

    public function test_frontend_locale_filter_allows_public_only_preference_for_elevated_user(): void
    {
        add_filter('ll_tools_tier2_public_locales', static function (array $locales, bool $active_only): array {
            $locales[] = 'ru_RU';
            return array_values(array_unique($locales));
        }, 10, 2);
        $user_id = self::factory()->user->create();
        $user = get_user_by('id', $user_id);
        $user->add_cap('view_ll_tools');
        wp_set_current_user($user_id);
        update_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, 'ru_RU');
        update_user_meta($user_id, 'locale', 'de_DE');
        if (function_exists('set_current_screen')) {
            set_current_screen('front');
        }

        $this->assertSame('ru_RU', ll_tools_filter_locale('en_US'));
    }

    public function test_admin_locale_falls_back_for_elevated_public_only_preference(): void
    {
        add_filter('ll_tools_tier2_public_locales', static function (array $locales, bool $active_only): array {
            $locales[] = 'ru_RU';
            return array_values(array_unique($locales));
        }, 10, 2);
        $user_id = self::factory()->user->create();
        $user = get_user_by('id', $user_id);
        $user->add_cap('view_ll_tools');
        wp_set_current_user($user_id);
        update_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, 'ru_RU');
        update_user_meta($user_id, 'locale', 'ru_RU');
        if (function_exists('set_current_screen')) {
            set_current_screen('dashboard');
        }

        $this->assertSame('en_US', ll_tools_pre_determine_locale_for_staff_public_only_preference(null));
        $this->assertSame('en_US', (string) get_user_meta($user_id, 'locale', true));
        $this->assertSame('ru_RU', (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true));
    }

    public function test_append_preferred_locale_to_url_uses_saved_user_locale(): void
    {
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'locale', 'tr_TR');

        $url = home_url('/record-audio/');
        $redirect = ll_tools_append_preferred_locale_to_url($url, $user_id);
        $query = wp_parse_args((string) wp_parse_url($redirect, PHP_URL_QUERY));

        $this->assertSame('tr_TR', (string) ($query['ll_locale'] ?? ''));
        $this->assertSame($url, remove_query_arg(['ll_locale', 'll_locale_nonce'], $redirect));
        $this->assertNotSame('', (string) ($query['ll_locale_nonce'] ?? ''));

        $nonce = (string) ($query['ll_locale_nonce'] ?? '');
        $this->assertSame(1, wp_verify_nonce($nonce, ll_tools_get_locale_switch_nonce_action()));
    }

    public function test_recorder_ajax_locale_preference_prefers_explicit_request_then_user_locale(): void
    {
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'locale', 'tr_TR');
        wp_set_current_user($user_id);
        $_COOKIE[LL_TOOLS_I18N_COOKIE] = 'en_US';

        $this->assertSame('tr_TR', ll_tools_get_recorder_ajax_locale_preference());

        $_REQUEST['ll_locale'] = 'en_US';
        $_GET['ll_locale'] = 'en_US';

        $this->assertSame('en_US', ll_tools_get_recorder_ajax_locale_preference());
    }

    public function test_filter_locale_ignores_unsigned_locale_switch_requests(): void
    {
        $_REQUEST['ll_locale'] = 'tr_TR';
        $_GET['ll_locale'] = 'tr_TR';

        $this->assertSame('en_US', ll_tools_filter_locale('en_US'));
    }

    public function test_filter_locale_accepts_signed_locale_switch_requests(): void
    {
        $_REQUEST['ll_locale'] = 'tr_TR';
        $_GET['ll_locale'] = 'tr_TR';
        $_REQUEST['ll_locale_nonce'] = wp_create_nonce(ll_tools_get_locale_switch_nonce_action());
        $_GET['ll_locale_nonce'] = $_REQUEST['ll_locale_nonce'];

        $this->assertSame('tr_TR', ll_tools_filter_locale('en_US'));
    }

    public function test_filter_locale_defaults_to_browser_language_when_enabled(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';

        $this->assertSame('tr_TR', ll_tools_filter_locale('en_US'));
    }

    public function test_filter_locale_matches_available_german_plugin_locale(): void
    {
        $this->assertContains('de_DE', ll_tools_get_plugin_locales());

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7';

        $this->assertSame('de_DE', ll_tools_filter_locale('en_US'));
    }

    public function test_header_language_switcher_includes_flags(): void
    {
        ob_start();
        ll_tools_render_header_language_switcher();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-tools-header-language-switcher', $html);
        $this->assertStringContainsString('ll-lang-switcher--dropdown', $html);
        $this->assertStringContainsString('ll-flag', $html);
    }

    public function test_language_switcher_dropdown_display_renders_button_summary(): void
    {
        $html = ll_language_switcher_shortcode([
            'display' => 'dropdown',
            'button_label' => 'Language',
        ]);

        $this->assertStringContainsString('ll-lang-switcher--dropdown', $html);
        $this->assertStringContainsString('ll-lang-switcher__summary', $html);
        $this->assertStringContainsString('A/あ', $html);
        $this->assertStringContainsString('ll_locale_nonce', $html);
    }

    public function test_browser_locale_preference_skips_saved_logged_in_user_locale(): void
    {
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'locale', 'en_US');
        wp_set_current_user($user_id);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';

        $this->assertSame('', ll_tools_get_browser_locale_preference());
        $this->assertSame('en_US', ll_tools_filter_locale('tr_TR'));
    }

    public function test_filter_locale_skips_browser_language_when_admin_setting_disables_it(): void
    {
        update_option('ll_enable_browser_language_autoswitch', 0);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';

        $this->assertSame('en_US', ll_tools_filter_locale('en_US'));
    }
}
