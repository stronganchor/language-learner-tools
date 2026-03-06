<?php
declare(strict_types=1);

final class VocabLessonDeferredGridTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8Bf6kAAAAASUVORK5CYII=';

    public function test_lesson_grid_ajax_returns_rendered_word_grid_markup(): void
    {
        $wordset = wp_insert_term('Deferred Grid Wordset', 'wordset', ['slug' => 'deferred-grid-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Grid Category', 'word-category', ['slug' => 'deferred-grid-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Nehir',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'River');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('data-ll-word-grid', $html);
        $this->assertStringContainsString('Nehir', $html);
        $this->assertStringContainsString('River', $html);
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

    public function test_shell_spec_defaults_skeleton_media_to_square_when_no_aspect_ratio_is_known(): void
    {
        $wordset = wp_insert_term('Square Shell Wordset', 'wordset', ['slug' => 'square-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Square Shell Category', 'word-category', ['slug' => 'square-shell-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'square-shell-category',
            'wordset' => 'square-shell-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $style = (string) (($spec['attributes']['style'] ?? ''));

        $this->assertStringContainsString('--ll-word-grid-shell-image-aspect:1 / 1;', $style);
    }

    public function test_shell_spec_uses_known_image_aspect_ratio_when_a_thumbnail_is_available(): void
    {
        $wordset = wp_insert_term('Ratio Shell Wordset', 'wordset', ['slug' => 'ratio-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Ratio Shell Category', 'word-category', ['slug' => 'ratio-shell-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $attachment_id = $this->createImageAttachment('ratio-shell.png');
        wp_update_attachment_metadata($attachment_id, [
            'width' => 400,
            'height' => 300,
            'file' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
        ]);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ratio Shell Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'ratio-shell-category',
            'wordset' => 'ratio-shell-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $style = (string) (($spec['attributes']['style'] ?? ''));

        $this->assertStringContainsString('--ll-word-grid-shell-image-aspect:4 / 3;', $style);
    }

    public function test_shell_spec_cards_follow_visible_recording_button_counts(): void
    {
        $wordset = wp_insert_term('Shell Card Count Wordset', 'wordset', ['slug' => 'shell-card-count-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Shell Card Count Category', 'word-category', ['slug' => 'shell-card-count-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $this->createRecordingType('question', 'Question');
        $this->createRecordingType('isolation', 'Isolation');

        $first_word_id = $this->createWordWithThumbnail('Shell Card One', $category_id, $wordset_id, 'shell-card-one.png');
        $second_word_id = $this->createWordWithThumbnail('Shell Card Two', $category_id, $wordset_id, 'shell-card-two.png');

        $this->createAudioRecording($first_word_id, 'question', 'shell-card-one-question.mp3');
        $this->createAudioRecording($second_word_id, 'question', 'shell-card-two-question.mp3');
        $this->createAudioRecording($second_word_id, 'isolation', 'shell-card-two-isolation.mp3');

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'shell-card-count-category',
            'wordset' => 'shell-card-count-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $cards = isset($spec['cards']) && is_array($spec['cards']) ? array_values($spec['cards']) : [];

        $this->assertCount(2, $cards);
        $recording_counts = array_map(static function (array $card): int {
            return (int) ($card['recording_count'] ?? 0);
        }, $cards);
        sort($recording_counts);
        $this->assertSame([1, 2], $recording_counts);
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    private function createWordWithThumbnail(string $title, int $category_id, int $wordset_id, string $image_filename): int
    {
        $attachment_id = $this->createImageAttachment($image_filename);
        wp_update_attachment_metadata($attachment_id, [
            'width' => 300,
            'height' => 300,
            'file' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
        ]);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    private function createAudioRecording(int $word_id, string $recording_type, string $audio_file_name): int
    {
        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $recording_type . ' ' . $word_id,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);
        wp_set_post_terms($audio_post_id, [$recording_type], 'recording_type', false);

        return (int) $audio_post_id;
    }

    private function createRecordingType(string $slug, string $label): void
    {
        $existing_term = get_term_by('slug', $slug, 'recording_type');
        if ($existing_term && !is_wp_error($existing_term)) {
            return;
        }

        $result = wp_insert_term($label, 'recording_type', ['slug' => $slug]);
        $this->assertIsArray($result);
    }
}
