<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_VERSION')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_VERSION', '1');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION', 'll_tools_image_match_index_version');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION', 'll_tools_image_match_index_exists');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION', 'll_tools_image_match_index_state');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_HOOK')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_HOOK', 'll_tools_image_match_index_rebuild_batch');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION', 'll_tools_image_match_index_rebuild_lock');
}
if (!defined('LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY')) {
    define('LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY', '_ll_image_match_index_hash');
}

function ll_tools_image_match_index_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'll_image_match_index';
}

function ll_tools_image_match_index_table_exists(bool $refresh = false): bool {
    static $cached = null;
    global $wpdb;

    if (!$refresh && is_bool($cached)) {
        return $cached;
    }
    if (!$refresh) {
        $stored = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, '');
        if ($stored === '1') {
            $cached = true;
            return true;
        }
        if ($stored === '0') {
            $cached = false;
            return false;
        }
    }

    $table = ll_tools_image_match_index_table_name();
    $cached = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    update_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, $cached ? '1' : '0', false);
    return $cached;
}

function ll_tools_install_image_match_index_schema(): void {
    global $wpdb;

    $table = ll_tools_image_match_index_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        image_id bigint(20) unsigned NOT NULL,
        lookup_kind varchar(12) NOT NULL,
        lookup_value varchar(40) NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_image_lookup (image_id, lookup_kind, lookup_value),
        KEY idx_kind_value_image (lookup_kind, lookup_value, image_id),
        KEY idx_image (image_id)
    ) {$charset_collate};";
    dbDelta($sql);
    if (ll_tools_image_match_index_table_exists(true)) {
        update_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, LL_TOOLS_IMAGE_MATCH_INDEX_VERSION, false);
    }
}

function ll_tools_image_match_normalize_title(string $value): string {
    $value = strtolower(wp_strip_all_tags($value));
    $value = (string) preg_replace('/[._\-]+/u', ' ', $value);
    $value = (string) preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function ll_tools_image_match_clean_image_title(string $value): string {
    return (string) preg_replace('/_\d+$/', '', $value);
}

/**
 * @return array{exact:array<int,string>,token:array<int,string>,gram:array<int,string>}
 */
function ll_tools_image_match_index_keys(string $value, bool $image_title = false): array {
    if ($image_title) {
        $value = ll_tools_image_match_clean_image_title($value);
    }
    $normalized = ll_tools_image_match_normalize_title($value);
    if ($normalized === '') {
        return ['exact' => [], 'token' => [], 'gram' => []];
    }

    preg_match_all('/[[:alnum:]]{2,}/u', $normalized, $matches);
    $tokens = array_values(array_unique(array_map('strval', (array) ($matches[0] ?? []))));
    $token_cap = max(1, min(32, (int) apply_filters('ll_tools_image_match_index_token_cap', 16)));
    $tokens = array_slice($tokens, 0, $token_cap);

    $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $characters = is_array($characters) ? $characters : [];
    $grams = [];
    $gram_cap = max(1, min(128, (int) apply_filters('ll_tools_image_match_index_gram_cap', 64)));
    $character_count = count($characters);
    for ($index = 0; $index + 2 < $character_count && count($grams) < $gram_cap; $index++) {
        $grams[sha1($characters[$index] . $characters[$index + 1] . $characters[$index + 2])] = true;
    }

    return [
        'exact' => [sha1($normalized)],
        'token' => array_values(array_unique(array_map('sha1', $tokens))),
        'gram' => array_keys($grams),
    ];
}

function ll_tools_image_match_index_delete_post_rows(int $image_id): void {
    global $wpdb;
    if ($image_id <= 0 || !ll_tools_image_match_index_table_exists()) {
        return;
    }
    $wpdb->delete(ll_tools_image_match_index_table_name(), ['image_id' => $image_id], ['%d']);
}

function ll_tools_image_match_index_post_has_rows(int $image_id): bool {
    global $wpdb;
    if ($image_id <= 0 || !ll_tools_image_match_index_table_exists()) {
        return false;
    }
    $table = ll_tools_image_match_index_table_name();
    return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE image_id = %d LIMIT 1", $image_id));
}

function ll_tools_image_match_index_sync_post(int $image_id, bool $force = false): bool {
    global $wpdb;
    if ($image_id <= 0) {
        return false;
    }
    if (!ll_tools_image_match_index_table_exists()) {
        ll_tools_install_image_match_index_schema();
        if (!ll_tools_image_match_index_table_exists()) {
            return false;
        }
    }

    $post = get_post($image_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'word_images' || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
        ll_tools_image_match_index_delete_post_rows($image_id);
        delete_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY);
        return false;
    }

    $keys = ll_tools_image_match_index_keys((string) $post->post_title, true);
    $hash = sha1(wp_json_encode($keys));
    if (
        !$force
        && hash_equals((string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true), $hash)
        && ll_tools_image_match_index_post_has_rows($image_id)
    ) {
        return true;
    }

    $table = ll_tools_image_match_index_table_name();
    $wpdb->delete($table, ['image_id' => $image_id], ['%d']);
    $row_placeholders = [];
    $args = [];
    foreach ($keys as $kind => $values) {
        foreach ($values as $value) {
            $row_placeholders[] = '(%d, %s, %s)';
            $args[] = $image_id;
            $args[] = (string) $kind;
            $args[] = (string) $value;
        }
    }

    if ($row_placeholders === []) {
        update_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, $hash);
        return true;
    }

    $sql = "INSERT IGNORE INTO {$table} (image_id, lookup_kind, lookup_value) VALUES " . implode(', ', $row_placeholders);
    $inserted = $wpdb->query($wpdb->prepare($sql, $args));
    if ($inserted === false) {
        return false;
    }

    update_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, $hash);
    return true;
}

function ll_tools_image_match_index_sync_post_on_save(int $post_id, WP_Post $post): void {
    if ($post->post_type === 'word_images' && !wp_is_post_revision($post_id)) {
        ll_tools_image_match_index_sync_post($post_id);
    }
}
add_action('save_post_word_images', 'll_tools_image_match_index_sync_post_on_save', 20, 2);

function ll_tools_image_match_index_delete_post_on_delete(int $post_id, WP_Post $post): void {
    if ($post->post_type === 'word_images') {
        ll_tools_image_match_index_delete_post_rows($post_id);
    }
}
add_action('before_delete_post', 'll_tools_image_match_index_delete_post_on_delete', 10, 2);

/**
 * @return array{status:string,last_id:int,processed:int,started_at:string,completed_at:string}
 */
function ll_tools_image_match_index_state(): array {
    $raw = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, []);
    $status = (string) ($raw['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'running', 'completed'], true)) {
        $status = 'pending';
    }
    return [
        'status' => $status,
        'last_id' => max(0, (int) ($raw['last_id'] ?? 0)),
        'processed' => max(0, (int) ($raw['processed'] ?? 0)),
        'started_at' => trim((string) ($raw['started_at'] ?? '')),
        'completed_at' => trim((string) ($raw['completed_at'] ?? '')),
    ];
}

function ll_tools_image_match_index_update_state(array $state): array {
    $sanitized = [
        'status' => in_array((string) ($state['status'] ?? ''), ['pending', 'running', 'completed'], true)
            ? (string) $state['status']
            : 'pending',
        'last_id' => max(0, (int) ($state['last_id'] ?? 0)),
        'processed' => max(0, (int) ($state['processed'] ?? 0)),
        'started_at' => trim((string) ($state['started_at'] ?? '')),
        'completed_at' => trim((string) ($state['completed_at'] ?? '')),
    ];
    update_option(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, $sanitized, false);
    return $sanitized;
}

function ll_tools_image_match_index_schedule_rebuild(bool $reset = false): void {
    if ($reset) {
        ll_tools_image_match_index_update_state([
            'status' => 'pending',
            'last_id' => 0,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
        ]);
    }
    if (!wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK)) {
        wp_schedule_single_event(time() + 5, LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);
    }
}

function ll_tools_image_match_index_maybe_upgrade(): void {
    $installed = (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, '');
    if ($installed === LL_TOOLS_IMAGE_MATCH_INDEX_VERSION && ll_tools_image_match_index_table_exists()) {
        return;
    }
    ll_tools_install_image_match_index_schema();
    ll_tools_image_match_index_schedule_rebuild(true);
}
add_action('init', 'll_tools_image_match_index_maybe_upgrade', 14);

function ll_tools_image_match_index_batch_size(int $requested = 0): int {
    if ($requested > 0) {
        return min(200, $requested);
    }
    return max(1, min(200, (int) apply_filters('ll_tools_image_match_index_batch_size', 50)));
}

function ll_tools_image_match_index_acquire_rebuild_lock(): string {
    $existing = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION, null);
    $created_at = is_array($existing) ? (int) ($existing['created_at'] ?? 0) : 0;
    if ($existing !== null && (!is_array($existing) || $created_at <= 0 || $created_at + 300 < time())) {
        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION);
    }

    $token = wp_generate_uuid4();
    $acquired = add_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION, [
        'token' => $token,
        'created_at' => time(),
    ], '', false);
    return $acquired ? $token : '';
}

function ll_tools_image_match_index_release_rebuild_lock(string $token): void {
    $existing = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION, []);
    if ($token !== '' && is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION);
    }
}

/**
 * @return array{status:string,last_id:int,processed:int,started_at:string,completed_at:string,batch:int}
 */
function ll_tools_image_match_index_process_rebuild_batch(int $requested = 0): array {
    global $wpdb;
    $state = ll_tools_image_match_index_state();
    if ($state['status'] === 'completed') {
        return array_merge($state, ['batch' => 0]);
    }

    $lock = ll_tools_image_match_index_acquire_rebuild_lock();
    if ($lock === '') {
        return array_merge($state, ['batch' => 0]);
    }

    try {
        $batch_size = ll_tools_image_match_index_batch_size($requested);
        $sql = $wpdb->prepare(
            "/* ll_tools_image_match_rebuild */
             SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status NOT IN (%s, %s)
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            'word_images',
            'trash',
            'auto-draft',
            $state['last_id'],
            $batch_size + 1
        );
        $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($sql))));
        $has_more = count($ids) > $batch_size;
        $ids = array_slice($ids, 0, $batch_size);
        if ($state['started_at'] === '') {
            $state['started_at'] = gmdate('c');
        }
        $state['status'] = 'running';
        $batch_processed = 0;
        foreach ($ids as $image_id) {
            if (!ll_tools_image_match_index_sync_post($image_id, true)) {
                $has_more = true;
                break;
            }
            $state['last_id'] = $image_id;
            $state['processed']++;
            $batch_processed++;
        }
        if (!$has_more) {
            $state['status'] = 'completed';
            $state['completed_at'] = gmdate('c');
        }
        $state = ll_tools_image_match_index_update_state($state);
        if ($has_more) {
            ll_tools_image_match_index_schedule_rebuild();
        }
        return array_merge($state, ['batch' => $batch_processed]);
    } finally {
        ll_tools_image_match_index_release_rebuild_lock($lock);
    }
}
add_action(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK, 'll_tools_image_match_index_process_rebuild_batch');

function ll_tools_image_match_index_prime_once(): void {
    static $primed = false;
    if ($primed) {
        return;
    }
    $primed = true;
    if (ll_tools_image_match_index_state()['status'] === 'completed') {
        return;
    }
    $inline_size = max(0, min(25, (int) apply_filters('ll_tools_image_match_index_inline_batch_size', 10)));
    if ($inline_size > 0) {
        ll_tools_image_match_index_process_rebuild_batch($inline_size);
    } else {
        ll_tools_image_match_index_schedule_rebuild();
    }
}

function ll_tools_image_match_index_sql_in(array $values, string $placeholder = '%d'): string {
    return implode(', ', array_fill(0, count($values), $placeholder));
}

/**
 * Return a relevance-ordered, hard-capped image candidate set.
 *
 * @return array<int,int>
 */
function ll_tools_image_match_index_candidate_ids(string $search, array $category_ids, array $wordset_ids = []): array {
    global $wpdb;

    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
    $wordset_ids = array_values(array_unique(array_filter(array_map('intval', $wordset_ids))));
    if ($category_ids === []) {
        return [];
    }
    if (!ll_tools_image_match_index_table_exists()) {
        ll_tools_install_image_match_index_schema();
        if (!ll_tools_image_match_index_table_exists()) {
            return [];
        }
        ll_tools_image_match_index_schedule_rebuild(true);
    }
    ll_tools_image_match_index_prime_once();

    $keys = ll_tools_image_match_index_keys($search);
    $match_clauses = [];
    $match_args = [];
    foreach ($keys as $kind => $values) {
        if ($values === []) {
            continue;
        }
        $match_clauses[] = '(match_idx.lookup_kind = %s AND match_idx.lookup_value IN ('
            . ll_tools_image_match_index_sql_in($values, '%s') . '))';
        $match_args[] = (string) $kind;
        $match_args = array_merge($match_args, $values);
    }
    if ($match_clauses === []) {
        return [];
    }

    $limit = max(1, min(250, (int) apply_filters('ll_tools_image_match_index_candidate_limit', 100)));
    $category_placeholders = ll_tools_image_match_index_sql_in($category_ids);
    $owner_meta_key = defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')
        ? LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY
        : 'll_wordset_owner_id';

    $owner_join = '';
    $owner_where = '';
    $args = [];
    if ($wordset_ids !== []) {
        $owner_join = "LEFT JOIN {$wpdb->postmeta} owner_meta
                         ON owner_meta.post_id = p.ID
                        AND owner_meta.meta_key = %s";
        $owner_where = "AND (
            owner_meta.meta_id IS NULL
            OR owner_meta.meta_value IN ('', '0')
            OR owner_meta.meta_value IN (" . ll_tools_image_match_index_sql_in($wordset_ids) . ")
        )";
        $args[] = $owner_meta_key;
    }

    $args = array_merge(
        $args,
        ['word_images', 'publish', 'word-category'],
        $category_ids,
        $match_args,
        $wordset_ids !== [] ? $wordset_ids : []
    );
    $table = ll_tools_image_match_index_table_name();
    $match_where = implode(' OR ', $match_clauses);
    $sql = "/* ll_tools_image_match_candidates */
        SELECT p.ID,
               COUNT(DISTINCT CASE WHEN match_idx.lookup_kind = 'exact' THEN match_idx.lookup_value END) AS exact_hits,
               COUNT(DISTINCT CASE WHEN match_idx.lookup_kind = 'token' THEN match_idx.lookup_value END) AS token_hits,
               COUNT(DISTINCT CASE WHEN match_idx.lookup_kind = 'gram' THEN match_idx.lookup_value END) AS gram_hits
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} category_rel ON category_rel.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} category_tt ON category_tt.term_taxonomy_id = category_rel.term_taxonomy_id
        INNER JOIN {$table} match_idx ON match_idx.image_id = p.ID
        {$owner_join}
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND category_tt.taxonomy = %s
          AND category_tt.term_id IN ({$category_placeholders})
          AND ({$match_where})
          {$owner_where}
        GROUP BY p.ID, p.post_title
        ORDER BY exact_hits DESC, token_hits DESC, gram_hits DESC, p.post_title ASC, p.ID ASC
        LIMIT {$limit}";
    $prepared = $wpdb->prepare($sql, $args);
    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($prepared))));
    return apply_filters('ll_tools_image_match_index_candidate_ids', $ids, $search, $category_ids, $wordset_ids);
}
