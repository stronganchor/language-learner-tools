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
            $scope[$level][] = ['id' => (int) $id, 'learning_supported' => $learning, 'practice_supported' => $practice,
                'listening_supported' => !empty($row['listening_supported']),
                'gender_supported' => !empty($row['gender_supported']),
                'self_check_supported' => !isset($row['self_check_supported']) || $row['self_check_supported']];
        }
        if ($card['categoryIds']) {
            $card['modes'] = ll_tools_wordset_level_quiz_modes($scope[$level]);
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

/** Public catalog data is enough to paint the level layout before the interactive controls load. */
function ll_tools_wordset_level_render_initial_catalog(array $catalog, bool $signed_in): string {
    ob_start();
    ?>
    <section data-ll-wordset-levels aria-label="<?php esc_attr_e('Course levels', 'll-tools-text-domain'); ?>">
        <?php if ($signed_in) : ?>
            <span class="ll-level-button ll-level-review-placeholder ll-level-placeholder ll-level-sheen" aria-hidden="true"></span>
        <?php endif; ?>
        <div class="ll-level-grid">
            <?php foreach ($catalog['levels'] as $group) :
                $slots = (int) !empty($group['nextId']) + (int) !empty($group['practiceId']);
                $has_categories = !empty($group['categoryIds']);
                ?>
                <article class="ll-level-card" data-ll-level-card="<?php echo (int) $group['level']; ?>">
                    <div class="ll-level-row">
                        <div class="ll-level-overview">
                            <div class="ll-level-card-head">
                                <h2 class="ll-level-title"><span class="ll-level-shell-title"><?php
                                    echo esc_html(sprintf($catalog['i18n']['level'], (int) $group['level']));
                                ?></span></h2>
                                <?php if ($signed_in && $has_categories) : ?>
                                    <div class="ll-level-badges" aria-hidden="true">
                                        <?php for ($i = 0; $i < 3; ++$i) : ?>
                                            <span class="ll-level-badge-placeholder"><span class="ll-level-badge ll-level-sheen"></span></span>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($slots) : ?>
                            <div class="ll-level-activities" aria-hidden="true">
                                <?php for ($i = 0; $i < ($signed_in ? $slots : 1); ++$i) : ?>
                                    <span class="ll-level-button ll-level-activity ll-level-placeholder ll-level-sheen"></span>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($has_categories) : ?>
                        <div class="ll-wordset-card__progress" aria-hidden="true">
                            <span class="ll-wordset-card__progress-toggle"><span class="ll-wordset-card__progress-track is-loading"></span></span>
                        </div>
                    <?php endif; ?>
                    <div class="ll-level-details" aria-hidden="true"></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
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
function ll_tools_wordset_level_bounded_plan(array $activity, int $chunk_size = 12, bool $fast_start = false): array {
    $chunks = [];
    $chunk = ['category_ids' => [], 'word_ids' => []];
    $category_limit = $fast_start ? 2 : 8;
    foreach ($activity['session_word_ids'] as $word_id) {
        $category_id = $activity['word_category_ids'][$word_id];
        $new_category = !in_array($category_id, $chunk['category_ids'], true);
        // Only one lesson's rows are needed before the first round can start.
        $starter_full = $fast_start && !$chunks && $chunk['word_ids'] && ($new_category || count($chunk['word_ids']) >= 4);
        if ($starter_full || count($chunk['word_ids']) >= $chunk_size || ($new_category && count($chunk['category_ids']) >= $category_limit)) {
            $chunks[] = $chunk;
            $chunk = ['category_ids' => [], 'word_ids' => []];
            $new_category = true;
        }
        if ($new_category) { $chunk['category_ids'][] = $category_id; }
        $chunk['word_ids'][] = $word_id;
    }
    if ($chunk['word_ids']) { $chunks[] = $chunk; }
    $count = count($activity['session_word_ids']);
    return ($chunks[0] ?? $chunk) + ['mode' => $activity['mode'], 'chunks' => $chunks, 'chunk_count' => count($chunks),
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
    if ($user_id !== get_current_user_id() || ($user_id ? !ll_tools_user_study_can_access($user_id) : ($request['operation'] ?? '') !== 'quiz')) {
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
        if (($request['operation'] ?? '') === 'launch' && ($request['launch_mode'] ?? '') === 'review' && $user_id > 0) {
            // Resolve current preferences, visible targets and the saved batch under the same learner lock.
            return ll_tools_offline_app_run_user_data_write_locked($user_id, static function () use ($user_id, $wordset_id, $payload, $request) {
                wp_cache_delete($user_id, 'user_meta');
                return ll_tools_wordset_level_request_in_scope($user_id, $wordset_id, $payload, $request);
            });
        }
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
    $complete = true;
    $scope = $user_id ? ll_tools_wordset_level_visible_study_scope($payload['level_scope'], $user_id, $complete) : $payload['level_scope'];
    if (!$complete) { return ll_tools_wordset_level_read_error(); }
    $operation = $request['operation'] ?? '';
    if ($operation === 'completion_seen') {
        return ll_tools_wordset_level_observe_completions($user_id, $wordset_id, $scope, $request['levels'] ?? []);
    }
    $level = filter_var($request['level'] ?? 0, FILTER_VALIDATE_INT);
    $mode = $request['launch_mode'] ?? '';
    $global_review = $operation === 'launch' && in_array($mode, ['due', 'review'], true) && $level === 0;
    if ($operation !== 'summary' && !$global_review && (!$level || empty($scope[$level]))) {
        return new WP_Error('ll_level_unknown', __('This level is unavailable.', 'll-tools-text-domain'), ['status' => 400]);
    }
    if ($operation === 'quiz') {
        if (!in_array($mode, ll_tools_get_study_launch_mode_order(true), true)) {
            return new WP_Error('ll_level_action_invalid', __('Unknown level action.', 'll-tools-text-domain'), ['status' => 400]);
        }
        return ll_tools_wordset_level_quiz($wordset_id, $user_id, $scope[$level], $mode, !empty($request['fast_start']))
            ?? new WP_Error('ll_level_no_activity', __('No quiz words are available for this selection.', 'll-tools-text-domain'), ['status' => 409]);
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
        $reported_levels = !empty($request['progressive'])
            ? array_intersect(array_keys($scope), wp_parse_id_list($request['ready_levels'] ?? []))
            : null;
        $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, $scope, $days, $now, true, $reported_levels);
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
        if (!empty($dashboard['preparing'])) { return $dashboard; }
        $overall = $dashboard['overall'];
        $next_midnight = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone())->modify('tomorrow');
        return [
            'levels' => $dashboard['levels'],
            'due' => $overall['due'],
            'next_reset_in' => $next_midnight->getTimestamp() - $now,
            'review' => ['reason' => $overall['reason'], 'count' => $overall['count'], 'due' => $overall['due']],
        ];
    }
    if ($global_review) {
        $overall = ll_tools_wordset_level_overall_review($wordset_id, $user_id, $scope, $days, $now, $mode === 'review');
        if (is_wp_error($overall)) { return $overall; }
        $activity = $mode === 'due' && !$overall['due'] ? null : $overall['activity'];
        if ($activity && $mode === 'review') { $activity['reason'] = $overall['reason']; }
    } elseif ($operation === 'launch' && in_array($mode, ['learn', 'practice', 'due', 'hard', 'starred'], true)) {
        $activity = ll_tools_wordset_level_launch($wordset_id, $user_id, $scope[$level], $days[$level], $mode, $now, $level);
    } else {
        return new WP_Error('ll_level_action_invalid', __('Unknown level action.', 'll-tools-text-domain'), ['status' => 400]);
    }
    if (!is_wp_error($activity ?? null) && !empty($activity['session_word_ids'])) {
        $activity['bounded'] = ll_tools_wordset_level_bounded_plan($activity, 12, !empty($request['fast_start']));
    }
    return $activity ?? new WP_Error('ll_level_no_activity', __('There is nothing to practice in this selection right now.', 'll-tools-text-domain'), ['status' => 409]);
}

function ll_tools_wordset_levels_ajax(): void {
    check_ajax_referer('ll_tools_wordset_levels', 'nonce');
    $lease = [];
    if (!is_user_logged_in()) {
        $throttle = ll_tools_flashcards_public_ajax_throttle_status();
        if (empty($throttle['allowed'])) { ll_tools_flashcards_public_ajax_send_throttle_response($throttle); }
        $lease = ll_tools_flashcards_public_ajax_acquire_client_inflight();
        if (empty($lease['acquired'])) { ll_tools_flashcards_public_ajax_send_client_busy_response($lease); }
    }
    try {
        $result = ll_tools_wordset_level_request(get_current_user_id(), wp_unslash($_POST));
    } finally {
        if ($lease) { ll_tools_flashcards_public_ajax_release_client_inflight($lease); }
    }
    if (is_wp_error($result)) {
        $details = (array) $result->get_error_data();
        wp_send_json_error(array_merge($details, [
            'code' => $result->get_error_code(), 'message' => $result->get_error_message(),
        ]), (int) ($details['status'] ?? 503));
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_wordset_levels', 'll_tools_wordset_levels_ajax');
add_action('wp_ajax_nopriv_ll_tools_wordset_levels', 'll_tools_wordset_levels_ajax');

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

function ll_tools_wordset_level_completion_key(): string {
    global $wpdb;
    return $wpdb->get_blog_prefix() . 'll_tools_level_completions_seen';
}

function ll_tools_wordset_level_completion_history(int $user_id) {
    global $wpdb;
    $wpdb->last_error = '';
    $history = get_user_meta($user_id, ll_tools_wordset_level_completion_key(), true);
    if ($wpdb->last_error !== '' || ($history !== '' && !is_array($history))) { return ll_tools_wordset_level_read_error(); }
    return $history === '' ? [] : $history;
}

/** These receipts record what this learner has seen. They never grant or change mastery. */
function ll_tools_wordset_level_observe_completions(int $user_id, int $wordset_id, array $scope, $observed) {
    if (!$user_id || !$wordset_id || !is_array($observed) || !$observed || count($observed) > 32) {
        return ll_tools_wordset_level_read_error();
    }
    foreach ($observed as $level => $value) {
        if (!is_int($level) || empty($scope[$level]) || !in_array($value, [0, 1, '0', '1'], true)) {
            return ll_tools_wordset_level_read_error();
        }
    }
    return ll_tools_offline_app_run_user_data_write_locked($user_id, static function () use ($user_id, $wordset_id, $observed) {
        wp_cache_delete($user_id, 'user_meta');
        $courses = ll_tools_wordset_level_completion_history($user_id);
        if (is_wp_error($courses)) { return $courses; }
        $history = $courses[$wordset_id] ?? [];
        if (!is_array($history)) { return ll_tools_wordset_level_read_error(); }
        $next = $history;
        $celebrate = [];
        foreach ($observed as $level => $value) {
            if (($history[$level] ?? null) === 0 && (int) $value === 1) { $celebrate[] = $level; }
            // Unknown completed levels establish a quiet baseline; old tabs cannot reset it.
            $next[$level] = max((int) ($history[$level] ?? 0), (int) $value);
        }
        if ($next !== $history) {
            $courses[$wordset_id] = $next;
            if (!ll_tools_user_progress_update_meta_verified($user_id, ll_tools_wordset_level_completion_key(), $courses)) {
                return ll_tools_wordset_level_read_error();
            }
        }
        return ['history' => array_intersect_key($next, $observed), 'celebrate' => $celebrate];
    });
}
