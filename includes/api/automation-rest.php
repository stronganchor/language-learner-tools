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
            'report' => '/ll-tools/v1/wordsets/{wordset}/report',
            'report_summary' => '/ll-tools/v1/wordsets/{wordset}/report-summary',
            'import_preview' => '/ll-tools/v1/imports/preview',
            'import_start' => '/ll-tools/v1/imports/start',
            'import_status' => '/ll-tools/v1/imports/{job_id}',
            'import_process' => '/ll-tools/v1/imports/{job_id}/process',
            'import_discard' => '/ll-tools/v1/imports/{job_id}/discard',
            'import_result' => '/ll-tools/v1/imports/{job_id}/result',
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
        ],
        'count' => count($rows),
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

    $offset = max(0, (int) $request->get_param('offset'));
    $limit = max(0, (int) $request->get_param('limit'));
    $rows = ll_tools_cli_slice_rows($rows, $offset, $limit);

    $dry_run = rest_sanitize_boolean($request->get_param('dry_run'));
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
        'set' => $set_args,
        'matched_count' => count($rows),
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
