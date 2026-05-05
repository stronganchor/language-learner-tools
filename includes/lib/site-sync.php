<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_SITE_SYNC_SCHEMA_VERSION')) {
    define('LL_TOOLS_SITE_SYNC_SCHEMA_VERSION', 1);
}

function ll_tools_site_sync_uuid_meta_key(): string {
    return '_ll_tools_sync_uuid';
}

function ll_tools_site_sync_capability(): string {
    return (string) apply_filters('ll_tools_site_sync_capability', 'manage_options');
}

function ll_tools_site_sync_supported_surfaces(): array {
    $surfaces = [
        'transcriptions' => [
            'label' => __('Recording transcriptions', 'll-tools-text-domain'),
            'record_type' => 'word_audio_transcription',
        ],
    ];

    return (array) apply_filters('ll_tools_site_sync_supported_surfaces', $surfaces);
}

function ll_tools_site_sync_normalize_surface(string $surface): string {
    $surface = sanitize_key($surface);
    return isset(ll_tools_site_sync_supported_surfaces()[$surface]) ? $surface : 'transcriptions';
}

function ll_tools_site_sync_transcription_value_keys(): array {
    return ['recording_text', 'recording_ipa', 'needs_review', 'review_fields', 'review_note'];
}

function ll_tools_site_sync_get_or_create_post_uuid(int $post_id, bool $ensure = true): string {
    if ($post_id <= 0) {
        return '';
    }

    $meta_key = ll_tools_site_sync_uuid_meta_key();
    $uuid = trim((string) get_post_meta($post_id, $meta_key, true));
    if ($uuid !== '' || !$ensure) {
        return $uuid;
    }

    $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5((string) wp_rand() . '|' . microtime(true));
    update_post_meta($post_id, $meta_key, $uuid);
    return $uuid;
}

function ll_tools_site_sync_get_or_create_term_uuid(int $term_id, bool $ensure = true): string {
    if ($term_id <= 0) {
        return '';
    }

    $meta_key = ll_tools_site_sync_uuid_meta_key();
    $uuid = trim((string) get_term_meta($term_id, $meta_key, true));
    if ($uuid !== '' || !$ensure) {
        return $uuid;
    }

    $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5((string) wp_rand() . '|' . microtime(true));
    update_term_meta($term_id, $meta_key, $uuid);
    return $uuid;
}

function ll_tools_site_sync_normalize_review_fields($fields): array {
    if (function_exists('ll_tools_ipa_keyboard_normalize_review_fields')) {
        $fields = ll_tools_ipa_keyboard_normalize_review_fields($fields);
    } elseif (is_string($fields)) {
        $fields = preg_split('/[,;|]/', $fields);
    }

    $normalized = [];
    foreach ((array) $fields as $key => $value) {
        if (is_string($key)) {
            if (!$value) {
                continue;
            }
            $field = sanitize_key($key);
        } else {
            $field = sanitize_key((string) $value);
        }
        if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
            $normalized[$field] = true;
        }
    }

    $fields = array_keys($normalized);
    sort($fields);
    return $fields;
}

function ll_tools_site_sync_record_values(int $recording_id, int $wordset_id): array {
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $recording_ipa = (string) get_post_meta($recording_id, 'recording_ipa', true);

    return [
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_ipa' => function_exists('ll_tools_word_grid_normalize_ipa_output')
            ? ll_tools_word_grid_normalize_ipa_output($recording_ipa, $transcription_mode)
            : $recording_ipa,
        'needs_review' => function_exists('ll_tools_ipa_keyboard_recording_needs_auto_review')
            ? (bool) ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id)
            : ((string) get_post_meta($recording_id, 'll_auto_transcription_needs_review', true) === '1'),
        'review_fields' => function_exists('ll_tools_ipa_keyboard_get_recording_review_field_list')
            ? ll_tools_site_sync_normalize_review_fields(ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id))
            : [],
        'review_note' => function_exists('ll_tools_ipa_keyboard_get_recording_review_note')
            ? ll_tools_ipa_keyboard_get_recording_review_note($recording_id)
            : trim((string) get_post_meta($recording_id, 'll_auto_transcription_review_note', true)),
    ];
}

function ll_tools_site_sync_normalize_record_values(array $values): array {
    return [
        'recording_text' => (string) ($values['recording_text'] ?? ''),
        'recording_ipa' => (string) ($values['recording_ipa'] ?? ''),
        'needs_review' => !empty($values['needs_review']),
        'review_fields' => ll_tools_site_sync_normalize_review_fields($values['review_fields'] ?? []),
        'review_note' => (string) ($values['review_note'] ?? ''),
    ];
}

function ll_tools_site_sync_value_hash(array $values): string {
    $values = ll_tools_site_sync_normalize_record_values($values);
    return hash('sha256', wp_json_encode($values));
}

function ll_tools_site_sync_record_natural_key(string $word_slug, string $recording_slug, array $recording_types): string {
    $recording_types = array_values(array_unique(array_filter(array_map('sanitize_title', $recording_types))));
    sort($recording_types);
    return 'word:' . sanitize_title($word_slug) . '|audio:' . sanitize_title($recording_slug) . '|types:' . implode(',', $recording_types);
}

function ll_tools_site_sync_collect_transcription_records(int $wordset_id, bool $ensure_sync_ids = true): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = get_posts([
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'title',
        'order' => 'ASC',
        'tax_query' => [
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$wordset_id],
            ],
        ],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', $word_ids)));
    if (empty($word_ids)) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'post_parent__in' => $word_ids,
    ]);

    $records = [];
    foreach ($audio_posts as $audio_post) {
        if (!($audio_post instanceof WP_Post)) {
            continue;
        }

        $word_id = (int) $audio_post->post_parent;
        if ($word_id <= 0) {
            continue;
        }

        $word_post = get_post($word_id);
        if (!($word_post instanceof WP_Post) || $word_post->post_type !== 'words') {
            continue;
        }

        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        $recording_types = is_wp_error($recording_types) ? [] : array_values(array_map('strval', (array) $recording_types));
        sort($recording_types);

        $values = ll_tools_site_sync_record_values((int) $audio_post->ID, $wordset_id);
        $word_sync_id = ll_tools_site_sync_get_or_create_post_uuid($word_id, $ensure_sync_ids);
        $sync_id = ll_tools_site_sync_get_or_create_post_uuid((int) $audio_post->ID, $ensure_sync_ids);
        $word_slug = (string) $word_post->post_name;
        $recording_slug = (string) $audio_post->post_name;

        $records[] = [
            'record_type' => 'word_audio_transcription',
            'sync_id' => $sync_id,
            'natural_key' => ll_tools_site_sync_record_natural_key($word_slug, $recording_slug, $recording_types),
            'word' => [
                'id' => $word_id,
                'sync_id' => $word_sync_id,
                'slug' => $word_slug,
                'title' => get_the_title($word_id),
            ],
            'recording' => [
                'id' => (int) $audio_post->ID,
                'slug' => $recording_slug,
                'title' => get_the_title((int) $audio_post->ID),
                'types' => $recording_types,
            ],
            'values' => $values,
            'value_hash' => ll_tools_site_sync_value_hash($values),
        ];
    }

    usort($records, static function (array $a, array $b): int {
        $a_key = (string) ($a['natural_key'] ?? '');
        $b_key = (string) ($b['natural_key'] ?? '');
        if ($a_key === $b_key) {
            return (int) (($a['recording']['id'] ?? 0) <=> ($b['recording']['id'] ?? 0));
        }
        return strcmp($a_key, $b_key);
    });

    return $records;
}

function ll_tools_site_sync_build_snapshot(int $wordset_id, string $surface = 'transcriptions', bool $ensure_sync_ids = true) {
    $surface = ll_tools_site_sync_normalize_surface($surface);
    $wordset = get_term($wordset_id, 'wordset');
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error(
            'll_tools_site_sync_invalid_wordset',
            __('Select a valid word set for site sync.', 'll-tools-text-domain')
        );
    }

    $records = [];
    if ($surface === 'transcriptions') {
        $records = ll_tools_site_sync_collect_transcription_records($wordset_id, $ensure_sync_ids);
    }

    return [
        'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
        'surface' => $surface,
        'generated_at_gmt' => gmdate('c'),
        'site_url' => home_url('/'),
        'plugin_version' => defined('LL_TOOLS_VERSION') ? LL_TOOLS_VERSION : '',
        'wordset' => [
            'id' => (int) $wordset->term_id,
            'sync_id' => ll_tools_site_sync_get_or_create_term_uuid((int) $wordset->term_id, $ensure_sync_ids),
            'slug' => (string) $wordset->slug,
            'name' => (string) $wordset->name,
        ],
        'record_count' => count($records),
        'records' => $records,
    ];
}

function ll_tools_site_sync_record_lookup_key(array $record): string {
    $sync_id = trim((string) ($record['sync_id'] ?? ''));
    if ($sync_id !== '') {
        return 'sync:' . $sync_id;
    }

    $natural_key = trim((string) ($record['natural_key'] ?? ''));
    return $natural_key !== '' ? 'natural:' . $natural_key : '';
}

function ll_tools_site_sync_index_snapshot(array $snapshot): array {
    $index = [
        'by_sync_id' => [],
        'by_natural_key' => [],
        'records' => [],
    ];

    foreach ((array) ($snapshot['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }

        $record['values'] = ll_tools_site_sync_normalize_record_values((array) ($record['values'] ?? []));
        $lookup_key = ll_tools_site_sync_record_lookup_key($record);
        if ($lookup_key !== '') {
            $index['records'][$lookup_key] = $record;
        }

        $sync_id = trim((string) ($record['sync_id'] ?? ''));
        if ($sync_id !== '') {
            $index['by_sync_id'][$sync_id] = $record;
        }

        $natural_key = trim((string) ($record['natural_key'] ?? ''));
        if ($natural_key !== '') {
            $index['by_natural_key'][$natural_key] = $record;
        }
    }

    return $index;
}

function ll_tools_site_sync_find_matching_record(array $record, array $index): ?array {
    $sync_id = trim((string) ($record['sync_id'] ?? ''));
    if ($sync_id !== '' && isset($index['by_sync_id'][$sync_id]) && is_array($index['by_sync_id'][$sync_id])) {
        return $index['by_sync_id'][$sync_id];
    }

    $natural_key = trim((string) ($record['natural_key'] ?? ''));
    if ($natural_key !== '' && isset($index['by_natural_key'][$natural_key]) && is_array($index['by_natural_key'][$natural_key])) {
        return $index['by_natural_key'][$natural_key];
    }

    return null;
}

function ll_tools_site_sync_values_equal($a, $b): bool {
    if (is_array($a) || is_array($b)) {
        $a = array_values(array_map('strval', (array) $a));
        $b = array_values(array_map('strval', (array) $b));
        sort($a);
        sort($b);
        return $a === $b;
    }

    if (is_bool($a) || is_bool($b)) {
        return (bool) $a === (bool) $b;
    }

    return (string) $a === (string) $b;
}

function ll_tools_site_sync_build_conflict_note(array $conflict): string {
    $field = (string) ($conflict['field'] ?? '');
    $word_title = (string) ($conflict['word_title'] ?? '');
    $recording_title = (string) ($conflict['recording_title'] ?? '');
    $live_value = $conflict['remote_value'] ?? '';
    $staging_value = $conflict['local_value'] ?? '';
    $base_value = $conflict['base_value'] ?? '';

    $format_value = static function ($value): string {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    };

    $note = sprintf(
        /* translators: 1: field key, 2: word title, 3: recording title */
        __('Site sync conflict for %1$s on "%2$s" / "%3$s".', 'll-tools-text-domain'),
        $field,
        $word_title,
        $recording_title
    );

    return $note . "\n\n"
        . __('Live value:', 'll-tools-text-domain') . "\n" . $format_value($live_value) . "\n\n"
        . __('Staging value:', 'll-tools-text-domain') . "\n" . $format_value($staging_value) . "\n\n"
        . __('Last pulled value:', 'll-tools-text-domain') . "\n" . $format_value($base_value);
}

function ll_tools_site_sync_plan_empty(string $direction, array $local_snapshot, array $remote_snapshot, array $base_snapshot): array {
    return [
        'direction' => $direction,
        'surface' => ll_tools_site_sync_normalize_surface((string) ($local_snapshot['surface'] ?? $remote_snapshot['surface'] ?? 'transcriptions')),
        'generated_at_gmt' => gmdate('c'),
        'local_wordset' => $local_snapshot['wordset'] ?? [],
        'remote_wordset' => $remote_snapshot['wordset'] ?? [],
        'has_base_snapshot' => !empty($base_snapshot['records']),
        'actions' => [],
        'conflicts' => [],
        'skipped' => [],
        'remote_updates' => [],
        'conflict_review_updates' => [],
        'stats' => [
            'records_checked' => 0,
            'fields_checked' => 0,
            'fields_to_apply' => 0,
            'conflicts' => 0,
            'skipped' => 0,
        ],
    ];
}

function ll_tools_site_sync_build_push_plan(array $local_snapshot, array $remote_snapshot, array $base_snapshot = []): array {
    $plan = ll_tools_site_sync_plan_empty('push', $local_snapshot, $remote_snapshot, $base_snapshot);
    $remote_index = ll_tools_site_sync_index_snapshot($remote_snapshot);
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $seen_remote_keys = [];

    foreach ((array) ($local_snapshot['records'] ?? []) as $local_record) {
        if (!is_array($local_record)) {
            continue;
        }

        $plan['stats']['records_checked']++;
        $remote_record = ll_tools_site_sync_find_matching_record($local_record, $remote_index);
        if ($remote_record === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_remote_record',
                'record' => $local_record,
            ];
            $plan['stats']['skipped']++;
            continue;
        }

        $remote_key = ll_tools_site_sync_record_lookup_key($remote_record);
        if ($remote_key !== '') {
            $seen_remote_keys[$remote_key] = true;
        }

        $base_record = ll_tools_site_sync_find_matching_record($local_record, $base_index);
        if ($base_record === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_base_record',
                'record' => $local_record,
                'remote_record' => $remote_record,
            ];
            $plan['stats']['skipped']++;
            continue;
        }

        $local_values = ll_tools_site_sync_normalize_record_values((array) ($local_record['values'] ?? []));
        $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
        $base_values = ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
        $push_fields = [];
        $conflict_fields = [];

        foreach (ll_tools_site_sync_transcription_value_keys() as $field) {
            $plan['stats']['fields_checked']++;
            $local_value = $local_values[$field] ?? '';
            $remote_value = $remote_values[$field] ?? '';
            $base_value = $base_values[$field] ?? '';

            if (ll_tools_site_sync_values_equal($local_value, $remote_value)) {
                continue;
            }

            $local_changed = !ll_tools_site_sync_values_equal($local_value, $base_value);
            $remote_changed = !ll_tools_site_sync_values_equal($remote_value, $base_value);

            if ($local_changed && !$remote_changed) {
                $push_fields[$field] = $local_value;
                continue;
            }

            if ($local_changed && $remote_changed) {
                $conflict = [
                    'field' => $field,
                    'local_value' => $local_value,
                    'remote_value' => $remote_value,
                    'base_value' => $base_value,
                    'word_title' => (string) (($local_record['word']['title'] ?? '') ?: ($remote_record['word']['title'] ?? '')),
                    'recording_title' => (string) (($local_record['recording']['title'] ?? '') ?: ($remote_record['recording']['title'] ?? '')),
                    'local_record' => $local_record,
                    'remote_record' => $remote_record,
                ];
                $conflict_fields[] = $conflict;
                $plan['conflicts'][] = $conflict;
            }
        }

        if (!empty($push_fields)) {
            $update = ['recording_id' => (int) ($remote_record['recording']['id'] ?? 0)];
            foreach ($push_fields as $field => $value) {
                if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
                    $update[$field] = $value;
                }
            }
            if (array_key_exists('needs_review', $push_fields) || array_key_exists('review_fields', $push_fields) || array_key_exists('review_note', $push_fields)) {
                $update['needs_review'] = (bool) $local_values['needs_review'];
                $update['review_fields'] = (array) $local_values['review_fields'];
                $update['review_note'] = (string) $local_values['review_note'];
            }

            $plan['actions'][] = [
                'type' => 'push',
                'fields' => array_keys($push_fields),
                'local_record' => $local_record,
                'remote_record' => $remote_record,
            ];
            $plan['remote_updates'][] = $update;
            $plan['stats']['fields_to_apply'] += count($push_fields);
        }

        foreach ($conflict_fields as $conflict) {
            $review_field = in_array((string) $conflict['field'], ['recording_text', 'recording_ipa'], true)
                ? (string) $conflict['field']
                : 'recording_ipa';
            $plan['conflict_review_updates'][] = [
                'recording_id' => (int) ($remote_record['recording']['id'] ?? 0),
                'needs_review' => true,
                'review_fields' => [$review_field],
                'review_note' => ll_tools_site_sync_build_conflict_note($conflict),
            ];
        }
    }

    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }
        $remote_key = ll_tools_site_sync_record_lookup_key($remote_record);
        if ($remote_key !== '' && isset($seen_remote_keys[$remote_key])) {
            continue;
        }
        if (ll_tools_site_sync_find_matching_record($remote_record, ll_tools_site_sync_index_snapshot($local_snapshot)) === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_local_record',
                'remote_record' => $remote_record,
            ];
            $plan['stats']['skipped']++;
        }
    }

    $plan['stats']['conflicts'] = count($plan['conflicts']);
    return $plan;
}

function ll_tools_site_sync_build_pull_plan(array $local_snapshot, array $remote_snapshot, array $base_snapshot = []): array {
    $plan = ll_tools_site_sync_plan_empty('pull', $local_snapshot, $remote_snapshot, $base_snapshot);
    $local_index = ll_tools_site_sync_index_snapshot($local_snapshot);
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);

    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }

        $plan['stats']['records_checked']++;
        $local_record = ll_tools_site_sync_find_matching_record($remote_record, $local_index);
        if ($local_record === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_local_record',
                'remote_record' => $remote_record,
            ];
            $plan['stats']['skipped']++;
            continue;
        }

        $base_record = ll_tools_site_sync_find_matching_record($remote_record, $base_index);
        $local_values = ll_tools_site_sync_normalize_record_values((array) ($local_record['values'] ?? []));
        $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
        $base_values = $base_record === null
            ? null
            : ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
        $pull_fields = [];

        foreach (ll_tools_site_sync_transcription_value_keys() as $field) {
            $plan['stats']['fields_checked']++;
            $local_value = $local_values[$field] ?? '';
            $remote_value = $remote_values[$field] ?? '';

            if (ll_tools_site_sync_values_equal($local_value, $remote_value)) {
                continue;
            }

            if ($base_values === null) {
                $pull_fields[$field] = $remote_value;
                continue;
            }

            $base_value = $base_values[$field] ?? '';
            $local_changed = !ll_tools_site_sync_values_equal($local_value, $base_value);
            $remote_changed = !ll_tools_site_sync_values_equal($remote_value, $base_value);

            if (!$local_changed && $remote_changed) {
                $pull_fields[$field] = $remote_value;
                continue;
            }

            if ($local_changed && $remote_changed) {
                $plan['conflicts'][] = [
                    'field' => $field,
                    'local_value' => $local_value,
                    'remote_value' => $remote_value,
                    'base_value' => $base_value,
                    'word_title' => (string) (($local_record['word']['title'] ?? '') ?: ($remote_record['word']['title'] ?? '')),
                    'recording_title' => (string) (($local_record['recording']['title'] ?? '') ?: ($remote_record['recording']['title'] ?? '')),
                    'local_record' => $local_record,
                    'remote_record' => $remote_record,
                ];
            }
        }

        $needs_sync_link = trim((string) ($remote_record['sync_id'] ?? '')) !== ''
            && trim((string) ($remote_record['sync_id'] ?? '')) !== trim((string) ($local_record['sync_id'] ?? ''));
        $remote_word_sync_id = trim((string) ($remote_record['word']['sync_id'] ?? ''));
        if ($remote_word_sync_id !== '' && $remote_word_sync_id !== trim((string) ($local_record['word']['sync_id'] ?? ''))) {
            $needs_sync_link = true;
        }

        if (!empty($pull_fields) || $needs_sync_link) {
            $plan['actions'][] = [
                'type' => !empty($pull_fields) ? 'pull' : 'link_sync_id',
                'fields' => array_keys($pull_fields),
                'local_record' => $local_record,
                'remote_record' => $remote_record,
                'values' => $pull_fields,
            ];
            $plan['stats']['fields_to_apply'] += count($pull_fields);
        }
    }

    $plan['stats']['conflicts'] = count($plan['conflicts']);
    return $plan;
}

function ll_tools_site_sync_merge_base_snapshot_after_pull(array $base_snapshot, array $remote_snapshot, array $pull_plan): array {
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $conflict_fields = [];

    foreach ((array) ($pull_plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }
        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $key = ll_tools_site_sync_record_lookup_key($remote_record);
        $field = (string) ($conflict['field'] ?? '');
        if ($key !== '' && $field !== '') {
            $conflict_fields[$key][$field] = true;
        }
    }

    $merged = $remote_snapshot;
    $records = [];
    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }

        $key = ll_tools_site_sync_record_lookup_key($remote_record);
        $base_record = ll_tools_site_sync_find_matching_record($remote_record, $base_index);
        if ($key !== '' && $base_record !== null && !empty($conflict_fields[$key])) {
            $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
            $base_values = ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
            foreach (array_keys($conflict_fields[$key]) as $field) {
                if (array_key_exists($field, $base_values)) {
                    $remote_values[$field] = $base_values[$field];
                }
            }
            $remote_record['values'] = $remote_values;
            $remote_record['value_hash'] = ll_tools_site_sync_value_hash($remote_values);
        }

        $records[] = $remote_record;
    }

    $merged['records'] = $records;
    $merged['record_count'] = count($records);
    $merged['generated_at_gmt'] = gmdate('c');
    return $merged;
}

function ll_tools_site_sync_apply_record_values(int $recording_id, int $wordset_id, array $values): array {
    $values = ll_tools_site_sync_normalize_record_values($values);
    $field_updates = [];
    foreach (['recording_text', 'recording_ipa'] as $field) {
        if (array_key_exists($field, $values)) {
            $field_updates[$field] = (string) $values[$field];
        }
    }

    if (!empty($field_updates)) {
        if (function_exists('ll_tools_ipa_keyboard_update_recording_fields')) {
            ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, $field_updates);
        } else {
            foreach ($field_updates as $meta_key => $value) {
                $value = sanitize_text_field($value);
                if ($value === '') {
                    delete_post_meta($recording_id, $meta_key);
                } else {
                    update_post_meta($recording_id, $meta_key, $value);
                }
            }
        }
    }

    if (array_key_exists('needs_review', $values) && function_exists('ll_tools_ipa_keyboard_set_recording_review_state')) {
        $needs_review = (bool) $values['needs_review'];
        $review_fields = ll_tools_site_sync_normalize_review_fields($values['review_fields'] ?? []);
        if (!$needs_review && function_exists('ll_tools_ipa_keyboard_clear_recording_auto_review')) {
            ll_tools_ipa_keyboard_clear_recording_auto_review($recording_id);
        } elseif ($needs_review) {
            if (empty($review_fields)) {
                $review_fields = ['recording_ipa'];
            }
            foreach ($review_fields as $review_field) {
                ll_tools_ipa_keyboard_set_recording_review_state(
                    $recording_id,
                    true,
                    $review_field,
                    (string) ($values['review_note'] ?? '')
                );
            }
        }
    }

    return ll_tools_site_sync_record_values($recording_id, $wordset_id);
}

function ll_tools_site_sync_apply_pull_plan(array $plan, int $local_wordset_id): array {
    $summary = [
        'records_updated' => 0,
        'fields_updated' => 0,
        'sync_ids_linked' => 0,
        'errors' => [],
    ];

    foreach ((array) ($plan['actions'] ?? []) as $action) {
        if (!is_array($action) || !in_array((string) ($action['type'] ?? ''), ['pull', 'link_sync_id'], true)) {
            continue;
        }

        $local_record = (array) ($action['local_record'] ?? []);
        $remote_record = (array) ($action['remote_record'] ?? []);
        $recording_id = (int) ($local_record['recording']['id'] ?? 0);
        if ($recording_id <= 0) {
            $summary['errors'][] = __('Skipped a pull row because the local recording ID was missing.', 'll-tools-text-domain');
            continue;
        }

        $local_sync_id = trim((string) ($local_record['sync_id'] ?? ''));
        $remote_sync_id = trim((string) ($remote_record['sync_id'] ?? ''));
        if ($remote_sync_id !== '' && $local_sync_id !== $remote_sync_id) {
            update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), $remote_sync_id);
            $summary['sync_ids_linked']++;
        }

        $remote_word_sync_id = trim((string) ($remote_record['word']['sync_id'] ?? ''));
        $local_word_id = (int) ($local_record['word']['id'] ?? 0);
        if ($local_word_id > 0 && $remote_word_sync_id !== '' && (string) ($local_record['word']['sync_id'] ?? '') !== $remote_word_sync_id) {
            update_post_meta($local_word_id, ll_tools_site_sync_uuid_meta_key(), $remote_word_sync_id);
            $summary['sync_ids_linked']++;
        }

        if ((string) ($action['type'] ?? '') === 'pull') {
            $before = ll_tools_site_sync_record_values($recording_id, $local_wordset_id);
            $values = array_merge($before, (array) ($action['values'] ?? []));
            $after = ll_tools_site_sync_apply_record_values($recording_id, $local_wordset_id, $values);
            if ($after !== $before) {
                $summary['records_updated']++;
                $summary['fields_updated'] += count((array) ($action['fields'] ?? []));
            }
        }
    }

    return $summary;
}

function ll_tools_site_sync_snapshot_endpoint(WP_REST_Request $request) {
    if (!function_exists('ll_tools_rest_automation_resolve_wordset_term')) {
        return new WP_Error(
            'll_tools_site_sync_rest_unavailable',
            __('LL Tools automation helpers are unavailable.', 'll-tools-text-domain'),
            ['status' => 500]
        );
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $surface = ll_tools_site_sync_normalize_surface((string) ($request->get_param('surface') ?? 'transcriptions'));
    $ensure_sync_ids = !($request->get_param('ensure_sync_ids') === '0' || $request->get_param('ensure_sync_ids') === false);
    $snapshot = ll_tools_site_sync_build_snapshot((int) $wordset_term->term_id, $surface, $ensure_sync_ids);
    if (is_wp_error($snapshot)) {
        return $snapshot;
    }

    return rest_ensure_response($snapshot);
}

function ll_tools_site_sync_register_rest_routes(): void {
    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/site-sync/snapshot', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_site_sync_snapshot_endpoint',
        'permission_callback' => function (WP_REST_Request $request) {
            if (!function_exists('ll_tools_rest_automation_require_wordset_access')) {
                return new WP_Error(
                    'll_tools_site_sync_rest_unavailable',
                    __('LL Tools automation helpers are unavailable.', 'll-tools-text-domain'),
                    ['status' => 500]
                );
            }
            return ll_tools_rest_automation_require_wordset_access($request);
        },
        'args' => [
            'surface' => [
                'required' => false,
                'type' => 'string',
            ],
            'ensure_sync_ids' => [
                'required' => false,
                'type' => 'boolean',
            ],
        ],
    ]);
}
add_action('rest_api_init', 'll_tools_site_sync_register_rest_routes');
