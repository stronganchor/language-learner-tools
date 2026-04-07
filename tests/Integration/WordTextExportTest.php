<?php
declare(strict_types=1);

final class WordTextExportTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    public function test_export_page_renders_word_text_defaults_checked(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset('Word Text Render Wordset');
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->ensureRecordingType('isolation');

        $_GET['wordset_id'] = (string) $wordset_id;

        ob_start();
        ll_tools_render_export_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Export Word Text (CSV)', $output);
        $this->assertMatchesRegularExpression('/name="ll_word_text_sources\\[\\]" value="title" checked/', $output);
        $this->assertMatchesRegularExpression('/name="ll_word_text_sources\\[\\]" value="translation" checked/', $output);
        $this->assertMatchesRegularExpression('/name="ll_word_text_sources\\[\\]" value="example_sentence" checked/', $output);
        $this->assertMatchesRegularExpression('/name="ll_word_text_sources\\[\\]" value="example_sentence_translation" checked/', $output);
        $this->assertMatchesRegularExpression('/name="ll_recording_sources\\[[^\\]]+\\]\\[\\]" value="transcription" checked/', $output);
        $this->assertStringContainsString('id="ll_export_dialect"', $output);
        $this->assertStringContainsString('value="' . esc_attr((string) $wordset->name) . '"', $output);
    }

    public function test_build_wordset_csv_rows_includes_all_selected_word_and_recording_text(): void
    {
        $wordset_id = $this->createWordset('Word Text CSV');
        $word_id = $this->createWord($wordset_id, 'Merheba', 'Hello');
        update_post_meta($word_id, 'word_example_sentence', 'Merheba dunya');
        update_post_meta($word_id, 'word_example_sentence_translation', 'Hello world');

        $this->createAudioRecording($word_id, [
            'recording_text' => 'Merheba sentence',
            'recording_translation' => 'Hello sentence',
            'recording_ipa' => 'mer.he.ba',
            'recording_type' => 'isolation',
        ]);

        $rows = ll_tools_export_build_wordset_csv_rows(
            $wordset_id,
            ['title', 'translation', 'example_sentence', 'example_sentence_translation'],
            [
                'isolation' => ['text', 'translation', 'transcription'],
            ],
            1,
            'Selected Dialect',
            'Selected Source'
        );

        $this->assertCount(7, $rows);
        $this->assertContains(['Merheba', 'mer.he.ba', 'Hello', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['Hello', 'mer.he.ba', 'Merheba', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['Merheba dunya', '', 'Hello world', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['Hello world', '', 'Merheba dunya', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['Merheba sentence', 'mer.he.ba', 'Hello sentence', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['Hello sentence', 'mer.he.ba', 'Merheba sentence', 'Selected Dialect', 'Selected Source', ''], $rows);
        $this->assertContains(['mer.he.ba', 'mer.he.ba', 'Merheba sentence', 'Selected Dialect', 'Selected Source', ''], $rows);
    }

    private function createWordset(string $name): int
    {
        $result = wp_insert_term($name . ' ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($result));
        $this->assertIsArray($result);

        return (int) $result['term_id'];
    }

    private function createWord(int $wordset_id, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    /**
     * @param array<string,string> $meta
     */
    private function createAudioRecording(int $word_id, array $meta): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Recording ' . wp_generate_password(5, false, false),
        ]);

        if (isset($meta['recording_text'])) {
            update_post_meta($recording_id, 'recording_text', $meta['recording_text']);
        }
        if (isset($meta['recording_translation'])) {
            update_post_meta($recording_id, 'recording_translation', $meta['recording_translation']);
        }
        if (isset($meta['recording_ipa'])) {
            update_post_meta($recording_id, 'recording_ipa', $meta['recording_ipa']);
        }
        if (!empty($meta['recording_type'])) {
            $recording_type_id = $this->ensureRecordingType((string) $meta['recording_type']);
            wp_set_post_terms($recording_id, [$recording_type_id], 'recording_type', false);
        }

        return (int) $recording_id;
    }

    private function ensureRecordingType(string $slug): int
    {
        $slug = sanitize_title($slug);
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'recording_type', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }
}
