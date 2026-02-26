<?php
declare(strict_types=1);

final class DefaultShortcodePageFlowTest extends LL_Tools_TestCase
{
    private int $adminUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUserId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);

        $this->cleanupRecordingPageState();
        $this->cleanupEditorHubPageState();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];

        $this->cleanupRecordingPageState();
        $this->cleanupEditorHubPageState();

        parent::tearDown();
    }

    public function test_shared_helper_reuses_existing_shortcode_page_and_updates_option(): void
    {
        $existingPageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Existing Helper Reuse Page',
            'post_content' => '[audio_recording_interface]',
        ]);

        $result = ll_tools_ensure_default_shortcode_page($this->recordingHelperConfig([
            'option_key' => 'll_test_default_recording_page_id',
            'force_option_key' => 'll_test_force_create_recording_page',
            'creation_attempt_transient' => 'll_test_recording_page_creation_attempt',
            'created_notice_transient' => 'll_test_recording_page_created',
        ]));

        $this->assertSame($existingPageId, $result);
        $this->assertSame($existingPageId, (int) get_option('ll_test_default_recording_page_id'));
        $this->assertFalse(get_transient('ll_test_recording_page_created'));

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);
        $this->assertCount(1, $pages);
    }

    public function test_shared_helper_force_create_bypasses_existing_page_and_cooldown(): void
    {
        $existingPageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Existing Helper Force Page',
            'post_content' => '[audio_recording_interface]',
        ]);

        update_option('ll_test_force_create_recording_page', 1);
        set_transient('ll_test_recording_page_creation_attempt', time(), MINUTE_IN_SECONDS);

        $result = ll_tools_ensure_default_shortcode_page($this->recordingHelperConfig([
            'option_key' => 'll_test_default_recording_page_id',
            'force_option_key' => 'll_test_force_create_recording_page',
            'creation_attempt_transient' => 'll_test_recording_page_creation_attempt',
            'created_notice_transient' => 'll_test_recording_page_created',
            'post_title' => 'Created By Helper Force',
        ]));

        $this->assertGreaterThan(0, $result);
        $this->assertNotSame($existingPageId, $result);
        $this->assertSame($result, (int) get_option('ll_test_default_recording_page_id'));
        $this->assertFalse((bool) get_option('ll_test_force_create_recording_page', false));
        $this->assertSame($result, (int) get_transient('ll_test_recording_page_created'));
    }

    public function test_recording_ajax_recreate_bypasses_existing_shortcode_page_and_cooldown(): void
    {
        $existingPageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Existing Recording AJAX Page',
            'post_content' => '[audio_recording_interface]',
        ]);

        update_option('ll_default_recording_page_id', $existingPageId);
        set_transient('ll_recording_page_creation_attempt', time(), MINUTE_IN_SECONDS);

        $_POST = [
            'action' => 'll_create_recording_page',
            'nonce' => wp_create_nonce('ll_create_recording_page'),
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_create_recording_page();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $newPageId = (int) ($data['page_id'] ?? 0);
        $this->assertGreaterThan(0, $newPageId);
        $this->assertNotSame($existingPageId, $newPageId);
        $this->assertSame($newPageId, (int) get_option('ll_default_recording_page_id'));
    }

    public function test_editor_hub_ajax_recreate_bypasses_existing_shortcode_page_and_cooldown(): void
    {
        $existingPageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Existing Editor Hub AJAX Page',
            'post_content' => '[editor_hub]',
        ]);

        update_option('ll_default_editor_hub_page_id', $existingPageId);
        set_transient('ll_editor_hub_page_creation_attempt', time(), MINUTE_IN_SECONDS);

        $_POST = [
            'action' => 'll_create_editor_hub_page',
            'nonce' => wp_create_nonce('ll_create_editor_hub_page'),
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_create_editor_hub_page();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $newPageId = (int) ($data['page_id'] ?? 0);
        $this->assertGreaterThan(0, $newPageId);
        $this->assertNotSame($existingPageId, $newPageId);
        $this->assertSame($newPageId, (int) get_option('ll_default_editor_hub_page_id'));
    }

    public function test_find_editor_hub_page_migrates_legacy_missing_text_shortcode(): void
    {
        $legacyPageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Legacy Missing Text Page',
            'post_content' => '[missing_text_interface foo=\"bar\"]',
        ]);

        $foundPageId = ll_find_editor_hub_page();

        $this->assertSame($legacyPageId, $foundPageId);
        $this->assertSame($legacyPageId, (int) get_option('ll_default_editor_hub_page_id'));

        $updatedContent = (string) get_post_field('post_content', $legacyPageId);
        $this->assertStringContainsString('[editor_hub', $updatedContent);
        $this->assertStringNotContainsString('[missing_text_interface', $updatedContent);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function recordingHelperConfig(array $overrides = []): array
    {
        return array_merge([
            'option_key' => 'll_test_default_recording_page_id',
            'force_option_key' => 'll_test_force_create_recording_page',
            'creation_attempt_transient' => 'll_test_recording_page_creation_attempt',
            'created_notice_transient' => 'll_test_recording_page_created',
            'shortcode_search' => '[audio_recording_interface',
            'post_title' => 'Test Default Recording Page',
            'post_content' => '[audio_recording_interface]',
            'error_context' => 'test recording page',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }

    private function cleanupRecordingPageState(): void
    {
        delete_option('ll_default_recording_page_id');
        delete_option('ll_tools_force_create_recording_page');
        delete_transient('ll_recording_page_creation_attempt');
        delete_transient('ll_recording_page_created');

        delete_option('ll_test_default_recording_page_id');
        delete_option('ll_test_force_create_recording_page');
        delete_transient('ll_test_recording_page_creation_attempt');
        delete_transient('ll_test_recording_page_created');
    }

    private function cleanupEditorHubPageState(): void
    {
        delete_option('ll_default_editor_hub_page_id');
        delete_option('ll_default_missing_text_page_id');
        delete_option('ll_tools_force_create_editor_hub_page');
        delete_transient('ll_editor_hub_page_creation_attempt');
        delete_transient('ll_editor_hub_page_created');
    }
}
