<?php
declare(strict_types=1);

final class MetadataUpdateBatchJobTest extends LL_Tools_TestCase
{
    public function test_csv_preview_and_apply_are_bounded_and_preserve_sequential_undo(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Batched Metadata Word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Original translation');

        $file_path = wp_tempnam('ll-tools-metadata-batch.csv');
        $this->assertIsString($file_path);
        $csv = "word_id,word_translation\n";
        for ($index = 1; $index <= 5; $index++) {
            $csv .= $word_id . ',Translation ' . $index . "\n";
        }
        $this->assertNotFalse(file_put_contents($file_path, $csv));

        $job = null;
        try {
            $preview_page = ll_tools_parse_metadata_updates_file_page($file_path, 'updates.csv', [], 2);
            $this->assertIsArray($preview_page);
            $this->assertCount(2, $preview_page['rows']);
            $this->assertTrue((bool) $preview_page['has_more']);
            $this->assertSame('Original translation', (string) get_post_meta($word_id, 'word_translation', true));

            $preview = ll_tools_build_metadata_update_preview_data($file_path, 'updates.csv', [
                'prepared_rows' => $preview_page['rows'],
                'partial_preview' => true,
                'mark_imported_ipa_review' => false,
            ]);
            $this->assertTrue((bool) $preview['ok']);
            $this->assertTrue((bool) $preview['partial_preview']);
            $this->assertSame(2, (int) $preview['stats']['metadata_rows_total']);

            $job = ll_tools_metadata_update_job_create($file_path, 'updates.csv', false, '', [
                'mark_imported_ipa_review' => false,
            ]);
            $this->assertIsArray($job);
            $this->assertSame('running', (string) $job['status']);
            $this->assertSame('Original translation', (string) get_post_meta($word_id, 'word_translation', true));

            $batch_size = static function (): int {
                return 2;
            };
            add_filter('ll_tools_metadata_update_stream_batch_size', $batch_size);
            $step_values = [];
            $previous_total = 0;
            try {
                for ($step = 0; $step < 5 && (string) ($job['status'] ?? '') === 'running'; $step++) {
                    $job = ll_tools_metadata_update_job_process($job);
                    $this->assertIsArray($job);
                    $current_total = (int) ($job['result']['stats']['metadata_rows_total'] ?? 0);
                    $this->assertLessThanOrEqual(2, $current_total - $previous_total);
                    $previous_total = $current_total;
                    $step_values[] = (string) get_post_meta($word_id, 'word_translation', true);
                }
            } finally {
                remove_filter('ll_tools_metadata_update_stream_batch_size', $batch_size);
            }

            $this->assertSame('completed', (string) $job['status']);
            $this->assertSame(5, (int) $job['result']['stats']['metadata_rows_total']);
            $this->assertSame(5, (int) $job['result']['stats']['metadata_rows_applied']);
            $this->assertSame(1, (int) $job['result']['stats']['words_updated']);
            $this->assertSame('Translation 5', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('Translation 2', $step_values[0]);
            $this->assertSame('Translation 4', $step_values[1]);
            $this->assertArrayNotHasKey('metadata_state', $job['result']);

            $snapshot = $job['result']['undo']['updated_post_snapshots']['words'][(string) $word_id] ?? [];
            $this->assertSame(['Original translation'], $snapshot['meta']['word_translation'] ?? null);

            $restore_result = [
                'stats' => array_merge(ll_tools_import_default_stats(), [
                    'metadata_posts_restored' => 0,
                    'metadata_fields_restored' => 0,
                ]),
                'errors' => [],
                'warnings' => [],
            ];
            ll_tools_import_restore_updated_post_snapshots(
                (array) ($job['result']['undo']['updated_post_snapshots'] ?? []),
                $restore_result
            );
            $this->assertSame('Original translation', (string) get_post_meta($word_id, 'word_translation', true));
        } finally {
            if (is_array($job) && !empty($job['token'])) {
                ll_tools_import_job_delete_path(dirname(ll_tools_metadata_update_job_manifest_path((string) $job['token'])));
            }
            @unlink($file_path);
        }
    }
}
