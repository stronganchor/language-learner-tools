<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION', 'll_tools_wordset_isolation_enabled');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION', 'll_tools_wordset_isolation_migration_version');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT')) {
    define('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT', 'll_tools_wordset_isolation_migration_notice');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION', 'll_tools_wordset_isolation_migration_state');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION', 'll_tools_wordset_isolation_migration_lock');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK', 'll_tools_wordset_isolation_migration_batch');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK', 'll_tools_wordset_isolation_reconcile_generated_pages');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION', 'll_tools_wordset_isolation_reconciliation_state');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION', 'll_tools_wordset_isolation_reconciliation_lock');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT', 'll_tools_wordset_isolation_health_report');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL', DAY_IN_SECONDS);
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_VOCAB_LESSON_AUTO_REPAIR_TRANSIENT')) {
    define('LL_TOOLS_WORDSET_ISOLATION_VOCAB_LESSON_AUTO_REPAIR_TRANSIENT', 'll_tools_wordset_isolation_vocab_lesson_auto_repair');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK', 'll_tools_wordset_isolation_health_refresh');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION', 'll_tools_wordset_isolation_health_refresh_state');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK', 'll_tools_wordset_isolation_health_refresh_lock');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK', 'll_tools_wordset_isolation_vocab_repair');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION', 'll_tools_wordset_isolation_vocab_repair_state');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK')) {
    define('LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK', 'll_tools_wordset_isolation_vocab_repair_lock');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION', 5);
}
if (!defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
    define('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY', 'll_wordset_owner_id');
}
if (!defined('LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY')) {
    define('LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY', 'll_category_isolation_source_id');
}
if (!defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')) {
    define('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY', 'll_wordset_owner_id');
}
if (!defined('LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY')) {
    define('LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY', 'll_word_image_isolation_source_id');
}

function ll_tools_is_wordset_isolation_enabled(): bool {
    $raw = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1');
    if (function_exists('ll_tools_normalize_wordset_boolean_setting')) {
        return ll_tools_normalize_wordset_boolean_setting($raw) === 1;
    }

    if (is_bool($raw)) {
        return $raw;
    }
    if (is_numeric($raw)) {
        return ((int) $raw) === 1;
    }

    $normalized = strtolower(trim((string) $raw));
    return !in_array($normalized, ['', '0', 'false', 'no', 'off'], true);
}

function ll_tools_get_wordset_isolation_migration_version(): int {
    return max(0, (int) get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION, 0));
}

function ll_tools_get_wordset_isolation_owner_wordset_id($value): int {
    if ($value instanceof WP_Term) {
        return ($value->taxonomy === 'wordset') ? (int) $value->term_id : 0;
    }
    if (function_exists('ll_tools_resolve_wordset_term_id')) {
        return (int) ll_tools_resolve_wordset_term_id($value);
    }
    return max(0, (int) $value);
}

function ll_tools_get_single_wordset_owner_id_for_categories(array $category_ids): int {
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($category_ids)) {
        return 0;
    }

    $owners = [];
    foreach ($category_ids as $category_id) {
        $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
        if ($owner_id > 0) {
            $owners[$owner_id] = true;
        }
    }

    if (count($owners) !== 1) {
        return 0;
    }

    return (int) array_key_first($owners);
}

function ll_tools_lookup_existing_word_ids_by_title_in_wordset(string $title, int $wordset_term_id = 0): array {
    global $wpdb;

    $title = (string) $title;
    $wordset_term_id = (int) $wordset_term_id;

    if ($title === '') {
        return [];
    }

    if ($wordset_term_id > 0) {
        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wordset'
             WHERE tt.term_id = %d
               AND p.post_type = 'words'
               AND p.post_status NOT IN ('trash','auto-draft')
               AND p.post_title = %s",
            $wordset_term_id,
            $title
        );

        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    $sql = $wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
         LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wordset'
         WHERE tt.term_taxonomy_id IS NULL
           AND p.post_type = 'words'
           AND p.post_status NOT IN ('trash','auto-draft')
           AND p.post_title = %s",
        $title
    );

    return array_map('intval', (array) $wpdb->get_col($sql));
}

function ll_tools_find_existing_word_post_by_title_in_wordsets(string $title, array $wordset_ids = []): ?WP_Post {
    $title = sanitize_text_field($title);
    $title = trim($title);
    $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_map('intval', $wordset_ids), static function (int $id): bool {
            return $id > 0;
        }));
    if ($title === '') {
        return null;
    }

    if (!empty($wordset_ids) && function_exists('ll_tools_find_existing_word_ids_by_title_in_wordset')) {
        foreach ($wordset_ids as $wordset_id) {
            $matching_ids = ll_tools_find_existing_word_ids_by_title_in_wordset($title, (int) $wordset_id);
            if (empty($matching_ids)) {
                continue;
            }

            $post = get_post((int) $matching_ids[0]);
            if ($post instanceof WP_Post && $post->post_type === 'words') {
                return $post;
            }
        }
    } elseif (!empty($wordset_ids)) {
        foreach ($wordset_ids as $wordset_id) {
            $matching_ids = ll_tools_lookup_existing_word_ids_by_title_in_wordset($title, (int) $wordset_id);
            if (empty($matching_ids)) {
                continue;
            }

            $post = get_post((int) $matching_ids[0]);
            if ($post instanceof WP_Post && $post->post_type === 'words') {
                return $post;
            }
        }
    }

    if (!empty($wordset_ids) && ll_tools_is_wordset_isolation_enabled()) {
        return null;
    }

    if (function_exists('ll_find_post_by_exact_title')) {
        $post = ll_find_post_by_exact_title($title, 'words');
        if ($post instanceof WP_Post && $post->post_type === 'words') {
            return $post;
        }
    }

    return null;
}

function ll_tools_get_category_wordset_owner_id($category, ?bool &$complete = null): int {
    global $wpdb;

    $complete = true;
    $wpdb->last_error = '';
    $term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (!($term instanceof WP_Term) || is_wp_error($term) || $wpdb->last_error !== '') {
        if (is_wp_error($term) || $wpdb->last_error !== '') {
            $complete = false;
        }
        return 0;
    }

    $wpdb->last_error = '';
    $owner_id = max(0, (int) get_term_meta((int) $term->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    return $owner_id;
}

function ll_tools_get_category_isolation_source_id($category, ?bool &$complete = null): int {
    global $wpdb;

    $complete = true;
    $wpdb->last_error = '';
    $term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (!($term instanceof WP_Term) || is_wp_error($term) || $wpdb->last_error !== '') {
        if (is_wp_error($term) || $wpdb->last_error !== '') {
            $complete = false;
        }
        return 0;
    }

    $wpdb->last_error = '';
    $origin_id = max(0, (int) get_term_meta((int) $term->term_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, true));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return 0;
    }
    return $origin_id > 0 ? $origin_id : (int) $term->term_id;
}

function ll_tools_set_category_wordset_owner(int $term_id, int $wordset_id, int $origin_id = 0): void {
    $term_id = (int) $term_id;
    $wordset_id = (int) $wordset_id;
    if ($term_id <= 0) {
        return;
    }

    if ($wordset_id > 0) {
        update_term_meta($term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);
    } else {
        delete_term_meta($term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY);
    }

    if ($origin_id <= 0) {
        $origin_id = $term_id;
    }
    update_term_meta($term_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, (int) $origin_id);

    if (function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }
}

function ll_tools_get_word_image_wordset_owner_id(int $image_post_id): int {
    $image_post_id = (int) $image_post_id;
    if ($image_post_id <= 0) {
        return 0;
    }

    return max(0, (int) get_post_meta($image_post_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, true));
}

function ll_tools_get_word_image_isolation_source_id(int $image_post_id): int {
    $image_post_id = (int) $image_post_id;
    if ($image_post_id <= 0) {
        return 0;
    }

    $origin_id = max(0, (int) get_post_meta($image_post_id, LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY, true));
    return $origin_id > 0 ? $origin_id : $image_post_id;
}

function ll_tools_set_word_image_wordset_owner(int $image_post_id, int $wordset_id, int $origin_id = 0): void {
    $image_post_id = (int) $image_post_id;
    $wordset_id = (int) $wordset_id;
    if ($image_post_id <= 0) {
        return;
    }

    if ($wordset_id > 0) {
        update_post_meta($image_post_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_id);
    } else {
        delete_post_meta($image_post_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY);
    }

    if ($origin_id <= 0) {
        $origin_id = $image_post_id;
    }
    update_post_meta($image_post_id, LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY, (int) $origin_id);

    if (function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }
}

function ll_tools_get_wordset_isolation_slug_suffix(int $wordset_id): string {
    static $cache = [];

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return 'wordset-0';
    }
    if (isset($cache[$wordset_id])) {
        return $cache[$wordset_id];
    }

    $term = get_term($wordset_id, 'wordset');
    $slug = ($term instanceof WP_Term && !is_wp_error($term))
        ? sanitize_title((string) $term->slug)
        : '';
    if ($slug === '') {
        $slug = 'wordset-' . $wordset_id;
    }

    $cache[$wordset_id] = $slug;
    return $cache[$wordset_id];
}

function ll_tools_build_isolated_category_slug(string $base_slug, int $wordset_id): string {
    $base_slug = sanitize_title($base_slug);
    if ($base_slug === '') {
        $base_slug = 'category';
    }

    return sanitize_title($base_slug . '--' . ll_tools_get_wordset_isolation_slug_suffix($wordset_id));
}

function ll_tools_build_isolated_word_image_slug(string $base_slug, int $wordset_id): string {
    $base_slug = sanitize_title($base_slug);
    if ($base_slug === '') {
        $base_slug = 'image';
    }

    return sanitize_title($base_slug . '--' . ll_tools_get_wordset_isolation_slug_suffix($wordset_id));
}

function ll_tools_copy_term_meta(int $source_term_id, int $target_term_id, array $exclude_keys = []): void {
    if ($source_term_id <= 0 || $target_term_id <= 0) {
        return;
    }

    $meta = get_term_meta($source_term_id);
    if (!is_array($meta) || empty($meta)) {
        return;
    }

    $exclude = array_fill_keys(array_map('strval', $exclude_keys), true);
    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if ($key === '' || isset($exclude[$key])) {
            continue;
        }

        delete_term_meta($target_term_id, $key);
        foreach ((array) $values as $value) {
            add_term_meta($target_term_id, $key, maybe_unserialize($value));
        }
    }
}

function ll_tools_copy_post_meta(int $source_post_id, int $target_post_id, array $exclude_keys = []): void {
    if ($source_post_id <= 0 || $target_post_id <= 0) {
        return;
    }

    $meta = get_post_meta($source_post_id);
    if (!is_array($meta) || empty($meta)) {
        return;
    }

    $exclude = array_fill_keys(array_map('strval', $exclude_keys), true);
    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if ($key === '' || isset($exclude[$key])) {
            continue;
        }

        delete_post_meta($target_post_id, $key);
        foreach ((array) $values as $value) {
            add_post_meta($target_post_id, $key, maybe_unserialize($value));
        }
    }
}

function ll_tools_wordset_isolation_parse_category_id_list($raw_value): array {
    if (function_exists('ll_tools_wordset_parse_id_list_meta')) {
        return ll_tools_wordset_parse_id_list_meta($raw_value);
    }

    if (is_array($raw_value)) {
        $ids = $raw_value;
    } elseif (is_string($raw_value) && trim($raw_value) !== '') {
        $ids = preg_split('/\s*,\s*/', trim($raw_value));
    } else {
        $ids = [];
    }

    $normalized = [];
    foreach ((array) $ids as $id) {
        $cid = (int) $id;
        if ($cid > 0) {
            $normalized[$cid] = true;
        }
    }

    return array_map('intval', array_keys($normalized));
}

function ll_tools_wordset_isolation_normalize_prereq_map($raw_map, array $allowed_category_ids = []): array {
    if (function_exists('ll_tools_wordset_normalize_category_prereq_map')) {
        return ll_tools_wordset_normalize_category_prereq_map($raw_map, $allowed_category_ids);
    }

    return is_array($raw_map) ? $raw_map : [];
}

function ll_tools_wordset_isolation_get_category_id_map_for_wordset(
    int $wordset_id,
    array $source_category_ids,
    bool $create_missing = false
): array {
    $source_category_ids = function_exists('ll_tools_wordset_normalize_category_id_list')
        ? ll_tools_wordset_normalize_category_id_list($source_category_ids)
        : ll_tools_wordset_isolation_parse_category_id_list($source_category_ids);
    if (empty($source_category_ids)) {
        return [];
    }

    $identity_map = [];
    foreach ($source_category_ids as $source_category_id) {
        $source_category_id = (int) $source_category_id;
        if ($source_category_id > 0) {
            $identity_map[$source_category_id] = $source_category_id;
        }
    }

    if ($wordset_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return $identity_map;
    }

    $category_id_map = [];
    foreach ($source_category_ids as $source_category_id) {
        $source_category_id = (int) $source_category_id;
        if ($source_category_id <= 0) {
            continue;
        }

        $source_term = get_term($source_category_id, 'word-category');
        if (!($source_term instanceof WP_Term) || is_wp_error($source_term)) {
            continue;
        }

        $source_owner_id = ll_tools_get_category_wordset_owner_id($source_term);
        if ($source_owner_id === $wordset_id) {
            $category_id_map[$source_category_id] = (int) $source_term->term_id;
            continue;
        }

        $source_origin_id = ll_tools_get_category_isolation_source_id($source_term);
        $target_category_id = $source_origin_id > 0
            ? ll_tools_get_existing_isolated_category_copy_id($source_origin_id, $wordset_id)
            : 0;

        if ($target_category_id <= 0 && $create_missing && $source_origin_id > 0) {
            $target_category_id = ll_tools_get_or_create_isolated_category_copy($source_origin_id, $wordset_id);
        }

        if ($target_category_id <= 0 && $source_owner_id <= 0) {
            $target_category_id = (int) $source_term->term_id;
        }

        if ($target_category_id > 0) {
            $category_id_map[$source_category_id] = $target_category_id;
        }
    }

    return $category_id_map;
}

function ll_tools_wordset_isolation_remap_category_id_list_for_wordset(
    $raw_value,
    int $wordset_id,
    bool $create_missing = false
): array {
    $source_ids = ll_tools_wordset_isolation_parse_category_id_list($raw_value);
    if (empty($source_ids)) {
        return [];
    }

    $category_id_map = ll_tools_wordset_isolation_get_category_id_map_for_wordset(
        $wordset_id,
        $source_ids,
        $create_missing
    );
    if (empty($category_id_map)) {
        return [];
    }

    $remapped = [];
    foreach ($source_ids as $source_id) {
        $source_id = (int) $source_id;
        $target_id = (int) ($category_id_map[$source_id] ?? 0);
        if ($target_id > 0 && !in_array($target_id, $remapped, true)) {
            $remapped[] = $target_id;
        }
    }

    return $remapped;
}

/**
 * Remap a category list only when every source has a verified target.
 *
 * @return array<int,int>|null Null when any lookup/copy/readback is incomplete.
 */
function ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete(
    $raw_value,
    int $wordset_id,
    bool $create_missing = false
): ?array {
    global $wpdb;

    $source_ids = ll_tools_wordset_isolation_parse_category_id_list($raw_value);
    if (empty($source_ids)) {
        return [];
    }

    $requires_owned_targets = $wordset_id > 0 && ll_tools_is_wordset_isolation_enabled();
    if ($requires_owned_targets) {
        foreach ($source_ids as $source_id) {
            $source_owner_complete = true;
            $source_origin_complete = true;
            ll_tools_get_category_wordset_owner_id(
                (int) $source_id,
                $source_owner_complete
            );
            if (!$source_owner_complete || $wpdb->last_error !== '') {
                return null;
            }
            ll_tools_get_category_isolation_source_id(
                (int) $source_id,
                $source_origin_complete
            );
            if (!$source_origin_complete || $wpdb->last_error !== '') {
                return null;
            }
        }
    }

    $category_id_map = ll_tools_wordset_isolation_get_category_id_map_for_wordset(
        $wordset_id,
        $source_ids,
        $create_missing
    );
    $remapped = [];
    foreach ($source_ids as $source_id) {
        $source_id = (int) $source_id;
        $target_id = (int) ($category_id_map[$source_id] ?? 0);
        if ($target_id <= 0) {
            return null;
        }

        if ($requires_owned_targets) {
            $source_origin_complete = true;
            $target_owner_complete = true;
            $target_origin_complete = true;
            $source_origin_id = ll_tools_get_category_isolation_source_id(
                $source_id,
                $source_origin_complete
            );
            $target_owner_id = ll_tools_get_category_wordset_owner_id(
                $target_id,
                $target_owner_complete
            );
            $target_origin_id = ll_tools_get_category_isolation_source_id(
                $target_id,
                $target_origin_complete
            );
            if (
                !$source_origin_complete
                || !$target_owner_complete
                || !$target_origin_complete
                || $wpdb->last_error !== ''
                || $source_origin_id <= 0
                || $target_owner_id !== $wordset_id
                || $target_origin_id !== $source_origin_id
            ) {
                return null;
            }
        }

        if (!in_array($target_id, $remapped, true)) {
            $remapped[] = $target_id;
        }
    }

    return $remapped;
}

function ll_tools_wordset_isolation_expand_category_id_list_across_wordsets($raw_value): array {
    $source_ids = ll_tools_wordset_isolation_parse_category_id_list($raw_value);
    if (empty($source_ids)) {
        return [];
    }

    $expanded = [];
    foreach ($source_ids as $source_id) {
        $source_id = (int) $source_id;
        if ($source_id <= 0) {
            continue;
        }

        $term = get_term($source_id, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $owner_wordset_id = ll_tools_get_category_wordset_owner_id($term);
        if ($owner_wordset_id > 0) {
            $expanded[$source_id] = true;
            continue;
        }

        $origin_category_id = ll_tools_get_category_isolation_source_id($term);
        if ($origin_category_id <= 0) {
            $expanded[$source_id] = true;
            continue;
        }

        $copy_ids = get_terms([
            'taxonomy'   => 'word-category',
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [
                [
                    'key'     => LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
                    'value'   => $origin_category_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);
        if (is_wp_error($copy_ids) || empty($copy_ids)) {
            $expanded[$source_id] = true;
            continue;
        }

        foreach ((array) $copy_ids as $copy_id) {
            $copy_id = (int) $copy_id;
            if ($copy_id > 0) {
                $expanded[$copy_id] = true;
            }
        }
    }

    return array_map('intval', array_keys($expanded));
}

function ll_tools_wordset_isolation_get_category_query_scope_for_wordset($raw_value, int $wordset_id): array {
    $category_ids = ll_tools_wordset_isolation_parse_category_id_list($raw_value);
    if (empty($category_ids)) {
        return [];
    }

    $scope = [];
    foreach ($category_ids as $category_id) {
        $category_id = (int) $category_id;
        if ($category_id <= 0) {
            continue;
        }

        $scope[$category_id] = true;

        if ($wordset_id > 0) {
            $effective_category_id = ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
            if ($effective_category_id > 0) {
                $scope[$effective_category_id] = true;
            }
        }

        $origin_category_id = ll_tools_get_category_isolation_source_id($category_id);
        if ($origin_category_id > 0) {
            $scope[$origin_category_id] = true;
        }
    }

    return array_map('intval', array_keys($scope));
}

function ll_tools_get_effective_category_id_for_wordset(int $category_id, int $wordset_id, bool $create_missing = false): int {
    $category_id = (int) $category_id;
    $wordset_id = (int) $wordset_id;
    if ($category_id <= 0) {
        return 0;
    }

    $remapped_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset(
        [$category_id],
        $wordset_id,
        $create_missing
    );

    return isset($remapped_ids[0]) ? (int) $remapped_ids[0] : $category_id;
}

function ll_tools_wordset_isolation_remap_prerequisite_map_for_wordset(
    $raw_map,
    int $wordset_id,
    bool $create_missing = false
): array {
    $source_map = ll_tools_wordset_isolation_normalize_prereq_map($raw_map);
    if (empty($source_map)) {
        return [];
    }

    $source_category_lookup = [];
    foreach ($source_map as $source_category_id => $source_dependencies) {
        $source_category_id = (int) $source_category_id;
        if ($source_category_id <= 0) {
            continue;
        }

        $source_category_lookup[$source_category_id] = true;
        foreach ((array) $source_dependencies as $source_dependency_id) {
            $source_dependency_id = (int) $source_dependency_id;
            if ($source_dependency_id > 0) {
                $source_category_lookup[$source_dependency_id] = true;
            }
        }
    }

    $source_category_ids = array_map('intval', array_keys($source_category_lookup));
    if (empty($source_category_ids)) {
        return [];
    }

    $category_id_map = ll_tools_wordset_isolation_get_category_id_map_for_wordset(
        $wordset_id,
        $source_category_ids,
        $create_missing
    );
    if (empty($category_id_map)) {
        return [];
    }

    $remapped = [];
    foreach ($source_map as $source_category_id => $source_dependencies) {
        $source_category_id = (int) $source_category_id;
        $target_category_id = (int) ($category_id_map[$source_category_id] ?? 0);
        if ($target_category_id <= 0) {
            continue;
        }

        $target_dependencies = [];
        foreach ((array) $source_dependencies as $source_dependency_id) {
            $source_dependency_id = (int) $source_dependency_id;
            $target_dependency_id = (int) ($category_id_map[$source_dependency_id] ?? 0);
            if ($target_dependency_id > 0 && $target_dependency_id !== $target_category_id) {
                $target_dependencies[$target_dependency_id] = true;
            }
        }

        if (!empty($target_dependencies)) {
            $remapped[$target_category_id] = array_map('intval', array_keys($target_dependencies));
        }
    }

    $allowed_target_ids = array_values(array_unique(array_map('intval', array_values($category_id_map))));
    sort($allowed_target_ids, SORT_NUMERIC);

    return ll_tools_wordset_isolation_normalize_prereq_map($remapped, $allowed_target_ids);
}

function ll_tools_repair_wordset_category_ordering_meta_for_isolation(int $wordset_id): bool {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return false;
    }

    $updated = false;

    $manual_raw = get_term_meta($wordset_id, 'll_wordset_category_manual_order', true);
    $manual_current = ll_tools_wordset_isolation_parse_category_id_list($manual_raw);
    if (!empty($manual_current)) {
        $manual_repaired = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($manual_raw, $wordset_id, true);
        if (!empty($manual_repaired) && $manual_repaired !== $manual_current) {
            update_term_meta($wordset_id, 'll_wordset_category_manual_order', $manual_repaired);
            $updated = true;
        }
    }

    $prereq_raw = get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true);
    $prereq_current = ll_tools_wordset_isolation_normalize_prereq_map($prereq_raw);
    if (!empty($prereq_current)) {
        $prereq_repaired = ll_tools_wordset_isolation_remap_prerequisite_map_for_wordset($prereq_raw, $wordset_id, true);
        if (!empty($prereq_repaired) && $prereq_repaired !== $prereq_current) {
            update_term_meta($wordset_id, 'll_wordset_category_prerequisites', $prereq_repaired);
            $updated = true;
        }
    }

    if ($updated && function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }

    return $updated;
}

function ll_tools_repair_vocab_lesson_category_meta_for_isolation(int $lesson_id): bool {
    $lesson_id = (int) $lesson_id;
    if ($lesson_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return false;
    }

    if (!defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META') || !defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
        return false;
    }

    $lesson = get_post($lesson_id);
    if (!($lesson instanceof WP_Post) || $lesson->post_type !== 'll_vocab_lesson') {
        return false;
    }

    $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    if ($wordset_id <= 0 || $category_id <= 0) {
        return false;
    }

    $effective_category_id = ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
    if ($effective_category_id <= 0 || $effective_category_id === $category_id) {
        return false;
    }

    update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);

    if (function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }

    return true;
}

function ll_tools_repair_all_vocab_lesson_category_meta_for_isolation(): int {
    if (!ll_tools_is_wordset_isolation_enabled()) {
        return 0;
    }

    if (!defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
        return 0;
    }

    $lesson_ids = get_posts([
        'post_type'        => 'll_vocab_lesson',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);

    $repaired = 0;
    foreach ((array) $lesson_ids as $lesson_id) {
        if (ll_tools_repair_vocab_lesson_category_meta_for_isolation((int) $lesson_id)) {
            $repaired++;
        }
    }

    return $repaired;
}

function ll_tools_get_existing_isolated_category_copy_id(int $source_origin_id, int $wordset_id): int {
    $source_origin_id = (int) $source_origin_id;
    $wordset_id = (int) $wordset_id;
    if ($source_origin_id <= 0 || $wordset_id <= 0) {
        return 0;
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
        'number'     => 1,
        'orderby'    => 'term_id',
        'order'      => 'DESC',
        'meta_query' => [
            [
                'key'   => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                'value' => $wordset_id,
            ],
            [
                'key'   => LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
                'value' => $source_origin_id,
            ],
        ],
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return 0;
    }

    return max(0, (int) $terms[0]);
}

function ll_tools_get_existing_isolated_word_image_copy_id(int $source_origin_id, int $wordset_id): int {
    $source_origin_id = (int) $source_origin_id;
    $wordset_id = (int) $wordset_id;
    if ($source_origin_id <= 0 || $wordset_id <= 0) {
        return 0;
    }

    $posts = get_posts([
        'post_type'       => 'word_images',
        'post_status'     => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'  => 1,
        'fields'          => 'ids',
        'no_found_rows'   => true,
        'suppress_filters'=> true,
        'orderby'         => 'ID',
        'order'           => 'DESC',
        'meta_query'      => [
            [
                'key'   => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
                'value' => $wordset_id,
            ],
            [
                'key'   => LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY,
                'value' => $source_origin_id,
            ],
        ],
    ]);

    if (empty($posts)) {
        return 0;
    }

    return max(0, (int) $posts[0]);
}

function ll_tools_create_or_get_wordset_category(string $name, int $wordset_id, array $args = []) {
    $name = sanitize_text_field($name);
    $name = trim($name);
    $wordset_id = (int) $wordset_id;

    if ($name === '') {
        return new WP_Error('ll_wordset_category_empty', __('Missing category name.', 'll-tools-text-domain'));
    }

    if ($wordset_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        $existing = term_exists($name, 'word-category', 0);
        if ($existing) {
            return (int) (is_array($existing) ? ($existing['term_id'] ?? 0) : $existing);
        }

        $insert_args = $args;
        unset($insert_args['parent']);

        $insert = wp_insert_term($name, 'word-category', $insert_args);
        if (is_wp_error($insert)) {
            return $insert;
        }

        $term_id = (int) ($insert['term_id'] ?? 0);
        if ($term_id > 0) {
            ll_tools_set_category_wordset_owner($term_id, 0, $term_id);
        }
        return $term_id;
    }

    $existing_terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'name'       => $name,
        'meta_query' => [
            [
                'key'   => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                'value' => $wordset_id,
            ],
        ],
    ]);
    if (!is_wp_error($existing_terms) && !empty($existing_terms)) {
        foreach ($existing_terms as $existing_term) {
            if (!($existing_term instanceof WP_Term) || is_wp_error($existing_term)) {
                continue;
            }
            if (strcasecmp((string) $existing_term->name, $name) === 0) {
                return (int) $existing_term->term_id;
            }
        }
    }

    $insert_args = $args;
    $base_slug = isset($args['slug']) && trim((string) $args['slug']) !== ''
        ? (string) $args['slug']
        : $name;
    $insert_args['slug'] = ll_tools_build_isolated_category_slug((string) $base_slug, $wordset_id);
    unset($insert_args['parent']);

    $insert = wp_insert_term($name, 'word-category', $insert_args);
    if (is_wp_error($insert)) {
        if ($insert->get_error_code() === 'term_exists') {
            return (int) $insert->get_error_data('term_exists');
        }
        return $insert;
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id > 0) {
        ll_tools_set_category_wordset_owner($term_id, $wordset_id, $term_id);
    }

    return $term_id;
}

function ll_tools_get_or_create_isolated_category_copy($source_category, int $wordset_id): int {
    $wordset_id = (int) $wordset_id;
    $source_term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($source_category)
        : get_term($source_category, 'word-category');
    if (!($source_term instanceof WP_Term) || is_wp_error($source_term)) {
        return 0;
    }

    if ($wordset_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return (int) $source_term->term_id;
    }

    $source_owner_id = ll_tools_get_category_wordset_owner_id($source_term);
    if ($source_owner_id === $wordset_id) {
        return (int) $source_term->term_id;
    }

    $source_origin_id = ll_tools_get_category_isolation_source_id($source_term);
    $existing_id = ll_tools_get_existing_isolated_category_copy_id($source_origin_id, $wordset_id);
    if ($existing_id > 0) {
        return $existing_id;
    }

    $target_slug = ll_tools_build_isolated_category_slug((string) $source_term->slug, $wordset_id);
    $insert = wp_insert_term($source_term->name, 'word-category', [
        'slug'        => $target_slug,
        'description' => (string) $source_term->description,
    ]);
    if (is_wp_error($insert)) {
        if ($insert->get_error_code() === 'term_exists') {
            $existing_id = (int) $insert->get_error_data('term_exists');
            if ($existing_id > 0) {
                $existing_term = get_term($existing_id, 'word-category');
                $existing_owner_id = max(0, (int) get_term_meta(
                    $existing_id,
                    LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                    true
                ));
                $existing_source_id = max(0, (int) get_term_meta(
                    $existing_id,
                    LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
                    true
                ));
                if (
                    !($existing_term instanceof WP_Term)
                    || is_wp_error($existing_term)
                    || strcasecmp((string) $existing_term->name, (string) $source_term->name) !== 0
                    || (string) $existing_term->slug !== $target_slug
                    || ($existing_owner_id > 0 && $existing_owner_id !== $wordset_id)
                    || ($existing_source_id > 0 && $existing_source_id !== $source_origin_id)
                ) {
                    return 0;
                }
                ll_tools_set_category_wordset_owner($existing_id, $wordset_id, $source_origin_id);
                return ll_tools_get_category_wordset_owner_id($existing_id) === $wordset_id
                    && ll_tools_get_category_isolation_source_id($existing_id) === $source_origin_id
                    ? $existing_id
                    : 0;
            }
        }
        return 0;
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        return 0;
    }

    $exclude_keys = [
        LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
        LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
        '_ll_wc_cache_version',
    ];
    if (defined('LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY')) {
        $exclude_keys[] = (string) LL_TOOLS_CATEGORY_ASPECT_CACHE_VERSION_META_KEY;
    }
    if (defined('LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY')) {
        $exclude_keys[] = (string) LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY;
    }
    if (defined('LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY')) {
        $exclude_keys[] = (string) LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY;
    }
    ll_tools_copy_term_meta((int) $source_term->term_id, $term_id, $exclude_keys);
    ll_tools_set_category_wordset_owner($term_id, $wordset_id, $source_origin_id);

    if (
        ll_tools_get_category_wordset_owner_id($term_id) !== $wordset_id
        || ll_tools_get_category_isolation_source_id($term_id) !== $source_origin_id
    ) {
        wp_delete_term($term_id, 'word-category');
        return 0;
    }

    return $term_id;
}

function ll_tools_get_isolated_category_ids_for_wordsets(
    array $category_ids,
    array $wordset_ids,
    bool $expand_missing_wordsets = true
): array {
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_map('intval', $wordset_ids), static function (int $id): bool {
            return $id > 0;
        }));

    if (empty($category_ids) || empty($wordset_ids) || !ll_tools_is_wordset_isolation_enabled()) {
        return $category_ids;
    }

    $allowed_wordsets = array_fill_keys($wordset_ids, true);
    $category_rows = [];
    $categories_by_source = [];
    foreach ($category_ids as $category_id) {
        $term = get_term($category_id, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return [];
        }

        $owner_wordset_id = ll_tools_get_category_wordset_owner_id($term);
        $source_id = ll_tools_get_category_isolation_source_id($term);
        if ($source_id <= 0) {
            return [];
        }

        $category_rows[] = [
            'term_id' => (int) $term->term_id,
            'owner_wordset_id' => $owner_wordset_id,
            'source_id' => $source_id,
        ];
        $categories_by_source[$source_id][] = (int) $term->term_id;
    }

    $normalized = [];
    $remap_targets_by_source = [];
    foreach ($category_rows as $category_row) {
        $term_id = (int) $category_row['term_id'];
        $owner_wordset_id = (int) $category_row['owner_wordset_id'];
        $source_id = (int) $category_row['source_id'];

        if (!$expand_missing_wordsets && $owner_wordset_id > 0 && isset($allowed_wordsets[$owner_wordset_id])) {
            // Explicit word-category writes keep valid owned assignments
            // independent. Wordset changes and migration pass expansion=true
            // so every source family is materialized across the active set.
            $normalized[$term_id] = true;
            $target_wordset_ids = [];
        } else {
            // Expansion mode and legacy/no-longer-owned rows retain their
            // meaning by remapping the source across the active set.
            $target_wordset_ids = $wordset_ids;
        }

        foreach ($target_wordset_ids as $target_wordset_id) {
            $target_wordset_id = (int) $target_wordset_id;
            if ($target_wordset_id > 0) {
                $remap_targets_by_source[$source_id][$target_wordset_id] = true;
            }
        }
    }

    foreach ($remap_targets_by_source as $source_id => $target_wordset_lookup) {
        $source_category_ids = $categories_by_source[$source_id] ?? [];
        $source_term = get_term((int) $source_id, 'word-category');
        $copy_source_id = ($source_term instanceof WP_Term) && !is_wp_error($source_term)
            ? (int) $source_term->term_id
            : (int) reset($source_category_ids);
        foreach (array_keys($target_wordset_lookup) as $wordset_id) {
            $wordset_id = (int) $wordset_id;
            $copy_id = ll_tools_get_existing_isolated_category_copy_id((int) $source_id, $wordset_id);
            if ($copy_id <= 0) {
                foreach ($source_category_ids as $candidate_id) {
                    if (
                        ll_tools_get_category_wordset_owner_id((int) $candidate_id) === $wordset_id
                        && ll_tools_get_category_isolation_source_id((int) $candidate_id) === (int) $source_id
                    ) {
                        $copy_id = (int) $candidate_id;
                        break;
                    }
                }
            }
            if ($copy_id <= 0) {
                $copy_id = ll_tools_get_or_create_isolated_category_copy($copy_source_id, $wordset_id);
            }
            if (
                $copy_id <= 0
                || ll_tools_get_category_wordset_owner_id($copy_id) !== $wordset_id
                || ll_tools_get_category_isolation_source_id($copy_id) !== (int) $source_id
            ) {
                return [];
            }
            $normalized[$copy_id] = true;
        }
    }

    $normalized_ids = array_values(array_map('intval', array_keys($normalized)));
    sort($normalized_ids, SORT_NUMERIC);
    return $normalized_ids;
}

function ll_tools_replace_post_terms_for_isolation(int $post_id, array $term_ids, string $taxonomy): void {
    static $guard = [];

    $post_id = (int) $post_id;
    $taxonomy = sanitize_key($taxonomy);
    $key = $post_id . '|' . $taxonomy;
    if ($post_id <= 0 || $taxonomy === '' || !empty($guard[$key])) {
        return;
    }

    $guard[$key] = true;
    try {
        wp_set_object_terms($post_id, array_values(array_map('intval', $term_ids)), $taxonomy, false);
    } finally {
        unset($guard[$key]);
    }
}

function ll_tools_get_primary_wordset_id_for_post(int $post_id): int {
    if ($post_id <= 0) {
        return 0;
    }

    if (function_exists('ll_tools_get_word_primary_wordset_id')) {
        $primary = (int) ll_tools_get_word_primary_wordset_id($post_id);
        if ($primary > 0) {
            return $primary;
        }
    }

    $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
        ? ll_tools_get_post_wordset_ids($post_id)
        : [];
    return (int) ($wordset_ids[0] ?? 0);
}

function ll_tools_get_effective_word_image_id_for_wordset(int $image_post_id, int $wordset_id): int {
    $image_post_id = (int) $image_post_id;
    $wordset_id = (int) $wordset_id;
    if ($image_post_id <= 0 || $wordset_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return $image_post_id;
    }

    $image_post = get_post($image_post_id);
    if (!($image_post instanceof WP_Post) || $image_post->post_type !== 'word_images') {
        return 0;
    }

    $owner_id = ll_tools_get_word_image_wordset_owner_id($image_post_id);
    if ($owner_id === $wordset_id) {
        return $image_post_id;
    }

    $source_origin_id = ll_tools_get_word_image_isolation_source_id($image_post_id);
    $existing_id = ll_tools_get_existing_isolated_word_image_copy_id($source_origin_id, $wordset_id);
    if ($existing_id > 0) {
        return $existing_id;
    }

    return ll_tools_get_or_create_isolated_word_image_copy($image_post_id, $wordset_id);
}

function ll_tools_sync_and_verify_isolated_word_image_copy(
    int $copy_id,
    int $source_image_id,
    int $wordset_id,
    int $source_origin_id
): bool {
    ll_tools_set_word_image_wordset_owner($copy_id, $wordset_id, $source_origin_id);
    if (
        ll_tools_get_word_image_wordset_owner_id($copy_id) !== $wordset_id
        || ll_tools_get_word_image_isolation_source_id($copy_id) !== $source_origin_id
    ) {
        return false;
    }

    $thumbnail_id = (int) get_post_thumbnail_id($source_image_id);
    if ($thumbnail_id > 0) {
        set_post_thumbnail($copy_id, $thumbnail_id);
    } else {
        delete_post_thumbnail($copy_id);
    }
    if ((int) get_post_thumbnail_id($copy_id) !== $thumbnail_id) {
        return false;
    }

    $source_category_ids = wp_get_post_terms($source_image_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($source_category_ids)) {
        return false;
    }
    $source_category_ids = array_values(array_filter(array_map('intval', (array) $source_category_ids)));
    $expected_category_ids = empty($source_category_ids)
        ? []
        : ll_tools_get_isolated_category_ids_for_wordsets($source_category_ids, [$wordset_id]);
    if (!empty($source_category_ids) && empty($expected_category_ids)) {
        return false;
    }

    ll_tools_replace_post_terms_for_isolation($copy_id, $expected_category_ids, 'word-category');
    $persisted_category_ids = wp_get_post_terms($copy_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($persisted_category_ids)) {
        return false;
    }
    $persisted_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $persisted_category_ids))));
    $expected_category_ids = array_values(array_unique(array_filter(array_map('intval', $expected_category_ids))));
    sort($persisted_category_ids, SORT_NUMERIC);
    sort($expected_category_ids, SORT_NUMERIC);
    return $persisted_category_ids === $expected_category_ids;
}

function ll_tools_get_or_create_isolated_word_image_copy(int $source_image_id, int $wordset_id): int {
    $source_image_id = (int) $source_image_id;
    $wordset_id = (int) $wordset_id;
    if ($source_image_id <= 0 || $wordset_id <= 0) {
        return 0;
    }

    $source_post = get_post($source_image_id);
    if (!($source_post instanceof WP_Post) || $source_post->post_type !== 'word_images') {
        return 0;
    }

    if (!ll_tools_is_wordset_isolation_enabled()) {
        return $source_image_id;
    }

    $source_owner_id = ll_tools_get_word_image_wordset_owner_id($source_image_id);
    if ($source_owner_id === $wordset_id) {
        return $source_image_id;
    }

    $source_origin_id = ll_tools_get_word_image_isolation_source_id($source_image_id);
    $existing_id = ll_tools_get_existing_isolated_word_image_copy_id($source_origin_id, $wordset_id);
    if ($existing_id > 0) {
        return ll_tools_sync_and_verify_isolated_word_image_copy(
            $existing_id,
            $source_image_id,
            $wordset_id,
            $source_origin_id
        ) ? $existing_id : 0;
    }

    $new_post_id = wp_insert_post([
        'post_type'    => 'word_images',
        'post_status'  => in_array((string) $source_post->post_status, ['publish', 'draft', 'pending', 'future', 'private'], true)
            ? (string) $source_post->post_status
            : 'publish',
        'post_title'   => (string) $source_post->post_title,
        'post_content' => (string) $source_post->post_content,
        'post_excerpt' => (string) $source_post->post_excerpt,
        'post_author'  => (int) $source_post->post_author,
        'post_name'    => ll_tools_build_isolated_word_image_slug((string) $source_post->post_name, $wordset_id),
    ], true);
    if (is_wp_error($new_post_id) || !$new_post_id) {
        return 0;
    }

    ll_tools_copy_post_meta($source_image_id, (int) $new_post_id, [
        LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
        LL_TOOLS_WORD_IMAGE_ISOLATION_SOURCE_META_KEY,
        '_thumbnail_id',
        '_edit_lock',
        '_edit_last',
        '_ll_picked_count',
        '_ll_picked_last',
    ]);
    $verified = ll_tools_sync_and_verify_isolated_word_image_copy(
        (int) $new_post_id,
        $source_image_id,
        $wordset_id,
        $source_origin_id
    );
    if (!$verified) {
        wp_delete_post((int) $new_post_id, true);
        return 0;
    }
    return (int) $new_post_id;
}

function ll_tools_get_word_image_owner_meta_query(array $wordset_ids, bool $include_legacy = true): array {
    $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_map('intval', $wordset_ids), static function (int $id): bool {
            return $id > 0;
        }));

    $clauses = [];
    if (!empty($wordset_ids)) {
        $clauses[] = [
            'key'     => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
            'value'   => $wordset_ids,
            'compare' => 'IN',
        ];
    }

    if ($include_legacy) {
        $clauses[] = [
            'key'     => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
            'compare' => 'NOT EXISTS',
        ];
        $clauses[] = [
            'key'     => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
            'value'   => ['0', 0, ''],
            'compare' => 'IN',
        ];
    }

    if (empty($clauses)) {
        return [];
    }
    if (count($clauses) === 1) {
        return $clauses;
    }

    return array_merge(['relation' => 'OR'], $clauses);
}

function ll_tools_normalize_word_categories_for_isolation(
    int $word_id,
    bool $expand_missing_wordsets = true,
    array $expansion_wordset_ids = []
): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return [];
    }

    $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
        ? ll_tools_get_post_wordset_ids($word_id)
        : [];
    if (empty($wordset_ids)) {
        return [];
    }

    $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($category_ids) || empty($category_ids)) {
        return [];
    }

    $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    $expansion_wordset_ids = array_values(array_intersect(
        $wordset_ids,
        array_values(array_filter(array_map('intval', $expansion_wordset_ids), static function (int $wordset_id): bool {
            return $wordset_id > 0;
        }))
    ));
    if ($expand_missing_wordsets && !empty($expansion_wordset_ids)) {
        $base_ids = ll_tools_get_isolated_category_ids_for_wordsets($category_ids, $wordset_ids, false);
        if (empty($base_ids)) {
            return [];
        }
        $expanded_ids = ll_tools_get_isolated_category_ids_for_wordsets($base_ids, $expansion_wordset_ids, true);
        if (empty($expanded_ids)) {
            return [];
        }
        $normalized_ids = array_values(array_unique(array_merge($base_ids, $expanded_ids)));
        sort($normalized_ids, SORT_NUMERIC);
    } else {
        $normalized_ids = ll_tools_get_isolated_category_ids_for_wordsets(
            $category_ids,
            $wordset_ids,
            $expand_missing_wordsets
        );
    }

    if (empty($normalized_ids)) {
        return [];
    }

    $current_ids = $category_ids;
    sort($current_ids, SORT_NUMERIC);
    $compare_ids = $normalized_ids;
    sort($compare_ids, SORT_NUMERIC);

    if ($current_ids !== $compare_ids) {
        ll_tools_replace_post_terms_for_isolation($word_id, $normalized_ids, 'word-category');
    }

    $primary_wordset_id = ll_tools_get_primary_wordset_id_for_post($word_id);
    $linked_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($linked_image_id > 0 && $primary_wordset_id > 0) {
        $effective_image_id = ll_tools_get_effective_word_image_id_for_wordset($linked_image_id, $primary_wordset_id);
        if ($effective_image_id > 0 && $effective_image_id !== $linked_image_id) {
            update_post_meta($word_id, '_ll_autopicked_image_id', $effective_image_id);
        }
    }

    return $normalized_ids;
}

function ll_tools_wordset_term_ids_from_taxonomy_ids($term_taxonomy_ids): array {
    $wordset_ids = [];
    foreach ((array) $term_taxonomy_ids as $term_taxonomy_id) {
        $term_taxonomy_id = (int) $term_taxonomy_id;
        if ($term_taxonomy_id <= 0) {
            continue;
        }
        $term = get_term_by('term_taxonomy_id', $term_taxonomy_id, 'wordset');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            $wordset_ids[(int) $term->term_id] = true;
        }
    }

    $normalized = array_map('intval', array_keys($wordset_ids));
    sort($normalized, SORT_NUMERIC);
    return $normalized;
}

function ll_tools_track_added_wordset_relationship($object_id, $term_taxonomy_id, $taxonomy): void {
    if ($taxonomy !== 'wordset' || !ll_tools_is_wordset_isolation_enabled()) {
        return;
    }

    $object_id = (int) $object_id;
    $term_taxonomy_id = (int) $term_taxonomy_id;
    $post = $object_id > 0 ? get_post($object_id) : null;
    if ($term_taxonomy_id <= 0 || !($post instanceof WP_Post) || $post->post_type !== 'words') {
        return;
    }

    if (!isset($GLOBALS['ll_tools_wordset_isolation_added_tt_ids'])) {
        $GLOBALS['ll_tools_wordset_isolation_added_tt_ids'] = [];
    }
    $GLOBALS['ll_tools_wordset_isolation_added_tt_ids'][$object_id][$term_taxonomy_id] = true;
}
add_action('added_term_relationship', 'll_tools_track_added_wordset_relationship', 20, 3);

function ll_tools_take_added_wordset_term_ids(int $object_id, array $operation_tt_ids): array {
    $object_id = (int) $object_id;
    $tracked = (array) ($GLOBALS['ll_tools_wordset_isolation_added_tt_ids'][$object_id] ?? []);
    unset($GLOBALS['ll_tools_wordset_isolation_added_tt_ids'][$object_id]);
    if (empty($tracked)) {
        return [];
    }

    $operation_lookup = array_fill_keys(array_map('intval', $operation_tt_ids), true);
    $added_tt_ids = [];
    foreach (array_keys($tracked) as $term_taxonomy_id) {
        $term_taxonomy_id = (int) $term_taxonomy_id;
        if ($term_taxonomy_id > 0 && isset($operation_lookup[$term_taxonomy_id])) {
            $added_tt_ids[] = $term_taxonomy_id;
        }
    }

    return ll_tools_wordset_term_ids_from_taxonomy_ids($added_tt_ids);
}

function ll_tools_normalize_word_image_categories_for_isolation(int $image_post_id): array {
    $image_post_id = (int) $image_post_id;
    if ($image_post_id <= 0 || !ll_tools_is_wordset_isolation_enabled()) {
        return [];
    }

    $owner_wordset_id = ll_tools_get_word_image_wordset_owner_id($image_post_id);
    if ($owner_wordset_id <= 0) {
        return [];
    }

    $category_ids = wp_get_post_terms($image_post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($category_ids) || empty($category_ids)) {
        return [];
    }

    $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    $normalized_ids = ll_tools_get_isolated_category_ids_for_wordsets($category_ids, [$owner_wordset_id]);

    if (empty($normalized_ids)) {
        return [];
    }

    $current_ids = $category_ids;
    sort($current_ids, SORT_NUMERIC);
    $compare_ids = $normalized_ids;
    sort($compare_ids, SORT_NUMERIC);

    if ($current_ids !== $compare_ids) {
        ll_tools_replace_post_terms_for_isolation($image_post_id, $normalized_ids, 'word-category');
    }

    return $normalized_ids;
}

function ll_tools_handle_wordset_isolation_term_assignment($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    if (!ll_tools_is_wordset_isolation_enabled()) {
        return;
    }

    $object_id = (int) $object_id;
    if ($object_id <= 0) {
        return;
    }

    $post = get_post($object_id);
    if (!($post instanceof WP_Post)) {
        return;
    }

    $did_normalize = false;

    if ($post->post_type === 'words' && $taxonomy === 'word-category') {
        ll_tools_normalize_word_categories_for_isolation($object_id, false);
        $did_normalize = true;
    } elseif ($post->post_type === 'words' && $taxonomy === 'wordset') {
        $current_wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
            ? ll_tools_get_post_wordset_ids($object_id)
            : [];
        $added_wordset_ids = ll_tools_take_added_wordset_term_ids($object_id, (array) $tt_ids);
        if (!$append && empty($added_wordset_ids)) {
            $previous_wordset_ids = ll_tools_wordset_term_ids_from_taxonomy_ids($old_tt_ids);
            $added_wordset_ids = array_values(array_diff($current_wordset_ids, $previous_wordset_ids));
        }
        ll_tools_normalize_word_categories_for_isolation(
            $object_id,
            !empty($added_wordset_ids),
            $added_wordset_ids
        );
        $did_normalize = true;
    } elseif ($post->post_type === 'word_images' && $taxonomy === 'word-category') {
        ll_tools_normalize_word_image_categories_for_isolation($object_id);
        $did_normalize = true;
    }

    if ($did_normalize && function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }
}
add_action('set_object_terms', 'll_tools_handle_wordset_isolation_term_assignment', 20, 6);

function ll_tools_get_legacy_candidate_word_image_for_word(int $word_id, array $legacy_category_ids = []): int {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return 0;
    }

    $linked_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($linked_image_id > 0) {
        $linked_post = get_post($linked_image_id);
        if ($linked_post instanceof WP_Post && $linked_post->post_type === 'word_images') {
            return $linked_image_id;
        }
    }

    $thumbnail_id = (int) get_post_thumbnail_id($word_id);
    if ($thumbnail_id <= 0) {
        return 0;
    }

    $query_args = [
        'post_type'        => 'word_images',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'orderby'          => 'date',
        'order'            => 'ASC',
        'meta_query'       => [
            [
                'key'   => '_thumbnail_id',
                'value' => $thumbnail_id,
            ],
        ],
    ];

    $legacy_category_ids = array_values(array_filter(array_map('intval', (array) $legacy_category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    if (!empty($legacy_category_ids)) {
        $query_args['tax_query'] = [
            [
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => $legacy_category_ids,
            ],
        ];
    }

    $matches = get_posts($query_args);
    return !empty($matches) ? (int) $matches[0] : 0;
}

function ll_tools_wordset_isolation_migration_counter_defaults(): array {
    return [
        'words_scanned' => 0,
        'words_updated' => 0,
        'categories_created' => 0,
        'images_created' => 0,
        'images_relinked' => 0,
        'lessons_repaired' => 0,
        'wordsets_repaired' => 0,
        'word_option_rule_scopes_repaired' => 0,
        'user_data_repaired' => 0,
    ];
}

function ll_tools_wordset_isolation_migration_phase_order(): array {
    return ['words', 'images', 'wordsets', 'word_option_rules', 'lessons', 'users', 'finalize'];
}

function ll_tools_wordset_isolation_migration_new_state(): array {
    return [
        'schema' => 1,
        'target_version' => LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION,
        'status' => 'queued',
        'phase' => 'words',
        'cursor' => 0,
        'counters' => ll_tools_wordset_isolation_migration_counter_defaults(),
        'errors' => [],
        'last_error' => '',
        'queued_at' => time(),
        'started_at' => 0,
        'updated_at' => time(),
        'completed_at' => 0,
        'lease_token' => '',
        'lease_expires_at' => 0,
    ];
}

function ll_tools_get_wordset_isolation_migration_state(): array {
    $stored = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, []);
    if (!is_array($stored)) {
        $stored = [];
    }

    $defaults = ll_tools_wordset_isolation_migration_new_state();
    $state = array_merge($defaults, $stored);
    $state['schema'] = max(1, (int) ($state['schema'] ?? 1));
    $state['target_version'] = max(0, (int) ($state['target_version'] ?? 0));
    $state['status'] = in_array((string) ($state['status'] ?? ''), ['idle', 'queued', 'running', 'failed', 'completed'], true)
        ? (string) $state['status']
        : 'idle';
    $state['phase'] = in_array((string) ($state['phase'] ?? ''), ll_tools_wordset_isolation_migration_phase_order(), true)
        ? (string) $state['phase']
        : 'words';
    $state['cursor'] = max(0, (int) ($state['cursor'] ?? 0));
    $state['counters'] = array_merge(
        ll_tools_wordset_isolation_migration_counter_defaults(),
        is_array($state['counters'] ?? null) ? $state['counters'] : []
    );
    foreach ($state['counters'] as $key => $value) {
        $state['counters'][$key] = max(0, (int) $value);
    }
    $state['errors'] = array_values(array_filter(array_map('sanitize_text_field', (array) ($state['errors'] ?? []))));
    $state['last_error'] = sanitize_text_field((string) ($state['last_error'] ?? ''));
    foreach (['queued_at', 'started_at', 'updated_at', 'completed_at', 'lease_expires_at'] as $timestamp_key) {
        $state[$timestamp_key] = max(0, (int) ($state[$timestamp_key] ?? 0));
    }
    $state['lease_token'] = sanitize_key((string) ($state['lease_token'] ?? ''));

    return $state;
}

function ll_tools_wordset_isolation_migration_batch_size(): int {
    return min(100, max(1, (int) apply_filters('ll_tools_wordset_isolation_migration_batch_size', 20)));
}

function ll_tools_wordset_isolation_migration_word_option_rules_max_bytes(): int {
    return max(64 * KB_IN_BYTES, (int) apply_filters(
        'll_tools_wordset_isolation_migration_word_option_rules_max_bytes',
        512 * KB_IN_BYTES
    ));
}

function ll_tools_wordset_isolation_migration_lease_ttl(): int {
    return min(HOUR_IN_SECONDS, max(MINUTE_IN_SECONDS, (int) apply_filters(
        'll_tools_wordset_isolation_migration_lease_ttl',
        10 * MINUTE_IN_SECONDS
    )));
}

function ll_tools_wordset_isolation_migration_delete_lock_if_matches($lock): bool {
    global $wpdb;

    if ($lock === false) {
        return false;
    }
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s
           AND option_value = %s",
        LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION,
        maybe_serialize($lock)
    ));
    wp_cache_delete(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, 'options');
    return $deleted === 1;
}

function ll_tools_wordset_isolation_migration_acquire_lease(): string {
    $now = time();
    $existing = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, false);
    if (is_array($existing) && !empty($existing['token']) && (int) ($existing['expires_at'] ?? 0) > $now) {
        return '';
    }
    if ($existing !== false && !ll_tools_wordset_isolation_migration_delete_lock_if_matches($existing)) {
        return '';
    }

    $token = strtolower(wp_generate_password(24, false, false));
    $lock = [
        'token' => $token,
        'expires_at' => $now + ll_tools_wordset_isolation_migration_lease_ttl(),
    ];
    return add_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, $lock, '', false) ? $token : '';
}

function ll_tools_wordset_isolation_migration_refresh_lease(string $token): int {
    global $wpdb;

    $token = sanitize_key($token);
    $lock = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, []);
    if ($token === '' || !is_array($lock) || !hash_equals((string) ($lock['token'] ?? ''), $token)) {
        return 0;
    }

    $expires_at = time() + ll_tools_wordset_isolation_migration_lease_ttl();
    $refreshed_lock = [
        'token' => $token,
        'expires_at' => $expires_at,
    ];
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s
           AND option_value = %s",
        maybe_serialize($refreshed_lock),
        LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION,
        maybe_serialize($lock)
    ));
    wp_cache_delete(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, 'options');
    if ($updated !== 1) {
        $current_lock = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, []);
        if (
            is_array($current_lock)
            && hash_equals((string) ($current_lock['token'] ?? ''), $token)
            && (int) ($current_lock['expires_at'] ?? 0) >= $expires_at
        ) {
            return (int) $current_lock['expires_at'];
        }
        return 0;
    }
    return $expires_at;
}

function ll_tools_wordset_isolation_migration_release_lease(string $token): void {
    $lock = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, []);
    if ($token !== '' && is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
        ll_tools_wordset_isolation_migration_delete_lock_if_matches($lock);
    }
}

function ll_tools_wordset_isolation_migration_save_state(array $state, string $lease_token = ''): bool {
    global $wpdb;

    if ($lease_token !== '') {
        $expires_at = ll_tools_wordset_isolation_migration_refresh_lease($lease_token);
        if ($expires_at <= 0) {
            return false;
        }
        $state['lease_token'] = $lease_token;
        $state['lease_expires_at'] = $expires_at;
    } else {
        $state['lease_token'] = '';
        $state['lease_expires_at'] = 0;
    }
    $state['updated_at'] = time();
    $current = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, false);

    if ($current === false) {
        $saved = add_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, '', false);
    } else {
        $saved = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s
               AND option_value = %s",
            maybe_serialize($state),
            LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION,
            maybe_serialize($current)
        ));
        $saved = $saved === 1;
    }
    wp_cache_delete(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, 'options');
    $persisted = get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, false);
    return ($saved || $persisted === $state) && $persisted === $state;
}

function ll_tools_wordset_isolation_migration_schedule_next(int $delay = 5): void {
    if (!wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK)) {
        wp_schedule_single_event(time() + max(1, $delay), LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK);
    }
}

function ll_tools_queue_wordset_isolation_migration(bool $retry_failed = false): array {
    $state = ll_tools_get_wordset_isolation_migration_state();
    $stored_version = ll_tools_get_wordset_isolation_migration_version();
    $needs_new_state = $state['target_version'] !== LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION
        || ($stored_version < LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION && $state['status'] === 'completed');
    if ($needs_new_state) {
        $state = ll_tools_wordset_isolation_migration_new_state();
    } elseif ($stored_version >= LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION) {
        $state['status'] = 'completed';
        $state['phase'] = 'finalize';
        if ((int) $state['completed_at'] <= 0) {
            $state['completed_at'] = time();
        }
        return ll_tools_wordset_isolation_migration_save_state($state)
            ? $state
            : ll_tools_get_wordset_isolation_migration_state();
    } elseif ($state['status'] === 'failed' && !$retry_failed) {
        return $state;
    } elseif (in_array($state['status'], ['queued', 'running'], true)) {
        ll_tools_wordset_isolation_migration_schedule_next();
        return $state;
    } else {
        $state['status'] = 'queued';
        $state['queued_at'] = time();
        if ($retry_failed) {
            $state['last_error'] = '';
            $state['errors'] = [];
        }
    }

    if (!ll_tools_wordset_isolation_migration_save_state($state)) {
        return ll_tools_get_wordset_isolation_migration_state();
    }
    ll_tools_wordset_isolation_migration_schedule_next();
    return $state;
}

function ll_tools_wordset_isolation_migration_query_ids(string $query, string $failure_message): array {
    global $wpdb;

    $ids = $wpdb->get_col($query);
    if (!is_array($ids) || $wpdb->last_error !== '') {
        throw new RuntimeException($failure_message);
    }
    return array_values(array_map('intval', $ids));
}

function ll_tools_wordset_isolation_migration_post_ids(string $post_type, int $cursor, int $limit): array {
    global $wpdb;

    $statuses = ['publish', 'draft', 'pending', 'future', 'private'];
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $params = array_merge([$post_type], $statuses, [max(0, $cursor), max(1, $limit)]);
    return ll_tools_wordset_isolation_migration_query_ids($wpdb->prepare(
        "SELECT ID
         FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status IN ({$status_placeholders})
           AND ID > %d
         ORDER BY ID ASC
         LIMIT %d",
         $params
    ), __('Could not read posts for the wordset isolation migration.', 'll-tools-text-domain'));
}

function ll_tools_wordset_isolation_migration_wordset_ids(int $cursor, int $limit): array {
    global $wpdb;

    return ll_tools_wordset_isolation_migration_query_ids($wpdb->prepare(
        "SELECT terms.term_id
         FROM {$wpdb->terms} AS terms
         INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
            ON taxonomy.term_id = terms.term_id
            AND taxonomy.taxonomy = 'wordset'
         WHERE terms.term_id > %d
         GROUP BY terms.term_id
         ORDER BY terms.term_id ASC
         LIMIT %d",
        max(0, $cursor),
        max(1, $limit)
    ), __('Could not read wordsets for the wordset isolation migration.', 'll-tools-text-domain'));
}

function ll_tools_wordset_isolation_migration_user_meta_keys(): array {
    return array_values(array_filter([
        defined('LL_TOOLS_USER_CATEGORY_META') ? LL_TOOLS_USER_CATEGORY_META : '',
        defined('LL_TOOLS_USER_GOALS_META') ? LL_TOOLS_USER_GOALS_META : '',
        defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') ? LL_TOOLS_USER_CATEGORY_PROGRESS_META : '',
        defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') ? LL_TOOLS_USER_RECOMMENDATION_QUEUE_META : '',
        defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') ? LL_TOOLS_USER_LAST_RECOMMENDATION_META : '',
        defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META') ? LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META : '',
        defined('LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META') ? LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META : '',
    ], static function (string $meta_key): bool {
        return $meta_key !== '';
    }));
}

function ll_tools_wordset_isolation_migration_user_ids(int $cursor, int $limit): array {
    global $wpdb;

    $meta_keys = ll_tools_wordset_isolation_migration_user_meta_keys();
    if (empty($meta_keys)) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
    $params = array_merge($meta_keys, [max(0, $cursor), max(1, $limit)]);
    return ll_tools_wordset_isolation_migration_query_ids($wpdb->prepare(
        "SELECT user_id
         FROM {$wpdb->usermeta}
         WHERE meta_key IN ({$placeholders})
           AND user_id > %d
         GROUP BY user_id
         ORDER BY user_id ASC
         LIMIT %d",
        $params
    ), __('Could not read users for the wordset isolation migration.', 'll-tools-text-domain'));
}

function ll_tools_wordset_isolation_migration_source_category_wordset_ids(int $source_category_id): array {
    global $wpdb;

    $source_category_id = max(0, $source_category_id);
    if ($source_category_id <= 0) {
        return [];
    }
    $wordset_ids = ll_tools_wordset_isolation_migration_query_ids($wpdb->prepare(
        "SELECT owner.meta_value
         FROM {$wpdb->termmeta} AS source
         INNER JOIN {$wpdb->termmeta} AS owner
            ON owner.term_id = source.term_id
            AND owner.meta_key = %s
         WHERE source.meta_key = %s
           AND source.meta_value = %s",
        LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
        LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
        (string) $source_category_id
    ), __('Could not read category owners for the wordset isolation migration.', 'll-tools-text-domain'));
    return array_values(array_filter(array_unique($wordset_ids), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    }));
}

function ll_tools_wordset_isolation_migration_fail(array &$state, string $message): void {
    $message = sanitize_text_field($message);
    $state['status'] = 'failed';
    $state['last_error'] = $message;
    $errors = (array) ($state['errors'] ?? []);
    $errors[] = $message;
    $state['errors'] = array_slice(array_values(array_unique($errors)), -20);
}

function ll_tools_wordset_isolation_migration_verify_post_categories(
    int $post_id,
    array $expected_category_ids,
    array &$state,
    string $post_type_label
): bool {
    $persisted_category_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($persisted_category_ids)) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: 1: post type label, 2: post ID. */
            __('Could not verify isolated categories for %1$s %2$d.', 'll-tools-text-domain'),
            $post_type_label,
            $post_id
        ));
        return false;
    }

    $expected_category_ids = array_values(array_unique(array_filter(array_map('intval', $expected_category_ids))));
    $persisted_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $persisted_category_ids))));
    sort($expected_category_ids, SORT_NUMERIC);
    sort($persisted_category_ids, SORT_NUMERIC);
    if ($expected_category_ids !== $persisted_category_ids) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: 1: post type label, 2: post ID. */
            __('Isolated categories could not be saved for %1$s %2$d.', 'll-tools-text-domain'),
            $post_type_label,
            $post_id
        ));
        return false;
    }

    return true;
}

function ll_tools_wordset_isolation_migration_process_wordset(int $wordset_id, array &$state): bool {
    $updated = ll_tools_repair_wordset_category_ordering_meta_for_isolation($wordset_id);

    $manual_raw = get_term_meta($wordset_id, 'll_wordset_category_manual_order', true);
    $manual_current = ll_tools_wordset_isolation_parse_category_id_list($manual_raw);
    if (!empty($manual_current)) {
        $manual_expected = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($manual_raw, $wordset_id, true);
        if (empty($manual_expected) || $manual_current !== $manual_expected) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a wordset term ID. */
                __('Category ordering could not be saved for wordset %d.', 'll-tools-text-domain'),
                $wordset_id
            ));
            return false;
        }
    }

    $prereq_raw = get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true);
    $prereq_current = ll_tools_wordset_isolation_normalize_prereq_map($prereq_raw);
    if (!empty($prereq_current)) {
        $prereq_expected = ll_tools_wordset_isolation_remap_prerequisite_map_for_wordset($prereq_raw, $wordset_id, true);
        if (empty($prereq_expected) || $prereq_current !== $prereq_expected) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a wordset term ID. */
                __('Category prerequisites could not be saved for wordset %d.', 'll-tools-text-domain'),
                $wordset_id
            ));
            return false;
        }
    }

    if ($updated) {
        $state['counters']['wordsets_repaired']++;
    }
    return true;
}

function ll_tools_wordset_isolation_migration_process_lesson(int $lesson_id, array &$state): bool {
    $updated = defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
        && ll_tools_repair_vocab_lesson_category_meta_for_isolation($lesson_id);
    if (!defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META') || !defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
        return true;
    }

    $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    if ($wordset_id > 0 && $category_id > 0) {
        $effective_category_id = ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
        if ($effective_category_id <= 0 || $effective_category_id !== $category_id) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a lesson post ID. */
                __('The isolated category could not be saved for lesson %d.', 'll-tools-text-domain'),
                $lesson_id
            ));
            return false;
        }
    }

    if ($updated) {
        $state['counters']['lessons_repaired']++;
    }
    return true;
}

function ll_tools_wordset_isolation_migration_process_word(int $word_id, array &$state): bool {
    $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
        ? ll_tools_get_post_wordset_ids($word_id)
        : [];
    $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_map('intval', (array) $wordset_ids)));
    if (empty($wordset_ids)) {
        $state['counters']['words_scanned']++;
        return true;
    }
    $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($category_ids)) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a word post ID. */
            __('Could not read categories for word %d.', 'll-tools-text-domain'),
            $word_id
        ));
        return false;
    }
    $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids)));
    $missing_category_copies = [];
    foreach ($category_ids as $category_id) {
        $source_category_id = ll_tools_get_category_isolation_source_id($category_id);
        foreach ($wordset_ids as $wordset_id) {
            $wordset_id = (int) $wordset_id;
            if (
                $source_category_id > 0
                && $wordset_id > 0
                && ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_id) <= 0
            ) {
                $missing_category_copies[$source_category_id . ':' . $wordset_id] = [$source_category_id, $wordset_id];
            }
        }
    }
    $legacy_image_id = ll_tools_get_legacy_candidate_word_image_for_word($word_id, $category_ids);
    $linked_image_before = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);

    $normalized_ids = ll_tools_normalize_word_categories_for_isolation($word_id);
    if (!empty($category_ids) && empty($normalized_ids)) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a word post ID. */
            __('Isolated categories could not be created for word %d.', 'll-tools-text-domain'),
            $word_id
        ));
        return false;
    }
    if (!ll_tools_wordset_isolation_migration_verify_post_categories($word_id, $normalized_ids, $state, __('word', 'll-tools-text-domain'))) {
        return false;
    }
    $before_sorted = $category_ids;
    $after_sorted = $normalized_ids;
    sort($before_sorted, SORT_NUMERIC);
    sort($after_sorted, SORT_NUMERIC);
    if ($before_sorted !== $after_sorted) {
        $state['counters']['words_updated']++;
    }
    foreach ($missing_category_copies as [$source_category_id, $wordset_id]) {
        if (ll_tools_get_existing_isolated_category_copy_id((int) $source_category_id, (int) $wordset_id) > 0) {
            $state['counters']['categories_created']++;
        }
    }

    if (!empty($wordset_ids) && (int) get_post_thumbnail_id($word_id) > 0) {
        if ($legacy_image_id <= 0) {
            $ensured = function_exists('ll_tools_ensure_word_image_post_for_word')
                ? ll_tools_ensure_word_image_post_for_word($word_id)
                : 0;
            if (is_wp_error($ensured)) {
                ll_tools_wordset_isolation_migration_fail($state, sprintf(
                    /* translators: 1: word post ID, 2: error message. */
                    __('Word %1$d could not be linked to an isolated word image: %2$s', 'll-tools-text-domain'),
                    $word_id,
                    $ensured->get_error_message()
                ));
                return false;
            }
            $legacy_image_id = (int) $ensured;
        }

        if ($legacy_image_id > 0) {
            $source_image_id = ll_tools_get_word_image_isolation_source_id($legacy_image_id);
            foreach ($wordset_ids as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                $before_copy_id = ll_tools_get_existing_isolated_word_image_copy_id($source_image_id, $wordset_id);
                $copy_id = ll_tools_get_or_create_isolated_word_image_copy($legacy_image_id, $wordset_id);
                if ($copy_id <= 0) {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: 1: image post ID, 2: wordset term ID. */
                        __('Word image %1$d could not be isolated for wordset %2$d.', 'll-tools-text-domain'),
                        $legacy_image_id,
                        $wordset_id
                    ));
                    return false;
                }
                if ($before_copy_id <= 0 && ll_tools_get_word_image_wordset_owner_id($copy_id) === $wordset_id) {
                    $state['counters']['images_created']++;
                }
            }

            $primary_wordset_id = (int) ($wordset_ids[0] ?? 0);
            if ($primary_wordset_id > 0) {
                $effective_image_id = ll_tools_get_effective_word_image_id_for_wordset($legacy_image_id, $primary_wordset_id);
                if ($effective_image_id > 0 && $effective_image_id !== $legacy_image_id) {
                    update_post_meta($word_id, '_ll_autopicked_image_id', $effective_image_id);
                    if ((int) get_post_meta($word_id, '_ll_autopicked_image_id', true) !== $effective_image_id) {
                        ll_tools_wordset_isolation_migration_fail($state, sprintf(
                            /* translators: %d is a word post ID. */
                            __('The isolated image could not be linked to word %d.', 'll-tools-text-domain'),
                            $word_id
                        ));
                        return false;
                    }
                }
            }
        }
    }

    $linked_image_after = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($linked_image_after > 0 && $linked_image_after !== $linked_image_before) {
        $state['counters']['images_relinked']++;
    }
    $state['counters']['words_scanned']++;
    return true;
}

function ll_tools_wordset_isolation_migration_process_image(int $image_id, array &$state): bool {
    $owner_wordset_id = ll_tools_get_word_image_wordset_owner_id($image_id);
    if ($owner_wordset_id > 0) {
        $category_ids = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($category_ids)) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a word image post ID. */
                __('Could not read categories for word image %d.', 'll-tools-text-domain'),
                $image_id
            ));
            return false;
        }
        $normalized_ids = ll_tools_normalize_word_image_categories_for_isolation($image_id);
        if (!empty($category_ids) && empty($normalized_ids)) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a word image post ID. */
                __('Isolated categories could not be created for word image %d.', 'll-tools-text-domain'),
                $image_id
            ));
            return false;
        }
        return ll_tools_wordset_isolation_migration_verify_post_categories(
            $image_id,
            $normalized_ids,
            $state,
            __('word image', 'll-tools-text-domain')
        );
    }

    $source_image_id = ll_tools_get_word_image_isolation_source_id($image_id);
    if ($source_image_id !== $image_id) {
        return true;
    }
    $category_ids = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($category_ids)) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a word image post ID. */
            __('Could not read categories for word image %d.', 'll-tools-text-domain'),
            $image_id
        ));
        return false;
    }

    $target_wordset_ids = [];
    foreach ((array) $category_ids as $category_id) {
        $source_category_id = ll_tools_get_category_isolation_source_id((int) $category_id);
        foreach (ll_tools_wordset_isolation_migration_source_category_wordset_ids($source_category_id) as $wordset_id) {
            $target_wordset_ids[(int) $wordset_id] = true;
        }
    }
    foreach (array_keys($target_wordset_ids) as $wordset_id) {
        $before_copy_id = ll_tools_get_existing_isolated_word_image_copy_id($source_image_id, (int) $wordset_id);
        $copy_id = ll_tools_get_or_create_isolated_word_image_copy($image_id, (int) $wordset_id);
        if ($copy_id <= 0) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: 1: image post ID, 2: wordset term ID. */
                __('Word image %1$d could not be isolated for wordset %2$d.', 'll-tools-text-domain'),
                $image_id,
                $wordset_id
            ));
            return false;
        }
        if ($before_copy_id <= 0 && ll_tools_get_word_image_wordset_owner_id($copy_id) === (int) $wordset_id) {
            $state['counters']['images_created']++;
        }
    }
    return true;
}

function ll_tools_wordset_isolation_migration_write_user_meta(
    int $user_id,
    string $meta_key,
    $before,
    $expected,
    array &$state
): bool {
    if ($before === $expected) {
        return true;
    }

    update_user_meta($user_id, $meta_key, $expected, $before);
    if (get_user_meta($user_id, $meta_key, true) !== $expected) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a WordPress user ID. */
            __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
            $user_id
        ));
        return false;
    }
    $state['counters']['user_data_repaired']++;
    return true;
}

function ll_tools_wordset_isolation_migration_require_user_category_mapping(
    array $category_ids,
    array $wordset_ids,
    int $user_id,
    array &$state
): bool {
    $category_ids = ll_tools_wordset_isolation_parse_category_id_list($category_ids);
    $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_unique(array_map('intval', $wordset_ids))));
    if (empty($category_ids) || empty($wordset_ids)) {
        return true;
    }

    $source_ids = [];
    foreach ($category_ids as $category_id) {
        $source_id = ll_tools_get_category_isolation_source_id((int) $category_id);
        if ($source_id <= 0) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a WordPress user ID. */
                __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                $user_id
            ));
            return false;
        }
        $source_ids[$source_id] = true;
    }

    $mapped_ids = ll_tools_get_isolated_category_ids_for_wordsets($category_ids, $wordset_ids);
    $mapped_pairs = [];
    foreach ($mapped_ids as $mapped_id) {
        $mapped_id = (int) $mapped_id;
        $owner_id = ll_tools_get_category_wordset_owner_id($mapped_id);
        $source_id = ll_tools_get_category_isolation_source_id($mapped_id);
        if ($owner_id <= 0 || $source_id <= 0) {
            continue;
        }
        $mapped_pairs[$source_id][$owner_id] = true;
    }

    foreach (array_keys($source_ids) as $source_id) {
        foreach ($wordset_ids as $wordset_id) {
            if (empty($mapped_pairs[(int) $source_id][(int) $wordset_id])) {
                ll_tools_wordset_isolation_migration_fail($state, sprintf(
                    /* translators: %d is a WordPress user ID. */
                    __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                    $user_id
                ));
                return false;
            }
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
    array $activity,
    int $wordset_id,
    int $user_id,
    array &$state
): bool {
    $reference_state = function_exists('ll_tools_recommendation_activity_category_reference_state_for_isolation')
        ? ll_tools_recommendation_activity_category_reference_state_for_isolation($activity)
        : 'error';

    if ($reference_state === 'error') {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a WordPress user ID. */
            __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
            $user_id
        ));
        return false;
    }

    if ($wordset_id > 0 && $reference_state === 'missing') {
        $repair_status = null;
        $repaired = function_exists('ll_tools_repair_recommendation_activity_for_isolation')
            ? ll_tools_repair_recommendation_activity_for_isolation($activity, $wordset_id, $repair_status)
            : $activity;
        if ($repaired === null && $repair_status === 'dropped') {
            return true;
        }

        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a WordPress user ID. */
            __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
            $user_id
        ));
        return false;
    }

    return ll_tools_wordset_isolation_migration_require_user_category_mapping(
        (array) ($activity['category_ids'] ?? []),
        [$wordset_id],
        $user_id,
        $state
    );
}

function ll_tools_wordset_isolation_migration_preflight_recommendation_queue_snapshot(
    array $queues,
    int $user_id,
    array &$state
): bool {
    foreach ($queues as $wordset_id => $queue) {
        $queue = (array) $queue;
        if (count($queue) > 16) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a WordPress user ID. */
                __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                $user_id
            ));
            return false;
        }

        foreach ($queue as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            if (!ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
                $activity,
                (int) $wordset_id,
                $user_id,
                $state
            )) {
                return false;
            }
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_preflight_last_recommendation_snapshot(
    array $activities,
    int $user_id,
    array &$state
): bool {
    foreach ($activities as $wordset_id => $activity) {
        if (!is_array($activity)) {
            continue;
        }
        if (!ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
            $activity,
            (int) $wordset_id,
            $user_id,
            $state
        )) {
            return false;
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_require_user_goal_category_mapping(
    array $category_ids,
    string $category_key,
    int $user_id,
    array &$state
): bool {
    $category_ids = ll_tools_wordset_isolation_parse_category_id_list($category_ids);
    if (empty($category_ids)) {
        return true;
    }

    foreach ($category_ids as $category_id) {
        $term = get_term((int) $category_id, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a WordPress user ID. */
                __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                $user_id
            ));
            return false;
        }
    }

    $bounded_input = function_exists('ll_tools_sanitize_user_study_goal_id_array')
        ? ll_tools_sanitize_user_study_goal_id_array($category_ids, $category_key)
        : $category_ids;
    if (count($bounded_input) !== count($category_ids)) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a WordPress user ID. */
            __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
            $user_id
        ));
        return false;
    }

    $expanded_ids = ll_tools_wordset_isolation_expand_category_id_list_across_wordsets($bounded_input);
    $expanded_ids = ll_tools_wordset_isolation_parse_category_id_list($expanded_ids);
    $bounded_expansion = function_exists('ll_tools_sanitize_user_study_goal_id_array')
        ? ll_tools_sanitize_user_study_goal_id_array($expanded_ids, $category_key)
        : $expanded_ids;
    if (
        empty($expanded_ids)
        || count($bounded_expansion) !== count($expanded_ids)
        || !empty(array_diff($expanded_ids, $bounded_expansion))
        || !empty(array_diff($bounded_expansion, $expanded_ids))
    ) {
        ll_tools_wordset_isolation_migration_fail($state, sprintf(
            /* translators: %d is a WordPress user ID. */
            __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
            $user_id
        ));
        return false;
    }

    foreach ($bounded_expansion as $category_id) {
        $category_id = (int) $category_id;
        $owner_wordset_id = ll_tools_get_category_wordset_owner_id($category_id);
        if ($owner_wordset_id > 0) {
            if (!ll_tools_wordset_isolation_migration_require_user_category_mapping(
                [$category_id],
                [$owner_wordset_id],
                $user_id,
                $state
            )) {
                return false;
            }
            continue;
        }
        if (ll_tools_get_category_isolation_source_id($category_id) !== $category_id) {
            ll_tools_wordset_isolation_migration_fail($state, sprintf(
                /* translators: %d is a WordPress user ID. */
                __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                $user_id
            ));
            return false;
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_has_source_category_family_row(int $source_category_id): ?bool {
    global $wpdb;

    $source_category_id = max(0, $source_category_id);
    if ($source_category_id <= 0) {
        return null;
    }
    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT term_id
         FROM {$wpdb->termmeta}
         WHERE meta_key = %s
           AND meta_value = %s
         LIMIT 1",
        LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
        (string) $source_category_id
    ));
    if ($wpdb->last_error !== '') {
        return null;
    }
    return $found !== null;
}

/**
 * Resolve category IDs that still have word-category taxonomy rows.
 *
 * Chunked fail-closed reads distinguish confirmed deletions from lookup
 * failures without constructing an unbounded IN clause.
 *
 * @return array<int,int>|null Null when a bounded lookup fails.
 */
function ll_tools_wordset_isolation_migration_existing_category_ids(
    array $raw_category_ids,
    int $user_id,
    array &$state
): ?array {
    global $wpdb;

    $category_ids = ll_tools_wordset_isolation_parse_category_id_list($raw_category_ids);
    if (empty($category_ids)) {
        return [];
    }

    $failure_message = sprintf(
        /* translators: %d is a WordPress user ID. */
        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
        $user_id
    );
    $batch_size = max(1, min(500, (int) apply_filters(
        'll_tools_wordset_isolation_migration_category_lookup_batch_size',
        250
    )));
    $current_ids = [];
    foreach (array_chunk($category_ids, $batch_size) as $category_id_batch) {
        $placeholders = implode(', ', array_fill(0, count($category_id_batch), '%d'));
        try {
            $current_ids = array_merge($current_ids, ll_tools_wordset_isolation_migration_query_ids($wpdb->prepare(
                "SELECT /* ll-tools-existing-category-preflight */ taxonomy.term_id
                 FROM {$wpdb->term_taxonomy} AS taxonomy
                 WHERE taxonomy.taxonomy = %s
                   AND taxonomy.term_id IN ({$placeholders})
                 GROUP BY taxonomy.term_id",
                array_merge(['word-category'], $category_id_batch)
            ), $failure_message));
        } catch (Throwable $error) {
            ll_tools_wordset_isolation_migration_fail($state, $failure_message);
            return null;
        }
    }

    $current_lookup = array_fill_keys($current_ids, true);
    return array_values(array_filter($category_ids, static function (int $category_id) use ($current_lookup): bool {
        return isset($current_lookup[$category_id]);
    }));
}

/**
 * Keep only current terms from a user's active category selection.
 *
 * Deleted selections are transient UI state, unlike historical progress.
 */
function ll_tools_wordset_isolation_migration_current_user_category_ids(
    $raw_category_ids,
    int $user_id,
    array &$state
): ?array {
    $category_ids = function_exists('ll_tools_user_study_sanitize_state_id_array')
        ? ll_tools_user_study_sanitize_state_id_array($raw_category_ids, 'category_ids')
        : ll_tools_wordset_isolation_parse_category_id_list($raw_category_ids);

    return ll_tools_wordset_isolation_migration_existing_category_ids($category_ids, $user_id, $state);
}

/**
 * Repair one bounded deferral bucket from a complete category snapshot.
 *
 * The migration cannot reuse the shared runtime helper's lossy lookup: a
 * transient empty or partial read after preflight could otherwise delete
 * durable deferrals. Build and validate the exact source-to-target map once,
 * then pass a snapshot-only mapper into the shared normalization/re-key logic.
 *
 * @return array<string,mixed>|null Null when discovery or exact mapping fails.
 */
function ll_tools_wordset_isolation_migration_prepare_recommendation_deferral_entries(
    array $entries,
    int $wordset_id,
    int $user_id,
    array &$state
): ?array {
    $failure_message = sprintf(
        /* translators: %d is a WordPress user ID. */
        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
        $user_id
    );
    if (count($entries) > 256) {
        ll_tools_wordset_isolation_migration_fail($state, $failure_message);
        return null;
    }

    $all_category_ids = [];
    foreach ($entries as $signature => $raw_entry) {
        if (!is_array($raw_entry)) {
            continue;
        }
        $raw_category_ids = (array) ($raw_entry['category_ids'] ?? []);
        // One recommendation activity has at most thirty session words, so a
        // larger category list cannot be legitimate generated state.
        if (count($raw_category_ids) > 30) {
            ll_tools_wordset_isolation_migration_fail($state, $failure_message);
            return null;
        }
        $entry = function_exists('ll_tools_normalize_recommendation_deferral_entry')
            ? ll_tools_normalize_recommendation_deferral_entry((string) $signature, $raw_entry)
            : $raw_entry;
        if (!is_array($entry)) {
            continue;
        }

        $entry_category_ids = ll_tools_wordset_isolation_parse_category_id_list(
            (array) ($entry['category_ids'] ?? [])
        );
        foreach ($entry_category_ids as $category_id) {
            $all_category_ids[(int) $category_id] = true;
        }
    }

    $current_category_ids = ll_tools_wordset_isolation_migration_existing_category_ids(
        array_map('intval', array_keys($all_category_ids)),
        $user_id,
        $state
    );
    if (
        $current_category_ids === null
        || !ll_tools_wordset_isolation_migration_require_user_category_mapping(
            $current_category_ids,
            [$wordset_id],
            $user_id,
            $state
        )
    ) {
        return null;
    }

    $current_lookup = array_fill_keys($current_category_ids, true);
    $category_id_map = ll_tools_wordset_isolation_get_category_id_map_for_wordset(
        $wordset_id,
        $current_category_ids,
        false
    );
    $requires_owned_targets = $wordset_id > 0 && ll_tools_is_wordset_isolation_enabled();
    foreach ($current_category_ids as $source_category_id) {
        $source_category_id = (int) $source_category_id;
        $target_category_id = (int) ($category_id_map[$source_category_id] ?? 0);
        if ($target_category_id <= 0) {
            ll_tools_wordset_isolation_migration_fail($state, $failure_message);
            return null;
        }
        if (!$requires_owned_targets) {
            continue;
        }

        $source_origin_id = ll_tools_get_category_isolation_source_id($source_category_id);
        if (
            $source_origin_id <= 0
            || ll_tools_get_category_wordset_owner_id($target_category_id) !== $wordset_id
            || ll_tools_get_category_isolation_source_id($target_category_id) !== $source_origin_id
        ) {
            ll_tools_wordset_isolation_migration_fail($state, $failure_message);
            return null;
        }
    }

    $snapshot_mapper = static function (array $source_category_ids, int $requested_wordset_id) use (
        $wordset_id,
        $current_lookup,
        $category_id_map
    ): ?array {
        if ($requested_wordset_id !== $wordset_id) {
            return null;
        }

        $mapped = [];
        foreach (ll_tools_wordset_isolation_parse_category_id_list($source_category_ids) as $source_category_id) {
            $source_category_id = (int) $source_category_id;
            if (!isset($current_lookup[$source_category_id])) {
                continue;
            }
            $target_category_id = (int) ($category_id_map[$source_category_id] ?? 0);
            if ($target_category_id <= 0) {
                return null;
            }
            if (!in_array($target_category_id, $mapped, true)) {
                $mapped[] = $target_category_id;
            }
        }
        return $mapped;
    };
    $repair_status = null;
    $repaired = ll_tools_repair_recommendation_deferral_map_for_isolation(
        $entries,
        $wordset_id,
        256,
        $snapshot_mapper,
        $repair_status
    );
    if ($repair_status !== 'ok') {
        ll_tools_wordset_isolation_migration_fail($state, $failure_message);
        return null;
    }

    return $repaired;
}

function ll_tools_wordset_isolation_migration_preflight_recommendation_deferral_snapshot(
    array $deferrals,
    int $user_id,
    array &$state
): bool {
    foreach ($deferrals as $wordset_id => $entries) {
        $wordset_id = (int) $wordset_id;
        if (ll_tools_wordset_isolation_migration_prepare_recommendation_deferral_entries(
            (array) $entries,
            $wordset_id,
            $user_id,
            $state
        ) === null) {
            return false;
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_preflight_user_category_mappings(int $user_id, array &$state): bool {
    $user_wordset_id = defined('LL_TOOLS_USER_WORDSET_META')
        ? (int) get_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, true)
        : 0;

    if (defined('LL_TOOLS_USER_CATEGORY_META')) {
        $categories = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true);
        if (is_array($categories)) {
            $current_categories = ll_tools_wordset_isolation_migration_current_user_category_ids(
                $categories,
                $user_id,
                $state
            );
            if (
                $current_categories === null
                || !ll_tools_wordset_isolation_migration_require_user_category_mapping(
                $current_categories,
                [$user_wordset_id],
                $user_id,
                $state
                )
            ) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_GOALS_META')) {
        $goals = get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true);
        if (is_array($goals)) {
            foreach (['ignored_category_ids', 'placement_known_category_ids'] as $category_key) {
                if (!ll_tools_wordset_isolation_migration_require_user_goal_category_mapping(
                    (array) ($goals[$category_key] ?? []),
                    $category_key,
                    $user_id,
                    $state
                )) {
                    return false;
                }
            }
        }
    }

    if (defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META')) {
        $progress = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
        if (is_array($progress)) {
            $expected_progress = function_exists('ll_tools_repair_user_category_progress_store_for_isolation')
                ? ll_tools_repair_user_category_progress_store_for_isolation($progress)
                : null;
            foreach ($progress as $raw_category_id => $entry) {
                $category_id = (int) $raw_category_id;
                if ($category_id <= 0 || !is_array($entry) || !is_array($expected_progress)) {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                $term = get_term($category_id, 'word-category');
                if (is_wp_error($term)) {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                if (!($term instanceof WP_Term)) {
                    $wordset_id = (int) ($entry['wordset_id'] ?? 0);
                    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
                    $has_family_row = ll_tools_wordset_isolation_migration_has_source_category_family_row($category_id);
                    $preserved = ($entry['category_id'] ?? null) === $category_id
                        && ($wordset instanceof WP_Term)
                        && !is_wp_error($wordset)
                        && $has_family_row === false
                        && array_key_exists($category_id, $expected_progress)
                        && $expected_progress[$category_id] === $entry;
                    if ($preserved) {
                        continue;
                    }
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                if (!ll_tools_wordset_isolation_migration_require_user_category_mapping(
                    [$category_id],
                    [(int) ($entry['wordset_id'] ?? 0)],
                    $user_id,
                    $state
                )) {
                    return false;
                }
            }
        }
    }

    if (defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META')) {
        $queues = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
        if (is_array($queues) && !ll_tools_wordset_isolation_migration_preflight_recommendation_queue_snapshot(
            $queues,
            $user_id,
            $state
        )) {
            return false;
        }
    }

    if (defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META')) {
        $activities = get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true);
        if (is_array($activities) && !ll_tools_wordset_isolation_migration_preflight_last_recommendation_snapshot(
            $activities,
            $user_id,
            $state
        )) {
            return false;
        }
    }

    if (defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META')) {
        $deferrals = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true);
        if (
            is_array($deferrals)
            && !ll_tools_wordset_isolation_migration_preflight_recommendation_deferral_snapshot(
                $deferrals,
                $user_id,
                $state
            )
        ) {
            return false;
        }
    }

    if (defined('LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META')) {
        $prompt_progress = get_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, true);
        if (is_array($prompt_progress)) {
            foreach ($prompt_progress as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!ll_tools_wordset_isolation_migration_require_user_category_mapping(
                    [(int) ($entry['category_id'] ?? 0)],
                    [(int) ($entry['wordset_id'] ?? 0)],
                    $user_id,
                    $state
                )) {
                    return false;
                }
            }
        }
    }

    return true;
}

function ll_tools_wordset_isolation_migration_process_user(int $user_id, array &$state): bool {
    if (!ll_tools_wordset_isolation_migration_preflight_user_category_mappings($user_id, $state)) {
        return false;
    }

    if (defined('LL_TOOLS_USER_CATEGORY_META')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true);
        if (is_array($before)) {
            $wordset_id = defined('LL_TOOLS_USER_WORDSET_META')
                ? (int) get_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, true)
                : 0;
            $sanitized = ll_tools_wordset_isolation_migration_current_user_category_ids(
                $before,
                $user_id,
                $state
            );
            if (
                $sanitized === null
                || !ll_tools_wordset_isolation_migration_require_user_category_mapping(
                    $sanitized,
                    [$wordset_id],
                    $user_id,
                    $state
                )
            ) {
                return false;
            }
            $expected = $sanitized;
            if ($wordset_id > 0 && !empty($sanitized)) {
                $repaired = ll_tools_get_isolated_category_ids_for_wordsets($sanitized, [$wordset_id]);
                if (empty($repaired)) {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                $expected = function_exists('ll_tools_user_study_sanitize_state_id_array')
                    ? ll_tools_user_study_sanitize_state_id_array($repaired, 'category_ids')
                    : array_values(array_filter(array_map('intval', $repaired)));
            }
            if ($expected !== $before && !ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_CATEGORY_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_GOALS_META') && function_exists('ll_tools_sanitize_user_study_goals')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true);
        if (is_array($before)) {
            $normalized = ll_tools_sanitize_user_study_goals($before);
            $expected = $before;
            $raw_ignored = array_values(array_unique(array_filter(array_map('intval', (array) ($before['ignored_category_ids'] ?? [])))));
            $raw_placement = array_values(array_unique(array_filter(array_map('intval', (array) ($before['placement_known_category_ids'] ?? [])))));
            if (($normalized['ignored_category_ids'] ?? []) !== $raw_ignored) {
                $expected['ignored_category_ids'] = (array) ($normalized['ignored_category_ids'] ?? []);
            }
            if (($normalized['placement_known_category_ids'] ?? []) !== $raw_placement) {
                $expected['placement_known_category_ids'] = (array) ($normalized['placement_known_category_ids'] ?? []);
            }
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_GOALS_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') && function_exists('ll_tools_repair_user_category_progress_store_for_isolation')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
        if (is_array($before)) {
            $expected = ll_tools_repair_user_category_progress_store_for_isolation($before);
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_CATEGORY_PROGRESS_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') && function_exists('ll_tools_repair_recommendation_queue_for_isolation')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
        if (is_array($before)) {
            // Revalidate the exact value that will become the CAS baseline. A
            // concurrent refresh after the initial user preflight must not
            // introduce an unverified category reference into this write.
            if (!ll_tools_wordset_isolation_migration_preflight_recommendation_queue_snapshot(
                $before,
                $user_id,
                $state
            )) {
                return false;
            }
            $expected = $before;
            foreach (array_keys($expected) as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                if ($wordset_id <= 0) {
                    continue;
                }
                $queue_key = (string) $wordset_id;
                $queue_raw = isset($expected[$queue_key]) && is_array($expected[$queue_key]) ? $expected[$queue_key] : [];
                $repair_status = null;
                $expected[$queue_key] = ll_tools_repair_recommendation_queue_for_isolation(
                    $queue_raw,
                    $wordset_id,
                    8,
                    $repair_status
                );
                if ($repair_status === 'error') {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
            }
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_RECOMMENDATION_QUEUE_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') && function_exists('ll_tools_repair_recommendation_activity_for_isolation')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true);
        if (is_array($before)) {
            if (!ll_tools_wordset_isolation_migration_preflight_last_recommendation_snapshot(
                $before,
                $user_id,
                $state
            )) {
                return false;
            }
            $expected = $before;
            foreach (array_keys($expected) as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                if ($wordset_id <= 0) {
                    continue;
                }
                $activity_key = (string) $wordset_id;
                $repair_status = null;
                $normalized = ll_tools_repair_recommendation_activity_for_isolation(
                    $expected[$activity_key] ?? null,
                    $wordset_id,
                    $repair_status
                );
                if ($repair_status === 'error') {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                if ($normalized) {
                    $expected[$activity_key] = $normalized;
                } else {
                    unset($expected[$activity_key]);
                }
            }
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_LAST_RECOMMENDATION_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META') && function_exists('ll_tools_repair_recommendation_deferral_map_for_isolation')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true);
        if (is_array($before)) {
            $expected = $before;
            foreach (array_keys($expected) as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                if ($wordset_id <= 0) {
                    continue;
                }
                $bucket_key = (string) $wordset_id;
                $bucket = isset($expected[$bucket_key]) && is_array($expected[$bucket_key])
                    ? $expected[$bucket_key]
                    : [];
                $repaired = ll_tools_wordset_isolation_migration_prepare_recommendation_deferral_entries(
                    $bucket,
                    $wordset_id,
                    $user_id,
                    $state
                );
                if ($repaired === null) {
                    return false;
                }
                if (empty($repaired)) {
                    unset($expected[$bucket_key]);
                } else {
                    $expected[$bucket_key] = $repaired;
                }
            }
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }

    if (defined('LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META')) {
        $before = get_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, true);
        if (is_array($before)) {
            $expected = $before;
            foreach ($expected as $prompt_card_id => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $category_id = (int) ($entry['category_id'] ?? 0);
                $wordset_id = (int) ($entry['wordset_id'] ?? 0);
                if ($category_id <= 0 || $wordset_id <= 0) {
                    continue;
                }
                $mapped_ids = ll_tools_get_isolated_category_ids_for_wordsets([$category_id], [$wordset_id]);
                if (empty($mapped_ids)) {
                    ll_tools_wordset_isolation_migration_fail($state, sprintf(
                        /* translators: %d is a WordPress user ID. */
                        __('Isolated category data could not be saved for user %d.', 'll-tools-text-domain'),
                        $user_id
                    ));
                    return false;
                }
                $expected[$prompt_card_id]['category_id'] = (int) reset($mapped_ids);
            }
            if (!ll_tools_wordset_isolation_migration_write_user_meta(
                $user_id,
                LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META,
                $before,
                $expected,
                $state
            )) {
                return false;
            }
        }
    }
    return true;
}

function ll_tools_wordset_isolation_migration_advance_phase(array &$state): void {
    $phases = ll_tools_wordset_isolation_migration_phase_order();
    $index = array_search((string) $state['phase'], $phases, true);
    $state['phase'] = ($index !== false && isset($phases[$index + 1])) ? $phases[$index + 1] : 'finalize';
    $state['cursor'] = 0;
}

function ll_tools_wordset_isolation_schedule_bounded_category_reconciliation(int $delay = MINUTE_IN_SECONDS): bool {
    if (!wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK)) {
        wp_schedule_single_event(
            time() + max(1, $delay),
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK
        );
    }

    return wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK) !== false;
}

function ll_tools_wordset_isolation_reconciliation_update_option_exact(string $option_name, array $before, array $after): bool {
    global $wpdb;

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s
           AND option_value = %s",
        maybe_serialize($after),
        $option_name,
        maybe_serialize($before)
    ));
    wp_cache_delete($option_name, 'options');
    return $updated === 1 && get_option($option_name, false) === $after;
}

function ll_tools_wordset_isolation_reconciliation_delete_option_exact(string $option_name, $before): bool {
    global $wpdb;

    if ($before === false) {
        return true;
    }
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s
           AND option_value = %s",
        $option_name,
        maybe_serialize($before)
    ));
    wp_cache_delete($option_name, 'options');
    return $deleted === 1 || get_option($option_name, false) === false;
}

function ll_tools_wordset_isolation_request_bounded_category_reconciliation(): bool {
    $state = [
        'schema' => 1,
        'token' => strtolower(wp_generate_password(24, false, false)),
        'status' => 'waiting',
        'requested_at' => time(),
        'updated_at' => time(),
        'attempts' => 0,
        'quiz_queued_at' => 0,
        'vocab_queued_at' => 0,
    ];
    $saved = false;
    for ($attempt = 0; $attempt < 3 && !$saved; $attempt++) {
        $existing = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false);
        if ($existing === false) {
            $saved = add_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, $state, '', false);
        } elseif (is_array($existing)) {
            $saved = ll_tools_wordset_isolation_reconciliation_update_option_exact(
                LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
                $existing,
                $state
            );
        } else {
            $saved = ll_tools_wordset_isolation_reconciliation_delete_option_exact(
                LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
                $existing
            ) && add_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, $state, '', false);
        }
        wp_cache_delete(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, 'options');
    }
    if (!$saved) {
        return false;
    }
    $stored = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false);
    if (!is_array($stored) || !hash_equals($state['token'], (string) ($stored['token'] ?? ''))) {
        return false;
    }

    if (!ll_tools_wordset_isolation_schedule_bounded_category_reconciliation(MINUTE_IN_SECONDS)) {
        ll_tools_wordset_isolation_reconciliation_delete_option_exact(
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
            $stored
        );
        return false;
    }
    return true;
}

function ll_tools_wordset_isolation_reconciliation_acquire_lock(): string {
    $now = time();
    $existing = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION, false);
    if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) > $now) {
        return '';
    }
    if (
        $existing !== false
        && !ll_tools_wordset_isolation_reconciliation_delete_option_exact(
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION,
            $existing
        )
    ) {
        return '';
    }

    $token = strtolower(wp_generate_password(24, false, false));
    $lock = [
        'token' => $token,
        'expires_at' => $now + 5 * MINUTE_IN_SECONDS,
    ];
    return add_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION, $lock, '', false)
        ? $token
        : '';
}

function ll_tools_wordset_isolation_reconciliation_release_lock(string $token): void {
    $lock = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION, false);
    if (
        $token !== ''
        && is_array($lock)
        && hash_equals((string) ($lock['token'] ?? ''), $token)
    ) {
        ll_tools_wordset_isolation_reconciliation_delete_option_exact(
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION,
            $lock
        );
    }
}

function ll_tools_wordset_isolation_reconciliation_state_is_active(array $state): bool {
    return in_array((string) ($state['status'] ?? ''), ['queued', 'running'], true);
}

function ll_tools_wordset_isolation_reconciliation_tag_fresh_child(
    string $option_name,
    string $token,
    int $requested_at,
    string $event_hook,
    string $kind
): int {
    $state = get_option($option_name, false);
    if (!is_array($state) || wp_next_scheduled($event_hook) === false) {
        return 0;
    }
    $queued_at = (int) ($state['queued_at'] ?? 0);
    $status = (string) ($state['status'] ?? '');
    if ($queued_at < $requested_at || $status !== 'queued') {
        return 0;
    }
    if ($kind === 'quiz') {
        if ((string) ($state['phase'] ?? '') !== 'cleanup' || (int) ($state['cursor'] ?? -1) !== 0) {
            return 0;
        }
    } elseif (
        (string) ($state['phase'] ?? '') !== 'sync'
        || (int) ($state['wordset_index'] ?? -1) !== 0
        || (int) ($state['category_cursor'] ?? -1) !== 0
    ) {
        return 0;
    } elseif (function_exists('ll_tools_get_vocab_lesson_wordset_ids')) {
        $actual_wordset_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($state['wordset_ids'] ?? [])
        ), static fn(int $wordset_id): bool => $wordset_id > 0)));
        $expected_wordset_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ll_tools_get_vocab_lesson_wordset_ids()
        ), static fn(int $wordset_id): bool => $wordset_id > 0)));
        sort($actual_wordset_ids, SORT_NUMERIC);
        sort($expected_wordset_ids, SORT_NUMERIC);
        if ($actual_wordset_ids !== $expected_wordset_ids) {
            return 0;
        }
    }

    $tagged = $state;
    $tagged['wordset_isolation_reconciliation_token'] = $token;
    return ll_tools_wordset_isolation_reconciliation_update_option_exact($option_name, $state, $tagged)
        ? $queued_at
        : 0;
}

function ll_tools_wordset_isolation_run_bounded_category_reconciliation(): void {
    $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false);
    if (!is_array($request) || (string) ($request['token'] ?? '') === '') {
        if ($request !== false) {
            ll_tools_wordset_isolation_reconciliation_delete_option_exact(
                LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
                $request
            );
        }
        return;
    }

    // WP-Cron consumes a single event before invoking its callback. Re-arm first;
    // durable intent remains until both tagged child passes complete.
    if (!ll_tools_wordset_isolation_schedule_bounded_category_reconciliation(MINUTE_IN_SECONDS)) {
        return;
    }

    $lock_token = ll_tools_wordset_isolation_reconciliation_acquire_lock();
    if ($lock_token === '') {
        return;
    }

    try {
        $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false);
        if (!is_array($request) || (string) ($request['token'] ?? '') === '') {
            return;
        }
        $token = (string) $request['token'];
        $vocab_enabled = !function_exists('ll_tools_vocab_lessons_enabled') || ll_tools_vocab_lessons_enabled();
        $quiz_state = function_exists('ll_tools_get_quiz_page_sync_state')
            ? ll_tools_get_quiz_page_sync_state()
            : [];
        $vocab_state = $vocab_enabled && function_exists('ll_tools_get_vocab_lesson_reconciliation_state')
            ? ll_tools_get_vocab_lesson_reconciliation_state()
            : [];
        $quiz_active = ll_tools_wordset_isolation_reconciliation_state_is_active($quiz_state);
        $vocab_active = $vocab_enabled && ll_tools_wordset_isolation_reconciliation_state_is_active($vocab_state);
        $quiz_locked = defined('LL_TOOLS_QUIZ_PAGE_SYNC_LOCK')
            && (bool) get_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK);
        $vocab_locked = $vocab_enabled
            && defined('LL_TOOLS_VOCAB_LESSON_SYNC_LOCK')
            && (bool) get_transient(LL_TOOLS_VOCAB_LESSON_SYNC_LOCK);

        if ((string) ($request['status'] ?? '') === 'launched') {
            $quiz_complete = (string) ($quiz_state['status'] ?? '') === 'completed'
                && hash_equals($token, (string) ($quiz_state['wordset_isolation_reconciliation_token'] ?? ''))
                && (int) ($quiz_state['queued_at'] ?? 0) === (int) ($request['quiz_queued_at'] ?? 0);
            $vocab_complete = !$vocab_enabled || (
                (string) ($vocab_state['status'] ?? '') === 'completed'
                && hash_equals($token, (string) ($vocab_state['wordset_isolation_reconciliation_token'] ?? ''))
                && (int) ($vocab_state['queued_at'] ?? 0) === (int) ($request['vocab_queued_at'] ?? 0)
            );
            if ($quiz_complete && $vocab_complete) {
                ll_tools_wordset_isolation_reconciliation_delete_option_exact(
                    LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
                    $request
                );
                return;
            }
            if ($quiz_active || $quiz_locked || $vocab_active || $vocab_locked) {
                if (
                    $quiz_active
                    && !$quiz_locked
                    && wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT) === false
                    && function_exists('ll_tools_schedule_quiz_page_full_sync')
                ) {
                    ll_tools_schedule_quiz_page_full_sync(1);
                }
                if (
                    $vocab_active
                    && !$vocab_locked
                    && wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT) === false
                    && function_exists('ll_tools_schedule_vocab_lesson_full_sync')
                ) {
                    ll_tools_schedule_vocab_lesson_full_sync(1);
                }
                return;
            }

            $waiting = $request;
            $waiting['status'] = 'waiting';
            $waiting['updated_at'] = time();
            $waiting['quiz_queued_at'] = 0;
            $waiting['vocab_queued_at'] = 0;
            ll_tools_wordset_isolation_reconciliation_update_option_exact(
                LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
                $request,
                $waiting
            );
            return;
        }

        if ($quiz_active || $quiz_locked || $vocab_active || $vocab_locked) {
            if ($quiz_active && function_exists('ll_tools_schedule_quiz_page_full_sync')) {
                ll_tools_schedule_quiz_page_full_sync(1);
            }
            if ($vocab_active && function_exists('ll_tools_schedule_vocab_lesson_full_sync')) {
                ll_tools_schedule_vocab_lesson_full_sync(1);
            }
            return;
        }

        if (!function_exists('ll_tools_schedule_quiz_page_full_sync')) {
            return;
        }
        ll_tools_schedule_quiz_page_full_sync(1);
        if ($vocab_enabled) {
            if (!function_exists('ll_tools_schedule_vocab_lesson_full_sync')) {
                return;
            }
            ll_tools_schedule_vocab_lesson_full_sync(1);
        }

        $requested_at = max(1, (int) ($request['requested_at'] ?? 0));
        $quiz_queued_at = ll_tools_wordset_isolation_reconciliation_tag_fresh_child(
            LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION,
            $token,
            $requested_at,
            LL_TOOLS_QUIZ_PAGE_SYNC_EVENT,
            'quiz'
        );
        $vocab_queued_at = $vocab_enabled
            ? ll_tools_wordset_isolation_reconciliation_tag_fresh_child(
                LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION,
                $token,
                $requested_at,
                LL_TOOLS_VOCAB_LESSON_SYNC_EVENT,
                'vocab'
            )
            : 0;
        if ($quiz_queued_at <= 0 || ($vocab_enabled && $vocab_queued_at <= 0)) {
            return;
        }

        $launched = $request;
        $launched['status'] = 'launched';
        $launched['updated_at'] = time();
        $launched['attempts'] = max(0, (int) ($request['attempts'] ?? 0)) + 1;
        $launched['quiz_queued_at'] = $quiz_queued_at;
        $launched['vocab_queued_at'] = $vocab_queued_at;
        ll_tools_wordset_isolation_reconciliation_update_option_exact(
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
            $request,
            $launched
        );
    } finally {
        ll_tools_wordset_isolation_reconciliation_release_lock($lock_token);
    }
}
add_action(
    LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK,
    'll_tools_wordset_isolation_run_bounded_category_reconciliation'
);

function ll_tools_wordset_isolation_maybe_schedule_bounded_category_reconciliation(): void {
    $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false);
    if ($request === false) {
        return;
    }
    if (!is_array($request) || (string) ($request['token'] ?? '') === '') {
        ll_tools_wordset_isolation_reconciliation_delete_option_exact(
            LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION,
            $request
        );
        return;
    }
    ll_tools_wordset_isolation_schedule_bounded_category_reconciliation(1);
}
add_action('admin_init', 'll_tools_wordset_isolation_maybe_schedule_bounded_category_reconciliation', 6);

function ll_tools_run_wordset_isolation_migration_batch(): array {
    if (!ll_tools_is_wordset_isolation_enabled()) {
        return ll_tools_get_wordset_isolation_migration_state();
    }
    if (ll_tools_get_wordset_isolation_migration_version() >= LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION) {
        return ll_tools_queue_wordset_isolation_migration();
    }

    $lease_token = ll_tools_wordset_isolation_migration_acquire_lease();
    if ($lease_token === '') {
        $state = ll_tools_get_wordset_isolation_migration_state();
        if (in_array((string) ($state['status'] ?? ''), ['queued', 'running'], true)) {
            ll_tools_wordset_isolation_migration_schedule_next(MINUTE_IN_SECONDS);
        }
        return $state;
    }

    $deferred_category_maintenance = false;
    $deferred_category_maintenance_queue_before = [];
    try {
        if (
            function_exists('ll_tools_begin_deferred_category_maintenance')
            && function_exists('ll_tools_end_deferred_category_maintenance')
        ) {
            if (function_exists('ll_tools_get_category_maintenance_runtime')) {
                $maintenance_runtime = &ll_tools_get_category_maintenance_runtime();
                $deferred_category_maintenance_queue_before = is_array($maintenance_runtime['queued_category_ids'] ?? null)
                    ? $maintenance_runtime['queued_category_ids']
                    : [];
            }
            ll_tools_begin_deferred_category_maintenance('wordset-isolation-migration');
            $deferred_category_maintenance = true;
        }

        $state = ll_tools_get_wordset_isolation_migration_state();
        if ($state['status'] === 'failed') {
            return $state;
        }
        if ($state['target_version'] !== LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION) {
            $state = ll_tools_wordset_isolation_migration_new_state();
        }
        $state['status'] = 'running';
        if ((int) $state['started_at'] <= 0) {
            $state['started_at'] = time();
        }
        if (!ll_tools_wordset_isolation_migration_save_state($state, $lease_token)) {
            return ll_tools_get_wordset_isolation_migration_state();
        }

        $phase = (string) $state['phase'];
        $cursor = (int) $state['cursor'];
        $batch_size = ll_tools_wordset_isolation_migration_batch_size();
        $ids = [];
        if ($phase === 'words') {
            $ids = ll_tools_wordset_isolation_migration_post_ids('words', $cursor, $batch_size);
        } elseif ($phase === 'images') {
            $ids = ll_tools_wordset_isolation_migration_post_ids('word_images', $cursor, $batch_size);
        } elseif ($phase === 'wordsets') {
            $ids = ll_tools_wordset_isolation_migration_wordset_ids($cursor, $batch_size);
        } elseif ($phase === 'lessons') {
            $ids = ll_tools_wordset_isolation_migration_post_ids('ll_vocab_lesson', $cursor, $batch_size);
        } elseif ($phase === 'users') {
            $ids = ll_tools_wordset_isolation_migration_user_ids($cursor, $batch_size);
        }

        if (in_array($phase, ['words', 'images', 'wordsets', 'lessons', 'users'], true)) {
            foreach ($ids as $id) {
                $success = true;
                if ($phase === 'words') {
                    $success = ll_tools_wordset_isolation_migration_process_word($id, $state);
                } elseif ($phase === 'images') {
                    $success = ll_tools_wordset_isolation_migration_process_image($id, $state);
                } elseif ($phase === 'wordsets') {
                    $success = ll_tools_wordset_isolation_migration_process_wordset($id, $state);
                } elseif ($phase === 'lessons') {
                    $success = ll_tools_wordset_isolation_migration_process_lesson($id, $state);
                } elseif ($phase === 'users') {
                    $success = ll_tools_wordset_isolation_migration_process_user($id, $state);
                }
                if (!$success) {
                    ll_tools_wordset_isolation_migration_save_state($state, $lease_token);
                    return $state;
                }
                $state['cursor'] = $id;
                if (!ll_tools_wordset_isolation_migration_save_state($state, $lease_token)) {
                    return ll_tools_get_wordset_isolation_migration_state();
                }
            }

            if (count($ids) < $batch_size) {
                ll_tools_wordset_isolation_migration_advance_phase($state);
            }
        } elseif ($phase === 'word_option_rules') {
            global $wpdb;
            $store_length = $wpdb->get_var($wpdb->prepare(
                "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'll_tools_word_option_rules'
            ));
            if ($wpdb->last_error !== '') {
                ll_tools_wordset_isolation_migration_fail(
                    $state,
                    __('Could not inspect word option rules for the isolation migration.', 'll-tools-text-domain')
                );
            } elseif (
                is_numeric($store_length)
                && (int) $store_length > ll_tools_wordset_isolation_migration_word_option_rules_max_bytes()
            ) {
                ll_tools_wordset_isolation_migration_fail(
                    $state,
                    __('Word option rules are too large for the background migration. Run the WP-CLI migration with --allow-large-option-rules.', 'll-tools-text-domain')
                );
            }
            if ($state['status'] !== 'failed' && function_exists('ll_tools_repair_word_option_rules_store_for_isolation')) {
                $raw_store = get_option('ll_tools_word_option_rules', []);
                $expected_store = is_array($raw_store) ? $raw_store : [];
                if (!empty($expected_store) && function_exists('ll_tools_word_option_rules_repair_store_array_for_isolation')) {
                    $repair = ll_tools_word_option_rules_repair_store_array_for_isolation($expected_store, true);
                    $expected_store = is_array($repair['store'] ?? null) ? $repair['store'] : $expected_store;
                }
                $state['counters']['word_option_rule_scopes_repaired'] = (int) ll_tools_repair_word_option_rules_store_for_isolation(true);
                $persisted_store = get_option('ll_tools_word_option_rules', []);
                if (!is_array($persisted_store) || $persisted_store !== $expected_store) {
                    ll_tools_wordset_isolation_migration_fail(
                        $state,
                        __('Isolated word option rules could not be saved.', 'll-tools-text-domain')
                    );
                }
            }
            if ($state['status'] !== 'failed') {
                ll_tools_wordset_isolation_migration_advance_phase($state);
            }
        } elseif ($phase === 'finalize') {
            if (!empty($state['errors'])) {
                ll_tools_wordset_isolation_migration_fail($state, __('Wordset isolation migration has unresolved errors.', 'll-tools-text-domain'));
            } else {
                // Persist a replayable finalization checkpoint before any side effects.
                // If execution stops below, the next batch repeats this idempotent block.
                $state['status'] = 'running';
                $state['phase'] = 'finalize';
                if (!ll_tools_wordset_isolation_migration_save_state($state, $lease_token)) {
                    return ll_tools_get_wordset_isolation_migration_state();
                }
                if (function_exists('ll_tools_bump_category_cache_epoch')) {
                    ll_tools_bump_category_cache_epoch();
                }
                if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
                    ll_tools_bump_wordset_cache_epoch();
                }
                if (function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
                    ll_tools_invalidate_wordset_isolation_health_report();
                }
                set_transient(
                    LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT,
                    array_merge($state['counters'], ['errors' => []]),
                    DAY_IN_SECONDS
                );
                $state['status'] = 'completed';
                $state['completed_at'] = time();
                if (!ll_tools_wordset_isolation_migration_save_state($state, $lease_token)) {
                    return ll_tools_get_wordset_isolation_migration_state();
                }
                if (ll_tools_wordset_isolation_migration_refresh_lease($lease_token) <= 0) {
                    return ll_tools_get_wordset_isolation_migration_state();
                }
                if (!ll_tools_wordset_isolation_request_bounded_category_reconciliation()) {
                    ll_tools_wordset_isolation_migration_fail(
                        $state,
                        __('Generated-page reconciliation could not be scheduled after the isolation migration.', 'll-tools-text-domain')
                    );
                } else {
                    update_option(
                        LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION,
                        LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION,
                        false
                    );
                    if (ll_tools_get_wordset_isolation_migration_version() !== LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION) {
                        ll_tools_wordset_isolation_migration_fail(
                            $state,
                            __('The wordset isolation migration version could not be saved.', 'll-tools-text-domain')
                        );
                    } else {
                        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK);
                        return $state;
                    }
                }
            }
        }

        if ($state['status'] === 'running') {
            $state['status'] = 'queued';
        }
        if (!ll_tools_wordset_isolation_migration_save_state($state, $lease_token)) {
            return ll_tools_get_wordset_isolation_migration_state();
        }
        if ($state['status'] === 'queued') {
            ll_tools_wordset_isolation_migration_schedule_next();
        }
        return $state;
    } catch (Throwable $throwable) {
        $state = isset($state) && is_array($state) ? $state : ll_tools_get_wordset_isolation_migration_state();
        ll_tools_wordset_isolation_migration_fail($state, $throwable->getMessage());
        return ll_tools_wordset_isolation_migration_save_state($state, $lease_token)
            ? $state
            : ll_tools_get_wordset_isolation_migration_state();
    } finally {
        try {
            if ($deferred_category_maintenance) {
                // Migration mutations can touch thousands of categories. Their generated-page
                // maintenance is reconciled by the migration-owned follow-up queued at finalization.
                ll_tools_end_deferred_category_maintenance(false);
                if (function_exists('ll_tools_get_category_maintenance_runtime')) {
                    $maintenance_runtime = &ll_tools_get_category_maintenance_runtime();
                    $maintenance_runtime['queued_category_ids'] = $deferred_category_maintenance_queue_before;
                }
            }
        } finally {
            ll_tools_wordset_isolation_migration_release_lease($lease_token);
        }
    }
}
add_action(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK, 'll_tools_run_wordset_isolation_migration_batch');

function ll_tools_run_wordset_isolation_migration(): array {
    $state = ll_tools_queue_wordset_isolation_migration(true);
    $max_batches = max(1, (int) apply_filters('ll_tools_wordset_isolation_migration_drain_max_batches', 10000));
    for ($batch = 0; $batch < $max_batches && !in_array($state['status'], ['completed', 'failed'], true); $batch++) {
        $before_progress = wp_json_encode([
            'status' => (string) ($state['status'] ?? ''),
            'phase' => (string) ($state['phase'] ?? ''),
            'cursor' => (int) ($state['cursor'] ?? 0),
            'counters' => (array) ($state['counters'] ?? []),
            'errors' => (array) ($state['errors'] ?? []),
        ]);
        $next_state = ll_tools_run_wordset_isolation_migration_batch();
        $after_progress = wp_json_encode([
            'status' => (string) ($next_state['status'] ?? ''),
            'phase' => (string) ($next_state['phase'] ?? ''),
            'cursor' => (int) ($next_state['cursor'] ?? 0),
            'counters' => (array) ($next_state['counters'] ?? []),
            'errors' => (array) ($next_state['errors'] ?? []),
        ]);
        $state = $next_state;
        if ($before_progress === $after_progress) {
            break;
        }
    }

    return array_merge(
        ll_tools_wordset_isolation_migration_counter_defaults(),
        (array) ($state['counters'] ?? []),
        [
            'status' => (string) ($state['status'] ?? 'idle'),
            'phase' => (string) ($state['phase'] ?? 'words'),
            'errors' => (array) ($state['errors'] ?? []),
        ]
    );
}

function ll_tools_maybe_run_wordset_isolation_migration(): void {
    if (!is_admin()) {
        return;
    }
    if (defined('WP_TESTS_DOMAIN')) {
        return;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!ll_tools_is_wordset_isolation_enabled()) {
        return;
    }
    if (ll_tools_get_wordset_isolation_migration_version() >= LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION) {
        return;
    }

    ll_tools_queue_wordset_isolation_migration();
}
add_action('admin_init', 'll_tools_maybe_run_wordset_isolation_migration', 5);

function ll_tools_retry_wordset_isolation_migration_handler(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to retry this migration.', 'll-tools-text-domain'), '', ['response' => 403]);
    }
    check_admin_referer('ll_tools_retry_wordset_isolation_migration');
    ll_tools_queue_wordset_isolation_migration(true);
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_ll_tools_retry_wordset_isolation_migration', 'll_tools_retry_wordset_isolation_migration_handler');

function ll_tools_render_wordset_isolation_migration_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $state = ll_tools_get_wordset_isolation_migration_state();
    if (
        ll_tools_get_wordset_isolation_migration_version() < LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION
        && in_array($state['status'], ['queued', 'running', 'failed'], true)
    ) {
        $is_failed = $state['status'] === 'failed';
        $phase_labels = [
            'words' => __('Words', 'll-tools-text-domain'),
            'images' => __('Word images', 'll-tools-text-domain'),
            'wordsets' => __('Word sets', 'll-tools-text-domain'),
            'word_option_rules' => __('Word option rules', 'll-tools-text-domain'),
            'lessons' => __('Lessons', 'll-tools-text-domain'),
            'users' => __('Users', 'll-tools-text-domain'),
            'finalize' => __('Finalizing', 'll-tools-text-domain'),
        ];
        $phase_label = (string) ($phase_labels[(string) $state['phase']] ?? $state['phase']);
        $message = $is_failed
            ? sprintf(
                /* translators: 1: migration phase, 2: error message. */
                __('Wordset isolation migration stopped in phase %1$s: %2$s', 'll-tools-text-domain'),
                $phase_label,
                (string) ($state['last_error'] ?: __('Unknown error.', 'll-tools-text-domain'))
            )
            : sprintf(
                /* translators: 1: migration phase, 2: number of words scanned. */
                __('Wordset isolation migration is continuing in the background. Phase: %1$s. Words scanned: %2$d.', 'll-tools-text-domain'),
                $phase_label,
                (int) ($state['counters']['words_scanned'] ?? 0)
            );
        echo '<div class="notice ' . ($is_failed ? 'notice-error' : 'notice-info') . '"><p>' . esc_html($message) . '</p>';
        if ($is_failed) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="ll_tools_retry_wordset_isolation_migration" />';
            wp_nonce_field('ll_tools_retry_wordset_isolation_migration');
            submit_button(__('Retry migration', 'll-tools-text-domain'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div>';
        return;
    }

    $result = get_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT);
    if (!is_array($result)) {
        return;
    }

    delete_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT);

    $classes = empty($result['errors']) ? 'notice notice-success' : 'notice notice-warning';
    printf(
        '<div class="%1$s"><p>%2$s</p></div>',
        esc_attr($classes),
        esc_html(sprintf(
            __('Wordset isolation migration completed. Words scanned: %1$d. Words updated: %2$d. Categories created: %3$d. Word images created: %4$d. Word-image links updated: %5$d. Lessons repaired: %6$d. Wordsets repaired: %7$d. Word option rule scopes repaired: %8$d. User study records repaired: %9$d.', 'll-tools-text-domain'),
            (int) ($result['words_scanned'] ?? 0),
            (int) ($result['words_updated'] ?? 0),
            (int) ($result['categories_created'] ?? 0),
            (int) ($result['images_created'] ?? 0),
            (int) ($result['images_relinked'] ?? 0),
            (int) ($result['lessons_repaired'] ?? 0),
            (int) ($result['wordsets_repaired'] ?? 0),
            (int) ($result['word_option_rule_scopes_repaired'] ?? 0),
            (int) ($result['user_data_repaired'] ?? 0)
        ))
    );
}
add_action('admin_notices', 'll_tools_render_wordset_isolation_migration_notice');

function ll_tools_current_user_can_view_wordset_isolation_health_notice(): bool {
    if (!is_user_logged_in()) {
        return false;
    }

    if (function_exists('ll_tools_current_user_can_settings_maintenance')) {
        return ll_tools_current_user_can_settings_maintenance();
    }

    return current_user_can('manage_options');
}

function ll_tools_invalidate_wordset_isolation_health_report(): void {
    delete_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT);
}

function ll_tools_get_wordset_isolation_health_report_ttl(): int {
    $ttl = defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL')
        ? (int) LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL
        : (15 * MINUTE_IN_SECONDS);

    $ttl = (int) apply_filters('ll_tools_wordset_isolation_health_report_ttl', $ttl);
    return max(MINUTE_IN_SECONDS, $ttl);
}

function ll_tools_get_wordset_isolation_health_refresh_state(): array {
    $state = get_option(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION, []);
    return [
        'status' => in_array((string) ($state['status'] ?? ''), ['idle', 'queued', 'running', 'completed', 'failed'], true)
            ? (string) $state['status']
            : 'idle',
        'queued_at' => max(0, (int) ($state['queued_at'] ?? 0)),
        'started_at' => max(0, (int) ($state['started_at'] ?? 0)),
        'completed_at' => max(0, (int) ($state['completed_at'] ?? 0)),
        'message' => sanitize_text_field((string) ($state['message'] ?? '')),
    ];
}

function ll_tools_queue_wordset_isolation_health_refresh(): array {
    $state = [
        'status' => 'queued',
        'queued_at' => time(),
        'started_at' => 0,
        'completed_at' => 0,
        'message' => '',
    ];
    update_option(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION, $state, false);
    if (!wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK)) {
        wp_schedule_single_event(time() + 1, LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK);
    }
    return $state;
}

function ll_tools_run_wordset_isolation_health_refresh(): array {
    if (get_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK)) {
        return ll_tools_get_wordset_isolation_health_refresh_state();
    }
    set_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK, 1, 15 * MINUTE_IN_SECONDS);

    $state = ll_tools_get_wordset_isolation_health_refresh_state();
    $state['status'] = 'running';
    $state['started_at'] = time();
    $state['message'] = '';
    update_option(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION, $state, false);

    try {
        $report = ll_tools_build_wordset_isolation_health_report();
        set_transient(
            LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT,
            $report,
            ll_tools_get_wordset_isolation_health_report_ttl()
        );
        $state['status'] = 'completed';
        $state['completed_at'] = time();
    } catch (Throwable $error) {
        $state['status'] = 'failed';
        $state['completed_at'] = time();
        $state['message'] = sanitize_text_field($error->getMessage());
    } finally {
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK);
    }

    update_option(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION, $state, false);
    return $state;
}
add_action(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK, 'll_tools_run_wordset_isolation_health_refresh');

function ll_tools_get_wordset_isolation_vocab_repair_state(): array {
    $state = get_option(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION, []);
    return [
        'status' => in_array((string) ($state['status'] ?? ''), ['idle', 'queued', 'running', 'completed', 'failed'], true)
            ? (string) $state['status']
            : 'idle',
        'cursor' => max(0, (int) ($state['cursor'] ?? 0)),
        'processed' => max(0, (int) ($state['processed'] ?? 0)),
        'repaired' => max(0, (int) ($state['repaired'] ?? 0)),
        'queued_at' => max(0, (int) ($state['queued_at'] ?? 0)),
        'completed_at' => max(0, (int) ($state['completed_at'] ?? 0)),
        'message' => sanitize_text_field((string) ($state['message'] ?? '')),
    ];
}

function ll_tools_queue_wordset_isolation_vocab_repair(): array {
    $state = [
        'status' => 'queued',
        'cursor' => 0,
        'processed' => 0,
        'repaired' => 0,
        'queued_at' => time(),
        'completed_at' => 0,
        'message' => '',
    ];
    update_option(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION, $state, false);
    if (!wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK)) {
        wp_schedule_single_event(time() + 1, LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK);
    }
    return $state;
}

function ll_tools_run_wordset_isolation_vocab_repair_batch(): array {
    global $wpdb;

    if (get_transient(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK)) {
        return ll_tools_get_wordset_isolation_vocab_repair_state();
    }
    set_transient(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK, 1, 5 * MINUTE_IN_SECONDS);

    $state = ll_tools_get_wordset_isolation_vocab_repair_state();
    $state['status'] = 'running';
    $state['message'] = '';
    update_option(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION, $state, false);

    try {
        $batch_size = (int) apply_filters('ll_tools_wordset_isolation_vocab_repair_batch_size', 100);
        $batch_size = max(1, min(250, $batch_size));
        $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'll_vocab_lesson'
               AND post_status IN ('publish', 'draft', 'pending', 'future', 'private')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            (int) $state['cursor'],
            $batch_size + 1
        )))));
        $has_more = count($ids) > $batch_size;
        $batch_ids = array_slice($ids, 0, $batch_size);
        foreach ($batch_ids as $lesson_id) {
            if (ll_tools_repair_vocab_lesson_category_meta_for_isolation($lesson_id)) {
                $state['repaired']++;
            }
        }
        if (!empty($batch_ids)) {
            $state['cursor'] = (int) end($batch_ids);
            $state['processed'] += count($batch_ids);
        }

        if ($has_more) {
            $state['status'] = 'queued';
            wp_schedule_single_event(time() + 1, LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK);
        } else {
            $state['status'] = 'completed';
            $state['completed_at'] = time();
            ll_tools_queue_wordset_isolation_health_refresh();
        }
    } catch (Throwable $error) {
        $state['status'] = 'failed';
        $state['completed_at'] = time();
        $state['message'] = sanitize_text_field($error->getMessage());
    } finally {
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK);
    }

    update_option(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION, $state, false);
    return $state;
}
add_action(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK, 'll_tools_run_wordset_isolation_vocab_repair_batch');

function ll_tools_handle_queue_wordset_isolation_health_refresh(): void {
    if (!ll_tools_current_user_can_view_wordset_isolation_health_notice()) {
        wp_die(
            esc_html__('You are not allowed to run this maintenance check.', 'll-tools-text-domain'),
            '',
            ['response' => 403]
        );
    }
    check_admin_referer('ll_tools_queue_wordset_isolation_health_refresh');
    ll_tools_queue_wordset_isolation_health_refresh();
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_ll_tools_queue_wordset_isolation_health_refresh', 'll_tools_handle_queue_wordset_isolation_health_refresh');

function ll_tools_handle_queue_wordset_isolation_vocab_repair(): void {
    if (!ll_tools_current_user_can_view_wordset_isolation_health_notice()) {
        wp_die(
            esc_html__('You are not allowed to run this maintenance repair.', 'll-tools-text-domain'),
            '',
            ['response' => 403]
        );
    }
    check_admin_referer('ll_tools_queue_wordset_isolation_vocab_repair');
    ll_tools_queue_wordset_isolation_vocab_repair();
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_ll_tools_queue_wordset_isolation_vocab_repair', 'll_tools_handle_queue_wordset_isolation_vocab_repair');

function ll_tools_render_wordset_isolation_health_actions(array $report = []): void {
    $health_state = ll_tools_get_wordset_isolation_health_refresh_state();
    $repair_state = ll_tools_get_wordset_isolation_vocab_repair_state();
    $health_pending = in_array((string) $health_state['status'], ['queued', 'running'], true);
    $repair_pending = in_array((string) $repair_state['status'], ['queued', 'running'], true);

    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0;">';
    if ($health_pending) {
        echo '<span>' . esc_html__('Health check queued or running.', 'll-tools-text-domain') . '</span>';
    } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ll_tools_queue_wordset_isolation_health_refresh">';
        wp_nonce_field('ll_tools_queue_wordset_isolation_health_refresh');
        submit_button(__('Check now', 'll-tools-text-domain'), 'secondary', 'submit', false);
        echo '</form>';
    }

    $lesson_count = (int) (($report['issues']['vocab_lessons']['count'] ?? 0));
    if ($repair_pending) {
        echo '<span>' . esc_html(sprintf(
            __('Vocab lesson repair: %1$d processed, %2$d repaired.', 'll-tools-text-domain'),
            (int) $repair_state['processed'],
            (int) $repair_state['repaired']
        )) . '</span>';
    } elseif ($lesson_count > 0) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ll_tools_queue_wordset_isolation_vocab_repair">';
        wp_nonce_field('ll_tools_queue_wordset_isolation_vocab_repair');
        submit_button(__('Repair vocab lessons', 'll-tools-text-domain'), 'secondary', 'submit', false);
        echo '</form>';
    }
    echo '</div>';
}

function ll_tools_wordset_isolation_health_format_id_list(array $ids, int $limit = 8): string {
    $ids = array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    }));

    if (empty($ids)) {
        return (string) __('none', 'll-tools-text-domain');
    }

    $display_ids = array_slice($ids, 0, max(1, $limit));
    $parts = array_map(static function (int $id): string {
        return '#' . $id;
    }, $display_ids);

    if (count($ids) > count($display_ids)) {
        $parts[] = '...';
    }

    return implode(', ', $parts);
}

function ll_tools_wordset_isolation_health_format_category_refs(array $refs, int $limit = 8): string {
    if (empty($refs)) {
        return (string) __('none', 'll-tools-text-domain');
    }

    $parts = [];
    foreach (array_slice($refs, 0, max(1, $limit)) as $ref) {
        $parts[] = sprintf(
            '#%1$d[o:%2$d,s:%3$d]',
            (int) ($ref['id'] ?? 0),
            (int) ($ref['owner_id'] ?? 0),
            (int) ($ref['source_id'] ?? 0)
        );
    }

    if (count($refs) > count($parts)) {
        $parts[] = '...';
    }

    return implode(', ', $parts);
}

function ll_tools_wordset_isolation_health_format_foreign_refs(array $refs, int $limit = 8): string {
    if (empty($refs)) {
        return (string) __('none', 'll-tools-text-domain');
    }

    $parts = [];
    foreach (array_slice($refs, 0, max(1, $limit)) as $ref) {
        $parts[] = sprintf(
            '#%1$d->w%2$d',
            (int) ($ref['category_id'] ?? 0),
            (int) ($ref['owner_id'] ?? 0)
        );
    }

    if (count($refs) > count($parts)) {
        $parts[] = '...';
    }

    return implode(', ', $parts);
}

function ll_tools_wordset_isolation_health_format_missing_pairs(array $pairs, int $limit = 8): string {
    if (empty($pairs)) {
        return (string) __('none', 'll-tools-text-domain');
    }

    $parts = [];
    foreach (array_slice($pairs, 0, max(1, $limit)) as $pair) {
        $parts[] = sprintf(
            's%1$d->w%2$d',
            (int) ($pair['source_id'] ?? 0),
            (int) ($pair['wordset_id'] ?? 0)
        );
    }

    if (count($pairs) > count($parts)) {
        $parts[] = '...';
    }

    return implode(', ', $parts);
}

function ll_tools_collect_wordset_isolation_word_assignment_anomalies(int $sample_limit = 5): array {
    $result = [
        'count' => 0,
        'samples' => [],
    ];

    $word_ids = get_posts([
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    foreach ((array) $word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
            ? ll_tools_get_post_wordset_ids($word_id)
            : [];
        $wordset_ids = function_exists('ll_tools_normalize_wordset_setting_ids')
            ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
            : array_values(array_filter(array_map('intval', (array) $wordset_ids), static function (int $id): bool {
                return $id > 0;
            }));
        if (empty($wordset_ids)) {
            continue;
        }

        $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($category_ids) || empty($category_ids)) {
            continue;
        }

        $category_refs = [];
        $shared_ids = [];
        $foreign_refs = [];
        $origin_owner_map = [];

        foreach ((array) $category_ids as $category_id) {
            $category_id = (int) $category_id;
            if ($category_id <= 0) {
                continue;
            }

            $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
            $source_id = ll_tools_get_category_isolation_source_id($category_id);
            $category_refs[] = [
                'id' => $category_id,
                'owner_id' => $owner_id,
                'source_id' => $source_id,
            ];

            if ($owner_id <= 0) {
                $shared_ids[$category_id] = true;
            } elseif (!in_array($owner_id, $wordset_ids, true)) {
                $foreign_refs[] = [
                    'category_id' => $category_id,
                    'owner_id' => $owner_id,
                ];
            }

            if (!isset($origin_owner_map[$source_id])) {
                $origin_owner_map[$source_id] = [];
            }
            $origin_owner_map[$source_id][$owner_id] = true;
        }

        $missing_pairs = [];
        foreach ($origin_owner_map as $source_id => $owner_lookup) {
            foreach ($wordset_ids as $wordset_id) {
                if (empty($owner_lookup[$wordset_id])) {
                    $missing_pairs[] = [
                        'source_id' => (int) $source_id,
                        'wordset_id' => (int) $wordset_id,
                    ];
                }
            }
        }

        if (empty($shared_ids) && empty($foreign_refs) && empty($missing_pairs)) {
            continue;
        }

        $result['count']++;
        if (count($result['samples']) >= $sample_limit) {
            continue;
        }

        $post = get_post($word_id);
        $result['samples'][] = [
            'id' => $word_id,
            'title' => ($post instanceof WP_Post && !is_wp_error($post)) ? (string) $post->post_title : '',
            'url' => (string) get_edit_post_link($word_id, ''),
            'wordset_ids' => $wordset_ids,
            'category_refs' => $category_refs,
            'shared_ids' => array_values(array_map('intval', array_keys($shared_ids))),
            'foreign_refs' => $foreign_refs,
            'missing_pairs' => $missing_pairs,
        ];
    }

    return $result;
}

function ll_tools_collect_wordset_isolation_word_image_assignment_anomalies(int $sample_limit = 5): array {
    $result = [
        'count' => 0,
        'samples' => [],
    ];

    $image_ids = get_posts([
        'post_type' => 'word_images',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    foreach ((array) $image_ids as $image_id) {
        $image_id = (int) $image_id;
        if ($image_id <= 0) {
            continue;
        }

        $owner_wordset_id = ll_tools_get_word_image_wordset_owner_id($image_id);
        if ($owner_wordset_id <= 0) {
            continue;
        }

        $category_ids = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($category_ids) || empty($category_ids)) {
            continue;
        }

        $category_refs = [];
        $shared_ids = [];
        $foreign_refs = [];
        $origin_owner_map = [];

        foreach ((array) $category_ids as $category_id) {
            $category_id = (int) $category_id;
            if ($category_id <= 0) {
                continue;
            }

            $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
            $source_id = ll_tools_get_category_isolation_source_id($category_id);
            $category_refs[] = [
                'id' => $category_id,
                'owner_id' => $owner_id,
                'source_id' => $source_id,
            ];

            if ($owner_id <= 0) {
                $shared_ids[$category_id] = true;
            } elseif ($owner_id !== $owner_wordset_id) {
                $foreign_refs[] = [
                    'category_id' => $category_id,
                    'owner_id' => $owner_id,
                ];
            }

            if (!isset($origin_owner_map[$source_id])) {
                $origin_owner_map[$source_id] = [];
            }
            $origin_owner_map[$source_id][$owner_id] = true;
        }

        $missing_pairs = [];
        foreach ($origin_owner_map as $source_id => $owner_lookup) {
            if (empty($owner_lookup[$owner_wordset_id])) {
                $missing_pairs[] = [
                    'source_id' => (int) $source_id,
                    'wordset_id' => $owner_wordset_id,
                ];
            }
        }

        if (empty($shared_ids) && empty($foreign_refs) && empty($missing_pairs)) {
            continue;
        }

        $result['count']++;
        if (count($result['samples']) >= $sample_limit) {
            continue;
        }

        $post = get_post($image_id);
        $result['samples'][] = [
            'id' => $image_id,
            'title' => ($post instanceof WP_Post && !is_wp_error($post)) ? (string) $post->post_title : '',
            'url' => (string) get_edit_post_link($image_id, ''),
            'owner_wordset_id' => $owner_wordset_id,
            'category_refs' => $category_refs,
            'shared_ids' => array_values(array_map('intval', array_keys($shared_ids))),
            'foreign_refs' => $foreign_refs,
            'missing_pairs' => $missing_pairs,
        ];
    }

    return $result;
}

function ll_tools_collect_wordset_isolation_vocab_lesson_anomalies(int $sample_limit = 5): array {
    $result = [
        'count' => 0,
        'samples' => [],
    ];

    if (!defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META') || !defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
        return $result;
    }

    $lesson_ids = get_posts([
        'post_type' => 'll_vocab_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    foreach ((array) $lesson_ids as $lesson_id) {
        $lesson_id = (int) $lesson_id;
        if ($lesson_id <= 0) {
            continue;
        }

        $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
        $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        if ($wordset_id <= 0 || $category_id <= 0) {
            continue;
        }

        $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
        if ($owner_id === $wordset_id) {
            continue;
        }

        $source_id = ll_tools_get_category_isolation_source_id($category_id);
        $existing_copy_id = $source_id > 0
            ? ll_tools_get_existing_isolated_category_copy_id($source_id, $wordset_id)
            : 0;

        $result['count']++;
        if (count($result['samples']) >= $sample_limit) {
            continue;
        }

        $post = get_post($lesson_id);
        $result['samples'][] = [
            'id' => $lesson_id,
            'title' => ($post instanceof WP_Post && !is_wp_error($post)) ? (string) $post->post_title : '',
            'url' => (string) get_edit_post_link($lesson_id, ''),
            'wordset_id' => $wordset_id,
            'category_ref' => [
                'id' => $category_id,
                'owner_id' => $owner_id,
                'source_id' => $source_id,
            ],
            'existing_copy_id' => $existing_copy_id,
        ];
    }

    return $result;
}

function ll_tools_collect_wordset_isolation_wordset_meta_anomalies(int $sample_limit = 5): array {
    $result = [
        'count' => 0,
        'samples' => [],
    ];

    $wordset_ids = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return $result;
    }

    foreach ((array) $wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }

        $manual_bad_refs = [];
        $manual_ids = ll_tools_wordset_isolation_parse_category_id_list(get_term_meta($wordset_id, 'll_wordset_category_manual_order', true));
        foreach ($manual_ids as $category_id) {
            $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
            if ($owner_id === $wordset_id) {
                continue;
            }

            $manual_bad_refs[] = [
                'id' => (int) $category_id,
                'owner_id' => $owner_id,
                'source_id' => ll_tools_get_category_isolation_source_id($category_id),
            ];
        }

        $prereq_key_bad_refs = [];
        $prereq_dependency_bad_refs = [];
        $raw_prereq_map = get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true);
        $prereq_map = ll_tools_wordset_isolation_normalize_prereq_map($raw_prereq_map);
        foreach ((array) $prereq_map as $category_id => $dependency_ids) {
            $category_id = (int) $category_id;
            if ($category_id > 0) {
                $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
                if ($owner_id !== $wordset_id) {
                    $prereq_key_bad_refs[] = [
                        'id' => $category_id,
                        'owner_id' => $owner_id,
                        'source_id' => ll_tools_get_category_isolation_source_id($category_id),
                    ];
                }
            }

            foreach ((array) $dependency_ids as $dependency_id) {
                $dependency_id = (int) $dependency_id;
                if ($dependency_id <= 0) {
                    continue;
                }

                $owner_id = ll_tools_get_category_wordset_owner_id($dependency_id);
                if ($owner_id === $wordset_id) {
                    continue;
                }

                $prereq_dependency_bad_refs[] = [
                    'id' => $dependency_id,
                    'owner_id' => $owner_id,
                    'source_id' => ll_tools_get_category_isolation_source_id($dependency_id),
                ];
            }
        }

        if (empty($manual_bad_refs) && empty($prereq_key_bad_refs) && empty($prereq_dependency_bad_refs)) {
            continue;
        }

        $result['count']++;
        if (count($result['samples']) >= $sample_limit) {
            continue;
        }

        $term = get_term($wordset_id, 'wordset');
        $result['samples'][] = [
            'id' => $wordset_id,
            'title' => ($term instanceof WP_Term && !is_wp_error($term)) ? (string) $term->name : '',
            'url' => (string) get_edit_term_link($wordset_id, 'wordset', 'words'),
            'manual_bad_refs' => $manual_bad_refs,
            'prereq_key_bad_refs' => $prereq_key_bad_refs,
            'prereq_dependency_bad_refs' => $prereq_dependency_bad_refs,
        ];
    }

    return $result;
}

function ll_tools_build_wordset_isolation_health_report(): array {
    $stored_version = ll_tools_get_wordset_isolation_migration_version();
    $expected_version = (int) LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION;

    $report = [
        'generated_at' => time(),
        'migration_version' => [
            'stored' => $stored_version,
            'expected' => $expected_version,
        ],
        'issues' => [
            'migration_outdated' => [
                'count' => $stored_version < $expected_version ? 1 : 0,
            ],
            'words' => ll_tools_collect_wordset_isolation_word_assignment_anomalies(),
            'word_images' => ll_tools_collect_wordset_isolation_word_image_assignment_anomalies(),
            'vocab_lessons' => ll_tools_collect_wordset_isolation_vocab_lesson_anomalies(),
            'wordset_meta' => ll_tools_collect_wordset_isolation_wordset_meta_anomalies(),
        ],
    ];

    $report['has_issues'] = ll_tools_wordset_isolation_health_report_has_issues($report);
    return $report;
}

function ll_tools_get_wordset_isolation_health_report(bool $force = false): array {
    static $request_cache = null;

    if (!$force && is_array($request_cache)) {
        return $request_cache;
    }

    if (!$force) {
        $cached = get_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT);
        if (is_array($cached)) {
            $request_cache = $cached;
            return $cached;
        }
    }

    $report = ll_tools_build_wordset_isolation_health_report();
    $request_cache = $report;

    set_transient(
        LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT,
        $report,
        ll_tools_get_wordset_isolation_health_report_ttl()
    );

    return $report;
}

function ll_tools_wordset_isolation_health_report_has_issues(array $report): bool {
    $issues = isset($report['issues']) && is_array($report['issues'])
        ? $report['issues']
        : [];

    foreach ($issues as $issue) {
        if ((int) ($issue['count'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

function ll_tools_render_wordset_isolation_health_notice(): void {
    if (!is_admin()) {
        return;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }
    if (!ll_tools_is_wordset_isolation_enabled()) {
        return;
    }
    if (!ll_tools_current_user_can_view_wordset_isolation_health_notice()) {
        return;
    }

    $report = get_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT);
    if (!is_array($report)) {
        echo '<div class="notice notice-info">';
        echo '<p><strong>' . esc_html__('LL Tools Maintenance', 'll-tools-text-domain') . ':</strong> ';
        echo esc_html__('Wordset isolation health has not been checked since the last data change or cache expiry.', 'll-tools-text-domain') . '</p>';
        ll_tools_render_wordset_isolation_health_actions();
        echo '</div>';
        return;
    }
    if (!ll_tools_wordset_isolation_health_report_has_issues($report)) {
        return;
    }

    $issues = (array) ($report['issues'] ?? []);
    $migration_version = (array) ($report['migration_version'] ?? []);
    $stored_version = (int) ($migration_version['stored'] ?? 0);
    $expected_version = (int) ($migration_version['expected'] ?? 0);
    $generated_at = (int) ($report['generated_at'] ?? 0);
    $generated_label = (string) __('unknown', 'll-tools-text-domain');
    if ($generated_at > 0) {
        $format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
        $generated_label = function_exists('wp_date')
            ? wp_date($format, $generated_at)
            : date_i18n($format, $generated_at);
    }

    $summary_items = [];
    if ((int) (($issues['migration_outdated']['count'] ?? 0)) > 0) {
        $summary_items[] = sprintf(
            __('Stored migration version is %1$d, expected %2$d.', 'll-tools-text-domain'),
            $stored_version,
            $expected_version
        );
    }

    $word_count = (int) (($issues['words']['count'] ?? 0));
    if ($word_count > 0) {
        $summary_items[] = sprintf(
            _n(
                '%d word has suspicious category isolation state.',
                '%d words have suspicious category isolation state.',
                $word_count,
                'll-tools-text-domain'
            ),
            $word_count
        );
    }

    $word_image_count = (int) (($issues['word_images']['count'] ?? 0));
    if ($word_image_count > 0) {
        $summary_items[] = sprintf(
            _n(
                '%d word image has suspicious category isolation state.',
                '%d word images have suspicious category isolation state.',
                $word_image_count,
                'll-tools-text-domain'
            ),
            $word_image_count
        );
    }

    $lesson_count = (int) (($issues['vocab_lessons']['count'] ?? 0));
    if ($lesson_count > 0) {
        $summary_items[] = sprintf(
            _n(
                '%d vocab lesson points at a non-owned category.',
                '%d vocab lessons point at non-owned categories.',
                $lesson_count,
                'll-tools-text-domain'
            ),
            $lesson_count
        );
    }

    $wordset_meta_count = (int) (($issues['wordset_meta']['count'] ?? 0));
    if ($wordset_meta_count > 0) {
        $summary_items[] = sprintf(
            _n(
                '%d wordset has category ordering or prerequisite metadata that still references non-owned categories.',
                '%d wordsets have category ordering or prerequisite metadata that still reference non-owned categories.',
                $wordset_meta_count,
                'll-tools-text-domain'
            ),
            $wordset_meta_count
        );
    }

    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>' . esc_html__('LL Tools Maintenance Alert', 'll-tools-text-domain') . ':</strong> ';
    echo esc_html__('Wordset isolation health checks found data that may need repair.', 'll-tools-text-domain') . '</p>';
    echo '<p>' . esc_html(sprintf(
        __('Last checked: %1$s. Stored migration version: %2$d. Expected version: %3$d.', 'll-tools-text-domain'),
        $generated_label,
        $stored_version,
        $expected_version
    )) . '</p>';

    if (!empty($summary_items)) {
        echo '<ul>';
        foreach ($summary_items as $summary_item) {
            echo '<li>' . esc_html($summary_item) . '</li>';
        }
        echo '</ul>';
    }

    echo '<details><summary>' . esc_html__('Debug details', 'll-tools-text-domain') . '</summary>';

    if (!empty($issues['words']['samples'])) {
        echo '<p><strong>' . esc_html__('Word samples', 'll-tools-text-domain') . '</strong></p><ul>';
        foreach ((array) $issues['words']['samples'] as $sample) {
            $label = sprintf(
                '#%1$d %2$s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['title'] ?? '')
            );
            $clauses = [
                sprintf(__('Wordsets: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list((array) ($sample['wordset_ids'] ?? []))),
                sprintf(__('Categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs((array) ($sample['category_refs'] ?? []))),
            ];
            if (!empty($sample['shared_ids'])) {
                $clauses[] = sprintf(__('Shared categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list((array) $sample['shared_ids']));
            }
            if (!empty($sample['foreign_refs'])) {
                $clauses[] = sprintf(__('Foreign-owner categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_foreign_refs((array) $sample['foreign_refs']));
            }
            if (!empty($sample['missing_pairs'])) {
                $clauses[] = sprintf(__('Missing owned copies: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_missing_pairs((array) $sample['missing_pairs']));
            }

            echo '<li>';
            if (!empty($sample['url'])) {
                echo '<a href="' . esc_url((string) $sample['url']) . '">' . esc_html(trim($label)) . '</a>: ';
            } else {
                echo esc_html(trim($label)) . ': ';
            }
            echo esc_html(implode(' ', $clauses));
            echo '</li>';
        }
        echo '</ul>';
    }

    if (!empty($issues['word_images']['samples'])) {
        echo '<p><strong>' . esc_html__('Word image samples', 'll-tools-text-domain') . '</strong></p><ul>';
        foreach ((array) $issues['word_images']['samples'] as $sample) {
            $label = sprintf(
                '#%1$d %2$s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['title'] ?? '')
            );
            $clauses = [
                sprintf(__('Owner wordset: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list([(int) ($sample['owner_wordset_id'] ?? 0)])),
                sprintf(__('Categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs((array) ($sample['category_refs'] ?? []))),
            ];
            if (!empty($sample['shared_ids'])) {
                $clauses[] = sprintf(__('Shared categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list((array) $sample['shared_ids']));
            }
            if (!empty($sample['foreign_refs'])) {
                $clauses[] = sprintf(__('Foreign-owner categories: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_foreign_refs((array) $sample['foreign_refs']));
            }
            if (!empty($sample['missing_pairs'])) {
                $clauses[] = sprintf(__('Missing owned copies: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_missing_pairs((array) $sample['missing_pairs']));
            }

            echo '<li>';
            if (!empty($sample['url'])) {
                echo '<a href="' . esc_url((string) $sample['url']) . '">' . esc_html(trim($label)) . '</a>: ';
            } else {
                echo esc_html(trim($label)) . ': ';
            }
            echo esc_html(implode(' ', $clauses));
            echo '</li>';
        }
        echo '</ul>';
    }

    if (!empty($issues['vocab_lessons']['samples'])) {
        echo '<p><strong>' . esc_html__('Vocab lesson samples', 'll-tools-text-domain') . '</strong></p><ul>';
        foreach ((array) $issues['vocab_lessons']['samples'] as $sample) {
            $label = sprintf(
                '#%1$d %2$s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['title'] ?? '')
            );
            $clauses = [
                sprintf(__('Wordset: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list([(int) ($sample['wordset_id'] ?? 0)])),
                sprintf(__('Category: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs([(array) ($sample['category_ref'] ?? [])])),
            ];
            if (!empty($sample['existing_copy_id'])) {
                $clauses[] = sprintf(__('Existing owned copy: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_id_list([(int) $sample['existing_copy_id']]));
            }

            echo '<li>';
            if (!empty($sample['url'])) {
                echo '<a href="' . esc_url((string) $sample['url']) . '">' . esc_html(trim($label)) . '</a>: ';
            } else {
                echo esc_html(trim($label)) . ': ';
            }
            echo esc_html(implode(' ', $clauses));
            echo '</li>';
        }
        echo '</ul>';
    }

    if (!empty($issues['wordset_meta']['samples'])) {
        echo '<p><strong>' . esc_html__('Wordset metadata samples', 'll-tools-text-domain') . '</strong></p><ul>';
        foreach ((array) $issues['wordset_meta']['samples'] as $sample) {
            $label = sprintf(
                '#%1$d %2$s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['title'] ?? '')
            );
            $clauses = [];
            if (!empty($sample['manual_bad_refs'])) {
                $clauses[] = sprintf(__('Manual order refs: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs((array) $sample['manual_bad_refs']));
            }
            if (!empty($sample['prereq_key_bad_refs'])) {
                $clauses[] = sprintf(__('Prerequisite keys: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs((array) $sample['prereq_key_bad_refs']));
            }
            if (!empty($sample['prereq_dependency_bad_refs'])) {
                $clauses[] = sprintf(__('Prerequisite dependencies: %s.', 'll-tools-text-domain'), ll_tools_wordset_isolation_health_format_category_refs((array) $sample['prereq_dependency_bad_refs']));
            }

            echo '<li>';
            if (!empty($sample['url'])) {
                echo '<a href="' . esc_url((string) $sample['url']) . '">' . esc_html(trim($label)) . '</a>: ';
            } else {
                echo esc_html(trim($label)) . ': ';
            }
            echo esc_html(implode(' ', $clauses));
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '</details>';
    ll_tools_render_wordset_isolation_health_actions($report);
    echo '</div>';
}
add_action('admin_notices', 'll_tools_render_wordset_isolation_health_notice', 6);
