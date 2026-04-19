<?php
declare(strict_types=1);

final class AudioProcessorSplitLinkTest extends LL_Tools_TestCase
{
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
}
