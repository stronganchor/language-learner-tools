<?php
declare(strict_types=1);

final class AdminToolCapabilityTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    public function test_default_offline_export_capability_is_manage_options(): void
    {
        $this->assertSame('manage_options', ll_tools_get_offline_app_export_capability());
    }

    public function test_offline_export_capability_filter_is_applied(): void
    {
        $filter = static function (): string {
            return 'view_ll_tools';
        };

        add_filter('ll_tools_offline_app_export_capability', $filter);

        try {
            $this->assertSame('view_ll_tools', ll_tools_get_offline_app_export_capability());
        } finally {
            remove_filter('ll_tools_offline_app_export_capability', $filter);
        }
    }

    public function test_default_bulk_word_import_capability_is_manage_options(): void
    {
        $this->assertSame('manage_options', ll_tools_get_bulk_word_import_capability());
    }

    public function test_bulk_word_import_capability_filter_is_applied(): void
    {
        $filter = static function (): string {
            return 'view_ll_tools';
        };

        add_filter('ll_tools_bulk_word_import_capability', $filter);

        try {
            $this->assertSame('view_ll_tools', ll_tools_get_bulk_word_import_capability());
        } finally {
            remove_filter('ll_tools_bulk_word_import_capability', $filter);
        }
    }

    public function test_default_settings_maintenance_capability_is_manage_options(): void
    {
        $this->assertSame('manage_options', ll_tools_get_settings_maintenance_capability());
    }

    public function test_settings_maintenance_capability_filter_is_applied(): void
    {
        $filter = static function (): string {
            return 'view_ll_tools';
        };

        add_filter('ll_tools_settings_maintenance_capability', $filter);

        try {
            $this->assertSame('view_ll_tools', ll_tools_get_settings_maintenance_capability());
        } finally {
            remove_filter('ll_tools_settings_maintenance_capability', $filter);
        }
    }

    public function test_offline_export_handler_blocks_view_ll_tools_only_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_export_offline_app'),
            'll_offline_wordset_id' => 1,
        ];
        $_REQUEST = $_POST;

        try {
            $message = $this->runEndpointExpectWpDie(static function (): void {
                ll_tools_handle_export_offline_app();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertStringContainsString('You do not have permission', $message);
    }

    public function test_bulk_word_import_does_not_create_words_for_view_ll_tools_only_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $wordTitle = 'Should Not Import';
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_bulk_word_import_nonce' => wp_create_nonce('ll_bulk_word_import'),
            'll_word_list' => $wordTitle . "\n",
        ];

        ob_start();
        try {
            ll_tools_render_bulk_word_import_page();
            $output = (string) ob_get_clean();
        } finally {
            $_POST = [];
            if ($originalRequestMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
            }
        }

        $this->assertSame('', $output);
        $this->assertSame(0, $this->countWordsByExactTitle($wordTitle));
    }

    public function test_bulk_word_import_creates_draft_for_admin(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordTitle = 'Reliable Import ' . wp_generate_password(8, false, false);
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_bulk_word_import_nonce' => wp_create_nonce('ll_bulk_word_import'),
            'll_word_list' => $wordTitle . "\n",
        ];
        $_REQUEST = $_POST;

        ob_start();
        try {
            ll_tools_render_bulk_word_import_page();
            ob_end_clean();
        } finally {
            $_POST = [];
            $_REQUEST = [];
            if ($originalRequestMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
            }
        }

        $this->assertSame(1, $this->countWordsByExactTitle($wordTitle));
    }

    public function test_settings_page_rejects_purge_for_view_ll_tools_only_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Legacy Audio Word',
        ]);
        update_post_meta($word_id, 'word_audio_file', 'legacy-audio.mp3');
        $this->assertSame('legacy-audio.mp3', (string) get_post_meta($word_id, 'word_audio_file', true));

        $_POST = [
            'll_tools_purge_legacy_audio' => '1',
            'll_tools_purge_legacy_audio_nonce' => wp_create_nonce('ll_tools_purge_legacy_audio'),
        ];

        ob_start();
        try {
            ll_render_settings_page();
            $output = (string) ob_get_clean();
        } finally {
            $_POST = [];
        }

        $this->assertStringContainsString('You do not have permission to run maintenance actions.', $output);
        $this->assertSame('legacy-audio.mp3', (string) get_post_meta($word_id, 'word_audio_file', true));
    }

    public function test_settings_page_rejects_cache_flush_for_view_ll_tools_only_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        set_transient('ll_wc_words_capability_test', ['safe' => true], HOUR_IN_SECONDS);
        $this->assertSame(['safe' => true], get_transient('ll_wc_words_capability_test'));

        $_POST = [
            'll_tools_flush_quiz_cache' => '1',
            'll_tools_flush_quiz_cache_nonce' => wp_create_nonce('ll_tools_flush_quiz_cache'),
        ];

        ob_start();
        try {
            ll_render_settings_page();
            $output = (string) ob_get_clean();
        } finally {
            $_POST = [];
        }

        $this->assertStringContainsString('You do not have permission to run maintenance actions.', $output);
        $this->assertSame(['safe' => true], get_transient('ll_wc_words_capability_test'));
    }

    public function test_tools_hub_hides_admin_only_cards_from_view_ll_tools_only_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        ob_start();
        ll_tools_render_tools_hub_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Audio Processor', $output);
        $this->assertStringContainsString('Missing Audio', $output);
        $this->assertStringNotContainsString('Bulk Word Import', $output);
        $this->assertStringNotContainsString('Offline App Export', $output);
    }

    private function runEndpointExpectWpDie(callable $callback): string
    {
        $captured = '';
        $dieHandler = static function ($message = '') use (&$captured): void {
            if (is_scalar($message)) {
                $captured = (string) $message;
            }
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);

        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
        }

        return $captured;
    }

    private function countWordsByExactTitle(string $title): int
    {
        $ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $count = 0;
        foreach ((array) $ids as $id) {
            if ((string) get_post_field('post_title', (int) $id) === $title) {
                $count++;
            }
        }

        return $count;
    }
}
