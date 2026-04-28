<?php
if (!defined('WPINC')) {
    die;
}

require_once LL_TOOLS_BASE_PATH . 'includes/cli/cli-support.php';

function ll_tools_rest_automation_load_import_helpers(): void {
    if (!function_exists('ll_tools_import_job_get_snapshot')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/export-import.php';
    }
}

function ll_tools_rest_automation_load_word_option_rules_helpers(): void {
    if (!function_exists('ll_tools_update_word_option_rules')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/lib/word-option-rules.php';
    }
}

function ll_tools_rest_automation_get_route_path(): string {
    $rest_route = isset($_GET['rest_route']) ? wp_unslash((string) $_GET['rest_route']) : '';
    if ($rest_route !== '') {
        return '/' . ltrim($rest_route, '/');
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return '';
    }

    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '';
    }

    $prefix = '/' . trim((string) rest_get_url_prefix(), '/') . '/';
    $prefix_offset = strpos($path, $prefix);
    if ($prefix_offset === false) {
        $prefix_root = '/' . trim((string) rest_get_url_prefix(), '/');
        return untrailingslashit($path) === $prefix_root ? '/' : '';
    }

    $route = substr($path, $prefix_offset + strlen($prefix) - 1);
    return is_string($route) ? '/' . ltrim($route, '/') : '';
}

function ll_tools_rest_automation_is_request(): bool {
    $route_path = ll_tools_rest_automation_get_route_path();
    return $route_path === '/ll-tools/v1' || strpos($route_path, '/ll-tools/v1/') === 0;
}

function ll_tools_rest_automation_error(string $code, string $message, int $status): WP_Error {
    return new WP_Error($code, $message, ['status' => $status]);
}

function ll_tools_rest_automation_with_status($result, int $status) {
    if (!is_wp_error($result)) {
        return $result;
    }

    $data = $result->get_error_data();
    if (is_array($data) && isset($data['status'])) {
        return $result;
    }

    $first_code = $result->get_error_code();
    $first_message = $first_code !== '' ? $result->get_error_message($first_code) : __('Unexpected REST automation error.', 'll-tools-text-domain');

    return new WP_Error(
        $first_code !== '' ? $first_code : 'll_tools_rest_automation_error',
        $first_message,
        ['status' => $status]
    );
}

function ll_tools_rest_automation_has_local_host_context(): bool {
    $environment_type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
    if ($environment_type === 'local') {
        return true;
    }

    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        return false;
    }

    $host = preg_replace('/:\d+$/', '', $host);
    if (!is_string($host) || $host === '') {
        return false;
    }

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    return (bool) preg_match('/(?:^|\.)local$/', $host);
}

function ll_tools_rest_automation_password_auth_is_allowed(): bool {
    $allowed = is_ssl() || ll_tools_rest_automation_has_local_host_context();

    return (bool) apply_filters('ll_tools_rest_allow_password_auth', $allowed, ll_tools_rest_automation_get_route_path());
}

function ll_tools_rest_automation_get_basic_auth_credentials(): array {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return [
            'username' => wp_unslash((string) $_SERVER['PHP_AUTH_USER']),
            'password' => wp_unslash((string) $_SERVER['PHP_AUTH_PW']),
        ];
    }

    $header_keys = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
    ];
    foreach ($header_keys as $header_key) {
        if (empty($_SERVER[$header_key])) {
            continue;
        }

        $raw_header = trim((string) $_SERVER[$header_key]);
        if (!preg_match('/^Basic\s+(.+)$/i', $raw_header, $matches)) {
            continue;
        }

        $decoded = base64_decode((string) $matches[1], true);
        if (!is_string($decoded) || strpos($decoded, ':') === false) {
            return [];
        }

        [$username, $password] = explode(':', $decoded, 2);
        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    return [];
}

function ll_tools_rest_automation_clear_auth_runtime_state(): void {
    unset($GLOBALS['ll_tools_rest_automation_auth_error']);
    unset($GLOBALS['ll_tools_rest_automation_auth_mode']);
}

function ll_tools_rest_automation_determine_current_user($user_id) {
    if (!empty($user_id)) {
        return $user_id;
    }

    ll_tools_rest_automation_clear_auth_runtime_state();

    if (!ll_tools_rest_automation_is_request()) {
        return $user_id;
    }

    $credentials = ll_tools_rest_automation_get_basic_auth_credentials();
    if (empty($credentials['username']) && empty($credentials['password'])) {
        return $user_id;
    }

    if (!ll_tools_rest_automation_password_auth_is_allowed()) {
        $GLOBALS['ll_tools_rest_automation_auth_error'] = ll_tools_rest_automation_error(
            'll_tools_rest_password_auth_requires_https',
            __('LL Tools password-based REST authentication requires HTTPS, except in local development.', 'll-tools-text-domain'),
            403
        );
        return $user_id;
    }

    $authenticated = wp_authenticate((string) $credentials['username'], (string) $credentials['password']);
    if ($authenticated instanceof WP_User) {
        $GLOBALS['ll_tools_rest_automation_auth_mode'] = 'basic_password';
        return (int) $authenticated->ID;
    }

    $message = $authenticated instanceof WP_Error
        ? $authenticated->get_error_message()
        : __('Unable to authenticate this REST request.', 'll-tools-text-domain');

    $GLOBALS['ll_tools_rest_automation_auth_error'] = ll_tools_rest_automation_error(
        'll_tools_rest_invalid_basic_credentials',
        $message,
        401
    );

    return $user_id;
}
add_filter('determine_current_user', 'll_tools_rest_automation_determine_current_user', 30);

function ll_tools_rest_automation_authentication_errors($result) {
    if (!empty($result)) {
        return $result;
    }

    if (!ll_tools_rest_automation_is_request()) {
        return $result;
    }

    $auth_error = $GLOBALS['ll_tools_rest_automation_auth_error'] ?? null;
    if ($auth_error instanceof WP_Error) {
        return $auth_error;
    }

    return $result;
}
add_filter('rest_authentication_errors', 'll_tools_rest_automation_authentication_errors');

function ll_tools_rest_automation_forbidden_message(): string {
    return __('You are not allowed to use LL Tools automation on this site.', 'll-tools-text-domain');
}

function ll_tools_rest_automation_require_view_access() {
    if (!is_user_logged_in()) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_automation_auth_required',
            __('Authenticate with a WordPress login, application password, or LL Tools basic password access before calling this endpoint.', 'll-tools-text-domain'),
            rest_authorization_required_code()
        );
    }

    if (!current_user_can('view_ll_tools')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_automation_forbidden',
            ll_tools_rest_automation_forbidden_message(),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_resolve_wordset_term(WP_REST_Request $request) {
    $wordset_spec = $request->get_param('wordset');
    $wordset_term = ll_tools_cli_resolve_wordset_term($wordset_spec);
    if (is_wp_error($wordset_term)) {
        return ll_tools_rest_automation_with_status($wordset_term, 404);
    }

    return $wordset_term;
}

function ll_tools_rest_automation_require_wordset_access(WP_REST_Request $request) {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    if (!function_exists('ll_tools_user_can_manage_wordset_content') || !ll_tools_user_can_manage_wordset_content((int) $wordset_term->term_id, (int) get_current_user_id())) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_wordset_forbidden',
            __('You cannot manage automation for this word set.', 'll-tools-text-domain'),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_require_review_notes_access(WP_REST_Request $request) {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    if ($request->get_method() !== 'POST') {
        if (!function_exists('ll_tools_current_user_can_read_internal_review_notes') || !ll_tools_current_user_can_read_internal_review_notes((int) $wordset_term->term_id)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_review_notes_forbidden',
                __('You cannot view internal review notes for this word set.', 'll-tools-text-domain'),
                403
            );
        }

        return true;
    }

    if (!function_exists('ll_tools_current_user_can_manage_internal_review_notes') || !ll_tools_current_user_can_manage_internal_review_notes((int) $wordset_term->term_id)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_review_notes_forbidden',
            __('You cannot manage internal review notes for this word set.', 'll-tools-text-domain'),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_require_wordset_create_access() {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    if (!current_user_can('edit_wordsets')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_wordset_create_forbidden',
            __('You cannot create word sets through LL Tools automation.', 'll-tools-text-domain'),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_require_import_access() {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    ll_tools_rest_automation_load_import_helpers();
    if (!function_exists('ll_tools_current_user_can_export_import') || !ll_tools_current_user_can_export_import()) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_import_forbidden',
            __('You cannot import LL Tools bundles through automation.', 'll-tools-text-domain'),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_request_string(WP_REST_Request $request, string $key): string {
    $value = $request->get_param($key);
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

function ll_tools_rest_automation_request_string_list(WP_REST_Request $request, string $key, array $allowed_fields = []) {
    $value = $request->get_param($key);
    if (is_array($value)) {
        $raw_values = array_map('strval', $value);
        $raw = implode(',', $raw_values);
    } else {
        $raw = is_scalar($value) ? trim((string) $value) : '';
    }

    if ($raw === '') {
        return [];
    }

    if (empty($allowed_fields)) {
        $parts = preg_split('/\s*,\s*/', $raw);
        $parts = is_array($parts) ? $parts : [];
        return array_values(array_filter(array_map('strval', $parts), static function (string $part): bool {
            return $part !== '';
        }));
    }

    return ll_tools_cli_normalize_field_list($raw, $allowed_fields);
}

function ll_tools_rest_automation_parse_set_value(WP_REST_Request $request) {
    $set_param = $request->get_param('set');
    if (is_array($set_param)) {
        $field = sanitize_key((string) ($set_param['field'] ?? ''));
        $value = isset($set_param['value']) && is_scalar($set_param['value'])
            ? trim((string) $set_param['value'])
            : '';

        if ($field === '') {
            return ll_tools_rest_automation_error(
                'll_tools_rest_missing_set_field',
                __('Missing set.field in the REST update payload.', 'll-tools-text-domain'),
                400
            );
        }

        if (!in_array($field, ll_tools_cli_supported_update_fields(), true)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_invalid_set_field',
                sprintf(
                    /* translators: 1: field name, 2: comma-separated field list */
                    __('Unsupported update field "%1$s". Allowed fields: %2$s', 'll-tools-text-domain'),
                    $field,
                    implode(', ', ll_tools_cli_supported_update_fields())
                ),
                400
            );
        }

        return [
            'field' => $field,
            'value' => $value,
        ];
    }

    if (!is_scalar($set_param)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_missing_set_argument',
            __('Provide set as either "field=value" or an object with field/value.', 'll-tools-text-domain'),
            400
        );
    }

    return ll_tools_rest_automation_with_status(
        ll_tools_cli_parse_set_argument((string) $set_param),
        400
    );
}

function ll_tools_rest_automation_normalize_resume_state($raw_resume_state): array {
    if (!is_array($raw_resume_state)) {
        return [
            'version' => 1,
            'processed_ids' => [],
        ];
    }

    $processed_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($raw_resume_state['processed_ids'] ?? [])), static function (int $word_id): bool {
        return $word_id > 0;
    })));

    return [
        'version' => 1,
        'processed_ids' => $processed_ids,
    ];
}

function ll_tools_rest_automation_resume_mark_processed(array &$resume_state, int $word_id): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return;
    }

    $processed_ids = array_map('intval', (array) ($resume_state['processed_ids'] ?? []));
    if (!in_array($word_id, $processed_ids, true)) {
        $processed_ids[] = $word_id;
    }

    $resume_state['version'] = 1;
    $resume_state['processed_ids'] = array_values(array_unique(array_filter($processed_ids, static function (int $id): bool {
        return $id > 0;
    })));
    $resume_state['updated_at_gmt'] = gmdate('c');
}

function ll_tools_rest_automation_batch_limit(string $context, bool $dry_run): array {
    $defaults = [
        'bulk_update' => [
            'default' => $dry_run ? 50 : 10,
            'max' => $dry_run ? 100 : 10,
        ],
        'missing_meta' => [
            'default' => 100,
            'max' => 250,
        ],
    ];

    $settings = $defaults[$context] ?? [
        'default' => 25,
        'max' => 50,
    ];

    $settings['default'] = max(1, (int) apply_filters('ll_tools_rest_automation_default_batch_limit', (int) $settings['default'], $context, $dry_run));
    $settings['max'] = max(1, (int) apply_filters('ll_tools_rest_automation_max_batch_limit', (int) $settings['max'], $context, $dry_run));
    if ($settings['default'] > $settings['max']) {
        $settings['default'] = $settings['max'];
    }

    return $settings;
}

function ll_tools_rest_automation_resolve_batch_limit(WP_REST_Request $request, string $context, bool $dry_run): array {
    $settings = ll_tools_rest_automation_batch_limit($context, $dry_run);
    $raw_limit = $request->get_param('limit');
    $requested_limit = (is_scalar($raw_limit) && trim((string) $raw_limit) !== '')
        ? max(0, (int) $raw_limit)
        : 0;

    $effective_limit = $requested_limit > 0 ? $requested_limit : (int) $settings['default'];
    $clamped = $effective_limit > (int) $settings['max'];
    $effective_limit = min($effective_limit, (int) $settings['max']);

    return [
        'requested' => $requested_limit,
        'effective' => $effective_limit,
        'default' => (int) $settings['default'],
        'max' => (int) $settings['max'],
        'clamped' => $clamped,
    ];
}

function ll_tools_rest_resource_guard_option_name(string $scope, string $suffix): string {
    return 'll_tools_rest_guard_' . sanitize_key($suffix) . '_' . md5($scope);
}

function ll_tools_rest_resource_guard_has_authorization_header(): bool {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER'] as $key) {
        if (!empty($_SERVER[$key])) {
            return true;
        }
    }

    return false;
}

function ll_tools_rest_resource_guard_policy(WP_REST_Request $request): array {
    if (!(bool) apply_filters('ll_tools_rest_resource_guard_enabled', true, $request)) {
        return [];
    }

    $method = strtoupper((string) $request->get_method());
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return [];
    }

    if (rest_sanitize_boolean($request->get_param('dry_run'))) {
        return [];
    }

    if (!ll_tools_rest_resource_guard_has_authorization_header()) {
        return [];
    }

    $route = (string) $request->get_route();
    $resource = '';
    $delay_seconds = 1.25;

    if (preg_match('#^/wp/v2/(media|word_images|words)(?:/\d+)?$#', $route, $matches)) {
        $resource = (string) $matches[1];
        $delay_seconds = in_array($resource, ['media', 'word_images'], true) ? 2.0 : 1.25;
    } elseif ($route === '/ll-tools/v1/wordsets') {
        $resource = 'll_tools_wordset_create';
        $delay_seconds = 2.0;
    } elseif (preg_match('#^/ll-tools/v1/wordsets/[^/]+/(bulk-update|word-option-rules|review-notes)$#', $route, $matches)) {
        $resource = 'll_tools_' . sanitize_key((string) $matches[1]);
        $delay_seconds = 1.25;
    }

    if ($resource === '') {
        return [];
    }

    return [
        'scope' => (string) apply_filters('ll_tools_rest_resource_guard_scope', 'll_tools_rest_automation_write', $request, $resource),
        'resource' => $resource,
        'route' => $route,
        'delay_seconds' => max(0.1, (float) apply_filters('ll_tools_rest_resource_guard_delay_seconds', $delay_seconds, $request, $resource)),
        'lock_ttl_seconds' => max(10.0, (float) apply_filters('ll_tools_rest_resource_guard_lock_ttl_seconds', 90.0, $request, $resource)),
    ];
}

function ll_tools_rest_resource_guard_wait_response(array $policy, float $retry_after_seconds): WP_REST_Response {
    $retry_after_seconds = max(1.0, $retry_after_seconds);
    $retry_after_header = (string) max(1, (int) ceil($retry_after_seconds));

    $response = new WP_REST_Response([
        'code' => 'll_tools_rest_resource_guard_wait',
        'message' => __('LL Tools automation is already processing a write request. Wait and retry this request instead of running parallel updates.', 'll-tools-text-domain'),
        'data' => [
            'status' => 429,
            'retry_after_seconds' => (float) round($retry_after_seconds, 3),
            'scope' => (string) ($policy['scope'] ?? ''),
            'resource' => (string) ($policy['resource'] ?? ''),
            'route' => (string) ($policy['route'] ?? ''),
        ],
    ], 429);
    $response->header('Retry-After', $retry_after_header);
    $response->header('X-LL-Tools-Rest-Guard', 'wait');

    return $response;
}

function ll_tools_rest_resource_guard_wait_error(array $policy, float $retry_after_seconds): WP_Error {
    $retry_after_seconds = max(1.0, $retry_after_seconds);

    return new WP_Error(
        'll_tools_rest_resource_guard_wait',
        __('LL Tools automation is already processing a write request. Wait and retry this request instead of running parallel updates.', 'll-tools-text-domain'),
        [
            'status' => 429,
            'retry_after_seconds' => (float) round($retry_after_seconds, 3),
            'scope' => (string) ($policy['scope'] ?? ''),
            'resource' => (string) ($policy['resource'] ?? ''),
            'route' => (string) ($policy['route'] ?? ''),
        ]
    );
}

function ll_tools_rest_resource_guard_before_callbacks($response, array $handler, WP_REST_Request $request) {
    unset($handler);

    if (!empty($response)) {
        return $response;
    }

    $policy = ll_tools_rest_resource_guard_policy($request);
    if (empty($policy['scope'])) {
        return $response;
    }

    $scope = (string) $policy['scope'];
    $lock_option = ll_tools_rest_resource_guard_option_name($scope, 'lock');
    $next_option = ll_tools_rest_resource_guard_option_name($scope, 'next');
    $now = microtime(true);
    $next_allowed = (float) get_option($next_option, 0);
    if ($next_allowed > $now) {
        return ll_tools_rest_resource_guard_wait_error($policy, $next_allowed - $now);
    }

    $owner = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(24, false, false);
    $lock = [
        'owner' => $owner,
        'route' => (string) ($policy['route'] ?? ''),
        'resource' => (string) ($policy['resource'] ?? ''),
        'user_id' => (int) get_current_user_id(),
        'created_at' => $now,
        'expires_at' => $now + (float) ($policy['lock_ttl_seconds'] ?? 90.0),
    ];

    $added = add_option($lock_option, $lock, '', 'no');
    if (!$added) {
        $existing = get_option($lock_option, []);
        $expires_at = is_array($existing) ? (float) ($existing['expires_at'] ?? 0) : 0.0;
        if ($expires_at > $now) {
            $active_retry_after = min(
                $expires_at - $now,
                max(1.0, (float) ($policy['delay_seconds'] ?? 1.25))
            );
            return ll_tools_rest_resource_guard_wait_error($policy, $active_retry_after);
        }

        delete_option($lock_option);
        $added = add_option($lock_option, $lock, '', 'no');
        if (!$added) {
            return ll_tools_rest_resource_guard_wait_error($policy, 1.0);
        }
    }

    if (!isset($GLOBALS['ll_tools_rest_resource_guard_locks']) || !is_array($GLOBALS['ll_tools_rest_resource_guard_locks'])) {
        $GLOBALS['ll_tools_rest_resource_guard_locks'] = [];
    }
    $GLOBALS['ll_tools_rest_resource_guard_locks'][] = [
        'scope' => $scope,
        'owner' => $owner,
        'lock_option' => $lock_option,
        'next_option' => $next_option,
        'delay_seconds' => (float) ($policy['delay_seconds'] ?? 1.25),
    ];

    return $response;
}
add_filter('rest_request_before_callbacks', 'll_tools_rest_resource_guard_before_callbacks', 8, 3);

function ll_tools_rest_resource_guard_after_callbacks($response, array $handler, WP_REST_Request $request) {
    unset($handler, $request);

    if (!is_wp_error($response) || !in_array('ll_tools_rest_resource_guard_wait', $response->get_error_codes(), true)) {
        return $response;
    }

    $data = $response->get_error_data('ll_tools_rest_resource_guard_wait');
    $policy = is_array($data) ? $data : [];

    return ll_tools_rest_resource_guard_wait_response(
        [
            'scope' => (string) ($policy['scope'] ?? ''),
            'resource' => (string) ($policy['resource'] ?? ''),
            'route' => (string) ($policy['route'] ?? ''),
        ],
        (float) ($policy['retry_after_seconds'] ?? 1.0)
    );
}
add_filter('rest_request_after_callbacks', 'll_tools_rest_resource_guard_after_callbacks', 1000, 3);

function ll_tools_rest_resource_guard_release($response, $server, WP_REST_Request $request) {
    unset($server, $request);

    $locks = isset($GLOBALS['ll_tools_rest_resource_guard_locks']) && is_array($GLOBALS['ll_tools_rest_resource_guard_locks'])
        ? $GLOBALS['ll_tools_rest_resource_guard_locks']
        : [];
    if (empty($locks)) {
        return $response;
    }

    foreach ($locks as $lock) {
        if (!is_array($lock)) {
            continue;
        }

        $lock_option = (string) ($lock['lock_option'] ?? '');
        $next_option = (string) ($lock['next_option'] ?? '');
        $owner = (string) ($lock['owner'] ?? '');
        if ($lock_option === '' || $next_option === '' || $owner === '') {
            continue;
        }

        $existing = get_option($lock_option, []);
        if (is_array($existing) && (string) ($existing['owner'] ?? '') === $owner) {
            delete_option($lock_option);
        }

        update_option($next_option, microtime(true) + max(0.1, (float) ($lock['delay_seconds'] ?? 1.25)), false);
    }

    $GLOBALS['ll_tools_rest_resource_guard_locks'] = [];
    return $response;
}
add_filter('rest_post_dispatch', 'll_tools_rest_resource_guard_release', 999, 3);
add_filter('rest_request_after_callbacks', 'll_tools_rest_resource_guard_release', 999, 3);

function ll_tools_rest_resource_guard_clear_state(): void {
    $scope = 'll_tools_rest_automation_write';
    delete_option(ll_tools_rest_resource_guard_option_name($scope, 'lock'));
    delete_option(ll_tools_rest_resource_guard_option_name($scope, 'next'));
    $GLOBALS['ll_tools_rest_resource_guard_locks'] = [];
}

function ll_tools_rest_automation_rest_import_word_image_chunk_size($chunk_size, array $job, array $payload): int {
    unset($job, $payload);

    if (!ll_tools_rest_automation_is_request()) {
        return (int) $chunk_size;
    }

    return min((int) $chunk_size, (int) apply_filters('ll_tools_rest_import_word_image_chunk_size', 8));
}
add_filter('ll_tools_import_job_word_image_chunk_size', 'll_tools_rest_automation_rest_import_word_image_chunk_size', 10, 3);

function ll_tools_rest_automation_status(WP_REST_Request $request): WP_REST_Response {
    unset($request);

    $user = wp_get_current_user();
    $auth_mode = isset($GLOBALS['ll_tools_rest_automation_auth_mode']) && is_string($GLOBALS['ll_tools_rest_automation_auth_mode'])
        ? $GLOBALS['ll_tools_rest_automation_auth_mode']
        : (is_user_logged_in() ? 'cookie_or_application_password' : 'none');

    return rest_ensure_response([
        'namespace' => 'll-tools/v1',
        'plugin_version' => defined('LL_TOOLS_VERSION') ? LL_TOOLS_VERSION : '',
        'auth_mode' => $auth_mode,
        'password_basic_auth_allowed' => ll_tools_rest_automation_password_auth_is_allowed(),
        'user' => [
            'id' => $user instanceof WP_User ? (int) $user->ID : 0,
            'login' => $user instanceof WP_User ? (string) $user->user_login : '',
            'display_name' => $user instanceof WP_User ? (string) $user->display_name : '',
            'roles' => $user instanceof WP_User ? array_values(array_map('strval', (array) $user->roles)) : [],
        ],
        'capabilities' => [
            'view_ll_tools' => current_user_can('view_ll_tools'),
            'edit_posts' => current_user_can('edit_posts'),
            'edit_wordsets' => current_user_can('edit_wordsets'),
            'manage_options' => current_user_can('manage_options'),
        ],
        'routes' => [
            'status' => '/ll-tools/v1/automation/status',
            'create_wordset' => '/ll-tools/v1/wordsets',
            'missing_meta' => '/ll-tools/v1/wordsets/{wordset}/missing-meta',
            'bulk_update' => '/ll-tools/v1/wordsets/{wordset}/bulk-update',
            'word_option_rules' => '/ll-tools/v1/wordsets/{wordset}/word-option-rules',
            'report' => '/ll-tools/v1/wordsets/{wordset}/report',
            'report_summary' => '/ll-tools/v1/wordsets/{wordset}/report-summary',
            'review_notes' => '/ll-tools/v1/wordsets/{wordset}/review-notes',
            'import_preview' => '/ll-tools/v1/imports/preview',
            'import_start' => '/ll-tools/v1/imports/start',
            'import_status' => '/ll-tools/v1/imports/{job_id}',
            'import_process' => '/ll-tools/v1/imports/{job_id}/process',
            'import_discard' => '/ll-tools/v1/imports/{job_id}/discard',
            'import_result' => '/ll-tools/v1/imports/{job_id}/result',
        ],
        'resource_guard' => [
            'retry_status' => 429,
            'core_write_routes' => [
                '/wp/v2/media',
                '/wp/v2/word_images',
                '/wp/v2/words',
            ],
            'bulk_update_batch' => [
                'default_write_limit' => ll_tools_rest_automation_batch_limit('bulk_update', false)['default'],
                'max_write_limit' => ll_tools_rest_automation_batch_limit('bulk_update', false)['max'],
                'default_dry_run_limit' => ll_tools_rest_automation_batch_limit('bulk_update', true)['default'],
                'max_dry_run_limit' => ll_tools_rest_automation_batch_limit('bulk_update', true)['max'],
            ],
            'missing_meta_batch' => [
                'default_limit' => ll_tools_rest_automation_batch_limit('missing_meta', false)['default'],
                'max_limit' => ll_tools_rest_automation_batch_limit('missing_meta', false)['max'],
            ],
            'rest_import_word_image_chunk_size' => (int) apply_filters('ll_tools_rest_import_word_image_chunk_size', 8),
        ],
    ]);
}

function ll_tools_rest_automation_create_wordset(WP_REST_Request $request) {
    $name = sanitize_text_field(ll_tools_rest_automation_request_string($request, 'name'));
    if ($name === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_missing_wordset_name',
            __('Provide a name for the new word set.', 'll-tools-text-domain'),
            400
        );
    }

    $slug = sanitize_title(ll_tools_rest_automation_request_string($request, 'slug'));
    $manager_id = 0;
    $manager_spec = ll_tools_rest_automation_request_string($request, 'manager');
    if ($manager_spec !== '') {
        $manager_id = ll_tools_cli_resolve_user_id($manager_spec);
        if (is_wp_error($manager_id)) {
            return ll_tools_rest_automation_with_status($manager_id, 404);
        }
        $manager_id = (int) $manager_id;
    }

    $template_spec = ll_tools_rest_automation_request_string($request, 'template');
    if ($template_spec !== '') {
        $template_term = ll_tools_cli_resolve_wordset_term($template_spec);
        if (is_wp_error($template_term)) {
            return ll_tools_rest_automation_with_status($template_term, 404);
        }

        $result = ll_tools_create_wordset_from_template((int) $template_term->term_id, [
            'name' => $name,
            'slug' => $slug,
            'manager_user_id' => $manager_id,
            'copy_settings' => true,
        ]);
        if (is_wp_error($result)) {
            return ll_tools_rest_automation_with_status($result, 400);
        }

        $created_wordset_id = (int) ($result['wordset_id'] ?? 0);
        if ($manager_id > 0) {
            ll_tools_cli_assign_wordset_manager($created_wordset_id, $manager_id);
        }

        $created_term = get_term($created_wordset_id, 'wordset');
        return rest_ensure_response([
            'wordset_id' => $created_wordset_id,
            'wordset_slug' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->slug : '',
            'wordset_name' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->name : '',
            'template_wordset_id' => (int) $template_term->term_id,
            'template_wordset_slug' => (string) $template_term->slug,
            'categories_created' => (int) ($result['categories_created'] ?? 0),
            'images_created' => (int) ($result['images_created'] ?? 0),
            'failed_categories' => (int) ($result['failed_categories'] ?? 0),
            'failed_images' => (int) ($result['failed_images'] ?? 0),
            'manager_user_id' => $manager_id,
        ]);
    }

    $insert_args = [];
    if ($slug !== '') {
        $insert_args['slug'] = $slug;
    }
    $inserted = wp_insert_term($name, 'wordset', $insert_args);
    if (is_wp_error($inserted)) {
        return ll_tools_rest_automation_with_status($inserted, 400);
    }

    $created_wordset_id = (int) ($inserted['term_id'] ?? 0);
    if (function_exists('ll_tools_ensure_vocab_lessons_enabled_for_wordset')) {
        ll_tools_ensure_vocab_lessons_enabled_for_wordset($created_wordset_id, false);
    }
    if ($manager_id > 0) {
        ll_tools_cli_assign_wordset_manager($created_wordset_id, $manager_id);
    }

    $created_term = get_term($created_wordset_id, 'wordset');
    return rest_ensure_response([
        'wordset_id' => $created_wordset_id,
        'wordset_slug' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->slug : '',
        'wordset_name' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->name : '',
        'manager_user_id' => $manager_id,
    ]);
}

function ll_tools_rest_automation_wordset_missing_meta(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $category_spec = ll_tools_rest_automation_request_string($request, 'category');
    $word_ids = ll_tools_cli_get_word_ids_for_scope((int) $wordset_term->term_id, $category_spec, '');
    if (is_wp_error($word_ids)) {
        return ll_tools_rest_automation_with_status($word_ids, 400);
    }

    $rows = ll_tools_cli_get_word_rows((int) $wordset_term->term_id, $word_ids);
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return !empty($row['has_missing']);
    }));

    $missing_fields = ll_tools_rest_automation_request_string_list($request, 'fields', ll_tools_cli_supported_missing_fields());
    if (is_wp_error($missing_fields)) {
        return ll_tools_rest_automation_with_status($missing_fields, 400);
    }

    if (!empty($missing_fields)) {
        $rows = ll_tools_cli_filter_word_rows($rows, [
            'missing_fields' => $missing_fields,
        ]);
    }

    $total_count = count($rows);
    $offset = max(0, (int) $request->get_param('offset'));
    $limit_info = ll_tools_rest_automation_resolve_batch_limit($request, 'missing_meta', false);
    $limit = (int) $limit_info['effective'];
    $rows = array_slice($rows, $offset, $limit);
    $next_offset = $offset + count($rows);

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'filters' => [
            'category' => $category_spec,
            'fields' => $missing_fields,
            'limit' => $limit,
            'offset' => $offset,
        ],
        'batch' => [
            'requested_limit' => (int) $limit_info['requested'],
            'effective_limit' => $limit,
            'max_limit' => (int) $limit_info['max'],
            'limit_clamped' => (bool) $limit_info['clamped'],
            'has_more' => $next_offset < $total_count,
            'next_offset' => $next_offset < $total_count ? $next_offset : null,
        ],
        'count' => count($rows),
        'total_count' => $total_count,
        'rows' => ll_tools_cli_prepare_word_rows_for_output($rows),
    ]);
}

function ll_tools_rest_automation_word_bulk_update(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $set_args = ll_tools_rest_automation_parse_set_value($request);
    if (is_wp_error($set_args)) {
        return $set_args;
    }

    $category_spec = ll_tools_rest_automation_request_string($request, 'category');
    $word_spec = ll_tools_rest_automation_request_string($request, 'word');
    $word_ids = ll_tools_cli_get_word_ids_for_scope((int) $wordset_term->term_id, $category_spec, $word_spec);
    if (is_wp_error($word_ids)) {
        return ll_tools_rest_automation_with_status($word_ids, 400);
    }

    $rows = ll_tools_cli_get_word_rows((int) $wordset_term->term_id, $word_ids);

    $missing_fields = ll_tools_rest_automation_request_string_list($request, 'where_missing', ll_tools_cli_supported_missing_fields());
    if (is_wp_error($missing_fields)) {
        return ll_tools_rest_automation_with_status($missing_fields, 400);
    }

    $rows = ll_tools_cli_filter_word_rows($rows, [
        'missing_fields' => $missing_fields,
        'part_of_speech' => ll_tools_rest_automation_request_string($request, 'where_pos'),
    ]);

    $resume_state = ll_tools_rest_automation_normalize_resume_state($request->get_param('resume_state'));
    if (!empty($resume_state['processed_ids'])) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($resume_state): bool {
            return !ll_tools_cli_resume_has_processed($resume_state, (int) ($row['word_id'] ?? 0));
        }));
    }

    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $total_matched_count = count($rows);
    $offset = max(0, (int) $request->get_param('offset'));
    $limit_info = ll_tools_rest_automation_resolve_batch_limit($request, 'bulk_update', $dry_run);
    $limit = (int) $limit_info['effective'];
    $rows = ll_tools_cli_slice_rows($rows, $offset, $limit);
    $next_offset = $offset + count($rows);

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'filters' => [
            'category' => $category_spec,
            'word' => $word_spec,
            'where_missing' => $missing_fields,
            'where_pos' => sanitize_title(ll_tools_rest_automation_request_string($request, 'where_pos')),
            'limit' => $limit,
            'offset' => $offset,
        ],
        'batch' => [
            'requested_limit' => (int) $limit_info['requested'],
            'effective_limit' => $limit,
            'max_limit' => (int) $limit_info['max'],
            'limit_clamped' => (bool) $limit_info['clamped'],
            'has_more' => $next_offset < $total_matched_count,
            'next_offset' => $next_offset < $total_matched_count ? $next_offset : null,
        ],
        'set' => $set_args,
        'matched_count' => count($rows),
        'total_matched_count' => $total_matched_count,
        'matched_rows' => ll_tools_cli_prepare_word_rows_for_output($rows),
        'updated_count' => 0,
        'updated' => [],
        'errors' => [],
        'resume_state' => $resume_state,
    ];

    if ($dry_run) {
        return rest_ensure_response($summary);
    }

    foreach ($rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        if ($word_id <= 0) {
            continue;
        }

        $update_result = ll_tools_cli_apply_word_field_update(
            (int) $wordset_term->term_id,
            $word_id,
            (string) $set_args['field'],
            (string) $set_args['value']
        );

        if (is_wp_error($update_result)) {
            $summary['errors'][] = [
                'word_id' => $word_id,
                'word_slug' => (string) ($row['word_slug'] ?? ''),
                'message' => $update_result->get_error_message(),
            ];
            continue;
        }

        $summary['updated'][] = [
            'word_id' => (int) ($update_result['word_id'] ?? 0),
            'word_slug' => (string) ($update_result['word_slug'] ?? ''),
            'changed' => !empty($update_result['changed']),
            'changed_keys' => array_values(array_map('strval', (array) ($update_result['changed_keys'] ?? []))),
            'before' => ll_tools_cli_prepare_word_rows_for_output([(array) ($update_result['before'] ?? [])])[0] ?? [],
            'after' => ll_tools_cli_prepare_word_rows_for_output([(array) ($update_result['after'] ?? [])])[0] ?? [],
        ];
        if (!empty($update_result['changed'])) {
            $summary['updated_count']++;
        }

        ll_tools_rest_automation_resume_mark_processed($resume_state, $word_id);
    }

    $summary['resume_state'] = $resume_state;

    return rest_ensure_response($summary);
}

function ll_tools_rest_automation_wordset_report(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $category_spec = ll_tools_rest_automation_request_string($request, 'category');
    $report = ll_tools_cli_build_wordset_report((int) $wordset_term->term_id, $category_spec);
    if (!empty($report['error'])) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_report_failed',
            (string) $report['error'],
            400
        );
    }

    return rest_ensure_response($report);
}

function ll_tools_rest_automation_prepare_id_list(array $ids): array {
    return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
}

function ll_tools_rest_automation_prepare_sql(string $sql, array $args): string {
    global $wpdb;

    if (empty($args)) {
        return $sql;
    }

    return (string) call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $args));
}

function ll_tools_rest_automation_count_words_with_audio(array $word_ids): int {
    global $wpdb;

    $word_ids = ll_tools_rest_automation_prepare_id_list($word_ids);
    if (empty($word_ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT COUNT(DISTINCT p.post_parent)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
            AND pm.meta_key = 'audio_file_path'
            AND pm.meta_value <> ''
        WHERE p.post_type = 'word_audio'
            AND p.post_status = 'publish'
            AND p.post_parent IN ({$placeholders})
    ";

    return max(0, (int) $wpdb->get_var(ll_tools_rest_automation_prepare_sql($sql, $word_ids)));
}

function ll_tools_rest_automation_count_audio_records(array $word_ids): int {
    global $wpdb;

    $word_ids = ll_tools_rest_automation_prepare_id_list($word_ids);
    if (empty($word_ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
            AND pm.meta_key = 'audio_file_path'
            AND pm.meta_value <> ''
        WHERE p.post_type = 'word_audio'
            AND p.post_status = 'publish'
            AND p.post_parent IN ({$placeholders})
    ";

    return max(0, (int) $wpdb->get_var(ll_tools_rest_automation_prepare_sql($sql, $word_ids)));
}

function ll_tools_rest_automation_count_words_with_images(array $word_ids): int {
    global $wpdb;

    $word_ids = ll_tools_rest_automation_prepare_id_list($word_ids);
    if (empty($word_ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT COUNT(DISTINCT pm.post_id)
        FROM {$wpdb->postmeta} pm
        WHERE pm.post_id IN ({$placeholders})
            AND pm.meta_key IN ('_ll_autopicked_image_id', '_thumbnail_id')
            AND pm.meta_value <> ''
            AND pm.meta_value <> '0'
    ";

    return max(0, (int) $wpdb->get_var(ll_tools_rest_automation_prepare_sql($sql, $word_ids)));
}

function ll_tools_rest_automation_get_category_ids_for_words(array $word_ids): array {
    global $wpdb;

    $word_ids = ll_tools_rest_automation_prepare_id_list($word_ids);
    if (empty($word_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT DISTINCT tt.term_id
        FROM {$wpdb->term_relationships} tr
        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
            AND tt.taxonomy = 'word-category'
        WHERE tr.object_id IN ({$placeholders})
    ";

    return ll_tools_rest_automation_prepare_id_list((array) $wpdb->get_col(ll_tools_rest_automation_prepare_sql($sql, $word_ids)));
}

function ll_tools_rest_automation_array_is_list(array $value): bool {
    if (empty($value)) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function ll_tools_rest_automation_term_summary(WP_Term $term): array {
    return [
        'id' => (int) $term->term_id,
        'slug' => (string) $term->slug,
        'name' => (string) $term->name,
    ];
}

function ll_tools_rest_automation_get_word_option_category_spec(WP_REST_Request $request): string {
    foreach (['category', 'category_id', 'category_slug'] as $key) {
        $value = ll_tools_rest_automation_request_string($request, $key);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function ll_tools_rest_automation_resolve_word_option_category(WP_Term $wordset_term, string $category_spec, bool $create_missing) {
    $category_spec = trim($category_spec);
    if ($category_spec === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_word_option_rules_missing_category',
            __('Provide category, category_id, or category_slug for the word-option rules update.', 'll-tools-text-domain'),
            400
        );
    }

    $term = null;
    if ($create_missing && function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
        $term = ll_tools_resolve_word_category_term_for_wordsets($category_spec, [(int) $wordset_term->term_id]);
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        $term = function_exists('ll_tools_resolve_word_category_term')
            ? ll_tools_resolve_word_category_term($category_spec)
            : null;
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_word_option_rules_category_not_found',
            sprintf(
                /* translators: %s: category identifier */
                __('Unable to resolve word category: %s', 'll-tools-text-domain'),
                $category_spec
            ),
            404
        );
    }

    $source_term = $term;
    if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset((int) $term->term_id, (int) $wordset_term->term_id, $create_missing);
        if ($effective_category_id > 0 && $effective_category_id !== (int) $term->term_id) {
            $effective_term = get_term($effective_category_id, 'word-category');
            if ($effective_term instanceof WP_Term && !is_wp_error($effective_term)) {
                $term = $effective_term;
            }
        }
    }

    if (function_exists('ll_tools_get_category_wordset_owner_id')) {
        $owner_wordset_id = (int) ll_tools_get_category_wordset_owner_id($term);
        if ($owner_wordset_id > 0 && $owner_wordset_id !== (int) $wordset_term->term_id) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_word_option_rules_category_forbidden',
                __('That word category belongs to a different word set.', 'll-tools-text-domain'),
                403
            );
        }
    }

    return [
        'term' => $term,
        'source_term' => $source_term,
    ];
}

function ll_tools_rest_automation_word_ref_preview($ref): string {
    if (is_scalar($ref)) {
        return trim((string) $ref);
    }

    if (is_array($ref)) {
        foreach (['id', 'word_id', 'slug', 'word_slug', 'word', 'title', 'text'] as $key) {
            if (isset($ref[$key]) && is_scalar($ref[$key])) {
                return trim((string) $ref[$key]);
            }
        }
    }

    return '';
}

function ll_tools_rest_automation_collect_word_refs($raw): array {
    if (!is_array($raw)) {
        return is_scalar($raw) ? [$raw] : [];
    }

    foreach ([['a', 'b'], ['word_a', 'word_b'], ['left', 'right']] as $pair_keys) {
        if (array_key_exists($pair_keys[0], $raw) && array_key_exists($pair_keys[1], $raw)) {
            return [$raw[$pair_keys[0]], $raw[$pair_keys[1]]];
        }
    }

    $refs = [];
    foreach (['word_ids', 'words', 'word_slugs', 'slugs', 'ids'] as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }

        $value = $raw[$key];
        if (is_array($value)) {
            foreach ($value as $item) {
                $refs[] = $item;
            }
        } elseif (is_scalar($value)) {
            $refs[] = $value;
        }
    }

    if (empty($refs) && ll_tools_rest_automation_array_is_list($raw)) {
        foreach ($raw as $item) {
            $refs[] = $item;
        }
    }

    return $refs;
}

function ll_tools_rest_automation_prepare_word_summary(int $word_id): array {
    $post = get_post($word_id);

    return [
        'id' => $word_id,
        'slug' => $post instanceof WP_Post ? (string) $post->post_name : '',
        'title' => $post instanceof WP_Post ? (string) get_the_title($post) : '',
    ];
}

function ll_tools_rest_automation_add_missing_word(array &$missing_words, $ref, string $context, string $reason, int $word_id = 0): void {
    $entry = [
        'context' => $context,
        'ref' => ll_tools_rest_automation_word_ref_preview($ref),
        'reason' => $reason,
    ];
    if ($word_id > 0) {
        $entry['word'] = ll_tools_rest_automation_prepare_word_summary($word_id);
    }

    $missing_words[] = $entry;
}

function ll_tools_rest_automation_resolve_word_option_word_ref(int $wordset_id, int $category_id, $ref, string $context, array &$missing_words): int {
    $word_ref = ll_tools_rest_automation_word_ref_preview($ref);
    if ($word_ref === '') {
        ll_tools_rest_automation_add_missing_word($missing_words, $ref, $context, 'empty_ref');
        return 0;
    }

    if (ctype_digit($word_ref)) {
        $word_id = (int) $word_ref;
        if ($word_id <= 0 || get_post_type($word_id) !== 'words' || !has_term($wordset_id, 'wordset', $word_id)) {
            ll_tools_rest_automation_add_missing_word($missing_words, $ref, $context, 'not_found_in_wordset');
            return 0;
        }
    } else {
        $resolved_word_id = ll_tools_cli_resolve_word_id($wordset_id, $word_ref);
        if (is_wp_error($resolved_word_id)) {
            ll_tools_rest_automation_add_missing_word($missing_words, $ref, $context, 'not_found_in_wordset');
            return 0;
        }
        $word_id = (int) $resolved_word_id;
    }

    if ($category_id > 0 && !has_term($category_id, 'word-category', $word_id)) {
        ll_tools_rest_automation_add_missing_word($missing_words, $ref, $context, 'not_in_category', $word_id);
        return 0;
    }

    return $word_id;
}

function ll_tools_rest_automation_resolve_word_refs(int $wordset_id, int $category_id, array $refs, string $context, array &$missing_words): array {
    $word_ids = [];
    foreach ($refs as $index => $ref) {
        $word_id = ll_tools_rest_automation_resolve_word_option_word_ref(
            $wordset_id,
            $category_id,
            $ref,
            $context . '[' . $index . ']',
            $missing_words
        );
        if ($word_id > 0) {
            $word_ids[] = $word_id;
        }
    }

    return ll_tools_rest_automation_prepare_id_list($word_ids);
}

function ll_tools_rest_automation_normalize_word_option_groups_for_rest($groups_raw, int $wordset_id, int $category_id, array &$missing_words, array &$errors): array {
    if (!is_array($groups_raw)) {
        $errors[] = [
            'context' => 'groups',
            'message' => __('Groups must be an array.', 'll-tools-text-domain'),
        ];
        return [];
    }

    $groups = [];
    foreach ($groups_raw as $index => $group_raw) {
        $context = 'groups[' . (is_scalar($index) ? (string) $index : '') . ']';
        $label = '';
        if (is_array($group_raw) && isset($group_raw['label']) && is_scalar($group_raw['label'])) {
            $label = trim(sanitize_text_field((string) $group_raw['label']));
        } elseif (is_string($index) && !is_numeric($index)) {
            $label = trim(sanitize_text_field($index));
        }

        if ($label === '') {
            $errors[] = [
                'context' => $context,
                'message' => __('Every word-option group needs a label.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $word_ids = ll_tools_rest_automation_resolve_word_refs(
            $wordset_id,
            $category_id,
            ll_tools_rest_automation_collect_word_refs($group_raw),
            $context . '.words',
            $missing_words
        );
        if (empty($word_ids)) {
            $errors[] = [
                'context' => $context,
                'message' => __('Word-option groups must contain at least one resolved word.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $groups[] = [
            'label' => $label,
            'word_ids' => $word_ids,
        ];
    }

    return $groups;
}

function ll_tools_rest_automation_normalize_word_option_pairs_for_rest($pairs_raw, int $wordset_id, int $category_id, array &$missing_words, array &$errors, bool $include_recording_types): array {
    ll_tools_rest_automation_load_word_option_rules_helpers();

    if (!is_array($pairs_raw)) {
        $errors[] = [
            'context' => $include_recording_types ? 'pairs' : 'similar_image_overrides',
            'message' => __('Word-option pairs must be an array.', 'll-tools-text-domain'),
        ];
        return [];
    }

    $pairs = [];
    foreach ($pairs_raw as $index => $pair_raw) {
        $context = ($include_recording_types ? 'pairs' : 'similar_image_overrides') . '[' . (is_scalar($index) ? (string) $index : '') . ']';
        $word_ids = ll_tools_rest_automation_resolve_word_refs(
            $wordset_id,
            $category_id,
            ll_tools_rest_automation_collect_word_refs($pair_raw),
            $context . '.words',
            $missing_words
        );
        if (count($word_ids) < 2) {
            $errors[] = [
                'context' => $context,
                'message' => __('Word-option pairs must contain two resolved words.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $pair = [
            'word_ids' => array_slice($word_ids, 0, 2),
        ];
        if ($include_recording_types && is_array($pair_raw)) {
            $pair['unblocked_recording_types'] = function_exists('ll_tools_normalize_word_option_pair_recording_type_list')
                ? ll_tools_normalize_word_option_pair_recording_type_list($pair_raw['unblocked_recording_types'] ?? [])
                : [];
        }
        $pairs[] = $pair;
    }

    return function_exists('ll_tools_normalize_word_option_pair_list')
        ? ll_tools_normalize_word_option_pair_list($pairs)
        : $pairs;
}

function ll_tools_rest_automation_request_has_any_param(WP_REST_Request $request, array $keys): bool {
    $params = $request->get_params();
    foreach ($keys as $key) {
        if (is_array($params) && array_key_exists($key, $params)) {
            return true;
        }
    }

    return false;
}

function ll_tools_rest_automation_first_request_param(WP_REST_Request $request, array $keys) {
    $params = $request->get_params();
    foreach ($keys as $key) {
        if (is_array($params) && array_key_exists($key, $params)) {
            return $params[$key];
        }
    }

    return null;
}

function ll_tools_rest_automation_word_option_rules(WP_REST_Request $request) {
    ll_tools_rest_automation_load_word_option_rules_helpers();

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $category_result = ll_tools_rest_automation_resolve_word_option_category(
        $wordset_term,
        ll_tools_rest_automation_get_word_option_category_spec($request),
        !$dry_run
    );
    if (is_wp_error($category_result)) {
        return $category_result;
    }

    $category_term = $category_result['term'];
    $source_category_term = $category_result['source_term'];
    $wordset_id = (int) $wordset_term->term_id;
    $category_id = (int) $category_term->term_id;
    $current_rules = function_exists('ll_tools_get_word_option_rules')
        ? ll_tools_get_word_option_rules($wordset_id, $category_id)
        : ['groups' => [], 'pairs' => [], 'similar_image_overrides' => []];

    $errors = [];
    $missing_words = [];
    $groups = (array) ($current_rules['groups'] ?? []);
    $pairs = (array) ($current_rules['pairs'] ?? []);
    $similar_image_overrides = (array) ($current_rules['similar_image_overrides'] ?? []);

    if (ll_tools_rest_automation_request_has_any_param($request, ['groups', 'word_option_groups'])) {
        $groups = ll_tools_rest_automation_normalize_word_option_groups_for_rest(
            ll_tools_rest_automation_first_request_param($request, ['groups', 'word_option_groups']),
            $wordset_id,
            $category_id,
            $missing_words,
            $errors
        );
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['pairs', 'blocked_pairs'])) {
        $pairs = ll_tools_rest_automation_normalize_word_option_pairs_for_rest(
            ll_tools_rest_automation_first_request_param($request, ['pairs', 'blocked_pairs']),
            $wordset_id,
            $category_id,
            $missing_words,
            $errors,
            true
        );
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['similar_image_overrides'])) {
        $similar_image_overrides = ll_tools_rest_automation_normalize_word_option_pairs_for_rest(
            ll_tools_rest_automation_first_request_param($request, ['similar_image_overrides']),
            $wordset_id,
            $category_id,
            $missing_words,
            $errors,
            false
        );
    }

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'valid' => empty($errors) && empty($missing_words),
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'category' => ll_tools_rest_automation_term_summary($category_term),
        'source_category' => ll_tools_rest_automation_term_summary($source_category_term),
        'rules' => [
            'groups' => $groups,
            'pairs' => $pairs,
            'similar_image_overrides' => $similar_image_overrides,
        ],
        'counts' => [
            'groups' => count($groups),
            'pairs' => count($pairs),
            'similar_image_overrides' => count($similar_image_overrides),
            'missing_words' => count($missing_words),
            'errors' => count($errors),
        ],
        'missing_words' => $missing_words,
        'errors' => $errors,
        'updated' => false,
    ];

    if (!$summary['valid']) {
        if ($dry_run) {
            return rest_ensure_response($summary);
        }

        return new WP_Error(
            'll_tools_rest_word_option_rules_invalid',
            __('Word-option rules were not saved because the payload did not validate. Review missing_words and errors in the response data.', 'll-tools-text-domain'),
            [
                'status' => 400,
                'summary' => $summary,
                'missing_words' => $missing_words,
                'errors' => $errors,
            ]
        );
    }

    if ($dry_run) {
        return rest_ensure_response($summary);
    }

    $updated = ll_tools_update_word_option_rules($wordset_id, $category_id, $groups, $pairs, $similar_image_overrides);
    if (!$updated) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_word_option_rules_update_failed',
            __('Word-option rules could not be saved for this word set and category.', 'll-tools-text-domain'),
            500
        );
    }

    $summary['updated'] = true;
    $summary['rules'] = function_exists('ll_tools_get_word_option_rules')
        ? ll_tools_get_word_option_rules($wordset_id, $category_id)
        : $summary['rules'];

    return rest_ensure_response($summary);
}

function ll_tools_rest_automation_wordset_report_summary(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $category_spec = ll_tools_rest_automation_request_string($request, 'category');
    $word_ids = ll_tools_cli_get_word_ids_for_scope((int) $wordset_term->term_id, $category_spec, '');
    if (is_wp_error($word_ids)) {
        return ll_tools_rest_automation_with_status($word_ids, 400);
    }
    $word_ids = ll_tools_rest_automation_prepare_id_list((array) $word_ids);

    $category_ids = ll_tools_rest_automation_get_category_ids_for_words($word_ids);
    $words_with_audio = ll_tools_rest_automation_count_words_with_audio($word_ids);
    $words_with_images = ll_tools_rest_automation_count_words_with_images($word_ids);

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'filters' => [
            'category' => $category_spec,
        ],
        'settings' => [
            'visibility' => function_exists('ll_tools_get_wordset_visibility')
                ? (string) ll_tools_get_wordset_visibility((int) $wordset_term->term_id)
                : '',
            'target_language' => function_exists('ll_tools_get_wordset_target_language')
                ? (string) ll_tools_get_wordset_target_language([(int) $wordset_term->term_id], true)
                : '',
            'translation_language' => function_exists('ll_tools_get_wordset_translation_language')
                ? (string) ll_tools_get_wordset_translation_language([(int) $wordset_term->term_id], true)
                : '',
            'word_title_language_role' => function_exists('ll_tools_get_wordset_title_language_role')
                ? (string) ll_tools_get_wordset_title_language_role([(int) $wordset_term->term_id], true)
                : '',
            'category_translation_enabled' => function_exists('ll_tools_is_wordset_category_translation_enabled')
                ? (bool) ll_tools_is_wordset_category_translation_enabled([(int) $wordset_term->term_id], true)
                : false,
            'category_translation_source' => function_exists('ll_tools_get_wordset_category_translation_source')
                ? (string) ll_tools_get_wordset_category_translation_source([(int) $wordset_term->term_id], true)
                : '',
            'recording_transcription_mode' => function_exists('ll_tools_get_wordset_recording_transcription_mode')
                ? (string) ll_tools_get_wordset_recording_transcription_mode([(int) $wordset_term->term_id], true)
                : '',
        ],
        'counts' => [
            'words_total' => count($word_ids),
            'categories_total' => count($category_ids),
            'words_with_audio' => $words_with_audio,
            'words_without_audio' => max(0, count($word_ids) - $words_with_audio),
            'words_with_images' => $words_with_images,
            'words_without_images' => max(0, count($word_ids) - $words_with_images),
            'audio_records_total' => ll_tools_rest_automation_count_audio_records($word_ids),
        ],
    ]);
}

function ll_tools_rest_automation_review_notes(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    if ($request->get_method() === 'POST') {
        $object_id_param = $request->get_param('object_id');
        $object_id = is_scalar($object_id_param) ? absint($object_id_param) : 0;
        $submitted_type = ll_tools_internal_review_note_normalize_object_type(
            ll_tools_rest_automation_request_string($request, 'object_type')
        );
        $note_param = $request->get_param('note');
        if (!is_scalar($note_param)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_review_note_missing_note',
                __('Provide a note value. Send an empty string to clear the note.', 'll-tools-text-domain'),
                400
            );
        }
        if ($object_id <= 0) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_review_note_missing_object',
                __('Provide the word or prompt card object_id to update.', 'll-tools-text-domain'),
                400
            );
        }

        $object_type = function_exists('ll_tools_internal_review_note_object_type')
            ? ll_tools_internal_review_note_object_type($object_id)
            : '';
        if ($object_type === '' || ($submitted_type !== '' && $submitted_type !== $object_type)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_review_note_invalid_object',
                __('This item cannot store an internal review note.', 'll-tools-text-domain'),
                400
            );
        }
        if (!ll_tools_internal_review_note_object_belongs_to_wordset($object_id, $wordset_id)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_review_note_wrong_wordset',
                __('This item does not belong to the selected word set.', 'll-tools-text-domain'),
                400
            );
        }

        $saved_note = ll_tools_set_internal_review_note($object_id, (string) $note_param);
        return rest_ensure_response([
            'wordset' => [
                'id' => $wordset_id,
                'slug' => (string) $wordset_term->slug,
                'name' => (string) $wordset_term->name,
            ],
            'object_type' => $object_type,
            'object_id' => $object_id,
            'note' => $saved_note,
            'row' => ll_tools_build_internal_review_note_row($object_id, $wordset_id),
        ]);
    }

    $category_spec = ll_tools_rest_automation_request_string($request, 'category');
    $include_empty = (bool) rest_sanitize_boolean($request->get_param('include_empty'));
    $rows = function_exists('ll_tools_get_internal_review_note_rows_for_wordset')
        ? ll_tools_get_internal_review_note_rows_for_wordset($wordset_id, $category_spec, $include_empty)
        : [];

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'wordset' => [
            'id' => $wordset_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'filters' => [
            'category' => $category_spec,
            'include_empty' => $include_empty,
        ],
        'count' => count($rows),
        'notes' => $rows,
    ]);
}

function ll_tools_rest_automation_resolve_import_zip(WP_REST_Request $request) {
    ll_tools_rest_automation_load_import_helpers();

    $files = $request->get_file_params();
    $uploaded_file = [];
    foreach (['ll_import_file', 'file'] as $file_key) {
        if (!empty($files[$file_key]) && is_array($files[$file_key])) {
            $uploaded_file = $files[$file_key];
            break;
        }
    }

    if (!empty($uploaded_file['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $source_name = sanitize_file_name(wp_unslash((string) $uploaded_file['name']));
        $upload = wp_handle_upload($uploaded_file, [
            'test_form' => false,
            'mimes' => ['zip' => 'application/zip'],
        ]);
        if (isset($upload['error'])) {
            return new WP_Error('ll_tools_rest_import_upload_failed', (string) $upload['error']);
        }

        return [
            'zip_path' => (string) $upload['file'],
            'cleanup_zip' => true,
            'uploaded_file' => true,
            'zip_name' => $source_name !== '' ? $source_name : basename((string) $upload['file']),
        ];
    }

    $existing_file = ll_tools_rest_automation_request_string($request, 'existing');
    if ($existing_file === '') {
        $existing_file = ll_tools_rest_automation_request_string($request, 'filename');
    }
    if ($existing_file === '') {
        $existing_file = ll_tools_rest_automation_request_string($request, 'll_import_existing');
    }
    $existing_file = sanitize_file_name($existing_file);
    if ($existing_file === '') {
        return new WP_Error('ll_tools_rest_import_missing', __('Import failed: provide an uploaded zip file or a server import filename.', 'll-tools-text-domain'));
    }

    $import_dir = ll_tools_get_import_dir();
    if (!ll_tools_ensure_import_dir($import_dir)) {
        return new WP_Error('ll_tools_import_dir_unavailable', __('Import failed: server import folder is not available.', 'll-tools-text-domain'));
    }

    $existing_path = ll_tools_get_existing_import_zip_path($existing_file, $import_dir);
    if (is_wp_error($existing_path)) {
        return $existing_path;
    }

    return [
        'zip_path' => (string) $existing_path,
        'cleanup_zip' => false,
        'uploaded_file' => false,
        'zip_name' => $existing_file !== '' ? $existing_file : basename((string) $existing_path),
    ];
}

function ll_tools_rest_automation_prepare_preview_response(array $preview): array {
    $preview_data = isset($preview['preview_data']) && is_array($preview['preview_data']) ? $preview['preview_data'] : [];
    $options = isset($preview_data['options']) && is_array($preview_data['options']) ? $preview_data['options'] : [];
    $target_wordset_id = isset($options['target_wordset_id']) ? (int) $options['target_wordset_id'] : 0;
    $target_wordset = null;
    if ($target_wordset_id > 0) {
        $term = get_term($target_wordset_id, 'wordset');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            $target_wordset = [
                'id' => (int) $term->term_id,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ];
        }
    }

    return [
        'preview_token' => isset($preview['preview_token']) ? (string) $preview['preview_token'] : '',
        'bundle_type' => sanitize_key((string) ($preview_data['bundle_type'] ?? '')),
        'summary' => isset($preview_data['summary']) && is_array($preview_data['summary']) ? $preview_data['summary'] : [],
        'warnings' => isset($preview_data['warnings']) && is_array($preview_data['warnings']) ? array_values(array_map('strval', $preview_data['warnings'])) : [],
        'category_names' => isset($preview_data['category_names']) && is_array($preview_data['category_names']) ? array_values(array_map('strval', $preview_data['category_names'])) : [],
        'wordsets' => isset($preview_data['wordsets']) && is_array($preview_data['wordsets']) ? array_values($preview_data['wordsets']) : [],
        'sample_word' => isset($preview_data['sample_word']) && is_array($preview_data['sample_word']) ? $preview_data['sample_word'] : [],
        'options' => [
            'wordset_mode' => isset($options['wordset_mode']) ? sanitize_key((string) $options['wordset_mode']) : 'create_from_export',
            'target_wordset_id' => $target_wordset_id,
            'target_wordset' => $target_wordset,
            'wordset_name_overrides' => isset($options['wordset_name_overrides']) && is_array($options['wordset_name_overrides'])
                ? $options['wordset_name_overrides']
                : [],
        ],
        'source' => [
            'type' => isset($preview_data['source_type']) ? sanitize_key((string) $preview_data['source_type']) : '',
            'zip_name' => isset($preview_data['zip_name']) ? sanitize_file_name((string) $preview_data['zip_name']) : '',
            'cleanup_zip' => !empty($preview_data['cleanup_zip']),
        ],
    ];
}

function ll_tools_rest_automation_import_preview(WP_REST_Request $request) {
    ll_tools_rest_automation_load_import_helpers();
    if (!class_exists('ZipArchive')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_import_zip_missing',
            __('ZipArchive is not available on this server.', 'll-tools-text-domain'),
            500
        );
    }

    $zip_info = ll_tools_rest_automation_resolve_import_zip($request);
    if (is_wp_error($zip_info)) {
        return ll_tools_rest_automation_with_status($zip_info, 400);
    }

    $preview = ll_tools_prepare_import_preview_from_zip_info($zip_info);
    if (is_wp_error($preview)) {
        return ll_tools_rest_automation_with_status($preview, 400);
    }

    return rest_ensure_response(ll_tools_rest_automation_prepare_preview_response($preview));
}

function ll_tools_rest_automation_import_request_params(WP_REST_Request $request): array {
    $params = $request->get_params();
    $normalized = is_array($params) ? $params : [];

    $preview_token = '';
    foreach (['preview_token', 'll_import_preview_token', 'll_import_preview'] as $key) {
        if ($preview_token === '' && isset($normalized[$key]) && is_scalar($normalized[$key])) {
            $preview_token = sanitize_text_field((string) $normalized[$key]);
        }
    }
    if ($preview_token !== '') {
        $normalized['ll_import_preview_token'] = $preview_token;
    }

    if (isset($normalized['wordset_mode']) && !isset($normalized['ll_import_wordset_mode'])) {
        $normalized['ll_import_wordset_mode'] = sanitize_key((string) $normalized['wordset_mode']);
    }
    if (isset($normalized['target_wordset_id']) && !isset($normalized['ll_import_target_wordset'])) {
        $normalized['ll_import_target_wordset'] = (string) (int) $normalized['target_wordset_id'];
    }
    if (isset($normalized['wordset_names']) && is_array($normalized['wordset_names']) && !isset($normalized['ll_import_wordset_names'])) {
        $normalized['ll_import_wordset_names'] = $normalized['wordset_names'];
    }

    return $normalized;
}

function ll_tools_rest_automation_get_import_job_from_request(WP_REST_Request $request) {
    ll_tools_rest_automation_load_import_helpers();

    $job_id = sanitize_text_field(ll_tools_rest_automation_request_string($request, 'job_id'));
    if ($job_id === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_import_missing_job_id',
            __('The requested import job could not be identified.', 'll-tools-text-domain'),
            400
        );
    }

    $job = ll_tools_import_job_get($job_id);
    if (!is_array($job)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_import_job_not_found',
            __('The requested import job could not be found.', 'll-tools-text-domain'),
            404
        );
    }

    return $job;
}

function ll_tools_rest_automation_import_start(WP_REST_Request $request) {
    ll_tools_rest_automation_load_import_helpers();

    $active_job_id = ll_tools_import_job_get_active_id();
    if ($active_job_id !== '') {
        $active_job = ll_tools_import_job_get($active_job_id);
        if (is_array($active_job) && in_array((string) ($active_job['status'] ?? ''), ['running', 'paused'], true)) {
            return new WP_Error(
                'll_tools_rest_import_job_active',
                __('Another import job is already active. Resume or finish it before starting a new one.', 'll-tools-text-domain'),
                [
                    'status' => 409,
                    'job' => ll_tools_import_job_get_snapshot($active_job),
                ]
            );
        }

        ll_tools_import_job_clear_active_id($active_job_id);
    }

    $job = ll_tools_import_job_create_from_request(ll_tools_rest_automation_import_request_params($request));
    if (is_wp_error($job)) {
        return ll_tools_rest_automation_with_status($job, 400);
    }

    return rest_ensure_response([
        'job' => ll_tools_import_job_get_snapshot($job),
    ]);
}

function ll_tools_rest_automation_import_status(WP_REST_Request $request) {
    $job = ll_tools_rest_automation_get_import_job_from_request($request);
    if (is_wp_error($job)) {
        return $job;
    }

    return rest_ensure_response([
        'job' => ll_tools_import_job_get_snapshot($job),
    ]);
}

function ll_tools_rest_automation_import_process(WP_REST_Request $request) {
    $job = ll_tools_rest_automation_get_import_job_from_request($request);
    if (is_wp_error($job)) {
        return $job;
    }

    $job_id = (string) ($job['id'] ?? '');
    $status = sanitize_key((string) ($job['status'] ?? 'running'));
    if ($status === 'completed') {
        return rest_ensure_response(['job' => ll_tools_import_job_get_snapshot($job)]);
    }

    if ($status === 'paused') {
        $job['status'] = 'running';
        $job['error_message'] = '';
    }

    $processed_job = ll_tools_import_job_process($job);
    if (is_wp_error($processed_job)) {
        $job = ll_tools_import_job_pause($job, $processed_job->get_error_message());
        $job = ll_tools_import_job_save($job_id, $job);

        return new WP_Error(
            'll_tools_rest_import_process_failed',
            $processed_job->get_error_message(),
            [
                'status' => 500,
                'job' => ll_tools_import_job_get_snapshot($job),
            ]
        );
    }

    $saved_job = ll_tools_import_job_save($job_id, $processed_job);
    return rest_ensure_response([
        'job' => ll_tools_import_job_get_snapshot($saved_job),
    ]);
}

function ll_tools_rest_automation_import_discard(WP_REST_Request $request) {
    $job = ll_tools_rest_automation_get_import_job_from_request($request);
    if (is_wp_error($job)) {
        return $job;
    }

    $discarded = ll_tools_import_job_discard($job);
    if (is_wp_error($discarded)) {
        return new WP_Error(
            'll_tools_rest_import_discard_failed',
            $discarded->get_error_message(),
            [
                'status' => 409,
                'job' => ll_tools_import_job_get_snapshot($job),
            ]
        );
    }

    return rest_ensure_response($discarded);
}

function ll_tools_rest_automation_import_result(WP_REST_Request $request) {
    $job = ll_tools_rest_automation_get_import_job_from_request($request);
    if (is_wp_error($job)) {
        return $job;
    }

    $snapshot = ll_tools_import_job_get_snapshot($job);
    return rest_ensure_response([
        'job' => $snapshot,
        'result' => isset($snapshot['result']) && is_array($snapshot['result']) ? $snapshot['result'] : null,
    ]);
}

function ll_tools_rest_register_automation_routes(): void {
    register_rest_route('ll-tools/v1', '/automation/status', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_status',
        'permission_callback' => 'll_tools_rest_automation_require_view_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_create_wordset',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_create_access',
        'args' => [
            'name' => [
                'required' => true,
                'type' => 'string',
            ],
            'slug' => [
                'required' => false,
                'type' => 'string',
            ],
            'template' => [
                'required' => false,
                'type' => 'string',
            ],
            'manager' => [
                'required' => false,
                'type' => 'string',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/missing-meta', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_wordset_missing_meta',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/bulk-update', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_bulk_update',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-option-rules', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_option_rules',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/report', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_wordset_report',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/report-summary', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_wordset_report_summary',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/review-notes', [
        'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
        'callback' => 'll_tools_rest_automation_review_notes',
        'permission_callback' => 'll_tools_rest_automation_require_review_notes_access',
        'args' => [
            'category' => [
                'required' => false,
                'type' => 'string',
            ],
            'include_empty' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'object_type' => [
                'required' => false,
                'type' => 'string',
            ],
            'object_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'note' => [
                'required' => false,
                'type' => 'string',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/imports/preview', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_import_preview',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);

    register_rest_route('ll-tools/v1', '/imports/start', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_import_start',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);

    register_rest_route('ll-tools/v1', '/imports/(?P<job_id>[A-Za-z0-9_-]+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_import_status',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);

    register_rest_route('ll-tools/v1', '/imports/(?P<job_id>[A-Za-z0-9_-]+)/process', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_import_process',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);

    register_rest_route('ll-tools/v1', '/imports/(?P<job_id>[A-Za-z0-9_-]+)/discard', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_import_discard',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);

    register_rest_route('ll-tools/v1', '/imports/(?P<job_id>[A-Za-z0-9_-]+)/result', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_import_result',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
    ]);
}
add_action('rest_api_init', 'll_tools_rest_register_automation_routes');
