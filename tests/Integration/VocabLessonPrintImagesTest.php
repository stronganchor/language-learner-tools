<?php
declare(strict_types=1);

final class VocabLessonPrintImagesTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8Bf6kAAAAASUVORK5CYII=';

    public function test_print_permission_allows_editor_roles_but_not_wordset_manager(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'editor']);
        $ll_tools_editor_id = self::factory()->user->create(['role' => 'll_tools_editor']);
        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);

        wp_set_current_user($editor_id);
        $this->assertTrue(ll_tools_user_can_print_vocab_lesson_images());

        wp_set_current_user($ll_tools_editor_id);
        $this->assertTrue(ll_tools_user_can_print_vocab_lesson_images());

        wp_set_current_user($manager_id);
        $this->assertFalse(ll_tools_user_can_print_vocab_lesson_images());
    }

    public function test_print_url_nonce_verifies_for_authorized_request(): void
    {
        $wordset = wp_insert_term('Print URL Wordset', 'wordset', ['slug' => 'print-url-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Print URL Category', 'word-category', ['slug' => 'print-url-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $lesson_id = $this->createLesson('Print URL Lesson', $wordset_id, $category_id);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $url = ll_tools_get_vocab_lesson_image_print_url($lesson_id);
        $this->assertNotSame('', $url);

        $query = [];
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $original_get = $_GET;
        $_GET = $query;

        try {
            $this->assertTrue(ll_tools_is_vocab_lesson_image_print_request());
            $this->assertTrue(ll_tools_verify_vocab_lesson_image_print_request($lesson_id));
        } finally {
            $_GET = $original_get;
        }
    }

    public function test_print_items_include_only_imaged_words_for_the_lesson_scope_in_natural_order(): void
    {
        $wordset = wp_insert_term('Print Items Wordset', 'wordset', ['slug' => 'print-items-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $other_wordset = wp_insert_term('Other Print Items Wordset', 'wordset', ['slug' => 'other-print-items-wordset']);
        $this->assertIsArray($other_wordset);
        $other_wordset_id = (int) $other_wordset['term_id'];

        $parent = wp_insert_term('Print Items Parent', 'word-category', ['slug' => 'print-items-parent']);
        $this->assertIsArray($parent);
        $parent_id = (int) $parent['term_id'];

        $child = wp_insert_term('Print Items Child', 'word-category', [
            'slug' => 'print-items-child',
            'parent' => $parent_id,
        ]);
        $this->assertIsArray($child);
        $child_id = (int) $child['term_id'];

        $first_child_word_id = $this->createWordWithThumbnail('Lesson 10', $child_id, $wordset_id, 'print-items-10.png');
        wp_set_post_terms($first_child_word_id, [$parent_id, $child_id], 'word-category', false);

        $second_child_word_id = $this->createWordWithThumbnail('Lesson 2', $child_id, $wordset_id, 'print-items-2.png');
        wp_set_post_terms($second_child_word_id, [$parent_id, $child_id], 'word-category', false);

        $parent_only_word_id = $this->createWordWithThumbnail('Lesson 1', $parent_id, $wordset_id, 'print-items-parent.png');
        wp_set_post_terms($parent_only_word_id, [$parent_id], 'word-category', false);

        $no_image_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Lesson 3',
        ]);
        wp_set_post_terms($no_image_word_id, [$parent_id, $child_id], 'word-category', false);
        wp_set_post_terms($no_image_word_id, [$wordset_id], 'wordset', false);

        $other_wordset_word_id = $this->createWordWithThumbnail('Lesson 4', $child_id, $other_wordset_id, 'print-items-other-wordset.png');
        wp_set_post_terms($other_wordset_word_id, [$parent_id, $child_id], 'word-category', false);

        $items = ll_tools_get_vocab_lesson_print_items($wordset_id, $child_id);
        $labels = array_map(static function (array $item): string {
            return (string) ($item['label'] ?? '');
        }, $items);
        $word_ids = array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, $items);

        $this->assertSame(['Lesson 2', 'Lesson 10'], $labels);
        $this->assertSame([$second_child_word_id, $first_child_word_id], $word_ids);
    }

    private function createLesson(string $title, int $wordset_id, int $category_id): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        return (int) $lesson_id;
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
}
