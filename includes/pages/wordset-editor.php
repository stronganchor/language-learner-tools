<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION')) {
    define('LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION', 'll_tools_wordset_editor_action_history');
}

if (!defined('LL_TOOLS_WORDSET_EDITOR_SAVED_FILTERS_META')) {
    define('LL_TOOLS_WORDSET_EDITOR_SAVED_FILTERS_META', 'll_tools_wordset_editor_saved_filters');
}

function ll_tools_wordset_editor_history_limit(): int {
    return 300;
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

function ll_tools_wordset_editor_history_type_options(): array {
    return [
        'quick_update'              => __('Quick edits', 'll-tools-text-domain'),
        'word_trash'                => __('Word Trash', 'll-tools-text-domain'),
        'bulk_trash'                => __('Bulk Trash', 'll-tools-text-domain'),
        'recording_trash'           => __('Recording Trash', 'll-tools-text-domain'),
        'recording_move'            => __('Recording moves', 'll-tools-text-domain'),
        'bulk_status'               => __('Status changes', 'll-tools-text-domain'),
        'bulk_categories'           => __('Category changes', 'll-tools-text-domain'),
        'bulk_missing_audio_review' => __('Audio review', 'll-tools-text-domain'),
        'bulk_missing_image_review' => __('Image review', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_editor_history_type_label(string $type): string {
    $type = sanitize_key($type);
    $options = ll_tools_wordset_editor_history_type_options();
    return isset($options[$type]) ? $options[$type] : ucwords(str_replace('_', ' ', $type));
}

function ll_tools_wordset_editor_get_history_filters(): array {
    $type = isset($_GET['ll_editor_history_type'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_editor_history_type']))
        : '';
    if ($type !== '' && !array_key_exists($type, ll_tools_wordset_editor_history_type_options())) {
        $type = '';
    }

    return [
        'type'  => $type,
        'paged' => isset($_GET['ll_editor_history_page'])
            ? max(1, absint(wp_unslash((string) $_GET['ll_editor_history_page'])))
            : 1,
    ];
}

function ll_tools_wordset_editor_get_filtered_history(int $wordset_id, array $filters): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $type_filter = sanitize_key((string) ($filters['type'] ?? ''));
    $rows = [];
    foreach (ll_tools_wordset_editor_get_history() as $entry) {
        if ((int) ($entry['wordset_id'] ?? 0) !== $wordset_id) {
            continue;
        }
        if ($type_filter !== '' && sanitize_key((string) ($entry['type'] ?? '')) !== $type_filter) {
            continue;
        }
        $rows[] = $entry;
    }

    return $rows;
}

function ll_tools_wordset_editor_history_user_label(array $entry): string {
    $user_id = (int) ($entry['user_id'] ?? 0);
    if ($user_id <= 0) {
        return __('Unknown user', 'll-tools-text-domain');
    }

    $user = get_userdata($user_id);
    if (!($user instanceof WP_User)) {
        return __('Unknown user', 'll-tools-text-domain');
    }

    $label = trim((string) $user->display_name);
    return $label !== '' ? $label : (string) $user->user_login;
}

function ll_tools_wordset_editor_history_detail_lines(array $entry): array {
    $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
    $lines = [];

    if (isset($payload['words']) && is_array($payload['words'])) {
        $lines[] = sprintf(_n('%d word affected.', '%d words affected.', count($payload['words']), 'll-tools-text-domain'), count($payload['words']));
    } elseif (isset($payload['word_id'])) {
        $word_id = (int) $payload['word_id'];
        if ($word_id > 0) {
            $lines[] = sprintf(__('Word: %s', 'll-tools-text-domain'), get_the_title($word_id));
        }
    }

    if (isset($payload['source_word_id']) || isset($payload['target_word_id'])) {
        $source_id = (int) ($payload['source_word_id'] ?? 0);
        $target_id = (int) ($payload['target_word_id'] ?? 0);
        if ($source_id > 0) {
            $lines[] = sprintf(__('From: %s', 'll-tools-text-domain'), get_the_title($source_id));
        }
        if ($target_id > 0) {
            $lines[] = sprintf(__('To: %s', 'll-tools-text-domain'), get_the_title($target_id));
        }
    }

    if (isset($payload['recording_id'])) {
        $lines[] = sprintf(__('Recording ID: %d', 'll-tools-text-domain'), (int) $payload['recording_id']);
    }
    if (isset($payload['new_status'])) {
        $lines[] = sprintf(__('New status: %s', 'll-tools-text-domain'), ll_tools_wordset_editor_status_label((string) $payload['new_status']));
    }
    if (isset($payload['category_action'])) {
        $lines[] = sprintf(__('Category action: %s', 'll-tools-text-domain'), ucwords(str_replace('_', ' ', sanitize_key((string) $payload['category_action']))));
    }
    if (isset($payload['previous_title']) && isset($payload['new_title'])) {
        $lines[] = sprintf(__('Title: %1$s -> %2$s', 'll-tools-text-domain'), (string) $payload['previous_title'], (string) $payload['new_title']);
    }

    return $lines;
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

function ll_tools_wordset_editor_get_selected_word_ids_from_post(int $wordset_id, array $category_rows = []): array {
    if (!empty($_POST['ll_wordset_editor_all_filtered'])) {
        $filters = ll_tools_wordset_editor_get_filters_from_source($_POST);
        $filters['paged'] = 1;
        $data = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, $filters);
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        return ll_tools_wordset_editor_normalize_word_ids(wp_list_pluck($rows, 'id'));
    }

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

function ll_tools_wordset_editor_get_recordings_for_word_ids(array $word_ids): array {
    $word_ids = ll_tools_wordset_editor_normalize_word_ids($word_ids);
    if (empty($word_ids)) {
        return [];
    }

    $recordings = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'post_parent__in' => $word_ids,
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    $by_word = [];
    foreach ($word_ids as $word_id) {
        $by_word[$word_id] = [];
    }

    foreach ($recordings as $recording) {
        if (!($recording instanceof WP_Post)) {
            continue;
        }
        $word_id = (int) $recording->post_parent;
        if ($word_id <= 0 || !isset($by_word[$word_id])) {
            continue;
        }

        $type_terms = wp_get_post_terms((int) $recording->ID, 'recording_type');
        $type_labels = [];
        if (!is_wp_error($type_terms)) {
            foreach ((array) $type_terms as $type_term) {
                if ($type_term instanceof WP_Term) {
                    $type_labels[] = (string) $type_term->name;
                }
            }
        }

        $by_word[$word_id][] = [
            'id'          => (int) $recording->ID,
            'title'       => (string) $recording->post_title,
            'status'      => (string) $recording->post_status,
            'word_id'     => $word_id,
            'type_labels' => $type_labels,
            'file_path'   => (string) get_post_meta((int) $recording->ID, 'audio_file_path', true),
        ];
    }

    return $by_word;
}

function ll_tools_wordset_editor_get_move_choices(int $wordset_id): array {
    $choices = [];
    foreach (ll_tools_wordset_editor_get_all_word_ids($wordset_id) as $word_id) {
        $title = trim((string) get_the_title($word_id));
        if ($title === '') {
            continue;
        }
        $translation = ll_tools_wordset_editor_get_translation($word_id);
        $choices[] = [
            'id'    => $word_id,
            'label' => $translation !== '' ? $title . ' - ' . $translation : $title,
        ];
    }

    usort($choices, static function (array $left, array $right): int {
        return ll_tools_wordset_editor_compare_values((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    return $choices;
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

function ll_tools_wordset_editor_get_filters_from_source(array $source): array {
    $filters = [
        'q'         => '',
        'category'  => 0,
        'status'    => '',
        'image'     => '',
        'recording' => '',
        'sort'      => 'word',
        'dir'       => 'asc',
        'paged'     => 1,
    ];

    if (isset($source['ll_editor_q'])) {
        $filters['q'] = sanitize_text_field(wp_unslash((string) $source['ll_editor_q']));
    }
    if (isset($source['ll_editor_category'])) {
        $filters['category'] = absint(wp_unslash((string) $source['ll_editor_category']));
    }
    if (isset($source['ll_editor_status'])) {
        $status = sanitize_key(wp_unslash((string) $source['ll_editor_status']));
        $filters['status'] = in_array($status, ['publish', 'draft', 'pending', 'private'], true) ? $status : '';
    }
    if (isset($source['ll_editor_image'])) {
        $image = sanitize_key(wp_unslash((string) $source['ll_editor_image']));
        $filters['image'] = in_array($image, ['has', 'missing'], true) ? $image : '';
    }
    if (isset($source['ll_editor_recording'])) {
        $recording = sanitize_key(wp_unslash((string) $source['ll_editor_recording']));
        if ($recording === 'none') {
            $recording = 'missing';
        }
        $filters['recording'] = in_array($recording, ['has', 'missing'], true) ? $recording : '';
    }
    if (isset($source['ll_editor_sort'])) {
        $sort = sanitize_key(wp_unslash((string) $source['ll_editor_sort']));
        $filters['sort'] = in_array($sort, ['word', 'translation', 'category', 'status', 'image', 'recording'], true) ? $sort : 'word';
    }
    if (isset($source['ll_editor_dir'])) {
        $dir = strtolower(sanitize_key(wp_unslash((string) $source['ll_editor_dir'])));
        $filters['dir'] = $dir === 'desc' ? 'desc' : 'asc';
    }
    if (isset($source['ll_editor_page'])) {
        $filters['paged'] = max(1, absint(wp_unslash((string) $source['ll_editor_page'])));
    }

    return $filters;
}

function ll_tools_wordset_editor_get_filters(): array {
    return ll_tools_wordset_editor_get_filters_from_source($_GET);
}

function ll_tools_wordset_editor_filter_query_args_from_filters(array $filters): array {
    $args = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $args['ll_editor_q'] = $q;
    }

    $category_id = (int) ($filters['category'] ?? 0);
    if ($category_id > 0) {
        $args['ll_editor_category'] = $category_id;
    }

    foreach ([
        'status' => 'll_editor_status',
        'image' => 'll_editor_image',
        'recording' => 'll_editor_recording',
    ] as $filter_key => $query_key) {
        $value = sanitize_key((string) ($filters[$filter_key] ?? ''));
        if ($filter_key === 'recording' && $value === 'none') {
            $value = 'missing';
        }
        if ($value !== '') {
            $args[$query_key] = $value;
        }
    }

    $sort = sanitize_key((string) ($filters['sort'] ?? 'word'));
    if ($sort !== '' && $sort !== 'word') {
        $args['ll_editor_sort'] = $sort;
    }

    $dir = ((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    if ($dir === 'desc') {
        $args['ll_editor_dir'] = $dir;
    }

    return $args;
}

function ll_tools_wordset_editor_filter_query_args_from_source(array $source): array {
    return ll_tools_wordset_editor_filter_query_args_from_filters(ll_tools_wordset_editor_get_filters_from_source($source));
}

function ll_tools_wordset_editor_filter_preset_from_filters(array $filters): array {
    $recording = sanitize_key((string) ($filters['recording'] ?? ''));
    if ($recording === 'none') {
        $recording = 'missing';
    }

    return [
        'q'         => (string) ($filters['q'] ?? ''),
        'category'  => (int) ($filters['category'] ?? 0),
        'status'    => sanitize_key((string) ($filters['status'] ?? '')),
        'image'     => sanitize_key((string) ($filters['image'] ?? '')),
        'recording' => $recording,
        'sort'      => sanitize_key((string) ($filters['sort'] ?? 'word')),
        'dir'       => ((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
    ];
}

function ll_tools_wordset_editor_get_saved_filter_store(int $user_id = 0): array {
    $user_id = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    $store = get_user_meta($user_id, LL_TOOLS_WORDSET_EDITOR_SAVED_FILTERS_META, true);
    return is_array($store) ? $store : [];
}

function ll_tools_wordset_editor_save_filter_store(array $store, int $user_id = 0): void {
    $user_id = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    update_user_meta($user_id, LL_TOOLS_WORDSET_EDITOR_SAVED_FILTERS_META, $store);
}

function ll_tools_wordset_editor_get_saved_filters(int $wordset_id, int $user_id = 0): array {
    $wordset_id = (int) $wordset_id;
    $store = ll_tools_wordset_editor_get_saved_filter_store($user_id);
    $rows = isset($store[$wordset_id]) && is_array($store[$wordset_id]) ? array_values($store[$wordset_id]) : [];
    usort($rows, static function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    return $rows;
}

function ll_tools_wordset_editor_save_filter_preset(int $wordset_id, string $name, array $filters, int $user_id = 0): string {
    $wordset_id = (int) $wordset_id;
    $name = trim(sanitize_text_field($name));
    if ($wordset_id <= 0 || $name === '') {
        return '';
    }

    $store = ll_tools_wordset_editor_get_saved_filter_store($user_id);
    $rows = isset($store[$wordset_id]) && is_array($store[$wordset_id]) ? array_values($store[$wordset_id]) : [];
    $rows = array_values(array_filter($rows, static function (array $row) use ($name): bool {
        return strcasecmp((string) ($row['name'] ?? ''), $name) !== 0;
    }));

    $preset_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ll-we-filter-', true);
    array_unshift($rows, [
        'id'         => $preset_id,
        'name'       => $name,
        'filters'    => ll_tools_wordset_editor_filter_preset_from_filters($filters),
        'created_at' => current_time('mysql'),
    ]);

    $store[$wordset_id] = array_slice($rows, 0, 12);
    ll_tools_wordset_editor_save_filter_store($store, $user_id);

    return $preset_id;
}

function ll_tools_wordset_editor_delete_filter_preset(int $wordset_id, string $preset_id, int $user_id = 0): bool {
    $wordset_id = (int) $wordset_id;
    $preset_id = sanitize_text_field($preset_id);
    if ($wordset_id <= 0 || $preset_id === '') {
        return false;
    }

    $store = ll_tools_wordset_editor_get_saved_filter_store($user_id);
    $rows = isset($store[$wordset_id]) && is_array($store[$wordset_id]) ? array_values($store[$wordset_id]) : [];
    $before = count($rows);
    $rows = array_values(array_filter($rows, static function (array $row) use ($preset_id): bool {
        return (string) ($row['id'] ?? '') !== $preset_id;
    }));

    $store[$wordset_id] = $rows;
    ll_tools_wordset_editor_save_filter_store($store, $user_id);

    return count($rows) !== $before;
}

function ll_tools_wordset_editor_filter_hidden_inputs(array $filters): string {
    $fields = [
        'll_editor_q'         => (string) ($filters['q'] ?? ''),
        'll_editor_category'  => (string) ((int) ($filters['category'] ?? 0)),
        'll_editor_status'    => (string) ($filters['status'] ?? ''),
        'll_editor_image'     => (string) ($filters['image'] ?? ''),
        'll_editor_recording' => (string) ($filters['recording'] ?? ''),
        'll_editor_sort'      => (string) ($filters['sort'] ?? 'word'),
        'll_editor_dir'       => (string) ($filters['dir'] ?? 'asc'),
    ];

    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />' . "\n";
    }

    return $html;
}

function ll_tools_wordset_editor_nonce_input(int $wordset_id): string {
    return '<input type="hidden" name="ll_wordset_manager_editor_nonce" value="' . esc_attr(wp_create_nonce('ll_wordset_manager_editor_' . (int) $wordset_id)) . '" />';
}

function ll_tools_wordset_editor_compare_values($left, $right): int {
    if (is_bool($left) || is_bool($right) || is_numeric($left) || is_numeric($right)) {
        return ((int) $left) <=> ((int) $right);
    }

    $left = (string) $left;
    $right = (string) $right;
    if (function_exists('ll_tools_locale_compare_strings')) {
        return ll_tools_locale_compare_strings($left, $right);
    }

    return strnatcasecmp($left, $right);
}

function ll_tools_wordset_editor_sort_rows(array $rows, array $filters): array {
    $sort = sanitize_key((string) ($filters['sort'] ?? 'word'));
    $dir = ((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $value_for = static function (array $row) use ($sort) {
        if ($sort === 'translation') {
            return (string) ($row['translation'] ?? '');
        }
        if ($sort === 'category') {
            return implode(' ', (array) ($row['selected_category_labels'] ?? []));
        }
        if ($sort === 'status') {
            return (string) ($row['status'] ?? '');
        }
        if ($sort === 'image') {
            return !empty($row['has_image']) ? 1 : 0;
        }
        if ($sort === 'recording') {
            return (int) ($row['published_audio_count'] ?? 0);
        }

        return (string) ($row['title'] ?? '');
    };

    usort($rows, static function (array $left, array $right) use ($value_for, $dir): int {
        $comparison = ll_tools_wordset_editor_compare_values($value_for($left), $value_for($right));
        if ($comparison === 0) {
            $comparison = ll_tools_wordset_editor_compare_values((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        }
        return $dir === 'desc' ? -$comparison : $comparison;
    });

    return $rows;
}

function ll_tools_wordset_editor_build_rows(int $wordset_id, array $category_rows, array $filters = []): array {
    $filters = array_merge(ll_tools_wordset_editor_get_filters(), $filters);
    $word_ids = ll_tools_wordset_editor_get_all_word_ids($wordset_id);
    $audio_counts = ll_tools_wordset_editor_get_audio_counts($word_ids);
    $available_category_ids = ll_tools_wordset_editor_get_available_category_ids($category_rows);
    $category_labels = ll_tools_wordset_editor_get_category_labels($category_rows);
    $search = strtolower(trim((string) ($filters['q'] ?? '')));
    $recording_filter = sanitize_key((string) ($filters['recording'] ?? ''));
    if ($recording_filter === 'none') {
        $recording_filter = 'missing';
    }
    $filtered_rows = [];
    $summary = [
        'total'         => count($word_ids),
        'missing_audio' => 0,
        'missing_image' => 0,
        'no_audio'      => 0,
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
        $missing_audio = $published_audio_count <= 0;

        if ($missing_audio) {
            $summary['missing_audio']++;
        }
        if ($published_audio_count <= 0) {
            $summary['no_audio']++;
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
        if ($recording_filter === 'has' && $published_audio_count <= 0) {
            continue;
        }
        if ($recording_filter === 'missing' && !$missing_audio) {
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
        'rows'    => ll_tools_wordset_editor_sort_rows($filtered_rows, $filters),
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
            $previous_status = sanitize_key((string) ($payload['previous_status'] ?? ''));
            if ($previous_status !== '' && get_post_status($recording_id) !== $previous_status) {
                wp_update_post([
                    'ID'          => $recording_id,
                    'post_status' => $previous_status,
                ]);
            }
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
    } elseif ($type === 'quick_update') {
        $word_id = (int) ($payload['word_id'] ?? 0);
        if ($word_id > 0 && ll_tools_wordset_editor_word_belongs_to_wordset($word_id, $wordset_id)) {
            $previous_title = (string) ($payload['previous_title'] ?? '');
            $updated = wp_update_post([
                'ID'         => $word_id,
                'post_title' => $previous_title,
            ], true);
            if (!is_wp_error($updated) && (int) $updated > 0) {
                foreach (['word_translation' => 'previous_translation', 'word_english_meaning' => 'previous_english_meaning'] as $meta_key => $payload_key) {
                    $value = (string) ($payload[$payload_key] ?? '');
                    if ($value === '') {
                        delete_post_meta($word_id, $meta_key);
                    } else {
                        update_post_meta($word_id, $meta_key, $value);
                    }
                }
                $restored++;
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
    } elseif ($type === 'bulk_missing_image_review') {
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
    $filter_args = [];
    if (!empty($_POST)) {
        $filter_args = ll_tools_wordset_editor_filter_query_args_from_source($_POST);
    } elseif (!empty($_GET)) {
        $filter_args = ll_tools_wordset_editor_filter_query_args_from_source($_GET);
    }

    wp_safe_redirect(add_query_arg(array_merge($filter_args, [
        'll_wordset_manager_editor'         => $status,
        'll_wordset_manager_editor_result'  => sanitize_key($result),
        'll_wordset_manager_editor_count'   => max(0, $count),
        'll_wordset_manager_editor_blocked' => max(0, $blocked),
    ]), $url));
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
    if (!in_array($action, ['publish', 'draft', 'add_category', 'remove_category', 'move_category', 'missing_audio_review', 'missing_image_review', 'trash', 'undo', 'delete_recording', 'move_recording', 'quick_update', 'save_filter', 'delete_filter'], true)) {
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

    if ($action === 'save_filter') {
        $name = isset($_POST['ll_wordset_editor_filter_name'])
            ? sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_editor_filter_name']))
            : '';
        $saved_id = ll_tools_wordset_editor_save_filter_preset($wordset_id, $name, ll_tools_wordset_editor_get_filters_from_source($_POST));
        if ($saved_id === '') {
            $redirect_error('filter');
        }
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'save_filter', 1);
    }

    if ($action === 'delete_filter') {
        $preset_id = isset($_POST['ll_wordset_editor_filter_id'])
            ? sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_editor_filter_id']))
            : '';
        if (!ll_tools_wordset_editor_delete_filter_preset($wordset_id, $preset_id)) {
            $redirect_error('filter');
        }
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'delete_filter', 1);
    }

    $category_rows = function_exists('ll_tools_word_grid_get_category_editor_rows')
        ? ll_tools_word_grid_get_category_editor_rows($wordset_id)
        : [];
    $available_category_ids = ll_tools_wordset_editor_get_available_category_ids($category_rows);

    if ($action === 'quick_update') {
        $word_id = isset($_POST['ll_wordset_editor_word_id'])
            ? absint(wp_unslash((string) $_POST['ll_wordset_editor_word_id']))
            : 0;
        if (!ll_tools_wordset_editor_word_belongs_to_wordset($word_id, $wordset_id)) {
            $redirect_error('word');
        }

        $title = isset($_POST['ll_wordset_editor_word_title'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_editor_word_title'])))
            : '';
        if ($title === '') {
            $redirect_error('title');
        }

        $translation = isset($_POST['ll_wordset_editor_word_translation'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_wordset_editor_word_translation'])))
            : '';
        $previous_title = (string) get_post_field('post_title', $word_id);
        $previous_translation = (string) get_post_meta($word_id, 'word_translation', true);
        $previous_english_meaning = (string) get_post_meta($word_id, 'word_english_meaning', true);
        $changed = 0;

        if ($title !== $previous_title) {
            $updated = wp_update_post([
                'ID'         => $word_id,
                'post_title' => $title,
            ], true);
            if (is_wp_error($updated) || (int) $updated <= 0) {
                $redirect_error('word');
            }
            $changed = 1;
        }

        if ($translation !== $previous_translation || $translation !== $previous_english_meaning) {
            if ($translation === '') {
                delete_post_meta($word_id, 'word_translation');
                delete_post_meta($word_id, 'word_english_meaning');
            } else {
                update_post_meta($word_id, 'word_translation', $translation);
                update_post_meta($word_id, 'word_english_meaning', $translation);
            }
            $changed = 1;
        }

        if ($changed > 0) {
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'quick_update',
                sprintf(__('Quick edited "%s".', 'll-tools-text-domain'), $title),
                [
                    'word_id'                  => $word_id,
                    'previous_title'           => $previous_title,
                    'previous_translation'     => $previous_translation,
                    'previous_english_meaning' => $previous_english_meaning,
                    'new_title'                => $title,
                    'new_translation'          => $translation,
                ]
            );
            ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        }

        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'quick_update', $changed);
    }

    if (in_array($action, ['delete_recording', 'move_recording'], true)) {
        $recording_id = isset($_POST['ll_wordset_editor_recording_id'])
            ? absint(wp_unslash((string) $_POST['ll_wordset_editor_recording_id']))
            : 0;
        $recording = $recording_id > 0 ? get_post($recording_id) : null;
        if (!$recording instanceof WP_Post || $recording->post_type !== 'word_audio' || $recording->post_status === 'trash') {
            $redirect_error('recording');
        }

        $source_word_id = (int) $recording->post_parent;
        if (!ll_tools_wordset_editor_word_belongs_to_wordset($source_word_id, $wordset_id)) {
            $redirect_error('recording');
        }

        if ($action === 'delete_recording') {
            $deleted = wp_trash_post($recording_id);
            if (!$deleted) {
                $redirect_error('recording');
            }
            if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
                ll_tools_sync_parent_word_status_by_children($source_word_id);
            }
            ll_tools_wordset_editor_log_action(
                $wordset_id,
                'recording_trash',
                sprintf(__('Moved a recording from "%s" to Trash.', 'll-tools-text-domain'), get_the_title($source_word_id)),
                [
                    'recording_id'    => $recording_id,
                    'word_id'         => $source_word_id,
                    'previous_status' => (string) $recording->post_status,
                    'recording_title' => (string) $recording->post_title,
                ]
            );
            ll_tools_wordset_editor_invalidate_wordset($wordset_id);
            ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'delete_recording', 1);
        }

        $target_word_id = isset($_POST['ll_wordset_editor_target_word_id'])
            ? absint(wp_unslash((string) $_POST['ll_wordset_editor_target_word_id']))
            : 0;
        if ($target_word_id <= 0 || $target_word_id === $source_word_id || !ll_tools_wordset_editor_word_belongs_to_wordset($target_word_id, $wordset_id)) {
            $redirect_error('target');
        }

        $updated = wp_update_post([
            'ID'          => $recording_id,
            'post_parent' => $target_word_id,
        ], true);
        if (is_wp_error($updated) || (int) $updated <= 0) {
            $redirect_error('recording');
        }
        if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
            ll_tools_sync_parent_word_status_by_children($source_word_id);
            ll_tools_sync_parent_word_status_by_children($target_word_id);
        }
        ll_tools_wordset_editor_log_action(
            $wordset_id,
            'recording_move',
            sprintf(__('Moved a recording from "%1$s" to "%2$s".', 'll-tools-text-domain'), get_the_title($source_word_id), get_the_title($target_word_id)),
            [
                'recording_id'   => $recording_id,
                'source_word_id' => $source_word_id,
                'target_word_id' => $target_word_id,
            ]
        );
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'move_recording', 1);
    }

    $selected_word_ids = ll_tools_wordset_editor_get_selected_word_ids_from_post($wordset_id, $category_rows);
    if (empty($selected_word_ids)) {
        $redirect_error('selection');
    }

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

    if ($action === 'missing_image_review') {
        $review_note = __('Missing image review: this word needs an image before it is learner-ready.', 'll-tools-text-domain');
        foreach ($selected_word_ids as $word_id) {
            $has_image = function_exists('ll_tools_word_has_effective_image')
                ? ll_tools_word_has_effective_image($word_id, true)
                : has_post_thumbnail($word_id);
            if ($has_image) {
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
                'bulk_missing_image_review',
                sprintf(_n('Flagged %d word for missing images.', 'Flagged %d words for missing images.', $changed, 'll-tools-text-domain'), $changed),
                ['words' => $history_words]
            );
        }
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
        ll_tools_wordset_editor_redirect_with_notice($wordset_term, $back_url, 'ok', 'missing_image_review', $changed, $blocked);
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
    } elseif ($result === 'missing_image_review') {
        $message = sprintf(_n('Flagged %d word for missing image review.', 'Flagged %d words for missing image review.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'trash') {
        $message = sprintf(_n('Moved %d word to Trash.', 'Moved %d words to Trash.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'undo') {
        $message = sprintf(_n('Undid %d item.', 'Undid %d items.', $count, 'll-tools-text-domain'), $count);
    } elseif ($result === 'delete_recording') {
        $message = __('Recording moved to Trash.', 'll-tools-text-domain');
    } elseif ($result === 'move_recording') {
        $message = __('Recording moved.', 'll-tools-text-domain');
    } elseif ($result === 'quick_update') {
        $message = $count > 0 ? __('Word updated.', 'll-tools-text-domain') : __('No word changes were needed.', 'll-tools-text-domain');
    } elseif ($result === 'save_filter') {
        $message = __('Saved editor view.', 'll-tools-text-domain');
    } elseif ($result === 'delete_filter') {
        $message = __('Deleted editor view.', 'll-tools-text-domain');
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
    if ($icon === 'edit') {
        return $base . '<path d="m5 16.8-.6 2.8 2.8-.6L17.5 8.7 15.3 6.5 5 16.8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m14 7.8 2.2 2.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    if ($icon === 'bookmark') {
        return $base . '<path d="M7 5.5h10v14l-5-3.2-5 3.2v-14Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    }
    if ($icon === 'filter') {
        return $base . '<path d="M5 6h14l-5.5 6.2v4.3l-3 1.5v-5.8L5 6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
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

function ll_tools_wordset_editor_sort_link(WP_Term $wordset_term, string $key, string $label, array $filters, string $back_url = ''): string {
    $key = sanitize_key($key);
    $current_sort = sanitize_key((string) ($filters['sort'] ?? 'word'));
    $current_dir = ((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $next_dir = ($current_sort === $key && $current_dir === 'asc') ? 'desc' : 'asc';
    $query = [
        'll_wordset_tool' => 'editor',
        'll_editor_sort' => $key,
        'll_editor_dir' => $next_dir,
    ];
    foreach ([
        'q' => 'll_editor_q',
        'category' => 'll_editor_category',
        'status' => 'll_editor_status',
        'image' => 'll_editor_image',
        'recording' => 'll_editor_recording',
    ] as $filter_key => $query_key) {
        $value = $filters[$filter_key] ?? '';
        if ($value !== '' && $value !== 0 && $value !== '0') {
            $query[$query_key] = $value;
        }
    }
    if ($back_url !== '') {
        $query['ll_wordset_back'] = $back_url;
    }

    $url = add_query_arg($query, ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
    $classes = 'll-wordset-editor-sort-link';
    if ($current_sort === $key) {
        $classes .= ' is-active is-' . $current_dir;
    }
    $indicator = $current_sort === $key
        ? ($current_dir === 'asc' ? '^' : 'v')
        : '<>';

    return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span><span class="ll-wordset-editor-sort-link__icon" aria-hidden="true">' . esc_html($indicator) . '</span></a>';
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
    $bulk_form_id = 'll-wordset-editor-bulk-' . (int) $wordset_id;
    $page_word_ids = ll_tools_wordset_editor_normalize_word_ids(wp_list_pluck($page_rows, 'id'));
    $recordings_by_word_id = ll_tools_wordset_editor_get_recordings_for_word_ids($page_word_ids);
    $move_choices = ll_tools_wordset_editor_get_move_choices($wordset_id);
    $move_target_template_id = 'll-wordset-editor-move-options-' . (int) $wordset_id;
    $saved_filters = ll_tools_wordset_editor_get_saved_filters($wordset_id);
    $history_filters = ll_tools_wordset_editor_get_history_filters();
    $history_rows = ll_tools_wordset_editor_get_filtered_history($wordset_id, $history_filters);
    $history_per_page = 20;
    $history_paged = max(1, (int) ($history_filters['paged'] ?? 1));
    $history_total_pages = max(1, (int) ceil(count($history_rows) / $history_per_page));
    if ($history_paged > $history_total_pages) {
        $history_paged = $history_total_pages;
    }
    $history_page_rows = array_slice($history_rows, ($history_paged - 1) * $history_per_page, $history_per_page);
    $editor_base_url = add_query_arg([
        'll_wordset_tool' => 'editor',
    ], ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
    if ($back_url !== '') {
        $editor_base_url = add_query_arg('ll_wordset_back', $back_url, $editor_base_url);
    }
    $all_words_url = $editor_base_url;
    $missing_audio_url = add_query_arg([
        'll_wordset_tool' => 'editor',
        'll_editor_recording' => 'missing',
    ], $editor_base_url);
    $missing_image_url = add_query_arg([
        'll_wordset_tool' => 'editor',
        'll_editor_image' => 'missing',
    ], $editor_base_url);
    $recent_actions_url = $editor_base_url . '#ll-wordset-editor-history';

    ob_start();
    ?>
    <section class="ll-wordset-settings-page ll-wordset-editor" data-ll-wordset-editor data-ll-wordset-editor-selected-singular="<?php echo esc_attr__('1 selected', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-selected-plural="<?php echo esc_attr__('%d selected', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-all-filtered="<?php echo esc_attr(sprintf(_n('All %d filtered word selected', 'All %d filtered words selected', $total_filtered, 'll-tools-text-domain'), $total_filtered)); ?>">
        <div class="ll-wordset-editor-stats" aria-label="<?php echo esc_attr__('Wordset editor summary', 'll-tools-text-domain'); ?>">
            <a class="ll-wordset-editor-stat" href="<?php echo esc_url($all_words_url); ?>" aria-label="<?php echo esc_attr__('Show all words', 'll-tools-text-domain'); ?>">
                <?php echo ll_tools_wordset_editor_icon('table'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['total'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Words', 'll-tools-text-domain'); ?></span>
            </a>
            <a class="ll-wordset-editor-stat" href="<?php echo esc_url($missing_audio_url); ?>" aria-label="<?php echo esc_attr__('Show words missing published audio', 'll-tools-text-domain'); ?>">
                <?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['missing_audio'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Missing audio', 'll-tools-text-domain'); ?></span>
            </a>
            <a class="ll-wordset-editor-stat" href="<?php echo esc_url($missing_image_url); ?>" aria-label="<?php echo esc_attr__('Show words missing images', 'll-tools-text-domain'); ?>">
                <?php echo ll_tools_wordset_editor_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) ((int) ($summary['missing_image'] ?? 0))); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Missing images', 'll-tools-text-domain'); ?></span>
            </a>
            <a class="ll-wordset-editor-stat" href="<?php echo esc_url($recent_actions_url); ?>" aria-label="<?php echo esc_attr__('Jump to recent actions', 'll-tools-text-domain'); ?>">
                <?php echo ll_tools_wordset_editor_icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="ll-wordset-editor-stat__value"><?php echo esc_html((string) count($recent_actions)); ?></span>
                <span class="ll-wordset-editor-stat__label"><?php echo esc_html__('Recent actions', 'll-tools-text-domain'); ?></span>
            </a>
        </div>

        <form class="ll-wordset-settings-card ll-wordset-editor-filters" method="get" action="<?php echo esc_url(ll_tools_get_wordset_page_view_url($wordset_term, 'settings')); ?>">
            <input type="hidden" name="ll_wordset_tool" value="editor" />
            <input type="hidden" name="ll_editor_sort" value="<?php echo esc_attr((string) ($filters['sort'] ?? 'word')); ?>" />
            <input type="hidden" name="ll_editor_dir" value="<?php echo esc_attr((string) ($filters['dir'] ?? 'asc')); ?>" />
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
                        <option value="missing" <?php selected((string) ($filters['recording'] ?? ''), 'missing'); ?>><?php echo esc_html__('Missing audio', 'll-tools-text-domain'); ?></option>
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

        <section class="ll-wordset-settings-card ll-wordset-editor-saved-views" aria-label="<?php echo esc_attr__('Saved editor views', 'll-tools-text-domain'); ?>">
            <div class="ll-wordset-editor-panel-head">
                <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Saved views', 'll-tools-text-domain'); ?></h2>
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-saved-view-form">
                    <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                    <input type="hidden" name="ll_wordset_manager_editor_action" value="save_filter" />
                    <input type="hidden" name="ll_wordset_tool" value="editor" />
                    <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                    <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <label class="screen-reader-text" for="ll-wordset-editor-filter-name"><?php echo esc_html__('Saved view name', 'll-tools-text-domain'); ?></label>
                    <input id="ll-wordset-editor-filter-name" type="text" name="ll_wordset_editor_filter_name" placeholder="<?php echo esc_attr__('View name', 'll-tools-text-domain'); ?>" required />
                    <button type="submit" class="ll-wordset-editor-icon-button" aria-label="<?php echo esc_attr__('Save current view', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Save current view', 'll-tools-text-domain'); ?>">
                        <?php echo ll_tools_wordset_editor_icon('bookmark'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                </form>
            </div>
            <?php if (empty($saved_filters)) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('No saved views yet.', 'll-tools-text-domain'); ?></p>
            <?php else : ?>
                <div class="ll-wordset-editor-saved-view-list">
                    <?php foreach ($saved_filters as $saved_filter) : ?>
                        <?php
                        $saved_filter_args = ll_tools_wordset_editor_filter_query_args_from_filters((array) ($saved_filter['filters'] ?? []));
                        $saved_filter_args['ll_wordset_tool'] = 'editor';
                        if ($back_url !== '') {
                            $saved_filter_args['ll_wordset_back'] = $back_url;
                        }
                        $saved_filter_url = add_query_arg($saved_filter_args, ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
                        ?>
                        <div class="ll-wordset-editor-saved-view">
                            <a href="<?php echo esc_url($saved_filter_url); ?>">
                                <?php echo ll_tools_wordset_editor_icon('filter'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span><?php echo esc_html((string) ($saved_filter['name'] ?? '')); ?></span>
                            </a>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" data-ll-wordset-editor-confirm="<?php echo esc_attr__('Delete this saved view?', 'll-tools-text-domain'); ?>">
                                <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                <input type="hidden" name="ll_wordset_manager_editor_action" value="delete_filter" />
                                <input type="hidden" name="ll_wordset_editor_filter_id" value="<?php echo esc_attr((string) ($saved_filter['id'] ?? '')); ?>" />
                                <input type="hidden" name="ll_wordset_tool" value="editor" />
                                <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <button type="submit" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="<?php echo esc_attr__('Delete saved view', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Delete saved view', 'll-tools-text-domain'); ?>">
                                    <?php echo ll_tools_wordset_editor_icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <form id="<?php echo esc_attr($bulk_form_id); ?>" class="ll-wordset-settings-card ll-wordset-editor-bulk" method="post" action="<?php echo esc_url($action_url); ?>" data-ll-wordset-editor-bulk-form data-ll-wordset-editor-empty-selection="<?php echo esc_attr__('Select at least one visible word first.', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-category-required="<?php echo esc_attr__('Choose a category target for this action.', 'll-tools-text-domain'); ?>" data-ll-wordset-editor-trash-confirm="<?php echo esc_attr__('Move the selected words to Trash?', 'll-tools-text-domain'); ?>">
            <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
            <input type="hidden" name="ll_wordset_tool" value="editor" />
            <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
            <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="ll-wordset-editor-bulk__bar">
                <span class="ll-wordset-editor-selected-count" data-ll-wordset-editor-selected-count>
                    <?php echo esc_html__('0 selected', 'll-tools-text-domain'); ?>
                </span>
                <label class="ll-wordset-editor-all-filtered">
                    <input type="checkbox" name="ll_wordset_editor_all_filtered" value="1" data-ll-wordset-editor-all-filtered />
                    <span><?php echo esc_html(sprintf(_n('All %d filtered word', 'All %d filtered words', $total_filtered, 'll-tools-text-domain'), $total_filtered)); ?></span>
                </label>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo esc_html__('Action', 'll-tools-text-domain'); ?></span>
                    <select name="ll_wordset_manager_editor_action">
                        <option value="draft"><?php echo esc_html__('Move to draft', 'll-tools-text-domain'); ?></option>
                        <option value="publish"><?php echo esc_html__('Publish', 'll-tools-text-domain'); ?></option>
                        <option value="add_category"><?php echo esc_html__('Add category', 'll-tools-text-domain'); ?></option>
                        <option value="remove_category"><?php echo esc_html__('Remove category', 'll-tools-text-domain'); ?></option>
                        <option value="move_category"><?php echo esc_html__('Move to category', 'll-tools-text-domain'); ?></option>
                        <option value="missing_audio_review"><?php echo esc_html__('Missing-audio review', 'll-tools-text-domain'); ?></option>
                        <option value="missing_image_review"><?php echo esc_html__('Missing-image review', 'll-tools-text-domain'); ?></option>
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
        </form>

        <div class="ll-wordset-settings-card ll-wordset-editor-table-card">
            <template id="<?php echo esc_attr($move_target_template_id); ?>">
                <?php foreach ($move_choices as $choice) : ?>
                    <?php $choice_id = (int) ($choice['id'] ?? 0); ?>
                    <?php if ($choice_id <= 0) { continue; } ?>
                    <option value="<?php echo esc_attr((string) $choice_id); ?>"><?php echo esc_html((string) ($choice['label'] ?? '')); ?></option>
                <?php endforeach; ?>
            </template>
            <div class="ll-wordset-editor-table" role="table" aria-label="<?php echo esc_attr__('Words in this word set', 'll-tools-text-domain'); ?>">
                <div class="ll-wordset-editor-row ll-wordset-editor-row--head" role="row">
                    <span class="ll-wordset-editor-cell ll-wordset-editor-cell--check" role="columnheader">
                        <input type="checkbox" data-ll-wordset-editor-select-all aria-label="<?php echo esc_attr__('Select all visible words', 'll-tools-text-domain'); ?>" />
                    </span>
                    <span class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="columnheader">
                        <span class="ll-wordset-editor-sort-group">
                            <?php echo ll_tools_wordset_editor_sort_link($wordset_term, 'word', __('Word', 'll-tools-text-domain'), $filters, $back_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php echo ll_tools_wordset_editor_sort_link($wordset_term, 'translation', __('Translation', 'll-tools-text-domain'), $filters, $back_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    </span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo ll_tools_wordset_editor_sort_link($wordset_term, 'category', __('Categories', 'll-tools-text-domain'), $filters, $back_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo ll_tools_wordset_editor_sort_link($wordset_term, 'status', __('State', 'll-tools-text-domain'), $filters, $back_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="ll-wordset-editor-cell" role="columnheader"><?php echo ll_tools_wordset_editor_sort_link($wordset_term, 'recording', __('Media', 'll-tools-text-domain'), $filters, $back_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
                                <input type="checkbox" name="ll_wordset_editor_word_ids[]" value="<?php echo esc_attr((string) $word_id); ?>" form="<?php echo esc_attr($bulk_form_id); ?>" data-ll-wordset-editor-word />
                                <span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'll-tools-text-domain'), (string) ($row['title'] ?? ''))); ?></span>
                            </label>
                            <div class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="cell" data-label="<?php echo esc_attr__('Word', 'll-tools-text-domain'); ?>">
                                <strong class="ll-wordset-editor-word-title"><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong>
                                <?php if ((string) ($row['translation'] ?? '') !== '') : ?>
                                    <span class="ll-wordset-editor-word-translation"><?php echo esc_html((string) ($row['translation'] ?? '')); ?></span>
                                <?php endif; ?>
                                <details class="ll-wordset-editor-inline-edit">
                                    <summary aria-label="<?php echo esc_attr__('Quick edit word', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Quick edit word', 'll-tools-text-domain'); ?>">
                                        <?php echo ll_tools_wordset_editor_icon('edit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html__('Edit', 'll-tools-text-domain'); ?></span>
                                    </summary>
                                    <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-inline-form">
                                        <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                        <input type="hidden" name="ll_wordset_manager_editor_action" value="quick_update" />
                                        <input type="hidden" name="ll_wordset_editor_word_id" value="<?php echo esc_attr((string) $word_id); ?>" />
                                        <input type="hidden" name="ll_wordset_tool" value="editor" />
                                        <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                        <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <label>
                                            <span><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></span>
                                            <input type="text" name="ll_wordset_editor_word_title" value="<?php echo esc_attr((string) ($row['title'] ?? '')); ?>" required />
                                        </label>
                                        <label>
                                            <span><?php echo esc_html__('Translation', 'll-tools-text-domain'); ?></span>
                                            <input type="text" name="ll_wordset_editor_word_translation" value="<?php echo esc_attr((string) ($row['translation'] ?? '')); ?>" />
                                        </label>
                                        <button type="submit" class="ll-wordset-editor-icon-button" aria-label="<?php echo esc_attr__('Save quick edit', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Save quick edit', 'll-tools-text-domain'); ?>">
                                            <?php echo ll_tools_wordset_editor_icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </button>
                                    </form>
                                </details>
                            </div>
                            <div class="ll-wordset-editor-cell ll-wordset-editor-cell--categories" role="cell" data-label="<?php echo esc_attr__('Categories', 'll-tools-text-domain'); ?>">
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
                            <div class="ll-wordset-editor-cell ll-wordset-editor-cell--state" role="cell" data-label="<?php echo esc_attr__('State', 'll-tools-text-domain'); ?>">
                                <span class="ll-wordset-editor-state ll-wordset-editor-state--<?php echo esc_attr(sanitize_html_class($status)); ?>">
                                    <?php echo esc_html(ll_tools_wordset_editor_status_label($status)); ?>
                                </span>
                            </div>
                            <div class="ll-wordset-editor-cell ll-wordset-editor-cell--media" role="cell" data-label="<?php echo esc_attr__('Media', 'll-tools-text-domain'); ?>">
                                <div class="ll-wordset-editor-media">
                                    <span class="ll-wordset-editor-media__item <?php echo !empty($row['has_image']) ? 'is-ready' : 'is-missing'; ?>" title="<?php echo esc_attr(!empty($row['has_image']) ? __('Has image', 'll-tools-text-domain') : __('Missing image', 'll-tools-text-domain')); ?>">
                                        <?php echo ll_tools_wordset_editor_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="ll-wordset-editor-media__item <?php echo ((int) ($row['published_audio_count'] ?? 0) > 0) ? 'is-ready' : (!empty($row['missing_audio']) ? 'is-missing' : 'is-muted'); ?>" title="<?php echo esc_attr(!empty($row['missing_audio']) ? __('Missing audio', 'll-tools-text-domain') : sprintf(_n('%d published recording', '%d published recordings', (int) ($row['published_audio_count'] ?? 0), 'll-tools-text-domain'), (int) ($row['published_audio_count'] ?? 0))); ?>">
                                        <?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html((string) ((int) ($row['published_audio_count'] ?? 0))); ?></span>
                                    </span>
                                </div>
                            </div>
                            <?php $recording_rows = (array) ($recordings_by_word_id[$word_id] ?? []); ?>
                            <?php if (!empty($recording_rows)) : ?>
                                <div class="ll-wordset-editor-row__details" role="cell" aria-colspan="4" data-label="<?php echo esc_attr__('Recordings', 'll-tools-text-domain'); ?>">
                                    <div class="ll-wordset-editor-recordings">
                                        <?php foreach ($recording_rows as $recording_row) : ?>
                                            <?php
                                            $recording_id = (int) ($recording_row['id'] ?? 0);
                                            $recording_label = implode(', ', (array) ($recording_row['type_labels'] ?? []));
                                            if ($recording_label === '') {
                                                $recording_label = (string) ($recording_row['title'] ?? '');
                                            }
                                            if ($recording_label === '') {
                                                $recording_label = sprintf(__('Recording %d', 'll-tools-text-domain'), $recording_id);
                                            }
                                            ?>
                                            <div class="ll-wordset-editor-recording">
                                                <div class="ll-wordset-editor-recording__main">
                                                    <?php echo ll_tools_wordset_editor_icon('audio'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                    <span class="ll-wordset-editor-recording__label"><?php echo esc_html($recording_label); ?></span>
                                                    <span class="ll-wordset-editor-state ll-wordset-editor-state--<?php echo esc_attr(sanitize_html_class((string) ($recording_row['status'] ?? ''))); ?>"><?php echo esc_html(ll_tools_wordset_editor_status_label((string) ($recording_row['status'] ?? ''))); ?></span>
                                                </div>
                                                <div class="ll-wordset-editor-recording__actions">
                                                    <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-mini-form" data-ll-wordset-editor-confirm="<?php echo esc_attr__('Move this recording to Trash?', 'll-tools-text-domain'); ?>">
                                                        <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                                        <input type="hidden" name="ll_wordset_manager_editor_action" value="delete_recording" />
                                                        <input type="hidden" name="ll_wordset_editor_recording_id" value="<?php echo esc_attr((string) $recording_id); ?>" />
                                                        <input type="hidden" name="ll_wordset_tool" value="editor" />
                                                        <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                                        <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <button type="submit" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="<?php echo esc_attr__('Move recording to Trash', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Trash recording', 'll-tools-text-domain'); ?>">
                                                            <?php echo ll_tools_wordset_editor_icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-move-form" data-ll-wordset-editor-target-required="<?php echo esc_attr__('Choose a target word first.', 'll-tools-text-domain'); ?>">
                                                        <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                                        <input type="hidden" name="ll_wordset_manager_editor_action" value="move_recording" />
                                                        <input type="hidden" name="ll_wordset_editor_recording_id" value="<?php echo esc_attr((string) $recording_id); ?>" />
                                                        <input type="hidden" name="ll_wordset_tool" value="editor" />
                                                        <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                                        <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr('ll-wordset-editor-recording-target-' . $recording_id); ?>"><?php echo esc_html__('Move recording to word', 'll-tools-text-domain'); ?></label>
                                                        <select id="<?php echo esc_attr('ll-wordset-editor-recording-target-' . $recording_id); ?>" name="ll_wordset_editor_target_word_id" data-ll-wordset-editor-move-target data-ll-wordset-editor-source-word-id="<?php echo esc_attr((string) $word_id); ?>" data-ll-wordset-editor-options-template="<?php echo esc_attr($move_target_template_id); ?>">
                                                            <option value="0"><?php echo esc_html__('Move to...', 'll-tools-text-domain'); ?></option>
                                                        </select>
                                                        <button type="submit" class="ll-wordset-editor-icon-button" aria-label="<?php echo esc_attr__('Move recording', 'll-tools-text-domain'); ?>" title="<?php echo esc_attr__('Move recording', 'll-tools-text-domain'); ?>">
                                                            <?php echo ll_tools_wordset_editor_icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
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
        </div>

        <section id="ll-wordset-editor-history" class="ll-wordset-settings-card ll-wordset-editor-history" aria-label="<?php echo esc_attr__('Editor action history', 'll-tools-text-domain'); ?>">
            <div class="ll-wordset-editor-history__head">
                <h2 class="ll-wordset-settings-card__title"><?php echo esc_html__('Action history', 'll-tools-text-domain'); ?></h2>
                <span class="ll-wordset-editor-history__hint"><?php echo esc_html__('Undo is available for recent quick edits, Trash, recording moves, status, category, and review actions.', 'll-tools-text-domain'); ?></span>
            </div>
            <form class="ll-wordset-editor-history-filter" method="get" action="<?php echo esc_url(ll_tools_get_wordset_page_view_url($wordset_term, 'settings')); ?>">
                <input type="hidden" name="ll_wordset_tool" value="editor" />
                <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if ($back_url !== '') : ?>
                    <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                <?php endif; ?>
                <label class="ll-wordset-editor-field">
                    <span class="ll-wordset-editor-field__label"><?php echo ll_tools_wordset_editor_icon('filter'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__('History type', 'll-tools-text-domain'); ?></span>
                    <select name="ll_editor_history_type">
                        <option value=""><?php echo esc_html__('All actions', 'll-tools-text-domain'); ?></option>
                        <?php foreach (ll_tools_wordset_editor_history_type_options() as $type_key => $type_label) : ?>
                            <option value="<?php echo esc_attr($type_key); ?>" <?php selected((string) ($history_filters['type'] ?? ''), $type_key); ?>><?php echo esc_html($type_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--secondary">
                    <?php echo ll_tools_wordset_editor_icon('filter'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span><?php echo esc_html__('Show', 'll-tools-text-domain'); ?></span>
                </button>
            </form>
            <?php if (empty($history_page_rows)) : ?>
                <p class="ll-wordset-settings-empty"><?php echo esc_html__('No editor actions match this view.', 'll-tools-text-domain'); ?></p>
            <?php else : ?>
                <div class="ll-wordset-editor-history__list">
                    <?php foreach ($history_page_rows as $action_row) : ?>
                        <?php
                        $created_at = (string) ($action_row['created_at'] ?? '');
                        $created_label = $created_at !== '' ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $created_at) : '';
                        $is_undoable = !empty($action_row['undoable']) && empty($action_row['undone']);
                        $type = sanitize_key((string) ($action_row['type'] ?? ''));
                        $detail_lines = ll_tools_wordset_editor_history_detail_lines($action_row);
                        ?>
                        <div class="ll-wordset-editor-history__row">
                            <div class="ll-wordset-editor-history__main">
                                <div class="ll-wordset-editor-history__meta">
                                    <span class="ll-wordset-editor-pill"><?php echo esc_html(ll_tools_wordset_editor_history_type_label($type)); ?></span>
                                    <?php if ($created_label !== '') : ?>
                                        <span class="ll-wordset-editor-history__time"><?php echo esc_html($created_label); ?></span>
                                    <?php endif; ?>
                                    <span class="ll-wordset-editor-history__time"><?php echo esc_html(ll_tools_wordset_editor_history_user_label($action_row)); ?></span>
                                    <?php if (!empty($action_row['undone'])) : ?>
                                        <span class="ll-wordset-editor-pill ll-wordset-editor-pill--muted"><?php echo esc_html__('Undone', 'll-tools-text-domain'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="ll-wordset-editor-history__summary"><?php echo esc_html((string) ($action_row['summary'] ?? '')); ?></span>
                                <?php if (!empty($detail_lines)) : ?>
                                    <details class="ll-wordset-editor-history__details">
                                        <summary><?php echo esc_html__('Details', 'll-tools-text-domain'); ?></summary>
                                        <ul>
                                            <?php foreach ($detail_lines as $detail_line) : ?>
                                                <li><?php echo esc_html($detail_line); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_undoable) : ?>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" class="ll-wordset-editor-history__undo">
                                    <input type="hidden" name="ll_wordset_manager_editor_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                    <input type="hidden" name="ll_wordset_manager_editor_action" value="undo" />
                                    <input type="hidden" name="ll_wordset_editor_action_id" value="<?php echo esc_attr((string) ($action_row['id'] ?? '')); ?>" />
                                    <input type="hidden" name="ll_wordset_tool" value="editor" />
                                    <input type="hidden" name="ll_wordset_back" value="<?php echo esc_attr($back_url); ?>" />
                                    <?php echo ll_tools_wordset_editor_filter_hidden_inputs($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo ll_tools_wordset_editor_nonce_input($wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--secondary">
                                        <?php echo ll_tools_wordset_editor_icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html__('Undo', 'll-tools-text-domain'); ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($history_total_pages > 1) : ?>
                    <nav class="ll-wordset-editor-pagination" aria-label="<?php echo esc_attr__('Editor history pages', 'll-tools-text-domain'); ?>">
                        <?php
                        $history_base_query = $_GET;
                        $history_base_query['ll_wordset_tool'] = 'editor';
                        for ($history_page = 1; $history_page <= $history_total_pages; $history_page++) :
                            $history_base_query['ll_editor_history_page'] = $history_page;
                            $history_page_url = add_query_arg($history_base_query, ll_tools_get_wordset_page_view_url($wordset_term, 'settings'));
                            ?>
                            <a class="ll-wordset-editor-pagination__item <?php echo $history_page === $history_paged ? 'is-current' : ''; ?>" href="<?php echo esc_url($history_page_url); ?>" <?php echo $history_page === $history_paged ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html((string) $history_page); ?>
                            </a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </section>
    <?php

    return (string) ob_get_clean();
}
