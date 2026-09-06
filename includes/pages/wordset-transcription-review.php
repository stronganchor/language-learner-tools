<?php
/** Scoped frontend recording review. Provider configuration remains a separate tool. */
if (!defined('WPINC')) { die; }

function ll_tools_wordset_transcription_review_can_access(int $wordset_id, ?bool &$complete = null): bool {
    $complete = true;
    return $wordset_id > 0 && term_exists($wordset_id, 'wordset')
        && current_user_can('view_ll_tools')
        && ll_tools_user_can_view_wordset($wordset_id, get_current_user_id(), $complete) && $complete
        && ll_tools_current_user_can_manage_wordset_content($wordset_id)
        && ll_tools_user_can_edit_vocab_words($wordset_id);
}

function ll_tools_wordset_transcription_review_load_runtime(): void {
    require_once LL_TOOLS_BASE_PATH . 'includes/admin/ipa-keyboard-admin.php';
    require_once LL_TOOLS_BASE_PATH . 'includes/lib/mutation-job-state.php';
}

/** Check the selected scope and both objects before resolving any recording media. */
function ll_tools_wordset_transcription_review_can_access_recording(int $recording_id, int $wordset_id, ?bool &$complete = null): bool {
    global $wpdb;
    $complete = true;
    if (!ll_tools_wordset_transcription_review_can_access($wordset_id, $complete)) {
        return false;
    }
    $wpdb->last_error = '';
    $recording = get_post($recording_id);
    $word = $recording instanceof WP_Post ? get_post((int) $recording->post_parent) : null;
    if ($wpdb->last_error !== '') { $complete = false; return false; }
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio'
        || !($word instanceof WP_Post) || $word->post_type !== 'words'
        || !ll_tools_word_grid_user_can_manage_word($word->ID, $wordset_id)) {
        return false;
    }
    $wpdb->last_error = '';
    $sets = wp_get_object_terms($word->ID, 'wordset', ['fields' => 'ids', 'suppress_filter' => true]);
    if (is_wp_error($sets) || $wpdb->last_error !== '') { $complete = false; return false; }
    if (!in_array($wordset_id, array_map('intval', $sets), true)) { return false; }
    $wpdb->last_error = '';
    $categories = wp_get_object_terms($word->ID, 'word-category', ['suppress_filter' => true]);
    if (is_wp_error($categories) || $wpdb->last_error !== '') { $complete = false; return false; }
    $in_scope = !$categories;
    foreach ($categories as $category) {
        if (!($category instanceof WP_Term)) { $complete = false; return false; }
        $complete = true;
        $visible = ll_tools_user_can_view_category($category, get_current_user_id(), $complete);
        if (!$complete) { return false; }
        $owner = ll_tools_get_category_wordset_owner_id((int) $category->term_id, $complete);
        if (!$complete) { return false; }
        if ($visible && ($owner <= 0 || $owner === $wordset_id)) { $in_scope = true; }
    }
    if (!$in_scope) { return false; }
    foreach ([$recording, $word] as $object) {
        if (!in_array($object->post_status, ['publish', 'draft', 'pending', 'private', 'future'], true)
            || !current_user_can('read_post', $object->ID)
            || ($object->post_status !== 'publish' && !current_user_can('edit_post', $object->ID))
            || ($object->post_password !== '' && !current_user_can('edit_post', $object->ID))) {
            return false;
        }
    }
    return true;
}

function ll_tools_wordset_transcription_review_revision(string $field, $value): string {
    return hash('sha256', $field . '|' . wp_json_encode($value));
}

function ll_tools_wordset_transcription_review_row(int $recording_id, int $wordset_id, ?bool &$complete = null): array {
    if (!ll_tools_wordset_transcription_review_can_access_recording($recording_id, $wordset_id, $complete)) {
        return [];
    }
    $word_id = (int) wp_get_post_parent_id($recording_id);
    $display = ll_tools_ipa_keyboard_get_word_display_map([$word_id]);
    $values = [
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_ipa' => (string) get_post_meta($recording_id, 'recording_ipa', true),
        'review_note' => ll_tools_ipa_keyboard_get_recording_review_note($recording_id),
        'review_fields' => ll_tools_ipa_keyboard_get_recording_review_fields($recording_id),
    ];
    $revisions = [];
    foreach ($values as $field => $value) {
        $revisions[$field] = ll_tools_wordset_transcription_review_revision($field,
            $field === 'review_fields' ? [$value, $values['review_note']] : $value);
    }
    $types = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'names']);
    return array_merge($values, [
        'recording_id' => $recording_id,
        'word_id' => $word_id,
        'word_text' => (string) ($display[$word_id]['word_text'] ?? ''),
        'word_translation' => (string) ($display[$word_id]['translation'] ?? ''),
        'recording_type' => is_wp_error($types) ? '' : implode(', ', $types),
        'audio_url' => ll_tools_word_grid_get_recording_audio_url($recording_id),
        'needs_review' => !empty(array_filter($values['review_fields'])),
        'revisions' => $revisions,
    ]);
}

/** Narrow in SQL before bounded hydration; the cursor also handles unreadable objects. */
function ll_tools_wordset_transcription_review_list(int $wordset_id, string $query = '', int $after_id = 0, bool $review_only = false) {
    global $wpdb;
    $complete = true;
    if (!ll_tools_wordset_transcription_review_can_access($wordset_id, $complete)) {
        return ll_tools_wordset_transcription_review_access_error($complete);
    }
    if (strlen($query) > 160 || $after_id < 0) {
        return new WP_Error('invalid_request', __('Invalid request.', 'll-tools-text-domain'), ['status' => 400]);
    }
    ll_tools_wordset_transcription_review_load_runtime();
    $limit = 75;
    $search_sql = '';
    if ($query !== '') {
        $like = '%' . $wpdb->esc_like($query) . '%';
        $search_sql = $wpdb->prepare(" AND (w.post_title LIKE %s
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} wm WHERE wm.post_id = w.ID
                AND wm.meta_key IN ('word_translation','word_english_meaning') AND wm.meta_value LIKE %s)
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id = a.ID
                AND am.meta_key IN ('recording_text','recording_ipa','recording_translation') AND am.meta_value LIKE %s))", $like, $like, $like);
    }
    if ($review_only) {
        $search_sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} review_pm WHERE review_pm.post_id = a.ID
            AND review_pm.meta_key = %s AND review_pm.meta_value = '1')", ll_tools_ipa_keyboard_auto_review_meta_key());
    }
    $sql = "SELECT a.ID FROM {$wpdb->posts} a INNER JOIN {$wpdb->posts} w ON w.ID = a.post_parent
        WHERE a.post_type = 'word_audio' AND w.post_type = 'words'
        AND a.post_status IN ('publish','draft','pending','private','future')
        AND w.post_status IN ('publish','draft','pending','private','future') AND a.ID > %d
        AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = w.ID AND tt.taxonomy = 'wordset' AND tt.term_id = %d)"
        . $search_sql . "
        ORDER BY a.ID ASC LIMIT %d";
    $ids = $wpdb->get_col($wpdb->prepare($sql, $after_id, $wordset_id, $limit + 1));
    if ($wpdb->last_error !== '') {
        return new WP_Error('read_failed', __('Could not load recordings.', 'll-tools-text-domain'), ['status' => 503]);
    }
    $has_more = count($ids) > $limit;
    $ids = array_slice($ids, 0, $limit);
    $rows = [];
    $cursor = $after_id;
    foreach ($ids as $index => $id) {
        $cursor = (int) $id;
        $complete = true;
        $row = ll_tools_wordset_transcription_review_row($cursor, $wordset_id, $complete);
        if (!$complete) { return ll_tools_wordset_transcription_review_access_error(false); }
        if (!$row || ($review_only && !$row['needs_review'])) {
            continue;
        }
        $rows[] = $row;
        if (count($rows) >= 20) {
            $has_more = $has_more || $index < count($ids) - 1;
            break;
        }
    }
    return ['recordings' => $rows, 'next_cursor' => $cursor, 'has_more' => $has_more];
}

/** One field per request; a stale/uncertain edit never silently replaces newer text. */
function ll_tools_wordset_transcription_review_save(int $wordset_id, int $recording_id, string $field, string $value, string $expected) {
    global $wpdb;
    $complete = true;
    if (!ll_tools_wordset_transcription_review_can_access_recording($recording_id, $wordset_id, $complete)) {
        return ll_tools_wordset_transcription_review_access_error($complete);
    }
    if (!in_array($field, ['recording_text', 'recording_ipa', 'review_note', 'review_fields'], true)
        || strlen($value) > 8000 || !preg_match('/^[a-f0-9]{64}$/D', $expected)
        || ($field === 'review_fields' && $value !== 'reviewed')) {
        return new WP_Error('invalid_request', __('Invalid request.', 'll-tools-text-domain'), ['status' => 400]);
    }
    ll_tools_wordset_transcription_review_load_runtime();
    $scope = ll_tools_recording_write_acquire($recording_id);
    if (is_wp_error($scope)) {
        return $scope;
    }
    $did_mutate = false;
    try {
        // Shared legacy/frontend write admission covers existing and absent metadata on every engine.
        $snapshot = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE post_id = %d ORDER BY meta_id ASC LIMIT 1001", $recording_id), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($snapshot) || count($snapshot) > 1000) {
            return new WP_Error('save_unverified', __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
        }
        $meta = [];
        foreach ($snapshot as $item) { $meta[$item['meta_key']][] = $item['meta_value']; }
        foreach (['recording_text', 'recording_ipa', ll_tools_ipa_keyboard_review_note_meta_key(), ll_tools_ipa_keyboard_review_fields_meta_key(), ll_tools_ipa_keyboard_auto_review_meta_key()] as $key) {
            if (count($meta[$key] ?? []) > 1) {
                return new WP_Error('save_unverified', __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
            }
        }
        clean_post_cache($recording_id);
        wp_cache_set($recording_id, $meta, 'post_meta');
        $before = ll_tools_wordset_transcription_review_row($recording_id, $wordset_id, $complete);
        if (!$before) {
            return ll_tools_wordset_transcription_review_access_error($complete);
        }
        if (!hash_equals($before['revisions'][$field], $expected)) {
            return new WP_Error('edit_conflict', __('This recording changed. Reload it before editing again.', 'll-tools-text-domain'), ['status' => 409]);
        }
        if ($field === 'review_note') {
            $wanted = trim(sanitize_textarea_field($value));
            $did_mutate = true;
            ll_tools_ipa_keyboard_set_recording_review_note($recording_id, wp_slash($wanted));
        } elseif ($field === 'review_fields') {
            $did_mutate = true;
            foreach (array_keys(array_filter($before['review_fields'])) as $review_field) {
                ll_tools_ipa_keyboard_set_recording_review_state($recording_id, false, $review_field);
            }
            $wanted = [];
        } else {
            $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
            $wanted = $field === 'recording_ipa'
                ? ll_tools_word_grid_sanitize_ipa($value, $mode)
                : ll_tools_word_grid_sanitize_non_ipa_text($value);
            $did_mutate = true;
            ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [$field => $value]);
        }
        wp_cache_delete($recording_id, 'post_meta');
        $after = ll_tools_wordset_transcription_review_row($recording_id, $wordset_id);
        $stored = $field === 'review_fields' ? array_filter($after[$field] ?? [true]) : ($after[$field] ?? null);
        if (!$after || $stored !== $wanted || ll_tools_recording_write_error($recording_id) || !ll_tools_mutation_job_owns($scope['lease'])) {
            return new WP_Error('save_unverified', __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'), ['status' => 503]);
        }
        return ['recording' => $after];
    } finally {
        wp_cache_delete($recording_id, 'post_meta');
        if ($did_mutate && ll_tools_recording_write_error($recording_id)) {
            wp_cache_delete($wordset_id, 'term_meta');
            ll_tools_ipa_keyboard_mark_aggregates_stale($wordset_id);
        }
        ll_tools_recording_write_release($scope);
    }
}

function ll_tools_wordset_transcription_review_ajax(): void {
    check_ajax_referer('ll_wordset_transcription_review', 'nonce');
    foreach (['wordset_id', 'recording_id', 'query', 'cursor', 'field', 'value', 'expected', 'review_only'] as $key) {
        if (isset($_POST[$key]) && !is_scalar($_POST[$key])) {
            wp_send_json_error(['message' => __('Invalid request.', 'll-tools-text-domain')], 400);
        }
    }
    if (function_exists('ll_tools_recorder_apply_ajax_locale')) { ll_tools_recorder_apply_ajax_locale(); }
    $wordset_id = absint($_POST['wordset_id'] ?? 0);
    $result = ($_POST['action'] ?? '') === 'll_tools_save_wordset_transcription_review'
        ? ll_tools_wordset_transcription_review_save($wordset_id, absint($_POST['recording_id'] ?? 0), (string) ($_POST['field'] ?? ''), wp_unslash((string) ($_POST['value'] ?? '')), (string) ($_POST['expected'] ?? ''))
        : (absint($_POST['recording_id'] ?? 0) > 0
            ? ll_tools_wordset_transcription_review_get($wordset_id, absint($_POST['recording_id']))
            : ll_tools_wordset_transcription_review_list($wordset_id, trim(wp_unslash((string) ($_POST['query'] ?? ''))), (int) ($_POST['cursor'] ?? 0), !empty($_POST['review_only'])));
    if (is_wp_error($result)) {
        wp_send_json_error(['code' => $result->get_error_code(), 'message' => $result->get_error_message()], (int) ($result->get_error_data()['status'] ?? 500));
    }
    nocache_headers();
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_get_wordset_transcription_review', 'll_tools_wordset_transcription_review_ajax');
add_action('wp_ajax_ll_tools_save_wordset_transcription_review', 'll_tools_wordset_transcription_review_ajax');

function ll_tools_wordset_transcription_review_get(int $wordset_id, int $recording_id) {
    $complete = true;
    if (!ll_tools_wordset_transcription_review_can_access_recording($recording_id, $wordset_id, $complete)) {
        return ll_tools_wordset_transcription_review_access_error($complete);
    }
    ll_tools_wordset_transcription_review_load_runtime();
    $row = ll_tools_wordset_transcription_review_row($recording_id, $wordset_id, $complete);
    return $row ? ['recording' => $row] : ll_tools_wordset_transcription_review_access_error($complete);
}

function ll_tools_wordset_transcription_review_access_error(bool $complete): WP_Error {
    return $complete
        ? new WP_Error('forbidden', __('Forbidden', 'll-tools-text-domain'), ['status' => 403])
        : new WP_Error('read_failed', __('Could not load recordings.', 'll-tools-text-domain'), ['status' => 503]);
}

function ll_tools_enqueue_wordset_transcription_review_assets(int $wordset_id): void {
    if (!ll_tools_wordset_transcription_review_can_access($wordset_id)) { return; }
    ll_tools_wordset_transcription_review_load_runtime();
    ll_enqueue_asset_by_timestamp('css/wordset-transcription-review.css', 'll-wordset-transcription-review');
    ll_enqueue_asset_by_timestamp('js/wordset-transcription-review.js', 'll-wordset-transcription-review', [], true);
    $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id, true);
    wp_localize_script('ll-wordset-transcription-review', 'llWordsetTranscriptionReview', [
        'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ll_wordset_transcription_review'), 'locale' => get_locale(),
        'wordsetId' => $wordset_id, 'ipaLabel' => (string) ($config['label'] ?? __('IPA', 'll-tools-text-domain')),
        'symbols' => array_values((array) ($config['keyboard_symbols'] ?? [])),
        'messages' => [
            'loading' => __('Loading…', 'll-tools-text-domain'), 'saving' => __('Saving…', 'll-tools-text-domain'),
            'saved' => __('Saved', 'll-tools-text-domain'), 'empty' => __('No recordings found on this page.', 'll-tools-text-domain'),
            'more' => __('More recordings remain. Continue to the next page.', 'll-tools-text-domain'),
            'error' => __('Could not load recordings.', 'll-tools-text-domain'),
            'saveError' => __('The save could not be verified. Reload this recording before editing again.', 'll-tools-text-domain'),
            'pending' => __('Finish saving or reload the changed recordings before leaving this page.', 'll-tools-text-domain'),
            'recordingText' => __('Recording text', 'll-tools-text-domain'), 'note' => __('Review note', 'll-tools-text-domain'),
            'review' => __('Needs review', 'll-tools-text-domain'), 'reviewed' => __('Mark reviewed', 'll-tools-text-domain'),
            'reload' => __('Reload', 'll-tools-text-domain'), 'audio' => __('Audio Recording', 'll-tools-text-domain'),
        ],
    ]);
}

function ll_tools_render_wordset_transcription_review(int $wordset_id): string {
    if (!ll_tools_wordset_transcription_review_can_access($wordset_id)) { return ''; }
    ob_start(); ?>
    <section class="ll-transcription-review" data-ll-transcription-review>
        <form class="ll-transcription-review__search" data-review-search>
            <label><?php esc_html_e('Search recordings', 'll-tools-text-domain'); ?><input type="search" name="query" maxlength="160" autocomplete="off"></label>
            <label class="ll-transcription-review__check"><input type="checkbox" name="review_only"> <?php esc_html_e('Needs review', 'll-tools-text-domain'); ?></label>
            <button class="ll-transcription-review__button" type="submit"><?php esc_html_e('Search', 'll-tools-text-domain'); ?></button>
        </form>
        <p class="ll-transcription-review__status" data-review-status role="status" aria-live="polite"></p>
        <div class="ll-transcription-review__rows" data-review-rows></div>
        <nav class="ll-transcription-review__pages" aria-label="<?php esc_attr_e('Recordings', 'll-tools-text-domain'); ?>">
            <button class="ll-transcription-review__button" type="button" data-review-previous disabled><?php esc_html_e('Previous', 'll-tools-text-domain'); ?></button>
            <button class="ll-transcription-review__button" type="button" data-review-next disabled><?php esc_html_e('Next', 'll-tools-text-domain'); ?></button>
        </nav>
    </section>
    <?php return (string) ob_get_clean();
}
