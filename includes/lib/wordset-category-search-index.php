<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION')) {
    define('LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION', '3');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION')) {
    define('LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION', 'll_tools_wordset_category_search_version');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION')) {
    define('LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION', 'll_tools_wordset_category_search_table_exists');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK')) {
    define('LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK', 'll_tools_wordset_category_search_rebuild_batch');
}

/**
 * Return the durable word/category search-index table name.
 */
function ll_tools_wordset_category_search_table_name(): string {
    global $wpdb;

    return $wpdb->prefix . 'll_wordset_category_search';
}

/**
 * Determine whether the durable search-index table exists.
 */
function ll_tools_wordset_category_search_table_exists(bool $refresh = false): bool {
    static $cached = null;
    global $wpdb;

    if (!$refresh && is_bool($cached)) {
        return $cached;
    }

    if (!$refresh) {
        $stored = get_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION, '');
        if ($stored === '1') {
            $cached = true;
            return true;
        }
        if ($stored === '0') {
            $cached = false;
            return false;
        }
    }

    $table = ll_tools_wordset_category_search_table_name();
    $cached = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) === $table;
    update_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION, $cached ? '1' : '0', false);

    return $cached;
}

/**
 * Install or upgrade the durable search-index schema.
 */
function ll_tools_install_wordset_category_search_schema(): bool {
    global $wpdb;

    $table = ll_tools_wordset_category_search_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        wordset_id bigint(20) unsigned NOT NULL,
        generation varchar(64) NOT NULL DEFAULT '',
        category_id bigint(20) unsigned NOT NULL,
        word_id bigint(20) unsigned NOT NULL,
        title_value text NOT NULL,
        translation_value text NOT NULL,
        title_normalized text NOT NULL,
        translation_normalized text NOT NULL,
        title_tokens text NOT NULL,
        translation_tokens text NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (wordset_id, generation, category_id, word_id),
        KEY idx_wordset_category (wordset_id, category_id),
        KEY idx_word (word_id)
    ) {$charset_collate};";

    $table_exists = ((string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table)
    )) === $table;
    if ($table_exists) {
        $generation_column = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table} LIKE 'generation'"
        );
        if ((string) $generation_column !== 'generation') {
            $wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN generation varchar(64) NOT NULL DEFAULT '' AFTER wordset_id"
            );
        }

        $primary_rows = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        );
        $primary_columns = [];
        foreach ((array) $primary_rows as $primary_row) {
            $sequence = max(1, (int) ($primary_row['Seq_in_index'] ?? 1));
            $primary_columns[$sequence] = (string) ($primary_row['Column_name'] ?? '');
        }
        ksort($primary_columns);
        $primary_columns = array_values($primary_columns);
        if ($primary_columns !== ['wordset_id', 'generation', 'category_id', 'word_id']) {
            $primary_change = empty($primary_columns)
                ? 'ADD PRIMARY KEY (wordset_id, generation, category_id, word_id)'
                : 'DROP PRIMARY KEY, ADD PRIMARY KEY (wordset_id, generation, category_id, word_id)';
            $wpdb->query("ALTER TABLE {$table} {$primary_change}");
        }
    }

    dbDelta($sql);
    $exists = ll_tools_wordset_category_search_table_exists(true);
    $generation_column = $exists
        ? $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'generation'")
        : '';
    $primary_rows = $exists
        ? $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        )
        : [];
    $primary_columns = [];
    foreach ((array) $primary_rows as $primary_row) {
        $sequence = max(1, (int) ($primary_row['Seq_in_index'] ?? 1));
        $primary_columns[$sequence] = (string) ($primary_row['Column_name'] ?? '');
    }
    ksort($primary_columns);
    $exists = $exists
        && (string) $generation_column === 'generation'
        && array_values($primary_columns) === ['wordset_id', 'generation', 'category_id', 'word_id'];
    $exists = (bool) apply_filters(
        'll_tools_wordset_category_search_schema_exists_after_install',
        $exists
    );
    update_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION, $exists ? '1' : '0', false);
    if (!$exists) {
        delete_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION);
        set_transient('ll_tools_wordset_category_search_schema_retry', 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    update_option(
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION,
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION,
        false
    );
    delete_transient('ll_tools_wordset_category_search_schema_retry');

    return true;
}

/**
 * Ensure the durable table exists without starting an unbounded global backfill.
 */
function ll_tools_maybe_upgrade_wordset_category_search_schema(): void {
    $installed = (string) get_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION, '');
    if (
        $installed === LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION
        && ll_tools_wordset_category_search_table_exists()
    ) {
        return;
    }

    if (get_transient('ll_tools_wordset_category_search_schema_retry')) {
        return;
    }
    ll_tools_install_wordset_category_search_schema();
}
add_action('init', 'll_tools_maybe_upgrade_wordset_category_search_schema', 13);

/**
 * Return the per-wordset rebuild-state option name.
 */
function ll_tools_wordset_category_search_state_option(int $wordset_id): string {
    return 'll_tools_wcs_state_' . max(0, $wordset_id);
}

/**
 * Return the per-wordset rebuild-lock option name.
 */
function ll_tools_wordset_category_search_lock_option(int $wordset_id): string {
    return 'll_tools_wcs_lock_' . max(0, $wordset_id);
}

/**
 * Return the cache dependencies that make a wordset materialization current.
 */
function ll_tools_wordset_category_search_dependency_signature(int $wordset_id): string {
    $wordset_id = max(0, $wordset_id);
    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? (string) ll_tools_get_quiz_content_cache_epoch([$wordset_id])
        : (string) $category_epoch;

    return hash('sha256', wp_json_encode([
        'schema' => LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION,
        'wordset_id' => $wordset_id,
        'category_epoch' => $category_epoch,
        'wordset_epoch' => $wordset_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
    ]));
}

/**
 * Return a storage-safe materializer generation token.
 */
function ll_tools_wordset_category_search_sanitize_generation($generation): string {
    $generation = preg_replace('/[^a-f0-9]/', '', strtolower((string) $generation));

    return substr((string) $generation, 0, 64);
}

/**
 * Generate an unguessable durable build generation.
 */
function ll_tools_wordset_category_search_generate_generation(int $wordset_id): string {
    $entropy = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : (string) wp_rand();

    return hash('sha256', max(0, $wordset_id) . '|' . $entropy . '|' . microtime(true));
}

/**
 * Return sanitized per-wordset rebuild state.
 *
 * @return array{
 *   status:string,
 *   signature:string,
 *   generation:string,
 *   published_generation:string,
 *   last_id:int,
 *   processed:int,
 *   started_at:string,
 *   completed_at:string,
 *   last_error:string,
 *   retry_count:int,
 *   next_retry_at:int,
 *   terminal:int
 * }
 */
function ll_tools_get_wordset_category_search_state(int $wordset_id): array {
    $raw = get_option(ll_tools_wordset_category_search_state_option($wordset_id), []);
    $raw = is_array($raw) ? $raw : [];
    $status = (string) ($raw['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'running', 'completed'], true)) {
        $status = 'pending';
    }

    return [
        'status' => $status,
        'signature' => preg_replace('/[^a-f0-9]/', '', strtolower((string) ($raw['signature'] ?? ''))),
        'generation' => ll_tools_wordset_category_search_sanitize_generation($raw['generation'] ?? ''),
        'published_generation' => ll_tools_wordset_category_search_sanitize_generation(
            $raw['published_generation'] ?? ''
        ),
        'last_id' => max(0, (int) ($raw['last_id'] ?? 0)),
        'processed' => max(0, (int) ($raw['processed'] ?? 0)),
        'started_at' => trim((string) ($raw['started_at'] ?? '')),
        'completed_at' => trim((string) ($raw['completed_at'] ?? '')),
        'last_error' => trim((string) ($raw['last_error'] ?? '')),
        'retry_count' => max(0, min(20, (int) ($raw['retry_count'] ?? 0))),
        'next_retry_at' => max(0, (int) ($raw['next_retry_at'] ?? 0)),
        'terminal' => !empty($raw['terminal']) ? 1 : 0,
    ];
}

/**
 * Persist sanitized per-wordset rebuild state without autoloading it.
 *
 * When an expected generation is supplied, the serialized option is changed
 * with compare-and-swap semantics. That prevents a worker which renewed its
 * lease before stalling from publishing over a replacement generation.
 */
function ll_tools_update_wordset_category_search_state(
    int $wordset_id,
    array $state,
    ?string $expected_generation = null,
    ?bool &$updated = null
): array {
    global $wpdb;

    $updated = false;
    $wordset_id = max(0, $wordset_id);
    $status = (string) ($state['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'running', 'completed'], true)) {
        $status = 'pending';
    }
    $sanitized = [
        'status' => $status,
        'signature' => preg_replace('/[^a-f0-9]/', '', strtolower((string) ($state['signature'] ?? ''))),
        'generation' => ll_tools_wordset_category_search_sanitize_generation($state['generation'] ?? ''),
        'published_generation' => ll_tools_wordset_category_search_sanitize_generation(
            $state['published_generation'] ?? ''
        ),
        'last_id' => max(0, (int) ($state['last_id'] ?? 0)),
        'processed' => max(0, (int) ($state['processed'] ?? 0)),
        'started_at' => trim((string) ($state['started_at'] ?? '')),
        'completed_at' => trim((string) ($state['completed_at'] ?? '')),
        'last_error' => trim((string) ($state['last_error'] ?? '')),
        'retry_count' => max(0, min(20, (int) ($state['retry_count'] ?? 0))),
        'next_retry_at' => max(0, (int) ($state['next_retry_at'] ?? 0)),
        'terminal' => !empty($state['terminal']) ? 1 : 0,
    ];

    $option_name = ll_tools_wordset_category_search_state_option($wordset_id);
    if ($expected_generation === null) {
        update_option($option_name, $sanitized, false);
        $updated = true;
        return $sanitized;
    }

    $expected_generation = ll_tools_wordset_category_search_sanitize_generation(
        $expected_generation
    );
    $stored_value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value
         FROM {$wpdb->options}
         WHERE option_name = %s
         LIMIT 1",
        $option_name
    ));
    if ($stored_value === null) {
        return ll_tools_get_wordset_category_search_state($wordset_id);
    }
    $stored_state = maybe_unserialize((string) $stored_value);
    $stored_state = is_array($stored_state) ? $stored_state : [];
    $stored_generation = ll_tools_wordset_category_search_sanitize_generation(
        $stored_state['generation'] ?? ''
    );
    if (!hash_equals($expected_generation, $stored_generation)) {
        wp_cache_delete($option_name, 'options');
        return ll_tools_get_wordset_category_search_state($wordset_id);
    }

    $replacement = maybe_serialize($sanitized);
    if (hash_equals((string) $stored_value, $replacement)) {
        $updated = true;
        return $sanitized;
    }
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $replacement,
        $option_name,
        (string) $stored_value
    ));
    wp_cache_delete($option_name, 'options');
    $updated = $result === 1;

    return $updated
        ? $sanitized
        : ll_tools_get_wordset_category_search_state($wordset_id);
}

/**
 * Acquire an owner-fenced, expiring rebuild lock for one wordset.
 *
 * @return array{acquired:bool,replaced:bool,option_name:string,value:string}
 */
function ll_tools_acquire_wordset_category_search_lock(int $wordset_id, int $ttl = 90): array {
    global $wpdb;

    $wordset_id = max(0, $wordset_id);
    $ttl = max(15, min(300, $ttl));
    $option_name = ll_tools_wordset_category_search_lock_option($wordset_id);
    $now = time();
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', $wordset_id . '|' . microtime(true) . '|' . wp_rand());
    $value = ($now + $ttl) . '|' . $token;
    if (add_option($option_name, $value, '', false)) {
        return [
            'acquired' => true,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => $value,
        ];
    }

    $current = (string) get_option($option_name, '');
    $separator = strpos($current, '|');
    $expires_at = $separator === false ? (int) $current : (int) substr($current, 0, $separator);
    if ($expires_at > $now) {
        return [
            'acquired' => false,
            'replaced' => false,
            'option_name' => $option_name,
            'value' => '',
        ];
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $value,
        $option_name,
        $current
    ));
    wp_cache_delete($option_name, 'options');

    return [
        'acquired' => ($updated === 1),
        'replaced' => ($updated === 1),
        'option_name' => $option_name,
        'value' => ($updated === 1) ? $value : '',
    ];
}

/**
 * Release only the exact rebuild lock acquired by this worker.
 */
function ll_tools_release_wordset_category_search_lock(array $lock): void {
    global $wpdb;

    $option_name = (string) ($lock['option_name'] ?? '');
    $value = (string) ($lock['value'] ?? '');
    if ($option_name === '' || $value === '') {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s AND option_value = %s",
        $option_name,
        $value
    ));
    wp_cache_delete($option_name, 'options');
}

/**
 * Renew the exact lock value held by this worker.
 *
 * The compare-and-swap update is also the write fence: a worker whose lease
 * expired and was replaced must stop before changing rows or rebuild state.
 */
function ll_tools_renew_wordset_category_search_lock(array &$lock, int $ttl = 90): bool {
    global $wpdb;

    $option_name = (string) ($lock['option_name'] ?? '');
    $current_value = (string) ($lock['value'] ?? '');
    if ($option_name === '' || $current_value === '') {
        return false;
    }

    $separator = strpos($current_value, '|');
    $token = $separator === false ? '' : substr($current_value, $separator + 1);
    if ($token === '') {
        return false;
    }

    $ttl = max(15, min(300, $ttl));
    $current_expiry = (int) substr($current_value, 0, $separator);
    $replacement = max(time() + $ttl, $current_expiry + 1) . '|' . $token;
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $replacement,
        $option_name,
        $current_value
    ));
    wp_cache_delete($option_name, 'options');
    if ($updated !== 1) {
        return false;
    }

    $lock['value'] = $replacement;
    return true;
}

/**
 * Report whether any worker lock still exists for a wordset.
 *
 * Even an expired lease forces the next request through takeover/rotation:
 * the expired owner may still have a database write in flight.
 */
function ll_tools_wordset_category_search_lock_exists(int $wordset_id): bool {
    $value = get_option(ll_tools_wordset_category_search_lock_option($wordset_id), false);

    return $value !== false && (string) $value !== '';
}

/**
 * Start a fresh build generation with a compare-and-swap state rotation.
 *
 * @return array<string,mixed>
 */
function ll_tools_wordset_category_search_begin_generation(
    int $wordset_id,
    string $signature,
    array &$lock
): array {
    $wordset_id = max(0, $wordset_id);
    $option_name = ll_tools_wordset_category_search_state_option($wordset_id);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        if (!ll_tools_renew_wordset_category_search_lock($lock)) {
            return ll_tools_get_wordset_category_search_state($wordset_id);
        }

        $current = ll_tools_get_wordset_category_search_state($wordset_id);
        $replacement = [
            'status' => 'running',
            'signature' => $signature,
            'generation' => ll_tools_wordset_category_search_generate_generation($wordset_id),
            'published_generation' => '',
            'last_id' => 0,
            'processed' => 0,
            'started_at' => current_time('mysql', true),
            'completed_at' => '',
            'last_error' => '',
            'retry_count' => 0,
            'next_retry_at' => 0,
            'terminal' => 0,
        ];

        if (get_option($option_name, null) === null) {
            if (add_option($option_name, $replacement, '', false)) {
                return ll_tools_get_wordset_category_search_state($wordset_id);
            }
            wp_cache_delete($option_name, 'options');
            continue;
        }

        $did_update = false;
        $result = ll_tools_update_wordset_category_search_state(
            $wordset_id,
            $replacement,
            (string) ($current['generation'] ?? ''),
            $did_update
        );
        if ($did_update) {
            return $result;
        }
    }

    return ll_tools_get_wordset_category_search_state($wordset_id);
}

/**
 * Persist progress only while both the lease and build generation are owned.
 *
 * @return array<string,mixed>
 */
function ll_tools_wordset_category_search_update_owned_state(
    int $wordset_id,
    array $state,
    string $generation,
    array &$lock,
    ?bool &$updated = null
): array {
    $updated = false;
    if (!ll_tools_renew_wordset_category_search_lock($lock)) {
        return ll_tools_get_wordset_category_search_state($wordset_id);
    }

    return ll_tools_update_wordset_category_search_state(
        $wordset_id,
        $state,
        $generation,
        $updated
    );
}

/**
 * Delete only one build generation.
 */
function ll_tools_wordset_category_search_delete_generation_rows(
    int $wordset_id,
    string $generation
): bool {
    global $wpdb;

    $generation = ll_tools_wordset_category_search_sanitize_generation($generation);
    if ($wordset_id <= 0 || $generation === '') {
        return false;
    }

    $wpdb->last_error = '';
    $deleted = $wpdb->delete(
        ll_tools_wordset_category_search_table_name(),
        [
            'wordset_id' => $wordset_id,
            'generation' => $generation,
        ],
        ['%d', '%s']
    );

    return $deleted !== false && $wpdb->last_error === '';
}

/**
 * Remove a bounded chunk of unpublished generations.
 *
 * @return bool True when another cleanup pass may be useful.
 */
function ll_tools_wordset_category_search_cleanup_old_generations(
    int $wordset_id,
    string $published_generation
): bool {
    global $wpdb;

    $published_generation = ll_tools_wordset_category_search_sanitize_generation(
        $published_generation
    );
    if ($wordset_id <= 0 || $published_generation === '') {
        return false;
    }

    $limit = (int) apply_filters(
        'll_tools_wordset_category_search_cleanup_row_limit',
        500
    );
    $limit = max(50, min(2000, $limit));
    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM " . ll_tools_wordset_category_search_table_name() . "
         WHERE wordset_id = %d
           AND generation <> %s
         LIMIT %d",
        $wordset_id,
        $published_generation,
        $limit
    ));

    return $deleted === $limit && $wpdb->last_error === '';
}

/**
 * Return a bounded keyset batch size for one materializer step.
 */
function ll_tools_wordset_category_search_rebuild_batch_size(): int {
    $batch_size = (int) apply_filters('ll_tools_wordset_category_search_rebuild_batch_size', 100);

    return max(10, min(250, $batch_size));
}

/**
 * Return the maximum category relationships one word may contribute.
 */
function ll_tools_wordset_category_search_categories_per_word_limit(): int {
    $limit = (int) apply_filters('ll_tools_wordset_category_search_categories_per_word_limit', 16);

    return max(4, min(64, $limit));
}

/**
 * Cap individual persisted values so malformed metadata cannot inflate a batch.
 */
function ll_tools_wordset_category_search_cap_value(string $value, int $limit = 1000): string {
    $value = trim($value);
    $limit = max(120, min(4000, $limit));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/**
 * Normalize a stored value for contains and token-boundary matching.
 */
function ll_tools_wordset_category_search_normalize_value(string $value): string {
    if (function_exists('ll_tools_wordset_page_normalize_category_search_match_text')) {
        return ll_tools_wordset_category_search_cap_value(
            ll_tools_wordset_page_normalize_category_search_match_text($value)
        );
    }

    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = wp_strip_all_tags($value);
    $value = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    return ll_tools_wordset_category_search_cap_value(
        trim((string) preg_replace('/\s+/u', ' ', $value))
    );
}

/**
 * Convert normalized punctuation boundaries into a space-padded token string.
 */
function ll_tools_wordset_category_search_token_value(string $normalized): string {
    $tokens = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
    if (!is_string($tokens)) {
        $tokens = preg_replace('/[^a-z0-9]+/i', ' ', $normalized);
    }
    $tokens = trim((string) preg_replace('/\s+/u', ' ', (string) $tokens));

    return $tokens === '' ? '' : ' ' . $tokens . ' ';
}

/**
 * Queue the next bounded materializer batch for one wordset.
 */
function ll_tools_schedule_wordset_category_search_rebuild(int $wordset_id, int $delay = 2): void {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0) {
        return;
    }

    $args = [$wordset_id];
    if (!wp_next_scheduled(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, $args)) {
        wp_schedule_single_event(
            time() + max(1, min(HOUR_IN_SECONDS, $delay)),
            LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
            $args
        );
    }
}

/**
 * Return one bounded keyset batch of published words and their search strings.
 *
 * @return array<int,array{word_id:int,title_value:string,translation_value:string}>
 */
function ll_tools_wordset_category_search_get_word_batch(
    int $wordset_id,
    int $last_id,
    int $batch_size,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $limit = max(1, $batch_size) + 1;
    $sql = "
        SELECT
            posts.ID AS word_id,
            LEFT(posts.post_title, 1000) AS title_value,
            COALESCE(
                NULLIF((
                    SELECT LEFT(translation_meta.meta_value, 1000)
                    FROM {$wpdb->postmeta} AS translation_meta
                    WHERE translation_meta.post_id = posts.ID
                      AND translation_meta.meta_key = 'word_translation'
                    ORDER BY translation_meta.meta_id DESC
                    LIMIT 1
                ), ''),
                (
                    SELECT LEFT(legacy_translation_meta.meta_value, 1000)
                    FROM {$wpdb->postmeta} AS legacy_translation_meta
                    WHERE legacy_translation_meta.post_id = posts.ID
                      AND legacy_translation_meta.meta_key = 'word_english_meaning'
                    ORDER BY legacy_translation_meta.meta_id DESC
                    LIMIT 1
                ),
                ''
            ) AS translation_value
        FROM {$wpdb->posts} AS posts
        INNER JOIN {$wpdb->term_relationships} AS wordset_relationships
            ON wordset_relationships.object_id = posts.ID
        INNER JOIN {$wpdb->term_taxonomy} AS wordset_taxonomy
            ON wordset_taxonomy.term_taxonomy_id = wordset_relationships.term_taxonomy_id
           AND wordset_taxonomy.taxonomy = 'wordset'
           AND wordset_taxonomy.term_id = %d
        WHERE posts.post_type = 'words'
          AND posts.post_status = 'publish'
          AND posts.ID > %d
        ORDER BY posts.ID ASC
        LIMIT %d
    ";

    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        $sql,
        $wordset_id,
        max(0, $last_id),
        $limit
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }

    return array_values(array_filter(array_map(static function ($row): array {
        $row = is_array($row) ? $row : [];
        return [
            'word_id' => max(0, (int) ($row['word_id'] ?? 0)),
            'title_value' => (string) ($row['title_value'] ?? ''),
            'translation_value' => (string) ($row['translation_value'] ?? ''),
        ];
    }, (array) $rows), static function (array $row): bool {
        return $row['word_id'] > 0;
    }));
}

/**
 * Load category relationships for one already-bounded word batch.
 *
 * @return array<int,int[]>
 */
function ll_tools_wordset_category_search_get_category_map(
    array $word_ids,
    ?bool &$complete = null,
    ?string &$error_code = null
): array {
    global $wpdb;

    $complete = true;
    $error_code = '';
    $word_ids = array_values(array_filter(array_unique(array_map('intval', $word_ids)), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $per_word_limit = ll_tools_wordset_category_search_categories_per_word_limit();
    $row_limit = (count($word_ids) * $per_word_limit) + 1;
    $placeholders = implode(', ', array_fill(0, count($word_ids), '%d'));
    $sql = "
        SELECT relationships.object_id AS word_id, taxonomy.term_id AS category_id
        FROM {$wpdb->term_relationships} AS relationships
        INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
            ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
           AND taxonomy.taxonomy = 'word-category'
        WHERE relationships.object_id IN ({$placeholders})
        ORDER BY relationships.object_id ASC, taxonomy.term_id ASC
        LIMIT %d
    ";

    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        $sql,
        array_merge($word_ids, [$row_limit])
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        $complete = false;
        $error_code = 'category_relationship_query_failed';
        return [];
    }
    if (count((array) $rows) >= $row_limit) {
        $complete = false;
        $error_code = 'category_relationship_limit';
        return [];
    }

    $map = [];
    foreach ((array) $rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        $category_id = (int) ($row['category_id'] ?? 0);
        if ($word_id <= 0 || $category_id <= 0) {
            continue;
        }
        if (!isset($map[$word_id])) {
            $map[$word_id] = [];
        }
        $map[$word_id][] = $category_id;
        if (count($map[$word_id]) > $per_word_limit) {
            $complete = false;
            $error_code = 'category_relationship_limit';
            return [];
        }
    }

    return $map;
}

/**
 * Keep only deepest assigned categories, preserving ties at the same depth.
 *
 * @return int[]
 */
function ll_tools_wordset_category_search_get_deepest_categories(
    array $category_ids,
    ?bool &$complete = null
): array {
    $complete = true;
    $category_ids = array_values(array_filter(array_unique(array_map('intval', $category_ids)), static function (int $id): bool {
        return $id > 0;
    }));
    $deepest = [];
    $max_depth = -1;

    foreach ($category_ids as $category_id) {
        if (function_exists('ll_tools_wordset_page_get_category_depth_cached')) {
            $depth_complete = true;
            $depth = ll_tools_wordset_page_get_category_depth_cached($category_id, $depth_complete);
            if (!$depth_complete) {
                $complete = false;
                return [];
            }
        } else {
            $ancestors = get_ancestors($category_id, 'word-category', 'taxonomy');
            if (!is_array($ancestors)) {
                $complete = false;
                return [];
            }
            $depth = count($ancestors);
        }

        if ($depth > $max_depth) {
            $max_depth = $depth;
            $deepest = [$category_id];
        } elseif ($depth === $max_depth) {
            $deepest[] = $category_id;
        }
    }

    return $deepest;
}

/**
 * Estimate an escaped SQL contribution for one durable row.
 */
function ll_tools_wordset_category_search_estimate_row_bytes(array $row): int {
    $bytes = 256;
    foreach ([
        'generation',
        'title_value',
        'translation_value',
        'title_normalized',
        'translation_normalized',
        'title_tokens',
        'translation_tokens',
        'updated_at',
    ] as $key) {
        // Doubling is a conservative allowance for SQL string escaping.
        $bytes += strlen((string) ($row[$key] ?? '')) * 2;
    }

    return $bytes;
}

/**
 * Insert one already-bounded row chunk.
 */
function ll_tools_wordset_category_search_insert_row_chunk(array $rows): bool {
    global $wpdb;

    if (empty($rows)) {
        return true;
    }

    $table = ll_tools_wordset_category_search_table_name();
    $placeholders = [];
    $params = [];
    foreach ($rows as $row) {
        $placeholders[] = '(%d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s)';
        $params[] = (int) ($row['wordset_id'] ?? 0);
        $params[] = ll_tools_wordset_category_search_sanitize_generation(
            $row['generation'] ?? ''
        );
        $params[] = (int) ($row['category_id'] ?? 0);
        $params[] = (int) ($row['word_id'] ?? 0);
        $params[] = (string) ($row['title_value'] ?? '');
        $params[] = (string) ($row['translation_value'] ?? '');
        $params[] = (string) ($row['title_normalized'] ?? '');
        $params[] = (string) ($row['translation_normalized'] ?? '');
        $params[] = (string) ($row['title_tokens'] ?? '');
        $params[] = (string) ($row['translation_tokens'] ?? '');
        $params[] = (string) ($row['updated_at'] ?? current_time('mysql', true));
    }

    $sql = "INSERT INTO {$table}
        (wordset_id, generation, category_id, word_id, title_value, translation_value,
         title_normalized, translation_normalized, title_tokens, translation_tokens, updated_at)
        VALUES " . implode(', ', $placeholders) . "
        ON DUPLICATE KEY UPDATE
            title_value = VALUES(title_value),
            translation_value = VALUES(translation_value),
            title_normalized = VALUES(title_normalized),
            translation_normalized = VALUES(translation_normalized),
            title_tokens = VALUES(title_tokens),
            translation_tokens = VALUES(translation_tokens),
            updated_at = VALUES(updated_at)";

    $wpdb->last_error = '';
    $result = $wpdb->query($wpdb->prepare($sql, $params));

    return $result !== false && $wpdb->last_error === '';
}

/**
 * Insert a materializer batch using both row-count and byte-size ceilings.
 */
function ll_tools_wordset_category_search_insert_rows(array $rows, ?array &$lock = null): bool {
    if (empty($rows)) {
        return true;
    }

    $max_rows = (int) apply_filters('ll_tools_wordset_category_search_insert_chunk_rows', 50);
    $max_rows = max(5, min(200, $max_rows));
    $max_bytes = (int) apply_filters('ll_tools_wordset_category_search_insert_chunk_bytes', 512 * 1024);
    $max_bytes = max(64 * 1024, min(2 * 1024 * 1024, $max_bytes));
    $chunk = [];
    $chunk_bytes = 0;

    $flush = static function () use (&$chunk, &$chunk_bytes, &$lock): bool {
        if (empty($chunk)) {
            return true;
        }
        if (is_array($lock) && !ll_tools_renew_wordset_category_search_lock($lock)) {
            return false;
        }
        if (!ll_tools_wordset_category_search_insert_row_chunk($chunk)) {
            return false;
        }
        $chunk = [];
        $chunk_bytes = 0;
        return true;
    };

    foreach ($rows as $row) {
        $row = is_array($row) ? $row : [];
        $row_bytes = ll_tools_wordset_category_search_estimate_row_bytes($row);
        if (
            !empty($chunk)
            && (count($chunk) >= $max_rows || ($chunk_bytes + $row_bytes) > $max_bytes)
        ) {
            if (!$flush()) {
                return false;
            }
        }
        $chunk[] = $row;
        $chunk_bytes += $row_bytes;
    }

    return $flush();
}

/**
 * Return an exponential retry delay for a transient materializer failure.
 */
function ll_tools_wordset_category_search_retry_delay(int $retry_count): int {
    $retry_count = max(1, min(20, $retry_count));
    $delay = min(HOUR_IN_SECONDS, 5 * (2 ** min(9, $retry_count - 1)));

    return max(5, (int) apply_filters(
        'll_tools_wordset_category_search_retry_delay',
        $delay,
        $retry_count
    ));
}

/**
 * Persist a fenced failure state and schedule only transient retries.
 *
 * @return array<string,mixed>
 */
function ll_tools_wordset_category_search_record_failure(
    int $wordset_id,
    array $state,
    string $signature,
    string $error_code,
    bool $terminal,
    ?array &$lock = null
): array {
    $retry_count = max(0, (int) ($state['retry_count'] ?? 0)) + 1;
    $delay = $terminal ? 0 : ll_tools_wordset_category_search_retry_delay($retry_count);
    $state['status'] = 'pending';
    $state['signature'] = $signature;
    $state['last_error'] = $error_code;
    $state['retry_count'] = $retry_count;
    $state['next_retry_at'] = $terminal ? 0 : time() + $delay;
    $state['terminal'] = $terminal ? 1 : 0;
    $state['completed_at'] = '';
    if (is_array($lock)) {
        $did_update = false;
        $state = ll_tools_wordset_category_search_update_owned_state(
            $wordset_id,
            $state,
            (string) ($state['generation'] ?? ''),
            $lock,
            $did_update
        );
        if (!$did_update) {
            return $state;
        }
    } else {
        $state = ll_tools_update_wordset_category_search_state($wordset_id, $state);
    }
    if (!$terminal) {
        ll_tools_schedule_wordset_category_search_rebuild($wordset_id, $delay);
    }

    return $state;
}

/**
 * Process exactly one bounded, keyset-paginated materializer batch.
 *
 * @return array<string,mixed>
 */
function ll_tools_wordset_category_search_process_rebuild_batch(int $wordset_id): array {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0) {
        return ll_tools_get_wordset_category_search_state($wordset_id);
    }
    if (!ll_tools_wordset_category_search_table_exists()) {
        if (!ll_tools_install_wordset_category_search_schema()) {
            $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
            return ll_tools_wordset_category_search_record_failure(
                $wordset_id,
                ll_tools_get_wordset_category_search_state($wordset_id),
                $signature,
                'schema_install_failed',
                false
            );
        }
    }

    $lock = ll_tools_acquire_wordset_category_search_lock($wordset_id);
    if (empty($lock['acquired'])) {
        return ll_tools_get_wordset_category_search_state($wordset_id);
    }

    try {
        $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
        $state = ll_tools_get_wordset_category_search_state($wordset_id);
        if (empty($lock['replaced']) && $state['signature'] === $signature) {
            if (!empty($state['terminal'])) {
                return $state;
            }
            if ((int) $state['next_retry_at'] > time()) {
                return $state;
            }
        }
        $must_reset = !empty($lock['replaced'])
            || (string) ($state['generation'] ?? '') === ''
            || $state['signature'] !== $signature
            || (
                $state['status'] === 'completed'
                && (string) ($state['published_generation'] ?? '') !== (string) ($state['generation'] ?? '')
            )
            || ($state['status'] === 'pending' && $state['last_id'] === 0);
        if ($must_reset) {
            $previous_generation = (string) ($state['generation'] ?? '');
            $state = ll_tools_wordset_category_search_begin_generation(
                $wordset_id,
                $signature,
                $lock
            );
            if (
                (string) ($state['generation'] ?? '') === ''
                || (string) ($state['signature'] ?? '') !== $signature
                || hash_equals(
                    ll_tools_wordset_category_search_sanitize_generation($previous_generation),
                    (string) ($state['generation'] ?? '')
                )
            ) {
                return $state;
            }
        }
        $generation = (string) ($state['generation'] ?? '');
        if (
            $state['status'] === 'completed'
            && $state['signature'] === $signature
            && $generation !== ''
            && hash_equals($generation, (string) ($state['published_generation'] ?? ''))
        ) {
            if (ll_tools_wordset_category_search_cleanup_old_generations(
                $wordset_id,
                $generation
            )) {
                ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
            }
            return $state;
        }
        $state['status'] = 'running';
        $state['next_retry_at'] = 0;
        $state['terminal'] = 0;

        $batch_size = ll_tools_wordset_category_search_rebuild_batch_size();
        $batch_complete = true;
        $word_rows = ll_tools_wordset_category_search_get_word_batch(
            $wordset_id,
            (int) $state['last_id'],
            $batch_size,
            $batch_complete
        );
        if (!$batch_complete) {
            return ll_tools_wordset_category_search_record_failure(
                $wordset_id,
                $state,
                $signature,
                'word_batch_failed',
                false,
                $lock
            );
        }

        $has_more = count($word_rows) > $batch_size;
        if ($has_more) {
            $word_rows = array_slice($word_rows, 0, $batch_size);
        }
        if (empty($word_rows)) {
            $current_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
            if ($current_signature !== $signature) {
                $state = ll_tools_wordset_category_search_begin_generation(
                    $wordset_id,
                    $current_signature,
                    $lock
                );
                ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
                return $state;
            }
            $state['status'] = 'completed';
            $state['published_generation'] = $generation;
            $state['completed_at'] = current_time('mysql', true);
            $state['last_error'] = '';
            $state['retry_count'] = 0;
            $state['next_retry_at'] = 0;
            $state['terminal'] = 0;
            $did_publish = false;
            $state = ll_tools_wordset_category_search_update_owned_state(
                $wordset_id,
                $state,
                $generation,
                $lock,
                $did_publish
            );
            if (
                $did_publish
                && ll_tools_wordset_category_search_cleanup_old_generations(
                    $wordset_id,
                    $generation
                )
            ) {
                ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
            }
            return $state;
        }

        $word_ids = array_values(array_map(static function (array $row): int {
            return (int) $row['word_id'];
        }, $word_rows));
        $category_map_complete = true;
        $category_map_error = '';
        $category_map = ll_tools_wordset_category_search_get_category_map(
            $word_ids,
            $category_map_complete,
            $category_map_error
        );
        if (!$category_map_complete) {
            ll_tools_wordset_category_search_delete_generation_rows(
                $wordset_id,
                $generation
            );
            $state['last_id'] = 0;
            $state['processed'] = 0;
            return ll_tools_wordset_category_search_record_failure(
                $wordset_id,
                $state,
                $signature,
                $category_map_error !== '' ? $category_map_error : 'category_relationship_query_failed',
                $category_map_error === 'category_relationship_limit',
                $lock
            );
        }

        $insert_rows = [];
        $now = current_time('mysql', true);
        foreach ($word_rows as $word_row) {
            $word_id = (int) $word_row['word_id'];
            $deepest_complete = true;
            $deepest_category_ids = ll_tools_wordset_category_search_get_deepest_categories(
                (array) ($category_map[$word_id] ?? []),
                $deepest_complete
            );
            if (!$deepest_complete) {
                ll_tools_wordset_category_search_delete_generation_rows(
                    $wordset_id,
                    $generation
                );
                $state['last_id'] = 0;
                $state['processed'] = 0;
                return ll_tools_wordset_category_search_record_failure(
                    $wordset_id,
                    $state,
                    $signature,
                    'category_depth_failed',
                    false,
                    $lock
                );
            }

            $title = ll_tools_wordset_category_search_cap_value((string) $word_row['title_value']);
            $translation = ll_tools_wordset_category_search_cap_value((string) $word_row['translation_value']);
            $title_normalized = ll_tools_wordset_category_search_normalize_value($title);
            $translation_normalized = ll_tools_wordset_category_search_normalize_value($translation);
            if ($title_normalized === '' && $translation_normalized === '') {
                continue;
            }

            foreach ($deepest_category_ids as $category_id) {
                $insert_rows[] = [
                    'wordset_id' => $wordset_id,
                    'generation' => $generation,
                    'category_id' => (int) $category_id,
                    'word_id' => $word_id,
                    'title_value' => $title,
                    'translation_value' => $translation,
                    'title_normalized' => $title_normalized,
                    'translation_normalized' => $translation_normalized,
                    'title_tokens' => ll_tools_wordset_category_search_token_value($title_normalized),
                    'translation_tokens' => ll_tools_wordset_category_search_token_value($translation_normalized),
                    'updated_at' => $now,
                ];
            }
        }

        if (!ll_tools_wordset_category_search_insert_rows($insert_rows, $lock)) {
            ll_tools_wordset_category_search_delete_generation_rows(
                $wordset_id,
                $generation
            );
            $state['last_id'] = 0;
            $state['processed'] = 0;
            return ll_tools_wordset_category_search_record_failure(
                $wordset_id,
                $state,
                $signature,
                'insert_failed',
                false,
                $lock
            );
        }

        $state['last_id'] = (int) end($word_ids);
        $state['processed'] += count($word_ids);
        $state['last_error'] = '';
        $state['retry_count'] = 0;
        $state['next_retry_at'] = 0;
        $state['terminal'] = 0;
        $current_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
        if ($current_signature !== $signature) {
            $state = ll_tools_wordset_category_search_begin_generation(
                $wordset_id,
                $current_signature,
                $lock
            );
            ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
            return $state;
        }

        if ($has_more) {
            $state['status'] = 'running';
            $did_update = false;
            $state = ll_tools_wordset_category_search_update_owned_state(
                $wordset_id,
                $state,
                $generation,
                $lock,
                $did_update
            );
            if (!$did_update) {
                return $state;
            }
            ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
            return $state;
        } else {
            $state['status'] = 'completed';
            $state['published_generation'] = $generation;
            $state['completed_at'] = current_time('mysql', true);
        }

        $did_publish = false;
        $state = ll_tools_wordset_category_search_update_owned_state(
            $wordset_id,
            $state,
            $generation,
            $lock,
            $did_publish
        );
        if (
            $did_publish
            && ll_tools_wordset_category_search_cleanup_old_generations(
                $wordset_id,
                $generation
            )
        ) {
            ll_tools_schedule_wordset_category_search_rebuild($wordset_id);
        }
        return $state;
    } finally {
        ll_tools_release_wordset_category_search_lock($lock);
    }
}

/**
 * Cron callback for one wordset materializer batch.
 */
function ll_tools_wordset_category_search_run_scheduled_rebuild($wordset_id): void {
    $state = ll_tools_wordset_category_search_process_rebuild_batch((int) $wordset_id);
    if (($state['status'] ?? '') === 'completed' || !empty($state['terminal'])) {
        return;
    }
    $delay = max(1, (int) ($state['next_retry_at'] ?? 0) - time());
    ll_tools_schedule_wordset_category_search_rebuild((int) $wordset_id, $delay);
}
add_action(
    LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
    'll_tools_wordset_category_search_run_scheduled_rebuild',
    10,
    1
);

/**
 * Verify that one completed generation is safe to expose to readers.
 */
function ll_tools_wordset_category_search_state_is_ready(
    int $wordset_id,
    string $signature,
    array $state
): bool {
    $generation = (string) ($state['generation'] ?? '');
    $published_generation = (string) ($state['published_generation'] ?? '');

    return ($state['status'] ?? '') === 'completed'
        && $generation !== ''
        && hash_equals($generation, $published_generation)
        && hash_equals($signature, (string) ($state['signature'] ?? ''))
        && !ll_tools_wordset_category_search_lock_exists($wordset_id);
}

/**
 * Advance one bounded batch and report whether the wordset is query-ready.
 */
function ll_tools_wordset_category_search_ensure_ready(int $wordset_id): bool {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0 || !ll_tools_wordset_category_search_table_exists()) {
        return false;
    }

    $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
    $state = ll_tools_get_wordset_category_search_state($wordset_id);
    if (ll_tools_wordset_category_search_state_is_ready($wordset_id, $signature, $state)) {
        return true;
    }
    if (
        hash_equals($signature, (string) $state['signature'])
        && (!empty($state['terminal']) || (int) $state['next_retry_at'] > time())
    ) {
        return false;
    }

    $state = ll_tools_wordset_category_search_process_rebuild_batch($wordset_id);

    return ll_tools_wordset_category_search_state_is_ready(
        $wordset_id,
        $signature,
        $state
    );
}

/**
 * Report a deterministic build failure for the current source generation.
 */
function ll_tools_wordset_category_search_failure_is_terminal(int $wordset_id): bool {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0) {
        return false;
    }

    $state = ll_tools_get_wordset_category_search_state($wordset_id);
    $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);

    return !empty($state['terminal'])
        && hash_equals($signature, (string) $state['signature']);
}

/**
 * Query a bounded number of durable rows for compatibility/index diagnostics.
 *
 * @return array<int,array<string,mixed>>
 */
function ll_tools_wordset_category_search_get_index_rows(
    int $wordset_id,
    array $allowed_category_ids,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $allowed_category_ids = array_values(array_filter(array_unique(array_map('intval', $allowed_category_ids)), static function (int $id): bool {
        return $id > 0;
    }));
    if ($wordset_id <= 0 || empty($allowed_category_ids)) {
        return [];
    }
    if (!ll_tools_wordset_category_search_ensure_ready($wordset_id)) {
        $complete = false;
        return [];
    }
    $source_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
    $read_state = ll_tools_get_wordset_category_search_state($wordset_id);
    $published_generation = (string) ($read_state['published_generation'] ?? '');
    if (!ll_tools_wordset_category_search_state_is_ready(
        $wordset_id,
        $source_signature,
        $read_state
    )) {
        $complete = false;
        return [];
    }

    $row_limit = (int) apply_filters('ll_tools_wordset_category_search_compatibility_row_limit', 5000);
    $row_limit = max(100, min(20000, $row_limit));
    $table = ll_tools_wordset_category_search_table_name();
    $rows = [];
    foreach (array_chunk($allowed_category_ids, 250) as $category_chunk) {
        $remaining = $row_limit - count($rows);
        if ($remaining < 0) {
            $complete = false;
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($category_chunk), '%d'));
        $sql = "
            SELECT category_id, word_id, title_value, translation_value
            FROM {$table}
            WHERE wordset_id = %d
              AND generation = %s
              AND category_id IN ({$placeholders})
            ORDER BY category_id ASC, word_id ASC
            LIMIT %d
        ";
        $wpdb->last_error = '';
        $chunk_rows = $wpdb->get_results($wpdb->prepare(
            $sql,
            array_merge(
                [$wordset_id, $published_generation],
                $category_chunk,
                [$remaining + 1]
            )
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $rows = array_merge($rows, (array) $chunk_rows);
        if (count($rows) > $row_limit) {
            $complete = false;
            return [];
        }
    }
    $final_state = ll_tools_get_wordset_category_search_state($wordset_id);
    if (
        !hash_equals(
            $source_signature,
            ll_tools_wordset_category_search_dependency_signature($wordset_id)
        )
        || !ll_tools_wordset_category_search_state_is_ready(
            $wordset_id,
            $source_signature,
            $final_state
        )
        || !hash_equals(
            $published_generation,
            (string) ($final_state['published_generation'] ?? '')
        )
    ) {
        $complete = false;
        return [];
    }

    return $rows;
}

/**
 * Query materialized category matches without hydrating the whole wordset.
 *
 * @return array<int,array<int,array<string,mixed>>>
 */
function ll_tools_wordset_category_search_query_matches(
    int $wordset_id,
    array $allowed_category_ids,
    string $query,
    int $per_category_limit = 1,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $allowed_category_ids = array_values(array_filter(array_unique(array_map('intval', $allowed_category_ids)), static function (int $id): bool {
        return $id > 0;
    }));
    if ($wordset_id <= 0 || empty($allowed_category_ids) || $query === '') {
        return [];
    }
    if (!ll_tools_wordset_category_search_ensure_ready($wordset_id)) {
        $complete = false;
        return [];
    }
    $source_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
    $read_state = ll_tools_get_wordset_category_search_state($wordset_id);
    $published_generation = (string) ($read_state['published_generation'] ?? '');
    if (!ll_tools_wordset_category_search_state_is_ready(
        $wordset_id,
        $source_signature,
        $read_state
    )) {
        $complete = false;
        return [];
    }

    $category_limit = (int) apply_filters('ll_tools_wordset_category_search_request_category_limit', 5000);
    $category_limit = max(100, min(10000, $category_limit));
    if (count($allowed_category_ids) > $category_limit) {
        $complete = false;
        return [];
    }

    $per_category_limit = max(0, min(25, $per_category_limit));
    $candidate_limit = $per_category_limit > 0
        ? max(4, min(100, $per_category_limit * 4))
        : 1;
    $table = ll_tools_wordset_category_search_table_name();
    $contains_like = '%' . $wpdb->esc_like($query) . '%';
    $exact_like = '% ' . $wpdb->esc_like($query) . ' %';
    $prefix_like = '% ' . $wpdb->esc_like($query) . '%';
    $exact_condition = $wpdb->prepare(
        '(title_tokens LIKE %s OR translation_tokens LIKE %s)',
        $exact_like,
        $exact_like
    );
    $prefix_condition = $wpdb->prepare(
        '(title_tokens LIKE %s OR translation_tokens LIKE %s)',
        $prefix_like,
        $prefix_like
    );
    $rank_expression = "CASE
        WHEN {$exact_condition} THEN 300
        WHEN {$prefix_condition} THEN 200
        ELSE 100
    END";
    $grouped_rows = [];
    foreach (array_chunk($allowed_category_ids, 250) as $category_chunk) {
        $category_placeholders = implode(', ', array_fill(0, count($category_chunk), '%d'));
        $sql = "
            SELECT
                category_id,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(word_id ORDER BY {$rank_expression} DESC, word_id ASC SEPARATOR ','),
                    ',',
                    %d
                ) AS candidate_word_ids
            FROM {$table}
            WHERE wordset_id = %d
              AND generation = %s
              AND category_id IN ({$category_placeholders})
              AND (title_normalized LIKE %s OR translation_normalized LIKE %s)
            GROUP BY category_id
            ORDER BY category_id ASC
        ";

        $wpdb->last_error = '';
        $chunk_rows = $wpdb->get_results($wpdb->prepare(
            $sql,
            array_merge(
                [$candidate_limit, $wordset_id],
                [$published_generation],
                $category_chunk,
                [$contains_like, $contains_like]
            )
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $grouped_rows = array_merge($grouped_rows, (array) $chunk_rows);
    }

    $candidate_map = [];
    foreach ((array) $grouped_rows as $row) {
        $category_id = (int) ($row['category_id'] ?? 0);
        $ids = wp_parse_id_list((string) ($row['candidate_word_ids'] ?? ''));
        if ($category_id <= 0 || empty($ids)) {
            continue;
        }
        $candidate_map[$category_id] = array_fill_keys($ids, true);
    }
    if (empty($candidate_map)) {
        $final_state = ll_tools_get_wordset_category_search_state($wordset_id);
        if (
            !hash_equals(
                $source_signature,
                ll_tools_wordset_category_search_dependency_signature($wordset_id)
            )
            || !ll_tools_wordset_category_search_state_is_ready(
                $wordset_id,
                $source_signature,
                $final_state
            )
            || !hash_equals(
                $published_generation,
                (string) ($final_state['published_generation'] ?? '')
            )
        ) {
            $complete = false;
        }
        return [];
    }
    if ($per_category_limit === 0) {
        $final_state = ll_tools_get_wordset_category_search_state($wordset_id);
        if (
            !hash_equals(
                $source_signature,
                ll_tools_wordset_category_search_dependency_signature($wordset_id)
            )
            || !ll_tools_wordset_category_search_state_is_ready(
                $wordset_id,
                $source_signature,
                $final_state
            )
            || !hash_equals(
                $published_generation,
                (string) ($final_state['published_generation'] ?? '')
            )
        ) {
            $complete = false;
            return [];
        }
        return array_fill_keys(array_keys($candidate_map), []);
    }

    $detail_rows = [];
    foreach (array_chunk($candidate_map, 100, true) as $candidate_chunk) {
        $pair_clauses = [];
        $params = [$wordset_id, $published_generation];
        foreach ($candidate_chunk as $category_id => $word_lookup) {
            $word_ids = array_values(array_filter(array_map('intval', array_keys((array) $word_lookup))));
            if (empty($word_ids)) {
                continue;
            }
            $word_placeholders = implode(', ', array_fill(0, count($word_ids), '%d'));
            $pair_clauses[] = "(category_id = %d AND word_id IN ({$word_placeholders}))";
            $params[] = (int) $category_id;
            array_push($params, ...$word_ids);
        }
        if (empty($pair_clauses)) {
            continue;
        }
        $sql = "
            SELECT category_id, word_id, title_value, translation_value
            FROM {$table}
            WHERE wordset_id = %d
              AND generation = %s
              AND (" . implode(' OR ', $pair_clauses) . ")
            ORDER BY category_id ASC, word_id ASC
        ";
        $wpdb->last_error = '';
        $chunk_rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $detail_rows = array_merge($detail_rows, (array) $chunk_rows);
    }

    $matches = [];
    foreach ((array) $detail_rows as $row) {
        $category_id = (int) ($row['category_id'] ?? 0);
        $word_id = (int) ($row['word_id'] ?? 0);
        if ($category_id <= 0 || $word_id <= 0 || empty($candidate_map[$category_id][$word_id])) {
            continue;
        }

        $title = function_exists('ll_tools_wordset_page_normalize_search_text')
            ? ll_tools_wordset_page_normalize_search_text((string) ($row['title_value'] ?? ''))
            : trim((string) ($row['title_value'] ?? ''));
        $translation = function_exists('ll_tools_wordset_page_normalize_search_text')
            ? ll_tools_wordset_page_normalize_search_text((string) ($row['translation_value'] ?? ''))
            : trim((string) ($row['translation_value'] ?? ''));
        $title_rank = function_exists('ll_tools_wordset_page_category_search_text_match_rank')
            ? ll_tools_wordset_page_category_search_text_match_rank($title, $query)
            : 100;
        $translation_rank = function_exists('ll_tools_wordset_page_category_search_text_match_rank')
            ? ll_tools_wordset_page_category_search_text_match_rank($translation, $query)
            : 100;
        $rank = max($title_rank, $translation_rank);
        if ($rank <= 0) {
            continue;
        }
        $matches[$category_id][] = [
            'id' => $word_id,
            'title' => $title,
            'translation' => $translation,
            'match_rank' => $rank,
            'match_field' => $title_rank >= $translation_rank ? 'title' : 'translation',
            'image' => '',
        ];
    }

    foreach ($matches as $category_id => $word_matches) {
        usort($word_matches, static function (array $left, array $right): int {
            $rank_compare = ((int) ($right['match_rank'] ?? 0)) <=> ((int) ($left['match_rank'] ?? 0));
            if ($rank_compare !== 0) {
                return $rank_compare;
            }
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });
        $matches[$category_id] = array_slice($word_matches, 0, $per_category_limit);
    }

    $final_state = ll_tools_get_wordset_category_search_state($wordset_id);
    if (
        !hash_equals(
            $source_signature,
            ll_tools_wordset_category_search_dependency_signature($wordset_id)
        )
        || !ll_tools_wordset_category_search_state_is_ready(
            $wordset_id,
            $source_signature,
            $final_state
        )
        || !hash_equals(
            $published_generation,
            (string) ($final_state['published_generation'] ?? '')
        )
    ) {
        $complete = false;
        return [];
    }

    return $matches;
}

/**
 * Remove durable rows and coordinator state after a wordset is deleted.
 */
function ll_tools_wordset_category_search_cleanup_deleted_wordset(
    $term_id,
    $term_taxonomy_id,
    $taxonomy
): void {
    global $wpdb;

    if ((string) $taxonomy !== 'wordset') {
        return;
    }
    $wordset_id = max(0, (int) $term_id);
    if ($wordset_id <= 0) {
        return;
    }

    if (ll_tools_wordset_category_search_table_exists()) {
        $wpdb->delete(
            ll_tools_wordset_category_search_table_name(),
            ['wordset_id' => $wordset_id],
            ['%d']
        );
    }
    delete_option(ll_tools_wordset_category_search_state_option($wordset_id));
    delete_option(ll_tools_wordset_category_search_lock_option($wordset_id));
    wp_clear_scheduled_hook(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$wordset_id]);
}
add_action(
    'deleted_term',
    'll_tools_wordset_category_search_cleanup_deleted_wordset',
    10,
    3
);
