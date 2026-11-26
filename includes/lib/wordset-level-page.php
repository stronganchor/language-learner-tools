<?php
if (!defined('ABSPATH')) { exit; }

const LL_TOOLS_LEVEL_REVIEW_INTERVALS_META = 'll_tools_level_review_intervals';

/**
 * Project a complete, already visibility-filtered category catalog into public
 * cards and a signed-in request scope. Neither projection reads vocabulary.
 */
function ll_tools_wordset_level_page_catalog(int $wordset_id, array $categories, array $mixed_lessons): array {
    $by_id = [];
    foreach ($categories as $cat) {
        $id = (int) ($cat['id'] ?? 0);
        if ($id <= 0 || !empty($cat['hidden']) || empty($cat['is_public']) || !empty($cat['is_virtual_category'])) {
            continue;
        }
        $by_id[$id] = $cat;
    }
    $groups = ll_tools_wordset_curriculum_groups($wordset_id, array_keys($by_id));
    if (is_wp_error($groups) || empty($groups['enabled']) || empty($groups['levels'])) {
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

function ll_tools_wordset_level_default_days(int $level): int {
    return $level <= 2 ? 90 : 30;
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
        $days[$level] = $override >= 1 && $override <= 365 ? $override : ll_tools_wordset_level_default_days((int) $level);
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
    if ($wordset_id <= 0 || $wordset_id !== (int) ($payload['wordset_id'] ?? 0) || $user_id !== (int) ($payload['user_id'] ?? 0)
        || !ll_tools_wordset_page_category_search_payload_scope_is_current($wordset_id, (string) ($payload['access_signature'] ?? ''), $user_id)
        || empty($payload['level_policy']) || !hash_equals($payload['level_policy'], ll_tools_wordset_level_policy_signature($wordset_id))) {
        return new WP_Error('ll_level_scope_changed', __('Reload this page to update your levels.', 'll-tools-text-domain'), ['status' => 403]);
    }
    $scope = $payload['level_scope'];
    $goals = ll_tools_get_user_study_goals($user_id);
    $ignored = array_fill_keys((array) ($goals['ignored_category_ids'] ?? []), true);
    if ($ignored) {
        foreach ($scope as $key => $rows) {
            $scope[$key] = array_values(array_filter($rows, static function ($row) use ($ignored): bool {
                return !isset($ignored[$row['id']]);
            }));
        }
    }
    $operation = $request['operation'] ?? '';
    $level = filter_var($request['level'] ?? 0, FILTER_VALIDATE_INT);
    $mode = $request['launch_mode'] ?? '';
    $all_due = $operation === 'launch' && $mode === 'due' && $level === 0;
    if ($operation !== 'summary' && !$all_due && (!$level || empty($scope[$level]))) {
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
        }
        unset($row);
        return ['levels' => $dashboard['levels'], 'due' => $dashboard['overall']['due']];
    }
    if ($all_due) {
        $overall = ll_tools_wordset_level_overall_review($wordset_id, $user_id, $scope, $days, $now);
        $activity = is_wp_error($overall) ? $overall : $overall['activity'];
    } elseif ($operation === 'launch' && in_array($mode, ['learn', 'practice', 'due'], true)) {
        $activity = ll_tools_wordset_level_launch($wordset_id, $user_id, $scope[$level], $days[$level], $mode, $now);
    } else {
        return new WP_Error('ll_level_action_invalid', __('Unknown level action.', 'll-tools-text-domain'), ['status' => 400]);
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
