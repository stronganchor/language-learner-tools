<?php
declare(strict_types=1);

final class SttTrainingExportTest extends LL_Tools_TestCase
{
    public function test_export_page_renders_stt_training_section(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('STT Render Wordset ' . wp_generate_password(6, false, false), 'wordset');
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
            'review_fields' => ['recording_text'],
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
        $category = wp_insert_term('STT Zip Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        $assigned_categories = wp_get_post_terms($word_id, 'word-category', [
            'orderby' => 'term_id',
            'order' => 'ASC',
        ]);
        $this->assertNotEmpty($assigned_categories);
        $assigned_category_term = $assigned_categories[0];
        $this->assertInstanceOf(WP_Term::class, $assigned_category_term);

        $recording_id = $this->createAudioRecording($word_id, 'stt-zip.mp3', [
            'recording_text' => 'Bravo text',
            'recording_ipa' => 'bɹa.vo',
            'review_fields' => ['recording_text'],
            'recording_type' => 'isolation',
            'audio_credit' => 'Speaker: Speaker Bravo; Recorder: Example Commons',
            'audio_source_name' => 'Example Commons',
            'audio_source_url' => 'https://example.com/audio/bravo',
            'audio_license' => 'CC BY-SA 4.0',
            'audio_license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'audio_change_note' => 'Normalized for loudness and trimmed for silence',
        ]);
        $speaker_id = self::factory()->user->create([
            'role' => 'administrator',
            'display_name' => 'Speaker Bravo',
        ]);
        update_post_meta($recording_id, 'speaker_user_id', $speaker_id);

        $stored_audio_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
        $this->assertNotSame('', $stored_audio_path);
        $duration_signature = ll_tools_wordset_games_build_audio_duration_signature($stored_audio_path);
        update_post_meta($recording_id, ll_tools_wordset_games_get_audio_duration_cache_meta_key(), '1.234');
        update_post_meta($recording_id, ll_tools_wordset_games_get_audio_duration_signature_meta_key(), $duration_signature);

        $entries = ll_tools_export_build_stt_training_entries($wordset_id, 'recording_text', false);
        $this->assertCount(1, $entries);
        $this->assertSame($recording_id, (int) $entries[0]['recording_id']);
        $this->assertSame($recording_id, (int) $entries[0]['word_audio_id']);
        $this->assertTrue((bool) $entries[0]['needs_review']);
        $this->assertSame('needs_review', (string) $entries[0]['review_status']);
        $this->assertSame('isolation', (string) $entries[0]['recording_type']);
        $this->assertSame((string) $assigned_category_term->slug, (string) $entries[0]['category_slug']);
        $this->assertSame((string) $assigned_category_term->name, (string) $entries[0]['category_name']);
        $this->assertSame($speaker_id, (int) $entries[0]['speaker_user_id']);
        $this->assertSame('Speaker Bravo', (string) $entries[0]['speaker_name']);
        $this->assertSame('Speaker: Speaker Bravo; Recorder: Example Commons', (string) $entries[0]['audio_credit']);
        $this->assertSame('Example Commons', (string) $entries[0]['audio_source_name']);
        $this->assertSame('https://example.com/audio/bravo', (string) $entries[0]['audio_source_url']);
        $this->assertSame('CC BY-SA 4.0', (string) $entries[0]['audio_license']);
        $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', (string) $entries[0]['audio_license_url']);
        $this->assertSame('Normalized for loudness and trimmed for silence', (string) $entries[0]['audio_change_note']);
        $this->assertSame(
            ll_tools_resolve_audio_file_url($stored_audio_path),
            (string) $entries[0]['audio_url']
        );
        $this->assertSame(1.234, (float) $entries[0]['duration_seconds']);

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
            $this->assertSame(ll_tools_resolve_audio_file_url($stored_audio_path), (string) ($csv_entry['audio_url'] ?? ''));
            $this->assertSame('1.234', (string) ($csv_entry['duration_seconds'] ?? ''));
            $this->assertSame((string) $word_id, (string) ($csv_entry['word_id'] ?? ''));
            $this->assertSame((string) $recording_id, (string) ($csv_entry['recording_id'] ?? ''));
            $this->assertSame((string) $recording_id, (string) ($csv_entry['word_audio_id'] ?? ''));
            $this->assertSame('isolation', (string) ($csv_entry['recording_type'] ?? ''));
            $this->assertSame((string) $assigned_category_term->slug, (string) ($csv_entry['category_slug'] ?? ''));
            $this->assertSame((string) $assigned_category_term->name, (string) ($csv_entry['category_name'] ?? ''));
            $this->assertSame((string) $speaker_id, (string) ($csv_entry['speaker_user_id'] ?? ''));
            $this->assertSame('Speaker Bravo', (string) ($csv_entry['speaker_name'] ?? ''));
            $this->assertSame('Speaker: Speaker Bravo; Recorder: Example Commons', (string) ($csv_entry['audio_credit'] ?? ''));
            $this->assertSame('Example Commons', (string) ($csv_entry['audio_source_name'] ?? ''));
            $this->assertSame('https://example.com/audio/bravo', (string) ($csv_entry['audio_source_url'] ?? ''));
            $this->assertSame('CC BY-SA 4.0', (string) ($csv_entry['audio_license'] ?? ''));
            $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', (string) ($csv_entry['audio_license_url'] ?? ''));
            $this->assertSame('Normalized for loudness and trimmed for silence', (string) ($csv_entry['audio_change_note'] ?? ''));
            $this->assertSame('Bravo text', (string) ($csv_entry['recording_text'] ?? ''));
            $this->assertSame('bɹa.vo', (string) ($csv_entry['recording_ipa'] ?? ''));
            $this->assertSame('needs_review', (string) ($csv_entry['review-status'] ?? ''));
            $this->assertSame('1', (string) ($csv_entry['needs_review'] ?? ''));

            $jsonl_rows = preg_split('/\r\n|\r|\n/', trim($metadata_jsonl));
            $this->assertIsArray($jsonl_rows);
            $this->assertCount(1, $jsonl_rows);
            $json_entry = json_decode((string) $jsonl_rows[0], true);
            $this->assertIsArray($json_entry);
            $this->assertSame($audio_entry, (string) ($json_entry['audio'] ?? ''));
            $this->assertSame('Bravo text', (string) ($json_entry['text'] ?? ''));
            $this->assertSame(ll_tools_resolve_audio_file_url($stored_audio_path), (string) ($json_entry['audio_url'] ?? ''));
            $this->assertSame(1.234, (float) ($json_entry['duration_seconds'] ?? 0));
            $this->assertSame($recording_id, (int) ($json_entry['word_audio_id'] ?? 0));
            $this->assertSame('isolation', (string) ($json_entry['recording_type'] ?? ''));
            $this->assertSame(['isolation'], $json_entry['recording_types'] ?? []);
            $this->assertSame((string) $assigned_category_term->slug, (string) ($json_entry['category_slug'] ?? ''));
            $this->assertSame((string) $assigned_category_term->name, (string) ($json_entry['category_name'] ?? ''));
            $this->assertSame($speaker_id, (int) ($json_entry['speaker_user_id'] ?? 0));
            $this->assertSame('Speaker Bravo', (string) ($json_entry['speaker_name'] ?? ''));
            $this->assertSame('Speaker: Speaker Bravo; Recorder: Example Commons', (string) ($json_entry['audio_credit'] ?? ''));
            $this->assertSame('Example Commons', (string) ($json_entry['audio_source_name'] ?? ''));
            $this->assertSame('https://example.com/audio/bravo', (string) ($json_entry['audio_source_url'] ?? ''));
            $this->assertSame('CC BY-SA 4.0', (string) ($json_entry['audio_license'] ?? ''));
            $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', (string) ($json_entry['audio_license_url'] ?? ''));
            $this->assertSame('Normalized for loudness and trimmed for silence', (string) ($json_entry['audio_change_note'] ?? ''));
            $this->assertSame('needs_review', (string) ($json_entry['review_status'] ?? ''));
            $this->assertTrue((bool) ($json_entry['needs_review'] ?? false));

            $zip->close();
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_stt_training_job_uses_bounded_title_ordered_batches(): void
    {
        $wordset_id = $this->createWordset('STT Batched Wordset');
        foreach (['Echo', 'Alpha', 'Delta', 'Bravo', 'Charlie'] as $title) {
            $word_id = $this->createWord($wordset_id, $title, $title . ' translation');
            $this->createAudioRecording($word_id, 'stt-job-' . strtolower($title) . '.mp3', [
                'recording_text' => $title . ' text',
            ]);
        }

        $candidate_queries = [];
        $query_filter = static function (string $query) use (&$candidate_queries): string {
            if (stripos($query, 'SELECT a.ID AS recording_id') !== false
                && stripos($query, "a.post_type = 'word_audio'") !== false) {
                $candidate_queries[] = $query;
            }
            return $query;
        };
        $batch_filter = static function (): int {
            return 2;
        };
        add_filter('query', $query_filter);
        add_filter('ll_tools_export_stt_job_batch_size', $batch_filter);
        $token = '';
        $zip_path = '';
        try {
            $start = ll_tools_export_prepare_stt_training_job([
                'll_stt_wordset_id' => $wordset_id,
                'll_stt_text_field' => 'recording_text',
                'll_stt_only_reviewed' => '0',
            ]);
            $this->assertIsArray($start);
            $this->assertSame([], $candidate_queries, 'Starting the job must not scan recordings.');
            $token = (string) ($start['token'] ?? '');
            $this->assertNotSame('', $token);

            $responses = [];
            for ($iteration = 0; $iteration < 10; $iteration++) {
                $response = ll_tools_export_run_batch_job($token);
                $this->assertIsArray($response, is_wp_error($response) ? $response->get_error_message() : '');
                $responses[] = $response;
                if ((string) ($response['status'] ?? '') === 'completed') {
                    break;
                }
            }

            $this->assertCount(3, $responses);
            $this->assertSame(2, (int) ($responses[0]['checkedRecordings'] ?? 0));
            $this->assertSame(4, (int) ($responses[1]['checkedRecordings'] ?? 0));
            $this->assertSame(5, (int) ($responses[2]['checkedRecordings'] ?? 0));
            $this->assertSame(5, (int) ($responses[2]['sampleCount'] ?? 0));
            $this->assertSame('completed', (string) ($responses[2]['status'] ?? ''));
            $this->assertCount(3, $candidate_queries);
            foreach ($candidate_queries as $candidate_query) {
                $this->assertMatchesRegularExpression('/LIMIT\s+3\s*$/i', trim($candidate_query));
            }

            $download = get_transient(ll_tools_export_download_transient_key($token));
            $this->assertIsArray($download);
            $zip_path = (string) ($download['zip_path'] ?? '');
            $this->assertFileExists($zip_path);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path) === true);
            $jsonl = (string) $zip->getFromName('metadata.jsonl');
            $zip->close();
            $rows = array_values(array_filter(array_map(static function (string $line): array {
                $decoded = json_decode($line, true);
                return is_array($decoded) ? $decoded : [];
            }, preg_split('/\r\n|\r|\n/', trim($jsonl)))));
            $this->assertSame(
                ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'],
                array_values(array_map(static function (array $row): string {
                    return (string) ($row['word_title'] ?? '');
                }, $rows))
            );
        } finally {
            remove_filter('query', $query_filter);
            remove_filter('ll_tools_export_stt_job_batch_size', $batch_filter);
            if ($token !== '') {
                delete_transient(ll_tools_export_download_transient_key($token));
            }
            if ($zip_path !== '' && is_file($zip_path)) {
                @unlink($zip_path);
            }
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
     * @param array<string,mixed> $meta
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
        if (isset($meta['audio_credit'])) {
            update_post_meta($recording_id, 'audio_credit', (string) $meta['audio_credit']);
        }
        if (isset($meta['audio_source_name'])) {
            update_post_meta($recording_id, 'audio_source_name', (string) $meta['audio_source_name']);
        }
        if (isset($meta['audio_source_url'])) {
            update_post_meta($recording_id, 'audio_source_url', (string) $meta['audio_source_url']);
        }
        if (isset($meta['audio_license'])) {
            update_post_meta($recording_id, 'audio_license', (string) $meta['audio_license']);
        }
        if (isset($meta['audio_license_url'])) {
            update_post_meta($recording_id, 'audio_license_url', (string) $meta['audio_license_url']);
        }
        if (isset($meta['audio_change_note'])) {
            update_post_meta($recording_id, 'audio_change_note', (string) $meta['audio_change_note']);
        }
        $review_fields = [];
        if (!empty($meta['review_fields']) && is_array($meta['review_fields'])) {
            $review_fields = $meta['review_fields'];
        } elseif (!empty($meta['needs_review'])) {
            $review_fields = ['recording_ipa'];
        }
        foreach ($review_fields as $review_field) {
            if (function_exists('ll_tools_ipa_keyboard_mark_recording_needs_auto_review')) {
                ll_tools_ipa_keyboard_mark_recording_needs_auto_review($recording_id, (string) $review_field);
            } else {
                update_post_meta($recording_id, 'll_auto_transcription_needs_review', '1');
            }
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
