<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_SITE_SYNC_SCHEMA_VERSION')) {
    define('LL_TOOLS_SITE_SYNC_SCHEMA_VERSION', 1);
}

function ll_tools_site_sync_uuid_meta_key(): string {
    return '_ll_tools_sync_uuid';
}

function ll_tools_site_sync_capability(): string {
    return (string) apply_filters('ll_tools_site_sync_capability', 'manage_options');
}

function ll_tools_site_sync_supported_surfaces(): array {
    $surfaces = [
        'transcriptions' => [
            'label' => __('Recording transcriptions', 'll-tools-text-domain'),
            'record_type' => 'word_audio_transcription',
        ],
    ];

    return (array) apply_filters('ll_tools_site_sync_supported_surfaces', $surfaces);
}

function ll_tools_site_sync_normalize_surface(string $surface): string {
    $surface = sanitize_key($surface);
    return isset(ll_tools_site_sync_supported_surfaces()[$surface]) ? $surface : 'transcriptions';
}

function ll_tools_site_sync_transcription_value_keys(): array {
    return ['recording_text', 'recording_ipa', 'needs_review', 'review_fields', 'review_note'];
}

function ll_tools_site_sync_get_or_create_post_uuid(int $post_id, bool $ensure = true): string {
    if ($post_id <= 0) {
        return '';
    }

    $meta_key = ll_tools_site_sync_uuid_meta_key();
    $uuid = trim((string) get_post_meta($post_id, $meta_key, true));
    if ($uuid !== '' || !$ensure) {
        return $uuid;
    }

    $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5((string) wp_rand() . '|' . microtime(true));
    update_post_meta($post_id, $meta_key, $uuid);
    return $uuid;
}

function ll_tools_site_sync_get_or_create_term_uuid(int $term_id, bool $ensure = true): string {
    if ($term_id <= 0) {
        return '';
    }

    $meta_key = ll_tools_site_sync_uuid_meta_key();
    $uuid = trim((string) get_term_meta($term_id, $meta_key, true));
    if ($uuid !== '' || !$ensure) {
        return $uuid;
    }

    $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5((string) wp_rand() . '|' . microtime(true));
    update_term_meta($term_id, $meta_key, $uuid);
    return $uuid;
}

function ll_tools_site_sync_normalize_review_fields($fields): array {
    if (function_exists('ll_tools_ipa_keyboard_normalize_review_fields')) {
        $fields = ll_tools_ipa_keyboard_normalize_review_fields($fields);
    } elseif (is_string($fields)) {
        $fields = preg_split('/[,;|]/', $fields);
    }

    $normalized = [];
    foreach ((array) $fields as $key => $value) {
        if (is_string($key)) {
            if (!$value) {
                continue;
            }
            $field = sanitize_key($key);
        } else {
            $field = sanitize_key((string) $value);
        }
        if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
            $normalized[$field] = true;
        }
    }

    $fields = array_keys($normalized);
    sort($fields);
    return $fields;
}

function ll_tools_site_sync_review_fields_meta_key(): string {
    return function_exists('ll_tools_ipa_keyboard_review_fields_meta_key')
        ? ll_tools_ipa_keyboard_review_fields_meta_key()
        : 'll_auto_transcription_review_fields';
}

function ll_tools_site_sync_review_note_meta_key(): string {
    return function_exists('ll_tools_ipa_keyboard_review_note_meta_key')
        ? ll_tools_ipa_keyboard_review_note_meta_key()
        : 'll_auto_transcription_review_note';
}

function ll_tools_site_sync_auto_review_meta_key(): string {
    return function_exists('ll_tools_ipa_keyboard_auto_review_meta_key')
        ? ll_tools_ipa_keyboard_auto_review_meta_key()
        : 'll_auto_transcription_needs_review';
}

function ll_tools_site_sync_get_recording_review_fields(int $recording_id): array {
    if (function_exists('ll_tools_ipa_keyboard_get_recording_review_field_list')) {
        return ll_tools_site_sync_normalize_review_fields(ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id));
    }

    $fields = ll_tools_site_sync_normalize_review_fields(get_post_meta($recording_id, ll_tools_site_sync_review_fields_meta_key(), true));
    if (empty($fields) && (string) get_post_meta($recording_id, ll_tools_site_sync_auto_review_meta_key(), true) === '1') {
        $fields = ['recording_ipa'];
    }

    return $fields;
}

function ll_tools_site_sync_recording_needs_review(int $recording_id): bool {
    if (function_exists('ll_tools_ipa_keyboard_recording_needs_auto_review')) {
        return (bool) ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id);
    }

    return !empty(ll_tools_site_sync_get_recording_review_fields($recording_id));
}

function ll_tools_site_sync_get_recording_review_note(int $recording_id): string {
    if (function_exists('ll_tools_ipa_keyboard_get_recording_review_note')) {
        return ll_tools_ipa_keyboard_get_recording_review_note($recording_id);
    }

    return trim((string) get_post_meta($recording_id, ll_tools_site_sync_review_note_meta_key(), true));
}

function ll_tools_site_sync_set_recording_review_note(int $recording_id, string $review_note): void {
    if (function_exists('ll_tools_ipa_keyboard_set_recording_review_note')) {
        ll_tools_ipa_keyboard_set_recording_review_note($recording_id, $review_note);
        return;
    }

    $review_note = sanitize_textarea_field($review_note);
    if ($review_note === '') {
        delete_post_meta($recording_id, ll_tools_site_sync_review_note_meta_key());
        return;
    }

    update_post_meta($recording_id, ll_tools_site_sync_review_note_meta_key(), $review_note);
}

function ll_tools_site_sync_clear_recording_review_state(int $recording_id): void {
    if (function_exists('ll_tools_ipa_keyboard_clear_recording_auto_review')) {
        ll_tools_ipa_keyboard_clear_recording_auto_review($recording_id);
        return;
    }

    delete_post_meta($recording_id, ll_tools_site_sync_auto_review_meta_key());
    delete_post_meta($recording_id, ll_tools_site_sync_review_fields_meta_key());
    delete_post_meta($recording_id, ll_tools_site_sync_review_note_meta_key());
}

function ll_tools_site_sync_apply_recording_review_state(int $recording_id, bool $needs_review, array $review_fields, string $review_note): void {
    if (!$needs_review) {
        ll_tools_site_sync_clear_recording_review_state($recording_id);
        return;
    }

    $review_fields = ll_tools_site_sync_normalize_review_fields($review_fields);
    if (empty($review_fields)) {
        $review_fields = ['recording_ipa'];
    }

    ll_tools_site_sync_clear_recording_review_state($recording_id);
    if (function_exists('ll_tools_ipa_keyboard_set_recording_review_state')) {
        foreach ($review_fields as $review_field) {
            ll_tools_ipa_keyboard_set_recording_review_state($recording_id, true, $review_field, $review_note);
        }
        if ($review_note === '') {
            ll_tools_site_sync_set_recording_review_note($recording_id, '');
        }
        return;
    }

    update_post_meta($recording_id, ll_tools_site_sync_auto_review_meta_key(), '1');
    update_post_meta($recording_id, ll_tools_site_sync_review_fields_meta_key(), array_fill_keys($review_fields, true));
    ll_tools_site_sync_set_recording_review_note($recording_id, $review_note);
}

function ll_tools_site_sync_write_recording_text(int $recording_id, string $recording_text): void {
    $recording_text = sanitize_text_field($recording_text);
    if ($recording_text === '') {
        delete_post_meta($recording_id, 'recording_text');
        return;
    }

    update_post_meta($recording_id, 'recording_text', $recording_text);
}

function ll_tools_site_sync_record_values(int $recording_id, int $wordset_id): array {
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? (string) ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $recording_ipa = (string) get_post_meta($recording_id, 'recording_ipa', true);

    return [
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_ipa' => function_exists('ll_tools_word_grid_normalize_ipa_output')
            ? ll_tools_word_grid_normalize_ipa_output($recording_ipa, $transcription_mode)
            : $recording_ipa,
        'needs_review' => ll_tools_site_sync_recording_needs_review($recording_id),
        'review_fields' => ll_tools_site_sync_get_recording_review_fields($recording_id),
        'review_note' => ll_tools_site_sync_get_recording_review_note($recording_id),
    ];
}

function ll_tools_site_sync_normalize_record_values(array $values): array {
    $needs_review = !empty($values['needs_review']);
    $review_fields = ll_tools_site_sync_normalize_review_fields($values['review_fields'] ?? []);
    if ($needs_review && empty($review_fields)) {
        $review_fields = ['recording_ipa'];
    }

    return [
        'recording_text' => (string) ($values['recording_text'] ?? ''),
        'recording_ipa' => (string) ($values['recording_ipa'] ?? ''),
        'needs_review' => $needs_review,
        'review_fields' => $review_fields,
        'review_note' => (string) ($values['review_note'] ?? ''),
    ];
}

function ll_tools_site_sync_value_hash(array $values): string {
    $values = ll_tools_site_sync_normalize_record_values($values);
    return hash('sha256', wp_json_encode($values));
}

function ll_tools_site_sync_normalize_remote_url($url): string {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (strpos($url, '//') === 0) {
        $url = (is_ssl() ? 'https:' : 'http:') . $url;
    }

    $url = esc_url_raw($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }

    $validated = wp_http_validate_url($url);
    return is_string($validated) ? $validated : '';
}

function ll_tools_site_sync_urls_equal($a, $b): bool {
    return rtrim(trim((string) $a), '/') === rtrim(trim((string) $b), '/');
}

function ll_tools_site_sync_resolve_local_file_path(string $path_or_url): string {
    $path_or_url = trim($path_or_url);
    if ($path_or_url === '') {
        return '';
    }

    $candidate = wp_normalize_path($path_or_url);
    if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
        return $candidate;
    }

    $path = $path_or_url;
    if (preg_match('#^https?://#i', $path_or_url)) {
        $upload_dir = wp_upload_dir();
        $base_url = isset($upload_dir['baseurl']) ? (string) $upload_dir['baseurl'] : '';
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        if ($base_url !== '' && $base_dir !== '' && strpos($path_or_url, $base_url) === 0) {
            $relative = ltrim(substr($path_or_url, strlen($base_url)), '/');
            $candidate = wp_normalize_path(trailingslashit($base_dir) . $relative);
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        $url_path = wp_parse_url($path_or_url, PHP_URL_PATH);
        if (!is_string($url_path) || $url_path === '') {
            return '';
        }
        $path = $url_path;
    }

    $path = wp_normalize_path($path);
    if ($path === '') {
        return '';
    }

    if (is_file($path) && is_readable($path)) {
        return $path;
    }

    $candidate = $path[0] === '/'
        ? wp_normalize_path(untrailingslashit(ABSPATH) . $path)
        : wp_normalize_path(untrailingslashit(ABSPATH) . '/' . ltrim($path, '/'));

    return ($candidate !== '' && is_file($candidate) && is_readable($candidate)) ? $candidate : '';
}

function ll_tools_site_sync_audio_media(int $recording_id): array {
    $stored_path = trim((string) get_post_meta($recording_id, 'audio_file_path', true));
    $local_path = ll_tools_site_sync_resolve_local_file_path($stored_path);
    $url = '';
    if ($stored_path !== '') {
        $url = function_exists('ll_tools_resolve_audio_file_url')
            ? (string) ll_tools_resolve_audio_file_url($stored_path)
            : (preg_match('#^https?://#i', $stored_path) ? $stored_path : site_url($stored_path));
    }

    $mime_type = '';
    $mime_source = $local_path !== '' ? $local_path : (string) wp_parse_url($url, PHP_URL_PATH);
    if ($mime_source !== '') {
        $filetype = wp_check_filetype(basename($mime_source), null);
        $mime_type = isset($filetype['type']) ? (string) $filetype['type'] : '';
    }

    return [
        'path' => $stored_path,
        'url' => ll_tools_site_sync_normalize_remote_url($url),
        'mime_type' => $mime_type,
        'has_local_file' => $local_path !== '',
    ];
}

function ll_tools_site_sync_attachment_has_local_file(int $attachment_id): bool {
    if ($attachment_id <= 0) {
        return false;
    }

    $file = (string) get_attached_file($attachment_id, true);
    return $file !== '' && is_file($file) && is_readable($file);
}

function ll_tools_site_sync_attachment_media(int $attachment_id): array {
    $fallback = [
        'id' => 0,
        'url' => '',
        'source_url' => '',
        'mime_type' => '',
        'title' => '',
        'alt' => '',
        'width' => 0,
        'height' => 0,
        'has_local_file' => false,
    ];

    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return $fallback;
    }

    $attachment = get_post($attachment_id);
    if (!($attachment instanceof WP_Post) || $attachment->post_type !== 'attachment') {
        return $fallback;
    }

    $url = (string) wp_get_attachment_url($attachment_id);
    $external_url = function_exists('ll_tools_get_external_attachment_source_url')
        ? (string) ll_tools_get_external_attachment_source_url($attachment_id)
        : trim((string) get_post_meta($attachment_id, '_ll_tools_external_source_url', true));
    $metadata = wp_get_attachment_metadata($attachment_id);
    $width = is_array($metadata) ? (int) ($metadata['width'] ?? 0) : 0;
    $height = is_array($metadata) ? (int) ($metadata['height'] ?? 0) : 0;

    return [
        'id' => $attachment_id,
        'url' => ll_tools_site_sync_normalize_remote_url($url),
        'source_url' => ll_tools_site_sync_normalize_remote_url($external_url !== '' ? $external_url : $url),
        'mime_type' => (string) $attachment->post_mime_type,
        'title' => (string) get_the_title($attachment_id),
        'alt' => trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)),
        'width' => max(0, $width),
        'height' => max(0, $height),
        'has_local_file' => ll_tools_site_sync_attachment_has_local_file($attachment_id),
    ];
}

function ll_tools_site_sync_word_image_media(int $word_id, bool $ensure_sync_ids = true): array {
    $word_image_id = function_exists('ll_tools_get_canonical_word_image_post_id_for_word')
        ? (int) ll_tools_get_canonical_word_image_post_id_for_word($word_id, true)
        : (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    $attachment_id = 0;
    $source = 'none';

    if ($word_image_id > 0) {
        $attachment_id = (int) get_post_thumbnail_id($word_image_id);
        if ($attachment_id > 0) {
            $source = 'word_image';
        }
    }

    if ($attachment_id <= 0) {
        $attachment_id = (int) get_post_thumbnail_id($word_id);
        if ($attachment_id > 0) {
            $source = 'word';
        }
    }

    $word_image_post = $word_image_id > 0 ? get_post($word_image_id) : null;

    return [
        'id' => $word_image_id,
        'sync_id' => $word_image_id > 0 ? ll_tools_site_sync_get_or_create_post_uuid($word_image_id, $ensure_sync_ids) : '',
        'slug' => $word_image_post instanceof WP_Post ? (string) $word_image_post->post_name : '',
        'title' => $word_image_post instanceof WP_Post ? (string) get_the_title($word_image_id) : '',
        'status' => $word_image_post instanceof WP_Post ? (string) $word_image_post->post_status : '',
        'source' => $source,
        'attachment' => ll_tools_site_sync_attachment_media($attachment_id),
    ];
}

function ll_tools_site_sync_record_media(int $recording_id, int $word_id, bool $ensure_sync_ids = true): array {
    return [
        'audio' => ll_tools_site_sync_audio_media($recording_id),
        'word_image' => ll_tools_site_sync_word_image_media($word_id, $ensure_sync_ids),
    ];
}

function ll_tools_site_sync_normalize_record_media(array $media): array {
    $audio = (array) ($media['audio'] ?? []);
    $word_image = (array) ($media['word_image'] ?? []);
    $attachment = (array) ($word_image['attachment'] ?? []);

    return [
        'audio' => [
            'path' => (string) ($audio['path'] ?? ''),
            'url' => ll_tools_site_sync_normalize_remote_url($audio['url'] ?? ''),
            'mime_type' => sanitize_mime_type((string) ($audio['mime_type'] ?? '')),
            'has_local_file' => !empty($audio['has_local_file']),
        ],
        'word_image' => [
            'id' => (int) ($word_image['id'] ?? 0),
            'sync_id' => trim((string) ($word_image['sync_id'] ?? '')),
            'slug' => sanitize_title((string) ($word_image['slug'] ?? '')),
            'title' => (string) ($word_image['title'] ?? ''),
            'status' => sanitize_key((string) ($word_image['status'] ?? '')),
            'source' => sanitize_key((string) ($word_image['source'] ?? '')),
            'attachment' => [
                'id' => (int) ($attachment['id'] ?? 0),
                'url' => ll_tools_site_sync_normalize_remote_url($attachment['url'] ?? ''),
                'source_url' => ll_tools_site_sync_normalize_remote_url(($attachment['source_url'] ?? '') ?: ($attachment['url'] ?? '')),
                'mime_type' => sanitize_mime_type((string) ($attachment['mime_type'] ?? '')),
                'title' => (string) ($attachment['title'] ?? ''),
                'alt' => (string) ($attachment['alt'] ?? ''),
                'width' => max(0, (int) ($attachment['width'] ?? 0)),
                'height' => max(0, (int) ($attachment['height'] ?? 0)),
                'has_local_file' => !empty($attachment['has_local_file']),
            ],
        ],
    ];
}

function ll_tools_site_sync_record_natural_key(string $word_slug, string $recording_slug, array $recording_types): string {
    $recording_types = array_values(array_unique(array_filter(array_map('sanitize_title', $recording_types))));
    sort($recording_types);
    return 'word:' . sanitize_title($word_slug) . '|audio:' . sanitize_title($recording_slug) . '|types:' . implode(',', $recording_types);
}

function ll_tools_site_sync_word_categories(int $word_id, bool $ensure_sync_ids = true): array {
    if ($word_id <= 0) {
        return [];
    }

    $terms = wp_get_post_terms($word_id, 'word-category');
    if (is_wp_error($terms)) {
        return [];
    }

    $categories = [];
    foreach ((array) $terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }

        $parent_slug = '';
        if ($term->parent > 0) {
            $raw_parent_slug = get_term_field('slug', (int) $term->parent, 'word-category');
            $parent_slug = is_wp_error($raw_parent_slug) ? '' : (string) $raw_parent_slug;
        }

        $categories[] = [
            'id' => (int) $term->term_id,
            'sync_id' => ll_tools_site_sync_get_or_create_term_uuid((int) $term->term_id, $ensure_sync_ids),
            'slug' => (string) $term->slug,
            'name' => (string) $term->name,
            'parent_slug' => $parent_slug,
        ];
    }

    usort($categories, static function (array $a, array $b): int {
        return strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
    });

    return $categories;
}

function ll_tools_site_sync_normalize_snapshot_args(array $args = []): array {
    $max_per_page = max(1, (int) apply_filters('ll_tools_site_sync_snapshot_max_per_page', 250));
    $limit = isset($args['limit']) ? max(0, (int) $args['limit']) : 0;
    if ($limit > 0) {
        $limit = min($limit, $max_per_page);
    }

    return [
        'include_media' => !array_key_exists('include_media', $args) || !empty($args['include_media']),
        'limit' => $limit,
        'offset' => isset($args['offset']) ? max(0, (int) $args['offset']) : 0,
    ];
}

function ll_tools_site_sync_collect_transcription_records(int $wordset_id, bool $ensure_sync_ids = true, array $args = []): array {
    $result = ll_tools_site_sync_collect_transcription_record_page($wordset_id, $ensure_sync_ids, $args);
    return (array) ($result['records'] ?? []);
}

function ll_tools_site_sync_collect_transcription_record_page(int $wordset_id, bool $ensure_sync_ids = true, array $args = []): array {
    if ($wordset_id <= 0) {
        return [
            'records' => [],
            'total' => 0,
        ];
    }

    $snapshot_args = ll_tools_site_sync_normalize_snapshot_args($args);
    $limit = (int) $snapshot_args['limit'];
    $offset = (int) $snapshot_args['offset'];
    $include_media = !empty($snapshot_args['include_media']);

    $word_ids = get_posts([
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'tax_query' => [
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$wordset_id],
            ],
        ],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', $word_ids)));
    if (empty($word_ids)) {
        return [
            'records' => [],
            'total' => 0,
        ];
    }

    $audio_query_args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit > 0 ? $limit : -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'post_parent__in' => $word_ids,
        'no_found_rows' => $limit <= 0,
    ];
    if ($limit > 0 && $offset > 0) {
        $audio_query_args['offset'] = $offset;
    }

    $audio_query = new WP_Query($audio_query_args);
    $audio_posts = (array) $audio_query->posts;
    $total_records = $limit > 0 ? (int) $audio_query->found_posts : count($audio_posts);

    $records = [];
    foreach ($audio_posts as $audio_post) {
        if (!($audio_post instanceof WP_Post)) {
            continue;
        }

        $word_id = (int) $audio_post->post_parent;
        if ($word_id <= 0) {
            continue;
        }

        $word_post = get_post($word_id);
        if (!($word_post instanceof WP_Post) || $word_post->post_type !== 'words') {
            continue;
        }

        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        $recording_types = is_wp_error($recording_types) ? [] : array_values(array_map('strval', (array) $recording_types));
        sort($recording_types);

        $values = ll_tools_site_sync_record_values((int) $audio_post->ID, $wordset_id);
        $word_sync_id = ll_tools_site_sync_get_or_create_post_uuid($word_id, $ensure_sync_ids);
        $sync_id = ll_tools_site_sync_get_or_create_post_uuid((int) $audio_post->ID, $ensure_sync_ids);
        $word_slug = (string) $word_post->post_name;
        $recording_slug = (string) $audio_post->post_name;
        $word_translation = (string) get_post_meta($word_id, 'word_translation', true);
        $word_english_meaning = (string) get_post_meta($word_id, 'word_english_meaning', true);

        $record = [
            'record_type' => 'word_audio_transcription',
            'sync_id' => $sync_id,
            'natural_key' => ll_tools_site_sync_record_natural_key($word_slug, $recording_slug, $recording_types),
            'word' => [
                'id' => $word_id,
                'sync_id' => $word_sync_id,
                'slug' => $word_slug,
                'title' => get_the_title($word_id),
                'status' => (string) $word_post->post_status,
                'word_translation' => $word_translation,
                'word_english_meaning' => $word_english_meaning,
                'categories' => ll_tools_site_sync_word_categories($word_id, $ensure_sync_ids),
            ],
            'recording' => [
                'id' => (int) $audio_post->ID,
                'slug' => $recording_slug,
                'title' => get_the_title((int) $audio_post->ID),
                'types' => $recording_types,
            ],
            'values' => $values,
            'value_hash' => ll_tools_site_sync_value_hash($values),
        ];
        if ($include_media) {
            $record['media'] = ll_tools_site_sync_record_media((int) $audio_post->ID, $word_id, $ensure_sync_ids);
        }

        $records[] = $record;
    }

    usort($records, static function (array $a, array $b): int {
        $a_key = (string) ($a['natural_key'] ?? '');
        $b_key = (string) ($b['natural_key'] ?? '');
        if ($a_key === $b_key) {
            return (int) (($a['recording']['id'] ?? 0) <=> ($b['recording']['id'] ?? 0));
        }
        return strcmp($a_key, $b_key);
    });

    return [
        'records' => $records,
        'total' => $total_records,
    ];
}

function ll_tools_site_sync_build_snapshot(int $wordset_id, string $surface = 'transcriptions', bool $ensure_sync_ids = true, array $args = []) {
    $surface = ll_tools_site_sync_normalize_surface($surface);
    $wordset = get_term($wordset_id, 'wordset');
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error(
            'll_tools_site_sync_invalid_wordset',
            __('Select a valid word set for site sync.', 'll-tools-text-domain')
        );
    }

    $records = [];
    $total_records = 0;
    $snapshot_args = ll_tools_site_sync_normalize_snapshot_args($args);
    if ($surface === 'transcriptions') {
        $record_page = ll_tools_site_sync_collect_transcription_record_page($wordset_id, $ensure_sync_ids, $snapshot_args);
        $records = (array) ($record_page['records'] ?? []);
        $total_records = (int) ($record_page['total'] ?? count($records));
    }

    $snapshot = [
        'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
        'surface' => $surface,
        'generated_at_gmt' => gmdate('c'),
        'site_url' => home_url('/'),
        'plugin_version' => defined('LL_TOOLS_VERSION') ? LL_TOOLS_VERSION : '',
        'wordset' => [
            'id' => (int) $wordset->term_id,
            'sync_id' => ll_tools_site_sync_get_or_create_term_uuid((int) $wordset->term_id, $ensure_sync_ids),
            'slug' => (string) $wordset->slug,
            'name' => (string) $wordset->name,
        ],
        'record_count' => $total_records,
        'records_returned' => count($records),
        'include_media' => !empty($snapshot_args['include_media']),
        'records' => $records,
    ];

    if ((int) $snapshot_args['limit'] > 0) {
        $next_offset = (int) $snapshot_args['offset'] + count($records);
        $snapshot['pagination'] = [
            'limit' => (int) $snapshot_args['limit'],
            'offset' => (int) $snapshot_args['offset'],
            'returned_count' => count($records),
            'total_count' => $total_records,
            'has_more' => $next_offset < $total_records,
            'next_offset' => $next_offset < $total_records ? $next_offset : null,
        ];
    }

    return $snapshot;
}

function ll_tools_site_sync_record_lookup_key(array $record): string {
    $sync_id = trim((string) ($record['sync_id'] ?? ''));
    if ($sync_id !== '') {
        return 'sync:' . $sync_id;
    }

    $natural_key = trim((string) ($record['natural_key'] ?? ''));
    return $natural_key !== '' ? 'natural:' . $natural_key : '';
}

function ll_tools_site_sync_index_snapshot(array $snapshot): array {
    $index = [
        'by_sync_id' => [],
        'by_natural_key' => [],
        'records' => [],
    ];

    foreach ((array) ($snapshot['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }

        $record['values'] = ll_tools_site_sync_normalize_record_values((array) ($record['values'] ?? []));
        $record['media'] = ll_tools_site_sync_normalize_record_media((array) ($record['media'] ?? []));
        $lookup_key = ll_tools_site_sync_record_lookup_key($record);
        if ($lookup_key !== '') {
            $index['records'][$lookup_key] = $record;
        }

        $sync_id = trim((string) ($record['sync_id'] ?? ''));
        if ($sync_id !== '') {
            $index['by_sync_id'][$sync_id] = $record;
        }

        $natural_key = trim((string) ($record['natural_key'] ?? ''));
        if ($natural_key !== '') {
            $index['by_natural_key'][$natural_key] = $record;
        }
    }

    return $index;
}

function ll_tools_site_sync_find_matching_record(array $record, array $index): ?array {
    $sync_id = trim((string) ($record['sync_id'] ?? ''));
    if ($sync_id !== '' && isset($index['by_sync_id'][$sync_id]) && is_array($index['by_sync_id'][$sync_id])) {
        return $index['by_sync_id'][$sync_id];
    }

    $natural_key = trim((string) ($record['natural_key'] ?? ''));
    if ($natural_key !== '' && isset($index['by_natural_key'][$natural_key]) && is_array($index['by_natural_key'][$natural_key])) {
        return $index['by_natural_key'][$natural_key];
    }

    return null;
}

function ll_tools_site_sync_values_equal($a, $b): bool {
    if (is_array($a) || is_array($b)) {
        $a = array_values(array_map('strval', (array) $a));
        $b = array_values(array_map('strval', (array) $b));
        sort($a);
        sort($b);
        return $a === $b;
    }

    if (is_bool($a) || is_bool($b)) {
        return (bool) $a === (bool) $b;
    }

    return (string) $a === (string) $b;
}

function ll_tools_site_sync_build_conflict_note(array $conflict): string {
    $field = (string) ($conflict['field'] ?? '');
    $word_title = (string) ($conflict['word_title'] ?? '');
    $recording_title = (string) ($conflict['recording_title'] ?? '');
    $live_value = $conflict['remote_value'] ?? '';
    $staging_value = $conflict['local_value'] ?? '';
    $base_value = $conflict['base_value'] ?? '';

    $format_value = static function ($value): string {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    };

    $note = sprintf(
        /* translators: 1: field key, 2: word title, 3: recording title */
        __('Site sync conflict for %1$s on "%2$s" / "%3$s".', 'll-tools-text-domain'),
        $field,
        $word_title,
        $recording_title
    );

    return $note . "\n\n"
        . __('Live value:', 'll-tools-text-domain') . "\n" . $format_value($live_value) . "\n\n"
        . __('Staging value:', 'll-tools-text-domain') . "\n" . $format_value($staging_value) . "\n\n"
        . __('Last pulled value:', 'll-tools-text-domain') . "\n" . $format_value($base_value);
}

function ll_tools_site_sync_plan_empty(string $direction, array $local_snapshot, array $remote_snapshot, array $base_snapshot): array {
    return [
        'direction' => $direction,
        'surface' => ll_tools_site_sync_normalize_surface((string) ($local_snapshot['surface'] ?? $remote_snapshot['surface'] ?? 'transcriptions')),
        'generated_at_gmt' => gmdate('c'),
        'local_wordset' => $local_snapshot['wordset'] ?? [],
        'remote_wordset' => $remote_snapshot['wordset'] ?? [],
        'has_base_snapshot' => !empty($base_snapshot['records']),
        'actions' => [],
        'conflicts' => [],
        'skipped' => [],
        'remote_updates' => [],
        'conflict_review_updates' => [],
        'stats' => [
            'records_checked' => 0,
            'fields_checked' => 0,
            'fields_to_apply' => 0,
            'media_refs_to_apply' => 0,
            'words_to_create' => 0,
            'records_to_create' => 0,
            'conflicts' => 0,
            'skipped' => 0,
        ],
    ];
}

function ll_tools_site_sync_build_push_plan(array $local_snapshot, array $remote_snapshot, array $base_snapshot = []): array {
    $plan = ll_tools_site_sync_plan_empty('push', $local_snapshot, $remote_snapshot, $base_snapshot);
    $remote_index = ll_tools_site_sync_index_snapshot($remote_snapshot);
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $seen_remote_keys = [];

    foreach ((array) ($local_snapshot['records'] ?? []) as $local_record) {
        if (!is_array($local_record)) {
            continue;
        }

        $plan['stats']['records_checked']++;
        $remote_record = ll_tools_site_sync_find_matching_record($local_record, $remote_index);
        if ($remote_record === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_remote_record',
                'record' => $local_record,
            ];
            $plan['stats']['skipped']++;
            continue;
        }

        $remote_key = ll_tools_site_sync_record_lookup_key($remote_record);
        if ($remote_key !== '') {
            $seen_remote_keys[$remote_key] = true;
        }

        $base_record = ll_tools_site_sync_find_matching_record($local_record, $base_index);
        if ($base_record === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_base_record',
                'record' => $local_record,
                'remote_record' => $remote_record,
            ];
            $plan['stats']['skipped']++;
            continue;
        }

        $local_values = ll_tools_site_sync_normalize_record_values((array) ($local_record['values'] ?? []));
        $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
        $base_values = ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
        $push_fields = [];
        $conflict_fields = [];

        foreach (ll_tools_site_sync_transcription_value_keys() as $field) {
            $plan['stats']['fields_checked']++;
            $local_value = $local_values[$field] ?? '';
            $remote_value = $remote_values[$field] ?? '';
            $base_value = $base_values[$field] ?? '';

            if (ll_tools_site_sync_values_equal($local_value, $remote_value)) {
                continue;
            }

            $local_changed = !ll_tools_site_sync_values_equal($local_value, $base_value);
            $remote_changed = !ll_tools_site_sync_values_equal($remote_value, $base_value);

            if ($local_changed && !$remote_changed) {
                $push_fields[$field] = $local_value;
                continue;
            }

            if ($local_changed && $remote_changed) {
                $conflict = [
                    'field' => $field,
                    'local_value' => $local_value,
                    'remote_value' => $remote_value,
                    'base_value' => $base_value,
                    'word_title' => (string) (($local_record['word']['title'] ?? '') ?: ($remote_record['word']['title'] ?? '')),
                    'recording_title' => (string) (($local_record['recording']['title'] ?? '') ?: ($remote_record['recording']['title'] ?? '')),
                    'local_record' => $local_record,
                    'remote_record' => $remote_record,
                ];
                $conflict_fields[] = $conflict;
                $plan['conflicts'][] = $conflict;
            }
        }

        if (!empty($push_fields)) {
            $update = ['recording_id' => (int) ($remote_record['recording']['id'] ?? 0)];
            foreach ($push_fields as $field => $value) {
                if (in_array($field, ['recording_text', 'recording_ipa'], true)) {
                    $update[$field] = $value;
                }
            }
            if (array_key_exists('needs_review', $push_fields) || array_key_exists('review_fields', $push_fields) || array_key_exists('review_note', $push_fields)) {
                $update['needs_review'] = (bool) $local_values['needs_review'];
                $update['review_fields'] = (array) $local_values['review_fields'];
                $update['review_note'] = (string) $local_values['review_note'];
            }

            $plan['actions'][] = [
                'type' => 'push',
                'fields' => array_keys($push_fields),
                'local_record' => $local_record,
                'remote_record' => $remote_record,
            ];
            $plan['remote_updates'][] = $update;
            $plan['stats']['fields_to_apply'] += count($push_fields);
        }

        foreach ($conflict_fields as $conflict) {
            $review_field = in_array((string) $conflict['field'], ['recording_text', 'recording_ipa'], true)
                ? (string) $conflict['field']
                : 'recording_ipa';
            $plan['conflict_review_updates'][] = [
                'recording_id' => (int) ($remote_record['recording']['id'] ?? 0),
                'needs_review' => true,
                'review_fields' => [$review_field],
                'review_note' => ll_tools_site_sync_build_conflict_note($conflict),
            ];
        }
    }

    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }
        $remote_key = ll_tools_site_sync_record_lookup_key($remote_record);
        if ($remote_key !== '' && isset($seen_remote_keys[$remote_key])) {
            continue;
        }
        if (ll_tools_site_sync_find_matching_record($remote_record, ll_tools_site_sync_index_snapshot($local_snapshot)) === null) {
            $plan['skipped'][] = [
                'reason' => 'missing_local_record',
                'remote_record' => $remote_record,
            ];
            $plan['stats']['skipped']++;
        }
    }

    $plan['stats']['conflicts'] = count($plan['conflicts']);
    return $plan;
}

function ll_tools_site_sync_build_media_pull_fields(array $local_record, array $remote_record): array {
    $local_media = ll_tools_site_sync_normalize_record_media((array) ($local_record['media'] ?? []));
    $remote_media = ll_tools_site_sync_normalize_record_media((array) ($remote_record['media'] ?? []));
    $fields = [];

    $remote_audio_url = (string) ($remote_media['audio']['url'] ?? '');
    if ($remote_audio_url !== '' && empty($local_media['audio']['has_local_file'])) {
        $local_audio_url = (string) (($local_media['audio']['url'] ?? '') ?: ($local_media['audio']['path'] ?? ''));
        if ($local_audio_url === '' || !ll_tools_site_sync_urls_equal($local_audio_url, $remote_audio_url)) {
            $fields[] = 'audio_file_path';
        }
    }

    $remote_attachment = (array) ($remote_media['word_image']['attachment'] ?? []);
    $remote_image_url = (string) (($remote_attachment['source_url'] ?? '') ?: ($remote_attachment['url'] ?? ''));
    if ($remote_image_url !== '' && empty($local_media['word_image']['attachment']['has_local_file'])) {
        $local_attachment = (array) ($local_media['word_image']['attachment'] ?? []);
        $local_image_url = (string) (($local_attachment['source_url'] ?? '') ?: ($local_attachment['url'] ?? ''));
        $local_word_image_id = (int) ($local_media['word_image']['id'] ?? 0);
        if ($local_word_image_id <= 0 || $local_image_url === '' || !ll_tools_site_sync_urls_equal($local_image_url, $remote_image_url)) {
            $fields[] = 'word_image';
        }
    }

    return array_values(array_unique($fields));
}

function ll_tools_site_sync_find_local_word_for_remote_record(array $remote_record, int $local_wordset_id, bool $allow_mismatched_sync_fallback = true): int {
    if ($local_wordset_id <= 0) {
        return 0;
    }

    $remote_word = (array) ($remote_record['word'] ?? []);
    $remote_word_sync_id = trim((string) ($remote_word['sync_id'] ?? ''));
    if ($remote_word_sync_id !== '') {
        $matches = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$local_wordset_id],
                ],
            ],
            'meta_query' => [
                [
                    'key' => ll_tools_site_sync_uuid_meta_key(),
                    'value' => $remote_word_sync_id,
                ],
            ],
        ]);
        if (!empty($matches)) {
            return (int) $matches[0];
        }
    }

    $remote_word_slug = sanitize_title((string) ($remote_word['slug'] ?? ''));
    if ($remote_word_slug !== '') {
        $matches = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'name' => $remote_word_slug,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$local_wordset_id],
                ],
            ],
        ]);
        if (!empty($matches)) {
            $candidate_id = (int) $matches[0];
            $candidate_sync_id = trim((string) get_post_meta($candidate_id, ll_tools_site_sync_uuid_meta_key(), true));
            if (!$allow_mismatched_sync_fallback && $remote_word_sync_id !== '' && $candidate_sync_id !== '' && $candidate_sync_id !== $remote_word_sync_id) {
                return 0;
            }
            return $candidate_id;
        }
    }

    $remote_word_title = trim((string) ($remote_word['title'] ?? ''));
    if ($remote_word_title === '') {
        return 0;
    }

    $matches = get_posts([
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'title' => $remote_word_title,
        'posts_per_page' => 2,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'tax_query' => [
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$local_wordset_id],
            ],
        ],
    ]);

    if (count((array) $matches) !== 1) {
        return 0;
    }

    $candidate_id = (int) $matches[0];
    $candidate_sync_id = trim((string) get_post_meta($candidate_id, ll_tools_site_sync_uuid_meta_key(), true));
    if (!$allow_mismatched_sync_fallback && $remote_word_sync_id !== '' && $candidate_sync_id !== '' && $candidate_sync_id !== $remote_word_sync_id) {
        return 0;
    }

    return $candidate_id;
}

function ll_tools_site_sync_remote_word_create_key(array $remote_record): string {
    $remote_word = (array) ($remote_record['word'] ?? []);
    $sync_id = trim((string) ($remote_word['sync_id'] ?? ''));
    if ($sync_id !== '') {
        return 'sync:' . $sync_id;
    }

    $slug = sanitize_title((string) ($remote_word['slug'] ?? ''));
    if ($slug !== '') {
        return 'slug:' . $slug;
    }

    $title = trim((string) ($remote_word['title'] ?? ''));
    return $title !== '' ? 'title:' . strtolower($title) : '';
}

function ll_tools_site_sync_find_local_category_by_sync_id(string $sync_id, int $wordset_id): int {
    $sync_id = trim($sync_id);
    if ($sync_id === '') {
        return 0;
    }

    $matches = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'number' => 5,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => ll_tools_site_sync_uuid_meta_key(),
                'value' => $sync_id,
            ],
        ],
    ]);
    if (is_wp_error($matches) || empty($matches)) {
        return 0;
    }

    foreach ((array) $matches as $term_id) {
        $term_id = (int) $term_id;
        $owner_id = function_exists('ll_tools_get_category_wordset_owner_id')
            ? (int) ll_tools_get_category_wordset_owner_id($term_id)
            : 0;
        if ($owner_id <= 0 || $owner_id === $wordset_id) {
            return $term_id;
        }
    }

    return 0;
}

function ll_tools_site_sync_get_or_create_local_category_from_remote(array $remote_category, int $wordset_id): int {
    if ($wordset_id <= 0) {
        return 0;
    }

    $sync_id = trim((string) ($remote_category['sync_id'] ?? ''));
    $term_id = ll_tools_site_sync_find_local_category_by_sync_id($sync_id, $wordset_id);
    if ($term_id > 0) {
        return $term_id;
    }

    $slug = sanitize_title((string) ($remote_category['slug'] ?? ''));
    if ($slug !== '') {
        $existing = get_term_by('slug', $slug, 'word-category');
        if ($existing instanceof WP_Term) {
            $owner_id = function_exists('ll_tools_get_category_wordset_owner_id')
                ? (int) ll_tools_get_category_wordset_owner_id($existing)
                : 0;
            if ($owner_id <= 0 || $owner_id === $wordset_id) {
                $term_id = (int) $existing->term_id;
                if ($sync_id !== '') {
                    update_term_meta($term_id, ll_tools_site_sync_uuid_meta_key(), $sync_id);
                }
                return $term_id;
            }
        }
    }

    $name = trim((string) ($remote_category['name'] ?? ''));
    if ($name === '') {
        $name = $slug !== '' ? $slug : __('Synced category', 'll-tools-text-domain');
    }

    $insert_args = [];
    if ($slug !== '') {
        $insert_args['slug'] = $slug;
    }

    $inserted = wp_insert_term($name, 'word-category', $insert_args);
    if (is_wp_error($inserted) && $inserted->get_error_code() === 'term_exists') {
        $term_id = (int) $inserted->get_error_data();
    } elseif (is_wp_error($inserted)) {
        return 0;
    } else {
        $term_id = (int) ($inserted['term_id'] ?? 0);
    }

    if ($term_id > 0) {
        if ($sync_id !== '') {
            update_term_meta($term_id, ll_tools_site_sync_uuid_meta_key(), $sync_id);
        }
        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($term_id, $wordset_id, $term_id);
        }
    }

    return $term_id;
}

function ll_tools_site_sync_apply_remote_word_metadata(int $word_id, int $wordset_id, array $remote_record): void {
    if ($word_id <= 0) {
        return;
    }

    $remote_word = (array) ($remote_record['word'] ?? []);
    $remote_word_sync_id = trim((string) ($remote_word['sync_id'] ?? ''));
    if ($remote_word_sync_id !== '') {
        update_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), $remote_word_sync_id);
    }

    $word_translation = (string) ($remote_word['word_translation'] ?? '');
    if ($word_translation !== '') {
        update_post_meta($word_id, 'word_translation', $word_translation);
    }

    $word_english_meaning = (string) ($remote_word['word_english_meaning'] ?? '');
    if ($word_english_meaning !== '') {
        update_post_meta($word_id, 'word_english_meaning', $word_english_meaning);
    } elseif ($word_translation !== '') {
        update_post_meta($word_id, 'word_english_meaning', $word_translation);
    }

    wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

    $category_ids = [];
    foreach ((array) ($remote_word['categories'] ?? []) as $remote_category) {
        if (!is_array($remote_category)) {
            continue;
        }
        $category_id = ll_tools_site_sync_get_or_create_local_category_from_remote($remote_category, $wordset_id);
        if ($category_id > 0) {
            $category_ids[] = $category_id;
        }
    }
    if (!empty($category_ids)) {
        wp_set_object_terms($word_id, array_values(array_unique($category_ids)), 'word-category', false);
    }
}

function ll_tools_site_sync_ensure_local_word_for_remote_record(array $remote_record, int $local_wordset_id, bool $allow_mismatched_sync_fallback = false) {
    $existing_word_id = ll_tools_site_sync_find_local_word_for_remote_record($remote_record, $local_wordset_id, $allow_mismatched_sync_fallback);
    if ($existing_word_id > 0) {
        ll_tools_site_sync_apply_remote_word_metadata($existing_word_id, $local_wordset_id, $remote_record);
        return [
            'word_id' => $existing_word_id,
            'created' => false,
        ];
    }

    $remote_word = (array) ($remote_record['word'] ?? []);
    $title = trim((string) ($remote_word['title'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($remote_record['recording']['title'] ?? ''));
    }
    if ($title === '') {
        $title = __('Synced word', 'll-tools-text-domain');
    }

    $status = sanitize_key((string) ($remote_word['status'] ?? 'publish'));
    if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        $status = 'publish';
    }

    $insert_args = [
        'post_type' => 'words',
        'post_status' => $status,
        'post_title' => sanitize_text_field($title),
    ];

    $slug = sanitize_title((string) ($remote_word['slug'] ?? ''));
    if ($slug !== '') {
        $insert_args['post_name'] = $slug;
    }

    $word_id = wp_insert_post($insert_args, true);
    if (is_wp_error($word_id)) {
        return $word_id;
    }
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return new WP_Error('ll_tools_site_sync_word_create_failed', __('Could not create the synced word.', 'll-tools-text-domain'));
    }

    ll_tools_site_sync_apply_remote_word_metadata($word_id, $local_wordset_id, $remote_record);

    return [
        'word_id' => $word_id,
        'created' => true,
    ];
}

function ll_tools_site_sync_local_record_stub_for_remote(array $remote_record, int $local_word_id): array {
    $word_post = $local_word_id > 0 ? get_post($local_word_id) : null;
    $remote_word = (array) ($remote_record['word'] ?? []);

    return [
        'record_type' => 'word_audio_transcription',
        'sync_id' => '',
        'natural_key' => '',
        'word' => [
            'id' => $local_word_id,
            'sync_id' => $local_word_id > 0 ? ll_tools_site_sync_get_or_create_post_uuid($local_word_id, false) : '',
            'slug' => $word_post instanceof WP_Post ? (string) $word_post->post_name : sanitize_title((string) ($remote_word['slug'] ?? '')),
            'title' => $local_word_id > 0 ? (string) get_the_title($local_word_id) : (string) ($remote_word['title'] ?? ''),
        ],
        'recording' => [
            'id' => 0,
            'slug' => '',
            'title' => '',
            'types' => (array) ($remote_record['recording']['types'] ?? []),
        ],
        'values' => ll_tools_site_sync_normalize_record_values([]),
        'value_hash' => ll_tools_site_sync_value_hash([]),
        'media' => ll_tools_site_sync_normalize_record_media([]),
    ];
}

function ll_tools_site_sync_find_external_attachment_id(string $source_url): int {
    $source_url = ll_tools_site_sync_normalize_remote_url($source_url);
    if ($source_url === '') {
        return 0;
    }

    $matches = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'meta_query' => [
            [
                'key' => '_ll_tools_external_source_url',
                'value' => $source_url,
            ],
        ],
    ]);

    return !empty($matches) ? (int) $matches[0] : 0;
}

function ll_tools_site_sync_ensure_external_image_attachment(string $source_url, array $remote_attachment, int $parent_post_id = 0) {
    $source_url = ll_tools_site_sync_normalize_remote_url($source_url);
    if ($source_url === '') {
        return new WP_Error('ll_tools_site_sync_invalid_remote_image_url', __('The remote image URL is invalid.', 'll-tools-text-domain'));
    }

    $existing_id = ll_tools_site_sync_find_external_attachment_id($source_url);
    if ($existing_id > 0) {
        ll_tools_site_sync_update_external_attachment_metadata($existing_id, $source_url, $remote_attachment);
        return $existing_id;
    }

    $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
    $basename = $path !== '' ? sanitize_file_name(basename(rawurldecode($path))) : '';
    if ($basename === '' || $basename === '.' || $basename === '..') {
        $basename = 'site-sync-remote-image';
    }

    $filetype = wp_check_filetype($basename, null);
    $mime_type = sanitize_mime_type((string) ($remote_attachment['mime_type'] ?? ''));
    if ($mime_type === '' && !empty($filetype['type'])) {
        $mime_type = (string) $filetype['type'];
    }
    if ($mime_type === '' || strpos($mime_type, 'image/') !== 0) {
        return new WP_Error('ll_tools_site_sync_invalid_remote_image_mime', __('The remote image is not a supported image type.', 'll-tools-text-domain'));
    }

    $title = trim((string) ($remote_attachment['title'] ?? ''));
    if ($title === '') {
        $title = (string) preg_replace('/\.[^.]+$/', '', $basename);
    }
    if ($title === '') {
        $title = __('Synced remote image', 'll-tools-text-domain');
    }

    $attachment_id = wp_insert_attachment([
        'guid' => $source_url,
        'post_mime_type' => $mime_type,
        'post_title' => sanitize_text_field($title),
        'post_content' => '',
        'post_status' => 'inherit',
    ], false, $parent_post_id);

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }
    if (!$attachment_id) {
        return new WP_Error('ll_tools_site_sync_remote_attachment_failed', __('Could not create the remote image placeholder attachment.', 'll-tools-text-domain'));
    }

    ll_tools_site_sync_update_external_attachment_metadata((int) $attachment_id, $source_url, $remote_attachment);
    return (int) $attachment_id;
}

function ll_tools_site_sync_update_external_attachment_metadata(int $attachment_id, string $source_url, array $remote_attachment): void {
    if ($attachment_id <= 0) {
        return;
    }

    update_post_meta($attachment_id, '_ll_tools_external_source_url', ll_tools_site_sync_normalize_remote_url($source_url));

    $alt = trim((string) ($remote_attachment['alt'] ?? ''));
    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
    }

    $width = max(0, (int) ($remote_attachment['width'] ?? 0));
    $height = max(0, (int) ($remote_attachment['height'] ?? 0));
    if ($width > 0 || $height > 0) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $metadata = is_array($metadata) ? $metadata : [];
        if ($width > 0) {
            $metadata['width'] = $width;
        }
        if ($height > 0) {
            $metadata['height'] = $height;
        }
        $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
        if (!isset($metadata['file']) && $path !== '') {
            $metadata['file'] = sanitize_file_name(basename(rawurldecode($path)));
        }
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
}

function ll_tools_site_sync_find_word_image_by_sync_id(string $sync_id): int {
    $sync_id = trim($sync_id);
    if ($sync_id === '') {
        return 0;
    }

    $matches = get_posts([
        'post_type' => 'word_images',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'meta_query' => [
            [
                'key' => ll_tools_site_sync_uuid_meta_key(),
                'value' => $sync_id,
            ],
        ],
    ]);

    return !empty($matches) ? (int) $matches[0] : 0;
}

function ll_tools_site_sync_find_word_image_by_slug(string $slug, int $wordset_id): int {
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return 0;
    }

    $matches = get_posts([
        'post_type' => 'word_images',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'name' => $slug,
        'posts_per_page' => 5,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    foreach ((array) $matches as $candidate_id) {
        $candidate_id = (int) $candidate_id;
        if ($candidate_id <= 0) {
            continue;
        }

        $owner_id = function_exists('ll_tools_get_word_image_wordset_owner_id')
            ? (int) ll_tools_get_word_image_wordset_owner_id($candidate_id)
            : 0;
        if ($owner_id <= 0 || $owner_id === $wordset_id) {
            return $candidate_id;
        }
    }

    return 0;
}

function ll_tools_site_sync_get_or_create_word_image_for_remote(int $word_id, int $wordset_id, array $remote_word_image) {
    $remote_word_image = ll_tools_site_sync_normalize_record_media(['word_image' => $remote_word_image])['word_image'];
    $remote_sync_id = trim((string) ($remote_word_image['sync_id'] ?? ''));
    $word_image_id = $remote_sync_id !== '' ? ll_tools_site_sync_find_word_image_by_sync_id($remote_sync_id) : 0;

    if ($word_image_id <= 0 && function_exists('ll_tools_get_canonical_word_image_post_id_for_word')) {
        $word_image_id = (int) ll_tools_get_canonical_word_image_post_id_for_word($word_id, true);
    }

    if ($word_image_id <= 0) {
        $word_image_id = ll_tools_site_sync_find_word_image_by_slug((string) ($remote_word_image['slug'] ?? ''), $wordset_id);
    }

    if ($word_image_id <= 0) {
        $title = trim((string) ($remote_word_image['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) get_the_title($word_id));
        }
        if ($title === '') {
            $title = __('Synced word image', 'll-tools-text-domain');
        }

        $status = sanitize_key((string) ($remote_word_image['status'] ?? 'publish'));
        if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
            $status = 'publish';
        }

        $insert_args = [
            'post_type' => 'word_images',
            'post_status' => $status,
            'post_title' => sanitize_text_field($title),
        ];
        $slug = sanitize_title((string) ($remote_word_image['slug'] ?? ''));
        if ($slug !== '') {
            $insert_args['post_name'] = $slug;
        }

        $created_id = wp_insert_post($insert_args, true);
        if (is_wp_error($created_id)) {
            return $created_id;
        }
        $word_image_id = (int) $created_id;
    }

    if ($word_image_id <= 0) {
        return new WP_Error('ll_tools_site_sync_word_image_missing', __('Could not create the synced word image.', 'll-tools-text-domain'));
    }

    if ($remote_sync_id !== '') {
        update_post_meta($word_image_id, ll_tools_site_sync_uuid_meta_key(), $remote_sync_id);
    }

    $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($category_ids) && !empty($category_ids)) {
        wp_set_post_terms($word_image_id, array_map('intval', (array) $category_ids), 'word-category', false);
    }

    if ($wordset_id > 0 && function_exists('ll_tools_set_word_image_wordset_owner')) {
        ll_tools_set_word_image_wordset_owner($word_image_id, $wordset_id, $word_image_id);
    }

    update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
    return $word_image_id;
}

function ll_tools_site_sync_apply_remote_audio_media(int $recording_id, array $remote_record): bool {
    $remote_media = ll_tools_site_sync_normalize_record_media((array) ($remote_record['media'] ?? []));
    $remote_url = (string) ($remote_media['audio']['url'] ?? '');
    if ($remote_url === '') {
        return false;
    }

    $current_path = trim((string) get_post_meta($recording_id, 'audio_file_path', true));
    if (ll_tools_site_sync_resolve_local_file_path($current_path) !== '') {
        return false;
    }

    $current_url = '';
    if ($current_path !== '') {
        $current_url = function_exists('ll_tools_resolve_audio_file_url')
            ? (string) ll_tools_resolve_audio_file_url($current_path)
            : $current_path;
    }

    if ($current_url !== '' && ll_tools_site_sync_urls_equal($current_url, $remote_url)) {
        return false;
    }

    update_post_meta($recording_id, 'audio_file_path', $remote_url);
    return true;
}

function ll_tools_site_sync_apply_remote_word_image_media(int $word_id, int $wordset_id, array $remote_record) {
    $remote_media = ll_tools_site_sync_normalize_record_media((array) ($remote_record['media'] ?? []));
    $remote_word_image = (array) ($remote_media['word_image'] ?? []);
    $remote_attachment = (array) ($remote_word_image['attachment'] ?? []);
    $remote_url = (string) (($remote_attachment['source_url'] ?? '') ?: ($remote_attachment['url'] ?? ''));
    if ($remote_url === '') {
        return false;
    }

    $current_word_image_id = function_exists('ll_tools_get_canonical_word_image_post_id_for_word')
        ? (int) ll_tools_get_canonical_word_image_post_id_for_word($word_id, true)
        : (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    $current_attachment_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
        ? (int) ll_tools_get_effective_word_image_attachment_id_for_word($word_id, true)
        : (int) get_post_thumbnail_id($word_id);

    if ($current_attachment_id > 0 && ll_tools_site_sync_attachment_has_local_file($current_attachment_id)) {
        return false;
    }

    $current_attachment = ll_tools_site_sync_attachment_media($current_attachment_id);
    $current_url = (string) (($current_attachment['source_url'] ?? '') ?: ($current_attachment['url'] ?? ''));
    if ($current_word_image_id > 0 && $current_url !== '' && ll_tools_site_sync_urls_equal($current_url, $remote_url)) {
        return false;
    }

    $word_image_id = ll_tools_site_sync_get_or_create_word_image_for_remote($word_id, $wordset_id, $remote_word_image);
    if (is_wp_error($word_image_id)) {
        return $word_image_id;
    }
    $word_image_id = (int) $word_image_id;

    if ($current_attachment_id > 0 && $current_url !== '' && ll_tools_site_sync_urls_equal($current_url, $remote_url)) {
        $attachment_id = $current_attachment_id;
    } else {
        $attachment_id = ll_tools_site_sync_ensure_external_image_attachment($remote_url, $remote_attachment, $word_image_id);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        $attachment_id = (int) $attachment_id;
    }

    $changed = false;
    if ((int) get_post_thumbnail_id($word_image_id) !== $attachment_id) {
        set_post_thumbnail($word_image_id, $attachment_id);
        $changed = true;
    }
    if ((int) get_post_thumbnail_id($word_id) !== $attachment_id) {
        set_post_thumbnail($word_id, $attachment_id);
        $changed = true;
    }
    if ((int) get_post_meta($word_id, '_ll_autopicked_image_id', true) !== $word_image_id) {
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        $changed = true;
    }

    return $changed;
}

function ll_tools_site_sync_apply_record_media_refs(int $recording_id, int $word_id, int $wordset_id, array $remote_record): array {
    $summary = [
        'updated' => 0,
        'errors' => [],
    ];

    if (ll_tools_site_sync_apply_remote_audio_media($recording_id, $remote_record)) {
        $summary['updated']++;
    }

    if ($word_id > 0) {
        $image_result = ll_tools_site_sync_apply_remote_word_image_media($word_id, $wordset_id, $remote_record);
        if (is_wp_error($image_result)) {
            $summary['errors'][] = $image_result->get_error_message();
        } elseif ($image_result) {
            $summary['updated']++;
        }
    }

    return $summary;
}

function ll_tools_site_sync_build_pull_plan(array $local_snapshot, array $remote_snapshot, array $base_snapshot = []): array {
    $plan = ll_tools_site_sync_plan_empty('pull', $local_snapshot, $remote_snapshot, $base_snapshot);
    $local_index = ll_tools_site_sync_index_snapshot($local_snapshot);
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $local_wordset_id = (int) ($local_snapshot['wordset']['id'] ?? 0);
    $word_create_keys = [];
    $allow_word_identity_fallback = empty($base_snapshot['records']);

    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }

        $plan['stats']['records_checked']++;
        $local_record = ll_tools_site_sync_find_matching_record($remote_record, $local_index);
        if ($local_record === null) {
            $local_word_id = ll_tools_site_sync_find_local_word_for_remote_record($remote_record, $local_wordset_id, $allow_word_identity_fallback);
            $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
            $local_record_stub = ll_tools_site_sync_local_record_stub_for_remote($remote_record, $local_word_id);
            $media_fields = ll_tools_site_sync_build_media_pull_fields($local_record_stub, $remote_record);
            $plan['actions'][] = [
                'type' => 'create_local_recording',
                'fields' => array_merge(ll_tools_site_sync_transcription_value_keys(), $media_fields),
                'value_fields' => ll_tools_site_sync_transcription_value_keys(),
                'media_fields' => $media_fields,
                'local_record' => $local_record_stub,
                'remote_record' => $remote_record,
                'values' => $remote_values,
            ];
            if ($local_word_id <= 0) {
                $word_create_key = ll_tools_site_sync_remote_word_create_key($remote_record);
                if ($word_create_key === '') {
                    $word_create_key = 'record:' . ll_tools_site_sync_record_lookup_key($remote_record);
                }
                if ($word_create_key !== '' && empty($word_create_keys[$word_create_key])) {
                    $word_create_keys[$word_create_key] = true;
                    $plan['stats']['words_to_create']++;
                }
            }
            $plan['stats']['records_to_create']++;
            $plan['stats']['fields_to_apply'] += count(ll_tools_site_sync_transcription_value_keys());
            $plan['stats']['media_refs_to_apply'] += count($media_fields);
            continue;
        }

        $base_record = ll_tools_site_sync_find_matching_record($remote_record, $base_index);
        $local_values = ll_tools_site_sync_normalize_record_values((array) ($local_record['values'] ?? []));
        $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
        $base_values = $base_record === null
            ? null
            : ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
        $pull_fields = [];

        foreach (ll_tools_site_sync_transcription_value_keys() as $field) {
            $plan['stats']['fields_checked']++;
            $local_value = $local_values[$field] ?? '';
            $remote_value = $remote_values[$field] ?? '';

            if (ll_tools_site_sync_values_equal($local_value, $remote_value)) {
                continue;
            }

            if ($base_values === null) {
                $pull_fields[$field] = $remote_value;
                continue;
            }

            $base_value = $base_values[$field] ?? '';
            $local_changed = !ll_tools_site_sync_values_equal($local_value, $base_value);
            $remote_changed = !ll_tools_site_sync_values_equal($remote_value, $base_value);

            if (!$local_changed && $remote_changed) {
                $pull_fields[$field] = $remote_value;
                continue;
            }

            if ($local_changed && $remote_changed) {
                $plan['conflicts'][] = [
                    'field' => $field,
                    'local_value' => $local_value,
                    'remote_value' => $remote_value,
                    'base_value' => $base_value,
                    'word_title' => (string) (($local_record['word']['title'] ?? '') ?: ($remote_record['word']['title'] ?? '')),
                    'recording_title' => (string) (($local_record['recording']['title'] ?? '') ?: ($remote_record['recording']['title'] ?? '')),
                    'local_record' => $local_record,
                    'remote_record' => $remote_record,
                ];
            }
        }

        $needs_sync_link = trim((string) ($remote_record['sync_id'] ?? '')) !== ''
            && trim((string) ($remote_record['sync_id'] ?? '')) !== trim((string) ($local_record['sync_id'] ?? ''));
        $remote_word_sync_id = trim((string) ($remote_record['word']['sync_id'] ?? ''));
        if ($remote_word_sync_id !== '' && $remote_word_sync_id !== trim((string) ($local_record['word']['sync_id'] ?? ''))) {
            $needs_sync_link = true;
        }

        $media_fields = ll_tools_site_sync_build_media_pull_fields($local_record, $remote_record);

        if (!empty($pull_fields) || $needs_sync_link || !empty($media_fields)) {
            $plan['actions'][] = [
                'type' => (!empty($pull_fields) || !empty($media_fields)) ? 'pull' : 'link_sync_id',
                'fields' => array_merge(array_keys($pull_fields), $media_fields),
                'value_fields' => array_keys($pull_fields),
                'media_fields' => $media_fields,
                'allow_word_sync_relink' => $allow_word_identity_fallback,
                'local_record' => $local_record,
                'remote_record' => $remote_record,
                'values' => $pull_fields,
            ];
            $plan['stats']['fields_to_apply'] += count($pull_fields);
            $plan['stats']['media_refs_to_apply'] += count($media_fields);
        }
    }

    $plan['stats']['conflicts'] = count($plan['conflicts']);
    return $plan;
}

function ll_tools_site_sync_merge_base_snapshot_after_pull(array $base_snapshot, array $remote_snapshot, array $pull_plan): array {
    $base_index = ll_tools_site_sync_index_snapshot($base_snapshot);
    $conflict_fields = [];

    foreach ((array) ($pull_plan['conflicts'] ?? []) as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }
        $remote_record = (array) ($conflict['remote_record'] ?? []);
        $key = ll_tools_site_sync_record_lookup_key($remote_record);
        $field = (string) ($conflict['field'] ?? '');
        if ($key !== '' && $field !== '') {
            $conflict_fields[$key][$field] = true;
        }
    }

    $merged = $remote_snapshot;
    $records = [];
    foreach ((array) ($remote_snapshot['records'] ?? []) as $remote_record) {
        if (!is_array($remote_record)) {
            continue;
        }

        $key = ll_tools_site_sync_record_lookup_key($remote_record);
        $base_record = ll_tools_site_sync_find_matching_record($remote_record, $base_index);
        if ($key !== '' && $base_record !== null && !empty($conflict_fields[$key])) {
            $remote_values = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
            $base_values = ll_tools_site_sync_normalize_record_values((array) ($base_record['values'] ?? []));
            foreach (array_keys($conflict_fields[$key]) as $field) {
                if (array_key_exists($field, $base_values)) {
                    $remote_values[$field] = $base_values[$field];
                }
            }
            $remote_record['values'] = $remote_values;
            $remote_record['value_hash'] = ll_tools_site_sync_value_hash($remote_values);
        }

        $records[] = $remote_record;
    }

    $merged['records'] = $records;
    $merged['record_count'] = count($records);
    $merged['generated_at_gmt'] = gmdate('c');
    return $merged;
}

function ll_tools_site_sync_compact_base_snapshot(array $snapshot): array {
    $compacted = $snapshot;
    $records = [];

    foreach ((array) ($snapshot['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }

        $values = ll_tools_site_sync_normalize_record_values((array) ($record['values'] ?? []));
        $word = (array) ($record['word'] ?? []);
        $recording = (array) ($record['recording'] ?? []);
        $recording_types = array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            (array) ($recording['types'] ?? [])
        ))));
        sort($recording_types);

        $records[] = [
            'record_type' => (string) ($record['record_type'] ?? 'word_audio_transcription'),
            'sync_id' => trim((string) ($record['sync_id'] ?? '')),
            'natural_key' => trim((string) ($record['natural_key'] ?? '')),
            'word' => [
                'sync_id' => trim((string) ($word['sync_id'] ?? '')),
                'slug' => sanitize_title((string) ($word['slug'] ?? '')),
            ],
            'recording' => [
                'slug' => sanitize_title((string) ($recording['slug'] ?? '')),
                'types' => $recording_types,
            ],
            'values' => $values,
            'value_hash' => ll_tools_site_sync_value_hash($values),
        ];
    }

    $compacted['records'] = $records;
    $compacted['record_count'] = count($records);
    return $compacted;
}

function ll_tools_site_sync_apply_record_values(int $recording_id, int $wordset_id, array $values): array {
    $values = ll_tools_site_sync_normalize_record_values($values);
    $field_updates = [];
    $desired_recording_text = null;
    foreach (['recording_text', 'recording_ipa'] as $field) {
        if (array_key_exists($field, $values)) {
            $field_updates[$field] = (string) $values[$field];
            if ($field === 'recording_text') {
                $desired_recording_text = (string) $values[$field];
            }
        }
    }

    if (!empty($field_updates)) {
        if (function_exists('ll_tools_ipa_keyboard_update_recording_fields')) {
            ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, $field_updates);
        } else {
            foreach ($field_updates as $meta_key => $value) {
                $value = sanitize_text_field($value);
                if ($value === '') {
                    delete_post_meta($recording_id, $meta_key);
                } else {
                    update_post_meta($recording_id, $meta_key, $value);
                }
            }
        }
    }

    if ($desired_recording_text !== null) {
        $desired_recording_text = sanitize_text_field($desired_recording_text);
    }
    if ($desired_recording_text !== null && (string) get_post_meta($recording_id, 'recording_text', true) !== $desired_recording_text) {
        ll_tools_site_sync_write_recording_text($recording_id, $desired_recording_text);
    }

    if (array_key_exists('needs_review', $values)) {
        ll_tools_site_sync_apply_recording_review_state(
            $recording_id,
            (bool) $values['needs_review'],
            (array) ($values['review_fields'] ?? []),
            (string) ($values['review_note'] ?? '')
        );
    }

    return ll_tools_site_sync_record_values($recording_id, $wordset_id);
}

function ll_tools_site_sync_ensure_recording_type_term(string $slug): int {
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return 0;
    }

    $existing = get_term_by('slug', $slug, 'recording_type');
    if ($existing instanceof WP_Term) {
        return (int) $existing->term_id;
    }

    $label = ucwords(str_replace(['-', '_'], ' ', $slug));
    $inserted = wp_insert_term($label, 'recording_type', ['slug' => $slug]);
    if (is_wp_error($inserted) || !is_array($inserted)) {
        return 0;
    }

    return (int) ($inserted['term_id'] ?? 0);
}

function ll_tools_site_sync_apply_recording_types(int $recording_id, array $recording_types): void {
    if ($recording_id <= 0) {
        return;
    }

    $term_ids = [];
    foreach ($recording_types as $recording_type) {
        $term_id = ll_tools_site_sync_ensure_recording_type_term((string) $recording_type);
        if ($term_id > 0) {
            $term_ids[] = $term_id;
        }
    }

    wp_set_object_terms($recording_id, array_values(array_unique($term_ids)), 'recording_type', false);
}

function ll_tools_site_sync_create_local_recording_from_remote(int $word_id, int $wordset_id, array $remote_record) {
    if ($word_id <= 0) {
        return new WP_Error('ll_tools_site_sync_missing_local_word', __('Could not create the synced recording because the local word is missing.', 'll-tools-text-domain'));
    }

    $remote_recording = (array) ($remote_record['recording'] ?? []);
    $title = trim((string) ($remote_recording['title'] ?? ''));
    if ($title === '') {
        $title = trim((string) get_the_title($word_id));
    }
    if ($title === '') {
        $title = __('Synced recording', 'll-tools-text-domain');
    }

    $status = sanitize_key((string) ($remote_recording['status'] ?? 'publish'));
    if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        $status = 'publish';
    }

    $insert_args = [
        'post_type' => 'word_audio',
        'post_status' => $status,
        'post_parent' => $word_id,
        'post_title' => sanitize_text_field($title),
    ];

    $slug = sanitize_title((string) ($remote_recording['slug'] ?? ''));
    if ($slug !== '') {
        $insert_args['post_name'] = $slug;
    }

    $recording_id = wp_insert_post($insert_args, true);
    if (is_wp_error($recording_id)) {
        return $recording_id;
    }
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0) {
        return new WP_Error('ll_tools_site_sync_recording_create_failed', __('Could not create the synced recording.', 'll-tools-text-domain'));
    }

    $remote_recording_sync_id = trim((string) ($remote_record['sync_id'] ?? ''));
    if ($remote_recording_sync_id !== '') {
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), $remote_recording_sync_id);
    }

    $remote_word_sync_id = trim((string) ($remote_record['word']['sync_id'] ?? ''));
    if ($remote_word_sync_id !== '') {
        update_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), $remote_word_sync_id);
    }

    ll_tools_site_sync_apply_recording_types($recording_id, (array) ($remote_recording['types'] ?? []));
    ll_tools_site_sync_apply_record_values($recording_id, $wordset_id, (array) ($remote_record['values'] ?? []));

    return $recording_id;
}

function ll_tools_site_sync_apply_pull_plan(array $plan, int $local_wordset_id): array {
    $summary = [
        'words_created' => 0,
        'records_created' => 0,
        'records_updated' => 0,
        'recordings_reparented' => 0,
        'fields_updated' => 0,
        'media_refs_updated' => 0,
        'sync_ids_linked' => 0,
        'errors' => [],
    ];

    foreach ((array) ($plan['actions'] ?? []) as $action) {
        if (!is_array($action) || !in_array((string) ($action['type'] ?? ''), ['pull', 'link_sync_id', 'create_local_recording'], true)) {
            continue;
        }

        $local_record = (array) ($action['local_record'] ?? []);
        $remote_record = (array) ($action['remote_record'] ?? []);
        if ((string) ($action['type'] ?? '') === 'create_local_recording') {
            $local_word_id = (int) ($local_record['word']['id'] ?? 0);
            if ($local_word_id <= 0) {
                $word_result = ll_tools_site_sync_ensure_local_word_for_remote_record($remote_record, $local_wordset_id);
                if (is_wp_error($word_result)) {
                    $summary['errors'][] = $word_result->get_error_message();
                    continue;
                }

                $local_word_id = (int) ($word_result['word_id'] ?? 0);
                if (!empty($word_result['created'])) {
                    $summary['words_created']++;
                }
            } else {
                ll_tools_site_sync_apply_remote_word_metadata($local_word_id, $local_wordset_id, $remote_record);
            }

            $created_recording_id = ll_tools_site_sync_create_local_recording_from_remote($local_word_id, $local_wordset_id, $remote_record);
            if (is_wp_error($created_recording_id)) {
                $summary['errors'][] = $created_recording_id->get_error_message();
                continue;
            }

            $summary['records_created']++;
            $summary['fields_updated'] += count((array) ($action['value_fields'] ?? []));
            if (!empty($action['media_fields'])) {
                $media_result = ll_tools_site_sync_apply_record_media_refs((int) $created_recording_id, $local_word_id, $local_wordset_id, $remote_record);
                $summary['media_refs_updated'] += (int) ($media_result['updated'] ?? 0);
                foreach ((array) ($media_result['errors'] ?? []) as $error) {
                    $summary['errors'][] = (string) $error;
                }
            }
            continue;
        }

        $recording_id = (int) ($local_record['recording']['id'] ?? 0);
        if ($recording_id <= 0) {
            $summary['errors'][] = __('Skipped a pull row because the local recording ID was missing.', 'll-tools-text-domain');
            continue;
        }

        $local_sync_id = trim((string) ($local_record['sync_id'] ?? ''));
        $remote_sync_id = trim((string) ($remote_record['sync_id'] ?? ''));
        if ($remote_sync_id !== '' && $local_sync_id !== $remote_sync_id) {
            update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), $remote_sync_id);
            $summary['sync_ids_linked']++;
        }

        $remote_word_sync_id = trim((string) ($remote_record['word']['sync_id'] ?? ''));
        $local_word_id = (int) ($local_record['word']['id'] ?? 0);
        $local_word_sync_id = trim((string) ($local_record['word']['sync_id'] ?? ''));
        if ($local_word_id > 0 && $remote_word_sync_id !== '' && $local_word_sync_id !== $remote_word_sync_id) {
            if ($local_word_sync_id !== '' && empty($action['allow_word_sync_relink'])) {
                $word_result = ll_tools_site_sync_ensure_local_word_for_remote_record($remote_record, $local_wordset_id, false);
                if (is_wp_error($word_result)) {
                    $summary['errors'][] = $word_result->get_error_message();
                    continue;
                }

                $target_word_id = (int) ($word_result['word_id'] ?? 0);
                if (!empty($word_result['created'])) {
                    $summary['words_created']++;
                }
                if ($target_word_id > 0 && $target_word_id !== $local_word_id) {
                    $reparented = wp_update_post([
                        'ID' => $recording_id,
                        'post_parent' => $target_word_id,
                    ], true);
                    if (is_wp_error($reparented)) {
                        $summary['errors'][] = $reparented->get_error_message();
                        continue;
                    }
                    $local_word_id = $target_word_id;
                    $summary['recordings_reparented']++;
                }
            } else {
                update_post_meta($local_word_id, ll_tools_site_sync_uuid_meta_key(), $remote_word_sync_id);
                $summary['sync_ids_linked']++;
                ll_tools_site_sync_apply_remote_word_metadata($local_word_id, $local_wordset_id, $remote_record);
            }
        }

        if ((string) ($action['type'] ?? '') === 'pull') {
            $before = ll_tools_site_sync_record_values($recording_id, $local_wordset_id);
            $values = array_merge($before, (array) ($action['values'] ?? []));
            $after = ll_tools_site_sync_apply_record_values($recording_id, $local_wordset_id, $values);
            if ($after !== $before) {
                $summary['records_updated']++;
                $summary['fields_updated'] += count((array) ($action['value_fields'] ?? array_keys((array) ($action['values'] ?? []))));
            }

            if (!empty($action['media_fields'])) {
                $media_result = ll_tools_site_sync_apply_record_media_refs($recording_id, $local_word_id, $local_wordset_id, $remote_record);
                $summary['media_refs_updated'] += (int) ($media_result['updated'] ?? 0);
                foreach ((array) ($media_result['errors'] ?? []) as $error) {
                    $summary['errors'][] = (string) $error;
                }
            }
        }
    }

    return $summary;
}

function ll_tools_site_sync_snapshot_endpoint(WP_REST_Request $request) {
    if (!function_exists('ll_tools_rest_automation_resolve_wordset_term')) {
        return new WP_Error(
            'll_tools_site_sync_rest_unavailable',
            __('LL Tools automation helpers are unavailable.', 'll-tools-text-domain'),
            ['status' => 500]
        );
    }

    $wordset_term = ll_tools_rest_automation_resolve_wordset_term($request);
    if (is_wp_error($wordset_term)) {
        return $wordset_term;
    }

    $surface = ll_tools_site_sync_normalize_surface((string) ($request->get_param('surface') ?? 'transcriptions'));
    $ensure_sync_ids = !($request->get_param('ensure_sync_ids') === '0' || $request->get_param('ensure_sync_ids') === false);
    $include_media = !($request->get_param('include_media') === '0' || $request->get_param('include_media') === false);
    $per_page = max(0, (int) $request->get_param('per_page'));
    $page = max(1, (int) $request->get_param('page'));
    $offset_param = $request->get_param('offset');
    $offset = is_numeric($offset_param) ? max(0, (int) $offset_param) : ($per_page > 0 ? ($page - 1) * $per_page : 0);
    $snapshot = ll_tools_site_sync_build_snapshot((int) $wordset_term->term_id, $surface, $ensure_sync_ids, [
        'include_media' => $include_media,
        'limit' => $per_page,
        'offset' => $offset,
    ]);
    if (is_wp_error($snapshot)) {
        return $snapshot;
    }

    return rest_ensure_response($snapshot);
}

function ll_tools_site_sync_register_rest_routes(): void {
    register_rest_route('ll-tools/v1', '/wordsets/(?P<wordset>[^/]+)/site-sync/snapshot', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'll_tools_site_sync_snapshot_endpoint',
        'permission_callback' => function (WP_REST_Request $request) {
            if (!function_exists('ll_tools_rest_automation_require_wordset_access')) {
                return new WP_Error(
                    'll_tools_site_sync_rest_unavailable',
                    __('LL Tools automation helpers are unavailable.', 'll-tools-text-domain'),
                    ['status' => 500]
                );
            }
            return ll_tools_rest_automation_require_wordset_access($request);
        },
        'args' => [
            'surface' => [
                'required' => false,
                'type' => 'string',
            ],
            'ensure_sync_ids' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'include_media' => [
                'required' => false,
                'type' => 'boolean',
            ],
            'per_page' => [
                'required' => false,
                'type' => 'integer',
            ],
            'page' => [
                'required' => false,
                'type' => 'integer',
            ],
            'offset' => [
                'required' => false,
                'type' => 'integer',
            ],
        ],
    ]);
}
add_action('rest_api_init', 'll_tools_site_sync_register_rest_routes');
