<?php
declare(strict_types=1);

final class LmsGradeDeliveryTest extends LL_Tools_TestCase
{
    private const ADAPTER = 'test_adapter';

    /** @var mixed */
    private $originalAdapters;

    /** @var array<int,array<string,mixed>> */
    private array $adapterResponses = [];

    /** @var array<int,array<string,mixed>> */
    private array $sentContexts = [];

    /** @var array<int,int> */
    private array $identityIdsByUser = [];

    protected function setUp(): void
    {
        parent::setUp();

        delete_transient(LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT);
        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_grade_delivery_schema());
        $this->clearFoundationRows();

        $this->originalAdapters = $GLOBALS['ll_tools_grade_delivery_adapters'] ?? null;
        $GLOBALS['ll_tools_grade_delivery_adapters'] = [];
        $registered = ll_tools_grade_delivery_register_adapter(self::ADAPTER, $this->adapterDefinition());
        $this->assertTrue($registered === true);
    }

    protected function tearDown(): void
    {
        remove_all_filters('ll_tools_grade_delivery_now');
        remove_all_filters('ll_tools_grade_delivery_erasure_batch_size');
        remove_all_filters('ll_tools_grade_delivery_schema_exists_after_install');
        remove_all_filters('ll_tools_schema_maintenance_upgrade_is_allowed');
        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
        wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['grade_delivery']);

        $this->clearFoundationRows();
        if ($this->originalAdapters === null) {
            unset($GLOBALS['ll_tools_grade_delivery_adapters']);
        } else {
            $GLOBALS['ll_tools_grade_delivery_adapters'] = $this->originalAdapters;
        }
        delete_transient(LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT);
        if (!ll_tools_lms_assignment_schema_is_available()) {
            ll_tools_install_lms_assignment_schema();
        }
        if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) {
            ll_tools_install_grade_delivery_schema();
        }

        parent::tearDown();
    }

    public function test_schema_is_versioned_innodb_indexed_and_public_stale_admission_only_schedules_repair(): void
    {
        global $wpdb;

        $this->assertTrue(ll_tools_grade_delivery_schema_ready(true));
        $this->assertTrue((bool) ll_tools_grade_delivery_runtime_schema_status()['ready']);
        $this->assertSame(
            LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION,
            (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION, '')
        );
        $this->assertSame(
            LL_TOOLS_GRADE_DELIVERY_SCHEMA_VERSION,
            (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION, '')
        );
        $this->assertSame(13, has_action('init', 'll_tools_maybe_upgrade_grade_delivery_schema'));

        $requiredIndexes = [
            'identities' => ['uniq_adapter_subject', 'uniq_adapter_learner', 'idx_learner'],
            'destinations' => ['uniq_destination', 'idx_assignment', 'idx_adapter_status'],
            'recipients' => ['uniq_destination_learner', 'uniq_destination_recipient', 'idx_identity', 'idx_learner'],
            'deliveries' => ['uniq_dedupe', 'idx_due', 'idx_lease', 'idx_recipient_revision', 'idx_grade', 'idx_learner'],
        ];
        foreach (ll_tools_grade_delivery_table_names() as $key => $table) {
            $status = $wpdb->get_row(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
                ARRAY_A
            );
            $this->assertIsArray($status, $key);
            $this->assertSame('INNODB', strtoupper((string) ($status['Engine'] ?? '')), $key);
            $names = array_values(array_unique(array_map(
                static fn(array $row): string => (string) ($row['Key_name'] ?? ''),
                (array) $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A)
            )));
            foreach ($requiredIndexes[$key] as $indexName) {
                $this->assertContains($indexName, $names, $key . ':' . $indexName);
            }
        }

        $installerCalls = 0;
        $denyUpgrade = static fn(): bool => false;
        $countInstaller = static function (bool $ready) use (&$installerCalls): bool {
            $installerCalls++;
            return $ready;
        };
        delete_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION);
        delete_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION);
        add_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
        add_filter('ll_tools_grade_delivery_schema_exists_after_install', $countInstaller);
        try {
            $this->assertFalse(ll_tools_maybe_upgrade_grade_delivery_schema());
            $this->assertSame(0, $installerCalls, 'A public stale marker must not invoke DDL.');
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['grade_delivery']));
            $this->assertTrue(ll_tools_grade_delivery_schema_ready(true));
        } finally {
            remove_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
            remove_filter('ll_tools_grade_delivery_schema_exists_after_install', $countInstaller);
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['grade_delivery']);
        }

        $forceFailure = static fn(): bool => false;
        add_filter('ll_tools_grade_delivery_schema_exists_after_install', $forceFailure);
        try {
            $this->assertFalse(ll_tools_install_grade_delivery_schema());
            $this->assertSame('', (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERSION_OPTION, ''));
            $this->assertSame('', (string) get_option(LL_TOOLS_GRADE_DELIVERY_VERIFIED_VERSION_OPTION, ''));
            $this->assertNotFalse(get_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT));
        } finally {
            remove_filter('ll_tools_grade_delivery_schema_exists_after_install', $forceFailure);
            delete_transient(LL_TOOLS_GRADE_DELIVERY_SCHEMA_RETRY_TRANSIENT);
        }

        $deliveryTable = ll_tools_grade_delivery_table_names()['deliveries'];
        $now = gmdate('Y-m-d H:i:s');
        $this->assertSame(1, $wpdb->insert($deliveryTable, [
            'adapter' => self::ADAPTER,
            'destination_id' => 81001,
            'recipient_id' => 81002,
            'assignment_id' => 81003,
            'revision_id' => 81004,
            'learner_user_id' => 81005,
            'grade_revision' => 1,
            'score_given' => 4,
            'score_maximum' => 5,
            'points_given' => '8.0000',
            'points_maximum' => '10.0000',
            'dedupe_key' => hash('sha256', 'repair-pending-delivery'),
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
        $this->assertTrue(ll_tools_maybe_upgrade_grade_delivery_schema());
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK));
        $wpdb->delete($deliveryTable, ['dedupe_key' => hash('sha256', 'repair-pending-delivery')], ['%s']);
    }

    public function test_next_due_query_failure_arms_a_bounded_worker_retry(): void
    {
        global $wpdb;

        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
        $table = ll_tools_grade_delivery_table_names()['deliveries'];
        $breakDueRead = static function (string $query) use ($table): string {
            if (str_contains($query, 'SELECT MIN(d.available_at)')) {
                return "SELECT missing_due_column FROM {$table} LIMIT 1";
            }
            return $query;
        };
        add_filter('query', $breakDueRead);
        try {
            $this->assertTrue(ll_tools_grade_delivery_schedule_next_due());
        } finally {
            remove_filter('query', $breakDueRead);
        }
        $scheduled = wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
        $this->assertNotFalse($scheduled);
        $this->assertGreaterThanOrEqual(time() + (4 * MINUTE_IN_SECONDS), (int) $scheduled);
    }

    public function test_runtime_resumer_recovers_a_failed_post_commit_cron_write(): void
    {
        $seed = $this->seedSelectedGrade();
        $this->mapSeed($seed, 'resume-failed-cron');
        $queued = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($queued);
        $this->assertSame(1, $queued['enqueued']);
        wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);

        $blockSchedule = static function ($pre, $event) {
            return is_object($event) && ($event->hook ?? '') === LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK
                ? false
                : $pre;
        };
        add_filter('pre_schedule_event', $blockSchedule, 10, 2);
        try {
            $this->assertFalse(ll_tools_grade_delivery_schedule_worker());
        } finally {
            remove_filter('pre_schedule_event', $blockSchedule, 10);
        }
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK));

        delete_transient(LL_TOOLS_GRADE_DELIVERY_RESUME_TRANSIENT);
        ll_tools_grade_delivery_maybe_resume_worker();
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK));
    }

    public function test_adapter_contract_and_mapping_writes_are_strict_bounded_and_tenant_isolated(): void
    {
        $invalidDefinition = $this->adapterDefinition();
        $invalidDefinition['label'] = 'not allowed';
        $this->assertWpErrorCode(
            'invalid_grade_delivery_adapter_contract',
            ll_tools_grade_delivery_register_adapter('invalid_adapter', $invalidDefinition)
        );

        for ($index = 1; $index < LL_TOOLS_GRADE_DELIVERY_MAX_ADAPTERS; $index++) {
            $this->assertTrue(
                ll_tools_grade_delivery_register_adapter('bounded_' . $index, $this->adapterDefinition()) === true
            );
        }
        $this->assertWpErrorCode(
            'grade_delivery_adapter_limit',
            ll_tools_grade_delivery_register_adapter('bounded_overflow', $this->adapterDefinition())
        );

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $otherLearnerId = self::factory()->user->create(['role' => 'subscriber']);
        $connectionA = $this->hashKey('connection-a');
        $connectionB = $this->hashKey('connection-b');
        $subject = $this->hashKey('same-subject');

        $identityA = ll_tools_grade_delivery_create_external_identity([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionA,
            'subject_key_hash' => $subject,
            'learner_user_id' => $learnerId,
        ]);
        $this->assertIsInt($identityA);
        $this->assertGreaterThan(0, $identityA);

        $identityOtherTenant = ll_tools_grade_delivery_create_external_identity([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionB,
            'subject_key_hash' => $subject,
            'learner_user_id' => $learnerId,
        ]);
        $this->assertIsInt($identityOtherTenant);
        $this->assertNotSame($identityA, $identityOtherTenant);

        $previousSuppress = $GLOBALS['wpdb']->suppress_errors(true);
        try {
            $conflict = ll_tools_grade_delivery_create_external_identity([
                'adapter' => self::ADAPTER,
                'connection_key_hash' => $connectionA,
                'subject_key_hash' => $subject,
                'learner_user_id' => $otherLearnerId,
            ]);
        } finally {
            $GLOBALS['wpdb']->suppress_errors($previousSuppress);
        }
        $this->assertWpErrorCode('external_identity_mapping_conflict', $conflict);

        $this->assertWpErrorCode(
            'invalid_external_identity_mapping',
            ll_tools_grade_delivery_create_external_identity([
                'adapter' => self::ADAPTER,
                'connection_key_hash' => $connectionA,
                'subject_key_hash' => $this->hashKey('extra-key-subject'),
                'learner_user_id' => $learnerId,
                'email' => 'must-not-be-accepted@example.com',
            ])
        );

        $seed = $this->seedSelectedGrade($learnerId);
        $destinationId = ll_tools_grade_delivery_create_destination([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionA,
            'destination_key_hash' => $this->hashKey('destination-a'),
            'assignment_id' => $seed['assignment_id'],
            'revision_id' => $seed['revision_id'],
        ]);
        $this->assertIsInt($destinationId);

        $this->assertWpErrorCode(
            'grade_recipient_scope_mismatch',
            ll_tools_grade_delivery_create_recipient([
                'destination_id' => $destinationId,
                'external_identity_id' => $identityOtherTenant,
                'learner_user_id' => $learnerId,
                'recipient_key_hash' => $this->hashKey('recipient-a'),
            ])
        );

        $recipientId = ll_tools_grade_delivery_create_recipient([
            'destination_id' => $destinationId,
            'external_identity_id' => $identityA,
            'learner_user_id' => $learnerId,
            'recipient_key_hash' => $this->hashKey('recipient-a'),
        ]);
        $this->assertIsInt($recipientId);
        $this->assertGreaterThan(0, $recipientId);
    }

    public function test_external_identity_writer_requires_the_verified_assignment_schema_marker(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $identityTable = ll_tools_grade_delivery_table_names()['identities'];
        $mapping = [
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $this->hashKey('identity-schema-gate-connection'),
            'subject_key_hash' => $this->hashKey('identity-schema-gate-subject'),
            'learner_user_id' => $learnerId,
        ];

        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);
        try {
            $this->assertFalse(ll_tools_lms_assignment_schema_is_available());
            $result = ll_tools_grade_delivery_create_external_identity($mapping);
        } finally {
            update_option(
                LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION,
                LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION,
                false
            );
        }

        $this->assertWpErrorCode('lms_assignment_schema_unavailable', $result);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$identityTable} WHERE learner_user_id = %d",
            $learnerId
        )));
    }

    public function test_recipient_writer_requires_the_verified_assignment_schema_marker(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $seed = $this->seedSelectedGrade($learnerId);
        $connectionHash = $this->hashKey('recipient-schema-gate-connection');
        $identityId = ll_tools_grade_delivery_create_external_identity([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'subject_key_hash' => $this->hashKey('recipient-schema-gate-subject'),
            'learner_user_id' => $learnerId,
        ]);
        $this->assertIsInt($identityId);
        $destinationId = ll_tools_grade_delivery_create_destination([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'destination_key_hash' => $this->hashKey('recipient-schema-gate-destination'),
            'assignment_id' => $seed['assignment_id'],
            'revision_id' => $seed['revision_id'],
        ]);
        $this->assertIsInt($destinationId);
        $recipientTable = ll_tools_grade_delivery_table_names()['recipients'];

        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);
        try {
            $this->assertFalse(ll_tools_lms_assignment_schema_is_available());
            $result = ll_tools_grade_delivery_create_recipient([
                'destination_id' => $destinationId,
                'external_identity_id' => $identityId,
                'learner_user_id' => $learnerId,
                'recipient_key_hash' => $this->hashKey('recipient-schema-gate-recipient'),
            ]);
        } finally {
            update_option(
                LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION,
                LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION,
                false
            );
        }

        $this->assertWpErrorCode('lms_assignment_schema_unavailable', $result);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$recipientTable} WHERE destination_id = %d AND learner_user_id = %d",
            $destinationId,
            $learnerId
        )));
    }

    public function test_external_identity_write_rechecks_a_deletion_tombstone_after_the_learner_lock(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $mapping = [
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $this->hashKey('identity-fence-connection'),
            'subject_key_hash' => $this->hashKey('identity-fence-subject'),
            'learner_user_id' => $learnerId,
        ];

        try {
            $result = $this->runWithDeletionTombstoneAtLearnerLock(
                $learnerId,
                static fn() => ll_tools_grade_delivery_create_external_identity($mapping)
            );
            $this->assertWpErrorCode('invalid_external_identity_mapping', $result);
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_grade_delivery_table_names()['identities'] . ' WHERE learner_user_id = %d',
                $learnerId
            )));
        } finally {
            $this->assertTrue(ll_tools_privacy_dequeue_deleted_user_lms_cleanup($learnerId));
        }
    }

    public function test_recipient_write_rechecks_a_deletion_tombstone_after_the_learner_lock(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $seed = $this->seedSelectedGrade($learnerId);
        $connectionHash = $this->hashKey('recipient-fence-connection');
        $identityId = ll_tools_grade_delivery_create_external_identity([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'subject_key_hash' => $this->hashKey('recipient-fence-subject'),
            'learner_user_id' => $learnerId,
        ]);
        $this->assertIsInt($identityId);
        $destinationId = ll_tools_grade_delivery_create_destination([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'destination_key_hash' => $this->hashKey('recipient-fence-destination'),
            'assignment_id' => $seed['assignment_id'],
            'revision_id' => $seed['revision_id'],
        ]);
        $this->assertIsInt($destinationId);
        $mapping = [
            'destination_id' => $destinationId,
            'external_identity_id' => $identityId,
            'learner_user_id' => $learnerId,
            'recipient_key_hash' => $this->hashKey('recipient-fence-recipient'),
        ];

        try {
            $result = $this->runWithDeletionTombstoneAtLearnerLock(
                $learnerId,
                static fn() => ll_tools_grade_delivery_create_recipient($mapping)
            );
            $this->assertWpErrorCode('invalid_grade_recipient_mapping', $result);
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_grade_delivery_table_names()['recipients'] . ' WHERE learner_user_id = %d',
                $learnerId
            )));
        } finally {
            $this->assertTrue(ll_tools_privacy_dequeue_deleted_user_lms_cleanup($learnerId));
        }
    }

    public function test_external_identity_write_failure_rolls_back_its_savepoint_without_owning_the_outer_transaction(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $mapping = [
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $this->hashKey('identity-rollback-connection'),
            'subject_key_hash' => $this->hashKey('identity-rollback-subject'),
            'learner_user_id' => $learnerId,
        ];
        $identityTable = ll_tools_grade_delivery_table_names()['identities'];
        $markerName = 'll_tools_grade_identity_outer_' . strtolower(wp_generate_password(12, false, false));
        $outer = ll_tools_lms_assignment_begin_transaction();
        $this->assertIsArray($outer);
        $outerOpen = true;

        try {
            $this->assertSame(1, $wpdb->insert($wpdb->options, [
                'option_name' => $markerName,
                'option_value' => 'outer-owned',
                'autoload' => 'no',
            ], ['%s', '%s', '%s']));
            $breakIdentityInsert = static function (string $query) use ($identityTable): string {
                return stripos($query, "INSERT INTO `{$identityTable}`") !== false
                    || stripos($query, "INSERT INTO {$identityTable}") !== false
                    ? 'INSERT INTO ll_tools_missing_identity_mapping_table (broken) VALUES (1)'
                    : $query;
            };
            add_filter('query', $breakIdentityInsert);
            $previousSuppress = $wpdb->suppress_errors(true);
            try {
                $failed = ll_tools_grade_delivery_create_external_identity($mapping);
            } finally {
                $wpdb->suppress_errors($previousSuppress);
                remove_filter('query', $breakIdentityInsert);
            }

            $this->assertWpErrorCode('external_identity_mapping_write_failed', $failed);
            $this->assertSame('outer-owned', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $markerName
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$identityTable} WHERE learner_user_id = %d",
                $learnerId
            )));

            $succeeded = ll_tools_grade_delivery_create_external_identity($mapping);
            $this->assertIsInt($succeeded);
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$identityTable} WHERE learner_user_id = %d",
                $learnerId
            )));

            ll_tools_lms_assignment_rollback_transaction($outer);
            $outerOpen = false;
        } finally {
            if ($outerOpen) {
                ll_tools_lms_assignment_rollback_transaction($outer);
            }
        }

        $this->assertNull($wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $markerName
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$identityTable} WHERE learner_user_id = %d",
            $learnerId
        )));
    }

    public function test_recipient_write_failure_rolls_back_its_savepoint_without_owning_the_outer_transaction(): void
    {
        global $wpdb;

        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $seed = $this->seedSelectedGrade($learnerId);
        $connectionHash = $this->hashKey('recipient-rollback-connection');
        $identityId = ll_tools_grade_delivery_create_external_identity([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'subject_key_hash' => $this->hashKey('recipient-rollback-subject'),
            'learner_user_id' => $learnerId,
        ]);
        $this->assertIsInt($identityId);
        $destinationId = ll_tools_grade_delivery_create_destination([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'destination_key_hash' => $this->hashKey('recipient-rollback-destination'),
            'assignment_id' => $seed['assignment_id'],
            'revision_id' => $seed['revision_id'],
        ]);
        $this->assertIsInt($destinationId);
        $mapping = [
            'destination_id' => $destinationId,
            'external_identity_id' => $identityId,
            'learner_user_id' => $learnerId,
            'recipient_key_hash' => $this->hashKey('recipient-rollback-recipient'),
        ];
        $recipientTable = ll_tools_grade_delivery_table_names()['recipients'];
        $markerName = 'll_tools_grade_recipient_outer_' . strtolower(wp_generate_password(12, false, false));
        $outer = ll_tools_lms_assignment_begin_transaction();
        $this->assertIsArray($outer);
        $outerOpen = true;

        try {
            $this->assertSame(1, $wpdb->insert($wpdb->options, [
                'option_name' => $markerName,
                'option_value' => 'outer-owned',
                'autoload' => 'no',
            ], ['%s', '%s', '%s']));
            $breakRecipientInsert = static function (string $query) use ($recipientTable): string {
                return stripos($query, "INSERT INTO `{$recipientTable}`") !== false
                    || stripos($query, "INSERT INTO {$recipientTable}") !== false
                    ? 'INSERT INTO ll_tools_missing_recipient_mapping_table (broken) VALUES (1)'
                    : $query;
            };
            add_filter('query', $breakRecipientInsert);
            $previousSuppress = $wpdb->suppress_errors(true);
            try {
                $failed = ll_tools_grade_delivery_create_recipient($mapping);
            } finally {
                $wpdb->suppress_errors($previousSuppress);
                remove_filter('query', $breakRecipientInsert);
            }

            $this->assertWpErrorCode('grade_recipient_mapping_write_failed', $failed);
            $this->assertSame('outer-owned', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $markerName
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$recipientTable} WHERE learner_user_id = %d",
                $learnerId
            )));

            $succeeded = ll_tools_grade_delivery_create_recipient($mapping);
            $this->assertIsInt($succeeded);
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$recipientTable} WHERE learner_user_id = %d",
                $learnerId
            )));

            ll_tools_lms_assignment_rollback_transaction($outer);
            $outerOpen = false;
        } finally {
            if ($outerOpen) {
                ll_tools_lms_assignment_rollback_transaction($outer);
            }
        }

        $this->assertNull($wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $markerName
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$recipientTable} WHERE learner_user_id = %d",
            $learnerId
        )));
    }

    public function test_enqueue_uses_current_selected_grade_dedupes_and_immediately_supersedes_older_revision(): void
    {
        global $wpdb;

        $seed = $this->seedSelectedGrade();
        $noMapping = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($noMapping);
        $this->assertSame(0, $noMapping['enqueued']);

        $mapping = $this->mapSeed($seed, 'dedupe');
        $first = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($first);
        $this->assertSame(1, $first['enqueued']);
        $this->assertSame(0, $first['duplicates']);

        $duplicate = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($duplicate);
        $this->assertSame(0, $duplicate['enqueued']);
        $this->assertSame(1, $duplicate['duplicates']);

        $deliveriesTable = ll_tools_grade_delivery_table_names()['deliveries'];
        $rows = $wpdb->get_results("SELECT * FROM {$deliveriesTable} ORDER BY id ASC", ARRAY_A);
        $this->assertCount(1, $rows);
        $expectedDedupe = hash('sha256', implode('|', [
            'll-tools-grade-delivery-v1',
            self::ADAPTER,
            (string) $mapping['destination_id'],
            '1',
            (string) $seed['user_id'],
        ]));
        $this->assertSame($expectedDedupe, (string) $rows[0]['dedupe_key']);
        $this->assertSame('8.0000', (string) $rows[0]['points_given']);
        $this->assertSame('10.0000', (string) $rows[0]['points_maximum']);

        $this->updateSelectedGrade($seed, 2, 9, 10, '9.0000', '10.0000');
        $corrected = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            2
        );
        $this->assertIsArray($corrected);
        $this->assertSame(1, $corrected['enqueued']);
        $this->assertSame(1, $corrected['superseded']);

        $rows = $wpdb->get_results("SELECT status, grade_revision FROM {$deliveriesTable} ORDER BY id ASC", ARRAY_A);
        $this->assertSame([
            ['status' => 'superseded', 'grade_revision' => '1'],
            ['status' => 'pending', 'grade_revision' => '2'],
        ], $rows);

        $this->updateSelectedGrade($seed, 1, 8, 10, '8.0000', '10.0000');
        $this->assertWpErrorCode(
            'grade_delivery_revision_regression',
            ll_tools_grade_delivery_enqueue_current_grade(
                $seed['assignment_id'],
                $seed['revision_id'],
                $seed['user_id'],
                1
            )
        );
    }

    public function test_claim_leases_require_exact_owner_and_allow_expired_takeover(): void
    {
        global $wpdb;

        $seed = $this->seedSelectedGrade();
        $this->mapSeed($seed, 'lease');
        $queued = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($queued);
        $deliveryId = (int) $queued['delivery_ids'][0];

        $tokenA = $this->hashKey('lease-owner-a');
        $tokenB = $this->hashKey('lease-owner-b');
        $tokenC = $this->hashKey('lease-owner-c');
        $claimA = ll_tools_grade_delivery_claim_next($tokenA, 60);
        $this->assertIsArray($claimA);
        $this->assertSame($deliveryId, (int) $claimA['id']);
        $this->assertSame(1, (int) $claimA['attempt_count']);
        $this->assertNull(ll_tools_grade_delivery_claim_next($tokenB, 60));
        $this->assertFalse(ll_tools_grade_delivery_release_lease($deliveryId, $tokenB));
        $this->assertTrue(ll_tools_grade_delivery_release_lease($deliveryId, $tokenA));

        $claimB = ll_tools_grade_delivery_claim_next($tokenB, 60);
        $this->assertIsArray($claimB);
        $this->assertSame(2, (int) $claimB['attempt_count']);

        $deliveriesTable = ll_tools_grade_delivery_table_names()['deliveries'];
        $this->assertSame(1, $wpdb->update(
            $deliveriesTable,
            ['lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 10)],
            ['id' => $deliveryId, 'lease_token' => $tokenB],
            ['%s'],
            ['%d', '%s']
        ));
        $claimC = ll_tools_grade_delivery_claim_next($tokenC, 60);
        $this->assertIsArray($claimC);
        $this->assertSame($deliveryId, (int) $claimC['id']);
        $this->assertSame(3, (int) $claimC['attempt_count']);
        $this->assertSame($tokenC, (string) $claimC['lease_token']);

        $success = ll_tools_grade_delivery_classify_adapter_response(['type' => 'http', 'http_status' => 204]);
        $this->assertWpErrorCode(
            'grade_delivery_lease_lost',
            ll_tools_grade_delivery_apply_claim_result($claimB, $tokenB, $success)
        );
        $this->assertSame('succeeded', ll_tools_grade_delivery_apply_claim_result($claimC, $tokenC, $success));
        $stored = $wpdb->get_row($wpdb->prepare(
            "SELECT status, lease_token, attempt_count FROM {$deliveriesTable} WHERE id = %d",
            $deliveryId
        ), ARRAY_A);
        $this->assertSame('succeeded', (string) $stored['status']);
        $this->assertSame('', (string) $stored['lease_token']);
        $this->assertSame(3, (int) $stored['attempt_count']);
    }

    public function test_worker_supersedes_stale_grade_before_any_adapter_send(): void
    {
        global $wpdb;

        $seed = $this->seedSelectedGrade();
        $this->mapSeed($seed, 'stale');
        $queued = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($queued);
        $this->updateSelectedGrade($seed, 2, 9, 10, '9.0000', '10.0000');

        $stats = ll_tools_grade_delivery_run_worker(1);
        $this->assertSame(1, $stats['claimed']);
        $this->assertSame(1, $stats['superseded']);
        $this->assertSame(0, $stats['succeeded']);
        $this->assertSame([], $this->sentContexts);
        $status = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . ll_tools_grade_delivery_table_names()['deliveries'] . ' WHERE id = %d',
            (int) $queued['delivery_ids'][0]
        ));
        $this->assertSame('superseded', $status);
    }

    public function test_worker_classifies_success_retry_and_permanent_failure_and_redacts_diagnostics(): void
    {
        global $wpdb;

        $this->adapterResponses = [
            [
                'type' => 'http',
                'http_status' => 429,
                'error_code' => 'rate_limited',
                'retry_after' => '600',
                'diagnostic' => 'Authorization: Bearer secret-token refresh_token=refresh-secret learner@example.com https://lms.example/scores?access_token=abc',
            ],
            [
                'type' => 'http',
                'http_status' => 400,
                'error_code' => 'invalid_mapping',
                'diagnostic' => 'The destination mapping was rejected.',
            ],
            ['type' => 'http', 'http_status' => 204],
        ];

        for ($index = 1; $index <= 3; $index++) {
            $seed = $this->seedSelectedGrade();
            $this->mapSeed($seed, 'classification-' . $index);
            $queued = ll_tools_grade_delivery_enqueue_current_grade(
                $seed['assignment_id'],
                $seed['revision_id'],
                $seed['user_id'],
                1
            );
            $this->assertIsArray($queued);
            $this->assertSame(1, $queued['enqueued']);
        }

        $stats = ll_tools_grade_delivery_run_worker(20);
        $this->assertSame(3, $stats['claimed']);
        $this->assertSame(1, $stats['retried']);
        $this->assertSame(1, $stats['permanent_failed']);
        $this->assertSame(1, $stats['succeeded']);
        $this->assertCount(3, $this->sentContexts);

        $rows = $wpdb->get_results(
            'SELECT status, last_http_status, last_error_code, last_diagnostic, delivered_at FROM '
            . ll_tools_grade_delivery_table_names()['deliveries'] . ' ORDER BY id ASC',
            ARRAY_A
        );
        $this->assertSame(['retry', 'permanent_failed', 'succeeded'], array_column($rows, 'status'));
        $this->assertSame(429, (int) $rows[0]['last_http_status']);
        $this->assertSame('rate_limited', (string) $rows[0]['last_error_code']);
        $this->assertStringContainsString('[redacted', (string) $rows[0]['last_diagnostic']);
        $this->assertStringNotContainsString('secret-token', (string) $rows[0]['last_diagnostic']);
        $this->assertStringNotContainsString('refresh-secret', (string) $rows[0]['last_diagnostic']);
        $this->assertStringNotContainsString('learner@example.com', (string) $rows[0]['last_diagnostic']);
        $this->assertStringNotContainsString('https://', (string) $rows[0]['last_diagnostic']);
        $this->assertSame(400, (int) $rows[1]['last_http_status']);
        $this->assertSame('invalid_mapping', (string) $rows[1]['last_error_code']);
        $this->assertSame('', (string) $rows[2]['last_error_code']);
        $this->assertNotEmpty($rows[2]['delivered_at']);

        $invalid = ll_tools_grade_delivery_classify_adapter_response([
            'type' => 'http',
            'http_status' => 200,
            'body' => '{"access_token":"must-never-be-accepted"}',
        ]);
        $this->assertSame('permanent', $invalid['outcome']);
        $this->assertSame('invalid_adapter_response', $invalid['error_code']);
        $this->assertArrayNotHasKey('body', $invalid);
        $this->assertLessThanOrEqual(
            ll_tools_grade_delivery_retry_cap_seconds(),
            ll_tools_grade_delivery_parse_retry_after('9999999999')
        );
        foreach ($this->sentContexts as $context) {
            $this->assertArrayNotHasKey('learner_user_id', $context);
            $this->assertArrayNotHasKey('lease_token', $context);
            $this->assertArrayNotHasKey('connection_key_hash', $context);
            $this->assertArrayNotHasKey('subject_key_hash', $context);
        }
    }

    public function test_neutral_export_is_bounded_and_erasure_never_calls_an_adapter(): void
    {
        global $wpdb;

        $seed = $this->seedSelectedGrade();
        $mapping = $this->mapSeed($seed, 'privacy');
        $queued = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($queued);

        $export = ll_tools_grade_delivery_export_user_data($seed['user_id'], 0, 1);
        $this->assertIsArray($export);
        $this->assertCount(1, $export['items']);
        $this->assertTrue($export['done']);
        $this->assertSame(1, $export['mapping_counts']['external_identities']);
        $this->assertSame(1, $export['mapping_counts']['grade_recipients']);
        $pageExport = ll_tools_grade_delivery_export_user_data_page($seed['user_id'], 1, 1);
        $this->assertIsArray($pageExport);
        $this->assertSame(1, $pageExport['page']);
        $this->assertSame($export['items'], $pageExport['items']);
        $serialized = wp_json_encode($export);
        $this->assertIsString($serialized);
        foreach (['dedupe_key', 'lease_token', 'last_diagnostic', 'connection_key_hash', 'subject_key_hash', 'recipient_key_hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        add_filter('ll_tools_grade_delivery_erasure_batch_size', static fn(): int => 1);
        $done = false;
        for ($attempt = 0; $attempt < 5 && !$done; $attempt++) {
            $erased = ll_tools_grade_delivery_erase_user_data($seed['user_id']);
            $this->assertIsArray($erased);
            $done = (bool) $erased['done'];
        }
        $this->assertTrue($done);
        $this->assertSame([], $this->sentContexts, 'Local erasure must not invoke external deletion or grade delivery.');

        $tables = ll_tools_grade_delivery_table_names();
        foreach (['deliveries', 'recipients', 'identities'] as $key) {
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables[$key]} WHERE learner_user_id = %d",
                $seed['user_id']
            )), $key);
        }
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['destinations']} WHERE id = %d",
            $mapping['destination_id']
        )), 'Erasure removes learner data, not the instructor-owned destination.');
    }

    public function test_erasure_defers_a_live_claim_and_preserves_its_audit_and_mappings(): void
    {
        global $wpdb;

        $seed = $this->seedSelectedGrade();
        $mapping = $this->mapSeed($seed, 'privacy-live-lease');
        $queued = ll_tools_grade_delivery_enqueue_current_grade(
            $seed['assignment_id'],
            $seed['revision_id'],
            $seed['user_id'],
            1
        );
        $this->assertIsArray($queued);
        $leaseToken = hash('sha256', 'privacy-live-lease-owner');
        $claimed = ll_tools_grade_delivery_claim_next($leaseToken, 120);
        $this->assertIsArray($claimed);

        $erased = ll_tools_grade_delivery_erase_user_data($seed['user_id']);
        $this->assertWpErrorCode('grade_delivery_erasure_in_progress', $erased);
        $tables = ll_tools_grade_delivery_table_names();
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['deliveries']} WHERE id = %d AND status = 'processing'",
            (int) $claimed['id']
        )));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['recipients']} WHERE id = %d",
            $mapping['recipient_id']
        )));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['identities']} WHERE id = %d",
            $mapping['identity_id']
        )));
        $this->assertTrue(ll_tools_grade_delivery_release_lease((int) $claimed['id'], $leaseToken));
    }

    /** @return array<string,callable> */
    private function adapterDefinition(): array
    {
        return [
            'validate_identity' => static fn(array $mapping): bool => true,
            'validate_destination' => static fn(array $mapping): bool => true,
            'validate_recipient' => static fn(array $mapping): bool => true,
            'send' => function (array $context): array {
                $this->sentContexts[] = $context;
                if ($this->adapterResponses !== []) {
                    return array_shift($this->adapterResponses);
                }
                return ['type' => 'http', 'http_status' => 204];
            },
        ];
    }

    /** @return array{assignment_id:int,revision_id:int,user_id:int} */
    private function seedSelectedGrade(
        int $userId = 0,
        int $gradeRevision = 1,
        int $scoreGiven = 8,
        int $scoreMaximum = 10,
        string $pointsGiven = '8.0000',
        string $pointsMaximum = '10.0000'
    ): array {
        global $wpdb;

        $userId = $userId > 0 ? $userId : self::factory()->user->create(['role' => 'subscriber']);
        $tables = ll_tools_lms_assignment_table_names();
        $now = gmdate('Y-m-d H:i:s');
        $this->assertNotFalse($wpdb->insert($tables['assignments'], [
            'assignment_uuid' => wp_generate_uuid4(),
            'class_id' => 1,
            'wordset_id' => 1,
            'created_by_user_id' => 1,
            'title' => 'Grade Delivery ' . wp_generate_password(6, false),
            'status' => 'published',
            'current_revision_id' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $assignmentId = (int) $wpdb->insert_id;
        $manifestJson = '{"schema":1,"kind":"closed_response","items":[]}';
        $this->assertNotFalse($wpdb->insert($tables['revisions'], [
            'assignment_id' => $assignmentId,
            'revision_number' => 1,
            'manifest_schema' => 1,
            'manifest_json' => $manifestJson,
            'manifest_hash' => hash('sha256', $manifestJson),
            'question_count' => $scoreMaximum,
            'points_maximum' => $pointsMaximum,
            'attempt_limit' => 1,
            'grade_policy' => 'latest',
            'created_by_user_id' => 1,
            'created_at' => $now,
            'published_at' => $now,
        ]));
        $revisionId = (int) $wpdb->insert_id;
        $this->assertSame(1, $wpdb->update(
            $tables['assignments'],
            ['current_revision_id' => $revisionId],
            ['id' => $assignmentId],
            ['%d'],
            ['%d']
        ));
        $this->assertNotFalse($wpdb->insert($tables['grades'], [
            'assignment_id' => $assignmentId,
            'revision_id' => $revisionId,
            'user_id' => $userId,
            'selected_attempt_id' => 1,
            'grade_revision' => $gradeRevision,
            'score_given' => $scoreGiven,
            'score_maximum' => $scoreMaximum,
            'points_given' => $pointsGiven,
            'points_maximum' => $pointsMaximum,
            'grade_policy' => 'latest',
            'updated_at' => $now,
        ]));

        return [
            'assignment_id' => $assignmentId,
            'revision_id' => $revisionId,
            'user_id' => $userId,
        ];
    }

    private function updateSelectedGrade(
        array $seed,
        int $gradeRevision,
        int $scoreGiven,
        int $scoreMaximum,
        string $pointsGiven,
        string $pointsMaximum
    ): void {
        global $wpdb;

        $this->assertSame(1, $wpdb->update(
            ll_tools_lms_assignment_table_names()['grades'],
            [
                'grade_revision' => $gradeRevision,
                'score_given' => $scoreGiven,
                'score_maximum' => $scoreMaximum,
                'points_given' => $pointsGiven,
                'points_maximum' => $pointsMaximum,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'assignment_id' => $seed['assignment_id'],
                'revision_id' => $seed['revision_id'],
                'user_id' => $seed['user_id'],
            ]
        ));
    }

    /** @return array{identity_id:int,destination_id:int,recipient_id:int} */
    private function mapSeed(array $seed, string $suffix): array
    {
        $userId = (int) $seed['user_id'];
        $connectionHash = $this->hashKey('connection-main');
        if (!isset($this->identityIdsByUser[$userId])) {
            $identityId = ll_tools_grade_delivery_create_external_identity([
                'adapter' => self::ADAPTER,
                'connection_key_hash' => $connectionHash,
                'subject_key_hash' => $this->hashKey('subject-' . $userId),
                'learner_user_id' => $userId,
            ]);
            $this->assertIsInt($identityId);
            $this->identityIdsByUser[$userId] = $identityId;
        }
        $destinationId = ll_tools_grade_delivery_create_destination([
            'adapter' => self::ADAPTER,
            'connection_key_hash' => $connectionHash,
            'destination_key_hash' => $this->hashKey('destination-' . $suffix),
            'assignment_id' => (int) $seed['assignment_id'],
            'revision_id' => (int) $seed['revision_id'],
        ]);
        $this->assertIsInt($destinationId);
        $recipientId = ll_tools_grade_delivery_create_recipient([
            'destination_id' => $destinationId,
            'external_identity_id' => $this->identityIdsByUser[$userId],
            'learner_user_id' => $userId,
            'recipient_key_hash' => $this->hashKey('recipient-' . $suffix),
        ]);
        $this->assertIsInt($recipientId);

        return [
            'identity_id' => $this->identityIdsByUser[$userId],
            'destination_id' => $destinationId,
            'recipient_id' => $recipientId,
        ];
    }

    /** @return mixed */
    private function runWithDeletionTombstoneAtLearnerLock(int $userId, callable $operation)
    {
        global $wpdb;

        $this->assertTrue(ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId));
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($userId));
        $tombstoneName = ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId);
        $learnerLockObserved = false;
        $lockedFenceReadObserved = false;
        $hideStalePreflight = static function (string $query) use (
            &$learnerLockObserved,
            &$lockedFenceReadObserved,
            $wpdb,
            $userId,
            $tombstoneName
        ): string {
            if (stripos($query, "SELECT ID FROM {$wpdb->users} WHERE ID = {$userId} FOR UPDATE") !== false) {
                $learnerLockObserved = true;
                return $query;
            }
            if (
                stripos($query, 'SELECT option_name, option_value') !== false
                && stripos($query, 'FOR UPDATE') !== false
                && stripos($query, $tombstoneName) !== false
            ) {
                $lockedFenceReadObserved = $learnerLockObserved;
                return $query;
            }
            if (
                stripos($query, "SELECT option_value FROM {$wpdb->options}") !== false
                && stripos($query, $tombstoneName) !== false
                && stripos($query, 'FOR UPDATE') === false
            ) {
                // Model a pre-admission snapshot that predates deletion. The
                // real tombstone remains in storage for the locked current
                // read; no re-entrant or second-connection write is needed.
                return 'SELECT NULL AS option_value';
            }
            return $query;
        };

        add_filter('query', $hideStalePreflight);
        try {
            $result = $operation();
        } finally {
            remove_filter('query', $hideStalePreflight);
        }

        $this->assertTrue($learnerLockObserved, 'The mapping writer must lock the learner row before its final write path.');
        $this->assertTrue($lockedFenceReadObserved, 'The real tombstone must be read with FOR UPDATE after the learner lock.');
        $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
        return $result;
    }

    private function hashKey(string $value): string
    {
        return hash('sha256', 'lms-grade-delivery-test|' . $value);
    }

    /** @param mixed $value */
    private function assertWpErrorCode(string $expected, $value): void
    {
        $this->assertInstanceOf(WP_Error::class, $value);
        $this->assertSame($expected, $value->get_error_code());
    }

    private function clearFoundationRows(): void
    {
        global $wpdb;

        if (function_exists('ll_tools_grade_delivery_table_names')) {
            $tables = ll_tools_grade_delivery_table_names();
            foreach (['deliveries', 'recipients', 'identities', 'destinations'] as $key) {
                $wpdb->query("DELETE FROM {$tables[$key]}");
            }
        }
        if (function_exists('ll_tools_lms_assignment_table_names')) {
            $tables = ll_tools_lms_assignment_table_names();
            foreach (['answers', 'attempts', 'grades', 'revisions', 'assignments'] as $key) {
                $wpdb->query("DELETE FROM {$tables[$key]}");
            }
        }
        $this->identityIdsByUser = [];
        $this->adapterResponses = [];
        $this->sentContexts = [];
    }
}
