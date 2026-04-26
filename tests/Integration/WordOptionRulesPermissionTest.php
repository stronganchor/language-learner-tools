<?php
declare(strict_types=1);

final class WordOptionRulesPermissionTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        delete_transient('ll_word_options_import_test_token');

        parent::tearDown();
    }

    public function test_view_ll_tools_only_user_cannot_render_wordset_rules(): void
    {
        $fixture = $this->createFixture();
        $this->setCurrentViewOnlyUser();

        $_GET = [
            'page' => 'll-word-option-rules',
            'wordset_id' => $fixture['wordset_id'],
            'category_id' => $fixture['category_id'],
        ];

        $message = $this->runEndpointExpectWpDie(static function (): void {
            ll_render_word_option_rules_admin_page();
        });

        $this->assertStringContainsString('permission', strtolower($message));
    }

    public function test_wordset_manager_can_render_managed_wordset_rules(): void
    {
        $fixture = $this->createFixture();
        $manager_id = $this->setCurrentViewOnlyUser();
        update_term_meta($fixture['wordset_id'], 'manager_user_id', $manager_id);

        $_GET = [
            'page' => 'll-word-option-rules',
            'wordset_id' => $fixture['wordset_id'],
            'category_id' => $fixture['category_id'],
        ];

        $buffer_level = ob_get_level();
        ob_start();
        try {
            ll_render_word_option_rules_admin_page();
            $html = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
        }

        $this->assertStringContainsString('ll-tools-word-options-form', $html);
        $this->assertStringContainsString('Permission Fixture Word', $html);
    }

    public function test_view_ll_tools_only_user_cannot_export_wordset_rules(): void
    {
        $fixture = $this->createFixture();
        $this->setCurrentViewOnlyUser();

        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_export_word_options'),
            'wordset_id' => $fixture['wordset_id'],
            'category_id' => $fixture['category_id'],
        ];
        $_REQUEST = $_POST;

        $message = $this->runEndpointExpectWpDie(static function (): void {
            ll_tools_handle_export_word_option_rules();
        });

        $this->assertStringContainsString('permission', strtolower($message));
    }

    public function test_view_ll_tools_only_user_cannot_prepare_wordset_rule_import(): void
    {
        $fixture = $this->createFixture();
        $this->setCurrentViewOnlyUser();

        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_prepare_word_options_import'),
            'wordset_id' => $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;
        $_FILES = [];

        $message = $this->runEndpointExpectWpDie(static function (): void {
            ll_tools_handle_prepare_word_option_rules_import();
        });

        $this->assertStringContainsString('permission', strtolower($message));
    }

    public function test_view_ll_tools_only_user_cannot_apply_wordset_rule_import(): void
    {
        $fixture = $this->createFixture();
        set_transient('ll_word_options_import_test_token', [
            'data' => [
                'items' => [],
                'groups' => [],
                'pairs' => [],
            ],
            'wordset_id' => $fixture['wordset_id'],
        ], HOUR_IN_SECONDS);
        $this->setCurrentViewOnlyUser();

        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_apply_word_options_import'),
            'import_token' => 'test_token',
            'category_id' => $fixture['category_id'],
        ];
        $_REQUEST = $_POST;

        $message = $this->runEndpointExpectWpDie(static function (): void {
            ll_tools_handle_apply_word_option_rules_import();
        });

        $this->assertStringContainsString('permission', strtolower($message));
        $this->assertSame([], ll_tools_get_word_option_rules($fixture['wordset_id'], $fixture['category_id'])['groups']);
    }

    private function createFixture(): array
    {
        $wordset = wp_insert_term('Permission Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term('Permission Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Permission Fixture Word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'word_id' => (int) $word_id,
        ];
    }

    private function setCurrentViewOnlyUser(): int
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        return (int) $user_id;
    }

    private function runEndpointExpectWpDie(callable $callback): string
    {
        $captured = '';
        $buffer_level = ob_get_level();
        $die_handler = static function ($message = '') use (&$captured): void {
            if (is_scalar($message)) {
                $captured = (string) $message;
            }
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);

        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
        }

        return $captured;
    }
}
