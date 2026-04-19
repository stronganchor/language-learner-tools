<?php
/**
 * Admin page for bulk processing uploaded audio recordings
 */

if (!defined('WPINC')) { die; }

function ll_register_audio_processor_page() {
    add_submenu_page(
        'tools.php',
        __('Audio Processor - Language Learner Tools', 'll-tools-text-domain'),
        __('LL Audio Processor', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-audio-processor',
        'll_render_audio_processor_page'
    );
}
add_action('admin_menu', 'll_register_audio_processor_page');

function ll_audio_processor_get_recording_type_icons_map() {
    if (function_exists('ll_get_recording_type_icons_map')) {
        $map = ll_get_recording_type_icons_map();
        if (is_array($map) && !empty($map)) {
            return $map;
        }
    }

    return [
        'isolation'    => '🔍',
        'introduction' => '💬',
        'question'     => '❓',
        'sentence'     => '📝',
        'default'      => '🎙️',
    ];
}

function ll_audio_processor_get_recording_type_icon($slug) {
    if (function_exists('ll_get_recording_type_icon')) {
        return (string) ll_get_recording_type_icon($slug);
    }
    $map = ll_audio_processor_get_recording_type_icons_map();
    $key = sanitize_title((string) $slug);
    if ($key !== '' && !empty($map[$key])) {
        return (string) $map[$key];
    }
    return (string) ($map['default'] ?? '');
}

function ll_audio_processor_get_recording_type_name($slug, $term_name = '') {
    if (function_exists('ll_get_recording_type_name')) {
        return (string) ll_get_recording_type_name($slug, $term_name);
    }
    if ((string) $term_name !== '') {
        return (string) $term_name;
    }
    $normalized = trim((string) $slug);
    if ($normalized === '') {
        return '';
    }
    return ucwords(str_replace(['-', '_'], ' ', $normalized));
}

function ll_audio_processor_get_recording_type_label($slug, $term_name = '') {
    $name = ll_audio_processor_get_recording_type_name($slug, $term_name);
    $icon = ll_audio_processor_get_recording_type_icon($slug);
    return trim($icon . ' ' . $name);
}

function ll_audio_processor_get_page_url($args = []) {
    return add_query_arg($args, admin_url('tools.php?page=ll-audio-processor'));
}

function ll_audio_processor_get_recording_timestamp($recording_date) {
    if (is_numeric($recording_date)) {
        $timestamp = (int) $recording_date;
        return $timestamp > 0 ? $timestamp : 0;
    }

    $recording_date = trim((string) $recording_date);
    if ($recording_date === '') {
        return 0;
    }

    try {
        $timestamp = (new DateTimeImmutable($recording_date, wp_timezone()))->getTimestamp();
        return $timestamp > 0 ? $timestamp : 0;
    } catch (Exception $exception) {
        $fallback = strtotime($recording_date);
        return $fallback ? (int) $fallback : 0;
    }
}

function ll_enqueue_audio_processor_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-processor') return;

    $recording_type_terms = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);

    $recording_type_choices = [];
    if (!is_wp_error($recording_type_terms)) {
        usort($recording_type_terms, static function ($left, $right) {
            if (function_exists('ll_compare_recording_type_slugs')) {
                return ll_compare_recording_type_slugs($left->slug, $right->slug);
            }
            return strnatcasecmp((string) $left->slug, (string) $right->slug);
        });

        foreach ($recording_type_terms as $term) {
            $name = ll_audio_processor_get_recording_type_name($term->slug, $term->name);
            $recording_type_choices[] = [
                'slug' => $term->slug,
                'name' => $name,
                'icon' => ll_audio_processor_get_recording_type_icon($term->slug),
                'label' => ll_audio_processor_get_recording_type_label($term->slug, $term->name),
            ];
        }
    }

    ll_enqueue_asset_by_timestamp('/css/audio-processor.css', 'll-audio-processor-css');
    ll_enqueue_asset_by_timestamp('/js/audio-processor.js', 'll-audio-processor-js', [], true);

    // Get all unprocessed recordings (grouped with duplicates)
    $recording_sets = ll_get_unprocessed_recordings();
    $recordings = isset($recording_sets['all']) ? $recording_sets['all'] : [];

    wp_localize_script('ll-audio-processor-js', 'llAudioProcessor', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_audio_processor'),
        'recordings' => $recordings,
        'recordingTypes' => $recording_type_choices,
        'recordingTypeIcons' => ll_audio_processor_get_recording_type_icons_map(),
        'i18n' => [
            'saveNoFiles' => __('No files to save.', 'll-tools-text-domain'),
            /* translators: %d: number of processed audio files ready to save */
            'saveConfirmTemplate' => __('Save %d processed audio file(s)?', 'll-tools-text-domain'),
            'saveButtonDefault' => __('Save All Changes', 'll-tools-text-domain'),
            'saveButtonSaving' => __('Saving...', 'll-tools-text-domain'),
            'savePreparing' => __('Preparing uploads...', 'll-tools-text-domain'),
            /* translators: 1: recording title, 2: current item number, 3: total items */
            'saveStatusTemplate' => __('Saving: %1$s (%2$d/%3$d)', 'll-tools-text-domain'),
            /* translators: 1: saved file count, 2: total file count */
            'saveSuccessTemplate' => __('Success! Saved %1$d of %2$d files.', 'll-tools-text-domain'),
            /* translators: 1: saved file count, 2: failed file count */
            'saveErrorSummaryTemplate' => __('Completed with errors: %1$d saved, %2$d failed.', 'll-tools-text-domain'),
            'saveUnexpectedError' => __('Unexpected error while saving. Please try again.', 'll-tools-text-domain'),
            /* translators: 1: completed file count, 2: total file count */
            'saveCountTemplate' => __('%1$d / %2$d complete', 'll-tools-text-domain'),
            'beforeUnloadWarning' => __('Saving is still in progress. Leaving this page will interrupt uploads.', 'll-tools-text-domain'),
            'editWordButton' => __('Edit word', 'll-tools-text-domain'),
            'saveWordButton' => __('Save changes', 'll-tools-text-domain'),
            'cancelWordButton' => __('Cancel', 'll-tools-text-domain'),
            'wordInputLabel' => __('Word', 'll-tools-text-domain'),
            'wordInputPlaceholder' => __('Enter word', 'll-tools-text-domain'),
            'translationInputLabel' => __('Translation', 'll-tools-text-domain'),
            'translationInputPlaceholder' => __('Enter translation', 'll-tools-text-domain'),
            'wordRequired' => __('Word cannot be empty.', 'll-tools-text-domain'),
            'translationRequired' => __('Translation cannot be empty for this word.', 'll-tools-text-domain'),
            'wordSaving' => __('Saving...', 'll-tools-text-domain'),
            'wordSaveFailed' => __('Could not update word details.', 'll-tools-text-domain'),
            /* translators: %d: number of selected recordings */
            'deleteSelectedConfirmTemplate' => __('Delete %d recording(s)? This action cannot be undone.', 'll-tools-text-domain'),
            'deleteButtonDeleting' => __('Deleting...', 'll-tools-text-domain'),
            'deleteSelectedButtonDefault' => __('Delete Selected', 'll-tools-text-domain'),
            'deleteAllButtonDefault' => __('Delete All', 'll-tools-text-domain'),
            /* translators: %d: number of deleted recordings */
            'deleteSuccessTemplate' => __('Deleted %d recording(s).', 'll-tools-text-domain'),
            /* translators: 1: deleted recording count, 2: failed deletion count */
            'deletePartialTemplate' => __('Deleted %1$d recording(s). Failed to delete %2$d.', 'll-tools-text-domain'),
            /* translators: %d: number of deleted recordings */
            'deleteAllSuccessTemplate' => __('Successfully deleted %d recording(s)', 'll-tools-text-domain'),
            /* translators: %s: recording title */
            'deleteSingleConfirmTemplate' => __('Delete "%s"? This action cannot be undone.', 'll-tools-text-domain'),
            /* translators: %s: recording title */
            'removeFromBatchConfirmTemplate' => __('Remove "%s" from this batch? It will remain unprocessed.', 'll-tools-text-domain'),
            'deleteErrorPrefix' => __('Error:', 'll-tools-text-domain'),
            'deleteFailed' => __('Failed to delete recording', 'll-tools-text-domain'),
            /* translators: %s: error message */
            'deleteErrorTemplate' => __('Error deleting recording: %s', 'll-tools-text-domain'),
            'recordingFallbackLabel' => __('this recording', 'll-tools-text-domain'),
            'cancelReviewConfirm' => __('Are you sure you want to cancel? All processing will be lost.', 'll-tools-text-domain'),
            'trimLabel' => __('Trim', 'll-tools-text-domain'),
            'noiseReductionLabel' => __('Noise Reduction', 'll-tools-text-domain'),
            'loudnessLabel' => __('Loudness', 'll-tools-text-domain'),
            'recordingTypeLabel' => __('Recording Type', 'll-tools-text-domain'),
            'recordingTypeSelectPlaceholder' => __('Select type', 'll-tools-text-domain'),
            'recordingTypeNoneFound' => __('No recording types found', 'll-tools-text-domain'),
            'removeFromBatchButton' => __('Remove', 'll-tools-text-domain'),
            'removeFromBatchTitle' => __('Remove from this batch', 'll-tools-text-domain'),
            'deleteRecordingButton' => __('Delete', 'll-tools-text-domain'),
            'deleteRecordingTitle' => __('Delete this recording', 'll-tools-text-domain'),
            /* translators: 1: recording title, 2: current item number, 3: total items */
            'processingStatusTemplate' => __('Processing: %1$s (%2$d/%3$d)', 'll-tools-text-domain'),
            /* translators: 1: recording title, 2: error message */
            'processingErrorTemplate' => __('Error processing %1$s: %2$s', 'll-tools-text-domain'),
            'processingComplete' => __('Processing complete! Review the results below.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_audio_processor_assets');

function ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug) {
    $type_key = $recording_type_slug ? $recording_type_slug : '__none__';
    return $parent_word_id . '::' . $type_key;
}

function ll_audio_processor_get_word_editor_values($word_id) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [
            'word_text' => '',
            'translation_text' => '',
            'store_in_title' => true,
        ];
    }

    if (function_exists('ll_tools_word_grid_resolve_display_text')) {
        $values = ll_tools_word_grid_resolve_display_text($word_id);
        return [
            'word_text' => trim((string) ($values['word_text'] ?? '')),
            'translation_text' => trim((string) ($values['translation_text'] ?? '')),
            'store_in_title' => isset($values['store_in_title']) ? (bool) $values['store_in_title'] : true,
        ];
    }

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? (bool) ll_tools_should_store_word_in_title($word_id)
        : true;
    $word_title = trim((string) get_the_title($word_id));
    $word_translation = trim((string) get_post_meta($word_id, 'word_translation', true));
    if ($store_in_title && $word_translation === '') {
        $word_translation = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
    }

    if ($store_in_title) {
        $word_text = $word_title;
        $translation_text = $word_translation;
    } else {
        $word_text = $word_translation !== '' ? $word_translation : $word_title;
        $translation_text = $word_title;
    }

    return [
        'word_text' => trim((string) $word_text),
        'translation_text' => trim((string) $translation_text),
        'store_in_title' => $store_in_title,
    ];
}

function ll_audio_processor_get_published_recording_map($parent_word_ids) {
    $parent_word_ids = array_values(array_unique(array_filter(array_map('intval', $parent_word_ids))));
    if (empty($parent_word_ids)) {
        return [];
    }

    $published_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'post_parent__in' => $parent_word_ids,
        'fields' => 'ids',
    ]);

    if (empty($published_ids) || is_wp_error($published_ids)) {
        return [];
    }

    $published_by_key = [];
    foreach ($published_ids as $audio_post_id) {
        $parent_word_id = wp_get_post_parent_id($audio_post_id);
        if (!$parent_word_id) {
            continue;
        }

        $recording_type_slugs = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'slugs']);
        $recording_type_slug = (!is_wp_error($recording_type_slugs) && !empty($recording_type_slugs)) ? $recording_type_slugs[0] : '';
        $key = ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug);

        if (!isset($published_by_key[$key])) {
            $published_by_key[$key] = [];
        }
        $published_by_key[$key][] = (int) $audio_post_id;
    }

    return $published_by_key;
}

function ll_audio_processor_get_word_audio_child_count_map($parent_word_ids) {
    global $wpdb;

    $parent_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $parent_word_ids))));
    if (empty($parent_word_ids)) {
        return [];
    }

    $allowed_statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    $status_placeholders = implode(', ', array_fill(0, count($allowed_statuses), '%s'));
    $parent_placeholders = implode(', ', array_fill(0, count($parent_word_ids), '%d'));
    $sql = $wpdb->prepare(
        "
        SELECT post_parent, COUNT(ID) AS child_count
        FROM {$wpdb->posts}
        WHERE post_type = %s
          AND post_status IN ({$status_placeholders})
          AND post_parent IN ({$parent_placeholders})
        GROUP BY post_parent
        ",
        array_merge(['word_audio'], $allowed_statuses, $parent_word_ids)
    );

    $results = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($results) || empty($results)) {
        return [];
    }

    $count_map = [];
    foreach ($results as $row) {
        $parent_id = isset($row['post_parent']) ? (int) $row['post_parent'] : 0;
        if ($parent_id <= 0) {
            continue;
        }

        $count_map[$parent_id] = isset($row['child_count']) ? (int) $row['child_count'] : 0;
    }

    return $count_map;
}

function ll_get_unprocessed_recordings() {
    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $query = new WP_Query($args);
    $recordings = [];
    $parent_word_ids = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $audio_post_id = get_the_ID();
            $audio_file = get_post_meta($audio_post_id, 'audio_file_path', true);
            $parent_word_id = wp_get_post_parent_id($audio_post_id);

            if ($audio_file && $parent_word_id) {
                $word_values = ll_audio_processor_get_word_editor_values($parent_word_id);
                $word_title = (string) ($word_values['word_text'] ?? '');
                $categories = wp_get_post_terms($parent_word_id, 'word-category', ['fields' => 'names']);
                $upload_date = (string) get_post_meta($audio_post_id, 'recording_date', true);

                // Get wordset names
                $wordsets = wp_get_post_terms($parent_word_id, 'wordset', ['fields' => 'names']);
                $wordset_names = (!is_wp_error($wordsets) && !empty($wordsets)) ? $wordsets : [];

                // Get recording type names
                $recording_type_terms = wp_get_post_terms($audio_post_id, 'recording_type');
                $recording_type_names = [];
                $recording_type_slugs = [];
                $recording_type_items = [];
                if (!is_wp_error($recording_type_terms) && !empty($recording_type_terms)) {
                    foreach ($recording_type_terms as $term) {
                        $slug = (string) $term->slug;
                        $name = ll_audio_processor_get_recording_type_name($slug, $term->name);
                        $recording_type_slugs[] = $slug;
                        $recording_type_names[] = $name;
                        $recording_type_items[] = [
                            'slug' => $slug,
                            'name' => $name,
                            'icon' => ll_audio_processor_get_recording_type_icon($slug),
                            'label' => ll_audio_processor_get_recording_type_label($slug, $term->name),
                        ];
                    }
                }
                $recording_type_slug = !empty($recording_type_slugs) ? $recording_type_slugs[0] : '';

                // Get image thumbnail
                $image_url = get_the_post_thumbnail_url($parent_word_id, 'thumbnail');

                $recordings[] = [
                    'id' => $audio_post_id,
                    'title' => $word_title,
                    'wordText' => $word_title,
                    'translationText' => (string) ($word_values['translation_text'] ?? ''),
                    'storeInTitle' => !empty($word_values['store_in_title']),
                    'audioUrl' => site_url($audio_file),
                    'uploadDate' => $upload_date,
                    'uploadTimestamp' => ll_audio_processor_get_recording_timestamp($upload_date),
                    'categories' => is_array($categories) && !is_wp_error($categories) ? $categories : [],
                    'wordsets' => $wordset_names,
                    'recordingTypes' => $recording_type_names,
                    'recordingTypeItems' => $recording_type_items,
                    'recordingType' => $recording_type_slug,
                    'imageUrl' => $image_url ?: '',
                    'parentWordId' => (int) $parent_word_id,
                    'recordingKey' => ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug),
                ];

                $parent_word_ids[] = (int) $parent_word_id;
            }
        }
        wp_reset_postdata();
    }

    $audio_child_count_map = ll_audio_processor_get_word_audio_child_count_map($parent_word_ids);
    foreach ($recordings as &$recording) {
        $parent_word_id = isset($recording['parentWordId']) ? (int) $recording['parentWordId'] : 0;
        $recording['splitWordEnabled'] = $parent_word_id > 0
            && ((int) ($audio_child_count_map[$parent_word_id] ?? 0) > 1);
    }
    unset($recording);

    $published_by_key = ll_audio_processor_get_published_recording_map($parent_word_ids);
    $queue = [];
    $duplicates = [];
    $seen_keys = [];

    foreach ($recordings as $recording) {
        $key = $recording['recordingKey'];
        $published_ids = isset($published_by_key[$key]) ? $published_by_key[$key] : [];
        $has_published_duplicate = false;

        if (!empty($published_ids)) {
            if (count($published_ids) > 1 || (count($published_ids) === 1 && (int) $published_ids[0] !== (int) $recording['id'])) {
                $has_published_duplicate = true;
            }
        }

        if ($has_published_duplicate) {
            $recording['duplicateReason'] = 'published';
            $duplicates[] = $recording;
            continue;
        }

        if (isset($seen_keys[$key])) {
            $recording['duplicateReason'] = 'queued';
            $duplicates[] = $recording;
            continue;
        }

        $recording['duplicateReason'] = '';
        $queue[] = $recording;
        $seen_keys[$key] = true;
    }

    return [
        'queue' => $queue,
        'duplicates' => $duplicates,
        'all' => $recordings,
    ];
}

function ll_render_audio_processor_recording_item($recording, $duplicate_reason = '') {
    $recording_type_items = [];
    if (!empty($recording['recordingTypeItems']) && is_array($recording['recordingTypeItems'])) {
        foreach ($recording['recordingTypeItems'] as $item) {
            $slug = sanitize_title((string) ($item['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $name = ll_audio_processor_get_recording_type_name($slug, (string) ($item['name'] ?? ''));
            $recording_type_items[] = [
                'slug' => $slug,
                'name' => $name,
                'icon' => ll_audio_processor_get_recording_type_icon($slug),
            ];
        }
    } elseif (!empty($recording['recordingType'])) {
        $slug = sanitize_title((string) $recording['recordingType']);
        if ($slug !== '') {
            $fallback_name = !empty($recording['recordingTypes'][0]) ? (string) $recording['recordingTypes'][0] : '';
            $recording_type_items[] = [
                'slug' => $slug,
                'name' => ll_audio_processor_get_recording_type_name($slug, $fallback_name),
                'icon' => ll_audio_processor_get_recording_type_icon($slug),
            ];
        }
    }

    $duplicate_label = '';
    if ($duplicate_reason === 'published') {
        $duplicate_label = __('Published audio exists', 'll-tools-text-domain');
    } elseif ($duplicate_reason === 'queued') {
        $duplicate_label = __('Duplicate in queue', 'll-tools-text-domain');
    }

    $word_text = trim((string) ($recording['wordText'] ?? $recording['title'] ?? ''));
    $translation_text = trim((string) ($recording['translationText'] ?? ''));
    $store_in_title = !empty($recording['storeInTitle']);
    $parent_word_id = (int) ($recording['parentWordId'] ?? 0);
    $processor_return_url = ll_audio_processor_get_page_url();
    $split_word_url = '';
    if (
        $parent_word_id > 0 &&
        current_user_can('view_ll_tools') &&
        current_user_can('edit_post', $parent_word_id) &&
        !empty($recording['splitWordEnabled']) &&
        function_exists('ll_tools_get_split_word_page_url')
    ) {
        $split_word_url = ll_tools_get_split_word_page_url($parent_word_id, [], $processor_return_url);
    }
    $display_word_text = function_exists('ll_tools_esc_html_display')
        ? ll_tools_esc_html_display($word_text)
        : esc_html($word_text);
    $display_translation_text = function_exists('ll_tools_esc_html_display')
        ? ll_tools_esc_html_display($translation_text)
        : esc_html($translation_text);
    $upload_date = trim((string) ($recording['uploadDate'] ?? ''));
    $upload_timestamp = isset($recording['uploadTimestamp'])
        ? (int) $recording['uploadTimestamp']
        : ll_audio_processor_get_recording_timestamp($upload_date);
    $fallback_upload_label = $upload_date;
    $upload_datetime_attr = '';

    if ($upload_timestamp > 0) {
        $fallback_upload_label = wp_date('Y-m-d H:i', $upload_timestamp, wp_timezone());
        $upload_datetime_attr = gmdate('c', $upload_timestamp);
    }
    ?>
    <div
        class="ll-recording-item"
        data-id="<?php echo esc_attr($recording['id']); ?>"
        data-parent-word-id="<?php echo esc_attr($parent_word_id); ?>"
    >
        <div class="ll-recording-label">
            <input type="checkbox" class="ll-recording-checkbox" value="<?php echo esc_attr($recording['id']); ?>">
            <div class="ll-recording-info">
                <div
                    class="ll-word-title-block"
                    data-parent-word-id="<?php echo esc_attr($parent_word_id); ?>"
                    data-word-text="<?php echo esc_attr($word_text); ?>"
                    data-translation-text="<?php echo esc_attr($translation_text); ?>"
                    data-store-in-title="<?php echo $store_in_title ? '1' : '0'; ?>"
                >
                    <div class="ll-word-title-display-row">
                        <span class="ll-word-title-display-text">
                            <strong class="ll-recording-title-text" dir="auto"><?php echo $display_word_text; ?></strong>
                            <span class="ll-recording-translation-text" dir="auto" <?php echo $translation_text === '' ? 'hidden' : ''; ?>>
                                <?php echo $display_translation_text; ?>
                            </span>
                        </span>
                        <span class="ll-word-title-actions">
                            <button type="button" class="ll-edit-word-title-btn button-link">
                                <?php echo esc_html__('Edit word', 'll-tools-text-domain'); ?>
                            </button>
                            <?php if ($split_word_url !== '') : ?>
                                <a
                                    href="<?php echo esc_url($split_word_url); ?>"
                                    class="button button-secondary button-small ll-split-word-link"
                                    data-split-word-url="<?php echo esc_attr($split_word_url); ?>"
                                    data-return-base-url="<?php echo esc_attr($processor_return_url); ?>"
                                >
                                    <?php echo esc_html__('Split word', 'll-tools-text-domain'); ?>
                                </a>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="ll-word-title-editor" hidden>
                        <div class="ll-word-editor-field">
                            <label for="<?php echo esc_attr('ll-word-title-input-' . (int) $recording['id']); ?>">
                                <?php echo esc_html__('Word', 'll-tools-text-domain'); ?>
                            </label>
                            <input
                                id="<?php echo esc_attr('ll-word-title-input-' . (int) $recording['id']); ?>"
                                type="text"
                                class="ll-word-title-input"
                                value="<?php echo esc_attr($word_text); ?>"
                                placeholder="<?php echo esc_attr__('Enter word', 'll-tools-text-domain'); ?>"
                                maxlength="200"
                                dir="auto"
                            >
                        </div>
                        <div class="ll-word-editor-field">
                            <label for="<?php echo esc_attr('ll-word-translation-input-' . (int) $recording['id']); ?>">
                                <?php echo esc_html__('Translation', 'll-tools-text-domain'); ?>
                            </label>
                            <input
                                id="<?php echo esc_attr('ll-word-translation-input-' . (int) $recording['id']); ?>"
                                type="text"
                                class="ll-word-translation-input"
                                value="<?php echo esc_attr($translation_text); ?>"
                                placeholder="<?php echo esc_attr__('Enter translation', 'll-tools-text-domain'); ?>"
                                dir="auto"
                            >
                        </div>
                        <div class="ll-word-editor-actions">
                            <button type="button" class="button button-small ll-save-word-title-btn">
                                <?php echo esc_html__('Save changes', 'll-tools-text-domain'); ?>
                            </button>
                            <button type="button" class="button button-small ll-cancel-word-title-btn">
                                <?php echo esc_html__('Cancel', 'll-tools-text-domain'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="ll-recording-meta">
                    <?php if (!empty($recording['categories'])): ?>
                        <span class="ll-recording-categories">
                            <?php echo esc_html(implode(', ', $recording['categories'])); ?>
                        </span>
                    <?php endif; ?>
                    <span class="ll-recording-type">
                        <span class="ll-recording-meta-label"><?php echo esc_html__('Type', 'll-tools-text-domain'); ?>:</span>
                        <span class="ll-recording-meta-value">
                            <?php if (!empty($recording_type_items)): ?>
                                <?php foreach ($recording_type_items as $index => $type): ?>
                                    <span class="ll-recording-type-entry" data-recording-type="<?php echo esc_attr($type['slug']); ?>">
                                        <span class="ll-recording-type-icon" aria-hidden="true"><?php echo esc_html($type['icon']); ?></span>
                                        <span class="ll-recording-type-text"><?php echo esc_html($type['name']); ?></span>
                                    </span>
                                    <?php if ($index < (count($recording_type_items) - 1)): ?>
                                        <span class="ll-recording-type-separator" aria-hidden="true">, </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="ll-recording-type-entry">
                                    <span class="ll-recording-type-text"><?php echo esc_html__('Unassigned', 'll-tools-text-domain'); ?></span>
                                </span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($duplicate_label): ?>
                        <span class="ll-recording-duplicate"><?php echo esc_html($duplicate_label); ?></span>
                    <?php endif; ?>
                    <time
                        class="ll-recording-date"
                        <?php echo $upload_datetime_attr !== '' ? 'datetime="' . esc_attr($upload_datetime_attr) . '"' : ''; ?>
                        <?php echo $upload_timestamp > 0 ? 'data-upload-timestamp="' . esc_attr((string) $upload_timestamp) . '"' : ''; ?>
                    >
                        <?php echo esc_html($fallback_upload_label); ?>
                    </time>
                </div>
            </div>
        </div>
        <audio controls preload="none" src="<?php echo esc_url($recording['audioUrl']); ?>"></audio>
    </div>
    <?php
}

function ll_render_audio_processor_page() {
    if (function_exists('ll_tools_acknowledge_recording_notification_batch_from_processor_page')) {
        ll_tools_acknowledge_recording_notification_batch_from_processor_page();
    }

    $recording_sets = ll_get_unprocessed_recordings();
    $queue_recordings = isset($recording_sets['queue']) ? $recording_sets['queue'] : [];
    $duplicate_recordings = isset($recording_sets['duplicates']) ? $recording_sets['duplicates'] : [];
    $has_recordings = !empty($queue_recordings) || !empty($duplicate_recordings);
    $active_tab = !empty($queue_recordings) ? 'queue' : 'duplicates';
    $requested_tab = isset($_GET['ll_ap_tab']) ? sanitize_key((string) $_GET['ll_ap_tab']) : '';
    if (in_array($requested_tab, ['queue', 'duplicates'], true)) {
        $active_tab = $requested_tab;
    }
    ?>
    <div class="wrap ll-audio-processor-wrap">
        <h1><?php esc_html_e('Audio Processor', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Process uploaded audio recordings with configurable noise reduction, loudness normalization, and silence trimming.', 'll-tools-text-domain'); ?></p>

        <?php if (!$has_recordings): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e('No unprocessed audio recordings found.', 'll-tools-text-domain'); ?></p>
            </div>
        <?php else: ?>
            <div class="ll-processing-options">
                <h3><?php esc_html_e('Processing Options', 'll-tools-text-domain'); ?></h3>
                <label>
                    <input type="checkbox" id="ll-enable-trim" checked>
                    <span><?php esc_html_e('Trim silence from start and end', 'll-tools-text-domain'); ?></span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-noise" checked>
                    <span><?php esc_html_e('Apply noise reduction', 'll-tools-text-domain'); ?></span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-loudness" checked>
                    <span><?php esc_html_e('Normalize loudness', 'll-tools-text-domain'); ?></span>
                </label>
            </div>

            <div class="ll-processor-controls">
                <button id="ll-select-all" class="button"><?php esc_html_e('Select All', 'll-tools-text-domain'); ?></button>
                <button id="ll-deselect-all" class="button"><?php esc_html_e('Deselect All', 'll-tools-text-domain'); ?></button>
                <button id="ll-process-selected" class="button button-primary" disabled>
                    <?php esc_html_e('Process Selected', 'll-tools-text-domain'); ?> (<span id="ll-selected-count">0</span>)
                </button>
                <button id="ll-delete-selected" class="ll-btn-danger" type="button" disabled>
                    <span class="ll-btn-label"><?php esc_html_e('Delete Selected', 'll-tools-text-domain'); ?></span> (<span id="ll-delete-selected-count">0</span>)
                </button>
            </div>

            <div id="ll-processor-status" class="ll-processor-status" style="display:none;">
                <div class="ll-progress-bar">
                    <div class="ll-progress-fill" style="width: 0%"></div>
                </div>
                <p class="ll-status-text"><?php esc_html_e('Processing...', 'll-tools-text-domain'); ?></p>
            </div>

            <div id="ll-save-progress-overlay" class="ll-save-progress-overlay" hidden aria-hidden="true">
                <div class="ll-save-progress-panel" role="status" aria-live="assertive" aria-atomic="true">
                    <h2 class="ll-save-progress-title"><?php echo esc_html__('Saving Processed Audio', 'll-tools-text-domain'); ?></h2>
                    <p id="ll-save-progress-current" class="ll-save-progress-current"><?php echo esc_html__('Preparing uploads...', 'll-tools-text-domain'); ?></p>
                    <div class="ll-progress-bar ll-save-progress-bar" aria-hidden="true">
                        <div id="ll-save-progress-fill" class="ll-progress-fill" style="width: 0%"></div>
                    </div>
                    <p id="ll-save-progress-count" class="ll-save-progress-count">0 / 0</p>
                    <p class="ll-save-progress-note">
                        <?php echo esc_html__('Keep this page open until saving is complete. Navigating away will interrupt remaining uploads.', 'll-tools-text-domain'); ?>
                    </p>
                </div>
            </div>

            <div class="ll-audio-processor-tabs" role="tablist" data-initial-tab="<?php echo esc_attr($active_tab); ?>">
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>"
                    data-tab="queue"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'queue' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-queue"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Queue', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="queue"><?php echo esc_html(count($queue_recordings)); ?></span>
                </button>
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'duplicates' ? 'is-active' : ''; ?>"
                    data-tab="duplicates"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'duplicates' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-duplicates"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Duplicates', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="duplicates"><?php echo esc_html(count($duplicate_recordings)); ?></span>
                </button>
            </div>

            <div
                id="ll-recordings-queue"
                class="ll-recordings-list <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>"
                data-tab="queue"
                role="tabpanel"
                aria-hidden="<?php echo $active_tab === 'queue' ? 'false' : 'true'; ?>"
            >
                <?php if (empty($queue_recordings)): ?>
                    <div class="notice notice-info ll-recordings-empty">
                        <p><?php echo esc_html__('No unique recordings in the queue. Check the duplicates tab for additional recordings.', 'll-tools-text-domain'); ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($queue_recordings as $recording): ?>
                        <?php ll_render_audio_processor_recording_item($recording); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div
                id="ll-recordings-duplicates"
                class="ll-recordings-list <?php echo $active_tab === 'duplicates' ? 'is-active' : ''; ?>"
                data-tab="duplicates"
                role="tabpanel"
                aria-hidden="<?php echo $active_tab === 'duplicates' ? 'false' : 'true'; ?>"
            >
                <?php if (!empty($duplicate_recordings)): ?>
                    <div class="ll-duplicate-note">
                        <?php echo esc_html__('Duplicates are hidden so you can process one recording per word and recording type first.', 'll-tools-text-domain'); ?>
                    </div>
                    <?php foreach ($duplicate_recordings as $recording): ?>
                        <?php ll_render_audio_processor_recording_item($recording, isset($recording['duplicateReason']) ? $recording['duplicateReason'] : ''); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notice notice-info ll-recordings-empty">
                        <p><?php echo esc_html__('No duplicates found.', 'll-tools-text-domain'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Review Interface (shown after processing) -->
            <div id="ll-review-interface" class="ll-review-interface">
                <h2><?php esc_html_e('Review Processed Audio', 'll-tools-text-domain'); ?></h2>
                <div id="ll-review-files-container"></div>
                <div class="ll-review-actions">
                    <button id="ll-save-all" class="ll-btn-save-all"><?php esc_html_e('Save All Changes', 'll-tools-text-domain'); ?></button>
                    <button id="ll-delete-all-review" class="button button-link-delete"><?php esc_html_e('Delete All', 'll-tools-text-domain'); ?></button>
                    <button id="ll-cancel-review" class="ll-btn-cancel"><?php esc_html_e('Cancel', 'll-tools-text-domain'); ?></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * AJAX handler to save processed audio file
 */
add_action('wp_ajax_ll_save_processed_audio', 'll_save_processed_audio_handler');

/**
 * Resolve a stored recording path to a safe deletable file path inside uploads.
 *
 * Stored `audio_file_path` values are expected to be ABSPATH-relative. This
 * helper rejects anything that resolves outside the current uploads base dir.
 */
function ll_audio_processor_resolve_safe_delete_path($stored_path) {
    $stored_path = trim((string) $stored_path);
    if ($stored_path === '') {
        return '';
    }

    $uploads = wp_get_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    $candidate = wp_normalize_path(ABSPATH . ltrim($stored_path, "/\\"));
    if (!file_exists($candidate)) {
        return '';
    }

    $real = realpath($candidate);
    if (!is_string($real) || $real === '') {
        return '';
    }

    $real_norm = wp_normalize_path($real);
    $uploads_base = wp_normalize_path(untrailingslashit((string) $uploads['basedir']));
    if ($uploads_base === '') {
        return '';
    }

    $real_cmp = strtolower($real_norm);
    $base_cmp = strtolower($uploads_base);
    if ($real_cmp !== $base_cmp && strpos($real_cmp, $base_cmp . '/') !== 0) {
        return '';
    }

    return $real_norm;
}

function ll_save_processed_audio_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }
    if (!current_user_can('upload_files')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $recording_type = isset($_POST['recording_type']) ? sanitize_text_field($_POST['recording_type']) : '';

    if (!$audio_post_id || !isset($_FILES['audio'])) {
        wp_send_json_error(__('Missing required data', 'll-tools-text-domain'));
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid audio post', 'll-tools-text-domain'));
    }
    if (!current_user_can('edit_post', $audio_post_id)) {
        wp_send_json_error(__('Insufficient permissions to edit this recording.', 'll-tools-text-domain'));
    }

    $parent_word_id = wp_get_post_parent_id($audio_post_id);
    if (!$parent_word_id) {
        wp_send_json_error(__('No parent word post found', 'll-tools-text-domain'));
    }

    $parent_word = get_post($parent_word_id);
    if (!$parent_word || $parent_word->post_type !== 'words') {
        wp_send_json_error(__('Invalid parent word post', 'll-tools-text-domain'));
    }
    if (!current_user_can('edit_post', $parent_word_id)) {
        wp_send_json_error(__('Insufficient permissions to edit the parent word.', 'll-tools-text-domain'));
    }

    $file = (array) $_FILES['audio'];
    if (!function_exists('ll_tools_validate_recording_upload_file')) {
        wp_send_json_error(__('Audio upload validation is unavailable', 'll-tools-text-domain'));
    }
    $upload_validation = ll_tools_validate_recording_upload_file($file);
    if (empty($upload_validation['valid'])) {
        $status = max(400, (int) ($upload_validation['status'] ?? 400));
        $message = (string) ($upload_validation['error'] ?? '');
        if ($message === '') {
            $message = __('Invalid audio upload.', 'll-tools-text-domain');
        }
        wp_send_json_error($message, $status);
    }

    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error']) || empty($upload_dir['path'])) {
        wp_send_json_error(__('Upload directory is unavailable', 'll-tools-text-domain'));
    }
    if (!wp_mkdir_p((string) $upload_dir['path'])) {
        wp_send_json_error(__('Upload directory is unavailable', 'll-tools-text-domain'));
    }

    $title = sanitize_file_name($parent_word->post_title);

    // Get recording type (selected override falls back to current term) to make filename unique
    $existing_recording_types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'slugs']);
    $type_for_filename = $recording_type ?: (!is_wp_error($existing_recording_types) && !empty($existing_recording_types) ? $existing_recording_types[0] : '');
    $type_suffix = $type_for_filename ? '_' . $type_for_filename : '';

    $validated_ext = sanitize_key((string) ($upload_validation['ext'] ?? ''));
    if ($validated_ext === '') {
        $validated_ext = 'mp3';
    }
    // Include audio_post_id to ensure absolute uniqueness.
    $filename = $title . $type_suffix . '_' . $audio_post_id . '_' . time() . '.' . $validated_ext;
    $file['name'] = $filename;
    if (!empty($upload_validation['mime'])) {
        $file['type'] = (string) $upload_validation['mime'];
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $upload_result = wp_handle_upload($file, [
        'test_form' => false,
        // Validation already enforced by ll_tools_validate_recording_upload_file().
        'test_type' => false,
        'mimes' => function_exists('ll_tools_get_allowed_recording_upload_mimes')
            ? ll_tools_get_allowed_recording_upload_mimes()
            : null,
    ]);
    if (!is_array($upload_result) || !empty($upload_result['error']) || empty($upload_result['file'])) {
        $upload_error = is_array($upload_result) ? (string) ($upload_result['error'] ?? '') : '';
        if ($upload_error !== '') {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: upload subsystem error message */
                    __('Failed to save file: %s', 'll-tools-text-domain'),
                    $upload_error
                ),
                400
            );
        }
        wp_send_json_error(__('Failed to save file', 'll-tools-text-domain'));
    }
    $filepath = (string) $upload_result['file'];

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Update this specific word_audio post
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    delete_post_meta($audio_post_id, '_ll_needs_audio_processing');
    update_post_meta($audio_post_id, '_ll_processed_audio_date', current_time('mysql'));
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    wp_update_post([
        'ID'          => $audio_post_id,
        'post_status' => 'publish',
    ]);

    // Also publish the parent word post if it's still a draft
    if ($parent_word->post_status === 'draft') {
        wp_update_post([
            'ID'          => $parent_word_id,
            'post_status' => 'publish',
        ]);
    }

    wp_send_json_success([
        'message' => __('Audio processed and published successfully', 'll-tools-text-domain'),
        'file_path' => $relative_path,
        'audio_post_id' => $audio_post_id,
        'recording_type' => $recording_type,
    ]);
}

add_action('wp_ajax_ll_audio_processor_update_word_text', 'll_audio_processor_update_word_text_handler');
add_action('wp_ajax_ll_audio_processor_update_word_title', 'll_audio_processor_update_word_text_handler');

function ll_audio_processor_update_word_text_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    $word_id = isset($_POST['word_id']) ? intval($_POST['word_id']) : 0;
    $word_text_raw = isset($_POST['word_text'])
        ? wp_unslash($_POST['word_text'])
        : (isset($_POST['title']) ? wp_unslash($_POST['title']) : '');
    $translation_text_raw = isset($_POST['translation_text']) ? wp_unslash($_POST['translation_text']) : '';

    $word_text = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_text_raw)
        : trim(sanitize_text_field((string) $word_text_raw));
    $translation_text = sanitize_text_field((string) $translation_text_raw);
    if (function_exists('ll_tools_strip_display_word_joiners')) {
        $translation_text = ll_tools_strip_display_word_joiners($translation_text);
    }
    $translation_text = trim((string) $translation_text);

    if (!$word_id || $word_text === '') {
        wp_send_json_error(__('Missing required data', 'll-tools-text-domain'));
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error(__('Invalid word post', 'll-tools-text-domain'));
    }

    if (!current_user_can('edit_post', $word_id)) {
        wp_send_json_error(__('Insufficient permissions to edit this word.', 'll-tools-text-domain'));
    }

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? (bool) ll_tools_should_store_word_in_title($word_id)
        : true;
    if (!$store_in_title && $translation_text === '') {
        wp_send_json_error(__('Translation cannot be empty for this word.', 'll-tools-text-domain'));
    }

    $new_title = $store_in_title ? $word_text : $translation_text;
    $updated = wp_update_post([
        'ID' => $word_id,
        'post_title' => $new_title,
    ], true);

    if (is_wp_error($updated)) {
        wp_send_json_error(__('Could not update word details.', 'll-tools-text-domain'));
    }

    if ($translation_text !== '') {
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_english_meaning');
    }

    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
        }
    } else {
        update_post_meta($word_id, 'word_translation', $word_text);
    }

    $word_values = ll_audio_processor_get_word_editor_values($word_id);

    wp_send_json_success([
        'word_id' => $word_id,
        'title' => (string) ($word_values['word_text'] ?? ''),
        'wordText' => (string) ($word_values['word_text'] ?? ''),
        'translationText' => (string) ($word_values['translation_text'] ?? ''),
        'storeInTitle' => !empty($word_values['store_in_title']),
    ]);
}

/**
 * AJAX handler to delete an audio recording
 */
add_action('wp_ajax_ll_delete_audio_recording', 'll_delete_audio_recording_handler');

function ll_delete_audio_recording_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$audio_post_id) {
        wp_send_json_error(__('Invalid post ID', 'll-tools-text-domain'));
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid audio post', 'll-tools-text-domain'));
    }
    if (!current_user_can('delete_post', $audio_post_id)) {
        wp_send_json_error(__('Insufficient permissions to delete this recording.', 'll-tools-text-domain'));
    }

    // Resolve parent word before deletion
    $parent_word_id = (int) $audio_post->post_parent;

    // Delete the audio file from filesystem
    $audio_file_path = get_post_meta($audio_post_id, 'audio_file_path', true);
    if ($audio_file_path) {
        $full_path = ll_audio_processor_resolve_safe_delete_path($audio_file_path);
        if ($full_path !== '' && file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    // Delete the post
    $deleted = wp_delete_post($audio_post_id, true);

    if ($deleted) {
        // If parent exists, and it now has no published audio posts, set it to draft and clean legacy meta
        if ($parent_word_id) {
            $remaining = get_posts([
                'post_type'      => 'word_audio',
                'post_parent'    => $parent_word_id,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (empty($remaining)) {
                $parent = get_post($parent_word_id);
                if ($parent && $parent->post_status === 'publish') {
                    wp_update_post([
                        'ID'          => $parent_word_id,
                        'post_status' => 'draft',
                    ]);
                }
                // Remove legacy meta to prevent stale fallbacks elsewhere
                delete_post_meta($parent_word_id, 'word_audio_file');
            }
        }

        wp_send_json_success(['message' => __('Audio recording deleted', 'll-tools-text-domain')]);
    } else {
        wp_send_json_error(__('Failed to delete audio recording', 'll-tools-text-domain'));
    }
}

/**
 * Get count of audio recordings waiting for processing.
 */
function ll_tools_get_audio_processing_queue_count(): int {
    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'fields' => 'ids'
    ];

    $query = new WP_Query($args);
    $unprocessed_count = (int) $query->found_posts;
    wp_reset_postdata();

    return max(0, $unprocessed_count);
}

/**
 * Build LL Tools maintenance task list for the global admin notice.
 *
 * @return array<int,array{key:string,url:string,screen_id:string,title:string,message:string,screen_query_args?:array<string,string>}>
 */
function ll_tools_get_admin_maintenance_tasks(): array {
    $tasks = [];

    $audio_count = ll_tools_get_audio_processing_queue_count();
    if ($audio_count > 0) {
        $tasks[] = [
            'key' => 'audio_processing',
            'url' => admin_url('tools.php?page=ll-audio-processor'),
            'screen_id' => 'tools_page_ll-audio-processor',
            'title' => __('Audio Processor', 'll-tools-text-domain'),
            'message' => sprintf(
                /* translators: %d: number of audio recordings */
                _n(
                    '%d audio recording needs processing',
                    '%d audio recordings need processing',
                    $audio_count,
                    'll-tools-text-domain'
                ),
                $audio_count
            ),
        ];
    }

    if (function_exists('ll_tools_get_aspect_normalization_needs_lookup') && function_exists('ll_tools_get_aspect_normalizer_admin_url')) {
        $aspect_needs_lookup = ll_tools_get_aspect_normalization_needs_lookup();
        $aspect_category_count = count($aspect_needs_lookup);
        if ($aspect_category_count > 0) {
            $tasks[] = [
                'key' => 'image_aspect_normalization',
                'url' => ll_tools_get_aspect_normalizer_admin_url(),
                'screen_id' => 'tools_page_' . ll_tools_get_aspect_normalizer_page_slug(),
                'title' => __('Image Aspect Normalizer', 'll-tools-text-domain'),
                'message' => sprintf(
                    /* translators: %d: number of categories */
                    _n(
                        'Images need aspect ratio normalization in %d category',
                        'Images need aspect ratio normalization in %d categories',
                        $aspect_category_count,
                        'll-tools-text-domain'
                    ),
                    $aspect_category_count
                ),
            ];
        }
    }

    if (function_exists('ll_tools_webp_optimizer_get_queue') && function_exists('ll_tools_get_webp_optimizer_admin_url')) {
        $webp_queue = [];
        try {
            $webp_queue = ll_tools_webp_optimizer_get_queue([
                'ids_only' => true,
                'include_non_flagged' => false,
            ]);
        } catch (Throwable $e) {
            $webp_queue = [];
        }
        $webp_queued_count = (int) (($webp_queue['summary']['queued_count'] ?? 0));
        if ($webp_queued_count > 0) {
            $tasks[] = [
                'key' => 'image_webp_optimization',
                'url' => ll_tools_get_webp_optimizer_admin_url(),
                'screen_id' => 'tools_page_' . ll_tools_get_webp_optimizer_page_slug(),
                'title' => __('WebP Image Optimizer', 'll-tools-text-domain'),
                'message' => sprintf(
                    /* translators: %d: number of word images */
                    _n(
                        '%d image needs WebP optimization',
                        '%d images need WebP optimization',
                        $webp_queued_count,
                        'll-tools-text-domain'
                    ),
                    $webp_queued_count
                ),
            ];
        }
    }

    if (
        function_exists('ll_tools_get_words_without_quizzable_categories_count')
        && function_exists('ll_tools_get_words_without_quizzable_categories_admin_url')
        && function_exists('ll_tools_get_words_no_quizzable_category_filter_value')
    ) {
        $missing_quizzable_category_count = ll_tools_get_words_without_quizzable_categories_count();
        if ($missing_quizzable_category_count > 0) {
            $tasks[] = [
                'key' => 'words_without_quizzable_category',
                'url' => ll_tools_get_words_without_quizzable_categories_admin_url(),
                'screen_id' => 'edit-words',
                'screen_query_args' => [
                    'll_quiz_category_status' => ll_tools_get_words_no_quizzable_category_filter_value(),
                ],
                'title' => __('Words', 'll-tools-text-domain'),
                'message' => sprintf(
                    /* translators: %d: number of words */
                    _n(
                        '%d word is not in any quizzable category',
                        '%d words are not in any quizzable category',
                        $missing_quizzable_category_count,
                        'll-tools-text-domain'
                    ),
                    $missing_quizzable_category_count
                ),
            ];
        }
    }

    if (function_exists('ll_tools_ipa_keyboard_get_flagged_validation_recording_counts_by_wordset')) {
        $flagged_by_wordset = ll_tools_ipa_keyboard_get_flagged_validation_recording_counts_by_wordset();
        foreach ($flagged_by_wordset as $wordset_entry) {
            $wordset_id = (int) ($wordset_entry['wordset_id'] ?? 0);
            $wordset_name = (string) ($wordset_entry['wordset_name'] ?? '');
            $flagged_transcription_count = (int) ($wordset_entry['count'] ?? 0);
            if ($wordset_id <= 0 || $wordset_name === '' || $flagged_transcription_count <= 0) {
                continue;
            }

            $tasks[] = [
                'key' => 'transcription_validation_' . $wordset_id,
                'url' => add_query_arg([
                    'page' => 'll-ipa-keyboard',
                    'tab' => 'search',
                    'issues' => '1',
                    'wordset_id' => $wordset_id,
                ], admin_url('tools.php')),
                'screen_id' => 'tools_page_ll-ipa-keyboard',
                'screen_query_args' => [
                    'tab' => 'search',
                    'issues' => '1',
                    'wordset_id' => (string) $wordset_id,
                ],
                'title' => sprintf(
                    /* translators: %s: wordset name */
                    __('Transcription Manager: %s', 'll-tools-text-domain'),
                    $wordset_name
                ),
                'message' => sprintf(
                    /* translators: 1: number of recordings, 2: wordset name */
                    _n(
                        '%1$d recording in %2$s has possible transcription issues',
                        '%1$d recordings in %2$s have possible transcription issues',
                        $flagged_transcription_count,
                        'll-tools-text-domain'
                    ),
                    $flagged_transcription_count,
                    $wordset_name
                ),
            ];
        }
    }

    if (function_exists('ll_tools_ipa_keyboard_get_auto_review_recording_counts_by_wordset')) {
        $review_counts_by_wordset = ll_tools_ipa_keyboard_get_auto_review_recording_counts_by_wordset();
        foreach ($review_counts_by_wordset as $wordset_entry) {
            $wordset_id = (int) ($wordset_entry['wordset_id'] ?? 0);
            $wordset_name = (string) ($wordset_entry['wordset_name'] ?? '');
            $review_count = (int) ($wordset_entry['count'] ?? 0);
            if ($wordset_id <= 0 || $wordset_name === '' || $review_count <= 0) {
                continue;
            }

            $tasks[] = [
                'key' => 'transcription_review_' . $wordset_id,
                'url' => add_query_arg([
                    'page' => 'll-ipa-keyboard',
                    'tab' => 'search',
                    'review' => '1',
                    'wordset_id' => $wordset_id,
                ], admin_url('tools.php')),
                'screen_id' => 'tools_page_ll-ipa-keyboard',
                'screen_query_args' => [
                    'tab' => 'search',
                    'review' => '1',
                    'wordset_id' => (string) $wordset_id,
                ],
                'title' => sprintf(
                    /* translators: %s: wordset name */
                    __('Transcription Manager: %s', 'll-tools-text-domain'),
                    $wordset_name
                ),
                'message' => sprintf(
                    /* translators: 1: number of recordings, 2: wordset name */
                    _n(
                        '%1$d auto-generated transcription in %2$s needs review',
                        '%1$d auto-generated transcriptions in %2$s need review',
                        $review_count,
                        'll-tools-text-domain'
                    ),
                    $review_count,
                    $wordset_name
                ),
            ];
        }
    }

    return $tasks;
}

/**
 * Determine whether the current admin screen already matches a maintenance task destination.
 *
 * @param array<string,mixed> $task
 * @param WP_Screen|null $screen
 */
function ll_tools_is_current_admin_task_screen(array $task, $screen = null): bool {
    if (!($screen instanceof WP_Screen) && function_exists('get_current_screen')) {
        $screen = get_current_screen();
    }
    if (!($screen instanceof WP_Screen)) {
        return false;
    }

    $task_screen_id = isset($task['screen_id']) ? (string) $task['screen_id'] : '';
    if ($task_screen_id === '' || $task_screen_id !== (string) $screen->id) {
        return false;
    }

    $required_query_args = isset($task['screen_query_args']) && is_array($task['screen_query_args'])
        ? $task['screen_query_args']
        : [];

    foreach ($required_query_args as $key => $value) {
        $actual = isset($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : '';
        if ($actual !== (string) $value) {
            return false;
        }
    }

    return true;
}

/**
 * Show admin notice if LL Tools maintenance tasks need attention.
 */
add_action('admin_notices', 'll_audio_processor_admin_notice');

function ll_audio_processor_admin_notice() {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $tasks = ll_tools_get_admin_maintenance_tasks();
    if (empty($tasks)) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (count($tasks) === 1 && ll_tools_is_current_admin_task_screen($tasks[0], $screen)) {
        return;
    }

    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>' . esc_html__('LL Tools Admin Tasks', 'll-tools-text-domain') . ':</strong> ';
    echo esc_html__('The following maintenance tasks need attention.', 'll-tools-text-domain');
    echo '</p>';

    if (count($tasks) > 1) {
        echo '<ul>';
        foreach ($tasks as $task) {
            echo '<li>';
            echo '<a href="' . esc_url((string) $task['url']) . '">' . esc_html((string) $task['title']) . '</a>: ';
            echo esc_html((string) $task['message']);
            echo '</li>';
        }
        echo '</ul>';
    } else {
        $task = $tasks[0];
        echo '<p>';
        echo '<a href="' . esc_url((string) $task['url']) . '">' . esc_html((string) $task['title']) . '</a>: ';
        echo esc_html((string) $task['message']);
        echo '</p>';
    }

    echo '</div>';
}
