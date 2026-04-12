<?php
declare(strict_types=1);

final class WordsetSettingsCustomUiTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_settings_hub_renders_language_and_offline_app_cards_for_managers(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('Language', $html);
        $this->assertStringContainsString('ll_wordset_tool=language', $html);
        $this->assertStringContainsString('Template', $html);
        $this->assertStringContainsString('ll_wordset_tool=template', $html);
        $this->assertStringContainsString('Offline App', $html);
        $this->assertStringContainsString('ll_wordset_tool=offline-app', $html);
    }

    public function test_template_tool_renders_create_wordset_form_for_managers(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [
            'll_wordset_tool' => 'template',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'template'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('Create Word Set From Template', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_template_action" value="create"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_template_name"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_template_copy_settings"', $html);
    }

    public function test_language_settings_action_updates_wordset_meta_and_redirects_back_to_language_tool(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $back_url = home_url('/custom-return/');
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'language',
            'll_wordset_back' => $back_url,
            'wordset_language' => 'Turkish',
            'll_wordset_translation_language' => 'English',
            'll_wordset_enable_category_translation' => '1',
            'll_wordset_category_translation_source' => 'translation',
            'll_wordset_word_title_language_role' => 'translation',
            'll_wordset_recording_transcription_mode' => 'transliteration',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('language', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_settings'] ?? ''));

        $this->assertSame('Turkish', (string) get_term_meta($wordset_id, 'll_language', true));
        $this->assertSame('English', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true));
        $this->assertSame('translation', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, true));
        $this->assertSame('translation', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, true));
        $this->assertSame('transliteration', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY, true));
    }

    public function test_study_settings_action_updates_hide_lesson_text_toggle(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'study',
            'll_wordset_hide_lesson_text_for_non_text_quiz' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('study', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', true));
    }

    public function test_transcription_settings_action_updates_speaking_game_access(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'transcription',
            'll_wordset_transcription_provider' => 'local_browser',
            'll_wordset_local_transcription_target' => 'recording_ipa',
            'll_wordset_local_transcription_endpoint' => 'http://127.0.0.1:8765/transcribe',
            'll_wordset_speaking_game_enabled' => '1',
            'll_wordset_speaking_game_provider' => 'audio_matcher',
            'll_wordset_speaking_game_access' => 'managers',
            'll_wordset_speaking_game_target' => 'recording_text',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('transcription', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('managers', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ACCESS_META_KEY, true));
    }

    public function test_offline_app_tool_renders_frontend_export_form_for_current_wordset(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [
            'll_wordset_tool' => 'offline-app',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'offline-app'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('name="ll_wordset_manager_offline_export_action" value="export"', $html);
        $this->assertStringContainsString('name="ll_offline_category_ids[]"', $html);
        $this->assertStringContainsString((string) $fixture['category_name'], $html);
        $this->assertStringContainsString('Export Offline App', $html);
    }

    /**
     * @return array{wordset_id:int,wordset_slug:string,category_id:int,category_name:string,template_image_id:int}
     */
    private function createWordsetFixtureWithCategory(): array
    {
        $wordset = wp_insert_term('Custom Settings Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_slug = (string) get_term_field('slug', $wordset_id, 'wordset');

        update_term_meta($wordset_id, 'll_language', 'Spanish');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, 'English');

        $category_name = 'Custom Settings Category ' . wp_generate_password(4, false);
        $category = wp_insert_term($category_name, 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Custom Settings Word ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Fixture translation');
        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);

        $template_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Custom Settings Template Image ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($template_image_id, [$category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($template_image_id, $wordset_id, $template_image_id);

        return [
            'wordset_id' => $wordset_id,
            'wordset_slug' => $wordset_slug,
            'category_id' => $category_id,
            'category_name' => $category_name,
            'template_image_id' => $template_image_id,
        ];
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
