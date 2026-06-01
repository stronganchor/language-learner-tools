<?php
if (!defined('WPINC')) {
    die;
}

function ll_tools_cli_json_encode($value): string {
    $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

function ll_tools_cli_write_json_file(string $path, array $payload) {
    $path = trim($path);
    if ($path === '') {
        return true;
    }

    $json = ll_tools_cli_json_encode($payload);
    if ($json === '') {
        return new WP_Error('ll_tools_cli_json_encode_failed', __('Unable to encode the CLI output as JSON.', 'll-tools-text-domain'));
    }

    $directory = dirname($path);
    if ($directory !== '' && $directory !== '.' && !is_dir($directory) && !wp_mkdir_p($directory)) {
        return new WP_Error(
            'll_tools_cli_directory_create_failed',
            sprintf(
                /* translators: %s: directory path */
                __('Unable to create directory: %s', 'll-tools-text-domain'),
                $directory
            )
        );
    }

    $bytes = @file_put_contents($path, $json . PHP_EOL);
    if ($bytes === false) {
        return new WP_Error(
            'll_tools_cli_file_write_failed',
            sprintf(
                /* translators: %s: file path */
                __('Unable to write file: %s', 'll-tools-text-domain'),
                $path
            )
        );
    }

    return true;
}

function ll_tools_cli_supported_missing_fields(): array {
    return [
        'word_text',
        'word_translation',
        'word_note',
        'dictionary_entry',
        'part_of_speech',
        'grammatical_gender',
        'grammatical_plurality',
        'verb_tense',
        'verb_mood',
    ];
}

function ll_tools_cli_supported_update_fields(): array {
    return [
        'post_title',
        'word_title',
        'word_text',
        'word_translation',
        'word_note',
        'dictionary_entry_title',
        'part_of_speech',
        'grammatical_gender',
        'grammatical_plurality',
        'verb_tense',
        'verb_mood',
    ];
}

function ll_tools_cli_normalize_field_list(string $raw_fields, array $allowed_fields) {
    $allowed_lookup = array_fill_keys($allowed_fields, true);
    $fields = preg_split('/\s*,\s*/', trim($raw_fields));
    $fields = is_array($fields) ? $fields : [];
    $normalized = [];

    foreach ($fields as $field) {
        $field = sanitize_key((string) $field);
        if ($field === '') {
            continue;
        }
        if (!isset($allowed_lookup[$field])) {
            return new WP_Error(
                'll_tools_cli_invalid_field',
                sprintf(
                    /* translators: 1: field name, 2: comma-separated allowed field list */
                    __('Unsupported field "%1$s". Allowed fields: %2$s', 'll-tools-text-domain'),
                    $field,
                    implode(', ', $allowed_fields)
                )
            );
        }
        $normalized[$field] = true;
    }

    return array_values(array_keys($normalized));
}

function ll_tools_cli_parse_set_argument(string $raw_set) {
    $raw_set = trim($raw_set);
    if ($raw_set === '' || strpos($raw_set, '=') === false) {
        return new WP_Error(
            'll_tools_cli_invalid_set_argument',
            __('Use --set=<field>=<value>. Example: --set=grammatical_gender=feminine', 'll-tools-text-domain')
        );
    }

    [$raw_field, $raw_value] = explode('=', $raw_set, 2);
    $field = sanitize_key(trim((string) $raw_field));
    if ($field === '') {
        return new WP_Error('ll_tools_cli_missing_set_field', __('Missing field name in --set argument.', 'll-tools-text-domain'));
    }

    if (!in_array($field, ll_tools_cli_supported_update_fields(), true)) {
        return new WP_Error(
            'll_tools_cli_invalid_set_field',
            sprintf(
                /* translators: 1: field name, 2: comma-separated field list */
                __('Unsupported update field "%1$s". Allowed fields: %2$s', 'll-tools-text-domain'),
                $field,
                implode(', ', ll_tools_cli_supported_update_fields())
            )
        );
    }

    return [
        'field' => $field,
        'value' => trim((string) $raw_value),
    ];
}

function ll_tools_cli_resolve_wordset_term($wordset_spec) {
    if ($wordset_spec instanceof WP_Term && $wordset_spec->taxonomy === 'wordset') {
        return $wordset_spec;
    }

    $raw_spec = is_scalar($wordset_spec) ? trim((string) $wordset_spec) : '';
    if ($raw_spec === '') {
        return new WP_Error('ll_tools_cli_missing_wordset', __('Missing word set identifier.', 'll-tools-text-domain'));
    }

    $candidate_ids = [];
    if (ctype_digit($raw_spec)) {
        $candidate_ids[] = (int) $raw_spec;
    }

    if (function_exists('ll_flashcards_resolve_wordset_ids')) {
        $resolved = ll_flashcards_resolve_wordset_ids($raw_spec, true);
        foreach ((array) $resolved as $resolved_id) {
            $resolved_id = (int) $resolved_id;
            if ($resolved_id > 0) {
                $candidate_ids[] = $resolved_id;
            }
        }
    }

    if (!empty($candidate_ids)) {
        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids), static function (int $term_id): bool {
            return $term_id > 0;
        })));

        foreach ($candidate_ids as $candidate_id) {
            $term = get_term($candidate_id, 'wordset');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }
    }

    $slug_term = get_term_by('slug', sanitize_title($raw_spec), 'wordset');
    if ($slug_term instanceof WP_Term && !is_wp_error($slug_term)) {
        return $slug_term;
    }

    $name_matches = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'name' => $raw_spec,
        'number' => 2,
    ]);
    if (!is_wp_error($name_matches) && count((array) $name_matches) === 1 && $name_matches[0] instanceof WP_Term) {
        return $name_matches[0];
    }

    return new WP_Error(
        'll_tools_cli_wordset_not_found',
        sprintf(
            /* translators: %s: wordset identifier */
            __('Unable to resolve word set: %s', 'll-tools-text-domain'),
            $raw_spec
        )
    );
}

function ll_tools_cli_resolve_user_id($user_spec) {
    $raw_spec = is_scalar($user_spec) ? trim((string) $user_spec) : '';
    if ($raw_spec === '') {
        return new WP_Error('ll_tools_cli_missing_user', __('Missing user identifier.', 'll-tools-text-domain'));
    }

    if (ctype_digit($raw_spec)) {
        $user = get_userdata((int) $raw_spec);
        if ($user instanceof WP_User) {
            return (int) $user->ID;
        }
    }

    $user = get_user_by('login', $raw_spec);
    if (!($user instanceof WP_User)) {
        $user = get_user_by('email', $raw_spec);
    }
    if ($user instanceof WP_User) {
        return (int) $user->ID;
    }

    return new WP_Error(
        'll_tools_cli_user_not_found',
        sprintf(
            /* translators: %s: user identifier */
            __('Unable to resolve user: %s', 'll-tools-text-domain'),
            $raw_spec
        )
    );
}

function ll_tools_cli_assign_wordset_manager(int $wordset_id, int $user_id): void {
    $wordset_id = (int) $wordset_id;
    $user_id = (int) $user_id;
    if ($wordset_id <= 0 || $user_id <= 0) {
        return;
    }

    if (function_exists('ll_tools_set_wordset_manager_user_ids') && function_exists('ll_tools_get_wordset_manager_user_ids')) {
        $manager_ids = ll_tools_get_wordset_manager_user_ids($wordset_id, true);
        $manager_ids[] = $user_id;
        ll_tools_set_wordset_manager_user_ids($wordset_id, $manager_ids, $user_id);
        return;
    }

    update_term_meta($wordset_id, 'manager_user_id', $user_id);
    update_user_meta($user_id, 'managed_wordsets', [$wordset_id]);
}

function ll_tools_cli_resolve_category_slug(int $wordset_id, string $category_spec) {
    $category_spec = trim($category_spec);
    if ($category_spec === '') {
        return '';
    }

    if (sanitize_title($category_spec) === 'uncategorized') {
        return 'uncategorized';
    }

    $term = function_exists('ll_tools_resolve_word_category_term_for_wordsets')
        ? ll_tools_resolve_word_category_term_for_wordsets($category_spec, $wordset_id > 0 ? [$wordset_id] : [])
        : get_term_by('slug', sanitize_title($category_spec), 'word-category');

    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        $name_matches = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'name' => $category_spec,
            'number' => 2,
        ]);
        if (!is_wp_error($name_matches) && count((array) $name_matches) === 1 && $name_matches[0] instanceof WP_Term) {
            $term = $name_matches[0];
        }
    }

    if ($term instanceof WP_Term && !is_wp_error($term)) {
        return (string) $term->slug;
    }

    return new WP_Error(
        'll_tools_cli_category_not_found',
        sprintf(
            /* translators: %s: category identifier */
            __('Unable to resolve category: %s', 'll-tools-text-domain'),
            $category_spec
        )
    );
}

function ll_tools_cli_resolve_word_id(int $wordset_id, string $word_spec) {
    $word_spec = trim($word_spec);
    if ($word_spec === '') {
        return new WP_Error('ll_tools_cli_missing_word', __('Missing word identifier.', 'll-tools-text-domain'));
    }

    if (ctype_digit($word_spec)) {
        $word_id = (int) $word_spec;
        if ($word_id > 0 && get_post_type($word_id) === 'words' && has_term($wordset_id, 'wordset', $word_id)) {
            return $word_id;
        }
    }

    $slug = sanitize_title($word_spec);
    if ($slug !== '') {
        $query = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'name' => $slug,
            'posts_per_page' => 2,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
            ],
        ]);
        if (count((array) $query) === 1) {
            return (int) $query[0];
        }
    }

    if (function_exists('ll_tools_find_existing_word_post_by_title_in_wordsets')) {
        $match = ll_tools_find_existing_word_post_by_title_in_wordsets($word_spec, [$wordset_id]);
        if ($match instanceof WP_Post && $match->post_type === 'words') {
            return (int) $match->ID;
        }
    }

    return new WP_Error(
        'll_tools_cli_word_not_found',
        sprintf(
            /* translators: %s: word identifier */
            __('Unable to resolve word in this word set: %s', 'll-tools-text-domain'),
            $word_spec
        )
    );
}

function ll_tools_cli_get_word_ids_for_scope(int $wordset_id, string $category_spec = '', string $word_spec = '') {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return new WP_Error('ll_tools_cli_missing_wordset_id', __('Missing word set ID.', 'll-tools-text-domain'));
    }

    $word_spec = trim($word_spec);
    if ($word_spec !== '') {
        $word_id = ll_tools_cli_resolve_word_id($wordset_id, $word_spec);
        if (is_wp_error($word_id)) {
            return $word_id;
        }
        return [(int) $word_id];
    }

    $category_slug = ll_tools_cli_resolve_category_slug($wordset_id, $category_spec);
    if (is_wp_error($category_slug)) {
        return $category_slug;
    }

    if (!function_exists('ll_tools_editor_hub_get_word_ids_for_wordset')) {
        return new WP_Error('ll_tools_cli_missing_editor_hub_helper', __('Editor Hub helpers are not available.', 'll-tools-text-domain'));
    }

    return array_values(array_filter(array_map('intval', ll_tools_editor_hub_get_word_ids_for_wordset($wordset_id, (string) $category_slug)), static function (int $word_id): bool {
        return $word_id > 0;
    }));
}

function ll_tools_cli_build_word_row(int $wordset_id, int $word_id, array $ui_options, array $pos_by_word): array {
    $item = function_exists('ll_tools_editor_hub_build_item')
        ? ll_tools_editor_hub_build_item($word_id, $wordset_id, $ui_options, $pos_by_word, [])
        : [];
    if (empty($item)) {
        return [];
    }

    $word_post = get_post($word_id);
    if (!($word_post instanceof WP_Post) || $word_post->post_type !== 'words') {
        return [];
    }

    $missing_flags = [];
    foreach ((array) ($item['missing_flags'] ?? []) as $field => $is_missing) {
        $field = sanitize_key((string) $field);
        if ($field === '' || !in_array($field, ll_tools_cli_supported_missing_fields(), true)) {
            continue;
        }
        $missing_flags[$field] = !empty($is_missing);
    }

    $missing_fields = [];
    foreach ($missing_flags as $field => $is_missing) {
        if ($is_missing) {
            $missing_fields[] = $field;
        }
    }

    return [
        'word_id' => $word_id,
        'word_slug' => (string) $word_post->post_name,
        'title' => (string) get_the_title($word_id),
        'word_title' => (string) get_the_title($word_id),
        'word_text' => trim((string) ($item['word_text'] ?? '')),
        'word_translation' => trim((string) ($item['word_translation'] ?? '')),
        'word_note' => trim((string) ($item['word_note'] ?? '')),
        'dictionary_entry_id' => (int) ($item['dictionary_entry']['id'] ?? 0),
        'dictionary_entry_title' => trim((string) ($item['dictionary_entry']['title'] ?? '')),
        'category_id' => (int) ($item['category']['id'] ?? 0),
        'category_slug' => (string) ($item['category']['slug'] ?? 'uncategorized'),
        'category_name' => (string) ($item['category']['name'] ?? __('Uncategorized', 'll-tools-text-domain')),
        'part_of_speech' => (string) ($item['part_of_speech']['slug'] ?? ''),
        'part_of_speech_label' => (string) ($item['part_of_speech']['label'] ?? ''),
        'grammatical_gender' => (string) ($item['grammatical_gender']['value'] ?? ''),
        'grammatical_gender_label' => (string) ($item['grammatical_gender']['label'] ?? ''),
        'grammatical_plurality' => (string) ($item['grammatical_plurality']['value'] ?? ''),
        'grammatical_plurality_label' => (string) ($item['grammatical_plurality']['label'] ?? ''),
        'verb_tense' => (string) ($item['verb_tense']['value'] ?? ''),
        'verb_tense_label' => (string) ($item['verb_tense']['label'] ?? ''),
        'verb_mood' => (string) ($item['verb_mood']['value'] ?? ''),
        'verb_mood_label' => (string) ($item['verb_mood']['label'] ?? ''),
        'missing_flags' => $missing_flags,
        'missing_fields' => $missing_fields,
        'missing_count' => count($missing_fields),
        'has_missing' => !empty($missing_fields),
    ];
}

function ll_tools_cli_get_word_rows(int $wordset_id, array $word_ids): array {
    $wordset_id = (int) $wordset_id;
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    if ($wordset_id <= 0 || empty($word_ids)) {
        return [];
    }

    update_meta_cache('post', $word_ids);

    $ui_options = function_exists('ll_tools_editor_hub_build_ui_options')
        ? (array) ll_tools_editor_hub_build_ui_options($wordset_id)
        : [];
    $pos_by_word = function_exists('ll_tools_word_grid_collect_part_of_speech_terms')
        ? (array) ll_tools_word_grid_collect_part_of_speech_terms($word_ids)
        : [];

    $rows = [];
    foreach ($word_ids as $word_id) {
        $row = ll_tools_cli_build_word_row($wordset_id, $word_id, $ui_options, $pos_by_word);
        if (!empty($row)) {
            $rows[] = $row;
        }
    }

    usort($rows, static function (array $left, array $right): int {
        $left_text = trim((string) ($left['word_text'] ?: $left['title'] ?: $left['word_translation']));
        $right_text = trim((string) ($right['word_text'] ?: $right['title'] ?: $right['word_translation']));
        return strnatcasecmp($left_text, $right_text);
    });

    return $rows;
}

function ll_tools_cli_filter_word_rows(array $rows, array $filters): array {
    $required_missing_fields = array_values(array_filter(array_map('strval', (array) ($filters['missing_fields'] ?? []))));
    $part_of_speech = sanitize_title((string) ($filters['part_of_speech'] ?? ''));

    if (empty($required_missing_fields) && $part_of_speech === '') {
        return array_values($rows);
    }

    return array_values(array_filter($rows, static function (array $row) use ($required_missing_fields, $part_of_speech): bool {
        if ($part_of_speech !== '' && sanitize_title((string) ($row['part_of_speech'] ?? '')) !== $part_of_speech) {
            return false;
        }

        if (!empty($required_missing_fields)) {
            $missing_flags = (array) ($row['missing_flags'] ?? []);
            foreach ($required_missing_fields as $field) {
                if (empty($missing_flags[$field])) {
                    return false;
                }
            }
        }

        return true;
    }));
}

function ll_tools_cli_slice_rows(array $rows, int $offset = 0, int $limit = 0): array {
    $offset = max(0, $offset);
    $limit = max(0, $limit);
    if ($offset > 0) {
        $rows = array_slice($rows, $offset);
    }
    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }
    return array_values($rows);
}

function ll_tools_cli_prepare_word_rows_for_output(array $rows): array {
    return array_map(static function (array $row): array {
        return [
            'word_id' => (int) ($row['word_id'] ?? 0),
            'word_slug' => (string) ($row['word_slug'] ?? ''),
            'word_title' => (string) ($row['word_title'] ?? $row['title'] ?? ''),
            'word_text' => (string) ($row['word_text'] ?? ''),
            'word_translation' => (string) ($row['word_translation'] ?? ''),
            'category_slug' => (string) ($row['category_slug'] ?? ''),
            'part_of_speech' => (string) ($row['part_of_speech'] ?? ''),
            'grammatical_gender' => (string) ($row['grammatical_gender'] ?? ''),
            'grammatical_plurality' => (string) ($row['grammatical_plurality'] ?? ''),
            'verb_tense' => (string) ($row['verb_tense'] ?? ''),
            'verb_mood' => (string) ($row['verb_mood'] ?? ''),
            'dictionary_entry_title' => (string) ($row['dictionary_entry_title'] ?? ''),
            'missing_fields' => implode(',', array_map('strval', (array) ($row['missing_fields'] ?? []))),
            'missing_count' => (int) ($row['missing_count'] ?? 0),
        ];
    }, $rows);
}

function ll_tools_cli_get_resume_state(string $path): array {
    $path = trim($path);
    if ($path === '' || !is_readable($path)) {
        return [
            'version' => 1,
            'processed_ids' => [],
        ];
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return [
            'version' => 1,
            'processed_ids' => [],
        ];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [
            'version' => 1,
            'processed_ids' => [],
        ];
    }

    $processed_ids = array_values(array_filter(array_map('intval', (array) ($decoded['processed_ids'] ?? [])), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $decoded['version'] = 1;
    $decoded['processed_ids'] = array_values(array_unique($processed_ids));

    return $decoded;
}

function ll_tools_cli_resume_has_processed(array $resume_state, int $word_id): bool {
    $processed_lookup = array_fill_keys(array_map('intval', (array) ($resume_state['processed_ids'] ?? [])), true);
    return isset($processed_lookup[$word_id]);
}

function ll_tools_cli_resume_mark_processed(string $path, array &$resume_state, int $word_id) {
    $path = trim($path);
    $word_id = (int) $word_id;
    if ($path === '' || $word_id <= 0) {
        return true;
    }

    $processed_ids = array_map('intval', (array) ($resume_state['processed_ids'] ?? []));
    if (!in_array($word_id, $processed_ids, true)) {
        $processed_ids[] = $word_id;
    }

    $resume_state['version'] = 1;
    $resume_state['processed_ids'] = array_values(array_unique(array_filter($processed_ids, static function (int $id): bool {
        return $id > 0;
    })));
    $resume_state['updated_at_gmt'] = gmdate('c');

    return ll_tools_cli_write_json_file($path, $resume_state);
}

function ll_tools_cli_update_word_text(int $word_id, string $word_text) {
    $word_text = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_text)
        : trim(sanitize_text_field($word_text));
    $display_values = function_exists('ll_tools_word_grid_resolve_display_text')
        ? ll_tools_word_grid_resolve_display_text($word_id)
        : [
            'store_in_title' => true,
        ];
    $store_in_title = !empty($display_values['store_in_title']);

    if ($store_in_title) {
        return wp_update_post([
            'ID' => $word_id,
            'post_title' => $word_text,
        ], true);
    }

    if ($word_text !== '') {
        update_post_meta($word_id, 'word_translation', $word_text);
    } else {
        delete_post_meta($word_id, 'word_translation');
    }

    return true;
}

function ll_tools_cli_update_word_title(int $word_id, string $word_title) {
    $word_title = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_title)
        : trim(sanitize_text_field($word_title));

    return wp_update_post([
        'ID' => $word_id,
        'post_title' => $word_title,
    ], true);
}

function ll_tools_cli_update_word_translation(int $word_id, string $translation_text): void {
    $translation_text = trim($translation_text);
    $display_values = function_exists('ll_tools_word_grid_resolve_display_text')
        ? ll_tools_word_grid_resolve_display_text($word_id)
        : [
            'word_text' => (string) get_the_title($word_id),
            'store_in_title' => true,
        ];
    $store_in_title = !empty($display_values['store_in_title']);
    $word_text = trim((string) ($display_values['word_text'] ?? get_the_title($word_id)));

    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
            update_post_meta($word_id, 'word_english_meaning', $translation_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
            delete_post_meta($word_id, 'word_english_meaning');
        }
        return;
    }

    wp_update_post([
        'ID' => $word_id,
        'post_title' => $translation_text,
    ]);

    if ($translation_text !== '') {
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_english_meaning');
    }

    if ($word_text !== '') {
        update_post_meta($word_id, 'word_translation', $word_text);
    } else {
        delete_post_meta($word_id, 'word_translation');
    }
}

function ll_tools_cli_apply_word_field_update(int $wordset_id, int $word_id, string $field, string $value) {
    $wordset_id = (int) $wordset_id;
    $word_id = (int) $word_id;
    $field = sanitize_key($field);
    $value = trim($value);

    if ($wordset_id <= 0 || $word_id <= 0) {
        return new WP_Error('ll_tools_cli_invalid_update_scope', __('Missing word or word set for update.', 'll-tools-text-domain'));
    }

    $before_rows = ll_tools_cli_get_word_rows($wordset_id, [$word_id]);
    $before = $before_rows[0] ?? [];
    if (empty($before)) {
        return new WP_Error('ll_tools_cli_word_row_missing', __('Unable to load the current word state.', 'll-tools-text-domain'));
    }

    $current_pos = sanitize_title((string) ($before['part_of_speech'] ?? ''));

    switch ($field) {
        case 'post_title':
        case 'word_title':
            $word_title_result = ll_tools_cli_update_word_title($word_id, $value);
            if (is_wp_error($word_title_result)) {
                return $word_title_result;
            }
            break;

        case 'word_text':
            $word_text_result = ll_tools_cli_update_word_text($word_id, $value);
            if (is_wp_error($word_text_result)) {
                return $word_text_result;
            }
            break;

        case 'word_translation':
            ll_tools_cli_update_word_translation($word_id, $value);
            break;

        case 'word_note':
            if ($value === '') {
                delete_post_meta($word_id, 'll_word_usage_note');
            } else {
                update_post_meta($word_id, 'll_word_usage_note', sanitize_textarea_field($value));
            }
            break;

        case 'dictionary_entry_title':
            if (!function_exists('ll_tools_assign_dictionary_entry_to_word')) {
                return new WP_Error('ll_tools_cli_missing_dictionary_helper', __('Dictionary entry helpers are not available.', 'll-tools-text-domain'));
            }
            $dictionary_result = ll_tools_assign_dictionary_entry_to_word($word_id, 0, $value);
            if (is_wp_error($dictionary_result)) {
                return $dictionary_result;
            }
            break;

        case 'part_of_speech':
            $pos_slug = sanitize_title($value);
            if ($pos_slug === '') {
                wp_set_object_terms($word_id, [], 'part_of_speech', false);
                delete_post_meta($word_id, 'll_grammatical_gender');
                delete_post_meta($word_id, 'll_grammatical_plurality');
                delete_post_meta($word_id, 'll_verb_tense');
                delete_post_meta($word_id, 'll_verb_mood');
                break;
            }

            $term = get_term_by('slug', $pos_slug, 'part_of_speech');
            if (!($term instanceof WP_Term) || is_wp_error($term)) {
                return new WP_Error(
                    'll_tools_cli_invalid_part_of_speech',
                    sprintf(
                        /* translators: %s: part-of-speech slug */
                        __('Unknown part of speech: %s', 'll-tools-text-domain'),
                        $pos_slug
                    )
                );
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
            break;

        case 'grammatical_gender':
            if (!function_exists('ll_tools_wordset_has_grammatical_gender') || !ll_tools_wordset_has_grammatical_gender($wordset_id)) {
                return new WP_Error('ll_tools_cli_gender_disabled', __('Grammatical gender is not enabled for this word set.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'noun' && $value !== '') {
                return new WP_Error('ll_tools_cli_gender_requires_noun', __('Gender can only be set on noun words.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'noun' && $value === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
                break;
            }

            $allowed = function_exists('ll_tools_wordset_get_gender_options')
                ? ll_tools_wordset_get_gender_options($wordset_id)
                : [];
            $normalized = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                ? ll_tools_wordset_normalize_gender_value_for_options($value, $allowed)
                : $value;

            if ($normalized === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
            } elseif (in_array($normalized, $allowed, true)) {
                update_post_meta($word_id, 'll_grammatical_gender', $normalized);
            } else {
                return new WP_Error(
                    'll_tools_cli_invalid_gender_value',
                    sprintf(
                        /* translators: %s: comma-separated options */
                        __('Invalid gender value. Allowed values: %s', 'll-tools-text-domain'),
                        implode(', ', $allowed)
                    )
                );
            }
            break;

        case 'grammatical_plurality':
            if (!function_exists('ll_tools_wordset_has_plurality') || !ll_tools_wordset_has_plurality($wordset_id)) {
                return new WP_Error('ll_tools_cli_plurality_disabled', __('Plurality is not enabled for this word set.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'noun' && $value !== '') {
                return new WP_Error('ll_tools_cli_plurality_requires_noun', __('Plurality can only be set on noun words.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'noun' && $value === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                break;
            }

            $allowed = function_exists('ll_tools_wordset_get_plurality_options')
                ? ll_tools_wordset_get_plurality_options($wordset_id)
                : [];
            $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
                ? ll_tools_word_grid_match_option_value_case_insensitive($value, $allowed)
                : $value;

            if ($normalized === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            } elseif (in_array($normalized, $allowed, true)) {
                update_post_meta($word_id, 'll_grammatical_plurality', $normalized);
            } else {
                return new WP_Error(
                    'll_tools_cli_invalid_plurality_value',
                    sprintf(
                        /* translators: %s: comma-separated options */
                        __('Invalid plurality value. Allowed values: %s', 'll-tools-text-domain'),
                        implode(', ', $allowed)
                    )
                );
            }
            break;

        case 'verb_tense':
            if (!function_exists('ll_tools_wordset_has_verb_tense') || !ll_tools_wordset_has_verb_tense($wordset_id)) {
                return new WP_Error('ll_tools_cli_verb_tense_disabled', __('Verb tense is not enabled for this word set.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'verb' && $value !== '') {
                return new WP_Error('ll_tools_cli_verb_tense_requires_verb', __('Verb tense can only be set on verb words.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'verb' && $value === '') {
                delete_post_meta($word_id, 'll_verb_tense');
                break;
            }

            $allowed = function_exists('ll_tools_wordset_get_verb_tense_options')
                ? ll_tools_wordset_get_verb_tense_options($wordset_id)
                : [];
            $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
                ? ll_tools_word_grid_match_option_value_case_insensitive($value, $allowed)
                : $value;

            if ($normalized === '') {
                delete_post_meta($word_id, 'll_verb_tense');
            } elseif (in_array($normalized, $allowed, true)) {
                update_post_meta($word_id, 'll_verb_tense', $normalized);
            } else {
                return new WP_Error(
                    'll_tools_cli_invalid_verb_tense_value',
                    sprintf(
                        /* translators: %s: comma-separated options */
                        __('Invalid verb tense value. Allowed values: %s', 'll-tools-text-domain'),
                        implode(', ', $allowed)
                    )
                );
            }
            break;

        case 'verb_mood':
            if (!function_exists('ll_tools_wordset_has_verb_mood') || !ll_tools_wordset_has_verb_mood($wordset_id)) {
                return new WP_Error('ll_tools_cli_verb_mood_disabled', __('Verb mood is not enabled for this word set.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'verb' && $value !== '') {
                return new WP_Error('ll_tools_cli_verb_mood_requires_verb', __('Verb mood can only be set on verb words.', 'll-tools-text-domain'));
            }
            if ($current_pos !== 'verb' && $value === '') {
                delete_post_meta($word_id, 'll_verb_mood');
                break;
            }

            $allowed = function_exists('ll_tools_wordset_get_verb_mood_options')
                ? ll_tools_wordset_get_verb_mood_options($wordset_id)
                : [];
            $normalized = function_exists('ll_tools_word_grid_match_option_value_case_insensitive')
                ? ll_tools_word_grid_match_option_value_case_insensitive($value, $allowed)
                : $value;

            if ($normalized === '') {
                delete_post_meta($word_id, 'll_verb_mood');
            } elseif (in_array($normalized, $allowed, true)) {
                update_post_meta($word_id, 'll_verb_mood', $normalized);
            } else {
                return new WP_Error(
                    'll_tools_cli_invalid_verb_mood_value',
                    sprintf(
                        /* translators: %s: comma-separated options */
                        __('Invalid verb mood value. Allowed values: %s', 'll-tools-text-domain'),
                        implode(', ', $allowed)
                    )
                );
            }
            break;

        default:
            return new WP_Error(
                'll_tools_cli_unsupported_update_field',
                sprintf(
                    /* translators: %s: field name */
                    __('Unsupported update field: %s', 'll-tools-text-domain'),
                    $field
                )
            );
    }

    clean_post_cache($word_id);
    if (function_exists('ll_tools_word_grid_bump_category_cache_for_words')) {
        ll_tools_word_grid_bump_category_cache_for_words([$word_id]);
    }

    $after_rows = ll_tools_cli_get_word_rows($wordset_id, [$word_id]);
    $after = $after_rows[0] ?? [];
    if (empty($after)) {
        return new WP_Error('ll_tools_cli_word_row_after_missing', __('Unable to load the updated word state.', 'll-tools-text-domain'));
    }

    $compare_keys = [
        'word_title',
        'word_text',
        'word_translation',
        'word_note',
        'dictionary_entry_title',
        'part_of_speech',
        'grammatical_gender',
        'grammatical_plurality',
        'verb_tense',
        'verb_mood',
    ];
    $changed_keys = [];
    foreach ($compare_keys as $compare_key) {
        $before_value = (string) ($before[$compare_key] ?? '');
        $after_value = (string) ($after[$compare_key] ?? '');
        if ($before_value !== $after_value) {
            $changed_keys[] = $compare_key;
        }
    }

    return [
        'word_id' => $word_id,
        'word_slug' => (string) ($after['word_slug'] ?? ''),
        'changed' => !empty($changed_keys),
        'changed_keys' => $changed_keys,
        'before' => $before,
        'after' => $after,
    ];
}

function ll_tools_cli_build_wordset_report(int $wordset_id, string $category_spec = ''): array {
    $wordset_id = (int) $wordset_id;
    $word_ids = ll_tools_cli_get_word_ids_for_scope($wordset_id, $category_spec, '');
    if (is_wp_error($word_ids)) {
        return [
            'error' => $word_ids->get_error_message(),
        ];
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    $rows = ll_tools_cli_get_word_rows($wordset_id, $word_ids);
    $missing_field_counts = array_fill_keys(ll_tools_cli_supported_missing_fields(), 0);
    $category_counts = [];

    foreach ($rows as $row) {
        $category_slug = (string) ($row['category_slug'] ?? 'uncategorized');
        if (!isset($category_counts[$category_slug])) {
            $category_counts[$category_slug] = [
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_slug' => $category_slug,
                'category_name' => (string) ($row['category_name'] ?? __('Uncategorized', 'll-tools-text-domain')),
                'word_count' => 0,
                'missing_metadata_words' => 0,
            ];
        }
        $category_counts[$category_slug]['word_count']++;
        if (!empty($row['has_missing'])) {
            $category_counts[$category_slug]['missing_metadata_words']++;
        }

        foreach ((array) ($row['missing_fields'] ?? []) as $field) {
            if (isset($missing_field_counts[$field])) {
                $missing_field_counts[$field]++;
            }
        }
    }

    uasort($category_counts, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['category_name'] ?? ''), (string) ($right['category_name'] ?? ''));
    });

    $words_with_images = 0;
    foreach ($word_ids as $word_id) {
        $image_data = function_exists('ll_tools_get_effective_word_image_data_for_word')
            ? (array) ll_tools_get_effective_word_image_data_for_word((int) $word_id, 'thumbnail', true)
            : [];
        $has_image = (int) ($image_data['attachment_id'] ?? 0) > 0 || (int) ($image_data['word_image_id'] ?? 0) > 0;
        if ($has_image) {
            $words_with_images++;
        }
    }

    $audio_by_word = function_exists('ll_tools_word_grid_collect_audio_files')
        ? (array) ll_tools_word_grid_collect_audio_files($word_ids, false)
        : [];
    $words_with_audio = 0;
    $audio_ids = [];
    $audio_word_type_counts = [
        'question' => 0,
        'isolation' => 0,
        'introduction' => 0,
    ];
    $audio_attribution_counts = [
        'speaker' => 0,
        'audio_credit' => 0,
        'audio_source_name' => 0,
        'audio_source_url' => 0,
        'audio_license' => 0,
        'audio_license_url' => 0,
        'audio_change_note' => 0,
    ];
    $audio_seen_ids = [];

    foreach ($audio_by_word as $word_audio_rows) {
        if (!empty($word_audio_rows)) {
            $words_with_audio++;
        }

        $word_types = [];
        foreach ((array) $word_audio_rows as $audio_row) {
            $audio_id = (int) ($audio_row['id'] ?? 0);
            if ($audio_id <= 0) {
                continue;
            }
            $type = sanitize_key((string) ($audio_row['recording_type'] ?? ''));
            if ($type !== '') {
                $word_types[$type] = true;
            }

            if (isset($audio_seen_ids[$audio_id])) {
                continue;
            }
            $audio_seen_ids[$audio_id] = true;
            $audio_ids[] = $audio_id;

            $has_speaker = !empty($audio_row['speaker_name']) || !empty($audio_row['speaker_user_id']);
            if ($has_speaker) {
                $audio_attribution_counts['speaker']++;
            }

            foreach (['audio_credit', 'audio_source_name', 'audio_source_url', 'audio_license', 'audio_license_url', 'audio_change_note'] as $field) {
                if (!empty($audio_row[$field])) {
                    $audio_attribution_counts[$field]++;
                }
            }
        }

        foreach (array_keys($audio_word_type_counts) as $type) {
            if (!empty($word_types[$type])) {
                $audio_word_type_counts[$type]++;
            }
        }
    }

    return [
        'generated_at_gmt' => gmdate('c'),
        'wordset' => [
            'id' => $wordset_id,
            'slug' => ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term)) ? (string) $wordset_term->slug : '',
            'name' => ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term)) ? (string) $wordset_term->name : '',
        ],
        'settings' => [
            'visibility' => function_exists('ll_tools_get_wordset_visibility')
                ? (string) ll_tools_get_wordset_visibility($wordset_id)
                : '',
            'translation_language' => function_exists('ll_tools_get_wordset_translation_language')
                ? (string) ll_tools_get_wordset_translation_language([$wordset_id], true)
                : '',
            'word_title_language_role' => function_exists('ll_tools_get_wordset_title_language_role')
                ? (string) ll_tools_get_wordset_title_language_role([$wordset_id], true)
                : '',
            'recording_transcription_mode' => function_exists('ll_tools_get_wordset_recording_transcription_mode')
                ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
                : '',
            'gender_enabled' => function_exists('ll_tools_wordset_has_grammatical_gender')
                ? (bool) ll_tools_wordset_has_grammatical_gender($wordset_id)
                : false,
            'plurality_enabled' => function_exists('ll_tools_wordset_has_plurality')
                ? (bool) ll_tools_wordset_has_plurality($wordset_id)
                : false,
            'verb_tense_enabled' => function_exists('ll_tools_wordset_has_verb_tense')
                ? (bool) ll_tools_wordset_has_verb_tense($wordset_id)
                : false,
            'verb_mood_enabled' => function_exists('ll_tools_wordset_has_verb_mood')
                ? (bool) ll_tools_wordset_has_verb_mood($wordset_id)
                : false,
        ],
        'counts' => [
            'words_total' => count($word_ids),
            'categories_total' => count($category_counts),
            'missing_metadata_words' => count(array_filter($rows, static function (array $row): bool {
                return !empty($row['has_missing']);
            })),
            'words_with_images' => $words_with_images,
            'words_without_images' => max(0, count($word_ids) - $words_with_images),
            'words_with_audio' => $words_with_audio,
            'words_without_audio' => max(0, count($word_ids) - $words_with_audio),
            'audio_records_total' => count($audio_ids),
        ],
        'coverage' => [
            'recording_type_word_counts' => $audio_word_type_counts,
            'audio_attribution_counts' => $audio_attribution_counts,
        ],
        'missing_metadata_by_field' => $missing_field_counts,
        'categories' => array_values($category_counts),
    ];
}
