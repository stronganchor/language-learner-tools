<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION')) {
    define('LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION', 'll_tools_wordset_editor_action_history');
}

function ll_tools_wordset_editor_history_limit(): int {
    return 120;
}

function ll_tools_wordset_editor_get_history(): array {
    $history = get_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION, []);
    return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
}

function ll_tools_wordset_editor_save_history(array $history): void {
    $history = array_values(array_filter($history, 'is_array'));
    usort($history, static function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });
    $history = array_slice($history, 0, ll_tools_wordset_editor_history_limit());
    update_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION, $history, false);
}

function ll_tools_wordset_editor_log_action(int $wordset_id, string $type, string $summary, array $payload = [], bool $undoable = true): string {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return '';
    }

    $type = sanitize_key($type);
    if ($type === '') {
        return '';
    }

    $action_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ll-we-', true);
    $entry = [
        'id'         => $action_id,
        'wordset_id' => $wordset_id,
        'type'       => $type,
        'summary'    => sanitize_text_field($summary),
        'payload'    => $payload,
        'user_id'    => get_current_user_id(),
        'created_at' => current_time('mysql'),
        'undoable'   => (bool) $undoable,
        'undone'     => false,
        'undone_at'  => '',
    ];

    $history = ll_tools_wordset_editor_get_history();
    array_unshift($history, $entry);
    ll_tools_wordset_editor_save_history($history);

    return $action_id;
}

function ll_tools_wordset_editor_get_recent_actions(int $wordset_id, int $limit = 8): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $rows = [];
    foreach (ll_tools_wordset_editor_get_history() as $entry) {
        if ((int) ($entry['wordset_id'] ?? 0) !== $wordset_id) {
            continue;
        }
        $rows[] = $entry;
        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function ll_tools_wordset_editor_mark_action_undone(string $action_id): void {
    $history = ll_tools_wordset_editor_get_history();
    foreach ($history as &$entry) {
        if ((string) ($entry['id'] ?? '') !== $action_id) {
            continue;
        }
        $entry['undone'] = true;
        $entry['undone_at'] = current_time('mysql');
        break;
    }
    unset($entry);
    ll_tools_wordset_editor_save_history($history);
}

function ll_tools_wordset_editor_get_action(string $action_id, int $wordset_id): ?array {
    foreach (ll_tools_wordset_editor_get_history() as $entry) {
        if ((string) ($entry['id'] ?? '') === $action_id && (int) ($entry['wordset_id'] ?? 0) === (int) $wordset_id) {
            return $entry;
        }
    }

    return null;
}

function ll_tools_wordset_editor_normalize_word_ids($raw): array {
    $ids = array_map('absint', (array) $raw);
    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}

function ll_tools_wordset_editor_get_available_category_ids(array $category_rows): array {
    $ids = [];
    foreach ($category_rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return function_exists('ll_tools_word_grid_normalize_category_id_list')
        ? ll_tools_word_grid_normalize_category_id_list($ids)
        : array_values(array_unique($ids));
}

function ll_tools_wordset_editor_get_category_labels(array $category_rows): array {
    $labels = [];
    foreach ($category_rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $labels[$id] = ll_tools_wordset_editor_category_row_label($row);
    }

    return $labels;
}

function ll_tools_wordset_editor_category_row_label(array $category_row): string {
    foreach (['label', 'display_name', 'name'] as $key) {
        $label = trim((string) ($category_row[$key] ?? ''));
        if ($label !== '') {
            return $label;
        }
    }

    return '';
}

function ll_tools_wordset_editor_word_belongs_to_wordset(int $word_id, int $wordset_id): bool {
    $post = get_post($word_id);
    return $post instanceof WP_Post
        && $post->post_type === 'words'
        && $post->post_status !== 'trash'
        && has_term($wordset_id, 'wordset', $word_id);
}

function ll_tools_wordset_editor_get_selected_word_ids_from_post(int $wordset_id): array {
    $ids = ll_tools_wordset_editor_normalize_word_ids($_POST['ll_wordset_editor_word_ids'] ?? []);
    return array_values(array_filter($ids, static function (int $word_id) use ($wordset_id): bool {
        return ll_tools_wordset_editor_word_belongs_to_wordset($word_id, $wordset_id);
    }));
}

function ll_tools_wordset_editor_get_translation(int $word_id): string {
    $translation = trim((string) get_post_meta($word_id, 'word_translation', true));
    if ($translation === '') {
        $translation = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
    }

    return $translation;
}

function ll_tools_wordset_editor_get_audio_counts(array $word_ids): array {
    global $wpdb;

    $word_ids = ll_tools_wordset_editor_normalize_word_ids($word_ids);
    if (empty($word_ids)) {
        return [];
    }

    $counts = [];
    foreach ($word_ids as $word_id) {
        $counts[$word_id] = [
            'published' => 0,
            'total'     => 0,
        ];
    }

    $placeholders = implode(',', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT p.post_parent AS word_id, p.post_status, COUNT(*) AS total
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
           AND pm.meta_key = %s
           AND pm.meta_value <> ''
        WHERE p.post_type = %s
          AND p.post_status <> %s
          AND p.post_parent IN ({$placeholders})
        GROUP BY p.post_parent, p.post_status
    ";
    $params = array_merge(['audio_file_path', 'word_audio', 'trash'], $word_ids);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    foreach ((array) $rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        if ($word_id <= 0 || !isset($counts[$word_id])) {
            continue;
        }
        $total = (int) ($row['total'] ?? 0);
        $counts[$word_id]['total'] += $total;
        if ((string) ($row['post_status'] ?? '') === 'publish') {
            $counts[$word_id]['published'] += $total;
        }
    }

    return $counts;
}

function ll_tools_wordset_editor_word_requires_audio(int $word_id): bool {
    return function_exists('ll_word_requires_audio_to_publish') ? (bool) ll_word_requires_audio_to_publish($word_id) : true;
}

function ll_tools_wordset_editor_get_all_word_ids(int $wordset_id): array {
    $query = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [(int) $wordset_id],
            ],
        ],
    ]);

    return ll_tools_wordset_editor_normalize_word_ids($query->posts);
}

function ll_tools_wordset_editor_get_filters(): array {
    $filters = [
        'q'         => '',
        'category'  => 0,
        'status'    => '',
        'image'     => '',
        'recording' => '',
        'paged'     => 1,
    ];

    if (isset($_GET['ll_editor_q'])) {
        $filters['q'] = sanitize_text_field(wp_unslash((string) $_GET['ll_editor_q']));
    }
    if (isset($_GET['ll_editor_category'])) {
        $filters['category'] = absint(wp_unslash((string) $_GET['ll_editor_category']));
    }
    if (isset($_GET['ll_editor_status'])) {
        $status = sanitize_key(wp_unslash((string) $_GET['ll_editor_status']));
        $filters['status'] = in_array($status, ['publish', 'draft', 'pending', 'private'], true) ? $status : '';
    }
    if (isset($_GET['ll_editor_image'])) {
        $image = sanitize_key(wp_unslash((string) $_GET['ll_editor_image']));
        $filters['image'] = in_array($image, ['has', 'missing'], true) ? $image : '';
    }
    if (isset($_GET['ll_editor_recording'])) {
        $recording = sanitize_key(wp_unslash((string) $_GET['ll_editor_recording']));
        $filters['recording'] = in_array($recording, ['has', 'missing', 'none'], true) ? $recording : '';
    }
    if (isset($_GET['ll_editor_page'])) {
        $filters['paged'] = max(1, absint(wp_unslash((string) $_GET['ll_editor_page'])));
    }

    return $filters;
}

function ll_tools_wordset_editor_build_rows(int $wordset_id, array $category_rows, array $filters = []): array {
    $filters = array_merge(ll_tools_wordset_editor_get_filters(), $filters);
    $word_ids = ll_tools_wordset_editor_get_all_word_ids($wordset_id);
    $audio_counts = ll_tools_wordset_editor_get_audio_counts($word_ids);
    $available_category_ids = ll_tools_wordset_editor_get_available_category_ids($category_rows);
    $category_labels = ll_tools_wordset_editor_get_category_labels($category_rows);
    $search = strtolower(trim((string) ($filters['q'] ?? '')));
    $filtered_rows = [];
    $summary = [
        'total'         => count($word_ids),
        'missing_audio' => 0,
        'missing_image' => 0,
    ];

    foreach ($word_ids as $word_id) {
        $post = get_post($word_id);
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $title = get_the_title($word_id);
        $translation = ll_tools_wordset_editor_get_translation($word_id);
        $selected_category_ids = function_exists('ll_tools_word_grid_get_selected_category_ids_for_editor')
            ? ll_tools_word_grid_get_selected_category_ids_for_editor($word_id, $wordset_id, $available_category_ids)
            : [];
        $selected_category_labels = [];
        foreach ($selected_category_ids as $category_id) {
            if (isset($category_labels[$category_id])) {
                $selected_category_labels[] = $category_labels[$category_id];
            }
        }

        $has_image = function_exists('ll_tools_word_has_effective_image')
            ? ll_tools_word_has_effective_image($word_id, true)
            : (has_post_thumbnail($word_id));
        $published_audio_count = (int) ($audio_counts[$word_id]['published'] ?? 0);
        $total_audio_count = (int) ($audio_counts[$word_id]['total'] ?? 0);
        $requires_audio = ll_tools_wordset_editor_word_requires_audio($word_id);
        $missing_audio = $requires_audio && $published_audio_count <= 0;

        if ($missing_audio) {
            $summary['missing_audio']++;
        }
        if (!$has_image) {
            $summary['missing_image']++;
        }

        if ($search !== '') {
            $haystack = strtolower($title . ' ' . $translation . ' ' . implode(' ', $selected_category_labels));
            if (strpos($haystack, $search) === false) {
                continue;
            }
        }
        if ((int) ($filters['category'] ?? 0) > 0 && !in_array((int) $filters['category'], $selected_category_ids, true)) {
            continue;
        }
        if ((string) ($filters['status'] ?? '') !== '' && $post->post_status !== (string) $filters['status']) {
            continue;
        }
        if ((string) ($filters['image'] ?? '') === 'has' && !$has_image) {
            continue;
        }
        if ((string) ($filters['image'] ?? '') === 'missing' && $has_image) {
            continue;
        }
        if ((string) ($filters['recording'] ?? '') === 'has' && $published_audio_count <= 0) {
            continue;
        }
        if ((string) ($filters['recording'] ?? '') === 'missing' && !$missing_audio) {
            continue;
        }
        if ((string) ($filters['recording'] ?? '') === 'none' && $published_audio_count > 0) {
            continue;
        }

        $filtered_rows[] = [
            'id'                       => $word_id,
            'title'                    => $title,
            'translation'              => $translation,
            'status'                   => (string) $post->post_status,
            'selected_category_ids'    => $selected_category_ids,
            'selected_category_labels' => $selected_category_labels,
            'has_image'                => $has_image,
            'published_audio_count'    => $published_audio_count,
            'total_audio_count'        => $total_audio_count,
            'requires_audio'           => $requires_audio,
            'missing_audio'            => $missing_audio,
            'edit_url'                 => get_edit_post_link($word_id, ''),
        ];
    }

    return [
        'rows'    => $filtered_rows,
        'summary' => $summary,
    ];
}

function ll_tools_wordset_editor_get_summary(int $wordset_id): array {
    $category_rows = function_exists('ll_tools_word_grid_get_category_editor_rows')
        ? ll_tools_word_grid_get_category_editor_rows($wordset_id)
        : [];
    $result = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, [
        'q'         => '',
        'category'  => 0,
        'status'    => '',
        'image'     => '',
        'recording' => '',
    ]);
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    $summary['recent_actions'] = count(ll_tools_wordset_editor_get_recent_actions($wordset_id, 20));

    return $summary;
}

function ll_tools_wordset_editor_restore_statuses(array $records): int {
    $restored = 0;
    foreach ($records as $record) {
        $word_id = (int) ($record['word_id'] ?? 0);
        $status = sanitize_key((string) ($record['previous_status'] ?? ''));
        if ($word_id <= 0 || $status === '') {
            continue;
        }
        $updated = wp_update_post([
            'ID'          => $word_id,
            'post_status' => $status,
        ], true);
        if (!is_wp_error($updated) && (int) $updated > 0) {
            $restored++;
        }
    }

    return $restored;
}

function ll_tools_wordset_editor_undo_action(string $action_id, int $wordset_id) {
    $entry = ll_tools_wordset_editor_get_action($action_id, $wordset_id);
    if (!$entry) {
        return new WP_Error('ll_wordset_editor_undo_missing', __('Recent action was not found.', 'll-tools-text-domain'));
    }
    if (empty($entry['undoable'])) {
        return new WP_Error('ll_wordset_editor_undo_not_allowed', __('This action cannot be undone here.', 'll-tools-text-domain'));
    }
    if (!empty($entry['undone'])) {
        return new WP_Error('ll_wordset_editor_undo_already_done', __('This action has already been undone.', 'll-tools-text-domain'));
    }

    $type = sanitize_key((string) ($entry['type'] ?? ''));
    $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
    $restored = 0;

    if (in_array($type, ['word_trash', 'bulk_trash'], true)) {
        $records = isset($payload['words']) && is_array($payload['words']) ? $payload['words'] : [];
        if (empty($records) && isset($payload['word_id'])) {
            $records = [$payload];
        }
        foreach ($records as $record) {
            $word_id = (int) ($record['word_id'] ?? 0);
            if ($word_id <= 0 || get_post_status($word_id) !== 'trash') {
                continue;
            }
            $untrashed = wp_untrash_post($word_id);
            if ($untrashed) {
                $restored++;
            }
        }
    } elseif ($type === 'recording_trash') {
        $recording_id = (int) ($payload['recording_id'] ?? 0);
        $word_id = (int) ($payload['word_id'] ?? 0);
        if ($recording_id > 0 && get_post_status($recording_id) === 'trash' && wp_untrash_post($recording_id)) {
            $restored++;
        }
        if ($word_id > 0 && function_exists('ll_tools_sync_parent_word_status_by_children')) {
            ll_tools_sync_parent_word_status_by_children($word_id);
        }
    } elseif ($type === 'recording_move') {
        $recording_id = (int) ($payload['recording_id'] ?? 0);
        $source_word_id = (int) ($payload['source_word_id'] ?? 0);
        $target_word_id = (int) ($payload['target_word_id'] ?? 0);
        if ($recording_id > 0 && $source_word_id > 0 && get_post_status($recording_id) !== 'trash') {
            $updated = wp_update_post([
                'ID'          => $recording_id,
                'post_parent' => $source_word_id,
            ], true);
            if (!is_wp_error($updated) && (int) $updated > 0) {
                $restored++;
                if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
                    ll_tools_sync_parent_word_status_by_children($source_word_id);
                    if ($target_word_id > 0) {
                        ll_tools_sync_parent_word_status_by_children($target_word_id);
                    }
                }
            }
        }
    } elseif ($type === 'bulk_status') {
        $restored = ll_tools_wordset_editor_restore_statuses((array) ($payload['words'] ?? []));
    } elseif ($type === 'bulk_categories') {
        $available_category_ids = array_map('intval', (array) ($payload['available_category_ids'] ?? []));
        foreach ((array) ($payload['words'] ?? []) as $record) {
            $word_id = (int) ($record['word_id'] ?? 0);
            $previous_ids = array_map('intval', (array) ($record['previous_selected_category_ids'] ?? []));
            if ($word_id <= 0 || empty($available_category_ids) || !function_exists('ll_tools_word_grid_update_word_categories_for_wordset')) {
                continue;
            }
            $result = ll_tools_word_grid_update_word_categories_for_wordset($word_id, $wordset_id, $previous_ids, $available_category_ids);
            if (!is_wp_error($result)) {
                $restored++;
            }
        }
    } elseif ($type === 'bulk_missing_audio_review') {
        foreach ((array) ($payload['words'] ?? []) as $record) {
            $word_id = (int) ($record['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $previous_note = (string) ($record['previous_note'] ?? '');
            if (function_exists('ll_tools_set_internal_review_note')) {
                ll_tools_set_internal_review_note($word_id, $previous_note);
            }
            $status = sanitize_key((string) ($record['previous_status'] ?? ''));
            if ($status !== '') {
                wp_update_post([
                    'ID'          => $word_id,
                    'post_status' => $status,
                ]);
            }
            $restored++;
        }
    }

    if ($restored <= 0) {
        return new WP_Error('ll_wordset_editor_undo_empty', __('Nothing could be restored from that action.', 'll-tools-text-domain'));
    }

    ll_tools_wordset_editor_mark_action_undone($action_id);
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    wp_cache_delete('ll_vocab_lesson_deep_counts_' . (int) $wordset_id, 'll_tools');

    return $restored;
}

function ll_tools_wordset_editor_redirect_with_notice(WP_Term $wordset_term, string $back_url, string $status, string $result, int $count = 0, int $blocked = 0): void {
    $url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor', $back_url);
    wp_safe_redirect(add_query_arg([
        'll_wordset_manager_editor'         => $status,
        'll_wordset_manager_editor_result'  => sanitize_key($result),
        'll_wordset_manager_editor_count'   => max(0, $count),
        'll_wordset_manager_editor_blocked' => max(0, $blocked),
    ], $url));
    exit;
}

function ll_tools_wordset_editor_invalidate_wordset(int $wordset_id): void {
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    wp_cache_delete('ll_vocab_lesson_deep_counts_' . (int) $wordset_id, 'll_tools');
}

function ll_tools_wordset_page_handle_manager_editor_action(): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!function_exists('ll_tools_is_wordset_page_context') || !ll_tools_is_wordset_page_context()) {
        return;
    }

    $action = isset($_POST['ll_wordset_manager_editor_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_wordset_manager_editor_action']))
        : '';
    if (!in_array($action, ['publish', 'draft', 'add_category', 'remove_category', 'move_category', 'missing_audio_review', 'trash', 'undo'], true)) {
        return;
    }

    $wordset_term = ll_tools_get_wordset_page_term();
    if (!$wordset_term || is_wp_error($wordset_term)) {
        return;
    }

    $wordset_id = (int) $wordset_term->term_id;
    $submitted_wordset_id = isset($_POST['ll_wordset_manager_editor_wordset_id'])
        ? absint(wp_unslash((string) $_POST['ll_wordset_manager_editor_wordset_id']))
        : 0;
    $back_url = function_exists('ll_tools_wordset_page_resolve_back_url')
        ? ll_tools_wordset_page_resolve_back_url($wordset_term)
        : '';
    $nonce = isset($_POST['ll_wordset_manager_editor_nonce'])
        ? wp_unslash((string) $_POST['ll_wordset_manager_editor_nonce'])
        : '';

    $redirect_error = static function (string $error) use ($wordset_term, $back_url): void {
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'error', $error);
    };

    if ($submitted_wordset_id !== $wordset_id) {
        $redirect_error('wordset');
    }
    if (!function_exists('ll_tools_current_user_can_manage_wordset_content') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        $redirect_error('permission');
    }
    if (!wp_verify_nonce($nonce, 'll_wordset_manager_editor_' . $wordset_id)) {
        $redirect_error('nonce');
    }

    if ($action === 'undo') {
        $action_id = isset($_POST['ll_wordset_editor_action_id'])
            ? sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_editor_action_id']))
            : '';
        $result = $action_id !== '' ? ll_tools_wordset_editor_undo_action($action_id, $wordset_id) : new WP_Error('missing_action_id', __('Choose an action to undo.', 'll-tools-text-domain'));
        if (is_wp_error($result)) {
            $redirect_error('undo');
        }
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'undo', (int) $result);
    }

    $selected_word_ids = ll_tools_wordset_editor_get_selected_word_ids_from_post($wordset_id);
    if (empty($selected_word_ids)) {
        $redirect_error('selection');
    }

    $category_rows = function_exists('ll_tools_word_grid_get_category_editor_rows')
        ? ll_tools_word_grid_get_category_editor_rows($wordset_id)
        : [];
    $available_category_ids = ll_tools_wordset_editor_get_available_category_ids($category_rows);
    $target_category_id = isset($_POST['ll_wordset_editor_target_category'])
        ? absint(wp_unslash((string) $_POST['ll_wordset_editor_target_category']))
        : 0;

    $changed = 0;
    $blocked = 0;
    $history_words = [];

    if (in_array($action, ['publish', 'draft'], true)) {
        $new_status = $action === 'publish' ? 'publish' : 'draft';
        foreach ($selected_word_ids as $word_id) {
            $previous_status = (string) get_post_status($word_id);
            if ($previous_status === '') {
                continue;
            }
            $updated = wp_update_post([
                'ID'          => $word_id,
                'post_status' => $new_status,
            ], true);
            if (is_wp_error($updated) || (int) $updated <= 0) {
                $blocked++;
                continue;
            }
            $current_status = (string) get_post_status($word_id);
            if ($current_status !== $new_status) {
                $blocked++;
                continue;
            }
            if ($previous_status !== $current_status) {
                $changed++;
                $history_words[] = [
                    'word_id'         => $word_id,
                    'previous_status' => $previous_status,
                ];
            }
        }
        if (!empty($history_words)) {
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'bulk_status',
                sprintf(
                    $new_status === 'publish'
                        ? _n('Published %d word.', 'Published %d words.', $changed, 'll-tools-text-domain')
                        : _n('Moved %d word to draft.', 'Moved %d words to draft.', $changed, 'll-tools-text-domain'),
                    $changed
                ),
                ['words' => $history_words, 'new_status' => $new_status]
            );
        }
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', $action, $changed, $blocked);
    }

    if (in_array($action, ['add_category', 'remove_category', 'move_category'], true)) {
        if ($target_category_id <= 0 || !in_array($target_category_id, $available_category_ids, true) || !function_exists('ll_tools_word_grid_get_selected_category_ids_for_editor') || !function_exists('ll_tools_word_grid_update_word_categories_for_wordset')) {
            $redirect_error('category');
        }
        foreach ($selected_word_ids as $word_id) {
            $previous_ids = ll_tools_word_grid_get_selected_category_ids_for_editor($word_id, $wordset_id, $available_category_ids);
            $next_ids = $previous_ids;
            if ($action === 'add_category' && !in_array($target_category_id, $next_ids, true)) {
                $next_ids[] = $target_category_id;
            } elseif ($action === 'remove_category') {
                $next_ids = array_values(array_diff($next_ids, [$target_category_id]));
            } elseif ($action === 'move_category') {
                $next_ids = [$target_category_id];
            }
            $result = ll_tools_word_grid_update_word_categories_for_wordset($word_id, $wordset_id, $next_ids, $available_category_ids);
            if (is_wp_error($result)) {
                $blocked++;
                continue;
            }
            if (!empty($result['changed'])) {
                $changed++;
                $history_words[] = [
                    'word_id'                        => $word_id,
                    'previous_selected_category_ids' => $previous_ids,
                ];
            }
        }
        if (!empty($history_words)) {
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'bulk_categories',
                sprintf(_n('Updated categories for %d word.', 'Updated categories for %d words.', $changed, 'll-tools-text-domain'), $changed),
                [
                    'words'                  => $history_words,
                    'available_category_ids' => $available_category_ids,
                    'target_category_id'     => $target_category_id,
                    'category_action'        => $action,
                ]
            );
        }
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', $action, $changed, $blocked);
    }

    if ($action === 'missing_audio_review') {
        $review_note = __('Missing audio review: this word needs a published recording before it is learner-ready.', 'll-tools-text-domain');
        foreach ($selected_word_ids as $word_id) {
            $published_audio = function_exists('ll_tools_word_has_published_audio') ? ll_tools_word_has_published_audio($word_id) : false;
            if (!ll_tools_wordset_editor_word_requires_audio($word_id) || $published_audio) {
                $blocked++;
                continue;
            }
            $previous_note = function_exists('ll_tools_get_internal_review_note') ? ll_tools_get_internal_review_note($word_id) : '';
            $previous_status = (string) get_post_status($word_id);
            $next_note = $previous_note;
            if (strpos($previous_note, $review_note) === false) {
                $next_note = trim($previous_note . "\n\n" . $review_note);
            }
            if (function_exists('ll_tools_set_internal_review_note')) {
                ll_tools_set_internal_review_note($word_id, $next_note);
            }
            if ($previous_status !== 'draft') {
                wp_update_post([
                    'ID'          => $word_id,
                    'post_status' => 'draft',
                ]);
            }
            $changed++;
            $history_words[] = [
                'word_id'         => $word_id,
                'previous_note'   => $previous_note,
                'previous_status' => $previous_status,
            ];
        }
        if (!empty($history_words)) {
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'bulk_missing_audio_review',
                sprintf(_n('Flagged %d word for missing audio.', 'Flagged %d words for missing audio.', $changed, 'll-tools-text-domain'), $changed),
                ['words' => $history_words]
            );
        }
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'missing_audio_review', $changed, $blocked);
    }

    if ($action === 'trash') {
        foreach ($selected_word_ids as $word_id) {
            $previous_status = (string) get_post_status($word_id);
            $trashed = wp_trash_post($word_id);
            if (!$trashed) {
                $blocked++;
                continue;
            }
            $changed++;
            $history_words[] = [
                'word_id'         => $word_id,
                'previous_status' => $previous_status,
            ];
        }
        if (!empty($history_words)) {
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'bulk_trash',
                sprintf(_n('Moved %d word to Trash.', 'Moved %d words to Trash.', $changed, 'll-tools-text-domain'), $changed),
                ['words' => $history_words]
            );
        }
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'trash', $changed, $blocked);
    }
}
add_action('template_redirect', 'll_tools_wordset_page_handle_manager_editor_action', 6);

function ll_tools_wordset_page_manager_editor_notice(): ?array {
    if (!isset($_GET['ll_wordset_manager_editor'])) {
        return null;
    }

    $status = sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_editor']));
    $result = isset($_GET['ll_wordset_manager_editor_result'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_wordset_manager_editor_result']))
        : '';
    $count = isset($_GET['ll_wordset_manager_editor_count'])
        ? absint(wp_unslash((string) $_GET['ll_wordset_manager_editor_count']))
        : 0;
    $blocked = isset($_GET['ll_wordset_manager_editor_blocked'])
        ? absint(wp_unslash((string) $_GET['ll_wordset_manager_editor_blocked']))
        : 0;

    if ($status !== 'ok') {
        return [
            'type'    => 'error',
            'message' => __('The editor action could not be completed. Check the selection and try again.', 'll-tools-text-domain'),
        ];
    }

    $message = __('Editor action completed.', 'll-tools-text-domain');
    if ($result === 'publish') {
        $message = sprintf(_n('Published %d word.', 'Published %d words.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'draft') {
        $message = sprintf(_n('Moved %d word to draft.', 'Moved %d words to draft.', $count, 'll-tools-text-domain'), $count);
    } elseif (in_array($result, ['add_category', 'remove_category', 'move_category'], true)) {
        $message = sprintf(_n('Updated categories for %d word.', 'Updated categories for %d words.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'missing_audio_review') {
        $message = sprintf(_n('Flagged %d word for missing audio review.', 'Flagged %d words for missing audio review.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'trash') {
        $message = sprintf(_n('Moved %d word to Trash.', 'Moved %d words to Trash.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'undo') {
        $message = sprintf(_n('Undid %d item.', 'Undid %d items.', $count, 'll-tools-text-domain'), $count);
    }

    if ($blocked > 0) {
        $message .= ' ' . sprintf(_n('%d item was skipped.', '%d items were skipped.', $blocked, 'll-tools-text-domain'), $blocked);
    }

    return [
        'type'    => 'success',
        'message' => $message,
    ];
}

function ll_tools_wordset_editor_status_label(string $status): string {
    if ($status === 'publish') {
        return __('Published', 'll-tools-text-domain');
    }
    if ($status === 'draft') {
        return __('Draft', 'll-tools-text-domain');
    }
    if ($status === 'pending') {
        return __('Pending', 'll-tools-text-domain');
    }
    if ($status === 'private') {
        return __('Private', 'll-tools-text-domain');
    }

    return ucfirst($status);
}

function ll_tools_wordset_editor_icon(string $icon, string $class = 'll-wordset-editor-icon'): string {
    $icon = sanitize_key($icon);
    $base = '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">';
    if ($icon === 'search') {
        return $base . '<circle cx="10.5" cy="10.5" r="5.75" stroke="currentColor" stroke-width="1.9"/><path d="m15 15 4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
    }
    if ($icon === 'table') {
        return $base . '<rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M4 10h16M9 5v14M15 5v14" stroke="currentColor" stroke-width="1.8"/></svg>';
    }
    if ($icon === 'image') {
        return $base . '<rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.4" fill="currentColor"/><path d="m6.5 17 3.25-3.25L12 16l2.5-3 3 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if ($icon === 'audio') {
        return $base . '<path d="M6 9.5v5M9 7.5v9M12 5.5v13M15 7.5v9M18 9.5v5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
    }
    if ($icon === 'category') {
        return $base . '<rect x="4.5" y="5" width="6.5" height="6" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="5" width="6.5" height="6" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4.5" y="13" width="6.5" height="6" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="6.5" height="6" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>';
    }
    if ($icon === 'check') {
        return $base . '<path d="M5.5 12.5 10 17l8.5-10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if ($icon === 'draft') {
        return $base . '<path d="M6 5.5h8l4 4v9H6v-13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 5.5v4h4M8.5 14h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    if ($icon === 'review') {
        return $base . '<path d="M5 6.5h14v10H8l-3 3v-13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 10h7M8.5 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    if ($icon === 'trash') {
        return $base . '<path d="M7 8h10M10 8V6h4v2M9 10.5v6M12 10.5v6M15 10.5v6M8 8l.7 11h6.6L16 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if ($icon === 'undo') {
        return $base . '<path d="M9 8H5V4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.5 8A7 7 0 1 1 6.8 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
    }

    return $base . '<circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.8"/></svg>';
}

function ll_tools_wordset_page_render_settings_editor_tool(WP_Term $wordset_term, int $wordset_id, string $back_url, array $category_rows): string {
    $action_url = ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor', $back_url);
    $filters = ll_tools_wordset_editor_get_filters();
    $data = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, $filters);
    $rows = (array) ($data['rows'] ?? []);
    $summary = (array) ($data['summary'] ?? []);
    $per_page = 75;
    $paged = max(1, (int) ($filters['paged'] ?? 1));
    $total_filtered = count($rows);
    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    if ($paged > $total_pages) {
        $paged = $total_pages;
    }
    $page_rows = array_slice($rows, ($paged - 1) * $per_page, $per_page);
    $available_category_ids = ll_tools_wordset_editor_get_available_category_ids($category_rows);
    $recent_actions = ll_tools_wordset_editor_get_recent_actions($wordset_id, 8);
    $reset_url = $action_url;

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-editor" data-ll-wordset-editor data-ll-wordset-editor-selected-singular="<?php echo esc_attr__('1 selected', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-selected-plural="<?php echo esc_attr__('%d selected', 'll-tools-text-domain'); ?>">
        <div class="ll-wordset-editor-stats" aria-label="<?php echo esc_attr__('Wordset editor summary', 'll-tools-text-domain'); ?>">
            <div class="ll-wordset-editor-stat">
                <?php echo ll_tools_wordset_editor_icon('table'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['total'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Words', 'll-tools-text-domain'); ?></span>
            </div>
            <div class="ll-wordset-editor-stat">
                <?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['missing_audio'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Missing audio', 'll-tools-text-domain'); ?></span>
            </div>
            <div class="ll-wordset-editor-stat">
                <?php echo ll_tools_wordset_editor_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['missing_image'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Missing images', 'll-tools-text-domain'); ?></span>
            </div>
            <div class="ll-wordset-editor-stat">
                <?php echo ll_tools_wordset_editor_icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) count($recent_actions)); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Recent actions', 'll-tools-text-domain'); ?></span>
            </div>
        </div>

        <form class="ll-wordset-settings-card ll-wordset-editor-filters" method="get" action="<?php echo esc_url(ll_tools_get_wordset_page_view_url($wordset_term, 'settings')); ?>">
            <input type="hidden" name="ll_wordset_tool" value="editor" />
            <?php if ($back_url !== '') : ?>
                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
            <?php endif; ?>
            <div class="ll-wordset-editor-filters__grid">
                <label class="ll-wordset-editor-field ll-wordset-editor-field--search">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('Word or translation', 'll-tools-text-domain'); ?></span>
                    <input type="search" name="ll_editor_q" value="<?php echo esc_attr((string) ($filters['q'] ?? '')); ?>" />
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('category'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                    <select name="ll_editor_category">
                        <option value="0"><?php echo esc_html__('All', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($category_rows as $category_row) : ?>
                            <?php $category_id = (int) ($category_row['id'] ?? 0); ?>
                            <?php if ($category_id <= 0) { continue; } ?>
                            <option value="<?php echo esc_attr((string) $category_id); ?>" <?php selected((int) ($filters['category'] ?? 0), $category_id); ?>>
                                <?php echo esc_html(ll_tools_wordset_editor_category_row_label($category_row)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('draft'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('Status', 'll-tools-text-domain'); ?></span>
                    <select name="ll_editor_status">
                        <option value=""><?php echo esc_html__('All', 'll-tools-text-domain'); ?></option>
                        <option value="publish" <?php selected((string) ($filters['status'] ?? ''), 'publish'); ?>><?php echo esc_html__('Published', 'll-tools-text-domain'); ?></option>
                        <option value="draft" <?php selected((string) ($filters['status'] ?? ''), 'draft'); ?>><?php echo esc_html__('Draft', 'll-tools-text-domain'); ?></option>
                        <option value="pending" <?php selected((string) ($filters['status'] ?? ''), 'pending'); ?>><?php echo esc_html__('Pending', 'll-tools-text-domain'); ?></option>
                        <option value="private" <?php selected((string) ($filters['status'] ?? ''), 'private'); ?>><?php echo esc_html__('Private', 'll-tools-text-domain'); ?></option>
                    </select>
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('Image', 'll-tools-text-domain'); ?></span>
                    <select name="ll_editor_image">
                        <option value=""><?php echo esc_html__('All', 'll-tools-text-domain'); ?></option>
                        <option value="has" <?php selected((string) ($filters['image'] ?? ''), 'has'); ?>><?php echo esc_html__('Has image', 'll-tools-text-domain'); ?></option>
                        <option value="missing" <?php selected((string) ($filters['image'] ?? ''), 'missing'); ?>><?php echo esc_html__('Missing image', 'll-tools-text-domain'); ?></option>
                    </select>
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('Recording', 'll-tools-text-domain'); ?></span>
                    <select name="ll_editor_recording">
                        <option value=""><?php echo esc_html__('All', 'll-tools-text-domain'); ?></option>
                        <option value="has" <?php selected((string) ($filters['recording'] ?? ''), 'has'); ?>><?php echo esc_html__('Has published audio', 'll-tools-text-domain'); ?></option>
                        <option value="missing" <?php selected((string) ($filters['recording'] ?? ''), 'missing'); ?>><?php echo esc_html__('Missing required audio', 'll-tools-text-domain'); ?></option>
                        <option value="none" <?php selected((string) ($filters['recording'] ?? ''), 'none'); ?>><?php echo esc_html__('No published audio', 'll-tools-text-domain'); ?></option>
                    </select>
                </label>
            </div>
            <div class="ll-wordset-editor-filters__actions">
                <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--primary">
                    <?php echo ll_tools_wordset_editor_icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span><?php echo esc_html__('Filter', 'll-tools-text-domain'); ?></span>
                </button>
                <a class="ll-wordset-settings-action ll-wordset-settings-action--secondary" href="<?php echo esc_url($reset_url); ?>">
                    <span><?php echo esc_html__('Reset', 'll-tools-text-domain'); ?></span>
                </a>
            </div>
        </form>

        <form class="ll-wordset-settings-card ll-wordset-editor-bulk" method="post" action="<?php echo esc_url($action_url); ?>" data-ll-wordset-editor-bulk-form data-ll-wordset-editor-empty-selection="<?php echo esc_attr__('Select at least one visible word first.', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-category-required="<?php echo esc_attr__('Choose a category target for this action.', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-trash-confirm="<?php echo esc_attr__('Move the selected words to Trash?', 'll-tools-text-domain'); ?>">
            <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
            <input type="hidden" name="ll_wordset_tool" value="editor" />
            <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
            <?php wp_nonce_field('ll_wordset_manager_editor_' . $wordset_id, 'll_wordset_manager_editor_nonce'); ?>
            <div class="ll-wordset-editor-bulk__bar">
                <span class="ll-wordset-editor-selected-count" data-ll-wordset-editor-selected-count>
                    <?php echo esc_html__('0 selected', 'll-tools-text-domain'); ?>
                </span>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo esc_html__('Action', 'll-tools-text-domain'); ?></span>
                    <select name="ll_wordset_manager_editor_action">
                        <option value="draft"><?php echo esc_html__('Move to draft', 'll-tools-text-domain'); ?></option>
                        <option value="publish"><?php echo esc_html__('Publish', 'll-tools-text-domain'); ?></option>
                        <option value="add_category"><?php echo esc_html__('Add category', 'll-tools-text-domain'); ?></option>
                        <option value="remove_category"><?php echo esc_html__('Remove category', 'll-tools-text-domain'); ?></option>
                        <option value="move_category"><?php echo esc_html__('Move to category', 'll-tools-text-domain'); ?></option>
                        <option value="missing_audio_review"><?php echo esc_html__('Missing-audio review', 'll-tools-text-domain'); ?></option>
                        <option value="trash"><?php echo esc_html__('Move to Trash', 'll-tools-text-domain'); ?></option>
                    </select>
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo esc_html__('Category target', 'll-tools-text-domain'); ?></span>
                    <select name="ll_wordset_editor_target_category">
                        <option value="0"><?php echo esc_html__('Choose category', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($category_rows as $category_row) : ?>
                            <?php $category_id = (int) ($category_row['id'] ?? 0); ?>
                            <?php if ($category_id <= 0 || !in_array($category_id, $available_category_ids, true)) { continue; } ?>
                            <option value="<?php echo esc_attr((string) $category_id); ?>"><?php echo esc_html(ll_tools_wordset_editor_category_row_label($category_row)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--primary" data-ll-wordset-editor-apply>
                    <?php echo ll_tools_wordset_editor_icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span><?php echo esc_html__('Apply', 'll-tools-text-domain'); ?></span>
                </button>
            </div>

            <div class="ll-wordset-editor-table" role="table" aria-label="<?php echo esc_attr__('Words in this word set', 'll-tools-text-domain'); ?>">
                <div class="ll-wordset-editor-row ll-wordset-editor-row--head" role="row">
                    <span class="ll-wordset-editor-cell ll-wordset-editor-cell--check" role="columnheader">
                        <input type="checkbox" data-ll-wordset-editor-select-all aria-label="<?php echo esc_attr__('Select all visible words', 'll-tools-text-domain'); ?>" />
                    </span>
                    <span class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="columnheader"><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo esc_html__('Categories', 'll-tools-text-domain'); ?></span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo esc_html__('State', 'll-tools-text-domain'); ?></span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo esc_html__('Media', 'll-tools-text-domain'); ?></span>
                </div>
                <?php if (empty($page_rows)) : ?>
                    <div class="ll-wordset-settings-empty"><?php echo esc_html__('No words match these filters.', 'll-tools-text-domain'); ?></div>
                <?php else : ?>
                    <?php foreach ($page_rows as $row) : ?>
                        <?php
                        $word_id = (int) ($row['id'] ?? 0);
                        $status = (string) ($row['status'] ?? '');
                        $category_labels = (array) ($row['selected_category_labels'] ?? []);
                        ?>
                        <div class="ll-wordset-editor-row" role="row">
                            <label class="ll-wordset-editor-cell ll-wordset-editor-cell--check" role="cell">
                                <input type="checkbox" name="ll_wordset_editor_word_ids[]" value="<?php echo esc_attr((string) $word_id); ?>" data-ll-wordset-editor-word />
                                <span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'll-tools-text-domain'), (string) ($row['title'] ?? ''))); ?></span>
                            </label>
                            <div class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="cell">
                                <strong class="ll-wordset-editor-word-title"><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong>
                                <?php if ((string) ($row['translation'] ?? '') !== '') : ?>
                                    <span class="ll-wordset-editor-word-translation"><?php echo esc_html((string) ($row['translation'] ?? '')); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ll-wordset-editor-cell" role="cell">
                                <div class="ll-wordset-editor-pill-list">
                                    <?php if (empty($category_labels)) : ?>
                                        <span class="ll-wordset-editor-pill ll-wordset-editor-pill--muted"><?php echo esc_html__('No category', 'll-tools-text-domain'); ?></span>
                                    <?php else : ?>
                                        <?php foreach ($category_labels as $category_label) : ?>
                                            <span class="ll-wordset-editor-pill"><?php echo esc_html((string) $category_label); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ll-wordset-editor-cell" role="cell">
                                <span class="ll-wordset-editor-state ll-wordset-editor-state--<?php echo esc_attr(sanitize_html_class($status)); ?>">
                                    <?php echo esc_html(ll_tools_wordset_editor_status_label($status)); ?>
                                </span>
                            </div>
                            <div class="ll-wordset-editor-cell" role="cell">
                                <div class="ll-wordset-editor-media">
                                    <span class="ll-wordset-editor-media__item <?php echo !empty($row['has_image']) ? 'is-ready' : 'is-missing'; ?>" title="<?php echo esc_attr(!empty($row['has_image']) ? __('Has image', 'll-tools-text-domain') : __('Missing image', 'll-tools-text-domain')); ?>">
                                        <?php echo ll_tools_wordset_editor_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="ll-wordset-editor-media__item <?php echo ((int) ($row['published_audio_count'] ?? 0) > 0) ? 'is-ready' : (!empty($row['missing_audio']) ? 'is-missing' : 'is-muted'); ?>" title="<?php echo esc_attr(!empty($row['missing_audio']) ? __('Missing required audio', 'll-tools-text-domain') : sprintf(_n('%d published recording', '%d published recordings', (int) ($row['published_audio_count'] ?? 0), 'll-tools-text-domain'), (int) ($row['published_audio_count'] ?? 0))); ?>">
                                        <?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html((string) ((int) ($row['published_audio_count'] ?? 0))); ?></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1) : ?>
                <nav class="ll-wordset-editor-pagination" aria-label="<?php echo esc_attr__('Word editor pages', 'll-tools-text-domain'); ?>">
                    <?php
                    $base_query = $_GET;
                    $base_query['ll_wordset_tool'] = 'editor';
                    for ($page = 1; $page <= $total_pages; $page++) :
                        $base_query['ll_editor_page'] = $page;
                        $page_url = add_query_arg($base_query, ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
                        ?>
                        <a class="ll-wordset-editor-pagination__item <?php echo $page === $paged ? 'is-current' : ''; ?>" href="<?php echo esc_url($page_url); ?>" <?php echo $page === $paged ? 'aria-current="page"' : ''; ?>>
                            <?php echo esc_html((string) $page); ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        </form>

        <section class="ll-wordset-settings-card ll-wordset-editor-history" aria-label="<?php echo esc_attr__('Recent editor actions', 'll-tools-text-domain'); ?>">
            <div class="ll-wordset-editor-history__head">
                <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Recent actions', 'll-tools-text-domain'); ?></h2>
                <span class="ll-wordset-editor-history__hint"><?php echo esc_html__('Undo is available for recent Trash, recording move, status, category, and review actions.', 'll-tools-text-domain'); ?></span>
            </div>
            <?php if (empty($recent_actions)) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('No recent editor actions yet.', 'll-tools-text-domain'); ?></p>
            <?php else : ?>
                <div class="ll-wordset-editor-history__list">
                    <?php foreach ($recent_actions as $action_row) : ?>
                        <?php
                        $created_at = (string) ($action_row['created_at'] ?? '');
                        $created_label = $created_at !== '' ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $created_at) : '';
                        $is_undoable = !empty($action_row['undoable']) && empty($action_row['undone']);
                        ?>
                        <div class="ll-wordset-editor-history__row">
                            <div class="ll-wordset-editor-history__main">
                                <span class="ll-wordset-editor-history__summary"><?php echo esc_html((string) ($action_row['summary'] ?? '')); ?></span>
                                <?php if ($created_label !== '') : ?>
                                    <span class="ll-wordset-editor-history__time"><?php echo esc_html($created_label); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($action_row['undone'])) : ?>
                                    <span class="ll-wordset-editor-pill ll-wordset-editor-pill--muted"><?php echo esc_html__('Undone', 'll-tools-text-domain'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_undoable) : ?>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-history__undo">
                                    <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                    <input type="hidden" name="ll_wordset_manager_editor_action" value="undo" />
                                    <input type="hidden" name="ll_wordset_editor_action_id" value="<?php echo esc_attr((string) ($action_row['id'] ?? '')); ?>" />
                                    <input type="hidden" name="ll_wordset_tool" value="editor" />
                                    <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                    <?php wp_nonce_field('ll_wordset_manager_editor_' . $wordset_id, 'll_wordset_manager_editor_nonce'); ?>
                                    <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--secondary">
                                        <?php echo ll_tools_wordset_editor_icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html__('Undo', 'll-tools-text-domain'); ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
    <?php

    return (string) ob_get_clean();
}
