<?php
declare(strict_types=1);

final class WordsetPageLazyCardsAjaxTest extends LL_Tools_TestCase
{
    public function test_ajax_rebuilds_lazy_cards_when_cached_payload_is_missing(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];

        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = [
            'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
            'token' => 'missing-token',
            'wordset_id' => $wordset_id,
            'preview_limit' => 2,
            'offset' => 1,
            'count' => 1,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }

        $this->assertArrayHasKey('success', $response);
        $this->assertTrue((bool) $response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertIsArray($response['data']);

        $data = $response['data'];
        $this->assertSame(2, (int) ($data['loaded'] ?? 0));
        $this->assertSame(2, (int) ($data['nextOffset'] ?? 0));
        $this->assertFalse((bool) ($data['hasMore'] ?? true));
        $this->assertStringContainsString('Lazy Ajax Category B', (string) ($data['html'] ?? ''));
    }

    /**
     * @return array{wordset_id:int}
     */
    private function createWordsetFixture(): array
    {
        $wordset = wp_insert_term('Lazy Ajax Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_a_term = wp_insert_term('Lazy Ajax Category A ' . wp_generate_password(6, false), 'word-category');
        $category_b_term = wp_insert_term('Lazy Ajax Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_a_term));
        $this->assertFalse(is_wp_error($category_b_term));
        $this->assertIsArray($category_a_term);
        $this->assertIsArray($category_b_term);

        $category_a_id = (int) $category_a_term['term_id'];
        $category_b_id = (int) $category_b_term['term_id'];

        update_term_meta($category_a_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_a_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($category_b_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_b_id, 'll_quiz_option_type', 'text_title');

        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                'Lazy Ajax A Word ' . $index,
                'Lazy Ajax A Translation ' . $index,
                $category_a_id,
                $wordset_id,
                'lazy-ajax-a-' . $index . '.mp3'
            );
            $this->createWordWithAudio(
                'Lazy Ajax B Word ' . $index,
                'Lazy Ajax B Translation ' . $index,
                $category_b_id,
                $wordset_id,
                'lazy-ajax-b-' . $index . '.mp3'
            );
        }

        $category_a_effective_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_a_id, $wordset_id, true)
            : $category_a_id;
        $category_b_effective_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_b_id, $wordset_id, true)
            : $category_b_id;

        $lesson_a_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lazy Ajax Lesson A ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_a_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_a_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_a_effective_id);

        $lesson_b_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lazy Ajax Lesson B ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_b_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_b_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_b_effective_id);

        return [
            'wordset_id' => $wordset_id,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
