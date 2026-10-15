<?php
declare(strict_types=1);

/** Opt-in synthetic queue profile in the isolated WordPress test database. */
final class AudioProcessorCountPerformanceTest extends LL_Tools_TestCase
{
    public function test_large_processing_queue_counts_and_query_plans(): void
    {
        if (getenv('LL_TOOLS_AUDIO_PROCESSOR_PROFILE') !== '1') {
            $this->markTestSkipped('Opt-in Audio Processor queue profile.');
        }
        global $wpdb;
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $baseline = ll_audio_processor_get_queue_counts();
        $word_ids = [];
        for ($i = 0; $i < 400; $i++) {
            $word_ids[] = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
        }
        $type = self::factory()->term->create(['taxonomy' => 'recording_type', 'slug' => 'profile-question']);
        $tt_id = (int) get_term($type, 'recording_type')->term_taxonomy_id;
        $report = [];
        for ($i = 0; $i < 2000; $i++) {
            // SQL fixtures avoid two thousand content-change hooks and media
            // side effects. The test transaction owns every inserted row.
            $this->assertNotFalse($wpdb->insert($wpdb->posts, [
                'post_type' => 'word_audio', 'post_status' => 'draft',
                'post_parent' => $word_ids[$i % count($word_ids)], 'post_author' => $admin,
                'post_title' => 'Count profile ' . $i,
                'post_date' => gmdate('Y-m-d H:i:s', 1700000000 + $i),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', 1700000000 + $i),
                'post_content' => '', 'post_excerpt' => '', 'to_ping' => '',
                'pinged' => '', 'post_content_filtered' => '',
            ]));
            $audio_id = (int) $wpdb->insert_id;
            $this->assertNotFalse($wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_ll_needs_audio_processing', '1'), (%d, 'audio_file_path', '/profile.wav')",
                $audio_id, $audio_id
            )));
            $this->assertNotFalse($wpdb->insert($wpdb->term_relationships, ['object_id' => $audio_id, 'term_taxonomy_id' => $tt_id]));
            if (!in_array($i + 1, [200, 2000], true)) { continue; }
            $queries = [];
            $capture = static function (string $sql) use (&$queries): string { $queries[] = $sql; return $sql; };
            $samples = [];
            for ($sample = 0; $sample < 3; $sample++) {
                $started = microtime(true);
                add_filter('query', $capture);
                $counts = ll_audio_processor_get_queue_counts();
                remove_filter('query', $capture);
                $samples[] = round((microtime(true) - $started) * 1000, 3);
                $this->assertSame($baseline['queue'] + min(400, $i + 1), $counts['queue']);
                $this->assertSame($baseline['duplicates'] + max(0, $i + 1 - 400), $counts['duplicates']);
                $this->assertSame($baseline['reprocess'], $counts['reprocess']);
            }
            $report[] = [
                'queued_rows' => $i + 1, 'samples_ms' => $samples, 'counts' => $counts,
                'plans' => array_map(static fn($sql) => $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A), array_slice($queries, 0, 2)),
            ];
        }
        $dir = dirname(__DIR__, 2) . '/test-results/audio-processor-counts';
        wp_mkdir_p($dir);
        file_put_contents($dir . '/profile.json', wp_json_encode(['at' => gmdate('c'), 'report' => $report], JSON_PRETTY_PRINT));
    }
}
