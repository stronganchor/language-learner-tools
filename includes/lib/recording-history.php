<?php
if (!defined('WPINC')) { die; }

/** Private, read-only recorder history. No caller-supplied recorder identity. */
function ll_tools_recording_history_error(string $code = 'll_recording_history_unavailable', int $status = 503): WP_Error {
    return new WP_Error($code, __('Recordings could not be loaded. Please try again.', 'll-tools-text-domain'), ['status' => $status]);
}

function ll_tools_recording_history_scope(int $wordset_id) {
    if (!ll_tools_user_can_record() || !current_user_can('view_ll_tools')) {
        return ll_tools_recording_history_error('ll_recording_history_forbidden', 403);
    }
    $term = get_term($wordset_id, 'wordset');
    if (!($term instanceof WP_Term)) {
        return ll_tools_recording_history_error('ll_recording_history_forbidden', 403);
    }
    $user_id = get_current_user_id();
    // Assignment authorizes the recorder's own work in a private wordset too.
    // An empty assignment must not broaden history to every public wordset.
    $assigned = ll_tools_get_assigned_recorder_wordset_ids_for_user($user_id);
    if (in_array($wordset_id, $assigned, true)) {
        return $term;
    }
    if (!ll_tools_user_can_manage_wordset_content($term, $user_id)) {
        return ll_tools_recording_history_error('ll_recording_history_forbidden', 403);
    }
    return $term;
}

function ll_tools_recording_history_cursor(int $wordset_id, int $before_id): string {
    $body = ll_tools_recorder_queue_cursor_base64url_encode((string) wp_json_encode([
        'user' => get_current_user_id(), 'wordset' => $wordset_id,
        'before' => $before_id, 'expires' => time() + HOUR_IN_SECONDS,
    ]));
    return $body . '.' . hash_hmac('sha256', $body, wp_salt('nonce') . '|recording-history');
}

function ll_tools_recording_history_parse_cursor(string $cursor, int $wordset_id) {
    if ($cursor === '') { return 0; }
    if (strlen($cursor) > 512 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $parts)
        || !hash_equals(hash_hmac('sha256', $parts[1], wp_salt('nonce') . '|recording-history'), $parts[2])) {
        return ll_tools_recording_history_error('ll_recording_history_cursor_invalid', 400);
    }
    $data = json_decode(ll_tools_recorder_queue_cursor_base64url_decode($parts[1]), true);
    if (!is_array($data) || ($data['user'] ?? null) !== get_current_user_id()
        || ($data['wordset'] ?? null) !== $wordset_id || !is_int($data['before'] ?? null)
        || $data['before'] <= 0 || !is_int($data['expires'] ?? null) || $data['expires'] < time()) {
        return ll_tools_recording_history_error('ll_recording_history_cursor_invalid', 400);
    }
    return $data['before'];
}

/** One bounded candidate page; first meta row matches get_post_meta(..., true). */
function ll_tools_recording_history_candidates(int $wordset_id, int $before_id, int $limit) {
    global $wpdb;
    $user_id = get_current_user_id();
    $limit = max(1, min(26, $limit));
    $before_sql = $before_id > 0 ? $wpdb->prepare(' AND recording.ID < %d', $before_id) : '';
    $sql = "SELECT recording.ID, recording.post_parent
        FROM {$wpdb->posts} recording
        INNER JOIN {$wpdb->posts} parent ON parent.ID = recording.post_parent
        LEFT JOIN {$wpdb->postmeta} speaker ON speaker.post_id = recording.ID AND speaker.meta_key = 'speaker_user_id'
            AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} earlier_speaker WHERE earlier_speaker.post_id = speaker.post_id
                AND earlier_speaker.meta_key = speaker.meta_key AND earlier_speaker.meta_id < speaker.meta_id)
        LEFT JOIN {$wpdb->postmeta} prompt_speaker ON prompt_speaker.post_id = parent.ID AND prompt_speaker.meta_key = %s
            AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} earlier_prompt_speaker WHERE earlier_prompt_speaker.post_id = prompt_speaker.post_id
                AND earlier_prompt_speaker.meta_key = prompt_speaker.meta_key AND earlier_prompt_speaker.meta_id < prompt_speaker.meta_id)
        WHERE parent.post_status IN ('publish','draft','pending','private','future')
        AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} scope_rel
            INNER JOIN {$wpdb->term_taxonomy} scope_term ON scope_term.term_taxonomy_id = scope_rel.term_taxonomy_id
            WHERE scope_rel.object_id = parent.ID AND scope_term.taxonomy = 'wordset' AND scope_term.term_id = %d)
        AND (
            (recording.post_type = 'word_audio' AND parent.post_type = 'words'
                AND recording.post_status IN ('publish','draft','pending','private','future')
                AND (CASE WHEN CAST(speaker.meta_value AS SIGNED) > 0 THEN CAST(speaker.meta_value AS SIGNED) ELSE recording.post_author END) = %d)
            OR (recording.post_type = 'attachment' AND parent.post_type = %s AND recording.post_status = 'inherit'
                AND recording.post_mime_type LIKE 'audio/%%'
                AND (CASE WHEN CAST(prompt_speaker.meta_value AS SIGNED) > 0 THEN CAST(prompt_speaker.meta_value AS SIGNED) ELSE recording.post_author END) = %d
                AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} active_audio WHERE active_audio.post_id = parent.ID AND active_audio.meta_key = %s
                    AND CAST(active_audio.meta_value AS UNSIGNED) = recording.ID
                    AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} earlier_audio WHERE earlier_audio.post_id = active_audio.post_id
                        AND earlier_audio.meta_key = active_audio.meta_key AND earlier_audio.meta_id < active_audio.meta_id)))
        ) {$before_sql} ORDER BY recording.ID DESC LIMIT %d";
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare($sql,
        LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY, $wordset_id, $user_id,
        LL_TOOLS_PROMPT_CARD_POST_TYPE, $user_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, $limit
    ), ARRAY_A);
    return $wpdb->last_error !== '' || !is_array($rows) ? ll_tools_recording_history_error() : $rows;
}

/** Exact object checks precede titles, attribution labels, links, and media URLs. */
function ll_tools_recording_history_item(int $recording_id, int $wordset_id) {
    global $wpdb;
    $recording = get_post($recording_id);
    $parent = $recording instanceof WP_Post ? get_post((int) $recording->post_parent) : null;
    if (!($recording instanceof WP_Post) || !($parent instanceof WP_Post)
        || !in_array($parent->post_status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return null;
    }
    $is_prompt = $recording->post_type === 'attachment' && $parent->post_type === LL_TOOLS_PROMPT_CARD_POST_TYPE;
    if (!$is_prompt && ($recording->post_type !== 'word_audio' || $parent->post_type !== 'words'
        || !in_array($recording->post_status, ['publish', 'draft', 'pending', 'private', 'future'], true))) {
        return null;
    }
    if ($is_prompt && ($recording->post_status !== 'inherit' || strpos($recording->post_mime_type, 'audio/') !== 0)) {
        return null;
    }
    $wpdb->last_error = '';
    $speaker = (int) get_post_meta($is_prompt ? $parent->ID : $recording->ID,
        $is_prompt ? LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY : 'speaker_user_id', true);
    if ($wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
    if (($speaker > 0 ? $speaker : (int) $recording->post_author) !== get_current_user_id()) { return null; }
    if ($is_prompt && (int) get_post_meta($parent->ID, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, true) !== $recording->ID) {
        return null;
    }
    if (($parent->post_status === 'private' && !current_user_can('read_post', $parent->ID))
        || ($recording->post_status === 'private' && !current_user_can('read_post', $recording->ID))
        || post_password_required($parent) || post_password_required($recording)) {
        return null;
    }
    // Staff-created draft parents are valid recording targets. Their exact
    // recorder scope, not edit_posts, authorizes these own pending uploads.
    $wpdb->last_error = '';
    $wordsets = wp_get_post_terms($parent->ID, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordsets) || $wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
    if (!in_array($wordset_id, array_map('intval', $wordsets), true)) { return null; }
    $wpdb->last_error = '';
    $terms = wp_get_post_terms($parent->ID, 'word-category', ['orderby' => 'term_id']);
    if (is_wp_error($terms) || $wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
    $categories = [];
    foreach ($terms as $term) {
        $complete = true;
        $visible = ll_tools_user_can_view_category($term, get_current_user_id(), $complete);
        if (!$complete) { return ll_tools_recording_history_error(); }
        $owner = ll_tools_get_category_wordset_owner_id((int) $term->term_id, $complete);
        if (!$complete) { return ll_tools_recording_history_error(); }
        if ($visible && ($owner <= 0 || $owner === $wordset_id)) {
            $categories[] = ['id' => (int) $term->term_id, 'slug' => (string) $term->slug, 'name' => (string) $term->name];
        }
    }
    if ($terms && !$categories) { return null; }
    $wpdb->last_error = '';
    $types = $is_prompt ? [] : wp_get_post_terms($recording->ID, 'recording_type');
    if (is_wp_error($types) || $wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
    $type_names = $is_prompt ? [__('Prompt audio', 'll-tools-text-domain')] : array_map(static function (WP_Term $type): string {
        return ll_get_recording_type_name((string) $type->slug, (string) $type->name);
    }, $types);
    $wpdb->last_error = '';
    $date = (string) get_post_meta($is_prompt ? $parent->ID : $recording->ID,
        $is_prompt ? LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_AT_META_KEY : 'recording_date', true);
    if ($date === '') { $date = $recording->post_date; }
    try {
        $timestamp = ctype_digit($date) ? (int) $date : (new DateTimeImmutable($date, wp_timezone()))->getTimestamp();
    } catch (Exception $error) { $timestamp = 0; }
    $needs_processing = !$is_prompt && (string) get_post_meta($recording->ID, '_ll_needs_audio_processing', true) === '1';
    $status = $needs_processing ? 'processing' : ($is_prompt ? $parent->post_status : $recording->post_status);
    $labels = [
        'processing' => __('Awaiting processing', 'll-tools-text-domain'),
        'publish' => __('Published', 'll-tools-text-domain'),
        'draft' => __('Draft', 'll-tools-text-domain'),
        'pending' => __('Pending review', 'll-tools-text-domain'),
        'private' => __('Private', 'll-tools-text-domain'),
        'future' => __('Scheduled', 'll-tools-text-domain'),
    ];
    $audio_url = $is_prompt ? wp_get_attachment_url($recording->ID)
        : ll_tools_resolve_audio_file_url(get_post_meta($recording->ID, 'audio_file_path', true));
    if ($wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
    $word_url = !$is_prompt && $parent->post_status === 'publish' && current_user_can('read_post', $parent->ID)
        ? get_permalink($parent) : '';
    return [
        'id' => $recording->ID,
        'title' => wp_strip_all_tags((string) $parent->post_title),
        'word_url' => esc_url_raw((string) $word_url, ['http', 'https']),
        'categories' => array_slice($categories, 0, 5),
        'recording_type' => implode(', ', array_slice($type_names, 0, 5)),
        'timestamp' => $timestamp,
        'date' => $timestamp > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '',
        'status' => $status, 'status_label' => $labels[$status] ?? $labels['draft'],
        'audio_url' => esc_url_raw((string) $audio_url, ['http', 'https']),
    ];
}

function ll_tools_recording_history_page(int $wordset_id, string $cursor = '') {
    global $wpdb;
    $scope = ll_tools_recording_history_scope($wordset_id);
    if (is_wp_error($scope)) { return $scope; }
    $before = ll_tools_recording_history_parse_cursor($cursor, $wordset_id);
    if (is_wp_error($before)) { return $before; }
    $items = [];
    $scanned = 0;
    $has_more = false;
    while (count($items) < 20 && $scanned < 100) {
        $candidates = ll_tools_recording_history_candidates($wordset_id, $before, 26);
        if (is_wp_error($candidates)) { return $candidates; }
        $has_more = count($candidates) > 25;
        $batch = array_slice($candidates, 0, 25);
        $wpdb->last_error = '';
        _prime_post_caches(array_values(array_unique(array_merge(
            array_map('intval', wp_list_pluck($batch, 'ID')), array_map('intval', wp_list_pluck($batch, 'post_parent'))
        ))), false, true);
        if ($wpdb->last_error !== '') { return ll_tools_recording_history_error(); }
        foreach ($batch as $index => $candidate) {
            $before = (int) $candidate['ID'];
            $scanned++;
            $item = ll_tools_recording_history_item($before, $wordset_id);
            if (is_wp_error($item)) { return $item; }
            if ($item !== null) { $items[] = $item; }
            if (count($items) >= 20) {
                $has_more = $has_more || $index < count($batch) - 1;
                break;
            }
        }
        if (!$has_more || count($items) >= 20) { break; }
    }
    // Assignment removal during a request must not publish its already-built page.
    $scope = ll_tools_recording_history_scope($wordset_id);
    if (is_wp_error($scope)) { return $scope; }
    return ['items' => $items, 'has_more' => $has_more,
        'next_cursor' => $has_more ? ll_tools_recording_history_cursor($wordset_id, $before) : ''];
}

function ll_tools_recording_history_ajax(): void {
    if (!is_user_logged_in() || !ll_tools_user_can_record() || !current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You do not have access to these recordings.', 'll-tools-text-domain')], 403);
    }
    $nonce = $_POST['nonce'] ?? '';
    $wordset = $_POST['wordset_id'] ?? '';
    $cursor = $_POST['cursor'] ?? '';
    if (!is_string($nonce) || !wp_verify_nonce($nonce, 'll_tools_recording_history')) {
        wp_send_json_error(['message' => __('Please reload the page and try again.', 'll-tools-text-domain')], 403);
    }
    if (!is_scalar($wordset) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $wordset)
        || !is_string($cursor) || strlen($cursor) > 512) {
        wp_send_json_error(['message' => __('The recording history request is invalid.', 'll-tools-text-domain')], 400);
    }
    if (function_exists('ll_tools_recorder_apply_ajax_locale')) { ll_tools_recorder_apply_ajax_locale(); }
    nocache_headers();
    $page = ll_tools_recording_history_page((int) $wordset, wp_unslash($cursor));
    if (is_wp_error($page)) {
        $data = $page->get_error_data();
        wp_send_json_error(['code' => $page->get_error_code(), 'message' => $page->get_error_message()], (int) ($data['status'] ?? 503));
    }
    wp_send_json_success($page);
}
add_action('wp_ajax_ll_tools_recording_history', 'll_tools_recording_history_ajax');

function ll_tools_recording_history_enqueue(): void {
    ll_enqueue_asset_by_timestamp('css/recording-history.css', 'll-recording-history');
    ll_enqueue_asset_by_timestamp('js/recording-history.js', 'll-recording-history', [], true);
    wp_localize_script('ll-recording-history', 'llRecordingHistory', [
        'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ll_tools_recording_history'),
        'locale' => get_locale(), 'requestTimeoutMs' => 15000,
        'recorderUrl' => remove_query_arg(['ll_record_word', 'll_record_category'], ll_tools_get_current_request_url()),
        'strings' => [
            'loading' => __('Loading recordings…', 'll-tools-text-domain'),
            'empty' => __('No recordings to show.', 'll-tools-text-domain'),
            'error' => __('Recordings could not be loaded. Please try again.', 'll-tools-text-domain'),
            'playbackError' => __('This recording could not be played.', 'll-tools-text-domain'),
            'unavailable' => __('Audio unavailable', 'll-tools-text-domain'),
            'playback' => __('Play recording', 'll-tools-text-domain'),
        ],
    ]);
}

function ll_tools_recording_history_render(int $wordset_id): string {
    if (is_wp_error(ll_tools_recording_history_scope($wordset_id))) { return ''; }
    ll_tools_recording_history_enqueue();
    ob_start();
    ?>
    <details class="ll-recording-history" data-ll-recording-history data-wordset-id="<?php echo esc_attr((string) $wordset_id); ?>">
        <summary class="ll-recording-history__toggle">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M3 11a9 9 0 1 1 2.6 7M3 5v6h6M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php esc_html_e('My recordings', 'll-tools-text-domain'); ?>
        </summary>
        <div class="ll-recording-history__body" data-history-body aria-busy="false">
            <p class="ll-recording-history__hint"><?php esc_html_e('Recordings credited to you in this word set.', 'll-tools-text-domain'); ?></p>
            <p class="ll-recording-history__message" data-history-message role="status" aria-live="polite"></p>
            <button class="ll-recording-history__button" type="button" data-history-retry hidden><?php esc_html_e('Retry', 'll-tools-text-domain'); ?></button>
            <ul class="ll-recording-history__list" data-history-list></ul>
            <nav class="ll-recording-history__nav" aria-label="<?php esc_attr_e('Recording history pages', 'll-tools-text-domain'); ?>" data-history-nav hidden>
                <button class="ll-recording-history__button" type="button" data-history-previous disabled><?php esc_html_e('Newer', 'll-tools-text-domain'); ?></button>
                <button class="ll-recording-history__button" type="button" data-history-next disabled><?php esc_html_e('Older', 'll-tools-text-domain'); ?></button>
                <button class="ll-recording-history__button ll-recording-history__refresh" type="button" data-history-refresh><?php esc_html_e('Refresh', 'll-tools-text-domain'); ?></button>
            </nav>
        </div>
    </details>
    <?php
    return (string) ob_get_clean();
}
