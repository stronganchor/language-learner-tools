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

/** Reuse the player payload for one lesson. An unavailable starter falls back to the normal loader. */
function ll_tools_wordset_level_listening_starter(int $wordset_id, int $user_id, int $cid, array $ids = []): array {
    $term = get_term($cid, 'word-category');
    if (!$term instanceof WP_Term || !ll_tools_user_can_view_category($term, $user_id, $complete) || !$complete) { return []; }
    $minimum = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $config = ll_tools_resolve_effective_category_quiz_config($term, $minimum, [$wordset_id], $complete);
    if (!$complete) { return []; }
    if ($ids) {
        $config['__candidate_word_ids'] = $ids;
        $config['__skip_image_similarity_pairs'] = true;
        $rows = ll_get_words_by_category($term, $config['option_type'], [$wordset_id], $config, $complete);
        return $complete && $rows ? ['starter_rows' => ll_tools_flashcards_prepare_client_rows($rows, [$wordset_id], $term, $config)] : [];
    }
    $scope = ll_tools_flashcard_payload_build_scope($term, [$wordset_id], $config);
    if (is_wp_error($scope)) { return []; }
    $state = ll_tools_get_flashcard_payload_state(ll_tools_flashcard_payload_scope_hash($scope));
    // Only borrow an already published generation. The native page loader owns warmup and retries.
    if (empty($state['published_generation'])
        || !ll_tools_flashcard_payload_state_is_ready($scope, ll_tools_flashcard_payload_dependency_signature($scope), $state, false)) { return []; }
    $page = ll_tools_flashcard_payload_read_page($scope, '', 12);
    if (is_wp_error($page) || empty($page['rows'])) { return []; }
    return ['starter_page' => [
        'category_id' => $cid,
        'rows' => ll_tools_flashcards_prepare_client_rows($page['rows'], [$wordset_id], $term, $config),
        'next_cursor' => (string) ($page['next_cursor'] ?? ''),
    ]];
}

/** Plan the selected level and optionally include its first playable rows. */
function ll_tools_wordset_level_quiz(int $wordset_id, int $user_id, array $categories, string $mode, bool $fast_start = false) {
    $categories = array_filter($categories, static function (array $category) use ($mode): bool {
        return ($category['is_public'] ?? true) && ll_tools_wordset_level_category_supports_quiz($category, $mode);
    });
    if (!$categories) { return null; }
    $fast_listen = $user_id > 0 && $fast_start && $mode === 'listening';
    $data = ll_tools_wordset_level_member_progress($wordset_id, $user_id, [$categories], false, $mode, $fast_listen);
    if ($fast_listen && is_wp_error($data) && $data->get_error_code() === 'll_level_progress_preparing') {
        // An unopened level can start its ordinary incremental player while the overview warms.
        $ids = array_column($categories, 'id');
        $history = ll_tools_wordset_level_history($user_id, $wordset_id, $ids);
        if (is_wp_error($history)) { return $history; }
        usort($ids, static function ($left, $right) use ($history) {
            return strcmp($history[$left] ?? '', $history[$right] ?? '') ?: ($left <=> $right);
        });
        return ll_tools_wordset_level_activity('listening', $ids) + ['stream_categories' => true]
            + ll_tools_wordset_level_listening_starter($wordset_id, $user_id, $ids[0]);
    }
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
    $plan = ll_tools_wordset_level_bounded_plan($activity, $mode === 'gender' ? $limit : 15,
        $fast_start && in_array($mode, ['practice', 'self-check', 'listening'], true));
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
    if ($fast_listen) {
        $first = $plan['chunks'][0];
        $activity += ll_tools_wordset_level_listening_starter($wordset_id, $user_id, $first['category_ids'][0], $first['word_ids']);
    }
    return $activity;
}
