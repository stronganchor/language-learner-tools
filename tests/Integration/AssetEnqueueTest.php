<?php
declare(strict_types=1);

final class AssetEnqueueTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['ll_tools_public_assets_needed']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ll_tools_public_assets_needed']);
        parent::tearDown();
    }

    public function test_enqueue_script_uses_filemtime_version(): void
    {
        $handle = 'll-tools-test-quiz-pages-js';
        ll_enqueue_asset_by_timestamp('/js/quiz-pages.js', $handle, [], true);

        $this->assertTrue(wp_script_is($handle, 'registered'));
        $this->assertTrue(wp_script_is($handle, 'enqueued'));
        $registered = wp_scripts()->registered[$handle] ?? null;
        $this->assertNotNull($registered);
        $this->assertSame(
            (string) filemtime(LL_TOOLS_BASE_PATH . 'js/quiz-pages.js'),
            (string) $registered->ver
        );
    }

    public function test_enqueue_style_uses_filemtime_version(): void
    {
        $handle = 'll-tools-test-quiz-pages-css';
        ll_enqueue_asset_by_timestamp('/css/quiz-pages.css', $handle);

        $this->assertTrue(wp_style_is($handle, 'registered'));
        $this->assertTrue(wp_style_is($handle, 'enqueued'));
        $registered = wp_styles()->registered[$handle] ?? null;
        $this->assertNotNull($registered);
        $this->assertSame(
            (string) filemtime(LL_TOOLS_BASE_PATH . 'css/quiz-pages.css'),
            (string) $registered->ver
        );
    }

    public function test_enqueue_helper_skips_missing_file(): void
    {
        $handle = 'll-tools-test-missing-asset';
        ll_enqueue_asset_by_timestamp('/js/this-file-does-not-exist.js', $handle, [], true);

        $this->assertFalse(wp_script_is($handle, 'registered'));
        $this->assertFalse(wp_script_is($handle, 'enqueued'));
    }

    public function test_quiz_pages_script_localizes_close_confirmation_label(): void
    {
        $this->go_to('/');

        ll_qp_enqueue_assets();

        $this->assertTrue(wp_script_is('ll-quiz-pages-js', 'enqueued'));

        $localized = wp_scripts()->get_data('ll-quiz-pages-js', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('llQuizPages', $localized);
        $this->assertStringContainsString('closeConfirm', $localized);
    }

    public function test_public_asset_bundle_enqueues_login_window_styles(): void
    {
        ll_tools_enqueue_public_assets();

        $this->assertTrue(wp_style_is('ll-tools-login-window', 'registered'));
        $this->assertTrue(wp_style_is('ll-tools-login-window', 'enqueued'));
        $this->assertTrue(wp_script_is('ll-tools-login-window-js', 'registered'));
        $this->assertTrue(wp_script_is('ll-tools-login-window-js', 'enqueued'));

        $registered = wp_styles()->registered['ll-tools-login-window'] ?? null;
        $this->assertNotNull($registered);
        $this->assertSame(
            (string) filemtime(LL_TOOLS_BASE_PATH . 'css/login-window.css'),
            (string) $registered->ver
        );

        $registered_script = wp_scripts()->registered['ll-tools-login-window-js'] ?? null;
        $this->assertNotNull($registered_script);
        $this->assertSame(
            (string) filemtime(LL_TOOLS_BASE_PATH . 'js/login-window.js'),
            (string) $registered_script->ver
        );
    }

    public function test_request_needs_public_assets_false_for_plain_singular_page(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Plain Page',
            'post_content' => 'No LL shortcodes here.',
        ]);

        $this->go_to(get_permalink($page_id));

        $this->assertFalse(ll_tools_request_needs_public_assets());
    }

    public function test_request_needs_public_assets_true_for_singular_shortcode_page(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Flashcard Page',
            'post_content' => '[flashcard_widget]',
        ]);

        $this->go_to(get_permalink($page_id));

        $this->assertTrue(ll_tools_request_needs_public_assets());
    }

    public function test_request_needs_public_assets_true_when_marked_manually(): void
    {
        $this->go_to('/');
        ll_tools_mark_public_assets_needed();

        $this->assertTrue(ll_tools_public_assets_marked());
        $this->assertTrue(ll_tools_request_needs_public_assets());
    }

    public function test_request_needs_public_assets_global_force_filter_overrides_detection(): void
    {
        $filter = static function (): bool {
            return true;
        };
        add_filter('ll_tools_enqueue_public_assets_globally', $filter);

        try {
            $this->go_to('/');
            $this->assertTrue(ll_tools_request_needs_public_assets());
        } finally {
            remove_filter('ll_tools_enqueue_public_assets_globally', $filter);
        }
    }
}
