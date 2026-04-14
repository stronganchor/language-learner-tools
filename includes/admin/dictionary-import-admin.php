<?php
if (!defined('WPINC')) { die; }

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
 * Register the dictionary importer admin page.
 */
function ll_tools_register_dictionary_import_page(): void {
    add_management_page(
        __('LL Dictionary Import', 'll-tools-text-domain'),
        __('LL Dictionary Import', 'll-tools-text-domain'),
        ll_tools_get_dictionary_import_capability(),
        'll-dictionary-import',
        'll_tools_render_dictionary_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_dictionary_import_page');

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

    while (($data = fgetcsv($handle, 0, "\t")) !== false) {
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
            $header = array_map(static function (string $value): string {
                return strtolower(trim($value));
            }, $data);
            $looks_like_header = in_array('entry', $header, true)
                || in_array('definition', $header, true)
                || in_array('gender_number', $header, true)
                || in_array('entry_type', $header, true)
                || in_array('page_number', $header, true);
            if ($looks_like_header) {
                continue;
            }
        }

        if (count($data) < 1) {
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
 * Render one import summary notice.
 *
 * @param array<string,mixed> $summary
 */
function ll_tools_render_dictionary_import_summary(array $summary, string $heading): void {
    $entries_created = (int) ($summary['entries_created'] ?? 0);
    $entries_updated = (int) ($summary['entries_updated'] ?? 0);
    $rows_total = (int) ($summary['rows_total'] ?? 0);
    $rows_grouped = (int) ($summary['rows_grouped'] ?? 0);
    $rows_skipped_empty = (int) ($summary['rows_skipped_empty'] ?? 0);
    $rows_skipped_review = (int) ($summary['rows_skipped_review'] ?? 0);
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($summary['entry_ids'] ?? [])))));
    $errors = array_values(array_filter(array_map('strval', (array) ($summary['errors'] ?? []))));

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
}

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
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Dictionary Import', 'll-tools-text-domain'); ?></h1>
        <p>
            <?php esc_html_e('Import TSV dictionaries or migrate the older one-off dictionary table into LL Tools dictionary entries. Imported rows are grouped by headword so search, browse, bulk translations, and word-linking all use the same data.', 'll-tools-text-domain'); ?>
        </p>

        <?php foreach ($errors as $error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endforeach; ?>

        <?php if (is_array($summary)) : ?>
            <?php ll_tools_render_dictionary_import_summary($summary, $summary_heading); ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
            <?php wp_nonce_field('ll_tools_dictionary_import', 'll_dictionary_import_nonce'); ?>
            <input type="hidden" name="ll_dictionary_action" value="import_tsv">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ll-dictionary-tsv"><?php esc_html_e('TSV File', 'll-tools-text-domain'); ?></label></th>
                    <td>
                        <input type="file" name="ll_dictionary_tsv" id="ll-dictionary-tsv" accept=".tsv,text/tab-separated-values" required>
                        <p class="description">
                            <?php esc_html_e('Expected columns: entry, definition, gender_number, entry_type, parent, needs_review, page_number.', 'll-tools-text-domain'); ?>
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
                            <?php esc_html_e('Use a word set when this dictionary belongs to one language/course only. Existing headwords are matched within the same word set.', 'll-tools-text-domain'); ?>
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

        <h2><?php esc_html_e('Legacy Migration', 'll-tools-text-domain'); ?></h2>
        <?php if ($legacy_table_exists) : ?>
            <p><?php esc_html_e('A legacy raw dictionary table was found. This will group those rows into LL Tools dictionary entries so the old one-off plugin can be removed cleanly.', 'll-tools-text-domain'); ?></p>
            <form method="post">
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
    </div>
    <?php
}
