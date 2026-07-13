<?php
if (!defined('WPINC')) { die; }

const LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK = 'll_tools_word_grid_bulk_operation_cleanup';
const LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK = 'll_tools_word_grid_bulk_snapshot_cleanup';

function ll_tools_word_grid_bulk_operation_modes(): array {
    return ['pos', 'gender', 'plurality', 'verb_tense', 'verb_mood'];
}

function ll_tools_word_grid_bulk_operation_control_key(string $mode): string {
    return str_replace('_', '-', sanitize_key($mode));
}

function ll_tools_word_grid_bulk_operation_request_field(string $mode): string {
    $fields = [
        'pos' => 'part_of_speech',
        'gender' => 'grammatical_gender',
        'plurality' => 'grammatical_plurality',
        'verb_tense' => 'verb_tense',
        'verb_mood' => 'verb_mood',
    ];
    return (string) ($fields[$mode] ?? '');
}

function ll_tools_word_grid_bulk_operation_retention_seconds(): int {
    $retention = (int) apply_filters('ll_tools_word_grid_bulk_operation_retention_seconds', DAY_IN_SECONDS);
    return max(HOUR_IN_SECONDS, min(7 * DAY_IN_SECONDS, $retention));
}

function ll_tools_word_grid_bulk_operation_state_key(
    int $user_id,
    int $wordset_id,
    int $category_id,
    string $mode
): string {
    $scope = implode(':', [max(0, $user_id), max(0, $wordset_id), max(0, $category_id), sanitize_key($mode)]);
    return 'll_tools_wgb_' . md5($scope);
}

function ll_tools_word_grid_bulk_operation_lock_key(string $state_key): string {
    return $state_key . '_lock';
}

function ll_tools_word_grid_bulk_operation_lock_seconds(): int {
    return max(30, min(300, (int) apply_filters('ll_tools_word_grid_bulk_operation_lock_seconds', 120)));
}

function ll_tools_word_grid_bulk_operation_chunk_key(string $token, int $chunk_index): string {
    return 'll_tools_wgbc_' . substr(hash('sha256', $token), 0, 24) . '_' . max(1, $chunk_index);
}

function ll_tools_word_grid_bulk_operation_cleanup_key(string $token): string {
    return 'll_tools_wgbg_' . substr(hash('sha256', $token), 0, 24);
}

function ll_tools_word_grid_bulk_operation_write_option(string $option_name, array $value): bool {
    $existing = get_option($option_name, null);
    if ($existing === null) {
        return add_option($option_name, $value, '', 'no');
    }
    if ($existing === $value) {
        return true;
    }
    if (update_option($option_name, $value, false)) {
        return true;
    }
    return get_option($option_name, null) === $value;
}

function ll_tools_word_grid_bulk_operation_acquire_lock(string $state_key): string {
    global $wpdb;

    $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
    $now = time();
    $lease_seconds = ll_tools_word_grid_bulk_operation_lock_seconds();

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = wp_generate_uuid4();
        $payload = $token . '|' . ($now + $lease_seconds);
        if (add_option($lock_key, $payload, '', 'no')) {
            return $token;
        }

        $existing = (string) get_option($lock_key, '');
        $parts = explode('|', $existing, 2);
        $expires_at = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($existing === '' || $expires_at >= $now) {
            return '';
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $lock_key,
            $existing
        ));
        if ((int) $deleted !== 1) {
            return '';
        }
        wp_cache_delete($lock_key, 'options');
    }

    return '';
}

function ll_tools_word_grid_bulk_operation_renew_lock(string $state_key, string $lock_token): bool {
    global $wpdb;

    if ($state_key === '' || $lock_token === '') {
        return false;
    }

    $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
    $existing = (string) get_option($lock_key, '');
    $parts = explode('|', $existing, 2);
    if (($parts[0] ?? '') !== $lock_token || (int) ($parts[1] ?? 0) < time()) {
        return false;
    }

    $renewed = $lock_token . '|' . (time() + ll_tools_word_grid_bulk_operation_lock_seconds());
    if ($renewed === $existing) {
        return true;
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        $renewed,
        $lock_key,
        $existing
    ));
    wp_cache_delete($lock_key, 'options');
    return (int) $updated === 1 || (string) get_option($lock_key, '') === $renewed;
}

function ll_tools_word_grid_bulk_operation_write_locked_option(
    string $state_key,
    string $lock_token,
    string $option_name,
    array $value
): bool {
    global $wpdb;

    if (!ll_tools_word_grid_bulk_operation_renew_lock($state_key, $lock_token)) {
        return false;
    }

    $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
    $lock_value = (string) get_option($lock_key, '');
    if (strpos($lock_value, $lock_token . '|') !== 0) {
        return false;
    }

    $serialized = maybe_serialize($value);
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $option_name
    ));
    if ($exists === null) {
        add_option($option_name, [], '', 'no');
        do_action(
            'll_tools_word_grid_bulk_operation_placeholder_created',
            $state_key,
            $option_name,
            $lock_token
        );
    }
    $written = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} AS state_option
         INNER JOIN {$wpdb->options} AS lock_option
            ON lock_option.option_name = %s AND lock_option.option_value = %s
         SET state_option.option_value = %s, state_option.autoload = 'no'
         WHERE state_option.option_name = %s",
        $lock_key,
        $lock_value,
        $serialized,
        $option_name
    ));

    wp_cache_delete($option_name, 'options');
    if ($written === false || get_option($option_name, null) !== $value) {
        return false;
    }
    wp_cache_delete($lock_key, 'options');
    return (string) get_option($lock_key, '') === $lock_value;
}

function ll_tools_word_grid_bulk_operation_write_state(
    string $state_key,
    string $lock_token,
    array $state
): bool {
    return ll_tools_word_grid_bulk_operation_write_locked_option(
        $state_key,
        $lock_token,
        $state_key,
        $state
    );
}

function ll_tools_word_grid_bulk_operation_release_lock(string $state_key, string $lock_token): void {
    global $wpdb;

    if ($state_key === '' || $lock_token === '') {
        return;
    }

    $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
    $existing = (string) get_option($lock_key, '');
    if ($existing === '' || strpos($existing, $lock_token . '|') !== 0) {
        return;
    }

    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $lock_key,
        $existing
    ));
    if ((int) $deleted === 1) {
        wp_cache_delete($lock_key, 'options');
    }
}

function ll_tools_word_grid_bulk_operation_load(string $state_key): array {
    $state = get_option($state_key, []);
    return is_array($state) ? $state : [];
}

function ll_tools_word_grid_bulk_operation_mode_enabled(string $mode, int $wordset_id): bool {
    if ($mode === 'pos') {
        return true;
    }
    $callbacks = [
        'gender' => 'll_tools_wordset_has_grammatical_gender',
        'plurality' => 'll_tools_wordset_has_plurality',
        'verb_tense' => 'll_tools_wordset_has_verb_tense',
        'verb_mood' => 'll_tools_wordset_has_verb_mood',
    ];
    $callback = (string) ($callbacks[$mode] ?? '');
    return $callback !== '' && function_exists($callback) && (bool) $callback($wordset_id);
}

function ll_tools_word_grid_bulk_operation_summary(array $state): array {
    $status = sanitize_key((string) ($state['status'] ?? ''));
    $chunk_count = max(0, (int) ($state['chunk_count'] ?? 0));
    return [
        'token' => (string) ($state['token'] ?? ''),
        'mode' => (string) ($state['mode'] ?? ''),
        'control_key' => ll_tools_word_grid_bulk_operation_control_key((string) ($state['mode'] ?? '')),
        'request_field' => (string) ($state['request_field'] ?? ''),
        'request_value' => (string) ($state['request_value'] ?? ''),
        'status' => $status,
        'processed' => max(0, (int) ($state['processed'] ?? 0)),
        'updated' => max(0, (int) ($state['updated'] ?? 0)),
        'skipped' => max(0, (int) ($state['skipped'] ?? 0)),
        'can_continue' => $status === 'running' && ll_tools_word_grid_bulk_operation_mode_enabled(
            (string) ($state['mode'] ?? ''),
            (int) ($state['wordset_id'] ?? 0)
        ),
        'can_undo' => $chunk_count > 0 && in_array($status, ['running', 'complete', 'undoing'], true),
        'expires_at' => max(0, (int) ($state['expires_at'] ?? 0)),
    ];
}

function ll_tools_word_grid_bulk_operation_is_current_scope(
    array $state,
    int $user_id,
    int $wordset_id,
    int $category_id,
    string $mode
): bool {
    return (int) ($state['schema'] ?? 0) === 1
        && (int) ($state['user_id'] ?? 0) === $user_id
        && (int) ($state['wordset_id'] ?? 0) === $wordset_id
        && (int) ($state['category_id'] ?? 0) === $category_id
        && (string) ($state['mode'] ?? '') === $mode
        && (string) ($state['token'] ?? '') !== '';
}

function ll_tools_word_grid_bulk_operation_schedule_cleanup(string $state_key, string $token, int $expires_at): void {
    $args = [$state_key, $token];
    wp_clear_scheduled_hook(LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK, $args);
    if ($expires_at > time()) {
        wp_schedule_single_event($expires_at + 1, LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK, $args);
    }
}

function ll_tools_word_grid_bulk_operation_snapshot_cleanup_batch_size(): int {
    return max(1, min(100, (int) apply_filters('ll_tools_word_grid_bulk_snapshot_cleanup_batch_size', 50)));
}

function ll_tools_word_grid_bulk_operation_schedule_snapshot_cleanup(string $cleanup_key, int $delay = 60): void {
    wp_clear_scheduled_hook(LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK, [$cleanup_key]);
    wp_schedule_single_event(time() + max(1, $delay), LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK, [$cleanup_key]);
}

function ll_tools_word_grid_bulk_operation_process_snapshot_cleanup(string $cleanup_key): void {
    $manifest = get_option($cleanup_key, []);
    if (!is_array($manifest) || empty($manifest)) {
        return;
    }

    $token = (string) ($manifest['token'] ?? '');
    $next_index = max(0, (int) ($manifest['next_index'] ?? 0));
    if ($token === '' || $next_index <= 0) {
        delete_option($cleanup_key);
        wp_clear_scheduled_hook(LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK, [$cleanup_key]);
        return;
    }

    ll_tools_word_grid_bulk_operation_schedule_snapshot_cleanup($cleanup_key);
    $state_key = (string) ($manifest['state_key'] ?? '');
    if ($state_key !== '') {
        $active_state = ll_tools_word_grid_bulk_operation_load($state_key);
        if (!empty($active_state) && (string) ($active_state['token'] ?? '') === $token) {
            return;
        }
    }

    $remaining = ll_tools_word_grid_bulk_operation_snapshot_cleanup_batch_size();
    while ($next_index > 0 && $remaining > 0) {
        delete_option(ll_tools_word_grid_bulk_operation_chunk_key($token, $next_index));
        $next_index--;
        $remaining--;
    }

    if ($next_index <= 0) {
        delete_option($cleanup_key);
        wp_clear_scheduled_hook(LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK, [$cleanup_key]);
        return;
    }

    $manifest['next_index'] = $next_index;
    if (ll_tools_word_grid_bulk_operation_write_option($cleanup_key, $manifest)) {
        ll_tools_word_grid_bulk_operation_schedule_snapshot_cleanup($cleanup_key);
    }
}
add_action(
    LL_TOOLS_WORD_GRID_BULK_SNAPSHOT_CLEANUP_HOOK,
    'll_tools_word_grid_bulk_operation_process_snapshot_cleanup'
);

function ll_tools_word_grid_bulk_operation_delete(
    string $state_key,
    string $expected_token = '',
    string $lock_token = ''
): bool {
    if (!preg_match('/^ll_tools_wgb_[a-f0-9]{32}$/', $state_key)) {
        return false;
    }

    $state = ll_tools_word_grid_bulk_operation_load($state_key);
    if (empty($state)) {
        return true;
    }

    $token = (string) ($state['token'] ?? '');
    if ($expected_token !== '' && ($token === '' || !hash_equals($expected_token, $token))) {
        return false;
    }

    if ($lock_token !== '' && !ll_tools_word_grid_bulk_operation_renew_lock($state_key, $lock_token)) {
        return false;
    }

    $chunk_count = max(0, (int) ($state['chunk_count'] ?? 0));
    $cleanup_key = ll_tools_word_grid_bulk_operation_cleanup_key($token);
    if ($chunk_count > 0) {
        $manifest = [
            'schema' => 1,
            'token' => $token,
            'state_key' => $state_key,
            'next_index' => $chunk_count,
            'created_at' => time(),
        ];
        $manifest_written = $lock_token !== ''
            ? ll_tools_word_grid_bulk_operation_write_locked_option(
                $state_key,
                $lock_token,
                $cleanup_key,
                $manifest
            )
            : ll_tools_word_grid_bulk_operation_write_option($cleanup_key, $manifest);
        if (!$manifest_written) {
            return false;
        }
        ll_tools_word_grid_bulk_operation_schedule_snapshot_cleanup($cleanup_key);
    }
    wp_clear_scheduled_hook(LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK, [$state_key, $token]);
    if ($lock_token !== '') {
        global $wpdb;
        $lock_key = ll_tools_word_grid_bulk_operation_lock_key($state_key);
        $lock_value = (string) get_option($lock_key, '');
        if (strpos($lock_value, $lock_token . '|') !== 0) {
            return false;
        }
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE state_option FROM {$wpdb->options} AS state_option
             INNER JOIN {$wpdb->options} AS lock_option
                ON lock_option.option_name = %s AND lock_option.option_value = %s
             WHERE state_option.option_name = %s",
            $lock_key,
            $lock_value,
            $state_key
        ));
        wp_cache_delete($state_key, 'options');
        if ((int) $deleted !== 1 && !empty(ll_tools_word_grid_bulk_operation_load($state_key))) {
            return false;
        }
    } elseif (!delete_option($state_key) && !empty(ll_tools_word_grid_bulk_operation_load($state_key))) {
        return false;
    }
    if ($chunk_count > 0) {
        ll_tools_word_grid_bulk_operation_process_snapshot_cleanup($cleanup_key);
    }
    return true;
}

function ll_tools_word_grid_bulk_operation_cleanup(string $state_key, string $token): void {
    $state = ll_tools_word_grid_bulk_operation_load($state_key);
    if (empty($state) || (string) ($state['token'] ?? '') !== $token) {
        return;
    }
    if ((int) ($state['expires_at'] ?? 0) > time()) {
        return;
    }

    $lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($state_key);
    if ($lock_token === '') {
        wp_schedule_single_event(time() + 30, LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK, [$state_key, $token]);
        return;
    }
    try {
        $state = ll_tools_word_grid_bulk_operation_load($state_key);
        if (!empty($state)
            && (string) ($state['token'] ?? '') === $token
            && (int) ($state['expires_at'] ?? 0) <= time()
        ) {
            ll_tools_word_grid_bulk_operation_delete($state_key, $token, $lock_token);
        }
    } finally {
        ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
    }
}
add_action(LL_TOOLS_WORD_GRID_BULK_CLEANUP_HOOK, 'll_tools_word_grid_bulk_operation_cleanup', 10, 2);

function ll_tools_word_grid_bulk_operation_error(string $code, string $message, int $status = 400, array $data = []): WP_Error {
    return new WP_Error($code, $message, array_merge(['status' => $status], $data));
}

function ll_tools_word_grid_bulk_operation_open(
    int $user_id,
    int $wordset_id,
    int $category_id,
    string $mode,
    string $submitted_token,
    string $request_value = ''
) {
    $state_key = ll_tools_word_grid_bulk_operation_state_key($user_id, $wordset_id, $category_id, $mode);
    $lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($state_key);
    if ($lock_token === '') {
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_busy',
            __('Another bulk request is already running. Try again shortly.', 'll-tools-text-domain'),
            409
        );
    }

    $state = ll_tools_word_grid_bulk_operation_load($state_key);
    if (!empty($state) && (int) ($state['expires_at'] ?? 0) <= time()) {
        ll_tools_word_grid_bulk_operation_delete(
            $state_key,
            (string) ($state['token'] ?? ''),
            $lock_token
        );
        $state = [];
    }

    if ($submitted_token !== '') {
        if (!ll_tools_word_grid_bulk_operation_is_current_scope($state, $user_id, $wordset_id, $category_id, $mode)
            || !hash_equals((string) ($state['token'] ?? ''), $submitted_token)
        ) {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_not_found',
                __('This bulk operation is no longer available.', 'll-tools-text-domain'),
                404
            );
        }
        if ((string) ($state['status'] ?? '') !== 'running') {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_not_running',
                __('This bulk operation cannot be continued.', 'll-tools-text-domain'),
                409,
                ['operation' => ll_tools_word_grid_bulk_operation_summary($state)]
            );
        }
    } else {
        foreach (ll_tools_word_grid_bulk_operation_modes() as $other_mode) {
            if ($other_mode === $mode) {
                continue;
            }
            $other_state_key = ll_tools_word_grid_bulk_operation_state_key(
                $user_id,
                $wordset_id,
                $category_id,
                $other_mode
            );
            $other_state = ll_tools_word_grid_bulk_operation_load($other_state_key);
            if (empty($other_state)) {
                continue;
            }
            $other_lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($other_state_key);
            if ($other_lock_token === '') {
                ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
                return ll_tools_word_grid_bulk_operation_error(
                    'll_tools_word_grid_bulk_busy',
                    __('Another bulk request is already running. Try again shortly.', 'll-tools-text-domain'),
                    409
                );
            }
            $other_state = ll_tools_word_grid_bulk_operation_load($other_state_key);
            $other_status = (string) ($other_state['status'] ?? '');
            if (!empty($other_state)
                && (int) ($other_state['expires_at'] ?? 0) > time()
                && in_array($other_status, ['running', 'undoing'], true)
            ) {
                ll_tools_word_grid_bulk_operation_release_lock($other_state_key, $other_lock_token);
                ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
                return ll_tools_word_grid_bulk_operation_error(
                    'll_tools_word_grid_bulk_conflict',
                    __('Finish or undo the interrupted bulk operation first.', 'll-tools-text-domain'),
                    409,
                    ['operation' => ll_tools_word_grid_bulk_operation_summary($other_state)]
                );
            }
            ll_tools_word_grid_bulk_operation_delete(
                $other_state_key,
                (string) ($other_state['token'] ?? ''),
                $other_lock_token
            );
            ll_tools_word_grid_bulk_operation_release_lock($other_state_key, $other_lock_token);
        }

        if (!empty($state) && in_array((string) ($state['status'] ?? ''), ['running', 'undoing'], true)) {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_conflict',
                __('Finish or undo the interrupted bulk operation first.', 'll-tools-text-domain'),
                409,
                ['operation' => ll_tools_word_grid_bulk_operation_summary($state)]
            );
        }
        if (!empty($state)) {
            ll_tools_word_grid_bulk_operation_delete(
                $state_key,
                (string) ($state['token'] ?? ''),
                $lock_token
            );
        }

        $now = time();
        $token = wp_generate_uuid4();
        $state = [
            'schema' => 1,
            'token' => $token,
            'user_id' => $user_id,
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'mode' => $mode,
            'request_field' => ll_tools_word_grid_bulk_operation_request_field($mode),
            'request_value' => $request_value,
            'status' => 'running',
            'after_id' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'chunk_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + ll_tools_word_grid_bulk_operation_retention_seconds(),
            'pending' => [],
        ];
        if (!ll_tools_word_grid_bulk_operation_write_state($state_key, $lock_token, $state)) {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_store_failed',
                __('Unable to start the bulk operation.', 'll-tools-text-domain'),
                500
            );
        }
        ll_tools_word_grid_bulk_operation_schedule_cleanup($state_key, $token, (int) $state['expires_at']);
    }

    return [
        'state_key' => $state_key,
        'lock_token' => $lock_token,
        'state' => $state,
    ];
}

function ll_tools_word_grid_bulk_operation_capture_snapshot(array $word_ids): array {
    $word_ids = array_values(array_filter(array_unique(array_map('intval', $word_ids)), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    update_meta_cache('post', $word_ids);
    $pos_map = function_exists('ll_tools_word_grid_collect_part_of_speech_terms')
        ? ll_tools_word_grid_collect_part_of_speech_terms($word_ids)
        : [];
    $rows = [];
    foreach ($word_ids as $word_id) {
        $rows[] = [
            'word_id' => $word_id,
            'part_of_speech' => (string) ($pos_map[$word_id]['slug'] ?? ''),
            'grammatical_gender' => trim((string) get_post_meta($word_id, 'll_grammatical_gender', true)),
            'grammatical_plurality' => trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true)),
            'verb_tense' => trim((string) get_post_meta($word_id, 'll_verb_tense', true)),
            'verb_mood' => trim((string) get_post_meta($word_id, 'll_verb_mood', true)),
        ];
    }
    return $rows;
}

function ll_tools_word_grid_bulk_operation_add_expected_values(array $rows, array $state): array {
    $mode = (string) ($state['mode'] ?? '');
    $request_value = (string) ($state['request_value'] ?? '');
    foreach ($rows as &$row) {
        $original = [
            'part_of_speech' => (string) ($row['part_of_speech'] ?? ''),
            'grammatical_gender' => (string) ($row['grammatical_gender'] ?? ''),
            'grammatical_plurality' => (string) ($row['grammatical_plurality'] ?? ''),
            'verb_tense' => (string) ($row['verb_tense'] ?? ''),
            'verb_mood' => (string) ($row['verb_mood'] ?? ''),
        ];
        $expected = $original;
        if ($mode === 'pos') {
            $expected['part_of_speech'] = $request_value;
            if ($request_value !== 'noun') {
                $expected['grammatical_gender'] = '';
                $expected['grammatical_plurality'] = '';
            }
            if ($request_value !== 'verb') {
                $expected['verb_tense'] = '';
                $expected['verb_mood'] = '';
            }
        } else {
            $field = ll_tools_word_grid_bulk_operation_request_field($mode);
            if ($field !== '') {
                $expected[$field] = $request_value;
            }
        }
        $row['_expected'] = $expected;
        $row['_restore_expected'] = $original;
    }
    unset($row);
    return $rows;
}

function ll_tools_word_grid_bulk_operation_comparison_fields(array $state): array {
    $mode = (string) ($state['mode'] ?? '');
    $fields = $mode === 'pos'
        ? ['part_of_speech', 'grammatical_gender', 'grammatical_plurality', 'verb_tense', 'verb_mood']
        : [ll_tools_word_grid_bulk_operation_request_field($mode)];
    return array_values(array_filter($fields));
}

function ll_tools_word_grid_bulk_operation_mark_write_failure(array $operation, array $failed_word_ids) {
    $state = (array) ($operation['state'] ?? []);
    if (!is_array($state['pending'] ?? null) || empty($state['pending'])) {
        return $operation;
    }
    $state['pending']['failed_current_rows'] = ll_tools_word_grid_bulk_operation_capture_snapshot($failed_word_ids);
    $state['updated_at'] = time();
    if (!ll_tools_word_grid_bulk_operation_write_state(
        (string) ($operation['state_key'] ?? ''),
        (string) ($operation['lock_token'] ?? ''),
        $state
    )) {
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('Unable to save bulk operation progress.', 'll-tools-text-domain'),
            500
        );
    }
    $operation['state'] = $state;
    return $operation;
}

function ll_tools_word_grid_bulk_operation_mark_undo_failure(
    array $operation,
    int $chunk_index,
    array $failed_word_ids
) {
    $state = (array) ($operation['state'] ?? []);
    $state['undo_failure'] = [
        'chunk_index' => max(1, $chunk_index),
        'current_rows' => ll_tools_word_grid_bulk_operation_capture_snapshot($failed_word_ids),
    ];
    $state['updated_at'] = time();
    if (!ll_tools_word_grid_bulk_operation_write_state(
        (string) ($operation['state_key'] ?? ''),
        (string) ($operation['lock_token'] ?? ''),
        $state
    )) {
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('Unable to save rollback progress.', 'll-tools-text-domain'),
            500
        );
    }
    $operation['state'] = $state;
    return $operation;
}

function ll_tools_word_grid_bulk_operation_filter_undo_rows(array $rows, array $state): array {
    $comparison_fields = ll_tools_word_grid_bulk_operation_comparison_fields($state);
    $word_ids = array_values(array_filter(array_map('intval', wp_list_pluck($rows, 'word_id'))));
    $current_rows = ll_tools_word_grid_bulk_operation_capture_snapshot($word_ids);
    $current_by_id = [];
    foreach ($current_rows as $current_row) {
        $current_by_id[(int) ($current_row['word_id'] ?? 0)] = $current_row;
    }
    $failed_current_by_id = [];
    $failure_sources = [
        (array) ($state['pending']['failed_current_rows'] ?? []),
        (array) ($state['undo_failure']['current_rows'] ?? []),
    ];
    foreach ($failure_sources as $failure_rows) {
        foreach ($failure_rows as $failure_row) {
            if (is_array($failure_row) && (int) ($failure_row['word_id'] ?? 0) > 0) {
                $failed_current_by_id[(int) $failure_row['word_id']] = $failure_row;
            }
        }
    }

    $safe_rows = [];
    $conflict_ids = [];
    $restored_ids = [];
    foreach ($rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        $expected = is_array($row['_expected'] ?? null) ? $row['_expected'] : [];
        $restore_expected = is_array($row['_restore_expected'] ?? null) ? $row['_restore_expected'] : [];
        $current = $current_by_id[$word_id] ?? [];
        $failed_current = $failed_current_by_id[$word_id] ?? [];
        $matches = $word_id > 0 && !empty($expected) && !empty($current);
        $already_restored = $word_id > 0 && !empty($restore_expected) && !empty($current);
        $matches_recorded_failure = $word_id > 0 && !empty($failed_current) && !empty($current);
        foreach ($comparison_fields as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($expected[$field] ?? '')) {
                $matches = false;
            }
            if ((string) ($current[$field] ?? '') !== (string) ($restore_expected[$field] ?? '')) {
                $already_restored = false;
            }
            if ((string) ($current[$field] ?? '') !== (string) ($failed_current[$field] ?? '')) {
                $matches_recorded_failure = false;
            }
        }
        if ($matches || $matches_recorded_failure) {
            $safe_rows[] = $row;
        } elseif ($already_restored) {
            $restored_ids[] = $word_id;
        } elseif ($word_id > 0) {
            $conflict_ids[] = $word_id;
        }
    }

    return [
        'rows' => $safe_rows,
        'conflict_ids' => array_values(array_unique($conflict_ids)),
        'restored_ids' => array_values(array_unique($restored_ids)),
    ];
}

function ll_tools_word_grid_bulk_operation_prepare_batch(
    array $operation,
    array $batch,
    array $candidate_ids,
    array $target_ids
) {
    $state = (array) ($operation['state'] ?? []);
    $state_key = (string) ($operation['state_key'] ?? '');
    $lock_token = (string) ($operation['lock_token'] ?? '');
    $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];

    if (empty($pending)) {
        $candidate_ids = array_values(array_map('intval', $candidate_ids));
        $target_ids = array_values(array_map('intval', $target_ids));
        $chunk_index = empty($target_ids) ? 0 : max(0, (int) ($state['chunk_count'] ?? 0)) + 1;
        $pending = [
            'phase' => 'preparing',
            'candidate_ids' => $candidate_ids,
            'target_ids' => $target_ids,
            'batch' => [
                'next_after_id' => max(0, (int) ($batch['next_after_id'] ?? $state['after_id'] ?? 0)),
                'has_more' => !empty($batch['has_more']),
                'scanned' => max(0, (int) ($batch['scanned'] ?? count($candidate_ids))),
            ],
            'chunk_index' => $chunk_index,
        ];
        $state['pending'] = $pending;
        if ($chunk_index > 0) {
            $state['chunk_count'] = $chunk_index;
        }
        $state['updated_at'] = time();
        if (!ll_tools_word_grid_bulk_operation_write_state($state_key, $lock_token, $state)) {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_store_failed',
                __('Unable to save bulk operation progress.', 'll-tools-text-domain'),
                500
            );
        }
    }

    $target_ids = array_values(array_map('intval', (array) ($pending['target_ids'] ?? [])));
    $chunk_index = max(0, (int) ($pending['chunk_index'] ?? 0));
    if ($chunk_index <= 0 || empty($target_ids)) {
        $state['pending']['phase'] = 'prepared';
    } else {
        $chunk_key = ll_tools_word_grid_bulk_operation_chunk_key((string) $state['token'], $chunk_index);
        $snapshot = get_option($chunk_key, null);
        $phase = sanitize_key((string) ($pending['phase'] ?? 'prepared'));
        if ($phase === 'prepared' && (!is_array($snapshot) || empty($snapshot))) {
            ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_snapshot_missing',
                __('Rollback data for this bulk operation is incomplete.', 'll-tools-text-domain'),
                500
            );
        }
        if (!is_array($snapshot) || empty($snapshot)) {
            if (!ll_tools_word_grid_bulk_operation_renew_lock($state_key, $lock_token)) {
                ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
                return ll_tools_word_grid_bulk_operation_error(
                    'll_tools_word_grid_bulk_lease_lost',
                    __('Another bulk request took over this operation. Reload before continuing.', 'll-tools-text-domain'),
                    409
                );
            }
            $snapshot = ll_tools_word_grid_bulk_operation_add_expected_values(
                ll_tools_word_grid_bulk_operation_capture_snapshot($target_ids),
                $state
            );
            if (empty($snapshot) || !ll_tools_word_grid_bulk_operation_write_locked_option(
                $state_key,
                $lock_token,
                $chunk_key,
                $snapshot
            )) {
                ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
                return ll_tools_word_grid_bulk_operation_error(
                    'll_tools_word_grid_bulk_snapshot_failed',
                    __('Unable to save rollback data for this bulk batch.', 'll-tools-text-domain'),
                    500
                );
            }
        }
        $state['pending']['phase'] = 'prepared';
    }

    $state['updated_at'] = time();
    if (!ll_tools_word_grid_bulk_operation_write_state($state_key, $lock_token, $state)) {
        ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('Unable to save bulk operation progress.', 'll-tools-text-domain'),
            500
        );
    }

    $operation['state'] = $state;
    return $operation;
}

function ll_tools_word_grid_bulk_operation_finalize_batch(array $operation, int $updated_count) {
    $state = (array) ($operation['state'] ?? []);
    $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];
    $batch = is_array($pending['batch'] ?? null) ? $pending['batch'] : [];
    $candidate_ids = array_values(array_map('intval', (array) ($pending['candidate_ids'] ?? [])));
    $target_ids = array_values(array_map('intval', (array) ($pending['target_ids'] ?? [])));

    $state['after_id'] = max((int) ($state['after_id'] ?? 0), (int) ($batch['next_after_id'] ?? 0));
    $state['processed'] = max(0, (int) ($state['processed'] ?? 0)) + count($candidate_ids);
    $state['updated'] = max(0, (int) ($state['updated'] ?? 0)) + max(0, $updated_count);
    $state['skipped'] = max(0, (int) ($state['skipped'] ?? 0)) + max(0, count($candidate_ids) - count($target_ids));
    $state['status'] = !empty($batch['has_more']) ? 'running' : 'complete';
    $state['pending'] = [];
    $state['updated_at'] = time();
    if ($state['status'] === 'complete') {
        $state['expires_at'] = time() + ll_tools_word_grid_bulk_operation_retention_seconds();
    }

    if (!ll_tools_word_grid_bulk_operation_write_state(
        (string) $operation['state_key'],
        (string) $operation['lock_token'],
        $state
    )) {
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) $operation['state_key'],
            (string) $operation['lock_token']
        );
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('The changes were applied, but progress could not be saved. Reload to retry or undo.', 'll-tools-text-domain'),
            500
        );
    }
    if ($state['status'] === 'complete') {
        ll_tools_word_grid_bulk_operation_schedule_cleanup(
            (string) $operation['state_key'],
            (string) $state['token'],
            (int) $state['expires_at']
        );
    }
    ll_tools_word_grid_bulk_operation_release_lock(
        (string) $operation['state_key'],
        (string) $operation['lock_token']
    );
    return $state;
}

function ll_tools_word_grid_bulk_operation_update_pending_targets(array $operation, array $target_ids) {
    $state = (array) ($operation['state'] ?? []);
    if (!is_array($state['pending'] ?? null) || empty($state['pending'])) {
        return $operation;
    }

    $state['pending']['target_ids'] = array_values(array_filter(array_unique(array_map('intval', $target_ids))));
    $state['updated_at'] = time();
    if (!ll_tools_word_grid_bulk_operation_write_state(
        (string) ($operation['state_key'] ?? ''),
        (string) ($operation['lock_token'] ?? ''),
        $state
    )) {
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) ($operation['state_key'] ?? ''),
            (string) ($operation['lock_token'] ?? '')
        );
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('Unable to save bulk operation progress.', 'll-tools-text-domain'),
            500
        );
    }
    $operation['state'] = $state;
    return $operation;
}

function ll_tools_word_grid_bulk_operation_verify_restored_rows(array $rows, array $state): array {
    $comparison_fields = ll_tools_word_grid_bulk_operation_comparison_fields($state);
    $word_ids = array_values(array_filter(array_map('intval', wp_list_pluck($rows, 'word_id'))));
    $current_by_id = [];
    foreach (ll_tools_word_grid_bulk_operation_capture_snapshot($word_ids) as $current_row) {
        $current_by_id[(int) ($current_row['word_id'] ?? 0)] = $current_row;
    }

    $failed_ids = [];
    foreach ($rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        $expected = is_array($row['_restore_expected'] ?? null) ? $row['_restore_expected'] : [];
        $current = $current_by_id[$word_id] ?? [];
        $matches = $word_id > 0 && !empty($expected) && !empty($current);
        foreach ($comparison_fields as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($expected[$field] ?? '')) {
                $matches = false;
            }
        }
        if (!$matches && $word_id > 0) {
            $failed_ids[] = $word_id;
        }
    }
    return array_values(array_unique($failed_ids));
}

function ll_tools_word_grid_bulk_operation_open_existing(
    int $user_id,
    int $wordset_id,
    int $category_id,
    string $mode,
    string $submitted_token
) {
    $state_key = ll_tools_word_grid_bulk_operation_state_key($user_id, $wordset_id, $category_id, $mode);
    $lock_token = ll_tools_word_grid_bulk_operation_acquire_lock($state_key);
    if ($lock_token === '') {
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_busy',
            __('Another bulk request is already running. Try again shortly.', 'll-tools-text-domain'),
            409
        );
    }

    $state = ll_tools_word_grid_bulk_operation_load($state_key);
    if (!ll_tools_word_grid_bulk_operation_is_current_scope($state, $user_id, $wordset_id, $category_id, $mode)
        || $submitted_token === ''
        || !hash_equals((string) ($state['token'] ?? ''), $submitted_token)
        || (int) ($state['expires_at'] ?? 0) <= time()
    ) {
        ll_tools_word_grid_bulk_operation_release_lock($state_key, $lock_token);
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_not_found',
            __('This bulk operation is no longer available.', 'll-tools-text-domain'),
            404
        );
    }

    return [
        'state_key' => $state_key,
        'lock_token' => $lock_token,
        'state' => $state,
    ];
}

function ll_tools_word_grid_bulk_operation_get_undo_chunk(array $operation) {
    $state = (array) ($operation['state'] ?? []);
    $chunk_index = isset($state['undo_next'])
        ? max(0, (int) $state['undo_next'])
        : max(0, (int) ($state['chunk_count'] ?? 0));
    if ($chunk_index <= 0) {
        return [];
    }

    $chunk = get_option(
        ll_tools_word_grid_bulk_operation_chunk_key((string) ($state['token'] ?? ''), $chunk_index),
        null
    );
    if (!is_array($chunk) || empty($chunk)) {
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) ($operation['state_key'] ?? ''),
            (string) ($operation['lock_token'] ?? '')
        );
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_snapshot_missing',
            __('Rollback data for this bulk operation is incomplete.', 'll-tools-text-domain'),
            500
        );
    }
    return [
        'index' => $chunk_index,
        'rows' => array_values(array_filter($chunk, 'is_array')),
    ];
}

function ll_tools_word_grid_bulk_operation_finish_undo_chunk(array $operation, int $chunk_index) {
    $state = (array) ($operation['state'] ?? []);
    $next_index = max(0, $chunk_index - 1);
    if ($next_index <= 0) {
        if (!ll_tools_word_grid_bulk_operation_delete(
            (string) $operation['state_key'],
            (string) $state['token'],
            (string) $operation['lock_token']
        )) {
            ll_tools_word_grid_bulk_operation_release_lock(
                (string) $operation['state_key'],
                (string) $operation['lock_token']
            );
            return ll_tools_word_grid_bulk_operation_error(
                'll_tools_word_grid_bulk_store_failed',
                __('The rollback was applied, but its progress could not be saved. Reload to retry.', 'll-tools-text-domain'),
                500
            );
        }
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) $operation['state_key'],
            (string) $operation['lock_token']
        );
        return [
            'has_more' => false,
            'operation' => [],
        ];
    }

    $state['status'] = 'undoing';
    $state['undo_next'] = $next_index;
    unset($state['undo_failure']);
    $state['updated_at'] = time();
    if (!ll_tools_word_grid_bulk_operation_write_state(
        (string) $operation['state_key'],
        (string) $operation['lock_token'],
        $state
    )) {
        ll_tools_word_grid_bulk_operation_release_lock(
            (string) $operation['state_key'],
            (string) $operation['lock_token']
        );
        return ll_tools_word_grid_bulk_operation_error(
            'll_tools_word_grid_bulk_store_failed',
            __('The rollback was applied, but its progress could not be saved. Reload to retry.', 'll-tools-text-domain'),
            500
        );
    }
    delete_option(ll_tools_word_grid_bulk_operation_chunk_key((string) $state['token'], $chunk_index));
    ll_tools_word_grid_bulk_operation_release_lock(
        (string) $operation['state_key'],
        (string) $operation['lock_token']
    );
    return [
        'has_more' => true,
        'operation' => ll_tools_word_grid_bulk_operation_summary($state),
    ];
}

add_action('wp_ajax_ll_tools_word_grid_bulk_status', 'll_tools_word_grid_bulk_status_handler');
function ll_tools_word_grid_bulk_status_handler(): void {
    check_ajax_referer('ll_word_grid_edit', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', 'll-tools-text-domain')], 401);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(['message' => __('Missing word set or category.', 'll-tools-text-domain')], 400);
    }
    if (!term_exists($category_id, 'word-category')) {
        wp_send_json_error(['message' => __('Invalid category.', 'll-tools-text-domain')], 400);
    }
    if (!function_exists('ll_tools_word_grid_user_can_manage_wordset_scope')
        || !ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)
    ) {
        wp_send_json_error(['message' => __('Forbidden', 'll-tools-text-domain')], 403);
    }

    $user_id = get_current_user_id();
    $operations = [];
    foreach (ll_tools_word_grid_bulk_operation_modes() as $mode) {
        $state_key = ll_tools_word_grid_bulk_operation_state_key($user_id, $wordset_id, $category_id, $mode);
        $state = ll_tools_word_grid_bulk_operation_load($state_key);
        if (empty($state)) {
            continue;
        }
        if ((int) ($state['expires_at'] ?? 0) <= time()) {
            ll_tools_word_grid_bulk_operation_cleanup($state_key, (string) ($state['token'] ?? ''));
            continue;
        }
        if (!ll_tools_word_grid_bulk_operation_is_current_scope($state, $user_id, $wordset_id, $category_id, $mode)) {
            continue;
        }
        $operations[ll_tools_word_grid_bulk_operation_control_key($mode)] = ll_tools_word_grid_bulk_operation_summary($state);
    }

    wp_send_json_success(['operations' => $operations]);
}
