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
    $ttl = (int) apply_filters('ll_tools_wordset_buttons_shortcode_stale_ttl', 7 * DAY_IN_SECONDS);
    return min(30 * DAY_IN_SECONDS, max(60, $ttl));
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

/**
 * Durable key for the last exact anonymous membership/order snapshot.
 *
 * This key intentionally ignores plugin/content/structural epochs. The
 * snapshot stores only positive public term IDs and their last exact ranking;
 * every read resolves current terms, visibility, labels, URLs, and media
 * again. That makes it safe to bridge a bounded rebuild without exposing new
 * or zero-lesson terms through an optimistic raw-term shell.
 */
function ll_tools_wordset_buttons_navigation_manifest_key(array $atts, string $tag = ''): string {
    $payload = [
        'schema' => 1,
        'site' => home_url('/'),
        'locale' => function_exists('get_locale') ? (string) get_locale() : '',
        'tag' => sanitize_key($tag !== '' ? $tag : 'll_wordset_buttons'),
        'hide_empty' => ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0',
    ];

    return 'll_ws_buttons_nav_' . md5((string) wp_json_encode($payload));
}

function ll_tools_wordset_buttons_navigation_manifest_record_key(string $key): void {
    $key = sanitize_key($key);
    if ($key === '') {
        return;
    }

    $registry_key = 'll_tools_wordset_buttons_navigation_manifest_keys';
    $keys = get_option($registry_key, []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];
    $keys = array_values(array_unique($keys));
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
    }

    $evicted = count($keys) > 20 ? array_slice($keys, 0, count($keys) - 20) : [];
    update_option($registry_key, array_slice($keys, -20), false);
    foreach ($evicted as $evicted_key) {
        delete_option($evicted_key);
    }
}

/**
 * @return array{schema:int,stored_at:int,entries:array<int,array{term_id:int,lesson_count:int}>}|null
 */
function ll_tools_wordset_buttons_navigation_manifest_get(array $atts, string $tag = ''): ?array {
    $payload = get_option(ll_tools_wordset_buttons_navigation_manifest_key($atts, $tag), null);
    if (
        !is_array($payload)
        || (int) ($payload['schema'] ?? 0) !== 1
        || !is_array($payload['entries'] ?? null)
    ) {
        return null;
    }

    if (count($payload['entries']) > 5000) {
        return null;
    }

    $entries = [];
    $seen = [];
    foreach ($payload['entries'] as $entry) {
        if (!is_array($entry)) {
            return null;
        }
        $term_id = (int) ($entry['term_id'] ?? 0);
        $lesson_count = (int) ($entry['lesson_count'] ?? 0);
        if ($term_id <= 0 || $lesson_count <= 0 || isset($seen[$term_id])) {
            return null;
        }
        $seen[$term_id] = true;
        $entries[] = [
            'term_id' => $term_id,
            'lesson_count' => $lesson_count,
        ];
    }

    return [
        'schema' => 1,
        'stored_at' => max(0, (int) ($payload['stored_at'] ?? 0)),
        'entries' => $entries,
    ];
}

/**
 * Publish only a fully materialized anonymous item set behind the exact
 * render-context fence. Empty exact sets are valid manifests too.
 *
 * @param array<int,array{term:WP_Term,lesson_count:int,is_private:bool}> $items
 */
function ll_tools_wordset_buttons_navigation_manifest_publish(
    array $atts,
    string $tag,
    array $items,
    string $expected_cache_key,
    string $expected_stale_key
): bool {
    if (
        get_current_user_id() !== 0
        || !ll_tools_wordset_buttons_shortcode_context_matches(
            $atts,
            $tag,
            $expected_cache_key,
            $expected_stale_key
        )
    ) {
        return false;
    }

    $entries = [];
    foreach ($items as $item) {
        $term = $item['term'] ?? null;
        $lesson_count = (int) ($item['lesson_count'] ?? 0);
        if (!$term instanceof WP_Term || (int) $term->term_id <= 0 || $lesson_count <= 0 || !empty($item['is_private'])) {
            continue;
        }
        $entries[] = [
            'term_id' => (int) $term->term_id,
            'lesson_count' => $lesson_count,
        ];
    }

    if (count($entries) > 5000) {
        return false;
    }

    $key = ll_tools_wordset_buttons_navigation_manifest_key($atts, $tag);
    $lock_token = function_exists('ll_tools_acquire_wordset_button_count_lock')
        ? ll_tools_acquire_wordset_button_count_lock($key)
        : '';
    if ($lock_token === '') {
        return false;
    }

    $write_token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : wp_generate_password(32, false, false);
    try {
        if (!ll_tools_wordset_buttons_shortcode_context_matches(
            $atts,
            $tag,
            $expected_cache_key,
            $expected_stale_key
        )) {
            return false;
        }

        $missing = new stdClass();
        $previous = get_option($key, $missing);
        $payload = [
            'schema' => 1,
            'stored_at' => time(),
            'source_context' => $expected_cache_key,
            'write_token' => $write_token,
            'entries' => $entries,
        ];
        update_option($key, $payload, false);
        $published = get_option($key, null);
        if (
            !is_array($published)
            || !hash_equals($write_token, (string) ($published['write_token'] ?? ''))
        ) {
            return false;
        }
        ll_tools_wordset_buttons_navigation_manifest_record_key($key);

        if (ll_tools_wordset_buttons_shortcode_context_matches(
            $atts,
            $tag,
            $expected_cache_key,
            $expected_stale_key
        )) {
            return true;
        }

        ll_tools_wordset_buttons_navigation_manifest_restore_if_current(
            $key,
            $published,
            $previous,
            $missing
        );
        return false;
    } finally {
        ll_tools_release_wordset_button_count_lock($key, $lock_token);
    }
}

/** Restore a prior manifest only if the stale candidate is still current. */
function ll_tools_wordset_buttons_navigation_manifest_restore_if_current(
    string $key,
    array $current,
    $previous,
    stdClass $missing
): bool {
    global $wpdb;

    $serialized_current = maybe_serialize($current);
    if ($previous === $missing) {
        $changed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            $serialized_current
        ));
    } else {
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize($previous),
            $key,
            $serialized_current
        ));
    }
    wp_cache_delete($key, 'options');

    return (int) $changed === 1;
}

/** Explicit maintenance/test reset. Normal cache invalidation preserves LKG membership. */
function ll_tools_reset_wordset_buttons_navigation_manifests(): void {
    $registry_key = 'll_tools_wordset_buttons_navigation_manifest_keys';
    $keys = get_option($registry_key, []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];
    foreach ($keys as $key) {
        delete_option($key);
    }
    delete_option($registry_key);
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

function ll_tools_wordset_buttons_shortcode_context_matches(
    array $atts,
    string $tag,
    string $expected_cache_key,
    string $expected_stale_key
): bool {
    return $expected_cache_key !== ''
        && $expected_stale_key !== ''
        && hash_equals(
            ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag),
            $expected_cache_key
        )
        && hash_equals(
            ll_tools_wordset_buttons_shortcode_stale_key($atts, $tag),
            $expected_stale_key
        );
}

function ll_tools_wordset_buttons_shortcode_publish_anonymous_html(
    array $atts,
    string $tag,
    string $html,
    string $expected_cache_key,
    string $expected_stale_key
): bool {
    if (
        get_current_user_id() !== 0
        || $html === ''
        || strpos($html, 'll-wordset-buttons-shortcode__button') === false
        || $expected_cache_key === ''
        || $expected_stale_key === ''
    ) {
        return false;
    }

    if (!ll_tools_wordset_buttons_shortcode_context_matches(
        $atts,
        $tag,
        $expected_cache_key,
        $expected_stale_key
    )) {
        return false;
    }

    // Always write the pre-render keys. If an epoch changes after the fence,
    // these entries become unreachable instead of poisoning the new context.
    ll_tools_wordset_buttons_shortcode_cache_set($expected_cache_key, $html);
    ll_tools_wordset_buttons_shortcode_stale_set($expected_stale_key, $html);

    return ll_tools_wordset_buttons_shortcode_context_matches(
        $atts,
        $tag,
        $expected_cache_key,
        $expected_stale_key
    );
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
    if (!empty($GLOBALS['ll_tools_wordset_buttons_shortcode_cache_purged_this_request'])) {
        return 0;
    }

    $GLOBALS['ll_tools_wordset_buttons_shortcode_cache_purged_this_request'] = true;
    return ll_tools_purge_wordset_buttons_shortcode_cache();
}

/** Reset the request guard for tests and CLI loops that simulate HTTP boundaries. */
function ll_tools_reset_wordset_buttons_shortcode_cache_purge_once_state(): void {
    unset($GLOBALS['ll_tools_wordset_buttons_shortcode_cache_purged_this_request']);
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
    $ttl = (int) apply_filters('ll_tools_wordset_buttons_shortcode_count_state_ttl', 2 * DAY_IN_SECONDS);
    return min(7 * DAY_IN_SECONDS, max(5 * MINUTE_IN_SECONDS, $ttl));
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
function ll_tools_get_wordset_button_lesson_counts_bounded(
    array $wordset_ids,
    ?bool &$complete = null,
    ?int &$retry_after_ms = null,
    bool $advance = true
): array {
    $complete = false;
    $retry_after_ms = ll_tools_wordset_buttons_shortcode_refresh_retry_ms();
    $wordset_ids = ll_tools_wordset_button_normalize_wordset_ids($wordset_ids);
    if (empty($wordset_ids)) {
        $complete = true;
        $retry_after_ms = 0;
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
            $retry_after_ms = 0;
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
            return $counts;
        }
    }

    if (!$advance) {
        $run_at = is_array($existing) ? max(0, (int) ($existing['next_retry_at'] ?? 0)) : 0;
        if ($run_at > ll_tools_wordset_button_now()) {
            $retry_after_ms = max(
                $retry_after_ms,
                min(15 * MINUTE_IN_SECONDS * 1000, ($run_at - ll_tools_wordset_button_now()) * 1000)
            );
        }
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, true, $run_at);
        return [];
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
            $complete,
            $retry_after_ms
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
    ?bool &$complete = null,
    ?int &$retry_after_ms = null
): array {
    $complete = false;
    $retry_after_ms = ll_tools_wordset_buttons_shortcode_refresh_retry_ms();
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
        $retry_after_ms = 0;
        ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
        return $counts;
    }

    $now = ll_tools_wordset_button_now();
    $next_retry_at = max(0, (int) ($state['next_retry_at'] ?? 0));
    if ($next_retry_at > $now) {
        $retry_after_ms = max(
            $retry_after_ms,
            min(15 * MINUTE_IN_SECONDS * 1000, ($next_retry_at - $now) * 1000)
        );
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
            $retry_after_ms = max(
                $retry_after_ms,
                min(15 * MINUTE_IN_SECONDS * 1000, ((int) $state['next_retry_at'] - $now) * 1000)
            );
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
    $retry_after_ms = 0;
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

/**
 * Resolve the exact wordset terms visible to the current user without touching
 * lesson-count materialization. Public status polling uses this read-only
 * scope to inspect the durable anonymous generation safely.
 *
 * @return WP_Term[]
 */
function ll_tools_get_wordset_button_visible_terms(
    bool $hide_empty = false,
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

    $visible_lookup = array_fill_keys($visible_term_ids, true);
    return array_values(array_filter($terms, static function ($term) use ($visible_lookup): bool {
        return $term instanceof WP_Term
            && (int) $term->term_id > 0
            && isset($visible_lookup[(int) $term->term_id]);
    }));
}

/**
 * @param WP_Term[] $terms
 * @param array<int,int> $lesson_counts
 */
function ll_tools_get_wordset_button_items_from_counts(array $terms, array $lesson_counts): array {
    $items = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $term_id = (int) $term->term_id;
        $lesson_count = (int) ($lesson_counts[$term_id] ?? 0);
        if ($term_id <= 0 || $lesson_count <= 0) {
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

function ll_tools_get_wordset_button_items(
    bool $hide_empty = false,
    bool $bounded_counts = false,
    ?bool &$complete = null,
    ?int &$retry_after_ms = null,
    bool $advance_bounded_counts = true
): array {
    $terms_complete = true;
    $terms = ll_tools_get_wordset_button_visible_terms($hide_empty, $terms_complete);
    if (!$terms_complete) {
        $complete = false;
        return [];
    }
    $complete = true;
    if (empty($terms)) {
        return [];
    }

    $visible_term_ids = ll_tools_wordset_button_normalize_wordset_ids(wp_list_pluck($terms, 'term_id'));

    $counts_complete = true;
    $lesson_counts = $bounded_counts
        ? ll_tools_get_wordset_button_lesson_counts_bounded(
            $visible_term_ids,
            $counts_complete,
            $retry_after_ms,
            $advance_bounded_counts
        )
        : ll_tools_get_wordset_button_lesson_counts($visible_term_ids, $counts_complete);
    if (!$counts_complete) {
        $complete = false;
        return [];
    }

    return ll_tools_get_wordset_button_items_from_counts($terms, $lesson_counts);
}

/**
 * Resolve the durable public positive set against the current viewer's exact
 * visibility scope. Stored names, URLs, privacy, and media are never trusted.
 *
 * @return array<int,array{term:WP_Term,lesson_count:int,is_private:bool}>
 */
function ll_tools_get_wordset_button_navigation_manifest_items(
    array $atts,
    string $tag,
    ?bool &$complete = null,
    ?bool &$manifest_found = null
): array {
    $manifest = ll_tools_wordset_buttons_navigation_manifest_get($atts, $tag);
    $manifest_found = is_array($manifest);
    $complete = true;
    if (!$manifest_found) {
        return [];
    }

    $terms_complete = true;
    $terms = ll_tools_get_wordset_button_visible_terms(
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0'),
        $terms_complete
    );
    if (!$terms_complete) {
        $complete = false;
        return [];
    }

    $term_lookup = [];
    foreach ($terms as $term) {
        if ($term instanceof WP_Term && (int) $term->term_id > 0) {
            $term_lookup[(int) $term->term_id] = $term;
        }
    }

    $items = [];
    foreach ((array) ($manifest['entries'] ?? []) as $entry) {
        $term_id = (int) ($entry['term_id'] ?? 0);
        $lesson_count = (int) ($entry['lesson_count'] ?? 0);
        $term = $term_lookup[$term_id] ?? null;
        if (!$term instanceof WP_Term || $lesson_count <= 0) {
            continue;
        }
        $items[] = [
            'term' => $term,
            'lesson_count' => $lesson_count,
            'is_private' => function_exists('ll_tools_is_wordset_private') && ll_tools_is_wordset_private($term),
        ];
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
 * Render one stable card. A hydrating card deliberately keeps its exact last
 * known membership/order and current image while only the count pill shimmers.
 */
function ll_tools_wordset_buttons_shortcode_card_html(array $item, bool $count_loading = false): string {
    $term = $item['term'] ?? null;
    $lesson_count = (int) ($item['lesson_count'] ?? 0);
    $is_private = !empty($item['is_private']);
    if (!$term instanceof WP_Term || (int) $term->term_id <= 0 || $lesson_count <= 0) {
        return '';
    }

    $term_id = (int) $term->term_id;
    $term_name = function_exists('ll_tools_get_wordset_display_name')
        ? ll_tools_get_wordset_display_name($term)
        : (string) $term->name;
    $url = function_exists('ll_tools_get_wordset_page_view_url')
        ? (string) ll_tools_get_wordset_page_view_url($term)
        : '';
    if ($term_name === '' || $url === '') {
        return '';
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
    if ($count_loading) {
        $link_aria_label = $is_private
            ? sprintf(
                /* translators: 1: word set name, 2: word set privacy label. */
                __('%1$s, %2$s', 'll-tools-text-domain'),
                $term_name,
                $privacy_label
            )
            : $term_name;
    } else {
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
    }

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
    if ($count_loading) {
        $button_classes[] = 'll-wordset-buttons-shortcode__button--navigation';
        $button_classes[] = 'll-wordset-buttons-shortcode__button--hydrating';
    }

    ob_start();
    ?>
    <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="<?php echo esc_attr((string) $term_id); ?>">
        <a
            class="<?php echo esc_attr(implode(' ', $button_classes)); ?>"
            href="<?php echo esc_url($url); ?>"
            aria-label="<?php echo esc_attr($link_aria_label); ?>"
            data-ll-wordset-id="<?php echo esc_attr((string) $term_id); ?>"
            data-ll-wordset-card-state="<?php echo $count_loading ? 'hydrating' : 'ready'; ?>"
            <?php if ($count_loading) : ?>aria-busy="true"<?php endif; ?>
        >
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
            <?php if ($count_loading) : ?>
                <span class="ll-wordset-buttons-shortcode__count ll-wordset-buttons-shortcode__count--loading" aria-hidden="true"></span>
            <?php else : ?>
                <span class="ll-wordset-buttons-shortcode__count"><?php echo esc_html($count_label); ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php

    return trim((string) ob_get_clean());
}

/** Prime term/attachment metadata once so a large card manifest stays bounded. */
function ll_tools_wordset_buttons_shortcode_prime_card_media(array $items): void {
    $term_ids = [];
    foreach ($items as $item) {
        $term = $item['term'] ?? null;
        if ($term instanceof WP_Term && (int) $term->term_id > 0) {
            $term_ids[] = (int) $term->term_id;
        }
    }
    $term_ids = array_values(array_unique($term_ids));
    if (empty($term_ids)) {
        return;
    }

    update_meta_cache('term', $term_ids);
    $attachment_ids = [];
    foreach ($term_ids as $term_id) {
        $attachment_id = (int) get_term_meta(
            $term_id,
            LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY,
            true
        );
        if ($attachment_id > 0) {
            $attachment_ids[] = $attachment_id;
        }
    }
    $attachment_ids = array_values(array_unique($attachment_ids));
    if (empty($attachment_ids)) {
        return;
    }

    if (function_exists('_prime_post_caches')) {
        _prime_post_caches($attachment_ids, true, true);
    } else {
        update_meta_cache('post', $attachment_ids);
    }
}

/** @param array<int,array{term:WP_Term,lesson_count:int,is_private:bool}> $items */
function ll_tools_wordset_buttons_shortcode_items_html(
    array $atts,
    array $items,
    bool $count_loading = false
): string {
    $classes = ll_tools_wordset_buttons_shortcode_classes($atts, $count_loading);
    if ($count_loading) {
        $classes[] = 'll-wordset-buttons-shortcode--navigation';
    }

    ll_tools_wordset_buttons_shortcode_prime_card_media($items);
    $cards = [];
    foreach ($items as $item) {
        $card = ll_tools_wordset_buttons_shortcode_card_html($item, $count_loading);
        if ($card !== '') {
            $cards[] = $card;
        }
    }
    if (empty($cards)) {
        return '';
    }

    ob_start();
    ?>
    <div
        class="<?php echo esc_attr(implode(' ', array_unique($classes))); ?>"
        <?php if ($count_loading) : ?>data-ll-wordset-buttons-navigation aria-busy="true"<?php endif; ?>
    >
        <?php if ($count_loading) : ?>
            <span class="screen-reader-text"><?php echo esc_html__('Loading categories...', 'll-tools-text-domain'); ?></span>
        <?php endif; ?>
        <ul class="ll-wordset-buttons-shortcode__list">
            <?php echo implode("\n", $cards); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cards are escaped above. ?>
        </ul>
    </div>
    <?php

    return trim((string) ob_get_clean());
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

/**
 * Render the last exact positive public membership while current exact counts
 * materialize. New/unproven terms fail closed; current visibility and card
 * metadata are re-resolved on every request.
 */
function ll_tools_wordset_buttons_shortcode_navigation_html(
    array $atts,
    string $tag = 'll_wordset_buttons',
    ?bool &$complete = null,
    ?bool &$manifest_found = null
): string {
    $manifest_found = false;
    $items = ll_tools_get_wordset_button_navigation_manifest_items(
        $atts,
        $tag,
        $complete,
        $manifest_found
    );
    if (!$manifest_found || !$complete || empty($items)) {
        return '';
    }

    return ll_tools_wordset_buttons_shortcode_items_html($atts, $items, true);
}

function ll_tools_wordset_buttons_shortcode_refresh_retry_ms(): int {
    return min(10000, max(500, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_refresh_retry_ms',
        1500
    )));
}

function ll_tools_wordset_buttons_shortcode_public_status_client_identifier(): string {
    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR']))
        : '';

    return $ip !== '' ? $ip : 'unknown';
}

function ll_tools_wordset_buttons_shortcode_public_status_throttle(string $identifier = ''): array {
    if (!function_exists('ll_tools_public_ajax_reserve_counter')) {
        return [
            'allowed' => true,
            'count' => 0,
            'limit' => 0,
            'retry_after' => 0,
        ];
    }

    if ($identifier === '') {
        $identifier = ll_tools_wordset_buttons_shortcode_public_status_client_identifier();
    }
    $window = max(10, min(300, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_public_status_window',
        MINUTE_IN_SECONDS
    )));
    $limit = max(1, min(180, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_public_status_limit',
        60
    )));

    return ll_tools_public_ajax_reserve_counter(
        'll_ws_btn_status_rl_',
        $identifier,
        $limit,
        $window
    );
}

function ll_tools_wordset_buttons_shortcode_reset_public_status_throttle(string $identifier = ''): void {
    if (!function_exists('ll_tools_public_ajax_reset_counter')) {
        return;
    }
    if ($identifier === '') {
        $identifier = ll_tools_wordset_buttons_shortcode_public_status_client_identifier();
    }

    ll_tools_public_ajax_reset_counter('ll_ws_btn_status_rl_', $identifier);
}

function ll_tools_wordset_buttons_shortcode_public_worker_window(): int {
    return min(30, max(1, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_public_worker_window',
        3
    )));
}

/**
 * Admit at most one bounded anonymous builder batch per exact public context
 * in a short global window. This keeps polling useful when WP-Cron is disabled
 * or backlogged without allowing every visitor to launch the same scan.
 */
function ll_tools_wordset_buttons_shortcode_public_worker_admission(array $atts, string $tag): array {
    if (!function_exists('ll_tools_public_ajax_reserve_counter')) {
        return [
            'allowed' => true,
            'count' => 0,
            'limit' => 0,
            'retry_after' => 0,
        ];
    }

    return ll_tools_public_ajax_reserve_counter(
        'll_ws_btn_worker_',
        ll_tools_wordset_buttons_navigation_manifest_key($atts, $tag),
        1,
        ll_tools_wordset_buttons_shortcode_public_worker_window()
    );
}

function ll_tools_wordset_buttons_shortcode_reset_public_worker_admission(array $atts, string $tag): void {
    if (!function_exists('ll_tools_public_ajax_reset_counter')) {
        return;
    }

    ll_tools_public_ajax_reset_counter(
        'll_ws_btn_worker_',
        ll_tools_wordset_buttons_navigation_manifest_key($atts, $tag)
    );
}

function ll_tools_wordset_buttons_shortcode_public_status_retry_payload(int $retry_after_seconds): array {
    return [
        'complete' => false,
        'html' => '',
        'retryAfterMs' => max(
            ll_tools_wordset_buttons_shortcode_refresh_retry_ms(),
            min(60, max(1, $retry_after_seconds)) * 1000
        ),
    ];
}

function ll_tools_wordset_buttons_shortcode_status_token_ttl(): int {
    return min(HOUR_IN_SECONDS, max(10 * MINUTE_IN_SECONDS, (int) apply_filters(
        'll_tools_wordset_buttons_shortcode_status_token_ttl',
        15 * MINUTE_IN_SECONDS
    )));
}

function ll_tools_wordset_buttons_shortcode_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ll_tools_wordset_buttons_shortcode_base64url_decode(string $value): string {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return '';
    }

    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if (!is_string($decoded)) {
        return '';
    }

    $canonical = rtrim($value, '=');
    return hash_equals(ll_tools_wordset_buttons_shortcode_base64url_encode($decoded), $canonical)
        ? $decoded
        : '';
}

function ll_tools_wordset_buttons_shortcode_public_scope(array $atts, string $tag): array {
    $tag = in_array($tag, ll_tools_wordset_buttons_shortcode_tags(), true)
        ? $tag
        : 'll_wordset_buttons';
    $classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : array_filter(array_map(
            'sanitize_html_class',
            preg_split('/\s+/', trim((string) ($atts['class'] ?? ''))) ?: []
        ));

    return [
        'tag' => $tag,
        'atts' => [
            'class' => implode(' ', array_values(array_unique($classes))),
            'hide_empty' => ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0',
        ],
    ];
}

function ll_tools_wordset_buttons_shortcode_create_status_token(array $atts, string $tag): string {
    $scope = ll_tools_wordset_buttons_shortcode_public_scope($atts, $tag);
    $payload = [
        'schema' => 1,
        'expires_at' => time() + ll_tools_wordset_buttons_shortcode_status_token_ttl(),
        'tag' => $scope['tag'],
        'atts' => $scope['atts'],
        'context' => ll_tools_wordset_buttons_shortcode_cache_key($scope['atts'], $scope['tag']),
    ];
    $json = wp_json_encode($payload);
    if (!is_string($json) || $json === '') {
        return '';
    }

    $encoded = ll_tools_wordset_buttons_shortcode_base64url_encode($json);
    $signature = hash_hmac('sha256', $encoded, wp_salt('nonce'), true);
    return $encoded . '.' . ll_tools_wordset_buttons_shortcode_base64url_encode($signature);
}

/**
 * Verify an anonymous status token and return its server-owned public scope.
 */
function ll_tools_wordset_buttons_shortcode_verify_status_token(
    string $token,
    ?string &$error_code = null,
    ?string &$verified_context = null
): ?array {
    $error_code = 'invalid_status_token';
    $verified_context = null;
    if ($token === '' || strlen($token) > 2048 || substr_count($token, '.') !== 1) {
        return null;
    }

    [$encoded, $encoded_signature] = explode('.', $token, 2);
    $signature = ll_tools_wordset_buttons_shortcode_base64url_decode($encoded_signature);
    if (
        strlen($signature) !== 32
        || !hash_equals(hash_hmac('sha256', $encoded, wp_salt('nonce'), true), $signature)
    ) {
        return null;
    }

    $json = ll_tools_wordset_buttons_shortcode_base64url_decode($encoded);
    $payload = json_decode($json, true);
    if (
        !is_array($payload)
        || (int) ($payload['schema'] ?? 0) !== 1
        || !is_array($payload['atts'] ?? null)
    ) {
        return null;
    }
    if ((int) ($payload['expires_at'] ?? 0) < time()) {
        $error_code = 'expired_status_token';
        return null;
    }

    $scope = ll_tools_wordset_buttons_shortcode_public_scope(
        $payload['atts'],
        (string) ($payload['tag'] ?? '')
    );
    if (
        $scope['tag'] !== (string) ($payload['tag'] ?? '')
        || $scope['atts'] !== $payload['atts']
        || !hash_equals(
            ll_tools_wordset_buttons_shortcode_cache_key($scope['atts'], $scope['tag']),
            (string) ($payload['context'] ?? '')
        )
    ) {
        $error_code = 'stale_status_token';
        return null;
    }

    $error_code = '';
    $verified_context = (string) $payload['context'];
    return $scope;
}

function ll_tools_wordset_buttons_shortcode_incomplete_cache_control(): string {
    return 'no-cache, no-store, must-revalidate, max-age=0, private';
}

function ll_tools_wordset_buttons_shortcode_mark_incomplete_response_uncacheable(): void {
    if (
        is_user_logged_in()
        || (function_exists('wp_doing_ajax') && wp_doing_ajax())
        || (defined('DOING_AJAX') && DOING_AJAX)
    ) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!headers_sent()) {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        // WordPress versions before 6.8 do not add no-store/private for
        // anonymous nocache responses, so make the cold recovery boundary
        // explicit for every supported installation.
        header(
            'Cache-Control: ' . ll_tools_wordset_buttons_shortcode_incomplete_cache_control(),
            true
        );
    }
}

function ll_tools_wordset_buttons_shortcode_enqueue_refresh_script(): void {
    if (!function_exists('ll_enqueue_asset_by_timestamp')) {
        return;
    }

    $handle = 'll-tools-wordset-buttons-refresh';
    ll_enqueue_asset_by_timestamp('/js/wordset-buttons-refresh.js', $handle, [], true);
    $localized_data = (string) wp_scripts()->get_data($handle, 'data');
    $has_localized_config = strpos($localized_data, 'llToolsWordsetButtonsRefresh') !== false;
    if (
        $has_localized_config
        && (!is_user_logged_in() || strpos($localized_data, 'll_tools_wordset_buttons_refresh') !== false)
    ) {
        return;
    }

    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'retryMs' => ll_tools_wordset_buttons_shortcode_refresh_retry_ms(),
        'requestTimeoutMs' => 20000,
        'maxFailures' => 5,
        'maxWaitMs' => 10 * MINUTE_IN_SECONDS * 1000,
        'errorMessage' => __('Something went wrong', 'll-tools-text-domain'),
        'retryLabel' => __('Try again', 'll-tools-text-domain'),
    ];
    if (is_user_logged_in()) {
        $config['action'] = 'll_tools_wordset_buttons_refresh';
        $config['nonce'] = wp_create_nonce('ll_tools_wordset_buttons_refresh');
    }
    wp_localize_script($handle, 'llToolsWordsetButtonsRefresh', $config);
}

function ll_tools_wordset_buttons_shortcode_enqueue_logged_in_fallback(): void {
    if (!is_user_logged_in() || !is_singular()) {
        return;
    }

    ll_tools_wordset_buttons_shortcode_enqueue_refresh_script();
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_buttons_shortcode_enqueue_logged_in_fallback', 20);

function ll_tools_wordset_buttons_shortcode_refresh_html(
    array $atts,
    string $tag,
    string $inner_html
): string {
    ll_tools_wordset_buttons_shortcode_enqueue_refresh_script();

    $shortcode_tag = in_array($tag, ll_tools_wordset_buttons_shortcode_tags(), true)
        ? $tag
        : 'll_wordset_buttons';

    if (!is_user_logged_in()) {
        ll_tools_wordset_buttons_shortcode_mark_incomplete_response_uncacheable();
        return sprintf(
            '<div class="ll-wordset-buttons-refresh" data-ll-wordset-buttons-refresh data-ajax-url="%1$s" data-ajax-action="%2$s" data-status-token="%3$s" data-error-message="%4$s" data-retry-label="%5$s" aria-busy="true">%6$s</div>',
            esc_url(admin_url('admin-ajax.php')),
            esc_attr('ll_tools_wordset_buttons_status'),
            esc_attr(ll_tools_wordset_buttons_shortcode_create_status_token($atts, $shortcode_tag)),
            esc_attr__('Something went wrong', 'll-tools-text-domain'),
            esc_attr__('Try again', 'll-tools-text-domain'),
            $inner_html
        );
    }

    return sprintf(
        '<div class="ll-wordset-buttons-refresh" data-ll-wordset-buttons-refresh data-shortcode-tag="%1$s" data-shortcode-class="%2$s" data-hide-empty="%3$s" data-ajax-url="%4$s" data-ajax-action="%5$s" data-nonce="%6$s" data-error-message="%7$s" data-retry-label="%8$s" aria-busy="true">%9$s</div>',
        esc_attr($shortcode_tag),
        esc_attr((string) ($atts['class'] ?? '')),
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0') ? '1' : '0',
        esc_url(admin_url('admin-ajax.php')),
        esc_attr('ll_tools_wordset_buttons_refresh'),
        esc_attr(wp_create_nonce('ll_tools_wordset_buttons_refresh')),
        esc_attr__('Something went wrong', 'll-tools-text-domain'),
        esc_attr__('Try again', 'll-tools-text-domain'),
        $inner_html
    );
}

function ll_tools_wordset_buttons_shortcode_incomplete_html(
    array $atts,
    string $tag,
    bool $allow_refresh,
    string $expected_cache_key = '',
    string $expected_stale_key = ''
): string {
    if ($expected_cache_key === '') {
        $expected_cache_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag);
    }
    if ($expected_stale_key === '') {
        $expected_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, $tag);
    }

    $context_current = ll_tools_wordset_buttons_shortcode_context_matches(
        $atts,
        $tag,
        $expected_cache_key,
        $expected_stale_key
    );
    $stale_html = $context_current
        ? ll_tools_wordset_buttons_shortcode_stale_get($expected_stale_key)
        : '';
    if ($context_current && $stale_html === '') {
        $stale_html = ll_tools_wordset_buttons_shortcode_anonymous_cache_get($atts, $tag);
    }
    if ($context_current && $stale_html === '') {
        $stale_html = ll_tools_wordset_buttons_shortcode_previous_version_cache_get($atts, $tag);
    }
    if ($context_current && $stale_html === '') {
        $stale_html = ll_tools_wordset_buttons_shortcode_legacy_cache_get($atts, $tag);
    }

    $navigation_complete = true;
    $navigation_manifest_found = false;
    $navigation_html = $context_current
        ? ll_tools_wordset_buttons_shortcode_navigation_html(
            $atts,
            $tag,
            $navigation_complete,
            $navigation_manifest_found
        )
        : '';
    if (
        !ll_tools_wordset_buttons_shortcode_context_matches(
            $atts,
            $tag,
            $expected_cache_key,
            $expected_stale_key
        )
    ) {
        $stale_html = '';
        $navigation_html = '';
        $navigation_complete = false;
        $navigation_manifest_found = false;
    }
    if ($navigation_complete && $navigation_html !== '') {
        $inner_html = $navigation_html;
    } elseif ($navigation_manifest_found) {
        // An authoritative empty/currently invisible manifest must suppress an
        // older positive LKG instead of resurrecting ineligible navigation.
        $inner_html = ll_tools_wordset_buttons_shortcode_loading_html($atts);
    } elseif ($stale_html !== '') {
        $inner_html = $stale_html;
    } else {
        $inner_html = ll_tools_wordset_buttons_shortcode_loading_html($atts);
    }
    return $allow_refresh
        ? ll_tools_wordset_buttons_shortcode_refresh_html($atts, $tag, $inner_html)
        : $inner_html;
}

function ll_tools_wordset_buttons_shortcode_render(
    array $atts,
    string $tag = '',
    bool $allow_refresh = true,
    ?bool &$complete = null,
    ?int &$retry_after_ms = null,
    bool $advance_bounded_counts = true
): string {
    $complete = true;
    $retry_after_ms = 0;

    // The stable LKG is written only by complete anonymous renders, but is a
    // safe public subset for logged-in visitors while their wider scope builds.
    $expected_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, $tag);
    $expected_cache_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag);
    $cache_key = ll_tools_wordset_buttons_shortcode_cache_enabled()
        ? $expected_cache_key
        : '';
    if ($cache_key !== '') {
        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            if (!ll_tools_wordset_buttons_shortcode_context_matches(
                $atts,
                $tag,
                $expected_cache_key,
                $expected_stale_key
            )) {
                $complete = false;
                return ll_tools_wordset_buttons_shortcode_incomplete_html(
                    $atts,
                    $tag,
                    $allow_refresh,
                    $expected_cache_key,
                    $expected_stale_key
                );
            }
            if (ll_tools_wordset_buttons_shortcode_stale_get($expected_stale_key) === '') {
                ll_tools_wordset_buttons_shortcode_stale_set($expected_stale_key, $cached_html);
            }
            if (
                get_current_user_id() === 0
                && ll_tools_wordset_buttons_navigation_manifest_get($atts, $tag) === null
            ) {
                $manifest_items_complete = true;
                $manifest_retry_after_ms = 0;
                $manifest_items = ll_tools_get_wordset_button_items(
                    ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0'),
                    true,
                    $manifest_items_complete,
                    $manifest_retry_after_ms,
                    false
                );
                if ($manifest_items_complete) {
                    ll_tools_wordset_buttons_navigation_manifest_publish(
                        $atts,
                        $tag,
                        $manifest_items,
                        $expected_cache_key,
                        $expected_stale_key
                    );
                }
            }
            return $cached_html;
        }
    }

    $items_complete = true;
    $retry_after_ms = ll_tools_wordset_buttons_shortcode_refresh_retry_ms();
    $items = ll_tools_get_wordset_button_items(
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0'),
        true,
        $items_complete,
        $retry_after_ms,
        $advance_bounded_counts
    );
    if (!$items_complete) {
        $complete = false;
        return ll_tools_wordset_buttons_shortcode_incomplete_html(
            $atts,
            $tag,
            $allow_refresh,
            $expected_cache_key,
            $expected_stale_key
        );
    }
    if (!ll_tools_wordset_buttons_shortcode_context_matches(
        $atts,
        $tag,
        $expected_cache_key,
        $expected_stale_key
    )) {
        $complete = false;
        return ll_tools_wordset_buttons_shortcode_incomplete_html(
            $atts,
            $tag,
            $allow_refresh,
            $expected_cache_key,
            $expected_stale_key
        );
    }
    if (
        get_current_user_id() === 0
        && !ll_tools_wordset_buttons_navigation_manifest_publish(
            $atts,
            $tag,
            $items,
            $expected_cache_key,
            $expected_stale_key
        )
    ) {
        $complete = false;
        return ll_tools_wordset_buttons_shortcode_incomplete_html(
            $atts,
            $tag,
            $allow_refresh,
            $expected_cache_key,
            $expected_stale_key
        );
    }
    if (empty($items)) {
        return '';
    }

    $html = ll_tools_wordset_buttons_shortcode_items_html($atts, $items, false);
    if ($html === '' || strpos($html, 'll-wordset-buttons-shortcode__button') === false) {
        return '';
    }

    if (!ll_tools_wordset_buttons_shortcode_context_matches(
        $atts,
        $tag,
        $expected_cache_key,
        $expected_stale_key
    )) {
        $complete = false;
        return ll_tools_wordset_buttons_shortcode_incomplete_html(
            $atts,
            $tag,
            $allow_refresh,
            $expected_cache_key,
            $expected_stale_key
        );
    }

    if (
        $cache_key !== ''
        && !ll_tools_wordset_buttons_shortcode_publish_anonymous_html(
            $atts,
            $tag,
            $html,
            $expected_cache_key,
            $expected_stale_key
        )
    ) {
        $complete = false;
        return ll_tools_wordset_buttons_shortcode_incomplete_html(
            $atts,
            $tag,
            $allow_refresh,
            $expected_cache_key,
            $expected_stale_key
        );
    }

    return $html;
}

function ll_tools_wordset_buttons_shortcode_refresh_payload(
    array $atts,
    string $tag = '',
    bool $advance_bounded_counts = true
): array {
    $complete = false;
    $retry_after_ms = ll_tools_wordset_buttons_shortcode_refresh_retry_ms();
    $html = ll_tools_wordset_buttons_shortcode_render(
        $atts,
        $tag,
        false,
        $complete,
        $retry_after_ms,
        $advance_bounded_counts
    );
    return [
        'complete' => $complete,
        'html' => $html,
        'retryAfterMs' => max(
            ll_tools_wordset_buttons_shortcode_refresh_retry_ms(),
            (int) $retry_after_ms
        ),
    ];
}

function ll_tools_wordset_buttons_shortcode_refresh_ajax(): void {
    if (!is_user_logged_in()) {
        wp_send_json_error(['code' => 'authentication_required'], 403);
    }

    if (!check_ajax_referer('ll_tools_wordset_buttons_refresh', 'nonce', false)) {
        wp_send_json_error([
            'code' => 'invalid_nonce',
            'nonce' => wp_create_nonce('ll_tools_wordset_buttons_refresh'),
        ], 403);
    }

    $tag = isset($_POST['tag']) && is_string($_POST['tag'])
        ? sanitize_key(wp_unslash($_POST['tag']))
        : 'll_wordset_buttons';
    if (!in_array($tag, ll_tools_wordset_buttons_shortcode_tags(), true)) {
        $tag = 'll_wordset_buttons';
    }
    $class_value = isset($_POST['class']) && is_string($_POST['class'])
        ? sanitize_text_field(wp_unslash($_POST['class']))
        : '';
    $hide_empty_value = isset($_POST['hide_empty']) && is_string($_POST['hide_empty'])
        ? wp_unslash($_POST['hide_empty'])
        : '0';
    $atts = shortcode_atts([
        'class' => '',
        'hide_empty' => '0',
    ], [
        'class' => $class_value,
        'hide_empty' => ll_tools_wordset_buttons_shortcode_is_truthy($hide_empty_value) ? '1' : '0',
    ], $tag);

    wp_send_json_success(ll_tools_wordset_buttons_shortcode_refresh_payload($atts, $tag));
}
add_action('wp_ajax_ll_tools_wordset_buttons_refresh', 'll_tools_wordset_buttons_shortcode_refresh_ajax');

function ll_tools_wordset_buttons_shortcode_public_status_payload(array $atts, string $tag): array {
    $cached_html = ll_tools_wordset_buttons_shortcode_anonymous_cache_get($atts, $tag);
    if ($cached_html !== '') {
        return [
            'complete' => true,
            'html' => $cached_html,
            'retryAfterMs' => 0,
        ];
    }

    $admission = ll_tools_wordset_buttons_shortcode_public_worker_admission($atts, $tag);
    $payload = ll_tools_wordset_buttons_shortcode_refresh_payload(
        $atts,
        $tag,
        !empty($admission['allowed'])
    );
    if (empty($payload['complete']) && empty($admission['allowed'])) {
        $payload['retryAfterMs'] = max(
            (int) ($payload['retryAfterMs'] ?? 0),
            max(1, (int) ($admission['retry_after'] ?? 1)) * 1000
        );
    }

    return $payload;
}

function ll_tools_wordset_buttons_shortcode_status_error_http_code(string $error_code): int {
    if ($error_code === 'expired_status_token') {
        return 410;
    }
    if ($error_code === 'stale_status_token') {
        return 409;
    }
    return 403;
}

function ll_tools_wordset_buttons_shortcode_status_ajax(): void {
    $throttle = ll_tools_wordset_buttons_shortcode_public_status_throttle();
    if (empty($throttle['allowed'])) {
        $retry_after = max(1, (int) ($throttle['retry_after'] ?? 1));
        if (!headers_sent()) {
            header('Retry-After: ' . $retry_after);
        }
        wp_send_json_error([
            'code' => 'rate_limited',
            'retryAfterMs' => ll_tools_wordset_buttons_shortcode_public_status_retry_payload($retry_after)['retryAfterMs'],
        ], 429);
    }

    $token = isset($_POST['token']) && is_string($_POST['token'])
        ? trim((string) wp_unslash($_POST['token']))
        : '';
    $error_code = '';
    $verified_context = null;
    $scope = ll_tools_wordset_buttons_shortcode_verify_status_token(
        $token,
        $error_code,
        $verified_context
    );
    if (!is_array($scope)) {
        wp_send_json_error(
            ['code' => $error_code],
            ll_tools_wordset_buttons_shortcode_status_error_http_code($error_code)
        );
    }

    $payload = ll_tools_wordset_buttons_shortcode_public_status_payload(
        $scope['atts'],
        $scope['tag']
    );
    $expected_stale_key = ll_tools_wordset_buttons_shortcode_stale_key(
        $scope['atts'],
        $scope['tag']
    );
    $payload_html = (string) ($payload['html'] ?? '');
    if (
        !is_string($verified_context)
        || !hash_equals(
            ll_tools_wordset_buttons_shortcode_cache_key($scope['atts'], $scope['tag']),
            $verified_context
        )
        || (
            !empty($payload['complete'])
            && $payload_html !== ''
            && !ll_tools_wordset_buttons_shortcode_publish_anonymous_html(
                $scope['atts'],
                $scope['tag'],
                $payload_html,
                $verified_context,
                $expected_stale_key
            )
        )
    ) {
        wp_send_json_error(['code' => 'stale_status_token'], 409);
    }

    wp_send_json_success($payload);
}
add_action('wp_ajax_nopriv_ll_tools_wordset_buttons_status', 'll_tools_wordset_buttons_shortcode_status_ajax');

function ll_tools_wordset_buttons_shortcode($atts = [], $content = null, string $tag = ''): string {
    $tag = in_array($tag, ll_tools_wordset_buttons_shortcode_tags(), true)
        ? $tag
        : 'll_wordset_buttons';
    $atts = shortcode_atts([
        'class' => '',
        'hide_empty' => '0',
    ], $atts, $tag);

    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    if (function_exists('ll_tools_wordset_page_enqueue_styles')) {
        ll_tools_wordset_page_enqueue_styles();
    }

    // Initial page rendering is navigation-first. Read a completed generation
    // when one exists, but leave cold count work to the bounded continuation so
    // the visible wordset names and links are not delayed by eligibility scans.
    $complete = true;
    $retry_after_ms = 0;
    return ll_tools_wordset_buttons_shortcode_render(
        $atts,
        $tag,
        true,
        $complete,
        $retry_after_ms,
        false
    );
}
add_shortcode('wordset_buttons', 'll_tools_wordset_buttons_shortcode');
add_shortcode('ll_wordset_buttons', 'll_tools_wordset_buttons_shortcode');
