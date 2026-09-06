<?php
/** Portable serialization for recording text and review metadata, including MyISAM. */
if (!defined('WPINC')) { die; }
require_once __DIR__ . '/mutation-job-state.php';

function ll_tools_recording_write_acquire(int $recording_id) {
    $active = $GLOBALS['ll_tools_recording_write_scopes'][$recording_id] ?? null;
    if (is_array($active)) {
        if (!ll_tools_mutation_job_owns($active['lease'])) {
            return new WP_Error('recording_write_lost', __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
        }
        $GLOBALS['ll_tools_recording_write_scopes'][$recording_id]['depth']++;
        return ['recording_id' => $recording_id, 'lease' => $active['lease']];
    }
    $lease = ll_tools_mutation_job_acquire('recording_metadata', (string) $recording_id);
    if (is_wp_error($lease)) { return $lease; }
    unset($GLOBALS['ll_tools_recording_write_errors'][$recording_id]);
    $GLOBALS['ll_tools_recording_write_scopes'][$recording_id] = ['lease' => $lease, 'depth' => 1];
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id LIMIT 1001", $recording_id), ARRAY_A);
    $meta = [];
    $expected = [];
    foreach ((array) $rows as $row) {
        $meta[$row['meta_key']][] = $row['meta_value'];
        $expected[$row['meta_key']][] = ['meta_id' => $row['meta_id'], 'meta_value' => $row['meta_value']];
    }
    $invalid = $wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1000;
    foreach ($meta as $key => $values) {
        if (ll_tools_recording_metadata_is_shared_field($recording_id, $key) && count($values) > 1) { $invalid = true; }
    }
    if ($invalid) {
        ll_tools_recording_write_release(['recording_id' => $recording_id, 'lease' => $lease]);
        return new WP_Error('recording_write_failed', __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $GLOBALS['ll_tools_recording_write_scopes'][$recording_id]['expected'] = $expected;
    wp_cache_set($recording_id, $meta, 'post_meta');
    return ['recording_id' => $recording_id, 'lease' => $lease];
}

function ll_tools_recording_write_release(array $scope): void {
    $id = (int) ($scope['recording_id'] ?? 0);
    $active = $GLOBALS['ll_tools_recording_write_scopes'][$id] ?? null;
    if (!is_array($active) || $active['lease'] !== ($scope['lease'] ?? null)) { return; }
    $GLOBALS['ll_tools_recording_write_scopes'][$id]['depth']--;
    if ($GLOBALS['ll_tools_recording_write_scopes'][$id]['depth'] <= 0) {
        unset($GLOBALS['ll_tools_recording_write_scopes'][$id]);
        ll_tools_mutation_job_release($active['lease']);
    }
}

function ll_tools_recording_write_error(int $id): ?WP_Error {
    return $GLOBALS['ll_tools_recording_write_errors'][$id] ?? null;
}

function ll_tools_recording_metadata_is_shared_field(int $id, string $key): bool {
    return in_array($key, ['recording_text', 'recording_ipa', 'recording_translation',
        'll_auto_transcription_review_note', 'll_auto_transcription_review_fields', 'll_auto_transcription_needs_review'], true)
        && get_post_type($id) === 'word_audio';
}

/** Fence WordPress-generated metadata SQL itself, including reconnect retries. */
function ll_tools_recording_write_fence_query(string $sql, array $lease, int $id, string $key, array $before): string {
    global $wpdb;
    $table = preg_quote($wpdb->postmeta, '/');
    if (!preg_match('/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?' . $table . '`?\s/i', $sql, $match)) { return $sql; }
    $kind = strtoupper(trim($match[1]));
    $is_insert = str_starts_with($kind, 'INSERT');
    if ($is_insert) {
        $prefix = $wpdb->prepare('VALUES (%d, %s,', $id, $key);
        if (!str_contains($sql, $prefix)) { return $sql; }
    } elseif ($kind === 'UPDATE') {
        if (!preg_match('/`post_id`\s*=\s*' . $id . '(?![0-9])/', $sql)
            || !str_contains($sql, $wpdb->prepare('`meta_key` = %s', $key))) { return $sql; }
    } else {
        if (!preg_match('/WHERE\s+meta_id\s+IN\s*\(([^)]*)\)/i', $sql)) { return $sql; }
    }
    $owner = $GLOBALS['ll_tools_mutation_job_owners'][$lease['name']] ?? null;
    $fence = $owner === $lease
        ? $wpdb->prepare('CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d', (int) $lease['connection_id'], $lease['name'], (int) $lease['connection_id'])
        : '1 = 0';
    if ($is_insert) {
        if (preg_match('/^(\s*INSERT\s+INTO\s+`?' . $table . '`?\s*\([^)]*\))\s+VALUES\s*\((.*)\)\s*$/is', $sql, $parts)) {
            $absence = $wpdb->prepare("NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s)", $id, $key);
            return $parts[1] . ' SELECT ' . $parts[2] . ' FROM DUAL WHERE ' . $fence . ' AND ' . ($before ? '1 = 0' : $absence);
        }
        throw new RuntimeException('recording_metadata_insert_shape');
    }
    $preimage = count($before) === 1
        ? $wpdb->prepare('meta_id = %d AND BINARY meta_value = BINARY %s', (int) $before[0]['meta_id'], $before[0]['meta_value'])
        : '1 = 0';
    if ($kind === 'DELETE FROM') {
        // A hook can replace/add rows after WordPress selects IDs. Protect every
        // row for this exact field while unrelated hook metadata remains writable.
        return $sql . $wpdb->prepare(' AND (post_id <> %d OR meta_key <> %s OR (', $id, $key) . $fence . ' AND ' . $preimage . '))';
    }
    return $sql . ' AND (' . $fence . ') AND (' . $preimage . ')';
}

/** Retain WordPress sanitization/actions; never replace its metadata hooks with raw SQL. */
function ll_tools_recording_write_meta(string $operation, int $id, string $key, $value, $extra = '') {
    global $wpdb;
    $scope = ll_tools_recording_write_acquire($id);
    if (is_wp_error($scope)) {
        $GLOBALS['ll_tools_recording_write_errors'][$id] = $scope;
        return false;
    }
    try {
        if (ll_tools_recording_write_error($id)) { return false; }
        wp_cache_delete($id, 'post_meta');
        $before = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 2", $id, $key), ARRAY_A);
        $expected_before = $GLOBALS['ll_tools_recording_write_scopes'][$id]['expected'][$key] ?? [];
        if ($wpdb->last_error !== '' || !is_array($before) || count($before) > 1 || $before !== $expected_before) {
            throw new RuntimeException('recording_metadata_read_failed');
        }
        if ($operation === 'add' && $before && !$extra) { throw new RuntimeException('recording_metadata_duplicate_add'); }
        if (!ll_tools_mutation_job_owns($scope['lease'])) { throw new RuntimeException('recording_write_lost'); }
        $expected_value = wp_unslash($value);
        $capture_value = static function ($check, $object_id, $meta_key, $meta_value) use ($id, $key, &$expected_value) {
            if ((int) $object_id === $id && $meta_key === $key) { $expected_value = $meta_value; }
            return $check;
        };
        add_filter('update_post_metadata', $capture_value, PHP_INT_MAX, 4);
        add_filter('add_post_metadata', $capture_value, PHP_INT_MAX, 4);
        $fence_query = static fn($sql) => ll_tools_recording_write_fence_query($sql, $scope['lease'], $id, $key, $before);
        add_filter('query', $fence_query, PHP_INT_MAX);
        try {
            if ($operation === 'delete') { $result = delete_post_meta($id, $key, $value); }
            elseif ($operation === 'add') { $result = add_post_meta($id, $key, $value, (bool) $extra); }
            else { $result = update_post_meta($id, $key, $value, $extra); }
        } finally {
            remove_filter('query', $fence_query, PHP_INT_MAX);
            remove_filter('update_post_metadata', $capture_value, PHP_INT_MAX);
            remove_filter('add_post_metadata', $capture_value, PHP_INT_MAX);
        }
        wp_cache_delete($id, 'post_meta');
        $after_rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 2", $id, $key), ARRAY_A);
        $after = array_column((array) $after_rows, 'meta_value');
        if ($wpdb->last_error !== '' || count($after) > 1 || !ll_tools_mutation_job_owns($scope['lease'])) {
            throw new RuntimeException('recording_metadata_read_failed');
        }
        $wanted = (string) maybe_serialize($expected_value);
        if ($operation === 'delete') {
            $should_delete = $value === '' || ($before && $before[0]['meta_value'] === $wanted);
            $verified = $should_delete ? $after === [] : $after === array_column($before, 'meta_value');
        } elseif (($operation === 'add' && $before && $extra)
            || ($operation === 'update' && !empty($extra) && $before && $before[0]['meta_value'] !== (string) maybe_serialize($extra))) {
            $verified = $after === array_column($before, 'meta_value');
        } else {
            $verified = count($after) === 1 && $after[0] === $wanted;
        }
        if (!$verified) { throw new RuntimeException('recording_metadata_write_failed'); }
        $GLOBALS['ll_tools_recording_write_scopes'][$id]['expected'][$key] = $after_rows;
        return $result;
    } catch (Throwable $error) {
        $GLOBALS['ll_tools_recording_write_errors'][$id] = new WP_Error('recording_write_failed',
            __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
        return false;
    } finally { ll_tools_recording_write_release($scope); }
}

function ll_tools_recording_update_post_meta($id, $key, $value, $previous = '') {
    return ll_tools_recording_metadata_is_shared_field((int) $id, (string) $key)
        ? ll_tools_recording_write_meta('update', (int) $id, (string) $key, $value, $previous)
        : update_post_meta($id, $key, $value, $previous);
}
function ll_tools_recording_add_post_meta($id, $key, $value, $unique = false) {
    return ll_tools_recording_metadata_is_shared_field((int) $id, (string) $key)
        ? ll_tools_recording_write_meta('add', (int) $id, (string) $key, $value, $unique)
        : add_post_meta($id, $key, $value, $unique);
}
function ll_tools_recording_delete_post_meta($id, $key, $value = '') {
    return ll_tools_recording_metadata_is_shared_field((int) $id, (string) $key)
        ? ll_tools_recording_write_meta('delete', (int) $id, (string) $key, $value)
        : delete_post_meta($id, $key, $value);
}

/** Hold one fence across a logical field/review change, including absent metadata. */
function ll_tools_recording_write_run(int $id, callable $callback) {
    $scope = ll_tools_recording_write_acquire($id);
    if (is_wp_error($scope)) {
        $GLOBALS['ll_tools_recording_write_errors'][$id] = $scope;
        return $scope;
    }
    try {
        $error = ll_tools_recording_write_error($id);
        if ($error) { return $error; }
        $result = $callback();
        return ll_tools_recording_write_error($id) ?: $result;
    } finally { ll_tools_recording_write_release($scope); }
}
