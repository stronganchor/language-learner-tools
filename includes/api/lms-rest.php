<?php
/**
 * Native REST surface for server-authoritative LMS assignments and attempts.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_LMS_REST_BODY_MAX_BYTES')) {
    define('LL_TOOLS_LMS_REST_BODY_MAX_BYTES', 32 * 1024);
}

/** @return true|WP_Error */
function ll_tools_lms_rest_logged_in_permission() {
    if (!is_user_logged_in()) {
        return new WP_Error(
            'll_tools_lms_rest_auth_required',
            __('Sign in to use assignments.', 'll-tools-text-domain'),
            ['status' => 401]
        );
    }
    return true;
}

/** @return true|WP_Error */
function ll_tools_lms_rest_teacher_permission() {
    $logged_in = ll_tools_lms_rest_logged_in_permission();
    if (is_wp_error($logged_in)) {
        return $logged_in;
    }
    $user_id = get_current_user_id();
    if (
        !current_user_can('view_ll_tools')
        || !function_exists('ll_tools_user_can_manage_classes')
        || !ll_tools_user_can_manage_classes($user_id)
    ) {
        return new WP_Error(
            'll_tools_lms_rest_forbidden',
            __('You cannot manage assignments.', 'll-tools-text-domain'),
            ['status' => 403]
        );
    }
    return true;
}

/** @return array|WP_Error */
function ll_tools_lms_rest_json_params(WP_REST_Request $request, array $allowed_keys, bool $allow_empty = false) {
    $raw_body = (string) $request->get_body();
    if (strlen($raw_body) > LL_TOOLS_LMS_REST_BODY_MAX_BYTES) {
        return new WP_Error(
            'll_tools_lms_rest_body_too_large',
            __('The assignment request is too large.', 'll-tools-text-domain'),
            ['status' => 413]
        );
    }

    $params = $request->get_json_params();
    if ($params === null && $raw_body === '') {
        $params = [];
    }
    if (!is_array($params)) {
        return new WP_Error(
            'll_tools_lms_rest_invalid_json',
            __('The assignment request must be a JSON object.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }
    $encoded = wp_json_encode($params);
    if (!is_string($encoded) || strlen($encoded) > LL_TOOLS_LMS_REST_BODY_MAX_BYTES) {
        return new WP_Error(
            'll_tools_lms_rest_body_too_large',
            __('The assignment request is too large.', 'll-tools-text-domain'),
            ['status' => 413]
        );
    }
    if (!$allow_empty && $params === []) {
        return new WP_Error(
            'll_tools_lms_rest_body_required',
            __('The assignment request body is required.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }
    foreach (array_keys($params) as $key) {
        if (!is_string($key) || !in_array($key, $allowed_keys, true)) {
            return new WP_Error(
                'll_tools_lms_rest_unknown_field',
                __('The assignment request contains an unsupported field.', 'll-tools-text-domain'),
                ['status' => 400]
            );
        }
    }
    return $params;
}

function ll_tools_lms_rest_error_status(string $code): int {
    if (str_contains($code, 'schema_') || str_contains($code, 'transaction_') || str_contains($code, '_write_failed')) {
        return 503;
    }
    if (str_contains($code, 'forbidden') || str_contains($code, 'membership_required')) {
        return 403;
    }
    if (str_contains($code, 'not_found')) {
        return 404;
    }
    if (
        str_contains($code, 'already_')
        || str_contains($code, '_conflict')
        || str_contains($code, '_limit_')
        || str_contains($code, '_finalized')
        || str_contains($code, '_expired')
        || str_contains($code, '_closed')
        || $code === 'assignment_not_published'
        || $code === 'assignment_not_available'
        || $code === 'assignment_attempt_unavailable'
    ) {
        return 409;
    }
    return 400;
}

/** Add a deterministic REST status without replacing safe existing data. */
function ll_tools_lms_rest_prepare_error(WP_Error $error): WP_Error {
    $code = (string) $error->get_error_code();
    $data = $error->get_error_data($code);
    $data = is_array($data) ? $data : [];
    if (empty($data['status'])) {
        $data['status'] = ll_tools_lms_rest_error_status($code);
        $error->add_data($data, $code);
    }
    return $error;
}

function ll_tools_lms_rest_assignment_summary(array $assignment): array {
    return [
        'assignment_uuid' => (string) ($assignment['assignment_uuid'] ?? ''),
        'class_id' => max(0, (int) ($assignment['class_id'] ?? 0)),
        'wordset_id' => max(0, (int) ($assignment['wordset_id'] ?? 0)),
        'title' => (string) ($assignment['title'] ?? ''),
        'status' => (string) ($assignment['status'] ?? ''),
        'current_revision_id' => max(0, (int) ($assignment['current_revision_id'] ?? 0)),
        'created_at' => (string) ($assignment['created_at'] ?? ''),
        'updated_at' => (string) ($assignment['updated_at'] ?? ''),
    ];
}

/** @return array|WP_Error */
function ll_tools_lms_rest_assignment_from_route(WP_REST_Request $request) {
    $uuid = ll_tools_lms_assignment_normalize_uuid((string) $request['assignment_uuid']);
    $assignment = $uuid !== '' ? ll_tools_lms_assignment_get($uuid) : null;
    if (!is_array($assignment)) {
        return new WP_Error(
            'assignment_not_found',
            __('The assignment was not found.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }
    return $assignment;
}

function ll_tools_lms_rest_create_assignment(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, [
        'class_id', 'title', 'manifest', 'points_maximum', 'attempt_limit',
        'grade_policy', 'available_at', 'due_at',
    ]);
    if (is_wp_error($params)) {
        return $params;
    }
    $class_id = isset($params['class_id']) ? absint($params['class_id']) : 0;
    $created = ll_tools_lms_assignment_create($class_id, $params, get_current_user_id());
    if (is_wp_error($created)) {
        return ll_tools_lms_rest_prepare_error($created);
    }
    $assignment = ll_tools_lms_assignment_get((int) $created);
    if (!is_array($assignment)) {
        return new WP_Error(
            'assignment_write_failed',
            __('The assignment could not be read after it was saved.', 'll-tools-text-domain'),
            ['status' => 503]
        );
    }
    $response = new WP_REST_Response(['assignment' => ll_tools_lms_rest_assignment_summary($assignment)], 201);
    return $response;
}

function ll_tools_lms_rest_list_class_assignments(WP_REST_Request $request) {
    $class_id = absint($request['class_id']);
    $per_page = max(1, min(100, absint($request->get_param('per_page') ?: 20)));
    $after_id = max(0, absint($request->get_param('after_id')));
    $status = sanitize_key((string) $request->get_param('status'));
    $rows = ll_tools_lms_assignments_for_class($class_id, get_current_user_id(), [
        'number' => $per_page + 1,
        'after_id' => $after_id,
        'status' => $status,
    ]);
    if (is_wp_error($rows)) {
        return ll_tools_lms_rest_prepare_error($rows);
    }
    $has_more = count($rows) > $per_page;
    $rows = array_slice($rows, 0, $per_page);
    $items = array_map('ll_tools_lms_rest_assignment_summary', $rows);
    $next_after_id = $after_id;
    foreach ($rows as $row) {
        $next_after_id = max($next_after_id, (int) ($row['id'] ?? 0));
    }
    return rest_ensure_response([
        'items' => $items,
        'has_more' => $has_more,
        'next_after_id' => $next_after_id,
    ]);
}

function ll_tools_lms_rest_publish_assignment(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, [], true);
    if (is_wp_error($params)) {
        return $params;
    }
    if ($params !== []) {
        return new WP_Error('ll_tools_lms_rest_unknown_field', __('The publish request must be empty.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $assignment = ll_tools_lms_rest_assignment_from_route($request);
    if (is_wp_error($assignment)) {
        return $assignment;
    }
    $published = ll_tools_lms_assignment_publish((int) $assignment['id'], get_current_user_id());
    if (is_wp_error($published)) {
        return ll_tools_lms_rest_prepare_error($published);
    }
    $assignment = ll_tools_lms_assignment_get((int) $assignment['id']);
    return rest_ensure_response(['assignment' => ll_tools_lms_rest_assignment_summary((array) $assignment)]);
}

function ll_tools_lms_rest_archive_assignment(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, [], true);
    if (is_wp_error($params)) {
        return $params;
    }
    if ($params !== []) {
        return new WP_Error('ll_tools_lms_rest_unknown_field', __('The archive request must be empty.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $assignment = ll_tools_lms_rest_assignment_from_route($request);
    if (is_wp_error($assignment)) {
        return $assignment;
    }
    $archived = ll_tools_lms_assignment_archive((int) $assignment['id'], get_current_user_id());
    if (is_wp_error($archived)) {
        return ll_tools_lms_rest_prepare_error($archived);
    }
    $assignment = ll_tools_lms_assignment_get((int) $assignment['id']);
    return rest_ensure_response(['assignment' => ll_tools_lms_rest_assignment_summary((array) $assignment)]);
}

function ll_tools_lms_rest_start_attempt(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, [], true);
    if (is_wp_error($params)) {
        return $params;
    }
    if ($params !== []) {
        return new WP_Error('ll_tools_lms_rest_unknown_field', __('The attempt start request must be empty.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $result = ll_tools_lms_assignment_start_attempt((string) $request['assignment_uuid'], get_current_user_id());
    if (is_wp_error($result)) {
        return ll_tools_lms_rest_prepare_error($result);
    }
    return new WP_REST_Response($result, 201);
}

function ll_tools_lms_rest_submit_answer(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, ['answer_uuid', 'item_key', 'option_key']);
    if (is_wp_error($params)) {
        return $params;
    }
    foreach (['answer_uuid', 'item_key', 'option_key'] as $required) {
        if (!array_key_exists($required, $params) || !is_string($params[$required])) {
            return new WP_Error('invalid_assignment_answer', __('The assignment answer is invalid.', 'll-tools-text-domain'), ['status' => 400]);
        }
    }
    $result = ll_tools_lms_assignment_submit_answer(
        (string) $request['attempt_uuid'],
        $params['answer_uuid'],
        $params['item_key'],
        $params['option_key'],
        get_current_user_id()
    );
    return is_wp_error($result) ? ll_tools_lms_rest_prepare_error($result) : rest_ensure_response($result);
}

function ll_tools_lms_rest_finalize_attempt(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, [], true);
    if (is_wp_error($params)) {
        return $params;
    }
    if ($params !== []) {
        return new WP_Error(
            'll_tools_lms_rest_score_rejected',
            __('The server calculates the assignment score; the finalize request must be empty.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }
    $result = ll_tools_lms_assignment_finalize_attempt((string) $request['attempt_uuid'], get_current_user_id());
    return is_wp_error($result) ? ll_tools_lms_rest_prepare_error($result) : rest_ensure_response($result);
}

function ll_tools_lms_rest_get_attempt(WP_REST_Request $request) {
    $attempt = ll_tools_lms_assignment_get_attempt((string) $request['attempt_uuid']);
    if (!is_array($attempt) || (int) ($attempt['user_id'] ?? 0) !== get_current_user_id()) {
        return new WP_Error(
            'assignment_attempt_not_found',
            __('The assignment attempt was not found.', 'll-tools-text-domain'),
            ['status' => 404]
        );
    }
    $grade = ll_tools_lms_assignment_get_grade(
        (int) $attempt['assignment_id'],
        (int) $attempt['revision_id'],
        get_current_user_id()
    );
    return rest_ensure_response([
        'attempt' => ll_tools_lms_assignment_public_attempt($attempt),
        'grade' => ll_tools_lms_assignment_public_grade($grade),
    ]);
}

function ll_tools_register_lms_rest_routes(): void {
    $uuid_pattern = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';
    register_rest_route('ll-tools/v1', '/lms/assignments', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_create_assignment',
        'permission_callback' => 'll_tools_lms_rest_teacher_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/classes/(?P<class_id>\d+)/assignments', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_lms_rest_list_class_assignments',
        'permission_callback' => 'll_tools_lms_rest_teacher_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/assignments/(?P<assignment_uuid>' . $uuid_pattern . ')/publish', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_publish_assignment',
        'permission_callback' => 'll_tools_lms_rest_teacher_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/assignments/(?P<assignment_uuid>' . $uuid_pattern . ')/archive', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_archive_assignment',
        'permission_callback' => 'll_tools_lms_rest_teacher_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/assignments/(?P<assignment_uuid>' . $uuid_pattern . ')/attempts', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_start_attempt',
        'permission_callback' => 'll_tools_lms_rest_logged_in_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/attempts/(?P<attempt_uuid>' . $uuid_pattern . ')/answers', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_submit_answer',
        'permission_callback' => 'll_tools_lms_rest_logged_in_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/attempts/(?P<attempt_uuid>' . $uuid_pattern . ')/finalize', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'll_tools_lms_rest_finalize_attempt',
        'permission_callback' => 'll_tools_lms_rest_logged_in_permission',
    ]);
    register_rest_route('ll-tools/v1', '/lms/attempts/(?P<attempt_uuid>' . $uuid_pattern . ')', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_lms_rest_get_attempt',
        'permission_callback' => 'll_tools_lms_rest_logged_in_permission',
    ]);
}
add_action('rest_api_init', 'll_tools_register_lms_rest_routes');
