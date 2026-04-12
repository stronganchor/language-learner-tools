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
if (!defined('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION')) {
    define('LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION', 1);
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

    if ($post->post_type === 'words' && in_array($taxonomy, ['wordset', 'word-category'], true)) {
        ll_tools_normalize_word_categories_for_isolation($object_id);
    } elseif ($post->post_type === 'word_images' && $taxonomy === 'word-category') {
        ll_tools_normalize_word_image_categories_for_isolation($object_id);
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

    $result['categories_created'] = count($created_category_ids);
    $result['images_created'] = count($created_image_ids);

    if (function_exists('ll_tools_bump_category_cache_epoch')) {
        ll_tools_bump_category_cache_epoch();
    }
    if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
        ll_tools_bump_wordset_cache_epoch();
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
            __('Wordset isolation migration completed. Words scanned: %1$d. Words updated: %2$d. Categories created: %3$d. Word images created: %4$d. Word-image links updated: %5$d.', 'll-tools-text-domain'),
            (int) ($result['words_scanned'] ?? 0),
            (int) ($result['words_updated'] ?? 0),
            (int) ($result['categories_created'] ?? 0),
            (int) ($result['images_created'] ?? 0),
            (int) ($result['images_relinked'] ?? 0)
        ))
    );
}
add_action('admin_notices', 'll_tools_render_wordset_isolation_migration_notice');
