<?php
declare(strict_types=1);

final class WordGridTranscriptionBatchTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_register_words_post_type')) {
            ll_tools_register_words_post_type();
        }
        if (function_exists('ll_tools_register_word_audio_post_type')) {
            ll_tools_register_word_audio_post_type();
        }
        if (function_exists('ll_tools_register_word_category_taxonomy')) {
            ll_tools_register_word_category_taxonomy();
        }
        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }
        register_taxonomy_for_object_type('word-category', 'words');
        register_taxonomy_for_object_type('wordset', 'words');
    }

    public function test_lesson_recording_candidates_page_by_recording_id_and_filter_existing_text(): void
    {
        [$wordset_id, $category_id, $recording_ids] = $this->createLessonRecordings(60);
        foreach (array_slice($recording_ids, 0, 30) as $recording_id) {
            update_post_meta($recording_id, 'recording_text', 'Existing transcript');
        }

        $after_id = 0;
        $found_ids = [];
        $batch_count = 0;
        do {
            $batch = ll_tools_get_lesson_recording_ids_batch(
                $wordset_id,
                $category_id,
                $after_id,
                0,
                'recording_text',
                'missing'
            );
            $batch_ids = array_values(array_map('intval', (array) ($batch['ids'] ?? [])));
            $this->assertLessThanOrEqual(ll_tools_word_grid_recording_batch_size(), count($batch_ids));
            $found_ids = array_merge($found_ids, $batch_ids);
            $batch_count++;
            $next_after_id = (int) ($batch['next_after_id'] ?? 0);
            if (!empty($batch['has_more'])) {
                $this->assertGreaterThan($after_id, $next_after_id);
            }
            $after_id = $next_after_id;
        } while (!empty($batch['has_more']) && $batch_count < 10);

        $this->assertSame(2, $batch_count, wp_json_encode([
            'found_count' => count($found_ids),
            'batch' => $batch,
        ]));
        $this->assertEquals(array_slice($recording_ids, 30), $found_ids);
    }

    public function test_clear_lesson_transcriptions_uses_bounded_cursor_batches(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        [$wordset_id, $category_id, $recording_ids] = $this->createLessonRecordings(60);
        foreach ($recording_ids as $recording_id) {
            update_post_meta($recording_id, 'recording_text', 'Transcript ' . $recording_id);
            update_post_meta($recording_id, 'recording_translation', 'Translation ' . $recording_id);
        }
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Cursor Transcription Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $after_id = 0;
        $cleared_ids = [];
        $batch_count = 0;
        try {
            do {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_word_grid_edit'),
                    'lesson_id' => $lesson_id,
                    'after_id' => $after_id,
                ];
                $_REQUEST = $_POST;
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_clear_lesson_transcriptions_handler();
                });
                $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
                $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
                $batch_ids = array_values(array_map('intval', (array) ($data['cleared'] ?? [])));
                $this->assertLessThanOrEqual(ll_tools_word_grid_recording_batch_size(), count($batch_ids));
                $cleared_ids = array_merge($cleared_ids, $batch_ids);
                $batch_count++;
                $next_after_id = (int) ($data['next_after_id'] ?? 0);
                if (!empty($data['has_more'])) {
                    $this->assertGreaterThan($after_id, $next_after_id);
                }
                $after_id = $next_after_id;
            } while (!empty($data['has_more']) && $batch_count < 10);
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertSame(3, $batch_count, wp_json_encode([
            'cleared_count' => count($cleared_ids),
            'data' => $data,
        ]));
        $this->assertEquals($recording_ids, $cleared_ids);
        foreach ($recording_ids as $recording_id) {
            $this->assertSame('', (string) get_post_meta($recording_id, 'recording_text', true));
            $this->assertSame('', (string) get_post_meta($recording_id, 'recording_translation', true));
        }
    }

    /**
     * @return array{0:int,1:int,2:int[]}
     */
    private function createLessonRecordings(int $recording_count): array
    {
        $wordset = wp_insert_term('Recording Batch Wordset ' . wp_generate_password(5, false, false), 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term('Recording Batch Category ' . wp_generate_password(5, false, false), 'word-category');
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        $word_ids = [];
        for ($index = 1; $index <= 3; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Recording Batch Word ' . $index,
            ]);
            wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_object_terms($word_id, [$category_id], 'word-category', false);
            $word_ids[] = $word_id;
        }
        $assigned_category_ids = array_values(array_map('intval', wp_get_post_terms(
            $word_ids[0],
            'word-category',
            ['fields' => 'ids']
        )));
        if (!empty($assigned_category_ids)) {
            $category_id = $assigned_category_ids[0];
        }

        $recording_ids = [];
        for ($index = 0; $index < $recording_count; $index++) {
            $recording_ids[] = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_ids[$index % count($word_ids)],
                'post_title' => 'Recording Batch Audio ' . ($index + 1),
            ]);
        }
        sort($recording_ids, SORT_NUMERIC);

        return [$wordset_id, $category_id, $recording_ids];
    }

    /**
     * @return array<string, mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $error) {
            $this->assertSame('wp_die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
