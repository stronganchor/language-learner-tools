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

function ll_tools_rest_automation_require_interlinear_access(WP_REST_Request $request) {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    if ($request->get_method() !== 'POST') {
        if (!function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset($wordset_id, (int) get_current_user_id())) {
            return true;
        }

        return ll_tools_rest_automation_error(
            'll_tools_rest_interlinear_forbidden',
            __('You cannot view interlinear data for this word set.', 'll-tools-text-domain'),
            403
        );
    }

    if (!function_exists('ll_tools_user_can_manage_wordset_content') || !ll_tools_user_can_manage_wordset_content($wordset_id, (int) get_current_user_id())) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_interlinear_forbidden',
            __('You cannot manage interlinear data for this word set.', 'll-tools-text-domain'),
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
    } elseif (preg_match('#^/ll-tools/v1/wordsets/[^/]+/(bulk-update|transcriptions|word-option-rules|prompt-cards|review-notes|interlinear)$#', $route, $matches)) {
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
            'transcriptions' => '/ll-tools/v1/wordsets/{wordset}/transcriptions',
            'site_sync_snapshot' => '/ll-tools/v1/wordsets/{wordset}/site-sync/snapshot',
            'word_option_rules' => '/ll-tools/v1/wordsets/{wordset}/word-option-rules',
            'prompt_cards' => '/ll-tools/v1/wordsets/{wordset}/prompt-cards',
            'report' => '/ll-tools/v1/wordsets/{wordset}/report',
            'report_summary' => '/ll-tools/v1/wordsets/{wordset}/report-summary',
            'review_notes' => '/ll-tools/v1/wordsets/{wordset}/review-notes',
            'interlinear' => '/ll-tools/v1/wordsets/{wordset}/interlinear',
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

function ll_tools_rest_automation_recording_belongs_to_wordset(int $recording_id, int $wordset_id): bool {
    if ($recording_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return false;
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return false;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    return !is_wp_error($wordset_ids) && in_array($wordset_id, array_map('intval', (array) $wordset_ids), true);
}

function ll_tools_rest_automation_transcription_payload(int $recording_id, int $wordset_id): array {
    $recording = get_post($recording_id);
    $word_id = ($recording instanceof WP_Post) ? (int) $recording->post_parent : 0;
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $review_fields = function_exists('ll_tools_ipa_keyboard_get_recording_review_fields')
        ? ll_tools_ipa_keyboard_get_recording_review_fields($recording_id)
        : ll_tools_rest_automation_get_recording_review_fields($recording_id);

    return [
        'recording_id' => $recording_id,
        'word_audio_id' => $recording_id,
        'word_id' => $word_id,
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
        'recording_ipa' => function_exists('ll_tools_word_grid_normalize_ipa_output')
            ? ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode)
            : (string) get_post_meta($recording_id, 'recording_ipa', true),
        'review_fields' => $review_fields,
        'needs_review' => function_exists('ll_tools_ipa_keyboard_recording_needs_auto_review')
            ? ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id)
            : !empty(array_filter($review_fields)),
        'review_note' => function_exists('ll_tools_ipa_keyboard_get_recording_review_note')
            ? ll_tools_ipa_keyboard_get_recording_review_note($recording_id)
            : trim((string) get_post_meta($recording_id, 'll_auto_transcription_review_note', true)),
    ];
}

function ll_tools_rest_automation_normalize_review_field(string $field): string {
    if (function_exists('ll_tools_ipa_keyboard_normalize_review_field')) {
        return ll_tools_ipa_keyboard_normalize_review_field($field);
    }

    $field = sanitize_key($field);
    if (in_array($field, ['recording_text', 'recordingtext', 'text', 'orthography', 'ortho'], true)) {
        return 'recording_text';
    }
    if (in_array($field, ['recording_ipa', 'recordingipa', 'ipa', 'transcription', 'pronunciation', 'phonetic'], true)) {
        return 'recording_ipa';
    }

    return '';
}

function ll_tools_rest_automation_normalize_review_fields($fields): array {
    if (function_exists('ll_tools_ipa_keyboard_normalize_review_fields')) {
        return ll_tools_ipa_keyboard_normalize_review_fields($fields);
    }

    if (is_string($fields)) {
        $fields = preg_split('/[,;|]/', $fields);
    }

    $normalized = [];
    foreach ((array) $fields as $key => $field) {
        if (is_string($key)) {
            if (!$field) {
                continue;
            }
            $field_key = ll_tools_rest_automation_normalize_review_field($key);
            if ($field_key !== '') {
                $normalized[$field_key] = true;
            }
            continue;
        }

        if (is_array($field)) {
            foreach ($field as $nested_field => $enabled) {
                if (!$enabled) {
                    continue;
                }
                $field_key = is_string($nested_field)
                    ? ll_tools_rest_automation_normalize_review_field($nested_field)
                    : ll_tools_rest_automation_normalize_review_field((string) $enabled);
                if ($field_key !== '') {
                    $normalized[$field_key] = true;
                }
            }
            continue;
        }

        $field_key = ll_tools_rest_automation_normalize_review_field((string) $field);
        if ($field_key !== '') {
            $normalized[$field_key] = true;
        }
    }

    return array_values(array_keys($normalized));
}

function ll_tools_rest_automation_review_field_map(array $review_fields): array {
    $map = [
        'recording_text' => false,
        'recording_ipa' => false,
    ];

    foreach ($review_fields as $field => $enabled) {
        if (is_string($field)) {
            if (!$enabled) {
                continue;
            }
            $field_key = ll_tools_rest_automation_normalize_review_field($field);
        } else {
            $field_key = ll_tools_rest_automation_normalize_review_field((string) $enabled);
        }
        if (array_key_exists($field_key, $map)) {
            $map[$field_key] = true;
        }
    }

    return $map;
}

function ll_tools_rest_automation_get_recording_review_fields(int $recording_id): array {
    $fields = [
        'recording_text' => false,
        'recording_ipa' => false,
    ];
    if ($recording_id <= 0) {
        return $fields;
    }

    $raw = get_post_meta($recording_id, 'll_auto_transcription_review_fields', true);
    if (is_array($raw)) {
        $fields = ll_tools_rest_automation_review_field_map($raw);
    } elseif (is_string($raw) && trim($raw) !== '') {
        foreach (ll_tools_rest_automation_normalize_review_fields($raw) as $field_key) {
            $fields[$field_key] = true;
        }
    }

    $has_field_meta = is_array($raw) ? !empty($raw) : (is_string($raw) && trim($raw) !== '');
    if (!$has_field_meta && (string) get_post_meta($recording_id, 'll_auto_transcription_needs_review', true) === '1') {
        $fields['recording_ipa'] = true;
    }

    return $fields;
}

function ll_tools_rest_automation_review_field_list_from_map(array $review_field_map): array {
    $fields = [];
    foreach (['recording_text', 'recording_ipa'] as $field_key) {
        if (!empty($review_field_map[$field_key])) {
            $fields[] = $field_key;
        }
    }

    return $fields;
}

function ll_tools_rest_automation_sanitize_transcription_update_fields(array $fields, int $wordset_id): array {
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $prepared = [];

    foreach ($fields as $meta_key => $value) {
        if ($meta_key === 'recording_text') {
            $prepared[$meta_key] = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
                ? ll_tools_word_grid_sanitize_non_ipa_text((string) $value)
                : sanitize_text_field((string) $value);
            continue;
        }

        if ($meta_key === 'recording_ipa') {
            $clean_ipa = function_exists('ll_tools_word_grid_sanitize_ipa')
                ? ll_tools_word_grid_sanitize_ipa((string) $value, $transcription_mode)
                : sanitize_text_field((string) $value);
            $prepared[$meta_key] = $clean_ipa;
        }
    }

    return $prepared;
}

function ll_tools_rest_automation_transcription_payload_update_fields(array $fields, int $wordset_id): array {
    if (!array_key_exists('recording_ipa', $fields)) {
        return $fields;
    }

    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $fields['recording_ipa'] = function_exists('ll_tools_word_grid_normalize_ipa_output')
        ? ll_tools_word_grid_normalize_ipa_output((string) $fields['recording_ipa'], $transcription_mode)
        : (string) $fields['recording_ipa'];

    return $fields;
}

function ll_tools_rest_automation_extract_transcription_review_note(array $update): array {
    $shared_note_submitted = array_key_exists('review_note', $update);
    $shared_note = $shared_note_submitted
        ? sanitize_textarea_field((string) $update['review_note'])
        : '';
    $field_notes = [];
    $aliases = [
        'recording_text_review_note' => 'recording_text',
        'recording_ipa_review_note' => 'recording_ipa',
    ];

    foreach ($aliases as $payload_key => $field_key) {
        if (!array_key_exists($payload_key, $update)) {
            continue;
        }

        $field_note = sanitize_textarea_field((string) $update[$payload_key]);
        if ($field_note !== '') {
            $field_notes[$field_key] = $field_note;
        }
    }

    if ($shared_note !== '') {
        $review_note = $shared_note;
    } elseif (!empty($field_notes)) {
        $unique_notes = array_values(array_unique(array_values($field_notes)));
        if (count($unique_notes) === 1) {
            $review_note = (string) $unique_notes[0];
        } else {
            $lines = [];
            foreach ($field_notes as $field_key => $field_note) {
                $lines[] = $field_key . ': ' . $field_note;
            }
            $review_note = implode("\n", $lines);
        }
    } else {
        $review_note = $shared_note;
    }

    return [
        'submitted' => $shared_note_submitted || array_key_exists('recording_text_review_note', $update) || array_key_exists('recording_ipa_review_note', $update),
        'review_note' => $review_note,
        'review_fields' => array_keys($field_notes),
    ];
}

function ll_tools_rest_automation_clear_transcription_review_state(int $recording_id): void {
    if (function_exists('ll_tools_ipa_keyboard_clear_recording_auto_review')) {
        ll_tools_ipa_keyboard_clear_recording_auto_review($recording_id);
        return;
    }

    $auto_key = function_exists('ll_tools_ipa_keyboard_auto_review_meta_key')
        ? ll_tools_ipa_keyboard_auto_review_meta_key()
        : 'll_auto_transcription_needs_review';
    $fields_key = function_exists('ll_tools_ipa_keyboard_review_fields_meta_key')
        ? ll_tools_ipa_keyboard_review_fields_meta_key()
        : 'll_auto_transcription_review_fields';
    $note_key = function_exists('ll_tools_ipa_keyboard_review_note_meta_key')
        ? ll_tools_ipa_keyboard_review_note_meta_key()
        : 'll_auto_transcription_review_note';

    delete_post_meta($recording_id, $auto_key);
    delete_post_meta($recording_id, $fields_key);
    delete_post_meta($recording_id, $note_key);
}

function ll_tools_rest_automation_apply_transcription_review_state(
    int $recording_id,
    bool $needs_review,
    array $review_fields,
    string $review_note,
    bool $review_note_submitted,
    bool $replace_review_fields,
    bool $clear_all_review
): void {
    if ($clear_all_review || $replace_review_fields) {
        ll_tools_rest_automation_clear_transcription_review_state($recording_id);
        if (!$needs_review) {
            return;
        }
    }

    if (empty($review_fields) && !$needs_review) {
        ll_tools_rest_automation_clear_transcription_review_state($recording_id);
        return;
    }

    $field_map = $replace_review_fields
        ? ['recording_text' => false, 'recording_ipa' => false]
        : ll_tools_rest_automation_review_field_map((array) (get_post_meta($recording_id, 'll_auto_transcription_review_fields', true) ?: []));
    foreach ($review_fields as $field_key) {
        if (array_key_exists($field_key, $field_map)) {
            $field_map[$field_key] = $needs_review;
        }
    }

    $enabled_fields = ll_tools_rest_automation_review_field_list_from_map($field_map);
    if (empty($enabled_fields)) {
        ll_tools_rest_automation_clear_transcription_review_state($recording_id);
        return;
    }

    update_post_meta($recording_id, 'll_auto_transcription_needs_review', '1');
    update_post_meta($recording_id, 'll_auto_transcription_review_fields', array_fill_keys($enabled_fields, true));
    if ($review_note_submitted || $replace_review_fields) {
        if ($review_note === '') {
            delete_post_meta($recording_id, 'll_auto_transcription_review_note');
        } else {
            update_post_meta($recording_id, 'll_auto_transcription_review_note', $review_note);
        }
    }

    if (function_exists('ll_tools_ipa_keyboard_set_recording_review_state')) {
        foreach ($enabled_fields as $field_key) {
            ll_tools_ipa_keyboard_set_recording_review_state($recording_id, true, $field_key, $review_note);
        }
    }
    clean_post_cache($recording_id);
}

function ll_tools_rest_automation_project_transcription_after(
    array $before,
    array $fields,
    bool $has_review_state,
    bool $needs_review,
    array $review_fields,
    string $review_note,
    bool $review_note_submitted,
    bool $replace_review_fields,
    bool $clear_all_review
): array {
    $after = $before;
    foreach ($fields as $meta_key => $value) {
        if (array_key_exists($meta_key, $after)) {
            $after[$meta_key] = $value;
        }
    }

    if ($has_review_state) {
        $review_field_map = $replace_review_fields || $clear_all_review
            ? ['recording_text' => false, 'recording_ipa' => false]
            : [
                'recording_text' => !empty($before['review_fields']['recording_text']),
                'recording_ipa' => !empty($before['review_fields']['recording_ipa']),
            ];

        if (!$clear_all_review) {
            foreach ($review_fields as $field_key) {
                if (array_key_exists($field_key, $review_field_map)) {
                    $review_field_map[$field_key] = $needs_review;
                }
            }
        }

        $after['review_fields'] = $review_field_map;
        $after['needs_review'] = !empty(array_filter($review_field_map));
        if (!$after['needs_review']) {
            $after['review_note'] = '';
        } elseif ($review_note_submitted || $replace_review_fields) {
            $after['review_note'] = $review_note;
        }
    } elseif ($review_note_submitted && !empty($before['needs_review'])) {
        $after['review_note'] = $review_note;
    }

    return $after;
}

function ll_tools_rest_automation_update_transcriptions(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $raw_updates = $request->get_param('updates');
    if (!is_array($raw_updates)) {
        $raw_updates = [$request->get_json_params()];
    }
    if (count($raw_updates) === 1 && empty($raw_updates[0])) {
        $raw_updates = [$request->get_params()];
    }

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => $wordset_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'matched_count' => 0,
        'updated_count' => 0,
        'updated' => [],
        'errors' => [],
    ];

    foreach ((array) $raw_updates as $index => $update) {
        if (!is_array($update)) {
            continue;
        }

        $recording_id = (int) ($update['recording_id'] ?? ($update['word_audio_id'] ?? 0));
        if ($recording_id <= 0 || !ll_tools_rest_automation_recording_belongs_to_wordset($recording_id, $wordset_id)) {
            $summary['errors'][] = [
                'index' => (int) $index,
                'recording_id' => $recording_id,
                'message' => __('Recording not found in this word set.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $summary['matched_count']++;
        $before = ll_tools_rest_automation_transcription_payload($recording_id, $wordset_id);
        $fields = [];
        if (array_key_exists('recording_text', $update) || array_key_exists('text', $update)) {
            $fields['recording_text'] = (string) ($update['recording_text'] ?? $update['text']);
        }
        if (array_key_exists('recording_ipa', $update) || array_key_exists('ipa', $update)) {
            $fields['recording_ipa'] = (string) ($update['recording_ipa'] ?? $update['ipa']);
        }
        $prepared_fields = ll_tools_rest_automation_sanitize_transcription_update_fields($fields, $wordset_id);
        $projected_fields = ll_tools_rest_automation_transcription_payload_update_fields($prepared_fields, $wordset_id);

        $review_note_data = ll_tools_rest_automation_extract_transcription_review_note($update);
        $review_note = (string) $review_note_data['review_note'];
        $review_note_submitted = !empty($review_note_data['submitted']);
        $review_fields = [];
        $has_explicit_review_fields = false;
        $has_targeted_review_field = false;
        if (array_key_exists('review_fields', $update)) {
            $has_explicit_review_fields = true;
            $review_fields = ll_tools_rest_automation_normalize_review_fields($update['review_fields']);
        } elseif (array_key_exists('review_field', $update)) {
            $has_targeted_review_field = true;
            $field = ll_tools_rest_automation_normalize_review_field((string) $update['review_field']);
            if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
                $review_fields[] = $field;
            }
        } elseif (!empty($review_note_data['review_fields'])) {
            $has_explicit_review_fields = true;
            $review_fields = (array) $review_note_data['review_fields'];
        }
        $needs_review = array_key_exists('needs_review', $update)
            ? rest_sanitize_boolean($update['needs_review'])
            : !empty($review_fields);
        if (empty($review_fields) && array_key_exists('needs_review', $update) && $needs_review) {
            $review_fields = array_values(array_intersect(array_keys($prepared_fields), ['recording_text', 'recording_ipa']));
            if (empty($review_fields)) {
                $review_fields = ['recording_ipa'];
            }
        }
        $has_review_state = array_key_exists('needs_review', $update) || $has_explicit_review_fields || $has_targeted_review_field;
        $clear_all_review = array_key_exists('needs_review', $update) && !$needs_review && !$has_targeted_review_field;
        $replace_review_fields = $has_explicit_review_fields || $clear_all_review;

        if (!$dry_run) {
            if (!empty($prepared_fields)) {
                if (function_exists('ll_tools_ipa_keyboard_update_recording_fields')) {
                    ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, $prepared_fields);
                } else {
                    foreach ($prepared_fields as $meta_key => $value) {
                        $value = sanitize_text_field($value);
                        if ($value === '') {
                            delete_post_meta($recording_id, $meta_key);
                        } else {
                            update_post_meta($recording_id, $meta_key, $value);
                        }
                    }
                }
            }
            if ($has_review_state) {
                ll_tools_rest_automation_apply_transcription_review_state(
                    $recording_id,
                    $needs_review,
                    $review_fields,
                    $review_note,
                    $review_note_submitted,
                    $replace_review_fields,
                    $clear_all_review
                );
            } elseif (
                $review_note_submitted
                && function_exists('ll_tools_ipa_keyboard_set_recording_review_note')
                && function_exists('ll_tools_ipa_keyboard_recording_needs_auto_review')
                && ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id)
            ) {
                ll_tools_ipa_keyboard_set_recording_review_note($recording_id, $review_note);
            }
        }

        $after = $dry_run
            ? ll_tools_rest_automation_project_transcription_after(
                $before,
                $projected_fields,
                $has_review_state,
                $needs_review,
                $review_fields,
                $review_note,
                $review_note_submitted,
                $replace_review_fields,
                $clear_all_review
            )
            : ll_tools_rest_automation_transcription_payload($recording_id, $wordset_id);
        $changed = $after !== $before;
        if ($changed) {
            $summary['updated_count']++;
        }
        $summary['updated'][] = [
            'recording_id' => $recording_id,
            'changed' => $changed,
            'before' => $before,
            'after' => $after,
        ];
    }

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

function ll_tools_rest_automation_post_term_ids(int $post_id, string $taxonomy): array {
    $term_ids = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($term_ids)) {
        return [];
    }

    return ll_tools_rest_automation_prepare_id_list((array) $term_ids);
}

function ll_tools_rest_automation_prompt_card_request_id(WP_REST_Request $request): int {
    foreach (['prompt_card_id', 'object_id', 'id'] as $key) {
        $value = $request->get_param($key);
        if (is_scalar($value) && absint($value) > 0) {
            return absint($value);
        }
    }

    return 0;
}

function ll_tools_rest_automation_prompt_card_belongs_to_wordset(int $prompt_card_id, int $wordset_id): bool {
    if ($prompt_card_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    return has_term($wordset_id, 'wordset', $prompt_card_id);
}

function ll_tools_rest_automation_prompt_card_ref_value($ref, array $keys = []): string {
    if (is_scalar($ref)) {
        return trim((string) $ref);
    }

    if (is_array($ref)) {
        $candidate_keys = !empty($keys) ? $keys : ['id', 'term_id', 'slug', 'name'];
        foreach ($candidate_keys as $key) {
            if (isset($ref[$key]) && is_scalar($ref[$key])) {
                return trim((string) $ref[$key]);
            }
        }
    }

    return '';
}

function ll_tools_rest_automation_prompt_card_collect_refs($raw): array {
    if (is_scalar($raw)) {
        return [trim((string) $raw)];
    }

    if (!is_array($raw)) {
        return [];
    }

    if (ll_tools_rest_automation_array_is_list($raw)) {
        return array_values($raw);
    }

    foreach (['ids', 'term_ids', 'slugs', 'categories', 'wordsets'] as $key) {
        if (array_key_exists($key, $raw) && is_array($raw[$key])) {
            return array_values($raw[$key]);
        }
    }

    return [$raw];
}

function ll_tools_rest_automation_prompt_card_resolve_category_ref(WP_Term $wordset_term, $ref) {
    $category_spec = ll_tools_rest_automation_prompt_card_ref_value($ref, ['id', 'term_id', 'category_id', 'slug', 'category_slug', 'name', 'category_name']);
    if ($category_spec === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_empty_category',
            __('Prompt-card category references cannot be empty.', 'll-tools-text-domain'),
            400
        );
    }

    $term = null;
    if (is_numeric($category_spec)) {
        $term = get_term((int) $category_spec, 'word-category');
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        if (function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
            $term = ll_tools_resolve_word_category_term_for_wordsets($category_spec, [(int) $wordset_term->term_id]);
        }
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        $term = function_exists('ll_tools_resolve_word_category_term')
            ? ll_tools_resolve_word_category_term($category_spec)
            : get_term_by('slug', sanitize_title($category_spec), 'word-category');
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_category_not_found',
            sprintf(
                /* translators: %s: category identifier */
                __('Unable to resolve prompt-card category: %s', 'll-tools-text-domain'),
                $category_spec
            ),
            404
        );
    }

    if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset((int) $term->term_id, (int) $wordset_term->term_id, true);
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
                'll_tools_rest_prompt_card_category_forbidden',
                __('That prompt-card category belongs to a different word set.', 'll-tools-text-domain'),
                403
            );
        }
    }

    return $term;
}

function ll_tools_rest_automation_prompt_card_resolve_category_ids(WP_Term $wordset_term, $raw) {
    $refs = ll_tools_rest_automation_prompt_card_collect_refs($raw);
    $category_ids = [];
    foreach ($refs as $ref) {
        $term = ll_tools_rest_automation_prompt_card_resolve_category_ref($wordset_term, $ref);
        if (is_wp_error($term)) {
            return $term;
        }
        $category_ids[] = (int) $term->term_id;
    }

    $category_ids = ll_tools_rest_automation_prepare_id_list($category_ids);
    if (empty($category_ids)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_missing_category',
            __('Provide at least one word-category assignment for the prompt card.', 'll-tools-text-domain'),
            400
        );
    }

    return $category_ids;
}

function ll_tools_rest_automation_prompt_card_resolve_wordset_ref($ref) {
    $wordset_spec = ll_tools_rest_automation_prompt_card_ref_value($ref, ['id', 'term_id', 'wordset_id', 'slug', 'wordset_slug', 'name', 'wordset_name']);
    if ($wordset_spec === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_empty_wordset',
            __('Prompt-card word set references cannot be empty.', 'll-tools-text-domain'),
            400
        );
    }

    $term = null;
    if (is_numeric($wordset_spec)) {
        $term = get_term((int) $wordset_spec, 'wordset');
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        $term = ll_tools_cli_resolve_wordset_term($wordset_spec);
    }
    if (is_wp_error($term)) {
        return ll_tools_rest_automation_with_status($term, 404);
    }
    if (!($term instanceof WP_Term)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_wordset_not_found',
            sprintf(
                /* translators: %s: word set identifier */
                __('Unable to resolve prompt-card word set: %s', 'll-tools-text-domain'),
                $wordset_spec
            ),
            404
        );
    }

    return $term;
}

function ll_tools_rest_automation_prompt_card_resolve_wordset_ids(int $route_wordset_id, $raw) {
    $refs = ll_tools_rest_automation_prompt_card_collect_refs($raw);
    $wordset_ids = [];
    foreach ($refs as $ref) {
        $term = ll_tools_rest_automation_prompt_card_resolve_wordset_ref($ref);
        if (is_wp_error($term)) {
            return $term;
        }
        $wordset_ids[] = (int) $term->term_id;
    }

    $wordset_ids = ll_tools_rest_automation_prepare_id_list($wordset_ids);
    if (empty($wordset_ids)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_missing_wordset',
            __('Provide at least one word set assignment for the prompt card.', 'll-tools-text-domain'),
            400
        );
    }
    if (!in_array($route_wordset_id, $wordset_ids, true)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_wordset_scope_missing',
            __('Prompt-card word set assignments must include the route word set.', 'll-tools-text-domain'),
            400
        );
    }

    return $wordset_ids;
}

function ll_tools_rest_automation_prompt_card_terms_payload(int $post_id, string $taxonomy): array {
    $terms = wp_get_post_terms($post_id, $taxonomy);
    if (is_wp_error($terms)) {
        return [];
    }

    $payload = [];
    foreach ((array) $terms as $term) {
        if ($term instanceof WP_Term) {
            $payload[] = ll_tools_rest_automation_term_summary($term);
        }
    }

    return $payload;
}

function ll_tools_rest_automation_prompt_card_payload(int $prompt_card_id): array {
    $post = get_post($prompt_card_id);
    $card = function_exists('ll_tools_get_prompt_card_data')
        ? ll_tools_get_prompt_card_data($prompt_card_id)
        : [];

    return [
        'id' => $prompt_card_id,
        'title' => $post instanceof WP_Post ? (string) $post->post_title : '',
        'status' => $post instanceof WP_Post ? (string) $post->post_status : '',
        'data' => $card,
        'categories' => ll_tools_rest_automation_prompt_card_terms_payload($prompt_card_id, 'word-category'),
        'wordsets' => ll_tools_rest_automation_prompt_card_terms_payload($prompt_card_id, 'wordset'),
    ];
}

function ll_tools_rest_automation_prompt_cards(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $prompt_card_id = ll_tools_rest_automation_prompt_card_request_id($request);
    if ($prompt_card_id <= 0) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_missing_id',
            __('Provide prompt_card_id, object_id, or id for the prompt card to update.', 'll-tools-text-domain'),
            400
        );
    }

    $post = get_post($prompt_card_id);
    if (!($post instanceof WP_Post) || $post->post_type !== (defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') ? LL_TOOLS_PROMPT_CARD_POST_TYPE : 'll_prompt_card')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_not_found',
            __('That prompt card was not found.', 'll-tools-text-domain'),
            404
        );
    }

    if (!ll_tools_rest_automation_prompt_card_belongs_to_wordset($prompt_card_id, $wordset_id)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_prompt_card_wrong_wordset',
            __('That prompt card does not belong to the selected word set.', 'll-tools-text-domain'),
            400
        );
    }

    $before_category_ids = function_exists('ll_tools_prompt_card_get_word_category_term_ids')
        ? ll_tools_prompt_card_get_word_category_term_ids($prompt_card_id)
        : ll_tools_rest_automation_post_term_ids($prompt_card_id, 'word-category');
    $before_wordset_ids = ll_tools_rest_automation_post_term_ids($prompt_card_id, 'wordset');
    $before_payload = ll_tools_rest_automation_prompt_card_payload($prompt_card_id);
    $fields = [];

    if (ll_tools_rest_automation_request_has_any_param($request, ['prompt_text', 'prompt'])) {
        $fields['prompt_text'] = ll_tools_rest_automation_first_request_param($request, ['prompt_text', 'prompt']);
    }
    $has_prompt_audio_attachment = ll_tools_rest_automation_request_has_any_param($request, ['prompt_audio_attachment_id', 'prompt_audio_id']);
    if ($has_prompt_audio_attachment) {
        $fields['prompt_audio_attachment_id'] = ll_tools_rest_automation_first_request_param($request, ['prompt_audio_attachment_id', 'prompt_audio_id']);
    }
    if (ll_tools_rest_automation_request_has_any_param($request, ['prompt_audio_url', 'prompt_audio'])) {
        $prompt_audio_url = ll_tools_rest_automation_first_request_param($request, ['prompt_audio_url', 'prompt_audio']);
        $fields['prompt_audio_url'] = $prompt_audio_url;
        if (!$has_prompt_audio_attachment && is_scalar($prompt_audio_url) && trim((string) $prompt_audio_url) !== '') {
            $fields['prompt_audio_attachment_id'] = 0;
        }
    }
    if (ll_tools_rest_automation_request_has_any_param($request, ['prompt_image_word_id', 'prompt_image_id'])) {
        $fields['prompt_image_word_id'] = ll_tools_rest_automation_first_request_param($request, ['prompt_image_word_id', 'prompt_image_id']);
    }
    if (ll_tools_rest_automation_request_has_any_param($request, ['correct_answer_word_id', 'answer_word_id'])) {
        $fields['correct_answer_word_id'] = ll_tools_rest_automation_first_request_param($request, ['correct_answer_word_id', 'answer_word_id']);
    }
    if (ll_tools_rest_automation_request_has_any_param($request, ['wrong_answer_word_ids', 'wrong_answer_ids'])) {
        $fields['wrong_answer_word_ids'] = ll_tools_rest_automation_first_request_param($request, ['wrong_answer_word_ids', 'wrong_answer_ids']);
    }
    if (ll_tools_rest_automation_request_has_any_param($request, ['track_answer_word_progress'])) {
        $fields['track_answer_word_progress'] = rest_sanitize_boolean($request->get_param('track_answer_word_progress'));
    }

    $changed_keys = [];
    if (!empty($fields) && function_exists('ll_tools_update_prompt_card_configuration')) {
        $changed_keys = ll_tools_update_prompt_card_configuration($prompt_card_id, $fields);
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['category_ids', 'categories', 'category', 'category_slug'])) {
        $category_raw = ll_tools_rest_automation_first_request_param($request, ['category_ids', 'categories', 'category', 'category_slug']);
        $category_ids = ll_tools_rest_automation_prompt_card_resolve_category_ids($wordset_term, $category_raw);
        if (is_wp_error($category_ids)) {
            return $category_ids;
        }

        $assigned = wp_set_post_terms($prompt_card_id, $category_ids, 'word-category', false);
        if (is_wp_error($assigned)) {
            return ll_tools_rest_automation_with_status($assigned, 400);
        }
        if (ll_tools_rest_automation_prepare_id_list($before_category_ids) !== ll_tools_rest_automation_prepare_id_list((array) $category_ids)) {
            $changed_keys[] = 'categories';
        }
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['wordset_ids', 'wordsets'])) {
        $wordset_raw = ll_tools_rest_automation_first_request_param($request, ['wordset_ids', 'wordsets']);
        $wordset_ids = ll_tools_rest_automation_prompt_card_resolve_wordset_ids($wordset_id, $wordset_raw);
        if (is_wp_error($wordset_ids)) {
            return $wordset_ids;
        }

        $assigned = wp_set_post_terms($prompt_card_id, $wordset_ids, 'wordset', false);
        if (is_wp_error($assigned)) {
            return ll_tools_rest_automation_with_status($assigned, 400);
        }
        if (ll_tools_rest_automation_prepare_id_list($before_wordset_ids) !== ll_tools_rest_automation_prepare_id_list((array) $wordset_ids)) {
            $changed_keys[] = 'wordsets';
        }
    }

    $bumped_category_ids = function_exists('ll_tools_prompt_card_invalidate_category_caches')
        ? ll_tools_prompt_card_invalidate_category_caches($prompt_card_id, $before_category_ids)
        : [];

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'prompt_card_id' => $prompt_card_id,
        'changed' => !empty($changed_keys),
        'changed_keys' => array_values(array_unique(array_map('strval', $changed_keys))),
        'bumped_category_ids' => $bumped_category_ids,
        'before' => $before_payload,
        'after' => ll_tools_rest_automation_prompt_card_payload($prompt_card_id),
    ]);
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

function ll_tools_rest_automation_interlinear_allowed_post_type(string $post_type): string {
    $post_type = sanitize_key($post_type);
    return in_array($post_type, ll_tools_interlinear_supported_post_types(), true) ? $post_type : '';
}

function ll_tools_rest_automation_interlinear_lesson_posts(int $wordset_id, bool $include_empty = true, string $post_type = ''): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $post_types = $post_type !== ''
        ? [$post_type]
        : ll_tools_interlinear_supported_post_types();
    $posts = [];
    $meta_keys = [
        'll_content_lesson' => ll_tools_interlinear_content_wordset_meta_key(),
        'll_vocab_lesson' => ll_tools_interlinear_vocab_wordset_meta_key(),
    ];

    foreach ($post_types as $current_type) {
        $current_type = ll_tools_rest_automation_interlinear_allowed_post_type((string) $current_type);
        if ($current_type === '' || empty($meta_keys[$current_type])) {
            continue;
        }

        $query_posts = get_posts([
            'post_type' => $current_type,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => $meta_keys[$current_type],
                    'value' => (string) $wordset_id,
                ],
            ],
        ]);

        foreach ((array) $query_posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            if (!$include_empty && !ll_tools_interlinear_has_payload((int) $post->ID)) {
                continue;
            }
            $posts[] = $post;
        }
    }

    return $posts;
}

function ll_tools_rest_automation_interlinear_request_item_string(array $item, string $key): string {
    if (!array_key_exists($key, $item) || !is_scalar($item[$key])) {
        return '';
    }

    return trim((string) $item[$key]);
}

function ll_tools_rest_automation_interlinear_item_category_spec(array $item): string {
    foreach (['category', 'category_slug'] as $key) {
        $value = ll_tools_rest_automation_interlinear_request_item_string($item, $key);
        if ($value !== '') {
            return $value;
        }
    }

    $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : $item;
    $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
    foreach (['category_slug', 'category_name'] as $key) {
        if (isset($metadata[$key]) && is_scalar($metadata[$key]) && trim((string) $metadata[$key]) !== '') {
            return trim((string) $metadata[$key]);
        }
    }

    return '';
}

function ll_tools_rest_automation_resolve_interlinear_category_id(string $category_spec, int $wordset_id): int {
    $category_spec = trim($category_spec);
    if ($category_spec === '') {
        return 0;
    }

    if (function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
        $term = ll_tools_resolve_word_category_term_for_wordsets($category_spec, [$wordset_id]);
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }

    $term = is_numeric($category_spec)
        ? get_term((int) $category_spec, 'word-category')
        : get_term_by('slug', sanitize_title($category_spec), 'word-category');
    if ($term instanceof WP_Term && !is_wp_error($term)) {
        return (int) $term->term_id;
    }

    return 0;
}

function ll_tools_rest_automation_resolve_interlinear_vocab_lesson_by_category(int $wordset_id, int $category_id) {
    if ($wordset_id <= 0 || $category_id <= 0) {
        return null;
    }

    $category_ids = [$category_id];
    if (function_exists('ll_tools_get_vocab_lesson_category_meta_candidates')) {
        $category_ids = array_merge($category_ids, ll_tools_get_vocab_lesson_category_meta_candidates($category_id, $wordset_id));
    }
    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function (int $term_id): bool {
        return $term_id > 0;
    })));

    $posts = get_posts([
        'post_type' => 'll_vocab_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'no_found_rows' => true,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => ll_tools_interlinear_vocab_wordset_meta_key(),
                'value' => (string) $wordset_id,
            ],
            [
                'key' => defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META') ? LL_TOOLS_VOCAB_LESSON_CATEGORY_META : '_ll_tools_vocab_category_id',
                'value' => array_map('strval', $category_ids),
                'compare' => 'IN',
            ],
        ],
    ]);

    if (!empty($posts[0]) && $posts[0] instanceof WP_Post) {
        return $posts[0];
    }

    foreach (ll_tools_rest_automation_interlinear_lesson_posts($wordset_id, true, 'll_vocab_lesson') as $post) {
        $lesson_category_id = (int) get_post_meta((int) $post->ID, defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META') ? LL_TOOLS_VOCAB_LESSON_CATEGORY_META : '_ll_tools_vocab_category_id', true);
        if (in_array($lesson_category_id, $category_ids, true)) {
            return $post;
        }
    }

    return null;
}

function ll_tools_rest_automation_resolve_interlinear_lesson(int $wordset_id, array $item) {
    $post_type = ll_tools_rest_automation_interlinear_allowed_post_type(
        ll_tools_rest_automation_interlinear_request_item_string($item, 'post_type')
    );

    $id_specs = [
        ll_tools_rest_automation_interlinear_request_item_string($item, 'post_id'),
        ll_tools_rest_automation_interlinear_request_item_string($item, 'lesson'),
    ];
    foreach ($id_specs as $id_spec) {
        if ($id_spec === '' || !is_numeric($id_spec)) {
            continue;
        }
        $post = get_post((int) $id_spec);
        if (
            $post instanceof WP_Post
            && ll_tools_interlinear_post_type_supported($post)
            && ($post_type === '' || $post->post_type === $post_type)
            && ll_tools_interlinear_get_wordset_id_for_lesson((int) $post->ID) === $wordset_id
        ) {
            return $post;
        }
    }

    $category_spec = ll_tools_rest_automation_interlinear_item_category_spec($item);
    $category_id = ll_tools_rest_automation_resolve_interlinear_category_id($category_spec, $wordset_id);
    if ($category_id > 0 && ($post_type === '' || $post_type === 'll_vocab_lesson')) {
        $post = ll_tools_rest_automation_resolve_interlinear_vocab_lesson_by_category($wordset_id, $category_id);
        if ($post instanceof WP_Post) {
            return $post;
        }
    }

    $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : $item;
    $string_specs = [
        ll_tools_rest_automation_interlinear_request_item_string($item, 'lesson'),
        ll_tools_rest_automation_interlinear_request_item_string($item, 'post_slug'),
        ll_tools_rest_automation_interlinear_request_item_string($item, 'slug'),
        ll_tools_rest_automation_interlinear_request_item_string($item, 'interlinear_lesson_id'),
    ];
    if (isset($payload['lesson_id']) && is_scalar($payload['lesson_id'])) {
        $string_specs[] = trim((string) $payload['lesson_id']);
    }
    $string_specs = array_values(array_unique(array_filter(array_map('trim', $string_specs))));

    if (empty($string_specs)) {
        return null;
    }

    $posts = ll_tools_rest_automation_interlinear_lesson_posts($wordset_id, true, $post_type);
    foreach ($posts as $post) {
        $title = trim((string) get_the_title($post));
        $title_slug = sanitize_title($title);
        $interlinear_lesson_id = (string) get_post_meta((int) $post->ID, LL_TOOLS_INTERLINEAR_LESSON_ID_META, true);
        foreach ($string_specs as $spec) {
            $spec_slug = sanitize_title($spec);
            if (
                (string) $post->post_name === $spec
                || (string) $post->post_name === $spec_slug
                || $title === $spec
                || ($title_slug !== '' && $title_slug === $spec_slug)
                || ($interlinear_lesson_id !== '' && $interlinear_lesson_id === $spec)
            ) {
                return $post;
            }
        }
    }

    return null;
}

function ll_tools_rest_automation_interlinear_payload_from_item(array $item) {
    if (array_key_exists('payload', $item)) {
        return $item['payload'];
    }

    if (isset($item['schema']) || isset($item['lines'])) {
        return $item;
    }

    return null;
}

function ll_tools_rest_automation_process_interlinear_item(int $wordset_id, array $item, int $index, bool $dry_run) {
    $post = ll_tools_rest_automation_resolve_interlinear_lesson($wordset_id, $item);
    if (!($post instanceof WP_Post)) {
        return new WP_Error(
            'll_tools_rest_interlinear_lesson_not_found',
            __('Could not find a matching content or vocab lesson in this word set.', 'll-tools-text-domain'),
            ['status' => 404, 'index' => $index]
        );
    }

    $lesson_id = (int) $post->ID;
    $delete = (bool) rest_sanitize_boolean($item['delete'] ?? ($item['clear'] ?? false));
    $before = ll_tools_interlinear_payload_for_rest($lesson_id, false);

    if ($delete) {
        if (!$dry_run) {
            ll_tools_interlinear_clear_payload($lesson_id);
        }
        $after = $dry_run ? $before : ll_tools_interlinear_payload_for_rest($lesson_id, false);
        return [
            'index' => $index,
            'action' => 'clear',
            'dry_run' => $dry_run,
            'changed' => !empty($before['has_payload']),
            'lesson' => $after,
        ];
    }

    $payload = ll_tools_rest_automation_interlinear_payload_from_item($item);
    if ($payload === null) {
        return new WP_Error(
            'll_tools_rest_interlinear_missing_payload',
            __('Provide an interlinear payload object, or send delete=true to clear one.', 'll-tools-text-domain'),
            ['status' => 400, 'index' => $index]
        );
    }

    $normalized = ll_tools_interlinear_normalize_payload($payload);
    if (is_wp_error($normalized)) {
        $normalized->add_data(['status' => 400, 'index' => $index]);
        return $normalized;
    }

    $source = ll_tools_rest_automation_interlinear_request_item_string($item, 'source');
    if ($source === '' && isset($normalized['source']) && is_scalar($normalized['source'])) {
        $source = (string) $normalized['source'];
    }

    $before_payload = ll_tools_interlinear_get_payload($lesson_id);
    if (!$dry_run) {
        $updated = ll_tools_interlinear_set_payload($lesson_id, $normalized, $source);
        if (is_wp_error($updated)) {
            $updated->add_data(['status' => 400, 'index' => $index]);
            return $updated;
        }
    }

    $after = $dry_run
        ? array_merge($before, [
            'has_payload' => true,
            'summary' => ll_tools_interlinear_summary($normalized),
        ])
        : ll_tools_interlinear_payload_for_rest($lesson_id, false);

    return [
        'index' => $index,
        'action' => 'update',
        'dry_run' => $dry_run,
        'changed' => wp_json_encode($before_payload) !== wp_json_encode($normalized),
        'lesson' => $after,
    ];
}

function ll_tools_rest_automation_interlinear(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $wordset = [
        'id' => $wordset_id,
        'slug' => (string) $wordset_term->slug,
        'name' => (string) $wordset_term->name,
    ];

    if ($request->get_method() !== 'POST') {
        $include_empty = (bool) rest_sanitize_boolean($request->get_param('include_empty'));
        $include_payload = $request->has_param('include_payload')
            ? (bool) rest_sanitize_boolean($request->get_param('include_payload'))
            : true;
        $post_type = ll_tools_rest_automation_interlinear_allowed_post_type(ll_tools_rest_automation_request_string($request, 'post_type'));
        $lesson_spec = ll_tools_rest_automation_request_string($request, 'lesson');

        if ($lesson_spec !== '') {
            $post = ll_tools_rest_automation_resolve_interlinear_lesson($wordset_id, [
                'lesson' => $lesson_spec,
                'post_type' => $post_type,
            ]);
            if (!($post instanceof WP_Post)) {
                return ll_tools_rest_automation_error(
                    'll_tools_rest_interlinear_lesson_not_found',
                    __('Could not find a matching content or vocab lesson in this word set.', 'll-tools-text-domain'),
                    404
                );
            }

            $items = [ll_tools_interlinear_payload_for_rest((int) $post->ID, $include_payload)];
        } else {
            $posts = ll_tools_rest_automation_interlinear_lesson_posts($wordset_id, true, $post_type);
            $items = [];
            foreach ($posts as $post) {
                $has_payload = ll_tools_interlinear_has_payload((int) $post->ID);
                if (!$include_empty && !$has_payload) {
                    continue;
                }
                $items[] = ll_tools_interlinear_payload_for_rest((int) $post->ID, $include_payload);
            }
        }

        return rest_ensure_response([
            'generated_at_gmt' => gmdate('c'),
            'wordset' => $wordset,
            'filters' => [
                'lesson' => $lesson_spec,
                'post_type' => $post_type,
                'include_empty' => $include_empty,
                'include_payload' => $include_payload,
            ],
            'count' => count($items),
            'items' => $items,
        ]);
    }

    $raw_items = $request->get_param('items');
    $is_batch = is_array($raw_items);
    $items = $is_batch ? array_values($raw_items) : [$request->get_params()];
    $dry_run = (bool) rest_sanitize_boolean($request->get_param('dry_run'));
    $results = [];
    $errors = [];
    $updated_count = 0;
    $cleared_count = 0;

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $errors[] = [
                'index' => $index,
                'code' => 'll_tools_rest_interlinear_invalid_item',
                'message' => __('Each interlinear import item must be an object.', 'll-tools-text-domain'),
            ];
            continue;
        }

        if ($dry_run && !array_key_exists('dry_run', $item)) {
            $item['dry_run'] = true;
        }

        $result = ll_tools_rest_automation_process_interlinear_item($wordset_id, $item, $index, $dry_run);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $errors[] = [
                'index' => is_array($data) && isset($data['index']) ? (int) $data['index'] : $index,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : 400,
            ];
            continue;
        }

        $results[] = $result;
        if (!empty($result['changed']) && !$dry_run) {
            if (($result['action'] ?? '') === 'clear') {
                $cleared_count++;
            } else {
                $updated_count++;
            }
        }
    }

    if (!$is_batch && empty($results) && !empty($errors[0])) {
        return ll_tools_rest_automation_error(
            (string) $errors[0]['code'],
            (string) $errors[0]['message'],
            (int) ($errors[0]['status'] ?? 400)
        );
    }

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => $wordset,
        'count' => count($results),
        'updated_count' => $updated_count,
        'cleared_count' => $cleared_count,
        'results' => $results,
        'errors' => $errors,
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

function ll_tools_rest_corpus_text_normalize_source_key(string $source_key): string {
    $source_key = trim(str_replace('\\', '/', $source_key));
    $source_key = preg_replace('#/+#', '/', $source_key);
    $source_key = is_string($source_key) ? $source_key : '';

    return trim($source_key, '/');
}

function ll_tools_rest_corpus_text_find_attachment_by_source(string $source_key): int {
    $source_key = ll_tools_rest_corpus_text_normalize_source_key($source_key);
    if ($source_key === '') {
        return 0;
    }

    $attachments = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => '_ll_texts_source_asset',
                'value' => $source_key,
            ],
        ],
    ]);

    return !empty($attachments[0]) ? (int) $attachments[0] : 0;
}

function ll_tools_rest_corpus_text_find_post_by_slug(string $slug): ?WP_Post {
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return null;
    }

    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'name' => $slug,
        'posts_per_page' => 1,
        'no_found_rows' => true,
    ]);
    $post = $posts[0] ?? null;

    return $post instanceof WP_Post ? $post : null;
}

function ll_tools_rest_corpus_text_asset_max_bytes(): int {
    return max(1, (int) apply_filters('ll_tools_rest_corpus_text_asset_max_bytes', 8 * 1024 * 1024));
}

function ll_tools_rest_corpus_text_asset_max_dimension(): int {
    return max(1, (int) apply_filters('ll_tools_rest_corpus_text_asset_max_dimension', 6000));
}

function ll_tools_rest_corpus_text_payload_max_bytes(): int {
    return max(1, (int) apply_filters('ll_tools_rest_corpus_text_payload_max_bytes', 8 * 1024 * 1024));
}

function ll_tools_rest_corpus_text_payload_max_rows(): int {
    return max(1, (int) apply_filters('ll_tools_rest_corpus_text_payload_max_rows', 50000));
}

function ll_tools_rest_corpus_text_payload_max_depth(): int {
    return max(1, (int) apply_filters('ll_tools_rest_corpus_text_payload_max_depth', 64));
}

/**
 * @return array{rows:int,depth:int}
 */
function ll_tools_rest_corpus_text_payload_shape($value, int $depth = 1): array {
    if (!is_array($value)) {
        return ['rows' => 1, 'depth' => $depth];
    }

    $rows = 1;
    $max_depth = $depth;
    foreach ($value as $child) {
        $shape = ll_tools_rest_corpus_text_payload_shape($child, $depth + 1);
        $rows += (int) $shape['rows'];
        $max_depth = max($max_depth, (int) $shape['depth']);
    }

    return ['rows' => $rows, 'depth' => $max_depth];
}

function ll_tools_rest_corpus_text_validate_payload_budget(array $payload, string $raw_json = '') {
    $encoded = $raw_json !== '' ? $raw_json : wp_json_encode($payload);
    $encoded_bytes = is_string($encoded) ? strlen($encoded) : 0;
    if ($encoded_bytes > ll_tools_rest_corpus_text_payload_max_bytes()) {
        return new WP_Error(
            'll_tools_rest_corpus_text_payload_too_large',
            __('Corpus text payload is larger than the allowed automation import limit.', 'll-tools-text-domain'),
            ['status' => 413]
        );
    }

    $shape = ll_tools_rest_corpus_text_payload_shape($payload);
    if ((int) $shape['depth'] > ll_tools_rest_corpus_text_payload_max_depth()) {
        return new WP_Error(
            'll_tools_rest_corpus_text_payload_too_deep',
            __('Corpus text payload is too deeply nested for automation import.', 'll-tools-text-domain'),
            ['status' => 413]
        );
    }

    if ((int) $shape['rows'] > ll_tools_rest_corpus_text_payload_max_rows()) {
        return new WP_Error(
            'll_tools_rest_corpus_text_payload_too_many_rows',
            __('Corpus text payload contains too many rows for automation import.', 'll-tools-text-domain'),
            ['status' => 413]
        );
    }

    return true;
}

function ll_tools_rest_corpus_text_payload_from_request(WP_REST_Request $request) {
    $payload = $request->get_param('payload');
    if ($payload === null) {
        $params = $request->get_json_params();
        if (is_array($params) && (isset($params['schema']) || isset($params['source_lines']) || isset($params['reading_units']))) {
            $payload = $params;
        }
    }

    if (is_string($payload)) {
        $raw_payload = $payload;
        if (strlen($raw_payload) > ll_tools_rest_corpus_text_payload_max_bytes()) {
            return new WP_Error(
                'll_tools_rest_corpus_text_payload_too_large',
                __('Corpus text payload is larger than the allowed automation import limit.', 'll-tools-text-domain'),
                ['status' => 413]
            );
        }
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error(
                'll_tools_rest_corpus_text_invalid_json',
                __('Corpus text payload must be a valid JSON object.', 'll-tools-text-domain'),
                ['status' => 400]
            );
        }
        $payload = $decoded;
    } else {
        $raw_payload = '';
    }

    if (!is_array($payload)) {
        return new WP_Error(
            'll_tools_rest_corpus_text_missing_payload',
            __('Provide a corpus text payload object.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }

    $budget = ll_tools_rest_corpus_text_validate_payload_budget($payload, $raw_payload);
    if (is_wp_error($budget)) {
        return $budget;
    }

    return $payload;
}

function ll_tools_rest_corpus_text_excerpt(array $payload): string {
    $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
    foreach (['excerpt', 'summary_text', 'description'] as $excerpt_key) {
        if (isset($metadata[$excerpt_key]) && is_scalar($metadata[$excerpt_key]) && trim((string) $metadata[$excerpt_key]) !== '') {
            return trim((string) $metadata[$excerpt_key]);
        }
        if (isset($payload[$excerpt_key]) && is_scalar($payload[$excerpt_key]) && trim((string) $payload[$excerpt_key]) !== '') {
            return trim((string) $payload[$excerpt_key]);
        }
    }

    return __('Historical Zazaki text with source witnesses, interlinear analysis, and translations.', 'll-tools-text-domain');
}

function ll_tools_rest_import_corpus_text_asset(WP_REST_Request $request) {
    if (!current_user_can('upload_files')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_forbidden',
            __('You cannot upload corpus text assets.', 'll-tools-text-domain'),
            403
        );
    }

    $source_key = ll_tools_rest_corpus_text_normalize_source_key(ll_tools_rest_automation_request_string($request, 'source_key'));
    if ($source_key === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_missing_source',
            __('Provide a source key for the corpus text asset.', 'll-tools-text-domain'),
            400
        );
    }

    $existing_id = ll_tools_rest_corpus_text_find_attachment_by_source($source_key);
    if ($existing_id > 0) {
        $existing_url = wp_get_attachment_url($existing_id);
        return rest_ensure_response([
            'attachment_id' => $existing_id,
            'url' => is_string($existing_url) ? $existing_url : '',
            'source_key' => $source_key,
            'created' => false,
        ]);
    }

    $files = $request->get_file_params();
    $uploaded_file = [];
    foreach (['asset', 'file'] as $file_key) {
        if (!empty($files[$file_key]) && is_array($files[$file_key])) {
            $uploaded_file = $files[$file_key];
            break;
        }
    }
    if (empty($uploaded_file['tmp_name']) || empty($uploaded_file['name'])) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_missing_file',
            __('Provide a corpus text asset file.', 'll-tools-text-domain'),
            400
        );
    }

    $asset_size = isset($uploaded_file['size']) ? (int) $uploaded_file['size'] : 0;
    if ($asset_size <= 0 && is_string($uploaded_file['tmp_name']) && is_readable($uploaded_file['tmp_name'])) {
        $filesize = filesize($uploaded_file['tmp_name']);
        $asset_size = is_int($filesize) ? $filesize : 0;
    }
    if ($asset_size > ll_tools_rest_corpus_text_asset_max_bytes()) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_too_large',
            __('Corpus text asset file is larger than the allowed automation import limit.', 'll-tools-text-domain'),
            413
        );
    }

    if (is_string($uploaded_file['tmp_name']) && is_readable($uploaded_file['tmp_name'])) {
        $image_size = @getimagesize($uploaded_file['tmp_name']);
        if (is_array($image_size)) {
            $max_dimension = ll_tools_rest_corpus_text_asset_max_dimension();
            if ((int) ($image_size[0] ?? 0) > $max_dimension || (int) ($image_size[1] ?? 0) > $max_dimension) {
                return ll_tools_rest_automation_error(
                    'll_tools_rest_corpus_text_asset_dimensions_too_large',
                    __('Corpus text asset image dimensions exceed the allowed automation import limit.', 'll-tools-text-domain'),
                    413
                );
            }
        }
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $upload = wp_handle_sideload($uploaded_file, [
        'test_form' => false,
        'mimes' => [
            'webp' => 'image/webp',
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
        ],
    ]);
    if (!empty($upload['error']) || empty($upload['file'])) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_upload_failed',
            is_scalar($upload['error'] ?? null) ? (string) $upload['error'] : __('The corpus text asset could not be uploaded.', 'll-tools-text-domain'),
            400
        );
    }

    $post_parent = max(0, (int) $request->get_param('post_id'));
    $filetype = wp_check_filetype((string) $upload['file']);
    $attachment_id = wp_insert_attachment([
        'post_mime_type' => (string) ($filetype['type'] ?? 'image/webp'),
        'post_title' => sanitize_file_name(pathinfo((string) $uploaded_file['name'], PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
    ], (string) $upload['file'], $post_parent);
    if (is_wp_error($attachment_id)) {
        return ll_tools_rest_automation_with_status($attachment_id, 400);
    }
    if ((int) $attachment_id <= 0) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_asset_insert_failed',
            __('The corpus text asset could not be added to the media library.', 'll-tools-text-domain'),
            400
        );
    }

    $attachment_id = (int) $attachment_id;
    $metadata = wp_generate_attachment_metadata($attachment_id, (string) $upload['file']);
    if (is_array($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    update_post_meta($attachment_id, '_ll_texts_source_asset', $source_key);

    $url = wp_get_attachment_url($attachment_id);
    return rest_ensure_response([
        'attachment_id' => $attachment_id,
        'url' => is_string($url) ? $url : '',
        'source_key' => $source_key,
        'created' => true,
    ]);
}

function ll_tools_rest_import_corpus_text(WP_REST_Request $request) {
    $payload = ll_tools_rest_corpus_text_payload_from_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $post_slug = sanitize_title(ll_tools_rest_automation_request_string($request, 'post_slug'));
    if ($post_slug === '') {
        $lesson_id = isset($payload['lesson_id']) && is_scalar($payload['lesson_id']) ? (string) $payload['lesson_id'] : '';
        $title_for_slug = isset($payload['title']) && is_scalar($payload['title']) ? (string) $payload['title'] : '';
        $post_slug = sanitize_title($lesson_id !== '' ? $lesson_id : $title_for_slug);
    }
    if ($post_slug === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_missing_slug',
            __('Provide a corpus text post slug.', 'll-tools-text-domain'),
            400
        );
    }

    $status = sanitize_key(ll_tools_rest_automation_request_string($request, 'status'));
    if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
        $status = 'publish';
    }

    $title = isset($payload['title']) && is_scalar($payload['title']) && trim((string) $payload['title']) !== ''
        ? (string) $payload['title']
        : $post_slug;
    $excerpt = ll_tools_rest_corpus_text_excerpt($payload);

    $post = ll_tools_rest_corpus_text_find_post_by_slug($post_slug);
    if ($post instanceof WP_Post) {
        $post_id = wp_update_post([
            'ID' => (int) $post->ID,
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_status' => $status,
            'post_type' => 'll_content_lesson',
        ], true);
        $action = 'updated';
    } else {
        $post_id = wp_insert_post([
            'post_type' => 'll_content_lesson',
            'post_status' => $status,
            'post_title' => $title,
            'post_name' => $post_slug,
            'post_excerpt' => $excerpt,
        ], true);
        $action = 'created';
    }
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        if (!is_wp_error($post_id)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_corpus_text_post_failed',
                __('The corpus text post could not be created or updated.', 'll-tools-text-domain'),
                400
            );
        }
        return ll_tools_rest_automation_with_status($post_id, 400);
    }

    $post_id = (int) $post_id;
    if (defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META')) {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META);
    }
    if (defined('LL_TOOLS_CONTENT_LESSON_KIND_META')) {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');
    }

    $source = ll_tools_rest_automation_request_string($request, 'source');
    $updated = ll_tools_interlinear_set_payload($post_id, $payload, $source);
    if (is_wp_error($updated)) {
        return ll_tools_rest_automation_with_status($updated, 400);
    }

    $url = get_permalink($post_id);
    return rest_ensure_response([
        'action' => $action,
        'post_id' => $post_id,
        'post_slug' => $post_slug,
        'url' => is_string($url) ? $url : '',
        'summary' => ll_tools_interlinear_summary(is_array($updated['payload'] ?? null) ? $updated['payload'] : []),
        'updated_at' => isset($updated['updated_at']) ? (string) $updated['updated_at'] : '',
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

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcriptions', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_update_transcriptions',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-option-rules', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_option_rules',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/prompt-cards', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_prompt_cards',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'prompt_card_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'object_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'prompt_text' => [
                'required' => false,
                'type' => 'string',
            ],
            'prompt_audio_url' => [
                'required' => false,
                'type' => 'string',
            ],
            'prompt_audio_attachment_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'prompt_image_word_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'correct_answer_word_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'wrong_answer_word_ids' => [
                'required' => false,
            ],
            'track_answer_word_progress' => [
                'required' => false,
                'type' => 'boolean',
            ],
        ],
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

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/interlinear', [
        'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
        'callback' => 'll_tools_rest_automation_interlinear',
        'permission_callback' => 'll_tools_rest_automation_require_interlinear_access',
        'args' => [
            'lesson' => [
                'required' => false,
                'type' => 'string',
            ],
            'post_type' => [
                'required' => false,
                'type' => 'string',
            ],
            'include_empty' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'include_payload' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
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

    register_rest_route('ll-tools/v1', '/corpus-texts/asset', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_import_corpus_text_asset',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
        'args' => [
            'source_key' => [
                'required' => true,
                'type' => 'string',
            ],
            'post_id' => [
                'required' => false,
                'type' => 'integer',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/corpus-texts/import', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_import_corpus_text',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
        'args' => [
            'payload' => [
                'required' => true,
            ],
            'post_slug' => [
                'required' => false,
                'type' => 'string',
            ],
            'status' => [
                'required' => false,
                'type' => 'string',
            ],
            'source' => [
                'required' => false,
                'type' => 'string',
            ],
        ],
    ]);
}
add_action('rest_api_init', 'll_tools_rest_register_automation_routes');
