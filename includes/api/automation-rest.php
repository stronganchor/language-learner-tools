<?php
if (!defined('WPINC')) {
    die;
}

require_once LL_TOOLS_BASE_PATH . 'includes/cli/cli-support.php';
require_once LL_TOOLS_BASE_PATH . 'includes/api/word-metadata-plan-rest.php';

if (!defined('LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK')) {
    define('LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK', 'll_tools_transcription_validation_job_process_async');
}

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

function ll_tools_rest_automation_load_orthography_helpers(): void {
    if (!function_exists('ll_tools_ipa_orthography_get_conversion_profile')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/ipa-keyboard-admin.php';
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

function ll_tools_rest_automation_require_static_cache_purge_access() {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    if (!function_exists('ll_tools_current_user_can_purge_static_cache') || !ll_tools_current_user_can_purge_static_cache()) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_static_cache_purge_forbidden',
            __('You are not allowed to clear LL Tools caches on this site.', 'll-tools-text-domain'),
            403
        );
    }

    return true;
}

function ll_tools_rest_automation_require_plugin_update_access() {
    $view_check = ll_tools_rest_automation_require_view_access();
    if (is_wp_error($view_check)) {
        return $view_check;
    }

    if (!function_exists('ll_tools_user_can_manage_plugin_updates') || !ll_tools_user_can_manage_plugin_updates()) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_plugin_update_forbidden',
            __('You are not allowed to update LL Tools on this site.', 'll-tools-text-domain'),
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
        'word_title_updates' => [
            'default' => $dry_run ? 50 : 5,
            'max' => $dry_run ? 100 : 10,
        ],
        'word_helper_updates' => [
            'default' => $dry_run ? 50 : 10,
            'max' => $dry_run ? 100 : 25,
        ],
        'word_category_updates' => [
            'default' => $dry_run ? 10 : 5,
            'max' => $dry_run ? 25 : 10,
        ],
        'word_metadata_plan_jobs' => [
            'default' => 10,
            'max' => 25,
        ],
        'transcription_validation_jobs' => [
            'default' => 3,
            'max' => 5,
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

function ll_tools_rest_automation_transcription_validation_limits(WP_REST_Request $request, bool $dry_run): array {
    $raw_limit = $request->get_param('limit');
    $requested_limit = (is_scalar($raw_limit) && trim((string) $raw_limit) !== '')
        ? max(0, (int) $raw_limit)
        : 0;
    $default_limit = $dry_run ? 5 : 1;
    $max_limit = $dry_run
        ? max(1, (int) apply_filters('ll_tools_rest_transcription_validation_max_dry_run_limit', 10, $request))
        : 1;
    $effective_limit = $requested_limit > 0 ? $requested_limit : $default_limit;
    $limit_clamped = $effective_limit > $max_limit;
    $effective_limit = max(1, min($effective_limit, $max_limit));

    $raw_scan_limit = $request->get_param('scan_limit');
    $requested_scan_limit = (is_scalar($raw_scan_limit) && trim((string) $raw_scan_limit) !== '')
        ? max(0, (int) $raw_scan_limit)
        : 0;
    $default_scan_limit = $dry_run ? 50 : 25;
    $max_scan_limit = $dry_run
        ? max(1, (int) apply_filters('ll_tools_rest_transcription_validation_max_dry_run_scan_limit', 100, $request))
        : 25;
    $effective_scan_limit = $requested_scan_limit > 0 ? $requested_scan_limit : $default_scan_limit;
    $scan_limit_clamped = $effective_scan_limit > $max_scan_limit;
    $effective_scan_limit = max($effective_limit, min($effective_scan_limit, $max_scan_limit));

    return [
        'limit' => [
            'requested' => $requested_limit,
            'effective' => $effective_limit,
            'default' => $default_limit,
            'max' => $max_limit,
            'clamped' => $limit_clamped,
        ],
        'scan_limit' => [
            'requested' => $requested_scan_limit,
            'effective' => $effective_scan_limit,
            'default' => $default_scan_limit,
            'max' => $max_scan_limit,
            'clamped' => $scan_limit_clamped,
        ],
        'server_side_recommended' => !$dry_run,
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

function ll_tools_rest_resource_guard_is_automation_request(WP_REST_Request $request): bool {
    if (ll_tools_rest_resource_guard_has_authorization_header()) {
        return true;
    }

    foreach (['x-ll-tools-automation', 'x-codex-automation'] as $header_name) {
        if (trim((string) $request->get_header($header_name)) !== '') {
            return true;
        }
    }

    $user_agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($user_agent === '') {
        return false;
    }

    return (bool) preg_match('/(?:codex|ll tools|language learner tools|wordboat|automation)/', $user_agent);
}

function ll_tools_rest_resource_guard_policy(WP_REST_Request $request): array {
    if (!(bool) apply_filters('ll_tools_rest_resource_guard_enabled', true, $request)) {
        return [];
    }

    $method = strtoupper((string) $request->get_method());
    $is_write = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    $is_read = in_array($method, ['GET', 'HEAD'], true);
    $route = (string) $request->get_route();
    $is_auth_probe = $is_read && in_array($route, ['/ll-tools/v1/automation/status', '/wp/v2/users/me'], true);
    $is_guarded_read = $is_auth_probe
        || ($is_read && preg_match('#^/ll-tools/v1/imports/[^/]+(?:/result)?$#', $route))
        || ($is_read && preg_match('#^/ll-tools/v1/wordsets/[^/]+/word-metadata-plan-jobs/[^/]+(?:/result)?$#', $route))
        || ($is_read && preg_match('#^/ll-tools/v1/wordsets/[^/]+/(missing-meta|site-sync/snapshot|report|report-summary)$#', $route));
    if (!$is_write && !$is_guarded_read) {
        return [];
    }

    $resource = '';
    $delay_seconds = 5.0;
    $lock_ttl_seconds = 90.0;

    if ($route === '/ll-tools/v1/automation/status') {
        $resource = 'll_tools_automation_status';
        $delay_seconds = 2.0;
    } elseif ($route === '/wp/v2/users/me') {
        $resource = 'wp_users_me';
        $delay_seconds = 2.0;
    } elseif ($route === '/ll-tools/v1/automation/plugin-update') {
        $resource = 'll_tools_plugin_update';
        $delay_seconds = 10.0;
        $lock_ttl_seconds = 300.0;
    } elseif ($is_read && preg_match('#^/ll-tools/v1/imports/[^/]+(/result)?$#', $route, $matches)) {
        $resource = empty($matches[1]) ? 'll_tools_import_status' : 'll_tools_import_result';
        $delay_seconds = 3.0;
    } elseif ($is_read && preg_match('#^/ll-tools/v1/wordsets/[^/]+/word-metadata-plan-jobs/[^/]+(/result)?$#', $route, $matches)) {
        $resource = empty($matches[1]) ? 'll_tools_word_metadata_plan_status' : 'll_tools_word_metadata_plan_result';
        $delay_seconds = 3.0;
    } elseif ($is_read && preg_match('#^/ll-tools/v1/wordsets/[^/]+/transcription-validation-jobs/[^/]+$#', $route)) {
        $resource = 'll_tools_transcription_validation_job_status';
        $delay_seconds = 3.0;
    } elseif ($is_read && preg_match('#^/ll-tools/v1/wordsets/[^/]+/(missing-meta|site-sync/snapshot|report|report-summary)$#', $route, $matches)) {
        $resource = 'll_tools_' . sanitize_key((string) $matches[1]);
        $delay_seconds = in_array((string) $matches[1], ['site-sync/snapshot', 'report'], true) ? 5.0 : 3.0;
    } elseif (preg_match('#^/wp/v2/(media|word_images|words)(?:/\d+)?$#', $route, $matches)) {
        $resource = (string) $matches[1];
        $delay_seconds = in_array($resource, ['media', 'word_images'], true) ? 5.0 : 3.0;
    } elseif ($route === '/ll-tools/v1/cache/static/purge') {
        $resource = 'll_tools_static_cache_purge';
        $delay_seconds = 5.0;
    } elseif ($route === '/ll-tools/v1/wordsets') {
        $resource = 'll_tools_wordset_create';
        $delay_seconds = 5.0;
    } elseif (preg_match('#^/ll-tools/v1/wordsets/[^/]+/(bulk-update|word-title-updates|word-helper-updates|word-category-updates|transcriptions|transcription-validations|word-option-rules|orthography-conversion|prompt-cards|review-notes|interlinear|profile|translations)$#', $route, $matches)) {
        $resource = 'll_tools_' . sanitize_key((string) $matches[1]);
        if ((string) $matches[1] === 'transcription-validations') {
            $delay_seconds = 30.0;
            $lock_ttl_seconds = 180.0;
        } else {
            $delay_seconds = 5.0;
        }
    } elseif (preg_match('#^/ll-tools/v1/wordsets/[^/]+/word-metadata-plan-jobs(?:/[^/]+/(process|discard))?$#', $route, $matches)) {
        $resource = empty($matches[1]) ? 'll_tools_word_metadata_plan_create' : 'll_tools_word_metadata_plan_' . sanitize_key((string) $matches[1]);
        $delay_seconds = 5.0;
        $lock_ttl_seconds = 180.0;
    } elseif (preg_match('#^/ll-tools/v1/wordsets/[^/]+/transcription-validation-jobs(?:/[^/]+/process)?$#', $route, $matches)) {
        $resource = str_contains($route, '/process') ? 'll_tools_transcription_validation_job_process' : 'll_tools_transcription_validation_job_create';
        $delay_seconds = 5.0;
        $lock_ttl_seconds = 180.0;
    } elseif (preg_match('#^/ll-tools/v1/imports/(preview|start)$#', $route, $matches)) {
        $resource = 'll_tools_import_' . sanitize_key((string) $matches[1]);
        $delay_seconds = 5.0;
    } elseif (preg_match('#^/ll-tools/v1/imports/[^/]+/(process|discard)$#', $route, $matches)) {
        $resource = 'll_tools_import_' . sanitize_key((string) $matches[1]);
        $delay_seconds = 5.0;
    } elseif (preg_match('#^/ll-tools/v1/corpus-texts/(asset|import)$#', $route, $matches)) {
        $resource = 'll_tools_corpus_text_' . sanitize_key((string) $matches[1]);
        $delay_seconds = 5.0;
    }

    if ($resource === '') {
        return [];
    }

    if (!ll_tools_rest_resource_guard_is_automation_request($request)) {
        return [];
    }

    return [
        'scope' => (string) apply_filters('ll_tools_rest_resource_guard_scope', 'll_tools_rest_automation', $request, $resource),
        'resource' => $resource,
        'route' => $route,
        'delay_seconds' => max(0.1, (float) apply_filters('ll_tools_rest_resource_guard_delay_seconds', $delay_seconds, $request, $resource)),
        'lock_ttl_seconds' => max(10.0, (float) apply_filters('ll_tools_rest_resource_guard_lock_ttl_seconds', $lock_ttl_seconds, $request, $resource)),
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
    foreach (['ll_tools_rest_automation', 'll_tools_rest_automation_write'] as $scope) {
        delete_option(ll_tools_rest_resource_guard_option_name($scope, 'lock'));
        delete_option(ll_tools_rest_resource_guard_option_name($scope, 'next'));
    }
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
            'update_plugins' => current_user_can('update_plugins'),
            'manage_plugin_updates' => function_exists('ll_tools_user_can_manage_plugin_updates') ? ll_tools_user_can_manage_plugin_updates() : false,
            'purge_static_cache' => function_exists('ll_tools_current_user_can_purge_static_cache') ? ll_tools_current_user_can_purge_static_cache() : current_user_can('manage_options'),
        ],
        'routes' => [
            'status' => '/ll-tools/v1/automation/status',
            'plugin_update' => '/ll-tools/v1/automation/plugin-update',
            'static_cache_purge' => '/ll-tools/v1/cache/static/purge',
            'create_wordset' => '/ll-tools/v1/wordsets',
            'missing_meta' => '/ll-tools/v1/wordsets/{wordset}/missing-meta',
            'bulk_update' => '/ll-tools/v1/wordsets/{wordset}/bulk-update',
            'word_title_updates' => '/ll-tools/v1/wordsets/{wordset}/word-title-updates',
            'word_helper_updates' => '/ll-tools/v1/wordsets/{wordset}/word-helper-updates',
            'word_category_updates' => '/ll-tools/v1/wordsets/{wordset}/word-category-updates',
            'word_metadata_plan_jobs' => '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs',
            'word_metadata_plan_job_status' => '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}',
            'word_metadata_plan_job_process' => '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process',
            'word_metadata_plan_job_discard' => '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/discard',
            'word_metadata_plan_job_result' => '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result',
            'transcriptions' => '/ll-tools/v1/wordsets/{wordset}/transcriptions',
            'transcription_validations' => '/ll-tools/v1/wordsets/{wordset}/transcription-validations',
            'transcription_validation_jobs' => '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs',
            'transcription_validation_job_status' => '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs/{job_id}',
            'transcription_validation_job_process' => '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs/{job_id}/process',
            'site_sync_snapshot' => '/ll-tools/v1/wordsets/{wordset}/site-sync/snapshot',
            'word_option_rules' => '/ll-tools/v1/wordsets/{wordset}/word-option-rules',
            'orthography_conversion' => '/ll-tools/v1/wordsets/{wordset}/orthography-conversion',
            'wordset_profile' => '/ll-tools/v1/wordsets/{wordset}/profile',
            'wordset_translations' => '/ll-tools/v1/wordsets/{wordset}/translations',
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
            'corpus_text_asset' => '/ll-tools/v1/corpus-texts/asset',
            'corpus_text_import' => '/ll-tools/v1/corpus-texts/import',
            'corpus_text' => '/ll-tools/v1/corpus-texts/{slug}',
        ],
        'resource_guard' => [
            'retry_status' => 429,
            'shared_scope' => 'll_tools_rest_automation',
            'automation_context' => [
                'authorization_header' => true,
                'headers' => [
                    'X-LL-Tools-Automation',
                    'X-Codex-Automation',
                ],
                'user_agent_patterns' => [
                    'codex',
                    'll tools',
                    'language learner tools',
                    'wordboat',
                    'automation',
                ],
            ],
            'auth_probe_routes' => [
                '/ll-tools/v1/automation/status',
                '/wp/v2/users/me',
            ],
            'guarded_read_routes' => [
                '/ll-tools/v1/automation/status',
                '/wp/v2/users/me',
                '/ll-tools/v1/imports/{job_id}',
                '/ll-tools/v1/imports/{job_id}/result',
                '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}',
                '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result',
                '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs/{job_id}',
                '/ll-tools/v1/wordsets/{wordset}/missing-meta',
                '/ll-tools/v1/wordsets/{wordset}/site-sync/snapshot',
                '/ll-tools/v1/wordsets/{wordset}/report',
                '/ll-tools/v1/wordsets/{wordset}/report-summary',
            ],
            'core_write_routes' => [
                '/wp/v2/media',
                '/wp/v2/word_images',
                '/wp/v2/words',
            ],
            'automation_write_routes' => [
                '/ll-tools/v1/automation/plugin-update',
                '/ll-tools/v1/cache/static/purge',
                '/ll-tools/v1/wordsets',
                '/ll-tools/v1/wordsets/{wordset}/bulk-update',
                '/ll-tools/v1/wordsets/{wordset}/word-title-updates',
                '/ll-tools/v1/wordsets/{wordset}/word-helper-updates',
                '/ll-tools/v1/wordsets/{wordset}/word-category-updates',
                '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs',
                '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process',
                '/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/discard',
                '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs',
                '/ll-tools/v1/wordsets/{wordset}/transcription-validation-jobs/{job_id}/process',
                '/ll-tools/v1/wordsets/{wordset}/transcriptions',
                '/ll-tools/v1/wordsets/{wordset}/transcription-validations',
                '/ll-tools/v1/wordsets/{wordset}/word-option-rules',
                '/ll-tools/v1/wordsets/{wordset}/orthography-conversion',
                '/ll-tools/v1/wordsets/{wordset}/profile',
                '/ll-tools/v1/wordsets/{wordset}/translations',
                '/ll-tools/v1/wordsets/{wordset}/prompt-cards',
                '/ll-tools/v1/wordsets/{wordset}/review-notes',
                '/ll-tools/v1/wordsets/{wordset}/interlinear',
                '/ll-tools/v1/imports/preview',
                '/ll-tools/v1/imports/start',
                '/ll-tools/v1/imports/{job_id}/process',
                '/ll-tools/v1/imports/{job_id}/discard',
                '/ll-tools/v1/corpus-texts/asset',
                '/ll-tools/v1/corpus-texts/import',
            ],
            'bulk_update_batch' => [
                'default_write_limit' => ll_tools_rest_automation_batch_limit('bulk_update', false)['default'],
                'max_write_limit' => ll_tools_rest_automation_batch_limit('bulk_update', false)['max'],
                'default_dry_run_limit' => ll_tools_rest_automation_batch_limit('bulk_update', true)['default'],
                'max_dry_run_limit' => ll_tools_rest_automation_batch_limit('bulk_update', true)['max'],
            ],
            'word_title_updates_batch' => [
                'default_write_limit' => ll_tools_rest_automation_batch_limit('word_title_updates', false)['default'],
                'max_write_limit' => ll_tools_rest_automation_batch_limit('word_title_updates', false)['max'],
                'default_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_title_updates', true)['default'],
                'max_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_title_updates', true)['max'],
            ],
            'word_helper_updates_batch' => [
                'default_write_limit' => ll_tools_rest_automation_batch_limit('word_helper_updates', false)['default'],
                'max_write_limit' => ll_tools_rest_automation_batch_limit('word_helper_updates', false)['max'],
                'default_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_helper_updates', true)['default'],
                'max_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_helper_updates', true)['max'],
            ],
            'word_category_updates_batch' => [
                'default_write_limit' => ll_tools_rest_automation_batch_limit('word_category_updates', false)['default'],
                'max_write_limit' => ll_tools_rest_automation_batch_limit('word_category_updates', false)['max'],
                'default_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_category_updates', true)['default'],
                'max_dry_run_limit' => ll_tools_rest_automation_batch_limit('word_category_updates', true)['max'],
            ],
            'word_metadata_plan_jobs_batch' => [
                'default_process_limit' => ll_tools_rest_automation_batch_limit('word_metadata_plan_jobs', false)['default'],
                'max_process_limit' => ll_tools_rest_automation_batch_limit('word_metadata_plan_jobs', false)['max'],
                'max_plan_items' => ll_tools_rest_word_metadata_plan_max_items(),
                'supported_fields' => ll_tools_rest_word_metadata_plan_supported_fields(),
                'server_side_recommended' => true,
            ],
            'transcription_validation_jobs_batch' => [
                'default_process_limit' => ll_tools_rest_automation_batch_limit('transcription_validation_jobs', false)['default'],
                'max_process_limit' => ll_tools_rest_automation_batch_limit('transcription_validation_jobs', false)['max'],
                'default_max_candidates' => 500,
                'max_candidates' => (int) apply_filters('ll_tools_rest_transcription_validation_job_max_candidates', 5000),
                'server_side_recommended' => true,
            ],
            'missing_meta_batch' => [
                'default_limit' => ll_tools_rest_automation_batch_limit('missing_meta', false)['default'],
                'max_limit' => ll_tools_rest_automation_batch_limit('missing_meta', false)['max'],
            ],
            'transcription_validations_batch' => [
                'default_write_limit' => 1,
                'max_write_limit' => 1,
                'default_write_scan_limit' => 25,
                'max_write_scan_limit' => 25,
                'default_dry_run_limit' => 5,
                'max_dry_run_limit' => (int) apply_filters('ll_tools_rest_transcription_validation_max_dry_run_limit', 10, $request),
                'default_dry_run_scan_limit' => 50,
                'max_dry_run_scan_limit' => (int) apply_filters('ll_tools_rest_transcription_validation_max_dry_run_scan_limit', 100, $request),
                'server_side_recommended' => true,
            ],
            'rest_import_word_image_chunk_size' => (int) apply_filters('ll_tools_rest_import_word_image_chunk_size', 8),
        ],
    ]);
}

function ll_tools_rest_automation_plugin_update_read_plugin_data(): array {
    if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $data = [];
    if (function_exists('get_plugin_data')) {
        $data = get_plugin_data(LL_TOOLS_MAIN_FILE, false, false);
    } elseif (function_exists('get_file_data')) {
        $data = get_file_data(LL_TOOLS_MAIN_FILE, [
            'Name' => 'Plugin Name',
            'Version' => 'Version',
            'TextDomain' => 'Text Domain',
        ], 'plugin');
    }

    return [
        'name' => is_array($data) ? (string) ($data['Name'] ?? '') : '',
        'version' => is_array($data) ? (string) ($data['Version'] ?? '') : '',
        'text_domain' => is_array($data) ? (string) ($data['TextDomain'] ?? '') : '',
    ];
}

function ll_tools_rest_automation_plugin_update_normalize_channel($channel): string {
    $channel = sanitize_key(is_scalar($channel) ? (string) $channel : '');
    if ($channel === '' || $channel === 'dev') {
        return 'dev';
    }

    if (in_array($channel, ['configured', 'current'], true)) {
        return function_exists('ll_tools_get_update_branch') ? ll_tools_get_update_branch() : 'main';
    }

    return $channel;
}

function ll_tools_rest_automation_plugin_update_package_url(string $channel): string {
    if ($channel !== 'dev') {
        return '';
    }

    return 'https://github.com/stronganchor/language-learner-tools/archive/refs/heads/dev.zip';
}

function ll_tools_rest_automation_plugin_update_validate_package_url(string $package_url): bool {
    $parts = wp_parse_url($package_url);
    if (!is_array($parts)) {
        return false;
    }

    return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && strtolower((string) ($parts['host'] ?? '')) === 'github.com'
        && (string) ($parts['path'] ?? '') === '/stronganchor/language-learner-tools/archive/refs/heads/dev.zip';
}

function ll_tools_rest_automation_plugin_update_plugin_file(): string {
    $plugin_file = plugin_basename(LL_TOOLS_MAIN_FILE);
    if ($plugin_file === '' || strpos($plugin_file, ':') !== false || strpos($plugin_file, '\\') !== false || substr($plugin_file, 0, 1) === '/') {
        $plugin_file = basename(dirname(LL_TOOLS_MAIN_FILE)) . '/' . basename(LL_TOOLS_MAIN_FILE);
    }

    return $plugin_file;
}

function ll_tools_rest_automation_plugin_update_payload(string $channel, string $package_url, bool $dry_run, array $extra = []): array {
    $plugin_data = ll_tools_rest_automation_plugin_update_read_plugin_data();
    $plugin_file = ll_tools_rest_automation_plugin_update_plugin_file();
    $is_active = function_exists('is_plugin_active') ? (bool) is_plugin_active($plugin_file) : null;
    $is_network_active = function_exists('is_plugin_active_for_network') ? (bool) is_plugin_active_for_network($plugin_file) : null;

    return array_merge([
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'performed_update' => false,
        'channel' => $channel,
        'supported_channels' => ['dev'],
        'package_url' => $package_url,
        'requires_confirm' => true,
        'plugin' => [
            'basename' => $plugin_file,
            'directory' => basename(dirname(LL_TOOLS_MAIN_FILE)),
            'loaded_version' => defined('LL_TOOLS_VERSION') ? LL_TOOLS_VERSION : '',
            'installed_file_version' => (string) ($plugin_data['version'] ?? ''),
            'name' => (string) ($plugin_data['name'] ?? ''),
            'text_domain' => (string) ($plugin_data['text_domain'] ?? ''),
            'active' => $is_active,
            'network_active' => $is_network_active,
        ],
    ], $extra);
}

function ll_tools_rest_automation_plugin_update_error_from_upgrader($result, $skin, string $fallback_code, string $fallback_message): WP_Error {
    if ($result instanceof WP_Error) {
        $data = $result->get_error_data();
        if (is_array($data)) {
            $data['status'] = (int) ($data['status'] ?? 500);
            return new WP_Error($result->get_error_code(), $result->get_error_message(), $data);
        }

        return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
    }

    $messages = [];
    if (is_object($skin) && method_exists($skin, 'get_upgrade_messages')) {
        $messages = array_values(array_map('wp_strip_all_tags', array_map('strval', (array) $skin->get_upgrade_messages())));
    }

    return new WP_Error($fallback_code, $fallback_message, [
        'status' => 500,
        'messages' => $messages,
    ]);
}

function ll_tools_rest_automation_plugin_update_source_selection($source, $remote_source, $upgrader, $hook_extra) {
    if (!is_string($source) || $source === '' || !is_string($remote_source) || $remote_source === '') {
        return $source;
    }

    $expected_dir = basename(dirname(LL_TOOLS_MAIN_FILE));
    if ($expected_dir === '' || basename($source) === $expected_dir) {
        return $source;
    }

    if (strpos(basename($source), $expected_dir) !== 0) {
        return $source;
    }

    global $wp_filesystem;
    if (!is_object($wp_filesystem) || !method_exists($wp_filesystem, 'move')) {
        return $source;
    }

    $target = trailingslashit($remote_source) . $expected_dir;
    if ($wp_filesystem->exists($target)) {
        $wp_filesystem->delete($target, true);
    }

    return $wp_filesystem->move($source, $target, true) ? $target : $source;
}

function ll_tools_rest_automation_plugin_update(WP_REST_Request $request) {
    $channel = ll_tools_rest_automation_plugin_update_normalize_channel($request->get_param('channel'));
    $package_url = ll_tools_rest_automation_plugin_update_package_url($channel);
    $dry_run = $request->has_param('dry_run') ? (bool) rest_sanitize_boolean($request->get_param('dry_run')) : true;

    if ($package_url === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_plugin_update_unsupported_channel',
            __('LL Tools automation updates currently support only the dev branch package.', 'll-tools-text-domain'),
            400
        );
    }

    if (!ll_tools_rest_automation_plugin_update_validate_package_url($package_url)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_plugin_update_invalid_package',
            __('The LL Tools automation update package URL failed the built-in allowlist check.', 'll-tools-text-domain'),
            500
        );
    }

    $before_plugin_data = ll_tools_rest_automation_plugin_update_read_plugin_data();
    $before_version = (string) ($before_plugin_data['version'] ?? '');
    $expected_current_version = trim(ll_tools_rest_automation_request_string($request, 'expected_current_version'));
    if ($expected_current_version !== '' && $before_version !== $expected_current_version) {
        return new WP_Error(
            'll_tools_rest_plugin_update_current_version_mismatch',
            __('The installed LL Tools version does not match the expected current version, so the automation update was not started.', 'll-tools-text-domain'),
            [
                'status' => 409,
                'expected_current_version' => $expected_current_version,
                'installed_file_version' => $before_version,
            ]
        );
    }

    if ($dry_run) {
        return rest_ensure_response(ll_tools_rest_automation_plugin_update_payload($channel, $package_url, true));
    }

    $confirm = $request->has_param('confirm') ? (bool) rest_sanitize_boolean($request->get_param('confirm')) : false;
    if (!$confirm) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_plugin_update_confirmation_required',
            __('Set confirm=true and dry_run=false to update LL Tools through automation.', 'll-tools-text-domain'),
            400
        );
    }

    if (!defined('ABSPATH')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_plugin_update_missing_abspath',
            __('WordPress path constants are unavailable, so the plugin updater cannot run.', 'll-tools-text-domain'),
            500
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $plugin_file = ll_tools_rest_automation_plugin_update_plugin_file();
    $was_active = function_exists('is_plugin_active') ? (bool) is_plugin_active($plugin_file) : false;
    $was_network_active = function_exists('is_plugin_active_for_network') ? (bool) is_plugin_active_for_network($plugin_file) : false;
    $previous_transient = get_site_transient('update_plugins');
    $update_transient = is_object($previous_transient) ? clone $previous_transient : new stdClass();
    if (!isset($update_transient->response) || !is_array($update_transient->response)) {
        $update_transient->response = [];
    }

    $expected_version = trim(ll_tools_rest_automation_request_string($request, 'expected_version'));
    $update_transient->response[$plugin_file] = (object) [
        'id' => 'github.com/stronganchor/language-learner-tools',
        'slug' => 'language-learner-tools',
        'plugin' => $plugin_file,
        'new_version' => $expected_version !== '' ? $expected_version : 'dev',
        'url' => 'https://github.com/stronganchor/language-learner-tools',
        'package' => $package_url,
    ];
    set_site_transient('update_plugins', $update_transient, MINUTE_IN_SECONDS);

    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    add_filter('upgrader_source_selection', 'll_tools_rest_automation_plugin_update_source_selection', 10, 4);
    $result = $upgrader->upgrade($plugin_file, ['clear_update_cache' => true]);
    remove_filter('upgrader_source_selection', 'll_tools_rest_automation_plugin_update_source_selection', 10);
    if ($result !== true) {
        if (is_object($previous_transient)) {
            set_site_transient('update_plugins', $previous_transient);
        } else {
            delete_site_transient('update_plugins');
        }

        return ll_tools_rest_automation_plugin_update_error_from_upgrader(
            $result,
            $skin,
            'll_tools_rest_plugin_update_failed',
            __('LL Tools plugin update failed.', 'll-tools-text-domain')
        );
    }

    delete_site_transient('update_plugins');
    $reactivated = false;
    $active_after_update = function_exists('is_plugin_active') ? (bool) is_plugin_active($plugin_file) : null;
    $network_active_after_update = function_exists('is_plugin_active_for_network') ? (bool) is_plugin_active_for_network($plugin_file) : null;
    if (($was_active || $was_network_active)
        && function_exists('activate_plugin')
        && !($was_network_active ? (bool) $network_active_after_update : (bool) $active_after_update)) {
        $activation_result = activate_plugin($plugin_file, '', $was_network_active, true);
        if (is_wp_error($activation_result)) {
            return new WP_Error(
                'll_tools_rest_plugin_update_reactivation_failed',
                __('LL Tools plugin files were updated, but the plugin could not be reactivated automatically.', 'll-tools-text-domain'),
                [
                    'status' => 500,
                    'before_version' => $before_version,
                    'expected_version' => $expected_version,
                    'was_active' => $was_active,
                    'was_network_active' => $was_network_active,
                    'reactivation_error' => $activation_result->get_error_message(),
                ]
            );
        }
        $reactivated = true;
        $active_after_update = function_exists('is_plugin_active') ? (bool) is_plugin_active($plugin_file) : $active_after_update;
        $network_active_after_update = function_exists('is_plugin_active_for_network') ? (bool) is_plugin_active_for_network($plugin_file) : $network_active_after_update;
    }

    if (function_exists('ll_tools_schedule_post_update_maintenance')) {
        ll_tools_schedule_post_update_maintenance();
    }

    $messages = method_exists($skin, 'get_upgrade_messages')
        ? array_values(array_map('wp_strip_all_tags', array_map('strval', (array) $skin->get_upgrade_messages())))
        : [];
    $after_plugin_data = ll_tools_rest_automation_plugin_update_read_plugin_data();
    $after_version = (string) ($after_plugin_data['version'] ?? '');

    return rest_ensure_response(ll_tools_rest_automation_plugin_update_payload($channel, $package_url, false, [
        'performed_update' => true,
        'before_version' => $before_version,
        'after_version' => $after_version,
        'expected_version' => $expected_version,
        'expected_version_matched' => $expected_version === '' || $expected_version === $after_version,
        'activation' => [
            'was_active' => $was_active,
            'was_network_active' => $was_network_active,
            'reactivated' => $reactivated,
            'active_after_update' => $active_after_update,
            'network_active_after_update' => $network_active_after_update,
        ],
        'messages' => $messages,
        'plugin' => [
            'basename' => $plugin_file,
            'directory' => basename(dirname(LL_TOOLS_MAIN_FILE)),
            'loaded_version' => defined('LL_TOOLS_VERSION') ? LL_TOOLS_VERSION : '',
            'installed_file_version' => $after_version,
            'name' => (string) ($after_plugin_data['name'] ?? ''),
            'text_domain' => (string) ($after_plugin_data['text_domain'] ?? ''),
            'active' => $active_after_update,
            'network_active' => $network_active_after_update,
        ],
    ]));
}

function ll_tools_rest_automation_purge_static_cache(WP_REST_Request $request) {
    $target = function_exists('ll_tools_normalize_static_cache_purge_target')
        ? ll_tools_normalize_static_cache_purge_target($request->get_param('cache'))
        : sanitize_key((string) $request->get_param('cache'));

    if ($target === '') {
        return ll_tools_rest_automation_error(
            'll_tools_rest_static_cache_invalid_target',
            __('Use cache=all, cache=dictionary, or cache=public.', 'll-tools-text-domain'),
            400
        );
    }

    $result = function_exists('ll_tools_purge_static_caches')
        ? ll_tools_purge_static_caches($target)
        : [
            'target' => $target,
            'deleted' => 0,
            'caches' => [],
        ];

    return rest_ensure_response([
        'purged' => true,
        'target' => (string) ($result['target'] ?? $target),
        'deleted' => max(0, (int) ($result['deleted'] ?? 0)),
        'caches' => is_array($result['caches'] ?? null) ? $result['caches'] : [],
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

    $distinct_updates = $request->get_param('updates');
    if (is_array($distinct_updates) && !empty($distinct_updates)) {
        return ll_tools_rest_automation_word_bulk_update_distinct($request, $wordset_term, $distinct_updates);
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

function ll_tools_rest_automation_word_bulk_update_distinct(WP_REST_Request $request, WP_Term $wordset_term, array $updates) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $max_updates = 250;
    $updates = array_values($updates);
    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'batch_mode' => 'updates',
        'input_count' => count($updates),
        'max_updates' => $max_updates,
        'matched_count' => 0,
        'updated_count' => 0,
        'matched_rows' => [],
        'updated' => [],
        'errors' => [],
    ];

    if (count($updates) > $max_updates) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_too_many_word_updates',
            sprintf(
                /* translators: %d: maximum update count */
                __('Too many word updates in one request. Maximum is %d.', 'll-tools-text-domain'),
                $max_updates
            )
        ), 400);
    }

    foreach ($updates as $index => $update) {
        $update = is_array($update) ? $update : [];
        $word_spec = trim((string) ($update['word'] ?? $update['word_id'] ?? ''));
        $field = sanitize_key((string) ($update['field'] ?? ''));
        $value = (string) ($update['value'] ?? '');
        if (isset($update['set'])) {
            $set = $update['set'];
            if (is_array($set)) {
                $field = sanitize_key((string) ($set['field'] ?? $field));
                $value = (string) ($set['value'] ?? $value);
            } elseif (is_string($set) && strpos($set, '=') !== false) {
                [$set_field, $set_value] = explode('=', $set, 2);
                $field = sanitize_key((string) $set_field);
                $value = (string) $set_value;
            }
        }

        if ($word_spec === '') {
            $summary['errors'][] = [
                'index' => $index,
                'message' => __('Missing word identifier.', 'll-tools-text-domain'),
            ];
            continue;
        }
        if ($field === '' || !in_array($field, ll_tools_cli_supported_update_fields(), true)) {
            $summary['errors'][] = [
                'index' => $index,
                'word' => $word_spec,
                'message' => sprintf(
                    /* translators: %s: field name */
                    __('Unsupported update field: %s', 'll-tools-text-domain'),
                    $field
                ),
            ];
            continue;
        }

        $word_id = ll_tools_cli_resolve_word_id((int) $wordset_term->term_id, $word_spec);
        if (is_wp_error($word_id)) {
            $summary['errors'][] = [
                'index' => $index,
                'word' => $word_spec,
                'message' => $word_id->get_error_message(),
            ];
            continue;
        }

        $word_id = (int) $word_id;
        $before_rows = ll_tools_cli_get_word_rows((int) $wordset_term->term_id, [$word_id]);
        $before = $before_rows[0] ?? [];
        if (empty($before)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Unable to load the current word state.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $summary['matched_count']++;
        $summary['matched_rows'][] = ll_tools_cli_prepare_word_rows_for_output([$before])[0] ?? [];

        if ($dry_run) {
            continue;
        }

        $update_result = ll_tools_cli_apply_word_field_update(
            (int) $wordset_term->term_id,
            $word_id,
            $field,
            $value
        );

        if (is_wp_error($update_result)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
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
    }

    return rest_ensure_response($summary);
}

function ll_tools_rest_automation_sanitize_word_title_value($value): string {
    $value = is_scalar($value) ? (string) $value : '';

    return function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($value)
        : trim(sanitize_text_field($value));
}

function ll_tools_rest_automation_sanitize_word_title_guard_value($value): string {
    $value = is_scalar($value) ? (string) $value : '';

    return trim(sanitize_text_field($value));
}

function ll_tools_rest_automation_fetch_word_title_records(int $wordset_id, array $word_ids): array {
    global $wpdb;

    $wordset_id = (int) $wordset_id;
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if ($wordset_id <= 0 || empty($word_ids)) {
        return [];
    }

    $records = [];
    foreach (array_chunk($word_ids, 500) as $chunk) {
        $id_placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_name
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE p.ID IN ($id_placeholders)
               AND p.post_type = %s
               AND p.post_status <> %s
               AND tt.taxonomy = %s
               AND tt.term_id = %d",
            array_merge($chunk, ['words', 'trash', 'wordset', $wordset_id])
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ((array) $rows as $row) {
            $word_id = (int) ($row['ID'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $records[$word_id] = [
                'word_id' => $word_id,
                'word_title' => (string) ($row['post_title'] ?? ''),
                'word_slug' => (string) ($row['post_name'] ?? ''),
            ];
        }
    }

    return $records;
}

function ll_tools_rest_automation_update_word_title_direct(int $word_id, string $word_title) {
    global $wpdb;

    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return new WP_Error('ll_tools_rest_invalid_word_title_update_id', __('Missing word ID.', 'll-tools-text-domain'));
    }

    $result = $wpdb->update(
        $wpdb->posts,
        [
            'post_title' => $word_title,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
        ],
        [
            'ID' => $word_id,
        ],
        [
            '%s',
            '%s',
            '%s',
        ],
        [
            '%d',
        ]
    );

    if ($result === false) {
        return new WP_Error(
            'll_tools_rest_word_title_update_failed',
            __('Failed to update the word title.', 'll-tools-text-domain')
        );
    }

    return (int) $result;
}

function ll_tools_rest_automation_word_title_updates(WP_REST_Request $request) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $raw_updates = $request->get_param('updates');
    $raw_updates = is_array($raw_updates) ? array_values($raw_updates) : [];
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $batch_settings = ll_tools_rest_automation_batch_limit('word_title_updates', $dry_run);
    $max_updates = (int) $batch_settings['max'];
    $started = microtime(true);

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'batch_mode' => 'word_title_updates',
        'input_count' => count($raw_updates),
        'max_updates' => $max_updates,
        'batch' => [
            'default_limit' => (int) $batch_settings['default'],
            'max_limit' => (int) $batch_settings['max'],
        ],
        'matched_count' => 0,
        'changed_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'skipped_count' => 0,
        'updated' => [],
        'skipped' => [],
        'errors' => [],
    ];

    if (empty($raw_updates)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_missing_word_title_updates',
            __('Provide one or more word title updates.', 'll-tools-text-domain')
        ), 400);
    }

    if (count($raw_updates) > $max_updates) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_too_many_word_title_updates',
            sprintf(
                /* translators: %d: maximum update count */
                __('Too many word title updates in one request. Maximum is %d.', 'll-tools-text-domain'),
                $max_updates
            )
        ), 400);
    }

    $updates = [];
    $seen_word_ids = [];
    foreach ($raw_updates as $index => $update) {
        $update = is_array($update) ? $update : [];
        $raw_word_id = $update['word_id'] ?? $update['id'] ?? '';
        $word_id = is_scalar($raw_word_id) ? (int) $raw_word_id : 0;
        if ($word_id <= 0) {
            $summary['errors'][] = [
                'index' => $index,
                'message' => __('Missing word ID.', 'll-tools-text-domain'),
            ];
            continue;
        }

        if (isset($seen_word_ids[$word_id])) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Duplicate word ID in this batch.', 'll-tools-text-domain'),
            ];
            continue;
        }
        $seen_word_ids[$word_id] = true;

        $has_title = array_key_exists('title', $update) || array_key_exists('word_title', $update) || array_key_exists('post_title', $update);
        $raw_title = $update['title'] ?? $update['word_title'] ?? $update['post_title'] ?? '';
        $word_title = ll_tools_rest_automation_sanitize_word_title_value($raw_title);
        if (!$has_title || $word_title === '') {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Missing replacement title.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $has_old_title = array_key_exists('old_title', $update) || array_key_exists('old_word_title', $update) || array_key_exists('old_post_title', $update);
        $raw_old_title = $update['old_title'] ?? $update['old_word_title'] ?? $update['old_post_title'] ?? '';

        $updates[] = [
            'index' => $index,
            'word_id' => $word_id,
            'title' => $word_title,
            'has_old_title' => $has_old_title,
            'old_title' => $has_old_title ? ll_tools_rest_automation_sanitize_word_title_guard_value($raw_old_title) : '',
        ];
    }

    if (empty($updates)) {
        $summary['elapsed_seconds'] = round(microtime(true) - $started, 3);
        return rest_ensure_response($summary);
    }

    $records = ll_tools_rest_automation_fetch_word_title_records(
        (int) $wordset_term->term_id,
        array_map(static function (array $update): int {
            return (int) $update['word_id'];
        }, $updates)
    );

    $changes = [];
    foreach ($updates as $update) {
        $word_id = (int) $update['word_id'];
        $record = $records[$word_id] ?? null;
        if (!is_array($record)) {
            $summary['skipped'][] = [
                'index' => (int) $update['index'],
                'word_id' => $word_id,
                'reason' => 'not_in_wordset',
            ];
            $summary['skipped_count']++;
            continue;
        }

        $current_title = (string) ($record['word_title'] ?? '');
        $summary['matched_count']++;

        if (!empty($update['has_old_title']) && $current_title !== (string) $update['old_title']) {
            $summary['skipped'][] = [
                'index' => (int) $update['index'],
                'word_id' => $word_id,
                'word_slug' => (string) ($record['word_slug'] ?? ''),
                'reason' => 'old_title_mismatch',
                'current_title' => $current_title,
                'expected_old_title' => (string) $update['old_title'],
                'requested_title' => (string) $update['title'],
            ];
            $summary['skipped_count']++;
            continue;
        }

        if ($current_title === (string) $update['title']) {
            $summary['unchanged_count']++;
            continue;
        }

        $changes[] = [
            'index' => (int) $update['index'],
            'word_id' => $word_id,
            'word_slug' => (string) ($record['word_slug'] ?? ''),
            'before_title' => $current_title,
            'after_title' => (string) $update['title'],
        ];
    }

    $summary['changed_count'] = count($changes);

    if (!$dry_run && !empty($changes)) {
        $changed_word_ids = [];
        foreach ($changes as $change) {
            $word_id = (int) $change['word_id'];
            $result = ll_tools_rest_automation_update_word_title_direct($word_id, (string) $change['after_title']);
            if (is_wp_error($result)) {
                $summary['errors'][] = [
                    'index' => (int) $change['index'],
                    'word_id' => $word_id,
                    'message' => $result->get_error_message(),
                ];
                continue;
            }

            $summary['updated'][] = [
                'index' => (int) $change['index'],
                'word_id' => $word_id,
                'word_slug' => (string) $change['word_slug'],
                'changed' => true,
                'before_title' => (string) $change['before_title'],
                'after_title' => (string) $change['after_title'],
            ];
            $summary['updated_count']++;
            $changed_word_ids[] = $word_id;
        }

        $changed_word_ids = array_values(array_unique(array_filter(array_map('intval', $changed_word_ids))));
        foreach ($changed_word_ids as $changed_word_id) {
            clean_post_cache($changed_word_id);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_word_grid_bump_category_cache_for_words')) {
            ll_tools_word_grid_bump_category_cache_for_words($changed_word_ids);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_purge_public_static_cache_once')) {
            ll_tools_purge_public_static_cache_once(['wordset_ids' => [(int) $wordset_term->term_id]]);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_purge_wordset_buttons_shortcode_cache_once')) {
            ll_tools_purge_wordset_buttons_shortcode_cache_once();
        }
    }

    if ($dry_run) {
        $summary['updated'] = array_map(static function (array $change): array {
            return $change + [
                'changed' => true,
            ];
        }, $changes);
    }

    $summary['elapsed_seconds'] = round(microtime(true) - $started, 3);

    return rest_ensure_response($summary);
}

function ll_tools_rest_automation_sanitize_helper_translation_value($value): string {
    $value = is_scalar($value) ? (string) $value : '';

    return trim(sanitize_text_field($value));
}

function ll_tools_rest_automation_find_helper_translation_value(array $update, array $keys, bool &$has_value): string {
    foreach ($keys as $key) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (array_key_exists($key, $update)) {
            $has_value = true;
            return ll_tools_rest_automation_sanitize_helper_translation_value($update[$key]);
        }
    }

    $has_value = false;
    return '';
}

function ll_tools_rest_automation_sanitize_helper_dictionary_entry_id($value) {
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') {
        return 0;
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return new WP_Error(
            'll_tools_rest_invalid_dictionary_entry_id',
            __('Dictionary entry ID must be a positive integer, or 0 to unlink.', 'll-tools-text-domain')
        );
    }

    $entry_id = (int) $raw;
    if ($entry_id > 0 && function_exists('ll_tools_is_dictionary_entry_id') && !ll_tools_is_dictionary_entry_id($entry_id)) {
        return new WP_Error(
            'll_tools_rest_invalid_dictionary_entry_id',
            __('Invalid dictionary entry ID.', 'll-tools-text-domain')
        );
    }

    return max(0, $entry_id);
}

function ll_tools_rest_automation_find_helper_dictionary_entry_id(array $update, array $keys, bool &$has_value) {
    foreach ($keys as $key) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (array_key_exists($key, $update)) {
            $has_value = true;
            return ll_tools_rest_automation_sanitize_helper_dictionary_entry_id($update[$key]);
        }
    }

    $has_value = false;
    return 0;
}

function ll_tools_rest_automation_word_helper_updates(WP_REST_Request $request) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $raw_updates = $request->get_param('updates');
    $raw_updates = is_array($raw_updates) ? array_values($raw_updates) : [];
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $batch_settings = ll_tools_rest_automation_batch_limit('word_helper_updates', $dry_run);
    $max_updates = (int) $batch_settings['max'];
    $started = microtime(true);
    $supported_fields = ['word_translation', 'word_english_meaning', 'dictionary_entry_id'];

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => (int) $wordset_term->term_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'batch_mode' => 'word_helper_updates',
        'input_count' => count($raw_updates),
        'max_updates' => $max_updates,
        'batch' => [
            'default_limit' => (int) $batch_settings['default'],
            'max_limit' => (int) $batch_settings['max'],
        ],
        'matched_count' => 0,
        'changed_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'skipped_count' => 0,
        'updated' => [],
        'skipped' => [],
        'errors' => [],
    ];

    if (empty($raw_updates)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_missing_word_helper_updates',
            __('Provide one or more word helper translation updates.', 'll-tools-text-domain')
        ), 400);
    }

    if (count($raw_updates) > $max_updates) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_too_many_word_helper_updates',
            sprintf(
                /* translators: %d: maximum update count */
                __('Too many word helper translation updates in one request. Maximum is %d.', 'll-tools-text-domain'),
                $max_updates
            )
        ), 400);
    }

    $updates = [];
    $seen_word_ids = [];
    foreach ($raw_updates as $index => $update) {
        $update = is_array($update) ? $update : [];
        $raw_word_id = $update['word_id'] ?? $update['id'] ?? '';
        $word_id = is_scalar($raw_word_id) ? (int) $raw_word_id : 0;
        if ($word_id <= 0) {
            $summary['errors'][] = [
                'index' => $index,
                'message' => __('Missing word ID.', 'll-tools-text-domain'),
            ];
            continue;
        }

        if (isset($seen_word_ids[$word_id])) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Duplicate word ID in this batch.', 'll-tools-text-domain'),
            ];
            continue;
        }
        $seen_word_ids[$word_id] = true;

        $requested_values = [];
        $guard_values = [];

        $has_word_translation = false;
        $word_translation = ll_tools_rest_automation_find_helper_translation_value($update, ['word_translation', 'translation'], $has_word_translation);
        if ($has_word_translation) {
            $requested_values['word_translation'] = $word_translation;
        }

        $has_word_english_meaning = false;
        $word_english_meaning = ll_tools_rest_automation_find_helper_translation_value($update, ['word_english_meaning', 'helper_translation', 'known_language_translation', 'english_meaning'], $has_word_english_meaning);
        if ($has_word_english_meaning) {
            $requested_values['word_english_meaning'] = $word_english_meaning;
        }

        $has_dictionary_entry_id = false;
        $dictionary_entry_id = ll_tools_rest_automation_find_helper_dictionary_entry_id($update, ['dictionary_entry_id', 'entry_id'], $has_dictionary_entry_id);
        if (is_wp_error($dictionary_entry_id)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => $dictionary_entry_id->get_error_message(),
            ];
            continue;
        }
        if ($has_dictionary_entry_id) {
            if (!function_exists('ll_tools_assign_dictionary_entry_to_word')) {
                $summary['errors'][] = [
                    'index' => $index,
                    'word_id' => $word_id,
                    'message' => __('Dictionary entry helpers are not available.', 'll-tools-text-domain'),
                ];
                continue;
            }
            $requested_values['dictionary_entry_id'] = (int) $dictionary_entry_id;
        }

        if (array_key_exists('value', $update)) {
            $field = sanitize_key((string) ($update['field'] ?? ''));
            if (!in_array($field, $supported_fields, true)) {
                $summary['errors'][] = [
                    'index' => $index,
                    'word_id' => $word_id,
                    'message' => __('Use field=word_translation, field=word_english_meaning, or field=dictionary_entry_id with value.', 'll-tools-text-domain'),
                ];
                continue;
            }
            if ($field === 'dictionary_entry_id') {
                if (!function_exists('ll_tools_assign_dictionary_entry_to_word')) {
                    $summary['errors'][] = [
                        'index' => $index,
                        'word_id' => $word_id,
                        'message' => __('Dictionary entry helpers are not available.', 'll-tools-text-domain'),
                    ];
                    continue;
                }
                $value_dictionary_entry_id = ll_tools_rest_automation_sanitize_helper_dictionary_entry_id($update['value']);
                if (is_wp_error($value_dictionary_entry_id)) {
                    $summary['errors'][] = [
                        'index' => $index,
                        'word_id' => $word_id,
                        'message' => $value_dictionary_entry_id->get_error_message(),
                    ];
                    continue;
                }
                $requested_values[$field] = (int) $value_dictionary_entry_id;
            } else {
                $requested_values[$field] = ll_tools_rest_automation_sanitize_helper_translation_value($update['value']);
            }
        }

        $has_old_word_translation = false;
        $old_word_translation = ll_tools_rest_automation_find_helper_translation_value($update, ['old_word_translation', 'old_translation', 'expected_word_translation'], $has_old_word_translation);
        if ($has_old_word_translation) {
            $guard_values['word_translation'] = $old_word_translation;
        }

        $has_old_word_english_meaning = false;
        $old_word_english_meaning = ll_tools_rest_automation_find_helper_translation_value($update, ['old_word_english_meaning', 'old_helper_translation', 'expected_word_english_meaning', 'expected_helper_translation'], $has_old_word_english_meaning);
        if ($has_old_word_english_meaning) {
            $guard_values['word_english_meaning'] = $old_word_english_meaning;
        }

        $has_old_dictionary_entry_id = false;
        $old_dictionary_entry_id = ll_tools_rest_automation_find_helper_dictionary_entry_id(
            $update,
            ['old_dictionary_entry_id', 'expected_dictionary_entry_id'],
            $has_old_dictionary_entry_id
        );
        if (is_wp_error($old_dictionary_entry_id)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => $old_dictionary_entry_id->get_error_message(),
            ];
            continue;
        }
        if ($has_old_dictionary_entry_id) {
            $guard_values['dictionary_entry_id'] = (int) $old_dictionary_entry_id;
        }

        if (empty($requested_values)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Missing helper translation or dictionary entry value.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $updates[] = [
            'index' => $index,
            'word_id' => $word_id,
            'values' => $requested_values,
            'guards' => $guard_values,
        ];
    }

    if (empty($updates)) {
        $summary['elapsed_seconds'] = round(microtime(true) - $started, 3);
        return rest_ensure_response($summary);
    }

    $records = ll_tools_rest_automation_fetch_word_title_records(
        (int) $wordset_term->term_id,
        array_map(static function (array $update): int {
            return (int) $update['word_id'];
        }, $updates)
    );

    $changes = [];
    foreach ($updates as $update) {
        $word_id = (int) $update['word_id'];
        $record = $records[$word_id] ?? null;
        if (!is_array($record)) {
            $summary['skipped'][] = [
                'index' => (int) $update['index'],
                'word_id' => $word_id,
                'reason' => 'not_in_wordset',
            ];
            $summary['skipped_count']++;
            continue;
        }

        $before = [
            'word_translation' => (string) get_post_meta($word_id, 'word_translation', true),
            'word_english_meaning' => (string) get_post_meta($word_id, 'word_english_meaning', true),
            'dictionary_entry_id' => function_exists('ll_tools_get_word_dictionary_entry_id')
                ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
                : 0,
        ];
        $after = $before;
        $summary['matched_count']++;

        $guard_mismatch = '';
        foreach ((array) ($update['guards'] ?? []) as $field => $expected_value) {
            $field = (string) $field;
            if (!array_key_exists($field, $before) || (string) $before[$field] === (string) $expected_value) {
                continue;
            }
            $guard_mismatch = $field;
            break;
        }

        if ($guard_mismatch !== '') {
            $summary['skipped'][] = [
                'index' => (int) $update['index'],
                'word_id' => $word_id,
                'word_slug' => (string) ($record['word_slug'] ?? ''),
                'word_title' => (string) ($record['word_title'] ?? ''),
                'reason' => 'old_value_mismatch',
                'field' => $guard_mismatch,
                'current_value' => (string) $before[$guard_mismatch],
                'expected_old_value' => (string) ($update['guards'][$guard_mismatch] ?? ''),
            ];
            $summary['skipped_count']++;
            continue;
        }

        $changed_keys = [];
        foreach ((array) ($update['values'] ?? []) as $field => $value) {
            $field = (string) $field;
            if (!array_key_exists($field, $after)) {
                continue;
            }
            if ($field === 'dictionary_entry_id') {
                $value = max(0, (int) $value);
                if ((int) $after[$field] === $value) {
                    continue;
                }
            } else {
                $value = (string) $value;
                if ((string) $after[$field] === $value) {
                    continue;
                }
            }
            $after[$field] = $value;
            $changed_keys[] = $field;
        }

        if (empty($changed_keys)) {
            $summary['unchanged_count']++;
            continue;
        }

        $changes[] = [
            'index' => (int) $update['index'],
            'word_id' => $word_id,
            'word_slug' => (string) ($record['word_slug'] ?? ''),
            'word_title' => (string) ($record['word_title'] ?? ''),
            'changed_keys' => array_values(array_unique($changed_keys)),
            'before' => $before,
            'after' => $after,
        ];
    }

    $summary['changed_count'] = count($changes);

    if (!$dry_run && !empty($changes)) {
        $changed_word_ids = [];
        foreach ($changes as $change) {
            $word_id = (int) $change['word_id'];
            $apply_failed = false;
            foreach ((array) ($change['changed_keys'] ?? []) as $field) {
                $field = (string) $field;
                if ($field === 'dictionary_entry_id') {
                    if (!function_exists('ll_tools_assign_dictionary_entry_to_word')) {
                        $summary['errors'][] = [
                            'index' => (int) $change['index'],
                            'word_id' => $word_id,
                            'word_slug' => (string) $change['word_slug'],
                            'message' => __('Dictionary entry helpers are not available.', 'll-tools-text-domain'),
                        ];
                        $apply_failed = true;
                        break;
                    }
                    $dictionary_result = ll_tools_assign_dictionary_entry_to_word(
                        $word_id,
                        max(0, (int) ($change['after'][$field] ?? 0)),
                        ''
                    );
                    if (is_wp_error($dictionary_result)) {
                        $summary['errors'][] = [
                            'index' => (int) $change['index'],
                            'word_id' => $word_id,
                            'word_slug' => (string) $change['word_slug'],
                            'message' => $dictionary_result->get_error_message(),
                        ];
                        $apply_failed = true;
                        break;
                    }
                } else {
                    $value = (string) (($change['after'][$field] ?? ''));
                    if ($value === '') {
                        delete_post_meta($word_id, $field);
                    } else {
                        update_post_meta($word_id, $field, $value);
                    }
                }
            }
            if ($apply_failed) {
                continue;
            }

            $summary['updated'][] = [
                'index' => (int) $change['index'],
                'word_id' => $word_id,
                'word_slug' => (string) $change['word_slug'],
                'word_title' => (string) $change['word_title'],
                'changed' => true,
                'changed_keys' => array_values(array_map('strval', (array) ($change['changed_keys'] ?? []))),
                'before' => (array) ($change['before'] ?? []),
                'after' => (array) ($change['after'] ?? []),
            ];
            $summary['updated_count']++;
            $changed_word_ids[] = $word_id;
        }

        $changed_word_ids = array_values(array_unique(array_filter(array_map('intval', $changed_word_ids))));
        foreach ($changed_word_ids as $changed_word_id) {
            clean_post_cache($changed_word_id);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_word_grid_bump_category_cache_for_words')) {
            ll_tools_word_grid_bump_category_cache_for_words($changed_word_ids);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_purge_public_static_cache_once')) {
            ll_tools_purge_public_static_cache_once(['wordset_ids' => [(int) $wordset_term->term_id]]);
        }
        if (!empty($changed_word_ids) && function_exists('ll_tools_purge_wordset_buttons_shortcode_cache_once')) {
            ll_tools_purge_wordset_buttons_shortcode_cache_once();
        }
    }

    if ($dry_run) {
        $summary['updated'] = array_map(static function (array $change): array {
            return $change + [
                'changed' => true,
            ];
        }, $changes);
    }

    $summary['elapsed_seconds'] = round(microtime(true) - $started, 3);

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

function ll_tools_rest_automation_load_word_category_update_helpers() {
    $base_path = defined('LL_TOOLS_BASE_PATH') ? LL_TOOLS_BASE_PATH : dirname(__DIR__, 2) . '/';
    if (!function_exists('ll_tools_word_grid_get_category_editor_rows')) {
        $word_grid_path = trailingslashit($base_path) . 'includes/shortcodes/word-grid-shortcode.php';
        if (is_readable($word_grid_path)) {
            require_once $word_grid_path;
        }
    }
    if (!function_exists('ll_tools_wordset_editor_sync_linked_word_image_categories')) {
        $editor_path = trailingslashit($base_path) . 'includes/pages/wordset-editor.php';
        if (is_readable($editor_path)) {
            require_once $editor_path;
        }
    }

    foreach ([
        'll_tools_word_grid_get_category_editor_rows',
        'll_tools_word_grid_get_selected_category_ids_for_editor',
        'll_tools_word_grid_update_word_categories_for_wordset',
        'll_tools_wordset_editor_sync_linked_word_image_categories',
    ] as $function_name) {
        if (!function_exists($function_name)) {
            return new WP_Error(
                'll_tools_rest_word_category_helpers_missing',
                __('Word category update helpers are not available.', 'll-tools-text-domain')
            );
        }
    }

    return true;
}

function ll_tools_rest_automation_word_category_updates(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $batch_settings = ll_tools_rest_automation_batch_limit('word_category_updates', $dry_run);
    $max_updates = (int) $batch_settings['max'];
    $operation = sanitize_key((string) ($request->get_param('operation') ?: $request->get_param('action')));
    $target_category_id = absint($request->get_param('target_category_id') ?: $request->get_param('category_id'));
    $allow_empty_categories = rest_sanitize_boolean($request->get_param('allow_empty_categories'));
    $sync_linked_images = $request->has_param('sync_linked_images')
        ? rest_sanitize_boolean($request->get_param('sync_linked_images'))
        : true;
    $raw_word_ids = $request->get_param('word_ids');
    if (!is_array($raw_word_ids)) {
        $raw_word_ids = $request->get_param('ids');
    }
    $raw_word_ids = is_array($raw_word_ids) ? array_values($raw_word_ids) : [];

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => [
            'id' => $wordset_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'batch_mode' => 'word_category_updates',
        'operation' => $operation,
        'input_count' => count($raw_word_ids),
        'max_updates' => $max_updates,
        'batch' => [
            'default_limit' => (int) $batch_settings['default'],
            'max_limit' => (int) $batch_settings['max'],
        ],
        'target_category' => [
            'id' => $target_category_id,
            'requested_id' => $target_category_id,
            'slug' => '',
            'name' => '',
        ],
        'sync_linked_images' => $sync_linked_images,
        'matched_count' => 0,
        'changed_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'invalidated_category_ids' => [],
        'invalidated_wordset_cache' => false,
        'updated' => [],
        'errors' => [],
    ];

    if (!in_array($operation, ['add_category', 'remove_category', 'move_category'], true)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_invalid_word_category_operation',
            __('Choose add_category, remove_category, or move_category.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    if ($target_category_id <= 0) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_missing_word_category_target',
            __('Provide the target category ID.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    if (empty($raw_word_ids)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_missing_word_category_word_ids',
            __('Provide one or more explicit word IDs.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    if (count($raw_word_ids) > $max_updates) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_too_many_word_category_updates',
            sprintf(
                /* translators: %d: maximum word count */
                __('Too many word category updates in one request. Maximum is %d.', 'll-tools-text-domain'),
                $max_updates
            ),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    $word_ids = [];
    $seen_word_ids = [];
    foreach ($raw_word_ids as $index => $raw_word_id) {
        $word_id = is_scalar($raw_word_id) ? (int) $raw_word_id : 0;
        if ($word_id <= 0) {
            $summary['errors'][] = [
                'index' => $index,
                'message' => __('Missing word ID.', 'll-tools-text-domain'),
            ];
            continue;
        }
        if (isset($seen_word_ids[$word_id])) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Duplicate word ID in this batch.', 'll-tools-text-domain'),
            ];
            continue;
        }
        $seen_word_ids[$word_id] = true;
        $word_ids[] = $word_id;
    }

    if (!empty($summary['errors'])) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_invalid_word_category_word_ids',
            __('Fix word ID errors before retrying this category update.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    $helpers_loaded = ll_tools_rest_automation_load_word_category_update_helpers();
    if (is_wp_error($helpers_loaded)) {
        return ll_tools_rest_automation_with_status($helpers_loaded, 500);
    }

    $category_rows = ll_tools_word_grid_get_category_editor_rows($wordset_id);
    $available_category_ids = ll_tools_rest_automation_prepare_id_list(wp_list_pluck($category_rows, 'id'));
    $requested_target_category_id = $target_category_id;
    if (!in_array($target_category_id, $available_category_ids, true) && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_target_category_id = (int) ll_tools_get_effective_category_id_for_wordset($target_category_id, $wordset_id, false);
        if ($effective_target_category_id > 0 && in_array($effective_target_category_id, $available_category_ids, true)) {
            $target_category_id = $effective_target_category_id;
        }
    }
    if (!in_array($target_category_id, $available_category_ids, true)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_category_out_of_scope',
            __('Select a category that belongs to this word set.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    $target_category = get_term($target_category_id, 'word-category');
    if ($target_category instanceof WP_Term && !is_wp_error($target_category)) {
        $summary['target_category'] = [
            'id' => (int) $target_category->term_id,
            'requested_id' => $requested_target_category_id,
            'slug' => (string) $target_category->slug,
            'name' => (string) $target_category->name,
        ];
    }

    $plans = [];
    foreach ($word_ids as $index => $word_id) {
        $post = get_post($word_id);
        if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Word ID does not refer to a word post.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $wordset_ids_raw = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
        $wordset_ids = is_wp_error($wordset_ids_raw)
            ? []
            : ll_tools_rest_automation_prepare_id_list((array) $wordset_ids_raw);
        if (!in_array($wordset_id, $wordset_ids, true)) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Word is not assigned to this word set.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $before_ids = ll_tools_word_grid_get_selected_category_ids_for_editor($word_id, $wordset_id, $available_category_ids);
        $after_ids = $before_ids;
        if ($operation === 'add_category') {
            if (!in_array($target_category_id, $after_ids, true)) {
                $after_ids[] = $target_category_id;
            }
        } elseif ($operation === 'remove_category') {
            $after_ids = array_values(array_diff($after_ids, [$target_category_id]));
        } else {
            $after_ids = [$target_category_id];
        }

        if (empty($after_ids) && !$allow_empty_categories) {
            $summary['errors'][] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Refusing to remove the final category for this word.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $plans[] = [
            'index' => $index,
            'word_id' => $word_id,
            'word_slug' => (string) $post->post_name,
            'word_title' => (string) get_the_title($post),
            'before_category_ids' => $before_ids,
            'after_category_ids' => ll_tools_rest_automation_prepare_id_list($after_ids),
            'changed' => $before_ids !== ll_tools_rest_automation_prepare_id_list($after_ids),
        ];
    }

    $summary['matched_count'] = count($plans);
    if (!empty($summary['errors'])) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_category_preflight_failed',
            __('Category update preflight failed. No words were changed.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    if ($dry_run) {
        foreach ($plans as $plan) {
            $summary['updated'][] = $plan;
            if (!empty($plan['changed'])) {
                $summary['changed_count']++;
            } else {
                $summary['unchanged_count']++;
            }
        }

        return rest_ensure_response($summary);
    }

    $changed_category_lookup = [];
    foreach ($plans as $plan) {
        $result = ll_tools_word_grid_update_word_categories_for_wordset(
            (int) $plan['word_id'],
            $wordset_id,
            (array) $plan['after_category_ids'],
            $available_category_ids
        );

        if (is_wp_error($result)) {
            $summary['errors'][] = [
                'index' => (int) $plan['index'],
                'word_id' => (int) $plan['word_id'],
                'message' => $result->get_error_message(),
            ];
            continue;
        }

        if (!empty($result['changed']) && $sync_linked_images) {
            $sync_result = ll_tools_wordset_editor_sync_linked_word_image_categories(
                (int) $plan['word_id'],
                $wordset_id,
                (array) $plan['after_category_ids'],
                $available_category_ids
            );
            if (is_wp_error($sync_result)) {
                $summary['errors'][] = [
                    'index' => (int) $plan['index'],
                    'word_id' => (int) $plan['word_id'],
                    'message' => $sync_result->get_error_message(),
                ];
                continue;
            }
        }

        $verified_ids = ll_tools_word_grid_get_selected_category_ids_for_editor((int) $plan['word_id'], $wordset_id, $available_category_ids);
        $row = $plan;
        $row['verified_category_ids'] = $verified_ids;
        $row['changed'] = !empty($result['changed']);
        $summary['updated'][] = $row;
        if (!empty($result['changed'])) {
            foreach (array_merge((array) $plan['before_category_ids'], (array) $plan['after_category_ids']) as $changed_category_id) {
                $changed_category_id = (int) $changed_category_id;
                if ($changed_category_id > 0) {
                    $changed_category_lookup[$changed_category_id] = true;
                }
            }
            $summary['changed_count']++;
            $summary['updated_count']++;
        } else {
            $summary['unchanged_count']++;
        }
    }

    if (!empty($changed_category_lookup)) {
        $changed_category_ids = array_keys($changed_category_lookup);
        sort($changed_category_ids, SORT_NUMERIC);
        $summary['invalidated_category_ids'] = array_values(array_map('intval', $changed_category_ids));
        if (function_exists('ll_tools_bump_category_cache_version')) {
            ll_tools_bump_category_cache_version($summary['invalidated_category_ids']);
        }
        if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
            ll_tools_bump_wordset_cache_epoch([$wordset_id]);
            $summary['invalidated_wordset_cache'] = true;
        }
    }

    if (!empty($summary['errors'])) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_category_update_failed',
            __('One or more category updates failed.', 'll-tools-text-domain'),
            ['status' => 500, 'summary' => $summary]
        ), 500);
    }

    return rest_ensure_response($summary);
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

function ll_tools_rest_automation_refresh_transcription_validations(WP_REST_Request $request) {
    ll_tools_rest_automation_load_orthography_helpers();

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $limits = ll_tools_rest_automation_transcription_validation_limits($request, $dry_run);
    $limit = (int) ($limits['limit']['effective'] ?? 1);
    $scan_limit = (int) ($limits['scan_limit']['effective'] ?? 25);
    $stale_only = $request->has_param('stale_only')
        ? rest_sanitize_boolean($request->get_param('stale_only'))
        : true;

    $word_ids = get_objects_in_term($wordset_id, 'wordset');
    if (is_wp_error($word_ids)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_validation_wordset_lookup_failed',
            $word_ids->get_error_message(),
            500
        );
    }
    $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $word_ids))));
    $candidate_ids = [];
    if (!empty($word_ids)) {
        $candidate_ids = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent__in' => $word_ids,
            'posts_per_page' => $scan_limit,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query' => [
                [
                    'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);
    }
    $candidate_ids = array_values(array_map('intval', (array) $candidate_ids));

    $summary = [
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'stale_only' => $stale_only,
        'limit' => $limit,
        'scan_limit' => $scan_limit,
        'batch' => $limits,
        'wordset' => [
            'id' => $wordset_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'candidate_count' => count($candidate_ids),
        'candidate_count_is_limited' => count($candidate_ids) >= $scan_limit,
        'scanned_count' => 0,
        'matched_count' => 0,
        'updated_count' => 0,
        'remaining_stale_count' => 0,
        'remaining_stale_count_is_partial' => count($candidate_ids) >= $scan_limit,
        'updated_recording_ids' => [],
    ];

    foreach ((array) $candidate_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0 || !ll_tools_rest_automation_recording_belongs_to_wordset($recording_id, $wordset_id)) {
            continue;
        }

        $summary['scanned_count']++;
        $validation = function_exists('ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry')
            ? ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id)
            : [];
        $is_stale = function_exists('ll_tools_ipa_keyboard_validation_result_is_stale')
            && ll_tools_ipa_keyboard_validation_result_is_stale((array) $validation);
        $has_validation = !empty($validation['active']) || !empty($validation['ignored']);
        if ($stale_only && (!$has_validation || !$is_stale)) {
            continue;
        }

        $summary['matched_count']++;
        if ($summary['updated_count'] >= $limit) {
            if ($is_stale) {
                $summary['remaining_stale_count']++;
            }
            continue;
        }

        if (!$dry_run && function_exists('ll_tools_ipa_keyboard_update_recording_validation')) {
            ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        }
        $summary['updated_count']++;
        $summary['updated_recording_ids'][] = $recording_id;
    }

    return rest_ensure_response($summary);
}

function ll_tools_rest_transcription_validation_job_option_name(string $job_id): string {
    return 'll_tools_transcription_validation_job_' . sanitize_key(str_replace('-', '_', $job_id));
}

function ll_tools_rest_transcription_validation_job_default_summary(int $total): array {
    return [
        'total' => max(0, $total),
        'processed_count' => 0,
        'updated_count' => 0,
        'skipped_count' => 0,
        'error_count' => 0,
        'errors' => [],
        'recent' => [],
    ];
}

function ll_tools_rest_transcription_validation_job_save(array $job): bool {
    $job_id = (string) ($job['id'] ?? '');
    if ($job_id === '') {
        return false;
    }

    $job['updated_at_gmt'] = gmdate('c');
    return update_option(ll_tools_rest_transcription_validation_job_option_name($job_id), $job, false);
}

function ll_tools_rest_transcription_validation_job_get(string $job_id) {
    $job_id = trim($job_id);
    if ($job_id === '' || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $job_id)) {
        return new WP_Error(
            'll_tools_rest_transcription_validation_job_invalid_id',
            __('Invalid transcription validation job ID.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }

    $job = get_option(ll_tools_rest_transcription_validation_job_option_name($job_id), null);
    if (!is_array($job)) {
        return new WP_Error(
            'll_tools_rest_transcription_validation_job_not_found',
            __('Transcription validation job was not found.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }

    return $job;
}

function ll_tools_rest_transcription_validation_job_auto_delay_seconds($value = null): int {
    $requested = (is_scalar($value) && trim((string) $value) !== '')
        ? max(0, (int) $value)
        : 0;
    $default = max(30, (int) apply_filters('ll_tools_rest_transcription_validation_job_auto_delay_default', 120));
    $max = max($default, (int) apply_filters('ll_tools_rest_transcription_validation_job_auto_delay_max', 3600));
    $delay = $requested > 0 ? $requested : $default;

    return max(30, min($delay, $max));
}

function ll_tools_rest_transcription_validation_job_auto_limit($value = null): int {
    $limits = ll_tools_rest_automation_batch_limit('transcription_validation_jobs', false);
    $requested = (is_scalar($value) && trim((string) $value) !== '')
        ? max(0, (int) $value)
        : 0;
    $default = max(1, (int) apply_filters('ll_tools_rest_transcription_validation_job_auto_limit_default', 1));
    $max = max($default, (int) ($limits['max'] ?? 5));
    $limit = $requested > 0 ? $requested : $default;

    return max(1, min($limit, $max));
}

function ll_tools_rest_transcription_validation_job_apply_auto_request(array $job, WP_REST_Request $request, bool $default_enabled = false): array {
    $enabled = $request->has_param('auto_process')
        ? rest_sanitize_boolean($request->get_param('auto_process'))
        : $default_enabled;

    $job['auto_process'] = (bool) $enabled;
    $job['auto_delay_seconds'] = ll_tools_rest_transcription_validation_job_auto_delay_seconds(
        $request->has_param('auto_delay_seconds') ? $request->get_param('auto_delay_seconds') : ($job['auto_delay_seconds'] ?? null)
    );
    $job['auto_limit'] = ll_tools_rest_transcription_validation_job_auto_limit(
        $request->has_param('auto_limit') ? $request->get_param('auto_limit') : ($job['auto_limit'] ?? null)
    );

    return $job;
}

function ll_tools_rest_transcription_validation_job_clear_schedule(string $job_id): void {
    if ($job_id === '' || !function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }

    $args = [$job_id];
    while ($timestamp = wp_next_scheduled(LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, $args)) {
        $unscheduled = wp_unschedule_event((int) $timestamp, LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, $args);
        if ($unscheduled === false) {
            break;
        }
    }
}

function ll_tools_rest_transcription_validation_job_schedule_next(array $job): bool {
    $job_id = (string) ($job['id'] ?? '');
    if ($job_id === '' || empty($job['auto_process']) || (string) ($job['status'] ?? '') === 'completed'
        || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
        return false;
    }

    $args = [$job_id];
    if (wp_next_scheduled(LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, $args)) {
        return true;
    }

    $delay_seconds = ll_tools_rest_transcription_validation_job_auto_delay_seconds($job['auto_delay_seconds'] ?? null);
    return wp_schedule_single_event(time() + $delay_seconds, LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, $args) !== false;
}

function ll_tools_rest_transcription_validation_job_next_run_gmt(string $job_id): string {
    if ($job_id === '' || !function_exists('wp_next_scheduled')) {
        return '';
    }

    $timestamp = wp_next_scheduled(LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, [$job_id]);
    return $timestamp ? gmdate('c', (int) $timestamp) : '';
}

function ll_tools_rest_transcription_validation_job_summary(array $job, bool $include_recent = true): array {
    $recording_ids = array_values(array_filter(array_map('intval', (array) ($job['recording_ids'] ?? []))));
    $summary = (array) ($job['summary'] ?? ll_tools_rest_transcription_validation_job_default_summary(count($recording_ids)));
    $total = max(0, (int) ($job['total'] ?? count($recording_ids)));
    $current_index = max(0, (int) ($job['current_index'] ?? 0));

    $payload = [
        'id' => (string) ($job['id'] ?? ''),
        'status' => (string) ($job['status'] ?? 'running'),
        'wordset_id' => max(0, (int) ($job['wordset_id'] ?? 0)),
        'wordset_slug' => (string) ($job['wordset_slug'] ?? ''),
        'stale_only' => !empty($job['stale_only']),
        'total' => $total,
        'current_index' => min($total, $current_index),
        'remaining_count' => max(0, $total - $current_index),
        'created_at_gmt' => (string) ($job['created_at_gmt'] ?? ''),
        'updated_at_gmt' => (string) ($job['updated_at_gmt'] ?? ''),
        'process_limit' => [
            'default' => ll_tools_rest_automation_batch_limit('transcription_validation_jobs', false)['default'],
            'max' => ll_tools_rest_automation_batch_limit('transcription_validation_jobs', false)['max'],
        ],
        'candidate_scope' => (string) ($job['candidate_scope'] ?? 'issues'),
        'auto_process' => [
            'enabled' => !empty($job['auto_process']),
            'delay_seconds' => ll_tools_rest_transcription_validation_job_auto_delay_seconds($job['auto_delay_seconds'] ?? null),
            'limit' => ll_tools_rest_transcription_validation_job_auto_limit($job['auto_limit'] ?? null),
            'next_run_gmt' => ll_tools_rest_transcription_validation_job_next_run_gmt((string) ($job['id'] ?? '')),
            'last_run_gmt' => (string) ($job['last_auto_process_gmt'] ?? ''),
        ],
        'summary' => [
            'total' => max(0, (int) ($summary['total'] ?? $total)),
            'processed_count' => max(0, (int) ($summary['processed_count'] ?? 0)),
            'updated_count' => max(0, (int) ($summary['updated_count'] ?? 0)),
            'skipped_count' => max(0, (int) ($summary['skipped_count'] ?? 0)),
            'error_count' => max(0, (int) ($summary['error_count'] ?? 0)),
            'errors' => array_values((array) ($summary['errors'] ?? [])),
        ],
    ];

    if ($include_recent) {
        $payload['summary']['recent'] = array_values((array) ($summary['recent'] ?? []));
    }

    return $payload;
}

function ll_tools_rest_automation_transcription_validation_max_candidates(WP_REST_Request $request): array {
    $raw_limit = $request->get_param('max_candidates');
    $requested = (is_scalar($raw_limit) && trim((string) $raw_limit) !== '')
        ? max(0, (int) $raw_limit)
        : 0;
    $default = 500;
    $max = max(1, (int) apply_filters('ll_tools_rest_transcription_validation_job_max_candidates', 5000, $request));
    $effective = $requested > 0 ? $requested : $default;
    $clamped = $effective > $max;

    return [
        'requested' => $requested,
        'effective' => max(1, min($effective, $max)),
        'default' => $default,
        'max' => $max,
        'clamped' => $clamped,
    ];
}

function ll_tools_rest_automation_transcription_validation_candidate_scope(WP_REST_Request $request): string {
    $scope = $request->has_param('candidate_scope')
        ? sanitize_key((string) $request->get_param('candidate_scope'))
        : 'issues';
    if ($scope === 'issue' || $scope === 'open_issues') {
        $scope = 'issues';
    }
    if ($scope === 'recordings' || $scope === 'all_recordings') {
        $scope = 'all';
    }

    return in_array($scope, ['issues', 'all'], true) ? $scope : 'issues';
}

function ll_tools_rest_automation_transcription_validation_candidate_ids(int $wordset_id, int $max_candidates, bool $stale_only, string $candidate_scope = 'issues'): array {
    ll_tools_rest_automation_load_orthography_helpers();

    $word_ids = get_objects_in_term($wordset_id, 'wordset');
    if (is_wp_error($word_ids)) {
        return [];
    }

    $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $word_ids))));
    if (empty($word_ids)) {
        return [];
    }

    $query_args = [
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'post_parent__in' => $word_ids,
        'posts_per_page' => max(1, $max_candidates),
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    ];

    if ($candidate_scope === 'issues') {
        $query_args['meta_query'] = [
            [
                'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ];
    }

    $recording_ids = get_posts($query_args);

    $candidate_ids = [];
    foreach (array_map('intval', (array) $recording_ids) as $recording_id) {
        if ($recording_id <= 0 || !ll_tools_rest_automation_recording_belongs_to_wordset($recording_id, $wordset_id)) {
            continue;
        }
        if ($stale_only) {
            $validation = function_exists('ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry')
                ? ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id)
                : [];
            $is_stale = function_exists('ll_tools_ipa_keyboard_validation_result_is_stale')
                && ll_tools_ipa_keyboard_validation_result_is_stale((array) $validation);
            if (!$is_stale) {
                continue;
            }
        }

        $candidate_ids[] = $recording_id;
    }

    return array_values(array_unique($candidate_ids));
}

function ll_tools_rest_automation_create_transcription_validation_job(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $stale_only = $request->has_param('stale_only')
        ? rest_sanitize_boolean($request->get_param('stale_only'))
        : true;
    $candidate_limit = ll_tools_rest_automation_transcription_validation_max_candidates($request);
    $candidate_scope = ll_tools_rest_automation_transcription_validation_candidate_scope($request);
    $recording_ids = ll_tools_rest_automation_transcription_validation_candidate_ids(
        $wordset_id,
        (int) ($candidate_limit['effective'] ?? 500),
        $stale_only,
        $candidate_scope
    );

    $job_id = wp_generate_uuid4();
    $job = [
        'id' => $job_id,
        'status' => empty($recording_ids) ? 'completed' : 'running',
        'wordset_id' => $wordset_id,
        'wordset_slug' => (string) $wordset_term->slug,
        'stale_only' => $stale_only,
        'candidate_scope' => $candidate_scope,
        'total' => count($recording_ids),
        'current_index' => 0,
        'recording_ids' => $recording_ids,
        'candidate_limit' => $candidate_limit,
        'summary' => ll_tools_rest_transcription_validation_job_default_summary(count($recording_ids)),
        'created_at_gmt' => gmdate('c'),
        'updated_at_gmt' => gmdate('c'),
    ];
    $job = ll_tools_rest_transcription_validation_job_apply_auto_request($job, $request, false);
    ll_tools_rest_transcription_validation_job_save($job);
    if ((string) ($job['status'] ?? '') !== 'completed') {
        ll_tools_rest_transcription_validation_job_schedule_next($job);
    }

    return new WP_REST_Response([
        'generated_at_gmt' => gmdate('c'),
        'candidate_limit' => $candidate_limit,
        'job' => ll_tools_rest_transcription_validation_job_summary($job, false),
    ], 201);
}

function ll_tools_rest_automation_get_transcription_validation_job(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_transcription_validation_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset_id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error(
            'll_tools_rest_transcription_validation_job_wordset_mismatch',
            __('Transcription validation job belongs to a different word set.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }

    return rest_ensure_response(['job' => ll_tools_rest_transcription_validation_job_summary($job)]);
}

function ll_tools_rest_transcription_validation_job_process_records(array $job, int $wordset_id, int $limit, bool $profile_enabled = false): array {
    $limit = max(1, $limit);
    $recording_ids = array_values(array_filter(array_map('intval', (array) ($job['recording_ids'] ?? []))));
    $total = count($recording_ids);
    $current_index = min($total, max(0, (int) ($job['current_index'] ?? 0)));
    $summary = (array) ($job['summary'] ?? ll_tools_rest_transcription_validation_job_default_summary($total));
    $recent = [];
    $profiles = [];

    $processed_this_request = 0;
    while ($current_index < $total && $processed_this_request < $limit) {
        $recording_id = (int) ($recording_ids[$current_index] ?? 0);
        $current_index++;
        $processed_this_request++;
        $summary['processed_count'] = max(0, (int) ($summary['processed_count'] ?? 0)) + 1;

        if ($recording_id <= 0 || !ll_tools_rest_automation_recording_belongs_to_wordset($recording_id, $wordset_id)) {
            $summary['skipped_count'] = max(0, (int) ($summary['skipped_count'] ?? 0)) + 1;
            $recent[] = [
                'recording_id' => $recording_id,
                'status' => 'skipped',
                'reason' => 'not_in_wordset',
            ];
            continue;
        }

        if (!empty($job['stale_only'])) {
            $validation = function_exists('ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry')
                ? ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id)
                : [];
            $is_stale = function_exists('ll_tools_ipa_keyboard_validation_result_is_stale')
                && ll_tools_ipa_keyboard_validation_result_is_stale((array) $validation);
            if (!$is_stale) {
                $summary['skipped_count'] = max(0, (int) ($summary['skipped_count'] ?? 0)) + 1;
                $recent[] = [
                    'recording_id' => $recording_id,
                    'status' => 'skipped',
                    'reason' => 'not_stale',
                ];
                continue;
            }
        }

        try {
            $row_profile = [
                'recording_id' => $recording_id,
                'index' => $current_index - 1,
            ];
            if (function_exists('ll_tools_ipa_keyboard_update_recording_validation_for_wordset')) {
                if ($profile_enabled) {
                    ll_tools_ipa_keyboard_update_recording_validation_for_wordset($recording_id, $wordset_id, false, $row_profile);
                } else {
                    ll_tools_ipa_keyboard_update_recording_validation_for_wordset($recording_id, $wordset_id, false);
                }
            } else {
                ll_tools_ipa_keyboard_update_recording_validation($recording_id);
            }
            $summary['updated_count'] = max(0, (int) ($summary['updated_count'] ?? 0)) + 1;
            $recent_row = [
                'recording_id' => $recording_id,
                'status' => 'updated',
            ];
            if ($profile_enabled) {
                $recent_row['profile'] = $row_profile;
                $profiles[] = $row_profile;
            }
            $recent[] = $recent_row;
        } catch (Throwable $throwable) {
            $summary['error_count'] = max(0, (int) ($summary['error_count'] ?? 0)) + 1;
            $errors = array_values((array) ($summary['errors'] ?? []));
            $errors[] = [
                'recording_id' => $recording_id,
                'message' => $throwable->getMessage(),
            ];
            $summary['errors'] = array_slice($errors, -10);
            $recent[] = [
                'recording_id' => $recording_id,
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        }
    }

    $summary['recent'] = array_slice($recent, -10);
    $summary['total'] = $total;
    $job['summary'] = $summary;
    $job['current_index'] = $current_index;
    $job['status'] = $current_index >= $total ? 'completed' : 'running';

    return [
        'job' => $job,
        'processed_this_request' => $processed_this_request,
        'profiles' => $profiles,
    ];
}

function ll_tools_rest_automation_process_transcription_validation_job(WP_REST_Request $request) {
    ll_tools_rest_automation_load_orthography_helpers();

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_transcription_validation_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset_id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error(
            'll_tools_rest_transcription_validation_job_wordset_mismatch',
            __('Transcription validation job belongs to a different word set.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }

    $job = ll_tools_rest_transcription_validation_job_apply_auto_request($job, $request, !empty($job['auto_process']));
    if ((string) ($job['status'] ?? '') === 'completed') {
        $job['auto_process'] = false;
        ll_tools_rest_transcription_validation_job_clear_schedule((string) ($job['id'] ?? ''));
        ll_tools_rest_transcription_validation_job_save($job);
        return rest_ensure_response(['job' => ll_tools_rest_transcription_validation_job_summary($job)]);
    }

    $settings_only = $request->has_param('settings_only')
        ? rest_sanitize_boolean($request->get_param('settings_only'))
        : false;
    $profile_enabled = $request->has_param('profile')
        ? rest_sanitize_boolean($request->get_param('profile'))
        : false;
    $limit_info = ll_tools_rest_automation_resolve_batch_limit($request, 'transcription_validation_jobs', false);
    $limit = (int) ($limit_info['effective'] ?? 10);
    $process_result = $settings_only
        ? [
            'job' => $job,
            'processed_this_request' => 0,
            'profiles' => [],
        ]
        : ll_tools_rest_transcription_validation_job_process_records($job, (int) $wordset_term->term_id, $limit, $profile_enabled);
    $job = (array) ($process_result['job'] ?? $job);

    if ((string) ($job['status'] ?? '') === 'completed') {
        $job['auto_process'] = false;
        ll_tools_rest_transcription_validation_job_clear_schedule((string) ($job['id'] ?? ''));
    } elseif (!empty($job['auto_process'])) {
        ll_tools_rest_transcription_validation_job_schedule_next($job);
    } else {
        ll_tools_rest_transcription_validation_job_clear_schedule((string) ($job['id'] ?? ''));
    }
    ll_tools_rest_transcription_validation_job_save($job);

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'limit' => $limit,
        'limit_info' => $limit_info,
        'settings_only' => $settings_only,
        'profile' => $profile_enabled,
        'processed_this_request' => max(0, (int) ($process_result['processed_this_request'] ?? 0)),
        'profiles' => $profile_enabled ? array_values((array) ($process_result['profiles'] ?? [])) : [],
        'job' => ll_tools_rest_transcription_validation_job_summary($job),
    ]);
}

add_action(LL_TOOLS_TRANSCRIPTION_VALIDATION_JOB_CRON_HOOK, 'll_tools_rest_transcription_validation_job_run_scheduled', 10, 1);
function ll_tools_rest_transcription_validation_job_run_scheduled($job_id): void {
    ll_tools_rest_automation_load_orthography_helpers();

    $job_id = (string) $job_id;
    $job = ll_tools_rest_transcription_validation_job_get($job_id);
    if (is_wp_error($job) || !is_array($job)) {
        return;
    }

    if (empty($job['auto_process']) || (string) ($job['status'] ?? '') === 'completed') {
        ll_tools_rest_transcription_validation_job_clear_schedule($job_id);
        return;
    }

    $lock_key = 'll_tools_transcription_validation_job_lock_' . md5($job_id);
    if (get_transient($lock_key)) {
        ll_tools_rest_transcription_validation_job_schedule_next($job);
        return;
    }

    set_transient($lock_key, time(), 5 * MINUTE_IN_SECONDS);
    try {
        $wordset_id = max(0, (int) ($job['wordset_id'] ?? 0));
        if ($wordset_id <= 0) {
            $job['status'] = 'completed';
            ll_tools_rest_transcription_validation_job_save($job);
            ll_tools_rest_transcription_validation_job_clear_schedule($job_id);
            return;
        }

        $limit = ll_tools_rest_transcription_validation_job_auto_limit($job['auto_limit'] ?? null);
        $process_result = ll_tools_rest_transcription_validation_job_process_records($job, $wordset_id, $limit);
        $job = (array) ($process_result['job'] ?? $job);
        $job['last_auto_process_gmt'] = gmdate('c');

        if ((string) ($job['status'] ?? '') === 'completed') {
            ll_tools_rest_transcription_validation_job_clear_schedule($job_id);
        } elseif (!empty($job['auto_process'])) {
            ll_tools_rest_transcription_validation_job_schedule_next($job);
        }

        ll_tools_rest_transcription_validation_job_save($job);
    } finally {
        delete_transient($lock_key);
    }
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

function ll_tools_rest_automation_wordset_profile_payload(WP_Term $wordset_term): array {
    $wordset_id = (int) $wordset_term->term_id;
    $profile = function_exists('ll_tools_get_wordset_profile_summary')
        ? ll_tools_get_wordset_profile_summary($wordset_id, 'large', false)
        : [];
    $image = isset($profile['image']) && is_array($profile['image']) ? $profile['image'] : [];
    $attachment_id = isset($image['attachment_id']) ? (int) $image['attachment_id'] : 0;

    return [
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'display_name' => function_exists('ll_tools_get_wordset_display_name')
            ? ll_tools_get_wordset_display_name($wordset_term)
            : (string) $wordset_term->name,
        'language_code' => (string) ($profile['language_code'] ?? ''),
        'target_language' => (string) ($profile['language_code'] ?? ''),
        'translation_language' => (string) ($profile['translation_language'] ?? ''),
        'profile_blurb' => (string) ($profile['profile_blurb'] ?? ''),
        'display_profile_blurb' => function_exists('ll_tools_get_wordset_profile_blurb')
            ? ll_tools_get_wordset_profile_blurb((int) $wordset_term->term_id, ['use_locale' => true])
            : (string) ($profile['profile_blurb'] ?? ''),
        'translations' => function_exists('ll_tools_get_entity_translations')
            ? ll_tools_get_entity_translations('term', (int) $wordset_term->term_id)
            : [],
        'profile_image' => [
            'attachment_id' => $attachment_id,
            'url' => (string) ($image['url'] ?? ''),
            'title' => (string) ($image['title'] ?? ''),
        ],
        'profile_image_attachment_id' => $attachment_id,
        'button_image_attachment_id' => $attachment_id,
    ];
}

function ll_tools_rest_automation_profile_uploaded_image_id(WP_REST_Request $request, WP_Term $wordset_term) {
    $files = $request->get_file_params();
    if (!is_array($files) || empty($files)) {
        return 0;
    }

    $file = null;
    foreach (['profile_image', 'thumbnail', 'image', 'file'] as $key) {
        if (isset($files[$key]) && is_array($files[$key])) {
            $file = $files[$key];
            break;
        }
    }
    if (!is_array($file)) {
        return 0;
    }

    if (!current_user_can('upload_files')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_profile_image_upload_forbidden',
            __('You do not have permission to upload files.', 'll-tools-text-domain'),
            403
        );
    }

    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $title = sprintf(
        /* translators: %s: word set name. */
        __('%s profile image', 'll-tools-text-domain'),
        (string) $wordset_term->name
    );
    $attachment_id = media_handle_sideload($file, 0, $title);
    if (is_wp_error($attachment_id)) {
        return ll_tools_rest_automation_with_status($attachment_id, 400);
    }

    $attachment_id = function_exists('ll_tools_sanitize_wordset_button_image_attachment_id')
        ? ll_tools_sanitize_wordset_button_image_attachment_id($attachment_id)
        : absint($attachment_id);
    if ($attachment_id <= 0) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_profile_image_invalid',
            __('The uploaded file is not a valid image.', 'll-tools-text-domain'),
            400
        );
    }

    return $attachment_id;
}

function ll_tools_rest_automation_translation_supported_fields(): array {
    return [
        'wordset' => ['name', 'profile_blurb'],
        'category' => ['name'],
        'lesson' => ['title', 'excerpt'],
    ];
}

function ll_tools_rest_automation_normalize_translation_entity_type($type): string {
    $type = sanitize_key((string) $type);
    $aliases = [
        'word_set' => 'wordset',
        'word-category' => 'category',
        'word_category' => 'category',
        'category_name' => 'category',
        'vocab_lesson' => 'lesson',
        'content_lesson' => 'lesson',
        'll_vocab_lesson' => 'lesson',
        'll_content_lesson' => 'lesson',
    ];
    if (isset($aliases[$type])) {
        $type = $aliases[$type];
    }

    return in_array($type, ['wordset', 'category', 'lesson'], true) ? $type : '';
}

function ll_tools_rest_automation_normalize_translation_field(string $entity_type, $field): string {
    $field = function_exists('ll_tools_normalize_entity_translation_field')
        ? ll_tools_normalize_entity_translation_field($field)
        : sanitize_key((string) $field);

    if (($entity_type === 'wordset' || $entity_type === 'category') && $field === 'title') {
        $field = 'name';
    } elseif ($entity_type === 'lesson' && $field === 'name') {
        $field = 'title';
    }

    $supported = ll_tools_rest_automation_translation_supported_fields();
    return in_array($field, (array) ($supported[$entity_type] ?? []), true) ? $field : '';
}

function ll_tools_rest_automation_translation_locale_from_request(WP_REST_Request $request): string {
    $locale = ll_tools_rest_automation_request_string($request, 'locale');
    if ($locale === '') {
        $locale = ll_tools_rest_automation_request_string($request, 'language');
    }

    if (function_exists('ll_tools_normalize_entity_translation_locale')) {
        $locale = ll_tools_normalize_entity_translation_locale($locale);
    }

    if ($locale === '' && function_exists('ll_tools_current_entity_translation_locale')) {
        $locale = ll_tools_current_entity_translation_locale();
    }

    return $locale;
}

function ll_tools_rest_automation_translation_add_fields(array &$updates, string $entity_type, string $locale, array $fields): void {
    $locale = function_exists('ll_tools_normalize_entity_translation_locale')
        ? ll_tools_normalize_entity_translation_locale($locale)
        : sanitize_key($locale);
    if ($locale === '') {
        return;
    }

    foreach ($fields as $field => $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $field = ll_tools_rest_automation_normalize_translation_field($entity_type, $field);
        if ($field === '') {
            continue;
        }

        if (!isset($updates[$locale])) {
            $updates[$locale] = [];
        }
        $updates[$locale][$field] = (string) $value;
    }
}

function ll_tools_rest_automation_translation_item_updates(array $item, string $entity_type, string $default_locale): array {
    $updates = [];

    $translations = isset($item['translations']) && is_array($item['translations']) ? $item['translations'] : [];
    if (!empty($translations)) {
        $looks_like_field_map = false;
        foreach (array_keys($translations) as $key) {
            if (ll_tools_rest_automation_normalize_translation_field($entity_type, (string) $key) !== '') {
                $looks_like_field_map = true;
                break;
            }
        }

        if ($looks_like_field_map) {
            ll_tools_rest_automation_translation_add_fields($updates, $entity_type, $default_locale, $translations);
        } else {
            foreach ($translations as $locale => $fields) {
                if (is_array($fields)) {
                    ll_tools_rest_automation_translation_add_fields($updates, $entity_type, (string) $locale, $fields);
                }
            }
        }
    }

    if (isset($item['fields']) && is_array($item['fields'])) {
        $locale = isset($item['locale']) && is_scalar($item['locale'])
            ? (string) $item['locale']
            : $default_locale;
        ll_tools_rest_automation_translation_add_fields($updates, $entity_type, $locale, $item['fields']);
    }

    $direct_locale = isset($item['locale']) && is_scalar($item['locale'])
        ? (string) $item['locale']
        : $default_locale;
    ll_tools_rest_automation_translation_add_fields($updates, $entity_type, $direct_locale, $item);

    return $updates;
}

function ll_tools_rest_automation_translation_collect_group_items($raw_items, string $entity_type): array {
    if (!is_array($raw_items)) {
        return [];
    }

    $items = [];
    foreach ($raw_items as $key => $raw_item) {
        if (is_array($raw_item)) {
            $item = $raw_item;
        } elseif (is_scalar($raw_item)) {
            $item = ['name' => (string) $raw_item];
            if ($entity_type === 'lesson') {
                $item = ['title' => (string) $raw_item];
            }
        } else {
            continue;
        }

        $item['type'] = $entity_type;
        if (is_string($key) && !is_numeric($key)) {
            if ($entity_type === 'category' && empty($item['category']) && empty($item['slug']) && empty($item['id'])) {
                $item['category'] = $key;
            } elseif ($entity_type === 'lesson' && empty($item['lesson']) && empty($item['slug']) && empty($item['id'])) {
                $item['lesson'] = $key;
            }
        }
        $items[] = $item;
    }

    return $items;
}

function ll_tools_rest_automation_translation_collect_items(WP_REST_Request $request): array {
    $items = [];
    $raw_items = $request->get_param('items');
    if (is_array($raw_items)) {
        if (ll_tools_rest_automation_array_is_list($raw_items)) {
            foreach ($raw_items as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        } else {
            foreach ($raw_items as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['type']) && is_string($key)) {
                    $item['type'] = $key;
                }
                $items[] = $item;
            }
        }
    }

    $wordset = $request->get_param('wordset_translation');
    if (!is_array($wordset)) {
        $wordset = $request->get_param('wordset_fields');
    }
    if (!is_array($wordset)) {
        $wordset = $request->get_param('wordset_data');
    }
    if (is_array($wordset)) {
        $wordset['type'] = 'wordset';
        $items[] = $wordset;
    }

    $items = array_merge(
        $items,
        ll_tools_rest_automation_translation_collect_group_items($request->get_param('categories'), 'category'),
        ll_tools_rest_automation_translation_collect_group_items($request->get_param('lessons'), 'lesson')
    );

    return $items;
}

function ll_tools_rest_automation_translation_category_ids_for_wordset(int $wordset_id): array {
    $ids = [];
    $post_statuses = ['publish', 'future', 'draft', 'pending', 'private'];
    if (function_exists('ll_tools_get_word_category_ids_for_wordset_posts')) {
        $ids = array_merge(
            $ids,
            ll_tools_get_word_category_ids_for_wordset_posts($wordset_id, ['words'], $post_statuses)
        );
    }

    if (defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
        $owned = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                    'value' => (string) $wordset_id,
                    'compare' => '=',
                ],
            ],
        ]);
        if (!is_wp_error($owned)) {
            $ids = array_merge($ids, (array) $owned);
        }
    }

    return ll_tools_rest_automation_prepare_id_list($ids);
}

function ll_tools_rest_automation_translation_category_belongs_to_wordset(WP_Term $category, int $wordset_id): bool {
    if ($wordset_id <= 0 || $category->taxonomy !== 'word-category') {
        return false;
    }

    if (defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
        $owner_id = (int) get_term_meta((int) $category->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true);
        if ($owner_id === $wordset_id) {
            return true;
        }
    }

    return in_array((int) $category->term_id, ll_tools_rest_automation_translation_category_ids_for_wordset($wordset_id), true);
}

function ll_tools_rest_automation_translation_resolve_category(WP_Term $wordset_term, array $item) {
    $wordset_id = (int) $wordset_term->term_id;
    $term = null;
    $category_id = absint($item['id'] ?? ($item['term_id'] ?? ($item['category_id'] ?? 0)));
    if ($category_id > 0) {
        $term = get_term($category_id, 'word-category');
    }

    if (!$term instanceof WP_Term || is_wp_error($term)) {
        $spec = '';
        foreach (['category', 'category_slug', 'slug', 'name', 'title', 'label'] as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                $spec = trim((string) $item[$key]);
                break;
            }
        }

        if ($spec !== '' && function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
            $term = ll_tools_resolve_word_category_term_for_wordsets($spec, [$wordset_id]);
        } elseif ($spec !== '' && function_exists('ll_tools_resolve_word_category_term')) {
            $term = ll_tools_resolve_word_category_term($spec);
        }
    }

    if (!$term instanceof WP_Term || is_wp_error($term)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_category_not_found',
            __('Unable to resolve a word category for the translation item.', 'll-tools-text-domain'),
            404
        );
    }

    if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_id = (int) ll_tools_get_effective_category_id_for_wordset((int) $term->term_id, $wordset_id, false);
        if ($effective_id > 0 && $effective_id !== (int) $term->term_id) {
            $effective = get_term($effective_id, 'word-category');
            if ($effective instanceof WP_Term && !is_wp_error($effective)) {
                $term = $effective;
            }
        }
    }

    if (!ll_tools_rest_automation_translation_category_belongs_to_wordset($term, $wordset_id)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_category_forbidden',
            __('That word category is not part of this word set.', 'll-tools-text-domain'),
            403
        );
    }

    return $term;
}

function ll_tools_rest_automation_translation_lesson_posts_for_wordset(int $wordset_id): array {
    $meta_query = ['relation' => 'OR'];
    if (defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')) {
        $meta_query[] = [
            'key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
            'value' => (string) $wordset_id,
            'compare' => '=',
        ];
    }
    if (defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META')) {
        $meta_query[] = [
            'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            'value' => (string) $wordset_id,
            'compare' => '=',
        ];
    }
    if (count($meta_query) < 2) {
        return [];
    }

    $posts = get_posts([
        'post_type' => ['ll_vocab_lesson', 'll_content_lesson'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => $meta_query,
    ]);

    return array_values(array_filter((array) $posts, static function ($post): bool {
        return $post instanceof WP_Post;
    }));
}

function ll_tools_rest_automation_translation_lesson_belongs_to_wordset(WP_Post $post, int $wordset_id): bool {
    if (!in_array($post->post_type, ['ll_vocab_lesson', 'll_content_lesson'], true)) {
        return false;
    }

    $meta_key = $post->post_type === 'll_vocab_lesson'
        ? (defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META') ? LL_TOOLS_VOCAB_LESSON_WORDSET_META : '')
        : (defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META') ? LL_TOOLS_CONTENT_LESSON_WORDSET_META : '');

    return $meta_key !== '' && (int) get_post_meta((int) $post->ID, $meta_key, true) === $wordset_id;
}

function ll_tools_rest_automation_translation_resolve_lesson(WP_Term $wordset_term, array $item) {
    $wordset_id = (int) $wordset_term->term_id;
    $post = null;
    $post_id = absint($item['id'] ?? ($item['post_id'] ?? ($item['lesson_id'] ?? 0)));
    if ($post_id > 0) {
        $post = get_post($post_id);
    }

    if (!$post instanceof WP_Post) {
        $spec = '';
        foreach (['lesson', 'post_slug', 'lesson_slug', 'slug', 'title', 'name'] as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                $spec = trim((string) $item[$key]);
                break;
            }
        }

        if ($spec !== '') {
            foreach (ll_tools_rest_automation_translation_lesson_posts_for_wordset($wordset_id) as $candidate) {
                if (
                    strcasecmp((string) $candidate->post_name, sanitize_title($spec)) === 0
                    || strcasecmp((string) get_the_title($candidate), $spec) === 0
                ) {
                    $post = $candidate;
                    break;
                }
            }
        }
    }

    if (!$post instanceof WP_Post) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_lesson_not_found',
            __('Unable to resolve a lesson for the translation item.', 'll-tools-text-domain'),
            404
        );
    }

    if (!ll_tools_rest_automation_translation_lesson_belongs_to_wordset($post, $wordset_id)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_lesson_forbidden',
            __('That lesson is not part of this word set.', 'll-tools-text-domain'),
            403
        );
    }

    return $post;
}

function ll_tools_rest_automation_translation_term_payload(WP_Term $term, string $entity_type, int $wordset_id, string $locale): array {
    $base = ll_tools_rest_automation_term_summary($term);
    $base['entity_type'] = $entity_type;
    $base['translations'] = function_exists('ll_tools_get_entity_translations')
        ? ll_tools_get_entity_translations('term', (int) $term->term_id)
        : [];

    if ($entity_type === 'wordset') {
        $base['display_name'] = function_exists('ll_tools_get_wordset_display_name')
            ? ll_tools_get_wordset_display_name($term, ['locale' => $locale])
            : (string) $term->name;
        $base['profile_blurb'] = function_exists('ll_tools_get_wordset_profile_blurb')
            ? ll_tools_get_wordset_profile_blurb((int) $term->term_id, ['use_locale' => false])
            : '';
        $base['display_profile_blurb'] = function_exists('ll_tools_get_wordset_profile_blurb')
            ? ll_tools_get_wordset_profile_blurb((int) $term->term_id, ['use_locale' => true, 'locale' => $locale])
            : (string) ($base['profile_blurb'] ?? '');
    } elseif ($entity_type === 'category') {
        $base['display_name'] = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($term, ['wordset_ids' => [$wordset_id], 'site_language' => $locale])
            : (string) $term->name;
        $base['legacy_translation'] = (string) get_term_meta((int) $term->term_id, 'term_translation', true);
    }

    return $base;
}

function ll_tools_rest_automation_translation_lesson_payload(WP_Post $post, string $locale): array {
    $fallback_excerpt = trim((string) $post->post_excerpt);
    if ($fallback_excerpt === '') {
        $fallback_excerpt = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 28);
    }

    return [
        'entity_type' => 'lesson',
        'id' => (int) $post->ID,
        'post_type' => (string) $post->post_type,
        'slug' => (string) $post->post_name,
        'title' => (string) get_the_title($post),
        'display_title' => function_exists('ll_tools_get_lesson_display_title')
            ? ll_tools_get_lesson_display_title($post, ['fallback' => (string) get_the_title($post), 'locale' => $locale])
            : (string) get_the_title($post),
        'excerpt' => $fallback_excerpt,
        'display_excerpt' => function_exists('ll_tools_get_lesson_display_excerpt')
            ? ll_tools_get_lesson_display_excerpt($post, $fallback_excerpt, ['locale' => $locale])
            : $fallback_excerpt,
        'translations' => function_exists('ll_tools_get_entity_translations')
            ? ll_tools_get_entity_translations('post', (int) $post->ID)
            : [],
    ];
}

function ll_tools_rest_automation_wordset_translations_payload(WP_Term $wordset_term, string $locale): array {
    $wordset_id = (int) $wordset_term->term_id;
    $categories = [];
    foreach (ll_tools_rest_automation_translation_category_ids_for_wordset($wordset_id) as $category_id) {
        $term = get_term((int) $category_id, 'word-category');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            $categories[] = ll_tools_rest_automation_translation_term_payload($term, 'category', $wordset_id, $locale);
        }
    }

    usort($categories, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['display_name'] ?? $left['name'] ?? ''), (string) ($right['display_name'] ?? $right['name'] ?? ''));
    });

    $lessons = [];
    foreach (ll_tools_rest_automation_translation_lesson_posts_for_wordset($wordset_id) as $post) {
        $lessons[] = ll_tools_rest_automation_translation_lesson_payload($post, $locale);
    }

    usort($lessons, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['display_title'] ?? $left['title'] ?? ''), (string) ($right['display_title'] ?? $right['title'] ?? ''));
    });

    return [
        'generated_at_gmt' => gmdate('c'),
        'locale' => $locale,
        'supported_fields' => ll_tools_rest_automation_translation_supported_fields(),
        'wordset' => ll_tools_rest_automation_translation_term_payload($wordset_term, 'wordset', $wordset_id, $locale),
        'categories' => $categories,
        'lessons' => $lessons,
    ];
}

function ll_tools_rest_automation_wordset_translations(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $locale = ll_tools_rest_automation_translation_locale_from_request($request);
    $method = strtoupper((string) $request->get_method());
    if ($method === 'GET') {
        return rest_ensure_response(ll_tools_rest_automation_wordset_translations_payload($wordset_term, $locale));
    }

    if (!function_exists('ll_tools_update_entity_translations')) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_unavailable',
            __('Entity translation helpers are not available.', 'll-tools-text-domain'),
            500
        );
    }

    $items = ll_tools_rest_automation_translation_collect_items($request);
    if (empty($items)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_translation_missing_items',
            __('Provide wordset, categories, lessons, or items to update translations.', 'll-tools-text-domain'),
            400
        );
    }

    $dry_run = (bool) rest_sanitize_boolean($request->get_param('dry_run'));
    $resolved_items = [];
    $errors = [];
    foreach ($items as $index => $item) {
        $entity_type = ll_tools_rest_automation_normalize_translation_entity_type($item['type'] ?? ($item['entity_type'] ?? ($item['object_type'] ?? '')));
        if ($entity_type === '') {
            $errors[] = [
                'index' => (int) $index,
                'message' => __('Translation items must include type wordset, category, or lesson.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $updates = ll_tools_rest_automation_translation_item_updates($item, $entity_type, $locale);
        if (empty($updates)) {
            $errors[] = [
                'index' => (int) $index,
                'type' => $entity_type,
                'message' => __('Translation item does not contain any supported translated fields.', 'll-tools-text-domain'),
            ];
            continue;
        }

        if ($entity_type === 'wordset') {
            $resolved_items[] = [
                'entity_type' => 'wordset',
                'object_type' => 'term',
                'object_id' => (int) $wordset_term->term_id,
                'label' => (string) $wordset_term->slug,
                'updates' => $updates,
            ];
            continue;
        }

        if ($entity_type === 'category') {
            $term = ll_tools_rest_automation_translation_resolve_category($wordset_term, $item);
            if (is_wp_error($term)) {
                $errors[] = [
                    'index' => (int) $index,
                    'type' => 'category',
                    'message' => $term->get_error_message(),
                    'code' => $term->get_error_code(),
                ];
                continue;
            }

            $resolved_items[] = [
                'entity_type' => 'category',
                'object_type' => 'term',
                'object_id' => (int) $term->term_id,
                'label' => (string) $term->slug,
                'updates' => $updates,
            ];
            continue;
        }

        $post = ll_tools_rest_automation_translation_resolve_lesson($wordset_term, $item);
        if (is_wp_error($post)) {
            $errors[] = [
                'index' => (int) $index,
                'type' => 'lesson',
                'message' => $post->get_error_message(),
                'code' => $post->get_error_code(),
            ];
            continue;
        }

        $resolved_items[] = [
            'entity_type' => 'lesson',
            'object_type' => 'post',
            'object_id' => (int) $post->ID,
            'label' => (string) $post->post_name,
            'updates' => $updates,
        ];
    }

    if (!empty($errors)) {
        return new WP_Error(
            'll_tools_rest_translation_invalid_items',
            __('One or more translation items could not be applied.', 'll-tools-text-domain'),
            [
                'status' => 400,
                'errors' => $errors,
            ]
        );
    }

    $results = [];
    $changed_wordset_ids = [];
    $changed_category_ids = [];
    $changed_post_ids = [];
    foreach ($resolved_items as $resolved) {
        $result = ll_tools_update_entity_translations(
            (string) $resolved['object_type'],
            (int) $resolved['object_id'],
            (array) $resolved['updates'],
            $dry_run
        );
        $changed = !empty($result['changed']);
        if ($changed && !$dry_run) {
            if ($resolved['entity_type'] === 'category') {
                $changed_category_ids[] = (int) $resolved['object_id'];
            } elseif ($resolved['entity_type'] === 'lesson') {
                $changed_post_ids[] = (int) $resolved['object_id'];
            }
            $changed_wordset_ids[] = (int) $wordset_term->term_id;
        }

        $results[] = [
            'entity_type' => (string) $resolved['entity_type'],
            'object_type' => (string) $resolved['object_type'],
            'object_id' => (int) $resolved['object_id'],
            'label' => (string) $resolved['label'],
            'changed' => $changed,
            'updates' => (array) $resolved['updates'],
            'before' => (array) ($result['before'] ?? []),
            'after' => (array) ($result['after'] ?? []),
        ];
    }

    if (!$dry_run) {
        if (!empty($changed_category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
            ll_tools_bump_category_cache_version(array_values(array_unique($changed_category_ids)));
        }
        foreach (array_values(array_unique($changed_post_ids)) as $post_id) {
            clean_post_cache((int) $post_id);
        }
        if (!empty($changed_post_ids) && function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
            ll_tools_invalidate_wordset_page_lesson_cache();
        }
        if (!empty($changed_wordset_ids) && function_exists('ll_tools_bump_wordset_cache_epoch')) {
            ll_tools_bump_wordset_cache_epoch(array_values(array_unique($changed_wordset_ids)));
        }
    }

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'changed' => !empty(array_filter($results, static function (array $result): bool {
            return !empty($result['changed']);
        })),
        'changed_count' => count(array_filter($results, static function (array $result): bool {
            return !empty($result['changed']);
        })),
        'results' => $results,
        'after' => ll_tools_rest_automation_wordset_translations_payload($wordset_term, $locale),
    ]);
}

function ll_tools_rest_automation_wordset_profile(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $method = strtoupper((string) $request->get_method());
    $before = ll_tools_rest_automation_wordset_profile_payload($wordset_term);
    if ($method === 'GET') {
        return rest_ensure_response($before);
    }

    $wordset_id = (int) $wordset_term->term_id;
    $changed_keys = [];

    $uploaded_attachment_id = ll_tools_rest_automation_profile_uploaded_image_id($request, $wordset_term);
    if (is_wp_error($uploaded_attachment_id) || $uploaded_attachment_id instanceof WP_REST_Response) {
        return $uploaded_attachment_id;
    }
    if ((int) $uploaded_attachment_id > 0) {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, (int) $uploaded_attachment_id);
        $changed_keys[] = 'profile_image_attachment_id';
    } elseif (ll_tools_rest_automation_request_has_any_param($request, ['profile_image_attachment_id', 'button_image_attachment_id', 'thumbnail_attachment_id'])) {
        $raw_attachment_id = ll_tools_rest_automation_first_request_param($request, ['profile_image_attachment_id', 'button_image_attachment_id', 'thumbnail_attachment_id']);
        $requested_attachment_id = absint($raw_attachment_id);
        $attachment_id = function_exists('ll_tools_sanitize_wordset_button_image_attachment_id')
            ? ll_tools_sanitize_wordset_button_image_attachment_id($raw_attachment_id)
            : $requested_attachment_id;
        if ($requested_attachment_id > 0 && $attachment_id <= 0) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_profile_image_invalid',
                __('The profile image attachment must be an existing image attachment.', 'll-tools-text-domain'),
                400
            );
        }
        if ($attachment_id <= 0) {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY);
        } else {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, $attachment_id);
        }
        $changed_keys[] = 'profile_image_attachment_id';
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['profile_blurb', 'intro_blurb', 'blurb'])) {
        $raw_blurb = ll_tools_rest_automation_first_request_param($request, ['profile_blurb', 'intro_blurb', 'blurb']);
        $profile_blurb = function_exists('ll_tools_sanitize_wordset_profile_blurb')
            ? ll_tools_sanitize_wordset_profile_blurb((string) $raw_blurb)
            : sanitize_textarea_field((string) $raw_blurb);
        if ($profile_blurb === '') {
            delete_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY);
        } else {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, $profile_blurb);
        }
        $changed_keys[] = 'profile_blurb';
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['language_code', 'target_language'])) {
        $language_code = function_exists('ll_tools_sanitize_wordset_language_setting')
            ? ll_tools_sanitize_wordset_language_setting((string) ll_tools_rest_automation_first_request_param($request, ['language_code', 'target_language']))
            : sanitize_text_field((string) ll_tools_rest_automation_first_request_param($request, ['language_code', 'target_language']));
        update_term_meta($wordset_id, 'll_language', $language_code);
        $changed_keys[] = 'language_code';
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['translation_language'])) {
        $translation_language = function_exists('ll_tools_sanitize_wordset_language_setting')
            ? ll_tools_sanitize_wordset_language_setting((string) $request->get_param('translation_language'))
            : sanitize_text_field((string) $request->get_param('translation_language'));
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, $translation_language);
        $changed_keys[] = 'translation_language';
    }

    $changed_keys = array_values(array_unique($changed_keys));
    if (!empty($changed_keys) && function_exists('ll_tools_bump_wordset_cache_epoch')) {
        ll_tools_bump_wordset_cache_epoch([$wordset_id]);
    }

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'changed' => !empty($changed_keys),
        'changed_keys' => $changed_keys,
        'before' => $before,
        'after' => ll_tools_rest_automation_wordset_profile_payload($wordset_term),
    ]);
}

function ll_tools_rest_automation_orthography_conversion_payload(WP_Term $wordset_term): array {
    ll_tools_rest_automation_load_orthography_helpers();

    $wordset_id = (int) $wordset_term->term_id;
    $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    $effective_manual_rules = function_exists('ll_tools_ipa_orthography_get_effective_manual_rules')
        ? ll_tools_ipa_orthography_get_effective_manual_rules($wordset_id)
        : $manual_rules;
    $orthography_settings = ll_tools_ipa_orthography_get_settings($wordset_id);

    return [
        'generated_at_gmt' => gmdate('c'),
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'profile_key' => ll_tools_ipa_orthography_get_profile_key($wordset_id),
        'conversion_profile' => ll_tools_ipa_orthography_get_conversion_profile($wordset_id),
        'available_profiles' => array_values(ll_tools_ipa_orthography_get_available_conversion_profiles()),
        'manual_rules' => $manual_rules,
        'manual_rule_count' => count($manual_rules),
        'effective_manual_rules' => $effective_manual_rules,
        'effective_manual_rule_count' => count($effective_manual_rules),
        'orthography_settings' => $orthography_settings,
        'exception_word_ids' => ll_tools_ipa_orthography_get_exception_word_ids($wordset_id),
        'exception_dictionary_entry_ids' => ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id),
    ];
}

function ll_tools_rest_automation_orthography_conversion(WP_REST_Request $request) {
    ll_tools_rest_automation_load_orthography_helpers();

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $method = strtoupper((string) $request->get_method());
    $before = ll_tools_rest_automation_orthography_conversion_payload($wordset_term);
    if ($method === 'GET') {
        return rest_ensure_response($before);
    }

    $wordset_id = (int) $wordset_term->term_id;
    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
    $changed_keys = [];
    $rescan_count = 0;

    if (ll_tools_rest_automation_request_has_any_param($request, ['profile_key', 'profile', 'conversion_profile'])
        && sanitize_key((string) ll_tools_rest_automation_first_request_param($request, ['profile_key', 'profile', 'conversion_profile'])) !== '') {
            return ll_tools_rest_automation_error(
                'll_tools_rest_orthography_profile_invalid',
                __('Orthography conversion profiles are no longer supported. Configure wordset-level orthography settings instead.', 'll-tools-text-domain'),
                400
            );
    }

    $current_manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    $next_manual_rules = $current_manual_rules;
    if (rest_sanitize_boolean($request->get_param('clear_manual_rules'))) {
        $next_manual_rules = [];
    }

    if (ll_tools_rest_automation_request_has_any_param($request, ['manual_rules', 'mappings', 'ipa_to_orthography'])) {
        $raw_rules = ll_tools_rest_automation_first_request_param($request, ['manual_rules', 'mappings', 'ipa_to_orthography']);
        if (!is_array($raw_rules)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_orthography_rules_invalid',
                __('Manual orthography rules must be an object keyed by IPA segment.', 'll-tools-text-domain'),
                400
            );
        }

        $incoming_rules = ll_tools_ipa_orthography_sanitize_manual_rules($raw_rules, $wordset_id);
        if (rest_sanitize_boolean($request->get_param('replace_manual_rules'))) {
            $next_manual_rules = $incoming_rules;
        } else {
            foreach ($incoming_rules as $segment => $contexts) {
                if (!isset($next_manual_rules[$segment]) || !is_array($next_manual_rules[$segment])) {
                    $next_manual_rules[$segment] = [];
                }
                foreach ((array) $contexts as $context => $output) {
                    $next_manual_rules[$segment][$context] = $output;
                }
            }
            $next_manual_rules = ll_tools_ipa_orthography_sanitize_manual_rules($next_manual_rules, $wordset_id);
        }
    }

    $current_settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $next_settings = $current_settings;
    $settings_param_keys = [
        'orthography_settings',
        'settings',
        'word_overrides',
        'word_override_entry_ids',
        'phrase_overrides',
        'optional_matches',
        'recording_type_punctuation',
        'sentence_case',
        'clear_orthography_settings',
        'clear_settings',
    ];
    if (ll_tools_rest_automation_request_has_any_param($request, $settings_param_keys)) {
        if (rest_sanitize_boolean($request->get_param('clear_orthography_settings')) || rest_sanitize_boolean($request->get_param('clear_settings'))) {
            $next_settings = ll_tools_ipa_orthography_settings_defaults();
        }

        $raw_settings = [];
        if (ll_tools_rest_automation_request_has_any_param($request, ['orthography_settings', 'settings'])) {
            $raw_settings = ll_tools_rest_automation_first_request_param($request, ['orthography_settings', 'settings']);
            if (!is_array($raw_settings)) {
                return ll_tools_rest_automation_error(
                    'll_tools_rest_orthography_settings_invalid',
                    __('Orthography settings must be an object.', 'll-tools-text-domain'),
                    400
                );
            }
        }

        foreach (['word_overrides', 'word_override_word_ids', 'word_override_entry_ids', 'phrase_overrides', 'optional_matches', 'recording_type_punctuation', 'sentence_case'] as $setting_key) {
            if ($request->has_param($setting_key)) {
                $raw_settings[$setting_key] = $request->get_param($setting_key);
            }
        }

        if ((array_key_exists('word_override_word_ids', $raw_settings) || array_key_exists('word_override_entry_ids', $raw_settings))
            && !array_key_exists('word_overrides', $raw_settings)) {
            $raw_settings['word_overrides'] = (array) ($next_settings['word_overrides'] ?? []);
        }

        if (!empty($raw_settings)) {
            $incoming_settings = ll_tools_ipa_orthography_sanitize_settings($raw_settings, $wordset_id);
            if (rest_sanitize_boolean($request->get_param('replace_orthography_settings')) || rest_sanitize_boolean($request->get_param('replace_settings'))) {
                $next_settings = $incoming_settings;
            } else {
                foreach (['word_overrides', 'word_override_word_ids', 'word_override_entry_ids', 'recording_type_punctuation'] as $setting_key) {
                    if (array_key_exists($setting_key, $raw_settings)
                        || (in_array($setting_key, ['word_override_word_ids', 'word_override_entry_ids'], true)
                            && array_key_exists('word_overrides', $raw_settings))) {
                        $next_settings[$setting_key] = array_merge(
                            (array) ($next_settings[$setting_key] ?? []),
                            (array) ($incoming_settings[$setting_key] ?? [])
                        );
                    }
                }
                foreach (['phrase_overrides', 'optional_matches'] as $setting_key) {
                    if (array_key_exists($setting_key, $raw_settings)) {
                        $next_settings[$setting_key] = array_merge(
                            (array) ($next_settings[$setting_key] ?? []),
                            (array) ($incoming_settings[$setting_key] ?? [])
                        );
                    }
                }
                if (array_key_exists('sentence_case', $raw_settings)) {
                    $next_settings['sentence_case'] = (bool) ($incoming_settings['sentence_case'] ?? false);
                }
                $next_settings = ll_tools_ipa_orthography_sanitize_settings($next_settings, $wordset_id);
            }
        }
    }

    if ($next_settings !== $current_settings) {
        $changed_keys[] = 'orthography_settings';
        if (!$dry_run) {
            if ($next_settings === ll_tools_ipa_orthography_settings_defaults()) {
                delete_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key());
            } else {
                update_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key(), $next_settings);
            }
        }
    }

    $current_exception_word_ids = ll_tools_ipa_orthography_get_exception_word_ids($wordset_id);
    $current_exception_dictionary_entry_ids = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id, $current_exception_word_ids);
    $next_exception_word_ids = $current_exception_word_ids;
    $next_exception_dictionary_entry_ids = $current_exception_dictionary_entry_ids;
    if (rest_sanitize_boolean($request->get_param('clear_exception_word_ids'))) {
        $next_exception_word_ids = [];
        $next_exception_dictionary_entry_ids = [];
    }
    if ($request->has_param('exception_word_ids')) {
        $raw_exception_word_ids = $request->get_param('exception_word_ids');
        if (!is_array($raw_exception_word_ids)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_orthography_exception_word_ids_invalid',
                __('Orthography exception word IDs must be an array.', 'll-tools-text-domain'),
                400
            );
        }
        $next_exception_word_ids = ll_tools_ipa_orthography_sanitize_exception_word_ids($raw_exception_word_ids);
        $next_exception_dictionary_entry_ids = ll_tools_ipa_orthography_infer_exception_dictionary_entry_ids($next_exception_word_ids);
    }
    if ($request->has_param('exception_dictionary_entry_ids')) {
        $raw_exception_dictionary_entry_ids = $request->get_param('exception_dictionary_entry_ids');
        if (!is_array($raw_exception_dictionary_entry_ids)) {
            return ll_tools_rest_automation_error(
                'll_tools_rest_orthography_exception_dictionary_entry_ids_invalid',
                __('Orthography exception dictionary entry IDs must be an object keyed by word ID.', 'll-tools-text-domain'),
                400
            );
        }
        $next_exception_dictionary_entry_ids = ll_tools_ipa_orthography_sanitize_exception_dictionary_entry_ids(
            $raw_exception_dictionary_entry_ids,
            $next_exception_word_ids
        );
    }
    if ($next_exception_word_ids !== $current_exception_word_ids) {
        $changed_keys[] = 'exception_word_ids';
        if (!$dry_run) {
            if (empty($next_exception_word_ids)) {
                delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key());
            } else {
                update_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key(), $next_exception_word_ids);
            }
        }
    }
    if ($next_exception_dictionary_entry_ids !== $current_exception_dictionary_entry_ids) {
        $changed_keys[] = 'exception_dictionary_entry_ids';
        if (!$dry_run) {
            if (empty($next_exception_dictionary_entry_ids)) {
                delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key());
            } else {
                update_term_meta(
                    $wordset_id,
                    ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(),
                    $next_exception_dictionary_entry_ids
                );
            }
        }
    }

    if ($next_manual_rules !== $current_manual_rules) {
        $changed_keys[] = 'manual_rules';
        if (!$dry_run) {
            if (empty($next_manual_rules)) {
                delete_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key());
            } else {
                update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), $next_manual_rules);
            }
        }
    }

    $changed_keys = array_values(array_unique($changed_keys));
    if (!$dry_run && !empty($changed_keys)) {
        if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
            ll_tools_bump_wordset_cache_epoch([$wordset_id]);
        }
        if (rest_sanitize_boolean($request->get_param('rescan_validations')) || !ll_tools_rest_automation_request_has_any_param($request, ['rescan_validations'])) {
            $rescan_count = ll_tools_ipa_keyboard_rescan_wordset_validations($wordset_id);
        }
    }

    return rest_ensure_response([
        'generated_at_gmt' => gmdate('c'),
        'dry_run' => $dry_run,
        'wordset' => ll_tools_rest_automation_term_summary($wordset_term),
        'changed' => !empty($changed_keys),
        'changed_keys' => $changed_keys,
        'rescan_count' => $rescan_count,
        'before' => $before,
        'after' => $dry_run ? $before : ll_tools_rest_automation_orthography_conversion_payload($wordset_term),
    ]);
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

    $processed_job = ll_tools_import_job_process_with_lock($job);
    if (is_wp_error($processed_job)) {
        if (ll_tools_import_job_is_process_lock_error($processed_job)) {
            $error_data = $processed_job->get_error_data();
            $error_data = is_array($error_data) ? $error_data : [];

            return new WP_Error(
                'll_tools_import_job_process_locked',
                $processed_job->get_error_message(),
                [
                    'status' => 429,
                    'job' => ll_tools_import_job_get_snapshot($job),
                    'locked' => true,
                    'retry_after_seconds' => (float) ($error_data['retry_after_seconds'] ?? 1.0),
                ]
            );
        }

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
        if (is_array($params)
            && (isset($params['schema'])
                || isset($params['kind'])
                || isset($params['source_lines'])
                || isset($params['reading_units'])
                || isset($params['book_sections']))) {
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

function ll_tools_rest_get_corpus_text(WP_REST_Request $request) {
    $post = ll_tools_rest_corpus_text_find_post_by_slug(ll_tools_rest_automation_request_string($request, 'slug'));
    if (!($post instanceof WP_Post)) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_not_found',
            __('Could not find a matching corpus text post.', 'll-tools-text-domain'),
            404
        );
    }

    if (
        defined('LL_TOOLS_CONTENT_LESSON_KIND_META')
        && get_post_meta((int) $post->ID, LL_TOOLS_CONTENT_LESSON_KIND_META, true) !== 'corpus_text'
    ) {
        return ll_tools_rest_automation_error(
            'll_tools_rest_corpus_text_wrong_kind',
            __('The matching content lesson is not a corpus text.', 'll-tools-text-domain'),
            404
        );
    }

    $include_payload = $request->has_param('include_payload')
        ? (bool) rest_sanitize_boolean($request->get_param('include_payload'))
        : true;

    return rest_ensure_response(ll_tools_interlinear_payload_for_rest((int) $post->ID, $include_payload));
}

function ll_tools_rest_register_automation_routes(): void {
    register_rest_route('ll-tools/v1', '/automation/status', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_status',
        'permission_callback' => 'll_tools_rest_automation_require_view_access',
    ]);

    register_rest_route('ll-tools/v1', '/automation/plugin-update', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_plugin_update',
        'permission_callback' => 'll_tools_rest_automation_require_plugin_update_access',
        'args' => [
            'channel' => [
                'required' => false,
                'type' => 'string',
                'default' => 'dev',
                'enum' => ['dev', 'configured', 'current'],
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
            'confirm' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'expected_current_version' => [
                'required' => false,
                'type' => 'string',
            ],
            'expected_version' => [
                'required' => false,
                'type' => 'string',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/cache/static/purge', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_purge_static_cache',
        'permission_callback' => 'll_tools_rest_automation_require_static_cache_purge_access',
        'args' => [
            'cache' => [
                'required' => false,
                'type' => 'string',
                'default' => 'all',
            ],
        ],
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

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-title-updates', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_title_updates',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-helper-updates', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_helper_updates',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-category-updates', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_category_updates',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'operation' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['add_category', 'remove_category', 'move_category'],
            ],
            'target_category_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'category_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'word_ids' => [
                'required' => true,
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                ],
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'allow_empty_categories' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'sync_linked_images' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-metadata-plan-jobs', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_create_word_metadata_plan_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'updates' => [
                'required' => false,
                'type' => 'array',
            ],
            'plans' => [
                'required' => false,
                'type' => 'array',
            ],
            'allow_empty_categories' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'sync_linked_images' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
            'purge_public_static_cache' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-metadata-plan-jobs/(?P<job_id>[A-Za-z0-9_-]+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_get_word_metadata_plan_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-metadata-plan-jobs/(?P<job_id>[A-Za-z0-9_-]+)/process', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_process_word_metadata_plan_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'limit' => [
                'required' => false,
                'type' => 'integer',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-metadata-plan-jobs/(?P<job_id>[A-Za-z0-9_-]+)/discard', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_discard_word_metadata_plan_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-metadata-plan-jobs/(?P<job_id>[A-Za-z0-9_-]+)/result', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_word_metadata_plan_job_result',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcriptions', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_update_transcriptions',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcription-validations', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_refresh_transcription_validations',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'limit' => [
                'required' => false,
                'type' => 'integer',
                'default' => 1,
            ],
            'scan_limit' => [
                'required' => false,
                'type' => 'integer',
                'default' => 25,
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'stale_only' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcription-validation-jobs', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_create_transcription_validation_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'stale_only' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
            'max_candidates' => [
                'required' => false,
                'type' => 'integer',
            ],
            'candidate_scope' => [
                'required' => false,
                'type' => 'string',
                'default' => 'issues',
                'enum' => ['issues', 'all', 'issue', 'open_issues', 'recordings', 'all_recordings'],
            ],
            'auto_process' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'auto_delay_seconds' => [
                'required' => false,
                'type' => 'integer',
            ],
            'auto_limit' => [
                'required' => false,
                'type' => 'integer',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcription-validation-jobs/(?P<job_id>[A-Za-z0-9_-]+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_automation_get_transcription_validation_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/transcription-validation-jobs/(?P<job_id>[A-Za-z0-9_-]+)/process', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_process_transcription_validation_job',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'limit' => [
                'required' => false,
                'type' => 'integer',
            ],
            'auto_process' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'auto_delay_seconds' => [
                'required' => false,
                'type' => 'integer',
            ],
            'auto_limit' => [
                'required' => false,
                'type' => 'integer',
            ],
            'settings_only' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
            'profile' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/word-option-rules', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_rest_automation_word_option_rules',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/orthography-conversion', [
        'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE, WP_REST_Server::EDITABLE],
        'callback' => 'll_tools_rest_automation_orthography_conversion',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/profile', [
        'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE, WP_REST_Server::EDITABLE],
        'callback' => 'll_tools_rest_automation_wordset_profile',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'profile_image_attachment_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'button_image_attachment_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'thumbnail_attachment_id' => [
                'required' => false,
                'type' => 'integer',
            ],
            'profile_blurb' => [
                'required' => false,
                'type' => 'string',
            ],
            'intro_blurb' => [
                'required' => false,
                'type' => 'string',
            ],
            'blurb' => [
                'required' => false,
                'type' => 'string',
            ],
            'language_code' => [
                'required' => false,
                'type' => 'string',
            ],
            'target_language' => [
                'required' => false,
                'type' => 'string',
            ],
            'translation_language' => [
                'required' => false,
                'type' => 'string',
            ],
        ],
    ]);

    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/translations', [
        'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE, WP_REST_Server::EDITABLE],
        'callback' => 'll_tools_rest_automation_wordset_translations',
        'permission_callback' => 'll_tools_rest_automation_require_wordset_access',
        'args' => [
            'locale' => [
                'required' => false,
                'type' => 'string',
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'items' => [
                'required' => false,
            ],
            'wordset_translation' => [
                'required' => false,
            ],
            'categories' => [
                'required' => false,
            ],
            'lessons' => [
                'required' => false,
            ],
        ],
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

    register_rest_route('ll-tools/v1', '/corpus-texts/(?P<slug>[A-Za-z0-9_-]+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_rest_get_corpus_text',
        'permission_callback' => 'll_tools_rest_automation_require_import_access',
        'args' => [
            'slug' => [
                'required' => true,
                'type' => 'string',
            ],
            'include_payload' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ],
        ],
    ]);
}
add_action('rest_api_init', 'll_tools_rest_register_automation_routes');
