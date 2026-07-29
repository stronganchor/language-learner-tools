<?php
declare(strict_types=1);

final class ContentLessonProgressTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    public function test_completed_lesson_ids_are_normalized_and_can_be_set_idempotently(): void
    {
        $user_id = self::factory()->user->create();
        $lesson_id = $this->createLesson(0, 'Progress lesson');

        $this->assertSame(
            [4, 7, 9],
            ll_tools_normalize_completed_content_lesson_ids('9, 4, 7, 4, nope')
        );
        $this->assertTrue(ll_tools_set_content_lesson_completion($user_id, $lesson_id, true));
        $this->assertTrue(ll_tools_set_content_lesson_completion($user_id, $lesson_id, true));
        $this->assertTrue(ll_tools_user_completed_content_lesson($lesson_id, $user_id));
        $this->assertSame([$lesson_id], ll_tools_get_completed_content_lesson_ids($user_id));

        $this->assertTrue(ll_tools_set_content_lesson_completion($user_id, $lesson_id, false));
        $this->assertFalse(ll_tools_user_completed_content_lesson($lesson_id, $user_id));
        $this->assertSame([], ll_tools_get_completed_content_lesson_ids($user_id));
    }

    public function test_completion_compare_and_swap_preserves_a_concurrent_write(): void
    {
        $user_id = self::factory()->user->create();
        $first_id = $this->createLesson(0, 'First concurrent completion');
        $second_id = $this->createLesson(0, 'Second concurrent completion');
        $third_id = $this->createLesson(0, 'Concurrent request completion');
        update_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            [$first_id]
        );

        $interleaved = false;
        $interleave = static function (
            $check,
            int $object_id,
            string $meta_key
        ) use ($user_id, $first_id, $third_id, &$interleaved) {
            if ($interleaved
                || $object_id !== $user_id
                || $meta_key !== LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META
            ) {
                return $check;
            }

            $interleaved = true;
            update_user_meta(
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                [$first_id, $third_id]
            );
            return $check;
        };
        add_filter('update_user_metadata', $interleave, 10, 3);
        try {
            $this->assertTrue(
                ll_tools_set_content_lesson_completion($user_id, $second_id, true)
            );
        } finally {
            remove_filter('update_user_metadata', $interleave, 10);
        }

        $this->assertTrue($interleaved);
        $this->assertSame(
            [$first_id, $second_id, $third_id],
            ll_tools_get_completed_content_lesson_ids($user_id)
        );
    }

    public function test_completion_mutation_preserves_an_oversized_existing_store(): void
    {
        $user_id = self::factory()->user->create();
        $lesson_id = $this->createLesson(0, 'Oversized completion request');
        $existing_ids = range(100001, 100101);
        update_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            $existing_ids
        );
        $limit = static function (): int {
            return 100;
        };
        add_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        try {
            $complete = true;
            $bounded = ll_tools_normalize_completed_content_lesson_ids(
                $existing_ids,
                $complete
            );
            $written = ll_tools_set_content_lesson_completion(
                $user_id,
                $lesson_id,
                true
            );
        } finally {
            remove_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        }

        $this->assertFalse($complete);
        $this->assertCount(100, $bounded);
        $this->assertFalse($written);
        $this->assertSame(
            $existing_ids,
            get_user_meta(
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                true
            )
        );
    }

    public function test_completion_request_validates_auth_nonce_visibility_and_boolean_readback(): void
    {
        $wordset_id = $this->createWordset('Completion request visibility');
        $published_id = $this->createLesson(
            $wordset_id,
            'Published completion request'
        );
        $draft_id = $this->createLesson(
            $wordset_id,
            'Draft completion request',
            'draft'
        );
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        $anonymous = ll_tools_update_content_lesson_completion_request(
            0,
            $published_id,
            true
        );
        $this->assertWPError($anonymous);
        $this->assertSame(
            'content_lesson_completion_login_required',
            $anonymous->get_error_code()
        );
        $this->assertFalse(
            ll_tools_verify_content_lesson_completion_nonce('not-a-valid-nonce')
        );
        wp_set_current_user($user_id);
        $this->assertTrue(ll_tools_verify_content_lesson_completion_nonce(
            wp_create_nonce('ll_tools_content_lesson_completion')
        ));

        $draft = ll_tools_update_content_lesson_completion_request(
            $user_id,
            $draft_id,
            true
        );
        $this->assertWPError($draft);
        $this->assertSame(
            'content_lesson_completion_lesson_invalid',
            $draft->get_error_code()
        );

        update_term_meta(
            $wordset_id,
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
            'private'
        );
        $private = ll_tools_update_content_lesson_completion_request(
            $user_id,
            $published_id,
            true
        );
        $this->assertWPError($private);
        $this->assertSame(
            'content_lesson_completion_forbidden',
            $private->get_error_code()
        );

        update_term_meta(
            $wordset_id,
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
            'public'
        );
        $saved = ll_tools_update_content_lesson_completion_request(
            $user_id,
            $published_id,
            true
        );
        $this->assertIsArray($saved);
        $this->assertSame($published_id, (int) $saved['lesson_id']);
        $this->assertTrue((bool) $saved['completed']);

        $cleared = ll_tools_update_content_lesson_completion_request(
            $user_id,
            $published_id,
            false
        );
        $this->assertIsArray($cleared);
        $this->assertFalse((bool) $cleared['completed']);
    }

    public function test_prerequisite_status_rows_are_bounded_linked_and_completion_aware(): void
    {
        $wordset_id = $this->createWordset('Progress prerequisites');
        $first_id = $this->createLesson($wordset_id, 'First prerequisite');
        $second_id = $this->createLesson($wordset_id, 'Second prerequisite');
        $lesson_id = $this->createLesson($wordset_id, 'Follow-up lesson');
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$first_id, $second_id]
        );

        $user_id = self::factory()->user->create();
        ll_tools_set_content_lesson_completion($user_id, $first_id, true);

        $rows = ll_tools_get_content_lesson_prerequisite_status_rows($lesson_id, $user_id);
        $this->assertCount(2, $rows);
        $this->assertSame($first_id, (int) $rows[0]['id']);
        $this->assertTrue((bool) $rows[0]['completed']);
        $this->assertFalse((bool) $rows[1]['completed']);
        $this->assertNotSame('', (string) $rows[0]['url']);

        $html = ll_tools_render_content_lesson_prerequisites($lesson_id, $user_id);
        $this->assertStringContainsString('First prerequisite', $html);
        $this->assertStringContainsString('is-completed', $html);
        $this->assertStringContainsString('Before this lesson', $html);

        $dependent_rows = ll_tools_get_content_lesson_dependent_status_rows(
            $first_id,
            $user_id
        );
        $this->assertCount(1, $dependent_rows);
        $this->assertSame($lesson_id, (int) $dependent_rows[0]['id']);
        $this->assertStringContainsString(
            'Continue learning',
            ll_tools_render_content_lesson_dependents($first_id, $user_id)
        );
    }

    public function test_article_kind_save_clears_media_only_fields(): void
    {
        $wordset_id = $this->createWordset('Article lesson');
        $lesson_id = $this->createLesson($wordset_id, 'Article lesson', 'draft');
        $this->setCurrentManager();
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $wordset_id,
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'video',
            'll_content_lesson_media_url' => 'https://example.com/should-not-remain.mp4',
            'll_content_lesson_transcript_format' => 'vtt',
            'll_content_lesson_transcript_source' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHidden",
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertSame('article', ll_tools_get_content_lesson_kind($lesson_id));
        $this->assertTrue(ll_tools_content_lesson_is_article($lesson_id));
        $this->assertSame('', (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, true));
        $this->assertSame('', (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META, true));
        $this->assertSame('', (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CUES_META, true));
        $this->assertSame('', ll_tools_render_content_lesson_completion_control($lesson_id));
    }

    public function test_article_template_renders_body_without_media_stage_or_notes_wrapper(): void
    {
        $wordset_id = $this->createWordset('Article template');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Article template lesson'
        );
        wp_update_post([
            'ID' => $lesson_id,
            'post_content' => '<p>Article template body.</p>',
        ]);
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_KIND_META,
            'article'
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META,
            'video'
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META,
            'https://example.com/article-should-not-render.mp4'
        );

        $this->go_to(
            '/?post_type=ll_content_lesson&p=' . $lesson_id
        );
        $this->assertTrue(is_singular('ll_content_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/content-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'll-content-lesson-page--article',
            $html
        );
        $this->assertStringContainsString(
            '<span class="ll-content-lesson-pill">Article lesson</span>',
            $html
        );
        $this->assertStringContainsString(
            '<article class="ll-content-lesson-article">',
            $html
        );
        $this->assertStringContainsString('Article template body.', $html);
        $this->assertStringContainsString(
            'll-content-lesson-progress-login',
            $html
        );
        $this->assertStringNotContainsString(
            'data-ll-content-lesson-player',
            $html
        );
        $this->assertStringNotContainsString(
            'll-content-lesson-notes__content',
            $html
        );
        $this->assertStringNotContainsString(
            'article-should-not-render.mp4',
            $html
        );
    }

    public function test_wordset_lesson_collection_uses_a_bounded_limit(): void
    {
        $wordset_id = $this->createWordset('Bounded content lessons');
        $first_id = $this->createLesson($wordset_id, 'First bounded lesson');
        $this->createLesson($wordset_id, 'Second bounded lesson');
        $callback = static function (): int {
            return 1;
        };
        add_filter('ll_tools_content_lessons_for_wordset_limit', $callback);
        try {
            $complete = true;
            $lessons = ll_tools_get_content_lessons_for_wordset(
                $wordset_id,
                $complete
            );
        } finally {
            remove_filter('ll_tools_content_lessons_for_wordset_limit', $callback);
        }

        $this->assertCount(1, $lessons);
        $this->assertSame($first_id, (int) ($lessons[0]['id'] ?? 0));
        $this->assertFalse($complete);
    }

    public function test_wordset_lesson_collection_marks_visibility_read_failures_incomplete(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Incomplete lesson visibility');
        $this->createLesson($wordset_id, 'Hidden by incomplete visibility');
        wp_cache_delete($wordset_id, 'term_meta');

        $injected = false;
        $query_filter = static function (string $query) use ($wordset_id, &$injected): string {
            if (
                !$injected
                && stripos($query, 'termmeta') !== false
                && preg_match(
                    '/term_id\s+IN\s*\(\s*' . preg_quote((string) $wordset_id, '/') . '\s*\)/i',
                    $query
                ) === 1
            ) {
                $injected = true;
                return 'SELECT term_id, meta_key, meta_value FROM ll_tools_missing_termmeta_table';
            }

            return $query;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $query_filter);
        try {
            $complete = true;
            $lessons = ll_tools_get_content_lessons_for_wordset(
                $wordset_id,
                $complete
            );
        } finally {
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            wp_cache_delete($wordset_id, 'term_meta');
        }

        $this->assertTrue($injected, 'Expected the wordset visibility metadata query failure to be injected.');
        $this->assertSame([], $lessons);
        $this->assertFalse($complete);
    }

    public function test_content_lesson_completion_is_exported_and_erased_as_personal_data(): void
    {
        $user_id = self::factory()->user->create([
            'user_email' => 'content-lesson-progress@example.test',
        ]);
        $lesson_id = $this->createLesson(0, 'Privacy progress lesson');
        $this->assertTrue(
            ll_tools_set_content_lesson_completion($user_id, $lesson_id, true)
        );
        $legacy_completion_data = [$lesson_id, 9876];
        update_user_meta(
            $user_id,
            'tt_completed_lessons',
            $legacy_completion_data
        );

        $export = ll_tools_privacy_export_study_settings(
            'content-lesson-progress@example.test'
        );
        $groups = [];
        foreach ((array) ($export['data'] ?? []) as $item) {
            $groups[(string) ($item['group_id'] ?? '')] = $item;
        }
        $this->assertArrayHasKey('ll-tools-content-lesson-progress', $groups);
        $completion_data = (array) ($groups['ll-tools-content-lesson-progress']['data'] ?? []);
        $this->assertSame(
            wp_json_encode([$lesson_id]),
            (string) ($completion_data[0]['value'] ?? '')
        );
        $this->assertSame(
            wp_json_encode($legacy_completion_data),
            (string) ($completion_data[1]['value'] ?? '')
        );

        $this->assertTrue(ll_tools_privacy_delete_user_personal_data($user_id));
        $this->assertSame([], ll_tools_get_completed_content_lesson_ids($user_id));
        $this->assertFalse(
            metadata_exists(
                'user',
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META
            )
        );
        $this->assertFalse(
            metadata_exists(
                'user',
                $user_id,
                'tt_completed_lessons'
            )
        );
    }

    public function test_content_lesson_save_rejects_a_dependency_cycle(): void
    {
        $wordset_id = $this->createWordset('Cycle protection');
        $proposed_wordset_id = $this->createWordset('Rejected cycle wordset');
        $first_id = $this->createLesson($wordset_id, 'First cycle lesson', 'draft');
        $second_id = $this->createLesson($proposed_wordset_id, 'Second cycle lesson', 'draft');
        update_post_meta(
            $second_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$first_id]
        );
        $this->setCurrentManager();
        $first = get_post($first_id);
        $this->assertInstanceOf(WP_Post::class, $first);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $proposed_wordset_id,
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => '',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_prereq_lesson_ids' => [(string) $second_id],
        ];

        ll_tools_save_content_lesson_metabox($first_id, $first);

        $this->assertSame(
            $wordset_id,
            ll_tools_get_content_lesson_wordset_id($first_id)
        );
        $this->assertSame([], ll_tools_get_content_lesson_prereq_lesson_ids($first_id));
        $this->assertSame(
            'content_lesson_prerequisite_cycle',
            (string) get_post_meta($first_id, LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META, true)
        );
        $this->assertStringContainsString(
            'lesson loop',
            ll_tools_get_content_lesson_relation_error($first_id)
        );
    }

    public function test_prerequisite_graph_fails_closed_when_candidate_metadata_queries_fail(): void
    {
        $wordset_id = $this->createWordset('Incomplete prerequisite metadata');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Metadata failure target',
            'draft'
        );
        $candidate_id = $this->createLesson(
            $wordset_id,
            'Metadata failure candidate',
            'draft'
        );
        $stored_id = $this->createLesson(
            $wordset_id,
            'Metadata failure stored prerequisite',
            'draft'
        );
        update_post_meta(
            $candidate_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$stored_id]
        );

        foreach ([false, true] as $bypass_wordset_read) {
            [$result, $injected, $bypassed] = $this->validateWithFailedCandidateMetaQuery(
                $lesson_id,
                $wordset_id,
                $candidate_id,
                $bypass_wordset_read
            );

            $this->assertTrue(
                $injected,
                'Expected a bounded candidate metadata query failure to be injected.'
            );
            $this->assertSame($bypass_wordset_read, $bypassed);
            $this->assertWPError($result);
            $this->assertSame(
                'content_lesson_relation_query_incomplete',
                $result->get_error_code()
            );
        }
    }

    public function test_content_lesson_save_rejects_an_oversized_prerequisite_payload_atomically(): void
    {
        $wordset_id = $this->createWordset('Bounded prerequisite save');
        $proposed_wordset_id = $this->createWordset('Rejected oversized wordset');
        $lesson_id = $this->createLesson($wordset_id, 'Bounded prerequisite lesson', 'draft');
        $existing_prerequisite_id = $this->createLesson(
            $wordset_id,
            'Existing bounded prerequisite',
            'draft'
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$existing_prerequisite_id]
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
            [991]
        );
        $this->setCurrentManager();
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $edge_limit = static function (): int {
            return 10;
        };
        add_filter('ll_tools_content_lesson_prerequisite_edge_limit', $edge_limit);
        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $proposed_wordset_id,
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => '',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_prereq_lesson_ids' => array_fill(0, 11, (string) $existing_prerequisite_id),
        ];
        try {
            ll_tools_save_content_lesson_metabox($lesson_id, $lesson);
        } finally {
            remove_filter('ll_tools_content_lesson_prerequisite_edge_limit', $edge_limit);
        }

        $this->assertSame(
            $wordset_id,
            ll_tools_get_content_lesson_wordset_id($lesson_id)
        );
        $this->assertSame(
            [$existing_prerequisite_id],
            (array) get_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                true
            )
        );
        $this->assertSame(
            [991],
            (array) get_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
                true
            )
        );
        $this->assertSame(
            'content_lesson_prerequisite_graph_too_large',
            (string) get_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META,
                true
            )
        );
    }

    public function test_content_lesson_save_preserves_relation_scope_when_submitted_identity_reads_fail(): void
    {
        $wordset_id = $this->createWordset('Identity failure wordset');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Identity failure target',
            'draft'
        );
        $prerequisite_id = $this->createLesson(
            $wordset_id,
            'Identity failure prerequisite',
            'draft'
        );
        $category = wp_insert_term(
            'Identity failure category ' . wp_generate_password(5, false),
            'word-category'
        );
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
            [$category_id]
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
            [$category_id]
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$prerequisite_id]
        );
        $this->setCurrentManager();
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);
        $request = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $wordset_id,
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => '',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $category_id],
            'll_content_lesson_prereq_category_ids' => [(string) $category_id],
            'll_content_lesson_prereq_lesson_ids' => [(string) $prerequisite_id],
        ];

        $cases = [
            ['term', $wordset_id, 'wordset'],
            ['term', $category_id, 'category'],
            ['post', $prerequisite_id, 'prerequisite lesson'],
        ];
        foreach ($cases as [$identity_type, $identity_id, $label]) {
            $injected = $this->saveWithFailedContentLessonIdentityQuery(
                $lesson_id,
                $lesson,
                $request,
                $identity_type,
                $identity_id
            );
            $this->assertTrue(
                $injected,
                'Expected the submitted ' . $label . ' identity query failure to be injected.'
            );
            $this->assertSame(
                $wordset_id,
                ll_tools_get_content_lesson_wordset_id($lesson_id)
            );
            $this->assertSame(
                [$category_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
                    true
                )
            );
            $this->assertSame(
                [$category_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
                    true
                )
            );
            $this->assertSame(
                [$prerequisite_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                    true
                )
            );
        }

        $this->assertSame(
            'content_lesson_relation_query_incomplete',
            (string) get_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META,
                true
            )
        );
    }

    public function test_content_lesson_save_preserves_relations_when_category_filters_are_incomplete(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Incomplete category filtering');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Incomplete category filtering target',
            'draft'
        );
        $prerequisite_id = $this->createLesson(
            $wordset_id,
            'Incomplete category filtering prerequisite',
            'draft'
        );
        $category = wp_insert_term(
            'Incomplete filtering category ' . wp_generate_password(5, false),
            'word-category'
        );
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner(
            $category_id,
            $wordset_id,
            $category_id
        );
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Filter candidate word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');
        wp_set_object_terms($word_id, [$category_id], 'word-category');
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
            [$category_id]
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
            [$category_id]
        );
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$prerequisite_id]
        );
        $this->setCurrentManager();
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);
        $request = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $wordset_id,
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => '',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $category_id],
            'll_content_lesson_prereq_category_ids' => [(string) $category_id],
            'll_content_lesson_prereq_lesson_ids' => [(string) $prerequisite_id],
        ];

        foreach (['remap', 'quizzability'] as $failure_stage) {
            get_term_meta($category_id);
            if ($failure_stage === 'remap') {
                wp_cache_delete($category_id, 'term_meta');
            }
            $candidate_query_count = 0;
            $injected = false;
            $query_filter = static function (string $query) use (
                $wpdb,
                $category_id,
                $failure_stage,
                &$candidate_query_count,
                &$injected
            ): string {
                if (stripos($query, 'SELECT DISTINCT tt_cat.term_id') !== false) {
                    $candidate_query_count++;
                    if ($failure_stage === 'quizzability' && $candidate_query_count === 2) {
                        wp_cache_delete($category_id, 'term_meta');
                    }
                    return $query;
                }

                if (
                    !$injected
                    && stripos($query, 'FROM ' . $wpdb->termmeta) !== false
                    && preg_match(
                        '/term_id\s+IN\s*\(\s*'
                            . preg_quote((string) $category_id, '/')
                            . '\s*\)/i',
                        $query
                    ) === 1
                    && (
                        $failure_stage === 'remap'
                        || $candidate_query_count >= 2
                    )
                ) {
                    $injected = true;
                    return "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}_ll_tools_missing";
                }

                return $query;
            };

            $previous_suppress_errors = $wpdb->suppress_errors(true);
            add_filter('query', $query_filter);
            $_POST = $request;
            try {
                ll_tools_save_content_lesson_metabox($lesson_id, $lesson);
            } finally {
                remove_filter('query', $query_filter);
                $wpdb->suppress_errors($previous_suppress_errors);
                $wpdb->last_error = '';
                wp_cache_delete($category_id, 'term_meta');
            }

            $this->assertTrue(
                $injected,
                'Expected the ' . $failure_stage . ' metadata read failure to be injected.'
            );
            $this->assertSame(
                $wordset_id,
                ll_tools_get_content_lesson_wordset_id($lesson_id)
            );
            $this->assertSame(
                [$category_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
                    true
                )
            );
            $this->assertSame(
                [$category_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
                    true
                )
            );
            $this->assertSame(
                [$prerequisite_id],
                (array) get_post_meta(
                    $lesson_id,
                    LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                    true
                )
            );
        }
    }

    public function test_cached_empty_wordset_meta_cannot_bypass_existing_scope_authorization(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Protected existing scope');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Protected existing lesson',
            'draft'
        );
        $editor_id = self::factory()->user->create(['role' => 'editor']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        wp_update_post([
            'ID' => $lesson_id,
            'post_author' => $editor_id,
        ]);
        wp_set_current_user($editor_id);
        $this->assertTrue(current_user_can('edit_post', $lesson_id));
        $this->assertFalse(
            ll_tools_user_can_manage_wordset_content($wordset_id, $editor_id)
        );

        wp_cache_set($lesson_id, [], 'post_meta');
        $this->assertSame(0, ll_tools_get_content_lesson_wordset_id($lesson_id));
        $authoritative_complete = true;
        $this->assertSame(
            $wordset_id,
            ll_tools_get_content_lesson_wordset_id_authoritative(
                $lesson_id,
                $authoritative_complete
            )
        );
        $this->assertTrue($authoritative_complete);

        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);
        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => '0',
            'll_content_lesson_kind' => 'article',
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => '',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertSame(
            (string) $wordset_id,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id = %d
                  AND meta_key = %s
                ORDER BY meta_id ASC
                LIMIT 1",
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_WORDSET_META
            ))
        );
        clean_post_cache($lesson_id);
        $this->assertSame('standard', ll_tools_get_content_lesson_kind($lesson_id));
    }

    public function test_authoritative_wordset_read_rejects_duplicate_scope_rows(): void
    {
        $wordset_id = $this->createWordset('Duplicate existing scope');
        $other_wordset_id = $this->createWordset('Duplicate alternate scope');
        $lesson_id = $this->createLesson(
            $wordset_id,
            'Duplicate scoped lesson',
            'draft'
        );
        $this->assertNotFalse(add_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            $other_wordset_id,
            false
        ));

        $complete = true;
        $this->assertSame(
            0,
            ll_tools_get_content_lesson_wordset_id_authoritative(
                $lesson_id,
                $complete
            )
        );
        $this->assertFalse($complete);
    }

    /**
     * @return array{0:true|WP_Error,1:bool,2:bool}
     */
    private function validateWithFailedCandidateMetaQuery(
        int $lesson_id,
        int $wordset_id,
        int $candidate_id,
        bool $bypass_wordset_read
    ): array {
        global $wpdb;

        wp_cache_delete($candidate_id, 'post_meta');
        $injected = false;
        $bypassed = false;
        $query_filter = static function (string $query) use (
            $wpdb,
            $candidate_id,
            &$injected
        ): string {
            if (
                !$injected
                && stripos($query, 'FROM ' . $wpdb->postmeta) !== false
                && preg_match(
                    '/post_id\s+IN\s*\(\s*'
                    . preg_quote((string) $candidate_id, '/')
                    . '\s*\)/i',
                    $query
                ) === 1
            ) {
                $injected = true;
                return "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}_ll_tools_content_lesson_missing";
            }

            return $query;
        };
        $metadata_filter = static function (
            $value,
            int $object_id,
            string $meta_key,
            bool $single
        ) use (
            $candidate_id,
            $wordset_id,
            $bypass_wordset_read,
            &$bypassed
        ) {
            if (
                $bypass_wordset_read
                && $single
                && $object_id === $candidate_id
                && $meta_key === LL_TOOLS_CONTENT_LESSON_WORDSET_META
            ) {
                $bypassed = true;
                return (string) $wordset_id;
            }

            return $value;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $query_filter);
        if ($bypass_wordset_read) {
            add_filter('get_post_metadata', $metadata_filter, 10, 4);
        }
        try {
            $result = ll_tools_validate_content_lesson_prerequisite_graph(
                $lesson_id,
                $wordset_id,
                [$candidate_id]
            );
        } finally {
            if ($bypass_wordset_read) {
                remove_filter('get_post_metadata', $metadata_filter, 10);
            }
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            wp_cache_delete($candidate_id, 'post_meta');
        }

        return [$result, $injected, $bypassed];
    }

    /**
     * @param array<string,mixed> $request
     */
    private function saveWithFailedContentLessonIdentityQuery(
        int $lesson_id,
        WP_Post $lesson,
        array $request,
        string $identity_type,
        int $identity_id
    ): bool {
        global $wpdb;

        if ($identity_type === 'term') {
            clean_term_cache($identity_id);
        } else {
            clean_post_cache($identity_id);
        }
        $injected = false;
        $query_filter = static function (string $query) use (
            $wpdb,
            $identity_type,
            $identity_id,
            &$injected
        ): string {
            if ($injected) {
                return $query;
            }

            if (
                $identity_type === 'term'
                && stripos($query, 'FROM ' . $wpdb->terms) !== false
                && preg_match(
                    '/\bt\.term_id\s*(?:=\s*'
                        . preg_quote((string) $identity_id, '/')
                        . '\b|IN\s*\(\s*'
                        . preg_quote((string) $identity_id, '/')
                        . '\s*\))/i',
                    $query
                ) === 1
            ) {
                $injected = true;
                return "SELECT term_id FROM {$wpdb->terms}_ll_tools_missing";
            }
            if (
                $identity_type === 'post'
                && stripos($query, 'FROM ' . $wpdb->posts) !== false
                && preg_match(
                    '/\bID\s*(?:=\s*'
                        . preg_quote((string) $identity_id, '/')
                        . '\b|IN\s*\(\s*'
                        . preg_quote((string) $identity_id, '/')
                        . '\s*\))/i',
                    $query
                ) === 1
            ) {
                $injected = true;
                return "SELECT ID FROM {$wpdb->posts}_ll_tools_missing";
            }

            return $query;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $query_filter);
        $_POST = $request;
        try {
            ll_tools_save_content_lesson_metabox($lesson_id, $lesson);
        } finally {
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
            if ($identity_type === 'term') {
                clean_term_cache($identity_id);
            } else {
                clean_post_cache($identity_id);
            }
        }

        return $injected;
    }

    private function createWordset(string $label): int
    {
        $term = wp_insert_term(
            $label . ' ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createLesson(
        int $wordset_id,
        string $title,
        string $status = 'publish'
    ): int {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => '<p>Lesson body.</p>',
        ]);
        if ($wordset_id > 0) {
            update_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                $wordset_id
            );
        }
        return $lesson_id;
    }

    private function setCurrentManager(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        wp_set_current_user($user_id);
        return $user_id;
    }
}
