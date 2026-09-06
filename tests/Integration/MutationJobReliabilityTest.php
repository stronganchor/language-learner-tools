<?php
declare(strict_types=1);

final class MutationJobReliabilityTest extends LL_Tools_TestCase
{
    private array $jobOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        ll_tools_rest_automation_load_import_helpers();
    }

    protected function tearDown(): void
    {
        foreach ((array) ($GLOBALS['ll_tools_import_job_leases'] ?? []) as $job_id => $lock) {
            ll_tools_import_job_process_lock_release((string) $job_id, (string) $lock['owner']);
        }
        foreach ((array) ($GLOBALS['ll_tools_mutation_job_owners'] ?? []) as $lease) {
            ll_tools_mutation_job_release($lease);
        }
        foreach ($this->jobOptions as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    private function importJob(string $status = 'running'): array
    {
        $id = wp_generate_uuid4();
        $job = [
            'id' => $id, 'user_id' => get_current_user_id(), 'status' => $status,
            'phase' => 'words', 'word_chunk_files' => [], 'finalize_step' => 0,
            'payload_counts' => [], 'result' => ll_tools_import_job_default_result(),
        ];
        $this->jobOptions[] = ll_tools_import_job_get_option_key($id);
        $this->jobOptions[] = ll_tools_import_job_get_process_lock_option_key($id);
        $saved = ll_tools_import_job_save($id, $job);
        $this->assertNotWPError($saved);
        return $saved;
    }

    private function metadataFixture(array $set = ['word_note' => 'New note']): array
    {
        $term = wp_insert_term('Job ' . wp_generate_uuid4(), 'wordset');
        $this->assertNotWPError($term);
        $wordset_id = (int) $term['term_id'];
        $word_id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft', 'post_title' => 'Original title']);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');
        update_post_meta($word_id, 'll_word_usage_note', 'Old note');
        $request = new WP_REST_Request('POST');
        $request->set_param('wordset', $wordset_id);
        $request->set_param('updates', [['word_id' => $word_id, 'set' => $set]]);
        $created = ll_tools_rest_automation_create_word_metadata_plan_job($request);
        $this->assertNotWPError($created);
        $this->assertSame(201, $created->get_status());
        $job_id = (string) $created->get_data()['job']['id'];
        $this->jobOptions[] = ll_tools_rest_word_metadata_plan_job_option_name($job_id);
        $request->set_param('job_id', $job_id);
        return [$request, $word_id, $job_id, $wordset_id];
    }

    public function test_connection_lock_survives_expired_option_and_rejects_stale_release(): void
    {
        global $wpdb;
        $job = $this->importJob();
        $id = $job['id'];
        $lease = ll_tools_import_job_process_lock_acquire($id, $job);
        $this->assertNotWPError($lease);
        $this->assertLessThanOrEqual(64, strlen($lease['mutation_lease']['name']));
        $expired = $lease;
        $expired['expires_at'] = 1;
        update_option(ll_tools_import_job_get_process_lock_option_key($id), $expired, false);

        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        try {
            $this->assertSame('0', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lease['mutation_lease']['name'])));
            ll_tools_import_job_process_lock_release($id, 'wrong-owner');
            $this->assertTrue(ll_tools_mutation_job_owns($lease['mutation_lease']));
            $this->assertWPError(ll_tools_import_job_process_with_lock($job));
            ll_tools_import_job_process_lock_release($id, $lease['owner']);
            $successor = ll_tools_import_job_process_lock_acquire($id, $job);
            $this->assertNotWPError($successor);
            ll_tools_import_job_process_lock_release($id, $lease['owner']);
            $this->assertTrue(ll_tools_mutation_job_owns($successor['mutation_lease']));
            ll_tools_import_job_process_lock_release($id, $successor['owner']);
        } finally {
            $other->close();
        }
    }

    public function test_stale_import_snapshot_is_reloaded_and_checkpoint_stays_locked(): void
    {
        $stale = $this->importJob();
        $id = $stale['id'];
        $option = ll_tools_import_job_get_option_key($id);
        $contender = null;
        $filter = static function ($value, $old) use ($stale, &$contender) {
            if (is_array($value) && empty($value['pending_step']) && $value['phase'] === 'finalize' && $contender === null) {
                $contender = ll_tools_import_job_process_with_lock($stale);
            }
            return $value;
        };
        add_filter('pre_update_option_' . $option, $filter, 10, 2);
        try {
            $first = ll_tools_import_job_process_with_lock($stale);
        } finally {
            remove_filter('pre_update_option_' . $option, $filter);
        }
        $this->assertNotWPError($first);
        $this->assertSame('finalize', $first['phase']);
        $this->assertWPError($contender);
        $this->assertSame('ll_tools_import_job_process_locked', $contender->get_error_code());
        $second = ll_tools_import_job_process_with_lock($stale);
        $this->assertNotWPError($second);
        $this->assertSame(2, $second['finalize_step']);
        $this->assertSame(2, ll_tools_import_job_get($id)['finalize_step']);
        $this->assertArrayNotHasKey('pending_step', ll_tools_import_job_get($id));
    }

    public function test_checkpoint_cannot_overwrite_successor_after_ownership_changes(): void
    {
        $option = 'll_review_checkpoint_' . str_replace('-', '_', wp_generate_uuid4());
        $this->jobOptions[] = $option;
        $first = ll_tools_mutation_job_acquire('review', $option);
        $this->assertNotWPError($first);
        $this->assertNotWPError(ll_tools_mutation_job_write_option($option, ['status' => 'running'], $first));
        $successor = null;
        $hook = static function ($value) use ($first, $option, &$successor) {
            ll_tools_mutation_job_release($first);
            $successor = ll_tools_mutation_job_acquire('review', $option);
            update_option($option, ['status' => 'discarded'], false);
            return $value;
        };
        // Remove before the injected ordinary update_option to avoid recursion.
        $once = null;
        $once = static function ($value) use (&$once, $option, $hook) {
            remove_filter('pre_update_option_' . $option, $once);
            return $hook($value);
        };
        add_filter('pre_update_option_' . $option, $once);
        try {
            $stale = ll_tools_mutation_job_write_option($option, ['status' => 'completed'], $first);
        } finally {
            remove_filter('pre_update_option_' . $option, $once);
        }
        $this->assertWPError($stale);
        $this->assertNotWPError($successor);
        $this->assertSame(['status' => 'discarded'], ll_tools_mutation_job_read_option($option));
        ll_tools_mutation_job_release($first);
        $this->assertTrue(ll_tools_mutation_job_owns($successor));
        ll_tools_mutation_job_release($successor);
    }

    public function test_failed_import_checkpoint_preserves_marker_and_blocks_replay(): void
    {
        $job = $this->importJob();
        $option = ll_tools_import_job_get_option_key($job['id']);
        $filter = static function ($value, $old) {
            return is_array($value) && empty($value['pending_step']) ? $old : $value;
        };
        add_filter('pre_update_option_' . $option, $filter, 10, 2);
        try {
            $failed = ll_tools_import_job_process_with_lock($job);
        } finally {
            remove_filter('pre_update_option_' . $option, $filter);
        }
        $this->assertWPError($failed);
        $this->assertSame('ll_tools_mutation_job_storage_failed', $failed->get_error_code());
        $fresh = ll_tools_import_job_get($job['id']);
        $this->assertNotEmpty($fresh['pending_step']);
        $this->assertFalse(ll_tools_import_job_get_snapshot($fresh)['canResume']);
        $replay = ll_tools_import_job_process_with_lock($job);
        $this->assertWPError($replay);
        $this->assertSame('ll_tools_mutation_job_recovery_required', $replay->get_error_code());
    }

    public function test_checkpoint_sql_fences_creation_and_update_after_connection_ownership_loss(): void
    {
        global $wpdb;
        foreach ([false, true] as $existing) {
            $option = 'll_review_fence_' . str_replace('-', '_', wp_generate_uuid4());
            $this->jobOptions[] = $option;
            $lease = ll_tools_mutation_job_acquire('review', $option);
            $this->assertNotWPError($lease);
            if ($existing) {
                $this->assertNotWPError(ll_tools_mutation_job_write_option($option, ['status' => 'running'], $lease));
            }
            $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $injected = false;
            $acquired = null;
            $hook = static function (string $sql) use ($wpdb, $other, $option, $lease, &$injected, &$acquired): string {
                if (!$injected && str_contains($sql, $option) && str_contains($sql, 'IS_USED_LOCK')
                    && (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE'))) {
                    $injected = true;
                    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lease['name']));
                    $acquired = $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lease['name']));
                }
                return $sql;
            };
            add_filter('query', $hook);
            try {
                $result = ll_tools_mutation_job_write_option($option, ['status' => 'completed'], $lease);
            } finally {
                remove_filter('query', $hook);
                $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lease['name']));
                $other->close();
                ll_tools_mutation_job_release($lease);
            }
            $this->assertTrue($injected);
            $this->assertSame('1', (string) $acquired);
            $this->assertWPError($result);
            $this->assertSame($existing ? ['status' => 'running'] : null, ll_tools_mutation_job_read_option($option));
        }
    }

    public function test_checkpoint_sql_rejects_changed_stored_bytes_under_same_connection(): void
    {
        global $wpdb;
        $option = 'll_review_cas_' . str_replace('-', '_', wp_generate_uuid4());
        $this->jobOptions[] = $option;
        $lease = ll_tools_mutation_job_acquire('review', $option);
        $this->assertNotWPError($lease);
        $this->assertNotWPError(ll_tools_mutation_job_write_option($option, ['status' => 'running'], $lease));
        $injected = false;
        $hook = static function (string $sql) use ($wpdb, $option, &$injected): string {
            if (!$injected && str_starts_with($sql, 'UPDATE') && str_contains($sql, $option) && str_contains($sql, 'IS_USED_LOCK')) {
                $injected = true;
                $wpdb->update($wpdb->options, ['option_value' => maybe_serialize(['status' => 'discarded'])], ['option_name' => $option]);
            }
            return $sql;
        };
        add_filter('query', $hook);
        try {
            $result = ll_tools_mutation_job_write_option($option, ['status' => 'completed'], $lease);
        } finally {
            remove_filter('query', $hook);
            ll_tools_mutation_job_release($lease);
        }
        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame(['status' => 'discarded'], ll_tools_mutation_job_read_option($option));
    }

    public function test_failed_import_admission_does_not_process_or_leave_marker(): void
    {
        $job = $this->importJob();
        $option = ll_tools_import_job_get_option_key($job['id']);
        $filter = static fn($value, $old) => $old;
        add_filter('pre_update_option_' . $option, $filter, 10, 2);
        try {
            $failed = ll_tools_import_job_process_with_lock($job);
        } finally {
            remove_filter('pre_update_option_' . $option, $filter);
        }
        $this->assertWPError($failed);
        $this->assertSame($job, ll_tools_import_job_get($job['id']));
        $this->assertNotWPError(ll_tools_import_job_process_with_lock($job));
    }

    public function test_import_discard_revalidates_status_and_contends_with_processing(): void
    {
        $stale = $this->importJob('paused');
        $running = $stale;
        $running['status'] = 'running';
        ll_tools_import_job_save($stale['id'], $running);
        $denied = ll_tools_import_job_discard($stale);
        $this->assertWPError($denied);
        $this->assertSame('ll_tools_import_job_not_paused', $denied->get_error_code());
        $lock = ll_tools_import_job_process_lock_acquire($stale['id'], $running);
        $this->assertNotWPError($lock);
        try {
            $blocked = ll_tools_import_job_discard($stale);
            $this->assertWPError($blocked);
            $this->assertSame('ll_tools_import_job_process_locked', $blocked->get_error_code());
        } finally {
            ll_tools_import_job_process_lock_release($stale['id'], $lock['owner']);
        }
        $this->assertSame('running', ll_tools_import_job_get($stale['id'])['status']);
    }

    public function test_metadata_process_blocks_process_and_discard_before_checkpoint(): void
    {
        [$request, $word_id, $job_id] = $this->metadataFixture();
        $process = null;
        $discard = null;
        $hook = static function ($check, $id, $key) use ($request, $word_id, &$process, &$discard) {
            if ((int) $id === $word_id && $key === 'll_word_usage_note') {
                // All accepted spellings of the storage ID share one lock.
                $alias = clone $request;
                $alias->set_param('job_id', strtoupper(str_replace('-', '_', (string) $request->get_param('job_id'))));
                $process = ll_tools_rest_automation_process_word_metadata_plan_job($alias);
                $discard = ll_tools_rest_automation_discard_word_metadata_plan_job($alias);
            }
            return $check;
        };
        add_filter('update_post_metadata', $hook, 10, 3);
        try {
            $result = ll_tools_rest_automation_process_word_metadata_plan_job($request);
        } finally {
            remove_filter('update_post_metadata', $hook);
        }
        $this->assertNotWPError($result);
        $this->assertWPError($process);
        $this->assertWPError($discard);
        $this->assertSame('ll_tools_mutation_job_locked', $process->get_error_code());
        $this->assertSame('ll_tools_mutation_job_locked', $discard->get_error_code());
        $fresh = ll_tools_rest_word_metadata_plan_job_get($job_id);
        $this->assertSame(1, $fresh['current_index']);
        $this->assertSame('completed', $fresh['status']);
        $this->assertSame(1, $fresh['summary']['updated_count']);
        $this->assertArrayNotHasKey('pending_step', $fresh);
    }

    public function test_import_discard_failed_delete_returns_fresh_recovery_snapshot(): void
    {
        $job = $this->importJob('paused');
        $option = ll_tools_import_job_get_option_key($job['id']);
        $request = new WP_REST_Request('POST');
        $request->set_param('job_id', $job['id']);
        $hook = static fn(string $sql): string => str_starts_with($sql, 'DELETE') && str_contains($sql, $option) ? 'SELECT 1' : $sql;
        add_filter('query', $hook);
        try {
            $result = ll_tools_rest_automation_import_discard($request);
        } finally {
            remove_filter('query', $hook);
        }
        $this->assertWPError($result);
        $data = $result->get_error_data();
        $this->assertTrue($data['job']['recoveryRequired']);
        $this->assertFalse($data['job']['canResume']);
        $this->assertFalse($data['job']['canDiscard']);
        $this->assertNotEmpty($data['job']['errorMessage']);
        $this->assertNotEmpty(ll_tools_import_job_get($job['id'])['pending_step']);
        $this->assertWPError(ll_tools_import_job_discard($job));
    }

    public function test_metadata_failed_checkpoint_reports_uncertain_rows_and_blocks_replay(): void
    {
        [$request, $word_id, $job_id] = $this->metadataFixture();
        $option = ll_tools_rest_word_metadata_plan_job_option_name($job_id);
        $filter = static fn($value, $old) => is_array($value) && empty($value['pending_step']) ? $old : $value;
        add_filter('pre_update_option_' . $option, $filter, 10, 2);
        try {
            $failed = ll_tools_rest_automation_process_word_metadata_plan_job($request);
        } finally {
            remove_filter('pre_update_option_' . $option, $filter);
        }
        $this->assertWPError($failed);
        $this->assertSame('ll_tools_mutation_job_storage_failed', $failed->get_error_code());
        $this->assertSame('New note', get_post_meta($word_id, 'll_word_usage_note', true));
        $job = ll_tools_rest_word_metadata_plan_job_get($job_id);
        $this->assertSame(0, $job['current_index']);
        $this->assertTrue(ll_tools_rest_word_metadata_plan_job_summary($job)['recovery_required']);
        $replay = ll_tools_rest_automation_process_word_metadata_plan_job($request);
        $discard = ll_tools_rest_automation_discard_word_metadata_plan_job($request);
        $this->assertWPError($replay);
        $this->assertWPError($discard);
        $this->assertSame('ll_tools_mutation_job_recovery_required', $replay->get_error_code());
        $this->assertSame('ll_tools_mutation_job_recovery_required', $discard->get_error_code());
    }

    public function test_metadata_discard_failure_does_not_report_success_or_lose_job(): void
    {
        [$request, $word_id, $job_id] = $this->metadataFixture();
        $option = ll_tools_rest_word_metadata_plan_job_option_name($job_id);
        $filter = static fn($value, $old) => $old;
        add_filter('pre_update_option_' . $option, $filter, 10, 2);
        try {
            $result = ll_tools_rest_automation_discard_word_metadata_plan_job($request);
        } finally {
            remove_filter('pre_update_option_' . $option, $filter);
        }
        $this->assertWPError($result);
        $this->assertSame('running', ll_tools_rest_word_metadata_plan_job_get($job_id)['status']);
        $this->assertSame('Old note', get_post_meta($word_id, 'll_word_usage_note', true));
        $this->assertNotWPError(ll_tools_rest_automation_discard_word_metadata_plan_job($request));
        $this->assertSame('discarded', ll_tools_rest_word_metadata_plan_job_get($job_id)['status']);
        $this->assertNotWPError(ll_tools_rest_automation_process_word_metadata_plan_job($request));
        $this->assertSame('Old note', get_post_meta($word_id, 'll_word_usage_note', true));
    }

    public function test_metadata_create_failure_does_not_return_a_job_id(): void
    {
        [$request, $word_id] = $this->metadataFixture();
        $filter = static function ($value, $option, $old) {
            return str_starts_with($option, 'll_tools_word_metadata_plan_job_') ? $old : $value;
        };
        add_filter('pre_update_option', $filter, 10, 3);
        try {
            $failed = ll_tools_rest_automation_create_word_metadata_plan_job($request);
        } finally {
            remove_filter('pre_update_option', $filter);
        }
        $this->assertWPError($failed);
        $this->assertSame('ll_tools_mutation_job_storage_failed', $failed->get_error_code());
        $this->assertSame('Old note', get_post_meta($word_id, 'll_word_usage_note', true));
    }

    public function test_failed_pos_clear_keeps_pos_and_all_grammar(): void
    {
        global $wpdb;
        [$request, $word_id, $job_id, $wordset_id] = $this->metadataFixture();
        $noun = get_term_by('slug', 'noun', 'part_of_speech');
        if (!$noun instanceof WP_Term) {
            $created = wp_insert_term('Noun', 'part_of_speech', ['slug' => 'noun']);
            $noun = get_term((int) $created['term_id'], 'part_of_speech');
        }
        wp_set_object_terms($word_id, [(int) $noun->term_id], 'part_of_speech');
        $grammar = ['ll_grammatical_gender', 'll_grammatical_plurality', 'll_verb_tense', 'll_verb_mood'];
        foreach ($grammar as $key) {
            update_post_meta($word_id, $key, 'preserve');
        }
        $hook = static function (string $sql) use ($wpdb, $word_id): string {
            return str_starts_with($sql, "DELETE FROM {$wpdb->term_relationships}") && str_contains($sql, "object_id = $word_id") ? 'SELECT 1' : $sql;
        };
        add_filter('query', $hook);
        try {
            $result = ll_tools_rest_word_metadata_plan_apply_field($wordset_id, $word_id, 'part_of_speech', '');
        } finally {
            remove_filter('query', $hook);
        }
        $this->assertWPError($result);
        $this->assertSame('noun', ll_tools_rest_word_metadata_plan_word_current_pos_slug($word_id));
        foreach ($grammar as $key) {
            $this->assertSame('preserve', get_post_meta($word_id, $key, true));
        }
    }

    public function test_failed_pos_assignment_does_not_clear_existing_grammar(): void
    {
        global $wpdb;
        [$request, $word_id, $job_id, $wordset_id] = $this->metadataFixture();
        $term = wp_insert_term('Job verb ' . wp_generate_uuid4(), 'part_of_speech');
        $this->assertNotWPError($term);
        $pos = get_term((int) $term['term_id'], 'part_of_speech');
        update_post_meta($word_id, 'll_grammatical_gender', 'preserve');
        $hook = static function (string $sql) use ($wpdb): string {
            return str_starts_with($sql, 'INSERT INTO') && str_contains($sql, $wpdb->term_relationships)
                ? 'INSERT INTO ll_tools_missing_review_table (id) VALUES (1)' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $hook);
        try {
            $result = ll_tools_rest_word_metadata_plan_apply_field($wordset_id, $word_id, 'part_of_speech', $pos->slug);
        } finally {
            remove_filter('query', $hook);
            $wpdb->suppress_errors($previous);
        }
        $this->assertWPError($result);
        $this->assertSame('preserve', get_post_meta($word_id, 'll_grammatical_gender', true));
    }

    public function test_mixed_metadata_failure_reports_partial_fields_and_invalidates_changes(): void
    {
        [$request, $word_id, $job_id] = $this->metadataFixture(['word_title' => 'Updated title', 'word_note' => 'New note']);
        $filter = static fn($check, $id, $key) => (int) $id === $word_id && $key === 'll_word_usage_note' ? false : $check;
        add_filter('update_post_metadata', $filter, 10, 3);
        try {
            $result = ll_tools_rest_automation_process_word_metadata_plan_job($request);
        } finally {
            remove_filter('update_post_metadata', $filter);
        }
        $this->assertNotWPError($result);
        $data = $result->get_data();
        $this->assertSame('completed', $data['job']['status']);
        $this->assertSame(1, $data['job']['summary']['error_count']);
        $this->assertSame(0, $data['job']['summary']['updated_count']);
        $this->assertTrue($data['processed'][0]['partial']);
        $this->assertContains('word_title', $data['processed'][0]['applied_fields']);
        $this->assertSame('Updated title', $data['processed'][0]['after']['word_title']);
        $this->assertSame('Old note', $data['processed'][0]['after']['word_note']);
        $this->assertTrue($data['job']['summary']['invalidated_wordset_cache']);
    }
}
