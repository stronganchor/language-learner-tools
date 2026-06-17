<?php
declare(strict_types=1);

final class SiteToolsCustomUiTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var array<string,mixed> */
    private $optionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->serverBackup = $_SERVER;

        foreach ($this->trackedOptionNames() as $option_name) {
            $this->optionBackup[$option_name] = get_option($option_name, '__ll_tools_missing__');
        }
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_SERVER = $this->serverBackup;

        foreach ($this->optionBackup as $option_name => $value) {
            if ($value === '__ll_tools_missing__') {
                delete_option((string) $option_name);
                continue;
            }

            update_option((string) $option_name, $value);
        }

        delete_option('_transient_ll_wc_words_site_tools_alpha');
        delete_option('_transient_timeout_ll_wc_words_site_tools_alpha');
        delete_transient('ll_recording_page_creation_attempt');
        delete_transient('ll_editor_hub_page_creation_attempt');
        delete_transient('ll_dictionary_page_creation_attempt');
        delete_transient('ll_site_tools_page_creation_attempt');

        parent::tearDown();
    }

    public function test_site_tools_page_is_created_with_shortcode(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        delete_option('ll_default_site_tools_page_id');

        ll_tools_ensure_site_tools_page();

        $page_id = (int) get_option('ll_default_site_tools_page_id');
        $this->assertGreaterThan(0, $page_id);
        $this->assertSame('publish', (string) get_post_status($page_id));
        $this->assertStringContainsString('[ll_site_tools]', (string) get_post_field('post_content', $page_id));
    }

    public function test_site_tools_shortcode_renders_migrated_sections_for_admin(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl((string) get_permalink($page_id));

        $html = ll_site_tools_shortcode([]);

        $this->assertStringContainsString('Site Tools', $html);
        $this->assertStringContainsString('Study Defaults', $html);
        $this->assertStringContainsString('Learner Accounts', $html);
        $this->assertStringContainsString('Recording Defaults', $html);
        $this->assertStringContainsString('Recording Types', $html);
        $this->assertStringContainsString('Managed Pages', $html);
        $this->assertStringContainsString('Privacy &amp; Retention', $html);
        $this->assertStringContainsString('Plugin Updates', $html);
        $this->assertStringContainsString('API Providers', $html);
        $this->assertStringContainsString('Maintenance', $html);
        $this->assertStringContainsString('Refresh language list', $html);
        $this->assertStringContainsString('name="action" value="ll_tools_save_site_tools"', $html);
        $this->assertStringContainsString('name="ll_language_switcher_primary_count"', $html);
        $this->assertStringContainsString('name="ll_language_switcher_locale_order"', $html);
        $this->assertStringContainsString('name="action" value="ll_tools_site_tools_recording_type"', $html);
        $this->assertStringContainsString('name="action" value="ll_tools_manage_site_tools_page"', $html);
        $this->assertStringContainsString('name="action" value="ll_tools_run_site_tools_maintenance"', $html);
    }

    public function test_save_handler_updates_study_defaults_and_redirects_back(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        $_POST = [
            'll_site_tools_section' => 'study-defaults',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_study-defaults'),
            'redirect_to' => $page_url,
            'll_enable_browser_language_autoswitch' => '1',
            'll_max_options_override' => '7',
            'll_flashcard_image_size' => 'large',
            'll_language_switcher_primary_count' => '5',
            'll_language_switcher_locale_order' => 'fr,en,tr',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('study-defaults', (string) ($query['ll_site_tools_section'] ?? ''));

        $this->assertSame(1, (int) get_option('ll_enable_browser_language_autoswitch', 0));
        $this->assertSame(7, (int) get_option('ll_max_options_override', 0));
        $this->assertSame('large', (string) get_option('ll_flashcard_image_size', ''));
        $this->assertSame(5, (int) get_option(LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION, 0));
        $this->assertSame('fr_FR,en_US,tr_TR', (string) get_option(LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION, ''));
    }

    public function test_save_handler_updates_learner_account_defaults_and_syncs_wordpress_registration(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        update_option('users_can_register', 0);

        $_POST = [
            'll_site_tools_section' => 'learner-accounts',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_learner-accounts'),
            'redirect_to' => $page_url,
            'll_allow_learner_self_registration' => '1',
            'll_tools_send_registration_admin_email' => '1',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('learner-accounts', (string) ($query['ll_site_tools_section'] ?? ''));

        $this->assertSame(1, (int) get_option('ll_allow_learner_self_registration', 0));
        $this->assertSame(0, (int) get_option('ll_show_generated_registration_password', 1));
        $this->assertSame(1, (int) get_option('ll_tools_send_registration_admin_email', 0));
        $this->assertSame(1, (int) get_option('users_can_register', 0));
    }

    public function test_save_handler_updates_recording_defaults(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        $_POST = [
            'll_site_tools_section' => 'recording-defaults',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_recording-defaults'),
            'redirect_to' => $page_url,
            'll_hide_recording_titles' => '1',
            'll_tools_recording_notification_email' => 'alerts@example.com',
            'll_tools_recording_notification_delay_minutes' => '12',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('recording-defaults', (string) ($query['ll_site_tools_section'] ?? ''));

        $this->assertSame(1, (int) get_option('ll_hide_recording_titles', 0));
        $this->assertSame('alerts@example.com', (string) get_option('ll_tools_recording_notification_email', ''));
        $this->assertSame(12, (int) get_option('ll_tools_recording_notification_delay_minutes', 0));
    }

    public function test_save_handler_updates_privacy_retention_settings(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        $_POST = [
            'll_site_tools_section' => 'privacy-retention',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_privacy-retention'),
            'redirect_to' => $page_url,
            LL_TOOLS_USER_PROGRESS_RETENTION_OPTION => '365',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('privacy-retention', (string) ($query['ll_site_tools_section'] ?? ''));
        $this->assertSame(365, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_OPTION, 0));
    }

    public function test_save_handler_updates_plugin_update_channel(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        $_POST = [
            'll_site_tools_section' => 'plugin-updates',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_plugin-updates'),
            'redirect_to' => $page_url,
            'll_update_branch' => 'dev',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('plugin-updates', (string) ($query['ll_site_tools_section'] ?? ''));
        $this->assertSame('dev', (string) get_option('ll_update_branch', 'main'));
    }

    public function test_save_handler_updates_api_provider_keys(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        update_option('ll_deepl_api_key', 'old-deepl-key');
        update_option('ll_assemblyai_api_key', 'old-assembly-key');

        $_POST = [
            'll_site_tools_section' => 'api-providers',
            'll_site_tools_nonce' => wp_create_nonce('ll_tools_site_tools_api-providers'),
            'redirect_to' => $page_url,
            'll_deepl_api_key' => 'new-deepl-key',
            'll_assemblyai_api_key_clear' => '1',
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_save_site_tools_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('settings_saved', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('api-providers', (string) ($query['ll_site_tools_section'] ?? ''));
        $this->assertSame('new-deepl-key', (string) get_option('ll_deepl_api_key', ''));
        $this->assertSame('', (string) get_option('ll_assemblyai_api_key', ''));
    }

    public function test_recording_type_handler_adds_type_and_saves_uncategorized_defaults(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        $_POST = [
            'll_site_tools_recording_type_action' => 'add',
            'll_site_tools_recording_type_nonce' => wp_create_nonce('ll_tools_site_tools_recording_type_add'),
            'redirect_to' => $page_url,
            'term_name' => 'Narration',
            'term_slug' => 'narration',
        ];
        $_REQUEST = $_POST;

        $add_redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_site_tools_recording_type_action();
        });

        $add_query = $this->parseRedirectQuery($add_redirect_url);
        $this->assertSame('recording_type_added', (string) ($add_query['ll_site_tools_notice'] ?? ''));
        $this->assertInstanceOf(WP_Term::class, get_term_by('slug', 'narration', 'recording_type'));

        $_POST = [
            'll_site_tools_recording_type_action' => 'defaults',
            'll_site_tools_recording_type_nonce' => wp_create_nonce('ll_tools_site_tools_recording_type_defaults'),
            'redirect_to' => $page_url,
            'll_uncategorized_desired_recording_types' => ['narration'],
        ];
        $_REQUEST = $_POST;

        $defaults_redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_site_tools_recording_type_action();
        });

        $defaults_query = $this->parseRedirectQuery($defaults_redirect_url);
        $this->assertSame('recording_type_defaults_saved', (string) ($defaults_query['ll_site_tools_notice'] ?? ''));
        $this->assertSame(['narration'], get_option('ll_uncategorized_desired_recording_types', []));
    }

    public function test_page_management_handler_recreates_recording_page_and_redirects_back(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);
        $existing_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Existing Managed Recording Page',
            'post_content' => '[audio_recording_interface]',
        ]);
        update_option('ll_default_recording_page_id', $existing_page_id);
        set_transient('ll_recording_page_creation_attempt', time(), MINUTE_IN_SECONDS);

        $_POST = [
            'll_site_tools_page_key' => 'recording',
            'll_site_tools_page_mode' => 'recreate',
            'll_site_tools_page_nonce' => wp_create_nonce('ll_tools_site_tools_page_recording'),
            'redirect_to' => $page_url,
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_site_tools_page_management_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('page_managed', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('recording', (string) ($query['ll_site_tools_section'] ?? ''));

        $new_page_id = (int) get_option('ll_default_recording_page_id', 0);
        $this->assertGreaterThan(0, $new_page_id);
        $this->assertNotSame($existing_page_id, $new_page_id);
        $this->assertSame('publish', (string) get_post_status($new_page_id));
        $this->assertStringContainsString('[audio_recording_interface]', (string) get_post_field('post_content', $new_page_id));
    }

    public function test_maintenance_handler_flushes_quiz_caches_and_redirects_with_result(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $page_id = $this->createSiteToolsPage();
        $page_url = (string) get_permalink($page_id);

        wp_insert_term('Site Tools Cache Alpha', 'word-category', ['slug' => 'site-tools-cache-alpha']);
        add_option('_transient_ll_wc_words_site_tools_alpha', 'cached');
        add_option('_transient_timeout_ll_wc_words_site_tools_alpha', time() + HOUR_IN_SECONDS);

        $_POST = [
            'll_site_tools_maintenance_action' => 'flush-quiz-caches',
            'll_site_tools_maintenance_nonce' => wp_create_nonce('ll_tools_site_tools_maintenance_flush-quiz-caches'),
            'redirect_to' => $page_url,
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_site_tools_maintenance_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('cache_flushed', (string) ($query['ll_site_tools_notice'] ?? ''));
        $this->assertSame('flush-quiz-caches', (string) ($query['ll_site_tools_section'] ?? ''));
        $this->assertFalse(get_option('_transient_ll_wc_words_site_tools_alpha', false));
        $this->assertFalse(get_option('_transient_timeout_ll_wc_words_site_tools_alpha', false));
    }

    /**
     * @return string[]
     */
    private function trackedOptionNames(): array
    {
        return [
            'll_default_site_tools_page_id',
            'll_enable_browser_language_autoswitch',
            'll_max_options_override',
            'll_flashcard_image_size',
            LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION,
            LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION,
            'll_allow_learner_self_registration',
            'll_show_generated_registration_password',
            'll_tools_send_registration_admin_email',
            'll_deepl_api_key',
            'll_assemblyai_api_key',
            'll_hide_recording_titles',
            'll_tools_recording_notification_email',
            'll_tools_recording_notification_delay_minutes',
            'll_uncategorized_desired_recording_types',
            'll_languages_populated',
            LL_TOOLS_USER_PROGRESS_RETENTION_OPTION,
            'll_update_branch',
            'll_default_recording_page_id',
            'll_default_editor_hub_page_id',
            'll_default_dictionary_page_id',
            'll_tools_force_create_recording_page',
            'll_tools_force_create_editor_hub_page',
            'll_tools_force_create_dictionary_page',
            'll_tools_force_create_site_tools_page',
            'users_can_register',
        ];
    }

    private function createSiteToolsPage(): int
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Site Tools',
            'post_content' => '[ll_site_tools]',
        ]);
        update_option('ll_default_site_tools_page_id', $page_id);

        return (int) $page_id;
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return array_map('strval', $parsed);
    }

    private function captureRedirect(callable $callback): string
    {
        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };

        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }
}
