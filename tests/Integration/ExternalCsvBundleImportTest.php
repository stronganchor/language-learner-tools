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

    private function getSpecificWrongTextsForWord(int $word_id): array
    {
        if ($word_id <= 0) {
            return [];
        }

        if (function_exists('ll_tools_get_word_specific_wrong_answer_texts')) {
            return array_values(array_map('strval', ll_tools_get_word_specific_wrong_answer_texts($word_id)));
        }

        $raw = get_post_meta($word_id, '_ll_specific_wrong_answer_texts', true);
        $texts = array_values(array_filter(array_map('strval', (array) $raw), static function (string $text): bool {
            return trim($text) !== '';
        }));

        return $texts;
    }

    private function getWordTitlesForIds(array $word_ids): array
    {
        $titles = [];
        foreach ((array) $word_ids as $word_id_raw) {
            $word_id = (int) $word_id_raw;
            if ($word_id <= 0) {
                continue;
            }
            $title = (string) get_the_title($word_id);
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        return $titles;
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

    private function findQuizPageIdByCategoryId(int $category_id): int
    {
        if ($category_id <= 0) {
            return 0;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'meta_key' => '_ll_tools_word_category_id',
            'meta_value' => (string) $category_id,
            'fields' => 'ids',
        ]);

        if (!empty($pages)) {
            return (int) $pages[0];
        }

        return 0;
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
            $this->assertSame('Apple', (string) get_the_title($apple_word_id));
            $this->assertSame('', (string) get_post_meta($apple_word_id, 'word_translation', true));
            $this->assertSame('', (string) get_post_meta($apple_word_id, 'word_english_meaning', true));

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

    public function test_import_audio_to_text_stores_unresolved_wrong_answers_as_text_meta(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Audio Reserved Wrongset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $audio_category_name = 'Audio Reserved Wrongs ' . wp_generate_password(6, false, false);
        $audio_to_text_csv = "quiz,audio_file,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $audio_to_text_csv .= $audio_category_name . ",cat_prompt.wav,Cat,Lynx,Dog,\n";
        $audio_to_text_csv .= $audio_category_name . ",dog_prompt.wav,Dog,Cat,,\n";

        $zip_path = $this->createExternalZip([
            'audio-to-text.csv' => $audio_to_text_csv,
            // Test extension drift: CSV references .wav while files are .mp3.
            'audio/cat_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/dog_prompt.mp3' => self::TINY_MP3_BYTES,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $audio_term = get_term_by('name', $audio_category_name, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $audio_term);

            $this->assertSame('audio', (string) get_term_meta((int) $audio_term->term_id, 'll_quiz_prompt_type', true));
            $this->assertSame('text_title', (string) get_term_meta((int) $audio_term->term_id, 'll_quiz_option_type', true));

            $cat_word_id = $this->findWordIdByTitleAndCategory('Cat', (int) $audio_term->term_id);
            $dog_word_id = $this->findWordIdByTitleAndCategory('Dog', (int) $audio_term->term_id);
            $lynx_word_id = $this->findWordIdByTitleAndCategory('Lynx', (int) $audio_term->term_id);

            $this->assertGreaterThan(0, $cat_word_id);
            $this->assertGreaterThan(0, $dog_word_id);
            $this->assertSame(0, $lynx_word_id);

            $cat_audio_paths = $this->getWordAudioPathsForWord($cat_word_id);
            $dog_audio_paths = $this->getWordAudioPathsForWord($dog_word_id);
            $this->assertNotEmpty($cat_audio_paths);
            $this->assertNotEmpty($dog_audio_paths);

            $cat_wrong_ids = $this->getSpecificWrongIdsForWord($cat_word_id);
            $this->assertContains($dog_word_id, $cat_wrong_ids);
            $this->assertNotContains($lynx_word_id, $cat_wrong_ids);
            $this->assertSame(['Lynx', 'Dog'], $this->getSpecificWrongTextsForWord($cat_word_id));

            $audio_rows = ll_get_words_by_category(
                $audio_category_name,
                'text_title',
                null,
                [
                    'prompt_type' => 'audio',
                    'option_type' => 'text_title',
                ]
            );
            $this->assertCount(2, $audio_rows);

            $audio_row_by_id = [];
            foreach ($audio_rows as $row) {
                $audio_row_by_id[(int) ($row['id'] ?? 0)] = $row;
            }

            $lesson_word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, (int) $audio_term->term_id);
            $this->assertContains($cat_word_id, $lesson_word_ids);
            $this->assertContains($dog_word_id, $lesson_word_ids);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_import_audio_to_text_does_not_resolve_wrong_answers_to_other_categories(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Audio Cross-Category Wrongset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_a = 'Audio Cat A ' . wp_generate_password(6, false, false);
        $category_b = 'Audio Cat B ' . wp_generate_password(6, false, false);

        $audio_to_text_csv = "quiz,audio_file,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $audio_to_text_csv .= $category_a . ",a1_prompt.wav,Alpha,Shared Wrong,Beta,\n";
        $audio_to_text_csv .= $category_a . ",a2_prompt.wav,Beta,Alpha,,\n";
        $audio_to_text_csv .= $category_b . ",b1_prompt.wav,Shared Wrong,Other,,\n";
        $audio_to_text_csv .= $category_b . ",b2_prompt.wav,Other,Shared Wrong,,\n";

        $zip_path = $this->createExternalZip([
            'audio-to-text.csv' => $audio_to_text_csv,
            // Test extension drift: CSV references .wav while files are .mp3.
            'audio/a1_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/a2_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/b1_prompt.mp3' => self::TINY_MP3_BYTES,
            'audio/b2_prompt.mp3' => self::TINY_MP3_BYTES,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $term_a = get_term_by('name', $category_a, 'word-category');
            $term_b = get_term_by('name', $category_b, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $term_a);
            $this->assertInstanceOf(WP_Term::class, $term_b);

            $alpha_id = $this->findWordIdByTitleAndCategory('Alpha', (int) $term_a->term_id);
            $beta_id = $this->findWordIdByTitleAndCategory('Beta', (int) $term_a->term_id);
            $shared_in_a_id = $this->findWordIdByTitleAndCategory('Shared Wrong', (int) $term_a->term_id);
            $shared_in_b_id = $this->findWordIdByTitleAndCategory('Shared Wrong', (int) $term_b->term_id);

            $this->assertGreaterThan(0, $alpha_id);
            $this->assertGreaterThan(0, $beta_id);
            $this->assertSame(0, $shared_in_a_id);
            $this->assertGreaterThan(0, $shared_in_b_id);

            $alpha_wrong_ids = $this->getSpecificWrongIdsForWord($alpha_id);
            $this->assertContains($beta_id, $alpha_wrong_ids);
            $this->assertNotContains($shared_in_b_id, $alpha_wrong_ids);
            $this->assertSame(['Shared Wrong', 'Beta'], $this->getSpecificWrongTextsForWord($alpha_id));

            $lesson_a_word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, (int) $term_a->term_id);
            $this->assertContains($alpha_id, $lesson_a_word_ids);
            $this->assertContains($beta_id, $lesson_a_word_ids);
            $this->assertNotContains($shared_in_b_id, $lesson_a_word_ids);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_import_decodes_windows_1255_csv_values_and_generates_quiz_page(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }
        if (!function_exists('mb_convert_encoding') && !function_exists('iconv')) {
            $this->markTestSkipped('mb_convert_encoding or iconv is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Hebrew Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_name = 'Hebrew External ' . wp_generate_password(6, false, false);
        $utf8_csv = "quiz,prompt_text,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $utf8_csv .= $category_name . ",שלום,שלום,תודה,בית,כלב\n";
        $utf8_csv .= $category_name . ",תודה,תודה,שלום,בית,כלב\n";
        $utf8_csv .= $category_name . ",בית,בית,שלום,תודה,כלב\n";
        $utf8_csv .= $category_name . ",כלב,כלב,שלום,תודה,בית\n";
        $utf8_csv .= $category_name . ",חתול,חתול,שלום,תודה,בית\n";

        $encoded_csv = '';
        if (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'Windows-1255//IGNORE', $utf8_csv);
            $encoded_csv = is_string($iconv) ? $iconv : '';
        }
        if ($encoded_csv === '' && function_exists('mb_convert_encoding')) {
            $mb_target_encoding = 'ISO-8859-8';
            if (function_exists('mb_list_encodings')) {
                $available = array_map('strtoupper', (array) mb_list_encodings());
                if (in_array('CP1255', $available, true)) {
                    $mb_target_encoding = 'CP1255';
                } elseif (in_array('ISO-8859-8', $available, true)) {
                    $mb_target_encoding = 'ISO-8859-8';
                } else {
                    $mb_target_encoding = '';
                }
            }
            if ($mb_target_encoding !== '') {
                $encoded_csv = (string) mb_convert_encoding($utf8_csv, $mb_target_encoding, 'UTF-8');
            }
        }
        if ($encoded_csv === '') {
            $this->markTestSkipped('Could not encode test CSV to Windows-1255.');
        }

        $decoded_probe = ll_tools_import_convert_external_csv_bytes_to_utf8($encoded_csv);
        if ($decoded_probe === '' || strpos($decoded_probe, 'שלום') === false) {
            $this->markTestSkipped('Runtime encoding libraries do not round-trip this non-UTF Hebrew sample reliably.');
        }

        $zip_path = $this->createExternalZip([
            'hebrew-text.csv' => $encoded_csv,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $term = get_term_by('name', $category_name, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $term);
            $category_id = (int) $term->term_id;

            $shalom_id = $this->findWordIdByTitleAndCategory('שלום', $category_id);
            $todah_id = $this->findWordIdByTitleAndCategory('תודה', $category_id);
            $bayit_id = $this->findWordIdByTitleAndCategory('בית', $category_id);
            $kelev_id = $this->findWordIdByTitleAndCategory('כלב', $category_id);
            $chatul_id = $this->findWordIdByTitleAndCategory('חתול', $category_id);

            $this->assertGreaterThan(0, $shalom_id);
            $this->assertGreaterThan(0, $todah_id);
            $this->assertGreaterThan(0, $bayit_id);
            $this->assertGreaterThan(0, $kelev_id);
            $this->assertGreaterThan(0, $chatul_id);
            $this->assertSame('שלום', (string) get_post_meta($shalom_id, 'word_translation', true));

            $quiz_page_id = $this->findQuizPageIdByCategoryId($category_id);
            $this->assertGreaterThan(0, $quiz_page_id);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_preview_accepts_utf16le_hebrew_text_to_text_csv(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }
        if (!function_exists('mb_convert_encoding') && !function_exists('iconv')) {
            $this->markTestSkipped('mb_convert_encoding or iconv is required for this test.');
        }

        $csv_utf8 = "quiz,prompt_text,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $csv_utf8 .= "Quiz 21.3,___־זֶּה?,מַה,מֶה,,\n";
        $csv_utf8 .= "Quiz 21.3,___־הוּא?,מָה,מֶה,,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשָׂה?,מֶה,מַה,,\n";
        $csv_utf8 .= "Quiz 21.3,מֶה עָשָֹה הָאִישׁ?,הָלַךְ אֶל־הֶהָרִים,הָלַכְתְּ אֶל־הֶהָרִים,הָלַכְתָּ אֶל־הֶהָרִים,\n";
        $csv_utf8 .= "Quiz 21.3,מֶה עָשְֹתָה הָאִשָּׁה?,הָלְכָה אֶל־הַיָּם,הָלַךְ אֶל־הַיָּם,הָלַכְתִּי אֶל־הַיָּם,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשִֹיתִי,אֲנִי,אַתָּה,הִיא,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשִֹיתָ,אַתָּה,הוּא,אֲנִי,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשִׂית,אַתְּ,הִיא,אָנֹכִי,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשָׂה,הוּא,הִיא,אַתְּ,\n";
        $csv_utf8 .= "Quiz 21.3,___ עָשְׂתָה,הִיא,אַתְּ,אָנֹכִי,\n";

        $csv_utf16le = '';
        if (function_exists('mb_convert_encoding')) {
            $csv_utf16le = (string) mb_convert_encoding($csv_utf8, 'UTF-16LE', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'UTF-16LE//IGNORE', $csv_utf8);
            $csv_utf16le = is_string($iconv) ? $iconv : '';
        }
        if ($csv_utf16le === '') {
            $this->markTestSkipped('Could not encode test CSV to UTF-16LE.');
        }
        $csv_bytes = "\xFF\xFE" . $csv_utf16le;

        $zip_path = $this->createExternalZip([
            'quiz-21-3.csv' => $csv_bytes,
        ]);

        try {
            $preview = ll_tools_read_import_preview_from_zip($zip_path);
            $this->assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
            $this->assertIsArray($preview);

            $payload = isset($preview['payload']) && is_array($preview['payload']) ? $preview['payload'] : [];
            $this->assertCount(1, (array) ($payload['categories'] ?? []));
            $this->assertCount(10, (array) ($payload['words'] ?? []));

            $category = (array) (($payload['categories'] ?? [])[0] ?? []);
            $category_meta = isset($category['meta']) && is_array($category['meta']) ? $category['meta'] : [];
            $this->assertSame('text_translation', (string) (($category_meta['ll_quiz_prompt_type'][0] ?? '')));
            $this->assertSame('text_title', (string) (($category_meta['ll_quiz_option_type'][0] ?? '')));

            $target_word = null;
            foreach ((array) ($payload['words'] ?? []) as $word) {
                if ((string) ($word['title'] ?? '') === 'מַה') {
                    $target_word = $word;
                    break;
                }
            }

            $this->assertIsArray($target_word);
            $target_word_meta = isset($target_word['meta']) && is_array($target_word['meta']) ? $target_word['meta'] : [];
            $this->assertSame('___־זֶּה?', (string) (($target_word_meta['word_translation'][0] ?? '')));
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_import_text_to_text_stores_unresolved_wrong_answers_as_text_meta(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV Hebrew Reserved Wrongset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_name = 'Quiz 21.3';
        $csv = "quiz,prompt_text,correct_answer,wrong_answer_1,wrong_answer_2,wrong_answer_3\n";
        $csv .= "Quiz 21.3,___־זֶּה?,מַה,מֶה,,\n";
        $csv .= "Quiz 21.3,___־הוּא?,מָה,מֶה,,\n";
        $csv .= "Quiz 21.3,___ עָשָׂה?,מֶה,מַה,,\n";
        $csv .= "Quiz 21.3,מֶה עָשָֹה הָאִישׁ?,הָלַךְ אֶל־הֶהָרִים,הָלַכְתְּ אֶל־הֶהָרִים,הָלַכְתָּ אֶל־הֶהָרִים,\n";
        $csv .= "Quiz 21.3,מֶה עָשְֹתָה הָאִשָּׁה?,הָלְכָה אֶל־הַיָּם,הָלַךְ אֶל־הַיָּם,הָלַכְתִּי אֶל־הַיָּם,\n";
        $csv .= "Quiz 21.3,___ עָשִֹיתִי,אֲנִי,אַתָּה,הִיא,\n";
        $csv .= "Quiz 21.3,___ עָשִֹיתָ,אַתָּה,הוּא,אֲנִי,\n";
        $csv .= "Quiz 21.3,___ עָשִׂית,אַתְּ,הִיא,אָנֹכִי,\n";
        $csv .= "Quiz 21.3,___ עָשָׂה,הוּא,הִיא,אַתְּ,\n";
        $csv .= "Quiz 21.3,___ עָשְׂתָה,הִיא,אַתְּ,אָנֹכִי,\n";

        $zip_path = $this->createExternalZip([
            'quiz-21-3.csv' => $csv,
        ]);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $term = get_term_by('name', $category_name, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $term);
            $category_id = (int) $term->term_id;

            $hiy_id = $this->findWordIdByTitleAndCategory('הִיא', $category_id);
            $at_id = $this->findWordIdByTitleAndCategory('אַתְּ', $category_id);
            $halach_id = $this->findWordIdByTitleAndCategory('הָלַךְ אֶל־הֶהָרִים', $category_id);
            $halcha_id = $this->findWordIdByTitleAndCategory('הָלְכָה אֶל־הַיָּם', $category_id);

            $this->assertGreaterThan(0, $hiy_id);
            $this->assertGreaterThan(0, $at_id);
            $this->assertGreaterThan(0, $halach_id);
            $this->assertGreaterThan(0, $halcha_id);

            $hiy_wrong_ids = $this->getSpecificWrongIdsForWord($hiy_id);
            $hiy_wrong_titles = $this->getWordTitlesForIds($hiy_wrong_ids);
            $this->assertContains('אַתְּ', $hiy_wrong_titles);
            $this->assertNotContains('אָנֹכִי', $hiy_wrong_titles);
            $this->assertContains('אָנֹכִי', $this->getSpecificWrongTextsForWord($hiy_id));

            $at_wrong_ids = $this->getSpecificWrongIdsForWord($at_id);
            $at_wrong_titles = $this->getWordTitlesForIds($at_wrong_ids);
            $this->assertContains('הִיא', $at_wrong_titles);
            $this->assertNotContains('אָנֹכִי', $at_wrong_titles);
            $this->assertContains('אָנֹכִי', $this->getSpecificWrongTextsForWord($at_id));

            $halach_wrong_titles = $this->getWordTitlesForIds($this->getSpecificWrongIdsForWord($halach_id));
            $this->assertSame([], $halach_wrong_titles);
            $halach_wrong_texts = $this->getSpecificWrongTextsForWord($halach_id);
            $this->assertContains('הָלַכְתְּ אֶל־הֶהָרִים', $halach_wrong_texts);
            $this->assertContains('הָלַכְתָּ אֶל־הֶהָרִים', $halach_wrong_texts);

            $halcha_wrong_titles = $this->getWordTitlesForIds($this->getSpecificWrongIdsForWord($halcha_id));
            $this->assertSame([], $halcha_wrong_titles);
            $halcha_wrong_texts = $this->getSpecificWrongTextsForWord($halcha_id);
            $this->assertContains('הָלַךְ אֶל־הַיָּם', $halcha_wrong_texts);
            $this->assertContains('הָלַכְתִּי אֶל־הַיָּם', $halcha_wrong_texts);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_text_to_image_import_overwrites_existing_word_title_and_clears_translation_meta(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('External CSV TextToImage Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_name = 'TextToImage Existing ' . wp_generate_password(6, false, false);
        $category_slug = sanitize_title($category_name);

        $insert_term = wp_insert_term($category_name, 'word-category', ['slug' => $category_slug]);
        $this->assertFalse(is_wp_error($insert_term));
        $this->assertIsArray($insert_term);
        $category_id = (int) $insert_term['term_id'];

        $existing_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Old Title',
            'post_name' => sanitize_title($category_slug . '-apple'),
        ]);
        wp_set_post_terms($existing_word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($existing_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($existing_word_id, 'word_translation', 'Old Translation');
        update_post_meta($existing_word_id, 'word_english_meaning', 'Old Legacy Translation');

        $text_to_image_csv = "quiz,image,correct_answer\n";
        $text_to_image_csv .= $category_name . ",apple.jpg,Apple\n";
        $png = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($png);

        $zip_path = $this->createExternalZip([
            'text-to-image.csv' => $text_to_image_csv,
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

            $updated_word = get_post($existing_word_id);
            $this->assertInstanceOf(WP_Post::class, $updated_word);
            $this->assertSame('Apple', (string) $updated_word->post_title);
            $this->assertSame('', (string) get_post_meta($existing_word_id, 'word_translation', true));
            $this->assertSame('', (string) get_post_meta($existing_word_id, 'word_english_meaning', true));
        } finally {
            @unlink($zip_path);
        }
    }
}
