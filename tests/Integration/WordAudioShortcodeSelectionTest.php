<?php
declare(strict_types=1);

final class WordAudioShortcodeSelectionTest extends LL_Tools_TestCase
{
    public function test_shortcode_can_select_audio_by_recording_type(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shortcode Audio Word',
        ]);

        $this->createAudioRecording($word_id, 'isolation', '/wp-content/uploads/shortcode-audio-isolation.mp3');
        $this->createAudioRecording($word_id, 'introduction', '/wp-content/uploads/shortcode-audio-introduction.mp3');

        $output = do_shortcode('[word_audio recording_type="introduction"]Shortcode Audio Word[/word_audio]');

        $this->assertStringContainsString('shortcode-audio-introduction.mp3', $output);
        $this->assertStringNotContainsString('shortcode-audio-isolation.mp3', $output);
    }

    public function test_shortcode_can_select_audio_by_exact_word_audio_id_without_word_lookup(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Mapped Parent Word',
        ]);

        $this->createAudioRecording($word_id, 'isolation', '/wp-content/uploads/mapped-parent-isolation.mp3');
        $target_audio_id = $this->createAudioRecording($word_id, 'introduction', '/wp-content/uploads/mapped-parent-introduction.mp3');

        $output = do_shortcode(sprintf(
            '[word_audio word_audio_id="%d" translate="no"]Custom Label[/word_audio]',
            $target_audio_id
        ));

        $this->assertStringContainsString('mapped-parent-introduction.mp3', $output);
        $this->assertStringNotContainsString('mapped-parent-isolation.mp3', $output);
        $this->assertStringContainsString('Custom Label', $output);
    }

    private function createAudioRecording(int $word_id, string $recording_type, string $audio_path): int
    {
        $this->ensureTerm('recording_type', ucwords(str_replace('-', ' ', $recording_type)), $recording_type);

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $recording_type . ' ' . wp_generate_password(6, false),
        ]);

        update_post_meta($audio_id, 'audio_file_path', $audio_path);
        wp_set_post_terms($audio_id, [$recording_type], 'recording_type', false);

        return (int) $audio_id;
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
}
