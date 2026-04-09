<?php
declare(strict_types=1);

final class AudioUploadMetadataPreservationTest extends LL_Tools_TestCase
{
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
