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

function ll_tools_site_sync_fetch_remote_snapshot(array $connection, string $password) {
    $remote_wordset = rawurlencode((string) $connection['remote_wordset']);
    $surface = ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions'));
    $route = '/wordsets/' . $remote_wordset . '/site-sync/snapshot?' . http_build_query([
        'surface' => $surface,
        'ensure_sync_ids' => 1,
    ]);

    return ll_tools_site_sync_remote_request($connection, 'GET', $route, $password);
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
    return ll_tools_site_sync_remote_request(
        $connection,
        'POST',
        '/wordsets/' . $remote_wordset . '/transcriptions',
        $password,
        ['updates' => $updates]
    );
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
}
add_action('admin_enqueue_scripts', 'll_tools_site_sync_enqueue_admin_assets');

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

    $password = isset($_POST['ll_site_sync_remote_password'])
        ? (string) wp_unslash($_POST['ll_site_sync_remote_password'])
        : '';
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        $result['errors'][] = $validation->get_error_message();
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

    $remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password);
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
        return $result;
    }

    if ($action === 'apply_push') {
        $plan = ll_tools_site_sync_build_push_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        $result['plan'] = $plan;
        $remote_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, (array) ($plan['remote_updates'] ?? []));
        if (is_wp_error($remote_result)) {
            $result['errors'][] = $remote_result->get_error_message();
            return $result;
        }
        $result['remote_result'] = $remote_result;

        $flag_conflicts = !empty($_POST['ll_site_sync_flag_conflicts']);
        if ($flag_conflicts && !empty($plan['conflict_review_updates'])) {
            $conflict_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, (array) $plan['conflict_review_updates']);
            if (is_wp_error($conflict_result)) {
                $result['errors'][] = $conflict_result->get_error_message();
                return $result;
            }
            $result['conflict_result'] = $conflict_result;
        }

        $fresh_remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password);
        if (!is_wp_error($fresh_remote_snapshot)) {
            $merged_base = ll_tools_site_sync_merge_base_snapshot_after_pull($base_snapshot, $fresh_remote_snapshot, $plan);
            ll_tools_site_sync_save_base_snapshot($connection, $merged_base);
        }

        $result['notices'][] = sprintf(
            /* translators: 1: remote updated count, 2: conflict count */
            __('Push finished. Applied %1$d remote updates. Conflicts remaining: %2$d.', 'll-tools-text-domain'),
            (int) ($remote_result['updated_count'] ?? 0),
            count((array) ($plan['conflicts'] ?? []))
        );
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
    ], $extra);
}

function ll_tools_site_sync_plan_change_items(array $plan, int $limit = 25): array {
    $direction = (string) ($plan['direction'] ?? '');
    $before_label = $direction === 'pull'
        ? __('Staging now', 'll-tools-text-domain')
        : __('Live now', 'll-tools-text-domain');
    $after_label = $direction === 'pull'
        ? __('After pull', 'll-tools-text-domain')
        : __('After push', 'll-tools-text-domain');
    $items = [];

    foreach ((array) ($plan['actions'] ?? []) as $action) {
        if (!is_array($action)) {
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
            ]
        );

        if (count($items) >= $limit) {
            return $items;
        }
    }

    return $items;
}

function ll_tools_site_sync_conflict_change_items(array $plan, int $limit = 25): array {
    $items = [];

    foreach ((array) ($plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }

        $local_record = (array) ($conflict['local_record'] ?? []);
        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $field = (string) ($conflict['field'] ?? '');
        $items[] = ll_tools_site_sync_build_change_item(
            'conflict',
            $local_record,
            $remote_record,
            [[
                'field' => $field,
                'label' => ll_tools_site_sync_field_label($field),
                'before' => $conflict['local_value'] ?? '',
                'after' => $conflict['remote_value'] ?? '',
                'base' => $conflict['base_value'] ?? '',
                'base_label' => __('Last pulled', 'll-tools-text-domain'),
            ]],
            __('Staging', 'll-tools-text-domain'),
            __('Live', 'll-tools-text-domain'),
            [
                'status_label' => __('Conflict', 'll-tools-text-domain'),
                'word_title' => (string) ($conflict['word_title'] ?? ''),
                'recording_title' => (string) ($conflict['recording_title'] ?? ''),
            ]
        );

        if (count($items) >= $limit) {
            return $items;
        }
    }

    return $items;
}

function ll_tools_site_sync_build_local_change_summary(array $connection, array $base_snapshot = [], int $sample_limit = 12): array {
    $summary = [
        'available' => false,
        'message' => '',
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
        false
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

    foreach ((array) ($local_snapshot['records'] ?? []) as $local_record) {
        if (!is_array($local_record)) {
            continue;
        }

        $base_record = ll_tools_site_sync_find_matching_record($local_record, $base_index);
        if ($base_record === null) {
            $summary['stats']['local_only_records']++;
            $changes = ll_tools_site_sync_build_preview_changes([], $local_record, $fields);
            if (count($summary['samples']) < $sample_limit) {
                $summary['samples'][] = ll_tools_site_sync_build_change_item(
                    'local_only',
                    [],
                    $local_record,
                    $changes,
                    __('Baseline', 'll-tools-text-domain'),
                    __('Local', 'll-tools-text-domain'),
                    ['status_label' => __('Local only', 'll-tools-text-domain')]
                );
            }
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

        if (count($summary['samples']) < $sample_limit) {
            $summary['samples'][] = ll_tools_site_sync_build_change_item(
                'modified',
                $base_record,
                $local_record,
                $changes,
                __('Baseline', 'll-tools-text-domain'),
                __('Local', 'll-tools-text-domain'),
                ['status_label' => __('Modified locally', 'll-tools-text-domain')]
            );
        }
    }

    foreach ((array) ($base_snapshot['records'] ?? []) as $base_record) {
        if (!is_array($base_record)) {
            continue;
        }

        if (ll_tools_site_sync_find_matching_record($base_record, $local_index) !== null) {
            continue;
        }

        $summary['stats']['missing_local_records']++;
        if (count($summary['samples']) < $sample_limit) {
            $summary['samples'][] = ll_tools_site_sync_build_change_item(
                'base_only',
                $base_record,
                [],
                ll_tools_site_sync_build_preview_changes($base_record, [], $fields),
                __('Baseline', 'll-tools-text-domain'),
                __('Local', 'll-tools-text-domain'),
                ['status_label' => __('Missing locally', 'll-tools-text-domain')]
            );
        }
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
        <?php endif; ?>
    </div>
    <?php
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

function ll_tools_site_sync_render_plan_summary(?array $plan): void {
    if (!is_array($plan)) {
        return;
    }

    $stats = (array) ($plan['stats'] ?? []);
    $clean_items = ll_tools_site_sync_plan_change_items($plan, 25);
    $conflict_items = ll_tools_site_sync_conflict_change_items($plan, 25);
    ?>
    <section class="ll-site-sync-panel">
        <h2><?php esc_html_e('Sync Preview', 'll-tools-text-domain'); ?></h2>
        <div class="ll-site-sync-stat-row">
            <span><?php echo esc_html(sprintf(__('Records checked: %d', 'll-tools-text-domain'), (int) ($stats['records_checked'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Words to create: %d', 'll-tools-text-domain'), (int) ($stats['words_to_create'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Records to create: %d', 'll-tools-text-domain'), (int) ($stats['records_to_create'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Fields to apply: %d', 'll-tools-text-domain'), (int) ($stats['fields_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Media refs to apply: %d', 'll-tools-text-domain'), (int) ($stats['media_refs_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Conflicts: %d', 'll-tools-text-domain'), (int) ($stats['conflicts'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Skipped: %d', 'll-tools-text-domain'), (int) ($stats['skipped'] ?? 0))); ?></span>
        </div>
        <?php if (!empty($clean_items)) : ?>
            <h3><?php esc_html_e('Clean Changes', 'll-tools-text-domain'); ?></h3>
            <?php ll_tools_site_sync_render_change_cards($clean_items); ?>
        <?php endif; ?>
        <?php if (!empty($conflict_items)) : ?>
            <h3><?php esc_html_e('Conflicts', 'll-tools-text-domain'); ?></h3>
            <?php ll_tools_site_sync_render_change_cards($conflict_items); ?>
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
    $local_change_summary = ll_tools_site_sync_build_local_change_summary($connection, $base_snapshot);
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
            <?php ll_tools_site_sync_render_local_change_overview($local_change_summary); ?>
        </section>

        <?php ll_tools_site_sync_render_plan_summary(is_array($result['plan'] ?? null) ? $result['plan'] : null); ?>

        <?php if (is_array($result['plan'] ?? null) && (string) (($result['plan']['direction'] ?? '')) === 'push' && (string) ($result['processed_action'] ?? '') === 'preview_push') : ?>
            <section class="ll-site-sync-panel">
                <h2><?php esc_html_e('Apply Push', 'll-tools-text-domain'); ?></h2>
                <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>">
                    <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
                    <?php ll_tools_site_sync_render_connection_fields($connection); ?>
                    <label class="ll-site-sync-checkbox">
                        <input type="checkbox" name="ll_site_sync_flag_conflicts" value="1" checked>
                        <span><?php esc_html_e('Flag conflicts on the live site for transcription review instead of silently ignoring them.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <button type="submit" class="button button-primary" name="ll_site_sync_action" value="apply_push"><?php esc_html_e('Apply Clean Push to Live', 'll-tools-text-domain'); ?></button>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <?php
}
