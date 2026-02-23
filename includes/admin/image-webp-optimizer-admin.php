<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WEBP_OPTIMIZER_PAGE_SLUG')) {
    define('LL_TOOLS_WEBP_OPTIMIZER_PAGE_SLUG', 'll-image-webp-optimizer');
}

if (!defined('LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION')) {
    define('LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION', 'll_tools_webp_optimizer');
}

if (!defined('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_THRESHOLD_BYTES')) {
    define('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_THRESHOLD_BYTES', 307200); // 300 KB
}

if (!defined('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_BATCH_SIZE')) {
    define('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_BATCH_SIZE', 8);
}

if (!defined('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_QUALITY')) {
    define('LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_QUALITY', 82);
}

function ll_tools_get_webp_optimizer_page_slug(): string {
    return (string) LL_TOOLS_WEBP_OPTIMIZER_PAGE_SLUG;
}

function ll_tools_get_webp_optimizer_admin_url(array $args = []): string {
    $base = add_query_arg(
        ['page' => ll_tools_get_webp_optimizer_page_slug()],
        admin_url('tools.php')
    );

    if (!empty($args)) {
        $base = add_query_arg($args, $base);
    }

    return (string) $base;
}

function ll_tools_webp_optimizer_post_statuses(): array {
    return ['publish', 'draft', 'pending', 'private', 'future'];
}

function ll_tools_webp_optimizer_threshold_bytes(): int {
    $threshold = (int) apply_filters('ll_tools_webp_optimizer_threshold_bytes', (int) LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_THRESHOLD_BYTES);
    return max(32768, $threshold);
}

function ll_tools_webp_optimizer_quality(): int {
    $quality = (int) apply_filters('ll_tools_webp_optimizer_quality', (int) LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_QUALITY);
    return max(35, min(100, $quality));
}

function ll_tools_webp_optimizer_min_retry_quality(): int {
    $quality = (int) apply_filters('ll_tools_webp_optimizer_min_retry_quality', 20);
    return max(10, min(95, $quality));
}

function ll_tools_webp_optimizer_retry_quality_step(): int {
    $step = (int) apply_filters('ll_tools_webp_optimizer_retry_quality_step', 8);
    return max(1, min(25, $step));
}

function ll_tools_webp_optimizer_batch_size(): int {
    $size = (int) apply_filters('ll_tools_webp_optimizer_batch_size', (int) LL_TOOLS_WEBP_OPTIMIZER_DEFAULT_BATCH_SIZE);
    return max(1, min(25, $size));
}

function ll_tools_webp_optimizer_supported_source_mimes(): array {
    $mimes = apply_filters('ll_tools_webp_optimizer_supported_source_mimes', ['image/jpeg', 'image/png', 'image/webp']);
    if (!is_array($mimes)) {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    $clean = [];
    foreach ($mimes as $mime) {
        $mime = trim((string) $mime);
        if ($mime !== '') {
            $clean[] = strtolower($mime);
        }
    }

    $clean = array_values(array_unique($clean));
    if (empty($clean)) {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    return $clean;
}

function ll_tools_webp_optimizer_min_savings_bytes(): int {
    $bytes = (int) apply_filters('ll_tools_webp_optimizer_min_savings_bytes', 4096);
    return max(0, $bytes);
}

function ll_tools_webp_optimizer_can_encode_webp(): bool {
    if (!function_exists('wp_image_editor_supports')) {
        return false;
    }

    return (bool) wp_image_editor_supports(['mime_type' => 'image/webp']);
}

function ll_tools_webp_optimizer_bytes_label(int $bytes): string {
    $bytes = max(0, $bytes);
    if (function_exists('size_format')) {
        return (string) size_format($bytes, ($bytes >= 1048576 ? 2 : 1));
    }

    return (string) $bytes . ' B';
}

function ll_tools_webp_optimizer_mime_label(string $mime): string {
    $mime = strtolower(trim($mime));
    $map = [
        'image/jpeg' => 'JPEG',
        'image/png'  => 'PNG',
        'image/webp' => 'WebP',
        'image/gif'  => 'GIF',
    ];
    return isset($map[$mime]) ? $map[$mime] : strtoupper((string) preg_replace('/^image\//', '', $mime));
}

function ll_tools_webp_optimizer_safe_filesize(string $path): int {
    if ($path === '' || !file_exists($path)) {
        return 0;
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    return ($size === false) ? 0 : max(0, (int) $size);
}

function ll_tools_webp_optimizer_attachment_metadata_filesize(int $attachment_id): int {
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
        return 0;
    }

    if (isset($metadata['filesize'])) {
        return max(0, (int) $metadata['filesize']);
    }

    return 0;
}

function ll_tools_webp_optimizer_get_attachment_download_url(int $attachment_id): string {
    $url = (string) wp_get_attachment_url($attachment_id);
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (function_exists('wp_http_validate_url')) {
        return wp_http_validate_url($url) ? $url : '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function ll_tools_webp_optimizer_attachment_source_name(int $attachment_id, string $fallback = ''): string {
    $attached_rel = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
    if ($attached_rel !== '') {
        $base = (string) pathinfo($attached_rel, PATHINFO_FILENAME);
        if ($base !== '') {
            return sanitize_file_name($base);
        }
    }

    $url = ll_tools_webp_optimizer_get_attachment_download_url($attachment_id);
    if ($url !== '') {
        $url_path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = (string) pathinfo($url_path, PATHINFO_FILENAME);
        if ($base !== '') {
            return sanitize_file_name($base);
        }
    }

    $fallback = trim((string) $fallback);
    if ($fallback !== '') {
        $base = (string) pathinfo($fallback, PATHINFO_FILENAME);
        if ($base !== '') {
            return sanitize_file_name($base);
        }
    }

    return 'image';
}

function ll_tools_webp_optimizer_prepare_conversion_source(int $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return new WP_Error('invalid_attachment', __('Invalid attachment ID.', 'll-tools-text-domain'));
    }

    $local_path = get_attached_file($attachment_id, true);
    if (is_string($local_path) && $local_path !== '' && file_exists($local_path) && is_readable($local_path)) {
        return [
            'path' => $local_path,
            'cleanup_source' => false,
            'output_dir' => dirname($local_path),
            'source_name_override' => ll_tools_webp_optimizer_attachment_source_name($attachment_id, $local_path),
            'source_mode' => 'local',
        ];
    }

    $download_url = ll_tools_webp_optimizer_get_attachment_download_url($attachment_id);
    if ($download_url === '') {
        return new WP_Error('missing_source', __('The source image file is missing or unreadable.', 'll-tools-text-domain'));
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $temp_path = download_url($download_url, 60);
    if (is_wp_error($temp_path)) {
        return new WP_Error(
            'remote_download_failed',
            sprintf(
                /* translators: %s error message */
                __('Could not download the remote image for optimization: %s', 'll-tools-text-domain'),
                $temp_path->get_error_message()
            )
        );
    }

    $temp_path = (string) $temp_path;
    if ($temp_path === '' || !file_exists($temp_path) || !is_readable($temp_path)) {
        if ($temp_path !== '' && file_exists($temp_path)) {
            @unlink($temp_path);
        }
        return new WP_Error('remote_download_failed', __('The remote image was downloaded but the temporary file is unreadable.', 'll-tools-text-domain'));
    }

    $upload_dir = wp_upload_dir();
    if (!is_array($upload_dir) || !empty($upload_dir['error']) || empty($upload_dir['path'])) {
        @unlink($temp_path);
        return new WP_Error('upload_dir_unavailable', __('Could not access the current uploads folder for the optimized image.', 'll-tools-text-domain'));
    }

    $output_dir = (string) $upload_dir['path'];
    if ($output_dir === '' || (!is_dir($output_dir) && !wp_mkdir_p($output_dir))) {
        @unlink($temp_path);
        return new WP_Error('upload_dir_unavailable', __('Could not create the current uploads folder for the optimized image.', 'll-tools-text-domain'));
    }

    return [
        'path' => $temp_path,
        'cleanup_source' => true,
        'output_dir' => $output_dir,
        'source_name_override' => ll_tools_webp_optimizer_attachment_source_name($attachment_id, $download_url),
        'source_mode' => 'remote',
        'source_url' => $download_url,
    ];
}

function ll_tools_webp_optimizer_get_categories_for_post(int $post_id): array {
    $terms = get_the_terms($post_id, 'word-category');
    if (empty($terms) || is_wp_error($terms)) {
        return [
            'ids' => [],
            'labels' => [],
            'display' => '',
        ];
    }

    $ids = [];
    $labels = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }
        $ids[] = (int) $term->term_id;
        $labels[] = (string) $term->name;
    }

    $labels = array_values(array_unique(array_filter(array_map('strval', $labels))));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id): bool {
        return $id > 0;
    })));

    return [
        'ids' => $ids,
        'labels' => $labels,
        'display' => implode(', ', $labels),
    ];
}

function ll_tools_webp_optimizer_get_attachment_dimensions(int $attachment_id): array {
    $metadata = wp_get_attachment_metadata($attachment_id);
    $width = 0;
    $height = 0;

    if (is_array($metadata)) {
        $width = isset($metadata['width']) ? (int) $metadata['width'] : 0;
        $height = isset($metadata['height']) ? (int) $metadata['height'] : 0;
    }

    if ($width > 0 && $height > 0) {
        return ['width' => $width, 'height' => $height];
    }

    $path = get_attached_file($attachment_id, true);
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return ['width' => 0, 'height' => 0];
    }

    $size = @getimagesize($path);
    if (!is_array($size)) {
        return ['width' => 0, 'height' => 0];
    }

    return [
        'width' => isset($size[0]) ? max(0, (int) $size[0]) : 0,
        'height' => isset($size[1]) ? max(0, (int) $size[1]) : 0,
    ];
}

function ll_tools_webp_optimizer_build_item(int $word_image_id): array {
    $post = get_post($word_image_id);
    if (!$post || $post->post_type !== 'word_images') {
        return [];
    }

    $attachment_id = (int) get_post_thumbnail_id($word_image_id);
    $categories = ll_tools_webp_optimizer_get_categories_for_post($word_image_id);
    $threshold_bytes = ll_tools_webp_optimizer_threshold_bytes();
    $encoding_supported = ll_tools_webp_optimizer_can_encode_webp();

    $item = [
        'word_image_id' => (int) $word_image_id,
        'title' => (string) get_the_title($word_image_id),
        'edit_url' => (string) get_edit_post_link($word_image_id, ''),
        'optimizer_url' => ll_tools_get_webp_optimizer_admin_url(['word_image_id' => (int) $word_image_id]),
        'post_status' => (string) $post->post_status,
        'attachment_id' => $attachment_id,
        'categories' => $categories['labels'],
        'category_ids' => $categories['ids'],
        'categories_display' => $categories['display'],
        'thumbnail_url' => '',
        'attachment_edit_url' => ($attachment_id > 0) ? (string) get_edit_post_link($attachment_id, '') : '',
        'mime' => '',
        'format_label' => '',
        'file_size_bytes' => 0,
        'file_size_label' => ll_tools_webp_optimizer_bytes_label(0),
        'width' => 0,
        'height' => 0,
        'dimensions_label' => '',
        'is_supported_source' => false,
        'is_webp' => false,
        'needs_conversion' => false,
        'can_convert' => false,
        'has_problem' => false,
        'problem_code' => '',
        'problem_label' => '',
        'reason_codes' => [],
        'reason_labels' => [],
        'threshold_bytes' => $threshold_bytes,
        'threshold_label' => ll_tools_webp_optimizer_bytes_label($threshold_bytes),
        'encoding_supported' => $encoding_supported,
        'status_key' => 'unknown',
        'status_label' => __('Unknown', 'll-tools-text-domain'),
        'source_mode' => 'local',
        'source_url' => '',
    ];

    if ($attachment_id <= 0) {
        $item['has_problem'] = true;
        $item['problem_code'] = 'missing_thumbnail';
        $item['problem_label'] = __('No featured image attached.', 'll-tools-text-domain');
        $item['status_key'] = 'missing';
        $item['status_label'] = __('Missing image', 'll-tools-text-domain');
        return $item;
    }

    if (!wp_attachment_is_image($attachment_id)) {
        $item['has_problem'] = true;
        $item['problem_code'] = 'not_image';
        $item['problem_label'] = __('Featured image attachment is not a supported image.', 'll-tools-text-domain');
        $item['status_key'] = 'unsupported';
        $item['status_label'] = __('Unsupported', 'll-tools-text-domain');
        return $item;
    }

    $path = get_attached_file($attachment_id, true);
    $has_local_file = is_string($path) && $path !== '' && file_exists($path) && is_readable($path);

    $mime = strtolower((string) get_post_mime_type($attachment_id));
    if ($mime === '' && $has_local_file) {
        $check = wp_check_filetype($path);
        $mime = strtolower((string) ($check['type'] ?? ''));
    }

    $file_size = $has_local_file
        ? ll_tools_webp_optimizer_safe_filesize((string) $path)
        : ll_tools_webp_optimizer_attachment_metadata_filesize($attachment_id);
    $dims = ll_tools_webp_optimizer_get_attachment_dimensions($attachment_id);
    $width = max(0, (int) ($dims['width'] ?? 0));
    $height = max(0, (int) ($dims['height'] ?? 0));

    $item['thumbnail_url'] = (string) wp_get_attachment_image_url($attachment_id, 'medium');
    if ($item['thumbnail_url'] === '') {
        $item['thumbnail_url'] = (string) wp_get_attachment_image_url($attachment_id, 'thumbnail');
    }

    $item['mime'] = $mime;
    $item['format_label'] = ll_tools_webp_optimizer_mime_label($mime);
    $item['file_size_bytes'] = $file_size;
    $item['file_size_label'] = $file_size > 0
        ? ll_tools_webp_optimizer_bytes_label($file_size)
        : __('Unknown size', 'll-tools-text-domain');
    $item['width'] = $width;
    $item['height'] = $height;
    $item['dimensions_label'] = ($width > 0 && $height > 0) ? sprintf('%1$d×%2$d', $width, $height) : '';

    $supported_sources = ll_tools_webp_optimizer_supported_source_mimes();
    $is_supported_source = in_array($mime, $supported_sources, true);
    $is_webp = ($mime === 'image/webp');
    $is_oversize = ($file_size > $threshold_bytes);
    $download_url = '';
    if (!$has_local_file) {
        $download_url = ll_tools_webp_optimizer_get_attachment_download_url($attachment_id);
        if ($download_url !== '') {
            $item['source_mode'] = 'remote';
            $item['source_url'] = $download_url;
        }
    }

    $item['is_supported_source'] = $is_supported_source;
    $item['is_webp'] = $is_webp;

    if (!$encoding_supported) {
        $item['status_key'] = 'unavailable';
        $item['status_label'] = __('WebP unavailable', 'll-tools-text-domain');
        return $item;
    }

    if (!$is_supported_source) {
        $item['status_key'] = 'unsupported';
        $item['status_label'] = __('Unsupported', 'll-tools-text-domain');
        if (!$has_local_file) {
            $item['has_problem'] = true;
            $item['problem_code'] = 'missing_file';
            $item['problem_label'] = __('Image file is missing on disk.', 'll-tools-text-domain');
        }
        return $item;
    }

    if (!$has_local_file && $download_url === '') {
        $item['has_problem'] = true;
        $item['problem_code'] = 'missing_file';
        $item['problem_label'] = __('Image file is missing on disk.', 'll-tools-text-domain');
        $item['status_key'] = 'missing';
        $item['status_label'] = __('Missing file', 'll-tools-text-domain');
        return $item;
    }

    $reasons = [];
    if (!$is_webp) {
        $reasons[] = [
            'code' => 'non_webp',
            'label' => __('Optimize to WebP', 'll-tools-text-domain'),
        ];
    }
    if ($is_oversize) {
        $reasons[] = [
            'code' => 'oversize',
            'label' => sprintf(
                /* translators: %s file size threshold */
                __('Over %s', 'll-tools-text-domain'),
                ll_tools_webp_optimizer_bytes_label($threshold_bytes)
            ),
        ];
    }

    $item['reason_codes'] = array_values(array_filter(array_map(static function ($row): string {
        return isset($row['code']) ? (string) $row['code'] : '';
    }, $reasons)));
    $item['reason_labels'] = array_values(array_filter(array_map(static function ($row): string {
        return isset($row['label']) ? (string) $row['label'] : '';
    }, $reasons)));

    $needs = !empty($reasons);
    $item['needs_conversion'] = $needs;
    $item['can_convert'] = $needs;
    $item['status_key'] = $needs ? 'needs' : 'ok';
    $item['status_label'] = $needs
        ? __('Needs optimization', 'll-tools-text-domain')
        : __('Looks efficient', 'll-tools-text-domain');

    if (!$has_local_file && $download_url !== '') {
        $item['has_problem'] = true;
        $item['problem_code'] = 'remote_source';
        $item['problem_label'] = __('Source file is not stored locally. The optimizer will temporarily download it and save the optimized result to the current uploads folder.', 'll-tools-text-domain');
        if ($is_webp && $file_size <= 0 && !$needs) {
            $item['status_key'] = 'unknown';
            $item['status_label'] = __('Needs local check', 'll-tools-text-domain');
            $item['can_convert'] = false;
        }
    }

    return $item;
}

function ll_tools_webp_optimizer_item_matches_filters(array $item, array $args): bool {
    if (empty($item['word_image_id'])) {
        return false;
    }

    $category_id = isset($args['category_id']) ? (int) $args['category_id'] : 0;
    if ($category_id > 0) {
        $cat_ids = array_map('intval', (array) ($item['category_ids'] ?? []));
        if (!in_array($category_id, $cat_ids, true)) {
            return false;
        }
    }

    $search = isset($args['search']) ? trim((string) $args['search']) : '';
    if ($search !== '') {
        $needle = strtolower($search);
        $haystacks = [
            strtolower((string) ($item['title'] ?? '')),
            strtolower((string) ($item['categories_display'] ?? '')),
            strtolower((string) ($item['format_label'] ?? '')),
            '#' . (string) ((int) ($item['word_image_id'] ?? 0)),
            '#' . (string) ((int) ($item['attachment_id'] ?? 0)),
        ];

        $matched = false;
        foreach ($haystacks as $text) {
            if ($text !== '' && strpos($text, $needle) !== false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }
    }

    return true;
}

function ll_tools_webp_optimizer_get_queue(array $args = []): array {
    $defaults = [
        'category_id' => 0,
        'search' => '',
        'page' => 1,
        'per_page' => 18,
        'ids_only' => false,
        'include_non_flagged' => false,
    ];
    $args = wp_parse_args($args, $defaults);

    $page = max(1, (int) $args['page']);
    $per_page = max(1, min(100, (int) $args['per_page']));
    $ids_only = !empty($args['ids_only']);
    $include_non_flagged = !empty($args['include_non_flagged']);

    $query_args = [
        'post_type' => 'word_images',
        'post_status' => ll_tools_webp_optimizer_post_statuses(),
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ];

    $category_id = (int) $args['category_id'];
    if ($category_id > 0) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field' => 'term_id',
            'terms' => [$category_id],
        ]];
    }

    $search = trim((string) $args['search']);
    if ($search !== '') {
        $query_args['s'] = $search;
    }

    $post_ids = get_posts($query_args);
    $rows = [];
    $summary = [
        'queued_count' => 0,
        'queued_bytes' => 0,
        'non_webp_count' => 0,
        'oversize_count' => 0,
        'supported_count' => 0,
        'encoding_supported' => ll_tools_webp_optimizer_can_encode_webp(),
        'threshold_bytes' => ll_tools_webp_optimizer_threshold_bytes(),
        'threshold_label' => ll_tools_webp_optimizer_bytes_label(ll_tools_webp_optimizer_threshold_bytes()),
    ];

    foreach ((array) $post_ids as $raw_post_id) {
        $post_id = (int) $raw_post_id;
        if ($post_id <= 0) {
            continue;
        }

        $item = ll_tools_webp_optimizer_build_item($post_id);
        if (empty($item) || !ll_tools_webp_optimizer_item_matches_filters($item, $args)) {
            continue;
        }

        if (!empty($item['is_supported_source'])) {
            $summary['supported_count']++;
        }

        $needs = !empty($item['needs_conversion']);
        if ($needs) {
            $summary['queued_count']++;
            $summary['queued_bytes'] += (int) ($item['file_size_bytes'] ?? 0);
            if (in_array('non_webp', (array) ($item['reason_codes'] ?? []), true)) {
                $summary['non_webp_count']++;
            }
            if (in_array('oversize', (array) ($item['reason_codes'] ?? []), true)) {
                $summary['oversize_count']++;
            }
        }

        if ($needs || $include_non_flagged) {
            $rows[] = $item;
        }
    }

    usort($rows, static function (array $left, array $right): int {
        $leftNeeds = !empty($left['needs_conversion']) ? 1 : 0;
        $rightNeeds = !empty($right['needs_conversion']) ? 1 : 0;
        if ($leftNeeds !== $rightNeeds) {
            return ($leftNeeds > $rightNeeds) ? -1 : 1;
        }

        $leftSize = (int) ($left['file_size_bytes'] ?? 0);
        $rightSize = (int) ($right['file_size_bytes'] ?? 0);
        if ($leftSize !== $rightSize) {
            return ($leftSize > $rightSize) ? -1 : 1;
        }

        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });

    $summary['queued_bytes_label'] = ll_tools_webp_optimizer_bytes_label((int) $summary['queued_bytes']);

    if ($ids_only) {
        return [
            'ids' => array_values(array_map(static function (array $item): int {
                return (int) $item['word_image_id'];
            }, $rows)),
            'summary' => $summary,
            'total_items' => count($rows),
            'page' => 1,
            'per_page' => count($rows),
            'total_pages' => 1,
            'items' => [],
        ];
    }

    $total_items = count($rows);
    $total_pages = max(1, (int) ceil($total_items / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    $paged_rows = array_slice($rows, $offset, $per_page);

    return [
        'items' => array_values($paged_rows),
        'summary' => $summary,
        'total_items' => $total_items,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'ids' => [],
    ];
}

function ll_tools_webp_optimizer_parse_post_id_list($raw): array {
    if (is_string($raw)) {
        $raw = preg_split('/[\s,]+/', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

function ll_tools_webp_optimizer_build_target_path(
    string $source_path,
    int $quality,
    string $target_dir = '',
    string $source_name_override = ''
): string {
    $source_dir = trim($target_dir) !== '' ? rtrim($target_dir, '/\\') : dirname($source_path);
    $source_name = trim($source_name_override) !== ''
        ? sanitize_file_name((string) pathinfo($source_name_override, PATHINFO_FILENAME))
        : (string) pathinfo($source_path, PATHINFO_FILENAME);
    if ($source_name === '') {
        $source_name = 'image';
    }
    $suffix = sanitize_file_name($source_name . '-ll-webp-q' . max(1, min(100, $quality)));
    if ($suffix === '') {
        $suffix = 'll-webp';
    }
    $filename = wp_unique_filename($source_dir, $suffix . '.webp');
    return trailingslashit($source_dir) . $filename;
}

function ll_tools_webp_optimizer_finalize_generated_attachment(int $source_attachment_id, string $saved_path, string $saved_mime = 'image/webp') {
    $saved_path = trim($saved_path);
    if ($saved_path === '' || !file_exists($saved_path)) {
        return new WP_Error('save_failed', __('Could not save the optimized image.', 'll-tools-text-domain'));
    }

    $saved_mime = trim($saved_mime);
    if ($saved_mime === '') {
        $filetype = wp_check_filetype($saved_path);
        $saved_mime = (string) ($filetype['type'] ?? 'image/webp');
    }

    $source_post = get_post($source_attachment_id);
    $title = ($source_post instanceof WP_Post && $source_post->post_title !== '')
        ? (string) $source_post->post_title
        : (string) pathinfo($saved_path, PATHINFO_FILENAME);

    $new_attachment_id = wp_insert_attachment([
        'post_mime_type' => $saved_mime,
        'post_title' => $title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], $saved_path, 0, true);

    if (is_wp_error($new_attachment_id)) {
        return $new_attachment_id;
    }
    $new_attachment_id = (int) $new_attachment_id;

    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $generated = wp_generate_attachment_metadata($new_attachment_id, $saved_path);
    if (!is_wp_error($generated) && is_array($generated)) {
        wp_update_attachment_metadata($new_attachment_id, $generated);
    }

    $alt = (string) get_post_meta($source_attachment_id, '_wp_attachment_image_alt', true);
    if ($alt !== '') {
        update_post_meta($new_attachment_id, '_wp_attachment_image_alt', $alt);
    }

    if ($source_post instanceof WP_Post) {
        wp_update_post([
            'ID' => $new_attachment_id,
            'post_excerpt' => (string) $source_post->post_excerpt,
            'post_content' => (string) $source_post->post_content,
        ]);
    }

    return $new_attachment_id;
}

function ll_tools_webp_optimizer_save_webp_candidate(string $source_path, int $quality, array $args = []) {
    $quality = max(10, min(100, (int) $quality));
    $args = wp_parse_args($args, [
        'target_dir' => '',
        'source_name_override' => '',
    ]);

    $editor = wp_get_image_editor($source_path);
    if (is_wp_error($editor)) {
        return $editor;
    }

    if (method_exists($editor, 'set_quality')) {
        $quality_result = $editor->set_quality($quality);
        if (is_wp_error($quality_result)) {
            return $quality_result;
        }
    }

    $target_path = ll_tools_webp_optimizer_build_target_path(
        $source_path,
        $quality,
        (string) ($args['target_dir'] ?? ''),
        (string) ($args['source_name_override'] ?? '')
    );
    $saved = $editor->save($target_path, 'image/webp');
    if (is_wp_error($saved)) {
        return $saved;
    }

    $saved_path = isset($saved['path']) ? (string) $saved['path'] : $target_path;
    if ($saved_path === '' || !file_exists($saved_path)) {
        return new WP_Error('save_failed', __('Could not save optimized WebP image.', 'll-tools-text-domain'));
    }

    $size = ll_tools_webp_optimizer_safe_filesize($saved_path);
    if ($size <= 0) {
        @unlink($saved_path);
        return new WP_Error('save_failed', __('Could not read the optimized WebP file size.', 'll-tools-text-domain'));
    }

    return [
        'path' => $saved_path,
        'size' => $size,
        'quality' => $quality,
    ];
}

function ll_tools_webp_optimizer_convert_word_image(int $word_image_id, array $args = []) {
    $defaults = [
        'quality' => ll_tools_webp_optimizer_quality(),
        'force' => false,
    ];
    $args = wp_parse_args($args, $defaults);

    if (!ll_tools_webp_optimizer_can_encode_webp()) {
        return new WP_Error('webp_unavailable', __('This server cannot encode WebP images right now.', 'll-tools-text-domain'));
    }

    $quality = max(35, min(100, (int) $args['quality']));
    $force = !empty($args['force']);

    $item_before = ll_tools_webp_optimizer_build_item($word_image_id);
    if (empty($item_before)) {
        return new WP_Error('invalid_post', __('Word image not found.', 'll-tools-text-domain'));
    }
    if (empty($item_before['attachment_id'])) {
        return new WP_Error('missing_thumbnail', __('This word image does not have a featured image to optimize.', 'll-tools-text-domain'));
    }
    if (empty($item_before['is_supported_source'])) {
        return new WP_Error('unsupported_source', __('This image format is not supported for WebP optimization.', 'll-tools-text-domain'));
    }
    if (!$force && empty($item_before['needs_conversion'])) {
        return new WP_Error('not_needed', __('This image does not currently need optimization.', 'll-tools-text-domain'));
    }

    $attachment_id = (int) $item_before['attachment_id'];
    $source_context = ll_tools_webp_optimizer_prepare_conversion_source($attachment_id);
    if (is_wp_error($source_context)) {
        return $source_context;
    }

    $source_path = (string) ($source_context['path'] ?? '');
    $cleanup_source = !empty($source_context['cleanup_source']);
    $target_dir = (string) ($source_context['output_dir'] ?? '');
    $source_name_override = (string) ($source_context['source_name_override'] ?? '');
    if ($source_path === '' || !file_exists($source_path) || !is_readable($source_path)) {
        if ($cleanup_source && $source_path !== '' && file_exists($source_path)) {
            @unlink($source_path);
        }
        return new WP_Error('missing_source', __('The source image file is missing or unreadable.', 'll-tools-text-domain'));
    }

    $source_size = ll_tools_webp_optimizer_safe_filesize($source_path);
    $source_mime = (string) ($item_before['mime'] ?? '');
    try {
        $min_savings = ll_tools_webp_optimizer_min_savings_bytes();
        $queue_threshold = ll_tools_webp_optimizer_threshold_bytes();
        $retry_floor_quality = min($quality, ll_tools_webp_optimizer_min_retry_quality());
        $retry_quality_step = ll_tools_webp_optimizer_retry_quality_step();

        $qualities_to_try = [$quality];
        if ($queue_threshold > 0 && $source_size > $queue_threshold && $retry_floor_quality < $quality) {
            for ($try_quality = $quality - $retry_quality_step; $try_quality > $retry_floor_quality; $try_quality -= $retry_quality_step) {
                $qualities_to_try[] = max(10, min(100, (int) $try_quality));
            }
            $qualities_to_try[] = $retry_floor_quality;
        }
        $qualities_to_try = array_values(array_unique(array_map('intval', $qualities_to_try)));

        $best_attempt = null;
        $last_error = null;
        $had_larger_than_source_attempt = false;

        foreach ($qualities_to_try as $try_quality) {
            $candidate = ll_tools_webp_optimizer_save_webp_candidate($source_path, (int) $try_quality, [
                'target_dir' => $target_dir,
                'source_name_override' => $source_name_override,
            ]);
            if (is_wp_error($candidate)) {
                $last_error = $candidate;
                continue;
            }

            $candidate_path = (string) ($candidate['path'] ?? '');
            $candidate_size = max(0, (int) ($candidate['size'] ?? 0));
            $candidate_quality = max(10, min(100, (int) ($candidate['quality'] ?? $try_quality)));
            if ($candidate_path === '' || $candidate_size <= 0) {
                if ($candidate_path !== '') {
                    @unlink($candidate_path);
                }
                continue;
            }

            if ($source_size > 0 && $candidate_size > $source_size) {
                $had_larger_than_source_attempt = true;
                @unlink($candidate_path);
                continue;
            }

            if ($best_attempt === null || $candidate_size < (int) $best_attempt['size']) {
                if (is_array($best_attempt) && !empty($best_attempt['path']) && file_exists((string) $best_attempt['path'])) {
                    @unlink((string) $best_attempt['path']);
                }
                $best_attempt = [
                    'path' => $candidate_path,
                    'size' => $candidate_size,
                    'quality' => $candidate_quality,
                ];
            } else {
                @unlink($candidate_path);
            }

            if ($queue_threshold > 0 && $source_size > $queue_threshold && $candidate_size <= $queue_threshold) {
                break;
            }
        }

        if (!is_array($best_attempt) || empty($best_attempt['path'])) {
            if ($last_error instanceof WP_Error) {
                return $last_error;
            }

            if ($had_larger_than_source_attempt) {
                return new WP_Error(
                    'no_savings',
                    __('Optimized image would be larger than the current file at available quality levels, so it was not applied.', 'll-tools-text-domain')
                );
            }

            return new WP_Error(
                'save_failed',
                __('Could not produce an optimized WebP file from this image.', 'll-tools-text-domain')
            );
        }

        $saved_path = (string) $best_attempt['path'];
        $new_size = max(0, (int) $best_attempt['size']);
        $applied_quality = max(10, min(100, (int) $best_attempt['quality']));

        if ($source_size > 0 && $new_size > 0) {
            if ($new_size > $source_size) {
                @unlink($saved_path);
                return new WP_Error(
                    'no_savings',
                    sprintf(
                        /* translators: 1: source size, 2: new size */
                        __('Optimized image would be larger (%1$s -> %2$s), so it was not applied.', 'll-tools-text-domain'),
                        ll_tools_webp_optimizer_bytes_label($source_size),
                        ll_tools_webp_optimizer_bytes_label($new_size)
                    )
                );
            }

            $crosses_below_queue_threshold = (
                $queue_threshold > 0
                && $source_size > $queue_threshold
                && $new_size <= $queue_threshold
            );

            if (
                $source_mime === 'image/webp'
                && ($source_size - $new_size) < $min_savings
                && !$crosses_below_queue_threshold
            ) {
                @unlink($saved_path);
                return new WP_Error(
                    'no_meaningful_savings',
                    sprintf(
                        /* translators: %s minimum savings threshold */
                        __('Savings were under the minimum threshold (%s), so the existing WebP was kept.', 'll-tools-text-domain'),
                        ll_tools_webp_optimizer_bytes_label($min_savings)
                    )
                );
            }
        }

        $new_attachment_id = ll_tools_webp_optimizer_finalize_generated_attachment($attachment_id, $saved_path, 'image/webp');
        if (is_wp_error($new_attachment_id)) {
            @unlink($saved_path);
            return $new_attachment_id;
        }
        $new_attachment_id = (int) $new_attachment_id;

        update_post_meta($new_attachment_id, '_ll_webp_optimizer_source_attachment_id', $attachment_id);
        update_post_meta($new_attachment_id, '_ll_webp_optimizer_source_mime', $source_mime);
        update_post_meta($new_attachment_id, '_ll_webp_optimizer_source_size', $source_size);
        update_post_meta($new_attachment_id, '_ll_webp_optimizer_quality', $applied_quality);
        update_post_meta($new_attachment_id, '_ll_webp_optimizer_converted_at', current_time('mysql'));
        update_post_meta($new_attachment_id, '_ll_webp_optimizer_source_mode', (string) ($source_context['source_mode'] ?? 'local'));

        $connected_word_count = 0;
        if (function_exists('ll_tools_get_connected_word_ids_for_word_image_sync')) {
            $connected_ids = ll_tools_get_connected_word_ids_for_word_image_sync($word_image_id, $attachment_id);
            $connected_word_count = is_array($connected_ids) ? count($connected_ids) : 0;
        }

        $set_result = set_post_thumbnail($word_image_id, $new_attachment_id);
        if (!$set_result) {
            wp_delete_attachment($new_attachment_id, true);
            return new WP_Error('thumbnail_update_failed', __('The optimized image was created but could not be attached to the word image post.', 'll-tools-text-domain'));
        }

        $bytes_saved = max(0, $source_size - $new_size);
        $percent_saved = ($source_size > 0) ? round(($bytes_saved / $source_size) * 100, 1) : 0;
        $item_after = ll_tools_webp_optimizer_build_item($word_image_id);

        return [
            'word_image_id' => $word_image_id,
            'title' => (string) ($item_before['title'] ?? get_the_title($word_image_id)),
            'old_attachment_id' => $attachment_id,
            'new_attachment_id' => $new_attachment_id,
            'old_size_bytes' => $source_size,
            'old_size_label' => ll_tools_webp_optimizer_bytes_label($source_size),
            'new_size_bytes' => $new_size,
            'new_size_label' => ll_tools_webp_optimizer_bytes_label($new_size),
            'bytes_saved' => $bytes_saved,
            'bytes_saved_label' => ll_tools_webp_optimizer_bytes_label($bytes_saved),
            'percent_saved' => $percent_saved,
            'quality' => $applied_quality,
            'linked_word_count' => $connected_word_count,
            'item' => $item_after,
        ];
    } finally {
        if ($cleanup_source && is_string($source_path) && $source_path !== '' && file_exists($source_path)) {
            @unlink($source_path);
        }
    }
}

function ll_tools_webp_optimizer_render_reason_badges(array $item): string {
    $labels = array_values(array_filter(array_map('strval', (array) ($item['reason_labels'] ?? []))));
    if (empty($labels)) {
        return '';
    }

    $html = '';
    foreach ($labels as $label) {
        $html .= '<span class="ll-webp-badge ll-webp-badge--reason">' . esc_html($label) . '</span>';
    }
    return $html;
}

function ll_tools_webp_optimizer_get_primary_action_label(array $item): string {
    $is_webp = !empty($item['is_webp']);
    $needs_conversion = !empty($item['needs_conversion']);

    if ($is_webp && $needs_conversion) {
        $threshold_label = trim((string) ($item['threshold_label'] ?? ''));
        if ($threshold_label === '') {
            $threshold_label = ll_tools_webp_optimizer_bytes_label(ll_tools_webp_optimizer_threshold_bytes());
        }
        return sprintf(
            /* translators: %s target file size threshold */
            __('Optimize to %s', 'll-tools-text-domain'),
            $threshold_label
        );
    }

    return __('Optimize Image', 'll-tools-text-domain');
}

function ll_tools_webp_optimizer_render_list_cell_html(int $word_image_id, ?array $item = null): string {
    if (!is_array($item) || empty($item)) {
        $item = ll_tools_webp_optimizer_build_item($word_image_id);
    }

    if (empty($item)) {
        return '<div class="ll-webp-inline-card"><span class="ll-webp-pill ll-webp-pill--muted">' . esc_html__('Unavailable', 'll-tools-text-domain') . '</span></div>';
    }

    $needs = !empty($item['needs_conversion']);
    $can_convert = !empty($item['can_convert']);
    $status_key = (string) ($item['status_key'] ?? 'unknown');
    $format = (string) ($item['format_label'] ?? '');
    $size = (string) ($item['file_size_label'] ?? '');
    $dims = (string) ($item['dimensions_label'] ?? '');
    $word_image_id = (int) ($item['word_image_id'] ?? $word_image_id);
    $tool_url = (string) ($item['optimizer_url'] ?? ll_tools_get_webp_optimizer_admin_url(['word_image_id' => $word_image_id]));
    $primary_action_label = ll_tools_webp_optimizer_get_primary_action_label($item);

    $inline_state_class = 'is-muted';
    if ($status_key === 'ok' && !empty($item['is_webp'])) {
        $inline_state_class = 'is-efficient';
    } elseif ($needs) {
        $inline_state_class = 'is-flagged';
    } elseif ($status_key === 'unsupported' || $status_key === 'missing') {
        $inline_state_class = 'is-warn';
    }

    $meta_parts = array_values(array_filter([$format, $size, $dims]));
    $meta = implode(' · ', $meta_parts);
    $problem_label = trim((string) ($item['problem_label'] ?? ''));

    ob_start();
    ?>
    <div class="ll-webp-inline-card <?php echo esc_attr($inline_state_class); ?>" data-ll-webp-list-cell-root data-word-image-id="<?php echo esc_attr((string) $word_image_id); ?>">
        <div class="ll-webp-inline-card__top">
            <?php if ($meta !== '') : ?>
                <span class="ll-webp-inline-card__meta-chip"><?php echo esc_html($meta); ?></span>
            <?php endif; ?>
        </div>

        <?php if ($problem_label !== '') : ?>
            <div class="ll-webp-inline-card__problem"><?php echo esc_html($problem_label); ?></div>
        <?php endif; ?>

        <div class="ll-webp-inline-card__actions">
            <?php if ($can_convert) : ?>
                <button type="button" class="button button-secondary ll-webp-inline-card__button" data-ll-webp-inline-convert data-word-image-id="<?php echo esc_attr((string) $word_image_id); ?>">
                    <span class="ll-webp-inline-card__button-text"><?php echo esc_html($primary_action_label); ?></span>
                    <span class="ll-webp-inline-card__spinner" aria-hidden="true"></span>
                </button>
            <?php endif; ?>
            <a class="ll-webp-inline-card__link" href="<?php echo esc_url($tool_url); ?>">
                <?php echo esc_html__('Open Bulk Tool', 'll-tools-text-domain'); ?>
            </a>
        </div>

        <div class="ll-webp-inline-card__status" data-ll-webp-inline-status aria-live="polite"></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ll_tools_webp_optimizer_register_admin_page(): void {
    add_submenu_page(
        'tools.php',
        __('LL Tools - WebP Image Optimizer', 'll-tools-text-domain'),
        __('LL Optimize Images (WebP)', 'll-tools-text-domain'),
        'view_ll_tools',
        ll_tools_get_webp_optimizer_page_slug(),
        'll_tools_webp_optimizer_render_admin_page'
    );
}
add_action('admin_menu', 'll_tools_webp_optimizer_register_admin_page');

function ll_tools_webp_optimizer_enqueue_admin_assets($hook): void {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $is_tool_page = ($hook === ('tools_page_' . ll_tools_get_webp_optimizer_page_slug()));
    $is_word_images_list = ($screen instanceof WP_Screen)
        && ($screen->id === 'edit-word_images');

    if (!$is_tool_page && !$is_word_images_list) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/image-webp-optimizer-admin.css', 'll-tools-webp-optimizer-admin', [], false);
    ll_enqueue_asset_by_timestamp('/js/image-webp-optimizer-admin.js', 'll-tools-webp-optimizer-admin-js', ['jquery'], true);

    $preselected_word_image_id = isset($_GET['word_image_id']) ? (int) wp_unslash($_GET['word_image_id']) : 0;
    wp_localize_script('ll-tools-webp-optimizer-admin-js', 'llWebpOptimizerData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
        'pageUrl' => ll_tools_get_webp_optimizer_admin_url(),
        'preselectedWordImageId' => max(0, $preselected_word_image_id),
        'screen' => [
            'isToolPage' => $is_tool_page,
            'isWordImagesList' => $is_word_images_list,
        ],
        'thresholdBytes' => ll_tools_webp_optimizer_threshold_bytes(),
        'thresholdLabel' => ll_tools_webp_optimizer_bytes_label(ll_tools_webp_optimizer_threshold_bytes()),
        'quality' => ll_tools_webp_optimizer_quality(),
        'batchSize' => ll_tools_webp_optimizer_batch_size(),
        'encodingSupported' => ll_tools_webp_optimizer_can_encode_webp(),
        'actions' => [
            'queue' => 'll_tools_webp_optimizer_queue',
            'convert' => 'll_tools_webp_optimizer_convert',
        ],
        'i18n' => [
            'loadingQueue' => __('Loading image queue...', 'll-tools-text-domain'),
            'loadingIds' => __('Building optimization queue...', 'll-tools-text-domain'),
            'emptyQueue' => __('No word images currently need WebP optimization.', 'll-tools-text-domain'),
            'convertAll' => __('Optimize All Flagged', 'll-tools-text-domain'),
            'convertOne' => __('Optimize Image', 'll-tools-text-domain'),
            'compressToThreshold' => __('Optimize to %s', 'll-tools-text-domain'),
            'working' => __('Optimizing...', 'll-tools-text-domain'),
            'convertAllConfirm' => __('Optimize all currently flagged word images using batched WebP optimization?', 'll-tools-text-domain'),
            'convertButtonWorking' => __('Working...', 'll-tools-text-domain'),
            'convertSuccess' => __('Optimized image and updated the word image.', 'll-tools-text-domain'),
            'convertFailed' => __('Could not optimize this image right now.', 'll-tools-text-domain'),
            'queueFailed' => __('Could not load the WebP optimization queue.', 'll-tools-text-domain'),
            'webpUnavailable' => __('WebP encoding is not available on this server. The queue can still be reviewed, but optimization is disabled.', 'll-tools-text-domain'),
            'progressLabel' => __('Optimizing batch %1$d of %2$d...', 'll-tools-text-domain'),
            'progressDone' => __('Batch optimization finished.', 'll-tools-text-domain'),
            'bytesSaved' => __('Saved %s', 'll-tools-text-domain'),
            'resultSummary' => __('Optimized %1$d image(s); %2$d failed; saved %3$s total.', 'll-tools-text-domain'),
            'resultSummaryWarnings' => __('%d image(s) are still flagged and remain in the queue.', 'll-tools-text-domain'),
            'pageLabel' => __('Page %1$d of %2$d', 'll-tools-text-domain'),
            'prevPage' => __('Previous', 'll-tools-text-domain'),
            'nextPage' => __('Next', 'll-tools-text-domain'),
            'focusLabel' => __('Opened from list view', 'll-tools-text-domain'),
            'refresh' => __('Refresh Queue', 'll-tools-text-domain'),
            'applyFilters' => __('Apply Filters', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_webp_optimizer_enqueue_admin_assets');

function ll_tools_webp_optimizer_render_admin_page(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
    }

    $terms = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }

    $preselected_word_image_id = isset($_GET['word_image_id']) ? (int) wp_unslash($_GET['word_image_id']) : 0;
    ?>
    <div class="wrap ll-webp-optimizer-wrap">
        <h1><?php echo esc_html__('WebP Image Optimizer', 'll-tools-text-domain'); ?></h1>
        <p class="description">
            <?php echo esc_html__('Find word images that need WebP optimization because they are non-WebP or oversized for quiz use. Optimize individually or run a batched bulk pass with progress tracking.', 'll-tools-text-domain'); ?>
        </p>

        <div class="ll-webp-optimizer" data-ll-webp-optimizer-root data-preselected-word-image-id="<?php echo esc_attr((string) max(0, $preselected_word_image_id)); ?>">
            <section class="ll-webp-optimizer__hero">
                <div class="ll-webp-optimizer__stats" data-ll-webp-summary>
                    <div class="ll-webp-stat-card is-placeholder"></div>
                    <div class="ll-webp-stat-card is-placeholder"></div>
                    <div class="ll-webp-stat-card is-placeholder"></div>
                    <div class="ll-webp-stat-card is-placeholder"></div>
                </div>
            </section>

            <section class="ll-webp-optimizer__toolbar">
                <div class="ll-webp-optimizer__filters">
                    <label>
                        <span><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                        <select data-ll-webp-filter-category>
                            <option value="0"><?php echo esc_html__('All categories', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($terms as $term) : ?>
                                <?php if (!($term instanceof WP_Term)) { continue; } ?>
                                <option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ll-webp-optimizer__search">
                        <span><?php echo esc_html__('Search', 'll-tools-text-domain'); ?></span>
                        <input type="search" data-ll-webp-filter-search placeholder="<?php echo esc_attr__('Title, category, #post-id', 'll-tools-text-domain'); ?>">
                    </label>
                    <button type="button" class="button" data-ll-webp-apply-filters>
                        <?php echo esc_html__('Apply Filters', 'll-tools-text-domain'); ?>
                    </button>
                    <button type="button" class="button" data-ll-webp-refresh>
                        <?php echo esc_html__('Refresh Queue', 'll-tools-text-domain'); ?>
                    </button>
                </div>

                <div class="ll-webp-optimizer__bulk-actions">
                    <div class="ll-webp-optimizer__threshold-note">
                        <?php
                        printf(
                            /* translators: %s file size threshold */
                            esc_html__('Oversized WebP threshold: %s', 'll-tools-text-domain'),
                            esc_html(ll_tools_webp_optimizer_bytes_label(ll_tools_webp_optimizer_threshold_bytes()))
                        );
                        ?>
                    </div>
                    <button type="button" class="button button-primary" data-ll-webp-convert-all>
                        <span class="ll-webp-button__text"><?php echo esc_html__('Optimize All Flagged', 'll-tools-text-domain'); ?></span>
                        <span class="ll-webp-button__spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </section>

            <section class="ll-webp-optimizer__progress" data-ll-webp-progress hidden>
                <div class="ll-webp-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span class="ll-webp-progress__fill" data-ll-webp-progress-fill></span>
                </div>
                <div class="ll-webp-progress__label" data-ll-webp-progress-label></div>
            </section>

            <section class="ll-webp-optimizer__status" data-ll-webp-status hidden></section>

            <section class="ll-webp-optimizer__cards" data-ll-webp-cards>
                <div class="ll-webp-card-skeleton"></div>
                <div class="ll-webp-card-skeleton"></div>
                <div class="ll-webp-card-skeleton"></div>
                <div class="ll-webp-card-skeleton"></div>
            </section>

            <section class="ll-webp-optimizer__pagination" data-ll-webp-pagination hidden></section>
        </div>
    </div>
    <?php
}

function ll_tools_webp_optimizer_verify_ajax_request(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }

    check_ajax_referer(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION, 'nonce');
}

function ll_tools_webp_optimizer_queue_ajax(): void {
    ll_tools_webp_optimizer_verify_ajax_request();

    $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
    $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 18;
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash((string) $_POST['search'])) : '';
    $ids_only = !empty($_POST['ids_only']);
    $include_non_flagged = !empty($_POST['include_non_flagged']);

    $queue = ll_tools_webp_optimizer_get_queue([
        'page' => max(1, $page),
        'per_page' => max(1, min(100, $per_page)),
        'category_id' => max(0, $category_id),
        'search' => $search,
        'ids_only' => $ids_only,
        'include_non_flagged' => $include_non_flagged,
    ]);

    $focus_item = [];
    $focus_word_image_id = isset($_POST['focus_word_image_id']) ? (int) $_POST['focus_word_image_id'] : 0;
    if ($focus_word_image_id > 0) {
        $focus_item = ll_tools_webp_optimizer_build_item($focus_word_image_id);
    }

    wp_send_json_success([
        'items' => isset($queue['items']) && is_array($queue['items']) ? array_values($queue['items']) : [],
        'ids' => isset($queue['ids']) && is_array($queue['ids']) ? array_values(array_map('intval', $queue['ids'])) : [],
        'page' => (int) ($queue['page'] ?? 1),
        'per_page' => (int) ($queue['per_page'] ?? 0),
        'total_items' => (int) ($queue['total_items'] ?? 0),
        'total_pages' => (int) ($queue['total_pages'] ?? 1),
        'summary' => (array) ($queue['summary'] ?? []),
        'focus_item' => is_array($focus_item) ? $focus_item : [],
        'encoding_supported' => ll_tools_webp_optimizer_can_encode_webp(),
        'threshold_bytes' => ll_tools_webp_optimizer_threshold_bytes(),
        'threshold_label' => ll_tools_webp_optimizer_bytes_label(ll_tools_webp_optimizer_threshold_bytes()),
    ]);
}
add_action('wp_ajax_ll_tools_webp_optimizer_queue', 'll_tools_webp_optimizer_queue_ajax');

function ll_tools_webp_optimizer_convert_ajax(): void {
    ll_tools_webp_optimizer_verify_ajax_request();

    $raw_ids = $_POST['word_image_ids'] ?? ($_POST['word_image_id'] ?? []);
    $ids = ll_tools_webp_optimizer_parse_post_id_list($raw_ids);
    if (empty($ids)) {
        wp_send_json_error(['message' => __('No word images were selected for optimization.', 'll-tools-text-domain')], 400);
    }

    $quality = isset($_POST['quality']) ? (int) $_POST['quality'] : ll_tools_webp_optimizer_quality();
    $quality = max(35, min(100, $quality));
    $force = !empty($_POST['force']);

    $max_per_request = max(1, ll_tools_webp_optimizer_batch_size() * 3);
    if (count($ids) > $max_per_request) {
        $ids = array_slice($ids, 0, $max_per_request);
    }

    $results = [];
    $converted_count = 0;
    $failed_count = 0;
    $warning_count = 0;
    $bytes_saved_total = 0;

    foreach ($ids as $word_image_id) {
        if (!current_user_can('edit_post', $word_image_id)) {
            $failed_count++;
            $results[] = [
                'word_image_id' => $word_image_id,
                'success' => false,
                'message' => __('You do not have permission to edit this word image.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $converted = ll_tools_webp_optimizer_convert_word_image((int) $word_image_id, [
            'quality' => $quality,
            'force' => $force,
        ]);

        if (is_wp_error($converted)) {
            $failed_count++;
            $results[] = [
                'word_image_id' => (int) $word_image_id,
                'success' => false,
                'message' => $converted->get_error_message(),
                'item' => ll_tools_webp_optimizer_build_item((int) $word_image_id),
                'list_cell_html' => ll_tools_webp_optimizer_render_list_cell_html((int) $word_image_id),
            ];
            continue;
        }

        $converted_count++;
        $bytes_saved_total += (int) ($converted['bytes_saved'] ?? 0);
        $item_after = (array) ($converted['item'] ?? []);
        $still_flagged = !empty($item_after['needs_conversion']);
        $reason_labels = array_values(array_filter(array_map('strval', (array) ($item_after['reason_labels'] ?? []))));

        $message = sprintf(
            /* translators: 1: old size, 2: new size, 3: bytes saved */
            __('%1$s -> %2$s (%3$s saved)', 'll-tools-text-domain'),
            (string) ($converted['old_size_label'] ?? ''),
            (string) ($converted['new_size_label'] ?? ''),
            (string) ($converted['bytes_saved_label'] ?? '')
        );

        if ($still_flagged) {
            $warning_count++;
            if (!empty($reason_labels)) {
                $message = sprintf(
                    /* translators: 1: optimization summary, 2: remaining reason labels */
                    __('%1$s. Still flagged: %2$s (remains in queue).', 'll-tools-text-domain'),
                    $message,
                    implode(', ', $reason_labels)
                );
            } else {
                $message = sprintf(
                    /* translators: %s optimization summary */
                    __('%s. Still flagged and remains in queue.', 'll-tools-text-domain'),
                    $message
                );
            }
        }

        $results[] = [
            'word_image_id' => (int) ($converted['word_image_id'] ?? $word_image_id),
            'success' => true,
            'warning' => $still_flagged,
            'message' => $message,
            'bytes_saved' => (int) ($converted['bytes_saved'] ?? 0),
            'bytes_saved_label' => (string) ($converted['bytes_saved_label'] ?? ll_tools_webp_optimizer_bytes_label(0)),
            'percent_saved' => (float) ($converted['percent_saved'] ?? 0),
            'linked_word_count' => (int) ($converted['linked_word_count'] ?? 0),
            'new_attachment_id' => (int) ($converted['new_attachment_id'] ?? 0),
            'item' => (array) ($converted['item'] ?? []),
            'list_cell_html' => ll_tools_webp_optimizer_render_list_cell_html((int) $word_image_id, (array) ($converted['item'] ?? [])),
        ];
    }

    wp_send_json_success([
        'results' => $results,
        'converted_count' => $converted_count,
        'failed_count' => $failed_count,
        'warning_count' => $warning_count,
        'bytes_saved_total' => $bytes_saved_total,
        'bytes_saved_total_label' => ll_tools_webp_optimizer_bytes_label($bytes_saved_total),
        'quality' => $quality,
    ]);
}
add_action('wp_ajax_ll_tools_webp_optimizer_convert', 'll_tools_webp_optimizer_convert_ajax');

function ll_tools_word_images_add_webp_column(array $columns): array {
    $new_columns = [];
    $inserted = false;

    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'attached_image') {
            $new_columns['ll_webp_optimizer'] = __('WebP / Size', 'll-tools-text-domain');
            $inserted = true;
        }
    }

    if (!$inserted) {
        $new_columns['ll_webp_optimizer'] = __('WebP / Size', 'll-tools-text-domain');
    }

    return $new_columns;
}
add_filter('manage_word_images_posts_columns', 'll_tools_word_images_add_webp_column', 20);

function ll_tools_word_images_render_webp_column(string $column, int $post_id): void {
    if ($column !== 'll_webp_optimizer') {
        return;
    }

    echo ll_tools_webp_optimizer_render_list_cell_html((int) $post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action('manage_word_images_posts_custom_column', 'll_tools_word_images_render_webp_column', 20, 2);

function ll_tools_word_images_add_webp_row_action($actions, $post) {
    if (!is_admin() || !current_user_can('view_ll_tools')) {
        return $actions;
    }
    if (!($post instanceof WP_Post) || $post->post_type !== 'word_images') {
        return $actions;
    }

    $item = ll_tools_webp_optimizer_build_item((int) $post->ID);
    if (empty($item['needs_conversion'])) {
        return $actions;
    }

    $url = ll_tools_get_webp_optimizer_admin_url(['word_image_id' => (int) $post->ID]);
    $link = '<a class="ll-tools-webp-optimize-link" href="' . esc_url($url) . '">'
        . '<span class="ll-tools-webp-optimize-link__icon" aria-hidden="true">W</span>'
        . '<span class="ll-tools-webp-optimize-link__label">' . esc_html__('WebP Optimize', 'll-tools-text-domain') . '</span>'
        . '</a>';

    if (!is_array($actions)) {
        return ['ll_tools_webp_optimize' => $link];
    }

    $updated = [];
    $inserted = false;
    foreach ($actions as $key => $value) {
        $updated[$key] = $value;
        if ($key === 'edit') {
            $updated['ll_tools_webp_optimize'] = $link;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $updated['ll_tools_webp_optimize'] = $link;
    }

    return $updated;
}
add_filter('post_row_actions', 'll_tools_word_images_add_webp_row_action', 15, 2);
