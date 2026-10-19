<?php
if (!defined('WPINC')) { die; }

/** Zero means all questions. Enabled quizzes start with fifteen questions. */
function ll_tools_validate_quiz_question_limit($value) {
    if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string) $value)
        || ((int) $value > 0 && (int) $value < 5)) {
        return new WP_Error('question_limit', __('Enter a whole number of at least 5 questions.', 'll-tools-text-domain'), ['status' => 400]);
    }
    return (int) $value;
}

function ll_tools_get_quiz_question_limit($term, ?bool &$complete = null): int {
    if (!($term instanceof WP_Term)) {
        $term = get_term((int) $term, 'word-category');
    }
    if (!($term instanceof WP_Term)) { $complete = false; return 0; }
    $raw = ll_tools_get_category_meta_with_source_fallback($term, 'll_quiz_question_limit', true, $complete);
    $limit = ll_tools_validate_quiz_question_limit($raw === '' ? 0 : $raw);
    return is_wp_error($limit) ? 0 : $limit;
}

/** A missing field preserves the setting for older clients. */
function ll_tools_quiz_question_limit_from_request(array $request, string $prefix = '') {
    $enabled_key = $prefix . 'quiz_sample_enabled';
    $count_key = $prefix . 'quiz_question_limit';
    if (!array_key_exists($enabled_key, $request) && !array_key_exists($count_key, $request)) {
        return null;
    }
    if (array_key_exists($enabled_key, $request)) {
        if (!is_scalar($request[$enabled_key]) || !in_array((string) $request[$enabled_key], ['0', '1'], true)) {
            return new WP_Error('question_limit', __('Invalid request.', 'll-tools-text-domain'), ['status' => 400]);
        }
        if ((string) $request[$enabled_key] === '0') { return 0; }
    }
    $limit = ll_tools_validate_quiz_question_limit($request[$count_key] ?? 15);
    if ($limit === 0 && isset($request[$enabled_key]) && (string) $request[$enabled_key] === '1') {
        return new WP_Error('question_limit', __('Enter a whole number of at least 5 questions.', 'll-tools-text-domain'), ['status' => 400]);
    }
    return $limit;
}

/** Shared staff fields: a separate switch preserves the entered count while off. */
function ll_tools_render_quiz_question_limit_fields(int $limit, string $prefix = 'll_'): void {
    ?>
    <div class="ll-quiz-sampling-settings">
        <label class="ll-vocab-lesson-category-settings-field">
            <span class="ll-vocab-lesson-category-settings-field__label"><?php esc_html_e('Practice questions', 'll-tools-text-domain'); ?></span>
            <select name="<?php echo esc_attr($prefix . 'quiz_sample_enabled'); ?>" class="ll-vocab-lesson-category-settings-select">
                <option value="0" <?php selected($limit, 0); ?>><?php esc_html_e('All questions', 'll-tools-text-domain'); ?></option>
                <option value="1" <?php selected($limit > 0); ?>><?php esc_html_e('Random selection', 'll-tools-text-domain'); ?></option>
            </select>
        </label>
        <label class="ll-vocab-lesson-category-settings-field">
            <span class="ll-vocab-lesson-category-settings-field__label"><?php esc_html_e('Questions per attempt', 'll-tools-text-domain'); ?></span>
            <input type="number" min="5" step="1" name="<?php echo esc_attr($prefix . 'quiz_question_limit'); ?>" value="<?php echo esc_attr((string) ($limit ?: 15)); ?>" class="ll-vocab-lesson-category-settings-select ll-quiz-question-limit" />
        </label>
        <p class="description"><?php esc_html_e('Choose a fresh random set for each Practice attempt. Missed questions are repeated for practice.', 'll-tools-text-domain'); ?></p>
    </div>
    <?php
}

/** Only independently quizzable rows belong in the sampling index. */
function ll_tools_quiz_sample_row_is_target(array $row): bool {
    return (int) ($row['id'] ?? 0) > 0
        && empty($row['is_prompt_card_support_only'])
        && empty($row['is_specific_wrong_answer_only']);
}

/**
 * Read exact materialized identities with a byte check before payload hydration.
 * The caller owns the generation lock. No posts or media are hydrated here.
 * @return array|WP_Error
 */
function ll_tools_quiz_sample_read_records(string $scope_hash, string $generation, array $identities, int &$bytes) {
    global $wpdb;
    if (!$identities) { return []; }
    if (count($identities) > 250) { return new WP_Error('flashcard_payload_page_row_too_large'); }
    $clauses = [];
    $params = [$scope_hash, $generation];
    foreach ($identities as $identity) {
        $clauses[] = '(row_kind = %s AND row_id = %d)';
        $params[] = (string) $identity['row_kind'];
        $params[] = (int) $identity['row_id'];
    }
    $where = 'scope_hash = %s AND generation = %s AND (' . implode(' OR ', $clauses) . ')';
    $table = ll_tools_flashcard_payload_table_name();
    $metadata = $wpdb->get_results($wpdb->prepare(
        "SELECT row_kind, row_id, payload_bytes, OCTET_LENGTH(payload) AS actual_bytes FROM {$table} WHERE {$where}",
        $params
    ), ARRAY_A);
    if ($wpdb->last_error !== '') { return new WP_Error('flashcard_payload_read_failed'); }
    $expected = [];
    foreach ((array) $metadata as $row) {
        $size = (int) $row['payload_bytes'];
        if ($size <= 0 || $size !== (int) $row['actual_bytes'] || $size > ll_tools_flashcard_payload_row_byte_limit()) {
            return new WP_Error('flashcard_payload_corrupt_row');
        }
        $bytes += $size;
        if ($bytes > 2 * 1024 * 1024) { return new WP_Error('flashcard_payload_page_row_too_large'); }
        $expected[$row['row_kind'] . ':' . $row['row_id']] = $size;
    }
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT row_kind, row_id, payload FROM {$table} WHERE {$where}", $params
    ), ARRAY_A);
    if ($wpdb->last_error !== '' || count((array) $records) !== count($expected)) {
        return new WP_Error('flashcard_payload_read_failed');
    }
    $rows = [];
    foreach ((array) $records as $record) {
        $key = $record['row_kind'] . ':' . $record['row_id'];
        if (strlen((string) $record['payload']) !== ($expected[$key] ?? 0)) {
            return new WP_Error('flashcard_payload_corrupt_row');
        }
        $row = json_decode($record['payload'], true);
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== (int) $record['row_id']) {
            return new WP_Error('flashcard_payload_corrupt_row');
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * A stable random sample over one complete durable category generation.
 * Hash ordering scans materialized identity metadata only; payload reads stay
 * capped at thirty targets plus bounded required/extra distractors per page.
 * The signed cursor binds the seed, count, scope and generation. Retries keep
 * their selection; a new attempt supplies a fresh seed.
 * @return array|WP_Error
 */
function ll_tools_quiz_sample_read_page(array $scope, int $question_limit, string $seed, string $cursor = '') {
    global $wpdb;
    if ($question_limit < 5 || !preg_match('/^[a-f0-9]{32}$/D', $seed)) {
        return new WP_Error('invalid_quiz_sample', __('Invalid request.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $scope = ll_tools_flashcard_payload_normalize_scope($scope);
    $scope_hash = ll_tools_flashcard_payload_scope_hash($scope);
    $ready = ll_tools_flashcard_payload_ensure_ready($scope);
    if (is_wp_error($ready)) { return $ready; }
    if (!$ready) { return new WP_Error('flashcard_payload_warming', '', ['retry_after' => 1]); }
    $lock = ll_tools_acquire_flashcard_payload_lock($scope_hash, 90);
    if (empty($lock['acquired'])) { return new WP_Error('flashcard_payload_warming', '', ['retry_after' => 1]); }
    try {
        $signature = ll_tools_flashcard_payload_dependency_signature($scope);
        $state = ll_tools_get_flashcard_payload_state($scope_hash);
        if (!ll_tools_flashcard_payload_state_is_ready($scope, $signature, $state, false)) {
            return new WP_Error('flashcard_payload_warming', '', ['retry_after' => 1]);
        }
        $generation = (string) $state['published_generation'];
        $cursor_scope = hash('sha256', $scope_hash . '|sample|' . $seed . '|' . $question_limit);
        $offset = 0;
        if ($cursor !== '') {
            $decoded = ll_tools_flashcard_payload_decode_cursor($cursor, $cursor_scope, $generation);
            if (is_wp_error($decoded)) { return $decoded; }
            $offset = (int) $decoded['sort_group'];
        }
        $table = ll_tools_flashcard_payload_table_name();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scope_hash = %s AND generation = %s AND sort_group < 2", $scope_hash, $generation
        ));
        if ($wpdb->last_error !== '' || $count === null) { return new WP_Error('flashcard_payload_read_failed'); }
        $total = min($question_limit, (int) $count);
        if ($offset > $total) { return new WP_Error('stale_flashcard_payload_cursor'); }
        $page_size = min(30, $total - $offset);
        $identities = $page_size > 0 ? $wpdb->get_results($wpdb->prepare(
            "SELECT row_kind, row_id FROM {$table} WHERE scope_hash = %s AND generation = %s AND sort_group < 2
             ORDER BY SHA2(CONCAT(%s, ':', row_kind, ':', row_id), 256), row_id LIMIT %d OFFSET %d",
            $scope_hash, $generation, $seed, $page_size, $offset
        ), ARRAY_A) : [];
        if ($wpdb->last_error !== '' || count((array) $identities) !== $page_size) { return new WP_Error('flashcard_payload_read_failed'); }
        $bytes = 0;
        $targets = ll_tools_quiz_sample_read_records($scope_hash, $generation, (array) $identities, $bytes);
        if (is_wp_error($targets)) { return $targets; }
        if (count($targets) !== $page_size) { return new WP_Error('flashcard_payload_read_failed'); }
        $target_ids = [];
        $support_ids = [];
        foreach ($targets as $row) {
            if (!ll_tools_quiz_sample_row_is_target($row)) { return new WP_Error('flashcard_payload_corrupt_row'); }
            $target_ids[] = (int) $row['id'];
            foreach ((array) ($row['specific_wrong_answer_ids'] ?? []) as $id) {
                if ((int) $id > 0) { $support_ids[(int) $id] = ['row_kind' => 'word', 'row_id' => (int) $id]; }
            }
        }
        // Supplemental choices may come from anywhere in the bank, including
        // questions outside this attempt. They never become target questions.
        $extra = $wpdb->get_results($wpdb->prepare(
            "SELECT row_kind, row_id FROM {$table} WHERE scope_hash = %s AND generation = %s AND sort_group < 2
             ORDER BY SHA2(CONCAT(%s, ':options:', row_kind, ':', row_id), 256), row_id LIMIT 12",
            $scope_hash, $generation, $seed
        ), ARRAY_A);
        if ($wpdb->last_error !== '') { return new WP_Error('flashcard_payload_read_failed'); }
        $support = [];
        $seen = [];
        foreach ($identities as $identity) { $seen[$identity['row_kind'] . ':' . $identity['row_id']] = true; }
        foreach (array_merge(array_values($support_ids), (array) $extra) as $identity) {
            $key = $identity['row_kind'] . ':' . $identity['row_id'];
            if (!isset($seen[$key])) { $support[] = $identity; $seen[$key] = true; }
        }
        $options = ll_tools_quiz_sample_read_records($scope_hash, $generation, $support, $bytes);
        if (is_wp_error($options)) { return $options; }
        $final_state = ll_tools_get_flashcard_payload_state($scope_hash);
        if (!ll_tools_renew_flashcard_payload_lock($lock)
            || !ll_tools_flashcard_payload_state_is_ready($scope, $signature, $final_state, false)
            || !hash_equals($generation, (string) ($final_state['published_generation'] ?? ''))
            || !hash_equals($signature, ll_tools_flashcard_payload_dependency_signature($scope))
            || !ll_tools_flashcard_payload_touch_access_locked($scope_hash, $final_state, $signature, $lock)) {
            return new WP_Error('stale_flashcard_payload_cursor');
        }
        $next = $offset + count($targets);
        return [
            'schema' => 1, 'rows' => array_merge($targets, $options),
            'sample_target_ids' => $target_ids, 'sample_total' => $total,
            'next_cursor' => $next < $total ? ll_tools_flashcard_payload_encode_cursor($cursor_scope, $generation, $next, 0) : '',
            'complete' => $next >= $total, 'generation' => $generation,
            'total_rows' => $total, 'page_rows' => count($targets) + count($options), 'page_bytes' => $bytes,
        ];
    } finally {
        ll_tools_release_flashcard_payload_lock($lock);
    }
}
