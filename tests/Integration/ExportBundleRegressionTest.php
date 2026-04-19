<?php
declare(strict_types=1);

final class ExportBundleRegressionTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_full_bundle_zip_can_be_created_when_target_file_does_not_exist(): void
    {
        $fixture = $this->create_full_bundle_fixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];

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

    public function test_full_bundle_payload_can_exceed_export_safeguards_and_still_build(): void
    {
        $fixture = $this->create_full_bundle_fixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];

        $filters = [
            'll_tools_export_soft_limit_bytes' => static function (): int {
                return 1;
            },
            'll_tools_export_hard_limit_bytes' => static function (): int {
                return 1;
            },
            'll_tools_export_hard_limit_files' => static function (): int {
                return 1;
            },
            'll_tools_export_multi_full_bundle_limit_bytes' => static function (): int {
                return 1;
            },
        ];

        foreach ($filters as $hook => $callback) {
            add_filter($hook, $callback);
        }

        try {
            $payload = ll_tools_build_export_payload($category_id, [
                'include_full_bundle' => true,
                'full_wordset_id' => $wordset_id,
            ]);

            $this->assertFalse(is_wp_error($payload), is_wp_error($payload) ? $payload->get_error_message() : '');
            $this->assertIsArray($payload);

            $stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];
            $attachment_count = (int) ($stats['attachment_count'] ?? 0);
            $attachment_bytes = (int) ($stats['attachment_bytes'] ?? 0);

            $this->assertGreaterThan(1, $attachment_count);
            $this->assertGreaterThan(1, $attachment_bytes);

            $warnings = ll_tools_export_get_preflight_warnings($attachment_count, $attachment_bytes, true);
            $this->assertNotEmpty($warnings);

            $warning_text = implode("\n", $warnings);
            $this->assertStringContainsString('warning threshold', $warning_text);
            $this->assertStringContainsString('large-export safeguard', $warning_text);
            $this->assertStringContainsString('reliability safeguard', $warning_text);
        } finally {
            foreach ($filters as $hook => $callback) {
                remove_filter($hook, $callback);
            }
        }
    }

    public function test_full_bundle_can_be_built_in_multiple_export_batches_and_finish_as_one_zip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $fixture = $this->create_full_bundle_fixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];
        $token = '';
        $zip_path = '';

        $filters = [
            'll_tools_export_batch_max_files_per_request' => static function (): int {
                return 1;
            },
            'll_tools_export_batch_max_bytes_per_request' => static function (): int {
                return 64 * MB_IN_BYTES;
            },
        ];

        foreach ($filters as $hook => $callback) {
            add_filter($hook, $callback);
        }

        try {
            $start = ll_tools_export_prepare_batch_job([
                'll_word_category' => (string) $category_id,
                'll_export_include_full' => '1',
                'll_full_export_wordset_id' => (string) $wordset_id,
                'll_allow_large_export' => '1',
            ]);

            $this->assertFalse(is_wp_error($start), is_wp_error($start) ? $start->get_error_message() : '');
            $this->assertIsArray($start);
            $this->assertSame('processing', (string) ($start['status'] ?? ''));

            $token = (string) ($start['token'] ?? '');
            $this->assertNotSame('', $token);

            $result = $start;
            $iterations = 0;
            while ((string) ($result['status'] ?? '') !== 'completed' && $iterations < 10) {
                $result = ll_tools_export_run_batch_job($token);
                $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
                $this->assertIsArray($result);
                $iterations++;
            }

            $this->assertSame('completed', (string) ($result['status'] ?? ''));
            $this->assertGreaterThan(1, $iterations, 'Expected multiple export batch requests.');
            $this->assertSame(1.0, (float) ($result['progressRatio'] ?? 0));
            $this->assertNotSame('', (string) ($result['downloadUrl'] ?? ''));

            $download_manifest = get_transient(ll_tools_export_download_transient_key($token));
            $this->assertIsArray($download_manifest);
            $zip_path = isset($download_manifest['zip_path']) ? wp_normalize_path((string) $download_manifest['zip_path']) : '';
            $this->assertNotSame('', $zip_path);
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

            $this->assertTrue($has_audio_entry, 'Expected at least one exported audio file in batched zip.');
            $this->assertTrue($has_image_entry, 'Expected at least one exported image file in batched zip.');
        } finally {
            foreach ($filters as $hook => $callback) {
                remove_filter($hook, $callback);
            }

            if ($token !== '') {
                delete_transient(ll_tools_export_download_transient_key($token));
                delete_transient(ll_tools_export_batch_job_transient_key($token));
            }
            if ($zip_path !== '' && is_file($zip_path)) {
                @unlink($zip_path);
            }
        }
    }

    /**
     * @return array{wordset_id:int,category_id:int}
     */
    private function create_full_bundle_fixture(): array
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

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
        ];
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
