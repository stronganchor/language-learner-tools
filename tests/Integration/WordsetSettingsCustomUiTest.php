<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class WordsetSettingsCustomUiTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var mixed */
    private $scriptsBackup;

    private bool $hadScriptsBackup = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;
        $this->hadScriptsBackup = array_key_exists('wp_scripts', $GLOBALS);
        $this->scriptsBackup = $GLOBALS['wp_scripts'] ?? null;
        $this->installCleanScriptRegistry();
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        if ($this->hadScriptsBackup) {
            $GLOBALS['wp_scripts'] = $this->scriptsBackup;
        } else {
            unset($GLOBALS['wp_scripts']);
        }
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

    public function test_settings_hub_advanced_status_ignores_an_image_attachment_without_a_resolvable_url(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $attachment_id = $this->createImageAttachment('unresolvable-profile-image.png');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, $attachment_id);

        $this->assertSame($attachment_id, ll_tools_get_wordset_button_image_attachment_id($wordset_id));
        $url_filter = static function ($url, int $candidate_attachment_id) use ($attachment_id) {
            return $candidate_attachment_id === $attachment_id ? false : $url;
        };
        add_filter('wp_get_attachment_url', $url_filter, 10, 2);
        try {
            $this->assertSame(0, (int) (ll_tools_get_wordset_button_image_preview_data($wordset_id, 'medium', false)['attachment_id'] ?? 0));
            $this->assertSame(0, (int) (ll_tools_wordset_page_get_advanced_settings_summary($wordset_id)['button_image_attachment_id'] ?? 0));
            $this->assertSame(0, (int) (ll_tools_wordset_page_get_advanced_settings($wordset_id)['button_image_attachment_id'] ?? 0));

            $_GET = [];
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
            set_query_var('ll_wordset_page', (string) $wordset_term->slug);
            set_query_var('ll_wordset_view', 'settings');

            $html = ll_tools_render_wordset_page_content($wordset_id);
        } finally {
            remove_filter('wp_get_attachment_url', $url_filter, 10);
        }
        $matched = preg_match(
            '/<a[^>]*class="[^"]*ll-wordset-settings-tool-card--advanced[^"]*"[^>]*>.*?<\/a>/s',
            $html,
            $advanced_card
        );
        $this->assertSame(1, $matched);
        $this->assertStringNotContainsString('Profile image', (string) ($advanced_card[0] ?? ''));
    }

    public function test_advanced_summary_and_full_tool_share_the_same_stored_values(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, 'Shared advanced summary');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, 'large');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY, '1');
        update_term_meta($wordset_id, 'll_wordset_has_gender', '1');
        update_term_meta($wordset_id, 'll_wordset_has_plurality', '1');

        $summary = ll_tools_wordset_page_get_advanced_settings_summary($wordset_id);
        $full = ll_tools_wordset_page_get_advanced_settings($wordset_id);

        foreach ($summary as $key => $value) {
            $this->assertArrayHasKey($key, $full);
            $this->assertSame($value, $full[$key], 'Stored Advanced setting drifted for ' . $key);
        }
        $this->assertArrayNotHasKey('button_image_preview_url', $summary);
        $this->assertArrayNotHasKey('button_image_label', $summary);
        $this->assertArrayHasKey('button_image_preview_url', $full);
        $this->assertArrayHasKey('button_image_label', $full);
        $this->assertSame('Shared advanced summary', $summary['profile_blurb'] ?? null);
        $this->assertSame('large', $summary['games_image_size'] ?? null);
        $this->assertTrue((bool) ($summary['keep_original_audio'] ?? false));
    }

    public function test_settings_hub_reuses_durable_managed_category_summary_across_object_cache_flushes(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];

        $goals = ll_tools_default_user_study_goals();
        $goals['ignored_category_ids'] = [$category_id];
        ll_tools_save_user_study_goals($goals, $admin_id);

        $summary_query_count = 0;
        $query_watcher = static function (WP_Term_Query $query) use (&$summary_query_count): void {
            $taxonomies = array_map('strval', (array) ($query->query_vars['taxonomy'] ?? []));
            if (
                in_array('word-category', $taxonomies, true)
                && (string) ($query->query_vars['fields'] ?? '') === 'count'
                && !empty($query->query_vars['meta_query'])
            ) {
                $summary_query_count++;
            }
        };

        add_action('pre_get_terms', $query_watcher);
        try {
            $first = ll_tools_wordset_page_get_managed_category_summary($wordset_id);
            $first_query_count = $summary_query_count;

            $this->assertSame(1, (int) ($first['total'] ?? -1));
            $this->assertSame(0, (int) ($first['translated'] ?? -1));
            $this->assertSame(1, (int) ($first['hidden'] ?? -1));
            $this->assertGreaterThanOrEqual(3, $first_query_count);

            // The default WordPress object cache is request-local. Flushing it
            // here verifies that the durable transient, rather than wp_cache,
            // prevents the term-meta count joins on the next request.
            wp_cache_flush();
            $second = ll_tools_wordset_page_get_managed_category_summary($wordset_id);

            $this->assertSame($first, $second);
            $this->assertSame($first_query_count, $summary_query_count);

            $goals['ignored_category_ids'] = [];
            ll_tools_save_user_study_goals($goals, $admin_id);
            wp_cache_flush();
            $before_goal_refresh = $summary_query_count;
            $visible_summary = ll_tools_wordset_page_get_managed_category_summary($wordset_id);

            $this->assertSame(0, (int) ($visible_summary['hidden'] ?? -1));
            $this->assertGreaterThan($before_goal_refresh, $summary_query_count);

            update_term_meta($category_id, 'term_translation', 'Cached translated category');
            wp_cache_flush();
            $before_epoch_refresh = $summary_query_count;
            $translated_summary = ll_tools_wordset_page_get_managed_category_summary($wordset_id);

            $this->assertSame(1, (int) ($translated_summary['translated'] ?? -1));
            $this->assertGreaterThan($before_epoch_refresh, $summary_query_count);
        } finally {
            remove_action('pre_get_terms', $query_watcher);
        }
    }

    public function test_settings_hub_skips_audio_recorder_discovery_while_recorder_tool_keeps_it(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Hub Discovery Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $user_query_count = 0;
        $user_watcher = static function () use (&$user_query_count): void {
            $user_query_count++;
        };
        add_action('pre_get_users', $user_watcher);
        try {
            $this->setWordsetSettingsRoute($wordset_term);
            $hub_html = ll_tools_render_wordset_page_content($wordset_id);

            $this->assertSame(0, $user_query_count);
            $this->assertStringNotContainsString('Hub Discovery Recorder', $hub_html);
            $this->assertMatchesRegularExpression(
                '/<a[^>]*class="[^"]*ll-wordset-settings-tool-card--recorder-queues[^"]*"[^>]*>/',
                $hub_html
            );

            $this->setWordsetSettingsRoute($wordset_term, 'recorder');
            $recorder_html = ll_tools_render_wordset_page_content($wordset_id);

            $this->assertGreaterThan(0, $user_query_count);
            $this->assertStringContainsString('Hub Discovery Recorder', $recorder_html);
        } finally {
            remove_action('pre_get_users', $user_watcher);
        }
    }

    public function test_settings_hub_skips_full_category_catalog_and_unbounded_card_counts(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $full_category_catalog_calls = 0;
        $alternate_flashcard_catalog_calls = 0;
        $answer_preview_sampling_calls = 0;
        $unbounded_card_queries = [];
        $catalog_filter = static function ($enabled) use (&$full_category_catalog_calls) {
            $full_category_catalog_calls++;
            return $enabled;
        };
        $post_watcher = static function (WP_Query $query) use (&$unbounded_card_queries): void {
            $post_types = array_map('strval', (array) $query->get('post_type'));
            if (
                (int) $query->get('posts_per_page') === -1
                && !empty(array_intersect(['words', 'word_images'], $post_types))
            ) {
                $unbounded_card_queries[] = $query->query_vars;
            }
        };
        $flashcard_catalog_filter = static function ($ttl) use (&$alternate_flashcard_catalog_calls) {
            $alternate_flashcard_catalog_calls++;
            return $ttl;
        };
        $answer_preview_filter = static function ($limit) use (&$answer_preview_sampling_calls) {
            $answer_preview_sampling_calls++;
            return $limit;
        };

        add_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);
        add_filter('ll_tools_flashcard_categories_cache_ttl', $flashcard_catalog_filter);
        add_filter('ll_tools_wordset_preview_max_candidate_words', $answer_preview_filter);
        add_action('pre_get_posts', $post_watcher);
        try {
            $html = ll_tools_render_wordset_page_content($wordset_id);
        } finally {
            remove_action('pre_get_posts', $post_watcher);
            remove_filter('ll_tools_wordset_preview_max_candidate_words', $answer_preview_filter);
            remove_filter('ll_tools_flashcard_categories_cache_ttl', $flashcard_catalog_filter);
            remove_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);
        }

        $this->assertSame(0, $full_category_catalog_calls, 'The settings hub must not build the learner category catalog.');
        $this->assertSame(0, $alternate_flashcard_catalog_calls, 'The settings hub must not build category-ordering rows through the flashcard catalog.');
        $this->assertSame(0, $answer_preview_sampling_calls, 'The settings hub must not sample answer-option preview words.');
        $this->assertSame([], $unbounded_card_queries, 'Settings card labels must not hydrate every word or image.');
        $this->assertStringContainsString('ll_wordset_tool=editor', $html);
        $this->assertStringContainsString('ll_wordset_tool=template', $html);
        $this->assertStringContainsString('ll_wordset_tool=offline-app', $html);
    }

    public function test_settings_hub_skips_the_full_wordset_javascript_runtime(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $this->setWordsetSettingsRoute($wordset_term);
        $this->resetWordsetSettingsAssetHandles();

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('ll-wordset-settings-page--hub', $html);
        $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'registered'));
        $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'enqueued'));
        $this->assertFalse(wp_script_is('ll-tools-locale-sort', 'registered'));
        $this->assertFalse(wp_script_is('ll-tools-locale-sort', 'enqueued'));
        $this->assertSame('', $this->getWordsetRuntimeLocalization());
    }

    public function test_plain_settings_tools_skip_the_full_wordset_javascript_runtime(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $plain_tools = [
            'language',
            'visibility',
            'categories',
            'import',
            'template',
            'recorder',
            'transcription',
            'offline-app',
            'image-upload',
            'audio-upload',
        ];

        foreach ($plain_tools as $tool) {
            $this->resetWordsetSettingsAssetHandles();
            $this->setWordsetSettingsRoute($wordset_term, $tool);

            ll_tools_wordset_page_enqueue_scripts();

            $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'registered'), $tool);
            $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'enqueued'), $tool);
            $this->assertFalse(wp_script_is('ll-tools-locale-sort', 'registered'), $tool);
            $this->assertFalse(wp_script_is('ll-tools-locale-sort', 'enqueued'), $tool);
        }
    }

    public function test_plain_settings_tool_does_not_localize_the_skipped_runtime(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $this->resetWordsetSettingsAssetHandles();
        $this->setWordsetSettingsRoute($wordset_term, 'visibility');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('ll-wordset-settings-page--tool', $html);
        $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'registered'));
        $this->assertFalse(wp_script_is('ll-tools-locale-sort', 'registered'));
        $this->assertSame('', $this->getWordsetRuntimeLocalization());
    }

    public function test_advanced_settings_keeps_only_its_dedicated_runtime_assets(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $this->resetWordsetSettingsAssetHandles();
        $this->setWordsetSettingsRoute($wordset_term, 'advanced');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('ll-wordset-settings-page--tool', $html);
        $this->assertFalse(wp_script_is('ll-wordset-pages-js', 'registered'));
        $this->assertTrue(wp_script_is('ll-tools-locale-sort', 'enqueued'));
        $this->assertTrue(wp_script_is('manage-wordsets-script', 'enqueued'));
        $this->assertTrue(wp_script_is('ll-wordset-settings-media-js', 'enqueued'));
        $this->assertContains(
            'll-tools-locale-sort',
            (array) (wp_scripts()->registered['manage-wordsets-script']->deps ?? [])
        );
        $this->assertSame('', $this->getWordsetRuntimeLocalization());
    }

    public function test_interactive_settings_tools_keep_the_full_wordset_javascript_runtime(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        foreach (['study', 'editor', 'recorder-queues'] as $tool) {
            $this->resetWordsetSettingsAssetHandles();
            $this->setWordsetSettingsRoute($wordset_term, $tool);

            ll_tools_wordset_page_enqueue_scripts();

            $this->assertTrue(wp_script_is('ll-wordset-pages-js', 'enqueued'), $tool);
            $this->assertTrue(wp_script_is('ll-tools-locale-sort', 'enqueued'), $tool);
        }
    }

    public function test_interactive_settings_tool_localizes_the_retained_runtime(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $this->resetWordsetSettingsAssetHandles();
        $this->setWordsetSettingsRoute($wordset_term, 'editor');

        ll_tools_render_wordset_page_content($wordset_id);

        $this->assertTrue(wp_script_is('ll-wordset-pages-js', 'enqueued'));
        $this->assertStringContainsString('var llWordsetPageData = ', $this->getWordsetRuntimeLocalization());
    }

    #[DataProvider('wordsetViewConfettiProvider')]
    public function test_confetti_is_limited_to_main_and_progress_views(string $view, string $settings_tool, bool $expected): void
    {
        $this->assertSame($expected, ll_tools_wordset_page_should_enqueue_confetti($view), $settings_tool);
    }

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function wordsetViewConfettiProvider(): array
    {
        return [
            'main' => ['', '', true],
            'progress' => ['progress', '', true],
            'settings tool' => ['settings', 'visibility', false],
        ];
    }

    public function test_settings_hub_does_not_refresh_an_empty_recommendation_queue_without_a_category_catalog(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $queue_meta = [
            (string) $wordset_id => [],
        ];
        $dismissed_meta = [
            (string) $wordset_id => ['practice:category:' . (int) $fixture['category_id']],
        ];
        update_user_meta($admin_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $queue_meta);
        update_user_meta($admin_id, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, $dismissed_meta);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $recommendation_meta_updates = [];
        $meta_watcher = static function ($check, $object_id, $meta_key) use ($admin_id, &$recommendation_meta_updates) {
            if (
                (int) $object_id === $admin_id
                && in_array((string) $meta_key, [
                    LL_TOOLS_USER_RECOMMENDATION_QUEUE_META,
                    LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META,
                ], true)
            ) {
                $recommendation_meta_updates[] = (string) $meta_key;
            }
            return $check;
        };
        add_filter('update_user_metadata', $meta_watcher, 10, 3);
        try {
            ll_tools_render_wordset_page_content($wordset_id);
        } finally {
            remove_filter('update_user_metadata', $meta_watcher, 10);
        }

        $this->assertSame([], $recommendation_meta_updates);
        $this->assertSame($queue_meta, get_user_meta($admin_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
        $this->assertSame($dismissed_meta, get_user_meta($admin_id, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, true));
    }

    public function test_non_study_settings_tools_skip_the_learner_category_catalog(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $full_category_catalog_calls = 0;
        $catalog_filter = static function ($enabled) use (&$full_category_catalog_calls) {
            $full_category_catalog_calls++;
            return $enabled;
        };
        add_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);

        try {
            $rendered = [];
            foreach (['advanced', 'editor', 'import'] as $tool) {
                $_GET = ['ll_wordset_tool' => $tool];
                $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, $tool));
                set_query_var('ll_wordset_page', (string) $wordset_term->slug);
                set_query_var('ll_wordset_view', 'settings');
                $rendered[$tool] = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);
        }

        $this->assertSame(0, $full_category_catalog_calls);
        $this->assertStringContainsString('Save Advanced Settings', $rendered['advanced']);
        $this->assertStringContainsString('ll-wordset-editor', $rendered['editor']);
        $this->assertStringContainsString((string) $fixture['category_name'], $rendered['import']);
    }

    public function test_unauthorized_editor_request_cannot_trigger_the_expensive_category_catalog(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = ['ll_wordset_tool' => 'editor'];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $full_category_catalog_calls = 0;
        $catalog_filter = static function ($enabled) use (&$full_category_catalog_calls) {
            $full_category_catalog_calls++;
            return $enabled;
        };
        add_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);
        try {
            $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);
        } finally {
            remove_filter('ll_tools_wordset_page_user_categories_cache_enabled', $catalog_filter);
        }

        $this->assertSame(0, $full_category_catalog_calls);
        $this->assertStringNotContainsString('ll-wordset-editor', $html);
    }

    public function test_visibility_tool_skips_unrelated_settings_prework(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Unrelated Recorder',
        ]);

        $_GET = [
            'll_wordset_tool' => 'visibility',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'visibility'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $user_queries = 0;
        $template_image_queries = 0;
        $user_watcher = static function () use (&$user_queries): void {
            $user_queries++;
        };
        $post_watcher = static function (WP_Query $query) use (&$template_image_queries): void {
            if (
                $query->get('post_type') === 'word_images'
                && (int) $query->get('posts_per_page') === -1
                && $query->get('fields') === 'ids'
            ) {
                $template_image_queries++;
            }
        };

        add_action('pre_get_users', $user_watcher);
        add_action('pre_get_posts', $post_watcher);
        try {
            $html = ll_tools_render_wordset_page_content($wordset_id);
        } finally {
            remove_action('pre_get_users', $user_watcher);
            remove_action('pre_get_posts', $post_watcher);
        }

        $this->assertStringContainsString('Visibility', $html);
        $this->assertSame(0, $user_queries);
        $this->assertSame(0, $template_image_queries);
        $this->assertStringNotContainsString('Unrelated Recorder', $html);
        $this->assertStringNotContainsString('Export Offline App', $html);
    }

    public function test_settings_hub_skips_game_availability_scan_while_main_view_evaluates_it(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $game_category_queries = [];
        $query_watcher = static function (string $query) use (&$game_category_queries): string {
            if (
                stripos($query, 'SELECT DISTINCT category_tt.term_id') !== false
                && stripos($query, 'wordset_rel') !== false
                && stripos($query, 'category_rel') !== false
            ) {
                $game_category_queries[] = $query;
            }

            return $query;
        };

        add_filter('query', $query_watcher);
        try {
            $_GET = [];
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
            set_query_var('ll_wordset_page', (string) $wordset_term->slug);
            set_query_var('ll_wordset_view', 'settings');
            ll_tools_render_wordset_page_content($wordset_id);

            $this->assertSame([], $game_category_queries, 'The settings hub must not scan the wordset just to decide whether the main-page Games link is visible.');

            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term));
            set_query_var('ll_wordset_view', null);
            ll_tools_render_wordset_page_content($wordset_id);
        } finally {
            remove_filter('query', $query_watcher);
        }

        $this->assertNotEmpty($game_category_queries, 'The main wordset view must still evaluate game availability for its Games link.');
    }

    public function test_game_runtime_localization_is_built_only_for_the_games_view(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');
        ll_tools_render_wordset_page_content($wordset_id);

        $scripts = wp_scripts();
        $this->assertInstanceOf(WP_Scripts::class, $scripts);
        $settings_data = isset($scripts->registered['ll-wordset-pages-js'])
            ? (string) ($scripts->registered['ll-wordset-pages-js']->extra['data'] ?? '')
            : '';
        $this->assertStringNotContainsString('ll_wordset_games_bootstrap', $settings_data);
        $this->assertStringNotContainsString('gamesLoading', $settings_data);

        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'games'));
        set_query_var('ll_wordset_view', 'games');
        ll_tools_render_wordset_page_content($wordset_id);

        $games_data = (string) ($scripts->registered['ll-wordset-pages-js']->extra['data'] ?? '');
        $this->assertStringContainsString('ll_wordset_games_bootstrap', $games_data);
        $this->assertStringContainsString('gamesLoading', $games_data);
    }

    public function test_settings_hub_does_not_persist_main_view_category_search_payload(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $category_search_payload_queries = [];
        $query_watcher = static function (string $query) use (&$category_search_payload_queries): string {
            if (stripos($query, 'category_search_payload') !== false) {
                $category_search_payload_queries[] = $query;
            }

            return $query;
        };

        add_filter('query', $query_watcher);
        try {
            $_GET = [];
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
            set_query_var('ll_wordset_page', (string) $wordset_term->slug);
            set_query_var('ll_wordset_view', 'settings');
            ll_tools_render_wordset_page_content($wordset_id);

            $this->assertSame([], $category_search_payload_queries, 'The settings hub must not create or inspect the main-view category-search payload.');
        } finally {
            remove_filter('query', $query_watcher);
        }
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

    public function test_recorder_queue_overview_selects_one_recorder_and_exposes_every_assigned_recorder(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorders = [];
        foreach (['Alpha Stream Recorder', 'Beta Stream Recorder', 'Gamma Stream Recorder'] as $display_name) {
            $recorder_id = self::factory()->user->create([
                'role' => 'audio_recorder',
                'display_name' => $display_name,
            ]);
            update_user_meta($recorder_id, 'll_recording_config', [
                'wordset' => (string) $wordset_term->slug,
            ]);
            $recorders[] = [
                'id' => (int) $recorder_id,
                'name' => $display_name,
            ];
        }

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(
            ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
        );
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $fallback_html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('data-ll-recorder-queue-switcher', $fallback_html);
        $this->assertSame(1, substr_count($fallback_html, 'class="ll-wordset-settings-card ll-wordset-recorder-queue-card"'));
        $this->assertStringContainsString('id="ll-recorder-queue-' . $recorders[0]['id'] . '"', $fallback_html);
        $this->assertStringNotContainsString('id="ll-recorder-queue-' . $recorders[1]['id'] . '"', $fallback_html);
        $this->assertStringNotContainsString('id="ll-recorder-queue-' . $recorders[2]['id'] . '"', $fallback_html);
        foreach ($recorders as $recorder) {
            $this->assertStringContainsString('value="' . $recorder['id'] . '"', $fallback_html);
            $this->assertStringContainsString($recorder['name'], $fallback_html);
        }
        $this->assertMatchesRegularExpression(
            '/<option(?=[^>]*value="' . preg_quote((string) $recorders[0]['id'], '/') . '")(?=[^>]*selected=[\'\"]selected[\'\"])[^>]*>/',
            $fallback_html
        );

        $_GET['ll_recorder_queue_focus'] = (string) $recorders[1]['id'];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(add_query_arg(
            'll_recorder_queue_focus',
            (string) $recorders[1]['id'],
            ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
        ));

        $requested_html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertSame(1, substr_count($requested_html, 'class="ll-wordset-settings-card ll-wordset-recorder-queue-card"'));
        $this->assertStringContainsString('id="ll-recorder-queue-' . $recorders[1]['id'] . '"', $requested_html);
        $this->assertStringNotContainsString('id="ll-recorder-queue-' . $recorders[0]['id'] . '"', $requested_html);
        $this->assertStringNotContainsString('id="ll-recorder-queue-' . $recorders[2]['id'] . '"', $requested_html);
        foreach ($recorders as $recorder) {
            $this->assertStringContainsString('value="' . $recorder['id'] . '"', $requested_html);
        }
        $this->assertMatchesRegularExpression(
            '/<option(?=[^>]*value="' . preg_quote((string) $recorders[1]['id'], '/') . '")(?=[^>]*selected=[\'\"]selected[\'\"])[^>]*>/',
            $requested_html
        );
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
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, 'hide');

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

        $completed_category = wp_insert_term('Completed Queue Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($completed_category));
        $this->assertIsArray($completed_category);
        $completed_category_id = (int) $completed_category['term_id'];
        update_term_meta($completed_category_id, 'll_desired_recording_types', ['isolation']);
        ll_tools_set_category_wordset_owner($completed_category_id, $wordset_id, $completed_category_id);
        $completed_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Completed Queue Category Word',
        ]);
        wp_set_post_terms($completed_word_id, [$completed_category_id], 'word-category', false);
        wp_set_post_terms($completed_word_id, [$wordset_id], 'wordset', false);
        $this->createAudioRecordingForWord((int) $completed_word_id, 'isolation', $recorder_id);

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

        $summary_batch = ll_tools_wordset_page_build_recorder_queue_summary_batch(
            $wordset_id,
            $wordset_term,
            $recorder_id,
            [(string) $fixture['category_slug']]
        );
        $this->assertContains((string) $fixture['category_slug'], (array) ($summary_batch['resolvedSlugs'] ?? []));
        $summary_card_html = implode('', array_map(static function (array $card): string {
            return (string) ($card['html'] ?? '');
        }, (array) ($summary_batch['cards'] ?? [])));
        $this->assertStringContainsString('ll-wordset-preview-item ll-wordset-preview-item--image', $summary_card_html);
        $this->assertStringContainsString('visible-queue-word', $summary_card_html);
        $this->assertStringContainsString(
            'll_recorder_queue_category=' . rawurlencode((string) $fixture['category_slug']),
            $summary_card_html
        );

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Recorder Queues', $html);
        $this->assertStringContainsString('Queue Recorder', $html);
        $this->assertStringNotContainsString('Hidden Queue Word', $html);
        $this->assertStringContainsString('Queue by Category', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category-grid', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category-card', $html);
        $this->assertStringContainsString('ll-wordset-recorder-queue-category__preview', $html);
        $this->assertStringNotContainsString('Completed Queue Category', $html);
        $this->assertStringNotContainsString('ll-wordset-recorder-queue-item__title">Visible Queue Word', $html);
        $this->assertStringContainsString('Hidden (1)', $html);
        $this->assertStringContainsString('Change queue settings', $html);
        $this->assertStringContainsString('data-ll-recorder-queue-autosave="settings"', $html);
        $this->assertStringContainsString('Recorder text visibility', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_action" value="save_wordset_settings"', $html);
        $this->assertStringContainsString('name="ll_wordset_recorder_text_visibility"', $html);
        $this->assertStringContainsString('Current effective recorder state: hidden.', $html);
        $this->assertStringContainsString('Skipped types', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_allow_new_words"', $html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_auto_process_recordings"', $html);
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

    public function test_recorder_queue_tool_renders_image_only_categories_as_recordable(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Isolation', 'isolation');

        $category = wp_insert_term('Image Only Queue Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $category_slug = (string) get_term_field('slug', $category_id, 'word-category');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Image Only Queue Picture',
        ]);
        wp_set_post_terms($image_id, [$category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);
        set_post_thumbnail($image_id, $this->createImageAttachment('image-only-queue-picture.png'));

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Image Queue Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $summary_batch = ll_tools_wordset_page_build_recorder_queue_summary_batch(
            $wordset_id,
            $wordset_term,
            $recorder_id,
            [$category_slug]
        );
        $this->assertContains($category_slug, (array) ($summary_batch['resolvedSlugs'] ?? []));
        $summary_card_html = implode('', array_map(static function (array $card): string {
            return (string) ($card['html'] ?? '');
        }, (array) ($summary_batch['cards'] ?? [])));
        $this->assertStringContainsString('Image Only Queue Category', $summary_card_html);
        $this->assertStringContainsString('image-only-queue-picture', $summary_card_html);

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('data-recorder-queue-category="' . esc_attr($category_slug) . '"', $html);

        $_GET = [
            'll_wordset_tool' => 'recorder-queues',
            'll_recorder_queue_focus' => (string) $recorder_id,
            'll_recorder_queue_category' => $category_slug,
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(add_query_arg(
            [
                'll_recorder_queue_focus' => (string) $recorder_id,
                'll_recorder_queue_category' => $category_slug,
            ],
            ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
        ));

        $focused_html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('Back to categories', $focused_html);
        $this->assertStringContainsString('Image Only Queue Picture', $focused_html);
        $this->assertStringContainsString('image-only-queue-picture', $focused_html);
        $this->assertStringContainsString('name="ll_wordset_manager_recorder_queue_action" value="hide"', $focused_html);
        $this->assertStringNotContainsString('That category is no longer in this recorder queue.', $focused_html);
    }

    public function test_recorder_queue_category_view_pages_visible_items(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', ['isolation']);

        wp_update_post([
            'ID' => (int) $fixture['word_id'],
            'post_title' => 'Alpha Queue Page Word',
        ]);

        $completed_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Aardvark Completed Queue Page Word',
        ]);
        wp_set_post_terms($completed_word_id, [(int) $fixture['category_id']], 'word-category', false);
        wp_set_post_terms($completed_word_id, [$wordset_id], 'wordset', false);
        $this->createAudioRecordingForWord((int) $completed_word_id, 'isolation', $admin_id);

        $beta_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Beta Queue Page Word',
        ]);
        wp_set_post_terms($beta_word_id, [(int) $fixture['category_id']], 'word-category', false);
        wp_set_post_terms($beta_word_id, [$wordset_id], 'wordset', false);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Paged Queue Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $hidden_word_title = 'Aardwolf Hidden Queue Page Word';
        $hidden_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $hidden_word_title,
        ]);
        wp_set_post_terms($hidden_word_id, [(int) $fixture['category_id']], 'word-category', false);
        wp_set_post_terms($hidden_word_id, [$wordset_id], 'wordset', false);
        ll_tools_add_hidden_recording_word($recorder_id, [
            'key' => ll_tools_build_recording_hide_key((int) $hidden_word_id, 0, $hidden_word_title),
            'word_id' => (int) $hidden_word_id,
            'title' => $hidden_word_title,
            'category_name' => (string) $fixture['category_name'],
            'category_slug' => (string) $fixture['category_slug'],
        ]);
        $this->assertCount(1, ll_tools_get_hidden_recording_words_list($recorder_id));

        $page_size_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_wordset_recorder_queue_page_size', $page_size_filter);

        try {
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
            set_query_var('ll_wordset_page', (string) $wordset_term->slug);
            set_query_var('ll_wordset_view', 'settings');

            $first_page_html = ll_tools_render_wordset_page_content($wordset_id);

            $this->assertStringNotContainsString('Aardvark Completed Queue Page Word', $first_page_html);
            $this->assertStringNotContainsString($hidden_word_title, $first_page_html);
            $this->assertStringContainsString('Alpha Queue Page Word', $first_page_html);
            $this->assertStringNotContainsString('Beta Queue Page Word', $first_page_html);
            $this->assertStringContainsString('Page 1', $first_page_html);
            $this->assertStringContainsString('ll_recorder_queue_page=2', $first_page_html);

            $_GET['ll_recorder_queue_page'] = '2';
            $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(add_query_arg(
                [
                    'll_recorder_queue_focus' => (string) $recorder_id,
                    'll_recorder_queue_category' => (string) $fixture['category_slug'],
                    'll_recorder_queue_page' => '2',
                ],
                ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues')
            ));

            $second_page_html = ll_tools_render_wordset_page_content($wordset_id);

            $this->assertStringNotContainsString('Alpha Queue Page Word', $second_page_html);
            $this->assertStringContainsString('Beta Queue Page Word', $second_page_html);
            $this->assertStringContainsString('Page 2', $second_page_html);
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_page_size', $page_size_filter);
        }
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

    public function test_recorder_queue_action_saves_wordset_recorder_text_visibility(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        update_option('ll_tools_wordset_cache_epoch', 11, false);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_recorder_queue_action' => 'save_wordset_settings',
            'll_wordset_manager_recorder_queue_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_recorder_queue_nonce' => wp_create_nonce('ll_wordset_manager_recorder_queue_' . $wordset_id),
            'll_wordset_recorder_text_visibility' => 'hide',
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
        $this->assertSame('wordset-settings', (string) ($query['ll_wordset_manager_recorder_queue_result'] ?? ''));
        $this->assertSame('hide', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, true));
        $this->assertSame(12, (int) get_option('ll_tools_wordset_cache_epoch', 0));

        $_POST['ll_wordset_recorder_text_visibility'] = 'inherit';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_recorder_queue_action();
        });

        $this->assertSame('', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, true));
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
        $dictionary_groups_text = implode("\n", [
            'i ' . "\u{0131}" . ' ' . "\u{00EE}" . ' y',
            'u ' . "\u{00FC}" . ' ' . "\u{00FB}" . ' w',
            "\u{011F}" . ' x',
            'k q g',
        ]);

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
            'll_wordset_dictionary_close_match_groups' => $dictionary_groups_text,
            'll_wordset_dictionary_optional_apostrophes' => '1',
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
        $this->assertSame(
            ll_tools_sanitize_wordset_dictionary_close_match_groups($dictionary_groups_text),
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_DICTIONARY_CLOSE_MATCH_GROUPS_META_KEY, true)
        );
        $this->assertSame('1', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_DICTIONARY_OPTIONAL_APOSTROPHES_META_KEY, true));
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
        $this->assertStringContainsString('name="ll_wordset_profile_blurb"', $html);
        $this->assertStringContainsString('name="ll_wordset_keep_original_audio"', $html);
        $this->assertStringContainsString('name="ll_wordset_games_image_size"', $html);
        $this->assertStringContainsString('name="ll_wordset_has_gender"', $html);
        $this->assertStringContainsString('name="ll_wordset_plurality_options"', $html);
    }

    public function test_wordset_taxonomy_form_selects_saves_and_clears_orthography_profile(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        update_term_meta($wordset_id, ll_tools_ipa_orthography_profile_meta_key(), 'zazaki_genc_palu');

        ob_start();
        ll_add_wordset_language_field($wordset);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="ll_wordset_ipa_orthography_profile"', $html);
        $this->assertMatchesRegularExpression('/value="zazaki_genc_palu"\s+selected/', $html);
        $this->assertStringContainsString('None (generic language rules)', $html);

        $_POST = [
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            'll_wordset_ipa_orthography_profile' => '',
        ];
        ll_save_wordset_language($wordset_id);
        $this->assertSame('', (string) get_term_meta($wordset_id, ll_tools_ipa_orthography_profile_meta_key(), true));

        $_POST = [
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            'll_wordset_ipa_orthography_profile' => 'zazaki_genc_palu',
        ];
        ll_save_wordset_language($wordset_id);
        $this->assertSame('zazaki_genc_palu', (string) get_term_meta($wordset_id, ll_tools_ipa_orthography_profile_meta_key(), true));
    }

    public function test_wordset_page_renders_profile_image_language_code_and_blurb(): void
    {
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $attachment_id = $this->createImageAttachment('profile-wordset-page.png');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, $attachment_id);
        update_term_meta($wordset_id, 'll_language', 'he');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, 'Designed to complement Aleph with Beth videos.');

        $_GET = [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset_term));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        $html = ll_tools_render_wordset_page_content($wordset_id);

        $this->assertStringContainsString('ll-wordset-profile--has-image', $html);
        $this->assertStringContainsString('ll-wordset-profile__image', $html);
        $this->assertStringContainsString('Language code', $html);
        $this->assertStringContainsString('he', $html);
        $this->assertStringContainsString('Designed to complement Aleph with Beth videos.', $html);
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
        $this->assertStringContainsString('Delete Category', $html);
    }

    public function test_managed_category_rows_batch_content_summaries_for_delete_state(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];

        $image_category = wp_insert_term('Managed Summary Image Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($image_category);
        $image_category_id = (int) $image_category['term_id'];
        ll_tools_set_category_wordset_owner($image_category_id, $wordset_id, $image_category_id);

        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Managed Summary Image ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($image_id, [$image_category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner((int) $image_id, $wordset_id, (int) $image_id);

        $prompt_category = wp_insert_term('Managed Summary Prompt Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        ll_tools_set_category_wordset_owner($prompt_category_id, $wordset_id, $prompt_category_id);

        $prompt_card_id = self::factory()->post->create([
            'post_type' => defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') ? LL_TOOLS_PROMPT_CARD_POST_TYPE : 'll_prompt_card',
            'post_status' => 'publish',
            'post_title' => 'Managed Summary Prompt ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($prompt_card_id, [$prompt_category_id], 'word-category', false);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);

        $captured_sql = [];
        $sql_watcher = static function (string $query) use (&$captured_sql): string {
            if (
                strpos($query, 'SELECT DISTINCT p.ID, p.post_status') !== false
                || strpos($query, 'SELECT DISTINCT p.post_parent, p.post_status') !== false
                || strpos($query, 'COUNT(DISTINCT posts.ID) AS word_count') !== false
                || strpos($query, 'COUNT(DISTINCT posts.ID) AS image_count') !== false
                || strpos($query, 'COUNT(DISTINCT posts.ID) AS prompt_card_count') !== false
            ) {
                $captured_sql[] = $query;
            }
            return $query;
        };

        add_filter('query', $sql_watcher);
        try {
            $rows = ll_tools_wordset_page_get_managed_category_rows($wordset_id, []);
        } finally {
            remove_filter('query', $sql_watcher);
        }

        $rows_by_id = [];
        foreach ($rows as $row) {
            $rows_by_id[(int) ($row['id'] ?? 0)] = $row;
        }

        $this->assertArrayHasKey($image_category_id, $rows_by_id);
        $this->assertArrayHasKey($prompt_category_id, $rows_by_id);
        $this->assertTrue((bool) ($rows_by_id[$image_category_id]['can_delete'] ?? false));
        $this->assertFalse((bool) ($rows_by_id[$prompt_category_id]['can_delete'] ?? true));
        $this->assertSame('Remove prompt cards in this category first.', (string) ($rows_by_id[$prompt_category_id]['delete_reason'] ?? ''));

        $sql_blob = implode("\n", $captured_sql);
        $this->assertStringNotContainsString('SELECT DISTINCT p.ID, p.post_status', $sql_blob);
        $this->assertStringNotContainsString('SELECT DISTINCT p.post_parent, p.post_status', $sql_blob);
        $this->assertStringNotContainsString('audio_posts', $sql_blob);
        $this->assertSame(1, substr_count($sql_blob, 'COUNT(DISTINCT posts.ID) AS word_count'));
        $this->assertSame(1, substr_count($sql_blob, 'COUNT(DISTINCT posts.ID) AS image_count'));
        $this->assertSame(1, substr_count($sql_blob, 'COUNT(DISTINCT posts.ID) AS prompt_card_count'));
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
        $this->assertSame(0, (int) $created_category->parent);
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
            'll_wordset_category_parent_id' => (string) $fixture['category_id'],
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
            'll_wordset_profile_blurb' => "Complements the Aleph with Beth videos.\nFocus on early Biblical Hebrew reading.",
            'll_wordset_games_image_size' => 'large',
            'll_wordset_keep_original_audio' => '1',
            'll_wordset_answer_option_text_font_weight' => '500',
            'll_wordset_answer_option_text_font_size_px' => '36',
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => '',
            'll_wordset_category_manual_order' => '',
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
        $this->assertSame("Complements the Aleph with Beth videos.\nFocus on early Biblical Hebrew reading.", (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, true));
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
            'll_wordset_profile_blurb' => 'Manager-facing intro text for this word set.',
            'll_wordset_games_image_size' => 'large',
            'll_wordset_answer_option_text_font_weight' => '500',
            'll_wordset_answer_option_text_font_size_px' => '34',
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => '',
            'll_wordset_category_manual_order' => '',
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
        $this->assertSame('Manager-facing intro text for this word set.', (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, true));
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

    public function test_categories_settings_action_deletes_non_empty_owned_category_for_manager(): void
    {
        $this->ensureWordsetManagerRole();
        $fixture = $this->createWordsetFixtureWithCategory();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_slug = (string) $fixture['wordset_slug'];
        $category_id = (int) $fixture['category_id'];
        $word_id = (int) $fixture['word_id'];
        $template_image_id = (int) $fixture['template_image_id'];
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
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('Category deleted.', (string) ($query['ll_wordset_manager_settings_message'] ?? ''));

        $deleted_category = get_term($category_id, 'word-category');
        $this->assertTrue($deleted_category === null || is_wp_error($deleted_category));
        $this->assertSame('publish', get_post_status($word_id));
        $this->assertSame('publish', get_post_status($template_image_id));

        $word_categories = wp_get_object_terms($word_id, 'word-category', ['fields' => 'ids']);
        $this->assertIsArray($word_categories);
        $this->assertNotContains($category_id, array_map('intval', $word_categories));

        $word_wordsets = wp_get_object_terms($word_id, 'wordset', ['fields' => 'ids']);
        $this->assertIsArray($word_wordsets);
        $this->assertContains($wordset_id, array_map('intval', $word_wordsets));
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

    private function setWordsetSettingsRoute(WP_Term $wordset_term, string $tool = ''): void
    {
        $_GET = $tool !== '' ? ['ll_wordset_tool' => $tool] : [];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(
            $tool !== ''
                ? ll_tools_get_wordset_settings_tool_url($wordset_term, $tool)
                : ll_tools_get_wordset_page_view_url($wordset_term, 'settings')
        );
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');
    }

    private function resetWordsetSettingsAssetHandles(): void
    {
        // Asset assertions need a request-local registry. A prior test can
        // leave locale-sort reachable through an unrelated queued parent
        // (for example the recorder or flashcard runtime), and deregistering
        // the leaf does not remove that dependency from WP_Dependencies.
        $this->installCleanScriptRegistry();
    }

    private function installCleanScriptRegistry(): void
    {
        $scripts = new WP_Scripts();
        // The test request has already passed init. Do not retain each
        // short-lived registry through a never-fired callback.
        remove_action('init', [$scripts, 'init'], 0);
        $GLOBALS['wp_scripts'] = $scripts;
    }

    private function getWordsetRuntimeLocalization(): string
    {
        $scripts = wp_scripts();
        if (!$scripts instanceof WP_Scripts || !isset($scripts->registered['ll-wordset-pages-js'])) {
            return '';
        }

        return (string) ($scripts->registered['ll-wordset-pages-js']->extra['data'] ?? '');
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

    private function createAudioRecordingForWord(int $word_id, string $recording_type_slug, int $author_id = 0): int
    {
        $word_id = (int) $word_id;
        $this->assertGreaterThan(0, $word_id);

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Recording for ' . get_the_title($word_id),
            'post_parent' => $word_id,
            'post_author' => max(0, (int) $author_id),
        ]);
        $this->assertGreaterThan(0, (int) $audio_id);

        $recording_type = get_term_by('slug', $recording_type_slug, 'recording_type');
        $this->assertInstanceOf(WP_Term::class, $recording_type);
        wp_set_post_terms((int) $audio_id, [(int) $recording_type->term_id], 'recording_type', false);

        return (int) $audio_id;
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
