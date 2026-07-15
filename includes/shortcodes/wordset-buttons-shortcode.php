<?php
// /includes/shortcodes/wordset-buttons-shortcode.php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_buttons_shortcode_tags(): array {
    return ['wordset_buttons', 'll_wordset_buttons'];
}

function ll_tools_wordset_buttons_shortcode_maybe_enqueue_assets(): void {
    if (is_admin() || !is_singular()) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || !isset($post->post_content)) {
        return;
    }

    $content = (string) $post->post_content;
    if ($content === '') {
        return;
    }

    $has_shortcode = false;
    foreach (ll_tools_wordset_buttons_shortcode_tags() as $tag) {
        if (has_shortcode($content, $tag)) {
            $has_shortcode = true;
            break;
        }
    }

    if (!$has_shortcode) {
        return;
    }

    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    if (function_exists('ll_tools_wordset_page_enqueue_styles')) {
        ll_tools_wordset_page_enqueue_styles();
    }
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_buttons_shortcode_maybe_enqueue_assets');

function ll_tools_wordset_buttons_shortcode_is_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return !in_array($normalized, ['0', 'false', 'no', 'off', ''], true);
}

function ll_tools_wordset_buttons_shortcode_cache_ttl(): int {
    $ttl = defined('LL_TOOLS_WORDSET_BUTTONS_SHORTCODE_CACHE_TTL')
        ? (int) constant('LL_TOOLS_WORDSET_BUTTONS_SHORTCODE_CACHE_TTL')
        : DAY_IN_SECONDS;

    return max(60, (int) apply_filters('ll_tools_wordset_buttons_shortcode_cache_ttl', $ttl));
}

function ll_tools_wordset_buttons_shortcode_stale_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_wordset_buttons_shortcode_stale_ttl', HOUR_IN_SECONDS);
    return min(DAY_IN_SECONDS, max(60, $ttl));
}

function ll_tools_wordset_buttons_shortcode_cache_enabled(): bool {
    if (is_admin() || is_user_logged_in()) {
        return false;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }
    if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return false;
    }
    if (is_preview() || (function_exists('is_customize_preview') && is_customize_preview())) {
        return false;
    }

    return (bool) apply_filters('ll_tools_wordset_buttons_shortcode_cache_enabled', true);
}

function ll_tools_wordset_buttons_shortcode_generation_epoch(): int {
    if (function_exists('ll_tools_read_option_epoch')) {
        return max(1, (int) ll_tools_read_option_epoch('ll_tools_wordset_buttons_generation_epoch'));
    }
    return max(1, (int) get_option('ll_tools_wordset_buttons_generation_epoch', 1));
}

function ll_tools_bump_wordset_buttons_shortcode_generation_epoch(): int {
    if (function_exists('ll_tools_atomic_increment_option_epoch')) {
        return max(1, (int) ll_tools_atomic_increment_option_epoch('ll_tools_wordset_buttons_generation_epoch'));
    }
    $next = ll_tools_wordset_buttons_shortcode_generation_epoch() + 1;
    update_option('ll_tools_wordset_buttons_generation_epoch', $next, false);
    return $next;
}

function ll_tools_wordset_buttons_shortcode_cache_key(
    array $atts,
    string $tag = '',
    string $plugin_version = ''
): string {
    $hide_empty = ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0';
    $extra_classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim((string) ($atts['class'] ?? ''))) ?: []));
    sort($extra_classes, SORT_STRING);

    $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
        ? max(1, (int) ll_tools_get_wordset_cache_epoch())
        : 1;
    $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
        ? max(1, (int) ll_tools_get_category_cache_epoch())
        : 1;
    $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
        ? ll_tools_get_quiz_content_cache_epoch()
        : (string) $category_epoch;

    $payload = [
        'schema' => 5,
        'plugin_version' => $plugin_version !== ''
            ? $plugin_version
            : (defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : ''),
        'site' => home_url('/'),
        'locale' => function_exists('get_locale') ? (string) get_locale() : '',
        'wordset_epoch' => $wordset_epoch,
        'category_epoch' => $category_epoch,
        'quiz_content_epoch' => $quiz_content_epoch,
        'buttons_generation_epoch' => ll_tools_wordset_buttons_shortcode_generation_epoch(),
        'tag' => sanitize_key($tag !== '' ? $tag : 'll_wordset_buttons'),
        'atts' => [
            'class' => $extra_classes,
            'hide_empty' => $hide_empty,
        ],
    ];

    return 'll_ws_buttons_' . md5((string) wp_json_encode($payload));
}

/**
 * Key for the most recent complete anonymous render.
 *
 * Quiz/content and plugin-version changes intentionally do not rotate this
 * key. The payload is a short-lived bridge while the exact new generation is
 * rebuilt in bounded batches. Structural epochs and an explicit markup schema
 * remain part of the key so visibility, labels, URLs, and presentation changes
 * can never reuse an incompatible render.
 */
function ll_tools_wordset_buttons_shortcode_stale_key(array $atts, string $tag = ''): string {
    $hide_empty = ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0';
    $extra_classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim((string) ($atts['class'] ?? ''))) ?: []));
    sort($extra_classes, SORT_STRING);

    $payload = [
        'schema' => 2,
        'markup_schema' => 1,
        'site' => home_url('/'),
        'locale' => function_exists('get_locale') ? (string) get_locale() : '',
        'wordset_epoch' => function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1,
        'category_epoch' => function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1,
        'tag' => sanitize_key($tag !== '' ? $tag : 'll_wordset_buttons'),
        'atts' => [
            'class' => $extra_classes,
            'hide_empty' => $hide_empty,
        ],
    ];

    return 'll_ws_buttons_lkg_' . md5((string) wp_json_encode($payload));
}

/**
 * One-release bridge to the last complete 6.6.74 exact anonymous render.
 *
 * 6.6.75 gives last-known-good markup a version-independent key. Reading the
 * prior exact cache prevents a blank response while the first user-scoped
 * count generation is rebuilt. This bridge never writes and closes after
 * 6.6.75.
 */
function ll_tools_wordset_buttons_shortcode_previous_version_bridge_enabled(string $current_version = ''): bool {
    if ($current_version === '') {
        $current_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    }

    return $current_version === '6.6.75';
}

function ll_tools_wordset_buttons_shortcode_previous_version_cache_get(array $atts, string $tag = ''): string {
    if (!ll_tools_wordset_buttons_shortcode_previous_version_bridge_enabled()) {
        return '';
    }

    $previous_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag, '6.6.74');
    $html = get_transient($previous_key);

    return is_string($html) && strpos($html, 'll-wordset-buttons-shortcode__button') !== false
        ? $html
        : '';
}

/**
 * Read the complete anonymous exact render for the current release.
 *
 * Logged-in renders never write this cache, so it is a safe public subset when
 * an authorization-specific count generation is still incomplete.
 */
function ll_tools_wordset_buttons_shortcode_anonymous_cache_get(array $atts, string $tag = ''): string {
    $anonymous_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag);
    $html = get_transient($anonymous_key);

    return is_string($html) && strpos($html, 'll-wordset-buttons-shortcode__button') !== false
        ? $html
        : '';
}

function ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled(string $current_version = ''): bool {
    if ($current_version === '') {
        $current_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    }

    return $current_version !== ''
        && version_compare($current_version, '6.6.72', '>=')
        && version_compare($current_version, '6.6.74', '<=');
}

/**
 * Finite migration bridge to the last complete schema-3 anonymous render.
 *
 * 6.6.72 replaced this key with schema 4. Reading (never writing) the 6.6.71
 * key avoids a cold blank response during the first bounded rebuild. The
 * bridge closes after 6.6.74, and every structural/request dimension still
 * has to match exactly.
 */
function ll_tools_wordset_buttons_shortcode_legacy_cache_key(
    array $atts,
    string $tag = '',
    string $current_version = ''
): string {
    if (!ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled($current_version)) {
        return '';
    }

    $hide_empty = ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0';
    $extra_classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim((string) ($atts['class'] ?? ''))) ?: []));
    sort($extra_classes, SORT_STRING);

    $payload = [
        'schema' => 3,
        'plugin_version' => '6.6.71',
        'site' => home_url('/'),
        'locale' => function_exists('get_locale') ? (string) get_locale() : '',
        'wordset_epoch' => function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1,
        'category_epoch' => function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1,
        'tag' => sanitize_key($tag !== '' ? $tag : 'll_wordset_buttons'),
        'atts' => [
            'class' => $extra_classes,
            'hide_empty' => $hide_empty,
        ],
    ];

    return 'll_ws_buttons_' . md5((string) wp_json_encode($payload));
}

function ll_tools_wordset_buttons_shortcode_legacy_cache_get(
    array $atts,
    string $tag = '',
    string $current_version = ''
): string {
    $legacy_key = ll_tools_wordset_buttons_shortcode_legacy_cache_key($atts, $tag, $current_version);
    if ($legacy_key === '') {
        return '';
    }

    $html = get_transient($legacy_key);
    return is_string($html) && strpos($html, 'll-wordset-buttons-shortcode__button') !== false
        ? $html
        : '';
}

function ll_tools_wordset_buttons_shortcode_stale_get(string $key): string {
    $key = sanitize_key($key);
    if ($key === '') {
        return '';
    }

    $payload = get_transient($key);
    if (!is_array($payload) || (int) ($payload['schema'] ?? 0) !== 1) {
        return '';
    }

    $stored_at = (int) ($payload['stored_at'] ?? 0);
    $html = isset($payload['html']) && is_string($payload['html']) ? $payload['html'] : '';
    if (
        $stored_at <= 0
        || $stored_at < time() - ll_tools_wordset_buttons_shortcode_stale_ttl()
        || $html === ''
        || strpos($html, 'll-wordset-buttons-shortcode__button') === false
    ) {
        return '';
    }

    return $html;
}

function ll_tools_wordset_buttons_shortcode_stale_set(string $key, string $html): void {
    $key = sanitize_key($key);
    if ($key === '' || $html === '' || strpos($html, 'll-wordset-buttons-shortcode__button') === false) {
        return;
    }

    set_transient($key, [
        'schema' => 1,
        'stored_at' => time(),
        'html' => $html,
    ], ll_tools_wordset_buttons_shortcode_stale_ttl());
}

function ll_tools_wordset_buttons_shortcode_cache_record_key(string $key): void {
    $key = sanitize_key($key);
    if ($key === '') {
        return;
    }

    $keys = get_option('ll_tools_wordset_buttons_shortcode_cache_keys', []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
        update_option('ll_tools_wordset_buttons_shortcode_cache_keys', array_slice(array_values(array_unique($keys)), -50), false);
    }
}

function ll_tools_wordset_buttons_shortcode_cache_set(string $key, string $html): void {
    $key = sanitize_key($key);
    if ($key === '' || $html === '') {
        return;
    }

    set_transient($key, $html, ll_tools_wordset_buttons_shortcode_cache_ttl());
    ll_tools_wordset_buttons_shortcode_cache_record_key($key);
}

function ll_tools_purge_wordset_buttons_shortcode_cache(): int {
    ll_tools_bump_wordset_buttons_shortcode_generation_epoch();

    $keys = get_option('ll_tools_wordset_buttons_shortcode_cache_keys', []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];

    $deleted = 0;
    foreach ($keys as $key) {
        if (delete_transient($key)) {
            $deleted++;
        }
    }
    delete_option('ll_tools_wordset_buttons_shortcode_cache_keys');

    $state_keys = get_option('ll_tools_wordset_buttons_shortcode_state_keys', []);
    $state_keys = is_array($state_keys) ? array_values(array_filter(array_map('sanitize_key', $state_keys))) : [];
    foreach ($state_keys as $state_key) {
        delete_option($state_key);
    }
    delete_option('ll_tools_wordset_buttons_shortcode_state_keys');

    $lock_keys = get_option('ll_tools_wordset_buttons_shortcode_lock_keys', []);
    $lock_keys = is_array($lock_keys) ? array_values(array_filter(array_map('sanitize_key', $lock_keys))) : [];
    foreach ($lock_keys as $lock_key) {
        delete_option($lock_key);
    }
    delete_option('ll_tools_wordset_buttons_shortcode_lock_keys');

    return $deleted;
}

function ll_tools_wordset_buttons_shortcode_record_state_key(string $key): void {
    $key = sanitize_key($key);
    if ($key === '') {
        return;
    }

    $keys = get_option('ll_tools_wordset_buttons_shortcode_state_keys', []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];
    $keys = array_values(array_unique($keys));
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
    }
    $evicted = count($keys) > 50 ? array_slice($keys, 0, count($keys) - 50) : [];
    $keys = array_slice($keys, -50);
    update_option('ll_tools_wordset_buttons_shortcode_state_keys', $keys, false);

    $removed_lock_keys = [];
    foreach ($evicted as $evicted_state_key) {
        delete_option($evicted_state_key);
        if (function_exists('ll_tools_wordset_button_count_lock_option')) {
            $evicted_lock_key = ll_tools_wordset_button_count_lock_option($evicted_state_key);
            delete_option($evicted_lock_key);
            $removed_lock_keys[] = $evicted_lock_key;
        }
    }
    if (!empty($removed_lock_keys)) {
        $lock_keys = get_option('ll_tools_wordset_buttons_shortcode_lock_keys', []);
        $lock_keys = is_array($lock_keys) ? array_values(array_filter(array_map('sanitize_key', $lock_keys))) : [];
        $lock_keys = array_values(array_diff($lock_keys, $removed_lock_keys));
        if (empty($lock_keys)) {
            delete_option('ll_tools_wordset_buttons_shortcode_lock_keys');
        } else {
            update_option('ll_tools_wordset_buttons_shortcode_lock_keys', $lock_keys, false);
        }
    }
}

function ll_tools_purge_wordset_buttons_shortcode_cache_once(): int {
    static $did_purge = false;
    if ($did_purge) {
        return 0;
    }

    $did_purge = true;
    return ll_tools_purge_wordset_buttons_shortcode_cache();
}

function ll_tools_wordset_buttons_shortcode_purge_on_post_change($post_id = 0): void {
    $post_type = $post_id ? get_post_type((int) $post_id) : '';
    if ($post_type !== 'll_vocab_lesson') {
        return;
    }

    ll_tools_purge_wordset_buttons_shortcode_cache_once();
}
add_action('save_post_ll_vocab_lesson', 'll_tools_wordset_buttons_shortcode_purge_on_post_change', 30, 1);
add_action('before_delete_post', 'll_tools_wordset_buttons_shortcode_purge_on_post_change', 30, 1);

function ll_tools_wordset_button_normalize_wordset_ids(array $wordset_ids): array {
    $wordset_ids = array_values(array_unique(array_filter(array_map('intval', $wordset_ids), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    })));
    sort($wordset_ids, SORT_NUMERIC);
    return $wordset_ids;
}

function ll_tools_wordset_buttons_shortcode_record_lock_key(string $key): void {
    $key = sanitize_key($key);
    if ($key === '') {
        return;
    }

    $keys = get_option('ll_tools_wordset_buttons_shortcode_lock_keys', []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];
    $keys = array_values(array_unique($keys));
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
    }
    $evicted = count($keys) > 50 ? array_slice($keys, 0, count($keys) - 50) : [];
    $keys = array_slice($keys, -50);
    update_option('ll_tools_wordset_buttons_shortcode_lock_keys', $keys, false);
    foreach ($evicted as $evicted_lock_key) {
        delete_option($evicted_lock_key);
    }
}

function ll_tools_wordset_button_quiz_min_word_count(): int {
    $min_word_count = defined('LL_TOOLS_MIN_WORDS_PER_QUIZ')
        ? (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)
        : 5;
    return max(1, $min_word_count);
}

function ll_tools_wordset_button_count_builder_schema(): int {
    return max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_count_builder_schema',
        2
    ));
}

function ll_tools_wordset_button_eligibility_batch_size(): int {
    return min(25, max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_eligibility_batch_size',
        8
    )));
}

function ll_tools_wordset_button_counts_generation_key(array $wordset_ids, int $min_word_count): string {
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    $payload = [
        'schema' => 2,
        'builder_schema' => ll_tools_wordset_button_count_builder_schema(),
        'wordsets' => $wordset_ids,
        'min' => max(1, $min_word_count),
        'wordset_epoch' => function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1,
        'category_epoch' => function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1,
        'quiz_content_epoch' => function_exists('ll_tools_get_quiz_content_cache_epoch')
            ? ll_tools_get_quiz_content_cache_epoch($wordset_ids)
            : 'unavailable',
        'buttons_generation_epoch' => ll_tools_wordset_buttons_shortcode_generation_epoch(),
        // Category and wordset grants can differ even when the visible wordset
        // ID vector is identical, so the authorization scope must remain exact.
        'user_id' => (int) get_current_user_id(),
    ];

    return md5((string) wp_json_encode($payload));
}

function ll_tools_wordset_button_counts_state_key(string $generation_key): string {
    return 'll_ws_button_counts_' . md5($generation_key);
}

function ll_tools_wordset_button_count_state_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_wordset_buttons_shortcode_count_state_ttl', 6 * HOUR_IN_SECONDS);
    return min(DAY_IN_SECONDS, max(5 * MINUTE_IN_SECONDS, $ttl));
}

function ll_tools_wordset_button_count_lock_option(string $state_key): string {
    return 'll_ws_button_lock_' . md5($state_key);
}

function ll_tools_wordset_button_count_lock_ttl(): int {
    return min(300, max(30, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_count_lock_ttl',
        60
    )));
}

function ll_tools_acquire_wordset_button_count_lock(string $state_key): string {
    global $wpdb;

    $option_name = ll_tools_wordset_button_count_lock_option($state_key);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $now = time();
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(32, false, false);
        $payload = $token . '|' . ($now + ll_tools_wordset_button_count_lock_ttl());
        if (add_option($option_name, $payload, '', 'no')) {
            ll_tools_wordset_buttons_shortcode_record_lock_key($option_name);
            return $token;
        }

        $existing = (string) get_option($option_name, '');
        $parts = explode('|', $existing, 2);
        $expires_at = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($existing !== '' && $expires_at >= $now) {
            return '';
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option_name,
            $existing
        ));
        if ((int) $deleted !== 1) {
            return '';
        }
        wp_cache_delete($option_name, 'options');
    }

    return '';
}

function ll_tools_release_wordset_button_count_lock(string $state_key, string $token): void {
    global $wpdb;

    if ($state_key === '' || $token === '') {
        return;
    }

    $option_name = ll_tools_wordset_button_count_lock_option($state_key);
    $existing = (string) get_option($option_name, '');
    if ($existing === '' || strpos($existing, $token . '|') !== 0) {
        return;
    }

    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $option_name,
        $existing
    ));
    if ((int) $deleted === 1) {
        wp_cache_delete($option_name, 'options');
    }
}

/**
 * Discover one bounded, indexed page of lesson posts, then read only that
 * page's exact meta in PHP. This avoids a repeated CAST/GROUP BY scan and makes
 * the durable cursor the lesson post ID.
 *
 * @return array<int,array{lesson_id:int,wordset_id:int,category_id:int,preview_only:bool}>
 */
function ll_tools_get_wordset_button_lesson_pair_batch(
    array $wordset_ids,
    int $after_lesson_id,
    int $limit,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    if (
        empty($wordset_ids)
        || !defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
        || !defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
    ) {
        return [];
    }

    $wpdb->last_error = '';
    $lesson_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID
         FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status = %s
           AND ID > %d
         ORDER BY ID ASC
         LIMIT %d",
        'll_vocab_lesson',
        'publish',
        max(0, $after_lesson_id),
        max(1, $limit)
    ));
    if (!is_array($lesson_ids) || $wpdb->last_error !== '') {
        $complete = false;
        return [];
    }

    $preview_only_meta_key = defined('LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META')
        ? (string) LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META
        : '_ll_tools_vocab_preview_only';
    $rows = [];
    foreach ($lesson_ids as $lesson_id_raw) {
        $lesson_id = (int) $lesson_id_raw;
        if ($lesson_id <= 0) {
            $complete = false;
            return [];
        }

        $wpdb->last_error = '';
        $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $wpdb->last_error = '';
        $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $wpdb->last_error = '';
        $preview_only = (string) get_post_meta($lesson_id, $preview_only_meta_key, true) === '1';
        if ($wpdb->last_error !== '') {
            $complete = false;
            return [];
        }

        $rows[] = [
            'lesson_id' => $lesson_id,
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'preview_only' => $preview_only,
        ];
    }

    return $rows;
}

function ll_tools_wordset_button_now(): int {
    return max(1, (int) apply_filters('ll_tools_wordset_buttons_shortcode_now', time()));
}

function ll_tools_wordset_button_seen_pair_limit(): int {
    return min(5000, max(50, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_seen_pair_limit',
        1000
    )));
}

function ll_tools_wordset_button_state_byte_limit(): int {
    return min(1024 * 1024, max(64 * 1024, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_state_byte_limit',
        256 * 1024
    )));
}

function ll_tools_wordset_button_raw_query_budget(): int {
    return min(10, max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_raw_query_budget',
        8
    )));
}

function ll_tools_wordset_button_raw_row_budget(): int {
    return min(500, max(10, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_raw_row_budget',
        100
    )));
}

function ll_tools_wordset_button_prompt_query_budget(): int {
    return min(10, max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_prompt_query_budget',
        8
    )));
}

function ll_tools_wordset_button_prompt_card_budget(): int {
    return min(100, max(10, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_prompt_card_budget',
        25
    )));
}

function ll_tools_wordset_button_failure_backoff(int $attempt, string $reason): int {
    $attempt = min(10, max(1, $attempt));
    $seconds = min(15 * MINUTE_IN_SECONDS, 30 * (2 ** ($attempt - 1)));
    return min(HOUR_IN_SECONDS, max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_failure_backoff',
        $seconds,
        $attempt,
        sanitize_key($reason)
    )));
}

function ll_tools_get_wordset_button_count_state(string $state_key) {
    $state = get_option($state_key, null);
    if (!is_array($state)) {
        return null;
    }
    if ((int) ($state['expires_at'] ?? 0) < ll_tools_wordset_button_now()) {
        delete_option($state_key);
        return null;
    }
    return $state;
}

function ll_tools_refresh_wordset_button_count_lock(string $state_key, string $token): string {
    global $wpdb;

    if ($state_key === '' || $token === '') {
        return '';
    }
    $lock_key = ll_tools_wordset_button_count_lock_option($state_key);
    $existing = (string) get_option($lock_key, '');
    $parts = explode('|', $existing, 2);
    if (($parts[0] ?? '') !== $token || (int) ($parts[1] ?? 0) < time()) {
        return '';
    }

    $renewed = $token . '|' . (time() + ll_tools_wordset_button_count_lock_ttl());
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND option_value = %s",
        $renewed,
        $lock_key,
        $existing
    ));
    wp_cache_delete($lock_key, 'options');
    return (int) $updated === 1 || (string) get_option($lock_key, '') === $renewed
        ? $renewed
        : '';
}

/**
 * Persist progress only when both the exact lock token and expected state still
 * match. The SQL join fences stale workers that survived a purge or lease loss.
 */
function ll_tools_store_wordset_button_count_state(
    string $state_key,
    array &$state,
    $expected_state,
    string $lock_token
): bool {
    global $wpdb;

    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids((array) ($state['wordset_ids'] ?? []));
    $min_word_count = max(1, (int) ($state['min_word_count'] ?? 0));
    if (
        empty($wordset_ids)
        || (string) ($state['generation'] ?? '') !== ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count)
        || count((array) ($state['seen_pairs'] ?? [])) > ll_tools_wordset_button_seen_pair_limit()
    ) {
        return false;
    }

    $expected_state = is_array($expected_state) ? $expected_state : null;
    $state['schema'] = 2;
    $state['revision'] = max(0, (int) ($expected_state['revision'] ?? 0)) + 1;
    $state['expires_at'] = ll_tools_wordset_button_now() + ll_tools_wordset_button_count_state_ttl();
    $serialized = maybe_serialize($state);
    if (!is_string($serialized) || strlen($serialized) > ll_tools_wordset_button_state_byte_limit()) {
        return false;
    }

    $lock_value = ll_tools_refresh_wordset_button_count_lock($state_key, $lock_token);
    if ($lock_value === '') {
        return false;
    }
    $lock_key = ll_tools_wordset_button_count_lock_option($state_key);
    if ($expected_state === null) {
        $written = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'no'
             FROM {$wpdb->options} lock_option
             WHERE lock_option.option_name = %s
               AND lock_option.option_value = %s
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->options} state_option WHERE state_option.option_name = %s
               )
             LIMIT 1",
            $state_key,
            $serialized,
            $lock_key,
            $lock_value,
            $state_key
        ));
    } else {
        $expected_serialized = maybe_serialize($expected_state);
        $written = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} state_option
             INNER JOIN {$wpdb->options} lock_option
                ON lock_option.option_name = %s AND lock_option.option_value = %s
             SET state_option.option_value = %s, state_option.autoload = 'no'
             WHERE state_option.option_name = %s AND state_option.option_value = %s",
            $lock_key,
            $lock_value,
            $serialized,
            $state_key,
            $expected_serialized
        ));
    }
    wp_cache_delete($state_key, 'options');
    if ($expected_state === null) {
        // The direct fenced INSERT bypasses add_option(), so remove WordPress's
        // remembered negative lookup before verifying the newly created row.
        wp_cache_delete('notoptions', 'options');
    }
    if ((int) $written !== 1 || get_option($state_key, null) !== $state) {
        return false;
    }

    ll_tools_wordset_buttons_shortcode_record_state_key($state_key);
    return true;
}

function ll_tools_schedule_wordset_button_count_refresh(
    array $wordset_ids,
    bool $schedule,
    int $run_at = 0,
    bool $replace = false
): void {
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    if (empty($wordset_ids) || !function_exists('wp_next_scheduled')) {
        return;
    }

    $hook = 'll_tools_refresh_wordset_button_lesson_counts';
    $args = [$wordset_ids, (int) get_current_user_id()];
    if ($schedule) {
        $logical_now = ll_tools_wordset_button_now();
        $delay = $run_at > $logical_now
            ? max(1, $run_at - $logical_now)
            : min(60, max(1, (int) apply_filters(
                'll_tools_wordset_buttons_shortcode_refresh_delay',
                5
            )));
        $scheduled_at = time() + $delay;
        $existing = wp_next_scheduled($hook, $args);
        if ($replace && $existing !== false && function_exists('wp_unschedule_event')) {
            while (($existing = wp_next_scheduled($hook, $args)) !== false) {
                if (!wp_unschedule_event($existing, $hook, $args)) {
                    break;
                }
            }
            $existing = false;
        }
        if ($existing === false && function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event($scheduled_at, $hook, $args);
        }
        return;
    }

    if (!function_exists('wp_unschedule_event')) {
        return;
    }
    while (($timestamp = wp_next_scheduled($hook, $args)) !== false) {
        if (!wp_unschedule_event($timestamp, $hook, $args)) {
            break;
        }
    }
}

/**
 * Resume one exact lesson/category eligibility check inside a shared raw budget.
 */
function ll_tools_process_wordset_button_active_pair(
    array &$state,
    int $min_word_count,
    array &$raw_budget,
    ?bool &$pair_complete = null,
    ?string &$incomplete_reason = null
): bool {
    $pair_complete = false;
    $incomplete_reason = '';
    $active_pair = isset($state['active_pair']) && is_array($state['active_pair'])
        ? $state['active_pair']
        : [];
    $wordset_id = max(0, (int) ($active_pair['wordset_id'] ?? 0));
    $category_id = max(0, (int) ($active_pair['category_id'] ?? 0));
    if ($wordset_id <= 0 || $category_id <= 0) {
        $incomplete_reason = 'invalid_pair';
        return false;
    }

    $scan_context = isset($active_pair['scan']) && is_array($active_pair['scan'])
        ? $active_pair['scan']
        : [];
    $scan_context['schema'] = 1;
    $scan_context['enabled'] = true;
    $scan_context['budget'] = &$raw_budget;
    $eligibility_complete = true;
    $can_generate_quiz = !function_exists('ll_can_category_generate_quiz')
        || ll_can_category_generate_quiz(
            $category_id,
            $min_word_count,
            [$wordset_id],
            $eligibility_complete,
            $scan_context
        );
    do_action(
        'll_tools_wordset_buttons_shortcode_eligibility_pair_scanned',
        $wordset_id,
        $category_id,
        (bool) $eligibility_complete,
        (bool) $can_generate_quiz
    );

    $state['active_pair']['scan'] = [
        'schema' => 1,
        'phase' => sanitize_key((string) ($scan_context['phase'] ?? 'primary')),
        'phases' => isset($scan_context['phases']) && is_array($scan_context['phases'])
            ? $scan_context['phases']
            : [],
    ];
    if (!$eligibility_complete) {
        $incomplete_reason = !empty($scan_context['budget_exhausted'])
            ? 'budget'
            : sanitize_key((string) ($scan_context['failure_reason'] ?? 'eligibility_source'));
        if ($incomplete_reason === '') {
            $incomplete_reason = 'eligibility_source';
        }
        return false;
    }

    $pair_complete = true;
    return (bool) $can_generate_quiz;
}

/**
 * Advance one exact visible-wordset lesson-count generation by one bounded pair page.
 * Incomplete progress is durable but never returned as authoritative counts.
 */
function ll_tools_get_wordset_button_lesson_counts_bounded(array $wordset_ids, ?bool &$complete = null): array {
    $complete = false;
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    if (empty($wordset_ids)) {
        $complete = true;
        return [];
    }

    $min_word_count = ll_tools_wordset_button_quiz_min_word_count();
    $generation_key = ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count);
    $state_key = ll_tools_wordset_button_counts_state_key($generation_key);
    $existing = ll_tools_get_wordset_button_count_state($state_key);
    if (
        is_array($existing)
        && (int) ($existing['schema'] ?? 0) === 2
        && (string) ($existing['generation'] ?? '') === $generation_key
        && ll_tools_wordset_button_normalize_wordset_ids((array) ($existing['wordset_ids'] ?? [])) === $wordset_ids
        && (int) ($existing['min_word_count'] ?? 0) === $min_word_count
        && !empty($existing['complete'])
    ) {
        $counts = [];
        $counts_valid = is_array($existing['counts'] ?? null);
        foreach ($wordset_ids as $wordset_id) {
            if (!array_key_exists($wordset_id, (array) ($existing['counts'] ?? []))) {
                $counts_valid = false;
                break;
            }
            $counts[$wordset_id] = max(0, (int) $existing['counts'][$wordset_id]);
        }
        if (
            $counts_valid
            && ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count) === $generation_key
        ) {
            $complete = true;
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
            return $counts;
        }
    }

    $lock_token = ll_tools_acquire_wordset_button_count_lock($state_key);
    if ($lock_token === '') {
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
        return [];
    }

    try {
        return ll_tools_get_wordset_button_lesson_counts_bounded_unlocked(
            $wordset_ids,
            $state_key,
            $generation_key,
            $lock_token,
            $complete
        );
    } finally {
        ll_tools_release_wordset_button_count_lock($state_key, $lock_token);
    }
}

function ll_tools_get_wordset_button_lesson_counts_bounded_unlocked(
    array $wordset_ids,
    string $state_key,
    string $generation_key,
    string $lock_token,
    ?bool &$complete = null
): array {
    $complete = false;
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    $min_word_count = ll_tools_wordset_button_quiz_min_word_count();
    if (
        empty($wordset_ids)
        || $generation_key !== ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count)
    ) {
        return [];
    }

    $stored_state = ll_tools_get_wordset_button_count_state($state_key);
    $stored_state_valid = is_array($stored_state)
        && (int) ($stored_state['schema'] ?? 0) === 2
        && (string) ($stored_state['generation'] ?? '') === $generation_key
        && ll_tools_wordset_button_normalize_wordset_ids((array) ($stored_state['wordset_ids'] ?? [])) === $wordset_ids
        && (int) ($stored_state['min_word_count'] ?? 0) === $min_word_count;
    if (!$stored_state_valid && is_array($stored_state)) {
        delete_option($state_key);
        $stored_state = null;
    }
    $expected_state = $stored_state_valid ? $stored_state : null;
    $state = $stored_state_valid ? $stored_state : [
        'schema' => 2,
        'generation' => $generation_key,
        'wordset_ids' => $wordset_ids,
        'min_word_count' => $min_word_count,
        'lesson_cursor_id' => 0,
        'counts' => array_fill_keys($wordset_ids, 0),
        'seen_pairs' => [],
        'active_pair' => [],
        'attempts' => 0,
        'next_retry_at' => 0,
        'last_failure_reason' => '',
        'complete' => false,
        'revision' => 0,
    ];

    $counts = array_fill_keys($wordset_ids, 0);
    foreach ((array) ($state['counts'] ?? []) as $wordset_id => $count) {
        $wordset_id = (int) $wordset_id;
        if (array_key_exists($wordset_id, $counts)) {
            $counts[$wordset_id] = max(0, (int) $count);
        }
    }
    $state['counts'] = $counts;
    $state['seen_pairs'] = is_array($state['seen_pairs'] ?? null) ? $state['seen_pairs'] : [];
    $state['active_pair'] = is_array($state['active_pair'] ?? null) ? $state['active_pair'] : [];
    if (count($state['seen_pairs']) > ll_tools_wordset_button_seen_pair_limit()) {
        return [];
    }
    if (!empty($state['complete'])) {
        $complete = true;
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
        return $counts;
    }

    $now = ll_tools_wordset_button_now();
    $next_retry_at = max(0, (int) ($state['next_retry_at'] ?? 0));
    if ($next_retry_at > $now) {
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true, $next_retry_at);
        return [];
    }

    $raw_budget = [
        'max_raw_queries' => ll_tools_wordset_button_raw_query_budget(),
        'max_raw_rows' => ll_tools_wordset_button_raw_row_budget(),
        'raw_queries_used' => 0,
        'raw_rows_used' => 0,
        'max_prompt_queries' => ll_tools_wordset_button_prompt_query_budget(),
        'max_prompt_cards' => ll_tools_wordset_button_prompt_card_budget(),
        'prompt_queries_used' => 0,
        'prompt_cards_used' => 0,
    ];
    $failure_reason = '';
    $progress_only = false;

    if (!empty($state['active_pair'])) {
        $pair_complete = false;
        $pair_reason = '';
        $can_generate = ll_tools_process_wordset_button_active_pair(
            $state,
            $min_word_count,
            $raw_budget,
            $pair_complete,
            $pair_reason
        );
        if ($generation_key !== ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count)) {
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
            return [];
        }
        if (!$pair_complete) {
            $failure_reason = $pair_reason;
            $progress_only = ($pair_reason === 'budget');
        } else {
            $active_wordset_id = (int) ($state['active_pair']['wordset_id'] ?? 0);
            $active_category_id = (int) ($state['active_pair']['category_id'] ?? 0);
            $active_lesson_id = (int) ($state['active_pair']['lesson_id'] ?? 0);
            $pair_key = $active_wordset_id . ':' . $active_category_id;
            if ($can_generate) {
                $state['counts'][$active_wordset_id] = (int) ($state['counts'][$active_wordset_id] ?? 0) + 1;
            }
            $state['seen_pairs'][$pair_key] = 1;
            $state['lesson_cursor_id'] = max((int) ($state['lesson_cursor_id'] ?? 0), $active_lesson_id);
            $state['active_pair'] = [];
        }
    }

    $has_more = true;
    if ($failure_reason === '') {
        $batch_size = ll_tools_wordset_button_eligibility_batch_size();
        $source_complete = true;
        $lesson_rows = ll_tools_get_wordset_button_lesson_pair_batch(
            $wordset_ids,
            max(0, (int) ($state['lesson_cursor_id'] ?? 0)),
            $batch_size + 1,
            $source_complete
        );
        if (!$source_complete) {
            $failure_reason = 'lesson_source';
        } else {
            $has_more = count($lesson_rows) > $batch_size;
            $lesson_rows = array_slice($lesson_rows, 0, $batch_size);
            foreach ($lesson_rows as $row) {
                $lesson_id = max(0, (int) ($row['lesson_id'] ?? 0));
                $wordset_id = max(0, (int) ($row['wordset_id'] ?? 0));
                $category_id = max(0, (int) ($row['category_id'] ?? 0));
                if (
                    $lesson_id <= 0
                    || !empty($row['preview_only'])
                    || !in_array($wordset_id, $wordset_ids, true)
                    || $category_id <= 0
                ) {
                    $state['lesson_cursor_id'] = max((int) ($state['lesson_cursor_id'] ?? 0), $lesson_id);
                    continue;
                }

                $pair_key = $wordset_id . ':' . $category_id;
                if (isset($state['seen_pairs'][$pair_key])) {
                    $state['lesson_cursor_id'] = max((int) ($state['lesson_cursor_id'] ?? 0), $lesson_id);
                    continue;
                }
                if (count($state['seen_pairs']) >= ll_tools_wordset_button_seen_pair_limit()) {
                    $failure_reason = 'state_limit';
                    break;
                }

                $state['active_pair'] = [
                    'lesson_id' => $lesson_id,
                    'wordset_id' => $wordset_id,
                    'category_id' => $category_id,
                    'scan' => [],
                ];
                $pair_complete = false;
                $pair_reason = '';
                $can_generate = ll_tools_process_wordset_button_active_pair(
                    $state,
                    $min_word_count,
                    $raw_budget,
                    $pair_complete,
                    $pair_reason
                );
                if ($generation_key !== ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count)) {
                    ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
                    return [];
                }
                if (!$pair_complete) {
                    $failure_reason = $pair_reason;
                    $progress_only = ($pair_reason === 'budget');
                    break;
                }
                if ($can_generate) {
                    $state['counts'][$wordset_id] = (int) ($state['counts'][$wordset_id] ?? 0) + 1;
                }
                $state['seen_pairs'][$pair_key] = 1;
                $state['lesson_cursor_id'] = $lesson_id;
                $state['active_pair'] = [];
            }
        }
    }

    if ($generation_key !== ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count)) {
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
        return [];
    }

    if ($failure_reason !== '') {
        if ($progress_only) {
            $state['attempts'] = 0;
            $state['next_retry_at'] = 0;
            $state['last_failure_reason'] = '';
        } else {
            $attempts = min(10, max(0, (int) ($state['attempts'] ?? 0)) + 1);
            $state['attempts'] = $attempts;
            $state['last_failure_reason'] = sanitize_key($failure_reason);
            $state['next_retry_at'] = $now + ll_tools_wordset_button_failure_backoff($attempts, $failure_reason);
        }
        $state['complete'] = false;
        if (!ll_tools_store_wordset_button_count_state($state_key, $state, $expected_state, $lock_token)) {
            return [];
        }
        if ($progress_only) {
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
        } else {
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
            ll_tools_schedule_wordset_button_count_refresh(
                $wordset_ids,
                true,
                (int) $state['next_retry_at'],
                true
            );
        }
        return [];
    }

    $state['attempts'] = 0;
    $state['next_retry_at'] = 0;
    $state['last_failure_reason'] = '';
    $state['complete'] = !$has_more && empty($state['active_pair']);
    if (!ll_tools_store_wordset_button_count_state($state_key, $state, $expected_state, $lock_token)) {
        return [];
    }

    if (!$state['complete']) {
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true);
        return [];
    }

    $complete = true;
    ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
    return (array) $state['counts'];
}

function ll_tools_refresh_wordset_button_lesson_counts($wordset_ids = [], ?int $user_id = null): void {
    $previous_user_id = (int) get_current_user_id();
    $worker_user_id = $user_id === null ? $previous_user_id : max(0, $user_id);
    if ($worker_user_id > 0) {
        $worker_user = get_userdata($worker_user_id);
        if (!$worker_user instanceof WP_User || !$worker_user->exists()) {
            return;
        }
    }

    if ($worker_user_id !== $previous_user_id) {
        wp_set_current_user($worker_user_id);
    }
    try {
        $complete = false;
        ll_tools_get_wordset_button_lesson_counts_bounded((array) $wordset_ids, $complete);
    } finally {
        if ($worker_user_id !== $previous_user_id) {
            wp_set_current_user($previous_user_id);
        }
    }
}
add_action('ll_tools_refresh_wordset_button_lesson_counts', 'll_tools_refresh_wordset_button_lesson_counts', 10, 2);

function ll_tools_get_wordset_button_lesson_counts(array $wordset_ids, ?bool &$complete = null): array {
    global $wpdb;

    $complete = true;
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    if (
        empty($wordset_ids)
        || !defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
        || !defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
    ) {
        return [];
    }

    $min_word_count = ll_tools_wordset_button_quiz_min_word_count();
    static $request_cache = [];
    $cache_key = md5((string) wp_json_encode([
        'wordsets' => $wordset_ids,
        'min' => $min_word_count,
        'generation' => ll_tools_wordset_button_counts_generation_key($wordset_ids, $min_word_count),
    ]));
    if (isset($request_cache[$cache_key]) && is_array($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $counts = array_fill_keys($wordset_ids, 0);
    $seen_pairs = [];
    $lesson_cursor_id = 0;
    $page_size = 25;
    while (true) {
        $source_complete = true;
        $rows = ll_tools_get_wordset_button_lesson_pair_batch(
            $wordset_ids,
            $lesson_cursor_id,
            $page_size,
            $source_complete
        );
        if (!$source_complete) {
            $complete = false;
            return [];
        }
        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $lesson_cursor_id = max($lesson_cursor_id, (int) ($row['lesson_id'] ?? 0));
            $wordset_id = (int) ($row['wordset_id'] ?? 0);
            $category_id = (int) ($row['category_id'] ?? 0);
            if (
                !empty($row['preview_only'])
                || !array_key_exists($wordset_id, $counts)
                || $category_id <= 0
            ) {
                continue;
            }
            $pair_key = $wordset_id . ':' . $category_id;
            if (isset($seen_pairs[$pair_key])) {
                continue;
            }
            $seen_pairs[$pair_key] = true;

            $eligibility_complete = true;
            $can_generate_quiz = !function_exists('ll_can_category_generate_quiz')
                || ll_can_category_generate_quiz($category_id, $min_word_count, [$wordset_id], $eligibility_complete);
            if (!$eligibility_complete) {
                $complete = false;
                return [];
            }
            if ($can_generate_quiz) {
                $counts[$wordset_id]++;
            }
        }
        if (count($rows) < $page_size) {
            break;
        }
    }

    $request_cache[$cache_key] = $counts;
    return $counts;
}

function ll_tools_get_wordset_button_items(
    bool $hide_empty = false,
    bool $bounded_counts = false,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wpdb->last_error = '';
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => $hide_empty,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || $wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    if (empty($terms)) {
        return [];
    }

    $visible_term_ids = array_values(array_map('intval', wp_list_pluck($terms, 'term_id')));
    $visibility_complete = true;
    if (function_exists('ll_tools_filter_viewable_wordset_ids')) {
        $visible_term_ids = ll_tools_filter_viewable_wordset_ids(
            $visible_term_ids,
            (int) get_current_user_id(),
            $visibility_complete
        );
    } elseif (function_exists('ll_tools_user_can_view_wordset')) {
        $filtered_term_ids = [];
        foreach ($visible_term_ids as $term_id) {
            $term_complete = true;
            if (ll_tools_user_can_view_wordset($term_id, (int) get_current_user_id(), $term_complete)) {
                $filtered_term_ids[] = $term_id;
            }
            $visibility_complete = $visibility_complete && $term_complete;
        }
        $visible_term_ids = $filtered_term_ids;
    }
    if (!$visibility_complete) {
        $complete = false;
        return [];
    }

    if (empty($visible_term_ids)) {
        return [];
    }

    $counts_complete = true;
    $lesson_counts = $bounded_counts
        ? ll_tools_get_wordset_button_lesson_counts_bounded($visible_term_ids, $counts_complete)
        : ll_tools_get_wordset_button_lesson_counts($visible_term_ids, $counts_complete);
    if (!$counts_complete) {
        $complete = false;
        return [];
    }
    $visible_lookup = array_fill_keys($visible_term_ids, true);
    $items = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $term_id = (int) $term->term_id;
        if ($term_id <= 0 || !isset($visible_lookup[$term_id])) {
            continue;
        }

        $lesson_count = (int) ($lesson_counts[$term_id] ?? 0);
        if ($lesson_count <= 0) {
            continue;
        }

        $items[] = [
            'term' => $term,
            'lesson_count' => $lesson_count,
            'is_private' => function_exists('ll_tools_is_wordset_private') && ll_tools_is_wordset_private($term),
        ];
    }

    if (count($items) > 1) {
        usort($items, static function (array $left, array $right): int {
            $left_count = (int) ($left['lesson_count'] ?? 0);
            $right_count = (int) ($right['lesson_count'] ?? 0);
            if ($left_count !== $right_count) {
                return $right_count <=> $left_count;
            }

            $left_term = $left['term'] ?? null;
            $right_term = $right['term'] ?? null;
            $left_name = ($left_term instanceof WP_Term)
                ? (function_exists('ll_tools_get_wordset_display_name') ? ll_tools_get_wordset_display_name($left_term) : (string) $left_term->name)
                : '';
            $right_name = ($right_term instanceof WP_Term)
                ? (function_exists('ll_tools_get_wordset_display_name') ? ll_tools_get_wordset_display_name($right_term) : (string) $right_term->name)
                : '';

            return strnatcasecmp($left_name, $right_name);
        });
    }

    return $items;
}

function ll_tools_wordset_buttons_shortcode_classes(array $atts, bool $loading = false): array {
    $classes = ['ll-wordset-page', 'll-wordset-page--shortcode', 'll-wordset-buttons-shortcode'];
    if ($loading) {
        $classes[] = 'll-wordset-buttons-shortcode--loading';
    }

    $extra_classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : array_filter(array_map(
            'sanitize_html_class',
            preg_split('/\s+/', trim((string) ($atts['class'] ?? ''))) ?: []
        ));

    return array_values(array_unique(array_merge($classes, $extra_classes)));
}

/**
 * Keep a genuine cold rebuild visible without publishing partial counts.
 */
function ll_tools_wordset_buttons_shortcode_loading_html(array $atts): string {
    $classes = ll_tools_wordset_buttons_shortcode_classes($atts, true);

    ob_start();
    ?>
    <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" aria-busy="true">
        <span class="screen-reader-text"><?php echo esc_html__('Loading categories...', 'll-tools-text-domain'); ?></span>
        <ul class="ll-wordset-buttons-shortcode__list ll-wordset-buttons-shortcode__loading-list" aria-hidden="true">
            <?php for ($index = 0; $index < 3; $index++) : ?>
                <li class="ll-wordset-buttons-shortcode__item ll-wordset-buttons-shortcode__loading-item">
                    <span class="ll-wordset-buttons-shortcode__loading-card">
                        <span class="ll-wordset-buttons-shortcode__loading-line ll-wordset-buttons-shortcode__loading-line--label"></span>
                        <span class="ll-wordset-buttons-shortcode__loading-line ll-wordset-buttons-shortcode__loading-line--count"></span>
                    </span>
                </li>
            <?php endfor; ?>
        </ul>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function ll_tools_wordset_buttons_shortcode($atts = [], $content = null, string $tag = ''): string {
    $atts = shortcode_atts([
        'class' => '',
        'hide_empty' => '0',
    ], $atts, $tag !== '' ? $tag : 'll_wordset_buttons');

    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    if (function_exists('ll_tools_wordset_page_enqueue_styles')) {
        ll_tools_wordset_page_enqueue_styles();
    }

    // The stable LKG is written only by complete anonymous renders, but is a
    // safe public subset for logged-in visitors while their wider scope builds.
    $stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, $tag);
    $cache_key = ll_tools_wordset_buttons_shortcode_cache_enabled()
        ? ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag)
        : '';
    if ($cache_key !== '') {
        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            if (ll_tools_wordset_buttons_shortcode_stale_get($stale_key) === '') {
                ll_tools_wordset_buttons_shortcode_stale_set($stale_key, $cached_html);
            }
            return $cached_html;
        }
    }

    $items_complete = true;
    $items = ll_tools_get_wordset_button_items(
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0'),
        true,
        $items_complete
    );
    if (!$items_complete) {
        // Eligibility work can invalidate structural visibility while a bounded
        // batch is running. Re-read the key after that work so a racing request
        // can never serve markup from the pre-change public scope.
        $stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, $tag);
        $stale_html = ll_tools_wordset_buttons_shortcode_stale_get($stale_key);
        if ($stale_html === '') {
            $stale_html = ll_tools_wordset_buttons_shortcode_anonymous_cache_get($atts, $tag);
        }
        if ($stale_html === '') {
            $stale_html = ll_tools_wordset_buttons_shortcode_previous_version_cache_get($atts, $tag);
        }
        if ($stale_html === '') {
            $stale_html = ll_tools_wordset_buttons_shortcode_legacy_cache_get($atts, $tag);
        }
        return $stale_html !== ''
            ? $stale_html
            : ll_tools_wordset_buttons_shortcode_loading_html($atts);
    }
    if (empty($items)) {
        return '';
    }

    $classes = ll_tools_wordset_buttons_shortcode_classes($atts);

    ob_start();
    ?>
    <div class="<?php echo esc_attr(implode(' ', array_unique($classes))); ?>">
        <ul class="ll-wordset-buttons-shortcode__list">
            <?php foreach ($items as $item) : ?>
                <?php
                $term = $item['term'] ?? null;
                $lesson_count = isset($item['lesson_count']) ? (int) $item['lesson_count'] : 0;
                $is_private = !empty($item['is_private']);
                if (!$term instanceof WP_Term || $lesson_count <= 0) {
                    continue;
                }
                $term_name = function_exists('ll_tools_get_wordset_display_name')
                    ? ll_tools_get_wordset_display_name($term)
                    : (string) $term->name;

                $url = function_exists('ll_tools_get_wordset_page_view_url')
                    ? (string) ll_tools_get_wordset_page_view_url($term)
                    : '';
                if ($url === '') {
                    continue;
                }

                $button_image = function_exists('ll_tools_get_wordset_button_image_preview_data')
                    ? ll_tools_get_wordset_button_image_preview_data($term, 'large', true)
                    : ['attachment_id' => 0, 'url' => ''];
                $button_image_url = trim((string) ($button_image['url'] ?? ''));
                $has_button_image = ($button_image_url !== '');

                $count_label = sprintf(
                    /* translators: %d: number of lesson pages in the word set. */
                    _n('%d lesson', '%d lessons', $lesson_count, 'll-tools-text-domain'),
                    $lesson_count
                );
                $privacy_label = __('Private word set', 'll-tools-text-domain');
                $link_aria_label = $is_private
                    ? sprintf(
                        /* translators: 1: word set name, 2: lesson count label. */
                        __('%1$s, private word set, %2$s', 'll-tools-text-domain'),
                        $term_name,
                        $count_label
                    )
                    : sprintf(
                        /* translators: 1: word set name, 2: lesson count label. */
                        __('%1$s, %2$s', 'll-tools-text-domain'),
                        $term_name,
                        $count_label
                    );
                $button_classes = [
                    'll-study-btn',
                    'll-vocab-lesson-mode-button',
                    'll-wordset-buttons-shortcode__button',
                ];
                if ($has_button_image) {
                    $button_classes[] = 'll-wordset-buttons-shortcode__button--has-image';
                }
                if ($is_private) {
                    $button_classes[] = 'll-wordset-buttons-shortcode__button--private';
                }
                ?>
                <li class="ll-wordset-buttons-shortcode__item">
                    <a class="<?php echo esc_attr(implode(' ', $button_classes)); ?>" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr($link_aria_label); ?>">
                        <?php if ($has_button_image) : ?>
                            <span class="ll-wordset-buttons-shortcode__media" aria-hidden="true">
                                <img class="ll-wordset-buttons-shortcode__image" src="<?php echo esc_url($button_image_url); ?>" alt="" loading="lazy" decoding="async" />
                            </span>
                        <?php endif; ?>
                        <span class="ll-wordset-buttons-shortcode__label-wrap">
                            <span class="ll-wordset-buttons-shortcode__label"><?php echo esc_html($term_name); ?></span>
                            <?php if ($is_private) : ?>
                                <span class="ll-wordset-buttons-shortcode__privacy-badge" aria-hidden="true" title="<?php echo esc_attr($privacy_label); ?>"></span>
                            <?php endif; ?>
                        </span>
                        <span class="ll-wordset-buttons-shortcode__count"><?php echo esc_html($count_label); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php

    $html = trim((string) ob_get_clean());
    if ($html === '' || strpos($html, 'll-wordset-buttons-shortcode__button') === false) {
        return '';
    }

    if ($cache_key !== '') {
        ll_tools_wordset_buttons_shortcode_cache_set($cache_key, $html);
        ll_tools_wordset_buttons_shortcode_stale_set($stale_key, $html);
    }

    return $html;
}
add_shortcode('wordset_buttons', 'll_tools_wordset_buttons_shortcode');
add_shortcode('ll_wordset_buttons', 'll_tools_wordset_buttons_shortcode');
