<?php
declare(strict_types=1);

final class LmsPrivacyLifecycleTest extends LL_Tools_TestCase
{
    private ?wpdb $myisamDdlConnection = null;
    private string $myisamPostmetaTable = '';

    protected function setUp(): void
    {
        if ($this->name() === 'test_deleted_empty_account_completes_with_real_myisam_postmeta_without_local_mutation') {
            global $wpdb;

            // An auxiliary connection avoids committing the test transaction,
            // and the real fixture leaves every core test table unchanged.
            $this->myisamPostmetaTable = $wpdb->prefix . 'll_privacy_myisam_' . substr(md5(wp_generate_uuid4()), 0, 12);
            $this->myisamDdlConnection = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $this->assertTrue(mysqli_query($this->myisamDdlConnection->dbh, 'SET SESSION lock_wait_timeout = 3'));
            $this->assertTrue(mysqli_query(
                $this->myisamDdlConnection->dbh,
                "CREATE TABLE {$this->myisamPostmetaTable} (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255), meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id), KEY meta_key (meta_key(191))) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
            ));
        }
        parent::setUp();
        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_grade_delivery_schema());
        $this->assertTrue(ll_tools_install_google_classroom_schema());
        $this->assertTrue(ll_tools_install_user_progress_schema());
        $this->assertTrue(ll_tools_install_offline_app_session_schema());
        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        remove_all_filters('ll_tools_lms_deleted_user_cleanup_passes');
        remove_all_filters('ll_tools_lms_privacy_erasure_fence_ttl');
        remove_all_filters('ll_tools_lms_privacy_erasure_call_ttl');
        remove_all_filters('ll_tools_lms_privacy_erasure_job_token');
        remove_all_filters('ll_tools_privacy_erasure_table_engine');
        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
        foreach (ll_tools_privacy_deleted_user_lms_cleanup_queue() as $userId => $queuedAt) {
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup((int) $userId);
        }
        wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_HOOK);
        delete_transient(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT);
        $privacyFenceNames = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(LL_TOOLS_LMS_PRIVACY_ERASURE_OPTION_PREFIX) . '%'
        ));
        foreach ((array) $privacyFenceNames as $optionName) {
            delete_option((string) $optionName);
        }
        try {
            parent::tearDown();
        } finally {
            // Parent rollback releases the metadata lock before fixture DDL.
            if ($this->myisamDdlConnection instanceof wpdb) {
                try {
                    $this->assertTrue(mysqli_query(
                        $this->myisamDdlConnection->dbh,
                        "DROP TABLE IF EXISTS {$this->myisamPostmetaTable}"
                    ));
                } finally {
                    $this->myisamDdlConnection->close();
                    $this->myisamDdlConnection = null;
                    $this->myisamPostmetaTable = '';
                }
            }
        }
    }

    public function test_privacy_surfaces_report_errors_instead_of_looping_when_schema_is_unavailable(): void
    {
        $userId = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'lms-privacy-retry@example.test',
        ]);
        $user = get_userdata($userId);
        $this->assertInstanceOf(WP_User::class, $user);

        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);
        try {
            $export = ll_tools_privacy_export_lms_assignments((string) $user->user_email, 1);
            $this->assertWPError($export);

            $erasure = ll_tools_privacy_erase_personal_data((string) $user->user_email, 1);
            $this->assertWPError($erasure);
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        } finally {
            $this->assertTrue(ll_tools_install_lms_assignment_schema());
        }

        delete_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION);
        try {
            $export = ll_tools_privacy_export_grade_deliveries((string) $user->user_email, 1);
            $this->assertWPError($export);

            $erasure = ll_tools_privacy_erase_personal_data((string) $user->user_email, 1);
            $this->assertWPError($erasure);
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        } finally {
            $this->assertTrue(ll_tools_install_grade_delivery_schema());
        }

        delete_option(LL_TOOLS_GOOGLE_CLASSROOM_SCHEMA_VERIFIED_OPTION);
        try {
            $export = ll_tools_privacy_export_google_classroom_connections((string) $user->user_email, 1);
            $this->assertWPError($export);
        } finally {
            $this->assertTrue(ll_tools_install_google_classroom_schema());
        }
    }

    public function test_account_deletion_hook_removes_local_lms_rows_without_network_work(): void
    {
        global $wpdb;

        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $now = gmdate('Y-m-d H:i:s');
        $assignmentTables = ll_tools_lms_assignment_table_names();
        $deliveryTables = ll_tools_grade_delivery_table_names();
        $googleTables = ll_tools_google_classroom_table_names();

        $this->assertSame(1, $wpdb->insert($assignmentTables['grades'], [
            'assignment_id' => 987001,
            'revision_id' => 987002,
            'user_id' => $userId,
            'selected_attempt_id' => 987003,
            'grade_revision' => 1,
            'score_given' => 4,
            'score_maximum' => 5,
            'points_given' => '8.0000',
            'points_maximum' => '10.0000',
            'grade_policy' => 'latest',
            'updated_at' => $now,
        ]));
        $this->assertSame(1, $wpdb->insert($deliveryTables['identities'], [
            'adapter' => 'privacy_test',
            'connection_key_hash' => hash('sha256', 'connection'),
            'subject_key_hash' => hash('sha256', 'subject'),
            'learner_user_id' => $userId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertSame(1, $wpdb->insert($googleTables['connections'], [
            'teacher_user_id' => $userId,
            'google_sub_hash' => hash('sha256', 'google-sub'),
            'credential_envelope' => 'test-envelope',
            'scopes_json' => '[]',
            'status' => 'connected',
            'token_version' => 1,
            'connected_at' => $now,
            'updated_at' => $now,
            'disconnected_at' => null,
        ]));

        $this->assertSame(1, has_action('delete_user', 'll_tools_privacy_prepare_deleted_user_lms_cleanup'));
        $this->assertSame(1, has_action('wpmu_delete_user', 'll_tools_privacy_prepare_network_deleted_user_lms_cleanup'));
        $this->assertSame(1, has_action('remove_user_from_blog', 'll_tools_privacy_prepare_removed_user_lms_cleanup'));
        $this->assertNotFalse(has_action('remove_user_from_blog', 'll_tools_teacher_class_cleanup_removed_user'));
        $this->assertNotFalse(has_action('deleted_user', 'll_tools_privacy_cleanup_after_deleted_user'));
        $this->assertNotFalse(has_action(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, 'll_tools_privacy_cleanup_deleted_user_lms_data'));

        do_action('delete_user', $userId);
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $accountDeletionLease = ll_tools_privacy_begin_user_lms_erasure($userId, 'account-deletion-test');
        $this->assertIsString($accountDeletionLease);

        $transaction = ll_tools_lms_assignment_begin_transaction();
        $this->assertIsArray($transaction);
        try {
            $this->assertFalse(ll_tools_lms_assignment_lock_user($userId));
        } finally {
            ll_tools_lms_assignment_rollback_transaction($transaction);
        }

        $this->assertTrue(wp_delete_user($userId));

        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignmentTables['grades']} WHERE user_id = %d",
            $userId
        )));
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$deliveryTables['identities']} WHERE learner_user_id = %d",
            $userId
        )));
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$googleTables['connections']} WHERE teacher_user_id = %d",
            $userId
        )));
        $this->assertFalse(get_userdata($userId));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
        $this->assertArrayNotHasKey($userId, ll_tools_privacy_deleted_user_lms_cleanup_queue());
        $this->assertFalse(get_option(ll_tools_privacy_user_lms_erasure_option_name($userId), false));
        $this->assertFalse(ll_tools_privacy_deleted_user_post_seen($userId));
    }

    public function test_account_deletion_tombstone_cleans_a_writer_that_outlives_the_first_barrier(): void
    {
        global $wpdb;

        $fixture = $this->createLocalPrivacyFixture('account-delete-race');
        $userId = (int) $fixture['user_id'];
        $sessionTable = ll_tools_offline_app_session_table();
        $progressTables = ll_tools_user_progress_table_names();
        $sessionRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$sessionTable} WHERE user_id = %d",
            $userId
        ), ARRAY_A);
        $wordRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$progressTables['words']} WHERE user_id = %d",
            $userId
        ), ARRAY_A);
        $eventRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$progressTables['events']} WHERE user_id = %d",
            $userId
        ), ARRAY_A);
        $this->assertNotEmpty($sessionRows);
        $this->assertNotEmpty($wordRows);
        $this->assertNotEmpty($eventRows);

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $denyBarrier = static function (string $query): string {
            return stripos($query, 'SELECT GET_LOCK(') !== false ? 'SELECT 0' : $query;
        };
        add_filter('query', $denyBarrier);
        try {
            $this->assertTrue(wp_delete_user($userId));
        } finally {
            remove_filter('query', $denyBarrier);
        }

        $this->assertFalse(get_userdata($userId));
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertContains(
            $userId,
            ll_tools_teacher_class_get_student_ids((int) $fixture['class_id'])
        );

        // Model a request that passed the fence before deletion and commits
        // only after core and priority-10 cleanup have finished.
        foreach ($sessionRows as $row) {
            $this->assertSame(1, $wpdb->insert($sessionTable, $row));
        }
        foreach ($wordRows as $row) {
            $this->assertSame(1, $wpdb->insert($progressTables['words'], $row));
        }
        foreach ($eventRows as $row) {
            $this->assertSame(1, $wpdb->insert($progressTables['events'], $row));
        }
        $this->assertSame(1, $wpdb->insert($wpdb->usermeta, [
            'user_id' => $userId,
            'meta_key' => LL_TOOLS_USER_GOALS_META,
            'meta_value' => maybe_serialize(['daily_minutes' => 45]),
        ]));

        $this->makeDeletedAccountRetryDue($userId);
        ll_tools_privacy_cleanup_deleted_user_lms_data($userId);

        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessionTable} WHERE user_id = %d",
            $userId
        )));
        foreach (['words', 'events'] as $tableKey) {
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$progressTables[$tableKey]} WHERE user_id = %d",
                $userId
            )));
        }
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d",
            $userId
        )));
        $this->assertNotContains(
            $userId,
            ll_tools_teacher_class_get_student_ids((int) $fixture['class_id'])
        );
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
    }

    public function test_manual_erasure_lease_expiry_and_call_ownership_are_fail_safe(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $expiredAt = time() - (6 * MINUTE_IN_SECONDS);
        update_option(
            ll_tools_privacy_user_lms_erasure_option_name($userId),
            $expiredAt,
            false
        );
        add_filter('ll_tools_lms_privacy_erasure_fence_ttl', static fn(): int => 5 * MINUTE_IN_SECONDS);

        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $optionName = ll_tools_privacy_user_lms_erasure_option_name($userId);
        $this->assertSame($expiredAt, (int) get_option($optionName, 0));
        $firstLease = ll_tools_privacy_begin_user_lms_erasure($userId, 'privacy-job-one');
        $this->assertIsString($firstLease);
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertFalse(ll_tools_privacy_begin_user_lms_erasure($userId, 'privacy-job-one'));
        $this->assertFalse(ll_tools_privacy_begin_user_lms_erasure($userId, 'privacy-job-two'));

        $this->assertTrue(ll_tools_privacy_pause_user_lms_erasure($userId, $firstLease));
        $this->assertFalse(ll_tools_privacy_begin_user_lms_erasure($userId, 'privacy-job-two'));
        $secondLease = ll_tools_privacy_begin_user_lms_erasure($userId, 'privacy-job-one');
        $this->assertIsString($secondLease);
        $this->assertNotSame($firstLease, $secondLease);
        $this->assertFalse(ll_tools_privacy_finish_user_lms_erasure($userId, $firstLease));
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertTrue(ll_tools_privacy_finish_user_lms_erasure($userId, $secondLease));
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
    }

    public function test_per_user_deletion_tombstones_survive_other_user_cleanup_and_runtime_resumes_failed_cron(): void
    {
        $firstUser = self::factory()->user->create(['role' => 'subscriber']);
        $secondUser = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($firstUser));
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($secondUser));
        ll_tools_privacy_dequeue_deleted_user_lms_cleanup($firstUser);
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($firstUser));
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($secondUser));

        $blockSchedule = static function ($pre, $event) {
            return is_object($event) && ($event->hook ?? '') === LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK
                ? false
                : $pre;
        };
        add_filter('pre_schedule_event', $blockSchedule, 10, 2);
        try {
            ll_tools_privacy_resume_deleted_user_lms_cleanup();
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$secondUser]));
        } finally {
            remove_filter('pre_schedule_event', $blockSchedule, 10);
        }

        delete_transient(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT);
        ll_tools_privacy_maybe_resume_deleted_user_lms_cleanup();
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$secondUser]));
    }

    public function test_stale_cleanup_event_cannot_recreate_a_completed_tombstone(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($userId));
        $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId, true));
        $this->assertTrue(ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId));

        ll_tools_privacy_cleanup_deleted_user_lms_data($userId);

        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
    }

    public function test_manual_privacy_erasure_fences_writes_and_reports_oauth_state_removal(): void
    {
        global $wpdb;

        $userId = self::factory()->user->create([
            'role' => 'administrator',
            'user_email' => 'lms-privacy-fence@example.test',
        ]);
        $statesTable = ll_tools_google_classroom_table_names()['oauth_states'];
        $now = time();
        $this->assertSame(1, $wpdb->insert($statesTable, [
            'state_hash' => hash('sha256', 'privacy-fence-state'),
            'teacher_user_id' => $userId,
            'pkce_envelope' => 'privacy-test-envelope',
            'callback_hash' => hash('sha256', 'privacy-test-callback'),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + MINUTE_IN_SECONDS),
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]));

        $manualLease = ll_tools_privacy_begin_user_lms_erasure($userId, 'manual-fence-test');
        $this->assertIsString($manualLease);
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $transaction = ll_tools_lms_assignment_begin_transaction();
        $this->assertIsArray($transaction);
        try {
            $this->assertFalse(ll_tools_lms_assignment_lock_user($userId));
        } finally {
            ll_tools_lms_assignment_rollback_transaction($transaction);
        }
        $this->assertTrue(ll_tools_privacy_finish_user_lms_erasure($userId, $manualLease));

        $result = ll_tools_privacy_erase_personal_data('lms-privacy-fence@example.test', 1);
        $this->assertIsArray($result);
        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$statesTable} WHERE teacher_user_id = %d",
            $userId
        )));
    }

    public function test_manual_privacy_erasure_revokes_real_tokens_and_verifies_local_data_removal(): void
    {
        global $wpdb;

        $fixture = $this->createLocalPrivacyFixture('complete');
        $legacyKey = 'legacy' . strtolower(wp_generate_password(8, false, false));
        $this->assertNotFalse(update_user_meta($fixture['user_id'], LL_TOOLS_OFFLINE_APP_SESSION_META, [
            $legacyKey => [
                'secret_hash' => wp_hash_password('legacy-secret'),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
                'last_used_at' => gmdate('Y-m-d H:i:s'),
                'device_id' => 'privacy-legacy-device',
                'profile_id' => 'privacy-legacy-profile',
            ],
        ]));

        $result = ll_tools_privacy_erase_personal_data($fixture['email'], 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);
        $this->assertFalse($result['items_retained']);
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($fixture['user_id']));
        $this->assertNull(ll_tools_offline_app_authenticate_token($fixture['token'], false));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d',
            $fixture['user_id']
        )));
        $this->assertFalse(metadata_exists('user', $fixture['user_id'], LL_TOOLS_OFFLINE_APP_SESSION_META));

        $progressTables = ll_tools_user_progress_table_names();
        foreach (['words', 'events'] as $tableKey) {
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$progressTables[$tableKey]} WHERE user_id = %d",
                $fixture['user_id']
            )));
        }
        $this->assertFalse(metadata_exists('user', $fixture['user_id'], LL_TOOLS_USER_GOALS_META));
        $this->assertFalse(metadata_exists('user', $fixture['user_id'], LL_TOOLS_USER_FAST_TRANSITIONS_META));
        $this->assertFalse(metadata_exists('user', $fixture['user_id'], LL_TOOLS_STUDENT_CLASS_IDS_META));
        $this->assertNotContains(
            $fixture['user_id'],
            ll_tools_teacher_class_get_student_ids($fixture['class_id'])
        );
    }

    public function test_local_privacy_erasure_rolls_back_every_surface_when_progress_delete_fails(): void
    {
        $fixture = $this->createLocalPrivacyFixture('delete-failure');
        $eventsTable = ll_tools_user_progress_table_names()['events'];
        $queryFault = static function (string $query) use ($eventsTable): string {
            if (
                stripos($query, 'DELETE FROM') !== false
                && stripos($query, $eventsTable) !== false
            ) {
                return 'DELETE FROM ll_tools_missing_privacy_progress_events';
            }
            return $query;
        };

        add_filter('query', $queryFault);
        try {
            $result = ll_tools_privacy_erase_personal_data($fixture['email'], 1);
        } finally {
            remove_filter('query', $queryFault);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_privacy_progress_delete_failed', $result->get_error_code());
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($fixture['user_id']));
        $this->assertLocalPrivacyFixturePresent($fixture);
    }

    public function test_local_privacy_erasure_rolls_back_when_user_meta_delete_fails(): void
    {
        global $wpdb;

        $fixture = $this->createLocalPrivacyFixture('meta-failure');
        $metaFault = static function (string $query) use ($wpdb): string {
            if (
                stripos($query, 'DELETE FROM') !== false
                && stripos($query, $wpdb->usermeta) !== false
                && stripos($query, LL_TOOLS_USER_GOALS_META) !== false
            ) {
                return 'DELETE FROM ll_tools_missing_privacy_user_meta';
            }
            return $query;
        };

        add_filter('query', $metaFault);
        try {
            $result = ll_tools_privacy_erase_personal_data($fixture['email'], 1);
        } finally {
            remove_filter('query', $metaFault);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_privacy_user_meta_delete_failed', $result->get_error_code());
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($fixture['user_id']));
        $this->assertLocalPrivacyFixturePresent($fixture);
    }

    public function test_local_privacy_erasure_fails_before_mutation_when_any_rollback_table_is_non_transactional(): void
    {
        $fixture = $this->createLocalPrivacyFixture('engine-failure');
        $engineFault = static function (string $engine, string $tableKey): string {
            return $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        };

        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        try {
            $result = ll_tools_privacy_erase_personal_data($fixture['email'], 1);
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_privacy_transactional_engine_unavailable', $result->get_error_code());
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($fixture['user_id']));
        $this->assertLocalPrivacyFixturePresent($fixture);
    }

    public function test_deleted_empty_account_completes_with_real_myisam_postmeta_without_local_mutation(): void
    {
        global $wpdb;

        $userId = $this->createDeletedAccountTombstone();
        $originalPostmeta = $wpdb->postmeta;
        $localMutations = [];
        $watchQueries = static function (string $query) use (&$localMutations, $wpdb): string {
            if (
                preg_match('/^\s*(?:DELETE|UPDATE|INSERT)\b/i', $query)
                && strpos($query, $wpdb->postmeta) !== false
            ) {
                $localMutations[] = $query;
            }
            return $query;
        };
        try {
            $wpdb->postmeta = $this->myisamPostmetaTable;
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->postmeta), ARRAY_A);
            $this->assertSame('MyISAM', $status['Engine']);
            $this->assertFalse(ll_tools_privacy_local_erasure_engine_status()['ready']);

            add_filter('query', $watchQueries);
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        } finally {
            remove_filter('query', $watchQueries);
            $wpdb->postmeta = $originalPostmeta;
        }

        $this->assertSame([], $localMutations, 'Empty completion must not mutate non-transactional local storage.');
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertFalse(ll_tools_privacy_deleted_user_post_seen($userId));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
    }

    public function test_deleted_account_with_retained_local_data_keeps_fence_and_persisted_engine_backoff(): void
    {
        global $wpdb;

        $fixture = $this->createLocalPrivacyFixture('retained-myisam');
        $userId = $this->createDeletedAccountTombstone((int) $fixture['user_id']);
        $before = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
        $sessionTable = ll_tools_offline_app_session_table();
        $tables = ll_tools_user_progress_table_names();
        $rowsBefore = [
            'sessions' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$sessionTable} WHERE user_id = %d", $userId), ARRAY_A),
            'words' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['words']} WHERE user_id = %d", $userId), ARRAY_A),
            'events' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['events']} WHERE user_id = %d", $userId), ARRAY_A),
            'roster' => get_post_meta((int) $fixture['class_id'], LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, true),
        ];
        $engineFault = static fn(string $engine, string $tableKey): string => $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        $started = time();
        try {
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }

        $blocked = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
        $this->assertSame($before['queued_at'], $blocked['queued_at']);
        $this->assertSame($before['post_seen_at'], $blocked['post_seen_at']);
        $this->assertNotSame('', (string) ($blocked['blocked_reason'] ?? ''));
        $this->assertStringContainsString('MyISAM', implode(' ', (array) ($blocked['blocked_details'] ?? [])));
        $this->assertGreaterThanOrEqual($started + 15 * MINUTE_IN_SECONDS, (int) ($blocked['next_attempt_at'] ?? 0));
        $this->assertSame(1, (int) ($blocked['retry_count'] ?? 0));
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId));
        $this->assertGreaterThanOrEqual((int) $blocked['next_attempt_at'], (int) wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
        $this->assertSame($rowsBefore['sessions'], $wpdb->get_results($wpdb->prepare("SELECT * FROM {$sessionTable} WHERE user_id = %d", $userId), ARRAY_A));
        $this->assertSame($rowsBefore['words'], $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['words']} WHERE user_id = %d", $userId), ARRAY_A));
        $this->assertSame($rowsBefore['events'], $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['events']} WHERE user_id = %d", $userId), ARRAY_A));
        $this->assertSame($rowsBefore['roster'], get_post_meta((int) $fixture['class_id'], LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, true));

        $this->makeDeletedAccountRetryDue($userId);
        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        try {
            $secondStarted = time();
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
            $second = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
            $this->assertSame(2, (int) $second['retry_count']);
            $this->assertGreaterThanOrEqual($secondStarted + 30 * MINUTE_IN_SECONDS, (int) $second['next_attempt_at']);

            $second['retry_count'] = 6;
            $second['next_attempt_at'] = time() - 1;
            update_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId), $second, false);
            ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($userId);
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            $cappedStarted = time();
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
            $capped = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
            $this->assertGreaterThanOrEqual($cappedStarted + 6 * HOUR_IN_SECONDS, (int) $capped['next_attempt_at']);
            $this->assertLessThanOrEqual(time() + 6 * HOUR_IN_SECONDS, (int) $capped['next_attempt_at']);
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }
    }

    public function test_empty_deleted_account_storage_read_errors_never_release_the_fence(): void
    {
        global $wpdb;

        $progressTables = ll_tools_user_progress_table_names();
        $surfaces = [
            'users' => $wpdb->users,
            'sessions' => ll_tools_offline_app_session_table(),
            'progress_words' => $progressTables['words'],
            'progress_events' => $progressTables['events'],
            'user_meta' => $wpdb->usermeta,
            'class_roster' => $wpdb->postmeta,
        ];
        $engineFault = static fn(string $engine, string $tableKey): string => $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        try {
            foreach ($surfaces as $surface => $table) {
                $userId = $this->createDeletedAccountTombstone();
                add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
                $failedReads = 0;
                $queryFault = static function (string $query) use ($table, &$failedReads): string {
                    if (preg_match('/^\s*SELECT\b/i', $query) && strpos($query, $table) !== false) {
                        $failedReads++;
                        return 'SELECT id FROM ll_tools_missing_deleted_privacy_read';
                    }
                    return $query;
                };
                add_filter('query', $queryFault);
                $previous = $wpdb->suppress_errors(true);
                $started = time();
                try {
                    ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
                } finally {
                    remove_filter('query', $queryFault);
                    remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
                    $wpdb->suppress_errors($previous);
                }

                $this->assertGreaterThan(0, $failedReads, $surface . ' must be read independently of cached emptiness.');
                $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId), $surface . ' read failure must retain the deletion fence.');
                $blocked = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
                $this->assertSame('', (string) ($blocked['blocked_reason'] ?? ''), $surface . ' must not be mislabeled as a persistent storage-engine blocker.');
                $this->assertNotSame('', (string) ($blocked['last_error_code'] ?? ''), $surface . ' must retain its read-failure diagnosis.');
                $this->assertNotSame('ll_tools_privacy_transactional_engine_unavailable', (string) $blocked['last_error_code'], $surface . ' must report its read failure instead of an engine-only diagnosis.');
                $this->assertGreaterThanOrEqual($started + MINUTE_IN_SECONDS, (int) ($blocked['next_attempt_at'] ?? 0), $surface);
                $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS, (int) $blocked['next_attempt_at'], $surface . ' uses transient-error retry timing.');
            }
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }
    }

    public function test_late_writer_retains_deleted_account_fence_until_safe_transactional_retry(): void
    {
        global $wpdb;

        $userId = $this->createDeletedAccountTombstone();
        $denyLock = static fn(string $query): string => stripos($query, 'SELECT GET_LOCK(') !== false ? 'SELECT 0' : $query;
        add_filter('query', $denyLock);
        try {
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        } finally {
            remove_filter('query', $denyLock);
        }
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));

        // A previously admitted request commits after the first failed barrier.
        $this->assertSame(1, $wpdb->insert($wpdb->usermeta, [
            'user_id' => $userId,
            'meta_key' => LL_TOOLS_USER_GOALS_META,
            'meta_value' => maybe_serialize(['daily_minutes' => 45]),
        ]));
        $this->makeDeletedAccountRetryDue($userId);
        $engineFault = static fn(string $engine, string $tableKey): string => $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        try {
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertTrue(ll_tools_offline_app_user_data_write_is_fenced($userId));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, LL_TOOLS_USER_GOALS_META)));

        $this->makeDeletedAccountRetryDue($userId);
        ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, LL_TOOLS_USER_GOALS_META)));
    }

    public function test_worker_and_runtime_resumer_preserve_persisted_retry_deadline(): void
    {
        $userId = $this->createDeletedAccountTombstone();
        $row = ll_tools_privacy_deleted_user_lms_cleanup_row($userId);
        $payload = $row['value'];
        $deadline = time() + 2 * HOUR_IN_SECONDS;
        $payload['blocked_reason'] = 'll_tools_privacy_transactional_engine_unavailable';
        $payload['blocked_details'] = ['postmeta: MyISAM'];
        $payload['retry_count'] = 3;
        $payload['next_attempt_at'] = $deadline;
        update_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId), $payload, false);
        ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($userId);
        $before = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['raw'];
        $engineReads = 0;
        $watchEngine = static function (string $engine) use (&$engineReads): string {
            $engineReads++;
            return $engine;
        };
        add_filter('ll_tools_privacy_erasure_table_engine', $watchEngine);
        try {
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
            $this->assertSame(0, $engineReads, 'A stale early worker must not repeat storage work during backoff.');
            $this->assertSame($before, ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['raw']);
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            ll_tools_privacy_resume_deleted_user_lms_cleanup();
            $this->assertGreaterThanOrEqual($deadline, (int) wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            delete_transient(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT);
            ll_tools_privacy_maybe_resume_deleted_user_lms_cleanup();
            $this->assertGreaterThanOrEqual($deadline, (int) wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $watchEngine);
        }
        $this->assertSame($before, ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['raw']);
    }

    public function test_empty_completion_requires_the_owned_user_lock_and_a_fresh_deleted_account(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertWPError(ll_tools_privacy_deleted_user_local_data_is_empty_locked($userId, ''));
        $this->assertWPError(ll_tools_privacy_deleted_user_local_data_is_empty_locked($userId, 'unrelated-lock'));
        $lock = ll_tools_offline_app_acquire_user_session_lock($userId);
        $this->assertNotSame('', $lock);
        try {
            $result = ll_tools_privacy_deleted_user_local_data_is_empty_locked($userId, $lock);
            $this->assertTrue(is_wp_error($result) || $result === false, 'An existing single-site account is not a deleted-empty account.');
        } finally {
            ll_tools_offline_app_release_user_session_lock($lock);
        }
    }

    public function test_first_post_delete_boundary_clears_pre_delete_backoff_without_changing_queue_age(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($userId));
        $row = ll_tools_privacy_deleted_user_lms_cleanup_row($userId);
        $payload = $row['value'];
        $payload['blocked_reason'] = 'll_tools_privacy_transactional_engine_unavailable';
        $payload['blocked_details'] = ['postmeta: MyISAM'];
        $payload['retry_count'] = 4;
        $payload['next_attempt_at'] = time() + 6 * HOUR_IN_SECONDS;
        update_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId), $payload, false);
        ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($userId);

        $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId, true));
        $marked = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
        $this->assertSame($payload['queued_at'], $marked['queued_at']);
        $this->assertGreaterThan(0, (int) $marked['post_seen_at']);
        $this->assertLessThanOrEqual(time(), (int) ($marked['next_attempt_at'] ?? 0));
        $this->assertSame($payload['retry_count'], $marked['retry_count']);
        $this->assertSame($payload['blocked_reason'], $marked['blocked_reason']);
        $this->assertSame($payload['blocked_details'], $marked['blocked_details']);

        $marked['next_attempt_at'] = time() + HOUR_IN_SECONDS;
        update_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId), $marked, false);
        ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($userId);
        $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId, true));
        $this->assertSame($marked, ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'], 'A repeated post-delete event must preserve a new retry deadline.');
    }

    public function test_empty_verifier_reads_exact_class_roster_membership_after_cached_absence(): void
    {
        global $wpdb;

        $userId = $this->createDeletedAccountTombstone();
        $classId = (int) self::factory()->post->create([
            'post_type' => LL_TOOLS_TEACHER_CLASS_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Exact privacy roster',
        ]);
        $otherId = (int) ($userId . '1');
        $this->assertNotFalse(update_post_meta($classId, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, [$otherId]));
        $this->assertSame([$otherId], get_post_meta($classId, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, true));
        $lock = ll_tools_offline_app_acquire_user_session_lock($userId);
        $this->assertNotSame('', $lock);
        try {
            $this->assertTrue(ll_tools_privacy_deleted_user_local_data_is_empty_locked($userId, $lock), 'A longer roster ID must not match the deleted user.');

            // Bypass metadata invalidation to model another request committing
            // a previously admitted roster write while this cache stays stale.
            $this->assertSame(1, $wpdb->update($wpdb->postmeta, [
                'meta_value' => maybe_serialize([(string) $userId]),
            ], [
                'post_id' => $classId,
                'meta_key' => LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META,
            ], ['%s'], ['%d', '%s']));
            $this->assertSame([$otherId], get_post_meta($classId, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, true));
            $this->assertFalse(ll_tools_privacy_deleted_user_local_data_is_empty_locked($userId, $lock), 'Fresh storage must detect the exact string-valued roster ID.');
        } finally {
            ll_tools_offline_app_release_user_session_lock($lock);
            clean_post_cache($classId);
        }
    }

    public function test_manual_erasure_of_an_empty_existing_account_keeps_the_transactional_engine_guard(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $engineFault = static fn(string $engine, string $tableKey): string => $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        try {
            $result = ll_tools_privacy_delete_user_personal_data_verified($userId);
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }
        $this->assertWPError($result);
        $this->assertSame('ll_tools_privacy_transactional_engine_unavailable', $result->get_error_code());
        $this->assertInstanceOf(WP_User::class, get_userdata($userId));
        $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
    }

    public function test_retained_data_with_unreadable_engine_metadata_uses_transient_retry(): void
    {
        global $wpdb;

        $userId = $this->createDeletedAccountTombstone();
        $this->assertSame(1, $wpdb->insert($wpdb->usermeta, [
            'user_id' => $userId,
            'meta_key' => LL_TOOLS_USER_GOALS_META,
            'meta_value' => maybe_serialize(['daily_minutes' => 20]),
        ]));
        $failedReads = 0;
        $queryFault = static function (string $query) use (&$failedReads): string {
            if (preg_match('/^\s*SHOW TABLE STATUS\b/i', $query)) {
                $failedReads++;
                return 'SHOW TABLE STATUS FROM ll_tools_missing_privacy_database';
            }
            return $query;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $queryFault);
        $started = time();
        try {
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
        } finally {
            remove_filter('query', $queryFault);
            $wpdb->suppress_errors($previous);
        }

        $this->assertGreaterThan(0, $failedReads);
        $payload = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
        $this->assertSame('', (string) ($payload['blocked_reason'] ?? ''), 'Unknown engine metadata is not proof of a non-transactional engine.');
        $this->assertSame('ll_tools_privacy_transactional_engine_unavailable', (string) ($payload['last_error_code'] ?? ''));
        $this->assertGreaterThanOrEqual($started + MINUTE_IN_SECONDS, (int) $payload['next_attempt_at']);
        $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS, (int) $payload['next_attempt_at']);
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, LL_TOOLS_USER_GOALS_META)));
    }

    public function test_external_favorites_require_a_fresh_valid_single_replay_marker_before_empty_completion(): void
    {
        global $wpdb;

        $userId = $this->createDeletedAccountTombstone();
        $favoritesRaw = maybe_serialize(['external_items' => [401, 402], 'site_id' => get_current_blog_id()]);
        $this->assertSame(1, $wpdb->insert($wpdb->usermeta, [
            'user_id' => $userId,
            'meta_key' => 'simplefavorites',
            'meta_value' => $favoritesRaw,
        ]));
        $markerKey = LL_TOOLS_USER_LEGACY_FAVORITES_ERASURE_META;
        $engineFault = static fn(string $engine, string $tableKey): string => $tableKey === 'postmeta' ? 'MyISAM' : $engine;
        add_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10, 2);
        try {
            foreach ([[], ['0'], [maybe_serialize(['1'])], ['1', '1'], [str_repeat('1', 128)]] as $markerRows) {
                $this->assertNotFalse($wpdb->delete($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $markerKey], ['%d', '%s']));
                foreach ($markerRows as $raw) {
                    $this->assertSame(1, $wpdb->insert($wpdb->usermeta, [
                        'user_id' => $userId,
                        'meta_key' => $markerKey,
                        'meta_value' => $raw,
                    ]));
                }
                $this->makeDeletedAccountRetryDue($userId);
                ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
                $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId), 'Missing, invalid, non-scalar, duplicate, or oversized replay evidence must retain the fence.');
                $this->assertSame($favoritesRaw, $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'simplefavorites'", $userId)));
            }

            $this->assertNotFalse($wpdb->delete($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $markerKey], ['%d', '%s']));
            $this->assertSame(1, $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $markerKey, 'meta_value' => '1']));
            $this->makeDeletedAccountRetryDue($userId);
            $failedReads = 0;
            $markerReadFault = static function (string $query) use (&$failedReads): string {
                if (strpos($query, 'marker_raw') !== false) {
                    $failedReads++;
                    return 'SELECT marker_raw FROM ll_tools_missing_privacy_favorites_marker';
                }
                return $query;
            };
            add_filter('query', $markerReadFault);
            $previous = $wpdb->suppress_errors(true);
            $started = time();
            try {
                ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
            } finally {
                remove_filter('query', $markerReadFault);
                $wpdb->suppress_errors($previous);
            }
            $this->assertGreaterThan(0, $failedReads);
            $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $fault = ll_tools_privacy_deleted_user_lms_cleanup_row($userId)['value'];
            $this->assertSame('', (string) ($fault['blocked_reason'] ?? ''));
            $this->assertNotSame('', (string) ($fault['last_error_code'] ?? ''));
            $this->assertGreaterThanOrEqual($started + MINUTE_IN_SECONDS, (int) $fault['next_attempt_at']);
            $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS, (int) $fault['next_attempt_at']);

            $this->makeDeletedAccountRetryDue($userId);
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
            $this->assertSame($favoritesRaw, $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'simplefavorites'", $userId)));
            $this->assertSame('1', $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, $markerKey)));
        } finally {
            remove_filter('ll_tools_privacy_erasure_table_engine', $engineFault, 10);
        }
    }

    public function test_activation_reschedules_an_existing_pending_delivery(): void
    {
        global $wpdb;

        $table = ll_tools_grade_delivery_table_names()['deliveries'];
        $now = gmdate('Y-m-d H:i:s');
        $this->assertSame(1, $wpdb->insert($table, [
            'adapter' => 'activation_test',
            'destination_id' => 991001,
            'recipient_id' => 991002,
            'assignment_id' => 991003,
            'revision_id' => 991004,
            'learner_user_id' => 991005,
            'grade_revision' => 1,
            'score_given' => 4,
            'score_maximum' => 5,
            'points_given' => '8.0000',
            'points_maximum' => '10.0000',
            'dedupe_key' => hash('sha256', 'activation-pending-delivery'),
            'status' => 'pending',
            'available_at' => $now,
            'attempt_count' => 0,
            'lease_token' => '',
            'last_http_status' => 0,
            'last_error_code' => '',
            'last_diagnostic' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);

        $activationHook = 'activate_' . plugin_basename(LL_TOOLS_MAIN_FILE);
        $this->assertNotFalse(has_action($activationHook));
        ll_tools_resume_lms_background_work();

        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK));
        $wpdb->delete($table, ['dedupe_key' => hash('sha256', 'activation-pending-delivery')], ['%s']);
    }

    private function createDeletedAccountTombstone(int $userId = 0): int
    {
        global $wpdb;

        if ($userId === 0) {
            $userId = self::factory()->user->create(['role' => 'subscriber']);
            // Separate test-wrapper installs can reuse a core user ID while
            // LL custom tables survive. Prepare this fixture's empty surfaces
            // with normal verified erasure before installing any test fault.
            $this->assertIsArray(ll_tools_privacy_delete_user_personal_data_verified($userId));
        }
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($userId));
        // Model the completed core boundary without its synchronous LL worker,
        // so each fault is installed before the first post-delete attempt.
        $this->assertSame(1, $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']));
        $this->assertNotFalse($wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']));
        clean_user_cache($userId);
        $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId, true));
        $this->assertFalse(get_userdata($userId));
        return $userId;
    }

    private function makeDeletedAccountRetryDue(int $userId): void
    {
        $row = ll_tools_privacy_deleted_user_lms_cleanup_row($userId);
        $this->assertIsArray($row['value']);
        $payload = $row['value'];
        $payload['next_attempt_at'] = time() - 1;
        update_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId), $payload, false);
        ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($userId);
        wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
    }

    /** @return array{user_id:int,email:string,word_id:int,class_id:int,token:string,event_uuid:string} */
    private function createLocalPrivacyFixture(string $slug): array
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $email = 'privacy-' . sanitize_key($slug) . '-' . $suffix . '@example.test';
        $userId = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => $email,
        ]);
        $wordId = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Privacy progress word',
        ]);
        $eventUuid = 'privacy-' . sanitize_key($slug) . '-' . wp_generate_uuid4();
        $stats = ll_tools_process_progress_events_batch($userId, [[
            'event_uuid' => $eventUuid,
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'word_id' => $wordId,
        ]]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));
        $this->assertNotFalse(update_user_meta($userId, LL_TOOLS_USER_GOALS_META, ['daily_minutes' => 10]));
        $this->assertNotFalse(update_user_meta($userId, LL_TOOLS_USER_FAST_TRANSITIONS_META, 1));

        $classId = (int) self::factory()->post->create([
            'post_type' => LL_TOOLS_TEACHER_CLASS_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Privacy class',
        ]);
        $this->assertTrue(ll_tools_teacher_class_add_student($classId, $userId));

        $session = ll_tools_offline_app_create_session($userId, [
            'device_id' => 'privacy-device',
            'profile_id' => 'privacy-profile',
        ]);
        $token = (string) ($session['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertIsArray(ll_tools_offline_app_authenticate_token($token, false));

        return [
            'user_id' => $userId,
            'email' => $email,
            'word_id' => $wordId,
            'class_id' => $classId,
            'token' => $token,
            'event_uuid' => $eventUuid,
        ];
    }

    /** @param array{user_id:int,class_id:int,token:string,event_uuid:string} $fixture */
    private function assertLocalPrivacyFixturePresent(array $fixture): void
    {
        global $wpdb;

        $this->assertIsArray(ll_tools_offline_app_authenticate_token($fixture['token'], false));
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d',
            $fixture['user_id']
        )));
        $progressTables = ll_tools_user_progress_table_names();
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$progressTables['words']} WHERE user_id = %d",
            $fixture['user_id']
        )));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$progressTables['events']} WHERE event_uuid = %s",
            $fixture['event_uuid']
        )));
        $this->assertTrue(metadata_exists('user', $fixture['user_id'], LL_TOOLS_USER_GOALS_META));
        $this->assertTrue(metadata_exists('user', $fixture['user_id'], LL_TOOLS_USER_FAST_TRANSITIONS_META));
        $this->assertContains(
            $fixture['user_id'],
            ll_tools_teacher_class_get_student_ids($fixture['class_id'])
        );
        $this->assertContains(
            $fixture['class_id'],
            ll_tools_teacher_class_get_ids_for_student($fixture['user_id'])
        );
    }
}
