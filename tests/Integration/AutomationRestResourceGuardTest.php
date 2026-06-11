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

    public function test_guard_policy_covers_codex_cookie_nonce_automation_write_routes(): void
    {
        $this->setCodexUserAgent();

        $expected = [
            '/ll-tools/v1/automation/plugin-update' => 'll_tools_plugin_update',
            '/ll-tools/v1/cache/static/purge' => 'll_tools_static_cache_purge',
            '/ll-tools/v1/wordsets' => 'll_tools_wordset_create',
            '/ll-tools/v1/wordsets/spanish/bulk-update' => 'll_tools_bulk-update',
            '/ll-tools/v1/wordsets/spanish/word-title-updates' => 'll_tools_word-title-updates',
            '/ll-tools/v1/wordsets/spanish/word-helper-updates' => 'll_tools_word-helper-updates',
            '/ll-tools/v1/wordsets/spanish/word-image-category-ownership' => 'll_tools_word-image-category-ownership',
            '/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs' => 'll_tools_word_metadata_plan_create',
            '/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs/job-123/process' => 'll_tools_word_metadata_plan_process',
            '/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs/job-123/discard' => 'll_tools_word_metadata_plan_discard',
            '/ll-tools/v1/wordsets/spanish/transcriptions' => 'll_tools_transcriptions',
            '/ll-tools/v1/wordsets/spanish/transcription-validations' => 'll_tools_transcription-validations',
            '/ll-tools/v1/wordsets/spanish/word-option-rules' => 'll_tools_word-option-rules',
            '/ll-tools/v1/wordsets/spanish/orthography-conversion' => 'll_tools_orthography-conversion',
            '/ll-tools/v1/wordsets/spanish/prompt-cards' => 'll_tools_prompt-cards',
            '/ll-tools/v1/wordsets/spanish/review-notes' => 'll_tools_review-notes',
            '/ll-tools/v1/wordsets/spanish/interlinear' => 'll_tools_interlinear',
            '/ll-tools/v1/wordsets/spanish/translations' => 'll_tools_translations',
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
            $minimum_delay = strpos($route, '/transcription-validations') !== false ? 30.0 : 3.0;
            $this->assertGreaterThanOrEqual($minimum_delay, (float) ($policy['delay_seconds'] ?? 0), $route);
        }
    }

    public function test_guard_policy_covers_cookie_status_and_codex_probe_routes(): void
    {
        $this->setCodexUserAgent();

        $status_request = new WP_REST_Request('GET', '/ll-tools/v1/automation/status');
        $status_policy = ll_tools_rest_resource_guard_policy($status_request);

        $this->assertSame('ll_tools_automation_status', (string) ($status_policy['resource'] ?? ''));
        $this->assertSame('/ll-tools/v1/automation/status', (string) ($status_policy['route'] ?? ''));
        $this->assertSame('ll_tools_rest_automation', (string) ($status_policy['scope'] ?? ''));
        $this->assertGreaterThanOrEqual(2.0, (float) ($status_policy['delay_seconds'] ?? 0));

        $me_request = new WP_REST_Request('GET', '/wp/v2/users/me');
        $me_policy = ll_tools_rest_resource_guard_policy($me_request);

        $this->assertSame('wp_users_me', (string) ($me_policy['resource'] ?? ''));
        $this->assertSame('/wp/v2/users/me', (string) ($me_policy['route'] ?? ''));
        $this->assertSame('ll_tools_rest_automation', (string) ($me_policy['scope'] ?? ''));
        $this->assertGreaterThanOrEqual(2.0, (float) ($me_policy['delay_seconds'] ?? 0));
    }

    public function test_guard_policy_covers_dry_run_but_skips_unrelated_read_requests(): void
    {
        $this->setCodexUserAgent();

        $dryRun = new WP_REST_Request('POST', '/ll-tools/v1/wordsets/spanish/bulk-update');
        $dryRun->set_param('dry_run', true);
        $dry_run_policy = ll_tools_rest_resource_guard_policy($dryRun);
        $this->assertSame('ll_tools_bulk-update', (string) ($dry_run_policy['resource'] ?? ''));

        $read = new WP_REST_Request('GET', '/ll-tools/v1/corpus-texts/spanish-source');
        $this->assertSame([], ll_tools_rest_resource_guard_policy($read));
    }

    public function test_core_rest_writes_are_guarded_only_for_automation_context(): void
    {
        $ordinary_ll_tools = new WP_REST_Request('POST', '/ll-tools/v1/wordsets/spanish/bulk-update');
        $this->assertSame([], ll_tools_rest_resource_guard_policy($ordinary_ll_tools));

        $ordinary = new WP_REST_Request('POST', '/wp/v2/word_images');
        $this->assertSame([], ll_tools_rest_resource_guard_policy($ordinary));

        $this->setCodexUserAgent();

        $automation = new WP_REST_Request('POST', '/wp/v2/word_images');
        $policy = ll_tools_rest_resource_guard_policy($automation);

        $this->assertSame('word_images', (string) ($policy['resource'] ?? ''));
        $this->assertSame('/wp/v2/word_images', (string) ($policy['route'] ?? ''));
        $this->assertSame('ll_tools_rest_automation', (string) ($policy['scope'] ?? ''));
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
        $automationContext = is_array($resourceGuard['automation_context'] ?? null)
            ? $resourceGuard['automation_context']
            : [];
        $automationRoutes = array_map('strval', (array) ($resourceGuard['automation_write_routes'] ?? []));
        $authProbeRoutes = array_map('strval', (array) ($resourceGuard['auth_probe_routes'] ?? []));
        $guardedReadRoutes = array_map('strval', (array) ($resourceGuard['guarded_read_routes'] ?? []));

        $this->assertContains('X-LL-Tools-Automation', array_map('strval', (array) ($automationContext['headers'] ?? [])));
        $this->assertContains('codex', array_map('strval', (array) ($automationContext['user_agent_patterns'] ?? [])));
        $this->assertContains('/ll-tools/v1/automation/status', $authProbeRoutes);
        $this->assertContains('/wp/v2/users/me', $authProbeRoutes);
        $this->assertContains('/ll-tools/v1/imports/{job_id}', $guardedReadRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/site-sync/snapshot', $guardedReadRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}', $guardedReadRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result', $guardedReadRoutes);
        $this->assertContains('/ll-tools/v1/automation/plugin-update', $automationRoutes);
        $this->assertContains('/ll-tools/v1/cache/static/purge', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-image-category-ownership', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/orthography-conversion', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-helper-updates', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process', $automationRoutes);
        $this->assertContains('/ll-tools/v1/wordsets/{wordset}/translations', $automationRoutes);
        $this->assertContains('/ll-tools/v1/imports/{job_id}/process', $automationRoutes);
        $this->assertContains('/ll-tools/v1/corpus-texts/import', $automationRoutes);

        $titleBatch = is_array($resourceGuard['word_title_updates_batch'] ?? null)
            ? $resourceGuard['word_title_updates_batch']
            : [];
        $this->assertSame(5, (int) ($titleBatch['default_write_limit'] ?? 0));
        $this->assertSame(10, (int) ($titleBatch['max_write_limit'] ?? 0));

        $helperBatch = is_array($resourceGuard['word_helper_updates_batch'] ?? null)
            ? $resourceGuard['word_helper_updates_batch']
            : [];
        $this->assertSame(10, (int) ($helperBatch['default_write_limit'] ?? 0));
        $this->assertSame(25, (int) ($helperBatch['max_write_limit'] ?? 0));

        $validationBatch = is_array($resourceGuard['transcription_validations_batch'] ?? null)
            ? $resourceGuard['transcription_validations_batch']
            : [];
        $this->assertSame(1, (int) ($validationBatch['default_write_limit'] ?? 0));
        $this->assertSame(1, (int) ($validationBatch['max_write_limit'] ?? 0));
        $this->assertSame(25, (int) ($validationBatch['max_write_scan_limit'] ?? 0));
        $this->assertTrue((bool) ($validationBatch['server_side_recommended'] ?? false));
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

    private function setCodexUserAgent(): void
    {
        if (empty($this->serverBackup)) {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'HTTP_USER_AGENT'] as $key) {
                $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            }
        } elseif (!array_key_exists('HTTP_USER_AGENT', $this->serverBackup)) {
            $this->serverBackup['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }

        $_SERVER['HTTP_USER_AGENT'] = 'Codex WordBoat category cleanup 2026-06-05';
    }
}
