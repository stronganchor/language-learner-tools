<?php
declare(strict_types=1);

final class WordGridRecordingProcessingTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $filesBackup = [];

    /** @var array<int,string> */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->filesBackup = $_FILES;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;

        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function test_lesson_edit_popup_renders_recordings_expanded_with_processing_controls(): void
    {
        $fixture = $this->createWordWithRecording('lesson-popup-processing-render');
        update_post_meta($fixture['recording_id'], LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, $fixture['original_path']);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            $output = do_shortcode('[word_grid category="lesson-popup-processing-render-category" wordset="lesson-popup-processing-render-wordset"]');
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }

        $this->assertStringContainsString('data-ll-word-recordings-toggle aria-expanded="true"', $output);
        $this->assertStringContainsString('data-ll-word-recordings-panel aria-hidden="false"', $output);
        $this->assertStringContainsString('data-ll-process-recording-audio', $output);
        $this->assertStringContainsString('data-ll-processing-waveform', $output);
        $this->assertStringContainsString('data-ll-processing-waveform-canvas', $output);
        $this->assertStringContainsString('data-ll-processing-play-selection', $output);
        $this->assertStringContainsString('data-ll-processing-download-audio', $output);
        $this->assertStringContainsString('class="ll-word-edit-processing-download-audio"', $output);
        $this->assertStringContainsString('href="' . esc_url(site_url($fixture['current_path'])) . '" download', $output);
        $this->assertStringContainsString('aria-label="Download audio"', $output);
        $this->assertStringNotContainsString('data-ll-processing-start', $output);
        $this->assertStringNotContainsString('data-ll-processing-end', $output);
        $this->assertStringContainsString('data-ll-processing-source-audio-url="' . esc_url(site_url($fixture['original_path'])) . '"', $output);
        $this->assertStringContainsString('data-ll-uses-original-audio="1"', $output);
    }

    public function test_review_flagged_transcriptions_hide_for_nonstaff_and_render_editable_for_staff(): void
    {
        $fixture = $this->createWordWithRecording('lesson-popup-review-flags');
        update_post_meta($fixture['recording_id'], 'recording_ipa', 'old.ipa');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($fixture['recording_id'], 'recording_text', 'Check the first sound.');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($fixture['recording_id'], 'recording_ipa', 'Check the first sound.');

        wp_set_current_user(0);
        $public_output = do_shortcode('[word_grid category="lesson-popup-review-flags-category" wordset="lesson-popup-review-flags-wordset"]');

        $this->assertStringNotContainsString('Recording text', $public_output);
        $this->assertStringNotContainsString('old.ipa', $public_output);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            $staff_output = do_shortcode('[word_grid category="lesson-popup-review-flags-category" wordset="lesson-popup-review-flags-wordset"]');
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }

        $this->assertStringContainsString('Recording text', $staff_output);
        $this->assertStringContainsString('old.ipa', $staff_output);
        $this->assertStringContainsString('ll-word-recording-text-main--needs-review', $staff_output);
        $this->assertStringContainsString('ll-word-recording-ipa--needs-review', $staff_output);
        $this->assertStringContainsString('data-ll-recording-review-toggle data-review-field="recording_text"', $staff_output);
        $this->assertStringContainsString('data-ll-recording-review-toggle data-review-field="recording_ipa"', $staff_output);
        $this->assertStringContainsString('Check the first sound.', $staff_output);
    }

    public function test_lesson_grid_shows_sentence_recordings_and_marks_duplicate_manager_recordings(): void
    {
        $fixture = $this->createWordWithRecording('lesson-popup-all-recordings');
        $sentence_type_id = $this->ensureTerm('recording_type', 'In sentence', 'in-sentence');
        $isolation_type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        $sentence_recording_id = $this->createRecording(
            $fixture['word_id'],
            $sentence_type_id,
            'lesson-popup-all-recordings-sentence.wav',
            'Sentence recording text'
        );
        $duplicate_recording_id = $this->createRecording(
            $fixture['word_id'],
            $isolation_type_id,
            'lesson-popup-all-recordings-duplicate.wav',
            'Duplicate isolation text'
        );

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;
        try {
            wp_set_current_user(0);
            $public_output = do_shortcode('[word_grid category="lesson-popup-all-recordings-category" wordset="lesson-popup-all-recordings-wordset"]');

            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin_id);
            $staff_output = do_shortcode('[word_grid category="lesson-popup-all-recordings-category" wordset="lesson-popup-all-recordings-wordset"]');
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }

        $this->assertStringContainsString('data-recording-type="isolation"', $public_output);
        $this->assertStringContainsString('data-recording-id="' . (int) $sentence_recording_id . '"', $public_output);
        $this->assertStringContainsString('Sentence recording text', $public_output);
        $this->assertStringNotContainsString('ll-word-recording-row--secondary', $public_output);

        $this->assertStringContainsString('data-recording-id="' . (int) $fixture['recording_id'] . '"', $staff_output);
        $this->assertStringContainsString('data-recording-id="' . (int) $duplicate_recording_id . '"', $staff_output);
        $this->assertStringContainsString('ll-word-recording-row--secondary', $staff_output);
        $this->assertStringContainsString('ll-word-edit-recording--secondary', $staff_output);
        $this->assertStringContainsString('data-ll-recording-secondary="1"', $staff_output);
        $this->assertStringContainsString('Duplicate: not used as the default practice recording.', $staff_output);
    }

    public function test_lesson_edit_audio_processing_handler_saves_processed_audio_and_keeps_future_original_source(): void
    {
        $fixture = $this->createWordWithRecording('lesson-popup-processing-save');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $upload_path = $this->createTemporaryWavUpload('lesson-popup-processed.wav');
        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'recording_id' => $fixture['recording_id'],
            'wordset_id' => $fixture['wordset_id'],
            'recording_type' => 'isolation',
            'trim_start' => '100',
            'trim_end' => '2000',
            'source_samples' => '3000',
            'sample_rate' => '16000',
            'enable_trim' => '1',
            'enable_noise' => '1',
            'enable_loudness' => '1',
            'used_original_source' => '0',
        ];
        $_REQUEST = $_POST;
        $_FILES = [
            'audio' => [
                'name' => 'lesson-popup-processed.wav',
                'type' => 'audio/wav',
                'tmp_name' => $upload_path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($upload_path),
            ],
        ];

        $upload_filter = static function (): bool {
            return false;
        };
        add_filter('ll_tools_word_grid_require_uploaded_processed_audio_file', $upload_filter);
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_process_recording_audio_handler();
            });
        } finally {
            remove_filter('ll_tools_word_grid_require_uploaded_processed_audio_file', $upload_filter);
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));

        $recording_id = (int) $fixture['recording_id'];
        $next_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
        $this->assertNotSame($fixture['current_path'], $next_path);
        $this->assertSame($fixture['current_path'], (string) get_post_meta($recording_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, true));
        $this->assertSame('', (string) get_post_meta($recording_id, '_ll_needs_audio_processing', true));

        $settings = (array) get_post_meta($recording_id, LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY, true);
        $this->assertSame(100, (int) ($settings['trim_start'] ?? -1));
        $this->assertSame(2000, (int) ($settings['trim_end'] ?? -1));
        $this->assertSame(3000, (int) ($settings['source_samples'] ?? -1));
        $this->assertSame(16000, (int) ($settings['sample_rate'] ?? -1));
        $this->assertSame(0, (int) ($settings['used_original_source'] ?? -1));

        $data = (array) ($response['data'] ?? []);
        $this->assertSame($recording_id, (int) ($data['recording_id'] ?? 0));
        $this->assertSame(site_url($fixture['current_path']), (string) ($data['processing_source_audio_url'] ?? ''));
        $this->assertTrue((bool) ($data['has_original_audio'] ?? false));
        $this->assertTrue((bool) ($data['uses_original_audio'] ?? false));
    }

    /**
     * @return array{wordset_id:int,category_id:int,word_id:int,recording_id:int,current_path:string,original_path:string}
     */
    private function createWordWithRecording(string $prefix): array
    {
        $wordset_id = $this->ensureTerm('wordset', ucwords(str_replace('-', ' ', $prefix)) . ' Wordset', $prefix . '-wordset');
        $category_id = $this->ensureTerm('word-category', ucwords(str_replace('-', ' ', $prefix)) . ' Category', $prefix . '-category');
        $recording_type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        update_term_meta($wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY, '1');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => ucwords(str_replace('-', ' ', $prefix)) . ' Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', 'Translation');

        $current_path = $this->createRelativeWavUpload($prefix . '-current.wav');
        $original_path = $this->createRelativeWavUpload($prefix . '-original.wav');
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => ucwords(str_replace('-', ' ', $prefix)) . ' Recording',
        ]);
        update_post_meta($recording_id, 'audio_file_path', $current_path);
        update_post_meta($recording_id, 'recording_text', 'Recording text');
        wp_set_object_terms($recording_id, [$recording_type_id], 'recording_type', false);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'word_id' => (int) $word_id,
            'recording_id' => (int) $recording_id,
            'current_path' => $current_path,
            'original_path' => $original_path,
        ];
    }

    private function createRecording(int $word_id, int $recording_type_id, string $filename, string $recording_text): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => ucwords(str_replace(['-', '.wav'], [' ', ''], $filename)),
        ]);
        update_post_meta($recording_id, 'audio_file_path', $this->createRelativeWavUpload($filename));
        update_post_meta($recording_id, 'recording_text', $recording_text);
        wp_set_object_terms($recording_id, [$recording_type_id], 'recording_type', false);

        return (int) $recording_id;
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($created);

        return (int) ($created['term_id'] ?? 0);
    }

    private function createRelativeWavUpload(string $filename): string
    {
        $upload = wp_upload_bits($filename, null, $this->buildWavBytes());
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        return str_replace(
            wp_normalize_path(untrailingslashit(ABSPATH)),
            '',
            wp_normalize_path($file_path)
        );
    }

    private function createTemporaryWavUpload(string $filename): string
    {
        $path = trailingslashit(sys_get_temp_dir()) . wp_generate_password(12, false, false) . '-' . sanitize_file_name($filename);
        $written = file_put_contents($path, $this->buildWavBytes());
        $this->assertNotFalse($written);
        $this->assertFileExists($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function buildWavBytes(): string
    {
        $sample_rate = 16000;
        $samples = 1600;
        $data = '';
        for ($i = 0; $i < $samples; $i++) {
            $value = (int) round(sin(2 * M_PI * 440 * ($i / $sample_rate)) * 12000);
            $data .= pack('v', $value < 0 ? $value + 65536 : $value);
        }

        $data_size = strlen($data);
        return 'RIFF'
            . pack('V', 36 + $data_size)
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, $sample_rate, $sample_rate * 2, 2, 16)
            . 'data'
            . pack('V', $data_size)
            . $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
