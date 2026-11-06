<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Identify archive entries permitted before a delegated ZIP is ever extracted. */
function ll_tools_scoped_import_entry_kind(string $name): string {
    if (strlen($name) > 240) {
        return '';
    }

    $directory = substr($name, -1) === '/';
    $path = $directory ? substr($name, 0, -1) : $name;
    $parts = explode('/', $path);
    $basename = $directory ? '' : array_pop($parts);
    foreach ($parts as $part) {
        if (trim($part) !== $part || !preg_match('/^[\p{L}\p{M}\p{N}_ -]+$/uD', $part)) {
            return '';
        }
    }
    if ($directory) {
        return 'directory';
    }
    if ($basename === 'data.json' && empty($parts)) {
        return 'json';
    }
    if (!preg_match('/^([\p{L}\p{M}\p{N}_ -]+)\.([a-zA-Z0-9]+)$/uD', $basename, $matches)
        || trim($matches[1]) !== $matches[1]
    ) {
        return '';
    }

    $extension = strtolower($matches[2]);
    if ($extension === 'csv' && empty($parts)) {
        return 'csv';
    }
    if (in_array($extension, ['webp', 'png', 'jpg', 'jpeg', 'gif', 'avif'], true)) {
        return 'image';
    }
    if (in_array($extension, ['mp3', 'wav', 'aac', 'm4a', 'mp4', 'ogg', 'oga', 'opus', 'webm'], true)) {
        return 'audio';
    }

    return '';
}

function ll_tools_scoped_import_validate_zip(string $path) {
    $invalid = static function (): WP_Error {
        return new WP_Error(
            'll_tools_scoped_import_invalid_zip',
            __('Upload a ZIP containing data.json or top-level quiz CSV files and bundled images or audio. Executables, links, duplicate paths and oversized archives are not allowed.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    };

    $size = is_file($path) ? filesize($path) : false;
    if (!class_exists('ZipArchive') || $size === false || $size < 1 || $size > 64 * MB_IN_BYTES) {
        return $invalid();
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
        return $invalid();
    }

    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 1500) {
            return $invalid();
        }

        $entries = [];
        $parents = [];
        $expanded_bytes = 0;
        $has_json = false;
        $has_csv = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (!is_array($entry) || !isset($entry['name'], $entry['size'])) {
                return $invalid();
            }

            $name = (string) $entry['name'];
            $kind = ll_tools_scoped_import_entry_kind($name);
            $key = strtolower(rtrim($name, '/'));
            if ($kind === '' || isset($entries[$key])) {
                return $invalid();
            }
            $entries[$key] = $kind;

            $prefix = dirname($key);
            while ($prefix !== '.') {
                $parents[$prefix] = true;
                $prefix = dirname($prefix);
            }

            if (!$zip->getExternalAttributesIndex($index, $platform, $attributes)) {
                return $invalid();
            }
            $mode = ($attributes >> 16) & 0170000;
            $expected_mode = $kind === 'directory' ? 0040000 : 0100000;
            if (($mode !== 0 && $mode !== $expected_mode) || !empty($entry['encryption_method'])) {
                return $invalid();
            }

            $entry_size = (int) $entry['size'];
            $max_entry = in_array($kind, ['json', 'csv'], true) ? 4 * MB_IN_BYTES : 24 * MB_IN_BYTES;
            $expanded_bytes += $entry_size;
            if ($entry_size < 0 || $entry_size > $max_entry || $expanded_bytes > 128 * MB_IN_BYTES
                || ($kind === 'directory' && $entry_size !== 0)
            ) {
                return $invalid();
            }

            $has_json = $has_json || $kind === 'json';
            $has_csv = $has_csv || $kind === 'csv';
        }

        foreach (array_keys($parents) as $parent) {
            if (isset($entries[$parent]) && $entries[$parent] !== 'directory') {
                return $invalid();
            }
        }

        return $has_json !== $has_csv ? true : $invalid();
    } finally {
        $zip->close();
    }
}

function ll_tools_scoped_import_text($value, int $max = 8192): string {
    if (!is_scalar($value) || strlen((string) $value) > $max) {
        return '';
    }

    // Keep imported text inert even in content rendered through do_shortcode().
    return strtr(sanitize_textarea_field((string) $value), ['[' => '&#91;', ']' => '&#93;']);
}

function ll_tools_scoped_import_meta($meta, array $allowed): array {
    if (!is_array($meta)) {
        return [];
    }

    $result = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $meta)) {
            continue;
        }
        $values = (array) $meta[$key];
        if ($values === []) {
            $result[$key] = [];
        } elseif (count($values) === 1 && is_scalar(reset($values))) {
            $value = reset($values);
            if (strlen((string) $value) > 8192) {
                continue;
            }
            $decoded = ll_tools_import_decode_legacy_meta_value($value);
            if (is_scalar($decoded) && strlen((string) $decoded) <= 8192) {
                $clean = substr($key, -4) === '_url'
                    ? esc_url_raw((string) $decoded, ['https', 'http'])
                    : ll_tools_scoped_import_text($decoded);
                if (!is_serialized($clean)) {
                    $result[$key] = [$clean];
                }
            }
        }
    }

    return $result;
}

function ll_tools_scoped_import_media($media, string $kind, string $extract_dir, array &$verified) {
    if (empty($media)) {
        return [];
    }
    $path = is_array($media) && is_string($media['file'] ?? null) ? $media['file'] : '';
    if (ll_tools_scoped_import_entry_kind($path) !== $kind) {
        return new WP_Error(
            'll_tools_scoped_import_media_invalid',
            __('Delegated imports require image and audio files from the uploaded ZIP.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    }

    if ($extract_dir !== '' && !isset($verified[$path])) {
        $base = realpath($extract_dir);
        $absolute = realpath($extract_dir . DIRECTORY_SEPARATOR . $path);
        if ($base === false || $absolute === false || strpos($absolute, $base . DIRECTORY_SEPARATOR) !== 0
            || !is_file($absolute) || !ll_tools_scoped_import_valid_media_file($absolute, $kind)
        ) {
            return new WP_Error(
                'll_tools_scoped_import_media_invalid',
                sprintf(__('Missing or unsupported media in the ZIP: %s. Images must be no larger than 4096 pixels on either side and 12 megapixels.', 'll-tools-text-domain'), $path),
                ['status' => 400]
            );
        }
        $verified[$path] = max(0, (int) filesize($absolute));
    }

    return [
        'file' => $media['file'],
        'title' => ll_tools_scoped_import_text($media['title'] ?? ''),
        'alt' => ll_tools_scoped_import_text($media['alt'] ?? ''),
    ];
}

function ll_tools_scoped_import_valid_media_file(string $path, string $kind): bool {
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($kind === 'image') {
        $dimensions = wp_getimagesize($path);
        if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])
            || max($dimensions[0], $dimensions[1]) > 4096 || $dimensions[0] * $dimensions[1] > 12000000
        ) {
            return false;
        }
        $expected = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/' . $extension;
        return ($dimensions['mime'] ?? '') === $expected && in_array($expected, get_allowed_mime_types(), true);
    }

    $allowed = ll_tools_get_allowed_recording_upload_mimes();
    if (!isset($allowed[$extension]) || !function_exists('finfo_open')) {
        return false;
    }
    $probe = finfo_open(FILEINFO_MIME_TYPE);
    if ($probe === false) {
        return false;
    }
    $mime = finfo_file($probe, $path);
    finfo_close($probe);
    return is_string($mime) && in_array($mime, explode('|', $allowed[$extension]), true);
}

function ll_tools_scoped_import_slug($value): string {
    $slug = is_string($value) && strlen($value) <= 200 ? sanitize_title($value) : '';
    return strlen($slug) <= 200 ? $slug : '';
}

function ll_tools_scoped_import_status($status, string $default): string {
    return ll_tools_sanitize_import_post_status(is_string($status) ? $status : '', $default);
}

function ll_tools_scoped_import_existing_taxonomy_refs($values, string $taxonomy): array {
    $slugs = [];
    foreach (array_slice((array) $values, 0, 16) as $value) {
        $slug = ll_tools_scoped_import_slug($value);
        if ($slug !== '' && get_term_by('slug', $slug, $taxonomy) instanceof WP_Term) {
            $slugs[$slug] = $slug;
        }
    }
    return array_values($slugs);
}

function ll_tools_scoped_import_category_refs($values, array $categories): array {
    $refs = [];
    foreach ((array) $values as $value) {
        $slug = ll_tools_scoped_import_slug($value);
        if ($slug === '' || !isset($categories[$slug])) {
            return [];
        }
        $refs[$slug] = $slug;
    }
    return array_values($refs);
}

/** Keep only fields that can be applied to the selected wordset. */
function ll_tools_scoped_import_sanitize_payload(array $payload, int $target_wordset_id, string $extract_dir = '') {
    $invalid = static function (): WP_Error {
        return new WP_Error(
            'll_tools_scoped_import_invalid_payload',
            __('Import a full category bundle with unique slugs and words assigned to categories in the bundle.', 'll-tools-text-domain'),
            ['status' => 400]
        );
    };

    if ($target_wordset_id <= 0 || ($payload['bundle_type'] ?? '') !== 'category_full'
        || empty($payload['categories']) || !is_array($payload['categories']) || count($payload['categories']) > 100
        || empty($payload['words']) || !is_array($payload['words']) || count($payload['words']) > 1000
        || !is_array($payload['word_images'] ?? null) || count($payload['word_images']) > 1000
    ) {
        return $invalid();
    }

    $categories = [];
    foreach ($payload['categories'] as $category) {
        $slug = ll_tools_scoped_import_slug($category['slug'] ?? null);
        if ($slug === '' || isset($categories[$slug])) {
            return $invalid();
        }
        $categories[$slug] = [
            'slug' => $slug,
            'name' => ll_tools_scoped_import_text($category['name'] ?? $slug),
            'description' => ll_tools_scoped_import_text($category['description'] ?? ''),
            'meta' => ll_tools_scoped_import_meta($category['meta'] ?? [], [
                'll_quiz_prompt_type',
                'll_quiz_option_type',
                'll_lesson_grid_text_visibility_override',
                'll_category_visibility',
            ]),
        ];
    }

    $images = [];
    $verified_media = [];
    foreach ($payload['word_images'] as $image) {
        $slug = ll_tools_scoped_import_slug($image['slug'] ?? null);
        $refs = ll_tools_scoped_import_category_refs($image['categories'] ?? [], $categories);
        if ($slug === '' || isset($images[$slug]) || empty($refs)) {
            return $invalid();
        }
        $media = ll_tools_scoped_import_media($image['featured_image'] ?? [], 'image', $extract_dir, $verified_media);
        if (is_wp_error($media)) {
            return $media;
        }
        $images[$slug] = [
            'slug' => $slug,
            'title' => ll_tools_scoped_import_text($image['title'] ?? $slug),
            'status' => ll_tools_scoped_import_status($image['status'] ?? 'publish', 'publish'),
            'categories' => $refs,
            'featured_image' => $media,
        ];
    }

    $words = [];
    $audio_count = 0;
    foreach ($payload['words'] as $word) {
        $slug = ll_tools_scoped_import_slug($word['slug'] ?? null);
        $refs = ll_tools_scoped_import_category_refs($word['categories'] ?? [], $categories);
        $image_link = ll_tools_scoped_import_slug($word['linked_word_image_slug'] ?? null);
        if ($slug === '' || isset($words[$slug]) || empty($refs) || ($image_link !== '' && !isset($images[$image_link]))) {
            return $invalid();
        }
        $media = ll_tools_scoped_import_media($word['featured_image'] ?? [], 'image', $extract_dir, $verified_media);
        if (is_wp_error($media)) {
            return $media;
        }

        $recordings = [];
        foreach ((array) ($word['audio_entries'] ?? []) as $audio) {
            $audio_slug = ll_tools_scoped_import_slug($audio['slug'] ?? null);
            if ($audio_slug === '' || isset($recordings[$audio_slug]) || ++$audio_count > 1500) {
                return $invalid();
            }
            $audio_file = ll_tools_scoped_import_media($audio['audio_file'] ?? [], 'audio', $extract_dir, $verified_media);
            if (is_wp_error($audio_file)) {
                return $audio_file;
            }
            if (empty($audio_file)) {
                return $invalid();
            }
            $recordings[$audio_slug] = [
                'slug' => $audio_slug,
                'title' => ll_tools_scoped_import_text($audio['title'] ?? $audio_slug),
                'status' => ll_tools_scoped_import_status($audio['status'] ?? 'draft', 'draft'),
                'meta' => ll_tools_scoped_import_meta($audio['meta'] ?? [], [
                    'recording_text', 'recording_translation', 'recording_ipa', 'speaker_name',
                    'audio_credit', 'audio_license', 'audio_license_url', 'audio_source_name', 'audio_source_url', 'audio_change_note',
                ]),
                'recording_types' => ll_tools_scoped_import_existing_taxonomy_refs($audio['recording_types'] ?? [], 'recording_type'),
                'audio_file' => $audio_file,
            ];
        }

        $words[$slug] = [
            'slug' => $slug,
            'title' => ll_tools_scoped_import_text($word['title'] ?? $slug),
            'content' => ll_tools_scoped_import_text($word['content'] ?? ''),
            'excerpt' => ll_tools_scoped_import_text($word['excerpt'] ?? ''),
            'status' => ll_tools_scoped_import_status($word['status'] ?? 'draft', 'draft'),
            'meta' => ll_tools_scoped_import_meta($word['meta'] ?? [], [
                'word_translation', 'word_english_meaning', 'word_example_sentence',
                'word_example_sentence_translation', 'll_word_usage_note', 'll_grammatical_gender',
                'll_grammatical_plurality', 'll_grammatical_person', 'll_verb_tense', 'll_verb_mood',
            ]),
            'categories' => $refs,
            'languages' => ll_tools_scoped_import_existing_taxonomy_refs($word['languages'] ?? [], 'language'),
            'parts_of_speech' => ll_tools_scoped_import_existing_taxonomy_refs($word['parts_of_speech'] ?? [], 'part_of_speech'),
            'linked_word_image_slug' => $image_link,
            'featured_image' => $media,
            'audio_entries' => array_values($recordings),
            'specific_wrong_answer_texts' => array_values(array_filter(
                array_map('ll_tools_scoped_import_text', array_slice((array) ($word['specific_wrong_answer_texts'] ?? []), 0, 32)),
                static function (string $text): bool { return $text !== ''; }
            )),
            'preferred_wrong_image_files' => array_values(array_filter(
                array_slice((array) ($word['preferred_wrong_image_files'] ?? []), 0, 32),
                static function ($file): bool { return is_string($file) && ll_tools_scoped_import_entry_kind($file) === 'image'; }
            )),
        ];
    }

    $clean = [
        'bundle_type' => 'category_full',
        'categories' => array_values($categories),
        'words' => array_values($words),
        'word_images' => array_values($images),
        'wordsets' => [],
        'media_estimate' => [
            'attachment_count' => count($verified_media),
            'attachment_bytes' => array_sum($verified_media),
        ],
    ];
    // Keep warnings from the CSV parser, but do not trust native bundle metadata as job diagnostics.
    if ($extract_dir !== '' && !is_file($extract_dir . DIRECTORY_SEPARATOR . 'data.json')
        && ($payload['source_format'] ?? '') === 'external_csv_mappings'
    ) {
        $clean['import_warnings'] = array_values(array_filter(array_map(
            'll_tools_scoped_import_text',
            array_slice((array) ($payload['import_warnings'] ?? []), 0, 32)
        )));
        $clean['import_parser_summary'] = (array) ($payload['import_parser_summary'] ?? []);
    }
    return $clean;
}

/** Add distractor groups without replacing existing rules or repairing other wordsets. */
function ll_tools_scoped_import_append_image_groups(int $wordset_id, int $category_id, array $components): bool {
    global $wpdb;

    if ($components === []) {
        return true;
    }
    $category = get_term($category_id, 'word-category');
    if (!$category instanceof WP_Term || !ll_tools_import_scoped_category_matches($category, $wordset_id)) {
        return false;
    }
    $word_ids = array_unique(array_merge(...$components));
    foreach ($word_ids as $word_id) {
        $word = get_post($word_id);
        if (!$word instanceof WP_Post || $word->post_type !== 'words'
            || !ll_tools_import_scoped_word_matches($word, $wordset_id)
            || !has_term($category_id, 'word-category', $word_id)
        ) {
            return false;
        }
    }

    $option = 'll_tools_word_option_rules';
    $stored = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, HEX(option_value) AS option_value_hex FROM {$wpdb->options} WHERE option_name = %s",
        $option
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        return false;
    }
    $rules = $stored === null ? [] : maybe_unserialize($stored['option_value']);
    if (!is_array($rules) || !is_array($rules[$wordset_id] ?? [])
        || !is_array($rules[$wordset_id][$category_id] ?? [])
        || !is_array($rules[$wordset_id][$category_id]['groups'] ?? [])
    ) {
        return false;
    }

    $existing = $rules[$wordset_id][$category_id]['groups'] ?? [];
    $known_members = [];
    $used_labels = [];
    foreach (ll_tools_normalize_word_option_rules(['groups' => $existing])['groups'] as $group) {
        $members = $group['word_ids'];
        sort($members, SORT_NUMERIC);
        $known_members[implode(',', $members)] = true;
        $used_labels[$group['label']] = true;
    }

    $new_groups = [];
    $index = 1;
    $prefix = ll_tools_import_csv_image_option_group_label_prefix();
    foreach ($components as $members) {
        if (isset($known_members[implode(',', $members)])) {
            continue;
        }
        while (isset($used_labels[$prefix . $index])) {
            $index++;
        }
        $new_groups[] = ['label' => $prefix . $index, 'word_ids' => $members];
        $index++;
    }
    if ($new_groups === []) {
        return true;
    }

    $rules[$wordset_id][$category_id]['groups'] = array_merge($existing, $new_groups);
    if ($stored === null) {
        $written = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $option,
            maybe_serialize($rules)
        ));
    } else {
        $written = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = UNHEX(%s)",
            maybe_serialize($rules),
            $option,
            $stored['option_value_hex']
        ));
    }
    if ($written !== 1) {
        return false;
    }

    wp_cache_delete($option, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id], [$wordset_id], true);
    }
    return true;
}
