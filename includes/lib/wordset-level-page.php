<?php
if (!defined('ABSPATH')) { exit; }

const LL_TOOLS_LEVEL_REVIEW_INTERVALS_META = 'll_tools_level_review_intervals';

/**
 * Project a complete, already visibility-filtered category catalog into public
 * cards and a signed-in request scope. Neither projection reads vocabulary.
 */
function ll_tools_wordset_level_page_catalog(int $wordset_id, array $categories, array $mixed_lessons, ?bool &$complete = null, ?array $assignments = null): array {
    $complete = true;
    $by_id = [];
    foreach ($categories as $cat) {
        $id = (int) ($cat['id'] ?? 0);
        if ($id <= 0 || !empty($cat['hidden']) || empty($cat['is_public']) || !empty($cat['is_virtual_category'])) {
            continue;
        }
        $by_id[$id] = $cat;
    }
    $groups = ll_tools_wordset_curriculum_groups($wordset_id, array_keys($by_id), $assignments);
    if (is_wp_error($groups)) {
        $complete = false;
        return [];
    }
    if (empty($groups['enabled']) || empty($groups['levels'])) {
        return [];
    }
    $cards = [];
    $scope = [];
    foreach ($groups['levels'] as $level => $ids) {
        $card = [
            'level' => (int) $level, 'categoryIds' => [], 'contentIds' => [],
            'nextId' => 0, 'practiceId' => 0,
        ];
        foreach ($ids as $id) {
            if (!isset($by_id[$id])) {
                continue;
            }
            $row = $by_id[$id];
            $learning = !empty($row['learning_supported']);
            $practice = !array_key_exists('practice_supported', $row) || !empty($row['practice_supported']);
            $card['categoryIds'][] = (int) $id;
            if (!$card['nextId'] && $learning) {
                $card['nextId'] = (int) $id;
            }
            if (!$card['practiceId'] && $practice) {
                $card['practiceId'] = (int) $id;
            }
            $scope[$level][] = ['id' => (int) $id, 'learning_supported' => $learning, 'practice_supported' => $practice];
        }
        if ($card['categoryIds']) {
            $cards[$level] = $card;
        }
    }
    if (!$cards) {
        return [];
    }
    foreach ($mixed_lessons as $lesson) {
        $id = (int) ($lesson['id'] ?? 0);
        $level = (int) ($lesson['menu_order'] ?? 0);
        if ($id > 0 && isset($cards[$level])) {
            $cards[$level]['contentIds'][] = $id;
        }
    }
    ksort($cards, SORT_NUMERIC);
    return [
        'scope' => $scope,
        'public' => ['levels' => array_values($cards)],
    ];
}

function ll_tools_wordset_level_intervals(int $user_id, int $wordset_id, array $levels) {
    global $wpdb;
    $wpdb->last_error = '';
    $settings = get_user_meta($user_id, LL_TOOLS_LEVEL_REVIEW_INTERVALS_META, true);
    if ($wpdb->last_error !== '' || ($settings !== '' && !is_array($settings))
        || (isset($settings[$wordset_id]) && !is_array($settings[$wordset_id]))) {
        return ll_tools_wordset_level_read_error();
    }
    $days = [];
    foreach ($levels as $level) {
        $override = (int) ($settings[$wordset_id][$level] ?? 0);
        $days[$level] = $override >= 1 && $override <= 365 ? $override : 30;
    }
    return $days;
}

function ll_tools_wordset_level_read_error(): WP_Error {
    return new WP_Error('ll_level_data_unavailable', __('Could not load level progress. Please try again.', 'll-tools-text-domain'), ['status' => 503]);
}

function ll_tools_wordset_level_save_interval(int $user_id, int $wordset_id, int $level, int $days) {
    if ($user_id <= 0 || $wordset_id <= 0 || $level < 1 || $days < 1 || $days > 365) {
        return new WP_Error('ll_level_interval_invalid', __('Choose a review interval from 1 to 365 days.', 'll-tools-text-domain'), ['status' => 400]);
    }
    return ll_tools_offline_app_run_user_data_write_locked($user_id, static function () use ($user_id, $wordset_id, $level, $days) {
        global $wpdb;
        $wpdb->last_error = '';
        // A concurrent tab may have changed another level before we acquired the lock.
        wp_cache_delete($user_id, 'user_meta');
        $saved = get_user_meta($user_id, LL_TOOLS_LEVEL_REVIEW_INTERVALS_META, true);
        if ($wpdb->last_error !== '' || ($saved !== '' && !is_array($saved))
            || (isset($saved[$wordset_id]) && !is_array($saved[$wordset_id]))) {
            return ll_tools_wordset_level_read_error();
        }
        $saved = is_array($saved) ? $saved : [];
        $saved[$wordset_id][$level] = $days;
        if (!ll_tools_user_progress_update_meta_verified($user_id, LL_TOOLS_LEVEL_REVIEW_INTERVALS_META, $saved)) {
            return new WP_Error('ll_level_interval_unsaved', __('Could not save this review interval. Please try again.', 'll-tools-text-domain'), ['status' => 503]);
        }
        return ['level' => $level, 'days' => $days];
    });
}

/** Split an already ranked, exactly owned practice plan without reordering or hydrating it. */
function ll_tools_wordset_level_bounded_plan(array $activity): array {
    $chunks = [];
    $chunk = ['category_ids' => [], 'word_ids' => []];
    foreach ($activity['session_word_ids'] as $word_id) {
        $category_id = $activity['word_category_ids'][$word_id];
        $new_category = !in_array($category_id, $chunk['category_ids'], true);
        if (count($chunk['word_ids']) >= 12 || ($new_category && count($chunk['category_ids']) >= 8)) {
            $chunks[] = $chunk;
            $chunk = ['category_ids' => [], 'word_ids' => []];
            $new_category = true;
        }
        if ($new_category) { $chunk['category_ids'][] = $category_id; }
        $chunk['word_ids'][] = $word_id;
    }
    if ($chunk['word_ids']) { $chunks[] = $chunk; }
    $count = count($activity['session_word_ids']);
    return ($chunks[0] ?? $chunk) + ['mode' => 'practice', 'chunks' => $chunks, 'chunk_count' => count($chunks),
        'matched_count' => $count, 'planned_count' => $count, 'truncated' => false];
}

function ll_tools_wordset_level_visible_study_scope(array $scope, int $user_id, ?bool &$complete = null): array {
    global $wpdb;
    $wpdb->last_error = '';
    $goals = ll_tools_get_user_study_goals($user_id);
    $complete = $wpdb->last_error === '' && !is_wp_error(ll_tools_user_progress_get_source_error());
    if (!$complete) { return []; }
    $ignored = array_fill_keys((array) ($goals['ignored_category_ids'] ?? []), true);
    if ($ignored) {
        foreach ($scope as $key => $rows) {
            $scope[$key] = array_values(array_filter($rows, static function ($row) use ($ignored): bool {
                return !isset($ignored[$row['id']]);
            }));
        }
    }
    return $scope;
}

/** Dashboard and Progress must agree on the complete course before applying table filters. */
function ll_tools_wordset_level_analytics_due_lookup(int $wordset_id, int $user_id, ?array $categories = null) {
    if (!$wordset_id || $user_id !== get_current_user_id() || !$user_id) { return null; }
    if (!ll_tools_user_can_view_wordset($wordset_id, $user_id, $complete)) {
        return $complete ? [] : ll_tools_wordset_level_read_error();
    }
    $assignments = ll_tools_wordset_curriculum_assignment_map($wordset_id);
    if (is_wp_error($assignments)) { return $assignments; }
    if (!$assignments) { return null; } // Flat wordsets use the ordinary 30-day review window.
    if ($categories === null) {
        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2, ['defer_previews' => true], $complete);
        if (!$complete) { return ll_tools_wordset_level_read_error(); }
    }
    $catalog = ll_tools_wordset_level_page_catalog($wordset_id, $categories, [], $complete, $assignments);
    if (!$complete) { return ll_tools_wordset_level_read_error(); }
    if (!$catalog) { return []; }
    $scope = ll_tools_wordset_level_visible_study_scope($catalog['scope'], $user_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_read_error(); }
    $intervals = ll_tools_wordset_level_intervals($user_id, $wordset_id, array_keys($scope));
    if (is_wp_error($intervals)) { return $intervals; }
    $data = ll_tools_wordset_level_member_progress($wordset_id, $user_id, $scope, true);
    if (is_wp_error($data)) { return $data; }
    $reviews = ll_tools_wordset_level_course_reviews($wordset_id, $user_id, $data['levels'], $data['members'], $data['progress'], $intervals, time());
    if (is_wp_error($reviews)) { return $reviews; }
    return array_filter(array_column($reviews, 'due', 'id'));
}

/** Handle only the current learner's own level data and server-issued catalog scope. */
function ll_tools_wordset_level_request(int $user_id, array $request) {
    if ($user_id < 1 || $user_id !== get_current_user_id() || !ll_tools_user_study_can_access($user_id)) {
        return new WP_Error('ll_level_access', __('Please sign in to view your progress.', 'll-tools-text-domain'), ['status' => 403]);
    }
    $wordset_id = (int) filter_var($request['wordset_id'] ?? 0, FILTER_VALIDATE_INT);
    $token = $request['token'] ?? '';
    $payload = is_string($token) ? ll_tools_wordset_page_get_category_search_payload($token) : null;
    if (!$payload || empty($payload['level_scope'])) {
        return new WP_Error('ll_level_scope_expired', __('Reload this page to update your levels.', 'll-tools-text-domain'), ['status' => 410]);
    }
    if ($wordset_id <= 0 || $wordset_id !== (int) ($payload['wordset_id'] ?? 0) || $user_id !== (int) ($payload['user_id'] ?? 0)) {
        return new WP_Error('ll_level_scope_changed', __('Reload this page to update your levels.', 'll-tools-text-domain'), ['status' => 403]);
    }
    // admin-ajax can run in a different language from the tab that issued this catalog.
    $restore = ll_tools_flashcard_payload_enter_locale($payload['level_locale'] ?? determine_locale());
    if (is_wp_error($restore)) {
        return new WP_Error('ll_level_scope_expired', __('Reload this page to update your levels.', 'll-tools-text-domain'), ['status' => 410]);
    }
    try {
        return ll_tools_wordset_level_request_in_scope($user_id, $wordset_id, $payload, $request);
    } finally {
        $restore();
    }
}

function ll_tools_wordset_level_request_in_scope(int $user_id, int $wordset_id, array $payload, array $request) {
    if (!ll_tools_wordset_page_category_search_payload_scope_is_current($wordset_id, (string) ($payload['access_signature'] ?? ''), $user_id)
        || empty($payload['level_policy']) || !hash_equals($payload['level_policy'], ll_tools_wordset_level_policy_signature($wordset_id))) {
        return new WP_Error('ll_level_scope_changed', __('Reload this page to update your levels.', 'll-tools-text-domain'), ['status' => 403]);
    }
    $scope = ll_tools_wordset_level_visible_study_scope($payload['level_scope'], $user_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_read_error(); }
    $operation = $request['operation'] ?? '';
    $level = filter_var($request['level'] ?? 0, FILTER_VALIDATE_INT);
    $mode = $request['launch_mode'] ?? '';
    $global_review = $operation === 'launch' && in_array($mode, ['due', 'review'], true) && $level === 0;
    if ($operation !== 'summary' && !$global_review && (!$level || empty($scope[$level]))) {
        return new WP_Error('ll_level_unknown', __('This level is unavailable.', 'll-tools-text-domain'), ['status' => 400]);
    }
    if ($operation === 'interval') {
        return ll_tools_wordset_level_save_interval($user_id, $wordset_id, $level, (int) filter_var($request['days'] ?? 0, FILTER_VALIDATE_INT));
    }
    $days = ll_tools_wordset_level_intervals($user_id, $wordset_id, array_keys($scope));
    if (is_wp_error($days)) {
        return $days;
    }
    $now = time();
    if ($operation === 'summary') {
        $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, $scope, $days, $now);
        if (is_wp_error($dashboard)) {
            return $dashboard;
        }
        foreach ($dashboard['levels'] as $key => &$row) {
            $row['days'] = $days[$key];
            if ($row['practice_activity']) {
                // Cards need only the preview. Resolve exact targets again when the learner starts.
                $row['practice_activity']['word_count'] = count($row['practice_activity']['session_word_ids']);
                unset($row['practice_activity']['session_word_ids'], $row['practice_activity']['word_category_ids']);
            }
        }
        unset($row);
        $overall = $dashboard['overall'];
        return [
            'levels' => $dashboard['levels'],
            'due' => $overall['due'],
            'review' => ['reason' => $overall['reason'], 'count' => $overall['count'], 'due' => $overall['due']],
        ];
    }
    if ($global_review) {
        $overall = ll_tools_wordset_level_overall_review($wordset_id, $user_id, $scope, $days, $now);
        if (is_wp_error($overall)) { return $overall; }
        $activity = $mode === 'due' && !$overall['due'] ? null : $overall['activity'];
        if ($activity && $mode === 'review') { $activity['reason'] = $overall['reason']; }
    } elseif ($operation === 'launch' && in_array($mode, ['learn', 'practice', 'due'], true)) {
        $activity = ll_tools_wordset_level_launch($wordset_id, $user_id, $scope[$level], $days[$level], $mode, $now, $level);
    } else {
        return new WP_Error('ll_level_action_invalid', __('Unknown level action.', 'll-tools-text-domain'), ['status' => 400]);
    }
    if (!is_wp_error($activity ?? null) && !empty($activity['session_word_ids'])) {
        $activity['bounded'] = ll_tools_wordset_level_bounded_plan($activity);
    }
    return $activity ?? new WP_Error('ll_level_no_activity', __('There is nothing to practice in this selection right now.', 'll-tools-text-domain'), ['status' => 409]);
}

function ll_tools_wordset_levels_ajax(): void {
    check_ajax_referer('ll_tools_wordset_levels', 'nonce');
    $result = ll_tools_wordset_level_request(get_current_user_id(), wp_unslash($_POST));
    if (is_wp_error($result)) {
        $details = (array) $result->get_error_data();
        wp_send_json_error(array_merge($details, [
            'code' => $result->get_error_code(), 'message' => $result->get_error_message(),
        ]), (int) ($details['status'] ?? 503));
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_wordset_levels', 'll_tools_wordset_levels_ajax');

function ll_tools_wordset_level_badge(int $level): string {
    if ($level <= 0) {
        return '';
    }
    return '<span class="ll-wordset-level-badge" data-ll-level-badge="' . esc_attr((string) $level) . '">'
        . esc_html(sprintf(__('Level %d', 'll-tools-text-domain'), $level)) . '</span>';
}

/** Inline prerequisite editing can change a level without changing category visibility. */
function ll_tools_wordset_level_policy_signature(int $wordset_id): string {
    global $wpdb;
    $policy = [];
    foreach (['ll_wordset_category_ordering_mode', 'll_wordset_category_prerequisites'] as $key) {
        $wpdb->last_error = '';
        $policy[] = get_term_meta($wordset_id, $key, false);
        if ($wpdb->last_error !== '') {
            return '';
        }
    }
    return hash('sha256', serialize($policy));
}
