<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LL Tools — Bulk Word Import admin page.
 *
 * Allows privileged users to paste a list of vocabulary words and quickly
 * generate draft "words" posts. Titles are normalized so the first
 * character is capitalized, respecting Turkish-style dotted/dotless I
 * rules for Turkish and Zazaki wordsets.
 */

/**
 * Register the admin page under Tools.
 */
function ll_tools_get_bulk_word_import_capability(): string {
    return (string) apply_filters('ll_tools_bulk_word_import_capability', 'manage_options');
}

function ll_tools_current_user_can_bulk_word_import(): bool {
    return current_user_can(ll_tools_get_bulk_word_import_capability());
}

function ll_tools_bulk_word_import_get_selectable_categories(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;

    if ($wordset_id > 0) {
        global $wpdb;

        $category_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT tt_cat.term_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
            INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
            WHERE p.post_type = %s
              AND p.post_status IN (%s, %s, %s, %s, %s)
              AND tt_ws.taxonomy = %s
              AND tt_ws.term_id = %d
              AND tt_cat.taxonomy = %s
        ", 'words', 'publish', 'draft', 'pending', 'future', 'private', 'wordset', $wordset_id, 'word-category'));
        $category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), static function (int $category_id): bool {
            return $category_id > 0;
        }));
        if (empty($category_ids)) {
            return [];
        }

        $categories = get_terms([
            'taxonomy'   => 'word-category',
            'hide_empty' => false,
            'include'    => $category_ids,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        return is_wp_error($categories) ? [] : $categories;
    }

    $categories = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
        'meta_query' => [
            'relation' => 'OR',
            [
                'key'     => defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id',
                'value'   => 0,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ],
        ],
    ]);

    return is_wp_error($categories) ? [] : $categories;
}

function ll_tools_register_bulk_word_import_page() {
    add_management_page(
        __('LL Bulk Word Import', 'll-tools-text-domain'),
        __('LL Bulk Word Import', 'll-tools-text-domain'),
        ll_tools_get_bulk_word_import_capability(),
        'll-bulk-word-import',
        'll_tools_render_bulk_word_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_bulk_word_import_page');

/**
 * Capitalize the first character of a word, respecting Turkish-style casing rules.
 *
 * @param string $word Raw word from the import list.
 * @param int    $wordset_id Word set context for title-language casing rules.
 * @return string Normalized word title.
 */
function ll_tools_import_capitalize_word($word, int $wordset_id = 0) {
    $word = trim((string) $word);
    if ($word === '') {
        return '';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $first = mb_substr($word, 0, 1, 'UTF-8');
        $rest  = mb_substr($word, 1, null, 'UTF-8');

        $wordset_ids = $wordset_id > 0 ? [$wordset_id] : [];
        $title_language_raw = function_exists('ll_tools_get_wordset_title_language_label')
            ? (string) ll_tools_get_wordset_title_language_label($wordset_ids)
            : '';
        if ($title_language_raw === '') {
            $title_language_raw = (string) get_option('ll_target_language', '');
        }
        $uses_turkish_casing = function_exists('ll_tools_language_uses_turkish_casing')
            ? ll_tools_language_uses_turkish_casing($title_language_raw)
            : false;

        if ($uses_turkish_casing) {
            $first = function_exists('ll_tools_uppercase_first_char_for_language')
                ? ll_tools_uppercase_first_char_for_language($first, $title_language_raw)
                : mb_strtoupper($first, 'UTF-8');
        } else {
            $first = mb_strtoupper($first, 'UTF-8');
        }

        return $first . $rest;
    }

    // Fallback if multibyte functions are unavailable.
    return ucfirst($word);
}

/**
 * Parse a pasted bulk-import row into word/translation columns.
 *
 * Supported formats:
 * - `word`
 * - `word<TAB>translation`
 * - `word,translation` (CSV-style, quoted values supported)
 *
 * @param string $raw_line Raw pasted line.
 * @return array{title:string,translation:string,extra_columns:int}
 */
function ll_tools_bulk_word_import_parse_line($raw_line): array {
    $line = trim((string) $raw_line);
    if ($line === '') {
        return [
            'title'         => '',
            'translation'   => '',
            'extra_columns' => 0,
        ];
    }

    $delimiter = '';
    if (strpos($line, "\t") !== false) {
        $delimiter = "\t";
    } elseif (strpos($line, ',') !== false) {
        $delimiter = ',';
    }

    if ($delimiter === '') {
        return [
            'title'         => $line,
            'translation'   => '',
            'extra_columns' => 0,
        ];
    }

    if (function_exists('str_getcsv')) {
        $columns = str_getcsv($line, $delimiter, '"', '\\');
    } else {
        $columns = explode($delimiter, $line);
    }

    if (!is_array($columns) || empty($columns)) {
        $columns = [$line];
    }

    $columns = array_map(
        static function ($value): string {
            return trim((string) $value);
        },
        $columns
    );

    return [
        'title'         => (string) ($columns[0] ?? ''),
        'translation'   => (string) ($columns[1] ?? ''),
        'extra_columns' => max(0, count($columns) - 2),
    ];
}

/**
 * Render the Bulk Word Import page.
 */
function ll_tools_render_bulk_word_import_page() {
    if (!ll_tools_current_user_can_bulk_word_import()) {
        return;
    }

    $messages = [];
    $created = $created_with_translation = $skipped_existing = $skipped_empty = $rows_with_ignored_extra_columns = 0;
    $skipped_existing_words = [];
    $errors  = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ll_bulk_word_import_nonce'])) {
        check_admin_referer('ll_bulk_word_import', 'll_bulk_word_import_nonce');

        $raw_list = isset($_POST['ll_word_list']) ? wp_unslash($_POST['ll_word_list']) : '';
        $raw_lines = preg_split('/\r\n|\n|\r/', (string) $raw_list);
        $parsed_rows = is_array($raw_lines)
            ? array_map('ll_tools_bulk_word_import_parse_line', $raw_lines)
            : [];

        $selected_category = isset($_POST['ll_existing_category']) ? (int) wp_unslash($_POST['ll_existing_category']) : 0;
        $selected_wordset  = isset($_POST['ll_existing_wordset']) ? (int) wp_unslash($_POST['ll_existing_wordset']) : 0;
        $new_category_name = isset($_POST['ll_new_category']) ? sanitize_text_field(wp_unslash($_POST['ll_new_category'])) : '';
        $category_id = 0;

        if ($selected_wordset > 0 && $selected_category > 0 && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset($selected_category, $selected_wordset, true);
            if ($effective_category_id > 0) {
                $selected_category = $effective_category_id;
            }
        }

        if ($new_category_name !== '') {
            if (function_exists('ll_tools_create_or_get_wordset_category')) {
                $result = ll_tools_create_or_get_wordset_category($new_category_name, $selected_wordset);
            } else {
                $existing = term_exists($new_category_name, 'word-category');
                if ($existing) {
                    $result = (int) (is_array($existing) ? $existing['term_id'] : $existing);
                } else {
                    $result = wp_insert_term($new_category_name, 'word-category');
                }
            }

            if (is_wp_error($result)) {
                $errors[] = sprintf(
                    __('Could not create the "%1$s" category: %2$s', 'll-tools-text-domain'),
                    esc_html($new_category_name),
                    esc_html($result->get_error_message())
                );
            } else {
                $category_id = (int) (is_array($result) ? ($result['term_id'] ?? 0) : $result);
                if ($category_id > 0) {
                    $messages[] = [
                        'type' => 'success',
                        'text' => sprintf(
                            __('Created or reused category "%s".', 'll-tools-text-domain'),
                            esc_html($new_category_name)
                        ),
                    ];
                }
            }
        } elseif ($selected_category > 0) {
            $category_id = $selected_category;
        }

        if (trim((string) $raw_list) === '') {
            $errors[] = __('Please provide at least one word to import.', 'll-tools-text-domain');
        }

        if (empty($errors)) {
            foreach ($parsed_rows as $row) {
                $word = (string) ($row['title'] ?? '');
                $translation = sanitize_text_field((string) ($row['translation'] ?? ''));
                $extra_columns = isset($row['extra_columns']) ? (int) $row['extra_columns'] : 0;

                if ($extra_columns > 0) {
                    $rows_with_ignored_extra_columns++;
                }

                $normalized = ll_tools_import_capitalize_word($word, $selected_wordset);
                if ($normalized === '') {
                    $skipped_empty++;
                    continue;
                }

                // Duplicate detection scoped to selected wordset (or unassigned if none chosen), excluding trash
                $existing_ids = ll_tools_find_existing_word_ids_by_title_in_wordset($normalized, $selected_wordset);
                if (!empty($existing_ids)) {
                    $skipped_existing++;
                    $skipped_existing_words[] = $normalized;
                    continue;
                }

                $postarr = [
                    'post_type'   => 'words',
                    'post_status' => 'draft',
                    'post_title'  => $normalized,
                    'post_author' => get_current_user_id(),
                ];

                $post_id = wp_insert_post($postarr, true);
                if (is_wp_error($post_id)) {
                    $errors[] = sprintf(
                        __('Failed to create "%1$s": %2$s', 'll-tools-text-domain'),
                        esc_html($normalized),
                        esc_html($post_id->get_error_message())
                    );
                    continue;
                }

                if ($category_id > 0) {
                    $set_terms = wp_set_post_terms($post_id, [$category_id], 'word-category', false);
                    if (is_wp_error($set_terms)) {
                        $errors[] = sprintf(
                            __('The word "%1$s" was created but could not be assigned to the category: %2$s', 'll-tools-text-domain'),
                            esc_html($normalized),
                            esc_html($set_terms->get_error_message())
                        );
                    }
                }

                if ($selected_wordset > 0) {
                    $set_ws = wp_set_object_terms($post_id, [$selected_wordset], 'wordset', false);
                    if (is_wp_error($set_ws)) {
                        $errors[] = sprintf(
                            __('The word "%1$s" was created but could not be assigned to the word set: %2$s', 'll-tools-text-domain'),
                            esc_html($normalized),
                            esc_html($set_ws->get_error_message())
                        );
                    }
                }

                if ($translation !== '') {
                    update_post_meta($post_id, 'word_translation', $translation);
                    $created_with_translation++;
                }

                $created++;
            }

            if ($created > 0) {
                $messages[] = [
                    'type' => 'success',
                    'text' => sprintf(
                        _n('%d word was imported as a draft.', '%d words were imported as drafts.', $created, 'll-tools-text-domain'),
                        $created
                    ),
                ];
            }

            if ($created_with_translation > 0) {
                $messages[] = [
                    'type' => 'success',
                    'text' => sprintf(
                        _n(
                            '%d imported word included a translation.',
                            '%d imported words included translations.',
                            $created_with_translation,
                            'll-tools-text-domain'
                        ),
                        $created_with_translation
                    ),
                ];
            }

            if ($skipped_existing > 0) {
                // Show which specific words were skipped for easier review.
                $skipped_list = esc_html(implode(', ', $skipped_existing_words));
                $messages[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        _n(
                            '%1$d word was skipped because it already exists: %2$s',
                            '%1$d words were skipped because they already exist: %2$s',
                            $skipped_existing,
                            'll-tools-text-domain'
                        ),
                        $skipped_existing,
                        $skipped_list
                    ),
                ];
            }

            if ($skipped_empty > 0) {
                $messages[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        _n('%d empty line was ignored.', '%d empty lines were ignored.', $skipped_empty, 'll-tools-text-domain'),
                        $skipped_empty
                    ),
                ];
            }

            if ($rows_with_ignored_extra_columns > 0) {
                $messages[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        _n(
                            'Ignored extra columns on %d row. Only the first two columns were imported.',
                            'Ignored extra columns on %d rows. Only the first two columns were imported.',
                            $rows_with_ignored_extra_columns,
                            'll-tools-text-domain'
                        ),
                        $rows_with_ignored_extra_columns
                    ),
                ];
            }
        }
    }

    // Fetch existing word sets for assignment
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }
    // Try to preselect the default wordset when available
    $default_wordset_id = 0;
    if (function_exists('ll_get_default_wordset_term_id')) {
        $default_wordset_id = (int) ll_get_default_wordset_term_id();
    }
    $has_posted_wordset = isset($_POST['ll_existing_wordset']);
    $posted_wordset = $has_posted_wordset ? (int) wp_unslash($_POST['ll_existing_wordset']) : 0;
    $selected_wordset_effective = $has_posted_wordset ? $posted_wordset : $default_wordset_id;
    $selected_category_effective = isset($_POST['ll_existing_category']) ? (int) wp_unslash($_POST['ll_existing_category']) : 0;
    if ($selected_wordset_effective > 0 && $selected_category_effective > 0 && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset($selected_category_effective, $selected_wordset_effective, true);
        if ($effective_category_id > 0) {
            $selected_category_effective = $effective_category_id;
        }
    }
    $categories = ll_tools_bulk_word_import_get_selectable_categories($selected_wordset_effective);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Tools — Bulk Word Import', 'll-tools-text-domain'); ?></h1>

        <?php foreach ($messages as $notice) : ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo wp_kses_post($notice['text']); ?></p></div>
        <?php endforeach; ?>

        <?php if (!empty($errors)) : ?>
            <div class="notice notice-error">
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo wp_kses_post($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('ll_bulk_word_import', 'll_bulk_word_import_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ll-word-list"><?php esc_html_e('Words to import', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <textarea id="ll-word-list" name="ll_word_list" rows="12" cols="60" class="large-text code" placeholder="<?php esc_attr_e('Enter one word per line, or word + translation separated by a tab or comma', 'll-tools-text-domain'); ?>"><?php echo isset($_POST['ll_word_list']) ? esc_textarea(wp_unslash($_POST['ll_word_list'])) : ''; ?></textarea>
                            <p class="description"><?php esc_html_e('Each row creates a new draft word post with the title automatically capitalized.', 'll-tools-text-domain'); ?></p>
                            <p class="description"><?php echo wp_kses_post(sprintf(__('Word only: %s', 'll-tools-text-domain'), '<code>bonjour</code>')); ?></p>
                            <p class="description"><?php echo wp_kses_post(sprintf(__('Word + translation with a tab: %s', 'll-tools-text-domain'), '<code>bonjour[TAB]hello</code>')); ?></p>
                            <p class="description"><?php echo wp_kses_post(sprintf(__('Word + translation with a comma: %s', 'll-tools-text-domain'), '<code>bonjour,hello</code>')); ?></p>
                            <p class="description"><?php esc_html_e('Copying two spreadsheet columns from Excel or Google Sheets usually produces tab-separated rows and works as-is. If a word or translation contains a comma, wrap that value in double quotes.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Assign to word set', 'll-tools-text-domain'); ?></th>
                        <td>
                            <select name="ll_existing_wordset" class="regular-text">
                                <option value="0"><?php esc_html_e('Leave unassigned', 'll-tools-text-domain'); ?></option>
                                <?php foreach ($wordsets as $set) : ?>
                                    <option value="<?php echo (int) $set->term_id; ?>" <?php selected($selected_wordset_effective, (int) $set->term_id); ?>><?php echo esc_html($set->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Choose a word set to assign to newly imported words so they appear in the recording interface for that set.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Assign to existing category', 'll-tools-text-domain'); ?></th>
                        <td>
                            <select name="ll_existing_category" class="regular-text">
                                <option value="0"><?php esc_html_e('Leave uncategorized', 'll-tools-text-domain'); ?></option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected($selected_category_effective, (int) $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Choose an existing word category for the imported words, or leave uncategorized.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ll-new-category"><?php esc_html_e('Create a new category', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <input type="text" id="ll-new-category" name="ll_new_category" class="regular-text" value="<?php echo isset($_POST['ll_new_category']) ? esc_attr(wp_unslash($_POST['ll_new_category'])) : ''; ?>" />
                            <p class="description"><?php esc_html_e('Optional. If provided, the new category will be created and used for all imported words.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Import words', 'll-tools-text-domain')); ?>
        </form>
    </div>
    <?php
}

/**
 * Find existing non-trashed "words" posts by exact title within a wordset scope.
 * - If $wordset_term_id > 0: only posts assigned to that wordset.
 * - If $wordset_term_id === 0: only posts with NO wordset assigned.
 * Returns an array of post IDs.
 */
function ll_tools_find_existing_word_ids_by_title_in_wordset($title, $wordset_term_id = 0) {
    if (function_exists('ll_tools_lookup_existing_word_ids_by_title_in_wordset')) {
        return ll_tools_lookup_existing_word_ids_by_title_in_wordset((string) $title, (int) $wordset_term_id);
    }

    global $wpdb;
    $title = (string) $title;
    $wordset_term_id = (int) $wordset_term_id;

    if ($wordset_term_id > 0) {
        $sql = $wpdb->prepare(
            "SELECT p.ID\n             FROM {$wpdb->posts} p\n             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID\n             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wordset'\n             WHERE tt.term_id = %d\n               AND p.post_type = 'words'\n               AND p.post_status NOT IN ('trash','auto-draft')\n               AND p.post_title = %s",
            $wordset_term_id,
            $title
        );
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    $sql = $wpdb->prepare(
        "SELECT p.ID\n         FROM {$wpdb->posts} p\n         LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID\n         LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wordset'\n         WHERE tt.term_taxonomy_id IS NULL\n           AND p.post_type = 'words'\n           AND p.post_status NOT IN ('trash','auto-draft')\n           AND p.post_title = %s",
        $title
    );
    return array_map('intval', (array) $wpdb->get_col($sql));
}
