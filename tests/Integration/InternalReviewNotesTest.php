<?php
declare(strict_types=1);

final class InternalReviewNotesTest extends LL_Tools_TestCase
{
    public function test_internal_review_note_field_collapses_when_empty_and_opens_when_populated(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Compact note',
        ]);

        $empty_output = ll_tools_render_internal_review_note_field($word_id, 'word', 0);
        $this->assertStringContainsString('<details', $empty_output);
        $this->assertStringContainsString('data-ll-internal-review-note-summary', $empty_output);
        $this->assertStringContainsString('Add internal review note', $empty_output);
        $this->assertStringNotContainsString(' open', $empty_output);

        update_post_meta($word_id, ll_tools_internal_review_note_meta_key(), 'Already needs review.');
        $filled_output = ll_tools_render_internal_review_note_field($word_id, 'word', 0);
        $this->assertStringContainsString(' open', $filled_output);
        $this->assertStringContainsString('Already needs review.', $filled_output);
    }

    public function test_lesson_word_grid_shows_internal_review_note_only_to_ll_tools_staff(): void
    {
        $wordset_id = $this->createTerm('wordset', 'Internal Notes Wordset', 'internal-notes-wordset');
        $category_id = $this->createTerm('word-category', 'Internal Notes Category', 'internal-notes-category');
        $recording_type_id = $this->createTerm('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = $this->createWord($wordset_id, $category_id, 'Terminus', 'Boundary');
        $this->createAudio($word_id, $recording_type_id, 'audio/internal-notes-terminus.mp3');
        update_post_meta($word_id, ll_tools_internal_review_note_meta_key(), 'Split this into two cards.');

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            wp_set_current_user(0);
            $public_output = do_shortcode('[word_grid category="internal-notes-category" wordset="internal-notes-wordset"]');
            $this->assertStringNotContainsString('data-ll-internal-review-note', $public_output);
            $this->assertStringNotContainsString('Split this into two cards.', $public_output);

            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            $admin = get_user_by('id', $admin_id);
            $this->assertInstanceOf(WP_User::class, $admin);
            $admin->add_cap('view_ll_tools');
            clean_user_cache($admin_id);
            wp_set_current_user($admin_id);

            $staff_output = do_shortcode('[word_grid category="internal-notes-category" wordset="internal-notes-wordset"]');
            $this->assertStringContainsString('data-ll-internal-review-note', $staff_output);
            $this->assertStringContainsString('Internal review note', $staff_output);
            $this->assertStringContainsString('Split this into two cards.', $staff_output);
            $recordings_position = strpos($staff_output, 'class="ll-word-recordings');
            $note_position = strpos($staff_output, 'data-ll-internal-review-note');
            $this->assertIsInt($recordings_position);
            $this->assertIsInt($note_position);
            $this->assertGreaterThan($recordings_position, $note_position);
        } finally {
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
            remove_filter('wp_doing_ajax', $ajax_filter);
            wp_set_current_user(0);
        }
    }

    public function test_prompt_card_grid_renders_internal_review_note_for_staff_only(): void
    {
        $wordset_id = $this->createTerm('wordset', 'Prompt Internal Notes Wordset', 'prompt-internal-notes-wordset');
        $category_id = $this->createTerm('word-category', 'Prompt Internal Notes Category', 'prompt-internal-notes-category');
        $answer_id = $this->createWord($wordset_id, $category_id, 'Yes', 'Yes');
        $wrong_id = $this->createWord($wordset_id, $category_id, 'No', 'No');
        $prompt_card_id = $this->createPromptCard($wordset_id, $category_id, $answer_id, [$wrong_id]);
        update_post_meta($prompt_card_id, ll_tools_internal_review_note_meta_key(), 'Record a clearer prompt.');

        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        wp_set_current_user(0);
        $public_output = ll_tools_render_vocab_lesson_prompt_cards_grid($wordset_id, $category, 0);
        $this->assertStringNotContainsString('data-ll-internal-review-note', $public_output);
        $this->assertStringNotContainsString('Record a clearer prompt.', $public_output);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $staff_output = ll_tools_render_vocab_lesson_prompt_cards_grid($wordset_id, $category, 0);
        $this->assertStringContainsString('data-ll-internal-review-note', $staff_output);
        $this->assertStringContainsString('data-object-type="prompt_card"', $staff_output);
        $this->assertStringContainsString('Record a clearer prompt.', $staff_output);
    }

    public function test_ajax_internal_review_note_handler_saves_and_clears_prompt_card_note(): void
    {
        $wordset_id = $this->createTerm('wordset', 'AJAX Internal Notes Wordset', 'ajax-internal-notes-wordset');
        $category_id = $this->createTerm('word-category', 'AJAX Internal Notes Category', 'ajax-internal-notes-category');
        $answer_id = $this->createWord($wordset_id, $category_id, 'Yes', 'Yes');
        $wrong_id = $this->createWord($wordset_id, $category_id, 'No', 'No');
        $prompt_card_id = $this->createPromptCard($wordset_id, $category_id, $answer_id, [$wrong_id]);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $note_value = "Prompt image needs to show the final answer.\nThen clear this.";
        $_POST = [
            'nonce' => wp_create_nonce('ll_internal_review_note'),
            'object_type' => 'prompt_card',
            'object_id' => (string) $prompt_card_id,
            'wordset_id' => (string) $wordset_id,
            'note' => $note_value,
        ];
        $_REQUEST = $_POST;

        try {
            $save_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_save_internal_review_note_ajax_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($save_response['success']);
        $this->assertSame($note_value, (string) ($save_response['data']['note'] ?? ''));
        $this->assertSame($note_value, ll_tools_get_internal_review_note($prompt_card_id));

        $_POST = [
            'nonce' => wp_create_nonce('ll_internal_review_note'),
            'object_type' => 'prompt_card',
            'object_id' => (string) $prompt_card_id,
            'wordset_id' => (string) $wordset_id,
            'note' => '',
        ];
        $_REQUEST = $_POST;

        try {
            $clear_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_save_internal_review_note_ajax_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($clear_response['success']);
        $this->assertSame('', (string) ($clear_response['data']['note'] ?? ''));
        $this->assertSame('', ll_tools_get_internal_review_note($prompt_card_id));
    }

    public function test_ajax_internal_review_note_write_requires_wordset_management_access(): void
    {
        $wordset_id = $this->createTerm('wordset', 'AJAX Internal Notes Permission Wordset', 'ajax-internal-notes-permission-wordset');
        $category_id = $this->createTerm('word-category', 'AJAX Internal Notes Permission Category', 'ajax-internal-notes-permission-category');
        $word_id = $this->createWord($wordset_id, $category_id, 'Guarded note', 'Guarded translation');

        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);

        wp_set_current_user($viewer_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_internal_review_note'),
            'object_type' => 'word',
            'object_id' => (string) $word_id,
            'wordset_id' => (string) $wordset_id,
            'note' => 'View-only users must not save this.',
        ];
        $_REQUEST = $_POST;

        try {
            $blocked_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_save_internal_review_note_ajax_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse($blocked_response['success']);
        $this->assertSame('', ll_tools_get_internal_review_note($word_id));

        $manager_id = self::factory()->user->create(['role' => 'subscriber']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        clean_user_cache($manager_id);
        $this->assertTrue(function_exists('ll_tools_cli_assign_wordset_manager'));
        ll_tools_cli_assign_wordset_manager($wordset_id, $manager_id);

        wp_set_current_user($manager_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_internal_review_note'),
            'object_type' => 'word',
            'object_id' => (string) $word_id,
            'wordset_id' => (string) $wordset_id,
            'note' => 'Assigned manager can save this.',
        ];
        $_REQUEST = $_POST;

        try {
            $allowed_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_save_internal_review_note_ajax_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($allowed_response['success']);
        $this->assertSame('Assigned manager can save this.', ll_tools_get_internal_review_note($word_id));
    }

    public function test_text_document_review_notes_are_staff_only_and_save_by_line_key(): void
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Corpus Review Notes',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');

        wp_set_current_user(0);
        $public_field = ll_tools_text_document_render_review_note_field($lesson_id, 'line:l01', 'Line review note');
        $this->assertSame('', $public_field);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $empty_field = ll_tools_text_document_render_review_note_field($lesson_id, 'line:l01', 'Line review note');
        $this->assertStringContainsString('data-ll-text-document-review-note', $empty_field);
        $this->assertStringContainsString('data-note-key="line:l01"', $empty_field);
        $this->assertStringNotContainsString(' open', $empty_field);

        $_POST = [
            'nonce' => wp_create_nonce('ll_text_document_review_note'),
            'lesson_id' => (string) $lesson_id,
            'note_key' => 'line:l01',
            'note' => 'Check the dotted e in this line.',
        ];
        $_REQUEST = $_POST;

        try {
            $save_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_save_text_document_review_note_ajax_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($save_response['success']);
        $this->assertSame('Check the dotted e in this line.', ll_tools_get_text_document_review_note($lesson_id, 'line:l01'));

        $filled_field = ll_tools_text_document_render_review_note_field($lesson_id, 'line:l01', 'Line review note');
        $this->assertStringContainsString(' open', $filled_field);
        $this->assertStringContainsString('Check the dotted e in this line.', $filled_field);
    }

    private function createTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $term = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        return (int) $term['term_id'];
    }

    private function createWord(int $wordset_id, int $category_id, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return (int) $word_id;
    }

    private function createAudio(int $word_id, int $recording_type_id, string $audio_path): int
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Internal Notes Recording',
        ]);
        update_post_meta($audio_id, 'audio_file_path', $audio_path);
        wp_set_post_terms($audio_id, [$recording_type_id], 'recording_type', false);

        return (int) $audio_id;
    }

    /**
     * @param int[] $wrong_answer_ids
     */
    private function createPromptCard(int $wordset_id, int $category_id, int $answer_id, array $wrong_answer_ids): int
    {
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt Card Note Test',
        ]);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Is this correct?');
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, $answer_id);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', $wrong_answer_ids)));
        wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);

        return (int) $prompt_card_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
