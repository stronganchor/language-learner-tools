<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY', 'll_dictionary_entry_senses');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY', 'll_dictionary_entry_lookup_title');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY', 'll_dictionary_entry_lookup_translation');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY', 'll_dictionary_entry_search_index');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY', 'll_dictionary_entry_gender_number');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY', 'll_dictionary_entry_type');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY', 'll_dictionary_entry_parent');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY', 'll_dictionary_entry_needs_review');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY', 'll_dictionary_entry_page_number');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY', 'll_dictionary_entry_entry_lang');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY', 'll_dictionary_entry_def_lang');
}

/**
 * Normalize text for accent-insensitive dictionary search.
 */
function ll_tools_dictionary_normalize_search_text(string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'Â' => 'a', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e',
        'É' => 'e', 'È' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u',
        'ş' => 's', 'Ş' => 's', 'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g', 'İ' => 'i', 'ı' => 'i',
    ]);

    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }

    $value = function_exists('ll_tools_lowercase_for_language')
        ? ll_tools_lowercase_for_language($value, 'tr')
        : (function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $value = preg_replace('/[^\p{L}\p{N}\s\'"-]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string) $value);

    return trim((string) $value);
}

/**
 * Sanitize one imported dictionary sense row.
 *
 * @param array<string,mixed> $sense Raw sense payload.
 * @return array<string,string>
 */
function ll_tools_dictionary_sanitize_sense(array $sense): array {
    $clean = [
        'definition' => '',
        'gender_number' => '',
        'entry_type' => '',
        'parent' => '',
        'needs_review' => '',
        'page_number' => '',
        'entry_lang' => '',
        'def_lang' => '',
    ];

    foreach ($clean as $key => $unused) {
        $clean[$key] = trim(sanitize_text_field((string) ($sense[$key] ?? '')));
    }

    return $clean;
}

/**
 * Create a stable sense hash for de-duplication.
 *
 * @param array<string,string> $sense Sanitized sense.
 */
function ll_tools_dictionary_sense_hash(array $sense): string {
    $parts = [];
    foreach (['definition', 'gender_number', 'entry_type', 'parent', 'needs_review', 'page_number', 'entry_lang', 'def_lang'] as $key) {
        $parts[] = ll_tools_dictionary_normalize_search_text((string) ($sense[$key] ?? ''));
    }

    return md5(implode('|', $parts));
}

/**
 * Return sanitized structured senses for a dictionary entry.
 *
 * @return array<int,array<string,string>>
 */
function ll_tools_get_dictionary_entry_senses($entry_id): array {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return [];
    }

    $raw = get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, true);
    $senses = [];

    if (is_array($raw)) {
        foreach ($raw as $sense) {
            if (!is_array($sense)) {
                continue;
            }
            $clean = ll_tools_dictionary_sanitize_sense($sense);
            $has_content = false;
            foreach ($clean as $value) {
                if ($value !== '') {
                    $has_content = true;
                    break;
                }
            }
            if ($has_content) {
                $senses[] = $clean;
            }
        }
    }

    if (!empty($senses)) {
        return $senses;
    }

    $fallback_definition = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
    if ($fallback_definition === '') {
        $fallback_definition = trim(wp_strip_all_tags((string) get_post_field('post_content', $entry_id)));
    }

    $fallback = ll_tools_dictionary_sanitize_sense([
        'definition' => $fallback_definition,
        'gender_number' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY, true),
        'entry_type' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY, true),
        'parent' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY, true),
        'needs_review' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY, true),
        'page_number' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY, true),
        'entry_lang' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY, true),
        'def_lang' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY, true),
    ]);

    foreach ($fallback as $value) {
        if ($value !== '') {
            return [$fallback];
        }
    }

    return [];
}

/**
 * Merge structured senses without duplicating identical rows.
 *
 * @param array<int,array<string,string>> $existing Existing senses.
 * @param array<int,array<string,string>> $incoming Incoming senses.
 * @return array<int,array<string,string>>
 */
function ll_tools_dictionary_merge_senses(array $existing, array $incoming): array {
    $merged = [];
    $seen = [];

    foreach (array_merge($existing, $incoming) as $sense) {
        if (!is_array($sense)) {
            continue;
        }
        $clean = ll_tools_dictionary_sanitize_sense($sense);
        $hash = ll_tools_dictionary_sense_hash($clean);
        if (isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        $merged[] = $clean;
    }

    return $merged;
}

/**
 * Build a short translation summary from senses.
 *
 * @param array<int,array<string,string>> $senses Senses list.
 */
function ll_tools_dictionary_build_translation_summary(array $senses, string $fallback = ''): string {
    $definitions = [];
    $seen = [];

    foreach ($senses as $sense) {
        $definition = trim((string) ($sense['definition'] ?? ''));
        if ($definition === '') {
            continue;
        }
        $key = ll_tools_dictionary_entry_normalize_lookup_value($definition);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $definitions[] = $definition;
        if (count($definitions) >= 3) {
            break;
        }
    }

    if (!empty($definitions)) {
        return implode('; ', $definitions);
    }

    return trim((string) $fallback);
}

/**
 * Build plain post content from structured senses.
 *
 * @param array<int,array<string,string>> $senses Senses list.
 */
function ll_tools_dictionary_build_post_content_from_senses(array $senses): string {
    $lines = [];
    $seen = [];

    foreach ($senses as $sense) {
        $definition = trim((string) ($sense['definition'] ?? ''));
        if ($definition === '') {
            continue;
        }

        $extras = [];
        $entry_type = trim((string) ($sense['entry_type'] ?? ''));
        $gender = trim((string) ($sense['gender_number'] ?? ''));
        $parent = trim((string) ($sense['parent'] ?? ''));
        $page_number = trim((string) ($sense['page_number'] ?? ''));

        if ($entry_type !== '') {
            $extras[] = $entry_type;
        }
        if ($gender !== '') {
            $extras[] = $gender;
        }
        if ($parent !== '') {
            /* translators: %s: parent dictionary headword. */
            $extras[] = sprintf(__('Parent: %s', 'll-tools-text-domain'), $parent);
        }
        if ($page_number !== '') {
            /* translators: %s: source page number. */
            $extras[] = sprintf(__('Page %s', 'll-tools-text-domain'), $page_number);
        }

        $line = $definition;
        if (!empty($extras)) {
            $line .= ' (' . implode(', ', $extras) . ')';
        }

        $key = ll_tools_dictionary_entry_normalize_lookup_value($line);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $lines[] = $line;
    }

    return implode("\n\n", $lines);
}

/**
 * Build a normalized full-text search index string.
 *
 * @param array<int,array<string,string>> $senses Senses list.
 */
function ll_tools_dictionary_build_search_index(string $title, string $translation, string $content, array $senses): string {
    $parts = [$title, $translation, $content];

    foreach ($senses as $sense) {
        foreach (['definition', 'gender_number', 'entry_type', 'parent', 'page_number', 'entry_lang', 'def_lang'] as $key) {
            $value = trim((string) ($sense[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
    }

    return ll_tools_dictionary_normalize_search_text(implode(' ', $parts));
}

/**
 * Keep dictionary search meta in sync after save/import.
 */
function ll_tools_dictionary_refresh_entry_search_meta($post_id, $post = null, $update = false): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }
    if ($post instanceof WP_Post && $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!($post instanceof WP_Post) && get_post_type($post_id) !== 'll_dictionary_entry') {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    $title = trim((string) get_the_title($post_id));
    $content = trim(wp_strip_all_tags((string) get_post_field('post_content', $post_id)));
    $senses = ll_tools_get_dictionary_entry_senses($post_id);
    $stored_translation = trim((string) get_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
    $resolved_translation = ll_tools_dictionary_build_translation_summary($senses, $stored_translation);

    if ($resolved_translation !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $resolved_translation);
    } elseif ($stored_translation !== '') {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    if (!empty($senses)) {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $senses);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY);
    }

    $lookup_title = ll_tools_dictionary_entry_normalize_lookup_value($title);
    $lookup_translation = ll_tools_dictionary_entry_normalize_lookup_value($resolved_translation);
    $search_index = ll_tools_dictionary_build_search_index($title, $resolved_translation, $content, $senses);

    if ($lookup_title !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, $lookup_title);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY);
    }

    if ($lookup_translation !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY, $lookup_translation);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY);
    }

    if ($search_index !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, $search_index);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY);
    }
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_refresh_entry_search_meta', 50, 3);

/**
 * Resolve a part-of-speech slug from imported entry-type text.
 */
function ll_tools_dictionary_resolve_pos_slug_from_entry_type(string $entry_type): string {
    $entry_type = trim((string) $entry_type);
    if ($entry_type === '') {
        return '';
    }

    $normalized = sanitize_title($entry_type);
    $map = [
        'n' => 'noun',
        'noun' => 'noun',
        'v' => 'verb',
        'verb' => 'verb',
        'adj' => 'adjective',
        'adjective' => 'adjective',
        'adv' => 'adverb',
        'adverb' => 'adverb',
        'pron' => 'pronoun',
        'pronoun' => 'pronoun',
        'interj' => 'interjection',
        'interjection' => 'interjection',
        'prep' => 'preposition',
        'preposition' => 'preposition',
        'postp' => 'postposition',
        'postposition' => 'postposition',
        'conj' => 'conjunction',
        'conjunction' => 'conjunction',
    ];

    if (isset($map[$normalized])) {
        $normalized = $map[$normalized];
    }

    $term = get_term_by('slug', $normalized, 'part_of_speech');
    if ($term && !is_wp_error($term)) {
        return (string) $term->slug;
    }

    $term = get_term_by('name', $entry_type, 'part_of_speech');
    if ($term && !is_wp_error($term)) {
        return (string) $term->slug;
    }

    return '';
}

/**
 * Normalize one import row.
 *
 * @param array<string|int,mixed> $row Raw import row.
 * @param array<string,mixed>     $defaults Default values.
 * @return array<string,string>
 */
function ll_tools_dictionary_prepare_import_row(array $row, array $defaults = []): array {
    $prepared = [
        'entry' => '',
        'definition' => '',
        'gender_number' => '',
        'entry_type' => '',
        'parent' => '',
        'needs_review' => '',
        'page_number' => '',
        'entry_lang' => '',
        'def_lang' => '',
    ];

    $prepared['entry'] = trim(sanitize_text_field((string) ($row['entry'] ?? $row[0] ?? '')));
    $prepared['definition'] = trim(sanitize_text_field((string) ($row['definition'] ?? $row[1] ?? '')));
    $prepared['gender_number'] = trim(sanitize_text_field((string) ($row['gender_number'] ?? $row[2] ?? '')));
    $prepared['entry_type'] = trim(sanitize_text_field((string) ($row['entry_type'] ?? $row[3] ?? '')));
    $prepared['parent'] = trim(sanitize_text_field((string) ($row['parent'] ?? $row[4] ?? '')));
    $prepared['needs_review'] = trim(sanitize_text_field((string) ($row['needs_review'] ?? $row[5] ?? '')));
    $prepared['page_number'] = trim(sanitize_text_field((string) ($row['page_number'] ?? $row[6] ?? '')));
    $prepared['entry_lang'] = trim(sanitize_text_field((string) ($row['entry_lang'] ?? $defaults['entry_lang'] ?? '')));
    $prepared['def_lang'] = trim(sanitize_text_field((string) ($row['def_lang'] ?? $defaults['def_lang'] ?? '')));

    return $prepared;
}

/**
 * Decide whether an import row should be skipped because of review flags.
 *
 * @param array<string,string> $row Prepared import row.
 */
function ll_tools_dictionary_should_skip_row_for_review(array $row, bool $skip_flagged = true): bool {
    if (!$skip_flagged) {
        return false;
    }

    $flag = trim((string) ($row['needs_review'] ?? ''));
    if ($flag === '' || $flag === '0' || $flag === '1') {
        return false;
    }

    return true;
}

/**
 * Find one dictionary entry by exact title within an optional word set.
 */
function ll_tools_dictionary_find_entry_by_title(string $title, int $wordset_id = 0): int {
    global $wpdb;

    $title = trim((string) $title);
    if ($title === '') {
        return 0;
    }

    $lookup = ll_tools_dictionary_entry_normalize_lookup_value($title);
    $status_placeholders = implode(', ', array_fill(0, 5, '%s'));
    $params = ['publish', 'draft', 'pending', 'private', 'future', $lookup, $title];
    $sql = "
        SELECT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} lookup_title
            ON lookup_title.post_id = p.ID
           AND lookup_title.meta_key = %s
        LEFT JOIN {$wpdb->postmeta} wordset_meta
            ON wordset_meta.post_id = p.ID
           AND wordset_meta.meta_key = %s
        WHERE p.post_type = 'll_dictionary_entry'
          AND p.post_status IN ({$status_placeholders})
          AND (
                lookup_title.meta_value = %s
                OR (lookup_title.meta_value IS NULL AND p.post_title = %s)
          )
    ";

    $params = array_merge([
        LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY,
        LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY,
    ], $params);

    if ($wordset_id > 0) {
        $sql .= " AND CAST(COALESCE(wordset_meta.meta_value, '0') AS UNSIGNED) = %d";
        $params[] = $wordset_id;
    }

    $sql .= " ORDER BY p.ID ASC LIMIT 1";

    return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
}

/**
 * Return the first non-empty value from a senses list.
 *
 * @param array<int,array<string,string>> $senses Senses list.
 */
function ll_tools_dictionary_get_primary_sense_value(array $senses, string $field): string {
    foreach ($senses as $sense) {
        $value = trim((string) ($sense[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Create or update one dictionary entry from grouped import rows.
 *
 * @param array<int,array<string,string>> $rows Grouped prepared rows.
 * @param array<string,mixed>             $options Import options.
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_upsert_entry_from_rows(array $rows, array $options = []) {
    $title = trim((string) (($rows[0]['entry'] ?? '') ?: ''));
    if ($title === '') {
        return new WP_Error('ll_tools_dictionary_missing_title', __('Dictionary entry title cannot be empty.', 'll-tools-text-domain'));
    }

    $wordset_id = max(0, (int) ($options['wordset_id'] ?? 0));
    $replace_existing_senses = !empty($options['replace_existing_senses']);
    $entry_id = max(0, (int) ($options['entry_id'] ?? 0));
    if ($entry_id <= 0) {
        $entry_id = ll_tools_dictionary_find_entry_by_title($title, $wordset_id);
    }

    $existing_senses = [];
    if ($entry_id > 0 && !$replace_existing_senses) {
        $existing_senses = ll_tools_get_dictionary_entry_senses($entry_id);
    }

    $incoming_senses = [];
    foreach ($rows as $row) {
        $sense = ll_tools_dictionary_sanitize_sense([
            'definition' => (string) ($row['definition'] ?? ''),
            'gender_number' => (string) ($row['gender_number'] ?? ''),
            'entry_type' => (string) ($row['entry_type'] ?? ''),
            'parent' => (string) ($row['parent'] ?? ''),
            'needs_review' => (string) ($row['needs_review'] ?? ''),
            'page_number' => (string) ($row['page_number'] ?? ''),
            'entry_lang' => (string) ($row['entry_lang'] ?? ''),
            'def_lang' => (string) ($row['def_lang'] ?? ''),
        ]);
        $incoming_senses[] = $sense;
    }

    $merged_senses = ll_tools_dictionary_merge_senses($existing_senses, $incoming_senses);
    $content = ll_tools_dictionary_build_post_content_from_senses($merged_senses);
    $translation = ll_tools_dictionary_build_translation_summary(
        $merged_senses,
        ($entry_id > 0 ? (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true) : '')
    );

    $postarr = [
        'post_type' => 'll_dictionary_entry',
        'post_title' => $title,
        'post_status' => 'publish',
        'post_content' => $content,
    ];
    if ($entry_id > 0) {
        $postarr['ID'] = $entry_id;
    }

    $saved_entry_id = wp_insert_post($postarr, true);
    if (is_wp_error($saved_entry_id) || (int) $saved_entry_id <= 0) {
        return new WP_Error(
            'll_tools_dictionary_entry_save_failed',
            is_wp_error($saved_entry_id)
                ? $saved_entry_id->get_error_message()
                : __('Unable to save dictionary entry.', 'll-tools-text-domain')
        );
    }

    $saved_entry_id = (int) $saved_entry_id;
    update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $merged_senses);

    if ($translation !== '') {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
    } else {
        delete_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    if ($wordset_id > 0) {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
    }

    $primary_entry_type = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'entry_type');
    $primary_page_number = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'page_number');
    $primary_parent = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'parent');
    $primary_gender = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'gender_number');
    $primary_entry_lang = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'entry_lang');
    $primary_def_lang = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'def_lang');
    $primary_review = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'needs_review');
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
        } elseif ($replace_existing_senses) {
            delete_post_meta($saved_entry_id, $meta_key);
        }
    }

    ll_tools_dictionary_refresh_entry_search_meta($saved_entry_id);

    return [
        'entry_id' => $saved_entry_id,
        'entry_title' => $title,
        'created' => ($entry_id <= 0),
        'updated' => ($entry_id > 0),
        'sense_count' => count($merged_senses),
    ];
}

/**
 * Import prepared dictionary rows into ll_dictionary_entry posts.
 *
 * @param array<int,array<string|int,mixed>> $rows Raw rows.
 * @param array<string,mixed>                $options Import options.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_rows(array $rows, array $options = []): array {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $skip_flagged = !empty($options['skip_review_rows']);
    $defaults = [
        'entry_lang' => trim(sanitize_text_field((string) ($options['entry_lang'] ?? ''))),
        'def_lang' => trim(sanitize_text_field((string) ($options['def_lang'] ?? ''))),
    ];

    $summary = [
        'rows_total' => count($rows),
        'rows_grouped' => 0,
        'rows_skipped_empty' => 0,
        'rows_skipped_review' => 0,
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
    ];

    $grouped_rows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $summary['rows_skipped_empty']++;
            continue;
        }

        $prepared = ll_tools_dictionary_prepare_import_row($row, $defaults);
        if ($prepared['entry'] === '') {
            $summary['rows_skipped_empty']++;
            continue;
        }
        if (ll_tools_dictionary_should_skip_row_for_review($prepared, $skip_flagged)) {
            $summary['rows_skipped_review']++;
            continue;
        }

        $group_key = max(0, (int) ($options['wordset_id'] ?? 0))
            . '|'
            . ll_tools_dictionary_entry_normalize_lookup_value($prepared['entry']);
        if (!isset($grouped_rows[$group_key])) {
            $grouped_rows[$group_key] = [];
        }
        $grouped_rows[$group_key][] = $prepared;
    }

    $summary['rows_grouped'] = count($grouped_rows);
    foreach ($grouped_rows as $group_rows) {
        $result = ll_tools_dictionary_upsert_entry_from_rows($group_rows, $options);
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

    $summary['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', $summary['entry_ids']))));
    $summary['error_count'] = count($summary['errors']);

    return $summary;
}

/**
 * Name of the legacy one-off dictionary importer table.
 */
function ll_tools_dictionary_get_legacy_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'dictionary_entries';
}

/**
 * Check whether the legacy raw dictionary table exists.
 */
function ll_tools_dictionary_legacy_table_exists(): bool {
    global $wpdb;
    $table = ll_tools_dictionary_get_legacy_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $exists === $table;
}

/**
 * Import the legacy raw dictionary table in batches.
 *
 * @param array<string,mixed> $options Import options.
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_legacy_table(array $options = []) {
    global $wpdb;

    if (!ll_tools_dictionary_legacy_table_exists()) {
        return new WP_Error('ll_tools_dictionary_legacy_table_missing', __('Legacy dictionary table not found.', 'll-tools-text-domain'));
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $table = ll_tools_dictionary_get_legacy_table_name();
    $batch_size = max(50, min(1000, (int) ($options['batch_size'] ?? 500)));
    $offset = 0;
    $aggregate = [
        'rows_total' => 0,
        'rows_grouped' => 0,
        'rows_skipped_empty' => 0,
        'rows_skipped_review' => 0,
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
        'legacy_batches' => 0,
    ];

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entry, definition, gender_number, entry_type, parent, needs_review, page_number, entry_lang, def_lang
                 FROM {$table}
                 ORDER BY entry ASC, id ASC
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            break;
        }

        $aggregate['legacy_batches']++;
        $batch_summary = ll_tools_dictionary_import_rows($rows, $options);
        $aggregate['rows_total'] += (int) ($batch_summary['rows_total'] ?? 0);
        $aggregate['rows_grouped'] += (int) ($batch_summary['rows_grouped'] ?? 0);
        $aggregate['rows_skipped_empty'] += (int) ($batch_summary['rows_skipped_empty'] ?? 0);
        $aggregate['rows_skipped_review'] += (int) ($batch_summary['rows_skipped_review'] ?? 0);
        $aggregate['entries_created'] += (int) ($batch_summary['entries_created'] ?? 0);
        $aggregate['entries_updated'] += (int) ($batch_summary['entries_updated'] ?? 0);
        $aggregate['entry_ids'] = array_merge($aggregate['entry_ids'], (array) ($batch_summary['entry_ids'] ?? []));
        $aggregate['errors'] = array_merge($aggregate['errors'], (array) ($batch_summary['errors'] ?? []));

        $offset += $batch_size;
    } while (!empty($rows));

    $aggregate['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', $aggregate['entry_ids']))));
    $aggregate['error_count'] = count($aggregate['errors']);

    return $aggregate;
}

/**
 * Build front-end preview data for words linked to a dictionary entry.
 *
 * @return array<int,array{word_text:string,translation_text:string}>
 */
function ll_tools_dictionary_get_linked_word_previews(int $entry_id, int $limit = 4): array {
    $entry_id = (int) $entry_id;
    $limit = (int) $limit;
    if ($entry_id <= 0 || $limit <= 0 || !function_exists('ll_tools_get_dictionary_entry_word_ids')) {
        return [];
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, $limit);
    $items = [];

    foreach ($word_ids as $word_id) {
        $display = function_exists('ll_tools_get_word_display_with_translation')
            ? ll_tools_get_word_display_with_translation((int) $word_id)
            : ['word_text' => trim((string) get_the_title((int) $word_id)), 'translation_text' => ''];
        $word_text = trim((string) ($display['word_text'] ?? ''));
        $translation_text = trim((string) ($display['translation_text'] ?? ''));
        if ($word_text === '') {
            continue;
        }
        $items[] = [
            'word_text' => $word_text,
            'translation_text' => $translation_text,
        ];
    }

    return $items;
}

/**
 * Build a structured display payload for one dictionary entry.
 *
 * @return array<string,mixed>
 */
function ll_tools_dictionary_get_entry_data(int $entry_id, int $sense_limit = 3, int $linked_word_limit = 4): array {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return [];
    }

    $title = trim((string) get_the_title($entry_id));
    if ($title === '') {
        $title = __('(no title)', 'll-tools-text-domain');
    }

    $senses = ll_tools_get_dictionary_entry_senses($entry_id);
    $translation = function_exists('ll_tools_get_dictionary_entry_translation')
        ? ll_tools_get_dictionary_entry_translation($entry_id)
        : ll_tools_dictionary_build_translation_summary($senses, '');
    if ($translation === '') {
        $translation = ll_tools_dictionary_build_translation_summary($senses, trim(wp_strip_all_tags((string) get_post_field('post_content', $entry_id))));
    }

    $wordset_id = function_exists('ll_tools_get_dictionary_entry_wordset_id')
        ? (int) ll_tools_get_dictionary_entry_wordset_id($entry_id)
        : 0;
    $wordset_name = '';
    if ($wordset_id > 0) {
        $wordset = get_term($wordset_id, 'wordset');
        if ($wordset && !is_wp_error($wordset)) {
            $wordset_name = (string) $wordset->name;
        }
    }

    $pos_slug = function_exists('ll_tools_get_dictionary_entry_primary_pos_slug')
        ? ll_tools_get_dictionary_entry_primary_pos_slug($entry_id)
        : '';
    $pos_label = '';
    if ($pos_slug !== '') {
        $term = get_term_by('slug', $pos_slug, 'part_of_speech');
        if ($term && !is_wp_error($term)) {
            $pos_label = (string) $term->name;
        }
    }

    $entry_type = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY, true));
    if ($entry_type === '') {
        $entry_type = ll_tools_dictionary_get_primary_sense_value($senses, 'entry_type');
    }

    $page_number = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY, true));
    if ($page_number === '') {
        $page_number = ll_tools_dictionary_get_primary_sense_value($senses, 'page_number');
    }

    $linked_word_count = function_exists('ll_tools_count_dictionary_entry_words')
        ? (int) ll_tools_count_dictionary_entry_words($entry_id)
        : 0;

    return [
        'id' => $entry_id,
        'title' => $title,
        'translation' => $translation,
        'entry_type' => $entry_type,
        'page_number' => $page_number,
        'wordset_id' => $wordset_id,
        'wordset_name' => $wordset_name,
        'pos_slug' => $pos_slug,
        'pos_label' => $pos_label,
        'linked_word_count' => $linked_word_count,
        'linked_words' => ll_tools_dictionary_get_linked_word_previews($entry_id, $linked_word_limit),
        'senses' => array_slice($senses, 0, max(1, $sense_limit)),
        'sense_count' => count($senses),
    ];
}

/**
 * Query dictionary entries with ranked search and pagination.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_query_entries(array $args = []): array {
    global $wpdb;

    $search = trim((string) ($args['search'] ?? ''));
    $letter = trim((string) ($args['letter'] ?? ''));
    if ($letter !== '' && function_exists('mb_substr')) {
        $letter = mb_substr($letter, 0, 1, 'UTF-8');
    } elseif ($letter !== '') {
        $letter = substr($letter, 0, 1);
    }

    $page = max(1, (int) ($args['page'] ?? 1));
    $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
    $wordset_id = max(0, (int) ($args['wordset_id'] ?? 0));
    $pos_slug = sanitize_title((string) ($args['pos_slug'] ?? ''));
    $sense_limit = max(1, min(8, (int) ($args['sense_limit'] ?? 3)));
    $linked_word_limit = max(0, min(8, (int) ($args['linked_word_limit'] ?? 4)));
    $statuses = array_values(array_filter(array_map('sanitize_key', (array) ($args['post_status'] ?? ['publish']))));
    if (empty($statuses)) {
        $statuses = ['publish'];
    }

    $joins = "
        LEFT JOIN {$wpdb->postmeta} translation
               ON translation.post_id = p.ID
              AND translation.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY) . "'
        LEFT JOIN {$wpdb->postmeta} wordset_meta
               ON wordset_meta.post_id = p.ID
              AND wordset_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY) . "'
        LEFT JOIN {$wpdb->postmeta} pos_meta
               ON pos_meta.post_id = p.ID
              AND pos_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY) . "'
        LEFT JOIN {$wpdb->postmeta} lookup_title
               ON lookup_title.post_id = p.ID
              AND lookup_title.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY) . "'
        LEFT JOIN {$wpdb->postmeta} lookup_translation
               ON lookup_translation.post_id = p.ID
              AND lookup_translation.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY) . "'
        LEFT JOIN {$wpdb->postmeta} search_index
               ON search_index.post_id = p.ID
              AND search_index.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY) . "'
    ";

    $where = ["p.post_type = 'll_dictionary_entry'"];
    $params = [];

    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $where[] = "p.post_status IN ({$status_placeholders})";
    $params = array_merge($params, $statuses);

    if ($wordset_id > 0) {
        $where[] = "CAST(COALESCE(wordset_meta.meta_value, '0') AS UNSIGNED) = %d";
        $params[] = $wordset_id;
    }

    if ($pos_slug !== '') {
        $where[] = "pos_meta.meta_value = %s";
        $params[] = $pos_slug;
    }

    $order_sql = 'p.post_title ASC';
    $order_params = [];

    if ($search !== '') {
        $lookup = ll_tools_dictionary_entry_normalize_lookup_value($search);
        $search_norm = ll_tools_dictionary_normalize_search_text($search);
        $contains_raw = '%' . $wpdb->esc_like($search) . '%';
        $contains_lookup = '%' . $wpdb->esc_like($lookup) . '%';
        $contains_norm = '%' . $wpdb->esc_like($search_norm) . '%';

        $where[] = '(
            p.post_title LIKE %s
            OR translation.meta_value LIKE %s
            OR p.post_content LIKE %s
            OR lookup_title.meta_value LIKE %s
            OR lookup_translation.meta_value LIKE %s
            OR search_index.meta_value LIKE %s
        )';
        $params = array_merge($params, [
            $contains_raw,
            $contains_raw,
            $contains_raw,
            $contains_lookup,
            $contains_lookup,
            $contains_norm,
        ]);

        $prefix_lookup = $wpdb->esc_like($lookup) . '%';
        $prefix_raw = $wpdb->esc_like($search) . '%';
        $order_sql = "
            CASE
                WHEN lookup_title.meta_value = %s THEN 0
                WHEN lookup_translation.meta_value = %s THEN 1
                WHEN lookup_title.meta_value LIKE %s THEN 2
                WHEN lookup_translation.meta_value LIKE %s THEN 3
                WHEN p.post_title LIKE %s THEN 4
                WHEN translation.meta_value LIKE %s THEN 5
                WHEN search_index.meta_value LIKE %s THEN 6
                ELSE 7
            END,
            p.post_title ASC
        ";
        $order_params = [
            $lookup,
            $lookup,
            $prefix_lookup,
            $prefix_lookup,
            $prefix_raw,
            $prefix_raw,
            $contains_norm,
        ];
    } elseif ($letter !== '') {
        $where[] = 'p.post_title LIKE %s';
        $params[] = $wpdb->esc_like($letter) . '%';
    }

    $where_sql = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins} WHERE {$where_sql}";
    $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
    $total_pages = max(1, (int) ceil($total / $per_page));

    if ($total === 0) {
        return [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $per_page,
            'total_pages' => 1,
            'search' => $search,
            'letter' => $letter,
            'wordset_id' => $wordset_id,
            'pos_slug' => $pos_slug,
        ];
    }

    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;

    $query_sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        {$joins}
        WHERE {$where_sql}
        ORDER BY {$order_sql}
        LIMIT %d OFFSET %d
    ";
    $query_params = array_merge($params, $order_params, [$per_page, $offset]);
    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($query_sql, $query_params)))));

    if (!empty($ids)) {
        update_postmeta_cache($ids);
    }

    $items = [];
    foreach ($ids as $entry_id) {
        $item = ll_tools_dictionary_get_entry_data($entry_id, $sense_limit, $linked_word_limit);
        if (!empty($item)) {
            $items[] = $item;
        }
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'search' => $search,
        'letter' => $letter,
        'wordset_id' => $wordset_id,
        'pos_slug' => $pos_slug,
    ];
}

/**
 * Collect available browse letters for the current dictionary scope.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_available_letters(int $wordset_id = 0): array {
    global $wpdb;

    $joins = '';
    $where = [
        "p.post_type = 'll_dictionary_entry'",
        "p.post_status = 'publish'",
        "p.post_title <> ''",
    ];
    $params = [];

    if ($wordset_id > 0) {
        $joins .= "
            LEFT JOIN {$wpdb->postmeta} wordset_meta
                   ON wordset_meta.post_id = p.ID
                  AND wordset_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY) . "'
        ";
        $where[] = "CAST(COALESCE(wordset_meta.meta_value, '0') AS UNSIGNED) = %d";
        $params[] = $wordset_id;
    }

    $sql = "
        SELECT DISTINCT SUBSTRING(p.post_title, 1, 1) AS first_letter
        FROM {$wpdb->posts} p
        {$joins}
        WHERE " . implode(' AND ', $where) . "
    ";

    $rows = $params
        ? (array) $wpdb->get_col($wpdb->prepare($sql, $params))
        : (array) $wpdb->get_col($sql);

    $language = ($wordset_id > 0 && function_exists('ll_tools_get_wordset_title_language_label'))
        ? (string) ll_tools_get_wordset_title_language_label([$wordset_id])
        : '';

    $letters = [];
    foreach ($rows as $row) {
        $letter = trim((string) $row);
        if ($letter === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $letter = mb_substr($letter, 0, 1, 'UTF-8');
        } else {
            $letter = substr($letter, 0, 1);
        }
        if ($letter === '' || preg_match('/[\p{L}\p{N}]/u', $letter) !== 1) {
            continue;
        }
        $letter = function_exists('ll_tools_uppercase_first_char_for_language')
            ? ll_tools_uppercase_first_char_for_language($letter, $language)
            : (function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter));
        $letters[$letter] = true;
    }

    $letters = array_keys($letters);
    usort($letters, static function (string $left, string $right): int {
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left, $right)
            : strnatcasecmp($left, $right);
    });

    return $letters;
}

/**
 * Collect available part-of-speech filter options for the current scope.
 *
 * @return array<int,array{slug:string,label:string}>
 */
function ll_tools_dictionary_get_pos_filter_options(int $wordset_id = 0): array {
    global $wpdb;

    $joins = "
        INNER JOIN {$wpdb->postmeta} pos_meta
                ON pos_meta.post_id = p.ID
               AND pos_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY) . "'
    ";
    $where = [
        "p.post_type = 'll_dictionary_entry'",
        "p.post_status = 'publish'",
        "pos_meta.meta_value <> ''",
    ];
    $params = [];

    if ($wordset_id > 0) {
        $joins .= "
            LEFT JOIN {$wpdb->postmeta} wordset_meta
                   ON wordset_meta.post_id = p.ID
                  AND wordset_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY) . "'
        ";
        $where[] = "CAST(COALESCE(wordset_meta.meta_value, '0') AS UNSIGNED) = %d";
        $params[] = $wordset_id;
    }

    $sql = "
        SELECT DISTINCT pos_meta.meta_value
        FROM {$wpdb->posts} p
        {$joins}
        WHERE " . implode(' AND ', $where) . '
        ORDER BY pos_meta.meta_value ASC
    ';

    $rows = $params
        ? (array) $wpdb->get_col($wpdb->prepare($sql, $params))
        : (array) $wpdb->get_col($sql);

    $options = [];
    foreach ($rows as $slug) {
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            continue;
        }
        $term = get_term_by('slug', $slug, 'part_of_speech');
        $label = ($term && !is_wp_error($term))
            ? (string) $term->name
            : $slug;
        $options[] = [
            'slug' => $slug,
            'label' => $label,
        ];
    }

    usort($options, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    return $options;
}

/**
 * Compare stored/requested language values loosely by label or code.
 */
function ll_tools_dictionary_language_matches(string $stored, string $requested): bool {
    $requested = trim((string) $requested);
    if ($requested === '' || strtolower($requested) === 'auto') {
        return true;
    }

    $stored = trim((string) $stored);
    if ($stored === '') {
        return true;
    }

    $normalize = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('ll_tools_resolve_language_code_from_label')) {
            $resolved = (string) ll_tools_resolve_language_code_from_label($value, 'lower');
            if ($resolved !== '') {
                return $resolved;
            }
        }
        return strtolower($value);
    };

    return $normalize($stored) === $normalize($requested);
}

/**
 * Compute a small lookup score for one text candidate.
 */
function ll_tools_dictionary_lookup_text_score(string $candidate, string $term): int {
    $candidate_lookup = ll_tools_dictionary_entry_normalize_lookup_value($candidate);
    $term_lookup = ll_tools_dictionary_entry_normalize_lookup_value($term);
    $candidate_norm = ll_tools_dictionary_normalize_search_text($candidate);
    $term_norm = ll_tools_dictionary_normalize_search_text($term);

    if ($candidate_lookup !== '' && $candidate_lookup === $term_lookup) {
        return 0;
    }
    if ($candidate_norm !== '' && $candidate_norm === $term_norm) {
        return 1;
    }
    if ($term_lookup !== '' && strpos($candidate_lookup, $term_lookup) === 0) {
        return 2;
    }
    if ($term_norm !== '' && strpos($candidate_norm, $term_norm) === 0) {
        return 3;
    }
    if ($term_lookup !== '' && strpos($candidate_lookup, $term_lookup) !== false) {
        return 4;
    }
    if ($term_norm !== '' && strpos($candidate_norm, $term_norm) !== false) {
        return 5;
    }

    return 99;
}

/**
 * Lookup the best dictionary translation suggestion from ll_dictionary_entry posts.
 */
function ll_tools_dictionary_lookup_best($word, $source_lang, $target_lang, $reverse = false): ?string {
    $term = trim((string) $word);
    if ($term === '' || !function_exists('ll_tools_dictionary_query_entries')) {
        return null;
    }

    $results = ll_tools_dictionary_query_entries([
        'search' => $term,
        'per_page' => 40,
        'page' => 1,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'sense_limit' => 8,
        'linked_word_limit' => 0,
    ]);

    $items = (array) ($results['items'] ?? []);
    if (empty($items)) {
        return null;
    }

    $best_score = 999;
    $best_value = null;

    foreach ($items as $item) {
        $entry_id = (int) ($item['id'] ?? 0);
        if ($entry_id <= 0) {
            continue;
        }

        $entry_lang = (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY, true);
        $def_lang = (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY, true);
        if ($reverse) {
            if (!ll_tools_dictionary_language_matches($entry_lang, $target_lang) || !ll_tools_dictionary_language_matches($def_lang, $source_lang)) {
                continue;
            }
        } else {
            if (!ll_tools_dictionary_language_matches($entry_lang, $source_lang) || !ll_tools_dictionary_language_matches($def_lang, $target_lang)) {
                continue;
            }
        }

        $score = 99;
        $candidate_value = '';

        if ($reverse) {
            $definitions = [];
            foreach (ll_tools_get_dictionary_entry_senses($entry_id) as $sense) {
                $definition = trim((string) ($sense['definition'] ?? ''));
                if ($definition !== '') {
                    $definitions[] = $definition;
                }
            }
            $definitions[] = (string) ($item['translation'] ?? '');
            $definitions = array_values(array_unique(array_filter(array_map('strval', $definitions))));

            foreach ($definitions as $definition) {
                $def_score = ll_tools_dictionary_lookup_text_score($definition, $term);
                if ($def_score < $score) {
                    $score = $def_score;
                    $candidate_value = (string) ($item['title'] ?? '');
                }
            }
        } else {
            $score = ll_tools_dictionary_lookup_text_score((string) ($item['title'] ?? ''), $term);
            $candidate_value = (string) ($item['translation'] ?? '');
        }

        if ($candidate_value === '') {
            continue;
        }

        if ($score < $best_score) {
            $best_score = $score;
            $best_value = $candidate_value;
        }
    }

    return ($best_value !== null && trim((string) $best_value) !== '') ? trim((string) $best_value) : null;
}

/**
 * Show imported senses on the dictionary entry edit screen.
 */
function ll_tools_dictionary_entry_add_imported_senses_metabox(): void {
    add_meta_box(
        'll-tools-dictionary-entry-imported-senses',
        __('Imported Senses', 'll-tools-text-domain'),
        'll_tools_dictionary_entry_render_imported_senses_metabox',
        'll_dictionary_entry',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes_ll_dictionary_entry', 'll_tools_dictionary_entry_add_imported_senses_metabox');

/**
 * Render imported senses metabox content.
 */
function ll_tools_dictionary_entry_render_imported_senses_metabox($post): void {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $senses = ll_tools_get_dictionary_entry_senses((int) $post->ID);
    if (empty($senses)) {
        echo '<p>' . esc_html__('No structured senses have been imported for this entry yet.', 'll-tools-text-domain') . '</p>';
        return;
    }

    echo '<div class="widefat striped" style="border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">';
    echo '<table class="widefat striped" style="border:0;margin:0;">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Definition', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Type', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Gender/Number', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Parent', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Page', 'll-tools-text-domain') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($senses as $sense) {
        echo '<tr>';
        echo '<td>' . esc_html((string) ($sense['definition'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['entry_type'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['gender_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['parent'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['page_number'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
