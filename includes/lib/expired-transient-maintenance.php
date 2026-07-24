<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK')) {
    define('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK', 'll_tools_expired_transient_maintenance_event');
}
if (!defined('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION')) {
    define('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION', '_ll_tools_expired_transient_maintenance_lock');
}
if (!defined('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT')) {
    define('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT', 200);
}
if (!defined('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MIN_GRACE_SECONDS')) {
    define('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MIN_GRACE_SECONDS', 5 * MINUTE_IN_SECONDS);
}
if (!defined('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MAX_RUNTIME_SECONDS')) {
    define('LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MAX_RUNTIME_SECONDS', 2.0);
}

/**
 * Exact transient-key prefixes owned by bounded LL Tools caches/rate limits.
 *
 * Operational state such as import previews, notices, migration cursors,
 * background jobs, and persistent ll_tools_* options is intentionally absent.
 * Longer overlapping prefixes stay first so telemetry uses the narrowest
 * namespace even though deletion eligibility would be unchanged.
 *
 * @return array<string,string> Namespace => transient-key prefix.
 */
function ll_tools_expired_transient_maintenance_namespaces(): array {
    return [
        'dictionary-client-inflight' => 'll_dict_live_search_inflight_',
        'dictionary-rate-limit' => 'll_dict_live_search_rl_',
        'dictionary-cache' => 'll_dict_',
        'wordset-lazy-rate-limit' => 'll_tools_wsp_lazy_miss_',
        'wordset-search-rate-limit' => 'll_tools_wsp_search_miss_',
        'wordset-page-cache' => 'll_wsp_',
        'quiz-word-count-cache' => 'll_wc_words_count_',
        'quiz-word-row-cache' => 'll_wc_words_',
        'quiz-item-id-cache' => 'll_wc_item_ids_',
        'quiz-gender-count-cache' => 'll_wc_gender_count_',
        'quiz-eligibility-cache' => 'll_can_quiz_',
        'quiz-wordset-id-cache' => 'll_wcids_ws_',
        'quiz-raw-wordset-cache' => 'll_raw_ws_ids_',
        'quiz-default-wordset-cache' => 'll_default_quiz_ws_',
        'quiz-page-data-cache' => 'll_qpg_data_',
        'flashcard-ajax-client-inflight' => 'll_fc_ajax_inflight_',
        'flashcard-ajax-build-lock' => 'll_fc_ajax_build_',
        'flashcard-ajax-rate-limit' => 'll_fc_ajax_throttle_',
        'flashcard-ajax-cache' => 'll_fc_ajax_',
        'flashcard-category-cache' => 'll_fc_proc_cats_',
        'missing-flashcard-category-cache' => 'll_tools_missing_flashcard_category_',
        'word-grid-shell-cache' => 'll_wg_shell_cards_',
        'aspect-usage-cache' => 'll_tools_aspect_usage_',
        'aspect-stats-cache' => 'll_tools_aspect_stats_',
        'vocab-grid-rate-limit' => 'll_tools_vocab_grid_miss_',
        'vocab-grid-cache' => 'll_vl_grid_',
        'vocab-deep-count-cache' => 'll_vocab_lesson_deep_counts_',
        'vocab-gender-cache' => 'll_tools_vocab_lesson_gender_',
        'corpus-collection-page-cache' => 'll_corpus_collection_page_',
        'user-progress-word-id-cache' => 'll_up_an_words_',
        'user-study-word-id-cache' => 'll_us_renderable_word_ids_',
        'wordset-button-cache' => 'll_ws_buttons_',
        'active-wordset-cache' => 'll_active_ws_',
        'wordset-answer-preview-cache' => 'll_ws_ans_prev_samples_v2_',
        'wordset-preview-ratio-cache' => 'll_wordset_preview_ratio_v2_',
        'ai-export-cache' => 'll_ai_export_',
        'webp-queue-index-cache' => 'll_webp_queue_index_',
        'ipa-rules-index-cache' => 'll_ipa_ortho_engine_idx_',
        'ipa-rules-cache' => 'll_ipa_ortho_engine_',
        'registration-rate-limit' => 'll_tools_reg_attempt_',
        'username-rate-limit' => 'll_tools_user_suggest_',
        'login-rate-limit' => 'll_tools_login_attempt_',
        'offline-login-rate-limit' => 'll_tools_offline_login_',
        'offline-sync-rate-limit' => 'll_tools_offline_sync_',
        'speaking-rate-limit' => 'll_tools_speaking_txn_rl_',
    ];
}

function ll_tools_expired_transient_maintenance_namespace(string $transient_key): string {
    foreach (ll_tools_expired_transient_maintenance_namespaces() as $namespace => $prefix) {
        if (strpos($transient_key, $prefix) === 0) {
            return $namespace;
        }
    }

    return '';
}

function ll_tools_expired_transient_maintenance_batch_limit(): int {
    $limit = (int) apply_filters(
        'll_tools_expired_transient_maintenance_batch_limit',
        LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT
    );

    return max(1, min(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT, $limit));
}

function ll_tools_expired_transient_maintenance_grace_seconds(): int {
    $grace = (int) apply_filters(
        'll_tools_expired_transient_maintenance_grace_seconds',
        LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MIN_GRACE_SECONDS
    );

    return max(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MIN_GRACE_SECONDS, $grace);
}

function ll_tools_expired_transient_maintenance_runtime_seconds(): float {
    $runtime = (float) apply_filters('ll_tools_expired_transient_maintenance_runtime_seconds', 1.0);

    return max(0.05, min(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_MAX_RUNTIME_SECONDS, $runtime));
}

function ll_tools_expired_transient_maintenance_empty_telemetry(): array {
    return [
        'external_cache_bypass_count' => 0,
        'lock_bypass_count' => 0,
        'selected_count' => 0,
        'processed_count' => 0,
        'renewed_race_count' => 0,
        'deleted_transient_count' => 0,
        'deleted_row_count' => 0,
        'deleted_value_row_count' => 0,
        'deleted_timeout_only_count' => 0,
        'deleted_value_bytes' => 0,
        'deleted_timeout_bytes' => 0,
        'wall_time_stop_count' => 0,
        'namespaces' => [],
    ];
}

function ll_tools_expired_transient_maintenance_lock_payload(int $expires_at, string $token): string {
    return max(0, $expires_at) . ':' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function ll_tools_expired_transient_maintenance_lock_expires_at(string $payload): int {
    $separator = strpos($payload, ':');
    if ($separator === false) {
        return 0;
    }

    return max(0, (int) substr($payload, 0, $separator));
}

/**
 * Acquire an option-backed lock with compare-and-swap stale takeover.
 *
 * The returned payload is the ownership token required for release. An old
 * worker can therefore never delete a replacement worker's lock.
 */
function ll_tools_expired_transient_maintenance_acquire_lock(int $now = 0): string {
    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    $now = $now > 0 ? $now : time();
    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : '';
    if ($token === '') {
        $token = md5(uniqid((string) mt_rand(), true));
    }
    $payload = ll_tools_expired_transient_maintenance_lock_payload($now + 10 * MINUTE_IN_SECONDS, $token);
    $option_name = LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION;

    if (add_option($option_name, $payload, '', false)) {
        return $payload;
    }

    $current = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $option_name
    ));
    if (!is_string($current)) {
        return add_option($option_name, $payload, '', false) ? $payload : '';
    }
    if (ll_tools_expired_transient_maintenance_lock_expires_at($current) > $now) {
        return '';
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $payload,
        $option_name,
        $current
    ));
    if ((int) $updated !== 1) {
        return '';
    }

    wp_cache_delete($option_name, 'options');
    return $payload;
}

function ll_tools_expired_transient_maintenance_release_lock(string $payload): void {
    global $wpdb;

    if ($payload === '' || !($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION,
        $payload
    ));
    wp_cache_delete(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_LOCK_OPTION, 'options');
}

/**
 * Select a bounded oldest-first candidate set from exact allowlisted prefixes.
 *
 * @return array<int,array<string,mixed>>
 */
function ll_tools_expired_transient_maintenance_select_candidates(int $cutoff, int $limit): array {
    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [];
    }

    $limit = max(1, min(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HARD_BATCH_LIMIT, $limit));
    $timeout_prefix = '_transient_timeout_';
    $value_prefix = '_transient_';
    $prefix_clauses = [];
    $params = [$value_prefix, $timeout_prefix];

    foreach (array_values(array_unique(ll_tools_expired_transient_maintenance_namespaces())) as $prefix) {
        $prefix_clauses[] = 'timeout_row.option_name LIKE %s';
        $params[] = $wpdb->esc_like($timeout_prefix . $prefix) . '%';
    }
    if (empty($prefix_clauses)) {
        return [];
    }

    $params[] = max(0, $cutoff);
    $params[] = $limit;
    $sql = "SELECT timeout_row.option_id AS timeout_option_id,
                   timeout_row.option_name AS timeout_option_name,
                   timeout_row.option_value AS timeout_value,
                   OCTET_LENGTH(timeout_row.option_value) AS timeout_bytes,
                   value_row.option_id AS value_option_id,
                   value_row.option_name AS value_option_name,
                   COALESCE(OCTET_LENGTH(value_row.option_value), 0) AS value_bytes
            FROM {$wpdb->options} AS timeout_row
            LEFT JOIN {$wpdb->options} AS value_row
              ON value_row.option_name = CONCAT(%s, SUBSTRING(timeout_row.option_name, CHAR_LENGTH(%s) + 1))
            WHERE (" . implode(' OR ', $prefix_clauses) . ")
              AND timeout_row.option_value REGEXP '^[0-9]+$'
              AND CAST(timeout_row.option_value AS UNSIGNED) <= %d
            ORDER BY CAST(timeout_row.option_value AS UNSIGNED) ASC, timeout_row.option_id ASC
            LIMIT %d";
    $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params));
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    $candidates = [];
    foreach ((array) $rows as $row) {
        $timeout_option_name = (string) ($row['timeout_option_name'] ?? '');
        if (strpos($timeout_option_name, $timeout_prefix) !== 0) {
            continue;
        }
        $transient_key = substr($timeout_option_name, strlen($timeout_prefix));
        $namespace = ll_tools_expired_transient_maintenance_namespace($transient_key);
        if ($transient_key === '' || $namespace === '') {
            continue;
        }

        $candidates[] = [
            'transient_key' => $transient_key,
            'namespace' => $namespace,
            'timeout_option_id' => max(0, (int) ($row['timeout_option_id'] ?? 0)),
            'timeout_option_name' => $timeout_option_name,
            'timeout_value' => (string) ($row['timeout_value'] ?? ''),
            'timeout_bytes' => max(0, (int) ($row['timeout_bytes'] ?? 0)),
            'value_option_id' => max(0, (int) ($row['value_option_id'] ?? 0)),
            'value_option_name' => $value_prefix . $transient_key,
            'value_bytes' => max(0, (int) ($row['value_bytes'] ?? 0)),
        ];
    }

    return $candidates;
}

/**
 * Conditionally delete one exact pair in a single SQL statement.
 *
 * The selected timeout id/value and current expiry are rechecked by SQL. If a
 * producer has renewed the timeout before this statement runs, neither the
 * timeout nor its value row is deleted. The grace period also narrows the
 * legacy non-transactional value-then-timeout update window. A LEFT JOIN still
 * deletes timeout-only rows.
 *
 * @return array{deleted:bool,deleted_rows:int,deleted_value_row:bool}
 */
function ll_tools_expired_transient_maintenance_delete_candidate(array $candidate, int $cutoff): array {
    global $wpdb;

    $result = [
        'deleted' => false,
        'deleted_rows' => 0,
        'deleted_value_row' => false,
    ];
    if (!($wpdb instanceof wpdb)) {
        return $result;
    }

    $transient_key = (string) ($candidate['transient_key'] ?? '');
    $namespace = ll_tools_expired_transient_maintenance_namespace($transient_key);
    $timeout_option_name = (string) ($candidate['timeout_option_name'] ?? '');
    $value_option_name = (string) ($candidate['value_option_name'] ?? '');
    $timeout_value = (string) ($candidate['timeout_value'] ?? '');
    $timeout_option_id = max(0, (int) ($candidate['timeout_option_id'] ?? 0));
    if (
        $namespace === ''
        || $timeout_option_id <= 0
        || $timeout_option_name !== '_transient_timeout_' . $transient_key
        || $value_option_name !== '_transient_' . $transient_key
        || !preg_match('/^[0-9]+$/', $timeout_value)
    ) {
        return $result;
    }

    $deleted_rows = $wpdb->query($wpdb->prepare(
        "DELETE timeout_row, value_row
         FROM {$wpdb->options} AS timeout_row
         LEFT JOIN {$wpdb->options} AS value_row ON value_row.option_name = %s
         WHERE timeout_row.option_id = %d
           AND timeout_row.option_name = %s
           AND timeout_row.option_value = %s
           AND timeout_row.option_value REGEXP '^[0-9]+$'
           AND CAST(timeout_row.option_value AS UNSIGNED) <= %d",
        $value_option_name,
        $timeout_option_id,
        $timeout_option_name,
        $timeout_value,
        max(0, $cutoff)
    ));
    $deleted_rows = max(0, (int) $deleted_rows);
    if ($deleted_rows < 1) {
        return $result;
    }

    wp_cache_delete($timeout_option_name, 'options');
    wp_cache_delete($value_option_name, 'options');
    $result['deleted'] = true;
    $result['deleted_rows'] = $deleted_rows;
    $result['deleted_value_row'] = $deleted_rows > 1;
    return $result;
}

function ll_tools_run_expired_transient_maintenance(): array {
    $telemetry = ll_tools_expired_transient_maintenance_empty_telemetry();
    if (wp_using_ext_object_cache()) {
        $telemetry['external_cache_bypass_count'] = 1;
        do_action('ll_tools_expired_transient_maintenance_telemetry', $telemetry);
        return $telemetry;
    }

    $lock_payload = ll_tools_expired_transient_maintenance_acquire_lock();
    if ($lock_payload === '') {
        $telemetry['lock_bypass_count'] = 1;
        do_action('ll_tools_expired_transient_maintenance_telemetry', $telemetry);
        return $telemetry;
    }

    $started_at = microtime(true);
    $deadline = $started_at + ll_tools_expired_transient_maintenance_runtime_seconds();
    $cutoff = time() - ll_tools_expired_transient_maintenance_grace_seconds();

    try {
        $candidates = ll_tools_expired_transient_maintenance_select_candidates(
            $cutoff,
            ll_tools_expired_transient_maintenance_batch_limit()
        );
        $telemetry['selected_count'] = count($candidates);

        foreach ($candidates as $candidate) {
            if (microtime(true) >= $deadline) {
                $telemetry['wall_time_stop_count'] = 1;
                break;
            }

            $telemetry['processed_count']++;
            $deleted = ll_tools_expired_transient_maintenance_delete_candidate($candidate, $cutoff);
            if (empty($deleted['deleted'])) {
                $telemetry['renewed_race_count']++;
                continue;
            }

            $namespace = (string) ($candidate['namespace'] ?? '');
            $deleted_rows = max(1, (int) ($deleted['deleted_rows'] ?? 0));
            $deleted_value_row = !empty($deleted['deleted_value_row']);
            $value_bytes = $deleted_value_row ? max(0, (int) ($candidate['value_bytes'] ?? 0)) : 0;
            $timeout_bytes = max(0, (int) ($candidate['timeout_bytes'] ?? 0));

            $telemetry['deleted_transient_count']++;
            $telemetry['deleted_row_count'] += $deleted_rows;
            $telemetry['deleted_value_row_count'] += $deleted_value_row ? 1 : 0;
            $telemetry['deleted_timeout_only_count'] += $deleted_value_row ? 0 : 1;
            $telemetry['deleted_value_bytes'] += $value_bytes;
            $telemetry['deleted_timeout_bytes'] += $timeout_bytes;

            if ($namespace !== '') {
                if (!isset($telemetry['namespaces'][$namespace])) {
                    $telemetry['namespaces'][$namespace] = [
                        'deleted_transient_count' => 0,
                        'deleted_value_bytes' => 0,
                        'deleted_timeout_bytes' => 0,
                    ];
                }
                $telemetry['namespaces'][$namespace]['deleted_transient_count']++;
                $telemetry['namespaces'][$namespace]['deleted_value_bytes'] += $value_bytes;
                $telemetry['namespaces'][$namespace]['deleted_timeout_bytes'] += $timeout_bytes;
            }
        }
    } finally {
        ll_tools_expired_transient_maintenance_release_lock($lock_payload);
    }

    ksort($telemetry['namespaces'], SORT_STRING);
    do_action('ll_tools_expired_transient_maintenance_telemetry', $telemetry);
    return $telemetry;
}

function ll_tools_schedule_expired_transient_maintenance(): void {
    if (!wp_next_scheduled(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK);
    }
}

function ll_tools_clear_expired_transient_maintenance_schedule(): void {
    wp_clear_scheduled_hook(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK);
}

add_action('init', 'll_tools_schedule_expired_transient_maintenance', 30);
add_action(LL_TOOLS_EXPIRED_TRANSIENT_MAINTENANCE_HOOK, 'll_tools_run_expired_transient_maintenance');

if (defined('LL_TOOLS_MAIN_FILE')) {
    register_activation_hook(LL_TOOLS_MAIN_FILE, 'll_tools_schedule_expired_transient_maintenance');
    register_deactivation_hook(LL_TOOLS_MAIN_FILE, 'll_tools_clear_expired_transient_maintenance_schedule');
}
