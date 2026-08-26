<?php
declare(strict_types=1);

final class VocabLessonSettingsAccessTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        if (function_exists('set_current_screen')) {
            set_current_screen('front');
        }
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        delete_option('ll_vocab_lesson_wordsets');
        delete_transient(LL_TOOLS_VOCAB_LESSON_SYNC_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        unset($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);

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
        $state = ll_tools_get_vocab_lesson_reconciliation_state();
        $this->assertSame('queued', (string) ($state['status'] ?? ''));
        $this->assertSame('cleanup', (string) ($state['phase'] ?? ''));
        $this->assertSame([$wordset_id], (array) ($state['wordset_ids'] ?? []));
        $this->assertSame(0, (int) ($state['cleanup_processed'] ?? -1));
        $this->assertSame(0, (int) ($state['created'] ?? -1));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
    }

    public function test_enabled_wordset_lookup_filters_stale_ids_in_one_query_and_preserves_order(): void
    {
        global $wpdb;

        $suffix = strtolower(wp_generate_password(8, false, false));
        $first_id = $this->ensure_term(
            'wordset',
            'Filtered Vocab Wordset First ' . $suffix,
            'filtered-vocab-wordset-first-' . $suffix
        );
        $second_id = $this->ensure_term(
            'wordset',
            'Filtered Vocab Wordset Second ' . $suffix,
            'filtered-vocab-wordset-second-' . $suffix
        );
        $stale_ids = range(9000000, 9000199);
        $configured_ids = array_merge([$second_id], $stale_ids, [$first_id, $second_id, 0, -1]);
        $this->storeEnabledWordsets($configured_ids);

        $lookup_queries = [];
        $query_observer = static function (string $query) use (&$lookup_queries, $wpdb): string {
            if (
                strpos($query, "FROM {$wpdb->terms} AS t") !== false
                && strpos($query, "INNER JOIN {$wpdb->term_taxonomy} AS tt") !== false
                && stripos($query, "tt.taxonomy IN ('wordset')") !== false
            ) {
                $lookup_queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $query_observer);
        try {
            $resolved_ids = ll_tools_get_vocab_lesson_wordset_ids();
        } finally {
            remove_filter('query', $query_observer);
        }

        $this->assertSame([$second_id, $first_id], $resolved_ids);
        $this->assertCount(1, $lookup_queries, 'Stale IDs should be resolved by one set-based taxonomy query.');
        $this->assertSame($configured_ids, get_option('ll_vocab_lesson_wordsets'));
    }

    public function test_deleting_wordset_prunes_deleted_and_current_stale_ids_without_queueing_sync(): void
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $deleted_id = $this->ensure_term(
            'wordset',
            'Deleted Vocab Wordset ' . $suffix,
            'deleted-vocab-wordset-' . $suffix
        );
        $retained_id = $this->ensure_term(
            'wordset',
            'Retained Vocab Wordset ' . $suffix,
            'retained-vocab-wordset-' . $suffix
        );
        $this->storeEnabledWordsets([9100001, $retained_id, $deleted_id, 9100002]);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        $deleted = wp_delete_term($deleted_id, 'wordset');

        $this->assertTrue($deleted);
        $this->assertSame([$retained_id], get_option('ll_vocab_lesson_wordsets'));
        $this->assertSame([], ll_tools_get_vocab_lesson_reconciliation_state());
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
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

    private function storeEnabledWordsets(array $wordset_ids): void
    {
        $previous_skip_auto_sync = !empty($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);
        $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
        try {
            update_option('ll_vocab_lesson_wordsets', $wordset_ids, false);
        } finally {
            if ($previous_skip_auto_sync) {
                $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
            } else {
                unset($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);
            }
        }
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
