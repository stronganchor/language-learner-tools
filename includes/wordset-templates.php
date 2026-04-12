<?php
if (!defined('WPINC')) { die; }

function ll_tools_get_wordset_template_category_ids(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $category_ids = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'ids',
        'meta_query' => [
            [
                'key'   => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                'value' => $wordset_id,
            ],
        ],
    ]);

    if (is_wp_error($category_ids)) {
        $category_ids = [];
    }

    $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), static function (int $category_id): bool {
        return $category_id > 0;
    }));

    if (empty($category_ids)) {
        return [];
    }

    if (function_exists('ll_tools_wordset_sort_category_ids')) {
        return ll_tools_wordset_sort_category_ids($category_ids, $wordset_id);
    }

    sort($category_ids, SORT_NUMERIC);
    return $category_ids;
}

function ll_tools_get_wordset_template_word_image_ids(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $image_ids = get_posts([
        'post_type'        => 'word_images',
        'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'meta_query'       => [
            [
                'key'   => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
                'value' => $wordset_id,
            ],
        ],
    ]);

    if (empty($image_ids)) {
        return [];
    }

    $image_ids = array_values(array_filter(array_map('intval', (array) $image_ids), static function (int $image_id): bool {
        return $image_id > 0;
    }));
    sort($image_ids, SORT_NUMERIC);

    return $image_ids;
}

function ll_tools_wordset_template_copy_term_meta_values(int $source_term_id, int $target_term_id, string $meta_key): void {
    $source_term_id = (int) $source_term_id;
    $target_term_id = (int) $target_term_id;
    $meta_key = (string) $meta_key;
    if ($source_term_id <= 0 || $target_term_id <= 0 || $meta_key === '') {
        return;
    }

    delete_term_meta($target_term_id, $meta_key);

    $values = get_term_meta($source_term_id, $meta_key, false);
    if (empty($values)) {
        return;
    }

    foreach ((array) $values as $value) {
        add_term_meta($target_term_id, $meta_key, maybe_unserialize($value));
    }
}

function ll_tools_wordset_template_parse_id_list($raw_value): array {
    if (function_exists('ll_tools_wordset_parse_id_list_meta')) {
        return ll_tools_wordset_parse_id_list_meta($raw_value);
    }

    if (is_array($raw_value)) {
        $ids = array_map('intval', $raw_value);
    } else {
        $ids = preg_split('/\s*,\s*/', trim((string) $raw_value));
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
    }

    $normalized = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $normalized[$id] = true;
        }
    }

    return array_values(array_map('intval', array_keys($normalized)));
}

function ll_tools_wordset_template_remap_category_id_list($raw_value, array $category_id_map): array {
    $source_ids = ll_tools_wordset_template_parse_id_list($raw_value);
    if (empty($source_ids) || empty($category_id_map)) {
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

function ll_tools_wordset_template_remap_prerequisite_map($raw_map, array $category_id_map): array {
    if (empty($category_id_map)) {
        return [];
    }

    $source_category_ids = array_values(array_filter(array_map('intval', array_keys($category_id_map)), static function (int $category_id): bool {
        return $category_id > 0;
    }));
    if (empty($source_category_ids)) {
        return [];
    }

    if (function_exists('ll_tools_wordset_normalize_category_prereq_map')) {
        $normalized_map = ll_tools_wordset_normalize_category_prereq_map($raw_map, $source_category_ids);
    } else {
        $normalized_map = is_array($raw_map) ? $raw_map : [];
    }

    $remapped = [];
    foreach ($normalized_map as $source_category_id => $source_dependencies) {
        $source_category_id = (int) $source_category_id;
        $target_category_id = (int) ($category_id_map[$source_category_id] ?? 0);
        if ($target_category_id <= 0) {
            continue;
        }

        $target_dependencies = ll_tools_wordset_template_remap_category_id_list($source_dependencies, $category_id_map);
        if (!empty($target_dependencies)) {
            $remapped[$target_category_id] = $target_dependencies;
        }
    }

    ksort($remapped, SORT_NUMERIC);
    return $remapped;
}

function ll_tools_get_wordset_template_setting_meta_keys(): array {
    $keys = [
        'll_wordset_category_ordering_mode',
        'll_wordset_category_manual_order',
        'll_wordset_category_prerequisites',
        'll_wordset_hide_lesson_text_for_non_text_quiz',
    ];

    $constant_names = [
        'LL_TOOLS_WORDSET_VISIBILITY_META_KEY',
        'LL_TOOLS_WORDSET_AUTOPLAY_TEXT_AUDIO_ANSWER_OPTIONS_META_KEY',
        'LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY',
        'LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY',
        'LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY',
        'LL_TOOLS_WORDSET_TRANSCRIPTION_API_TOKEN_META_KEY',
        'LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY',
        'LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY',
        'LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY',
        'LL_TOOLS_WORDSET_SPEAKING_GAME_ACCESS_META_KEY',
        'LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY',
        'LL_TOOLS_WORDSET_SPEAKING_GAME_ASSEMBLYAI_PROFILE_META_KEY',
    ];

    foreach ($constant_names as $constant_name) {
        if (defined($constant_name)) {
            $keys[] = (string) constant($constant_name);
        }
    }

    return array_values(array_filter(array_unique(array_map('strval', $keys)), static function (string $key): bool {
        return $key !== '';
    }));
}

function ll_tools_copy_wordset_template_settings(int $source_wordset_id, int $target_wordset_id, array $category_id_map): void {
    $source_wordset_id = (int) $source_wordset_id;
    $target_wordset_id = (int) $target_wordset_id;
    if ($source_wordset_id <= 0 || $target_wordset_id <= 0) {
        return;
    }

    $manual_order_meta_key = 'll_wordset_category_manual_order';
    $prereq_meta_key = 'll_wordset_category_prerequisites';
    $exclude_keys = [
        $manual_order_meta_key,
        $prereq_meta_key,
    ];

    foreach (ll_tools_get_wordset_template_setting_meta_keys() as $meta_key) {
        if ($meta_key === '' || in_array($meta_key, $exclude_keys, true)) {
            continue;
        }

        ll_tools_wordset_template_copy_term_meta_values($source_wordset_id, $target_wordset_id, $meta_key);
    }

    $manual_order = ll_tools_wordset_template_remap_category_id_list(
        get_term_meta($source_wordset_id, $manual_order_meta_key, true),
        $category_id_map
    );
    if (empty($manual_order)) {
        delete_term_meta($target_wordset_id, $manual_order_meta_key);
    } else {
        update_term_meta($target_wordset_id, $manual_order_meta_key, $manual_order);
    }

    $prereq_map = ll_tools_wordset_template_remap_prerequisite_map(
        get_term_meta($source_wordset_id, $prereq_meta_key, true),
        $category_id_map
    );
    if (empty($prereq_map)) {
        delete_term_meta($target_wordset_id, $prereq_meta_key);
    } else {
        update_term_meta($target_wordset_id, $prereq_meta_key, $prereq_map);
    }
}

function ll_tools_create_wordset_from_template(int $source_wordset_id, array $args = []) {
    $source_wordset_id = (int) $source_wordset_id;
    $source_wordset = get_term($source_wordset_id, 'wordset');
    if (!($source_wordset instanceof WP_Term) || is_wp_error($source_wordset)) {
        return new WP_Error('ll_wordset_template_missing_source', __('Unable to find that template word set.', 'll-tools-text-domain'));
    }

    $name = isset($args['name']) ? sanitize_text_field((string) $args['name']) : '';
    $name = trim($name);
    if ($name === '') {
        return new WP_Error('ll_wordset_template_missing_name', __('Enter a name for the new word set.', 'll-tools-text-domain'));
    }

    $slug = isset($args['slug']) ? sanitize_title((string) $args['slug']) : '';
    $manager_user_id = isset($args['manager_user_id']) ? max(0, (int) $args['manager_user_id']) : max(0, (int) get_current_user_id());
    $copy_settings = !empty($args['copy_settings']);

    $insert_args = [];
    if ($slug !== '') {
        $insert_args['slug'] = $slug;
    }

    $inserted = wp_insert_term($name, 'wordset', $insert_args);
    if (is_wp_error($inserted)) {
        return $inserted;
    }

    $target_wordset_id = (int) ($inserted['term_id'] ?? 0);
    if ($target_wordset_id <= 0) {
        return new WP_Error('ll_wordset_template_create_failed', __('Unable to create the new word set right now.', 'll-tools-text-domain'));
    }

    if ($manager_user_id > 0) {
        update_term_meta($target_wordset_id, 'manager_user_id', $manager_user_id);
    }

    $category_id_map = [];
    $failed_categories = 0;
    foreach (ll_tools_get_wordset_template_category_ids($source_wordset_id) as $source_category_id) {
        $target_category_id = ll_tools_get_or_create_isolated_category_copy((int) $source_category_id, $target_wordset_id);
        if ($target_category_id <= 0) {
            $failed_categories++;
            continue;
        }

        $category_id_map[(int) $source_category_id] = (int) $target_category_id;
    }

    if ($copy_settings) {
        ll_tools_copy_wordset_template_settings($source_wordset_id, $target_wordset_id, $category_id_map);
    }

    $created_image_ids = [];
    $failed_images = 0;
    foreach (ll_tools_get_wordset_template_word_image_ids($source_wordset_id) as $source_image_id) {
        $target_image_id = ll_tools_get_or_create_isolated_word_image_copy((int) $source_image_id, $target_wordset_id);
        if ($target_image_id <= 0) {
            $failed_images++;
            continue;
        }

        $created_image_ids[(int) $target_image_id] = true;
    }

    $target_wordset = get_term($target_wordset_id, 'wordset');

    return [
        'wordset_id' => $target_wordset_id,
        'wordset_term' => ($target_wordset instanceof WP_Term && !is_wp_error($target_wordset)) ? $target_wordset : null,
        'categories_created' => count($category_id_map),
        'images_created' => count($created_image_ids),
        'failed_categories' => $failed_categories,
        'failed_images' => $failed_images,
        'copied_settings' => $copy_settings ? 1 : 0,
        'category_id_map' => $category_id_map,
    ];
}
