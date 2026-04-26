<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY')) {
    define('LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY', 'll_wordset_keep_original_audio');
}
if (!defined('LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY')) {
    define('LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY', '_ll_original_audio_file_path');
}
if (!defined('LL_TOOLS_ORIGINAL_AUDIO_SOURCE_META_KEY')) {
    define('LL_TOOLS_ORIGINAL_AUDIO_SOURCE_META_KEY', '_ll_original_audio_source');
}
if (!defined('LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY')) {
    define('LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY', '_ll_audio_processing_settings');
}

function ll_tools_should_keep_original_audio_for_wordset($wordset): bool {
    $wordset_id = function_exists('ll_tools_resolve_wordset_term_id')
        ? ll_tools_resolve_wordset_term_id($wordset)
        : (is_numeric($wordset) ? (int) $wordset : 0);
    if ($wordset_id <= 0) {
        return false;
    }

    if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY)) {
        return false;
    }

    $raw = get_term_meta($wordset_id, LL_TOOLS_WORDSET_KEEP_ORIGINAL_AUDIO_META_KEY, true);
    if (function_exists('ll_tools_normalize_wordset_boolean_setting')) {
        return ll_tools_normalize_wordset_boolean_setting($raw) === 1;
    }

    return absint($raw) === 1;
}

function ll_tools_should_keep_original_audio_for_wordsets($wordset_ids): bool {
    $ids = function_exists('ll_tools_normalize_wordset_setting_ids')
        ? ll_tools_normalize_wordset_setting_ids($wordset_ids)
        : array_values(array_filter(array_map('intval', (array) $wordset_ids), static function (int $id): bool {
            return $id > 0;
        }));

    foreach ($ids as $wordset_id) {
        if (ll_tools_should_keep_original_audio_for_wordset((int) $wordset_id)) {
            return true;
        }
    }

    return false;
}

function ll_tools_get_audio_post_wordset_ids(int $audio_post_id): array {
    $parent_word_id = (int) wp_get_post_parent_id($audio_post_id);
    if ($parent_word_id <= 0) {
        return [];
    }

    if (function_exists('ll_tools_get_post_wordset_ids')) {
        return ll_tools_get_post_wordset_ids($parent_word_id);
    }

    $term_ids = wp_get_post_terms($parent_word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || !is_array($term_ids)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $term_ids), static function (int $id): bool {
        return $id > 0;
    }));
}

function ll_tools_should_keep_original_audio_for_audio_post(int $audio_post_id, array $wordset_ids = []): bool {
    if ($audio_post_id <= 0) {
        return false;
    }

    $ids = !empty($wordset_ids) ? $wordset_ids : ll_tools_get_audio_post_wordset_ids($audio_post_id);
    return ll_tools_should_keep_original_audio_for_wordsets($ids);
}

function ll_tools_get_audio_original_file_path(int $audio_post_id): string {
    if ($audio_post_id <= 0) {
        return '';
    }

    return trim((string) get_post_meta($audio_post_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, true));
}

function ll_tools_audio_relative_file_exists(string $relative_path): bool {
    $relative_path = trim($relative_path);
    if ($relative_path === '') {
        return false;
    }

    $full_path = wp_normalize_path(ABSPATH . ltrim($relative_path, "/\\"));
    return $full_path !== '' && file_exists($full_path);
}

function ll_tools_get_audio_processing_source_file_path(int $audio_post_id, string $fallback_path = ''): string {
    $original_path = ll_tools_get_audio_original_file_path($audio_post_id);
    if ($original_path !== '' && ll_tools_audio_relative_file_exists($original_path)) {
        return $original_path;
    }

    return trim($fallback_path);
}

function ll_tools_store_original_audio_file_path(int $audio_post_id, string $relative_path, string $source = '', bool $overwrite = false): bool {
    $audio_post_id = (int) $audio_post_id;
    $relative_path = trim($relative_path);
    if ($audio_post_id <= 0 || $relative_path === '') {
        return false;
    }

    $existing = ll_tools_get_audio_original_file_path($audio_post_id);
    if ($existing !== '' && !$overwrite) {
        return false;
    }

    update_post_meta($audio_post_id, LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY, $relative_path);
    if ($source !== '') {
        update_post_meta($audio_post_id, LL_TOOLS_ORIGINAL_AUDIO_SOURCE_META_KEY, sanitize_key($source));
    }

    return true;
}

function ll_tools_store_original_audio_if_enabled(int $audio_post_id, string $relative_path, array $wordset_ids = [], string $source = ''): bool {
    if (!ll_tools_should_keep_original_audio_for_audio_post($audio_post_id, $wordset_ids)) {
        return false;
    }

    return ll_tools_store_original_audio_file_path($audio_post_id, $relative_path, $source, false);
}
