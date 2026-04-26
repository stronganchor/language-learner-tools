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

function ll_tools_wordset_buttons_shortcode_cache_key(array $atts, string $tag = ''): string {
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

    $payload = [
        'schema' => 1,
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'site' => home_url('/'),
        'locale' => function_exists('get_locale') ? (string) get_locale() : '',
        'wordset_epoch' => $wordset_epoch,
        'category_epoch' => $category_epoch,
        'tag' => sanitize_key($tag !== '' ? $tag : 'll_wordset_buttons'),
        'atts' => [
            'class' => $extra_classes,
            'hide_empty' => $hide_empty,
        ],
    ];

    return 'll_ws_buttons_' . md5((string) wp_json_encode($payload));
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
    $keys = get_option('ll_tools_wordset_buttons_shortcode_cache_keys', []);
    $keys = is_array($keys) ? array_values(array_filter(array_map('sanitize_key', $keys))) : [];

    $deleted = 0;
    foreach ($keys as $key) {
        if (delete_transient($key)) {
            $deleted++;
        }
    }
    delete_option('ll_tools_wordset_buttons_shortcode_cache_keys');

    return $deleted;
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

function ll_tools_get_wordset_button_lesson_counts(array $wordset_ids): array {
    global $wpdb;

    $wordset_ids = array_values(array_unique(array_filter(array_map('intval', $wordset_ids), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    })));
    if (empty($wordset_ids) || !defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')) {
        return [];
    }

    static $request_cache = [];
    $cache_key = md5(wp_json_encode($wordset_ids));
    if (isset($request_cache[$cache_key]) && is_array($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $counts = array_fill_keys($wordset_ids, 0);
    $placeholders = implode(',', array_fill(0, count($wordset_ids), '%d'));
    $sql = $wpdb->prepare(
        "
        SELECT CAST(pm.meta_value AS UNSIGNED) AS wordset_id, COUNT(DISTINCT p.ID) AS lesson_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
           AND pm.meta_key = %s
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
        GROUP BY wordset_id
        ",
        array_merge(
            [LL_TOOLS_VOCAB_LESSON_WORDSET_META, 'll_vocab_lesson', 'publish'],
            $wordset_ids
        )
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $wordset_id = isset($row['wordset_id']) ? (int) $row['wordset_id'] : 0;
        $lesson_count = isset($row['lesson_count']) ? (int) $row['lesson_count'] : 0;
        if ($wordset_id > 0 && array_key_exists($wordset_id, $counts)) {
            $counts[$wordset_id] = max(0, $lesson_count);
        }
    }

    $request_cache[$cache_key] = $counts;
    return $counts;
}

function ll_tools_get_wordset_button_items(bool $hide_empty = false): array {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => $hide_empty,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $visible_term_ids = array_values(array_map('intval', wp_list_pluck($terms, 'term_id')));
    if (function_exists('ll_tools_filter_viewable_wordset_ids')) {
        $visible_term_ids = ll_tools_filter_viewable_wordset_ids($visible_term_ids, (int) get_current_user_id());
    } elseif (function_exists('ll_tools_user_can_view_wordset')) {
        $visible_term_ids = array_values(array_filter($visible_term_ids, static function (int $term_id): bool {
            return ll_tools_user_can_view_wordset($term_id, (int) get_current_user_id());
        }));
    }

    if (empty($visible_term_ids)) {
        return [];
    }

    $lesson_counts = ll_tools_get_wordset_button_lesson_counts($visible_term_ids);
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
            $left_name = ($left_term instanceof WP_Term) ? (string) $left_term->name : '';
            $right_name = ($right_term instanceof WP_Term) ? (string) $right_term->name : '';

            return strnatcasecmp($left_name, $right_name);
        });
    }

    return $items;
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

    $cache_key = ll_tools_wordset_buttons_shortcode_cache_enabled()
        ? ll_tools_wordset_buttons_shortcode_cache_key($atts, $tag)
        : '';
    if ($cache_key !== '') {
        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            return $cached_html;
        }
    }

    $items = ll_tools_get_wordset_button_items(
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0')
    );
    if (empty($items)) {
        return '';
    }

    $classes = ['ll-wordset-page', 'll-wordset-page--shortcode', 'll-wordset-buttons-shortcode'];
    $extra_classes = function_exists('ll_tools_wordset_page_sanitize_class_list')
        ? ll_tools_wordset_page_sanitize_class_list([(string) ($atts['class'] ?? '')])
        : [];
    if (!empty($extra_classes)) {
        $classes = array_merge($classes, $extra_classes);
    }

    ob_start();
    ?>
    <div class="<?php echo esc_attr(implode(' ', array_unique($classes))); ?>">
        <ul class="ll-wordset-buttons-shortcode__list">
            <?php foreach ($items as $item) : ?>
                <?php
                $term = $item['term'] ?? null;
                $lesson_count = isset($item['lesson_count']) ? (int) $item['lesson_count'] : 0;
                if (!$term instanceof WP_Term || $lesson_count <= 0) {
                    continue;
                }

                $url = function_exists('ll_tools_get_wordset_page_view_url')
                    ? (string) ll_tools_get_wordset_page_view_url($term)
                    : '';
                if ($url === '') {
                    continue;
                }

                $button_image = function_exists('ll_tools_get_wordset_button_image_preview_data')
                    ? ll_tools_get_wordset_button_image_preview_data($term, 'medium', true)
                    : ['attachment_id' => 0, 'url' => ''];
                $button_image_url = trim((string) ($button_image['url'] ?? ''));
                $has_button_image = ($button_image_url !== '');

                $count_label = sprintf(
                    /* translators: %d: number of lesson pages in the word set. */
                    _n('%d lesson', '%d lessons', $lesson_count, 'll-tools-text-domain'),
                    $lesson_count
                );
                $link_aria_label = sprintf(
                    /* translators: 1: word set name, 2: lesson count label. */
                    __('%1$s, %2$s', 'll-tools-text-domain'),
                    $term->name,
                    $count_label
                );
                ?>
                <li class="ll-wordset-buttons-shortcode__item">
                    <a class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-buttons-shortcode__button<?php echo $has_button_image ? ' ll-wordset-buttons-shortcode__button--has-image' : ''; ?>" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr($link_aria_label); ?>">
                        <?php if ($has_button_image) : ?>
                            <span class="ll-wordset-buttons-shortcode__media" aria-hidden="true">
                                <img class="ll-wordset-buttons-shortcode__image" src="<?php echo esc_url($button_image_url); ?>" alt="" loading="lazy" decoding="async" />
                            </span>
                        <?php endif; ?>
                        <span class="ll-wordset-buttons-shortcode__label"><?php echo esc_html($term->name); ?></span>
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
    }

    return $html;
}
add_shortcode('wordset_buttons', 'll_tools_wordset_buttons_shortcode');
add_shortcode('ll_wordset_buttons', 'll_tools_wordset_buttons_shortcode');
