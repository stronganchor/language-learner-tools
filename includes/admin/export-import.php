<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LL Tools — Export/Import admin page for word categories and word image posts.
 *
 * Exports a zip (data.json + images) and imports the same bundle to recreate
 * categories, word image posts, and their featured images. Designed to let
 * users run smaller batches by exporting a single category if desired.
 */

/**
 * Register the admin page under Tools.
 */
function ll_tools_register_export_import_page() {
    add_management_page(
        'LL Export/Import',
        'LL Export/Import',
        'view_ll_tools',
        'll-export-import',
        'll_tools_render_export_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_export_import_page');

add_action('admin_post_ll_tools_export_bundle', 'll_tools_handle_export_bundle');
add_action('admin_post_ll_tools_import_bundle', 'll_tools_handle_import_bundle');

/**
 * Render the Export/Import page.
 */
function ll_tools_render_export_import_page() {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $import_result = get_transient('ll_tools_import_result');
    if ($import_result !== false) {
        delete_transient('ll_tools_import_result');
        $is_success = !empty($import_result['ok']) && empty($import_result['errors']);
        $notice_class = $is_success ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>';
        echo esc_html($import_result['message']);
        if (!empty($import_result['stats'])) {
            $stats = $import_result['stats'];
            $stat_bits = [];
            foreach (['categories_created', 'categories_updated', 'word_images_created', 'word_images_updated', 'attachments_imported'] as $key) {
                if (!empty($stats[$key])) {
                    $stat_bits[] = esc_html($stats[$key] . ' ' . str_replace('_', ' ', $key));
                }
            }
            if (!empty($stat_bits)) {
                echo '<br>' . esc_html(implode(' | ', $stat_bits));
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

    if (!class_exists('ZipArchive')) {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('ZipArchive is not available on this server. Please enable it to use the export/import tool.', 'll-tools-text-domain');
        echo '</p></div>';
        return;
    }

    $export_action = admin_url('admin-post.php');
    $import_action = admin_url('admin-post.php');
    $import_dir = ll_tools_get_import_dir();
    $import_dir_ready = ll_tools_ensure_import_dir($import_dir);
    $import_files = $import_dir_ready ? ll_tools_list_import_zips($import_dir) : [];
    $import_dir_display = wp_normalize_path($import_dir);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Tools Export/Import', 'll-tools-text-domain'); ?></h1>

        <p><?php esc_html_e('Export word categories and word image posts (with their images) to a single zip. Import the zip on another site to recreate them.', 'll-tools-text-domain'); ?></p>
        <p class="description"><?php esc_html_e('Tip: For large media libraries, export/import one category at a time to keep the bundle size smaller.', 'll-tools-text-domain'); ?></p>

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

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Download export (.zip)', 'll-tools-text-domain'); ?></button></p>
        </form>

        <hr>

        <h2><?php esc_html_e('Import', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo esc_url($import_action); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ll_tools_import_bundle'); ?>
            <input type="hidden" name="action" value="ll_tools_import_bundle">

            <p><label for="ll_import_file"><strong><?php esc_html_e('Upload export zip (optional)', 'll-tools-text-domain'); ?></strong></label></p>
            <input type="file" name="ll_import_file" id="ll_import_file" accept=".zip">
            <p class="description"><?php esc_html_e('Use a zip generated by the exporter above. Imports categories, word image posts, and their images.', 'll-tools-text-domain'); ?></p>

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

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Import', 'll-tools-text-domain'); ?></button></p>
        </form>
    </div>
    <?php
}

/**
 * Handle the export action: build data and stream a zip file.
 */
function ll_tools_handle_export_bundle() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to export LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_export_bundle');

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    $category_id = isset($_POST['ll_word_category']) ? (int) $_POST['ll_word_category'] : 0;

    @set_time_limit(0);
    $export = ll_tools_build_export_payload($category_id);
    if (is_wp_error($export)) {
        wp_die($export->get_error_message());
    }

    $zip_path = wp_tempnam('ll-tools-export.zip');
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::OVERWRITE) !== true) {
        wp_die(__('Could not create export zip.', 'll-tools-text-domain'));
    }

    $data_json = wp_json_encode($export['data']);
    $zip->addFromString('data.json', $data_json);

    foreach ($export['attachments'] as $attachment) {
        if (!empty($attachment['path']) && file_exists($attachment['path'])) {
            $zip->addFile($attachment['path'], $attachment['zip_path']);
        }
    }

    $zip->close();

    $filename = 'll-tools-export-' . date('Ymd-His') . '.zip';
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    @unlink($zip_path);
    exit;
}

/**
 * Handle the import action: upload zip, unpack, and rebuild objects.
 */
function ll_tools_handle_import_bundle() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to import LL Tools data.', 'll-tools-text-domain'));
    }
    check_admin_referer('ll_tools_import_bundle');

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $uploaded_file = !empty($_FILES['ll_import_file']['name']);
    $existing_file = '';
    if (!empty($_POST['ll_import_existing'])) {
        $existing_file = sanitize_file_name(wp_unslash($_POST['ll_import_existing']));
    }

    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'stats'   => [],
    ];

    if (!$uploaded_file && $existing_file === '') {
        $result['message'] = __('Import failed: please choose a zip file to import.', 'll-tools-text-domain');
        ll_tools_store_import_result_and_redirect($result);
    }

    $zip_path = '';
    $cleanup_zip = false;

    if ($uploaded_file) {
        $upload = wp_handle_upload($_FILES['ll_import_file'], [
            'test_form' => false,
            'mimes'     => ['zip' => 'application/zip'],
        ]);
        if (isset($upload['error'])) {
            $result['message'] = __('Import failed: could not upload file.', 'll-tools-text-domain');
            $result['errors'][] = $upload['error'];
            ll_tools_store_import_result_and_redirect($result);
        }
        $zip_path = $upload['file'];
        $cleanup_zip = true;
    } else {
        $import_dir = ll_tools_get_import_dir();
        if (!ll_tools_ensure_import_dir($import_dir)) {
            $result['message'] = __('Import failed: server import folder is not available.', 'll-tools-text-domain');
            ll_tools_store_import_result_and_redirect($result);
        }
        $existing_path = ll_tools_get_existing_import_zip_path($existing_file, $import_dir);
        if (is_wp_error($existing_path)) {
            $result['message'] = $existing_path->get_error_message();
            ll_tools_store_import_result_and_redirect($result);
        }
        $zip_path = $existing_path;
    }

    $processed = ll_tools_process_import_zip($zip_path);
    if ($cleanup_zip) {
        @unlink($zip_path);
    }

    ll_tools_store_import_result_and_redirect($processed);
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
 * Build the payload and attachment list for export.
 *
 * @param int $root_category_id Category to scope to (0 = all).
 * @return array|WP_Error
 */
function ll_tools_build_export_payload($root_category_id = 0) {
    $terms = ll_tools_get_export_terms($root_category_id);
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

    $term_ids = array_map('intval', array_keys($term_by_id));
    $query_args = [
        'post_type'      => 'word_images',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ($root_category_id > 0 && !empty($term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy'         => 'word-category',
            'field'            => 'term_id',
            'terms'            => $term_ids,
            'include_children' => true,
        ]];
    }

    $posts = get_posts($query_args);
    $attachments = [];
    $word_images = [];

    foreach ($posts as $post) {
        $meta = ll_tools_prepare_meta_for_export(get_post_meta($post->ID));
        $categories_for_post = wp_get_object_terms($post->ID, 'word-category', ['fields' => 'slugs']);

        $featured_image = null;
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $file_path = get_attached_file($thumb_id);
            if ($file_path && file_exists($file_path)) {
                $zip_rel = 'media/' . $thumb_id . '-' . basename($file_path);
                $attachments[$thumb_id] = [
                    'path'     => $file_path,
                    'zip_path' => $zip_rel,
                ];
                $featured_image = [
                    'file'      => $zip_rel,
                    'mime_type' => get_post_mime_type($thumb_id),
                    'alt'       => get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
                    'title'     => get_the_title($thumb_id),
                ];
            }
        }

        $word_images[] = [
            'slug'           => $post->post_name,
            'title'          => $post->post_title,
            'status'         => $post->post_status,
            'meta'           => $meta,
            'categories'     => is_array($categories_for_post) ? array_values($categories_for_post) : [],
            'featured_image' => $featured_image,
        ];
    }

    return [
        'data' => [
            'version'        => 1,
            'exported_at'    => current_time('mysql', true),
            'site'           => home_url(),
            'category_scope' => $root_category_id ?: 'all',
            'categories'     => $categories,
            'word_images'    => $word_images,
        ],
        'attachments' => array_values($attachments),
    ];
}

/**
 * Prepare meta for export by removing transient/editor keys and unserializing values.
 *
 * @param array $raw_meta Meta from get_post_meta or get_term_meta.
 * @return array
 */
function ll_tools_prepare_meta_for_export($raw_meta) {
    $filtered = [];
    $skip_keys = ['_edit_lock', '_edit_last', '_thumbnail_id'];

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
 * Get the set of word-category terms to export, optionally scoped to a root term.
 *
 * @param int $root_category_id
 * @return array|WP_Error
 */
function ll_tools_get_export_terms($root_category_id = 0) {
    $args = [
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];

    if ($root_category_id > 0) {
        $args['child_of'] = $root_category_id;
    }

    $terms = get_terms($args);
    if (is_wp_error($terms)) {
        return $terms;
    }

    if ($root_category_id > 0) {
        $root = get_term($root_category_id, 'word-category');
        if ($root && !is_wp_error($root)) {
            $terms[] = $root;
        }
    }

    $deduped = [];
    foreach ($terms as $term) {
        if (isset($term->term_id)) {
            $deduped[$term->term_id] = $term;
        }
    }

    return array_values($deduped);
}

/**
 * Process the uploaded zip: extract, parse JSON, and import content.
 *
 * @param string $zip_path
 * @return array Result payload for notices.
 */
function ll_tools_process_import_zip($zip_path) {
    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'stats'   => [
            'categories_created' => 0,
            'categories_updated' => 0,
            'word_images_created' => 0,
            'word_images_updated' => 0,
            'attachments_imported' => 0,
        ],
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
    wp_mkdir_p($extract_dir);
    $zip->extractTo($extract_dir);
    $zip->close();

    $data_path = trailingslashit($extract_dir) . 'data.json';
    if (!file_exists($data_path)) {
        $result['message'] = __('Import failed: data.json not found inside the zip.', 'll-tools-text-domain');
        ll_tools_rrmdir($extract_dir);
        return $result;
    }

    $data_contents = file_get_contents($data_path);
    $payload = json_decode($data_contents, true);
    if (!is_array($payload)) {
        $result['message'] = __('Import failed: data.json is not valid JSON.', 'll-tools-text-domain');
        ll_tools_rrmdir($extract_dir);
        return $result;
    }

    @set_time_limit(0);
    $imported = ll_tools_import_from_payload($payload, $extract_dir);
    ll_tools_rrmdir($extract_dir);

    return $imported;
}

/**
 * Import categories, word images, and attachments from a payload and extracted directory.
 *
 * @param array $payload
 * @param string $extract_dir
 * @return array
 */
function ll_tools_import_from_payload(array $payload, $extract_dir) {
    $result = [
        'ok'      => false,
        'message' => '',
        'errors'  => [],
        'stats'   => [
            'categories_created' => 0,
            'categories_updated' => 0,
            'word_images_created' => 0,
            'word_images_updated' => 0,
            'attachments_imported' => 0,
        ],
    ];

    if (!array_key_exists('categories', $payload) || !array_key_exists('word_images', $payload)) {
        $result['message'] = __('Import failed: payload missing categories or word images.', 'll-tools-text-domain');
        return $result;
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

        $insert = wp_insert_term($cat['name'], 'word-category', [
            'slug'        => $slug,
            'description' => isset($cat['description']) ? $cat['description'] : '',
        ]);

        if (is_wp_error($insert)) {
            $result['errors'][] = sprintf(__('Category "%s" could not be created: %s', 'll-tools-text-domain'), $cat['name'], $insert->get_error_message());
            continue;
        }

        $slug_to_term_id[$slug] = (int) $insert['term_id'];
        $result['stats']['categories_created']++;
    }

    // Apply parents now that all slugs are mapped.
    foreach ((array) $payload['categories'] as $cat) {
        if (empty($cat['parent_slug'])) {
            continue;
        }
        $child_slug = isset($cat['slug']) ? sanitize_title($cat['slug']) : '';
        $parent_slug = sanitize_title($cat['parent_slug']);
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
        if (!empty($cat['meta']) && is_array($cat['meta'])) {
            foreach ($cat['meta'] as $key => $values) {
                delete_term_meta($slug_to_term_id[$slug], $key);
                foreach ((array) $values as $val) {
                    add_term_meta($slug_to_term_id[$slug], $key, $val);
                }
            }
        }
    }

    // Import word images.
    foreach ((array) $payload['word_images'] as $item) {
        $slug = isset($item['slug']) ? sanitize_title($item['slug']) : '';
        if ($slug === '') {
            continue;
        }

        $existing = get_page_by_path($slug, OBJECT, 'word_images');
        $postarr = [
            'post_title'  => isset($item['title']) ? $item['title'] : '',
            'post_status' => isset($item['status']) ? $item['status'] : 'publish',
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
        } else {
            $postarr['post_author'] = get_current_user_id();
            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                $result['errors'][] = sprintf(__('Failed to create word image "%s": %s', 'll-tools-text-domain'), $slug, $post_id->get_error_message());
                continue;
            }
            $result['stats']['word_images_created']++;
        }

        // Sync taxonomy assignments.
        $term_ids = [];
        if (!empty($item['categories']) && is_array($item['categories'])) {
            foreach ($item['categories'] as $cat_slug) {
                $cat_slug = sanitize_title($cat_slug);
                if (isset($slug_to_term_id[$cat_slug])) {
                    $term_ids[] = $slug_to_term_id[$cat_slug];
                }
            }
        }
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'word-category', false);
        }

        // Sync meta.
        if (!empty($item['meta']) && is_array($item['meta'])) {
            foreach ($item['meta'] as $key => $values) {
                delete_post_meta($post_id, $key);
                foreach ((array) $values as $val) {
                    add_post_meta($post_id, $key, $val);
                }
            }
        }

        // Import featured image.
        if (!empty($item['featured_image']['file'])) {
            $rel = ltrim((string) $item['featured_image']['file'], '/');
            if (strpos($rel, '..') !== false) {
                $result['errors'][] = sprintf(__('Skipped thumbnail for "%s" because the file path was invalid.', 'll-tools-text-domain'), $slug);
            } else {
                $absolute = trailingslashit($extract_dir) . $rel;
                if (file_exists($absolute)) {
                    $attachment_id = ll_tools_import_attachment_from_file($absolute, $item['featured_image'], $post_id);
                    if (is_wp_error($attachment_id)) {
                        $result['errors'][] = sprintf(__('Failed to import image for "%1$s": %2$s', 'll-tools-text-domain'), $slug, $attachment_id->get_error_message());
                    } else {
                        set_post_thumbnail($post_id, $attachment_id);
                        $result['stats']['attachments_imported']++;
                    }
                } else {
                    $result['errors'][] = sprintf(__('Image file for "%s" is missing from the zip.', 'll-tools-text-domain'), $slug);
                }
            }
        }
    }

    $result['ok'] = empty($result['errors']);
    $result['message'] = $result['ok']
        ? __('Import complete.', 'll-tools-text-domain')
        : __('Import finished with some errors.', 'll-tools-text-domain');

    return $result;
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
    $upload_dir = wp_upload_dir();
    if (!wp_mkdir_p($upload_dir['path'])) {
        return new WP_Error('ll_tools_upload_path', __('Could not create uploads directory.', 'll-tools-text-domain'));
    }

    $filename = wp_unique_filename($upload_dir['path'], basename($file_path));
    $target = trailingslashit($upload_dir['path']) . $filename;

    if (!@copy($file_path, $target)) {
        return new WP_Error('ll_tools_copy_failed', __('Could not copy image into uploads.', 'll-tools-text-domain'));
    }

    $filetype = wp_check_filetype($filename, null);
    $attachment = [
        'guid'           => trailingslashit($upload_dir['url']) . $filename,
        'post_mime_type' => !empty($filetype['type']) ? $filetype['type'] : (!empty($info['mime_type']) ? $info['mime_type'] : 'image/jpeg'),
        'post_title'     => !empty($info['title']) ? $info['title'] : preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $target, $parent_post_id);
    if (is_wp_error($attach_id)) {
        return $attach_id;
    }

    $metadata = wp_generate_attachment_metadata($attach_id, $target);
    wp_update_attachment_metadata($attach_id, $metadata);

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
    wp_safe_redirect(admin_url('tools.php?page=ll-export-import'));
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
