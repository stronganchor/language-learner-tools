<?php
declare(strict_types=1);

final class ExternalCsvBundleImportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';
    private const TINY_MP3_BYTES = "ID3\x03\x00\x00\x00\x00\x00\x15TIT2\x00\x00\x00\x05\x00\x00\x03Test";

    private function createExternalZip(array $filesByPath): string
    {
        $zip_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-external-import-' . wp_generate_password(10, false, false) . '.zip');
        @unlink($zip_path);

        $zip = new ZipArchive();
        $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);

        foreach ($filesByPath as $path => $contents) {
            $this->assertTrue($zip->addFromString((string) $path, (string) $contents));
        }

        $this->assertTrue($zip->close());
        $this->assertFileExists($zip_path);

        return $zip_path;
    }

    private function findWordIdByTitleAndCategory(string $title, int $category_id): int
    {
        $posts = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [$category_id],
            ]],
        ]);

        foreach ($posts as $post) {
            if ($post instanceof WP_Post && (string) $post->post_title === $title) {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    private function getSpecificWrongIdsForWord(int $word_id): array
    {
        if ($word_id <= 0) {
            return [];
        }

        if (function_exists('ll_tools_get_word_specific_wrong_answer_ids')) {
            $ids = ll_tools_get_word_specific_wrong_answer_ids($word_id);
            sort($ids, SORT_NUMERIC);
            return array_values(array_map('intval', $ids));
        }

        $raw = get_post_meta($word_id, '_ll_specific_wrong_answer_ids', true);
        $ids = array_values(array_filter(array_map('intval', (array) $raw), static function ($id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function getWordAudioPathsForWord(int $word_id): array
    {
        if ($word_id <= 0) {
            return [];
        }

        $audio_posts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'post_parent' => $word_id,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $paths = [];
        foreach ($audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }
            $path = (string) get_post_meta((int) $audio_post->ID, 'audio_file_path', true);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function test_preview_accepts_external_csv_images_zip_without_data_json(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $category_name = 'Preview External Category ' . wp_generate_password(6, false, false);
        $csv = "quiz,image,correct answer\n";
        $csv .= $category_name . ",sample.jpg,Sample\n";

        $png = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($png);

        $zip_path = $this->createExternalZip([
            'external.csv' => $csv,
            'images/sample.webp' => $png,
        ]);

        try {
            $preview = ll_tools_read_import_preview_from_zip($zip_path);
            $this->assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
            $this->assertIsArray($preview);
            $this->assertIsArray($preview['payload']);
            $this->assertIsArray($preview['preview']);

            $payload = $preview['payload'];
            $summary = $preview['preview']['summary'];

            $this->assertSame('category_full', (string) ($payload['bundle_type'] ?? ''));
            $this->assertCount(1, (array) ($payload['categories'] ?? []));
            $this->assertCount(1, (array) ($payload['words'] ?? []));
            $this->assertSame(1, (int) ($summary['categories'] ?? 0));
            $this->assertSame(1, (int) ($summary['words'] ?? 0));
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_import_processes_external_csv_images_bundle_and_maps_wrong_answers(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $animals_name = 'Animals External ' . wp_generate_password(6, false, false);
        $fruits_name = 'Fruits External ' . wp_generate_password(6, false, false);

        $image_to_text_csv = "quiz,image file,correct answer,wrong answer 1,wrong answer 2\n";
        $image_to_text_csv .= $animals_name . ",cat.jpg,Cat,Dog,Bird\n";
        $image_to_text_csv .= $animals_name . ",dog.jpg,Dog,Cat,\n";
        $image_to_text_csv .= $animals_name . ",bird.jpg,Bird,Cat,Dog\n";

        $text_to_image_csv = "quiz,image,correct answer\n";
        $text_to_image_csv .= $fruits_name . ",apple.jpg,Apple\n";

        $png = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($png);

        $zip_path = $this->createExternalZip([
            'image-to-text.csv' => $image_to_text_csv,
            'text-to-image.csv' => $text_to_image_csv,
            'images/cat.webp' => $png,
            'images/dog.webp' => $png,
            'images/bird.webp' => $png,
            'images/apple.webp' => $png,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $animals_term = get_term_by('name', $animals_name, 'word-category');
            $fruits_term = get_term_by('name', $fruits_name, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $animals_term);
            $this->assertInstanceOf(WP_Term::class, $fruits_term);

            $this->assertSame('image', (string) get_term_meta((int) $animals_term->term_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', (string) get_term_meta((int) $animals_term->term_id, 'll_quiz_option_type', true));
            $this->assertSame('text_title', (string) get_term_meta((int) $fruits_term->term_id, 'll_quiz_prompt_type', true));
            $this->assertSame('image', (string) get_term_meta((int) $fruits_term->term_id, 'll_quiz_option_type', true));

            $cat_word_id = $this->findWordIdByTitleAndCategory('Cat', (int) $animals_term->term_id);
            $dog_word_id = $this->findWordIdByTitleAndCategory('Dog', (int) $animals_term->term_id);
            $bird_word_id = $this->findWordIdByTitleAndCategory('Bird', (int) $animals_term->term_id);
            $apple_word_id = $this->findWordIdByTitleAndCategory('Apple', (int) $fruits_term->term_id);

            $this->assertGreaterThan(0, $cat_word_id);
            $this->assertGreaterThan(0, $dog_word_id);
            $this->assertGreaterThan(0, $bird_word_id);
            $this->assertGreaterThan(0, $apple_word_id);

            $this->assertGreaterThan(0, (int) get_post_thumbnail_id($cat_word_id));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id($dog_word_id));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id($bird_word_id));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id($apple_word_id));

            $cat_wrong_ids = $this->getSpecificWrongIdsForWord($cat_word_id);
            $this->assertSame([$bird_word_id, $dog_word_id], $cat_wrong_ids);

            $dog_wrong_ids = $this->getSpecificWrongIdsForWord($dog_word_id);
            $this->assertSame([$cat_word_id], $dog_wrong_ids);

            $apple_wrong_ids = $this->getSpecificWrongIdsForWord($apple_word_id);
            $this->assertSame([], $apple_wrong_ids);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_import_processes_external_text_to_text_and_audio_to_text_csvs(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Mixed Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $text_category_name = 'Text Quiz External ' . wp_generate_password(6, false, false);
        $audio_category_name = 'Audio Quiz External ' . wp_generate_password(6, false, false);

        $text_to_text_csv = "quiz,prompt_text,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $text_to_text_csv .= $text_category_name . ",Question Cat,Cat,Dog,Bird,\n";
        $text_to_text_csv .= $text_category_name . ",Question Dog,Dog,Cat,,\n";
        $text_to_text_csv .= $text_category_name . ",Question Bird,Bird,Cat,Dog,\n";

        $audio_to_text_csv = "quiz,audio_file,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $audio_to_text_csv .= $audio_category_name . ",cat_prompt.wav,Cat,Dog,Bird,\n";
        $audio_to_text_csv .= $audio_category_name . ",dog_prompt.wav,Dog,Cat,,\n";
        $audio_to_text_csv .= $audio_category_name . ",bird_prompt.wav,Bird,Cat,Dog,\n";

        $zip_path = $this->createExternalZip([
            'text-to-text.csv' => $text_to_text_csv,
            'audio-to-text.csv' => $audio_to_text_csv,
            // Test extension drift: CSV references .wav while files are .mp3.
            'audio/cat_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/dog_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/bird_prompt.mp3' => self::TINY_MP3_BYTES,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $text_term = get_term_by('name', $text_category_name, 'word-category');
            $audio_term = get_term_by('name', $audio_category_name, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $text_term);
            $this->assertInstanceOf(WP_Term::class, $audio_term);

            $this->assertSame('text_translation', (string) get_term_meta((int) $text_term->term_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', (string) get_term_meta((int) $text_term->term_id, 'll_quiz_option_type', true));
            $this->assertSame('audio', (string) get_term_meta((int) $audio_term->term_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', (string) get_term_meta((int) $audio_term->term_id, 'll_quiz_option_type', true));

            $text_cat_word_id = $this->findWordIdByTitleAndCategory('Cat', (int) $text_term->term_id);
            $text_dog_word_id = $this->findWordIdByTitleAndCategory('Dog', (int) $text_term->term_id);
            $text_bird_word_id = $this->findWordIdByTitleAndCategory('Bird', (int) $text_term->term_id);
            $audio_cat_word_id = $this->findWordIdByTitleAndCategory('Cat', (int) $audio_term->term_id);
            $audio_dog_word_id = $this->findWordIdByTitleAndCategory('Dog', (int) $audio_term->term_id);
            $audio_bird_word_id = $this->findWordIdByTitleAndCategory('Bird', (int) $audio_term->term_id);

            $this->assertGreaterThan(0, $text_cat_word_id);
            $this->assertGreaterThan(0, $text_dog_word_id);
            $this->assertGreaterThan(0, $text_bird_word_id);
            $this->assertGreaterThan(0, $audio_cat_word_id);
            $this->assertGreaterThan(0, $audio_dog_word_id);
            $this->assertGreaterThan(0, $audio_bird_word_id);

            // Prompt text is imported into translation meta for text->text prompts.
            $this->assertSame('Question Cat', (string) get_post_meta($text_cat_word_id, 'word_translation', true));

            $text_rows = ll_get_words_by_category(
                $text_category_name,
                'text',
                null,
                [
                    'prompt_type' => 'text_translation',
                    'option_type' => 'text_title',
                ]
            );
            $this->assertCount(3, $text_rows);
            $text_row_by_id = [];
            foreach ($text_rows as $row) {
                $text_row_by_id[(int) ($row['id'] ?? 0)] = $row;
            }
            $this->assertSame('Question Cat', (string) ($text_row_by_id[$text_cat_word_id]['prompt_label'] ?? ''));
            $this->assertSame('Cat', (string) ($text_row_by_id[$text_cat_word_id]['label'] ?? ''));
            $this->assertFalse((bool) ($text_row_by_id[$text_cat_word_id]['has_audio'] ?? true));
            $this->assertFalse((bool) ($text_row_by_id[$text_cat_word_id]['has_image'] ?? true));

            $audio_rows = ll_get_words_by_category(
                $audio_category_name,
                'text_title',
                null,
                [
                    'prompt_type' => 'audio',
                    'option_type' => 'text_title',
                ]
            );
            $this->assertCount(3, $audio_rows);
            foreach ($audio_rows as $row) {
                $this->assertTrue((bool) ($row['has_audio'] ?? false));
            }

            $cat_audio_paths = $this->getWordAudioPathsForWord($audio_cat_word_id);
            $dog_audio_paths = $this->getWordAudioPathsForWord($audio_dog_word_id);
            $bird_audio_paths = $this->getWordAudioPathsForWord($audio_bird_word_id);
            $this->assertNotEmpty($cat_audio_paths);
            $this->assertNotEmpty($dog_audio_paths);
            $this->assertNotEmpty($bird_audio_paths);

            $text_cat_wrong_ids = $this->getSpecificWrongIdsForWord($text_cat_word_id);
            $this->assertSame([$text_bird_word_id, $text_dog_word_id], $text_cat_wrong_ids);

            $audio_cat_wrong_ids = $this->getSpecificWrongIdsForWord($audio_cat_word_id);
            $this->assertSame([$audio_bird_word_id, $audio_dog_word_id], $audio_cat_wrong_ids);
        } finally {
            @unlink($zip_path);
        }
    }
}
