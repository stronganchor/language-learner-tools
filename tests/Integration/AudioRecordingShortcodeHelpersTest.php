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

    public function test_legacy_wordset_category_catalog_uses_one_set_based_content_query(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Legacy Category Catalog', 'legacy-category-catalog');
        $other_wordset_id = $this->ensure_term('wordset', 'Other Legacy Category Catalog', 'other-legacy-category-catalog');
        $published_word_category_id = $this->ensure_term('word-category', 'Legacy Published Word Category', 'legacy-published-word-category');
        $private_word_category_id = $this->ensure_term('word-category', 'Legacy Private Word Category', 'legacy-private-word-category');
        $draft_image_category_id = $this->ensure_term('word-category', 'Legacy Draft Image Category', 'legacy-draft-image-category');
        $trashed_word_category_id = $this->ensure_term('word-category', 'Legacy Trashed Word Category', 'legacy-trashed-word-category');
        $trashed_image_category_id = $this->ensure_term('word-category', 'Legacy Trashed Image Category', 'legacy-trashed-image-category');
        $other_wordset_category_id = $this->ensure_term('word-category', 'Other Wordset Category', 'other-wordset-category');
        foreach ([
            $published_word_category_id,
            $private_word_category_id,
            $draft_image_category_id,
            $trashed_word_category_id,
            $trashed_image_category_id,
            $other_wordset_category_id,
        ] as $category_id) {
            delete_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY);
        }

        $published_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Legacy Published Catalog Word',
        ]);
        wp_set_post_terms($published_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($published_word_id, [$published_word_category_id], 'word-category', false);

        $private_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'private',
            'post_title' => 'Legacy Private Catalog Word',
        ]);
        wp_set_post_terms($private_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($private_word_id, [$private_word_category_id], 'word-category', false);

        $trashed_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'trash',
            'post_title' => 'Legacy Trashed Catalog Word',
        ]);
        wp_set_post_terms($trashed_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($trashed_word_id, [$trashed_word_category_id], 'word-category', false);

        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Other Legacy Catalog Word',
        ]);
        wp_set_post_terms($other_word_id, [$other_wordset_id], 'wordset', false);
        wp_set_post_terms($other_word_id, [$other_wordset_category_id], 'word-category', false);

        $draft_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'draft',
            'post_title' => 'Legacy Draft Catalog Image',
        ]);
        update_post_meta($draft_image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_id);
        wp_set_post_terms($draft_image_id, [$draft_image_category_id], 'word-category', false);

        $trashed_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'trash',
            'post_title' => 'Legacy Trashed Catalog Image',
        ]);
        update_post_meta($trashed_image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_id);
        wp_set_post_terms($trashed_image_id, [$trashed_image_category_id], 'word-category', false);

        $other_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Other Legacy Catalog Image',
        ]);
        update_post_meta($other_image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $other_wordset_id);
        wp_set_post_terms($other_image_id, [$other_wordset_category_id], 'word-category', false);

        $unbounded_post_queries = [];
        $set_based_queries = [];
        $capture_posts = static function (WP_Query $query) use (&$unbounded_post_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? $post_type : [$post_type];
            if (
                array_intersect(['words', 'word_images'], array_map('strval', $post_types))
                && (int) $query->get('posts_per_page') === -1
                && empty($query->get('post__in'))
            ) {
                $unbounded_post_queries[] = $query->query_vars;
            }
        };
        $capture_sql = static function (string $sql) use (&$set_based_queries): string {
            if (strpos($sql, 'll_tools_recorder_legacy_category_ids') !== false) {
                $set_based_queries[] = $sql;
            }

            return $sql;
        };
        add_action('pre_get_posts', $capture_posts);
        add_filter('query', $capture_sql);
        try {
            $terms = ll_tools_recorder_get_category_terms_for_wordsets([$wordset_id], get_current_user_id());
        } finally {
            remove_filter('query', $capture_sql);
            remove_action('pre_get_posts', $capture_posts);
        }

        $actual_ids = array_map('intval', wp_list_pluck($terms, 'term_id'));
        sort($actual_ids, SORT_NUMERIC);
        $expected_ids = [$published_word_category_id, $private_word_category_id, $draft_image_category_id];
        sort($expected_ids, SORT_NUMERIC);

        $this->assertSame($expected_ids, $actual_ids);
        $this->assertSame([], $unbounded_post_queries, 'Legacy category discovery must not hydrate every word or image ID.');
        $this->assertCount(1, $set_based_queries);
        $this->assertStringContainsString('UNION', $set_based_queries[0]);
        $this->assertStringContainsString('scoped_words', $set_based_queries[0]);
        $this->assertStringContainsString('scoped_images', $set_based_queries[0]);
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

    public function test_recorder_queue_cursor_tokens_are_signed_and_request_bound(): void
    {
        $viewer_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($viewer_id);
        $context = ll_tools_recorder_queue_cursor_context(
            'signed-recorder-category',
            [91],
            'isolation',
            '',
            2,
            40,
            71,
            $viewer_id
        );
        $state = [
            'schema' => 1,
            'stage' => 'candidate',
            'page_delivered' => 3,
            'candidate_cursor' => ['page_matches' => [['type' => 'word', 'id' => 123]]],
        ];

        $token = ll_tools_recorder_encode_queue_cursor_token($state, $context);

        $this->assertNotSame('', $token);
        $this->assertSame($state, ll_tools_recorder_decode_queue_cursor_token($token, $context));
        $this->assertSame([], ll_tools_recorder_decode_queue_cursor_token($token . 'x', $context));

        $other_page_context = $context;
        $other_page_context['page'] = 3;
        $this->assertSame([], ll_tools_recorder_decode_queue_cursor_token($token, $other_page_context));

        $other_viewer_context = $context;
        $other_viewer_context['viewer_user_id'] = $viewer_id + 1;
        $this->assertSame([], ll_tools_recorder_decode_queue_cursor_token($token, $other_viewer_context));

        $large_state = $state;
        $large_state['page_items'] = [];
        for ($index = 1; $index <= 40; $index++) {
            $large_state['page_items'][] = [
                'word_id' => 1000 + $index,
                'title' => str_repeat('Cumulative recorder row ' . $index . ' ', 10),
                'missing_types' => ['isolation', 'question', 'introduction'],
                'recording_prompts' => ['isolation' => str_repeat('Prompt ', 20)],
            ];
        }
        $large_token = ll_tools_recorder_encode_queue_cursor_token($large_state, $context);
        $this->assertNotSame('', $large_token);
        $this->assertLessThanOrEqual(20000, strlen($large_token));
        $this->assertSame($large_state, ll_tools_recorder_decode_queue_cursor_token($large_token, $context));

        $oversized_state = $state;
        $oversized_chunks = [];
        for ($index = 0; $index < 1100; $index++) {
            $oversized_chunks[] = hash('sha256', 'oversized-recorder-cursor-' . $index);
        }
        $oversized_state['page_items'] = [['title' => implode('', $oversized_chunks)]];
        $this->assertSame('', ll_tools_recorder_encode_queue_cursor_token($oversized_state, $context));
    }

    public function test_recorder_queue_cursor_decoder_rejects_noncanonical_base64url_aliases(): void
    {
        $this->assertSame("\0", ll_tools_recorder_queue_cursor_base64url_decode('AA'));
        $this->assertSame('', ll_tools_recorder_queue_cursor_base64url_decode('AB'));
    }

    public function test_queue_response_never_advertises_more_without_a_signed_cursor(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Cursor Encoding Failure Queue', 'cursor-encoding-failure-queue');
        $category_id = $this->ensure_term('word-category', 'Cursor Encoding Failure Category', 'cursor-encoding-failure-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Cursor Encoding Failure ' . $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        }

        $force_token_failure = static function (): int {
            return 1;
        };
        add_filter('ll_tools_recorder_queue_cursor_max_body_bytes', $force_token_failure);
        try {
            $page = ll_tools_get_recording_category_queue_page(
                'cursor-encoding-failure-category',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                ['viewer_user_id' => $recorder_id, 'incremental' => true]
            );
        } finally {
            remove_filter('ll_tools_recorder_queue_cursor_max_body_bytes', $force_token_failure);
        }

        $this->assertCount(2, (array) ($page['items'] ?? []));
        $this->assertFalse((bool) ($page['pagination']['has_more'] ?? true));
        $this->assertFalse((bool) ($page['pagination']['is_continuation'] ?? true));
        $this->assertSame(0, (int) ($page['pagination']['next_page'] ?? -1));
        $this->assertSame('', (string) ($page['pagination']['cursor_token'] ?? 'missing'));
        $this->assertTrue((bool) ($page['pagination']['continuation_unavailable'] ?? false));
        $this->assertTrue((bool) ($page['pagination']['count_is_lower_bound'] ?? false));
    }

    public function test_signed_candidate_cursor_resumes_sparse_pages_without_repeating_items(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Sparse Signed Queue', 'sparse-signed-queue');
        $category_id = $this->ensure_term('word-category', 'Sparse Signed Category', 'sparse-signed-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);
        $hidden_entries = [];
        for ($index = 1; $index <= 43; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('A Hidden sparse word %02d', $index),
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            $hidden_entries['word:' . $word_id] = [
                'key' => 'word:' . $word_id,
                'word_id' => $word_id,
                'title' => get_the_title($word_id),
                'category_slug' => 'sparse-signed-category',
                'hidden_at' => current_time('mysql'),
            ];
        }
        ll_tools_save_hidden_recording_words($recorder_id, $hidden_entries);

        $visible_ids = [];
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Z Visible sparse ' . $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            $visible_ids[] = (int) $word_id;
        }

        $query_budget = static function (): int {
            return 1;
        };
        $chunk_size = static function (): int {
            return 40;
        };
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_query_budget', $query_budget);
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);

        try {
            $first = ll_tools_get_recording_category_queue_page(
                'sparse-signed-category',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                ['viewer_user_id' => $recorder_id, 'incremental' => true]
            );
            $first_token = (string) ($first['pagination']['cursor_token'] ?? '');
            $second = ll_tools_get_recording_category_queue_page(
                'sparse-signed-category',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                [
                    'viewer_user_id' => $recorder_id,
                    'incremental' => true,
                    'cursor_token' => $first_token,
                ]
            );
            $second_token = (string) ($second['pagination']['cursor_token'] ?? '');
            $third = ll_tools_get_recording_category_queue_page(
                'sparse-signed-category',
                [$wordset_id],
                '',
                '',
                2,
                2,
                $recorder_id,
                [
                    'viewer_user_id' => $recorder_id,
                    'incremental' => true,
                    'cursor_token' => $second_token,
                ]
            );
            $third_token = (string) ($third['pagination']['cursor_token'] ?? '');
            $fourth = ll_tools_get_recording_category_queue_page(
                'sparse-signed-category',
                [$wordset_id],
                '',
                '',
                2,
                2,
                $recorder_id,
                [
                    'viewer_user_id' => $recorder_id,
                    'incremental' => true,
                    'cursor_token' => $third_token,
                ]
            );
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_query_budget', $query_budget);
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        }

        $this->assertSame([], (array) ($first['items'] ?? []));
        $this->assertTrue((bool) ($first['pagination']['candidate_truncated'] ?? false));
        $this->assertSame(1, (int) ($first['pagination']['next_page'] ?? 0));
        $this->assertNotSame('', $first_token);

        $this->assertSame(2, (int) ($second['pagination']['next_page'] ?? 0));
        $this->assertNotSame('', $second_token);
        $this->assertNotSame('', $third_token);
        $this->assertFalse((bool) ($fourth['pagination']['has_more'] ?? true));

        $returned_ids = [];
        foreach ([$second, $third, $fourth] as $response) {
            foreach ((array) ($response['items'] ?? []) as $item) {
                $returned_ids[] = (int) ($item['word_id'] ?? 0);
            }
        }
        $this->assertSame($visible_ids, $returned_ids);
        $this->assertCount(count($visible_ids), array_unique($returned_ids));
    }

    public function test_invalid_queue_cursor_rebases_to_the_current_first_page(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Cursor Rebase Queue', 'cursor-rebase-queue');
        $category_id = $this->ensure_term('word-category', 'Cursor Rebase Category', 'cursor-rebase-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);

        $word_ids = [];
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Cursor Rebase ' . $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            $word_ids[] = (int) $word_id;
        }

        $first = ll_tools_get_recording_category_queue_page(
            'cursor-rebase-category',
            [$wordset_id],
            '',
            '',
            1,
            2,
            $recorder_id,
            ['viewer_user_id' => $recorder_id, 'incremental' => true]
        );
        $token = (string) ($first['pagination']['cursor_token'] ?? '');
        $this->assertNotSame('', $token);

        $hidden = [];
        foreach (array_slice($word_ids, 0, 2) as $word_id) {
            $hidden['word:' . $word_id] = [
                'key' => 'word:' . $word_id,
                'word_id' => $word_id,
                'title' => get_the_title($word_id),
                'category_slug' => 'cursor-rebase-category',
                'hidden_at' => current_time('mysql'),
            ];
        }
        ll_tools_save_hidden_recording_words($recorder_id, $hidden);
        $tampered_token = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');

        $rebased = ll_tools_get_recording_category_queue_page(
            'cursor-rebase-category',
            [$wordset_id],
            '',
            '',
            2,
            2,
            $recorder_id,
            [
                'viewer_user_id' => $recorder_id,
                'incremental' => true,
                'cursor_token' => $tampered_token,
            ]
        );

        $this->assertTrue((bool) ($rebased['pagination']['cursor_rebased'] ?? false));
        $this->assertTrue((bool) ($rebased['pagination']['reset_queue'] ?? false));
        $this->assertSame(1, (int) ($rebased['pagination']['page'] ?? 0));
        $this->assertSame([$word_ids[2]], array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, (array) ($rebased['items'] ?? [])));
    }

    public function test_referenced_candidate_words_return_the_effective_isolated_image_map(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Known Image Map', 'recorder-known-image-map');
        $category_id = $this->ensure_term('word-category', 'Recorder Known Image Category', 'recorder-known-image-category');
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $source_image = $this->create_word_image_for_recording('Recorder Known Source Image', $category_id);
        $copy_image = $this->create_word_image_for_recording('Recorder Known Copy Image', $category_id);
        ll_tools_set_word_image_wordset_owner(
            (int) $copy_image['image_id'],
            $wordset_id,
            (int) $source_image['image_id']
        );

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Known Image Word',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', (int) $source_image['image_id']);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $word_id_by_image = [];
        $image_ids = ll_tools_recorder_get_referenced_word_image_ids_for_wordset_scope(
            [$wordset_id],
            'recorder-known-image-category',
            $category_id,
            [$word_id],
            $word_id_by_image
        );

        $this->assertSame([(int) $copy_image['image_id']], $image_ids);
        $this->assertSame(
            $word_id,
            (int) ($word_id_by_image[(int) $copy_image['image_id']] ?? 0),
            'The bounded word lookup should preserve the isolation-aware image-to-word relationship.'
        );
    }

    public function test_candidate_word_batches_do_not_repeat_reverse_image_scans_per_category(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Candidate Batch Map', 'recorder-candidate-batch-map');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $category_fixtures = [];

        for ($category_index = 1; $category_index <= 3; $category_index++) {
            $category_slug = sprintf('recorder-candidate-batch-%d', $category_index);
            $category_id = $this->ensure_term(
                'word-category',
                sprintf('Recorder Candidate Batch %d', $category_index),
                $category_slug
            );
            ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
            update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

            $word_ids = [];
            $expected_word_id_by_image = [];
            for ($word_index = 1; $word_index <= 3; $word_index++) {
                $image = $this->create_word_image_for_recording(
                    sprintf('Recorder Candidate Batch %d Image %d', $category_index, $word_index),
                    $category_id
                );
                $word_id = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => sprintf('Recorder Candidate Batch %d Word %d', $category_index, $word_index),
                ]);
                update_post_meta($word_id, '_ll_autopicked_image_id', (int) $image['image_id']);
                wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
                wp_set_post_terms($word_id, [$category_id], 'word-category', false);
                $word_ids[] = (int) $word_id;
                $expected_word_id_by_image[(int) $image['image_id']] = (int) $word_id;
            }

            $category_fixtures[] = [
                'slug' => $category_slug,
                'word_ids' => $word_ids,
                'expected_word_id_by_image' => $expected_word_id_by_image,
            ];
        }

        $reverse_scan_queries = [];
        $query_watcher = static function (string $query) use (&$reverse_scan_queries): string {
            if (
                strpos($query, 'CAST(candidate_meta.meta_value AS UNSIGNED) AS lookup_id') !== false
                || strpos($query, 'CAST(linked_thumbnail.meta_value AS UNSIGNED) AS lookup_id') !== false
            ) {
                $reverse_scan_queries[] = $query;
            }

            return $query;
        };

        add_filter('query', $query_watcher);
        try {
            foreach ($category_fixtures as $fixture) {
                $items = ll_tools_get_recording_queue_items(
                    (string) $fixture['slug'],
                    [$wordset_id],
                    'isolation',
                    '',
                    true,
                    $admin_id,
                    (array) $fixture['word_ids'],
                    [
                        'candidate_word_ids_limited' => true,
                        'include_prompt_cards' => false,
                    ]
                );
                $actual_word_id_by_image = [];
                foreach ($items as $item) {
                    $actual_word_id_by_image[(int) ($item['id'] ?? 0)] = (int) ($item['word_id'] ?? 0);
                }
                ksort($actual_word_id_by_image, SORT_NUMERIC);
                $expected_word_id_by_image = (array) $fixture['expected_word_id_by_image'];
                ksort($expected_word_id_by_image, SORT_NUMERIC);

                $this->assertSame($expected_word_id_by_image, $actual_word_id_by_image);
            }
        } finally {
            remove_filter('query', $query_watcher);
        }

        $this->assertSame(
            [],
            $reverse_scan_queries,
            'Canonical candidate words should not run three reverse postmeta scans for every category.'
        );
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

    public function test_candidate_image_map_resolves_a_direct_link_even_when_the_image_has_no_attachment(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Recorder Direct Empty Image Map', 'recorder-direct-empty-image-map');
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder Direct Empty Image',
        ]);
        delete_post_meta($image_id, '_thumbnail_id');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder Direct Empty Image Word',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $image_id);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $map = ll_tools_recorder_get_candidate_image_word_map([$image_id], [$wordset_id]);

        $this->assertSame($word_id, (int) ($map[$image_id] ?? 0));
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

    public function test_prompt_card_limit_is_applied_after_audio_eligibility_filtering(): void
    {
        if (
            !defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY')
        ) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        $wordset_id = $this->ensure_term('wordset', 'Recorder Prompt Eligibility', 'recorder-prompt-eligibility');
        $category_id = $this->ensure_term('word-category', 'Recorder Prompt Eligibility Category', 'recorder-prompt-eligibility-category');
        $ineligible_ids = [];
        foreach (['AAA Already Recorded', 'AAB Already Recorded'] as $title) {
            $prompt_card_id = self::factory()->post->create([
                'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, 'https://example.org/already-recorded.mp3');
            $ineligible_ids[] = (int) $prompt_card_id;
        }

        $eligible_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'ZZZ Eligible Prompt',
        ]);
        wp_set_post_terms($eligible_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($eligible_id, [$category_id], 'word-category', false);
        update_post_meta($eligible_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record the eligible prompt.');

        $page = ll_tools_get_prompt_cards_needing_audio_page(
            'recorder-prompt-eligibility-category',
            [$wordset_id],
            '',
            '',
            false,
            0,
            ['limit' => 1]
        );

        $returned_ids = array_map(static function (array $item): int {
            return (int) ($item['prompt_card_id'] ?? 0);
        }, (array) ($page['items'] ?? []));
        $this->assertSame([$eligible_id], $returned_ids);
        $this->assertTrue((bool) ($page['complete'] ?? false));
        $this->assertFalse((bool) ($page['truncated'] ?? true));
        $this->assertFalse((bool) ($page['has_more'] ?? true));
        $this->assertSame([], array_values(array_intersect($ineligible_ids, $returned_ids)));
    }

    public function test_candidate_limited_queue_does_not_hydrate_global_missing_audio_instances(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Missing Candidate Scope', 'recorder-missing-candidate-scope');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);
        update_option('ll_missing_audio_instances', [
            'Global legacy row must stay out' => 101,
        ], false);

        $option_reads = 0;
        $read_guard = static function ($pre_option) use (&$option_reads) {
            $option_reads++;
            return $pre_option;
        };
        add_filter('pre_option_ll_missing_audio_instances', $read_guard, 10, 1);

        try {
            $items = ll_get_images_needing_audio(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                true,
                0,
                [],
                [
                    'candidate_word_ids_limited' => true,
                    'include_prompt_cards' => false,
                ]
            );
        } finally {
            remove_filter('pre_option_ll_missing_audio_instances', $read_guard, 10);
        }

        $this->assertSame(0, $option_reads, 'Candidate-limited hydration must not read the global legacy option.');
        $this->assertSame([], array_values((array) $items));
    }

    public function test_legacy_missing_audio_pages_cap_results_and_do_not_repeat_numbered_rows(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Legacy Missing Pages', 'recorder-legacy-missing-pages');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);

        $instances = [];
        for ($index = 1; $index <= 9; $index++) {
            $instances[sprintf('Legacy missing row %02d', $index)] = 1000 + $index;
        }
        update_option('ll_missing_audio_instances', $instances, false);

        $pages = [];
        foreach ([0, 4, 8] as $offset) {
            $pages[] = ll_tools_get_legacy_missing_audio_instances_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                false,
                0,
                [
                    'limit' => 4,
                    'offset' => $offset,
                ]
            );
        }

        $page_titles = array_map(static function (array $page): array {
            return array_values(array_map(static function (array $item): string {
                return (string) ($item['title'] ?? '');
            }, (array) ($page['items'] ?? [])));
        }, $pages);
        $all_titles = array_merge(...$page_titles);

        $this->assertSame([4, 4, 1], array_map('count', $page_titles));
        $this->assertCount(9, $all_titles);
        $this->assertCount(9, array_unique($all_titles), 'Eligible offsets must not repeat legacy rows across numbered pages.');
        $this->assertTrue((bool) ($pages[0]['has_more'] ?? false));
        $this->assertTrue((bool) ($pages[1]['has_more'] ?? false));
        $this->assertFalse((bool) ($pages[2]['has_more'] ?? true));
        $this->assertTrue((bool) ($pages[2]['complete'] ?? false));
        $this->assertLessThanOrEqual(100, count((array) ($pages[0]['items'] ?? [])));
    }

    public function test_legacy_missing_audio_cursor_resumes_after_the_hard_scan_cap(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Recorder Legacy Missing Resume', 'recorder-legacy-missing-resume');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);

        $instances = [];
        for ($index = 1; $index <= 3; $index++) {
            $title = sprintf('Canonical candidate %02d', $index);
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $instances[$title] = 2000 + $index;
        }
        $instances['Resumed legacy row one'] = 2101;
        $instances['Resumed legacy row two'] = 2102;
        update_option('ll_missing_audio_instances', $instances, false);

        $batch_filter = static function (): int {
            return 3;
        };
        $cap_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_recorder_legacy_missing_audio_scan_batch_size', $batch_filter);
        add_filter('ll_tools_recorder_legacy_missing_audio_scan_hard_cap', $cap_filter);

        try {
            $first_scan = ll_tools_get_legacy_missing_audio_instances_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                false,
                0,
                ['limit' => 2]
            );
            $resumed_scan = ll_tools_get_legacy_missing_audio_instances_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                false,
                0,
                [
                    'limit' => 2,
                    'cursor' => (array) ($first_scan['cursor'] ?? []),
                ]
            );
        } finally {
            remove_filter('ll_tools_recorder_legacy_missing_audio_scan_batch_size', $batch_filter);
            remove_filter('ll_tools_recorder_legacy_missing_audio_scan_hard_cap', $cap_filter);
        }

        $this->assertSame([], (array) ($first_scan['items'] ?? []));
        $this->assertFalse((bool) ($first_scan['complete'] ?? true));
        $this->assertTrue((bool) ($first_scan['truncated'] ?? false));
        $this->assertTrue((bool) ($first_scan['has_more'] ?? false));
        $this->assertSame(3, (int) ($first_scan['raw_scanned'] ?? 0));
        $this->assertSame(3, (int) ($first_scan['cursor']['raw_offset'] ?? 0));

        $resumed_titles = array_values(array_map(static function (array $item): string {
            return (string) ($item['title'] ?? '');
        }, (array) ($resumed_scan['items'] ?? [])));
        $this->assertSame(['Resumed legacy row one', 'Resumed legacy row two'], $resumed_titles);
        $this->assertTrue((bool) ($resumed_scan['complete'] ?? false));
        $this->assertFalse((bool) ($resumed_scan['truncated'] ?? true));
        $this->assertFalse((bool) ($resumed_scan['has_more'] ?? true));
        $this->assertSame(2, (int) ($resumed_scan['raw_scanned'] ?? 0));
        $this->assertSame(5, (int) ($resumed_scan['cursor']['raw_offset'] ?? 0));
    }

    public function test_server_legacy_continuation_keeps_items_already_rendered_on_the_page(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Recorder Legacy Cumulative', 'recorder-legacy-cumulative');
        $isolation_id = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);
        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);

        $completed_titles = [];
        for ($index = 1; $index <= 3; $index++) {
            $title = sprintf('A Completed legacy word %02d', $index);
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $audio_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_author' => $recorder_id,
                'post_title' => 'Completed legacy audio ' . $index,
            ]);
            wp_set_object_terms($audio_id, [$isolation_id], 'recording_type', false);
            $completed_titles[$title] = 4000 + $index;
        }

        $candidate_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Z Canonical cumulative candidate',
        ]);
        wp_set_post_terms($candidate_id, [$wordset_id], 'wordset', false);
        update_option('ll_missing_audio_instances', $completed_titles + [
            'Z Resumed legacy item' => 4999,
        ], false);

        $batch_filter = static function (): int {
            return 3;
        };
        $cap_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_recorder_legacy_missing_audio_scan_batch_size', $batch_filter);
        add_filter('ll_tools_recorder_legacy_missing_audio_scan_hard_cap', $cap_filter);

        try {
            $first = ll_tools_get_recording_category_queue_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                ['viewer_user_id' => $recorder_id]
            );
            $second = ll_tools_get_recording_category_queue_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                [
                    'viewer_user_id' => $recorder_id,
                    'cursor_token' => (string) ($first['pagination']['cursor_token'] ?? ''),
                ]
            );
        } finally {
            remove_filter('ll_tools_recorder_legacy_missing_audio_scan_batch_size', $batch_filter);
            remove_filter('ll_tools_recorder_legacy_missing_audio_scan_hard_cap', $cap_filter);
        }

        $this->assertSame([$candidate_id], array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, (array) ($first['items'] ?? [])));
        $this->assertTrue((bool) ($first['pagination']['missing_audio_truncated'] ?? false));
        $this->assertSame(1, (int) ($first['pagination']['next_page'] ?? 0));
        $this->assertNotSame('', (string) ($first['pagination']['cursor_token'] ?? ''));
        $this->assertSame(
            ['Z Canonical cumulative candidate', 'Z Resumed legacy item'],
            array_map(static function (array $item): string {
                return (string) ($item['title'] ?? '');
            }, (array) ($second['items'] ?? []))
        );
    }

    public function test_server_prompt_continuation_keeps_items_already_rendered_on_the_page(): void
    {
        if (
            !defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY')
        ) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Recorder Prompt Cumulative', 'recorder-prompt-cumulative');
        $category_id = $this->ensure_term('word-category', 'Recorder Prompt Cumulative Category', 'recorder-prompt-cumulative-category');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);

        $candidate_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Canonical prompt cumulative candidate',
        ]);
        wp_set_post_terms($candidate_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($candidate_id, [$category_id], 'word-category', false);

        for ($index = 1; $index <= 20; $index++) {
            $prompt_card_id = self::factory()->post->create([
                'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf('A Recorded cumulative prompt %02d', $index),
            ]);
            wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Already recorded prompt ' . $index);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, 'https://example.test/prompt-' . $index . '.mp3');
        }
        $eligible_prompt_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Z Resumed cumulative prompt',
        ]);
        wp_set_post_terms($eligible_prompt_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($eligible_prompt_id, [$category_id], 'word-category', false);
        update_post_meta($eligible_prompt_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Z Resumed cumulative prompt');

        $batch_filter = static function (): int {
            return 20;
        };
        $cap_filter = static function (): int {
            return 20;
        };
        add_filter('ll_tools_recorder_prompt_scan_batch_size', $batch_filter);
        add_filter('ll_tools_recorder_prompt_scan_hard_cap', $cap_filter);

        try {
            $first = ll_tools_get_recording_category_queue_page(
                'recorder-prompt-cumulative-category',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                ['viewer_user_id' => $recorder_id]
            );
            $second = ll_tools_get_recording_category_queue_page(
                'recorder-prompt-cumulative-category',
                [$wordset_id],
                '',
                '',
                1,
                2,
                $recorder_id,
                [
                    'viewer_user_id' => $recorder_id,
                    'cursor_token' => (string) ($first['pagination']['cursor_token'] ?? ''),
                ]
            );
        } finally {
            remove_filter('ll_tools_recorder_prompt_scan_batch_size', $batch_filter);
            remove_filter('ll_tools_recorder_prompt_scan_hard_cap', $cap_filter);
        }

        $this->assertSame([$candidate_id], array_map(static function (array $item): int {
            return (int) ($item['word_id'] ?? 0);
        }, (array) ($first['items'] ?? [])));
        $this->assertTrue((bool) ($first['pagination']['prompt_truncated'] ?? false));
        $this->assertNotSame('', (string) ($first['pagination']['cursor_token'] ?? ''));
        $this->assertSame(
            [$candidate_id, $eligible_prompt_id],
            array_map(static function (array $item): int {
                $word_id = (int) ($item['word_id'] ?? 0);
                return $word_id > 0 ? $word_id : (int) ($item['prompt_card_id'] ?? 0);
            }, (array) ($second['items'] ?? []))
        );
    }

    public function test_recording_category_pages_merge_legacy_missing_audio_before_prompt_cards(): void
    {
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        $wordset_id = $this->ensure_term('wordset', 'Recorder Legacy Source Order', 'recorder-legacy-source-order');
        $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);

        $candidate_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Canonical queue candidate',
        ]);
        wp_set_post_terms($candidate_word_id, [$wordset_id], 'wordset', false);
        update_option('ll_missing_audio_instances', [
            'Legacy source first' => 3001,
            'Legacy source second' => 3002,
            'Legacy source third' => 3003,
        ], false);

        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt source last',
        ]);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Prompt source last');

        $pages = [];
        foreach ([1, 2, 3] as $page_number) {
            $pages[] = ll_tools_get_recording_category_queue_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                $page_number,
                2,
                0
            );
        }

        $page_titles = array_map(static function (array $page): array {
            return array_values(array_map(static function (array $item): string {
                return (string) ($item['title'] ?? '');
            }, (array) ($page['items'] ?? [])));
        }, $pages);
        $all_titles = array_merge(...$page_titles);

        $this->assertSame([
            ['Canonical queue candidate', 'Legacy source first'],
            ['Legacy source second', 'Legacy source third'],
            ['Prompt source last'],
        ], $page_titles);
        $this->assertCount(5, array_unique($all_titles), 'The three sources must not repeat rows across numbered pages.');
        $this->assertTrue((bool) ($pages[0]['pagination']['has_more'] ?? false));
        $this->assertTrue((bool) ($pages[1]['pagination']['has_more'] ?? false));
        $this->assertFalse((bool) ($pages[2]['pagination']['has_more'] ?? true));
        $this->assertSame(5, (int) ($pages[2]['pagination']['count'] ?? 0));
        $this->assertFalse((bool) ($pages[2]['pagination']['count_is_lower_bound'] ?? true));
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(2, count((array) ($page['items'] ?? [])));
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
