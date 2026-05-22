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
        $this->ensure_term('wordset', 'Recorder UI Layout Wordset', $wordset_slug);

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

        // Compatibility guard: preserve critical IDs used by recorder JS.
        $this->assertStringContainsString('id="ll-record-btn"', $output);
        $this->assertStringContainsString('id="ll-category-select"', $output);
        $this->assertStringContainsString('class="ll-recording-type-selector"', $output);
        $this->assertStringContainsString('id="ll-recording-type"', $output);
        $this->assertStringContainsString('id="ll-playback-controls"', $output);
        $this->assertStringContainsString('id="ll-new-word-back"', $output);
        $this->assertStringContainsString('id="ll-new-word-start"', $output);
        $this->assertStringContainsString('id="ll-upload-feedback"', $output);
        $this->assertStringContainsString('id="ll-upload-progress-bar"', $output);

        $this->assertTrue(wp_script_is('ll-audio-recorder', 'enqueued'));
        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('checking_upload', $localized);
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
