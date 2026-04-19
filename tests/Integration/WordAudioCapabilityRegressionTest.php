<?php
declare(strict_types=1);

final class WordAudioCapabilityRegressionTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('ll_missing_audio_instances');
    }

    protected function tearDown(): void
    {
        delete_option('ll_missing_audio_instances');
        parent::tearDown();
    }

    public function test_missing_audio_shortcode_caches_host_post_for_admins_only(): void
    {
        $host_post_id = $this->createHostPost('Word Audio Capability Host');
        $word_title = 'Missing Audio Capability Word';
        $this->createWordPost($word_title);

        $this->go_to(get_permalink($host_post_id));
        wp_set_current_user($this->createUserWithRole('administrator'));

        $output = do_shortcode('[word_audio]' . $word_title . '[/word_audio]');

        $this->assertSame($word_title, trim(wp_strip_all_tags($output)));
        $this->assertSame(
            [$word_title => $host_post_id],
            get_option('ll_missing_audio_instances', [])
        );
    }

    public function test_missing_audio_shortcode_does_not_cache_host_post_for_non_admins(): void
    {
        $host_post_id = $this->createHostPost('Word Audio Capability Host Non Admin');
        $word_title = 'Non Admin Missing Audio Word';
        $this->createWordPost($word_title);

        $this->go_to(get_permalink($host_post_id));
        wp_set_current_user($this->createUserWithRole('subscriber'));

        $output = do_shortcode('[word_audio]' . $word_title . '[/word_audio]');

        $this->assertSame($word_title, trim(wp_strip_all_tags($output)));
        $this->assertSame([], get_option('ll_missing_audio_instances', []));
    }

    public function test_missing_audio_shortcode_clears_cache_for_admins_when_audio_exists(): void
    {
        $host_post_id = $this->createHostPost('Word Audio Capability Host With Recording');
        $word_title = 'Recorded Capability Word';
        $word_id = $this->createWordPost($word_title);
        $this->createAudioRecording($word_id, '/wp-content/uploads/recorded-capability-word.mp3');

        update_option('ll_missing_audio_instances', [
            $word_title => $host_post_id,
        ], false);

        $this->go_to(get_permalink($host_post_id));
        wp_set_current_user($this->createUserWithRole('administrator'));

        $output = do_shortcode('[word_audio]' . $word_title . '[/word_audio]');

        $this->assertStringContainsString('ll-word-audio__button', $output);
        $this->assertSame([], get_option('ll_missing_audio_instances', []));
    }

    public function test_hide_admin_bar_for_non_admins_uses_manage_options(): void
    {
        wp_set_current_user($this->createUserWithRole('subscriber'));
        $this->assertFalse(hide_admin_bar_for_non_admins());
        $this->assertFalse(apply_filters('show_admin_bar', true));

        wp_set_current_user($this->createUserWithRole('administrator'));
        $this->assertTrue(hide_admin_bar_for_non_admins());
        $this->assertTrue(apply_filters('show_admin_bar', false));
    }

    private function createHostPost(string $title): int
    {
        return (int) self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
    }

    private function createWordPost(string $title): int
    {
        return (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
    }

    private function createAudioRecording(int $word_id, string $audio_path): int
    {
        $audio_id = (int) self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . wp_generate_password(8, false),
        ]);

        update_post_meta($audio_id, 'audio_file_path', $audio_path);

        return $audio_id;
    }

    private function createUserWithRole(string $role): int
    {
        return (int) self::factory()->user->create([
            'role' => $role,
        ]);
    }
}
