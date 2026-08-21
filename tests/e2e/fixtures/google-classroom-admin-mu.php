<?php
/**
 * LL Tools E2E Google Classroom admin fixture.
 *
 * This file is copied into the Local site's mu-plugins directory only while
 * the focused Playwright test is active. The option gate and short expiry keep
 * an interrupted run inert, and every Google HTTP request is short-circuited.
 */

if (!defined('ABSPATH')) {
    return;
}

function ll_tools_e2e_google_classroom_admin_config(): array {
    $config = get_option('ll_tools_e2e_google_classroom_admin_fixture', []);
    if (!is_array($config)
        || (int) ($config['schema'] ?? 0) !== 1
        || (int) ($config['expires_at'] ?? 0) < time()) {
        return [];
    }
    $mode = sanitize_key((string) ($config['mode'] ?? ''));
    if (!in_array($mode, ['unconfigured', 'configured'], true)) {
        return [];
    }
    $config['mode'] = $mode;
    return $config;
}

function ll_tools_e2e_google_classroom_admin_mode_is(string $mode): bool {
    $config = ll_tools_e2e_google_classroom_admin_config();
    return $config !== [] && (string) $config['mode'] === $mode;
}

add_filter('ll_tools_google_classroom_client_id', static function ($value): string {
    if (ll_tools_e2e_google_classroom_admin_mode_is('unconfigured')) {
        return '';
    }
    if (ll_tools_e2e_google_classroom_admin_mode_is('configured')) {
        return 'll-tools-e2e-classroom.apps.googleusercontent.com';
    }
    return is_string($value) ? $value : '';
}, PHP_INT_MAX);

add_filter('ll_tools_google_classroom_client_secret', static function ($value): string {
    if (ll_tools_e2e_google_classroom_admin_mode_is('unconfigured')) {
        return '';
    }
    if (ll_tools_e2e_google_classroom_admin_mode_is('configured')) {
        return 'll-tools-e2e-client-secret-never-sent';
    }
    return is_string($value) ? $value : '';
}, PHP_INT_MAX);

add_filter('ll_tools_lms_credential_master_key', static function ($value): string {
    if (ll_tools_e2e_google_classroom_admin_mode_is('unconfigured')) {
        return '';
    }
    if (ll_tools_e2e_google_classroom_admin_mode_is('configured')) {
        return str_repeat('E', 32);
    }
    return is_string($value) ? $value : '';
}, PHP_INT_MAX);

add_filter('ll_tools_google_classroom_allow_insecure_callback', static function ($allowed): bool {
    if (ll_tools_e2e_google_classroom_admin_mode_is('configured')) {
        return true;
    }
    return (bool) $allowed;
}, PHP_INT_MAX);

function ll_tools_e2e_google_classroom_admin_http_response(array $body): array {
    return [
        'headers' => [],
        'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}

add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    if (!ll_tools_e2e_google_classroom_admin_mode_is('configured')) {
        return $pre;
    }

    $parts = wp_parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $google_hosts = [
        'accounts.google.com',
        'oauth2.googleapis.com',
        'openidconnect.googleapis.com',
        'classroom.googleapis.com',
    ];
    if (!in_array($host, $google_hosts, true)) {
        return $pre;
    }

    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($host === 'oauth2.googleapis.com' && $path === '/token' && $method === 'POST') {
        return ll_tools_e2e_google_classroom_admin_http_response([
            'access_token' => 'll-tools-e2e-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    if ($host === 'classroom.googleapis.com' && $path === '/v1/courses' && $method === 'GET') {
        return ll_tools_e2e_google_classroom_admin_http_response([
            'courses' => [
                [
                    'id' => 'e2e-course-hebrew',
                    'name' => 'E2E Hebrew I',
                    'section' => 'Section A',
                    'courseState' => 'ACTIVE',
                ],
                [
                    'id' => 'e2e-course-greek',
                    'name' => 'E2E Greek I',
                    'section' => 'Section B',
                    'courseState' => 'ACTIVE',
                ],
                [
                    'id' => 'e2e-course-archived',
                    'name' => 'E2E Archived Course',
                    'section' => 'Old',
                    'courseState' => 'ARCHIVED',
                ],
            ],
        ]);
    }

    return new WP_Error(
        'll_tools_e2e_unexpected_google_request',
        'The E2E fixture blocked an unexpected Google request.'
    );
}, PHP_INT_MIN, 3);
