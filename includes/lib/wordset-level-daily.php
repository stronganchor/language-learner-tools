<?php
if (!defined('ABSPATH')) { exit; }

function ll_tools_wordset_level_daily_meta_key(): string {
    global $wpdb;
    return $wpdb->get_blog_prefix() . 'll_tools_level_daily_review';
}

/** The current site's calendar day, queried against the recorder's UTC timestamps. */
function ll_tools_wordset_level_daily_outcomes(int $uid, int $wordset_id, DateTimeImmutable $midnight) {
    global $wpdb;
    $table = ll_tools_user_progress_table_names()['events'];
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT word_id, MAX(id) AS outcome_id FROM {$table}
         WHERE user_id = %d AND wordset_id = %d AND created_at >= %s AND created_at < %s
           AND event_type = 'word_outcome' AND mode = 'practice' AND is_correct = 1 AND word_id > 0
         GROUP BY word_id",
        $uid, $wordset_id, gmdate('Y-m-d H:i:s', $midnight->getTimestamp()),
        gmdate('Y-m-d H:i:s', $midnight->modify('+1 day')->getTimestamp())
    ), ARRAY_A);
    return $wpdb->last_error !== '' ? ll_tools_wordset_level_progress_error() : array_column($rows, 'outcome_id', 'word_id');
}

/** Only the daily CTA launch saves a batch. Its caller holds the learner's personal-data mutation lock. */
function ll_tools_wordset_level_daily_plan(int $uid, int $wordset_id, array $eligible, array $progress, array $starred,
    array $intervals, int $now, bool $start = false) {
    global $wpdb;
    if (!$eligible || $uid <= 0 || $wordset_id <= 0) { return ll_tools_wordset_level_global_plan($eligible, $progress, $starred); }
    $midnight = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone())->setTime(0, 0);
    $outcomes = ll_tools_wordset_level_daily_outcomes($uid, $wordset_id, $midnight);
    if (is_wp_error($outcomes)) { return $outcomes; }
    $wpdb->last_error = '';
    $meta_key = ll_tools_wordset_level_daily_meta_key();
    $saved = get_user_meta($uid, $meta_key, true);
    if ($wpdb->last_error !== '' || ($saved !== '' && !is_array($saved))) { return ll_tools_wordset_level_progress_error(); }
    $day = $midnight->format('Y-m-d');
    $today = ($saved['day'] ?? '') === $day ? ($saved['goals'] ?? []) : [];
    if (!is_array($today)) { return ll_tools_wordset_level_progress_error(); }
    ksort($intervals, SORT_NUMERIC);
    $policy = hash('sha256', wp_json_encode($intervals));
    $goal = $today[$wordset_id] ?? [];
    if (($goal['policy'] ?? '') !== $policy) { $goal = []; }
    if ($goal && (!isset($goal['after_id'], $goal['reason']) || !is_array($goal['word_ids'] ?? null))) {
        return ll_tools_wordset_level_progress_error();
    }
    $plan = ll_tools_wordset_level_global_plan($eligible, $progress, $starred, $goal, $outcomes);
    if ($start && $plan['activity']) {
        // The watermark allows another pass over the same word after all cohorts have been visited.
        $batch = ['reason' => $plan['reason'], 'word_ids' => $plan['activity']['session_word_ids'],
            'after_id' => $outcomes ? (int) max($outcomes) : 0, 'policy' => $policy];
        if ($goal !== $batch) {
            $today[$wordset_id] = $batch;
            if (!ll_tools_user_progress_update_meta_verified($uid, $meta_key, ['day' => $day, 'goals' => $today])) {
                return ll_tools_wordset_level_progress_error();
            }
        }
    }
    return $plan;
}

/** Size and rank one exact review from unique practice-eligible targets at their shortest intervals. */
function ll_tools_wordset_level_global_plan(array $eligible, array $progress, array $starred, array $goal = [], array $outcomes = []): array {
    $cohorts = array_fill_keys(['due', 'working', 'hard', 'starred', 'least_recent'], []);
    $interval_counts = [];
    foreach ($eligible as $wid => $word) {
        $row = $progress[$wid] ?? [];
        if (ll_tools_user_progress_word_is_studied($row)) {
            $days = $word['days'];
            $interval_counts[$days] = ($interval_counts[$days] ?? 0) + 1;
            $cohorts['least_recent'][$wid] = $word;
            if (!ll_tools_user_progress_word_is_mastered($row)) {
                $reason = ll_tools_user_progress_word_is_hard($row) ? 'hard' : 'working';
                $cohorts[$reason][$wid] = $word;
            }
        }
        if ($word['due']) { $cohorts['due'][$wid] = $word; }
        if (isset($starred[$wid])) { $cohorts['starred'][$wid] = $word; }
    }
    ll_tools_wordset_level_sort_candidates($cohorts['due']);
    $due = array_keys($cohorts['due']);
    $result = ['due' => count($due), 'due_word_ids' => $due, 'reason' => '', 'count' => 0, 'activity' => null];
    $reason = $goal['reason'] ?? '';
    $selected = [];
    foreach ($goal['word_ids'] ?? [] as $wid) {
        // An exposure or a wrong answer updates last_seen_at and may remove the word from the live due list.
        // Keep its place in this batch until the learner actually finishes the practice question.
        if (isset($eligible[$wid]) && ($outcomes[$wid] ?? 0) <= $goal['after_id']
            && ($reason !== 'starred' || isset($starred[$wid]))
            && ($reason !== 'due' || empty($eligible[$wid]['review_in_context']))) {
            $selected[$wid] = $eligible[$wid];
        }
    }
    if (!$selected) {
        $daily = 0.0;
        foreach ($interval_counts as $days => $count) { $daily += $count / $days; }
        $budget = max(12, (int) ceil(round($daily * 1.2, 8)));
        $order = array_keys($cohorts);
        $previous = array_search($reason, $order, true);
        if ($previous !== false) {
            $order = array_merge(array_slice($order, $previous + 1), array_slice($order, 0, $previous + 1));
        }
        // Avoid reassigning today's answered words through overlapping filters while fresh targets exist.
        foreach ([true, false] as $fresh_only) {
            foreach ($order as $reason) {
                $pool = $fresh_only ? array_diff_key($cohorts[$reason], $outcomes) : $cohorts[$reason];
                if (!$pool) { continue; }
                if ($reason !== 'due') { ll_tools_wordset_level_sort_candidates($pool); }
                $selected = array_slice($pool, 0, $reason === 'due' ? $budget : min(100, $budget), true);
                break 2;
            }
        }
    }
    if ($selected) {
        $result['reason'] = $reason;
        $result['count'] = count($selected);
        $result['activity'] = ll_tools_wordset_level_exact_practice($selected);
    }
    return $result;
}
