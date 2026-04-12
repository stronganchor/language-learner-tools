<?php
declare(strict_types=1);

final class WordsetTemplateBundleImportExportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_wordset_template_export_payload_contains_template_bundle_and_template_safe_settings(): void
    {
        $fixture = $this->createTemplateBundleFixture();

        $payload = ll_tools_build_export_payload(0, [
            'bundle_type' => 'wordset_template',
            'template_wordset_id' => (int) $fixture['wordset_id'],
        ]);

        $this->assertFalse(is_wp_error($payload));
        $this->assertIsArray($payload);

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $this->assertSame('wordset_template', (string) ($data['bundle_type'] ?? ''));
        $this->assertSame([], (array) ($data['words'] ?? []));
        $this->assertCount(2, (array) ($data['categories'] ?? []));
        $this->assertCount(1, (array) ($data['word_images'] ?? []));
        $this->assertCount(1, (array) ($data['wordsets'] ?? []));

        $wordsetPayload = (array) (($data['wordsets'][0] ?? []));
        $wordsetMeta = isset($wordsetPayload['meta']) && is_array($wordsetPayload['meta']) ? $wordsetPayload['meta'] : [];
        $this->assertArrayNotHasKey('ll_language', $wordsetMeta);
        $this->assertSame(['private'], (array) ($wordsetMeta[LL_TOOLS_WORDSET_VISIBILITY_META_KEY] ?? []));
        $this->assertSame(['1'], (array) ($wordsetMeta['ll_wordset_hide_lesson_text_for_non_text_quiz'] ?? []));
        $this->assertSame([(string) $fixture['category_b_slug'], (string) $fixture['category_a_slug']], (array) ($wordsetPayload['template_category_manual_order'] ?? []));
        $this->assertSame(
            [(string) $fixture['category_b_slug'] => [(string) $fixture['category_a_slug']]],
            (array) ($wordsetPayload['template_category_prerequisites'] ?? [])
        );

        $categorySlugs = array_values(array_map(static function (array $categoryPayload): string {
            return (string) ($categoryPayload['slug'] ?? '');
        }, (array) ($data['categories'] ?? [])));
        sort($categorySlugs, SORT_STRING);
        $expectedCategorySlugs = [(string) $fixture['category_a_slug'], (string) $fixture['category_b_slug']];
        sort($expectedCategorySlugs, SORT_STRING);
        $this->assertSame($expectedCategorySlugs, $categorySlugs);
    }

    public function test_wordset_template_import_creates_new_isolated_wordset_categories_and_images(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $fixture = $this->createTemplateBundleFixture();
        $payload = ll_tools_build_export_payload(0, [
            'bundle_type' => 'wordset_template',
            'template_wordset_id' => (int) $fixture['wordset_id'],
        ]);

        $this->assertFalse(is_wp_error($payload));
        $this->assertIsArray($payload);

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $attachments = isset($payload['attachments']) && is_array($payload['attachments']) ? $payload['attachments'] : [];
        $targetWordsetName = 'Imported Template ' . wp_generate_password(6, false);
        $extractDir = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-template-import-' . wp_generate_password(10, false, false));

        try {
            wp_mkdir_p($extractDir);
            $this->stageExportAttachments($attachments, $extractDir);

            $result = ll_tools_import_from_payload($data, $extractDir, [
                'wordset_name_overrides' => [
                    (string) $fixture['wordset_slug'] => $targetWordsetName,
                ],
            ]);

            $this->assertTrue(!empty($result['ok']), (string) ($result['message'] ?? ''));
            $this->assertSame(1, (int) ($result['stats']['wordsets_created'] ?? 0));
            $this->assertSame(2, (int) ($result['stats']['categories_created'] ?? 0));
            $this->assertSame(1, (int) ($result['stats']['word_images_created'] ?? 0));

            $targetWordset = get_term_by('name', $targetWordsetName, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $targetWordset);
            $targetWordsetId = (int) $targetWordset->term_id;

            $this->assertSame('private', (string) get_term_meta($targetWordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertSame('1', (string) get_term_meta($targetWordsetId, 'll_wordset_hide_lesson_text_for_non_text_quiz', true));
            $this->assertSame('', (string) get_term_meta($targetWordsetId, 'll_language', true));
            $this->assertSame((string) $adminId, (string) get_term_meta($targetWordsetId, 'manager_user_id', true));

            $targetCategories = get_terms([
                'taxonomy' => 'word-category',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key' => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                        'value' => $targetWordsetId,
                    ],
                ],
            ]);
            $this->assertIsArray($targetCategories);
            $this->assertCount(2, $targetCategories);

            $targetCategoryIdsByName = [];
            foreach ($targetCategories as $targetCategory) {
                if ($targetCategory instanceof WP_Term) {
                    $targetCategoryIdsByName[(string) $targetCategory->name] = (int) $targetCategory->term_id;
                }
            }

            $targetCategoryAId = (int) ($targetCategoryIdsByName[(string) $fixture['category_a_name']] ?? 0);
            $targetCategoryBId = (int) ($targetCategoryIdsByName[(string) $fixture['category_b_name']] ?? 0);
            $this->assertGreaterThan(0, $targetCategoryAId);
            $this->assertGreaterThan(0, $targetCategoryBId);
            $this->assertNotSame((int) $fixture['category_a_id'], $targetCategoryAId);
            $this->assertNotSame((int) $fixture['category_b_id'], $targetCategoryBId);

            $manualOrder = get_term_meta($targetWordsetId, 'll_wordset_category_manual_order', true);
            $this->assertSame([$targetCategoryBId, $targetCategoryAId], $manualOrder);

            $prereqMap = get_term_meta($targetWordsetId, 'll_wordset_category_prerequisites', true);
            $this->assertSame([$targetCategoryBId => [$targetCategoryAId]], $prereqMap);

            $importedImages = get_posts([
                'post_type' => 'word_images',
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
                        'value' => $targetWordsetId,
                    ],
                ],
            ]);
            $this->assertCount(1, $importedImages);
            $importedImage = $importedImages[0];
            $this->assertInstanceOf(WP_Post::class, $importedImage);
            $this->assertNotSame((int) $fixture['word_image_id'], (int) $importedImage->ID);

            $imageCategoryIds = wp_get_post_terms((int) $importedImage->ID, 'word-category', ['fields' => 'ids']);
            $this->assertSame([$targetCategoryAId], array_values(array_map('intval', (array) $imageCategoryIds)));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id((int) $importedImage->ID));
        } finally {
            ll_tools_rrmdir($extractDir);
        }
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   wordset_slug:string,
     *   category_a_id:int,
     *   category_a_name:string,
     *   category_a_slug:string,
     *   category_b_id:int,
     *   category_b_name:string,
     *   category_b_slug:string,
     *   word_image_id:int
     * }
     */
    private function createTemplateBundleFixture(): array
    {
        $wordset = wp_insert_term('Template Export Source ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        $wordsetSlug = (string) get_term_field('slug', $wordsetId, 'wordset');

        update_term_meta($wordsetId, 'll_language', 'Spanish');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        update_term_meta($wordsetId, 'll_wordset_hide_lesson_text_for_non_text_quiz', '1');
        update_term_meta($wordsetId, 'll_wordset_category_ordering_mode', 'manual');

        $categoryAName = 'Template Export Category A ' . wp_generate_password(4, false);
        $categoryBName = 'Template Export Category B ' . wp_generate_password(4, false);
        $categoryAId = (int) ll_tools_create_or_get_wordset_category($categoryAName, $wordsetId);
        $categoryBId = (int) ll_tools_create_or_get_wordset_category($categoryBName, $wordsetId);
        $this->assertGreaterThan(0, $categoryAId);
        $this->assertGreaterThan(0, $categoryBId);

        $categoryASlug = (string) get_term_field('slug', $categoryAId, 'word-category');
        $categoryBSlug = (string) get_term_field('slug', $categoryBId, 'word-category');
        update_term_meta($categoryAId, 'll_quiz_prompt_type', 'image');
        update_term_meta($categoryAId, 'll_quiz_option_type', 'text_title');
        update_term_meta($categoryBId, 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($categoryBId, 'll_quiz_option_type', 'text_title');

        update_term_meta($wordsetId, 'll_wordset_category_manual_order', [$categoryBId, $categoryAId]);
        update_term_meta($wordsetId, 'll_wordset_category_prerequisites', [$categoryBId => [$categoryAId]]);

        $imageAttachmentId = $this->createImageAttachment('template-export-image.png');
        $wordImageId = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Template Export Image',
        ]);
        wp_set_post_terms($wordImageId, [$categoryAId], 'word-category', false);
        set_post_thumbnail($wordImageId, $imageAttachmentId);
        ll_tools_set_word_image_wordset_owner($wordImageId, $wordsetId, $wordImageId);

        return [
            'wordset_id' => $wordsetId,
            'wordset_slug' => $wordsetSlug,
            'category_a_id' => $categoryAId,
            'category_a_name' => $categoryAName,
            'category_a_slug' => $categoryASlug,
            'category_b_id' => $categoryBId,
            'category_b_name' => $categoryBName,
            'category_b_slug' => $categoryBSlug,
            'word_image_id' => $wordImageId,
        ];
    }

    /**
     * @param array<int,array<string,string>> $attachments
     */
    private function stageExportAttachments(array $attachments, string $extractDir): void
    {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $sourcePath = isset($attachment['path']) ? (string) $attachment['path'] : '';
            $zipPath = isset($attachment['zip_path']) ? ltrim((string) $attachment['zip_path'], '/') : '';
            if ($sourcePath === '' || $zipPath === '' || !is_file($sourcePath)) {
                continue;
            }

            $targetPath = wp_normalize_path(trailingslashit($extractDir) . $zipPath);
            wp_mkdir_p(dirname($targetPath));
            copy($sourcePath, $targetPath);
        }
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $filePath = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $filePath);
        $this->assertFileExists($filePath);

        $filetype = wp_check_filetype(basename($filePath), null);
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\\.[^.]+$/', '', basename($filePath)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $filePath);
        $this->assertIsInt($attachmentId);
        $this->assertGreaterThan(0, $attachmentId);

        $relativePath = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($filePath)
            : '';
        if ($relativePath !== '') {
            update_post_meta($attachmentId, '_wp_attached_file', $relativePath);
        }

        return (int) $attachmentId;
    }
}
