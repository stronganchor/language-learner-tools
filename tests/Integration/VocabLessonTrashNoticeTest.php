<?php
declare(strict_types=1);

final class VocabLessonTrashNoticeTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        delete_option('ll_vocab_lesson_wordsets');
        delete_option('ll_tools_vocab_lesson_sync_last');
        delete_option(LL_TOOLS_VOCAB_LESSON_TRASH_NOTICE_OPTION);
        delete_transient(LL_TOOLS_VOCAB_LESSON_RECENT_UPDATE_TRANSIENT);

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);

        parent::tearDown();
    }

    public function test_recent_update_guard_preserves_existing_lessons_when_enabled_wordsets_temporarily_empty(): void
    {
        $fixture = $this->createBareLessonFixture();

        set_transient(LL_TOOLS_VOCAB_LESSON_RECENT_UPDATE_TRANSIENT, time(), 30 * MINUTE_IN_SECONDS);

        $guarded_result = ll_tools_sync_vocab_lesson_pages([]);

        $this->assertSame(0, (int) ($guarded_result['removed'] ?? -1));
        $this->assertSame('publish', get_post_status($fixture['lesson_id']));

        $manual_result = ll_tools_sync_vocab_lesson_pages([], [
            'manual' => true,
        ]);

        $this->assertSame(1, (int) ($manual_result['removed'] ?? 0));
        $this->assertSame('trash', get_post_status($fixture['lesson_id']));
    }

    public function test_recent_update_guard_skips_cleanup_when_wordset_temporarily_resolves_no_generatable_lessons(): void
    {
        $fixture = $this->createQuizzableLessonFixture();
        update_option('ll_vocab_lesson_wordsets', [$fixture['wordset_id']], false);

        $min_words_filter = static function ($min_words = 0): int {
            return 999;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        set_transient(LL_TOOLS_VOCAB_LESSON_RECENT_UPDATE_TRANSIENT, time(), 30 * MINUTE_IN_SECONDS);

        try {
            $guarded_result = ll_tools_sync_vocab_lesson_pages([$fixture['wordset_id']]);

            $this->assertSame(0, (int) ($guarded_result['removed'] ?? -1));
            $this->assertSame(1, (int) ($guarded_result['guarded_wordsets'] ?? 0));
            $this->assertSame('publish', get_post_status($fixture['lesson_id']));

            $manual_result = ll_tools_sync_vocab_lesson_pages([$fixture['wordset_id']], [
                'manual' => true,
            ]);

            $this->assertSame(1, (int) ($manual_result['removed'] ?? 0));
            $this->assertSame('trash', get_post_status($fixture['lesson_id']));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_trash_notice_renders_undo_and_restore_handler_untrashes_lesson(): void
    {
        $this->grantViewLlToolsCapabilityToAdministrators();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createBareLessonFixture();

        $this->assertInstanceOf(WP_Post::class, wp_trash_post($fixture['lesson_id']));
        $this->assertSame([$fixture['lesson_id']], ll_tools_get_trashed_vocab_lesson_notice_post_ids());

        set_current_screen('dashboard');

        ob_start();
        ll_tools_render_vocab_lesson_trash_notice();
        $output = (string) ob_get_clean();
        $decoded_output = html_entity_decode($output, ENT_QUOTES, 'UTF-8');

        $this->assertStringContainsString('moved to the Trash', $decoded_output);
        $this->assertStringContainsString('Undo', $decoded_output);
        $this->assertStringContainsString('View Trash', $decoded_output);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_wpnonce' => wp_create_nonce('ll_tools_restore_vocab_lessons'),
            'redirect_to' => admin_url('edit.php?post_type=ll_vocab_lesson'),
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_restore_vocab_lessons_request();
        });
        $redirect_query = $this->parseRedirectQuery($redirect_url);

        $this->assertSame('ok', (string) ($redirect_query['ll_vocab_lesson_restore'] ?? ''));
        $this->assertSame('1', (string) ($redirect_query['ll_vocab_lesson_restored'] ?? ''));
        $this->assertSame('publish', get_post_status($fixture['lesson_id']));
        $this->assertSame([], ll_tools_get_trashed_vocab_lesson_notice_post_ids());
    }

    /**
     * @return array{wordset_id:int, category_id:int, lesson_id:int}
     */
    private function createBareLessonFixture(): array
    {
        $suffix = strtolower(wp_generate_password(6, false));
        $wordset = wp_insert_term('Trash Notice Wordset ' . $suffix, 'wordset', [
            'slug' => 'trash-notice-wordset-' . $suffix,
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Trash Notice Category ' . $suffix, 'word-category', [
            'slug' => 'trash-notice-category-' . $suffix,
        ]);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Trash Notice Lesson ' . $suffix,
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'lesson_id' => $lesson_id,
        ];
    }

    /**
     * @return array{wordset_id:int, category_id:int, lesson_id:int}
     */
    private function createQuizzableLessonFixture(): array
    {
        $suffix = strtolower(wp_generate_password(6, false));
        $wordset = wp_insert_term('Guarded Sync Wordset ' . $suffix, 'wordset', [
            'slug' => 'guarded-sync-wordset-' . $suffix,
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Guarded Sync Category ' . $suffix, 'word-category', [
            'slug' => 'guarded-sync-category-' . $suffix,
        ]);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        for ($index = 1; $index <= LL_TOOLS_MIN_WORDS_PER_QUIZ; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Guard Word ' . $suffix . ' ' . $index,
            ]);
            update_post_meta($word_id, 'word_translation', 'Translation ' . $index);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        $created = ll_tools_get_or_create_vocab_lesson_page($category_id, $wordset_id);
        $this->assertIsArray($created);
        $lesson_id = (int) ($created['post_id'] ?? 0);
        $this->assertGreaterThan(0, $lesson_id);
        $this->assertSame('publish', get_post_status($lesson_id));

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'lesson_id' => $lesson_id,
        ];
    }

    private function grantViewLlToolsCapabilityToAdministrators(): void
    {
        $role = get_role('administrator');
        $this->assertInstanceOf(WP_Role::class, $role);
        $role->add_cap('view_ll_tools');
    }

    private function captureRedirect(callable $callback): string
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

    /**
     * @return array<string, string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);
        return array_map('strval', $decoded);
    }
}
