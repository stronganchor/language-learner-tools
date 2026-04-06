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
        $this->assertStringContainsString('ll_tools_import_metadata_updates', $output);
        $this->assertStringContainsString('metadata.csv or metadata.jsonl', $output);
        $this->assertStringContainsString('ll-tools-copy-reference-button', $output);
        $this->assertStringContainsString('ll-tools-metadata-update-agent-instructions', $output);
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
            $this->assertSame('New Word', (string) get_the_title($word_id));
            $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));
            $this->assertSame('New Recording Text', (string) get_post_meta($recording_id, 'recording_text', true));
            $this->assertSame('new.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame('Speaker New', (string) get_post_meta($recording_id, 'speaker_name', true));
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
}
