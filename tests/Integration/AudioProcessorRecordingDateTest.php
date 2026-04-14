<?php
declare(strict_types=1);

final class AudioProcessorRecordingDateTest extends LL_Tools_TestCase
{
    public function test_audio_processor_recordings_include_absolute_timestamp_for_browser_localized_rendering(): void
    {
        $previous_timezone = (string) get_option('timezone_string', '');
        $previous_offset = get_option('gmt_offset', 0);

        update_option('timezone_string', 'Europe/Istanbul');
        update_option('gmt_offset', 3);

        try {
            $editor_id = $this->createAudioProcessorEditor();
            wp_set_current_user($editor_id);

            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'draft',
                'post_title' => 'Timezone Test Word',
                'post_author' => $editor_id,
            ]);

            $audio_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'draft',
                'post_parent' => $word_id,
                'post_title' => 'Timezone Test Audio',
                'post_author' => $editor_id,
            ]);

            update_post_meta($audio_id, '_ll_needs_audio_processing', '1');
            update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/timezone-test-audio.mp3');
            update_post_meta($audio_id, 'recording_date', '2026-03-09 09:00:00');

            $recordings = ll_get_unprocessed_recordings();
            $all = isset($recordings['all']) && is_array($recordings['all']) ? $recordings['all'] : [];
            $recording = $this->findRecordingById($all, $audio_id);

            $expected_timestamp = (new DateTimeImmutable('2026-03-09 09:00:00', wp_timezone()))->getTimestamp();

            $this->assertSame($expected_timestamp, (int) ($recording['uploadTimestamp'] ?? 0));

            ob_start();
            ll_render_audio_processor_recording_item($recording);
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('data-upload-timestamp="' . $expected_timestamp . '"', $output);
            $this->assertStringContainsString('datetime="2026-03-09T06:00:00+00:00"', $output);
            $this->assertStringContainsString('2026-03-09 09:00', $output);
        } finally {
            update_option('timezone_string', $previous_timezone);
            update_option('gmt_offset', $previous_offset);
        }
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

    private function findRecordingById(array $recordings, int $recording_id): array
    {
        foreach ($recordings as $recording) {
            if ((int) ($recording['id'] ?? 0) === $recording_id) {
                return $recording;
            }
        }

        $this->fail('Expected recording was not returned by ll_get_unprocessed_recordings().');
    }
}
