<?php
/**
 * Admin maintenance screen for orphaned LL Tools media.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_ORPHAN_MEDIA_PAGE_SLUG')) {
    define('LL_TOOLS_ORPHAN_MEDIA_PAGE_SLUG', 'll-orphan-media');
}

if (!defined('LL_TOOLS_ORPHAN_MEDIA_OPTION_REGISTRY')) {
    define('LL_TOOLS_ORPHAN_MEDIA_OPTION_REGISTRY', 'll_tools_orphan_media_registry_v1');
}

if (!defined('LL_TOOLS_ORPHAN_MEDIA_OPTION_SNAPSHOT')) {
    define('LL_TOOLS_ORPHAN_MEDIA_OPTION_SNAPSHOT', 'll_tools_orphan_media_snapshot_v1');
}

if (!defined('LL_TOOLS_ORPHAN_MEDIA_NONCE_ACTION')) {
    define('LL_TOOLS_ORPHAN_MEDIA_NONCE_ACTION', 'll_tools_orphan_media_manage');
}

if (!defined('LL_TOOLS_ORPHAN_MEDIA_SNAPSHOT_MAX_AGE')) {
    define('LL_TOOLS_ORPHAN_MEDIA_SNAPSHOT_MAX_AGE', 21600);
}

if (!defined('LL_TOOLS_ORPHAN_MEDIA_STORED_ITEM_LIMIT')) {
    define('LL_TOOLS_ORPHAN_MEDIA_STORED_ITEM_LIMIT', 1000);
}

function ll_tools_orphan_media_get_page_slug(): string {
    return (string) LL_TOOLS_ORPHAN_MEDIA_PAGE_SLUG;
}

function ll_tools_orphan_media_get_maintenance_capability(): string {
    if (function_exists('ll_tools_get_settings_maintenance_capability')) {
        return (string) ll_tools_get_settings_maintenance_capability();
    }

    return 'manage_options';
}

function ll_tools_orphan_media_current_user_can_manage(): bool {
    return current_user_can(ll_tools_orphan_media_get_maintenance_capability());
}

function ll_tools_orphan_media_get_admin_url(array $args = []): string {
    if (function_exists('ll_tools_get_tools_page_url')) {
        return (string) ll_tools_get_tools_page_url(ll_tools_orphan_media_get_page_slug(), $args);
    }

    $base = add_query_arg(
        ['page' => ll_tools_orphan_media_get_page_slug()],
        admin_url('tools.php')
    );

    return !empty($args) ? (string) add_query_arg($args, $base) : (string) $base;
}

function ll_tools_orphan_media_get_valid_post_statuses(): array {
    return ['publish', 'draft', 'pending', 'private', 'future'];
}

function ll_tools_orphan_media_get_audio_extensions(): array {
    if (function_exists('ll_tools_get_allowed_recording_upload_mimes')) {
        $mimes = ll_tools_get_allowed_recording_upload_mimes();
        if (is_array($mimes) && !empty($mimes)) {
            return array_values(array_unique(array_map('sanitize_key', array_keys($mimes))));
        }
    }

    return ['mp3', 'wav', 'aac', 'm4a', 'mp4', 'ogg', 'oga', 'opus', 'webm'];
}

function ll_tools_orphan_media_get_uploads_info(): array {
    $uploads = wp_get_upload_dir();
    if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
        return [
            'basedir' => '',
            'baseurl' => '',
            'baseurl_path' => '',
        ];
    }

    $baseurl_path = (string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH);

    return [
        'basedir' => wp_normalize_path(untrailingslashit((string) $uploads['basedir'])),
        'baseurl' => untrailingslashit((string) $uploads['baseurl']),
        'baseurl_path' => wp_normalize_path(untrailingslashit($baseurl_path)),
    ];
}

function ll_tools_orphan_media_normalize_relative_upload_path(string $relative_path): string {
    $relative_path = wp_normalize_path(trim($relative_path));
    $relative_path = ltrim($relative_path, '/');
    $parts = array_values(array_filter(explode('/', $relative_path), static function ($part): bool {
        return $part !== '' && $part !== '.' && $part !== '..';
    }));

    return implode('/', $parts);
}

function ll_tools_orphan_media_relative_upload_path_from_absolute(string $absolute_path): string {
    $absolute_path = wp_normalize_path(trim($absolute_path));
    if ($absolute_path === '') {
        return '';
    }

    $uploads = ll_tools_orphan_media_get_uploads_info();
    $basedir = (string) ($uploads['basedir'] ?? '');
    if ($basedir === '') {
        return '';
    }

    $absolute_cmp = strtolower($absolute_path);
    $basedir_cmp = strtolower($basedir);
    if ($absolute_cmp !== $basedir_cmp && strpos($absolute_cmp, $basedir_cmp . '/') !== 0) {
        return '';
    }

    $relative = substr($absolute_path, strlen($basedir));
    if (!is_string($relative)) {
        return '';
    }

    return ll_tools_orphan_media_normalize_relative_upload_path($relative);
}

function ll_tools_orphan_media_relative_upload_path_from_audio_meta(string $stored_path): string {
    $stored_path = trim($stored_path);
    if ($stored_path === '') {
        return '';
    }

    $uploads = ll_tools_orphan_media_get_uploads_info();
    $basedir = (string) ($uploads['basedir'] ?? '');
    $baseurl = (string) ($uploads['baseurl'] ?? '');
    $baseurl_path = (string) ($uploads['baseurl_path'] ?? '');

    $url_candidate = esc_url_raw($stored_path);
    if ($url_candidate !== '' && preg_match('#^https?://#i', $url_candidate)) {
        if ($baseurl !== '' && stripos($url_candidate, $baseurl) === 0) {
            $relative = substr($url_candidate, strlen($baseurl));
            return ll_tools_orphan_media_normalize_relative_upload_path((string) $relative);
        }

        $url_path = (string) wp_parse_url($url_candidate, PHP_URL_PATH);
        $url_path = wp_normalize_path($url_path);
        if ($baseurl_path !== '' && $url_path !== '' && (strtolower($url_path) === strtolower($baseurl_path) || strpos(strtolower($url_path), strtolower($baseurl_path) . '/') === 0)) {
            $relative = substr($url_path, strlen($baseurl_path));
            return ll_tools_orphan_media_normalize_relative_upload_path((string) $relative);
        }

        return '';
    }

    $normalized = wp_normalize_path($stored_path);
    if ($basedir !== '' && (strtolower($normalized) === strtolower($basedir) || strpos(strtolower($normalized), strtolower($basedir) . '/') === 0)) {
        return ll_tools_orphan_media_relative_upload_path_from_absolute($normalized);
    }

    $abspath = wp_normalize_path(untrailingslashit(ABSPATH));
    if ($abspath !== '' && (strtolower($normalized) === strtolower($abspath) || strpos(strtolower($normalized), strtolower($abspath) . '/') === 0)) {
        $candidate = ll_tools_orphan_media_relative_upload_path_from_absolute($normalized);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    if ($baseurl_path !== '' && (strtolower($normalized) === strtolower($baseurl_path) || strpos(strtolower($normalized), strtolower($baseurl_path) . '/') === 0)) {
        $relative = substr($normalized, strlen($baseurl_path));
        return ll_tools_orphan_media_normalize_relative_upload_path((string) $relative);
    }

    if ($normalized[0] === '/') {
        $abspath_candidate = wp_normalize_path(ABSPATH . ltrim($normalized, '/'));
        $candidate = ll_tools_orphan_media_relative_upload_path_from_absolute($abspath_candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return ll_tools_orphan_media_normalize_relative_upload_path($normalized);
}

function ll_tools_orphan_media_get_audio_local_path(string $relative_path): string {
    $relative_path = ll_tools_orphan_media_normalize_relative_upload_path($relative_path);
    if ($relative_path === '') {
        return '';
    }

    $uploads = ll_tools_orphan_media_get_uploads_info();
    $basedir = (string) ($uploads['basedir'] ?? '');
    if ($basedir === '') {
        return '';
    }

    return wp_normalize_path(trailingslashit($basedir) . $relative_path);
}

function ll_tools_orphan_media_build_upload_url_from_relative(string $relative_path): string {
    $relative_path = ll_tools_orphan_media_normalize_relative_upload_path($relative_path);
    if ($relative_path === '') {
        return '';
    }

    $uploads = ll_tools_orphan_media_get_uploads_info();
    $baseurl = (string) ($uploads['baseurl'] ?? '');
    if ($baseurl === '') {
        return '';
    }

    $parts = array_map('rawurlencode', explode('/', $relative_path));
    return trailingslashit($baseurl) . implode('/', $parts);
}

function ll_tools_orphan_media_relative_upload_path_from_attachment(int $attachment_id): string {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $relative = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
    return ll_tools_orphan_media_normalize_relative_upload_path($relative);
}

function ll_tools_orphan_media_get_registry(): array {
    if (isset($GLOBALS['ll_tools_orphan_media_registry_cache']) && is_array($GLOBALS['ll_tools_orphan_media_registry_cache'])) {
        return $GLOBALS['ll_tools_orphan_media_registry_cache'];
    }

    $raw = get_option(LL_TOOLS_ORPHAN_MEDIA_OPTION_REGISTRY, []);
    if (!is_array($raw)) {
        $raw = [];
    }

    $registry = [
        'audio' => [],
        'image_attachments' => [],
    ];

    foreach ((array) ($raw['audio'] ?? []) as $relative_path => $entry) {
        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) $relative_path);
        if ($relative_path === '' || !is_array($entry)) {
            continue;
        }

        $registry['audio'][$relative_path] = [
            'rel_path' => $relative_path,
            'filename' => sanitize_file_name((string) ($entry['filename'] ?? basename($relative_path))),
            'first_seen_gmt' => sanitize_text_field((string) ($entry['first_seen_gmt'] ?? '')),
            'last_seen_gmt' => sanitize_text_field((string) ($entry['last_seen_gmt'] ?? '')),
            'sources' => array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($entry['sources'] ?? []))))),
            'contexts' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($entry['contexts'] ?? []))))),
        ];
    }

    foreach ((array) ($raw['image_attachments'] ?? []) as $attachment_id => $entry) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !is_array($entry)) {
            continue;
        }

        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) ($entry['rel_path'] ?? ''));
        $registry['image_attachments'][(string) $attachment_id] = [
            'attachment_id' => $attachment_id,
            'rel_path' => $relative_path,
            'filename' => sanitize_file_name((string) ($entry['filename'] ?? basename($relative_path))),
            'first_seen_gmt' => sanitize_text_field((string) ($entry['first_seen_gmt'] ?? '')),
            'last_seen_gmt' => sanitize_text_field((string) ($entry['last_seen_gmt'] ?? '')),
            'sources' => array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($entry['sources'] ?? []))))),
            'contexts' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($entry['contexts'] ?? []))))),
        ];
    }

    $GLOBALS['ll_tools_orphan_media_registry_cache'] = $registry;
    return $registry;
}

function ll_tools_orphan_media_store_registry(array $registry): void {
    $GLOBALS['ll_tools_orphan_media_registry_cache'] = $registry;
    $GLOBALS['ll_tools_orphan_media_registry_dirty'] = true;
    $GLOBALS['ll_tools_orphan_media_snapshot_stale'] = true;
}

function ll_tools_orphan_media_flush_runtime_state(): void {
    if (!empty($GLOBALS['ll_tools_orphan_media_registry_dirty']) && isset($GLOBALS['ll_tools_orphan_media_registry_cache']) && is_array($GLOBALS['ll_tools_orphan_media_registry_cache'])) {
        update_option(LL_TOOLS_ORPHAN_MEDIA_OPTION_REGISTRY, $GLOBALS['ll_tools_orphan_media_registry_cache'], false);
    }

    if (!empty($GLOBALS['ll_tools_orphan_media_snapshot_stale'])) {
        delete_option(LL_TOOLS_ORPHAN_MEDIA_OPTION_SNAPSHOT);
    }
}
add_action('shutdown', 'll_tools_orphan_media_flush_runtime_state');

function ll_tools_orphan_media_register_audio_path(string $stored_path, array $context = []): void {
    $relative_path = ll_tools_orphan_media_relative_upload_path_from_audio_meta($stored_path);
    if ($relative_path === '') {
        return;
    }

    $registry = ll_tools_orphan_media_get_registry();
    $entry = isset($registry['audio'][$relative_path]) && is_array($registry['audio'][$relative_path])
        ? $registry['audio'][$relative_path]
        : [
            'rel_path' => $relative_path,
            'filename' => sanitize_file_name(basename($relative_path)),
            'first_seen_gmt' => current_time('mysql', true),
            'last_seen_gmt' => '',
            'sources' => [],
            'contexts' => [],
        ];

    $entry['last_seen_gmt'] = current_time('mysql', true);
    if ($entry['filename'] === '') {
        $entry['filename'] = sanitize_file_name(basename($relative_path));
    }

    $source = isset($context['source']) ? sanitize_key((string) $context['source']) : '';
    if ($source !== '') {
        $entry['sources'][] = $source;
    }

    foreach ((array) ($context['contexts'] ?? []) as $context_token) {
        $context_token = sanitize_text_field((string) $context_token);
        if ($context_token !== '') {
            $entry['contexts'][] = $context_token;
        }
    }

    $entry['sources'] = array_values(array_unique(array_filter($entry['sources'])));
    $entry['contexts'] = array_values(array_unique(array_filter($entry['contexts'])));

    $registry['audio'][$relative_path] = $entry;
    ll_tools_orphan_media_store_registry($registry);
}

function ll_tools_orphan_media_register_image_attachment(int $attachment_id, array $context = []): void {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return;
    }

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return;
    }

    $mime = (string) get_post_mime_type($attachment_id);
    if ($mime !== '' && strpos($mime, 'image/') !== 0) {
        return;
    }

    $relative_path = ll_tools_orphan_media_relative_upload_path_from_attachment($attachment_id);
    $registry = ll_tools_orphan_media_get_registry();
    $key = (string) $attachment_id;
    $entry = isset($registry['image_attachments'][$key]) && is_array($registry['image_attachments'][$key])
        ? $registry['image_attachments'][$key]
        : [
            'attachment_id' => $attachment_id,
            'rel_path' => $relative_path,
            'filename' => sanitize_file_name(basename($relative_path)),
            'first_seen_gmt' => current_time('mysql', true),
            'last_seen_gmt' => '',
            'sources' => [],
            'contexts' => [],
        ];

    $entry['attachment_id'] = $attachment_id;
    $entry['rel_path'] = $relative_path;
    $entry['filename'] = sanitize_file_name((string) ($entry['filename'] ?? basename($relative_path)));
    if ($entry['filename'] === '') {
        $entry['filename'] = sanitize_file_name((string) get_the_title($attachment_id));
    }
    $entry['last_seen_gmt'] = current_time('mysql', true);

    $source = isset($context['source']) ? sanitize_key((string) $context['source']) : '';
    if ($source !== '') {
        $entry['sources'][] = $source;
    }

    foreach ((array) ($context['contexts'] ?? []) as $context_token) {
        $context_token = sanitize_text_field((string) $context_token);
        if ($context_token !== '') {
            $entry['contexts'][] = $context_token;
        }
    }

    $entry['sources'] = array_values(array_unique(array_filter($entry['sources'])));
    $entry['contexts'] = array_values(array_unique(array_filter($entry['contexts'])));

    $registry['image_attachments'][$key] = $entry;
    ll_tools_orphan_media_store_registry($registry);
}

function ll_tools_orphan_media_remove_registry_item(array $item): void {
    $registry = ll_tools_orphan_media_get_registry();
    $changed = false;

    if (($item['kind'] ?? '') === 'audio') {
        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) ($item['rel_path'] ?? ''));
        if ($relative_path !== '' && isset($registry['audio'][$relative_path])) {
            unset($registry['audio'][$relative_path]);
            $changed = true;
        }
    } elseif (($item['kind'] ?? '') === 'image') {
        $attachment_id = (int) ($item['attachment_id'] ?? 0);
        if ($attachment_id > 0 && isset($registry['image_attachments'][(string) $attachment_id])) {
            unset($registry['image_attachments'][(string) $attachment_id]);
            $changed = true;
        }
    }

    if ($changed) {
        ll_tools_orphan_media_store_registry($registry);
    }
}

function ll_tools_orphan_media_capture_audio_meta_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
    if ($meta_key !== 'audio_file_path') {
        return $check;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_audio') {
        return $check;
    }

    $context = [
        'source' => 'audio_meta_update',
        'contexts' => ['word_audio:' . (int) $object_id],
    ];

    $old_value = (string) get_post_meta((int) $object_id, 'audio_file_path', true);
    if ($old_value !== '') {
        ll_tools_orphan_media_register_audio_path($old_value, $context);
    }

    $new_value = is_scalar($meta_value) ? (string) $meta_value : '';
    if ($new_value !== '') {
        ll_tools_orphan_media_register_audio_path($new_value, $context);
    }

    return $check;
}
add_filter('update_post_metadata', 'll_tools_orphan_media_capture_audio_meta_update', 10, 5);

function ll_tools_orphan_media_capture_audio_meta_add($check, $object_id, $meta_key, $meta_value, $unique) {
    if ($meta_key !== 'audio_file_path') {
        return $check;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_audio') {
        return $check;
    }

    $new_value = is_scalar($meta_value) ? (string) $meta_value : '';
    if ($new_value !== '') {
        ll_tools_orphan_media_register_audio_path($new_value, [
            'source' => 'audio_meta_add',
            'contexts' => ['word_audio:' . (int) $object_id],
        ]);
    }

    return $check;
}
add_filter('add_post_metadata', 'll_tools_orphan_media_capture_audio_meta_add', 10, 5);

function ll_tools_orphan_media_capture_audio_meta_delete($delete, $object_ids, $meta_key, $meta_value, $delete_all) {
    if ($meta_key !== 'audio_file_path') {
        return $delete;
    }

    foreach ((array) $object_ids as $object_id) {
        $post = get_post((int) $object_id);
        if (!$post || $post->post_type !== 'word_audio') {
            continue;
        }

        $current_value = (string) get_post_meta((int) $object_id, 'audio_file_path', true);
        if ($current_value !== '') {
            ll_tools_orphan_media_register_audio_path($current_value, [
                'source' => 'audio_meta_delete',
                'contexts' => ['word_audio:' . (int) $object_id],
            ]);
        }
    }

    return $delete;
}
add_filter('delete_post_metadata', 'll_tools_orphan_media_capture_audio_meta_delete', 10, 5);

function ll_tools_orphan_media_capture_thumbnail_meta_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
    if ($meta_key !== '_thumbnail_id') {
        return $check;
    }

    $post = get_post((int) $object_id);
    if (!$post || !in_array($post->post_type, ['words', 'word_images'], true)) {
        return $check;
    }

    $context = [
        'source' => 'thumbnail_update',
        'contexts' => [$post->post_type . ':' . (int) $object_id],
    ];

    $old_attachment_id = (int) get_post_meta((int) $object_id, '_thumbnail_id', true);
    if ($old_attachment_id > 0) {
        ll_tools_orphan_media_register_image_attachment($old_attachment_id, $context);
    }

    $new_attachment_id = (int) $meta_value;
    if ($new_attachment_id > 0) {
        ll_tools_orphan_media_register_image_attachment($new_attachment_id, $context);
    }

    return $check;
}
add_filter('update_post_metadata', 'll_tools_orphan_media_capture_thumbnail_meta_update', 10, 5);

function ll_tools_orphan_media_capture_thumbnail_meta_add($check, $object_id, $meta_key, $meta_value, $unique) {
    if ($meta_key !== '_thumbnail_id') {
        return $check;
    }

    $post = get_post((int) $object_id);
    if (!$post || !in_array($post->post_type, ['words', 'word_images'], true)) {
        return $check;
    }

    $attachment_id = (int) $meta_value;
    if ($attachment_id > 0) {
        ll_tools_orphan_media_register_image_attachment($attachment_id, [
            'source' => 'thumbnail_add',
            'contexts' => [$post->post_type . ':' . (int) $object_id],
        ]);
    }

    return $check;
}
add_filter('add_post_metadata', 'll_tools_orphan_media_capture_thumbnail_meta_add', 10, 5);

function ll_tools_orphan_media_capture_thumbnail_meta_delete($delete, $object_ids, $meta_key, $meta_value, $delete_all) {
    if ($meta_key !== '_thumbnail_id') {
        return $delete;
    }

    foreach ((array) $object_ids as $object_id) {
        $post = get_post((int) $object_id);
        if (!$post || !in_array($post->post_type, ['words', 'word_images'], true)) {
            continue;
        }

        $attachment_id = (int) get_post_meta((int) $object_id, '_thumbnail_id', true);
        if ($attachment_id > 0) {
            ll_tools_orphan_media_register_image_attachment($attachment_id, [
                'source' => 'thumbnail_delete',
                'contexts' => [$post->post_type . ':' . (int) $object_id],
            ]);
        }
    }

    return $delete;
}
add_filter('delete_post_metadata', 'll_tools_orphan_media_capture_thumbnail_meta_delete', 10, 5);

function ll_tools_orphan_media_register_attachment_parent(int $attachment_id): void {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return;
    }

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return;
    }

    $parent_id = (int) $attachment->post_parent;
    if ($parent_id <= 0) {
        return;
    }

    $parent = get_post($parent_id);
    if (!$parent || !in_array($parent->post_type, ['words', 'word_images'], true)) {
        return;
    }

    ll_tools_orphan_media_register_image_attachment($attachment_id, [
        'source' => 'attachment_parent',
        'contexts' => [$parent->post_type . ':' . $parent_id],
    ]);
}
add_action('add_attachment', 'll_tools_orphan_media_register_attachment_parent');
add_action('edit_attachment', 'll_tools_orphan_media_register_attachment_parent');

function ll_tools_orphan_media_cleanup_deleted_attachment(int $attachment_id): void {
    $registry = ll_tools_orphan_media_get_registry();
    $key = (string) ((int) $attachment_id);
    if (isset($registry['image_attachments'][$key])) {
        unset($registry['image_attachments'][$key]);
        ll_tools_orphan_media_store_registry($registry);
    }
}
add_action('delete_attachment', 'll_tools_orphan_media_cleanup_deleted_attachment');

function ll_tools_orphan_media_query_rows(string $sql, array $params = []): array {
    global $wpdb;

    if (!empty($params)) {
        $prepared = $wpdb->prepare($sql, $params);
    } else {
        $prepared = $sql;
    }

    if (!is_string($prepared) || $prepared === '') {
        return [];
    }

    $rows = $wpdb->get_results($prepared, ARRAY_A);
    return is_array($rows) ? $rows : [];
}

function ll_tools_orphan_media_get_current_audio_reference_map(): array {
    global $wpdb;

    $statuses = ll_tools_orphan_media_get_valid_post_statuses();
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $sql = "
        SELECT p.ID AS post_id, pm.meta_value AS stored_path
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
            AND pm.meta_key = %s
        WHERE p.post_type = %s
          AND p.post_status IN ({$status_placeholders})
    ";

    $params = array_merge(['audio_file_path', 'word_audio'], $statuses);
    $rows = ll_tools_orphan_media_query_rows($sql, $params);
    $map = [];

    foreach ($rows as $row) {
        $relative_path = ll_tools_orphan_media_relative_upload_path_from_audio_meta((string) ($row['stored_path'] ?? ''));
        if ($relative_path === '') {
            continue;
        }

        if (!isset($map[$relative_path])) {
            $map[$relative_path] = [];
        }
        $map[$relative_path][] = 'word_audio:' . (int) ($row['post_id'] ?? 0);
    }

    foreach ($map as $relative_path => $contexts) {
        $map[$relative_path] = array_values(array_unique(array_filter($contexts)));
    }

    return $map;
}

function ll_tools_orphan_media_get_audio_attachment_reference_map(): array {
    global $wpdb;

    $sql = "
        SELECT p.ID AS attachment_id, p.post_mime_type AS mime_type, pm.meta_value AS attached_file
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
            AND pm.meta_key = %s
        WHERE p.post_type = %s
    ";

    $rows = ll_tools_orphan_media_query_rows($sql, ['_wp_attached_file', 'attachment']);
    $allowed_extensions = ll_tools_orphan_media_get_audio_extensions();
    $map = [];

    foreach ($rows as $row) {
        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) ($row['attached_file'] ?? ''));
        if ($relative_path === '') {
            continue;
        }

        $mime = strtolower(trim((string) ($row['mime_type'] ?? '')));
        $extension = strtolower((string) pathinfo($relative_path, PATHINFO_EXTENSION));
        if (strpos($mime, 'audio/') !== 0 && !in_array($extension, $allowed_extensions, true)) {
            continue;
        }

        if (!isset($map[$relative_path])) {
            $map[$relative_path] = [];
        }
        $map[$relative_path][] = 'attachment:' . (int) ($row['attachment_id'] ?? 0);
    }

    foreach ($map as $relative_path => $contexts) {
        $map[$relative_path] = array_values(array_unique(array_filter($contexts)));
    }

    return $map;
}

function ll_tools_orphan_media_get_thumbnail_reference_map(array $post_types = []): array {
    global $wpdb;

    $statuses = ll_tools_orphan_media_get_valid_post_statuses();
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $params = ['_thumbnail_id'];

    $sql = "
        SELECT p.ID AS post_id, p.post_type AS post_type, pm.meta_value AS attachment_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
            AND pm.meta_key = %s
        WHERE p.post_status IN ({$status_placeholders})
    ";
    $params = array_merge($params, $statuses);

    if (!empty($post_types)) {
        $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
        if (!empty($post_types)) {
            $post_type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
            $sql .= " AND p.post_type IN ({$post_type_placeholders})";
            $params = array_merge($params, $post_types);
        }
    }

    $rows = ll_tools_orphan_media_query_rows($sql, $params);
    $map = [];

    foreach ($rows as $row) {
        $attachment_id = (int) ($row['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            continue;
        }

        if (!isset($map[$attachment_id])) {
            $map[$attachment_id] = [];
        }
        $map[$attachment_id][] = sanitize_key((string) ($row['post_type'] ?? '')) . ':' . (int) ($row['post_id'] ?? 0);
    }

    foreach ($map as $attachment_id => $contexts) {
        $map[$attachment_id] = array_values(array_unique(array_filter($contexts)));
    }

    return $map;
}

function ll_tools_orphan_media_get_parented_ll_attachment_ids(): array {
    global $wpdb;

    $sql = "
        SELECT a.ID AS attachment_id
        FROM {$wpdb->posts} a
        INNER JOIN {$wpdb->posts} p
            ON p.ID = a.post_parent
        WHERE a.post_type = %s
          AND p.post_type IN (%s, %s)
    ";

    $rows = ll_tools_orphan_media_query_rows($sql, ['attachment', 'words', 'word_images']);
    $ids = [];
    foreach ($rows as $row) {
        $attachment_id = (int) ($row['attachment_id'] ?? 0);
        if ($attachment_id > 0) {
            $ids[] = $attachment_id;
        }
    }

    return array_values(array_unique($ids));
}

function ll_tools_orphan_media_scan_local_audio_files(): array {
    $uploads = ll_tools_orphan_media_get_uploads_info();
    $basedir = (string) ($uploads['basedir'] ?? '');
    if ($basedir === '' || !is_dir($basedir)) {
        return [];
    }

    $allowed_extensions = ll_tools_orphan_media_get_audio_extensions();
    $files = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basedir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
        );
    } catch (Throwable $e) {
        return [];
    }

    foreach ($iterator as $file_info) {
        if (!($file_info instanceof SplFileInfo) || !$file_info->isFile()) {
            continue;
        }

        $extension = strtolower((string) $file_info->getExtension());
        if ($extension === '' || !in_array($extension, $allowed_extensions, true)) {
            continue;
        }

        $absolute_path = wp_normalize_path((string) $file_info->getPathname());
        $relative_path = ll_tools_orphan_media_relative_upload_path_from_absolute($absolute_path);
        if ($relative_path === '') {
            continue;
        }

        $files[$relative_path] = [
            'path' => $absolute_path,
            'size_bytes' => max(0, (int) $file_info->getSize()),
        ];
    }

    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function ll_tools_orphan_media_backfill_registry_from_live_refs(array $current_audio_refs, array $current_ll_image_refs, array $ll_parented_attachments): void {
    foreach ($current_audio_refs as $relative_path => $contexts) {
        ll_tools_orphan_media_register_audio_path($relative_path, [
            'source' => 'live_ref_backfill',
            'contexts' => $contexts,
        ]);
    }

    foreach ($current_ll_image_refs as $attachment_id => $contexts) {
        ll_tools_orphan_media_register_image_attachment((int) $attachment_id, [
            'source' => 'live_ref_backfill',
            'contexts' => $contexts,
        ]);
    }

    foreach ($ll_parented_attachments as $attachment_id) {
        ll_tools_orphan_media_register_attachment_parent((int) $attachment_id);
    }
}

function ll_tools_orphan_media_format_context_token(string $token): string {
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    if (preg_match('/^(word_audio|words|word_images|attachment):(\d+)$/', $token, $matches)) {
        $prefix = $matches[1];
        $id = (int) $matches[2];
        $labels = [
            'word_audio' => __('Word Audio', 'll-tools-text-domain'),
            'words' => __('Word', 'll-tools-text-domain'),
            'word_images' => __('Word Image', 'll-tools-text-domain'),
            'attachment' => __('Attachment', 'll-tools-text-domain'),
        ];
        return sprintf('%1$s #%2$d', (string) ($labels[$prefix] ?? $prefix), $id);
    }

    return $token;
}

function ll_tools_orphan_media_format_context_list(array $contexts): string {
    $labels = [];
    foreach ($contexts as $context) {
        $label = ll_tools_orphan_media_format_context_token((string) $context);
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    $labels = array_values(array_unique($labels));
    if (empty($labels)) {
        return '';
    }

    return implode(', ', $labels);
}

function ll_tools_orphan_media_format_source_list(array $sources): string {
    $labels = [];
    $map = [
        'audio_meta_add' => __('recording save', 'll-tools-text-domain'),
        'audio_meta_update' => __('recording replace', 'll-tools-text-domain'),
        'audio_meta_delete' => __('recording removal', 'll-tools-text-domain'),
        'thumbnail_add' => __('featured image set', 'll-tools-text-domain'),
        'thumbnail_update' => __('featured image replace', 'll-tools-text-domain'),
        'thumbnail_delete' => __('featured image removal', 'll-tools-text-domain'),
        'attachment_parent' => __('attachment parent', 'll-tools-text-domain'),
        'live_ref_backfill' => __('current live reference', 'll-tools-text-domain'),
    ];

    foreach ($sources as $source) {
        $source = sanitize_key((string) $source);
        if ($source === '') {
            continue;
        }
        $labels[] = (string) ($map[$source] ?? str_replace('_', ' ', $source));
    }

    $labels = array_values(array_unique(array_filter($labels)));
    return implode(', ', $labels);
}

function ll_tools_orphan_media_format_datetime(string $mysql_gmt): string {
    $mysql_gmt = trim($mysql_gmt);
    if ($mysql_gmt === '') {
        return '';
    }

    $timestamp = mysql2date('U', $mysql_gmt, false);
    if (!$timestamp) {
        return '';
    }

    return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $timestamp, wp_timezone());
}

function ll_tools_orphan_media_get_item_key(array $item): string {
    $kind = (string) ($item['kind'] ?? '');
    if ($kind === 'image') {
        return 'image:' . (int) ($item['attachment_id'] ?? 0);
    }

    return 'audio:' . ll_tools_orphan_media_normalize_relative_upload_path((string) ($item['rel_path'] ?? ''));
}

function ll_tools_orphan_media_build_audio_item(string $relative_path, array $registry_entry = [], array $scan_data = []): array {
    $relative_path = ll_tools_orphan_media_normalize_relative_upload_path($relative_path);
    if ($relative_path === '') {
        return [];
    }

    $local_path = isset($scan_data['path']) ? (string) $scan_data['path'] : ll_tools_orphan_media_get_audio_local_path($relative_path);
    if ($local_path === '' || !file_exists($local_path)) {
        return [];
    }

    $size_bytes = isset($scan_data['size_bytes']) ? max(0, (int) $scan_data['size_bytes']) : max(0, (int) @filesize($local_path));
    $filename = sanitize_file_name((string) ($registry_entry['filename'] ?? basename($relative_path)));
    if ($filename === '') {
        $filename = sanitize_file_name(basename($relative_path));
    }

    $context_summary = ll_tools_orphan_media_format_context_list((array) ($registry_entry['contexts'] ?? []));
    $source_summary = ll_tools_orphan_media_format_source_list((array) ($registry_entry['sources'] ?? []));
    if ($source_summary === '') {
        $source_summary = __('No current LL recording or Media Library reference found.', 'll-tools-text-domain');
    }

    $item = [
        'kind' => 'audio',
        'rel_path' => $relative_path,
        'attachment_id' => 0,
        'filename' => $filename,
        'title' => (string) pathinfo($filename, PATHINFO_FILENAME),
        'preview_url' => ll_tools_orphan_media_build_upload_url_from_relative($relative_path),
        'size_bytes' => $size_bytes,
        'size_label' => function_exists('size_format') ? (string) size_format($size_bytes, ($size_bytes >= 1048576 ? 2 : 1)) : (string) $size_bytes,
        'first_seen_gmt' => sanitize_text_field((string) ($registry_entry['first_seen_gmt'] ?? '')),
        'last_seen_gmt' => sanitize_text_field((string) ($registry_entry['last_seen_gmt'] ?? '')),
        'context_summary' => $context_summary,
        'source_summary' => $source_summary,
        'warning' => __('Unreferenced audio upload', 'll-tools-text-domain'),
    ];
    $item['key'] = ll_tools_orphan_media_get_item_key($item);

    return $item;
}

function ll_tools_orphan_media_build_image_item(int $attachment_id, array $registry_entry = []): array {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return [];
    }

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return [];
    }

    $mime = (string) get_post_mime_type($attachment_id);
    if ($mime !== '' && strpos($mime, 'image/') !== 0) {
        return [];
    }

    $local_path = get_attached_file($attachment_id, true);
    $local_path = is_string($local_path) ? wp_normalize_path($local_path) : '';
    $has_local = ($local_path !== '' && file_exists($local_path));
    $relative_path = ll_tools_orphan_media_relative_upload_path_from_attachment($attachment_id);
    $size_bytes = $has_local ? max(0, (int) @filesize($local_path)) : 0;

    $filename = sanitize_file_name((string) ($registry_entry['filename'] ?? basename($relative_path)));
    if ($filename === '') {
        $filename = sanitize_file_name((string) pathinfo((string) wp_parse_url((string) wp_get_attachment_url($attachment_id), PHP_URL_PATH), PATHINFO_BASENAME));
    }
    if ($filename === '') {
        $filename = 'attachment-' . $attachment_id;
    }

    $preview_url = (string) wp_get_attachment_image_url($attachment_id, 'medium');
    if ($preview_url === '') {
        $preview_url = (string) wp_get_attachment_url($attachment_id);
    }

    $context_summary = ll_tools_orphan_media_format_context_list((array) ($registry_entry['contexts'] ?? []));
    $source_summary = ll_tools_orphan_media_format_source_list((array) ($registry_entry['sources'] ?? []));
    if ($source_summary === '') {
        $source_summary = __('Tracked LL image attachment is no longer used as a featured image.', 'll-tools-text-domain');
    }

    $item = [
        'kind' => 'image',
        'rel_path' => $relative_path,
        'attachment_id' => $attachment_id,
        'filename' => $filename,
        'title' => (string) get_the_title($attachment_id),
        'preview_url' => $preview_url,
        'size_bytes' => $size_bytes,
        'size_label' => $size_bytes > 0 && function_exists('size_format') ? (string) size_format($size_bytes, ($size_bytes >= 1048576 ? 2 : 1)) : __('Unknown size', 'll-tools-text-domain'),
        'first_seen_gmt' => sanitize_text_field((string) ($registry_entry['first_seen_gmt'] ?? '')),
        'last_seen_gmt' => sanitize_text_field((string) ($registry_entry['last_seen_gmt'] ?? '')),
        'context_summary' => $context_summary,
        'source_summary' => $source_summary,
        'warning' => __('Tracked orphan image attachment', 'll-tools-text-domain'),
        'edit_url' => (string) get_edit_post_link($attachment_id, ''),
    ];
    $item['key'] = ll_tools_orphan_media_get_item_key($item);

    return $item;
}

function ll_tools_orphan_media_sort_items(array &$items): void {
    usort($items, static function (array $left, array $right): int {
        $left_kind = (string) ($left['kind'] ?? '');
        $right_kind = (string) ($right['kind'] ?? '');
        if ($left_kind !== $right_kind) {
            return strcasecmp($left_kind, $right_kind);
        }

        $left_size = (int) ($left['size_bytes'] ?? 0);
        $right_size = (int) ($right['size_bytes'] ?? 0);
        if ($left_size !== $right_size) {
            return ($left_size > $right_size) ? -1 : 1;
        }

        return strcasecmp((string) ($left['filename'] ?? ''), (string) ($right['filename'] ?? ''));
    });
}

function ll_tools_orphan_media_prepare_snapshot_for_storage(array $snapshot): array {
    $items = isset($snapshot['items']) && is_array($snapshot['items'])
        ? array_values($snapshot['items'])
        : [];
    $stored_item_limit = (int) apply_filters('ll_tools_orphan_media_stored_item_limit', (int) LL_TOOLS_ORPHAN_MEDIA_STORED_ITEM_LIMIT);

    if ($stored_item_limit <= 0 || count($items) <= $stored_item_limit) {
        return $snapshot;
    }

    $stored_snapshot = $snapshot;
    $stored_snapshot['items'] = array_slice($items, 0, $stored_item_limit);
    $stored_snapshot['items_truncated'] = true;
    $stored_snapshot['stored_item_count'] = $stored_item_limit;

    if (isset($stored_snapshot['summary']) && is_array($stored_snapshot['summary'])) {
        $stored_snapshot['summary']['stored_item_count'] = $stored_item_limit;
    }

    return $stored_snapshot;
}

function ll_tools_orphan_media_get_snapshot(bool $force = false): array {
    $max_age = (int) apply_filters('ll_tools_orphan_media_snapshot_max_age', (int) LL_TOOLS_ORPHAN_MEDIA_SNAPSHOT_MAX_AGE);
    $is_stale = !empty($GLOBALS['ll_tools_orphan_media_snapshot_stale']);
    $snapshot = get_option(LL_TOOLS_ORPHAN_MEDIA_OPTION_SNAPSHOT, []);

    $has_snapshot = is_array($snapshot)
        && !empty($snapshot['generated_at_gmt'])
        && isset($snapshot['items'])
        && is_array($snapshot['items'])
        && isset($snapshot['summary'])
        && is_array($snapshot['summary']);

    if (!$force && !$is_stale && $has_snapshot) {
        $generated = mysql2date('U', (string) $snapshot['generated_at_gmt'], false);
        if ($generated && ((time() - (int) $generated) <= max(60, $max_age))) {
            return $snapshot;
        }
    }

    $current_audio_refs = ll_tools_orphan_media_get_current_audio_reference_map();
    $current_ll_image_refs = ll_tools_orphan_media_get_thumbnail_reference_map(['words', 'word_images']);
    $all_thumbnail_refs = ll_tools_orphan_media_get_thumbnail_reference_map();
    $ll_parented_attachments = ll_tools_orphan_media_get_parented_ll_attachment_ids();

    ll_tools_orphan_media_backfill_registry_from_live_refs($current_audio_refs, $current_ll_image_refs, $ll_parented_attachments);

    $registry = ll_tools_orphan_media_get_registry();
    $audio_attachment_refs = ll_tools_orphan_media_get_audio_attachment_reference_map();
    $scanned_audio_files = ll_tools_orphan_media_scan_local_audio_files();
    $audio_candidate_paths = array_values(array_unique(array_merge(
        array_keys($scanned_audio_files),
        array_keys((array) ($registry['audio'] ?? []))
    )));

    $items = [];
    $pruned_registry = $registry;
    $pruned = false;

    foreach ($audio_candidate_paths as $relative_path) {
        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) $relative_path);
        if ($relative_path === '') {
            continue;
        }

        if (isset($current_audio_refs[$relative_path]) || isset($audio_attachment_refs[$relative_path])) {
            continue;
        }

        $scan_data = $scanned_audio_files[$relative_path] ?? [];
        if (empty($scan_data)) {
            $local_path = ll_tools_orphan_media_get_audio_local_path($relative_path);
            if ($local_path !== '' && file_exists($local_path)) {
                $scan_data = [
                    'path' => $local_path,
                    'size_bytes' => max(0, (int) @filesize($local_path)),
                ];
            }
        }

        if (empty($scan_data)) {
            if (isset($pruned_registry['audio'][$relative_path])) {
                unset($pruned_registry['audio'][$relative_path]);
                $pruned = true;
            }
            continue;
        }

        $item = ll_tools_orphan_media_build_audio_item($relative_path, (array) ($registry['audio'][$relative_path] ?? []), $scan_data);
        if (!empty($item)) {
            $items[] = $item;
        }
    }

    $image_candidate_ids = array_map('intval', array_keys((array) ($registry['image_attachments'] ?? [])));
    $image_candidate_ids = array_merge($image_candidate_ids, $ll_parented_attachments);
    $image_candidate_ids = array_values(array_unique(array_filter(array_map('intval', $image_candidate_ids), static function ($attachment_id): bool {
        return $attachment_id > 0;
    })));

    foreach ($image_candidate_ids as $attachment_id) {
        if (isset($all_thumbnail_refs[$attachment_id])) {
            continue;
        }

        $item = ll_tools_orphan_media_build_image_item($attachment_id, (array) ($registry['image_attachments'][(string) $attachment_id] ?? []));
        if (!empty($item)) {
            $items[] = $item;
            continue;
        }

        if (isset($pruned_registry['image_attachments'][(string) $attachment_id])) {
            unset($pruned_registry['image_attachments'][(string) $attachment_id]);
            $pruned = true;
        }
    }

    if ($pruned) {
        ll_tools_orphan_media_store_registry($pruned_registry);
    }

    ll_tools_orphan_media_sort_items($items);

    $summary = [
        'total_count' => 0,
        'image_count' => 0,
        'audio_count' => 0,
        'total_bytes' => 0,
        'image_bytes' => 0,
        'audio_bytes' => 0,
    ];

    foreach ($items as $item) {
        $kind = (string) ($item['kind'] ?? '');
        $size = max(0, (int) ($item['size_bytes'] ?? 0));
        $summary['total_count']++;
        $summary['total_bytes'] += $size;

        if ($kind === 'image') {
            $summary['image_count']++;
            $summary['image_bytes'] += $size;
        } elseif ($kind === 'audio') {
            $summary['audio_count']++;
            $summary['audio_bytes'] += $size;
        }
    }

    $summary['total_bytes_label'] = function_exists('size_format') ? (string) size_format((int) $summary['total_bytes'], ((int) $summary['total_bytes'] >= 1048576 ? 2 : 1)) : (string) $summary['total_bytes'];
    $summary['image_bytes_label'] = function_exists('size_format') ? (string) size_format((int) $summary['image_bytes'], ((int) $summary['image_bytes'] >= 1048576 ? 2 : 1)) : (string) $summary['image_bytes'];
    $summary['audio_bytes_label'] = function_exists('size_format') ? (string) size_format((int) $summary['audio_bytes'], ((int) $summary['audio_bytes'] >= 1048576 ? 2 : 1)) : (string) $summary['audio_bytes'];

    $snapshot = [
        'generated_at_gmt' => current_time('mysql', true),
        'summary' => $summary,
        'items' => $items,
    ];

    $stored_snapshot = ll_tools_orphan_media_prepare_snapshot_for_storage($snapshot);
    update_option(LL_TOOLS_ORPHAN_MEDIA_OPTION_SNAPSHOT, $stored_snapshot, false);
    $GLOBALS['ll_tools_orphan_media_snapshot_stale'] = false;

    return $snapshot;
}

function ll_tools_orphan_media_add_maintenance_task(array $tasks): array {
    if (!ll_tools_orphan_media_current_user_can_manage()) {
        return $tasks;
    }

    $snapshot = ll_tools_orphan_media_get_snapshot(false);
    $count = (int) (($snapshot['summary']['total_count'] ?? 0));
    if ($count <= 0) {
        return $tasks;
    }

    $tasks[] = [
        'key' => 'orphan_media',
        'url' => ll_tools_orphan_media_get_admin_url(),
        'screen_id' => 'tools_page_' . ll_tools_orphan_media_get_page_slug(),
        'title' => __('Orphaned Media', 'll-tools-text-domain'),
        'message' => sprintf(
            /* translators: %d: number of orphaned media items */
            _n(
                '%d orphaned media item can be reviewed and cleaned up',
                '%d orphaned media items can be reviewed and cleaned up',
                $count,
                'll-tools-text-domain'
            ),
            $count
        ),
    ];

    return $tasks;
}
add_filter('ll_tools_admin_maintenance_tasks', 'll_tools_orphan_media_add_maintenance_task');

function ll_tools_orphan_media_register_admin_page(): void {
    add_submenu_page(
        'tools.php',
        __('LL Tools - Orphaned Media', 'll-tools-text-domain'),
        __('LL Orphaned Media', 'll-tools-text-domain'),
        ll_tools_orphan_media_get_maintenance_capability(),
        ll_tools_orphan_media_get_page_slug(),
        'll_tools_orphan_media_render_admin_page'
    );
}
add_action('admin_menu', 'll_tools_orphan_media_register_admin_page');

function ll_tools_orphan_media_get_notice(): ?array {
    $notice = isset($_GET['ll_orphan_media_notice'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_orphan_media_notice']))
        : '';
    if ($notice === '') {
        return null;
    }

    if ($notice === 'refreshed') {
        return [
            'type' => 'success',
            'message' => __('Orphaned media scan refreshed.', 'll-tools-text-domain'),
        ];
    }

    if ($notice === 'deleted') {
        $deleted = isset($_GET['ll_orphan_media_deleted']) ? max(0, (int) wp_unslash((string) $_GET['ll_orphan_media_deleted'])) : 0;
        $failed = isset($_GET['ll_orphan_media_failed']) ? max(0, (int) wp_unslash((string) $_GET['ll_orphan_media_failed'])) : 0;
        if ($failed > 0) {
            return [
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: 1: deleted count, 2: failed count */
                    __('Deleted %1$d orphaned media item(s). %2$d could not be deleted.', 'll-tools-text-domain'),
                    $deleted,
                    $failed
                ),
            ];
        }

        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: %d: deleted count */
                __('Deleted %d orphaned media item(s).', 'll-tools-text-domain'),
                $deleted
            ),
        ];
    }

    if ($notice === 'error') {
        $error = isset($_GET['ll_orphan_media_error'])
            ? sanitize_key(wp_unslash((string) $_GET['ll_orphan_media_error']))
            : '';
        $map = [
            'permission' => __('You do not have permission to manage orphaned media.', 'll-tools-text-domain'),
            'missing_selection' => __('Select at least one orphaned media item first.', 'll-tools-text-domain'),
            'download_unavailable' => __('The selected files could not be prepared for download.', 'll-tools-text-domain'),
            'zip_unavailable' => __('Bulk download requires ZipArchive on this server.', 'll-tools-text-domain'),
        ];
        return [
            'type' => 'error',
            'message' => (string) ($map[$error] ?? __('The orphaned media action could not be completed.', 'll-tools-text-domain')),
        ];
    }

    return null;
}

function ll_tools_orphan_media_filter_items(array $items, string $media_kind = 'all', string $search = ''): array {
    $media_kind = in_array($media_kind, ['all', 'image', 'audio'], true) ? $media_kind : 'all';
    $search = trim($search);
    if ($media_kind === 'all' && $search === '') {
        return $items;
    }

    $needle = strtolower($search);

    return array_values(array_filter($items, static function (array $item) use ($media_kind, $needle): bool {
        if ($media_kind !== 'all' && (string) ($item['kind'] ?? '') !== $media_kind) {
            return false;
        }

        if ($needle === '') {
            return true;
        }

        $haystacks = [
            strtolower((string) ($item['title'] ?? '')),
            strtolower((string) ($item['filename'] ?? '')),
            strtolower((string) ($item['rel_path'] ?? '')),
            strtolower((string) ($item['context_summary'] ?? '')),
        ];

        foreach ($haystacks as $text) {
            if ($text !== '' && strpos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }));
}

function ll_tools_orphan_media_paginate_items(array $items, int $page, int $per_page): array {
    $total_items = count($items);
    $total_pages = max(1, (int) ceil($total_items / max(1, $per_page)));
    $page = max(1, min($page, $total_pages));
    $offset = ($page - 1) * $per_page;

    return [
        'items' => array_slice($items, $offset, $per_page),
        'total_items' => $total_items,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
    ];
}

function ll_tools_orphan_media_render_pagination(int $page, int $total_pages, array $args = []): void {
    if ($total_pages <= 1) {
        return;
    }

    $base_url = ll_tools_orphan_media_get_admin_url($args);
    echo '<div class="tablenav-pages">';
    echo wp_kses_post(paginate_links([
        'base' => add_query_arg('paged', '%#%', $base_url),
        'format' => '',
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
        'current' => $page,
        'total' => $total_pages,
    ]));
    echo '</div>';
}

function ll_tools_orphan_media_get_current_admin_state(): array {
    $media_kind = isset($_GET['media_kind']) ? sanitize_key(wp_unslash((string) $_GET['media_kind'])) : 'all';
    if (!in_array($media_kind, ['all', 'image', 'audio'], true)) {
        $media_kind = 'all';
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $page = isset($_GET['paged']) ? max(1, (int) wp_unslash((string) $_GET['paged'])) : 1;

    return [
        'media_kind' => $media_kind,
        'search' => $search,
        'paged' => $page,
    ];
}

function ll_tools_orphan_media_resolve_item_file(array $item): array {
    $kind = (string) ($item['kind'] ?? '');
    if ($kind === 'audio') {
        $relative_path = ll_tools_orphan_media_normalize_relative_upload_path((string) ($item['rel_path'] ?? ''));
        $local_path = ll_tools_orphan_media_get_audio_local_path($relative_path);
        if ($local_path !== '' && file_exists($local_path)) {
            return [
                'path' => $local_path,
                'filename' => sanitize_file_name((string) ($item['filename'] ?? basename($local_path))),
                'mime' => (string) wp_check_filetype($local_path)['type'],
            ];
        }

        return [];
    }

    if ($kind === 'image') {
        $attachment_id = (int) ($item['attachment_id'] ?? 0);
        $local_path = get_attached_file($attachment_id, true);
        $local_path = is_string($local_path) ? wp_normalize_path($local_path) : '';
        if ($local_path !== '' && file_exists($local_path)) {
            $mime = (string) get_post_mime_type($attachment_id);
            return [
                'path' => $local_path,
                'filename' => sanitize_file_name((string) ($item['filename'] ?? basename($local_path))),
                'mime' => $mime,
            ];
        }
    }

    return [];
}

function ll_tools_orphan_media_stream_file_download(string $path, string $filename, string $mime = 'application/octet-stream'): void {
    if ($path === '' || !file_exists($path) || !is_readable($path)) {
        wp_die(esc_html__('The requested file could not be read.', 'll-tools-text-domain'));
    }

    if (!function_exists('nocache_headers')) {
        require_once ABSPATH . 'wp-includes/functions.php';
    }

    nocache_headers();
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('X-Content-Type-Options: nosniff');

    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        wp_die(esc_html__('The requested file could not be opened.', 'll-tools-text-domain'));
    }

    while (!feof($handle)) {
        echo (string) fread($handle, 8192);
    }
    fclose($handle);
    exit;
}

function ll_tools_orphan_media_prepare_zip_download(array $items) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('zip_unavailable', __('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    $temp_path = wp_tempnam('ll-tools-orphan-media.zip');
    if (!is_string($temp_path) || $temp_path === '') {
        return new WP_Error('zip_create_failed', __('Could not create a temporary ZIP file.', 'll-tools-text-domain'));
    }

    $zip = new ZipArchive();
    if ($zip->open($temp_path, ZipArchive::OVERWRITE) !== true) {
        @unlink($temp_path);
        return new WP_Error('zip_create_failed', __('Could not open a temporary ZIP file.', 'll-tools-text-domain'));
    }

    $used_names = [];
    foreach ($items as $item) {
        $resolved = ll_tools_orphan_media_resolve_item_file($item);
        if (empty($resolved)) {
            continue;
        }

        $folder = ((string) ($item['kind'] ?? '') === 'image') ? 'images' : 'audio';
        $filename = sanitize_file_name((string) ($resolved['filename'] ?? basename((string) ($resolved['path'] ?? ''))));
        if ($filename === '') {
            $filename = $folder . '-' . count($used_names) . '.bin';
        }

        $zip_name = $folder . '/' . $filename;
        $counter = 2;
        while (in_array($zip_name, $used_names, true)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $candidate = $base . '-' . $counter;
            if ($ext !== '') {
                $candidate .= '.' . $ext;
            }
            $zip_name = $folder . '/' . $candidate;
            $counter++;
        }

        if ($zip->addFile((string) $resolved['path'], $zip_name)) {
            $used_names[] = $zip_name;
        }
    }

    $zip->close();

    if (empty($used_names) || !file_exists($temp_path)) {
        @unlink($temp_path);
        return new WP_Error('zip_empty', __('No downloadable files were available for the selected items.', 'll-tools-text-domain'));
    }

    return [
        'path' => $temp_path,
        'filename' => 'll-tools-orphan-media-' . gmdate('Ymd-His') . '.zip',
        'mime' => 'application/zip',
    ];
}

function ll_tools_orphan_media_redirect_with_notice(string $notice, array $args = []): void {
    $redirect = isset($_REQUEST['redirect_to']) ? wp_validate_redirect((string) wp_unslash($_REQUEST['redirect_to']), '') : '';
    if ($redirect === '') {
        $redirect = ll_tools_orphan_media_get_admin_url();
    }

    $args = array_merge(['ll_orphan_media_notice' => $notice], $args);
    wp_safe_redirect(add_query_arg($args, $redirect));
    exit;
}

function ll_tools_orphan_media_get_snapshot_item_map(array $snapshot): array {
    $map = [];
    foreach ((array) ($snapshot['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = ll_tools_orphan_media_get_item_key($item);
        if ($key !== '') {
            $map[$key] = $item;
        }
    }

    return $map;
}

function ll_tools_orphan_media_parse_selected_keys(): array {
    $keys = [];

    if (isset($_POST['single_delete_key'])) {
        $keys[] = sanitize_text_field(wp_unslash((string) $_POST['single_delete_key']));
    } elseif (isset($_POST['single_download_key'])) {
        $keys[] = sanitize_text_field(wp_unslash((string) $_POST['single_download_key']));
    } else {
        foreach ((array) ($_POST['item_keys'] ?? []) as $key) {
            $key = sanitize_text_field(wp_unslash((string) $key));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
    }

    return array_values(array_unique(array_filter($keys)));
}

function ll_tools_orphan_media_delete_item(array $item): bool {
    if (($item['kind'] ?? '') === 'audio') {
        $resolved = ll_tools_orphan_media_resolve_item_file($item);
        if (!empty($resolved['path']) && file_exists((string) $resolved['path'])) {
            $deleted = @unlink((string) $resolved['path']);
            if (!$deleted) {
                return false;
            }
        }
        ll_tools_orphan_media_remove_registry_item($item);
        $GLOBALS['ll_tools_orphan_media_snapshot_stale'] = true;
        return true;
    }

    if (($item['kind'] ?? '') === 'image') {
        $attachment_id = (int) ($item['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            return false;
        }

        $deleted = wp_delete_attachment($attachment_id, true);
        if (!$deleted) {
            return false;
        }

        ll_tools_orphan_media_remove_registry_item($item);
        $GLOBALS['ll_tools_orphan_media_snapshot_stale'] = true;
        return true;
    }

    return false;
}

function ll_tools_orphan_media_handle_manage_action(): void {
    if (!ll_tools_orphan_media_current_user_can_manage()) {
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'permission']);
    }

    check_admin_referer(LL_TOOLS_ORPHAN_MEDIA_NONCE_ACTION, 'll_orphan_media_nonce');

    $mode = '';
    if (isset($_POST['single_delete_key']) || (isset($_POST['bulk_action']) && wp_unslash((string) $_POST['bulk_action']) === 'delete')) {
        $mode = 'delete';
    } elseif (isset($_POST['single_download_key']) || (isset($_POST['bulk_action']) && wp_unslash((string) $_POST['bulk_action']) === 'download')) {
        $mode = 'download';
    }

    if ($mode === '') {
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'missing_selection']);
    }

    $keys = ll_tools_orphan_media_parse_selected_keys();
    if (empty($keys)) {
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'missing_selection']);
    }

    $snapshot = ll_tools_orphan_media_get_snapshot(true);
    $item_map = ll_tools_orphan_media_get_snapshot_item_map($snapshot);
    $items = [];
    foreach ($keys as $key) {
        if (isset($item_map[$key])) {
            $items[] = $item_map[$key];
        }
    }

    if (empty($items)) {
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'missing_selection']);
    }

    if ($mode === 'delete') {
        $deleted = 0;
        $failed = 0;
        foreach ($items as $item) {
            if (ll_tools_orphan_media_delete_item($item)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        ll_tools_orphan_media_redirect_with_notice('deleted', [
            'll_orphan_media_deleted' => $deleted,
            'll_orphan_media_failed' => $failed,
        ]);
    }

    if (count($items) === 1) {
        $resolved = ll_tools_orphan_media_resolve_item_file($items[0]);
        if (empty($resolved)) {
            ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'download_unavailable']);
        }

        ll_tools_orphan_media_stream_file_download(
            (string) $resolved['path'],
            (string) $resolved['filename'],
            (string) ($resolved['mime'] ?? 'application/octet-stream')
        );
    }

    $zip = ll_tools_orphan_media_prepare_zip_download($items);
    if (is_wp_error($zip)) {
        $error_key = ($zip->get_error_code() === 'zip_unavailable') ? 'zip_unavailable' : 'download_unavailable';
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => $error_key]);
    }

    $zip_path = (string) ($zip['path'] ?? '');
    $zip_filename = (string) ($zip['filename'] ?? 'll-tools-orphan-media.zip');
    $zip_mime = (string) ($zip['mime'] ?? 'application/zip');

    register_shutdown_function(static function () use ($zip_path): void {
        if ($zip_path !== '' && file_exists($zip_path)) {
            @unlink($zip_path);
        }
    });

    ll_tools_orphan_media_stream_file_download($zip_path, $zip_filename, $zip_mime);
}
add_action('admin_post_ll_tools_orphan_media_manage', 'll_tools_orphan_media_handle_manage_action');

function ll_tools_orphan_media_handle_refresh_action(): void {
    if (!ll_tools_orphan_media_current_user_can_manage()) {
        ll_tools_orphan_media_redirect_with_notice('error', ['ll_orphan_media_error' => 'permission']);
    }

    check_admin_referer('ll_tools_orphan_media_refresh', 'll_orphan_media_refresh_nonce');
    ll_tools_orphan_media_get_snapshot(true);
    ll_tools_orphan_media_redirect_with_notice('refreshed');
}
add_action('admin_post_ll_tools_orphan_media_refresh', 'll_tools_orphan_media_handle_refresh_action');

function ll_tools_orphan_media_render_admin_page(): void {
    if (!ll_tools_orphan_media_current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to manage orphaned media.', 'll-tools-text-domain'));
    }

    $state = ll_tools_orphan_media_get_current_admin_state();
    $snapshot = ll_tools_orphan_media_get_snapshot(false);
    $summary = (array) ($snapshot['summary'] ?? []);
    $filtered_items = ll_tools_orphan_media_filter_items((array) ($snapshot['items'] ?? []), (string) $state['media_kind'], (string) $state['search']);
    $pagination = ll_tools_orphan_media_paginate_items($filtered_items, (int) $state['paged'], 50);
    $items = (array) ($pagination['items'] ?? []);
    $page_args = [
        'media_kind' => (string) $state['media_kind'],
        's' => (string) $state['search'],
    ];
    $notice = ll_tools_orphan_media_get_notice();
    ?>
    <div class="wrap ll-orphan-media-admin">
        <h1><?php esc_html_e('LL Tools Orphaned Media', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Review orphaned LL Tools media, preview it, download it, and remove it safely when it is no longer referenced.', 'll-tools-text-domain'); ?></p>

        <?php if (is_array($notice) && !empty($notice['message'])) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> is-dismissible">
                <p><?php echo esc_html((string) $notice['message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($snapshot['items_truncated'])) : ?>
            <div class="notice notice-warning">
                <p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: visible item count, 2: total orphaned media item count */
                        __('Showing the largest %1$d orphaned media items from a total of %2$d. Refresh the scan after cleaning these up to reveal more items.', 'll-tools-text-domain'),
                        (int) ($snapshot['stored_item_count'] ?? count((array) ($snapshot['items'] ?? []))),
                        (int) ($summary['total_count'] ?? 0)
                    ));
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="ll-orphan-media-admin__summary">
            <div class="ll-orphan-media-admin__summary-card">
                <strong><?php echo esc_html((string) ($summary['total_count'] ?? 0)); ?></strong>
                <span><?php esc_html_e('Total orphaned items', 'll-tools-text-domain'); ?></span>
            </div>
            <div class="ll-orphan-media-admin__summary-card">
                <strong><?php echo esc_html((string) ($summary['image_count'] ?? 0)); ?></strong>
                <span><?php echo esc_html(sprintf(__('Images (%s)', 'll-tools-text-domain'), (string) ($summary['image_bytes_label'] ?? '0 B'))); ?></span>
            </div>
            <div class="ll-orphan-media-admin__summary-card">
                <strong><?php echo esc_html((string) ($summary['audio_count'] ?? 0)); ?></strong>
                <span><?php echo esc_html(sprintf(__('Audio (%s)', 'll-tools-text-domain'), (string) ($summary['audio_bytes_label'] ?? '0 B'))); ?></span>
            </div>
            <div class="ll-orphan-media-admin__summary-card">
                <strong><?php echo esc_html((string) ($summary['total_bytes_label'] ?? '0 B')); ?></strong>
                <span><?php echo esc_html(sprintf(__('Last scan: %s', 'll-tools-text-domain'), ll_tools_orphan_media_format_datetime((string) ($snapshot['generated_at_gmt'] ?? '')))); ?></span>
            </div>
        </div>

        <div class="ll-orphan-media-admin__toolbar">
            <form method="get" action="">
                <input type="hidden" name="page" value="<?php echo esc_attr(ll_tools_orphan_media_get_page_slug()); ?>">
                <label for="ll-orphan-media-kind" class="screen-reader-text"><?php esc_html_e('Filter media kind', 'll-tools-text-domain'); ?></label>
                <select name="media_kind" id="ll-orphan-media-kind">
                    <option value="all" <?php selected((string) $state['media_kind'], 'all'); ?>><?php esc_html_e('All media', 'll-tools-text-domain'); ?></option>
                    <option value="image" <?php selected((string) $state['media_kind'], 'image'); ?>><?php esc_html_e('Images', 'll-tools-text-domain'); ?></option>
                    <option value="audio" <?php selected((string) $state['media_kind'], 'audio'); ?>><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
                </select>
                <label for="ll-orphan-media-search" class="screen-reader-text"><?php esc_html_e('Search orphaned media', 'll-tools-text-domain'); ?></label>
                <input type="search" id="ll-orphan-media-search" name="s" value="<?php echo esc_attr((string) $state['search']); ?>" placeholder="<?php esc_attr_e('Search filename or context', 'll-tools-text-domain'); ?>">
                <button type="submit" class="button"><?php esc_html_e('Filter', 'll-tools-text-domain'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ll_tools_orphan_media_refresh">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr(ll_tools_orphan_media_get_admin_url($page_args)); ?>">
                <?php wp_nonce_field('ll_tools_orphan_media_refresh', 'll_orphan_media_refresh_nonce'); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Refresh Scan', 'll-tools-text-domain'); ?></button>
            </form>
        </div>

        <?php if (empty($filtered_items)) : ?>
            <div class="notice notice-success inline">
                <p><?php esc_html_e('No orphaned media matched the current filters.', 'll-tools-text-domain'); ?></p>
            </div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ll-orphan-media-bulk-form">
                <input type="hidden" name="action" value="ll_tools_orphan_media_manage">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr(ll_tools_orphan_media_get_admin_url($page_args)); ?>">
                <?php wp_nonce_field(LL_TOOLS_ORPHAN_MEDIA_NONCE_ACTION, 'll_orphan_media_nonce'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <button type="submit" class="button button-secondary" name="bulk_action" value="download"><?php esc_html_e('Download Selected', 'll-tools-text-domain'); ?></button>
                        <button type="submit" class="button button-link-delete" name="bulk_action" value="delete" data-ll-orphan-delete-confirm="<?php echo esc_attr__('Delete the selected orphaned media files? This cannot be undone.', 'll-tools-text-domain'); ?>"><?php esc_html_e('Delete Selected', 'll-tools-text-domain'); ?></button>
                    </div>
                    <?php ll_tools_orphan_media_render_pagination((int) $pagination['page'], (int) $pagination['total_pages'], $page_args); ?>
                    <br class="clear">
                </div>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" id="ll-orphan-media-toggle-all"></td>
                            <th scope="col"><?php esc_html_e('Preview', 'll-tools-text-domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Media', 'll-tools-text-domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Seen Before', 'll-tools-text-domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'll-tools-text-domain'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $kind = (string) ($item['kind'] ?? '');
                        $item_key = (string) ($item['key'] ?? '');
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="item_keys[]" value="<?php echo esc_attr($item_key); ?>">
                            </th>
                            <td class="ll-orphan-media-admin__preview">
                                <?php if ($kind === 'image' && !empty($item['preview_url'])) : ?>
                                    <img src="<?php echo esc_url((string) $item['preview_url']); ?>" alt="" loading="lazy">
                                <?php elseif ($kind === 'audio' && !empty($item['preview_url'])) : ?>
                                    <audio controls preload="none" src="<?php echo esc_url((string) $item['preview_url']); ?>"></audio>
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e('No preview', 'll-tools-text-domain'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html((string) ($item['title'] !== '' ? $item['title'] : $item['filename'])); ?></strong>
                                <div><code><?php echo esc_html((string) ($item['filename'] ?? '')); ?></code></div>
                                <?php if (!empty($item['rel_path'])) : ?>
                                    <div class="description"><?php echo esc_html((string) $item['rel_path']); ?></div>
                                <?php endif; ?>
                                <div class="ll-orphan-media-admin__meta">
                                    <span class="ll-orphan-media-admin__chip"><?php echo esc_html($kind === 'image' ? __('Image', 'll-tools-text-domain') : __('Audio', 'll-tools-text-domain')); ?></span>
                                    <span class="ll-orphan-media-admin__chip"><?php echo esc_html((string) ($item['size_label'] ?? '')); ?></span>
                                </div>
                                <div class="description"><?php echo esc_html((string) ($item['warning'] ?? '')); ?></div>
                                <?php if (!empty($item['context_summary'])) : ?>
                                    <div class="description"><?php echo esc_html(sprintf(__('Last seen on: %s', 'll-tools-text-domain'), (string) $item['context_summary'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['source_summary'])) : ?>
                                    <div class="description"><?php echo esc_html((string) $item['source_summary']); ?></div>
                                <?php endif; ?>
                                <?php if ($kind === 'image' && !empty($item['edit_url'])) : ?>
                                    <div><a href="<?php echo esc_url((string) $item['edit_url']); ?>"><?php esc_html_e('Edit attachment', 'll-tools-text-domain'); ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo esc_html(ll_tools_orphan_media_format_datetime((string) ($item['first_seen_gmt'] ?? '')) ?: __('Unknown', 'll-tools-text-domain')); ?></div>
                                <div class="description"><?php echo esc_html(ll_tools_orphan_media_format_datetime((string) ($item['last_seen_gmt'] ?? '')) ?: __('Unknown', 'll-tools-text-domain')); ?></div>
                            </td>
                            <td class="ll-orphan-media-admin__actions">
                                <button type="submit" class="button button-secondary" name="single_download_key" value="<?php echo esc_attr($item_key); ?>"><?php esc_html_e('Download', 'll-tools-text-domain'); ?></button>
                                <button type="submit" class="button button-link-delete" name="single_delete_key" value="<?php echo esc_attr($item_key); ?>" data-ll-orphan-delete-confirm="<?php echo esc_attr__('Delete this orphaned media item? This cannot be undone.', 'll-tools-text-domain'); ?>"><?php esc_html_e('Delete', 'll-tools-text-domain'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="tablenav bottom">
                    <div class="alignleft actions">
                        <button type="submit" class="button button-secondary" name="bulk_action" value="download"><?php esc_html_e('Download Selected', 'll-tools-text-domain'); ?></button>
                        <button type="submit" class="button button-link-delete" name="bulk_action" value="delete" data-ll-orphan-delete-confirm="<?php echo esc_attr__('Delete the selected orphaned media files? This cannot be undone.', 'll-tools-text-domain'); ?>"><?php esc_html_e('Delete Selected', 'll-tools-text-domain'); ?></button>
                    </div>
                    <?php ll_tools_orphan_media_render_pagination((int) $pagination['page'], (int) $pagination['total_pages'], $page_args); ?>
                    <br class="clear">
                </div>
            </form>
        <?php endif; ?>
    </div>

    <style>
        .ll-orphan-media-admin__summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 18px 0;
        }
        .ll-orphan-media-admin__summary-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            padding: 14px 16px;
        }
        .ll-orphan-media-admin__summary-card strong {
            display: block;
            font-size: 22px;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        .ll-orphan-media-admin__toolbar {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            margin: 0 0 16px;
            flex-wrap: wrap;
        }
        .ll-orphan-media-admin__toolbar form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .ll-orphan-media-admin__preview img {
            display: block;
            width: 128px;
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            border: 1px solid #dcdcde;
            background: #fff;
        }
        .ll-orphan-media-admin__preview audio {
            width: 280px;
            max-width: 100%;
        }
        .ll-orphan-media-admin__meta {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin: 6px 0;
        }
        .ll-orphan-media-admin__chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #f0f6fc;
            color: #1d2327;
            padding: 3px 8px;
            font-size: 12px;
            line-height: 1.2;
        }
        .ll-orphan-media-admin__actions {
            min-width: 140px;
        }
        .ll-orphan-media-admin__actions .button {
            display: block;
            margin: 0 0 8px;
            width: fit-content;
        }
    </style>

    <script>
    (function() {
        const toggleAll = document.getElementById('ll-orphan-media-toggle-all');
        if (toggleAll) {
            toggleAll.addEventListener('change', function() {
                document.querySelectorAll('#ll-orphan-media-bulk-form input[name="item_keys[]"]').forEach(function(input) {
                    input.checked = toggleAll.checked;
                });
            });
        }

        document.querySelectorAll('#ll-orphan-media-bulk-form [data-ll-orphan-delete-confirm]').forEach(function(button) {
            button.addEventListener('click', function(event) {
                const message = button.getAttribute('data-ll-orphan-delete-confirm') || '';
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }());
    </script>
    <?php
}
