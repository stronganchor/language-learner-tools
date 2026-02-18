<?php
declare(strict_types=1);

final class RecordingWorkflowTest extends LL_Tools_TestCase
{
    public function test_prepare_new_word_recording_creates_word_and_category(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        update_user_meta($user_id, 'll_recording_config', [
            'allow_new_words' => '1',
        ]);
        wp_set_current_user($user_id);

        $wordset_id = $this->ensure_term('wordset', 'Primary Flow Wordset', 'primary-flow-wordset');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $this->ensure_term('recording_type', 'Question', 'question');

        $nonce = wp_create_nonce('ll_upload_recording');

        $_POST = [
            'nonce'                => $nonce,
            'word_text_target'     => 'Merhaba',
            'word_text_translation'=> 'Hello',
            'create_category'      => '1',
            'new_category_name'    => 'Primary Flow New Category',
            'new_category_types'   => ['isolation', 'question'],
            'wordset_ids'          => wp_json_encode([$wordset_id]),
            'include_types'        => '',
            'exclude_types'        => '',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_prepare_new_word_recording_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);

        $data = $response['data'];
        $word_data = $data['word'] ?? [];
        $word_id = (int) ($word_data['word_id'] ?? 0);
        $this->assertGreaterThan(0, $word_id);

        $word = get_post($word_id);
        $this->assertInstanceOf(WP_Post::class, $word);
        $this->assertSame('words', $word->post_type);
        $this->assertSame('draft', $word->post_status);
        $this->assertSame('Merhaba', $word->post_title);
        $this->assertSame('Hello', (string) get_post_meta($word_id, 'word_translation', true));

        $category = get_term_by('name', 'Primary Flow New Category', 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $word_categories = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        $this->assertContains((int) $category->term_id, array_map('intval', (array) $word_categories));

        $word_wordsets = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
        $this->assertContains($wordset_id, array_map('intval', (array) $word_wordsets));

        $recording_types = array_map(static function ($entry): string {
            return is_array($entry) && isset($entry['slug']) ? (string) $entry['slug'] : '';
        }, (array) ($data['recording_types'] ?? []));

        $this->assertContains('isolation', $recording_types);
        $this->assertContains('question', $recording_types);
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
}
