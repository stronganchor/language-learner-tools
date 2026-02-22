<?php
/**
 * [audio_recording_interface] - Public-facing interface for native speakers
 * to record audio for word images that don't have audio yet.
 */

if (!defined('WPINC')) { die; }

/**
 * Get translatable name for a recording type by slug
 */
function ll_get_recording_type_name($slug, $term_name = '') {
    $term = get_term_by('slug', $slug, 'recording_type');
    if ($term && !is_wp_error($term)) {
        $translated = get_term_meta((int) $term->term_id, 'term_translation', true);
        if ($translated !== '') {
            return $translated;
        }
    }

    $english_defaults = [
        'isolation'     => 'Isolation',
        'question'      => 'Question',
        'introduction'  => 'Introduction',
        'sentence'      => 'In Sentence',
    ];

    $translated_defaults = [
        'isolation'     => __('Isolation', 'll-tools-text-domain'),
        'question'      => __('Question', 'll-tools-text-domain'),
        'introduction'  => __('Introduction', 'll-tools-text-domain'),
        'sentence'      => __('In Sentence', 'll-tools-text-domain'),
    ];

    if ($term_name !== '') {
        if (!empty($english_defaults[$slug]) && $term_name !== $english_defaults[$slug]) {
            return $term_name;
        }
        if (isset($translated_defaults[$slug]) && isset($english_defaults[$slug])) {
            if ($translated_defaults[$slug] !== $english_defaults[$slug]) {
                return $translated_defaults[$slug];
            }
        }
        return $term_name;
    }

    if ($term && !is_wp_error($term) && !empty($term->name)) {
        if (!empty($english_defaults[$slug]) && $term->name !== $english_defaults[$slug]) {
            return $term->name;
        }
    }

    if (isset($translated_defaults[$slug]) && isset($english_defaults[$slug])) {
        if ($translated_defaults[$slug] !== $english_defaults[$slug]) {
            return $translated_defaults[$slug];
        }
    }

    if ($term && !is_wp_error($term) && !empty($term->name)) {
        return $term->name;
    }

    return isset($translated_defaults[$slug]) ? $translated_defaults[$slug] : ucfirst($slug);
}

/**
 * Returns an ordered list of recording type slugs for prompting.
 *
 * @return string[]
 */
function ll_get_recording_type_prompt_order(): array {
    $default_order = ['isolation', 'introduction', 'question', 'sentence'];
    $order = apply_filters('ll_tools_recording_type_prompt_order', $default_order);
    if (!is_array($order)) {
        return $default_order;
    }
    $sanitized = [];
    foreach ($order as $slug) {
        $normalized = sanitize_title((string) $slug);
        if ($normalized !== '') {
            $sanitized[] = $normalized;
        }
    }
    $sanitized = array_values(array_unique($sanitized));
    return !empty($sanitized) ? $sanitized : $default_order;
}

/**
 * Returns recording-type icon map keyed by slug.
 *
 * @return array<string, string>
 */
function ll_get_recording_type_icons_map(): array {
    $defaults = [
        'isolation'    => '🔍',
        'introduction' => '💬',
        'question'     => '❓',
        'sentence'     => '📝',
        'default'      => '🎙️',
    ];
    $maybe_map = apply_filters('ll_tools_recording_type_icons_map', $defaults);
    if (!is_array($maybe_map)) {
        return $defaults;
    }

    $icons = [];
    foreach ($maybe_map as $slug => $icon) {
        $slug_key = sanitize_title((string) $slug);
        if ($slug_key === '') {
            if ((string) $slug !== 'default') {
                continue;
            }
            $slug_key = 'default';
        }
        $icons[$slug_key] = sanitize_text_field((string) $icon);
    }

    foreach ($defaults as $slug => $icon) {
        if (!isset($icons[$slug]) || $icons[$slug] === '') {
            $icons[$slug] = $icon;
        }
    }
    return $icons;
}

/**
 * Return the icon associated with a recording type slug.
 */
function ll_get_recording_type_icon($slug): string {
    $icons = ll_get_recording_type_icons_map();
    $key = sanitize_title((string) $slug);
    if ($key !== '' && !empty($icons[$key])) {
        return (string) $icons[$key];
    }
    return (string) ($icons['default'] ?? '');
}

/**
 * Compare two recording type slugs by canonical prompt order.
 */
function ll_compare_recording_type_slugs($left, $right): int {
    $left_slug = sanitize_title((string) $left);
    $right_slug = sanitize_title((string) $right);
    $priority = array_flip(ll_get_recording_type_prompt_order());

    $left_in_priority = array_key_exists($left_slug, $priority);
    $right_in_priority = array_key_exists($right_slug, $priority);

    if ($left_in_priority && $right_in_priority) {
        return (int) $priority[$left_slug] <=> (int) $priority[$right_slug];
    }
    if ($left_in_priority) {
        return -1;
    }
    if ($right_in_priority) {
        return 1;
    }
    return strnatcasecmp($left_slug, $right_slug);
}

/**
 * Sort + normalize a list of recording type slugs into canonical order.
 *
 * @param array $slugs
 * @return string[]
 */
function ll_sort_recording_type_slugs(array $slugs): array {
    $normalized = [];
    foreach ($slugs as $slug) {
        $clean = sanitize_title((string) $slug);
        if ($clean !== '') {
            $normalized[] = $clean;
        }
    }
    $normalized = array_values(array_unique($normalized));
    if (count($normalized) < 2) {
        return $normalized;
    }
    usort($normalized, 'll_compare_recording_type_slugs');
    return $normalized;
}

/**
 * Build standardized recording-type payload entries for recorder UI.
 *
 * @param array $recording_type_slugs
 * @return array<int, array<string, mixed>>
 */
function ll_build_recording_type_payload(array $recording_type_slugs): array {
    $sorted_slugs = ll_sort_recording_type_slugs($recording_type_slugs);
    if (empty($sorted_slugs)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'slug'       => $sorted_slugs,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $terms_by_slug = [];
    foreach ($terms as $term) {
        if (!empty($term->slug)) {
            $terms_by_slug[$term->slug] = $term;
        }
    }

    $payload = [];
    foreach ($sorted_slugs as $slug) {
        if (!isset($terms_by_slug[$slug])) {
            continue;
        }
        $term = $terms_by_slug[$slug];
        $name = ll_get_recording_type_name($term->slug, $term->name);
        $icon = ll_get_recording_type_icon($term->slug);
        $label = trim($icon . ' ' . $name);
        $payload[] = [
            'slug'    => $term->slug,
            'name'    => $name,
            'term_id' => (int) $term->term_id,
            'icon'    => $icon,
            'label'   => $label,
        ];
    }

    return $payload;
}

/**
 * Fetch all recording-type slugs in canonical prompt order.
 *
 * @return string[]
 */
function ll_tools_get_all_recording_type_slugs(): array {
    $all_types = get_terms([
        'taxonomy'   => 'recording_type',
        'fields'     => 'slugs',
        'hide_empty' => false,
    ]);
    if (is_wp_error($all_types) || empty($all_types)) {
        return [];
    }

    return ll_sort_recording_type_slugs((array) $all_types);
}

/**
 * Apply include/exclude filters to recording-type slugs.
 *
 * @param string[] $all_types
 * @return string[]
 */
function ll_tools_filter_recording_type_slugs(array $all_types, string $include_types_csv = '', string $exclude_types_csv = ''): array {
    $include_types = $include_types_csv !== '' ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv !== '' ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }

    return ll_sort_recording_type_slugs($filtered_types);
}

/**
 * Parse include/exclude recording-type filters from the current request.
 *
 * @param string[]|null $all_types
 * @return array{include_types_csv:string,exclude_types_csv:string,all_types:array<int,string>,filtered_types:array<int,string>}
 */
function ll_tools_get_recording_type_filters_from_request(?array $all_types = null): array {
    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    if ($all_types === null) {
        $all_types = ll_tools_get_all_recording_type_slugs();
    } else {
        $all_types = ll_sort_recording_type_slugs($all_types);
    }

    $filtered_types = ll_tools_filter_recording_type_slugs($all_types, $include_types_csv, $exclude_types_csv);

    return [
        'include_types_csv' => $include_types_csv,
        'exclude_types_csv' => $exclude_types_csv,
        'all_types' => $all_types,
        'filtered_types' => $filtered_types,
    ];
}

/**
 * Return allowed MIME map for recorder uploads.
 *
 * @return array<string,string>
 */
function ll_tools_get_allowed_recording_upload_mimes(): array {
    $defaults = [
        'mp3'  => 'audio/mpeg|audio/mp3|audio/x-mp3',
        'wav'  => 'audio/wav|audio/x-wav|audio/wave',
        'aac'  => 'audio/aac|audio/x-aac',
        'm4a'  => 'audio/mp4|video/mp4|audio/x-m4a',
        'mp4'  => 'audio/mp4|video/mp4',
        'ogg'  => 'audio/ogg|application/ogg',
        'oga'  => 'audio/ogg|application/ogg',
        'webm' => 'audio/webm|video/webm|audio/x-webm|video/x-webm',
    ];

    $raw_mimes = apply_filters('ll_tools_allowed_recording_upload_mimes', $defaults);
    if (!is_array($raw_mimes) || empty($raw_mimes)) {
        return $defaults;
    }

    $sanitized = [];
    foreach ($raw_mimes as $ext => $mime_pattern) {
        $ext_key = sanitize_key((string) $ext);
        if ($ext_key === '') {
            continue;
        }
        $pattern = preg_replace('/[^a-z0-9_+.\-|\/]/i', '', (string) $mime_pattern);
        if ($pattern === '') {
            continue;
        }
        $sanitized[$ext_key] = $pattern;
    }

    return !empty($sanitized) ? $sanitized : $defaults;
}

/**
 * Normalize recorder upload MIME values into canonical, comparable strings.
 */
function ll_tools_normalize_recording_upload_mime($mime): string {
    $mime = strtolower(trim((string) $mime));
    if ($mime === '') {
        return '';
    }

    // Browsers often append codec parameters (e.g. audio/webm;codecs=opus).
    $mime = preg_replace('/\s*;.*$/', '', $mime);
    $mime = strtolower(trim((string) $mime));
    if ($mime === '') {
        return '';
    }

    $mime = (string) preg_replace('/[^a-z0-9+.\-\/]/i', '', $mime);
    if ($mime === '') {
        return '';
    }

    $aliases = [
        'audio/mp3'    => 'audio/mpeg',
        'audio/x-mp3'  => 'audio/mpeg',
        'audio/x-m4a'  => 'audio/mp4',
        'audio/wave'   => 'audio/wav',
        'audio/x-wav'  => 'audio/wav',
        'audio/x-webm' => 'audio/webm',
        'video/x-webm' => 'video/webm',
    ];

    return isset($aliases[$mime]) ? (string) $aliases[$mime] : $mime;
}

/**
 * Validate recorder audio upload payload before moving the file.
 *
 * @param array<string,mixed> $file
 * @return array{valid:bool,error:string,status:int,ext:string,mime:string,size:int}
 */
function ll_tools_validate_recording_upload_file(array $file, bool $require_uploaded_file = true): array {
    $result = [
        'valid'  => false,
        'error'  => __('Invalid audio upload.', 'll-tools-text-domain'),
        'status' => 400,
        'ext'    => '',
        'mime'   => '',
        'size'   => 0,
    ];

    $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error_code !== UPLOAD_ERR_OK) {
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            $result['error'] = __('Audio file is too large.', 'll-tools-text-domain');
            $result['status'] = 413;
            return $result;
        }
        if ($error_code === UPLOAD_ERR_NO_FILE) {
            $result['error'] = __('No audio file was uploaded.', 'll-tools-text-domain');
            return $result;
        }

        $result['error'] = __('Audio upload failed. Please try again.', 'll-tools-text-domain');
        return $result;
    }

    $tmp_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmp_path === '' || !file_exists($tmp_path) || !is_readable($tmp_path)) {
        $result['error'] = __('Uploaded audio file is missing or unreadable.', 'll-tools-text-domain');
        return $result;
    }

    if ($require_uploaded_file && !is_uploaded_file($tmp_path)) {
        $result['error'] = __('Upload source is invalid.', 'll-tools-text-domain');
        return $result;
    }

    $size = isset($file['size']) ? (int) $file['size'] : (int) @filesize($tmp_path);
    $size = max(0, $size);
    $result['size'] = $size;
    if ($size <= 0) {
        $result['error'] = __('Uploaded audio file is empty.', 'll-tools-text-domain');
        return $result;
    }

    $max_size = (int) apply_filters('ll_tools_max_recording_upload_bytes', wp_max_upload_size());
    if ($max_size <= 0) {
        $max_size = 10 * 1024 * 1024;
    }
    if ($size > $max_size) {
        $result['error'] = sprintf(
            /* translators: %s: max upload size */
            __('Audio file is too large. Maximum allowed size is %s.', 'll-tools-text-domain'),
            size_format($max_size)
        );
        $result['status'] = 413;
        return $result;
    }

    if (!function_exists('wp_check_filetype_and_ext')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $allowed_mimes = ll_tools_get_allowed_recording_upload_mimes();
    $original_name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
    if ($original_name === '') {
        $original_name = 'recording';
    }

    $detected = wp_check_filetype_and_ext($tmp_path, $original_name, $allowed_mimes);
    $ext = sanitize_key((string) ($detected['ext'] ?? ''));
    $mime = ll_tools_normalize_recording_upload_mime((string) ($detected['type'] ?? ''));

    // Some hosts/browsers report sparse MIME metadata for media blobs; fall back
    // to client-reported values while still enforcing our allowed map + audio probe.
    $original_ext = sanitize_key((string) pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext === '' && $original_ext !== '' && isset($allowed_mimes[$original_ext])) {
        $ext = $original_ext;
    }

    $client_mime = ll_tools_normalize_recording_upload_mime((string) ($file['type'] ?? ''));
    if ($mime === '' && $client_mime !== '') {
        $mime = $client_mime;
    }

    if ($ext === '' && $mime !== '') {
        foreach ($allowed_mimes as $candidate_ext => $candidate_pattern) {
            $candidate_ext = sanitize_key((string) $candidate_ext);
            if ($candidate_ext === '') {
                continue;
            }
            $candidate_mimes = array_values(array_filter(array_map(static function ($value) {
                return ll_tools_normalize_recording_upload_mime((string) $value);
            }, explode('|', (string) $candidate_pattern))));
            if (!empty($candidate_mimes) && wp_match_mime_types($candidate_mimes, $mime)) {
                $ext = $candidate_ext;
                break;
            }
        }
    }

    $allowed_for_ext = [];
    if ($ext !== '' && isset($allowed_mimes[$ext])) {
        $allowed_for_ext = array_values(array_filter(array_map(static function ($value) {
            return ll_tools_normalize_recording_upload_mime((string) $value);
        }, explode('|', (string) $allowed_mimes[$ext]))));
        if ($mime === '' && !empty($allowed_for_ext)) {
            $mime = (string) $allowed_for_ext[0];
        }
    }

    if ($ext === '' || $mime === '' || empty($allowed_for_ext) || !wp_match_mime_types($allowed_for_ext, $mime)) {
        $result['error'] = __('Uploaded file type is not allowed.', 'll-tools-text-domain');
        return $result;
    }

    $getid3_path = LL_TOOLS_BASE_PATH . 'vendor/getid3/getid3.php';
    if (is_readable($getid3_path)) {
        require_once $getid3_path;
        if (class_exists('getID3')) {
            $analyzer = new getID3();
            $info = $analyzer->analyze($tmp_path);
            if (empty($info['audio'])) {
                $result['error'] = __('Uploaded file is not a valid audio recording.', 'll-tools-text-domain');
                return $result;
            }
        }
    }

    $result['valid'] = true;
    $result['ext'] = $ext;
    $result['mime'] = $mime;
    $result['error'] = '';
    return $result;
}

/**
 * User meta key for recorder-hidden words.
 */
function ll_tools_recording_hidden_words_meta_key(): string {
    return 'll_recording_hidden_words';
}

/**
 * Normalize a title into a deterministic key fragment for hide/unhide matching.
 */
function ll_tools_normalize_recording_hide_title($title): string {
    $clean = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $clean = sanitize_text_field($clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    if ($clean === '') {
        return '';
    }

    $slug = sanitize_title($clean);
    if ($slug === '') {
        $slug = 't' . substr(md5($clean), 0, 12);
    }
    return $slug;
}

/**
 * Build the canonical hide key for a recording item.
 */
function ll_tools_build_recording_hide_key(int $word_id = 0, int $image_id = 0, string $title = ''): string {
    if ($word_id > 0) {
        return 'word:' . $word_id;
    }
    if ($image_id > 0) {
        return 'image:' . $image_id;
    }
    $normalized_title = ll_tools_normalize_recording_hide_title($title);
    if ($normalized_title !== '') {
        return 'title:' . $normalized_title;
    }
    return '';
}

/**
 * Sanitize a hide key and verify its prefix.
 */
function ll_tools_sanitize_recording_hide_key($hide_key): string {
    $hide_key = strtolower(trim((string) $hide_key));
    $hide_key = preg_replace('/[^a-z0-9:_-]/', '', $hide_key);
    if ($hide_key === '') {
        return '';
    }
    if (strpos($hide_key, 'word:') === 0 || strpos($hide_key, 'image:') === 0 || strpos($hide_key, 'title:') === 0) {
        return $hide_key;
    }
    return '';
}

/**
 * Normalize one hidden-word entry into a sanitized shape.
 *
 * @return array<string, mixed>|null
 */
function ll_tools_normalize_hidden_recording_entry($entry, $fallback_key = '') {
    if (is_string($entry)) {
        $entry = ['key' => $entry];
    }
    if (!is_array($entry)) {
        return null;
    }

    $word_id = absint($entry['word_id'] ?? 0);
    $image_id = absint($entry['image_id'] ?? 0);
    $title = sanitize_text_field((string) ($entry['title'] ?? ''));
    $category_name = sanitize_text_field((string) ($entry['category_name'] ?? ''));
    $category_slug = sanitize_title((string) ($entry['category_slug'] ?? ''));
    $hidden_at = sanitize_text_field((string) ($entry['hidden_at'] ?? ''));

    $hide_key = ll_tools_sanitize_recording_hide_key($entry['key'] ?? '');
    if ($hide_key === '' && $fallback_key !== '') {
        $hide_key = ll_tools_sanitize_recording_hide_key($fallback_key);
    }
    if ($hide_key === '') {
        $hide_key = ll_tools_build_recording_hide_key($word_id, $image_id, $title);
    }
    if ($hide_key === '') {
        return null;
    }

    if ($title === '' && strpos($hide_key, 'title:') === 0) {
        $title = str_replace('-', ' ', substr($hide_key, 6));
    }

    return [
        'key'           => $hide_key,
        'word_id'       => $word_id,
        'image_id'      => $image_id,
        'title'         => $title,
        'category_name' => $category_name,
        'category_slug' => $category_slug,
        'hidden_at'     => $hidden_at,
    ];
}

/**
 * Get the hidden-word map for the current (or specified) user.
 *
 * @return array<string, array<string, mixed>>
 */
function ll_tools_get_hidden_recording_words(int $user_id = 0): array {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    $raw = get_user_meta($user_id, ll_tools_recording_hidden_words_meta_key(), true);
    if (!is_array($raw) || empty($raw)) {
        return [];
    }

    $normalized = [];
    foreach ($raw as $maybe_key => $entry) {
        $fallback_key = is_string($maybe_key) ? $maybe_key : '';
        $normalized_entry = ll_tools_normalize_hidden_recording_entry($entry, $fallback_key);
        if (!$normalized_entry) {
            continue;
        }
        $normalized[(string) $normalized_entry['key']] = $normalized_entry;
    }

    if (empty($normalized)) {
        return [];
    }

    uasort($normalized, static function ($left, $right): int {
        $left_time = strtotime((string) ($left['hidden_at'] ?? '')) ?: 0;
        $right_time = strtotime((string) ($right['hidden_at'] ?? '')) ?: 0;
        if ($left_time !== $right_time) {
            return $right_time <=> $left_time;
        }
        $left_title = (string) ($left['title'] ?? '');
        $right_title = (string) ($right['title'] ?? '');
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings($left_title, $right_title);
        }
        return strnatcasecmp($left_title, $right_title);
    });

    return $normalized;
}

/**
 * Persist hidden-word entries for a user.
 */
function ll_tools_save_hidden_recording_words(int $user_id, array $entries): bool {
    if ($user_id <= 0) {
        return false;
    }

    $normalized = [];
    foreach ($entries as $maybe_key => $entry) {
        $fallback_key = is_string($maybe_key) ? $maybe_key : '';
        $normalized_entry = ll_tools_normalize_hidden_recording_entry($entry, $fallback_key);
        if (!$normalized_entry) {
            continue;
        }
        $normalized[(string) $normalized_entry['key']] = $normalized_entry;
    }

    if (empty($normalized)) {
        delete_user_meta($user_id, ll_tools_recording_hidden_words_meta_key());
        return true;
    }

    if (count($normalized) > 500) {
        uasort($normalized, static function ($left, $right): int {
            $left_time = strtotime((string) ($left['hidden_at'] ?? '')) ?: 0;
            $right_time = strtotime((string) ($right['hidden_at'] ?? '')) ?: 0;
            return $right_time <=> $left_time;
        });
        $normalized = array_slice($normalized, 0, 500, true);
    }

    return (bool) update_user_meta($user_id, ll_tools_recording_hidden_words_meta_key(), $normalized);
}

/**
 * Return hidden-word entries as an indexed list suitable for JSON transport.
 *
 * @return array<int, array<string, mixed>>
 */
function ll_tools_get_hidden_recording_words_list(int $user_id = 0): array {
    return array_values(ll_tools_get_hidden_recording_words($user_id));
}

/**
 * Build a key lookup for hidden words.
 *
 * @return array<string, bool>
 */
function ll_tools_get_hidden_recording_word_lookup(int $user_id = 0): array {
    $lookup = [];
    $hidden_words = ll_tools_get_hidden_recording_words($user_id);
    foreach ($hidden_words as $entry) {
        $primary = ll_tools_sanitize_recording_hide_key((string) ($entry['key'] ?? ''));
        if ($primary !== '') {
            $lookup[$primary] = true;
        }

        $fallback = ll_tools_build_recording_hide_key(
            absint($entry['word_id'] ?? 0),
            absint($entry['image_id'] ?? 0),
            (string) ($entry['title'] ?? '')
        );
        if ($fallback !== '') {
            $lookup[$fallback] = true;
        }
    }
    return $lookup;
}

/**
 * Get all possible hide keys for an item payload.
 *
 * @param array<string, mixed> $item
 * @return string[]
 */
function ll_tools_get_recording_item_hide_keys(array $item): array {
    $keys = [];

    $explicit = ll_tools_sanitize_recording_hide_key($item['hide_key'] ?? '');
    if ($explicit !== '') {
        $keys[] = $explicit;
    }

    $word_id = absint($item['word_id'] ?? 0);
    $image_id = absint($item['id'] ?? ($item['image_id'] ?? 0));
    if ($word_id > 0) {
        $keys[] = 'word:' . $word_id;
    }
    if ($image_id > 0) {
        $keys[] = 'image:' . $image_id;
    }

    $title_candidates = [];
    if (!empty($item['word_title'])) {
        $title_candidates[] = (string) $item['word_title'];
    }
    if (!empty($item['title'])) {
        $title_candidates[] = (string) $item['title'];
    }
    foreach ($title_candidates as $candidate) {
        $title_key = ll_tools_build_recording_hide_key(0, 0, $candidate);
        if ($title_key !== '') {
            $keys[] = $title_key;
        }
    }

    return array_values(array_unique(array_filter($keys)));
}

/**
 * Remove hidden items from recorder payloads for the current user.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function ll_tools_filter_hidden_recording_items(array $items, int $user_id = 0): array {
    if (empty($items)) {
        return $items;
    }

    $lookup = ll_tools_get_hidden_recording_word_lookup($user_id);
    $has_hidden = !empty($lookup);
    $filtered = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hide_keys = ll_tools_get_recording_item_hide_keys($item);
        if (empty($item['hide_key']) && !empty($hide_keys)) {
            $item['hide_key'] = $hide_keys[0];
        }
        if (!$has_hidden) {
            $filtered[] = $item;
            continue;
        }

        $is_hidden = false;
        foreach ($hide_keys as $hide_key) {
            if (isset($lookup[$hide_key])) {
                $is_hidden = true;
                break;
            }
        }
        if ($is_hidden) {
            continue;
        }
        $filtered[] = $item;
    }

    return $filtered;
}

/**
 * Add (or replace) one hidden-word entry for a user.
 *
 * @param array<string, mixed> $entry
 * @return array<string, array<string, mixed>>
 */
function ll_tools_add_hidden_recording_word(int $user_id, array $entry): array {
    $existing = ll_tools_get_hidden_recording_words($user_id);
    $normalized = ll_tools_normalize_hidden_recording_entry($entry);
    if (!$normalized) {
        return $existing;
    }
    if (empty($normalized['hidden_at'])) {
        $normalized['hidden_at'] = current_time('mysql');
    }
    $existing[(string) $normalized['key']] = $normalized;
    ll_tools_save_hidden_recording_words($user_id, $existing);
    return ll_tools_get_hidden_recording_words($user_id);
}

/**
 * Remove one hidden-word entry using key and optional fallback identity fields.
 *
 * @return array<string, array<string, mixed>>
 */
function ll_tools_remove_hidden_recording_word(int $user_id, string $hide_key, int $word_id = 0, int $image_id = 0, string $title = ''): array {
    $existing = ll_tools_get_hidden_recording_words($user_id);
    if (empty($existing)) {
        return [];
    }

    $remove_keys = [];
    $primary = ll_tools_sanitize_recording_hide_key($hide_key);
    if ($primary !== '') {
        $remove_keys[] = $primary;
    }
    if ($word_id > 0) {
        $remove_keys[] = 'word:' . $word_id;
    }
    if ($image_id > 0) {
        $remove_keys[] = 'image:' . $image_id;
    }
    $title_key = ll_tools_build_recording_hide_key(0, 0, $title);
    if ($title_key !== '') {
        $remove_keys[] = $title_key;
    }
    $remove_keys = array_values(array_unique(array_filter($remove_keys)));

    if (empty($remove_keys)) {
        return $existing;
    }

    foreach ($remove_keys as $candidate) {
        unset($existing[$candidate]);
    }

    ll_tools_save_hidden_recording_words($user_id, $existing);
    return ll_tools_get_hidden_recording_words($user_id);
}

function ll_audio_recording_interface_shortcode($atts) {
    // Require user to be logged in
    if (!is_user_logged_in()) {
        return ll_tools_render_login_window([
            'container_class' => 'll-recording-interface ll-login-required',
            'title' => __('Sign in to record', 'll-tools-text-domain'),
            'message' => __('Use an account with recording access to continue.', 'll-tools-text-domain'),
            'submit_label' => __('Continue', 'll-tools-text-domain'),
            'redirect_to' => ll_tools_get_current_request_url(),
        ]);
    }

    if (!ll_tools_user_can_record()) {
        return '<div class="ll-recording-interface"><p>' .
               __('You do not have permission to record audio. If you think this is a mistake, ask for the "Audio Recorder" user role to be added to your user account.', 'll-tools-text-domain') . '</p></div>';
    }

    // Get user-specific configuration from meta (if exists)
    $current_user_id = get_current_user_id();
    $user_config = get_user_meta($current_user_id, 'll_recording_config', true);
    $hidden_recording_words = ll_tools_get_hidden_recording_words_list($current_user_id);
    $has_hidden_recording_words = !empty($hidden_recording_words);

    // Merge user config with shortcode attributes (shortcode takes precedence if specified)
    $defaults = [];
    if (is_array($user_config)) {
        $defaults = $user_config;
    }

    $atts = shortcode_atts(array_merge([
        'category' => '',
        'wordset'  => '',
        'language' => '',
        'include_recording_types' => '',
        'exclude_recording_types' => '',
        'allow_new_words' => '',
        'auto_process_recordings' => '',
    ], $defaults), $atts);

    // Resolve wordset term IDs
    $wordset_term_ids = ll_resolve_wordset_term_ids_or_default($atts['wordset']);

    $allow_new_words = !empty($atts['allow_new_words']);
    $auto_process_recordings = !empty($atts['auto_process_recordings']);

    // Get available categories for the wordset
    $available_categories = ll_get_categories_for_wordset($wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);

    // If no categories available, provide helpful diagnostics
    if (empty($available_categories)) {
        if (!$allow_new_words && !$has_hidden_recording_words) {
            $diagnostic_msg = ll_diagnose_no_categories($wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
            return '<div class="ll-recording-interface"><div class="ll-diagnostic-message">' . $diagnostic_msg . '</div></div>';
        }
        $available_categories = [
            'uncategorized' => __('Uncategorized', 'll-tools-text-domain'),
        ];
    }

    // Get images for the initial category (or first if none specified)
    $initial_category = !empty($atts['category']) && isset($available_categories[$atts['category']]) ? $atts['category'] : key($available_categories);
    // Prefer showing uncategorized first when present so missing-audio words are surfaced
    if (empty($atts['category']) && isset($available_categories['uncategorized'])) {
        $initial_category = 'uncategorized';
    }
    $images_needing_audio = ll_get_images_needing_audio($initial_category, $wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
    // If the preferred initial category is empty (e.g., stale uncategorized records), fall back to the first category with work.
    if (empty($images_needing_audio) && count($available_categories) > 1) {
        foreach ($available_categories as $slug => $name) {
            if ($slug === $initial_category) {
                continue;
            }
            $maybe = ll_get_images_needing_audio($slug, $wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
            if (!empty($maybe)) {
                $images_needing_audio = $maybe;
                $initial_category = $slug;
                break;
            }
        }
    }

    if (empty($images_needing_audio) && !$allow_new_words && !$has_hidden_recording_words) {
        return '<div class="ll-recording-interface"><p>' .
               __('No images need audio recordings in the selected category at this time. Thank you!', 'll-tools-text-domain') .
               '</p></div>';
    }

    ll_enqueue_recording_assets($auto_process_recordings);

    // Get recording types for dropdown (based on initial images)
    $recording_types = [];
    foreach ($images_needing_audio as $img) {
        if (is_array($img['missing_types'])) {
            $recording_types = array_merge($recording_types, $img['missing_types']);
        }
        if (is_array($img['existing_types'])) {
            $recording_types = array_merge($recording_types, $img['existing_types']);
        }
    }
    $dropdown_types = ll_build_recording_type_payload($recording_types);

    // Get current user info for display
    $current_user = wp_get_current_user();

    $all_recording_types = [];
    if ($allow_new_words) {
        $all_recording_types = get_terms([
            'taxonomy'   => 'recording_type',
            'hide_empty' => false,
        ]);
        if (is_wp_error($all_recording_types)) {
            $all_recording_types = [];
        } else {
            usort($all_recording_types, static function ($left, $right) {
                return ll_compare_recording_type_slugs($left->slug, $right->slug);
            });
        }
    }

    wp_localize_script('ll-audio-recorder', 'll_recorder_data', [
        'ajax_url'        => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('ll_upload_recording'),
        'images'          => $images_needing_audio,
        'available_categories' => $available_categories,
        'language'        => $atts['language'],
        'wordset'         => $atts['wordset'],
        'wordset_ids'     => $wordset_term_ids,
        'sort_locale'     => get_locale(),
        'hide_name'       => (bool) get_option('ll_hide_recording_titles', 0),
        'recording_types' => $dropdown_types,
        'recording_type_order' => ll_get_recording_type_prompt_order(),
        'recording_type_icons' => ll_get_recording_type_icons_map(),
        'allow_new_words' => $allow_new_words,
        'assembly_enabled' => ((string) get_option('ll_assemblyai_api_key', '') !== ''),
        'deepl_enabled'    => ((string) get_option('ll_deepl_api_key', '') !== ''),
        'user_display_name' => $current_user->display_name,
        'require_all_types' => true,
        'initial_category' => $initial_category,
        'include_types'    => $atts['include_recording_types'],
        'exclude_types'    => $atts['exclude_recording_types'],
        'auto_process_recordings' => $auto_process_recordings,
        'transcribe_poll_attempts' => (int) apply_filters('ll_tools_recorder_transcribe_poll_attempts', 20),
        'transcribe_poll_interval_ms' => (int) apply_filters('ll_tools_recorder_transcribe_poll_interval_ms', 1200),
        'current_user_id'  => get_current_user_id(),
        'hidden_words'    => $hidden_recording_words,
        'hidden_count'    => count($hidden_recording_words),
        'i18n' => [
            'uploading' => __('Uploading...', 'll-tools-text-domain'),
            'success' => __('Success! Recording will be processed later.', 'll-tools-text-domain'),
            'success_processed' => __('Success! Recording published.', 'll-tools-text-domain'),
            'error_prefix' => __('Error:', 'll-tools-text-domain'),
            'upload_failed' => __('Upload failed:', 'll-tools-text-domain'),
            'saved_next_type' => __('Saved. Next type selected.', 'll-tools-text-domain'),
            'skipped_type' => __('Skipped this type. Next type selected.', 'll-tools-text-domain'),
            'all_complete' => __('All recordings completed for the selected set. Thank you!', 'll-tools-text-domain'),
            'category' => __('Category:', 'll-tools-text-domain'),
            'uncategorized' => __('Uncategorized', 'll-tools-text-domain'),
            'no_blob' => __('No audio blob to submit', 'll-tools-text-domain'),
            'microphone_error' => __('Error: Could not access microphone', 'll-tools-text-domain'),
            'processing' => __('Processing audio...', 'll-tools-text-domain'),
            'processing_ready' => __('Review the processed audio below.', 'll-tools-text-domain'),
            'processing_failed' => __('Audio processing failed. You can upload the raw recording instead.', 'll-tools-text-domain'),
            'starting_upload' => __('Starting upload for image:', 'll-tools-text-domain'),
            'http_error' => __('HTTP %d: %s', 'll-tools-text-domain'),
            'invalid_response' => __('Server returned invalid response format', 'll-tools-text-domain'),
            'switching_category' => __('Switching category...', 'll-tools-text-domain'),
            'skipping'            => __('Skipping...', 'll-tools-text-domain'),
            'skip_failed'         => __('Skip failed:', 'll-tools-text-domain'),
            'no_images_in_category'=> __('No images need audio in this category.', 'll-tools-text-domain'),
            'category_switched'   => __('Category switched. Ready to record.', 'll-tools-text-domain'),
            'switch_failed'       => __('Switch failed:', 'll-tools-text-domain'),
            'new_word_preparing'  => __('Preparing new word...', 'll-tools-text-domain'),
            'new_word_failed'     => __('New word setup failed:', 'll-tools-text-domain'),
            'new_word_missing_category' => __('Enter a category name or disable "Create new category".', 'll-tools-text-domain'),
            'new_word_missing_recording' => __('Record audio before saving this word.', 'll-tools-text-domain'),
            'transcribing'        => __('Transcribing...', 'll-tools-text-domain'),
            'translating'         => __('Translating...', 'll-tools-text-domain'),
            'transcription_failed'=> __('Transcription failed:', 'll-tools-text-domain'),
            'transcription_timeout'=> __('Transcription is still processing. Please try again in a moment.', 'll-tools-text-domain'),
            'transcription_unavailable' => __('Speech-to-text is not available. Please enter the word manually.', 'll-tools-text-domain'),
            'translation_failed'  => __('Translation failed:', 'll-tools-text-domain'),
            'translation_ready'   => __('Translation ready.', 'll-tools-text-domain'),
            'hiding'             => __('Hiding...', 'll-tools-text-domain'),
            'hide_failed'        => __('Hide failed:', 'll-tools-text-domain'),
            'hidden_success'     => __('Word hidden. Moving to the next item.', 'll-tools-text-domain'),
            'hide_while_recording' => __('Stop recording before hiding this word.', 'll-tools-text-domain'),
            'hidden_words'       => __('Hidden words', 'll-tools-text-domain'),
            'hidden_empty'       => __('No hidden words yet.', 'll-tools-text-domain'),
            'unhide'             => __('Unhide', 'll-tools-text-domain'),
            'unhide_failed'      => __('Unhide failed:', 'll-tools-text-domain'),
            'unhide_success'     => __('Word unhidden.', 'll-tools-text-domain'),
        ],
    ]);
    // Get wordset name for display
    $wordset_name = '';
    if (!empty($wordset_term_ids)) {
        $wordset_term = get_term($wordset_term_ids[0], 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_name = $wordset_term->name;
        }
    }

    ob_start();
    ?>
    <?php $initial_count = is_array($images_needing_audio) ? count($images_needing_audio) : 0; ?>
    <div class="ll-recording-interface">
        <!-- Compact header - progress, category, wordset, user -->
        <div class="ll-recording-header">
            <div class="ll-recording-progress">
                <span class="ll-current-num"><?php echo $initial_count ? 1 : 0; ?></span> / <span class="ll-total-num"><?php echo $initial_count; ?></span>
            </div>

            <div class="ll-category-selector">
                <select id="ll-category-select">
                    <?php
                    foreach ($available_categories as $slug => $name) {
                        $selected = ($slug === $initial_category) ? 'selected' : '';
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($slug),
                            $selected,
                            esc_html($name)
                        );
                    }
                    ?>
                </select>
            </div>

            <?php if ($wordset_name): ?>
            <div class="ll-wordset-display">
                <span><?php _e('Set:', 'll-tools-text-domain'); ?></span>
                <strong><?php echo esc_html($wordset_name); ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($allow_new_words): ?>
            <div class="ll-new-word-toggle">
                <button type="button" class="ll-btn ll-btn-secondary" id="ll-new-word-toggle">
                    <?php _e('Record New Word', 'll-tools-text-domain'); ?>
                </button>
            </div>
            <?php endif; ?>

            <div class="ll-hidden-words-toggle">
                <button
                    type="button"
                    class="ll-btn ll-hidden-words-toggle-btn"
                    id="ll-hidden-words-toggle"
                    aria-expanded="false"
                    aria-controls="ll-hidden-words-panel"
                    title="<?php esc_attr_e('Hidden words', 'll-tools-text-domain'); ?>"
                >
                    <span class="ll-hidden-words-toggle-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64" width="18" height="18" fill="none" focusable="false" aria-hidden="true">
                            <path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="32" cy="32" r="7" fill="currentColor" />
                            <path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" />
                        </svg>
                    </span>
                    <span class="ll-hidden-words-toggle-label"><?php esc_html_e('Hidden', 'll-tools-text-domain'); ?></span>
                    <span class="ll-hidden-words-count" id="ll-hidden-words-count"><?php echo esc_html((string) count($hidden_recording_words)); ?></span>
                </button>
            </div>

            <div class="ll-recorder-info">
                <?php echo esc_html($current_user->display_name); ?>
            </div>
        </div>

        <div class="ll-hidden-words-panel" id="ll-hidden-words-panel" hidden>
            <div class="ll-hidden-words-panel-head">
                <h3 class="ll-hidden-words-title"><?php esc_html_e('Hidden words', 'll-tools-text-domain'); ?></h3>
                <button type="button" class="ll-btn ll-hidden-words-close" id="ll-hidden-words-close" title="<?php esc_attr_e('Close', 'll-tools-text-domain'); ?>" aria-label="<?php esc_attr_e('Close', 'll-tools-text-domain'); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" focusable="false" aria-hidden="true">
                        <path d="M6.7 5.3a1 1 0 0 0-1.4 1.4L10.6 12l-5.3 5.3a1 1 0 0 0 1.4 1.4l5.3-5.3 5.3 5.3a1 1 0 0 0 1.4-1.4L13.4 12l5.3-5.3a1 1 0 0 0-1.4-1.4L12 10.6Z" fill="currentColor" />
                    </svg>
                </button>
            </div>
            <ul id="ll-hidden-words-list" class="ll-hidden-words-list"></ul>
            <p id="ll-hidden-words-empty" class="ll-hidden-words-empty"><?php esc_html_e('No hidden words yet.', 'll-tools-text-domain'); ?></p>
        </div>

        <?php if ($allow_new_words): ?>
        <div class="ll-new-word-panel" style="display: none;">
            <div class="ll-new-word-card">
                <h3><?php _e('Record a New Word', 'll-tools-text-domain'); ?></h3>
                <div class="ll-new-word-row">
                    <label for="ll-new-word-category"><?php _e('Category', 'll-tools-text-domain'); ?></label>
                    <select id="ll-new-word-category">
                        <?php
                        $uncat_label = __('Uncategorized', 'll-tools-text-domain');
                        $category_terms = get_terms([
                            'taxonomy'   => 'word-category',
                            'hide_empty' => false,
                        ]);
                        if (is_wp_error($category_terms)) { $category_terms = []; }
                        $category_options = [];
                        foreach ($category_terms as $term) {
                            if ($term->slug === 'uncategorized') {
                                $uncat_label = $term->name ?: $uncat_label;
                                continue;
                            }
                            $category_options[$term->slug] = $term->name;
                        }
                        if (!empty($category_options)) {
                            asort($category_options, SORT_FLAG_CASE | SORT_NATURAL);
                        }
                        ?>
                        <option value="uncategorized"><?php echo esc_html($uncat_label); ?></option>
                        <?php foreach ($category_options as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ll-new-word-row ll-new-word-checkbox">
                    <label>
                        <input type="checkbox" id="ll-new-word-create-category" />
                        <?php _e('Create a new category for these words', 'll-tools-text-domain'); ?>
                    </label>
                </div>

                <div class="ll-new-word-create-fields" style="display: none;">
                    <div class="ll-new-word-row">
                        <label for="ll-new-word-category-name"><?php _e('New Category Name', 'll-tools-text-domain'); ?></label>
                        <input type="text" id="ll-new-word-category-name" placeholder="<?php esc_attr_e('e.g., Food', 'll-tools-text-domain'); ?>" />
                    </div>
                    <div class="ll-new-word-row">
                        <label><?php _e('Desired Recording Types', 'll-tools-text-domain'); ?></label>
                        <div class="ll-new-word-types">
                            <?php if (!empty($all_recording_types)): ?>
                                <?php foreach ($all_recording_types as $type): ?>
                                    <?php
                                    $type_name = ll_get_recording_type_name($type->slug, $type->name);
                                    $type_icon = ll_get_recording_type_icon($type->slug);
                                    ?>
                                    <label class="ll-recording-type-option" data-recording-type="<?php echo esc_attr($type->slug); ?>">
                                        <input
                                            type="checkbox"
                                            value="<?php echo esc_attr($type->slug); ?>"
                                            data-type-name="<?php echo esc_attr($type_name); ?>"
                                            <?php checked($type->slug, 'isolation'); ?>
                                        />
                                        <span class="ll-recording-type-option-icon" aria-hidden="true"><?php echo esc_html($type_icon); ?></span>
                                        <span class="ll-recording-type-option-label"><?php echo esc_html($type_name); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <em><?php _e('No recording types available.', 'll-tools-text-domain'); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="ll-new-word-recording">
                    <div class="ll-new-word-recording-controls">
                        <button id="ll-new-word-record-btn" class="ll-btn ll-btn-record"
                                title="<?php esc_attr_e('Record', 'll-tools-text-domain'); ?>"></button>
                        <div id="ll-new-word-recording-indicator" class="ll-recording-indicator" style="display:none;">
                            <span class="ll-recording-dot"></span>
                            <span id="ll-new-word-recording-timer">0:00</span>
                        </div>
                    </div>
                    <div id="ll-new-word-recording-type" class="ll-new-word-recording-type" style="display:none;" role="status" aria-live="polite">
                        <span class="ll-new-word-recording-type-dot" aria-hidden="true"></span>
                        <span id="ll-new-word-recording-type-label" class="ll-new-word-recording-type-label"></span>
                    </div>
                    <div id="ll-new-word-playback-controls" class="ll-new-word-playback-controls" style="display:none;">
                        <audio id="ll-new-word-playback-audio" controls></audio>
                        <div class="ll-new-word-playback-actions">
                            <button id="ll-new-word-redo-btn" class="ll-btn ll-btn-secondary"
                                    title="<?php esc_attr_e('Record again', 'll-tools-text-domain'); ?>"></button>
                        </div>
                    </div>
                </div>

                <div id="ll-new-word-review-slot" class="ll-new-word-review-slot"></div>

                <div class="ll-new-word-auto-status" id="ll-new-word-auto-status" style="display:none;" role="status" aria-live="polite" aria-busy="false">
                    <span class="ll-new-word-auto-icon" aria-hidden="true">🤖</span>
                    <span class="ll-new-word-auto-spinner" aria-hidden="true"></span>
                    <button type="button" class="ll-btn ll-new-word-auto-cancel" id="ll-new-word-auto-cancel" aria-label="<?php esc_attr_e('Cancel automatic transcription', 'll-tools-text-domain'); ?>">x</button>
                </div>

                <div class="ll-new-word-row">
                    <label for="ll-new-word-text-target"><?php _e('Target Word (optional)', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll-new-word-text-target" placeholder="<?php esc_attr_e('Enter the word in the target language', 'll-tools-text-domain'); ?>" />
                </div>
                <div class="ll-new-word-row">
                    <label for="ll-new-word-text-translation"><?php _e('Translation (optional)', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll-new-word-text-translation" placeholder="<?php esc_attr_e('Enter the translation', 'll-tools-text-domain'); ?>" />
                </div>

                <div class="ll-new-word-actions">
                    <button type="button" class="ll-btn ll-btn-primary" id="ll-new-word-start"><?php _e('Save and Continue', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="ll-btn ll-btn-secondary" id="ll-new-word-back"><?php _e('Back to Existing Words', 'll-tools-text-domain'); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="ll-recording-main">
            <?php
            $flashcard_size = get_option('ll_flashcard_image_size', 'small');
            $size_class = 'flashcard-size-' . sanitize_html_class($flashcard_size);
            ?>

            <!-- Image on left -->
            <div class="ll-recording-image-container">
                <div class="flashcard-container <?php echo esc_attr($size_class); ?>">
                    <img id="ll-current-image" class="quiz-image" src="" alt="">
                </div>
                <p id="ll-image-title" class="ll-image-title"></p>
                <p id="ll-image-category" class="ll-image-category"></p>
            </div>

            <!-- Controls on right -->
            <div class="ll-recording-controls-column">
                <!-- Recording type moved here for better visibility and more space -->
                <div class="ll-recording-type-selector">
                    <label for="ll-recording-type"><?php _e('Recording Type:', 'll-tools-text-domain'); ?></label>
                    <?php
                    $initial_recording_type = '';
                    if (!empty($images_needing_audio[0]['missing_types']) && is_array($images_needing_audio[0]['missing_types'])) {
                        $initial_recording_type = (string) $images_needing_audio[0]['missing_types'][0];
                    } elseif (!empty($dropdown_types[0]['slug'])) {
                        $initial_recording_type = (string) $dropdown_types[0]['slug'];
                    }
                    ?>
                    <select id="ll-recording-type">
                        <?php
                        if (!empty($dropdown_types) && !is_wp_error($dropdown_types)) {
                            foreach ($dropdown_types as $type) {
                                $selected = ($type['slug'] === $initial_recording_type) ? 'selected' : '';
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($type['slug']),
                                    $selected,
                                    esc_html($type['label'] ?? $type['name'])
                                );
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="ll-recording-buttons">
                    <button id="ll-record-btn" class="ll-btn ll-btn-record"
                            title="<?php esc_attr_e('Record', 'll-tools-text-domain'); ?>"></button>

                    <button id="ll-skip-btn" class="ll-btn ll-btn-skip"
                            title="<?php esc_attr_e('Skip', 'll-tools-text-domain'); ?>">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                            <path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/>
                        </svg>
                    </button>

                    <button id="ll-hide-btn" class="ll-btn ll-btn-hide" title="<?php esc_attr_e('Hide this word', 'll-tools-text-domain'); ?>" aria-label="<?php esc_attr_e('Hide this word', 'll-tools-text-domain'); ?>">
                        <svg viewBox="0 0 64 64" width="20" height="20" fill="none" focusable="false" aria-hidden="true">
                            <path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="32" cy="32" r="7" fill="currentColor" />
                            <path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <div id="ll-recording-indicator" class="ll-recording-indicator" style="display:none;">
                    <span class="ll-recording-dot"></span>
                    <span id="ll-recording-timer">0:00</span>
                </div>

                <div id="ll-playback-controls" class="ll-playback-controls" style="display:none;">
                    <audio id="ll-playback-audio" controls></audio>
                    <div class="ll-playback-actions">
                        <button id="ll-redo-btn" class="ll-btn ll-btn-secondary"
                                title="<?php esc_attr_e('Record again', 'll-tools-text-domain'); ?>"></button>
                        <button id="ll-submit-btn" class="ll-btn ll-btn-primary"
                                title="<?php esc_attr_e('Save and continue', 'll-tools-text-domain'); ?>"></button>
                    </div>
                </div>

                <div id="ll-upload-status" class="ll-upload-status"></div>
            </div>
        </div>

        <?php if ($auto_process_recordings): ?>
        <div id="ll-recording-review-slot" class="ll-recording-review-slot">
            <div id="ll-recording-review" class="ll-review-interface ll-recording-review" style="display:none;">
                <h2><?php _e('Review Processed Audio', 'll-tools-text-domain'); ?></h2>
                <div id="ll-review-files-container"></div>
                <div class="ll-review-actions">
                    <button type="button" id="ll-review-redo" class="ll-btn ll-btn-secondary" title="<?php esc_attr_e('Record again', 'll-tools-text-domain'); ?>" aria-label="<?php esc_attr_e('Record again', 'll-tools-text-domain'); ?>"></button>
                    <button type="button" id="ll-review-submit" class="ll-btn ll-btn-primary" title="<?php esc_attr_e('Save and continue', 'll-tools-text-domain'); ?>" aria-label="<?php esc_attr_e('Save and continue', 'll-tools-text-domain'); ?>"></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="ll-recording-complete" style="display:none;">
            <h2>✓</h2>
            <p><span class="ll-completed-count"></span> <?php _e('recordings completed', 'll-tools-text-domain'); ?></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_recording_interface', 'll_audio_recording_interface_shortcode');

function ll_get_categories_for_wordset($wordset_term_ids, $include_types_csv, $exclude_types_csv) {
    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types)) $all_types = [];

    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $base_filtered = $all_types;
    if (!empty($include_types)) {
        $base_filtered = array_intersect($base_filtered, $include_types);
    } elseif (!empty($exclude_types)) {
        $base_filtered = array_diff($base_filtered, $exclude_types);
    }
    $base_filtered = ll_sort_recording_type_slugs(array_values($base_filtered));

    if (empty($base_filtered)) {
        return [];
    }

    $categories = [];
    $has_uncategorized_items = false;
    $current_uid = get_current_user_id();

    // Image-backed items
    $image_args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ],
        ],
    ];
    $image_posts = get_posts($image_args);

    foreach ($image_posts as $img_id) {
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);
        // desired types
        $desired = [];
        if ($word_id) {
            $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        } else {
            $term_ids = wp_get_post_terms($img_id, 'word-category', ['fields' => 'ids']);
            $has_enabled_cat = false;
            $has_disabled_cat = false;
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                foreach ($term_ids as $tid) {
                    if (ll_tools_is_category_recording_disabled($tid)) {
                        $has_disabled_cat = true;
                        continue;
                    }
                    $has_enabled_cat = true;
                    $desired = array_merge($desired, ll_tools_get_desired_recording_types_for_category($tid));
                }
            }
            if (empty($desired)) {
                if ($has_enabled_cat) {
                    $desired = ll_tools_get_uncategorized_desired_recording_types();
                } elseif ($has_disabled_cat) {
                    $desired = [];
                } else {
                    $desired = ll_tools_get_uncategorized_desired_recording_types();
                }
            }
        }
        $desired = ll_sort_recording_type_slugs($desired);
        $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($desired, $base_filtered)));
        if (empty($filtered_types)) { continue; }

        // Apply single-speaker gating only if requesting the full main set; otherwise, use global missing logic
        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        if ($word_id) {
            $missing = $types_equal_main
                ? ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_uid)
                : ll_get_missing_recording_types_for_word($word_id, $filtered_types);
        } else {
            $missing = $filtered_types;
        }
        if (!empty($missing)) {
            // Prefer the word's categories when available. This allows duplicated word categories
            // to appear even if the shared word_images post wasn't manually recategorized yet.
            if ($word_id) {
                $cats = wp_get_post_terms($word_id, 'word-category');
            } else {
                $cats = wp_get_post_terms($img_id, 'word-category');
            }
            if (!empty($cats) && !is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $categories[$cat->slug] = $cat->name;
                }
            } else {
                $has_uncategorized_items = true;
            }
        }
    }

    // Text-only words (no image)
    $word_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_thumbnail_id', 'value' => '', 'compare' => '=' ],
        ],
    ];
    if (!empty($wordset_term_ids)) {
        $word_args['tax_query'] = [[ 'taxonomy' => 'wordset', 'field' => 'term_id', 'terms' => array_map('intval', $wordset_term_ids) ]];
    }
    $text_words = get_posts($word_args);
    foreach ($text_words as $word_id) {
        $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        $desired = ll_sort_recording_type_slugs($desired);
        $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($desired, $base_filtered)));
        if (empty($filtered_types)) { continue; }

        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        $missing = $types_equal_main
            ? ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_uid)
            : ll_get_missing_recording_types_for_word($word_id, $filtered_types);
        if (!empty($missing)) {
            $cats = wp_get_post_terms($word_id, 'word-category');
            if (!empty($cats) && !is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $categories[$cat->slug] = $cat->name;
                }
            } else {
                $has_uncategorized_items = true;
            }
        }
    }

    // Drop categories that don't actually have any items after applying filters.
    if (!empty($categories)) {
        $filtered_categories = [];
        foreach ($categories as $slug => $name) {
            if ($slug === 'uncategorized') {
                $filtered_categories[$slug] = $name;
                continue;
            }
            $maybe_images = ll_get_images_needing_audio($slug, $wordset_term_ids, $include_types_csv, $exclude_types_csv);
            if (!empty($maybe_images)) {
                $filtered_categories[$slug] = $name;
            }
        }
        $categories = $filtered_categories;
    }

    $uncat_images = ll_get_images_needing_audio('uncategorized', $wordset_term_ids, $include_types_csv, $exclude_types_csv);
    $has_uncategorized_items = !empty($uncat_images);

    $uncat_label = isset($categories['uncategorized']) ? $categories['uncategorized'] : __('Uncategorized', 'll-tools-text-domain');
    unset($categories['uncategorized']);

    if (!empty($categories)) {
        asort($categories, SORT_FLAG_CASE | SORT_NATURAL);
    }

    if ($has_uncategorized_items) {
        $categories = array_merge(['uncategorized' => $uncat_label], $categories);
    }

    return $categories;
}

/**
 * Diagnose why no categories are available and provide helpful feedback
 */
function ll_diagnose_no_categories($wordset_term_ids, $include_types_csv, $exclude_types_csv) {
    $messages = [];

    // Check if any word_images posts exist at all
    $total_images = wp_count_posts('word_images');
    $published_images = $total_images->publish ?? 0;

    if ($published_images === 0) {
        $messages[] = __('No word images have been created yet. Please create some word images first.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s">%s</a>',
            admin_url('post-new.php?post_type=word_images'),
            __('Create a word image', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Check what's actually being looked for by the recording interface
    $args = [
        'post_type' => 'word_images',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => '_thumbnail_id',
                'compare' => 'EXISTS',
            ],
        ],
    ];

    $images_with_featured = get_posts($args);

    if (empty($images_with_featured)) {
        $messages[] = sprintf(
            __('You have %d word image(s), but none have a featured image set.', 'll-tools-text-domain'),
            $published_images
        );
        $messages[] = __('<strong>To fix this:</strong> Edit each word image and set a featured image using the "Featured Image" panel on the right side of the editor.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('edit.php?post_type=word_images'),
            __('Edit Word Images', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Check if any have categories
    $images_with_categories = 0;
    $sample_categories = [];

    foreach ($images_with_featured as $img_id) {
        $categories = wp_get_post_terms($img_id, 'word-category');
        if (!is_wp_error($categories) && !empty($categories)) {
            $images_with_categories++;
            if (count($sample_categories) < 3) {
                foreach ($categories as $cat) {
                    $sample_categories[$cat->slug] = $cat->name;
                }
            }
        }
    }

    if ($images_with_categories === 0) {
        $messages[] = sprintf(
            __('You have %d word image(s) with featured images, but none are assigned to any word categories.', 'll-tools-text-domain'),
            count($images_with_featured)
        );
        $messages[] = __('<strong>To fix this:</strong> Edit each word image and assign it to at least one word category.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('edit.php?post_type=word_images'),
            __('Edit Word Images', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // At this point we have images with featured images and categories.
    // Check recording types.

    $all_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
        'fields' => 'slugs'
    ]);

    if (is_wp_error($all_types) || empty($all_types)) {
        $messages[] = __('No recording types are configured in your system.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('tools.php?page=ll-recording-types'),
            __('Set Up Recording Types', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Everything looks good but still no categories showing
    $messages[] = sprintf(
        __('You have %d word image(s) with featured images in categories (%s), but all images in the selected wordset already have recordings.', 'll-tools-text-domain'),
        $images_with_categories,
        implode(', ', array_slice($sample_categories, 0, 3)) . (count($sample_categories) > 3 ? '...' : '')
    );

    if (!empty($wordset_term_ids)) {
        $wordset = get_term($wordset_term_ids[0], 'wordset');
        if ($wordset && !is_wp_error($wordset)) {
            $messages[] = sprintf(__('Current wordset filter: <strong>%s</strong>', 'll-tools-text-domain'), $wordset->name);
        }
    }

    $messages[] = __('This means all available images already have the required recording types. Great work!', 'll-tools-text-domain');

    return '<p>' . implode('</p><p>', $messages) . '</p>';
}

/**
 * AJAX handler to get new images for a selected category
 */
add_action('wp_ajax_ll_get_images_for_recording', 'll_get_images_for_recording_handler');

function ll_get_images_for_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }
    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to record audio.', 'll-tools-text-domain'), 403);
    }

    if (!isset($_POST['category']) || !isset($_POST['wordset_ids'])) {
        wp_send_json_error(__('Missing parameters.', 'll-tools-text-domain'));
    }

    $category = sanitize_text_field($_POST['category']);
    $wordset_ids = json_decode(stripslashes($_POST['wordset_ids']), true);
    $wordset_term_ids = is_array($wordset_ids) ? array_map('intval', $wordset_ids) : [];
    $include_types = isset($_POST['include_types']) ? sanitize_text_field($_POST['include_types']) : '';
    $exclude_types = isset($_POST['exclude_types']) ? sanitize_text_field($_POST['exclude_types']) : '';

    $images = ll_get_images_needing_audio($category, $wordset_term_ids, $include_types, $exclude_types);

    if (empty($images)) {
        wp_send_json_success([
            'images' => [],
            'recording_types' => [],
        ]);
    }

    $recording_types = [];
    foreach ($images as $img) {
        if (is_array($img['missing_types'])) {
            $recording_types = array_merge($recording_types, $img['missing_types']);
        }
        if (is_array($img['existing_types'])) {
            $recording_types = array_merge($recording_types, $img['existing_types']);
        }
    }
    $dropdown_types = ll_build_recording_type_payload($recording_types);

    wp_send_json_success([
        'images' => $images,
        'recording_types' => $dropdown_types,
    ]);
}

// AJAX: hide a recorder word/image for the current user
add_action('wp_ajax_ll_hide_recording_word', 'll_hide_recording_word_handler');
function ll_hide_recording_word_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }
    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to record audio.', 'll-tools-text-domain'), 403);
    }

    $user_id = get_current_user_id();
    $word_id = absint($_POST['word_id'] ?? 0);
    $image_id = absint($_POST['image_id'] ?? 0);
    $title = sanitize_text_field((string) ($_POST['title'] ?? ($_POST['word_title'] ?? '')));
    $category_name = sanitize_text_field((string) ($_POST['category_name'] ?? ''));
    $category_slug = sanitize_title((string) ($_POST['category_slug'] ?? ''));

    $hide_key = ll_tools_sanitize_recording_hide_key($_POST['hide_key'] ?? '');
    if ($hide_key === '') {
        $hide_key = ll_tools_build_recording_hide_key($word_id, $image_id, $title);
    }
    if ($hide_key === '') {
        wp_send_json_error(__('Missing hide key.', 'll-tools-text-domain'));
    }

    $hidden_words = ll_tools_add_hidden_recording_word($user_id, [
        'key'           => $hide_key,
        'word_id'       => $word_id,
        'image_id'      => $image_id,
        'title'         => $title,
        'category_name' => $category_name,
        'category_slug' => $category_slug,
        'hidden_at'     => current_time('mysql'),
    ]);

    $entry = $hidden_words[$hide_key] ?? ll_tools_normalize_hidden_recording_entry([
        'key'           => $hide_key,
        'word_id'       => $word_id,
        'image_id'      => $image_id,
        'title'         => $title,
        'category_name' => $category_name,
        'category_slug' => $category_slug,
        'hidden_at'     => current_time('mysql'),
    ]);

    wp_send_json_success([
        'entry'        => $entry,
        'hidden_words' => array_values($hidden_words),
        'hidden_count' => count($hidden_words),
    ]);
}

// AJAX: unhide a recorder word/image for the current user
add_action('wp_ajax_ll_unhide_recording_word', 'll_unhide_recording_word_handler');
function ll_unhide_recording_word_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }
    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to record audio.', 'll-tools-text-domain'), 403);
    }

    $user_id = get_current_user_id();
    $hide_key = ll_tools_sanitize_recording_hide_key($_POST['hide_key'] ?? '');
    $word_id = absint($_POST['word_id'] ?? 0);
    $image_id = absint($_POST['image_id'] ?? 0);
    $title = sanitize_text_field((string) ($_POST['title'] ?? ''));

    if ($hide_key === '' && $word_id < 1 && $image_id < 1 && $title === '') {
        wp_send_json_error(__('Missing hide key.', 'll-tools-text-domain'));
    }

    $hidden_words = ll_tools_remove_hidden_recording_word($user_id, $hide_key, $word_id, $image_id, $title);

    wp_send_json_success([
        'hidden_words' => array_values($hidden_words),
        'hidden_count' => count($hidden_words),
    ]);
}

// AJAX: fetch desired recording types for a category (new-word preflight)
add_action('wp_ajax_ll_get_recording_types_for_category', 'll_get_recording_types_for_category_handler');

function ll_get_recording_types_for_category_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to record audio.', 'll-tools-text-domain'));
    }

    $category_slug = sanitize_text_field($_POST['category'] ?? 'uncategorized');
    $type_filters = ll_tools_get_recording_type_filters_from_request();
    $all_types = $type_filters['all_types'];
    if (empty($all_types)) {
        wp_send_json_error(__('No recording types are configured.', 'll-tools-text-domain'));
    }

    $category_term_id = 0;
    if (!empty($category_slug) && $category_slug !== 'uncategorized') {
        $category_term = get_term_by('slug', $category_slug, 'word-category');
        if ($category_term && !is_wp_error($category_term)) {
            $category_term_id = (int) $category_term->term_id;
        }
    } elseif ($category_slug === 'uncategorized') {
        $maybe_uncat = get_term_by('slug', 'uncategorized', 'word-category');
        if ($maybe_uncat && !is_wp_error($maybe_uncat)) {
            $category_term_id = (int) $maybe_uncat->term_id;
        }
    }

    if ($category_term_id && function_exists('ll_tools_is_category_recording_disabled') && ll_tools_is_category_recording_disabled($category_term_id)) {
        wp_send_json_error(__('Recording is disabled for this category.', 'll-tools-text-domain'));
    }

    $desired_types = [];
    if ($category_term_id) {
        $desired_types = ll_tools_get_desired_recording_types_for_category($category_term_id);
    }
    if (empty($desired_types)) {
        $desired_types = ll_tools_get_uncategorized_desired_recording_types();
    }

    $filtered_types = $type_filters['filtered_types'];
    $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($desired_types, $filtered_types)));

    if (empty($filtered_types)) {
        wp_send_json_error(__('No recording types are available for this category.', 'll-tools-text-domain'));
    }

    $dropdown_types = ll_build_recording_type_payload($filtered_types);

    wp_send_json_success([
        'recording_types' => $dropdown_types,
    ]);
}

// AJAX: prepare a new word (and optional category) for recording
add_action('wp_ajax_ll_prepare_new_word_recording', 'll_prepare_new_word_recording_handler');

function ll_prepare_new_word_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to record audio.', 'll-tools-text-domain'));
    }

    $config = function_exists('ll_get_user_recording_config') ? ll_get_user_recording_config(get_current_user_id()) : [];
    $allow_new = is_array($config) && !empty($config['allow_new_words']);
    if (!$allow_new && !current_user_can('manage_options')) {
        wp_send_json_error(__('New word recording is not enabled for your account.', 'll-tools-text-domain'));
    }

    $target_text_raw = sanitize_text_field($_POST['word_text_target'] ?? '');
    $target_text = ll_sanitize_word_title_text($target_text_raw);
    $translation_text = sanitize_text_field($_POST['word_text_translation'] ?? '');
    $translation_text = trim($translation_text);

    $category_slug = sanitize_text_field($_POST['category'] ?? 'uncategorized');
    $create_category = !empty($_POST['create_category']);
    $new_category_name = sanitize_text_field($_POST['new_category_name'] ?? '');

    $posted_ids = ll_tools_get_recording_wordset_ids_from_request();

    $type_filters = ll_tools_get_recording_type_filters_from_request();
    $all_types = $type_filters['all_types'];
    if (empty($all_types)) {
        wp_send_json_error(__('No recording types are configured.', 'll-tools-text-domain'));
    }

    $category_term = null;
    $category_name = __('Uncategorized', 'll-tools-text-domain');
    $category_slug_value = 'uncategorized';
    $category_term_id = 0;

    if ($create_category) {
        if ($new_category_name === '') {
            wp_send_json_error(__('Missing new category name.', 'll-tools-text-domain'));
        }
        $existing = term_exists($new_category_name, 'word-category');
        if (is_array($existing)) {
            $category_term_id = (int) $existing['term_id'];
        } elseif ($existing) {
            $category_term_id = (int) $existing;
        } else {
            $created = wp_insert_term($new_category_name, 'word-category');
            if (is_wp_error($created)) {
                wp_send_json_error(sprintf(
                    /* translators: %s: category creation error message */
                    __('Failed to create category: %s', 'll-tools-text-domain'),
                    $created->get_error_message()
                ));
            }
            $category_term_id = (int) $created['term_id'];
        }

        $selected_types = isset($_POST['new_category_types']) ? (array) $_POST['new_category_types'] : [];
        $selected_types = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_types))));
        $selected_types = array_values(array_intersect($selected_types, $all_types));
        if (empty($selected_types)) {
            $selected_types = in_array('isolation', $all_types, true) ? ['isolation'] : array_slice($all_types, 0, 1);
        }
        update_term_meta($category_term_id, 'll_desired_recording_types', $selected_types);

        $category_term = get_term($category_term_id, 'word-category');
    } elseif (!empty($category_slug) && $category_slug !== 'uncategorized') {
        $category_term = get_term_by('slug', $category_slug, 'word-category');
    } elseif ($category_slug === 'uncategorized') {
        $maybe_uncat = get_term_by('slug', 'uncategorized', 'word-category');
        if ($maybe_uncat && !is_wp_error($maybe_uncat)) {
            $category_term = $maybe_uncat;
        }
    }

    if ($category_term && !is_wp_error($category_term)) {
        $category_term_id = (int) $category_term->term_id;
        $category_name = $category_term->name;
        $category_slug_value = $category_term->slug;
    }

    if ($category_term_id && function_exists('ll_tools_is_category_recording_disabled') && ll_tools_is_category_recording_disabled($category_term_id)) {
        wp_send_json_error(__('Recording is disabled for this category.', 'll-tools-text-domain'));
    }

    $desired_types = [];
    if ($category_term_id) {
        $desired_types = ll_tools_get_desired_recording_types_for_category($category_term_id);
    }
    if (empty($desired_types)) {
        $desired_types = ll_tools_get_uncategorized_desired_recording_types();
    }

    $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($desired_types, $type_filters['filtered_types'])));

    if (empty($filtered_types)) {
        wp_send_json_error(__('No recording types are available for this category.', 'll-tools-text-domain'));
    }

    $store_in_title = (get_option('ll_word_title_language_role', 'target') === 'target');
    if ($create_category) {
        $store_in_title = true;
    } elseif ($category_term && function_exists('ll_tools_get_category_quiz_config')) {
        $cat_cfg = ll_tools_get_category_quiz_config($category_term);
        $opt_type = isset($cat_cfg['option_type']) ? (string) $cat_cfg['option_type'] : '';
        if ($opt_type === 'text_title') {
            $store_in_title = true;
        } elseif (in_array($opt_type, ['text_translation', 'text_audio'], true)) {
            $store_in_title = false;
        }
    }

    $placeholder = sprintf(
        __('New word %s', 'll-tools-text-domain'),
        date_i18n('Y-m-d H:i', current_time('timestamp'))
    );
    if ($store_in_title) {
        $post_title = $target_text !== '' ? $target_text : $placeholder;
    } else {
        $post_title = $translation_text !== '' ? $translation_text : $placeholder;
    }

    $word_id = wp_insert_post([
        'post_title'  => $post_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id) || !$word_id) {
        $err = is_wp_error($word_id) ? $word_id->get_error_message() : 'Unknown error';
        wp_send_json_error(sprintf(
            /* translators: %s: word creation error */
            __('Failed to create word: %s', 'll-tools-text-domain'),
            $err
        ));
    }

    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        }
    } else {
        if ($target_text !== '') {
            update_post_meta($word_id, 'word_translation', $target_text);
        }
    }

    if ($category_term_id) {
        wp_set_object_terms($word_id, [$category_term_id], 'word-category');
    }

    if (!empty($posted_ids)) {
        wp_set_object_terms($word_id, array_map('intval', $posted_ids), 'wordset');
    }

    $display_text = $post_title;

    $dropdown_types = ll_build_recording_type_payload($filtered_types);

    $item = [
        'id'               => 0,
        'title'            => $display_text,
        'image_url'        => '',
        'category_name'    => $category_name,
        'category_slug'    => $category_slug_value,
        'word_id'          => (int) $word_id,
        'word_title'       => $display_text,
        'word_translation' => $store_in_title
            ? ($translation_text !== '' ? $translation_text : '')
            : ($target_text !== '' ? $target_text : ''),
        'use_word_display' => true,
        'missing_types'    => ll_sort_recording_type_slugs($filtered_types),
        'existing_types'   => [],
        'is_text_only'     => true,
    ];

    wp_send_json_success([
        'word' => $item,
        'recording_types' => $dropdown_types,
        'category' => [
            'slug' => $category_slug_value,
            'name' => $category_name,
            'term_id' => $category_term_id,
        ],
    ]);
}

/**
 * Normalize a language code for external APIs (AssemblyAI/DeepL).
 *
 * @param string $raw
 * @param string $case lower|upper
 * @return string
 */
function ll_tools_normalize_language_code($raw, $case = 'lower') {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('_', '-', $raw);
    $raw = preg_replace('/[^A-Za-z\-]+/', '', $raw);
    if (strpos($raw, '-') !== false) {
        $parts = explode('-', $raw);
        $raw = $parts[0];
    }

    $raw_lower = strtolower($raw);
    if ($raw_lower === 'auto') {
        return 'auto';
    }

    $iso3_map = [
        'eng' => 'en',
        'tur' => 'tr',
        'deu' => 'de',
        'ger' => 'de',
        'fra' => 'fr',
        'fre' => 'fr',
        'spa' => 'es',
        'ita' => 'it',
        'por' => 'pt',
        'rus' => 'ru',
        'ara' => 'ar',
        'hin' => 'hi',
        'jpn' => 'ja',
        'kor' => 'ko',
        'zho' => 'zh',
        'cmn' => 'zh',
        'vie' => 'vi',
        'nld' => 'nl',
        'dut' => 'nl',
        'swe' => 'sv',
        'dan' => 'da',
        'fin' => 'fi',
        'nor' => 'no',
        'pol' => 'pl',
        'ces' => 'cs',
        'cze' => 'cs',
        'slk' => 'sk',
        'hun' => 'hu',
        'ron' => 'ro',
        'rum' => 'ro',
        'bul' => 'bg',
        'ukr' => 'uk',
        'heb' => 'he',
        'ind' => 'id',
        'msa' => 'ms',
        'tha' => 'th',
    ];

    if (strlen($raw_lower) === 3 && isset($iso3_map[$raw_lower])) {
        $raw_lower = $iso3_map[$raw_lower];
    }

    if (strlen($raw_lower) > 3) {
        return '';
    }

    return ($case === 'upper') ? strtoupper($raw_lower) : $raw_lower;
}

/**
 * Resolve a language code from labels, IDs, or taxonomy names.
 *
 * @param string $raw
 * @param string $case lower|upper
 * @return string
 */
function ll_tools_resolve_language_code_from_label($raw, $case = 'lower') {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }

    $normalized = ll_tools_normalize_language_code($raw, $case);
    if ($normalized !== '' && $normalized !== 'auto') {
        return $normalized;
    }

    $term = null;
    if (ctype_digit($raw)) {
        $term = get_term((int) $raw, 'language');
    }
    if (!$term || is_wp_error($term)) {
        $term = get_term_by('slug', sanitize_title($raw), 'language');
    }
    if (!$term || is_wp_error($term)) {
        $term = get_term_by('name', $raw, 'language');
    }
    if ($term && !is_wp_error($term)) {
        $normalized = ll_tools_normalize_language_code((string) $term->slug, $case);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

/**
 * Get the wordset language label for the current recording scope.
 *
 * @param array $wordset_ids
 * @return string
 */
function ll_tools_get_wordset_language_label($wordset_ids) {
    if (!is_array($wordset_ids)) {
        return '';
    }

    foreach ($wordset_ids as $wsid) {
        $lang = function_exists('ll_get_wordset_language') ? ll_get_wordset_language((int) $wsid) : '';
        if (!empty($lang)) {
            return $lang;
        }
    }

    return '';
}

/**
 * Resolve AssemblyAI language code from wordset or settings.
 *
 * @param array $wordset_ids
 * @return string
 */
function ll_tools_get_assemblyai_language_code($wordset_ids) {
    $raw = ll_tools_get_wordset_language_label($wordset_ids);
    if ($raw === '') {
        $raw = (string) get_option('ll_target_language', '');
    }

    $normalized = ll_tools_resolve_language_code_from_label($raw, 'lower');
    if ($normalized !== '' && $normalized !== 'auto' && strlen($normalized) !== 2) {
        $normalized = '';
    }
    if ($normalized === 'auto') {
        $normalized = '';
    }
    $normalized = apply_filters('ll_tools_assemblyai_language_code', $normalized, $raw, $wordset_ids);
    return $normalized;
}

/**
 * Resolve DeepL source/target language codes.
 *
 * @param array $wordset_ids
 * @return array [source, target]
 */
function ll_tools_get_deepl_language_codes($wordset_ids) {
    $source_raw = ll_tools_get_wordset_language_label($wordset_ids);
    if ($source_raw === '') {
        $source_raw = (string) get_option('ll_target_language', 'auto');
    }
    $target_raw = (string) get_option('ll_translation_language', '');

    $source = ll_tools_resolve_language_code_from_label($source_raw, 'upper');
    if ($source === 'AUTO') {
        $source = 'auto';
    }
    $target = ll_tools_resolve_language_code_from_label($target_raw, 'upper');

    return [$source, $target];
}

/**
 * Determine whether a word should store the target language in the title.
 *
 * @param int $word_id
 * @return bool
 */
function ll_tools_should_store_word_in_title($word_id) {
    $store_in_title = (get_option('ll_word_title_language_role', 'target') === 'target');
    $terms = wp_get_post_terms($word_id, 'word-category');
    if (!empty($terms) && !is_wp_error($terms) && function_exists('ll_tools_get_category_quiz_config')) {
        $cat_cfg = ll_tools_get_category_quiz_config($terms[0]);
        $opt_type = isset($cat_cfg['option_type']) ? (string) $cat_cfg['option_type'] : '';
        if ($opt_type === 'text_title') {
            $store_in_title = true;
        } elseif (in_array($opt_type, ['text_translation', 'text_audio'], true)) {
            $store_in_title = false;
        }
    }
    return $store_in_title;
}

// AJAX: update word title/translation text after transcription/manual edits
add_action('wp_ajax_ll_update_new_word_text', 'll_update_new_word_text_handler');
function ll_update_new_word_text_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }

    $word_id = intval($_POST['word_id'] ?? 0);
    if (!$word_id) {
        wp_send_json_error(__('Missing word ID.', 'll-tools-text-domain'));
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error(__('Invalid word ID.', 'll-tools-text-domain'));
    }

    $current_user_id = get_current_user_id();
    $can_edit_word = current_user_can('edit_post', $word_id);
    $owns_editable_draft = ((int) $word_post->post_author === $current_user_id)
        && in_array($word_post->post_status, ['draft', 'pending', 'auto-draft'], true);
    if (!$can_edit_word && !$owns_editable_draft) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }

    $target_text_raw = sanitize_text_field($_POST['word_text_target'] ?? '');
    $target_text = ll_sanitize_word_title_text($target_text_raw);
    $translation_text = sanitize_text_field($_POST['word_text_translation'] ?? '');
    $translation_text = trim($translation_text);

    $store_in_title = ll_tools_should_store_word_in_title($word_id);
    $new_title = $word_post->post_title;
    if ($store_in_title && $target_text !== '') {
        $new_title = $target_text;
    } elseif (!$store_in_title && $translation_text !== '') {
        $new_title = $translation_text;
    }

    if ($new_title !== $word_post->post_title) {
        wp_update_post([
            'ID'         => $word_id,
            'post_title' => $new_title,
        ]);
    }

    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        }
    } else {
        if ($target_text !== '') {
            update_post_meta($word_id, 'word_translation', $target_text);
        }
    }

    $saved_translation = get_post_meta($word_id, 'word_translation', true);
    if ($saved_translation === '') {
        $saved_translation = get_post_meta($word_id, 'word_english_meaning', true);
    }

    wp_send_json_success([
        'word_id'         => $word_id,
        'post_title'      => $new_title,
        'word_translation'=> $saved_translation,
        'store_in_title'  => $store_in_title,
    ]);
}

/**
 * Resolve wordset term IDs from recorder AJAX requests.
 *
 * @return int[]
 */
function ll_tools_get_recording_wordset_ids_from_request() {
    $posted_ids = [];
    if (isset($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) {
            $posted_ids = array_map('intval', $decoded);
        }
    }

    $wordset_spec = sanitize_text_field($_POST['wordset'] ?? '');
    if (empty($posted_ids)) {
        $posted_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    return array_values(array_filter(array_map('intval', (array) $posted_ids), static function ($id) {
        return $id > 0;
    }));
}

/**
 * Convert a WP_Error to the recorder AJAX error format.
 *
 * @param WP_Error $error
 * @return void
 */
function ll_tools_send_recording_error($error) {
    if (is_wp_error($error)) {
        wp_send_json_error([
            'code' => (string) $error->get_error_code(),
            'message' => (string) $error->get_error_message(),
        ]);
    }

    wp_send_json_error([
        'code' => 'unknown_error',
        'message' => __('Unknown recorder error.', 'll-tools-text-domain'),
    ]);
}

/**
 * Build recorder response payload from AssemblyAI text and optional DeepL translation.
 *
 * @param string $raw_transcript
 * @param array  $posted_ids
 * @param string $language_code
 * @return array|WP_Error
 */
function ll_tools_prepare_transcription_response_data($raw_transcript, $posted_ids, $language_code = '') {
    $transcript = trim((string) $raw_transcript);
    if ($transcript !== '' && function_exists('ll_tools_normalize_transcript_case')) {
        $transcript = ll_tools_normalize_transcript_case($transcript, $posted_ids);
    }

    $transcript = sanitize_text_field($transcript);
    if ($transcript === '') {
        return new WP_Error('empty_transcript', __('No transcript text returned.', 'll-tools-text-domain'));
    }

    $trimmed = trim($transcript);
    $word_count = $trimmed === '' ? 0 : count(preg_split('/\s+/', $trimmed));
    if ($word_count > 0 && $word_count <= 3) {
        $transcript = preg_replace('/\.\s*$/', '', $trimmed);
    }

    $translation = '';
    $source_lang = '';
    $target_lang = '';
    $has_deepl = ((string) get_option('ll_deepl_api_key') !== '');
    if ($has_deepl && function_exists('translate_with_deepl')) {
        [$source_lang, $target_lang] = ll_tools_get_deepl_language_codes($posted_ids);
        if ($target_lang !== '') {
            $translated = translate_with_deepl($transcript, $target_lang, $source_lang);
            if ($translated !== null) {
                $translation = sanitize_text_field($translated);
            }
        }
    }

    return [
        'transcript'      => $transcript,
        'translation'     => $translation,
        'source_language' => $source_lang,
        'target_language' => $target_lang,
        'language_code'   => $language_code,
    ];
}

// AJAX: start transcription with AssemblyAI, optionally translate via DeepL
add_action('wp_ajax_ll_transcribe_recording', 'll_transcribe_recording_handler');
function ll_transcribe_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }

    if (empty($_FILES['audio'])) {
        wp_send_json_error(__('Missing audio file.', 'll-tools-text-domain'));
    }

    $posted_ids = ll_tools_get_recording_wordset_ids_from_request();
    $language_code = ll_tools_get_assemblyai_language_code($posted_ids);

    if (!function_exists('ll_tools_assemblyai_start_transcription') || !function_exists('ll_tools_assemblyai_get_transcript')) {
        wp_send_json_error(__('AssemblyAI integration not available.', 'll-tools-text-domain'));
    }

    $transcript_id = ll_tools_assemblyai_start_transcription($_FILES['audio']['tmp_name'], $language_code);
    if (is_wp_error($transcript_id)) {
        ll_tools_send_recording_error($transcript_id);
    }

    $status = ll_tools_assemblyai_get_transcript($transcript_id);
    if (is_wp_error($status)) {
        ll_tools_send_recording_error($status);
    }

    $state = isset($status['status']) ? (string) $status['status'] : '';
    if ($state === 'completed') {
        $payload = ll_tools_prepare_transcription_response_data($status['text'] ?? '', $posted_ids, $language_code);
        if (is_wp_error($payload)) {
            ll_tools_send_recording_error($payload);
        }

        $payload['pending'] = false;
        $payload['transcript_id'] = (string) $transcript_id;
        $payload['status'] = 'completed';
        wp_send_json_success($payload);
    }

    if ($state === 'error') {
        $message = isset($status['error']) ? (string) $status['error'] : __('AssemblyAI transcription failed.', 'll-tools-text-domain');
        wp_send_json_error([
            'code' => 'transcript_error',
            'message' => $message,
        ]);
    }

    wp_send_json_success([
        'pending'         => true,
        'transcript_id'   => (string) $transcript_id,
        'status'          => ($state !== '' ? $state : 'processing'),
        'language_code'   => $language_code,
    ]);
}

// AJAX: poll AssemblyAI transcript status for recorder flow
add_action('wp_ajax_ll_transcribe_recording_status', 'll_transcribe_recording_status_handler');
function ll_transcribe_recording_status_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }

    $transcript_id = sanitize_text_field($_POST['transcript_id'] ?? '');
    if ($transcript_id === '') {
        wp_send_json_error(__('Missing transcript ID.', 'll-tools-text-domain'));
    }

    if (!function_exists('ll_tools_assemblyai_get_transcript')) {
        wp_send_json_error(__('AssemblyAI integration not available.', 'll-tools-text-domain'));
    }

    $posted_ids = ll_tools_get_recording_wordset_ids_from_request();
    $language_code = ll_tools_get_assemblyai_language_code($posted_ids);
    $status = ll_tools_assemblyai_get_transcript($transcript_id);
    if (is_wp_error($status)) {
        ll_tools_send_recording_error($status);
    }

    $state = isset($status['status']) ? (string) $status['status'] : '';
    if ($state === 'completed') {
        $payload = ll_tools_prepare_transcription_response_data($status['text'] ?? '', $posted_ids, $language_code);
        if (is_wp_error($payload)) {
            ll_tools_send_recording_error($payload);
        }

        $payload['pending'] = false;
        $payload['transcript_id'] = $transcript_id;
        $payload['status'] = 'completed';
        wp_send_json_success($payload);
    }

    if ($state === 'error') {
        $message = isset($status['error']) ? (string) $status['error'] : __('AssemblyAI transcription failed.', 'll-tools-text-domain');
        wp_send_json_error([
            'code' => 'transcript_error',
            'message' => $message,
        ]);
    }

    wp_send_json_success([
        'pending'       => true,
        'transcript_id' => $transcript_id,
        'status'        => ($state !== '' ? $state : 'processing'),
        'language_code' => $language_code,
    ]);
}

// AJAX: translate text via DeepL (manual fallback)
add_action('wp_ajax_ll_translate_recording_text', 'll_translate_recording_text_handler');
function ll_translate_recording_text_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in.', 'll-tools-text-domain'));
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }

    $text = sanitize_text_field($_POST['text'] ?? '');
    if ($text === '') {
        wp_send_json_error(__('Missing text.', 'll-tools-text-domain'));
    }

    $has_deepl = ((string) get_option('ll_deepl_api_key') !== '');
    if (!$has_deepl || !function_exists('translate_with_deepl')) {
        wp_send_json_error([
            'code' => 'missing_key',
            'message' => __('DeepL API key not configured.', 'll-tools-text-domain'),
        ]);
    }

    $posted_ids = ll_tools_get_recording_wordset_ids_from_request();

    [$source_lang, $target_lang] = ll_tools_get_deepl_language_codes($posted_ids);
    if ($target_lang === '') {
        wp_send_json_error(__('Translation language is not configured.', 'll-tools-text-domain'));
    }

    $translated = translate_with_deepl($text, $target_lang, $source_lang);
    if ($translated === null) {
        wp_send_json_error(__('Translation failed.', 'll-tools-text-domain'));
    }

    wp_send_json_success([
        'translation'     => sanitize_text_field($translated),
        'source_language' => $source_lang,
        'target_language' => $target_lang,
    ]);
}

// AJAX: verify a recording exists after a possibly misleading 500
add_action('wp_ajax_ll_verify_recording', 'll_verify_recording_handler');

function ll_verify_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in to verify recordings.', 'll-tools-text-domain'));
    }
    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to verify recordings.', 'll-tools-text-domain'), 403);
    }

    $image_id       = intval($_POST['image_id'] ?? 0);
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');
    $wordset_ids    = ll_tools_get_recording_wordset_ids_from_request();
    $type_filters   = ll_tools_get_recording_type_filters_from_request();
    $filtered_types = $type_filters['filtered_types'];

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error(__('Missing image_id or word_id.', 'll-tools-text-domain'));
        }
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            wp_send_json_error(__('Invalid image ID.', 'll-tools-text-domain'));
        }
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $wordset_ids);
        if (is_wp_error($word_id)) {
            wp_send_json_error(sprintf(
                /* translators: %s: word creation error */
                __('Failed to find/create word: %s', 'll-tools-text-domain'),
                $word_id->get_error_message()
            ));
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                wp_send_json_error(__('Invalid word ID.', 'll-tools-text-domain'));
            }
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $wordset_ids);
            if (is_wp_error($created_word_id)) {
                wp_send_json_error(sprintf(
                    /* translators: %s: word creation error */
                    __('Failed to create word: %s', 'll-tools-text-domain'),
                    $created_word_id->get_error_message()
                ));
            }
            $word_id = (int) $created_word_id;
        }
    }

    // Rebuild filtered type list just like the UI
    // Look for a recent child "word_audio" with this type
    $args = [
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish','private','inherit'],
        'perm'           => 'any', // allow detection even if the current user can't read drafts
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_parent'    => $word_id,
        'tax_query'      => [[
            'taxonomy' => 'recording_type',
            'field'    => 'slug',
            'terms'    => $recording_type ? [$recording_type] : $filtered_types,
        ]],
    ];
    $latest = get_posts($args);
    $audio_post_id = !empty($latest) ? (int) $latest[0] : 0;

    // Remaining types (computed with the same filtered list)
    // Recompute remaining types, respecting desired types and only applying single-speaker logic
    // when the full main set is requested.
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $desired_word = ll_sort_recording_type_slugs($desired_word);
    $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($filtered_types, $desired_word)));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, get_current_user_id());
        }
    } else {
        // For subset requests (e.g., isolation-only), consider any existing recording sufficient
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }

    // If this exact type was requested and it still appears in remaining, then treat as not found.
    $found = 0;
    if ($audio_post_id) {
        if ($recording_type && in_array($recording_type, $remaining_missing, true)) {
            $found = 0;
        } else {
            $found = $audio_post_id;
        }
    }

    wp_send_json_success([
        'found_audio_post_id' => $found,
        'word_id'             => $word_id,
        'remaining_types'     => ll_sort_recording_type_slugs($remaining_missing),
    ]);
}

/**
 * Return the earliest-created wordset term_id (approximate via lowest term_id).
 */
function ll_get_default_wordset_term_id() {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'id',   // lowest term_id first
        'order'      => 'ASC',
        'number'     => 1,
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
        return (int) $terms[0]->term_id;
    }
    return 0;
}

/**
 * Resolve explicit wordset spec to term IDs, otherwise fall back to default wordset.
 */
function ll_resolve_wordset_term_ids_or_default($wordset_spec) {
    $ids = [];
    if (!empty($wordset_spec) && function_exists('ll_raw_resolve_wordset_term_ids')) {
        $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
    }
    if (empty($ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $ids = [$default_id];
        }
    }
    return array_map('intval', $ids);
}

/**
 * Get word images that need audio recordings for a specific wordset (by term IDs),
 * returning per-image missing/existing recording types so the UI can prompt for each type.
 *
 * @param string $category_slug
 * @param array  $wordset_term_ids
 * @param string $include_types_csv Comma-separated slugs to include
 * @param string $exclude_types_csv Comma-separated slugs to exclude
 * @param bool   $include_hidden Whether to include words hidden by the current recorder.
 * @return array [
 *   [
 *     'id'            => int,
 *     'title'         => string,
 *     'image_url'     => string,
 *     'category_name' => string,
 *     'word_id'       => int|null,      // the word in this wordset that uses the image (if any)
 *     'word_title'    => string|null,   // NEW: word post title (target lang, preferred)
 *     'word_translation' => string|null, // NEW: word's English meaning (fallback)
 *     'use_word_display' => bool,       // NEW: true if word data is preferred over image title
 *     'category_slug' => string,        // category slug or "uncategorized" placeholder
 *     'missing_types' => string[],       // recording_type slugs still needed (filtered)
 *     'existing_types'=> string[],       // recording_type slugs already present (not filtered, all)
 *     'hide_key'      => string,         // stable key used for per-user hide/unhide
 *   ],
 *   ...
 * ]
 */
function ll_get_images_needing_audio($category_slug = '', $wordset_term_ids = [], $include_types_csv = '', $exclude_types_csv = '', $include_hidden = false) {
    if (empty($wordset_term_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_term_ids = [$default_id];
        }
    }

    $is_uncategorized_request = ($category_slug === 'uncategorized');
    $uncategorized_label = __('Uncategorized', 'll-tools-text-domain');

    $all_types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'fields'     => 'slugs',
    ]);
    if (is_wp_error($all_types) || empty($all_types)) {
        $all_types = [];
    }

    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_intersect($filtered_types, $include_types);
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_diff($filtered_types, $exclude_types);
    }
    $filtered_types = ll_sort_recording_type_slugs(array_values($filtered_types));

    if (empty($filtered_types)) {
        return [];
    }
    $items_by_category = [];
    $missing_audio_instances = get_option('ll_missing_audio_instances', []);
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $maybe_active = get_term_by('slug', (string) $category_slug, 'word-category');
        if ($maybe_active && !is_wp_error($maybe_active)) {
            $active_category_term = $maybe_active;
        }
    }

    $image_args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ];

    if (!empty($category_slug) && !$is_uncategorized_request) {
        $image_args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ]];
    }

    $image_posts = get_posts($image_args);

    // Category-specific fallback:
    // Include image posts referenced by words in this category even when those image posts
    // were not categorized yet (common after category-duplication workflows).
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $word_image_word_query = [
            'post_type'      => 'words',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_thumbnail_id',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
            'tax_query'      => [
                [
                    'taxonomy' => 'word-category',
                    'field'    => 'slug',
                    'terms'    => [$category_slug],
                ],
            ],
        ];
        if (!empty($wordset_term_ids)) {
            $word_image_word_query['tax_query'][] = [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $wordset_term_ids),
            ];
            $word_image_word_query['tax_query']['relation'] = 'AND';
        }

        $word_ids_for_category = get_posts($word_image_word_query);
        if (!empty($word_ids_for_category)) {
            $thumb_ids = [];
            foreach ($word_ids_for_category as $wid) {
                $thumb_id = (int) get_post_thumbnail_id((int) $wid);
                if ($thumb_id > 0) {
                    $thumb_ids[$thumb_id] = true;
                }
            }
            $thumb_ids = array_keys($thumb_ids);

            if (!empty($thumb_ids)) {
                $fallback_image_posts = get_posts([
                    'post_type'      => 'word_images',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => [[
                        'key'     => '_thumbnail_id',
                        'value'   => array_map('intval', $thumb_ids),
                        'compare' => 'IN',
                    ]],
                ]);
                if (!empty($fallback_image_posts)) {
                    $image_posts = array_values(array_unique(array_merge(
                        array_map('intval', (array) $image_posts),
                        array_map('intval', (array) $fallback_image_posts)
                    )));
                }
            }
        }
    }

    foreach ($image_posts as $img_id) {
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);

        // NEW: Enrich with word display data (title or translation)
        $word_title = null;
        $word_translation = null;
        $use_word_display = false;
        $title_role = get_option('ll_word_title_language_role', 'target');
        if ($word_id) {
            $word_post = get_post($word_id);
            if ($word_post && $word_post->post_type === 'words') {
                $use_word_display = true;
                $word_title = get_the_title($word_id);
                $word_translation = get_post_meta($word_id, 'word_translation', true);
                if ($word_translation === '') {
                    $word_translation = get_post_meta($word_id, 'word_english_meaning', true);
                }
                // If titles are in translation language (helper) and we have a learned-language translation,
                // prefer showing the translation for recording UI.
                if ($title_role === 'translation' && !empty($word_translation)) {
                    $word_title = $word_translation;
                }
            }
        }

        // Determine desired + filtered types for this item
        $desired = [];
        $has_disabled_cat = false;
        if ($word_id) {
            $category_disabled = false;
            // If a specific category is being recorded, respect only that category's settings
            if (!empty($category_slug) && !$is_uncategorized_request) {
                $cat_term = get_term_by('slug', $category_slug, 'word-category');
                if ($cat_term && !is_wp_error($cat_term)) {
                    if (ll_tools_is_category_recording_disabled((int) $cat_term->term_id)) {
                        $category_disabled = true;
                    } else {
                        $desired = ll_tools_get_desired_recording_types_for_category((int) $cat_term->term_id);
                    }
                }
            }
            // Fallback to union across the word's categories when no specific category context
            if (empty($desired) && !$category_disabled) {
                $desired = ll_tools_get_desired_recording_types_for_word($word_id);
            }
            if ($category_disabled && empty($desired)) {
                $has_disabled_cat = true;
            }
        } else {
            $term_ids = wp_get_post_terms($img_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                foreach ($term_ids as $tid) {
                    if (ll_tools_is_category_recording_disabled($tid)) {
                        $has_disabled_cat = true;
                        continue;
                    }
                    $desired = array_merge($desired, ll_tools_get_desired_recording_types_for_category($tid));
                }
            }
        }
        if (empty($desired)) {
            if ($has_disabled_cat) {
                $desired = [];
            } else {
                $desired = ll_tools_get_uncategorized_desired_recording_types();
            }
        }
        $desired = ll_sort_recording_type_slugs($desired);
        $types_for_item = ll_sort_recording_type_slugs(array_values(array_intersect($desired, $filtered_types)));

        // Decide whether to enforce single-speaker gating and per-user missing logic
        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($types_for_item, $main_types)) && empty(array_diff($main_types, $types_for_item));

        // If a complete speaker exists and we're asking for all main types, skip this item entirely
        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) {
            continue;
        }

        if ($word_id) {
            if ($types_equal_main) {
                // Encourage a single speaker to complete the full set
                $current_uid = get_current_user_id();
                $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_item, $current_uid);
            } else {
                // For subset requests (e.g., isolation-only), consider any existing recording sufficient
                $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_item);
            }
            $existing_types = ll_get_existing_recording_types_for_word($word_id);
        } else {
            $missing_types = $types_for_item;
            $existing_types = [];
        }
        $missing_types = ll_sort_recording_type_slugs($missing_types);
        $existing_types = ll_sort_recording_type_slugs($existing_types);

        if (!empty($missing_types)) {
            $thumb_url = get_the_post_thumbnail_url($img_id, 'large');
            if ($thumb_url) {
                if (isset($active_category_term) && $active_category_term) {
                    $category_name       = $active_category_term->name;
                    $category_slug_value = $active_category_term->slug;
                } else {
                    $categories = wp_get_post_terms($img_id, 'word-category');
                    if (!empty($categories) && !is_wp_error($categories)) {
                        $category            = $categories[0];
                        $category_name       = $category->name;
                        $category_slug_value = $category->slug;
                    } else {
                        $category_name       = $uncategorized_label;
                        $category_slug_value = 'uncategorized';
                    }
                }

                if (!isset($items_by_category[$category_slug_value])) {
                    $items_by_category[$category_slug_value] = [
                        'name'  => $category_name,
                        'slug'  => $category_slug_value,
                        'items' => [],
                    ];
                }

                // Try to find a translation for image-only cases when titles are helper-language
                $img_translation = get_post_meta($img_id, 'word_translation', true);
                if ($img_translation === '') {
                    $img_translation = get_post_meta($img_id, 'word_english_meaning', true);
                }

                if (!$word_id && $title_role === 'translation' && empty($img_translation)) {
                    // Search for a word with the same title in the same wordset or same-language wordsets
                    $image_title = get_the_title($img_id);
                    $allowed_wordset_ids = array_map('intval', $wordset_term_ids);
                    // Expand to wordsets with the same language label(s)
                    $langs = [];
                    foreach ((array)$wordset_term_ids as $wsid) {
                        $lang = function_exists('ll_get_wordset_language') ? ll_get_wordset_language($wsid) : '';
                        if (!empty($lang)) $langs[$lang] = true;
                    }
                    if (!empty($langs)) {
                        $lang_list = array_keys($langs);
                        // Fetch wordsets that share the same language meta
                        $mq = ['relation' => (count($lang_list) > 1 ? 'OR' : 'AND')];
                        foreach ($lang_list as $l) { $mq[] = ['key' => 'll_language', 'value' => $l, 'compare' => '=']; }
                        $lang_sets = get_terms([
                            'taxonomy'   => 'wordset',
                            'hide_empty' => false,
                            'fields'     => 'ids',
                            'meta_query' => $mq,
                        ]);
                        if (!is_wp_error($lang_sets) && !empty($lang_sets)) {
                            $allowed_wordset_ids = array_values(array_unique(array_merge($allowed_wordset_ids, array_map('intval', $lang_sets))));
                        }
                    }

                    $related_words = get_posts([
                        'post_type'      => 'words',
                        'post_status'    => ['publish','draft','pending'],
                        'posts_per_page' => 25,
                        's'              => $image_title,
                        'fields'         => 'ids',
                        'tax_query'      => !empty($allowed_wordset_ids) ? [[
                            'taxonomy' => 'wordset',
                            'field'    => 'term_id',
                            'terms'    => $allowed_wordset_ids,
                        ]] : [],
                    ]);
                    if (!is_wp_error($related_words) && !empty($related_words)) {
                        foreach ($related_words as $rw_id) {
                            if (strcasecmp(get_the_title($rw_id), $image_title) === 0) {
                                $img_translation = get_post_meta($rw_id, 'word_translation', true);
                                if ($img_translation === '') {
                                    $img_translation = get_post_meta($rw_id, 'word_english_meaning', true);
                                }
                                if (!empty($img_translation)) {
                                    break;
                                }
                            }
                        }
                    }
                }

                $items_by_category[$category_slug_value]['items'][] = [
                    'id'               => $img_id,
                    'title'            => ($title_role === 'translation' && !empty($img_translation)) ? $img_translation : get_the_title($img_id),
                    'image_url'        => $thumb_url,
                    'category_name'    => $category_name,
                    'category_slug'    => $category_slug_value,
                    'word_id'          => $word_id ?: 0,
                    'word_title'       => $word_title,
                    'word_translation' => $word_translation,
                    'use_word_display' => ($use_word_display || ($title_role === 'translation' && (!empty($img_translation) || !empty($word_translation)))),
                    'missing_types'    => $missing_types,
                    'existing_types'   => $existing_types,
                    'is_text_only'     => false,
                ];
            }
        }
    }

    $word_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_thumbnail_id',
                'value'   => '',
                'compare' => '=',
            ],
        ],
    ];

    if (!empty($wordset_term_ids)) {
        $word_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_term_ids),
        ]];
    }

    if (!empty($category_slug) && !$is_uncategorized_request) {
        if (empty($word_args['tax_query'])) {
            $word_args['tax_query'] = [];
        }
        $word_args['tax_query'][] = [
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ];
        if (count($word_args['tax_query']) > 1) {
            $word_args['tax_query']['relation'] = 'AND';
        }
    }

    $text_words = get_posts($word_args);

    foreach ($text_words as $word_id) {
        // Respect category-specific desired types when a category is targeted
        $desired = [];
        $category_disabled = false;
        if (!empty($category_slug) && !$is_uncategorized_request) {
            $cat_term = get_term_by('slug', $category_slug, 'word-category');
            if ($cat_term && !is_wp_error($cat_term)) {
                if (ll_tools_is_category_recording_disabled((int) $cat_term->term_id)) {
                    $category_disabled = true;
                } else {
                    $desired = ll_tools_get_desired_recording_types_for_category((int) $cat_term->term_id);
                }
            }
        }
        if (empty($desired) && !$category_disabled) {
            $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        }
        $desired = ll_sort_recording_type_slugs($desired);
        $types_for_word = ll_sort_recording_type_slugs(array_values(array_intersect($desired, $filtered_types)));
        if (empty($types_for_word)) { continue; }

        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($types_for_word, $main_types)) && empty(array_diff($main_types, $types_for_word));

        // Skip only if complete speaker exists AND we’re collecting the full main set
        if ($types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        if ($types_equal_main) {
            $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_word, get_current_user_id());
        } else {
            $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_word);
        }
        $existing_types = ll_get_existing_recording_types_for_word($word_id);
        $missing_types = ll_sort_recording_type_slugs($missing_types);
        $existing_types = ll_sort_recording_type_slugs($existing_types);

        if (!empty($missing_types)) {
            if (isset($active_category_term) && $active_category_term) {
                $category_name       = $active_category_term->name;
                $category_slug_value = $active_category_term->slug;
            } else {
                $categories = wp_get_post_terms($word_id, 'word-category');
                if (!empty($categories) && !is_wp_error($categories)) {
                    $category            = $categories[0];
                    $category_name       = $category->name;
                    $category_slug_value = $category->slug;
                } else {
                    $category_name       = $uncategorized_label;
                    $category_slug_value = 'uncategorized';
                }
            }

            if (!isset($items_by_category[$category_slug_value])) {
                $items_by_category[$category_slug_value] = [
                    'name'  => $category_name,
                    'slug'  => $category_slug_value,
                    'items' => [],
                ];
            }

            $translation = get_post_meta($word_id, 'word_translation', true);
            if ($translation === '') {
                $translation = get_post_meta($word_id, 'word_english_meaning', true);
            }

            $display_word_title = get_the_title($word_id);
            if (get_option('ll_word_title_language_role', 'target') === 'translation' && !empty($translation)) {
                $display_word_title = $translation;
            }

            $items_by_category[$category_slug_value]['items'][] = [
                'id'             => 0,
                'title'          => $display_word_title,
                'image_url'      => '',
                'category_name'  => $category_name,
                'category_slug'  => $category_slug_value,
                'word_id'        => $word_id,
                // NEW: For text-only (no image), use word's own title and translation
                'word_title'       => $display_word_title,
                'word_translation' => $translation,
                'use_word_display' => true, // Always prefer word data for text-only
                'missing_types'    => $missing_types,
                'existing_types'  => $existing_types,
                'is_text_only'   => true,
            ];
        }
    }

    // Missing audio words captured by [word_audio] shortcode (no matching word/audio found)
    if ($is_uncategorized_request || empty($category_slug)) {
        $missing_audio_instances = is_array($missing_audio_instances) ? $missing_audio_instances : [];
        if (!empty($missing_audio_instances)) {
            $uncat_desired = ll_tools_get_uncategorized_desired_recording_types();
            $uncat_desired = ll_sort_recording_type_slugs($uncat_desired);
            $types_for_missing = ll_sort_recording_type_slugs(array_values(array_intersect($uncat_desired, $filtered_types)));

            if (!empty($types_for_missing)) {
                if (!isset($items_by_category['uncategorized'])) {
                    $items_by_category['uncategorized'] = [
                        'name'  => $uncategorized_label,
                        'slug'  => 'uncategorized',
                        'items' => [],
                    ];
                }

                $seen_missing_titles = [];
                foreach ($missing_audio_instances as $missing_word => $source_post_id) {
                    $word_title = sanitize_text_field($missing_word);
                    if ($word_title === '' || isset($seen_missing_titles[$word_title])) {
                        continue;
                    }
                    $seen_missing_titles[$word_title] = true;

                    $word_id = 0;
                    $existing_types = [];
                    $missing_types = $types_for_missing;

                    if (function_exists('ll_find_post_by_exact_title')) {
                        $maybe_word = ll_find_post_by_exact_title($word_title, 'words');
                        if ($maybe_word) {
                            $word_id = (int) $maybe_word->ID;
                            $main_types = ll_tools_get_main_recording_types();
                            $types_equal_main = empty(array_diff($types_for_missing, $main_types)) && empty(array_diff($main_types, $types_for_missing));
                            if ($types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) {
                                continue;
                            }
                            if ($types_equal_main) {
                                $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_missing, get_current_user_id());
                            } else {
                                $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_missing);
                            }
                            $existing_types = ll_get_existing_recording_types_for_word($word_id);
                        }
                    }
                    $missing_types = ll_sort_recording_type_slugs($missing_types);
                    $existing_types = ll_sort_recording_type_slugs($existing_types);

                    if (empty($missing_types)) {
                        continue;
                    }

                    $items_by_category['uncategorized']['items'][] = [
                        'id'               => 0,
                        'title'            => $word_title,
                        'image_url'        => '',
                        'category_name'    => $uncategorized_label,
                        'category_slug'    => 'uncategorized',
                        'word_id'          => $word_id,
                        'word_title'       => $word_title,
                        'word_translation' => '',
                        'use_word_display' => true,
                        'missing_types'    => $missing_types,
                        'existing_types'   => $existing_types,
                        'is_text_only'     => true,
                        'missing_audio_source_post' => intval($source_post_id),
                    ];
                }
            }
        }
    }

    uasort($items_by_category, function($a, $b) {
        $left_name = isset($a['name']) ? (string) $a['name'] : '';
        $right_name = isset($b['name']) ? (string) $b['name'] : '';
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings($left_name, $right_name);
        }
        return strcasecmp($left_name, $right_name);
    });

    if (!empty($category_slug)) {
        $target_slugs = $is_uncategorized_request ? ['uncategorized'] : [$category_slug];
    } else {
        $target_slugs = array_keys($items_by_category);
    }

    $result = [];
    foreach ($target_slugs as $slug) {
        if (!isset($items_by_category[$slug])) {
            continue;
        }
        foreach ($items_by_category[$slug]['items'] as $item) {
            $result[] = $item;
        }
    }

    foreach ($result as &$item) {
        if (!is_array($item)) {
            continue;
        }
        $hide_keys = ll_tools_get_recording_item_hide_keys($item);
        $item['hide_key'] = !empty($hide_keys) ? (string) $hide_keys[0] : '';
    }
    unset($item);

    if (!$include_hidden) {
        $result = ll_tools_filter_hidden_recording_items($result, get_current_user_id());
    }

    return $result;
}

/**
 * Return the first "words" post (ID) in the given wordset(s) that uses this image.
 */
function ll_get_word_for_image_in_wordset(int $image_post_id, array $wordset_term_ids) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return 0;
    }

    $query_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending'], // Include draft/pending words
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'   => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    if (!empty($wordset_term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_term_ids),
        ]];
    }

    $ids = get_posts($query_args);
    return !empty($ids) ? (int) $ids[0] : 0;
}

/**
 * For a given word (parent of word_audio), return the recording_type slugs already present.
 */
function ll_get_existing_recording_types_for_word(int $word_id): array {
    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish','private','inherit'], // count in-flight too
        'perm'           => 'any', // bypass capability gating so recorder drafts still count
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_parent'    => $word_id,
        'tax_query'      => [[
            'taxonomy' => 'recording_type',
            'field'    => 'slug',
            'terms'    => [], // placeholder so WP includes the join; we’ll read terms below
            'operator' => 'NOT IN', // this keeps query valid; we’ll fetch terms via wp_get_post_terms
        ]],
    ]);

    if (empty($audio_posts)) {
        return [];
    }

    $existing = [];
    foreach ($audio_posts as $post_id) {
        $terms = wp_get_post_terms($post_id, 'recording_type', ['fields' => 'slugs']);
        if (!is_wp_error($terms) && !empty($terms)) {
            // allow only one per audio post; if multiple, merge all
            foreach ($terms as $slug) {
                $existing[] = $slug;
            }
        }
    }
    return ll_sort_recording_type_slugs(array_values(array_unique($existing)));
}

/**
 * For a given word (parent of word_audio), return the recording_type slugs missing (not recorded), limited to provided filtered types.
 * The $ignore_skipped flag is retained for backward compatibility but has no effect now that skipped types are session-only.
 *
 * @param int $word_id
 * @param array $filtered_types Slugs available for this shortcode instance
 * @param bool  $ignore_skipped Legacy flag (unused)
 * @return array
 */
function ll_get_missing_recording_types_for_word(int $word_id, array $filtered_types, bool $ignore_skipped = false): array {
    $existing = ll_get_existing_recording_types_for_word($word_id);
    $missing = array_values(array_diff($filtered_types, $existing));
    return ll_sort_recording_type_slugs($missing);
}

/**
 * Get existing recording types for a word recorded by a specific user.
 */
function ll_get_existing_recording_types_for_word_by_user(int $word_id, int $user_id): array {
    if (!$user_id) { return []; }
    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish','private','inherit'],
        'perm'           => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_parent'    => $word_id,
        'author'         => $user_id,
    ]);
    if (empty($audio_posts)) { return []; }
    $existing = [];
    foreach ($audio_posts as $post_id) {
        $terms = wp_get_post_terms($post_id, 'recording_type', ['fields' => 'slugs']);
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $slug) { $existing[] = $slug; }
        }
    }
    return ll_sort_recording_type_slugs(array_values(array_unique($existing)));
}

/**
 * User-specific missing types for a word among the provided desired/filter set.
 * The $ignore_skipped flag is retained for backward compatibility but has no effect now that skipped types are session-only.
 */
function ll_get_user_missing_recording_types_for_word(int $word_id, array $filtered_types, int $user_id, bool $ignore_skipped = false): array {
    $user_existing = ll_get_existing_recording_types_for_word_by_user($word_id, $user_id);
    $missing = array_values(array_diff($filtered_types, $user_existing));
    return ll_sort_recording_type_slugs($missing);
}

/**
 * Enqueue recording interface assets
 */
function ll_enqueue_recording_assets($auto_process_recordings = false) {
    // Enqueue flashcard styles first so recording interface can use them
    ll_enqueue_asset_by_timestamp('/css/flashcard/base.css', 'll-flashcard-style');
    ll_enqueue_asset_by_timestamp('/css/flashcard/mode-practice.css', 'll-flashcard-mode-practice', ['ll-flashcard-style']);
    ll_enqueue_asset_by_timestamp('/css/flashcard/mode-learning.css', 'll-flashcard-mode-learning', ['ll-flashcard-style']);
    ll_enqueue_asset_by_timestamp('/css/flashcard/mode-listening.css', 'll-flashcard-mode-listening', ['ll-flashcard-style']);
    ll_enqueue_asset_by_timestamp(
        '/css/recording-interface.css',
        'll-recording-interface',
        [
            'll-flashcard-style',
            'll-flashcard-mode-practice',
            'll-flashcard-mode-learning',
            'll-flashcard-mode-listening'
        ]
    );

    if ($auto_process_recordings) {
        ll_enqueue_asset_by_timestamp('/css/audio-processor.css', 'll-audio-processor-css', ['ll-recording-interface']);
    }

    ll_enqueue_asset_by_timestamp('/js/audio-recorder.js', 'll-audio-recorder', [], true);
}

/**
 * AJAX handler: Skip recording a type for a word
 */
add_action('wp_ajax_ll_skip_recording_type', 'll_skip_recording_type_handler');

function ll_skip_recording_type_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in to skip recordings.', 'll-tools-text-domain'));
    }
    if (!ll_tools_user_can_record()) {
        wp_send_json_error(__('You do not have permission to skip recordings.', 'll-tools-text-domain'), 403);
    }

    $image_id       = intval($_POST['image_id'] ?? 0);
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');
    $posted_ids     = ll_tools_get_recording_wordset_ids_from_request();
    $type_filters   = ll_tools_get_recording_type_filters_from_request();
    $filtered_types = $type_filters['filtered_types'];

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error(__('Missing image_id or word_id.', 'll-tools-text-domain'));
        }
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            wp_send_json_error(__('Invalid image ID.', 'll-tools-text-domain'));
        }
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
        if (is_wp_error($word_id)) {
            wp_send_json_error(sprintf(
                /* translators: %s: word creation error */
                __('Failed to find/create word post: %s', 'll-tools-text-domain'),
                $word_id->get_error_message()
            ));
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                wp_send_json_error(__('Invalid word ID.', 'll-tools-text-domain'));
            }
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $posted_ids);
            if (is_wp_error($created_word_id)) {
                wp_send_json_error(sprintf(
                    /* translators: %s: word creation error */
                    __('Failed to create word post: %s', 'll-tools-text-domain'),
                    $created_word_id->get_error_message()
                ));
            }
            $word_id = (int) $created_word_id;
        }
    }

    // Remaining types, applying single-speaker logic only when collecting the full main set
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $desired_word = ll_sort_recording_type_slugs($desired_word);
    $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($filtered_types, $desired_word)));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, get_current_user_id());
        }
    } else {
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }
    // Drop the skipped type for this session so the UI can move on, but do not persist anything.
    if ($recording_type) {
        $remaining_missing = array_values(array_diff($remaining_missing, [$recording_type]));
    }
    $remaining_missing = ll_sort_recording_type_slugs($remaining_missing);

    wp_send_json_success([
        'remaining_types' => $remaining_missing,
    ]);
}

/**
 * Register recording notification settings on the main LL Tools settings page.
 */
function ll_tools_register_recording_notification_settings() {
    register_setting('language-learning-tools-options', 'll_tools_recording_notification_email', [
        'type' => 'string',
        'sanitize_callback' => 'll_tools_sanitize_recording_notification_email',
        'default' => '',
    ]);

    register_setting('language-learning-tools-options', 'll_tools_recording_notification_delay_minutes', [
        'type' => 'integer',
        'sanitize_callback' => 'll_tools_sanitize_recording_notification_delay_minutes',
        'default' => 20,
    ]);
}
add_action('admin_init', 'll_tools_register_recording_notification_settings');

/**
 * Sanitize recording notification email value.
 */
function ll_tools_sanitize_recording_notification_email($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $email = sanitize_email($value);
    if (!is_email($email)) {
        add_settings_error(
            'll_tools_recording_notification_email',
            'll_tools_recording_notification_email_invalid',
            __('Please enter a valid notification email address.', 'll-tools-text-domain')
        );
        return '';
    }

    return $email;
}

/**
 * Sanitize recording notification inactivity delay.
 */
function ll_tools_sanitize_recording_notification_delay_minutes($value) {
    $minutes = absint($value);
    if ($minutes < 1) {
        $minutes = 20;
    }
    if ($minutes > 1440) {
        $minutes = 1440;
    }
    return $minutes;
}

/**
 * Get notification inactivity delay in minutes.
 */
function ll_tools_get_recording_notification_delay_minutes() {
    $minutes = (int) get_option('ll_tools_recording_notification_delay_minutes', 20);
    if ($minutes < 1) {
        return 20;
    }
    if ($minutes > 1440) {
        return 1440;
    }
    return $minutes;
}

/**
 * Resolve recipient email (custom LL Tools setting, then fallback to site admin email).
 */
function ll_tools_get_recording_notification_recipient() {
    $configured = trim((string) get_option('ll_tools_recording_notification_email', ''));
    if ($configured !== '' && is_email($configured)) {
        return $configured;
    }

    $admin_email = trim((string) get_option('admin_email'));
    if (is_email($admin_email)) {
        return $admin_email;
    }

    return '';
}

/**
 * Render LL Tools settings rows for recording notifications.
 */
function ll_tools_render_recording_notification_settings_rows() {
    $configured_email = (string) get_option('ll_tools_recording_notification_email', '');
    $admin_email = (string) get_option('admin_email', '');
    $delay_minutes = ll_tools_get_recording_notification_delay_minutes();
    ?>
    <tr valign="top">
        <th scope="row"><?php esc_html_e('Recording Notification Email', 'll-tools-text-domain'); ?></th>
        <td>
            <input
                type="email"
                name="ll_tools_recording_notification_email"
                id="ll_tools_recording_notification_email"
                value="<?php echo esc_attr($configured_email); ?>"
                class="regular-text"
            />
            <p class="description">
                <?php
                if ($admin_email !== '' && is_email($admin_email)) {
                    printf(
                        /* translators: %s: admin email address */
                        esc_html__('Leave blank to use the site admin email (%s).', 'll-tools-text-domain'),
                        esc_html($admin_email)
                    );
                } else {
                    esc_html_e('Leave blank to use the site admin email.', 'll-tools-text-domain');
                }
                ?>
            </p>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row"><?php esc_html_e('Recording Notification Delay (minutes)', 'll-tools-text-domain'); ?></th>
        <td>
            <input
                type="number"
                name="ll_tools_recording_notification_delay_minutes"
                id="ll_tools_recording_notification_delay_minutes"
                value="<?php echo esc_attr((string) $delay_minutes); ?>"
                min="1"
                max="1440"
                step="1"
            />
            <p class="description">
                <?php esc_html_e('After the most recent upload, wait this many minutes before sending a summary email.', 'll-tools-text-domain'); ?>
            </p>
        </td>
    </tr>
    <?php
}
add_action('ll_tools_settings_after_translations', 'll_tools_render_recording_notification_settings_rows', 30);

/**
 * Save a successful recording upload into the pending-notification batch.
 */
function ll_tools_queue_recording_upload_notification($audio_post_id, $user_id) {
    $audio_post_id = (int) $audio_post_id;
    $user_id = (int) $user_id;
    if ($audio_post_id <= 0 || $user_id <= 0) {
        return;
    }

    $now_unix = time();
    $now_local = current_time('mysql');

    $state = get_option('ll_tools_recording_notification_state', []);
    if (!is_array($state)) {
        $state = [];
    }

    $state['total_count'] = isset($state['total_count']) ? max(0, (int) $state['total_count']) : 0;
    $state['total_count']++;

    if (empty($state['batch_started_at'])) {
        $state['batch_started_at'] = $now_local;
    }
    if (empty($state['batch_started_unix'])) {
        $state['batch_started_unix'] = $now_unix;
    }

    $state['last_upload_at'] = $now_local;
    $state['last_upload_unix'] = $now_unix;

    $users = isset($state['users']) && is_array($state['users']) ? $state['users'] : [];
    $user_key = (string) $user_id;
    if (!isset($users[$user_key]) || !is_array($users[$user_key])) {
        $display_name = '';
        $user = get_userdata($user_id);
        if ($user && !empty($user->display_name)) {
            $display_name = sanitize_text_field((string) $user->display_name);
        }
        if ($display_name === '') {
            $display_name = sprintf(
                /* translators: %d: user ID */
                __('User #%d', 'll-tools-text-domain'),
                $user_id
            );
        }
        $users[$user_key] = [
            'display_name' => $display_name,
            'count' => 0,
        ];
    }
    $users[$user_key]['count'] = max(0, (int) ($users[$user_key]['count'] ?? 0)) + 1;
    $state['users'] = $users;

    update_option('ll_tools_recording_notification_state', $state, false);
    ll_tools_schedule_recording_notification_event();
}

/**
 * Schedule (or reschedule) the summary notification email.
 */
function ll_tools_schedule_recording_notification_event() {
    wp_clear_scheduled_hook('ll_tools_send_recording_notification_event');

    $delay_seconds = ll_tools_get_recording_notification_delay_minutes() * MINUTE_IN_SECONDS;
    wp_schedule_single_event(time() + $delay_seconds, 'll_tools_send_recording_notification_event');
}

/**
 * Cron callback for sending recording summary notification email.
 */
function ll_tools_send_recording_notification_email() {
    $state = get_option('ll_tools_recording_notification_state', []);
    if (!is_array($state)) {
        return;
    }

    $total_count = isset($state['total_count']) ? max(0, (int) $state['total_count']) : 0;
    if ($total_count < 1) {
        delete_option('ll_tools_recording_notification_state');
        return;
    }

    $last_upload_unix = isset($state['last_upload_unix']) ? (int) $state['last_upload_unix'] : 0;
    $delay_seconds = ll_tools_get_recording_notification_delay_minutes() * MINUTE_IN_SECONDS;
    if ($last_upload_unix > 0 && (time() - $last_upload_unix) < $delay_seconds) {
        ll_tools_schedule_recording_notification_event();
        return;
    }

    $recipient = ll_tools_get_recording_notification_recipient();
    if ($recipient === '') {
        return;
    }

    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = sprintf(
        /* translators: 1: site name, 2: number of recordings */
        _n(
            '[%1$s] %2$d new recording is ready for processing',
            '[%1$s] %2$d new recordings are ready for processing',
            $total_count,
            'll-tools-text-domain'
        ),
        $site_name,
        $total_count
    );

    $users = isset($state['users']) && is_array($state['users']) ? $state['users'] : [];
    uasort($users, function ($a, $b) {
        return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
    });

    $lines = [];
    $lines[] = sprintf(
        /* translators: %d: number of new recordings */
        _n(
            '%d new audio recording was uploaded through the recording interface.',
            '%d new audio recordings were uploaded through the recording interface.',
            $total_count,
            'll-tools-text-domain'
        ),
        $total_count
    );
    $lines[] = '';

    if (!empty($users)) {
        $lines[] = __('Recorders:', 'll-tools-text-domain');
        foreach ($users as $entry) {
            $display_name = isset($entry['display_name']) ? sanitize_text_field((string) $entry['display_name']) : __('Unknown user', 'll-tools-text-domain');
            $user_count = max(0, (int) ($entry['count'] ?? 0));
            $lines[] = sprintf(
                /* translators: 1: display name, 2: recording count */
                _n(
                    '- %1$s (%2$d recording)',
                    '- %1$s (%2$d recordings)',
                    $user_count,
                    'll-tools-text-domain'
                ),
                $display_name,
                $user_count
            );
        }
        $lines[] = '';
    }

    if (!empty($state['batch_started_at'])) {
        $lines[] = sprintf(
            /* translators: %s: date/time */
            __('Batch started: %s', 'll-tools-text-domain'),
            (string) $state['batch_started_at']
        );
    }
    if (!empty($state['last_upload_at'])) {
        $lines[] = sprintf(
            /* translators: %s: date/time */
            __('Most recent upload: %s', 'll-tools-text-domain'),
            (string) $state['last_upload_at']
        );
    }
    $lines[] = '';
    $lines[] = __('Review and process recordings:', 'll-tools-text-domain');
    $lines[] = admin_url('tools.php?page=ll-audio-processor');

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $sent = wp_mail($recipient, $subject, implode("\n", $lines), $headers);

    if ($sent) {
        delete_option('ll_tools_recording_notification_state');
        return;
    }

    // Keep state and try again after another quiet window if sending fails.
    ll_tools_schedule_recording_notification_event();
}
add_action('ll_tools_send_recording_notification_event', 'll_tools_send_recording_notification_email');

/**
 * AJAX handler: Upload recording and create word_audio post
 */
add_action('wp_ajax_ll_upload_recording', 'll_handle_recording_upload');

function ll_handle_recording_upload() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in to upload recordings', 'll-tools-text-domain'));
    }
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('You do not have permission to upload recordings', 'll-tools-text-domain'));
    }
    if (!current_user_can('upload_files')) {
        wp_send_json_error(__('You do not have permission to upload recordings', 'll-tools-text-domain'));
    }

    $current_user_id = get_current_user_id();
    $auto_processed_request = isset($_POST['auto_processed']) ? sanitize_text_field($_POST['auto_processed']) : '';
    $auto_processed_request = ($auto_processed_request === '1');
    $user_recording_config = function_exists('ll_get_user_recording_config')
        ? ll_get_user_recording_config($current_user_id)
        : [];
    $auto_process_allowed = is_array($user_recording_config) && !empty($user_recording_config['auto_process_recordings']);
    $auto_publish = $auto_processed_request && $auto_process_allowed;

    if (empty($_FILES['audio'])) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'));
    }

    $upload_validation = ll_tools_validate_recording_upload_file((array) $_FILES['audio']);
    if (empty($upload_validation['valid'])) {
        $status = max(400, (int) ($upload_validation['status'] ?? 400));
        $message = (string) ($upload_validation['error'] ?? '');
        if ($message === '') {
            $message = __('Invalid audio upload.', 'll-tools-text-domain');
        }
        wp_send_json_error($message, $status);
    }

    $image_id       = intval($_POST['image_id'] ?? 0);
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? 'isolation');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');
    $posted_ids     = ll_tools_get_recording_wordset_ids_from_request();
    $type_filters   = ll_tools_get_recording_type_filters_from_request();
    $filtered_types = $type_filters['filtered_types'];

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error(__('Missing image_id or word_id', 'll-tools-text-domain'));
        }
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            error_log('Upload step: Invalid image ID');
            wp_send_json_error(__('Invalid image ID', 'll-tools-text-domain'));
        }
        $title = $image_post->post_title;
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
        if (is_wp_error($word_id)) {
            error_log('Upload step: Failed to find/create word post: ' . $word_id->get_error_message());
            wp_send_json_error(sprintf(
                __('Failed to find/create word post: %s', 'll-tools-text-domain'),
                $word_id->get_error_message()
            ));
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                error_log('Upload step: Invalid word ID');
                wp_send_json_error(__('Invalid word ID', 'll-tools-text-domain'));
            }
            $title = $word_post->post_title;
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $posted_ids);
            if (is_wp_error($created_word_id)) {
                error_log('Upload step: Failed to create word post: ' . $created_word_id->get_error_message());
                wp_send_json_error(sprintf(
                    __('Failed to create word post: %s', 'll-tools-text-domain'),
                    $created_word_id->get_error_message()
                ));
            }
            $word_id = (int) $created_word_id;
            $title = $word_title;
        }
    }

    $file       = (array) $_FILES['audio'];
    $safe_title = sanitize_file_name($title);
    // Extra hardening for edge-case titles (quotes, exotic punctuation)
    $safe_title = str_replace(array("'", '"'), '', $safe_title);
    $safe_title = preg_replace('/[^A-Za-z0-9._-]+/', '-', $safe_title);
    $safe_title = trim($safe_title, '-_.');
    if ($safe_title === '') {
        $safe_title = 'recording';
    }
    $timestamp  = time();

    $validated_ext = sanitize_key((string) ($upload_validation['ext'] ?? ''));
    if ($validated_ext === '') {
        $validated_ext = 'webm';
    }
    $filename = $safe_title . '_' . $timestamp . '.' . $validated_ext;
    $file['name'] = $filename;
    if (!empty($upload_validation['mime'])) {
        $file['type'] = (string) $upload_validation['mime'];
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $upload_result = wp_handle_upload($file, [
        'test_form' => false,
        // We already enforce extension, MIME, size, and audio-content checks above.
        // Disable duplicate core MIME validation here to avoid false rejections
        // for valid recorder blobs on hosts with strict/legacy MIME mappings.
        'test_type' => false,
        'mimes' => ll_tools_get_allowed_recording_upload_mimes(),
    ]);
    if (!is_array($upload_result) || !empty($upload_result['error']) || empty($upload_result['file'])) {
        $upload_error = is_array($upload_result) ? (string) ($upload_result['error'] ?? '') : '';
        error_log('Upload step: Failed to save file' . ($upload_error !== '' ? ': ' . $upload_error : ''));
        $status = ($upload_error !== '' && stripos($upload_error, 'file type') !== false) ? 415 : 400;
        if ($upload_error !== '') {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: upload subsystem error message */
                    __('Failed to save file: %s', 'll-tools-text-domain'),
                    $upload_error
                ),
                $status
            );
        }
        wp_send_json_error(__('Failed to save file', 'll-tools-text-domain'), $status);
    }
    $filepath = (string) $upload_result['file'];

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    $audio_post_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'word_audio',
        'post_status' => $auto_publish ? 'publish' : 'draft',
        'post_parent' => $word_id,
        'post_author' => $current_user_id,
    ]);
    if (is_wp_error($audio_post_id) || !$audio_post_id) {
        $err_msg = is_wp_error($audio_post_id) ? $audio_post_id->get_error_message() : 'Unknown insert failure';
        if (is_string($filepath) && $filepath !== '' && file_exists($filepath)) {
            @unlink($filepath);
        }
        error_log('Upload step: Failed to create word_audio post: ' . $err_msg);
        wp_send_json_error(sprintf(
            __('Failed to create word_audio post: %s', 'll-tools-text-domain'),
            $err_msg
        ));
    }

    $extension = '.' . strtolower((string) pathinfo($filepath, PATHINFO_EXTENSION));
    if ($extension === '.') {
        $extension = '.' . $validated_ext;
    }

    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    update_post_meta($audio_post_id, 'speaker_user_id', $current_user_id);
    update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
    if ($auto_publish) {
        update_post_meta($audio_post_id, '_ll_processed_audio_date', current_time('mysql'));
    } else {
        update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
    }
    update_post_meta($audio_post_id, '_ll_raw_recording_format', $extension);

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    if ($auto_publish) {
        $parent_word = get_post($word_id);
        if ($parent_word && $parent_word->post_status !== 'publish') {
            wp_update_post([
                'ID'          => $word_id,
                'post_status' => 'publish',
            ]);
        }
    }

    // Recompute remaining types using the same rules as the UI: honor desired types
    // and only apply single-speaker gating when the full main set is being collected.
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $desired_word = ll_sort_recording_type_slugs($desired_word);
    $filtered_types = ll_sort_recording_type_slugs(array_values(array_intersect($filtered_types, $desired_word)));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_user_id);
        }
    } else {
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }

    if (function_exists('ll_remove_missing_audio_instance')) {
        // Match the normalization pipeline used when populating the cache (normalize case, then sanitize)
        $normalized_for_cache = $title;
        if (function_exists('ll_normalize_case')) {
            $normalized_for_cache = ll_normalize_case($normalized_for_cache);
        }
        if (function_exists('ll_missing_audio_sanitize_word_text')) {
            $normalized_for_cache = ll_missing_audio_sanitize_word_text($normalized_for_cache);
        }

        // Canonicalize apostrophes to avoid smart-quote vs straight-quote mismatches
        $canonicalize_apostrophes = function ($text) {
            return str_replace(
                array("\u{2019}", "\u{2018}", "\u{201B}", "\u{02BC}", "\u{FF07}"),
                "'",
                (string) $text
            );
        };

        $candidates = [];
        if (is_string($normalized_for_cache) && $normalized_for_cache !== '') {
            $candidates[] = $normalized_for_cache;
            $candidates[] = $canonicalize_apostrophes($normalized_for_cache);
            // Version without apostrophes to catch legacy keys
            $candidates[] = preg_replace("/['’ʼ`´]/u", '', $normalized_for_cache);
        }
        // Fall back to raw title variants in case earlier cache entries were stored differently
        if ($title !== '') {
            $candidates[] = $title;
            $candidates[] = $canonicalize_apostrophes($title);
            $candidates[] = preg_replace("/['’ʼ`´]/u", '', $title);
            if (function_exists('ll_normalize_case')) {
                $norm = ll_normalize_case($title);
                $candidates[] = $norm;
                $candidates[] = $canonicalize_apostrophes($norm);
                $candidates[] = preg_replace("/['’ʼ`´]/u", '', $norm);
            }
        }

        foreach (array_unique(array_filter($candidates, function ($v) { return is_string($v) && $v !== ''; })) as $cand) {
            ll_remove_missing_audio_instance($cand);
        }
    }

    $should_notify = apply_filters(
        'll_tools_should_queue_recording_notification',
        !$auto_publish,
        (int) $audio_post_id,
        (int) $word_id,
        (int) $current_user_id
    );
    if ($should_notify) {
        ll_tools_queue_recording_upload_notification((int) $audio_post_id, (int) $current_user_id);
    }

    wp_send_json_success([
        'audio_post_id'   => (int) $audio_post_id,
        'word_id'         => (int) $word_id,
        'recording_type'  => $recording_type,
        'remaining_types' => ll_sort_recording_type_slugs($remaining_missing),
    ]);
}

/**
 * Find existing word post for an image, or create one
 */
function ll_find_or_create_word_for_image($image_id, $image_post, $wordset_ids) {
    $attachment_id = get_post_thumbnail_id($image_id);

    if (!$attachment_id) {
        return new WP_Error('no_attachment', __('Image has no attachment', 'll-tools-text-domain'));
    }

    // Check if a word already exists with this image IN THE SPECIFIED WORDSET
    $query_args = [
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    // Filter by wordset if specified
    if (!empty($wordset_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_ids),
        ]];
    }

    $existing_words = get_posts($query_args);

    if (!empty($existing_words)) {
        return (int) $existing_words[0];
    }

    // Create new word post
    $word_id = wp_insert_post([
        'post_title'  => $image_post->post_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id)) {
        return $word_id;
    }

    // Set the featured image
    set_post_thumbnail($word_id, $attachment_id);

    // Copy categories from image to word
    $categories = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($categories) && !empty($categories)) {
        wp_set_object_terms($word_id, $categories, 'word-category');
    }

    // Copy translation from image if present
    $img_translation = get_post_meta($image_id, 'word_translation', true);
    if (!empty($img_translation)) {
        update_post_meta($word_id, 'word_translation', $img_translation);
    }

    // Assign to wordset
    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset');
    }

    return $word_id;
}

/**
 * Find an existing word by exact title or create a new one (draft) with the default wordset.
 *
 * @param string $word_title
 * @param array  $wordset_ids
 * @return int|WP_Error
 */
function ll_find_or_create_word_by_title($word_title, $wordset_ids = []) {
    $word_title = ll_sanitize_word_title_text($word_title);
    if ($word_title === '') {
        return new WP_Error('empty_title', __('Missing word title', 'll-tools-text-domain'));
    }

    // Try to find existing by exact title
    if (function_exists('ll_find_post_by_exact_title')) {
        $maybe = ll_find_post_by_exact_title($word_title, 'words');
        if ($maybe) {
            $word_id = (int) $maybe->ID;
            if (!empty($wordset_ids)) {
                wp_set_object_terms($word_id, $wordset_ids, 'wordset', true);
            }
            return $word_id;
        }
    }

    if (empty($wordset_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_ids = [$default_id];
        }
    }

    $word_id = wp_insert_post([
        'post_title'  => $word_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id)) {
        return $word_id;
    }

    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset');
    }

    return (int) $word_id;
}

/**
 * Strip shortcodes while keeping their inner content intact.
 *
 * @param string $text
 * @return string
 */
function ll_strip_shortcodes_preserve_content($text) {
    if (!function_exists('get_shortcode_regex')) {
        return $text;
    }

    $pattern = '/' . get_shortcode_regex() . '/s';
    $previous = null;

    while ($previous !== $text) {
        $previous = $text;
        $text = preg_replace_callback($pattern, function ($m) {
            // Respect escaped shortcodes [[tag]]
            if ($m[1] === '[' && $m[6] === ']') {
                return substr($m[0], 1, -1);
            }
            // Group 5 is the inner content of enclosing shortcodes.
            return isset($m[5]) ? $m[5] : '';
        }, $text);
    }

    return $text;
}

/**
 * Sanitize a word title by stripping shortcodes, HTML, parentheses, and extra whitespace.
 *
 * @param string $text
 * @return string
 */
function ll_sanitize_word_title_text($text) {
    $text = (string) $text;
    // Remove shortcode wrappers while keeping the inner text (e.g., color tags)
    $text = ll_strip_shortcodes_preserve_content($text);
    // Strip BBCode-style or unknown bracket tags (e.g., [color]...[/color])
    $text = preg_replace('/\[[^\]]+\]/u', '', $text);
    $text = wp_kses_decode_entities($text);
    // Strip HTML tags
    $text = wp_strip_all_tags($text);
    // Remove anything in parentheses (multiple occurrences)
    $text = preg_replace('/\s*\([^)]*\)/u', '', $text);
    // Collapse whitespace
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}
