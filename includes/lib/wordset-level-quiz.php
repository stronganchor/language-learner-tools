<?php
if (!defined('ABSPATH')) { exit; }

function ll_tools_wordset_level_category_supports_quiz(array $category, string $mode): bool {
    $flag = str_replace('-', '_', $mode) . '_supported';
    return $category[$flag] ?? in_array($mode, ['practice', 'learning', 'self-check'], true);
}

/** Catalogs use category configuration; learner summaries can also omit lessons without trackable targets. */
function ll_tools_wordset_level_quiz_modes(array $categories, ?array $members = null): array {
    $modes = [];
    foreach (ll_tools_get_study_launch_mode_order(true) as $mode) {
        foreach ($categories as $cid => $category) {
            if ($members !== null && empty($members[$category['id'] ?? $cid])) { continue; }
            if (ll_tools_wordset_level_category_supports_quiz($category, $mode)) {
                $modes[] = $mode;
                break;
            }
        }
    }
    return $modes;
}

/** One compatible Learn round, choosing the oldest words that fit the player together. */
function ll_tools_wordset_level_learn_selection(array $ordered, array $owners, array $meta): array {
    $groups = [];
    $categories = [];
    $keys = array_map('ll_tools_user_study_learning_compatibility_key', $meta);
    foreach ($ordered as $word) {
        foreach ($owners[$word] as $cid) {
            $key = $keys[$cid];
            if (isset($groups[$key][$word]) || count($groups[$key] ?? []) >= 10) { continue; }
            if (!isset($categories[$key][$cid]) && count($categories[$key] ?? []) >= 8) { continue; }
            $groups[$key][$word] = $cid;
            $categories[$key][$cid] = true;
        }
    }
    // Equal-sized groups retain their earliest candidate as the tie breaker.
    $best = [];
    $best_key = '';
    foreach ($groups as $key => $group) {
        if (count($group) > count($best)) {
            $best = $group;
            $best_key = $key;
        }
    }
    return [$best, $best_key];
}

/** Plan only the selected level. Its scoped ID projections never hydrate quiz media. */
function ll_tools_wordset_level_quiz(int $wordset_id, int $user_id, array $categories, string $mode) {
    $categories = array_filter($categories, static function (array $category) use ($mode): bool {
        return ll_tools_wordset_level_category_supports_quiz($category, $mode);
    });
    if (!$categories) { return null; }
    $data = ll_tools_wordset_level_member_progress($wordset_id, $user_id, [$categories], false, $mode);
    if (is_wp_error($data)) { return $data; }
    $by_category = array_map('array_keys', $data['members']);
    $meta = [];
    if (in_array($mode, ['learning', 'self-check', 'gender'], true)) {
        $meta = ll_tools_user_progress_selection_category_payload_map(array_keys($by_category), $wordset_id, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $by_category = array_intersect_key($by_category, $meta);
        if ($mode === 'gender') {
            $by_category = ll_tools_filter_user_study_gender_selection_matches($by_category, $wordset_id, $meta, $complete);
            if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        } else {
            foreach (array_keys($by_category) as $cid) {
                if (!ll_tools_category_meta_supports_progress_mode($mode, $meta[$cid])) { unset($by_category[$cid]); }
            }
        }
    }
    $owners = [];
    foreach ($by_category as $cid => $words) {
        foreach ($words as $word) { $owners[$word][] = $cid; }
    }
    if (!$owners) { return null; }
    $ordered = array_keys($owners);
    if ($user_id) {
        sort($ordered, SORT_NUMERIC);
        $ordered = ll_tools_user_study_order_word_ids_by_recency($ordered, array_column($data['progress'], 'last_seen_at', 'word_id'));
    } else {
        shuffle($ordered);
    }

    $limit = ['practice' => 20, 'self-check' => 50, 'listening' => count($ordered)][$mode] ?? 10;
    if ($mode === 'gender') {
        $rows = ll_tools_get_user_word_gender_planning_rows($user_id, $ordered, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $stages = [];
        foreach ($ordered as $word) {
            $state = ll_tools_get_progress_row_gender_progress($rows[$word] ?? []);
            $stages[$word] = ll_tools_user_progress_normalize_gender_level($state['level'] ?? 1);
        }
        $stage = $stages[$ordered[0]];
        $ordered = array_keys($stages, $stage, true);
        $limits = ll_tools_user_study_selection_launch_limits($wordset_id);
        $limit = ll_tools_user_study_gender_launch_word_limits($wordset_id, $limits['max_words'])[$stage];
    }
    if ($mode === 'learning') {
        [$selected, $compatibility] = ll_tools_wordset_level_learn_selection($ordered, $owners, $meta);
    } else {
        $selected = [];
        foreach (array_slice($ordered, 0, $limit) as $word) { $selected[$word] = $owners[$word][0]; }
    }

    $activity = ll_tools_wordset_level_activity($mode, array_values($selected), array_keys($selected));
    $activity['word_category_ids'] = $selected;
    $plan = ll_tools_wordset_level_bounded_plan($activity, $mode === 'gender' ? $limit : 15);
    if ($mode === 'learning') {
        $learning = ['target_word_ids' => $activity['session_word_ids'], 'compatibility_key' => $compatibility];
        $plan['chunks'][0] += $learning;
        $plan += $learning + ['expanded_count' => 0, 'minimum_words' => min(8, count($selected)),
            'maximum_words' => 10, 'preferred_maximum_words' => 10, 'criteria' => ''];
    } elseif ($mode === 'gender') {
        foreach ($plan['chunks'] as &$chunk) {
            $chunk['details'] = ['gender_level' => $stage, 'gender_auto_continue' => $stage > 1];
        }
        unset($chunk);
    }
    $activity['bounded'] = $plan;
    return $activity;
}
