<?php
declare(strict_types=1);

final class WordsetCategoryPreviewDedupTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';
    private const ALT_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_preview_does_not_repeat_identical_images_for_category_cards(): void
    {
        $wordset = wp_insert_term('Preview Dedup Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Preview Dedup Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $attachment_id = $this->createImageAttachment('preview-dedup-shared.png');
        $this->createWordWithThumbnail($category_id, $wordset_id, $attachment_id, 'Preview Dedup Word A');
        $this->createWordWithThumbnail($category_id, $wordset_id, $attachment_id, 'Preview Dedup Word B');

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $category_id, 2, true);
        $this->assertIsArray($preview);

        $items = array_values((array) ($preview['items'] ?? []));
        $image_items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && (($item['type'] ?? '') === 'image')
                && !empty($item['url']);
        }));
        $image_urls = array_values(array_filter(array_map(static function (array $item): string {
            return (string) ($item['url'] ?? '');
        }, $image_items)));
        $unique_image_urls = array_values(array_unique($image_urls));

        $this->assertNotEmpty($image_urls, 'Expected at least one image preview item.');
        $this->assertSame(
            count($unique_image_urls),
            count($image_urls),
            'Duplicate image URLs should not be returned for category previews.'
        );
        $this->assertSame(
            1,
            count($unique_image_urls),
            'A shared thumbnail should only appear once in the preview payload.'
        );
    }

    public function test_preview_skips_duplicate_content_from_separate_attachments_and_keeps_searching(): void
    {
        $wordset = wp_insert_term('Preview File Dedup Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Preview File Dedup Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $duplicate_attachment_a = $this->createImageAttachment('preview-dedup-file-a.png');
        $duplicate_attachment_b = $this->createImageAttachment('preview-dedup-file-b.png');
        $unique_attachment = $this->createImageAttachment('preview-dedup-file-c.png', self::ALT_PIXEL_PNG_BASE64);

        $this->createWordWithThumbnail($category_id, $wordset_id, $duplicate_attachment_a, 'Preview File Dedup Word A', '2026-01-01 00:00:03');
        $this->createWordWithThumbnail($category_id, $wordset_id, $duplicate_attachment_b, 'Preview File Dedup Word B', '2026-01-01 00:00:02');
        $this->createWordWithThumbnail($category_id, $wordset_id, $unique_attachment, 'Preview File Dedup Word C', '2026-01-01 00:00:01');

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $category_id, 2, true);
        $this->assertIsArray($preview);

        $image_urls = $this->extractPreviewImageUrls($preview);
        $this->assertCount(2, $image_urls, 'Expected two image preview items when a unique fallback image exists.');

        $duplicate_url_a = (string) wp_get_attachment_image_url($duplicate_attachment_a, 'medium');
        if ($duplicate_url_a === '') {
            $duplicate_url_a = (string) wp_get_attachment_url($duplicate_attachment_a);
        }
        $duplicate_url_b = (string) wp_get_attachment_image_url($duplicate_attachment_b, 'medium');
        if ($duplicate_url_b === '') {
            $duplicate_url_b = (string) wp_get_attachment_url($duplicate_attachment_b);
        }
        $unique_url = (string) wp_get_attachment_image_url($unique_attachment, 'medium');
        if ($unique_url === '') {
            $unique_url = (string) wp_get_attachment_url($unique_attachment);
        }

        $this->assertContains($unique_url, $image_urls, 'The preview should keep searching until it finds a non-duplicate image.');
        $duplicate_matches = array_values(array_intersect($image_urls, [$duplicate_url_a, $duplicate_url_b]));
        $this->assertCount(1, $duplicate_matches, 'Only one of the duplicate-content attachments should appear in the preview.');
    }

    private function createWordWithThumbnail(int $category_id, int $wordset_id, int $attachment_id, string $title, string $post_date = ''): int
    {
        $post_data = [
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ];
        if ($post_date !== '') {
            $post_data['post_date'] = $post_date;
            $post_data['post_date_gmt'] = $post_date;
        }

        $word_id = self::factory()->post->create($post_data);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    private function createImageAttachment(string $filename, string $base64 = self::ONE_PIXEL_PNG_BASE64): int
    {
        $bytes = base64_decode($base64, true);
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

    private function extractPreviewImageUrls(array $preview): array
    {
        $items = array_values((array) ($preview['items'] ?? []));
        $image_items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && (($item['type'] ?? '') === 'image')
                && !empty($item['url']);
        }));

        return array_values(array_filter(array_map(static function (array $item): string {
            return (string) ($item['url'] ?? '');
        }, $image_items)));
    }
}
