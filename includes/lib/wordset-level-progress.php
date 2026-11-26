<?php
if (!defined('ABSPATH')) { exit; }
/** Learner-specific level summaries. Callers supply the complete, access-checked public lesson map. */

function ll_tools_wordset_level_progress_error(): WP_Error {
    return new WP_Error('ll_level_progress_unavailable', __('Could not load level progress.', 'll-tools-text-domain'), ['status' => 503]);
}

function ll_tools_wordset_level_preparing(): WP_Error {
    return new WP_Error('ll_level_progress_preparing', __('Preparing level progress…', 'll-tools-text-domain'), ['status' => 503, 'retry_after' => 2]);
}

/** Legacy level notification metadata must stay local when importing or copying content. */
function ll_tools_wordset_level_is_availability_meta(string $key): bool {
    return strpos($key, '_ll_level_') === 0;
}

/**
 * A published target may be a word or a prompt with answer-word tracking.
 * Multiple prompt forms of the same word count once. Standalone prompts are exercises.
 */
function ll_tools_wordset_level_project_targets(array $ids, array &$members, bool $materialized_targets = false) {
    global $wpdb;
    if (!$ids) { return true; }
    $slots = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT ID, post_type FROM {$wpdb->posts}
        WHERE ID IN ($slots) AND post_status='publish' AND post_type IN ('words','ll_prompt_card')";
    $records = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    $words = [];
    $prompts = [];
    foreach ($records as $record) {
        if ($record['post_type'] === 'words') { $words[] = (int) $record['ID']; }
        else { $prompts[] = (int) $record['ID']; }
    }
    $complete = true;
    // The public flashcard worker already removes all distractors from target
    // sort groups. The ordinary renderable-items resolver can include them.
    $wrong = $materialized_targets ? [] : ll_tools_get_specific_wrong_answer_only_word_lookup($words, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $answers = ll_tools_user_progress_prompt_card_answer_word_id_map($prompts, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    foreach ($records as $record) {
        $id = (int) $record['ID'];
        if (isset($wrong[$id])) { continue; }
        $word = $record['post_type'] === 'words' ? $id : (int) ($answers[$id] ?? 0);
        if ($word) { $members[$word] = true; }
    }
    return true;
}

function ll_tools_wordset_level_member_key(int $wordset_id, int $category_id): string {
    return 'll_lvl_m4_' . md5(wp_json_encode([$wordset_id, $category_id, get_locale(),
        ll_tools_get_category_cache_versions([$category_id]), ll_tools_get_category_dependency_epoch(), ll_tools_get_wordset_cache_epoch(),
        ll_tools_get_category_versioned_content_epoch([$wordset_id], [$category_id]),
        (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)]));
}

/**
 * Prime one bounded chunk of the authorized catalog. The single-category
 * reader still checks source and local visibility, the current viewer, and
 * expiration through WordPress. Cache keys and invalidation are identical.
 */
function ll_tools_wordset_level_prime_member_keys(int $wordset_id, array $category_ids, array &$object_cache) {
    global $wpdb;
    $wpdb->last_error = '';
    _prime_term_caches($category_ids, false);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    update_meta_cache('term', $category_ids);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    // The source of an isolated copy may impose stricter visibility.
    $sources = [];
    foreach ($category_ids as $category_id) {
        $source = (int) get_term_meta($category_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, true);
        if ($source > 0 && $source !== $category_id) { $sources[$source] = $source; }
    }
    if ($sources) {
        update_meta_cache('term', $sources);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    }
    ll_tools_get_category_cache_versions($category_ids);
    $viewer = get_current_user_id();
    $keys = [];
    $transients = [];
    foreach ($category_ids as $category_id) {
        $key = ll_tools_wordset_level_member_key($wordset_id, $category_id);
        $keys[$category_id] = $key;
        $transients[] = $key;
        $transients[] = $key . '_u' . $viewer;
    }
    $object_cache = [];
    if (wp_using_ext_object_cache()) {
        $object_cache = wp_cache_get_multiple($transients, 'transient');
    } elseif (function_exists('wp_prime_option_caches')) {
        $options = [];
        foreach ($transients as $transient) {
            $options[] = '_transient_' . $transient;
            $options[] = '_transient_timeout_' . $transient;
        }
        wp_prime_option_caches($options);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    }
    return $keys;
}

/**
 * Word IDs are the keys, including in older projections whose values were dates.
 * Small lessons use the standard renderable item resolver after proving
 * their raw input is bounded. Large lessons reuse the flashcard worker and read
 * compact IDs out of its completed generation without loading media or posts.
 */
function ll_tools_wordset_level_members(int $wordset_id, int $category_id, int &$cold_budget, ?string $key = null, array $object_cache = []) {
    global $wpdb;
    $complete = true;
    $category_private = ll_tools_is_category_private($category_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $wordset_private = ll_tools_is_wordset_private($wordset_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $public = !$category_private && !$wordset_private;
    $key = $key ?? ll_tools_wordset_level_member_key($wordset_id, $category_id);
    $personal_key = $key . '_u' . get_current_user_id();
    $cached = $public ? ($object_cache[$key] ?? get_transient($key)) : false;
    if (!is_array($cached)) { $cached = $object_cache[$personal_key] ?? get_transient($personal_key); }
    if (is_array($cached)) { return $cached; }
    if ($cold_budget <= 0) { return ll_tools_wordset_level_preparing(); }
    --$cold_budget;
    $complete = true;
    $raw_words = ll_tools_get_quiz_eligibility_raw_word_id_batch($category_id, [$wordset_id], 0, 49, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $raw_prompts = ll_tools_get_quiz_eligibility_prompt_card_id_batch($category_id, [$wordset_id], 0, 49, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $members = [];
    if (count($raw_words) + count($raw_prompts) <= 48) {
        $targets = ll_tools_user_study_renderable_word_ids_by_category([$category_id], $wordset_id, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $primary_targets = array_intersect($targets[$category_id] ?? [], array_merge($raw_words, $raw_prompts));
        $ready = ll_tools_wordset_level_project_targets($primary_targets, $members);
        if (is_wp_error($ready)) { return $ready; }
        // Regular published words belonging to the public wordset and public
        // category share a scope. Small prompt lessons can reference words from
        // other scopes; keep that projection isolated for the viewing account.
        if (!$public || $raw_prompts) { $key = $personal_key; }
    } else {
        $term = get_term($category_id, 'word-category');
        if (!$term instanceof WP_Term) { return ll_tools_wordset_level_progress_error(); }
        $minimum = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
        $config = ll_tools_resolve_effective_category_quiz_config($term, $minimum, [$wordset_id], $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $scope = ll_tools_flashcard_payload_build_scope($term, [$wordset_id], $config);
        if (is_wp_error($scope)) { return $scope; }
        if (!$public || !empty($scope['viewer_user_id'])) { $key = $personal_key; }
        $ready = ll_tools_flashcard_payload_ensure_ready($scope);
        if (is_wp_error($ready)) { return $ready; }
        if (!$ready) { return ll_tools_wordset_level_preparing(); }
        $hash = ll_tools_flashcard_payload_scope_hash($scope);
        $state = ll_tools_get_flashcard_payload_state($hash);
        $generation = (string) ($state['published_generation'] ?? '');
        if ($generation === '' || $state['generation'] !== $generation) { return ll_tools_wordset_level_preparing(); }
        // Spend the remainder of the cold budget on this larger page.
        $cold_budget = 0;
        return ll_tools_wordset_level_project_generation($key, $hash, $generation);
    }
    set_transient($key, $members, DAY_IN_SECONDS);
    return $members;
}

/** Checkpoint one compact target page, retaining only complete published generations. */
function ll_tools_wordset_level_project_generation(string $key, string $hash, string $generation) {
    global $wpdb;
    $checkpoint_key = $key . '_p_' . substr($generation, 0, 20);
    if (!ll_tools_wordset_page_acquire_cache_rebuild_lock($checkpoint_key, 60)) {
        return ll_tools_wordset_level_preparing();
    }
    try {
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        $checkpoint = get_transient($checkpoint_key);
        $members = is_array($checkpoint) ? $checkpoint['members'] : [];
        $cursor = is_array($checkpoint) ? (int) $checkpoint['cursor'] : 0;
        $group = (int) ($checkpoint['sort_group'] ?? 0);
        $table = ll_tools_flashcard_payload_table_name();
        $page = $wpdb->get_results($wpdb->prepare("SELECT sort_group, row_id FROM {$table}
            WHERE scope_hash=%s AND generation=%s AND sort_group<=1
                AND (sort_group>%d OR (sort_group=%d AND row_id>%d))
            ORDER BY sort_group,row_id LIMIT 250", $hash, $generation, $group, $group, $cursor), ARRAY_A);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
        $ids = array_column($page, 'row_id');
        $step = ll_tools_wordset_level_project_targets($ids, $members, true);
        if (is_wp_error($step)) { return $step; }
        $latest = ll_tools_get_flashcard_payload_state($hash);
        if ($latest['generation'] !== $generation || $latest['published_generation'] !== $generation
            || !ll_tools_wordset_page_renew_cache_rebuild_lock($checkpoint_key, 60)) {
            return ll_tools_wordset_level_preparing();
        }
        if (count($ids) === 250) {
            $last = end($page);
            set_transient($checkpoint_key, ['cursor' => (int) $last['row_id'], 'sort_group' => (int) $last['sort_group'],
                'members' => $members], HOUR_IN_SECONDS);
            return ll_tools_wordset_level_preparing();
        }
        set_transient($key, $members, DAY_IN_SECONDS);
        delete_transient($checkpoint_key);
        return $members;
    } finally {
        ll_tools_wordset_page_release_cache_rebuild_lock($checkpoint_key);
    }
}

/** Most recent actual study in these lessons. Merely viewing the level does not move the baseline. */
function ll_tools_wordset_level_history(int $uid, int $wordset_id, array $category_ids) {
    global $wpdb;
    if ($uid <= 0 || !$category_ids) { return []; }
    $tables = ll_tools_user_progress_table_names();
    $history = [];
    foreach (array_chunk($category_ids, 200) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $params = array_merge([$uid, $wordset_id], $chunk);
        $sources = [
            "SELECT category_id, MAX(created_at) AS last_seen FROM {$tables['events']}
            WHERE user_id=%d AND wordset_id=%d AND category_id IN ($slots)
              AND event_type IN ('word_exposure','word_outcome','mode_session_complete') GROUP BY category_id",
            "SELECT category_id, MAX(last_seen_at) AS last_seen FROM {$tables['words']}
            WHERE user_id=%d AND wordset_id=%d AND category_id IN ($slots) GROUP BY category_id",
        ];
        foreach ($sources as $sql) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
            foreach ($rows as $row) {
                $cid = (int) $row['category_id'];
                $history[$cid] = max((string) ($history[$cid] ?? ''), (string) $row['last_seen']);
            }
        }
    }
    // Session batches can include additional categories which have no dedicated
    // event row. The legacy category store also predates the durable event stream.
    $legacy = ll_tools_get_user_category_progress($uid);
    if (ll_tools_user_progress_get_source_error() instanceof WP_Error) { return ll_tools_wordset_level_progress_error(); }
    foreach ($category_ids as $cid) {
        $row = $legacy[$cid] ?? [];
        if ((int) ($row['wordset_id'] ?? 0) === $wordset_id && !empty($row['last_seen_at'])) {
            $history[$cid] = max((string) ($history[$cid] ?? ''), (string) $row['last_seen_at']);
        }
    }
    return $history;
}

function ll_tools_wordset_level_activity(string $mode, array $categories, array $ids = []): array {
    return ['mode' => $mode, 'category_ids' => array_values(array_unique(array_map('intval', $categories))),
        'session_word_ids' => array_values(array_map('intval', $ids))];
}

/** Oldest effective review first, with a stable order for equal timestamps. */
function ll_tools_wordset_level_sort_candidates(array &$candidates): void {
    uasort($candidates, static function (array $left, array $right): int {
        return strcmp($left['date'], $right['date']) ?: ($left['id'] <=> $right['id']);
    });
}

// Preserve the chosen order and the category that can actually present each word.
// Only compact IDs cross the launch boundary. Widgets hydrate one segment at a time.
function ll_tools_wordset_level_exact_practice(array $selected, bool $describe_mix = false): ?array {
    if (!$selected) { return null; }
    $ownership = array_column($selected, 'category_id', 'id');
    $activity = ll_tools_wordset_level_activity('practice', array_values($ownership), array_keys($ownership));
    $activity['word_category_ids'] = $ownership;
    if ($describe_mix && count($activity['category_ids']) > 1) {
        $activity['label'] = ll_tools_wordset_level_practice_label(array_count_values(array_column($selected, 'status')));
    }
    return $activity;
}

/** Give unfinished words most of the session, with a few familiar peers from their own lessons. */
function ll_tools_wordset_level_focused_practice(array $ordered, array $members, bool $near_completion): ?array {
    $familiar = [];
    $focus = [];
    foreach ($ordered as $wid => $word) {
        if ($word['status'] === 'mastered') { $familiar[$wid] = $word; }
    }
    foreach ($ordered as $wid => $word) {
        if ($word['status'] !== 'mastered' && ($word['status'] !== 'unseen' || $near_completion)) {
            $focus[$wid] = $word;
        }
    }
    if (!$focus) { return ll_tools_wordset_level_exact_practice(array_slice($familiar, 0, 12, true), true); }

    $selected = array_slice($focus, 0, 10, true);
    $peer_limit = min(2, (int) ceil(count($selected) / 3));
    $focus_categories = array_unique(array_column($selected, 'category_id'));
    $peers = [];
    foreach ($focus_categories as $cid) {
        // A shared word can reinforce this lesson even if it appeared earlier in another one.
        foreach (array_intersect_key($familiar, $members[$cid]) as $wid => $word) {
            $word['category_id'] = $cid;
            $peers[$wid] = $word;
            if (count($peers) >= $peer_limit) { break 2; }
        }
    }
    return ll_tools_wordset_level_exact_practice(array_slice($selected + $peers + $focus, 0, 12, true), true);
}

/**
 * Resolve each word's shortest applicable interval first, then apply contextual
 * review to the entire course. A due richer phrase can cover a learned word in
 * another level. Table filters are deliberately applied after this projection.
 */
function ll_tools_wordset_level_course_reviews(int $wordset_id, int $user_id, array $levels, array $members, array $progress, array $intervals, int $now, array $starred = []) {
    $owners = [];
    $word_days = [];
    foreach ($levels as $level => $categories) {
        $days = max(1, min(365, (int) ($intervals[$level] ?? 30)));
        foreach ($categories as $cid => $category) {
            if (isset($category['practice_supported']) && !$category['practice_supported']) { continue; }
            foreach ($members[$cid] as $wid => $_) {
                if (!isset($word_days[$wid]) || $days < $word_days[$wid]) {
                    $owners[$wid] = $cid;
                    $word_days[$wid] = $days;
                }
            }
        }
    }
    $rows = array_intersect_key($progress, $owners);
    ll_tools_context_review_mark_rows($user_id, $wordset_id, $rows, array_keys($owners), 30, $now, $complete, $word_days);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $candidates = [];
    foreach ($owners as $wid => $cid) {
        $row = $rows[$wid] ?? [];
        $studied = ll_tools_user_progress_word_is_studied($row);
        if (!$studied && !isset($starred[$wid])) { continue; }
        $candidates[$wid] = ['id' => $wid, 'category_id' => $cid, 'days' => $word_days[$wid],
            'date' => ll_tools_context_review_last_studied_at($row),
            'due' => $studied && ll_tools_context_review_word_due($row, $word_days[$wid], $now)];
    }
    return $candidates;
}

/** Size one review from unique studied words and their shortest practice-eligible intervals. */
function ll_tools_wordset_level_global_plan(array $eligible, array $progress, array $starred): array {
    $cohorts = [];
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
    $daily = 0.0;
    foreach ($interval_counts as $days => $count) {
        $daily += $count / $days;
    }
    $budget = max(12, (int) ceil(round($daily * 1.2, 8)));
    $result = ['due' => count($cohorts['due'] ?? []), 'due_word_ids' => [], 'reason' => '', 'count' => 0, 'activity' => null];
    foreach (['due', 'working', 'hard', 'starred', 'least_recent'] as $reason) {
        $pool = $cohorts[$reason] ?? [];
        if (!$pool) { continue; }
        ll_tools_wordset_level_sort_candidates($pool);
        if ($reason === 'due') { $result['due_word_ids'] = array_keys($pool); }
        $selected = array_slice($pool, 0, $budget, true);
        $result['reason'] = $reason;
        $result['count'] = count($selected);
        $result['activity'] = ll_tools_wordset_level_exact_practice($selected);
        break;
    }
    return $result;
}

/** Load exact membership IDs and bounded progress columns for the complete visible course. */
function ll_tools_wordset_level_member_progress(int $wordset_id, int $user_id, array $level_categories, bool $practice_only = false) {
    $levels = [];
    $category_ids = [];
    foreach ($level_categories as $level => $rows) {
        $levels[$level] = [];
        foreach ((array) $rows as $row) {
            $row = is_array($row) ? $row : ['id' => (int) $row];
            $cid = (int) ($row['id'] ?? 0);
            if ($cid <= 0 || (isset($row['is_public']) && !$row['is_public'])) { continue; }
            if ($practice_only && isset($row['practice_supported']) && !$row['practice_supported']) { continue; }
            $levels[$level][$cid] = $row;
            $category_ids[$cid] = $cid;
        }
    }
    $cold_budget = 8;
    $members = [];
    $all_ids = [];
    $preparing = false;
    foreach (array_chunk($category_ids, 100) as $chunk) {
        $object_cache = [];
        $keys = ll_tools_wordset_level_prime_member_keys($wordset_id, $chunk, $object_cache);
        if (is_wp_error($keys)) { return $keys; }
        foreach ($keys as $cid => $key) {
            $items = ll_tools_wordset_level_members($wordset_id, $cid, $cold_budget, $key, $object_cache);
            if (is_wp_error($items) && $items->get_error_code() === 'll_level_progress_preparing') {
                $preparing = true;
                if ($cold_budget <= 0) { return $items; }
                continue;
            }
            if (is_wp_error($items)) { return $items; }
            $members[$cid] = $items;
            $all_ids += $items;
        }
    }
    if ($preparing) { return ll_tools_wordset_level_preparing(); }
    $complete = true;
    $progress = ll_tools_get_user_word_progress_summary_rows($user_id, array_keys($all_ids), $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    return ['levels' => $levels, 'members' => $members, 'progress' => $progress, 'category_ids' => $category_ids];
}

function ll_tools_wordset_level_dashboard(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    global $wpdb;
    $now = $now ?? time();
    $data = ll_tools_wordset_level_member_progress($wordset_id, $user_id, $level_categories);
    if (is_wp_error($data)) { return $data; }
    ['levels' => $levels, 'members' => $members, 'progress' => $progress, 'category_ids' => $category_ids] = $data;
    $history = ll_tools_wordset_level_history($user_id, $wordset_id, array_values($category_ids));
    if (is_wp_error($history)) { return $history; }
    $placement = [];
    $starred = [];
    if ($user_id > 0) {
        $goals = ll_tools_get_user_study_goals($user_id);
        if (ll_tools_user_progress_get_source_error() instanceof WP_Error) { return ll_tools_wordset_level_progress_error(); }
        $placement = array_fill_keys((array) ($goals['placement_known_category_ids'] ?? []), true);
        $wpdb->last_error = '';
        $starred = array_fill_keys(ll_tools_get_user_study_starred_word_ids($user_id), true);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    }
    return ll_tools_wordset_level_summarize($wordset_id, $user_id, $levels, $members, $history, $progress, $review_days_by_level, $now, $placement, $starred);
}

function ll_tools_wordset_level_summarize(int $wordset_id, int $user_id, array $levels, array $members, array $history, array $progress, array $review_days_by_level, int $now, array $placement = [], array $starred = []) {
    $result = [];
    foreach ($levels as $level => $categories) {
        $days = max(1, min(365, (int) ($review_days_by_level[$level] ?? 30)));
        $available = [];
        $practice_category = [];
        $next = 0;
        $partial = 0;
        $last = '';
        foreach ($categories as $cid => $category) {
            $last = max($last, (string) ($history[$cid] ?? ''));
            $has_new = false;
            $has_studied = false;
            foreach ($members[$cid] as $wid => $_) {
                $available[$wid] = true;
                if (!isset($practice_category[$wid]) && (!isset($category['practice_supported']) || $category['practice_supported'])) {
                    $practice_category[$wid] = $cid;
                }
                if (isset($progress[$wid]) && ll_tools_user_progress_word_is_studied($progress[$wid])) {
                    $has_studied = true;
                } else {
                    $has_new = true;
                }
            }
            $learn_supported = !isset($placement[$cid]) && (!isset($category['learning_supported']) || $category['learning_supported']);
            // Exercise-only lessons may have no trackable answer words. Their
            // own category history still distinguishes a first visit.
            if ($learn_supported && !$next && empty($history[$cid]) && (!$has_studied || $has_new)) {
                $next = $cid;
            }
            if ($learn_supported && !$partial && $has_new) { $partial = $cid; }
        }
        $rows = array_intersect_key($progress, $available);
        $reviewable = array_intersect_key($rows, $practice_category);
        $complete = true;
        ll_tools_context_review_mark_rows($user_id, $wordset_id, $reviewable, array_keys($practice_category), $days, $now, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $studied = 0;
        $mastered = 0;
        $hard = 0;
        $starred_count = 0;
        $eligible = [];
        foreach ($available as $wid => $_) {
            $row = $rows[$wid] ?? [];
            $is_studied = ll_tools_user_progress_word_is_studied($row);
            $is_mastered = $is_studied && ll_tools_user_progress_word_is_mastered($row);
            $is_hard = $is_studied && !$is_mastered && ll_tools_user_progress_word_is_hard($row);
            if ($is_studied) { ++$studied; }
            if ($is_mastered) { ++$mastered; }
            if ($is_hard) { ++$hard; }
            if (isset($starred[$wid])) { ++$starred_count; }
            if (!isset($practice_category[$wid])) { continue; }
            $review_row = $reviewable[$wid] ?? [];
            $status = 'unseen';
            if ($is_mastered) {
                $status = 'mastered';
            } elseif ($is_studied) {
                $status = $is_hard ? 'hard' : 'working';
            }
            $candidate = ['id' => $wid, 'category_id' => $practice_category[$wid],
                'date' => ll_tools_context_review_last_studied_at($review_row), 'status' => $status,
                'days' => $days, 'due' => $is_studied && ll_tools_context_review_word_due($review_row, $days, $now),
                'starred' => isset($starred[$wid])];
            $eligible[$wid] = $candidate;
        }
        ll_tools_wordset_level_sort_candidates($eligible);
        $due = array_filter($eligible, static function (array $word): bool { return $word['due']; });
        $total = count($available);
        $near_completion = $mastered > 0 && ($mastered * 5 >= $total * 4 || ($mastered * 2 > $total && $total - $mastered <= 3));
        $practice_activity = ll_tools_wordset_level_focused_practice($eligible, $members, $near_completion);
        $due_activity = ll_tools_wordset_level_exact_practice(array_slice($due, 0, 12, true));
        $result[$level] = ['total' => count($available), 'studied' => $studied, 'mastered' => $mastered,
            'working' => $studied - $mastered, 'new' => count($available) - $studied, 'due' => count($due),
            'hard' => $hard, 'starred' => $starred_count,
            'last_studied_at' => $last, 'next_category_id' => $next ?: $partial,
            'practice_category_id' => $practice_activity['category_ids'][0] ?? 0,
            'due_category_id' => $due_activity['category_ids'][0] ?? 0,
            'practice_activity' => $practice_activity, 'due_activity' => $due_activity];
    }
    $global = ll_tools_wordset_level_course_reviews($wordset_id, $user_id, $levels, $members, $progress, $review_days_by_level, $now, $starred);
    if (is_wp_error($global)) { return $global; }
    return ['levels' => $result, 'overall' => ll_tools_wordset_level_global_plan($global, $progress, $starred)];
}

function ll_tools_wordset_level_progress(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, $level_categories, $review_days_by_level, $now);
    return is_wp_error($dashboard) ? $dashboard : $dashboard['levels'];
}

function ll_tools_wordset_level_overall_review(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    global $wpdb;
    $data = ll_tools_wordset_level_member_progress($wordset_id, $user_id, $level_categories, true);
    if (is_wp_error($data)) { return $data; }
    $wpdb->last_error = '';
    $starred = $user_id > 0 ? array_fill_keys(ll_tools_get_user_study_starred_word_ids($user_id), true) : [];
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    $reviews = ll_tools_wordset_level_course_reviews($wordset_id, $user_id, $data['levels'], $data['members'],
        $data['progress'], $review_days_by_level, $now ?? time(), $starred);
    return is_wp_error($reviews) ? $reviews : ll_tools_wordset_level_global_plan($reviews, $data['progress'], $starred);
}

function ll_tools_wordset_level_launch(int $wordset_id, int $user_id, array $categories, int $review_days, string $action, ?int $now = null, int $level = 0) {
    $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, [$level => $categories], [$level => $review_days], $now);
    if (is_wp_error($dashboard)) { return $dashboard; }
    $row = $dashboard['levels'][$level];
    if ($action === 'learn' && $row['next_category_id']) {
        return ll_tools_wordset_level_activity('learning', [$row['next_category_id']]);
    }
    if ($action === 'due' || $action === 'practice') { return $row[$action . '_activity']; }
    return null;
}
