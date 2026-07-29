<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META')) {
    define('LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META', 'll_tools_completed_content_lessons');
}

/**
 * Normalize the compact user-meta list used for completed content lessons.
 *
 * @param mixed $raw
 * @return int[]
 */
function ll_tools_normalize_completed_content_lesson_ids(
    $raw,
    ?bool &$complete = null
): array {
    $complete = true;
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            $raw = [];
        } else {
            $decoded = json_decode($trimmed, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $trimmed);
        }
    } elseif (!is_array($raw)) {
        $raw = ($raw === null || $raw === '') ? [] : [$raw];
    }

    $limit = max(100, min(20000, (int) apply_filters(
        'll_tools_completed_content_lesson_ids_limit',
        5000
    )));
    $ids = [];
    foreach ((array) $raw as $raw_id) {
        $lesson_id = absint($raw_id);
        if ($lesson_id <= 0) {
            continue;
        }
        if (!isset($ids[$lesson_id]) && count($ids) >= $limit) {
            $complete = false;
            break;
        }
        $ids[$lesson_id] = $lesson_id;
    }

    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * @return int[]
 */
function ll_tools_get_completed_content_lesson_ids(int $user_id = 0): array {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    return ll_tools_normalize_completed_content_lesson_ids(
        get_user_meta($user_id, LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META, true)
    );
}

function ll_tools_user_completed_content_lesson(int $lesson_id, int $user_id = 0): bool {
    if ($lesson_id <= 0) {
        return false;
    }

    return in_array($lesson_id, ll_tools_get_completed_content_lesson_ids($user_id), true);
}

/**
 * Mutate the compact completion list with compare-and-swap retries.
 *
 * Keeping an empty array instead of deleting the row is intentional. A delete
 * followed by a create leaves a race where another request can create the same
 * unique row and have its completion silently replaced.
 *
 * @param callable(int[]): mixed $mutator
 */
function ll_tools_mutate_completed_content_lesson_ids(
    int $user_id,
    callable $mutator
): bool {
    if ($user_id <= 0 || !get_userdata($user_id)) {
        return false;
    }

    $meta_key = LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META;
    $attempt_limit = max(2, min(10, (int) apply_filters(
        'll_tools_content_lesson_completion_cas_attempts',
        5,
        $user_id
    )));

    for ($attempt = 0; $attempt < $attempt_limit; $attempt++) {
        $exists = metadata_exists('user', $user_id, $meta_key);
        $before_raw = $exists ? get_user_meta($user_id, $meta_key, true) : null;
        $before_complete = true;
        $before_ids = ll_tools_normalize_completed_content_lesson_ids(
            $before_raw,
            $before_complete
        );
        if (!$before_complete) {
            return false;
        }
        $next_complete = true;
        $next_ids = ll_tools_normalize_completed_content_lesson_ids(
            $mutator($before_ids),
            $next_complete
        );
        if (!$next_complete) {
            return false;
        }

        if ($exists && $next_ids === $before_ids && is_array($before_raw)) {
            return true;
        }

        if (!$exists) {
            $written = add_user_meta($user_id, $meta_key, $next_ids, true);
        } else {
            $written = update_user_meta(
                $user_id,
                $meta_key,
                $next_ids,
                $before_raw
            );
        }
        wp_cache_delete($user_id, 'user_meta');

        if ($written !== false
            && ll_tools_get_completed_content_lesson_ids($user_id) === $next_ids
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Merge migrated completion IDs without replacing concurrent user activity.
 *
 * @param mixed $lesson_ids
 */
function ll_tools_merge_completed_content_lesson_ids(
    int $user_id,
    $lesson_ids
): bool {
    $merge_complete = true;
    $merge_ids = ll_tools_normalize_completed_content_lesson_ids(
        $lesson_ids,
        $merge_complete
    );
    if (!$merge_complete) {
        return false;
    }
    if ($user_id <= 0 || empty($merge_ids)) {
        return $user_id > 0;
    }

    return ll_tools_mutate_completed_content_lesson_ids(
        $user_id,
        static function (array $before_ids) use ($merge_ids): array {
            return array_merge($before_ids, $merge_ids);
        }
    );
}

function ll_tools_set_content_lesson_completion(
    int $user_id,
    int $lesson_id,
    bool $completed
): bool {
    if ($user_id <= 0 || $lesson_id <= 0 || get_post_type($lesson_id) !== 'll_content_lesson') {
        return false;
    }

    $written = ll_tools_mutate_completed_content_lesson_ids(
        $user_id,
        static function (array $before_ids) use ($lesson_id, $completed): array {
            $lookup = array_fill_keys($before_ids, true);
            if ($completed) {
                $lookup[$lesson_id] = true;
            } else {
                unset($lookup[$lesson_id]);
            }
            return array_keys($lookup);
        }
    );
    if (!$written) {
        return false;
    }

    return ll_tools_user_completed_content_lesson($lesson_id, $user_id) === $completed;
}

/**
 * Return a bounded, display-ready snapshot of content-lesson prerequisites.
 *
 * @return array<int,array{id:int,title:string,url:string,completed:bool}>
 */
function ll_tools_get_content_lesson_prerequisite_status_rows(
    int $lesson_id,
    int $user_id = 0
): array {
    if ($lesson_id <= 0 || !function_exists('ll_tools_get_content_lesson_prereq_lesson_ids')) {
        return [];
    }

    $prerequisite_ids = ll_tools_get_content_lesson_prereq_lesson_ids($lesson_id);
    $limit = max(10, min(500, (int) apply_filters(
        'll_tools_content_lesson_prerequisite_status_limit',
        200,
        $lesson_id
    )));
    $prerequisite_ids = array_slice($prerequisite_ids, 0, $limit);
    if (empty($prerequisite_ids)) {
        return [];
    }

    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => count($prerequisite_ids),
        'post__in' => $prerequisite_ids,
        'orderby' => 'post__in',
        'no_found_rows' => true,
        'suppress_filters' => false,
    ]);
    $completed_lookup = array_fill_keys(
        ll_tools_get_completed_content_lesson_ids($user_id),
        true
    );
    $rows = [];
    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }
        $prerequisite_id = (int) $post->ID;
        $url = (string) get_permalink($prerequisite_id);
        if ($prerequisite_id <= 0 || $url === '') {
            continue;
        }
        $rows[] = [
            'id' => $prerequisite_id,
            'title' => (string) get_the_title($prerequisite_id),
            'url' => $url,
            'completed' => !empty($completed_lookup[$prerequisite_id]),
        ];
    }

    return $rows;
}

function ll_tools_render_content_lesson_prerequisites(int $lesson_id, int $user_id = 0): string {
    $rows = ll_tools_get_content_lesson_prerequisite_status_rows($lesson_id, $user_id);
    if (empty($rows)) {
        return '';
    }

    $is_logged_in = $user_id > 0 || is_user_logged_in();
    $html = '<section class="ll-content-lesson-prerequisites" aria-labelledby="ll-content-lesson-prerequisites-title">';
    $html .= '<h2 id="ll-content-lesson-prerequisites-title" class="ll-content-lessons-section__title">';
    $html .= esc_html__('Before this lesson', 'll-tools-text-domain');
    $html .= '</h2><ul class="ll-content-lesson-prerequisites__list">';
    foreach ($rows as $row) {
        $is_completed = !empty($row['completed']);
        $class_name = 'll-content-lesson-prerequisites__item';
        if ($is_completed) {
            $class_name .= ' is-completed';
        }
        $status = $is_completed
            ? __('Completed', 'll-tools-text-domain')
            : __('Not completed', 'll-tools-text-domain');
        $html .= '<li class="' . esc_attr($class_name) . '">';
        $html .= '<a href="' . esc_url((string) $row['url']) . '">';
        $html .= '<span class="ll-content-lesson-prerequisites__status" aria-hidden="true">'
            . ($is_completed ? '&#10003;' : '&#9675;')
            . '</span>';
        $html .= '<span>' . esc_html((string) $row['title']) . '</span>';
        $html .= '</a>';
        if ($is_logged_in) {
            $html .= '<span class="screen-reader-text"> — ' . esc_html($status) . '</span>';
        }
        $html .= '</li>';
    }
    $html .= '</ul></section>';
    return $html;
}

/**
 * Return a bounded reverse edge list for the legacy "this lesson is a
 * prerequisite for" footer without scanning every lesson.
 *
 * @return array<int,array{id:int,title:string,url:string,completed:bool}>
 */
function ll_tools_get_content_lesson_dependent_status_rows(
    int $lesson_id,
    int $user_id = 0
): array {
    if ($lesson_id <= 0 || !function_exists('ll_tools_get_content_lesson_wordset_id')) {
        return [];
    }
    $wordset_id = ll_tools_get_content_lesson_wordset_id($lesson_id);
    if ($wordset_id <= 0) {
        return [];
    }

    $limit = max(4, min(100, (int) apply_filters(
        'll_tools_content_lesson_dependent_status_limit',
        24,
        $lesson_id
    )));
    $dependent_ids = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC'],
        'post__not_in' => [$lesson_id],
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
            [
                'key' => LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                'value' => 'i:' . $lesson_id . ';',
                'compare' => 'LIKE',
            ],
        ],
    ]);
    if (empty($dependent_ids)) {
        return [];
    }

    $completed_lookup = array_fill_keys(
        ll_tools_get_completed_content_lesson_ids($user_id),
        true
    );
    $rows = [];
    foreach ((array) $dependent_ids as $dependent_id) {
        $dependent_id = (int) $dependent_id;
        $url = (string) get_permalink($dependent_id);
        if ($dependent_id <= 0 || $url === '') {
            continue;
        }
        $rows[] = [
            'id' => $dependent_id,
            'title' => (string) get_the_title($dependent_id),
            'url' => $url,
            'completed' => !empty($completed_lookup[$dependent_id]),
        ];
    }
    return $rows;
}

function ll_tools_render_content_lesson_dependents(int $lesson_id, int $user_id = 0): string {
    $rows = ll_tools_get_content_lesson_dependent_status_rows($lesson_id, $user_id);
    if (empty($rows)) {
        return '';
    }

    $html = '<section class="ll-content-lesson-prerequisites ll-content-lesson-dependents" aria-labelledby="ll-content-lesson-dependents-title">';
    $html .= '<h2 id="ll-content-lesson-dependents-title" class="ll-content-lessons-section__title">';
    $html .= esc_html__('Continue learning', 'll-tools-text-domain');
    $html .= '</h2><ul class="ll-content-lesson-prerequisites__list">';
    foreach ($rows as $row) {
        $class_name = 'll-content-lesson-prerequisites__item';
        if (!empty($row['completed'])) {
            $class_name .= ' is-completed';
        }
        $html .= '<li class="' . esc_attr($class_name) . '"><a href="'
            . esc_url((string) $row['url']) . '">';
        $html .= '<span class="ll-content-lesson-prerequisites__status" aria-hidden="true">&#8594;</span>';
        $html .= '<span>' . esc_html((string) $row['title']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul></section>';
    return $html;
}

function ll_tools_render_content_lesson_completion_control(int $lesson_id, int $user_id = 0): string {
    if ($lesson_id <= 0
        || get_post_type($lesson_id) !== 'll_content_lesson'
        || get_post_status($lesson_id) !== 'publish'
    ) {
        return '';
    }

    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return '<a class="ll-content-lesson-progress-login" href="'
            . esc_url(wp_login_url((string) get_permalink($lesson_id)))
            . '">' . esc_html__('Log in to save lesson progress', 'll-tools-text-domain') . '</a>';
    }

    $is_completed = ll_tools_user_completed_content_lesson($lesson_id, $user_id);
    $label = $is_completed
        ? __('Completed', 'll-tools-text-domain')
        : __('Mark complete', 'll-tools-text-domain');
    $class_name = 'll-content-lesson-progress-button';
    if ($is_completed) {
        $class_name .= ' is-completed';
    }

    return '<button type="button" class="' . esc_attr($class_name)
        . '" data-ll-content-lesson-progress data-lesson-id="' . esc_attr((string) $lesson_id)
        . '" data-completed="' . ($is_completed ? '1' : '0')
        . '" aria-pressed="' . ($is_completed ? 'true' : 'false') . '">'
        . '<span class="ll-content-lesson-progress-button__icon" aria-hidden="true">'
        . ($is_completed ? '&#10003;' : '&#9675;')
        . '</span><span data-ll-content-lesson-progress-label>' . esc_html($label) . '</span></button>'
        . '<span class="ll-content-lesson-progress-status" data-ll-content-lesson-progress-status role="status" aria-live="polite"></span>';
}

function ll_tools_verify_content_lesson_completion_nonce(string $nonce): bool {
    return (bool) wp_verify_nonce($nonce, 'll_tools_content_lesson_completion');
}

/**
 * Validate and persist one completion request without coupling the contract to
 * wp_send_json(), so every authorization/readback path is directly testable.
 *
 * @return array{lesson_id:int,completed:bool}|WP_Error
 */
function ll_tools_update_content_lesson_completion_request(
    int $user_id,
    int $lesson_id,
    bool $completed
) {
    if ($user_id <= 0 || !get_userdata($user_id)) {
        return new WP_Error(
            'content_lesson_completion_login_required',
            __('Log in to save lesson progress.', 'll-tools-text-domain')
        );
    }
    if ($lesson_id <= 0
        || get_post_type($lesson_id) !== 'll_content_lesson'
        || get_post_status($lesson_id) !== 'publish'
    ) {
        return new WP_Error(
            'content_lesson_completion_lesson_invalid',
            __('Select a valid published lesson.', 'll-tools-text-domain')
        );
    }

    $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id($lesson_id)
        : 0;
    $is_corpus_text = function_exists('ll_tools_content_lesson_is_corpus_text')
        && ll_tools_content_lesson_is_corpus_text($lesson_id);
    if (!$is_corpus_text
        && ($wordset_id <= 0
            || (function_exists('ll_tools_user_can_view_wordset')
                && !ll_tools_user_can_view_wordset($wordset_id, $user_id)))
    ) {
        return new WP_Error(
            'content_lesson_completion_forbidden',
            __('You cannot update progress for this lesson.', 'll-tools-text-domain')
        );
    }

    if (!ll_tools_set_content_lesson_completion($user_id, $lesson_id, $completed)) {
        return new WP_Error(
            'content_lesson_completion_write_failed',
            __('Lesson progress could not be saved.', 'll-tools-text-domain')
        );
    }

    return [
        'lesson_id' => $lesson_id,
        'completed' => ll_tools_user_completed_content_lesson(
            $lesson_id,
            $user_id
        ),
    ];
}

function ll_tools_content_lesson_completion_ajax(): void {
    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message' => __('Log in to save lesson progress.', 'll-tools-text-domain'),
        ], 401);
    }

    $nonce = isset($_POST['nonce']) ? wp_unslash((string) $_POST['nonce']) : '';
    if (!ll_tools_verify_content_lesson_completion_nonce($nonce)) {
        wp_send_json_error([
            'message' => __('The progress request could not be verified.', 'll-tools-text-domain'),
        ], 403);
    }

    $lesson_id = isset($_POST['lesson_id']) ? absint(wp_unslash((string) $_POST['lesson_id'])) : 0;
    $completed = isset($_POST['completed'])
        && in_array(wp_unslash((string) $_POST['completed']), ['1', 'true'], true);
    $result = ll_tools_update_content_lesson_completion_request(
        get_current_user_id(),
        $lesson_id,
        $completed
    );
    if (is_wp_error($result)) {
        $status = 500;
        if ($result->get_error_code() === 'content_lesson_completion_lesson_invalid') {
            $status = 400;
        } elseif ($result->get_error_code() === 'content_lesson_completion_forbidden') {
            $status = 403;
        }
        wp_send_json_error([
            'message' => $result->get_error_message(),
        ], $status);
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_content_lesson_completion', 'll_tools_content_lesson_completion_ajax');
add_action('wp_ajax_nopriv_ll_tools_content_lesson_completion', 'll_tools_content_lesson_completion_ajax');
