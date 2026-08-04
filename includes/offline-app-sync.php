<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_OFFLINE_APP_SESSION_META')) {
    define('LL_TOOLS_OFFLINE_APP_SESSION_META', 'll_tools_offline_app_sessions');
}

if (!defined('LL_TOOLS_OFFLINE_APP_MAX_SESSIONS')) {
    define('LL_TOOLS_OFFLINE_APP_MAX_SESSIONS', 8);
}
if (!defined('LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION')) {
    define('LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION', '1.0.0');
}
if (!defined('LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION')) {
    define('LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION', 'll_tools_offline_app_session_schema_version');
}
if (!defined('LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK')) {
    define('LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK', 'll_tools_offline_app_session_cleanup');
}

if (!function_exists('ll_tools_offline_app_public_ajax_actions')) {
    function ll_tools_offline_app_public_ajax_actions(): array {
        return [
            'll_tools_offline_app_login',
            'll_tools_offline_app_logout',
            'll_tools_offline_app_sync',
        ];
    }
}

if (!function_exists('ll_tools_offline_app_allowed_origins')) {
    function ll_tools_offline_app_allowed_origins(): array {
        $origins = [
            'capacitor://localhost',
            'http://localhost',
            'https://localhost',
            'http://127.0.0.1',
            'https://127.0.0.1',
            'ionic://localhost',
        ];

        foreach ([home_url('/'), site_url('/'), admin_url()] as $url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts)) {
                continue;
            }
            $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            if ($scheme === '' || $host === '') {
                continue;
            }
            $origin = $scheme . '://' . $host;
            if (!empty($parts['port'])) {
                $origin .= ':' . (int) $parts['port'];
            }
            $origins[] = $origin;
        }

        $origins = apply_filters('ll_tools_offline_app_allowed_origins', $origins);
        if (!is_array($origins)) {
            return [];
        }

        $clean = [];
        foreach ($origins as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }
            $clean[$origin] = $origin;
        }

        return array_values($clean);
    }
}

if (!function_exists('ll_tools_offline_app_is_allowed_origin')) {
    function ll_tools_offline_app_is_allowed_origin(string $origin): bool {
        $origin = trim($origin);
        if ($origin === '') {
            return false;
        }

        return in_array($origin, ll_tools_offline_app_allowed_origins(), true);
    }
}

if (!function_exists('ll_tools_offline_app_send_cors_headers')) {
    function ll_tools_offline_app_send_cors_headers(): void {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        if ($origin === '' || !ll_tools_offline_app_is_allowed_origin($origin)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
}

if (!function_exists('ll_tools_offline_app_maybe_handle_preflight')) {
    function ll_tools_offline_app_maybe_handle_preflight(): void {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'OPTIONS') {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
        if (!in_array($action, ll_tools_offline_app_public_ajax_actions(), true)) {
            return;
        }

        ll_tools_offline_app_send_cors_headers();
        status_header(204);
        exit;
    }
}
add_action('init', 'll_tools_offline_app_maybe_handle_preflight', 0);

if (!function_exists('ll_tools_offline_app_prepare_json_response')) {
    function ll_tools_offline_app_prepare_json_response(): void {
        ll_tools_offline_app_send_cors_headers();
        nocache_headers();
    }
}

if (!function_exists('ll_tools_offline_app_session_ttl')) {
    function ll_tools_offline_app_session_ttl(): int {
        $ttl = (int) apply_filters('ll_tools_offline_app_session_ttl', 90 * DAY_IN_SECONDS);
        return max(DAY_IN_SECONDS, $ttl);
    }
}

if (!function_exists('ll_tools_offline_app_sanitize_instance_id')) {
    function ll_tools_offline_app_sanitize_instance_id($raw): string {
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return '';
        }

        return substr(preg_replace('/[^a-z0-9._:-]/', '', $value), 0, 80);
    }
}

if (!function_exists('ll_tools_offline_app_request_string')) {
    function ll_tools_offline_app_request_string(string $key): string {
        return isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
    }
}

if (!function_exists('ll_tools_offline_app_normalize_ip')) {
    function ll_tools_offline_app_normalize_ip($candidate): string {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            return '';
        }

        if (strpos($candidate, ',') !== false) {
            $parts = explode(',', $candidate);
            foreach ($parts as $part) {
                $normalized = ll_tools_offline_app_normalize_ip($part);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }

        $candidate = trim($candidate, "[] \t\n\r\0\x0B");
        if (substr_count($candidate, ':') === 1 && strpos($candidate, '.') !== false) {
            $segments = explode(':', $candidate, 2);
            if (count($segments) === 2 && filter_var($segments[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $candidate = $segments[0];
            }
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
    }
}

if (!function_exists('ll_tools_offline_app_get_client_ip')) {
    function ll_tools_offline_app_get_client_ip(): string {
        $candidates = apply_filters('ll_tools_offline_app_login_ip_candidates', [
            isset($_SERVER['REMOTE_ADDR']) ? wp_unslash((string) $_SERVER['REMOTE_ADDR']) : '',
        ]);

        foreach ((array) $candidates as $candidate) {
            $normalized = ll_tools_offline_app_normalize_ip($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }
}

if (!function_exists('ll_tools_offline_app_login_attempt_limit_config')) {
    function ll_tools_offline_app_login_attempt_limit_config(): array {
        return [
            'limit' => max(0, (int) apply_filters('ll_tools_offline_app_login_ip_attempt_limit', 10)),
            'window' => max(MINUTE_IN_SECONDS, (int) apply_filters('ll_tools_offline_app_login_ip_attempt_window', 15 * MINUTE_IN_SECONDS)),
        ];
    }
}

if (!function_exists('ll_tools_offline_app_login_attempt_key')) {
    function ll_tools_offline_app_login_attempt_key(string $ip): string {
        return 'll_tools_offline_login_' . substr(md5($ip), 0, 24);
    }
}

if (!function_exists('ll_tools_offline_app_get_login_rate_limit_status')) {
    function ll_tools_offline_app_get_login_rate_limit_status(string $ip = ''): array {
        if ($ip === '') {
            $ip = ll_tools_offline_app_get_client_ip();
        }

        $config = ll_tools_offline_app_login_attempt_limit_config();
        if ($ip === '' || $config['limit'] <= 0) {
            return [
                'limited' => false,
                'attempts' => 0,
                'limit' => $config['limit'],
                'window' => $config['window'],
                'ip' => $ip,
            ];
        }

        $attempts = (int) get_transient(ll_tools_offline_app_login_attempt_key($ip));

        return [
            'limited' => ($attempts >= $config['limit']),
            'attempts' => $attempts,
            'limit' => $config['limit'],
            'window' => $config['window'],
            'ip' => $ip,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_record_login_attempt')) {
    function ll_tools_offline_app_record_login_attempt(string $ip = ''): void {
        if ($ip === '') {
            $ip = ll_tools_offline_app_get_client_ip();
        }

        $config = ll_tools_offline_app_login_attempt_limit_config();
        if ($ip === '' || $config['limit'] <= 0) {
            return;
        }

        $key = ll_tools_offline_app_login_attempt_key($ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, $config['window']);
    }
}

if (!function_exists('ll_tools_offline_app_reset_login_attempts')) {
    function ll_tools_offline_app_reset_login_attempts(string $ip = ''): void {
        if ($ip === '') {
            $ip = ll_tools_offline_app_get_client_ip();
        }

        if ($ip === '') {
            return;
        }

        delete_transient(ll_tools_offline_app_login_attempt_key($ip));
    }
}

if (!function_exists('ll_tools_offline_app_login_rate_limit_message')) {
    function ll_tools_offline_app_login_rate_limit_message(): string {
        return __('Too many login attempts. Please try again in a few minutes.', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_offline_app_sync_throttle_config')) {
    function ll_tools_offline_app_sync_throttle_config(): array {
        $defaults = [
            'window' => 5 * MINUTE_IN_SECONDS,
            'request_limit' => 30,
            'resource_unit_limit' => 120,
            'ip_request_limit' => 120,
            'ip_resource_unit_limit' => 360,
            'word_ids_per_unit' => 250,
            'events_per_unit' => 50,
        ];

        $config = [
            'window' => apply_filters('ll_tools_offline_app_sync_throttle_window', $defaults['window']),
            'request_limit' => apply_filters('ll_tools_offline_app_sync_throttle_request_limit', $defaults['request_limit']),
            'resource_unit_limit' => apply_filters('ll_tools_offline_app_sync_throttle_resource_unit_limit', $defaults['resource_unit_limit']),
            'ip_request_limit' => apply_filters('ll_tools_offline_app_sync_throttle_ip_request_limit', $defaults['ip_request_limit']),
            'ip_resource_unit_limit' => apply_filters('ll_tools_offline_app_sync_throttle_ip_resource_unit_limit', $defaults['ip_resource_unit_limit']),
            'word_ids_per_unit' => apply_filters('ll_tools_offline_app_sync_throttle_word_ids_per_unit', $defaults['word_ids_per_unit']),
            'events_per_unit' => apply_filters('ll_tools_offline_app_sync_throttle_events_per_unit', $defaults['events_per_unit']),
        ];
        $config = (array) apply_filters('ll_tools_offline_app_sync_throttle_config', $config);

        return [
            'window' => max(MINUTE_IN_SECONDS, (int) ($config['window'] ?? $defaults['window'])),
            'request_limit' => max(0, (int) ($config['request_limit'] ?? $defaults['request_limit'])),
            'resource_unit_limit' => max(0, (int) ($config['resource_unit_limit'] ?? $defaults['resource_unit_limit'])),
            'ip_request_limit' => max(0, (int) ($config['ip_request_limit'] ?? $defaults['ip_request_limit'])),
            'ip_resource_unit_limit' => max(0, (int) ($config['ip_resource_unit_limit'] ?? $defaults['ip_resource_unit_limit'])),
            'word_ids_per_unit' => max(1, (int) ($config['word_ids_per_unit'] ?? $defaults['word_ids_per_unit'])),
            'events_per_unit' => max(1, (int) ($config['events_per_unit'] ?? $defaults['events_per_unit'])),
        ];
    }
}

if (!function_exists('ll_tools_offline_app_sync_resource_units')) {
    function ll_tools_offline_app_sync_resource_units(array $events, array $word_ids): int {
        $config = ll_tools_offline_app_sync_throttle_config();
        $units = 1
            + (int) ceil(count($events) / max(1, (int) ($config['events_per_unit'] ?? 50)))
            + (int) ceil(count($word_ids) / max(1, (int) ($config['word_ids_per_unit'] ?? 250)));

        $units = (int) apply_filters('ll_tools_offline_app_sync_resource_units', $units, $events, $word_ids, $config);
        return max(1, $units);
    }
}

if (!function_exists('ll_tools_offline_app_sync_throttle_key')) {
    function ll_tools_offline_app_sync_throttle_key(string $scope, string $identifier): string {
        return 'll_tools_offline_sync_' . sanitize_key($scope) . '_' . substr(md5($identifier), 0, 24);
    }
}

if (!function_exists('ll_tools_offline_app_sync_token_identifier')) {
    function ll_tools_offline_app_sync_token_identifier(string $token): string {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return substr(hash('sha256', $token), 0, 32);
    }
}

if (!function_exists('ll_tools_offline_app_sync_throttle_bucket')) {
    function ll_tools_offline_app_sync_throttle_bucket(string $scope, string $identifier): array {
        if ($identifier === '') {
            return [
                'requests' => 0,
                'resource_units' => 0,
                'expires_at' => 0,
            ];
        }

        $stored = get_transient(ll_tools_offline_app_sync_throttle_key($scope, $identifier));
        if (!is_array($stored)) {
            return [
                'requests' => max(0, (int) $stored),
                'resource_units' => 0,
                'expires_at' => 0,
            ];
        }

        return [
            'requests' => max(0, (int) ($stored['requests'] ?? 0)),
            'resource_units' => max(0, (int) ($stored['resource_units'] ?? 0)),
            'expires_at' => max(0, (int) ($stored['expires_at'] ?? 0)),
        ];
    }
}

if (!function_exists('ll_tools_offline_app_sync_bucket_status')) {
    function ll_tools_offline_app_sync_bucket_status(
        string $scope,
        string $identifier,
        int $request_limit,
        int $resource_unit_limit,
        int $window,
        int $resource_units
    ): array {
        $bucket = ll_tools_offline_app_sync_throttle_bucket($scope, $identifier);
        $retry_after = (!empty($bucket['expires_at']) && (int) $bucket['expires_at'] > time())
            ? max(1, (int) $bucket['expires_at'] - time())
            : 0;
        $limit_type = '';
        if ($identifier !== '' && $request_limit > 0 && (int) $bucket['requests'] >= $request_limit) {
            $limit_type = 'requests';
        } elseif (
            $identifier !== ''
            && $resource_unit_limit > 0
            && ((int) $bucket['resource_units'] + max(1, $resource_units)) > $resource_unit_limit
        ) {
            $limit_type = 'resource_units';
        }
        if ($limit_type !== '' && $retry_after <= 0) {
            $retry_after = max(MINUTE_IN_SECONDS, $window);
        }

        return [
            'scope' => $scope,
            'identifier' => $identifier,
            'limited' => ($limit_type !== ''),
            'limit_type' => $limit_type,
            'requests' => (int) $bucket['requests'],
            'request_limit' => $request_limit,
            'resource_units' => (int) $bucket['resource_units'],
            'resource_unit_limit' => $resource_unit_limit,
            'request_resource_units' => max(1, $resource_units),
            'window' => max(MINUTE_IN_SECONDS, $window),
            'retry_after' => $retry_after,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_sync_record_bucket_attempt')) {
    function ll_tools_offline_app_sync_record_bucket_attempt(string $scope, string $identifier, int $window, int $resource_units): array {
        if ($identifier === '') {
            return [
                'requests' => 0,
                'resource_units' => 0,
                'expires_at' => 0,
            ];
        }

        $bucket = ll_tools_offline_app_sync_throttle_bucket($scope, $identifier);
        $expires_at = time() + max(MINUTE_IN_SECONDS, $window);
        $updated = [
            'requests' => max(0, (int) ($bucket['requests'] ?? 0)) + 1,
            'resource_units' => max(0, (int) ($bucket['resource_units'] ?? 0)) + max(1, $resource_units),
            'expires_at' => $expires_at,
        ];

        set_transient(
            ll_tools_offline_app_sync_throttle_key($scope, $identifier),
            $updated,
            max(MINUTE_IN_SECONDS, $window)
        );

        return $updated;
    }
}

if (!function_exists('ll_tools_offline_app_sync_rate_limit_message')) {
    function ll_tools_offline_app_sync_rate_limit_message(string $scope = '', string $limit_type = ''): string {
        if ($scope === 'ip') {
            return __('Too many offline sync attempts from this connection. Please wait a few minutes and try again.', 'll-tools-text-domain');
        }

        if ($limit_type === 'resource_units') {
            return __('Offline sync is temporarily limited for this device because it requested too much data. Please wait a few minutes and try again.', 'll-tools-text-domain');
        }

        return __('Too many offline sync requests from this device. Please wait a few minutes and try again.', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_offline_app_get_sync_throttle_status')) {
    function ll_tools_offline_app_get_sync_throttle_status(string $token = '', int $resource_units = 1, string $ip = ''): array {
        $config = ll_tools_offline_app_sync_throttle_config();
        $token_identifier = ll_tools_offline_app_sync_token_identifier($token);
        $normalized_ip = ($ip !== '') ? ll_tools_offline_app_normalize_ip($ip) : ll_tools_offline_app_get_client_ip();

        $token_status = ll_tools_offline_app_sync_bucket_status(
            'token',
            $token_identifier,
            (int) ($config['request_limit'] ?? 0),
            (int) ($config['resource_unit_limit'] ?? 0),
            (int) ($config['window'] ?? MINUTE_IN_SECONDS),
            $resource_units
        );
        $ip_status = ll_tools_offline_app_sync_bucket_status(
            'ip',
            $normalized_ip,
            (int) ($config['ip_request_limit'] ?? 0),
            (int) ($config['ip_resource_unit_limit'] ?? 0),
            (int) ($config['window'] ?? MINUTE_IN_SECONDS),
            $resource_units
        );

        $limited_status = [];
        if (!empty($token_status['limited'])) {
            $limited_status = $token_status;
        } elseif (!empty($ip_status['limited'])) {
            $limited_status = $ip_status;
        }

        return [
            'limited' => !empty($limited_status),
            'scope' => (string) ($limited_status['scope'] ?? ''),
            'limit_type' => (string) ($limited_status['limit_type'] ?? ''),
            'retry_after' => max(0, (int) ($limited_status['retry_after'] ?? 0)),
            'message' => ll_tools_offline_app_sync_rate_limit_message(
                (string) ($limited_status['scope'] ?? ''),
                (string) ($limited_status['limit_type'] ?? '')
            ),
            'token' => $token_status,
            'ip' => $ip_status,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_record_sync_throttle_attempt')) {
    function ll_tools_offline_app_record_sync_throttle_attempt(string $token = '', int $resource_units = 1, string $ip = ''): void {
        $config = ll_tools_offline_app_sync_throttle_config();
        $window = (int) ($config['window'] ?? MINUTE_IN_SECONDS);
        $token_identifier = ll_tools_offline_app_sync_token_identifier($token);
        if ($token_identifier !== '' && ((int) ($config['request_limit'] ?? 0) > 0 || (int) ($config['resource_unit_limit'] ?? 0) > 0)) {
            ll_tools_offline_app_sync_record_bucket_attempt('token', $token_identifier, $window, $resource_units);
        }

        $normalized_ip = ($ip !== '') ? ll_tools_offline_app_normalize_ip($ip) : ll_tools_offline_app_get_client_ip();
        if ($normalized_ip !== '' && ((int) ($config['ip_request_limit'] ?? 0) > 0 || (int) ($config['ip_resource_unit_limit'] ?? 0) > 0)) {
            ll_tools_offline_app_sync_record_bucket_attempt('ip', $normalized_ip, $window, $resource_units);
        }
    }
}

if (!function_exists('ll_tools_offline_app_check_sync_throttle')) {
    function ll_tools_offline_app_check_sync_throttle(string $token = '', int $resource_units = 1, bool $record = true, string $ip = ''): array {
        $status = ll_tools_offline_app_get_sync_throttle_status($token, $resource_units, $ip);
        if (!empty($status['limited'])) {
            return $status;
        }

        if ($record) {
            ll_tools_offline_app_record_sync_throttle_attempt($token, $resource_units, $ip);
        }

        return $status;
    }
}

if (!function_exists('ll_tools_offline_app_reset_sync_throttle')) {
    function ll_tools_offline_app_reset_sync_throttle(string $token = '', string $ip = ''): void {
        $token_identifier = ll_tools_offline_app_sync_token_identifier($token);
        if ($token_identifier !== '') {
            delete_transient(ll_tools_offline_app_sync_throttle_key('token', $token_identifier));
        }

        $normalized_ip = ($ip !== '') ? ll_tools_offline_app_normalize_ip($ip) : '';
        if ($normalized_ip !== '') {
            delete_transient(ll_tools_offline_app_sync_throttle_key('ip', $normalized_ip));
        }
    }
}

if (!function_exists('ll_tools_offline_app_decode_json_payload')) {
    function ll_tools_offline_app_decode_json_payload($raw): array {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) wp_unslash($raw), true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('ll_tools_offline_app_sync_payload_max_bytes')) {
    function ll_tools_offline_app_sync_payload_max_bytes(): int {
        return max(0, (int) apply_filters('ll_tools_offline_app_sync_payload_max_bytes', 512 * 1024));
    }
}

if (!function_exists('ll_tools_offline_app_payload_size')) {
    function ll_tools_offline_app_payload_size($value): int {
        if (is_array($value)) {
            $total = 0;
            foreach ($value as $key => $item) {
                $total += strlen((string) $key) + ll_tools_offline_app_payload_size($item);
            }
            return $total;
        }

        return strlen((string) wp_unslash($value));
    }
}

if (!function_exists('ll_tools_offline_app_sync_payload_exceeds_limit')) {
    function ll_tools_offline_app_sync_payload_exceeds_limit(array $keys = ['events', 'word_ids', 'state', 'goals']): bool {
        $max_bytes = ll_tools_offline_app_sync_payload_max_bytes();
        if ($max_bytes <= 0) {
            return false;
        }

        $total = 0;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }

            $total += ll_tools_offline_app_payload_size($_POST[$key]);
            if ($total > $max_bytes) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ll_tools_offline_app_sync_word_ids_limit')) {
    function ll_tools_offline_app_sync_word_ids_limit(): int {
        return max(0, (int) apply_filters('ll_tools_offline_app_sync_word_ids_limit', 5000));
    }
}

if (!function_exists('ll_tools_offline_app_sanitize_word_ids')) {
    function ll_tools_offline_app_sanitize_word_ids($raw): array {
        $values = is_array($raw) ? $raw : ll_tools_offline_app_decode_json_payload($raw);
        $clean = [];
        foreach ((array) $values as $value) {
            $id = (int) $value;
            if ($id <= 0) {
                continue;
            }
            $clean[$id] = $id;
        }

        $clean = array_values($clean);
        $limit = ll_tools_offline_app_sync_word_ids_limit();
        if ($limit <= 0) {
            return [];
        }

        return array_slice($clean, 0, $limit);
    }
}

if (!function_exists('ll_tools_offline_app_sanitize_state_id_array')) {
    function ll_tools_offline_app_sanitize_state_id_array($values, string $array_key): array {
        if (function_exists('ll_tools_user_study_sanitize_state_id_array')) {
            return ll_tools_user_study_sanitize_state_id_array($values, $array_key);
        }

        $clean = [];
        foreach ((array) $values as $value) {
            $id = (int) $value;
            if ($id <= 0) {
                continue;
            }
            $clean[$id] = $id;
        }

        $clean = array_values($clean);
        $default = ($array_key === 'starred_word_ids') ? 5000 : 1000;
        $limit = max(0, (int) apply_filters("ll_tools_user_study_{$array_key}_limit", $default, $array_key));
        if ($limit <= 0) {
            return [];
        }

        return array_slice($clean, 0, $limit);
    }
}

if (!function_exists('ll_tools_offline_app_session_table')) {
    function ll_tools_offline_app_session_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'll_tools_offline_sessions';
    }
}

if (!function_exists('ll_tools_install_offline_app_session_schema')) {
    function ll_tools_install_offline_app_session_schema(): bool {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = ll_tools_offline_app_session_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            session_key varchar(32) NOT NULL,
            secret_hash varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            last_used_at datetime NOT NULL,
            device_id varchar(191) NOT NULL DEFAULT '',
            profile_id varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY user_session (user_id,session_key),
            KEY user_expiry (user_id,expires_at),
            KEY user_activity (user_id,last_used_at),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql);

        $table_status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
        if (
            is_object($table_status)
            && strtoupper((string) ($table_status->Engine ?? '')) !== 'INNODB'
        ) {
            $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB");
        }

        $ready = ll_tools_offline_app_session_schema_ready(true);
        if ($ready) {
            update_option(
                LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION,
                LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION,
                false
            );
        } else {
            delete_option(LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION);
        }
        return $ready;
    }
}

if (!function_exists('ll_tools_offline_app_session_schema_ready')) {
    function ll_tools_offline_app_session_schema_ready(bool $refresh = false): bool {
        global $wpdb;

        static $ready = null;
        if (!$refresh && is_bool($ready)) {
            return $ready;
        }

        $table = ll_tools_offline_app_session_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            $ready = false;
            return false;
        }

        $table_status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
        if (
            !is_object($table_status)
            || strtoupper((string) ($table_status->Engine ?? '')) !== 'INNODB'
        ) {
            $ready = false;
            return false;
        }

        $column_rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        if (!is_array($column_rows) || $wpdb->last_error !== '') {
            $ready = false;
            return false;
        }
        $columns = [];
        foreach ($column_rows as $row) {
            if (is_object($row) && !empty($row->Field)) {
                $columns[(string) $row->Field] = $row;
            }
        }
        $column_types = [
            'id' => '/^bigint(?:\(\d+\))? unsigned$/',
            'user_id' => '/^bigint(?:\(\d+\))? unsigned$/',
            'session_key' => '/^varchar\(32\)$/',
            'secret_hash' => '/^varchar\(255\)$/',
            'created_at' => '/^datetime(?:\(0\))?$/',
            'expires_at' => '/^datetime(?:\(0\))?$/',
            'last_used_at' => '/^datetime(?:\(0\))?$/',
            'device_id' => '/^varchar\(191\)$/',
            'profile_id' => '/^varchar\(191\)$/',
        ];
        foreach ($column_types as $column_name => $type_pattern) {
            $row = $columns[$column_name] ?? null;
            $type = is_object($row)
                ? strtolower(trim((string) ($row->Type ?? '')))
                : '';
            if (!is_object($row) || preg_match($type_pattern, $type) !== 1 || strtoupper((string) ($row->Null ?? '')) !== 'NO') {
                $ready = false;
                return false;
            }
        }
        if (stripos((string) ($columns['id']->Extra ?? ''), 'auto_increment') === false) {
            $ready = false;
            return false;
        }
        foreach (['device_id', 'profile_id'] as $column_name) {
            if (($columns[$column_name]->Default ?? null) !== '') {
                $ready = false;
                return false;
            }
        }

        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (!is_array($index_rows) || $wpdb->last_error !== '') {
            $ready = false;
            return false;
        }
        $index_columns = [];
        $index_uniqueness = [];
        $index_prefix_lengths = [];
        foreach ($index_rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $key_name = (string) ($row->Key_name ?? '');
            $column_name = (string) ($row->Column_name ?? '');
            $sequence = max(1, (int) ($row->Seq_in_index ?? 1));
            if ($key_name !== '' && $column_name !== '') {
                $index_columns[$key_name][$sequence] = $column_name;
                $index_uniqueness[$key_name] = (int) ($row->Non_unique ?? 1);
                $index_prefix_lengths[$key_name][$sequence] = isset($row->Sub_part) && $row->Sub_part !== null
                    ? (int) $row->Sub_part
                    : 0;
            }
        }
        foreach ($index_columns as &$columns_for_index) {
            ksort($columns_for_index, SORT_NUMERIC);
            $columns_for_index = array_values($columns_for_index);
        }
        unset($columns_for_index);
        foreach ($index_prefix_lengths as &$prefixes_for_index) {
            ksort($prefixes_for_index, SORT_NUMERIC);
            $prefixes_for_index = array_values($prefixes_for_index);
        }
        unset($prefixes_for_index);

        $required_indexes = [
            'PRIMARY' => ['id'],
            'user_session' => ['user_id', 'session_key'],
            'user_expiry' => ['user_id', 'expires_at'],
            'user_activity' => ['user_id', 'last_used_at'],
            'expires_at' => ['expires_at'],
        ];
        foreach ($required_indexes as $key_name => $expected_columns) {
            if (($index_columns[$key_name] ?? []) !== $expected_columns) {
                $ready = false;
                return false;
            }
            if (array_filter((array) ($index_prefix_lengths[$key_name] ?? []))) {
                $ready = false;
                return false;
            }
        }
        $required_uniqueness = [
            'PRIMARY' => 0,
            'user_session' => 0,
            'user_expiry' => 1,
            'user_activity' => 1,
            'expires_at' => 1,
        ];
        foreach ($required_uniqueness as $key_name => $expected_non_unique) {
            if (($index_uniqueness[$key_name] ?? null) !== $expected_non_unique) {
                $ready = false;
                return false;
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('ll_tools_maybe_install_offline_app_session_schema')) {
    function ll_tools_maybe_install_offline_app_session_schema(): void {
        if ((string) get_option(LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION, '') === LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION) {
            return;
        }
        ll_tools_install_offline_app_session_schema();
    }
}
add_action('init', 'll_tools_maybe_install_offline_app_session_schema', 4);

if (!function_exists('ll_tools_offline_app_schedule_session_cleanup')) {
    function ll_tools_offline_app_schedule_session_cleanup(): void {
        if (!wp_next_scheduled(LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK);
        }
    }
}
add_action('init', 'll_tools_offline_app_schedule_session_cleanup', 20);

if (!function_exists('ll_tools_offline_app_cleanup_expired_sessions')) {
    function ll_tools_offline_app_cleanup_expired_sessions($continuation = null): int {
        global $wpdb;

        if (!ll_tools_offline_app_session_schema_ready()) {
            return 0;
        }
        $limit = min(1000, max(1, (int) apply_filters('ll_tools_offline_app_session_cleanup_batch_size', 500)));
        $table = ll_tools_offline_app_session_table();
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE expires_at < %s
             ORDER BY expires_at ASC
             LIMIT %d",
            gmdate('Y-m-d H:i:s'),
            $limit
        ));
        $deleted = $deleted === false ? 0 : (int) $deleted;
        if ($deleted >= $limit && !wp_next_scheduled(LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK, ['continuation'])) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK, ['continuation']);
        }
        return $deleted;
    }
}
add_action(LL_TOOLS_OFFLINE_APP_SESSION_CLEANUP_HOOK, 'll_tools_offline_app_cleanup_expired_sessions');

if (!function_exists('ll_tools_offline_app_delete_user_sessions')) {
    function ll_tools_offline_app_delete_user_sessions(int $user_id): void {
        global $wpdb;
        if ($user_id > 0 && ll_tools_offline_app_session_schema_ready()) {
            $wpdb->delete(ll_tools_offline_app_session_table(), ['user_id' => $user_id], ['%d']);
        }
        delete_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META);
    }
}
add_action('delete_user', 'll_tools_offline_app_delete_user_sessions');

if (!function_exists('ll_tools_offline_app_sanitize_legacy_sessions')) {
    function ll_tools_offline_app_sanitize_legacy_sessions(int $user_id, $raw_snapshot = null): array {
        $raw = is_array($raw_snapshot)
            ? $raw_snapshot
            : ($user_id > 0 ? get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true) : []);
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $clean = [];
        foreach ($raw as $session_key => $session) {
            $session_key = sanitize_key((string) $session_key);
            if ($session_key === '' || !is_array($session)) {
                continue;
            }
            $hash = trim((string) ($session['secret_hash'] ?? ''));
            $expires_at = trim((string) ($session['expires_at'] ?? ''));
            $expires_ts = $expires_at !== '' ? strtotime($expires_at . ' UTC') : 0;
            if ($hash === '' || $expires_ts <= $now) {
                continue;
            }
            $created_at = trim((string) ($session['created_at'] ?? ''));
            $last_used_at = trim((string) ($session['last_used_at'] ?? ''));
            $clean[$session_key] = [
                'secret_hash' => $hash,
                'created_at' => $created_at !== '' ? $created_at : gmdate('Y-m-d H:i:s'),
                'expires_at' => $expires_at,
                'last_used_at' => $last_used_at !== '' ? $last_used_at : ($created_at !== '' ? $created_at : gmdate('Y-m-d H:i:s')),
                'device_id' => ll_tools_offline_app_sanitize_instance_id($session['device_id'] ?? ''),
                'profile_id' => ll_tools_offline_app_sanitize_instance_id($session['profile_id'] ?? ''),
            ];
        }

        uasort($clean, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_used_at'] ?? ''), (string) ($left['last_used_at'] ?? ''));
        });
        return array_slice($clean, 0, LL_TOOLS_OFFLINE_APP_MAX_SESSIONS, true);
    }
}

if (!function_exists('ll_tools_offline_app_acquire_user_session_lock')) {
    function ll_tools_offline_app_acquire_user_session_lock(int $user_id): string {
        global $wpdb;
        if ($user_id <= 0) {
            return '';
        }
        $lock_name = 'll_tools_offline_' . substr(hash('sha256', (string) $user_id), 0, 32);
        $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        return $acquired === 1 ? $lock_name : '';
    }
}

if (!function_exists('ll_tools_offline_app_release_user_session_lock')) {
    function ll_tools_offline_app_release_user_session_lock(string $lock_name): void {
        global $wpdb;
        if ($lock_name !== '') {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}

if (!function_exists('ll_tools_offline_app_count_user_sessions')) {
    function ll_tools_offline_app_count_user_sessions(int $user_id): ?int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d',
            $user_id
        ));
        return is_numeric($count) && $wpdb->last_error === '' ? max(0, (int) $count) : null;
    }
}

if (!function_exists('ll_tools_offline_app_import_legacy_sessions_for_user')) {
    function ll_tools_offline_app_import_legacy_sessions_for_user(int $user_id): bool {
        global $wpdb;

        if ($user_id <= 0 || !ll_tools_offline_app_session_schema_ready()) {
            return false;
        }
        $raw = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
        if (!is_array($raw) || empty($raw)) {
            return true;
        }

        $lock_name = ll_tools_offline_app_acquire_user_session_lock($user_id);
        if ($lock_name === '') {
            return false;
        }
        try {
            $raw = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
            if (!is_array($raw) || empty($raw)) {
                return true;
            }
            $sessions = ll_tools_offline_app_sanitize_legacy_sessions($user_id, $raw);
            $table = ll_tools_offline_app_session_table();
            if ($wpdb->query('START TRANSACTION') === false) {
                return false;
            }
            foreach ($sessions as $session_key => $session) {
                $inserted = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                        (user_id, session_key, secret_hash, created_at, expires_at, last_used_at, device_id, profile_id)
                     VALUES (%d, %s, %s, %s, %s, %s, %s, %s)",
                    $user_id,
                    $session_key,
                    (string) $session['secret_hash'],
                    (string) $session['created_at'],
                    (string) $session['expires_at'],
                    (string) $session['last_used_at'],
                    (string) $session['device_id'],
                    (string) $session['profile_id']
                ));
                if ($inserted === false) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
            }

            $expected_keys = array_keys($sessions);
            if (!empty($expected_keys)) {
                $placeholders = implode(', ', array_fill(0, count($expected_keys), '%s'));
                $params = array_merge([$user_id], $expected_keys);
                $imported_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT session_key, secret_hash FROM {$table} WHERE user_id = %d AND session_key IN ({$placeholders})",
                    $params
                ), ARRAY_A);
                $imported_hashes = [];
                foreach ((array) $imported_rows as $imported_row) {
                    $imported_hashes[(string) ($imported_row['session_key'] ?? '')] = (string) ($imported_row['secret_hash'] ?? '');
                }
                foreach ($sessions as $expected_key => $expected_session) {
                    if (
                        !isset($imported_hashes[$expected_key])
                        || !hash_equals((string) $expected_session['secret_hash'], $imported_hashes[$expected_key])
                    ) {
                        $wpdb->query('ROLLBACK');
                        return false;
                    }
                }
                if (count($imported_hashes) !== count($expected_keys)) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND expires_at < %s",
                $user_id,
                $now
            )) === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $active_count = ll_tools_offline_app_count_user_sessions($user_id);
            if ($active_count === null) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $delete_count = max(0, $active_count - LL_TOOLS_OFFLINE_APP_MAX_SESSIONS);
            if ($delete_count > 0 && $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE user_id = %d
                 ORDER BY last_used_at ASC, created_at ASC, id ASC
                 LIMIT %d",
                $user_id,
                $delete_count
            )) === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            delete_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $raw);
            return get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true) !== $raw;
        } finally {
            ll_tools_offline_app_release_user_session_lock($lock_name);
        }
    }
}

if (!function_exists('ll_tools_offline_app_sessions_for_user')) {
    function ll_tools_offline_app_sessions_for_user(int $user_id): array {
        if ($user_id <= 0) {
            return [];
        }
        if (!ll_tools_offline_app_session_schema_ready()) {
            return ll_tools_offline_app_sanitize_legacy_sessions($user_id);
        }

        ll_tools_offline_app_import_legacy_sessions_for_user($user_id);
        global $wpdb;
        $table = ll_tools_offline_app_session_table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND expires_at < %s",
            $user_id,
            $now
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT session_key, secret_hash, created_at, expires_at, last_used_at, device_id, profile_id
             FROM {$table}
             WHERE user_id = %d AND expires_at >= %s
             ORDER BY last_used_at DESC, created_at DESC, id DESC
             LIMIT %d",
            $user_id,
            $now,
            LL_TOOLS_OFFLINE_APP_MAX_SESSIONS
        ), ARRAY_A);
        if (!is_array($rows)) {
            return ll_tools_offline_app_sanitize_legacy_sessions($user_id);
        }

        $sessions = [];
        foreach ($rows as $row) {
            $session_key = sanitize_key((string) ($row['session_key'] ?? ''));
            if ($session_key === '') {
                continue;
            }
            $sessions[$session_key] = [
                'secret_hash' => (string) ($row['secret_hash'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
                'device_id' => ll_tools_offline_app_sanitize_instance_id($row['device_id'] ?? ''),
                'profile_id' => ll_tools_offline_app_sanitize_instance_id($row['profile_id'] ?? ''),
            ];
        }
        foreach (ll_tools_offline_app_sanitize_legacy_sessions($user_id) as $session_key => $session) {
            if (!isset($sessions[$session_key]) && count($sessions) < LL_TOOLS_OFFLINE_APP_MAX_SESSIONS) {
                $sessions[$session_key] = $session;
            }
        }
        return $sessions;
    }
}

if (!function_exists('ll_tools_offline_app_create_session')) {
    function ll_tools_offline_app_create_session(int $user_id, array $context = []): array {
        global $wpdb;
        if ($user_id <= 0) {
            return [];
        }
        if (!ll_tools_offline_app_session_schema_ready() && !ll_tools_install_offline_app_session_schema()) {
            return [];
        }
        if (!ll_tools_offline_app_import_legacy_sessions_for_user($user_id)) {
            return [];
        }

        $session_key = sanitize_key(str_replace('-', '', wp_generate_uuid4()));
        $session_key = substr($session_key, 0, 24);
        if ($session_key === '') {
            $session_key = strtolower(wp_generate_password(20, false, false));
        }
        $secret = wp_generate_password(40, false, false);
        $now = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + ll_tools_offline_app_session_ttl());
        $lock_name = ll_tools_offline_app_acquire_user_session_lock($user_id);
        if ($lock_name === '') {
            return [];
        }
        try {
            $table = ll_tools_offline_app_session_table();
            if ($wpdb->query('START TRANSACTION') === false) {
                return [];
            }
            if ($wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND expires_at < %s",
                $user_id,
                $now
            )) === false) {
                $wpdb->query('ROLLBACK');
                return [];
            }
            $current_count = ll_tools_offline_app_count_user_sessions($user_id);
            if ($current_count === null) {
                $wpdb->query('ROLLBACK');
                return [];
            }
            $delete_count = max(0, $current_count - LL_TOOLS_OFFLINE_APP_MAX_SESSIONS + 1);
            if ($delete_count > 0 && $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE user_id = %d
                 ORDER BY last_used_at ASC, created_at ASC, id ASC
                 LIMIT %d",
                $user_id,
                $delete_count
            )) === false) {
                $wpdb->query('ROLLBACK');
                return [];
            }

            $inserted = $wpdb->insert($table, [
                'user_id' => $user_id,
                'session_key' => $session_key,
                'secret_hash' => wp_hash_password($secret),
                'created_at' => $now,
                'expires_at' => $expires_at,
                'last_used_at' => $now,
                'device_id' => ll_tools_offline_app_sanitize_instance_id($context['device_id'] ?? ''),
                'profile_id' => ll_tools_offline_app_sanitize_instance_id($context['profile_id'] ?? ''),
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                return [];
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return [];
            }
        } finally {
            ll_tools_offline_app_release_user_session_lock($lock_name);
        }

        return [
            'token' => sprintf('llapp.%d.%s.%s', $user_id, $session_key, $secret),
            'expires_at' => $expires_at,
            'session_key' => $session_key,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_authenticate_token')) {
    function ll_tools_offline_app_authenticate_token(string $token, bool $touch = true): ?array {
        $token = trim($token);
        if (!preg_match('/^llapp\.(\d+)\.([a-z0-9]+)\.([A-Za-z0-9]+)$/', $token, $matches)) {
            return null;
        }

        $user_id = (int) $matches[1];
        $session_key = sanitize_key((string) $matches[2]);
        $secret = (string) $matches[3];
        if ($user_id <= 0 || $session_key === '' || $secret === '') {
            return null;
        }

        global $wpdb;
        $session = null;
        $table_session = false;
        $legacy_import_succeeded = true;
        if (ll_tools_offline_app_session_schema_ready()) {
            $legacy_import_succeeded = ll_tools_offline_app_import_legacy_sessions_for_user($user_id);
            $table = ll_tools_offline_app_session_table();
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, secret_hash, created_at, expires_at, last_used_at, device_id, profile_id
                 FROM {$table}
                 WHERE user_id = %d AND session_key = %s
                 LIMIT 1",
                $user_id,
                $session_key
            ), ARRAY_A);
            if (is_array($row)) {
                $session = [
                    'secret_hash' => (string) ($row['secret_hash'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'expires_at' => (string) ($row['expires_at'] ?? ''),
                    'last_used_at' => (string) ($row['last_used_at'] ?? ''),
                    'device_id' => ll_tools_offline_app_sanitize_instance_id($row['device_id'] ?? ''),
                    'profile_id' => ll_tools_offline_app_sanitize_instance_id($row['profile_id'] ?? ''),
                ];
                $table_session = true;
            }
        }
        if (!is_array($session)) {
            if (!$legacy_import_succeeded) {
                return null;
            }
            $legacy_sessions = ll_tools_offline_app_sanitize_legacy_sessions($user_id);
            $session = isset($legacy_sessions[$session_key]) && is_array($legacy_sessions[$session_key])
                ? $legacy_sessions[$session_key]
                : null;
        }
        if (!is_array($session)) {
            return null;
        }
        $expires_ts = strtotime((string) ($session['expires_at'] ?? '') . ' UTC');
        if ($expires_ts <= time()) {
            if ($table_session) {
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s AND secret_hash = %s',
                    $user_id,
                    $session_key,
                    (string) $session['secret_hash']
                ));
            }
            return null;
        }
        if (!wp_check_password($secret, (string) ($session['secret_hash'] ?? ''))) {
            return null;
        }

        if ($touch && $table_session) {
            $now = gmdate('Y-m-d H:i:s');
            $session['last_used_at'] = $now;
            $session['expires_at'] = gmdate('Y-m-d H:i:s', time() + ll_tools_offline_app_session_ttl());
            $updated = $wpdb->update(ll_tools_offline_app_session_table(), [
                'last_used_at' => $session['last_used_at'],
                'expires_at' => $session['expires_at'],
            ], [
                'user_id' => $user_id,
                'session_key' => $session_key,
                'secret_hash' => (string) $session['secret_hash'],
            ], ['%s', '%s'], ['%d', '%s', '%s']);
            if ($updated === false) {
                return null;
            }
            if ($updated === 0) {
                $persisted_hash = $wpdb->get_var($wpdb->prepare(
                    'SELECT secret_hash FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s LIMIT 1',
                    $user_id,
                    $session_key
                ));
                if (
                    !is_string($persisted_hash)
                    || $wpdb->last_error !== ''
                    || !hash_equals((string) $session['secret_hash'], $persisted_hash)
                ) {
                    return null;
                }
            }
        }

        return [
            'user_id' => $user_id,
            'session_key' => $session_key,
            'session' => $session,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_revoke_session')) {
    function ll_tools_offline_app_remove_legacy_session_key(int $user_id, string $session_key, string $secret_hash = ''): bool {
        $secret_hash = trim($secret_hash);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $legacy_sessions = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
            if (!is_array($legacy_sessions) || !array_key_exists($session_key, $legacy_sessions)) {
                return true;
            }
            $legacy_hash = is_array($legacy_sessions[$session_key] ?? null)
                ? trim((string) ($legacy_sessions[$session_key]['secret_hash'] ?? ''))
                : '';
            if ($secret_hash !== '' && ($legacy_hash === '' || !hash_equals($secret_hash, $legacy_hash))) {
                return true;
            }

            $next_sessions = $legacy_sessions;
            unset($next_sessions[$session_key]);
            $changed = empty($next_sessions)
                ? delete_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $legacy_sessions)
                : update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $next_sessions, $legacy_sessions);
            $persisted = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
            if (!is_array($persisted) || !array_key_exists($session_key, $persisted)) {
                return true;
            }
            $persisted_hash = is_array($persisted[$session_key] ?? null)
                ? trim((string) ($persisted[$session_key]['secret_hash'] ?? ''))
                : '';
            if ($secret_hash !== '' && ($persisted_hash === '' || !hash_equals($secret_hash, $persisted_hash))) {
                return true;
            }
            if (!$changed && $persisted === $legacy_sessions) {
                return false;
            }
        }

        return false;
    }

    function ll_tools_offline_app_revoke_session(int $user_id, string $session_key, string $secret_hash = ''): bool {
        global $wpdb;
        $session_key = sanitize_key($session_key);
        if ($user_id <= 0 || $session_key === '') {
            return false;
        }
        if (ll_tools_offline_app_session_schema_ready()) {
            $lock_name = ll_tools_offline_app_acquire_user_session_lock($user_id);
            if ($lock_name === '') {
                return false;
            }
            try {
                $secret_hash = trim($secret_hash);
                if (!ll_tools_offline_app_remove_legacy_session_key($user_id, $session_key, $secret_hash)) {
                    return false;
                }
                if ($secret_hash === '') {
                    $current_hash = $wpdb->get_var($wpdb->prepare(
                        'SELECT secret_hash FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s LIMIT 1',
                        $user_id,
                        $session_key
                    ));
                    if ($wpdb->last_error !== '') {
                        return false;
                    }
                    $secret_hash = is_string($current_hash) ? $current_hash : '';
                }
                $deleted = $secret_hash === ''
                    ? 0
                    : $wpdb->query($wpdb->prepare(
                        'DELETE FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s AND secret_hash = %s',
                        $user_id,
                        $session_key,
                        $secret_hash
                    ));
                if ($deleted === false) {
                    return false;
                }
                $remaining = $secret_hash === ''
                    ? 0
                    : $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM ' . ll_tools_offline_app_session_table() . ' WHERE user_id = %d AND session_key = %s AND secret_hash = %s',
                        $user_id,
                        $session_key,
                        $secret_hash
                    ));
                return is_numeric($remaining) && $wpdb->last_error === '' && (int) $remaining === 0;
            } finally {
                ll_tools_offline_app_release_user_session_lock($lock_name);
            }
        }

        return ll_tools_offline_app_remove_legacy_session_key($user_id, $session_key, $secret_hash);
    }
}

if (!function_exists('ll_tools_offline_app_require_authenticated_user')) {
    function ll_tools_offline_app_require_authenticated_user(bool $touch = true): array {
        $token = ll_tools_offline_app_request_string('auth_token');
        $auth = ll_tools_offline_app_authenticate_token($token, $touch);
        if (!$auth) {
            wp_send_json_error(['message' => __('Sign in required.', 'll-tools-text-domain')], 401);
        }

        $user_id = (int) ($auth['user_id'] ?? 0);
        if ($user_id <= 0 || !ll_tools_user_study_can_access($user_id)) {
            wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
        }

        wp_set_current_user($user_id);

        return $auth;
    }
}

if (!function_exists('ll_tools_offline_app_build_user_summary')) {
    function ll_tools_offline_app_build_user_summary(int $user_id): array {
        $user = get_userdata($user_id);
        if (!($user instanceof WP_User)) {
            return [];
        }

        return [
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_normalize_state_payload')) {
    function ll_tools_offline_app_normalize_state_payload(array $raw, int $user_id): array {
        $current = ll_tools_get_user_study_state($user_id);
        $wordset_id = isset($raw['wordset_id']) ? (int) $raw['wordset_id'] : (int) ($current['wordset_id'] ?? 0);
        $category_ids = isset($raw['category_ids']) ? (array) $raw['category_ids'] : (array) ($current['category_ids'] ?? []);
        $starred_ids = isset($raw['starred_word_ids']) ? (array) $raw['starred_word_ids'] : (array) ($current['starred_word_ids'] ?? []);
        $fast_transitions = filter_var($raw['fast_transitions'] ?? ($current['fast_transitions'] ?? false), FILTER_VALIDATE_BOOLEAN);

        $goals = function_exists('ll_tools_get_user_study_goals')
            ? ll_tools_get_user_study_goals($user_id)
            : [];
        $ignored_lookup = [];
        foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
            $ignored_lookup[(int) $ignored_id] = true;
        }

        $category_ids = ll_tools_offline_app_sanitize_state_id_array($category_ids, 'category_ids');
        $category_ids = array_values(array_filter($category_ids, static function (int $id) use ($ignored_lookup): bool {
            return $id > 0 && empty($ignored_lookup[$id]);
        }));
        if (function_exists('ll_tools_user_study_filter_quizzable_category_ids')) {
            $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, $wordset_id);
        }

        $starred_ids = ll_tools_offline_app_sanitize_state_id_array($starred_ids, 'starred_word_ids');

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
            'starred_word_ids' => $starred_ids,
            'star_mode' => 'normal',
            'fast_transitions' => $fast_transitions,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_parse_state_request')) {
    function ll_tools_offline_app_parse_state_request(int $user_id): array {
        $state_raw = $_POST['state'] ?? null;
        if ($state_raw === null) {
            return ll_tools_get_user_study_state($user_id);
        }

        $state = ll_tools_offline_app_decode_json_payload($state_raw);
        return ll_tools_save_user_study_state(ll_tools_offline_app_normalize_state_payload($state, $user_id), $user_id);
    }
}

if (!function_exists('ll_tools_offline_app_parse_goals_request')) {
    function ll_tools_offline_app_parse_goals_request(int $user_id): array {
        if (!function_exists('ll_tools_get_user_study_goals')) {
            return [];
        }

        $goals_raw = $_POST['goals'] ?? null;
        if ($goals_raw === null) {
            return ll_tools_get_user_study_goals($user_id);
        }

        $goals = ll_tools_offline_app_decode_json_payload($goals_raw);
        if (function_exists('ll_tools_save_user_study_goals')) {
            return ll_tools_save_user_study_goals($goals, $user_id);
        }

        return ll_tools_get_user_study_goals($user_id);
    }
}

if (!function_exists('ll_tools_offline_app_parse_word_ids')) {
    function ll_tools_offline_app_parse_word_ids(): array {
        $raw = $_POST['word_ids'] ?? [];
        return ll_tools_offline_app_sanitize_word_ids($raw);
    }
}

if (!function_exists('ll_tools_offline_app_build_category_progress_snapshots')) {
    function ll_tools_offline_app_build_category_progress_snapshots(int $user_id, array $state): array {
        if (!function_exists('ll_tools_get_user_category_progress')) {
            return [];
        }

        $wordset_id = (int) ($state['wordset_id'] ?? 0);
        $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($state['category_ids'] ?? [])), static function (int $id): bool {
            return $id > 0;
        })));
        $category_lookup = array_fill_keys($category_ids, true);
        $raw = ll_tools_get_user_category_progress($user_id);
        if (empty($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $category_id => $entry) {
            $cid = (int) $category_id;
            if ($cid <= 0 || !is_array($entry)) {
                continue;
            }
            if (!empty($category_lookup) && empty($category_lookup[$cid])) {
                continue;
            }
            if ($wordset_id > 0 && !empty($entry['wordset_id']) && (int) $entry['wordset_id'] !== $wordset_id) {
                continue;
            }
            $out[$cid] = $entry;
        }

        return $out;
    }
}

if (!function_exists('ll_tools_offline_app_build_word_progress_snapshots')) {
    function ll_tools_offline_app_build_word_progress_snapshots(int $user_id, array $word_ids): array {
        $rows = ll_tools_get_user_word_progress_rows($user_id, $word_ids);
        if (empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $word_id => $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[(int) $word_id] = [
                'total_coverage' => max(0, (int) ($row['total_coverage'] ?? 0)),
                'coverage_learning' => max(0, (int) ($row['coverage_learning'] ?? 0)),
                'coverage_practice' => max(0, (int) ($row['coverage_practice'] ?? 0)),
                'coverage_listening' => max(0, (int) ($row['coverage_listening'] ?? 0)),
                'coverage_gender' => max(0, (int) ($row['coverage_gender'] ?? 0)),
                'coverage_self_check' => max(0, (int) ($row['coverage_self_check'] ?? 0)),
                'correct_clean' => max(0, (int) ($row['correct_clean'] ?? 0)),
                'correct_after_retry' => max(0, (int) ($row['correct_after_retry'] ?? 0)),
                'current_correct_streak' => max(0, (int) ($row['current_correct_streak'] ?? 0)),
                'mastery_unlocked' => !empty($row['mastery_unlocked']),
                'incorrect' => max(0, (int) ($row['incorrect'] ?? 0)),
                'lapse_count' => max(0, (int) ($row['lapse_count'] ?? 0)),
                'stage' => max(0, (int) ($row['stage'] ?? 0)),
                'last_mode' => isset($row['last_mode']) ? (string) $row['last_mode'] : '',
                'last_seen_at' => isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : '',
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
                'progress_status' => ll_tools_user_progress_word_status($row),
                'difficulty_score' => ll_tools_user_progress_word_difficulty_score($row),
                'practice_required_recording_types' => ll_tools_get_progress_row_practice_required_recording_types($row),
                'practice_correct_recording_types' => ll_tools_get_progress_row_practice_correct_recording_types($row),
                'gender_progress' => ll_tools_get_progress_row_gender_progress($row),
            ];
        }

        return $out;
    }
}

if (!function_exists('ll_tools_offline_app_build_sync_response')) {
    function ll_tools_offline_app_build_sync_response(int $user_id, array $state, array $stats, array $word_ids): array {
        $goals = ll_tools_offline_app_parse_goals_request($user_id);
        $categories = function_exists('ll_tools_user_study_categories_for_wordset')
            ? ll_tools_user_study_categories_for_wordset((int) ($state['wordset_id'] ?? 0))
            : [];
        $queue = function_exists('ll_tools_refresh_user_recommendation_queue')
            ? ll_tools_refresh_user_recommendation_queue($user_id, (int) ($state['wordset_id'] ?? 0), (array) ($state['category_ids'] ?? []), $categories, 8)
            : [];
        $recommendation = function_exists('ll_tools_recommendation_queue_pick_next')
            ? ll_tools_recommendation_queue_pick_next($queue)
            : null;
        if (!$recommendation && function_exists('ll_tools_build_next_activity_recommendation')) {
            $recommendation = ll_tools_build_next_activity_recommendation(
                $user_id,
                (int) ($state['wordset_id'] ?? 0),
                (array) ($state['category_ids'] ?? []),
                $categories
            );
        }
        if ($recommendation && function_exists('ll_tools_save_user_last_recommendation_activity')) {
            ll_tools_save_user_last_recommendation_activity($recommendation, $user_id, (int) ($state['wordset_id'] ?? 0));
        }

        return [
            'auth' => [
                'user' => ll_tools_offline_app_build_user_summary($user_id),
            ],
            'stats' => $stats,
            'state' => $state,
            'goals' => $goals,
            'scope_word_ids' => array_values(array_map('intval', $word_ids)),
            'progress_words' => ll_tools_offline_app_build_word_progress_snapshots($user_id, $word_ids),
            'category_progress' => ll_tools_offline_app_build_category_progress_snapshots($user_id, $state),
            'next_activity' => $recommendation,
            'recommendation_queue' => $queue,
            'server_time' => gmdate('c'),
        ];
    }
}

if (!function_exists('ll_tools_offline_app_login_ajax')) {
    function ll_tools_offline_app_login_ajax(): void {
        ll_tools_offline_app_prepare_json_response();

        $request_ip = ll_tools_offline_app_get_client_ip();
        $rate_limit_status = ll_tools_offline_app_get_login_rate_limit_status($request_ip);
        if (!empty($rate_limit_status['limited'])) {
            wp_send_json_error(['message' => ll_tools_offline_app_login_rate_limit_message()], 429);
        }
        ll_tools_offline_app_record_login_attempt($request_ip);

        $identifier = ll_tools_offline_app_request_string('identifier');
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($identifier === '' || $password === '') {
            wp_send_json_error(['message' => __('Enter your username or email and password.', 'll-tools-text-domain')], 400);
        }

        $user = wp_authenticate($identifier, $password);
        if (is_wp_error($user) || !($user instanceof WP_User)) {
            wp_send_json_error(['message' => __('Invalid login.', 'll-tools-text-domain')], 401);
        }
        if (!ll_tools_user_study_can_access((int) $user->ID)) {
            wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
        }

        $session = ll_tools_offline_app_create_session((int) $user->ID, [
            'device_id' => ll_tools_offline_app_request_string('device_id'),
            'profile_id' => ll_tools_offline_app_request_string('profile_id'),
        ]);
        if (empty($session['token'])) {
            wp_send_json_error(['message' => __('Could not start an offline session right now.', 'll-tools-text-domain')], 503);
        }

        wp_send_json_success([
            'auth_token' => (string) ($session['token'] ?? ''),
            'expires_at' => (string) ($session['expires_at'] ?? ''),
            'user' => ll_tools_offline_app_build_user_summary((int) $user->ID),
        ]);
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_login', 'll_tools_offline_app_login_ajax');
add_action('wp_ajax_ll_tools_offline_app_login', 'll_tools_offline_app_login_ajax');

if (!function_exists('ll_tools_offline_app_logout_ajax')) {
    function ll_tools_offline_app_logout_ajax(): void {
        ll_tools_offline_app_prepare_json_response();
        $auth = ll_tools_offline_app_require_authenticated_user(false);
        if (!ll_tools_offline_app_revoke_session(
            (int) ($auth['user_id'] ?? 0),
            (string) ($auth['session_key'] ?? ''),
            (string) (($auth['session'] ?? [])['secret_hash'] ?? '')
        )) {
            wp_send_json_error(['message' => __('Could not end this offline session right now.', 'll-tools-text-domain')], 503);
        }
        wp_send_json_success(['logged_out' => true]);
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_logout', 'll_tools_offline_app_logout_ajax');
add_action('wp_ajax_ll_tools_offline_app_logout', 'll_tools_offline_app_logout_ajax');

if (!function_exists('ll_tools_offline_app_sync_ajax')) {
    function ll_tools_offline_app_sync_ajax(): void {
        ll_tools_offline_app_prepare_json_response();

        $token = ll_tools_offline_app_request_string('auth_token');
        if (ll_tools_offline_app_sync_payload_exceeds_limit()) {
            wp_send_json_error([
                'code' => 'payload_too_large',
                'message' => ll_tools_offline_app_sync_rate_limit_message('', 'resource_units'),
            ], 413);
        }

        $events_raw = $_POST['events'] ?? '[]';
        $events = ll_tools_offline_app_decode_json_payload($events_raw);
        $events = array_slice($events, 0, 200);

        $requested_word_ids = ll_tools_offline_app_parse_word_ids();
        if (empty($requested_word_ids)) {
            $requested_word_ids = ll_tools_offline_app_sanitize_word_ids(array_map(static function ($event): int {
                return is_array($event) ? (int) ($event['word_id'] ?? 0) : 0;
            }, $events));
        }

        $throttle_status = ll_tools_offline_app_check_sync_throttle(
            $token,
            ll_tools_offline_app_sync_resource_units($events, $requested_word_ids)
        );
        if (!empty($throttle_status['limited'])) {
            $retry_after = max(0, (int) ($throttle_status['retry_after'] ?? 0));
            if ($retry_after > 0 && !headers_sent()) {
                header('Retry-After: ' . $retry_after);
            }

            wp_send_json_error([
                'code' => 'rate_limited',
                'message' => (string) ($throttle_status['message'] ?? ll_tools_offline_app_sync_rate_limit_message()),
                'scope' => (string) ($throttle_status['scope'] ?? ''),
                'limit_type' => (string) ($throttle_status['limit_type'] ?? ''),
                'retry_after' => $retry_after,
            ], 429);
        }

        $auth = ll_tools_offline_app_require_authenticated_user();
        $user_id = (int) ($auth['user_id'] ?? 0);

        // Fail before parse_state_request() can persist user meta. The batch
        // processor repeats this cached guard for non-AJAX callers.
        if (!empty($events)) {
            $schema_status = ll_tools_user_progress_runtime_schema_status();
            if (empty($schema_status['ready'])) {
                $stats = ll_tools_user_progress_core_engine_failure_stats($events, $schema_status);
                ll_tools_user_progress_send_retryable_failure($stats);
            }

            $core_engine_status = ll_tools_user_progress_core_engine_status();
            if (empty($core_engine_status['ready'])) {
                $stats = ll_tools_user_progress_core_engine_failure_stats($events, $core_engine_status);
                ll_tools_user_progress_send_retryable_failure($stats);
            }
        }

        $state = ll_tools_offline_app_parse_state_request($user_id);
        $stats = ll_tools_process_progress_events_batch($user_id, $events);

        wp_send_json_success(ll_tools_offline_app_build_sync_response($user_id, $state, $stats, $requested_word_ids));
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_sync', 'll_tools_offline_app_sync_ajax');
add_action('wp_ajax_ll_tools_offline_app_sync', 'll_tools_offline_app_sync_ajax');
