<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META')) {
    define('LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META', '_ll_tools_legacy_lesson_source_post_id');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META')) {
    define('LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META', '_ll_tools_legacy_lesson_source_url');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_MIGRATION_META')) {
    define('LL_TOOLS_LEGACY_LESSON_MIGRATION_META', '_ll_tools_legacy_lesson_migration_version');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META')) {
    define('LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META', '_ll_tools_legacy_lesson_retained_source');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META')) {
    define('LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META', '_ll_tools_legacy_lesson_unresolved_dependencies');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_CONCEPTS_META')) {
    define('LL_TOOLS_LEGACY_LESSON_CONCEPTS_META', '_ll_tools_legacy_lesson_concepts');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_CATEGORIES_META')) {
    define('LL_TOOLS_LEGACY_LESSON_CATEGORIES_META', '_ll_tools_legacy_lesson_categories');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META')) {
    define('LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META', '_ll_tools_legacy_lesson_category_id');
}
if (!defined('LL_TOOLS_LEGACY_LESSON_DEFAULT_WORDSET_OPTION')) {
    define('LL_TOOLS_LEGACY_LESSON_DEFAULT_WORDSET_OPTION', 'll_tools_legacy_lesson_default_wordset');
}

/**
 * Treat any retained-source marker row as a catalog exclusion.
 *
 * Runtime URL and redirect behavior requires one exact `1` row, but malformed
 * or duplicate rows still fail closed by keeping the target out of catalogs.
 */
function ll_tools_legacy_lesson_has_retained_source_marker(int $lesson_id): bool {
    return $lesson_id > 0
        && get_post_meta($lesson_id, LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META, false) !== [];
}

/**
 * Return whether a content lesson has one valid retained-source marker.
 */
function ll_tools_legacy_lesson_is_retained_source_target(int $lesson_id): bool {
    if ($lesson_id <= 0 || get_post_type($lesson_id) !== 'll_content_lesson') {
        return false;
    }

    $values = get_post_meta(
        $lesson_id,
        LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        false
    );
    return count($values) === 1
        && is_scalar($values[0])
        && (string) $values[0] === '1';
}

/**
 * Reusable fail-closed clause for normal content-lesson catalogs.
 *
 * @return array{key:string,compare:string}
 */
function ll_tools_legacy_lesson_retained_source_catalog_exclusion(): array {
    return [
        'key' => LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        'compare' => 'NOT EXISTS',
    ];
}

/**
 * Add the retained-source exclusion to an existing WP_Query argument array.
 *
 * Wrapping an existing meta query preserves its OR/AND semantics.
 */
function ll_tools_legacy_lesson_exclude_retained_sources_from_query_args(
    array $query_args
): array {
    $existing_meta_query = $query_args['meta_query'] ?? [];
    if (!is_array($existing_meta_query) || $existing_meta_query === []) {
        $query_args['meta_query'] = [
            ll_tools_legacy_lesson_retained_source_catalog_exclusion(),
        ];
        return $query_args;
    }

    $query_args['meta_query'] = [
        'relation' => 'AND',
        $existing_meta_query,
        ll_tools_legacy_lesson_retained_source_catalog_exclusion(),
    ];
    return $query_args;
}

/**
 * Resolve the current canonical URL of a valid published retained source.
 */
function ll_tools_get_legacy_lesson_retained_source_url(int $lesson_id): string {
    if (!ll_tools_legacy_lesson_is_retained_source_target($lesson_id)
        || get_post_status($lesson_id) !== 'publish'
    ) {
        return '';
    }

    $source_values = get_post_meta(
        $lesson_id,
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        false
    );
    if (count($source_values) !== 1
        || !is_scalar($source_values[0])
        || absint($source_values[0]) <= 0
    ) {
        return '';
    }

    $source = get_post(absint($source_values[0]));
    if (!($source instanceof WP_Post)
        || $source->post_type !== 'post'
        || $source->post_status !== 'publish'
    ) {
        return '';
    }

    $source_url = get_permalink($source);
    if (!is_string($source_url) || $source_url === '') {
        return '';
    }

    $source_scheme = strtolower((string) wp_parse_url($source_url, PHP_URL_SCHEME));
    $source_host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if (!in_array($source_scheme, ['http', 'https'], true)
        || $source_host === ''
        || $home_host === ''
        || $source_host !== $home_host
    ) {
        return '';
    }

    return esc_url_raw($source_url);
}

/**
 * Normalize the legacy WordPress category IDs retained on migrated lessons.
 *
 * The exact repeated-meta representation is intentionally separate from the
 * richer category snapshot. It lets public indexes use equality comparisons
 * instead of scanning or matching arbitrary serialized category structures.
 *
 * @param mixed $raw
 * @return int[]
 */
function ll_tools_normalize_legacy_lesson_category_ids($raw): array {
    if (!is_array($raw)) {
        $raw = preg_split('/[\s,]+/', trim((string) $raw));
    }

    $ids = [];
    foreach ((array) $raw as $raw_id) {
        $category_id = absint($raw_id);
        if ($category_id <= 0) {
            continue;
        }
        $ids[$category_id] = $category_id;
        if (count($ids) >= 100) {
            break;
        }
    }

    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * @return int[]
 */
function ll_tools_get_legacy_lesson_category_ids(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [];
    }

    return ll_tools_normalize_legacy_lesson_category_ids(
        get_post_meta($lesson_id, LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META, false)
    );
}

/**
 * Replace the exact repeated category-ID rows with rollback on write failure.
 */
function ll_tools_set_legacy_lesson_category_ids(int $lesson_id, array $category_ids): bool {
    if ($lesson_id <= 0) {
        return false;
    }

    $category_ids = ll_tools_normalize_legacy_lesson_category_ids($category_ids);
    $previous_ids = ll_tools_get_legacy_lesson_category_ids($lesson_id);
    if ($previous_ids === $category_ids) {
        return true;
    }

    delete_post_meta($lesson_id, LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META);
    $write_succeeded = true;
    foreach ($category_ids as $category_id) {
        if (add_post_meta(
            $lesson_id,
            LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META,
            (string) $category_id,
            false
        ) === false) {
            $write_succeeded = false;
            break;
        }
    }

    if ($write_succeeded && ll_tools_get_legacy_lesson_category_ids($lesson_id) === $category_ids) {
        return true;
    }

    delete_post_meta($lesson_id, LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META);
    foreach ($previous_ids as $previous_id) {
        add_post_meta(
            $lesson_id,
            LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META,
            (string) $previous_id,
            false
        );
    }
    return false;
}

function ll_tools_get_legacy_lesson_default_wordset_id(): int {
    $wordset_id = absint(get_option(LL_TOOLS_LEGACY_LESSON_DEFAULT_WORDSET_OPTION, 0));
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return 0;
    }

    return $wordset_id;
}

function ll_tools_set_legacy_lesson_default_wordset_id(int $wordset_id): bool {
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return false;
    }

    if (ll_tools_get_legacy_lesson_default_wordset_id() !== $wordset_id) {
        update_option(
            LL_TOOLS_LEGACY_LESSON_DEFAULT_WORDSET_OPTION,
            (string) $wordset_id,
            false
        );
    }

    return ll_tools_get_legacy_lesson_default_wordset_id() === $wordset_id;
}
