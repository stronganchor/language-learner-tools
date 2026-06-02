<?php
declare(strict_types=1);

final class AutomationRestResourceGuardTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->serverBackup = [];

        if (function_exists('ll_tools_rest_resource_guard_clear_state')) {
            ll_tools_rest_resource_guard_clear_state();
        }

        parent::tearDown();
    }

    public function test_basic_auth_guard_policy_covers_expensive_automation_write_routes(): void
    {
        $this->setAuthorizationHeader();

        $expected = [
            '/ll-tools/v1/cache/static/purge' => 'll_tools_static_cache_purge',
            '/ll-tools/v1/wordsets' => 'll_tools_wordset_create',
            '/ll-tools/v1/wordsets/spanish/bulk-update' => 'll_tools_bulk-update',
            '/ll-tools/v1/wordsets/spanish/word-title-updates' => 'll_tools_word-title-updates',
            '/ll-tools/v1/wordsets/spanish/transcriptions' => 'll_tools_transcriptions',
            '/ll-tools/v1/wordsets/spanish/word-option-rules' => 'll_tools_word-option-rules',
            '/ll-tools/v1/wordsets/spanish/orthography-conversion' => 'll_tools_orthography-conversion',
            '/ll-tools/v1/wordsets/spanish/prompt-cards' => 'll_tools_prompt-cards',
            '/ll-tools/v1/wordsets/spanish/review-notes' => 'll_tools_review-notes',
            '/ll-tools/v1/wordsets/spanish/interlinear' => 'll_tools_interlinear',
            '/ll-tools/v1/imports/preview' => 'll_tools_import_preview',
            '/ll-tools/v1/imports/start' => 'll_tools_import_start',
            '/ll-tools/v1/imports/job-123/process' => 'll_tools_import_process',
            '/ll-tools/v1/imports/job-123/discard' => 'll_tools_import_discard',
            '/ll-tools/v1/corpus-texts/asset' => 'll_tools_corpus_text_asset',
            '/ll-tools/v1/corpus-texts/import' => 'll_tools_corpus_text_import',
        ];

        foreach ($expected as $route => $resource) {
            $request = new WP_REST_Request('POST', $route);
            $policy = ll_tools_rest_resource_guard_policy($request);

            $this->assertSame($resource, (string) ($policy['resource'] ?? ''), $route);
            $this->assertSame($route, (string) ($policy['route'] ?? ''), $route);
            $this->assertSame('ll_tools_rest_automation', (string) ($policy['scope'] ?? ''), $route);
            $this->assertGreaterThanOrEqual(3.0, (float) ($policy['delay_seconds'] ?? 0), $route);
        }
    }

    public function test_basic_auth_guard_policy_covers_authentication_probe_routes(): void
    {
        $this->setAuthorizationHeader();

        $expected = [
            '/ll-tools/v1/automation/status' => 'll_tools_automation_status',
            '/wp/v2/users/me' => 'wp_users_me',
        ];

        foreach ($expected as $route => $resource) {
            $request = new WP_REST_Request('GET', $route);
            $policy = ll_tools_rest_resource_guard_policy($request);

            $this->assertSame($resource, (string) ($policy['resource'] ?? ''), $route);
            $this->assertSame($route, (string) ($policy['route'] ?? ''), $route);
            $this->assertSame('ll_tools_rest_automation', (string) ($policy['scope'] ?? ''), $route);
            $this->assertGreaterThanOrEqual(2.0, (float) ($policy['delay_seconds'] ?? 0), $route);
        }
    }

    public function test_basic_auth_guard_policy_skips_dry_run_and_read_requests(): void
    {
        $this->setAuthorizationHeader();

        $dryRun = new WP_REST_Request('POST', '/ll-tools/v1/wordsets/spanish/bulk-update');
        $dryRun->set_param('dry_run', true);
        $this->assertSame([], ll_tools_rest_resource_guard_policy($dryRun));

        $read = new WP_REST_Request('GET', '/ll-tools/v1/corpus-texts/spanish-source');
        $this->assertSame([], ll_tools_rest_resource_guard_policy($read));
    }

    public function test_status_exposes_guarded_automation_write_routes(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $response = ll_tools_rest_automation_status(new WP_REST_Request('GET', '/ll-tools/v1/automation/status'));
        $data = $response->get_data();

        $this->assertIsArray($data);
        $resourceGuard = $data['resource_guard'] ?? [];
        $this->assertIsArray($resourceGuard);
        $this->assertSame('ll_tools_rest_automation', (string) ($resourceGuard['shared_scope'] ?? ''));
        $automationRoutes = array_map('strval', (array) ($resourceGuard['automation_write_routes'] ?? []));
        $authProbeRoutes = array_map('strval', (array) ($resourceGuard['auth_probe_routes'] ?? []));

        $this->assertContains('/ll-tools/v1/automation/status', $authProbeRoutes);
        $this->assertContains('/wp/v2/users/me', $authProbeRoutes);
        $this->assertContains('/ll-tools/v1/cache/static/purge', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/orthography-conversion', $automationRoutes);
        $this->assertContains('/ll-tools/v1/imports/{job_id}/process', $automationRoutes);
        $this->assertContains('/ll-tools/v1/corpus-texts/import', $automationRoutes);

        $titleBatch = is_array($resourceGuard['word_title_updates_batch'] ?? null)
            ? $resourceGuard['word_title_updates_batch']
            : [];
        $this->assertSame(5, (int) ($titleBatch['default_write_limit'] ?? 0));
        $this->assertSame(10, (int) ($titleBatch['max_write_limit'] ?? 0));
    }

    private function setAuthorizationHeader(): void
    {
        if (empty($this->serverBackup)) {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER'] as $key) {
                $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            }
        }

        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('lltools-rest-admin:TempPass!234');
    }
}
