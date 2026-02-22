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
}
