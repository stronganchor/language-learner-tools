<?php
declare(strict_types=1);

final class AssignExistingCategoryCollisionImportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W8fcAAAAASUVORK5CYII=';

    public function test_assign_existing_import_creates_clean_owned_category_when_foreign_slug_collision_exists(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $target_wordset = wp_insert_term('Target Genc Palu ' . wp_generate_password(6, false, false), 'wordset', [
            'slug' => 'target-genc-palu-' . strtolower(wp_generate_password(4, false, false)),
        ]);
        $this->assertFalse(is_wp_error($target_wordset));
        $this->assertIsArray($target_wordset);
        $target_wordset_id = (int) $target_wordset['term_id'];

        $foreign_wordset = wp_insert_term('Foreign Genc Palu ' . wp_generate_password(6, false, false), 'wordset', [
            'slug' => 'foreign-genc-palu-' . strtolower(wp_generate_password(4, false, false)),
        ]);
        $this->assertFalse(is_wp_error($foreign_wordset));
        $this->assertIsArray($foreign_wordset);
        $foreign_wordset_id = (int) $foreign_wordset['term_id'];

        $dirty_source = wp_insert_term('Quiz 2.2', 'word-category', ['slug' => 'quiz-2-2-collision-test']);
        $this->assertFalse(is_wp_error($dirty_source));
        $this->assertIsArray($dirty_source);
        $dirty_source_id = (int) $dirty_source['term_id'];

        $foreign_slug = 'takvim-1-genc-palu';
        $foreign_category = wp_insert_term('Takvim 1 Dirty', 'word-category', ['slug' => $foreign_slug]);
        $this->assertFalse(is_wp_error($foreign_category));
        $this->assertIsArray($foreign_category);
        $foreign_category_id = (int) $foreign_category['term_id'];
        update_term_meta($foreign_category_id, 'll_wordset_owner_id', $foreign_wordset_id);
        update_term_meta($foreign_category_id, 'll_category_isolation_source_id', $dirty_source_id);

        $zip_path = $this->createMinimalFullBundleZip($foreign_slug);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $target_wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $owned_slug = function_exists('ll_tools_build_isolated_category_slug')
                ? ll_tools_build_isolated_category_slug($foreign_slug, $target_wordset_id)
                : $foreign_slug;
            $owned_term = get_term_by('slug', $owned_slug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $owned_term);
            $this->assertSame('Takvim 1', (string) $owned_term->name);
            $this->assertSame($target_wordset_id, (int) get_term_meta((int) $owned_term->term_id, 'll_wordset_owner_id', true));
            $this->assertSame((int) $owned_term->term_id, (int) get_term_meta((int) $owned_term->term_id, 'll_category_isolation_source_id', true));

            $this->assertSame('Takvim 1 Dirty', (string) get_term($foreign_category_id, 'word-category')->name);
            $this->assertSame($foreign_wordset_id, (int) get_term_meta($foreign_category_id, 'll_wordset_owner_id', true));
            $this->assertSame($dirty_source_id, (int) get_term_meta($foreign_category_id, 'll_category_isolation_source_id', true));

            $word = get_page_by_path('calendar-day', OBJECT, 'words');
            $this->assertInstanceOf(WP_Post::class, $word);
            $word_categories = wp_get_post_terms((int) $word->ID, 'word-category', ['fields' => 'ids']);
            $this->assertSame([(int) $owned_term->term_id], array_values(array_map('intval', (array) $word_categories)));

            $owned_image_slug = function_exists('ll_tools_build_isolated_word_image_slug')
                ? ll_tools_build_isolated_word_image_slug('calendar-card', $target_wordset_id)
                : 'calendar-card';
            $word_image = get_page_by_path($owned_image_slug, OBJECT, 'word_images');
            $this->assertInstanceOf(WP_Post::class, $word_image);
            $this->assertSame($target_wordset_id, (int) get_post_meta((int) $word_image->ID, 'll_wordset_owner_id', true));
            $this->assertSame((int) $word_image->ID, (int) get_post_meta((int) $word_image->ID, 'll_word_image_isolation_source_id', true));
            $image_categories = wp_get_post_terms((int) $word_image->ID, 'word-category', ['fields' => 'ids']);
            $this->assertSame([(int) $owned_term->term_id], array_values(array_map('intval', (array) $image_categories)));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id((int) $word_image->ID));
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_assign_existing_import_does_not_reuse_or_track_same_name_different_slug_owned_category(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $suffix = strtolower(wp_generate_password(6, false, false));
        $target_wordset = wp_insert_term('Strict Target ' . $suffix, 'wordset', [
            'slug' => 'strict-target-' . $suffix,
        ]);
        $this->assertIsArray($target_wordset);
        $target_wordset_id = (int) $target_wordset['term_id'];

        $preserved = wp_insert_term('Takvim 1', 'word-category', [
            'slug' => 'preserved-takvim-' . $suffix,
            'description' => 'Preserved category description',
        ]);
        $this->assertIsArray($preserved);
        $preserved_id = (int) $preserved['term_id'];
        ll_tools_set_category_wordset_owner($preserved_id, $target_wordset_id, $preserved_id);
        update_term_meta($preserved_id, 'term_translation', 'Preserved translation');
        update_term_meta($preserved_id, 'll_quiz_prompt_type', 'audio');

        $import_slug = 'strict-import-' . $suffix;
        $zip_path = $this->createMinimalFullBundleZip($import_slug);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $target_wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $desired_slug = ll_tools_build_isolated_category_slug($import_slug, $target_wordset_id);
            $imported = get_term_by('slug', $desired_slug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $imported);
            $imported_id = (int) $imported->term_id;
            $this->assertNotSame($preserved_id, $imported_id);
            $this->assertSame($target_wordset_id, (int) get_term_meta($imported_id, 'll_wordset_owner_id', true));
            $this->assertSame($imported_id, (int) get_term_meta($imported_id, 'll_category_isolation_source_id', true));

            $stored_preserved = get_term($preserved_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $stored_preserved);
            $this->assertSame('preserved-takvim-' . $suffix, (string) $stored_preserved->slug);
            $this->assertSame('Preserved category description', (string) $stored_preserved->description);
            $this->assertSame('Preserved translation', get_term_meta($preserved_id, 'term_translation', true));
            $this->assertSame('audio', get_term_meta($preserved_id, 'll_quiz_prompt_type', true));

            $undo_category_ids = array_values(array_map('intval', (array) (($result['undo'] ?? [])['category_term_ids'] ?? [])));
            $this->assertContains($imported_id, $undo_category_ids);
            $this->assertNotContains($preserved_id, $undo_category_ids);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_assign_existing_upsert_rejects_duplicate_winner_without_tracking_or_mutating_it(): void
    {
        global $wpdb;

        $targetWordset = wp_insert_term('Race Target ' . wp_generate_password(6, false), 'wordset');
        $sentinelWordset = wp_insert_term('Race Sentinel Owner ' . wp_generate_password(6, false), 'wordset');
        $sentinel = wp_insert_term('Race Sentinel Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($targetWordset);
        $this->assertIsArray($sentinelWordset);
        $this->assertIsArray($sentinel);
        $targetWordsetId = (int) $targetWordset['term_id'];
        $sentinelWordsetId = (int) $sentinelWordset['term_id'];
        $sentinelId = (int) $sentinel['term_id'];
        ll_tools_set_category_wordset_owner($sentinelId, $sentinelWordsetId, $sentinelId);
        update_term_meta($sentinelId, 'term_translation', 'Keep sentinel');
        $sentinelTerm = get_term($sentinelId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $sentinelTerm);

        $rawLoserId = 0;
        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = [], $termTaxonomyId = 0) use ($sentinelTerm, &$rawLoserId) {
            global $wpdb;
            if ((string) $taxonomy !== 'word-category' || !is_array($args) || empty($args['_ll_tools_new_category_token'])) {
                return $duplicateTerm;
            }
            $rawLoserId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                (int) $termTaxonomyId
            ));
            return $sentinelTerm;
        };
        $slugMap = [];
        $result = [
            'errors' => [],
            'stats' => ll_tools_import_default_stats(),
            'undo' => ll_tools_import_default_undo_payload(),
        ];
        $sourceSlug = 'race-import-' . strtolower(wp_generate_password(6, false));

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 5);
        try {
            ll_tools_import_upsert_categories_chunk(
                [[
                    'slug' => $sourceSlug,
                    'name' => 'Race Candidate ' . wp_generate_password(6, false),
                    'description' => '',
                ]],
                $slugMap,
                $result,
                [
                    'wordset_mode' => 'assign_existing',
                    'target_wordset_id' => $targetWordsetId,
                ]
            );
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
        }

        $this->assertGreaterThan(0, $rawLoserId);
        $this->assertSame([], $slugMap);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, (int) ($result['stats']['categories_created'] ?? 0));
        $this->assertNotContains($sentinelId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertNotContains($rawLoserId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawLoserId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawLoserId
        )));
        $this->assertSame($sentinelWordsetId, ll_tools_get_category_wordset_owner_id($sentinelId));
        $this->assertSame('Keep sentinel', get_term_meta($sentinelId, 'term_translation', true));
    }

    public function test_regular_upsert_rejects_duplicate_winner_without_tracking_or_mutating_it(): void
    {
        global $wpdb;

        $sentinel = wp_insert_term('Regular Race Sentinel ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($sentinel);
        $sentinelId = (int) $sentinel['term_id'];
        update_term_meta($sentinelId, 'term_translation', 'Keep regular sentinel');
        $sentinelTerm = get_term($sentinelId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $sentinelTerm);

        $rawLoserId = 0;
        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = [], $termTaxonomyId = 0) use ($sentinelTerm, &$rawLoserId) {
            global $wpdb;
            if ((string) $taxonomy !== 'word-category' || !is_array($args) || empty($args['_ll_tools_new_category_token'])) {
                return $duplicateTerm;
            }
            $rawLoserId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                (int) $termTaxonomyId
            ));
            return $sentinelTerm;
        };
        $slugMap = [];
        $result = [
            'errors' => [],
            'stats' => ll_tools_import_default_stats(),
            'undo' => ll_tools_import_default_undo_payload(),
        ];
        $sourceSlug = 'regular-race-import-' . strtolower(wp_generate_password(6, false));

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 5);
        try {
            ll_tools_import_upsert_categories_chunk(
                [[
                    'slug' => $sourceSlug,
                    'name' => 'Regular Race Candidate ' . wp_generate_password(6, false),
                    'description' => '',
                ]],
                $slugMap,
                $result
            );
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
        }

        $this->assertGreaterThan(0, $rawLoserId);
        $this->assertSame([], $slugMap);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, (int) ($result['stats']['categories_created'] ?? 0));
        $this->assertNotContains($sentinelId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertNotContains($rawLoserId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawLoserId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawLoserId
        )));
        $this->assertSame('Keep regular sentinel', get_term_meta($sentinelId, 'term_translation', true));
    }

    public function test_assign_existing_reuse_rejects_spoofed_or_ambiguous_owner_metadata(): void
    {
        global $wpdb;

        $targetWordset = wp_insert_term('Owner Read Target ' . wp_generate_password(6, false), 'wordset');
        $foreignWordset = wp_insert_term('Owner Read Foreign ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($targetWordset);
        $this->assertIsArray($foreignWordset);
        $targetWordsetId = (int) $targetWordset['term_id'];
        $foreignWordsetId = (int) $foreignWordset['term_id'];
        $sourceSlug = 'ambiguous-owner-' . strtolower(wp_generate_password(6, false));
        $ownedSlug = ll_tools_build_isolated_category_slug($sourceSlug, $targetWordsetId);
        $sentinel = wp_insert_term('Ambiguous Owner Sentinel', 'word-category', ['slug' => $ownedSlug]);
        $this->assertIsArray($sentinel);
        $sentinelId = (int) $sentinel['term_id'];
        ll_tools_set_category_wordset_owner($sentinelId, $targetWordsetId, $sentinelId);
        $this->assertNotFalse($wpdb->insert(
            $wpdb->termmeta,
            [
                'term_id' => $sentinelId,
                'meta_key' => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                'meta_value' => (string) $foreignWordsetId,
            ],
            ['%d', '%s', '%s']
        ));
        update_term_meta($sentinelId, 'term_translation', 'Preserve ambiguous sentinel');
        $spoofOwner = static function ($check, $objectId, $metaKey) use ($sentinelId, $targetWordsetId) {
            if ((int) $objectId === $sentinelId && (string) $metaKey === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY) {
                return [(string) $targetWordsetId];
            }

            return $check;
        };
        $ownerRowsBefore = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $sentinelId,
            LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY
        ));
        $slugMap = [];
        $result = [
            'errors' => [],
            'stats' => ll_tools_import_default_stats(),
            'undo' => ll_tools_import_default_undo_payload(),
        ];

        add_filter('get_term_metadata', $spoofOwner, 10, 3);
        try {
            ll_tools_import_upsert_categories_chunk(
                [[
                    'slug' => $sourceSlug,
                    'name' => 'Imported Owner Candidate',
                    'description' => 'Do not apply',
                ]],
                $slugMap,
                $result,
                [
                    'wordset_mode' => 'assign_existing',
                    'target_wordset_id' => $targetWordsetId,
                ]
            );
        } finally {
            remove_filter('get_term_metadata', $spoofOwner, 10);
        }

        $this->assertSame([], $slugMap);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, (int) ($result['stats']['categories_updated'] ?? 0));
        $this->assertNotContains($sentinelId, (array) ($result['undo']['category_term_ids'] ?? []));
        $this->assertSame($ownerRowsBefore, $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $sentinelId,
            LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY
        )));
        $this->assertSame('Preserve ambiguous sentinel', get_term_meta($sentinelId, 'term_translation', true));
        $this->assertSame('Ambiguous Owner Sentinel', (string) get_term($sentinelId, 'word-category')->name);
    }

    public function test_category_details_rejects_an_owner_changed_after_target_resolution(): void
    {
        $targetWordset = wp_insert_term('Owner Fence Target ' . wp_generate_password(6, false), 'wordset');
        $foreignWordset = wp_insert_term('Owner Fence Foreign ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($targetWordset);
        $this->assertIsArray($foreignWordset);
        $targetWordsetId = (int) $targetWordset['term_id'];
        $foreignWordsetId = (int) $foreignWordset['term_id'];
        $sourceSlug = 'owner-fence-' . strtolower(wp_generate_password(6, false));
        $ownedSlug = ll_tools_build_isolated_category_slug($sourceSlug, $targetWordsetId);
        $category = wp_insert_term('Original Owner Fence', 'word-category', [
            'slug' => $ownedSlug,
            'description' => 'Original owner-fence description',
        ]);
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($categoryId, $targetWordsetId, $categoryId);
        update_term_meta($categoryId, 'term_translation', 'Original owner-fence translation');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text');

        // Simulate ownership changing in the gap after the upsert/resolution
        // phase and before category details acquire their settings lock.
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $foreignWordsetId);
        $result = ll_tools_import_apply_category_details_chunk(
            [[
                'slug' => $sourceSlug,
                'name' => 'Imported Owner Fence',
                'description' => 'Imported owner-fence description',
                'meta' => [
                    'term_translation' => ['Imported owner-fence translation'],
                    'll_quiz_prompt_type' => ['audio'],
                ],
            ]],
            [$sourceSlug => $categoryId],
            [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $targetWordsetId,
            ]
        );

        $this->assertWPError($result);
        $this->assertSame('owner_conflict', (string) ($result->get_error_data()['operation'] ?? ''));
        $storedCategory = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedCategory);
        $this->assertSame('Original Owner Fence', (string) $storedCategory->name);
        $this->assertSame('Original owner-fence description', (string) $storedCategory->description);
        $this->assertSame($foreignWordsetId, (int) get_term_meta($categoryId, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
        $this->assertSame('Original owner-fence translation', (string) get_term_meta($categoryId, 'term_translation', true));
        $this->assertSame('text', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
    }

    private function createMinimalFullBundleZip(string $category_slug): string
    {
        $payload = [
            'version' => 2,
            'bundle_type' => 'category_full',
            'categories' => [
                [
                    'slug' => $category_slug,
                    'name' => 'Takvim 1',
                    'description' => 'Calendar words',
                    'parent_slug' => '',
                    'meta' => [
                        'll_quiz_prompt_type' => ['image'],
                        'll_quiz_option_type' => ['text_title'],
                        'll_wordset_owner_id' => ['999999'],
                        'll_category_isolation_source_id' => ['888888'],
                    ],
                ],
            ],
            'word_images' => [
                [
                    'slug' => 'calendar-card',
                    'title' => 'Calendar Card',
                    'status' => 'publish',
                    'meta' => [
                        'll_wordset_owner_id' => ['999999'],
                        'll_word_image_isolation_source_id' => ['777777'],
                    ],
                    'categories' => [$category_slug],
                    'featured_image' => [
                        'file' => 'media/calendar.png',
                        'mime_type' => 'image/png',
                        'title' => 'Calendar Card',
                    ],
                ],
            ],
            'wordsets' => [
                [
                    'slug' => 'source-wordset',
                    'name' => 'Source Wordset',
                    'description' => '',
                    'meta' => [],
                ],
            ],
            'words' => [
                [
                    'origin_id' => 321,
                    'slug' => 'calendar-day',
                    'title' => 'Yarin',
                    'content' => '',
                    'excerpt' => '',
                    'status' => 'publish',
                    'meta' => [],
                    'categories' => [$category_slug],
                    'wordsets' => ['source-wordset'],
                    'linked_word_image_slug' => 'calendar-card',
                    'languages' => [],
                    'parts_of_speech' => [],
                    'featured_image' => [],
                    'audio_entries' => [],
                ],
            ],
            'media_estimate' => [
                'attachment_count' => 1,
                'attachment_bytes' => 68,
            ],
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $zip_path = trailingslashit(sys_get_temp_dir()) . 'll-tools-collision-import-' . strtolower(wp_generate_password(8, false, false)) . '.zip';
        @unlink($zip_path);

        $png = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($png);

        $zip = new ZipArchive();
        $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to create collision test zip.');
        $this->assertTrue($zip->addFromString('data.json', $json));
        $this->assertTrue($zip->addFromString('media/calendar.png', $png));
        $this->assertTrue($zip->close());
        $this->assertFileExists($zip_path);

        return wp_normalize_path($zip_path);
    }
}
