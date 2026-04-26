<?php
declare(strict_types=1);

final class VocabLessonWordOrderTest extends LL_Tools_TestCase
{
    public function test_saved_lesson_word_order_overrides_default_title_sort_in_ajax_grid(): void
    {
        [$wordset_id, $category_id] = $this->createLessonTerms('Lesson Order Grid');

        $word_beta = $this->createWord($wordset_id, $category_id, 'Beta');
        $word_gamma = $this->createWord($wordset_id, $category_id, 'Gamma');
        $word_alpha = $this->createWord($wordset_id, $category_id, 'Alpha');

        $lesson_id = $this->createLesson($wordset_id, $category_id, 'Manual Order Lesson');
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META, [$word_gamma, $word_alpha, $word_beta]);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $html = (string) (($response['data'] ?? [])['html'] ?? '');

        $position_gamma = strpos($html, 'Gamma');
        $position_alpha = strpos($html, 'Alpha');
        $position_beta = strpos($html, 'Beta');

        $this->assertIsInt($position_gamma);
        $this->assertIsInt($position_alpha);
        $this->assertIsInt($position_beta);
        $this->assertLessThan($position_alpha, $position_gamma);
        $this->assertLessThan($position_beta, $position_alpha);
    }

    public function test_save_lesson_order_handler_sanitizes_duplicates_and_keeps_missing_words(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        [$wordset_id, $category_id] = $this->createLessonTerms('Lesson Order Save');

        $word_one = $this->createWord($wordset_id, $category_id, 'One');
        $word_two = $this->createWord($wordset_id, $category_id, 'Two');
        $word_three = $this->createWord($wordset_id, $category_id, 'Three');

        $lesson_id = $this->createLesson($wordset_id, $category_id, 'Save Order Lesson');

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'lesson_id' => $lesson_id,
            'order' => [$word_three, $word_one, $word_three, 999999],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_save_lesson_order_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $saved_order = array_values(array_map('intval', (array) (($response['data'] ?? [])['order'] ?? [])));

        $this->assertSame([$word_three, $word_one, $word_two], $saved_order);
        $this->assertSame(
            [$word_three, $word_one, $word_two],
            array_values(array_map('intval', (array) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META, true)))
        );
    }

    public function test_save_lesson_order_handler_preserves_draft_words_for_staff_refresh(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        [$wordset_id, $category_id] = $this->createLessonTerms('Lesson Draft Order Save');

        $word_one = $this->createWord($wordset_id, $category_id, 'One');
        $draft_word = $this->createWord($wordset_id, $category_id, 'Draft Middle', 'draft');
        $word_two = $this->createWord($wordset_id, $category_id, 'Two');

        $lesson_id = $this->createLesson($wordset_id, $category_id, 'Save Draft Order Lesson');

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'lesson_id' => $lesson_id,
            'order' => [$word_two, $draft_word, $word_one],
        ];
        $_REQUEST = $_POST;

        try {
            $save_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_save_lesson_order_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($save_response['success'] ?? false), wp_json_encode($save_response));
        $this->assertSame(
            [$word_two, $draft_word, $word_one],
            array_values(array_map('intval', (array) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META, true)))
        );

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $grid_response = $this->runJsonEndpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($grid_response['success'] ?? false), wp_json_encode($grid_response));
        $html = (string) (($grid_response['data'] ?? [])['html'] ?? '');

        $position_two = strpos($html, 'Two');
        $position_draft = strpos($html, 'Draft Middle');
        $position_one = strpos($html, 'One');

        $this->assertIsInt($position_two);
        $this->assertIsInt($position_draft);
        $this->assertIsInt($position_one);
        $this->assertLessThan($position_draft, $position_two);
        $this->assertLessThan($position_one, $position_draft);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createLessonTerms(string $label): array
    {
        $wordset = wp_insert_term($label . ' Wordset', 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term($label . ' Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        return [$wordset_id, $category_id];
    }

    private function createWord(int $wordset_id, int $category_id, string $title, string $status = 'publish'): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => $status,
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', $title . ' translation');
        return $word_id;
    }

    private function createLesson(int $wordset_id, int $category_id, string $title): int
    {
        $effective_category_id = $category_id;
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved_category_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved_category_id > 0) {
                $effective_category_id = $resolved_category_id;
            }
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $effective_category_id);
        return $lesson_id;
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
        $this->assertIsArray($decoded, 'Expected JSON output from AJAX handler.');

        return $decoded;
    }
}
