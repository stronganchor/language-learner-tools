<?php
declare(strict_types=1);

final class VocabLessonSettingsAccessTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        if (function_exists('set_current_screen')) {
            set_current_screen('front');
        }

        parent::tearDown();
    }

    public function test_non_admin_ll_tools_user_cannot_submit_global_vocab_lesson_settings(): void
    {
        delete_option('ll_vocab_lesson_wordsets');

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $_GET = [
            'post_type' => 'll_vocab_lesson',
        ];
        $_POST = [
            'll_vocab_lesson_settings_nonce' => wp_create_nonce('ll_vocab_lesson_settings'),
            'll_vocab_lesson_save' => '1',
            'll_vocab_lesson_wordsets' => [],
        ];
        set_current_screen('edit-ll_vocab_lesson');
        global $pagenow;
        $previous_pagenow = $pagenow;
        $pagenow = 'edit.php';

        try {
            $message = $this->capture_wp_die_message(static function (): void {
                ll_tools_handle_vocab_lesson_settings_submit();
            });
        } finally {
            $_GET = [];
            $_POST = [];
            $pagenow = $previous_pagenow;
        }

        $this->assertStringContainsString('do not have permission', strtolower($message));
        $this->assertSame([], ll_tools_get_vocab_lesson_wordset_ids());
    }

    public function test_global_vocab_lesson_settings_panel_is_hidden_for_non_admin_ll_tools_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        set_current_screen('edit-ll_vocab_lesson');

        ob_start();
        ll_tools_render_vocab_lesson_admin_panel();
        $html = (string) ob_get_clean();

        $this->assertSame('', trim($html));
    }

    public function test_admin_can_submit_global_vocab_lesson_settings(): void
    {
        delete_option('ll_vocab_lesson_wordsets');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensure_term('wordset', 'Admin Vocab Lesson Wordset', 'admin-vocab-lesson-wordset');

        $_GET = [
            'post_type' => 'll_vocab_lesson',
        ];
        $_POST = [
            'll_vocab_lesson_settings_nonce' => wp_create_nonce('ll_vocab_lesson_settings'),
            'll_vocab_lesson_save' => '1',
            'll_vocab_lesson_wordsets' => [(string) $wordset_id],
        ];
        set_current_screen('edit-ll_vocab_lesson');
        global $pagenow;
        $previous_pagenow = $pagenow;
        $pagenow = 'edit.php';

        try {
            $redirect_url = $this->capture_redirect(static function (): void {
                ll_tools_handle_vocab_lesson_settings_submit();
            });
        } finally {
            $_GET = [];
            $_POST = [];
            $pagenow = $previous_pagenow;
        }

        $this->assertStringContainsString('post_type=ll_vocab_lesson', $redirect_url);
        $this->assertSame([$wordset_id], ll_tools_get_vocab_lesson_wordset_ids());
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

    private function capture_wp_die_message(callable $callback): string
    {
        $captured = '';
        $die_handler = static function ($message) use (&$captured): void {
            $captured = is_scalar($message) ? (string) $message : '';
            throw new RuntimeException('wp_die_intercepted');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };

        add_filter('wp_die_handler', $die_filter);

        try {
            $callback();
            $this->fail('Expected wp_die().');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_die_handler', $die_filter);
        }

        return $captured;
    }

    private function capture_redirect(callable $callback): string
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
}
