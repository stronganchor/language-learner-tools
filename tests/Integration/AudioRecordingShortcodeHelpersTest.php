<?php
declare(strict_types=1);

final class AudioRecordingShortcodeHelpersTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    /** @var mixed */
    private $originalIsolationOption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
    }

    protected function tearDown(): void
    {
        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

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

    public function test_recording_category_dropdown_labels_include_ready_item_counts(): void
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
        $counts = ll_tools_get_recording_category_counts_from_items($items);
        $labels = ll_tools_get_recording_category_dropdown_labels($categories, $counts);

        $this->assertSame(1, (int) ($counts['uncategorized'] ?? 0));
        $this->assertSame(2, (int) ($counts['animals'] ?? 0));
        $this->assertSame(1, (int) ($counts['food'] ?? 0));
        $this->assertSame(
            ll_tools_format_recording_category_dropdown_label(__('Uncategorized', 'll-tools-text-domain'), 1),
            (string) ($labels['uncategorized'] ?? '')
        );
        $this->assertSame(
            ll_tools_format_recording_category_dropdown_label('Animals', 2),
            (string) ($labels['animals'] ?? '')
        );
        $this->assertSame(
            ll_tools_format_recording_category_dropdown_label('Food', 1),
            (string) ($labels['food'] ?? '')
        );
    }

    public function test_recording_category_dropdown_labels_leave_unknown_counts_unlabeled(): void
    {
        $labels = ll_tools_get_recording_category_dropdown_labels(
            ['animals' => 'Animals', 'food' => 'Food'],
            ['animals' => 3, 'food' => -1]
        );

        $this->assertSame(ll_tools_format_recording_category_dropdown_label('Animals', 3), $labels['animals']);
        $this->assertSame('Food', $labels['food']);
    }

    public function test_recording_category_dropdown_label_falls_back_when_translation_template_is_malformed(): void
    {
        $broken_template_filter = static function ($translation, $text, $domain) {
            if ($domain === 'll-tools-text-domain' && $text === '%1$s (%2$d)') {
                return '1$s (%2$d)';
            }

            return $translation;
        };

        add_filter('gettext', $broken_template_filter, 10, 3);

        try {
            $this->assertSame(
                'Animals (2)',
                ll_tools_format_recording_category_dropdown_label('Animals', 2)
            );
        } finally {
            remove_filter('gettext', $broken_template_filter, 10);
        }
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

    public function test_recording_items_can_prioritize_requested_start_word(): void
    {
        $items = [
            ['word_id' => 101, 'title' => 'First'],
            ['word_id' => 202, 'title' => 'Second'],
            ['word_id' => 303, 'title' => 'Third'],
        ];

        $prioritized = ll_tools_prioritize_recording_item_by_word_id($items, 202);

        $this->assertSame([202, 101, 303], array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, $prioritized));
    }

    public function test_wordset_recorder_text_visibility_defaults_to_lesson_setting_and_allows_override(): void
    {
        $original_hide_recording_titles = get_option('ll_hide_recording_titles', null);
        $wordset_id = $this->ensure_term('wordset', 'Recorder Text Visibility', 'recorder-text-visibility');

        try {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY);
            update_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', '1');

            $this->assertSame('inherit', ll_tools_get_wordset_recorder_text_visibility($wordset_id));
            $this->assertTrue(ll_tools_wordset_should_hide_recorder_text([$wordset_id]));

            update_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, 'show');
            $this->assertFalse(ll_tools_wordset_should_hide_recorder_text([$wordset_id]));

            update_term_meta($wordset_id, 'll_wordset_hide_lesson_text_for_non_text_quiz', '0');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDER_TEXT_VISIBILITY_META_KEY, 'hide');
            $this->assertTrue(ll_tools_wordset_should_hide_recorder_text([$wordset_id]));

            update_option('ll_hide_recording_titles', 1);
            $this->assertTrue(ll_tools_wordset_should_hide_recorder_text([]));
        } finally {
            if ($original_hide_recording_titles === null) {
                delete_option('ll_hide_recording_titles');
            } else {
                update_option('ll_hide_recording_titles', $original_hide_recording_titles);
            }
        }
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

    public function test_recorder_image_word_lookup_uses_linked_word_image_without_word_thumbnail(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Helper Linked WS', 'rec-helper-linked-ws');
        $category_id = $this->ensure_term('word-category', 'Recorder Helper Linked Category', 'rec-helper-linked-category');
        $attachment_id = $this->create_image_attachment('recorder-helper-linked-image.png');

        $word_image_id = self::factory()->post->create([
            'post_type'   => 'word_images',
            'post_status' => 'publish',
            'post_title'  => 'Recorder Helper Linked Image',
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);
        wp_set_post_terms($word_image_id, [$category_id], 'word-category', false);

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Recorder Helper Linked Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        delete_post_meta($word_id, '_thumbnail_id');

        $resolved_word_id = ll_get_word_for_image_in_wordset((int) $word_image_id, [$wordset_id]);
        $this->assertSame((int) $word_id, $resolved_word_id);

        $existing_word_id = ll_find_or_create_word_for_image((int) $word_image_id, get_post($word_image_id), [$wordset_id]);
        $this->assertSame((int) $word_id, (int) $existing_word_id);
    }

    public function test_recording_wordset_request_scope_can_require_an_explicit_accessible_wordset(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Explicit Recorder Scope', 'explicit-recorder-scope');
        $previous_post = $_POST;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        try {
            $_POST = [];
            $this->assertSame([], ll_tools_get_recording_wordset_ids_from_request(false));

            $_POST = [
                'wordset_ids' => wp_json_encode([$wordset_id]),
                'wordset' => 'ignored-legacy-scope',
            ];
            $this->assertSame([$wordset_id], ll_tools_get_recording_wordset_ids_from_request(false));
        } finally {
            $_POST = $previous_post;
        }
    }

    public function test_recorder_image_request_uses_posted_matching_word_id(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Posted Word WS', 'rec-posted-word-ws');
        $other_wordset_id = $this->ensure_term('wordset', 'Recorder Posted Word Other WS', 'rec-posted-word-other-ws');
        $attachment_id = $this->create_image_attachment('recorder-posted-word-image.png');

        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Posted Word Image',
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);

        $matching_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Posted Matching Word',
        ]);
        set_post_thumbnail($matching_word_id, $attachment_id);
        wp_set_post_terms($matching_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($matching_word_id, '_ll_autopicked_image_id', $word_image_id);

        $out_of_scope_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Posted Out Of Scope Word',
        ]);
        set_post_thumbnail($out_of_scope_word_id, $attachment_id);
        wp_set_post_terms($out_of_scope_word_id, [$other_wordset_id], 'wordset', false);

        $this->assertTrue(ll_tools_recorder_word_matches_image_request((int) $matching_word_id, (int) $word_image_id, [$wordset_id]));
        $this->assertFalse(ll_tools_recorder_word_matches_image_request((int) $out_of_scope_word_id, (int) $word_image_id, [$wordset_id]));

        $resolved_word_id = ll_tools_resolve_recorder_word_for_image_request(
            (int) $word_image_id,
            get_post($word_image_id),
            (int) $matching_word_id,
            [$wordset_id]
        );
        $this->assertSame((int) $matching_word_id, (int) $resolved_word_id);
    }

    public function test_recorder_category_context_rejects_word_ids_outside_requested_wordset(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Context WS', 'rec-context-ws');
        $other_wordset_id = $this->ensure_term('wordset', 'Recorder Context Other WS', 'rec-context-other-ws');
        $category_id = $this->ensure_term('word-category', 'Recorder Context Category', 'rec-context-category');
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);

        $in_scope_uncategorized_word = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Context In Scope',
        ]);
        wp_set_post_terms($in_scope_uncategorized_word, [$wordset_id], 'wordset', false);

        $foreign_uncategorized_word = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Context Foreign',
        ]);
        wp_set_post_terms($foreign_uncategorized_word, [$other_wordset_id], 'wordset', false);

        $foreign_categorized_word = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Context Foreign Categorized',
        ]);
        wp_set_post_terms($foreign_categorized_word, [$other_wordset_id], 'wordset', false);
        wp_set_post_terms($foreign_categorized_word, [$category_id], 'word-category', false);

        $in_scope_context = ll_tools_recorder_resolve_accessible_category_context(
            (int) $in_scope_uncategorized_word,
            0,
            (int) $recorder_id,
            [$wordset_id]
        );

        $this->assertSame('uncategorized', (string) ($in_scope_context['slug'] ?? ''));
        $this->assertSame([], ll_tools_recorder_resolve_accessible_category_context(
            (int) $foreign_uncategorized_word,
            0,
            (int) $recorder_id,
            [$wordset_id]
        ));
        $this->assertSame([], ll_tools_recorder_resolve_accessible_category_context(
            (int) $foreign_categorized_word,
            0,
            (int) $recorder_id,
            [$wordset_id]
        ));
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

    public function test_images_needing_audio_include_prompt_and_user_existing_types(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Prompt Types', 'recorder-prompt-types');
        $type_isolation = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $type_question = $this->ensure_term('recording_type', 'Question', 'question');
        $type_introduction = $this->ensure_term('recording_type', 'Introduction', 'introduction');

        update_option('ll_uncategorized_desired_recording_types', ['isolation', 'question', 'introduction']);

        $current_speaker = self::factory()->user->create(['role' => 'author']);
        $other_speaker = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($current_speaker);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Prompt Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');

        $audio_isolation = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_author' => $current_speaker,
            'post_title' => 'Recorder Prompt Isolation',
        ]);
        wp_set_object_terms($audio_isolation, [$type_isolation], 'recording_type');

        $audio_question = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_author' => $other_speaker,
            'post_title' => 'Recorder Prompt Question',
        ]);
        wp_set_object_terms($audio_question, [$type_question], 'recording_type');

        $images = ll_get_images_needing_audio('', [$wordset_id], '', '');
        $this->assertNotEmpty($images);

        $target = null;
        foreach ($images as $row) {
            if ((int) ($row['word_id'] ?? 0) === (int) $word_id) {
                $target = $row;
                break;
            }
        }

        $this->assertIsArray($target);
        $this->assertSame(['isolation', 'introduction', 'question'], array_values((array) ($target['prompt_types'] ?? [])));
        $this->assertSame(['isolation'], array_values((array) ($target['my_existing_types'] ?? [])));
    }

    public function test_images_needing_audio_can_be_scoped_to_another_users_hidden_queue(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $wordset_id = $this->ensure_term('wordset', 'Recorder Scoped Queue', 'recorder-scoped-queue');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');

        update_option('ll_uncategorized_desired_recording_types', ['isolation']);

        $manager_user_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_user_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($manager_user_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Scoped Queue Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');

        ll_tools_add_hidden_recording_word($recorder_user_id, [
            'word_id' => $word_id,
            'title' => 'Scoped Queue Word',
        ]);

        $visible_items = ll_get_images_needing_audio('', [$wordset_id], '', '', false, $recorder_user_id);
        $hidden_items = ll_get_images_needing_audio('', [$wordset_id], '', '', true, $recorder_user_id);

        $this->assertSame([], $visible_items);
        $this->assertCount(1, $hidden_items);
        $this->assertSame($word_id, (int) ($hidden_items[0]['word_id'] ?? 0));
    }

    public function test_wordset_scoped_recorder_queue_excludes_standalone_legacy_images_and_foreign_categories(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_a = $this->ensure_term('wordset', 'Recorder Scope A', 'recorder-scope-a');
        $wordset_b = $this->ensure_term('wordset', 'Recorder Scope B', 'recorder-scope-b');
        $category_a = $this->ensure_term('word-category', 'Scope A Category', 'scope-a-category');
        $category_b = $this->ensure_term('word-category', 'Quiz 1.1', 'quiz-1-1');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');

        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($category_a, $wordset_a, $category_a);
            ll_tools_set_category_wordset_owner($category_b, $wordset_b, $category_b);
        }
        update_term_meta($category_a, 'll_desired_recording_types', ['isolation']);
        update_term_meta($category_b, 'll_desired_recording_types', ['isolation']);
        update_option('ll_uncategorized_desired_recording_types', ['isolation']);

        $owned_a = $this->create_word_image_for_recording('Owned Scope A Image', $category_a, $wordset_a);
        $owned_b = $this->create_word_image_for_recording('Owned Scope B Image', $category_b, $wordset_b);
        $standalone_legacy = $this->create_word_image_for_recording('Standalone Legacy Image', $category_b, 0);
        $linked_legacy = $this->create_word_image_for_recording('Linked Legacy A Image', $category_a, 0);

        $word_a = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Linked Scope A Word',
        ]);
        set_post_thumbnail($word_a, (int) $linked_legacy['attachment_id']);
        update_post_meta($word_a, '_ll_autopicked_image_id', (int) $linked_legacy['image_id']);
        wp_set_object_terms($word_a, [$category_a], 'word-category', false);
        wp_set_object_terms($word_a, [$wordset_a], 'wordset', false);
        $expected_linked_image_id = function_exists('ll_tools_get_canonical_word_image_post_id_for_word')
            ? (int) ll_tools_get_canonical_word_image_post_id_for_word((int) $word_a, true)
            : (int) $linked_legacy['image_id'];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);

        $admin_items = ll_tools_get_recording_queue_items('', [$wordset_a], '', '', true, $admin_id);
        $admin_image_ids = array_map(static function (array $item): int {
            return (int) ($item['id'] ?? 0);
        }, $admin_items);
        $admin_word_ids = array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, $admin_items);
        $admin_category_slugs = array_values(array_unique(array_map(static function (array $item): string {
            return (string) ($item['category_slug'] ?? '');
        }, $admin_items)));

        $this->assertContains((int) $owned_a['image_id'], $admin_image_ids);
        $this->assertContains($expected_linked_image_id, $admin_image_ids);
        $this->assertContains((int) $word_a, $admin_word_ids);
        $this->assertNotContains((int) $standalone_legacy['image_id'], $admin_image_ids);
        $this->assertNotContains((int) $owned_b['image_id'], $admin_image_ids);
        $this->assertContains('scope-a-category', $admin_category_slugs);
        $this->assertNotContains('quiz-1-1', $admin_category_slugs);
        $this->assertNotContains('uncategorized', $admin_category_slugs);

        $recorder_items = ll_tools_get_recording_queue_items('', [$wordset_a], '', '', true, $recorder_id);
        $recorder_image_ids = array_map(static function (array $item): int {
            return (int) ($item['id'] ?? 0);
        }, $recorder_items);
        $this->assertContains($expected_linked_image_id, $recorder_image_ids);
        $this->assertNotContains((int) $standalone_legacy['image_id'], $recorder_image_ids);
        $this->assertNotContains((int) $owned_b['image_id'], $recorder_image_ids);

        $categories = ll_get_categories_for_wordset([$wordset_a], '', '');
        $this->assertArrayHasKey('scope-a-category', $categories);
        $this->assertArrayNotHasKey('quiz-1-1', $categories);

        $category_items = ll_get_images_needing_audio('scope-a-category', [$wordset_a], '', '', true, $recorder_id);
        $category_word_ids = array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, $category_items);
        $this->assertContains((int) $word_a, $category_word_ids);
    }

    public function test_recording_category_queue_page_uses_bounded_candidate_pages(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Paged Queue', 'recorder-paged-queue');
        $category_id = $this->ensure_term('word-category', 'Recorder Paged Category', 'recorder-paged-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        $word_ids = [];
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Paged Queue ' . $title,
            ]);
            wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_object_terms($word_id, [$category_id], 'word-category', false);
            $word_ids[] = (int) $word_id;
        }

        $page_size_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_recorder_category_switch_page_size', $page_size_filter);

        try {
            $first_page = ll_tools_get_recording_category_queue_page('recorder-paged-category', [$wordset_id], '', '', 1, 0, $recorder_id);
            $second_page = ll_tools_get_recording_category_queue_page('recorder-paged-category', [$wordset_id], '', '', 2, 0, $recorder_id);
        } finally {
            remove_filter('ll_tools_recorder_category_switch_page_size', $page_size_filter);
        }

        $first_ids = array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, (array) ($first_page['items'] ?? []));
        $second_ids = array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, (array) ($second_page['items'] ?? []));

        $this->assertSame(array_slice($word_ids, 0, 2), $first_ids);
        $this->assertSame(array_slice($word_ids, 2, 1), $second_ids);
        $this->assertTrue((bool) ($first_page['pagination']['has_more'] ?? false));
        $this->assertFalse((bool) ($second_page['pagination']['has_more'] ?? true));
        $this->assertSame(2, (int) ($first_page['pagination']['per_page'] ?? 0));
    }

    public function test_candidate_image_queue_does_not_build_a_whole_wordset_reverse_image_map(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Candidate Image Scope', 'recorder-candidate-image-scope');
        $foreign_wordset_id = $this->ensure_term('wordset', 'Recorder Candidate Image Foreign', 'recorder-candidate-image-foreign');
        $category_id = $this->ensure_term('word-category', 'Recorder Candidate Image Category', 'recorder-candidate-image-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $candidate_image = $this->create_word_image_for_recording('Recorder Candidate Image', $category_id);
        $candidate_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Candidate Word',
        ]);
        update_post_meta($candidate_word_id, '_ll_autopicked_image_id', (int) $candidate_image['image_id']);
        delete_post_meta($candidate_word_id, '_thumbnail_id');
        wp_set_post_terms($candidate_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($candidate_word_id, [$category_id], 'word-category', false);

        $foreign_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Candidate Foreign Word',
        ]);
        update_post_meta($foreign_word_id, '_ll_autopicked_image_id', (int) $candidate_image['image_id']);
        wp_set_post_terms($foreign_word_id, [$foreign_wordset_id], 'wordset', false);
        wp_set_post_terms($foreign_word_id, [$category_id], 'word-category', false);

        $unrelated_attachment_id = $this->create_image_attachment('recorder-candidate-unrelated.png');
        $unrelated_word_ids = [];
        for ($index = 1; $index <= 40; $index++) {
            $unrelated_image_id = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => sprintf('Recorder Unrelated Image %02d', $index),
            ]);
            set_post_thumbnail($unrelated_image_id, $unrelated_attachment_id);
            wp_set_post_terms($unrelated_image_id, [$category_id], 'word-category', false);

            $unrelated_word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Recorder Unrelated Word %02d', $index),
            ]);
            set_post_thumbnail($unrelated_word_id, $unrelated_attachment_id);
            update_post_meta($unrelated_word_id, '_ll_autopicked_image_id', $unrelated_image_id);
            wp_set_post_terms($unrelated_word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($unrelated_word_id, [$category_id], 'word-category', false);
            $unrelated_word_ids[] = (int) $unrelated_word_id;
        }

        $unrelated_word_lookup = array_fill_keys($unrelated_word_ids, true);
        $legacy_whole_map_queries = [];
        $unrelated_meta_reads = [];
        $query_watcher = static function (WP_Query $query) use (&$legacy_whole_map_queries): void {
            if (!in_array('words', (array) $query->get('post_type'), true)) {
                return;
            }

            $meta_query_json = wp_json_encode($query->get('meta_query'));
            if (
                (int) $query->get('posts_per_page') === -1
                && is_string($meta_query_json)
                && strpos($meta_query_json, '_thumbnail_id') !== false
                && strpos($meta_query_json, '_ll_autopicked_image_id') !== false
            ) {
                $legacy_whole_map_queries[] = $query->query_vars;
            }
        };
        $meta_watcher = static function ($value, $object_id, $meta_key, $single) use ($unrelated_word_lookup, &$unrelated_meta_reads) {
            if (
                isset($unrelated_word_lookup[(int) $object_id])
                && in_array((string) $meta_key, ['_thumbnail_id', '_ll_autopicked_image_id'], true)
            ) {
                $unrelated_meta_reads[] = (int) $object_id;
            }

            return $value;
        };

        add_action('pre_get_posts', $query_watcher, 10, 1);
        add_filter('get_post_metadata', $meta_watcher, 10, 4);
        try {
            $items = ll_tools_get_recording_queue_items(
                'recorder-candidate-image-category',
                [$wordset_id],
                '',
                '',
                true,
                0,
                [],
                [
                    'candidate_word_ids_limited' => true,
                    'candidate_image_ids' => [(int) $candidate_image['image_id']],
                    'include_prompt_cards' => false,
                ]
            );
        } finally {
            remove_filter('get_post_metadata', $meta_watcher, 10);
            remove_action('pre_get_posts', $query_watcher, 10);
        }

        $this->assertCount(40, $unrelated_word_ids);
        $this->assertSame([], $legacy_whole_map_queries, 'Candidate image queue work must not invoke the legacy unbounded wordset image map.');
        $this->assertSame([], array_values(array_unique($unrelated_meta_reads)), 'Candidate image queue work must not hydrate unrelated image-backed words.');
        $this->assertCount(1, $items);
        $this->assertSame((int) $candidate_image['image_id'], (int) ($items[0]['id'] ?? 0));
        $this->assertSame((int) $candidate_word_id, (int) ($items[0]['word_id'] ?? 0));
        $this->assertNotSame((int) $foreign_word_id, (int) ($items[0]['word_id'] ?? 0));
    }

    public function test_candidate_image_map_resolves_an_isolated_copy_from_the_source_link(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Isolated Candidate Map', 'recorder-isolated-candidate-map');
        $source_attachment_id = $this->create_image_attachment('recorder-isolated-source.png');
        $copy_attachment_id = $this->create_image_attachment('recorder-isolated-copy.png');

        $source_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Isolated Source Image',
        ]);
        set_post_thumbnail($source_image_id, $source_attachment_id);

        $copy_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Isolated Copy Image',
        ]);
        set_post_thumbnail($copy_image_id, $copy_attachment_id);
        ll_tools_set_word_image_wordset_owner($copy_image_id, $wordset_id, $source_image_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Isolated Source Linked Word',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $source_image_id);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $map = ll_tools_recorder_get_candidate_image_word_map([$copy_image_id], [$wordset_id]);

        $this->assertSame($word_id, (int) ($map[$copy_image_id] ?? 0));
        $this->assertSame($copy_image_id, ll_tools_get_effective_word_image_id_for_wordset($source_image_id, $wordset_id));
    }

    public function test_candidate_image_map_resolves_duplicate_image_posts_that_share_an_attachment(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Shared Attachment Map', 'recorder-shared-attachment-map');
        $attachment_id = $this->create_image_attachment('recorder-shared-attachment.png');
        $linked_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Shared Attachment Linked Image',
        ]);
        $candidate_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Shared Attachment Candidate Image',
        ]);
        set_post_thumbnail($linked_image_id, $attachment_id);
        set_post_thumbnail($candidate_image_id, $attachment_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Shared Attachment Linked Word',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $linked_image_id);
        delete_post_meta($word_id, '_thumbnail_id');
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $map = ll_tools_recorder_get_candidate_image_word_map([$candidate_image_id], [$wordset_id]);

        $this->assertSame($word_id, (int) ($map[$candidate_image_id] ?? 0));
    }

    public function test_candidate_image_map_uses_a_valid_thumbnail_word_when_a_newer_word_has_an_explicit_override(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Thumbnail Fallback Map', 'recorder-thumbnail-fallback-map');
        $candidate_attachment_id = $this->create_image_attachment('recorder-thumbnail-candidate.png');
        $override_attachment_id = $this->create_image_attachment('recorder-thumbnail-override.png');
        $candidate_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Thumbnail Candidate Image',
        ]);
        set_post_thumbnail($candidate_image_id, $candidate_attachment_id);

        $valid_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Valid Thumbnail Word',
        ]);
        set_post_thumbnail($valid_word_id, $candidate_attachment_id);
        wp_set_post_terms($valid_word_id, [$wordset_id], 'wordset', false);

        $override_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Explicit Override Image',
        ]);
        set_post_thumbnail($override_image_id, $override_attachment_id);
        $newer_overridden_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Newer Overridden Thumbnail Word',
        ]);
        set_post_thumbnail($newer_overridden_word_id, $candidate_attachment_id);
        update_post_meta($newer_overridden_word_id, '_ll_autopicked_image_id', $override_image_id);
        wp_set_post_terms($newer_overridden_word_id, [$wordset_id], 'wordset', false);
        $this->assertGreaterThan($valid_word_id, $newer_overridden_word_id);

        $map = ll_tools_recorder_get_candidate_image_word_map([$candidate_image_id], [$wordset_id]);

        $this->assertSame($valid_word_id, (int) ($map[$candidate_image_id] ?? 0));
        $this->assertNotSame($newer_overridden_word_id, (int) ($map[$candidate_image_id] ?? 0));
    }

    public function test_candidate_image_map_falls_back_to_the_word_thumbnail_when_the_linked_image_has_no_attachment(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Empty Linked Image Map', 'recorder-empty-linked-image-map');
        $attachment_id = $this->create_image_attachment('recorder-empty-linked-image-candidate.png');
        $candidate_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Empty Link Candidate Image',
        ]);
        set_post_thumbnail($candidate_image_id, $attachment_id);

        $empty_linked_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Empty Linked Image',
        ]);
        delete_post_meta($empty_linked_image_id, '_thumbnail_id');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Empty Link Thumbnail Word',
        ]);
        set_post_thumbnail($word_id, $attachment_id);
        update_post_meta($word_id, '_ll_autopicked_image_id', $empty_linked_image_id);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $map = ll_tools_recorder_get_candidate_image_word_map([$candidate_image_id], [$wordset_id]);

        $this->assertSame($word_id, (int) ($map[$candidate_image_id] ?? 0));
    }

    public function test_recording_category_queue_page_does_not_fetch_prompt_cards_when_word_page_is_full(): void
    {
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        $wordset_id = $this->ensure_term('wordset', 'Recorder Full Word Page', 'recorder-full-word-page');
        $category_id = $this->ensure_term('word-category', 'Recorder Full Word Category', 'recorder-full-word-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        foreach (['Alpha', 'Bravo'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Full Page ' . $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        }

        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Full Page Prompt Card',
        ]);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record this prompt later.');

        $page = ll_tools_get_recording_category_queue_page('recorder-full-word-category', [$wordset_id], '', '', 1, 2, 0);
        $items = (array) ($page['items'] ?? []);

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame(0, (int) ($item['prompt_card_id'] ?? 0));
            $this->assertGreaterThan(0, (int) ($item['word_id'] ?? 0));
        }
    }

    public function test_recorder_category_resolver_remaps_isolated_slug_to_requested_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensure_term('wordset', 'Recorder Resolver One', 'recorder-resolver-one');
        $wordset_two_id = $this->ensure_term('wordset', 'Recorder Resolver Two', 'recorder-resolver-two');
        $shared_category_id = $this->ensure_term('word-category', 'Recorder Resolver Trees', 'recorder-resolver-trees');

        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($shared_category_id, 0, $shared_category_id);
        }

        $scoped_two_id = function_exists('ll_tools_get_or_create_isolated_category_copy')
            ? (int) ll_tools_get_or_create_isolated_category_copy($shared_category_id, $wordset_two_id)
            : 0;
        $scoped_one_id = function_exists('ll_tools_get_or_create_isolated_category_copy')
            ? (int) ll_tools_get_or_create_isolated_category_copy($shared_category_id, $wordset_one_id)
            : 0;
        $this->assertGreaterThan(0, $scoped_two_id);
        $this->assertGreaterThan(0, $scoped_one_id);

        $scoped_two_term = get_term($scoped_two_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $scoped_two_term);

        $resolved = ll_tools_recorder_resolve_category_term_for_wordsets($scoped_two_term->slug, [$wordset_one_id], false);

        $this->assertInstanceOf(WP_Term::class, $resolved);
        $this->assertSame($scoped_one_id, (int) $resolved->term_id);
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

    /**
     * @return array{image_id:int,attachment_id:int}
     */
    private function create_word_image_for_recording(string $title, int $category_id = 0, int $owner_wordset_id = 0): array
    {
        $attachment_id = $this->create_image_attachment(sanitize_title($title) . '.png');
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        set_post_thumbnail($image_id, $attachment_id);
        if ($owner_wordset_id > 0 && function_exists('ll_tools_set_word_image_wordset_owner')) {
            ll_tools_set_word_image_wordset_owner((int) $image_id, $owner_wordset_id, (int) $image_id);
        }
        if ($category_id > 0) {
            wp_set_object_terms($image_id, [$category_id], 'word-category', false);
        }

        return [
            'image_id' => (int) $image_id,
            'attachment_id' => (int) $attachment_id,
        ];
    }
}
