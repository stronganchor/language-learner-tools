<?php
if (!defined('WPINC')) { die; }

/**
 * Serialize destructive job steps for the lifetime of their database connection.
 * An expiring option alone cannot fence a still-running worker's side effects.
 */
function ll_tools_mutation_job_acquire(string $family, string $job_id) {
    global $wpdb;

    $name = 'll_job_' . hash('sha256', DB_NAME . '|' . $wpdb->options . '|' . $family . '|' . $job_id);
    $name = substr($name, 0, 64);
    if (isset($GLOBALS['ll_tools_mutation_job_owners'][$name])) {
        return new WP_Error('ll_tools_mutation_job_locked', __('This job is already processing. Wait and retry.', 'll-tools-text-domain'), ['status' => 429, 'retry_after_seconds' => 1]);
    }
    $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
    if ((string) $acquired !== '1') {
        return new WP_Error('ll_tools_mutation_job_locked', __('This job is already processing or its lock is unavailable. Wait and retry.', 'll-tools-text-domain'), ['status' => 429, 'retry_after_seconds' => 1]);
    }
    $lease = [
        'name' => $name,
        'token' => wp_generate_uuid4(),
        'connection_id' => (string) $wpdb->get_var('SELECT CONNECTION_ID()'),
    ];
    $GLOBALS['ll_tools_mutation_job_owners'][$name] = $lease;
    if (!ll_tools_mutation_job_owns($lease)) {
        ll_tools_mutation_job_release($lease);
        return new WP_Error('ll_tools_mutation_job_lock_lost', __('Job ownership could not be verified.', 'll-tools-text-domain'), ['status' => 503]);
    }
    return $lease;
}

function ll_tools_mutation_job_owns(array $lease): bool {
    global $wpdb;

    $name = (string) ($lease['name'] ?? '');
    $owner = $GLOBALS['ll_tools_mutation_job_owners'][$name] ?? [];
    if ($name === '' || empty($lease['token']) || $owner !== $lease || empty($lease['connection_id'])) {
        return false;
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT CONNECTION_ID() AS current_id, IS_USED_LOCK(%s) AS owner_id', $name), ARRAY_A);
    return is_array($row)
        && (string) ($row['current_id'] ?? '') === (string) $lease['connection_id']
        && (string) ($row['owner_id'] ?? '') === (string) $lease['connection_id'];
}

function ll_tools_mutation_job_release(array $lease): void {
    global $wpdb;

    $name = (string) ($lease['name'] ?? '');
    if (($GLOBALS['ll_tools_mutation_job_owners'][$name] ?? null) !== $lease) {
        return;
    }
    if (ll_tools_mutation_job_owns($lease)) {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
    unset($GLOBALS['ll_tools_mutation_job_owners'][$name]);
}

/** Read from storage, bypassing stale request/object-cache option snapshots. */
function ll_tools_mutation_job_read_option(string $option) {
    global $wpdb;

    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
    if ($wpdb->last_error !== '') {
        return new WP_Error('ll_tools_mutation_job_storage_failed', __('The job state could not be read from storage.', 'll-tools-text-domain'), ['status' => 503]);
    }
    return $value === null ? null : maybe_unserialize($value);
}

/**
 * Compare-and-swap one exact stored checkpoint under connection ownership.
 * The fence is part of the mutation SQL, including initial marker creation.
 */
function ll_tools_mutation_job_write_option(string $option, array $value, array $lease = []) {
    global $wpdb;

    if (!empty($lease) && !ll_tools_mutation_job_owns($lease)) {
        return new WP_Error('ll_tools_mutation_job_lock_lost', __('Job ownership was lost. Read back the job before continuing.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $old_raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
    if ($wpdb->last_error !== '') {
        return new WP_Error('ll_tools_mutation_job_storage_failed', __('The job state could not be read from storage.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $old_value = $old_raw === null ? false : maybe_unserialize($old_raw);
    $candidate = apply_filters("pre_update_option_{$option}", $value, $old_value, $option);
    $candidate = apply_filters('pre_update_option', $candidate, $option, $old_value);
    if ($candidate !== $value) {
        return new WP_Error('ll_tools_mutation_job_storage_failed', __('The job checkpoint could not be saved. Read back the job before continuing.', 'll-tools-text-domain'), ['status' => 503]);
    }
    // Filters can release/reacquire on the same connection. Check the exact
    // request token again as well as the connection fence in the mutation SQL.
    if (!empty($lease) && !ll_tools_mutation_job_owns($lease)) {
        return new WP_Error('ll_tools_mutation_job_lock_lost', __('Job ownership was lost. Read back the job before continuing.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $new_raw = maybe_serialize($value);
    $fence = '';
    $fence_args = [];
    if (!empty($lease)) {
        $fence = ' AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d';
        $fence_args = [(int) $lease['connection_id'], (string) $lease['name'], (int) $lease['connection_id']];
    }
    if ($old_raw === null) {
        $written = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'off' FROM DUAL WHERE 1=1" . $fence,
            array_merge([$option, $new_raw], $fence_args)
        ));
    } else {
        $written = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off'
             WHERE option_name = %s AND BINARY option_value = BINARY %s" . $fence,
            array_merge([$new_raw, $option, $old_raw], $fence_args)
        ));
    }
    wp_cache_delete($option, 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options');
    if (!empty($lease) && !ll_tools_mutation_job_owns($lease)) {
        return new WP_Error('ll_tools_mutation_job_lock_lost', __('Job ownership was lost. Read back the job before continuing.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $saved = ll_tools_mutation_job_read_option($option);
    if ($written === false || ($written !== 1 && $old_raw !== $new_raw)
        || is_wp_error($saved) || maybe_serialize($saved) !== $new_raw) {
        return new WP_Error('ll_tools_mutation_job_storage_failed', __('The job checkpoint could not be saved. Read back the job before continuing.', 'll-tools-text-domain'), ['status' => 503]);
    }
    if ($old_raw !== null && $old_raw !== $new_raw) {
        do_action("update_option_{$option}", $old_value, $value, $option);
        do_action('updated_option', $option, $old_value, $value);
    } elseif ($old_raw === null) {
        do_action("add_option_{$option}", $option, $value);
        do_action('added_option', $option, $value);
    }
    return $saved;
}

/** Delete only the exact checkpoint still owned by this connection. */
function ll_tools_mutation_job_delete_option(string $option, array $expected, array $lease) {
    global $wpdb;

    if (!ll_tools_mutation_job_owns($lease)) {
        return ll_tools_mutation_job_recovery_error();
    }
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s
         AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d",
        $option,
        maybe_serialize($expected),
        (int) $lease['connection_id'],
        (string) $lease['name'],
        (int) $lease['connection_id']
    ));
    wp_cache_delete($option, 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options');
    if ($deleted !== 1 || !ll_tools_mutation_job_owns($lease) || ll_tools_mutation_job_read_option($option) !== null) {
        return ll_tools_mutation_job_recovery_error();
    }
    do_action("delete_option_{$option}", $option);
    do_action('deleted_option', $option);
    return true;
}

function ll_tools_mutation_job_recovery_error(): WP_Error {
    return new WP_Error(
        'll_tools_mutation_job_recovery_required',
        __('An earlier job step did not finish checkpointing. Review its applied changes and saved state before recovery; automatic replay is blocked.', 'll-tools-text-domain'),
        ['status' => 409, 'recovery_required' => true]
    );
}
