<?php
declare(strict_types=1);

final class WordGridRecordingLaunchTest extends LL_Tools_TestCase
{
    public function test_word_grid_renders_recording_launch_button_for_missing_audio_word(): void
    {
        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensure_term('wordset', 'Grid Recording Launch Wordset', 'grid-recording-launch-wordset');
        $category_id = $this->ensure_term('word-category', 'Grid Recording Launch Category', 'grid-recording-launch-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Grid Recording Launch Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        $output = do_shortcode('[word_grid category="grid-recording-launch-category" wordset="grid-recording-launch-wordset"]');

        $this->assertStringContainsString('class="ll-word-recording-launch"', $output);
        $this->assertStringContainsString('data-loading-label="Opening recorder..."', $output);
        $this->assertStringContainsString('class="ll-word-recording-launch__icon"', $output);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="8"', $output);
        $this->assertStringContainsString('ll_record_word=' . $word_id, $output);
        $this->assertStringContainsString('ll_record_wordset=' . $wordset_id, $output);
        $this->assertStringContainsString('ll_record_category=grid-recording-launch-category', $output);
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
