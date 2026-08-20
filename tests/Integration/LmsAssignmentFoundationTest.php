<?php
declare(strict_types=1);

final class LmsAssignmentFoundationTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        if (function_exists('ll_tools_install_grade_delivery_schema')) {
            $this->assertTrue(ll_tools_install_grade_delivery_schema());
        }

        global $wpdb;
        $tables = ll_tools_lms_assignment_table_names();
        foreach (['answers', 'grades', 'attempts', 'revisions', 'assignments'] as $key) {
            $this->assertNotFalse($wpdb->query("DELETE FROM {$tables[$key]}"));
        }

        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();
    }

    public function test_schema_is_fail_closed_versioned_innodb_and_schedules_stale_public_repair(): void
    {
        global $wpdb;

        $this->assertSame('1.0.0', LL_TOOLS_LMS_ASSIGNMENT_SCHEMA_VERSION);
        $this->assertSame(12, has_action('init', 'll_tools_maybe_upgrade_lms_assignment_schema'));
        $this->assertTrue(ll_tools_lms_assignment_schema_is_available());
        $this->assertTrue(ll_tools_lms_assignment_schema_is_ready());
        $contract = ll_tools_lms_assignment_schema_contract();
        $this->assertSame(['assignments', 'revisions', 'attempts', 'answers', 'grades'], array_keys($contract));
        $this->assertSame(
            ['assignment_id', 'revision_id', 'user_id'],
            $contract['grades']['indexes']['PRIMARY']['columns']
        );
        $this->assertSame(
            ['attempt_id', 'answer_uuid'],
            $contract['answers']['indexes']['uniq_attempt_answer_uuid']['columns']
        );
        foreach (ll_tools_lms_assignment_table_names() as $table) {
            $status = $wpdb->get_row(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
                ARRAY_A
            );
            $this->assertIsArray($status);
            $this->assertSame('innodb', strtolower((string) $status['Engine']));
        }
        $users_status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->users)),
            ARRAY_A
        );
        $this->assertIsArray($users_status);
        $this->assertSame('innodb', strtolower((string) $users_status['Engine']));

        delete_option(LL_TOOLS_LMS_ASSIGNMENT_VERIFIED_VERSION_OPTION);
        $this->assertFalse(ll_tools_lms_assignment_schema_is_available());
        $blocked = ll_tools_lms_assignment_start_attempt(999999, 999999);
        $this->assertWPError($blocked);
        $this->assertSame('lms_assignment_schema_unavailable', $blocked->get_error_code());

        $deny_ddl = static function (): bool {
            return false;
        };
        add_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $deny_ddl);
        try {
            $this->assertFalse(ll_tools_maybe_upgrade_lms_assignment_schema());
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['lms_assignments']));
        } finally {
            remove_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $deny_ddl);
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['lms_assignments']);
            $this->assertTrue(ll_tools_install_lms_assignment_schema());
        }
    }

    public function test_manifest_bounds_private_answer_key_and_revision_immutability(): void
    {
        $too_short = $this->manifest(4);
        $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($too_short));

        $missing_kind = $this->manifest();
        unset($missing_kind['kind']);
        $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($missing_kind));

        $oversized = $this->manifest();
        $oversized['ignored'] = str_repeat('x', LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_MAX_BYTES);
        $too_large = ll_tools_lms_assignment_normalize_manifest($oversized);
        $this->assertWPError($too_large);
        $this->assertSame('assignment_manifest_too_large', $too_large->get_error_code());

        $invalid_key = $this->manifest();
        $invalid_key['items'][0]['key'] = '../unsafe';
        $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($invalid_key));

        $two_correct = $this->manifest();
        $two_correct['items'][0]['options'][1]['correct'] = true;
        $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($two_correct));

        $limit_five = static function (): int {
            return 5;
        };
        add_filter('ll_tools_lms_assignment_manifest_item_limit', $limit_five);
        try {
            $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($this->manifest(6)));
        } finally {
            remove_filter('ll_tools_lms_assignment_manifest_item_limit', $limit_five);
        }

        $normalized = ll_tools_lms_assignment_normalize_manifest($this->manifest());
        $this->assertIsArray($normalized);
        $public = ll_tools_lms_assignment_public_manifest($normalized);
        $this->assertArrayHasKey('correct', $normalized['items'][0]['options'][0]);
        $this->assertArrayNotHasKey('correct', $public['items'][0]['options'][0]);

        [$teacher_id, , $class_id] = $this->classFixture();
        $assignment_id = ll_tools_lms_assignment_create(
            $class_id,
            $this->assignmentInput('latest', 2),
            $teacher_id
        );
        $this->assertIsInt($assignment_id);
        $assignment = ll_tools_lms_assignment_get($assignment_id);
        $this->assertIsArray($assignment);
        $first_revision = ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']);
        $this->assertIsArray($first_revision);
        $first_json = (string) $first_revision['manifest_json'];
        $first_hash = (string) $first_revision['manifest_hash'];

        $changed = $this->assignmentInput('best', 3);
        $changed['manifest']['items'][0]['options'][0]['correct'] = false;
        $changed['manifest']['items'][0]['options'][1]['correct'] = true;
        $second_revision_id = ll_tools_lms_assignment_create_revision($assignment_id, $changed, $teacher_id);
        $this->assertIsInt($second_revision_id);
        $this->assertGreaterThan((int) $first_revision['id'], $second_revision_id);
        $first_revision_after = ll_tools_lms_assignment_get_revision((int) $first_revision['id']);
        $this->assertSame($first_json, (string) $first_revision_after['manifest_json']);
        $this->assertSame($first_hash, (string) $first_revision_after['manifest_hash']);

        $this->assertTrue(ll_tools_lms_assignment_publish($assignment_id, $teacher_id));
        $immutable = ll_tools_lms_assignment_create_revision($assignment_id, $this->assignmentInput(), $teacher_id);
        $this->assertWPError($immutable);
        $this->assertSame('assignment_revision_immutable', $immutable->get_error_code());
        $this->assertTrue(ll_tools_lms_assignment_archive($assignment_id, $teacher_id));

        $listed = ll_tools_lms_assignments_for_class($class_id, $teacher_id, ['number' => 5, 'status' => 'archived']);
        $this->assertIsArray($listed);
        $this->assertCount(1, $listed);
        $this->assertSame($assignment_id, (int) $listed[0]['id']);
    }

    public function test_class_owner_and_current_member_fences_apply_to_every_learner_write(): void
    {
        [$teacher_id, $learner_id, $class_id] = $this->classFixture();
        $other_teacher = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $assignment_id = ll_tools_lms_assignment_create($class_id, $this->assignmentInput(), $teacher_id);
        $this->assertIsInt($assignment_id);

        $forbidden_publish = ll_tools_lms_assignment_publish($assignment_id, $other_teacher);
        $this->assertWPError($forbidden_publish);
        $this->assertSame('assignment_forbidden', $forbidden_publish->get_error_code());
        $this->assertTrue(ll_tools_lms_assignment_publish($assignment_id, $teacher_id));

        $not_member = ll_tools_lms_assignment_start_attempt($assignment_id, $learner_id);
        $this->assertWPError($not_member);
        $this->assertSame('assignment_membership_required', $not_member->get_error_code());

        $this->assertTrue(ll_tools_teacher_class_add_student($class_id, $learner_id));
        $started = ll_tools_lms_assignment_start_attempt($assignment_id, $learner_id);
        $this->assertIsArray($started);
        $attempt_uuid = (string) $started['attempt']['attempt_uuid'];

        $removed = ll_tools_teacher_class_remove_student($class_id, $learner_id);
        $this->assertIsArray($removed);
        $answer = ll_tools_lms_assignment_submit_answer(
            $attempt_uuid,
            wp_generate_uuid4(),
            'item-1',
            'correct',
            $learner_id
        );
        $this->assertWPError($answer);
        $this->assertSame('assignment_membership_required', $answer->get_error_code());
        $finalize = ll_tools_lms_assignment_finalize_attempt($attempt_uuid, $learner_id);
        $this->assertWPError($finalize);
        $this->assertSame('assignment_membership_required', $finalize->get_error_code());
    }

    public function test_answer_replay_is_append_only_and_finalization_scores_only_server_truth(): void
    {
        global $wpdb;

        [$teacher_id, $learner_id, $class_id] = $this->classFixture(true);
        $assignment_id = $this->publishedAssignment($teacher_id, $class_id, 'latest', 2);
        $started = ll_tools_lms_assignment_start_attempt($assignment_id, $learner_id);
        $this->assertIsArray($started);
        $this->assertArrayNotHasKey('correct', $started['manifest']['items'][0]['options'][0]);
        $attempt_uuid = (string) $started['attempt']['attempt_uuid'];
        $answer_uuid = wp_generate_uuid4();

        $first = ll_tools_lms_assignment_submit_answer($attempt_uuid, $answer_uuid, 'item-1', 'correct', $learner_id);
        $this->assertIsArray($first);
        $this->assertTrue($first['is_correct']);
        $this->assertFalse($first['replayed']);
        $replay = ll_tools_lms_assignment_submit_answer($attempt_uuid, $answer_uuid, 'item-1', 'correct', $learner_id);
        $this->assertIsArray($replay);
        $this->assertTrue($replay['replayed']);

        $conflict = ll_tools_lms_assignment_submit_answer($attempt_uuid, $answer_uuid, 'item-1', 'wrong', $learner_id);
        $this->assertWPError($conflict);
        $this->assertSame('assignment_answer_replay_conflict', $conflict->get_error_code());
        $second_uuid = ll_tools_lms_assignment_submit_answer($attempt_uuid, wp_generate_uuid4(), 'item-1', 'correct', $learner_id);
        $this->assertWPError($second_uuid);
        $this->assertSame('assignment_item_already_answered', $second_uuid->get_error_code());

        $wrong = ll_tools_lms_assignment_submit_answer($attempt_uuid, wp_generate_uuid4(), 'item-2', 'wrong', $learner_id);
        $correct = ll_tools_lms_assignment_submit_answer($attempt_uuid, wp_generate_uuid4(), 'item-3', 'correct', $learner_id);
        $this->assertIsArray($wrong);
        $this->assertFalse($wrong['is_correct']);
        $this->assertIsArray($correct);
        $this->assertTrue($correct['is_correct']);

        $finalized = ll_tools_lms_assignment_finalize_attempt($attempt_uuid, $learner_id);
        $this->assertIsArray($finalized);
        $this->assertSame(2, $finalized['attempt']['score_given']);
        $this->assertSame(5, $finalized['attempt']['score_maximum']);
        $this->assertSame(1, $finalized['grade']['grade_revision']);
        $this->assertTrue($finalized['grade_changed']);

        $late_answer_replay = ll_tools_lms_assignment_submit_answer(
            $attempt_uuid,
            $answer_uuid,
            'item-1',
            'correct',
            $learner_id
        );
        $this->assertIsArray($late_answer_replay);
        $this->assertTrue($late_answer_replay['replayed']);

        $finalize_replay = ll_tools_lms_assignment_finalize_attempt($attempt_uuid, $learner_id);
        $this->assertIsArray($finalize_replay);
        $this->assertTrue($finalize_replay['replayed']);
        $this->assertFalse($finalize_replay['grade_changed']);
        $this->assertSame(1, $finalize_replay['grade']['grade_revision']);

        $attempt = ll_tools_lms_assignment_get_attempt($attempt_uuid);
        $this->assertIsArray($attempt);
        $tables = ll_tools_lms_assignment_table_names();
        $answer_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item_key, is_correct FROM {$tables['answers']} WHERE attempt_id = %d ORDER BY item_key ASC",
            (int) $attempt['id']
        ), ARRAY_A);
        $this->assertSame(
            [
                ['item_key' => 'item-1', 'is_correct' => '1'],
                ['item_key' => 'item-2', 'is_correct' => '0'],
                ['item_key' => 'item-3', 'is_correct' => '1'],
            ],
            $answer_rows
        );

        $reflection = new ReflectionFunction('ll_tools_lms_assignment_submit_answer');
        $this->assertSame(5, $reflection->getNumberOfParameters());
        $this->assertSame(['attempt_uuid', 'answer_uuid', 'item_key', 'option_key', 'user_id'], array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $reflection->getParameters()
        ));
    }

    public function test_nested_finalization_defers_worker_scheduling_until_the_outer_commit(): void
    {
        global $wpdb;

        [$teacherId, $learnerId, $classId] = $this->classFixture(true);
        $assignmentId = $this->publishedAssignment($teacherId, $classId, 'latest', 1);
        $assignment = ll_tools_lms_assignment_get($assignmentId);
        $this->assertIsArray($assignment);
        $revisionId = (int) $assignment['current_revision_id'];
        $started = ll_tools_lms_assignment_start_attempt($assignmentId, $learnerId);
        $this->assertIsArray($started);
        $attemptUuid = (string) $started['attempt']['attempt_uuid'];

        $adapter = 'assignment_test_adapter';
        ll_tools_grade_delivery_unregister_adapter($adapter);
        $this->assertTrue(ll_tools_grade_delivery_register_adapter($adapter, [
            'validate_identity' => static fn(array $mapping): bool => true,
            'validate_destination' => static fn(array $mapping): bool => true,
            'validate_recipient' => static fn(array $mapping): bool => true,
            'send' => static fn(array $context): array => ['type' => 'permanent', 'error_code' => 'test_only'],
        ]) === true);
        $deliveryTables = ll_tools_grade_delivery_table_names();
        try {
            $connectionHash = hash('sha256', 'assignment-nested-connection');
            $identityId = ll_tools_grade_delivery_create_external_identity([
                'adapter' => $adapter,
                'connection_key_hash' => $connectionHash,
                'subject_key_hash' => hash('sha256', 'assignment-nested-subject'),
                'learner_user_id' => $learnerId,
            ]);
            $this->assertIsInt($identityId);
            $destinationId = ll_tools_grade_delivery_create_destination([
                'adapter' => $adapter,
                'connection_key_hash' => $connectionHash,
                'destination_key_hash' => hash('sha256', 'assignment-nested-destination'),
                'assignment_id' => $assignmentId,
                'revision_id' => $revisionId,
            ]);
            $this->assertIsInt($destinationId);
            $recipientId = ll_tools_grade_delivery_create_recipient([
                'destination_id' => $destinationId,
                'external_identity_id' => $identityId,
                'learner_user_id' => $learnerId,
                'recipient_key_hash' => hash('sha256', 'assignment-nested-recipient'),
            ]);
            $this->assertIsInt($recipientId);

            wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
            $outer = ll_tools_lms_assignment_begin_transaction();
            $this->assertIsArray($outer);
            $outerOpen = true;
            try {
                $finalized = ll_tools_lms_assignment_finalize_attempt($attemptUuid, $learnerId);
                $this->assertIsArray($finalized);
                $this->assertSame(1, $finalized['delivery_enqueued']);
                $this->assertFalse($finalized['delivery_scheduled']);
                $this->assertTrue($finalized['delivery_schedule_required']);
                $this->assertFalse(wp_next_scheduled(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK));
                ll_tools_lms_assignment_rollback_transaction($outer);
                $outerOpen = false;
            } finally {
                if ($outerOpen) {
                    ll_tools_lms_assignment_rollback_transaction($outer);
                }
            }

            $attempt = ll_tools_lms_assignment_get_attempt($attemptUuid);
            $this->assertIsArray($attempt);
            $this->assertSame('started', $attempt['status']);
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$deliveryTables['deliveries']} WHERE learner_user_id = %d",
                $learnerId
            )));
        } finally {
            foreach (['deliveries', 'recipients', 'identities', 'destinations'] as $key) {
                $wpdb->query("DELETE FROM {$deliveryTables[$key]}");
            }
            ll_tools_grade_delivery_unregister_adapter($adapter);
            wp_clear_scheduled_hook(LL_TOOLS_GRADE_DELIVERY_WORKER_HOOK);
        }
    }

    public function test_finalization_fails_closed_when_authoritative_score_read_fails(): void
    {
        global $wpdb;

        [$teacherId, $learnerId, $classId] = $this->classFixture(true);
        $assignmentId = $this->publishedAssignment($teacherId, $classId, 'latest', 1);
        $started = ll_tools_lms_assignment_start_attempt($assignmentId, $learnerId);
        $this->assertIsArray($started);
        $attemptUuid = (string) $started['attempt']['attempt_uuid'];
        $this->assertIsArray(ll_tools_lms_assignment_submit_answer(
            $attemptUuid,
            wp_generate_uuid4(),
            'item-1',
            'correct',
            $learnerId
        ));

        $answersTable = ll_tools_lms_assignment_table_names()['answers'];
        $breakScoreRead = static function (string $query) use ($answersTable): string {
            if (str_contains($query, "SELECT COUNT(*) FROM {$answersTable} WHERE attempt_id")) {
                return "SELECT ll_tools_missing_score_column FROM {$answersTable} LIMIT 1";
            }
            return $query;
        };
        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $breakScoreRead);
        try {
            $finalized = ll_tools_lms_assignment_finalize_attempt($attemptUuid, $learnerId);
            $this->assertWPError($finalized);
            $this->assertSame('assignment_finalize_write_failed', $finalized->get_error_code());
        } finally {
            remove_filter('query', $breakScoreRead);
            $wpdb->suppress_errors($previousSuppress);
        }

        $attempt = ll_tools_lms_assignment_get_attempt($attemptUuid);
        $this->assertIsArray($attempt);
        $this->assertSame('started', $attempt['status']);
        $this->assertSame(0, (int) $attempt['score_given']);
        $this->assertNull(ll_tools_lms_assignment_get_grade(
            $assignmentId,
            (int) $attempt['revision_id'],
            $learnerId
        ));
    }

    public function test_first_latest_and_best_policies_bump_grade_revision_only_when_selection_changes(): void
    {
        [$teacher_id, $learner_id, $class_id] = $this->classFixture(true);
        $expectations = [
            'first' => ['selected_attempt' => 1, 'grade_revision' => 1],
            'latest' => ['selected_attempt' => 3, 'grade_revision' => 3],
            'best' => ['selected_attempt' => 2, 'grade_revision' => 2],
        ];

        foreach ($expectations as $policy => $expected) {
            $assignment_id = $this->publishedAssignment($teacher_id, $class_id, $policy, 3);
            $results = [];
            foreach ([1, 4, 2] as $score) {
                $results[] = $this->completeAttempt($assignment_id, $learner_id, $score);
            }
            $assignment = ll_tools_lms_assignment_get($assignment_id);
            $grade = ll_tools_lms_assignment_get_grade(
                $assignment_id,
                (int) $assignment['current_revision_id'],
                $learner_id
            );
            $this->assertIsArray($grade);
            $selected = ll_tools_lms_assignment_get_attempt((int) $grade['selected_attempt_id']);
            $this->assertIsArray($selected);
            $this->assertSame($expected['selected_attempt'], (int) $selected['attempt_number'], $policy);
            $this->assertSame($expected['grade_revision'], (int) $grade['grade_revision'], $policy);

            if ($policy === 'first') {
                $this->assertFalse($results[1]['grade_changed']);
                $this->assertFalse($results[2]['grade_changed']);
            } elseif ($policy === 'latest') {
                $this->assertTrue($results[1]['grade_changed']);
                $this->assertTrue($results[2]['grade_changed']);
            } else {
                $this->assertTrue($results[1]['grade_changed']);
                $this->assertFalse($results[2]['grade_changed']);
            }
        }
    }

    public function test_attempt_limit_windows_and_bounded_privacy_lifecycle(): void
    {
        global $wpdb;

        [$teacher_id, $learner_id, $class_id] = $this->classFixture(true);
        $clock = 1786651200;
        $clock_filter = static function () use (&$clock): int {
            return $clock;
        };
        add_filter('ll_tools_lms_assignment_now', $clock_filter);
        try {
            $future_input = $this->assignmentInput('latest', 1);
            $future_input['available_at'] = gmdate('Y-m-d H:i:s', $clock + HOUR_IN_SECONDS);
            $future_input['due_at'] = gmdate('Y-m-d H:i:s', $clock + 2 * HOUR_IN_SECONDS);
            $future_id = ll_tools_lms_assignment_create($class_id, $future_input, $teacher_id);
            $this->assertIsInt($future_id);
            $this->assertTrue(ll_tools_lms_assignment_publish($future_id, $teacher_id));
            $future = ll_tools_lms_assignment_start_attempt($future_id, $learner_id);
            $this->assertWPError($future);
            $this->assertSame('assignment_not_available', $future->get_error_code());

            $assignment_id = $this->publishedAssignment($teacher_id, $class_id, 'latest', 1);
            $completed = $this->completeAttempt($assignment_id, $learner_id, 3);
            $this->assertSame(3, $completed['attempt']['score_given']);
            $limited = ll_tools_lms_assignment_start_attempt($assignment_id, $learner_id);
            $this->assertWPError($limited);
            $this->assertSame('assignment_attempt_limit_reached', $limited->get_error_code());

            $export = ll_tools_lms_assignment_privacy_export_items($learner_id, 1, 25);
            $this->assertIsArray($export);
            $this->assertTrue($export['done']);
            $this->assertCount(1, $export['data']);
            $this->assertStringContainsString('Response to item-1', wp_json_encode($export['data']));

            $done = false;
            for ($batch = 0; $batch < 5 && !$done; $batch++) {
                $erased = ll_tools_lms_assignment_erase_user_data($learner_id);
                $this->assertIsArray($erased);
                $done = (bool) $erased['done'];
            }
            $this->assertTrue($done);
            $tables = ll_tools_lms_assignment_table_names();
            $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['attempts']} WHERE user_id = %d",
                $learner_id
            )));
            $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['grades']} WHERE user_id = %d",
                $learner_id
            )));
            $this->assertIsArray(ll_tools_lms_assignment_get($assignment_id));
        } finally {
            remove_filter('ll_tools_lms_assignment_now', $clock_filter);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function classFixture(bool $add_learner = false): array
    {
        $teacher_id = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $learner_id = self::factory()->user->create(['role' => 'll_tools_learner']);
        $suffix = strtolower(wp_generate_password(8, false, false));
        $wordset = wp_insert_term('LMS Assignment Wordset ' . $suffix, 'wordset', ['slug' => 'lms-assignment-' . $suffix]);
        $this->assertIsArray($wordset);
        $class_id = ll_tools_teacher_class_create(
            $teacher_id,
            'LMS Assignment Class ' . $suffix,
            (int) $wordset['term_id']
        );
        $this->assertIsInt($class_id);
        if ($add_learner) {
            $this->assertTrue(ll_tools_teacher_class_add_student($class_id, $learner_id));
        }
        return [$teacher_id, $learner_id, $class_id];
    }

    private function manifest(int $count = 5): array
    {
        $items = [];
        for ($index = 1; $index <= $count; $index++) {
            $items[] = [
                'key' => 'item-' . $index,
                'options' => [
                    ['key' => 'correct', 'correct' => true],
                    ['key' => 'wrong', 'correct' => false],
                ],
            ];
        }
        return ['schema' => 1, 'kind' => 'closed_response', 'items' => $items];
    }

    private function assignmentInput(string $policy = 'latest', int $attempt_limit = 3): array
    {
        return [
            'title' => 'Server-authoritative quiz ' . wp_generate_password(5, false, false),
            'manifest' => $this->manifest(),
            'points_maximum' => 10,
            'attempt_limit' => $attempt_limit,
            'grade_policy' => $policy,
        ];
    }

    private function publishedAssignment(int $teacher_id, int $class_id, string $policy, int $attempt_limit): int
    {
        $assignment_id = ll_tools_lms_assignment_create(
            $class_id,
            $this->assignmentInput($policy, $attempt_limit),
            $teacher_id
        );
        $this->assertIsInt($assignment_id);
        $this->assertTrue(ll_tools_lms_assignment_publish($assignment_id, $teacher_id));
        return $assignment_id;
    }

    private function completeAttempt(int $assignment_id, int $learner_id, int $score): array
    {
        $started = ll_tools_lms_assignment_start_attempt($assignment_id, $learner_id);
        $this->assertIsArray($started);
        $attempt_uuid = (string) $started['attempt']['attempt_uuid'];
        for ($index = 1; $index <= 5; $index++) {
            $answer = ll_tools_lms_assignment_submit_answer(
                $attempt_uuid,
                wp_generate_uuid4(),
                'item-' . $index,
                $index <= $score ? 'correct' : 'wrong',
                $learner_id
            );
            $this->assertIsArray($answer);
        }
        $finalized = ll_tools_lms_assignment_finalize_attempt($attempt_uuid, $learner_id);
        $this->assertIsArray($finalized);
        return $finalized;
    }
}
