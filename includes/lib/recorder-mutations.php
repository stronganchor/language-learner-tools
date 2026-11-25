<?php
if (!defined('WPINC')) { die; }

/** Connection-owned locks do not expire underneath a running mutation. */
function ll_tools_recorder_mutation_lock(string $scope): string {
    global $wpdb;
    $name = 'll_recorder_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->options . ':' . $scope), 0, 48);
    $wpdb->last_error = '';
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1'
        || (string) $wpdb->last_error !== '') {
        return '';
    }
    // wp_send_json exits in production; finally alone cannot release an exit.
    register_shutdown_function('ll_tools_recorder_mutation_unlock', $name);
    return $name;
}

function ll_tools_recorder_mutation_unlock(string $name): void {
    global $wpdb;
    if ($name !== '') {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}

function ll_tools_recorder_protocol_error(string $code, string $message, int $status = 503): WP_Error {
    return new WP_Error($code, $message, [
        'status' => $status,
        'retryable' => !in_array($code, [
            'recorder_preparation_expired', 'recorder_preparation_mismatch',
            'recorder_preparation_incomplete', 'recorder_preparation_unavailable',
            'recorder_receipt_invalid',
        ], true),
    ]);
}

function ll_tools_recorder_send_protocol_error(WP_Error $error): void {
    $data = (array) $error->get_error_data();
    wp_send_json_error([
        'code' => $error->get_error_code(),
        'message' => $error->get_error_message(),
    ] + $data, (int) ($data['status'] ?? 503));
}

/** A fixed, explicit allowlist excludes transport nonces and UI locale. */
function ll_tools_recorder_prepare_request_hash(array $request): string {
    $payload = [];
    foreach (['word_text_target', 'word_text_translation', 'category', 'create_category',
        'new_category_name', 'new_category_types', 'wordset_ids', 'wordset_id', 'wordset',
        'include_types', 'exclude_types'] as $key) {
        $payload[$key] = $request[$key] ?? null;
    }
    return hash('sha256', (string) wp_json_encode($payload));
}

/**
 * Receipts live in the database even with Redis/Memcached: eviction must never
 * turn a replay into a fresh insert. Expiry rows join bounded LL Tools cleanup.
 */
function ll_tools_recorder_prepare_receipt_read(string $key) {
    global $wpdb;
    $wpdb->last_error = '';
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key
    ));
    if ((string) $wpdb->last_error !== '') {
        return ll_tools_recorder_protocol_error('recorder_receipt_unavailable', __('Failed to prepare new word', 'll-tools-text-domain'));
    }
    if ($value === null) {
        return null;
    }
    $receipt = maybe_unserialize($value);
    if (!is_array($receipt) || !isset($receipt['state'], $receipt['request_hash'])) {
        return ll_tools_recorder_protocol_error('recorder_receipt_invalid', __('Failed to prepare new word', 'll-tools-text-domain'));
    }
    return $receipt;
}

function ll_tools_recorder_prepare_receipt_write(string $key, array $receipt, ?array $previous): bool {
    global $wpdb;
    $serialized = maybe_serialize($receipt);
    if ($previous === null) {
        // Expiry precedes the claim; a failed claim can never create a word.
        $timeout = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            '_transient_timeout_' . $key, (string) $receipt['expires']
        ));
        if ($timeout === false) {
            return false;
        }
        $written = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            '_transient_' . $key, $serialized
        ));
    } else {
        $written = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $serialized, '_transient_' . $key, maybe_serialize($previous)
        ));
    }
    return ($previous === null ? $written === 1 : $written !== false)
        && ll_tools_recorder_prepare_receipt_read($key) === $receipt;
}

/** @return array|WP_Error Empty array means an older, non-replayable client. */
function ll_tools_recorder_prepare_begin(array $request) {
    $id = $request['preparation_id'] ?? '';
    if ($id === '') {
        return [];
    }
    if (!is_string($id) || !preg_match('/^([0-9]{10})-[a-f0-9]{32}$/D', $id, $match)
        || (int) $match[1] < time() - 7 * DAY_IN_SECONDS || (int) $match[1] > time() + 15 * MINUTE_IN_SECONDS) {
        return ll_tools_recorder_protocol_error('recorder_preparation_expired',
            __('This recording session has expired. Keep your recording and check for an existing draft before starting again.', 'll-tools-text-domain'), 409);
    }
    $key = 'll_recorder_prepare_' . hash('sha256', get_current_user_id() . ':' . $id);
    $lock = ll_tools_recorder_mutation_lock('prepare:' . $key);
    if ($lock === '') {
        return ll_tools_recorder_protocol_error('recorder_preparation_busy',
            __('New word preparation is still running. Please try again shortly.', 'll-tools-text-domain'), 409);
    }
    $receipt = ll_tools_recorder_prepare_receipt_read($key);
    $hash = ll_tools_recorder_prepare_request_hash($request);
    $context = ['key' => $key, 'lock' => $lock, 'receipt' => $receipt];
    if (is_wp_error($receipt)) {
        ll_tools_recorder_mutation_unlock($lock);
        return $receipt;
    }
    if ($receipt !== null) {
        if (!hash_equals((string) $receipt['request_hash'], $hash)) {
            ll_tools_recorder_mutation_unlock($lock);
            return ll_tools_recorder_protocol_error('recorder_preparation_mismatch',
                __('The original new word request must be checked before changing it.', 'll-tools-text-domain'), 409);
        }
        if (($receipt['state'] ?? '') !== 'complete' || !is_array($receipt['response'] ?? null)) {
            ll_tools_recorder_mutation_unlock($lock);
            return ll_tools_recorder_protocol_error('recorder_preparation_incomplete',
                __('New word creation could not be confirmed. Check for an existing draft before recording this word again.', 'll-tools-text-domain'), 409);
        }
        $word_id = (int) ($receipt['response']['word']['word_id'] ?? 0);
        $word = get_post($word_id);
        if (!$word || $word->post_type !== 'words' || $word->post_status === 'trash'
            || (!current_user_can('edit_post', $word_id) && (int) $word->post_author !== get_current_user_id())
            || (function_exists('ll_tools_user_can_view_vocab_post') && !ll_tools_user_can_view_vocab_post($word_id, get_current_user_id()))) {
            ll_tools_recorder_mutation_unlock($lock);
            return ll_tools_recorder_protocol_error('recorder_preparation_unavailable', __('Invalid word ID.', 'll-tools-text-domain'), 409);
        }
        return $context + ['response' => $receipt['response']];
    }
    $receipt = ['state' => 'pending', 'request_hash' => $hash, 'expires' => (int) $match[1] + 8 * DAY_IN_SECONDS];
    if (!ll_tools_recorder_prepare_receipt_write($key, $receipt, null)) {
        ll_tools_recorder_mutation_unlock($lock);
        return ll_tools_recorder_protocol_error('recorder_receipt_unavailable', __('Failed to prepare new word', 'll-tools-text-domain'));
    }
    $context['receipt'] = $receipt;
    return $context;
}

/**
 * Detect a no-op only when the canonical map and any legacy mirror are current.
 */
function ll_tools_recorder_default_translation_matches_storage(int $word_id, string $text): bool {
    $locale = ll_tools_get_default_translation_locale_for_word($word_id);
    if ($locale !== '') {
        $stored = get_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, true);
        $translations = ll_tools_normalize_word_translation_map($stored);
        if (($translations === [] ? metadata_exists('post', $word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY) : $stored !== $translations)
            || ($translations[$locale] ?? '') !== $text) {
            return false;
        }
    }

    if (!ll_tools_word_translation_meta_stores_default_translation($word_id)) {
        return true;
    }
    if ($text === '') {
        return !metadata_exists('post', $word_id, 'word_translation');
    }
    return get_post_meta($word_id, 'word_translation', true) === $text;
}

/** Confirm both canonical text and the revision while holding the word lock. */
function ll_tools_recorder_word_text_snapshot(int $word_id) {
    global $wpdb;
    clean_post_cache($word_id);
    $wpdb->last_error = '';
    $post = get_post($word_id);
    if ((string) $wpdb->last_error !== '' || !$post || $post->post_type !== 'words' || $post->post_status === 'trash') {
        return ll_tools_recorder_protocol_error('recorder_word_unavailable', __('Invalid word ID.', 'll-tools-text-domain'), 409);
    }
    if (!current_user_can('edit_post', $word_id) && (int) $post->post_author !== get_current_user_id()) {
        return ll_tools_recorder_protocol_error('recorder_word_forbidden', __('Forbidden.', 'll-tools-text-domain'), 403);
    }
    $wpdb->last_error = '';
    update_meta_cache('post', [$word_id]);
    if ((string) $wpdb->last_error !== '') {
        wp_cache_delete($word_id, 'post_meta');
        return ll_tools_recorder_protocol_error('recorder_text_unavailable', __('Failed to update word text', 'll-tools-text-domain'));
    }
    $complete = true;
    $viewable = !function_exists('ll_tools_user_can_view_vocab_post')
        || ll_tools_user_can_view_vocab_post($word_id, get_current_user_id(), $complete);
    if (!$complete) {
        return ll_tools_recorder_protocol_error('recorder_text_unavailable', __('Failed to update word text', 'll-tools-text-domain'));
    }
    if (!$viewable) {
        return ll_tools_recorder_protocol_error('recorder_word_forbidden', __('Forbidden.', 'll-tools-text-domain'), 403);
    }
    $wpdb->last_error = '';
    $display = ll_tools_word_grid_resolve_display_text($word_id);
    if ((string) $wpdb->last_error !== '') {
        return ll_tools_recorder_protocol_error('recorder_text_unavailable', __('Failed to update word text', 'll-tools-text-domain'));
    }
    $snapshot = [
        'word_id' => $word_id,
        'word_text_target' => (string) ($display['word_text'] ?? ''),
        'word_translation' => (string) ($display['translation_text'] ?? ''),
        'post_title' => (string) get_the_title($word_id),
        'store_in_title' => (bool) ($display['store_in_title'] ?? true),
    ];
    $snapshot['text_revision'] = hash('sha256', (string) wp_json_encode($snapshot));
    return $snapshot;
}

add_action('wp_ajax_ll_get_new_word_text', 'll_get_new_word_text_handler');
function ll_get_new_word_text_handler(): void {
    check_ajax_referer('ll_upload_recording', 'nonce');
    ll_tools_recorder_apply_ajax_locale();
    if (!is_user_logged_in() || !ll_tools_user_can_record()) {
        wp_send_json_error(__('Forbidden.', 'll-tools-text-domain'), 403);
    }
    $word_id = (int) ($_POST['word_id'] ?? 0);
    $lock = $word_id > 0 ? ll_tools_recorder_mutation_lock('word:' . $word_id) : '';
    if ($lock === '') {
        ll_tools_recorder_send_protocol_error(ll_tools_recorder_protocol_error('recorder_text_busy', __('Failed to update word text', 'll-tools-text-domain')));
    }
    try {
        $snapshot = ll_tools_recorder_word_text_snapshot($word_id);
        if (is_wp_error($snapshot)) {
            ll_tools_recorder_send_protocol_error($snapshot);
        }
        wp_send_json_success($snapshot);
    } finally {
        ll_tools_recorder_mutation_unlock($lock);
    }
}
