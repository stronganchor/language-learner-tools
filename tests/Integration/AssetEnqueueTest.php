<?php
declare(strict_types=1);

final class AssetEnqueueTest extends LL_Tools_TestCase
{
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
}
