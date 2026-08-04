<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION')) {
    define('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION', 'll_tools_example_sentence_migration_done');
}

if (!defined('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION')) {
    define('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION', 'll_tools_example_sentence_migration_state');
}

if (!defined('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK')) {
    define('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK', 'll_tools_example_sentence_migration_batch');
}

if (!defined('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT')) {
    define('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT', 'll_tools_example_sentence_migration_lock');
}

if (!defined('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HARD_BATCH_LIMIT')) {
    define('LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HARD_BATCH_LIMIT', 250);
}

function ll_tools_example_sentence_migration_batch_limit(): int {
    $limit = (int) apply_filters('ll_tools_example_sentence_migration_batch_limit', 50);
    return max(1, min(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HARD_BATCH_LIMIT, $limit));
}

function ll_tools_example_sentence_migration_default_state(): array {
    return [
        'schema' => 2,
        'status' => 'queued',
        'word_cursor' => 0,
        'active_word_id' => 0,
        'audio_cursor' => 0,
        'source_example' => '',
        'source_translation' => '',
        'source_example_meta_id' => 0,
        'source_translation_meta_id' => 0,
        'source_example_raw_hash' => '',
        'source_translation_raw_hash' => '',
        'active_had_intro_text' => false,
        'processed' => 0,
        'migrated' => 0,
        'skipped' => 0,
        'started_at' => time(),
        'updated_at' => time(),
        'completed_at' => 0,
        'last_error' => '',
    ];
}

function ll_tools_get_example_sentence_migration_state(): array {
    $raw = get_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION, []);
    $state = is_array($raw) ? array_merge(ll_tools_example_sentence_migration_default_state(), $raw) : ll_tools_example_sentence_migration_default_state();
    $state['schema'] = 2;
    $state['status'] = in_array((string) ($state['status'] ?? ''), ['queued', 'running', 'completed'], true)
        ? (string) $state['status']
        : 'queued';
    foreach (['word_cursor', 'active_word_id', 'audio_cursor', 'source_example_meta_id', 'source_translation_meta_id', 'processed', 'migrated', 'skipped', 'started_at', 'updated_at', 'completed_at'] as $key) {
        $state[$key] = max(0, (int) ($state[$key] ?? 0));
    }
    $state['source_example'] = sanitize_text_field((string) ($state['source_example'] ?? ''));
    $state['source_translation'] = sanitize_text_field((string) ($state['source_translation'] ?? ''));
    foreach (['source_example_raw_hash', 'source_translation_raw_hash'] as $hash_key) {
        $hash = strtolower(trim((string) ($state[$hash_key] ?? '')));
        $state[$hash_key] = preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }
    $state['active_had_intro_text'] = !empty($state['active_had_intro_text']);
    $state['last_error'] = sanitize_key((string) ($state['last_error'] ?? ''));
    if ($state['active_word_id'] > 0 && $state['active_word_id'] <= $state['word_cursor']) {
        $state['active_word_id'] = 0;
        $state['audio_cursor'] = 0;
        $state['source_example'] = '';
        $state['source_translation'] = '';
        $state['source_example_meta_id'] = 0;
        $state['source_translation_meta_id'] = 0;
        $state['source_example_raw_hash'] = '';
        $state['source_translation_raw_hash'] = '';
        $state['active_had_intro_text'] = false;
    }
    return $state;
}

function ll_tools_save_example_sentence_migration_state(array $state): void {
    $state['updated_at'] = time();
    update_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION, $state, false);
}

function ll_tools_schedule_example_sentence_migration(int $delay_seconds = 5): void {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
        return;
    }
    if (!wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK)) {
        wp_schedule_single_event(
            time() + max(1, $delay_seconds),
            LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK
        );
    }
}

function ll_tools_clear_example_sentence_migration_schedule(): void {
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK);
    }
    delete_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT);
}

function ll_tools_example_sentence_migration_lock_name(): string {
    global $wpdb;

    // MySQL advisory locks are server-wide. Scope the lock to both the
    // database and this site's exact options table without exposing either
    // identifier in the process-visible lock name.
    $database_name = defined('DB_NAME') ? (string) DB_NAME : '';
    $scope = $database_name . '|' . (string) $wpdb->options;
    return 'll_tools_example_migration_' . substr(hash('sha256', $scope), 0, 32);
}

function ll_tools_acquire_example_sentence_migration_lock(): string {
    global $wpdb;

    $lock_name = ll_tools_example_sentence_migration_lock_name();
    $wpdb->last_error = '';
    $acquired = $wpdb->get_var(
        $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0)
    );
    if ($wpdb->last_error !== '' || (int) $acquired !== 1) {
        return '';
    }

    return $lock_name;
}

function ll_tools_release_example_sentence_migration_lock(string $lock_name): void {
    global $wpdb;

    if ($lock_name !== '') {
        // Advisory locks are connection-owned. This exact connection can
        // release only the acquisition represented by the returned name.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }
}

/**
 * @param bool|null $source_complete Set false when the database read fails.
 * @param bool|null $has_more Set true when another candidate exists after this page.
 */
function ll_tools_get_words_with_example_sentence_meta(
    int $limit = 50,
    int $after_word_id = 0,
    ?bool &$source_complete = null,
    ?bool &$has_more = null
): array {
    global $wpdb;

    $source_complete = true;
    $has_more = false;
    $limit = max(1, min(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HARD_BATCH_LIMIT, $limit));
    $after_word_id = max(0, $after_word_id);
    $statuses = ['publish', 'draft', 'pending', 'future', 'private'];
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT posts.ID
         FROM {$wpdb->posts} AS posts
         INNER JOIN {$wpdb->postmeta} AS postmeta ON postmeta.post_id = posts.ID
         WHERE posts.post_type = %s
           AND posts.post_status IN ({$status_placeholders})
           AND posts.ID > %d
           AND postmeta.meta_key IN (%s, %s)
         ORDER BY posts.ID ASC
         LIMIT %d",
        array_merge(
            ['words'],
            $statuses,
            [
                $after_word_id,
                'word_example_sentence',
                'word_example_sentence_translation',
                $limit + 1,
            ]
        )
    ));
    if ($wpdb->last_error !== '') {
        $source_complete = false;
        return [];
    }

    $ids = array_values(array_filter(array_map('intval', (array) $ids), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $has_more = count($ids) > $limit;
    return $has_more ? array_slice($ids, 0, $limit) : $ids;
}

/**
 * @param bool|null $source_complete Set false when the database read fails.
 * @param bool|null $has_more Set true when another recording exists after this page.
 */
function ll_tools_get_example_sentence_intro_recording_ids(
    int $word_id,
    int $after_audio_id,
    int $limit,
    ?bool &$source_complete = null,
    ?bool &$has_more = null
): array {
    global $wpdb;

    $source_complete = true;
    $has_more = false;
    $word_id = max(0, $word_id);
    $after_audio_id = max(0, $after_audio_id);
    $limit = max(1, min(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HARD_BATCH_LIMIT, $limit));
    if ($word_id <= 0) {
        return [];
    }

    $statuses = ['publish', 'draft', 'pending', 'private'];
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT posts.ID
         FROM {$wpdb->posts} AS posts
         INNER JOIN {$wpdb->term_relationships} AS relationships
            ON relationships.object_id = posts.ID
         INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
            ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
         INNER JOIN {$wpdb->terms} AS terms ON terms.term_id = taxonomy.term_id
         WHERE posts.post_type = %s
           AND posts.post_status IN ({$status_placeholders})
           AND posts.post_parent = %d
           AND posts.ID > %d
           AND taxonomy.taxonomy = %s
           AND terms.slug = %s
         ORDER BY posts.ID ASC
         LIMIT %d",
        array_merge(
            ['word_audio'],
            $statuses,
            [
                $word_id,
                $after_audio_id,
                'recording_type',
                'introduction',
                $limit + 1,
            ]
        )
    ));
    if ($wpdb->last_error !== '') {
        $source_complete = false;
        return [];
    }

    $ids = array_values(array_filter(array_map('intval', (array) $ids), static function (int $audio_id): bool {
        return $audio_id > 0;
    }));
    $has_more = count($ids) > $limit;
    return $has_more ? array_slice($ids, 0, $limit) : $ids;
}

/**
 * Decode a legacy text metadata value without instantiating serialized classes.
 *
 * @param bool|null $decode_complete Set false for arrays, objects, resources,
 *                                   or an invalid serialized representation.
 * @return scalar|null
 */
function ll_tools_decode_example_sentence_migration_meta_value(
    string $stored_value,
    ?bool &$decode_complete = null
) {
    $decode_complete = true;
    if (!is_serialized($stored_value)) {
        return $stored_value;
    }

    $serialized_value = trim($stored_value);
    $decoded_value = @unserialize($serialized_value, ['allowed_classes' => false]);
    if ($decoded_value === false && $serialized_value !== 'b:0;') {
        $decode_complete = false;
        return '';
    }
    if (!is_scalar($decoded_value) && $decoded_value !== null) {
        $decode_complete = false;
        return '';
    }

    return $decoded_value;
}

/**
 * Read one migration metadata value without trusting the post-meta object cache.
 *
 * Metadata cache hydration turns a failed database read into an empty cache entry,
 * which is indistinguishable from a genuinely absent value to get_post_meta(). The
 * migration must not advance a durable cursor on that ambiguous result.
 *
 * @param bool|null $read_complete Set false when the database read fails.
 * @param bool|null $exists Set true when the metadata row exists, including an empty value.
 * @param int|null $meta_id Exact metadata row ID used for compare-and-delete cleanup.
 * @param string|null $stored_value Exact serialized database representation of the value.
 */
function ll_tools_read_example_sentence_migration_meta(
    int $post_id,
    string $meta_key,
    ?bool &$read_complete = null,
    ?bool &$exists = null,
    ?int &$meta_id = null,
    ?string &$stored_value = null
): string {
    global $wpdb;

    $read_complete = true;
    $exists = false;
    $meta_id = 0;
    $stored_value = '';
    $post_id = max(0, $post_id);
    $meta_key = (string) $meta_key;
    if ($post_id <= 0 || $meta_key === '') {
        return '';
    }

    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT meta_id, meta_value
         FROM {$wpdb->postmeta}
         WHERE post_id = %d
           AND meta_key = %s
         ORDER BY meta_id ASC
         LIMIT 1",
        $post_id,
        $meta_key
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        $read_complete = false;
        // A metadata API call elsewhere may have cached the same failed read as
        // an empty result. Ensure the exact post is queried again on retry.
        wp_cache_delete($post_id, 'post_meta');
        return '';
    }
    if (!is_array($row) || !array_key_exists('meta_value', $row)) {
        return '';
    }

    $exists = true;
    $meta_id = max(0, (int) ($row['meta_id'] ?? 0));
    $stored_value = (string) $row['meta_value'];
    if ($meta_id <= 0) {
        $read_complete = false;
        return '';
    }
    $decode_complete = true;
    $decoded_value = ll_tools_decode_example_sentence_migration_meta_value($stored_value, $decode_complete);
    if (!$decode_complete) {
        // The legacy fields are text-only. Do not coerce an unexpected
        // serialized structure and later delete a representation we cannot
        // faithfully compare with the durable text snapshot.
        $read_complete = false;
        return '';
    }

    return (string) $decoded_value;
}

function ll_tools_example_sentence_migration_raw_hash(string $stored_value): string {
    return hash('sha256', $stored_value);
}

function ll_tools_example_sentence_migration_source_snapshot_matches(
    bool $exists,
    int $meta_id,
    string $stored_value,
    string $normalized_value,
    int $expected_meta_id,
    string $expected_raw_hash,
    string $expected_normalized_value
): bool {
    // A missing row is safe: it may be the completed half of an earlier
    // two-key cleanup. A row that still exists must match the exact durable
    // activation snapshot, not merely sanitize to the same display text.
    if (!$exists) {
        return true;
    }
    if (
        $meta_id <= 0
        || $expected_meta_id <= 0
        || $meta_id !== $expected_meta_id
        || !preg_match('/^[a-f0-9]{64}$/', $expected_raw_hash)
    ) {
        return false;
    }

    return hash_equals($expected_raw_hash, ll_tools_example_sentence_migration_raw_hash($stored_value))
        && $normalized_value === $expected_normalized_value;
}

/**
 * Delete only the exact metadata row/value representation that was just read.
 *
 * A normal delete_post_meta() call deletes by key and can remove a concurrent
 * edit that lands after the source comparison. This bounded CAS statement
 * leaves a changed row intact; the caller then re-reads and retries the active
 * word conservatively.
 *
 * @param bool|null $delete_complete Set false when the database delete fails.
 */
function ll_tools_compare_and_delete_example_sentence_migration_meta(
    int $post_id,
    string $meta_key,
    int $meta_id,
    string $stored_value,
    ?bool &$delete_complete = null
): bool {
    global $wpdb;

    $delete_complete = true;
    $post_id = max(0, $post_id);
    $meta_id = max(0, $meta_id);
    if ($post_id <= 0 || $meta_id <= 0 || $meta_key === '') {
        $delete_complete = false;
        return false;
    }

    $decode_complete = true;
    $decoded_value = ll_tools_decode_example_sentence_migration_meta_value($stored_value, $decode_complete);
    if (!$decode_complete) {
        $delete_complete = false;
        return false;
    }

    $check = apply_filters('delete_post_metadata', null, $post_id, $meta_key, $decoded_value, false);
    if ($check !== null) {
        if (!$check) {
            $delete_complete = false;
        }
        return (bool) $check;
    }

    do_action('delete_post_meta', [$meta_id], $post_id, $meta_key, $decoded_value);

    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta}
         WHERE meta_id = %d
           AND post_id = %d
           AND BINARY meta_key = BINARY %s
           AND BINARY meta_value = BINARY %s
         LIMIT 1",
        $meta_id,
        $post_id,
        $meta_key,
        $stored_value
    ));
    if ($wpdb->last_error !== '' || $deleted === false) {
        $delete_complete = false;
        return false;
    }
    if ((int) $deleted !== 1) {
        return false;
    }

    wp_cache_delete($post_id, 'post_meta');
    do_action('deleted_post_meta', [$meta_id], $post_id, $meta_key, $decoded_value);
    return true;
}

/**
 * @param string|null $failure_code Populated with a retryable failure code.
 */
function ll_tools_finalize_example_sentence_migration_word(array &$state, ?string &$failure_code = null): bool {
    $failure_code = '';
    $word_id = max(0, (int) ($state['active_word_id'] ?? 0));
    if ($word_id <= 0) {
        return true;
    }

    $example_read_complete = true;
    $example_exists = false;
    $example_meta_id = 0;
    $example_stored_value = '';
    $current_example = sanitize_text_field(ll_tools_read_example_sentence_migration_meta(
        $word_id,
        'word_example_sentence',
        $example_read_complete,
        $example_exists,
        $example_meta_id,
        $example_stored_value
    ));
    if (!$example_read_complete) {
        $failure_code = 'word_source_unavailable';
        return false;
    }

    $translation_read_complete = true;
    $translation_exists = false;
    $translation_meta_id = 0;
    $translation_stored_value = '';
    $current_translation = sanitize_text_field(ll_tools_read_example_sentence_migration_meta(
        $word_id,
        'word_example_sentence_translation',
        $translation_read_complete,
        $translation_exists,
        $translation_meta_id,
        $translation_stored_value
    ));
    if (!$translation_read_complete) {
        $failure_code = 'word_source_unavailable';
        return false;
    }

    // A missing row may be the successful half of an earlier two-key cleanup
    // whose second delete/readback failed. Treat it as already deleted so the
    // same active word can finish on retry, but never delete a changed value.
    $source_unchanged = ll_tools_example_sentence_migration_source_snapshot_matches(
        $example_exists,
        $example_meta_id,
        $example_stored_value,
        $current_example,
        (int) ($state['source_example_meta_id'] ?? 0),
        (string) ($state['source_example_raw_hash'] ?? ''),
        (string) ($state['source_example'] ?? '')
    ) && ll_tools_example_sentence_migration_source_snapshot_matches(
        $translation_exists,
        $translation_meta_id,
        $translation_stored_value,
        $current_translation,
        (int) ($state['source_translation_meta_id'] ?? 0),
        (string) ($state['source_translation_raw_hash'] ?? ''),
        (string) ($state['source_translation'] ?? '')
    );
    if (!empty($state['active_had_intro_text']) && $source_unchanged) {
        $source_keys = [
            'word_example_sentence' => [
                'exists' => $example_exists,
                'meta_id' => $example_meta_id,
                'stored_value' => $example_stored_value,
            ],
            'word_example_sentence_translation' => [
                'exists' => $translation_exists,
                'meta_id' => $translation_meta_id,
                'stored_value' => $translation_stored_value,
            ],
        ];
        foreach ($source_keys as $meta_key => $source_row) {
            if (empty($source_row['exists'])) {
                continue;
            }

            $delete_complete = true;
            ll_tools_compare_and_delete_example_sentence_migration_meta(
                $word_id,
                $meta_key,
                (int) ($source_row['meta_id'] ?? 0),
                (string) ($source_row['stored_value'] ?? ''),
                $delete_complete
            );

            $readback_complete = true;
            $still_exists = false;
            ll_tools_read_example_sentence_migration_meta(
                $word_id,
                $meta_key,
                $readback_complete,
                $still_exists
            );
            if (!$delete_complete || !$readback_complete || $still_exists) {
                $failure_code = 'word_cleanup_unavailable';
                return false;
            }
        }
        $state['migrated'] = (int) ($state['migrated'] ?? 0) + 1;
    } else {
        $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
    }

    $state['processed'] = (int) ($state['processed'] ?? 0) + 1;
    $state['word_cursor'] = max((int) ($state['word_cursor'] ?? 0), $word_id);
    $state['active_word_id'] = 0;
    $state['audio_cursor'] = 0;
    $state['source_example'] = '';
    $state['source_translation'] = '';
    $state['source_example_meta_id'] = 0;
    $state['source_translation_meta_id'] = 0;
    $state['source_example_raw_hash'] = '';
    $state['source_translation_raw_hash'] = '';
    $state['active_had_intro_text'] = false;
    return true;
}

/**
 * @param string|null $failure_code Populated with a retryable failure code.
 */
function ll_tools_process_active_example_sentence_migration_word(array &$state, int &$work_budget, ?string &$failure_code = null): bool {
    global $wpdb;

    $failure_code = '';
    $word_id = max(0, (int) ($state['active_word_id'] ?? 0));
    if ($word_id <= 0 || $work_budget <= 0) {
        return true;
    }

    $read_complete = true;
    $has_more = false;
    $audio_ids = ll_tools_get_example_sentence_intro_recording_ids(
        $word_id,
        (int) ($state['audio_cursor'] ?? 0),
        $work_budget,
        $read_complete,
        $has_more
    );
    if (!$read_complete) {
        $failure_code = 'recording_data_unavailable';
        return false;
    }

    foreach ($audio_ids as $audio_id) {
        $audio_id = (int) $audio_id;
        $text_read_complete = true;
        $text_exists = false;
        $existing_text = ll_tools_read_example_sentence_migration_meta(
            $audio_id,
            'recording_text',
            $text_read_complete,
            $text_exists
        );
        if (!$text_read_complete) {
            $failure_code = 'recording_data_unavailable';
            return false;
        }

        $translation_read_complete = true;
        $translation_exists = false;
        $existing_translation = ll_tools_read_example_sentence_migration_meta(
            $audio_id,
            'recording_translation',
            $translation_read_complete,
            $translation_exists
        );
        if (!$translation_read_complete) {
            $failure_code = 'recording_data_unavailable';
            return false;
        }

        if ($existing_text !== '' || $existing_translation !== '') {
            $state['active_had_intro_text'] = true;
        }
        if ((string) ($state['source_example'] ?? '') !== '' && $existing_text === '') {
            $wpdb->last_error = '';
            update_post_meta($audio_id, 'recording_text', (string) $state['source_example']);
            $write_error = $wpdb->last_error !== '';
            $readback_complete = true;
            $stored_text = ll_tools_read_example_sentence_migration_meta(
                $audio_id,
                'recording_text',
                $readback_complete,
                $text_exists
            );
            if ($write_error || !$readback_complete || $stored_text !== (string) $state['source_example']) {
                $failure_code = 'recording_data_unavailable';
                return false;
            }
            $state['active_had_intro_text'] = true;
        }
        if ((string) ($state['source_translation'] ?? '') !== '' && $existing_translation === '') {
            $wpdb->last_error = '';
            update_post_meta($audio_id, 'recording_translation', (string) $state['source_translation']);
            $write_error = $wpdb->last_error !== '';
            $readback_complete = true;
            $stored_translation = ll_tools_read_example_sentence_migration_meta(
                $audio_id,
                'recording_translation',
                $readback_complete,
                $translation_exists
            );
            if ($write_error || !$readback_complete || $stored_translation !== (string) $state['source_translation']) {
                $failure_code = 'recording_data_unavailable';
                return false;
            }
            $state['active_had_intro_text'] = true;
        }
        $state['audio_cursor'] = max((int) ($state['audio_cursor'] ?? 0), $audio_id);
        $work_budget--;
    }

    if (!$has_more) {
        if (!ll_tools_finalize_example_sentence_migration_word($state, $failure_code)) {
            return false;
        }
    }
    return true;
}

function ll_tools_complete_example_sentence_migration(array &$state): void {
    $state['status'] = 'completed';
    $state['completed_at'] = time();
    $state['last_error'] = '';
    update_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION, '1', false);
    ll_tools_save_example_sentence_migration_state($state);
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK);
    }
}

function ll_tools_run_example_sentence_migration_batch(): array {
    $advisory_lock_name = ll_tools_acquire_example_sentence_migration_lock();
    if ($advisory_lock_name === '') {
        ll_tools_schedule_example_sentence_migration(30);
        return ll_tools_get_example_sentence_migration_state();
    }

    $lock_token = wp_generate_uuid4();
    try {
        set_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT, $lock_token, 5 * MINUTE_IN_SECONDS);

        if (get_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION, false)) {
            $state = ll_tools_get_example_sentence_migration_state();
            $state['status'] = 'completed';
            return $state;
        }

        $state = ll_tools_get_example_sentence_migration_state();
        if ($state['status'] === 'completed') {
            ll_tools_complete_example_sentence_migration($state);
            return $state;
        }

        $state['status'] = 'running';
        $state['last_error'] = '';
        $work_budget = ll_tools_example_sentence_migration_batch_limit();

        if ((int) ($state['active_word_id'] ?? 0) > 0) {
            $failure_code = '';
            if (!ll_tools_process_active_example_sentence_migration_word($state, $work_budget, $failure_code)) {
                $state['status'] = 'queued';
                $state['last_error'] = $failure_code !== '' ? $failure_code : 'recording_data_unavailable';
                ll_tools_save_example_sentence_migration_state($state);
                ll_tools_schedule_example_sentence_migration(5 * MINUTE_IN_SECONDS);
                return $state;
            }
            if ((int) ($state['active_word_id'] ?? 0) > 0 || $work_budget <= 0) {
                $state['status'] = 'queued';
                ll_tools_save_example_sentence_migration_state($state);
                ll_tools_schedule_example_sentence_migration();
                return $state;
            }
        }

        $source_complete = true;
        $has_more_words = false;
        $word_ids = ll_tools_get_words_with_example_sentence_meta(
            $work_budget,
            (int) ($state['word_cursor'] ?? 0),
            $source_complete,
            $has_more_words
        );
        if (!$source_complete) {
            $state['status'] = 'queued';
            $state['last_error'] = 'word_source_unavailable';
            ll_tools_save_example_sentence_migration_state($state);
            ll_tools_schedule_example_sentence_migration(5 * MINUTE_IN_SECONDS);
            return $state;
        }

        $handled_words = 0;
        foreach ($word_ids as $word_id) {
            if ($work_budget <= 0) {
                break;
            }

            $word_id = (int) $word_id;
            $state['active_word_id'] = $word_id;
            $state['audio_cursor'] = 0;
            $example_read_complete = true;
            $example_exists = false;
            $example_meta_id = 0;
            $example_stored_value = '';
            $source_example = sanitize_text_field(ll_tools_read_example_sentence_migration_meta(
                $word_id,
                'word_example_sentence',
                $example_read_complete,
                $example_exists,
                $example_meta_id,
                $example_stored_value
            ));
            $translation_read_complete = true;
            $translation_exists = false;
            $translation_meta_id = 0;
            $translation_stored_value = '';
            $source_translation = sanitize_text_field(ll_tools_read_example_sentence_migration_meta(
                $word_id,
                'word_example_sentence_translation',
                $translation_read_complete,
                $translation_exists,
                $translation_meta_id,
                $translation_stored_value
            ));
            if (!$example_read_complete || !$translation_read_complete) {
                // Leave the durable keyset cursor before this word so the next
                // batch selects this exact candidate again.
                $state['active_word_id'] = 0;
                $state['audio_cursor'] = 0;
                $state['source_example'] = '';
                $state['source_translation'] = '';
                $state['source_example_meta_id'] = 0;
                $state['source_translation_meta_id'] = 0;
                $state['source_example_raw_hash'] = '';
                $state['source_translation_raw_hash'] = '';
                $state['active_had_intro_text'] = false;
                $state['status'] = 'queued';
                $state['last_error'] = 'word_source_unavailable';
                ll_tools_save_example_sentence_migration_state($state);
                ll_tools_schedule_example_sentence_migration(5 * MINUTE_IN_SECONDS);
                return $state;
            }

            $state['source_example'] = $source_example;
            $state['source_translation'] = $source_translation;
            $state['source_example_meta_id'] = $example_exists ? $example_meta_id : 0;
            $state['source_translation_meta_id'] = $translation_exists ? $translation_meta_id : 0;
            $state['source_example_raw_hash'] = $example_exists
                ? ll_tools_example_sentence_migration_raw_hash($example_stored_value)
                : '';
            $state['source_translation_raw_hash'] = $translation_exists
                ? ll_tools_example_sentence_migration_raw_hash($translation_stored_value)
                : '';
            $state['active_had_intro_text'] = false;
            $work_budget--;

            if ($state['source_example'] === '' && $state['source_translation'] === '') {
                $failure_code = '';
                if (!ll_tools_finalize_example_sentence_migration_word($state, $failure_code)) {
                    $state['status'] = 'queued';
                    $state['last_error'] = $failure_code !== '' ? $failure_code : 'word_source_unavailable';
                    ll_tools_save_example_sentence_migration_state($state);
                    ll_tools_schedule_example_sentence_migration(5 * MINUTE_IN_SECONDS);
                    return $state;
                }
                $handled_words++;
                continue;
            }

            $failure_code = '';
            if (!ll_tools_process_active_example_sentence_migration_word($state, $work_budget, $failure_code)) {
                $state['status'] = 'queued';
                $state['last_error'] = $failure_code !== '' ? $failure_code : 'recording_data_unavailable';
                ll_tools_save_example_sentence_migration_state($state);
                ll_tools_schedule_example_sentence_migration(5 * MINUTE_IN_SECONDS);
                return $state;
            }
            if ((int) ($state['active_word_id'] ?? 0) > 0) {
                break;
            }
            $handled_words++;
        }

        if ((int) ($state['active_word_id'] ?? 0) <= 0 && $handled_words === count($word_ids) && !$has_more_words) {
            ll_tools_complete_example_sentence_migration($state);
            return $state;
        }

        $state['status'] = 'queued';
        ll_tools_save_example_sentence_migration_state($state);
        ll_tools_schedule_example_sentence_migration();
        return $state;
    } finally {
        try {
            if (get_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT) === $lock_token) {
                delete_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT);
            }
        } finally {
            ll_tools_release_example_sentence_migration_lock($advisory_lock_name);
        }
    }
}

function ll_tools_migrate_example_sentence_meta_to_intro_recordings(): array {
    $before = ll_tools_get_example_sentence_migration_state();
    $after = ll_tools_run_example_sentence_migration_batch();
    return [
        'migrated' => max(0, (int) ($after['migrated'] ?? 0) - (int) ($before['migrated'] ?? 0)),
        'skipped' => max(0, (int) ($after['skipped'] ?? 0) - (int) ($before['skipped'] ?? 0)),
        'total' => max(0, (int) ($after['processed'] ?? 0) - (int) ($before['processed'] ?? 0)),
    ];
}

function ll_tools_maybe_run_example_sentence_migration() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }

    if (get_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION, false)) {
        return;
    }

    ll_tools_run_example_sentence_migration_batch();
}
add_action('admin_init', 'll_tools_maybe_run_example_sentence_migration');
add_action(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK, 'll_tools_run_example_sentence_migration_batch');
