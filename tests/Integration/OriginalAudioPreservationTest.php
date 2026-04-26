<?php
declare(strict_types=1);

final class OriginalAudioPreservationTest extends LL_Tools_TestCase
{
    public function test_manual_audio_upload_preserves_original_source_when_wordset_option_is_enabled(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $enabled_wordset_id = $this->ensureTerm('wordset', 'Preserve Original Audio', 'preserve-original-audio');
        update_term_meta($enabled_wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY, '1');

        $enabled_audio_path = '/wp-content/uploads/2026/04/manual-preserve-source.mp3';
        $enabled_word_id = ll_create_new_word_post(
            'Manual Preserve Source',
            $enabled_audio_path,
            [
                'll_wordset_scope_mode' => 'single',
                'll_single_wordset_id' => (string) $enabled_wordset_id,
                'll_recording_type' => 'isolation',
            ],
            [],
            wp_upload_dir()
        );
        $this->assertIsInt($enabled_word_id);

        $enabled_audio_id = $this->getFirstAudioChildId((int) $enabled_word_id);
        $this->assertGreaterThan(0, $enabled_audio_id);
        $this->assertSame(
            $enabled_audio_path,
            (string) get_post_meta($enabled_audio_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, true)
        );

        $disabled_wordset_id = $this->ensureTerm('wordset', 'Do Not Preserve Original Audio', 'do-not-preserve-original-audio');
        $disabled_word_id = ll_create_new_word_post(
            'Manual No Preserve Source',
            '/wp-content/uploads/2026/04/manual-no-preserve-source.mp3',
            [
                'll_wordset_scope_mode' => 'single',
                'll_single_wordset_id' => (string) $disabled_wordset_id,
                'll_recording_type' => 'isolation',
            ],
            [],
            wp_upload_dir()
        );
        $this->assertIsInt($disabled_word_id);

        $disabled_audio_id = $this->getFirstAudioChildId((int) $disabled_word_id);
        $this->assertGreaterThan(0, $disabled_audio_id);
        $this->assertSame(
            '',
            (string) get_post_meta($disabled_audio_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, true)
        );
    }

    public function test_audio_processor_reprocess_queue_uses_preserved_original_source(): void
    {
        $wordset_id = $this->ensureTerm('wordset', 'Reprocess Original Audio', 'reprocess-original-audio');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Reprocess Audio Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $current_path = $this->createRelativeUploadFile('current-processed-source.wav', "current audio\n");
        $original_path = $this->createRelativeUploadFile('original-raw-source.wav', "original audio\n");

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Reprocess Audio Recording',
        ]);
        update_post_meta($audio_id, 'audio_file_path', $current_path);
        update_post_meta($audio_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, $original_path);
        update_post_meta($audio_id, 'recording_date', current_time('mysql'));

        $recordings = ll_get_reprocessable_recordings();
        $matched = null;
        foreach ($recordings as $recording) {
            if ((int) ($recording['id'] ?? 0) === (int) $audio_id) {
                $matched = $recording;
                break;
            }
        }

        $this->assertIsArray($matched);
        $this->assertSame(site_url($original_path), (string) ($matched['audioUrl'] ?? ''));
        $this->assertSame(site_url($current_path), (string) ($matched['currentAudioUrl'] ?? ''));
        $this->assertTrue(!empty($matched['isReprocessSource']));
        $this->assertTrue(!empty($matched['usesOriginalAudio']));
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }

    private function getFirstAudioChildId(int $word_id): int
    {
        $ids = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'post_parent' => $word_id,
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        return empty($ids) ? 0 : (int) $ids[0];
    }

    private function createRelativeUploadFile(string $filename, string $contents): string
    {
        $upload = wp_upload_bits($filename, null, $contents);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        return str_replace(
            wp_normalize_path(untrailingslashit(ABSPATH)),
            '',
            wp_normalize_path($file_path)
        );
    }
}
