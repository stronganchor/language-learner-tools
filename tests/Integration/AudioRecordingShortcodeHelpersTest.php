<?php
declare(strict_types=1);

final class AudioRecordingShortcodeHelpersTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_recording_categories_helper_prioritizes_uncategorized_and_sorts_rest(): void
    {
        $items = [
            [
                'id' => 11,
                'category_slug' => 'food',
                'category_name' => 'Food',
            ],
            [
                'id' => 12,
                'category_slug' => '',
                'category_name' => '',
            ],
            [
                'id' => 13,
                'category_slug' => 'animals',
                'category_name' => 'Animals',
            ],
            [
                'id' => 14,
                'category_slug' => 'animals',
                'category_name' => 'Animals Duplicate',
            ],
        ];

        $categories = ll_tools_get_recording_categories_from_items($items);

        $this->assertSame(
            ['uncategorized', 'animals', 'food'],
            array_keys($categories)
        );
        $this->assertSame(__('Uncategorized', 'll-tools-text-domain'), (string) ($categories['uncategorized'] ?? ''));
        $this->assertSame('Animals', (string) ($categories['animals'] ?? ''));
        $this->assertSame('Food', (string) ($categories['food'] ?? ''));
    }

    public function test_recording_items_filter_normalizes_uncategorized_slug(): void
    {
        $items = [
            ['id' => 1, 'category_slug' => '', 'category_name' => ''],
            ['id' => 2, 'category_slug' => 'uncategorized', 'category_name' => 'Uncategorized'],
            ['id' => 3, 'category_slug' => 'animals', 'category_name' => 'Animals'],
        ];

        $uncategorized = ll_tools_filter_recording_items_by_category($items, 'uncategorized');
        $uncategorized_ids = array_map(static function ($row): int {
            return (int) ($row['id'] ?? 0);
        }, $uncategorized);

        $this->assertSame([1, 2], $uncategorized_ids);
        $this->assertCount(3, ll_tools_filter_recording_items_by_category($items, ''));
    }

    public function test_get_word_for_image_in_wordset_respects_requested_wordset(): void
    {
        $wordset_one = $this->ensure_term('wordset', 'Recorder Helper WS One', 'rec-helper-ws-one');
        $wordset_two = $this->ensure_term('wordset', 'Recorder Helper WS Two', 'rec-helper-ws-two');

        $attachment_id = $this->create_image_attachment('recorder-helper-wordset.png');

        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Helper Image',
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Helper Word One',
        ]);
        set_post_thumbnail($word_one, $attachment_id);
        wp_set_object_terms($word_one, [$wordset_one], 'wordset');

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Helper Word Two',
        ]);
        set_post_thumbnail($word_two, $attachment_id);
        wp_set_object_terms($word_two, [$wordset_two], 'wordset');

        $resolved_one = ll_get_word_for_image_in_wordset((int) $word_image_id, [$wordset_one]);
        $resolved_two = ll_get_word_for_image_in_wordset((int) $word_image_id, [$wordset_two]);

        $this->assertSame((int) $word_one, $resolved_one);
        $this->assertSame((int) $word_two, $resolved_two);
    }

    public function test_existing_recording_type_helpers_return_unique_types_with_user_scope(): void
    {
        $type_isolation = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $type_question = $this->ensure_term('recording_type', 'Question', 'question');

        $speaker_one = self::factory()->user->create(['role' => 'author']);
        $speaker_two = self::factory()->user->create(['role' => 'author']);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Helper Types Word',
        ]);

        $audio_one = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_author' => $speaker_one,
            'post_title' => 'Recorder Helper Isolation 1',
        ]);
        wp_set_object_terms($audio_one, [$type_isolation], 'recording_type');

        $audio_two = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_author' => $speaker_one,
            'post_title' => 'Recorder Helper Isolation 2',
        ]);
        wp_set_object_terms($audio_two, [$type_isolation], 'recording_type');

        $audio_three = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_author' => $speaker_two,
            'post_title' => 'Recorder Helper Question',
        ]);
        wp_set_object_terms($audio_three, [$type_question], 'recording_type');

        $all_types = ll_get_existing_recording_types_for_word((int) $word_id);
        $speaker_one_types = ll_get_existing_recording_types_for_word_by_user((int) $word_id, (int) $speaker_one);
        $speaker_two_types = ll_get_existing_recording_types_for_word_by_user((int) $word_id, (int) $speaker_two);

        $this->assertSame(['isolation', 'question'], $all_types);
        $this->assertSame(['isolation'], $speaker_one_types);
        $this->assertSame(['question'], $speaker_two_types);
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
