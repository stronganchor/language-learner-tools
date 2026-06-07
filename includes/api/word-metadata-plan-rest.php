<?php
if (!defined('WPINC')) {
    die;
}

function ll_tools_rest_word_metadata_plan_job_option_name(string $job_id): string {
    return 'll_tools_word_metadata_plan_job_' . sanitize_key(str_replace('-', '_', $job_id));
}

function ll_tools_rest_word_metadata_plan_max_items(): int {
    return max(1, (int) apply_filters('ll_tools_rest_word_metadata_plan_max_items', 500));
}

function ll_tools_rest_word_metadata_plan_supported_fields(): array {
    $fields = [
        'word_title',
        'word_text',
        'word_translation',
        'word_english_meaning',
        'word_translations',
        'word_note',
        'dictionary_entry_id',
        'dictionary_entry_title',
        'part_of_speech',
        'grammatical_gender',
        'grammatical_plurality',
        'verb_tense',
        'verb_mood',
        'word_category_ids',
    ];

    return array_values(array_unique(array_map('sanitize_key', (array) apply_filters('ll_tools_rest_word_metadata_plan_supported_fields', $fields))));
}

function ll_tools_rest_word_metadata_plan_field_alias(string $field): string {
    $field = sanitize_key($field);
    $aliases = [
        'title' => 'word_title',
        'post_title' => 'word_title',
        'word_title' => 'word_title',
        'text' => 'word_text',
        'word_text' => 'word_text',
        'translation' => 'word_translation',
        'word_translation' => 'word_translation',
        'translations' => 'word_translations',
        'translation_map' => 'word_translations',
        'word_translation_map' => 'word_translations',
        'word_translations' => 'word_translations',
        'helper_translation' => 'word_english_meaning',
        'known_language_translation' => 'word_english_meaning',
        'english_meaning' => 'word_english_meaning',
        'word_english_meaning' => 'word_english_meaning',
        'note' => 'word_note',
        'word_note' => 'word_note',
        'dictionary_entry' => 'dictionary_entry_title',
        'dictionary_entry_title' => 'dictionary_entry_title',
        'dictionary_entry_id' => 'dictionary_entry_id',
        'entry_id' => 'dictionary_entry_id',
        'part_of_speech' => 'part_of_speech',
        'pos' => 'part_of_speech',
        'grammatical_gender' => 'grammatical_gender',
        'gender' => 'grammatical_gender',
        'grammatical_plurality' => 'grammatical_plurality',
        'plurality' => 'grammatical_plurality',
        'verb_tense' => 'verb_tense',
        'tense' => 'verb_tense',
        'verb_mood' => 'verb_mood',
        'mood' => 'verb_mood',
        'category_ids' => 'word_category_ids',
        'categories' => 'word_category_ids',
        'word_categories' => 'word_category_ids',
        'word_category_ids' => 'word_category_ids',
    ];

    $normalized = (string) ($aliases[$field] ?? '');
    if ($normalized === '' && preg_match('/^(?:word_)?translation_([a-z0-9_-]+)$/', $field, $matches)) {
        $locale = function_exists('ll_tools_normalize_translation_locale')
            ? ll_tools_normalize_translation_locale((string) $matches[1])
            : sanitize_key((string) $matches[1]);
        $normalized = $locale !== '' ? 'word_translation_' . $locale : '';
    }
    if (
        $normalized !== ''
        && !in_array($normalized, ll_tools_rest_word_metadata_plan_supported_fields(), true)
        && !ll_tools_rest_word_metadata_plan_is_locale_translation_field($normalized)
    ) {
        return '';
    }

    return $normalized;
}

function ll_tools_rest_word_metadata_plan_is_locale_translation_field(string $field): bool {
    return (bool) preg_match('/^word_translation_[a-z0-9][a-z0-9_-]{0,31}$/', sanitize_key($field));
}

function ll_tools_rest_word_metadata_plan_is_control_key(string $key): bool {
    return in_array(sanitize_key($key), [
        'id',
        'word',
        'word_id',
        'word_slug',
        'slug',
        'set',
        'expected',
        'note',
        'reason',
    ], true);
}

function ll_tools_rest_word_metadata_plan_has_category_fields(array $raw_updates): bool {
    foreach ($raw_updates as $raw_update) {
        $update = is_array($raw_update) ? $raw_update : [];
        foreach ([$update, (array) ($update['set'] ?? []), (array) ($update['expected'] ?? [])] as $source) {
            foreach ($source as $key => $_value) {
                $key = is_string($key) ? $key : '';
                if ($key !== '' && ll_tools_rest_word_metadata_plan_field_alias($key) === 'word_category_ids') {
                    return true;
                }
                if (preg_match('/^(old|expected)_(.+)$/', $key, $matches) && ll_tools_rest_word_metadata_plan_field_alias((string) $matches[2]) === 'word_category_ids') {
                    return true;
                }
            }
        }
    }

    return false;
}

function ll_tools_rest_word_metadata_plan_scalar_string($value): string {
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
}

function ll_tools_rest_word_metadata_plan_normalize_category_ids($value, int $wordset_id, array $available_category_ids) {
    $raw_values = [];
    if (is_string($value)) {
        $raw_values = preg_split('/[\s,;|]+/', trim($value));
    } elseif (is_array($value)) {
        foreach ($value as $item) {
            if (is_array($item)) {
                $raw_values[] = $item['id'] ?? ($item['term_id'] ?? ($item['category_id'] ?? 0));
            } else {
                $raw_values[] = $item;
            }
        }
    } elseif (is_scalar($value)) {
        $raw_values = [$value];
    }

    $ids = ll_tools_rest_automation_prepare_id_list((array) $raw_values);
    $available_lookup = array_fill_keys(array_map('intval', $available_category_ids), true);
    $normalized = [];
    foreach ($ids as $id) {
        $category_id = (int) $id;
        if (!isset($available_lookup[$category_id]) && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $effective_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
            if ($effective_id > 0 && isset($available_lookup[$effective_id])) {
                $category_id = $effective_id;
            }
        }
        if (!isset($available_lookup[$category_id])) {
            return new WP_Error(
                'll_tools_rest_word_metadata_plan_category_out_of_scope',
                __('All word_category_ids must belong to the requested word set.', 'll-tools-text-domain')
            );
        }
        $normalized[$category_id] = true;
    }

    return array_values(array_map('intval', array_keys($normalized)));
}

function ll_tools_rest_word_metadata_plan_normalize_value(string $field, $value, int $wordset_id, array $available_category_ids) {
    if ($field === 'word_category_ids') {
        return ll_tools_rest_word_metadata_plan_normalize_category_ids($value, $wordset_id, $available_category_ids);
    }
    if ($field === 'dictionary_entry_id') {
        return ll_tools_rest_automation_sanitize_helper_dictionary_entry_id($value);
    }
    if ($field === 'word_title') {
        return ll_tools_rest_automation_sanitize_word_title_value($value);
    }
    if ($field === 'word_text') {
        $raw = ll_tools_rest_word_metadata_plan_scalar_string($value);
        return function_exists('ll_sanitize_word_title_text')
            ? ll_sanitize_word_title_text($raw)
            : trim(sanitize_text_field($raw));
    }
    if (in_array($field, ['word_translation', 'word_english_meaning'], true) || ll_tools_rest_word_metadata_plan_is_locale_translation_field($field)) {
        return ll_tools_rest_automation_sanitize_helper_translation_value($value);
    }
    if ($field === 'word_translations') {
        return function_exists('ll_tools_normalize_word_translation_map')
            ? ll_tools_normalize_word_translation_map($value)
            : [];
    }
    if ($field === 'word_note') {
        return sanitize_textarea_field(ll_tools_rest_word_metadata_plan_scalar_string($value));
    }
    if ($field === 'dictionary_entry_title') {
        return trim(sanitize_text_field(ll_tools_rest_word_metadata_plan_scalar_string($value)));
    }
    if ($field === 'part_of_speech') {
        return sanitize_title(ll_tools_rest_word_metadata_plan_scalar_string($value));
    }

    return trim(sanitize_text_field(ll_tools_rest_word_metadata_plan_scalar_string($value)));
}

function ll_tools_rest_word_metadata_plan_normalize_field_map(array $raw_map, int $wordset_id, array $available_category_ids, array &$errors, int $index, string $context): array {
    $normalized = [];
    foreach ($raw_map as $raw_key => $raw_value) {
        if (!is_string($raw_key) || $raw_key === '') {
            continue;
        }

        $field = ll_tools_rest_word_metadata_plan_field_alias($raw_key);
        if ($field === '') {
            $errors[] = [
                'index' => $index,
                'field' => $raw_key,
                'context' => $context,
                'message' => __('Unsupported word metadata field.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $value = ll_tools_rest_word_metadata_plan_normalize_value($field, $raw_value, $wordset_id, $available_category_ids);
        if (is_wp_error($value)) {
            $errors[] = [
                'index' => $index,
                'field' => $field,
                'context' => $context,
                'message' => $value->get_error_message(),
            ];
            continue;
        }

        $normalized[$field] = $value;
    }

    return $normalized;
}

function ll_tools_rest_word_metadata_plan_extract_update_maps(array $update, int $wordset_id, array $available_category_ids, array &$errors, int $index): array {
    $set_map = [];
    $expected_map = [];

    if (isset($update['set']) && is_array($update['set'])) {
        $set_map = array_merge($set_map, ll_tools_rest_word_metadata_plan_normalize_field_map($update['set'], $wordset_id, $available_category_ids, $errors, $index, 'set'));
    }

    if (array_key_exists('field', $update) && array_key_exists('value', $update)) {
        $field = ll_tools_rest_word_metadata_plan_field_alias((string) $update['field']);
        if ($field === '') {
            $errors[] = [
                'index' => $index,
                'field' => (string) $update['field'],
                'context' => 'set',
                'message' => __('Unsupported word metadata field.', 'll-tools-text-domain'),
            ];
        } else {
            $value = ll_tools_rest_word_metadata_plan_normalize_value($field, $update['value'], $wordset_id, $available_category_ids);
            if (is_wp_error($value)) {
                $errors[] = [
                    'index' => $index,
                    'field' => $field,
                    'context' => 'set',
                    'message' => $value->get_error_message(),
                ];
            } else {
                $set_map[$field] = $value;
            }
        }
    }

    foreach ($update as $key => $value) {
        if (!is_string($key) || ll_tools_rest_word_metadata_plan_is_control_key($key)) {
            continue;
        }
        if (preg_match('/^(old|expected)_(.+)$/', $key)) {
            continue;
        }
        if (in_array($key, ['field', 'value'], true)) {
            continue;
        }
        $field = ll_tools_rest_word_metadata_plan_field_alias($key);
        if ($field === '') {
            continue;
        }
        $normalized_value = ll_tools_rest_word_metadata_plan_normalize_value($field, $value, $wordset_id, $available_category_ids);
        if (is_wp_error($normalized_value)) {
            $errors[] = [
                'index' => $index,
                'field' => $field,
                'context' => 'set',
                'message' => $normalized_value->get_error_message(),
            ];
            continue;
        }
        $set_map[$field] = $normalized_value;
    }

    if (isset($update['expected']) && is_array($update['expected'])) {
        $expected_map = array_merge($expected_map, ll_tools_rest_word_metadata_plan_normalize_field_map($update['expected'], $wordset_id, $available_category_ids, $errors, $index, 'expected'));
    }

    foreach ($update as $key => $value) {
        if (!is_string($key) || !preg_match('/^(old|expected)_(.+)$/', $key, $matches)) {
            continue;
        }
        $field = ll_tools_rest_word_metadata_plan_field_alias((string) $matches[2]);
        if ($field === '') {
            continue;
        }
        $normalized_value = ll_tools_rest_word_metadata_plan_normalize_value($field, $value, $wordset_id, $available_category_ids);
        if (is_wp_error($normalized_value)) {
            $errors[] = [
                'index' => $index,
                'field' => $field,
                'context' => 'expected',
                'message' => $normalized_value->get_error_message(),
            ];
            continue;
        }
        $expected_map[$field] = $normalized_value;
    }

    return [
        'set' => $set_map,
        'expected' => $expected_map,
    ];
}

function ll_tools_rest_word_metadata_plan_job_default_summary(int $input_count): array {
    return [
        'input_count' => $input_count,
        'matched_count' => 0,
        'processed_count' => 0,
        'changed_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'skipped_count' => 0,
        'error_count' => 0,
        'invalidated_category_ids' => [],
        'invalidated_wordset_cache' => false,
        'public_static_cache_purged' => false,
        'updated' => [],
        'skipped' => [],
        'errors' => [],
    ];
}

function ll_tools_rest_word_metadata_plan_job_save(array $job): bool {
    $job_id = (string) ($job['id'] ?? '');
    if ($job_id === '') {
        return false;
    }

    return update_option(ll_tools_rest_word_metadata_plan_job_option_name($job_id), $job, false);
}

function ll_tools_rest_word_metadata_plan_job_get(string $job_id) {
    $job_id = trim($job_id);
    if ($job_id === '' || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $job_id)) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_job_id', __('Invalid word metadata plan job ID.', 'll-tools-text-domain'), ['status' => 404]);
    }

    $job = get_option(ll_tools_rest_word_metadata_plan_job_option_name($job_id), null);
    if (!is_array($job)) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_job_not_found', __('Word metadata plan job was not found.', 'll-tools-text-domain'), ['status' => 404]);
    }

    return $job;
}

function ll_tools_rest_word_metadata_plan_job_summary(array $job, bool $include_recent = true): array {
    $summary = (array) ($job['summary'] ?? []);
    $total = max(0, (int) ($job['total'] ?? 0));
    $current_index = max(0, (int) ($job['current_index'] ?? 0));
    $payload = [
        'id' => (string) ($job['id'] ?? ''),
        'status' => (string) ($job['status'] ?? 'unknown'),
        'created_at_gmt' => (string) ($job['created_at_gmt'] ?? ''),
        'updated_at_gmt' => (string) ($job['updated_at_gmt'] ?? ''),
        'completed_at_gmt' => (string) ($job['completed_at_gmt'] ?? ''),
        'wordset' => (array) ($job['wordset'] ?? []),
        'current_index' => $current_index,
        'total' => $total,
        'percent_complete' => $total > 0 ? round(($current_index / $total) * 100, 2) : 100.0,
        'uses_categories' => !empty($job['uses_categories']),
        'sync_linked_images' => !empty($job['sync_linked_images']),
        'allow_empty_categories' => !empty($job['allow_empty_categories']),
        'purge_public_static_cache' => !empty($job['purge_public_static_cache']),
        'batch' => [
            'default_process_limit' => ll_tools_rest_automation_batch_limit('word_metadata_plan_jobs', false)['default'],
            'max_process_limit' => ll_tools_rest_automation_batch_limit('word_metadata_plan_jobs', false)['max'],
        ],
        'summary' => [
            'input_count' => (int) ($summary['input_count'] ?? 0),
            'matched_count' => (int) ($summary['matched_count'] ?? 0),
            'processed_count' => (int) ($summary['processed_count'] ?? 0),
            'changed_count' => (int) ($summary['changed_count'] ?? 0),
            'updated_count' => (int) ($summary['updated_count'] ?? 0),
            'unchanged_count' => (int) ($summary['unchanged_count'] ?? 0),
            'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
            'error_count' => (int) ($summary['error_count'] ?? 0),
            'invalidated_category_ids' => array_values(array_map('intval', (array) ($summary['invalidated_category_ids'] ?? []))),
            'invalidated_wordset_cache' => !empty($summary['invalidated_wordset_cache']),
            'public_static_cache_purged' => !empty($summary['public_static_cache_purged']),
        ],
    ];

    if ($include_recent) {
        $payload['recent'] = [
            'updated' => array_slice(array_values((array) ($summary['updated'] ?? [])), -5),
            'skipped' => array_slice(array_values((array) ($summary['skipped'] ?? [])), -5),
            'errors' => array_slice(array_values((array) ($summary['errors'] ?? [])), -5),
        ];
    }

    return $payload;
}

function ll_tools_rest_word_metadata_plan_word_current_pos_slug(int $word_id): string {
    $terms = wp_get_post_terms($word_id, 'part_of_speech', [
        'fields' => 'slugs',
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return '';
    }

    return sanitize_title((string) ((array) $terms)[0]);
}

function ll_tools_rest_word_metadata_plan_current_values(int $word_id, int $wordset_id, array $available_category_ids, array $fields): array {
    $fields = array_values(array_unique(array_map('sanitize_key', $fields)));
    $values = [];
    $post = get_post($word_id);

    foreach ($fields as $field) {
        switch ($field) {
            case 'word_title':
                $values[$field] = $post instanceof WP_Post ? (string) $post->post_title : '';
                break;
            case 'word_text':
                $display_values = function_exists('ll_tools_word_grid_resolve_display_text')
                    ? ll_tools_word_grid_resolve_display_text($word_id)
                    : ['word_text' => $post instanceof WP_Post ? (string) $post->post_title : ''];
                $values[$field] = (string) ($display_values['word_text'] ?? '');
                break;
            case 'word_translation':
                $values[$field] = (string) get_post_meta($word_id, 'word_translation', true);
                break;
            case 'word_english_meaning':
                $values[$field] = (string) get_post_meta($word_id, 'word_english_meaning', true);
                break;
            case 'word_translations':
                $values[$field] = function_exists('ll_tools_get_effective_word_translation_map')
                    ? ll_tools_get_effective_word_translation_map($word_id)
                    : [];
                break;
            case 'word_note':
                $values[$field] = (string) get_post_meta($word_id, 'll_word_usage_note', true);
                break;
            case 'dictionary_entry_id':
                $values[$field] = function_exists('ll_tools_get_word_dictionary_entry_id')
                    ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
                    : 0;
                break;
            case 'dictionary_entry_title':
                $entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
                    ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
                    : 0;
                $values[$field] = $entry_id > 0 ? (string) get_the_title($entry_id) : '';
                break;
            case 'part_of_speech':
                $values[$field] = ll_tools_rest_word_metadata_plan_word_current_pos_slug($word_id);
                break;
            case 'grammatical_gender':
                $values[$field] = (string) get_post_meta($word_id, 'll_grammatical_gender', true);
                break;
            case 'grammatical_plurality':
                $values[$field] = (string) get_post_meta($word_id, 'll_grammatical_plurality', true);
                break;
            case 'verb_tense':
                $values[$field] = (string) get_post_meta($word_id, 'll_verb_tense', true);
                break;
            case 'verb_mood':
                $values[$field] = (string) get_post_meta($word_id, 'll_verb_mood', true);
                break;
            case 'word_category_ids':
                $values[$field] = ll_tools_word_grid_get_selected_category_ids_for_editor($word_id, $wordset_id, $available_category_ids);
                break;
            default:
                if (ll_tools_rest_word_metadata_plan_is_locale_translation_field($field)) {
                    $locale = substr($field, strlen('word_translation_'));
                    $values[$field] = function_exists('ll_tools_get_word_translation_for_locale')
                        ? ll_tools_get_word_translation_for_locale($word_id, $locale, true)
                        : '';
                }
                break;
        }
    }

    return $values;
}

function ll_tools_rest_word_metadata_plan_is_int_list($value): bool {
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $item) {
        if (!is_int($key)) {
            return false;
        }
        if (is_array($item) || (string) (int) $item !== (string) $item) {
            return false;
        }
    }

    return true;
}

function ll_tools_rest_word_metadata_plan_normalize_assoc_for_compare($value) {
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = ll_tools_rest_word_metadata_plan_normalize_assoc_for_compare($item);
        }
        ksort($normalized, SORT_NATURAL);
        return $normalized;
    }

    return is_scalar($value) ? (string) $value : '';
}

function ll_tools_rest_word_metadata_plan_values_equal($left, $right): bool {
    if (is_array($left) || is_array($right)) {
        if (!ll_tools_rest_word_metadata_plan_is_int_list($left) || !ll_tools_rest_word_metadata_plan_is_int_list($right)) {
            return ll_tools_rest_word_metadata_plan_normalize_assoc_for_compare($left)
                === ll_tools_rest_word_metadata_plan_normalize_assoc_for_compare($right);
        }

        $left_values = array_values(array_map('intval', (array) $left));
        $right_values = array_values(array_map('intval', (array) $right));
        sort($left_values, SORT_NUMERIC);
        sort($right_values, SORT_NUMERIC);
        return $left_values === $right_values;
    }

    return (string) $left === (string) $right;
}

function ll_tools_rest_word_metadata_plan_validate_field(int $wordset_id, int $word_id, string $field, $value, string $target_pos) {
    if ($field === 'dictionary_entry_id' || $field === 'dictionary_entry_title') {
        if (!function_exists('ll_tools_assign_dictionary_entry_to_word')) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_dictionary_helpers_missing', __('Dictionary entry helpers are not available.', 'll-tools-text-domain'));
        }
        return true;
    }

    if ($field === 'word_translations' || ll_tools_rest_word_metadata_plan_is_locale_translation_field($field)) {
        return true;
    }

    if ($field === 'part_of_speech') {
        $pos_slug = sanitize_title((string) $value);
        if ($pos_slug === '') {
            return true;
        }
        $term = get_term_by('slug', $pos_slug, 'part_of_speech');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return new WP_Error(
                'll_tools_rest_word_metadata_plan_invalid_part_of_speech',
                sprintf(
                    /* translators: %s: part-of-speech slug */
                    __('Unknown part of speech: %s', 'll-tools-text-domain'),
                    $pos_slug
                )
            );
        }
        return true;
    }

    if ($field === 'grammatical_gender') {
        if (!function_exists('ll_tools_wordset_has_grammatical_gender') || !ll_tools_wordset_has_grammatical_gender($wordset_id)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_gender_disabled', __('Grammatical gender is not enabled for this word set.', 'll-tools-text-domain'));
        }
        if ($target_pos !== 'noun' && (string) $value !== '') {
            return new WP_Error('ll_tools_rest_word_metadata_plan_gender_requires_noun', __('Gender can only be set on noun words.', 'll-tools-text-domain'));
        }
        $allowed = function_exists('ll_tools_wordset_get_gender_options') ? ll_tools_wordset_get_gender_options($wordset_id) : [];
        $normalized = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
            ? ll_tools_wordset_normalize_gender_value_for_options((string) $value, $allowed)
            : (string) $value;
        if ($normalized !== '' && !in_array($normalized, $allowed, true)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_gender_value', __('Invalid gender value.', 'll-tools-text-domain'));
        }
        return true;
    }

    if ($field === 'grammatical_plurality') {
        if (!function_exists('ll_tools_wordset_has_plurality') || !ll_tools_wordset_has_plurality($wordset_id)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_plurality_disabled', __('Plurality is not enabled for this word set.', 'll-tools-text-domain'));
        }
        if ($target_pos !== 'noun' && (string) $value !== '') {
            return new WP_Error('ll_tools_rest_word_metadata_plan_plurality_requires_noun', __('Plurality can only be set on noun words.', 'll-tools-text-domain'));
        }
        $allowed = function_exists('ll_tools_wordset_get_plurality_options') ? ll_tools_wordset_get_plurality_options($wordset_id) : [];
        $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
            ? ll_tools_word_grid_match_option_value_case_insensitive((string) $value, $allowed)
            : (string) $value;
        if ($normalized !== '' && !in_array($normalized, $allowed, true)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_plurality_value', __('Invalid plurality value.', 'll-tools-text-domain'));
        }
        return true;
    }

    if ($field === 'verb_tense') {
        if (!function_exists('ll_tools_wordset_has_verb_tense') || !ll_tools_wordset_has_verb_tense($wordset_id)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_verb_tense_disabled', __('Verb tense is not enabled for this word set.', 'll-tools-text-domain'));
        }
        if ($target_pos !== 'verb' && (string) $value !== '') {
            return new WP_Error('ll_tools_rest_word_metadata_plan_verb_tense_requires_verb', __('Verb tense can only be set on verb words.', 'll-tools-text-domain'));
        }
        $allowed = function_exists('ll_tools_wordset_get_verb_tense_options') ? ll_tools_wordset_get_verb_tense_options($wordset_id) : [];
        $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
            ? ll_tools_word_grid_match_option_value_case_insensitive((string) $value, $allowed)
            : (string) $value;
        if ($normalized !== '' && !in_array($normalized, $allowed, true)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_verb_tense_value', __('Invalid verb tense value.', 'll-tools-text-domain'));
        }
        return true;
    }

    if ($field === 'verb_mood') {
        if (!function_exists('ll_tools_wordset_has_verb_mood') || !ll_tools_wordset_has_verb_mood($wordset_id)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_verb_mood_disabled', __('Verb mood is not enabled for this word set.', 'll-tools-text-domain'));
        }
        if ($target_pos !== 'verb' && (string) $value !== '') {
            return new WP_Error('ll_tools_rest_word_metadata_plan_verb_mood_requires_verb', __('Verb mood can only be set on verb words.', 'll-tools-text-domain'));
        }
        $allowed = function_exists('ll_tools_wordset_get_verb_mood_options') ? ll_tools_wordset_get_verb_mood_options($wordset_id) : [];
        $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
            ? ll_tools_word_grid_match_option_value_case_insensitive((string) $value, $allowed)
            : (string) $value;
        if ($normalized !== '' && !in_array($normalized, $allowed, true)) {
            return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_verb_mood_value', __('Invalid verb mood value.', 'll-tools-text-domain'));
        }
        return true;
    }

    return true;
}

function ll_tools_rest_word_metadata_plan_match_option_value(string $value, array $allowed): string {
    return function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
        ? ll_tools_word_grid_match_option_value_case_insensitive($value, $allowed)
        : $value;
}

function ll_tools_rest_word_metadata_plan_apply_field(int $wordset_id, int $word_id, string $field, $value) {
    switch ($field) {
        case 'word_title':
            return function_exists('ll_tools_cli_update_word_title')
                ? ll_tools_cli_update_word_title($word_id, (string) $value)
                : wp_update_post(['ID' => $word_id, 'post_title' => (string) $value], true);

        case 'word_text':
            return function_exists('ll_tools_cli_update_word_text')
                ? ll_tools_cli_update_word_text($word_id, (string) $value)
                : wp_update_post(['ID' => $word_id, 'post_title' => (string) $value], true);

        case 'word_translation':
            if (function_exists('ll_tools_cli_update_word_translation')) {
                ll_tools_cli_update_word_translation($word_id, (string) $value);
            } elseif ((string) $value === '') {
                delete_post_meta($word_id, 'word_translation');
            } else {
                update_post_meta($word_id, 'word_translation', (string) $value);
            }
            return true;

        case 'word_english_meaning':
            if ((string) $value === '') {
                delete_post_meta($word_id, 'word_english_meaning');
            } else {
                update_post_meta($word_id, 'word_english_meaning', sanitize_text_field((string) $value));
            }
            return true;

        case 'word_translations':
            return function_exists('ll_tools_update_word_translation_map')
                ? ll_tools_update_word_translation_map($word_id, (array) $value)
                : true;

        case 'word_note':
            if ((string) $value === '') {
                delete_post_meta($word_id, 'll_word_usage_note');
            } else {
                update_post_meta($word_id, 'll_word_usage_note', sanitize_textarea_field((string) $value));
            }
            return true;

        case 'dictionary_entry_id':
            return ll_tools_assign_dictionary_entry_to_word($word_id, max(0, (int) $value), '');

        case 'dictionary_entry_title':
            return ll_tools_assign_dictionary_entry_to_word($word_id, 0, (string) $value);

        case 'part_of_speech':
            $pos_slug = sanitize_title((string) $value);
            if ($pos_slug === '') {
                wp_set_object_terms($word_id, [], 'part_of_speech', false);
                delete_post_meta($word_id, 'll_grammatical_gender');
                delete_post_meta($word_id, 'll_grammatical_plurality');
                delete_post_meta($word_id, 'll_verb_tense');
                delete_post_meta($word_id, 'll_verb_mood');
                return true;
            }
            $term = get_term_by('slug', $pos_slug, 'part_of_speech');
            if (!($term instanceof WP_Term) || is_wp_error($term)) {
                return new WP_Error('ll_tools_rest_word_metadata_plan_invalid_part_of_speech', __('Unknown part of speech.', 'll-tools-text-domain'));
            }
            wp_set_object_terms($word_id, [(int) $term->term_id], 'part_of_speech', false);
            if ($pos_slug !== 'noun') {
                delete_post_meta($word_id, 'll_grammatical_gender');
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }
            if ($pos_slug !== 'verb') {
                delete_post_meta($word_id, 'll_verb_tense');
                delete_post_meta($word_id, 'll_verb_mood');
            }
            return true;

        case 'grammatical_gender':
            $allowed_gender = function_exists('ll_tools_wordset_get_gender_options') ? ll_tools_wordset_get_gender_options($wordset_id) : [];
            $gender_value = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                ? ll_tools_wordset_normalize_gender_value_for_options((string) $value, $allowed_gender)
                : (string) $value;
            if ($gender_value === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
            } else {
                update_post_meta($word_id, 'll_grammatical_gender', $gender_value);
            }
            return true;

        case 'grammatical_plurality':
            $plurality_allowed = function_exists('ll_tools_wordset_get_plurality_options') ? ll_tools_wordset_get_plurality_options($wordset_id) : [];
            $plurality_value = ll_tools_rest_word_metadata_plan_match_option_value((string) $value, $plurality_allowed);
            if ($plurality_value === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            } else {
                update_post_meta($word_id, 'll_grammatical_plurality', $plurality_value);
            }
            return true;

        case 'verb_tense':
            $tense_allowed = function_exists('ll_tools_wordset_get_verb_tense_options') ? ll_tools_wordset_get_verb_tense_options($wordset_id) : [];
            $tense_value = ll_tools_rest_word_metadata_plan_match_option_value((string) $value, $tense_allowed);
            if ($tense_value === '') {
                delete_post_meta($word_id, 'll_verb_tense');
            } else {
                update_post_meta($word_id, 'll_verb_tense', $tense_value);
            }
            return true;

        case 'verb_mood':
            $mood_allowed = function_exists('ll_tools_wordset_get_verb_mood_options') ? ll_tools_wordset_get_verb_mood_options($wordset_id) : [];
            $mood_value = ll_tools_rest_word_metadata_plan_match_option_value((string) $value, $mood_allowed);
            if ($mood_value === '') {
                delete_post_meta($word_id, 'll_verb_mood');
            } else {
                update_post_meta($word_id, 'll_verb_mood', $mood_value);
            }
            return true;
    }

    if (ll_tools_rest_word_metadata_plan_is_locale_translation_field($field)) {
        $locale = substr($field, strlen('word_translation_'));
        return function_exists('ll_tools_update_word_translation_for_locale')
            ? ll_tools_update_word_translation_for_locale($word_id, $locale, (string) $value)
            : true;
    }

    return new WP_Error('ll_tools_rest_word_metadata_plan_unsupported_field', __('Unsupported word metadata field.', 'll-tools-text-domain'));
}

function ll_tools_rest_word_metadata_plan_apply_plan(array $plan, WP_Term $wordset_term, array $available_category_ids, array $job): array {
    $wordset_id = (int) $wordset_term->term_id;
    $word_id = (int) ($plan['word_id'] ?? 0);
    $set_values = (array) ($plan['set'] ?? []);
    $expected_values = (array) ($plan['expected'] ?? []);
    $all_fields = array_values(array_unique(array_merge(array_keys($set_values), array_keys($expected_values))));
    if (array_intersect($all_fields, ['grammatical_gender', 'grammatical_plurality', 'verb_tense', 'verb_mood'])) {
        $all_fields[] = 'part_of_speech';
    }
    $all_fields = array_values(array_unique($all_fields));

    $post = get_post($word_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return [
            'status' => 'skipped',
            'row' => [
                'index' => (int) ($plan['index'] ?? 0),
                'word_id' => $word_id,
                'reason' => 'missing_word',
            ],
        ];
    }

    $wordset_ids_raw = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    $wordset_ids = is_wp_error($wordset_ids_raw) ? [] : ll_tools_rest_automation_prepare_id_list((array) $wordset_ids_raw);
    if (!in_array($wordset_id, $wordset_ids, true)) {
        return [
            'status' => 'skipped',
            'row' => [
                'index' => (int) ($plan['index'] ?? 0),
                'word_id' => $word_id,
                'reason' => 'not_in_wordset',
            ],
        ];
    }

    $before = ll_tools_rest_word_metadata_plan_current_values($word_id, $wordset_id, $available_category_ids, $all_fields);
    foreach ($expected_values as $field => $expected_value) {
        $current_value = $before[$field] ?? ($field === 'word_category_ids' ? [] : '');
        if (!ll_tools_rest_word_metadata_plan_values_equal($current_value, $expected_value)) {
            return [
                'status' => 'skipped',
                'row' => [
                    'index' => (int) ($plan['index'] ?? 0),
                    'word_id' => $word_id,
                    'word_slug' => (string) $post->post_name,
                    'word_title' => (string) $post->post_title,
                    'reason' => 'expected_mismatch',
                    'field' => (string) $field,
                    'current_value' => $current_value,
                    'expected_value' => $expected_value,
                ],
            ];
        }
    }

    if (array_key_exists('word_category_ids', $set_values) && empty($set_values['word_category_ids']) && empty($job['allow_empty_categories'])) {
        return [
            'status' => 'error',
            'row' => [
                'index' => (int) ($plan['index'] ?? 0),
                'word_id' => $word_id,
                'message' => __('Refusing to remove the final category for this word.', 'll-tools-text-domain'),
            ],
        ];
    }

    $after = $before;
    foreach ($set_values as $field => $value) {
        $after[$field] = $value;
    }

    $changed_keys = [];
    foreach ($set_values as $field => $value) {
        $current_value = $before[$field] ?? ($field === 'word_category_ids' ? [] : '');
        if (!ll_tools_rest_word_metadata_plan_values_equal($current_value, $value)) {
            $changed_keys[] = (string) $field;
        }
    }

    if (empty($changed_keys)) {
        return [
            'status' => 'unchanged',
            'row' => [
                'index' => (int) ($plan['index'] ?? 0),
                'word_id' => $word_id,
                'word_slug' => (string) $post->post_name,
                'word_title' => (string) $post->post_title,
                'changed' => false,
                'before' => $before,
                'after' => $after,
            ],
        ];
    }

    $target_pos = array_key_exists('part_of_speech', $set_values)
        ? sanitize_title((string) $set_values['part_of_speech'])
        : sanitize_title((string) ($before['part_of_speech'] ?? ''));
    foreach ($changed_keys as $field) {
        if ($field === 'word_category_ids') {
            continue;
        }
        $validation = ll_tools_rest_word_metadata_plan_validate_field($wordset_id, $word_id, $field, $set_values[$field] ?? '', $target_pos);
        if (is_wp_error($validation)) {
            return [
                'status' => 'error',
                'row' => [
                    'index' => (int) ($plan['index'] ?? 0),
                    'word_id' => $word_id,
                    'word_slug' => (string) $post->post_name,
                    'word_title' => (string) $post->post_title,
                    'field' => (string) $field,
                    'message' => $validation->get_error_message(),
                ],
            ];
        }
    }

    $field_order = [
        'word_title',
        'word_text',
        'word_translation',
        'word_english_meaning',
        'word_translations',
        'word_note',
        'dictionary_entry_id',
        'dictionary_entry_title',
        'part_of_speech',
        'grammatical_gender',
        'grammatical_plurality',
        'verb_tense',
        'verb_mood',
        'word_category_ids',
    ];
    $changed_lookup = array_fill_keys($changed_keys, true);
    $applied_fields = [];
    foreach ($field_order as $field) {
        if (empty($changed_lookup[$field])) {
            continue;
        }
        $applied_fields[$field] = true;
        if ($field === 'word_category_ids') {
            $category_result = ll_tools_word_grid_update_word_categories_for_wordset($word_id, $wordset_id, (array) $set_values[$field], $available_category_ids);
            if (is_wp_error($category_result)) {
                return [
                    'status' => 'error',
                    'row' => [
                        'index' => (int) ($plan['index'] ?? 0),
                        'word_id' => $word_id,
                        'field' => $field,
                        'message' => $category_result->get_error_message(),
                    ],
                ];
            }
            if (!empty($category_result['changed']) && !empty($job['sync_linked_images'])) {
                $sync_result = ll_tools_wordset_editor_sync_linked_word_image_categories($word_id, $wordset_id, (array) $set_values[$field], $available_category_ids);
                if (is_wp_error($sync_result)) {
                    return [
                        'status' => 'error',
                        'row' => [
                            'index' => (int) ($plan['index'] ?? 0),
                            'word_id' => $word_id,
                            'field' => $field,
                            'message' => $sync_result->get_error_message(),
                        ],
                    ];
                }
            }
            continue;
        }

        $field_result = ll_tools_rest_word_metadata_plan_apply_field($wordset_id, $word_id, $field, $set_values[$field] ?? '');
        if (is_wp_error($field_result)) {
            return [
                'status' => 'error',
                'row' => [
                    'index' => (int) ($plan['index'] ?? 0),
                    'word_id' => $word_id,
                    'field' => $field,
                    'message' => $field_result->get_error_message(),
                ],
            ];
        }
    }
    foreach ($changed_keys as $field) {
        if (!empty($applied_fields[$field])) {
            continue;
        }

        $field_result = ll_tools_rest_word_metadata_plan_apply_field($wordset_id, $word_id, $field, $set_values[$field] ?? '');
        if (is_wp_error($field_result)) {
            return [
                'status' => 'error',
                'row' => [
                    'index' => (int) ($plan['index'] ?? 0),
                    'word_id' => $word_id,
                    'field' => $field,
                    'message' => $field_result->get_error_message(),
                ],
            ];
        }
    }

    clean_post_cache($word_id);
    $verified_after = ll_tools_rest_word_metadata_plan_current_values($word_id, $wordset_id, $available_category_ids, array_keys($after));

    return [
        'status' => 'updated',
        'row' => [
            'index' => (int) ($plan['index'] ?? 0),
            'word_id' => $word_id,
            'word_slug' => (string) get_post_field('post_name', $word_id),
            'word_title' => (string) get_the_title($word_id),
            'changed' => true,
            'changed_keys' => array_values($changed_keys),
            'before' => $before,
            'after' => $verified_after,
        ],
        'changed_word_ids' => [$word_id],
        'changed_category_ids' => in_array('word_category_ids', $changed_keys, true)
            ? array_values(array_unique(array_merge((array) ($before['word_category_ids'] ?? []), (array) ($set_values['word_category_ids'] ?? []))))
            : [],
    ];
}

function ll_tools_rest_word_metadata_plan_invalidate_after_chunk(int $wordset_id, array $changed_word_ids, array $changed_category_ids, bool $purge_public_static_cache): array {
    $changed_word_ids = ll_tools_rest_automation_prepare_id_list($changed_word_ids);
    $changed_category_ids = ll_tools_rest_automation_prepare_id_list($changed_category_ids);
    foreach ($changed_word_ids as $changed_word_id) {
        clean_post_cache($changed_word_id);
    }
    if (!empty($changed_word_ids) && function_exists('ll_tools_word_grid_bump_category_cache_for_words')) {
        ll_tools_word_grid_bump_category_cache_for_words($changed_word_ids);
    }
    if (!empty($changed_category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($changed_category_ids);
    }
    if ((!empty($changed_word_ids) || !empty($changed_category_ids)) && function_exists('ll_tools_bump_wordset_cache_epoch')) {
        ll_tools_bump_wordset_cache_epoch([$wordset_id]);
    }
    if ((!empty($changed_word_ids) || !empty($changed_category_ids)) && function_exists('ll_tools_wordset_editor_invalidate_wordset')) {
        ll_tools_wordset_editor_invalidate_wordset($wordset_id);
    }
    if ($purge_public_static_cache && (!empty($changed_word_ids) || !empty($changed_category_ids)) && function_exists('ll_tools_purge_public_static_cache_once')) {
        ll_tools_purge_public_static_cache_once(['wordset_ids' => [$wordset_id]]);
    }

    return [
        'changed_word_ids' => $changed_word_ids,
        'changed_category_ids' => $changed_category_ids,
        'invalidated_wordset_cache' => !empty($changed_word_ids) || !empty($changed_category_ids),
        'public_static_cache_purged' => $purge_public_static_cache && (!empty($changed_word_ids) || !empty($changed_category_ids)),
    ];
}

function ll_tools_rest_automation_create_word_metadata_plan_job(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $raw_updates = $request->get_param('updates');
    if (!is_array($raw_updates)) {
        $raw_updates = $request->get_param('plans');
    }
    $raw_updates = is_array($raw_updates) ? array_values($raw_updates) : [];
    $max_items = ll_tools_rest_word_metadata_plan_max_items();
    $summary = ll_tools_rest_word_metadata_plan_job_default_summary(count($raw_updates));
    $wordset_id = (int) $wordset_term->term_id;
    $uses_categories = ll_tools_rest_word_metadata_plan_has_category_fields($raw_updates);
    $allow_empty_categories = $request->has_param('allow_empty_categories')
        ? rest_sanitize_boolean($request->get_param('allow_empty_categories'))
        : false;
    $sync_linked_images = $request->has_param('sync_linked_images')
        ? rest_sanitize_boolean($request->get_param('sync_linked_images'))
        : true;
    $purge_public_static_cache = $request->has_param('purge_public_static_cache')
        ? rest_sanitize_boolean($request->get_param('purge_public_static_cache'))
        : false;

    if (empty($raw_updates)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_metadata_plan_missing_updates',
            __('Provide one or more word metadata updates.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }
    if (count($raw_updates) > $max_items) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_metadata_plan_too_many_updates',
            sprintf(
                /* translators: %d: maximum update count */
                __('Too many word metadata updates in one plan. Maximum is %d.', 'll-tools-text-domain'),
                $max_items
            ),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    $available_category_ids = [];
    if ($uses_categories) {
        $helpers_loaded = ll_tools_rest_automation_load_word_category_update_helpers();
        if (is_wp_error($helpers_loaded)) {
            return ll_tools_rest_automation_with_status($helpers_loaded, 500);
        }
        $available_category_ids = ll_tools_rest_automation_prepare_id_list(wp_list_pluck(ll_tools_word_grid_get_category_editor_rows($wordset_id), 'id'));
    }

    $errors = [];
    $plans = [];
    $word_ids = [];
    $seen_word_ids = [];
    foreach ($raw_updates as $index => $raw_update) {
        $update = is_array($raw_update) ? $raw_update : [];
        $raw_word_id = $update['word_id'] ?? ($update['id'] ?? '');
        $word_id = is_scalar($raw_word_id) ? (int) $raw_word_id : 0;
        if ($word_id <= 0) {
            $errors[] = [
                'index' => $index,
                'message' => __('Missing word ID.', 'll-tools-text-domain'),
            ];
            continue;
        }
        if (isset($seen_word_ids[$word_id])) {
            $errors[] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Duplicate word ID in this plan. Submit one final-state row per word.', 'll-tools-text-domain'),
            ];
            continue;
        }
        $seen_word_ids[$word_id] = true;

        $maps = ll_tools_rest_word_metadata_plan_extract_update_maps($update, $wordset_id, $available_category_ids, $errors, $index);
        if (empty($maps['set'])) {
            $errors[] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Missing metadata values to set.', 'll-tools-text-domain'),
            ];
            continue;
        }
        if (!$allow_empty_categories && array_key_exists('word_category_ids', $maps['set']) && empty($maps['set']['word_category_ids'])) {
            $errors[] = [
                'index' => $index,
                'word_id' => $word_id,
                'message' => __('Refusing to set an empty category list without allow_empty_categories=true.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $plans[] = [
            'index' => $index,
            'word_id' => $word_id,
            'set' => (array) $maps['set'],
            'expected' => (array) $maps['expected'],
            'note' => isset($update['note']) ? sanitize_text_field((string) $update['note']) : '',
        ];
        $word_ids[] = $word_id;
    }

    if (!empty($word_ids)) {
        $records = ll_tools_rest_automation_fetch_word_title_records($wordset_id, $word_ids);
        foreach ($plans as $plan_index => $plan) {
            $word_id = (int) ($plan['word_id'] ?? 0);
            if (!isset($records[$word_id])) {
                $errors[] = [
                    'index' => (int) ($plan['index'] ?? $plan_index),
                    'word_id' => $word_id,
                    'message' => __('Word is not assigned to this word set.', 'll-tools-text-domain'),
                ];
                unset($plans[$plan_index]);
                continue;
            }
            $plans[$plan_index]['word_slug'] = (string) ($records[$word_id]['word_slug'] ?? '');
            $plans[$plan_index]['word_title'] = (string) ($records[$word_id]['word_title'] ?? '');
        }
    }

    $plans = array_values($plans);
    $summary['matched_count'] = count($plans);
    $summary['errors'] = $errors;
    $summary['error_count'] = count($errors);
    if (!empty($errors)) {
        return ll_tools_rest_automation_with_status(new WP_Error(
            'll_tools_rest_word_metadata_plan_preflight_failed',
            __('Word metadata plan preflight failed. No job was created.', 'll-tools-text-domain'),
            ['status' => 400, 'summary' => $summary]
        ), 400);
    }

    $job_id = wp_generate_uuid4();
    $now = gmdate('c');
    $summary['matched_count'] = count($plans);
    $job = [
        'id' => $job_id,
        'status' => 'running',
        'created_at_gmt' => $now,
        'updated_at_gmt' => $now,
        'completed_at_gmt' => '',
        'user_id' => get_current_user_id(),
        'wordset' => [
            'id' => $wordset_id,
            'slug' => (string) $wordset_term->slug,
            'name' => (string) $wordset_term->name,
        ],
        'total' => count($plans),
        'current_index' => 0,
        'uses_categories' => $uses_categories,
        'sync_linked_images' => $sync_linked_images,
        'allow_empty_categories' => $allow_empty_categories,
        'purge_public_static_cache' => $purge_public_static_cache,
        'plans' => $plans,
        'summary' => $summary,
    ];
    ll_tools_rest_word_metadata_plan_job_save($job);

    $response = rest_ensure_response([
        'job' => ll_tools_rest_word_metadata_plan_job_summary($job, false),
        'plans' => $plans,
    ]);
    $response->set_status(201);

    return $response;
}

function ll_tools_rest_automation_get_word_metadata_plan_job(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_word_metadata_plan_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset']['id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_job_wordset_mismatch', __('Word metadata plan job belongs to a different word set.', 'll-tools-text-domain'), ['status' => 404]);
    }

    return rest_ensure_response(['job' => ll_tools_rest_word_metadata_plan_job_summary($job)]);
}

function ll_tools_rest_automation_process_word_metadata_plan_job(WP_REST_Request $request) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_word_metadata_plan_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset']['id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_job_wordset_mismatch', __('Word metadata plan job belongs to a different word set.', 'll-tools-text-domain'), ['status' => 404]);
    }
    if (in_array((string) ($job['status'] ?? ''), ['completed', 'discarded', 'failed'], true)) {
        return rest_ensure_response([
            'job' => ll_tools_rest_word_metadata_plan_job_summary($job),
            'processed' => [],
        ]);
    }

    $limit_info = ll_tools_rest_automation_resolve_batch_limit($request, 'word_metadata_plan_jobs', false);
    $limit = max(1, (int) $limit_info['effective']);
    $plans = array_values((array) ($job['plans'] ?? []));
    $total = count($plans);
    $current_index = max(0, (int) ($job['current_index'] ?? 0));
    $chunk = array_slice($plans, $current_index, $limit);
    $available_category_ids = [];
    if (!empty($job['uses_categories'])) {
        $helpers_loaded = ll_tools_rest_automation_load_word_category_update_helpers();
        if (is_wp_error($helpers_loaded)) {
            return ll_tools_rest_automation_with_status($helpers_loaded, 500);
        }
        $available_category_ids = ll_tools_rest_automation_prepare_id_list(wp_list_pluck(ll_tools_word_grid_get_category_editor_rows((int) $wordset_term->term_id), 'id'));
    }

    $processed = [];
    $changed_word_ids = [];
    $changed_category_ids = [];
    $summary = (array) ($job['summary'] ?? ll_tools_rest_word_metadata_plan_job_default_summary($total));
    foreach ($chunk as $plan) {
        $result = ll_tools_rest_word_metadata_plan_apply_plan((array) $plan, $wordset_term, $available_category_ids, $job);
        $status = (string) ($result['status'] ?? '');
        $row = (array) ($result['row'] ?? []);
        $processed[] = ['status' => $status] + $row;
        $summary['processed_count'] = (int) ($summary['processed_count'] ?? 0) + 1;
        $current_index++;

        if ($status === 'updated') {
            $summary['changed_count'] = (int) ($summary['changed_count'] ?? 0) + 1;
            $summary['updated_count'] = (int) ($summary['updated_count'] ?? 0) + 1;
            $summary['updated'][] = $row;
            $changed_word_ids = array_merge($changed_word_ids, (array) ($result['changed_word_ids'] ?? []));
            $changed_category_ids = array_merge($changed_category_ids, (array) ($result['changed_category_ids'] ?? []));
        } elseif ($status === 'unchanged') {
            $summary['unchanged_count'] = (int) ($summary['unchanged_count'] ?? 0) + 1;
        } elseif ($status === 'skipped') {
            $summary['skipped_count'] = (int) ($summary['skipped_count'] ?? 0) + 1;
            $summary['skipped'][] = $row;
        } else {
            $summary['error_count'] = (int) ($summary['error_count'] ?? 0) + 1;
            $summary['errors'][] = $row;
        }
    }

    $invalidation = ll_tools_rest_word_metadata_plan_invalidate_after_chunk(
        (int) $wordset_term->term_id,
        $changed_word_ids,
        $changed_category_ids,
        !empty($job['purge_public_static_cache'])
    );
    if (!empty($invalidation['changed_category_ids'])) {
        $summary['invalidated_category_ids'] = ll_tools_rest_automation_prepare_id_list(array_merge(
            (array) ($summary['invalidated_category_ids'] ?? []),
            (array) $invalidation['changed_category_ids']
        ));
    }
    if (!empty($invalidation['invalidated_wordset_cache'])) {
        $summary['invalidated_wordset_cache'] = true;
    }
    if (!empty($invalidation['public_static_cache_purged'])) {
        $summary['public_static_cache_purged'] = true;
    }

    $job['current_index'] = $current_index;
    $job['summary'] = $summary;
    $job['updated_at_gmt'] = gmdate('c');
    if ($current_index >= $total) {
        $job['status'] = 'completed';
        $job['completed_at_gmt'] = gmdate('c');
    }
    ll_tools_rest_word_metadata_plan_job_save($job);

    return rest_ensure_response([
        'job' => ll_tools_rest_word_metadata_plan_job_summary($job),
        'batch' => [
            'requested_limit' => (int) $limit_info['requested'],
            'effective_limit' => $limit,
            'max_limit' => (int) $limit_info['max'],
            'limit_clamped' => (bool) $limit_info['clamped'],
            'has_more' => $current_index < $total,
        ],
        'processed' => $processed,
    ]);
}

function ll_tools_rest_automation_discard_word_metadata_plan_job(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_word_metadata_plan_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset']['id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_job_wordset_mismatch', __('Word metadata plan job belongs to a different word set.', 'll-tools-text-domain'), ['status' => 404]);
    }

    $job['status'] = 'discarded';
    $job['updated_at_gmt'] = gmdate('c');
    ll_tools_rest_word_metadata_plan_job_save($job);

    return rest_ensure_response(['job' => ll_tools_rest_word_metadata_plan_job_summary($job)]);
}

function ll_tools_rest_automation_word_metadata_plan_job_result(WP_REST_Request $request) {
    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $job = ll_tools_rest_word_metadata_plan_job_get((string) $request->get_param('job_id'));
    if (is_wp_error($job)) {
        return $job;
    }
    if ((int) ($job['wordset']['id'] ?? 0) !== (int) $wordset_term->term_id) {
        return new WP_Error('ll_tools_rest_word_metadata_plan_job_wordset_mismatch', __('Word metadata plan job belongs to a different word set.', 'll-tools-text-domain'), ['status' => 404]);
    }

    return rest_ensure_response([
        'job' => ll_tools_rest_word_metadata_plan_job_summary($job, false),
        'summary' => (array) ($job['summary'] ?? []),
    ]);
}
