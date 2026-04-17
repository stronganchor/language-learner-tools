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
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT', 'll_tools_wordset_isolation_health_report');
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL')) {
    define('LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TTL', 15 * MINUTE_IN_SECONDS);
}
if (!defined('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION', 4);
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

function ll_tools_get_category_wordset_owner_id($category): int {
    $term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return 0;
    }

    return max(0, (int) get_term_meta((int) $term->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
}

function ll_tools_get_category_isolation_source_id($category): int {
    $term = function_exists('ll_tools_resolve_word_category_term')
        ? ll_tools_resolve_word_category_term($category)
        : get_term($category, 'word-category');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return 0;
    }

    $origin_id = max(0, (int) get_term_meta((int) $term->term_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, true));
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
        $existing = term_exists($name, 'word-category', isset($args['parent']) ? (int) $args['parent'] : 0);
        if ($existing) {
            return (int) (is_array($existing) ? ($existing['term_id'] ?? 0) : $existing);
        }

        $insert = wp_insert_term($name, 'word-category', $args);
        if (is_wp_error($insert)) {
            return $insert;
        }

        $term_id = (int) ($insert['term_id'] ?? 0);
        if ($term_id > 0) {
            ll_tools_set_category_wordset_owner($term_id, 0, $term_id);
        }
        return $term_id;
    }

    $parent_id = isset($args['parent']) ? (int) $args['parent'] : 0;
    $parent_id = $parent_id > 0 ? ll_tools_get_or_create_isolated_category_copy($parent_id, $wordset_id) : 0;

    $existing_terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'name'       => $name,
        'parent'     => $parent_id,
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
    if ($parent_id > 0) {
        $insert_args['parent'] = $parent_id;
    }

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

    $parent_id = 0;
    if ((int) $source_term->parent > 0) {
        $parent_id = ll_tools_get_or_create_isolated_category_copy((int) $source_term->parent, $wordset_id);
    }

    $insert = wp_insert_term($source_term->name, 'word-category', [
        'slug'        => ll_tools_build_isolated_category_slug((string) $source_term->slug, $wordset_id),
        'description' => (string) $source_term->description,
        'parent'      => $parent_id,
    ]);
    if (is_wp_error($insert)) {
        if ($insert->get_error_code() === 'term_exists') {
            $existing_id = (int) $insert->get_error_data('term_exists');
            if ($existing_id > 0) {
                ll_tools_set_category_wordset_owner($existing_id, $wordset_id, $source_origin_id);
                return $existing_id;
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

    return $term_id;
}

function ll_tools_get_isolated_category_ids_for_wordsets(array $category_ids, array $wordset_ids): array {
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
    $normalized = [];
    foreach ($category_ids as $category_id) {
        $owner_id = ll_tools_get_category_wordset_owner_id($category_id);
        if ($owner_id > 0 && isset($allowed_wordsets[$owner_id])) {
            $normalized[$category_id] = true;
            continue;
        }

        foreach ($wordset_ids as $wordset_id) {
            $copy_id = ll_tools_get_or_create_isolated_category_copy($category_id, (int) $wordset_id);
            if ($copy_id > 0) {
                $normalized[$copy_id] = true;
            }
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
        ll_tools_set_word_image_wordset_owner($existing_id, $wordset_id, $source_origin_id);

        $thumbnail_id = (int) get_post_thumbnail_id($source_image_id);
        if ($thumbnail_id > 0) {
            set_post_thumbnail($existing_id, $thumbnail_id);
        }

        $category_ids = wp_get_post_terms($source_image_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($category_ids) && !empty($category_ids)) {
            $isolated_category_ids = ll_tools_get_isolated_category_ids_for_wordsets(array_map('intval', (array) $category_ids), [$wordset_id]);
            if (!empty($isolated_category_ids)) {
                wp_set_object_terms($existing_id, $isolated_category_ids, 'word-category', false);
            }
        }

        ll_tools_normalize_word_image_categories_for_isolation($existing_id);
        return $existing_id;
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
    ll_tools_set_word_image_wordset_owner((int) $new_post_id, $wordset_id, $source_origin_id);

    $thumbnail_id = (int) get_post_thumbnail_id($source_image_id);
    if ($thumbnail_id > 0) {
        set_post_thumbnail((int) $new_post_id, $thumbnail_id);
    }

    $category_ids = wp_get_post_terms($source_image_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($category_ids) && !empty($category_ids)) {
        $isolated_category_ids = ll_tools_get_isolated_category_ids_for_wordsets(array_map('intval', (array) $category_ids), [$wordset_id]);
        if (!empty($isolated_category_ids)) {
            wp_set_object_terms((int) $new_post_id, $isolated_category_ids, 'word-category', false);
        }
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

function ll_tools_normalize_word_categories_for_isolation(int $word_id): array {
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
    $normalized_ids = ll_tools_get_isolated_category_ids_for_wordsets($category_ids, $wordset_ids);

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

    if ($post->post_type === 'words' && in_array($taxonomy, ['wordset', 'word-category'], true)) {
        ll_tools_normalize_word_categories_for_isolation($object_id);
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

function ll_tools_build_legacy_category_wordset_usage_map(array $word_ids): array {
    $usage = [];

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

        $source_category_ids = [];
        foreach ((array) $category_ids as $category_id) {
            $category_id = (int) $category_id;
            if ($category_id <= 0) {
                continue;
            }

            $source_category_id = ll_tools_get_category_isolation_source_id($category_id);
            if ($source_category_id <= 0) {
                continue;
            }

            $source_category_ids[$source_category_id] = true;
            foreach ((array) get_ancestors($source_category_id, 'word-category', 'taxonomy') as $ancestor_id) {
                $ancestor_id = (int) $ancestor_id;
                if ($ancestor_id > 0) {
                    $source_category_ids[$ancestor_id] = true;
                }
            }
        }

        foreach (array_keys($source_category_ids) as $source_category_id) {
            foreach ($wordset_ids as $wordset_id) {
                $usage[(int) $source_category_id][(int) $wordset_id] = true;
            }
        }
    }

    foreach ($usage as $category_id => $wordset_lookup) {
        $wordset_ids = array_values(array_map('intval', array_keys((array) $wordset_lookup)));
        sort($wordset_ids, SORT_NUMERIC);
        $usage[$category_id] = $wordset_ids;
    }

    return $usage;
}

function ll_tools_run_wordset_isolation_migration(): array {
    $result = [
        'words_scanned'       => 0,
        'words_updated'       => 0,
        'categories_created'  => 0,
        'images_created'      => 0,
        'images_relinked'     => 0,
        'lessons_repaired'    => 0,
        'wordsets_repaired'   => 0,
        'word_option_rule_scopes_repaired' => 0,
        'user_data_repaired'  => 0,
        'errors'              => [],
    ];

    $word_ids = get_posts([
        'post_type'        => 'words',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    if (empty($word_ids)) {
        return $result;
    }

    $legacy_category_wordset_usage = ll_tools_build_legacy_category_wordset_usage_map($word_ids);
    $legacy_word_image_ids = [];
    $created_category_ids = [];
    $created_image_ids = [];
    foreach ((array) $word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $result['words_scanned']++;
        $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
            ? ll_tools_get_post_wordset_ids($word_id)
            : [];
        if (empty($wordset_ids)) {
            continue;
        }

        $legacy_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        $legacy_category_ids = is_wp_error($legacy_category_ids)
            ? []
            : array_values(array_filter(array_map('intval', (array) $legacy_category_ids), static function (int $id): bool {
                return $id > 0;
            }));
        $legacy_word_image_ids[$word_id] = ll_tools_get_legacy_candidate_word_image_for_word($word_id, $legacy_category_ids);

        $before_category_ids = $legacy_category_ids;
        $normalized_category_ids = ll_tools_normalize_word_categories_for_isolation($word_id);
        foreach (array_diff($normalized_category_ids, $before_category_ids) as $new_category_id) {
            $new_category_id = (int) $new_category_id;
            if ($new_category_id > 0) {
                $created_category_ids[$new_category_id] = true;
            }
        }

        $before_sorted = $before_category_ids;
        sort($before_sorted, SORT_NUMERIC);
        $after_sorted = $normalized_category_ids;
        sort($after_sorted, SORT_NUMERIC);
        if ($before_sorted !== $after_sorted) {
            $result['words_updated']++;
        }
    }

    $word_image_ids = get_posts([
        'post_type'        => 'word_images',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    foreach ((array) $word_image_ids as $image_id) {
        $image_id = (int) $image_id;
        if ($image_id <= 0) {
            continue;
        }

        $owner_wordset_id = ll_tools_get_word_image_wordset_owner_id($image_id);
        if ($owner_wordset_id > 0) {
            ll_tools_normalize_word_image_categories_for_isolation($image_id);
            continue;
        }

        $source_origin_id = ll_tools_get_word_image_isolation_source_id($image_id);
        if ($source_origin_id !== $image_id) {
            continue;
        }

        $image_category_ids = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($image_category_ids) || empty($image_category_ids)) {
            continue;
        }

        $target_wordsets = [];
        foreach ((array) $image_category_ids as $category_id) {
            $source_category_id = ll_tools_get_category_isolation_source_id((int) $category_id);
            if ($source_category_id <= 0 || empty($legacy_category_wordset_usage[$source_category_id])) {
                continue;
            }

            foreach ((array) $legacy_category_wordset_usage[$source_category_id] as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                if ($wordset_id > 0) {
                    $target_wordsets[$wordset_id] = true;
                }
            }
        }

        foreach (array_keys($target_wordsets) as $wordset_id) {
            $before_copy_id = ll_tools_get_existing_isolated_word_image_copy_id($source_origin_id, (int) $wordset_id);
            $copy_id = ll_tools_get_or_create_isolated_word_image_copy($image_id, (int) $wordset_id);
            if ($copy_id > 0 && $before_copy_id <= 0 && ll_tools_get_word_image_wordset_owner_id($copy_id) === (int) $wordset_id) {
                $created_image_ids[(int) $copy_id] = true;
            }
        }
    }

    foreach ((array) $word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
            ? ll_tools_get_post_wordset_ids($word_id)
            : [];
        if (empty($wordset_ids)) {
            continue;
        }

        $thumbnail_id = (int) get_post_thumbnail_id($word_id);
        if ($thumbnail_id <= 0) {
            continue;
        }

        $primary_wordset_id = (int) ($wordset_ids[0] ?? 0);
        $legacy_image_id = max(0, (int) ($legacy_word_image_ids[$word_id] ?? 0));
        if ($legacy_image_id <= 0) {
            $ensured = function_exists('ll_tools_ensure_word_image_post_for_word')
                ? ll_tools_ensure_word_image_post_for_word($word_id)
                : 0;
            if (is_wp_error($ensured)) {
                $result['errors'][] = sprintf(
                    __('Word %1$d could not be linked to an isolated word image: %2$s', 'll-tools-text-domain'),
                    $word_id,
                    $ensured->get_error_message()
                );
                continue;
            }
            $legacy_image_id = (int) $ensured;
        }

        foreach ($wordset_ids as $wordset_id) {
            $before_copy_id = ll_tools_get_existing_isolated_word_image_copy_id(ll_tools_get_word_image_isolation_source_id($legacy_image_id), (int) $wordset_id);
            $copy_id = ll_tools_get_or_create_isolated_word_image_copy($legacy_image_id, (int) $wordset_id);
            if ($copy_id > 0 && $before_copy_id <= 0 && ll_tools_get_word_image_wordset_owner_id($copy_id) === (int) $wordset_id) {
                $created_image_ids[(int) $copy_id] = true;
            }
        }

        if ($primary_wordset_id > 0) {
            $effective_image_id = ll_tools_get_effective_word_image_id_for_wordset($legacy_image_id, $primary_wordset_id);
            if ($effective_image_id > 0 && $effective_image_id !== $legacy_image_id) {
                update_post_meta($word_id, '_ll_autopicked_image_id', $effective_image_id);
                $result['images_relinked']++;
            }
        }
    }

    $wordset_ids = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if (!is_wp_error($wordset_ids)) {
        foreach ((array) $wordset_ids as $wordset_id) {
            if (ll_tools_repair_wordset_category_ordering_meta_for_isolation((int) $wordset_id)) {
                $result['wordsets_repaired']++;
            }
        }
    }

    if (function_exists('ll_tools_repair_word_option_rules_store_for_isolation')) {
        $result['word_option_rule_scopes_repaired'] = (int) ll_tools_repair_word_option_rules_store_for_isolation(true);
    }

    if (defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')) {
        $lesson_ids = get_posts([
            'post_type'        => 'll_vocab_lesson',
            'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        foreach ((array) $lesson_ids as $lesson_id) {
            if (ll_tools_repair_vocab_lesson_category_meta_for_isolation((int) $lesson_id)) {
                $result['lessons_repaired']++;
            }
        }
    }

    global $wpdb;
    $user_meta_keys = array_values(array_filter([
        defined('LL_TOOLS_USER_CATEGORY_META') ? LL_TOOLS_USER_CATEGORY_META : '',
        defined('LL_TOOLS_USER_GOALS_META') ? LL_TOOLS_USER_GOALS_META : '',
        defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') ? LL_TOOLS_USER_CATEGORY_PROGRESS_META : '',
        defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') ? LL_TOOLS_USER_RECOMMENDATION_QUEUE_META : '',
        defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') ? LL_TOOLS_USER_LAST_RECOMMENDATION_META : '',
    ], static function (string $meta_key): bool {
        return $meta_key !== '';
    }));
    if (!empty($user_meta_keys)) {
        $placeholders = implode(', ', array_fill(0, count($user_meta_keys), '%s'));
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
            $user_meta_keys
        ));

        foreach ((array) $user_ids as $user_id) {
            $user_id = (int) $user_id;
            if ($user_id <= 0) {
                continue;
            }

            if (defined('LL_TOOLS_USER_CATEGORY_META') && function_exists('ll_tools_get_user_study_state')) {
                $state_before = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true);
                ll_tools_get_user_study_state($user_id);
                if ($state_before !== get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true)) {
                    $result['user_data_repaired']++;
                }
            }

            if (defined('LL_TOOLS_USER_GOALS_META') && function_exists('ll_tools_get_user_study_goals')) {
                $goals_before = get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true);
                ll_tools_get_user_study_goals($user_id);
                if ($goals_before !== get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true)) {
                    $result['user_data_repaired']++;
                }
            }

            if (defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') && function_exists('ll_tools_get_user_category_progress')) {
                $progress_before = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
                ll_tools_get_user_category_progress($user_id);
                if ($progress_before !== get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true)) {
                    $result['user_data_repaired']++;
                }
            }

            if (defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') && function_exists('ll_tools_get_user_recommendation_queue')) {
                $queue_before = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
                foreach (array_keys(is_array($queue_before) ? $queue_before : []) as $raw_wordset_id) {
                    ll_tools_get_user_recommendation_queue($user_id, (int) $raw_wordset_id);
                }
                if ($queue_before !== get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true)) {
                    $result['user_data_repaired']++;
                }
            }

            if (defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') && function_exists('ll_tools_get_user_last_recommendation_activity')) {
                $last_before = get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true);
                foreach (array_keys(is_array($last_before) ? $last_before : []) as $raw_wordset_id) {
                    ll_tools_get_user_last_recommendation_activity($user_id, (int) $raw_wordset_id);
                }
                if ($last_before !== get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true)) {
                    $result['user_data_repaired']++;
                }
            }
        }
    }

    $result['categories_created'] = count($created_category_ids);
    $result['images_created'] = count($created_image_ids);

    if (function_exists('ll_tools_bump_category_cache_epoch')) {
        ll_tools_bump_category_cache_epoch();
    }
    if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
        ll_tools_bump_wordset_cache_epoch();
    }

    if (function_exists('ll_tools_invalidate_wordset_isolation_health_report')) {
        ll_tools_invalidate_wordset_isolation_health_report();
    }

    return $result;
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

    $result = ll_tools_run_wordset_isolation_migration();
    update_option(
        LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION,
        LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION,
        false
    );
    set_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT, $result, DAY_IN_SECONDS);
}
add_action('admin_init', 'll_tools_maybe_run_wordset_isolation_migration', 5);

function ll_tools_render_wordset_isolation_migration_notice(): void {
    if (!current_user_can('manage_options')) {
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

    $report = ll_tools_get_wordset_isolation_health_report();
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
    echo '</div>';
}
add_action('admin_notices', 'll_tools_render_wordset_isolation_health_notice', 6);
