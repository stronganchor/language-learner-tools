<?php
declare(strict_types=1);

final class RecordingUploadDeduplicationTest extends LL_Tools_TestCase
{
    public function test_exact_duplicate_lookup_matches_existing_recording_and_backfills_hash_meta(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Duplicate Lookup Word',
        ]);
        $speaker_id = self::factory()->user->create(['role' => 'author']);
        $type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        $audio_path = $this->createAudioUploadFile('recording-dedupe-match.wav', "same audio bytes\n");
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $word_id,
            'post_author' => $speaker_id,
            'post_title' => 'Duplicate Lookup Recording',
        ]);
        update_post_meta($recording_id, 'audio_file_path', $audio_path);
        update_post_meta($recording_id, 'speaker_user_id', $speaker_id);
        wp_set_post_terms($recording_id, [$type_id], 'recording_type', false);

        $upload_sha1 = ll_tools_get_recording_upload_sha1_for_file($audio_path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $upload_sha1);

        delete_post_meta($recording_id, ll_tools_get_recording_upload_sha1_meta_key());

        $matched_id = ll_tools_find_matching_recording_upload($word_id, 'isolation', $speaker_id, $upload_sha1);

        $this->assertSame($recording_id, $matched_id);
        $this->assertSame(
            $upload_sha1,
            (string) get_post_meta($recording_id, ll_tools_get_recording_upload_sha1_meta_key(), true)
        );
    }

    public function test_exact_duplicate_lookup_does_not_merge_other_speakers(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Speaker Scoped Duplicate Word',
        ]);
        $speaker_id = self::factory()->user->create(['role' => 'author']);
        $other_speaker_id = self::factory()->user->create(['role' => 'author']);
        $type_id = $this->ensureTerm('recording_type', 'Question', 'question');

        $audio_path = $this->createAudioUploadFile('recording-dedupe-speaker.wav', "speaker scoped audio\n");
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $word_id,
            'post_author' => $speaker_id,
            'post_title' => 'Speaker Scoped Recording',
        ]);
        update_post_meta($recording_id, 'audio_file_path', $audio_path);
        update_post_meta($recording_id, 'speaker_user_id', $speaker_id);
        wp_set_post_terms($recording_id, [$type_id], 'recording_type', false);

        $upload_sha1 = ll_tools_get_recording_upload_sha1_for_file($audio_path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $upload_sha1);

        $matched_id = ll_tools_find_matching_recording_upload($word_id, 'question', $other_speaker_id, $upload_sha1);

        $this->assertSame(0, $matched_id);
    }

    public function test_recording_upload_lock_blocks_parallel_claims_until_release(): void
    {
        $signature = ll_tools_build_recording_upload_dedupe_signature(
            123,
            'isolation',
            456,
            str_repeat('a', 40)
        );
        $this->assertNotSame('', $signature);

        $this->assertTrue(ll_tools_acquire_recording_upload_lock($signature));
        try {
            $this->assertFalse(ll_tools_acquire_recording_upload_lock($signature));
        } finally {
            ll_tools_release_recording_upload_lock($signature);
        }

        $this->assertTrue(ll_tools_acquire_recording_upload_lock($signature));
        ll_tools_release_recording_upload_lock($signature);
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function createAudioUploadFile(string $filename, string $contents): string
    {
        $upload = wp_upload_bits($filename, null, $contents);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        return wp_normalize_path($file_path);
    }
}
