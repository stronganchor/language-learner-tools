<?php
declare(strict_types=1);

final class WordCopyPopupTest extends LL_Tools_TestCase
{
    private array $postBackup = [];
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

    private function fixture(): array
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $category = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category, $wordset);
        update_term_meta($category, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category, 'll_quiz_option_type', 'text_translation');
        $word = self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Popup source',
        ]);
        wp_set_object_terms($word, [$wordset], 'wordset');
        wp_set_object_terms($word, [$category], 'word-category');
        update_post_meta($word, 'word_translation', 'Meaning');
        return [$wordset, $word];
    }

    private function manager(int $wordset): int
    {
        $manager = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset, 'manager_user_id', $manager);
        wp_set_current_user($manager);
        return $manager;
    }

    private function render(int $wordset, int $word): string
    {
        return ll_tools_word_grid_shortcode([
            'wordset' => (string) $wordset,
            'word_ids' => (string) $word,
            'editor_context' => '1',
            'category_editor_counts' => '0',
        ]);
    }

    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static fn() => $dieHandler;
        $doingAjaxFilter = static fn(): bool => true;
        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload. Raw output: ' . $output);
        return $decoded;
    }

    public function test_scoped_manager_popup_includes_wordset_bound_copy_control_and_assets(): void
    {
        [$wordset, $word] = $this->fixture();
        $this->manager($wordset);
        $html = $this->render($wordset, $word);

        $this->assertStringContainsString('data-ll-word-edit-panel', $html);
        $this->assertStringContainsString('data-ll-word-copy data-word-id="' . $word . '" data-wordset-id="' . $wordset . '"', $html);
        $this->assertSame(1, preg_match('/data-word-copy-nonce="([^"]+)"/', $html, $matches));
        $this->assertNotFalse(wp_verify_nonce($matches[1], 'll_wordset_manager_editor_' . $wordset));
        $this->assertFalse(wp_verify_nonce($matches[1], 'll_wordset_manager_editor_' . ($wordset + 1)));
        $this->assertTrue(wp_script_is('ll-word-copy-dialog', 'enqueued'));
        $this->assertTrue(wp_style_is('ll-word-copy-dialog', 'enqueued'));
    }

    public function test_detached_popup_returns_copy_control_for_the_requested_wordset(): void
    {
        [$wordset, $word] = $this->fixture();
        [$other_wordset] = $this->fixture();
        $manager = $this->manager($wordset);
        update_term_meta($other_wordset, 'manager_user_id', $manager);
        wp_set_object_terms($word, [$other_wordset], 'wordset', true);
        $_POST = $_REQUEST = [
            'nonce' => wp_create_nonce('ll_word_edit_modal'),
            'word_id' => (string) $word,
            'wordset_id' => (string) $other_wordset,
        ];

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_edit_modal_grid_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $html = (string) ($response['data']['html'] ?? '');
        $this->assertStringContainsString('data-ll-word-copy data-word-id="' . $word . '" data-wordset-id="' . $other_wordset . '"', $html);
        $this->assertSame(1, preg_match('/data-word-copy-nonce="([^"]+)"/', $html, $matches));
        $this->assertNotFalse(wp_verify_nonce($matches[1], 'll_wordset_manager_editor_' . $other_wordset));
        $this->assertFalse(wp_verify_nonce($matches[1], 'll_wordset_manager_editor_' . $wordset));
    }

    public function test_popup_omits_copy_controls_for_visitors_and_unassigned_managers(): void
    {
        [$wordset, $word] = $this->fixture();
        [$managed_wordset] = $this->fixture();
        wp_set_current_user(0);
        $this->assertStringNotContainsString('data-ll-word-copy', $this->render($wordset, $word));

        $this->manager($managed_wordset);
        $this->assertStringNotContainsString('data-ll-word-copy', $this->render($wordset, $word));
    }
}
