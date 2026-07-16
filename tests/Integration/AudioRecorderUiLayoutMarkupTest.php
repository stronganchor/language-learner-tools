<?php
declare(strict_types=1);

final class AudioRecorderUiLayoutMarkupTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption;

    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_utility_menu_includes_recorder_context_class(): void
    {
        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'recorder',
        ]);

        $this->assertStringContainsString('ll-wordset-utility-bar--context-recorder', $markup);
    }

    public function test_audio_recording_shortcode_renders_overlay_shells_and_core_controls(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_slug = 'recorder-ui-layout-wordset';
        $wordset_id = $this->ensure_term('wordset', 'Recorder UI Layout Wordset', $wordset_slug);
        $category_id = $this->ensure_term('word-category', 'Recorder UI Layout Category', 'recorder-ui-layout-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder UI Layout Word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $output = do_shortcode('[audio_recording_interface wordset="' . $wordset_slug . '" allow_new_words="1" auto_process_recordings="1"]');

        $this->assertStringContainsString('ll-wordset-utility-bar--context-recorder', $output);
        $this->assertStringContainsString('id="ll-hidden-words-overlay"', $output);
        $this->assertStringContainsString('id="ll-hidden-words-panel"', $output);
        $this->assertStringContainsString('id="ll-new-word-overlay"', $output);
        $this->assertStringContainsString('id="ll-recording-review-overlay"', $output);
        $this->assertStringContainsString('class="ll-new-word-layout"', $output);
        $this->assertStringContainsString('class="ll-new-word-form-grid"', $output);
        $this->assertStringContainsString('class="ll-new-word-sidebar"', $output);
        $this->assertStringContainsString('ll-new-word-close', $output);
        $this->assertStringContainsString('id="ll-new-word-status"', $output);

        // The initial route is a category overview, not a focused recorder.
        $this->assertStringNotContainsString('id="ll-record-btn"', $output);
        $this->assertStringNotContainsString('id="ll-category-select"', $output);
        $this->assertStringNotContainsString('class="ll-recording-progress"', $output);
        $this->assertStringNotContainsString('class="ll-recording-main"', $output);
        $this->assertStringContainsString('id="ll-new-word-toggle"', $output);
        $this->assertStringContainsString('id="ll-new-word-overlay" hidden', $output);
        $this->assertStringContainsString('id="ll-new-word-back"', $output);
        $this->assertStringContainsString('id="ll-new-word-start"', $output);
        $this->assertStringContainsString('id="ll-upload-feedback"', $output);
        $this->assertStringContainsString('id="ll-upload-progress-bar"', $output);
        $this->assertStringContainsString('data-ll-recorder-category-overview', $output);
        $this->assertStringContainsString('data-ll-recorder-category-grid', $output);
        $this->assertStringContainsString('data-ll-recorder-queue-summary-placeholder="true"', $output);

        $this->assertTrue(wp_script_is('ll-audio-recorder', 'enqueued'));
        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('checking_upload', $localized);
        $this->assertStringContainsString('"category_overview"', $localized);
        $this->assertStringContainsString('"action":"ll_tools_recorder_queue_summaries"', $localized);
        $this->assertStringContainsString('"view":"overview"', $localized);
        $this->assertStringContainsString('"images":[]', $localized);
        $this->assertStringContainsString('"initial_category":""', $localized);

        $fixed_category_output = do_shortcode(
            '[audio_recording_interface wordset="' . $wordset_slug . '" category="recorder-ui-layout-category"]'
        );
        $this->assertStringNotContainsString('data-ll-recorder-category-overview', $fixed_category_output);
        $this->assertStringContainsString('id="ll-record-btn"', $fixed_category_output);
        $this->assertStringContainsString('type="hidden" id="ll-category-select" value="recorder-ui-layout-category"', $fixed_category_output);
        $this->assertStringNotContainsString('<select id="ll-category-select"', $fixed_category_output);
        $this->assertStringNotContainsString('data-ll-recorder-category-back', $fixed_category_output);
        $this->assertStringContainsString('class="ll-recording-progress"', $fixed_category_output);

        $_GET['ll_record_category'] = 'recorder-ui-layout-category';
        $launched_category_output = do_shortcode(
            '[audio_recording_interface wordset="' . $wordset_slug . '"]'
        );
        $this->assertStringContainsString('data-ll-recorder-category-back', $launched_category_output);
        $this->assertStringContainsString('id="ll-record-btn"', $launched_category_output);
    }

    public function test_audio_recording_shortcode_launch_query_prioritizes_requested_word(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Launch Wordset', 'recorder-launch-wordset');
        $category_id = $this->ensure_term('word-category', 'Recorder Launch Category', 'recorder-launch-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $first_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Alpha Launch Word',
        ]);
        wp_set_object_terms($first_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($first_word_id, [$category_id], 'word-category', false);

        $start_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Zulu Launch Word',
        ]);
        wp_set_object_terms($start_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($start_word_id, [$category_id], 'word-category', false);

        $_GET = [
            'll_record_wordset' => (string) $wordset_id,
            'll_record_category' => 'recorder-launch-category',
            'll_record_word' => (string) $start_word_id,
        ];

        do_shortcode('[audio_recording_interface]');

        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $expected_category = function_exists('ll_tools_recorder_resolve_category_term_for_wordsets')
            ? ll_tools_recorder_resolve_category_term_for_wordsets('recorder-launch-category', [$wordset_id], false)
            : get_term_by('slug', 'recorder-launch-category', 'word-category');
        $expected_category_slug = $expected_category instanceof WP_Term ? (string) $expected_category->slug : 'recorder-launch-category';
        $this->assertStringContainsString('"initial_category":"' . $expected_category_slug . '"', $localized);

        $start_position = strpos($localized, '"word_id":' . $start_word_id);
        $first_position = strpos($localized, '"word_id":' . $first_word_id);

        $this->assertIsInt($start_position);
        $this->assertIsInt($first_position);
        $this->assertLessThan($first_position, $start_position);
    }

    public function test_default_recorder_bootstrap_is_category_neutral_and_does_not_hydrate_a_queue(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $wordsetId = $this->ensure_term('wordset', 'Recorder Bounded Bootstrap', 'recorder-bounded-bootstrap');
        $wordset = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $isolationId = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $this->assertGreaterThan(0, $isolationId);

        $categoryIds = [
            $this->ensure_term('word-category', 'Alpha Recorder Category', 'alpha-recorder-category'),
            $this->ensure_term('word-category', 'Beta Recorder Category', 'beta-recorder-category'),
        ];
        foreach ($categoryIds as $categoryId) {
            update_term_meta($categoryId, 'll_desired_recording_types', ['isolation']);
            for ($index = 1; $index <= 35; $index++) {
                $wordId = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => 'Recorder Bootstrap ' . $categoryId . '-' . $index,
                ]);
                wp_set_object_terms($wordId, [$wordsetId], 'wordset', false);
                wp_set_object_terms($wordId, [$categoryId], 'word-category', false);
            }
        }

        $pageSizeFilter = static function (): int {
            return 10;
        };
        $wordQueries = [];
        $legacyCategoryDiscoveryQueries = [];
        $captureWordQueries = static function (WP_Query $query) use (&$wordQueries): void {
            if ((string) $query->get('post_type') === 'words') {
                $wordQueries[] = $query->query_vars;
            }
        };
        $captureLegacyCategoryDiscovery = static function (string $sql) use (&$legacyCategoryDiscoveryQueries): string {
            $normalizedSql = preg_replace('/\s+/', ' ', $sql);
            if (
                is_string($normalizedSql)
                && strpos($normalizedSql, 'SELECT DISTINCT category_taxonomy.term_id') !== false
                && (
                    strpos($normalizedSql, ' words INNER JOIN') !== false
                    || strpos($normalizedSql, ' prompt_cards INNER JOIN') !== false
                )
            ) {
                $legacyCategoryDiscoveryQueries[] = $normalizedSql;
            }
            return $sql;
        };
        add_filter('ll_tools_recorder_category_switch_page_size', $pageSizeFilter);
        add_action('pre_get_posts', $captureWordQueries);
        add_filter('query', $captureLegacyCategoryDiscovery);
        try {
            do_shortcode('[audio_recording_interface wordset="' . $wordset->slug . '" allow_new_words="1"]');
        } finally {
            remove_filter('ll_tools_recorder_category_switch_page_size', $pageSizeFilter);
            remove_action('pre_get_posts', $captureWordQueries);
            remove_filter('query', $captureLegacyCategoryDiscovery);
        }

        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $matches = [];
        $matchCount = preg_match_all('/var ll_recorder_data = (\{[^\r\n]+\});/', $localized, $matches);
        $this->assertGreaterThanOrEqual(1, $matchCount);
        $payload = json_decode((string) end($matches[1]), true);
        $this->assertIsArray($payload);
        $this->assertSame('overview', (string) ($payload['view'] ?? ''));
        $this->assertSame([], (array) ($payload['images'] ?? []));
        $this->assertSame('', (string) ($payload['initial_category'] ?? 'missing'));
        $this->assertFalse((bool) ($payload['category_queue']['has_more'] ?? true));
        $availableCategories = (array) ($payload['available_categories'] ?? []);
        $this->assertCount(2, $availableCategories);
        $categoryLabels = implode(' ', array_map('strval', array_values($availableCategories)));
        $this->assertStringContainsString('Alpha Recorder Category', $categoryLabels);
        $this->assertStringContainsString('Beta Recorder Category', $categoryLabels);
        foreach ($wordQueries as $queryVars) {
            $this->assertNotSame(-1, (int) ($queryVars['posts_per_page'] ?? 0));
        }
        $this->assertSame([], $legacyCategoryDiscoveryQueries, 'Recorder bootstrap must not run the legacy uncached relationship scans.');
    }

    public function test_default_recorder_bootstrap_lists_prompt_category_without_hydrating_its_queue(): void
    {
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        ll_tools_register_or_refresh_audio_recorder_role();
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $wordsetId = $this->ensure_term('wordset', 'Prompt-only Recorder Wordset', 'prompt-only-recorder-wordset');
        $wordset = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $categoryId = $this->ensure_term('word-category', 'Prompt-only Recorder Category', 'prompt-only-recorder-category');

        $promptCardId = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt-only Recorder Card',
        ]);
        wp_set_object_terms($promptCardId, [$wordsetId], 'wordset', false);
        wp_set_object_terms($promptCardId, [$categoryId], 'word-category', false);
        update_post_meta($promptCardId, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record this categorized prompt.');

        $promptCardQueries = [];
        $capturePromptCardQueries = static function (WP_Query $query) use (&$promptCardQueries): void {
            if ((string) $query->get('post_type') === LL_TOOLS_PROMPT_CARD_POST_TYPE) {
                $promptCardQueries[] = $query->query_vars;
            }
        };
        add_action('pre_get_posts', $capturePromptCardQueries);
        try {
            $output = do_shortcode('[audio_recording_interface wordset="' . $wordset->slug . '"]');
        } finally {
            remove_action('pre_get_posts', $capturePromptCardQueries);
        }

        $this->assertStringNotContainsString('id="ll-record-btn"', $output);
        $this->assertStringContainsString('data-ll-recorder-category-overview', $output);
        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $matches = [];
        $matchCount = preg_match_all('/var ll_recorder_data = (\{[^\r\n]+\});/', $localized, $matches);
        $this->assertGreaterThanOrEqual(1, $matchCount);
        $payload = json_decode((string) end($matches[1]), true);
        $this->assertIsArray($payload);

        $this->assertSame('overview', (string) ($payload['view'] ?? ''));
        $this->assertSame('', (string) ($payload['initial_category'] ?? 'missing'));
        $this->assertSame(
            ['prompt-only-recorder-category' => 'Prompt-only Recorder Category'],
            (array) ($payload['available_categories'] ?? [])
        );
        $this->assertSame([], (array) ($payload['images'] ?? []));
        foreach ($promptCardQueries as $queryVars) {
            $this->assertSame('ids', (string) ($queryVars['fields'] ?? ''));
            $this->assertLessThanOrEqual(
                1,
                (int) ($queryVars['posts_per_page'] ?? 0),
                'Overview bootstrap may run only the compact prompt-category existence probe, not queue hydration.'
            );
        }
    }

    public function test_new_word_category_select_is_scoped_to_selected_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_register_or_refresh_audio_recorder_role();

        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensure_term('wordset', 'Recorder Scope Wordset One', 'recorder-scope-wordset-one');
        $wordset_two_id = $this->ensure_term('wordset', 'Recorder Scope Wordset Two', 'recorder-scope-wordset-two');
        $shared_category_id = $this->ensure_term('word-category', 'Recorder Shared Trees', 'recorder-shared-trees');

        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($shared_category_id, 0, $shared_category_id);
        }

        $scoped_one_id = function_exists('ll_tools_get_or_create_isolated_category_copy')
            ? (int) ll_tools_get_or_create_isolated_category_copy($shared_category_id, $wordset_one_id)
            : 0;
        $scoped_two_id = function_exists('ll_tools_get_or_create_isolated_category_copy')
            ? (int) ll_tools_get_or_create_isolated_category_copy($shared_category_id, $wordset_two_id)
            : 0;
        $this->assertGreaterThan(0, $scoped_one_id);
        $this->assertGreaterThan(0, $scoped_two_id);

        if (function_exists('ll_tools_create_or_get_wordset_category')) {
            $wordset_two_only_id = (int) ll_tools_create_or_get_wordset_category('Recorder Wordset Two Only', $wordset_two_id);
            $this->assertGreaterThan(0, $wordset_two_only_id);
        }

        $wordset_one = get_term($wordset_one_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_one);
        $scoped_one = get_term($scoped_one_id, 'word-category');
        $scoped_two = get_term($scoped_two_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $scoped_one);
        $this->assertInstanceOf(WP_Term::class, $scoped_two);

        $output = do_shortcode('[audio_recording_interface wordset="' . $wordset_one->slug . '" allow_new_words="1"]');
        $select_markup = $this->extract_select_markup($output, 'll-new-word-category');

        $this->assertStringContainsString('value="' . $scoped_one->slug . '"', $select_markup);
        $this->assertStringNotContainsString('value="' . $scoped_two->slug . '"', $select_markup);
        $this->assertStringNotContainsString('Recorder Wordset Two Only', $select_markup);
        $this->assertSame(1, preg_match_all('/<option[^>]*>\s*Recorder Shared Trees\s*<\/option>/', $select_markup));
    }

    public function test_admin_can_switch_recording_wordset_from_recorder_interface(): void
    {
        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensure_term('wordset', 'Recorder Admin Switch One', 'recorder-admin-switch-one');
        $wordset_two_id = $this->ensure_term('wordset', 'Recorder Admin Switch Two', 'recorder-admin-switch-two');
        $wordset_one = get_term($wordset_one_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_one);

        $output = do_shortcode('[audio_recording_interface wordset="' . $wordset_one->slug . '" allow_new_words="1"]');
        $select_markup = $this->extract_select_markup($output, 'll-wordset-select');

        $this->assertMatchesRegularExpression('/value="' . preg_quote((string) $wordset_one_id, '/') . '"\s+selected=/', $select_markup);
        $this->assertStringContainsString('value="' . $wordset_two_id . '"', $select_markup);
        $this->assertStringContainsString('Recorder Admin Switch One', $select_markup);
        $this->assertStringContainsString('Recorder Admin Switch Two', $select_markup);
    }

    public function test_wordset_manager_switcher_is_limited_to_managed_wordsets(): void
    {
        if (function_exists('ll_create_wordset_manager_role')) {
            ll_create_wordset_manager_role();
        }
        if (function_exists('ll_ensure_wordset_manager_has_view_ll_tools_cap')) {
            ll_ensure_wordset_manager_has_view_ll_tools_cap();
        }

        $manager_id = self::factory()->user->create([
            'role' => 'wordset_manager',
        ]);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        $manager->add_cap('upload_files');
        clean_user_cache($manager_id);

        $managed_one_id = $this->ensure_term('wordset', 'Recorder Managed Switch One', 'recorder-managed-switch-one');
        $managed_two_id = $this->ensure_term('wordset', 'Recorder Managed Switch Two', 'recorder-managed-switch-two');
        $unmanaged_id = $this->ensure_term('wordset', 'Recorder Unmanaged Switch', 'recorder-unmanaged-switch');

        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($managed_one_id, [$manager_id], $manager_id));
        $this->assertTrue((bool) ll_tools_set_wordset_manager_user_ids($managed_two_id, [$manager_id], $manager_id));
        update_user_meta($manager_id, 'll_primary_managed_wordset_id', $managed_two_id);

        wp_set_current_user($manager_id);

        $output = do_shortcode('[audio_recording_interface allow_new_words="1"]');
        $select_markup = $this->extract_select_markup($output, 'll-wordset-select');

        $this->assertStringContainsString('value="' . $managed_one_id . '"', $select_markup);
        $this->assertMatchesRegularExpression('/value="' . preg_quote((string) $managed_two_id, '/') . '"\s+selected=/', $select_markup);
        $this->assertStringNotContainsString('value="' . $unmanaged_id . '"', $select_markup);
        $this->assertStringContainsString('Recorder Managed Switch One', $select_markup);
        $this->assertStringContainsString('Recorder Managed Switch Two', $select_markup);
        $this->assertStringNotContainsString('Recorder Unmanaged Switch', $select_markup);

        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('"wordset_ids":[' . $managed_two_id . ']', $localized);
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function extract_select_markup(string $html, string $select_id): string
    {
        $matches = [];
        preg_match('/<select[^>]+id="' . preg_quote($select_id, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);

        return (string) $matches[1];
    }
}
