<?php
declare(strict_types=1);

final class ImportMetaKeyFilteringTest extends LL_Tools_TestCase
{
    public function test_post_meta_replace_skips_blocked_protected_keys_and_updates_allowed_keys(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Import Meta Word',
        ]);

        $this->assertGreaterThan(0, $post_id);

        update_post_meta($post_id, 'word_translation', 'Old Translation');
        update_post_meta($post_id, '_ll_similar_word_id', 10);
        update_post_meta($post_id, '_wp_old_slug', 'kept-old-slug');

        ll_tools_import_replace_post_meta_values($post_id, [
            'word_translation' => ['New Translation'],
            '_ll_similar_word_id' => [22],
            '_wp_old_slug' => ['should-not-overwrite'],
            '_thumbnail_id' => [999],
        ], 'words');

        $this->assertSame('New Translation', (string) get_post_meta($post_id, 'word_translation', true));
        $this->assertSame('22', (string) get_post_meta($post_id, '_ll_similar_word_id', true));
        $this->assertSame('kept-old-slug', (string) get_post_meta($post_id, '_wp_old_slug', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_thumbnail_id', true));
    }

    public function test_post_meta_replace_can_allow_custom_protected_key_via_filter(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Import Filtered Meta Word',
        ]);

        $this->assertGreaterThan(0, $post_id);

        $allow_key = static function (bool $allowed, string $key, string $post_type): bool {
            if ($key === '_custom_bundle_meta' && $post_type === 'words') {
                return true;
            }

            return $allowed;
        };
        add_filter('ll_tools_import_allow_post_meta_key', $allow_key, 10, 3);

        try {
            ll_tools_import_replace_post_meta_values($post_id, [
                '_custom_bundle_meta' => ['abc123'],
            ], 'words');
        } finally {
            remove_filter('ll_tools_import_allow_post_meta_key', $allow_key, 10);
        }

        $this->assertSame('abc123', (string) get_post_meta($post_id, '_custom_bundle_meta', true));
    }

    public function test_term_meta_replace_skips_blocked_keys_and_allows_filter_override(): void
    {
        $insert = wp_insert_term('Import Meta Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($insert));
        $term_id = (int) $insert['term_id'];

        update_term_meta($term_id, 'display_color', 'red');
        update_term_meta($term_id, '_wp_note', 'keep-me');

        $replace_result = ll_tools_import_replace_term_meta_values($term_id, [
            'display_color' => ['blue'],
            '_wp_note' => ['should-not-overwrite'],
            '_custom_term_bundle_meta' => ['hidden-by-default'],
        ], 'word-category');

        $this->assertTrue($replace_result);
        $this->assertSame('blue', (string) get_term_meta($term_id, 'display_color', true));
        $this->assertSame('keep-me', (string) get_term_meta($term_id, '_wp_note', true));
        $this->assertSame('', (string) get_term_meta($term_id, '_custom_term_bundle_meta', true));

        $allow_key = static function (bool $allowed, string $key, string $taxonomy): bool {
            if ($key === '_custom_term_bundle_meta' && $taxonomy === 'word-category') {
                return true;
            }

            return $allowed;
        };
        add_filter('ll_tools_import_allow_term_meta_key', $allow_key, 10, 3);

        try {
            $filtered_replace_result = ll_tools_import_replace_term_meta_values($term_id, [
                '_custom_term_bundle_meta' => ['allowed-value'],
            ], 'word-category');
        } finally {
            remove_filter('ll_tools_import_allow_term_meta_key', $allow_key, 10);
        }

        $this->assertTrue($filtered_replace_result);
        $this->assertSame('allowed-value', (string) get_term_meta($term_id, '_custom_term_bundle_meta', true));
    }

    public function test_term_meta_replace_rejects_a_short_circuited_delete_before_adding_new_values(): void
    {
        $insert = wp_insert_term('Blocked Import Meta Delete ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertIsArray($insert);
        $term_id = (int) $insert['term_id'];
        update_term_meta($term_id, 'display_color', 'red');
        $delete_was_blocked = false;
        $block_delete = static function ($check, $object_id, $meta_key) use ($term_id, &$delete_was_blocked) {
            if ((int) $object_id === $term_id && (string) $meta_key === 'display_color') {
                $delete_was_blocked = true;
                return false;
            }

            return $check;
        };
        add_filter('delete_term_metadata', $block_delete, 10, 3);

        try {
            $result = ll_tools_import_replace_term_meta_values($term_id, [
                'display_color' => ['blue'],
            ], 'word-category');
        } finally {
            remove_filter('delete_term_metadata', $block_delete, 10);
        }

        $this->assertTrue($delete_was_blocked);
        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_term_meta_write_failed', $result->get_error_code());
        $this->assertSame(['red'], get_term_meta($term_id, 'display_color', false));
    }

    public function test_term_meta_replace_rolls_back_the_old_value_when_an_add_fails(): void
    {
        $insert = wp_insert_term('Blocked Import Meta Add ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertIsArray($insert);
        $term_id = (int) $insert['term_id'];
        update_term_meta($term_id, 'display_color', 'red');
        $add_was_blocked = false;
        $block_add = static function ($check, $object_id, $meta_key) use ($term_id, &$add_was_blocked) {
            if ((int) $object_id === $term_id && (string) $meta_key === 'display_color') {
                $add_was_blocked = true;
                return false;
            }

            return $check;
        };
        add_filter('add_term_metadata', $block_add, 10, 3);

        try {
            $result = ll_tools_import_replace_term_meta_values($term_id, [
                'display_color' => ['blue'],
            ], 'word-category');
        } finally {
            remove_filter('add_term_metadata', $block_add, 10);
        }

        $this->assertTrue($add_was_blocked);
        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_term_meta_write_failed', $result->get_error_code());
        $this->assertSame(['red'], get_term_meta($term_id, 'display_color', false));
    }

    public function test_wordset_term_meta_replace_rolls_back_all_keys_when_a_later_add_fails(): void
    {
        $insert = wp_insert_term('Atomic Wordset Meta ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        update_term_meta($termId, 'll_language', 'old-language');
        update_term_meta($termId, 'll_dialect', 'old-dialect');
        $addWasBlocked = false;
        $blockSecondAdd = static function ($check, $objectId, $metaKey) use ($termId, &$addWasBlocked) {
            if ((int) $objectId === $termId && (string) $metaKey === 'll_dialect') {
                $addWasBlocked = true;
                return false;
            }
            return $check;
        };
        add_filter('add_term_metadata', $blockSecondAdd, 10, 3);

        try {
            $result = ll_tools_import_replace_term_meta_values($termId, [
                'll_language' => ['new-language'],
                'll_dialect' => ['new-dialect'],
            ], 'wordset');
        } finally {
            remove_filter('add_term_metadata', $blockSecondAdd, 10);
        }

        $this->assertTrue($addWasBlocked);
        $this->assertWPError($result);
        $this->assertSame('old-language', (string) get_term_meta($termId, 'll_language', true));
        $this->assertSame('old-dialect', (string) get_term_meta($termId, 'll_dialect', true));
    }

    public function test_wordset_map_records_regular_meta_write_failures_in_the_import_result(): void
    {
        $slug = sanitize_title('blocked-wordset-meta-' . wp_generate_password(6, false, false));
        $blocked_term_id = 0;
        $block_add = static function ($check, $object_id, $meta_key) use (&$blocked_term_id) {
            if ((string) $meta_key === 'll_language') {
                $blocked_term_id = (int) $object_id;
                return false;
            }

            return $check;
        };
        $result = ll_tools_import_job_default_result();
        add_filter('add_term_metadata', $block_add, 10, 3);

        try {
            $map = ll_tools_import_prepare_wordset_map([[
                'slug' => $slug,
                'name' => 'Blocked Wordset Meta',
                'description' => '',
                'meta' => ['ll_language' => ['zza']],
            ]], [], $result);
        } finally {
            remove_filter('add_term_metadata', $block_add, 10);
        }

        $wordset_id = (int) ($map[$slug] ?? 0);
        try {
            $this->assertGreaterThan(0, $wordset_id);
            $this->assertSame($wordset_id, $blocked_term_id);
            $this->assertSame('', (string) get_term_meta($wordset_id, 'll_language', true));
            $this->assertNotEmpty((array) ($result['errors'] ?? []));
            $this->assertStringContainsString(
                'Failed to update word set',
                implode("\n", (array) ($result['errors'] ?? []))
            );
        } finally {
            if ($wordset_id > 0) {
                wp_delete_term($wordset_id, 'wordset');
            }
        }
    }

    public function test_category_details_converts_throwing_metadata_hook_to_typed_write_error(): void
    {
        $slug = sanitize_title('throwing-category-meta-' . wp_generate_password(6, false, false));
        $insert = wp_insert_term('Original Throwing Category', 'word-category', [
            'slug' => $slug,
            'description' => 'Original throwing description',
        ]);
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        update_term_meta($termId, 'display_color', 'red');
        $sanitizeWasCalled = false;
        $throwDuringSanitize = static function ($value) use (&$sanitizeWasCalled) {
            $sanitizeWasCalled = true;
            throw new RuntimeException('Synthetic imported term-meta sanitizer failure.');
        };
        add_filter('sanitize_term_meta_display_color', $throwDuringSanitize);

        try {
            $result = ll_tools_import_apply_category_details_chunk(
                [[
                    'slug' => $slug,
                    'name' => 'Imported Throwing Category',
                    'description' => 'Imported throwing description',
                    'meta' => ['display_color' => ['blue']],
                ]],
                [$slug => $termId]
            );
        } finally {
            remove_filter('sanitize_term_meta_display_color', $throwDuringSanitize);
        }

        $this->assertTrue($sanitizeWasCalled);
        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_term_meta_write_failed', $result->get_error_code());
        $this->assertSame('sanitize', (string) ($result->get_error_data()['operation'] ?? ''));
        $this->assertSame('display_color', (string) ($result->get_error_data()['meta_key'] ?? ''));
        $this->assertTrue((bool) ($result->get_error_data()['retryable'] ?? false));
        $storedTerm = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedTerm);
        $this->assertSame('Original Throwing Category', (string) $storedTerm->name);
        $this->assertSame('Original throwing description', (string) $storedTerm->description);
        $this->assertSame('red', (string) get_term_meta($termId, 'display_color', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($termId));
    }

    public function test_category_details_rolls_back_throwing_shared_settings_hook_as_typed_write_error(): void
    {
        $slug = sanitize_title('throwing-shared-meta-' . wp_generate_password(6, false, false));
        $insert = wp_insert_term('Original Shared Meta', 'word-category', [
            'slug' => $slug,
            'description' => 'Original shared description',
        ]);
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        update_term_meta($termId, 'term_translation', 'Original translation');
        update_term_meta($termId, 'll_quiz_prompt_type', 'text');
        $updateWasCalled = false;
        $throwDuringUpdate = static function ($check, $objectId, $metaKey) use ($termId, &$updateWasCalled) {
            if ((int) $objectId === $termId && (string) $metaKey === 'll_quiz_prompt_type') {
                $updateWasCalled = true;
                throw new RuntimeException('Synthetic shared category-settings metadata failure.');
            }
            return $check;
        };
        add_filter('update_term_metadata', $throwDuringUpdate, 10, 3);

        try {
            $result = ll_tools_import_apply_category_details_chunk(
                [[
                    'slug' => $slug,
                    'name' => 'Imported Shared Meta',
                    'description' => 'Imported shared description',
                    'meta' => [
                        'term_translation' => ['Imported translation'],
                        'll_quiz_prompt_type' => ['audio'],
                    ],
                ]],
                [$slug => $termId]
            );
        } finally {
            remove_filter('update_term_metadata', $throwDuringUpdate, 10);
        }

        $this->assertTrue($updateWasCalled);
        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_term_meta_write_failed', $result->get_error_code());
        $this->assertSame('shared_settings_write', (string) ($result->get_error_data()['operation'] ?? ''));
        $this->assertSame('ll_quiz_prompt_type', (string) ($result->get_error_data()['meta_key'] ?? ''));
        $storedTerm = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedTerm);
        $this->assertSame('Original Shared Meta', (string) $storedTerm->name);
        $this->assertSame('Original shared description', (string) $storedTerm->description);
        $this->assertSame('Original translation', (string) get_term_meta($termId, 'term_translation', true));
        $this->assertSame('text', (string) get_term_meta($termId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($termId));
    }

    public function test_category_details_detects_a_silent_term_update_and_rolls_back(): void
    {
        global $wpdb;

        $slug = sanitize_title('silent-category-update-' . wp_generate_password(6, false, false));
        $insert = wp_insert_term('Original Silent Category', 'word-category', [
            'slug' => $slug,
            'description' => 'Original silent description',
        ]);
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        update_term_meta($termId, 'term_translation', 'Original silent translation');
        $updateWasBlocked = false;
        $blockTermsUpdate = static function (string $query) use ($wpdb, $termId, &$updateWasBlocked): string {
            if (
                !$updateWasBlocked
                && str_starts_with(trim($query), "UPDATE `{$wpdb->terms}` SET")
                && preg_match('/`term_id`\s*=\s*' . preg_quote((string) $termId, '/') . '\b/', $query)
            ) {
                $updateWasBlocked = true;
                return 'SELECT 1';
            }
            return $query;
        };
        add_filter('query', $blockTermsUpdate);

        try {
            $result = ll_tools_import_apply_category_details_chunk(
                [[
                    'slug' => $slug,
                    'name' => 'Imported Silent Category',
                    'description' => 'Imported silent description',
                    'meta' => ['term_translation' => ['Imported silent translation']],
                ]],
                [$slug => $termId]
            );
        } finally {
            remove_filter('query', $blockTermsUpdate);
        }

        $this->assertTrue($updateWasBlocked);
        $this->assertWPError($result);
        $this->assertSame('term_readback', (string) ($result->get_error_data()['operation'] ?? ''));
        $storedTerm = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedTerm);
        $this->assertSame('Original Silent Category', (string) $storedTerm->name);
        $this->assertSame('Original silent description', (string) $storedTerm->description);
        $this->assertSame('Original silent translation', (string) get_term_meta($termId, 'term_translation', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($termId));
    }

    public function test_category_details_commits_mixed_meta_with_one_revision(): void
    {
        $slug = sanitize_title('mixed-category-meta-' . wp_generate_password(6, false, false));
        $insert = wp_insert_term('Original Mixed Category', 'word-category', ['slug' => $slug]);
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];

        $result = ll_tools_import_apply_category_details_chunk(
            [[
                'slug' => $slug,
                'name' => 'Imported Mixed Category',
                'description' => 'Imported mixed description',
                'meta' => [
                    'term_translation' => ['Mixed translation'],
                    'll_quiz_prompt_type' => ['audio'],
                ],
            ]],
            [$slug => $termId]
        );

        $this->assertTrue($result);
        $storedTerm = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedTerm);
        $this->assertSame('Imported Mixed Category', (string) $storedTerm->name);
        $this->assertSame('Imported mixed description', (string) $storedTerm->description);
        $this->assertSame('Mixed translation', (string) get_term_meta($termId, 'term_translation', true));
        $this->assertSame('audio', (string) get_term_meta($termId, 'll_quiz_prompt_type', true));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($termId));
    }

    public function test_category_details_rolls_back_the_whole_chunk_when_a_later_category_fails(): void
    {
        $firstSlug = sanitize_title('atomic-first-category-' . wp_generate_password(6, false, false));
        $secondSlug = sanitize_title('atomic-second-category-' . wp_generate_password(6, false, false));
        $first = wp_insert_term('Atomic First Original', 'word-category', [
            'slug' => $firstSlug,
            'description' => 'Atomic first original description',
        ]);
        $second = wp_insert_term('Atomic Second Original', 'word-category', [
            'slug' => $secondSlug,
            'description' => 'Atomic second original description',
        ]);
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $firstId = (int) $first['term_id'];
        $secondId = (int) $second['term_id'];
        update_term_meta($firstId, 'term_translation', 'First original translation');
        update_term_meta($secondId, 'term_translation', 'Second original translation');
        ll_tools_reset_category_maintenance_runtime();

        $secondWriteWasBlocked = false;
        $editedHookObservations = [];
        $observeEditedHook = static function (int $termId) use (&$editedHookObservations): void {
            $maintenanceState = &ll_tools_get_category_maintenance_runtime();
            $editedHookObservations[] = [
                'term_id' => $termId,
                'deferred' => ll_tools_category_maintenance_is_deferred(),
                'quiz_synced' => isset($maintenanceState['synced_quiz_category_ids'][$termId]),
                'vocab_synced' => isset($maintenanceState['synced_vocab_category_ids'][$termId]),
            ];
        };
        $blockSecondWrite = static function ($check, $objectId, $metaKey) use ($secondId, &$secondWriteWasBlocked) {
            if ((int) $objectId === $secondId && (string) $metaKey === 'term_translation') {
                $secondWriteWasBlocked = true;
                return false;
            }
            return $check;
        };
        add_filter('add_term_metadata', $blockSecondWrite, 10, 3);
        add_action('edited_word-category', $observeEditedHook, 999, 1);
        try {
            $result = ll_tools_import_apply_category_details_chunk(
                [
                    [
                        'slug' => $firstSlug,
                        'name' => 'Atomic First Imported',
                        'description' => 'Atomic first imported description',
                        'meta' => ['term_translation' => ['First imported translation']],
                    ],
                    [
                        'slug' => $secondSlug,
                        'name' => 'Atomic Second Imported',
                        'description' => 'Atomic second imported description',
                        'meta' => ['term_translation' => ['Second imported translation']],
                    ],
                ],
                [
                    $firstSlug => $firstId,
                    $secondSlug => $secondId,
                ]
            );
        } finally {
            remove_action('edited_word-category', $observeEditedHook, 999);
            remove_filter('add_term_metadata', $blockSecondWrite, 10);
        }

        $this->assertTrue($secondWriteWasBlocked);
        $this->assertWPError($result);
        $this->assertCount(2, $editedHookObservations);
        $this->assertSame([$firstId, $secondId], array_column($editedHookObservations, 'term_id'));
        foreach ($editedHookObservations as $observation) {
            $this->assertTrue((bool) $observation['deferred']);
            $this->assertFalse((bool) $observation['quiz_synced']);
            $this->assertFalse((bool) $observation['vocab_synced']);
        }
        $maintenanceState = &ll_tools_get_category_maintenance_runtime();
        $this->assertSame([], (array) ($maintenanceState['queued_category_ids'] ?? []));
        $firstStored = get_term($firstId, 'word-category');
        $secondStored = get_term($secondId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $firstStored);
        $this->assertInstanceOf(WP_Term::class, $secondStored);
        $this->assertSame('Atomic First Original', (string) $firstStored->name);
        $this->assertSame('Atomic first original description', (string) $firstStored->description);
        $this->assertSame('First original translation', (string) get_term_meta($firstId, 'term_translation', true));
        $this->assertSame('Atomic Second Original', (string) $secondStored->name);
        $this->assertSame('Atomic second original description', (string) $secondStored->description);
        $this->assertSame('Second original translation', (string) get_term_meta($secondId, 'term_translation', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($firstId));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($secondId));
    }

    public function test_category_details_fails_closed_when_term_tables_are_not_transactional(): void
    {
        $slug = sanitize_title('nontransactional-category-meta-' . wp_generate_password(6, false, false));
        $insert = wp_insert_term('Original Nontransactional Category', 'word-category', [
            'slug' => $slug,
            'description' => 'Original nontransactional description',
        ]);
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        update_term_meta($termId, 'term_translation', 'Original nontransactional translation');
        $nontransactional = static function (): bool {
            return false;
        };
        add_filter('ll_tools_strict_term_insert_tables_are_transactional', $nontransactional);

        try {
            $result = ll_tools_import_apply_category_details_chunk(
                [[
                    'slug' => $slug,
                    'name' => 'Imported Nontransactional Category',
                    'description' => 'Imported nontransactional description',
                    'meta' => [
                        'term_translation' => ['Imported nontransactional translation'],
                        'll_quiz_prompt_type' => ['audio'],
                    ],
                ]],
                [$slug => $termId]
            );
        } finally {
            remove_filter('ll_tools_strict_term_insert_tables_are_transactional', $nontransactional);
        }

        $this->assertWPError($result);
        $this->assertSame('transaction_storage', (string) ($result->get_error_data()['operation'] ?? ''));
        $storedTerm = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedTerm);
        $this->assertSame('Original Nontransactional Category', (string) $storedTerm->name);
        $this->assertSame('Original nontransactional description', (string) $storedTerm->description);
        $this->assertSame('Original nontransactional translation', (string) get_term_meta($termId, 'term_translation', true));
        $this->assertSame('', (string) get_term_meta($termId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($termId));
    }
}
