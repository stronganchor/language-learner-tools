<?php
declare(strict_types=1);

final class AutomationRestApiTest extends LL_Tools_TestCase
{
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
        $job_id = '';
        $preview_token = '';

        try {
            $preview = $this->dispatch_ll_tools_rest_request(
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

            $start = $this->dispatch_ll_tools_rest_request(
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
                $process = $this->dispatch_ll_tools_rest_request(
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
