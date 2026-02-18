<?php
declare(strict_types=1);

final class WordGridWordNotesTest extends LL_Tools_TestCase
{
    public function test_word_grid_renders_saved_word_note(): void
    {
        $category = wp_insert_term('Word Grid Notes Category', 'word-category', ['slug' => 'word-grid-notes-category']);
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Terminus',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', 'Boundary');
        update_post_meta($word_id, 'll_word_usage_note', 'Used mostly in formal contexts.');

        $output = do_shortcode('[word_grid category="word-grid-notes-category"]');

        $this->assertStringContainsString('data-ll-word-note', $output);
        $this->assertStringContainsString('Used mostly in formal contexts.', $output);
    }

    public function test_ajax_word_update_saves_and_clears_word_note(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Terminus',
        ]);
        update_post_meta($word_id, 'word_translation', 'Boundary');

        $nonce = wp_create_nonce('ll_word_grid_edit');
        $note_value = "Used in legal writing only.\nAvoid in casual speech.";

        $_POST = [
            'nonce'            => $nonce,
            'word_id'          => $word_id,
            'word_text'        => 'Terminus',
            'word_translation' => 'Boundary',
            'word_note'        => $note_value,
        ];
        $_REQUEST = $_POST;

        try {
            $save_response = $this->run_json_endpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($save_response['success']);
        $this->assertSame($note_value, (string) ($save_response['data']['word_note'] ?? ''));
        $this->assertSame($note_value, (string) get_post_meta($word_id, 'll_word_usage_note', true));

        $_POST = [
            'nonce'            => $nonce,
            'word_id'          => $word_id,
            'word_text'        => 'Terminus',
            'word_translation' => 'Boundary',
            'word_note'        => '',
        ];
        $_REQUEST = $_POST;

        try {
            $clear_response = $this->run_json_endpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($clear_response['success']);
        $this->assertSame('', (string) ($clear_response['data']['word_note'] ?? ''));
        $this->assertSame('', (string) get_post_meta($word_id, 'll_word_usage_note', true));
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
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
