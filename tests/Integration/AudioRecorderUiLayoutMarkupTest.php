<?php
declare(strict_types=1);

final class AudioRecorderUiLayoutMarkupTest extends LL_Tools_TestCase
{
    public function test_utility_menu_includes_recorder_context_class(): void
    {
        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'recorder',
        ]);

        $this->assertStringContainsString('ll-wordset-utility-bar--context-recorder', $markup);
    }

    public function test_audio_recording_shortcode_renders_overlay_shells_and_core_controls(): void
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

        $wordset_slug = 'recorder-ui-layout-wordset';
        $this->ensure_term('wordset', 'Recorder UI Layout Wordset', $wordset_slug);

        $output = do_shortcode('[audio_recording_interface wordset="' . $wordset_slug . '" allow_new_words="1" auto_process_recordings="1"]');

        $this->assertStringContainsString('ll-wordset-utility-bar--context-recorder', $output);
        $this->assertStringContainsString('id="ll-hidden-words-overlay"', $output);
        $this->assertStringContainsString('id="ll-hidden-words-panel"', $output);
        $this->assertStringContainsString('id="ll-new-word-overlay"', $output);
        $this->assertStringContainsString('id="ll-recording-review-overlay"', $output);

        // Compatibility guard: preserve critical IDs used by recorder JS.
        $this->assertStringContainsString('id="ll-record-btn"', $output);
        $this->assertStringContainsString('id="ll-category-select"', $output);
        $this->assertStringContainsString('class="ll-recording-type-selector"', $output);
        $this->assertStringContainsString('id="ll-recording-type"', $output);
        $this->assertStringContainsString('id="ll-playback-controls"', $output);
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
