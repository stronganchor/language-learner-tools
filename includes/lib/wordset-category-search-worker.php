<?php
if (!defined('WPINC')) { die; }

/**
 * Managed sites opt in only after installing an independent scheduled worker.
 * A public search then neither builds rows nor creates maintenance events.
 */
function ll_tools_wordset_category_search_background_only(): bool {
    return (bool) get_option('ll_tools_wordset_category_search_background_only', false);
}

/**
 * Drain only search maintenance, independently of unrelated overdue WP-Cron jobs.
 *
 * The server must serialize invocations with its normal site cron runner and
 * impose an outer process timeout. This in-process budget is checked between
 * bounded batches, so it cannot interrupt a slow database query. Each selected
 * event stays scheduled during execution: a killed process leaves retryable work.
 * Existing generation/owner fences protect against concurrent WP-Cron workers.
 *
 * @return array{batches:int,elapsed_seconds:float,budget_exhausted:bool,errors:array}
 */
function ll_tools_run_wordset_category_search_worker(int $max_seconds = 10, int $max_batches = 20): array {
    $max_seconds = max(1, min(20, $max_seconds));
    $max_batches = max(1, min(100, $max_batches));
    $started = microtime(true);
    $batches = 0;
    $skipped = [];
    $errors = [];
    while ($batches < $max_batches && microtime(true) - $started < $max_seconds) {
        $selected = null;
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            if ((int) $timestamp > time() + 2) {
                break;
            }
            foreach ([
                LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
                LL_TOOLS_WORDSET_CATEGORY_SEARCH_SWEEP_HOOK,
            ] as $hook) {
                foreach ((array) ($hooks[$hook] ?? []) as $event) {
                    $args = (array) ($event['args'] ?? []);
                    $identity = $hook . ':' . md5(serialize($args));
                    if (isset($skipped[$identity])) {
                        continue;
                    }
                    if ($hook === LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK) {
                        $wordset_id = (int) ($args[0] ?? 0);
                        $signature = ll_tools_wordset_category_search_fresh_signature($wordset_id);
                        $state = ll_tools_get_wordset_category_search_state($wordset_id);
                        $lease = (string) get_option(ll_tools_wordset_category_search_lock_option($wordset_id), '');
                        // An expired owner must be recoverable through the
                        // canonical takeover path, which rotates generation.
                        if ((int) $lease > time()
                            || ((int) $state['next_retry_at'] > time()
                                && hash_equals((string) $state['signature'], $signature))) {
                            $skipped[$identity] = true;
                            continue;
                        }
                    }
                    $selected = compact('timestamp', 'hook', 'args', 'identity');
                    break 3;
                }
            }
        }
        if ($selected === null) {
            break;
        }
        ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'identity' => $identity] = $selected;
        if ($hook === LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK) {
            $wordset_id = (int) ($args[0] ?? 0);
            $state = ll_tools_wordset_category_search_process_rebuild_batch($wordset_id);
            $batches++;
            $next_timestamp = null;
            if (($state['status'] ?? '') !== 'completed' && empty($state['terminal'])) {
                $next_timestamp = max(time() + 1, (int) ($state['next_retry_at'] ?? 0));
            } elseif (($state['status'] ?? '') === 'completed') {
                $generation = (string) $state['generation'];
                // The next pass checks remaining generations under the lease.
                // Do not delete rows here, outside the canonical writer.
                if (ll_tools_wordset_category_search_has_old_generations($wordset_id, $generation)) {
                    $next_timestamp = time() + 2;
                }
            }
            // Replace the consumed event with its continuation in one CAS.
            // There is no unschedule/requeue gap if the process is killed.
            if (!ll_tools_wordset_category_search_checkpoint_worker_event((int) $timestamp, $hook, $args, $next_timestamp)) {
                $errors[] = ['wordset_id' => $wordset_id, 'code' => 'cron_checkpoint_failed'];
                break;
            }
            if (!empty($state['last_error']) || !empty($state['terminal'])) {
                $errors[] = ['wordset_id' => $wordset_id, 'code' => (string) $state['last_error']];
                $skipped[$identity] = true;
            }
        } else {
            $complete = ll_tools_wordset_category_search_run_scheduling_sweep(...$args);
            $batches++;
            if (!ll_tools_wordset_category_search_checkpoint_worker_event(
                (int) $timestamp, $hook, $args, $complete ? null : time() + MINUTE_IN_SECONDS
            )) {
                $errors[] = ['wordset_id' => 0, 'code' => 'cron_checkpoint_failed'];
                break;
            }
            if (!$complete) {
                $errors[] = ['wordset_id' => 0, 'code' => 'scheduling_sweep_query_failed'];
            }
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

/**
 * Atomically retire a search event and retain its continuation when necessary.
 * A racing cron write is preserved; a failed CAS leaves the original event.
 */
function ll_tools_wordset_category_search_checkpoint_worker_event(
    int $timestamp, string $hook, array $args, ?int $next_timestamp
): bool {
    global $wpdb;
    if (!in_array($hook, [
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_SWEEP_HOOK,
    ], true)) {
        return false;
    }
    $key = md5(serialize($args));
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $wpdb->last_error = '';
        $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron' LIMIT 1");
        if ($wpdb->last_error !== '' || !is_string($raw)) {
            return false;
        }
        $cron = maybe_unserialize($raw);
        if (!is_array($cron) || (int) ($cron['version'] ?? 0) !== 2) {
            return false;
        }
        $event = $cron[$timestamp][$hook][$key] ?? null;
        if (!is_array($event) || !empty($event['schedule']) || ($event['args'] ?? null) !== $args) {
            return false;
        }
        unset($cron[$timestamp][$hook][$key]);
        if (empty($cron[$timestamp][$hook])) { unset($cron[$timestamp][$hook]); }
        if (empty($cron[$timestamp])) { unset($cron[$timestamp]); }
        $already_scheduled = false;
        foreach ($cron as $hooks) {
            if (is_array($hooks) && isset($hooks[$hook][$key])) {
                $already_scheduled = true;
                break;
            }
        }
        if ($next_timestamp !== null && !$already_scheduled) {
            $cron[$next_timestamp][$hook][$key] = ['schedule' => false, 'args' => $args];
        }
        unset($cron['version']);
        uksort($cron, 'strnatcasecmp');
        $cron['version'] = 2;
        $replacement = maybe_serialize($cron);
        if ($replacement === $raw) {
            // Two fast batches may share the same one-second continuation.
            // The existing event already represents the next checkpoint.
            return true;
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'cron' AND option_value = %s",
            $replacement, $raw
        ));
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');
        if ($updated === 1) {
            return true;
        }
        if ($updated === false || $wpdb->last_error !== '') {
            return false;
        }
    }
    return false;
}

/** One bounded existence probe; a failed probe retains maintenance work. */
function ll_tools_wordset_category_search_has_old_generations(int $wordset_id, string $generation): bool {
    global $wpdb;
    $table = ll_tools_wordset_category_search_table_name();
    $wpdb->last_error = '';
    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT word_id FROM {$table} WHERE wordset_id = %d AND generation <> %s LIMIT 1",
        $wordset_id, $generation
    ));
    return $wpdb->last_error !== '' || $found !== null;
}
