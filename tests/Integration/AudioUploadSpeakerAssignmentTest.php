<?php
declare(strict_types=1);

final class AudioUploadSpeakerAssignmentTest extends LL_Tools_TestCase
{
    public function test_non_privileged_uploader_cannot_assign_another_user_as_speaker(): void
    {
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $other_recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($recorder_id);

        $result = ll_create_new_word_post(
            'Recorder Ownership Regression Word',
            '/wp-content/uploads/2026/04/recorder-ownership-regression-word.mp3',
            [
                'll_speaker_assignment' => (string) $other_recorder_id,
                'll_recording_type' => 'isolation',
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
        $this->assertSame($recorder_id, (int) get_post_meta($audio_id, 'speaker_user_id', true));
    }

    public function test_assignable_speaker_list_excludes_users_without_ll_tools_access(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($admin_id);

        $users = ll_audio_upload_get_assignable_speaker_users();
        $ids = array_values(array_filter(array_map(static function ($user): int {
            return ($user instanceof WP_User) ? (int) $user->ID : 0;
        }, $users)));

        $this->assertContains($admin_id, $ids);
        $this->assertContains($recorder_id, $ids);
        $this->assertNotContains($subscriber_id, $ids);
    }
}
