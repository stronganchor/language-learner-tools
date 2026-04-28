<?php
declare(strict_types=1);

final class WordsetEditorToolTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_wordset_editor_tool_renders_searchable_filterable_word_table(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-render');
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [
            'll_wordset_tool' => 'editor',
            'll_editor_q' => 'Alpha Translation',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('data-ll-wordset-editor', $html);
        $this->assertStringContainsString('name="ll_editor_q"', $html);
        $this->assertStringContainsString('name="ll_editor_category"', $html);
        $this->assertStringContainsString('name="ll_editor_status"', $html);
        $this->assertStringContainsString('name="ll_editor_image"', $html);
        $this->assertStringContainsString('name="ll_editor_recording"', $html);
        $this->assertStringContainsString('Alpha Word', $html);
        $this->assertStringContainsString('Alpha Translation', $html);
        $this->assertStringNotContainsString('Beta Word', $html);
        $this->assertStringContainsString('ll_wordset_manager_editor_action', $html);
        $this->assertStringContainsString('Recent actions', $html);
    }

    public function test_bulk_category_move_logs_undo_and_restores_previous_category(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-category');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'move_category',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_ids' => [(string) $fixture['alpha_word_id']],
            'll_wordset_editor_target_category' => (string) $fixture['category_b_id'],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $move_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $move_query = $this->parseRedirectQuery($move_redirect);
        $this->assertSame('ok', (string) ($move_query['ll_wordset_manager_editor'] ?? ''));
        $this->assertContains((int) $fixture['category_b_id'], array_map('intval', wp_get_post_terms((int) $fixture['alpha_word_id'], 'word-category', ['fields' => 'ids'])));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertCount(1, $recent);
        $this->assertSame('bulk_categories', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $category_ids = array_map('intval', wp_get_post_terms((int) $fixture['alpha_word_id'], 'word-category', ['fields' => 'ids']));
        $this->assertContains((int) $fixture['category_a_id'], $category_ids);
        $this->assertNotContains((int) $fixture['category_b_id'], $category_ids);
    }

    public function test_missing_audio_review_adds_internal_note_and_is_undoable(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-review');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'missing_audio_review',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_ids' => [(string) $fixture['beta_word_id']],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $review_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $review_query = $this->parseRedirectQuery($review_redirect);
        $this->assertSame('missing_audio_review', (string) ($review_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertStringContainsString('Missing audio review', ll_tools_get_internal_review_note((int) $fixture['beta_word_id']));
        $this->assertSame('draft', get_post_status((int) $fixture['beta_word_id']));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertSame('bulk_missing_audio_review', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];

        $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $this->assertSame('', ll_tools_get_internal_review_note((int) $fixture['beta_word_id']));
    }

    /**
     * @return array{wordset_id:int,category_a_id:int,category_b_id:int,alpha_word_id:int,beta_word_id:int}
     */
    private function createFixture(string $prefix): array
    {
        $wordset = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' Wordset', 'wordset', ['slug' => $prefix . '-wordset']);
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) ($wordset['term_id'] ?? 0);

        $category_a = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' A', 'word-category', ['slug' => $prefix . '-a']);
        $category_b = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' B', 'word-category', ['slug' => $prefix . '-b']);
        $this->assertFalse(is_wp_error($category_a));
        $this->assertFalse(is_wp_error($category_b));
        $category_a_id = (int) ($category_a['term_id'] ?? 0);
        $category_b_id = (int) ($category_b['term_id'] ?? 0);

        foreach ([$category_a_id, $category_b_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
            update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
            if (function_exists('ll_tools_set_category_wordset_owner')) {
                ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
            }
        }

        $recording_type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        $alpha_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Alpha Word',
        ]);
        wp_set_object_terms($alpha_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($alpha_word_id, [$category_a_id], 'word-category', false);
        update_post_meta($alpha_word_id, 'word_translation', 'Alpha Translation');

        $alpha_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $alpha_word_id,
            'post_title' => 'Alpha Recording',
        ]);
        wp_set_object_terms($alpha_recording_id, [$recording_type_id], 'recording_type', false);
        update_post_meta($alpha_recording_id, 'audio_file_path', '/wp-content/uploads/' . $prefix . '-alpha.wav');
        wp_update_post([
            'ID' => $alpha_word_id,
            'post_status' => 'publish',
        ]);

        $beta_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Beta Word',
        ]);
        wp_set_object_terms($beta_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($beta_word_id, [$category_a_id], 'word-category', false);
        update_post_meta($beta_word_id, 'word_translation', 'Beta Translation');

        return [
            'wordset_id' => $wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'alpha_word_id' => (int) $alpha_word_id,
            'beta_word_id' => (int) $beta_word_id,
        ];
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        return (int) ($created['term_id'] ?? 0);
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return array_map('strval', $parsed);
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
}
