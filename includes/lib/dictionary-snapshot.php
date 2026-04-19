<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY', 'll_dictionary_entry_import_key');
}

if (!defined('LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION')) {
    define('LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION', 'll_tools_dictionary_import_history');
}

if (!defined('LL_TOOLS_DICTIONARY_SNAPSHOT_FORMAT')) {
    define('LL_TOOLS_DICTIONARY_SNAPSHOT_FORMAT', 'll-tools-dictionary-snapshot');
}

function ll_tools_dictionary_import_history_max_entries(): int {
    return max(5, (int) apply_filters('ll_tools_dictionary_import_history_max_entries', 25));
}

function ll_tools_dictionary_snapshot_sanitize_import_key(string $value): string {
    $value = trim(sanitize_text_field($value));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^A-Za-z0-9:_-]+/', '-', $value) ?? '';

    return trim($value, '-_: ');
}

function ll_tools_dictionary_generate_import_key(): string {
    return 'dict:' . wp_generate_uuid4();
}

function ll_tools_get_dictionary_entry_import_key(int $entry_id, bool $create = true): string {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !function_exists('ll_tools_is_dictionary_entry_id') || !ll_tools_is_dictionary_entry_id($entry_id)) {
        return '';
    }

    $stored = ll_tools_dictionary_snapshot_sanitize_import_key((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY, true));
    if ($stored !== '') {
        return $stored;
    }

    if (!$create) {
        return '';
    }

    $generated = ll_tools_dictionary_generate_import_key();
    update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY, $generated);

    return $generated;
}

function ll_tools_dictionary_find_entry_by_import_key(string $import_key): int {
    global $wpdb;

    $import_key = ll_tools_dictionary_snapshot_sanitize_import_key($import_key);
    if ($import_key === '') {
        return 0;
    }

    $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $params = array_merge(
        [
            LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY,
            $import_key,
        ],
        $statuses
    );

    $sql = "
        SELECT posts.ID
        FROM {$wpdb->posts} posts
        INNER JOIN {$wpdb->postmeta} import_key_meta
            ON import_key_meta.post_id = posts.ID
           AND import_key_meta.meta_key = %s
        WHERE import_key_meta.meta_value = %s
          AND posts.post_type = 'll_dictionary_entry'
          AND posts.post_status IN ({$placeholders})
        ORDER BY posts.ID ASC
        LIMIT 1
    ";

    return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
}

function ll_tools_dictionary_snapshot_normalize_status(string $status): string {
    $status = sanitize_key($status);
    if (in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return $status;
    }

    return 'publish';
}

/**
 * @return array{id:int,slug:string,name:string}|null
 */
function ll_tools_dictionary_snapshot_build_wordset_payload(int $wordset_id): ?array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return null;
    }

    $term = get_term($wordset_id, 'wordset');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return null;
    }

    return [
        'id' => (int) $term->term_id,
        'slug' => (string) $term->slug,
        'name' => (string) $term->name,
    ];
}

function ll_tools_dictionary_snapshot_resolve_wordset_id($wordset): int {
    if (is_scalar($wordset)) {
        $slug = sanitize_title((string) $wordset);
        if ($slug !== '') {
            $term = get_term_by('slug', $slug, 'wordset');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }

        $maybe_id = (int) $wordset;
        if ($maybe_id > 0 && term_exists($maybe_id, 'wordset')) {
            return $maybe_id;
        }

        return 0;
    }

    if (!is_array($wordset)) {
        return 0;
    }

    $slug = sanitize_title((string) ($wordset['slug'] ?? ''));
    if ($slug !== '') {
        $term = get_term_by('slug', $slug, 'wordset');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }

    $wordset_id = isset($wordset['id']) ? (int) $wordset['id'] : 0;
    if ($wordset_id > 0 && term_exists($wordset_id, 'wordset')) {
        return $wordset_id;
    }

    $name = trim(sanitize_text_field((string) ($wordset['name'] ?? '')));
    if ($name !== '') {
        $terms = get_terms([
            'taxonomy' => 'wordset',
            'hide_empty' => false,
            'name' => $name,
            'number' => 1,
        ]);
        if (is_array($terms) && !empty($terms) && $terms[0] instanceof WP_Term) {
            return (int) $terms[0]->term_id;
        }
    }

    return 0;
}

/**
 * @return int[]
 */
function ll_tools_dictionary_get_exportable_entry_ids(array $args = []): array {
    $statuses = isset($args['post_status']) && is_array($args['post_status'])
        ? array_values(array_filter(array_map('sanitize_key', $args['post_status'])))
        : ['publish', 'draft', 'pending', 'private', 'future'];

    $entry_ids = get_posts([
        'post_type' => 'll_dictionary_entry',
        'post_status' => $statuses,
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => false,
    ]);

    return array_values(array_filter(array_map('intval', (array) $entry_ids), static function (int $entry_id): bool {
        return $entry_id > 0;
    }));
}

/**
 * @return array<string,mixed>
 */
function ll_tools_dictionary_build_entry_snapshot(int $entry_id): array {
    $entry_id = (int) $entry_id;
    $explicit_wordset_id = function_exists('ll_tools_get_dictionary_entry_explicit_wordset_id')
        ? (int) ll_tools_get_dictionary_entry_explicit_wordset_id($entry_id)
        : 0;

    return [
        'import_key' => ll_tools_get_dictionary_entry_import_key($entry_id, true),
        'title' => trim((string) get_the_title($entry_id)),
        'status' => ll_tools_dictionary_snapshot_normalize_status((string) get_post_status($entry_id)),
        'translation' => function_exists('ll_tools_get_dictionary_entry_translation')
            ? (string) ll_tools_get_dictionary_entry_translation($entry_id)
            : trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true)),
        'wordset' => ll_tools_dictionary_snapshot_build_wordset_payload($explicit_wordset_id),
        'senses' => array_values(array_map(
            static function (array $sense): array {
                return ll_tools_dictionary_sanitize_sense($sense);
            },
            ll_tools_get_dictionary_entry_senses($entry_id)
        )),
    ];
}

/**
 * @return array<string,mixed>
 */
function ll_tools_dictionary_build_snapshot(array $args = []): array {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $entries = [];
    foreach (ll_tools_dictionary_get_exportable_entry_ids($args) as $entry_id) {
        $entries[] = ll_tools_dictionary_build_entry_snapshot((int) $entry_id);
    }

    return [
        'format' => LL_TOOLS_DICTIONARY_SNAPSHOT_FORMAT,
        'version' => 1,
        'generated_at' => gmdate('c'),
        'site_url' => home_url('/'),
        'source_count' => count(ll_tools_get_dictionary_source_registry()),
        'entry_count' => count($entries),
        'sources' => array_values(ll_tools_get_dictionary_source_registry()),
        'entries' => $entries,
    ];
}

function ll_tools_dictionary_encode_snapshot(array $snapshot) {
    $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_encode_failed', __('Could not encode the dictionary snapshot JSON.', 'll-tools-text-domain'));
    }

    return $json;
}

function ll_tools_dictionary_write_snapshot_file(string $path, array $snapshot) {
    $json = ll_tools_dictionary_encode_snapshot($snapshot);
    if (is_wp_error($json)) {
        return $json;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        return new WP_Error('ll_tools_dictionary_snapshot_dir_failed', __('Could not create the dictionary snapshot directory.', 'll-tools-text-domain'));
    }

    if (file_put_contents($path, $json) === false) {
        return new WP_Error('ll_tools_dictionary_snapshot_write_failed', __('Could not write the dictionary snapshot file.', 'll-tools-text-domain'));
    }

    return [
        'path' => $path,
        'bytes' => strlen($json),
    ];
}

function ll_tools_dictionary_parse_snapshot_payload(string $payload) {
    $payload = trim($payload);
    if ($payload === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_empty', __('The uploaded dictionary snapshot is empty.', 'll-tools-text-domain'));
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return new WP_Error('ll_tools_dictionary_snapshot_invalid_json', __('The dictionary snapshot is not valid JSON.', 'll-tools-text-domain'));
    }

    $format = trim((string) ($decoded['format'] ?? ''));
    if ($format !== LL_TOOLS_DICTIONARY_SNAPSHOT_FORMAT) {
        return new WP_Error('ll_tools_dictionary_snapshot_wrong_format', __('This file is not an LL Tools dictionary snapshot.', 'll-tools-text-domain'));
    }

    $sources = ll_tools_dictionary_sanitize_source_registry($decoded['sources'] ?? []);
    $entries = [];
    foreach ((array) ($decoded['entries'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entries[] = ll_tools_dictionary_snapshot_sanitize_entry($entry);
    }

    return [
        'format' => LL_TOOLS_DICTIONARY_SNAPSHOT_FORMAT,
        'version' => max(1, (int) ($decoded['version'] ?? 1)),
        'generated_at' => trim(sanitize_text_field((string) ($decoded['generated_at'] ?? ''))),
        'site_url' => esc_url_raw((string) ($decoded['site_url'] ?? '')),
        'source_count' => count($sources),
        'entry_count' => count($entries),
        'sources' => array_values($sources),
        'entries' => $entries,
    ];
}

function ll_tools_dictionary_parse_snapshot_file(string $file_path) {
    $file_path = trim($file_path);
    if ($file_path === '' || !is_readable($file_path)) {
        return new WP_Error('ll_tools_dictionary_snapshot_unreadable', __('Could not read the uploaded dictionary snapshot.', 'll-tools-text-domain'));
    }

    $payload = file_get_contents($file_path);
    if (!is_string($payload) || $payload === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_read_failed', __('Could not read the dictionary snapshot file.', 'll-tools-text-domain'));
    }

    return ll_tools_dictionary_parse_snapshot_payload($payload);
}

/**
 * @return array<string,mixed>
 */
function ll_tools_dictionary_snapshot_sanitize_entry(array $entry): array {
    $senses = [];
    foreach ((array) ($entry['senses'] ?? []) as $sense) {
        if (!is_array($sense)) {
            continue;
        }
        $senses[] = ll_tools_dictionary_sanitize_sense($sense);
    }

    return [
        'import_key' => ll_tools_dictionary_snapshot_sanitize_import_key((string) ($entry['import_key'] ?? '')),
        'title' => trim(sanitize_text_field((string) ($entry['title'] ?? ''))),
        'status' => ll_tools_dictionary_snapshot_normalize_status((string) ($entry['status'] ?? 'publish')),
        'translation' => trim(sanitize_text_field((string) ($entry['translation'] ?? ''))),
        'wordset' => ll_tools_dictionary_snapshot_sanitize_wordset($entry['wordset'] ?? null),
        'senses' => $senses,
    ];
}

/**
 * @return array{id:int,slug:string,name:string}|null
 */
function ll_tools_dictionary_snapshot_sanitize_wordset($wordset): ?array {
    if ($wordset === null || $wordset === '' || $wordset === 0) {
        return null;
    }

    if (is_scalar($wordset)) {
        $slug = sanitize_title((string) $wordset);
        if ($slug === '') {
            return null;
        }

        return [
            'id' => 0,
            'slug' => $slug,
            'name' => '',
        ];
    }

    if (!is_array($wordset)) {
        return null;
    }

    $slug = sanitize_title((string) ($wordset['slug'] ?? ''));
    $name = trim(sanitize_text_field((string) ($wordset['name'] ?? '')));
    $id = isset($wordset['id']) ? max(0, (int) $wordset['id']) : 0;
    if ($slug === '' && $name === '' && $id <= 0) {
        return null;
    }

    return [
        'id' => $id,
        'slug' => $slug,
        'name' => $name,
    ];
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return string[]
 */
function ll_tools_dictionary_snapshot_collect_entry_keys(array $entries): array {
    $keys = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $key = ll_tools_dictionary_snapshot_sanitize_import_key((string) ($entry['import_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $keys[$key] = $key;
    }

    return array_values($keys);
}

/**
 * @param array<int,array<string,mixed>> $senses
 * @return string[]
 */
function ll_tools_dictionary_snapshot_collect_preferred_languages(array $senses): array {
    $languages = [];
    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $def_lang = ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''));
        if ($def_lang !== '' && !in_array($def_lang, $languages, true)) {
            $languages[] = $def_lang;
        }

        foreach (array_keys((array) ($sense['translations'] ?? [])) as $language) {
            $language = ll_tools_dictionary_normalize_language_key((string) $language);
            if ($language !== '' && !in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }
    }

    return $languages;
}

function ll_tools_dictionary_upsert_entry_from_snapshot(array $entry, array $options = []) {
    $snapshot = ll_tools_dictionary_snapshot_sanitize_entry($entry);
    $title = (string) ($snapshot['title'] ?? '');
    if ($title === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_missing_title', __('Dictionary snapshot entries need a title.', 'll-tools-text-domain'));
    }

    $senses = array_values(array_filter((array) ($snapshot['senses'] ?? []), static function ($sense): bool {
        return is_array($sense);
    }));

    $import_key = ll_tools_dictionary_snapshot_sanitize_import_key((string) ($snapshot['import_key'] ?? ''));
    if ($import_key === '') {
        $import_key = ll_tools_dictionary_generate_import_key();
    }

    $entry_id = ll_tools_dictionary_find_entry_by_import_key($import_key);
    $wordset_id = ll_tools_dictionary_snapshot_resolve_wordset_id($snapshot['wordset'] ?? null);

    if ($entry_id <= 0) {
        $entry_id = ll_tools_dictionary_find_entry_by_title($title, $wordset_id);
    }

    $preferred_languages = ll_tools_dictionary_snapshot_collect_preferred_languages($senses);
    $translation = trim((string) ($snapshot['translation'] ?? ''));
    if ($translation === '') {
        $translation = ll_tools_dictionary_build_translation_summary(
            $senses,
            ($entry_id > 0 ? (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true) : ''),
            $preferred_languages
        );
    }

    $postarr = [
        'post_type' => 'll_dictionary_entry',
        'post_title' => $title,
        'post_status' => ll_tools_dictionary_snapshot_normalize_status((string) ($snapshot['status'] ?? 'publish')),
        'post_content' => ll_tools_dictionary_build_post_content_from_senses($senses, $preferred_languages),
    ];
    if ($entry_id > 0) {
        $postarr['ID'] = $entry_id;
    }

    $saved_entry_id = wp_insert_post($postarr, true);
    if (is_wp_error($saved_entry_id) || (int) $saved_entry_id <= 0) {
        return new WP_Error(
            'll_tools_dictionary_snapshot_save_failed',
            is_wp_error($saved_entry_id)
                ? $saved_entry_id->get_error_message()
                : __('Unable to save the dictionary snapshot entry.', 'll-tools-text-domain')
        );
    }

    $saved_entry_id = (int) $saved_entry_id;
    update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_IMPORT_KEY_META_KEY, $import_key);
    update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $senses);

    if ($translation !== '') {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
    } else {
        delete_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    if ($wordset_id > 0) {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
    } else {
        delete_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY);
    }

    $primary_entry_type = ll_tools_dictionary_get_primary_sense_value($senses, 'entry_type');
    $primary_page_number = ll_tools_dictionary_get_primary_sense_value($senses, 'page_number');
    $primary_parent = ll_tools_dictionary_get_primary_sense_value($senses, 'parent');
    $primary_gender = ll_tools_dictionary_get_primary_sense_value($senses, 'gender_number');
    $primary_entry_lang = ll_tools_dictionary_get_primary_sense_value($senses, 'entry_lang');
    $primary_def_lang = ll_tools_dictionary_get_primary_sense_value($senses, 'def_lang');
    $primary_review = ll_tools_dictionary_get_primary_sense_value($senses, 'needs_review');
    $pos_slug = ll_tools_dictionary_resolve_pos_slug_from_entry_type($primary_entry_type);

    $meta_updates = [
        LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY => $primary_entry_type,
        LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY => $primary_page_number,
        LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY => $primary_parent,
        LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY => $primary_gender,
        LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY => $primary_entry_lang,
        LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY => $primary_def_lang,
        LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY => $primary_review,
        LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY => $pos_slug,
    ];

    foreach ($meta_updates as $meta_key => $value) {
        if ($value !== '') {
            update_post_meta($saved_entry_id, $meta_key, $value);
        } else {
            delete_post_meta($saved_entry_id, $meta_key);
        }
    }

    ll_tools_dictionary_refresh_entry_search_meta($saved_entry_id);
    if ($translation !== '') {
        $title_lookup = ll_tools_dictionary_entry_normalize_lookup_value($title);
        $translation_lookup = ll_tools_dictionary_entry_normalize_lookup_value($translation);
        $search_index = ll_tools_dictionary_build_search_index(
            $title,
            $translation,
            (string) get_post_field('post_content', $saved_entry_id),
            $senses
        );

        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
        if ($title_lookup !== '') {
            update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, $title_lookup);
        }
        if ($translation_lookup !== '') {
            update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY, $translation_lookup);
        }
        if ($search_index !== '') {
            update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, $search_index);
        }
    }
    if (function_exists('ll_tools_refresh_dictionary_entry_wordset_scope_meta')) {
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($saved_entry_id);
    }

    return [
        'entry_id' => $saved_entry_id,
        'entry_title' => $title,
        'import_key' => $import_key,
        'created' => ($entry_id <= 0),
        'updated' => ($entry_id > 0),
    ];
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_snapshot_entries(array $entries, array $options = []): array {
    $summary = [
        'rows_total' => count($entries),
        'rows_grouped' => count($entries),
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
        'error_count' => 0,
    ];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $result = ll_tools_dictionary_upsert_entry_from_snapshot($entry, $options);
        if (is_wp_error($result)) {
            $summary['errors'][] = $result->get_error_message();
            continue;
        }

        $entry_id = (int) ($result['entry_id'] ?? 0);
        if ($entry_id > 0) {
            $summary['entry_ids'][] = $entry_id;
        }

        if (!empty($result['created'])) {
            $summary['entries_created']++;
        } else {
            $summary['entries_updated']++;
        }
    }

    $summary['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $summary['entry_ids']))));
    $summary['error_count'] = count((array) $summary['errors']);

    return $summary;
}

/**
 * @param array<int,string>|array<string,string> $snapshot_sources
 * @return array<string,int>
 */
function ll_tools_dictionary_apply_snapshot_sources(array $snapshot_sources, string $mode = 'merge'): array {
    $incoming = ll_tools_dictionary_sanitize_source_registry($snapshot_sources);
    $mode = sanitize_key($mode);

    if ($mode === 'override') {
        ll_tools_update_dictionary_source_registry($incoming);

        return [
            'sources_updated' => count($incoming),
            'sources_replaced' => count($incoming),
        ];
    }

    $current = ll_tools_get_dictionary_source_registry();
    foreach ($incoming as $source_id => $source) {
        $current[$source_id] = $source;
    }
    ll_tools_update_dictionary_source_registry($current);

    return [
        'sources_updated' => count($incoming),
        'sources_replaced' => 0,
    ];
}

/**
 * @param string[] $import_keys
 */
function ll_tools_dictionary_delete_entries_missing_keys(array $import_keys): int {
    $allowed = [];
    foreach ($import_keys as $import_key) {
        $clean_key = ll_tools_dictionary_snapshot_sanitize_import_key((string) $import_key);
        if ($clean_key !== '') {
            $allowed[$clean_key] = true;
        }
    }

    $deleted = 0;
    foreach (ll_tools_dictionary_get_exportable_entry_ids() as $entry_id) {
        $key = ll_tools_get_dictionary_entry_import_key((int) $entry_id, true);
        if ($key !== '' && isset($allowed[$key])) {
            continue;
        }

        $result = wp_delete_post((int) $entry_id, true);
        if ($result) {
            $deleted++;
        }
    }

    return $deleted;
}

function ll_tools_dictionary_snapshot_get_dir(): string {
    $upload_dir = wp_get_upload_dir();
    $base_dir = trailingslashit((string) ($upload_dir['basedir'] ?? '')) . 'll-tools-dictionary-snapshots';
    if (!is_dir($base_dir)) {
        wp_mkdir_p($base_dir);
    }

    return $base_dir;
}

function ll_tools_dictionary_import_read_history(): array {
    $raw = get_option(LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION, []);
    return is_array($raw) ? array_values($raw) : [];
}

function ll_tools_dictionary_import_write_history(array $entries): void {
    $normalized = array_values(array_filter($entries, static function ($entry): bool {
        return is_array($entry) && !empty($entry['id']);
    }));

    $trimmed = array_slice($normalized, 0, ll_tools_dictionary_import_history_max_entries());
    $kept_paths = [];
    foreach ($trimmed as $entry) {
        $path = trim((string) ($entry['backup_snapshot_path'] ?? ''));
        if ($path !== '') {
            $kept_paths[$path] = true;
        }
    }

    foreach ($normalized as $index => $entry) {
        if ($index < count($trimmed)) {
            continue;
        }

        $path = trim((string) ($entry['backup_snapshot_path'] ?? ''));
        if ($path !== '' && !isset($kept_paths[$path]) && file_exists($path)) {
            @unlink($path);
        }
    }

    update_option(LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION, $trimmed, false);
}

function ll_tools_dictionary_import_append_history_entry(array $entry): string {
    $entry['id'] = sanitize_text_field((string) ($entry['id'] ?? wp_generate_uuid4()));
    $entries = ll_tools_dictionary_import_read_history();
    array_unshift($entries, $entry);
    ll_tools_dictionary_import_write_history($entries);

    return (string) $entry['id'];
}

function ll_tools_dictionary_import_update_history_entry(string $history_id, array $updates): bool {
    $history_id = sanitize_text_field($history_id);
    if ($history_id === '') {
        return false;
    }

    $entries = ll_tools_dictionary_import_read_history();
    $updated = false;
    foreach ($entries as $index => $entry) {
        if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $history_id) {
            continue;
        }

        $entries[$index] = array_merge($entry, $updates);
        $updated = true;
        break;
    }

    if ($updated) {
        ll_tools_dictionary_import_write_history($entries);
    }

    return $updated;
}

function ll_tools_dictionary_import_get_history_entry(string $history_id): ?array {
    $history_id = sanitize_text_field($history_id);
    if ($history_id === '') {
        return null;
    }

    foreach (ll_tools_dictionary_import_read_history() as $entry) {
        if (is_array($entry) && (string) ($entry['id'] ?? '') === $history_id) {
            return $entry;
        }
    }

    return null;
}

function ll_tools_dictionary_import_get_recent_history_entries(): array {
    $entries = ll_tools_dictionary_import_read_history();
    if (empty($entries)) {
        return [];
    }

    $timezone = wp_timezone();
    $now = new DateTimeImmutable('now', $timezone);
    $start_of_today = $now->setTime(0, 0, 0);
    $start_of_yesterday = $start_of_today->modify('-1 day')->getTimestamp();
    $recent = [];
    $latest = null;

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ($latest === null) {
            $latest = $entry;
        }

        $finished_at = (int) ($entry['finished_at'] ?? 0);
        if ($finished_at > 0 && $finished_at >= $start_of_yesterday) {
            $recent[] = $entry;
        }
    }

    if (!empty($recent)) {
        return $recent;
    }

    return $latest !== null ? [$latest] : [];
}

function ll_tools_dictionary_entry_ensure_import_key_on_save(int $post_id, $post = null): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }
    if ($post instanceof WP_Post && $post->post_type !== 'll_dictionary_entry') {
        return;
    }

    ll_tools_get_dictionary_entry_import_key($post_id, true);
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_entry_ensure_import_key_on_save', 65, 2);
