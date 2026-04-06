<?php
declare(strict_types=1);

final class ImportHistoryUndoTest extends LL_Tools_TestCase
{
    public function test_recent_history_only_returns_today_and_yesterday(): void
    {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $startToday = $now->setTime(0, 0, 0);

        update_option(ll_tools_import_history_option_name(), [
            [
                'id' => 'recent-today',
                'finished_at' => $startToday->getTimestamp() + 3600,
            ],
            [
                'id' => 'recent-yesterday',
                'finished_at' => $startToday->modify('-1 day')->getTimestamp() + 7200,
            ],
            [
                'id' => 'too-old',
                'finished_at' => $startToday->modify('-2 day')->getTimestamp() + 7200,
            ],
        ], false);

        $recent = ll_tools_import_get_recent_history_entries();
        $ids = array_values(array_map(static function (array $entry): string {
            return (string) ($entry['id'] ?? '');
        }, $recent));

        $this->assertContains('recent-today', $ids);
        $this->assertContains('recent-yesterday', $ids);
        $this->assertNotContains('too-old', $ids);
    }

    public function test_undo_import_entry_deletes_created_posts_terms_and_audio_file(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $category_insert = wp_insert_term('Undo Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($category_insert));
        $category_id = (int) $category_insert['term_id'];

        $wordset_insert = wp_insert_term('Undo Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_insert));
        $wordset_id = (int) $wordset_insert['term_id'];

        $word_id = wp_insert_post([
            'post_type' => 'words',
            'post_title' => 'Undo Word',
            'post_status' => 'draft',
        ]);
        $this->assertGreaterThan(0, $word_id);

        $word_image_id = wp_insert_post([
            'post_type' => 'word_images',
            'post_title' => 'Undo Image',
            'post_status' => 'draft',
        ]);
        $this->assertGreaterThan(0, $word_image_id);

        $word_audio_id = wp_insert_post([
            'post_type' => 'word_audio',
            'post_title' => 'Undo Audio',
            'post_status' => 'draft',
            'post_parent' => $word_id,
        ]);
        $this->assertGreaterThan(0, $word_audio_id);

        $upload_dir = wp_upload_dir();
        $audio_file = trailingslashit((string) $upload_dir['path']) . 'undo-audio-' . wp_generate_password(6, false, false) . '.mp3';
        if (!is_dir((string) $upload_dir['path'])) {
            wp_mkdir_p((string) $upload_dir['path']);
        }
        file_put_contents($audio_file, 'undo-audio');
        $this->assertFileExists($audio_file);

        $undo_result = ll_tools_undo_import_entry([
            'undo' => [
                'category_term_ids' => [$category_id],
                'wordset_term_ids' => [$wordset_id],
                'word_image_post_ids' => [$word_image_id],
                'word_post_ids' => [$word_id],
                'word_audio_post_ids' => [$word_audio_id],
                'attachment_ids' => [],
                'audio_paths' => [$audio_file],
            ],
        ]);

        $this->assertTrue((bool) ($undo_result['ok'] ?? false), implode(' | ', (array) ($undo_result['errors'] ?? [])));
        $this->assertNull(get_post($word_id));
        $this->assertNull(get_post($word_image_id));
        $this->assertNull(get_post($word_audio_id));
        $this->assertEmpty(term_exists($category_id, 'word-category'));
        $this->assertEmpty(term_exists($wordset_id, 'wordset'));
        $this->assertFileDoesNotExist($audio_file);

        $stats = isset($undo_result['stats']) && is_array($undo_result['stats']) ? $undo_result['stats'] : [];
        $this->assertSame(1, (int) ($stats['words_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['word_images_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['word_audio_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['categories_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['wordsets_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['audio_files_deleted'] ?? 0));
    }

    public function test_undo_import_entry_restores_metadata_update_snapshots(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Undo Original Word',
            'post_name' => 'undo-original-word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Undo Original Translation');
        update_post_meta($word_id, 'word_english_meaning', 'Undo Original Translation');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Undo Original Recording',
            'post_name' => 'undo-original-recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Undo Original Recording Text');
        update_post_meta($recording_id, 'recording_ipa', 'undo.old.ipa');
        update_post_meta($recording_id, 'speaker_name', 'Undo Speaker');

        $csv = implode("\n", [
            'word_id,recording_id,word_title,word_translation,recording_text,recording_ipa,speaker_name',
            $word_id . ',' . $recording_id . ',Undo Changed Word,Undo Changed Translation,Undo Changed Recording Text,undo.new.ipa,Undo Speaker New',
        ]) . "\n";

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-undo-metadata-' . wp_generate_password(8, false, false) . '.csv');
        file_put_contents($file_path, $csv);

        try {
            $processed = ll_tools_process_metadata_updates_file($file_path, 'undo-updates.csv');
            $this->assertTrue((bool) ($processed['ok'] ?? false), implode(' | ', (array) ($processed['errors'] ?? [])));
            $this->assertTrue(ll_tools_import_has_undo_targets((array) ($processed['undo'] ?? [])));
            $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

            $undo_result = ll_tools_undo_import_entry([
                'undo' => (array) ($processed['undo'] ?? []),
            ]);

            $this->assertTrue((bool) ($undo_result['ok'] ?? false), implode(' | ', (array) ($undo_result['errors'] ?? [])));
            $this->assertSame('Undo Original Word', (string) get_the_title($word_id));
            $this->assertSame('Undo Original Translation', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('Undo Original Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));
            $this->assertSame('Undo Original Recording Text', (string) get_post_meta($recording_id, 'recording_text', true));
            $this->assertSame('undo.old.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame('Undo Speaker', (string) get_post_meta($recording_id, 'speaker_name', true));
            $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

            $stats = isset($undo_result['stats']) && is_array($undo_result['stats']) ? $undo_result['stats'] : [];
            $this->assertSame(2, (int) ($stats['metadata_posts_restored'] ?? 0));
            $this->assertGreaterThanOrEqual(5, (int) ($stats['metadata_fields_restored'] ?? 0));
        } finally {
            @unlink($file_path);
        }
    }

    public function test_recent_imports_section_lists_categories_and_matching_lesson_links(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $category_insert = wp_insert_term('History Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($category_insert));
        $this->assertIsArray($category_insert);
        $category_id = (int) $category_insert['term_id'];
        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $primary_wordset_insert = wp_insert_term('History Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($primary_wordset_insert));
        $this->assertIsArray($primary_wordset_insert);
        $primary_wordset_id = (int) $primary_wordset_insert['term_id'];
        $primary_wordset = get_term($primary_wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $primary_wordset);

        $secondary_wordset_insert = wp_insert_term('Other Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($secondary_wordset_insert));
        $this->assertIsArray($secondary_wordset_insert);
        $secondary_wordset_id = (int) $secondary_wordset_insert['term_id'];

        $matching_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Matching History Lesson',
        ]);
        update_post_meta($matching_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);
        update_post_meta($matching_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $primary_wordset_id);

        $non_matching_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Non Matching History Lesson',
        ]);
        update_post_meta($non_matching_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);
        update_post_meta($non_matching_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $secondary_wordset_id);

        ob_start();
        ll_tools_render_recent_imports_section([
            [
                'id' => 'history-entry-with-lessons',
                'finished_at' => time(),
                'ok' => true,
                'stats' => [
                    'categories_created' => 1,
                ],
                'undo' => ll_tools_import_default_undo_payload(),
                'history_context' => [
                    'categories' => [
                        [
                            'term_id' => $category_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                        ],
                    ],
                    'wordsets' => [
                        [
                            'term_id' => $primary_wordset_id,
                            'name' => $primary_wordset->name,
                            'slug' => $primary_wordset->slug,
                        ],
                    ],
                ],
            ],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Categories (1)', $html);
        $this->assertStringContainsString($category->name, $html);
        $this->assertStringContainsString('Lesson pages:', $html);
        $this->assertStringContainsString($primary_wordset->name, $html);
        $this->assertStringContainsString(get_permalink($matching_lesson_id), $html);
        $this->assertStringNotContainsString(get_permalink($non_matching_lesson_id), $html);
    }
}
