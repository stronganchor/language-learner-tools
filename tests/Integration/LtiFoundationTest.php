<?php
declare(strict_types=1);

use LLTools\Vendor\Firebase\JWT\JWT;
use LLTools\Vendor\Firebase\JWT\Key;

final class LtiFoundationTest extends LL_Tools_TestCase {
    private array $platform = [];
    private array $requests = [];
    private $httpFilter;
    private $keyFilter;
    private string $originalHome;
    private string $originalSite;
    private array $originalCookies;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if (!defined('LL_TOOLS_LTI_PRIVATE_KEY')) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            define('LL_TOOLS_LTI_PRIVATE_KEY', $pem);
            define('LL_TOOLS_LTI_KEY_ID', 'integration-test-key');
        }
    }

    protected function setUp(): void {
        parent::setUp();
        $this->originalHome = (string) get_option('home');
        $this->originalSite = (string) get_option('siteurl');
        $this->originalCookies = $_COOKIE;
        update_option('home', 'https://wordboat.example.org');
        update_option('siteurl', 'https://wordboat.example.org');
        $_COOKIE['__Host-ll-tools-lti'] = str_repeat('a', 64);
        $this->keyFilter = static fn(): string => str_repeat('test-encryption-', 2);
        add_filter('ll_tools_lms_credential_master_key', $this->keyFilter);
        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_grade_delivery_schema());
        ll_tools_lti_register_grade_adapter();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(LL_TOOLS_LTI_PLATFORMS_OPTION);
        $this->platform = ll_tools_lti_register_platform($this->config());
        $this->assertIsArray($this->platform);
        $this->requests = [];
        delete_transient('ll_tools_lti_jwks_' . hash('sha256', $this->platform['jwks_url']));
        $this->httpFilter = function ($pre, array $args, string $url) {
            $this->requests[] = ['url' => $url, 'args' => $args];
            if ($url === $this->platform['jwks_url']) {
                $material = ll_tools_lti_signing_material();
                return $this->response(['keys' => [$material['jwk']]]);
            }
            if ($url === $this->platform['token_url']) {
                return $this->response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer', 'scope' => LL_TOOLS_LTI_SCORE_SCOPE]);
            }
            return $this->response([], 204);
        };
        add_filter('pre_http_request', $this->httpFilter, 10, 3);
    }

    protected function tearDown(): void {
        global $wpdb;
        remove_filter('pre_http_request', $this->httpFilter, 10);
        remove_filter('ll_tools_lms_credential_master_key', $this->keyFilter);
        update_option('home', $this->originalHome);
        update_option('siteurl', $this->originalSite);
        $_COOKIE = $this->originalCookies;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('ll_tools_lti_') . '%'));
        foreach ((array) $names as $name) { delete_option($name); }
        delete_transient('ll_tools_lti_jwks_' . hash('sha256', $this->platform['jwks_url']));
        wp_clear_scheduled_hook('ll_tools_lti_cleanup_tickets');
        parent::tearDown();
    }

    public function test_registration_requires_admin_and_fixed_https_origins(): void {
        $this->assertSame($this->platform, ll_tools_lti_get_platform('moodle-test'));
        foreach (['http://moodle.example.org/token', 'https://foreign.example.org/token', 'https://127.0.0.1/token', 'https://user:password@moodle.example.org/token', 'https://moodle.example.org:8443/token', 'https://moodle.example.org/token#fragment'] as $endpoint) {
            $bad = $this->config();
            $bad['token_url'] = $endpoint;
            $this->assertWPError(ll_tools_lti_register_platform($bad), $endpoint);
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame('lti_registration_forbidden', ll_tools_lti_register_platform($this->config())->get_error_code());
        $this->assertCount(0, $this->requests);
    }

    public function test_one_use_records_are_encrypted_context_bound_and_atomic(): void {
        global $wpdb;
        $ticket = ll_tools_lti_ticket_put('account', ['subject' => 'private-moodle-student']);
        $this->assertIsString($ticket);
        $name = ll_tools_lti_ticket_name('account', $ticket);
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        $this->assertStringNotContainsString('private-moodle-student', $raw);
        $this->assertNull(ll_tools_lti_decrypt_record($raw, $name . '-different-context'));
        $this->assertSame(['subject' => 'private-moodle-student'], ll_tools_lti_ticket_consume('account', $ticket));
        $this->assertWPError(ll_tools_lti_ticket_consume('account', $ticket));
        $this->assertNull(ll_tools_lti_ticket_get('account', $ticket));
        $this->assertWPError(ll_tools_lti_ticket_consume('account', '../invalid'));
    }

    public function test_login_issues_exact_oidc_parameters_and_rejects_unknown_target(): void {
        $request = new WP_REST_Request('POST', '/ll-tools/v1/lti/login');
        $request->set_body_params(['iss' => $this->platform['issuer'], 'client_id' => $this->platform['client_id'], 'login_hint' => 'opaque-hint', 'lti_message_hint' => 'opaque-message', 'target_link_uri' => ll_tools_lti_tool_urls()['resource']]);
        $response = ll_tools_lti_rest_login($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(302, $response->get_status());
        parse_str((string) wp_parse_url($response->get_headers()['Location'], PHP_URL_QUERY), $params);
        $this->assertSame('id_token', $params['response_type']);
        $this->assertSame('form_post', $params['response_mode']);
        $this->assertSame('none', $params['prompt']);
        $this->assertSame($this->platform['client_id'], $params['client_id']);
        $this->assertSame(ll_tools_lti_tool_urls()['launch'], $params['redirect_uri']);
        $state = ll_tools_lti_ticket_get('state', $params['state']);
        $this->assertTrue(ll_tools_lti_browser_matches($state));
        $this->assertSame($params['nonce'], $state['nonce']);
        $request->set_param('target_link_uri', 'https://foreign.example.org');
        $this->assertWPError(ll_tools_lti_rest_login($request));
        $this->assertCount(0, $this->requests, 'Login redirects without fetching any user-selected URL.');
    }

    public function test_signed_launch_creates_browser_bound_ticket_and_replays_fail(): void {
        [$state, $payload] = $this->stateAndClaims();
        $request = $this->launchRequest($state, $payload);
        $response = ll_tools_lti_rest_launch($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(303, $response->get_status());
        $this->assertStringContainsString('no-store', $response->get_headers()['Cache-Control']);
        $this->assertSame('no-referrer', $response->get_headers()['Referrer-Policy']);
        parse_str((string) wp_parse_url($response->get_headers()['Location'], PHP_URL_QUERY), $params);
        $context = ll_tools_lti_consume_launch($params['ll_lti_launch']);
        $this->assertIsArray($context);
        $this->assertSame('opaque-student', $context['subject']);
        $this->assertSame('activity-opaque', $context['custom']['ll_activity']);
        $this->assertArrayNotHasKey('email', $context);
        $this->assertWPError(ll_tools_lti_consume_launch($params['ll_lti_launch']));
        $this->assertWPError(ll_tools_lti_rest_launch($request));
        $this->assertCount(1, $this->requests, 'Only the configured public signing key endpoint is fetched.');
        $args = $this->requests[0]['args'];
        $this->assertSame(0, $args['redirection']);
        $this->assertTrue($args['sslverify']);
        $this->assertTrue($args['reject_unsafe_urls']);
    }

    public function test_login_csrf_wrong_browser_cannot_use_valid_signed_launch(): void {
        [$state, $payload] = $this->stateAndClaims();
        $_COOKIE['__Host-ll-tools-lti'] = str_repeat('b', 64);
        $response = ll_tools_lti_rest_launch($this->launchRequest($state, $payload));
        $this->assertSame('lti_browser_binding_failed', $response->get_error_code());
        $this->assertCount(0, $this->requests);
        $_COOKIE['__Host-ll-tools-lti'] = str_repeat('a', 64);
        $this->assertWPError(ll_tools_lti_rest_launch($this->launchRequest($state, $payload)));
    }

    public function test_claim_scope_expiry_nonce_and_foreign_grade_endpoint_fail_closed(): void {
        [, $valid] = $this->stateAndClaims();
        $prefix = 'https://purl.imsglobal.org/spec/lti/claim/';
        $state = ['nonce' => 'fixture-nonce', 'target_uri' => ll_tools_lti_tool_urls()['resource']];
        $badClaims = [
            ['iss' => 'https://foreign.example.org'], ['aud' => ['wrong-client']], ['sub' => ''],
            ['nonce' => 'wrong-nonce'], ['exp' => time() - 1], ['iat' => time() - 600],
            [$prefix . 'deployment_id' => 'another-deployment'], [$prefix . 'target_link_uri' => 'https://foreign.example.org'],
            [$prefix . 'message_type' => 'LtiDeepLinkingRequest'], [$prefix . 'context' => ['id' => '']],
            ['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => ['scope' => [LL_TOOLS_LTI_SCORE_SCOPE], 'lineitem' => 'https://foreign.example.org/lineitem']],
        ];
        foreach ($badClaims as $change) { $this->assertWPError(ll_tools_lti_validate_claims(array_replace($valid, $change), $this->platform, $state)); }
        $valid['sub'] = 'student-without-email';
        $this->assertIsArray(ll_tools_lti_validate_claims($valid, $this->platform, $state));
    }

    public function test_hmac_algorithm_confusion_and_wrong_rsa_signature_are_rejected(): void {
        [$state, $payload] = $this->stateAndClaims();
        $request = new WP_REST_Request('POST');
        $request->set_body_params(['state' => $state, 'id_token' => JWT::encode($payload, str_repeat('hmac-secret-fixture-', 3), 'HS256', LL_TOOLS_LTI_KEY_ID)]);
        $this->assertSame('lti_invalid_signature', ll_tools_lti_rest_launch($request)->get_error_code());
        [$state, $payload] = $this->stateAndClaims();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $request->set_body_params(['state' => $state, 'id_token' => JWT::encode($payload, $pem, 'RS256', LL_TOOLS_LTI_KEY_ID)]);
        $this->assertSame('lti_invalid_signature', ll_tools_lti_rest_launch($request)->get_error_code());
    }

    public function test_durable_identity_reuses_subject_across_courses_and_never_merges_by_email(): void {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $otherUser = self::factory()->user->create(['role' => 'subscriber']);
        $context = $this->context();
        $identity = ll_tools_lti_create_identity($user, $context);
        $this->assertIsInt($identity);
        $context['context_id'] = 'another-course';
        $context['resource_link_id'] = 'another-resource';
        $this->assertSame($identity, ll_tools_lti_create_identity($user, $context));
        $this->assertWPError(ll_tools_lti_create_identity($otherUser, $context));
        $second = $this->config();
        $second['id'] = 'moodle-other-deployment';
        $second['deployment_id'] = 'deployment-two';
        $this->assertIsArray(ll_tools_lti_register_platform($second));
        $context['platform_id'] = $second['id'];
        $context['deployment_id'] = $second['deployment_id'];
        $this->assertSame($identity, ll_tools_lti_create_identity($user, $context));
        $context['issuer'] = 'https://unregistered.example.org';
        $this->assertWPError(ll_tools_lti_create_identity($user, $context));
    }

    public function test_user_mapping_write_failure_rolls_back_generic_identity_and_respects_erasure_fence(): void {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $breakRecord = static function (string $query) use ($wpdb, $user): string {
            return str_starts_with($query, "INSERT INTO `{$wpdb->options}`") && strpos($query, 'll_tools_lti_u_' . $user . '_i_') !== false
                ? 'INSERT INTO ll_tools_missing_lti_table (id) VALUES (1)' : $query;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $breakRecord);
        try { $this->assertWPError(ll_tools_lti_create_identity($user, $this->context())); }
        finally { remove_filter('query', $breakRecord); $wpdb->suppress_errors($previous); }
        $table = ll_tools_grade_delivery_table_names()['identities'];
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE learner_user_id = %d", $user)));
        add_option(ll_tools_privacy_deleted_user_lms_cleanup_option_name($user), ['pending' => true], '', false);
        $this->assertWPError(ll_tools_lti_create_identity($user, $this->context()));
    }

    public function test_private_export_errors_on_tampering_and_erasure_removes_only_exact_user_prefix(): void {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $name = 'll_tools_lti_u_' . $user . '_i_fixture';
        $otherName = 'll_tools_lti_u_' . $other . '_i_fixture';
        $this->assertTrue(ll_tools_lti_record_put($name, ['issuer' => $this->platform['issuer'], 'subject' => 'student-private']));
        $this->assertTrue(ll_tools_lti_record_put($otherName, ['issuer' => $this->platform['issuer'], 'subject' => 'other-student']));
        $export = ll_tools_lti_export_user_data($user);
        $this->assertCount(1, $export['data']);
        $this->assertStringContainsString('student-private', wp_json_encode($export));
        $this->assertStringNotContainsString('fixture-access-token', wp_json_encode($export));
        $wpdb->update($wpdb->options, ['option_value' => 'tampered'], ['option_name' => $name]);
        $this->assertWPError(ll_tools_lti_export_user_data($user));
        $erased = ll_tools_lti_erase_user_data($user);
        $this->assertTrue($erased['items_removed']);
        $this->assertTrue($erased['done']);
        $this->assertNull(ll_tools_lti_record_get($name));
        $this->assertIsArray(ll_tools_lti_record_get($otherName));
        $this->assertCount(0, $this->requests);
    }

    public function test_score_only_ags_lost_ack_conflict_retry_and_queryful_endpoint_converge(): void {
        global $wpdb;
        [$user, $links, $send] = $this->deliveryFixture();
        $clock = time();
        $clockFilter = static function () use (&$clock): int { return $clock; };
        add_filter('ll_tools_lti_transport_now', $clockFilter);
        remove_filter('pre_http_request', $this->httpFilter, 10);
        $public = openssl_pkey_get_details(openssl_pkey_get_private(LL_TOOLS_LTI_PRIVATE_KEY))['key'];
        $scoreCalls = [];
        $tokenCalls = [];
        $mock = function ($pre, array $args, string $url) use (&$scoreCalls, &$tokenCalls, $public, $user) {
            $held = $GLOBALS['ll_tools_lti_held_learner_lock'] ?? [];
            $this->assertSame($user, $held['user_id']);
            $this->assertTrue(ll_tools_privacy_user_session_lock_is_owned($user, $held['token']));
            $this->assertSame(0, (int) ($GLOBALS['ll_tools_lti_transaction_depth'] ?? 0), 'HTTP keeps advisory ownership without a long SQL row transaction.');
            if ($url === $this->platform['token_url']) {
                $tokenCalls[] = $args;
                $claims = JWT::decode($args['body']['client_assertion'], new Key($public, 'RS256'));
                $this->assertSame($this->platform['client_id'], $claims->sub);
                $this->assertSame($this->platform['token_url'], $claims->aud);
                $this->assertSame(LL_TOOLS_LTI_SCORE_SCOPE, $args['body']['scope']);
                return $this->response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer', 'scope' => LL_TOOLS_LTI_SCORE_SCOPE]);
            }
            $this->assertSame('https://moodle.example.org/mod/lti/services.php/1/lineitems/2/scores?type_id=3', $url);
            $scoreCalls[] = ['args' => $args, 'score' => json_decode($args['body'], true)];
            if (count($scoreCalls) === 1) { return new WP_Error('http_request_failed', 'Fixture lost response.'); }
            return $this->response([], count($scoreCalls) === 2 ? 409 : 204);
        };
        add_filter('pre_http_request', $mock, 10, 3);
        try {
            $first = ll_tools_lti_send_grade($send);
            $this->assertSame('network_error', $first['type']);
            $second = ll_tools_lti_send_grade($send);
            $this->assertSame(409, $second['http_status']);
            $this->assertSame('retry', ll_tools_grade_delivery_classify_adapter_response($second)['outcome']);
            $clock++;
            $third = ll_tools_lti_send_grade($send);
            $this->assertSame(204, $third['http_status']);
            $this->assertSame('success', ll_tools_grade_delivery_classify_adapter_response($third)['outcome']);
            $this->assertCount(3, $scoreCalls);
            $this->assertSame($scoreCalls[0]['score']['timestamp'], $scoreCalls[1]['score']['timestamp']);
            $this->assertGreaterThan(strtotime($scoreCalls[1]['score']['timestamp']), strtotime($scoreCalls[2]['score']['timestamp']));
            foreach ($scoreCalls as $call) {
                $this->assertSame('opaque-student', $call['score']['userId']);
                $this->assertSame(10, $call['score']['scoreGiven']);
                $this->assertSame(10, $call['score']['scoreMaximum']);
                $this->assertSame('FullyGraded', $call['score']['gradingProgress']);
                $this->assertSame(0, $call['args']['redirection']);
                $this->assertTrue($call['args']['reject_unsafe_urls']);
            }
            $tables = ll_tools_lms_assignment_table_names();
            $wpdb->update($tables['grades'], ['grade_revision' => 2, 'score_given' => 4, 'points_given' => '8.0000'], ['assignment_id' => $send['assignment_id'], 'revision_id' => $send['revision_id'], 'user_id' => $user]);
            $stale = ll_tools_lti_send_grade($send);
            $this->assertSame('permanent_error', $stale['type']);
            $this->assertCount(3, $scoreCalls, 'An obsolete selected grade never makes an external request.');
            $this->assertCount(3, $tokenCalls);
            $this->assertStringNotContainsString('fixture-access-token', wp_json_encode(ll_tools_lti_export_user_data($user)));
        } finally {
            remove_filter('pre_http_request', $mock, 10);
            remove_filter('ll_tools_lti_transport_now', $clockFilter);
            add_filter('pre_http_request', $this->httpFilter, 10, 3);
        }
    }

    public function test_same_second_grade_correction_defers_instead_of_fabricating_future_time(): void {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $clock = time();
        $clockFilter = static function () use (&$clock): int { return $clock; };
        add_filter('ll_tools_lti_transport_now', $clockFilter);
        try {
            $first = ll_tools_lti_grade_timestamp($user, 123, 1);
            $this->assertSame($clock, $first);
            $this->assertSame($first, ll_tools_lti_grade_timestamp($user, 123, 1));
            $this->assertWPError(ll_tools_lti_grade_timestamp($user, 123, 2));
            $clock++;
            $this->assertSame($clock, ll_tools_lti_grade_timestamp($user, 123, 2));
            $this->assertWPError(ll_tools_lti_grade_timestamp($user, 123, 1));
        } finally { remove_filter('ll_tools_lti_transport_now', $clockFilter); }
    }

    public function test_collection_only_ags_allows_practice_but_cannot_connect_a_grade(): void {
        [$ticket, $claims] = $this->stateAndClaims();
        $state = ll_tools_lti_ticket_consume('state', $ticket);
        $agsClaim = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';
        $claims[$agsClaim] = ['scope' => [LL_TOOLS_LTI_SCORE_SCOPE], 'lineitems' => 'https://moodle.example.org/mod/lti/services.php/1/lineitems'];
        $context = ll_tools_lti_validate_claims($claims, $this->platform, $state);
        $this->assertIsArray($context);
        $this->assertSame([], $context['ags']);
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $identity = ll_tools_lti_create_identity($user, $context);
        $this->assertIsInt($identity);
        $this->assertSame('lti_grades_not_authorized', ll_tools_lti_connect_grade($context, 1, 1, $identity, $user)->get_error_code());
        $claims[$agsClaim]['lineitems'] = 'https://foreign.example.org/lineitems';
        $this->assertSame('lti_invalid_ags', ll_tools_lti_validate_claims($claims, $this->platform, $state)->get_error_code());
        $claims[$agsClaim] = ['scope' => ['https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly'], 'lineitem' => 'https://moodle.example.org/mod/lti/services.php/1/lineitem'];
        $this->assertSame([], ll_tools_lti_validate_claims($claims, $this->platform, $state)['ags']);
        $this->assertCount(0, $this->requests);
    }

    public function test_nested_same_user_lock_reuses_advisory_and_outer_rollback_removes_mapping(): void {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $context = $this->context();
        $acquisitions = 0;
        $queryFilter = static function (string $sql) use (&$acquisitions): string {
            if (preg_match('/SELECT\s+GET_LOCK\s*\(/i', $sql)) { $acquisitions++; }
            return $sql;
        };
        add_filter('query', $queryFilter);
        $name = '';
        try {
            $result = ll_tools_lti_with_learner_lock($user, function () use ($user, $other, $context, &$name) {
                $this->assertSame('nested', ll_tools_lti_with_learner_lock($user, static fn() => 'nested'));
                $this->assertWPError(ll_tools_lti_with_learner_lock($other, static fn() => true));
                $identity = ll_tools_lti_create_identity($user, $context);
                $this->assertIsInt($identity);
                $name = ll_tools_lti_identity_record_name(['learner_user_id' => $user, 'connection_key_hash' => ll_tools_lti_connection_hash($context), 'subject_key_hash' => ll_tools_lti_subject_hash($context)]);
                $this->assertIsString(get_option($name));
                return new WP_Error('fixture_rollback');
            });
            $this->assertSame('fixture_rollback', $result->get_error_code());
            $this->assertSame(1, $acquisitions);
            $this->assertNull(ll_tools_lti_record_get($name));
            $this->assertSame('missing', get_option($name, 'missing'));
            $table = ll_tools_grade_delivery_table_names()['identities'];
            $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE learner_user_id = %d", $user)));
            $this->assertArrayNotHasKey('ll_tools_lti_held_learner_lock', $GLOBALS);
        } finally { remove_filter('query', $queryFilter); }
    }

    public function test_new_moodle_destination_enqueues_existing_selected_grade_once(): void {
        global $wpdb;
        [$user, $links, $send] = $this->deliveryFixture();
        $context = $this->context();
        $context['resource_link_id'] = 'later-moodle-activity';
        $context['ags']['lineitem'] = 'https://moodle.example.org/mod/lti/services.php/1/lineitems/99?type_id=3';
        $identity = ll_tools_lti_create_identity($user, $context);
        $this->assertIsInt($identity);
        $new = ll_tools_lti_connect_grade($context, $send['assignment_id'], $send['revision_id'], $identity, $user);
        $this->assertIsArray($new);
        $this->assertNotSame($links['destination_id'], $new['destination_id']);
        $table = ll_tools_grade_delivery_table_names()['deliveries'];
        $queued = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE destination_id = %d AND learner_user_id = %d", $new['destination_id'], $user), ARRAY_A);
        $this->assertCount(1, $queued);
        $this->assertSame('1', (string) $queued[0]['grade_revision']);
        $this->assertSame('10.0000', $queued[0]['points_given']);
        $this->assertSame($new, ll_tools_lti_connect_grade($context, $send['assignment_id'], $send['revision_id'], $identity, $user));
        $this->assertSame('1', (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE destination_id = %d AND learner_user_id = %d", $new['destination_id'], $user)));
        $this->assertCount(0, $this->requests, 'Connecting an existing grade queues delivery without synchronous external calls.');
    }

    private function deliveryFixture(): array {
        global $wpdb;
        $teacher = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $user = self::factory()->user->create(['role' => 'll_tools_learner']);
        $wordset = wp_insert_term('LTI Grade ' . wp_generate_uuid4(), 'wordset');
        $class = ll_tools_teacher_class_create($teacher, 'LTI Grade Class', (int) $wordset['term_id']);
        $this->assertIsInt($class);
        $this->assertTrue(ll_tools_teacher_class_add_student($class, $user));
        $items = [];
        for ($i = 0; $i < 5; $i++) { $items[] = ['key' => 'item-' . $i, 'options' => [['key' => 'yes', 'correct' => true], ['key' => 'no', 'correct' => false]]]; }
        $assignment = ll_tools_lms_assignment_create($class, ['title' => 'LTI score fixture', 'manifest' => ['schema' => 1, 'kind' => 'closed_response', 'items' => $items], 'points_maximum' => 10, 'attempt_limit' => 2, 'grade_policy' => 'best'], $teacher);
        $this->assertIsInt($assignment);
        $this->assertTrue(ll_tools_lms_assignment_publish($assignment, $teacher));
        $record = ll_tools_lms_assignment_get($assignment);
        $context = $this->context();
        $identity = ll_tools_lti_create_identity($user, $context);
        $this->assertIsInt($identity);
        $links = ll_tools_lti_connect_grade($context, $assignment, (int) $record['current_revision_id'], $identity, $user);
        $this->assertIsArray($links);
        $attempt = ll_tools_lms_assignment_start_attempt($assignment, $user);
        $this->assertIsArray($attempt);
        for ($i = 0; $i < 5; $i++) { $this->assertIsArray(ll_tools_lms_assignment_submit_answer($attempt['attempt']['attempt_uuid'], wp_generate_uuid4(), 'item-' . $i, 'yes', $user)); }
        $this->assertIsArray(ll_tools_lms_assignment_finalize_attempt($attempt['attempt']['attempt_uuid'], $user));
        $table = ll_tools_grade_delivery_table_names()['deliveries'];
        $delivery = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE destination_id = %d AND learner_user_id = %d ORDER BY id DESC LIMIT 1", $links['destination_id'], $user), ARRAY_A);
        $this->assertIsArray($delivery);
        $mapping = ll_tools_grade_delivery_load_exact_mapping($delivery);
        $this->assertIsArray($mapping);
        return [$user, $links, ll_tools_grade_delivery_send_context($delivery, $mapping)];
    }

    private function config(): array {
        return ['id' => 'moodle-test', 'name' => 'Test Moodle', 'issuer' => 'https://moodle.example.org',
            'client_id' => 'opaque-client', 'deployment_id' => 'deployment-one', 'authorization_url' => 'https://moodle.example.org/mod/lti/auth.php',
            'jwks_url' => 'https://moodle.example.org/mod/lti/certs.php', 'token_url' => 'https://moodle.example.org/mod/lti/token.php', 'enabled' => true];
    }

    private function response(array $body, int $code = 200): array {
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'fixture'], 'cookies' => []];
    }

    private function stateAndClaims(): array {
        $state = ['platform_id' => $this->platform['id'], 'nonce' => 'fixture-nonce', 'target_uri' => ll_tools_lti_tool_urls()['resource'], '_browser_binding' => ll_tools_lti_browser_hash($_COOKIE['__Host-ll-tools-lti'])];
        $ticket = ll_tools_lti_ticket_put('state', $state, 300);
        $prefix = 'https://purl.imsglobal.org/spec/lti/claim/';
        return [$ticket, ['iss' => $this->platform['issuer'], 'aud' => $this->platform['client_id'], 'sub' => 'opaque-student', 'nonce' => $state['nonce'], 'iat' => time(), 'exp' => time() + 120,
            $prefix . 'version' => '1.3.0', $prefix . 'message_type' => 'LtiResourceLinkRequest', $prefix . 'deployment_id' => $this->platform['deployment_id'],
            $prefix . 'target_link_uri' => $state['target_uri'], $prefix . 'context' => ['id' => 'course-one'], $prefix . 'resource_link' => ['id' => 'resource-one'],
            $prefix . 'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'], $prefix . 'custom' => ['ll_activity' => 'activity-opaque'],
            'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => ['scope' => [LL_TOOLS_LTI_SCORE_SCOPE], 'lineitem' => 'https://moodle.example.org/mod/lti/services.php/1/lineitems/2?type_id=3']]];
    }

    private function launchRequest(string $state, array $payload): WP_REST_Request {
        $request = new WP_REST_Request('POST');
        $request->set_body_params(['state' => $state, 'id_token' => JWT::encode($payload, LL_TOOLS_LTI_PRIVATE_KEY, 'RS256', LL_TOOLS_LTI_KEY_ID)]);
        return $request;
    }

    private function context(): array {
        [$ticket, $claims] = $this->stateAndClaims();
        $state = ll_tools_lti_ticket_consume('state', $ticket);
        return ll_tools_lti_validate_claims($claims, $this->platform, $state);
    }
}
