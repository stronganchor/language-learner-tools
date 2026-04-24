<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LL Tools - Prompt Audio Queue admin page.
 *
 * Allows privileged users to paste prompt lines and generate draft prompt cards
 * whose prompt audio can be recorded through the normal recorder interface.
 */

function ll_tools_get_prompt_audio_import_capability(): string {
    return (string) apply_filters('ll_tools_prompt_audio_import_capability', 'manage_options');
}

function ll_tools_current_user_can_prompt_audio_import(): bool {
    return current_user_can(ll_tools_get_prompt_audio_import_capability());
}

function ll_tools_register_prompt_audio_import_page(): void {
    add_management_page(
        __('LL Prompt Audio Queue', 'll-tools-text-domain'),
        __('LL Prompt Audio Queue', 'll-tools-text-domain'),
        ll_tools_get_prompt_audio_import_capability(),
        'll-prompt-audio-import',
        'll_tools_render_prompt_audio_import_page'
    );
}
add_action('admin_menu', 'll_tools_register_prompt_audio_import_page');

function ll_tools_prompt_audio_import_enqueue_admin_assets(string $hook_suffix): void {
    if ($hook_suffix !== 'tools_page_ll-prompt-audio-import') {
        return;
    }
    if (!ll_tools_current_user_can_prompt_audio_import()) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/js/bulk-word-import-admin.js', 'll-tools-bulk-word-import-admin', [], true);

    $category_rows_by_wordset = [
        '0' => function_exists('ll_tools_bulk_word_import_get_selectable_category_rows')
            ? ll_tools_bulk_word_import_get_selectable_category_rows(0)
            : [],
    ];
    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if (!is_wp_error($wordsets)) {
        foreach ((array) $wordsets as $wordset_id) {
            $category_rows_by_wordset[(string) ((int) $wordset_id)] = function_exists('ll_tools_bulk_word_import_get_selectable_category_rows')
                ? ll_tools_bulk_word_import_get_selectable_category_rows((int) $wordset_id)
                : [];
        }
    }

    wp_localize_script('ll-tools-bulk-word-import-admin', 'llBulkWordImportData', [
        'categoryRowsByWordset' => $category_rows_by_wordset,
        'uncategorizedLabel'    => __('Leave uncategorized', 'll-tools-text-domain'),
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_prompt_audio_import_enqueue_admin_assets');

function ll_tools_prompt_audio_import_parse_line($raw_line): array {
    $line = trim((string) $raw_line);
    if ($line === '') {
        return [
            'prompt_text'   => '',
            'title'         => '',
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
            'prompt_text'   => $line,
            'title'         => '',
            'extra_columns' => 0,
        ];
    }

    $columns = function_exists('str_getcsv')
        ? str_getcsv($line, $delimiter, '"', '\\')
        : explode($delimiter, $line);
    if (!is_array($columns) || empty($columns)) {
        $columns = [$line];
    }
    $columns = array_map(static function ($value): string {
        return trim((string) $value);
    }, $columns);

    return [
        'prompt_text'   => (string) ($columns[0] ?? ''),
        'title'         => (string) ($columns[1] ?? ''),
        'extra_columns' => max(0, count($columns) - 2),
    ];
}

function ll_tools_prompt_audio_import_normalize_title(string $prompt_text, string $title = ''): string {
    $title = trim($title);
    if ($title === '') {
        $title = trim($prompt_text);
    }
    $title = sanitize_text_field($title);
    if ($title === '') {
        return __('Prompt card', 'll-tools-text-domain');
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($title, 'UTF-8') > 120) {
        return rtrim(mb_substr($title, 0, 117, 'UTF-8')) . '...';
    }

    return strlen($title) > 120 ? rtrim(substr($title, 0, 117)) . '...' : $title;
}

function ll_tools_prompt_audio_import_ensure_prompt_recording_type(): void {
    if (!taxonomy_exists('recording_type')) {
        return;
    }
    if (get_term_by('slug', 'prompt', 'recording_type') instanceof WP_Term) {
        return;
    }

    wp_insert_term(
        __('Prompt', 'll-tools-text-domain'),
        'recording_type',
        [
            'slug' => 'prompt',
        ]
    );
}

function ll_tools_find_existing_prompt_card_ids_by_prompt_text_in_scope(string $prompt_text, int $wordset_term_id = 0, int $category_term_id = 0): array {
    if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
        return [];
    }

    $prompt_text = trim($prompt_text);
    if ($prompt_text === '') {
        return [];
    }

    $tax_query = [];
    if ($wordset_term_id > 0) {
        $tax_query[] = [
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [(int) $wordset_term_id],
        ];
    }
    if ($category_term_id > 0) {
        $tax_query[] = [
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [(int) $category_term_id],
        ];
    }

    $query_args = [
        'post_type'      => LL_TOOLS_PROMPT_CARD_POST_TYPE,
        'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'   => LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
                'value' => $prompt_text,
            ],
        ],
    ];
    if (!empty($tax_query)) {
        $query_args['tax_query'] = count($tax_query) > 1
            ? array_merge(['relation' => 'AND'], $tax_query)
            : $tax_query;
    }

    $ids = get_posts($query_args);
    return array_values(array_map('intval', (array) $ids));
}

function ll_tools_render_prompt_audio_import_page(): void {
    if (!ll_tools_current_user_can_prompt_audio_import()) {
        return;
    }

    $messages = [];
    $errors = [];
    $created = $skipped_existing = $skipped_empty = $rows_with_ignored_extra_columns = 0;
    $skipped_existing_prompts = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ll_prompt_audio_import_nonce'])) {
        check_admin_referer('ll_prompt_audio_import', 'll_prompt_audio_import_nonce');

        $raw_list = isset($_POST['ll_prompt_audio_list']) ? wp_unslash($_POST['ll_prompt_audio_list']) : '';
        $raw_lines = preg_split('/\r\n|\n|\r/', (string) $raw_list);
        $parsed_rows = is_array($raw_lines)
            ? array_map('ll_tools_prompt_audio_import_parse_line', $raw_lines)
            : [];

        $selected_category = isset($_POST['ll_existing_category']) ? (int) wp_unslash($_POST['ll_existing_category']) : 0;
        $selected_wordset = isset($_POST['ll_existing_wordset']) ? (int) wp_unslash($_POST['ll_existing_wordset']) : 0;
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
                $result = $existing
                    ? (int) (is_array($existing) ? $existing['term_id'] : $existing)
                    : wp_insert_term($new_category_name, 'word-category');
            }

            if (is_wp_error($result)) {
                $errors[] = sprintf(
                    /* translators: 1: category name, 2: error message */
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
                            /* translators: %s: category name */
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
            $errors[] = __('Please provide at least one prompt to import.', 'll-tools-text-domain');
        }
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')) {
            $errors[] = __('Prompt cards are not available in this installation.', 'll-tools-text-domain');
        }

        if (empty($errors)) {
            ll_tools_prompt_audio_import_ensure_prompt_recording_type();

            foreach ($parsed_rows as $row) {
                $prompt_text = sanitize_textarea_field((string) ($row['prompt_text'] ?? ''));
                $title = sanitize_text_field((string) ($row['title'] ?? ''));
                $extra_columns = isset($row['extra_columns']) ? (int) $row['extra_columns'] : 0;

                if ($extra_columns > 0) {
                    $rows_with_ignored_extra_columns++;
                }
                if (trim($prompt_text) === '') {
                    $skipped_empty++;
                    continue;
                }

                $existing_ids = ll_tools_find_existing_prompt_card_ids_by_prompt_text_in_scope($prompt_text, $selected_wordset, $category_id);
                if (!empty($existing_ids)) {
                    $skipped_existing++;
                    $skipped_existing_prompts[] = ll_tools_prompt_audio_import_normalize_title($prompt_text, $title);
                    continue;
                }

                $post_id = wp_insert_post([
                    'post_type'   => LL_TOOLS_PROMPT_CARD_POST_TYPE,
                    'post_status' => 'draft',
                    'post_title'  => ll_tools_prompt_audio_import_normalize_title($prompt_text, $title),
                    'post_author' => get_current_user_id(),
                ], true);
                if (is_wp_error($post_id)) {
                    $errors[] = sprintf(
                        /* translators: 1: prompt text, 2: error message */
                        __('Failed to create "%1$s": %2$s', 'll-tools-text-domain'),
                        esc_html(ll_tools_prompt_audio_import_normalize_title($prompt_text, $title)),
                        esc_html($post_id->get_error_message())
                    );
                    continue;
                }

                update_post_meta((int) $post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, $prompt_text);

                if ($category_id > 0) {
                    $set_terms = wp_set_post_terms((int) $post_id, [$category_id], 'word-category', false);
                    if (is_wp_error($set_terms)) {
                        $errors[] = sprintf(
                            /* translators: 1: prompt title, 2: error message */
                            __('The prompt "%1$s" was created but could not be assigned to the category: %2$s', 'll-tools-text-domain'),
                            esc_html(get_the_title((int) $post_id)),
                            esc_html($set_terms->get_error_message())
                        );
                    }
                }

                if ($selected_wordset > 0) {
                    $set_ws = wp_set_object_terms((int) $post_id, [$selected_wordset], 'wordset', false);
                    if (is_wp_error($set_ws)) {
                        $errors[] = sprintf(
                            /* translators: 1: prompt title, 2: error message */
                            __('The prompt "%1$s" was created but could not be assigned to the word set: %2$s', 'll-tools-text-domain'),
                            esc_html(get_the_title((int) $post_id)),
                            esc_html($set_ws->get_error_message())
                        );
                    }
                }

                $created++;
            }

            if ($created > 0) {
                $messages[] = [
                    'type' => 'success',
                    'text' => sprintf(
                        _n('%d prompt card was added to the audio queue as a draft.', '%d prompt cards were added to the audio queue as drafts.', $created, 'll-tools-text-domain'),
                        $created
                    ),
                ];
            }
            if ($skipped_existing > 0) {
                $messages[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        _n('%1$d prompt was skipped because it already exists: %2$s', '%1$d prompts were skipped because they already exist: %2$s', $skipped_existing, 'll-tools-text-domain'),
                        $skipped_existing,
                        esc_html(implode(', ', array_slice($skipped_existing_prompts, 0, 10)))
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
                        _n('Ignored extra columns on %d row. Only the prompt text and optional title were imported.', 'Ignored extra columns on %d rows. Only the prompt text and optional title were imported.', $rows_with_ignored_extra_columns, 'll-tools-text-domain'),
                        $rows_with_ignored_extra_columns
                    ),
                ];
            }
        }
    }

    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }

    $default_wordset_id = function_exists('ll_get_default_wordset_term_id') ? (int) ll_get_default_wordset_term_id() : 0;
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
    $category_rows = function_exists('ll_tools_bulk_word_import_get_selectable_category_rows')
        ? ll_tools_bulk_word_import_get_selectable_category_rows($selected_wordset_effective)
        : [];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Tools - Prompt Audio Queue', 'll-tools-text-domain'); ?></h1>

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
            <?php wp_nonce_field('ll_prompt_audio_import', 'll_prompt_audio_import_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ll-prompt-audio-list"><?php esc_html_e('Prompts to record', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <textarea id="ll-prompt-audio-list" name="ll_prompt_audio_list" rows="12" cols="60" class="large-text code" placeholder="<?php esc_attr_e('Enter one prompt per line, or prompt text + admin title separated by a tab or comma', 'll-tools-text-domain'); ?>"><?php echo isset($_POST['ll_prompt_audio_list']) ? esc_textarea(wp_unslash($_POST['ll_prompt_audio_list'])) : ''; ?></textarea>
                            <p class="description"><?php esc_html_e('Each row creates a draft prompt card with no prompt audio. It will appear in the recorder as a text-only prompt item until a recorder uploads audio for it.', 'll-tools-text-domain'); ?></p>
                            <p class="description"><?php echo wp_kses_post(sprintf(__('Prompt only: %s', 'll-tools-text-domain'), '<code>Listen and choose the matching answer.</code>')); ?></p>
                            <p class="description"><?php echo wp_kses_post(sprintf(__('Prompt + title with a tab: %s', 'll-tools-text-domain'), '<code>Listen and choose the matching answer.[TAB]Choose matching answer</code>')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Assign to word set', 'll-tools-text-domain'); ?></th>
                        <td>
                            <select id="ll-bulk-word-import-wordset" name="ll_existing_wordset" class="regular-text">
                                <option value="0"><?php esc_html_e('Leave unassigned', 'll-tools-text-domain'); ?></option>
                                <?php foreach ($wordsets as $set) : ?>
                                    <option value="<?php echo (int) $set->term_id; ?>" <?php selected($selected_wordset_effective, (int) $set->term_id); ?>><?php echo esc_html($set->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Choose the word set whose recorder should see these prompt cards.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Assign to existing category', 'll-tools-text-domain'); ?></th>
                        <td>
                            <select id="ll-bulk-word-import-category" name="ll_existing_category" class="regular-text">
                                <option value="0"><?php esc_html_e('Leave uncategorized', 'll-tools-text-domain'); ?></option>
                                <?php foreach ($category_rows as $category_row) : ?>
                                    <option value="<?php echo (int) ($category_row['id'] ?? 0); ?>" <?php selected($selected_category_effective, (int) ($category_row['id'] ?? 0)); ?>><?php echo esc_html((string) ($category_row['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Use the same category structure as the normal recorder queue.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ll-new-category"><?php esc_html_e('Create a new category', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <input type="text" id="ll-new-category" name="ll_new_category" class="regular-text" value="<?php echo isset($_POST['ll_new_category']) ? esc_attr(wp_unslash($_POST['ll_new_category'])) : ''; ?>" />
                            <p class="description"><?php esc_html_e('Optional. If provided, the new category will be created and used for all imported prompt cards.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Add prompts to audio queue', 'll-tools-text-domain')); ?>
        </form>
    </div>
    <?php
}
