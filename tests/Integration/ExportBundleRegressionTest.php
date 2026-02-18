<?php
declare(strict_types=1);

final class ExportBundleRegressionTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_full_bundle_zip_can_be_created_when_target_file_does_not_exist(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_term = wp_insert_term('Export Bundle Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_term = wp_insert_term('Export Bundle Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];

        $recording_term = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        if (is_wp_error($recording_term)) {
            $existing = get_term_by('slug', 'isolation', 'recording_type');
            $this->assertInstanceOf(WP_Term::class, $existing);
            $recording_type_id = (int) $existing->term_id;
        } else {
            $this->assertIsArray($recording_term);
            $recording_type_id = (int) $recording_term['term_id'];
        }

        $image_attachment_id = $this->create_image_attachment('export-regression-image.png');
        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Export Regression Image Post',
        ]);
        wp_set_post_terms($word_image_id, [$category_id], 'word-category', false);
        set_post_thumbnail($word_image_id, $image_attachment_id);

        $word_attachment_id = $this->create_image_attachment('export-regression-word-image.png');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Export Regression Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $word_attachment_id);

        $audio_path = $this->create_audio_upload_file('export-regression-audio.mp3');
        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Export Regression Audio',
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', $audio_path);
        wp_set_post_terms($audio_post_id, [$recording_type_id], 'recording_type', false);

        $payload = ll_tools_build_export_payload($category_id, [
            'include_full_bundle' => true,
            'full_wordset_id' => $wordset_id,
        ]);
        $this->assertFalse(is_wp_error($payload));
        $this->assertIsArray($payload);
        $this->assertNotEmpty((array) ($payload['attachments'] ?? []));

        $zip_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-export-regression-' . wp_generate_password(10, false, false) . '.zip');
        @unlink($zip_path);
        $this->assertFalse(file_exists($zip_path));

        try {
            $zip_result = ll_tools_write_export_bundle_zip(
                $zip_path,
                (array) ($payload['data'] ?? []),
                (array) ($payload['attachments'] ?? [])
            );
            $this->assertTrue($zip_result === true, is_wp_error($zip_result) ? $zip_result->get_error_message() : '');
            $this->assertFileExists($zip_path);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path) === true);
            $this->assertNotFalse($zip->locateName('data.json'));

            $entry_names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (is_array($stat) && isset($stat['name'])) {
                    $entry_names[] = (string) $stat['name'];
                }
            }
            $zip->close();

            $has_audio_entry = false;
            $has_image_entry = false;
            foreach ($entry_names as $entry_name) {
                if (strpos($entry_name, 'audio/') === 0) {
                    $has_audio_entry = true;
                }
                if (strpos($entry_name, 'media/') === 0) {
                    $has_image_entry = true;
                }
            }

            $this->assertTrue($has_audio_entry, 'Expected at least one exported audio file in zip.');
            $this->assertTrue($has_image_entry, 'Expected at least one exported image file in zip.');
        } finally {
            @unlink($zip_path);
        }
    }

    private function create_image_attachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertArrayHasKey('error', $upload);
        $this->assertSame('', (string) $upload['error']);

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);
        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    private function create_audio_upload_file(string $filename): string
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
