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

    public function test_wordset_template_import_shields_created_hooks_from_a_term_id_remap_and_retains_owned_staging_private(): void
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
            $this->assertInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $this->assertSame('private', (string) get_term_meta($rawWordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
            $this->assertContains($rawWordsetId, (array) ($result['undo']['wordset_term_ids'] ?? []));
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

    public function test_wordset_template_import_captures_outer_raw_term_before_a_nested_token_clone(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $nestedId = 0;
        $nesting = false;
        $createNested = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$nestedId, &$nesting): void {
            if ($nesting || $taxonomy !== 'wordset' || empty($args['_ll_tools_template_import_token'])) {
                return;
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
        };

        add_action('create_term', $createNested, PHP_INT_MIN + 1, 4);
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
            remove_action('create_term', $createNested, PHP_INT_MIN + 1);
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
        $rejectVisibilityWrite = static function ($check, $objectId, $metaKey) use (&$rawWordsetId) {
            if ((string) $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY) {
                $rawWordsetId = (int) $objectId;
                return false;
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
            $this->assertInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('CRITICAL: template_wordset_cleanup_failed', $errors);
            $this->assertStringContainsString('cleanup_status=delete_threw', $errors);
        } finally {
            if ($rawWordsetId > 0) {
                wp_delete_term($rawWordsetId, 'wordset');
            }
        }
    }

    public function test_wordset_template_import_does_not_treat_post_delete_mapping_error_as_confirmed_cleanup(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $rawWordsetId = 0;
        $mappingReads = 0;
        $rejectVisibilityWrite = static function ($check, $objectId, $metaKey) use (&$rawWordsetId) {
            if ((string) $metaKey === LL_TOOLS_WORDSET_VISIBILITY_META_KEY) {
                $rawWordsetId = (int) $objectId;
                return false;
            }
            return $check;
        };
        $blockDelete = static function ($termId, $taxonomy) use (&$rawWordsetId): void {
            if ((int) $termId === $rawWordsetId && (string) $taxonomy === 'wordset') {
                throw new RuntimeException('Intentional template cleanup failure.');
            }
        };
        $failPostDeleteMappingRead = static function ($query) use (&$mappingReads, $wpdb) {
            if (
                strpos((string) $query, "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}") !== false
                && strpos((string) $query, 'term_taxonomy_id =') !== false
            ) {
                $mappingReads++;
                if ($mappingReads === 2) {
                    return "SELECT ll_missing_template_mapping_column FROM {$wpdb->term_taxonomy}";
                }
            }
            return $query;
        };

        add_filter('update_term_metadata', $rejectVisibilityWrite, 10, 3);
        add_action('pre_delete_term', $blockDelete, PHP_INT_MIN, 2);
        add_filter('query', $failPostDeleteMappingRead, PHP_INT_MAX, 1);
        try {
            $slug = sanitize_title('template-mapping-read-failure-' . wp_generate_password(8, false, false));
            $result = ll_tools_import_from_payload($this->minimalTemplatePayload($slug), sys_get_temp_dir());
        } finally {
            remove_filter('update_term_metadata', $rejectVisibilityWrite, 10);
            remove_action('pre_delete_term', $blockDelete, PHP_INT_MIN);
            remove_filter('query', $failPostDeleteMappingRead, PHP_INT_MAX);
        }

        try {
            $this->assertIsArray($result);
            $this->assertFalse(!empty($result['ok']));
            $this->assertGreaterThanOrEqual(2, $mappingReads);
            $this->assertGreaterThan(0, $rawWordsetId);
            $this->assertInstanceOf(WP_Term::class, get_term($rawWordsetId, 'wordset'));
            $errors = implode("\n", (array) ($result['errors'] ?? []));
            $this->assertStringContainsString('CRITICAL: template_wordset_cleanup_failed', $errors);
            $this->assertStringContainsString('cleanup_status=delete_threw_mapping_read_failed', $errors);
        } finally {
            if ($rawWordsetId > 0) {
                wp_delete_term($rawWordsetId, 'wordset');
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
