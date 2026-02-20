<?php
declare(strict_types=1);

final class ImportPreviewWarningsTest extends LL_Tools_TestCase
{
    public function test_preview_warns_when_media_file_count_reaches_threshold(): void
    {
        $file_limit_filter = static function () {
            return 200;
        };
        $byte_limit_filter = static function () {
            return 0;
        };

        add_filter('ll_tools_import_soft_limit_files', $file_limit_filter);
        add_filter('ll_tools_import_soft_limit_bytes', $byte_limit_filter);

        try {
            $preview = ll_tools_build_import_preview_data_from_payload([
                'categories' => [],
                'word_images' => [],
                'wordsets' => [],
                'words' => [],
                'media_estimate' => [
                    'attachment_count' => 205,
                    'attachment_bytes' => 0,
                ],
            ]);

            $warnings = isset($preview['warnings']) && is_array($preview['warnings']) ? $preview['warnings'] : [];
            $this->assertNotEmpty($warnings);
            $this->assertStringContainsString('205', (string) $warnings[0]);
            $this->assertStringContainsString('200', (string) $warnings[0]);
        } finally {
            remove_filter('ll_tools_import_soft_limit_files', $file_limit_filter);
            remove_filter('ll_tools_import_soft_limit_bytes', $byte_limit_filter);
        }
    }

    public function test_preview_warns_when_media_size_reaches_threshold(): void
    {
        $file_limit_filter = static function () {
            return 0;
        };
        $byte_limit_filter = static function () {
            return 10 * MB_IN_BYTES;
        };

        add_filter('ll_tools_import_soft_limit_files', $file_limit_filter);
        add_filter('ll_tools_import_soft_limit_bytes', $byte_limit_filter);

        try {
            $preview = ll_tools_build_import_preview_data_from_payload([
                'categories' => [],
                'word_images' => [],
                'wordsets' => [],
                'words' => [],
                'media_estimate' => [
                    'attachment_count' => 1,
                    'attachment_bytes' => 11 * MB_IN_BYTES,
                ],
            ]);

            $warnings = isset($preview['warnings']) && is_array($preview['warnings']) ? $preview['warnings'] : [];
            $this->assertNotEmpty($warnings);
            $warning_text = implode(' ', array_map('strval', $warnings));
            $this->assertStringContainsString('11 MB', $warning_text);
            $this->assertStringContainsString('10 MB', $warning_text);
        } finally {
            remove_filter('ll_tools_import_soft_limit_files', $file_limit_filter);
            remove_filter('ll_tools_import_soft_limit_bytes', $byte_limit_filter);
        }
    }

    public function test_preview_includes_category_names_and_sample_word_details(): void
    {
        $preview = ll_tools_build_import_preview_data_from_payload([
            'categories' => [
                ['slug' => 'animals', 'name' => 'Animals'],
                ['slug' => 'fruits', 'name' => 'Fruits'],
            ],
            'word_images' => [],
            'wordsets' => [
                ['slug' => 'starter-pack', 'name' => 'Starter Pack'],
            ],
            'words' => [
                [
                    'slug' => 'cat',
                    'title' => 'Cat',
                    'meta' => [
                        'word_translation' => ['Kedi'],
                    ],
                    'categories' => ['animals'],
                    'wordsets' => ['starter-pack'],
                    'featured_image' => ['file' => 'media/words/cat.webp'],
                    'audio_entries' => [
                        ['audio_file' => ['file' => 'audio/cat-1.mp3']],
                        ['audio_file' => ['file' => 'audio/cat-2.mp3']],
                    ],
                ],
            ],
            'media_estimate' => [
                'attachment_count' => 4,
                'attachment_bytes' => 1024,
            ],
        ]);

        $this->assertSame(['Animals', 'Fruits'], (array) ($preview['category_names'] ?? []));

        $sample_word = isset($preview['sample_word']) && is_array($preview['sample_word']) ? $preview['sample_word'] : [];
        $this->assertSame('word', (string) ($sample_word['type'] ?? ''));
        $this->assertSame('Cat', (string) ($sample_word['title'] ?? ''));
        $this->assertSame('Kedi', (string) ($sample_word['translation'] ?? ''));
        $this->assertSame(['Animals'], (array) ($sample_word['categories'] ?? []));
        $this->assertSame(['Starter Pack'], (array) ($sample_word['wordsets'] ?? []));
        $this->assertSame('media/words/cat.webp', (string) ($sample_word['image'] ?? ''));
        $this->assertSame(['audio/cat-1.mp3', 'audio/cat-2.mp3'], (array) ($sample_word['audio'] ?? []));
    }

    public function test_preview_uses_word_image_as_sample_when_words_are_missing(): void
    {
        $preview = ll_tools_build_import_preview_data_from_payload([
            'categories' => [
                ['slug' => 'objects', 'name' => 'Objects'],
            ],
            'word_images' => [
                [
                    'slug' => 'chair',
                    'title' => 'Chair',
                    'categories' => ['objects'],
                    'featured_image' => ['file' => 'media/chair.webp'],
                ],
            ],
            'wordsets' => [],
            'words' => [],
            'media_estimate' => [
                'attachment_count' => 1,
                'attachment_bytes' => 100,
            ],
        ]);

        $sample_word = isset($preview['sample_word']) && is_array($preview['sample_word']) ? $preview['sample_word'] : [];
        $this->assertSame('word_image', (string) ($sample_word['type'] ?? ''));
        $this->assertSame('Chair', (string) ($sample_word['title'] ?? ''));
        $this->assertSame(['Objects'], (array) ($sample_word['categories'] ?? []));
        $this->assertSame('media/chair.webp', (string) ($sample_word['image'] ?? ''));
        $this->assertSame([], (array) ($sample_word['audio'] ?? []));
    }
}
