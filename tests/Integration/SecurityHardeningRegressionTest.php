<?php
declare(strict_types=1);

final class SecurityHardeningRegressionTest extends LL_Tools_TestCase
{
    public function test_update_new_word_text_blocks_recorder_from_editing_unrelated_word(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $owner_id = self::factory()->user->create(['role' => 'administrator']);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Locked Word',
            'post_author' => $owner_id,
        ]);

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($recorder_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_upload_recording'),
            'word_id' => $word_id,
            'word_text_target' => 'Changed',
            'word_text_translation' => 'Changed Translation',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_update_new_word_text_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringStartsWith('Forbidden', (string) ($response['data'] ?? ''));
        $this->assertSame('Locked Word', (string) get_post_field('post_title', $word_id));
    }

    public function test_update_new_word_text_allows_authorized_editor(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($editor_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Original Word',
            'post_author' => $editor_id,
        ]);

        wp_set_current_user($editor_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_upload_recording'),
            'word_id' => $word_id,
            'word_text_target' => 'Updated Word',
            'word_text_translation' => 'Updated Translation',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_update_new_word_text_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('Updated Word', (string) get_post_field('post_title', $word_id));
        $this->assertSame('Updated Translation', (string) get_post_meta($word_id, 'word_translation', true));
    }

    public function test_public_word_fetch_redacts_speaker_ids_for_logged_out_requests(): void
    {
        $fixture = $this->create_flashcard_word_with_audio(777);
        wp_set_current_user(0);

        $_POST = [
            'category' => $fixture['category_name'],
            'display_mode' => 'text',
            'option_type' => 'text_translation',
            'prompt_type' => 'audio',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_get_words_by_category_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $row = $this->find_row_by_word_id($rows, $fixture['word_id']);
        $this->assertIsArray($row);
        $this->assertSame(0, (int) ($row['preferred_speaker_user_id'] ?? -1));
        foreach ((array) ($row['audio_files'] ?? []) as $audio_file) {
            $this->assertSame(0, (int) ($audio_file['speaker_user_id'] ?? -1));
        }
    }

    public function test_public_word_fetch_keeps_speaker_ids_for_logged_in_requests(): void
    {
        $fixture = $this->create_flashcard_word_with_audio(888);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $_POST = [
            'category' => $fixture['category_name'],
            'display_mode' => 'text',
            'option_type' => 'text_translation',
            'prompt_type' => 'audio',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_get_words_by_category_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $row = $this->find_row_by_word_id($rows, $fixture['word_id']);
        $this->assertIsArray($row);
        $this->assertArrayHasKey('preferred_speaker_user_id', $row);

        $speaker_ids = [];
        foreach ((array) ($row['audio_files'] ?? []) as $audio_file) {
            $speaker_ids[] = (int) ($audio_file['speaker_user_id'] ?? 0);
        }
        $this->assertContains(888, $speaker_ids);
    }

    public function test_image_upload_validation_accepts_real_png(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.png');
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($png);
        file_put_contents($tmp, $png);

        try {
            $result = ll_image_upload_validate_uploaded_image(
                $tmp,
                'safe-test.png',
                UPLOAD_ERR_OK,
                ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'],
                false
            );
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue((bool) ($result['valid'] ?? false));
        $this->assertSame('image/png', (string) ($result['mime'] ?? ''));
        $this->assertSame('png', (string) ($result['ext'] ?? ''));
    }

    public function test_image_upload_validation_rejects_non_image_file_disguised_as_png(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.png');
        file_put_contents($tmp, "not an image\n");

        try {
            $result = ll_image_upload_validate_uploaded_image(
                $tmp,
                'evil.png',
                UPLOAD_ERR_OK,
                ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'],
                false
            );
        } finally {
            @unlink($tmp);
        }

        $this->assertFalse((bool) ($result['valid'] ?? true));
        $this->assertNotSame('', (string) ($result['error'] ?? ''));
    }

    public function test_recording_upload_validation_rejects_empty_file(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.wav');
        file_put_contents($tmp, '');

        try {
            $result = ll_tools_validate_recording_upload_file([
                'name' => 'empty.wav',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => 0,
            ], false);
        } finally {
            @unlink($tmp);
        }

        $this->assertFalse((bool) ($result['valid'] ?? true));
        $this->assertSame(400, (int) ($result['status'] ?? 0));
        $this->assertNotSame('', (string) ($result['error'] ?? ''));
    }

    public function test_recording_upload_validation_rejects_file_over_max_size(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.wav');
        file_put_contents($tmp, str_repeat('a', 16));

        $max_size_filter = static function (): int {
            return 8;
        };
        add_filter('ll_tools_max_recording_upload_bytes', $max_size_filter);

        try {
            $result = ll_tools_validate_recording_upload_file([
                'name' => 'oversize.wav',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => 16,
            ], false);
        } finally {
            remove_filter('ll_tools_max_recording_upload_bytes', $max_size_filter);
            @unlink($tmp);
        }

        $this->assertFalse((bool) ($result['valid'] ?? true));
        $this->assertSame(413, (int) ($result['status'] ?? 0));
    }

    public function test_recording_upload_validation_rejects_disallowed_extension(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.exe');
        file_put_contents($tmp, "MZ fake payload");

        try {
            $result = ll_tools_validate_recording_upload_file([
                'name' => 'payload.exe',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => 15,
            ], false);
        } finally {
            @unlink($tmp);
        }

        $this->assertFalse((bool) ($result['valid'] ?? true));
        $this->assertSame('', (string) ($result['ext'] ?? ''));
        $this->assertSame(400, (int) ($result['status'] ?? 0));
    }

    public function test_recording_upload_validation_accepts_valid_wav_file(): void
    {
        $tmp = $this->create_temp_file_with_suffix('.wav');
        file_put_contents($tmp, $this->build_silent_wav_bytes());

        try {
            $result = ll_tools_validate_recording_upload_file([
                'name' => 'valid.wav',
                'tmp_name' => $tmp,
                'type' => 'audio/wav',
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($tmp),
            ], false);
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue((bool) ($result['valid'] ?? false));
        $this->assertSame('wav', (string) ($result['ext'] ?? ''));
        $this->assertNotSame('', (string) ($result['mime'] ?? ''));
    }

    public function test_recording_upload_mime_normalization_strips_codec_parameters(): void
    {
        $this->assertSame(
            'audio/webm',
            ll_tools_normalize_recording_upload_mime('audio/webm;codecs=opus')
        );
    }

    public function test_recording_upload_mime_normalization_maps_common_aliases(): void
    {
        $this->assertSame('audio/mpeg', ll_tools_normalize_recording_upload_mime('audio/mp3'));
        $this->assertSame('audio/mp4', ll_tools_normalize_recording_upload_mime('audio/x-m4a'));
    }

    public function test_skip_recording_type_handler_returns_success_payload(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Skip Handler Word',
            'post_author' => $user_id,
        ]);

        $_POST = [
            'nonce' => wp_create_nonce('ll_upload_recording'),
            'word_id' => $word_id,
            'recording_type' => 'isolation',
            'wordset_ids' => wp_json_encode([]),
            'include_types' => '',
            'exclude_types' => '',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_skip_recording_type_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data'] ?? null);
        $this->assertIsArray($response['data']['remaining_types'] ?? null);
    }

    public function test_manage_word_sets_shortcode_uses_ll_tools_handler(): void
    {
        global $shortcode_tags;
        $this->assertIsArray($shortcode_tags);
        $this->assertArrayHasKey('manage_word_sets', $shortcode_tags);
        $this->assertSame('ll_manage_word_sets_shortcode', $shortcode_tags['manage_word_sets']);
    }

    public function test_wordset_manager_menu_trim_uses_role_membership(): void
    {
        ll_create_wordset_manager_role();

        $user_id = self::factory()->user->create(['role' => 'wordset_manager']);
        wp_set_current_user($user_id);

        global $menu, $submenu;
        $original_menu = $menu;
        $original_submenu = $submenu;
        $dashboard_slug = function_exists('ll_tools_get_admin_menu_slug')
            ? ll_tools_get_admin_menu_slug()
            : 'll-tools-dashboard-home';

        $menu = [
            [0 => 'Profile', 2 => 'profile.php'],
            [0 => 'Dashboard', 2 => $dashboard_slug],
            [0 => 'Posts', 2 => 'edit.php'],
        ];
        $submenu = [];

        try {
            customize_admin_menu_for_wordset_manager();
            $remaining = array_values(array_map(static function ($item): string {
                return isset($item[2]) ? (string) $item[2] : '';
            }, (array) $menu));
        } finally {
            $menu = $original_menu;
            $submenu = $original_submenu;
        }

        $this->assertContains('profile.php', $remaining);
        $this->assertContains($dashboard_slug, $remaining);
        $this->assertNotContains('edit.php', $remaining);
    }

    public function test_audio_processor_safe_delete_path_rejects_non_uploads_file(): void
    {
        $base = wp_normalize_path(untrailingslashit(ABSPATH));
        $dir = trailingslashit($base) . 'll-tools-tests-non-uploads';
        wp_mkdir_p($dir);
        $path = trailingslashit($dir) . 'outside-uploads.txt';
        file_put_contents($path, 'test');
        $stored_path = str_replace($base, '', wp_normalize_path($path));

        try {
            $resolved = ll_audio_processor_resolve_safe_delete_path($stored_path);
            $this->assertSame('', $resolved);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_audio_processor_safe_delete_path_accepts_uploads_file(): void
    {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            $this->markTestSkipped('Uploads directory unavailable in test environment.');
        }

        $base = wp_normalize_path(untrailingslashit(ABSPATH));
        $uploads_base = wp_normalize_path((string) $uploads['basedir']);
        if ($uploads_base === '' || strpos(strtolower($uploads_base), strtolower($base . '/')) !== 0) {
            $this->markTestSkipped('Uploads directory is not inside ABSPATH in this test environment.');
        }

        $dir = trailingslashit($uploads['basedir']) . 'll-tools-tests';
        wp_mkdir_p($dir);
        $path = trailingslashit($dir) . 'safe-delete-test.txt';
        file_put_contents($path, 'test');

        $stored_path = str_replace($base, '', wp_normalize_path($path));

        try {
            $resolved = ll_audio_processor_resolve_safe_delete_path($stored_path);
            $this->assertSame(wp_normalize_path($path), $resolved);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_audio_processor_save_handler_requires_upload_files_cap(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_audio_processor'),
        ];
        $_REQUEST = $_POST;
        $_FILES = [];

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_save_processed_audio_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            $_FILES = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringContainsString('Permission denied', (string) ($response['data'] ?? ''));
    }

    public function test_audio_processor_delete_handler_requires_delete_cap_for_recording(): void
    {
        $owner_id = self::factory()->user->create(['role' => 'administrator']);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Audio Processor Delete Guard Word',
            'post_author' => $owner_id,
        ]);
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Audio Processor Delete Guard Recording',
            'post_parent' => $word_id,
            'post_author' => $owner_id,
        ]);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_audio_processor'),
            'post_id' => $audio_id,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_delete_audio_recording_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringContainsString('Insufficient permissions', (string) ($response['data'] ?? ''));
        $this->assertInstanceOf(WP_Post::class, get_post($audio_id));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function find_row_by_word_id(array $rows, int $word_id): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['id'] ?? 0) === $word_id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array{word_id:int, category_name:string}
     */
    private function create_flashcard_word_with_audio(int $speaker_user_id): array
    {
        $category_name = 'Privacy Category';
        $category_id = $this->ensure_term('word-category', $category_name, 'privacy-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Privacy Word',
        ]);
        wp_set_object_terms($word_id, [$category_id], 'word-category');
        update_post_meta($word_id, 'word_translation', 'Privacy Translation');

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Privacy Word Audio',
            'post_parent' => $word_id,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/privacy-word-audio.mp3');
        update_post_meta($audio_id, 'speaker_user_id', $speaker_user_id);
        wp_set_object_terms($audio_id, ['isolation'], 'recording_type');

        return [
            'word_id' => $word_id,
            'category_name' => $category_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function create_temp_file_with_suffix(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'llt');
        $this->assertIsString($base);
        $target = $base . $suffix;
        @unlink($target);
        rename($base, $target);
        return $target;
    }

    private function build_silent_wav_bytes(): string
    {
        $sample_rate = 8000;
        $channels = 1;
        $bits_per_sample = 16;
        $sample_count = 800; // 0.1s of silence at 8kHz
        $block_align = (int) ($channels * ($bits_per_sample / 8));
        $byte_rate = $sample_rate * $block_align;
        $data_size = $sample_count * $block_align;
        $riff_size = 36 + $data_size;

        $header = 'RIFF'
            . pack('V', $riff_size)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)          // PCM fmt chunk size
            . pack('v', 1)           // audio format PCM
            . pack('v', $channels)
            . pack('V', $sample_rate)
            . pack('V', $byte_rate)
            . pack('v', $block_align)
            . pack('v', $bits_per_sample)
            . 'data'
            . pack('V', $data_size);

        return $header . str_repeat("\0", $data_size);
    }
}
