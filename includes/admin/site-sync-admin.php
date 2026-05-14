<?php
if (!defined('WPINC')) { die; }

function ll_tools_site_sync_get_admin_page_slug(): string {
    return 'll-site-sync';
}

function ll_tools_site_sync_connection_option_name(): string {
    return 'll_tools_site_sync_connection';
}

function ll_tools_site_sync_state_option_name(): string {
    return 'll_tools_site_sync_state';
}

function ll_tools_site_sync_get_admin_page_url(array $args = []): string {
    $query_args = array_merge(['page' => ll_tools_site_sync_get_admin_page_slug()], $args);
    return (string) add_query_arg($query_args, admin_url('tools.php'));
}

function ll_tools_site_sync_get_saved_connection(): array {
    $raw = get_option(ll_tools_site_sync_connection_option_name(), []);
    return ll_tools_site_sync_sanitize_connection(is_array($raw) ? $raw : []);
}

function ll_tools_site_sync_sanitize_connection(array $raw): array {
    $remote_url = isset($raw['remote_url']) ? esc_url_raw(trim((string) $raw['remote_url'])) : '';
    $remote_url = untrailingslashit($remote_url);

    return [
        'local_wordset_id' => isset($raw['local_wordset_id']) ? absint($raw['local_wordset_id']) : 0,
        'remote_url' => $remote_url,
        'remote_wordset' => isset($raw['remote_wordset']) ? sanitize_text_field((string) $raw['remote_wordset']) : '',
        'remote_username' => isset($raw['remote_username']) ? sanitize_text_field((string) $raw['remote_username']) : '',
        'surface' => ll_tools_site_sync_normalize_surface((string) ($raw['surface'] ?? 'transcriptions')),
    ];
}

function ll_tools_site_sync_connection_from_request(array $fallback): array {
    $raw = [
        'local_wordset_id' => isset($_POST['ll_site_sync_local_wordset_id'])
            ? wp_unslash((string) $_POST['ll_site_sync_local_wordset_id'])
            : ($fallback['local_wordset_id'] ?? 0),
        'remote_url' => isset($_POST['ll_site_sync_remote_url'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_url'])
            : ($fallback['remote_url'] ?? ''),
        'remote_wordset' => isset($_POST['ll_site_sync_remote_wordset'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_wordset'])
            : ($fallback['remote_wordset'] ?? ''),
        'remote_username' => isset($_POST['ll_site_sync_remote_username'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_username'])
            : ($fallback['remote_username'] ?? ''),
        'surface' => isset($_POST['ll_site_sync_surface'])
            ? wp_unslash((string) $_POST['ll_site_sync_surface'])
            : ($fallback['surface'] ?? 'transcriptions'),
    ];

    return ll_tools_site_sync_sanitize_connection($raw);
}

function ll_tools_site_sync_connection_key(array $connection): string {
    $parts = [
        (string) ($connection['remote_url'] ?? ''),
        (string) ($connection['remote_wordset'] ?? ''),
        (string) ($connection['local_wordset_id'] ?? 0),
        ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions')),
    ];
    return md5(implode('|', $parts));
}

function ll_tools_site_sync_get_state(): array {
    $state = get_option(ll_tools_site_sync_state_option_name(), []);
    return is_array($state) ? $state : [];
}

function ll_tools_site_sync_get_base_snapshot(array $connection): array {
    $state = ll_tools_site_sync_get_state();
    $key = ll_tools_site_sync_connection_key($connection);
    $snapshot = $state[$key]['base_snapshot'] ?? [];
    return is_array($snapshot) ? $snapshot : [];
}

function ll_tools_site_sync_save_base_snapshot(array $connection, array $snapshot): void {
    $state = ll_tools_site_sync_get_state();
    $key = ll_tools_site_sync_connection_key($connection);
    $snapshot = ll_tools_site_sync_compact_base_snapshot($snapshot);
    $state[$key] = [
        'updated_at_gmt' => gmdate('c'),
        'connection' => ll_tools_site_sync_sanitize_connection($connection),
        'base_snapshot' => $snapshot,
    ];
    update_option(ll_tools_site_sync_state_option_name(), $state, false);
}

function ll_tools_site_sync_validate_connection(array $connection, string $password = '') {
    if ((int) ($connection['local_wordset_id'] ?? 0) <= 0) {
        return new WP_Error('ll_tools_site_sync_missing_local_wordset', __('Select the local staging word set.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_url'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_remote_url', __('Enter the live or remote site URL.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_wordset'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_remote_wordset', __('Enter the remote word set slug or ID.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_username'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_username', __('Enter the remote WordPress username.', 'll-tools-text-domain'));
    }

    if ($password === '') {
        return new WP_Error('ll_tools_site_sync_missing_password', __('Enter the remote WordPress password or application password for this operation.', 'll-tools-text-domain'));
    }

    $scheme = strtolower((string) wp_parse_url((string) $connection['remote_url'], PHP_URL_SCHEME));
    $host = strtolower((string) wp_parse_url((string) $connection['remote_url'], PHP_URL_HOST));
    $is_local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || preg_match('/\.(local|test)$/', $host);
    $allowed_insecure = (bool) apply_filters('ll_tools_site_sync_allow_insecure_remote', false, $connection);
    if ($scheme !== 'https' && !$is_local && !$allowed_insecure) {
        return new WP_Error('ll_tools_site_sync_https_required', __('Remote password authentication is only allowed over HTTPS unless the remote host is local development.', 'll-tools-text-domain'));
    }

    return true;
}

function ll_tools_site_sync_remote_request(array $connection, string $method, string $route, string $password, array $body = []) {
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $remote_url = untrailingslashit((string) $connection['remote_url']);
    $route = '/' . ltrim($route, '/');
    $url = $remote_url . '/wp-json/ll-tools/v1' . $route;

    $args = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode((string) $connection['remote_username'] . ':' . $password),
            'Accept' => 'application/json',
        ],
    ];

    if (!empty($body)) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw_body, true);
    if (!is_array($data)) {
        return new WP_Error(
            'll_tools_site_sync_bad_remote_response',
            __('The remote site did not return a valid JSON response.', 'll-tools-text-domain'),
            ['status' => $code, 'body' => $raw_body]
        );
    }

    if ($code < 200 || $code >= 300) {
        $message = isset($data['message']) ? (string) $data['message'] : __('The remote site returned an error.', 'll-tools-text-domain');
        return new WP_Error('ll_tools_site_sync_remote_error', $message, ['status' => $code, 'response' => $data]);
    }

    return $data;
}

function ll_tools_site_sync_remote_error_is_retryable(WP_Error $error): bool {
    $data = (array) $error->get_error_data();
    $status = (int) ($data['status'] ?? 0);
    if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
        return true;
    }

    $message = $error->get_error_message();
    foreach (['already processing', 'cURL error 28', 'timed out', 'timeout'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function ll_tools_site_sync_remote_request_with_retry(
    array $connection,
    string $method,
    string $route,
    string $password,
    array $body = [],
    int $max_attempts = 5
) {
    $attempt = 0;
    $delays = [2, 4, 8, 12];

    do {
        $attempt++;
        $result = ll_tools_site_sync_remote_request($connection, $method, $route, $password, $body);
        if (!is_wp_error($result)) {
            return $result;
        }

        if ($attempt >= $max_attempts || !ll_tools_site_sync_remote_error_is_retryable($result)) {
            return $result;
        }

        sleep((int) ($delays[$attempt - 1] ?? 12));
    } while ($attempt < $max_attempts);

    return $result;
}

function ll_tools_site_sync_remote_snapshot_per_page(): int {
    $per_page = (int) apply_filters('ll_tools_site_sync_remote_snapshot_per_page', 100);
    return max(1, min(250, $per_page));
}

function ll_tools_site_sync_remote_update_batch_size(): int {
    $batch_size = (int) apply_filters('ll_tools_site_sync_remote_update_batch_size', 10);
    return max(1, min(50, $batch_size));
}

function ll_tools_site_sync_remote_apply_update_limit(): int {
    $limit = (int) apply_filters('ll_tools_site_sync_remote_apply_update_limit', 100);
    return max(1, min(100, $limit));
}

function ll_tools_site_sync_fetch_remote_snapshot_page(array $connection, string $password, bool $include_media, int $offset = 0) {
    $remote_wordset = rawurlencode((string) $connection['remote_wordset']);
    $surface = ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions'));
    $route = '/wordsets/' . $remote_wordset . '/site-sync/snapshot?' . http_build_query([
        'surface' => $surface,
        'ensure_sync_ids' => 1,
        'include_media' => $include_media ? 1 : 0,
        'per_page' => ll_tools_site_sync_remote_snapshot_per_page(),
        'offset' => max(0, $offset),
    ]);

    return ll_tools_site_sync_remote_request_with_retry($connection, 'GET', $route, $password);
}

function ll_tools_site_sync_fetch_remote_snapshot(array $connection, string $password, bool $include_media = true) {
    $snapshot = ll_tools_site_sync_fetch_remote_snapshot_page($connection, $password, $include_media, 0);
    if (is_wp_error($snapshot)) {
        return $snapshot;
    }

    $pagination = (array) ($snapshot['pagination'] ?? []);
    if (empty($pagination)) {
        return $snapshot;
    }

    $records = array_values((array) ($snapshot['records'] ?? []));
    $next_offset = isset($pagination['next_offset']) ? (int) $pagination['next_offset'] : null;
    while (!empty($pagination['has_more']) && $next_offset !== null) {
        $page = ll_tools_site_sync_fetch_remote_snapshot_page($connection, $password, $include_media, $next_offset);
        if (is_wp_error($page)) {
            return $page;
        }

        $records = array_merge($records, array_values((array) ($page['records'] ?? [])));
        $pagination = (array) ($page['pagination'] ?? []);
        $next_offset = isset($pagination['next_offset']) ? (int) $pagination['next_offset'] : null;
        if (empty($pagination)) {
            break;
        }
    }

    $snapshot['records'] = $records;
    $snapshot['record_count'] = isset($pagination['total_count']) ? (int) $pagination['total_count'] : count($records);
    $snapshot['records_returned'] = count($records);
    $snapshot['pagination'] = [
        'limit' => ll_tools_site_sync_remote_snapshot_per_page(),
        'offset' => 0,
        'returned_count' => count($records),
        'total_count' => (int) ($snapshot['record_count'] ?? count($records)),
        'has_more' => false,
        'next_offset' => null,
    ];

    return $snapshot;
}

function ll_tools_site_sync_send_remote_transcription_updates(array $connection, string $password, array $updates) {
    $updates = array_values(array_filter($updates, 'is_array'));
    if (empty($updates)) {
        return [
            'matched_count' => 0,
            'updated_count' => 0,
            'updated' => [],
            'errors' => [],
        ];
    }

    $remote_wordset = rawurlencode((string) $connection['remote_wordset']);
    $batch_size = ll_tools_site_sync_remote_update_batch_size();
    $summary = [
        'matched_count' => 0,
        'updated_count' => 0,
        'updated' => [],
        'errors' => [],
        'batch' => [
            'batch_size' => $batch_size,
            'request_count' => 0,
            'sent_count' => count($updates),
        ],
    ];

    foreach (array_chunk($updates, $batch_size) as $chunk) {
        $response = ll_tools_site_sync_remote_request_with_retry(
            $connection,
            'POST',
            '/wordsets/' . $remote_wordset . '/transcriptions',
            $password,
            ['updates' => $chunk]
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $summary['matched_count'] += (int) ($response['matched_count'] ?? 0);
        $summary['updated_count'] += (int) ($response['updated_count'] ?? 0);
        $summary['updated'] = array_merge($summary['updated'], array_values((array) ($response['updated'] ?? [])));
        $summary['errors'] = array_merge($summary['errors'], array_values((array) ($response['errors'] ?? [])));
        $summary['batch']['request_count']++;
        usleep(300000);
    }

    return $summary;
}

function ll_tools_site_sync_cached_preview_transient_key(array $connection): string {
    return 'll_site_sync_preview_' . get_current_user_id() . '_' . ll_tools_site_sync_connection_key($connection);
}

function ll_tools_site_sync_strip_preview_media($value) {
    if (!is_array($value)) {
        return $value;
    }

    unset($value['media']);
    foreach ($value as $key => $item) {
        $value[$key] = ll_tools_site_sync_strip_preview_media($item);
    }

    return $value;
}

function ll_tools_site_sync_save_cached_preview_plan(array $connection, array $plan): void {
    set_transient(
        ll_tools_site_sync_cached_preview_transient_key($connection),
        [
            'connection_key' => ll_tools_site_sync_connection_key($connection),
            'plan' => ll_tools_site_sync_strip_preview_media($plan),
            'created_at_gmt' => gmdate('c'),
        ],
        15 * MINUTE_IN_SECONDS
    );
}

function ll_tools_site_sync_get_cached_preview_plan(array $connection): ?array {
    $cached = get_transient(ll_tools_site_sync_cached_preview_transient_key($connection));
    if (!is_array($cached)) {
        return null;
    }

    if ((string) ($cached['connection_key'] ?? '') !== ll_tools_site_sync_connection_key($connection)) {
        return null;
    }

    $plan = $cached['plan'] ?? null;
    return is_array($plan) ? $plan : null;
}

function ll_tools_site_sync_delete_cached_preview_plan(array $connection): void {
    delete_transient(ll_tools_site_sync_cached_preview_transient_key($connection));
}

function ll_tools_site_sync_value_fields_from_request(): array {
    $raw_json = isset($_POST['ll_site_sync_fields_json'])
        ? (string) wp_unslash($_POST['ll_site_sync_fields_json'])
        : '';
    $decoded = $raw_json !== '' ? json_decode($raw_json, true) : [];
    $fields = is_array($decoded) ? $decoded : [];
    $allowed = array_fill_keys(ll_tools_site_sync_transcription_value_keys(), true);
    $normalized = [];

    foreach ($fields as $field) {
        $field = sanitize_key((string) $field);
        if (isset($allowed[$field])) {
            $normalized[$field] = true;
        }
    }

    return array_keys($normalized);
}

function ll_tools_site_sync_recording_belongs_to_wordset(int $recording_id, int $wordset_id): bool {
    if ($recording_id <= 0 || $wordset_id <= 0 || get_post_type($recording_id) !== 'word_audio') {
        return false;
    }

    $word_id = (int) wp_get_post_parent_id($recording_id);
    return $word_id > 0 && has_term($wordset_id, 'wordset', $word_id);
}

function ll_tools_site_sync_values_from_json_request(string $key): array {
    $raw_json = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
    $decoded = $raw_json !== '' ? json_decode($raw_json, true) : [];
    return is_array($decoded) ? $decoded : [];
}

function ll_tools_site_sync_submitted_edit_values(array $fields, array $current_values): array {
    $values = $current_values;
    $raw_after_values = isset($_POST['ll_site_sync_after_values'])
        ? (array) wp_unslash($_POST['ll_site_sync_after_values'])
        : [];

    foreach ($fields as $field) {
        if (in_array($field, ['recording_text', 'recording_ipa', 'review_note'], true)) {
            $values[$field] = isset($raw_after_values[$field]) ? (string) $raw_after_values[$field] : '';
            continue;
        }

        if ($field === 'needs_review') {
            $values[$field] = !empty($_POST['ll_site_sync_needs_review']);
            continue;
        }

        if ($field === 'review_fields') {
            $raw_review_fields = isset($_POST['ll_site_sync_review_fields'])
                ? (array) wp_unslash($_POST['ll_site_sync_review_fields'])
                : [];
            $values[$field] = ll_tools_site_sync_normalize_review_fields($raw_review_fields);
        }
    }

    return $values;
}

function ll_tools_site_sync_process_local_change_request(array $connection, string $action) {
    $recording_id = isset($_POST['ll_site_sync_recording_id']) ? absint(wp_unslash((string) $_POST['ll_site_sync_recording_id'])) : 0;
    $wordset_id = (int) ($connection['local_wordset_id'] ?? 0);
    if (!ll_tools_site_sync_recording_belongs_to_wordset($recording_id, $wordset_id)) {
        return new WP_Error('ll_tools_site_sync_invalid_local_recording', __('The selected local recording is not part of the configured staging word set.', 'll-tools-text-domain'));
    }

    $fields = ll_tools_site_sync_value_fields_from_request();
    if (empty($fields)) {
        return new WP_Error('ll_tools_site_sync_no_local_fields', __('No editable local sync fields were submitted.', 'll-tools-text-domain'));
    }

    $current_values = ll_tools_site_sync_record_values($recording_id, $wordset_id);
    if ($action === 'revert_local_change') {
        $before_values = ll_tools_site_sync_values_from_json_request('ll_site_sync_before_values_json');
        $next_values = $current_values;
        foreach ($fields as $field) {
            if (array_key_exists($field, $before_values)) {
                $next_values[$field] = $before_values[$field];
            }
        }
    } else {
        $next_values = ll_tools_site_sync_submitted_edit_values($fields, $current_values);
    }

    ll_tools_site_sync_apply_record_values($recording_id, $wordset_id, $next_values);

    return [
        'recording_id' => $recording_id,
        'fields_updated' => count($fields),
    ];
}

function ll_tools_site_sync_conflict_mode_from_request(): string {
    $mode = isset($_POST['ll_site_sync_conflict_mode'])
        ? sanitize_key((string) wp_unslash($_POST['ll_site_sync_conflict_mode']))
        : '';
    if (in_array($mode, ['flag', 'skip', 'accept_live', 'push_local'], true)) {
        return $mode;
    }

    return !empty($_POST['ll_site_sync_flag_conflicts']) ? 'flag' : 'skip';
}

function ll_tools_site_sync_merge_remote_update(array $current, array $next): array {
    foreach ($next as $key => $value) {
        $current[$key] = $key === 'recording_id' ? (int) $value : $value;
    }

    return $current;
}

function ll_tools_site_sync_push_local_conflict_updates(array $plan): array {
    $updates_by_recording = [];

    foreach ((array) ($plan['remote_updates'] ?? []) as $update) {
        if (!is_array($update)) {
            continue;
        }

        $recording_id = (int) ($update['recording_id'] ?? 0);
        if ($recording_id <= 0) {
            continue;
        }

        $updates_by_recording[$recording_id] = ll_tools_site_sync_merge_remote_update(
            (array) ($updates_by_recording[$recording_id] ?? ['recording_id' => $recording_id]),
            $update
        );
    }

    foreach ((array) ($plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }

        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $local_record = (array) ($conflict['local_record'] ?? []);
        $recording_id = (int) (($remote_record['recording'] ?? [])['id'] ?? 0);
        $field = sanitize_key((string) ($conflict['field'] ?? ''));
        if ($recording_id <= 0 || $field === '') {
            continue;
        }

        $local_values = ll_tools_site_sync_normalize_record_values((array) ($local_record['values'] ?? []));
        $update = (array) ($updates_by_recording[$recording_id] ?? ['recording_id' => $recording_id]);
        if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
            $update[$field] = (string) ($local_values[$field] ?? '');
        } elseif (in_array($field, ['needs_review', 'review_fields', 'review_note'], true)) {
            $update['needs_review'] = (bool) ($local_values['needs_review'] ?? false);
            $update['review_fields'] = array_values((array) ($local_values['review_fields'] ?? []));
            $update['review_note'] = (string) ($local_values['review_note'] ?? '');
        }

        $updates_by_recording[$recording_id] = $update;
    }

    return array_values($updates_by_recording);
}

function ll_tools_site_sync_accept_live_base_updates(array $plan): array {
    $updates_by_recording = [];
    $allowed = array_fill_keys(ll_tools_site_sync_transcription_value_keys(), true);

    foreach ((array) ($plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }

        $field = sanitize_key((string) ($conflict['field'] ?? ''));
        if (!isset($allowed[$field])) {
            continue;
        }

        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $recording_id = (int) (($remote_record['recording'] ?? [])['id'] ?? 0);
        if ($recording_id <= 0) {
            continue;
        }

        $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
        $update = (array) ($updates_by_recording[$recording_id] ?? ['recording_id' => $recording_id]);
        $update[$field] = $remote_values[$field] ?? ($conflict['remote_value'] ?? '');
        $updates_by_recording[$recording_id] = $update;
    }

    return array_values($updates_by_recording);
}

function ll_tools_site_sync_accept_live_conflicts_locally(array $plan, int $wordset_id): array {
    $updates_by_recording = [];
    $allowed = array_fill_keys(ll_tools_site_sync_transcription_value_keys(), true);

    foreach ((array) ($plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }

        $field = sanitize_key((string) ($conflict['field'] ?? ''));
        if (!isset($allowed[$field])) {
            continue;
        }

        $local_record = (array) ($conflict['local_record'] ?? []);
        $recording_id = (int) (($local_record['recording'] ?? [])['id'] ?? 0);
        if (!ll_tools_site_sync_recording_belongs_to_wordset($recording_id, $wordset_id)) {
            continue;
        }

        if (!isset($updates_by_recording[$recording_id])) {
            $updates_by_recording[$recording_id] = [];
        }
        $updates_by_recording[$recording_id][$field] = $conflict['remote_value'] ?? '';
    }

    $fields_updated = 0;
    $recordings_updated = 0;
    foreach ($updates_by_recording as $recording_id => $field_values) {
        $current_values = ll_tools_site_sync_record_values((int) $recording_id, $wordset_id);
        foreach ($field_values as $field => $value) {
            $current_values[$field] = $value;
            $fields_updated++;
        }
        ll_tools_site_sync_apply_record_values((int) $recording_id, $wordset_id, $current_values);
        $recordings_updated++;
    }

    return [
        'fields_updated' => $fields_updated,
        'recordings_updated' => $recordings_updated,
    ];
}

function ll_tools_site_sync_register_admin_page(): void {
    $capability = ll_tools_site_sync_capability();
    add_management_page(
        __('LL Site Sync', 'll-tools-text-domain'),
        __('LL Site Sync', 'll-tools-text-domain'),
        $capability,
        ll_tools_site_sync_get_admin_page_slug(),
        'll_tools_site_sync_render_admin_page'
    );
}
add_action('admin_menu', 'll_tools_site_sync_register_admin_page');

function ll_tools_site_sync_enqueue_admin_assets($hook): void {
    if ((string) $hook !== 'tools_page_' . ll_tools_site_sync_get_admin_page_slug()) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/site-sync-admin.css', 'll-tools-site-sync-admin', [], false);
    ll_enqueue_asset_by_timestamp('/js/site-sync-admin.js', 'll-tools-site-sync-admin-js', [], true);
    wp_localize_script('ll-tools-site-sync-admin-js', 'llToolsSiteSyncAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'localOverviewNonce' => wp_create_nonce('ll_tools_site_sync_local_overview'),
        'applyPushNonce' => wp_create_nonce('ll_tools_site_sync_apply_push'),
        'strings' => [
            'loadingOverview' => __('Checking local changes in the background...', 'll-tools-text-domain'),
            'overviewFailed' => __('Local change overview could not load.', 'll-tools-text-domain'),
            'retry' => __('Retry', 'll-tools-text-domain'),
            'applyPasswordRequired' => __('Enter the remote password before applying the push.', 'll-tools-text-domain'),
            'applyStarting' => __('Starting push batches...', 'll-tools-text-domain'),
            'applyRunning' => __('Applying push batches. Keep this tab open.', 'll-tools-text-domain'),
            'applyDone' => __('Push batches finished.', 'll-tools-text-domain'),
            'applyFailed' => __('Push batches stopped before finishing.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_site_sync_enqueue_admin_assets');

function ll_tools_site_sync_ajax_local_overview(): void {
    if (!current_user_can(ll_tools_site_sync_capability())) {
        wp_send_json_error([
            'message' => __('You do not have permission to use site sync.', 'll-tools-text-domain'),
        ], 403);
    }

    check_ajax_referer('ll_tools_site_sync_local_overview', 'nonce');

    $connection = ll_tools_site_sync_get_saved_connection();
    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);
    $per_page = ll_tools_site_sync_local_overview_per_page();
    $page = ll_tools_site_sync_positive_int_from_request('page', 1, 'post');
    $summary = ll_tools_site_sync_build_local_change_summary($connection, $base_snapshot, $per_page, ($page - 1) * $per_page);

    ob_start();
    ll_tools_site_sync_render_local_change_overview($summary);
    $html = (string) ob_get_clean();

    wp_send_json_success([
        'html' => $html,
    ]);
}
add_action('wp_ajax_ll_tools_site_sync_local_overview', 'll_tools_site_sync_ajax_local_overview');

function ll_tools_site_sync_apply_push_batch(array $connection, string $password, string $conflict_mode): array {
    $result = [
        'errors' => [],
        'notices' => [],
        'plan' => null,
        'remote_result' => null,
        'conflict_result' => null,
        'accept_live_result' => null,
        'progress' => [
            'sent_remote_updates' => 0,
            'total_remote_updates' => 0,
            'remaining_remote_updates_before_refresh' => 0,
            'sent_conflict_review_updates' => 0,
            'total_conflict_review_updates' => 0,
            'remaining_conflict_review_updates_before_refresh' => 0,
            'next_remote_updates' => 0,
            'next_conflict_review_updates' => 0,
            'next_conflicts' => 0,
            'next_skipped' => 0,
            'done' => false,
        ],
    ];

    $local_snapshot = ll_tools_site_sync_build_snapshot(
        (int) $connection['local_wordset_id'],
        (string) $connection['surface'],
        true,
        ['include_media' => false]
    );
    if (is_wp_error($local_snapshot)) {
        $result['errors'][] = $local_snapshot->get_error_message();
        return $result;
    }

    $remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password, false);
    if (is_wp_error($remote_snapshot)) {
        $result['errors'][] = $remote_snapshot->get_error_message();
        return $result;
    }

    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);
    $plan = ll_tools_site_sync_build_push_plan($local_snapshot, $remote_snapshot, $base_snapshot);
    $result['plan'] = $plan;

    $apply_limit = ll_tools_site_sync_remote_apply_update_limit();
    $remote_updates = $conflict_mode === 'push_local'
        ? ll_tools_site_sync_push_local_conflict_updates($plan)
        : array_values((array) ($plan['remote_updates'] ?? []));
    $remote_updates_to_send = array_slice($remote_updates, 0, $apply_limit);
    $remaining_remote_updates = max(0, count($remote_updates) - count($remote_updates_to_send));

    $remote_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, $remote_updates_to_send);
    if (is_wp_error($remote_result)) {
        $result['errors'][] = $remote_result->get_error_message();
        return $result;
    }
    $result['remote_result'] = $remote_result;
    $result['progress']['sent_remote_updates'] = count($remote_updates_to_send);
    $result['progress']['total_remote_updates'] = count($remote_updates);
    $result['progress']['remaining_remote_updates_before_refresh'] = $remaining_remote_updates;

    $flag_conflicts = $conflict_mode === 'flag';
    $conflict_review_updates = array_values((array) ($plan['conflict_review_updates'] ?? []));
    $remaining_capacity = max(0, $apply_limit - count($remote_updates_to_send));
    $conflict_review_updates_to_send = $flag_conflicts && $remaining_capacity > 0
        ? array_slice($conflict_review_updates, 0, $remaining_capacity)
        : [];
    $remaining_conflict_review_updates = $flag_conflicts
        ? max(0, count($conflict_review_updates) - count($conflict_review_updates_to_send))
        : 0;
    if (!empty($conflict_review_updates_to_send)) {
        $conflict_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, $conflict_review_updates_to_send);
        if (is_wp_error($conflict_result)) {
            $result['errors'][] = $conflict_result->get_error_message();
            return $result;
        }
        $result['conflict_result'] = $conflict_result;
    }
    $result['progress']['sent_conflict_review_updates'] = count($conflict_review_updates_to_send);
    $result['progress']['total_conflict_review_updates'] = $flag_conflicts ? count($conflict_review_updates) : 0;
    $result['progress']['remaining_conflict_review_updates_before_refresh'] = $remaining_conflict_review_updates;

    if ($conflict_mode === 'accept_live' && !empty($plan['conflicts'])) {
        $accept_live_result = ll_tools_site_sync_accept_live_conflicts_locally($plan, (int) $connection['local_wordset_id']);
        $result['accept_live_result'] = $accept_live_result;
        $result['notices'][] = sprintf(
            /* translators: 1: updated local field count, 2: updated local recording count */
            __('Accepted live values locally for %1$d conflicted field(s) across %2$d recording(s).', 'll-tools-text-domain'),
            (int) ($accept_live_result['fields_updated'] ?? 0),
            (int) ($accept_live_result['recordings_updated'] ?? 0)
        );

        $local_snapshot = ll_tools_site_sync_build_snapshot(
            (int) $connection['local_wordset_id'],
            (string) $connection['surface'],
            true,
            ['include_media' => false]
        );
        if (is_wp_error($local_snapshot)) {
            $result['errors'][] = $local_snapshot->get_error_message();
            return $result;
        }
    } elseif ($conflict_mode === 'skip' && !empty($plan['conflicts'])) {
        $result['notices'][] = __('Skipped conflict handling for this push. Conflicted fields were not changed locally or on the live site.', 'll-tools-text-domain');
    }

    $fresh_remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password, false);
    if (is_wp_error($fresh_remote_snapshot)) {
        $result['errors'][] = $fresh_remote_snapshot->get_error_message();
        return $result;
    }

    $base_updates = $remote_updates_to_send;
    if ($conflict_mode === 'accept_live' && !empty($plan['conflicts'])) {
        $base_updates = array_merge($base_updates, ll_tools_site_sync_accept_live_base_updates($plan));
    }
    $merged_base = ll_tools_site_sync_merge_base_snapshot_after_push($base_snapshot, $fresh_remote_snapshot, $base_updates);
    ll_tools_site_sync_save_base_snapshot($connection, $merged_base);

    $next_plan = ll_tools_site_sync_build_push_plan($local_snapshot, $fresh_remote_snapshot, $merged_base);
    $result['plan'] = $next_plan;
    ll_tools_site_sync_save_cached_preview_plan($connection, $next_plan);

    $next_remote_updates = $conflict_mode === 'push_local'
        ? count(ll_tools_site_sync_push_local_conflict_updates($next_plan))
        : count((array) ($next_plan['remote_updates'] ?? []));
    $next_conflict_review_updates = count((array) ($next_plan['conflict_review_updates'] ?? []));
    $next_conflicts = count((array) ($next_plan['conflicts'] ?? []));
    $result['progress']['next_remote_updates'] = $next_remote_updates;
    $result['progress']['next_conflict_review_updates'] = $next_conflict_review_updates;
    $result['progress']['next_conflicts'] = $next_conflicts;
    $result['progress']['next_skipped'] = count((array) ($next_plan['skipped'] ?? []));
    $result['progress']['done'] = $next_remote_updates <= 0
        && ($conflict_mode !== 'push_local' || $next_conflicts <= 0)
        && ($conflict_mode !== 'flag' || $next_conflict_review_updates <= 0);

    return $result;
}

function ll_tools_site_sync_ajax_apply_push_batch(): void {
    if (!current_user_can(ll_tools_site_sync_capability())) {
        wp_send_json_error([
            'message' => __('You do not have permission to use site sync.', 'll-tools-text-domain'),
        ], 403);
    }

    check_ajax_referer('ll_tools_site_sync_apply_push', 'nonce');

    $connection = ll_tools_site_sync_connection_from_request(ll_tools_site_sync_get_saved_connection());
    update_option(ll_tools_site_sync_connection_option_name(), $connection, false);
    $password = isset($_POST['ll_site_sync_remote_password'])
        ? (string) wp_unslash($_POST['ll_site_sync_remote_password'])
        : '';
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        wp_send_json_error(['message' => $validation->get_error_message()], 400);
    }

    $conflict_mode = ll_tools_site_sync_conflict_mode_from_request();
    $batch_result = ll_tools_site_sync_apply_push_batch($connection, $password, $conflict_mode);
    if (!empty($batch_result['errors'])) {
        wp_send_json_error([
            'message' => implode(' ', array_map('strval', (array) $batch_result['errors'])),
            'progress' => (array) ($batch_result['progress'] ?? []),
        ], 500);
    }

    $progress = (array) ($batch_result['progress'] ?? []);
    $message = !empty($progress['done'])
        ? __('Push finished. Live comparison is up to date for pushable rows.', 'll-tools-text-domain')
        : sprintf(
            /* translators: 1: processed update count, 2: total update count, 3: remaining update count, 4: conflict count */
            __('Processed %1$d of %2$d queued remote update(s). %3$d update(s) remain after refresh. Conflicts remaining: %4$d.', 'll-tools-text-domain'),
            (int) ($progress['sent_remote_updates'] ?? 0),
            (int) ($progress['total_remote_updates'] ?? 0),
            (int) ($progress['next_remote_updates'] ?? 0),
            (int) ($progress['next_conflicts'] ?? 0)
        );

    wp_send_json_success([
        'message' => $message,
        'progress' => $progress,
        'notices' => array_values((array) ($batch_result['notices'] ?? [])),
    ]);
}
add_action('wp_ajax_ll_tools_site_sync_apply_push_batch', 'll_tools_site_sync_ajax_apply_push_batch');

function ll_tools_site_sync_admin_process_request(array &$connection): array {
    $result = [
        'notices' => [],
        'errors' => [],
        'plan' => null,
        'remote_result' => null,
        'pull_result' => null,
        'processed_action' => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ll_site_sync_action'])) {
        return $result;
    }

    if (!current_user_can(ll_tools_site_sync_capability())) {
        $result['errors'][] = __('You do not have permission to use site sync.', 'll-tools-text-domain');
        return $result;
    }

    check_admin_referer('ll_tools_site_sync_action', 'll_site_sync_nonce');

    $action = sanitize_key((string) wp_unslash($_POST['ll_site_sync_action']));
    $result['processed_action'] = $action;
    $connection = ll_tools_site_sync_connection_from_request($connection);
    update_option(ll_tools_site_sync_connection_option_name(), $connection, false);

    if ($action === 'save') {
        $result['notices'][] = __('Site sync connection saved. Passwords are not stored.', 'll-tools-text-domain');
        return $result;
    }

    if (in_array($action, ['revert_local_change', 'edit_local_change'], true)) {
        $local_result = ll_tools_site_sync_process_local_change_request($connection, $action);
        if (is_wp_error($local_result)) {
            $result['errors'][] = $local_result->get_error_message();
            return $result;
        }

        ll_tools_site_sync_delete_cached_preview_plan($connection);
        $result['notices'][] = $action === 'revert_local_change'
            ? sprintf(
                /* translators: %d: updated local field count */
                __('Reverted %d local field(s). Preview again when you are ready to compare against the live site.', 'll-tools-text-domain'),
                (int) ($local_result['fields_updated'] ?? 0)
            )
            : sprintf(
                /* translators: %d: updated local field count */
                __('Saved %d local after-change field(s). Preview again when you are ready to compare against the live site.', 'll-tools-text-domain'),
                (int) ($local_result['fields_updated'] ?? 0)
            );
        return $result;
    }

    $password = isset($_POST['ll_site_sync_remote_password'])
        ? (string) wp_unslash($_POST['ll_site_sync_remote_password'])
        : '';
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        $result['errors'][] = $validation->get_error_message();
        return $result;
    }

    if ($action === 'apply_push') {
        $conflict_mode = ll_tools_site_sync_conflict_mode_from_request();
        $batch_result = ll_tools_site_sync_apply_push_batch($connection, $password, $conflict_mode);
        $result['plan'] = $batch_result['plan'] ?? null;
        $result['remote_result'] = $batch_result['remote_result'] ?? null;
        $result['conflict_result'] = $batch_result['conflict_result'] ?? null;
        $result['accept_live_result'] = $batch_result['accept_live_result'] ?? null;
        $result['notices'] = array_merge($result['notices'], array_values((array) ($batch_result['notices'] ?? [])));
        foreach ((array) ($batch_result['errors'] ?? []) as $batch_error) {
            $result['errors'][] = (string) $batch_error;
        }
        if (!empty($result['errors'])) {
            return $result;
        }

        $progress = (array) ($batch_result['progress'] ?? []);
        $remaining_clean_updates = (int) ($progress['next_remote_updates'] ?? 0);
        $final_conflict_count = (int) ($progress['next_conflicts'] ?? 0);
        if (empty($progress['done'])) {
            $result['notices'][] = sprintf(
                /* translators: 1: processed update count, 2: total clean update count, 3: remaining clean update count, 4: conflict count */
                __('Push batch finished. Processed %1$d of %2$d queued remote update(s). %3$d update(s) remain; use Apply All Push Batches to continue automatically. Conflicts remaining: %4$d.', 'll-tools-text-domain'),
                (int) ($progress['sent_remote_updates'] ?? 0),
                (int) ($progress['total_remote_updates'] ?? 0),
                $remaining_clean_updates,
                $final_conflict_count
            );
        } else {
            $remote_result = (array) ($batch_result['remote_result'] ?? []);
            $request_count = (int) (($remote_result['batch'] ?? [])['request_count'] ?? 1);
            $result['notices'][] = sprintf(
                /* translators: 1: remote updated count, 2: remote request count, 3: conflict count */
                __('Push finished. Applied %1$d remote updates across %2$d small request(s). Conflicts remaining: %3$d.', 'll-tools-text-domain'),
                (int) ($remote_result['updated_count'] ?? 0),
                max(1, $request_count),
                $final_conflict_count
            );
        }

        return $result;
    }

    $local_snapshot = ll_tools_site_sync_build_snapshot(
        (int) $connection['local_wordset_id'],
        (string) $connection['surface'],
        true
    );
    if (is_wp_error($local_snapshot)) {
        $result['errors'][] = $local_snapshot->get_error_message();
        return $result;
    }

    $remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password, $action === 'pull');
    if (is_wp_error($remote_snapshot)) {
        $result['errors'][] = $remote_snapshot->get_error_message();
        return $result;
    }

    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);

    if ($action === 'pull') {
        $plan = ll_tools_site_sync_build_pull_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        $pull_result = ll_tools_site_sync_apply_pull_plan($plan, (int) $connection['local_wordset_id']);
        $merged_base = ll_tools_site_sync_merge_base_snapshot_after_pull($base_snapshot, $remote_snapshot, $plan);
        ll_tools_site_sync_save_base_snapshot($connection, $merged_base);
        $result['plan'] = $plan;
        $result['pull_result'] = $pull_result;
        $result['notices'][] = sprintf(
            /* translators: 1: updated record count, 2: created word count, 3: created record count, 4: reparented recording count, 5: field count, 6: media reference count */
            __('Pull finished. Updated %1$d local recordings, created %2$d words and %3$d recordings, moved %4$d recordings, and applied %5$d fields plus %6$d media references.', 'll-tools-text-domain'),
            (int) ($pull_result['records_updated'] ?? 0),
            (int) ($pull_result['words_created'] ?? 0),
            (int) ($pull_result['records_created'] ?? 0),
            (int) ($pull_result['recordings_reparented'] ?? 0),
            (int) ($pull_result['fields_updated'] ?? 0),
            (int) ($pull_result['media_refs_updated'] ?? 0)
        );
        foreach ((array) ($pull_result['errors'] ?? []) as $pull_error) {
            $result['errors'][] = (string) $pull_error;
        }
        if (!empty($plan['conflicts'])) {
            $result['errors'][] = sprintf(
                /* translators: %d conflict count */
                __('Pull found %d conflicts. Conflicting fields were not overwritten locally.', 'll-tools-text-domain'),
                count((array) $plan['conflicts'])
            );
        }
        return $result;
    }

    if ($action === 'preview_push') {
        $result['plan'] = ll_tools_site_sync_build_push_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        ll_tools_site_sync_save_cached_preview_plan($connection, $result['plan']);
        return $result;
    }

    $result['errors'][] = __('Unknown site sync action.', 'll-tools-text-domain');
    return $result;
}

function ll_tools_site_sync_get_wordset_options(): array {
    $terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    return is_wp_error($terms) ? [] : array_values((array) $terms);
}

function ll_tools_site_sync_field_label(string $field): string {
    $labels = [
        'recording_text' => __('Orthography', 'll-tools-text-domain'),
        'recording_ipa' => __('IPA', 'll-tools-text-domain'),
        'needs_review' => __('Needs review', 'll-tools-text-domain'),
        'review_fields' => __('Review fields', 'll-tools-text-domain'),
        'review_note' => __('Review note', 'll-tools-text-domain'),
        'audio_file_path' => __('Audio', 'll-tools-text-domain'),
        'word_image' => __('Image', 'll-tools-text-domain'),
        'sync_id' => __('Sync IDs', 'll-tools-text-domain'),
        'record' => __('Record', 'll-tools-text-domain'),
    ];

    return (string) ($labels[$field] ?? sprintf(
        /* translators: %s: sync field key */
        __('Field: %s', 'll-tools-text-domain'),
        ucwords(str_replace('_', ' ', $field))
    ));
}

function ll_tools_site_sync_positive_int_from_request(string $key, int $default = 1, string $source = 'request'): int {
    $raw = null;
    if ($source === 'post') {
        $raw = $_POST[$key] ?? null;
    } elseif ($source === 'get') {
        $raw = $_GET[$key] ?? null;
    } else {
        $raw = $_REQUEST[$key] ?? null;
    }

    if ($raw === null || is_array($raw)) {
        return max(1, $default);
    }

    return max(1, absint(wp_unslash((string) $raw)));
}

function ll_tools_site_sync_local_overview_per_page(): int {
    $per_page = (int) apply_filters('ll_tools_site_sync_local_overview_per_page', 12);
    return max(1, min(50, $per_page));
}

function ll_tools_site_sync_preview_per_page(): int {
    $per_page = (int) apply_filters('ll_tools_site_sync_preview_per_page', 25);
    return max(1, min(50, $per_page));
}

function ll_tools_site_sync_max_page(int $total_items, int $per_page): int {
    return max(1, (int) ceil(max(0, $total_items) / max(1, $per_page)));
}

function ll_tools_site_sync_render_pagination(
    string $page_key,
    int $current_page,
    int $total_items,
    int $per_page,
    array $extra_args = []
): void {
    if ($total_items <= $per_page) {
        return;
    }

    $max_page = ll_tools_site_sync_max_page($total_items, $per_page);
    $current_page = min(max(1, $current_page), $max_page);
    $start = (($current_page - 1) * $per_page) + 1;
    $end = min($total_items, $current_page * $per_page);
    ?>
    <nav class="ll-site-sync-pagination" aria-label="<?php esc_attr_e('Sync preview pagination', 'll-tools-text-domain'); ?>">
        <span><?php echo esc_html(sprintf(
            /* translators: 1: first visible item, 2: last visible item, 3: total item count */
            __('Showing %1$d-%2$d of %3$d', 'll-tools-text-domain'),
            $start,
            $end,
            $total_items
        )); ?></span>
        <?php if ($current_page > 1) : ?>
            <a class="button" href="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url(array_merge($extra_args, [$page_key => $current_page - 1]))); ?>"><?php esc_html_e('Previous', 'll-tools-text-domain'); ?></a>
        <?php endif; ?>
        <?php if ($current_page < $max_page) : ?>
            <a class="button" href="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url(array_merge($extra_args, [$page_key => $current_page + 1]))); ?>"><?php esc_html_e('Next', 'll-tools-text-domain'); ?></a>
        <?php endif; ?>
    </nav>
    <?php
}

function ll_tools_site_sync_preview_value_text($value): string {
    if (is_array($value)) {
        $items = array_values(array_filter(array_map('strval', $value), static function (string $item): bool {
            return $item !== '';
        }));
        return empty($items) ? __('(none)', 'll-tools-text-domain') : implode(', ', $items);
    }

    if (is_bool($value)) {
        return $value ? __('Yes', 'll-tools-text-domain') : __('No', 'll-tools-text-domain');
    }

    $text = trim((string) $value);
    return $text === '' ? __('(empty)', 'll-tools-text-domain') : $text;
}

function ll_tools_site_sync_preview_diff_tokens(string $before, string $after): array {
    if ($before === $after) {
        return [
            'before' => [['text' => $before, 'changed' => false]],
            'after' => [['text' => $after, 'changed' => false]],
        ];
    }

    $before_tokens = preg_split('/(\s+)/u', $before, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $after_tokens = preg_split('/(\s+)/u', $after, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $before_tokens = is_array($before_tokens) ? array_values($before_tokens) : [$before];
    $after_tokens = is_array($after_tokens) ? array_values($after_tokens) : [$after];
    $before_count = count($before_tokens);
    $after_count = count($after_tokens);

    if ($before_count * $after_count > 20000) {
        return [
            'before' => array_map(static function (string $token): array {
                return ['text' => $token, 'changed' => trim($token) !== ''];
            }, $before_tokens),
            'after' => array_map(static function (string $token): array {
                return ['text' => $token, 'changed' => trim($token) !== ''];
            }, $after_tokens),
        ];
    }

    $table = array_fill(0, $before_count + 1, array_fill(0, $after_count + 1, 0));
    for ($i = $before_count - 1; $i >= 0; $i--) {
        for ($j = $after_count - 1; $j >= 0; $j--) {
            if ($before_tokens[$i] === $after_tokens[$j]) {
                $table[$i][$j] = $table[$i + 1][$j + 1] + 1;
            } else {
                $table[$i][$j] = max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
    }

    $before_output = [];
    $after_output = [];
    $i = 0;
    $j = 0;
    while ($i < $before_count && $j < $after_count) {
        if ($before_tokens[$i] === $after_tokens[$j]) {
            $before_output[] = ['text' => $before_tokens[$i], 'changed' => false];
            $after_output[] = ['text' => $after_tokens[$j], 'changed' => false];
            $i++;
            $j++;
            continue;
        }

        if ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
            $before_output[] = ['text' => $before_tokens[$i], 'changed' => trim((string) $before_tokens[$i]) !== ''];
            $i++;
        } else {
            $after_output[] = ['text' => $after_tokens[$j], 'changed' => trim((string) $after_tokens[$j]) !== ''];
            $j++;
        }
    }

    while ($i < $before_count) {
        $before_output[] = ['text' => $before_tokens[$i], 'changed' => trim((string) $before_tokens[$i]) !== ''];
        $i++;
    }

    while ($j < $after_count) {
        $after_output[] = ['text' => $after_tokens[$j], 'changed' => trim((string) $after_tokens[$j]) !== ''];
        $j++;
    }

    return [
        'before' => $before_output,
        'after' => $after_output,
    ];
}

function ll_tools_site_sync_render_diff_value($before, $after, string $side): string {
    $before_text = ll_tools_site_sync_preview_value_text($before);
    $after_text = ll_tools_site_sync_preview_value_text($after);
    $side = $side === 'after' ? 'after' : 'before';
    $class = $side === 'after' ? 'll-site-sync-diff-added' : 'll-site-sync-diff-removed';
    $tokens = ll_tools_site_sync_preview_diff_tokens($before_text, $after_text)[$side] ?? [];
    $html = '';

    foreach ($tokens as $token) {
        $text = (string) ($token['text'] ?? '');
        if (!empty($token['changed'])) {
            $html .= '<mark class="' . esc_attr($class) . '">' . esc_html($text) . '</mark>';
        } else {
            $html .= esc_html($text);
        }
    }

    return $html === '' ? esc_html(ll_tools_site_sync_preview_value_text('')) : $html;
}

function ll_tools_site_sync_json_attr($value): string {
    return esc_attr((string) wp_json_encode($value));
}

function ll_tools_site_sync_render_local_action_hidden_fields(array $item): void {
    ?>
    <input type="hidden" name="ll_site_sync_recording_id" value="<?php echo esc_attr((string) ((int) ($item['local_recording_id'] ?? 0))); ?>">
    <input type="hidden" name="ll_site_sync_fields_json" value="<?php echo ll_tools_site_sync_json_attr(array_values((array) ($item['editable_fields'] ?? []))); ?>">
    <?php
}

function ll_tools_site_sync_render_after_value_control(string $field, $value): void {
    $field = sanitize_key($field);
    ?>
    <div class="ll-site-sync-edit-field ll-site-sync-edit-field--<?php echo esc_attr($field); ?>">
        <span><?php echo esc_html(ll_tools_site_sync_field_label($field)); ?></span>
        <?php if (in_array($field, ['recording_text', 'recording_ipa', 'review_note'], true)) : ?>
            <textarea name="ll_site_sync_after_values[<?php echo esc_attr($field); ?>]" rows="2"><?php echo esc_textarea((string) $value); ?></textarea>
        <?php elseif ($field === 'needs_review') : ?>
            <label class="ll-site-sync-checkbox ll-site-sync-checkbox--compact">
                <input type="checkbox" name="ll_site_sync_needs_review" value="1" <?php checked(!empty($value)); ?>>
                <span><?php esc_html_e('Needs review', 'll-tools-text-domain'); ?></span>
            </label>
        <?php elseif ($field === 'review_fields') : ?>
            <?php $selected = array_fill_keys(ll_tools_site_sync_normalize_review_fields($value), true); ?>
            <div class="ll-site-sync-review-field-options">
                <?php foreach (['recording_text', 'recording_ipa'] as $review_field) : ?>
                    <label class="ll-site-sync-checkbox ll-site-sync-checkbox--compact">
                        <input type="checkbox" name="ll_site_sync_review_fields[]" value="<?php echo esc_attr($review_field); ?>" <?php checked(isset($selected[$review_field])); ?>>
                        <span><?php echo esc_html(ll_tools_site_sync_field_label($review_field)); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function ll_tools_site_sync_render_local_change_actions(array $item): void {
    if (empty($item['allow_local_actions'])) {
        return;
    }

    $editable_fields = array_values((array) ($item['editable_fields'] ?? []));
    if (empty($editable_fields) || (int) ($item['local_recording_id'] ?? 0) <= 0) {
        return;
    }

    $before_values = (array) ($item['before_values'] ?? []);
    $after_values = (array) ($item['after_values'] ?? []);
    ?>
    <div class="ll-site-sync-card-actions">
        <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>" class="ll-site-sync-revert-form">
            <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
            <input type="hidden" name="ll_site_sync_action" value="revert_local_change">
            <?php ll_tools_site_sync_render_local_action_hidden_fields($item); ?>
            <input type="hidden" name="ll_site_sync_before_values_json" value="<?php echo ll_tools_site_sync_json_attr($before_values); ?>">
            <button type="submit" class="button button-secondary"><?php esc_html_e('Revert local change', 'll-tools-text-domain'); ?></button>
        </form>
        <details class="ll-site-sync-edit-details">
            <summary><?php esc_html_e('Edit after-change state', 'll-tools-text-domain'); ?></summary>
            <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>" class="ll-site-sync-edit-form">
                <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
                <input type="hidden" name="ll_site_sync_action" value="edit_local_change">
                <?php ll_tools_site_sync_render_local_action_hidden_fields($item); ?>
                <div class="ll-site-sync-edit-grid">
                    <?php foreach ($editable_fields as $field) : ?>
                        <?php ll_tools_site_sync_render_after_value_control((string) $field, $after_values[$field] ?? ''); ?>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save local edit', 'll-tools-text-domain'); ?></button>
            </form>
        </details>
    </div>
    <?php
}

function ll_tools_site_sync_preview_media_from_record(array $record): array {
    $media = ll_tools_site_sync_normalize_record_media((array) ($record['media'] ?? []));
    $audio = (array) ($media['audio'] ?? []);
    $word_image = (array) ($media['word_image'] ?? []);
    $attachment = (array) ($word_image['attachment'] ?? []);
    $word = (array) ($record['word'] ?? []);

    $image_url = (string) (($attachment['source_url'] ?? '') ?: ($attachment['url'] ?? ''));
    $audio_url = (string) (($audio['url'] ?? '') ?: ($audio['path'] ?? ''));

    return [
        'image_url' => $image_url,
        'image_alt' => (string) (($attachment['alt'] ?? '') ?: ($word['title'] ?? '')),
        'audio_url' => $audio_url,
        'audio_mime_type' => (string) ($audio['mime_type'] ?? ''),
    ];
}

function ll_tools_site_sync_preview_media_value(array $record, string $field): string {
    $media = ll_tools_site_sync_preview_media_from_record($record);

    if ($field === 'audio_file_path') {
        return (string) ($media['audio_url'] ?? '');
    }

    if ($field === 'word_image') {
        return (string) ($media['image_url'] ?? '');
    }

    return '';
}

function ll_tools_site_sync_preview_record_title(array $record, string $part): string {
    $data = (array) ($record[$part] ?? []);
    return (string) ($data['title'] ?? '');
}

function ll_tools_site_sync_preview_recording_types(array $record): array {
    return array_values(array_filter(array_map('strval', (array) (($record['recording'] ?? [])['types'] ?? []))));
}

function ll_tools_site_sync_build_preview_changes(array $before_record, array $after_record, array $fields): array {
    $changes = [];
    $before_values = ll_tools_site_sync_normalize_record_values((array) ($before_record['values'] ?? []));
    $after_values = ll_tools_site_sync_normalize_record_values((array) ($after_record['values'] ?? []));

    foreach (array_values(array_unique(array_map('strval', $fields))) as $field) {
        if (in_array($field, ll_tools_site_sync_transcription_value_keys(), true)) {
            $before_value = $before_values[$field] ?? '';
            $after_value = $after_values[$field] ?? '';
        } elseif (in_array($field, ['audio_file_path', 'word_image'], true)) {
            $before_value = ll_tools_site_sync_preview_media_value($before_record, $field);
            $after_value = ll_tools_site_sync_preview_media_value($after_record, $field);
        } else {
            continue;
        }

        if (ll_tools_site_sync_values_equal($before_value, $after_value)) {
            continue;
        }

        $changes[] = [
            'field' => $field,
            'label' => ll_tools_site_sync_field_label($field),
            'before' => $before_value,
            'after' => $after_value,
        ];
    }

    return $changes;
}

function ll_tools_site_sync_build_record_presence_change(string $status): array {
    if ($status === 'local_only') {
        return [
            'field' => 'record',
            'label' => ll_tools_site_sync_field_label('record'),
            'before' => __('Not in baseline', 'll-tools-text-domain'),
            'after' => __('Exists locally', 'll-tools-text-domain'),
        ];
    }

    if ($status === 'base_only') {
        return [
            'field' => 'record',
            'label' => ll_tools_site_sync_field_label('record'),
            'before' => __('Exists in baseline', 'll-tools-text-domain'),
            'after' => __('Missing locally', 'll-tools-text-domain'),
        ];
    }

    return [
        'field' => 'record',
        'label' => ll_tools_site_sync_field_label('record'),
        'before' => __('Previous version', 'll-tools-text-domain'),
        'after' => __('Current version', 'll-tools-text-domain'),
    ];
}

function ll_tools_site_sync_recording_id_from_record(array $record): int {
    return (int) (($record['recording'] ?? [])['id'] ?? 0);
}

function ll_tools_site_sync_change_value_fields(array $changes): array {
    $allowed = array_fill_keys(ll_tools_site_sync_transcription_value_keys(), true);
    $fields = [];

    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }
        $field = sanitize_key((string) ($change['field'] ?? ''));
        if (isset($allowed[$field])) {
            $fields[$field] = true;
        }
    }

    return array_keys($fields);
}

function ll_tools_site_sync_record_values_for_fields(array $record, array $fields): array {
    $values = ll_tools_site_sync_normalize_record_values((array) ($record['values'] ?? []));
    $subset = [];

    foreach ($fields as $field) {
        $field = sanitize_key((string) $field);
        if (array_key_exists($field, $values)) {
            $subset[$field] = $values[$field];
        }
    }

    return $subset;
}

function ll_tools_site_sync_build_change_item(
    string $status,
    array $before_record,
    array $after_record,
    array $changes,
    string $before_label,
    string $after_label,
    array $extra = []
): array {
    $display_record = !empty($after_record) ? $after_record : $before_record;
    $changes = !empty($changes) ? $changes : [ll_tools_site_sync_build_record_presence_change($status)];
    $editable_fields = ll_tools_site_sync_change_value_fields($changes);
    $local_record = isset($extra['local_record']) && is_array($extra['local_record'])
        ? (array) $extra['local_record']
        : [];
    $local_recording_id = ll_tools_site_sync_recording_id_from_record($local_record);

    return array_merge([
        'status' => $status,
        'status_label' => (string) ($extra['status_label'] ?? ''),
        'before_label' => $before_label,
        'after_label' => $after_label,
        'word_title' => ll_tools_site_sync_preview_record_title($display_record, 'word'),
        'recording_title' => ll_tools_site_sync_preview_record_title($display_record, 'recording'),
        'recording_types' => ll_tools_site_sync_preview_recording_types($display_record),
        'before_media' => ll_tools_site_sync_preview_media_from_record($before_record),
        'after_media' => ll_tools_site_sync_preview_media_from_record($after_record),
        'changes' => $changes,
        'editable_fields' => $editable_fields,
        'before_values' => ll_tools_site_sync_record_values_for_fields($before_record, $editable_fields),
        'after_values' => ll_tools_site_sync_record_values_for_fields($after_record, $editable_fields),
        'local_recording_id' => $local_recording_id,
        'allow_local_actions' => !empty($extra['allow_local_actions']) && $local_recording_id > 0 && !empty($editable_fields),
    ], $extra);
}

function ll_tools_site_sync_plan_change_items(array $plan, int $limit = 25, int $offset = 0): array {
    $direction = (string) ($plan['direction'] ?? '');
    $before_label = $direction === 'pull'
        ? __('Staging now', 'll-tools-text-domain')
        : __('Live now', 'll-tools-text-domain');
    $after_label = $direction === 'pull'
        ? __('After pull', 'll-tools-text-domain')
        : __('After push', 'll-tools-text-domain');
    $items = [];
    $seen = 0;

    foreach ((array) ($plan['actions'] ?? []) as $action) {
        if (!is_array($action)) {
            continue;
        }

        if ($seen < $offset) {
            $seen++;
            continue;
        }

        $local_record = (array) ($action['local_record'] ?? []);
        $remote_record = (array) ($action['remote_record'] ?? []);
        $before_record = $direction === 'pull' ? $local_record : $remote_record;
        $after_record = $direction === 'pull' ? $remote_record : $local_record;
        $fields = array_values(array_unique(array_merge(
            (array) ($action['value_fields'] ?? []),
            (array) ($action['media_fields'] ?? [])
        )));
        if (empty($fields)) {
            $fields = (array) ($action['fields'] ?? []);
        }

        $changes = ll_tools_site_sync_build_preview_changes($before_record, $after_record, $fields);
        if ((string) ($action['type'] ?? '') === 'link_sync_id' && empty($changes)) {
            $changes[] = [
                'field' => 'sync_id',
                'label' => ll_tools_site_sync_field_label('sync_id'),
                'before' => trim((string) ($local_record['sync_id'] ?? '')),
                'after' => trim((string) ($remote_record['sync_id'] ?? '')),
            ];
        }

        $items[] = ll_tools_site_sync_build_change_item(
            (string) ($action['type'] ?? 'change'),
            $before_record,
            $after_record,
            $changes,
            $before_label,
            $after_label,
            [
                'status_label' => (string) ($action['type'] ?? '') === 'create_local_recording'
                    ? __('Create locally', 'll-tools-text-domain')
                    : __('Clean change', 'll-tools-text-domain'),
                'local_record' => $local_record,
                'allow_local_actions' => $direction === 'push',
            ]
        );
        $seen++;

        if (count($items) >= $limit) {
            return $items;
        }
    }

    return $items;
}

function ll_tools_site_sync_conflict_change_items(array $plan, int $limit = 25, int $offset = 0): array {
    $direction = (string) ($plan['direction'] ?? '');
    $before_label = $direction === 'pull'
        ? __('Staging now', 'll-tools-text-domain')
        : __('Live now', 'll-tools-text-domain');
    $after_label = $direction === 'pull'
        ? __('After pull', 'll-tools-text-domain')
        : __('After push', 'll-tools-text-domain');
    $items = [];
    $seen = 0;

    foreach ((array) ($plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }

        if ($seen < $offset) {
            $seen++;
            continue;
        }

        $local_record = (array) ($conflict['local_record'] ?? []);
        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $field = (string) ($conflict['field'] ?? '');
        $before_record = $direction === 'pull' ? $local_record : $remote_record;
        $after_record = $direction === 'pull' ? $remote_record : $local_record;
        $before_value = $direction === 'pull' ? ($conflict['local_value'] ?? '') : ($conflict['remote_value'] ?? '');
        $after_value = $direction === 'pull' ? ($conflict['remote_value'] ?? '') : ($conflict['local_value'] ?? '');

        $items[] = ll_tools_site_sync_build_change_item(
            'conflict',
            $before_record,
            $after_record,
            [[
                'field' => $field,
                'label' => ll_tools_site_sync_field_label($field),
                'before' => $before_value,
                'after' => $after_value,
                'base' => $conflict['base_value'] ?? '',
                'base_label' => __('Last pulled', 'll-tools-text-domain'),
            ]],
            $before_label,
            $after_label,
            [
                'status_label' => __('Conflict', 'll-tools-text-domain'),
                'word_title' => (string) ($conflict['word_title'] ?? ''),
                'recording_title' => (string) ($conflict['recording_title'] ?? ''),
                'local_record' => $local_record,
                'allow_local_actions' => $direction === 'push',
            ]
        );
        $seen++;

        if (count($items) >= $limit) {
            return $items;
        }
    }

    return $items;
}

function ll_tools_site_sync_build_local_change_summary(array $connection, array $base_snapshot = [], int $sample_limit = 12, int $sample_offset = 0): array {
    $sample_limit = max(1, min(50, $sample_limit));
    $sample_offset = max(0, $sample_offset);
    $summary = [
        'available' => false,
        'message' => '',
        'sample_limit' => $sample_limit,
        'sample_offset' => $sample_offset,
        'sample_total' => 0,
        'sample_page' => (int) floor($sample_offset / $sample_limit) + 1,
        'stats' => [
            'baseline_records' => count((array) ($base_snapshot['records'] ?? [])),
            'local_records' => 0,
            'changed_records' => 0,
            'local_only_records' => 0,
            'missing_local_records' => 0,
        ],
        'field_counts' => [],
        'samples' => [],
    ];

    if ((int) ($connection['local_wordset_id'] ?? 0) <= 0) {
        $summary['message'] = __('Select and save a local staging word set to see local changes.', 'll-tools-text-domain');
        return $summary;
    }

    if (empty($base_snapshot['records'])) {
        $summary['message'] = __('No local change overview is available until a pull baseline exists.', 'll-tools-text-domain');
        return $summary;
    }

    $local_snapshot = ll_tools_site_sync_build_snapshot(
        (int) $connection['local_wordset_id'],
        (string) ($connection['surface'] ?? 'transcriptions'),
        false,
        ['include_media' => false]
    );
    if (is_wp_error($local_snapshot)) {
        $summary['message'] = $local_snapshot->get_error_message();
        return $summary;
    }

    $summary['available'] = true;
    $summary['generated_at_gmt'] = (string) ($local_snapshot['generated_at_gmt'] ?? '');
    $summary['stats']['local_records'] = count((array) ($local_snapshot['records'] ?? []));

    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $local_index = ll_tools_site_sync_index_snapshot($local_snapshot);
    $fields = ll_tools_site_sync_transcription_value_keys();
    $add_sample = static function (array $item) use (&$summary, $sample_limit, $sample_offset): void {
        $sample_index = (int) ($summary['sample_total'] ?? 0);
        $summary['sample_total'] = $sample_index + 1;
        if ($sample_index < $sample_offset || count((array) ($summary['samples'] ?? [])) >= $sample_limit) {
            return;
        }

        $summary['samples'][] = $item;
    };

    foreach ((array) ($local_snapshot['records'] ?? []) as $local_record) {
        if (!is_array($local_record)) {
            continue;
        }

        $base_record = ll_tools_site_sync_find_matching_record($local_record, $base_index);
        if ($base_record === null) {
            $summary['stats']['local_only_records']++;
            $changes = ll_tools_site_sync_build_preview_changes([], $local_record, $fields);
            $add_sample(ll_tools_site_sync_build_change_item(
                'local_only',
                [],
                $local_record,
                $changes,
                __('Baseline', 'll-tools-text-domain'),
                __('Local', 'll-tools-text-domain'),
                [
                    'status_label' => __('Local only', 'll-tools-text-domain'),
                    'local_record' => $local_record,
                    'allow_local_actions' => true,
                ]
            ));
            continue;
        }

        $changes = ll_tools_site_sync_build_preview_changes($base_record, $local_record, $fields);
        if (empty($changes)) {
            continue;
        }

        $summary['stats']['changed_records']++;
        foreach ($changes as $change) {
            $field = (string) ($change['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $summary['field_counts'][$field] = (int) ($summary['field_counts'][$field] ?? 0) + 1;
        }

        $add_sample(ll_tools_site_sync_build_change_item(
            'modified',
            $base_record,
            $local_record,
            $changes,
            __('Baseline', 'll-tools-text-domain'),
            __('Local', 'll-tools-text-domain'),
            [
                'status_label' => __('Modified locally', 'll-tools-text-domain'),
                'local_record' => $local_record,
                'allow_local_actions' => true,
            ]
        ));
    }

    foreach ((array) ($base_snapshot['records'] ?? []) as $base_record) {
        if (!is_array($base_record)) {
            continue;
        }

        if (ll_tools_site_sync_find_matching_record($base_record, $local_index) !== null) {
            continue;
        }

        $summary['stats']['missing_local_records']++;
        $add_sample(ll_tools_site_sync_build_change_item(
            'base_only',
            $base_record,
            [],
            ll_tools_site_sync_build_preview_changes($base_record, [], $fields),
            __('Baseline', 'll-tools-text-domain'),
            __('Local', 'll-tools-text-domain'),
            ['status_label' => __('Missing locally', 'll-tools-text-domain')]
        ));
    }

    ksort($summary['field_counts']);
    return $summary;
}

function ll_tools_site_sync_render_change_cards(array $items, array $args = []): void {
    $empty_message = (string) ($args['empty_message'] ?? __('No changes to preview.', 'll-tools-text-domain'));
    if (empty($items)) {
        echo '<p class="description">' . esc_html($empty_message) . '</p>';
        return;
    }

    ?>
    <div class="ll-site-sync-change-list">
        <?php foreach ($items as $item) : ?>
            <?php
            $before_media = (array) ($item['before_media'] ?? []);
            $after_media = (array) ($item['after_media'] ?? []);
            $image_url = (string) (($after_media['image_url'] ?? '') ?: ($before_media['image_url'] ?? ''));
            $image_alt = (string) (($after_media['image_alt'] ?? '') ?: ($before_media['image_alt'] ?? ''));
            $audio_url = (string) (($after_media['audio_url'] ?? '') ?: ($before_media['audio_url'] ?? ''));
            $status = sanitize_html_class((string) ($item['status'] ?? 'change'));
            ?>
            <article class="ll-site-sync-change-card ll-site-sync-change-card--<?php echo esc_attr($status); ?>">
                <div class="ll-site-sync-change-media">
                    <?php if ($image_url !== '') : ?>
                        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" loading="lazy">
                    <?php else : ?>
                        <div class="ll-site-sync-media-empty"><?php esc_html_e('No image', 'll-tools-text-domain'); ?></div>
                    <?php endif; ?>
                    <?php if ($audio_url !== '') : ?>
                        <audio controls preload="none" src="<?php echo esc_url($audio_url); ?>"></audio>
                    <?php else : ?>
                        <div class="ll-site-sync-audio-empty"><?php esc_html_e('No audio', 'll-tools-text-domain'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="ll-site-sync-change-main">
                    <div class="ll-site-sync-change-header">
                        <?php if ((string) ($item['status_label'] ?? '') !== '') : ?>
                            <span class="ll-site-sync-status-badge"><?php echo esc_html((string) $item['status_label']); ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?php echo esc_html((string) ($item['word_title'] ?? '')); ?></strong>
                            <?php if ((string) ($item['recording_title'] ?? '') !== '') : ?>
                                <span><?php echo esc_html((string) $item['recording_title']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($item['recording_types'])) : ?>
                        <div class="ll-site-sync-type-row">
                            <?php foreach ((array) $item['recording_types'] as $type) : ?>
                                <code><?php echo esc_html((string) $type); ?></code>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ll-site-sync-field-diffs">
                        <?php foreach ((array) ($item['changes'] ?? []) as $change) : ?>
                            <?php
                            $before_label = (string) ($item['before_label'] ?? __('Before', 'll-tools-text-domain'));
                            $after_label = (string) ($item['after_label'] ?? __('After', 'll-tools-text-domain'));
                            ?>
                            <div class="ll-site-sync-field-diff">
                                <div class="ll-site-sync-field-name"><?php echo esc_html((string) ($change['label'] ?? ll_tools_site_sync_field_label((string) ($change['field'] ?? '')))); ?></div>
                                <?php if (array_key_exists('base', $change)) : ?>
                                    <div class="ll-site-sync-base-value">
                                        <span><?php echo esc_html((string) ($change['base_label'] ?? __('Baseline', 'll-tools-text-domain'))); ?></span>
                                        <div><?php echo esc_html(ll_tools_site_sync_preview_value_text($change['base'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="ll-site-sync-diff-grid">
                                    <div class="ll-site-sync-diff-side">
                                        <span><?php echo esc_html($before_label); ?></span>
                                        <div class="ll-site-sync-diff-value"><?php echo ll_tools_site_sync_render_diff_value($change['before'] ?? '', $change['after'] ?? '', 'before'); ?></div>
                                    </div>
                                    <div class="ll-site-sync-diff-side">
                                        <span><?php echo esc_html($after_label); ?></span>
                                        <div class="ll-site-sync-diff-value"><?php echo ll_tools_site_sync_render_diff_value($change['before'] ?? '', $change['after'] ?? '', 'after'); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php ll_tools_site_sync_render_local_change_actions((array) $item); ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
}

function ll_tools_site_sync_render_local_change_overview(array $summary): void {
    ?>
    <div class="ll-site-sync-local-overview">
        <h3><?php esc_html_e('Local changes since baseline', 'll-tools-text-domain'); ?></h3>
        <p class="description"><?php esc_html_e('This overview compares the current staging word set to the last saved pull baseline. It does not contact the live site or require the remote password.', 'll-tools-text-domain'); ?></p>
        <?php if (empty($summary['available'])) : ?>
            <p class="description"><?php echo esc_html((string) ($summary['message'] ?? '')); ?></p>
        <?php else : ?>
            <?php $stats = (array) ($summary['stats'] ?? []); ?>
            <div class="ll-site-sync-stat-row">
                <span><?php echo esc_html(sprintf(__('Baseline records: %d', 'll-tools-text-domain'), (int) ($stats['baseline_records'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Local records: %d', 'll-tools-text-domain'), (int) ($stats['local_records'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Modified locally: %d', 'll-tools-text-domain'), (int) ($stats['changed_records'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Local only: %d', 'll-tools-text-domain'), (int) ($stats['local_only_records'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Missing locally: %d', 'll-tools-text-domain'), (int) ($stats['missing_local_records'] ?? 0))); ?></span>
            </div>
            <?php if (!empty($summary['field_counts'])) : ?>
                <div class="ll-site-sync-field-counts">
                    <?php foreach ((array) $summary['field_counts'] as $field => $count) : ?>
                        <span><?php echo esc_html(sprintf(
                            /* translators: 1: sync field label, 2: changed row count */
                            __('%1$s: %2$d', 'll-tools-text-domain'),
                            ll_tools_site_sync_field_label((string) $field),
                            (int) $count
                        )); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php ll_tools_site_sync_render_change_cards((array) ($summary['samples'] ?? []), [
                'empty_message' => __('No local changes were found against the saved baseline.', 'll-tools-text-domain'),
            ]); ?>
            <?php
            ll_tools_site_sync_render_pagination(
                'll_site_sync_local_page',
                (int) ($summary['sample_page'] ?? 1),
                (int) ($summary['sample_total'] ?? 0),
                (int) ($summary['sample_limit'] ?? ll_tools_site_sync_local_overview_per_page())
            );
            ?>
        <?php endif; ?>
    </div>
    <?php
}

function ll_tools_site_sync_render_local_change_overview_placeholder(array $connection, array $base_snapshot): void {
    if ((int) ($connection['local_wordset_id'] ?? 0) <= 0) {
        ll_tools_site_sync_render_local_change_overview([
            'available' => false,
            'message' => __('Select and save a local staging word set to see local changes.', 'll-tools-text-domain'),
            'stats' => [
                'baseline_records' => 0,
                'local_records' => 0,
                'changed_records' => 0,
                'local_only_records' => 0,
                'missing_local_records' => 0,
            ],
            'field_counts' => [],
            'samples' => [],
        ]);
        return;
    }

    if (empty($base_snapshot['records'])) {
        ll_tools_site_sync_render_local_change_overview([
            'available' => false,
            'message' => __('No local change overview is available until a pull baseline exists.', 'll-tools-text-domain'),
            'stats' => [
                'baseline_records' => 0,
                'local_records' => 0,
                'changed_records' => 0,
                'local_only_records' => 0,
                'missing_local_records' => 0,
            ],
            'field_counts' => [],
            'samples' => [],
        ]);
        return;
    }

    ?>
    <div class="ll-site-sync-local-overview ll-site-sync-local-overview--async" data-ll-site-sync-local-overview aria-busy="true">
        <h3><?php esc_html_e('Local changes since baseline', 'll-tools-text-domain'); ?></h3>
        <p class="description"><?php esc_html_e('This overview compares the current staging word set to the last saved pull baseline. It does not contact the live site or require the remote password.', 'll-tools-text-domain'); ?></p>
        <div class="ll-site-sync-loading" role="status" aria-live="polite" data-ll-site-sync-overview-state="1">
            <span class="spinner is-active" aria-hidden="true"></span>
            <span><?php esc_html_e('Checking local changes in the background...', 'll-tools-text-domain'); ?></span>
        </div>
        <button type="button" class="button ll-site-sync-retry-overview" hidden><?php esc_html_e('Retry', 'll-tools-text-domain'); ?></button>
    </div>
    <?php
}

function ll_tools_site_sync_should_render_local_change_overview(array $result): bool {
    return !is_array($result['plan'] ?? null);
}

function ll_tools_site_sync_render_notices(array $result): void {
    foreach ((array) ($result['notices'] ?? []) as $notice) {
        echo '<div class="notice notice-success"><p>' . esc_html((string) $notice) . '</p></div>';
    }

    foreach ((array) ($result['errors'] ?? []) as $error) {
        echo '<div class="notice notice-error"><p>' . esc_html((string) $error) . '</p></div>';
    }
}

function ll_tools_site_sync_render_connection_fields(array $connection): void {
    $wordsets = ll_tools_site_sync_get_wordset_options();
    ?>
    <div class="ll-site-sync-grid">
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Local staging word set', 'll-tools-text-domain'); ?></span>
            <select name="ll_site_sync_local_wordset_id" required>
                <option value="0"><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                <?php foreach ($wordsets as $wordset) : ?>
                    <?php if ($wordset instanceof WP_Term) : ?>
                        <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected((int) ($connection['local_wordset_id'] ?? 0), (int) $wordset->term_id); ?>>
                            <?php echo esc_html($wordset->name . ' (' . $wordset->slug . ')'); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote site URL', 'll-tools-text-domain'); ?></span>
            <input type="url" name="ll_site_sync_remote_url" value="<?php echo esc_attr((string) ($connection['remote_url'] ?? '')); ?>" placeholder="https://example.com" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote word set slug or ID', 'll-tools-text-domain'); ?></span>
            <input type="text" name="ll_site_sync_remote_wordset" value="<?php echo esc_attr((string) ($connection['remote_wordset'] ?? '')); ?>" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote username', 'll-tools-text-domain'); ?></span>
            <input type="text" name="ll_site_sync_remote_username" value="<?php echo esc_attr((string) ($connection['remote_username'] ?? '')); ?>" autocomplete="username" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote password for this action', 'll-tools-text-domain'); ?></span>
            <input type="password" name="ll_site_sync_remote_password" value="" autocomplete="current-password">
        </label>
        <input type="hidden" name="ll_site_sync_surface" value="transcriptions">
    </div>
    <?php
}

function ll_tools_site_sync_render_connection_hidden_fields(array $connection): void {
    ?>
    <input type="hidden" name="ll_site_sync_local_wordset_id" value="<?php echo esc_attr((string) ((int) ($connection['local_wordset_id'] ?? 0))); ?>">
    <input type="hidden" name="ll_site_sync_remote_url" value="<?php echo esc_attr((string) ($connection['remote_url'] ?? '')); ?>">
    <input type="hidden" name="ll_site_sync_remote_wordset" value="<?php echo esc_attr((string) ($connection['remote_wordset'] ?? '')); ?>">
    <input type="hidden" name="ll_site_sync_remote_username" value="<?php echo esc_attr((string) ($connection['remote_username'] ?? '')); ?>">
    <input type="hidden" name="ll_site_sync_surface" value="<?php echo esc_attr(ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions'))); ?>">
    <?php
}

function ll_tools_site_sync_render_apply_push_panel(array $connection, ?array $plan): void {
    if (!is_array($plan) || (string) ($plan['direction'] ?? '') !== 'push') {
        return;
    }

    $clean_count = count((array) ($plan['remote_updates'] ?? []));
    $conflict_count = count((array) ($plan['conflicts'] ?? []));
    $skipped_count = count((array) ($plan['skipped'] ?? []));
    $total_push_local_count = count(ll_tools_site_sync_push_local_conflict_updates($plan));
    ?>
    <section class="ll-site-sync-panel ll-site-sync-apply-panel" data-ll-site-sync-apply-panel>
        <h2><?php esc_html_e('Apply Push', 'll-tools-text-domain'); ?></h2>
        <p class="description">
            <?php echo esc_html(sprintf(
                /* translators: 1: clean update count, 2: conflict count, 3: skipped count */
                __('Ready to process %1$d clean update(s), %2$d conflict(s), and %3$d skipped row(s). The browser will keep sending small live-safe batches until the pushable work is finished.', 'll-tools-text-domain'),
                $clean_count,
                $conflict_count,
                $skipped_count
            )); ?>
        </p>
        <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>" data-ll-site-sync-apply-form>
            <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
            <?php ll_tools_site_sync_render_connection_hidden_fields($connection); ?>
            <label class="ll-site-sync-field ll-site-sync-apply-password">
                <span><?php esc_html_e('Remote password', 'll-tools-text-domain'); ?></span>
                <input type="password" name="ll_site_sync_remote_password" value="" autocomplete="current-password" required>
            </label>
            <?php if ($conflict_count > 0) : ?>
                <fieldset class="ll-site-sync-conflict-options">
                    <legend><?php esc_html_e('Conflict handling', 'll-tools-text-domain'); ?></legend>
                    <label class="ll-site-sync-checkbox">
                        <input type="radio" name="ll_site_sync_conflict_mode" value="flag" checked>
                        <span><?php esc_html_e('Flag conflicts on the live site for transcription review.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <label class="ll-site-sync-checkbox">
                        <input type="radio" name="ll_site_sync_conflict_mode" value="push_local">
                        <span><?php esc_html_e('Push staging values to live for conflicts.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <label class="ll-site-sync-checkbox">
                        <input type="radio" name="ll_site_sync_conflict_mode" value="skip">
                        <span><?php esc_html_e('Skip conflicts for now.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <label class="ll-site-sync-checkbox">
                        <input type="radio" name="ll_site_sync_conflict_mode" value="accept_live">
                        <span><?php esc_html_e('Accept the live site version locally for all conflicts.', 'll-tools-text-domain'); ?></span>
                    </label>
                </fieldset>
                <p class="description">
                    <?php echo esc_html(sprintf(
                        /* translators: %d: update count including local-wins conflicts */
                        __('Choosing staging-values conflict handling would queue %d total remote update(s).', 'll-tools-text-domain'),
                        $total_push_local_count
                    )); ?>
                </p>
            <?php else : ?>
                <input type="hidden" name="ll_site_sync_conflict_mode" value="skip">
            <?php endif; ?>
            <div class="ll-site-sync-actions">
                <button type="submit" class="button button-primary" name="ll_site_sync_action" value="apply_push" data-ll-site-sync-apply-button>
                    <?php esc_html_e('Apply All Push Batches', 'll-tools-text-domain'); ?>
                </button>
            </div>
            <div class="ll-site-sync-apply-progress" data-ll-site-sync-apply-progress hidden>
                <div class="ll-site-sync-loading" role="status" aria-live="polite">
                    <span class="spinner" aria-hidden="true"></span>
                    <span data-ll-site-sync-apply-status><?php esc_html_e('Waiting to start.', 'll-tools-text-domain'); ?></span>
                </div>
                <progress data-ll-site-sync-apply-meter max="100" value="0"></progress>
            </div>
        </form>
    </section>
    <?php
}

function ll_tools_site_sync_render_plan_summary(?array $plan): void {
    if (!is_array($plan)) {
        return;
    }

    $stats = (array) ($plan['stats'] ?? []);
    $per_page = ll_tools_site_sync_preview_per_page();
    $direction = (string) ($plan['direction'] ?? '');
    $clean_count = count((array) ($plan['actions'] ?? []));
    $conflict_count = count((array) ($plan['conflicts'] ?? []));
    $clean_page = min(
        ll_tools_site_sync_positive_int_from_request('ll_site_sync_clean_page', 1, 'get'),
        ll_tools_site_sync_max_page($clean_count, $per_page)
    );
    $conflict_page = min(
        ll_tools_site_sync_positive_int_from_request('ll_site_sync_conflict_page', 1, 'get'),
        ll_tools_site_sync_max_page($conflict_count, $per_page)
    );
    $clean_items = ll_tools_site_sync_plan_change_items($plan, $per_page, ($clean_page - 1) * $per_page);
    $conflict_items = ll_tools_site_sync_conflict_change_items($plan, $per_page, ($conflict_page - 1) * $per_page);
    $preview_page_args = [
        'll_site_sync_cached_preview' => 1,
        'll_site_sync_clean_page' => $clean_page,
        'll_site_sync_conflict_page' => $conflict_page,
    ];
    ?>
    <section class="ll-site-sync-panel">
        <h2><?php esc_html_e('Live Comparison Preview', 'll-tools-text-domain'); ?></h2>
        <p class="description">
            <?php
            echo esc_html($direction === 'pull'
                ? __('This preview uses a fresh live-site snapshot for this request and shows what would change on staging if you pull now.', 'll-tools-text-domain')
                : __('This preview uses a fresh live-site snapshot for this request and shows what would change on live if you push now.', 'll-tools-text-domain'));
            ?>
        </p>
        <div class="ll-site-sync-stat-row">
            <span><?php echo esc_html(sprintf(__('Records checked: %d', 'll-tools-text-domain'), (int) ($stats['records_checked'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Words to create: %d', 'll-tools-text-domain'), (int) ($stats['words_to_create'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Records to create: %d', 'll-tools-text-domain'), (int) ($stats['records_to_create'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Fields to apply: %d', 'll-tools-text-domain'), (int) ($stats['fields_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Media refs to apply: %d', 'll-tools-text-domain'), (int) ($stats['media_refs_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Conflicts: %d', 'll-tools-text-domain'), (int) ($stats['conflicts'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Skipped: %d', 'll-tools-text-domain'), (int) ($stats['skipped'] ?? 0))); ?></span>
        </div>
        <?php if ($conflict_count > 0) : ?>
            <div class="ll-site-sync-conflict-banner" role="alert">
                <strong><?php echo esc_html(sprintf(
                    /* translators: %d: conflict count */
                    _n('%d conflict needs review', '%d conflicts need review', $conflict_count, 'll-tools-text-domain'),
                    $conflict_count
                )); ?></strong>
                <span><?php esc_html_e('These fields changed on both staging and live since the saved baseline. Review the baseline, staging, and live values before applying clean changes.', 'll-tools-text-domain'); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($clean_items)) : ?>
            <h3><?php esc_html_e('Clean Changes', 'll-tools-text-domain'); ?></h3>
            <?php ll_tools_site_sync_render_change_cards($clean_items); ?>
            <?php ll_tools_site_sync_render_pagination(
                'll_site_sync_clean_page',
                $clean_page,
                $clean_count,
                $per_page,
                array_merge($preview_page_args, ['ll_site_sync_conflict_page' => $conflict_page])
            ); ?>
        <?php endif; ?>
        <?php if (!empty($conflict_items)) : ?>
            <h3><?php esc_html_e('Conflicts', 'll-tools-text-domain'); ?></h3>
            <?php ll_tools_site_sync_render_change_cards($conflict_items); ?>
            <?php ll_tools_site_sync_render_pagination(
                'll_site_sync_conflict_page',
                $conflict_page,
                $conflict_count,
                $per_page,
                array_merge($preview_page_args, ['ll_site_sync_clean_page' => $clean_page])
            ); ?>
        <?php endif; ?>
        <?php if (empty($clean_items) && empty($conflict_items) && empty($plan['skipped'])) : ?>
            <p class="description"><?php esc_html_e('No changes were found for this sync direction.', 'll-tools-text-domain'); ?></p>
        <?php endif; ?>
        <?php if (!empty($plan['skipped'])) : ?>
            <p class="description">
                <?php echo esc_html(sprintf(__('Skipped rows are usually recordings that exist on only one site or rows without a previous pull baseline. Count: %d', 'll-tools-text-domain'), count((array) $plan['skipped']))); ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

function ll_tools_site_sync_render_admin_page(): void {
    if (!current_user_can(ll_tools_site_sync_capability())) {
        wp_die(__('You do not have permission to use site sync.', 'll-tools-text-domain'));
    }

    $connection = ll_tools_site_sync_get_saved_connection();
    $result = ll_tools_site_sync_admin_process_request($connection);
    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);
    $plan = is_array($result['plan'] ?? null) ? $result['plan'] : null;
    if (!is_array($plan) && !empty($_GET['ll_site_sync_cached_preview'])) {
        $plan = ll_tools_site_sync_get_cached_preview_plan($connection);
        if (is_array($plan)) {
            $result['plan'] = $plan;
            $result['processed_action'] = 'preview_cache';
        }
    }
    $show_local_change_overview = ll_tools_site_sync_should_render_local_change_overview($result);
    ?>
    <div class="wrap ll-site-sync">
        <h1><?php esc_html_e('LL Site Sync', 'll-tools-text-domain'); ?></h1>
        <p class="ll-site-sync-intro"><?php esc_html_e('Sync one word set at a time between this staging site and a live LL Tools site. The first supported sync surface is recording text, transcription, and transcription review state.', 'll-tools-text-domain'); ?></p>
        <?php ll_tools_site_sync_render_notices($result); ?>

        <section class="ll-site-sync-panel">
            <h2><?php esc_html_e('Connection', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>">
                <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
                <?php ll_tools_site_sync_render_connection_fields($connection); ?>
                <div class="ll-site-sync-actions">
                    <button type="submit" class="button" name="ll_site_sync_action" value="save"><?php esc_html_e('Save Connection', 'll-tools-text-domain'); ?></button>
                    <button type="submit" class="button button-secondary" name="ll_site_sync_action" value="pull"><?php esc_html_e('Pull Live into Staging', 'll-tools-text-domain'); ?></button>
                    <button type="submit" class="button button-secondary" name="ll_site_sync_action" value="preview_push"><?php esc_html_e('Preview Push to Live', 'll-tools-text-domain'); ?></button>
                </div>
            </form>
        </section>

        <section class="ll-site-sync-panel ll-site-sync-panel--status">
            <h2><?php esc_html_e('Baseline', 'll-tools-text-domain'); ?></h2>
            <?php if (!empty($base_snapshot['records'])) : ?>
                <p><?php echo esc_html(sprintf(__('Last pull baseline: %1$s, %2$d records.', 'll-tools-text-domain'), (string) ($base_snapshot['generated_at_gmt'] ?? ''), count((array) ($base_snapshot['records'] ?? [])))); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('No pull baseline is stored yet. Pull from live before pushing staging changes.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
            <?php if ($show_local_change_overview) : ?>
                <?php ll_tools_site_sync_render_local_change_overview_placeholder($connection, $base_snapshot); ?>
            <?php elseif (is_array($plan)) : ?>
                <p class="description"><?php esc_html_e('The saved-baseline local overview is hidden because a fresh live comparison preview is shown below for this request.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </section>

        <?php ll_tools_site_sync_render_apply_push_panel($connection, $plan); ?>

        <?php ll_tools_site_sync_render_plan_summary($plan); ?>
    </div>
    <?php
}
