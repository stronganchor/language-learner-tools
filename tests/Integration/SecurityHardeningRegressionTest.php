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
        $this->assertSame('Forbidden', (string) ($response['data'] ?? ''));
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
}
