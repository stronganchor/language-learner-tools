<?php
declare(strict_types=1);

final class LocalePreferenceTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[LL_TOOLS_I18N_COOKIE], $_REQUEST['ll_locale'], $_GET['ll_locale'], $_REQUEST['ll_locale_nonce'], $_GET['ll_locale_nonce']);
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        delete_option('ll_enable_browser_language_autoswitch');
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
        $this->assertSame('tr_TR', (string) get_user_meta($user_id, 'locale', true));
        $this->assertSame('tr_TR', $_COOKIE[LL_TOOLS_I18N_COOKIE] ?? '');
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
