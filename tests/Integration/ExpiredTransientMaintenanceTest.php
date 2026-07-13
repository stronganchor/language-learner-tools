<?php
declare(strict_types=1);

final class ExpiredTransientMaintenanceTest extends LL_Tools_TestCase
{
    /** @var array<int,string> */
    private array $createdOptions = [];

    /** @var array<int,array{hook:string,callback:callable,priority:int}> */
    private array $filters = [];

    private bool $originalExternalObjectCache = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalExternalObjectCache = (bool) wp_using_ext_object_cache();
        wp_using_ext_object_cache(false);
        delete_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION);
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as $filter) {
            remove_filter($filter['hook'], $filter['callback'], $filter['priority']);
        }
        $this->filters = [];

        foreach (array_unique($this->createdOptions) as $optionName) {
            delete_option($optionName);
        }
        $this->createdOptions = [];

        delete_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION);
        wp_using_ext_object_cache($this->originalExternalObjectCache);
        parent::tearDown();
    }

    public function test_run_deletes_only_expired_allowlisted_pairs_and_timeout_only_rows(): void
    {
        $suffix = $this->uniqueSuffix('protections');
        $expiredPair = 'll_dict_' . $suffix . '_pair';
        $timeoutOnly = 'll_dict_' . $suffix . '_timeout_only';
        $activePair = 'll_dict_' . $suffix . '_active';
        $gracePair = 'll_dict_' . $suffix . '_grace';
        $valueOnly = 'll_dict_' . $suffix . '_value_only';
        $foreignPair = 'other_plugin_' . $suffix;
        $excludedLlPair = 'll_tools_background_job_' . $suffix;
        $persistentJob = 'll_tools_background_job_' . $suffix . '_persistent';
        $secretValue = 'secret-value-' . $suffix;

        $this->addTransientRows($expiredPair, $secretValue, time() - 10 * MINUTE_IN_SECONDS);
        $this->addTimeoutRow($timeoutOnly, time() - 10 * MINUTE_IN_SECONDS);
        $this->addTransientRows($activePair, 'active-value', time() + HOUR_IN_SECONDS);
        $this->addTransientRows($gracePair, 'grace-value', time() - 2 * MINUTE_IN_SECONDS);
        $this->addValueRow($valueOnly, 'orphan-value');
        $this->addTransientRows($foreignPair, 'foreign-value', time() - HOUR_IN_SECONDS);
        $this->addTransientRows($excludedLlPair, 'operational-value', time() - HOUR_IN_SECONDS);
        $this->addOption($persistentJob, 'persistent-job-state');

        $this->addFilter('ll_tools_expired_transient_maintenance_runtime_seconds', static fn (): float => 2.0);
        $capturedTelemetry = null;
        $capture = static function (array $telemetry) use (&$capturedTelemetry): void {
            $capturedTelemetry = $telemetry;
        };
        add_action('ll_tools_expired_transient_maintenance_telemetry', $capture, 10, 1);

        try {
            $telemetry = ll_tools_run_expired_transient_maintenance();
        } finally {
            remove_action('ll_tools_expired_transient_maintenance_telemetry', $capture, 10);
        }

        $this->assertFalse(get_option('_transient_' . $expiredPair, false));
        $this->assertFalse(get_option('_transient_timeout_' . $expiredPair, false));
        $this->assertFalse(get_option('_transient_timeout_' . $timeoutOnly, false));

        $this->assertSame('active-value', get_option('_transient_' . $activePair));
        $this->assertNotFalse(get_option('_transient_timeout_' . $activePair, false));
        $this->assertSame('grace-value', get_option('_transient_' . $gracePair));
        $this->assertNotFalse(get_option('_transient_timeout_' . $gracePair, false));
        $this->assertSame('orphan-value', get_option('_transient_' . $valueOnly));
        $this->assertSame('foreign-value', get_option('_transient_' . $foreignPair));
        $this->assertNotFalse(get_option('_transient_timeout_' . $foreignPair, false));
        $this->assertSame('operational-value', get_option('_transient_' . $excludedLlPair));
        $this->assertNotFalse(get_option('_transient_timeout_' . $excludedLlPair, false));
        $this->assertSame('persistent-job-state', get_option($persistentJob));

        $this->assertSame(2, $telemetry['selected_count']);
        $this->assertSame(2, $telemetry['processed_count']);
        $this->assertSame(2, $telemetry['deleted_transient_count']);
        $this->assertSame(3, $telemetry['deleted_row_count']);
        $this->assertSame(1, $telemetry['deleted_value_row_count']);
        $this->assertSame(1, $telemetry['deleted_timeout_only_count']);
        $this->assertGreaterThan(0, $telemetry['deleted_value_bytes']);
        $this->assertSame(2, $telemetry['namespaces']['dictionary-cache']['deleted_transient_count']);
        $this->assertSame($telemetry, $capturedTelemetry);

        $encodedTelemetry = wp_json_encode($telemetry);
        $this->assertIsString($encodedTelemetry);
        $this->assertStringNotContainsString($secretValue, $encodedTelemetry);
        $this->assertStringNotContainsString($expiredPair, $encodedTelemetry);
    }

    public function test_conditional_delete_preserves_a_timeout_renewed_after_selection(): void
    {
        global $wpdb;

        $key = 'll_wsp_' . $this->uniqueSuffix('renewed');
        $oldTimeout = time() - YEAR_IN_SECONDS;
        $futureTimeout = time() + HOUR_IN_SECONDS;
        $this->addTransientRows($key, 'renewed-value', $oldTimeout);

        $candidates = ll_tools_expired_transient_maintenance_select_candidates(time() - 5 * MINUTE_IN_SECONDS, 200);
        $candidate = $this->findCandidate($candidates, $key);
        $this->assertNotNull($candidate);

        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => (string) $futureTimeout],
            ['option_name' => '_transient_timeout_' . $key],
            ['%s'],
            ['%s']
        );
        $this->assertSame(1, $updated);
        wp_cache_delete('_transient_timeout_' . $key, 'options');

        $result = ll_tools_expired_transient_maintenance_delete_candidate(
            (array) $candidate,
            time() - 5 * MINUTE_IN_SECONDS
        );

        $this->assertFalse($result['deleted']);
        $this->assertSame(0, $result['deleted_rows']);
        $this->assertSame('renewed-value', get_option('_transient_' . $key));
        $this->assertSame((string) $futureTimeout, get_option('_transient_timeout_' . $key));
    }

    public function test_batch_limit_is_hard_capped_and_a_run_processes_only_the_configured_batch(): void
    {
        $this->addFilter('ll_tools_expired_transient_maintenance_batch_limit', static fn (): int => 999);
        $this->assertSame(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT, ll_tools_expired_transient_maintenance_batch_limit());
        $this->removeAllFilters();

        $this->addFilter('ll_tools_expired_transient_maintenance_runtime_seconds', static fn (): float => 99.0);
        $this->assertSame(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MAX_RUNTIME_SECONDS, ll_tools_expired_transient_maintenance_runtime_seconds());
        $this->removeAllFilters();

        $this->addFilter('ll_tools_expired_transient_maintenance_grace_seconds', static fn (): int => 0);
        $this->assertSame(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MIN_GRACE_SECONDS, ll_tools_expired_transient_maintenance_grace_seconds());
        $this->removeAllFilters();

        $keys = [];
        for ($index = 1; $index <= 3; $index++) {
            $key = 'll_tools_aspect_stats_' . $this->uniqueSuffix('cap_' . $index);
            $keys[] = $key;
            $this->addTransientRows($key, 'value-' . $index, $index);
        }

        $this->addFilter('ll_tools_expired_transient_maintenance_batch_limit', static fn (): int => 2);
        $this->addFilter('ll_tools_expired_transient_maintenance_runtime_seconds', static fn (): float => 2.0);

        $telemetry = ll_tools_run_expired_transient_maintenance();

        $this->assertSame(2, $telemetry['selected_count']);
        $this->assertSame(2, $telemetry['processed_count']);
        $this->assertSame(2, $telemetry['deleted_transient_count']);
        $remainingTimeouts = 0;
        foreach ($keys as $key) {
            if (get_option('_transient_timeout_' . $key, false) !== false) {
                $remainingTimeouts++;
            }
        }
        $this->assertSame(1, $remainingTimeouts);
    }

    public function test_active_lock_skips_work_and_stale_lock_release_cannot_remove_successor(): void
    {
        $key = 'll_wc_words_' . $this->uniqueSuffix('lock');
        $this->addTransientRows($key, 'locked-value', time() - HOUR_IN_SECONDS);

        $activePayload = ll_tools_expired_transient_maintenance_lock_payload(time() + 10 * MINUTE_IN_SECONDS, 'active-owner');
        $this->addOption(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION, $activePayload);

        $telemetry = ll_tools_run_expired_transient_maintenance();

        $this->assertSame(1, $telemetry['lock_bypass_count']);
        $this->assertSame('locked-value', get_option('_transient_' . $key));
        $this->assertSame($activePayload, get_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION));

        delete_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION);
        $stalePayload = ll_tools_expired_transient_maintenance_lock_payload(time() - 1, 'stale-owner');
        $this->addOption(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION, $stalePayload);

        $successorPayload = ll_tools_expired_transient_maintenance_acquire_lock(time());
        $this->assertNotSame('', $successorPayload);
        $this->assertNotSame($stalePayload, $successorPayload);
        $this->assertSame($successorPayload, get_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION));

        ll_tools_expired_transient_maintenance_release_lock($stalePayload);
        $this->assertSame($successorPayload, get_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION));

        ll_tools_expired_transient_maintenance_release_lock($successorPayload);
        $this->assertFalse(get_option(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION, false));
    }

    public function test_external_object_cache_bypasses_database_cleanup(): void
    {
        $key = 'll_vl_grid_' . $this->uniqueSuffix('external_cache');
        $this->addTransientRows($key, 'external-cache-value', time() - HOUR_IN_SECONDS);
        wp_using_ext_object_cache(true);

        $telemetry = ll_tools_run_expired_transient_maintenance();

        $this->assertSame(1, $telemetry['external_cache_bypass_count']);
        $this->assertSame(0, $telemetry['selected_count']);
        $this->assertSame('external-cache-value', get_option('_transient_' . $key));
        $this->assertNotFalse(get_option('_transient_timeout_' . $key, false));
    }

    public function test_schedule_is_hourly_idempotent_and_clearable(): void
    {
        wp_clear_scheduled_hook(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK);
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK));

        ll_tools_schedule_expired_transient_maintenance();
        $firstTimestamp = wp_next_scheduled(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK);
        $this->assertIsInt($firstTimestamp);
        $this->assertSame('hourly', wp_get_schedule(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK));

        ll_tools_schedule_expired_transient_maintenance();
        $this->assertSame($firstTimestamp, wp_next_scheduled(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK));

        ll_tools_clear_expired_transient_maintenance_schedule();
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK));

        // Restore the normal plugin runtime state for any later integration tests.
        ll_tools_schedule_expired_transient_maintenance();
    }

    private function uniqueSuffix(string $label): string
    {
        return 'codex_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($label)) . '_' . strtolower(wp_generate_password(10, false, false));
    }

    private function addOption(string $optionName, string $value): void
    {
        delete_option($optionName);
        $this->assertTrue(add_option($optionName, $value, '', false));
        $this->createdOptions[] = $optionName;
    }

    private function addValueRow(string $transientKey, string $value): void
    {
        $this->addOption('_transient_' . $transientKey, $value);
    }

    private function addTimeoutRow(string $transientKey, int $timeout): void
    {
        $this->addOption('_transient_timeout_' . $transientKey, (string) $timeout);
    }

    private function addTransientRows(string $transientKey, string $value, int $timeout): void
    {
        $this->addValueRow($transientKey, $value);
        $this->addTimeoutRow($transientKey, $timeout);
    }

    private function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        add_filter($hook, $callback, $priority);
        $this->filters[] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
        ];
    }

    private function removeAllFilters(): void
    {
        foreach ($this->filters as $filter) {
            remove_filter($filter['hook'], $filter['callback'], $filter['priority']);
        }
        $this->filters = [];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    private function findCandidate(array $candidates, string $transientKey): ?array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['transient_key'] ?? '') === $transientKey) {
                return $candidate;
            }
        }

        return null;
    }
}
