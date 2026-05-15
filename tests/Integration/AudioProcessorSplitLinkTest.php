<?php
declare(strict_types=1);

final class AudioProcessorSplitLinkTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_unprocessed_recordings_enable_split_only_for_words_with_multiple_audio_children(): void
    {
        $editor_id = $this->createAudioProcessorEditor();

        $multi_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Multi Audio Word',
            'post_author' => $editor_id,
        ]);
        $single_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Single Audio Word',
            'post_author' => $editor_id,
        ]);

        $multi_audio_one = $this->createQueuedAudio($multi_word_id, $editor_id, 'Multi Audio One');
        $multi_audio_two = $this->createQueuedAudio($multi_word_id, $editor_id, 'Multi Audio Two');
        $single_audio = $this->createQueuedAudio($single_word_id, $editor_id, 'Single Audio');

        $recordings = ll_get_unprocessed_recordings();
        $all = isset($recordings['all']) && is_array($recordings['all']) ? $recordings['all'] : [];
        $by_id = [];
        foreach ($all as $recording) {
            $recording_id = isset($recording['id']) ? (int) $recording['id'] : 0;
            if ($recording_id > 0) {
                $by_id[$recording_id] = $recording;
            }
        }

        $this->assertArrayHasKey($multi_audio_one, $by_id);
        $this->assertArrayHasKey($multi_audio_two, $by_id);
        $this->assertArrayHasKey($single_audio, $by_id);

        $this->assertTrue((bool) ($by_id[$multi_audio_one]['splitWordEnabled'] ?? false));
        $this->assertTrue((bool) ($by_id[$multi_audio_two]['splitWordEnabled'] ?? false));
        $this->assertFalse((bool) ($by_id[$single_audio]['splitWordEnabled'] ?? true));
    }

    public function test_unprocessed_recording_uses_effective_word_image_without_word_thumbnail(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        $attachment_id = $this->createImageAttachment('audio-processor-effective-word-image.png');
        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Audio Processor Linked Image',
            'post_author' => $editor_id,
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Word With Linked Image',
            'post_author' => $editor_id,
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        delete_post_meta($word_id, '_thumbnail_id');

        $audio_id = $this->createQueuedAudio($word_id, $editor_id, 'Linked Image Audio');

        $recordings = ll_get_unprocessed_recordings();
        $recording = $this->findRecordingById($recordings, $audio_id);
        $image_data = ll_tools_get_effective_word_image_data_for_word($word_id, 'thumbnail', true);

        $this->assertSame('', (string) (get_the_post_thumbnail_url($word_id, 'thumbnail') ?: ''));
        $this->assertNotSame('', (string) ($image_data['url'] ?? ''));
        $this->assertSame((string) ($image_data['url'] ?? ''), (string) ($recording['imageUrl'] ?? ''));
    }

    private function createAudioProcessorEditor(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function createQueuedAudio(int $parent_word_id, int $author_id, string $title): int
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $parent_word_id,
            'post_title' => $title,
            'post_author' => $author_id,
        ]);

        update_post_meta($audio_id, '_ll_needs_audio_processing', '1');
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '.mp3');
        update_post_meta($audio_id, 'recording_date', '2026-03-09 09:00:00');

        return $audio_id;
    }

    /**
     * @param array<string,mixed> $recordings
     * @return array<string,mixed>
     */
    private function findRecordingById(array $recordings, int $audio_id): array
    {
        $all = isset($recordings['all']) && is_array($recordings['all']) ? $recordings['all'] : [];
        foreach ($all as $recording) {
            if (!is_array($recording)) {
                continue;
            }

            if ((int) ($recording['id'] ?? 0) === $audio_id) {
                return $recording;
            }
        }

        $this->fail('Expected audio processor recording was not returned.');
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }
}
