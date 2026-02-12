<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LL Tools — Export/Import admin page for category bundles.
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

function ll_tools_current_user_can_export_import() {
    return current_user_can(ll_tools_get_export_import_capability());
}

function ll_tools_register_export_import_page() {
    add_management_page(
        'LL Export/Import',
        'LL Export/Import',
        ll_tools_get_export_import_capability(),
        'll-export-import',
        'll_tools_render_export_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_export_import_page');

add_action('admin_post_ll_tools_export_bundle', 'll_tools_handle_export_bundle');
add_action('admin_post_ll_tools_import_bundle', 'll_tools_handle_import_bundle');
add_action('admin_post_ll_tools_export_wordset_csv', 'll_tools_handle_export_wordset_csv');
add_action('admin_enqueue_scripts', 'll_tools_enqueue_export_import_assets');

function ll_tools_enqueue_export_import_assets($hook) {
    if ($hook !== 'tools_page_ll-export-import') {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/export-import-admin.css', 'll-tools-export-import-admin', [], false);
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

/**
 * Render the Export/Import page.
 */
function ll_tools_render_export_import_page() {
    if (!ll_tools_current_user_can_export_import()) {
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
            ] as $key) {
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
    $soft_limit_label = $soft_export_limit_bytes > 0 ? size_format($soft_export_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $hard_limit_label = $hard_export_limit_bytes > 0 ? size_format($hard_export_limit_bytes) : __('disabled', 'll-tools-text-domain');
    $hard_files_label = $hard_export_limit_files > 0 ? (string) $hard_export_limit_files : __('disabled', 'll-tools-text-domain');
    ?>
    <div class="wrap ll-tools-export-import">
        <h1><?php esc_html_e('LL Tools Export/Import', 'll-tools-text-domain'); ?></h1>

        <p><?php esc_html_e('Export category bundles as a zip. Image mode includes categories and word images; full mode also includes words, audio, and source word sets.', 'll-tools-text-domain'); ?></p>
        <p class="description"><?php esc_html_e('Tip: Export one category at a time for large media libraries.', 'll-tools-text-domain'); ?></p>

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

        <hr>

        <h2><?php esc_html_e('Import', 'll-tools-text-domain'); ?></h2>
        <form method="post" action="<?php echo esc_url($import_action); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ll_tools_import_bundle'); ?>
            <input type="hidden" name="action" value="ll_tools_import_bundle">

            <p><label for="ll_import_file"><strong><?php esc_html_e('Upload export zip (optional)', 'll-tools-text-domain'); ?></strong></label></p>
            <input type="file" name="ll_import_file" id="ll_import_file" accept=".zip">
            <p class="description"><?php esc_html_e('Use a zip generated by the exporter above. Legacy bundles import categories + word images. Full bundles also import words and audio.', 'll-tools-text-domain'); ?></p>

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

            <p><strong><?php esc_html_e('Full bundle word set handling', 'll-tools-text-domain'); ?></strong></p>
            <fieldset class="ll-tools-import-wordset-mode">
                <label for="ll_import_wordset_mode_create">
                    <input type="radio" id="ll_import_wordset_mode_create" name="ll_import_wordset_mode" value="create_from_export" checked>
                    <?php esc_html_e('Create or reuse source word set(s) from the export', 'll-tools-text-domain'); ?>
                </label>
                <label for="ll_import_wordset_mode_assign">
                    <input type="radio" id="ll_import_wordset_mode_assign" name="ll_import_wordset_mode" value="assign_existing">
                    <?php esc_html_e('Assign all imported words to one existing word set', 'll-tools-text-domain'); ?>
                </label>
                <select id="ll_import_target_wordset" name="ll_import_target_wordset" class="ll-tools-input"<?php echo $has_wordsets ? '' : ' disabled'; ?>>
                    <?php if (!$has_wordsets) : ?>
                        <option value="0"><?php esc_html_e('No word sets found', 'll-tools-text-domain'); ?></option>
                    <?php else : ?>
                        <option value="0"><?php esc_html_e('Select an existing word set', 'll-tools-text-domain'); ?></option>
                        <?php foreach ($wordsets as $wordset) : ?>
                            <option value="<?php echo (int) $wordset->term_id; ?>">
                                <?php echo esc_html($wordset->name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </fieldset>
            <p class="description"><?php esc_html_e('This setting is used only when the zip contains full category data with words/audio.', 'll-tools-text-domain'); ?></p>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Import', 'll-tools-text-domain'); ?></button></p>
        </form>
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

    @set_time_limit(0);
    $export = ll_tools_build_export_payload($category_id, [
        'include_full_bundle' => $include_full_bundle,
    ]);
    if (is_wp_error($export)) {
        wp_die($export->get_error_message());
    }

    $attachment_count = isset($export['stats']['attachment_count']) ? (int) $export['stats']['attachment_count'] : count((array) ($export['attachments'] ?? []));
    $attachment_bytes = isset($export['stats']['attachment_bytes']) ? (int) $export['stats']['attachment_bytes'] : 0;
    $hard_limit_bytes = ll_tools_export_get_hard_limit_bytes();
    $hard_limit_files = ll_tools_export_get_hard_limit_files();
    $soft_limit_bytes = ll_tools_export_get_soft_limit_bytes();

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

    if ($soft_limit_bytes > 0 && $attachment_bytes > $soft_limit_bytes && !$allow_large_export) {
        wp_die(sprintf(
            /* translators: 1: estimated size, 2: warning threshold */
            __('Export warning: estimated media size is %1$s (threshold %2$s). Tick \"Allow export when warning threshold is exceeded\" and run export again.', 'll-tools-text-domain'),
            size_format($attachment_bytes),
            size_format($soft_limit_bytes)
        ));
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

    $mode_suffix = $include_full_bundle ? 'full' : 'images';
    $filename = 'll-tools-export-' . $mode_suffix . '-' . date('Ymd-His') . '.zip';
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    @unlink($zip_path);
    exit;
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

    $import_options = ll_tools_parse_import_options($_POST);
    $processed = ll_tools_process_import_zip($zip_path, $import_options);
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
 * Parse optional import controls from the submitted form.
 *
 * @param array $request
 * @return array
 */
function ll_tools_parse_import_options(array $request): array {
    $mode = 'create_from_export';
    if (!empty($request['ll_import_wordset_mode'])) {
        $candidate = sanitize_key(wp_unslash((string) $request['ll_import_wordset_mode']));
        if (in_array($candidate, ['create_from_export', 'assign_existing'], true)) {
            $mode = $candidate;
        }
    }

    $target_wordset_id = 0;
    if (!empty($request['ll_import_target_wordset'])) {
        $target_wordset_id = (int) wp_unslash((string) $request['ll_import_target_wordset']);
    }

    return [
        'wordset_mode'      => $mode,
        'target_wordset_id' => $target_wordset_id,
    ];
}

/**
 * Build the payload and attachment list for export.
 *
 * @param int $root_category_id Category to scope to (0 = all).
 * @param array $options
 * @return array|WP_Error
 */
function ll_tools_build_export_payload($root_category_id = 0, array $options = []) {
    $include_full_bundle = !empty($options['include_full_bundle']);
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

    if ($root_category_id > 0 && !empty($term_ids)) {
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
        $full_payload = ll_tools_export_collect_full_words_payload($term_ids, $allowed_category_lookup, $attachments, $attachment_tracker, $root_category_id);
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
            'category_scope' => $root_category_id ?: 'all',
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

    if (isset($attachments[$file_path])) {
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

    $attachments[$file_path] = [
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

    $file_path = get_attached_file($thumb_id);
    if (!$file_path || !is_file($file_path)) {
        return null;
    }

    $zip_rel = trim((string) $zip_dir, '/') . '/' . $thumb_id . '-' . basename($file_path);
    $tracked = ll_tools_export_track_attachment_file($file_path, $zip_rel, $attachments, $tracker);
    if (is_wp_error($tracked)) {
        return $tracked;
    }

    return [
        'file'      => $zip_rel,
        'mime_type' => get_post_mime_type($thumb_id),
        'alt'       => get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
        'title'     => get_the_title($thumb_id),
    ];
}

/**
 * Collect wordset terms, words, and word_audio entries for full bundle mode.
 *
 * @param array $category_term_ids
 * @param array $allowed_category_lookup
 * @param array $attachments
 * @param array $tracker
 * @param int $root_category_id
 * @return array|WP_Error
 */
function ll_tools_export_collect_full_words_payload(array $category_term_ids, array $allowed_category_lookup, array &$attachments, array &$tracker, int $root_category_id = 0) {
    $query_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ($root_category_id > 0 && !empty($category_term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy'         => 'word-category',
            'field'            => 'term_id',
            'terms'            => array_values(array_unique(array_map('intval', $category_term_ids))),
            'include_children' => true,
        ]];
    }

    $words = [];
    $used_wordset_ids = [];
    $word_posts = get_posts($query_args);

    foreach ($word_posts as $word_post) {
        $categories_for_word = ll_tools_export_get_scoped_category_slugs($word_post->ID, $allowed_category_lookup);
        if ($root_category_id > 0 && empty($categories_for_word)) {
            continue;
        }

        $word_meta = ll_tools_prepare_meta_for_export(get_post_meta($word_post->ID), ['_ll_autopicked_image_id']);
        $word_featured_image = ll_tools_export_collect_post_featured_image($word_post->ID, 'media/words', $attachments, $tracker);
        if (is_wp_error($word_featured_image)) {
            return $word_featured_image;
        }

        $wordset_ids = wp_get_post_terms($word_post->ID, 'wordset', ['fields' => 'ids']);
        if (!is_wp_error($wordset_ids)) {
            foreach ((array) $wordset_ids as $wordset_id) {
                $wordset_id = (int) $wordset_id;
                if ($wordset_id > 0) {
                    $used_wordset_ids[$wordset_id] = true;
                }
            }
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
            'wordsets'        => ll_tools_export_collect_post_term_slugs($word_post->ID, 'wordset'),
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
        'stats'   => ll_tools_import_default_stats(),
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
        'stats'   => ll_tools_import_default_stats(),
    ];

    if (!array_key_exists('categories', $payload) || !array_key_exists('word_images', $payload)) {
        $result['message'] = __('Import failed: payload missing categories or word images.', 'll-tools-text-domain');
        return $result;
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
        ll_tools_import_replace_term_meta_values((int) $slug_to_term_id[$slug], isset($cat['meta']) && is_array($cat['meta']) ? $cat['meta'] : []);
    }

    // Import word images.
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
        } else {
            $postarr['post_author'] = get_current_user_id();
            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                $result['errors'][] = sprintf(__('Failed to create word image "%s": %s', 'll-tools-text-domain'), $slug, $post_id->get_error_message());
                continue;
            }
            $result['stats']['word_images_created']++;
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

        ll_tools_import_replace_post_meta_values((int) $post_id, isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : []);
        ll_tools_import_apply_featured_image((int) $post_id, isset($item['featured_image']) ? (array) $item['featured_image'] : [], $extract_dir, $slug, $result, 'word_image');
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
                ],
                $result
            );
        } finally {
            remove_filter('ll_tools_skip_audio_requirement', $skip_audio_cb, 9999);
        }
    }

    $result['ok'] = empty($result['errors']);
    $result['message'] = $result['ok']
        ? __('Import complete.', 'll-tools-text-domain')
        : __('Import finished with some errors.', 'll-tools-text-domain');

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
function ll_tools_import_replace_post_meta_values(int $post_id, array $meta): void {
    if ($post_id <= 0 || empty($meta)) {
        return;
    }

    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if ($key === '') {
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
function ll_tools_import_replace_term_meta_values(int $term_id, array $meta): void {
    if ($term_id <= 0 || empty($meta)) {
        return;
    }

    foreach ($meta as $key => $values) {
        $key = (string) $key;
        if ($key === '') {
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
 * @return void
 */
function ll_tools_import_apply_featured_image(int $post_id, array $featured_image, $extract_dir, string $item_slug, array &$result, string $context = 'post'): void {
    if ($post_id <= 0 || empty($featured_image['file'])) {
        return;
    }

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
    if (is_wp_error($attachment_id)) {
        $label = $context === 'word' ? __('word', 'll-tools-text-domain') : __('word image', 'll-tools-text-domain');
        $result['errors'][] = sprintf(__('Failed to import image for %1$s "%2$s": %3$s', 'll-tools-text-domain'), $label, $item_slug, $attachment_id->get_error_message());
        return;
    }

    set_post_thumbnail($post_id, $attachment_id);
    $result['stats']['attachments_imported']++;
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

    $origin_word_id_to_imported = [];
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
        } else {
            $postarr['post_author'] = get_current_user_id();
            $word_id = wp_insert_post($postarr, true);
            if (is_wp_error($word_id)) {
                $result['errors'][] = sprintf(__('Failed to create word "%s": %s', 'll-tools-text-domain'), $slug, $word_id->get_error_message());
                continue;
            }
            $result['stats']['words_created']++;
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

        ll_tools_import_replace_post_meta_values($word_id, isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : []);
        ll_tools_import_apply_featured_image($word_id, isset($item['featured_image']) ? (array) $item['featured_image'] : [], $extract_dir, $slug, $result, 'word');

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
            } else {
                $audio_postarr['post_author'] = get_current_user_id();
                $audio_post_id = wp_insert_post($audio_postarr, true);
                if (is_wp_error($audio_post_id)) {
                    $result['errors'][] = sprintf(__('Failed to create audio "%1$s" for word "%2$s": %3$s', 'll-tools-text-domain'), $audio_slug, $slug, $audio_post_id->get_error_message());
                    continue;
                }
                $result['stats']['word_audio_created']++;
            }

            $audio_post_id = (int) $audio_post_id;
            ll_tools_import_replace_post_meta_values($audio_post_id, isset($audio_item['meta']) && is_array($audio_item['meta']) ? $audio_item['meta'] : []);

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
                $result
            );
        }
    }

    if (!empty($origin_word_id_to_imported)) {
        ll_tools_import_remap_similar_word_ids($origin_word_id_to_imported);
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
        }

        ll_tools_import_replace_term_meta_values($term_id, isset($wordset['meta']) && is_array($wordset['meta']) ? $wordset['meta'] : []);
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
 * @return void
 */
function ll_tools_import_apply_audio_file(int $audio_post_id, array $audio_file, $extract_dir, string $word_slug, array &$result): void {
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
