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

    wp_localize_script('ll-audio-processor-js', 'llAudioProcessor', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_audio_processor'),
        'recordings' => [],
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
            'queueLoading' => __('Loading recordings...', 'll-tools-text-domain'),
            'queueLoadFailed' => __('Could not load recordings. Please try again.', 'll-tools-text-domain'),
            'queueRetry' => __('Retry', 'll-tools-text-domain'),
            'queueEmpty' => __('No unique recordings in the queue. Check the duplicates tab for additional recordings.', 'll-tools-text-domain'),
            'duplicatesEmpty' => __('No duplicates found.', 'll-tools-text-domain'),
            'reprocessEmpty' => __('No recordings with preserved original audio are ready to reprocess.', 'll-tools-text-domain'),
            'queuePrevious' => __('Previous', 'll-tools-text-domain'),
            'queueNext' => __('Next', 'll-tools-text-domain'),
            /* translators: %d: current queue page number */
            'queuePageTemplate' => __('Page %d', 'll-tools-text-domain'),
            /* translators: %d: known minimum number of recordings in a queue */
            'queueCountMoreTemplate' => __('%d+', 'll-tools-text-domain'),
            'wordsetLabel' => __('Wordset:', 'll-tools-text-domain'),
            'categoryLabel' => __('Category:', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_audio_processor_assets');

function ll_audio_processor_get_word_editor_values($word_id, ?array $wordset_ids = null) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [
            'word_text' => '',
            'translation_text' => '',
            'store_in_title' => true,
        ];
    }

    if (function_exists('ll_tools_get_word_text_parts')) {
        $values = ll_tools_get_word_text_parts($word_id, null, true, $wordset_ids);
        return [
            'word_text' => trim((string) ($values['word_text'] ?? '')),
            'translation_text' => trim((string) ($values['translation_text'] ?? '')),
            'store_in_title' => isset($values['store_in_title']) ? (bool) $values['store_in_title'] : true,
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
        ? (bool) ll_tools_should_store_word_in_title($word_id, $wordset_ids)
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

function ll_audio_processor_get_processing_source_path(int $audio_post_id, string $fallback_path): string {
    if (function_exists('ll_tools_get_audio_processing_source_file_path')) {
        $source_path = ll_tools_get_audio_processing_source_file_path($audio_post_id, $fallback_path);
        if ($source_path !== '') {
            return $source_path;
        }
    }

    return trim($fallback_path);
}

function ll_audio_processor_current_user_has_unrestricted_queue_access(): bool {
    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    return ($user instanceof WP_User) && in_array('ll_tools_editor', (array) $user->roles, true);
}

function ll_audio_processor_current_user_queue_wordset_ids(): array {
    if (ll_audio_processor_current_user_has_unrestricted_queue_access()) {
        return [];
    }

    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    $ids = [];
    if (function_exists('ll_tools_get_user_managed_wordset_ids')) {
        $ids = array_merge($ids, (array) ll_tools_get_user_managed_wordset_ids($user_id));
    }
    if (function_exists('ll_tools_get_assigned_recorder_wordset_ids_for_user')) {
        $ids = array_merge($ids, (array) ll_tools_get_assigned_recorder_wordset_ids_for_user($user_id));
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

function ll_audio_processor_current_user_can_view_parent_word(int $parent_word_id): bool {
    if ($parent_word_id <= 0) {
        return false;
    }

    // Internal test/CLI calls may build queue payloads without an active user.
    // Web access to the queue is still gated by the admin page/AJAX capability checks.
    if ((int) get_current_user_id() <= 0) {
        return true;
    }

    if (ll_audio_processor_current_user_has_unrestricted_queue_access()) {
        return true;
    }

    $allowed_wordset_ids = ll_audio_processor_current_user_queue_wordset_ids();
    if (empty($allowed_wordset_ids)) {
        return false;
    }

    $wordset_terms = get_the_terms($parent_word_id, 'wordset');
    if (!is_array($wordset_terms) || empty($wordset_terms)) {
        return false;
    }
    $wordset_ids = array_map(static function (WP_Term $term): int {
        return (int) $term->term_id;
    }, $wordset_terms);

    return !empty(array_intersect(array_map('intval', $wordset_ids), $allowed_wordset_ids));
}

function ll_audio_processor_build_recording_payload(
    int $audio_post_id,
    bool $prefer_original_source = false,
    bool $allow_legacy_image_lookup = true
) {
    $audio_post_id = (int) $audio_post_id;
    if ($audio_post_id <= 0) {
        return null;
    }

    $audio_file = trim((string) get_post_meta($audio_post_id, 'audio_file_path', true));
    $parent_word_id = (int) wp_get_post_parent_id($audio_post_id);
    if ($audio_file === '' || $parent_word_id <= 0) {
        return null;
    }
    if (!ll_audio_processor_current_user_can_view_parent_word($parent_word_id)) {
        return null;
    }

    $source_audio_file = $prefer_original_source
        ? ll_audio_processor_get_processing_source_path($audio_post_id, $audio_file)
        : $audio_file;
    if ($source_audio_file === '') {
        return null;
    }

    $wordset_terms = get_the_terms($parent_word_id, 'wordset');
    $wordset_names = is_array($wordset_terms)
        ? array_values(array_map(static function (WP_Term $term): string {
            return (string) $term->name;
        }, $wordset_terms))
        : [];
    $wordset_ids = is_array($wordset_terms)
        ? array_values(array_map(static function (WP_Term $term): int {
            return (int) $term->term_id;
        }, $wordset_terms))
        : [];

    $word_values = ll_audio_processor_get_word_editor_values($parent_word_id, $wordset_ids);
    $word_title = (string) ($word_values['word_text'] ?? '');
    $category_terms = get_the_terms($parent_word_id, 'word-category');
    $categories = is_array($category_terms)
        ? array_values(array_map(static function (WP_Term $term): string {
            return (string) $term->name;
        }, $category_terms))
        : [];
    $upload_date = (string) get_post_meta($audio_post_id, 'recording_date', true);

    $recording_type_terms = get_the_terms($audio_post_id, 'recording_type');
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
    $original_audio_path = function_exists('ll_tools_get_audio_original_file_path')
        ? ll_tools_get_audio_original_file_path($audio_post_id)
        : '';
    $word_image_data = ll_tools_get_effective_word_image_data_for_word(
        $parent_word_id,
        'thumbnail',
        $allow_legacy_image_lookup
    );
    $word_image_url = (string) ($word_image_data['url'] ?? '');

    return [
        'id' => $audio_post_id,
        'title' => $word_title,
        'wordText' => $word_title,
        'translationText' => (string) ($word_values['translation_text'] ?? ''),
        'storeInTitle' => !empty($word_values['store_in_title']),
        'audioUrl' => site_url($source_audio_file),
        'currentAudioUrl' => site_url($audio_file),
        'uploadDate' => $upload_date,
        'uploadTimestamp' => ll_audio_processor_get_recording_timestamp($upload_date),
        'categories' => $categories,
        'wordsets' => $wordset_names,
        'recordingTypes' => $recording_type_names,
        'recordingTypeItems' => $recording_type_items,
        'recordingType' => $recording_type_slug,
        'imageUrl' => $word_image_url,
        'parentWordId' => (int) $parent_word_id,
        'hasOriginalAudio' => $original_audio_path !== '',
        'usesOriginalAudio' => $source_audio_file !== $audio_file,
    ];
}

/**
 * Keep Audio Processor queue responses bounded even when a filter changes the default.
 */
function ll_audio_processor_queue_page_size(): int {
    $page_size = (int) apply_filters('ll_audio_processor_queue_page_size', 40);
    return max(25, min(50, $page_size));
}

/**
 * Absolute ceiling for cursorless legacy queue offsets.
 *
 * This is intentionally not filterable. Sites may lower or raise the normal
 * limit below, but a filter cannot restore arbitrarily deep SQL OFFSET scans.
 */
function ll_audio_processor_legacy_queue_offset_hard_limit(): int {
    return 50000;
}

/**
 * Maximum SQL OFFSET retained for direct legacy Audio Processor page links.
 */
function ll_audio_processor_legacy_queue_offset_limit(): int {
    $limit = (int) apply_filters('ll_audio_processor_legacy_queue_offset_limit', 5000);

    return max(0, min(ll_audio_processor_legacy_queue_offset_hard_limit(), $limit));
}

/**
 * Build an opaque, user-bound cursor for Audio Processor keyset pagination.
 */
function ll_audio_processor_encode_queue_cursor(string $tab, string $sort_value, int $recording_id): string {
    $tab = sanitize_key($tab);
    $sort_value = trim($sort_value);
    $recording_id = max(0, $recording_id);
    if (!in_array($tab, ['queue', 'duplicates', 'reprocess'], true) || $sort_value === '' || $recording_id <= 0) {
        return '';
    }

    $json = wp_json_encode([
        'v' => 1,
        'tab' => $tab,
        'sort' => $sort_value,
        'id' => $recording_id,
        'user_id' => get_current_user_id(),
    ]);
    if (!is_string($json) || $json === '') {
        return '';
    }

    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, wp_salt('nonce'));
    return $payload . '.' . $signature;
}

/**
 * @return array{sort:string,id:int}|array{}
 */
function ll_audio_processor_decode_queue_cursor(string $cursor, string $tab): array {
    $cursor = trim($cursor);
    $tab = sanitize_key($tab);
    if ($cursor === '' || strlen($cursor) > 512 || !in_array($tab, ['queue', 'duplicates', 'reprocess'], true)) {
        return [];
    }

    $parts = explode('.', $cursor, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return [];
    }
    [$payload, $provided_signature] = $parts;
    $expected_signature = hash_hmac('sha256', $payload, wp_salt('nonce'));
    if (!hash_equals($expected_signature, $provided_signature)) {
        return [];
    }

    $encoded = strtr($payload, '-_', '+/');
    $padding = strlen($encoded) % 4;
    if ($padding > 0) {
        $encoded .= str_repeat('=', 4 - $padding);
    }
    $json = base64_decode($encoded, true);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($decoded)) {
        return [];
    }

    $sort_value = isset($decoded['sort']) && is_scalar($decoded['sort'])
        ? trim((string) $decoded['sort'])
        : '';
    $recording_id = isset($decoded['id']) ? (int) $decoded['id'] : 0;
    if (
        (int) ($decoded['v'] ?? 0) !== 1
        || sanitize_key((string) ($decoded['tab'] ?? '')) !== $tab
        || (int) ($decoded['user_id'] ?? 0) !== get_current_user_id()
        || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sort_value)
        || $recording_id <= 0
    ) {
        return [];
    }

    return [
        'sort' => $sort_value,
        'id' => $recording_id,
    ];
}

/**
 * Calculate a page-derived count offset without overflowing an integer.
 *
 * Cursor-backed requests do not use this value in SQL, but the queue response
 * still reports a best-known count based on the requested logical page.
 */
function ll_audio_processor_safe_queue_page_offset(int $page, int $per_page): int {
    $page = max(1, $page);
    $per_page = max(1, $per_page);
    $page_index = $page - 1;
    $max_offset = PHP_INT_MAX - $per_page;
    if ($page_index > intdiv($max_offset, $per_page)) {
        return $max_offset;
    }

    return $page_index * $per_page;
}

/**
 * Normalize a queue page before selecting its SQL pagination mode.
 *
 * Valid signed cursors always retain keyset continuation, including logical
 * pages beyond the legacy offset ceiling. Cursorless or invalid-cursor pages
 * may use the compatibility OFFSET path only while the computed offset stays
 * within the configured limit; deeper requests rebase to page one.
 *
 * @return array{
 *   page:int,
 *   per_page:int,
 *   offset:int,
 *   cursor:string,
 *   cursor_data:array{sort:string,id:int}|array{},
 *   cursor_applied:bool,
 *   legacy_page_rebased:bool
 * }
 */
function ll_audio_processor_normalize_queue_page_request(
    string $tab,
    int $page,
    int $per_page,
    string $cursor = ''
): array {
    $tab = sanitize_key($tab);
    if (!in_array($tab, ['queue', 'duplicates', 'reprocess'], true)) {
        $tab = 'queue';
    }

    $page = max(1, $page);
    $per_page = max(25, min(50, $per_page));
    $cursor_data = $page > 1
        ? ll_audio_processor_decode_queue_cursor($cursor, $tab)
        : [];
    if (!empty($cursor_data)) {
        return [
            'page' => $page,
            'per_page' => $per_page,
            'offset' => ll_audio_processor_safe_queue_page_offset($page, $per_page),
            'cursor' => trim($cursor),
            'cursor_data' => $cursor_data,
            'cursor_applied' => true,
            'legacy_page_rebased' => false,
        ];
    }

    $legacy_offset_limit = ll_audio_processor_legacy_queue_offset_limit();
    $legacy_page_limit = intdiv($legacy_offset_limit, $per_page) + 1;
    $legacy_page_rebased = $page > $legacy_page_limit;
    if ($legacy_page_rebased) {
        $page = 1;
    }

    return [
        'page' => $page,
        'per_page' => $per_page,
        'offset' => ll_audio_processor_safe_queue_page_offset($page, $per_page),
        'cursor' => '',
        'cursor_data' => [],
        'cursor_applied' => false,
        'legacy_page_rebased' => $legacy_page_rebased,
    ];
}

/**
 * SQL expression matching the first recording_type term used by the legacy queue grouper.
 */
function ll_audio_processor_recording_type_slug_sql(string $audio_alias): string {
    global $wpdb;

    return "COALESCE((
        SELECT recording_type_term.slug
        FROM {$wpdb->term_relationships} recording_type_relationship
        INNER JOIN {$wpdb->term_taxonomy} recording_type_taxonomy
            ON recording_type_taxonomy.term_taxonomy_id = recording_type_relationship.term_taxonomy_id
        INNER JOIN {$wpdb->terms} recording_type_term
            ON recording_type_term.term_id = recording_type_taxonomy.term_id
        WHERE recording_type_relationship.object_id = {$audio_alias}.ID
          AND recording_type_taxonomy.taxonomy = 'recording_type'
        ORDER BY recording_type_term.name ASC, recording_type_term.term_id ASC
        LIMIT 1
    ), '')";
}

/**
 * Restrict queue discovery before payload hydration for scoped LL Tools users.
 */
function ll_audio_processor_queue_access_sql(string $audio_alias): string {
    global $wpdb;

    if (ll_audio_processor_current_user_has_unrestricted_queue_access()) {
        return '';
    }

    $allowed_wordset_ids = ll_audio_processor_current_user_queue_wordset_ids();
    if (empty($allowed_wordset_ids)) {
        return ' AND 1 = 0';
    }

    $allowed_wordset_ids = implode(', ', array_map('intval', $allowed_wordset_ids));
    return " AND EXISTS (
        SELECT 1
        FROM {$wpdb->term_relationships} queue_access_relationship
        INNER JOIN {$wpdb->term_taxonomy} queue_access_taxonomy
            ON queue_access_taxonomy.term_taxonomy_id = queue_access_relationship.term_taxonomy_id
        WHERE queue_access_relationship.object_id = {$audio_alias}.post_parent
          AND queue_access_taxonomy.taxonomy = 'wordset'
          AND queue_access_taxonomy.term_id IN ({$allowed_wordset_ids})
    )";
}

/**
 * Prime only the posts, metadata, and terms needed by the current queue page.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function ll_audio_processor_prime_queue_payload_caches(array $rows): void {
    if (!function_exists('_prime_post_caches') || empty($rows)) {
        return;
    }

    $audio_ids = [];
    $parent_word_ids = [];
    foreach ($rows as $row) {
        $audio_id = (int) ($row['ID'] ?? 0);
        $parent_word_id = (int) ($row['post_parent'] ?? 0);
        if ($audio_id > 0) {
            $audio_ids[] = $audio_id;
        }
        if ($parent_word_id > 0) {
            $parent_word_ids[] = $parent_word_id;
        }
    }

    $audio_ids = array_values(array_unique($audio_ids));
    $parent_word_ids = array_values(array_unique($parent_word_ids));
    if (!empty($audio_ids)) {
        _prime_post_caches($audio_ids, true, true);
        foreach ($audio_ids as $audio_id) {
            if (wp_cache_get($audio_id, 'recording_type_relationships') === false) {
                wp_cache_add($audio_id, [], 'recording_type_relationships');
            }
        }
    }
    if (!empty($parent_word_ids)) {
        _prime_post_caches($parent_word_ids, true, true);
        foreach ($parent_word_ids as $parent_word_id) {
            foreach (['word-category', 'wordset'] as $taxonomy) {
                $cache_group = $taxonomy . '_relationships';
                if (wp_cache_get($parent_word_id, $cache_group) === false) {
                    wp_cache_add($parent_word_id, [], $cache_group);
                }
            }
        }
    }

    // The queue cards render canonical word images. Prime their post and
    // attachment metadata in batches so card hydration does not add one query
    // per visible word when those links are already materialized.
    $word_image_ids = [];
    $attachment_ids = [];
    foreach ($parent_word_ids as $parent_word_id) {
        $word_image_id = (int) get_post_meta($parent_word_id, '_ll_autopicked_image_id', true);
        if ($word_image_id > 0) {
            $word_image_ids[] = $word_image_id;
        }

        $word_attachment_id = (int) get_post_meta($parent_word_id, '_thumbnail_id', true);
        if ($word_attachment_id > 0) {
            $attachment_ids[] = $word_attachment_id;
        }
    }

    $word_image_ids = array_values(array_unique($word_image_ids));
    if (!empty($word_image_ids)) {
        _prime_post_caches($word_image_ids, true, true);
        foreach ($word_image_ids as $word_image_id) {
            $attachment_id = (int) get_post_meta($word_image_id, '_thumbnail_id', true);
            if ($attachment_id > 0) {
                $attachment_ids[] = $attachment_id;
            }
        }
    }

    $attachment_ids = array_values(array_unique($attachment_ids));
    if (!empty($attachment_ids)) {
        _prime_post_caches($attachment_ids, false, true);
    }
}

/**
 * Load one bounded Audio Processor tab page without hydrating the rest of the queue.
 *
 * @return array{tab:string,page:int,perPage:int,hasMore:bool,knownCount:int,cursorApplied:bool,legacyPageRebased:bool,nextCursor:string,recordings:array<int,array<string,mixed>>}
 */
function ll_audio_processor_get_queue_page(
    string $tab,
    int $page = 1,
    ?int $per_page = null,
    string $cursor = ''
): array {
    global $wpdb;

    $allowed_tabs = ['queue', 'duplicates', 'reprocess'];
    if (!in_array($tab, $allowed_tabs, true)) {
        $tab = 'queue';
    }

    $per_page = $per_page === null ? ll_audio_processor_queue_page_size() : max(25, min(50, $per_page));
    $request_page = ll_audio_processor_normalize_queue_page_request(
        $tab,
        $page,
        $per_page,
        $cursor
    );
    $page = (int) $request_page['page'];
    $per_page = (int) $request_page['per_page'];
    $offset = (int) $request_page['offset'];
    $query_limit = $per_page + 1;
    $access_sql = ll_audio_processor_queue_access_sql('audio');
    $cursor_data = (array) $request_page['cursor_data'];
    $cursor_applied = !empty($request_page['cursor_applied']);
    $legacy_page_rebased = !empty($request_page['legacy_page_rebased']);

    if ($tab === 'reprocess') {
        if (!defined('LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY')) {
            $rows = [];
        } else {
            $original_meta_key = esc_sql((string) LL_TOOLS_ORIGINAL_AUDIO_FILE_PATH_META_KEY);
            $keyset_sql = '';
            if ($cursor_applied) {
                $keyset_sql = $wpdb->prepare(
                    ' AND (audio.post_modified < %s OR (audio.post_modified = %s AND audio.ID < %d))',
                    (string) $cursor_data['sort'],
                    (string) $cursor_data['sort'],
                    (int) $cursor_data['id']
                );
            }
            $page_sql = $cursor_applied
                ? "LIMIT {$query_limit}"
                : "LIMIT {$query_limit} OFFSET {$offset}";
            $rows = $wpdb->get_results(
                "
                SELECT audio.ID, audio.post_parent, audio.post_modified AS queue_sort, '' AS duplicate_reason
                FROM {$wpdb->posts} audio
                WHERE audio.post_type = 'word_audio'
                  AND audio.post_status IN ('publish', 'draft')
                  AND audio.post_parent > 0
                  AND EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} source_path
                      WHERE source_path.post_id = audio.ID
                        AND source_path.meta_key = '{$original_meta_key}'
                        AND TRIM(COALESCE(source_path.meta_value, '')) <> ''
                  )
                  AND EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} audio_path
                      WHERE audio_path.post_id = audio.ID
                        AND audio_path.meta_key = 'audio_file_path'
                        AND TRIM(COALESCE(audio_path.meta_value, '')) <> ''
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} processing_flag
                      WHERE processing_flag.post_id = audio.ID
                        AND processing_flag.meta_key = '_ll_needs_audio_processing'
                        AND processing_flag.meta_value = '1'
                  )
                  {$access_sql}
                  {$keyset_sql}
                ORDER BY audio.post_modified DESC, audio.ID DESC
                {$page_sql}
                ",
                ARRAY_A
            );
        }
    } else {
        $candidate_type_sql = ll_audio_processor_recording_type_slug_sql('audio');
        $published_type_sql = ll_audio_processor_recording_type_slug_sql('published_audio');
        $earlier_type_sql = ll_audio_processor_recording_type_slug_sql('earlier_audio');
        $candidate_sql = "
            SELECT audio.ID, audio.post_parent, audio.post_date, {$candidate_type_sql} AS recording_type_slug
            FROM {$wpdb->posts} audio
            WHERE audio.post_type = 'word_audio'
              AND audio.post_status IN ('publish', 'draft')
              AND audio.post_parent > 0
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} processing_flag
                  WHERE processing_flag.post_id = audio.ID
                    AND processing_flag.meta_key = '_ll_needs_audio_processing'
                    AND processing_flag.meta_value = '1'
              )
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} audio_path
                  WHERE audio_path.post_id = audio.ID
                    AND audio_path.meta_key = 'audio_file_path'
                    AND TRIM(COALESCE(audio_path.meta_value, '')) <> ''
              )
              {$access_sql}
        ";
        $published_duplicate_sql = "EXISTS (
            SELECT 1
            FROM {$wpdb->posts} published_audio
            WHERE published_audio.post_type = 'word_audio'
              AND published_audio.post_status = 'publish'
              AND published_audio.post_parent = candidate.post_parent
              AND published_audio.ID <> candidate.ID
              AND {$published_type_sql} = candidate.recording_type_slug
        )";
        $earlier_pending_sql = "EXISTS (
            SELECT 1
            FROM {$wpdb->posts} earlier_audio
            WHERE earlier_audio.post_type = 'word_audio'
              AND earlier_audio.post_status IN ('publish', 'draft')
              AND earlier_audio.post_parent = candidate.post_parent
              AND (
                  earlier_audio.post_date > candidate.post_date
                  OR (earlier_audio.post_date = candidate.post_date AND earlier_audio.ID > candidate.ID)
              )
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} earlier_processing_flag
                  WHERE earlier_processing_flag.post_id = earlier_audio.ID
                    AND earlier_processing_flag.meta_key = '_ll_needs_audio_processing'
                    AND earlier_processing_flag.meta_value = '1'
              )
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} earlier_audio_path
                  WHERE earlier_audio_path.post_id = earlier_audio.ID
                    AND earlier_audio_path.meta_key = 'audio_file_path'
                    AND TRIM(COALESCE(earlier_audio_path.meta_value, '')) <> ''
              )
              AND {$earlier_type_sql} = candidate.recording_type_slug
        )";
        $duplicate_reason_sql = "CASE
            WHEN {$published_duplicate_sql} THEN 'published'
            WHEN {$earlier_pending_sql} THEN 'queued'
            ELSE ''
        END";
        $having_sql = $tab === 'duplicates'
            ? "HAVING duplicate_reason <> ''"
            : "HAVING duplicate_reason = ''";
        $keyset_sql = '';
        if ($cursor_applied) {
            $keyset_sql = $wpdb->prepare(
                'WHERE (candidate.post_date < %s OR (candidate.post_date = %s AND candidate.ID < %d))',
                (string) $cursor_data['sort'],
                (string) $cursor_data['sort'],
                (int) $cursor_data['id']
            );
        }
        $page_sql = $cursor_applied
            ? "LIMIT {$query_limit}"
            : "LIMIT {$query_limit} OFFSET {$offset}";

        $rows = $wpdb->get_results(
            "
            SELECT candidate.ID, candidate.post_parent, candidate.post_date AS queue_sort, {$duplicate_reason_sql} AS duplicate_reason
            FROM ({$candidate_sql}) candidate
            {$keyset_sql}
            {$having_sql}
            ORDER BY candidate.post_date DESC, candidate.ID DESC
            {$page_sql}
            ",
            ARRAY_A
        );
    }

    $rows = is_array($rows) ? $rows : [];
    $has_more = count($rows) > $per_page;
    if ($has_more) {
        array_pop($rows);
    }
    $next_cursor = '';
    if ($has_more && !empty($rows)) {
        $last_row = end($rows);
        if (is_array($last_row)) {
            $next_cursor = ll_audio_processor_encode_queue_cursor(
                $tab,
                (string) ($last_row['queue_sort'] ?? ''),
                (int) ($last_row['ID'] ?? 0)
            );
        }
        reset($rows);
    }

    ll_audio_processor_prime_queue_payload_caches($rows);

    $recordings = [];
    $parent_word_ids = [];
    foreach ($rows as $row) {
        $recording = ll_audio_processor_build_recording_payload((int) ($row['ID'] ?? 0), true, false);
        if (!is_array($recording)) {
            continue;
        }

        $recording['duplicateReason'] = (string) ($row['duplicate_reason'] ?? '');
        $recording['reprocessAvailable'] = !empty($recording['hasOriginalAudio']);
        if ($tab === 'reprocess') {
            $recording['isReprocessSource'] = true;
        }
        $recordings[] = $recording;
        $parent_word_ids[] = (int) ($recording['parentWordId'] ?? 0);
    }

    $audio_child_count_map = ll_audio_processor_get_word_audio_child_count_map($parent_word_ids);
    foreach ($recordings as &$recording) {
        $parent_word_id = (int) ($recording['parentWordId'] ?? 0);
        $recording['splitWordEnabled'] = $parent_word_id > 0
            && ((int) ($audio_child_count_map[$parent_word_id] ?? 0) > 1);
    }
    unset($recording);

    $known_count = $offset > PHP_INT_MAX - count($recordings)
        ? PHP_INT_MAX
        : $offset + count($recordings);

    return [
        'tab' => $tab,
        'page' => $page,
        'perPage' => $per_page,
        'hasMore' => $has_more,
        'knownCount' => $known_count,
        'cursorApplied' => $cursor_applied,
        'legacyPageRebased' => $legacy_page_rebased,
        'nextCursor' => $next_cursor,
        'recordings' => $recordings,
    ];
}

function ll_audio_processor_render_queue_page_html(array $page_data): string {
    $tab = (string) ($page_data['tab'] ?? 'queue');
    $recordings = isset($page_data['recordings']) && is_array($page_data['recordings'])
        ? $page_data['recordings']
        : [];

    ob_start();
    if ($tab === 'duplicates' && !empty($recordings)) {
        echo '<div class="ll-duplicate-note">';
        echo esc_html__('Duplicates are hidden so you can process one recording per word and recording type first.', 'll-tools-text-domain');
        echo '</div>';
    } elseif ($tab === 'reprocess' && !empty($recordings)) {
        echo '<div class="ll-duplicate-note">';
        echo esc_html__('These recordings use their saved original audio source. Saving replaces the current processed audio for the same word.', 'll-tools-text-domain');
        echo '</div>';
    }

    foreach ($recordings as $recording) {
        ll_render_audio_processor_recording_item(
            $recording,
            $tab === 'duplicates' ? (string) ($recording['duplicateReason'] ?? '') : ''
        );
    }

    return (string) ob_get_clean();
}

function ll_audio_processor_load_queue_page_handler(): void {
    if (!check_ajax_referer('ll_audio_processor', 'nonce', false)) {
        wp_send_json_error(__('The queue request expired. Refresh the page and try again.', 'll-tools-text-domain'), 403);
    }

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'), 403);
    }

    $tab = isset($_POST['tab']) ? sanitize_key((string) wp_unslash($_POST['tab'])) : 'queue';
    if (!in_array($tab, ['queue', 'duplicates', 'reprocess'], true)) {
        wp_send_json_error(__('Invalid queue.', 'll-tools-text-domain'), 400);
    }

    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $cursor = isset($_POST['cursor']) && !is_array($_POST['cursor'])
        ? sanitize_text_field(wp_unslash((string) $_POST['cursor']))
        : '';
    $page_data = ll_audio_processor_get_queue_page($tab, $page, null, $cursor);
    $page_data['html'] = ll_audio_processor_render_queue_page_html($page_data);
    wp_send_json_success($page_data);
}
add_action('wp_ajax_ll_audio_processor_load_queue_page', 'll_audio_processor_load_queue_page_handler');

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
    $source_label = !empty($recording['isReprocessSource'])
        ? __('Saved original source', 'll-tools-text-domain')
        : '';

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
                    <?php if ($source_label): ?>
                        <span class="ll-recording-duplicate"><?php echo esc_html($source_label); ?></span>
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

function ll_render_audio_processor_queue_panel(
    string $tab,
    bool $is_active,
    int $initial_page = 1,
    string $initial_cursor = ''
): void {
    $initial_page = max(1, $initial_page);
    ?>
    <div
        id="ll-recordings-<?php echo esc_attr($tab); ?>"
        class="ll-recordings-list <?php echo $is_active ? 'is-active' : ''; ?>"
        data-tab="<?php echo esc_attr($tab); ?>"
        data-page="<?php echo esc_attr((string) $initial_page); ?>"
        data-cursor="<?php echo esc_attr($initial_page > 1 ? $initial_cursor : ''); ?>"
        data-loaded="false"
        role="tabpanel"
        aria-hidden="<?php echo $is_active ? 'false' : 'true'; ?>"
    >
        <div class="ll-queue-status is-loading" role="status" aria-live="polite">
            <span class="ll-queue-status-text"><?php echo esc_html__('Loading recordings...', 'll-tools-text-domain'); ?></span>
            <button type="button" class="button ll-queue-retry" hidden>
                <?php echo esc_html__('Retry', 'll-tools-text-domain'); ?>
            </button>
        </div>
        <div class="ll-queue-items"></div>
        <div class="ll-queue-pagination" hidden>
            <button type="button" class="button ll-queue-page-previous">
                <?php echo esc_html__('Previous', 'll-tools-text-domain'); ?>
            </button>
            <span class="ll-queue-page-label" aria-live="polite"></span>
            <button type="button" class="button ll-queue-page-next">
                <?php echo esc_html__('Next', 'll-tools-text-domain'); ?>
            </button>
        </div>
    </div>
    <?php
}

function ll_render_audio_processor_page() {
    if (function_exists('ll_tools_acknowledge_recording_notification_batch_from_processor_page')) {
        ll_tools_acknowledge_recording_notification_batch_from_processor_page();
    }

    $active_tab = 'queue';
    $requested_tab = isset($_GET['ll_ap_tab']) ? sanitize_key((string) $_GET['ll_ap_tab']) : '';
    if (in_array($requested_tab, ['queue', 'duplicates', 'reprocess'], true)) {
        $active_tab = $requested_tab;
    }
    $active_page = isset($_GET['ll_ap_page'])
        ? max(1, (int) wp_unslash((string) $_GET['ll_ap_page']))
        : 1;
    $active_cursor = isset($_GET['ll_ap_cursor']) && !is_array($_GET['ll_ap_cursor'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_ap_cursor']))
        : '';
    if (strlen($active_cursor) > 512) {
        $active_cursor = '';
    }
    $active_request_page = ll_audio_processor_normalize_queue_page_request(
        $active_tab,
        $active_page,
        ll_audio_processor_queue_page_size(),
        $active_cursor
    );
    $active_page = (int) $active_request_page['page'];
    $active_cursor = (string) $active_request_page['cursor'];
    ?>
    <div class="wrap ll-audio-processor-wrap">
        <h1><?php esc_html_e('Audio Processor', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Process uploaded audio recordings with configurable noise reduction, loudness normalization, and silence trimming.', 'll-tools-text-domain'); ?></p>

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

            <div
                class="ll-audio-processor-tabs"
                role="tablist"
                data-initial-tab="<?php echo esc_attr($active_tab); ?>"
                data-auto-select-work="<?php echo $requested_tab === '' ? 'true' : 'false'; ?>"
            >
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>"
                    data-tab="queue"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'queue' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-queue"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Queue', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="queue" aria-label="<?php echo esc_attr__('Loading', 'll-tools-text-domain'); ?>">&hellip;</span>
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
                    <span class="ll-tab-count" data-tab-count="duplicates" aria-label="<?php echo esc_attr__('Loading', 'll-tools-text-domain'); ?>">&hellip;</span>
                </button>
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'reprocess' ? 'is-active' : ''; ?>"
                    data-tab="reprocess"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'reprocess' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-reprocess"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Reprocess', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="reprocess" aria-label="<?php echo esc_attr__('Loading', 'll-tools-text-domain'); ?>">&hellip;</span>
                </button>
            </div>

            <?php ll_render_audio_processor_queue_panel('queue', $active_tab === 'queue', $active_tab === 'queue' ? $active_page : 1, $active_tab === 'queue' ? $active_cursor : ''); ?>
            <?php ll_render_audio_processor_queue_panel('duplicates', $active_tab === 'duplicates', $active_tab === 'duplicates' ? $active_page : 1, $active_tab === 'duplicates' ? $active_cursor : ''); ?>
            <?php ll_render_audio_processor_queue_panel('reprocess', $active_tab === 'reprocess', $active_tab === 'reprocess' ? $active_page : 1, $active_tab === 'reprocess' ? $active_cursor : ''); ?>

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

function ll_audio_processor_collect_processing_settings_from_request(): array {
    $int_fields = [
        'trim_start' => 'trim_start',
        'trim_end' => 'trim_end',
        'source_samples' => 'source_samples',
        'sample_rate' => 'sample_rate',
    ];
    $settings = [];
    foreach ($int_fields as $request_key => $setting_key) {
        if (!isset($_POST[$request_key])) {
            continue;
        }
        $settings[$setting_key] = max(0, (int) wp_unslash((string) $_POST[$request_key]));
    }

    foreach (['enable_trim', 'enable_noise', 'enable_loudness', 'used_original_source'] as $request_key) {
        if (!isset($_POST[$request_key])) {
            continue;
        }
        $settings[$request_key] = ((string) wp_unslash((string) $_POST[$request_key]) === '1') ? 1 : 0;
    }

    if (!empty($settings)) {
        $settings['processed_at'] = current_time('mysql');
        $settings['processed_by'] = (int) get_current_user_id();
    }

    return $settings;
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
    $previous_audio_file = trim((string) get_post_meta($audio_post_id, 'audio_file_path', true));
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
    if ($previous_audio_file !== '' && function_exists('ll_tools_store_original_audio_if_enabled')) {
        ll_tools_store_original_audio_if_enabled((int) $audio_post_id, $previous_audio_file, [], 'audio_processor');
    }
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    delete_post_meta($audio_post_id, '_ll_needs_audio_processing');
    update_post_meta($audio_post_id, '_ll_processed_audio_date', current_time('mysql'));
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');
    $processing_settings = ll_audio_processor_collect_processing_settings_from_request();
    if (!empty($processing_settings) && defined('LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY')) {
        update_post_meta($audio_post_id, LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY, $processing_settings);
    }

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

    $updated = function_exists('ll_tools_update_word_target_text')
        ? ll_tools_update_word_target_text($word_id, $word_text, true)
        : wp_update_post([
            'ID' => $word_id,
            'post_title' => $word_text,
        ], true);

    if (is_wp_error($updated)) {
        wp_send_json_error(__('Could not update word details.', 'll-tools-text-domain'));
    }

    if ($translation_text !== '') {
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_english_meaning');
    }

    if (function_exists('ll_tools_update_word_default_translation_text')) {
        ll_tools_update_word_default_translation_text($word_id, $translation_text, true);
    } elseif ($translation_text !== '') {
        update_post_meta($word_id, 'word_translation', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_translation');
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
    if (function_exists('ll_tools_get_audio_original_file_path')) {
        $original_audio_file_path = ll_tools_get_audio_original_file_path((int) $audio_post_id);
        if ($original_audio_file_path !== '' && $original_audio_file_path !== $audio_file_path) {
            $original_full_path = ll_audio_processor_resolve_safe_delete_path($original_audio_file_path);
            if ($original_full_path !== '' && file_exists($original_full_path)) {
                @unlink($original_full_path);
            }
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
    $ipa_notice_counts = function_exists('ll_tools_ipa_keyboard_get_admin_notice_recording_counts_by_wordset')
        ? ll_tools_ipa_keyboard_get_admin_notice_recording_counts_by_wordset()
        : null;

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
        $flagged_by_wordset = is_array($ipa_notice_counts)
            ? (array) ($ipa_notice_counts['validation'] ?? [])
            : ll_tools_ipa_keyboard_get_flagged_validation_recording_counts_by_wordset();
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
        $review_counts_by_wordset = is_array($ipa_notice_counts)
            ? (array) ($ipa_notice_counts['auto_review'] ?? [])
            : ll_tools_ipa_keyboard_get_auto_review_recording_counts_by_wordset();
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

    return (array) apply_filters('ll_tools_admin_maintenance_tasks', $tasks);
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
