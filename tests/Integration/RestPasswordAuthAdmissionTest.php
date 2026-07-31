<?php
declare(strict_types=1);

final class RestPasswordAuthAdmissionTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    /** @var array<string,mixed> */
    private array $getBackup = [];

    protected function tearDown(): void
    {
        $this->restoreRequestState();
        ll_tools_rest_automation_clear_auth_runtime_state();
        ll_tools_rest_resource_guard_clear_state();
        parent::tearDown();
    }

    public function test_failed_password_counter_is_owned_by_expired_transient_maintenance(): void
    {
        $this->assertSame(
            'rest-basic-auth-rate-limit',
            ll_tools_expired_transient_maintenance_namespace('ll_tools_rest_basic_fail_deadbeef_123')
        );
        $this->assertSame(
            'rest-basic-auth-peer-rate-limit',
            ll_tools_expired_transient_maintenance_namespace('ll_tools_rest_basic_peer_deadbeef_123')
        );
    }

    public function test_raw_password_failures_are_indistinguishable_limited_by_direct_peer_and_expire(): void
    {
        $username = $this->uniqueUsername('limited');
        self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => $username,
            'user_pass' => 'CorrectPass!234',
        ]);
        $unknownUsername = $this->uniqueUsername('unknown');
        $clientIp = '203.0.113.41';
        $now = 1200;
        $limitFilter = static fn (): int => 2;
        $windowFilter = static fn (): int => 60;
        $nowFilter = static function () use (&$now): int {
            return $now;
        };
        add_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);
        add_filter('ll_tools_rest_password_auth_failure_window', $windowFilter);
        add_filter('ll_tools_rest_password_auth_failure_now', $nowFilter);

        try {
            $wrongPassword = $this->dispatchAuthenticatedStatus(
                $username,
                'WrongPass!234',
                $clientIp,
                ['HTTP_X_FORWARDED_FOR' => '198.51.100.10']
            );
            $unknownUser = $this->dispatchAuthenticatedStatus(
                $unknownUsername,
                'WrongPass!234',
                $clientIp
            );

            $this->assertSame(401, $wrongPassword->get_status());
            $this->assertSame(401, $unknownUser->get_status());
            $wrongData = $wrongPassword->get_data();
            $unknownData = $unknownUser->get_data();
            $this->assertIsArray($wrongData);
            $this->assertIsArray($unknownData);
            $this->assertSame('ll_tools_rest_invalid_basic_credentials', (string) ($wrongData['code'] ?? ''));
            $this->assertSame($wrongData['code'] ?? null, $unknownData['code'] ?? null);
            $this->assertSame($wrongData['message'] ?? null, $unknownData['message'] ?? null);

            $secondFailure = $this->dispatchAuthenticatedStatus(
                strtoupper($username),
                'StillWrong!234',
                $clientIp,
                ['HTTP_X_FORWARDED_FOR' => '198.51.100.11']
            );
            $limited = $this->dispatchAuthenticatedStatus(
                $username,
                'CorrectPass!234',
                $clientIp,
                ['HTTP_X_FORWARDED_FOR' => '198.51.100.12']
            );

            $this->assertSame(401, $secondFailure->get_status());
            $this->assertSame(429, $limited->get_status());
            $limitedData = $limited->get_data();
            $this->assertIsArray($limitedData);
            $this->assertSame('ll_tools_rest_basic_auth_rate_limited', (string) ($limitedData['code'] ?? ''));
            $this->assertGreaterThan(0, (int) ($limitedData['data']['retry_after_seconds'] ?? 0));
            $this->assertSame(
                (string) ($limitedData['data']['retry_after_seconds'] ?? ''),
                (string) ($limited->get_headers()['Retry-After'] ?? '')
            );
            $this->assertSame('limited', (string) ($limited->get_headers()['X-LL-Tools-Auth-Guard'] ?? ''));

            $now += 60;
            $afterExpiry = $this->dispatchAuthenticatedStatus(
                $username,
                'WrongAgain!234',
                $clientIp
            );
            $this->assertSame(401, $afterExpiry->get_status());
        } finally {
            ll_tools_rest_automation_password_auth_reset_failures($username, $clientIp);
            ll_tools_rest_automation_password_auth_reset_failures($unknownUsername, $clientIp);
            remove_filter('ll_tools_rest_password_auth_failure_now', $nowFilter);
            remove_filter('ll_tools_rest_password_auth_failure_window', $windowFilter);
            remove_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);
        }
    }

    public function test_successful_raw_password_auth_refunds_its_atomic_reservation(): void
    {
        $username = $this->uniqueUsername('refund');
        $adminId = self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => $username,
            'user_pass' => 'CorrectPass!456',
        ]);
        $clientIp = '203.0.113.42';
        $limitFilter = static fn (): int => 1;
        add_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);

        try {
            $success = $this->dispatchAuthenticatedStatus(
                $username,
                'CorrectPass!456',
                $clientIp
            );
            $this->assertSame(200, $success->get_status());
            $successData = $success->get_data();
            $this->assertIsArray($successData);
            $this->assertSame('basic_password', (string) ($successData['auth_mode'] ?? ''));
            $this->assertSame($adminId, (int) ($successData['user']['id'] ?? 0));

            $firstFailure = $this->dispatchAuthenticatedStatus(
                $username,
                'WrongPass!456',
                $clientIp
            );
            $secondFailure = $this->dispatchAuthenticatedStatus(
                $username,
                'WrongPass!456',
                $clientIp
            );
            $this->assertSame(401, $firstFailure->get_status());
            $this->assertSame(429, $secondFailure->get_status());
        } finally {
            ll_tools_rest_automation_password_auth_reset_failures($username, $clientIp);
            remove_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);
        }
    }

    public function test_rotating_identifiers_cannot_bypass_the_direct_peer_limit(): void
    {
        $clientIp = '203.0.113.44';
        $pairLimit = static fn (): int => 100;
        $peerLimit = static fn (): int => 3;
        add_filter('ll_tools_rest_password_auth_failure_limit', $pairLimit);
        add_filter('ll_tools_rest_password_auth_peer_failure_limit', $peerLimit);
        $usernames = [];

        try {
            for ($index = 0; $index < 4; $index++) {
                $usernames[] = $this->uniqueUsername('rotated-' . $index);
            }

            for ($index = 0; $index < 3; $index++) {
                $response = $this->dispatchAuthenticatedStatus(
                    $usernames[$index],
                    'WrongPass!Rotate',
                    $clientIp
                );
                $this->assertSame(401, $response->get_status());
            }

            $limited = $this->dispatchAuthenticatedStatus(
                $usernames[3],
                'WrongPass!Rotate',
                $clientIp
            );
            $this->assertSame(429, $limited->get_status());
            $data = $limited->get_data();
            $this->assertIsArray($data);
            $this->assertSame(
                'll_tools_rest_basic_auth_rate_limited',
                (string) ($data['code'] ?? '')
            );
        } finally {
            foreach ($usernames as $username) {
                ll_tools_rest_automation_password_auth_reset_failures($username, $clientIp);
            }
            remove_filter('ll_tools_rest_password_auth_peer_failure_limit', $peerLimit);
            remove_filter('ll_tools_rest_password_auth_failure_limit', $pairLimit);
        }
    }

    public function test_oversized_raw_credentials_are_rejected_before_password_hashing(): void
    {
        $clientIp = '203.0.113.45';
        $limits = static fn (): array => [
            'header_bytes' => 96,
            'username_bytes' => 24,
            'password_bytes' => 24,
        ];
        $authenticateCalls = 0;
        $watchAuthenticate = static function ($user) use (&$authenticateCalls) {
            $authenticateCalls++;
            return $user;
        };
        add_filter('ll_tools_rest_basic_auth_input_limits', $limits);
        add_filter('authenticate', $watchAuthenticate, 1);

        try {
            $response = $this->dispatchAuthenticatedStatus(
                'bounded-user',
                str_repeat('p', 80),
                $clientIp
            );
        } finally {
            ll_tools_rest_automation_password_auth_reset_failures('__invalid__', $clientIp);
            remove_filter('authenticate', $watchAuthenticate, 1);
            remove_filter('ll_tools_rest_basic_auth_input_limits', $limits);
        }

        $this->assertSame(401, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('ll_tools_rest_invalid_basic_credentials', (string) ($data['code'] ?? ''));
        $this->assertSame(0, $authenticateCalls);
    }

    public function test_oversized_non_basic_authorization_header_is_left_for_other_auth_handlers(): void
    {
        $this->backupRequestState();
        unset(
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('t', 9000);

        $credentials = ll_tools_rest_automation_get_basic_auth_credentials();

        $this->assertSame([], $credentials);
    }

    public function test_application_password_auth_bypasses_a_saturated_raw_password_counter(): void
    {
        $username = $this->uniqueUsername('app-pass');
        $adminId = self::factory()->user->create([
            'role' => 'administrator',
            'user_login' => $username,
            'user_pass' => 'AccountPass!789',
        ]);
        $applicationPassword = 'abcd EFGH 1234 WXYZ 5678 uvwx';
        $coreApplicationPasswordAuth = static function ($userId) use (
            $username,
            $applicationPassword,
            $adminId
        ) {
            if (!empty($userId)) {
                return $userId;
            }

            $credentials = ll_tools_rest_automation_get_basic_auth_credentials();
            if (
                (string) ($credentials['username'] ?? '') !== $username
                || (string) ($credentials['password'] ?? '') !== $applicationPassword
            ) {
                return $userId;
            }

            $user = get_user_by('id', $adminId);
            if (!$user instanceof WP_User) {
                return $userId;
            }

            // Simulate core's successful priority-20 application-password
            // determination without depending on sodium_compat in this test
            // runtime. The LL Tools raw-password layer runs at priority 30.
            $GLOBALS['wp_rest_application_password_status'] = $user;
            $GLOBALS['wp_rest_application_password_uuid'] = 'll-tools-test-application-password';
            return $adminId;
        };
        add_filter('determine_current_user', $coreApplicationPasswordAuth, 20);

        $clientIp = '203.0.113.43';
        $limitFilter = static fn (): int => 1;
        add_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);

        try {
            $rawFailure = $this->dispatchAuthenticatedStatus(
                $username,
                'WrongAccountPass!789',
                $clientIp
            );
            $this->assertSame(401, $rawFailure->get_status());

            $applicationSuccess = $this->dispatchAuthenticatedStatus(
                $username,
                $applicationPassword,
                $clientIp
            );
            $this->assertSame(200, $applicationSuccess->get_status());
            $data = $applicationSuccess->get_data();
            $this->assertIsArray($data);
            $this->assertSame('cookie_or_application_password', (string) ($data['auth_mode'] ?? ''));
            $this->assertSame($adminId, (int) ($data['user']['id'] ?? 0));
        } finally {
            ll_tools_rest_automation_password_auth_reset_failures($username, $clientIp);
            remove_filter('ll_tools_rest_password_auth_failure_limit', $limitFilter);
            remove_filter('determine_current_user', $coreApplicationPasswordAuth, 20);
            unset($GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid']);
        }
    }

    /**
     * Run the authentication stage before dispatch so this helper mirrors
     * WP_REST_Server::serve_request rather than the test server's bare dispatch.
     *
     * @param array<string,string> $extraServer
     */
    private function dispatchAuthenticatedStatus(
        string $username,
        string $password,
        string $clientIp,
        array $extraServer = []
    ): WP_REST_Response {
        $this->backupRequestState();
        $_GET['rest_route'] = '/ll-tools/v1/automation/status';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:10036';
        $_SERVER['REMOTE_ADDR'] = $clientIp;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($username . ':' . $password);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        foreach ($extraServer as $key => $value) {
            $_SERVER[$key] = $value;
        }

        global $current_user;
        wp_set_current_user(0);
        $current_user = null;
        ll_tools_rest_automation_clear_auth_runtime_state();

        $request = new WP_REST_Request('GET', '/ll-tools/v1/automation/status');
        wp_get_current_user();
        $server = rest_get_server();
        $authentication = $server->check_authentication();
        $response = is_wp_error($authentication)
            ? rest_convert_error_to_response($authentication)
            : rest_ensure_response($server->dispatch($request));
        $response = apply_filters('rest_post_dispatch', $response, $server, $request);

        return rest_ensure_response($response);
    }

    private function uniqueUsername(string $suffix): string
    {
        return sanitize_user(
            'lltools-auth-' . $suffix . '-' . strtolower(wp_generate_password(8, false, false)),
            true
        );
    }

    private function backupRequestState(): void
    {
        if (empty($this->serverBackup)) {
            foreach ([
                'REQUEST_METHOD',
                'HTTP_AUTHORIZATION',
                'REDIRECT_HTTP_AUTHORIZATION',
                'PHP_AUTH_USER',
                'PHP_AUTH_PW',
                'HTTP_HOST',
                'REMOTE_ADDR',
                'HTTP_X_FORWARDED_FOR',
            ] as $key) {
                $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            }
        }
        if (empty($this->getBackup)) {
            $this->getBackup['rest_route'] = $_GET['rest_route'] ?? null;
        }
    }

    private function restoreRequestState(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->serverBackup = [];

        foreach ($this->getBackup as $key => $value) {
            if ($value === null) {
                unset($_GET[$key]);
            } else {
                $_GET[$key] = $value;
            }
        }
        $this->getBackup = [];
    }
}
