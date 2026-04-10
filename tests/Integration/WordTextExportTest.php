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
        $category = wp_insert_term('Greetings', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        update_post_meta($word_id, 'word_example_sentence', 'Merheba dunya');
        update_post_meta($word_id, 'word_example_sentence_translation', 'Hello world');
        $speaker_id = self::factory()->user->create([
            'role' => 'administrator',
            'display_name' => 'Speaker Export',
        ]);

        $recording_id = $this->createAudioRecording($word_id, [
            'recording_text' => 'Merheba sentence',
            'recording_translation' => 'Hello sentence',
            'recording_ipa' => 'mer.he.ba',
            'recording_type' => 'isolation',
            'speaker_user_id' => (string) $speaker_id,
            'audio_filename' => 'word-text-export.mp3',
            'duration_seconds' => '1.234',
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

        $header = ll_tools_export_build_wordset_csv_header(['en']);
        $this->assertSame([
            'Lexeme',
            'PhoneticForm',
            'Gloss_en',
            'Dialect',
            'Source',
            'Notes',
            'word_id',
            'recording_id',
            'word_audio_id',
            'recording_type',
            'category_slug',
            'category_name',
            'speaker_user_id',
            'speaker_name',
            'recording_text',
            'recording_ipa',
            'review-status',
            'audio_url',
            'duration_seconds',
        ], $header);

        $rows_by_key = [];
        foreach ($rows as $row) {
            $assoc_row = array_combine($header, $row);
            $this->assertIsArray($assoc_row);
            $rows_by_key[((string) $assoc_row['Lexeme']) . '|' . ((string) $assoc_row['Gloss_en'])] = $assoc_row;
        }

        $this->assertCount(7, $rows);

        $title_row = $rows_by_key['Merheba|Hello'] ?? null;
        $this->assertIsArray($title_row);
        $this->assertSame('mer.he.ba', (string) $title_row['PhoneticForm']);
        $this->assertSame((string) $word_id, (string) $title_row['word_id']);
        $this->assertSame('', (string) $title_row['recording_id']);
        $this->assertSame('', (string) $title_row['word_audio_id']);
        $this->assertSame('', (string) $title_row['recording_type']);
        $this->assertSame((string) $category_term->slug, (string) $title_row['category_slug']);
        $this->assertSame((string) $category_term->name, (string) $title_row['category_name']);
        $this->assertSame('', (string) $title_row['speaker_user_id']);
        $this->assertSame('', (string) $title_row['speaker_name']);
        $this->assertSame('', (string) $title_row['recording_text']);
        $this->assertSame('', (string) $title_row['recording_ipa']);
        $this->assertSame('', (string) $title_row['review-status']);
        $this->assertSame('', (string) $title_row['audio_url']);
        $this->assertSame('', (string) $title_row['duration_seconds']);

        $recording_row = $rows_by_key['Merheba sentence|Hello sentence'] ?? null;
        $this->assertIsArray($recording_row);
        $this->assertSame('mer.he.ba', (string) $recording_row['PhoneticForm']);
        $this->assertSame((string) $word_id, (string) $recording_row['word_id']);
        $this->assertSame((string) $recording_id, (string) $recording_row['recording_id']);
        $this->assertSame((string) $recording_id, (string) $recording_row['word_audio_id']);
        $this->assertSame('isolation', (string) $recording_row['recording_type']);
        $this->assertSame((string) $category_term->slug, (string) $recording_row['category_slug']);
        $this->assertSame((string) $category_term->name, (string) $recording_row['category_name']);
        $this->assertSame((string) $speaker_id, (string) $recording_row['speaker_user_id']);
        $this->assertSame('Speaker Export', (string) $recording_row['speaker_name']);
        $this->assertSame('Merheba sentence', (string) $recording_row['recording_text']);
        $this->assertSame('mer.he.ba', (string) $recording_row['recording_ipa']);
        $this->assertSame('reviewed', (string) $recording_row['review-status']);
        $this->assertSame(
            ll_tools_resolve_audio_file_url((string) get_post_meta($recording_id, 'audio_file_path', true)),
            (string) $recording_row['audio_url']
        );
        $this->assertSame('1.234', (string) $recording_row['duration_seconds']);
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
        if (isset($meta['speaker_user_id'])) {
            update_post_meta($recording_id, 'speaker_user_id', (int) $meta['speaker_user_id']);
        }
        if (isset($meta['speaker_name'])) {
            update_post_meta($recording_id, 'speaker_name', (string) $meta['speaker_name']);
        }
        if (!empty($meta['audio_filename'])) {
            $file_path = $this->createAudioUploadFile((string) $meta['audio_filename']);
            update_post_meta($recording_id, 'audio_file_path', $file_path);

            if (isset($meta['duration_seconds']) && $meta['duration_seconds'] !== '') {
                $signature = ll_tools_wordset_games_build_audio_duration_signature($file_path);
                update_post_meta($recording_id, ll_tools_wordset_games_get_audio_duration_cache_meta_key(), (string) $meta['duration_seconds']);
                update_post_meta($recording_id, ll_tools_wordset_games_get_audio_duration_signature_meta_key(), $signature);
            }
        }
        if (!empty($meta['needs_review'])) {
            update_post_meta($recording_id, 'll_auto_transcription_needs_review', '1');
        }
        if (!empty($meta['recording_type'])) {
            $recording_type_id = $this->ensureRecordingType((string) $meta['recording_type']);
            wp_set_post_terms($recording_id, [$recording_type_id], 'recording_type', false);
        }

        return (int) $recording_id;
    }

    private function createAudioUploadFile(string $filename): string
    {
        $upload = wp_upload_bits($filename, null, "fake audio bytes\n");
        $this->assertIsArray($upload);
        $this->assertArrayHasKey('error', $upload);
        $this->assertSame('', (string) $upload['error']);

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        return wp_normalize_path($file_path);
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
