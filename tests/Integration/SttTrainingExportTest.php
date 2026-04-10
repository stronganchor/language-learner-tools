<?php
declare(strict_types=1);

final class SttTrainingExportTest extends LL_Tools_TestCase
{
    public function test_export_page_renders_stt_training_section(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('STT Render Wordset', 'wordset');
        $this->assertFalse(is_wp_error($wordset));

        ob_start();
        ll_tools_render_export_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Export STT Training Data', $output);
        $this->assertStringContainsString('ll_tools_export_stt_training_bundle', $output);
        $this->assertStringContainsString('ll_export_stt_text_field', $output);
        $this->assertMatchesRegularExpression('/name="ll_stt_only_reviewed" value="1" checked/', $output);
    }

    public function test_build_stt_training_entries_filters_by_selected_text_field(): void
    {
        $wordset_id = $this->createWordset('STT Field Filter');
        $word_id = $this->createWord($wordset_id, 'Alpha', 'Alpha translation');

        $audio_text_id = $this->createAudioRecording($word_id, 'stt-text.mp3', [
            'recording_text' => 'Alpha text',
            'recording_ipa' => '',
        ]);
        $audio_ipa_id = $this->createAudioRecording($word_id, 'stt-ipa.mp3', [
            'recording_text' => '',
            'recording_ipa' => 'al.fa',
        ]);

        $text_entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_text');
        $ipa_entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_ipa');

        $this->assertCount(1, $text_entries);
        $this->assertSame($audio_text_id, (int) $text_entries[0]['recording_id']);
        $this->assertSame('Alpha text', (string) $text_entries[0]['text']);
        $this->assertSame('Alpha', (string) $text_entries[0]['word_title']);

        $this->assertCount(1, $ipa_entries);
        $this->assertSame($audio_ipa_id, (int) $ipa_entries[0]['recording_id']);
        $this->assertSame('al.fa', (string) $ipa_entries[0]['text']);
        $this->assertSame('recording_ipa', (string) $ipa_entries[0]['text_field']);
    }

    public function test_build_stt_training_entries_excludes_unreviewed_transcriptions_by_default(): void
    {
        $wordset_id = $this->createWordset('STT Review Filter');
        $word_id = $this->createWord($wordset_id, 'Charlie', 'Charlie translation');

        $reviewed_recording_id = $this->createAudioRecording($word_id, 'stt-reviewed.mp3', [
            'recording_text' => 'Charlie reviewed text',
        ]);
        $flagged_recording_id = $this->createAudioRecording($word_id, 'stt-flagged.mp3', [
            'recording_text' => 'Charlie needs review',
            'needs_review' => '1',
        ]);

        $default_entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_text');
        $all_entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_text', false);

        $this->assertCount(1, $default_entries);
        $this->assertSame($reviewed_recording_id, (int) $default_entries[0]['recording_id']);
        $this->assertFalse((bool) $default_entries[0]['needs_review']);

        $this->assertCount(2, $all_entries);
        $this->assertSame(
            [$reviewed_recording_id, $flagged_recording_id],
            array_map('intval', array_column($all_entries, 'recording_id'))
        );

        $entries_by_id = [];
        foreach ($all_entries as $entry) {
            $entries_by_id[(int) ($entry['recording_id'] ?? 0)] = $entry;
        }

        $this->assertFalse((bool) ($entries_by_id[$reviewed_recording_id]['needs_review'] ?? true));
        $this->assertTrue((bool) ($entries_by_id[$flagged_recording_id]['needs_review'] ?? false));
    }

    public function test_stt_training_zip_contains_metadata_and_audio_files(): void
    {
        $wordset_id = $this->createWordset('STT Zip Wordset');
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $word_id = $this->createWord($wordset_id, 'Bravo', 'Bravo translation');
        $recording_id = $this->createAudioRecording($word_id, 'stt-zip.mp3', [
            'recording_text' => 'Bravo text',
            'recording_ipa' => 'bɹa.vo',
            'needs_review' => '1',
            'recording_type' => 'isolation',
        ]);

        $entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_text', false);
        $this->assertCount(1, $entries);
        $this->assertSame($recording_id, (int) $entries[0]['recording_id']);
        $this->assertTrue((bool) $entries[0]['needs_review']);

        $zip_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-stt-test-' . wp_generate_password(10, false, false) . '.zip');
        @unlink($zip_path);

        try {
            $result = ll_tools_write_stt_training_zip($zip_path, $entries, $wordset, 'recording_text');
            $this->assertTrue($result === true, is_wp_error($result) ? $result->get_error_message() : '');
            $this->assertFileExists($zip_path);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path) === true);

            $metadata_csv = (string) $zip->getFromName('metadata.csv');
            $metadata_jsonl = (string) $zip->getFromName('metadata.jsonl');
            $audio_entry = (string) $entries[0]['audio'];

            $this->assertNotSame('', $metadata_csv);
            $this->assertNotSame('', $metadata_jsonl);
            $this->assertNotFalse($zip->locateName($audio_entry));
            $csv_rows = array_map(
                static function (string $row): array {
                    return str_getcsv($row, ',', '"', '\\');
                },
                preg_split('/\r\n|\r|\n/', trim($metadata_csv))
            );
            $this->assertNotEmpty($csv_rows);
            $csv_header = array_shift($csv_rows);
            $this->assertIsArray($csv_header);
            $this->assertNotEmpty($csv_rows);
            $csv_entry = array_combine($csv_header, $csv_rows[0]);
            $this->assertIsArray($csv_entry);
            $this->assertSame($audio_entry, (string) ($csv_entry['audio'] ?? ''));
            $this->assertSame('Bravo text', (string) ($csv_entry['text'] ?? ''));
            $this->assertSame('1', (string) ($csv_entry['needs_review'] ?? ''));

            $jsonl_rows = preg_split('/\r\n|\r|\n/', trim($metadata_jsonl));
            $this->assertIsArray($jsonl_rows);
            $this->assertCount(1, $jsonl_rows);
            $json_entry = json_decode((string) $jsonl_rows[0], true);
            $this->assertIsArray($json_entry);
            $this->assertSame($audio_entry, (string) ($json_entry['audio'] ?? ''));
            $this->assertSame('Bravo text', (string) ($json_entry['text'] ?? ''));
            $this->assertSame(['isolation'], $json_entry['recording_types'] ?? []);
            $this->assertTrue((bool) ($json_entry['needs_review'] ?? false));

            $zip->close();
        } finally {
            @unlink($zip_path);
        }
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
    private function createAudioRecording(int $word_id, string $filename, array $meta): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Recording ' . wp_generate_password(5, false, false),
        ]);

        update_post_meta($recording_id, 'audio_file_path', $this->createAudioUploadFile($filename));

        if (isset($meta['recording_text'])) {
            update_post_meta($recording_id, 'recording_text', $meta['recording_text']);
        }
        if (isset($meta['recording_ipa'])) {
            update_post_meta($recording_id, 'recording_ipa', $meta['recording_ipa']);
        }
        if (isset($meta['recording_translation'])) {
            update_post_meta($recording_id, 'recording_translation', $meta['recording_translation']);
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
}
