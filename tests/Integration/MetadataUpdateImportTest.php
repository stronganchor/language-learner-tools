<?php
declare(strict_types=1);

final class MetadataUpdateImportTest extends LL_Tools_TestCase
{
    public function test_import_page_renders_metadata_update_section(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        ob_start();
        ll_tools_render_import_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Metadata Updates', $output);
        $this->assertStringContainsString('ll_tools_preview_metadata_updates', $output);
        $this->assertStringContainsString('metadata.csv or metadata.jsonl', $output);
        $this->assertStringContainsString('ll_import_metadata_mark_ipa_review', $output);
        $this->assertStringContainsString('Mark imported IPA transcription changes as needing review', $output);
        $this->assertStringContainsString('Preview Metadata Updates', $output);
        $this->assertStringContainsString('ll-tools-copy-reference-button', $output);
        $this->assertStringContainsString('ll-tools-metadata-update-agent-instructions', $output);
    }

    public function test_metadata_update_preview_counts_changes_and_samples_old_vs_new_values(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Preview Word',
            'post_name' => 'preview-word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Old Translation');
        update_post_meta($word_id, 'word_english_meaning', 'Old Translation');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Preview Recording',
            'post_name' => 'preview-recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Old Recording Text');
        update_post_meta($recording_id, 'speaker_name', 'Speaker Old');

        $csv = implode("\n", [
            'word_id,recording_id,word_translation,word_example_sentence,recording_text,clear_fields',
            $word_id . ',' . $recording_id . ',New Translation,Fresh example sentence,New Recording Text,speaker_name',
        ]) . "\n";

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-metadata-preview-' . wp_generate_password(8, false, false) . '.csv');
        file_put_contents($file_path, $csv);

        try {
            $preview = ll_tools_build_metadata_update_preview_data($file_path, 'preview-updates.csv');

            $this->assertTrue((bool) ($preview['ok'] ?? false), implode(' | ', (array) ($preview['errors'] ?? [])));
            $this->assertSame(1, (int) (($preview['stats'] ?? [])['metadata_rows_total'] ?? 0));
            $this->assertSame(1, (int) (($preview['stats'] ?? [])['metadata_rows_applied'] ?? 0));
            $this->assertSame(1, (int) (($preview['stats'] ?? [])['words_updated'] ?? 0));
            $this->assertSame(1, (int) (($preview['stats'] ?? [])['word_audio_updated'] ?? 0));
            $this->assertSame(3, (int) (($preview['stats'] ?? [])['metadata_fields_updated'] ?? 0));
            $this->assertSame(1, (int) (($preview['stats'] ?? [])['metadata_fields_cleared'] ?? 0));

            $sample_changes = isset($preview['sample_changes']) && is_array($preview['sample_changes'])
                ? $preview['sample_changes']
                : [];
            $this->assertCount(4, $sample_changes);

            $by_field = [];
            foreach ($sample_changes as $sample_change) {
                if (!is_array($sample_change)) {
                    continue;
                }
                $by_field[(string) ($sample_change['field_label'] ?? '')] = $sample_change;
            }

            $this->assertSame('Old Translation', (string) (($by_field['Word translation'] ?? [])['current_value'] ?? ''));
            $this->assertSame('New Translation', (string) (($by_field['Word translation'] ?? [])['new_value'] ?? ''));
            $this->assertSame('', (string) (($by_field['Word example sentence'] ?? [])['current_value'] ?? ''));
            $this->assertSame('Fresh example sentence', (string) (($by_field['Word example sentence'] ?? [])['new_value'] ?? ''));
            $this->assertSame('Old Recording Text', (string) (($by_field['Recording text'] ?? [])['current_value'] ?? ''));
            $this->assertSame('New Recording Text', (string) (($by_field['Recording text'] ?? [])['new_value'] ?? ''));
            $this->assertSame('Speaker Old', (string) (($by_field['Speaker name'] ?? [])['current_value'] ?? ''));
            $this->assertSame('', (string) (($by_field['Speaker name'] ?? [])['new_value'] ?? ''));
        } finally {
            @unlink($file_path);
        }
    }

    public function test_import_page_renders_metadata_update_preview_section_from_transient(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $token = 'metadata-preview-' . wp_generate_password(8, false, false);
        set_transient(ll_tools_metadata_update_preview_transient_key($token), [
            'source_name' => 'preview-updates.csv',
            'options' => [
                'mark_imported_ipa_review' => true,
            ],
            'stats' => [
                'metadata_rows_total' => 2,
                'metadata_rows_applied' => 1,
                'metadata_rows_skipped' => 1,
                'words_updated' => 1,
                'word_audio_updated' => 1,
                'metadata_fields_updated' => 2,
                'metadata_fields_cleared' => 1,
            ],
            'sample_changes' => [
                [
                    'item_label' => 'Word: Preview Word',
                    'field_label' => 'Word translation',
                    'current_value' => '',
                    'new_value' => 'New Translation',
                ],
            ],
            'warnings' => [],
            'errors' => [],
            'file_path' => '/tmp/preview-updates.csv',
            'cleanup_file' => false,
        ], 30 * MINUTE_IN_SECONDS);

        $_GET['ll_metadata_preview'] = $token;

        try {
            ob_start();
            ll_tools_render_import_page();
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('Metadata Update Preview', $output);
            $this->assertStringContainsString('preview-updates.csv', $output);
            $this->assertStringContainsString('Words to update: 1', $output);
            $this->assertStringContainsString('Example updates', $output);
            $this->assertStringContainsString('Confirm Metadata Updates', $output);
            $this->assertStringContainsString('Blank', $output);
        } finally {
            unset($_GET['ll_metadata_preview']);
            delete_transient(ll_tools_metadata_update_preview_transient_key($token));
        }
    }

    public function test_process_metadata_update_csv_updates_word_and_recording_fields(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Old Word',
            'post_name' => 'old-word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Old Translation');
        update_post_meta($word_id, 'word_english_meaning', 'Old Translation');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Old Recording',
            'post_name' => 'old-recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Old Recording Text');
        update_post_meta($recording_id, 'recording_ipa', 'old.ipa');
        update_post_meta($recording_id, 'speaker_name', 'Speaker Old');

        $csv = implode("\n", [
            'word_id,recording_id,word_title,word_translation,recording_text,recording_ipa,speaker_name',
            $word_id . ',' . $recording_id . ',New Word,New Translation,New Recording Text,new.ipa,Speaker New',
        ]) . "\n";

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-metadata-update-' . wp_generate_password(8, false, false) . '.csv');
        file_put_contents($file_path, $csv);

        try {
            $result = ll_tools_process_metadata_updates_file($file_path, 'updates.csv');

            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertSame(1, (int) (($result['stats'] ?? [])['metadata_rows_applied'] ?? 0));
            $this->assertTrue(ll_tools_import_has_undo_targets((array) ($result['undo'] ?? [])));
            $this->assertSame(1, (int) (($result['stats'] ?? [])['words_updated'] ?? 0));
            $this->assertSame(1, (int) (($result['stats'] ?? [])['word_audio_updated'] ?? 0));
            $this->assertSame(1, (int) (($result['stats'] ?? [])['metadata_ipa_reviews_flagged'] ?? 0));
            $this->assertSame('New Word', (string) get_the_title($word_id));
            $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));
            $this->assertSame('New Recording Text', (string) get_post_meta($recording_id, 'recording_text', true));
            $this->assertSame('new.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame('Speaker New', (string) get_post_meta($recording_id, 'speaker_name', true));
            $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));
        } finally {
            @unlink($file_path);
        }
    }

    public function test_process_metadata_update_jsonl_supports_text_field_and_clear_fields(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Sample Word',
            'post_name' => 'sample-word',
        ]);
        update_post_meta($word_id, 'word_translation', 'To Clear');
        update_post_meta($word_id, 'word_english_meaning', 'To Clear');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Sample Recording',
            'post_name' => 'sample-recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Sample Text');
        update_post_meta($recording_id, 'recording_ipa', 'old.ipa');
        update_post_meta($recording_id, 'speaker_name', 'Speaker Old');

        $row = wp_json_encode([
            'word_id' => $word_id,
            'audio' => 'audio/' . $recording_id . '-sample.mp3',
            'text_field' => 'recording_ipa',
            'text' => 'fresh.ipa',
            'clear_fields' => ['word_translation', 'speaker_name'],
        ]);
        $this->assertIsString($row);

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-metadata-update-' . wp_generate_password(8, false, false) . '.jsonl');
        file_put_contents($file_path, $row . "\n");

        try {
            $result = ll_tools_process_metadata_updates_file($file_path, 'updates.jsonl');

            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertSame(1, (int) (($result['stats'] ?? [])['metadata_rows_applied'] ?? 0));
            $this->assertSame('', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('', (string) get_post_meta($word_id, 'word_english_meaning', true));
            $this->assertSame('fresh.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame('', (string) get_post_meta($recording_id, 'speaker_name', true));
        } finally {
            @unlink($file_path);
        }
    }

    public function test_process_metadata_update_can_skip_marking_imported_ipa_for_review(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Review Skip Word',
            'post_name' => 'review-skip-word',
        ]);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Review Skip Recording',
            'post_name' => 'review-skip-recording',
        ]);
        update_post_meta($recording_id, 'recording_ipa', 'skip.old.ipa');

        $csv = implode("\n", [
            'word_id,recording_id,recording_ipa',
            $word_id . ',' . $recording_id . ',skip.new.ipa',
        ]) . "\n";

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-metadata-update-' . wp_generate_password(8, false, false) . '.csv');
        file_put_contents($file_path, $csv);

        try {
            $result = ll_tools_process_metadata_updates_file($file_path, 'updates-skip-review.csv', [
                'mark_imported_ipa_review' => false,
            ]);

            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertSame('skip.new.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame(0, (int) (($result['stats'] ?? [])['metadata_ipa_reviews_flagged'] ?? 0));
            $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));
        } finally {
            @unlink($file_path);
        }
    }
}
