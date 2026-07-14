<?php
declare(strict_types=1);

final class OfflineAppSyncTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
        if (function_exists('ll_tools_install_offline_app_session_schema')) {
            ll_tools_install_offline_app_session_schema();
        }
        global $wpdb;
        if (function_exists('ll_tools_offline_app_session_table') && ll_tools_offline_app_session_schema_ready()) {
            $wpdb->query('DELETE FROM ' . ll_tools_offline_app_session_table());
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (function_exists('ll_tools_offline_app_session_table') && ll_tools_offline_app_session_schema_ready()) {
            $wpdb->query('DELETE FROM ' . ll_tools_offline_app_session_table());
        }
        parent::tearDown();
    }

    public function test_offline_app_token_login_sync_dedupes_and_logout_revokes_token(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
            $username = 'offline_sync_' . strtolower(wp_generate_password(6, false, false));
            $email = $username . '@example.com';
            $password = 'Pass!' . wp_generate_password(12, false, false);
            $user_id = wp_create_user($username, $password, $email);
            $this->assertIsInt($user_id);

            $fixture = $this->createOfflineSyncFixture();
            $client_seen_at = '2026-02-03T04:05:06Z';
            $word_event_uuid = wp_generate_uuid4();
            $category_event_uuid = wp_generate_uuid4();
            $events = [
                [
                    'event_uuid' => $word_event_uuid,
                    'event_type' => 'word_exposure',
                    'mode' => 'practice',
                    'word_id' => $fixture['word_id'],
                    'category_id' => $fixture['category_id'],
                    'wordset_id' => $fixture['wordset_id'],
                    'client_created_at' => $client_seen_at,
                    'payload' => [],
                ],
                [
                    'event_uuid' => $category_event_uuid,
                    'event_type' => 'category_study',
                    'mode' => 'practice',
                    'category_id' => $fixture['category_id'],
                    'wordset_id' => $fixture['wordset_id'],
                    'payload' => [
                        'units' => 2,
                    ],
                ],
            ];
            $state_payload = [
                'wordset_id' => $fixture['wordset_id'],
                'category_ids' => [$fixture['category_id']],
                'starred_word_ids' => [$fixture['word_id']],
                'star_mode' => 'only',
                'fast_transitions' => true,
            ];

            wp_set_current_user(0);

            $_POST = [
                'identifier' => $username,
                'password' => $password,
                'device_id' => 'device-a',
                'profile_id' => 'profile-a',
            ];
            $_REQUEST = $_POST;

            try {
                $login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($login['success'] ?? false));
            $login_data = is_array($login['data'] ?? null) ? $login['data'] : [];
            $token = (string) ($login_data['auth_token'] ?? '');
            $this->assertNotSame('', $token);
            $this->assertSame($user_id, (int) (($login_data['user'] ?? [])['id'] ?? 0));

            $sessions = ll_tools_offline_app_sessions_for_user($user_id);
            $this->assertIsArray($sessions);
            $this->assertNotEmpty($sessions);
            $this->assertSame('', get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));

            $_POST = [
                'auth_token' => $token,
                'state' => wp_json_encode($state_payload),
                'events' => wp_json_encode($events),
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $sync = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($sync['success'] ?? false));
            $sync_data = is_array($sync['data'] ?? null) ? $sync['data'] : [];
            $this->assertSame(2, (int) (($sync_data['stats'] ?? [])['processed'] ?? 0));
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) ($sync_data['scope_word_ids'] ?? []))));
            $effective_category_id = $fixture['effective_category_id'];
            $synced_category_ids = array_values(array_map('intval', (array) (($sync_data['state'] ?? [])['category_ids'] ?? [])));
            $this->assertContains($effective_category_id, $synced_category_ids);
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) (($sync_data['state'] ?? [])['starred_word_ids'] ?? []))));
            $this->assertSame('normal', (string) (($sync_data['state'] ?? [])['star_mode'] ?? ''));
            $this->assertTrue((bool) (($sync_data['state'] ?? [])['fast_transitions'] ?? false));
            $progress_words = (array) ($sync_data['progress_words'] ?? []);
            $this->assertTrue(
                array_key_exists($fixture['word_id'], $progress_words) || array_key_exists((string) $fixture['word_id'], $progress_words)
            );

            $progress_rows = ll_tools_get_user_word_progress_rows($user_id, [$fixture['word_id']]);
            $this->assertArrayHasKey($fixture['word_id'], $progress_rows);
            $row = $progress_rows[$fixture['word_id']];
            $this->assertSame(1, (int) ($row['total_coverage'] ?? 0));
            $this->assertSame('2026-02-03 04:05:06', (string) ($row['last_seen_at'] ?? ''));

            $saved_state = ll_tools_get_user_study_state($user_id);
            $this->assertNotEmpty(array_values(array_map('intval', (array) ($saved_state['category_ids'] ?? []))));
            $this->assertSame([$fixture['word_id']], array_values(array_map('intval', (array) ($saved_state['starred_word_ids'] ?? []))));
            $this->assertSame('normal', (string) ($saved_state['star_mode'] ?? ''));
            $this->assertTrue((bool) ($saved_state['fast_transitions'] ?? false));

            $category_progress = (array) ($sync_data['category_progress'] ?? []);
            $this->assertTrue(
                array_key_exists($effective_category_id, $category_progress) || array_key_exists((string) $effective_category_id, $category_progress)
            );
            $this->assertArrayHasKey('recommendation_queue', $sync_data);
            $this->assertArrayHasKey('next_activity', $sync_data);
            $this->assertArrayHasKey('server_time', $sync_data);

            $_POST = [
                'auth_token' => $token,
                'state' => wp_json_encode($state_payload),
                'events' => wp_json_encode($events),
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $repeat_sync = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($repeat_sync['success'] ?? false));
            $progress_rows_after_repeat = ll_tools_get_user_word_progress_rows($user_id, [$fixture['word_id']]);
            $this->assertSame(1, (int) (($progress_rows_after_repeat[$fixture['word_id']] ?? [])['total_coverage'] ?? 0));

            $_POST = [
                'auth_token' => $token,
            ];
            $_REQUEST = $_POST;

            try {
                $logout = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_logout_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($logout['success'] ?? false));

            $_POST = [
                'auth_token' => $token,
                'events' => '[]',
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ];
            $_REQUEST = $_POST;

            try {
                $rejected = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_sync_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($rejected['success'] ?? true));
            $this->assertSame('Sign in required.', (string) (($rejected['data'] ?? [])['message'] ?? ''));
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_offline_app_session_table_enforces_eight_session_limit_without_rewriting_user_meta(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $tokens = [];
        for ($index = 1; $index <= 10; $index++) {
            $session = ll_tools_offline_app_create_session($user_id, [
                'device_id' => 'device-' . $index,
                'profile_id' => 'profile-' . $index,
            ]);
            $tokens[] = (string) ($session['token'] ?? '');
            usleep(1000);
        }

        $sessions = ll_tools_offline_app_sessions_for_user($user_id);
        $this->assertCount(LL_TOOLS_OFFLINE_APP_MAX_SESSIONS, $sessions);
        $this->assertNull(ll_tools_offline_app_authenticate_token($tokens[0], false));
        $this->assertNull(ll_tools_offline_app_authenticate_token($tokens[1], false));
        $this->assertIsArray(ll_tools_offline_app_authenticate_token($tokens[9], false));
        $this->assertSame('', get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $this->assertSame(
            LL_TOOLS_OFFLINE_APP_MAX_SESSIONS,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d',
                $user_id
            ))
        );
    }

    public function test_offline_app_legacy_sessions_import_once_with_verified_readback(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $session_key = 'legacysessionkey';
        $secret = 'LegacySecret123456789';
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, [
            $session_key => [
                'secret_hash' => wp_hash_password($secret),
                'created_at' => '2026-07-14 08:00:00',
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => '2026-07-14 08:00:00',
                'device_id' => 'legacy-device',
                'profile_id' => 'legacy-profile',
            ],
        ]);

        $this->assertTrue(ll_tools_offline_app_import_legacy_sessions_for_user($user_id));
        $this->assertSame('', get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $auth = ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, $session_key, $secret),
            false
        );
        $this->assertIsArray($auth);
        $this->assertSame('legacy-device', (string) (($auth['session'] ?? [])['device_id'] ?? ''));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s',
            $user_id,
            $session_key
        )));
    }

    public function test_offline_app_legacy_import_keeps_only_eight_most_recent_combined_sessions(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $table = ll_tools_offline_app_session_table();
        $expires_at = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);

        for ($index = 1; $index <= LL_TOOLS_OFFLINE_APP_MAX_SESSIONS; $index++) {
            $timestamp = gmdate('Y-m-d H:i:s', time() - $index * MINUTE_IN_SECONDS);
            $inserted = $wpdb->insert($table, [
                'user_id' => $user_id,
                'session_key' => 'tablesession' . $index,
                'secret_hash' => wp_hash_password('TableSecret' . $index),
                'created_at' => $timestamp,
                'expires_at' => $expires_at,
                'last_used_at' => $timestamp,
                'device_id' => '',
                'profile_id' => '',
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            $this->assertSame(1, $inserted);
        }

        $legacy_sessions = [];
        for ($index = 1; $index <= LL_TOOLS_OFFLINE_APP_MAX_SESSIONS; $index++) {
            $timestamp = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS - $index * MINUTE_IN_SECONDS);
            $legacy_sessions['legacysession' . $index] = [
                'secret_hash' => wp_hash_password('LegacySecret' . $index),
                'created_at' => $timestamp,
                'expires_at' => $expires_at,
                'last_used_at' => $timestamp,
            ];
        }
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $legacy_sessions);

        $this->assertTrue(ll_tools_offline_app_import_legacy_sessions_for_user($user_id));
        $this->assertSame('', get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $this->assertSame(
            LL_TOOLS_OFFLINE_APP_MAX_SESSIONS,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id))
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND session_key LIKE %s",
                $user_id,
                'legacysession%'
            ))
        );
    }

    public function test_offline_app_legacy_import_keeps_meta_when_existing_row_hash_conflicts(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $session_key = 'conflictsession';
        $legacy_secret = 'LegacySecret123';
        $table_secret = 'TableSecret456';
        $now = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, [
            $session_key => [
                'secret_hash' => wp_hash_password($legacy_secret),
                'created_at' => $now,
                'expires_at' => $expires_at,
                'last_used_at' => $now,
            ],
            'otherlegacysession' => [
                'secret_hash' => wp_hash_password('OtherLegacySecret789'),
                'created_at' => $now,
                'expires_at' => $expires_at,
                'last_used_at' => $now,
            ],
        ]);
        $inserted = $wpdb->insert(ll_tools_offline_app_session_table(), [
            'user_id' => $user_id,
            'session_key' => $session_key,
            'secret_hash' => wp_hash_password($table_secret),
            'created_at' => $now,
            'expires_at' => $expires_at,
            'last_used_at' => $now,
            'device_id' => '',
            'profile_id' => '',
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        $this->assertSame(1, $inserted);

        $this->assertFalse(ll_tools_offline_app_import_legacy_sessions_for_user($user_id));
        $this->assertIsArray(get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $this->assertNull(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, $session_key, $legacy_secret),
            false
        ));
        $this->assertIsArray(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, $session_key, $table_secret),
            false
        ));
        $this->assertNull(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, 'otherlegacysession', 'OtherLegacySecret789'),
            false
        ));
        $this->assertTrue(ll_tools_offline_app_revoke_session($user_id, $session_key));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s',
            $user_id,
            $session_key
        )));
        $preserved_legacy_sessions = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
        $this->assertIsArray($preserved_legacy_sessions);
        $this->assertArrayNotHasKey($session_key, $preserved_legacy_sessions);
        $this->assertArrayHasKey('otherlegacysession', $preserved_legacy_sessions);
        $this->assertNull(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, $session_key, $legacy_secret),
            false
        ));
        $this->assertNull(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, $session_key, $table_secret),
            false
        ));
        $this->assertIsArray(ll_tools_offline_app_authenticate_token(
            sprintf('llapp.%d.%s.%s', $user_id, 'otherlegacysession', 'OtherLegacySecret789'),
            false
        ));
        $this->assertTrue(ll_tools_offline_app_revoke_session($user_id, 'otherlegacysession'));
        $this->assertSame('', get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
    }

    public function test_offline_app_schema_readiness_requires_all_runtime_indexes(): void
    {
        global $wpdb;
        $table = ll_tools_offline_app_session_table();

        $this->assertTrue(ll_tools_offline_app_session_schema_ready(true));
        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} DROP INDEX user_activity"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $this->assertTrue(ll_tools_install_offline_app_session_schema());
        }
        $this->assertTrue(ll_tools_offline_app_session_schema_ready(true));
    }

    public function test_offline_app_schema_readiness_rejects_unique_prefix_column_and_engine_drift(): void
    {
        global $wpdb;
        $table = ll_tools_offline_app_session_table();

        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} DROP INDEX user_activity, ADD UNIQUE KEY user_activity (user_id, last_used_at)"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX user_activity, ADD KEY user_activity (user_id, last_used_at)");
        }

        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} DROP INDEX user_session, ADD KEY user_session (user_id, session_key)"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX user_session, ADD UNIQUE KEY user_session (user_id, session_key)");
        }

        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} DROP INDEX user_session, ADD UNIQUE KEY user_session (user_id, session_key(12))"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX user_session, ADD UNIQUE KEY user_session (user_id, session_key)");
        }

        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} MODIFY session_key varchar(16) NOT NULL"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $wpdb->query("ALTER TABLE {$table} MODIFY session_key varchar(32) NOT NULL");
        }

        $this->assertNotFalse($wpdb->query("ALTER TABLE {$table} ENGINE=MyISAM"));
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            $this->assertTrue(ll_tools_install_offline_app_session_schema());
        }
        $this->assertTrue(ll_tools_offline_app_session_schema_ready(true));
    }

    public function test_offline_app_session_cap_count_failures_roll_back_creation_and_legacy_import(): void
    {
        global $wpdb;
        $table = ll_tools_offline_app_session_table();
        $user_id = self::factory()->user->create();
        $break_count = static function (string $sql) use ($table): string {
            if (strpos($sql, "SELECT COUNT(*) FROM {$table} WHERE user_id =") !== false && strpos($sql, 'session_key') === false) {
                return "SELECT COUNT(*) FROM {$table}_missing";
            }
            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_count);
        try {
            $this->assertSame([], ll_tools_offline_app_create_session($user_id));
        } finally {
            remove_filter('query', $break_count);
            $wpdb->suppress_errors($previous_suppress);
        }
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id)));

        $legacy_key = 'countfailurelegacy';
        $legacy = [
            $legacy_key => [
                'secret_hash' => wp_hash_password('CountFailureSecret'),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $legacy);
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_count);
        try {
            $this->assertFalse(ll_tools_offline_app_import_legacy_sessions_for_user($user_id));
        } finally {
            remove_filter('query', $break_count);
            $wpdb->suppress_errors($previous_suppress);
        }
        $this->assertSame($legacy, get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id)));
    }

    public function test_offline_app_auth_touch_rejects_a_replaced_session_row(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $created = ll_tools_offline_app_create_session($user_id);
        $token = (string) ($created['token'] ?? '');
        $session_key = (string) ($created['session_key'] ?? '');
        $table = ll_tools_offline_app_session_table();
        $replacement_hash = wp_hash_password('ReplacementSecret123');
        $interleave = null;
        $interleave = static function (string $sql) use (&$interleave, $wpdb, $table, $user_id, $session_key, $replacement_hash): string {
            if (strpos($sql, "UPDATE `{$table}` SET") === false && strpos($sql, "UPDATE {$table} SET") === false) {
                return $sql;
            }
            if (strpos($sql, '`last_used_at`') === false && strpos($sql, 'last_used_at') === false) {
                return $sql;
            }
            remove_filter('query', $interleave);
            $wpdb->delete($table, ['user_id' => $user_id, 'session_key' => $session_key], ['%d', '%s']);
            $now = gmdate('Y-m-d H:i:s');
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'session_key' => $session_key,
                'secret_hash' => $replacement_hash,
                'created_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => $now,
                'device_id' => '',
                'profile_id' => '',
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            add_filter('query', $interleave);
            return $sql;
        };
        add_filter('query', $interleave);
        try {
            $this->assertNull(ll_tools_offline_app_authenticate_token($token, true));
        } finally {
            remove_filter('query', $interleave);
        }
        $this->assertSame($replacement_hash, (string) $wpdb->get_var($wpdb->prepare(
            "SELECT secret_hash FROM {$table} WHERE user_id = %d AND session_key = %s",
            $user_id,
            $session_key
        )));
    }

    public function test_offline_app_hash_fenced_revoke_preserves_a_replacement_session(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $created = ll_tools_offline_app_create_session($user_id);
        $auth = ll_tools_offline_app_authenticate_token((string) ($created['token'] ?? ''), false);
        $this->assertIsArray($auth);
        $session_key = (string) ($auth['session_key'] ?? '');
        $old_hash = (string) (($auth['session'] ?? [])['secret_hash'] ?? '');
        $replacement_hash = wp_hash_password('ReplacementRevokeSecret');
        $table = ll_tools_offline_app_session_table();
        $wpdb->delete($table, ['user_id' => $user_id, 'session_key' => $session_key], ['%d', '%s']);
        $now = gmdate('Y-m-d H:i:s');
        $this->assertSame(1, $wpdb->insert($table, [
            'user_id' => $user_id,
            'session_key' => $session_key,
            'secret_hash' => $replacement_hash,
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'last_used_at' => $now,
            'device_id' => '',
            'profile_id' => '',
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']));

        $this->assertTrue(ll_tools_offline_app_revoke_session($user_id, $session_key, $old_hash));
        $this->assertSame($replacement_hash, (string) $wpdb->get_var($wpdb->prepare(
            "SELECT secret_hash FROM {$table} WHERE user_id = %d AND session_key = %s",
            $user_id,
            $session_key
        )));
    }

    public function test_offline_app_legacy_import_cas_preserves_a_concurrent_legacy_writer(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create();
        $now = gmdate('Y-m-d H:i:s');
        $raw = [
            'snapshotlegacy' => [
                'secret_hash' => wp_hash_password('SnapshotSecret'),
                'created_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => $now,
            ],
        ];
        $concurrent = $raw;
        $concurrent['concurrentlegacy'] = [
            'secret_hash' => wp_hash_password('ConcurrentSecret'),
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'last_used_at' => $now,
        ];
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $raw);

        $interleave = null;
        $interleave = static function ($check, $object_id, $meta_key) use (&$interleave, $user_id, $concurrent) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_OFFLINE_APP_SESSION_META) {
                return $check;
            }
            remove_filter('delete_user_metadata', $interleave, 10);
            update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $concurrent);
            add_filter('delete_user_metadata', $interleave, 10, 3);
            return $check;
        };
        add_filter('delete_user_metadata', $interleave, 10, 3);
        try {
            $this->assertTrue(ll_tools_offline_app_import_legacy_sessions_for_user($user_id));
        } finally {
            remove_filter('delete_user_metadata', $interleave, 10);
        }
        $this->assertSame($concurrent, get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s',
            $user_id,
            'snapshotlegacy'
        )));
    }

    public function test_offline_app_legacy_revoke_cas_preserves_a_concurrent_session(): void
    {
        $user_id = self::factory()->user->create();
        $now = gmdate('Y-m-d H:i:s');
        $target_hash = wp_hash_password('TargetSecret');
        $raw = [
            'targetlegacy' => [
                'secret_hash' => $target_hash,
                'created_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => $now,
            ],
        ];
        $concurrent = $raw;
        $concurrent['concurrentlegacy'] = [
            'secret_hash' => wp_hash_password('ConcurrentSecret'),
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'last_used_at' => $now,
        ];
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $raw);

        $interleave = null;
        $interleave = static function ($check, $object_id, $meta_key) use (&$interleave, $user_id, $concurrent) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_OFFLINE_APP_SESSION_META) {
                return $check;
            }
            remove_filter('delete_user_metadata', $interleave, 10);
            update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $concurrent);
            add_filter('delete_user_metadata', $interleave, 10, 3);
            return $check;
        };
        add_filter('delete_user_metadata', $interleave, 10, 3);
        try {
            $this->assertTrue(ll_tools_offline_app_remove_legacy_session_key($user_id, 'targetlegacy', $target_hash));
        } finally {
            remove_filter('delete_user_metadata', $interleave, 10);
        }

        $persisted = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
        $this->assertIsArray($persisted);
        $this->assertArrayNotHasKey('targetlegacy', $persisted);
        $this->assertArrayHasKey('concurrentlegacy', $persisted);

        $replacement = $raw;
        $replacement['targetlegacy']['secret_hash'] = wp_hash_password('ReplacementSecret');
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $raw);
        $replace_interleave = null;
        $replace_interleave = static function ($check, $object_id, $meta_key) use (&$replace_interleave, $user_id, $replacement) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_OFFLINE_APP_SESSION_META) {
                return $check;
            }
            remove_filter('delete_user_metadata', $replace_interleave, 10);
            update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $replacement);
            add_filter('delete_user_metadata', $replace_interleave, 10, 3);
            return $check;
        };
        add_filter('delete_user_metadata', $replace_interleave, 10, 3);
        try {
            $this->assertTrue(ll_tools_offline_app_remove_legacy_session_key($user_id, 'targetlegacy', $target_hash));
        } finally {
            remove_filter('delete_user_metadata', $replace_interleave, 10);
        }
        $this->assertSame($replacement, get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
    }

    public function test_offline_app_schema_unavailable_revoke_preserves_a_same_key_replacement(): void
    {
        $user_id = self::factory()->user->create();
        $now = gmdate('Y-m-d H:i:s');
        $old_hash = wp_hash_password('LegacyOldSecret');
        $replacement_hash = wp_hash_password('LegacyReplacementSecret');
        $raw = [
            'legacyfallback' => [
                'secret_hash' => $old_hash,
                'created_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
                'last_used_at' => $now,
            ],
        ];
        $replacement = $raw;
        $replacement['legacyfallback']['secret_hash'] = $replacement_hash;
        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $raw);

        $hide_schema = static function (string $sql): string {
            return stripos(ltrim($sql), 'SHOW TABLES LIKE') === 0 ? "SELECT ''" : $sql;
        };
        add_filter('query', $hide_schema);
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            remove_filter('query', $hide_schema);
        }

        $interleave = null;
        $interleave = static function ($check, $object_id, $meta_key) use (&$interleave, $user_id, $replacement) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_OFFLINE_APP_SESSION_META) {
                return $check;
            }
            remove_filter('delete_user_metadata', $interleave, 10);
            update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $replacement);
            add_filter('delete_user_metadata', $interleave, 10, 3);
            return $check;
        };
        add_filter('delete_user_metadata', $interleave, 10, 3);
        try {
            $this->assertTrue(ll_tools_offline_app_revoke_session(
                $user_id,
                'legacyfallback',
                $old_hash
            ));
        } finally {
            remove_filter('delete_user_metadata', $interleave, 10);
            $this->assertTrue(ll_tools_offline_app_session_schema_ready(true));
        }

        $this->assertSame($replacement, get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true));
    }

    public function test_offline_app_logout_authentication_can_skip_sliding_expiry(): void
    {
        $user_id = self::factory()->user->create();
        $created = ll_tools_offline_app_create_session($user_id);
        $previous_post = $_POST;
        $_POST = ['auth_token' => (string) ($created['token'] ?? '')];
        $touch_queries = [];
        $capture = static function (string $sql) use (&$touch_queries): string {
            if (strpos($sql, 'last_used_at') !== false && strpos($sql, 'UPDATE') !== false) {
                $touch_queries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $auth = ll_tools_offline_app_require_authenticated_user(false);
        } finally {
            remove_filter('query', $capture);
            $_POST = $previous_post;
        }
        $this->assertIsArray($auth);
        $this->assertSame([], $touch_queries);
    }

    public function test_offline_app_login_rate_limit_blocks_after_configured_attempts(): void
    {
        $ip = '203.0.113.44';
        $limit_filter = static function (): int {
            return 2;
        };
        $window_filter = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };

        add_filter('ll_tools_offline_app_login_ip_attempt_limit', $limit_filter);
        add_filter('ll_tools_offline_app_login_ip_attempt_window', $window_filter);

        $username = 'offline_rate_' . strtolower(wp_generate_password(6, false, false));
        $email = $username . '@example.com';
        $password = 'Pass!' . wp_generate_password(12, false, false);
        $user_id = wp_create_user($username, $password, $email);
        $this->assertIsInt($user_id);

        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        try {
            ll_tools_offline_app_reset_login_attempts($ip);

            $_POST = [
                'identifier' => $username,
                'password' => $password,
                'device_id' => 'rate-device',
                'profile_id' => 'rate-profile',
            ];
            $_REQUEST = $_POST;

            try {
                $first_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertTrue((bool) ($first_login['success'] ?? false));
            $first_login_data = is_array($first_login['data'] ?? null) ? $first_login['data'] : [];
            $this->assertNotSame('', (string) ($first_login_data['auth_token'] ?? ''));
            $this->assertSame($user_id, (int) (($first_login_data['user'] ?? [])['id'] ?? 0));

            $_POST = [
                'identifier' => $username,
                'password' => 'wrong-password',
            ];
            $_REQUEST = $_POST;

            try {
                $second_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($second_login['success'] ?? true));
            $this->assertSame('Invalid login.', (string) (($second_login['data'] ?? [])['message'] ?? ''));
            $limited_status = ll_tools_offline_app_get_login_rate_limit_status($ip);
            $this->assertTrue((bool) ($limited_status['limited'] ?? false));
            $this->assertSame(2, (int) ($limited_status['attempts'] ?? 0));
            $this->assertSame(2, (int) ($limited_status['limit'] ?? 0));

            $_POST = [
                'identifier' => $username,
                'password' => $password,
            ];
            $_REQUEST = $_POST;

            try {
                $third_login = $this->run_json_endpoint(static function (): void {
                    ll_tools_offline_app_login_ajax();
                });
            } finally {
                $_POST = [];
                $_REQUEST = [];
            }

            $this->assertFalse((bool) ($third_login['success'] ?? true));
            $this->assertSame(
                'Too many login attempts. Please try again in a few minutes.',
                (string) (($third_login['data'] ?? [])['message'] ?? '')
            );
        } finally {
            ll_tools_offline_app_reset_login_attempts($ip);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
            remove_filter('ll_tools_offline_app_login_ip_attempt_limit', $limit_filter);
            remove_filter('ll_tools_offline_app_login_ip_attempt_window', $window_filter);
        }
    }

    public function test_offline_app_sync_request_throttle_limits_repeated_valid_token_responses(): void
    {
        $ip = '198.51.100.20';
        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        $config_filter = static function (array $config): array {
            $config['request_limit'] = 1;
            $config['resource_unit_limit'] = 100;
            $config['ip_request_limit'] = 0;
            $config['ip_resource_unit_limit'] = 0;
            return $config;
        };
        add_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);

        $token = '';

        try {
            $user_id = self::factory()->user->create();
            $this->assertIsInt($user_id);
            $session = ll_tools_offline_app_create_session($user_id);
            $token = (string) ($session['token'] ?? '');
            $this->assertNotSame('', $token);
            $fixture = $this->createOfflineSyncFixture();

            ll_tools_offline_app_reset_sync_throttle($token, $ip);

            $first = $this->runOfflineSyncRequest([
                'auth_token' => $token,
                'events' => '[]',
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ]);
            $this->assertTrue((bool) ($first['success'] ?? false));

            $second = $this->runOfflineSyncRequest([
                'auth_token' => $token,
                'events' => '[]',
                'word_ids' => wp_json_encode([$fixture['word_id']]),
            ]);
            $this->assertFalse((bool) ($second['success'] ?? true));
            $this->assertSame('rate_limited', (string) (($second['data'] ?? [])['code'] ?? ''));
            $this->assertSame('token', (string) (($second['data'] ?? [])['scope'] ?? ''));
            $this->assertSame('requests', (string) (($second['data'] ?? [])['limit_type'] ?? ''));
        } finally {
            ll_tools_offline_app_reset_sync_throttle($token, $ip);
            remove_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
        }
    }

    public function test_offline_app_sync_resource_throttle_limits_large_response_requests(): void
    {
        $ip = '198.51.100.21';
        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        $config_filter = static function (array $config): array {
            $config['request_limit'] = 10;
            $config['resource_unit_limit'] = 3;
            $config['ip_request_limit'] = 0;
            $config['ip_resource_unit_limit'] = 0;
            $config['word_ids_per_unit'] = 1;
            return $config;
        };
        add_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);

        $token = '';

        try {
            $user_id = self::factory()->user->create();
            $this->assertIsInt($user_id);
            $session = ll_tools_offline_app_create_session($user_id);
            $token = (string) ($session['token'] ?? '');
            $this->assertNotSame('', $token);
            $fixture = $this->createOfflineSyncFixture();
            $second_word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Offline Sync Resource Word',
            ]);
            wp_set_post_terms($second_word_id, [$fixture['wordset_id']], 'wordset', false);
            wp_set_post_terms($second_word_id, [$fixture['category_id']], 'word-category', false);
            update_post_meta($second_word_id, 'word_translation', 'Offline Sync Resource Translation');

            ll_tools_offline_app_reset_sync_throttle($token, $ip);

            $post = [
                'auth_token' => $token,
                'events' => '[]',
                'word_ids' => wp_json_encode([$fixture['word_id'], $second_word_id]),
            ];
            $first = $this->runOfflineSyncRequest($post);
            $this->assertTrue((bool) ($first['success'] ?? false));

            $second = $this->runOfflineSyncRequest($post);
            $this->assertFalse((bool) ($second['success'] ?? true));
            $this->assertSame('rate_limited', (string) (($second['data'] ?? [])['code'] ?? ''));
            $this->assertSame('token', (string) (($second['data'] ?? [])['scope'] ?? ''));
            $this->assertSame('resource_units', (string) (($second['data'] ?? [])['limit_type'] ?? ''));
        } finally {
            ll_tools_offline_app_reset_sync_throttle($token, $ip);
            remove_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
        }
    }

    public function test_offline_app_sync_ip_throttle_limits_repeated_bad_tokens(): void
    {
        $ip = '198.51.100.22';
        $previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        $config_filter = static function (array $config): array {
            $config['request_limit'] = 0;
            $config['resource_unit_limit'] = 0;
            $config['ip_request_limit'] = 1;
            $config['ip_resource_unit_limit'] = 100;
            return $config;
        };
        add_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);

        $bad_token_one = '';
        $bad_token_two = '';

        try {
            $user_id = self::factory()->user->create();
            $this->assertIsInt($user_id);
            $session = ll_tools_offline_app_create_session($user_id);
            $token = (string) ($session['token'] ?? '');
            $this->assertNotSame('', $token);
            $token_parts = explode('.', $token);
            $this->assertCount(4, $token_parts);
            $bad_token_one = sprintf('llapp.%d.%s.badsecret1', $user_id, $token_parts[2]);
            $bad_token_two = sprintf('llapp.%d.%s.badsecret2', $user_id, $token_parts[2]);

            ll_tools_offline_app_reset_sync_throttle($bad_token_one, $ip);
            ll_tools_offline_app_reset_sync_throttle($bad_token_two, $ip);

            $first = $this->runOfflineSyncRequest([
                'auth_token' => $bad_token_one,
                'events' => '[]',
                'word_ids' => '[]',
            ]);
            $this->assertFalse((bool) ($first['success'] ?? true));
            $this->assertSame('Sign in required.', (string) (($first['data'] ?? [])['message'] ?? ''));

            $second = $this->runOfflineSyncRequest([
                'auth_token' => $bad_token_two,
                'events' => '[]',
                'word_ids' => '[]',
            ]);
            $this->assertFalse((bool) ($second['success'] ?? true));
            $this->assertSame('rate_limited', (string) (($second['data'] ?? [])['code'] ?? ''));
            $this->assertSame('ip', (string) (($second['data'] ?? [])['scope'] ?? ''));
            $this->assertSame('requests', (string) (($second['data'] ?? [])['limit_type'] ?? ''));
        } finally {
            ll_tools_offline_app_reset_sync_throttle($bad_token_one, $ip);
            ll_tools_offline_app_reset_sync_throttle($bad_token_two, $ip);
            remove_filter('ll_tools_offline_app_sync_throttle_config', $config_filter);
            if ($previous_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
            }
        }
    }

    public function test_offline_app_sync_rejects_oversized_payload_before_authentication(): void
    {
        $max_payload_filter = static function (): int {
            return 64;
        };
        add_filter('ll_tools_offline_app_sync_payload_max_bytes', $max_payload_filter);

        try {
            $response = $this->runOfflineSyncRequest([
                'auth_token' => 'not-a-valid-token',
                'events' => str_repeat('x', 128),
                'word_ids' => '[]',
            ]);

            $this->assertFalse((bool) ($response['success'] ?? true));
            $this->assertSame('payload_too_large', (string) (($response['data'] ?? [])['code'] ?? ''));
            $this->assertNotSame('Sign in required.', (string) (($response['data'] ?? [])['message'] ?? ''));
        } finally {
            remove_filter('ll_tools_offline_app_sync_payload_max_bytes', $max_payload_filter);
        }
    }

    private function createOfflineSyncFixture(): array
    {
        $wordset = wp_insert_term('Offline Sync Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Offline Sync Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $effective_category_id = $category_id;
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved_category_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved_category_id > 0) {
                $effective_category_id = $resolved_category_id;
            }
        }
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Offline Sync Word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', 'Offline Sync Translation');

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'effective_category_id' => $effective_category_id,
            'word_id' => $word_id,
        ];
    }

    private function runOfflineSyncRequest(array $post): array
    {
        $_POST = $post;
        $_REQUEST = $post;

        try {
            return $this->run_json_endpoint(static function (): void {
                ll_tools_offline_app_sync_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }
    }

    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);
        $headers_before = function_exists('headers_list') ? headers_list() : [];

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        $status = 0;
        if (function_exists('headers_list')) {
            $headers_after = headers_list();
            $new_headers = array_values(array_diff($headers_after, $headers_before));
            foreach ($new_headers as $header_line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header_line, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
                if (preg_match('/^Status:\s+(\d{3})\b/', (string) $header_line, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }
        if ($status === 0) {
            $status = (int) http_response_code();
        }
        $decoded['status'] = $status;
        return $decoded;
    }
}
