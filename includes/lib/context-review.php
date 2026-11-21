<?php
if (!defined('ABSPATH')) { exit; }

// This private, non-autoloaded option is read directly so the REST writer can
// compare-and-swap the exact snapshot, including across concurrent requests.
function ll_tools_context_review_snapshot(int $wordset_id) {
    global $wpdb;
    $key = 'll_tools_context_review_' . $wordset_id;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
    $edges = $raw === null ? [] : json_decode($raw, true);
    if ($wpdb->last_error !== '' || !is_array($edges)) {
        return new WP_Error('ll_tools_context_review_read', __('Could not read review relationships.', 'll-tools-text-domain'), ['status' => 503]);
    }
    return ['key' => $key, 'raw' => $raw, 'edges' => $edges, 'revision' => hash('sha256', $raw ?? '[]')];
}

function ll_tools_rest_context_review(WP_REST_Request $request) {
    global $wpdb;
    $term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($term)) { return $term; }
    $wordset_id = (int) $term->term_id;
    $state = ll_tools_context_review_snapshot($wordset_id);
    if (is_wp_error($state)) { return $state; }
    if (in_array($request->get_method(), ['GET', 'HEAD'], true)) {
        return rest_ensure_response(['wordset_id' => $wordset_id, 'revision' => $state['revision'], 'edges' => $state['edges']]);
    }
    $conflict = new WP_Error('ll_tools_context_review_conflict', __('Relationships changed. Read the current revision and retry.', 'll-tools-text-domain'), ['status' => 409]);
    if ($request['expected_revision'] !== $state['revision']) { return $conflict; }
    $op = $request['operation'];
    $batch = $request['edges'];
    if (!in_array($op, ['add', 'remove'], true) || !is_array($batch) || !$batch || count($batch) > 100) {
        return new WP_Error('ll_tools_context_review_batch', __('Provide an add or remove operation and 1–100 word pairs.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $pairs = [];
    foreach ($state['edges'] as [$source, $base]) { $pairs[$source . ':' . $base] = [$source, $base]; }
    $checked = [];
    foreach ($batch as $pair) {
        if (!is_array($pair) || array_keys($pair) !== [0, 1] || !is_int($pair[0]) || !is_int($pair[1]) || min($pair) < 1 || $pair[0] === $pair[1]) {
            return new WP_Error('ll_tools_context_review_pair', __('Each pair needs two distinct positive word IDs.', 'll-tools-text-domain'), ['status' => 400]);
        }
        [$source, $base] = $pair;
        $key = $source . ':' . $base;
        if ($op === 'remove') {
            unset($pairs[$key]); // Also allow removing links to retired words.
            continue;
        }
        foreach ($pair as $id) {
            if (!isset($checked[$id])) {
                if (get_post_type($id) !== 'words' || get_post_status($id) !== 'publish' || !has_term($wordset_id, 'wordset', $id)) {
                    return new WP_Error('ll_tools_context_review_scope', __('Both words must be published members of this word set.', 'll-tools-text-domain'), ['status' => 400]);
                }
                $checked[$id] = true;
            }
        }
        $pairs[$key] = $pair;
    }
    $edges = array_values($pairs);
    sort($edges);
    if ($op === 'add') {
        $graph = [];
        foreach ($edges as [$source, $base]) { $graph[$source][] = $base; $graph[$base] ??= []; }
        $cycle = ll_tools_wordset_find_prereq_cycle(array_keys($graph), $graph);
        if ($cycle['has_cycle']) {
            return new WP_Error('ll_tools_context_review_cycle', __('Review relationships cannot contain a loop.', 'll-tools-text-domain'), ['status' => 400, 'cycle' => $cycle['cycle_path']]);
        }
    }
    $raw = wp_json_encode($edges);
    $changed = $edges !== $state['edges'];
    $dry = rest_sanitize_boolean($request['dry_run']);
    if ($changed && !$dry) {
        if ($state['raw'] === null) {
            $sql = $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $state['key'], $raw);
        } else {
            $sql = $wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $raw, $state['key'], $state['raw']);
        }
        $written = $wpdb->query($sql);
        if ($written === false) {
            return new WP_Error('ll_tools_context_review_write', __('Could not save review relationships.', 'll-tools-text-domain'), ['status' => 503]);
        }
        if ($written !== 1) { return $conflict; }
    }
    return rest_ensure_response(['wordset_id' => $wordset_id, 'changed' => $changed, 'dry_run' => $dry,
        'revision' => $dry ? $state['revision'] : hash('sha256', $raw), 'edge_count' => count($edges)]);
}

function ll_tools_context_review_word_due(array $row): bool {
    return empty($row['review_in_context']) && ll_tools_user_progress_last_seen_matches_analytics_filter((string) ($row['last_seen_at'] ?? ''), 'older');
}

// Decorate only the in-scope rows for this request. Never write synthetic
// outcomes or advance a word's mastery, streak, or direct last-seen date.
function ll_tools_context_review_mark_rows(int $uid, int $wordset_id, array &$rows): void {
    global $wpdb;
    if (!$rows || $wordset_id <= 0) { return; }
    $snapshot = ll_tools_context_review_snapshot($wordset_id);
    if (is_wp_error($snapshot) || !$snapshot['edges']) { return; }
    $graph = [];
    foreach ($snapshot['edges'] as [$source, $base]) {
        if (isset($rows[$source], $rows[$base]) && ll_tools_user_progress_word_is_studied($rows[$source]) && ll_tools_user_progress_word_is_mastered($rows[$base])) {
            $graph[$source][] = $base;
        }
    }
    $queue = [];
    $recent = [];
    foreach (array_keys($graph) as $source) {
        if (ll_tools_context_review_word_due($rows[$source])) {
            $queue[] = $source; // The richer card represents this pending review.
        } else {
            $recent[] = $source;
        }
    }
    $table = ll_tools_user_progress_table_names()['events'];
    $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    foreach (array_chunk($recent, 200) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $sql = "SELECT DISTINCT word_id FROM {$table} WHERE user_id = %d AND wordset_id = %d AND created_at >= %s
            AND created_at <= UTC_TIMESTAMP() AND event_type = 'word_outcome' AND is_correct = 1 AND mode IN ('learning','practice','self-check')
            AND word_id IN ({$slots})";
        $successful = $wpdb->get_col($wpdb->prepare($sql, array_merge([$uid, $wordset_id, $since], $chunk)));
        foreach ($successful as $id) { $queue[] = (int) $id; }
    }
    $visited = [];
    while ($queue) {
        $source = array_pop($queue);
        if (isset($visited[$source])) { continue; }
        $visited[$source] = true;
        foreach ($graph[$source] ?? [] as $base) {
            $rows[$base]['review_in_context'] = true;
            $queue[] = $base;
        }
    }
}
