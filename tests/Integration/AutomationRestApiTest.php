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
            $current_user = null;
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
}
