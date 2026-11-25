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

function ll_tools_context_review_last_studied_at(array $row): string {
    return max((string) ($row['last_seen_at'] ?? ''), (string) ($row['context_reviewed_at'] ?? ''));
}

function ll_tools_context_review_word_due(array $row): bool {
    return empty($row['review_in_context']) && ll_tools_user_progress_last_seen_matches_analytics_filter(ll_tools_context_review_last_studied_at($row), 'older');
}

// A completed review can come from another lesson. Load only linked ancestors
// of learned words in this view, excluding unpublished or moved cards.
function ll_tools_context_review_ancestor_rows(int $uid, int $wordset_id, array $edges, array $rows, array $scope_ids): array {
    $parents = [];
    $stack = [];
    foreach ($edges as [$source, $base]) {
        $parents[$base][] = $source;
        if (isset($rows[$base]) && ll_tools_user_progress_word_is_mastered($rows[$base])) {
            $stack[$base] = $base;
        }
    }
    $visited = [];
    while ($stack) {
        $base = array_pop($stack);
        if (isset($visited[$base])) { continue; }
        $visited[$base] = true;
        foreach ($parents[$base] ?? [] as $source) { $stack[] = $source; }
    }
    $outside = array_diff(array_keys($visited), $scope_ids);
    $extra = [];
    foreach (array_chunk($outside, 500) as $chunk) {
        $published = get_posts(['post_type' => 'words', 'post_status' => 'publish', 'fields' => 'ids',
            'post__in' => $chunk, 'posts_per_page' => count($chunk), 'orderby' => 'none',
            'tax_query' => [['taxonomy' => 'wordset', 'field' => 'term_id', 'terms' => [$wordset_id], 'include_children' => false]]]);
        $extra += ll_tools_get_user_word_progress_summary_rows($uid, $published);
    }
    return array_intersect_key($rows, $visited) + $extra;
}

// Direct progress is authoritative for mastery and outcomes. Project actual
// event dates onto linked components only in this learner's analytics response.
function ll_tools_context_review_mark_rows(int $uid, int $wordset_id, array &$rows, array $scope_ids): void {
    global $wpdb;
    if (!$rows || $wordset_id <= 0) { return; }
    $snapshot = ll_tools_context_review_snapshot($wordset_id);
    if (is_wp_error($snapshot) || !$snapshot['edges']) { return; }
    $progress = ll_tools_context_review_ancestor_rows($uid, $wordset_id, $snapshot['edges'], $rows, $scope_ids);
    $graph = [];
    $indegree = [];
    foreach ($snapshot['edges'] as [$source, $base]) {
        if (isset($progress[$source], $progress[$base]) && ll_tools_user_progress_word_is_studied($progress[$source]) && ll_tools_user_progress_word_is_mastered($progress[$base])) {
            $graph[$source][] = $base;
            $indegree[$source] ??= 0;
            $indegree[$base] = ($indegree[$base] ?? 0) + 1;
        }
    }
    $latest = [];
    $table = ll_tools_user_progress_table_names()['events'];
    foreach (array_chunk(array_keys($graph), 1000) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $sql = "SELECT word_id, MAX(created_at) AS reviewed_at FROM {$table} WHERE user_id = %d AND wordset_id = %d
            AND event_type = 'word_outcome' AND is_correct = 1 AND mode IN ('learning','practice','self-check')
            AND created_at <= UTC_TIMESTAMP() AND word_id IN ({$slots}) GROUP BY word_id";
        foreach ($wpdb->get_results($wpdb->prepare($sql, array_merge([$uid, $wordset_id], $chunk)), ARRAY_A) as $event) {
            $latest[(int) $event['word_id']] = $event['reviewed_at'];
        }
    }
    // Process every richer card before its components, even when two phrases
    // cover the same word. Only an actual success can supply a review date.
    $queue = array_keys($indegree, 0, true);
    $pending = [];
    while ($queue) {
        $source = array_pop($queue);
        $source_pending = isset($rows[$source]) && (isset($pending[$source]) || ll_tools_context_review_word_due($rows[$source]));
        foreach ($graph[$source] ?? [] as $base) {
            if ($source_pending && isset($rows[$base])) {
                $pending[$base] = true;
                $rows[$base]['review_in_context'] = true;
            }
            if (isset($latest[$source])) {
                $date = $latest[$source];
                $latest[$base] = max($latest[$base] ?? '', $date);
                if (isset($rows[$base])) {
                    $rows[$base]['context_reviewed_at'] = max($rows[$base]['context_reviewed_at'] ?? '', $date);
                    if (ll_tools_user_progress_last_seen_matches_analytics_filter($date, '30d')) {
                        $rows[$base]['review_in_context'] = true;
                    }
                }
            }
            if (--$indegree[$base] === 0) { $queue[] = $base; }
        }
    }
}
