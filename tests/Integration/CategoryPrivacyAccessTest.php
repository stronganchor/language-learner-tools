<?php
declare(strict_types=1);

final class CategoryPrivacyAccessTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_private_category_requires_assignment_for_learner_study_access(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset_id = $this->ensure_term('wordset', 'Private Study Wordset', 'private-study-wordset');
            $category_id = $this->create_private_category('Private Study Category', 'private-study-category');
            $word_id = $this->create_word($wordset_id, $category_id, 'Private Study Word', 'Private Study Translation');

            $learner_id = self::factory()->user->create(['role' => 'll_tools_learner']);

            wp_set_current_user($learner_id);
            $hidden_categories = ll_tools_user_study_categories_for_wordset($wordset_id);
            $hidden_words = ll_get_words_by_category('Private Study Category', 'text_title', [$wordset_id], [
                'prompt_type' => 'text_title',
                'option_type' => 'text_title',
            ]);

            $this->assertSame([], $this->category_ids_from_rows($hidden_categories));
            $this->assertSame([], $hidden_words);
            $this->assertFalse(ll_tools_user_can_view_category($category_id, $learner_id));

            update_term_meta($category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$learner_id]);

            $visible_categories = ll_tools_user_study_categories_for_wordset($wordset_id);
            $visible_words = ll_get_words_by_category('Private Study Category', 'text_title', [$wordset_id], [
                'prompt_type' => 'text_title',
                'option_type' => 'text_title',
            ]);

            $this->assertSame([$category_id], $this->category_ids_from_rows($visible_categories));
            $this->assertCount(1, $visible_words);
            $this->assertSame($word_id, (int) ($visible_words[0]['id'] ?? 0));
            $this->assertTrue(ll_tools_user_can_view_category($category_id, $learner_id));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_administrator_can_view_private_category_without_assignment(): void
    {
        $category_id = $this->create_private_category('Admin Visible Category', 'admin-visible-category');
        $admin_id = self::factory()->user->create(['role' => 'administrator']);

        $this->assertTrue(ll_tools_user_can_view_category($category_id, $admin_id));
    }

    public function test_private_category_recorder_queue_and_new_word_flow_require_assignment(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $wordset_id = $this->ensure_term('wordset', 'Recorder Private Wordset', 'recorder-private-wordset');
        $category_id = $this->create_private_category('Recorder Private Category', 'recorder-private-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $this->ensure_term('recording_type', 'Question', 'question');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $attachment_id = $this->create_image_attachment('recorder-private-category.png');
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Private Image',
        ]);
        set_post_thumbnail($image_id, $attachment_id);
        wp_set_object_terms($image_id, [$category_id], 'word-category');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Private Word',
        ]);
        set_post_thumbnail($word_id, $attachment_id);
        wp_set_object_terms($word_id, [$category_id], 'word-category');
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => 'recorder-private-wordset',
            'allow_new_words' => '1',
        ]);
        wp_set_current_user($recorder_id);

        $hidden_items = ll_get_images_needing_audio('recorder-private-category', [$wordset_id], '', '', true);
        $this->assertSame([], $hidden_items);

        $protected_types_before = get_term_meta($category_id, 'll_desired_recording_types', true);

        $_POST = [
            'nonce' => wp_create_nonce('ll_upload_recording'),
            'word_text_target' => 'Blocked Private Word',
            'word_text_translation' => 'Blocked Translation',
            'create_category' => '1',
            'new_category_name' => 'Recorder Private Category',
            'new_category_types' => ['question'],
            'wordset_ids' => wp_json_encode([$wordset_id]),
            'include_types' => '',
            'exclude_types' => '',
        ];
        $_REQUEST = $_POST;

        try {
            $blocked_response = $this->run_json_endpoint(static function (): void {
                ll_prepare_new_word_recording_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($blocked_response['success'] ?? true));
        $this->assertSame('You do not have access to that category.', (string) ($blocked_response['data'] ?? ''));
        $this->assertSame($protected_types_before, get_term_meta($category_id, 'll_desired_recording_types', true));
        $this->assertSame(0, $this->count_words_by_title('Blocked Private Word'));

        update_term_meta($category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$recorder_id]);

        $visible_items = ll_get_images_needing_audio('recorder-private-category', [$wordset_id], '', '', true);
        $this->assertCount(1, $visible_items);
        $this->assertSame($word_id, (int) ($visible_items[0]['word_id'] ?? 0));

        $_POST = [
            'nonce' => wp_create_nonce('ll_upload_recording'),
            'word_text_target' => 'Allowed Private Word',
            'word_text_translation' => 'Allowed Translation',
            'create_category' => '1',
            'new_category_name' => 'Recorder Private Category',
            'new_category_types' => ['question'],
            'wordset_ids' => wp_json_encode([$wordset_id]),
            'include_types' => '',
            'exclude_types' => '',
        ];
        $_REQUEST = $_POST;

        try {
            $allowed_response = $this->run_json_endpoint(static function (): void {
                ll_prepare_new_word_recording_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($allowed_response['success'] ?? false));
        $this->assertCount(1, (array) ($allowed_response['data']['recording_types'] ?? []));
        $this->assertSame('question', (string) ($allowed_response['data']['recording_types'][0]['slug'] ?? ''));
        $this->assertSame($category_id, (int) ($allowed_response['data']['category']['term_id'] ?? 0));
        $this->assertSame(['question'], array_values((array) get_term_meta($category_id, 'll_desired_recording_types', true)));
        $this->assertSame(1, $this->count_words_by_title('Allowed Private Word'));
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
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
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

    private function create_private_category(string $name, string $slug): int
    {
        $category_id = $this->ensure_term('word-category', $name, $slug);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        return $category_id;
    }

    private function create_word(int $wordset_id, int $category_id, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return (int) $word_id;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function category_ids_from_rows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function count_words_by_title(string $title): int
    {
        $posts = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'title' => $title,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        return is_array($posts) ? count($posts) : 0;
    }

    private function create_image_attachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
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
}
