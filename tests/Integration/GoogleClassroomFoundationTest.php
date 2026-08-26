<?php
declare(strict_types=1);

final class GoogleClassroomFoundationTest extends LL_Tools_TestCase
{
    /** @var callable */
    private $keyFilter;
    /** @var callable */
    private $clientIdFilter;
    /** @var callable */
    private $clientSecretFilter;
    /** @var callable */
    private $insecureCallbackFilter;
    /** @var callable */
    private $writesFilter;
    /** @var callable */
    private $writeAdapterReadyFilter;
    private bool $writesReady = false;
    /** @var array<int,callable> */
    private array $httpFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyFilter = static fn($value): string => str_repeat('K', 32);
        $this->clientIdFilter = static fn($value): string => 'classroom-client.apps.googleusercontent.com';
        $this->clientSecretFilter = static fn($value): string => 'classroom-client-secret';
        $this->insecureCallbackFilter = static fn($allowed): bool => true;
        $this->writesFilter = function ($ready): bool {
            return $this->writesReady;
        };
        $this->writeAdapterReadyFilter = static fn($ready): bool => true;
        add_filter('ll_tools_lms_credential_master_key', $this->keyFilter);
        add_filter('ll_tools_google_classroom_client_id', $this->clientIdFilter);
        add_filter('ll_tools_google_classroom_client_secret', $this->clientSecretFilter);
        add_filter('ll_tools_google_classroom_allow_insecure_callback', $this->insecureCallbackFilter);
        add_filter('ll_tools_google_classroom_writes_ready', $this->writesFilter);
        add_filter('ll_tools_google_classroom_write_adapter_ready', $this->writeAdapterReadyFilter);

        ll_tools_grade_delivery_unregister_adapter('google_classroom');
        $this->assertTrue(ll_tools_grade_delivery_register_adapter('google_classroom', [
            'validate_identity' => static fn(array $mapping): bool => true,
            'validate_destination' => static fn(array $mapping): bool => true,
            'validate_recipient' => static fn(array $mapping): bool => true,
            'send' => static fn(array $context): array => ['type' => 'permanent', 'error_code' => 'test_only'],
        ]) === true);

        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_google_classroom_schema());
        $this->clearAssignmentTables();
        $this->clearGoogleTables();
    }

    protected function tearDown(): void
    {
        foreach ($this->httpFilters as $filter) {
            remove_filter('pre_http_request', $filter, 10);
        }
        $this->httpFilters = [];
        wp_clear_scheduled_hook(LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK);
        $this->clearGoogleTables();
        $this->clearAssignmentTables();
        remove_filter('ll_tools_lms_credential_master_key', $this->keyFilter);
        remove_filter('ll_tools_google_classroom_client_id', $this->clientIdFilter);
        remove_filter('ll_tools_google_classroom_client_secret', $this->clientSecretFilter);
        remove_filter('ll_tools_google_classroom_allow_insecure_callback', $this->insecureCallbackFilter);
        remove_filter('ll_tools_google_classroom_writes_ready', $this->writesFilter);
        remove_filter('ll_tools_google_classroom_write_adapter_ready', $this->writeAdapterReadyFilter);
        ll_tools_grade_delivery_unregister_adapter('google_classroom');
        parent::tearDown();
    }

    private function clearGoogleTables(): void
    {
        global $wpdb;
        if (!function_exists('ll_tools_google_classroom_table_names')) {
            return;
        }
        foreach (ll_tools_google_classroom_table_names() as $table) {
            $wpdb->query("DELETE FROM {$table}");
        }
    }

    private function clearAssignmentTables(): void
    {
        global $wpdb;
        if (!function_exists('ll_tools_lms_assignment_table_names')) {
            return;
        }
        $tables = ll_tools_lms_assignment_table_names();
        foreach (['answers', 'grades', 'attempts', 'revisions', 'assignments'] as $key) {
            $table = $tables[$key] ?? '';
            if ($table !== '') {
                $wpdb->query("DELETE FROM {$table}");
            }
        }
    }

    private function httpResponse(array $body, int $status = 200, array $headers = []): array
    {
        return [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'response' => ['code' => $status, 'message' => $status < 300 ? 'OK' : 'Rejected'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    private function addHttpFilter(callable $filter): void
    {
        $this->httpFilters[] = $filter;
        add_filter('pre_http_request', $filter, 10, 3);
    }

    private function requiredScopes(): array
    {
        return ll_tools_google_classroom_required_scopes();
    }

    private function consumedStateHash(int $teacherId): string
    {
        $state = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertIsArray($state);
        $consumed = ll_tools_google_classroom_consume_oauth_state((string) $state['state'], $teacherId);
        $this->assertIsArray($consumed);
        return (string) $consumed['state_hash'];
    }

    private function connectedTeacher(): array
    {
        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $stored = ll_tools_google_classroom_store_connection(
            $teacherId,
            'refresh-token-' . $teacherId,
            [
                'sub' => 'google-sub-' . $teacherId,
                'email' => 'teacher' . $teacherId . '@example.test',
                'email_verified' => true,
                'name' => 'Test Teacher',
                'hd' => 'school.example',
            ],
            $this->requiredScopes(),
            $this->consumedStateHash($teacherId)
        );
        $this->assertIsArray($stored);
        return [$teacherId, $stored];
    }

    /**
     * Insert a canonical selected-grade snapshot without exercising the API
     * route being tested. The resolver must independently read every row.
     */
    private function authoritativeGradeFixture(int $teacherId): array
    {
        global $wpdb;

        $classId = wp_insert_post([
            'post_type' => LL_TOOLS_TEACHER_CLASS_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Integration class',
            'post_author' => $teacherId,
        ]);
        $this->assertIsInt($classId);
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $items = [];
        for ($index = 1; $index <= 5; $index++) {
            $items[] = [
                'key' => 'item-' . $index,
                'options' => [
                    ['key' => 'a', 'correct' => true],
                    ['key' => 'b', 'correct' => false],
                ],
            ];
        }
        $manifest = ll_tools_lms_assignment_normalize_manifest([
            'schema' => LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA,
            'kind' => 'closed_response',
            'items' => $items,
        ]);
        $this->assertIsArray($manifest);
        $manifestJson = wp_json_encode($manifest);
        $tables = ll_tools_lms_assignment_table_names();
        $now = gmdate('Y-m-d H:i:s');

        $this->assertSame(1, $wpdb->insert($tables['assignments'], [
            'assignment_uuid' => wp_generate_uuid4(),
            'class_id' => $classId,
            'wordset_id' => 1,
            'created_by_user_id' => $teacherId,
            'title' => 'Hebrew Quiz 1',
            'status' => 'published',
            'current_revision_id' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $assignmentId = (int) $wpdb->insert_id;
        $this->assertSame(1, $wpdb->insert($tables['revisions'], [
            'assignment_id' => $assignmentId,
            'revision_number' => 1,
            'manifest_schema' => LL_TOOLS_LMS_ASSIGNMENT_MANIFEST_SCHEMA,
            'manifest_json' => $manifestJson,
            'manifest_hash' => hash('sha256', $manifestJson),
            'question_count' => 5,
            'points_maximum' => '20.0000',
            'attempt_limit' => 1,
            'grade_policy' => 'latest',
            'available_at' => null,
            'due_at' => null,
            'created_by_user_id' => $teacherId,
            'created_at' => $now,
            'published_at' => $now,
        ]));
        $revisionId = (int) $wpdb->insert_id;
        $this->assertSame(1, $wpdb->update(
            $tables['assignments'],
            ['current_revision_id' => $revisionId],
            ['id' => $assignmentId]
        ));
        $this->assertSame(1, $wpdb->insert($tables['attempts'], [
            'attempt_uuid' => wp_generate_uuid4(),
            'assignment_id' => $assignmentId,
            'revision_id' => $revisionId,
            'user_id' => $learnerId,
            'attempt_number' => 1,
            'status' => 'finalized',
            'score_given' => 4,
            'score_maximum' => 5,
            'points_given' => '16.0000',
            'points_maximum' => '20.0000',
            'started_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            'finalized_at' => $now,
            'updated_at' => $now,
        ]));
        $attemptId = (int) $wpdb->insert_id;
        $this->assertSame(1, $wpdb->insert($tables['grades'], [
            'assignment_id' => $assignmentId,
            'revision_id' => $revisionId,
            'user_id' => $learnerId,
            'selected_attempt_id' => $attemptId,
            'grade_revision' => 2,
            'score_given' => 4,
            'score_maximum' => 5,
            'points_given' => '16.0000',
            'points_maximum' => '20.0000',
            'grade_policy' => 'latest',
            'updated_at' => $now,
        ]));

        return [
            'assignment' => ['assignment_id' => $assignmentId, 'revision_id' => $revisionId],
            'grade' => [
                'assignment_id' => $assignmentId,
                'revision_id' => $revisionId,
                'user_id' => $learnerId,
                'selected_attempt_id' => $attemptId,
                'grade_revision' => 2,
                'score_given' => 4,
                'score_maximum' => 5,
                'points_given' => '16.0000',
                'points_maximum' => '20.0000',
            ],
        ];
    }

    public function test_configuration_and_authenticated_envelope_fail_closed(): void
    {
        $status = ll_tools_google_classroom_config_status();
        $this->assertTrue($status['ready']);
        $this->assertArrayNotHasKey('client_secret', $status);
        $this->assertArrayNotHasKey('credential_key', $status);

        $sealed = ll_tools_lms_seal_secret('refresh-token-secret', 'google-test-context');
        $this->assertIsString($sealed);
        $this->assertStringStartsWith('lltcred1.', $sealed);
        $this->assertStringNotContainsString('refresh-token-secret', $sealed);
        $this->assertSame('refresh-token-secret', ll_tools_lms_open_secret($sealed, 'google-test-context'));
        $this->assertWPError(ll_tools_lms_open_secret($sealed, 'wrong-context'));

        $last = substr($sealed, -1);
        $tampered = substr($sealed, 0, -1) . ($last === 'A' ? 'B' : 'A');
        $opened = ll_tools_lms_open_secret($tampered, 'google-test-context');
        $this->assertWPError($opened);
        $this->assertSame('ll_tools_lms_credential_tampered', $opened->get_error_code());

        $invalidKey = static fn($value): string => 'too-short';
        add_filter('ll_tools_lms_credential_master_key', $invalidKey, 20);
        try {
            $this->assertFalse(ll_tools_lms_credential_store_is_available());
            $this->assertFalse(ll_tools_google_classroom_is_configured());
        } finally {
            remove_filter('ll_tools_lms_credential_master_key', $invalidKey, 20);
        }
    }

    public function test_schema_full_readback_schedules_and_repairs_semantic_index_and_engine_drift(): void
    {
        global $wpdb;

        $tables = ll_tools_google_classroom_table_names();
        $connections = $tables['connections'];
        wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['google_classroom']);
        $publicInitFastPath = static fn($allowed): bool => false;
        $publicFilterAdded = false;

        try {
            $this->assertTrue(ll_tools_google_classroom_schema_is_ready(true));
            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} MODIFY COLUMN `status` varchar(32) NULL DEFAULT 'broken'"
            ));
            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} DROP INDEX `uniq_teacher_sub`, "
                . 'ADD KEY `uniq_teacher_sub` (`teacher_user_id`, `google_sub_hash`)'
            ));
            $this->assertTrue(ll_tools_google_classroom_schema_markers_current());

            // A normal public/admin init trusts current markers and performs no
            // full table readback. Feature admission detects drift and queues it.
            add_filter(
                'll_tools_google_classroom_schema_init_full_readback_allowed',
                $publicInitFastPath,
                20
            );
            $publicFilterAdded = true;
            $this->assertTrue(ll_tools_maybe_upgrade_google_classroom_schema());
            $this->assertFalse(
                wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['google_classroom'])
            );
            remove_filter(
                'll_tools_google_classroom_schema_init_full_readback_allowed',
                $publicInitFastPath,
                20
            );
            $publicFilterAdded = false;

            $this->assertFalse(ll_tools_google_classroom_schema_is_ready(true));
            $this->assertNotFalse(
                wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['google_classroom'])
            );

            // WP-Cron, CLI, and tests are admitted to re-read and repair drift.
            $this->assertTrue(ll_tools_maybe_upgrade_google_classroom_schema());
            $this->assertTrue(ll_tools_google_classroom_schema_is_ready(true));
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['google_classroom']);
            $statusColumn = $wpdb->get_row(
                "SHOW FULL COLUMNS FROM {$connections} WHERE Field = 'status'",
                ARRAY_A
            );
            $this->assertIsArray($statusColumn);
            $this->assertSame('varchar(24)', strtolower((string) $statusColumn['Type']));
            $this->assertSame('NO', (string) $statusColumn['Null']);
            $this->assertSame('connected', (string) $statusColumn['Default']);
            $uniqueRows = $wpdb->get_results(
                "SHOW INDEX FROM {$connections} WHERE Key_name = 'uniq_teacher_sub'",
                ARRAY_A
            );
            $this->assertCount(2, $uniqueRows);
            usort($uniqueRows, static fn(array $left, array $right): int =>
                (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']
            );
            $this->assertSame([0, 0], array_map(
                static fn(array $row): int => (int) $row['Non_unique'],
                $uniqueRows
            ));
            $this->assertSame(
                ['teacher_user_id', 'google_sub_hash'],
                array_column($uniqueRows, 'Column_name')
            );

            $this->assertNotFalse($wpdb->query("ALTER TABLE {$connections} ENGINE=MyISAM"));
            $this->assertFalse(ll_tools_google_classroom_schema_is_ready(true));
            $this->assertTrue(ll_tools_maybe_upgrade_google_classroom_schema());
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $connections
            ));
            $this->assertSame('innodb', strtolower((string) $engine));
            $this->assertTrue(ll_tools_google_classroom_schema_is_ready(true));

            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} ADD UNIQUE KEY `unexpected_teacher_unique` (`teacher_user_id`)"
            ));
            $this->assertFalse(ll_tools_google_classroom_schema_is_ready(true));
            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} DROP INDEX `unexpected_teacher_unique`"
            ));
            $this->assertTrue(ll_tools_google_classroom_schema_is_ready(true));

            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} ADD COLUMN `unexpected_required` varchar(16) NOT NULL"
            ));
            $this->assertFalse(ll_tools_google_classroom_schema_is_ready(true));
            $this->assertNotFalse($wpdb->query(
                "ALTER TABLE {$connections} DROP COLUMN `unexpected_required`"
            ));
            $this->assertTrue(ll_tools_google_classroom_schema_is_ready(true));
        } finally {
            if ($publicFilterAdded) {
                remove_filter(
                    'll_tools_google_classroom_schema_init_full_readback_allowed',
                    $publicInitFastPath,
                    20
                );
            }
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['google_classroom']);
            ll_tools_install_google_classroom_schema();
        }
    }

    public function test_oauth_state_is_teacher_callback_and_pkce_bound_and_single_use(): void
    {
        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $state = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertIsArray($state);
        $this->assertSame('S256', $state['code_challenge_method']);
        $this->assertSame(
            ll_tools_lms_base64url_encode(hash('sha256', $state['code_verifier'], true)),
            $state['code_challenge']
        );

        $authorizationUrl = ll_tools_google_classroom_authorization_url(
            $state['state'],
            $state['code_challenge']
        );
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authorizationUrl);
        parse_str((string) wp_parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame($state['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(ll_tools_google_classroom_callback_url(), $query['redirect_uri']);
        $this->assertNotContains(
            'https://www.googleapis.com/auth/classroom.coursework.students',
            preg_split('/\s+/', (string) $query['scope'])
        );

        $this->assertWPError(ll_tools_google_classroom_consume_oauth_state($state['state'], $teacherId + 1));
        $consumed = ll_tools_google_classroom_consume_oauth_state($state['state'], $teacherId);
        $this->assertIsArray($consumed);
        $this->assertSame($state['code_verifier'], $consumed['code_verifier']);
        $this->assertSame(ll_tools_google_classroom_callback_url(), $consumed['callback_url']);
        $this->assertWPError(ll_tools_google_classroom_consume_oauth_state($state['state'], $teacherId));

        $second = ll_tools_google_classroom_create_oauth_state($teacherId);
        $changedCallback = static fn($url): string => 'http://example.org/different-callback';
        add_filter('ll_tools_google_classroom_callback_url', $changedCallback, 20);
        try {
            $this->assertWPError(ll_tools_google_classroom_consume_oauth_state($second['state'], $teacherId));
        } finally {
            remove_filter('ll_tools_google_classroom_callback_url', $changedCallback, 20);
        }
    }

    public function test_oauth_state_admission_enforces_a_hard_per_teacher_pending_limit(): void
    {
        global $wpdb;

        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $limit = ll_tools_google_classroom_pending_oauth_state_limit();
        $this->assertSame(5, $limit);

        $states = [];
        for ($index = 0; $index < $limit; $index++) {
            $state = ll_tools_google_classroom_create_oauth_state($teacherId);
            $this->assertIsArray($state);
            $states[] = $state;
        }

        $blocked = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertWPError($blocked);
        $this->assertSame(
            'll_tools_google_classroom_pending_state_limit_reached',
            $blocked->get_error_code()
        );

        $table = ll_tools_google_classroom_table_names()['oauth_states'];
        $live = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE teacher_user_id = %d AND expires_at >= %s",
            $teacherId,
            gmdate('Y-m-d H:i:s')
        ));
        $this->assertSame($limit, $live);

        $consumed = ll_tools_google_classroom_consume_oauth_state(
            (string) $states[0]['state'],
            $teacherId
        );
        $this->assertIsArray($consumed);
        $this->assertWPError(ll_tools_google_classroom_create_oauth_state($teacherId));

        $stored = ll_tools_google_classroom_store_connection(
            $teacherId,
            'pending-cap-refresh-token',
            [
                'sub' => 'pending-cap-google-sub',
                'email' => 'pending-cap@example.test',
                'email_verified' => true,
                'name' => 'Pending Cap Account',
            ],
            $this->requiredScopes(),
            (string) $consumed['state_hash']
        );
        $this->assertIsArray($stored);
        $replacement = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertIsArray($replacement);
    }

    public function test_oauth_state_retention_is_bounded_scheduled_and_privacy_safe(): void
    {
        global $wpdb;

        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $oauth = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertIsArray($oauth);
        $table = ll_tools_google_classroom_table_names()['oauth_states'];
        $expired = gmdate('Y-m-d H:i:s', time() - (2 * HOUR_IN_SECONDS));
        $this->assertSame(1, $wpdb->update($table, ['expires_at' => $expired], [
            'state_hash' => hash('sha256', (string) $oauth['state']),
        ]));
        for ($index = 0; $index < 100; $index++) {
            $this->assertSame(1, $wpdb->insert($table, [
                'state_hash' => hash('sha256', 'expired-oauth-state-' . $teacherId . '-' . $index),
                'teacher_user_id' => $teacherId,
                'pkce_envelope' => 'expired-test-envelope',
                'callback_hash' => hash('sha256', 'callback-' . $index),
                'expires_at' => $expired,
                'consumed_at' => null,
                'created_at' => $expired,
            ]));
        }

        $this->assertTrue(ll_tools_google_classroom_schedule_oauth_state_cleanup());
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK));
        $this->assertSame(100, ll_tools_google_classroom_cleanup_oauth_states());
        $this->assertSame('1', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
        $this->assertNotFalse(wp_next_scheduled(
            LL_TOOLS_GOOGLE_CLASSROOM_OAUTH_CLEANUP_HOOK,
            ['continuation']
        ));
        $this->assertSame(1, ll_tools_google_classroom_cleanup_oauth_states('continuation'));

        $fresh = ll_tools_google_classroom_create_oauth_state($teacherId);
        $this->assertIsArray($fresh);
        $teacher = get_userdata($teacherId);
        $this->assertInstanceOf(WP_User::class, $teacher);
        $export = ll_tools_privacy_export_google_classroom_connections((string) $teacher->user_email, 1);
        $this->assertIsArray($export);
        $encoded = wp_json_encode($export);
        $this->assertStringContainsString('Stored authorization attempts', $encoded);
        $this->assertStringNotContainsString((string) $fresh['state'], $encoded);
        $this->assertStringNotContainsString((string) $fresh['code_verifier'], $encoded);
    }

    public function test_code_exchange_and_refresh_require_scope_readback_and_use_expected_shapes(): void
    {
        $requests = [];
        $responses = [
            $this->httpResponse([
                'access_token' => 'access-one',
                'refresh_token' => 'refresh-one',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => implode(' ', $this->requiredScopes()),
                'id_token' => 'must-never-be-returned',
            ]),
            $this->httpResponse([
                'access_token' => 'access-two',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
        ];
        $filter = static function ($pre, array $args, string $url) use (&$requests, &$responses) {
            $requests[] = ['url' => $url, 'args' => $args];
            return array_shift($responses);
        };
        $this->addHttpFilter($filter);

        $verifier = str_repeat('v', 64);
        $exchange = ll_tools_google_classroom_exchange_code('one-time-code', $verifier);
        $this->assertIsArray($exchange);
        $this->assertSame('access-one', $exchange['access_token']);
        $this->assertSame('refresh-one', $exchange['refresh_token']);
        $this->assertArrayNotHasKey('id_token', $exchange);
        $firstBody = [];
        parse_str($requests[0]['args']['body'], $firstBody);
        $this->assertSame('authorization_code', $firstBody['grant_type']);
        $this->assertSame($verifier, $firstBody['code_verifier']);
        $this->assertSame(ll_tools_google_classroom_callback_url(), $firstBody['redirect_uri']);
        $this->assertSame('https://oauth2.googleapis.com/token', $requests[0]['url']);

        $refresh = ll_tools_google_classroom_refresh_access_token('refresh-one', $this->requiredScopes());
        $this->assertIsArray($refresh);
        $this->assertSame('access-two', $refresh['access_token']);
        $secondBody = [];
        parse_str($requests[1]['args']['body'], $secondBody);
        $this->assertSame('refresh_token', $secondBody['grant_type']);
        $this->assertSame('refresh-one', $secondBody['refresh_token']);

        $missingScopeFilter = static function () {
            return [
                'headers' => [],
                'body' => wp_json_encode([
                    'access_token' => 'access-no-scopes',
                    'refresh_token' => 'refresh-no-scopes',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $missingScopeFilter, 20, 3);
        try {
            $error = ll_tools_google_classroom_exchange_code('another-code', $verifier);
            $this->assertWPError($error);
            $this->assertSame('ll_tools_google_classroom_scope_readback_missing', $error->get_error_code());
        } finally {
            remove_filter('pre_http_request', $missingScopeFilter, 20);
        }
    }

    public function test_connection_summary_is_token_free_and_local_erasure_makes_no_request(): void
    {
        global $wpdb;
        [$teacherId, $summary] = $this->connectedTeacher();
        $this->assertSame('google_classroom', $summary['provider']);
        $encodedSummary = wp_json_encode($summary);
        $this->assertStringNotContainsString('refresh-token-', $encodedSummary);
        $this->assertStringNotContainsString('google-sub-', $encodedSummary);
        $this->assertStringNotContainsString('@example.test', $encodedSummary);

        $credentials = ll_tools_google_classroom_load_connection_credentials((int) $summary['id'], $teacherId);
        $this->assertIsArray($credentials);
        $this->assertSame('refresh-token-' . $teacherId, $credentials['refresh_token']);

        $privacyProfile = ll_tools_google_classroom_privacy_profile_for_connection(
            (int) $summary['id'],
            $teacherId
        );
        $this->assertSame([
            'email' => 'teacher' . $teacherId . '@example.test',
            'name' => 'Test Teacher',
            'hd' => 'school.example',
            'email_verified' => true,
        ], $privacyProfile);
        $encodedPrivacyProfile = wp_json_encode($privacyProfile);
        $this->assertStringNotContainsString('refresh-token-', $encodedPrivacyProfile);
        $this->assertStringNotContainsString('google-sub-', $encodedPrivacyProfile);
        $this->assertWPError(ll_tools_google_classroom_privacy_profile_for_connection(
            (int) $summary['id'],
            $teacherId + 1
        ));

        $table = ll_tools_google_classroom_table_names()['connections'];
        $envelope = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT credential_envelope FROM {$table} WHERE id = %d",
            $summary['id']
        ));
        $this->assertStringNotContainsString('refresh-token-', $envelope);

        $second = ll_tools_google_classroom_store_connection(
            $teacherId,
            'second-refresh-token',
            [
                'sub' => 'second-google-sub',
                'email' => 'second@example.test',
                'email_verified' => true,
                'name' => 'Second Account',
            ],
            $this->requiredScopes(),
            $this->consumedStateHash($teacherId)
        );
        $this->assertIsArray($second);
        $owned = ll_tools_google_classroom_owned_connections($teacherId);
        $this->assertIsArray($owned);
        $this->assertCount(2, $owned);
        $this->assertTrue(ll_tools_google_classroom_delete_connection((int) $summary['id'], $teacherId));
        $remaining = ll_tools_google_classroom_connection_summary_for_user($teacherId);
        $this->assertCount(1, $remaining);
        $this->assertSame((int) $second['id'], (int) $remaining[0]['id']);

        $requestCount = 0;
        $filter = static function ($pre) use (&$requestCount) {
            $requestCount++;
            return $pre;
        };
        $this->addHttpFilter($filter);
        $this->assertTrue(ll_tools_google_classroom_erase_connection_for_user($teacherId));
        $this->assertSame(0, $requestCount);
        $this->assertSame([], ll_tools_google_classroom_connection_summary_for_user($teacherId));
        $this->assertWPError(ll_tools_google_classroom_load_connection_credentials((int) $summary['id'], $teacherId));
    }

    public function test_connection_admission_enforces_the_ui_cap_but_allows_exact_account_refresh(): void
    {
        global $wpdb;

        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $limit = ll_tools_google_classroom_connection_limit();
        $this->assertSame(20, $limit);
        $first = null;

        for ($index = 0; $index < $limit; $index++) {
            $stored = ll_tools_google_classroom_store_connection(
                $teacherId,
                'cap-refresh-token-' . $index,
                [
                    'sub' => 'cap-google-sub-' . $index,
                    'email' => 'cap-' . $index . '@example.test',
                    'email_verified' => true,
                    'name' => 'Cap Account ' . $index,
                ],
                $this->requiredScopes(),
                $this->consumedStateHash($teacherId)
            );
            $this->assertIsArray($stored);
            if ($index === 0) {
                $first = $stored;
            }
        }

        $this->assertIsArray($first);
        $connectionsTable = ll_tools_google_classroom_table_names()['connections'];
        $this->assertSame(
            $limit,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$connectionsTable} WHERE teacher_user_id = %d",
                $teacherId
            ))
        );

        $refreshed = ll_tools_google_classroom_store_connection(
            $teacherId,
            'cap-refresh-token-rotated',
            [
                'sub' => 'cap-google-sub-0',
                'email' => 'cap-0@example.test',
                'email_verified' => true,
                'name' => 'Cap Account 0',
            ],
            $this->requiredScopes(),
            $this->consumedStateHash($teacherId)
        );
        $this->assertIsArray($refreshed);
        $this->assertSame((int) $first['id'], (int) $refreshed['id']);
        $credentials = ll_tools_google_classroom_load_connection_credentials(
            (int) $first['id'],
            $teacherId
        );
        $this->assertIsArray($credentials);
        $this->assertSame('cap-refresh-token-rotated', $credentials['refresh_token']);

        $blocked = ll_tools_google_classroom_store_connection(
            $teacherId,
            'cap-overflow-token',
            [
                'sub' => 'cap-google-sub-overflow',
                'email' => 'cap-overflow@example.test',
                'email_verified' => true,
                'name' => 'Cap Overflow Account',
            ],
            $this->requiredScopes(),
            $this->consumedStateHash($teacherId)
        );
        $this->assertWPError($blocked);
        $this->assertSame(
            'll_tools_google_classroom_connection_limit_reached',
            $blocked->get_error_code()
        );
        $this->assertCount(
            $limit,
            ll_tools_google_classroom_owned_connections($teacherId, $limit)
        );
    }

    public function test_local_disconnect_and_erasure_preserve_a_caller_owned_transaction(): void
    {
        [$teacherId, $summary] = $this->connectedTeacher();

        $outer = ll_tools_google_classroom_begin_transaction();
        $this->assertIsArray($outer);
        $outerOpen = true;
        try {
            $this->assertTrue(ll_tools_google_classroom_delete_connection((int) $summary['id'], $teacherId));
            $this->assertSame([], ll_tools_google_classroom_connection_summary_for_user($teacherId));
            ll_tools_google_classroom_rollback_transaction($outer);
            $outerOpen = false;
        } finally {
            if ($outerOpen) {
                ll_tools_google_classroom_rollback_transaction($outer);
            }
        }
        $this->assertCount(1, ll_tools_google_classroom_connection_summary_for_user($teacherId));

        $outer = ll_tools_google_classroom_begin_transaction();
        $this->assertIsArray($outer);
        $outerOpen = true;
        try {
            $this->assertTrue(ll_tools_google_classroom_erase_connection_for_user($teacherId));
            $this->assertSame([], ll_tools_google_classroom_connection_summary_for_user($teacherId));
            ll_tools_google_classroom_rollback_transaction($outer);
            $outerOpen = false;
        } finally {
            if ($outerOpen) {
                ll_tools_google_classroom_rollback_transaction($outer);
            }
        }
        $this->assertCount(1, ll_tools_google_classroom_connection_summary_for_user($teacherId));
    }

    public function test_connection_store_rejects_a_teacher_with_a_pending_account_deletion(): void
    {
        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $stateHash = $this->consumedStateHash($teacherId);
        $this->assertTrue(ll_tools_privacy_queue_deleted_user_lms_cleanup($teacherId));
        try {
            $this->assertWPError(ll_tools_google_classroom_create_oauth_state($teacherId));
            $stored = ll_tools_google_classroom_store_connection(
                $teacherId,
                'late-refresh-token',
                [
                    'sub' => 'late-google-sub',
                    'email' => 'late@example.test',
                    'email_verified' => true,
                    'name' => 'Late Teacher',
                ],
                $this->requiredScopes(),
                $stateHash
            );
            $this->assertWPError($stored);
            $this->assertSame([], ll_tools_google_classroom_connection_summary_for_user($teacherId));
        } finally {
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup($teacherId);
        }
    }

    public function test_privacy_erasure_invalidates_an_in_flight_consumed_oauth_generation(): void
    {
        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $stateHash = $this->consumedStateHash($teacherId);
        $this->assertTrue(ll_tools_google_classroom_erase_connection_for_user($teacherId));

        $stored = ll_tools_google_classroom_store_connection(
            $teacherId,
            'late-after-erasure-token',
            [
                'sub' => 'late-after-erasure-sub',
                'email' => 'late-after-erasure@example.test',
                'email_verified' => true,
                'name' => 'Late After Erasure',
            ],
            $this->requiredScopes(),
            $stateHash
        );
        $this->assertWPError($stored);
        $this->assertSame([], ll_tools_google_classroom_connection_summary_for_user($teacherId));
    }

    public function test_userinfo_and_http_errors_are_bounded_and_redacted(): void
    {
        $secret = 'access-token-that-must-not-leak';
        $filter = function ($pre, array $args, string $url) use ($secret) {
            $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', $url);
            $this->assertSame('Bearer ' . $secret, $args['headers']['Authorization']);
            return $this->httpResponse([
                'error' => [
                    'status' => 'PERMISSION_DENIED',
                    'message' => 'raw provider error containing ' . $secret,
                ],
            ], 403);
        };
        $this->addHttpFilter($filter);
        $error = ll_tools_google_classroom_fetch_userinfo($secret);
        $this->assertWPError($error);
        $rendered = wp_json_encode([
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
        ]);
        $this->assertStringNotContainsString($secret, $rendered);
        $this->assertStringNotContainsString('raw provider error', $rendered);
        $this->assertSame(403, $error->get_error_data()['http_status']);

        $this->assertWPError(ll_tools_google_classroom_http_json('GET', 'https://attacker.example/v1/courses'));
    }

    public function test_successful_malformed_json_is_rejected_instead_of_becoming_an_empty_course_list(): void
    {
        $filter = static function () {
            return [
                'headers' => [],
                'body' => '{"courses":',
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        $this->addHttpFilter($filter);

        $error = ll_tools_google_classroom_list_active_courses_with_access_token('bounded-access-token', 10);
        $this->assertWPError($error);
        $this->assertSame('ll_tools_google_classroom_response_invalid', $error->get_error_code());
    }

    public function test_active_course_listing_refreshes_once_and_paginates_with_hard_bounds(): void
    {
        [$teacherId, $connection] = $this->connectedTeacher();
        $requests = [];
        $patchResponseUser = 'student-1';
        $filter = function ($pre, array $args, string $url) use (&$requests, &$patchResponseUser) {
            $requests[] = ['url' => $url, 'args' => $args];
            if ($url === 'https://oauth2.googleapis.com/token') {
                return $this->httpResponse([
                    'access_token' => 'course-access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ]);
            }
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
            if (!isset($query['pageToken'])) {
                return $this->httpResponse([
                    'courses' => [
                        ['id' => 'course-1', 'name' => 'Hebrew I', 'section' => 'A', 'courseState' => 'ACTIVE'],
                        ['id' => 'archived', 'name' => 'Old', 'courseState' => 'ARCHIVED'],
                    ],
                    'nextPageToken' => 'page-two',
                ]);
            }
            return $this->httpResponse([
                'courses' => [
                    ['id' => 'course-2', 'name' => 'Greek I', 'section' => 'B', 'courseState' => 'ACTIVE'],
                    ['id' => 'course-3', 'name' => 'Hebrew II', 'section' => 'C', 'courseState' => 'ACTIVE'],
                ],
            ]);
        };
        $this->addHttpFilter($filter);

        $courses = ll_tools_google_classroom_list_active_courses((int) $connection['id'], $teacherId, 3);
        $this->assertIsArray($courses);
        $this->assertSame(['course-1', 'course-2', 'course-3'], array_column($courses, 'id'));
        $this->assertCount(3, $requests);
        $this->assertSame('https://oauth2.googleapis.com/token', $requests[0]['url']);
        $this->assertStringStartsWith('https://classroom.googleapis.com/v1/courses?', $requests[1]['url']);
        $this->assertSame('Bearer course-access-token', $requests[1]['args']['headers']['Authorization']);
        parse_str((string) wp_parse_url($requests[1]['url'], PHP_URL_QUERY), $firstQuery);
        parse_str((string) wp_parse_url($requests[2]['url'], PHP_URL_QUERY), $secondQuery);
        $this->assertSame('me', $firstQuery['teacherId']);
        $this->assertSame('ACTIVE', $firstQuery['courseStates']);
        $this->assertSame(
            'nextPageToken,courses(id,name,section,courseState,alternateLink)',
            $firstQuery['fields']
        );
        $this->assertSame('page-two', $secondQuery['pageToken']);
    }

    public function test_coursework_submission_and_grade_helpers_are_typed_gated_and_exact(): void
    {
        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        $fixture = $this->authoritativeGradeFixture($teacherId);
        $assignment = $fixture['assignment'];
        $this->assertWPError(ll_tools_google_classroom_create_draft_coursework_with_access_token(
            'write-access', $teacherId, 'course-1', $assignment
        ));

        $grade = $fixture['grade'];
        $this->writesReady = true;
        $requests = [];
        $patchResponseUser = 'student-1';
        $filter = function ($pre, array $args, string $url) use (&$requests, &$patchResponseUser) {
            $requests[] = ['url' => $url, 'args' => $args];
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            if ($method === 'POST' && $path === '/v1/courses/course-1/courseWork') {
                return $this->httpResponse([
                    'id' => 'work-1', 'courseId' => 'course-1', 'title' => 'Hebrew Quiz 1',
                    'state' => 'DRAFT', 'workType' => 'ASSIGNMENT', 'maxPoints' => 20,
                    'associatedWithDeveloper' => true,
                ]);
            }
            if ($method === 'GET' && $path === '/v1/courses/course-1/courseWork/work-1') {
                return $this->httpResponse([
                    'id' => 'work-1', 'courseId' => 'course-1', 'title' => 'Hebrew Quiz 1',
                    'state' => count($requests) <= 2 ? 'DRAFT' : 'PUBLISHED',
                    'workType' => 'ASSIGNMENT', 'maxPoints' => 20,
                    'associatedWithDeveloper' => true,
                ]);
            }
            if ($method === 'GET' && str_ends_with($path, '/studentSubmissions')) {
                return $this->httpResponse(['studentSubmissions' => [[
                    'id' => 'submission-1', 'courseId' => 'course-1',
                    'courseWorkId' => 'work-1', 'userId' => 'student-1', 'state' => 'TURNED_IN',
                ]]]);
            }
            if ($method === 'PATCH' && str_ends_with($path, '/studentSubmissions/submission-1')) {
                return $this->httpResponse([
                    'id' => 'submission-1', 'courseId' => 'course-1', 'courseWorkId' => 'work-1',
                    'userId' => $patchResponseUser, 'state' => 'TURNED_IN', 'draftGrade' => 16,
                ]);
            }
            return new WP_Error('unexpected_google_request', 'Unexpected mocked request.');
        };
        $this->addHttpFilter($filter);

        $markerWrappedAssignment = $assignment;
        $markerWrappedAssignment['source_kind'] = 'server_authoritative_assignment_revision';
        $markerWrappedAssignment['score_maximum'] = 999;
        $this->assertWPError(ll_tools_google_classroom_create_draft_coursework_with_access_token(
            'write-access', $teacherId, 'course-1', $markerWrappedAssignment
        ));
        $this->assertSame([], $requests);

        $staleAssignment = $assignment;
        $staleAssignment['revision_id']++;
        $this->assertWPError(ll_tools_google_classroom_create_draft_coursework_with_access_token(
            'write-access', $teacherId, 'course-1', $staleAssignment
        ));
        $this->assertSame([], $requests);

        $coursework = ll_tools_google_classroom_create_draft_coursework_with_access_token(
            'write-access', $teacherId, 'course-1', $assignment
        );
        $this->assertIsArray($coursework);
        $this->assertSame('DRAFT', $coursework['state']);
        $createdBody = json_decode($requests[0]['args']['body'], true);
        $this->assertSame([
            'title' => 'Hebrew Quiz 1',
            'workType' => 'ASSIGNMENT',
            'state' => 'DRAFT',
            'maxPoints' => 20,
        ], $createdBody);

        $submission = ll_tools_google_classroom_lookup_student_submission_with_access_token(
            'write-access', 'course-1', 'work-1', 'student-1'
        );
        $this->assertIsArray($submission);
        parse_str((string) wp_parse_url($requests[2]['url'], PHP_URL_QUERY), $lookupQuery);
        $this->assertSame('student-1', $lookupQuery['userId']);
        $this->assertSame('2', $lookupQuery['pageSize']);

        $phaseOne = $grade;
        $phaseOne['source_kind'] = 'server_authoritative_assignment_attempt';
        $phaseOne['kind'] = 'practice_first_try';
        $phaseOne['score_basis'] = 'first_try_distinct_words';
        $phaseOne['score_given'] = 5;
        $before = count($requests);
        $this->assertWPError(ll_tools_google_classroom_patch_draft_grade_with_access_token(
            'write-access', $teacherId, 'course-1', 'work-1', 'student-1', 'submission-1', $phaseOne
        ));
        $this->assertSame($before, count($requests));

        $stale = $grade;
        $stale['grade_revision']--;
        $this->assertWPError(ll_tools_google_classroom_patch_draft_grade_with_access_token(
            'write-access', $teacherId, 'course-1', 'work-1', 'student-1', 'submission-1', $stale
        ));
        $this->assertSame($before, count($requests));

        $this->assertWPError(ll_tools_google_classroom_patch_draft_grade_with_access_token(
            'write-access', $teacherId, 'course-1', 'work-1', 'student-other', 'submission-1', $grade
        ));
        $this->assertSame($before + 2, count($requests));
        $this->assertSame('GET', $requests[array_key_last($requests)]['args']['method']);

        $patchResponseUser = 'student-other';
        $this->assertWPError(ll_tools_google_classroom_patch_draft_grade_with_access_token(
            'write-access', $teacherId, 'course-1', 'work-1', 'student-1', 'submission-1', $grade
        ));
        $this->assertSame('PATCH', $requests[array_key_last($requests)]['args']['method']);
        $patchResponseUser = 'student-1';

        $patched = ll_tools_google_classroom_patch_draft_grade_with_access_token(
            'write-access', $teacherId, 'course-1', 'work-1', 'student-1', 'submission-1', $grade
        );
        $this->assertIsArray($patched);
        $this->assertSame('student-1', $patched['user_id']);
        $last = $requests[array_key_last($requests)];
        $this->assertSame('PATCH', $last['args']['method']);
        $this->assertSame(['draftGrade' => 16], json_decode($last['args']['body'], true));
        parse_str((string) wp_parse_url($last['url'], PHP_URL_QUERY), $patchQuery);
        $this->assertSame('draftGrade', $patchQuery['updateMask']);
        $lookupBeforePatch = $requests[count($requests) - 2];
        $this->assertSame('GET', $lookupBeforePatch['args']['method']);
        parse_str((string) wp_parse_url($lookupBeforePatch['url'], PHP_URL_QUERY), $prePatchLookupQuery);
        $this->assertSame('student-1', $prePatchLookupQuery['userId']);
    }

    public function test_admin_surface_has_capability_and_action_hooks_but_no_write_action(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);
        $this->assertFalse(ll_tools_google_classroom_current_user_can_connect());

        $teacherId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($teacherId);
        $this->assertTrue(ll_tools_google_classroom_current_user_can_connect());
        $this->assertNotFalse(has_action('admin_post_ll_tools_google_classroom_oauth_start', 'll_tools_google_classroom_oauth_start'));
        $this->assertNotFalse(has_action('admin_post_ll_tools_google_classroom_oauth_callback', 'll_tools_google_classroom_oauth_callback'));
        $this->assertNotFalse(has_action('admin_post_ll_tools_google_classroom_disconnect', 'll_tools_google_classroom_disconnect'));
        $this->assertFalse(has_action('admin_post_ll_tools_google_classroom_create_coursework'));
        $this->assertFalse(has_action('admin_post_ll_tools_google_classroom_patch_grade'));
    }
}
