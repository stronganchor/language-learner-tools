<?php
declare(strict_types=1);

final class AudioUploadMetadataPreservationTest extends LL_Tools_TestCase
{
    public function test_existing_word_audio_insert_failure_is_reported(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Audio Insert Failure Word',
        ]);
        wp_set_current_user($admin_id);

        $reject_audio = static function (bool $maybe_empty, array $postarr): bool {
            return (($postarr['post_type'] ?? '') === 'word_audio') ? true : $maybe_empty;
        };
        add_filter('wp_insert_post_empty_content', $reject_audio, 10, 2);
        try {
            $result = ll_update_existing_post_audio($word_id, '/wp-content/uploads/failing-audio.mp3');
        } finally {
            remove_filter('wp_insert_post_empty_content', $reject_audio, 10);
        }

        $this->assertWPError($result);
        $this->assertSame([], array_values(get_children([
            'post_type' => 'word_audio',
            'post_parent' => $word_id,
            'post_status' => 'any',
            'numberposts' => 5,
            'fields' => 'ids',
        ])));
    }

    public function test_new_word_is_rolled_back_when_audio_insert_fails(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $before_count = (int) wp_count_posts('words')->draft;

        $reject_audio = static function (bool $maybe_empty, array $postarr): bool {
            return (($postarr['post_type'] ?? '') === 'word_audio') ? true : $maybe_empty;
        };
        add_filter('wp_insert_post_empty_content', $reject_audio, 10, 2);
        try {
            $result = ll_create_new_word_post(
                'Rolled Back Audio Word',
                '/wp-content/uploads/rolled-back-audio.mp3',
                [],
                [],
                wp_upload_dir()
            );
        } finally {
            remove_filter('wp_insert_post_empty_content', $reject_audio, 10);
        }

        $this->assertWPError($result);
        $this->assertSame($before_count, (int) wp_count_posts('words')->draft);
    }

    public function test_failed_audio_upload_cleanup_removes_staged_file(): void
    {
        $path = wp_tempnam('ll-tools-failed-audio.mp3');
        $this->assertIsString($path);
        $this->assertNotSame('', $path);
        file_put_contents($path, 'staged audio');
        $this->assertFileExists($path);

        ll_audio_upload_cleanup_staged_file($path);

        $this->assertFileDoesNotExist($path);
    }

    public function test_create_new_word_post_preserves_selected_speaker_and_recording_type(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $speaker_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($admin_id);

        $question_term = get_term_by('slug', 'question', 'recording_type');
        if (!($question_term instanceof WP_Term)) {
            $inserted = wp_insert_term('Question', 'recording_type', ['slug' => 'question']);
            $this->assertFalse(is_wp_error($inserted));
        }

        $result = ll_create_new_word_post(
            'Metadata Regression Word',
            '/wp-content/uploads/2026/03/metadata-regression-word.mp3',
            [
                'll_speaker_assignment' => (string) $speaker_id,
                'll_recording_type' => 'question',
            ],
            [],
            wp_upload_dir()
        );

        $this->assertIsInt($result);
        $word_id = (int) $result;
        $this->assertGreaterThan(0, $word_id);

        $audio_children = get_children([
            'post_type' => 'word_audio',
            'post_parent' => $word_id,
            'post_status' => 'any',
            'numberposts' => 5,
            'fields' => 'ids',
        ]);

        $audio_ids = array_values(array_filter(array_map('intval', (array) $audio_children), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $this->assertCount(1, $audio_ids);

        $audio_id = (int) $audio_ids[0];
        $this->assertSame($speaker_id, (int) get_post_meta($audio_id, 'speaker_user_id', true));

        $type_slugs = wp_get_post_terms($audio_id, 'recording_type', ['fields' => 'slugs']);
        $type_slugs = array_values(array_unique(array_map('strval', (array) $type_slugs)));
        sort($type_slugs, SORT_STRING);
        $this->assertSame(['question'], $type_slugs);
    }
}
