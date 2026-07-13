<?php
declare(strict_types=1);

final class WordGridBulkEditStateTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_register_words_post_type')) {
            ll_tools_register_words_post_type();
        }
        if (function_exists('ll_tools_register_word_category_taxonomy')) {
            ll_tools_register_word_category_taxonomy();
        }
        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }
        register_taxonomy_for_object_type('word-category', 'words');
        register_taxonomy_for_object_type('wordset', 'words');

        if (function_exists('ll_tools_rebuild_specific_wrong_answer_owner_map')) {
            ll_tools_rebuild_specific_wrong_answer_owner_map();
        }
    }

    public function test_bulk_defaults_helper_returns_shared_values_for_applicable_words(): void
    {
        ll_register_part_of_speech_taxonomy();

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Defaults');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $word_one = $this->createWord($wordset_id, $category_id, 'Bulk Noun One');
        wp_set_object_terms($word_one, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_one, 'll_grammatical_gender', 'masculine');
        update_post_meta($word_one, 'll_grammatical_plurality', 'singular');

        $word_two = $this->createWord($wordset_id, $category_id, 'Bulk Noun Two');
        wp_set_object_terms($word_two, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_two, 'll_grammatical_gender', 'Masculine');
        update_post_meta($word_two, 'll_grammatical_plurality', 'Singular');

        $word_three = $this->createWord($wordset_id, $category_id, 'Bulk Verb One');
        wp_set_object_terms($word_three, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($word_three, 'll_verb_tense', 'present');
        update_post_meta($word_three, 'll_verb_mood', 'Indicative');

        $defaults = ll_tools_word_grid_get_bulk_control_defaults($wordset_id, [$word_one, $word_two, $word_three]);

        $this->assertSame('', (string) ($defaults['part_of_speech'] ?? ''));
        $this->assertSame('Masculine', (string) ($defaults['grammatical_gender'] ?? ''));
        $this->assertSame('Singular', (string) ($defaults['grammatical_plurality'] ?? ''));
        $this->assertSame('Present', (string) ($defaults['verb_tense'] ?? ''));
        $this->assertSame('Indicative', (string) ($defaults['verb_mood'] ?? ''));
    }

    public function test_word_grid_meta_payload_normalizes_case_insensitive_meta_values(): void
    {
        ll_register_part_of_speech_taxonomy();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Render');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $noun_word_id = $this->createWord($wordset_id, $category_id, 'Render Noun');
        wp_set_object_terms($noun_word_id, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($noun_word_id, 'll_grammatical_gender', 'masculine');
        update_post_meta($noun_word_id, 'll_grammatical_plurality', 'singular');

        $verb_word_id = $this->createWord($wordset_id, $category_id, 'Render Verb');
        wp_set_object_terms($verb_word_id, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($verb_word_id, 'll_verb_tense', 'present');
        update_post_meta($verb_word_id, 'll_verb_mood', 'indicative');

        $noun_payload = ll_tools_word_grid_get_word_meta_payload($noun_word_id, $wordset_id);
        $verb_payload = ll_tools_word_grid_get_word_meta_payload($verb_word_id, $wordset_id);

        $this->assertSame('Masculine', (string) ($noun_payload['grammatical_gender']['value'] ?? ''));
        $this->assertSame('Singular', (string) ($noun_payload['grammatical_plurality']['value'] ?? ''));
        $this->assertSame('Present', (string) ($verb_payload['verb_tense']['value'] ?? ''));
        $this->assertSame('Indicative', (string) ($verb_payload['verb_mood']['value'] ?? ''));
    }

    public function test_bulk_undo_handler_restores_previous_word_meta_state(): void
    {
        ll_register_part_of_speech_taxonomy();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Undo');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');

        $word_one = $this->createWord($wordset_id, $category_id, 'Undo Noun');
        wp_set_object_terms($word_one, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_one, 'll_grammatical_gender', 'Masculine');
        update_post_meta($word_one, 'll_grammatical_plurality', 'Singular');

        $word_two = $this->createWord($wordset_id, $category_id, 'Undo Verb');
        wp_set_object_terms($word_two, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($word_two, 'll_verb_tense', 'Present');
        update_post_meta($word_two, 'll_verb_mood', 'Indicative');

        wp_set_object_terms($word_one, [$adjective_term_id], 'part_of_speech', false);
        wp_set_object_terms($word_two, [$adjective_term_id], 'part_of_speech', false);
        delete_post_meta($word_one, 'll_grammatical_gender');
        delete_post_meta($word_one, 'll_grammatical_plurality');
        delete_post_meta($word_two, 'll_verb_tense');
        delete_post_meta($word_two, 'll_verb_mood');

        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $this->assertSame([$wordset_id], wp_get_post_terms($word_one, 'wordset', ['fields' => 'ids']));
        $this->assertSame([$effective_category_id], wp_get_post_terms($word_one, 'word-category', ['fields' => 'ids']));

        $lesson_word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $effective_category_id);
        $this->assertEqualsCanonicalizing([$word_one, $word_two], array_values(array_map('intval', $lesson_word_ids)));

        $nonce = wp_create_nonce('ll_word_grid_edit');
        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'snapshot' => wp_json_encode([
                [
                    'word_id' => $word_one,
                    'part_of_speech' => 'noun',
                    'grammatical_gender' => 'Masculine',
                    'grammatical_plurality' => 'Singular',
                    'verb_tense' => '',
                    'verb_mood' => '',
                ],
                [
                    'word_id' => $word_two,
                    'part_of_speech' => 'verb',
                    'grammatical_gender' => '',
                    'grammatical_plurality' => '',
                    'verb_tense' => 'Present',
                    'verb_mood' => 'Indicative',
                ],
            ]),
        ];
        $_REQUEST = $_POST;
        $allow_legacy_undo = static function (): bool { return true; };
        add_filter('ll_tools_word_grid_allow_legacy_bulk_snapshot_undo', $allow_legacy_undo);

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_undo_handler();
            });
        } finally {
            remove_filter('ll_tools_word_grid_allow_legacy_bulk_snapshot_undo', $allow_legacy_undo);
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame(2, (int) ($response['data']['count'] ?? 0));

        $word_one_meta = ll_tools_word_grid_get_word_meta_payload($word_one, $wordset_id);
        $word_two_meta = ll_tools_word_grid_get_word_meta_payload($word_two, $wordset_id);

        $this->assertSame('noun', (string) ($word_one_meta['part_of_speech']['slug'] ?? ''));
        $this->assertSame('Masculine', (string) ($word_one_meta['grammatical_gender']['value'] ?? ''));
        $this->assertSame('Singular', (string) ($word_one_meta['grammatical_plurality']['value'] ?? ''));

        $this->assertSame('verb', (string) ($word_two_meta['part_of_speech']['slug'] ?? ''));
        $this->assertSame('Present', (string) ($word_two_meta['verb_tense']['value'] ?? ''));
        $this->assertSame('Indicative', (string) ($word_two_meta['verb_mood']['value'] ?? ''));
    }

    public function test_tokenless_bulk_undo_is_rejected_without_explicit_legacy_opt_in(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->resolveEffectiveCategoryId(
            $this->createCategory('Legacy Undo Rejection'),
            $wordset_id
        );
        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'mode' => 'pos',
            'snapshot' => '[]',
        ];
        $_REQUEST = $_POST;
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_undo_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringContainsString(
            'older bulk rollback',
            (string) ($response['data']['message'] ?? '')
        );
    }

    public function test_large_bulk_pos_update_and_undo_use_bounded_cursor_batches(): void
    {
        ll_register_part_of_speech_taxonomy();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Cursor');
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $word_ids = [];
        for ($index = 1; $index <= 60; $index++) {
            $word_ids[] = $this->createWord($wordset_id, $category_id, sprintf('Bulk Cursor %02d', $index));
        }
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);

        $captured_word_queries = [];
        $capture = static function (WP_Query $query) use (&$captured_word_queries): void {
            if ((string) $query->get('post_type') === 'words') {
                $captured_word_queries[] = $query->query_vars;
            }
        };
        add_action('pre_get_posts', $capture);
        try {
            $after_id = 0;
            $operation_token = '';
            $updated_ids = [];
            $batch_count = 0;
            do {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_word_grid_edit'),
                    'wordset_id' => $wordset_id,
                    'category_id' => $effective_category_id,
                    'mode' => 'pos',
                    'after_id' => $after_id,
                ];
                if ($operation_token === '') {
                    $_POST['part_of_speech'] = 'adjective';
                } else {
                    $_POST['operation_token'] = $operation_token;
                }
                $_REQUEST = $_POST;
                $response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_word_grid_bulk_update_handler();
                });
                $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
                $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
                $operation_token = (string) ($data['operation_token'] ?? $operation_token);
                $this->assertNotSame('', $operation_token);
                $batch_ids = array_values(array_map('intval', (array) ($data['word_ids'] ?? [])));
                $this->assertLessThanOrEqual(ll_tools_word_grid_bulk_batch_size(), count($batch_ids));
                $updated_ids = array_merge($updated_ids, $batch_ids);
                $batch_count++;
                $next_after_id = (int) ($data['next_after_id'] ?? 0);
                if (!empty($data['has_more'])) {
                    $this->assertGreaterThan($after_id, $next_after_id);
                }
                $after_id = $next_after_id;
            } while (!empty($data['has_more']) && $batch_count < 10);

            $this->assertSame(3, $batch_count);
            $this->assertEqualsCanonicalizing($word_ids, $updated_ids);
            foreach ($word_ids as $word_id) {
                $this->assertSame([$adjective_term_id], array_map('intval', wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])));
            }

            $undo_batch_count = 0;
            do {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_word_grid_edit'),
                    'wordset_id' => $wordset_id,
                    'category_id' => $effective_category_id,
                    'mode' => 'pos',
                    'operation_token' => $operation_token,
                ];
                $_REQUEST = $_POST;
                $undo_response = $this->runJsonEndpoint(static function (): void {
                    ll_tools_word_grid_bulk_undo_handler();
                });
                $this->assertTrue((bool) ($undo_response['success'] ?? false), wp_json_encode($undo_response));
                $this->assertLessThanOrEqual(
                    ll_tools_word_grid_bulk_batch_size(),
                    (int) ($undo_response['data']['count'] ?? 0)
                );
                $undo_batch_count++;
            } while (!empty($undo_response['data']['has_more_undo']) && $undo_batch_count < 10);
            $this->assertSame(3, $undo_batch_count);
        } finally {
            remove_action('pre_get_posts', $capture);
            $_POST = [];
            $_REQUEST = [];
        }

        foreach ($word_ids as $word_id) {
            $this->assertSame([], wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids']));
        }
        foreach ($captured_word_queries as $query_vars) {
            $this->assertNotSame(-1, (int) ($query_vars['posts_per_page'] ?? 0));
            $this->assertLessThanOrEqual(50, (int) ($query_vars['posts_per_page'] ?? 0));
        }
    }

    public function test_interrupted_bulk_operation_is_visible_only_to_its_owner_and_resumes(): void
    {
        ll_register_part_of_speech_taxonomy();
        $batch_size = static function (): int { return 1; };
        add_filter('ll_tools_word_grid_bulk_batch_size', $batch_size);

        $owner_id = self::factory()->user->create(['role' => 'administrator']);
        $owner = get_user_by('id', $owner_id);
        $this->assertInstanceOf(WP_User::class, $owner);
        $owner->add_cap('view_ll_tools');
        wp_set_current_user($owner_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Resume');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $word_ids = [
            $this->createWord($wordset_id, $category_id, 'Resume One'),
            $this->createWord($wordset_id, $category_id, 'Resume Two'),
            $this->createWord($wordset_id, $category_id, 'Resume Three'),
        ];

        try {
            $_POST = [
                'nonce' => wp_create_nonce('ll_word_grid_edit'),
                'wordset_id' => $wordset_id,
                'category_id' => $effective_category_id,
                'mode' => 'pos',
                'part_of_speech' => 'adjective',
            ];
            $_REQUEST = $_POST;
            $start = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_update_handler();
            });
            $this->assertTrue((bool) ($start['success'] ?? false), wp_json_encode($start));
            $token = (string) ($start['data']['operation_token'] ?? '');
            $this->assertNotSame('', $token);
            $this->assertTrue((bool) ($start['data']['has_more'] ?? false));
            $this->assertSame('running', (string) ($start['data']['operation']['status'] ?? ''));
            global $wpdb;
            $state_key = ll_tools_word_grid_bulk_operation_state_key(
                $owner_id,
                $wordset_id,
                $effective_category_id,
                'pos'
            );
            $autoload = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                $state_key
            ));
            $this->assertNotContains($autoload, ['yes', 'on', 'auto', 'auto-on']);

            $_POST = [
                'nonce' => wp_create_nonce('ll_word_grid_edit'),
                'wordset_id' => $wordset_id,
                'category_id' => $effective_category_id,
            ];
            $_REQUEST = $_POST;
            $owner_status = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_status_handler();
            });
            $this->assertSame($token, (string) ($owner_status['data']['operations']['pos']['token'] ?? ''));
            $this->assertTrue((bool) ($owner_status['data']['operations']['pos']['can_continue'] ?? false));
            $this->assertTrue((bool) ($owner_status['data']['operations']['pos']['can_undo'] ?? false));

            $other_id = self::factory()->user->create(['role' => 'administrator']);
            $other = get_user_by('id', $other_id);
            $this->assertInstanceOf(WP_User::class, $other);
            $other->add_cap('view_ll_tools');
            wp_set_current_user($other_id);
            $_POST['nonce'] = wp_create_nonce('ll_word_grid_edit');
            $_REQUEST = $_POST;
            $other_status = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_status_handler();
            });
            $this->assertSame([], (array) ($other_status['data']['operations'] ?? []));

            $_POST = [
                'nonce' => wp_create_nonce('ll_word_grid_edit'),
                'wordset_id' => $wordset_id,
                'category_id' => $effective_category_id,
                'mode' => 'pos',
                'operation_token' => $token,
            ];
            $_REQUEST = $_POST;
            $other_continue = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_update_handler();
            });
            $this->assertFalse((bool) ($other_continue['success'] ?? true));
            $this->assertSame('ll_tools_word_grid_bulk_not_found', (string) ($other_continue['data']['code'] ?? ''));

            wp_set_current_user($owner_id);
            $has_more = true;
            $continuations = 0;
            while ($has_more && $continuations < 5) {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_word_grid_edit'),
                    'wordset_id' => $wordset_id,
                    'category_id' => $effective_category_id,
                    'mode' => 'pos',
                    'operation_token' => $token,
                ];
                $_REQUEST = $_POST;
                $continued = $this->runJsonEndpoint(static function (): void {
                    ll_tools_word_grid_bulk_update_handler();
                });
                $this->assertTrue((bool) ($continued['success'] ?? false), wp_json_encode($continued));
                $has_more = !empty($continued['data']['has_more']);
                $continuations++;
            }
            $this->assertFalse($has_more);
            $this->assertSame(2, $continuations);
            foreach ($word_ids as $word_id) {
                $this->assertSame([$adjective_term_id], array_map('intval', wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])));
            }
        } finally {
            remove_filter('ll_tools_word_grid_bulk_batch_size', $batch_size);
            $_POST = [];
            $_REQUEST = [];
        }
    }

    public function test_prepared_batch_can_retry_without_recapturing_mutated_state(): void
    {
        ll_register_part_of_speech_taxonomy();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Prepared Retry');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $word_id = $this->createWord($wordset_id, $category_id, 'Prepared Word');
        wp_set_object_terms($word_id, [$noun_term_id], 'part_of_speech', false);

        $operation = ll_tools_word_grid_bulk_operation_open(
            $admin_id,
            $wordset_id,
            $effective_category_id,
            'pos',
            '',
            'adjective'
        );
        $this->assertIsArray($operation);
        $batch = ll_tools_get_lesson_word_ids_batch($wordset_id, $effective_category_id, 0);
        $candidate_ids = array_values(array_map('intval', (array) ($batch['ids'] ?? [])));
        $operation = ll_tools_word_grid_bulk_operation_prepare_batch(
            $operation,
            $batch,
            $candidate_ids,
            $candidate_ids
        );
        $this->assertIsArray($operation);
        $token = (string) ($operation['state']['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertSame([$noun_term_id], array_map('intval', wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])));
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) $operation['state_key'],
            (string) $operation['lock_token']
        );

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'operation_token' => $token,
        ];
        $_REQUEST = $_POST;
        $retry = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_update_handler();
        });
        $this->assertTrue((bool) ($retry['success'] ?? false), wp_json_encode($retry));
        $this->assertFalse((bool) ($retry['data']['has_more'] ?? true));

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'operation_token' => $token,
        ];
        $_REQUEST = $_POST;
        $undo = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_undo_handler();
        });
        $this->assertTrue((bool) ($undo['success'] ?? false), wp_json_encode($undo));
        $this->assertSame([$noun_term_id], array_map('intval', wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])));
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_expired_operation_cleanup_deletes_state_and_snapshot_chunks(): void
    {
        ll_register_part_of_speech_taxonomy();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Expired Bulk');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $this->createWord($wordset_id, $category_id, 'Expired Word');

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'part_of_speech' => 'adjective',
        ];
        $_REQUEST = $_POST;
        $start = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_update_handler();
        });
        $token = (string) ($start['data']['operation_token'] ?? '');
        $state_key = ll_tools_word_grid_bulk_operation_state_key($admin_id, $wordset_id, $effective_category_id, 'pos');
        $chunk_key = ll_tools_word_grid_bulk_operation_chunk_key($token, 1);
        $this->assertIsArray(get_option($chunk_key, null));

        $state = ll_tools_word_grid_bulk_operation_load($state_key);
        $state['expires_at'] = time() - 1;
        update_option($state_key, $state, false);
        $this->assertNotFalse(wp_next_scheduled(
            LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK,
            [$state_key, $token]
        ));
        ll_tools_word_grid_bulk_operation_cleanup($state_key, $token);
        $this->assertNull(get_option($state_key, null));
        $this->assertNull(get_option($chunk_key, null));
        $this->assertFalse(wp_next_scheduled(
            LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK,
            [$state_key, $token]
        ));
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_durable_undo_skips_words_changed_after_bulk_completion(): void
    {
        ll_register_part_of_speech_taxonomy();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Conflict Undo');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');
        $word_id = $this->createWord($wordset_id, $category_id, 'Conflict Word');
        wp_set_object_terms($word_id, [$noun_term_id], 'part_of_speech', false);

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'part_of_speech' => 'adjective',
        ];
        $_REQUEST = $_POST;
        $start = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_update_handler();
        });
        $token = (string) ($start['data']['operation_token'] ?? '');
        wp_set_object_terms($word_id, [$verb_term_id], 'part_of_speech', false);

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'operation_token' => $token,
        ];
        $_REQUEST = $_POST;
        $undo = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_undo_handler();
        });
        $this->assertTrue((bool) ($undo['success'] ?? false), wp_json_encode($undo));
        $this->assertSame(1, (int) ($undo['data']['conflict_count'] ?? 0));
        $this->assertSame([$verb_term_id], array_map('intval', wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])));
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_resume_revalidates_prepared_targets_against_current_lesson_scope(): void
    {
        ll_register_part_of_speech_taxonomy();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Prepared Scope');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $word_id = $this->createWord($wordset_id, $category_id, 'Moved Before Resume');
        wp_set_object_terms($word_id, [$noun_term_id], 'part_of_speech', false);

        $operation = ll_tools_word_grid_bulk_operation_open(
            $admin_id,
            $wordset_id,
            $effective_category_id,
            'pos',
            '',
            'adjective'
        );
        $this->assertIsArray($operation);
        $batch = ll_tools_get_lesson_word_ids_batch($wordset_id, $effective_category_id, 0);
        $candidate_ids = array_values(array_map('intval', (array) ($batch['ids'] ?? [])));
        $operation = ll_tools_word_grid_bulk_operation_prepare_batch(
            $operation,
            $batch,
            $candidate_ids,
            $candidate_ids
        );
        $this->assertIsArray($operation);
        $token = (string) ($operation['state']['token'] ?? '');
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) $operation['state_key'],
            (string) $operation['lock_token']
        );

        wp_set_object_terms($word_id, [], 'word-category', false);
        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'pos',
            'operation_token' => $token,
        ];
        $_REQUEST = $_POST;
        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_update_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame(0, (int) ($response['data']['count'] ?? -1));
        $this->assertSame([$noun_term_id], array_map(
            'intval',
            wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids'])
        ));
    }

    public function test_stale_lease_holder_cannot_overwrite_operation_state(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Lease Fence');
        $operation = ll_tools_word_grid_bulk_operation_open(
            $admin_id,
            $wordset_id,
            $category_id,
            'pos',
            '',
            'noun'
        );
        $this->assertIsArray($operation);

        $state_key = (string) $operation['state_key'];
        $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
        $takeover_token = wp_generate_uuid4();
        update_option($lock_key, $takeover_token . '|' . (time() + 120), false);
        $stale_state = (array) $operation['state'];
        $stale_state['processed'] = 99;

        $this->assertFalse(ll_tools_word_grid_bulk_operation_write_state(
            $state_key,
            (string) $operation['lock_token'],
            $stale_state
        ));
        $this->assertSame(0, (int) (ll_tools_word_grid_bulk_operation_load($state_key)['processed'] ?? -1));

        delete_option($state_key);
        delete_option($lock_key);
    }

    public function test_lease_takeover_during_first_write_leaves_only_an_empty_placeholder(): void
    {
        $state_key = ll_tools_word_grid_bulk_operation_state_key(11, 22, 33, 'pos');
        $lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($state_key);
        $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
        $takeover = static function (string $hook_state_key, string $option_name) use (
            $state_key,
            $lock_key
        ): void {
            if ($hook_state_key === $state_key && $option_name === $state_key) {
                update_option($lock_key, wp_generate_uuid4() . '|' . (time() + 120), false);
            }
        };
        add_action('ll_tools_word_grid_bulk_operation_placeholder_created', $takeover, 10, 2);
        try {
            $this->assertNotSame('', $lock_token);
            $this->assertFalse(ll_tools_word_grid_bulk_operation_write_state(
                $state_key,
                $lock_token,
                ['schema' => 1, 'token' => 'stale-state']
            ));
            $this->assertSame([], get_option($state_key, null));
        } finally {
            remove_action('ll_tools_word_grid_bulk_operation_placeholder_created', $takeover, 10);
            delete_option($state_key);
            delete_option($lock_key);
        }
    }

    public function test_undo_accepts_an_unchanged_recorded_partial_write_state(): void
    {
        ll_register_part_of_speech_taxonomy();
        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Partial Write Undo');
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');
        $word_id = $this->createWord($wordset_id, $category_id, 'Partial Write');
        wp_set_object_terms($word_id, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_id, 'll_grammatical_gender', 'Masculine');

        $state = [
            'mode' => 'pos',
            'request_value' => 'adjective',
        ];
        $rows = ll_tools_word_grid_bulk_operation_add_expected_values(
            ll_tools_word_grid_bulk_operation_capture_snapshot([$word_id]),
            $state
        );
        wp_set_object_terms($word_id, [$adjective_term_id], 'part_of_speech', false);
        $state['pending']['failed_current_rows'] = ll_tools_word_grid_bulk_operation_capture_snapshot([$word_id]);

        $filtered = ll_tools_word_grid_bulk_operation_filter_undo_rows($rows, $state);
        $this->assertSame([$word_id], array_map('intval', wp_list_pluck($filtered['rows'], 'word_id')));
        $this->assertSame([], $filtered['conflict_ids']);
    }

    public function test_snapshot_cleanup_is_bounded_and_resumable(): void
    {
        $batch_size = static function (): int { return 1; };
        add_filter('ll_tools_word_grid_bulk_snapshot_cleanup_batch_size', $batch_size);
        $token = wp_generate_uuid4();
        $state_key = ll_tools_word_grid_bulk_operation_state_key(1, 2, 3, 'pos');
        $state = [
            'schema' => 1,
            'token' => $token,
            'chunk_count' => 3,
        ];
        add_option($state_key, $state, '', 'no');
        for ($index = 1; $index <= 3; $index++) {
            add_option(
                ll_tools_word_grid_bulk_operation_chunk_key($token, $index),
                [['word_id' => $index]],
                '',
                'no'
            );
        }
        $lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($state_key);
        $cleanup_key = ll_tools_word_grid_bulk_operation_cleanup_key($token);
        try {
            $this->assertNotSame('', $lock_token);
            $this->assertTrue(ll_tools_word_grid_bulk_operation_delete($state_key, $token, $lock_token));
            $this->assertNull(get_option($state_key, null));
            $this->assertNull(get_option(ll_tools_word_grid_bulk_operation_chunk_key($token, 3), null));
            $this->assertIsArray(get_option(ll_tools_word_grid_bulk_operation_chunk_key($token, 2), null));
            $this->assertSame(2, (int) (get_option($cleanup_key, [])['next_index'] ?? 0));
            $this->assertNotFalse(wp_next_scheduled(
                LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK,
                [$cleanup_key]
            ));

            ll_tools_word_grid_bulk_operation_process_snapshot_cleanup($cleanup_key);
            $this->assertNotFalse(wp_next_scheduled(
                LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK,
                [$cleanup_key]
            ));
            ll_tools_word_grid_bulk_operation_process_snapshot_cleanup($cleanup_key);
            $this->assertNull(get_option($cleanup_key, null));
            $this->assertNull(get_option(ll_tools_word_grid_bulk_operation_chunk_key($token, 1), null));
            $this->assertNull(get_option(ll_tools_word_grid_bulk_operation_chunk_key($token, 2), null));
            $this->assertFalse(wp_next_scheduled(
                LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK,
                [$cleanup_key]
            ));
        } finally {
            remove_filter('ll_tools_word_grid_bulk_snapshot_cleanup_batch_size', $batch_size);
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
        }
    }

    public function test_snapshot_cleanup_waits_until_operation_state_is_detached(): void
    {
        $token = wp_generate_uuid4();
        $state_key = ll_tools_word_grid_bulk_operation_state_key(4, 5, 6, 'pos');
        $chunk_key = ll_tools_word_grid_bulk_operation_chunk_key($token, 1);
        $cleanup_key = ll_tools_word_grid_bulk_operation_cleanup_key($token);
        add_option($state_key, ['schema' => 1, 'token' => $token, 'chunk_count' => 1], '', 'no');
        add_option($chunk_key, [['word_id' => 1]], '', 'no');
        add_option($cleanup_key, [
            'schema' => 1,
            'token' => $token,
            'state_key' => $state_key,
            'next_index' => 1,
        ], '', 'no');

        ll_tools_word_grid_bulk_operation_process_snapshot_cleanup($cleanup_key);
        $this->assertIsArray(get_option($chunk_key, null));
        $this->assertNotFalse(wp_next_scheduled(
            LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK,
            [$cleanup_key]
        ));

        delete_option($state_key);
        ll_tools_word_grid_bulk_operation_process_snapshot_cleanup($cleanup_key);
        $this->assertNull(get_option($chunk_key, null));
        $this->assertNull(get_option($cleanup_key, null));
    }

    public function test_durable_gender_undo_restores_raw_value_after_feature_is_disabled(): void
    {
        ll_register_part_of_speech_taxonomy();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $this->enableBulkWordsetMeta($wordset_id);
        $category_id = $this->createCategory('Disabled Gender Undo');
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);
        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $word_id = $this->createWord($wordset_id, $category_id, 'Gender Restore');
        wp_set_object_terms($word_id, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_id, 'll_grammatical_gender', 'Masculine');

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'gender',
            'grammatical_gender' => 'Feminine',
        ];
        $_REQUEST = $_POST;
        $update = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_bulk_update_handler();
        });
        $this->assertTrue((bool) ($update['success'] ?? false), wp_json_encode($update));
        $token = (string) ($update['data']['operation_token'] ?? '');
        update_term_meta($wordset_id, 'll_wordset_has_gender', 0);

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $effective_category_id,
            'mode' => 'gender',
            'operation_token' => $token,
        ];
        $_REQUEST = $_POST;
        try {
            $undo = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_undo_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($undo['success'] ?? false), wp_json_encode($undo));
        $this->assertSame('Masculine', (string) get_post_meta($word_id, 'll_grammatical_gender', true));
    }

    private function createWordset(): int
    {
        $term = wp_insert_term('Bulk Test Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        return $term_id;
    }

    private function createWord(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => $title,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);
        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);
        return $word_id;
    }

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return $category_id;
    }

    private function enableBulkWordsetMeta(int $wordset_id): void
    {
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        update_term_meta($wordset_id, 'll_wordset_has_plurality', 1);
        update_term_meta($wordset_id, 'll_wordset_plurality_options', ['Singular', 'Plural']);
        update_term_meta($wordset_id, 'll_wordset_has_verb_tense', 1);
        update_term_meta($wordset_id, 'll_wordset_verb_tense_options', ['Present', 'Past']);
        update_term_meta($wordset_id, 'll_wordset_has_verb_mood', 1);
        update_term_meta($wordset_id, 'll_wordset_verb_mood_options', ['Indicative', 'Imperative']);
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): int
    {
        $existing = term_exists($slug, 'part_of_speech');
        if (is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing)) {
            return $existing;
        }

        $term = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
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
}
