<?php
declare(strict_types=1);

final class WordImagesFixerBatchTest extends LL_Tools_TestCase
{
    public function test_page_render_does_not_scan_words_and_batches_resume_by_id(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $word_ids = [];
        for ($index = 1; $index <= 7; $index++) {
            $attachment_id = self::factory()->post->create([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_title' => 'Fixer Attachment ' . $index,
            ]);
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Fixer Word ' . $index,
            ]);
            update_post_meta($word_id, '_thumbnail_id', $attachment_id);
            $word_ids[] = (int) $word_id;
        }

        $batch_size_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_word_images_fixer_batch_size', $batch_size_filter);

        $scan_limits = [];
        $query_watcher = static function (WP_Query $query) use (&$scan_limits): void {
            if ($query->get('post_type') !== 'words' || $query->get('orderby') !== 'ID') {
                return;
            }
            $meta_query = wp_json_encode($query->get('meta_query'));
            if (strpos((string) $meta_query, '_thumbnail_id') === false) {
                return;
            }
            $scan_limits[] = (int) $query->get('posts_per_page');
        };
        add_action('pre_get_posts', $query_watcher);

        try {
            ob_start();
            ll_render_word_images_fixer_page();
            $initial_html = (string) ob_get_clean();
            $this->assertStringContainsString('ll_fix_images_action', $initial_html);
            $this->assertSame([], $scan_limits, 'A normal page render must not start the legacy scan.');

            update_user_meta($user_id, ll_word_images_fixer_job_meta_key(), [
                'next_cursor' => $word_ids[1],
                'created_total' => 2,
                'failed_total' => 0,
                'has_more' => true,
            ]);
            ob_start();
            ll_render_word_images_fixer_page();
            $resume_html = (string) ob_get_clean();
            $this->assertStringContainsString('value="' . $word_ids[1] . '"', $resume_html);
            $this->assertStringContainsString('Continue', $resume_html);
            $this->assertSame([], $scan_limits, 'Reading saved progress must not scan candidates.');
            delete_user_meta($user_id, ll_word_images_fixer_job_meta_key());

            $first = ll_word_images_fixer_process_batch(0, 0, 0);
            $this->assertTrue((bool) $first['has_more']);
            $this->assertSame(3, (int) $first['scanned']);
            $this->assertSame(3, (int) $first['created_total']);
            $this->assertSame($word_ids[2], (int) $first['next_cursor']);

            $second = ll_word_images_fixer_process_batch((int) $first['next_cursor'], (int) $first['created_total'], 0);
            $this->assertTrue((bool) $second['has_more']);
            $this->assertSame(6, (int) $second['created_total']);
            $this->assertSame($word_ids[5], (int) $second['next_cursor']);

            $third = ll_word_images_fixer_process_batch((int) $second['next_cursor'], (int) $second['created_total'], 0);
            $this->assertFalse((bool) $third['has_more']);
            $this->assertSame(1, (int) $third['scanned']);
            $this->assertSame(7, (int) $third['created_total']);
            $this->assertSame($word_ids[6], (int) $third['next_cursor']);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
            remove_filter('ll_tools_word_images_fixer_batch_size', $batch_size_filter);
        }

        $this->assertSame([4, 4, 4], $scan_limits);
        $this->assertNotContains(-1, $scan_limits);
        foreach ($word_ids as $word_id) {
            $this->assertGreaterThan(0, (int) get_post_meta($word_id, '_ll_autopicked_image_id', true));
        }
    }
}
