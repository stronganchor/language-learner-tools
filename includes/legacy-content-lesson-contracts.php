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
