<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LL Tools — Export/Import admin tools for category bundles.
 *
 * Exports a zip (data.json + media) and imports the same bundle to recreate
 * categories and related content. Supports both:
 * - image bundle mode (categories + word image posts + featured images)
 * - full bundle mode (also words + word audio + wordsets for category scope)
 */

/**
 * Register the admin page under Tools.
 */
function ll_tools_get_export_import_capability() {
    return (string) apply_filters('ll_tools_export_import_capability', 'manage_options');
}

function ll_tools_get_export_page_slug(): string {
    return 'll-export';
}

function ll_tools_get_import_page_slug(): string {
    return 'll-import';
}

function ll_tools_get_legacy_export_import_page_slug(): string {
    return 'll-export-import';
}

function ll_tools_get_export_import_page_url(string $page_slug, array $args = []): string {
    $page_slug = sanitize_key($page_slug);
    if (function_exists('ll_tools_get_tools_page_url')) {
        return ll_tools_get_tools_page_url($page_slug, $args);
    }

    $query_args = array_merge(['page' => $page_slug], $args);
    return (string) add_query_arg($query_args, admin_url('tools.php'));
}

function ll_tools_current_user_can_export_import() {
    return current_user_can(ll_tools_get_export_import_capability());
}

function ll_tools_register_export_import_page() {
    $capability = ll_tools_get_export_import_capability();

    $export_hook = add_management_page(
        __('LL Export', 'll-tools-text-domain'),
        __('LL Export', 'll-tools-text-domain'),
        $capability,
        ll_tools_get_export_page_slug(),
        'll_tools_render_export_page'
    );
    if (is_string($export_hook) && $export_hook !== '') {
        add_action('load-' . $export_hook, 'll_tools_prime_export_admin_title');
    }

    $import_hook = add_management_page(
        __('LL Import', 'll-tools-text-domain'),
        __('LL Import', 'll-tools-text-domain'),
        $capability,
        ll_tools_get_import_page_slug(),
        'll_tools_render_import_page'
    );
    if (is_string($import_hook) && $import_hook !== '') {
        add_action('load-' . $import_hook, 'll_tools_prime_import_admin_title');
    }

    // Backward-compatible slug for existing bookmarks/links.
    $legacy_hook = add_management_page(
        __('LL Export/Import', 'll-tools-text-domain'),
        __('LL Export/Import', 'll-tools-text-domain'),
        $capability,
        ll_tools_get_legacy_export_import_page_slug(),
        'll_tools_render_legacy_export_import_page'
    );
    if (is_string($legacy_hook) && $legacy_hook !== '') {
        add_action('load-' . $legacy_hook, 'll_tools_handle_legacy_export_import_redirect');
    }
}
add_action('admin_menu', 'll_tools_register_export_import_page');

function ll_tools_prime_export_admin_title(): void {
    global $title;
    if (!is_string($title) || $title === '') {
        $title = __('LL Export', 'll-tools-text-domain');
    }
}

function ll_tools_prime_import_admin_title(): void {
    global $title;
    if (!is_string($title) || $title === '') {
        $title = __('LL Import', 'll-tools-text-domain');
    }
}

add_action('admin_post_ll_tools_export_bundle', 'll_tools_handle_export_bundle');
add_action('admin_post_ll_tools_download_bundle', 'll_tools_handle_download_bundle');
add_action('admin_post_ll_tools_preview_import_bundle', 'll_tools_handle_preview_import_bundle');
add_action('admin_post_ll_tools_import_preview_media', 'll_tools_handle_import_preview_media');
add_action('admin_post_ll_tools_import_bundle', 'll_tools_handle_import_bundle');
add_action('admin_post_ll_tools_undo_import', 'll_tools_handle_undo_import');
add_action('admin_post_ll_tools_export_wordset_csv', 'll_tools_handle_export_wordset_csv');
add_action('admin_enqueue_scripts', 'll_tools_enqueue_export_import_assets');

function ll_tools_enqueue_export_import_assets($hook) {
    $allowed_hooks = [
        'tools_page_' . ll_tools_get_export_page_slug(),
        'tools_page_' . ll_tools_get_import_page_slug(),
        'tools_page_' . ll_tools_get_legacy_export_import_page_slug(),
    ];

    if (!in_array((string) $hook, $allowed_hooks, true)) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/export-import-admin.css', 'll-tools-export-import-admin', [], false);
    ll_enqueue_asset_by_timestamp('/js/export-import-admin.js', 'll-tools-export-import-admin-js', [], true);
    wp_localize_script('ll-tools-export-import-admin-js', 'llToolsImportUi', [
        'importPageUrl' => ll_tools_get_export_import_page_url(ll_tools_get_import_page_slug()),
        'processingTitle' => __('Import in progress', 'll-tools-text-domain'),
        'processingMessageKeepOpen' => __('Keep this window open while import runs. Closing it can interrupt the request.', 'll-tools-text-domain'),
        'processingMessageBackground' => __('You can switch tabs, but do not close this tab until it finishes.', 'll-tools-text-domain'),
        'processingProgressLabel' => __('Processing import bundle...', 'll-tools-text-domain'),
        'processingDone' => __('Import finished. Loading results...', 'll-tools-text-domain'),
        'processingFailed' => __('Import request did not complete. Return to the import page and try again.', 'll-tools-text-domain'),
        'processingReload' => __('Back to import page', 'll-tools-text-domain'),
    ]);
}

function ll_tools_export_get_soft_limit_bytes(): int {
    $default = 128 * MB_IN_BYTES;
    return max(0, (int) apply_filters('ll_tools_export_soft_limit_bytes', $default));
}

function ll_tools_export_get_hard_limit_bytes(): int {
    $default = 1024 * MB_IN_BYTES;
    return max(0, (int) apply_filters('ll_tools_export_hard_limit_bytes', $default));
}

function ll_tools_export_get_hard_limit_files(): int {
    $default = 5000;
    return max(0, (int) apply_filters('ll_tools_export_hard_limit_files', $default));
}

function ll_tools_export_get_multi_full_bundle_limit_bytes(): int {
    // Keep multi-category full exports conservative for typical WP hosts/import flows.
    $default = 256 * MB_IN_BYTES;
    return max(0, (int) apply_filters('ll_tools_export_multi_full_bundle_limit_bytes', $default));
}

function ll_tools_import_get_soft_limit_files(): int {
    $default = 200;
    return max(0, (int) apply_filters('ll_tools_import_soft_limit_files', $default));
}

function ll_tools_import_get_soft_limit_bytes(): int {
    $default = 256 * MB_IN_BYTES;
    return max(0, (int) apply_filters('ll_tools_import_soft_limit_bytes', $default));
}

function ll_tools_import_preview_transient_key($token): string {
    $uid = get_current_user_id();
    $uid = $uid > 0 ? $uid : 0;
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
    return 'll_tools_import_preview_' . $uid . '_' . $token;
}

function ll_tools_import_preview_media_nonce_action(string $token, string $relative_path): string {
    return 'll_tools_import_preview_media_' . $token . '|' . $relative_path;
}

function ll_tools_build_import_preview_media_url(string $preview_token, string $relative_path): string {
    $preview_token = sanitize_text_field($preview_token);
    $relative_path = ll_tools_normalize_import_relative_path($relative_path);
    if ($preview_token === '' || $relative_path === '') {
        return '';
    }

    return add_query_arg([
        'action' => 'll_tools_import_preview_media',
        'll_import_preview' => $preview_token,
        'll_import_file' => $relative_path,
        '_wpnonce' => wp_create_nonce(ll_tools_import_preview_media_nonce_action($preview_token, $relative_path)),
    ], admin_url('admin-post.php'));
}

function ll_tools_detect_preview_media_mime_type(string $relative_path): string {
    $filetype = wp_check_filetype(basename($relative_path), null);
    $mime = isset($filetype['type']) ? (string) $filetype['type'] : '';
    if ($mime !== '') {
        return $mime;
    }

    $extension = strtolower((string) pathinfo($relative_path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
    ];

    return $map[$extension] ?? 'application/octet-stream';
}

function ll_tools_import_preview_detect_audio_type(string $path): string {
    $filename = strtolower((string) basename($path));
    if ($filename === '') {
        return 'audio';
    }

    $patterns = [
        'question' => '/(^|[_\-.])question([_\-.]|$)/',
        'isolation' => '/(^|[_\-.])isolation([_\-.]|$)/',
        'introduction' => '/(^|[_\-.])introduction([_\-.]|$)/',
        'sentence' => '/(^|[_\-.])sentence([_\-.]|$)/',
    ];

    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $filename)) {
            return $type;
        }
    }

    return 'audio';
}

function ll_tools_import_preview_audio_type_label(string $audio_type): string {
    $audio_type = sanitize_key($audio_type);
    $labels = [
        'question' => __('Question', 'll-tools-text-domain'),
        'isolation' => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
        'sentence' => __('Sentence', 'll-tools-text-domain'),
        'audio' => __('Audio', 'll-tools-text-domain'),
    ];

    return isset($labels[$audio_type]) ? (string) $labels[$audio_type] : (string) $labels['audio'];
}

function ll_tools_handle_import_preview_media(): void {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to access LL Tools import preview media.', 'll-tools-text-domain'));
    }

    $preview_token = isset($_GET['ll_import_preview'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_import_preview']))
        : '';
    $relative_path = isset($_GET['ll_import_file'])
        ? ll_tools_normalize_import_relative_path(wp_unslash((string) $_GET['ll_import_file']))
        : '';
    $nonce = isset($_GET['_wpnonce']) ? wp_unslash((string) $_GET['_wpnonce']) : '';

    if ($preview_token === '' || $relative_path === '') {
        wp_die(__('Preview media request is invalid.', 'll-tools-text-domain'));
    }

    if (!wp_verify_nonce($nonce, ll_tools_import_preview_media_nonce_action($preview_token, $relative_path))) {
        wp_die(__('Preview media request failed nonce verification.', 'll-tools-text-domain'));
    }

    $preview_data = get_transient(ll_tools_import_preview_transient_key($preview_token));
    if (!is_array($preview_data) || empty($preview_data['zip_path'])) {
        wp_die(__('Preview media request failed because the import preview expired.', 'll-tools-text-domain'));
    }

    $zip_path = (string) $preview_data['zip_path'];
    if ($zip_path === '' || !is_file($zip_path)) {
        wp_die(__('Preview media request failed because the source zip is missing.', 'll-tools-text-domain'));
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        wp_die(__('Preview media request failed because the source zip could not be opened.', 'll-tools-text-domain'));
    }

    $entry_index = $zip->locateName($relative_path);
    if ($entry_index === false) {
        $zip->close();
        wp_die(__('Preview media file was not found in the source zip.', 'll-tools-text-domain'));
    }

    $stat = $zip->statIndex((int) $entry_index);
    if (!is_array($stat) || !isset($stat['name'])) {
        $zip->close();
        wp_die(__('Preview media metadata is invalid.', 'll-tools-text-domain'));
    }

    $entry_name = ll_tools_normalize_import_relative_path((string) $stat['name']);
    if ($entry_name === '' || $entry_name !== $relative_path) {
        $zip->close();
        wp_die(__('Preview media path validation failed.', 'll-tools-text-domain'));
    }

    $stream = $zip->getStream((string) $stat['name']);
    if (!$stream) {
        $zip->close();
        wp_die(__('Preview media stream could not be opened.', 'll-tools-text-domain'));
    }

    $mime_type = ll_tools_detect_preview_media_mime_type($relative_path);
    $is_allowed_media = (
        strpos($mime_type, 'image/') === 0 ||
        strpos($mime_type, 'audio/') === 0
    );
    if (!$is_allowed_media) {
        fclose($stream);
        $zip->close();
        wp_die(__('Preview media type is not supported.', 'll-tools-text-domain'));
    }

    nocache_headers();
    header('Content-Type: ' . $mime_type);
    if (isset($stat['size']) && (int) $stat['size'] >= 0) {
        header('Content-Length: ' . (int) $stat['size']);
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($relative_path)) . '"');

    fpassthru($stream);
    fclose($stream);
    $zip->close();
    exit;
}

function ll_tools_import_history_option_name(): string {
    return 'll_tools_import_history';
}

function ll_tools_import_history_max_entries(): int {
    return max(5, (int) apply_filters('ll_tools_import_history_max_entries', 25));
}

function ll_tools_import_default_undo_payload(): array {
    return [
        'category_term_ids' => [],
        'wordset_term_ids' => [],
        'word_image_post_ids' => [],
        'word_post_ids' => [],
        'word_audio_post_ids' => [],
        'attachment_ids' => [],
        'audio_paths' => [],
    ];
}

function ll_tools_import_normalize_id_list(array $values): array {
    return array_values(array_unique(array_filter(array_map('intval', $values), static function (int $id): bool {
        return $id > 0;
    })));
}

/**
 * Normalize export category root IDs from request or internal values.
 *
 * Accepts a single ID, a list of IDs, or empty input. Returns unique positive IDs.
 *
 * @param mixed $raw_ids
 * @return array
 */
function ll_tools_export_normalize_category_root_ids($raw_ids): array {
    if (is_array($raw_ids)) {
        $values = [];
        foreach ($raw_ids as $raw_id) {
            if (!is_scalar($raw_id)) {
                continue;
            }
            $values[] = (int) wp_unslash((string) $raw_id);
        }
        return ll_tools_import_normalize_id_list($values);
    }

    if (!is_scalar($raw_ids)) {
        return [];
    }

    $single_id = (int) wp_unslash((string) $raw_ids);
    return $single_id > 0 ? [$single_id] : [];
}

function ll_tools_import_track_undo_id(array &$result, string $bucket, int $id): void {
    if ($id <= 0) {
        return;
    }

    if (!isset($result['undo']) || !is_array($result['undo'])) {
        $result['undo'] = ll_tools_import_default_undo_payload();
    }
    if (!array_key_exists($bucket, $result['undo']) || !is_array($result['undo'][$bucket])) {
        $result['undo'][$bucket] = [];
    }

    $result['undo'][$bucket][] = $id;
    $result['undo'][$bucket] = ll_tools_import_normalize_id_list($result['undo'][$bucket]);
}

function ll_tools_import_track_undo_path(array &$result, string $bucket, string $path): void {
    $path = trim($path);
    if ($path === '') {
        return;
    }

    if (!isset($result['undo']) || !is_array($result['undo'])) {
        $result['undo'] = ll_tools_import_default_undo_payload();
    }
    if (!array_key_exists($bucket, $result['undo']) || !is_array($result['undo'][$bucket])) {
        $result['undo'][$bucket] = [];
    }
    if (!in_array($path, $result['undo'][$bucket], true)) {
        $result['undo'][$bucket][] = $path;
    }
}

function ll_tools_import_has_undo_targets(array $undo): bool {
    foreach ([
        'category_term_ids',
        'wordset_term_ids',
        'word_image_post_ids',
        'word_post_ids',
        'word_audio_post_ids',
        'attachment_ids',
        'audio_paths',
    ] as $bucket) {
        if (!empty($undo[$bucket]) && is_array($undo[$bucket])) {
            return true;
        }
    }

    return false;
}

function ll_tools_import_read_history(): array {
    $raw = get_option(ll_tools_import_history_option_name(), []);
    return is_array($raw) ? array_values($raw) : [];
}

function ll_tools_import_write_history(array $entries): void {
    update_option(
        ll_tools_import_history_option_name(),
        array_slice(array_values($entries), 0, ll_tools_import_history_max_entries()),
        false
    );
}

function ll_tools_import_append_history_entry(array $entry): void {
    if (empty($entry['id'])) {
        $entry['id'] = wp_generate_uuid4();
    }

    $entries = ll_tools_import_read_history();
    array_unshift($entries, $entry);
    ll_tools_import_write_history($entries);
}

function ll_tools_import_get_recent_history_entries(): array {
    $entries = ll_tools_import_read_history();
    if (empty($entries)) {
        return [];
    }

    $timezone = wp_timezone();
    $now = new DateTimeImmutable('now', $timezone);
    $start_of_today = $now->setTime(0, 0, 0);
    $start_of_yesterday = $start_of_today->modify('-1 day')->getTimestamp();
    $recent = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $finished_at = isset($entry['finished_at']) ? (int) $entry['finished_at'] : 0;
        if ($finished_at <= 0 || $finished_at < $start_of_yesterday) {
            continue;
        }
        $recent[] = $entry;
    }

    return $recent;
}

function ll_tools_export_download_transient_key($token): string {
    $uid = get_current_user_id();
    $uid = $uid > 0 ? $uid : 0;
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
    return 'll_tools_export_download_' . $uid . '_' . $token;
}

function ll_tools_export_download_ttl_seconds(): int {
    return max(MINUTE_IN_SECONDS, (int) apply_filters('ll_tools_export_download_ttl_seconds', 2 * HOUR_IN_SECONDS));
}

function ll_tools_get_export_dir(): string {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'll-tools-exports';
}

function ll_tools_ensure_export_dir(string $export_dir): bool {
    if (!function_exists('wp_mkdir_p')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    return wp_mkdir_p($export_dir);
}

function ll_tools_cleanup_stale_export_files(string $export_dir, int $ttl_seconds): void {
    if (!is_dir($export_dir)) {
        return;
    }

    $ttl_seconds = max(MINUTE_IN_SECONDS, $ttl_seconds);
    $cutoff = time() - $ttl_seconds;

    try {
        foreach (new DirectoryIterator($export_dir) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = (string) $file->getFilename();
            if (strpos($name, 'll-tools-export-') !== 0 || strtolower($file->getExtension()) !== 'zip') {
                continue;
            }

            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            @unlink($file->getPathname());
        }
    } catch (Exception $e) {
        // Ignore cleanup errors; stale files can be removed on the next request.
    }
}

/**
 * Stream a file download, including byte-range support for resumable downloads.
 *
 * @param string $file_path
 * @param string $filename
 * @param string $content_type
 * @return void
 */
function ll_tools_stream_download_file(string $file_path, string $filename, string $content_type = 'application/octet-stream'): void {
    $file_size = @filesize($file_path);
    if ($file_size === false || $file_size < 0) {
        wp_die(__('Could not read the export file size.', 'll-tools-text-domain'));
    }
    $file_size = (int) $file_size;
    if ($file_size <= 0) {
        wp_die(__('The export file is empty.', 'll-tools-text-domain'));
    }

    $range_header = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
    $start = 0;
    $end = $file_size - 1;
    $is_partial = false;

    if ($range_header !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range_header, $matches)) {
        $range_start = $matches[1] !== '' ? (int) $matches[1] : null;
        $range_end = $matches[2] !== '' ? (int) $matches[2] : null;

        if ($range_start === null && $range_end !== null) {
            $length = min($range_end, $file_size);
            $start = max(0, $file_size - $length);
        } elseif ($range_start !== null && $range_end === null) {
            $start = $range_start;
        } elseif ($range_start !== null && $range_end !== null) {
            $start = $range_start;
            $end = $range_end;
        }

        if ($start > $end || $start >= $file_size) {
            status_header(416);
            header('Content-Range: bytes */' . $file_size);
            exit;
        }

        $end = min($end, $file_size - 1);
        $is_partial = true;
    }

    $length = $end - $start + 1;
    if ($length <= 0) {
        wp_die(__('Could not stream the export file.', 'll-tools-text-domain'));
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    nocache_headers();
    if ($is_partial) {
        status_header(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
    } else {
        status_header(200);
    }
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);

    $handle = @fopen($file_path, 'rb');
    if (!$handle) {
        wp_die(__('Could not open the export file for download.', 'll-tools-text-domain'));
    }

    if ($start > 0) {
        fseek($handle, $start);
    }

    $chunk_size = 1024 * 1024;
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $buffer = fread($handle, min($chunk_size, $remaining));
        if ($buffer === false || $buffer === '') {
            break;
        }
        echo $buffer;
        $remaining -= strlen($buffer);
        flush();
    }

    fclose($handle);
    exit;
}

function ll_tools_export_get_category_selector_rows(): array {
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $by_parent = [];
    foreach ($terms as $term) {
        if (!isset($term->term_id)) {
            continue;
        }
        $parent_id = isset($term->parent) ? (int) $term->parent : 0;
        if (!isset($by_parent[$parent_id])) {
            $by_parent[$parent_id] = [];
        }
        $by_parent[$parent_id][] = $term;
    }

    foreach ($by_parent as &$siblings) {
        usort($siblings, static function ($a, $b): int {
            $name_cmp = strcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? ''));
            if ($name_cmp !== 0) {
                return $name_cmp;
            }
            return ((int) ($a->term_id ?? 0)) <=> ((int) ($b->term_id ?? 0));
        });
    }
    unset($siblings);

    $rows = [];
    $visited = [];
    $walk = static function (int $parent_id, int $depth) use (&$walk, &$rows, &$visited, $by_parent): void {
        if (empty($by_parent[$parent_id])) {
            return;
        }

        foreach ($by_parent[$parent_id] as $term) {
            $term_id = (int) ($term->term_id ?? 0);
            if ($term_id <= 0 || isset($visited[$term_id])) {
                continue;
            }
            $visited[$term_id] = true;

            $rows[] = [
                'id'    => $term_id,
                'label' => str_repeat('-- ', max(0, $depth)) . (string) ($term->name ?? ''),
            ];

            $walk($term_id, $depth + 1);
        }
    };

    $walk(0, 0);

    // Include orphaned/cyclic terms at the end instead of dropping them.
    foreach ($terms as $term) {
        $term_id = (int) ($term->term_id ?? 0);
        if ($term_id <= 0 || isset($visited[$term_id])) {
            continue;
        }
        $rows[] = [
            'id'    => $term_id,
            'label' => (string) ($term->name ?? ''),
        ];
    }

    return $rows;
}

function ll_tools_build_export_zip_filename($include_full_bundle, $category_root_ids, $full_wordset_id = 0): string {
    $parts = ['ll-tools-export', $include_full_bundle ? 'full' : 'images'];

    $category_root_ids = ll_tools_export_normalize_category_root_ids($category_root_ids);
    if (count($category_root_ids) === 1) {
        $term = get_term((int) $category_root_ids[0], 'word-category');
        if ($term && !is_wp_error($term)) {
            $parts[] = sanitize_title($term->slug ?: $term->name);
        } else {
            $parts[] = 'category-' . (int) $category_root_ids[0];
        }
    } elseif (!empty($category_root_ids)) {
        $parts[] = count($category_root_ids) . '-categories';
        $first_term = get_term((int) $category_root_ids[0], 'word-category');
        if ($first_term && !is_wp_error($first_term)) {
            $parts[] = 'from-' . sanitize_title($first_term->slug ?: $first_term->name);
        }
    } else {
        $parts[] = 'all-categories';
    }

    if ($include_full_bundle) {
        $wordset_id = (int) $full_wordset_id;
        if ($wordset_id > 0) {
            $wordset = get_term($wordset_id, 'wordset');
            if ($wordset && !is_wp_error($wordset)) {
                $parts[] = 'wordset-' . sanitize_title($wordset->slug ?: $wordset->name);
            } else {
                $parts[] = 'wordset-' . $wordset_id;
            }
        }
    }

    $parts[] = date('Ymd-His');
    $parts = array_values(array_filter(array_map('sanitize_title', $parts), static function ($part) {
        return $part !== '';
    }));

    return implode('-', $parts) . '.zip';
}

/**
 * Renderers for dedicated Export and Import pages.
 */
function ll_tools_render_export_page() {
    ll_tools_render_export_import_page('export');
}

function ll_tools_render_import_page() {
    ll_tools_render_export_import_page('import');
}

function ll_tools_handle_legacy_export_import_redirect() {
    if (!ll_tools_current_user_can_export_import()) {
        return;
    }

    $import_preview_token = isset($_GET['ll_import_preview']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_import_preview'])) : '';
    $target_page = $import_preview_token !== '' ? ll_tools_get_import_page_slug() : ll_tools_get_export_page_slug();

    $redirect_url = ll_tools_get_export_import_page_url($target_page, $import_preview_token !== '' ? [
        'll_import_preview' => $import_preview_token,
    ] : []);
    if ($import_preview_token !== '') {
        $redirect_url .= '#ll-tools-import-preview';
    }

    wp_safe_redirect($redirect_url);
    exit;
}

function ll_tools_render_legacy_export_import_page() {
    ll_tools_render_export_import_page();
}

function ll_tools_import_history_created_summary(array $stats): string {
    $summary = [];
    $map = [
        'categories_created' => __('Categories: %d', 'll-tools-text-domain'),
        'wordsets_created' => __('Word sets: %d', 'll-tools-text-domain'),
        'word_images_created' => __('Word images: %d', 'll-tools-text-domain'),
        'words_created' => __('Words: %d', 'll-tools-text-domain'),
        'word_audio_created' => __('Audio entries: %d', 'll-tools-text-domain'),
    ];

    foreach ($map as $key => $label) {
        $value = isset($stats[$key]) ? (int) $stats[$key] : 0;
        if ($value > 0) {
            $summary[] = sprintf($label, $value);
        }
    }

    if (empty($summary)) {
        $updated_summary = [];
        $updated_map = [
            'categories_updated' => __('Categories updated: %d', 'll-tools-text-domain'),
            'wordsets_updated' => __('Word sets updated: %d', 'll-tools-text-domain'),
            'word_images_updated' => __('Word images updated: %d', 'll-tools-text-domain'),
            'words_updated' => __('Words updated: %d', 'll-tools-text-domain'),
            'word_audio_updated' => __('Audio entries updated: %d', 'll-tools-text-domain'),
        ];
        foreach ($updated_map as $key => $label) {
            $value = isset($stats[$key]) ? (int) $stats[$key] : 0;
            if ($value > 0) {
                $updated_summary[] = sprintf($label, $value);
            }
        }
        if (!empty($updated_summary)) {
            return implode(' | ', $updated_summary);
        }
        return __('No new items created.', 'll-tools-text-domain');
    }

    return implode(' | ', $summary);
}

function ll_tools_render_recent_imports_section(array $recent_imports): void {
    ?>
    <div class="ll-tools-recent-imports">
        <h2><?php esc_html_e('Recent Imports', 'll-tools-text-domain'); ?></h2>
        <p class="description"><?php esc_html_e('Shows imports completed today and yesterday. Undo removes items created by one import; existing items updated by that import are not reverted.', 'll-tools-text-domain'); ?></p>

        <?php if (empty($recent_imports)) : ?>
            <p class="description"><?php esc_html_e('No recent imports found for today or yesterday.', 'll-tools-text-domain'); ?></p>
        <?php else : ?>
            <table class="widefat striped ll-tools-recent-imports-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Source', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Result', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Created', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Action', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_imports as $entry) : ?>
                        <?php
                        $entry_id = isset($entry['id']) ? sanitize_text_field((string) $entry['id']) : '';
                        if ($entry_id === '') {
                            continue;
                        }
                        $finished_at = isset($entry['finished_at']) ? (int) $entry['finished_at'] : 0;
                        $source_zip = isset($entry['source_zip']) ? (string) $entry['source_zip'] : '';
                        $source_type = isset($entry['source_type']) ? sanitize_key((string) $entry['source_type']) : 'server';
                        $is_ok = !empty($entry['ok']);
                        $errors_count = isset($entry['errors_count']) ? (int) $entry['errors_count'] : 0;
                        $stats = isset($entry['stats']) && is_array($entry['stats']) ? $entry['stats'] : [];
                        $undo = isset($entry['undo']) && is_array($entry['undo']) ? $entry['undo'] : [];
                        $undone_at = isset($entry['undone_at']) ? (int) $entry['undone_at'] : 0;
                        $can_undo = ($undone_at <= 0 && ll_tools_import_has_undo_targets($undo));
                        $time_text = $finished_at > 0
                            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $finished_at)
                            : __('Unknown', 'll-tools-text-domain');
                        $source_label = $source_zip !== '' ? $source_zip : __('Uploaded zip', 'll-tools-text-domain');
                        if ($source_type === 'server') {
                            $source_label = sprintf(
                                /* translators: %s zip filename */
                                __('Server zip: %s', 'll-tools-text-domain'),
                                $source_label
                            );
                        }

                        $status_label = $is_ok
                            ? __('Completed', 'll-tools-text-domain')
                            : __('Completed with errors', 'll-tools-text-domain');
                        if ($undone_at > 0) {
                            $status_label = __('Undone', 'll-tools-text-domain');
                        } elseif ($errors_count > 0) {
                            $status_label = sprintf(
                                /* translators: 1 status label, 2 number of errors */
                                __('%1$s (%2$d errors)', 'll-tools-text-domain'),
                                $status_label,
                                $errors_count
                            );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($time_text); ?></td>
                            <td><?php echo esc_html($source_label); ?></td>
                            <td><?php echo esc_html($status_label); ?></td>
                            <td><?php echo esc_html(ll_tools_import_history_created_summary($stats)); ?></td>
                            <td>
                                <?php if ($can_undo) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('ll_tools_undo_import', 'll_tools_undo_import_nonce'); ?>
                                        <input type="hidden" name="action" value="ll_tools_undo_import">
                                        <input type="hidden" name="ll_import_history_id" value="<?php echo esc_attr($entry_id); ?>">
                                        <button
                                            type="submit"
                                            class="button button-secondary ll-tools-undo-import-button"
                                            onclick="return confirm('<?php echo esc_attr(__('Undo this import? This removes items created by this import and cannot be undone.', 'll-tools-text-domain')); ?>');"
                                        >
                                            <?php esc_html_e('Undo import', 'll-tools-text-domain'); ?>
                                        </button>
                                    </form>
                                <?php elseif ($undone_at > 0) : ?>
                                    <span class="ll-tools-import-undone-label"><?php esc_html_e('Already undone', 'll-tools-text-domain'); ?></span>
                                <?php else : ?>
                                    <span class="ll-tools-import-undone-label"><?php esc_html_e('No undo data', 'll-tools-text-domain'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Export/Import interface.
 *
 * @param string $mode Allowed values: both, export, import.
 */
function ll_tools_render_export_import_page(string $mode = 'both') {
    if (!ll_tools_current_user_can_export_import()) {
        return;
    }

    if (!in_array($mode, ['both', 'export', 'import'], true)) {
        $mode = 'both';
    }
    $show_export = ($mode === 'both' || $mode === 'export');
    $show_import = ($mode === 'both' || $mode === 'import');

    if ($show_import) {
        $import_result = get_transient('ll_tools_import_result');
        if ($import_result !== false) {
            delete_transient('ll_tools_import_result');
            $has_errors = !empty($import_result['errors']);
            $has_warnings = !empty($import_result['warnings']);
            $notice_class = $has_errors ? 'notice-error' : ($has_warnings ? 'notice-warning' : 'notice-success');
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>';
            echo esc_html($import_result['message']);
            if (!empty($import_result['stats'])) {
                $stats = $import_result['stats'];
                $stat_bits = [];
                foreach ([
                    'categories_created',
                    'categories_updated',
                    'wordsets_created',
                    'wordsets_updated',
                    'word_images_created',
                    'word_images_updated',
                    'words_created',
                    'words_updated',
                    'word_audio_created',
                    'word_audio_updated',
                    'attachments_imported',
                    'audio_files_imported',
                    'word_audio_deleted',
                    'words_deleted',
                    'word_images_deleted',
                    'attachments_deleted',
                    'categories_deleted',
                    'wordsets_deleted',
                    'audio_files_deleted',
                ] as $key) {
                    if (!empty($stats[$key])) {
                        $stat_bits[] = esc_html($stats[$key] . ' ' . str_replace('_', ' ', $key));
                    }
                }
                if (!empty($stat_bits)) {
                    echo '<br>' . esc_html(implode(' | ', $stat_bits));
                }
            }
            if (!empty($import_result['warnings'])) {
                echo '<br>' . esc_html__('Warnings:', 'll-tools-text-domain') . '<br>';
                foreach ((array) $import_result['warnings'] as $warning) {
                    echo esc_html('• ' . (string) $warning) . '<br>';
                }
            }
            if (!empty($import_result['errors'])) {
                echo '<br>' . esc_html__('Errors:', 'll-tools-text-domain') . '<br>';
                foreach ($import_result['errors'] as $err) {
                    echo esc_html('• ' . $err) . '<br>';
                }
            }
            echo '</p></div>';
        }
    }

    $zip_available = class_exists('ZipArchive');
    if (!$zip_available) {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('ZipArchive is not available on this server. Please enable it to use the export/import tool.', 'll-tools-text-domain');
        echo '</p></div>';
    }

    $export_action = admin_url('admin-post.php');
    $import_action = admin_url('admin-post.php');
    $import_dir = ll_tools_get_import_dir();
    $import_dir_ready = ll_tools_ensure_import_dir($import_dir);
    $import_files = $import_dir_ready ? ll_tools_list_import_zips($import_dir) : [];
    $import_dir_display = wp_normalize_path($import_dir);
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }
    $recording_types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($recording_types)) {
        $recording_types = [];
    }
    $export_category_rows = ll_tools_export_get_category_selector_rows();
    $has_export_categories = !empty($export_category_rows);
    $selected_wordset_id = isset($_GET['wordset_id']) ? (int) $_GET['wordset_id'] : 0;
    if ($selected_wordset_id <= 0 && function_exists('ll_get_default_wordset_term_id')) {
        $selected_wordset_id = (int) ll_get_default_wordset_term_id();
    }
    if ($selected_wordset_id <= 0 && !empty($wordsets)) {
        $selected_wordset_id = (int) $wordsets[0]->term_id;
    }
    $selected_wordset = $selected_wordset_id ? get_term($selected_wordset_id, 'wordset') : null;
    $default_dialect = ($selected_wordset && !is_wp_error($selected_wordset)) ? (string) $selected_wordset->name : '';
    $default_source = (string) get_bloginfo('name');
    $default_gloss_languages = ll_tools_export_get_default_gloss_languages();
    $has_wordsets = !empty($wordsets);
    $soft_export_limit_bytes = ll_tools_export_get_soft_limit_bytes();
    $hard_export_limit_bytes = ll_tools_export_get_hard_limit_bytes();
    $hard_export_limit_files = ll_tools_export_get_hard_limit_files();
    $multi_full_bundle_limit_bytes = ll_tools_export_get_multi_full_bundle_limit_bytes();
    $soft_import_limit_files = ll_tools_import_get_soft_limit_files();
    $soft_import_limit_bytes = ll_tools_import_get_soft_limit_bytes();
    $soft_limit_label = $soft_export_limit_bytes > 0 ? size_format($soft_export_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $hard_limit_label = $hard_export_limit_bytes > 0 ? size_format($hard_export_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $hard_files_label = $hard_export_limit_files > 0 ? (string) $hard_export_limit_files : __('disabled', 'll-tools-text-domain');
    $multi_full_bundle_limit_label = $multi_full_bundle_limit_bytes > 0 ? size_format($multi_full_bundle_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $import_soft_files_label = $soft_import_limit_files > 0 ? (string) $soft_import_limit_files : __('disabled', 'll-tools-text-domain');
    $import_soft_bytes_label = $soft_import_limit_bytes > 0 ? size_format($soft_import_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $import_preview_token = '';
    $import_preview = null;
    if ($show_import) {
        $import_preview_token = isset($_GET['ll_import_preview']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_import_preview'])) : '';
        if ($import_preview_token !== '') {
            $preview_key = ll_tools_import_preview_transient_key($import_preview_token);
            $preview_value = get_transient($preview_key);
            if (is_array($preview_value)) {
                $import_preview = $preview_value;
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                esc_html_e('Import preview expired. Generate a new preview and try again.', 'll-tools-text-domain');
                echo '</p></div>';
            }
        }
    }
    $recent_imports = $show_import ? ll_tools_import_get_recent_history_entries() : [];

    $page_title = __('LL Tools Export/Import', 'll-tools-text-domain');
    if ($mode === 'export') {
        $page_title = __('LL Tools Export', 'll-tools-text-domain');
    } elseif ($mode === 'import') {
        $page_title = __('LL Tools Import', 'll-tools-text-domain');
    }
    ?>
    <div class="wrap ll-tools-export-import">
        <h1><?php echo esc_html($page_title); ?></h1>

        <?php if ($show_export) : ?>
        <p><?php esc_html_e('Export category bundles as a zip. Image mode includes categories and word images; full mode also includes words, audio, and source word sets.', 'll-tools-text-domain'); ?></p>
        <p class="description"><?php esc_html_e('Tip: For large media libraries, export one category at a time or use small batches.', 'll-tools-text-domain'); ?></p>

        <h2><?php esc_html_e('Export', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo esc_url($export_action); ?>">
            <?php wp_nonce_field('ll_tools_export_bundle'); ?>
            <input type="hidden" name="action" value="ll_tools_export_bundle">

            <p><strong><?php esc_html_e('Category scope', 'll-tools-text-domain'); ?></strong></p>
            <p>
                <?php
                wp_dropdown_categories([
                    'taxonomy'         => 'word-category',
                    'hide_empty'       => false,
                    'name'             => 'll_word_category',
                    'orderby'          => 'name',
                    'order'            => 'ASC',
                    'show_option_all'  => __('All categories', 'll-tools-text-domain'),
                    'option_none_value'=> 0,
                ]);
                ?>
            </p>
            <p class="description"><?php esc_html_e('Selecting a category exports that category and its children, plus all word images assigned to them. Choose “All categories” for a full export.', 'll-tools-text-domain'); ?></p>

            <p><strong><?php esc_html_e('Bundle content', 'll-tools-text-domain'); ?></strong></p>
            <p>
                <label for="ll_export_include_full">
                    <input type="checkbox" id="ll_export_include_full" name="ll_export_include_full" value="1">
                    <?php esc_html_e('Include full category content (words, audio files, featured images, and word sets)', 'll-tools-text-domain'); ?>
                </label>
            </p>
            <p>
                <label for="ll_full_export_wordset_id"><strong><?php esc_html_e('Full export word set', 'll-tools-text-domain'); ?></strong></label><br>
                <select id="ll_full_export_wordset_id" name="ll_full_export_wordset_id" class="ll-tools-input"<?php echo $has_wordsets ? '' : ' disabled'; ?>>
                    <?php if (!$has_wordsets) : ?>
                        <option value="0"><?php esc_html_e('No word sets found', 'll-tools-text-domain'); ?></option>
                    <?php else : ?>
                        <option value="0" selected><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($wordsets as $wordset) : ?>
                            <option value="<?php echo (int) $wordset->term_id; ?>">
                                <?php echo esc_html($wordset->name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </p>
            <p class="description"><?php esc_html_e('Required when full bundle mode is enabled. Full exports are scoped to one word set.', 'll-tools-text-domain'); ?></p>
            <p>
                <label for="ll_full_export_category_ids"><strong><?php esc_html_e('Full export categories (optional multi-select)', 'll-tools-text-domain'); ?></strong></label><br>
                <select
                    id="ll_full_export_category_ids"
                    name="ll_full_export_category_ids[]"
                    class="ll-tools-input ll-tools-input--multi"
                    multiple
                    size="12"
                    data-no-categories="<?php echo $has_export_categories ? '0' : '1'; ?>"
                >
                    <?php if (!$has_export_categories) : ?>
                        <option value="0" disabled><?php esc_html_e('No categories found', 'll-tools-text-domain'); ?></option>
                    <?php else : ?>
                        <?php foreach ($export_category_rows as $category_row) : ?>
                            <?php
                            $category_row_id = isset($category_row['id']) ? (int) $category_row['id'] : 0;
                            $category_row_label = isset($category_row['label']) ? (string) $category_row['label'] : '';
                            if ($category_row_id <= 0 || $category_row_label === '') {
                                continue;
                            }
                            ?>
                            <option value="<?php echo $category_row_id; ?>"><?php echo esc_html($category_row_label); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </p>
            <p class="description"><?php esc_html_e('Only used when full bundle mode is enabled. Select one or more categories to export those categories (and their child categories). Leave empty to use the single Category scope dropdown above.', 'll-tools-text-domain'); ?></p>
            <p class="description"><?php esc_html_e('Tip: Hold Ctrl (Windows) or Command (Mac) to select multiple categories.', 'll-tools-text-domain'); ?></p>
            <p class="description">
                <?php
                echo esc_html(sprintf(
                    /* translators: %s estimated media size limit */
                    __('Multi-category full exports are limited to %s estimated media size to improve WordPress export/import reliability. Split into smaller batches if needed.', 'll-tools-text-domain'),
                    $multi_full_bundle_limit_label
                ));
                ?>
            </p>
            <p class="description"><?php esc_html_e('Leave unchecked to export only categories + word images + image files (legacy format).', 'll-tools-text-domain'); ?></p>

            <p><strong><?php esc_html_e('Large bundle safeguards', 'll-tools-text-domain'); ?></strong></p>
            <p>
                <label for="ll_allow_large_export">
                    <input type="checkbox" id="ll_allow_large_export" name="ll_allow_large_export" value="1">
                    <?php esc_html_e('Allow export when warning threshold is exceeded', 'll-tools-text-domain'); ?>
                </label>
            </p>
            <p class="description">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: soft size limit, 2: hard size limit, 3: max file count */
                    __('Warning threshold: %1$s. Hard limit: %2$s. Max media files: %3$s.', 'll-tools-text-domain'),
                    $soft_limit_label,
                    $hard_limit_label,
                    $hard_files_label
                ));
                ?>
            </p>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Download export (.zip)', 'll-tools-text-domain'); ?></button></p>
        </form>

        <h2><?php esc_html_e('Export Word Text (CSV)', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo esc_url($export_action); ?>">
            <?php wp_nonce_field('ll_tools_export_wordset_csv'); ?>
            <input type="hidden" name="action" value="ll_tools_export_wordset_csv">

            <div class="ll-tools-export-csv">
                <p class="description"><?php esc_html_e('Lexeme uses the selected text source. Choose the fields that match your target language.', 'll-tools-text-domain'); ?></p>

                <div class="ll-tools-export-field">
                    <label class="ll-tools-export-label" for="ll_export_wordset"><?php esc_html_e('Word set', 'll-tools-text-domain'); ?></label>
                    <select id="ll_export_wordset" name="ll_wordset_id" class="ll-tools-input"<?php echo $has_wordsets ? '' : ' disabled'; ?>>
                        <?php if (!$has_wordsets) : ?>
                            <option value="0"><?php esc_html_e('No word sets found', 'll-tools-text-domain'); ?></option>
                        <?php else : ?>
                            <?php foreach ($wordsets as $wordset) : ?>
                                <option value="<?php echo (int) $wordset->term_id; ?>" <?php selected($selected_wordset_id, (int) $wordset->term_id); ?>>
                                    <?php echo esc_html($wordset->name); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Exports words assigned to the selected word set.', 'll-tools-text-domain'); ?></p>
                </div>

                <div class="ll-tools-export-field">
                    <span class="ll-tools-export-label"><?php esc_html_e('Word text sources', 'll-tools-text-domain'); ?></span>
                    <div class="ll-tools-checkboxes">
                        <label><input type="checkbox" name="ll_word_text_sources[]" value="title" checked> <?php esc_html_e('Title', 'll-tools-text-domain'); ?></label>
                        <label><input type="checkbox" name="ll_word_text_sources[]" value="translation"> <?php esc_html_e('Translation', 'll-tools-text-domain'); ?></label>
                    </div>
                    <p class="description"><?php esc_html_e('Each selected source adds rows. Gloss columns use the paired translation when available.', 'll-tools-text-domain'); ?></p>
                </div>

                <div class="ll-tools-export-field">
                    <span class="ll-tools-export-label"><?php esc_html_e('Recording types', 'll-tools-text-domain'); ?></span>
                    <?php if (empty($recording_types)) : ?>
                        <p class="description"><?php esc_html_e('No recording types found.', 'll-tools-text-domain'); ?></p>
                    <?php else : ?>
                        <div class="ll-tools-recording-grid">
                            <?php foreach ($recording_types as $recording_type) : ?>
                                <div class="ll-tools-recording-type">
                                    <div class="ll-tools-recording-title"><?php echo esc_html($recording_type->name); ?></div>
                                    <div class="ll-tools-recording-options">
                                        <label><input type="checkbox" name="ll_recording_sources[<?php echo esc_attr($recording_type->slug); ?>][]" value="text"> <?php esc_html_e('Text', 'll-tools-text-domain'); ?></label>
                                        <label><input type="checkbox" name="ll_recording_sources[<?php echo esc_attr($recording_type->slug); ?>][]" value="translation"> <?php esc_html_e('Translation', 'll-tools-text-domain'); ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php esc_html_e('Select which recording types to export, and whether to include their text, translation, or both.', 'll-tools-text-domain'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="ll-tools-export-field">
                    <label class="ll-tools-export-label" for="ll_gloss_languages"><?php esc_html_e('Gloss languages', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll_gloss_languages" name="ll_gloss_languages" class="ll-tools-input" value="<?php echo esc_attr($default_gloss_languages); ?>">
                    <p class="description"><?php esc_html_e('Comma-separated language codes for Gloss columns (for example: en, tr).', 'll-tools-text-domain'); ?></p>
                </div>

                <div class="ll-tools-export-field">
                    <label class="ll-tools-export-label" for="ll_export_dialect"><?php esc_html_e('Dialect', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll_export_dialect" name="ll_export_dialect" class="ll-tools-input" value="<?php echo esc_attr($default_dialect); ?>">
                    <p class="description"><?php esc_html_e('Defaults to the word set name.', 'll-tools-text-domain'); ?></p>
                </div>

                <div class="ll-tools-export-field">
                    <label class="ll-tools-export-label" for="ll_export_source"><?php esc_html_e('Source', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll_export_source" name="ll_export_source" class="ll-tools-input" value="<?php echo esc_attr($default_source); ?>">
                    <p class="description"><?php esc_html_e('Defaults to the site name.', 'll-tools-text-domain'); ?></p>
                </div>

                <div class="ll-tools-export-field">
                    <button type="submit" class="ll-tools-action-button"<?php echo $has_wordsets ? '' : ' disabled'; ?>><?php esc_html_e('Download CSV', 'll-tools-text-domain'); ?></button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($show_import) : ?>
            <?php if ($show_export) : ?>
                <hr>
            <?php endif; ?>

        <?php ll_tools_render_recent_imports_section($recent_imports); ?>
        <p><?php esc_html_e('Import category bundles from a zip generated by LL Tools export, or from a zip with top-level CSV files plus media folders such as /images and /audio.', 'll-tools-text-domain'); ?></p>
        <p class="description"><?php esc_html_e('Preview an import first, then confirm to apply it.', 'll-tools-text-domain'); ?></p>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: media file warning threshold, 2: media size warning threshold */
                __('Large import warning thresholds: %1$s media files or %2$s of media.', 'll-tools-text-domain'),
                $import_soft_files_label,
                $import_soft_bytes_label
            ));
            ?>
        </p>
        <h2><?php esc_html_e('Import', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo esc_url($import_action); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ll_tools_preview_import_bundle'); ?>
            <input type="hidden" name="action" value="ll_tools_preview_import_bundle">

            <p><label for="ll_import_file"><strong><?php esc_html_e('Upload export zip (optional)', 'll-tools-text-domain'); ?></strong></label></p>
            <input type="file" name="ll_import_file" id="ll_import_file" accept=".zip">
            <p class="description"><?php esc_html_e('Supported zip formats: (1) LL Tools export bundles, and (2) top-level CSV files with a "quiz" column plus external media folders (/images and/or /audio). CSV mode supports image->text, text->image, text->text, and audio->text mappings.', 'll-tools-text-domain'); ?></p>

            <p><label for="ll_import_existing"><strong><?php esc_html_e('Or select a zip already on the server', 'll-tools-text-domain'); ?></strong></label></p>
            <select name="ll_import_existing" id="ll_import_existing">
                <option value=""><?php esc_html_e('Select a zip file', 'll-tools-text-domain'); ?></option>
                <?php foreach ($import_files as $import_file) : ?>
                    <option value="<?php echo esc_attr($import_file); ?>"><?php echo esc_html($import_file); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php
                if ($import_dir_ready) {
                    echo wp_kses_post(sprintf(
                        __('Upload the zip to %s and refresh this page to select it. If both fields are used, the uploaded file takes precedence.', 'll-tools-text-domain'),
                        '<code>' . esc_html($import_dir_display) . '</code>'
                    ));
                    if (empty($import_files)) {
                        echo '<br>' . esc_html__('No zip files found in the server import folder yet.', 'll-tools-text-domain');
                    }
                } else {
                    esc_html_e('Server import folder could not be created. Please check permissions before using server-side imports.', 'll-tools-text-domain');
                }
                ?>
            </p>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Preview Import', 'll-tools-text-domain'); ?></button></p>
        </form>

        <?php if (is_array($import_preview)) : ?>
            <?php
            $preview_summary = isset($import_preview['summary']) && is_array($import_preview['summary']) ? $import_preview['summary'] : [];
            $preview_wordsets = isset($import_preview['wordsets']) && is_array($import_preview['wordsets']) ? $import_preview['wordsets'] : [];
            $preview_warnings = isset($import_preview['warnings']) && is_array($import_preview['warnings']) ? array_values(array_filter(array_map('strval', $import_preview['warnings']))) : [];
            $preview_bundle_type = isset($import_preview['bundle_type']) ? (string) $import_preview['bundle_type'] : 'images';
            $preview_category_names = isset($import_preview['category_names']) && is_array($import_preview['category_names'])
                ? array_values(array_filter(array_map('strval', $import_preview['category_names']), static function (string $name): bool {
                    return trim($name) !== '';
                }))
                : [];
            $preview_sample_word = isset($import_preview['sample_word']) && is_array($import_preview['sample_word'])
                ? $import_preview['sample_word']
                : [];
            $preview_default_mode = isset($import_preview['options']['wordset_mode']) ? sanitize_key((string) $import_preview['options']['wordset_mode']) : 'create_from_export';
            if (!in_array($preview_default_mode, ['create_from_export', 'assign_existing'], true)) {
                $preview_default_mode = 'create_from_export';
            }
            $preview_target_wordset = isset($import_preview['options']['target_wordset_id']) ? (int) $import_preview['options']['target_wordset_id'] : 0;
            $preview_has_full_bundle = ($preview_bundle_type === 'category_full');
            ?>
            <hr>
            <h3 id="ll-tools-import-preview"><?php esc_html_e('Import Preview', 'll-tools-text-domain'); ?></h3>
            <div class="ll-tools-import-preview">
                <p><strong><?php esc_html_e('Bundle type:', 'll-tools-text-domain'); ?></strong> <?php echo esc_html($preview_bundle_type === 'category_full' ? __('Full category bundle', 'll-tools-text-domain') : __('Images bundle', 'll-tools-text-domain')); ?></p>
                <?php if (!empty($preview_warnings)) : ?>
                    <div class="notice notice-warning inline">
                        <p><strong><?php esc_html_e('Import warnings:', 'll-tools-text-domain'); ?></strong></p>
                        <ul>
                            <?php foreach ($preview_warnings as $preview_warning) : ?>
                                <li><?php echo esc_html($preview_warning); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <ul>
                    <li><?php echo esc_html(sprintf(__('Categories: %d', 'll-tools-text-domain'), (int) ($preview_summary['categories'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf(__('Word images: %d', 'll-tools-text-domain'), (int) ($preview_summary['word_images'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf(__('Words: %d', 'll-tools-text-domain'), (int) ($preview_summary['words'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf(__('Audio entries: %d', 'll-tools-text-domain'), (int) ($preview_summary['word_audio'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf(__('Source word sets: %d', 'll-tools-text-domain'), (int) ($preview_summary['wordsets'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf(__('Media files: %d (%s)', 'll-tools-text-domain'), (int) ($preview_summary['media_files'] ?? 0), size_format((int) ($preview_summary['media_bytes'] ?? 0)))); ?></li>
                </ul>

                <?php if (!empty($preview_category_names)) : ?>
                    <p><strong><?php esc_html_e('Categories to import:', 'll-tools-text-domain'); ?></strong></p>
                    <ul class="ll-tools-import-category-list">
                        <?php foreach ($preview_category_names as $preview_category_name) : ?>
                            <li><?php echo esc_html($preview_category_name); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($preview_sample_word)) : ?>
                    <?php
                    $preview_sample_type = isset($preview_sample_word['type']) ? sanitize_key((string) $preview_sample_word['type']) : 'word';
                    $preview_sample_title = isset($preview_sample_word['title']) ? (string) $preview_sample_word['title'] : '';
                    $preview_sample_translation = isset($preview_sample_word['translation']) ? (string) $preview_sample_word['translation'] : '';
                    $preview_sample_image = isset($preview_sample_word['image']) ? (string) $preview_sample_word['image'] : '';
                    $preview_sample_categories = isset($preview_sample_word['categories']) && is_array($preview_sample_word['categories'])
                        ? array_values(array_filter(array_map('strval', $preview_sample_word['categories']), static function (string $name): bool {
                            return trim($name) !== '';
                        }))
                        : [];
                    $preview_sample_wordsets = isset($preview_sample_word['wordsets']) && is_array($preview_sample_word['wordsets'])
                        ? array_values(array_filter(array_map('strval', $preview_sample_word['wordsets']), static function (string $slug): bool {
                            return trim($slug) !== '';
                        }))
                        : [];
                    $preview_sample_audio = isset($preview_sample_word['audio']) && is_array($preview_sample_word['audio'])
                        ? array_values(array_filter(array_map('strval', $preview_sample_word['audio']), static function (string $file): bool {
                            return trim($file) !== '';
                        }))
                        : [];
                    $preview_sample_image_url = '';
                    if ($preview_sample_image !== '') {
                        if (preg_match('#^https?://#i', $preview_sample_image)) {
                            $preview_sample_image_url = $preview_sample_image;
                        } elseif ($import_preview_token !== '') {
                            $preview_sample_image_url = ll_tools_build_import_preview_media_url($import_preview_token, $preview_sample_image);
                        }
                    }
                    $preview_sample_audio_urls = [];
                    if ($import_preview_token !== '') {
                        foreach ($preview_sample_audio as $preview_audio_file) {
                            $preview_audio_url = ll_tools_build_import_preview_media_url($import_preview_token, $preview_audio_file);
                            if ($preview_audio_url !== '') {
                                $preview_audio_type = ll_tools_import_preview_detect_audio_type($preview_audio_file);
                                $preview_sample_audio_urls[] = [
                                    'file' => $preview_audio_file,
                                    'url' => $preview_audio_url,
                                    'type' => $preview_audio_type,
                                    'label' => ll_tools_import_preview_audio_type_label($preview_audio_type),
                                ];
                            }
                        }
                    }
                    ?>
                    <p><strong><?php esc_html_e('Example imported item', 'll-tools-text-domain'); ?></strong></p>
                    <div class="word-grid ll-word-grid ll-tools-import-sample-grid">
                        <div class="word-item ll-tools-import-sample-word-item">
                            <?php if ($preview_sample_image_url !== '') : ?>
                                <div class="word-image-container">
                                    <img
                                        class="word-image"
                                        src="<?php echo esc_url($preview_sample_image_url); ?>"
                                        alt="<?php echo esc_attr($preview_sample_title !== '' ? $preview_sample_title : __('Imported preview image', 'll-tools-text-domain')); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <div class="ll-word-title-row">
                                <h3 class="word-title">
                                    <span class="ll-word-text"><?php echo esc_html($preview_sample_title); ?></span>
                                    <?php if ($preview_sample_translation !== '') : ?>
                                        <span class="ll-word-translation" dir="auto"><?php echo esc_html($preview_sample_translation); ?></span>
                                    <?php endif; ?>
                                </h3>
                            </div>

                            <div class="ll-word-meta-row">
                                <span class="ll-word-meta-tag ll-word-meta-tag--type">
                                    <?php echo esc_html($preview_sample_type === 'word_image' ? __('Word image', 'll-tools-text-domain') : __('Word', 'll-tools-text-domain')); ?>
                                </span>
                                <?php foreach ($preview_sample_categories as $preview_category_name) : ?>
                                    <span class="ll-word-meta-tag ll-word-meta-tag--category"><?php echo esc_html($preview_category_name); ?></span>
                                <?php endforeach; ?>
                                <?php foreach ($preview_sample_wordsets as $preview_wordset_name) : ?>
                                    <span class="ll-word-meta-tag ll-word-meta-tag--wordset"><?php echo esc_html($preview_wordset_name); ?></span>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!empty($preview_sample_audio_urls)) : ?>
                                <div class="ll-word-recordings ll-word-recordings--with-text">
                                    <?php foreach ($preview_sample_audio_urls as $preview_audio) : ?>
                                        <?php
                                        $preview_audio_type = isset($preview_audio['type']) ? sanitize_key((string) $preview_audio['type']) : 'audio';
                                        $preview_audio_label = isset($preview_audio['label']) ? (string) $preview_audio['label'] : __('Audio', 'll-tools-text-domain');
                                        $preview_audio_play_label = sprintf(
                                            /* translators: %s recording type label */
                                            __('Play %s recording', 'll-tools-text-domain'),
                                            $preview_audio_label
                                        );
                                        ?>
                                        <div class="ll-word-recording-row">
                                            <button
                                                type="button"
                                                class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--<?php echo esc_attr($preview_audio_type); ?>"
                                                data-ll-import-preview-audio="<?php echo esc_url((string) $preview_audio['url']); ?>"
                                                data-recording-type="<?php echo esc_attr($preview_audio_type); ?>"
                                                aria-pressed="false"
                                                aria-label="<?php echo esc_attr($preview_audio_play_label); ?>"
                                                title="<?php echo esc_attr($preview_audio_play_label); ?>"
                                            >
                                                <span class="ll-study-recording-icon" aria-hidden="true"></span>
                                                <span class="ll-study-recording-visualizer" aria-hidden="true">
                                                    <span class="bar"></span>
                                                    <span class="bar"></span>
                                                    <span class="bar"></span>
                                                    <span class="bar"></span>
                                                </span>
                                            </button>
                                            <span class="ll-word-recording-text">
                                                <span class="ll-word-recording-text-main"><?php echo esc_html($preview_audio_label); ?></span>
                                                <span class="ll-word-recording-file"><?php echo esc_html((string) $preview_audio['file']); ?></span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url($import_action); ?>">
                    <?php wp_nonce_field('ll_tools_import_bundle'); ?>
                    <input type="hidden" name="action" value="ll_tools_import_bundle">
                    <input type="hidden" name="ll_import_preview_token" value="<?php echo esc_attr($import_preview_token); ?>">

                    <?php if ($preview_has_full_bundle) : ?>
                        <p><strong><?php esc_html_e('Full bundle word set handling', 'll-tools-text-domain'); ?></strong></p>
                        <fieldset class="ll-tools-import-wordset-mode">
                            <label for="ll_import_confirm_wordset_mode_create">
                                <input type="radio" id="ll_import_confirm_wordset_mode_create" name="ll_import_wordset_mode" value="create_from_export" <?php checked($preview_default_mode, 'create_from_export'); ?>>
                                <?php esc_html_e('Create or reuse source word set(s) from the export', 'll-tools-text-domain'); ?>
                            </label>
                            <label for="ll_import_confirm_wordset_mode_assign">
                                <input type="radio" id="ll_import_confirm_wordset_mode_assign" name="ll_import_wordset_mode" value="assign_existing" <?php checked($preview_default_mode, 'assign_existing'); ?>>
                                <?php esc_html_e('Assign all imported words to one existing word set', 'll-tools-text-domain'); ?>
                            </label>
                            <select id="ll_import_confirm_target_wordset" name="ll_import_target_wordset" class="ll-tools-input" data-no-wordsets="<?php echo $has_wordsets ? '0' : '1'; ?>"<?php echo $has_wordsets ? '' : ' disabled'; ?>>
                                <?php if (!$has_wordsets) : ?>
                                    <option value="0"><?php esc_html_e('No word sets found', 'll-tools-text-domain'); ?></option>
                                <?php else : ?>
                                    <option value="0"><?php esc_html_e('Select an existing word set', 'll-tools-text-domain'); ?></option>
                                    <?php foreach ($wordsets as $wordset) : ?>
                                        <option value="<?php echo (int) $wordset->term_id; ?>" <?php selected($preview_target_wordset, (int) $wordset->term_id); ?>>
                                            <?php echo esc_html($wordset->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </fieldset>
                    <?php endif; ?>

                    <?php if ($preview_has_full_bundle && !empty($preview_wordsets)) : ?>
                        <?php
                        $preview_show_wordset_name_overrides = ($preview_default_mode !== 'assign_existing');
                        $preview_wordset_name_disabled_attr = $preview_show_wordset_name_overrides ? '' : ' disabled';
                        ?>
                        <div id="ll-tools-import-wordset-name-overrides"<?php echo $preview_show_wordset_name_overrides ? '' : ' hidden'; ?>>
                        <p><strong><?php esc_html_e('Source word set names', 'll-tools-text-domain'); ?></strong></p>
                        <p class="description"><?php esc_html_e('When using "Create or reuse", edit names here before confirming import.', 'll-tools-text-domain'); ?></p>
                        <table class="widefat striped ll-tools-import-preview-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Source slug', 'll-tools-text-domain'); ?></th>
                                    <th><?php esc_html_e('Name to create/use', 'll-tools-text-domain'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_wordsets as $preview_wordset) : ?>
                                    <?php
                                    $preview_slug = isset($preview_wordset['slug']) ? sanitize_title((string) $preview_wordset['slug']) : '';
                                    if ($preview_slug === '') {
                                        continue;
                                    }
                                    $preview_name = isset($preview_wordset['name']) ? (string) $preview_wordset['name'] : $preview_slug;
                                    ?>
                                    <tr>
                                        <td><code><?php echo esc_html($preview_slug); ?></code></td>
                                        <td>
                                            <input type="text" class="ll-tools-input" name="ll_import_wordset_names[<?php echo esc_attr($preview_slug); ?>]" value="<?php echo esc_attr($preview_name); ?>"<?php echo $preview_wordset_name_disabled_attr; ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>

                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Confirm Import', 'll-tools-text-domain'); ?></button></p>
                </form>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Handle the export action: build data and stream a zip file.
 */
function ll_tools_handle_export_bundle() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to export LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_export_bundle');

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    $category_id = isset($_POST['ll_word_category']) ? (int) $_POST['ll_word_category'] : 0;
    $include_full_bundle = !empty($_POST['ll_export_include_full']);
    $allow_large_export = !empty($_POST['ll_allow_large_export']);
    $full_wordset_id = isset($_POST['ll_full_export_wordset_id']) ? (int) wp_unslash((string) $_POST['ll_full_export_wordset_id']) : 0;
    $full_export_category_ids = isset($_POST['ll_full_export_category_ids']) && is_array($_POST['ll_full_export_category_ids'])
        ? ll_tools_export_normalize_category_root_ids($_POST['ll_full_export_category_ids'])
        : [];
    $category_root_ids = [];
    if ($include_full_bundle && !empty($full_export_category_ids)) {
        $category_root_ids = $full_export_category_ids;
    } elseif ($category_id > 0) {
        $category_root_ids = [$category_id];
    }

    if ($include_full_bundle) {
        if ($full_wordset_id <= 0) {
            wp_die(__('Select a word set when exporting a full category bundle.', 'll-tools-text-domain'));
        }
        $full_wordset = get_term($full_wordset_id, 'wordset');
        if (!$full_wordset || is_wp_error($full_wordset)) {
            wp_die(__('The selected word set for full export is invalid.', 'll-tools-text-domain'));
        }
    }

    @set_time_limit(0);
    $export = ll_tools_build_export_payload($category_root_ids, [
        'include_full_bundle' => $include_full_bundle,
        'full_wordset_id'     => $full_wordset_id,
    ]);
    if (is_wp_error($export)) {
        wp_die($export->get_error_message());
    }

    $attachment_count = isset($export['stats']['attachment_count']) ? (int) $export['stats']['attachment_count'] : count((array) ($export['attachments'] ?? []));
    $attachment_bytes = isset($export['stats']['attachment_bytes']) ? (int) $export['stats']['attachment_bytes'] : 0;
    $hard_limit_bytes = ll_tools_export_get_hard_limit_bytes();
    $hard_limit_files = ll_tools_export_get_hard_limit_files();
    $soft_limit_bytes = ll_tools_export_get_soft_limit_bytes();
    $multi_full_bundle_limit_bytes = ll_tools_export_get_multi_full_bundle_limit_bytes();

    if ($hard_limit_files > 0 && $attachment_count > $hard_limit_files) {
        wp_die(sprintf(
            /* translators: 1: count, 2: limit */
            __('Export stopped: media file count (%1$d) exceeds the hard limit (%2$d).', 'll-tools-text-domain'),
            $attachment_count,
            $hard_limit_files
        ));
    }

    if ($hard_limit_bytes > 0 && $attachment_bytes > $hard_limit_bytes) {
        wp_die(sprintf(
            /* translators: 1: estimated size, 2: hard limit */
            __('Export stopped: estimated media size (%1$s) exceeds the hard limit (%2$s).', 'll-tools-text-domain'),
            size_format($attachment_bytes),
            size_format($hard_limit_bytes)
        ));
    }

    $is_multi_scope_full_bundle = $include_full_bundle && (count($category_root_ids) > 1 || empty($category_root_ids));
    if ($is_multi_scope_full_bundle && $multi_full_bundle_limit_bytes > 0 && $attachment_bytes > $multi_full_bundle_limit_bytes) {
        wp_die(sprintf(
            /* translators: 1: estimated media size, 2: multi-category full export limit */
            __('Export stopped: multi-category full export estimated media size (%1$s) exceeds the reliability limit (%2$s). Split categories into smaller batches and try again.', 'll-tools-text-domain'),
            size_format($attachment_bytes),
            size_format($multi_full_bundle_limit_bytes)
        ));
    }

    if ($soft_limit_bytes > 0 && $attachment_bytes > $soft_limit_bytes && !$allow_large_export) {
        wp_die(sprintf(
            /* translators: 1: estimated size, 2: warning threshold */
            __('Export warning: estimated media size is %1$s (threshold %2$s). Tick \"Allow export when warning threshold is exceeded\" and run export again.', 'll-tools-text-domain'),
            size_format($attachment_bytes),
            size_format($soft_limit_bytes)
        ));
    }

    $export_dir = ll_tools_get_export_dir();
    if (!ll_tools_ensure_export_dir($export_dir)) {
        wp_die(__('Could not create export storage directory.', 'll-tools-text-domain'));
    }

    $ttl_seconds = ll_tools_export_download_ttl_seconds();
    ll_tools_cleanup_stale_export_files($export_dir, $ttl_seconds);

    $token = wp_generate_password(20, false, false);
    $zip_path = trailingslashit($export_dir) . 'll-tools-export-' . $token . '.zip';
    $zip_result = ll_tools_write_export_bundle_zip($zip_path, (array) $export['data'], (array) $export['attachments']);
    if (is_wp_error($zip_result)) {
        wp_die($zip_result->get_error_message());
    }

    $filename = ll_tools_build_export_zip_filename($include_full_bundle, $category_root_ids, $full_wordset_id);
    $download_manifest = [
        'zip_path' => $zip_path,
        'filename' => $filename,
        'created_by' => get_current_user_id(),
        'created_at' => time(),
    ];
    if (!set_transient(ll_tools_export_download_transient_key($token), $download_manifest, $ttl_seconds)) {
        @unlink($zip_path);
        wp_die(__('Could not prepare the export download link. Please try again.', 'll-tools-text-domain'));
    }

    $download_url = add_query_arg([
        'action' => 'll_tools_download_bundle',
        'll_export_token' => $token,
        '_wpnonce' => wp_create_nonce('ll_tools_download_bundle_' . $token),
    ], admin_url('admin-post.php'));

    wp_safe_redirect($download_url);
    exit;
}

/**
 * Write export payload + media attachments into a zip file.
 *
 * @param string $zip_path Absolute destination path.
 * @param array  $data_payload Export data payload for data.json.
 * @param array  $attachments Media attachment map/list.
 * @return true|WP_Error
 */
function ll_tools_write_export_bundle_zip(string $zip_path, array $data_payload, array $attachments) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('ll_tools_export_zip_missing', __('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    $zip = new ZipArchive();
    $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($open_result !== true) {
        return new WP_Error('ll_tools_export_zip_open_failed', __('Could not create export zip.', 'll-tools-text-domain'));
    }

    $data_json = wp_json_encode($data_payload);
    if (!is_string($data_json) || $data_json === '') {
        $zip->close();
        @unlink($zip_path);
        return new WP_Error('ll_tools_export_zip_data_json_failed', __('Could not encode export payload.', 'll-tools-text-domain'));
    }

    if (!$zip->addFromString('data.json', $data_json)) {
        $zip->close();
        @unlink($zip_path);
        return new WP_Error('ll_tools_export_zip_data_file_failed', __('Could not add export data to the zip file.', 'll-tools-text-domain'));
    }

    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $source_path = isset($attachment['path']) ? (string) $attachment['path'] : '';
        $target_path = isset($attachment['zip_path']) ? ltrim((string) $attachment['zip_path'], '/') : '';
        if ($source_path === '' || $target_path === '' || !is_file($source_path)) {
            continue;
        }

        if (!$zip->addFile($source_path, $target_path)) {
            $zip->close();
            @unlink($zip_path);
            return new WP_Error('ll_tools_export_zip_add_file_failed', __('Could not add media files to the export zip.', 'll-tools-text-domain'));
        }
    }

    if (!$zip->close()) {
        @unlink($zip_path);
        return new WP_Error('ll_tools_export_zip_close_failed', __('Could not finalize export zip.', 'll-tools-text-domain'));
    }

    return true;
}

/**
 * Stream a prepared export bundle zip file via GET.
 */
function ll_tools_handle_download_bundle() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to export LL Tools data.', 'll-tools-text-domain'));
    }

    $token = isset($_GET['ll_export_token']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_export_token'])) : '';
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
    if ($token === '') {
        wp_die(__('Export download link is invalid.', 'll-tools-text-domain'));
    }

    $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'll_tools_download_bundle_' . $token)) {
        wp_die(__('Export download link is invalid or expired.', 'll-tools-text-domain'));
    }

    $manifest = get_transient(ll_tools_export_download_transient_key($token));
    if (!is_array($manifest)) {
        wp_die(__('Export download has expired. Generate a new export and try again.', 'll-tools-text-domain'));
    }

    $creator_id = isset($manifest['created_by']) ? (int) $manifest['created_by'] : 0;
    if ($creator_id > 0 && $creator_id !== get_current_user_id()) {
        wp_die(__('You do not have permission to download this export file.', 'll-tools-text-domain'));
    }

    $zip_path = isset($manifest['zip_path']) ? (string) $manifest['zip_path'] : '';
    if ($zip_path === '' || !is_file($zip_path)) {
        delete_transient(ll_tools_export_download_transient_key($token));
        wp_die(__('The export file is no longer available. Generate a new export and try again.', 'll-tools-text-domain'));
    }

    $filename = isset($manifest['filename']) ? sanitize_file_name((string) $manifest['filename']) : '';
    if ($filename === '') {
        $filename = 'll-tools-export-' . gmdate('Ymd-His') . '.zip';
    }

    $ttl_seconds = ll_tools_export_download_ttl_seconds();
    $export_dir = ll_tools_get_export_dir();
    ll_tools_cleanup_stale_export_files($export_dir, $ttl_seconds);

    ll_tools_stream_download_file($zip_path, $filename, 'application/zip');
}

/**
 * Handle the CSV export action for word text.
 */
function ll_tools_handle_export_wordset_csv() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to export LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_export_wordset_csv');

    $wordset_id = isset($_POST['ll_wordset_id']) ? (int) $_POST['ll_wordset_id'] : 0;
    if ($wordset_id <= 0) {
        wp_die(__('Missing word set selection for export.', 'll-tools-text-domain'));
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
        wp_die(__('Invalid word set selection for export.', 'll-tools-text-domain'));
    }

    @set_time_limit(0);

    $word_sources = isset($_POST['ll_word_text_sources']) && is_array($_POST['ll_word_text_sources'])
        ? array_map('sanitize_text_field', wp_unslash($_POST['ll_word_text_sources']))
        : [];
    $include_title = in_array('title', $word_sources, true);
    $include_translation = in_array('translation', $word_sources, true);

    $recording_sources = ll_tools_export_parse_recording_sources($_POST['ll_recording_sources'] ?? []);
    $include_recordings = !empty($recording_sources);

    if (!$include_title && !$include_translation && !$include_recordings) {
        wp_die(__('Select at least one text source to export.', 'll-tools-text-domain'));
    }

    $gloss_languages = ll_tools_export_parse_gloss_languages(wp_unslash($_POST['ll_gloss_languages'] ?? ''));
    if (empty($gloss_languages)) {
        $gloss_languages = ll_tools_export_get_default_gloss_language_codes();
    }
    if (empty($gloss_languages)) {
        $gloss_languages = ['en'];
    }

    $dialect = sanitize_text_field(wp_unslash($_POST['ll_export_dialect'] ?? ''));
    if ($dialect === '') {
        $dialect = (string) $wordset->name;
    }

    $source = sanitize_text_field(wp_unslash($_POST['ll_export_source'] ?? ''));
    if ($source === '') {
        $source = (string) get_bloginfo('name');
    }

    $word_ids = ll_tools_export_get_wordset_word_ids($wordset_id);
    if (empty($word_ids)) {
        wp_die(__('No words found for the selected word set.', 'll-tools-text-domain'));
    }

    $audio_by_word = ll_tools_export_collect_audio_entries($word_ids);
    $gloss_headers = array_map(function ($code) {
        return 'Gloss_' . $code;
    }, $gloss_languages);
    $header = array_merge(['Lexeme', 'PhoneticForm'], $gloss_headers, ['Dialect', 'Source', 'Notes']);

    $filename = 'll-tools-wordset-text-' . sanitize_title($wordset->slug) . '-' . gmdate('Ymd-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if (!$output) {
        wp_die(__('Could not start CSV export.', 'll-tools-text-domain'));
    }

    fputcsv($output, $header);
    $gloss_count = count($gloss_headers);

    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $title = (string) get_the_title($word_id);
        $translation = ll_tools_export_get_word_translation($word_id);
        $word_ipa = ll_tools_export_pick_word_ipa($audio_by_word[$word_id] ?? []);

        if ($include_title) {
            $lexeme = trim($title);
            if ($lexeme !== '') {
                $row = ll_tools_export_build_csv_row($lexeme, $word_ipa, $translation, $gloss_count, $dialect, $source);
                fputcsv($output, $row);
            }
        }

        if ($include_translation) {
            $lexeme = trim($translation);
            if ($lexeme !== '') {
                $row = ll_tools_export_build_csv_row($lexeme, $word_ipa, $title, $gloss_count, $dialect, $source);
                fputcsv($output, $row);
            }
        }

        if ($include_recordings && !empty($audio_by_word[$word_id])) {
            foreach ($audio_by_word[$word_id] as $recording) {
                $recording_type = (string) ($recording['recording_type'] ?? '');
                if ($recording_type === '' || empty($recording_sources[$recording_type])) {
                    continue;
                }

                $recording_text = trim((string) ($recording['recording_text'] ?? ''));
                $recording_translation = trim((string) ($recording['recording_translation'] ?? ''));
                $recording_ipa = trim((string) ($recording['recording_ipa'] ?? ''));

                if (in_array('text', $recording_sources[$recording_type], true) && $recording_text !== '') {
                    $row = ll_tools_export_build_csv_row($recording_text, $recording_ipa, $recording_translation, $gloss_count, $dialect, $source);
                    fputcsv($output, $row);
                }

                if (in_array('translation', $recording_sources[$recording_type], true) && $recording_translation !== '') {
                    $row = ll_tools_export_build_csv_row($recording_translation, $recording_ipa, $recording_text, $gloss_count, $dialect, $source);
                    fputcsv($output, $row);
                }
            }
        }
    }

    fclose($output);
    exit;
}

/**
 * Resolve the selected zip source from request (uploaded file or server file).
 *
 * @param bool $allow_missing
 * @return array|WP_Error
 */
function ll_tools_resolve_import_request_zip(bool $allow_missing = false) {
    $uploaded_file = !empty($_FILES['ll_import_file']['name']);
    $existing_file = '';
    if (!empty($_POST['ll_import_existing'])) {
        $existing_file = sanitize_file_name(wp_unslash((string) $_POST['ll_import_existing']));
    }

    if (!$uploaded_file && $existing_file === '') {
        if ($allow_missing) {
            return [
                'zip_path'     => '',
                'cleanup_zip'  => false,
                'uploaded_file'=> false,
            ];
        }
        return new WP_Error('ll_tools_import_missing', __('Import failed: please choose a zip file to import.', 'll-tools-text-domain'));
    }

    if ($uploaded_file) {
        $upload = wp_handle_upload($_FILES['ll_import_file'], [
            'test_form' => false,
            'mimes'     => ['zip' => 'application/zip'],
        ]);
        if (isset($upload['error'])) {
            return new WP_Error('ll_tools_import_upload_failed', (string) $upload['error']);
        }
        return [
            'zip_path'     => (string) $upload['file'],
            'cleanup_zip'  => true,
            'uploaded_file'=> true,
        ];
    }

    $import_dir = ll_tools_get_import_dir();
    if (!ll_tools_ensure_import_dir($import_dir)) {
        return new WP_Error('ll_tools_import_dir_unavailable', __('Import failed: server import folder is not available.', 'll-tools-text-domain'));
    }
    $existing_path = ll_tools_get_existing_import_zip_path($existing_file, $import_dir);
    if (is_wp_error($existing_path)) {
        return $existing_path;
    }

    return [
        'zip_path'     => (string) $existing_path,
        'cleanup_zip'  => false,
        'uploaded_file'=> false,
    ];
}

/**
 * Resolve imported category labels for preview display.
 *
 * @param array $category_refs
 * @param array $category_names_by_slug
 * @return array
 */
function ll_tools_import_preview_resolve_category_names(array $category_refs, array $category_names_by_slug): array {
    $resolved = [];
    foreach ($category_refs as $category_ref_raw) {
        $raw = trim((string) $category_ref_raw);
        if ($raw === '') {
            continue;
        }

        $slug = sanitize_title($raw);
        $name = '';
        if ($slug !== '' && isset($category_names_by_slug[$slug])) {
            $name = (string) $category_names_by_slug[$slug];
        } else {
            $name = $raw;
        }

        if ($name === '' || isset($resolved[$name])) {
            continue;
        }
        $resolved[$name] = true;
    }

    return array_keys($resolved);
}

/**
 * Resolve imported wordset labels for preview display.
 *
 * @param array $wordset_refs
 * @param array $wordset_names_by_slug
 * @return array
 */
function ll_tools_import_preview_resolve_wordset_names(array $wordset_refs, array $wordset_names_by_slug): array {
    $resolved = [];
    foreach ($wordset_refs as $wordset_ref_raw) {
        $raw = trim((string) $wordset_ref_raw);
        if ($raw === '') {
            continue;
        }

        $slug = sanitize_title($raw);
        $name = '';
        if ($slug !== '' && isset($wordset_names_by_slug[$slug])) {
            $name = (string) $wordset_names_by_slug[$slug];
        } else {
            $name = $raw;
        }

        if ($name === '' || isset($resolved[$name])) {
            continue;
        }
        $resolved[$name] = true;
    }

    return array_keys($resolved);
}

/**
 * Build one sample imported item for preview display.
 *
 * @param array $payload
 * @param array $category_names_by_slug
 * @param array $wordset_names_by_slug
 * @return array
 */
function ll_tools_build_import_preview_sample_item(array $payload, array $category_names_by_slug, array $wordset_names_by_slug): array {
    foreach ((array) ($payload['words'] ?? []) as $word_item) {
        if (!is_array($word_item)) {
            continue;
        }

        $title = trim((string) ($word_item['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($word_item['slug'] ?? ''));
        }

        $translation = '';
        $word_meta = isset($word_item['meta']) && is_array($word_item['meta']) ? $word_item['meta'] : [];
        foreach (['word_translation', 'word_english_meaning'] as $meta_key) {
            if (!array_key_exists($meta_key, $word_meta)) {
                continue;
            }
            $meta_value = is_array($word_meta[$meta_key]) ? reset($word_meta[$meta_key]) : $word_meta[$meta_key];
            $translation = trim((string) $meta_value);
            if ($translation !== '') {
                break;
            }
        }

        $audio_files = [];
        foreach ((array) ($word_item['audio_entries'] ?? []) as $audio_item) {
            if (!is_array($audio_item)) {
                continue;
            }
            $audio_file = isset($audio_item['audio_file']['file']) ? ltrim((string) $audio_item['audio_file']['file'], '/') : '';
            if ($audio_file !== '') {
                $audio_files[$audio_file] = true;
            }
        }

        return [
            'type' => 'word',
            'title' => $title,
            'translation' => $translation,
            'categories' => ll_tools_import_preview_resolve_category_names((array) ($word_item['categories'] ?? []), $category_names_by_slug),
            'wordsets' => ll_tools_import_preview_resolve_wordset_names((array) ($word_item['wordsets'] ?? []), $wordset_names_by_slug),
            'image' => isset($word_item['featured_image']['file']) && (string) $word_item['featured_image']['file'] !== ''
                ? ltrim((string) $word_item['featured_image']['file'], '/')
                : ((isset($word_item['featured_image']['source_url']) && is_string($word_item['featured_image']['source_url']))
                    ? trim((string) $word_item['featured_image']['source_url'])
                    : ''),
            'audio' => array_keys($audio_files),
        ];
    }

    foreach ((array) ($payload['word_images'] ?? []) as $word_image_item) {
        if (!is_array($word_image_item)) {
            continue;
        }

        $title = trim((string) ($word_image_item['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($word_image_item['slug'] ?? ''));
        }

        return [
            'type' => 'word_image',
            'title' => $title,
            'translation' => '',
            'categories' => ll_tools_import_preview_resolve_category_names((array) ($word_image_item['categories'] ?? []), $category_names_by_slug),
            'wordsets' => [],
            'image' => isset($word_image_item['featured_image']['file']) && (string) $word_image_item['featured_image']['file'] !== ''
                ? ltrim((string) $word_image_item['featured_image']['file'], '/')
                : ((isset($word_image_item['featured_image']['source_url']) && is_string($word_image_item['featured_image']['source_url']))
                    ? trim((string) $word_image_item['featured_image']['source_url'])
                    : ''),
            'audio' => [],
        ];
    }

    return [];
}

/**
 * Build import preview summary data from payload.
 *
 * @param array $payload
 * @return array
 */
function ll_tools_build_import_preview_data_from_payload(array $payload): array {
    $bundle_type = isset($payload['bundle_type']) ? sanitize_key((string) $payload['bundle_type']) : 'images';
    if ($bundle_type !== 'category_full') {
        $bundle_type = !empty($payload['words']) ? 'category_full' : 'images';
    }

    $words = isset($payload['words']) && is_array($payload['words']) ? $payload['words'] : [];
    $word_audio_count = 0;
    foreach ($words as $word_item) {
        $word_audio_count += is_array($word_item['audio_entries'] ?? null) ? count($word_item['audio_entries']) : 0;
    }

    $media_estimate = isset($payload['media_estimate']) && is_array($payload['media_estimate']) ? $payload['media_estimate'] : [];
    $media_files = isset($media_estimate['attachment_count']) ? (int) $media_estimate['attachment_count'] : 0;
    $media_bytes = isset($media_estimate['attachment_bytes']) ? (int) $media_estimate['attachment_bytes'] : 0;
    $warnings = [];

    $soft_limit_files = ll_tools_import_get_soft_limit_files();
    if ($soft_limit_files > 0 && $media_files >= $soft_limit_files) {
        $warnings[] = sprintf(
            /* translators: 1: detected media files count, 2: warning threshold count */
            __('Detected %1$d media files (warning threshold: %2$d). Large imports can hit server time or memory limits.', 'll-tools-text-domain'),
            $media_files,
            $soft_limit_files
        );
    }

    $soft_limit_bytes = ll_tools_import_get_soft_limit_bytes();
    if ($soft_limit_bytes > 0 && $media_bytes >= $soft_limit_bytes) {
        $warnings[] = sprintf(
            /* translators: 1: detected media size, 2: warning threshold size */
            __('Detected %1$s of media files (warning threshold: %2$s). Large imports can hit server time or memory limits.', 'll-tools-text-domain'),
            size_format($media_bytes),
            size_format($soft_limit_bytes)
        );
    }

    $parser_summary = isset($payload['import_parser_summary']) && is_array($payload['import_parser_summary'])
        ? $payload['import_parser_summary']
        : [];
    $parser_warnings = isset($payload['import_warnings']) && is_array($payload['import_warnings'])
        ? array_values(array_filter(array_map('strval', $payload['import_warnings']), static function (string $warning): bool {
            return trim($warning) !== '';
        }))
        : [];

    $csv_files_skipped = isset($parser_summary['csv_files_skipped']) ? (int) $parser_summary['csv_files_skipped'] : 0;
    if ($csv_files_skipped > 0) {
        $warnings[] = sprintf(
            _n(
                'CSV parsing skipped %d file before import. Review the warnings below for details.',
                'CSV parsing skipped %d files before import. Review the warnings below for details.',
                $csv_files_skipped,
                'll-tools-text-domain'
            ),
            $csv_files_skipped
        );
    }

    $rows_skipped = isset($parser_summary['rows_skipped']) ? (int) $parser_summary['rows_skipped'] : 0;
    $rows_used = isset($parser_summary['rows_used']) ? (int) $parser_summary['rows_used'] : 0;
    $rows_nonempty = isset($parser_summary['rows_nonempty']) ? (int) $parser_summary['rows_nonempty'] : 0;
    if ($rows_skipped > 0) {
        if ($rows_nonempty > 0) {
            $warnings[] = sprintf(
                __('CSV parsing used %1$d of %2$d non-empty rows before import (%3$d skipped).', 'll-tools-text-domain'),
                $rows_used,
                $rows_nonempty,
                $rows_skipped
            );
        } else {
            $warnings[] = sprintf(
                _n(
                    'CSV parsing skipped %d row before import.',
                    'CSV parsing skipped %d rows before import.',
                    $rows_skipped,
                    'll-tools-text-domain'
                ),
                $rows_skipped
            );
        }
    }

    if (!empty($parser_warnings)) {
        $warnings = array_merge($warnings, $parser_warnings);
    }
    $warnings = array_values(array_unique($warnings));

    $preview_wordsets = [];
    $wordset_names_by_slug = [];
    foreach ((array) ($payload['wordsets'] ?? []) as $wordset) {
        $slug = isset($wordset['slug']) ? sanitize_title((string) $wordset['slug']) : '';
        if ($slug === '') {
            continue;
        }
        $name = isset($wordset['name']) ? (string) $wordset['name'] : $slug;
        if (!isset($wordset_names_by_slug[$slug])) {
            $wordset_names_by_slug[$slug] = $name;
        }
        $preview_wordsets[] = [
            'slug' => $slug,
            'name' => $name,
        ];
    }

    $category_names = [];
    $category_names_by_slug = [];
    foreach ((array) ($payload['categories'] ?? []) as $category) {
        if (!is_array($category)) {
            continue;
        }
        $slug = isset($category['slug']) ? sanitize_title((string) $category['slug']) : '';
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '' && $slug !== '') {
            $name = $slug;
        }
        if ($name === '') {
            continue;
        }
        if ($slug !== '') {
            if (isset($category_names_by_slug[$slug])) {
                continue;
            }
            $category_names_by_slug[$slug] = $name;
        }
        $category_names[] = $name;
    }

    $sample_word = ll_tools_build_import_preview_sample_item($payload, $category_names_by_slug, $wordset_names_by_slug);

    return [
        'bundle_type' => $bundle_type,
        'summary' => [
            'categories'  => count((array) ($payload['categories'] ?? [])),
            'word_images' => count((array) ($payload['word_images'] ?? [])),
            'words'       => count($words),
            'word_audio'  => $word_audio_count,
            'wordsets'    => count($preview_wordsets),
            'media_files' => $media_files,
            'media_bytes' => $media_bytes,
        ],
        'category_names' => $category_names,
        'sample_word' => $sample_word,
        'wordsets' => $preview_wordsets,
        'warnings' => $warnings,
    ];
}

/**
 * Normalize term meta structure for wordset identity comparison.
 *
 * @param array $meta
 * @return array
 */
function ll_tools_import_normalize_meta_for_compare(array $meta): array {
    $normalized = [];
    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if ($key === '') {
            continue;
        }

        $bucket = [];
        foreach ((array) $values as $value) {
            $bucket[] = maybe_serialize(maybe_unserialize($value));
        }
        sort($bucket, SORT_STRING);
        $normalized[$key] = $bucket;
    }
    ksort($normalized, SORT_STRING);

    return $normalized;
}

/**
 * Normalize user-facing term text for wordset comparison.
 *
 * @param string $value
 * @return string
 */
function ll_tools_import_normalize_term_text_for_compare(string $value): string {
    $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

/**
 * Determine whether every normalized source meta key/value exists on destination meta.
 *
 * @param array $source_meta
 * @param array $destination_meta
 * @return bool
 */
function ll_tools_import_meta_is_subset(array $source_meta, array $destination_meta): bool {
    foreach ($source_meta as $key => $values) {
        if (!array_key_exists($key, $destination_meta)) {
            return false;
        }
        if ((array) $values !== (array) $destination_meta[$key]) {
            return false;
        }
    }

    return true;
}

/**
 * Check whether an exported wordset payload is identical (or functionally equivalent)
 * to an existing local wordset.
 *
 * @param array $source_wordset
 * @param int $existing_term_id
 * @return bool
 */
function ll_tools_import_wordset_payload_is_identical(array $source_wordset, int $existing_term_id): bool {
    $existing_term_id = (int) $existing_term_id;
    if ($existing_term_id <= 0) {
        return false;
    }

    $term = get_term($existing_term_id, 'wordset');
    if (!$term || is_wp_error($term)) {
        return false;
    }

    $source_name = ll_tools_import_normalize_term_text_for_compare((string) ($source_wordset['name'] ?? ''));
    $source_description = ll_tools_import_normalize_term_text_for_compare((string) ($source_wordset['description'] ?? ''));
    $existing_name = ll_tools_import_normalize_term_text_for_compare((string) $term->name);
    $existing_description = ll_tools_import_normalize_term_text_for_compare((string) $term->description);

    if ($source_name !== $existing_name) {
        return false;
    }
    if ($source_description !== $existing_description) {
        return false;
    }

    $source_meta = isset($source_wordset['meta']) && is_array($source_wordset['meta']) ? $source_wordset['meta'] : [];
    if (isset($source_meta['manager_user_id'])) {
        unset($source_meta['manager_user_id']);
    }
    $existing_meta = ll_tools_prepare_meta_for_export(get_term_meta($existing_term_id), ['manager_user_id']);
    $normalized_source_meta = ll_tools_import_normalize_meta_for_compare($source_meta);
    $normalized_existing_meta = ll_tools_import_normalize_meta_for_compare($existing_meta);

    if ($normalized_source_meta === $normalized_existing_meta) {
        return true;
    }

    return ll_tools_import_meta_is_subset($normalized_source_meta, $normalized_existing_meta);
}

/**
 * Build default import options for preview based on source payload and local data.
 *
 * If a single source wordset matches an existing local slug, default to
 * assigning imported words to that existing wordset.
 *
 * @param array $payload
 * @return array
 */
function ll_tools_build_import_preview_default_options(array $payload): array {
    $defaults = [
        'wordset_mode' => 'create_from_export',
        'target_wordset_id' => 0,
        'wordset_name_overrides' => [],
    ];

    $bundle_type = isset($payload['bundle_type']) ? sanitize_key((string) $payload['bundle_type']) : 'images';
    if ($bundle_type !== 'category_full' && empty($payload['words'])) {
        return $defaults;
    }

    $source_wordsets = isset($payload['wordsets']) && is_array($payload['wordsets']) ? array_values($payload['wordsets']) : [];
    if (empty($source_wordsets) && !empty($payload['words'])) {
        $fallback_wordset_id = 0;
        if (function_exists('ll_get_default_wordset_term_id')) {
            $fallback_wordset_id = (int) ll_get_default_wordset_term_id();
        }
        if ($fallback_wordset_id <= 0) {
            $existing_wordsets = get_terms([
                'taxonomy' => 'wordset',
                'hide_empty' => false,
                'orderby' => 'term_id',
                'order' => 'ASC',
                'number' => 1,
                'fields' => 'ids',
            ]);
            if (!is_wp_error($existing_wordsets) && !empty($existing_wordsets)) {
                $fallback_wordset_id = (int) $existing_wordsets[0];
            }
        }
        if ($fallback_wordset_id > 0) {
            $defaults['wordset_mode'] = 'assign_existing';
            $defaults['target_wordset_id'] = $fallback_wordset_id;
        }
        return $defaults;
    }

    if (count($source_wordsets) !== 1) {
        return $defaults;
    }

    $source_wordset = $source_wordsets[0];
    $source_slug = isset($source_wordset['slug']) ? sanitize_title((string) $source_wordset['slug']) : '';
    if ($source_slug === '') {
        return $defaults;
    }

    $existing = get_term_by('slug', $source_slug, 'wordset');
    if (!$existing || is_wp_error($existing)) {
        return $defaults;
    }

    $existing_term_id = (int) $existing->term_id;
    if ($existing_term_id <= 0) {
        return $defaults;
    }

    $slug_matches = ($source_slug === sanitize_title((string) $existing->slug));
    $is_identical = ll_tools_import_wordset_payload_is_identical($source_wordset, $existing_term_id);

    if ($slug_matches || $is_identical) {
        $defaults['wordset_mode'] = 'assign_existing';
        $defaults['target_wordset_id'] = $existing_term_id;
    }

    return $defaults;
}

/**
 * Normalize text for import-side matching (used for explicit wrong-answer links).
 *
 * @param mixed $value
 * @return string
 */
function ll_tools_import_normalize_match_text($value): string {
    $text = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        if (is_string($normalized) && $normalized !== '') {
            $text = $normalized;
        }
    }

    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }

    return strtolower($text);
}

/**
 * Collect top-level CSV files from an extracted import bundle.
 *
 * @param string $extract_dir
 * @return array
 */
function ll_tools_import_collect_external_csv_files($extract_dir): array {
    $files = [];
    if (!is_dir($extract_dir)) {
        return $files;
    }

    try {
        foreach (new DirectoryIterator($extract_dir) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if (strtolower($entry->getExtension()) !== 'csv') {
                continue;
            }
            $path = wp_normalize_path($entry->getPathname());
            if ($path !== '') {
                $files[] = $path;
            }
        }
    } catch (Exception $e) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

/**
 * Build lookup maps for images under /images in an extracted bundle.
 *
 * @param string $extract_dir
 * @return array
 */
function ll_tools_import_build_external_image_catalog($extract_dir): array {
    $catalog = [
        'exact' => [],
        'stems' => [],
        'paths' => [],
    ];

    $images_dir = trailingslashit((string) $extract_dir) . 'images';
    if (!is_dir($images_dir)) {
        return $catalog;
    }

    $root = trailingslashit(wp_normalize_path((string) $extract_dir));
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($images_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $file_info) {
            if (!$file_info instanceof SplFileInfo || !$file_info->isFile()) {
                continue;
            }

            $absolute = wp_normalize_path($file_info->getPathname());
            if ($absolute === '' || strpos($absolute, $root) !== 0) {
                continue;
            }
            if (is_wp_error(ll_tools_validate_import_image_file($absolute))) {
                continue;
            }

            $relative = substr($absolute, strlen($root));
            $relative = ll_tools_normalize_import_relative_path($relative);
            if ($relative === '') {
                continue;
            }

            $basename = strtolower((string) basename($relative));
            $stem = strtolower((string) pathinfo($basename, PATHINFO_FILENAME));
            $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
            if ($basename === '' || $stem === '') {
                continue;
            }

            if (!isset($catalog['exact'][$basename])) {
                $catalog['exact'][$basename] = $relative;
            }
            if (!isset($catalog['stems'][$stem])) {
                $catalog['stems'][$stem] = [];
            }
            $catalog['stems'][$stem][] = [
                'relative' => $relative,
                'ext' => $ext,
            ];
            $catalog['paths'][$relative] = $absolute;
        }
    } catch (Exception $e) {
        return [
            'exact' => [],
            'stems' => [],
            'paths' => [],
        ];
    }

    foreach ($catalog['stems'] as $stem => $entries) {
        usort($entries, static function (array $left, array $right): int {
            if (($left['ext'] ?? '') === ($right['ext'] ?? '')) {
                return strcmp((string) ($left['relative'] ?? ''), (string) ($right['relative'] ?? ''));
            }
            if (($left['ext'] ?? '') === 'webp') {
                return -1;
            }
            if (($right['ext'] ?? '') === 'webp') {
                return 1;
            }
            return strcmp((string) ($left['ext'] ?? ''), (string) ($right['ext'] ?? ''));
        });
        $catalog['stems'][$stem] = $entries;
    }

    return $catalog;
}

/**
 * Build lookup maps for audio files in /audio (or top-level fallback) in an extracted bundle.
 *
 * @param string $extract_dir
 * @return array
 */
function ll_tools_import_build_external_audio_catalog($extract_dir): array {
    $catalog = [
        'exact' => [],
        'stems' => [],
        'paths' => [],
    ];

    $candidate_roots = [];
    foreach (['audio', 'audios'] as $dir_name) {
        $candidate = trailingslashit((string) $extract_dir) . $dir_name;
        if (is_dir($candidate)) {
            $candidate_roots[] = $candidate;
        }
    }
    if (empty($candidate_roots)) {
        $candidate_roots[] = (string) $extract_dir;
    }

    $root = trailingslashit(wp_normalize_path((string) $extract_dir));
    foreach ($candidate_roots as $scan_root) {
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scan_root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file_info) {
                if (!$file_info instanceof SplFileInfo || !$file_info->isFile()) {
                    continue;
                }

                $absolute = wp_normalize_path($file_info->getPathname());
                if ($absolute === '' || strpos($absolute, $root) !== 0) {
                    continue;
                }

                $basename = strtolower((string) basename($absolute));
                if ($basename === 'data.json' || strtolower((string) pathinfo($basename, PATHINFO_EXTENSION)) === 'csv') {
                    continue;
                }
                if (is_wp_error(ll_tools_validate_import_audio_file($absolute))) {
                    continue;
                }

                $relative = substr($absolute, strlen($root));
                $relative = ll_tools_normalize_import_relative_path($relative);
                if ($relative === '') {
                    continue;
                }

                $basename = strtolower((string) basename($relative));
                $stem = strtolower((string) pathinfo($basename, PATHINFO_FILENAME));
                $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
                if ($basename === '' || $stem === '') {
                    continue;
                }

                if (!isset($catalog['exact'][$basename])) {
                    $catalog['exact'][$basename] = $relative;
                }
                if (!isset($catalog['stems'][$stem])) {
                    $catalog['stems'][$stem] = [];
                }
                $catalog['stems'][$stem][] = [
                    'relative' => $relative,
                    'ext' => $ext,
                ];
                $catalog['paths'][$relative] = $absolute;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    foreach ($catalog['stems'] as $stem => $entries) {
        usort($entries, static function (array $left, array $right): int {
            if (($left['ext'] ?? '') === ($right['ext'] ?? '')) {
                return strcmp((string) ($left['relative'] ?? ''), (string) ($right['relative'] ?? ''));
            }
            return strcmp((string) ($left['ext'] ?? ''), (string) ($right['ext'] ?? ''));
        });
        $catalog['stems'][$stem] = $entries;
    }

    return $catalog;
}

/**
 * Resolve a CSV media reference to an extracted file path relative to bundle root.
 *
 * Supports extension drift (e.g. CSV says .jpg, file is actually .webp).
 *
 * @param string $reference
 * @param array $catalog
 * @return string
 */
function ll_tools_import_choose_external_catalog_match(string $reference, array $catalog): string {
    $reference = trim($reference);
    if ($reference === '') {
        return '';
    }

    $reference = str_replace('\\', '/', $reference);
    $reference = preg_replace('/[?#].*$/', '', $reference);
    $filename = strtolower((string) basename((string) $reference));
    if ($filename === '') {
        return '';
    }

    if (isset($catalog['exact'][$filename])) {
        return (string) $catalog['exact'][$filename];
    }

    $stem = strtolower((string) pathinfo($filename, PATHINFO_FILENAME));
    if ($stem === '') {
        return '';
    }

    $candidates = isset($catalog['stems'][$stem]) && is_array($catalog['stems'][$stem])
        ? $catalog['stems'][$stem]
        : [];
    if (empty($candidates)) {
        return '';
    }

    $requested_ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if ($requested_ext !== '') {
        foreach ($candidates as $candidate) {
            if (($candidate['ext'] ?? '') === $requested_ext) {
                return (string) ($candidate['relative'] ?? '');
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (($candidate['ext'] ?? '') === 'webp') {
            return (string) ($candidate['relative'] ?? '');
        }
    }

    return (string) ($candidates[0]['relative'] ?? '');
}

/**
 * Resolve a CSV image reference to an extracted image path relative to bundle root.
 *
 * @param string $reference
 * @param array $catalog
 * @return string
 */
function ll_tools_import_choose_external_image_match(string $reference, array $catalog): string {
    return ll_tools_import_choose_external_catalog_match($reference, $catalog);
}

/**
 * Resolve a CSV audio reference to an extracted audio path relative to bundle root.
 *
 * @param string $reference
 * @param array $catalog
 * @return string
 */
function ll_tools_import_choose_external_audio_match(string $reference, array $catalog): string {
    return ll_tools_import_choose_external_catalog_match($reference, $catalog);
}

/**
 * Convert external CSV text values to UTF-8 when possible.
 *
 * @param mixed $value
 * @return string
 */
function ll_tools_import_decode_external_csv_text($value): string {
    $text = (string) $value;
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if ($text === '') {
        return '';
    }

    if ((function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) || preg_match('//u', $text) === 1) {
        return $text;
    }

    $encodings = ['CP1255', 'Windows-1255', 'ISO-8859-8', 'Windows-1252', 'ISO-8859-1'];

    if (function_exists('mb_detect_encoding')) {
        $detect_encodings = $encodings;
        if (function_exists('mb_list_encodings')) {
            $available = array_map('strtoupper', (array) mb_list_encodings());
            $detect_encodings = array_values(array_filter($detect_encodings, static function (string $encoding) use ($available): bool {
                return in_array(strtoupper($encoding), $available, true);
            }));
        }
        if (empty($detect_encodings)) {
            $detect_encodings = ['ISO-8859-1'];
        }
        try {
            $detected = mb_detect_encoding($text, array_merge(['UTF-8'], $detect_encodings), true);
        } catch (Throwable $e) {
            $detected = false;
        }
        if (is_string($detected) && $detected !== '' && strcasecmp($detected, 'UTF-8') !== 0) {
            array_unshift($encodings, $detected);
            $encodings = array_values(array_unique($encodings));
        }
    }

    foreach ($encodings as $encoding) {
        $converted = '';
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = (string) mb_convert_encoding($text, 'UTF-8', (string) $encoding);
            } catch (Throwable $e) {
                $converted = '';
            }
        }
        if ($converted === '' && function_exists('iconv')) {
            $iconv = @iconv((string) $encoding, 'UTF-8//IGNORE', $text);
            $converted = is_string($iconv) ? $iconv : '';
        }

        if ($converted === '') {
            continue;
        }
        if (!function_exists('mb_check_encoding') || mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    return $text;
}

/**
 * Convert raw CSV bytes to UTF-8 for robust parsing (supports BOM/UTF-16 bundles).
 *
 * @param string $contents
 * @return string
 */
function ll_tools_import_convert_external_csv_bytes_to_utf8(string $contents): string {
    if ($contents === '') {
        return '';
    }

    // UTF-8 BOM
    if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $contents = substr($contents, 3);
    }

    $bom_source_encoding = '';
    if (strncmp($contents, "\xFF\xFE\x00\x00", 4) === 0) {
        $bom_source_encoding = 'UTF-32LE';
    } elseif (strncmp($contents, "\x00\x00\xFE\xFF", 4) === 0) {
        $bom_source_encoding = 'UTF-32BE';
    } elseif (strncmp($contents, "\xFF\xFE", 2) === 0) {
        $bom_source_encoding = 'UTF-16LE';
    } elseif (strncmp($contents, "\xFE\xFF", 2) === 0) {
        $bom_source_encoding = 'UTF-16BE';
    }

    if ($bom_source_encoding !== '') {
        if (function_exists('mb_convert_encoding')) {
            try {
                return (string) mb_convert_encoding($contents, 'UTF-8', $bom_source_encoding);
            } catch (Throwable $e) {
                // fall through to additional detection/conversion paths.
            }
        }
        if (function_exists('iconv')) {
            $iconv = @iconv($bom_source_encoding, 'UTF-8//IGNORE', $contents);
            if (is_string($iconv) && $iconv !== '') {
                return $iconv;
            }
        }
    }

    if ((function_exists('mb_check_encoding') && mb_check_encoding($contents, 'UTF-8')) || preg_match('//u', $contents) === 1) {
        return $contents;
    }

    $candidates = [
        'UTF-16LE',
        'UTF-16BE',
        'UTF-32LE',
        'UTF-32BE',
        'CP1255',
        'Windows-1255',
        'ISO-8859-8',
        'Windows-1252',
        'ISO-8859-1',
    ];

    foreach ($candidates as $source_encoding) {
        $converted = '';
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = (string) mb_convert_encoding($contents, 'UTF-8', (string) $source_encoding);
            } catch (Throwable $e) {
                $converted = '';
            }
        }
        if ($converted === '' && function_exists('iconv')) {
            $iconv = @iconv($source_encoding, 'UTF-8//IGNORE', $contents);
            $converted = is_string($iconv) ? $iconv : '';
        }
        if ($converted !== '' && (!function_exists('mb_check_encoding') || mb_check_encoding($converted, 'UTF-8'))) {
            return $converted;
        }
    }

    return $contents;
}

/**
 * Normalize a CSV header name for loose matching.
 *
 * @param mixed $header
 * @return string
 */
function ll_tools_import_normalize_external_csv_header($header): string {
    $header = ll_tools_import_decode_external_csv_text($header);
    $header = trim((string) $header);
    if ($header === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $header = mb_strtolower($header, 'UTF-8');
    } else {
        $header = strtolower($header);
    }

    $header = str_replace(['_', '-', '.'], ' ', $header);
    $header = preg_replace('/\s+/', ' ', $header);
    return trim((string) $header);
}

/**
 * Detect CSV delimiter from the first line.
 *
 * @param string $line
 * @return string
 */
function ll_tools_import_detect_external_csv_delimiter(string $line): string {
    $line = ll_tools_import_decode_external_csv_text($line);
    $line = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line);
    $scores = [
        ',' => substr_count($line, ','),
        ';' => substr_count($line, ';'),
        "\t" => substr_count($line, "\t"),
        '|' => substr_count($line, '|'),
    ];
    arsort($scores);
    $candidate = (string) key($scores);
    if ($candidate === '' || (int) ($scores[$candidate] ?? 0) <= 0) {
        return ',';
    }
    return $candidate;
}

/**
 * Get a CSV cell value by index.
 *
 * @param array $row
 * @param int $index
 * @return string
 */
function ll_tools_import_get_external_csv_cell(array $row, int $index): string {
    if ($index < 0 || !array_key_exists($index, $row)) {
        return '';
    }
    return trim(ll_tools_import_decode_external_csv_text($row[$index]));
}

/**
 * Find a header index by candidate normalized names.
 *
 * @param array $headers
 * @param array $candidates
 * @return int
 */
function ll_tools_import_find_external_csv_header_index(array $headers, array $candidates): int {
    $normalized_candidates = [];
    foreach ($candidates as $candidate) {
        $normalized = ll_tools_import_normalize_external_csv_header($candidate);
        if ($normalized !== '') {
            $normalized_candidates[$normalized] = true;
        }
    }
    if (empty($normalized_candidates)) {
        return -1;
    }

    foreach ($headers as $index => $header) {
        if (isset($normalized_candidates[(string) $header])) {
            return (int) $index;
        }
    }

    return -1;
}

/**
 * Build a compatible import payload from an external CSV + media bundle.
 *
 * Supported CSV modes:
 * - image -> text: quiz,image,correct answer,wrong answer...
 * - text -> image: quiz,image,correct answer
 * - text -> text:  quiz,prompt_text,correct_answer,wrong_answer...
 * - audio -> text: quiz,audio_file,correct_answer,wrong_answer...
 *
 * @param string $extract_dir
 * @return array|WP_Error
 */
function ll_tools_import_build_payload_from_external_csv_bundle($extract_dir) {
    $csv_files = ll_tools_import_collect_external_csv_files($extract_dir);
    if (empty($csv_files)) {
        return new WP_Error(
            'll_tools_preview_missing_payload',
            __('Import failed: no top-level CSV files were found in the zip.', 'll-tools-text-domain')
        );
    }

    $image_catalog = ll_tools_import_build_external_image_catalog($extract_dir);
    $audio_catalog = ll_tools_import_build_external_audio_catalog($extract_dir);

    $category_map = [];
    $word_map = [];
    $used_slugs = [];
    $used_image_files = [];
    $used_audio_files = [];
    $warnings = [];
    $warning_overflow_count = 0;
    $max_warning_messages = 50;
    $csv_files_used = 0;
    $csv_files_skipped = 0;
    $rows_nonempty = 0;
    $rows_used = 0;
    $rows_skipped = 0;
    $add_warning = static function (string $message) use (&$warnings, &$warning_overflow_count, $max_warning_messages): void {
        if (count($warnings) >= $max_warning_messages) {
            $warning_overflow_count++;
            return;
        }
        $warnings[] = $message;
    };

    foreach ($csv_files as $csv_path) {
        $raw_csv = @file_get_contents((string) $csv_path);
        if (!is_string($raw_csv)) {
            $csv_files_skipped++;
            $add_warning(sprintf(
                __('Could not read CSV file "%s".', 'll-tools-text-domain'),
                basename((string) $csv_path)
            ));
            continue;
        }

        $csv_contents = ll_tools_import_convert_external_csv_bytes_to_utf8($raw_csv);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $csv_files_skipped++;
            $add_warning(sprintf(
                __('Could not read CSV file "%s".', 'll-tools-text-domain'),
                basename((string) $csv_path)
            ));
            continue;
        }
        fwrite($handle, $csv_contents);
        rewind($handle);

        $first_line = fgets($handle);
        if ($first_line === false) {
            $csv_files_skipped++;
            $add_warning(sprintf(
                __('Skipped CSV "%s" because it is empty.', 'll-tools-text-domain'),
                basename((string) $csv_path)
            ));
            fclose($handle);
            continue;
        }
        $delimiter = ll_tools_import_detect_external_csv_delimiter((string) $first_line);
        rewind($handle);

        $header_row = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($header_row) || empty($header_row)) {
            $csv_files_skipped++;
            $add_warning(sprintf(
                __('Skipped CSV "%s" because the header row could not be read.', 'll-tools-text-domain'),
                basename((string) $csv_path)
            ));
            fclose($handle);
            continue;
        }

        $headers = array_map('ll_tools_import_normalize_external_csv_header', $header_row);
        $quiz_index = ll_tools_import_find_external_csv_header_index($headers, ['quiz']);
        $image_index = ll_tools_import_find_external_csv_header_index($headers, [
            'image',
            'image file',
            'image filename',
            'image name',
            'image prompt',
            'prompt image',
            'picture',
            'picture file',
            'photo',
        ]);
        $audio_index = ll_tools_import_find_external_csv_header_index($headers, [
            'audio',
            'audio file',
            'audio filename',
            'audio name',
            'prompt audio',
            'recording',
            'recording file',
            'sound file',
        ]);
        $prompt_text_index = ll_tools_import_find_external_csv_header_index($headers, [
            'prompt text',
            'prompt_text',
            'prompt',
            'prompt label',
            'question text',
        ]);
        $correct_index = ll_tools_import_find_external_csv_header_index($headers, [
            'correct answer',
            'correct_answer',
            'correct',
            'answer',
            'correct text',
            'text',
            'word',
            'label',
        ]);

        $wrong_indexes = [];
        foreach ($headers as $index => $header) {
            $header = (string) $header;
            if ($header === '') {
                continue;
            }
            if (strpos($header, 'wrong') !== false && (strpos($header, 'answer') !== false || preg_match('/^wrong(\b|\s*\d+)/', $header))) {
                $wrong_indexes[] = (int) $index;
            }
        }

        $mode = '';
        if ($image_index >= 0) {
            $mode = !empty($wrong_indexes) ? 'image_to_text' : 'text_to_image';
        } elseif ($audio_index >= 0) {
            $mode = 'audio_to_text';
        } elseif ($prompt_text_index >= 0) {
            $mode = 'text_to_text';
        }

        if ($quiz_index < 0 || $correct_index < 0 || $mode === '') {
            fclose($handle);
            $csv_files_skipped++;
            $add_warning(sprintf(
                __('Skipped CSV "%s" because required columns were not found.', 'll-tools-text-domain'),
                basename((string) $csv_path)
            ));
            continue;
        }

        $csv_files_used++;
        $csv_row_number = 1;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $csv_row_number++;

            $category_name = ll_tools_import_get_external_csv_cell($row, $quiz_index);
            $correct_answer = ll_tools_import_get_external_csv_cell($row, $correct_index);
            $image_reference = ($image_index >= 0) ? ll_tools_import_get_external_csv_cell($row, $image_index) : '';
            $audio_reference = ($audio_index >= 0) ? ll_tools_import_get_external_csv_cell($row, $audio_index) : '';
            $prompt_text = ($prompt_text_index >= 0) ? ll_tools_import_get_external_csv_cell($row, $prompt_text_index) : '';

            if ($category_name === '' && $correct_answer === '' && $image_reference === '' && $audio_reference === '' && $prompt_text === '') {
                continue;
            }
            $rows_nonempty++;
            if ($category_name === '' || $correct_answer === '') {
                $rows_skipped++;
                $add_warning(sprintf(
                    __('Skipped row %1$d in CSV "%2$s": category and answer are required.', 'll-tools-text-domain'),
                    $csv_row_number,
                    basename((string) $csv_path)
                ));
                continue;
            }
            if ($mode === 'text_to_text' && $prompt_text === '') {
                $rows_skipped++;
                $add_warning(sprintf(
                    __('Skipped row %1$d in CSV "%2$s": prompt text is required for text-to-text quizzes.', 'll-tools-text-domain'),
                    $csv_row_number,
                    basename((string) $csv_path)
                ));
                continue;
            }

            $category_slug = sanitize_title($category_name);
            if ($category_slug === '') {
                $rows_skipped++;
                $add_warning(sprintf(
                    __('Skipped row %1$d in CSV "%2$s": category name could not be converted to a valid slug.', 'll-tools-text-domain'),
                    $csv_row_number,
                    basename((string) $csv_path)
                ));
                continue;
            }

            if (!isset($category_map[$category_slug])) {
                $category_map[$category_slug] = [
                    'slug' => $category_slug,
                    'name' => $category_name,
                    'mode' => $mode,
                ];
            } elseif (($category_map[$category_slug]['mode'] ?? '') !== $mode) {
                $rows_skipped++;
                $add_warning(sprintf(
                    __('Skipped row %1$d in CSV "%2$s": category "%3$s" mixes incompatible quiz formats.', 'll-tools-text-domain'),
                    $csv_row_number,
                    basename((string) $csv_path),
                    $category_name
                ));
                continue;
            }

            $correct_answer_key = ll_tools_import_normalize_match_text($correct_answer);
            $image_relative = '';
            $audio_relative = '';
            $prompt_identity = '';

            if ($mode === 'image_to_text' || $mode === 'text_to_image') {
                $image_relative = ll_tools_import_choose_external_image_match($image_reference, $image_catalog);
                if ($image_relative === '') {
                    $rows_skipped++;
                    $add_warning(sprintf(
                        __('Skipped row %1$d in CSV "%2$s": image "%3$s" was not found in /images.', 'll-tools-text-domain'),
                        $csv_row_number,
                        basename((string) $csv_path),
                        $image_reference
                    ));
                    continue;
                }

                $used_image_files[$image_relative] = true;
                $prompt_identity = 'image:' . ll_tools_import_normalize_match_text($image_relative);
            } elseif ($mode === 'audio_to_text') {
                $audio_relative = ll_tools_import_choose_external_audio_match($audio_reference, $audio_catalog);
                if ($audio_relative === '') {
                    $rows_skipped++;
                    $add_warning(sprintf(
                        __('Skipped row %1$d in CSV "%2$s": audio "%3$s" was not found.', 'll-tools-text-domain'),
                        $csv_row_number,
                        basename((string) $csv_path),
                        $audio_reference
                    ));
                    continue;
                }

                $used_audio_files[$audio_relative] = true;
                $prompt_identity = 'audio:' . ll_tools_import_normalize_match_text($audio_relative);
            } elseif ($mode === 'text_to_text') {
                $prompt_identity = 'text:' . ll_tools_import_normalize_match_text($prompt_text);
            }

            $word_key = $category_slug . '|' . $mode . '|' . $correct_answer_key;
            if ($prompt_identity !== '') {
                $word_key .= '|' . $prompt_identity;
            }

            if (!isset($word_map[$word_key])) {
                $base_slug = sanitize_title($correct_answer);
                $using_hashed_base_slug = false;
                if ($base_slug === '') {
                    $hash_source = $correct_answer_key !== '' ? $correct_answer_key : $word_key;
                    $base_slug = 'item-' . substr(md5($hash_source), 0, 10);
                    $using_hashed_base_slug = true;
                }

                $slug_seed = sanitize_title($category_slug . '-' . $base_slug);
                if ($slug_seed === '') {
                    $slug_seed = 'imported-word-' . substr(md5($word_key), 0, 12);
                }
                if ($using_hashed_base_slug && $prompt_identity !== '') {
                    $slug_seed = sanitize_title($slug_seed . '-' . substr(md5($prompt_identity), 0, 8));
                }

                $slug = $slug_seed;
                $slug_index = 2;
                while ($slug === '' || isset($used_slugs[$slug])) {
                    $slug = sanitize_title($slug_seed . '-' . $slug_index);
                    $slug_index++;
                }
                $used_slugs[$slug] = true;

                $meta = [];
                if ($mode === 'text_to_text' && $prompt_text !== '') {
                    $meta['word_translation'] = [$prompt_text];
                } elseif ($mode === 'text_to_image') {
                    // Text->image CSVs define only a title label; clear legacy translation fields
                    // so prior imports/edits do not leak into the new import result.
                    $meta['word_translation'] = [];
                    $meta['word_english_meaning'] = [];
                }

                $word_map[$word_key] = [
                    'origin_id'       => 0,
                    'slug'            => $slug,
                    'title'           => $correct_answer,
                    'content'         => '',
                    'excerpt'         => '',
                    'status'          => 'publish',
                    'meta'            => $meta,
                    'categories'      => [$category_slug],
                    'wordsets'        => [],
                    'linked_word_image_slug' => '',
                    'languages'       => [],
                    'parts_of_speech' => [],
                    'featured_image'  => [],
                    'audio_entries' => [],
                    'specific_wrong_answer_texts' => [],
                ];
            }

            if ($mode === 'image_to_text' || $mode === 'text_to_image') {
                $word_map[$word_key]['featured_image'] = [
                    'file'      => $image_relative,
                    'mime_type' => '',
                    'alt'       => $correct_answer,
                    'title'     => $correct_answer,
                ];
            } elseif ($mode === 'audio_to_text') {
                $existing_audio_entries = isset($word_map[$word_key]['audio_entries']) && is_array($word_map[$word_key]['audio_entries'])
                    ? $word_map[$word_key]['audio_entries']
                    : [];
                $already_added = false;
                foreach ($existing_audio_entries as $existing_audio) {
                    $existing_file = isset($existing_audio['audio_file']['file']) ? (string) $existing_audio['audio_file']['file'] : '';
                    if ($existing_file === $audio_relative) {
                        $already_added = true;
                        break;
                    }
                }
                if (!$already_added) {
                    $audio_count = count($existing_audio_entries) + 1;
                    $audio_slug = sanitize_title((string) ($word_map[$word_key]['slug'] ?? '') . '-audio-' . $audio_count);
                    if ($audio_slug === '') {
                        $audio_slug = 'imported-audio-' . wp_generate_password(8, false, false);
                    }
                    $word_map[$word_key]['audio_entries'][] = [
                        'origin_id' => 0,
                        'slug' => $audio_slug,
                        'title' => $correct_answer,
                        'status' => 'publish',
                        'meta' => [],
                        'recording_types' => ['isolation'],
                        'audio_file' => [
                            'file' => $audio_relative,
                            'mime_type' => '',
                            'title' => $correct_answer,
                        ],
                    ];
                }
            } elseif ($mode === 'text_to_text') {
                $existing_prompt = isset($word_map[$word_key]['meta']['word_translation'][0])
                    ? (string) $word_map[$word_key]['meta']['word_translation'][0]
                    : '';
                if ($existing_prompt === '' && $prompt_text !== '') {
                    $word_map[$word_key]['meta']['word_translation'] = [$prompt_text];
                }
            }

            if (in_array($mode, ['image_to_text', 'audio_to_text', 'text_to_text'], true) && !empty($wrong_indexes)) {
                $existing_wrong = isset($word_map[$word_key]['specific_wrong_answer_texts']) && is_array($word_map[$word_key]['specific_wrong_answer_texts'])
                    ? $word_map[$word_key]['specific_wrong_answer_texts']
                    : [];
                $wrong_lookup = [];
                foreach ($existing_wrong as $wrong_text) {
                    $normalized = ll_tools_import_normalize_match_text($wrong_text);
                    if ($normalized !== '') {
                        $wrong_lookup[$normalized] = (string) $wrong_text;
                    }
                }
                $correct_normalized = ll_tools_import_normalize_match_text($correct_answer);
                foreach ($wrong_indexes as $wrong_index) {
                    $wrong_text = ll_tools_import_get_external_csv_cell($row, (int) $wrong_index);
                    if ($wrong_text === '') {
                        continue;
                    }
                    $wrong_normalized = ll_tools_import_normalize_match_text($wrong_text);
                    if ($wrong_normalized === '' || $wrong_normalized === $correct_normalized) {
                        continue;
                    }
                    if (!isset($wrong_lookup[$wrong_normalized])) {
                        $wrong_lookup[$wrong_normalized] = $wrong_text;
                    }
                }
                $word_map[$word_key]['specific_wrong_answer_texts'] = array_values($wrong_lookup);
            }

            $rows_used++;
        }

        fclose($handle);
    }

    if ($warning_overflow_count > 0) {
        $warnings[] = sprintf(
            _n(
                '%d additional CSV issue was not shown.',
                '%d additional CSV issues were not shown.',
                $warning_overflow_count,
                'll-tools-text-domain'
            ),
            $warning_overflow_count
        );
    }

    if (empty($word_map) || empty($category_map)) {
        $message = __('Import failed: no valid quiz rows were found in the CSV files.', 'll-tools-text-domain');
        if (!empty($warnings)) {
            $message .= ' ' . implode(' ', array_slice($warnings, 0, 3));
        }
        return new WP_Error('ll_tools_preview_missing_payload', $message);
    }

    $categories = [];
    foreach ($category_map as $category_slug => $category) {
        $mode = (string) ($category['mode'] ?? 'text_to_image');
        $prompt_type = 'text_title';
        $option_type = 'image';
        if ($mode === 'image_to_text') {
            $prompt_type = 'image';
            $option_type = 'text_title';
        } elseif ($mode === 'text_to_text') {
            $prompt_type = 'text_translation';
            $option_type = 'text_title';
        } elseif ($mode === 'audio_to_text') {
            $prompt_type = 'audio';
            $option_type = 'text_title';
        }
        $categories[] = [
            'slug' => (string) $category_slug,
            'name' => (string) ($category['name'] ?? $category_slug),
            'description' => '',
            'parent_slug' => '',
            'meta' => [
                'll_quiz_prompt_type' => [$prompt_type],
                'll_quiz_option_type' => [$option_type],
            ],
        ];
    }
    usort($categories, static function (array $left, array $right): int {
        return strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
    });

    $words = array_values($word_map);
    usort($words, static function (array $left, array $right): int {
        return strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
    });

    $attachment_count = 0;
    $attachment_bytes = 0;
    foreach (array_keys($used_image_files) as $relative) {
        $absolute = isset($image_catalog['paths'][$relative]) ? (string) $image_catalog['paths'][$relative] : '';
        if ($absolute === '' || !is_file($absolute)) {
            continue;
        }
        $attachment_count++;
        $size = @filesize($absolute);
        if ($size !== false && $size > 0) {
            $attachment_bytes += (int) $size;
        }
    }
    foreach (array_keys($used_audio_files) as $relative) {
        $absolute = isset($audio_catalog['paths'][$relative]) ? (string) $audio_catalog['paths'][$relative] : '';
        if ($absolute === '' || !is_file($absolute)) {
            continue;
        }
        $attachment_count++;
        $size = @filesize($absolute);
        if ($size !== false && $size > 0) {
            $attachment_bytes += (int) $size;
        }
    }

    return [
        'bundle_type' => 'category_full',
        'source_format' => 'external_csv_mappings',
        'categories' => $categories,
        'word_images' => [],
        'wordsets' => [],
        'words' => $words,
        'import_warnings' => $warnings,
        'import_parser_summary' => [
            'csv_files_found' => count($csv_files),
            'csv_files_used' => $csv_files_used,
            'csv_files_skipped' => $csv_files_skipped,
            'rows_nonempty' => $rows_nonempty,
            'rows_used' => $rows_used,
            'rows_skipped' => $rows_skipped,
        ],
        'media_estimate' => [
            'attachment_count' => $attachment_count,
            'attachment_bytes' => $attachment_bytes,
        ],
    ];
}

/**
 * Read import payload from extracted bundle directory.
 *
 * Supports:
 * - native LL bundle (data.json + media files)
 * - external CSV/media bundle (top-level CSV files with /images and/or /audio)
 *
 * @param string $extract_dir
 * @return array|WP_Error
 */
function ll_tools_import_read_payload_from_extract_dir($extract_dir) {
    $data_path = trailingslashit((string) $extract_dir) . 'data.json';
    if (file_exists($data_path)) {
        $data_contents = file_get_contents($data_path);
        $payload = json_decode((string) $data_contents, true);
        if (!is_array($payload)) {
            return new WP_Error('ll_tools_preview_invalid_json', __('Import failed: data.json is not valid JSON.', 'll-tools-text-domain'));
        }
        if (!array_key_exists('categories', $payload) || !array_key_exists('word_images', $payload)) {
            return new WP_Error('ll_tools_preview_missing_payload', __('Import failed: payload missing categories or word images.', 'll-tools-text-domain'));
        }
        return $payload;
    }

    return ll_tools_import_build_payload_from_external_csv_bundle($extract_dir);
}

/**
 * Read and validate bundle payload from a zip for preview purposes.
 *
 * @param string $zip_path
 * @return array|WP_Error
 */
function ll_tools_read_import_preview_from_zip($zip_path) {
    if (!file_exists($zip_path)) {
        return new WP_Error('ll_tools_import_missing_file', __('Import failed: uploaded file is missing.', 'll-tools-text-domain'));
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return new WP_Error('ll_tools_import_open_failed', __('Import failed: could not open zip file.', 'll-tools-text-domain'));
    }

    $upload_dir = wp_upload_dir();
    $extract_dir = trailingslashit($upload_dir['basedir']) . 'll-tools-preview-' . wp_generate_password(8, false, false);
    if (!wp_mkdir_p($extract_dir)) {
        $zip->close();
        return new WP_Error('ll_tools_preview_extract_dir', __('Import failed: could not create temporary extraction directory.', 'll-tools-text-domain'));
    }

    $extract_result = ll_tools_extract_zip_safely($zip, $extract_dir);
    $zip->close();
    if (is_wp_error($extract_result)) {
        ll_tools_rrmdir($extract_dir);
        return $extract_result;
    }

    $payload = ll_tools_import_read_payload_from_extract_dir($extract_dir);
    ll_tools_rrmdir($extract_dir);

    if (is_wp_error($payload)) {
        return $payload;
    }

    return [
        'payload' => $payload,
        'preview' => ll_tools_build_import_preview_data_from_payload($payload),
    ];
}

/**
 * Handle preview action: inspect bundle and store summary for user confirmation.
 */
function ll_tools_handle_preview_import_bundle() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to import LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_preview_import_bundle');

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';

    $zip_info = ll_tools_resolve_import_request_zip();
    if (is_wp_error($zip_info)) {
        ll_tools_store_import_result_and_redirect([
            'ok' => false,
            'message' => __('Import preview failed.', 'll-tools-text-domain'),
            'errors' => [$zip_info->get_error_message()],
            'stats' => [],
        ]);
    }

    $zip_path = (string) $zip_info['zip_path'];
    $preview_result = ll_tools_read_import_preview_from_zip($zip_path);
    if (is_wp_error($preview_result)) {
        if (!empty($zip_info['cleanup_zip']) && $zip_path !== '') {
            @unlink($zip_path);
        }
        ll_tools_store_import_result_and_redirect([
            'ok' => false,
            'message' => __('Import preview failed.', 'll-tools-text-domain'),
            'errors' => [$preview_result->get_error_message()],
            'stats' => [],
        ]);
    }

    $payload_for_defaults = isset($preview_result['payload']) && is_array($preview_result['payload'])
        ? $preview_result['payload']
        : [];
    $preview_options = ll_tools_build_import_preview_default_options($payload_for_defaults);
    $preview_data = is_array($preview_result['preview'] ?? null) ? $preview_result['preview'] : [];
    $preview_data['zip_path'] = $zip_path;
    $preview_data['zip_name'] = basename($zip_path);
    $preview_data['source_type'] = !empty($zip_info['uploaded_file']) ? 'uploaded' : 'server';
    $preview_data['cleanup_zip'] = !empty($zip_info['cleanup_zip']);
    $preview_data['options'] = $preview_options;
    $preview_data['created_by'] = get_current_user_id();
    $preview_data['created_at'] = time();

    $token = wp_generate_password(20, false, false);
    set_transient(ll_tools_import_preview_transient_key($token), $preview_data, 30 * MINUTE_IN_SECONDS);

    $redirect_url = ll_tools_get_export_import_page_url(ll_tools_get_import_page_slug(), [
        'll_import_preview' => $token,
    ]);
    $redirect_url .= '#ll-tools-import-preview';
    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Handle the import action: upload zip, unpack, and rebuild objects.
 */
function ll_tools_handle_import_bundle() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to import LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_import_bundle');

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'stats'   => [],
    ];

    $preview_token = '';
    if (!empty($_POST['ll_import_preview_token'])) {
        $preview_token = sanitize_text_field(wp_unslash((string) $_POST['ll_import_preview_token']));
    }

    $zip_path = '';
    $cleanup_zip = false;
    $preview_defaults = [];
    $history_source_type = 'server';
    $history_source_zip = '';
    if ($preview_token !== '') {
        $preview_key = ll_tools_import_preview_transient_key($preview_token);
        $preview_data = get_transient($preview_key);
        if (!is_array($preview_data) || empty($preview_data['zip_path'])) {
            $result['message'] = __('Import failed: preview is missing or expired. Please preview the bundle again.', 'll-tools-text-domain');
            ll_tools_store_import_result_and_redirect($result);
        }

        $zip_path = (string) $preview_data['zip_path'];
        $cleanup_zip = !empty($preview_data['cleanup_zip']);
        $preview_defaults = isset($preview_data['options']) && is_array($preview_data['options']) ? $preview_data['options'] : [];
        $history_source_type = isset($preview_data['source_type']) ? sanitize_key((string) $preview_data['source_type']) : 'server';
        $history_source_zip = isset($preview_data['zip_name']) ? (string) $preview_data['zip_name'] : basename($zip_path);
    } else {
        // Fallback path for direct imports (legacy flow).
        $zip_info = ll_tools_resolve_import_request_zip();
        if (is_wp_error($zip_info)) {
            $result['message'] = __('Import failed: could not resolve zip file.', 'll-tools-text-domain');
            $result['errors'][] = $zip_info->get_error_message();
            ll_tools_store_import_result_and_redirect($result);
        }
        $zip_path = (string) $zip_info['zip_path'];
        $cleanup_zip = !empty($zip_info['cleanup_zip']);
        $history_source_type = !empty($zip_info['uploaded_file']) ? 'uploaded' : 'server';
        $history_source_zip = basename($zip_path);
    }

    if ($zip_path === '' || !file_exists($zip_path)) {
        $result['message'] = __('Import failed: selected zip file is missing.', 'll-tools-text-domain');
        ll_tools_store_import_result_and_redirect($result);
    }

    $import_options = array_merge($preview_defaults, ll_tools_parse_import_options($_POST));
    $processed = ll_tools_process_import_zip($zip_path, $import_options);
    if ($cleanup_zip) {
        @unlink($zip_path);
    }
    if ($preview_token !== '') {
        delete_transient(ll_tools_import_preview_transient_key($preview_token));
    }

    ll_tools_import_append_history_entry([
        'id' => wp_generate_uuid4(),
        'finished_at' => time(),
        'user_id' => get_current_user_id(),
        'ok' => !empty($processed['ok']),
        'message' => isset($processed['message']) ? (string) $processed['message'] : '',
        'errors_count' => isset($processed['errors']) && is_array($processed['errors']) ? count($processed['errors']) : 0,
        'stats' => isset($processed['stats']) && is_array($processed['stats']) ? $processed['stats'] : [],
        'source_type' => $history_source_type === 'uploaded' ? 'uploaded' : 'server',
        'source_zip' => $history_source_zip !== '' ? $history_source_zip : basename($zip_path),
        'undo' => isset($processed['undo']) && is_array($processed['undo']) ? $processed['undo'] : ll_tools_import_default_undo_payload(),
        'undone_at' => 0,
    ]);

    ll_tools_store_import_result_and_redirect($processed);
}

function ll_tools_import_delete_audio_file_if_safe(string $audio_path): bool {
    $audio_path = trim($audio_path);
    if ($audio_path === '') {
        return true;
    }

    $absolute = ll_tools_export_resolve_audio_source_path($audio_path);
    if ($absolute === '' || !is_file($absolute)) {
        return true;
    }

    $upload_dir = wp_upload_dir();
    $uploads_base = wp_normalize_path((string) ($upload_dir['basedir'] ?? ''));
    $absolute_normalized = wp_normalize_path($absolute);
    if ($uploads_base === '' || strpos($absolute_normalized, $uploads_base) !== 0) {
        return false;
    }

    return @unlink($absolute) !== false;
}

function ll_tools_undo_import_entry(array $entry): array {
    $undo = isset($entry['undo']) && is_array($entry['undo']) ? $entry['undo'] : ll_tools_import_default_undo_payload();
    $result = [
        'ok' => true,
        'message' => __('Undo complete.', 'll-tools-text-domain'),
        'errors' => [],
        'stats' => [
            'word_audio_deleted' => 0,
            'words_deleted' => 0,
            'word_images_deleted' => 0,
            'attachments_deleted' => 0,
            'categories_deleted' => 0,
            'wordsets_deleted' => 0,
            'audio_files_deleted' => 0,
        ],
    ];

    $word_audio_ids = ll_tools_import_normalize_id_list((array) ($undo['word_audio_post_ids'] ?? []));
    $word_ids = ll_tools_import_normalize_id_list((array) ($undo['word_post_ids'] ?? []));
    $word_image_ids = ll_tools_import_normalize_id_list((array) ($undo['word_image_post_ids'] ?? []));
    $attachment_ids = ll_tools_import_normalize_id_list((array) ($undo['attachment_ids'] ?? []));
    $category_ids = ll_tools_import_normalize_id_list((array) ($undo['category_term_ids'] ?? []));
    $wordset_ids = ll_tools_import_normalize_id_list((array) ($undo['wordset_term_ids'] ?? []));
    $audio_paths = array_values(array_unique(array_filter(array_map('strval', (array) ($undo['audio_paths'] ?? [])), static function (string $path): bool {
        return trim($path) !== '';
    })));

    foreach ($word_audio_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }
        if ($post->post_type !== 'word_audio') {
            $result['errors'][] = sprintf(__('Skipped post %d during undo because it is not word_audio.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $deleted = wp_delete_post($post_id, true);
        if (!$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete word_audio post %d during undo.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $result['stats']['word_audio_deleted']++;
    }

    foreach ($word_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }
        if ($post->post_type !== 'words') {
            $result['errors'][] = sprintf(__('Skipped post %d during undo because it is not a word.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $deleted = wp_delete_post($post_id, true);
        if (!$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete word post %d during undo.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $result['stats']['words_deleted']++;
    }

    foreach ($word_image_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }
        if ($post->post_type !== 'word_images') {
            $result['errors'][] = sprintf(__('Skipped post %d during undo because it is not a word image.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $deleted = wp_delete_post($post_id, true);
        if (!$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete word image post %d during undo.', 'll-tools-text-domain'), $post_id);
            continue;
        }
        $result['stats']['word_images_deleted']++;
    }

    foreach ($attachment_ids as $attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            continue;
        }
        if ($attachment->post_type !== 'attachment') {
            $result['errors'][] = sprintf(__('Skipped post %d during undo because it is not an attachment.', 'll-tools-text-domain'), $attachment_id);
            continue;
        }
        $deleted = wp_delete_attachment($attachment_id, true);
        if (!$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete attachment %d during undo.', 'll-tools-text-domain'), $attachment_id);
            continue;
        }
        $result['stats']['attachments_deleted']++;
    }

    foreach ($audio_paths as $audio_path) {
        if (!ll_tools_import_delete_audio_file_if_safe($audio_path)) {
            $result['errors'][] = sprintf(__('Skipped deleting audio file "%s" during undo because the path was outside uploads or invalid.', 'll-tools-text-domain'), $audio_path);
            continue;
        }
        $result['stats']['audio_files_deleted']++;
    }

    foreach ($category_ids as $term_id) {
        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $deleted = wp_delete_term($term_id, 'word-category');
        if (is_wp_error($deleted) || !$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete category term %d during undo.', 'll-tools-text-domain'), $term_id);
            continue;
        }
        $result['stats']['categories_deleted']++;
    }

    foreach ($wordset_ids as $term_id) {
        $term = get_term($term_id, 'wordset');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $deleted = wp_delete_term($term_id, 'wordset');
        if (is_wp_error($deleted) || !$deleted) {
            $result['errors'][] = sprintf(__('Failed to delete word set term %d during undo.', 'll-tools-text-domain'), $term_id);
            continue;
        }
        $result['stats']['wordsets_deleted']++;
    }

    if (function_exists('ll_tools_rebuild_specific_wrong_answer_owner_map')) {
        ll_tools_rebuild_specific_wrong_answer_owner_map();
    }
    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
    }

    if (!empty($result['errors'])) {
        $result['ok'] = false;
        $result['message'] = __('Undo finished with some errors.', 'll-tools-text-domain');
    }

    return $result;
}

function ll_tools_handle_undo_import() {
    if (!ll_tools_current_user_can_export_import()) {
        wp_die(__('You do not have permission to undo LL Tools imports.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_undo_import', 'll_tools_undo_import_nonce');

    $entry_id = isset($_POST['ll_import_history_id']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_import_history_id'])) : '';
    $history = ll_tools_import_read_history();
    $entry_index = -1;
    foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ((string) ($entry['id'] ?? '') === $entry_id) {
            $entry_index = (int) $index;
            break;
        }
    }

    if ($entry_index < 0) {
        ll_tools_store_import_result_and_redirect([
            'ok' => false,
            'message' => __('Undo failed: import record not found.', 'll-tools-text-domain'),
            'errors' => [],
            'stats' => [],
        ]);
    }

    $entry = is_array($history[$entry_index]) ? $history[$entry_index] : [];
    $undone_at = isset($entry['undone_at']) ? (int) $entry['undone_at'] : 0;
    $undo = isset($entry['undo']) && is_array($entry['undo']) ? $entry['undo'] : ll_tools_import_default_undo_payload();
    if ($undone_at > 0) {
        ll_tools_store_import_result_and_redirect([
            'ok' => false,
            'message' => __('Undo skipped: this import was already undone.', 'll-tools-text-domain'),
            'errors' => [],
            'stats' => [],
        ]);
    }
    if (!ll_tools_import_has_undo_targets($undo)) {
        ll_tools_store_import_result_and_redirect([
            'ok' => false,
            'message' => __('Undo is unavailable for this import record.', 'll-tools-text-domain'),
            'errors' => [],
            'stats' => [],
        ]);
    }

    $undo_result = ll_tools_undo_import_entry($entry);
    $history[$entry_index]['undone_at'] = time();
    $history[$entry_index]['undo_result'] = $undo_result;
    ll_tools_import_write_history($history);

    ll_tools_store_import_result_and_redirect($undo_result);
}

/**
 * Get the server-side import directory path.
 *
 * @return string
 */
function ll_tools_get_import_dir() {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'll-tools-imports';
}

/**
 * Ensure the import directory exists.
 *
 * @param string $import_dir
 * @return bool
 */
function ll_tools_ensure_import_dir($import_dir) {
    if (!function_exists('wp_mkdir_p')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    return wp_mkdir_p($import_dir);
}

/**
 * List zip files available for server-side import.
 *
 * @param string $import_dir
 * @return array
 */
function ll_tools_list_import_zips($import_dir) {
    $files = [];
    if (!is_dir($import_dir)) {
        return $files;
    }

    try {
        foreach (new DirectoryIterator($import_dir) as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
                $files[] = $file->getFilename();
            }
        }
    } catch (Exception $e) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files;
}

/**
 * Resolve a selected server-side zip file to an absolute path.
 *
 * @param string $filename
 * @param string $import_dir
 * @return string|WP_Error
 */
function ll_tools_get_existing_import_zip_path($filename, $import_dir) {
    $filename = sanitize_file_name($filename);
    if ($filename === '') {
        return new WP_Error('ll_tools_import_missing', __('Import failed: no server zip selected.', 'll-tools-text-domain'));
    }
    if (preg_match('/[\\\\\\/]/', $filename) || strpos($filename, '..') !== false) {
        return new WP_Error('ll_tools_import_invalid', __('Import failed: invalid server zip name.', 'll-tools-text-domain'));
    }
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        return new WP_Error('ll_tools_import_invalid', __('Import failed: selected file is not a zip.', 'll-tools-text-domain'));
    }

    $candidate = trailingslashit($import_dir) . $filename;
    if (!is_file($candidate)) {
        return new WP_Error('ll_tools_import_missing', __('Import failed: selected server zip was not found.', 'll-tools-text-domain'));
    }

    $import_dir_real = realpath($import_dir);
    $candidate_real = realpath($candidate);
    if ($import_dir_real && $candidate_real) {
        $import_dir_real = trailingslashit(wp_normalize_path($import_dir_real));
        $candidate_real = wp_normalize_path($candidate_real);
        if (strpos($candidate_real, $import_dir_real) !== 0) {
            return new WP_Error('ll_tools_import_invalid', __('Import failed: selected zip is outside the import directory.', 'll-tools-text-domain'));
        }
        return $candidate_real;
    }

    return $candidate;
}

/**
 * Parse optional import controls from the submitted form.
 *
 * @param array $request
 * @return array
 */
function ll_tools_parse_import_options(array $request): array {
    $options = [];

    if (array_key_exists('ll_import_wordset_mode', $request)) {
        $mode = 'create_from_export';
        $candidate = sanitize_key(wp_unslash((string) $request['ll_import_wordset_mode']));
        if (in_array($candidate, ['create_from_export', 'assign_existing'], true)) {
            $mode = $candidate;
        }
        $options['wordset_mode'] = $mode;
    }

    if (array_key_exists('ll_import_target_wordset', $request)) {
        $options['target_wordset_id'] = (int) wp_unslash((string) $request['ll_import_target_wordset']);
    }

    if (!empty($request['ll_import_wordset_names']) && is_array($request['ll_import_wordset_names'])) {
        $wordset_name_overrides = [];
        foreach ($request['ll_import_wordset_names'] as $slug_raw => $name_raw) {
            $slug = sanitize_title(wp_unslash((string) $slug_raw));
            if ($slug === '') {
                continue;
            }
            $name = sanitize_text_field(wp_unslash((string) $name_raw));
            if ($name !== '') {
                $wordset_name_overrides[$slug] = $name;
            }
        }
        $options['wordset_name_overrides'] = $wordset_name_overrides;
    }

    return $options;
}

/**
 * Build a compact category scope descriptor for export metadata.
 *
 * @param array $root_category_ids
 * @return mixed
 */
function ll_tools_export_build_category_scope_descriptor(array $root_category_ids) {
    $root_category_ids = ll_tools_import_normalize_id_list($root_category_ids);
    if (empty($root_category_ids)) {
        return 'all';
    }

    if (count($root_category_ids) === 1) {
        return (int) $root_category_ids[0];
    }

    return [
        'mode' => 'selected_roots',
        'root_term_ids' => $root_category_ids,
    ];
}

/**
 * Build the payload and attachment list for export.
 *
 * @param int|array $root_category_ids Category root(s) to scope to (empty/0 = all).
 * @param array $options
 * @return array|WP_Error
 */
function ll_tools_build_export_payload($root_category_ids = 0, array $options = []) {
    $root_category_ids = ll_tools_export_normalize_category_root_ids($root_category_ids);
    $has_scoped_roots = !empty($root_category_ids);
    $include_full_bundle = !empty($options['include_full_bundle']);
    $full_wordset_id = isset($options['full_wordset_id']) ? (int) $options['full_wordset_id'] : 0;
    $full_wordset = null;
    if ($include_full_bundle) {
        if ($full_wordset_id <= 0) {
            return new WP_Error('ll_tools_export_missing_wordset', __('Select a word set when exporting a full category bundle.', 'll-tools-text-domain'));
        }
        $full_wordset = get_term($full_wordset_id, 'wordset');
        if (!$full_wordset || is_wp_error($full_wordset)) {
            return new WP_Error('ll_tools_export_invalid_wordset', __('The selected word set for full export is invalid.', 'll-tools-text-domain'));
        }
    }
    $terms = ll_tools_get_export_terms($root_category_ids);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $term_by_id = [];
    foreach ($terms as $term) {
        $term_by_id[$term->term_id] = $term;
    }

    $categories = [];
    foreach ($terms as $term) {
        $categories[] = [
            'slug'        => $term->slug,
            'name'        => $term->name,
            'description' => $term->description,
            'parent_slug' => $term->parent && isset($term_by_id[$term->parent]) ? $term_by_id[$term->parent]->slug : '',
            'meta'        => ll_tools_prepare_meta_for_export(get_term_meta($term->term_id)),
        ];
    }

    $attachment_tracker = [
        'attachment_count' => 0,
        'attachment_bytes' => 0,
        'hard_limit_bytes' => ll_tools_export_get_hard_limit_bytes(),
        'hard_limit_files' => ll_tools_export_get_hard_limit_files(),
    ];
    $attachments = [];

    $allowed_category_slugs = array_values(array_map(static function ($term) {
        return sanitize_title($term->slug);
    }, $terms));
    $allowed_category_lookup = array_fill_keys($allowed_category_slugs, true);

    $term_ids = array_map('intval', array_keys($term_by_id));
    $query_args = [
        'post_type'      => 'word_images',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ($has_scoped_roots && !empty($term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy'         => 'word-category',
            'field'            => 'term_id',
            'terms'            => $term_ids,
            'include_children' => true,
        ]];
    }

    $posts = get_posts($query_args);
    $word_images = [];

    foreach ($posts as $post) {
        $meta = ll_tools_prepare_meta_for_export(get_post_meta($post->ID));
        $categories_for_post = ll_tools_export_get_scoped_category_slugs($post->ID, $allowed_category_lookup);
        $featured_image = ll_tools_export_collect_post_featured_image($post->ID, 'media', $attachments, $attachment_tracker);
        if (is_wp_error($featured_image)) {
            return $featured_image;
        }

        $word_images[] = [
            'slug'           => $post->post_name,
            'title'          => $post->post_title,
            'status'         => $post->post_status,
            'meta'           => $meta,
            'categories'     => $categories_for_post,
            'featured_image' => $featured_image,
        ];
    }

    $wordsets = [];
    $words = [];
    if ($include_full_bundle) {
        $full_payload = ll_tools_export_collect_full_words_payload(
            $term_ids,
            $allowed_category_lookup,
            $attachments,
            $attachment_tracker,
            $root_category_ids,
            $full_wordset_id
        );
        if (is_wp_error($full_payload)) {
            return $full_payload;
        }
        $wordsets = $full_payload['wordsets'];
        $words = $full_payload['words'];
    }

    return [
        'data' => [
            'version'        => 2,
            'bundle_type'    => $include_full_bundle ? 'category_full' : 'images',
            'exported_at'    => current_time('mysql', true),
            'site'           => home_url(),
            'category_scope' => ll_tools_export_build_category_scope_descriptor($root_category_ids),
            'full_wordset'   => $include_full_bundle && $full_wordset ? [
                'id'   => (int) $full_wordset->term_id,
                'slug' => (string) $full_wordset->slug,
                'name' => (string) $full_wordset->name,
            ] : null,
            'categories'     => $categories,
            'word_images'    => $word_images,
            'wordsets'       => $wordsets,
            'words'          => $words,
            'media_estimate' => [
                'attachment_count' => (int) $attachment_tracker['attachment_count'],
                'attachment_bytes' => (int) $attachment_tracker['attachment_bytes'],
            ],
        ],
        'attachments' => array_values($attachments),
        'stats' => [
            'attachment_count' => (int) $attachment_tracker['attachment_count'],
            'attachment_bytes' => (int) $attachment_tracker['attachment_bytes'],
        ],
    ];
}

/**
 * Collect scoped category slugs for a post.
 *
 * @param int $post_id
 * @param array $allowed_category_lookup
 * @return array
 */
function ll_tools_export_get_scoped_category_slugs($post_id, array $allowed_category_lookup = []): array {
    $slugs = wp_get_object_terms((int) $post_id, 'word-category', ['fields' => 'slugs']);
    if (is_wp_error($slugs) || empty($slugs)) {
        return [];
    }

    $clean = [];
    foreach ((array) $slugs as $slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            continue;
        }
        if (!empty($allowed_category_lookup) && !isset($allowed_category_lookup[$slug])) {
            continue;
        }
        $clean[] = $slug;
    }

    return array_values(array_unique($clean));
}

/**
 * Add a file to export attachments while enforcing hard limits.
 *
 * @param string $file_path
 * @param string $zip_path
 * @param array $attachments
 * @param array $tracker
 * @return true|WP_Error
 */
function ll_tools_export_track_attachment_file($file_path, $zip_path, array &$attachments, array &$tracker) {
    $file_path = wp_normalize_path((string) $file_path);
    $zip_path = ltrim((string) $zip_path, '/');

    if ($file_path === '' || $zip_path === '' || !is_file($file_path)) {
        return true;
    }

    if (isset($attachments[$zip_path])) {
        return true;
    }

    $size = @filesize($file_path);
    if ($size === false || $size < 0) {
        $size = 0;
    }
    $size = (int) $size;

    $next_count = (int) $tracker['attachment_count'] + 1;
    $next_bytes = (int) $tracker['attachment_bytes'] + $size;
    $hard_limit_files = isset($tracker['hard_limit_files']) ? (int) $tracker['hard_limit_files'] : 0;
    $hard_limit_bytes = isset($tracker['hard_limit_bytes']) ? (int) $tracker['hard_limit_bytes'] : 0;

    if ($hard_limit_files > 0 && $next_count > $hard_limit_files) {
        return new WP_Error(
            'll_tools_export_too_many_files',
            sprintf(
                /* translators: 1: current media file count, 2: max allowed */
                __('Export stopped: media file count (%1$d) exceeds the hard limit (%2$d).', 'll-tools-text-domain'),
                $next_count,
                $hard_limit_files
            )
        );
    }

    if ($hard_limit_bytes > 0 && $next_bytes > $hard_limit_bytes) {
        return new WP_Error(
            'll_tools_export_too_large',
            sprintf(
                /* translators: 1: estimated media size, 2: max allowed size */
                __('Export stopped: estimated media size (%1$s) exceeds the hard limit (%2$s).', 'll-tools-text-domain'),
                size_format($next_bytes),
                size_format($hard_limit_bytes)
            )
        );
    }

    $attachments[$zip_path] = [
        'path'     => $file_path,
        'zip_path' => $zip_path,
    ];
    $tracker['attachment_count'] = $next_count;
    $tracker['attachment_bytes'] = $next_bytes;

    return true;
}

/**
 * Export featured image payload for a post and enqueue image file in the zip.
 *
 * @param int $post_id
 * @param string $zip_dir
 * @param array $attachments
 * @param array $tracker
 * @return array|null|WP_Error
 */
function ll_tools_export_collect_post_featured_image($post_id, $zip_dir, array &$attachments, array &$tracker) {
    $thumb_id = (int) get_post_thumbnail_id((int) $post_id);
    if ($thumb_id <= 0) {
        return null;
    }

    $payload = [
        'mime_type' => (string) get_post_mime_type($thumb_id),
        'alt'       => (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
        'title'     => (string) get_the_title($thumb_id),
    ];

    $metadata = wp_get_attachment_metadata($thumb_id);
    if (is_array($metadata)) {
        $width = isset($metadata['width']) ? (int) $metadata['width'] : 0;
        $height = isset($metadata['height']) ? (int) $metadata['height'] : 0;
        if ($width > 0) {
            $payload['width'] = $width;
        }
        if ($height > 0) {
            $payload['height'] = $height;
        }
    }

    $file_path = get_attached_file($thumb_id);
    if ($file_path && is_file($file_path)) {
        $zip_rel = trim((string) $zip_dir, '/') . '/' . $thumb_id . '-' . basename($file_path);
        $tracked = ll_tools_export_track_attachment_file($file_path, $zip_rel, $attachments, $tracker);
        if (is_wp_error($tracked)) {
            return $tracked;
        }

        $payload['file'] = $zip_rel;
        return $payload;
    }

    $source_url = wp_get_attachment_url($thumb_id);
    if (is_string($source_url)) {
        $source_url = trim($source_url);
    } else {
        $source_url = '';
    }
    if ($source_url !== '' && preg_match('#^https?://#i', $source_url)) {
        $payload['source_url'] = $source_url;
        return $payload;
    }

    return null;
}

/**
 * Collect wordset terms, words, and word_audio entries for full bundle mode.
 *
 * @param array $category_term_ids
 * @param array $allowed_category_lookup
 * @param array $attachments
 * @param array $tracker
 * @param array $root_category_ids
 * @param int $full_wordset_id
 * @return array|WP_Error
 */
function ll_tools_export_collect_full_words_payload(array $category_term_ids, array $allowed_category_lookup, array &$attachments, array &$tracker, array $root_category_ids = [], int $full_wordset_id = 0) {
    $root_category_ids = ll_tools_import_normalize_id_list($root_category_ids);
    $has_scoped_roots = !empty($root_category_ids);
    $full_wordset_id = (int) $full_wordset_id;
    if ($full_wordset_id <= 0) {
        return new WP_Error('ll_tools_export_missing_wordset', __('Select a word set when exporting a full category bundle.', 'll-tools-text-domain'));
    }

    $full_wordset_term = get_term($full_wordset_id, 'wordset');
    if (!$full_wordset_term || is_wp_error($full_wordset_term)) {
        return new WP_Error('ll_tools_export_invalid_wordset', __('The selected word set for full export is invalid.', 'll-tools-text-domain'));
    }

    $query_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    $tax_query = [[
        'taxonomy' => 'wordset',
        'field'    => 'term_id',
        'terms'    => [$full_wordset_id],
    ]];
    if ($has_scoped_roots && !empty($category_term_ids)) {
        $tax_query[] = [
            'taxonomy'         => 'word-category',
            'field'            => 'term_id',
            'terms'            => array_values(array_unique(array_map('intval', $category_term_ids))),
            'include_children' => true,
        ];
    }
    if (count($tax_query) > 1) {
        $tax_query['relation'] = 'AND';
    }
    $query_args['tax_query'] = $tax_query;

    $words = [];
    $used_wordset_ids = [$full_wordset_id => true];
    $word_posts = get_posts($query_args);

    foreach ($word_posts as $word_post) {
        $categories_for_word = ll_tools_export_get_scoped_category_slugs($word_post->ID, $allowed_category_lookup);
        if ($has_scoped_roots && empty($categories_for_word)) {
            continue;
        }

        $word_meta = ll_tools_prepare_meta_for_export(get_post_meta($word_post->ID), ['_ll_autopicked_image_id']);
        $word_featured_image = ll_tools_export_collect_post_featured_image($word_post->ID, 'media/words', $attachments, $tracker);
        if (is_wp_error($word_featured_image)) {
            return $word_featured_image;
        }

        $audio_entries = ll_tools_export_collect_word_audio_payload($word_post->ID, $attachments, $tracker);
        if (is_wp_error($audio_entries)) {
            return $audio_entries;
        }

        $words[] = [
            'origin_id'       => (int) $word_post->ID,
            'slug'            => (string) $word_post->post_name,
            'title'           => (string) $word_post->post_title,
            'content'         => (string) $word_post->post_content,
            'excerpt'         => (string) $word_post->post_excerpt,
            'status'          => (string) $word_post->post_status,
            'meta'            => $word_meta,
            'categories'      => $categories_for_word,
            'wordsets'        => [(string) $full_wordset_term->slug],
            'linked_word_image_slug' => ll_tools_export_get_linked_word_image_slug((int) $word_post->ID),
            'languages'       => ll_tools_export_collect_post_term_slugs($word_post->ID, 'language'),
            'parts_of_speech' => ll_tools_export_collect_post_term_slugs($word_post->ID, 'part_of_speech'),
            'featured_image'  => $word_featured_image,
            'audio_entries'   => $audio_entries,
        ];
    }

    $wordsets = ll_tools_export_collect_wordsets(array_keys($used_wordset_ids));

    return [
        'wordsets' => $wordsets,
        'words'    => $words,
    ];
}

/**
 * Resolve linked word_images slug for a word via _ll_autopicked_image_id.
 *
 * @param int $word_id
 * @return string
 */
function ll_tools_export_get_linked_word_image_slug(int $word_id): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return '';
    }

    $word_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($word_image_id <= 0) {
        return '';
    }

    $word_image = get_post($word_image_id);
    if (!$word_image || $word_image->post_type !== 'word_images') {
        return '';
    }

    return sanitize_title((string) $word_image->post_name);
}

/**
 * Collect wordset term payload for export.
 *
 * @param array $wordset_ids
 * @return array
 */
function ll_tools_export_collect_wordsets(array $wordset_ids): array {
    $wordset_ids = array_values(array_unique(array_filter(array_map('intval', $wordset_ids), static function ($id) {
        return $id > 0;
    })));
    if (empty($wordset_ids)) {
        return [];
    }

    sort($wordset_ids, SORT_NUMERIC);
    $wordsets = [];
    foreach ($wordset_ids as $wordset_id) {
        $term = get_term($wordset_id, 'wordset');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $wordsets[] = [
            'slug'        => (string) $term->slug,
            'name'        => (string) $term->name,
            'description' => (string) $term->description,
            'meta'        => ll_tools_prepare_meta_for_export(get_term_meta($term->term_id), ['manager_user_id']),
        ];
    }

    return $wordsets;
}

/**
 * Collect child word_audio posts + audio file references for a word.
 *
 * @param int $word_id
 * @param array $attachments
 * @param array $tracker
 * @return array|WP_Error
 */
function ll_tools_export_collect_word_audio_payload($word_id, array &$attachments, array &$tracker) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'post_parent'    => $word_id,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $payload = [];
    foreach ($audio_posts as $audio_post) {
        $audio_file = null;
        $audio_path = (string) get_post_meta($audio_post->ID, 'audio_file_path', true);
        if ($audio_path !== '') {
            $resolved = ll_tools_export_resolve_audio_source_path($audio_path);
            if ($resolved !== '' && is_file($resolved)) {
                $zip_rel = 'audio/' . (int) $audio_post->ID . '-' . basename($resolved);
                $tracked = ll_tools_export_track_attachment_file($resolved, $zip_rel, $attachments, $tracker);
                if (is_wp_error($tracked)) {
                    return $tracked;
                }
                $filetype = wp_check_filetype(basename($resolved), null);
                $audio_file = [
                    'file'      => $zip_rel,
                    'mime_type' => isset($filetype['type']) ? (string) $filetype['type'] : '',
                    'title'     => (string) $audio_post->post_title,
                ];
            }
        }

        $payload[] = [
            'origin_id'        => (int) $audio_post->ID,
            'slug'             => (string) $audio_post->post_name,
            'title'            => (string) $audio_post->post_title,
            'status'           => (string) $audio_post->post_status,
            'meta'             => ll_tools_prepare_meta_for_export(get_post_meta($audio_post->ID), ['audio_file_path']),
            'recording_types'  => ll_tools_export_collect_post_term_slugs($audio_post->ID, 'recording_type'),
            'audio_file'       => $audio_file,
        ];
    }

    return $payload;
}

/**
 * Resolve an exported audio_file_path value to a local absolute file path.
 *
 * @param string $audio_path
 * @return string
 */
function ll_tools_export_resolve_audio_source_path($audio_path): string {
    $audio_path = trim((string) $audio_path);
    if ($audio_path === '') {
        return '';
    }

    $candidate = wp_normalize_path($audio_path);
    if ($candidate !== '' && is_file($candidate)) {
        return $candidate;
    }

    $upload_dir = wp_upload_dir();
    $base_url = isset($upload_dir['baseurl']) ? (string) $upload_dir['baseurl'] : '';
    $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';

    if (preg_match('#^https?://#i', $audio_path)) {
        if ($base_url !== '' && strpos($audio_path, $base_url) === 0) {
            $relative = ltrim(substr($audio_path, strlen($base_url)), '/');
            $candidate = wp_normalize_path(trailingslashit($base_dir) . $relative);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        $url_path = wp_parse_url($audio_path, PHP_URL_PATH);
        if (!is_string($url_path) || $url_path === '') {
            return '';
        }
        $audio_path = $url_path;
    }

    $audio_path = wp_normalize_path($audio_path);
    if ($audio_path === '') {
        return '';
    }

    if (is_file($audio_path)) {
        return $audio_path;
    }

    if ($audio_path[0] === '/') {
        $candidate = wp_normalize_path(untrailingslashit(ABSPATH) . $audio_path);
    } else {
        $candidate = wp_normalize_path(untrailingslashit(ABSPATH) . '/' . ltrim($audio_path, '/'));
    }

    return is_file($candidate) ? $candidate : '';
}

/**
 * Collect sanitized post term slugs for export.
 *
 * @param int $post_id
 * @param string $taxonomy
 * @return array
 */
function ll_tools_export_collect_post_term_slugs($post_id, $taxonomy): array {
    $slugs = wp_get_post_terms((int) $post_id, (string) $taxonomy, ['fields' => 'slugs']);
    if (is_wp_error($slugs) || empty($slugs)) {
        return [];
    }

    $clean = [];
    foreach ((array) $slugs as $slug) {
        $slug = sanitize_title($slug);
        if ($slug !== '') {
            $clean[] = $slug;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Prepare meta for export by removing transient/editor keys and unserializing values.
 *
 * @param array $raw_meta Meta from get_post_meta or get_term_meta.
 * @param array $extra_skip_keys
 * @return array
 */
function ll_tools_prepare_meta_for_export($raw_meta, array $extra_skip_keys = []) {
    $filtered = [];
    $skip_keys = array_values(array_unique(array_merge(
        ['_edit_lock', '_edit_last', '_thumbnail_id'],
        array_map('strval', $extra_skip_keys)
    )));

    foreach ((array) $raw_meta as $key => $values) {
        if (in_array($key, $skip_keys, true)) {
            continue;
        }
        $clean_values = [];
        foreach ((array) $values as $val) {
            $clean_values[] = maybe_unserialize($val);
        }
        $filtered[$key] = $clean_values;
    }

    return $filtered;
}

/**
 * Get the set of word-category terms to export, optionally scoped to one or more root terms.
 *
 * @param int|array $root_category_ids
 * @return array|WP_Error
 */
function ll_tools_get_export_terms($root_category_ids = 0) {
    $root_category_ids = ll_tools_export_normalize_category_root_ids($root_category_ids);
    $base_args = [
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];

    if (empty($root_category_ids)) {
        $terms = get_terms($base_args);
        if (is_wp_error($terms)) {
            return $terms;
        }

        return array_values(array_filter((array) $terms, static function ($term): bool {
            return isset($term->term_id);
        }));
    }

    $deduped = [];
    foreach ($root_category_ids as $root_category_id) {
        $root_category_id = (int) $root_category_id;
        if ($root_category_id <= 0) {
            continue;
        }

        $root = get_term($root_category_id, 'word-category');
        if (!$root || is_wp_error($root)) {
            return new WP_Error(
                'll_tools_export_invalid_category',
                sprintf(
                    /* translators: %d category term ID */
                    __('The selected category (ID %d) is invalid.', 'll-tools-text-domain'),
                    $root_category_id
                )
            );
        }

        $args = $base_args;
        $args['child_of'] = $root_category_id;
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return $terms;
        }

        $terms[] = $root;
        foreach ($terms as $term) {
            if (isset($term->term_id)) {
                $deduped[(int) $term->term_id] = $term;
            }
        }
    }

    if (empty($deduped)) {
        return new WP_Error('ll_tools_export_no_categories', __('Select at least one category to export.', 'll-tools-text-domain'));
    }

    $sorted = array_values($deduped);
    usort($sorted, static function ($a, $b): int {
        $name_cmp = strcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? ''));
        if ($name_cmp !== 0) {
            return $name_cmp;
        }
        return ((int) ($a->term_id ?? 0)) <=> ((int) ($b->term_id ?? 0));
    });

    return $sorted;
}

function ll_tools_export_get_default_gloss_languages(): string {
    $codes = ll_tools_export_get_default_gloss_language_codes();
    return !empty($codes) ? implode(', ', $codes) : '';
}

function ll_tools_export_get_default_gloss_language_codes(): array {
    $raw = (string) get_option('ll_translation_language', '');
    if ($raw === '') {
        return ['en'];
    }

    $code = '';
    if (function_exists('ll_tools_resolve_language_code_from_label')) {
        $code = (string) ll_tools_resolve_language_code_from_label($raw, 'lower');
    }
    if ($code === '') {
        $code = strtolower(preg_replace('/[^a-z0-9-]/i', '', $raw));
    }

    return $code !== '' ? [$code] : [];
}

function ll_tools_export_parse_gloss_languages($raw): array {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\\s,]+/', $raw);
    $codes = [];
    foreach ((array) $parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }

        $code = '';
        if (function_exists('ll_tools_resolve_language_code_from_label')) {
            $code = (string) ll_tools_resolve_language_code_from_label($part, 'lower');
        }
        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-z0-9-]/i', '', $part));
        }

        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

function ll_tools_export_parse_recording_sources($raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $sources = [];
    foreach ($raw as $slug => $values) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            continue;
        }
        $values = is_array($values) ? $values : [$values];
        $clean = [];
        foreach ($values as $value) {
            $value = sanitize_text_field(wp_unslash($value));
            if ($value === 'text' || $value === 'translation') {
                $clean[] = $value;
            }
        }
        $clean = array_values(array_unique($clean));
        if (!empty($clean)) {
            $sources[$slug] = $clean;
        }
    }

    return $sources;
}

function ll_tools_export_get_wordset_word_ids(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = get_posts([
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ]);

    return array_values(array_unique(array_map('intval', $word_ids)));
}

function ll_tools_export_collect_audio_entries(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_parent__in'=> $word_ids,
    ]);

    $by_word = [];
    foreach ($audio_posts as $audio_post) {
        $word_id = (int) $audio_post->post_parent;
        if ($word_id <= 0) {
            continue;
        }

        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        if (is_wp_error($recording_types) || empty($recording_types)) {
            continue;
        }

        $entry = [
            'recording_text'        => (string) get_post_meta($audio_post->ID, 'recording_text', true),
            'recording_translation' => (string) get_post_meta($audio_post->ID, 'recording_translation', true),
            'recording_ipa'         => (string) get_post_meta($audio_post->ID, 'recording_ipa', true),
        ];

        foreach ($recording_types as $recording_type) {
            $recording_type = sanitize_title($recording_type);
            if ($recording_type === '') {
                continue;
            }
            $by_word[$word_id][] = $entry + ['recording_type' => $recording_type];
        }
    }

    return $by_word;
}

function ll_tools_export_pick_word_ipa(array $recordings): string {
    if (empty($recordings)) {
        return '';
    }

    $priority = ['isolation', 'question', 'introduction', 'sentence', 'in sentence'];
    foreach ($priority as $type) {
        foreach ($recordings as $recording) {
            if (($recording['recording_type'] ?? '') !== $type) {
                continue;
            }
            $ipa = trim((string) ($recording['recording_ipa'] ?? ''));
            if ($ipa !== '') {
                return $ipa;
            }
        }
    }

    foreach ($recordings as $recording) {
        $ipa = trim((string) ($recording['recording_ipa'] ?? ''));
        if ($ipa !== '') {
            return $ipa;
        }
    }

    return '';
}

function ll_tools_export_get_word_translation(int $word_id): string {
    $translation = (string) get_post_meta($word_id, 'word_translation', true);
    if ($translation === '') {
        $translation = (string) get_post_meta($word_id, 'word_english_meaning', true);
    }
    return trim($translation);
}

function ll_tools_export_build_csv_row(string $lexeme, string $phonetic, string $gloss, int $gloss_count, string $dialect, string $source): array {
    $row = [$lexeme, $phonetic];
    for ($i = 0; $i < $gloss_count; $i++) {
        $row[] = $gloss;
    }
    $row[] = $dialect;
    $row[] = $source;
    $row[] = '';
    return $row;
}

function ll_tools_import_default_stats(): array {
    return [
        'categories_created' => 0,
        'categories_updated' => 0,
        'wordsets_created' => 0,
        'wordsets_updated' => 0,
        'word_images_created' => 0,
        'word_images_updated' => 0,
        'words_created' => 0,
        'words_updated' => 0,
        'word_audio_created' => 0,
        'word_audio_updated' => 0,
        'attachments_imported' => 0,
        'audio_files_imported' => 0,
    ];
}

/**
 * Process the uploaded zip: extract, parse JSON, and import content.
 *
 * @param string $zip_path
 * @param array $options
 * @return array Result payload for notices.
 */
function ll_tools_process_import_zip($zip_path, array $options = []) {
    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'warnings' => [],
        'stats'   => ll_tools_import_default_stats(),
        'undo'    => ll_tools_import_default_undo_payload(),
    ];

    if (!file_exists($zip_path)) {
        $result['message'] = __('Import failed: uploaded file is missing.', 'll-tools-text-domain');
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        $result['message'] = __('Import failed: could not open zip file.', 'll-tools-text-domain');
        return $result;
    }

    $upload_dir = wp_upload_dir();
    $extract_dir = trailingslashit($upload_dir['basedir']) . 'll-tools-import-' . wp_generate_password(8, false, false);
    if (!wp_mkdir_p($extract_dir)) {
        $zip->close();
        $result['message'] = __('Import failed: could not create temporary extraction directory.', 'll-tools-text-domain');
        return $result;
    }

    $extract_result = ll_tools_extract_zip_safely($zip, $extract_dir);
    $zip->close();
    if (is_wp_error($extract_result)) {
        $result['message'] = $extract_result->get_error_message();
        ll_tools_rrmdir($extract_dir);
        return $result;
    }

    $payload = ll_tools_import_read_payload_from_extract_dir($extract_dir);
    if (is_wp_error($payload)) {
        $result['message'] = $payload->get_error_message();
        ll_tools_rrmdir($extract_dir);
        return $result;
    }

    @set_time_limit(0);
    $imported = ll_tools_import_from_payload($payload, $extract_dir, $options);
    ll_tools_rrmdir($extract_dir);

    return $imported;
}

/**
 * Import categories, images, and optional full word/audio data from a payload.
 *
 * @param array $payload
 * @param string $extract_dir
 * @param array $options
 * @return array
 */
function ll_tools_import_from_payload(array $payload, $extract_dir, array $options = []) {
    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'warnings' => [],
        'stats'   => ll_tools_import_default_stats(),
        'undo'    => ll_tools_import_default_undo_payload(),
    ];

    if (!array_key_exists('categories', $payload) || !array_key_exists('word_images', $payload)) {
        $result['message'] = __('Import failed: payload missing categories or word images.', 'll-tools-text-domain');
        return $result;
    }

    if (!empty($payload['import_warnings']) && is_array($payload['import_warnings'])) {
        $result['warnings'] = array_values(array_filter(array_map('strval', $payload['import_warnings']), static function (string $warning): bool {
            return trim($warning) !== '';
        }));
    }

    $parser_summary = isset($payload['import_parser_summary']) && is_array($payload['import_parser_summary'])
        ? $payload['import_parser_summary']
        : [];
    $csv_files_skipped = isset($parser_summary['csv_files_skipped']) ? (int) $parser_summary['csv_files_skipped'] : 0;
    if ($csv_files_skipped > 0) {
        $result['warnings'][] = sprintf(
            _n(
                'CSV parsing skipped %d file before import. Review the warnings below for details.',
                'CSV parsing skipped %d files before import. Review the warnings below for details.',
                $csv_files_skipped,
                'll-tools-text-domain'
            ),
            $csv_files_skipped
        );
    }

    $rows_skipped = isset($parser_summary['rows_skipped']) ? (int) $parser_summary['rows_skipped'] : 0;
    $rows_used = isset($parser_summary['rows_used']) ? (int) $parser_summary['rows_used'] : 0;
    $rows_nonempty = isset($parser_summary['rows_nonempty']) ? (int) $parser_summary['rows_nonempty'] : 0;
    if ($rows_skipped > 0) {
        if ($rows_nonempty > 0) {
            $result['warnings'][] = sprintf(
                __('CSV parsing used %1$d of %2$d non-empty rows before import (%3$d skipped).', 'll-tools-text-domain'),
                $rows_used,
                $rows_nonempty,
                $rows_skipped
            );
        } else {
            $result['warnings'][] = sprintf(
                _n(
                    'CSV parsing skipped %d row before import.',
                    'CSV parsing skipped %d rows before import.',
                    $rows_skipped,
                    'll-tools-text-domain'
                ),
                $rows_skipped
            );
        }
    }

    $wordset_mode = isset($options['wordset_mode']) ? sanitize_key((string) $options['wordset_mode']) : 'create_from_export';
    if (!in_array($wordset_mode, ['create_from_export', 'assign_existing'], true)) {
        $wordset_mode = 'create_from_export';
    }
    $target_wordset_id = isset($options['target_wordset_id']) ? (int) $options['target_wordset_id'] : 0;

    $has_full_content = !empty($payload['words']) || (isset($payload['bundle_type']) && $payload['bundle_type'] === 'category_full');
    if ($has_full_content && $wordset_mode === 'assign_existing') {
        $target_wordset = get_term($target_wordset_id, 'wordset');
        if (!$target_wordset || is_wp_error($target_wordset)) {
            $result['message'] = __('Import failed: select a valid existing word set for assignment.', 'll-tools-text-domain');
            return $result;
        }
    }

    $slug_to_term_id = [];

    // Create or find categories first (without parents).
    foreach ((array) $payload['categories'] as $cat) {
        $slug = isset($cat['slug']) ? sanitize_title($cat['slug']) : '';
        if ($slug === '') {
            continue;
        }
        $existing = get_term_by('slug', $slug, 'word-category');
        if ($existing && !is_wp_error($existing)) {
            $slug_to_term_id[$slug] = (int) $existing->term_id;
            $result['stats']['categories_updated']++;
            continue;
        }

        $insert = wp_insert_term(isset($cat['name']) ? (string) $cat['name'] : $slug, 'word-category', [
            'slug'        => $slug,
            'description' => isset($cat['description']) ? (string) $cat['description'] : '',
        ]);

        if (is_wp_error($insert)) {
            $result['errors'][] = sprintf(__('Category "%s" could not be created: %s', 'll-tools-text-domain'), $slug, $insert->get_error_message());
            continue;
        }

        $slug_to_term_id[$slug] = (int) $insert['term_id'];
        $result['stats']['categories_created']++;
        ll_tools_import_track_undo_id($result, 'category_term_ids', (int) $insert['term_id']);
    }

    // Apply parents now that all slugs are mapped.
    foreach ((array) $payload['categories'] as $cat) {
        if (empty($cat['parent_slug'])) {
            continue;
        }
        $child_slug = isset($cat['slug']) ? sanitize_title($cat['slug']) : '';
        $parent_slug = sanitize_title((string) $cat['parent_slug']);
        if (isset($slug_to_term_id[$child_slug], $slug_to_term_id[$parent_slug])) {
            wp_update_term($slug_to_term_id[$child_slug], 'word-category', [
                'parent' => $slug_to_term_id[$parent_slug],
            ]);
        }
    }

    // Apply term meta.
    foreach ((array) $payload['categories'] as $cat) {
        $slug = isset($cat['slug']) ? sanitize_title($cat['slug']) : '';
        if ($slug === '' || !isset($slug_to_term_id[$slug])) {
            continue;
        }
        ll_tools_import_replace_term_meta_values((int) $slug_to_term_id[$slug], isset($cat['meta']) && is_array($cat['meta']) ? $cat['meta'] : [], 'word-category');
    }

    // Import word images.
    $word_image_slug_to_id = [];
    foreach ((array) $payload['word_images'] as $item) {
        $slug = isset($item['slug']) ? sanitize_title($item['slug']) : '';
        if ($slug === '') {
            continue;
        }

        $existing = get_page_by_path($slug, OBJECT, 'word_images');
        $postarr = [
            'post_title'  => isset($item['title']) ? (string) $item['title'] : '',
            'post_status' => ll_tools_sanitize_import_post_status($item['status'] ?? 'publish', 'publish'),
            'post_type'   => 'word_images',
            'post_name'   => $slug,
        ];

        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $post_id = wp_update_post($postarr, true);
            if (is_wp_error($post_id)) {
                $result['errors'][] = sprintf(__('Failed to update word image "%s": %s', 'll-tools-text-domain'), $slug, $post_id->get_error_message());
                continue;
            }
            $result['stats']['word_images_updated']++;
            $is_new_word_image = false;
        } else {
            $postarr['post_author'] = get_current_user_id();
            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                $result['errors'][] = sprintf(__('Failed to create word image "%s": %s', 'll-tools-text-domain'), $slug, $post_id->get_error_message());
                continue;
            }
            $result['stats']['word_images_created']++;
            $is_new_word_image = true;
            ll_tools_import_track_undo_id($result, 'word_image_post_ids', (int) $post_id);
        }

        $term_ids = [];
        if (!empty($item['categories']) && is_array($item['categories'])) {
            foreach ($item['categories'] as $cat_slug) {
                $cat_slug = sanitize_title($cat_slug);
                if (isset($slug_to_term_id[$cat_slug])) {
                    $term_ids[] = (int) $slug_to_term_id[$cat_slug];
                }
            }
        }
        $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'word-category', false);
        }

        ll_tools_import_replace_post_meta_values((int) $post_id, isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [], 'word_images');
        ll_tools_import_apply_featured_image((int) $post_id, isset($item['featured_image']) ? (array) $item['featured_image'] : [], $extract_dir, $slug, $result, 'word_image', $is_new_word_image);
        $word_image_slug_to_id[$slug] = (int) $post_id;
    }

    if ($has_full_content) {
        $skip_audio_cb = static function ($skip) {
            return true;
        };
        add_filter('ll_tools_skip_audio_requirement', $skip_audio_cb, 9999, 4);
        try {
            ll_tools_import_full_bundle_payload(
                $payload,
                $extract_dir,
                $slug_to_term_id,
                [
                    'wordset_mode' => $wordset_mode,
                    'target_wordset_id' => $target_wordset_id,
                    'word_image_slug_to_id' => $word_image_slug_to_id,
                ],
                $result
            );
        } finally {
            remove_filter('ll_tools_skip_audio_requirement', $skip_audio_cb, 9999);
        }
    }

    $imported_category_ids = array_values(array_unique(array_filter(array_map('intval', array_values($slug_to_term_id)))));
    if (!empty($imported_category_ids)) {
        if (function_exists('ll_tools_handle_category_sync')) {
            foreach ($imported_category_ids as $category_id) {
                ll_tools_handle_category_sync((int) $category_id);
            }
        }
        if (function_exists('ll_tools_sync_vocab_lessons_for_category')) {
            foreach ($imported_category_ids as $category_id) {
                ll_tools_sync_vocab_lessons_for_category((int) $category_id);
            }
        }
    }

    $expected_categories = count((array) ($payload['categories'] ?? []));
    $imported_categories = (int) ($result['stats']['categories_created'] ?? 0) + (int) ($result['stats']['categories_updated'] ?? 0);
    if ($expected_categories > 0 && $imported_categories < $expected_categories) {
        $result['warnings'][] = sprintf(
            __('Only %1$d of %2$d categories from the import bundle were created or updated.', 'll-tools-text-domain'),
            $imported_categories,
            $expected_categories
        );
    }

    $expected_words = count((array) ($payload['words'] ?? []));
    $imported_words = (int) ($result['stats']['words_created'] ?? 0) + (int) ($result['stats']['words_updated'] ?? 0);
    if ($expected_words > 0 && $imported_words < $expected_words) {
        $result['warnings'][] = sprintf(
            __('Only %1$d of %2$d words from the import bundle were created or updated.', 'll-tools-text-domain'),
            $imported_words,
            $expected_words
        );
    }

    $result['warnings'] = array_values(array_filter(array_unique(array_map('strval', (array) $result['warnings'])), static function (string $warning): bool {
        return trim($warning) !== '';
    }));
    $result['ok'] = empty($result['errors']);
    if (!$result['ok']) {
        $result['message'] = __('Import finished with some errors.', 'll-tools-text-domain');
    } elseif (!empty($result['warnings'])) {
        $result['message'] = __('Import complete with warnings.', 'll-tools-text-domain');
    } else {
        $result['message'] = __('Import complete.', 'll-tools-text-domain');
    }

    return $result;
}

/**
 * Normalize to supported post statuses for imports.
 *
 * @param mixed $status
 * @param string $fallback
 * @return string
 */
function ll_tools_sanitize_import_post_status($status, $fallback = 'draft') {
    $allowed = ['publish', 'draft', 'pending', 'private', 'future'];
    $fallback = sanitize_key((string) $fallback);
    if (!in_array($fallback, $allowed, true)) {
        $fallback = 'draft';
    }

    $status = sanitize_key((string) $status);
    return in_array($status, $allowed, true) ? $status : $fallback;
}

/**
 * Replace all post meta values with imported values.
 *
 * @param int $post_id
 * @param array $meta
 * @return void
 */
function ll_tools_import_should_replace_post_meta_key(string $key, string $post_type = ''): bool {
    $key = trim($key);
    $post_type = sanitize_key($post_type);
    if ($key === '') {
        return false;
    }

    // Public custom meta keys are allowed by default.
    if ($key[0] !== '_') {
        return (bool) apply_filters('ll_tools_import_allow_post_meta_key', true, $key, $post_type);
    }

    $allowed_protected_prefixes = ['_ll_'];
    $allowed_protected_exact = [
        '_ll_similar_word_id',
        '_ll_autopicked_image_id',
        '_ll_needs_audio_processing',
    ];

    foreach ($allowed_protected_prefixes as $prefix) {
        if (strpos($key, $prefix) === 0) {
            return (bool) apply_filters('ll_tools_import_allow_post_meta_key', true, $key, $post_type);
        }
    }
    if (in_array($key, $allowed_protected_exact, true)) {
        return (bool) apply_filters('ll_tools_import_allow_post_meta_key', true, $key, $post_type);
    }

    $blocked_exact = [
        '_edit_lock',
        '_edit_last',
        '_thumbnail_id',
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_wp_attachment_backup_sizes',
        '_wp_old_slug',
        '_wp_desired_post_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_trash_meta_comments_status',
    ];
    if (in_array($key, $blocked_exact, true)) {
        return (bool) apply_filters('ll_tools_import_allow_post_meta_key', false, $key, $post_type);
    }

    $blocked_prefixes = [
        '_wp_',
        '_edit_',
        '_oembed_',
    ];
    foreach ($blocked_prefixes as $prefix) {
        if (strpos($key, $prefix) === 0) {
            return (bool) apply_filters('ll_tools_import_allow_post_meta_key', false, $key, $post_type);
        }
    }

    // Unknown protected keys are denied by default; allow via filter when needed.
    return (bool) apply_filters('ll_tools_import_allow_post_meta_key', false, $key, $post_type);
}

function ll_tools_import_should_replace_term_meta_key(string $key, string $taxonomy = ''): bool {
    $key = trim($key);
    $taxonomy = sanitize_key($taxonomy);
    if ($key === '') {
        return false;
    }

    if ($key[0] !== '_') {
        return (bool) apply_filters('ll_tools_import_allow_term_meta_key', true, $key, $taxonomy);
    }

    if (strpos($key, '_ll_') === 0) {
        return (bool) apply_filters('ll_tools_import_allow_term_meta_key', true, $key, $taxonomy);
    }

    if (strpos($key, '_wp_') === 0 || strpos($key, '_edit_') === 0) {
        return (bool) apply_filters('ll_tools_import_allow_term_meta_key', false, $key, $taxonomy);
    }

    return (bool) apply_filters('ll_tools_import_allow_term_meta_key', false, $key, $taxonomy);
}

function ll_tools_import_replace_post_meta_values(int $post_id, array $meta, string $post_type = ''): void {
    if ($post_id <= 0 || empty($meta)) {
        return;
    }

    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if (!ll_tools_import_should_replace_post_meta_key($key, $post_type)) {
            continue;
        }
        delete_post_meta($post_id, $key);
        foreach ((array) $values as $val) {
            add_post_meta($post_id, $key, maybe_unserialize($val));
        }
    }
}

/**
 * Replace all term meta values with imported values.
 *
 * @param int $term_id
 * @param array $meta
 * @return void
 */
function ll_tools_import_replace_term_meta_values(int $term_id, array $meta, string $taxonomy = ''): void {
    if ($term_id <= 0 || empty($meta)) {
        return;
    }

    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if (!ll_tools_import_should_replace_term_meta_key($key, $taxonomy)) {
            continue;
        }
        delete_term_meta($term_id, $key);
        foreach ((array) $values as $val) {
            add_term_meta($term_id, $key, maybe_unserialize($val));
        }
    }
}

/**
 * Import and apply featured image for a post from the extracted bundle.
 *
 * @param int $post_id
 * @param array $featured_image
 * @param string $extract_dir
 * @param string $item_slug
 * @param array $result
 * @param string $context
 * @param bool $track_for_undo
 * @return void
 */
function ll_tools_import_apply_featured_image(int $post_id, array $featured_image, $extract_dir, string $item_slug, array &$result, string $context = 'post', bool $track_for_undo = false): void {
    if ($post_id <= 0) {
        return;
    }

    $attachment_id = 0;
    $has_local_file = !empty($featured_image['file']);

    if ($has_local_file) {
        $rel = ltrim((string) $featured_image['file'], '/');
        $absolute = ll_tools_resolve_import_path($extract_dir, $rel);
        if ($absolute === '') {
            $result['errors'][] = sprintf(__('Skipped thumbnail for "%s" because the file path was invalid.', 'll-tools-text-domain'), $item_slug);
            return;
        }
        if (!file_exists($absolute)) {
            $result['errors'][] = sprintf(__('Image file for "%s" is missing from the zip.', 'll-tools-text-domain'), $item_slug);
            return;
        }

        $attachment_id = ll_tools_import_attachment_from_file($absolute, $featured_image, $post_id);
    } else {
        $source_url = ll_tools_import_normalize_external_image_source_url($featured_image['source_url'] ?? '');
        if ($source_url === '') {
            return;
        }
        $attachment_id = ll_tools_import_attachment_from_external_url($source_url, $featured_image, $post_id);
    }

    if (is_wp_error($attachment_id)) {
        $label = $context === 'word' ? __('word', 'll-tools-text-domain') : __('word image', 'll-tools-text-domain');
        $result['errors'][] = sprintf(__('Failed to import image for %1$s "%2$s": %3$s', 'll-tools-text-domain'), $label, $item_slug, $attachment_id->get_error_message());
        return;
    }

    set_post_thumbnail($post_id, $attachment_id);
    $result['stats']['attachments_imported']++;
    if ($track_for_undo) {
        ll_tools_import_track_undo_id($result, 'attachment_ids', (int) $attachment_id);
    }
}

/**
 * Normalize an imported remote image URL for placeholder-attachment imports.
 *
 * @param mixed $url
 * @return string
 */
function ll_tools_import_normalize_external_image_source_url($url): string {
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

/**
 * Resolve word image linkage for an imported word.
 *
 * @param array $word_item
 * @param array $word_image_slug_to_id
 * @return array{word_image_id:int, source:string}
 */
function ll_tools_import_resolve_word_image_link_for_word(array $word_item, array $word_image_slug_to_id): array {
    $explicit_slug = sanitize_title((string) ($word_item['linked_word_image_slug'] ?? ''));
    if ($explicit_slug !== '' && isset($word_image_slug_to_id[$explicit_slug])) {
        return [
            'word_image_id' => (int) $word_image_slug_to_id[$explicit_slug],
            'source' => 'explicit',
        ];
    }

    $word_slug = sanitize_title((string) ($word_item['slug'] ?? ''));
    if ($word_slug !== '' && isset($word_image_slug_to_id[$word_slug])) {
        return [
            'word_image_id' => (int) $word_image_slug_to_id[$word_slug],
            'source' => 'slug_fallback',
        ];
    }

    return [
        'word_image_id' => 0,
        'source' => '',
    ];
}

/**
 * Import full bundle content: wordsets, words, and word_audio posts.
 *
 * @param array $payload
 * @param string $extract_dir
 * @param array $slug_to_category_term_id
 * @param array $options
 * @param array $result
 * @return void
 */
function ll_tools_import_full_bundle_payload(array $payload, $extract_dir, array $slug_to_category_term_id, array $options, array &$result): void {
    $wordset_map = ll_tools_import_prepare_wordset_map(
        isset($payload['wordsets']) && is_array($payload['wordsets']) ? $payload['wordsets'] : [],
        $options,
        $result
    );
    $word_image_slug_to_id = isset($options['word_image_slug_to_id']) && is_array($options['word_image_slug_to_id'])
        ? $options['word_image_slug_to_id']
        : [];

    $origin_word_id_to_imported = [];
    $word_label_index_by_category = [];
    $word_label_index_global = [];
    $pending_specific_wrong_answers = [];
    foreach ((array) ($payload['words'] ?? []) as $item) {
        $slug = isset($item['slug']) ? sanitize_title($item['slug']) : '';
        $origin_id = isset($item['origin_id']) ? (int) $item['origin_id'] : 0;
        if ($slug === '') {
            $slug = $origin_id > 0 ? ('imported-word-' . $origin_id) : ('imported-word-' . wp_generate_password(8, false, false));
        }

        $existing = get_page_by_path($slug, OBJECT, 'words');
        $postarr = [
            'post_title'   => isset($item['title']) ? (string) $item['title'] : '',
            'post_content' => isset($item['content']) ? (string) $item['content'] : '',
            'post_excerpt' => isset($item['excerpt']) ? (string) $item['excerpt'] : '',
            'post_status'  => ll_tools_sanitize_import_post_status($item['status'] ?? 'draft', 'draft'),
            'post_type'    => 'words',
            'post_name'    => $slug,
        ];

        if ($existing) {
            $postarr['ID'] = (int) $existing->ID;
            $word_id = wp_update_post($postarr, true);
            if (is_wp_error($word_id)) {
                $result['errors'][] = sprintf(__('Failed to update word "%s": %s', 'll-tools-text-domain'), $slug, $word_id->get_error_message());
                continue;
            }
            $result['stats']['words_updated']++;
            $is_new_word = false;
        } else {
            $postarr['post_author'] = get_current_user_id();
            $word_id = wp_insert_post($postarr, true);
            if (is_wp_error($word_id)) {
                $result['errors'][] = sprintf(__('Failed to create word "%s": %s', 'll-tools-text-domain'), $slug, $word_id->get_error_message());
                continue;
            }
            $result['stats']['words_created']++;
            $is_new_word = true;
            ll_tools_import_track_undo_id($result, 'word_post_ids', (int) $word_id);
        }

        $word_id = (int) $word_id;
        if ($origin_id > 0) {
            $origin_word_id_to_imported[$origin_id] = $word_id;
        }

        $category_ids = [];
        foreach ((array) ($item['categories'] ?? []) as $cat_slug) {
            $cat_slug = sanitize_title((string) $cat_slug);
            if ($cat_slug !== '' && isset($slug_to_category_term_id[$cat_slug])) {
                $category_ids[] = (int) $slug_to_category_term_id[$cat_slug];
            }
        }
        $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
        if (!empty($category_ids)) {
            wp_set_object_terms($word_id, $category_ids, 'word-category', false);
        }

        $mode = isset($options['wordset_mode']) ? sanitize_key((string) $options['wordset_mode']) : 'create_from_export';
        $target_wordset_id = isset($options['target_wordset_id']) ? (int) $options['target_wordset_id'] : 0;
        $wordset_ids = [];
        if ($mode === 'assign_existing' && $target_wordset_id > 0) {
            $wordset_ids[] = $target_wordset_id;
        } else {
            foreach ((array) ($item['wordsets'] ?? []) as $wordset_slug) {
                $wordset_slug = sanitize_title((string) $wordset_slug);
                if ($wordset_slug !== '' && isset($wordset_map[$wordset_slug])) {
                    $wordset_ids[] = (int) $wordset_map[$wordset_slug];
                }
            }
        }
        $wordset_ids = array_values(array_unique(array_filter(array_map('intval', $wordset_ids))));
        if (!empty($wordset_ids)) {
            wp_set_object_terms($word_id, $wordset_ids, 'wordset', false);
        } elseif (isset($item['wordsets']) && is_array($item['wordsets'])) {
            wp_set_object_terms($word_id, [], 'wordset', false);
        }

        $language_ids = ll_tools_import_resolve_taxonomy_term_ids_by_slugs((array) ($item['languages'] ?? []), 'language', true, $result['errors']);
        if (!empty($language_ids) || (isset($item['languages']) && is_array($item['languages']))) {
            wp_set_object_terms($word_id, $language_ids, 'language', false);
        }

        $part_of_speech_ids = ll_tools_import_resolve_taxonomy_term_ids_by_slugs((array) ($item['parts_of_speech'] ?? []), 'part_of_speech', true, $result['errors']);
        if (!empty($part_of_speech_ids) || (isset($item['parts_of_speech']) && is_array($item['parts_of_speech']))) {
            wp_set_object_terms($word_id, $part_of_speech_ids, 'part_of_speech', false);
        }

        ll_tools_import_replace_post_meta_values($word_id, isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [], 'words');
        ll_tools_import_apply_featured_image($word_id, isset($item['featured_image']) ? (array) $item['featured_image'] : [], $extract_dir, $slug, $result, 'word', $is_new_word);

        $word_label = isset($item['title']) ? (string) $item['title'] : (string) $postarr['post_title'];
        $word_label_key = ll_tools_import_normalize_match_text($word_label);
        if ($word_label_key !== '') {
            if (!isset($word_label_index_global[$word_label_key])) {
                $word_label_index_global[$word_label_key] = [];
            }
            $word_label_index_global[$word_label_key][$word_id] = true;

            foreach ($category_ids as $category_id) {
                $category_id = (int) $category_id;
                if ($category_id <= 0) {
                    continue;
                }
                if (!isset($word_label_index_by_category[$category_id])) {
                    $word_label_index_by_category[$category_id] = [];
                }
                if (!isset($word_label_index_by_category[$category_id][$word_label_key])) {
                    $word_label_index_by_category[$category_id][$word_label_key] = [];
                }
                $word_label_index_by_category[$category_id][$word_label_key][$word_id] = true;
            }
        }

        if (!empty($item['specific_wrong_answer_texts']) && is_array($item['specific_wrong_answer_texts'])) {
            $wrong_texts = [];
            foreach ($item['specific_wrong_answer_texts'] as $wrong_text_raw) {
                $wrong_text = sanitize_text_field((string) $wrong_text_raw);
                if ($wrong_text !== '') {
                    $wrong_texts[] = $wrong_text;
                }
            }
            if (!empty($wrong_texts)) {
                $pending_specific_wrong_answers[$word_id] = [
                    'word_slug' => $slug,
                    'category_ids' => $category_ids,
                    'wrong_texts' => $wrong_texts,
                ];
            }
        }

        $word_image_link = ll_tools_import_resolve_word_image_link_for_word($item, $word_image_slug_to_id);
        $linked_word_image_id = (int) ($word_image_link['word_image_id'] ?? 0);
        $linked_word_image_source = (string) ($word_image_link['source'] ?? '');
        if ($linked_word_image_id > 0) {
            update_post_meta($word_id, '_ll_autopicked_image_id', $linked_word_image_id);
            if ($linked_word_image_source === 'explicit') {
                $linked_thumb_id = (int) get_post_thumbnail_id($linked_word_image_id);
                if ($linked_thumb_id > 0) {
                    set_post_thumbnail($word_id, $linked_thumb_id);
                }
            }
        } else {
            $source_word_image_slug = sanitize_title((string) ($item['linked_word_image_slug'] ?? ''));
            if ($source_word_image_slug !== '') {
                $result['errors'][] = sprintf(
                    __('Could not link word "%1$s" to source word image "%2$s".', 'll-tools-text-domain'),
                    $slug,
                    $source_word_image_slug
                );
            }
        }

        foreach ((array) ($item['audio_entries'] ?? []) as $audio_item) {
            $audio_slug = isset($audio_item['slug']) ? sanitize_title($audio_item['slug']) : '';
            $audio_origin_id = isset($audio_item['origin_id']) ? (int) $audio_item['origin_id'] : 0;
            if ($audio_slug === '') {
                $audio_slug = $audio_origin_id > 0 ? ('imported-audio-' . $audio_origin_id) : ('imported-audio-' . wp_generate_password(8, false, false));
            }

            $existing_audio_id = ll_tools_import_find_word_audio_id_by_slug($word_id, $audio_slug);
            $audio_postarr = [
                'post_title'  => isset($audio_item['title']) ? (string) $audio_item['title'] : '',
                'post_status' => ll_tools_sanitize_import_post_status($audio_item['status'] ?? 'draft', 'draft'),
                'post_type'   => 'word_audio',
                'post_parent' => $word_id,
                'post_name'   => $audio_slug,
            ];

            if ($existing_audio_id > 0) {
                $audio_postarr['ID'] = $existing_audio_id;
                $audio_post_id = wp_update_post($audio_postarr, true);
                if (is_wp_error($audio_post_id)) {
                    $result['errors'][] = sprintf(__('Failed to update audio "%1$s" for word "%2$s": %3$s', 'll-tools-text-domain'), $audio_slug, $slug, $audio_post_id->get_error_message());
                    continue;
                }
                $result['stats']['word_audio_updated']++;
                $is_new_word_audio = false;
            } else {
                $audio_postarr['post_author'] = get_current_user_id();
                $audio_post_id = wp_insert_post($audio_postarr, true);
                if (is_wp_error($audio_post_id)) {
                    $result['errors'][] = sprintf(__('Failed to create audio "%1$s" for word "%2$s": %3$s', 'll-tools-text-domain'), $audio_slug, $slug, $audio_post_id->get_error_message());
                    continue;
                }
                $result['stats']['word_audio_created']++;
                $is_new_word_audio = true;
                ll_tools_import_track_undo_id($result, 'word_audio_post_ids', (int) $audio_post_id);
            }

            $audio_post_id = (int) $audio_post_id;
            ll_tools_import_replace_post_meta_values($audio_post_id, isset($audio_item['meta']) && is_array($audio_item['meta']) ? $audio_item['meta'] : [], 'word_audio');

            $recording_type_ids = ll_tools_import_resolve_taxonomy_term_ids_by_slugs((array) ($audio_item['recording_types'] ?? []), 'recording_type', true, $result['errors']);
            if (!empty($recording_type_ids)) {
                wp_set_object_terms($audio_post_id, $recording_type_ids, 'recording_type', false);
            } elseif (isset($audio_item['recording_types']) && is_array($audio_item['recording_types'])) {
                wp_set_object_terms($audio_post_id, [], 'recording_type', false);
            }

            ll_tools_import_apply_audio_file(
                $audio_post_id,
                isset($audio_item['audio_file']) ? (array) $audio_item['audio_file'] : [],
                $extract_dir,
                $slug,
                $result,
                $is_new_word_audio
            );
        }
    }

    if (!empty($origin_word_id_to_imported)) {
        ll_tools_import_remap_similar_word_ids($origin_word_id_to_imported);
    }

    if (!empty($pending_specific_wrong_answers)) {
        ll_tools_import_apply_specific_wrong_answers_from_texts(
            $pending_specific_wrong_answers,
            $word_label_index_by_category,
            $word_label_index_global,
            $result
        );
    }
}

/**
 * Apply specific wrong-answer mappings from imported text labels.
 *
 * @param array $pending
 * @param array $word_label_index_by_category
 * @param array $word_label_index_global
 * @param array $result
 * @return void
 */
function ll_tools_import_apply_specific_wrong_answers_from_texts(
    array $pending,
    array $word_label_index_by_category,
    array $word_label_index_global,
    array &$result
): void {
    if (empty($pending)) {
        return;
    }

    $id_meta_key = defined('LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY')
        ? (string) LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY
        : '_ll_specific_wrong_answer_ids';
    $text_meta_key = defined('LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY')
        ? (string) LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY
        : '_ll_specific_wrong_answer_texts';
    $touched_category_ids = [];
    $word_category_cache = [];

    foreach ($pending as $owner_word_id_raw => $config) {
        $owner_word_id = (int) $owner_word_id_raw;
        if ($owner_word_id <= 0 || !is_array($config)) {
            continue;
        }

        $category_ids = isset($config['category_ids']) && is_array($config['category_ids'])
            ? array_values(array_filter(array_map('intval', $config['category_ids']), static function ($id): bool {
                return $id > 0;
            }))
            : [];
        $wrong_texts_raw = isset($config['wrong_texts']) && is_array($config['wrong_texts'])
            ? $config['wrong_texts']
            : [];
        $owner_title = (string) get_the_title($owner_word_id);
        if (function_exists('ll_tools_normalize_specific_wrong_answer_texts')) {
            $wrong_texts = ll_tools_normalize_specific_wrong_answer_texts($wrong_texts_raw, $owner_title);
        } else {
            $owner_key = ll_tools_import_normalize_match_text($owner_title);
            $lookup = [];
            foreach ($wrong_texts_raw as $wrong_text_raw) {
                $wrong_text = sanitize_text_field((string) $wrong_text_raw);
                $wrong_key = ll_tools_import_normalize_match_text($wrong_text);
                if ($wrong_key === '' || $wrong_key === $owner_key || isset($lookup[$wrong_key])) {
                    continue;
                }
                $lookup[$wrong_key] = $wrong_text;
            }
            $wrong_texts = array_values($lookup);
        }

        if (!empty($wrong_texts)) {
            update_post_meta($owner_word_id, $text_meta_key, $wrong_texts);
        } else {
            delete_post_meta($owner_word_id, $text_meta_key);
        }

        $resolved_lookup = [];
        foreach ($wrong_texts as $wrong_text_raw) {
            $wrong_text = sanitize_text_field((string) $wrong_text_raw);
            $wrong_key = ll_tools_import_normalize_match_text($wrong_text);
            if ($wrong_key === '') {
                continue;
            }

            $candidate_ids = [];
            foreach ($category_ids as $category_id) {
                if (!isset($word_label_index_by_category[$category_id][$wrong_key])) {
                    continue;
                }
                $candidate_ids = array_merge($candidate_ids, array_map('intval', array_keys((array) $word_label_index_by_category[$category_id][$wrong_key])));
            }

            if (empty($candidate_ids) && isset($word_label_index_global[$wrong_key])) {
                $candidate_ids = array_map('intval', array_keys((array) $word_label_index_global[$wrong_key]));
            }

            $candidate_ids = array_values(array_unique(array_filter($candidate_ids, static function ($candidate_id) use ($owner_word_id): bool {
                return $candidate_id > 0 && $candidate_id !== $owner_word_id;
            })));
            if (!empty($candidate_ids) && !empty($category_ids)) {
                $owner_category_lookup = array_fill_keys($category_ids, true);
                $candidate_ids = array_values(array_filter($candidate_ids, function ($candidate_id) use (&$word_category_cache, $owner_category_lookup): bool {
                    $candidate_id = (int) $candidate_id;
                    if ($candidate_id <= 0) {
                        return false;
                    }

                    if (!array_key_exists($candidate_id, $word_category_cache)) {
                        $candidate_category_ids = wp_get_post_terms($candidate_id, 'word-category', ['fields' => 'ids']);
                        if (is_wp_error($candidate_category_ids)) {
                            $candidate_category_ids = [];
                        }
                        $word_category_cache[$candidate_id] = array_values(array_unique(array_filter(array_map('intval', (array) $candidate_category_ids), static function ($category_id): bool {
                            return $category_id > 0;
                        })));
                    }

                    foreach ((array) $word_category_cache[$candidate_id] as $candidate_category_id) {
                        if (isset($owner_category_lookup[(int) $candidate_category_id])) {
                            return true;
                        }
                    }

                    return false;
                }));
            }

            if (empty($candidate_ids)) {
                continue;
            }

            foreach ($candidate_ids as $candidate_id) {
                $resolved_lookup[$candidate_id] = true;
            }
        }

        $resolved_ids = array_values(array_map('intval', array_keys($resolved_lookup)));
        sort($resolved_ids, SORT_NUMERIC);

        if (!empty($resolved_ids)) {
            update_post_meta($owner_word_id, $id_meta_key, $resolved_ids);
        } else {
            delete_post_meta($owner_word_id, $id_meta_key);
        }

        if (!empty($category_ids)) {
            foreach ($category_ids as $category_id) {
                $touched_category_ids[$category_id] = true;
            }
        } elseif (function_exists('ll_tools_collect_specific_wrong_answer_related_category_ids')) {
            $related = ll_tools_collect_specific_wrong_answer_related_category_ids($owner_word_id, $resolved_ids);
            foreach ((array) $related as $category_id_raw) {
                $category_id = (int) $category_id_raw;
                if ($category_id > 0) {
                    $touched_category_ids[$category_id] = true;
                }
            }
        }
    }

    if (function_exists('ll_tools_rebuild_specific_wrong_answer_owner_map')) {
        ll_tools_rebuild_specific_wrong_answer_owner_map();
    }

    if (!empty($touched_category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_map('intval', array_keys($touched_category_ids)));
    }
}

/**
 * Build a map of exported wordset slug => local term_id based on import mode.
 *
 * @param array $wordsets
 * @param array $options
 * @param array $result
 * @return array
 */
function ll_tools_import_prepare_wordset_map(array $wordsets, array $options, array &$result): array {
    $mode = isset($options['wordset_mode']) ? sanitize_key((string) $options['wordset_mode']) : 'create_from_export';
    $target_wordset_id = isset($options['target_wordset_id']) ? (int) $options['target_wordset_id'] : 0;
    $name_overrides = isset($options['wordset_name_overrides']) && is_array($options['wordset_name_overrides'])
        ? $options['wordset_name_overrides']
        : [];

    $map = [];
    if ($mode === 'assign_existing' && $target_wordset_id > 0) {
        foreach ($wordsets as $wordset) {
            $slug = isset($wordset['slug']) ? sanitize_title((string) $wordset['slug']) : '';
            if ($slug !== '') {
                $map[$slug] = $target_wordset_id;
            }
        }
        return $map;
    }

    foreach ($wordsets as $wordset) {
        $slug = isset($wordset['slug']) ? sanitize_title((string) $wordset['slug']) : '';
        if ($slug === '') {
            continue;
        }

        $name = isset($wordset['name']) ? (string) $wordset['name'] : $slug;
        if (isset($name_overrides[$slug])) {
            $name = sanitize_text_field((string) $name_overrides[$slug]);
        }
        $description = isset($wordset['description']) ? (string) $wordset['description'] : '';

        $existing = get_term_by('slug', $slug, 'wordset');
        if ($existing && !is_wp_error($existing)) {
            $term_id = (int) $existing->term_id;
            $updated = wp_update_term($term_id, 'wordset', [
                'name'        => $name,
                'description' => $description,
            ]);
            if (is_wp_error($updated)) {
                $result['errors'][] = sprintf(__('Failed to update word set "%s": %s', 'll-tools-text-domain'), $slug, $updated->get_error_message());
                $map[$slug] = $term_id;
                continue;
            }
            $result['stats']['wordsets_updated']++;
        } else {
            $insert = wp_insert_term($name, 'wordset', [
                'slug'        => $slug,
                'description' => $description,
            ]);
            if (is_wp_error($insert)) {
                $result['errors'][] = sprintf(__('Failed to create word set "%s": %s', 'll-tools-text-domain'), $slug, $insert->get_error_message());
                continue;
            }
            $term_id = (int) $insert['term_id'];
            $result['stats']['wordsets_created']++;
            ll_tools_import_track_undo_id($result, 'wordset_term_ids', $term_id);
        }

        ll_tools_import_replace_term_meta_values($term_id, isset($wordset['meta']) && is_array($wordset['meta']) ? $wordset['meta'] : [], 'wordset');
        if (get_current_user_id() > 0) {
            delete_term_meta($term_id, 'manager_user_id');
            add_term_meta($term_id, 'manager_user_id', get_current_user_id());
        }

        $map[$slug] = $term_id;
    }

    return $map;
}

/**
 * Resolve a list of term slugs to term IDs, creating missing terms when requested.
 *
 * @param array $slugs
 * @param string $taxonomy
 * @param bool $create_missing
 * @param array $errors
 * @return array
 */
function ll_tools_import_resolve_taxonomy_term_ids_by_slugs(array $slugs, string $taxonomy, bool $create_missing, array &$errors): array {
    $term_ids = [];
    foreach ($slugs as $slug_raw) {
        $slug = sanitize_title((string) $slug_raw);
        if ($slug === '') {
            continue;
        }

        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term && !is_wp_error($term)) {
            $term_ids[] = (int) $term->term_id;
            continue;
        }

        if (!$create_missing) {
            continue;
        }

        $insert = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), $taxonomy, ['slug' => $slug]);
        if (is_wp_error($insert)) {
            $existing_id = term_exists($slug, $taxonomy);
            if (is_array($existing_id) && !empty($existing_id['term_id'])) {
                $term_ids[] = (int) $existing_id['term_id'];
                continue;
            }
            if (is_int($existing_id) && $existing_id > 0) {
                $term_ids[] = (int) $existing_id;
                continue;
            }
            $errors[] = sprintf(__('Could not create %1$s term "%2$s": %3$s', 'll-tools-text-domain'), $taxonomy, $slug, $insert->get_error_message());
            continue;
        }

        $term_ids[] = (int) $insert['term_id'];
    }

    return array_values(array_unique(array_filter(array_map('intval', $term_ids))));
}

/**
 * Find an existing word_audio child post by slug + parent word ID.
 *
 * @param int $word_id
 * @param string $slug
 * @return int
 */
function ll_tools_import_find_word_audio_id_by_slug(int $word_id, string $slug): int {
    if ($word_id <= 0 || $slug === '') {
        return 0;
    }

    $matches = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => 1,
        'post_parent'    => $word_id,
        'name'           => $slug,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    return !empty($matches) ? (int) $matches[0] : 0;
}

/**
 * Import an audio file from payload and attach its relative path to word_audio meta.
 *
 * @param int $audio_post_id
 * @param array $audio_file
 * @param string $extract_dir
 * @param string $word_slug
 * @param array $result
 * @param bool $track_for_undo
 * @return void
 */
function ll_tools_import_apply_audio_file(int $audio_post_id, array $audio_file, $extract_dir, string $word_slug, array &$result, bool $track_for_undo = false): void {
    if ($audio_post_id <= 0 || empty($audio_file['file'])) {
        return;
    }

    $rel = ltrim((string) $audio_file['file'], '/');
    $absolute = ll_tools_resolve_import_path($extract_dir, $rel);
    if ($absolute === '') {
        $result['errors'][] = sprintf(__('Skipped audio import for "%s" because the file path was invalid.', 'll-tools-text-domain'), $word_slug);
        return;
    }
    if (!is_file($absolute)) {
        $result['errors'][] = sprintf(__('Audio file for "%s" is missing from the zip.', 'll-tools-text-domain'), $word_slug);
        return;
    }

    $relative_path = ll_tools_import_copy_audio_file_to_uploads($absolute);
    if (is_wp_error($relative_path)) {
        $result['errors'][] = sprintf(__('Failed to import audio for "%1$s": %2$s', 'll-tools-text-domain'), $word_slug, $relative_path->get_error_message());
        return;
    }

    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    $result['stats']['audio_files_imported']++;
    if ($track_for_undo) {
        ll_tools_import_track_undo_path($result, 'audio_paths', (string) $relative_path);
    }
}

/**
 * Copy an imported audio file into uploads and return stored path format.
 *
 * @param string $file_path
 * @return string|WP_Error
 */
function ll_tools_import_copy_audio_file_to_uploads($file_path) {
    $mime_type = ll_tools_validate_import_audio_file($file_path);
    if (is_wp_error($mime_type)) {
        return $mime_type;
    }

    $upload_dir = wp_upload_dir();
    if (!wp_mkdir_p($upload_dir['path'])) {
        return new WP_Error('ll_tools_upload_path', __('Could not create uploads directory.', 'll-tools-text-domain'));
    }

    $file_info = wp_check_filetype_and_ext($file_path, basename($file_path));
    $source_name = '';
    if (!empty($file_info['proper_filename'])) {
        $source_name = (string) $file_info['proper_filename'];
    } elseif (basename($file_path) !== '') {
        $source_name = basename($file_path);
    } else {
        $source_name = 'imported-audio';
    }

    $filename = wp_unique_filename($upload_dir['path'], $source_name);
    $target = trailingslashit($upload_dir['path']) . $filename;
    if (!@copy($file_path, $target)) {
        return new WP_Error('ll_tools_copy_failed', __('Could not copy audio into uploads.', 'll-tools-text-domain'));
    }

    $target_normalized = wp_normalize_path($target);
    $abspath_normalized = wp_normalize_path(untrailingslashit(ABSPATH));
    if ($abspath_normalized !== '' && strpos($target_normalized, $abspath_normalized) === 0) {
        $relative = substr($target_normalized, strlen($abspath_normalized));
        $relative = '/' . ltrim((string) $relative, '/');
        return $relative;
    }

    return trailingslashit((string) $upload_dir['baseurl']) . $filename;
}

/**
 * Validate an imported audio file before copying to uploads.
 *
 * @param string $file_path
 * @return string|WP_Error
 */
function ll_tools_validate_import_audio_file($file_path) {
    if (!is_file($file_path) || !is_readable($file_path)) {
        return new WP_Error('ll_tools_invalid_audio', __('Imported audio file is missing or unreadable.', 'll-tools-text-domain'));
    }

    $file_info = wp_check_filetype_and_ext($file_path, basename($file_path));
    $mime_type = '';
    if (!empty($file_info['type'])) {
        $mime_type = (string) $file_info['type'];
    } else {
        $fallback = wp_check_filetype(basename($file_path), null);
        $mime_type = isset($fallback['type']) ? (string) $fallback['type'] : '';
    }

    if ($mime_type === '') {
        return new WP_Error('ll_tools_invalid_audio', __('Imported file is not a supported audio type.', 'll-tools-text-domain'));
    }

    if (strpos($mime_type, 'audio/') !== 0 && strpos($mime_type, 'video/') !== 0) {
        return new WP_Error('ll_tools_invalid_audio', __('Imported file is not an audio recording.', 'll-tools-text-domain'));
    }

    return $mime_type;
}

/**
 * Remap similar word meta IDs using exported origin_id => imported word ID map.
 *
 * @param array $origin_to_imported
 * @return void
 */
function ll_tools_import_remap_similar_word_ids(array $origin_to_imported): void {
    if (empty($origin_to_imported)) {
        return;
    }

    foreach ($origin_to_imported as $origin_id => $imported_id) {
        $imported_id = (int) $imported_id;
        if ((int) $origin_id <= 0 || $imported_id <= 0) {
            continue;
        }

        foreach (['similar_word_id', '_ll_similar_word_id'] as $key) {
            $current = get_post_meta($imported_id, $key, true);
            $current_id = (int) $current;
            if ($current_id <= 0 || !isset($origin_to_imported[$current_id])) {
                continue;
            }
            $mapped_id = (int) $origin_to_imported[$current_id];
            if ($mapped_id > 0) {
                update_post_meta($imported_id, $key, (string) $mapped_id);
            }
        }
    }
}

/**
 * Import a remote image URL as an attachment placeholder (no local download).
 *
 * @param string $source_url
 * @param array $info
 * @param int $parent_post_id
 * @return int|WP_Error
 */
function ll_tools_import_attachment_from_external_url(string $source_url, array $info, $parent_post_id = 0) {
    $source_url = ll_tools_import_normalize_external_image_source_url($source_url);
    if ($source_url === '') {
        return new WP_Error('ll_tools_external_image_url_invalid', __('The external image URL is invalid.', 'll-tools-text-domain'));
    }

    $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
    $basename = '';
    if ($path !== '') {
        $basename = basename(rawurldecode($path));
    }
    $basename = sanitize_file_name($basename);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        $basename = 'imported-remote-image';
    }

    $detected_filetype = wp_check_filetype($basename, null);
    $mime_type = isset($info['mime_type']) ? sanitize_text_field((string) $info['mime_type']) : '';
    if ($mime_type === '' && !empty($detected_filetype['type'])) {
        $mime_type = (string) $detected_filetype['type'];
    }
    if ($mime_type === '' || strpos($mime_type, 'image/') !== 0) {
        return new WP_Error('ll_tools_external_image_mime_invalid', __('The external image URL is not a supported image type.', 'll-tools-text-domain'));
    }

    $title = !empty($info['title'])
        ? (string) $info['title']
        : preg_replace('/\.[^.]+$/', '', $basename);
    if (!is_string($title) || trim($title) === '') {
        $title = __('Imported remote image', 'll-tools-text-domain');
    }

    $attachment = [
        'guid'           => $source_url,
        'post_mime_type' => $mime_type,
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, false, (int) $parent_post_id);
    if (is_wp_error($attach_id)) {
        return $attach_id;
    }

    update_post_meta((int) $attach_id, '_ll_tools_external_source_url', $source_url);

    if (!empty($info['alt'])) {
        update_post_meta((int) $attach_id, '_wp_attachment_image_alt', sanitize_text_field((string) $info['alt']));
    }

    $width = isset($info['width']) ? (int) $info['width'] : 0;
    $height = isset($info['height']) ? (int) $info['height'] : 0;
    if ($width > 0 || $height > 0) {
        $metadata = [];
        if ($basename !== '') {
            $metadata['file'] = $basename;
        }
        if ($width > 0) {
            $metadata['width'] = $width;
        }
        if ($height > 0) {
            $metadata['height'] = $height;
        }
        wp_update_attachment_metadata((int) $attach_id, $metadata);
    }

    return (int) $attach_id;
}

/**
 * Import an attachment file from the extracted directory into the media library.
 *
 * @param string $file_path
 * @param array  $info
 * @param int    $parent_post_id
 * @return int|WP_Error
 */
function ll_tools_import_attachment_from_file($file_path, array $info, $parent_post_id = 0) {
    $mime_type = ll_tools_validate_import_image_file($file_path);
    if (is_wp_error($mime_type)) {
        return $mime_type;
    }

    $upload_dir = wp_upload_dir();
    if (!wp_mkdir_p($upload_dir['path'])) {
        return new WP_Error('ll_tools_upload_path', __('Could not create uploads directory.', 'll-tools-text-domain'));
    }

    $file_info = wp_check_filetype_and_ext($file_path, basename($file_path));
    $source_name = '';
    if (!empty($file_info['proper_filename'])) {
        $source_name = (string) $file_info['proper_filename'];
    } elseif (basename($file_path) !== '') {
        $source_name = basename($file_path);
    } else {
        $source_name = 'imported-image';
    }

    $filename = wp_unique_filename($upload_dir['path'], $source_name);
    $target = trailingslashit($upload_dir['path']) . $filename;

    if (!@copy($file_path, $target)) {
        return new WP_Error('ll_tools_copy_failed', __('Could not copy image into uploads.', 'll-tools-text-domain'));
    }

    $filetype = wp_check_filetype($filename, null);
    $attachment = [
        'guid'           => trailingslashit($upload_dir['url']) . $filename,
        'post_mime_type' => !empty($filetype['type']) ? $filetype['type'] : $mime_type,
        'post_title'     => !empty($info['title']) ? $info['title'] : preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $target, $parent_post_id);
    if (is_wp_error($attach_id)) {
        return $attach_id;
    }

    $metadata = [];
    try {
        $generated = wp_generate_attachment_metadata($attach_id, $target);
        if (is_array($generated)) {
            $metadata = $generated;
            wp_update_attachment_metadata($attach_id, $metadata);
        }
    } catch (Throwable $e) {
        // Some test environments omit image editor classes; keep the attachment.
    }

    if (function_exists('_wp_relative_upload_path')) {
        $relative_file = (string) _wp_relative_upload_path($target);
        if ($relative_file !== '') {
            update_post_meta($attach_id, '_wp_attached_file', $relative_file);
        }
    }

    if (!empty($info['alt'])) {
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($info['alt']));
    }

    return $attach_id;
}

/**
 * Store import result in a transient and redirect back to the page.
 *
 * @param array $result
 * @return void
 */
function ll_tools_store_import_result_and_redirect(array $result) {
    set_transient('ll_tools_import_result', $result, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(ll_tools_get_export_import_page_url(ll_tools_get_import_page_slug()));
    exit;
}

/**
 * Recursively remove a directory (best effort).
 *
 * @param string $dir
 * @return void
 */
function ll_tools_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            ll_tools_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Defend against zip-slip by extracting files manually with strict path checks.
 *
 * @param ZipArchive $zip
 * @param string     $extract_dir
 * @return true|WP_Error
 */
function ll_tools_extract_zip_safely(ZipArchive $zip, $extract_dir) {
    $entry_count = (int) $zip->numFiles;
    $max_entries = (int) apply_filters('ll_tools_import_max_zip_entries', 5000);
    if ($entry_count < 1) {
        return new WP_Error('ll_tools_import_empty_zip', __('Import failed: zip file is empty.', 'll-tools-text-domain'));
    }
    if ($entry_count > $max_entries) {
        return new WP_Error('ll_tools_import_too_many_entries', __('Import failed: zip contains too many files.', 'll-tools-text-domain'));
    }

    $default_max_uncompressed = max(512 * MB_IN_BYTES, ll_tools_export_get_hard_limit_bytes());
    $max_uncompressed = (int) apply_filters('ll_tools_import_max_uncompressed_bytes', $default_max_uncompressed);
    $total_uncompressed = 0;

    for ($i = 0; $i < $entry_count; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || !isset($stat['name'])) {
            return new WP_Error('ll_tools_import_bad_zip_entry', __('Import failed: zip metadata is invalid.', 'll-tools-text-domain'));
        }

        $entry_name = (string) $stat['name'];
        $is_dir = (substr($entry_name, -1) === '/' || substr($entry_name, -1) === '\\');
        $normalized = ll_tools_normalize_import_relative_path($entry_name);

        if ($normalized === '') {
            return new WP_Error('ll_tools_import_bad_zip_path', __('Import failed: zip contains an invalid file path.', 'll-tools-text-domain'));
        }

        $target = ll_tools_resolve_import_path($extract_dir, $normalized);
        if ($target === '') {
            return new WP_Error('ll_tools_import_bad_zip_path', __('Import failed: zip contains an unsafe file path.', 'll-tools-text-domain'));
        }

        if ($is_dir) {
            if (!wp_mkdir_p($target)) {
                return new WP_Error('ll_tools_import_extract_dir', __('Import failed: could not create extraction folders.', 'll-tools-text-domain'));
            }
            continue;
        }

        $entry_size = isset($stat['size']) ? (int) $stat['size'] : 0;
        if ($entry_size < 0) {
            return new WP_Error('ll_tools_import_bad_zip_size', __('Import failed: zip entry size is invalid.', 'll-tools-text-domain'));
        }
        $total_uncompressed += $entry_size;
        if ($total_uncompressed > $max_uncompressed) {
            return new WP_Error('ll_tools_import_zip_too_large', __('Import failed: uncompressed zip size is too large.', 'll-tools-text-domain'));
        }

        $target_dir = dirname($target);
        if (!wp_mkdir_p($target_dir)) {
            return new WP_Error('ll_tools_import_extract_dir', __('Import failed: could not create extraction folders.', 'll-tools-text-domain'));
        }

        $in = $zip->getStream($entry_name);
        if ($in === false) {
            return new WP_Error('ll_tools_import_extract_stream', __('Import failed: could not read a file from the zip.', 'll-tools-text-domain'));
        }

        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            return new WP_Error('ll_tools_import_extract_write', __('Import failed: could not write extracted files.', 'll-tools-text-domain'));
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    return true;
}

/**
 * Normalize a zip/import relative path and reject traversal/absolute paths.
 *
 * @param string $path
 * @return string Empty string when invalid.
 */
function ll_tools_normalize_import_relative_path($path) {
    $path = str_replace('\\', '/', trim((string) $path));
    if ($path === '' || strpos($path, "\0") !== false) {
        return '';
    }
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path)) {
        return '';
    }

    $segments = explode('/', $path);
    $normalized = [];
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return '';
        }
        $normalized[] = $segment;
    }

    if (empty($normalized)) {
        return '';
    }

    return implode('/', $normalized);
}

/**
 * Resolve a validated relative import path against extraction directory.
 *
 * @param string $extract_dir
 * @param string $relative_path
 * @return string Empty string when invalid.
 */
function ll_tools_resolve_import_path($extract_dir, $relative_path) {
    $normalized = ll_tools_normalize_import_relative_path($relative_path);
    if ($normalized === '') {
        return '';
    }

    $base = trailingslashit(wp_normalize_path($extract_dir));
    $target = wp_normalize_path($base . $normalized);
    if (strpos($target, $base) !== 0) {
        return '';
    }

    return $target;
}

/**
 * Verify an imported attachment file is a real image before media import.
 *
 * @param string $file_path
 * @return string|WP_Error Detected mime type or WP_Error.
 */
function ll_tools_validate_import_image_file($file_path) {
    if (!is_file($file_path) || !is_readable($file_path)) {
        return new WP_Error('ll_tools_invalid_image', __('Imported image file is missing or unreadable.', 'll-tools-text-domain'));
    }

    $mime_type = wp_get_image_mime($file_path);
    if (!is_string($mime_type) || strpos($mime_type, 'image/') !== 0) {
        return new WP_Error('ll_tools_invalid_image', __('Imported file is not a valid image.', 'll-tools-text-domain'));
    }

    $allowed_mimes = get_allowed_mime_types();
    if (!in_array($mime_type, $allowed_mimes, true)) {
        return new WP_Error('ll_tools_invalid_image', __('Imported image type is not allowed on this site.', 'll-tools-text-domain'));
    }

    return $mime_type;
}
