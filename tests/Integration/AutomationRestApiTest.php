<?php
declare(strict_types=1);

final class AutomationRestApiTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

    /** @var array<string,mixed> */
    private array $server_backup = [];

    /** @var array<string,mixed> */
    private array $get_backup = [];

    protected function tearDown(): void
    {
        $this->restore_request_state();
        if (function_exists('ll_tools_rest_automation_clear_auth_runtime_state')) {
            ll_tools_rest_automation_clear_auth_runtime_state();
        }
        if (function_exists('ll_tools_rest_resource_guard_clear_state')) {
            ll_tools_rest_resource_guard_clear_state();
        }
        parent::tearDown();
    }

    public function test_status_endpoint_accepts_basic_password_auth_for_temp_admin_workflow(): void
    {
        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => 'lltools-rest-admin',
            'user_pass' => 'TempPass!234',
        ]);

        $response = $this->dispatch_ll_tools_rest_request(
            'GET',
            '/ll-tools/v1/automation/status',
            [],
            [
                'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('lltools-rest-admin:TempPass!234'),
                'HTTP_HOST' => '127.0.0.1:10036',
            ],
            true
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('basic_password', (string) ($data['auth_mode'] ?? ''));
        $this->assertSame($admin_id, (int) (($data['user']['id'] ?? 0)));
        $this->assertTrue(!empty($data['capabilities']['view_ll_tools']));
        $this->assertTrue(!empty($data['capabilities']['purge_static_cache']));
        $this->assertSame('/ll-tools/v1/cache/static/purge', (string) ($data['routes']['static_cache_purge'] ?? ''));
        $this->assertSame('/ll-tools/v1/corpus-texts/asset', (string) ($data['routes']['corpus_text_asset'] ?? ''));
        $this->assertSame('/ll-tools/v1/corpus-texts/import', (string) ($data['routes']['corpus_text_import'] ?? ''));
        $this->assertSame('/ll-tools/v1/corpus-texts/{slug}', (string) ($data['routes']['corpus_text'] ?? ''));
        $this->assertSame('/ll-tools/v1/wordsets/{wordset}/orthography-conversion', (string) ($data['routes']['orthography_conversion'] ?? ''));
        $this->assertSame('/ll-tools/v1/wordsets/{wordset}/word-title-updates', (string) ($data['routes']['word_title_updates'] ?? ''));
        $this->assertSame('/ll-tools/v1/wordsets/{wordset}/word-helper-updates', (string) ($data['routes']['word_helper_updates'] ?? ''));
    }

    public function test_static_cache_purge_route_requires_admin_and_deletes_cache_files(): void
    {
        $dictionary_dir = ll_tools_dictionary_static_cache_dir();
        $public_dir = ll_tools_public_static_cache_dir();
        $this->assertNotSame('', $dictionary_dir);
        $this->assertNotSame('', $public_dir);
        $this->assertTrue(wp_mkdir_p($dictionary_dir));
        $this->assertTrue(wp_mkdir_p($public_dir));
        ll_tools_purge_static_caches();

        $dictionary_file = trailingslashit($dictionary_dir) . 'dictionary-rest-test.html';
        $public_file = trailingslashit($public_dir) . 'public-rest-test.html';
        file_put_contents($dictionary_file, '<!doctype html><html><body>dictionary rest</body></html>');
        file_put_contents($public_file, '<!doctype html><html><body>public rest</body></html>');
        ll_tools_public_static_cache_write_meta($public_file, 'rest-test', [
            'type' => 'wordset_main',
            'id' => 17,
            'path' => '/rest-test',
            'wordset_id' => 17,
        ]);

        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);
        wp_set_current_user($viewer_id);

        $blocked = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/cache/static/purge');
        $this->assertSame(403, $blocked->get_status());
        $this->assertFileExists($dictionary_file);
        $this->assertFileExists($public_file);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $purged = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/cache/static/purge');
        $this->assertSame(200, $purged->get_status());
        $data = $purged->get_data();
        $this->assertIsArray($data);
        $this->assertTrue((bool) ($data['purged'] ?? false));
        $this->assertSame('all', (string) ($data['target'] ?? ''));
        $this->assertSame(2, (int) ($data['deleted'] ?? 0));
        $this->assertFileDoesNotExist($dictionary_file);
        $this->assertFileDoesNotExist($public_file);
    }

    public function test_wordset_scoped_endpoints_allow_assigned_manager_and_block_other_wordsets(): void
    {
        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $allowed_wordset_id = $this->ensure_term('wordset', 'Managed REST Wordset', 'managed-rest-wordset');
        $blocked_wordset_id = $this->ensure_term('wordset', 'Blocked REST Wordset', 'blocked-rest-wordset');

        $category_id = $this->ensure_term('word-category', 'Managed REST Category', 'managed-rest-category');
        $this->create_word($allowed_wordset_id, [$category_id], 'Manager Visible Word', 'Translation One');
        $this->create_word($blocked_wordset_id, [$category_id], 'Manager Hidden Word', 'Translation Two');

        $this->assertTrue(function_exists('ll_tools_cli_assign_wordset_manager'));
        ll_tools_cli_assign_wordset_manager($allowed_wordset_id, $manager_id);

        wp_set_current_user($manager_id);

        $allowed = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/managed-rest-wordset/report');
        $this->assertSame(200, $allowed->get_status());
        $allowed_data = $allowed->get_data();
        $this->assertIsArray($allowed_data);
        $this->assertSame($allowed_wordset_id, (int) (($allowed_data['wordset']['id'] ?? 0)));

        $blocked = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/blocked-rest-wordset/report');
        $this->assertSame(403, $blocked->get_status());
    }

    public function test_wordset_profile_rest_route_updates_thumbnail_blurb_and_language_code(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Profile Wordset', 'rest-profile-wordset');
        $attachment_id = $this->create_image_attachment('rest-profile-thumbnail.png');

        wp_set_current_user($admin_id);

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-profile-wordset/profile', [
            'profile_image_attachment_id' => $attachment_id,
            'language_code' => 'he',
            'profile_blurb' => "Complements Aleph with Beth videos.\nIncludes early reading practice.",
        ]);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertTrue((bool) ($data['changed'] ?? false));
        $this->assertContains('profile_image_attachment_id', (array) ($data['changed_keys'] ?? []));
        $this->assertContains('profile_blurb', (array) ($data['changed_keys'] ?? []));
        $this->assertContains('language_code', (array) ($data['changed_keys'] ?? []));
        $this->assertSame($attachment_id, (int) get_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, true));
        $this->assertSame('he', (string) get_term_meta($wordset_id, 'll_language', true));
        $this->assertSame("Complements Aleph with Beth videos.\nIncludes early reading practice.", (string) get_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, true));

        $read = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-profile-wordset/profile');
        $this->assertSame(200, $read->get_status());
        $read_data = $read->get_data();
        $this->assertIsArray($read_data);
        $this->assertSame($attachment_id, (int) ($read_data['profile_image_attachment_id'] ?? 0));
        $this->assertSame('he', (string) ($read_data['language_code'] ?? ''));
        $this->assertSame("Complements Aleph with Beth videos.\nIncludes early reading practice.", (string) ($read_data['profile_blurb'] ?? ''));
    }

    public function test_wordset_translations_rest_route_updates_locale_entity_text(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Translation Wordset', 'rest-translation-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Translation Category', 'rest-translation-category');
        $this->create_word($wordset_id, [$category_id], 'REST Translation Word', 'Original translation');
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Original Content Lesson',
            'post_excerpt' => 'Original content lesson excerpt.',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');

        wp_set_current_user($admin_id);

        $payload = [
            'locale' => 'tr_TR',
            'wordset_translation' => [
                'name' => 'Turkish Wordset Name',
                'profile_blurb' => "Turkish learner intro.\nSecond line.",
            ],
            'categories' => [
                [
                    'id' => $category_id,
                    'name' => 'Turkish Category Name',
                ],
            ],
            'lessons' => [
                [
                    'id' => $lesson_id,
                    'title' => 'Turkish Lesson Name',
                    'excerpt' => 'Turkish lesson excerpt.',
                ],
            ],
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-translation-wordset/translations', $payload + [
            'dry_run' => true,
        ]);
        $this->assertSame(200, $dry_run->get_status());
        $dry_data = $dry_run->get_data();
        $this->assertIsArray($dry_data);
        $this->assertSame(3, (int) ($dry_data['changed_count'] ?? 0));
        $this->assertSame([], ll_tools_get_entity_translations('term', $wordset_id));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-translation-wordset/translations', $payload);
        $this->assertSame(200, $update->get_status());
        $update_data = $update->get_data();
        $this->assertIsArray($update_data);
        $this->assertSame(3, (int) ($update_data['changed_count'] ?? 0));
        $translated_category_id = $category_id;
        foreach ((array) ($update_data['results'] ?? []) as $result) {
            if (is_array($result) && (string) ($result['entity_type'] ?? '') === 'category') {
                $translated_category_id = (int) ($result['object_id'] ?? $category_id);
                break;
            }
        }

        $wordset = get_term($wordset_id, 'wordset');
        $category = get_term($translated_category_id, 'word-category');
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->assertInstanceOf(WP_Term::class, $category);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $this->assertSame('Turkish Wordset Name', ll_tools_get_wordset_display_name($wordset, ['locale' => 'tr_TR']));
        $this->assertSame("Turkish learner intro.\nSecond line.", ll_tools_get_wordset_profile_blurb($wordset_id, [
            'use_locale' => true,
            'locale' => 'tr_TR',
        ]));
        $this->assertSame('Turkish Category Name', ll_tools_get_category_display_name($category, [
            'wordset_ids' => [$wordset_id],
            'site_language' => 'tr_TR',
        ]));
        $this->assertSame('Turkish Lesson Name', ll_tools_get_lesson_display_title($lesson, [
            'locale' => 'tr_TR',
            'fallback' => 'Original Content Lesson',
        ]));
        $this->assertSame('Turkish lesson excerpt.', ll_tools_get_lesson_display_excerpt($lesson, 'Original content lesson excerpt.', [
            'locale' => 'tr_TR',
        ]));

        $read = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-translation-wordset/translations', [
            'locale' => 'tr_TR',
        ]);
        $this->assertSame(200, $read->get_status());
        $read_data = $read->get_data();
        $this->assertIsArray($read_data);
        $this->assertSame('Turkish Wordset Name', (string) ($read_data['wordset']['display_name'] ?? ''));
        $this->assertSame("Turkish learner intro.\nSecond line.", (string) ($read_data['wordset']['display_profile_blurb'] ?? ''));
        $this->assertSame('Turkish Category Name', (string) ($read_data['categories'][0]['display_name'] ?? ''));
        $this->assertSame('Turkish Lesson Name', (string) ($read_data['lessons'][0]['display_title'] ?? ''));
    }

    public function test_orthography_conversion_rest_route_sets_manual_rules_settings_and_exceptions(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Orthography Wordset', 'rest-orthography-wordset');
        if (function_exists('ll_tools_register_dictionary_entry_post_type')) {
            ll_tools_register_dictionary_entry_post_type();
        }
        $exception_entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'REST Orthography Exception Entry',
        ]);
        wp_set_current_user($admin_id);

        $payload = [
            'manual_rules' => [
                'x' => [
                    'any' => 'q',
                ],
                'n' => [
                    'any' => 'n',
                ],
                "\u{0127}" => [
                    'any' => "'h",
                ],
            ],
            'orthography_settings' => [
                'word_overrides' => [
                    'sq' => 'se',
                ],
                'phrase_overrides' => [
                    [
                        'from' => ['des', 'erzen'],
                        'to' => ['dest', 'erzen'],
                    ],
                ],
                'optional_matches' => [
                    [
                        'ipa' => 'x',
                        'orthography' => 'q',
                    ],
                ],
                'recording_type_punctuation' => [
                    'question' => '?',
                ],
                'sentence_case' => true,
            ],
            'exception_word_ids' => [77, 12, 77],
            'exception_dictionary_entry_ids' => [
                12 => $exception_entry_id,
                77 => 999999,
            ],
            'replace_manual_rules' => true,
            'replace_orthography_settings' => true,
            'rescan_validations' => false,
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request(
            'POST',
            '/ll-tools/v1/wordsets/rest-orthography-wordset/orthography-conversion',
            array_merge($payload, ['dry_run' => true])
        );
        $this->assertSame(200, $dry_run->get_status());
        $dry_data = $dry_run->get_data();
        $this->assertIsArray($dry_data);
        $this->assertTrue((bool) ($dry_data['changed'] ?? false));
        $this->assertSame('', (string) get_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key(), true));
        $this->assertSame('', (string) get_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key(), true));
        $this->assertSame('', (string) get_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(), true));

        $update = $this->dispatch_ll_tools_rest_request(
            'POST',
            '/ll-tools/v1/wordsets/rest-orthography-wordset/orthography-conversion',
            $payload
        );
        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertContains('manual_rules', (array) ($data['changed_keys'] ?? []));
        $this->assertContains('orthography_settings', (array) ($data['changed_keys'] ?? []));
        $this->assertContains('exception_word_ids', (array) ($data['changed_keys'] ?? []));
        $this->assertContains('exception_dictionary_entry_ids', (array) ($data['changed_keys'] ?? []));

        $read = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-orthography-wordset/orthography-conversion');
        $this->assertSame(200, $read->get_status());
        $read_data = $read->get_data();
        $this->assertIsArray($read_data);
        $this->assertSame('', (string) ($read_data['profile_key'] ?? ''));
        $this->assertSame('q', (string) ($read_data['manual_rules']['x']['any'] ?? ''));
        $this->assertSame('n', (string) ($read_data['manual_rules']['n']['any'] ?? ''));
        $this->assertSame("'h", (string) ($read_data['manual_rules']["\u{0127}"]['any'] ?? ''));
        $this->assertSame('se', (string) ($read_data['orthography_settings']['word_overrides']['sq'] ?? ''));
        $this->assertSame(['des', 'erzen'], (array) ($read_data['orthography_settings']['phrase_overrides'][0]['from'] ?? []));
        $this->assertSame(['dest', 'erzen'], (array) ($read_data['orthography_settings']['phrase_overrides'][0]['to'] ?? []));
        $this->assertSame('x', (string) ($read_data['orthography_settings']['optional_matches'][0]['ipa'] ?? ''));
        $this->assertSame('q', (string) ($read_data['orthography_settings']['optional_matches'][0]['orthography'] ?? ''));
        $this->assertSame('?', (string) ($read_data['orthography_settings']['recording_type_punctuation']['question'] ?? ''));
        $this->assertTrue((bool) ($read_data['orthography_settings']['sentence_case'] ?? false));
        $this->assertSame([12, 77], array_map('intval', (array) ($read_data['exception_word_ids'] ?? [])));
        $this->assertSame($exception_entry_id, (int) (($read_data['exception_dictionary_entry_ids'][12] ?? 0)));
        $this->assertArrayNotHasKey(77, (array) ($read_data['exception_dictionary_entry_ids'] ?? []));

        $bind_update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-orthography-wordset/orthography-conversion', [
            'orthography_settings' => [
                'word_overrides' => [
                    [
                        'from' => 'sq',
                        'to' => 'se',
                        'dictionary_entry_id' => $exception_entry_id,
                    ],
                ],
            ],
            'rescan_validations' => false,
        ]);
        $this->assertSame(200, $bind_update->get_status());
        $bind_data = $bind_update->get_data();
        $this->assertIsArray($bind_data);
        $this->assertContains('orthography_settings', (array) ($bind_data['changed_keys'] ?? []));

        $bound_read = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-orthography-wordset/orthography-conversion');
        $this->assertSame(200, $bound_read->get_status());
        $bound_data = $bound_read->get_data();
        $this->assertIsArray($bound_data);
        $this->assertSame($exception_entry_id, (int) ($bound_data['orthography_settings']['word_override_entry_ids']['sq'] ?? 0));
    }

    public function test_create_wordset_route_can_clone_template_and_assign_manager(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $template_id = $this->ensure_term('wordset', 'REST Template Source', 'rest-template-source');

        wp_set_current_user($admin_id);

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets', [
            'name' => 'REST Template Clone',
            'slug' => 'rest-template-clone',
            'template' => 'rest-template-source',
            'manager' => (string) $manager_id,
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);

        $created_wordset_id = (int) ($data['wordset_id'] ?? 0);
        $this->assertGreaterThan(0, $created_wordset_id);
        $this->assertSame('rest-template-clone', (string) ($data['wordset_slug'] ?? ''));
        $this->assertSame($template_id, (int) ($data['template_wordset_id'] ?? 0));
        $this->assertSame($manager_id, (int) get_term_meta($created_wordset_id, 'manager_user_id', true));

        $managed_wordsets = array_map('intval', (array) get_user_meta($manager_id, 'managed_wordsets', true));
        $this->assertContains($created_wordset_id, $managed_wordsets);
    }

    public function test_bulk_update_route_supports_dry_run_and_resume_state(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Bulk Update Wordset', 'rest-bulk-update-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Bulk Update Category', 'rest-bulk-update-category');
        $this->ensure_term('part_of_speech', 'Noun', 'noun');

        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Bulk Update Word', 'Bulk Translation');

        wp_set_current_user($admin_id);

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-bulk-update-wordset/bulk-update', [
            'set' => [
                'field' => 'part_of_speech',
                'value' => 'noun',
            ],
            'where_missing' => ['part_of_speech'],
            'dry_run' => true,
        ]);

        $this->assertSame(200, $dry_run->get_status());
        $dry_run_data = $dry_run->get_data();
        $this->assertIsArray($dry_run_data);
        $this->assertSame(1, (int) ($dry_run_data['matched_count'] ?? 0));
        $this->assertSame(0, (int) ($dry_run_data['updated_count'] ?? 0));
        $this->assertSame([], (array) (($dry_run_data['resume_state']['processed_ids'] ?? [])));
        $this->assertSame([], wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'ids']));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-bulk-update-wordset/bulk-update', [
            'set' => [
                'field' => 'part_of_speech',
                'value' => 'noun',
            ],
            'where_missing' => ['part_of_speech'],
        ]);

        $this->assertSame(200, $update->get_status());
        $update_data = $update->get_data();
        $this->assertIsArray($update_data);
        $this->assertSame(1, (int) ($update_data['updated_count'] ?? 0));
        $this->assertContains($word_id, array_map('intval', (array) (($update_data['resume_state']['processed_ids'] ?? []))));

        $assigned_terms = wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'slugs']);
        $this->assertContains('noun', array_map('strval', (array) $assigned_terms));

        $resume = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-bulk-update-wordset/bulk-update', [
            'set' => [
                'field' => 'part_of_speech',
                'value' => 'noun',
            ],
            'resume_state' => $update_data['resume_state'] ?? [],
        ]);

        $this->assertSame(200, $resume->get_status());
        $resume_data = $resume->get_data();
        $this->assertIsArray($resume_data);
        $this->assertSame(0, (int) ($resume_data['matched_count'] ?? 0));
    }

    public function test_bulk_update_route_can_update_word_text(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Word Text Update Wordset', 'rest-word-text-update-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Word Text Update Category', 'rest-word-text-update-category');

        $word_id = $this->create_word($wordset_id, [$category_id], 'Old Word Text', 'Existing Translation');

        wp_set_current_user($admin_id);

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-word-text-update-wordset/bulk-update', [
            'set' => [
                'field' => 'word_text',
                'value' => 'New Word Text',
            ],
            'word' => (string) $word_id,
        ]);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['matched_count'] ?? 0));
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('New Word Text', get_the_title($word_id));
        $this->assertSame('Existing Translation', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertContains('word_text', (array) ($data['updated'][0]['changed_keys'] ?? []));
        $updated = (array) (($data['updated'][0]['after'] ?? []));
        $this->assertSame('New Word Text', (string) ($updated['word_text'] ?? ''));
    }

    public function test_bulk_update_route_can_update_helper_translation_directly(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Helper Translation Wordset', 'rest-helper-translation-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Helper Translation Category', 'rest-helper-translation-category');

        $word_id = $this->create_word($wordset_id, [$category_id], 'Target Title', 'Target Text');
        update_post_meta($word_id, 'word_english_meaning', 'Old Helper');

        wp_set_current_user($admin_id);

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-helper-translation-wordset/bulk-update', [
            'set' => [
                'field' => 'word_english_meaning',
                'value' => 'New Helper',
            ],
            'word' => (string) $word_id,
        ]);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('Target Title', get_the_title($word_id));
        $this->assertSame('Target Text', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertSame('New Helper', (string) get_post_meta($word_id, 'word_english_meaning', true));
        $this->assertContains('word_english_meaning', (array) ($data['updated'][0]['changed_keys'] ?? []));
    }

    public function test_display_text_uses_helper_translation_when_title_role_is_translation(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Display Helper Wordset', 'rest-display-helper-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Display Helper Category', 'rest-display-helper-category');
        update_term_meta(
            $wordset_id,
            defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY') ? LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY : 'll_wordset_word_title_language_role',
            'translation'
        );

        $word_id = $this->create_word($wordset_id, [$category_id], 'Target Title', 'Target Text');
        update_post_meta($word_id, 'word_english_meaning', 'Helper Translation');

        $display = ll_tools_word_grid_resolve_display_text($word_id);

        $this->assertSame('Target Text', (string) ($display['word_text'] ?? ''));
        $this->assertSame('Helper Translation', (string) ($display['translation_text'] ?? ''));
        $this->assertFalse((bool) ($display['store_in_title'] ?? true));
    }

    public function test_bulk_update_route_can_update_word_title_directly(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Word Title Update Wordset', 'rest-word-title-update-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Word Title Update Category', 'rest-word-title-update-category');

        $word_id = $this->create_word($wordset_id, [$category_id], 'Old Post Title', 'Existing Translation');

        wp_set_current_user($admin_id);

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-word-title-update-wordset/bulk-update', [
            'set' => [
                'field' => 'word_title',
                'value' => 'New Post Title',
            ],
            'word' => (string) $word_id,
        ]);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['matched_count'] ?? 0));
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('New Post Title', get_the_title($word_id));
        $this->assertSame('Existing Translation', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertContains('word_title', (array) ($data['updated'][0]['changed_keys'] ?? []));
        $updated = (array) (($data['updated'][0]['after'] ?? []));
        $this->assertSame('New Post Title', (string) ($updated['word_title'] ?? ''));
    }

    public function test_bulk_update_route_can_apply_distinct_word_title_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Distinct Word Updates Wordset', 'rest-distinct-word-updates-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Distinct Word Updates Category', 'rest-distinct-word-updates-category');

        $first_word_id = $this->create_word($wordset_id, [$category_id], 'Old First Title', 'First Translation');
        $second_word_id = $this->create_word($wordset_id, [$category_id], 'Old Second Title', 'Second Translation');

        wp_set_current_user($admin_id);

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-distinct-word-updates-wordset/bulk-update', [
            'updates' => [
                [
                    'word_id' => $first_word_id,
                    'field' => 'word_title',
                    'value' => 'New First Title',
                ],
                [
                    'word_id' => $second_word_id,
                    'set' => [
                        'field' => 'word_title',
                        'value' => 'New Second Title',
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame('updates', (string) ($data['batch_mode'] ?? ''));
        $this->assertSame(2, (int) ($data['matched_count'] ?? 0));
        $this->assertSame(2, (int) ($data['updated_count'] ?? 0));
        $this->assertEmpty((array) ($data['errors'] ?? []));
        $this->assertSame('New First Title', get_the_title($first_word_id));
        $this->assertSame('New Second Title', get_the_title($second_word_id));
    }

    public function test_word_title_updates_route_applies_guarded_fast_title_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Fast Title Wordset', 'rest-fast-title-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Fast Title Category', 'rest-fast-title-category');

        $first_word_id = $this->create_word($wordset_id, [$category_id], 'Old Fast First', 'First Translation');
        $second_word_id = $this->create_word($wordset_id, [$category_id], 'Old Fast Second', 'Second Translation');
        $third_word_id = $this->create_word($wordset_id, [$category_id], 'Already Correct', 'Third Translation');
        $parenthetical_word_id = $this->create_word($wordset_id, [$category_id], 'Old Parent (hint)', 'Parenthetical Translation');
        $blocked_wordset_id = $this->ensure_term('wordset', 'REST Fast Title Blocked Wordset', 'rest-fast-title-blocked-wordset');
        $blocked_word_id = $this->create_word($blocked_wordset_id, [$category_id], 'Blocked Word', 'Blocked Translation');
        $first_slug = (string) get_post_field('post_name', $first_word_id);
        $parenthetical_slug = (string) get_post_field('post_name', $parenthetical_word_id);

        wp_set_current_user($admin_id);

        $payload = [
            'updates' => [
                [
                    'word_id' => $first_word_id,
                    'old_title' => 'Old Fast First',
                    'title' => 'New Fast First',
                ],
                [
                    'word_id' => $second_word_id,
                    'old_title' => 'Wrong Old Title',
                    'title' => 'New Fast Second',
                ],
                [
                    'word_id' => $third_word_id,
                    'old_title' => 'Already Correct',
                    'title' => 'Already Correct',
                ],
                [
                    'word_id' => $parenthetical_word_id,
                    'old_title' => 'Old Parent (hint)',
                    'title' => 'New Parent',
                ],
                [
                    'word_id' => $blocked_word_id,
                    'title' => 'Should Not Change',
                ],
            ],
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-title-wordset/word-title-updates', $payload + [
            'dry_run' => true,
        ]);

        $this->assertSame(200, $dry_run->get_status());
        $dry_data = $dry_run->get_data();
        $this->assertIsArray($dry_data);
        $this->assertSame(4, (int) ($dry_data['matched_count'] ?? 0));
        $this->assertSame(2, (int) ($dry_data['changed_count'] ?? 0));
        $this->assertSame(0, (int) ($dry_data['updated_count'] ?? 0));
        $this->assertSame(1, (int) ($dry_data['unchanged_count'] ?? 0));
        $this->assertSame(2, (int) ($dry_data['skipped_count'] ?? 0));
        $this->assertSame('Old Fast First', get_the_title($first_word_id));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-title-wordset/word-title-updates', $payload);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(2, (int) ($data['changed_count'] ?? 0));
        $this->assertSame(2, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('New Fast First', get_the_title($first_word_id));
        $this->assertSame($first_slug, (string) get_post_field('post_name', $first_word_id));
        $this->assertSame('New Parent', get_the_title($parenthetical_word_id));
        $this->assertSame($parenthetical_slug, (string) get_post_field('post_name', $parenthetical_word_id));
        $this->assertSame('Old Fast Second', get_the_title($second_word_id));
        $this->assertSame('Already Correct', get_the_title($third_word_id));
        $this->assertSame('Blocked Word', get_the_title($blocked_word_id));
        $this->assertSame('old_title_mismatch', (string) ($data['skipped'][0]['reason'] ?? ''));
        $this->assertSame('not_in_wordset', (string) ($data['skipped'][1]['reason'] ?? ''));
    }

    public function test_word_title_updates_route_rejects_oversized_write_batches(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->ensure_term('wordset', 'REST Fast Title Limit Wordset', 'rest-fast-title-limit-wordset');

        wp_set_current_user($admin_id);

        $updates = [];
        for ($index = 0; $index < 11; $index++) {
            $updates[] = [
                'word_id' => 1000 + $index,
                'title' => 'New Title ' . $index,
            ];
        }

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-title-limit-wordset/word-title-updates', [
            'updates' => $updates,
        ]);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertIsArray($data);
        $this->assertSame('ll_tools_rest_too_many_word_title_updates', (string) ($data['code'] ?? ''));
        $this->assertStringContainsString('10', (string) ($data['message'] ?? ''));
    }

    public function test_word_helper_updates_route_updates_translation_meta_with_stale_guards(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Fast Helper Wordset', 'rest-fast-helper-wordset');
        $blocked_wordset_id = $this->ensure_term('wordset', 'REST Blocked Helper Wordset', 'rest-blocked-helper-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Fast Helper Category', 'rest-fast-helper-category');

        $changed_word_id = $this->create_word($wordset_id, [$category_id], 'Helper Changed', 'Old Target');
        $guarded_word_id = $this->create_word($wordset_id, [$category_id], 'Helper Guarded', 'Guarded Target');
        $unchanged_word_id = $this->create_word($wordset_id, [$category_id], 'Helper Same', 'Same Target');
        $blocked_word_id = $this->create_word($blocked_wordset_id, [$category_id], 'Blocked Helper', 'Blocked Target');
        update_post_meta($changed_word_id, 'word_english_meaning', 'Old Helper');
        update_post_meta($guarded_word_id, 'word_english_meaning', 'Guarded Helper');
        update_post_meta($unchanged_word_id, 'word_english_meaning', 'Same Helper');
        update_post_meta($blocked_word_id, 'word_english_meaning', 'Blocked Helper');

        wp_set_current_user($admin_id);

        $payload = [
            'updates' => [
                [
                    'word_id' => $changed_word_id,
                    'old_word_translation' => 'Old Target',
                    'word_translation' => 'New Target',
                    'old_word_english_meaning' => 'Old Helper',
                    'word_english_meaning' => 'New Helper',
                ],
                [
                    'word_id' => $guarded_word_id,
                    'old_helper_translation' => 'Not Current Helper',
                    'word_english_meaning' => 'Should Not Apply',
                ],
                [
                    'word_id' => $unchanged_word_id,
                    'word_translation' => 'Same Target',
                    'word_english_meaning' => 'Same Helper',
                ],
                [
                    'word_id' => $blocked_word_id,
                    'word_english_meaning' => 'Should Not Apply',
                ],
            ],
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-helper-wordset/word-helper-updates', $payload + [
            'dry_run' => true,
        ]);

        $this->assertSame(200, $dry_run->get_status());
        $dry_data = $dry_run->get_data();
        $this->assertIsArray($dry_data);
        $this->assertArrayNotHasKey('matched_rows', $dry_data);
        $this->assertSame('word_helper_updates', (string) ($dry_data['batch_mode'] ?? ''));
        $this->assertSame(3, (int) ($dry_data['matched_count'] ?? 0));
        $this->assertSame(1, (int) ($dry_data['changed_count'] ?? 0));
        $this->assertSame(0, (int) ($dry_data['updated_count'] ?? 0));
        $this->assertSame(1, (int) ($dry_data['unchanged_count'] ?? 0));
        $this->assertSame(2, (int) ($dry_data['skipped_count'] ?? 0));
        $this->assertSame('Old Target', (string) get_post_meta($changed_word_id, 'word_translation', true));
        $this->assertSame('Old Helper', (string) get_post_meta($changed_word_id, 'word_english_meaning', true));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-helper-wordset/word-helper-updates', $payload);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['changed_count'] ?? 0));
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('New Target', (string) get_post_meta($changed_word_id, 'word_translation', true));
        $this->assertSame('New Helper', (string) get_post_meta($changed_word_id, 'word_english_meaning', true));
        $this->assertSame('Guarded Helper', (string) get_post_meta($guarded_word_id, 'word_english_meaning', true));
        $this->assertSame('Blocked Helper', (string) get_post_meta($blocked_word_id, 'word_english_meaning', true));
        $this->assertContains('word_translation', (array) ($data['updated'][0]['changed_keys'] ?? []));
        $this->assertContains('word_english_meaning', (array) ($data['updated'][0]['changed_keys'] ?? []));
        $this->assertSame('old_value_mismatch', (string) ($data['skipped'][0]['reason'] ?? ''));
        $this->assertSame('not_in_wordset', (string) ($data['skipped'][1]['reason'] ?? ''));
    }

    public function test_word_helper_updates_route_links_dictionary_entries_by_id(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Helper Dictionary Wordset', 'rest-helper-dictionary-wordset');
        $blocked_wordset_id = $this->ensure_term('wordset', 'REST Helper Dictionary Blocked Wordset', 'rest-helper-dictionary-blocked-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Helper Dictionary Category', 'rest-helper-dictionary-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Helper Dictionary Word', 'Dictionary Target');
        $blocked_word_id = $this->create_word($blocked_wordset_id, [$category_id], 'Blocked Dictionary Word', 'Blocked Target');
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'Helper Dictionary Entry',
        ]);

        wp_set_current_user($admin_id);

        $payload = [
            'updates' => [
                [
                    'word_id' => $word_id,
                    'old_dictionary_entry_id' => 0,
                    'dictionary_entry_id' => $entry_id,
                ],
                [
                    'word_id' => $blocked_word_id,
                    'dictionary_entry_id' => $entry_id,
                ],
            ],
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-helper-dictionary-wordset/word-helper-updates', $payload + [
            'dry_run' => true,
        ]);

        $this->assertSame(200, $dry_run->get_status());
        $dry_data = $dry_run->get_data();
        $this->assertIsArray($dry_data);
        $this->assertSame(1, (int) ($dry_data['matched_count'] ?? 0));
        $this->assertSame(1, (int) ($dry_data['changed_count'] ?? 0));
        $this->assertSame(0, (int) ($dry_data['updated_count'] ?? 0));
        $this->assertSame(1, (int) ($dry_data['skipped_count'] ?? 0));
        $this->assertSame('not_in_wordset', (string) ($dry_data['skipped'][0]['reason'] ?? ''));
        $this->assertSame(0, ll_tools_get_word_dictionary_entry_id($word_id));
        $this->assertContains('dictionary_entry_id', (array) ($dry_data['updated'][0]['changed_keys'] ?? []));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-helper-dictionary-wordset/word-helper-updates', $payload);

        $this->assertSame(200, $update->get_status());
        $data = $update->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['changed_count'] ?? 0));
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame($entry_id, ll_tools_get_word_dictionary_entry_id($word_id));
        $this->assertSame(0, ll_tools_get_word_dictionary_entry_id($blocked_word_id));
        $this->assertContains('dictionary_entry_id', (array) ($data['updated'][0]['changed_keys'] ?? []));
        $this->assertSame($entry_id, (int) (($data['updated'][0]['after']['dictionary_entry_id'] ?? 0)));
    }

    public function test_word_helper_updates_route_rejects_oversized_write_batches(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->ensure_term('wordset', 'REST Fast Helper Limit Wordset', 'rest-fast-helper-limit-wordset');

        wp_set_current_user($admin_id);

        $updates = [];
        for ($index = 0; $index < 26; $index++) {
            $updates[] = [
                'word_id' => 2000 + $index,
                'word_english_meaning' => 'Helper ' . $index,
            ];
        }

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-fast-helper-limit-wordset/word-helper-updates', [
            'updates' => $updates,
        ]);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertIsArray($data);
        $this->assertSame('ll_tools_rest_too_many_word_helper_updates', (string) ($data['code'] ?? ''));
        $this->assertStringContainsString('25', (string) ($data['message'] ?? ''));
    }

    public function test_transcriptions_route_updates_fields_review_flags_and_review_note(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Transcription Wordset', 'rest-transcription-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Transcription Category', 'rest-transcription-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Transcription Word', 'Translation');
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'REST Transcription Recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'old text');
        update_post_meta($recording_id, 'recording_ipa', 'old.ipa');

        wp_set_current_user($admin_id);

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-transcription-wordset/transcriptions', [
            'updates' => [
                [
                    'recording_id' => $recording_id,
                    'recording_text' => 'new text',
                    'recording_ipa' => 'new.ipa',
                    'review_fields' => ['recording_text', 'recording_ipa'],
                    'review_note' => 'Unsure if this sound is x or y, otherwise high confidence.',
                ],
            ],
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertSame('new text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('new.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_text'));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_ipa'));
        $this->assertSame('Unsure if this sound is x or y, otherwise high confidence.', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));

        $clear = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-transcription-wordset/transcriptions', [
            'recording_id' => $recording_id,
            'needs_review' => false,
            'review_fields' => ['recording_text', 'recording_ipa'],
        ]);

        $this->assertSame(200, $clear->get_status());
        $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));
        $this->assertSame('', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
    }

    public function test_transcriptions_route_dry_run_update_and_review_surfaces_agree(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Transcription Review Sync Wordset', 'rest-transcription-review-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Transcription Review Sync Category', 'rest-transcription-review-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Review Sync Word', 'Review Sync Translation');
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'REST Review Sync Recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'old text');
        update_post_meta($recording_id, 'recording_ipa', 'old.ipa');

        wp_set_current_user($admin_id);

        $update_row = [
            'recording_id' => $recording_id,
            'recording_text' => 'new text',
            'recording_ipa' => 'new.ipa',
            'review_fields' => [
                'recording_text' => true,
                'recording_ipa' => true,
            ],
            'review_note' => 'Review both generated transcription fields.',
        ];

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-transcription-review-sync-wordset/transcriptions', [
            'dry_run' => true,
            'updates' => [$update_row],
        ]);

        $this->assertSame(200, $dry_run->get_status());
        $dry_run_data = $dry_run->get_data();
        $this->assertIsArray($dry_run_data);
        $this->assertTrue((bool) (($dry_run_data['updated'][0]['changed'] ?? false)));
        $this->assertSame(1, (int) ($dry_run_data['updated_count'] ?? 0));
        $dry_run_after = (array) (($dry_run_data['updated'][0]['after'] ?? []));
        $this->assertSame('new text', (string) ($dry_run_after['recording_text'] ?? ''));
        $this->assertSame('new.ipa', (string) ($dry_run_after['recording_ipa'] ?? ''));
        $this->assertTrue((bool) ($dry_run_after['needs_review'] ?? false));
        $this->assertTrue((bool) (($dry_run_after['review_fields']['recording_text'] ?? false)));
        $this->assertTrue((bool) (($dry_run_after['review_fields']['recording_ipa'] ?? false)));
        $this->assertSame('Review both generated transcription fields.', (string) ($dry_run_after['review_note'] ?? ''));
        $this->assertSame('old text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('old.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
        $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

        $real_update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-transcription-review-sync-wordset/transcriptions', [
            'updates' => [$update_row],
        ]);

        $this->assertSame(200, $real_update->get_status());
        $real_data = $real_update->get_data();
        $this->assertIsArray($real_data);
        $this->assertSame(1, (int) ($real_data['updated_count'] ?? 0));
        $real_after = (array) (($real_data['updated'][0]['after'] ?? []));
        $this->assertSame('new text', (string) ($real_after['recording_text'] ?? ''));
        $this->assertSame('new.ipa', (string) ($real_after['recording_ipa'] ?? ''));
        $this->assertTrue((bool) ($real_after['needs_review'] ?? false));
        $this->assertTrue((bool) (($real_after['review_fields']['recording_text'] ?? false)));
        $this->assertTrue((bool) (($real_after['review_fields']['recording_ipa'] ?? false)));
        $this->assertSame('Review both generated transcription fields.', (string) ($real_after['review_note'] ?? ''));

        $search = ll_tools_ipa_keyboard_search_recordings($wordset_id, '', 'both', false, true, false, 1);
        $search_rows = array_values((array) ($search['results'] ?? []));
        $this->assertCount(1, $search_rows);
        $search_row = (array) $search_rows[0];
        $this->assertSame($recording_id, (int) ($search_row['recording_id'] ?? 0));
        $this->assertTrue((bool) ($search_row['needs_review'] ?? false));
        $this->assertTrue((bool) (($search_row['review_fields']['recording_text'] ?? false)));
        $this->assertTrue((bool) (($search_row['review_fields']['recording_ipa'] ?? false)));
        $this->assertSame('Review both generated transcription fields.', (string) ($search_row['review_note'] ?? ''));

        $snapshot_response = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-transcription-review-sync-wordset/site-sync/snapshot');
        $this->assertSame(200, $snapshot_response->get_status());
        $snapshot_data = $snapshot_response->get_data();
        $this->assertIsArray($snapshot_data);
        $snapshot_record = null;
        foreach ((array) ($snapshot_data['records'] ?? []) as $record) {
            if ((int) (($record['recording']['id'] ?? 0)) === $recording_id) {
                $snapshot_record = (array) $record;
                break;
            }
        }
        $this->assertIsArray($snapshot_record);
        $snapshot_values = (array) ($snapshot_record['values'] ?? []);
        $snapshot_review_fields = array_values((array) ($snapshot_values['review_fields'] ?? []));
        sort($snapshot_review_fields);
        $this->assertSame('new text', (string) ($snapshot_values['recording_text'] ?? ''));
        $this->assertSame('new.ipa', (string) ($snapshot_values['recording_ipa'] ?? ''));
        $this->assertTrue((bool) ($snapshot_values['needs_review'] ?? false));
        $this->assertSame(['recording_ipa', 'recording_text'], $snapshot_review_fields);
        $this->assertSame('Review both generated transcription fields.', (string) ($snapshot_values['review_note'] ?? ''));
    }

    public function test_transcriptions_route_accepts_field_specific_review_note_aliases(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Transcription Alias Wordset', 'rest-transcription-alias-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Transcription Alias Category', 'rest-transcription-alias-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Transcription Alias Word', 'Alias Translation');
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'REST Transcription Alias Recording',
        ]);

        wp_set_current_user($admin_id);

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-transcription-alias-wordset/transcriptions', [
            'updates' => [
                [
                    'recording_id' => $recording_id,
                    'recording_text_review_note' => 'Check the orthography before publishing.',
                ],
            ],
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(1, (int) ($data['updated_count'] ?? 0));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_text'));
        $this->assertFalse(ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_ipa'));
        $this->assertSame('Check the orthography before publishing.', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
    }

    public function test_bulk_update_route_caps_write_batches_by_default(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Batch Cap Wordset', 'rest-batch-cap-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Batch Cap Category', 'rest-batch-cap-category');
        $this->ensure_term('part_of_speech', 'Noun', 'noun');

        $word_ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $word_ids[] = $this->create_word($wordset_id, [$category_id], 'REST Batch Cap Word ' . $i, 'Batch Translation ' . $i);
        }

        wp_set_current_user($admin_id);

        $first = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-batch-cap-wordset/bulk-update', [
            'set' => [
                'field' => 'part_of_speech',
                'value' => 'noun',
            ],
            'where_missing' => ['part_of_speech'],
        ]);

        $this->assertSame(200, $first->get_status());
        $first_data = $first->get_data();
        $this->assertIsArray($first_data);
        $this->assertSame(12, (int) ($first_data['total_matched_count'] ?? 0));
        $this->assertSame(10, (int) ($first_data['matched_count'] ?? 0));
        $this->assertSame(10, (int) ($first_data['updated_count'] ?? 0));
        $this->assertTrue((bool) (($first_data['batch']['has_more'] ?? false)));
        $this->assertSame(10, (int) (($first_data['batch']['effective_limit'] ?? 0)));

        $second = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-batch-cap-wordset/bulk-update', [
            'set' => [
                'field' => 'part_of_speech',
                'value' => 'noun',
            ],
            'where_missing' => ['part_of_speech'],
            'resume_state' => $first_data['resume_state'] ?? [],
        ]);

        $this->assertSame(200, $second->get_status());
        $second_data = $second->get_data();
        $this->assertIsArray($second_data);
        $this->assertSame(2, (int) ($second_data['total_matched_count'] ?? 0));
        $this->assertSame(2, (int) ($second_data['updated_count'] ?? 0));
        $this->assertFalse((bool) (($second_data['batch']['has_more'] ?? true)));

        foreach ($word_ids as $word_id) {
            $assigned_terms = wp_get_post_terms($word_id, 'part_of_speech', ['fields' => 'slugs']);
            $this->assertContains('noun', array_map('strval', (array) $assigned_terms));
        }
    }

    public function test_basic_auth_image_rest_writes_are_rate_limited(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $server = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('lltools-image-rest:TempPass!789'),
            'HTTP_HOST' => '127.0.0.1:10036',
        ];

        try {
            $first = $this->dispatch_ll_tools_rest_request('POST', '/wp/v2/word_images', [
                'title' => 'Guarded REST Image One',
                'status' => 'publish',
            ], $server);

            $this->assertSame(201, $first->get_status());

            $second = $this->dispatch_ll_tools_rest_request('POST', '/wp/v2/word_images', [
                'title' => 'Guarded REST Image Two',
                'status' => 'publish',
            ], $server);

            $this->assertSame(429, $second->get_status());
            $data = $second->get_data();
            $this->assertIsArray($data);
            $this->assertSame('ll_tools_rest_resource_guard_wait', (string) ($data['code'] ?? ''));
            $this->assertGreaterThan(0, (float) (($data['data']['retry_after_seconds'] ?? 0)));
            $this->assertSame('/wp/v2/word_images', (string) (($data['data']['route'] ?? '')));
            $headers = $second->get_headers();
            $this->assertNotSame('', (string) ($headers['Retry-After'] ?? ''));
        } finally {
            if (function_exists('ll_tools_rest_resource_guard_clear_state')) {
                ll_tools_rest_resource_guard_clear_state();
            }
        }
    }

    public function test_report_summary_route_returns_lightweight_counts(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Summary Wordset', 'rest-summary-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Summary Category', 'rest-summary-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Summary Word', 'Summary Translation');

        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'REST Summary Image',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $image_id);

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'REST Summary Audio',
        ]);
        update_post_meta($audio_id, 'audio_file_path', 'audio/rest-summary.mp3');

        wp_set_current_user($admin_id);

        $response = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-summary-wordset/report-summary');

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame($wordset_id, (int) (($data['wordset']['id'] ?? 0)));
        $this->assertSame(1, (int) (($data['counts']['words_total'] ?? 0)));
        $this->assertSame(1, (int) (($data['counts']['categories_total'] ?? 0)));
        $this->assertSame(1, (int) (($data['counts']['words_with_audio'] ?? 0)));
        $this->assertSame(1, (int) (($data['counts']['audio_records_total'] ?? 0)));
        $this->assertSame(1, (int) (($data['counts']['words_with_images'] ?? 0)));
    }

    public function test_review_notes_route_lists_updates_and_clears_internal_notes(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);

        $wordset_id = $this->ensure_term('wordset', 'REST Review Notes Wordset', 'rest-review-notes-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Review Notes Category', 'rest-review-notes-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Review Word', 'Review Translation');
        $wrong_word_id = $this->create_word($wordset_id, [$category_id], 'REST Review Wrong', 'Wrong Translation');
        $prompt_card_id = $this->create_prompt_card($wordset_id, $category_id, $word_id, [$wrong_word_id]);

        update_post_meta($word_id, ll_tools_internal_review_note_meta_key(), 'Split this word.');
        update_post_meta($prompt_card_id, ll_tools_internal_review_note_meta_key(), 'Update prompt image.');

        wp_set_current_user($admin_id);

        $list = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-review-notes-wordset/review-notes');
        $this->assertSame(200, $list->get_status());
        $list_data = $list->get_data();
        $this->assertIsArray($list_data);
        $this->assertSame(2, (int) ($list_data['count'] ?? 0));

        $notes_by_key = [];
        foreach ((array) ($list_data['notes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $notes_by_key[(string) ($row['object_type'] ?? '') . ':' . (int) ($row['object_id'] ?? 0)] = (string) ($row['note'] ?? '');
        }
        $this->assertSame('Split this word.', $notes_by_key['word:' . $word_id] ?? '');
        $this->assertSame('Update prompt image.', $notes_by_key['prompt_card:' . $prompt_card_id] ?? '');

        $clear = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-review-notes-wordset/review-notes', [
            'object_type' => 'word',
            'object_id' => $word_id,
            'note' => '',
        ]);
        $this->assertSame(200, $clear->get_status());
        $clear_data = $clear->get_data();
        $this->assertIsArray($clear_data);
        $this->assertSame('', (string) ($clear_data['note'] ?? 'not-cleared'));
        $this->assertSame('', ll_tools_get_internal_review_note($word_id));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-review-notes-wordset/review-notes', [
            'object_type' => 'prompt_card',
            'object_id' => $prompt_card_id,
            'note' => 'Replace prompt audio.',
        ]);
        $this->assertSame(200, $update->get_status());
        $update_data = $update->get_data();
        $this->assertIsArray($update_data);
        $this->assertSame('Replace prompt audio.', (string) ($update_data['note'] ?? ''));
        $this->assertSame('Replace prompt audio.', ll_tools_get_internal_review_note($prompt_card_id));
    }

    public function test_review_notes_route_blocks_view_only_writes_and_allows_manager_writes(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Review Notes Permission Wordset', 'rest-review-notes-permission-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Review Notes Permission Category', 'rest-review-notes-permission-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Guarded Review Word', 'Guarded Translation');
        update_post_meta($word_id, ll_tools_internal_review_note_meta_key(), 'Existing manager note.');

        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);

        wp_set_current_user($viewer_id);

        $list = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-review-notes-permission-wordset/review-notes');
        $this->assertSame(200, $list->get_status());

        $blocked = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-review-notes-permission-wordset/review-notes', [
            'object_type' => 'word',
            'object_id' => $word_id,
            'note' => 'View-only users must not save this.',
        ]);
        $this->assertSame(403, $blocked->get_status());
        $this->assertSame('Existing manager note.', ll_tools_get_internal_review_note($word_id));

        $manager_id = self::factory()->user->create(['role' => 'subscriber']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        clean_user_cache($manager_id);
        $this->assertTrue(function_exists('ll_tools_cli_assign_wordset_manager'));
        ll_tools_cli_assign_wordset_manager($wordset_id, $manager_id);

        wp_set_current_user($manager_id);

        $allowed = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-review-notes-permission-wordset/review-notes', [
            'object_type' => 'word',
            'object_id' => $word_id,
            'note' => 'Assigned manager can save this.',
        ]);
        $this->assertSame(200, $allowed->get_status());
        $this->assertSame('Assigned manager can save this.', ll_tools_get_internal_review_note($word_id));
    }

    public function test_interlinear_render_block_is_staff_only_for_content_and_vocab_lessons(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Interlinear Render Wordset', 'rest-interlinear-render-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Interlinear Render Category', 'rest-interlinear-render-category');
        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Interlinear Render Content',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        $vocab_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Interlinear Render Vocab',
        ]);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $payload = $this->sample_interlinear_payload('rest-render-lesson');
        $this->assertNotWPError(ll_tools_interlinear_set_payload($content_lesson_id, $payload, 'phpunit'));
        $this->assertNotWPError(ll_tools_interlinear_set_payload($vocab_lesson_id, $payload, 'phpunit'));

        wp_set_current_user(0);
        $this->assertSame('', ll_tools_render_interlinear_block($content_lesson_id));
        $this->assertSame('', ll_tools_render_interlinear_block($vocab_lesson_id));

        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);
        wp_set_current_user($viewer_id);

        $content_html = ll_tools_render_interlinear_block($content_lesson_id);
        $this->assertStringContainsString('class="ll-interlinear"', $content_html);
        $this->assertStringContainsString('ll-interlinear__summary-icon--table', $content_html);
        $this->assertStringContainsString('Show interlinear', $content_html);
        $this->assertStringContainsString('Dara', $content_html);
        $this->assertStringContainsString('class="ll-interlinear-table"', $content_html);
        $this->assertStringContainsString('>WORD<', $content_html);
        $this->assertStringContainsString('>MORPH<', $content_html);
        $this->assertStringContainsString('>LEMMA<', $content_html);
        $this->assertStringContainsString('>GLOSS<', $content_html);
        $this->assertStringContainsString('>POS<', $content_html);
        $this->assertStringNotContainsString('Confidence', $content_html);
        $this->assertStringNotContainsString('Line 1', $content_html);
        $this->assertStringNotContainsString('<details class="ll-interlinear" data-ll-interlinear open', $content_html);

        $vocab_html = ll_tools_render_interlinear_block($vocab_lesson_id);
        $this->assertStringContainsString('class="ll-interlinear ll-interlinear--word-grid-toggle"', $vocab_html);
        $this->assertStringContainsString('ll-interlinear__summary-icon--table', $vocab_html);
        $this->assertStringContainsString('Interlinear', $vocab_html);
        $this->assertStringNotContainsString('Show interlinear', $vocab_html);
        $this->assertStringNotContainsString('Staff', $vocab_html);
        $this->assertStringNotContainsString('class="ll-interlinear-table"', $vocab_html);
        $this->assertStringNotContainsString('Dara', $vocab_html);
    }

    public function test_corpus_text_payload_renders_public_reader_and_staff_linguist_views(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Corpus Text Wordset', 'rest-corpus-text-wordset');
        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Corpus Text Content',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');

        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'corpus_text',
            'title' => 'Sample corpus text',
            'source_label' => 'Zazaki',
            'metadata' => [
                'publication' => [
                    'places_tr' => 'Sivan/Servi; Nyêrib/Nerib/Kuyular.',
                    'place_links' => [
                        [
                            'name' => 'Hyêni',
                            'modern' => 'Hani',
                            'url' => 'https://www.google.com/maps/search/?api=1&query=Hani%2C%20Diyarbak%C4%B1r',
                        ],
                    ],
                    'historical_context_tr' => 'Working historical context note.',
                    'editorial_note_tr' => 'Working editorial note.',
                ],
            ],
            'translations' => [
                'tr' => ['label' => 'Turkish'],
                'en' => ['label' => 'English'],
            ],
            'witnesses' => [
                [
                    'label' => 'Scholarly source',
                    'citation' => 'Lerch, Peter. Sample source. 1857.',
                    'url' => 'https://example.com/source',
                ],
            ],
            'reading_units' => [
                [
                    'id' => 'p1_s1',
                    'source' => 'Merheba, Dêrsim.',
                    'translations' => [
                        'tr' => 'Merhaba, Dersim.',
                        'en' => 'Hello, Dersim.',
                    ],
                ],
            ],
            'source_lines' => [
                [
                    'id' => 'l01',
                    'display_rows' => [
                        ['label' => 'LERCH', 'value' => 'Merheba, Dêrsim.'],
                        ['label' => 'IPA', 'value' => 'merheba deːrsim'],
                    ],
                    'witnesses' => [
                        [
                            'label' => 'Russian scan',
                            'image_url' => 'https://example.com/line-01.png',
                        ],
                    ],
                    'tokens' => [
                        [
                            'form' => 'Merheba',
                            'lemma' => 'merheba',
                            'display_gloss' => 'hello',
                            'pos' => 'INTJ',
                            'confidence' => 1.0,
                        ],
                    ],
                ],
            ],
        ];
        $this->assertNotWPError(ll_tools_interlinear_set_payload($content_lesson_id, $payload, 'phpunit'));
        $this->assertSame('corpus_text', ll_tools_get_content_lesson_kind($content_lesson_id));

        wp_set_current_user(0);
        $reader_html = ll_tools_render_interlinear_block($content_lesson_id);
        $this->assertStringContainsString('class="ll-text-document', $reader_html);
        $this->assertStringContainsString('>Text<', $reader_html);
        $this->assertStringContainsString('>Sources<', $reader_html);
        $this->assertStringContainsString('Merheba, Dêrsim.', $reader_html);
        $this->assertStringContainsString('Hello, Dersim.', $reader_html);
        $this->assertStringNotContainsString('Merhaba, Dersim.', $reader_html);
        $this->assertStringContainsString('>Interlinear<', $reader_html);
        $this->assertStringContainsString('Text background', $reader_html);
        $this->assertStringContainsString('Places mentioned', $reader_html);
        $this->assertStringContainsString('ll-text-document__place-list', $reader_html);
        $this->assertStringContainsString('Hyêni', $reader_html);
        $this->assertStringContainsString('href="https://www.google.com/maps/search/?api=1&#038;query=Hani%2C%20Diyarbak%C4%B1r"', $reader_html);
        $this->assertStringNotContainsString('Sivan/Servi', $reader_html);
        $this->assertStringContainsString('Working historical context note.', $reader_html);
        $this->assertStringContainsString('Working editorial note.', $reader_html);
        $this->assertStringNotContainsString('Russian scan', $reader_html);

        $_GET['ll_locale'] = 'tr_TR';
        $_REQUEST['ll_locale'] = 'tr_TR';
        try {
            $turkish_reader_html = ll_tools_render_interlinear_block($content_lesson_id);
        } finally {
            unset($_GET['ll_locale'], $_REQUEST['ll_locale']);
        }
        $this->assertStringContainsString('<span class="ll-text-reader__translation-head">Türkçe</span>', $turkish_reader_html);
        $this->assertStringNotContainsString('<span class="ll-text-reader__translation-head">Turkish</span>', $turkish_reader_html);
        $this->assertStringContainsString('Merhaba, Dersim.', $turkish_reader_html);

        $_GET['ll_text_view'] = 'sources';
        try {
            $sources_html = ll_tools_render_interlinear_block($content_lesson_id);
        } finally {
            unset($_GET['ll_text_view']);
        }
        $this->assertStringContainsString('Scholarly source', $sources_html);
        $this->assertStringContainsString('Lerch, Peter. Sample source. 1857.', $sources_html);

        $_GET['ll_text_view'] = 'interlinear';
        try {
            $public_interlinear_html = ll_tools_render_interlinear_block($content_lesson_id);
        } finally {
            unset($_GET['ll_text_view']);
        }
        $this->assertStringContainsString('class="ll-text-source-line"', $public_interlinear_html);
        $this->assertStringContainsString('Russian scan', $public_interlinear_html);

        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);
        wp_set_current_user($viewer_id);

        $_GET['ll_text_view'] = 'linguist';
        try {
            $staff_html = ll_tools_render_interlinear_block($content_lesson_id);
        } finally {
            unset($_GET['ll_text_view']);
        }

        $this->assertStringContainsString('class="ll-text-source-line"', $staff_html);
        $this->assertStringContainsString('>Interlinear<', $staff_html);
        $this->assertStringContainsString('Russian scan', $staff_html);
        $this->assertStringContainsString('>LERCH<', $staff_html);
        $this->assertStringContainsString('>IPA<', $staff_html);
        $this->assertStringContainsString('class="ll-interlinear-table"', $staff_html);
        $this->assertStringNotContainsString('>POS<', $staff_html);
        $this->assertStringNotContainsString('>INTJ<', $staff_html);
    }

    public function test_vocab_word_grid_renders_staff_interlinear_under_matching_recordings(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Interlinear Grid Wordset', 'rest-interlinear-grid-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Interlinear Grid Category', 'rest-interlinear-grid-category');
        $recording_type_id = $this->ensure_term('recording_type', 'Question', 'question');
        $second_recording_type_id = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Interlinear Grid Vocab',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $word_id = $this->create_word($wordset_id, [$category_id], 'Dara', 'Tree');
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Dara pil a.',
            'post_parent' => $word_id,
        ]);
        update_post_meta($recording_id, 'audio_file_path', '/wp-content/uploads/test/dara.mp3');
        update_post_meta($recording_id, 'recording_text', 'Dara pil a.');
        update_post_meta($recording_id, 'recording_translation', 'It is a big tree.');
        update_post_meta($recording_id, 'recording_ipa', 'dɑɾɑ pil ɑ');
        wp_set_post_terms($recording_id, [$recording_type_id], 'recording_type', false);
        $second_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Dara tenya.',
            'post_parent' => $word_id,
        ]);
        update_post_meta($second_recording_id, 'audio_file_path', '/wp-content/uploads/test/dara-isolation.mp3');
        update_post_meta($second_recording_id, 'recording_text', 'Dara tenya.');
        wp_set_post_terms($second_recording_id, [$second_recording_type_id], 'recording_type', false);

        $payload = $this->sample_interlinear_payload('grid-live');
        $payload['lines'][0]['id'] = (string) $recording_id;
        $payload['lines'][0]['text'] = 'Dara pil a.';
        $payload['lines'][0]['tokens'][1]['confidence'] = 0.72;
        $payload['lines'][0]['tokens'][1]['match_type'] = 'dictionary_folded';
        $this->assertNotWPError(ll_tools_interlinear_set_payload($lesson_id, $payload, 'phpunit'));

        wp_set_current_user(0);
        $public_output = do_shortcode('[word_grid category="rest-interlinear-grid-category" wordset="rest-interlinear-grid-wordset" lesson_id="' . (int) $lesson_id . '"]');
        $this->assertStringNotContainsString('data-ll-recording-interlinear', $public_output);
        $this->assertStringNotContainsString('ll-word-grid--has-interlinear', $public_output);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $staff_output = do_shortcode('[word_grid category="rest-interlinear-grid-category" wordset="rest-interlinear-grid-wordset" lesson_id="' . (int) $lesson_id . '"]');
        $this->assertStringContainsString('ll-word-grid--has-interlinear', $staff_output);
        $this->assertStringContainsString('ll-word-recordings--with-interlinear', $staff_output);
        $this->assertStringContainsString('ll-word-recording-row--has-interlinear', $staff_output);
        $this->assertStringContainsString('data-ll-recording-interlinear data-recording-id="' . (int) $recording_id . '"', $staff_output);
        $this->assertStringNotContainsString('data-ll-recording-interlinear data-recording-id="' . (int) $second_recording_id . '"', $staff_output);
        $this->assertStringNotContainsString('data-ll-word-interlinear', $staff_output);
        $this->assertStringContainsString('Dara pil a.', $staff_output);
        $this->assertStringContainsString('It is a big tree.', $staff_output);
        $this->assertStringContainsString('dɑɾɑ pil ɑ', $staff_output);
        $this->assertStringContainsString('Dara tenya.', $staff_output);
        $this->assertStringContainsString('class="ll-interlinear-table"', $staff_output);
        $this->assertStringContainsString('low-certainty', $staff_output);
        $this->assertStringNotContainsString('ll-interlinear-line__text', $staff_output);
        $this->assertStringNotContainsString('Confidence', $staff_output);

        $first_row_position = strpos($staff_output, 'll-word-recording-row--has-interlinear" data-recording-id="' . (int) $recording_id . '"');
        $interlinear_position = strpos($staff_output, 'data-ll-recording-interlinear data-recording-id="' . (int) $recording_id . '"');
        $second_row_position = strpos($staff_output, 'class="ll-word-recording-row ll-word-recording-row--editable" data-recording-id="' . (int) $second_recording_id . '"');
        if ($second_row_position === false) {
            $second_row_position = strpos($staff_output, 'class="ll-word-recording-row" data-recording-id="' . (int) $second_recording_id . '"');
        }
        $this->assertNotFalse($first_row_position);
        $this->assertNotFalse($interlinear_position);
        $this->assertNotFalse($second_row_position);
        $this->assertGreaterThan($first_row_position, $interlinear_position);
        $this->assertLessThan($second_row_position, $interlinear_position);
    }

    public function test_interlinear_rest_route_exports_updates_dry_runs_and_clears_lessons(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'REST Interlinear Wordset', 'rest-interlinear-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Interlinear Category', 'rest-interlinear-category');

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Interlinear Content Lesson',
            'post_name' => 'rest-interlinear-content-lesson',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);

        $vocab_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'REST Interlinear Vocab Lesson',
            'post_name' => 'rest-interlinear-vocab-lesson',
        ]);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        wp_set_current_user($admin_id);

        $dry_run = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-interlinear-wordset/interlinear', [
            'lesson' => 'rest-interlinear-content-lesson',
            'payload' => $this->sample_interlinear_payload('dry-run-content'),
            'source' => 'dry-run',
            'dry_run' => true,
        ]);
        $this->assertSame(200, $dry_run->get_status());
        $dry_run_data = $dry_run->get_data();
        $this->assertIsArray($dry_run_data);
        $this->assertTrue((bool) ($dry_run_data['dry_run'] ?? false));
        $this->assertSame([], ll_tools_interlinear_get_payload($content_lesson_id));

        $update = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-interlinear-wordset/interlinear', [
            'items' => [
                [
                    'lesson' => (string) $content_lesson_id,
                    'payload' => $this->sample_interlinear_payload('content-live'),
                    'source' => 'content-source',
                ],
                [
                    'category_slug' => 'rest-interlinear-category',
                    'payload' => $this->sample_interlinear_payload('vocab-live'),
                    'source' => 'vocab-source',
                ],
            ],
        ]);
        $this->assertSame(200, $update->get_status());
        $update_data = $update->get_data();
        $this->assertIsArray($update_data);
        $this->assertSame([], (array) ($update_data['errors'] ?? []));
        $this->assertSame(2, (int) ($update_data['updated_count'] ?? 0));
        $this->assertSame('content-live', (string) (ll_tools_interlinear_get_payload($content_lesson_id)['lesson_id'] ?? ''));
        $this->assertSame('vocab-live', (string) (ll_tools_interlinear_get_payload($vocab_lesson_id)['lesson_id'] ?? ''));

        $export = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/wordsets/rest-interlinear-wordset/interlinear');
        $this->assertSame(200, $export->get_status());
        $export_data = $export->get_data();
        $this->assertIsArray($export_data);
        $this->assertSame(2, (int) ($export_data['count'] ?? 0));
        $this->assertSame('zazaki_interlinear.v1', (string) (($export_data['items'][0]['payload']['schema'] ?? '')));

        $clear = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/wordsets/rest-interlinear-wordset/interlinear', [
            'lesson' => (string) $content_lesson_id,
            'delete' => true,
        ]);
        $this->assertSame(200, $clear->get_status());
        $clear_data = $clear->get_data();
        $this->assertIsArray($clear_data);
        $this->assertSame(1, (int) ($clear_data['cleared_count'] ?? 0));
        $this->assertSame([], ll_tools_interlinear_get_payload($content_lesson_id));
    }

    public function test_corpus_text_import_route_creates_wordset_free_content_lesson(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'corpus_text',
            'lesson_id' => 'rest-corpus-text',
            'title' => 'REST Corpus Text',
            'summary' => [
                'lines' => 51,
                'source_lines' => 51,
            ],
            'metadata' => [
                'collection' => 'lerch',
                'collection_label' => 'Peter Lerch',
                'source_author' => 'Peter Lerch',
                'excerpt' => 'Kısa Türkçe özet.',
            ],
            'source_lines' => [
                [
                    'id' => 'l01',
                    'witnesses' => [],
                ],
            ],
            'reading_units' => [],
            'translations' => [
                'tr' => [
                    'title' => 'REST Corpus Text',
                    'units' => [],
                ],
            ],
        ];

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/corpus-texts/import', [
            'post_slug' => 'rest-corpus-text',
            'payload' => $payload,
            'source' => 'unit-test',
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('created', (string) ($data['action'] ?? ''));

        $post_id = (int) ($data['post_id'] ?? 0);
        $this->assertGreaterThan(0, $post_id);
        $this->assertSame('ll_content_lesson', get_post_type($post_id));
        $this->assertSame('', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, true));
        $this->assertSame('corpus_text', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_KIND_META, true));
        $this->assertSame('lerch', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META, true));
        $this->assertSame('Peter Lerch', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META, true));
        $this->assertSame('Peter Lerch', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META, true));
        $this->assertSame('unit-test', (string) get_post_meta($post_id, LL_TOOLS_INTERLINEAR_SOURCE_META, true));
        $this->assertSame('rest-corpus-text', (string) (ll_tools_interlinear_get_payload($post_id)['lesson_id'] ?? ''));
        $this->assertSame('Kısa Türkçe özet.', ll_tools_get_content_lesson_localized_excerpt($post_id, ''));

        $export = $this->dispatch_ll_tools_rest_request('GET', '/ll-tools/v1/corpus-texts/rest-corpus-text');
        $this->assertSame(200, $export->get_status());
        $export_data = $export->get_data();
        $this->assertIsArray($export_data);
        $this->assertSame($post_id, (int) ($export_data['post_id'] ?? 0));
        $this->assertSame('rest-corpus-text', (string) ($export_data['payload']['lesson_id'] ?? ''));
    }

    public function test_book_text_import_route_creates_public_paginated_reader(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'book_text',
            'lesson_id' => 'rest-book-text',
            'title' => 'REST Book Text',
            'metadata' => [
                'collection' => 'lerch',
                'collection_label' => 'Peter Lerch',
                'source_author' => 'Peter Lerch',
                'excerpt' => 'Short multilingual book excerpt.',
                'default_language' => 'tr',
            ],
            'translations' => [
                'tr' => ['label' => 'Turkish'],
                'en' => ['label' => 'English'],
                'de' => ['label' => 'German'],
            ],
            'book_sections' => [
                [
                    'id' => 'intro',
                    'label' => 'Introduction',
                    'source_page' => 'Book I, p. 1',
                    'texts' => [
                        'tr' => 'Turkish intro text.',
                        'en' => 'English intro text.',
                        'de' => 'German intro text.',
                    ],
                ],
                [
                    'id' => 'notes',
                    'label' => 'Notes',
                    'source_page' => 'Book I, p. 2',
                    'texts' => [
                        'tr' => 'Turkish notes text.',
                        'en' => 'English notes text.',
                        'de' => 'German notes text.',
                    ],
                ],
            ],
            'witnesses' => [
                [
                    'label' => 'Scholarly source',
                    'citation' => 'Lerch, Peter. Research source. 1857.',
                    'url' => 'https://example.com/lerch',
                ],
            ],
        ];

        $locale_filter = static fn(): string => 'en_US';
        add_filter('locale', $locale_filter);

        try {
            $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/corpus-texts/import', [
                'post_slug' => 'rest-book-text',
                'payload' => $payload,
                'source' => 'unit-test',
            ]);
        } finally {
            remove_filter('locale', $locale_filter);
        }

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('created', (string) ($data['action'] ?? ''));
        $this->assertSame(2, (int) ($data['summary']['book_sections'] ?? 0));

        $post_id = (int) ($data['post_id'] ?? 0);
        $this->assertGreaterThan(0, $post_id);
        $this->assertSame('ll_content_lesson', get_post_type($post_id));
        $this->assertSame('', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, true));
        $this->assertSame('corpus_text', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_KIND_META, true));
        $this->assertSame('lerch', (string) get_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META, true));
        $this->assertTrue(ll_tools_interlinear_has_payload($post_id));

        $original_get = $_GET;
        wp_set_current_user(0);
        $locale_filter = static fn(): string => 'en_US';
        add_filter('locale', $locale_filter);
        try {
            $_GET = [];
            $reader_html = ll_tools_render_interlinear_block($post_id);
            $this->assertStringContainsString('class="ll-text-document ll-book-text"', $reader_html);
            $this->assertStringNotContainsString('Language: English', $reader_html);
            $this->assertStringNotContainsString('ll-book-text__translations', $reader_html);
            $this->assertStringContainsString('English intro text.', $reader_html);
            $this->assertStringContainsString('Book I, p. 1', $reader_html);
            $this->assertStringContainsString('Lerch, Peter. Research source. 1857.', $reader_html);
            $this->assertStringNotContainsString('Turkish intro text.', $reader_html);
            $this->assertStringNotContainsString('ll-text-document__tabs', $reader_html);
            $this->assertStringNotContainsString('>Interlinear<', $reader_html);

            $_GET = [
                'll_book_language' => 'de',
                'll_book_section' => 'notes',
                'unused' => 'drop-me',
            ];
            $print_url = ll_tools_get_content_lesson_print_source_url($post_id);
            $this->assertStringContainsString('ll_book_language=de', $print_url);
            $this->assertStringContainsString('ll_book_section=notes', $print_url);
            $this->assertStringNotContainsString('drop-me', $print_url);

            $german_html = ll_tools_render_interlinear_block($post_id);
            $this->assertStringNotContainsString('Language: German', $german_html);
            $this->assertStringContainsString('English notes text.', $german_html);
            $this->assertStringContainsString('Book I, p. 2', $german_html);
            $this->assertStringContainsString('Previous', $german_html);
            $this->assertStringNotContainsString('English intro text.', $german_html);
        } finally {
            $_GET = $original_get;
            remove_filter('locale', $locale_filter);
        }
    }

    public function test_book_text_explicit_html_body_is_rendered_safely(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'book_text',
            'text_format' => 'html',
            'lesson_id' => 'rest-book-html-text',
            'title' => 'REST Book HTML Text',
            'metadata' => [
                'collection' => 'lerch',
                'collection_label' => 'Peter Lerch',
                'default_language' => 'en',
            ],
            'translations' => [
                'en' => ['label' => 'English'],
            ],
            'book_sections' => [
                [
                    'id' => 'alphabet',
                    'label' => 'Alphabet',
                    'texts' => [
                        'en' => '<h3>Sound Count</h3><p>Symbol <span class="ll-book-text__term">e̱</span>.</p><table><thead><tr><th>Class</th></tr></thead><tbody><tr><td>Gutturals</td></tr></tbody></table><script>alert(1)</script>',
                    ],
                ],
            ],
        ];

        $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/corpus-texts/import', [
            'post_slug' => 'rest-book-html-text',
            'payload' => $payload,
            'source' => 'unit-test',
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $post_id = (int) ($data['post_id'] ?? 0);
        $this->assertGreaterThan(0, $post_id);

        wp_set_current_user(0);
        $reader_html = ll_tools_render_interlinear_block($post_id);
        $this->assertStringContainsString('<h3>Sound Count</h3>', $reader_html);
        $this->assertStringContainsString('class="ll-book-text__term"', $reader_html);
        $this->assertStringContainsString('<table>', $reader_html);
        $this->assertStringNotContainsString('<script>', $reader_html);
        $this->assertStringNotContainsString('alert(1)', $reader_html);
    }

    public function test_book_text_language_resolution_prefers_site_locale_then_document_default(): void
    {
        $payload = [
            'schema' => 'll_tools_text_document.v1',
            'kind' => 'book_text',
            'metadata' => [
                'default_language' => 'de',
            ],
            'translations' => [
                'tr' => ['label' => 'Turkish'],
                'en' => ['label' => 'English'],
                'de' => ['label' => 'German'],
            ],
            'book_sections' => [
                [
                    'id' => 'intro',
                    'texts' => [
                        'tr' => 'Turkish text.',
                        'en' => 'English text.',
                        'de' => 'German text.',
                    ],
                ],
            ],
        ];

        $original_get = $_GET;
        try {
            $_GET = ['ll_book_language' => 'tr'];
            $english_locale = static fn(): string => 'en_US';
            add_filter('locale', $english_locale);
            try {
                $this->assertSame('en', ll_tools_text_document_selected_translation_key($payload));
            } finally {
                remove_filter('locale', $english_locale);
            }

            $_GET = [];
            $turkish_locale = static fn(): string => 'tr_TR';
            add_filter('locale', $turkish_locale);
            try {
                $this->assertSame('tr', ll_tools_text_document_selected_translation_key($payload));
            } finally {
                remove_filter('locale', $turkish_locale);
            }

            $unsupported_locale = static fn(): string => 'fr_FR';
            add_filter('locale', $unsupported_locale);
            try {
                $this->assertSame('de', ll_tools_text_document_selected_translation_key($payload));
            } finally {
                remove_filter('locale', $unsupported_locale);
            }
        } finally {
            $_GET = $original_get;
        }
    }

    public function test_corpus_text_import_rejects_oversized_payload_without_creating_post(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $max_bytes = static fn(): int => 128;
        add_filter('ll_tools_rest_corpus_text_payload_max_bytes', $max_bytes);

        try {
            $payload = wp_json_encode([
                'schema' => 'll_tools_text_document.v1',
                'lesson_id' => 'rest-corpus-too-large',
                'title' => str_repeat('Oversized corpus payload ', 20),
                'source_lines' => [],
                'reading_units' => [],
            ]);
            $this->assertIsString($payload);

            $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/corpus-texts/import', [
                'post_slug' => 'rest-corpus-too-large',
                'payload' => $payload,
            ]);

            $this->assertSame(413, $response->get_status());
            $this->assertNull(ll_tools_rest_corpus_text_find_post_by_slug('rest-corpus-too-large'));
        } finally {
            remove_filter('ll_tools_rest_corpus_text_payload_max_bytes', $max_bytes);
        }
    }

    public function test_corpus_text_import_rejects_over_row_budget_payload_without_creating_post(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $max_rows = static fn(): int => 6;
        add_filter('ll_tools_rest_corpus_text_payload_max_rows', $max_rows);

        try {
            $response = $this->dispatch_ll_tools_rest_request('POST', '/ll-tools/v1/corpus-texts/import', [
                'post_slug' => 'rest-corpus-too-many-rows',
                'payload' => [
                    'schema' => 'll_tools_text_document.v1',
                    'lesson_id' => 'rest-corpus-too-many-rows',
                    'title' => 'REST Corpus Too Many Rows',
                    'source_lines' => array_fill(0, 10, ['id' => 'line', 'witnesses' => []]),
                    'reading_units' => [],
                ],
            ]);

            $this->assertSame(413, $response->get_status());
            $this->assertNull(ll_tools_rest_corpus_text_find_post_by_slug('rest-corpus-too-many-rows'));
        } finally {
            remove_filter('ll_tools_rest_corpus_text_payload_max_rows', $max_rows);
        }
    }

    public function test_corpus_text_asset_upload_rejects_oversized_file_before_sideload(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $max_bytes = static fn(): int => 8;
        add_filter('ll_tools_rest_corpus_text_asset_max_bytes', $max_bytes);

        $tmp_file = wp_tempnam('ll-tools-rest-oversized-asset.png');
        $this->assertIsString($tmp_file);
        file_put_contents($tmp_file, str_repeat('x', 64));

        try {
            $this->backup_request_state();
            $_GET['rest_route'] = '/ll-tools/v1/corpus-texts/asset';

            $request = new WP_REST_Request('POST', '/ll-tools/v1/corpus-texts/asset');
            $request->set_param('source_key', 'oversized-corpus-asset');
            $request->set_file_params([
                'asset' => [
                    'name' => 'oversized-corpus-asset.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp_file,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 64,
                ],
            ]);

            $response = rest_ensure_response(rest_get_server()->dispatch($request));

            $this->assertSame(413, $response->get_status());
            $this->assertSame(0, ll_tools_rest_corpus_text_find_attachment_by_source('oversized-corpus-asset'));
        } finally {
            remove_filter('ll_tools_rest_corpus_text_asset_max_bytes', $max_bytes);
            @unlink($tmp_file);
        }
    }

    public function test_import_rest_routes_preview_start_process_and_expose_result_with_basic_auth(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => 'lltools-import-rest-admin',
            'user_pass' => 'TempPass!456',
        ]);

        update_option(ll_tools_import_history_option_name(), [], false);
        delete_transient('ll_tools_import_result');
        delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
        delete_user_meta($admin_id, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);

        $bundle = $this->create_minimal_server_import_bundle_zip();
        $zip_path = (string) ($bundle['zip_path'] ?? '');
        $zip_filename = (string) ($bundle['zip_filename'] ?? '');
        $category_slug = (string) ($bundle['category_slug'] ?? '');
        $this->assertNotSame('', $zip_path);
        $this->assertNotSame('', $zip_filename);
        $this->assertNotSame('', $category_slug);

        $auth_server = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('lltools-import-rest-admin:TempPass!456'),
            'HTTP_HOST' => '127.0.0.1:10036',
        ];
        $guard_delay = static function (): float {
            return 0.1;
        };
        $job_id = '';
        $preview_token = '';

        add_filter('ll_tools_rest_resource_guard_delay_seconds', $guard_delay);
        try {
            $preview = $this->dispatch_ll_tools_rest_request_with_guard_retry(
                'POST',
                '/ll-tools/v1/imports/preview',
                ['existing' => $zip_filename],
                $auth_server,
                true
            );
            $this->assertSame(200, $preview->get_status());
            $preview_data = $preview->get_data();
            $this->assertIsArray($preview_data);
            $preview_token = (string) ($preview_data['preview_token'] ?? '');
            $this->assertNotSame('', $preview_token);
            $this->assertSame(1, (int) (($preview_data['summary']['categories'] ?? 0)));
            $this->assertSame($zip_filename, (string) (($preview_data['source']['zip_name'] ?? '')));

            $start = $this->dispatch_ll_tools_rest_request_with_guard_retry(
                'POST',
                '/ll-tools/v1/imports/start',
                ['preview_token' => $preview_token],
                $auth_server,
                true
            );
            $this->assertSame(200, $start->get_status());
            $start_data = $start->get_data();
            $this->assertIsArray($start_data);
            $job = is_array($start_data['job'] ?? null) ? $start_data['job'] : [];
            $job_id = (string) ($job['id'] ?? '');
            $this->assertNotSame('', $job_id);
            $this->assertSame('running', (string) ($job['status'] ?? ''));

            $completed_job = [];
            for ($attempt = 0; $attempt < 12; $attempt++) {
                $process = $this->dispatch_ll_tools_rest_request_with_guard_retry(
                    'POST',
                    '/ll-tools/v1/imports/' . rawurlencode($job_id) . '/process',
                    [],
                    $auth_server,
                    true
                );
                $this->assertSame(200, $process->get_status());
                $process_data = $process->get_data();
                $this->assertIsArray($process_data);
                $completed_job = is_array($process_data['job'] ?? null) ? $process_data['job'] : [];
                if ((string) ($completed_job['status'] ?? '') === 'completed') {
                    break;
                }
            }

            $this->assertSame('completed', (string) ($completed_job['status'] ?? ''));
            $this->assertArrayHasKey('result', $completed_job);
            $this->assertTrue((bool) (($completed_job['result']['ok'] ?? false)), implode(' | ', (array) (($completed_job['result']['errors'] ?? []))));
            $this->assertNotSame('', (string) (($completed_job['result']['historyEntryId'] ?? '')));
            $this->assertTrue((bool) (($completed_job['result']['hasUndo'] ?? false)));

            $result_response = $this->dispatch_ll_tools_rest_request(
                'GET',
                '/ll-tools/v1/imports/' . rawurlencode($job_id) . '/result',
                [],
                $auth_server,
                true
            );
            $this->assertSame(200, $result_response->get_status());
            $result_data = $result_response->get_data();
            $this->assertIsArray($result_data);
            $this->assertTrue((bool) (($result_data['result']['ok'] ?? false)));
            $this->assertSame(
                (string) (($completed_job['result']['historyEntryId'] ?? '')),
                (string) (($result_data['result']['historyEntryId'] ?? ''))
            );

            $imported_category = get_term_by('slug', $category_slug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $imported_category);
        } finally {
            remove_filter('ll_tools_rest_resource_guard_delay_seconds', $guard_delay);
            if ($job_id !== '') {
                ll_tools_import_job_delete($job_id, $admin_id);
            }
            if ($preview_token !== '') {
                ll_tools_delete_import_preview_data($preview_token);
            }
            @unlink($zip_path);
            delete_transient('ll_tools_import_result');
            delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
            delete_user_meta($admin_id, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        }
    }

    public function test_import_rest_process_locked_job_returns_retry_without_pausing(): void
    {
        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => 'lltools-import-lock-rest-admin',
            'user_pass' => 'TempPass!789',
        ]);

        $job_id = 'rest-import-lock-' . strtolower(wp_generate_password(8, false, false));
        $job = [
            'id' => $job_id,
            'status' => 'running',
            'phase' => 'extract',
            'created_at' => time(),
            'updated_at' => time(),
            'user_id' => $admin_id,
            'extract_index' => 0,
            'extract_total' => 1,
            'payload_counts' => [],
            'error_message' => '',
            'result' => ll_tools_import_job_default_result(),
        ];
        ll_tools_import_job_save($job_id, $job);
        ll_tools_import_job_set_active_id($job_id);

        $auth_server = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('lltools-import-lock-rest-admin:TempPass!789'),
            'HTTP_HOST' => '127.0.0.1:10036',
        ];
        $guard_delay = static function (): float {
            return 0.1;
        };
        $lock = ll_tools_import_job_process_lock_acquire($job_id, $job);
        $this->assertNotWPError($lock);
        $this->assertIsArray($lock);
        $lock_owner = (string) ($lock['owner'] ?? '');

        add_filter('ll_tools_rest_resource_guard_delay_seconds', $guard_delay);
        try {
            $response = $this->dispatch_ll_tools_rest_request_with_guard_retry(
                'POST',
                '/ll-tools/v1/imports/' . rawurlencode($job_id) . '/process',
                [],
                $auth_server,
                true
            );

            $this->assertSame(429, $response->get_status());
            $data = $response->get_data();
            $this->assertIsArray($data);
            $this->assertSame('ll_tools_import_job_process_locked', (string) ($data['code'] ?? ''));
            $error_data = is_array($data['data'] ?? null) ? $data['data'] : [];
            $this->assertTrue((bool) ($error_data['locked'] ?? false));
            $this->assertGreaterThan(0, (float) ($error_data['retry_after_seconds'] ?? 0));
            $response_job = is_array($error_data['job'] ?? null) ? $error_data['job'] : [];
            $this->assertSame('running', (string) ($response_job['status'] ?? ''));

            $saved_job = ll_tools_import_job_get($job_id);
            $this->assertIsArray($saved_job);
            $this->assertSame('running', (string) ($saved_job['status'] ?? ''));
            $this->assertSame('', (string) ($saved_job['error_message'] ?? ''));
        } finally {
            remove_filter('ll_tools_rest_resource_guard_delay_seconds', $guard_delay);
            ll_tools_import_job_process_lock_release($job_id, $lock_owner);
            ll_tools_import_job_delete($job_id, $admin_id);
            delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
            delete_user_meta($admin_id, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $server_overrides
     */
    private function dispatch_ll_tools_rest_request(string $method, string $route, array $params = [], array $server_overrides = [], bool $reset_current_user = false): WP_REST_Response
    {
        $this->backup_request_state();
        $_GET['rest_route'] = $route;

        foreach ($server_overrides as $key => $value) {
            $_SERVER[$key] = $value;
        }

        if ($reset_current_user) {
            global $current_user;
            wp_set_current_user(0);
            $current_user = null;
            if (function_exists('ll_tools_rest_automation_clear_auth_runtime_state')) {
                ll_tools_rest_automation_clear_auth_runtime_state();
            }
        }

        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_get_server()->dispatch($request);
        $this->assertNotWPError($response);

        return rest_ensure_response($response);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $server_overrides
     */
    private function dispatch_ll_tools_rest_request_with_guard_retry(string $method, string $route, array $params = [], array $server_overrides = [], bool $reset_current_user = false): WP_REST_Response
    {
        $response = $this->dispatch_ll_tools_rest_request($method, $route, $params, $server_overrides, $reset_current_user);
        if ($response->get_status() !== 429) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data) || (string) ($data['code'] ?? '') !== 'll_tools_rest_resource_guard_wait') {
            return $response;
        }

        $error_data = is_array($data['data'] ?? null) ? $data['data'] : [];
        $retry_after = max(0.1, (float) ($error_data['retry_after_seconds'] ?? 0.1));
        usleep((int) ceil(($retry_after + 0.05) * 1000000));

        return $this->dispatch_ll_tools_rest_request($method, $route, $params, $server_overrides, $reset_current_user);
    }

    private function backup_request_state(): void
    {
        if (empty($this->server_backup)) {
            $keys = [
                'HTTP_AUTHORIZATION',
                'REDIRECT_HTTP_AUTHORIZATION',
                'PHP_AUTH_USER',
                'PHP_AUTH_PW',
                'HTTP_HOST',
            ];
            foreach ($keys as $key) {
                $this->server_backup[$key] = $_SERVER[$key] ?? null;
            }
        }

        if (empty($this->get_backup)) {
            $this->get_backup = [
                'rest_route' => $_GET['rest_route'] ?? null,
            ];
        }
    }

    private function restore_request_state(): void
    {
        foreach ($this->server_backup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->server_backup = [];

        foreach ($this->get_backup as $key => $value) {
            if ($value === null) {
                unset($_GET[$key]);
            } else {
                $_GET[$key] = $value;
            }
        }
        $this->get_backup = [];
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

    /**
     * @param int[] $category_ids
     */
    private function create_word(int $wordset_id, array $category_ids, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, $category_ids, 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return (int) $word_id;
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

        $metadata = function_exists('wp_generate_attachment_metadata')
            ? wp_generate_attachment_metadata($attachment_id, $file_path)
            : [];
        if (is_array($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

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
     * @return array<string,mixed>
     */
    private function sample_interlinear_payload(string $lesson_id): array
    {
        return [
            'schema' => 'zazaki_interlinear.v1',
            'lesson_id' => $lesson_id,
            'title' => 'Sample Interlinear',
            'metadata' => [
                'category_slug' => 'rest-interlinear-category',
            ],
            'summary' => [
                'lines' => 1,
                'tokens' => 2,
                'matched_tokens' => 2,
                'matched_pct' => '100%',
                'high_confidence_tokens' => 2,
                'high_confidence_pct' => '100%',
                'mean_confidence' => 0.95,
            ],
            'lines' => [
                [
                    'id' => 'line-1',
                    'text' => 'Dara pil a.',
                    'tokens' => [
                        [
                            'form' => 'Dara',
                            'lemma' => 'dar',
                            'display_gloss' => 'tree',
                            'pos' => 'N',
                            'confidence' => 0.95,
                            'morphemes' => [],
                        ],
                        [
                            'form' => 'pil',
                            'lemma' => 'pil',
                            'display_gloss' => 'big',
                            'pos' => 'ADJ',
                            'confidence' => 0.95,
                            'morphemes' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param int[] $wrong_answer_ids
     */
    private function create_prompt_card(int $wordset_id, int $category_id, int $answer_id, array $wrong_answer_ids): int
    {
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'REST Review Prompt Card',
        ]);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Which option is right?');
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, $answer_id);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', $wrong_answer_ids)));
        wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);

        return (int) $prompt_card_id;
    }

    /**
     * @return array{zip_path:string,zip_filename:string,category_slug:string}
     */
    private function create_minimal_server_import_bundle_zip(): array
    {
        $import_dir = ll_tools_get_import_dir();
        $this->assertTrue(ll_tools_ensure_import_dir($import_dir));

        $suffix = strtolower(wp_generate_password(8, false, false));
        $category_slug = 'import-rest-cat-' . $suffix;
        $payload = [
            'bundle_type' => 'images',
            'categories' => [
                [
                    'slug' => $category_slug,
                    'name' => 'Import REST Category ' . $suffix,
                    'description' => 'REST automation import test bundle',
                    'parent_slug' => '',
                    'meta' => [
                        'display_color' => ['green'],
                    ],
                ],
            ],
            'word_images' => [],
            'wordsets' => [],
            'words' => [],
            'media_estimate' => [
                'attachment_count' => 0,
                'attachment_bytes' => 0,
            ],
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $zip_filename = 'll-tools-rest-import-' . $suffix . '.zip';
        $zip_path = trailingslashit($import_dir) . $zip_filename;
        @unlink($zip_path);

        $zip = new ZipArchive();
        $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to create REST import test zip.');
        $this->assertTrue($zip->addFromString('data.json', $json));
        $this->assertTrue($zip->close());
        $this->assertFileExists($zip_path);

        return [
            'zip_path' => wp_normalize_path($zip_path),
            'zip_filename' => $zip_filename,
            'category_slug' => $category_slug,
        ];
    }
}
