<?php
declare(strict_types=1);

final class AudioUploadFormScopeTest extends LL_Tools_TestCase
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
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_audio_upload_form_locks_to_only_accessible_wordset_for_manager(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensureTerm('wordset', 'Audio Manager Scope', 'audio-manager-scope');
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        update_term_meta($wordset_id, 'manager_user_id', $user_id);
        wp_set_current_user($user_id);

        $html = ll_audio_upload_form_shortcode();

        $this->assertStringContainsString('name="ll_single_wordset_id" value="' . $wordset_id . '"', $html);
        $this->assertStringContainsString('Only accessible word set', $html);
        $this->assertStringNotContainsString('name="ll_multi_wordset_ids[]"', $html);
    }

    public function test_audio_upload_form_dedupes_isolated_categories_into_single_logical_option(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensureTerm('wordset', 'Audio Upload Scope One', 'audio-upload-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Audio Upload Scope Two', 'audio-upload-scope-two');
        $shared_category_id = $this->ensureTerm('word-category', 'Shared Audio Trees', 'shared-audio-trees');

        $this->createWordInScope('Audio Upload Tree One', $wordset_one_id, $shared_category_id);
        $this->createWordInScope('Audio Upload Tree Two', $wordset_two_id, $shared_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_one_id = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_two_id);

        $html = ll_audio_upload_form_shortcode();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="' . preg_quote((string) $shared_category_id, '/') . '"[^>]+data-ll-category-wordsets="[^"]*' . preg_quote((string) $wordset_one_id, '/') . '[^"]*' . preg_quote((string) $wordset_two_id, '/') . '[^"]*"[^>]*>\s*Shared Audio Trees\s*<\/option>/',
            $html
        );
        $this->assertSame(2, preg_match_all('/<option[^>]*>\s*Shared Audio Trees\s*<\/option>/', $html, $matches));
        $this->assertStringNotContainsString('value="' . $isolated_one_id . '"', $html);
        $this->assertStringNotContainsString('value="' . $isolated_two_id . '"', $html);
    }

    public function test_create_new_word_post_assigns_multiple_wordsets_and_isolated_categories(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensureTerm('wordset', 'Audio Create Scope One', 'audio-create-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Audio Create Scope Two', 'audio-create-scope-two');
        $shared_category_id = $this->ensureTerm('word-category', 'Audio Shared Plants', 'audio-shared-plants');

        $word_id = ll_create_new_word_post(
            'Audio Multi Scope Word',
            '/wp-content/uploads/2026/04/audio-multi-scope-word.mp3',
            [
                'll_wordset_scope_mode' => 'multiple',
                'll_multi_wordset_ids' => [(string) $wordset_one_id, (string) $wordset_two_id],
                'll_recording_type' => 'isolation',
            ],
            [$shared_category_id],
            wp_upload_dir()
        );

        $this->assertIsInt($word_id);

        $assigned_wordset_ids = wp_get_post_terms((int) $word_id, 'wordset', ['fields' => 'ids']);
        $assigned_wordset_ids = array_values(array_map('intval', (array) $assigned_wordset_ids));
        sort($assigned_wordset_ids, SORT_NUMERIC);
        $this->assertSame([$wordset_one_id, $wordset_two_id], $assigned_wordset_ids);

        $expected_category_ids = ll_tools_get_isolated_category_ids_for_wordsets([$shared_category_id], [$wordset_one_id, $wordset_two_id]);
        sort($expected_category_ids, SORT_NUMERIC);

        $assigned_category_ids = wp_get_post_terms((int) $word_id, 'word-category', ['fields' => 'ids']);
        $assigned_category_ids = array_values(array_map('intval', (array) $assigned_category_ids));
        sort($assigned_category_ids, SORT_NUMERIC);

        $this->assertSame($expected_category_ids, $assigned_category_ids);
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }

    private function createWordInScope(string $title, int $wordset_id, int $category_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }
}
