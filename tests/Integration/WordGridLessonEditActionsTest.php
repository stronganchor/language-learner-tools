<?php
declare(strict_types=1);

final class WordGridLessonEditActionsTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_lesson_edit_popup_renders_word_and_recording_management_controls(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-render');
        $this->loginEditor();

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            $output = do_shortcode('[word_grid category="lesson-edit-actions-render-category" wordset="lesson-edit-actions-render-wordset"]');
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }

        $this->assertStringContainsString('data-ll-word-delete-toggle', $output);
        $this->assertStringContainsString('data-ll-word-delete-confirm', $output);
        $this->assertStringContainsString('data-ll-recording-delete-toggle', $output);
        $this->assertStringContainsString('data-ll-recording-delete-confirm', $output);
        $this->assertStringContainsString('data-ll-recording-move-toggle', $output);
        $this->assertStringContainsString('data-ll-recording-move-search', $output);
        $this->assertStringContainsString('data-ll-recording-move-confirm', $output);
        $this->assertStringContainsString('data-ll-word-image-copyright', $output);
        $this->assertStringContainsString('Copyright Info', $output);
        $this->assertStringContainsString('data-recording-id="' . (int) $fixture['recording_id'] . '"', $output);
    }

    public function test_lesson_editor_can_move_word_to_trash(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-delete-word');
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'word_id' => (string) $fixture['source_word_id'],
            'wordset_id' => (string) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_delete_word_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame('trash', get_post_status((int) $fixture['source_word_id']));
        $this->assertSame((int) $fixture['source_word_id'], (int) ($response['data']['word_id'] ?? 0));
        $recent_actions = ll_tools_wordset_editor_get_recent_actions((int) $fixture['wordset_id'], 1);
        $this->assertSame('word_trash', (string) ($recent_actions[0]['type'] ?? ''));
    }

    public function test_lesson_editor_can_move_recording_to_trash(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-delete-recording');
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'recording_id' => (string) $fixture['recording_id'],
            'wordset_id' => (string) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_delete_recording_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame('trash', get_post_status((int) $fixture['recording_id']));
        $this->assertSame('draft', get_post_status((int) $fixture['source_word_id']));
        $this->assertSame((int) $fixture['source_word_id'], (int) ($response['data']['word_id'] ?? 0));
        $this->assertFalse((bool) ($response['data']['has_remaining_recordings'] ?? true));
        $recent_actions = ll_tools_wordset_editor_get_recent_actions((int) $fixture['wordset_id'], 1);
        $this->assertSame('recording_trash', (string) ($recent_actions[0]['type'] ?? ''));
    }

    public function test_lesson_editor_can_move_recording_to_another_word_in_wordset(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-move-recording');
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'recording_id' => (string) $fixture['recording_id'],
            'target_word_id' => (string) $fixture['target_word_id'],
            'wordset_id' => (string) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_move_recording_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame((int) $fixture['target_word_id'], (int) wp_get_post_parent_id((int) $fixture['recording_id']));
        $this->assertSame('draft', get_post_status((int) $fixture['source_word_id']));
        $this->assertSame((int) $fixture['source_word_id'], (int) ($response['data']['source_word_id'] ?? 0));
        $this->assertSame((int) $fixture['target_word_id'], (int) ($response['data']['target_word_id'] ?? 0));
        $this->assertSame((int) $fixture['recording_id'], (int) ($response['data']['recording']['id'] ?? 0));
        $recent_actions = ll_tools_wordset_editor_get_recent_actions((int) $fixture['wordset_id'], 1);
        $this->assertSame('recording_move', (string) ($recent_actions[0]['type'] ?? ''));
    }

    public function test_lesson_editor_word_search_finds_move_targets_by_translation(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-search-word');
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => (string) $fixture['wordset_id'],
            'exclude_word_id' => (string) $fixture['source_word_id'],
            'q' => 'Target Translation',
            'limit' => '20',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_search_words_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $ids = array_map('intval', wp_list_pluck((array) ($response['data']['words'] ?? []), 'id'));
        $this->assertContains((int) $fixture['target_word_id'], $ids);
        $this->assertNotContains((int) $fixture['source_word_id'], $ids);
    }

    public function test_lesson_editor_can_create_draft_word_for_lesson(): void
    {
        $fixture = $this->createFixture('lesson-edit-actions-create-word');
        $this->loginEditor();

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lesson Edit Actions Create Word Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (int) $fixture['wordset_id']);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (int) $fixture['category_id']);

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'lesson_id' => (string) $lesson_id,
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_create_lesson_word_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $word_id = (int) ($response['data']['word_id'] ?? 0);
        $this->assertGreaterThan(0, $word_id);
        $this->assertSame('draft', get_post_status($word_id));
        $wordset_ids = array_map('intval', (array) wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']));
        $category_ids = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $expected_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset((int) $fixture['category_id'], (int) $fixture['wordset_id'], false)
            : (int) $fixture['category_id'];
        $this->assertContains((int) $fixture['wordset_id'], $wordset_ids, wp_json_encode($wordset_ids));
        $this->assertContains($expected_category_id, $category_ids, wp_json_encode($category_ids));
        $this->assertSame((int) $fixture['category_id'], (int) ($response['data']['lesson_category_id'] ?? 0));
        $this->assertSame($expected_category_id, (int) ($response['data']['category_id'] ?? 0));
        $this->assertSame($lesson_id, (int) get_post_meta($word_id, '_ll_created_from_vocab_lesson_id', true));
        $this->assertStringContainsString('data-word-id="' . $word_id . '"', (string) ($response['data']['html'] ?? ''));
        $this->assertStringContainsString('data-ll-word-edit-toggle', (string) ($response['data']['html'] ?? ''));
        $recent_actions = ll_tools_wordset_editor_get_recent_actions((int) $fixture['wordset_id'], 1);
        $this->assertSame('word_create', (string) ($recent_actions[0]['type'] ?? ''));
    }

    /**
     * @return array{wordset_id:int,category_id:int,source_word_id:int,target_word_id:int,recording_id:int}
     */
    private function createFixture(string $prefix): array
    {
        $wordset_id = $this->ensureTerm('wordset', ucwords(str_replace('-', ' ', $prefix)) . ' Wordset', $prefix . '-wordset');
        $category_id = $this->ensureTerm('word-category', ucwords(str_replace('-', ' ', $prefix)) . ' Category', $prefix . '-category');
        $recording_type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $source_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => ucwords(str_replace('-', ' ', $prefix)) . ' Source',
        ]);
        wp_set_object_terms($source_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($source_word_id, [$category_id], 'word-category', false);
        update_post_meta($source_word_id, 'word_translation', 'Source Translation');
        update_post_meta($source_word_id, 'word_english_meaning', 'Source Translation');

        $target_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => ucwords(str_replace('-', ' ', $prefix)) . ' Target',
        ]);
        wp_set_object_terms($target_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($target_word_id, [$category_id], 'word-category', false);
        update_post_meta($target_word_id, 'word_translation', 'Target Translation');
        update_post_meta($target_word_id, 'word_english_meaning', 'Target Translation');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $source_word_id,
            'post_title' => ucwords(str_replace('-', ' ', $prefix)) . ' Recording',
        ]);
        wp_set_object_terms($recording_id, [$recording_type_id], 'recording_type', false);
        update_post_meta($recording_id, 'audio_file_path', '/wp-content/uploads/' . $prefix . '.wav');
        update_post_meta($recording_id, 'recording_text', 'Recording text');
        update_post_meta($recording_id, 'recording_translation', 'Recording translation');

        return [
            'wordset_id' => (int) $wordset_id,
            'category_id' => (int) $category_id,
            'source_word_id' => (int) $source_word_id,
            'target_word_id' => (int) $target_word_id,
            'recording_id' => (int) $recording_id,
        ];
    }

    private function loginEditor(): void
    {
        if (function_exists('ll_create_ll_tools_editor_role')) {
            ll_create_ll_tools_editor_role();
        }
        $editor_role = get_role('ll_tools_editor') ? 'll_tools_editor' : 'administrator';
        $editor_id = self::factory()->user->create(['role' => $editor_role]);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($created);

        return (int) ($created['term_id'] ?? 0);
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
        $this->assertIsArray($decoded, 'Expected JSON response payload. Raw output: ' . $output);

        return $decoded;
    }
}
