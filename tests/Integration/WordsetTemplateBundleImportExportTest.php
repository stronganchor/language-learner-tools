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
        $this->assertSame(['hide'], (array) ($wordsetMeta[LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY] ?? []));
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
            $this->assertSame('hide', (string) get_term_meta($targetWordsetId, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, true));
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

    public function test_featured_image_tracks_attachment_before_thumbnail_hook_throws(): void
    {
        $wordImageId = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Throwing Thumbnail Target',
        ]);
        $extractDir = wp_normalize_path(
            trailingslashit(sys_get_temp_dir()) . 'll-tools-thumbnail-throw-' . wp_generate_password(10, false, false)
        );
        $sourceName = 'throwing-thumbnail.png';
        $sourcePath = wp_normalize_path(trailingslashit($extractDir) . $sourceName);
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);
        $this->assertTrue(wp_mkdir_p($extractDir));
        $this->assertNotFalse(file_put_contents($sourcePath, $bytes));

        $result = [
            'errors' => [],
            'stats' => ll_tools_import_default_stats(),
            'undo' => ll_tools_import_default_undo_payload(),
        ];
        $throwOnThumbnailWrite = static function ($check, $objectId, $metaKey) use ($wordImageId) {
            if ((int) $objectId === $wordImageId && (string) $metaKey === '_thumbnail_id') {
                throw new RuntimeException('Synthetic thumbnail hook failure.');
            }

            return $check;
        };

        add_filter('update_post_metadata', $throwOnThumbnailWrite, PHP_INT_MIN, 3);
        try {
            try {
                ll_tools_import_apply_featured_image(
                    $wordImageId,
                    ['file' => $sourceName, 'title' => 'Throwing Thumbnail'],
                    $extractDir,
                    'throwing-thumbnail',
                    $result,
                    'word_image',
                    true
                );
                $this->fail('Expected the thumbnail metadata hook to throw.');
            } catch (RuntimeException $throwable) {
                $this->assertSame('Synthetic thumbnail hook failure.', $throwable->getMessage());
            }
        } finally {
            remove_filter('update_post_metadata', $throwOnThumbnailWrite, PHP_INT_MIN);
        }

        $attachmentIds = array_values(array_map('intval', (array) ($result['undo']['attachment_ids'] ?? [])));
        $this->assertCount(1, $attachmentIds);
        $this->assertSame('attachment', get_post_type($attachmentIds[0]));
        $this->assertSame(0, (int) ($result['stats']['attachments_imported'] ?? -1));

        wp_delete_attachment($attachmentIds[0], true);
        ll_tools_rrmdir($extractDir);
    }

    public function test_featured_image_insert_rejection_preserves_existing_thumbnail_and_counters(): void
    {
        $wordImageId = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Rejected Thumbnail Target',
        ]);
        $existingAttachmentId = $this->createImageAttachment('existing-thumbnail.png');
        $this->assertNotFalse(set_post_thumbnail($wordImageId, $existingAttachmentId));

        $extractDir = wp_normalize_path(
            trailingslashit(sys_get_temp_dir()) . 'll-tools-thumbnail-reject-' . wp_generate_password(10, false, false)
        );
        $sourceName = 'rejected-thumbnail-' . strtolower(wp_generate_password(8, false, false)) . '.png';
        $sourcePath = wp_normalize_path(trailingslashit($extractDir) . $sourceName);
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);
        $this->assertTrue(wp_mkdir_p($extractDir));
        $this->assertNotFalse(file_put_contents($sourcePath, $bytes));

        $uploadDir = wp_upload_dir();
        $copiedPath = wp_normalize_path(trailingslashit((string) $uploadDir['path']) . $sourceName);
        $this->assertFileDoesNotExist($copiedPath);
        $result = [
            'errors' => [],
            'stats' => ll_tools_import_default_stats(),
            'undo' => ll_tools_import_default_undo_payload(),
        ];
        $rejectAttachment = static function ($maybeEmpty, array $postarr) {
            return (string) ($postarr['post_type'] ?? '') === 'attachment' ? true : $maybeEmpty;
        };

        add_filter('wp_insert_post_empty_content', $rejectAttachment, PHP_INT_MIN, 2);
        try {
            ll_tools_import_apply_featured_image(
                $wordImageId,
                ['file' => $sourceName, 'title' => 'Rejected Thumbnail'],
                $extractDir,
                'rejected-thumbnail',
                $result,
                'word_image',
                true
            );
        } finally {
            remove_filter('wp_insert_post_empty_content', $rejectAttachment, PHP_INT_MIN);
        }

        $this->assertSame($existingAttachmentId, (int) get_post_thumbnail_id($wordImageId));
        $this->assertSame(0, (int) ($result['stats']['attachments_imported'] ?? -1));
        $this->assertSame([], (array) ($result['undo']['attachment_ids'] ?? []));
        $this->assertNotEmpty((array) ($result['errors'] ?? []));
        $this->assertFileDoesNotExist($copiedPath);

        wp_delete_attachment($existingAttachmentId, true);
        ll_tools_rrmdir($extractDir);
    }

    public function test_wordset_template_import_preserves_category_owner_when_global_isolation_is_disabled(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $suffix = strtolower(wp_generate_password(8, false, false));
        $wordsetSlug = sanitize_title('template-owner-disabled-' . $suffix);
        $categorySlug = sanitize_title('template-owned-category-' . $suffix);
        $payload = $this->minimalTemplatePayload($wordsetSlug);
        $payload['categories'] = [[
            'slug' => $categorySlug,
            'name' => 'Template Owned Category ' . $suffix,
            'description' => '',
            'meta' => [],
        ]];
        $originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, false);

        try {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0');
            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        } finally {
            if ($originalIsolationOption === false) {
                delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
            } else {
                update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $originalIsolationOption);
            }
        }

        $this->assertIsArray($result);
        $this->assertTrue(!empty($result['ok']), implode(' | ', (array) ($result['errors'] ?? [])));
        $wordsetIds = array_values(array_map('intval', (array) ($result['undo']['wordset_term_ids'] ?? [])));
        $categoryIds = array_values(array_map('intval', (array) ($result['undo']['category_term_ids'] ?? [])));
        $this->assertCount(1, $wordsetIds);
        $this->assertCount(1, $categoryIds);
        $this->assertSame($wordsetIds[0], (int) get_term_meta($categoryIds[0], LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
        $this->assertSame($categoryIds[0], (int) get_term_meta($categoryIds[0], LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, true));
        $category = get_term($categoryIds[0], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        $this->assertSame(
            ll_tools_build_isolated_category_slug((string) $category->name, $wordsetIds[0]),
            (string) $category->slug
        );
    }

    public function test_wordset_template_import_ignores_explicit_public_visibility_and_stays_private(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $suffix = wp_generate_password(8, false, false);
        $slug = sanitize_title('private-template-import-' . $suffix);
        $payload = $this->minimalTemplatePayload($slug, [
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY => ['public'],
        ]);

        $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        $wordset = get_term_by('slug', $slug, 'wordset');

        $this->assertIsArray($result);
        $this->assertTrue(!empty($result['ok']), (string) ($result['message'] ?? ''));
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->assertSame('private', (string) get_term_meta((int) $wordset->term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
    }

    public function test_wordset_template_import_ignores_case_and_space_variants_of_public_visibility_meta(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $suffix = wp_generate_password(8, false, false);
        $slug = sanitize_title('variant-private-template-import-' . $suffix);
        $payload = $this->minimalTemplatePayload($slug, [
            strtoupper(LL_TOOLS_WORDSET_VISIBILITY_META_KEY) . ' ' => ['public'],
        ]);

        $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        $wordset = get_term_by('slug', $slug, 'wordset');

        $this->assertIsArray($result);
        $this->assertTrue(!empty($result['ok']), (string) ($result['message'] ?? ''));
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->assertSame('private', (string) get_term_meta((int) $wordset->term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
        $this->assertFalse(metadata_exists('term', (int) $wordset->term_id, strtoupper(LL_TOOLS_WORDSET_VISIBILITY_META_KEY) . ' '));
    }

    public function test_wordset_template_import_shields_created_hooks_from_a_term_id_remap_and_rolls_back_owned_staging(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $sentinel = wp_insert_term('Template Remap Sentinel ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($sentinel);
        $sentinelId = (int) ($sentinel['term_id'] ?? 0);
        update_term_meta($sentinelId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'public');
        update_term_meta($sentinelId, 'll_template_remap_sentinel', 'unchanged');

        $enabledBefore = ll_tools_get_vocab_lesson_wordset_ids();
        $enabledWithoutSentinel = array_values(array_filter($enabledBefore, static function (int $wordsetId) use ($sentinelId): bool {
            return $wordsetId !== $sentinelId;
        }));
        update_option('ll_vocab_lesson_wordsets', $enabledWithoutSentinel, false);
        delete_term_meta($sentinelId, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY);

        $rawWordsetId = 0;
        $createdHookId = 0;
        $remap = static function ($termId, $termTaxonomyId, $args = []) use ($sentinelId, &$rawWordsetId) {
            if (is_array($args) && !empty($args['_ll_tools_template_import_token'])) {
                $rawWordsetId = (int) $termId;
                return $sentinelId;
            }
            return $termId;
        };
        $captureCreated = static function ($termId) use (&$createdHookId): void {
            $createdHookId = (int) $termId;
        };

        add_filter('term_id_filter', $remap, 10, 3);
        add_action('created_wordset', $captureCreated, 19, 1);
        try {
            $suffix = wp_generate_password(8, false, false);
            $slug = sanitize_title('remapped-template-import-' . $suffix);
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());

            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertSame($rawWordsetId, $createdHookId, 'Created hooks must see the raw owned ID, never the remap target.');
            $this->assertNotInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $this->assertSame('', (string) get_term_meta($rawWordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertNotContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $this->assertInstanceOf(WP_Term::class, get_term($sentinelId, 'wordset'));
            $this->assertSame('public', (string) get_term_meta($sentinelId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertSame('unchanged', (string) get_term_meta($sentinelId, 'll_template_remap_sentinel', true));
            $this->assertFalse(metadata_exists('term', $sentinelId, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY));
            $this->assertNotContains($sentinelId, ll_tools_get_vocab_lesson_wordset_ids());
        } finally {
            remove_filter('term_id_filter', $remap, 10);
            remove_action('created_wordset', $captureCreated, 19);
            update_option('ll_vocab_lesson_wordsets', $enabledBefore, false);
        }
    }

    public function test_wordset_template_import_ignores_a_same_priority_nested_token_clone_before_outer_capture(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $nestedId = 0;
        $nesting = false;
        $createNested = static function ($data, $taxonomy, $args = []) use (&$nestedId, &$nesting) {
            if ($nesting || $taxonomy !== 'wordset' || empty($args['_ll_tools_template_import_token'])) {
                return $data;
            }
            $nesting = true;
            try {
                $nestedArgs = $args;
                $nestedArgs['slug'] = sanitize_title('nested-token-clone-' . wp_generate_password(8, false, false));
                $nested = wp_insert_term('Nested Token Clone ' . wp_generate_password(6, false), 'wordset', $nestedArgs);
                if (is_array($nested) && !is_wp_error($nested)) {
                    $nestedId = (int) ($nested['term_id'] ?? 0);
                }
            } finally {
                $nesting = false;
            }
            return $data;
        };

        // Register before the importer adds its own PHP_INT_MAX capture. The
        // nested insertion therefore completes before the outer capture runs.
        add_filter('wp_insert_term_data', $createNested, PHP_INT_MAX, 3);
        try {
            $suffix = wp_generate_password(8, false, false);
            $slug = sanitize_title('outer-template-import-' . $suffix);
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
            $wordset = get_term_by('slug', $slug, 'wordset');

            $this->assertIsArray($result);
            $this->assertTrue(!empty($result['ok']), (string) ($result['message'] ?? ''));
            $this->assertInstanceOf(WP_Term::class, $wordset);
            $this->assertSame('private', (string) get_term_meta((int) $wordset->term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertGreaterThan(0, $nestedId);
            $this->assertNotSame((int) $wordset->term_id, $nestedId);
            $this->assertSame('', (string) get_term_meta($nestedId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertSame('public', ll_tools_get_wordset_visibility($nestedId));
        } finally {
            remove_filter('wp_insert_term_data', $createNested, PHP_INT_MAX);
            if ($nestedId > 0) {
                wp_delete_term($nestedId, 'wordset');
            }
        }
    }

    public function test_wordset_template_import_rejects_spoofed_private_metadata_readback(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $rawWordsetId = 0;
        $rejectVisibilityWrite = static function ($check, $objectId, $metaKey) use (&$rawWordsetId) {
            if ((string) $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY) {
                $rawWordsetId = (int) $objectId;
                return false;
            }
            return $check;
        };
        $spoofPrivateRead = static function ($check, $objectId, $metaKey) {
            return (string) $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY ? 'private' : $check;
        };

        add_filter('update_term_metadata', $rejectVisibilityWrite, 10, 3);
        add_filter('get_term_metadata', $spoofPrivateRead, 10, 3);
        try {
            $suffix = wp_generate_password(8, false, false);
            $slug = sanitize_title('spoofed-private-template-' . $suffix);
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('update_term_metadata', $rejectVisibilityWrite, 10);
            remove_filter('get_term_metadata', $spoofPrivateRead, 10);
        }

        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertGreaterThan(0, $rawWordsetId);
        $survivor = get_term($rawWordsetId, 'wordset');
        if ($survivor instanceof WP_Term) {
            $complete = true;
            $this->assertTrue(ll_tools_is_wordset_private($rawWordsetId, $complete));
            $this->assertTrue($complete);
        } else {
            $this->assertNotInstanceOf(WP_Term::class, $survivor);
        }
    }

    public function test_wordset_template_import_rejects_effective_visibility_override_to_public(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $forcePublic = static function (): string {
            return 'public';
        };
        add_filter('ll_tools_wordset_visibility', $forcePublic, 10, 0);
        try {
            $suffix = wp_generate_password(8, false, false);
            $slug = sanitize_title('forced-public-template-' . $suffix);
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('ll_tools_wordset_visibility', $forcePublic, 10);
        }

        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $wordset = get_term_by('slug', $slug, 'wordset');
        if ($wordset instanceof WP_Term) {
            $this->assertSame('private', (string) get_term_meta((int) $wordset->term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
        } else {
            $this->assertFalse($wordset);
        }
    }

    public function test_wordset_template_import_keeps_owned_wordset_private_when_assembly_throws(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $suffix = wp_generate_password(8, false, false);
        $slug = sanitize_title('throwing-template-import-' . $suffix);
        $payload = $this->minimalTemplatePayload($slug);
        $payload['categories'] = [[
            'slug' => 'throwing-category-' . $suffix,
            'name' => 'Throwing Category ' . $suffix,
            'description' => '',
            'meta' => [],
        ]];
        $throw = static function (): void {
            throw new RuntimeException('Intentional template assembly failure.');
        };

        add_action('created_word-category', $throw, PHP_INT_MAX, 0);
        try {
            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        } finally {
            remove_action('created_word-category', $throw, PHP_INT_MAX);
        }
        $wordset = get_term_by('slug', $slug, 'wordset');

        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->assertSame('private', (string) get_term_meta((int) $wordset->term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
    }

    public function test_wordset_template_import_seeds_private_before_created_hooks_and_finalizes_cache_fence(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $createdWordsetId = 0;
        $visibilityBeforeInitialEpoch = null;
        $epochAfterInitialBump = 0;
        $staticCacheFile = '';
        $buttonCacheKey = 'll_ws_btn_template_race_' . strtolower(wp_generate_password(8, false, false));
        $buttonCacheKeysOption = 'll_tools_wordset_buttons_shortcode_cache_keys';
        $originalButtonCacheKeys = get_option($buttonCacheKeysOption, null);

        $observeSeededVisibility = static function ($termId) use (&$createdWordsetId, &$visibilityBeforeInitialEpoch): void {
            $createdWordsetId = (int) $termId;
            $visibilityBeforeInitialEpoch = (string) get_term_meta(
                $createdWordsetId,
                LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
                true
            );
        };
        $observeInitialEpoch = static function ($termId) use (&$createdWordsetId, &$epochAfterInitialBump): void {
            if ((int) $termId === $createdWordsetId) {
                $epochAfterInitialBump = ll_tools_get_wordset_cache_epoch();
            }
        };
        $seedMidImportCacheArtifacts = static function () use (&$createdWordsetId, &$staticCacheFile, $buttonCacheKey, $buttonCacheKeysOption): void {
            if ($createdWordsetId <= 0 || $staticCacheFile !== '') {
                return;
            }

            $cacheDir = ll_tools_public_static_cache_dir();
            if ($cacheDir !== '' && wp_mkdir_p($cacheDir)) {
                $staticCacheFile = trailingslashit($cacheDir) . 'public-template-import-race-' . $createdWordsetId . '.html';
                file_put_contents($staticCacheFile, '<!doctype html><html><body>mid-import</body></html>');
                ll_tools_public_static_cache_write_meta($staticCacheFile, md5($staticCacheFile), [
                    'type' => 'wordset_main',
                    'id' => $createdWordsetId,
                    'path' => '/template-import-race/',
                    'wordset_id' => $createdWordsetId,
                ]);
            }

            set_transient($buttonCacheKey, 'mid-import', HOUR_IN_SECONDS);
            $registeredKeys = get_option($buttonCacheKeysOption, []);
            $registeredKeys = is_array($registeredKeys) ? $registeredKeys : [];
            $registeredKeys[] = $buttonCacheKey;
            update_option($buttonCacheKeysOption, array_values(array_unique($registeredKeys)), false);
        };

        add_action('created_wordset', $observeSeededVisibility, 19, 1);
        add_action('created_wordset', $observeInitialEpoch, 25, 1);
        add_action('created_word-category', $seedMidImportCacheArtifacts, 40, 0);
        try {
            $suffix = wp_generate_password(8, false, false);
            $slug = sanitize_title('template-cache-fence-' . $suffix);
            $payload = $this->minimalTemplatePayload($slug, [
                LL_TOOLS_WORDSET_VISIBILITY_META_KEY => ['public'],
            ]);
            $payload['categories'] = [[
                'slug' => sanitize_title('template-cache-category-' . $suffix),
                'name' => 'Template Cache Category ' . $suffix,
                'description' => '',
                'meta' => [],
            ]];

            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
            $wordset = get_term_by('slug', $slug, 'wordset');

            $this->assertTrue(!empty($result['ok']), (string) ($result['message'] ?? ''));
            $this->assertInstanceOf(WP_Term::class, $wordset);
            $this->assertSame((int) $wordset->term_id, $createdWordsetId);
            $this->assertSame('private', $visibilityBeforeInitialEpoch);
            $this->assertGreaterThan(0, $epochAfterInitialBump);
            $this->assertSame('private', (string) get_term_meta($createdWordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertGreaterThan($epochAfterInitialBump, ll_tools_get_wordset_cache_epoch());
            $this->assertNotSame('', $staticCacheFile);
            $this->assertFileDoesNotExist($staticCacheFile);
            $this->assertFalse(get_transient($buttonCacheKey));
        } finally {
            remove_action('created_wordset', $observeSeededVisibility, 19);
            remove_action('created_wordset', $observeInitialEpoch, 25);
            remove_action('created_word-category', $seedMidImportCacheArtifacts, 40);
            delete_transient($buttonCacheKey);
            if ($originalButtonCacheKeys === null) {
                delete_option($buttonCacheKeysOption);
            } else {
                update_option($buttonCacheKeysOption, $originalButtonCacheKeys, false);
            }
            if ($staticCacheFile !== '' && is_file($staticCacheFile)) {
                unlink($staticCacheFile);
            }
            if ($staticCacheFile !== '') {
                $staticMetaFile = ll_tools_public_static_cache_meta_file_path($staticCacheFile);
                if ($staticMetaFile !== '' && is_file($staticMetaFile)) {
                    unlink($staticMetaFile);
                }
            }
        }
    }

    public function test_wordset_template_import_rolls_back_a_bare_term_when_term_taxonomy_insert_throws(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $termTaxonomyInsertWasReached = false;
        $throwTermTaxonomyInsert = static function (string $query) use ($wpdb, &$rawWordsetId, &$termTaxonomyInsertWasReached): string {
            if (
                !$termTaxonomyInsertWasReached
                && str_starts_with(ltrim($query), 'INSERT INTO')
                && str_contains($query, (string) $wpdb->term_taxonomy)
            ) {
                $termTaxonomyInsertWasReached = true;
                $markerResult = mysqli_query($wpdb->dbh, 'SELECT LAST_INSERT_ID()');
                if ($markerResult !== false) {
                    $markerRow = mysqli_fetch_row($markerResult);
                    mysqli_free_result($markerResult);
                    $rawWordsetId = is_array($markerRow) ? (int) ($markerRow[0] ?? 0) : 0;
                }
                throw new RuntimeException('Synthetic term-taxonomy insert failure.');
            }
            return $query;
        };
        add_filter('query', $throwTermTaxonomyInsert);
        try {
            $slug = sanitize_title('template-bare-term-rollback-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('query', $throwTermTaxonomyInsert);
        }

        try {
            $this->assertTrue($termTaxonomyInsertWasReached);
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertSame(0, (int) ($result['stats']['wordsets_created'] ?? 0));
            $this->assertNotContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $rawWordsetId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                $rawWordsetId
            )));
            $this->assertStringContainsString(
                'template_wordset_insert_threw',
                implode("\n", (array) ($result['errors'] ?? []))
            );
        } finally {
            if ($rawWordsetId > 0) {
                $wpdb->delete($wpdb->termmeta, ['term_id' => $rawWordsetId], ['%d']);
                $wpdb->delete($wpdb->term_taxonomy, ['term_id' => $rawWordsetId], ['%d']);
                $wpdb->delete($wpdb->terms, ['term_id' => $rawWordsetId], ['%d']);
            }
        }
    }

    public function test_wordset_template_import_fails_closed_when_transaction_readiness_filter_throws(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $slug = sanitize_title('template-transaction-filter-throw-' . wp_generate_password(8, false, false));
        $filterWasCalled = false;
        $throwReadinessFilter = static function () use (&$filterWasCalled): bool {
            $filterWasCalled = true;
            throw new RuntimeException('Synthetic transaction-readiness failure.');
        };
        add_filter('ll_tools_strict_term_insert_tables_are_transactional', $throwReadinessFilter);
        try {
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('ll_tools_strict_term_insert_tables_are_transactional', $throwReadinessFilter);
        }

        $this->assertTrue($filterWasCalled);
        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertFalse(get_term_by('slug', $slug, 'wordset'));
        $this->assertStringContainsString(
            'template_wordset_transaction_unavailable',
            implode("\n", (array) ($result['errors'] ?? []))
        );
    }

    public function test_wordset_template_import_converts_a_throwing_slug_lookup_to_a_typed_failure(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $slug = sanitize_title('template-slug-read-throw-' . wp_generate_password(8, false, false));
        $lookupWasReached = false;
        $throwSlugLookup = static function (string $query) use ($wpdb, $slug, &$lookupWasReached): string {
            if (
                !$lookupWasReached
                && str_starts_with(ltrim($query), 'SELECT')
                && str_contains($query, (string) $wpdb->terms)
                && str_contains($query, $slug)
            ) {
                $lookupWasReached = true;
                throw new RuntimeException('Synthetic template slug lookup failure.');
            }
            return $query;
        };
        add_filter('query', $throwSlugLookup);
        try {
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('query', $throwSlugLookup);
        }

        $this->assertTrue($lookupWasReached);
        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertFalse(get_term_by('slug', $slug, 'wordset'));
        $this->assertStringContainsString(
            'template_wordset_slug_read_failed',
            implode("\n", (array) ($result['errors'] ?? []))
        );
    }

    public function test_wordset_template_import_cleans_a_mapping_whose_term_row_disappears_before_liveness_proof(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $rawWordsetTtId = 0;
        $termRowWasRemoved = false;
        $captureWordset = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawWordsetId, &$rawWordsetTtId): void {
            if ((string) $taxonomy === 'wordset' && is_array($args) && !empty($args['_ll_tools_template_import_token'])) {
                $rawWordsetId = (int) $termId;
                $rawWordsetTtId = (int) $termTaxonomyId;
            }
        };
        $removeTermBeforeProof = static function (string $query) use ($wpdb, &$rawWordsetId, &$termRowWasRemoved): string {
            if (
                !$termRowWasRemoved
                && $rawWordsetId > 0
                && str_contains($query, "FROM {$wpdb->term_taxonomy} AS tt")
                && str_contains($query, "INNER JOIN {$wpdb->terms} AS t")
            ) {
                $termRowWasRemoved = true;
                $wpdb->delete($wpdb->terms, ['term_id' => $rawWordsetId], ['%d']);
            }
            return $query;
        };
        add_action('create_term', $captureWordset, PHP_INT_MIN + 1, 4);
        add_filter('query', $removeTermBeforeProof);
        try {
            $slug = sanitize_title('template-preproof-term-loss-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_action('create_term', $captureWordset, PHP_INT_MIN + 1);
            remove_filter('query', $removeTermBeforeProof);
        }

        $this->assertTrue($termRowWasRemoved);
        $this->assertGreaterThan(0, $rawWordsetId);
        $this->assertGreaterThan(0, $rawWordsetTtId);
        $this->assertFalse(!empty($result['ok']));
        $this->assertSame(0, (int) ($result['stats']['wordsets_created'] ?? 0));
        $this->assertNotContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $rawWordsetTtId
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawWordsetId
        )));
    }

    public function test_wordset_template_import_reverses_stats_and_undo_when_final_liveness_proof_fails(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $rawWordsetTtId = 0;
        $livenessReads = 0;
        $termRowWasRemoved = false;
        $captureWordset = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawWordsetId, &$rawWordsetTtId): void {
            if ((string) $taxonomy === 'wordset' && is_array($args) && !empty($args['_ll_tools_template_import_token'])) {
                $rawWordsetId = (int) $termId;
                $rawWordsetTtId = (int) $termTaxonomyId;
            }
        };
        $removeTermAtFinalProof = static function (string $query) use (
            $wpdb,
            &$rawWordsetId,
            &$livenessReads,
            &$termRowWasRemoved
        ): string {
            if (
                $rawWordsetId > 0
                && str_contains($query, "FROM {$wpdb->term_taxonomy} AS tt")
                && str_contains($query, "INNER JOIN {$wpdb->terms} AS t")
            ) {
                $livenessReads++;
                if ($livenessReads === 2) {
                    $termRowWasRemoved = true;
                    $wpdb->delete($wpdb->terms, ['term_id' => $rawWordsetId], ['%d']);
                }
            }
            return $query;
        };
        add_action('create_term', $captureWordset, PHP_INT_MIN + 1, 4);
        add_filter('query', $removeTermAtFinalProof);
        try {
            $slug = sanitize_title('template-final-term-loss-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_action('create_term', $captureWordset, PHP_INT_MIN + 1);
            remove_filter('query', $removeTermAtFinalProof);
        }

        $this->assertTrue($termRowWasRemoved);
        $this->assertGreaterThanOrEqual(2, $livenessReads);
        $this->assertFalse(!empty($result['ok']));
        $this->assertSame(0, (int) ($result['stats']['wordsets_created'] ?? 0));
        $this->assertNotContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $rawWordsetTtId
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawWordsetId
        )));
    }

    public function test_wordset_template_import_rejects_duplicate_winner_without_mutating_it_or_leaving_visibility_meta(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $sentinelInsert = wp_insert_term('Template Duplicate Sentinel ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($sentinelInsert);
        $sentinelId = (int) ($sentinelInsert['term_id'] ?? 0);
        $sentinel = get_term($sentinelId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $sentinel);
        update_term_meta($sentinelId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'public');
        update_term_meta($sentinelId, 'll_template_duplicate_sentinel', 'unchanged');
        $vocabOptionBefore = get_option('ll_vocab_lesson_wordsets', null);
        $rawWordsetId = 0;

        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = [], $termTaxonomyId = 0) use ($sentinel, &$rawWordsetId) {
            global $wpdb;
            if ((string) $taxonomy !== 'wordset' || !is_array($args) || empty($args['_ll_tools_template_import_token'])) {
                return $duplicateTerm;
            }
            $rawWordsetId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                (int) $termTaxonomyId
            ));
            return $sentinel;
        };

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 5);
        try {
            $slug = sanitize_title('template-duplicate-race-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());

            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertSame(0, (int) ($result['stats']['wordsets_created'] ?? 0));
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertNotSame($sentinelId, $rawWordsetId);
            $this->assertNotInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s",
                $rawWordsetId,
                LL_TOOLS_WORDSET_VISIBILITY_META_KEY
            )));
            $this->assertSame('public', (string) get_term_meta($sentinelId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertSame('unchanged', (string) get_term_meta($sentinelId, 'll_template_duplicate_sentinel', true));
            $this->assertSame($vocabOptionBefore, get_option('ll_vocab_lesson_wordsets', null));
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
            wp_delete_term($sentinelId, 'wordset');
        }
    }

    public function test_wordset_template_import_reports_critical_when_private_write_and_cleanup_are_blocked(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $visibilityWrites = 0;
        $rejectVisibilityWrite = static function ($check, $objectId, $metaKey) use (&$rawWordsetId, &$visibilityWrites) {
            if ((string) $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY) {
                global $wpdb;
                $rawWordsetId = (int) $objectId;
                $visibilityWrites++;
                // Let the in-transaction creation hook establish privacy, then
                // fail the post-commit privacy proof so cleanup is exercised.
                if ($visibilityWrites > 1) {
                    $wpdb->update(
                        $wpdb->termmeta,
                        ['meta_value' => 'public'],
                        [
                            'term_id' => $rawWordsetId,
                            'meta_key' => LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
                        ],
                        ['%s'],
                        ['%d', '%s']
                    );
                    return false;
                }
                return $check;
            }
            return $check;
        };
        $blockDelete = static function ($termId, $taxonomy) use (&$rawWordsetId): void {
            if ((int) $termId === $rawWordsetId && (string) $taxonomy === 'wordset') {
                throw new RuntimeException('Intentional template cleanup failure.');
            }
        };
        add_filter('update_term_metadata', $rejectVisibilityWrite, 10, 3);
        add_action('pre_delete_term', $blockDelete, PHP_INT_MIN, 2);
        try {
            $slug = sanitize_title('template-blocked-cleanup-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('update_term_metadata', $rejectVisibilityWrite, 10);
            remove_action('pre_delete_term', $blockDelete, PHP_INT_MIN);
        }

        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertSame(0, (int) ($result['stats']['wordsets_created'] ?? 0));
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertGreaterThan(1, $visibilityWrites);
            $this->assertInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $this->assertContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('CRITICAL: template_wordset_cleanup_failed', $errors);
            $this->assertStringContainsString('cleanup_status=cleanup_failed_retained_for_undo', $errors);
        } finally {
            if ($rawWordsetId > 0) {
                wp_delete_term($rawWordsetId, 'wordset');
            }
        }
    }

    public function test_wordset_template_import_does_not_treat_mapping_read_throw_as_confirmed_cleanup(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $mappingReads = 0;
        $captureWordset = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawWordsetId): void {
            if ((string) $taxonomy === 'wordset' && is_array($args) && !empty($args['_ll_tools_template_import_token'])) {
                $rawWordsetId = (int) $termId;
            }
        };
        $blockDelete = static function ($termId, $taxonomy) use (&$rawWordsetId): void {
            if ((int) $termId === $rawWordsetId && (string) $taxonomy === 'wordset') {
                throw new RuntimeException('Intentional template cleanup failure.');
            }
        };
        $failMappingRead = static function ($query) use (&$mappingReads, &$rawWordsetId, $wpdb) {
            if (
                $rawWordsetId > 0
                && str_contains((string) $query, "FROM {$wpdb->term_taxonomy} AS tt")
                && str_contains((string) $query, "INNER JOIN {$wpdb->terms} AS t")
            ) {
                $mappingReads++;
                throw new RuntimeException('Intentional template mapping read failure.');
            }
            return $query;
        };
        add_action('create_term', $captureWordset, PHP_INT_MIN + 1, 4);
        add_action('pre_delete_term', $blockDelete, PHP_INT_MIN, 2);
        add_filter('query', $failMappingRead, PHP_INT_MAX, 1);
        try {
            $slug = sanitize_title('template-mapping-read-failure-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_action('create_term', $captureWordset, PHP_INT_MIN + 1);
            remove_action('pre_delete_term', $blockDelete, PHP_INT_MIN);
            remove_filter('query', $failMappingRead, PHP_INT_MAX);
        }

        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertSame(2, $mappingReads);
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $this->assertContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('CRITICAL: template_wordset_cleanup_failed', $errors);
            $this->assertStringContainsString('cleanup_status=ownership_read_failed_retained_for_undo', $errors);
        } finally {
            if ($rawWordsetId > 0) {
                wp_delete_term($rawWordsetId, 'wordset');
            }
        }
    }

    public function test_wordset_template_category_settings_failure_rolls_back_and_untracks_the_created_category(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $suffix = wp_generate_password(8, false, false);
        $wordsetSlug = sanitize_title('template-category-settings-failure-' . $suffix);
        $categorySlug = sanitize_title('template-category-settings-target-' . $suffix);
        $payload = $this->minimalTemplatePayload($wordsetSlug);
        $payload['categories'] = [[
            'slug' => $categorySlug,
            'name' => 'Template Settings Target ' . $suffix,
            'description' => '',
            'meta' => ['ll_quiz_prompt_type' => ['image']],
        ]];
        $rawCategoryId = 0;
        $captureCategory = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawCategoryId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawCategoryId = (int) $termId;
            }
        };
        $rejectSetting = static function ($check, $objectId, $metaKey) {
            return (string) $metaKey === 'll_quiz_prompt_type' ? false : $check;
        };

        add_action('create_term', $captureCategory, PHP_INT_MIN + 1, 4);
        add_filter('update_term_metadata', $rejectSetting, 10, 3);
        try {
            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        } finally {
            remove_action('create_term', $captureCategory, PHP_INT_MIN + 1);
            remove_filter('update_term_metadata', $rejectSetting, 10);
        }

        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertGreaterThan(0, $rawCategoryId);
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawCategoryId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawCategoryId
        )));
        $this->assertSame(0, (int) ($result['stats']['categories_created'] ?? 0));
        $this->assertNotContains($rawCategoryId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertStringContainsString(
            'Failed to import settings for category',
            implode("\n", (array) ($result['errors'] ?? []))
        );
    }

    public function test_wordset_template_import_reports_a_regular_wordset_meta_write_failure(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $slug = sanitize_title('template-wordset-meta-failure-' . wp_generate_password(8, false, false));
        $metaKey = 'll_wordset_hide_lesson_text_for_non_text_quiz';
        $wordsetId = 0;
        $regularMetaWasBlocked = false;
        $followOnWriteWasAttempted = false;
        $followOnMetaKeys = [
            'll_wordset_category_manual_order',
            'll_wordset_category_prerequisites',
            'manager_user_id',
        ];
        $blockAdd = static function ($check, $objectId, $candidateMetaKey) use (
            $metaKey,
            $followOnMetaKeys,
            &$wordsetId,
            &$regularMetaWasBlocked,
            &$followOnWriteWasAttempted
        ) {
            if ($regularMetaWasBlocked && in_array((string) $candidateMetaKey, $followOnMetaKeys, true)) {
                $followOnWriteWasAttempted = true;
            }
            if ((string) $candidateMetaKey === $metaKey) {
                $wordsetId = (int) $objectId;
                $regularMetaWasBlocked = true;
                return false;
            }

            return $check;
        };
        $observeFollowOnWrite = static function ($check, $objectId, $candidateMetaKey) use (
            $followOnMetaKeys,
            &$regularMetaWasBlocked,
            &$followOnWriteWasAttempted
        ) {
            if ($regularMetaWasBlocked && in_array((string) $candidateMetaKey, $followOnMetaKeys, true)) {
                $followOnWriteWasAttempted = true;
            }

            return $check;
        };
        add_filter('add_term_metadata', $blockAdd, 10, 3);
        add_filter('update_term_metadata', $observeFollowOnWrite, 10, 3);
        add_filter('delete_term_metadata', $observeFollowOnWrite, 10, 3);

        try {
            $result = ll_tools_import_from_payload(
                $this->minimalTemplatePayload($slug, [$metaKey => ['1']]),
                sys_get_temp_dir()
            );
        } finally {
            remove_filter('add_term_metadata', $blockAdd, 10);
            remove_filter('update_term_metadata', $observeFollowOnWrite, 10);
            remove_filter('delete_term_metadata', $observeFollowOnWrite, 10);
        }

        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertGreaterThan(0, $wordsetId);
            $this->assertInstanceOf(WP_Term::class, get_term($wordsetId, 'wordset'));
            $this->assertSame('', (string) get_term_meta($wordsetId, $metaKey, true));
            $this->assertTrue($regularMetaWasBlocked);
            $this->assertFalse($followOnWriteWasAttempted);
            $this->assertContains($wordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('Failed to update word set', $errors);
            $this->assertStringContainsString('Import request did not complete', $errors);
        } finally {
            if ($wordsetId > 0) {
                wp_delete_term($wordsetId, 'wordset');
            }
        }
    }

    public function test_wordset_template_import_reports_a_short_circuited_manual_order_write(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $suffix = wp_generate_password(8, false, false);
        $wordsetSlug = sanitize_title('template-manual-order-failure-' . $suffix);
        $categorySlug = sanitize_title('template-manual-order-target-' . $suffix);
        $payload = $this->minimalTemplatePayload($wordsetSlug);
        $payload['categories'] = [[
            'slug' => $categorySlug,
            'name' => 'Template Manual Order Target ' . $suffix,
            'description' => '',
            'meta' => [],
        ]];
        $payload['wordsets'][0]['template_category_manual_order'] = [$categorySlug];
        $wordsetId = 0;
        $manualOrderWasBlocked = false;
        $followOnWriteWasAttempted = false;
        $followOnMetaKeys = ['ll_wordset_category_prerequisites', 'manager_user_id'];
        $blockAdd = static function ($check, $objectId, $metaKey) use (
            $followOnMetaKeys,
            &$wordsetId,
            &$manualOrderWasBlocked,
            &$followOnWriteWasAttempted
        ) {
            if ($manualOrderWasBlocked && in_array((string) $metaKey, $followOnMetaKeys, true)) {
                $followOnWriteWasAttempted = true;
            }
            if ((string) $metaKey === 'll_wordset_category_manual_order') {
                $wordsetId = (int) $objectId;
                $manualOrderWasBlocked = true;
                return false;
            }

            return $check;
        };
        $observeFollowOnWrite = static function ($check, $objectId, $metaKey) use (
            $followOnMetaKeys,
            &$manualOrderWasBlocked,
            &$followOnWriteWasAttempted
        ) {
            if ($manualOrderWasBlocked && in_array((string) $metaKey, $followOnMetaKeys, true)) {
                $followOnWriteWasAttempted = true;
            }

            return $check;
        };
        add_filter('add_term_metadata', $blockAdd, 10, 3);
        add_filter('update_term_metadata', $observeFollowOnWrite, 10, 3);
        add_filter('delete_term_metadata', $observeFollowOnWrite, 10, 3);

        try {
            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        } finally {
            remove_filter('add_term_metadata', $blockAdd, 10);
            remove_filter('update_term_metadata', $observeFollowOnWrite, 10);
            remove_filter('delete_term_metadata', $observeFollowOnWrite, 10);
        }

        $categoryIds = array_values(array_map('intval', (array) ($result['undo']['category_term_ids'] ?? [])));
        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertGreaterThan(0, $wordsetId);
            $this->assertInstanceOf(WP_Term::class, get_term($wordsetId, 'wordset'));
            $this->assertSame([], get_term_meta($wordsetId, 'll_wordset_category_manual_order', false));
            $this->assertSame([], get_term_meta($wordsetId, 'll_wordset_category_prerequisites', false));
            $this->assertTrue($manualOrderWasBlocked);
            $this->assertFalse($followOnWriteWasAttempted);
            $this->assertContains($wordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('Failed to update word set', $errors);
            $this->assertStringContainsString('Import request did not complete', $errors);
        } finally {
            foreach ($categoryIds as $categoryId) {
                if ($categoryId > 0) {
                    wp_delete_term($categoryId, 'word-category');
                }
            }
            if ($wordsetId > 0) {
                wp_delete_term($wordsetId, 'wordset');
            }
        }
    }

    public function test_wordset_template_category_cleanup_failure_retains_undo_evidence(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $suffix = wp_generate_password(8, false, false);
        $wordsetSlug = sanitize_title('template-category-cleanup-failure-' . $suffix);
        $categorySlug = sanitize_title('template-category-cleanup-target-' . $suffix);
        $payload = $this->minimalTemplatePayload($wordsetSlug);
        $payload['categories'] = [[
            'slug' => $categorySlug,
            'name' => 'Template Cleanup Target ' . $suffix,
            'description' => '',
            'meta' => ['ll_quiz_prompt_type' => ['image']],
        ]];
        $rawCategoryId = 0;
        $captureCategory = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawCategoryId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawCategoryId = (int) $termId;
            }
        };
        $rejectSetting = static function ($check, $objectId, $metaKey) {
            return (string) $metaKey === 'll_quiz_prompt_type' ? false : $check;
        };
        $blockCleanup = static function ($termId, $taxonomy) use (&$rawCategoryId): void {
            if ($rawCategoryId > 0 && (int) $termId === $rawCategoryId && (string) $taxonomy === 'word-category') {
                throw new RuntimeException('Intentional category cleanup failure.');
            }
        };

        add_action('create_term', $captureCategory, PHP_INT_MIN + 1, 4);
        add_filter('update_term_metadata', $rejectSetting, 10, 3);
        add_action('pre_delete_term', $blockCleanup, PHP_INT_MIN, 2);
        try {
            $result = ll_tools_import_from_payload($payload, sys_get_temp_dir());
        } finally {
            remove_action('create_term', $captureCategory, PHP_INT_MIN + 1);
            remove_filter('update_term_metadata', $rejectSetting, 10);
            remove_action('pre_delete_term', $blockCleanup, PHP_INT_MIN);
        }

        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertGreaterThan(0, $rawCategoryId);
            $this->assertInstanceOf(WP_Term::class, get_term($rawCategoryId, 'word-category'));
            $this->assertSame(0, (int) ($result['stats']['categories_created'] ?? 0));
            $this->assertContains($rawCategoryId, (array) ($result['undo']['category_term_ids'] ?? []));
            $this->assertStringContainsString(
                'CRITICAL: template_category_cleanup_failed',
                implode("\n", (array) ($result['errors'] ?? []))
            );
        } finally {
            if ($rawCategoryId > 0) {
                wp_delete_term($rawCategoryId, 'word-category');
            }
        }
    }

    public function test_wordset_template_import_fails_before_mutation_on_wordpress_older_than_6_1(): void
    {
        global $wp_version;

        $originalVersion = $wp_version;
        $suffix = wp_generate_password(8, false, false);
        $slug = sanitize_title('legacy-wp-template-import-' . $suffix);
        try {
            $wp_version = '6.0.9';
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            $wp_version = $originalVersion;
        }

        $this->assertIsArray($result);
        $this->assertFalse(!empty($result['ok']));
        $this->assertStringContainsString('requires_wordpress_6_1', implode("\n", (array) ($result['errors'] ?? [])));
        $this->assertFalse(get_term_by('slug', $slug, 'wordset'));
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
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, 'hide');
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
     * @param array<string,mixed> $wordsetMeta
     * @return array<string,mixed>
     */
    private function minimalTemplatePayload(string $slug, array $wordsetMeta = []): array
    {
        return [
            'version' => 2,
            'bundle_type' => 'wordset_template',
            'categories' => [],
            'word_images' => [],
            'wordsets' => [[
                'slug' => $slug,
                'name' => ucwords(str_replace('-', ' ', $slug)),
                'description' => '',
                'meta' => $wordsetMeta,
                'template_category_manual_order' => [],
                'template_category_prerequisites' => [],
            ]],
            'words' => [],
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
