<?php
declare(strict_types=1);

final class AudioCreditGridShortcodeTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['ll_audio_credits_page']);
        parent::tearDown();
    }

    public function test_shortcode_avoids_sql_calc_found_rows_and_paginates_cached_audio_credit_ids(): void
    {
        $alpha = $this->createRecordingFixture('Alpha', true);
        $beta = $this->createRecordingFixture('Beta', true);
        $this->createRecordingFixture('Gamma', false);

        $queries = [];
        $capture_query = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $capture_query, 10, 1);

        try {
            $_GET['ll_audio_credits_page'] = '1';
            $page_one = do_shortcode('[audio_credit_grid posts_per_page="1"]');

            $_GET['ll_audio_credits_page'] = '2';
            $page_two = do_shortcode('[audio_credit_grid posts_per_page="1"]');
        } finally {
            remove_filter('query', $capture_query, 10);
            unset($_GET['ll_audio_credits_page']);
        }

        $this->assertStringContainsString((string) $alpha['word_title'], $page_one);
        $this->assertStringNotContainsString((string) $beta['word_title'], $page_one);
        $this->assertStringContainsString('ll_audio_credits_page=2', $page_one);

        $this->assertStringContainsString((string) $beta['word_title'], $page_two);
        $this->assertStringNotContainsString('Gamma Word', $page_one . $page_two);

        foreach ($queries as $query) {
            $this->assertFalse(
                stripos($query, 'SQL_CALC_FOUND_ROWS') !== false,
                'Audio credit grid should not run SQL_CALC_FOUND_ROWS queries: ' . $query
            );
        }
    }

    public function test_recording_id_cache_invalidates_when_relevant_meta_changes(): void
    {
        $alpha = $this->createRecordingFixture('Alpha Cache', true);
        $beta = $this->createRecordingFixture('Beta Cache', false);

        $initial_ids = ll_tools_get_audio_credit_grid_recording_ids();
        $this->assertSame([$alpha['recording_id']], $initial_ids);

        update_post_meta($beta['recording_id'], 'speaker_name', 'Beta Cache Speaker');

        $after_add_ids = ll_tools_get_audio_credit_grid_recording_ids();
        $this->assertContains($alpha['recording_id'], $after_add_ids);
        $this->assertContains($beta['recording_id'], $after_add_ids);

        delete_post_meta($alpha['recording_id'], 'speaker_name');

        $after_delete_ids = ll_tools_get_audio_credit_grid_recording_ids();
        $this->assertNotContains($alpha['recording_id'], $after_delete_ids);
        $this->assertContains($beta['recording_id'], $after_delete_ids);
    }

    /**
     * @return array{word_id:int, recording_id:int, word_title:string}
     */
    private function createRecordingFixture(string $prefix, bool $with_credit): array
    {
        $word_title = $prefix . ' Word';
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $word_title,
            'post_name' => sanitize_title($word_title),
        ]);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => $prefix . ' Recording',
            'post_name' => sanitize_title($prefix . ' Recording'),
        ]);

        update_post_meta($recording_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($prefix) . '.mp3');
        if ($with_credit) {
            update_post_meta($recording_id, 'speaker_name', $prefix . ' Speaker');
        }

        return [
            'word_id' => $word_id,
            'recording_id' => $recording_id,
            'word_title' => $word_title,
        ];
    }
}
