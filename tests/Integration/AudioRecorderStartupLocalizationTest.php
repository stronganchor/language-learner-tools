<?php
declare(strict_types=1);

final class AudioRecorderStartupLocalizationTest extends LL_Tools_TestCase
{
    public function test_audio_recording_shortcode_localizes_startup_strings_for_new_word_recorder(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_slug = 'recorder-startup-localization-wordset';
        $this->ensure_term('wordset', 'Recorder Startup Localization Wordset', $wordset_slug);

        $output = do_shortcode('[audio_recording_interface wordset="' . $wordset_slug . '" allow_new_words="1"]');

        $this->assertStringContainsString('id="ll-new-word-record-btn"', $output);
        $this->assertStringContainsString('id="ll-new-word-recording-indicator"', $output);
        $this->assertStringContainsString('id="ll-new-word-status"', $output);

        $localized = wp_scripts()->get_data('ll-audio-recorder', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('recording_starting', $localized);
        $this->assertStringContainsString('new_word_label', $localized);
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
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
