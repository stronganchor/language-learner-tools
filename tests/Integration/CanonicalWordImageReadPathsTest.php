<?php
declare(strict_types=1);

final class CanonicalWordImageReadPathsTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_linked_word_image_powers_frontend_and_editor_reads_without_word_thumbnail(): void
    {
        $wordset_id = $this->ensureTerm('wordset', 'Canonical Image Wordset', 'canonical-image-wordset');
        $category_id = $this->ensureTerm('word-category', 'Canonical Image Category', 'canonical-image-category');

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $attachment_id = $this->createImageAttachment('canonical-word-image.png');

        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Canonical Image Source',
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);
        wp_set_post_terms($word_image_id, [$category_id], 'word-category', false);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Canonical Image Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        update_post_meta($word_id, 'word_translation', 'Canonical image translation');
        delete_post_meta($word_id, '_thumbnail_id');

        $effective_word_image_id = (
            function_exists('ll_tools_get_effective_word_image_id_for_wordset')
            && function_exists('ll_tools_is_wordset_isolation_enabled')
            && ll_tools_is_wordset_isolation_enabled()
        )
            ? (int) ll_tools_get_effective_word_image_id_for_wordset($word_image_id, $wordset_id)
            : $word_image_id;

        $image_data = ll_tools_get_effective_word_image_data_for_word($word_id, 'large', true);
        $this->assertSame($effective_word_image_id, (int) ($image_data['word_image_id'] ?? 0));
        $this->assertSame($attachment_id, (int) ($image_data['attachment_id'] ?? 0));
        $this->assertSame('word_image', (string) ($image_data['source'] ?? ''));
        $this->assertNotSame('', (string) ($image_data['url'] ?? ''));
        $this->assertTrue(ll_tools_word_has_effective_image($word_id, true));

        $word_grid_image = ll_tools_word_grid_get_image_data_for_word($word_id);
        $this->assertSame($attachment_id, (int) ($word_grid_image['id'] ?? 0));
        $this->assertSame($effective_word_image_id, (int) ($word_grid_image['word_image_id'] ?? 0));
        $this->assertNotSame('', (string) ($word_grid_image['url'] ?? ''));

        $editor_hub_image = ll_tools_editor_hub_get_word_image_data($word_id);
        $this->assertSame($attachment_id, (int) ($editor_hub_image['id'] ?? 0));
        $this->assertNotSame('', (string) ($editor_hub_image['url'] ?? ''));

        $rows = ll_get_words_by_category('canonical-image-category', 'image', $wordset_id, [
            '__skip_quiz_config_merge' => true,
            'prompt_type' => 'image',
            'option_type' => 'image',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame($attachment_id, (int) ($rows[0]['image_attachment_id'] ?? 0));
        $this->assertNotSame('', (string) ($rows[0]['image'] ?? ''));
        $this->assertTrue((bool) ($rows[0]['has_image'] ?? false));

        $this->assertSame(1, ll_get_words_by_category_count('canonical-image-category', 'image', $wordset_id, [
            '__skip_quiz_config_merge' => true,
            'prompt_type' => 'image',
            'option_type' => 'image',
        ]));

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
            $print_items = ll_tools_get_vocab_lesson_print_items($wordset_id, $category_id);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }

        $this->assertCount(1, $print_items);
        $this->assertSame($attachment_id, (int) ($print_items[0]['attachment_id'] ?? 0));
        $this->assertSame($word_id, (int) ($print_items[0]['word_id'] ?? 0));

        $image_html = ll_tools_get_effective_word_image_html_for_word($word_id, 'medium', [], true);
        $this->assertStringContainsString('<img', $image_html);
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
