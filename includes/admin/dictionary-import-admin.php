<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_IMPORT_ACTIVE_JOB_OPTION')) {
    define('LL_TOOLS_DICTIONARY_IMPORT_ACTIVE_JOB_OPTION', 'll_tools_dictionary_import_active_job');
}

if (!defined('LL_TOOLS_DICTIONARY_IMPORT_JOB_OPTION_PREFIX')) {
    define('LL_TOOLS_DICTIONARY_IMPORT_JOB_OPTION_PREFIX', 'll_tools_dictionary_import_job_');
}

if (!defined('LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY', 'll_tools_dictionary_import_last_job_id');
}

/**
 * Capability required for dictionary import and migration tools.
 */
function ll_tools_get_dictionary_import_capability(): string {
    return (string) apply_filters('ll_tools_dictionary_import_capability', 'manage_options');
}

function ll_tools_current_user_can_dictionary_import(): bool {
    return current_user_can(ll_tools_get_dictionary_import_capability());
}

/**
 * Register the dictionary manager admin page.
 */
function ll_tools_register_dictionary_import_page(): void {
    add_management_page(
        __('LL Dictionary Manager', 'll-tools-text-domain'),
        __('LL Dictionary Manager', 'll-tools-text-domain'),
        ll_tools_get_dictionary_import_capability(),
        'll-dictionary-import',
        'll_tools_render_dictionary_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_dictionary_import_page');
add_action('admin_post_ll_tools_dictionary_export_snapshot', 'll_tools_dictionary_handle_export_snapshot');

/**
 * Return all word sets for importer dropdowns.
 *
 * @return WP_Term[]
 */
function ll_tools_dictionary_import_get_wordsets(): array {
    $terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    return is_wp_error($terms) ? [] : $terms;
}

/**
 * Parse a TSV file into import rows.
 *
 * @return array<int,array<string,string>>|WP_Error
 */
function ll_tools_dictionary_parse_tsv_file(string $file_path): array|WP_Error {
    $file_path = trim($file_path);
    if ($file_path === '' || !is_readable($file_path)) {
        return new WP_Error('ll_tools_dictionary_file_unreadable', __('Could not read the uploaded TSV file.', 'll-tools-text-domain'));
    }

    $handle = fopen($file_path, 'r');
    if ($handle === false) {
        return new WP_Error('ll_tools_dictionary_file_open_failed', __('Could not open the uploaded TSV file.', 'll-tools-text-domain'));
    }

    $rows = [];
    $line_number = 0;
    $header = [];

    while (($data = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
        $line_number++;
        if (!is_array($data)) {
            continue;
        }

        if ($line_number === 1 && !empty($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $data[0]) ?? (string) $data[0];
        }

        $data = array_map(static function ($value): string {
            return trim((string) $value);
        }, $data);

        if ($line_number === 1) {
            $possible_header = array_map(static function (string $value): string {
                $value = strtolower(trim($value));
                $value = preg_replace('/\s+/', '_', $value) ?? $value;
                return trim($value, '_');
            }, $data);
            $looks_like_header = in_array('entry', $possible_header, true)
                || in_array('definition', $possible_header, true)
                || in_array('gender_number', $possible_header, true)
                || in_array('entry_type', $possible_header, true)
                || in_array('page_number', $possible_header, true)
                || count(array_filter($possible_header, static function (string $column): bool {
                    return strpos($column, 'definition_full_') === 0;
                })) > 0;
            if ($looks_like_header) {
                $header = $possible_header;
                continue;
            }
        }

        if (count($data) < 1) {
            continue;
        }

        if (!empty($header)) {
            $row = [];
            foreach ($header as $index => $column_name) {
                if ($column_name === '') {
                    continue;
                }
                $row[$column_name] = (string) ($data[$index] ?? '');
            }
            $rows[] = $row;
            continue;
        }

        $rows[] = [
            'entry' => (string) ($data[0] ?? ''),
            'definition' => (string) ($data[1] ?? ''),
            'gender_number' => (string) ($data[2] ?? ''),
            'entry_type' => (string) ($data[3] ?? ''),
            'parent' => (string) ($data[4] ?? ''),
            'needs_review' => (string) ($data[5] ?? ''),
            'page_number' => (string) ($data[6] ?? ''),
        ];
    }

    fclose($handle);

    return $rows;
}

/**
 * Return a clean default import summary structure.
 *
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_default_summary(int $rows_total = 0): array {
    return [
        'rows_total' => max(0, $rows_total),
        'rows_grouped' => 0,
        'rows_skipped_empty' => 0,
        'rows_skipped_review' => 0,
        'entries_created' => 0,
        'entries_updated' => 0,
        'entries_deleted' => 0,
        'sources_updated' => 0,
        'sources_replaced' => 0,
        'entry_ids' => [],
        'errors' => [],
        'error_count' => 0,
    ];
}

/**
 * Merge one batch summary into an aggregate summary.
 *
 * @param array<string,mixed> $summary
 * @param array<string,mixed> $batch_summary
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_merge_summary(array $summary, array $batch_summary): array {
    foreach (['rows_grouped', 'rows_skipped_empty', 'rows_skipped_review', 'entries_created', 'entries_updated', 'entries_deleted', 'sources_updated', 'sources_replaced'] as $key) {
        $summary[$key] = (int) ($summary[$key] ?? 0) + (int) ($batch_summary[$key] ?? 0);
    }

    $summary['entry_ids'] = array_values(array_unique(array_filter(array_merge(
        array_map('intval', (array) ($summary['entry_ids'] ?? [])),
        array_map('intval', (array) ($batch_summary['entry_ids'] ?? []))
    ))));
    $summary['errors'] = array_values(array_filter(array_merge(
        array_map('strval', (array) ($summary['errors'] ?? [])),
        array_map('strval', (array) ($batch_summary['errors'] ?? []))
    )));
    $summary['error_count'] = count((array) $summary['errors']);

    return $summary;
}

/**
 * Render one import summary notice.
 *
 * @param array<string,mixed> $summary
 */
function ll_tools_get_dictionary_import_summary_html(array $summary, string $heading): string {
    $entries_created = (int) ($summary['entries_created'] ?? 0);
    $entries_updated = (int) ($summary['entries_updated'] ?? 0);
    $rows_total = (int) ($summary['rows_total'] ?? 0);
    $rows_grouped = (int) ($summary['rows_grouped'] ?? 0);
    $rows_skipped_empty = (int) ($summary['rows_skipped_empty'] ?? 0);
    $rows_skipped_review = (int) ($summary['rows_skipped_review'] ?? 0);
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($summary['entry_ids'] ?? [])))));
    $errors = array_values(array_filter(array_map('strval', (array) ($summary['errors'] ?? []))));

    ob_start();
    $notice_class = empty($errors) ? 'notice-success' : 'notice-warning';
    echo '<div class="notice ' . esc_attr($notice_class) . '"><p><strong>' . esc_html($heading) . '</strong></p>';
    echo '<p>' . esc_html(sprintf(
        /* translators: 1: total processed rows, 2: grouped entry count, 3: created entries, 4: updated entries */
        __('Processed %1$d rows into %2$d dictionary headwords. Created: %3$d. Updated: %4$d.', 'll-tools-text-domain'),
        $rows_total,
        $rows_grouped,
        $entries_created,
        $entries_updated
    )) . '</p>';

    if ($rows_skipped_empty > 0 || $rows_skipped_review > 0) {
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: skipped empty rows, 2: skipped review-flagged rows */
            __('Skipped empty rows: %1$d. Skipped review-flagged rows: %2$d.', 'll-tools-text-domain'),
            $rows_skipped_empty,
            $rows_skipped_review
        )) . '</p>';
    }

    if (!empty($entry_ids)) {
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: count of touched dictionary entries */
            _n('%d dictionary entry was touched.', '%d dictionary entries were touched.', count($entry_ids), 'll-tools-text-domain'),
            count($entry_ids)
        )) . '</p>';
    }

    if (!empty($errors)) {
        echo '<p>' . esc_html__('Some rows could not be imported:', 'll-tools-text-domain') . '</p><ul style="list-style:disc;padding-left:20px;">';
        foreach (array_slice($errors, 0, 8) as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        if (count($errors) > 8) {
            echo '<li>' . esc_html(sprintf(
                /* translators: %d: number of additional hidden errors */
                __('%d more errors not shown.', 'll-tools-text-domain'),
                count($errors) - 8
            )) . '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';

    return (string) ob_get_clean();
}

/**
 * Render one import summary notice.
 *
 * @param array<string,mixed> $summary
 */
function ll_tools_render_dictionary_import_summary(array $summary, string $heading): void {
    echo ll_tools_get_dictionary_import_summary_html($summary, $heading);
}

function ll_tools_dictionary_handle_export_snapshot(): void {
    if (!ll_tools_current_user_can_dictionary_import()) {
        wp_die(__('You do not have permission to export the dictionary snapshot.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_tools_dictionary_export_snapshot', 'll_dictionary_export_snapshot_nonce');

    $snapshot = ll_tools_dictionary_build_snapshot();
    $json = ll_tools_dictionary_encode_snapshot($snapshot);
    if (is_wp_error($json)) {
        wp_die(esc_html($json->get_error_message()));
    }

    $filename = sprintf(
        'll-tools-dictionary-%s.json',
        gmdate('Ymd-His')
    );

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . strlen($json));

    echo $json;
    exit;
}

function ll_tools_dictionary_import_generate_job_id(): string {
    return wp_generate_password(12, false, false) . '-' . time();
}

function ll_tools_dictionary_import_get_active_job_id(): string {
    return sanitize_text_field((string) get_option(LL_TOOLS_DICTIONARY_IMPORT_ACTIVE_JOB_OPTION, ''));
}

function ll_tools_dictionary_import_set_active_job_id(string $job_id): void {
    update_option(LL_TOOLS_DICTIONARY_IMPORT_ACTIVE_JOB_OPTION, sanitize_text_field($job_id), false);
}

function ll_tools_dictionary_import_clear_active_job_id(string $job_id = ''): void {
    $current = ll_tools_dictionary_import_get_active_job_id();
    if ($job_id !== '' && $current !== '' && $current !== $job_id) {
        return;
    }
    delete_option(LL_TOOLS_DICTIONARY_IMPORT_ACTIVE_JOB_OPTION);
}

function ll_tools_dictionary_import_get_last_job_id(int $user_id = 0): string {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return '';
    }

    return sanitize_text_field((string) get_user_meta($user_id, LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY, true));
}

function ll_tools_dictionary_import_set_last_job_id(string $job_id, int $user_id = 0): void {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    update_user_meta($user_id, LL_TOOLS_DICTIONARY_IMPORT_LAST_JOB_META_KEY, sanitize_text_field($job_id));
}

function ll_tools_dictionary_import_get_job_option_key(string $job_id): string {
    return LL_TOOLS_DICTIONARY_IMPORT_JOB_OPTION_PREFIX . sanitize_key(str_replace('-', '_', $job_id));
}

/**
 * @return array<string,mixed>|null
 */
function ll_tools_dictionary_import_get_job(string $job_id): ?array {
    $job_id = trim(sanitize_text_field($job_id));
    if ($job_id === '') {
        return null;
    }

    $job = get_option(ll_tools_dictionary_import_get_job_option_key($job_id), null);
    return is_array($job) ? $job : null;
}

/**
 * @param array<string,mixed> $job
 */
function ll_tools_dictionary_import_save_job(string $job_id, array $job): array {
    $job['id'] = $job_id;
    $job['updated_at'] = time();
    update_option(ll_tools_dictionary_import_get_job_option_key($job_id), $job, false);

    return $job;
}

function ll_tools_dictionary_import_get_base_dir(): string {
    $upload_dir = wp_get_upload_dir();
    $base_dir = trailingslashit((string) ($upload_dir['basedir'] ?? '')) . 'll-tools-dictionary-imports';
    if (!is_dir($base_dir)) {
        wp_mkdir_p($base_dir);
    }

    return $base_dir;
}

function ll_tools_dictionary_import_get_job_dir(string $job_id): string {
    return trailingslashit(ll_tools_dictionary_import_get_base_dir()) . sanitize_file_name($job_id);
}

function ll_tools_dictionary_import_delete_path(string $path): void {
    $path = trim($path);
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
        return;
    }

    $items = @scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            ll_tools_dictionary_import_delete_path(trailingslashit($path) . $item);
        }
    }

    @rmdir($path);
}

/**
 * @param array<int,mixed> $items
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_write_chunks(array $items, string $job_id, int $chunk_size = 25, string $filename_prefix = 'chunk') {
    $chunk_size = max(1, min(200, $chunk_size));
    $job_dir = ll_tools_dictionary_import_get_job_dir($job_id);
    if (!is_dir($job_dir) && !wp_mkdir_p($job_dir)) {
        return new WP_Error('ll_tools_dictionary_import_job_dir_failed', __('Could not create the temporary import directory.', 'll-tools-text-domain'));
    }

    $chunk_files = [];
    $item_count = 0;
    $chunk_index = 0;
    foreach (array_chunk($items, $chunk_size) as $chunk_items) {
        $chunk_index++;
        $item_count += count($chunk_items);

        $filename = sprintf('%s-%05d.json', sanitize_file_name($filename_prefix), $chunk_index);
        $path = trailingslashit($job_dir) . $filename;
        $payload = wp_json_encode($chunk_items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || $payload === '') {
            return new WP_Error('ll_tools_dictionary_import_chunk_encode_failed', __('Could not prepare the import chunks.', 'll-tools-text-domain'));
        }
        if (file_put_contents($path, $payload) === false) {
            return new WP_Error('ll_tools_dictionary_import_chunk_write_failed', __('Could not write the import chunk file.', 'll-tools-text-domain'));
        }
        $chunk_files[] = $filename;
    }

    return [
        'job_dir' => $job_dir,
        'chunk_files' => $chunk_files,
        'item_count' => $item_count,
    ];
}

/**
 * @param array<int,array<int,array<string,mixed>>> $grouped_rows
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_write_group_chunks(array $grouped_rows, string $job_id, int $chunk_size = 25) {
    $chunk_result = ll_tools_dictionary_import_write_chunks($grouped_rows, $job_id, $chunk_size, 'chunk');
    if (is_wp_error($chunk_result)) {
        return $chunk_result;
    }

    $total_rows = 0;
    foreach ($grouped_rows as $group_rows) {
        $total_rows += is_array($group_rows) ? count($group_rows) : 0;
    }

    return [
        'job_dir' => (string) ($chunk_result['job_dir'] ?? ''),
        'chunk_files' => array_values(array_map('strval', (array) ($chunk_result['chunk_files'] ?? []))),
        'processable_rows' => $total_rows,
    ];
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_backup_snapshot(string $job_id) {
    $snapshot_dir = ll_tools_dictionary_snapshot_get_dir();
    if (!is_dir($snapshot_dir) && !wp_mkdir_p($snapshot_dir)) {
        return new WP_Error('ll_tools_dictionary_backup_dir_failed', __('Could not create the dictionary snapshot backup directory.', 'll-tools-text-domain'));
    }

    $path = trailingslashit($snapshot_dir) . sprintf(
        'dictionary-backup-%s-%s.json',
        sanitize_file_name($job_id),
        gmdate('Ymd-His')
    );

    $snapshot = ll_tools_dictionary_build_snapshot();
    $write_result = ll_tools_dictionary_write_snapshot_file($path, $snapshot);
    if (is_wp_error($write_result)) {
        return $write_result;
    }

    return [
        'path' => $path,
        'entry_count' => (int) ($snapshot['entry_count'] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_attach_backup_snapshot(array $job): array {
    $backup = ll_tools_dictionary_import_create_backup_snapshot((string) ($job['id'] ?? ''));
    if (is_wp_error($backup)) {
        $job['backup_snapshot_path'] = '';
        $job['backup_snapshot_error'] = $backup->get_error_message();
        return $job;
    }

    $job['backup_snapshot_path'] = (string) ($backup['path'] ?? '');
    $job['backup_snapshot_error'] = '';
    $job['backup_entry_count'] = (int) ($backup['entry_count'] ?? 0);

    return $job;
}

function ll_tools_dictionary_import_get_snapshot_manifest_path(string $job_dir): string {
    return trailingslashit($job_dir) . 'snapshot-manifest.json';
}

/**
 * @param array<string,mixed> $manifest
 * @return string|WP_Error
 */
function ll_tools_dictionary_import_write_snapshot_manifest(string $job_dir, array $manifest) {
    if (!is_dir($job_dir) && !wp_mkdir_p($job_dir)) {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_dir_failed', __('Could not create the dictionary snapshot manifest directory.', 'll-tools-text-domain'));
    }

    $path = ll_tools_dictionary_import_get_snapshot_manifest_path($job_dir);
    $payload = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_encode_failed', __('Could not encode the dictionary snapshot manifest.', 'll-tools-text-domain'));
    }

    if (file_put_contents($path, $payload) === false) {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_write_failed', __('Could not write the dictionary snapshot manifest.', 'll-tools-text-domain'));
    }

    return $path;
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_read_snapshot_manifest(array $job) {
    $job_dir = trim((string) ($job['job_dir'] ?? ''));
    $path = ll_tools_dictionary_import_get_snapshot_manifest_path($job_dir);
    if (!is_readable($path)) {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_missing', __('The dictionary snapshot manifest could not be found.', 'll-tools-text-domain'));
    }

    $payload = file_get_contents($path);
    if (!is_string($payload) || $payload === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_read_failed', __('The dictionary snapshot manifest could not be read.', 'll-tools-text-domain'));
    }

    $manifest = json_decode($payload, true);
    if (!is_array($manifest)) {
        return new WP_Error('ll_tools_dictionary_snapshot_manifest_invalid', __('The dictionary snapshot manifest is invalid.', 'll-tools-text-domain'));
    }

    return $manifest;
}

/**
 * @param array<string,mixed> $snapshot
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_snapshot_job_from_snapshot(array $snapshot, array $options, string $original_filename = '') {
    $entries = array_values(array_filter((array) ($snapshot['entries'] ?? []), static function ($entry): bool {
        return is_array($entry);
    }));
    $sources = array_values(ll_tools_dictionary_sanitize_source_registry($snapshot['sources'] ?? []));
    $snapshot_mode = isset($options['snapshot_mode']) && sanitize_key((string) $options['snapshot_mode']) === 'override'
        ? 'override'
        : 'merge';

    $job_id = ll_tools_dictionary_import_generate_job_id();
    $chunk_result = ll_tools_dictionary_import_write_chunks($entries, $job_id, 25, 'snapshot');
    if (is_wp_error($chunk_result)) {
        return $chunk_result;
    }

    $manifest_result = ll_tools_dictionary_import_write_snapshot_manifest(
        (string) ($chunk_result['job_dir'] ?? ''),
        [
            'entry_keys' => ll_tools_dictionary_snapshot_collect_entry_keys($entries),
            'sources' => $sources,
        ]
    );
    if (is_wp_error($manifest_result)) {
        ll_tools_dictionary_import_delete_path((string) ($chunk_result['job_dir'] ?? ''));
        return $manifest_result;
    }

    $user_id = get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : null;
    $job = [
        'id' => $job_id,
        'type' => 'snapshot',
        'status' => empty($entries) ? 'completed' : 'running',
        'created_at' => time(),
        'updated_at' => time(),
        'user_id' => $user_id,
        'user_label' => $user instanceof WP_User ? (string) $user->display_name : '',
        'original_filename' => trim(sanitize_text_field($original_filename)),
        'options' => $options,
        'snapshot_mode' => $snapshot_mode,
        'summary' => ll_tools_dictionary_import_default_summary(count($entries)),
        'job_dir' => (string) ($chunk_result['job_dir'] ?? ''),
        'chunk_files' => array_values(array_map('strval', (array) ($chunk_result['chunk_files'] ?? []))),
        'current_index' => 0,
        'total_chunks' => count((array) ($chunk_result['chunk_files'] ?? [])),
        'total_entries' => count($entries),
        'processed_entries' => empty($entries) ? count($entries) : 0,
        'snapshot_source_count' => count($sources),
        'error_message' => '',
    ];

    $job = ll_tools_dictionary_import_attach_backup_snapshot($job);

    if ($job['status'] === 'completed') {
        $source_summary = ll_tools_dictionary_apply_snapshot_sources($sources, $snapshot_mode === 'override' ? 'override' : 'merge');
        $job['summary'] = ll_tools_dictionary_import_merge_summary($job['summary'], $source_summary);
        if ($snapshot_mode === 'override') {
            $job['summary']['entries_deleted'] = ll_tools_dictionary_delete_entries_missing_keys(
                ll_tools_dictionary_snapshot_collect_entry_keys($entries)
            );
        }
        ll_tools_dictionary_import_delete_path((string) $job['job_dir']);
    } else {
        ll_tools_dictionary_import_set_active_job_id($job_id);
    }

    ll_tools_dictionary_import_save_job($job_id, $job);
    ll_tools_dictionary_import_set_last_job_id($job_id, $user_id);

    return $job;
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_snapshot_job_from_file(string $file_path, array $options, string $original_filename = '') {
    $snapshot = ll_tools_dictionary_parse_snapshot_file($file_path);
    if (is_wp_error($snapshot)) {
        return $snapshot;
    }

    return ll_tools_dictionary_import_create_snapshot_job_from_snapshot($snapshot, $options, $original_filename);
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_snapshot_job_from_upload(array $options) {
    $tmp_name = isset($_FILES['ll_dictionary_snapshot']['tmp_name']) ? (string) $_FILES['ll_dictionary_snapshot']['tmp_name'] : '';
    $filename = isset($_FILES['ll_dictionary_snapshot']['name']) ? sanitize_file_name(wp_unslash((string) $_FILES['ll_dictionary_snapshot']['name'])) : '';

    return ll_tools_dictionary_import_create_snapshot_job_from_file($tmp_name, $options, $filename);
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_undo_job(string $history_id) {
    $history_entries = ll_tools_dictionary_import_read_history();
    $history_entry = null;
    $history_index = -1;
    foreach ($history_entries as $index => $entry) {
        if (!is_array($entry) || (string) ($entry['id'] ?? '') !== sanitize_text_field($history_id)) {
            continue;
        }
        $history_entry = $entry;
        $history_index = (int) $index;
        break;
    }

    if (!is_array($history_entry)) {
        return new WP_Error('ll_tools_dictionary_undo_missing_history', __('The requested dictionary import history entry could not be found.', 'll-tools-text-domain'));
    }
    if (!ll_tools_dictionary_import_can_undo_history_entry($history_entry, $history_index)) {
        return new WP_Error('ll_tools_dictionary_undo_unavailable', __('Only the latest completed dictionary import can be undone from this screen.', 'll-tools-text-domain'));
    }

    $backup_snapshot_path = trim((string) ($history_entry['backup_snapshot_path'] ?? ''));
    if ($backup_snapshot_path === '' || !is_readable($backup_snapshot_path)) {
        return new WP_Error('ll_tools_dictionary_undo_missing_backup', __('This dictionary import can no longer be undone because its backup snapshot is unavailable.', 'll-tools-text-domain'));
    }

    return ll_tools_dictionary_import_create_snapshot_job_from_file($backup_snapshot_path, [
        'snapshot_mode' => 'override',
        'history_mode' => 'undo',
        'undo_history_id' => sanitize_text_field($history_id),
    ], basename($backup_snapshot_path));
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_tsv_job_from_rows(array $rows, array $options, string $original_filename = '') {
    $grouping = function_exists('ll_tools_dictionary_group_import_rows')
        ? ll_tools_dictionary_group_import_rows($rows, $options)
        : ['grouped_rows' => [], 'summary' => ll_tools_dictionary_import_default_summary(count($rows))];

    $grouped_rows = isset($grouping['grouped_rows']) && is_array($grouping['grouped_rows']) ? $grouping['grouped_rows'] : [];
    $summary = isset($grouping['summary']) && is_array($grouping['summary']) ? $grouping['summary'] : ll_tools_dictionary_import_default_summary(count($rows));

    $job_id = ll_tools_dictionary_import_generate_job_id();
    $chunk_result = ll_tools_dictionary_import_write_group_chunks($grouped_rows, $job_id);
    if (is_wp_error($chunk_result)) {
        return $chunk_result;
    }

    $user_id = get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : null;
    $job = [
        'id' => $job_id,
        'type' => 'tsv',
        'status' => empty($grouped_rows) ? 'completed' : 'running',
        'created_at' => time(),
        'updated_at' => time(),
        'user_id' => $user_id,
        'user_label' => $user instanceof WP_User ? (string) $user->display_name : '',
        'original_filename' => trim(sanitize_text_field($original_filename)),
        'options' => $options,
        'summary' => $summary,
        'job_dir' => (string) ($chunk_result['job_dir'] ?? ''),
        'chunk_files' => array_values(array_map('strval', (array) ($chunk_result['chunk_files'] ?? []))),
        'current_index' => 0,
        'total_chunks' => count((array) ($chunk_result['chunk_files'] ?? [])),
        'total_groups' => (int) ($summary['rows_grouped'] ?? 0),
        'processed_groups' => empty($grouped_rows) ? (int) ($summary['rows_grouped'] ?? 0) : 0,
        'processable_rows' => (int) ($chunk_result['processable_rows'] ?? 0),
        'processed_rows' => empty($grouped_rows) ? (int) ($chunk_result['processable_rows'] ?? 0) : 0,
        'error_message' => '',
    ];

    if ($job['status'] === 'completed') {
        ll_tools_dictionary_import_delete_path((string) $job['job_dir']);
    } else {
        ll_tools_dictionary_import_set_active_job_id($job_id);
    }

    $job = ll_tools_dictionary_import_attach_backup_snapshot($job);
    ll_tools_dictionary_import_save_job($job_id, $job);
    ll_tools_dictionary_import_set_last_job_id($job_id, $user_id);

    return $job;
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_tsv_job_from_upload(array $options) {
    $tmp_name = isset($_FILES['ll_dictionary_tsv']['tmp_name']) ? (string) $_FILES['ll_dictionary_tsv']['tmp_name'] : '';
    $rows = ll_tools_dictionary_parse_tsv_file($tmp_name);
    if (is_wp_error($rows)) {
        return $rows;
    }

    $filename = isset($_FILES['ll_dictionary_tsv']['name']) ? sanitize_file_name(wp_unslash((string) $_FILES['ll_dictionary_tsv']['name'])) : '';

    return ll_tools_dictionary_import_create_tsv_job_from_rows($rows, $options, $filename);
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_create_legacy_job(array $options) {
    global $wpdb;

    if (!function_exists('ll_tools_dictionary_legacy_table_exists') || !ll_tools_dictionary_legacy_table_exists()) {
        return new WP_Error('ll_tools_dictionary_legacy_table_missing', __('Legacy dictionary table not found.', 'll-tools-text-domain'));
    }

    $table = ll_tools_dictionary_get_legacy_table_name();
    $total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    $job_id = ll_tools_dictionary_import_generate_job_id();
    $user_id = get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : null;
    $job = [
        'id' => $job_id,
        'type' => 'legacy',
        'status' => $total_rows > 0 ? 'running' : 'completed',
        'created_at' => time(),
        'updated_at' => time(),
        'user_id' => $user_id,
        'user_label' => $user instanceof WP_User ? (string) $user->display_name : '',
        'original_filename' => '',
        'options' => $options,
        'summary' => ll_tools_dictionary_import_default_summary($total_rows),
        'legacy_offset' => 0,
        'legacy_batch_size' => max(50, min(1000, (int) ($options['batch_size'] ?? 500))),
        'total_rows' => $total_rows,
        'processed_rows' => $total_rows > 0 ? 0 : $total_rows,
        'error_message' => '',
    ];

    if ($job['status'] === 'running') {
        ll_tools_dictionary_import_set_active_job_id($job_id);
    }

    $job = ll_tools_dictionary_import_attach_backup_snapshot($job);
    ll_tools_dictionary_import_save_job($job_id, $job);
    ll_tools_dictionary_import_set_last_job_id($job_id, $user_id);

    return $job;
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_process_tsv_job(array $job) {
    $job_id = (string) ($job['id'] ?? '');
    $chunk_files = array_values(array_map('strval', (array) ($job['chunk_files'] ?? [])));
    $current_index = max(0, (int) ($job['current_index'] ?? 0));
    if ($job_id === '' || empty($chunk_files) || $current_index >= count($chunk_files)) {
        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
        return $job;
    }

    $chunk_filename = $chunk_files[$current_index];
    $chunk_path = trailingslashit((string) ($job['job_dir'] ?? '')) . $chunk_filename;
    if (!is_readable($chunk_path)) {
        return new WP_Error('ll_tools_dictionary_import_chunk_missing', __('The next import chunk could not be found.', 'll-tools-text-domain'));
    }

    $payload = file_get_contents($chunk_path);
    if (!is_string($payload) || $payload === '') {
        return new WP_Error('ll_tools_dictionary_import_chunk_read_failed', __('The next import chunk could not be read.', 'll-tools-text-domain'));
    }

    $chunk_groups = json_decode($payload, true);
    if (!is_array($chunk_groups)) {
        return new WP_Error('ll_tools_dictionary_import_chunk_decode_failed', __('The next import chunk is invalid JSON.', 'll-tools-text-domain'));
    }

    $batch_summary = function_exists('ll_tools_dictionary_apply_grouped_import_rows')
        ? ll_tools_dictionary_apply_grouped_import_rows($chunk_groups, (array) ($job['options'] ?? []))
        : ll_tools_dictionary_import_default_summary();
    $job['summary'] = ll_tools_dictionary_import_merge_summary(
        is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary(),
        is_array($batch_summary) ? $batch_summary : []
    );

    $batch_group_count = count($chunk_groups);
    $batch_row_count = 0;
    foreach ($chunk_groups as $group_rows) {
        $batch_row_count += is_array($group_rows) ? count($group_rows) : 0;
    }

    $job['processed_groups'] = max(0, (int) ($job['processed_groups'] ?? 0)) + $batch_group_count;
    $job['processed_rows'] = max(0, (int) ($job['processed_rows'] ?? 0)) + $batch_row_count;
    $job['current_index'] = $current_index + 1;

    @unlink($chunk_path);

    if ((int) $job['current_index'] >= max(0, (int) ($job['total_chunks'] ?? 0))) {
        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
    }

    return $job;
}

/**
 * @return array<int,array<string,string>>
 */
function ll_tools_dictionary_import_get_legacy_rows_batch(int $offset, int $batch_size): array {
    global $wpdb;

    $table = ll_tools_dictionary_get_legacy_table_name();

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT entry, definition, gender_number, entry_type, parent, needs_review, page_number, entry_lang, def_lang
             FROM {$table}
             ORDER BY entry ASC, id ASC
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ),
        ARRAY_A
    );
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_process_legacy_job(array $job) {
    if (!function_exists('ll_tools_dictionary_legacy_table_exists') || !ll_tools_dictionary_legacy_table_exists()) {
        return new WP_Error('ll_tools_dictionary_legacy_table_missing', __('Legacy dictionary table not found.', 'll-tools-text-domain'));
    }

    $offset = max(0, (int) ($job['legacy_offset'] ?? 0));
    $batch_size = max(50, min(1000, (int) ($job['legacy_batch_size'] ?? 500)));
    $total_rows = max(0, (int) ($job['total_rows'] ?? 0));

    if ($offset >= $total_rows) {
        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id((string) ($job['id'] ?? ''));
        return $job;
    }

    $rows = ll_tools_dictionary_import_get_legacy_rows_batch($offset, $batch_size);
    if (empty($rows)) {
        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id((string) ($job['id'] ?? ''));
        return $job;
    }

    $batch_summary = function_exists('ll_tools_dictionary_import_rows')
        ? ll_tools_dictionary_import_rows($rows, (array) ($job['options'] ?? []))
        : ll_tools_dictionary_import_default_summary(count($rows));
    $job['summary'] = ll_tools_dictionary_import_merge_summary(
        is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary($total_rows),
        is_array($batch_summary) ? $batch_summary : []
    );
    $job['processed_rows'] = min($total_rows, max(0, (int) ($job['processed_rows'] ?? 0)) + count($rows));
    $job['legacy_offset'] = $offset + count($rows);

    if ((int) $job['legacy_offset'] >= $total_rows) {
        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id((string) ($job['id'] ?? ''));
    }

    return $job;
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_process_snapshot_job(array $job) {
    $job_id = (string) ($job['id'] ?? '');
    $chunk_files = array_values(array_map('strval', (array) ($job['chunk_files'] ?? [])));
    $current_index = max(0, (int) ($job['current_index'] ?? 0));
    if ($job_id === '' || empty($chunk_files) || $current_index >= count($chunk_files)) {
        $manifest = ll_tools_dictionary_import_read_snapshot_manifest($job);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        $source_summary = ll_tools_dictionary_apply_snapshot_sources(
            (array) ($manifest['sources'] ?? []),
            sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override' ? 'override' : 'merge'
        );
        $job['summary'] = ll_tools_dictionary_import_merge_summary(
            is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary(),
            $source_summary
        );

        if (sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override') {
            $job['summary']['entries_deleted'] = (int) ($job['summary']['entries_deleted'] ?? 0)
                + ll_tools_dictionary_delete_entries_missing_keys((array) ($manifest['entry_keys'] ?? []));
        }

        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
        return $job;
    }

    $chunk_filename = $chunk_files[$current_index];
    $chunk_path = trailingslashit((string) ($job['job_dir'] ?? '')) . $chunk_filename;
    if (!is_readable($chunk_path)) {
        return new WP_Error('ll_tools_dictionary_snapshot_chunk_missing', __('The next dictionary snapshot chunk could not be found.', 'll-tools-text-domain'));
    }

    $payload = file_get_contents($chunk_path);
    if (!is_string($payload) || $payload === '') {
        return new WP_Error('ll_tools_dictionary_snapshot_chunk_read_failed', __('The next dictionary snapshot chunk could not be read.', 'll-tools-text-domain'));
    }

    $chunk_entries = json_decode($payload, true);
    if (!is_array($chunk_entries)) {
        return new WP_Error('ll_tools_dictionary_snapshot_chunk_invalid', __('The next dictionary snapshot chunk is invalid JSON.', 'll-tools-text-domain'));
    }

    $batch_summary = ll_tools_dictionary_import_snapshot_entries($chunk_entries, (array) ($job['options'] ?? []));
    $job['summary'] = ll_tools_dictionary_import_merge_summary(
        is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary(),
        is_array($batch_summary) ? $batch_summary : []
    );

    $job['processed_entries'] = max(0, (int) ($job['processed_entries'] ?? 0)) + count($chunk_entries);
    $job['current_index'] = $current_index + 1;

    @unlink($chunk_path);

    if ((int) $job['current_index'] >= max(0, (int) ($job['total_chunks'] ?? 0))) {
        $manifest = ll_tools_dictionary_import_read_snapshot_manifest($job);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        $source_summary = ll_tools_dictionary_apply_snapshot_sources(
            (array) ($manifest['sources'] ?? []),
            sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override' ? 'override' : 'merge'
        );
        $job['summary'] = ll_tools_dictionary_import_merge_summary($job['summary'], $source_summary);

        if (sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override') {
            $job['summary']['entries_deleted'] = (int) ($job['summary']['entries_deleted'] ?? 0)
                + ll_tools_dictionary_delete_entries_missing_keys((array) ($manifest['entry_keys'] ?? []));
        }

        $job['status'] = 'completed';
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
    }

    return $job;
}

/**
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_process_job(array $job) {
    $type = sanitize_key((string) ($job['type'] ?? ''));
    $processed_job = null;

    if ($type === 'legacy') {
        $processed_job = ll_tools_dictionary_import_process_legacy_job($job);
    } elseif ($type === 'snapshot') {
        $processed_job = ll_tools_dictionary_import_process_snapshot_job($job);
    } else {
        $processed_job = ll_tools_dictionary_import_process_tsv_job($job);
    }

    if (is_wp_error($processed_job)) {
        return $processed_job;
    }

    return ll_tools_dictionary_import_finalize_job_history($processed_job);
}

function ll_tools_dictionary_import_get_job_heading(array $job): string {
    $type = sanitize_key((string) ($job['type'] ?? 'tsv'));
    $history_mode = sanitize_key((string) ($job['options']['history_mode'] ?? ''));
    if ($history_mode === 'undo') {
        return __('Dictionary undo restore completed.', 'll-tools-text-domain');
    }
    if ($type === 'legacy') {
        return __('Legacy dictionary migration completed.', 'll-tools-text-domain');
    }
    if ($type === 'snapshot') {
        return sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override'
            ? __('Dictionary snapshot override completed.', 'll-tools-text-domain')
            : __('Dictionary snapshot import completed.', 'll-tools-text-domain');
    }

    return __('Dictionary TSV import completed.', 'll-tools-text-domain');
}

function ll_tools_dictionary_import_get_job_summary_html(array $job): string {
    $summary = is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary();
    $heading = ll_tools_dictionary_import_get_job_heading($job);
    $type = sanitize_key((string) ($job['type'] ?? 'tsv'));

    if ($type === 'snapshot') {
        $entries_total = max(0, (int) ($job['total_entries'] ?? ($summary['rows_total'] ?? 0)));
        $entries_created = (int) ($summary['entries_created'] ?? 0);
        $entries_updated = (int) ($summary['entries_updated'] ?? 0);
        $entries_deleted = (int) ($summary['entries_deleted'] ?? 0);
        $sources_updated = (int) ($summary['sources_updated'] ?? 0);
        $sources_replaced = (int) ($summary['sources_replaced'] ?? 0);
        $errors = array_values(array_filter(array_map('strval', (array) ($summary['errors'] ?? []))));

        ob_start();
        $notice_class = empty($errors) ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr($notice_class) . '"><p><strong>' . esc_html($heading) . '</strong></p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: processed entries, 2: created entries, 3: updated entries, 4: deleted entries */
            __('Processed %1$d dictionary entries. Created: %2$d. Updated: %3$d. Deleted: %4$d.', 'll-tools-text-domain'),
            $entries_total,
            $entries_created,
            $entries_updated,
            $entries_deleted
        )) . '</p>';

        if ($sources_updated > 0 || $sources_replaced > 0) {
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: updated sources count, 2: replaced sources count */
                __('Dictionary sources updated: %1$d. Source registry replaced entries: %2$d.', 'll-tools-text-domain'),
                $sources_updated,
                $sources_replaced
            )) . '</p>';
        }

        if (!empty($errors)) {
            echo '<p>' . esc_html__('Some snapshot entries could not be imported:', 'll-tools-text-domain') . '</p><ul style="list-style:disc;padding-left:20px;">';
            foreach (array_slice($errors, 0, 8) as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            if (count($errors) > 8) {
                echo '<li>' . esc_html(sprintf(
                    /* translators: %d: number of additional hidden errors */
                    __('%d more errors not shown.', 'll-tools-text-domain'),
                    count($errors) - 8
                )) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    return ll_tools_get_dictionary_import_summary_html($summary, $heading);
}

/**
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_build_history_entry(array $job): array {
    $summary = is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary();
    $type = sanitize_key((string) ($job['type'] ?? 'tsv'));
    $history_mode = sanitize_key((string) ($job['options']['history_mode'] ?? ''));
    $source_label = trim((string) ($job['original_filename'] ?? ''));
    if ($source_label === '') {
        if ($history_mode === 'undo') {
            $source_label = __('Undo restore', 'll-tools-text-domain');
        } elseif ($type === 'legacy') {
            $source_label = __('Legacy dictionary table', 'll-tools-text-domain');
        } elseif ($type === 'snapshot') {
            $source_label = __('Dictionary snapshot', 'll-tools-text-domain');
        } else {
            $source_label = __('Dictionary TSV', 'll-tools-text-domain');
        }
    }

    return [
        'id' => sanitize_text_field((string) ($job['history_entry_id'] ?? wp_generate_uuid4())),
        'job_id' => (string) ($job['id'] ?? ''),
        'type' => $type,
        'history_mode' => $history_mode,
        'source_label' => $source_label,
        'ok' => sanitize_key((string) ($job['status'] ?? '')) === 'completed',
        'finished_at' => time(),
        'summary' => $summary,
        'backup_snapshot_path' => trim((string) ($job['backup_snapshot_path'] ?? '')),
        'backup_snapshot_error' => trim((string) ($job['backup_snapshot_error'] ?? '')),
        'undone_at' => 0,
    ];
}

/**
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_finalize_job_history(array $job): array {
    if (sanitize_key((string) ($job['status'] ?? '')) === 'running' || !empty($job['history_recorded_at'])) {
        return $job;
    }

    $history_entry = ll_tools_dictionary_import_build_history_entry($job);
    $history_id = ll_tools_dictionary_import_append_history_entry($history_entry);
    $job['history_entry_id'] = $history_id;
    $job['history_recorded_at'] = time();

    $source_history_id = sanitize_text_field((string) ($job['options']['undo_history_id'] ?? ''));
    if ($source_history_id !== '' && sanitize_key((string) ($job['options']['history_mode'] ?? '')) === 'undo' && sanitize_key((string) ($job['status'] ?? '')) === 'completed') {
        ll_tools_dictionary_import_update_history_entry($source_history_id, [
            'undone_at' => time(),
            'undo_job_id' => (string) ($job['id'] ?? ''),
        ]);
    }

    return $job;
}

function ll_tools_dictionary_import_history_summary_text(array $summary): string {
    return sprintf(
        /* translators: 1: created entries, 2: updated entries, 3: deleted entries */
        __('Created: %1$d | Updated: %2$d | Deleted: %3$d', 'll-tools-text-domain'),
        (int) ($summary['entries_created'] ?? 0),
        (int) ($summary['entries_updated'] ?? 0),
        (int) ($summary['entries_deleted'] ?? 0)
    );
}

function ll_tools_dictionary_import_can_undo_history_entry(array $entry, int $index = -1): bool {
    $backup_path = trim((string) ($entry['backup_snapshot_path'] ?? ''));
    if ($backup_path === '' || !is_readable($backup_path)) {
        return false;
    }

    if (!empty($entry['undone_at'])) {
        return false;
    }

    if ($index !== 0) {
        return false;
    }

    return true;
}

function ll_tools_render_dictionary_import_history_section(array $entries): void {
    ?>
    <div class="ll-dictionary-import-admin__history">
        <h2><?php esc_html_e('Recent Dictionary Imports', 'll-tools-text-domain'); ?></h2>
        <p class="description"><?php esc_html_e('Undo is available for the latest completed dictionary import while its backup snapshot still exists. Undo restores the whole dictionary using the same resumable batch importer.', 'll-tools-text-domain'); ?></p>

        <?php if (empty($entries)) : ?>
            <p class="description"><?php esc_html_e('No dictionary import history found yet.', 'll-tools-text-domain'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Type', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Source', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Changes', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Action', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $index => $entry) : ?>
                        <?php
                        if (!is_array($entry)) {
                            continue;
                        }

                        $finished_at = (int) ($entry['finished_at'] ?? 0);
                        $type = sanitize_key((string) ($entry['type'] ?? 'tsv'));
                        $history_mode = sanitize_key((string) ($entry['history_mode'] ?? ''));
                        $source_label = trim((string) ($entry['source_label'] ?? ''));
                        $summary = is_array($entry['summary'] ?? null) ? $entry['summary'] : [];
                        $can_undo = ll_tools_dictionary_import_can_undo_history_entry($entry, (int) $index);
                        $time_text = $finished_at > 0
                            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $finished_at)
                            : __('Unknown', 'll-tools-text-domain');
                        $type_label = $history_mode === 'undo'
                            ? __('Undo restore', 'll-tools-text-domain')
                            : ($type === 'legacy'
                                ? __('Legacy migration', 'll-tools-text-domain')
                                : ($type === 'snapshot' ? __('Snapshot import', 'll-tools-text-domain') : __('TSV import', 'll-tools-text-domain')));
                        $status_text = !empty($entry['undone_at'])
                            ? __('Undone', 'll-tools-text-domain')
                            : (!empty($entry['ok']) ? __('Completed', 'll-tools-text-domain') : __('Failed', 'll-tools-text-domain'));
                        $backup_error = trim((string) ($entry['backup_snapshot_error'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo esc_html($time_text); ?></td>
                            <td><?php echo esc_html($type_label . ' | ' . $status_text); ?></td>
                            <td><?php echo esc_html($source_label !== '' ? $source_label : __('(no source label)', 'll-tools-text-domain')); ?></td>
                            <td>
                                <?php echo esc_html(ll_tools_dictionary_import_history_summary_text($summary)); ?>
                                <?php if ($backup_error !== '') : ?>
                                    <div class="description"><?php echo esc_html(sprintf(__('Undo unavailable: %s', 'll-tools-text-domain'), $backup_error)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can_undo) : ?>
                                    <form method="post" class="ll-dictionary-import-admin__undo-form" data-ll-dictionary-job-form>
                                        <?php wp_nonce_field('ll_tools_dictionary_import', 'll_dictionary_import_nonce'); ?>
                                        <input type="hidden" name="ll_dictionary_action" value="undo_import">
                                        <input type="hidden" name="ll_dictionary_history_id" value="<?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">
                                        <?php submit_button(__('Undo Import', 'll-tools-text-domain'), 'secondary small', 'submit', false); ?>
                                    </form>
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e('Not available', 'll-tools-text-domain'); ?></span>
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
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_get_job_snapshot(array $job): array {
    $type = sanitize_key((string) ($job['type'] ?? 'tsv'));
    $status = sanitize_key((string) ($job['status'] ?? 'running'));
    $summary = is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary();
    $history_mode = sanitize_key((string) ($job['options']['history_mode'] ?? ''));

    if ($type === 'legacy') {
        $processed_units = max(0, (int) ($job['processed_rows'] ?? 0));
        $total_units = max(0, (int) ($job['total_rows'] ?? 0));
    } elseif ($type === 'snapshot') {
        $processed_units = max(0, (int) ($job['processed_entries'] ?? 0));
        $total_units = max(0, (int) ($job['total_entries'] ?? 0));
    } else {
        $processed_units = max(0, (int) ($job['processed_groups'] ?? 0));
        $total_units = max(0, (int) ($job['total_groups'] ?? 0));
    }

    $progress_percent = $total_units > 0 ? (int) floor(min(100, ($processed_units / $total_units) * 100)) : 100;
    if ($status === 'completed') {
        $progress_percent = 100;
    }

    if ($type === 'legacy') {
        $progress_text = sprintf(
            /* translators: 1: processed row count, 2: total row count */
            __('Processed %1$d of %2$d source rows.', 'll-tools-text-domain'),
            max(0, (int) ($job['processed_rows'] ?? 0)),
            max(0, (int) ($job['total_rows'] ?? 0))
        );
        $detail_text = sprintf(
            /* translators: 1: created entry count, 2: updated entry count, 3: skipped row count */
            __('Created %1$d, updated %2$d, skipped %3$d rows so far.', 'll-tools-text-domain'),
            max(0, (int) ($summary['entries_created'] ?? 0)),
            max(0, (int) ($summary['entries_updated'] ?? 0)),
            max(0, (int) ($summary['rows_skipped_empty'] ?? 0)) + max(0, (int) ($summary['rows_skipped_review'] ?? 0))
        );
    } elseif ($type === 'snapshot') {
        $progress_text = sprintf(
            /* translators: 1: processed dictionary entries, 2: total dictionary entries */
            __('Processed %1$d of %2$d dictionary entries.', 'll-tools-text-domain'),
            max(0, (int) ($job['processed_entries'] ?? 0)),
            max(0, (int) ($job['total_entries'] ?? 0))
        );
        $detail_text = sprintf(
            /* translators: 1: created entries, 2: updated entries, 3: deleted entries, 4: updated sources */
            __('Created %1$d, updated %2$d, deleted %3$d, sources updated %4$d so far.', 'll-tools-text-domain'),
            max(0, (int) ($summary['entries_created'] ?? 0)),
            max(0, (int) ($summary['entries_updated'] ?? 0)),
            max(0, (int) ($summary['entries_deleted'] ?? 0)),
            max(0, (int) ($summary['sources_updated'] ?? 0))
        );
    } else {
        $progress_text = sprintf(
            /* translators: 1: processed headword count, 2: total headword count */
            __('Imported %1$d of %2$d dictionary headwords.', 'll-tools-text-domain'),
            max(0, (int) ($job['processed_groups'] ?? 0)),
            max(0, (int) ($job['total_groups'] ?? 0))
        );
        $detail_text = sprintf(
            /* translators: 1: created entry count, 2: updated entry count, 3: skipped row count */
            __('Created %1$d, updated %2$d, skipped %3$d rows so far.', 'll-tools-text-domain'),
            max(0, (int) ($summary['entries_created'] ?? 0)),
            max(0, (int) ($summary['entries_updated'] ?? 0)),
            max(0, (int) ($summary['rows_skipped_empty'] ?? 0)) + max(0, (int) ($summary['rows_skipped_review'] ?? 0))
        );
    }

    $backup_warning = trim((string) ($job['backup_snapshot_error'] ?? ''));
    if ($backup_warning !== '') {
        $detail_text .= ' ' . sprintf(
            /* translators: %s: backup warning */
            __('Undo backup was not created: %s', 'll-tools-text-domain'),
            $backup_warning
        );
    }

    $advice_title = __('Keep This Window Open', 'll-tools-text-domain');
    $advice_text = __('This import runs in short batches from your browser. If you close this tab or navigate away, the job pauses. Reopen this screen to resume it.', 'll-tools-text-domain');
    if ($status === 'completed') {
        $advice_title = __('Safe To Close', 'll-tools-text-domain');
        $advice_text = __('The import finished successfully. You can close this window.', 'll-tools-text-domain');
    } elseif ($status === 'failed') {
        $advice_title = __('Import Paused', 'll-tools-text-domain');
        $advice_text = __('The import stopped because of an error. Fix the problem and reopen this screen to try again.', 'll-tools-text-domain');
    }

    return [
        'id' => (string) ($job['id'] ?? ''),
        'type' => $type,
        'status' => $status,
        'status_label' => $status === 'completed'
            ? __('Completed', 'll-tools-text-domain')
            : ($status === 'failed' ? __('Failed', 'll-tools-text-domain') : __('Running', 'll-tools-text-domain')),
        'title' => $history_mode === 'undo'
            ? __('Dictionary Undo Restore', 'll-tools-text-domain')
            : ($type === 'legacy'
                ? __('Legacy Dictionary Migration', 'll-tools-text-domain')
                : ($type === 'snapshot'
                    ? (sanitize_key((string) ($job['snapshot_mode'] ?? 'merge')) === 'override'
                        ? __('Dictionary Snapshot Override', 'll-tools-text-domain')
                        : __('Dictionary Snapshot Import', 'll-tools-text-domain'))
                    : __('Dictionary TSV Import', 'll-tools-text-domain'))),
        'original_filename' => (string) ($job['original_filename'] ?? ''),
        'progress_percent' => $progress_percent,
        'progress_text' => $progress_text,
        'detail_text' => $detail_text,
        'advice_title' => $advice_title,
        'advice_text' => $advice_text,
        'summary' => $summary,
        'summary_html' => ($status === 'completed' || $status === 'failed')
            ? ll_tools_dictionary_import_get_job_summary_html($job)
            : '',
        'error_message' => trim((string) ($job['error_message'] ?? '')),
        'has_more' => $status === 'running',
        'updated_at' => max(0, (int) ($job['updated_at'] ?? 0)),
    ];
}

function ll_tools_dictionary_import_get_relevant_job(string $job_id = ''): ?array {
    $job_id = trim(sanitize_text_field($job_id));
    if ($job_id !== '') {
        return ll_tools_dictionary_import_get_job($job_id);
    }

    $last_job_id = ll_tools_dictionary_import_get_last_job_id();
    if ($last_job_id !== '') {
        $last_job = ll_tools_dictionary_import_get_job($last_job_id);
        if (is_array($last_job)) {
            return $last_job;
        }
    }

    $active_job_id = ll_tools_dictionary_import_get_active_job_id();
    if ($active_job_id !== '') {
        return ll_tools_dictionary_import_get_job($active_job_id);
    }

    return null;
}

function ll_tools_dictionary_import_enqueue_admin_assets(string $hook_suffix): void {
    if ($hook_suffix !== 'tools_page_ll-dictionary-import') {
        return;
    }
    if (!ll_tools_current_user_can_dictionary_import()) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/dictionary-import-admin.css', 'll-tools-dictionary-import-admin');
    ll_enqueue_asset_by_timestamp('/js/dictionary-import-admin.js', 'll-tools-dictionary-import-admin', ['jquery'], true);
    wp_localize_script('ll-tools-dictionary-import-admin', 'llDictionaryImportAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_tools_dictionary_import_ajax'),
        'statusAction' => 'll_tools_dictionary_import_status',
        'startAction' => 'll_tools_dictionary_import_start_job',
        'processAction' => 'll_tools_dictionary_import_process_job',
        'strings' => [
            'starting' => __('Preparing the dictionary job…', 'll-tools-text-domain'),
            'running' => __('Dictionary job in progress…', 'll-tools-text-domain'),
            'failed' => __('The dictionary job request failed. Reopen this page to resume.', 'll-tools-text-domain'),
            'alreadyRunning' => __('Another dictionary job is already running. Finish or resume that one first.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_dictionary_import_enqueue_admin_assets');

function ll_tools_dictionary_import_ajax_start_job(): void {
    if (!ll_tools_current_user_can_dictionary_import()) {
        wp_send_json_error(['message' => __('You do not have permission to run dictionary imports.', 'll-tools-text-domain')], 403);
    }

    check_ajax_referer('ll_tools_dictionary_import_ajax', 'nonce');

    $active_job_id = ll_tools_dictionary_import_get_active_job_id();
    if ($active_job_id !== '') {
        $active_job = ll_tools_dictionary_import_get_job($active_job_id);
        if (is_array($active_job) && (string) ($active_job['status'] ?? '') === 'running') {
            wp_send_json_error([
                'message' => __('Another dictionary import is already running. Resume or finish it before starting a new one.', 'll-tools-text-domain'),
                'job' => ll_tools_dictionary_import_get_job_snapshot($active_job),
            ], 409);
        }
        ll_tools_dictionary_import_clear_active_job_id($active_job_id);
    }

    $selected_wordset_id = isset($_POST['ll_dictionary_wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['ll_dictionary_wordset_id'])) : 0;
    $entry_lang = isset($_POST['ll_dictionary_entry_lang']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_lang']))) : '';
    $def_lang = isset($_POST['ll_dictionary_def_lang']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_def_lang']))) : '';
    $skip_review_rows = !isset($_POST['ll_dictionary_skip_review_rows']) || wp_unslash((string) $_POST['ll_dictionary_skip_review_rows']) === '1';
    $replace_existing_senses = isset($_POST['ll_dictionary_replace_existing_senses']) && wp_unslash((string) $_POST['ll_dictionary_replace_existing_senses']) === '1';
    $action = isset($_POST['ll_dictionary_action']) ? sanitize_key((string) wp_unslash((string) $_POST['ll_dictionary_action'])) : 'import_tsv';
    $snapshot_mode = isset($_POST['ll_dictionary_snapshot_mode']) && sanitize_key((string) wp_unslash((string) $_POST['ll_dictionary_snapshot_mode'])) === 'override'
        ? 'override'
        : 'merge';
    $history_id = isset($_POST['ll_dictionary_history_id']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_history_id'])) : '';

    if ($selected_wordset_id > 0) {
        if ($entry_lang === '' && function_exists('ll_tools_get_wordset_target_language')) {
            $entry_lang = (string) ll_tools_get_wordset_target_language([$selected_wordset_id]);
        }
        if ($def_lang === '' && function_exists('ll_tools_get_wordset_translation_language')) {
            $def_lang = (string) ll_tools_get_wordset_translation_language([$selected_wordset_id]);
        }
    }

    $import_options = [
        'wordset_id' => $selected_wordset_id,
        'entry_lang' => $entry_lang,
        'def_lang' => $def_lang,
        'skip_review_rows' => $skip_review_rows,
        'replace_existing_senses' => $replace_existing_senses,
        'snapshot_mode' => $snapshot_mode,
    ];

    if ($action === 'migrate_legacy') {
        $job = ll_tools_dictionary_import_create_legacy_job($import_options);
    } elseif ($action === 'import_snapshot') {
        $job = ll_tools_dictionary_import_create_snapshot_job_from_upload($import_options);
    } elseif ($action === 'undo_import') {
        $job = ll_tools_dictionary_import_create_undo_job($history_id);
    } else {
        $job = ll_tools_dictionary_import_create_tsv_job_from_upload($import_options);
    }

    if (is_wp_error($job)) {
        wp_send_json_error(['message' => $job->get_error_message()], 400);
    }

    if (sanitize_key((string) ($job['status'] ?? '')) !== 'running') {
        $job = ll_tools_dictionary_import_finalize_job_history($job);
        $job = ll_tools_dictionary_import_save_job((string) ($job['id'] ?? ''), $job);
    }

    wp_send_json_success([
        'job' => ll_tools_dictionary_import_get_job_snapshot($job),
    ]);
}
add_action('wp_ajax_ll_tools_dictionary_import_start_job', 'll_tools_dictionary_import_ajax_start_job');

function ll_tools_dictionary_import_ajax_status(): void {
    if (!ll_tools_current_user_can_dictionary_import()) {
        wp_send_json_error(['message' => __('You do not have permission to view dictionary imports.', 'll-tools-text-domain')], 403);
    }

    check_ajax_referer('ll_tools_dictionary_import_ajax', 'nonce');

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
    $job = ll_tools_dictionary_import_get_relevant_job($job_id);
    if (!is_array($job)) {
        wp_send_json_success(['job' => null]);
    }

    wp_send_json_success([
        'job' => ll_tools_dictionary_import_get_job_snapshot($job),
    ]);
}
add_action('wp_ajax_ll_tools_dictionary_import_status', 'll_tools_dictionary_import_ajax_status');

function ll_tools_dictionary_import_ajax_process_job(): void {
    if (!ll_tools_current_user_can_dictionary_import()) {
        wp_send_json_error(['message' => __('You do not have permission to process dictionary imports.', 'll-tools-text-domain')], 403);
    }

    check_ajax_referer('ll_tools_dictionary_import_ajax', 'nonce');

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
    $job = ll_tools_dictionary_import_get_job($job_id);
    if (!is_array($job)) {
        wp_send_json_error(['message' => __('The requested import job could not be found.', 'll-tools-text-domain')], 404);
    }
    if ((string) ($job['status'] ?? '') !== 'running') {
        wp_send_json_success(['job' => ll_tools_dictionary_import_get_job_snapshot($job)]);
    }

    $processed_job = ll_tools_dictionary_import_process_job($job);
    if (is_wp_error($processed_job)) {
        $job['status'] = 'failed';
        $job['error_message'] = $processed_job->get_error_message();
        $job['summary'] = ll_tools_dictionary_import_merge_summary(
            is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary(),
            ['errors' => [$processed_job->get_error_message()]]
        );
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        $job = ll_tools_dictionary_import_save_job($job_id, $job);
        $job = ll_tools_dictionary_import_finalize_job_history($job);
        $job = ll_tools_dictionary_import_save_job($job_id, $job);

        wp_send_json_error([
            'message' => $processed_job->get_error_message(),
            'job' => ll_tools_dictionary_import_get_job_snapshot($job),
        ], 500);
    }

    $saved_job = ll_tools_dictionary_import_save_job($job_id, $processed_job);
    $saved_job = ll_tools_dictionary_import_finalize_job_history($saved_job);
    $saved_job = ll_tools_dictionary_import_save_job($job_id, $saved_job);
    wp_send_json_success([
        'job' => ll_tools_dictionary_import_get_job_snapshot($saved_job),
    ]);
}
add_action('wp_ajax_ll_tools_dictionary_import_process_job', 'll_tools_dictionary_import_ajax_process_job');

/**
 * Render the dictionary import/migration screen.
 */
function ll_tools_render_dictionary_import_page(): void {
    if (!ll_tools_current_user_can_dictionary_import()) {
        return;
    }

    $selected_wordset_id = isset($_POST['ll_dictionary_wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['ll_dictionary_wordset_id'])) : 0;
    $entry_lang = isset($_POST['ll_dictionary_entry_lang']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_entry_lang']))) : '';
    $def_lang = isset($_POST['ll_dictionary_def_lang']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_def_lang']))) : '';
    $skip_review_rows = !isset($_POST['ll_dictionary_skip_review_rows']) || wp_unslash((string) $_POST['ll_dictionary_skip_review_rows']) === '1';
    $replace_existing_senses = isset($_POST['ll_dictionary_replace_existing_senses']) && wp_unslash((string) $_POST['ll_dictionary_replace_existing_senses']) === '1';
    $snapshot_mode = isset($_POST['ll_dictionary_snapshot_mode']) && sanitize_key((string) wp_unslash((string) $_POST['ll_dictionary_snapshot_mode'])) === 'override'
        ? 'override'
        : 'merge';
    $summary = null;
    $summary_heading = '';
    $errors = [];

    if ($selected_wordset_id > 0) {
        if ($entry_lang === '' && function_exists('ll_tools_get_wordset_target_language')) {
            $entry_lang = (string) ll_tools_get_wordset_target_language([$selected_wordset_id]);
        }
        if ($def_lang === '' && function_exists('ll_tools_get_wordset_translation_language')) {
            $def_lang = (string) ll_tools_get_wordset_translation_language([$selected_wordset_id]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ll_dictionary_import_nonce'])) {
        check_admin_referer('ll_tools_dictionary_import', 'll_dictionary_import_nonce');

        $action = isset($_POST['ll_dictionary_action']) ? sanitize_key((string) wp_unslash($_POST['ll_dictionary_action'])) : '';
        $import_options = [
            'wordset_id' => $selected_wordset_id,
            'entry_lang' => $entry_lang,
            'def_lang' => $def_lang,
            'skip_review_rows' => $skip_review_rows,
            'replace_existing_senses' => $replace_existing_senses,
        ];

        if ($action === 'import_tsv') {
            $tmp_name = isset($_FILES['ll_dictionary_tsv']['tmp_name']) ? (string) $_FILES['ll_dictionary_tsv']['tmp_name'] : '';
            $rows = ll_tools_dictionary_parse_tsv_file($tmp_name);
            if (is_wp_error($rows)) {
                $errors[] = $rows->get_error_message();
            } else {
                $summary = ll_tools_dictionary_import_rows($rows, $import_options);
                $summary_heading = __('Dictionary TSV import completed.', 'll-tools-text-domain');
            }
        } elseif ($action === 'migrate_legacy') {
            $summary = ll_tools_dictionary_import_legacy_table($import_options);
            if (is_wp_error($summary)) {
                $errors[] = $summary->get_error_message();
                $summary = null;
            } else {
                $summary_heading = __('Legacy dictionary table migration completed.', 'll-tools-text-domain');
            }
        }
    }

    $wordsets = ll_tools_dictionary_import_get_wordsets();
    $legacy_table_exists = function_exists('ll_tools_dictionary_legacy_table_exists') && ll_tools_dictionary_legacy_table_exists();
    $recent_imports = ll_tools_dictionary_import_get_recent_history_entries();
    $sources_url = admin_url('tools.php?page=ll-dictionary-sources');
    ?>
    <div class="wrap ll-dictionary-import-admin">
        <h1><?php esc_html_e('LL Dictionary Manager', 'll-tools-text-domain'); ?></h1>
        <p>
            <?php esc_html_e('Manage dictionary imports, exports, and legacy migration in one place. TSV rows are grouped by headword so search, browse, bulk translations, and word-linking all use the same data.', 'll-tools-text-domain'); ?>
        </p>
        <p>
            <?php
            echo wp_kses_post(sprintf(
                /* translators: %s: URL to the dictionary sources screen */
                __('Use the <a href="%s">Dictionary Sources</a> screen to define per-dictionary attribution text, source detail URLs, and default dialect tags before importing rows from a new source.', 'll-tools-text-domain'),
                esc_url($sources_url)
            ));
            ?>
        </p>
        <p>
            <?php esc_html_e('Whole-site dictionary snapshots preserve stable import keys so you can export the site dictionary, edit it locally, then reimport it in override mode without breaking linked learning words that already point at those dictionary entries.', 'll-tools-text-domain'); ?>
        </p>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url($sources_url); ?>"><?php esc_html_e('Open Dictionary Sources', 'll-tools-text-domain'); ?></a>
        </p>

        <div class="card" style="max-width:920px;margin-top:20px;">
            <h2><?php esc_html_e('Export Whole Dictionary Snapshot', 'll-tools-text-domain'); ?></h2>
            <p><?php esc_html_e('Exports every LL Tools dictionary entry on this site, plus the dictionary source registry, as a JSON snapshot. Keep each entry\'s import_key intact when editing so override imports update the existing entry instead of creating a new one.', 'll-tools-text-domain'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ll_tools_dictionary_export_snapshot">
                <?php wp_nonce_field('ll_tools_dictionary_export_snapshot', 'll_dictionary_export_snapshot_nonce'); ?>
                <?php submit_button(__('Export Dictionary Snapshot', 'll-tools-text-domain'), 'secondary', 'submit', false); ?>
            </form>
        </div>

        <div id="ll-dictionary-import-runtime" class="ll-dictionary-import-admin__runtime" hidden>
            <div class="ll-dictionary-import-admin__status-card">
                <div class="ll-dictionary-import-admin__status-head">
                    <div>
                        <h2 class="ll-dictionary-import-admin__status-title"></h2>
                        <p class="ll-dictionary-import-admin__status-subtitle"></p>
                    </div>
                    <span class="ll-dictionary-import-admin__status-pill"></span>
                </div>
                <div class="ll-dictionary-import-admin__progress" aria-hidden="true">
                    <span class="ll-dictionary-import-admin__progress-bar"></span>
                </div>
                <p class="ll-dictionary-import-admin__progress-text"></p>
                <p class="ll-dictionary-import-admin__detail-text"></p>
                <div class="ll-dictionary-import-admin__advice">
                    <strong class="ll-dictionary-import-admin__advice-title"></strong>
                    <p class="ll-dictionary-import-admin__advice-text"></p>
                </div>
                <div class="ll-dictionary-import-admin__error notice notice-error inline" hidden>
                    <p class="ll-dictionary-import-admin__error-text"></p>
                </div>
            </div>
        </div>

        <div id="ll-dictionary-import-summary-area">
            <?php foreach ($errors as $error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endforeach; ?>

            <?php if (is_array($summary)) : ?>
                <?php ll_tools_render_dictionary_import_summary($summary, $summary_heading); ?>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px;" id="ll-dictionary-import-form" data-ll-dictionary-job-form>
            <?php wp_nonce_field('ll_tools_dictionary_import', 'll_dictionary_import_nonce'); ?>
            <input type="hidden" name="ll_dictionary_action" value="import_tsv">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ll-dictionary-tsv"><?php esc_html_e('TSV File', 'll-tools-text-domain'); ?></label></th>
                    <td>
                        <input type="file" name="ll_dictionary_tsv" id="ll-dictionary-tsv" accept=".tsv,text/tab-separated-values" required>
                        <p class="description">
                            <?php esc_html_e('Expected columns: entry, definition, gender_number, entry_type, parent, needs_review, page_number. Header-based TSVs can also include source_id, source_dictionary, source_row_idx, raw_headword, title_keys, dialect, dialects, and multilingual gloss columns like definition_full_tr, definition_tr, gloss_tr, translation_en, and definition_full_de.', 'll-tools-text-domain'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Large imports now run in short batches with a live progress bar. Keep this window open while the import is running; if you close it, reopening this screen will let you resume the same job.', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ll-dictionary-wordset"><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?></label></th>
                    <td>
                        <select name="ll_dictionary_wordset_id" id="ll-dictionary-wordset">
                            <option value="0"><?php esc_html_e('No word set scope', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($wordsets as $wordset) : ?>
                                <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected($selected_wordset_id, (int) $wordset->term_id); ?>>
                                    <?php echo esc_html((string) $wordset->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Use a word set when this dictionary belongs to one language/course only. Leave it unscoped for combined or pan-dialect dictionaries. Existing headwords are matched within the same import scope.', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ll-dictionary-entry-lang"><?php esc_html_e('Entry Language', 'll-tools-text-domain'); ?></label></th>
                    <td><input type="text" class="regular-text" name="ll_dictionary_entry_lang" id="ll-dictionary-entry-lang" value="<?php echo esc_attr($entry_lang); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ll-dictionary-def-lang"><?php esc_html_e('Definition Language', 'll-tools-text-domain'); ?></label></th>
                    <td><input type="text" class="regular-text" name="ll_dictionary_def_lang" id="ll-dictionary-def-lang" value="<?php echo esc_attr($def_lang); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Import Options', 'll-tools-text-domain'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="ll_dictionary_skip_review_rows" value="1" <?php checked($skip_review_rows); ?>>
                            <?php esc_html_e('Skip rows marked with a non-trivial review flag (matches the legacy plugin behavior).', 'll-tools-text-domain'); ?>
                        </label>
                        <label style="display:block;">
                            <input type="checkbox" name="ll_dictionary_replace_existing_senses" value="1" <?php checked($replace_existing_senses); ?>>
                            <?php esc_html_e('Replace existing structured senses for matching headwords instead of merging.', 'll-tools-text-domain'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Import Dictionary TSV', 'll-tools-text-domain')); ?>
        </form>

        <hr style="margin:28px 0;">

        <h2><?php esc_html_e('Import Whole Dictionary Snapshot', 'll-tools-text-domain'); ?></h2>
        <p><?php esc_html_e('Upload a JSON snapshot previously exported from LL Tools. Merge mode adds and updates entries from the file. Override mode makes the site dictionary match the file exactly, deleting dictionary entries and source-registry items that are not present in the uploaded snapshot.', 'll-tools-text-domain'); ?></p>
        <form method="post" enctype="multipart/form-data" id="ll-dictionary-snapshot-form" data-ll-dictionary-job-form>
            <?php wp_nonce_field('ll_tools_dictionary_import', 'll_dictionary_import_nonce'); ?>
            <input type="hidden" name="ll_dictionary_action" value="import_snapshot">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ll-dictionary-snapshot"><?php esc_html_e('Snapshot File', 'll-tools-text-domain'); ?></label></th>
                    <td>
                        <input type="file" name="ll_dictionary_snapshot" id="ll-dictionary-snapshot" accept=".json,application/json" required>
                        <p class="description"><?php esc_html_e('Use the whole-site JSON snapshot exported from this screen. Large snapshot imports run in the same resumable browser batches as TSV imports.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ll-dictionary-snapshot-mode"><?php esc_html_e('Import Mode', 'll-tools-text-domain'); ?></label></th>
                    <td>
                        <select name="ll_dictionary_snapshot_mode" id="ll-dictionary-snapshot-mode">
                            <option value="merge" <?php selected($snapshot_mode, 'merge'); ?>><?php esc_html_e('Merge into existing dictionary', 'll-tools-text-domain'); ?></option>
                            <option value="override" <?php selected($snapshot_mode, 'override'); ?>><?php esc_html_e('Override site dictionary from snapshot', 'll-tools-text-domain'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Override mode updates matching entries by import_key, keeps linked learning words attached to those entries, and deletes dictionary entries that are missing from the snapshot.', 'll-tools-text-domain'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Import Dictionary Snapshot', 'll-tools-text-domain'), 'primary', 'submit', false); ?>
        </form>

        <hr style="margin:28px 0;">

        <h2><?php esc_html_e('Legacy Migration', 'll-tools-text-domain'); ?></h2>
        <?php if ($legacy_table_exists) : ?>
            <p><?php esc_html_e('A legacy raw dictionary table was found. This will group those rows into LL Tools dictionary entries so the old one-off plugin can be removed cleanly.', 'll-tools-text-domain'); ?></p>
            <form method="post" id="ll-dictionary-legacy-form" data-ll-dictionary-job-form>
                <?php wp_nonce_field('ll_tools_dictionary_import', 'll_dictionary_import_nonce'); ?>
                <input type="hidden" name="ll_dictionary_action" value="migrate_legacy">
                <input type="hidden" name="ll_dictionary_wordset_id" value="<?php echo esc_attr((string) $selected_wordset_id); ?>">
                <input type="hidden" name="ll_dictionary_entry_lang" value="<?php echo esc_attr($entry_lang); ?>">
                <input type="hidden" name="ll_dictionary_def_lang" value="<?php echo esc_attr($def_lang); ?>">
                <input type="hidden" name="ll_dictionary_skip_review_rows" value="<?php echo esc_attr($skip_review_rows ? '1' : '0'); ?>">
                <input type="hidden" name="ll_dictionary_replace_existing_senses" value="<?php echo esc_attr($replace_existing_senses ? '1' : '0'); ?>">
                <?php submit_button(__('Migrate Legacy Dictionary Table', 'll-tools-text-domain'), 'secondary', 'submit', false); ?>
            </form>
        <?php else : ?>
            <p class="description"><?php esc_html_e('No legacy dictionary table was detected on this site.', 'll-tools-text-domain'); ?></p>
        <?php endif; ?>

        <hr style="margin:28px 0;">
        <?php ll_tools_render_dictionary_import_history_section($recent_imports); ?>
    </div>
    <?php
}
