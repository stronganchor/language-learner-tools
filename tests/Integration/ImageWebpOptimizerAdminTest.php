<?php
declare(strict_types=1);

final class ImageWebpOptimizerAdminTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $postBackup = [];

    /** @var array<string,mixed> */
    private array $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_parse_post_id_list_accepts_unique_positive_integer_tokens_only(): void
    {
        $this->assertSame(
            [12, 4, 9],
            ll_tools_webp_optimizer_parse_post_id_list("12, 004\n9 12 0 -5 7abc +8 3.5")
        );

        $this->assertSame(
            [5, 6],
            ll_tools_webp_optimizer_parse_post_id_list([5, '6', '06', 0, -1, '8x', ['9']])
        );

        $this->assertSame([], ll_tools_webp_optimizer_parse_post_id_list(false));
    }

    public function test_webp_optimizer_ajax_requires_view_permission_before_queue_access(): void
    {
        wp_set_current_user(0);

        $_POST = [
            'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
            'page' => '1',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_webp_optimizer_queue_ajax();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('You do not have permission.', (string) ($response['data']['message'] ?? ''));
    }

    public function test_webp_optimizer_convert_ajax_rejects_empty_sanitized_id_selection(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
            'word_image_ids' => '0 nope -4 8x',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_webp_optimizer_convert_ajax();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('No word images were selected for optimization.', (string) ($response['data']['message'] ?? ''));
    }

    /**
     * @return array<string,mixed>
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
}
