<?php
if (!defined('WPINC')) { die; }

require_once __DIR__ . '/../legacy-content-lesson-contracts.php';

if (!defined('LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION')) {
    define('LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION', 'll_tools_legacy_lesson_completion_audit');
}

function ll_tools_legacy_lesson_batch_limit($raw): int {
    return max(1, min(500, absint($raw ?: 100)));
}

function ll_tools_legacy_lesson_retained_source_contract_error(string $reason = ''): WP_Error {
    return new WP_Error(
        'legacy_lesson_retained_source_contract_invalid',
        __(
            'Retained-source migration requires the lessons phase, one to 20 explicit source IDs, --status=publish, a limit of 20 or less, no category scope, and show-in-mix disabled.',
            'll-tools-text-domain'
        ),
        ['reason' => sanitize_key($reason)]
    );
}

/**
 * Validate the deliberately narrow CLI/batch contract for retained sources.
 *
 * @return true|WP_Error
 */
function ll_tools_validate_legacy_lesson_retained_source_args(array $args) {
    if (empty($args['retained_source'])) {
        return true;
    }

    if (sanitize_key((string) ($args['phase'] ?? 'lessons')) !== 'lessons') {
        return ll_tools_legacy_lesson_retained_source_contract_error('phase');
    }
    if (!array_key_exists('status', $args)
        || sanitize_key((string) $args['status']) !== 'publish'
    ) {
        return ll_tools_legacy_lesson_retained_source_contract_error('status');
    }
    if (!empty($args['category_ids'])) {
        return ll_tools_legacy_lesson_retained_source_contract_error('categories');
    }
    if (!array_key_exists('show_in_mix', $args) || !empty($args['show_in_mix'])) {
        return ll_tools_legacy_lesson_retained_source_contract_error('show_in_mix');
    }
    if (array_key_exists('show_in_mix_was_explicit', $args)
        && (
            empty($args['show_in_mix_was_explicit'])
            || trim((string) ($args['show_in_mix_raw'] ?? '')) !== '0'
        )
    ) {
        return ll_tools_legacy_lesson_retained_source_contract_error('show_in_mix');
    }

    $raw_source_ids = $args['source_ids'] ?? [];
    if (!is_array($raw_source_ids)) {
        $raw_source_ids = preg_split('/[\s,]+/', trim((string) $raw_source_ids));
    }
    $source_ids = [];
    foreach ((array) $raw_source_ids as $raw_source_id) {
        if (!is_scalar($raw_source_id)) {
            return ll_tools_legacy_lesson_retained_source_contract_error('source_ids');
        }
        $raw_source_id = trim((string) $raw_source_id);
        if ($raw_source_id === ''
            || !ctype_digit($raw_source_id)
            || absint($raw_source_id) <= 0
        ) {
            return ll_tools_legacy_lesson_retained_source_contract_error('source_ids');
        }
        $source_ids[absint($raw_source_id)] = true;
    }
    if (count($source_ids) < 1 || count($source_ids) > 20) {
        return ll_tools_legacy_lesson_retained_source_contract_error('source_ids');
    }

    $limit = isset($args['limit']) ? absint($args['limit']) : 100;
    if ($limit < 1 || $limit > 20) {
        return ll_tools_legacy_lesson_retained_source_contract_error('limit');
    }
    if (array_key_exists('limit_was_explicit', $args)
        && (
            empty($args['limit_was_explicit'])
            || !ctype_digit(trim((string) ($args['limit_raw'] ?? '')))
            || absint($args['limit_raw']) !== $limit
        )
    ) {
        return ll_tools_legacy_lesson_retained_source_contract_error('limit');
    }

    return true;
}

/**
 * Normalize a single serialized-array meta value without turning WordPress's
 * absent empty-string sentinel into an array containing one empty item.
 */
function ll_tools_legacy_lesson_array_meta_value(int $post_id, string $meta_key): array {
    $raw = get_post_meta($post_id, $meta_key, true);
    return is_array($raw) ? $raw : [];
}

/**
 * Read a fresh, bounded, allowlisted metadata snapshot.
 *
 * The migration must not interpret a failed metadata query as an empty legacy
 * value. Direct SQL also avoids accepting a previously cached partial read as
 * proof that the source snapshot is complete.
 *
 * @param string[] $meta_keys
 * @param string[] $multi_value_keys
 * @param array<string,array{code:string,message:string}> $errors
 * @return array<string,array<int,mixed>>|WP_Error
 */
function ll_tools_legacy_lesson_meta_snapshot(
    string $meta_type,
    int $object_id,
    array $meta_keys,
    array $multi_value_keys,
    int $row_limit,
    array $errors
) {
    global $wpdb;

    if ($meta_type === 'post') {
        $table = $wpdb->postmeta;
        $object_column = 'post_id';
        $meta_id_column = 'meta_id';
    } elseif ($meta_type === 'user') {
        $table = $wpdb->usermeta;
        $object_column = 'user_id';
        $meta_id_column = 'umeta_id';
    } else {
        return new WP_Error(
            'legacy_lesson_meta_type_invalid',
            __('The requested legacy metadata snapshot type is invalid.', 'll-tools-text-domain')
        );
    }

    $normalized_keys = [];
    foreach ($meta_keys as $meta_key) {
        $meta_key = (string) $meta_key;
        if ($meta_key !== '') {
            $normalized_keys[$meta_key] = $meta_key;
        }
    }
    $meta_keys = array_values($normalized_keys);
    if ($object_id <= 0 || empty($meta_keys)) {
        return array_fill_keys($meta_keys, []);
    }

    $multi_value_lookup = array_fill_keys(
        array_values(array_intersect($meta_keys, array_map('strval', $multi_value_keys))),
        true
    );
    $row_limit = max(1, min(5000, $row_limit));
    $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
    $query_args = array_merge([$object_id], $meta_keys, [$row_limit + 1]);
    $sql = $wpdb->prepare(
        "SELECT {$meta_id_column} AS meta_id, meta_key, meta_value
         FROM {$table}
         WHERE {$object_column} = %d
           AND meta_key IN ({$key_placeholders})
         ORDER BY {$meta_id_column} ASC
         LIMIT %d",
        ...$query_args
    );

    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '') {
        $contract = (array) ($errors['query'] ?? []);
        return new WP_Error(
            (string) ($contract['code'] ?? 'legacy_lesson_meta_query_incomplete'),
            (string) ($contract['message'] ?? __(
                'The legacy metadata could not be read completely.',
                'll-tools-text-domain'
            )),
            ['object_id' => $object_id, 'meta_type' => $meta_type]
        );
    }
    if (count((array) $rows) > $row_limit) {
        $contract = (array) ($errors['too_large'] ?? []);
        return new WP_Error(
            (string) ($contract['code'] ?? 'legacy_lesson_meta_snapshot_too_large'),
            (string) ($contract['message'] ?? __(
                'The legacy metadata exceeds the configured safe limit.',
                'll-tools-text-domain'
            )),
            ['object_id' => $object_id, 'meta_type' => $meta_type]
        );
    }

    $snapshot = array_fill_keys($meta_keys, []);
    foreach ((array) $rows as $row) {
        $meta_key = (string) ($row['meta_key'] ?? '');
        if (!array_key_exists($meta_key, $snapshot)) {
            continue;
        }
        $snapshot[$meta_key][] = maybe_unserialize($row['meta_value'] ?? '');
    }
    foreach ($snapshot as $meta_key => $values) {
        if (isset($multi_value_lookup[$meta_key]) || count($values) <= 1) {
            continue;
        }
        $contract = (array) ($errors['ambiguous'] ?? []);
        return new WP_Error(
            (string) ($contract['code'] ?? 'legacy_lesson_meta_snapshot_ambiguous'),
            (string) ($contract['message'] ?? __(
                'The legacy metadata contains duplicate single-value rows.',
                'll-tools-text-domain'
            )),
            [
                'object_id' => $object_id,
                'meta_type' => $meta_type,
                'meta_key' => $meta_key,
            ]
        );
    }

    return $snapshot;
}

/**
 * @param array<string,array<int,mixed>> $snapshot
 * @param mixed $default
 * @return mixed
 */
function ll_tools_legacy_lesson_meta_single(
    array $snapshot,
    string $meta_key,
    $default = ''
) {
    return isset($snapshot[$meta_key][0]) ? $snapshot[$meta_key][0] : $default;
}

/**
 * Read fresh post rows without consulting the object cache.
 *
 * @param int[] $post_ids
 * @return array<int,WP_Post>|WP_Error
 */
function ll_tools_legacy_lesson_post_snapshot(array $post_ids) {
    global $wpdb;

    $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
    if (count($post_ids) > 4000) {
        return new WP_Error(
            'legacy_lesson_post_snapshot_too_large',
            __('The legacy lesson post snapshot exceeds the configured safe limit.', 'll-tools-text-domain')
        );
    }
    if (empty($post_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $sql = $wpdb->prepare(
        "SELECT *
         FROM {$wpdb->posts}
         WHERE ID IN ({$placeholders})
         ORDER BY ID ASC
         LIMIT %d",
        ...array_merge($post_ids, [count($post_ids) + 1])
    );
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_post_snapshot_query_incomplete',
            __('The legacy lesson posts could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $rows) > count($post_ids)) {
        return new WP_Error(
            'legacy_lesson_post_snapshot_ambiguous',
            __('The legacy lesson post snapshot is ambiguous.', 'll-tools-text-domain')
        );
    }

    $snapshot = [];
    foreach ((array) $rows as $row) {
        $post = new WP_Post($row);
        $snapshot[(int) $post->ID] = $post;
    }
    return $snapshot;
}

/**
 * Resolve a comma-separated list of category IDs, slugs, or names.
 *
 * @return int[]|WP_Error
 */
function ll_tools_resolve_legacy_lesson_category_ids($raw) {
    $tokens = is_array($raw) ? $raw : preg_split('/\s*,\s*/', trim((string) $raw));
    $ids = [];
    foreach ((array) $tokens as $token) {
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }
        if (ctype_digit($token)) {
            $term = get_term(absint($token), 'category');
        } else {
            $term = get_term_by('slug', sanitize_title($token), 'category');
            if (!($term instanceof WP_Term)) {
                $term = get_term_by('name', $token, 'category');
            }
        }
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return new WP_Error(
                'legacy_lesson_category_missing',
                sprintf(
                    __('Source category "%s" was not found.', 'll-tools-text-domain'),
                    $token
                )
            );
        }
        $ids[(int) $term->term_id] = (int) $term->term_id;
    }

    return array_values($ids);
}

/**
 * Select one deterministic source-post page. A category or explicit ID scope
 * is required so a migration cannot accidentally turn every site post into a
 * lesson.
 *
 * @return array{ids:int[],has_more:bool,next_cursor:int}|WP_Error
 */
function ll_tools_legacy_lesson_source_page(array $args) {
    global $wpdb;

    $after_id = max(0, (int) ($args['after_id'] ?? 0));
    $limit = ll_tools_legacy_lesson_batch_limit($args['limit'] ?? 100);
    $exclude_scope_complete = true;
    $exclude_source_ids = ll_tools_normalize_completed_content_lesson_ids(
        $args['exclude_source_ids'] ?? [],
        $exclude_scope_complete
    );
    $source_scope_complete = true;
    $exclude_lookup = array_fill_keys($exclude_source_ids, true);
    $source_ids = ll_tools_normalize_completed_content_lesson_ids(
        $args['source_ids'] ?? [],
        $source_scope_complete
    );
    if (!$exclude_scope_complete || !$source_scope_complete) {
        return new WP_Error(
            'legacy_lesson_scope_too_large',
            __('The explicit legacy lesson scope exceeds the configured safe limit.', 'll-tools-text-domain')
        );
    }
    if (!empty($source_ids)) {
        $source_ids = array_values(array_filter(
            $source_ids,
            static function (int $source_id) use ($after_id, $exclude_lookup): bool {
                return $source_id > $after_id
                    && empty($exclude_lookup[$source_id]);
            }
        ));
        sort($source_ids, SORT_NUMERIC);
        if (empty($source_ids)) {
            $page_ids = [];
        } else {
            $source_placeholders = implode(',', array_fill(0, count($source_ids), '%d'));
            $sql = $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = 'post'
                   AND ID IN ({$source_placeholders})
                 ORDER BY ID ASC
                 LIMIT %d",
                ...array_merge($source_ids, [$limit + 1])
            );
            $wpdb->last_error = '';
            $page_ids = array_map('intval', (array) $wpdb->get_col($sql));
            if ($wpdb->last_error !== '') {
                return new WP_Error(
                    'legacy_lesson_source_query_incomplete',
                    __('The source lesson page could not be read completely.', 'll-tools-text-domain')
                );
            }
        }
    } else {
        $category_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) ($args['category_ids'] ?? [])
        ))));
        if (count($category_ids) > 100) {
            return new WP_Error(
                'legacy_lesson_category_scope_too_large',
                __('The source category scope exceeds the configured safe limit.', 'll-tools-text-domain')
            );
        }
        if (empty($category_ids)) {
            return new WP_Error(
                'legacy_lesson_scope_required',
                __('Provide explicit source post IDs or source category IDs.', 'll-tools-text-domain')
            );
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $exclude_clause = '';
        if (!empty($exclude_source_ids)) {
            $exclude_placeholders = implode(',', array_fill(0, count($exclude_source_ids), '%d'));
            $exclude_clause = " AND p.ID NOT IN ({$exclude_placeholders})";
        }
        $query_args = array_merge(
            [$after_id],
            $category_ids,
            $exclude_source_ids,
            [$limit + 1]
        );
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.ID > %d
               AND p.post_type = 'post'
               AND p.post_status = 'publish'
               AND tt.taxonomy = 'category'
               AND tt.term_id IN ({$placeholders})
               {$exclude_clause}
             ORDER BY p.ID ASC
             LIMIT %d",
            ...$query_args
        );
        $wpdb->last_error = '';
        $page_ids = array_map('intval', (array) $wpdb->get_col($sql));
        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'legacy_lesson_source_query_incomplete',
                __('The source lesson page could not be read completely.', 'll-tools-text-domain')
            );
        }
    }

    $has_more = count($page_ids) > $limit;
    $page_ids = array_slice($page_ids, 0, $limit);
    return [
        'ids' => $page_ids,
        'has_more' => $has_more,
        'next_cursor' => empty($page_ids) ? $after_id : max($page_ids),
    ];
}

function ll_tools_legacy_lesson_transform_content(
    string $content,
    string $processed = ''
): string {
    $processed = trim($processed);
    if ($processed !== '' && preg_match('/\[regex_linker\b[^\]]*\]/i', $content)) {
        $safe_processed = wp_kses_post($processed);
        $content = preg_replace_callback(
            '/\[regex_linker\b[^\]]*\].*?\[\/regex_linker\]/is',
            static function () use ($safe_processed): string {
                return $safe_processed;
            },
            $content,
            1
        );
    }

    $content = preg_replace(
        '/\[\/?(?:custom_header|custom_footer|signup_link)\b[^\]]*\]/i',
        '',
        $content
    );
    $content = preg_replace('/\[\/?regex_linker\b[^\]]*\]/i', '', $content);
    return trim((string) $content);
}

/**
 * Preserve every sanitized legacy concept rather than the old renderer's
 * four-item display truncation.
 *
 * @return array<string,string[]>
 */
function ll_tools_legacy_lesson_concepts(array $source_meta): array {
    $concepts = [];
    foreach (['verb_ending', 'word_ending', 'other_concept'] as $meta_key) {
        $values = [];
        foreach ((array) ($source_meta[$meta_key] ?? []) as $concept) {
            if (!is_scalar($concept)) {
                continue;
            }
            $concept = trim(wp_strip_all_tags((string) $concept));
            if ($concept === '') {
                continue;
            }
            $values[$concept] = $concept;
        }
        if (!empty($values)) {
            $concepts[$meta_key] = array_values($values);
        }
    }

    return $concepts;
}

/**
 * @return array<int,array{id:int,slug:string,name:string}>|WP_Error
 */
function ll_tools_legacy_lesson_categories(int $source_post_id) {
    global $wpdb;

    $limit = max(10, min(500, (int) apply_filters(
        'll_tools_legacy_lesson_category_snapshot_limit',
        100,
        $source_post_id
    )));
    $sql = $wpdb->prepare(
        "SELECT t.term_id, t.slug, t.name
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->terms} t
            ON t.term_id = tt.term_id
         WHERE tr.object_id = %d
           AND tt.taxonomy = 'category'
         ORDER BY t.term_id ASC
         LIMIT %d",
        $source_post_id,
        $limit + 1
    );
    $wpdb->last_error = '';
    $categories = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_source_category_query_incomplete',
            __('The legacy lesson source categories could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $categories) > $limit) {
        return new WP_Error(
            'legacy_lesson_source_categories_too_large',
            __('The legacy lesson source categories exceed the configured safe limit.', 'll-tools-text-domain')
        );
    }

    $rows = [];
    foreach ((array) $categories as $category) {
        $category_id = (int) ($category['term_id'] ?? 0);
        if ($category_id <= 0) {
            continue;
        }
        $rows[] = [
            'id' => $category_id,
            'slug' => sanitize_title((string) ($category['slug'] ?? '')),
            'name' => sanitize_text_field((string) ($category['name'] ?? '')),
        ];
    }
    return $rows;
}

/**
 * @return array{
 *   processed_text:string,
 *   concepts:array<string,string[]>,
 *   categories:array<int,array{id:int,slug:string,name:string}>,
 *   category_ids:int[],
 *   legacy_level:int
 * }|WP_Error
 */
function ll_tools_legacy_lesson_source_snapshot(int $source_post_id) {
    $source_meta_limit = max(10, min(2000, (int) apply_filters(
        'll_tools_legacy_lesson_source_meta_row_limit',
        500,
        $source_post_id
    )));
    $source_meta = ll_tools_legacy_lesson_meta_snapshot(
        'post',
        $source_post_id,
        [
            '_processed_text_with_links',
            '_lesson_level',
            'verb_ending',
            'word_ending',
            'other_concept',
        ],
        ['verb_ending', 'word_ending', 'other_concept'],
        $source_meta_limit,
        [
            'query' => [
                'code' => 'legacy_lesson_source_meta_query_incomplete',
                'message' => __(
                    'The legacy lesson source metadata could not be read completely.',
                    'll-tools-text-domain'
                ),
            ],
            'too_large' => [
                'code' => 'legacy_lesson_source_meta_too_large',
                'message' => __(
                    'The legacy lesson source metadata exceeds the configured safe limit.',
                    'll-tools-text-domain'
                ),
            ],
            'ambiguous' => [
                'code' => 'legacy_lesson_source_meta_ambiguous',
                'message' => __(
                    'The legacy lesson source has duplicate single-value metadata and was not changed.',
                    'll-tools-text-domain'
                ),
            ],
        ]
    );
    if (is_wp_error($source_meta)) {
        return $source_meta;
    }

    $categories = ll_tools_legacy_lesson_categories($source_post_id);
    if (is_wp_error($categories)) {
        return $categories;
    }
    $processed_text = ll_tools_legacy_lesson_meta_single(
        $source_meta,
        '_processed_text_with_links',
        ''
    );
    $legacy_level = ll_tools_legacy_lesson_meta_single(
        $source_meta,
        '_lesson_level',
        0
    );

    return [
        'processed_text' => is_scalar($processed_text) ? (string) $processed_text : '',
        'concepts' => ll_tools_legacy_lesson_concepts($source_meta),
        'categories' => $categories,
        'category_ids' => ll_tools_normalize_legacy_lesson_category_ids(
            array_column($categories, 'id')
        ),
        'legacy_level' => is_scalar($legacy_level) ? (int) $legacy_level : 0,
    ];
}

function ll_tools_legacy_lesson_excerpt(WP_Post $source, array $concepts): string {
    $excerpt = trim((string) $source->post_excerpt);
    if ($excerpt !== '') {
        return wp_strip_all_tags($excerpt);
    }

    $flat_concepts = [];
    foreach ($concepts as $values) {
        foreach ($values as $value) {
            $flat_concepts[] = $value;
        }
    }

    return implode(' · ', array_slice($flat_concepts, 0, 4));
}

/**
 * Return an explicitly requested status, or an empty string when omitted.
 *
 * @return string|WP_Error
 */
function ll_tools_legacy_lesson_requested_status(array $args) {
    if (!array_key_exists('status', $args)) {
        return '';
    }

    $status = sanitize_key((string) $args['status']);
    if (!in_array($status, ['draft', 'publish', 'pending', 'private'], true)) {
        return new WP_Error(
            'legacy_lesson_status_invalid',
            __(
                'Use draft, publish, pending, or private for the migrated lesson status.',
                'll-tools-text-domain'
            )
        );
    }

    return $status;
}

/**
 * @return int|WP_Error
 */
function ll_tools_resolve_content_lesson_by_legacy_source(int $source_post_id) {
    global $wpdb;

    if ($source_post_id <= 0) {
        return 0;
    }

    $sql = $wpdb->prepare(
        "SELECT DISTINCT target.ID
         FROM {$wpdb->posts} target
         INNER JOIN {$wpdb->postmeta} source_meta
            ON source_meta.post_id = target.ID
           AND source_meta.meta_key = %s
           AND source_meta.meta_value = %s
         WHERE target.post_type = 'll_content_lesson'
         ORDER BY target.ID ASC
         LIMIT 2",
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        (string) $source_post_id
    );
    $wpdb->last_error = '';
    $matches = array_map('intval', (array) $wpdb->get_col($sql));
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_target_query_incomplete',
            __('The migrated lesson mapping could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count($matches) > 1) {
        return new WP_Error(
            'legacy_lesson_duplicate_target_mapping',
            sprintf(
                /* translators: %d: legacy source post ID */
                __('Legacy source post %d maps to more than one content lesson.', 'll-tools-text-domain'),
                $source_post_id
            )
        );
    }

    return !empty($matches) ? (int) $matches[0] : 0;
}

/**
 * Verify the status and word-set ownership of an existing migration target.
 *
 * @return array{post:WP_Post,status:string,wordset_id:int,retained_source:bool}|WP_Error
 */
function ll_tools_legacy_lesson_existing_target_snapshot(
    int $target_id,
    int $requested_wordset_id
) {
    $target = get_post($target_id);
    if (!($target instanceof WP_Post) || $target->post_type !== 'll_content_lesson') {
        return new WP_Error(
            'legacy_lesson_target_missing',
            __('The existing migrated lesson could not be verified.', 'll-tools-text-domain')
        );
    }
    $status = (string) $target->post_status;
    if (!in_array($status, ['draft', 'publish', 'pending', 'future', 'private'], true)) {
        return new WP_Error(
            'legacy_lesson_target_status_invalid',
            __(
                'The existing migrated lesson has an unsupported status and was not changed.',
                'll-tools-text-domain'
            )
        );
    }

    $target_meta = ll_tools_legacy_lesson_meta_snapshot(
        'post',
        $target_id,
        [
            LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        ],
        [],
        2,
        [
            'query' => [
                'code' => 'legacy_lesson_target_meta_query_incomplete',
                'message' => __(
                    'The existing migrated lesson metadata could not be read completely.',
                    'll-tools-text-domain'
                ),
            ],
            'too_large' => [
                'code' => 'legacy_lesson_target_meta_ambiguous',
                'message' => __(
                    'The existing migrated lesson has ambiguous metadata and was not changed.',
                    'll-tools-text-domain'
                ),
            ],
            'ambiguous' => [
                'code' => 'legacy_lesson_target_meta_ambiguous',
                'message' => __(
                    'The existing migrated lesson has ambiguous metadata and was not changed.',
                    'll-tools-text-domain'
                ),
            ],
        ]
    );
    if (is_wp_error($target_meta)) {
        return $target_meta;
    }
    $current_wordset_id = absint(ll_tools_legacy_lesson_meta_single(
        $target_meta,
        LL_TOOLS_CONTENT_LESSON_WORDSET_META,
        0
    ));
    if ($current_wordset_id !== $requested_wordset_id) {
        return new WP_Error(
            'legacy_lesson_target_wordset_mismatch',
            __(
                'The existing migrated lesson belongs to a different word set and was not changed.',
                'll-tools-text-domain'
            ),
            [
                'target_id' => $target_id,
                'requested_wordset_id' => $requested_wordset_id,
                'current_wordset_id' => $current_wordset_id,
            ]
        );
    }

    $retained_source_values = (array) (
        $target_meta[LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META] ?? []
    );
    $retained_source = false;
    if (!empty($retained_source_values)) {
        if (count($retained_source_values) !== 1
            || !is_scalar($retained_source_values[0])
            || (string) $retained_source_values[0] !== '1'
        ) {
            return new WP_Error(
                'legacy_lesson_retained_source_mode_mismatch',
                __(
                    'The existing lesson mapping uses a different retained-source mode and was not changed.',
                    'll-tools-text-domain'
                )
            );
        }
        $retained_source = true;
    }

    return [
        'post' => $target,
        'status' => $status,
        'wordset_id' => $current_wordset_id,
        'retained_source' => $retained_source,
    ];
}

function ll_tools_find_content_lesson_by_legacy_source(int $source_post_id): int {
    $target_id = ll_tools_resolve_content_lesson_by_legacy_source($source_post_id);
    return is_wp_error($target_id) ? 0 : (int) $target_id;
}

/**
 * @return array{target_id:int,created:bool,changed:bool}|WP_Error
 */
function ll_tools_migrate_legacy_lesson_post(
    int $source_post_id,
    int $wordset_id,
    array $args = []
) {
    global $wpdb;

    $retained_source = !empty($args['retained_source']);
    if ($retained_source
        && (
            !array_key_exists('status', $args)
            || sanitize_key((string) $args['status']) !== 'publish'
            || !array_key_exists('show_in_mix', $args)
            || !empty($args['show_in_mix'])
        )
    ) {
        return ll_tools_legacy_lesson_retained_source_contract_error('post_contract');
    }

    $source_posts = ll_tools_legacy_lesson_post_snapshot([$source_post_id]);
    if (is_wp_error($source_posts)) {
        return $source_posts;
    }
    $source = $source_posts[$source_post_id] ?? null;
    if (!($source instanceof WP_Post) || $source->post_type !== 'post') {
        return new WP_Error(
            'legacy_lesson_source_missing',
            __('The legacy lesson source post does not exist.', 'll-tools-text-domain')
        );
    }
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return new WP_Error(
            'legacy_lesson_wordset_missing',
            __('Select a valid target word set.', 'll-tools-text-domain')
        );
    }
    $requested_status = ll_tools_legacy_lesson_requested_status($args);
    if (is_wp_error($requested_status)) {
        return $requested_status;
    }
    $status_was_requested = $requested_status !== '';

    $target_id = ll_tools_resolve_content_lesson_by_legacy_source($source_post_id);
    if (is_wp_error($target_id)) {
        return $target_id;
    }
    $target_id = (int) $target_id;
    $created = $target_id <= 0;
    $apply = !empty($args['apply']);
    $existing_target = null;
    if (!$created) {
        $existing_target = ll_tools_legacy_lesson_existing_target_snapshot(
            $target_id,
            $wordset_id
        );
        if (is_wp_error($existing_target)) {
            return $existing_target;
        }
        if ((bool) $existing_target['retained_source'] !== $retained_source) {
            return new WP_Error(
                'legacy_lesson_retained_source_mode_mismatch',
                __(
                    'The existing lesson mapping uses a different retained-source mode and was not changed.',
                    'll-tools-text-domain'
                )
            );
        }
    }
    $target_status = $status_was_requested
        ? $requested_status
        : ($created ? 'draft' : (string) $existing_target['status']);
    if ($source->post_status !== 'publish' && $target_status === 'publish') {
        return new WP_Error(
            'legacy_lesson_source_not_published',
            __('A non-published source lesson cannot be migrated directly to published status.', 'll-tools-text-domain')
        );
    }
    $source_snapshot = ll_tools_legacy_lesson_source_snapshot($source_post_id);
    if (is_wp_error($source_snapshot)) {
        return $source_snapshot;
    }
    $concepts = (array) $source_snapshot['concepts'];
    $source_categories = (array) $source_snapshot['categories'];
    $source_category_ids = (array) $source_snapshot['category_ids'];
    $content = $retained_source
        ? ''
        : ll_tools_legacy_lesson_transform_content(
            (string) $source->post_content,
            (string) $source_snapshot['processed_text']
        );
    if (!$retained_source && $target_id > 0) {
        $link_target_map = ll_tools_legacy_lesson_link_target_map($wordset_id);
        if (is_wp_error($link_target_map)) {
            return $link_target_map;
        }
        $link_result = ll_tools_rewrite_legacy_lesson_links(
            $content,
            $link_target_map
        );
        if (is_wp_error($link_result)) {
            return $link_result;
        }
        $content = (string) $link_result['content'];
    }
    $legacy_level = (int) $source_snapshot['legacy_level'];
    $menu_order = $legacy_level > 0
        ? $legacy_level
        : max(0, (int) $source->menu_order);
    $post_data = [
        'post_type' => 'll_content_lesson',
        'post_status' => $target_status,
        'post_title' => (string) $source->post_title,
        'post_name' => (string) $source->post_name,
        'post_excerpt' => $retained_source
            ? wp_strip_all_tags((string) $source->post_excerpt)
            : ll_tools_legacy_lesson_excerpt($source, $concepts),
        'post_content' => $content,
        'post_author' => (int) $source->post_author,
        'post_password' => (string) $source->post_password,
        'post_date' => (string) $source->post_date,
        'post_date_gmt' => (string) $source->post_date_gmt,
        'menu_order' => $menu_order,
    ];
    if ($target_id > 0) {
        $post_data['ID'] = $target_id;
    }

    $wpdb->last_error = '';
    $source_url = (string) get_permalink($source);
    if ($wpdb->last_error !== '' || $source_url === '') {
        return new WP_Error(
            'legacy_lesson_source_permalink_incomplete',
            __('The legacy lesson source permalink could not be resolved completely.', 'll-tools-text-domain')
        );
    }
    $existing_signature = '';
    if ($target_id > 0) {
        $existing = $existing_target['post'];
        if ($existing instanceof WP_Post) {
            // WordPress may adjust a new CPT slug to avoid a collision with the
            // source post. Keep that durable target permalink on later runs.
            $post_data['post_name'] = (string) $existing->post_name;
            if (!$status_was_requested) {
                // Preserve the schedule paired with an existing `future`
                // status. Reapplying a source post's old publication date
                // would make WordPress publish the migrated lesson early.
                $post_data['post_date'] = (string) $existing->post_date;
                $post_data['post_date_gmt'] = (string) $existing->post_date_gmt;
            }
            $existing_signature = md5((string) wp_json_encode([
                $existing->post_status,
                $existing->post_title,
                $existing->post_name,
                $existing->post_excerpt,
                $existing->post_content,
                (int) $existing->post_author,
                (string) $existing->post_password,
                (int) $existing->menu_order,
                (int) $existing_target['wordset_id'],
                ll_tools_get_content_lesson_kind($target_id),
                ll_tools_get_content_lesson_show_in_mix($target_id),
                ll_tools_legacy_lesson_array_meta_value(
                    $target_id,
                    LL_TOOLS_LEGACY_LESSON_CONCEPTS_META
                ),
                ll_tools_legacy_lesson_array_meta_value(
                    $target_id,
                    LL_TOOLS_LEGACY_LESSON_CATEGORIES_META
                ),
                ll_tools_get_legacy_lesson_category_ids($target_id),
                (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META, true),
                (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META, true),
                (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_MIGRATION_META, true),
                (string) get_post_meta(
                    $target_id,
                    LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
                    true
                ),
            ]));
        }
    }
    $next_signature = md5((string) wp_json_encode([
        $target_status,
        $post_data['post_title'],
        $post_data['post_name'],
        $post_data['post_excerpt'],
        $post_data['post_content'],
        $post_data['post_author'],
        $post_data['post_password'],
        $post_data['menu_order'],
        $wordset_id,
        'article',
        !empty($args['show_in_mix']),
        $concepts,
        $source_categories,
        $source_category_ids,
        (string) $source_post_id,
        $source_url,
        '1',
        $retained_source ? '1' : '',
    ]));
    $post_changed = $created || $existing_signature !== $next_signature;
    $default_wordset_changed = ll_tools_get_legacy_lesson_default_wordset_id() !== $wordset_id;
    $changed = $post_changed || $default_wordset_changed;

    if (!$apply) {
        return [
            'target_id' => $target_id,
            'created' => $created,
            'changed' => $changed,
            'retained_source' => $retained_source,
        ];
    }

    if (!$post_changed) {
        if ($default_wordset_changed
            && !ll_tools_set_legacy_lesson_default_wordset_id($wordset_id)
        ) {
            return new WP_Error(
                'legacy_lesson_default_wordset_write_failed',
                __('The legacy lesson compatibility word set could not be saved.', 'll-tools-text-domain')
            );
        }

        return [
            'target_id' => $target_id,
            'created' => false,
            'changed' => $changed,
            'retained_source' => $retained_source,
        ];
    }

    $write_post_data = $post_data;
    if ($created && $retained_source && $target_status === 'publish') {
        // Keep a new bridge non-public until its redirect identity is stored.
        $write_post_data['post_status'] = 'draft';
    }
    $written_id = wp_insert_post(wp_slash($write_post_data), true);
    if (is_wp_error($written_id)) {
        return $written_id;
    }
    $target_id = (int) $written_id;
    update_post_meta($target_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
    update_post_meta($target_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'article');
    if (!$retained_source && !empty($args['show_in_mix'])) {
        update_post_meta($target_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
    } else {
        delete_post_meta($target_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META);
    }
    update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META, (string) $source_post_id);
    update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META, $source_url);
    update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_MIGRATION_META, '1');
    if ($retained_source) {
        update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META, '1');
    } else {
        delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META);
    }
    if (empty($concepts)) {
        delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_CONCEPTS_META);
    } else {
        update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_CONCEPTS_META, $concepts);
    }
    if (empty($source_categories)) {
        delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_CATEGORIES_META);
    } else {
        update_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_CATEGORIES_META,
            $source_categories
        );
    }
    if (!ll_tools_set_legacy_lesson_category_ids($target_id, $source_category_ids)) {
        return new WP_Error(
            'legacy_lesson_category_mapping_write_failed',
            __('The migrated lesson category mapping could not be saved.', 'll-tools-text-domain')
        );
    }

    if ($created && $retained_source && $target_status === 'publish') {
        $prepublish_target = get_post($target_id);
        if (!($prepublish_target instanceof WP_Post)
            || $prepublish_target->post_type !== 'll_content_lesson'
            || $prepublish_target->post_status !== 'draft'
            || (string) $prepublish_target->post_content !== ''
            || !ll_tools_legacy_lesson_is_retained_source_target($target_id)
            || ll_tools_get_content_lesson_wordset_id($target_id) !== $wordset_id
        ) {
            return new WP_Error(
                'legacy_lesson_write_verification_failed',
                __('The migrated lesson could not be verified after saving.', 'll-tools-text-domain')
            );
        }
        $published_id = wp_update_post([
            'ID' => $target_id,
            'post_status' => 'publish',
        ], true);
        if (is_wp_error($published_id) || (int) $published_id !== $target_id) {
            return is_wp_error($published_id)
                ? $published_id
                : new WP_Error(
                    'legacy_lesson_write_verification_failed',
                    __('The migrated lesson could not be verified after saving.', 'll-tools-text-domain')
                );
        }
    }

    $stored_post = get_post($target_id);
    $verification_checks = [
        'post_exists' => $stored_post instanceof WP_Post,
        'post_type' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_type === 'll_content_lesson',
        'post_status' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_status === (string) $post_data['post_status'],
        'post_title' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_title === (string) $post_data['post_title'],
        'post_name' => $stored_post instanceof WP_Post
            && (
                (string) $stored_post->post_name === (string) $post_data['post_name']
                || (
                    $created
                    && (string) $post_data['post_name'] !== ''
                    && str_starts_with(
                        (string) $stored_post->post_name,
                        (string) $post_data['post_name'] . '-'
                    )
                )
            ),
        'post_excerpt' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_excerpt === (string) $post_data['post_excerpt'],
        'post_content' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_content === (string) $post_data['post_content'],
        'post_author' => $stored_post instanceof WP_Post
            && (int) $stored_post->post_author === (int) $post_data['post_author'],
        'post_password' => $stored_post instanceof WP_Post
            && (string) $stored_post->post_password === (string) $post_data['post_password'],
        'menu_order' => $stored_post instanceof WP_Post
            && (int) $stored_post->menu_order === (int) $post_data['menu_order'],
        'source_mapping' => ll_tools_resolve_content_lesson_by_legacy_source($source_post_id) === $target_id,
        'wordset' => ll_tools_get_content_lesson_wordset_id($target_id) === $wordset_id,
        'kind' => ll_tools_get_content_lesson_kind($target_id) === 'article',
        'show_in_mix' => ll_tools_get_content_lesson_show_in_mix($target_id)
            === (!$retained_source && !empty($args['show_in_mix'])),
        'retained_source' => ll_tools_legacy_lesson_is_retained_source_target($target_id)
            === $retained_source,
        'retained_source_url' => !$retained_source
            || ll_tools_get_legacy_lesson_retained_source_url($target_id) === $source_url,
        'concepts' => ll_tools_legacy_lesson_array_meta_value(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_CONCEPTS_META
        ) === $concepts,
        'category_snapshot' => ll_tools_legacy_lesson_array_meta_value(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_CATEGORIES_META
        ) === $source_categories,
        'category_ids' => ll_tools_get_legacy_lesson_category_ids($target_id) === $source_category_ids,
        'source_id' => (string) get_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
            true
        ) === (string) $source_post_id,
        'source_url' => (string) get_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META,
            true
        ) === $source_url,
        'migration_version' => (string) get_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_MIGRATION_META,
            true
        ) === '1',
        'retained_source_meta' => (string) get_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
            true
        ) === ($retained_source ? '1' : ''),
    ];
    $failed_checks = array_keys(array_filter(
        $verification_checks,
        static function (bool $passed): bool {
            return !$passed;
        }
    ));
    if (!empty($failed_checks)) {
        return new WP_Error(
            'legacy_lesson_write_verification_failed',
            __('The migrated lesson could not be verified after saving.', 'll-tools-text-domain'),
            ['failed_checks' => $failed_checks]
        );
    }
    if (!ll_tools_set_legacy_lesson_default_wordset_id($wordset_id)) {
        return new WP_Error(
            'legacy_lesson_default_wordset_write_failed',
            __('The legacy lesson compatibility word set could not be saved.', 'll-tools-text-domain')
        );
    }

    return [
        'target_id' => $target_id,
        'created' => $created,
        'changed' => $changed,
        'retained_source' => $retained_source,
    ];
}

/**
 * @return array{id:int,slug:string}
 */
function ll_tools_legacy_lesson_dependency_identity($raw): array {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['id' => 0, 'slug' => ''];
    }
    if (ctype_digit($raw)) {
        return ['id' => absint($raw), 'slug' => ''];
    }

    $parts = wp_parse_url($raw);
    $post_id = 0;
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $query_args);
        foreach (['p', 'page_id'] as $identity_key) {
            $post_id = isset($query_args[$identity_key])
                ? absint($query_args[$identity_key])
                : 0;
            if ($post_id > 0) {
                break;
            }
        }
    }

    $path = is_array($parts) ? trim((string) ($parts['path'] ?? ''), '/') : '';
    $slug = sanitize_title((string) basename($path));
    return ['id' => $post_id, 'slug' => $slug];
}

/**
 * Resolve a bounded prerequisite batch from a fresh authoritative post query.
 *
 * @param mixed[] $dependency_values
 * @return array<string,int>|WP_Error raw dependency => source post ID, or zero
 */
function ll_tools_legacy_lesson_dependency_source_map(array $dependency_values) {
    global $wpdb;

    $identities = [];
    $candidate_ids = [];
    $candidate_slugs = [];
    foreach ($dependency_values as $dependency_value) {
        if (!is_scalar($dependency_value)) {
            continue;
        }
        $raw = trim((string) $dependency_value);
        if ($raw === '') {
            continue;
        }
        $identity = ll_tools_legacy_lesson_dependency_identity($raw);
        $identities[$raw] = $identity;
        if ($identity['id'] > 0) {
            $candidate_ids[$identity['id']] = $identity['id'];
        }
        if ($identity['slug'] !== '') {
            $candidate_slugs[$identity['slug']] = $identity['slug'];
        }
    }
    if (empty($identities)) {
        return [];
    }

    $id_placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
    $slug_placeholders = implode(',', array_fill(0, count($candidate_slugs), '%s'));
    $conditions = [];
    $query_args = [];
    if (!empty($candidate_ids)) {
        $conditions[] = "ID IN ({$id_placeholders})";
        $query_args = array_merge($query_args, array_values($candidate_ids));
    }
    if (!empty($candidate_slugs)) {
        $conditions[] = "post_name IN ({$slug_placeholders})";
        $query_args = array_merge($query_args, array_values($candidate_slugs));
    }
    if (empty($conditions)) {
        return array_fill_keys(array_keys($identities), 0);
    }

    $row_limit = count($candidate_ids) + count($candidate_slugs);
    $query_args[] = $row_limit + 1;
    $sql = $wpdb->prepare(
        "SELECT ID, post_name
         FROM {$wpdb->posts}
         WHERE post_type = 'post'
           AND (" . implode(' OR ', $conditions) . ")
         ORDER BY ID ASC
         LIMIT %d",
        ...$query_args
    );
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_dependency_source_query_incomplete',
            __('The legacy lesson prerequisite sources could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $rows) > $row_limit) {
        return new WP_Error(
            'legacy_lesson_dependency_source_ambiguous',
            __('The legacy lesson prerequisite sources are ambiguous.', 'll-tools-text-domain')
        );
    }

    $verified_ids = [];
    $slug_matches = [];
    foreach ((array) $rows as $row) {
        $post_id = (int) ($row->ID ?? 0);
        $slug = (string) ($row->post_name ?? '');
        if ($post_id > 0) {
            $verified_ids[$post_id] = $post_id;
        }
        if ($slug !== '') {
            $slug_matches[$slug][$post_id] = $post_id;
        }
    }
    foreach ($slug_matches as $slug => $matches) {
        if (count($matches) > 1 && isset($candidate_slugs[$slug])) {
            return new WP_Error(
                'legacy_lesson_dependency_source_ambiguous',
                __('The legacy lesson prerequisite sources are ambiguous.', 'll-tools-text-domain')
            );
        }
    }

    $map = [];
    foreach ($identities as $raw => $identity) {
        if ($identity['id'] > 0 && isset($verified_ids[$identity['id']])) {
            $map[$raw] = $identity['id'];
            continue;
        }
        $slug_matches_for_identity = $identity['slug'] !== ''
            ? array_values((array) ($slug_matches[$identity['slug']] ?? []))
            : [];
        $map[$raw] = count($slug_matches_for_identity) === 1
            ? (int) $slug_matches_for_identity[0]
            : 0;
    }
    return $map;
}

/**
 * @return int|WP_Error
 */
function ll_tools_legacy_lesson_dependency_source_id($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0;
    }
    $map = ll_tools_legacy_lesson_dependency_source_map([$raw]);
    return is_wp_error($map) ? $map : (int) ($map[$raw] ?? 0);
}

function ll_tools_legacy_lesson_link_match_key(string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || str_starts_with($url, '#')) {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
    if ($path !== '/') {
        $path = untrailingslashit($path);
    }
    $identity_query = '';
    if ($path === '/' && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $query_args);
        foreach (['p', 'page_id', 'attachment_id'] as $identity_key) {
            $identity_id = isset($query_args[$identity_key])
                ? absint($query_args[$identity_key])
                : 0;
            if ($identity_id > 0) {
                $identity_query = '?' . $identity_key . '=' . $identity_id;
                break;
            }
        }
    }

    return $host . '|' . $path . $identity_query;
}

/**
 * Snapshot every post and metadata row required to construct a link map.
 *
 * @param array<int,int> $source_target_map
 * @return array<int,array{
 *   source:WP_Post,
 *   target:WP_Post,
 *   source_permalink:string,
 *   stored_source_url:string,
 *   target_url:string
 * }>|WP_Error
 */
function ll_tools_legacy_lesson_link_snapshot(
    int $wordset_id,
    array $source_target_map
) {
    global $wpdb;

    if (empty($source_target_map)) {
        return [];
    }
    $target_owners = [];
    foreach ($source_target_map as $source_id => $target_id) {
        $source_id = (int) $source_id;
        $target_id = (int) $target_id;
        if (isset($target_owners[$target_id]) && $target_owners[$target_id] !== $source_id) {
            return new WP_Error(
                'legacy_lesson_link_map_ambiguous',
                __('The legacy lesson link map is ambiguous and was not used.', 'll-tools-text-domain')
            );
        }
        $target_owners[$target_id] = $source_id;
    }

    $post_ids = array_values(array_unique(array_merge(
        array_map('intval', array_keys($source_target_map)),
        array_map('intval', array_values($source_target_map))
    )));
    $posts = ll_tools_legacy_lesson_post_snapshot($post_ids);
    if (is_wp_error($posts)) {
        return new WP_Error(
            'legacy_lesson_link_snapshot_query_incomplete',
            __('The legacy lesson link map could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count($posts) !== count($post_ids)) {
        return new WP_Error(
            'legacy_lesson_link_snapshot_query_incomplete',
            __('The legacy lesson link map could not be read completely.', 'll-tools-text-domain')
        );
    }

    $target_ids = array_values(array_unique(array_map(
        'intval',
        array_values($source_target_map)
    )));
    $meta_keys = [
        LL_TOOLS_CONTENT_LESSON_WORDSET_META,
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META,
    ];
    $target_placeholders = implode(',', array_fill(0, count($target_ids), '%d'));
    $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
    $row_limit = count($target_ids) * count($meta_keys);
    $sql = $wpdb->prepare(
        "SELECT meta_id, post_id, meta_key, meta_value
         FROM {$wpdb->postmeta}
         WHERE post_id IN ({$target_placeholders})
           AND meta_key IN ({$key_placeholders})
         ORDER BY post_id ASC, meta_id ASC
         LIMIT %d",
        ...array_merge($target_ids, $meta_keys, [$row_limit + 1])
    );
    $wpdb->last_error = '';
    $meta_rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_link_snapshot_query_incomplete',
            __('The legacy lesson link map could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $meta_rows) > $row_limit) {
        return new WP_Error(
            'legacy_lesson_link_map_ambiguous',
            __('The legacy lesson link map is ambiguous and was not used.', 'll-tools-text-domain')
        );
    }

    $target_meta = [];
    foreach ($target_ids as $target_id) {
        $target_meta[$target_id] = array_fill_keys($meta_keys, []);
    }
    foreach ((array) $meta_rows as $meta_row) {
        $target_id = (int) ($meta_row['post_id'] ?? 0);
        $meta_key = (string) ($meta_row['meta_key'] ?? '');
        if (!isset($target_meta[$target_id])
            || !array_key_exists($meta_key, $target_meta[$target_id])
        ) {
            continue;
        }
        $target_meta[$target_id][$meta_key][] = maybe_unserialize(
            $meta_row['meta_value'] ?? ''
        );
        if (count($target_meta[$target_id][$meta_key]) > 1) {
            return new WP_Error(
                'legacy_lesson_link_map_ambiguous',
                __('The legacy lesson link map is ambiguous and was not used.', 'll-tools-text-domain')
            );
        }
    }

    $snapshot = [];
    foreach ($source_target_map as $source_id => $target_id) {
        $source_id = (int) $source_id;
        $target_id = (int) $target_id;
        $source = $posts[$source_id] ?? null;
        $target = $posts[$target_id] ?? null;
        if (!($source instanceof WP_Post)
            || $source->post_type !== 'post'
            || $source->post_status !== 'publish'
            || !($target instanceof WP_Post)
            || $target->post_type !== 'll_content_lesson'
            || !in_array(
                (string) $target->post_status,
                ['publish', 'draft', 'pending', 'future', 'private'],
                true
            )
        ) {
            return new WP_Error(
                'legacy_lesson_link_snapshot_query_incomplete',
                __('The legacy lesson link map could not be read completely.', 'll-tools-text-domain')
            );
        }
        $stored_wordset_id = absint(ll_tools_legacy_lesson_meta_single(
            $target_meta[$target_id],
            LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            0
        ));
        $stored_source_id = absint(ll_tools_legacy_lesson_meta_single(
            $target_meta[$target_id],
            LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
            0
        ));
        if ($stored_wordset_id !== $wordset_id || $stored_source_id !== $source_id) {
            return new WP_Error(
                'legacy_lesson_link_snapshot_query_incomplete',
                __('The legacy lesson link map could not be read completely.', 'll-tools-text-domain')
            );
        }
        $stored_source_url = ll_tools_legacy_lesson_meta_single(
            $target_meta[$target_id],
            LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META,
            ''
        );
        if (!is_scalar($stored_source_url)) {
            return new WP_Error(
                'legacy_lesson_link_map_ambiguous',
                __('The legacy lesson link map is ambiguous and was not used.', 'll-tools-text-domain')
            );
        }

        $wpdb->last_error = '';
        $source_url = (string) get_permalink($source);
        if ($wpdb->last_error !== '' || $source_url === '') {
            return new WP_Error(
                'legacy_lesson_link_permalink_incomplete',
                __('A legacy or migrated lesson permalink could not be resolved completely.', 'll-tools-text-domain')
            );
        }
        $wpdb->last_error = '';
        $target_url = (string) get_permalink($target);
        if ($wpdb->last_error !== '' || $target_url === '') {
            return new WP_Error(
                'legacy_lesson_link_permalink_incomplete',
                __('A legacy or migrated lesson permalink could not be resolved completely.', 'll-tools-text-domain')
            );
        }
        $snapshot[$source_id] = [
            'source' => $source,
            'target' => $target,
            'source_permalink' => $source_url,
            'stored_source_url' => (string) $stored_source_url,
            'target_url' => $target_url,
        ];
    }
    return $snapshot;
}

/**
 * Build a bounded lookup from legacy lesson URLs to migrated lesson URLs.
 *
 * @return array<string,string>|WP_Error
 */
function ll_tools_legacy_lesson_link_target_map(int $wordset_id) {
    static $request_cache = [];

    if (array_key_exists($wordset_id, $request_cache)) {
        return $request_cache[$wordset_id];
    }

    $source_target_map = ll_tools_legacy_lesson_source_target_map($wordset_id);
    if (is_wp_error($source_target_map)) {
        return $source_target_map;
    }
    $snapshot = ll_tools_legacy_lesson_link_snapshot($wordset_id, $source_target_map);
    if (is_wp_error($snapshot)) {
        return $snapshot;
    }

    $map = [];
    foreach ($snapshot as $source_id => $link_row) {
        $target_url = (string) $link_row['target_url'];
        $source_urls = [
            (string) $link_row['source_permalink'],
            (string) $link_row['stored_source_url'],
        ];
        foreach ($source_urls as $source_url) {
            $key = ll_tools_legacy_lesson_link_match_key($source_url);
            if ($key === '') {
                continue;
            }
            if (isset($map[$key]) && $map[$key] !== $target_url) {
                return new WP_Error(
                    'legacy_lesson_link_map_collision',
                    __('A legacy lesson URL maps to more than one migrated lesson.', 'll-tools-text-domain')
                );
            }
            $map[$key] = $target_url;

            $source_parts = wp_parse_url($source_url);
            $relative_url = is_array($source_parts)
                ? (string) ($source_parts['path'] ?? '/')
                : '';
            if ($relative_url !== ''
                && is_array($source_parts)
                && !empty($source_parts['query'])
            ) {
                $relative_url .= '?' . (string) $source_parts['query'];
            }
            $path_key = ll_tools_legacy_lesson_link_match_key($relative_url);
            if ($path_key !== '') {
                if (isset($map[$path_key]) && $map[$path_key] !== $target_url) {
                    return new WP_Error(
                        'legacy_lesson_link_map_collision',
                        __('A legacy lesson URL maps to more than one migrated lesson.', 'll-tools-text-domain')
                    );
                }
                $map[$path_key] = $target_url;
            }
        }
    }

    $request_cache[$wordset_id] = $map;
    return $map;
}

/**
 * Rewrite bounded legacy lesson anchors without changing their visible text.
 *
 * @param array<string,string> $target_map
 * @return array{content:string,rewritten:int}|WP_Error
 */
function ll_tools_rewrite_legacy_lesson_links(
    string $content,
    array $target_map
) {
    if ($content === '' || empty($target_map) || strpos($content, '<a') === false) {
        return ['content' => $content, 'rewritten' => 0];
    }
    if (!class_exists('WP_HTML_Tag_Processor')) {
        return new WP_Error(
            'legacy_lesson_link_rewriter_unavailable',
            __('The HTML link rewriter is unavailable.', 'll-tools-text-domain')
        );
    }

    $limit = max(10, min(1000, (int) apply_filters(
        'll_tools_legacy_lesson_link_rewrite_limit',
        250
    )));
    $processor = new WP_HTML_Tag_Processor($content);
    $visited = 0;
    $rewritten = 0;
    while ($processor->next_tag('A')) {
        $visited++;
        if ($visited > $limit) {
            return new WP_Error(
                'legacy_lesson_link_rewrite_too_large',
                __('The lesson contains too many links to rewrite in one bounded pass.', 'll-tools-text-domain')
            );
        }

        $href = (string) $processor->get_attribute('href');
        $key = ll_tools_legacy_lesson_link_match_key($href);
        if ($key === '' || !isset($target_map[$key])) {
            continue;
        }

        $target_url = (string) $target_map[$key];
        $query = (string) wp_parse_url($href, PHP_URL_QUERY);
        $href_path = '/' . ltrim(
            (string) wp_parse_url($href, PHP_URL_PATH),
            '/'
        );
        if ($href_path === '/' && $query !== '') {
            parse_str($query, $query_args);
            unset(
                $query_args['p'],
                $query_args['page_id'],
                $query_args['attachment_id']
            );
            $query = http_build_query($query_args);
        }
        $fragment = (string) wp_parse_url($href, PHP_URL_FRAGMENT);
        if ($query !== '') {
            $target_url .= (str_contains($target_url, '?') ? '&' : '?') . $query;
        }
        if ($fragment !== '') {
            $target_url .= '#' . $fragment;
        }
        $processor->set_attribute('href', $target_url);
        $rewritten++;
    }

    return [
        'content' => $processor->get_updated_html(),
        'rewritten' => $rewritten,
    ];
}

/**
 * @return array{target_id:int,resolved:int,unresolved:string[],rewritten_links:int,changed:bool}|WP_Error
 */
function ll_tools_migrate_legacy_lesson_relations(
    int $source_post_id,
    int $wordset_id,
    array $args = []
) {
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return new WP_Error(
            'legacy_lesson_wordset_missing',
            __('Select a valid target word set.', 'll-tools-text-domain')
        );
    }
    $target_id = ll_tools_resolve_content_lesson_by_legacy_source($source_post_id);
    if (is_wp_error($target_id)) {
        return $target_id;
    }
    $target_id = (int) $target_id;
    if ($target_id <= 0) {
        return new WP_Error(
            'legacy_lesson_target_missing',
            __('Migrate the lesson records before migrating their prerequisites.', 'll-tools-text-domain')
        );
    }

    $target_snapshot = ll_tools_legacy_lesson_existing_target_snapshot(
        $target_id,
        $wordset_id
    );
    if (is_wp_error($target_snapshot)) {
        return $target_snapshot;
    }
    $edge_limit = ll_tools_content_lesson_prerequisite_edge_limit($target_id);
    $dependency_snapshot = ll_tools_legacy_lesson_meta_snapshot(
        'post',
        $source_post_id,
        ['post_dependency'],
        ['post_dependency'],
        $edge_limit,
        [
            'query' => [
                'code' => 'legacy_lesson_dependency_query_incomplete',
                'message' => __(
                    'The legacy lesson prerequisites could not be read completely.',
                    'll-tools-text-domain'
                ),
            ],
            'too_large' => [
                'code' => 'content_lesson_prerequisite_graph_too_large',
                'message' => __(
                    'The prerequisite graph is too large to validate safely.',
                    'll-tools-text-domain'
                ),
            ],
        ]
    );
    if (is_wp_error($dependency_snapshot)) {
        return $dependency_snapshot;
    }
    $dependency_values = (array) ($dependency_snapshot['post_dependency'] ?? []);
    $dependency_source_map = ll_tools_legacy_lesson_dependency_source_map(
        $dependency_values
    );
    if (is_wp_error($dependency_source_map)) {
        return $dependency_source_map;
    }
    $target_prerequisite_ids = [];
    $unresolved = [];
    foreach ($dependency_values as $dependency_value) {
        if (!is_scalar($dependency_value)) {
            continue;
        }
        $dependency_value = trim((string) $dependency_value);
        if ($dependency_value === '') {
            continue;
        }
        $dependency_source_id = (int) ($dependency_source_map[$dependency_value] ?? 0);
        $dependency_target_id = $dependency_source_id > 0
            ? ll_tools_resolve_content_lesson_by_legacy_source($dependency_source_id)
            : 0;
        if (is_wp_error($dependency_target_id)) {
            return $dependency_target_id;
        }
        $dependency_target_id = (int) $dependency_target_id;
        if ($dependency_target_id <= 0) {
            $safe_dependency = sanitize_text_field($dependency_value);
            if ($safe_dependency !== '') {
                $unresolved[$safe_dependency] = $safe_dependency;
            }
            continue;
        }
        $target_prerequisite_ids[] = $dependency_target_id;
    }
    $unresolved = array_values($unresolved);
    $normalization_complete = true;
    $target_prerequisite_ids = ll_tools_content_lesson_normalize_lesson_ids(
        $target_prerequisite_ids,
        $normalization_complete
    );
    if (!$normalization_complete) {
        return new WP_Error(
            'content_lesson_relation_query_incomplete',
            __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count($target_prerequisite_ids) > $edge_limit) {
        return new WP_Error(
            'content_lesson_prerequisite_graph_too_large',
            __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
        );
    }
    $relation_filter_complete = true;
    $target_prerequisite_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
        $wordset_id,
        $target_prerequisite_ids,
        $target_id,
        $relation_filter_complete
    );
    if (!$relation_filter_complete) {
        return new WP_Error(
            'content_lesson_relation_query_incomplete',
            __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
        );
    }
    $graph_result = ll_tools_validate_content_lesson_prerequisite_graph(
        $target_id,
        $wordset_id,
        $target_prerequisite_ids
    );
    if (is_wp_error($graph_result)) {
        return $graph_result;
    }

    $link_target_map = ll_tools_legacy_lesson_link_target_map($wordset_id);
    if (is_wp_error($link_target_map)) {
        return $link_target_map;
    }
    $target_post = $target_snapshot['post'];
    $link_result = ll_tools_rewrite_legacy_lesson_links(
        (string) $target_post->post_content,
        $link_target_map
    );
    if (is_wp_error($link_result)) {
        return $link_result;
    }
    $rewritten_content = (string) $link_result['content'];
    $rewritten_links = (int) $link_result['rewritten'];

    $existing = ll_tools_get_content_lesson_prereq_lesson_ids($target_id);
    $changed = $existing !== $target_prerequisite_ids
        || ll_tools_legacy_lesson_array_meta_value(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META
        ) !== $unresolved
        || $rewritten_content !== (string) $target_post->post_content;
    if (!empty($args['apply'])) {
        if (empty($target_prerequisite_ids)) {
            delete_post_meta($target_id, LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META);
        } else {
            update_post_meta(
                $target_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                $target_prerequisite_ids
            );
        }
        if (empty($unresolved)) {
            delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META);
        } else {
            update_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META, $unresolved);
        }
        if ($rewritten_content !== (string) $target_post->post_content) {
            $content_update = wp_update_post(
                wp_slash([
                    'ID' => $target_id,
                    'post_content' => $rewritten_content,
                ]),
                true
            );
            if (is_wp_error($content_update)) {
                return $content_update;
            }
        }

        $stored_post = get_post($target_id);
        if (ll_tools_get_content_lesson_prereq_lesson_ids($target_id) !== $target_prerequisite_ids
            || ll_tools_legacy_lesson_array_meta_value(
                $target_id,
                LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META
            ) !== $unresolved
            || !($stored_post instanceof WP_Post)
            || (string) $stored_post->post_content !== $rewritten_content
        ) {
            return new WP_Error(
                'legacy_lesson_relation_verification_failed',
                __('The migrated lesson relations could not be verified after saving.', 'll-tools-text-domain')
            );
        }
    }

    return [
        'target_id' => $target_id,
        'resolved' => count($target_prerequisite_ids),
        'unresolved' => $unresolved,
        'rewritten_links' => $rewritten_links,
        'changed' => $changed,
    ];
}

/**
 * Extract only numeric values found under a Favorites `posts` key. This
 * handles the indexed site/group shape used by Simple Favorites without
 * mistaking site IDs or group IDs for completed lessons.
 *
 * @param mixed $raw
 * @return int[]|WP_Error
 */
function ll_tools_extract_legacy_favorite_post_ids($raw) {
    $raw = maybe_unserialize($raw);
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    $queue = [[$raw, 0]];
    $node_limit = max(1, min(100000, (int) apply_filters(
        'll_tools_legacy_favorites_node_limit',
        10000
    )));
    $association_limit = max(1, min(200000, (int) apply_filters(
        'll_tools_legacy_favorites_association_limit',
        20000
    )));
    $visited_nodes = 0;
    $visited_associations = 0;
    for ($offset = 0; $offset < count($queue); $offset++) {
        [$node, $depth] = $queue[$offset];
        if (!is_array($node) || $depth > 12) {
            continue;
        }
        foreach ($node as $key => $value) {
            $visited_nodes++;
            if ($visited_nodes > $node_limit) {
                return new WP_Error(
                    'legacy_favorites_structure_too_large',
                    __('The legacy Favorites structure exceeds the configured safe limit.', 'll-tools-text-domain')
                );
            }
            if ((string) $key === 'posts' && is_array($value)) {
                foreach ($value as $post_id) {
                    $visited_associations++;
                    if ($visited_associations > $association_limit) {
                        return new WP_Error(
                            'legacy_favorites_associations_too_large',
                            __('The legacy Favorites associations exceed the configured safe limit.', 'll-tools-text-domain')
                        );
                    }
                    if (is_numeric($post_id) && (int) $post_id > 0) {
                        $ids[(int) $post_id] = (int) $post_id;
                    }
                }
                continue;
            }
            if (is_array($value)) {
                $queue[] = [$value, $depth + 1];
            }
        }
    }

    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * @return array<int,int>|WP_Error source post ID => content lesson ID
 */
function ll_tools_legacy_lesson_source_target_map(int $wordset_id) {
    global $wpdb;

    $limit = max(50, min(2000, (int) apply_filters(
        'll_tools_legacy_lesson_mapping_limit',
        500,
        $wordset_id
    )));
    $sql = $wpdb->prepare(
        "SELECT DISTINCT target.ID AS target_id, source_meta.meta_value AS source_id
         FROM {$wpdb->posts} target
         INNER JOIN {$wpdb->postmeta} wordset_meta
            ON wordset_meta.post_id = target.ID
           AND wordset_meta.meta_key = %s
           AND wordset_meta.meta_value = %s
         INNER JOIN {$wpdb->postmeta} source_meta
            ON source_meta.post_id = target.ID
           AND source_meta.meta_key = %s
         INNER JOIN {$wpdb->posts} source_post
            ON source_post.ID = CAST(source_meta.meta_value AS UNSIGNED)
           AND source_post.post_type = 'post'
           AND source_post.post_status = 'publish'
         WHERE target.post_type = 'll_content_lesson'
           AND target.post_status IN ('publish', 'draft', 'pending', 'future', 'private')
         ORDER BY target.ID ASC
         LIMIT %d",
        LL_TOOLS_CONTENT_LESSON_WORDSET_META,
        (string) $wordset_id,
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        $limit + 1
    );
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_mapping_query_incomplete',
            __('The migrated lesson mapping could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $rows) > $limit) {
        return new WP_Error(
            'legacy_lesson_mapping_too_large',
            __('The migrated lesson mapping exceeds the configured safe limit.', 'll-tools-text-domain')
        );
    }

    $map = [];
    foreach ((array) $rows as $row) {
        $target_id = (int) ($row->target_id ?? 0);
        $source_id = (int) ($row->source_id ?? 0);
        if ($source_id <= 0) {
            continue;
        }
        if (isset($map[$source_id]) && $map[$source_id] !== $target_id) {
            return new WP_Error(
                'legacy_lesson_duplicate_target_mapping',
                sprintf(
                    /* translators: %d: legacy source post ID */
                    __('Legacy source post %d maps to more than one content lesson.', 'll-tools-text-domain'),
                    $source_id
                )
            );
        }
        $map[$source_id] = $target_id;
    }
    return $map;
}

/**
 * Read all legacy and canonical completion rows for one bounded user page.
 *
 * @param int[] $user_ids
 * @return array<int,array<string,array{exists:bool,meta_id:int,raw:mixed,stored:string}>>|WP_Error
 */
function ll_tools_legacy_lesson_completion_meta_snapshot(array $user_ids) {
    global $wpdb;

    $user_ids = array_values(array_unique(array_filter(array_map('absint', $user_ids))));
    if (count($user_ids) > 500) {
        return new WP_Error(
            'legacy_lesson_completion_meta_scope_too_large',
            __('The completion metadata user scope exceeds the configured safe limit.', 'll-tools-text-domain')
        );
    }
    if (empty($user_ids)) {
        return [];
    }

    $meta_keys = [
        'simplefavorites',
        'tt_completed_lessons',
        LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
    ];
    $user_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
    $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
    $row_limit = count($user_ids) * count($meta_keys);
    $query_args = array_merge($user_ids, $meta_keys, [$row_limit + 1]);
    $sql = $wpdb->prepare(
        "SELECT umeta_id, user_id, meta_key, meta_value
         FROM {$wpdb->usermeta}
         WHERE user_id IN ({$user_placeholders})
           AND meta_key IN ({$key_placeholders})
         ORDER BY user_id ASC, umeta_id ASC
         LIMIT %d",
        ...$query_args
    );

    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_completion_meta_query_incomplete',
            __('The legacy completion metadata could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $rows) > $row_limit) {
        return new WP_Error(
            'legacy_lesson_completion_meta_ambiguous',
            __('User completion metadata contains duplicate rows and was not changed.', 'll-tools-text-domain')
        );
    }

    $empty_entry = [
        'exists' => false,
        'meta_id' => 0,
        'raw' => null,
        'stored' => '',
    ];
    $snapshot = [];
    foreach ($user_ids as $user_id) {
        $snapshot[$user_id] = array_fill_keys($meta_keys, $empty_entry);
    }
    foreach ((array) $rows as $row) {
        $user_id = (int) ($row['user_id'] ?? 0);
        $meta_key = (string) ($row['meta_key'] ?? '');
        if (!isset($snapshot[$user_id]) || !array_key_exists($meta_key, $snapshot[$user_id])) {
            continue;
        }
        if (!empty($snapshot[$user_id][$meta_key]['exists'])) {
            return new WP_Error(
                'legacy_lesson_completion_meta_ambiguous',
                __('User completion metadata contains duplicate rows and was not changed.', 'll-tools-text-domain'),
                ['user_id' => $user_id, 'meta_key' => $meta_key]
            );
        }
        $stored = (string) ($row['meta_value'] ?? '');
        $snapshot[$user_id][$meta_key] = [
            'exists' => true,
            'meta_id' => (int) ($row['umeta_id'] ?? 0),
            'raw' => maybe_unserialize($stored),
            'stored' => $stored,
        ];
    }

    return $snapshot;
}

/**
 * Merge canonical completion IDs with compare-and-swap retries and fresh,
 * fail-closed readback verification.
 *
 * @param int[] $mapped_ids
 * @return int[]|WP_Error
 */
function ll_tools_legacy_lesson_merge_completion_ids(
    int $user_id,
    array $mapped_ids
) {
    global $wpdb;

    $merge_complete = true;
    $mapped_ids = ll_tools_normalize_completed_content_lesson_ids(
        $mapped_ids,
        $merge_complete
    );
    if (!$merge_complete) {
        return new WP_Error(
            'legacy_lesson_completion_canonical_too_large',
            sprintf(
                __('User %d canonical completion data exceeds the configured safe limit.', 'll-tools-text-domain'),
                $user_id
            )
        );
    }

    $attempt_limit = max(2, min(10, (int) apply_filters(
        'll_tools_content_lesson_completion_cas_attempts',
        5,
        $user_id
    )));
    for ($attempt = 0; $attempt < $attempt_limit; $attempt++) {
        $fresh_snapshot = ll_tools_legacy_lesson_completion_meta_snapshot([$user_id]);
        if (is_wp_error($fresh_snapshot)) {
            return $fresh_snapshot;
        }
        $entry = $fresh_snapshot[$user_id][LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META];
        $before_complete = true;
        $before_ids = ll_tools_normalize_completed_content_lesson_ids(
            $entry['raw'] ?? null,
            $before_complete
        );
        $next_complete = true;
        $next_ids = ll_tools_normalize_completed_content_lesson_ids(
            array_merge($before_ids, $mapped_ids),
            $next_complete
        );
        if (!$before_complete || !$next_complete) {
            return new WP_Error(
                'legacy_lesson_completion_canonical_too_large',
                sprintf(
                    __('User %d canonical completion data exceeds the configured safe limit.', 'll-tools-text-domain'),
                    $user_id
                )
            );
        }
        if ($next_ids === $before_ids) {
            return $before_ids;
        }

        $wpdb->last_error = '';
        if (empty($entry['exists'])) {
            $written = add_user_meta(
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                $next_ids,
                true
            ) !== false;
        } else {
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->usermeta}
                 SET meta_value = %s
                 WHERE umeta_id = %d
                   AND user_id = %d
                   AND meta_key = %s
                   AND meta_value = %s",
                maybe_serialize($next_ids),
                (int) ($entry['meta_id'] ?? 0),
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                (string) ($entry['stored'] ?? '')
            )) === 1;
        }
        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'legacy_lesson_completion_meta_write_failed',
                __('User completion metadata could not be updated safely.', 'll-tools-text-domain'),
                ['user_id' => $user_id]
            );
        }
        wp_cache_delete($user_id, 'user_meta');

        $fresh_snapshot = ll_tools_legacy_lesson_completion_meta_snapshot([$user_id]);
        if (is_wp_error($fresh_snapshot)) {
            return $fresh_snapshot;
        }
        $entry = $fresh_snapshot[$user_id][LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META];
        if (!$written) {
            continue;
        }

        $stored_complete = true;
        $stored_ids = ll_tools_normalize_completed_content_lesson_ids(
            $entry['raw'] ?? null,
            $stored_complete
        );
        if (!$stored_complete) {
            return new WP_Error(
                'legacy_lesson_completion_canonical_too_large',
                sprintf(
                    __('User %d canonical completion data exceeds the configured safe limit.', 'll-tools-text-domain'),
                    $user_id
                )
            );
        }
        $stored_lookup = array_fill_keys($stored_ids, true);
        $verified = true;
        foreach ($mapped_ids as $mapped_id) {
            if (!isset($stored_lookup[$mapped_id])) {
                $verified = false;
                break;
            }
        }
        if ($verified) {
            return $stored_ids;
        }
    }

    return new WP_Error(
        'legacy_lesson_completion_meta_write_failed',
        __('User completion metadata could not be updated safely.', 'll-tools-text-domain'),
        ['user_id' => $user_id]
    );
}

/**
 * @return array{processed:int,changed_users:int,source_associations:int,mapped_associations:int,unmapped_associations:int,unmapped_source_ids:int[],next_cursor:int,has_more:bool,errors:string[]}|WP_Error
 */
function ll_tools_migrate_legacy_lesson_completions_batch(
    int $wordset_id,
    array $args = []
) {
    global $wpdb;

    $after_user_id = max(0, (int) ($args['after_id'] ?? 0));
    $limit = ll_tools_legacy_lesson_batch_limit($args['limit'] ?? 100);
    $wpdb->last_error = '';
    $user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT user_id
         FROM {$wpdb->usermeta}
         WHERE user_id > %d
           AND meta_key IN ('simplefavorites', 'tt_completed_lessons')
         ORDER BY user_id ASC
         LIMIT %d",
        $after_user_id,
        $limit + 1
    ));
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'legacy_lesson_completion_query_incomplete',
            __('The legacy completion user page could not be read completely.', 'll-tools-text-domain')
        );
    }
    $user_ids = array_map('intval', (array) $user_ids);
    $has_more = count($user_ids) > $limit;
    $user_ids = array_slice($user_ids, 0, $limit);
    $source_target_map = ll_tools_legacy_lesson_source_target_map($wordset_id);
    if (is_wp_error($source_target_map)) {
        return $source_target_map;
    }
    $completion_meta_snapshot = ll_tools_legacy_lesson_completion_meta_snapshot($user_ids);
    if (is_wp_error($completion_meta_snapshot)) {
        return $completion_meta_snapshot;
    }
    $audit_run_id = '';
    $existing_audit = [];
    if (!empty($args['apply'])) {
        $requested_run_id = sanitize_text_field((string) ($args['run_id'] ?? ''));
        $stored_audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        if ($after_user_id === 0) {
            $stored_run_id = is_array($stored_audit)
                ? sanitize_text_field((string) ($stored_audit['audit_run_id'] ?? ''))
                : '';
            if ($requested_run_id !== '') {
                if (!is_array($stored_audit)
                    || (int) ($stored_audit['wordset_id'] ?? 0) !== $wordset_id
                    || $stored_run_id === ''
                    || !hash_equals($stored_run_id, $requested_run_id)
                ) {
                    return new WP_Error(
                        'legacy_completion_audit_sequence_invalid',
                        __('Start the completion migration at cursor zero, then pass its audit run ID to every continuation.', 'll-tools-text-domain')
                    );
                }
                $audit_run_id = $stored_run_id;
                $existing_audit = $stored_audit;
            } else {
                $audit_run_id = wp_generate_uuid4();
                $existing_audit = [
                    'wordset_id' => $wordset_id,
                    'audit_run_id' => $audit_run_id,
                    'pages' => [],
                ];
            }
        } else {
            $stored_run_id = is_array($stored_audit)
                ? sanitize_text_field((string) ($stored_audit['audit_run_id'] ?? ''))
                : '';
            if (!is_array($stored_audit)
                || (int) ($stored_audit['wordset_id'] ?? 0) !== $wordset_id
                || $stored_run_id === ''
                || $requested_run_id === ''
                || !hash_equals($stored_run_id, $requested_run_id)
            ) {
                return new WP_Error(
                    'legacy_completion_audit_sequence_invalid',
                    __('Start the completion migration at cursor zero, then pass its audit run ID to every continuation.', 'll-tools-text-domain')
                );
            }
            $audit_run_id = $stored_run_id;
            $existing_audit = $stored_audit;
        }
    }
    $summary = [
        'processed' => 0,
        'changed_users' => 0,
        'source_associations' => 0,
        'mapped_associations' => 0,
        'unmapped_associations' => 0,
        'unmapped_source_ids' => [],
        'next_cursor' => empty($user_ids) ? $after_user_id : max($user_ids),
        'has_more' => $has_more,
        'errors' => [],
        'audit_run_id' => $audit_run_id,
    ];
    $unmapped_source_ids = [];

    foreach ($user_ids as $user_id) {
        $user_completion_meta = $completion_meta_snapshot[$user_id];
        $favorite_ids = ll_tools_extract_legacy_favorite_post_ids(
            $user_completion_meta['simplefavorites']['raw']
        );
        if (is_wp_error($favorite_ids)) {
            $summary['processed']++;
            $summary['errors'][] = sprintf(
                /* translators: 1: user ID, 2: migration error */
                __('User %1$d completion data was skipped: %2$s', 'll-tools-text-domain'),
                $user_id,
                $favorite_ids->get_error_message()
            );
            continue;
        }
        $legacy_completion_complete = true;
        $legacy_completion_ids = ll_tools_normalize_completed_content_lesson_ids(
            $user_completion_meta['tt_completed_lessons']['raw'],
            $legacy_completion_complete
        );
        if (!$legacy_completion_complete) {
            $summary['processed']++;
            $summary['errors'][] = sprintf(
                __('User %d legacy completion data exceeds the configured safe limit.', 'll-tools-text-domain'),
                $user_id
            );
            continue;
        }
        $source_ids = array_values(array_unique(array_merge(
            $favorite_ids,
            $legacy_completion_ids
        )));
        $summary['processed']++;
        $summary['source_associations'] += count($source_ids);

        $mapped_ids = [];
        foreach ($source_ids as $source_id) {
            if (isset($source_target_map[$source_id])) {
                $mapped_ids[] = (int) $source_target_map[$source_id];
                $summary['mapped_associations']++;
            } else {
                $summary['unmapped_associations']++;
                if (count($unmapped_source_ids) < 500) {
                    $unmapped_source_ids[$source_id] = $source_id;
                }
            }
        }
        $existing_ids_complete = true;
        $existing_ids = ll_tools_normalize_completed_content_lesson_ids(
            $user_completion_meta[LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META]['raw'],
            $existing_ids_complete
        );
        if (!$existing_ids_complete) {
            $summary['errors'][] = sprintf(
                /* translators: %d: user ID */
                __('User %d canonical completion data exceeds the configured safe limit.', 'll-tools-text-domain'),
                $user_id
            );
            continue;
        }
        $next_ids_complete = true;
        $next_ids = ll_tools_normalize_completed_content_lesson_ids(
            array_merge($existing_ids, $mapped_ids),
            $next_ids_complete
        );
        if (!$next_ids_complete) {
            $summary['errors'][] = sprintf(
                /* translators: %d: user ID */
                __('User %d canonical completion data is at the configured safe limit and could not accept migrated lessons.', 'll-tools-text-domain'),
                $user_id
            );
            continue;
        }
        $planned_change = $next_ids !== $existing_ids;
        if ($planned_change) {
            $summary['changed_users']++;
        }
        if (empty($args['apply'])) {
            continue;
        }
        if (empty($mapped_ids)) {
            continue;
        }
        $stored_ids = ll_tools_legacy_lesson_merge_completion_ids(
            $user_id,
            $mapped_ids
        );
        if (is_wp_error($stored_ids)) {
            return $stored_ids;
        }
        $stored_lookup = array_fill_keys($stored_ids, true);
        foreach ($mapped_ids as $mapped_id) {
            if (!isset($stored_lookup[(int) $mapped_id])) {
                $summary['errors'][] = sprintf(
                    __('User %d completion data failed readback verification.', 'll-tools-text-domain'),
                    $user_id
                );
                break;
            }
        }
    }

    $summary['unmapped_source_ids'] = array_values($unmapped_source_ids);
    sort($summary['unmapped_source_ids'], SORT_NUMERIC);
    if (!empty($args['apply'])) {
        $pages = isset($existing_audit['pages']) && is_array($existing_audit['pages'])
            ? $existing_audit['pages']
            : [];
        if (!isset($pages[(string) $after_user_id]) && count($pages) >= 2000) {
            return new WP_Error(
                'legacy_completion_audit_too_large',
                __('The completion migration audit exceeds the configured safe page limit.', 'll-tools-text-domain')
            );
        }
        $page_summary = [
            'after_cursor' => $after_user_id,
            'processed' => (int) $summary['processed'],
            'changed_users' => (int) $summary['changed_users'],
            'source_associations' => (int) $summary['source_associations'],
            'mapped_associations' => (int) $summary['mapped_associations'],
            'unmapped_associations' => (int) $summary['unmapped_associations'],
            'unmapped_source_ids' => array_values($summary['unmapped_source_ids']),
            'errors' => array_values($summary['errors']),
            'next_cursor' => (int) $summary['next_cursor'],
            'has_more' => (bool) $summary['has_more'],
        ];
        $pages[(string) $after_user_id] = $page_summary;

        $aggregate = [
            'wordset_id' => $wordset_id,
            'audit_run_id' => $audit_run_id,
            'pages' => $pages,
            'processed' => 0,
            'changed_users' => 0,
            'source_associations' => 0,
            'mapped_associations' => 0,
            'unmapped_associations' => 0,
            'unmapped_source_ids' => [],
            'errors' => [],
            'next_cursor' => 0,
            'completed' => false,
            'updated_at' => gmdate('c'),
        ];
        $cursor = 0;
        $seen_cursors = [];
        $reached_tail = false;
        while (isset($pages[(string) $cursor]) && empty($seen_cursors[$cursor])) {
            $seen_cursors[$cursor] = true;
            $page = (array) $pages[(string) $cursor];
            foreach ([
                'processed',
                'changed_users',
                'source_associations',
                'mapped_associations',
                'unmapped_associations',
            ] as $count_key) {
                $aggregate[$count_key] += max(0, (int) ($page[$count_key] ?? 0));
            }
            $aggregate['unmapped_source_ids'] = array_slice(array_values(array_unique(array_merge(
                array_map('absint', (array) $aggregate['unmapped_source_ids']),
                array_map('absint', (array) ($page['unmapped_source_ids'] ?? []))
            ))), 0, 500);
            $aggregate['errors'] = array_slice(array_values(array_unique(array_merge(
                (array) $aggregate['errors'],
                array_map('strval', (array) ($page['errors'] ?? []))
            ))), -100);
            $next_cursor = max(0, (int) ($page['next_cursor'] ?? $cursor));
            $aggregate['next_cursor'] = $next_cursor;
            if (empty($page['has_more'])) {
                $reached_tail = true;
                break;
            }
            if ($next_cursor <= $cursor) {
                break;
            }
            $cursor = $next_cursor;
        }
        sort($aggregate['unmapped_source_ids'], SORT_NUMERIC);
        $aggregate['completed'] = $reached_tail && empty($aggregate['errors']);
        update_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, $aggregate, false);
        $stored_audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        if (!is_array($stored_audit)
            || (string) ($stored_audit['audit_run_id'] ?? '') !== $audit_run_id
            || (array) ($stored_audit['pages'][(string) $after_user_id] ?? []) !== $page_summary
        ) {
            return new WP_Error(
                'legacy_completion_audit_write_failed',
                __('The completion migration audit could not be verified after saving.', 'll-tools-text-domain')
            );
        }
    }

    return $summary;
}

/**
 * Run one resumable migration page.
 *
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_migrate_legacy_content_lessons_batch(array $args) {
    $phase = sanitize_key((string) ($args['phase'] ?? 'lessons'));
    $retained_source_contract = ll_tools_validate_legacy_lesson_retained_source_args(
        array_merge($args, ['phase' => $phase])
    );
    if (is_wp_error($retained_source_contract)) {
        return $retained_source_contract;
    }
    $wordset_id = max(0, (int) ($args['wordset_id'] ?? 0));
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return new WP_Error(
            'legacy_lesson_wordset_missing',
            __('Select a valid target word set.', 'll-tools-text-domain')
        );
    }
    if ($phase === 'completions') {
        $completion_summary = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            $args
        );
        if (is_wp_error($completion_summary)) {
            return $completion_summary;
        }
        return array_merge(
            [
                'phase' => $phase,
                'apply' => !empty($args['apply']),
                'wordset_id' => $wordset_id,
            ],
            $completion_summary
        );
    }
    if (!in_array($phase, ['lessons', 'relations'], true)) {
        return new WP_Error(
            'legacy_lesson_phase_invalid',
            __('Use the lessons, relations, or completions migration phase.', 'll-tools-text-domain')
        );
    }
    if ($phase === 'lessons') {
        $requested_status = ll_tools_legacy_lesson_requested_status($args);
        if (is_wp_error($requested_status)) {
            return $requested_status;
        }
        if ($requested_status !== '') {
            $args['status'] = $requested_status;
        }
    }

    $page = ll_tools_legacy_lesson_source_page($args);
    if (is_wp_error($page)) {
        return $page;
    }
    $summary = [
        'phase' => $phase,
        'apply' => !empty($args['apply']),
        'wordset_id' => $wordset_id,
        'retained_source' => !empty($args['retained_source']),
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'resolved_dependencies' => 0,
        'unresolved_dependencies' => 0,
        'rewritten_links' => 0,
        'failed_source_ids' => [],
        'next_cursor' => (int) $page['next_cursor'],
        'has_more' => (bool) $page['has_more'],
        'errors' => [],
    ];
    foreach ((array) $page['ids'] as $source_id) {
        $source_id = (int) $source_id;
        $result = $phase === 'lessons'
            ? ll_tools_migrate_legacy_lesson_post($source_id, $wordset_id, $args)
            : ll_tools_migrate_legacy_lesson_relations($source_id, $wordset_id, $args);
        $summary['processed']++;
        if (is_wp_error($result)) {
            $summary['failed_source_ids'][] = $source_id;
            $summary['errors'][] = sprintf(
                __('Source post %1$d: %2$s', 'll-tools-text-domain'),
                $source_id,
                $result->get_error_message()
            );
            continue;
        }
        if ($phase === 'lessons') {
            if (!empty($result['created'])) {
                $summary['created']++;
            } elseif (!empty($result['changed'])) {
                $summary['updated']++;
            } else {
                $summary['unchanged']++;
            }
        } else {
            $summary['resolved_dependencies'] += (int) ($result['resolved'] ?? 0);
            $summary['unresolved_dependencies'] += count((array) ($result['unresolved'] ?? []));
            $summary['rewritten_links'] += (int) ($result['rewritten_links'] ?? 0);
            if (!empty($result['changed'])) {
                $summary['updated']++;
            } else {
                $summary['unchanged']++;
            }
        }
    }

    $summary['failed_source_ids'] = array_values(array_unique(array_map(
        'absint',
        $summary['failed_source_ids']
    )));
    return $summary;
}
