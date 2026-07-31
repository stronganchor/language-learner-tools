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

        ll_enqueue_word_audio_js();
        $output = do_shortcode('[word_audio recording_type="introduction"]Shortcode Audio Word[/word_audio]');

        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
        $this->assertStringContainsString('class="ll-word-audio__button"', $output);
        $this->assertStringContainsString('aria-label="Play audio"', $output);
        $this->assertStringContainsString('class="ll-word-audio__audio"', $output);
        $this->assertStringContainsString('hidden', $output);
        $this->assertStringNotContainsString('onclick=', $output);
        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringContainsString('shortcode-audio-introduction.mp3', $output);
        $this->assertStringNotContainsString('shortcode-audio-isolation.mp3', $output);

        $localized = wp_scripts()->get_data('ll-word-audio', 'data');
        $this->assertIsString($localized);
        $this->assertStringContainsString('ll_word_audio_data', $localized);
        $this->assertStringContainsString('Play audio', $localized);
        $this->assertStringContainsString('Pause audio', $localized);
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

        $this->assertStringContainsString('class="ll-word-audio__button"', $output);
        $this->assertStringContainsString('mapped-parent-introduction.mp3', $output);
        $this->assertStringNotContainsString('mapped-parent-isolation.mp3', $output);
        $this->assertStringContainsString('Custom Label', $output);
    }

    public function test_shortcode_does_not_resolve_non_public_words_for_anonymous_title_lookup(): void
    {
        wp_set_current_user(0);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Hidden Shortcode Audio Word',
        ]);
        update_post_meta($word_id, 'word_english_meaning', 'Hidden meaning');
        $this->createAudioRecording((int) $word_id, 'isolation', '/wp-content/uploads/hidden-shortcode-audio.mp3');

        $output = do_shortcode('[word_audio]Hidden Shortcode Audio Word[/word_audio]');

        $this->assertSame('Hidden Shortcode Audio Word', trim(wp_strip_all_tags($output)));
        $this->assertStringNotContainsString('ll-word-audio__button', $output);
        $this->assertStringNotContainsString('hidden-shortcode-audio.mp3', $output);
        $this->assertStringNotContainsString('Hidden meaning', $output);
    }

    public function test_shortcode_does_not_resolve_non_public_words_for_logged_in_non_owner(): void
    {
        $owner_id = self::factory()->user->create(['role' => 'editor']);
        $viewer_id = self::factory()->user->create(['role' => 'author']);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'private',
            'post_author' => $owner_id,
            'post_title' => 'Private Non Owner Audio Word',
        ]);
        update_post_meta($word_id, 'word_english_meaning', 'Non owner meaning');
        $this->createAudioRecording((int) $word_id, 'isolation', '/wp-content/uploads/non-owner-shortcode-audio.mp3');

        wp_set_current_user((int) $viewer_id);

        $this->assertTrue(current_user_can('edit_posts'));
        $this->assertFalse(current_user_can('edit_post', (int) $word_id));

        $output = do_shortcode('[word_audio]Private Non Owner Audio Word[/word_audio]');

        $this->assertSame('Private Non Owner Audio Word', trim(wp_strip_all_tags($output)));
        $this->assertStringNotContainsString('ll-word-audio__button', $output);
        $this->assertStringNotContainsString('non-owner-shortcode-audio.mp3', $output);
        $this->assertStringNotContainsString('Non owner meaning', $output);
    }

    public function test_shortcode_does_not_render_exact_word_audio_id_for_non_public_parent_to_anonymous_user(): void
    {
        wp_set_current_user(0);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'private',
            'post_title' => 'Private Parent Audio Word',
        ]);
        update_post_meta($word_id, 'word_english_meaning', 'Private parent meaning');
        $audio_id = $this->createAudioRecording((int) $word_id, 'isolation', '/wp-content/uploads/private-parent-shortcode-audio.mp3');

        $output = do_shortcode(sprintf(
            '[word_audio word_audio_id="%d"]Public Label[/word_audio]',
            $audio_id
        ));

        $this->assertSame('Public Label', trim(wp_strip_all_tags($output)));
        $this->assertStringNotContainsString('ll-word-audio__button', $output);
        $this->assertStringNotContainsString('private-parent-shortcode-audio.mp3', $output);
        $this->assertStringNotContainsString('Private parent meaning', $output);
    }

    public function test_shortcode_allows_editors_to_preview_non_public_word_audio(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Editor Preview Audio Word',
        ]);
        update_post_meta($word_id, 'word_english_meaning', 'Preview meaning');
        $this->createAudioRecording((int) $word_id, 'isolation', '/wp-content/uploads/editor-preview-shortcode-audio.mp3');

        $output = do_shortcode('[word_audio]Editor Preview Audio Word[/word_audio]');

        $this->assertStringContainsString('ll-word-audio__button', $output);
        $this->assertStringContainsString('editor-preview-shortcode-audio.mp3', $output);
        $this->assertStringContainsString('Preview meaning', $output);
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
