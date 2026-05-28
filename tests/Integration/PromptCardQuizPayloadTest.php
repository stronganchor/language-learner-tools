<?php
declare(strict_types=1);

final class PromptCardQuizPayloadTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_prompt_card_rows_include_prompt_audio_support_rows_and_scoped_counts(): void
    {
        $fixture = $this->createPromptCardFixture();
        $term = get_term($fixture['effective_prompt_category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $config = ll_tools_get_category_quiz_config($term);
        $rows = ll_get_words_by_category($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);
        $count = ll_get_words_by_category_count($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);

        $prompt_rows = [];
        $support_rows = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['is_prompt_card'])) {
                $prompt_rows[(int) ($row['prompt_card_id'] ?? 0)] = $row;
                continue;
            }
            if (!empty($row['is_specific_wrong_answer_only'])) {
                $support_rows[(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $this->assertCount(2, $prompt_rows);
        $this->assertCount(2, $support_rows);
        $this->assertSame(2, $count, 'Count helper should count prompt-card rounds, not support rows.');

        $horse_prompt_row = $prompt_rows[$fixture['horse_or_cow_card_id']] ?? [];
        $this->assertTrue((bool) ($horse_prompt_row['is_prompt_card'] ?? false));
        $this->assertSame($fixture['horse_or_cow_card_id'], (int) ($horse_prompt_row['id'] ?? 0));
        $this->assertSame($fixture['horse_id'], (int) ($horse_prompt_row['answer_word_id'] ?? 0));
        $this->assertSame($fixture['horse_id'], (int) ($horse_prompt_row['progress_word_id'] ?? 0));
        $this->assertTrue((bool) ($horse_prompt_row['track_answer_word_progress'] ?? false));
        $this->assertSame('https://example.com/prompt-horse-or-cow.mp3', (string) ($horse_prompt_row['prompt_audio'] ?? ''));
        $this->assertSame('Is this a horse or a cow?', (string) ($horse_prompt_row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($horse_prompt_row['image'] ?? ''));

        $yes_no_prompt_row = $prompt_rows[$fixture['is_this_horse_card_id']] ?? [];
        $this->assertTrue((bool) ($yes_no_prompt_row['is_prompt_card'] ?? false));
        $this->assertSame($fixture['is_this_horse_card_id'], (int) ($yes_no_prompt_row['id'] ?? 0));
        $this->assertSame($fixture['yes_id'], (int) ($yes_no_prompt_row['answer_word_id'] ?? 0));
        $this->assertSame(0, (int) ($yes_no_prompt_row['progress_word_id'] ?? -1));
        $this->assertFalse((bool) ($yes_no_prompt_row['track_answer_word_progress'] ?? true));
        $this->assertSame('https://example.com/prompt-is-this-a-horse.mp3', (string) ($yes_no_prompt_row['prompt_audio'] ?? ''));
        $this->assertSame('Is this a horse?', (string) ($yes_no_prompt_row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($yes_no_prompt_row['image'] ?? ''));

        $cow_support_row = $support_rows[$fixture['cow_id']] ?? [];
        $this->assertTrue((bool) ($cow_support_row['is_specific_wrong_answer_only'] ?? false));
        $this->assertSame([$fixture['horse_or_cow_card_id']], $this->normalizeIds((array) ($cow_support_row['specific_wrong_answer_owner_ids'] ?? [])));

        $no_support_row = $support_rows[$fixture['no_id']] ?? [];
        $this->assertTrue((bool) ($no_support_row['is_specific_wrong_answer_only'] ?? false));
        $this->assertSame([$fixture['is_this_horse_card_id']], $this->normalizeIds((array) ($no_support_row['specific_wrong_answer_owner_ids'] ?? [])));
    }

    public function test_prompt_card_rows_support_image_prompts_with_image_answer_options(): void
    {
        $asset_category_id = $this->createCategory('ASL Prompt Card Assets ' . wp_generate_password(5, false), 'image', 'image');
        $prompt_category_name = 'ASL Prompt Card Questions ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'image', 'image');
        $wordset_id = $this->createWordset('ASL Prompt Card Wordset ' . wp_generate_password(5, false));
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $sign_tree_id = $this->createWord($asset_category_id, 'ASL Tree Sign');
        $tree_id = $this->createWord($asset_category_id, 'Tree');
        $river_id = $this->createWord($asset_category_id, 'River');
        $mountain_id = $this->createWord($asset_category_id, 'Mountain');
        foreach ([$sign_tree_id, $tree_id, $river_id, $mountain_id] as $word_id) {
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $this->addImage($word_id, '-asl');
        }

        $prompt_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'ASL Tree Image Prompt',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $sign_tree_id,
            'correct_answer_word_id' => $tree_id,
            'wrong_answer_word_ids' => [$river_id, $mountain_id],
            'track_answer_word_progress' => true,
        ]);

        $config = [
            'prompt_type' => 'image',
            'option_type' => 'image',
            'learning_supported' => true,
            'self_check_supported' => true,
            'use_titles' => false,
        ];
        $rows = ll_get_words_by_category($prompt_category_name, 'image', [$wordset_id], $config);
        $count = ll_get_words_by_category_count($prompt_category_name, 'image', [$wordset_id], $config);
        $prompt_row = $this->findPromptCardRow((array) $rows, $prompt_card_id);

        $this->assertSame(1, $count);
        $this->assertNotEmpty($prompt_row);
        $this->assertSame($prompt_card_id, (int) ($prompt_row['id'] ?? 0));
        $this->assertSame($tree_id, (int) ($prompt_row['answer_word_id'] ?? 0));
        $this->assertSame($tree_id, (int) ($prompt_row['option_image_source_word_id'] ?? 0));
        $this->assertSame('Tree', (string) ($prompt_row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($prompt_row['image'] ?? ''), 'Prompt image should come from the sign-media word.');
        $this->assertNotEmpty((string) ($prompt_row['answer_image'] ?? ''), 'Answer image should come from the correct answer word.');
        $this->assertNotSame((string) ($prompt_row['image'] ?? ''), (string) ($prompt_row['answer_image'] ?? ''));
        $this->assertGreaterThan(0, (int) ($prompt_row['answer_image_attachment_id'] ?? 0));
        $this->assertSame([$river_id, $mountain_id], $this->normalizeIds((array) ($prompt_row['specific_wrong_answer_ids'] ?? [])));

        $support_rows = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && !empty($row['is_specific_wrong_answer_only'])) {
                $support_rows[(int) ($row['id'] ?? 0)] = $row;
            }
        }
        $this->assertArrayHasKey($river_id, $support_rows);
        $this->assertArrayHasKey($mountain_id, $support_rows);
        $this->assertNotEmpty((string) ($support_rows[$river_id]['image'] ?? ''));
        $this->assertNotEmpty((string) ($support_rows[$mountain_id]['image'] ?? ''));
    }

    public function test_sign_language_prompt_card_support_words_are_not_prompt_targets(): void
    {
        $prompt_category_name = 'ASL Primary Support Questions ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'image', 'image');
        $wordset_id = $this->createWordset('ASL Primary Support Wordset ' . wp_generate_password(5, false));
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $sign_tree_id = $this->createWord($effective_prompt_category_id, 'ASL Tree Sign');
        $tree_id = $this->createWord($effective_prompt_category_id, 'Tree');
        $house_id = $this->createWord($effective_prompt_category_id, 'House');
        foreach ([$sign_tree_id, $tree_id, $house_id] as $word_id) {
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $this->addImage($word_id, '-primary-support');
        }

        $prompt_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'ASL Tree Primary Support',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $sign_tree_id,
            'correct_answer_word_id' => $tree_id,
            'wrong_answer_word_ids' => [$house_id],
            'track_answer_word_progress' => true,
        ]);

        $config = [
            'prompt_type' => 'image',
            'option_type' => 'image',
            'sign_language_mode' => true,
            'use_titles' => false,
        ];
        $rows = ll_get_words_by_category($prompt_category_name, 'image', [$wordset_id], $config);
        $count = ll_get_words_by_category_count($prompt_category_name, 'image', [$wordset_id], $config);
        $prompt_row = $this->findPromptCardRow((array) $rows, $prompt_card_id);

        $rows_by_id = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !empty($row['is_prompt_card'])) {
                continue;
            }
            $rows_by_id[(int) ($row['id'] ?? 0)] = $row;
        }

        $this->assertSame(1, $count);
        $this->assertNotEmpty($prompt_row);
        $this->assertFalse((bool) ($prompt_row['is_prompt_card_support_only'] ?? true));
        $this->assertSame([], (array) ($prompt_row['prompt_card_support_roles'] ?? []));

        $answer_row = $rows_by_id[$tree_id] ?? [];
        $this->assertTrue((bool) ($answer_row['is_prompt_card_support_only'] ?? false));
        $this->assertTrue((bool) ($answer_row['is_prompt_card_answer_option_support'] ?? false));
        $this->assertFalse((bool) ($answer_row['is_prompt_card_prompt_image_support'] ?? true));
        $this->assertSame([$prompt_card_id], $this->normalizeIds((array) ($answer_row['prompt_card_support_owner_ids'] ?? [])));
        $this->assertSame(['correct'], array_values((array) ($answer_row['prompt_card_support_roles'] ?? [])));

        $prompt_image_row = $rows_by_id[$sign_tree_id] ?? [];
        $this->assertTrue((bool) ($prompt_image_row['is_prompt_card_support_only'] ?? false));
        $this->assertFalse((bool) ($prompt_image_row['is_prompt_card_answer_option_support'] ?? true));
        $this->assertTrue((bool) ($prompt_image_row['is_prompt_card_prompt_image_support'] ?? false));
        $this->assertSame([$prompt_card_id], $this->normalizeIds((array) ($prompt_image_row['prompt_card_support_owner_ids'] ?? [])));
        $this->assertSame(['prompt'], array_values((array) ($prompt_image_row['prompt_card_support_roles'] ?? [])));
    }

    public function test_imported_support_word_image_link_bumps_prompt_card_category_cache(): void
    {
        $prompt_category_name = 'ASL Text Prompt Cards ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'image', 'text_title');
        $wordset_id = $this->createWordset('ASL Prompt Media Wordset ' . wp_generate_password(5, false));
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $prompt_word_id = $this->createWordWithoutCategory('ASL Have To Sign', 'asl-have-to-sign');
        $answer_word_id = $this->createWordWithoutCategory('have to', 'asl-have-to-answer');
        wp_set_post_terms($prompt_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($answer_word_id, [$wordset_id], 'wordset', false);

        $prompt_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'ASL Have To Text Prompt',
            'prompt_text' => 'Choose the matching text.',
            'prompt_image_word_id' => $prompt_word_id,
            'correct_answer_word_id' => $answer_word_id,
            'track_answer_word_progress' => true,
        ]);

        $config = [
            'prompt_type' => 'image',
            'option_type' => 'text_title',
            'sign_language_mode' => true,
            'use_titles' => true,
        ];
        $primed_rows = ll_get_words_by_category($prompt_category_name, 'text_title', [$wordset_id], $config);
        $primed_count = ll_get_words_by_category_count($prompt_category_name, 'text_title', [$wordset_id], $config);

        $this->assertSame(0, $primed_count);
        $this->assertSame([], $this->findPromptCardRow((array) $primed_rows, $prompt_card_id));

        $before_version = ll_tools_get_category_cache_version($effective_prompt_category_id);
        $word_image_id = $this->createWordImage('asl-have-to-sign-image', $wordset_id);
        $result = ll_tools_import_job_default_result();
        $word_state = ll_tools_import_default_word_import_state();

        ll_tools_import_upsert_words_chunk(
            [[
                'slug' => 'asl-have-to-sign',
                'title' => 'ASL Have To Sign',
                'status' => 'publish',
                'categories' => [],
                'linked_word_image_slug' => 'asl-have-to-sign-image',
            ]],
            '',
            [],
            [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordset_id,
                'word_image_slug_to_id' => [
                    'asl-have-to-sign-image' => $word_image_id,
                ],
            ],
            $word_state,
            $result
        );

        $this->assertGreaterThan($before_version, ll_tools_get_category_cache_version($effective_prompt_category_id));

        $updated_rows = ll_get_words_by_category($prompt_category_name, 'text_title', [$wordset_id], $config);
        $updated_count = ll_get_words_by_category_count($prompt_category_name, 'text_title', [$wordset_id], $config);
        $prompt_row = $this->findPromptCardRow((array) $updated_rows, $prompt_card_id);

        $this->assertSame(1, $updated_count);
        $this->assertNotEmpty($prompt_row);
        $this->assertSame($prompt_card_id, (int) ($prompt_row['id'] ?? 0));
        $this->assertSame($answer_word_id, (int) ($prompt_row['answer_word_id'] ?? 0));
        $this->assertSame($answer_word_id, (int) ($prompt_row['progress_word_id'] ?? 0));
        $this->assertNotEmpty((string) ($prompt_row['image'] ?? ''), 'Prompt image should resolve from the linked support-word image.');
        $this->assertTrue(ll_tools_word_has_effective_image($prompt_word_id, true));
    }

    public function test_prompt_card_admin_save_invalidates_cached_wrong_answer_payload(): void
    {
        $asset_category_id = $this->createCategory('Prompt Card Cache Assets ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $prompt_category_name = 'Prompt Card Cache Questions ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'text_title', 'text_title');
        $wordset_id = $this->createWordset('Prompt Card Cache Wordset ' . wp_generate_password(5, false));
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $answer_id = $this->createWord($asset_category_id, 'Cache Answer');
        $wrong_one_id = $this->createWord($asset_category_id, 'Cache Wrong One');
        $wrong_two_id = $this->createWord($asset_category_id, 'Cache Wrong Two');
        $wrong_three_id = $this->createWord($asset_category_id, 'Cache Wrong Three');
        foreach ([$answer_id, $wrong_one_id, $wrong_two_id, $wrong_three_id] as $word_id) {
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        $prompt_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Cached Wrong Answers',
            'prompt_text' => 'Choose the cached answer.',
            'correct_answer_word_id' => $answer_id,
            'wrong_answer_word_ids' => [$wrong_one_id, $wrong_two_id, $wrong_three_id],
            'track_answer_word_progress' => true,
        ]);

        $config = [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
        ];

        $primed_rows = ll_get_words_by_category($prompt_category_name, 'text_title', [$wordset_id], $config);
        $primed_prompt_row = $this->findPromptCardRow((array) $primed_rows, $prompt_card_id);
        $this->assertSame(
            [$wrong_one_id, $wrong_two_id, $wrong_three_id],
            $this->normalizeIds((array) ($primed_prompt_row['specific_wrong_answer_ids'] ?? []))
        );

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $post = get_post($prompt_card_id);
        $this->assertInstanceOf(WP_Post::class, $post);

        $post_backup = $_POST;
        try {
            $_POST = [
                'll_tools_prompt_card_nonce' => wp_create_nonce('ll_tools_prompt_card_save'),
                'll_prompt_card_prompt_text' => 'Choose the updated cached answer.',
                'll_prompt_card_prompt_audio_attachment_id' => '0',
                'll_prompt_card_prompt_audio_url' => '',
                'll_prompt_card_prompt_image_word_id' => '0',
                'll_prompt_card_correct_answer_word_id' => (string) $answer_id,
                'll_prompt_card_wrong_answer_word_ids' => $wrong_one_id . ', ' . $wrong_three_id,
                'll_prompt_card_track_answer_word_progress' => '1',
            ];
            ll_tools_prompt_card_save_post($prompt_card_id, $post);
        } finally {
            $_POST = $post_backup;
        }

        $updated_rows = ll_get_words_by_category($prompt_category_name, 'text_title', [$wordset_id], $config);
        $updated_prompt_row = $this->findPromptCardRow((array) $updated_rows, $prompt_card_id);

        $this->assertSame(
            [$wrong_one_id, $wrong_three_id],
            $this->normalizeIds((array) ($updated_prompt_row['specific_wrong_answer_ids'] ?? []))
        );
    }

    public function test_prompt_card_rest_route_invalidates_cached_wrong_answer_payload_and_allows_clearing(): void
    {
        $fixture = $this->createPromptCardFixture();
        $term = get_term($fixture['effective_prompt_category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $config = ll_tools_get_category_quiz_config($term);
        $primed_rows = ll_get_words_by_category($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);
        $primed_prompt_row = $this->findPromptCardRow((array) $primed_rows, (int) $fixture['horse_or_cow_card_id']);
        $this->assertSame([$fixture['cow_id']], $this->normalizeIds((array) ($primed_prompt_row['specific_wrong_answer_ids'] ?? [])));

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $update = $this->dispatchLlToolsRestRequest('POST', '/ll-tools/v1/wordsets/' . $fixture['wordset_id'] . '/prompt-cards', [
            'prompt_card_id' => $fixture['horse_or_cow_card_id'],
            'prompt_text' => 'Updated REST prompt question.',
            'prompt_audio_url' => 'https://example.com/rest-updated-prompt.mp3',
            'prompt_image_word_id' => $fixture['horse_id'],
            'correct_answer_word_id' => $fixture['horse_id'],
            'wrong_answer_word_ids' => [$fixture['cow_id'], $fixture['no_id']],
            'category_ids' => [$fixture['effective_prompt_category_id']],
            'wordset_ids' => [$fixture['wordset_id']],
            'track_answer_word_progress' => false,
        ]);

        $this->assertSame(200, $update->get_status());
        $update_data = $update->get_data();
        $this->assertIsArray($update_data);
        $changed_keys = array_values(array_map('strval', (array) ($update_data['changed_keys'] ?? [])));
        $this->assertContains('prompt_text', $changed_keys);
        $this->assertContains('wrong_answer_word_ids', $changed_keys);
        $this->assertContains('track_answer_word_progress', $changed_keys);

        $updated_rows = ll_get_words_by_category($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);
        $updated_prompt_row = $this->findPromptCardRow((array) $updated_rows, (int) $fixture['horse_or_cow_card_id']);
        $this->assertSame([$fixture['cow_id'], $fixture['no_id']], $this->normalizeIds((array) ($updated_prompt_row['specific_wrong_answer_ids'] ?? [])));
        $this->assertSame('Updated REST prompt question.', (string) ($updated_prompt_row['prompt_label'] ?? ''));
        $this->assertFalse((bool) ($updated_prompt_row['track_answer_word_progress'] ?? true));

        $clear = $this->dispatchLlToolsRestRequest('POST', '/ll-tools/v1/wordsets/' . $fixture['wordset_id'] . '/prompt-cards', [
            'prompt_card_id' => $fixture['horse_or_cow_card_id'],
            'wrong_answer_word_ids' => [],
        ]);
        $this->assertSame(200, $clear->get_status());

        $cleared_rows = ll_get_words_by_category($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);
        $cleared_prompt_row = $this->findPromptCardRow((array) $cleared_rows, (int) $fixture['horse_or_cow_card_id']);
        $this->assertSame([], $this->normalizeIds((array) ($cleared_prompt_row['specific_wrong_answer_ids'] ?? [])));
    }

    public function test_prompt_card_progress_batches_support_wordless_cards_and_scoped_reset(): void
    {
        $fixture = $this->createPromptCardFixture();
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        $stats = ll_tools_process_progress_events_batch($user_id, [
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => 0,
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'payload' => [
                    'prompt_card_id' => $fixture['is_this_horse_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => 0,
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'prompt_card_id' => $fixture['is_this_horse_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => $fixture['horse_id'],
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'payload' => [
                    'prompt_card_id' => $fixture['horse_or_cow_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $fixture['horse_id'],
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'prompt_card_id' => $fixture['horse_or_cow_card_id'],
                ],
            ],
        ]);

        $this->assertSame(4, (int) ($stats['processed'] ?? 0));

        $prompt_progress = ll_tools_get_user_prompt_card_progress($user_id);
        $this->assertArrayHasKey($fixture['is_this_horse_card_id'], $prompt_progress);
        $this->assertArrayHasKey($fixture['horse_or_cow_card_id'], $prompt_progress);
        $this->assertSame(1, (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['exposure_total'] ?? 0));
        $this->assertSame(1, (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['correct_clean'] ?? 0));
        $this->assertSame($fixture['effective_prompt_category_id'], (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['category_id'] ?? 0));
        $this->assertSame($fixture['wordset_id'], (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['wordset_id'] ?? 0));

        $word_rows = ll_tools_get_user_word_progress_rows($user_id, [$fixture['horse_id'], $fixture['yes_id']]);
        $this->assertArrayHasKey($fixture['horse_id'], $word_rows);
        $this->assertArrayNotHasKey($fixture['yes_id'], $word_rows, 'Yes/no prompt cards should not create answer-word progress rows when tracking is disabled.');
        $this->assertSame(1, (int) ($word_rows[$fixture['horse_id']]['correct_clean'] ?? 0));

        $summary = ll_tools_get_prompt_card_progress_summary_by_category($user_id, $fixture['wordset_id'], [$fixture['effective_prompt_category_id']]);
        $this->assertSame(2, (int) ($summary[$fixture['effective_prompt_category_id']]['total'] ?? 0));
        $this->assertSame(2, (int) ($summary[$fixture['effective_prompt_category_id']]['studied'] ?? 0));
        $this->assertSame(0, (int) ($summary[$fixture['effective_prompt_category_id']]['mastered'] ?? 0));

        $reset = ll_tools_reset_user_progress($user_id, [
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => [$fixture['effective_prompt_category_id']],
        ]);

        $this->assertSame(2, (int) ($reset['cleared_prompt_card_meta_entries'] ?? 0));
        $this->assertSame([], ll_tools_get_user_prompt_card_progress($user_id));
        $this->assertSame([], ll_tools_get_user_word_progress_rows($user_id, [$fixture['horse_id']]));
    }

    /**
     * @return array<string,int|string>
     */
    private function createPromptCardFixture(): array
    {
        $asset_category_id = $this->createCategory('Prompt Card Assets ' . wp_generate_password(5, false), 'image_audio', 'audio');
        $prompt_category_name = 'Prompt Card Questions ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'image_audio', 'audio');
        $wordset_id = $this->createWordset('Prompt Card Wordset ' . wp_generate_password(5, false));
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $horse_id = $this->createWord($asset_category_id, 'Horse');
        $cow_id = $this->createWord($asset_category_id, 'Cow');
        $yes_id = $this->createWord($asset_category_id, 'Yes');
        $no_id = $this->createWord($asset_category_id, 'No');

        $this->addImage($horse_id, '-horse');
        $this->addAudio($horse_id, '-horse');
        $this->addAudio($cow_id, '-cow');
        $this->addAudio($yes_id, '-yes');
        $this->addAudio($no_id, '-no');

        $horse_or_cow_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Horse Or Cow',
            'prompt_text' => 'Is this a horse or a cow?',
            'prompt_audio_url' => 'https://example.com/prompt-horse-or-cow.mp3',
            'prompt_image_word_id' => $horse_id,
            'correct_answer_word_id' => $horse_id,
            'wrong_answer_word_ids' => [$cow_id],
            'track_answer_word_progress' => true,
        ]);

        $is_this_horse_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Is This A Horse',
            'prompt_text' => 'Is this a horse?',
            'prompt_audio_url' => 'https://example.com/prompt-is-this-a-horse.mp3',
            'prompt_image_word_id' => $horse_id,
            'correct_answer_word_id' => $yes_id,
            'wrong_answer_word_ids' => [$no_id],
            'track_answer_word_progress' => false,
        ]);

        return [
            'asset_category_id' => $asset_category_id,
            'prompt_category_id' => $prompt_category_id,
            'effective_prompt_category_id' => $effective_prompt_category_id,
            'prompt_category_name' => $prompt_category_name,
            'wordset_id' => $wordset_id,
            'horse_id' => $horse_id,
            'cow_id' => $cow_id,
            'yes_id' => $yes_id,
            'no_id' => $no_id,
            'horse_or_cow_card_id' => $horse_or_cow_card_id,
            'is_this_horse_card_id' => $is_this_horse_card_id,
        ];
    }

    private function createCategory(string $name, string $prompt_type, string $option_type): int
    {
        $term = wp_insert_term($name, 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $category_id = (int) $term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($category_id, 'll_quiz_option_type', $option_type);

        return $category_id;
    }

    private function createWordset(string $name): int
    {
        $term = wp_insert_term($name, 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createWord(int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        return (int) $word_id;
    }

    private function createWordWithoutCategory(string $title, string $slug): int
    {
        return (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
        ]);
    }

    private function addAudio(int $word_id, string $suffix = ''): void
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $suffix,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/prompt-card-' . $word_id . $suffix . '.mp3');
    }

    private function addImage(int $word_id, string $suffix = ''): void
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/04/prompt-card-' . $word_id . $suffix . '.jpg');
        set_post_thumbnail($word_id, $attachment_id);
    }

    private function createWordImage(string $slug, int $wordset_id): int
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $slug,
            'post_mime_type' => 'image/webp',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/05/' . $slug . '.webp');

        $word_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Word Image ' . $slug,
            'post_name' => $slug,
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);
        if (function_exists('ll_tools_set_word_image_wordset_owner')) {
            ll_tools_set_word_image_wordset_owner($word_image_id, $wordset_id, $word_image_id);
        }

        return (int) $word_image_id;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function createPromptCard(int $category_id, int $wordset_id, array $args): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => (string) ($args['title'] ?? 'Prompt Card'),
        ]);

        wp_set_post_terms($post_id, [$category_id], 'word-category', false);
        wp_set_post_terms($post_id, [$wordset_id], 'wordset', false);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, (string) ($args['prompt_text'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, (string) ($args['prompt_audio_url'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, !empty($args['track_answer_word_progress']) ? 1 : 0);

        return (int) $post_id;
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

    private function findPromptCardRow(array $rows, int $prompt_card_id): array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['is_prompt_card']) && (int) ($row['prompt_card_id'] ?? 0) === $prompt_card_id) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatchLlToolsRestRequest(string $method, string $route, array $params = []): WP_REST_Response
    {
        $rest_route_backup = $_GET['rest_route'] ?? null;
        $_GET['rest_route'] = $route;

        try {
            $request = new WP_REST_Request($method, $route);
            foreach ($params as $key => $value) {
                $request->set_param($key, $value);
            }

            $response = rest_get_server()->dispatch($request);
            $this->assertNotWPError($response);

            return rest_ensure_response($response);
        } finally {
            if ($rest_route_backup === null) {
                unset($_GET['rest_route']);
            } else {
                $_GET['rest_route'] = $rest_route_backup;
            }
        }
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function normalizeIds(array $values): array
    {
        $ids = array_values(array_filter(array_map('intval', $values), static function (int $id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
