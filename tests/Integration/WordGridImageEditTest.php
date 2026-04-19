<?php
declare(strict_types=1);

final class WordGridImageEditTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $filesBackup = [];

    /** @var array<int,string> */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->filesBackup = $_FILES;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;

        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function test_ajax_word_update_replaces_linked_word_image_and_avoids_new_recorder_prompt(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'administrator']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);

        $wordset_id = $this->ensureTerm('wordset', 'Image Edit Wordset', 'image-edit-wordset');
        $other_wordset_id = $this->ensureTerm('wordset', 'Image Edit Other Wordset', 'image-edit-other-wordset');
        $category_id = $this->ensureTerm('word-category', 'Image Edit Category', 'image-edit-category');

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        update_term_meta($category_id, 'll_desired_recording_types', ['question', 'isolation', 'introduction']);

        $recording_type_ids = [
            'question' => $this->ensureTerm('recording_type', 'Question', 'question'),
            'isolation' => $this->ensureTerm('recording_type', 'Isolation', 'isolation'),
            'introduction' => $this->ensureTerm('recording_type', 'Introduction', 'introduction'),
        ];

        $old_attachment_id = $this->createImageAttachment('word-grid-image-old.png');

        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Image Edit Word',
        ]);
        wp_set_post_terms($word_image_id, [$category_id], 'word-category', false);
        set_post_thumbnail($word_image_id, $old_attachment_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Image Edit Word',
            'post_author' => $editor_id,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $old_attachment_id);
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        update_post_meta($word_id, 'word_translation', 'Edited Image');
        update_post_meta($word_id, 'word_english_meaning', 'Edited Image');

        $related_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Image Edit Related Word',
        ]);
        wp_set_post_terms($related_word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($related_word_id, [$other_wordset_id], 'wordset', false);
        set_post_thumbnail($related_word_id, $old_attachment_id);

        foreach (['question', 'isolation', 'introduction'] as $recording_type_slug) {
            $recording_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_author' => $editor_id,
                'post_title' => 'Image Edit ' . ucfirst($recording_type_slug),
            ]);
            wp_set_object_terms($recording_id, [$recording_type_ids[$recording_type_slug]], 'recording_type', false);
        }

        $upload_path = $this->createTemporaryUpload('word-grid-image-new.png');
        $nonce = wp_create_nonce('ll_word_grid_edit');
        $_POST = [
            'nonce' => $nonce,
            'word_id' => $word_id,
            'word_text' => 'Image Edit Word',
            'word_translation' => 'Edited Image',
            'wordset_id' => $wordset_id,
        ];
        $_REQUEST = $_POST;
        $_FILES = [
            'word_image_file' => [
                'name' => 'word-grid-image-new.png',
                'type' => 'image/png',
                'tmp_name' => $upload_path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($upload_path),
            ],
        ];

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_update_word_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));

        $effective_word_image_id = $this->resolveEffectiveWordImageId($word_image_id, $wordset_id);

        $new_attachment_id = (int) get_post_thumbnail_id($effective_word_image_id);
        $this->assertGreaterThan(0, $new_attachment_id);
        $this->assertNotSame($old_attachment_id, $new_attachment_id);
        $this->assertSame($new_attachment_id, (int) get_post_thumbnail_id($word_id));
        if ($effective_word_image_id !== $word_image_id) {
            $this->assertSame($old_attachment_id, (int) get_post_thumbnail_id($word_image_id));
            $this->assertSame($old_attachment_id, (int) get_post_thumbnail_id($related_word_id));
        } else {
            $this->assertSame($new_attachment_id, (int) get_post_thumbnail_id($related_word_id));
        }
        $this->assertSame($effective_word_image_id, (int) get_post_meta($word_id, '_ll_autopicked_image_id', true));

        $image_payload = (array) (($response['data'] ?? [])['image'] ?? []);
        $this->assertSame($new_attachment_id, (int) ($image_payload['id'] ?? 0));
        $this->assertNotSame('', (string) ($image_payload['url'] ?? ''));

        $recorder_items = ll_get_images_needing_audio('image-edit-category', [$wordset_id], '', '');
        foreach ((array) $recorder_items as $item) {
            $this->assertNotSame($word_id, (int) ($item['word_id'] ?? 0));
        }
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($result);

        return (int) ($result['term_id'] ?? 0);
    }

    private function resolveEffectiveWordImageId(int $wordImageId, int $wordsetId): int
    {
        if (
            function_exists('ll_tools_get_effective_word_image_id_for_wordset')
            && function_exists('ll_tools_is_wordset_isolation_enabled')
            && ll_tools_is_wordset_isolation_enabled()
        ) {
            return (int) ll_tools_get_effective_word_image_id_for_wordset($wordImageId, $wordsetId);
        }

        return $wordImageId;
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

    private function createTemporaryUpload(string $filename): string
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $path = trailingslashit(sys_get_temp_dir()) . wp_generate_password(12, false, false) . '-' . sanitize_file_name($filename);
        $written = file_put_contents($path, $bytes);
        $this->assertNotFalse($written);
        $this->assertFileExists($path);
        $this->tempFiles[] = $path;

        return $path;
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
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
