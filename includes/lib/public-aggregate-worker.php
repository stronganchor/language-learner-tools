<?php
if (!defined('WPINC')) { die; }

/** Only these bounded materializations may bypass the site's general cron queue. */
function ll_tools_public_aggregate_worker_hooks(): array {
    return [
        LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK,
        'll_tools_refresh_wordset_button_lesson_counts',
        'll_tools_wordset_page_warm_recorder_queue_summaries',
    ];
}

/**
 * Drain public cache preparation independently of unrelated overdue cron jobs.
 *
 * The managed server must hold its site cron lock and impose an outer timeout.
 * Time is checked between bounded batches. The selected event remains durable
 * until its result is checkpointed, including while its callback runs: killing
 * a worker cannot leave an unschedule/requeue gap. No arbitrary hook is invoked.
 *
 * @return array{batches:int,elapsed_seconds:float,budget_exhausted:bool,errors:array}
 */
function ll_tools_run_public_aggregate_worker(int $max_seconds = 8, int $max_batches = 6): array {
    $max_seconds = max(1, min(20, $max_seconds));
    $max_batches = max(1, min(100, $max_batches));
    $started = microtime(true);
    $batches = 0;
    $errors = [];
    $skipped = [];
    $pass_hooks = [];
    while ($batches < $max_batches && microtime(true) - $started < $max_seconds) {
        $selected = null;
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            // Only our own one-second progress continuations can run slightly
            // early. Failure backoff remains outside this small lookahead.
            if ((int) $timestamp > time() + 2) { break; }
            foreach (ll_tools_public_aggregate_worker_hooks() as $hook) {
                if (isset($pass_hooks[$hook])) { continue; }
                foreach ((array) ($hooks[$hook] ?? []) as $event) {
                    if (!is_array($event) || !empty($event['schedule'])) { continue; }
                    $args = (array) ($event['args'] ?? []);
                    $identity = $hook . ':' . md5(serialize($args));
                    if (isset($skipped[$identity])) { continue; }
                    if ($hook === LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK
                        && get_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY)) {
                        $skipped[$identity] = true;
                        continue;
                    }
                    $selected = compact('timestamp', 'hook', 'args', 'identity');
                    break 3;
                }
            }
        }
        if ($selected === null) {
            if ($pass_hooks !== []) {
                // Give every due materialization family one turn before a
                // long dictionary rebuild takes another batch.
                $pass_hooks = [];
                continue;
            }
            break;
        }
        ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'identity' => $identity] = $selected;
        $pass_hooks[$hook] = true;

        // Existing builders sometimes clear their event after completion or
        // before scheduling backoff. Keep this exact event until the CAS below.
        // Returning false also terminates their wp_next_scheduled/clear loops.
        $protect = static function ($pre, $event_timestamp, $event_hook, $event_args) use ($timestamp, $hook, $args) {
            return (int) $event_timestamp === (int) $timestamp && $event_hook === $hook && $event_args === $args
                ? false : $pre;
        };
        // Typed outcomes below own continuations. Suppress only this builder's
        // cron writes, so a callback's normal five-second schedule cannot
        // block progress batches or create a requeue gap before the CAS.
        $schedule = static function ($pre, $event) use ($hook, $args) {
            if ($event->hook !== $hook) { return $pre; }
            $next_args = (array) $event->args;
            $same_scope = $next_args === $args;
            if ($hook === 'll_tools_wordset_page_warm_recorder_queue_summaries') {
                $same_scope = array_slice($next_args, 0, 4) === array_slice($args, 0, 4);
            } elseif ($hook === 'll_tools_refresh_wordset_button_lesson_counts') {
                $same_scope = ll_tools_wordset_button_normalize_wordset_ids((array) ($next_args[0] ?? []))
                    === ll_tools_wordset_button_normalize_wordset_ids((array) ($args[0] ?? []))
                    && (int) ($next_args[1] ?? get_current_user_id()) === (int) ($args[1] ?? get_current_user_id());
            }
            return $same_scope ? true : $pre;
        };
        add_filter('pre_unschedule_event', $protect, PHP_INT_MAX, 4);
        add_filter('pre_schedule_event', $schedule, PHP_INT_MAX, 2);
        try {
            $result = ll_tools_public_aggregate_worker_process_event($hook, $args);
        } catch (Throwable $error) {
            // Materializations are idempotent. Retain the exact input scope
            // after an exception, without exposing database or content details.
            $result = ['complete' => false, 'args' => $args, 'delay' => 30, 'error' => 'batch_exception'];
        } finally {
            remove_filter('pre_unschedule_event', $protect, PHP_INT_MAX);
            remove_filter('pre_schedule_event', $schedule, PHP_INT_MAX);
        }
        $batches++;
        $continuation = !empty($result['complete']) ? null : [
            'timestamp' => time() + max(1, (int) ($result['delay'] ?? 30)),
            'args' => (array) ($result['args'] ?? $args),
        ];
        if (!ll_tools_public_aggregate_checkpoint_worker_event((int) $timestamp, $hook, $args, $continuation)) {
            $errors[] = ['hook' => $hook, 'code' => 'cron_checkpoint_failed'];
            break;
        }
        if (!empty($result['error'])) {
            $errors[] = ['hook' => $hook, 'code' => (string) $result['error']];
            $skipped[$identity] = true;
        }
    }
    $elapsed = microtime(true) - $started;
    return [
        'batches' => $batches,
        'elapsed_seconds' => round($elapsed, 3),
        'budget_exhausted' => $batches >= $max_batches || $elapsed >= $max_seconds,
        'errors' => $errors,
    ];
}

/** Dispatch a known builder, obtaining source-specific completeness and retry scope. */
function ll_tools_public_aggregate_worker_process_event(string $hook, array $args): array {
    global $wpdb;
    if ($hook !== 'll_tools_wordset_page_warm_recorder_queue_summaries' || !isset($args[1]) || !is_numeric($args[1])) {
        return ll_tools_public_aggregate_worker_process_event_in_locale($hook, $args);
    }
    // The CLI admin's locale is not the recorder's catalog locale. In
    // particular, the translated virtual category participates in generation.
    $wpdb->last_error = '';
    $locale = (string) ll_tools_get_user_locale_preference((int) $args[1], true);
    if ($wpdb->last_error !== '') {
        return ['complete' => false, 'args' => $args, 'delay' => 30, 'error' => 'recorder_locale_read_failed'];
    }
    $previous_locale = (string) determine_locale();
    $switched = switch_to_locale($locale);
    $force_locale = static function () use ($locale): string { return $locale; };
    add_filter('locale', $force_locale, PHP_INT_MAX);
    add_filter('pre_determine_locale', $force_locale, PHP_INT_MAX);
    // Plugin-only locales may have no installed WordPress language pack.
    // Explicitly load the bundled translation even if Core cannot switch.
    unload_textdomain('ll-tools-text-domain', true);
    load_textdomain('ll-tools-text-domain', LL_TOOLS_BASE_PATH . 'languages/ll-tools-text-domain-' . $locale . '.mo');
    try {
        return ll_tools_public_aggregate_worker_process_event_in_locale($hook, $args);
    } finally {
        remove_filter('locale', $force_locale, PHP_INT_MAX);
        remove_filter('pre_determine_locale', $force_locale, PHP_INT_MAX);
        if ($switched) {
            restore_previous_locale();
        } else {
            unload_textdomain('ll-tools-text-domain', true);
            load_textdomain('ll-tools-text-domain', LL_TOOLS_BASE_PATH . 'languages/ll-tools-text-domain-' . $previous_locale . '.mo');
        }
    }
}

function ll_tools_public_aggregate_worker_process_event_in_locale(string $hook, array $args): array {
    global $wpdb;
    $retry = ['complete' => false, 'args' => $args, 'delay' => 30, 'error' => 'invalid_event_args'];
    if ($hook === LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK && $args === []) {
        $wpdb->last_error = '';
        $before_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION
        ));
        $before = is_string($before_raw) ? maybe_unserialize($before_raw) : null;
        if ($wpdb->last_error !== '' || !is_array($before)) {
            $retry['error'] = 'dictionary_state_read_failed';
            return $retry;
        }
        ll_tools_dictionary_lookup_process_rebuild_batch();
        // A failed state read must never retire a still-pending materialization.
        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION
        ));
        $state = is_string($raw) ? maybe_unserialize($raw) : null;
        if ($wpdb->last_error !== '' || !is_array($state)) {
            $retry['error'] = 'dictionary_state_read_failed';
            return $retry;
        }
        $complete = ($state['status'] ?? '') === 'completed'
            && empty($state['truncate_pending'])
            && ($state['browse_version'] ?? '') === LL_TOOLS_DICTIONARY_BROWSE_LOOKUP_VERSION;
        $advanced = $state !== $before;
        return [
            'complete' => $complete, 'args' => $args, 'delay' => $advanced ? 1 : 30,
            'error' => $complete || $advanced ? '' : 'dictionary_rebuild_not_advanced',
        ];
    }
    if ($hook === 'll_tools_refresh_wordset_button_lesson_counts'
        && count($args) >= 1 && count($args) <= 2 && is_array($args[0])
        && (!isset($args[1]) || is_numeric($args[1]))) {
        $previous_user_id = (int) get_current_user_id();
        $user_id = isset($args[1]) ? max(0, (int) $args[1]) : $previous_user_id;
        if ($user_id > 0) {
            $wpdb->last_error = '';
            $user = get_userdata($user_id);
            if ($wpdb->last_error !== '') {
                $retry['error'] = 'user_scope_read_failed';
                return $retry;
            }
            if (!$user instanceof WP_User || !$user->exists()) {
                return ['complete' => true, 'args' => $args, 'delay' => 0, 'error' => ''];
            }
        }
        wp_set_current_user($user_id);
        try {
            $complete = false;
            $retry_ms = 0;
            ll_tools_get_wordset_button_lesson_counts_bounded($args[0], $complete, $retry_ms);
            return [
                'complete' => $complete, 'args' => $args,
                'delay' => max(1, (int) ceil($retry_ms / 1000)), 'error' => '',
            ];
        } finally {
            wp_set_current_user($previous_user_id);
        }
    }
    if ($hook === 'll_tools_wordset_page_warm_recorder_queue_summaries'
        && count($args) >= 2 && count($args) <= 7
        && is_numeric($args[0]) && is_numeric($args[1])
        && (!isset($args[2]) || is_string($args[2]))
        && (!isset($args[3]) || is_string($args[3]))
        && (!isset($args[4]) || is_string($args[4]))
        && (!isset($args[5]) || is_numeric($args[5]))
        && (!isset($args[6]) || is_array($args[6]))) {
        $wpdb->last_error = '';
        $scope = $wpdb->get_row($wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'wordset') AS wordset_exists,
                    EXISTS(SELECT 1 FROM {$wpdb->users} WHERE ID = %d) AS recorder_exists",
            (int) $args[0], (int) $args[1]
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($scope)) {
            $retry['error'] = 'recorder_scope_read_failed';
            return $retry;
        }
        if (empty($scope['wordset_exists']) || empty($scope['recorder_exists'])) {
            // Proven deleted scope is obsolete work, unlike an uncertain read.
            return ['complete' => true, 'args' => $args, 'delay' => 0, 'error' => ''];
        }
        $result = ll_tools_wordset_page_run_recorder_queue_summary_warmup(...$args);
        if (!empty($result['error'])) { $result['args'] = $args; }
        return $result;
    }
    return $retry;
}

/**
 * Atomically replace an exact single event with its typed continuation.
 * Concurrent cron writes survive; failure leaves the original event retryable.
 */
function ll_tools_public_aggregate_checkpoint_worker_event(
    int $timestamp, string $hook, array $args, ?array $continuation
): bool {
    global $wpdb;
    if (!in_array($hook, ll_tools_public_aggregate_worker_hooks(), true)) { return false; }
    $key = md5(serialize($args));
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $wpdb->last_error = '';
        $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron' LIMIT 1");
        if ($wpdb->last_error !== '' || !is_string($raw)) { return false; }
        $cron = maybe_unserialize($raw);
        if (!is_array($cron) || (int) ($cron['version'] ?? 0) !== 2) { return false; }
        $event = $cron[$timestamp][$hook][$key] ?? null;
        if (!is_array($event) || !empty($event['schedule']) || ($event['args'] ?? null) !== $args) { return false; }
        unset($cron[$timestamp][$hook][$key]);
        if (empty($cron[$timestamp][$hook])) { unset($cron[$timestamp][$hook]); }
        if (empty($cron[$timestamp])) { unset($cron[$timestamp]); }
        if ($continuation !== null) {
            $next_args = (array) ($continuation['args'] ?? $args);
            $next_key = md5(serialize($next_args));
            $exists = false;
            foreach ($cron as $hooks) {
                if (is_array($hooks) && ($hooks[$hook][$next_key]['args'] ?? null) === $next_args) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $next_timestamp = max(time() + 1, (int) ($continuation['timestamp'] ?? 0));
                $cron[$next_timestamp][$hook][$next_key] = ['schedule' => false, 'args' => $next_args];
            }
        }
        unset($cron['version']);
        uksort($cron, 'strnatcasecmp');
        $cron['version'] = 2;
        $replacement = maybe_serialize($cron);
        if ($replacement === $raw) { return true; }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'cron' AND option_value = %s",
            $replacement, $raw
        ));
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');
        if ($updated === 1) { return true; }
        if ($updated === false || $wpdb->last_error !== '') { return false; }
    }
    return false;
}
