<?php
declare(strict_types=1);

final class WordsetSettingsCustomUiTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

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
        $this->assertStringContainsString('Categories', $html);
        $this->assertStringContainsString('ll_wordset_tool=categories', $html);
        $this->assertStringContainsString('Advanced', $html);
        $this->assertStringContainsString('ll_wordset_tool=advanced', $html);
        $this->assertStringContainsString('Template', $html);
        $this->assertStringContainsString('ll_wordset_tool=template', $html);
        $this->assertStringContainsString('Recorder Queues', $html);
        $this->assertStringContainsString('Offline App', $html);
        $this->assertStringContainsString('ll_wordset_tool=offline-app', $html);
    }

    public function test_wordset_page_renders_add_category_card_before_category_grid_for_managers(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', null);

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $add_card_position = strpos($html, 'll-wordset-card--add-category');
        $category_card_position = strpos($html, 'data-cat-id="' . (int) $fixture['category_id'] . '"');

        $this->assertIsInt($add_card_position);
        $this->assertIsInt($category_card_position);
        $this->assertLessThan($category_card_position, $add_card_position);
        $this->assertStringContainsString('ll_wordset_tool=categories', $html);
        $this->assertStringContainsString('#ll-wordset-category-create', $html);
    }

    public function test_recorder_queue_tool_renders_visible_and_hidden_items_for_assigned_recorders(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Question', 'question');
        update_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', ['isolation', 'question']);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Queue Recorder',
            'user_email' => 'queue-recorder@example.com',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $hidden_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Hidden Queue Word',
        ]);
        wp_set_post_terms($hidden_word_id, [(int) $fixture['category_id']], 'word-category', false);
        wp_set_post_terms($hidden_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($hidden_word_id, 'word_translation', 'Hidden queue translation');

        $visible_word_id = (int) $fixture['word_id'];
        wp_update_post([
            'ID' => $visible_word_id,
            'post_title' => 'Visible Queue Word',
        ]);
        update_post_meta($visible_word_id, 'word_translation', 'Visible queue translation');
        $visible_attachment_id = $this->createImageAttachment('visible-queue-word.png');
        set_post_thumbnail($visible_word_id, $visible_attachment_id);
        set_post_thumbnail((int) $fixture['template_image_id'], $visible_attachment_id);
        update_post_meta($visible_word_id, ll_tools_recording_prompt_hints_meta_key(), [
            'question' => 'Where is the visible queue word?',
        ]);

        ll_tools_add_hidden_recording_word($recorder_id, [
            'word_id' => $hidden_word_id,
            'title' => 'Hidden Queue Word',
            'category_name' => (string) $fixture['category_name'],
            'category_slug' => (string) $fixture['category_slug'],
        ]);

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Recorder Queues', $html);
        $this->assertStringContainsString('Queue Recorder', $html);
        $this->assertStringContainsString('Visible Queue Word', $html);
        $this->assertStringNotContainsString('Hidden Queue Word', $html);
        $this->assertStringContainsString('Queue by Category', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category-grid', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category-card', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category__preview has-images', $html);
        $this->assertStringContainsString('ll-wordset-preview-item ll-wordset-preview-item--image', $html);
        $this->assertStringContainsString('Hidden (1)', $html);
        $this->assertStringContainsString('Change queue settings', $html);
        $this->assertStringContainsString('data-ll-recorder-queue-autosave="settings"', $html);
        $this->assertStringContainsString('Skipped types', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_allow_new_words"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_auto_process_recordings"', $html);
        $this->assertStringContainsString('ll_recorder_queue_category=' . rawurlencode((string) $fixture['category_slug']), $html);
        $this->assertStringNotContainsString('<details class="ll-wordset-recorder-queue-prompts" open>', $html);

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
            'll_recorder_queue_focus' => (string) $recorder_id,
            'll_recorder_queue_category' => (string) $fixture['category_slug'],
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(add_query_arg(
            [
                'll_recorder_queue_focus' => (string) $recorder_id,
                'll_recorder_queue_category' => (string) $fixture['category_slug'],
            ],
            ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
        ));

        $focused_html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Back to categories', $focused_html);
        $this->assertStringContainsString('Visible Queue Word', $focused_html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_action" value="hide"', $focused_html);
        $this->assertStringContainsString('Recording prompts', $focused_html);
        $this->assertStringContainsString('<details class="ll-wordset-recorder-queue-prompts" open>', $focused_html);
        $this->assertStringContainsString('data-ll-recorder-queue-autosave="prompts"', $focused_html);
        $this->assertStringContainsString('data-ll-recorder-queue-save-status', $focused_html);
        $this->assertStringContainsString('Where is the visible queue word?', $focused_html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_prompts[question]"', $focused_html);
        $this->assertStringContainsString('Edit word', $focused_html);
        $this->assertStringNotContainsString('Change queue settings', $focused_html);

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
            'll_recorder_queue_view' => 'hidden',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(add_query_arg(
            'll_recorder_queue_view',
            'hidden',
            ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
        ));

        $hidden_html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Back to queue', $hidden_html);
        $this->assertStringContainsString('Hidden by Category', $hidden_html);
        $this->assertStringContainsString('Hidden Queue Word', $hidden_html);
        $this->assertStringNotContainsString('Visible Queue Word', $hidden_html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_action" value="unhide"', $hidden_html);
    }

    public function test_recorder_queue_action_hides_and_unhides_words_for_assigned_recorders(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => $wordset_slug,
        ]);

        $word_title = get_the_title((int) $fixture['word_id']);
        $hide_key = ll_tools_build_recording_hide_key((int) $fixture['word_id'], 0, (string) $word_title);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'hide',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_user_id' => (string) $recorder_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_manager_recorder_queue_word_id' => (string) $fixture['word_id'],
            'll_wordset_manager_recorder_queue_title' => (string) $word_title,
            'll_wordset_manager_recorder_queue_category_name' => (string) $fixture['category_name'],
            'll_wordset_manager_recorder_queue_category_slug' => (string) $fixture['category_slug'],
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $hide_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action();
        });

        $hide_query = $this->parseRedirectQuery($hide_redirect);
        $this->assertSame('ok', (string) ($hide_query['ll_wordset_manager_recorder_queue'] ?? ''));
        $this->assertSame('hidden', (string) ($hide_query['ll_wordset_manager_recorder_queue_result'] ?? ''));
        $this->assertCount(1, ll_tools_get_hidden_recording_words_list($recorder_id));

        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'unhide',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_user_id' => (string) $recorder_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_manager_recorder_queue_hide_key' => $hide_key,
            'll_wordset_manager_recorder_queue_word_id' => (string) $fixture['word_id'],
            'll_wordset_manager_recorder_queue_title' => (string) $word_title,
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $unhide_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action();
        });

        $unhide_query = $this->parseRedirectQuery($unhide_redirect);
        $this->assertSame('ok', (string) ($unhide_query['ll_wordset_manager_recorder_queue'] ?? ''));
        $this->assertSame('unhidden', (string) ($unhide_query['ll_wordset_manager_recorder_queue_result'] ?? ''));
        $this->assertSame([], ll_tools_get_hidden_recording_words_list($recorder_id));
    }

    public function test_recorder_queue_action_saves_recorder_queue_settings(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Question', 'question');
        $this->ensureRecordingType('Sentence', 'sentence');

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => $wordset_slug,
            'category' => 'old-category',
            'exclude_recording_types' => 'isolation',
            'allow_new_words' => '0',
            'auto_process_recordings' => '0',
        ]);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'save_settings',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_user_id' => (string) $recorder_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_manager_recorder_queue_include_types' => ['question'],
            'll_wordset_manager_recorder_queue_exclude_types' => ['sentence'],
            'll_wordset_manager_recorder_queue_allow_new_words' => '1',
            'll_wordset_manager_recorder_queue_auto_process_recordings' => '1',
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action();
        });

        $query = $this->parseRedirectQuery($redirect);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_recorder_queue'] ?? ''));
        $this->assertSame('settings', (string) ($query['ll_wordset_manager_recorder_queue_result'] ?? ''));

        $config = get_user_meta($recorder_id, 'll_recording_config', true);
        $this->assertIsArray($config);
        $this->assertSame($wordset_slug, (string) ($config['wordset'] ?? ''));
        $this->assertSame('', (string) ($config['category'] ?? ''));
        $this->assertSame('question', (string) ($config['include_recording_types'] ?? ''));
        $this->assertSame('sentence', (string) ($config['exclude_recording_types'] ?? ''));
        $this->assertSame('1', (string) ($config['allow_new_words'] ?? ''));
        $this->assertSame('1', (string) ($config['auto_process_recordings'] ?? ''));
    }

    public function test_recorder_queue_action_saves_recording_prompts_for_queue_items(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Question', 'question');
        update_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', ['isolation', 'question']);

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => $wordset_slug,
        ]);

        $word_id = (int) $fixture['word_id'];
        $word_title = get_the_title($word_id);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'save_prompts',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_user_id' => (string) $recorder_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_manager_recorder_queue_word_id' => (string) $word_id,
            'll_wordset_manager_recorder_queue_title' => (string) $word_title,
            'll_wordset_manager_recorder_queue_category_name' => (string) $fixture['category_name'],
            'll_wordset_manager_recorder_queue_category_slug' => (string) $fixture['category_slug'],
            'll_wordset_manager_recorder_queue_prompts' => [
                'question' => 'Where is the custom settings word?',
                'isolation' => '',
            ],
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action();
        });

        $query = $this->parseRedirectQuery($redirect);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_recorder_queue'] ?? ''));
        $this->assertSame('prompts', (string) ($query['ll_wordset_manager_recorder_queue_result'] ?? ''));

        $stored = get_post_meta($word_id, ll_tools_recording_prompt_hints_meta_key(), true);
        $this->assertIsArray($stored);
        $this->assertSame('Where is the custom settings word?', (string) ($stored['question'] ?? ''));
        $this->assertArrayNotHasKey('isolation', $stored);

        $queue_items = ll_get_images_needing_audio('', [$wordset_id], '', '', true, $recorder_id);
        $this->assertNotEmpty($queue_items);
        $matched = null;
        foreach ($queue_items as $queue_item) {
            if ((int) ($queue_item['word_id'] ?? 0) === $word_id) {
                $matched = $queue_item;
                break;
            }
        }

        $this->assertIsArray($matched);
        $this->assertSame('Where is the custom settings word?', (string) ($matched['recording_prompts']['question'] ?? ''));
    }

    public function test_recorder_queue_ajax_saves_recording_prompts_without_redirect(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Question', 'question');

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => $wordset_slug,
        ]);

        $word_id = (int) $fixture['word_id'];
        $word_title = get_the_title($word_id);

        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'save_prompts',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_user_id' => (string) $recorder_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_manager_recorder_queue_word_id' => (string) $word_id,
            'll_wordset_manager_recorder_queue_title' => (string) $word_title,
            'll_wordset_manager_recorder_queue_category_name' => (string) $fixture['category_name'],
            'll_wordset_manager_recorder_queue_category_slug' => (string) $fixture['category_slug'],
            'll_wordset_manager_recorder_queue_prompts' => [
                'question' => 'How should the recorder say this?',
                'isolation' => '',
            ],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action_ajax();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('prompts', (string) ($response['data']['result'] ?? ''));
        $this->assertSame($wordset_id, (int) ($response['data']['wordset_id'] ?? 0));
        $this->assertSame($recorder_id, (int) ($response['data']['recorder_user_id'] ?? 0));

        $stored = get_post_meta($word_id, ll_tools_recording_prompt_hints_meta_key(), true);
        $this->assertIsArray($stored);
        $this->assertSame('How should the recorder say this?', (string) ($stored['question'] ?? ''));
        $this->assertArrayNotHasKey('isolation', $stored);
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

    public function test_wordset_settings_renders_manager_access_controls(): void
    {
        $this->ensureWordsetManagerRole();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create([
            'role' => 'wordset_manager',
            'display_name' => 'Primary Manager',
            'user_email' => 'primary-manager@example.org',
        ]);
        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($wordset_id, [$manager_id], $manager_id));

        $_GET = [
            'll_wordset_tool' => 'visibility',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Word Set Managers', $html);
        $this->assertStringContainsString('Primary Manager', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_access_action" value="upgrade"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_access_identifier"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_access_action" value="invite"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_access_email"', $html);
    }

    public function test_wordset_manager_upgrade_action_adds_second_manager_without_replacing_primary(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $primary_manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($wordset_id, [$primary_manager_id], $primary_manager_id));
        wp_set_current_user($primary_manager_id);

        $target_user_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_login' => 'secondmanager',
            'user_email' => 'second-manager@example.org',
        ]);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_access_action' => 'upgrade',
            'll_wordset_manager_access_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_access_identifier' => 'second-manager@example.org',
            'll_wordset_manager_access_nonce' => wp_create_nonce('ll_wordset_manager_access_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'visibility',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_access_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_access'] ?? ''));
        $this->assertSame('upgraded', (string) ($query['ll_wordset_manager_access_result'] ?? ''));
        $this->assertSame($primary_manager_id, (int) get_term_meta($wordset_id, 'manager_user_id', true));

        $manager_ids = ll_tools_get_wordset_manager_user_ids($wordset_id, true);
        $this->assertContains($primary_manager_id, $manager_ids);
        $this->assertContains($target_user_id, $manager_ids);
        $this->assertTrue(ll_tools_user_can_manage_wordset_content($wordset_id, $target_user_id));

        $target_user = get_userdata($target_user_id);
        $this->assertInstanceOf(WP_User::class, $target_user);
        $this->assertContains('wordset_manager', (array) $target_user->roles);
    }

    public function test_wordset_manager_invite_action_sends_email(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $primary_manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($wordset_id, [$primary_manager_id], $primary_manager_id));
        wp_set_current_user($primary_manager_id);

        $captured = [];
        $mail_filter = static function ($pre, $atts) use (&$captured) {
            $captured[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mail_filter, 10, 2);

        try {
            $_GET = [];
            $_POST = [
                'll_wordset_manager_access_action' => 'invite',
                'll_wordset_manager_access_wordset_id' => (string) $wordset_id,
                'll_wordset_manager_access_email' => 'invited-manager@example.org',
                'll_wordset_manager_access_nonce' => wp_create_nonce('ll_wordset_manager_access_' . $wordset_id),
                'll_wordset_page' => $wordset_slug,
                'll_wordset_view' => 'settings',
                'll_wordset_tool' => 'visibility',
            ];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility'));
            set_query_var('ll_wordset_page', $wordset_slug);
            set_query_var('ll_wordset_view', 'settings');

            $redirect_url = $this->captureRedirect(static function (): void {
                ll_tools_wordset_page_handle_manager_access_action();
            });
        } finally {
            remove_filter('pre_wp_mail', $mail_filter, 10);
        }

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_access'] ?? ''));
        $this->assertSame('invited', (string) ($query['ll_wordset_manager_access_result'] ?? ''));
        $this->assertCount(1, $captured);
        $this->assertSame('invited-manager@example.org', (string) ($captured[0]['to'] ?? ''));
        $this->assertStringContainsString((string) $wordset_term->name, (string) ($captured[0]['subject'] ?? ''));
        $this->assertStringContainsString('ll_tools_wordset_manager_invite=', (string) ($captured[0]['message'] ?? ''));
    }

    public function test_wordset_manager_invite_acceptance_adds_manager_assignment(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $primary_manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($wordset_id, [$primary_manager_id], $primary_manager_id));

        $target_user_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'accepted-manager@example.org',
        ]);
        $token = ll_tools_wordset_manager_invite_build_token($wordset_id, [
            'email' => 'accepted-manager@example.org',
            'expires_at' => time() + HOUR_IN_SECONDS,
        ]);
        $this->assertNotSame('', $token);

        $result = ll_tools_wordset_manager_invite_accept_for_user($token, $target_user_id);

        $this->assertIsArray($result);
        $this->assertSame($wordset_id, (int) ($result['wordset_id'] ?? 0));
        $this->assertTrue(ll_tools_user_can_manage_wordset_content($wordset_id, $target_user_id));
        $this->assertContains($target_user_id, ll_tools_get_wordset_manager_user_ids($wordset_id, true));

        $target_user = get_userdata($target_user_id);
        $this->assertInstanceOf(WP_User::class, $target_user);
        $this->assertContains('wordset_manager', (array) $target_user->roles);
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
        update_option('ll_tools_wordset_cache_epoch', 7, false);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'study',
            'll_wordset_hide_lesson_text_for_non_text_quiz' => '1',
            'll_wordset_recorder_text_visibility' => 'hide',
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
        $this->assertSame('hide', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, true));
        $this->assertSame(8, (int) get_option('ll_tools_wordset_cache_epoch', 0));
    }

    public function test_study_settings_action_updates_sign_language_mode_toggle(): void
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
            'll_wordset_sign_language_mode' => '1',
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
        $this->assertSame('1', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, true));
        $this->assertTrue(ll_tools_wordset_uses_sign_language_mode([$wordset_id]));

        $category_id = (int) $fixture['category_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'audio');
        $config = ll_tools_apply_wordset_quiz_presentation_overrides(
            ll_tools_get_category_quiz_config($category_id),
            [$wordset_id]
        );
        $this->assertSame('image', (string) ($config['prompt_type'] ?? ''));
        $this->assertSame('image', (string) ($config['option_type'] ?? ''));
        $this->assertFalse(ll_tools_quiz_requires_audio($config, (string) ($config['option_type'] ?? '')));

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        $config = ll_tools_apply_wordset_quiz_presentation_overrides(
            ll_tools_get_category_quiz_config($category_id),
            [$wordset_id]
        );
        $this->assertSame('image', (string) ($config['prompt_type'] ?? ''));
        $this->assertSame('text_title', (string) ($config['option_type'] ?? ''));
        $this->assertSame('image', (string) ($config['learning_prompt_type'] ?? ''));
        $this->assertSame('image', (string) ($config['learning_option_type'] ?? ''));
        $this->assertTrue((bool) ($config['learning_supported'] ?? false));
        $this->assertFalse(ll_tools_quiz_requires_audio($config, (string) ($config['option_type'] ?? '')));
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

    public function test_transcription_settings_action_rejects_private_host_for_hosted_api(): void
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
            'll_wordset_transcription_provider' => 'hosted_api',
            'll_wordset_local_transcription_target' => 'recording_ipa',
            'll_wordset_local_transcription_endpoint' => 'https://127.0.0.1/transcribe',
            'll_wordset_speaking_game_enabled' => '1',
            'll_wordset_speaking_game_provider' => 'hosted_api',
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
        $this->assertSame('error', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('hosted_api_endpoint', (string) ($query['ll_wordset_manager_settings_error'] ?? ''));
        $this->assertStringContainsString('public host', (string) ($query['ll_wordset_manager_settings_message'] ?? ''));
        $this->assertSame('', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, true));
    }

    public function test_advanced_tool_renders_category_ordering_and_grammar_controls(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [
            'll_wordset_tool' => 'advanced',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'advanced'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('Advanced Settings', $html);
        $this->assertStringContainsString('name="ll_wordset_category_ordering_mode"', $html);
        $this->assertStringContainsString('name="ll_wordset_button_image_attachment_id"', $html);
        $this->assertStringContainsString('name="ll_wordset_keep_original_audio"', $html);
        $this->assertStringContainsString('name="ll_wordset_games_image_size"', $html);
        $this->assertStringContainsString('name="ll_wordset_has_gender"', $html);
        $this->assertStringContainsString('name="ll_wordset_plurality_options"', $html);
    }

    public function test_categories_tool_renders_create_and_edit_forms_for_wordset_manager(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $_GET = [
            'll_wordset_tool' => 'categories',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Categories', $html);
        $this->assertStringContainsString('name="ll_wordset_categories_action" value="create"', $html);
        $this->assertStringContainsString('name="ll_wordset_categories_action" value="update"', $html);
        $this->assertStringContainsString('name="ll_wordset_category_translation"', $html);
        $this->assertStringContainsString('Delete Empty Category', $html);
    }

    public function test_categories_settings_action_creates_updates_and_deletes_owned_categories_for_manager(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'categories',
            'll_wordset_categories_action' => 'create',
            'll_wordset_category_name' => 'New Manager Category',
            'll_wordset_category_translation' => 'Yeni Kategori',
            'll_wordset_category_parent_id' => (string) $fixture['category_id'],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $create_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $create_query = $this->parseRedirectQuery($create_redirect);
        $this->assertSame('categories', (string) ($create_query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($create_query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('Category created.', (string) ($create_query['ll_wordset_manager_settings_message'] ?? ''));

        $created_categories = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'name' => 'New Manager Category',
            'meta_query' => [
                [
                    'key' => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                    'value' => $wordset_id,
                ],
            ],
        ]);
        $this->assertIsArray($created_categories);
        $this->assertCount(1, $created_categories);
        $created_category = $created_categories[0];
        $this->assertInstanceOf(WP_Term::class, $created_category);
        $this->assertSame((int) $fixture['category_id'], (int) $created_category->parent);
        $this->assertSame('Yeni Kategori', (string) get_term_meta((int) $created_category->term_id, 'term_translation', true));

        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'categories',
            'll_wordset_categories_action' => 'update',
            'll_wordset_category_id' => (string) $created_category->term_id,
            'll_wordset_category_name' => 'Updated Manager Category',
            'll_wordset_category_translation' => 'Guncel Kategori',
            'll_wordset_category_parent_id' => '0',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $update_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $update_query = $this->parseRedirectQuery($update_redirect);
        $this->assertSame('categories', (string) ($update_query['ll_wordset_tool'] ?? ''));
        $this->assertSame('Category updated.', (string) ($update_query['ll_wordset_manager_settings_message'] ?? ''));

        $updated_category = get_term((int) $created_category->term_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $updated_category);
        $this->assertSame('Updated Manager Category', (string) $updated_category->name);
        $this->assertSame(0, (int) $updated_category->parent);
        $this->assertSame('Guncel Kategori', (string) get_term_meta((int) $created_category->term_id, 'term_translation', true));

        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'categories',
            'll_wordset_categories_action' => 'delete',
            'll_wordset_category_id' => (string) $created_category->term_id,
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $delete_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $delete_query = $this->parseRedirectQuery($delete_redirect);
        $this->assertSame('categories', (string) ($delete_query['ll_wordset_tool'] ?? ''));
        $this->assertSame('Category deleted.', (string) ($delete_query['ll_wordset_manager_settings_message'] ?? ''));
        $this->assertFalse((bool) term_exists((int) $created_category->term_id, 'word-category'));
    }

    public function test_categories_settings_action_deletes_empty_category_and_linked_vocab_lesson_for_manager(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $empty_category = wp_insert_term('Linked Lesson Empty Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($empty_category);
        $empty_category_id = (int) $empty_category['term_id'];
        ll_tools_set_category_wordset_owner($empty_category_id, $wordset_id, $empty_category_id);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Linked Lesson Empty Category Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $empty_category_id);

        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'categories',
            'll_wordset_categories_action' => 'delete',
            'll_wordset_category_id' => (string) $empty_category_id,
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $delete_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $delete_query = $this->parseRedirectQuery($delete_redirect);
        $this->assertSame('categories', (string) ($delete_query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($delete_query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('Category deleted.', (string) ($delete_query['ll_wordset_manager_settings_message'] ?? ''));
        $this->assertFalse((bool) term_exists($empty_category_id, 'word-category'));
        $this->assertNull(get_post($lesson_id));
    }

    public function test_advanced_settings_action_updates_wordset_meta_and_category_ordering(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $category_id = (int) $fixture['category_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $button_image_attachment_id = $this->createImageAttachment('advanced-wordset-button-image.png');

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'advanced',
            'll_wordset_button_image_attachment_id' => (string) $button_image_attachment_id,
            'll_wordset_games_image_size' => 'large',
            'll_wordset_keep_original_audio' => '1',
            'll_wordset_answer_option_text_font_weight' => '500',
            'll_wordset_answer_option_text_font_size_px' => '36',
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => (string) $category_id,
            'll_wordset_category_manual_order' => (string) $category_id,
            'll_wordset_category_prereqs_compact_mode' => 'json-v1',
            'll_wordset_category_prereqs_compact' => '{}',
            'll_wordset_has_gender' => '1',
            'll_wordset_gender_options' => "Masc\nFem",
            'll_wordset_gender_symbol_masculine' => 'M',
            'll_wordset_gender_symbol_feminine' => 'F',
            'll_wordset_gender_color_masculine' => '#123456',
            'll_wordset_gender_color_feminine' => '#654321',
            'll_wordset_gender_color_other' => '#888888',
            'll_wordset_has_plurality' => '1',
            'll_wordset_plurality_options' => "Singular\nPlural",
            'll_wordset_has_verb_tense' => '1',
            'll_wordset_verb_tense_options' => "Present\nPast",
            'll_wordset_has_verb_mood' => '1',
            'll_wordset_verb_mood_options' => "Indicative\nImperative",
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('advanced', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame((string) $button_image_attachment_id, (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, true));
        $this->assertSame('large', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY, true));
        $this->assertSame('500', (string) get_term_meta($wordset_id, ll_tools_wordset_answer_option_font_weight_primary_meta_key(), true));
        $this->assertSame('36', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_ANSWER_OPTION_FONT_SIZE_META_KEY, true));
        $this->assertSame('manual', (string) get_term_meta($wordset_id, 'll_wordset_category_ordering_mode', true));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_has_gender', true));
        $this->assertSame(['Masc', 'Fem'], array_values((array) get_term_meta($wordset_id, 'll_wordset_gender_options', true)));
        $this->assertSame('M', (string) get_term_meta($wordset_id, ll_tools_wordset_get_gender_symbol_meta_key('masculine'), true));
        $this->assertSame('#123456', strtolower((string) get_term_meta($wordset_id, 'll_wordset_gender_color_masculine', true)));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_has_plurality', true));
        $this->assertSame(['Singular', 'Plural'], array_values((array) get_term_meta($wordset_id, 'll_wordset_plurality_options', true)));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_has_verb_tense', true));
        $this->assertSame(['Present', 'Past'], array_values((array) get_term_meta($wordset_id, 'll_wordset_verb_tense_options', true)));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_has_verb_mood', true));
        $this->assertSame(['Indicative', 'Imperative'], array_values((array) get_term_meta($wordset_id, 'll_wordset_verb_mood_options', true)));
    }

    public function test_wordset_manager_can_save_advanced_settings_for_managed_wordset(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $category_id = (int) $fixture['category_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $button_image_attachment_id = $this->createImageAttachment('manager-wordset-button-image.png');

        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'advanced',
            'll_wordset_button_image_attachment_id' => (string) $button_image_attachment_id,
            'll_wordset_games_image_size' => 'large',
            'll_wordset_answer_option_text_font_weight' => '500',
            'll_wordset_answer_option_text_font_size_px' => '34',
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => (string) $category_id,
            'll_wordset_category_manual_order' => (string) $category_id,
            'll_wordset_has_gender' => '1',
            'll_wordset_gender_options' => "Masc\nFem",
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'advanced'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('advanced', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame((string) $button_image_attachment_id, (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, true));
        $this->assertSame('large', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, true));
        $this->assertSame('500', (string) get_term_meta($wordset_id, ll_tools_wordset_answer_option_font_weight_primary_meta_key(), true));
        $this->assertSame('34', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_ANSWER_OPTION_FONT_SIZE_META_KEY, true));
        $this->assertSame('manual', (string) get_term_meta($wordset_id, 'll_wordset_category_ordering_mode', true));
        $this->assertSame('1', (string) get_term_meta($wordset_id, 'll_wordset_has_gender', true));
        $this->assertSame(['Masc', 'Fem'], array_values((array) get_term_meta($wordset_id, 'll_wordset_gender_options', true)));
    }

    public function test_recorder_tool_renders_upgrade_and_invite_forms(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [
            'll_wordset_tool' => 'recorder',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('name="ll_wordset_manager_recorder_action" value="upgrade"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_identifier"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_action" value="invite"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_email"', $html);
    }

    public function test_recorder_upgrade_action_promotes_existing_user_and_assigns_wordset(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $target_user_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_login' => 'existingrecorder',
            'user_email' => 'existing-recorder@example.org',
        ]);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_action' => 'upgrade',
            'll_wordset_manager_recorder_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_identifier' => 'existing-recorder@example.org',
            'll_wordset_manager_recorder_nonce' => wp_create_nonce('ll_wordset_manager_recorder_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_recorder'] ?? ''));
        $this->assertSame('upgraded', (string) ($query['ll_wordset_manager_recorder_result'] ?? ''));

        $updated_user = get_userdata($target_user_id);
        $this->assertInstanceOf(WP_User::class, $updated_user);
        $this->assertContains('audio_recorder', (array) $updated_user->roles);
        $config = get_user_meta($target_user_id, 'll_recording_config', true);
        $this->assertIsArray($config);
        $this->assertSame((string) $wordset_term->slug, (string) ($config['wordset'] ?? ''));
        $this->assertSame('', (string) ($config['category'] ?? ''));
    }

    public function test_recorder_upgrade_action_accepts_existing_username_identifier(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $target_user_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_login' => 'recorderbylogin',
            'user_email' => 'recorder-by-login@example.org',
        ]);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_action' => 'upgrade',
            'll_wordset_manager_recorder_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_identifier' => 'recorderbylogin',
            'll_wordset_manager_recorder_nonce' => wp_create_nonce('ll_wordset_manager_recorder_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'recorder',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_recorder'] ?? ''));
        $this->assertSame('upgraded', (string) ($query['ll_wordset_manager_recorder_result'] ?? ''));
        $this->assertSame('recorder-by-login@example.org', (string) ($query['ll_wordset_manager_recorder_identifier'] ?? ''));

        $updated_user = get_userdata($target_user_id);
        $this->assertInstanceOf(WP_User::class, $updated_user);
        $this->assertContains('audio_recorder', (array) $updated_user->roles);
        $config = get_user_meta($target_user_id, 'll_recording_config', true);
        $this->assertIsArray($config);
        $this->assertSame((string) $wordset_term->slug, (string) ($config['wordset'] ?? ''));
    }

    public function test_recorder_invite_action_sends_email_and_redirects_with_success_state(): void
    {
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $captured = [];
        $mail_filter = static function ($pre, $atts) use (&$captured) {
            $captured[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mail_filter, 10, 2);

        try {
            $_GET = [];
            $_POST = [
                'll_wordset_manager_recorder_action' => 'invite',
                'll_wordset_manager_recorder_wordset_id' => (string) $wordset_id,
                'll_wordset_manager_recorder_email' => 'new-recorder@example.org',
                'll_wordset_manager_recorder_nonce' => wp_create_nonce('ll_wordset_manager_recorder_' . $wordset_id),
                'll_wordset_page' => $wordset_slug,
                'll_wordset_view' => 'settings',
                'll_wordset_tool' => 'recorder',
            ];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder'));
            set_query_var('ll_wordset_page', $wordset_slug);
            set_query_var('ll_wordset_view', 'settings');

            $redirect_url = $this->captureRedirect(static function (): void {
                ll_tools_wordset_page_handle_manager_recorder_action();
            });
        } finally {
            remove_filter('pre_wp_mail', $mail_filter, 10);
        }

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_recorder'] ?? ''));
        $this->assertSame('invited', (string) ($query['ll_wordset_manager_recorder_result'] ?? ''));
        $this->assertSame('new-recorder@example.org', (string) ($query['ll_wordset_manager_recorder_email'] ?? ''));
        $this->assertCount(1, $captured);
        $this->assertSame('new-recorder@example.org', (string) ($captured[0]['to'] ?? ''));
        $this->assertStringContainsString((string) $wordset_term->name, (string) ($captured[0]['subject'] ?? ''));
        $this->assertStringContainsString('ll_tools_recorder_invite=', (string) ($captured[0]['message'] ?? ''));
    }

    public function test_categories_settings_action_rejects_deleting_non_empty_owned_category_for_manager(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $category_id = (int) $fixture['category_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordset_id),
            'll_wordset_page' => $wordset_slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'categories',
            'll_wordset_categories_action' => 'delete',
            'll_wordset_category_id' => (string) $category_id,
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'categories'));
        set_query_var('ll_wordset_page', $wordset_slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });

        $query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('error', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('category_delete', (string) ($query['ll_wordset_manager_settings_error'] ?? ''));
        $this->assertSame('Remove or move the words in this category first.', (string) ($query['ll_wordset_manager_settings_message'] ?? ''));

        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
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
     * @return array{wordset_id:int,wordset_slug:string,category_id:int,category_name:string,category_slug:string,word_id:int,template_image_id:int}
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
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        $this->ensureRecordingType('Isolation', 'isolation');

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
            'category_slug' => (string) get_term_field('slug', $category_id, 'word-category'),
            'word_id' => (int) $word_id,
            'template_image_id' => $template_image_id,
        ];
    }

    private function ensureWordsetManagerRole(): void
    {
        if (function_exists('ll_create_wordset_manager_role')) {
            ll_create_wordset_manager_role();
        }
        if (function_exists('ll_ensure_wordset_manager_has_view_ll_tools_cap')) {
            ll_ensure_wordset_manager_has_view_ll_tools_cap();
        }
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    private function ensureRecordingType(string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) ($created['term_id'] ?? 0);
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $metadata = function_exists('wp_generate_attachment_metadata')
            ? wp_generate_attachment_metadata($attachment_id, $file_path)
            : [];
        if (is_array($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
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

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
