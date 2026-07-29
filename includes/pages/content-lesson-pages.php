<?php
// /includes/pages/content-lesson-pages.php
if (!defined('WPINC')) { die; }

function ll_tools_content_lesson_media_label(string $media_type, string $lesson_kind = 'standard'): string {
    if ($lesson_kind === 'article') {
        return __('Article lesson', 'll-tools-text-domain');
    }
    if ($lesson_kind === 'corpus_text' || $media_type === 'text') {
        return __('Text document', 'll-tools-text-domain');
    }

    return ($media_type === 'video')
        ? __('Video lesson', 'll-tools-text-domain')
        : __('Audio lesson', 'll-tools-text-domain');
}

function ll_tools_get_content_lesson_wordset_term($lesson_id) {
    $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id((int) $lesson_id)
        : 0;

    if ($wordset_id <= 0) {
        return null;
    }

    $wordset = get_term($wordset_id, 'wordset');
    return ($wordset instanceof WP_Term && !is_wp_error($wordset)) ? $wordset : null;
}

function ll_tools_get_content_lesson_excerpt(WP_Post $lesson): string {
    $excerpt = trim((string) $lesson->post_excerpt);
    if ($excerpt === '') {
        $excerpt = wp_trim_words(wp_strip_all_tags((string) $lesson->post_content), 28);
    }

    return $excerpt;
}

function ll_tools_get_content_lesson_print_source_url(int $lesson_id, ?array $request_query = null): string {
    if ($lesson_id <= 0) {
        return '';
    }

    $permalink = get_permalink($lesson_id);
    if (!is_string($permalink) || $permalink === '') {
        return '';
    }

    $request_query = $request_query ?? $_GET;
    $allowed_query_keys = [
        'll_locale',
        'll_text_view',
        'll_translation',
        'll_book_language',
        'll_book_section',
    ];
    $query_args = [];
    foreach ($allowed_query_keys as $query_key) {
        if (!isset($request_query[$query_key]) || !is_scalar($request_query[$query_key])) {
            continue;
        }

        $value = trim(sanitize_text_field(wp_unslash((string) $request_query[$query_key])));
        if ($value !== '') {
            $query_args[$query_key] = $value;
        }
    }

    if (!empty($query_args)) {
        $permalink = add_query_arg($query_args, $permalink);
    }

    return $permalink;
}

function ll_tools_content_lesson_shortcode_localized_attribute(array $atts, string $base_key, string $fallback = ''): string {
    $values = [];
    foreach (['tr', 'en', 'de'] as $language_key) {
        $localized_key = $base_key . '_' . $language_key;
        if (isset($atts[$localized_key]) && is_scalar($atts[$localized_key]) && trim((string) $atts[$localized_key]) !== '') {
            $values[$language_key] = trim((string) $atts[$localized_key]);
        }
    }
    if (isset($atts[$base_key]) && is_scalar($atts[$base_key]) && trim((string) $atts[$base_key]) !== '') {
        $values[$base_key] = trim((string) $atts[$base_key]);
    }

    if (function_exists('ll_tools_text_document_localized_map_value')) {
        $value = ll_tools_text_document_localized_map_value($values);
        if ($value !== '') {
            return $value;
        }
    }

    foreach (['tr', 'en', 'de', $base_key] as $language_key) {
        if (isset($values[$language_key]) && $values[$language_key] !== '') {
            return $values[$language_key];
        }
    }

    return $fallback;
}

function ll_tools_get_content_lesson_card_data(WP_Post $lesson): array {
    $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id((int) $lesson->ID)
        : 0;
    $category_ids = function_exists('ll_tools_get_content_lesson_related_category_ids')
        ? ll_tools_get_content_lesson_related_category_ids((int) $lesson->ID)
        : [];
    $media_type = function_exists('ll_tools_get_content_lesson_media_type')
        ? ll_tools_get_content_lesson_media_type((int) $lesson->ID)
        : 'audio';
    $lesson_kind = function_exists('ll_tools_get_content_lesson_kind')
        ? ll_tools_get_content_lesson_kind((int) $lesson->ID)
        : 'standard';
    $display_media_type = $lesson_kind === 'corpus_text' ? 'text' : $media_type;
    $fallback_title = (string) get_the_title($lesson);
    $fallback_excerpt = ll_tools_get_content_lesson_excerpt($lesson);
    $localized_title = function_exists('ll_tools_get_lesson_display_title')
        ? ll_tools_get_lesson_display_title($lesson, ['fallback' => $fallback_title])
        : $fallback_title;
    $localized_excerpt = function_exists('ll_tools_get_lesson_display_excerpt')
        ? ll_tools_get_lesson_display_excerpt($lesson, $fallback_excerpt)
        : $fallback_excerpt;

    return [
        'id' => (int) $lesson->ID,
        'title' => $localized_title,
        'url' => (string) get_permalink($lesson),
        'excerpt' => $localized_excerpt,
        'lesson_kind' => $lesson_kind,
        'media_type' => $display_media_type,
        'media_label' => ll_tools_content_lesson_media_label($display_media_type, $lesson_kind),
        'wordset_id' => $wordset_id,
        'category_ids' => $category_ids,
        'category_count' => count($category_ids),
        'show_in_mix' => function_exists('ll_tools_get_content_lesson_show_in_mix')
            ? ll_tools_get_content_lesson_show_in_mix((int) $lesson->ID)
            : false,
        'prereq_category_ids' => function_exists('ll_tools_get_content_lesson_prereq_category_ids')
            ? ll_tools_get_content_lesson_prereq_category_ids((int) $lesson->ID)
            : [],
        'prereq_lesson_ids' => function_exists('ll_tools_get_content_lesson_prereq_lesson_ids')
            ? ll_tools_get_content_lesson_prereq_lesson_ids((int) $lesson->ID)
            : [],
        'menu_order' => isset($lesson->menu_order) ? (int) $lesson->menu_order : 0,
    ];
}

function ll_tools_corpus_text_collection_page_index_meta_key(): string {
    return '_ll_tools_corpus_text_grid_collection';
}

function ll_tools_corpus_text_collection_page_cache_key(string $collection): string {
    return 'll_corpus_collection_page_' . md5(sanitize_title($collection));
}

/**
 * @return string[]
 */
function ll_tools_corpus_text_page_collections_from_content(string $content): array {
    if ($content === '' || (!has_shortcode($content, 'll_corpus_text_grid') && !has_shortcode($content, 'll_text_document_grid'))) {
        return [];
    }

    $shortcode_pattern = get_shortcode_regex(['ll_corpus_text_grid', 'll_text_document_grid']);
    if (!preg_match_all('/' . $shortcode_pattern . '/s', $content, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $collections = [];
    foreach ($matches as $match) {
        $tag = isset($match[2]) ? (string) $match[2] : '';
        if (!in_array($tag, ['ll_corpus_text_grid', 'll_text_document_grid'], true)) {
            continue;
        }

        $atts = shortcode_parse_atts(isset($match[3]) ? (string) $match[3] : '');
        $collection = is_array($atts) && isset($atts['collection']) && is_scalar($atts['collection'])
            ? sanitize_title((string) $atts['collection'])
            : '';
        if ($collection !== '') {
            $collections[$collection] = true;
        }
    }

    $collections = array_keys($collections);
    sort($collections, SORT_STRING);
    return $collections;
}

/**
 * @return string[]
 */
function ll_tools_get_corpus_text_collection_page_index(int $page_id): array {
    if ($page_id <= 0) {
        return [];
    }

    $collections = get_post_meta($page_id, ll_tools_corpus_text_collection_page_index_meta_key(), false);
    $collections = array_values(array_unique(array_filter(array_map(static function ($collection): string {
        return is_scalar($collection) ? sanitize_title((string) $collection) : '';
    }, (array) $collections))));
    sort($collections, SORT_STRING);
    return $collections;
}

/**
 * @param string[] $collections
 */
function ll_tools_invalidate_corpus_text_collection_page_cache(array $collections): void {
    foreach (array_values(array_unique($collections)) as $collection) {
        $collection = sanitize_title((string) $collection);
        if ($collection !== '') {
            delete_transient(ll_tools_corpus_text_collection_page_cache_key($collection));
        }
    }
}

function ll_tools_sync_corpus_text_collection_page_index(int $post_id, $post = null): void {
    if ($post_id <= 0 || wp_is_post_revision($post_id)) {
        return;
    }

    $post = $post instanceof WP_Post ? $post : get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
        return;
    }

    $previous_collections = ll_tools_get_corpus_text_collection_page_index($post_id);
    $next_collections = $post->post_status === 'publish'
        ? ll_tools_corpus_text_page_collections_from_content((string) $post->post_content)
        : [];

    delete_post_meta($post_id, ll_tools_corpus_text_collection_page_index_meta_key());
    foreach ($next_collections as $collection) {
        add_post_meta($post_id, ll_tools_corpus_text_collection_page_index_meta_key(), $collection, false);
    }

    ll_tools_invalidate_corpus_text_collection_page_cache(array_merge($previous_collections, $next_collections));
}

function ll_tools_sync_corpus_text_collection_page_index_on_save(int $post_id, WP_Post $post): void {
    ll_tools_sync_corpus_text_collection_page_index($post_id, $post);
}
add_action('save_post_page', 'll_tools_sync_corpus_text_collection_page_index_on_save', 20, 2);

function ll_tools_invalidate_corpus_text_collection_page_index_on_delete(int $post_id, $post = null): void {
    $post = $post instanceof WP_Post ? $post : get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
        return;
    }

    $collections = array_merge(
        ll_tools_get_corpus_text_collection_page_index($post_id),
        ll_tools_corpus_text_page_collections_from_content((string) $post->post_content)
    );
    ll_tools_invalidate_corpus_text_collection_page_cache($collections);
}
add_action('before_delete_post', 'll_tools_invalidate_corpus_text_collection_page_index_on_delete', 20, 2);

function ll_tools_find_indexed_corpus_text_collection_page_id(string $collection): int {
    $collection = sanitize_title($collection);
    if ($collection === '') {
        return 0;
    }

    $page_ids = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'orderby' => [
            'menu_order' => 'ASC',
            'title' => 'ASC',
            'ID' => 'ASC',
        ],
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_key' => ll_tools_corpus_text_collection_page_index_meta_key(),
        'meta_value' => $collection,
    ]);

    return !empty($page_ids) ? max(0, (int) $page_ids[0]) : 0;
}

/**
 * Finds one pre-index page without hydrating the site's page catalog.
 */
function ll_tools_find_legacy_corpus_text_collection_page_id(string $collection): int {
    global $wpdb;

    $collection = sanitize_title($collection);
    if ($collection === '') {
        return 0;
    }

    $tag_grid = '%' . $wpdb->esc_like('[ll_corpus_text_grid') . '%';
    $tag_document = '%' . $wpdb->esc_like('[ll_text_document_grid') . '%';
    $collection_pattern = 'collection[[:space:]]*=[[:space:]]*["\']'
        . preg_quote($collection, '/')
        . '["\']';
    $sql = $wpdb->prepare(
        "SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = %s
          AND post_status = %s
          AND (post_content LIKE %s OR post_content LIKE %s)
          AND post_content REGEXP %s
        ORDER BY menu_order ASC, post_title ASC, ID ASC
        LIMIT 20",
        'page',
        'publish',
        $tag_grid,
        $tag_document,
        $collection_pattern
    );
    $page_ids = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

    foreach ((array) $page_ids as $page_id) {
        $page = get_post((int) $page_id);
        if (!($page instanceof WP_Post)
            || !in_array($collection, ll_tools_corpus_text_page_collections_from_content((string) $page->post_content), true)) {
            continue;
        }

        ll_tools_sync_corpus_text_collection_page_index((int) $page->ID, $page);
        return (int) $page->ID;
    }

    return 0;
}

function ll_tools_get_corpus_text_collection_page_id(string $collection): int {
    $collection = sanitize_title($collection);
    if ($collection === '') {
        return 0;
    }

    $cache_key = ll_tools_corpus_text_collection_page_cache_key($collection);
    $cached = get_transient($cache_key);
    if (is_array($cached) && array_key_exists('page_id', $cached)) {
        $cached_page_id = max(0, (int) $cached['page_id']);
        if ($cached_page_id === 0) {
            return 0;
        }

        $cached_page = get_post($cached_page_id);
        if ($cached_page instanceof WP_Post
            && $cached_page->post_type === 'page'
            && $cached_page->post_status === 'publish'
            && in_array($collection, ll_tools_get_corpus_text_collection_page_index($cached_page_id), true)) {
            return $cached_page_id;
        }

        delete_transient($cache_key);
    }

    $page_id = ll_tools_find_indexed_corpus_text_collection_page_id($collection);
    if ($page_id <= 0) {
        $page_id = ll_tools_find_legacy_corpus_text_collection_page_id($collection);
    }

    set_transient($cache_key, ['page_id' => $page_id], 12 * HOUR_IN_SECONDS);
    return $page_id;
}

function ll_tools_get_corpus_text_collection_link(int $lesson_id): array {
    if ($lesson_id <= 0 || get_post_type($lesson_id) !== 'll_content_lesson') {
        return ['url' => '', 'label' => ''];
    }

    $collection_meta = defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META')
        ? LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META
        : '_ll_tools_corpus_text_collection';
    $collection_label_meta = defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META')
        ? LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META
        : '_ll_tools_corpus_text_collection_label';
    $collection = sanitize_title((string) get_post_meta($lesson_id, $collection_meta, true));
    $label = trim((string) get_post_meta($lesson_id, $collection_label_meta, true));
    if ($label === '') {
        $label = __('Texts', 'll-tools-text-domain');
    }
    if (function_exists('ll_tools_get_content_lesson_localized_collection_label')) {
        $label = ll_tools_get_content_lesson_localized_collection_label($lesson_id, $label);
    }
    if ($collection === '') {
        return ['url' => '', 'label' => $label];
    }

    $page_id = ll_tools_get_corpus_text_collection_page_id($collection);
    if ($page_id > 0) {
        $url = get_permalink($page_id);
        return ['url' => is_string($url) ? $url : '', 'label' => $label];
    }

    return ['url' => '', 'label' => $label];
}

/**
 * Return the bounded first page used by the mixed word-set surface.
 *
 * Callers that need an authoritative lesson index must inspect `$complete` and
 * fall back to the paginated `[ll_content_lesson_index]` surface when false.
 */
function ll_tools_get_content_lessons_for_wordset(
    int $wordset_id,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    if (function_exists('ll_tools_user_can_view_wordset')) {
        $visibility_complete = true;
        if (!ll_tools_user_can_view_wordset($wordset_id, 0, $visibility_complete)) {
            if (!$visibility_complete) {
                $complete = false;
            }
            return [];
        }
    }

    $limit = max(1, min(500, (int) apply_filters(
        'll_tools_content_lessons_for_wordset_limit',
        200,
        $wordset_id
    )));
    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $limit + 1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
        ],
    ]);
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    if (count($posts) > $limit) {
        $complete = false;
        $posts = array_slice($posts, 0, $limit);
    }

    $lessons = [];
    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }
        if (function_exists('ll_tools_content_lesson_is_corpus_text') && ll_tools_content_lesson_is_corpus_text((int) $post->ID)) {
            continue;
        }

        $lessons[] = ll_tools_get_content_lesson_card_data($post);
    }

    return $lessons;
}

function ll_tools_get_content_lessons_for_vocab_lesson(int $wordset_id, int $category_id): array {
    $wordset_id = max(0, $wordset_id);
    $category_id = max(0, $category_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        return [];
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id)) {
        return [];
    }

    $limit = (int) apply_filters('ll_tools_vocab_lesson_related_content_lesson_limit', 6, $wordset_id, $category_id);
    $limit = max(1, min(12, $limit));
    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
            [
                'relation' => 'OR',
                [
                    'key' => LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
                    'value' => 'i:' . $category_id . ';',
                    'compare' => 'LIKE',
                ],
                [
                    'key' => LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
                    'value' => '"' . $category_id . '"',
                    'compare' => 'LIKE',
                ],
            ],
            [
                'relation' => 'OR',
                [
                    'key' => LL_TOOLS_CONTENT_LESSON_KIND_META,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => LL_TOOLS_CONTENT_LESSON_KIND_META,
                    'value' => 'corpus_text',
                    'compare' => '!=',
                ],
            ],
        ],
    ]);

    $matches = [];
    foreach ((array) $posts as $post) {
        if ($post instanceof WP_Post) {
            $matches[] = ll_tools_get_content_lesson_card_data($post);
        }
    }

    return $matches;
}

function ll_tools_get_content_lesson_related_vocab_items(int $lesson_id): array {
    $lesson_id = (int) $lesson_id;
    if ($lesson_id <= 0) {
        return [];
    }

    $wordset = ll_tools_get_content_lesson_wordset_term($lesson_id);
    if (!($wordset instanceof WP_Term)) {
        return [];
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset)) {
        return [];
    }

    $items = [];
    foreach ((array) ll_tools_get_content_lesson_related_category_ids($lesson_id) as $category_id) {
        $category = get_term((int) $category_id, 'word-category');
        if (!($category instanceof WP_Term) || is_wp_error($category)) {
            continue;
        }
        if (function_exists('ll_tools_user_can_view_category') && !ll_tools_user_can_view_category($category)) {
            continue;
        }

        $lesson_post_ids = get_posts([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                    'value' => (string) $wordset->term_id,
                ],
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
                    'value' => (string) $category->term_id,
                ],
            ],
        ]);
        $lesson_post_id = !empty($lesson_post_ids) ? (int) $lesson_post_ids[0] : 0;
        if ($lesson_post_id <= 0) {
            continue;
        }

        $label = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($category, ['wordset_ids' => [(int) $wordset->term_id]])
            : $category->name;

        $items[] = [
            'id' => (int) $category->term_id,
            'label' => (string) ($label !== '' ? $label : $category->name),
            'url' => (string) get_permalink($lesson_post_id),
        ];
    }

    return $items;
}

function ll_tools_render_content_lesson_cards(array $lessons, array $args = []): string {
    if (empty($lessons)) {
        return '';
    }

    $title = isset($args['title']) ? (string) $args['title'] : __('Main Lessons', 'll-tools-text-domain');
    $description = isset($args['description']) ? (string) $args['description'] : '';
    $context = isset($args['context']) ? sanitize_html_class((string) $args['context']) : 'default';
    $open_label = isset($args['open_label']) ? (string) $args['open_label'] : __('Open lesson', 'll-tools-text-domain');
    $show_head = $title !== '' || $description !== '';

    ob_start();
    ?>
    <section class="ll-content-lessons-section ll-content-lessons-section--<?php echo esc_attr($context); ?>">
        <?php if ($show_head) : ?>
            <div class="ll-content-lessons-section__head">
                <?php if ($title !== '') : ?>
                    <h2 class="ll-content-lessons-section__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>
                <?php if ($description !== '') : ?>
                    <p class="ll-content-lessons-section__description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="ll-content-lessons-grid" role="list">
            <?php foreach ($lessons as $lesson) : ?>
                <?php
                $lesson_id = isset($lesson['id']) ? (int) $lesson['id'] : 0;
                $lesson_title = isset($lesson['title']) ? (string) $lesson['title'] : '';
                $lesson_url = isset($lesson['url']) ? (string) $lesson['url'] : '';
                $lesson_excerpt = isset($lesson['excerpt']) ? (string) $lesson['excerpt'] : '';
                $media_label = isset($lesson['media_label']) ? (string) $lesson['media_label'] : '';
                $category_count = isset($lesson['category_count']) ? (int) $lesson['category_count'] : 0;
                ?>
                <article class="ll-content-lesson-card" role="listitem" data-ll-content-lesson-card data-lesson-id="<?php echo esc_attr((string) $lesson_id); ?>">
                    <div class="ll-content-lesson-card__meta">
                        <?php if ($media_label !== '') : ?>
                            <span class="ll-content-lesson-card__pill ll-content-lesson-card__pill--media"><?php echo esc_html($media_label); ?></span>
                        <?php endif; ?>
                        <?php if ($category_count > 0) : ?>
                            <span class="ll-content-lesson-card__pill ll-content-lesson-card__pill--count">
                                <?php
                                echo esc_html(sprintf(
                                    _n('%d vocab lesson', '%d vocab lessons', $category_count, 'll-tools-text-domain'),
                                    $category_count
                                ));
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="ll-content-lesson-card__title">
                        <?php if ($lesson_url !== '') : ?>
                            <a href="<?php echo esc_url($lesson_url); ?>"><?php echo esc_html($lesson_title); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($lesson_title); ?>
                        <?php endif; ?>
                    </h3>
                    <?php if ($lesson_excerpt !== '') : ?>
                        <p class="ll-content-lesson-card__excerpt"><?php echo esc_html($lesson_excerpt); ?></p>
                    <?php endif; ?>
                    <?php if ($lesson_url !== '') : ?>
                        <div class="ll-content-lesson-card__actions">
                            <a class="ll-study-btn tiny ll-content-lesson-card__link" href="<?php echo esc_url($lesson_url); ?>">
                                <?php echo esc_html($open_label); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_corpus_text_grid_default_limit(): int {
    $limit = (int) apply_filters('ll_tools_corpus_text_grid_default_limit', 24);

    return max(1, $limit);
}

function ll_tools_normalize_corpus_text_grid_limit($raw_limit): int {
    if (is_string($raw_limit) && strtolower(trim($raw_limit)) === 'all') {
        return -1;
    }

    $limit = (int) $raw_limit;
    if ($limit < 0) {
        return -1;
    }
    if ($limit === 0) {
        return ll_tools_corpus_text_grid_default_limit();
    }

    return $limit;
}

function ll_tools_get_corpus_text_grid_lessons(array $args = []): array {
    $result = ll_tools_get_corpus_text_grid_query_result($args);

    return (array) ($result['lessons'] ?? []);
}

function ll_tools_get_corpus_text_grid_query_result(array $args = []): array {
    $limit = ll_tools_normalize_corpus_text_grid_limit(
        array_key_exists('limit', $args) ? $args['limit'] : ll_tools_corpus_text_grid_default_limit()
    );
    $page = $limit > 0 ? max(1, (int) ($args['page'] ?? 1)) : 1;
    $collection = isset($args['collection']) ? sanitize_title((string) $args['collection']) : '';
    $source_author = isset($args['source_author']) ? sanitize_text_field((string) $args['source_author']) : '';
    $ids = [];
    if (!empty($args['ids'])) {
        $id_parts = preg_split('/[\s,]+/', (string) $args['ids']);
        $ids = is_array($id_parts)
            ? array_values(array_filter(array_map('absint', $id_parts)))
            : [];
    }
    $slugs = [];
    if (!empty($args['slugs'])) {
        $slug_parts = preg_split('/[\s,]+/', (string) $args['slugs']);
        $slugs = is_array($slug_parts)
            ? array_values(array_filter(array_map('sanitize_title', $slug_parts)))
            : [];
    }

    $meta_query = [
        [
            'key' => LL_TOOLS_CONTENT_LESSON_KIND_META,
            'value' => 'corpus_text',
        ],
    ];
    if ($collection !== '') {
        $meta_query[] = [
            'key' => LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META,
            'value' => $collection,
        ];
    }
    if ($source_author !== '') {
        $meta_query[] = [
            'key' => LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META,
            'value' => $source_author,
            'compare' => 'LIKE',
        ];
    }
    if (count($meta_query) > 1) {
        $meta_query['relation'] = 'AND';
    }
    $orderby = isset($args['orderby']) ? trim((string) $args['orderby']) : 'menu_order title';
    if (!in_array($orderby, ['menu_order title', 'title', 'date', 'modified', 'post__in', 'post_name__in'], true)) {
        $orderby = 'menu_order title';
    }

    $query_args = [
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'orderby' => $orderby,
        'order' => (isset($args['order']) && strtoupper((string) $args['order']) === 'DESC') ? 'DESC' : 'ASC',
        'paged' => $page,
        'no_found_rows' => $limit < 0,
        'meta_query' => $meta_query,
    ];
    if (!empty($ids)) {
        $query_args['post__in'] = $ids;
        $query_args['orderby'] = 'post__in';
    } elseif (!empty($slugs)) {
        $query_args['post_name__in'] = $slugs;
        $query_args['orderby'] = 'post_name__in';
    }

    $query = new WP_Query($query_args);
    $posts = $query->posts;
    $lessons = [];
    foreach ((array) $posts as $post) {
        if ($post instanceof WP_Post) {
            $lessons[] = ll_tools_get_content_lesson_card_data($post);
        }
    }

    $total = $limit > 0 ? (int) $query->found_posts : count($lessons);
    $total_pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;

    return [
        'lessons' => $lessons,
        'limit' => $limit,
        'page' => $page,
        'total' => $total,
        'total_pages' => $total_pages,
        'has_previous_page' => $limit > 0 && $page > 1,
        'has_next_page' => $limit > 0 && $page < $total_pages,
    ];
}

function ll_tools_corpus_text_grid_inline_styles(): string {
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style id="ll-corpus-text-grid-inline-css">'
        . '.ll-corpus-text-grid{--ll-cl-card:#fffdf8;--ll-cl-border:#e6ddcf;--ll-cl-text:#2f2a24;--ll-cl-muted:#6f6659;--ll-cl-accent:#1f6b5c;color:var(--ll-cl-text);}'
        . '.ll-corpus-text-grid .ll-content-lessons-section{max-width:1180px;margin:0 auto 24px;padding:0;border:0;background:transparent;box-shadow:none;}'
        . '.ll-corpus-text-grid .ll-content-lessons-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:18px;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card{display:flex;flex-direction:column;gap:12px;min-height:100%;padding:22px;border:1px solid #d7c5a9;border-radius:8px;background:#fffdf8;box-shadow:0 12px 26px rgba(57,46,32,.09);}'
        . '.ll-corpus-text-grid .ll-content-lesson-card:hover,.ll-corpus-text-grid .ll-content-lesson-card:focus-within{border-color:#bfa279;box-shadow:0 14px 30px rgba(57,46,32,.13);}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__pill--media{display:none!important;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__title{margin:0;font-size:20px;line-height:1.2;font-weight:700;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__title a{color:inherit;text-decoration:none;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__title a:hover,.ll-corpus-text-grid .ll-content-lesson-card__title a:focus-visible{color:var(--ll-cl-accent);outline:none;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__excerpt{margin:0;color:var(--ll-cl-muted);font-size:14px;line-height:1.6;}'
        . '.ll-corpus-text-grid .ll-content-lesson-card__actions{margin-top:auto;padding-top:4px;}'
        . '.ll-corpus-text-grid .ll-study-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 11px;border:1px solid var(--ll-cl-border);border-radius:999px;background:#fff;color:var(--ll-cl-text);font-size:13px;font-weight:700;text-decoration:none!important;}'
        . '.ll-corpus-text-grid .ll-study-btn:hover,.ll-corpus-text-grid .ll-study-btn:focus-visible{border-color:var(--ll-cl-accent);color:var(--ll-cl-accent);outline:none;text-decoration:none!important;}'
        . '.ll-corpus-text-grid__pagination{display:flex;align-items:center;justify-content:center;gap:12px;margin:22px 0 0;font-size:14px;}'
        . '.ll-corpus-text-grid__page-status{color:var(--ll-cl-muted);font-weight:700;}'
        . '.ll-corpus-text-grid__page-link{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 12px;border:1px solid var(--ll-cl-border);border-radius:999px;background:#fff;color:var(--ll-cl-text);font-weight:700;text-decoration:none!important;}'
        . '.ll-corpus-text-grid__page-link:hover,.ll-corpus-text-grid__page-link:focus-visible{border-color:var(--ll-cl-accent);color:var(--ll-cl-accent);outline:none;text-decoration:none!important;}'
        . '@media(max-width:820px){.ll-corpus-text-grid .ll-content-lessons-grid{grid-template-columns:1fr;}}'
        . '</style>';
}

function ll_tools_corpus_text_grid_page_param(array $atts): string {
    $page_param = isset($atts['page_param']) ? sanitize_key((string) $atts['page_param']) : 'll_corpus_text_page';

    return $page_param !== '' ? $page_param : 'll_corpus_text_page';
}

function ll_tools_corpus_text_grid_requested_page(array $atts): int {
    if (isset($atts['page']) && trim((string) $atts['page']) !== '') {
        return max(1, (int) $atts['page']);
    }

    $page_param = ll_tools_corpus_text_grid_page_param($atts);
    if (isset($_GET[$page_param]) && is_scalar($_GET[$page_param])) {
        return max(1, absint(wp_unslash((string) $_GET[$page_param])));
    }

    return 1;
}

function ll_tools_corpus_text_grid_current_url(): string {
    $permalink = get_permalink();
    if (is_string($permalink) && $permalink !== '') {
        return $permalink;
    }

    if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
        $request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        if ($request_uri !== '') {
            return home_url($request_uri);
        }
    }

    return home_url('/');
}

function ll_tools_corpus_text_grid_page_url(string $base_url, string $page_param, int $target_page): string {
    if ($target_page <= 1) {
        return (string) remove_query_arg($page_param, $base_url);
    }

    return (string) add_query_arg($page_param, (string) $target_page, $base_url);
}

function ll_tools_render_corpus_text_grid_pagination(array $query_result, array $atts): string {
    $total_pages = max(1, (int) ($query_result['total_pages'] ?? 1));
    if ($total_pages <= 1) {
        return '';
    }

    $page = max(1, (int) ($query_result['page'] ?? 1));
    $page_param = ll_tools_corpus_text_grid_page_param($atts);
    $base_url = ll_tools_corpus_text_grid_current_url();
    $previous_label = isset($atts['previous_label']) && trim((string) $atts['previous_label']) !== ''
        ? (string) $atts['previous_label']
        : __('Previous', 'll-tools-text-domain');
    $next_label = isset($atts['next_label']) && trim((string) $atts['next_label']) !== ''
        ? (string) $atts['next_label']
        : __('Next', 'll-tools-text-domain');

    ob_start();
    ?>
    <nav class="ll-corpus-text-grid__pagination" aria-label="<?php echo esc_attr__('Text pages', 'll-tools-text-domain'); ?>">
        <?php if ($page > 1) : ?>
            <a class="ll-corpus-text-grid__page-link ll-corpus-text-grid__page-link--previous" href="<?php echo esc_url(ll_tools_corpus_text_grid_page_url($base_url, $page_param, $page - 1)); ?>">
                <?php echo esc_html($previous_label); ?>
            </a>
        <?php endif; ?>
        <span class="ll-corpus-text-grid__page-status">
            <?php
            echo esc_html(sprintf(
                __('Page %1$d of %2$d', 'll-tools-text-domain'),
                $page,
                $total_pages
            ));
            ?>
        </span>
        <?php if ($page < $total_pages) : ?>
            <a class="ll-corpus-text-grid__page-link ll-corpus-text-grid__page-link--next" href="<?php echo esc_url(ll_tools_corpus_text_grid_page_url($base_url, $page_param, $page + 1)); ?>">
                <?php echo esc_html($next_label); ?>
            </a>
        <?php endif; ?>
    </nav>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_corpus_text_grid_shortcode($atts = []): string {
    $atts = shortcode_atts([
        'collection' => '',
        'source_author' => '',
        'ids' => '',
        'slugs' => '',
        'limit' => (string) ll_tools_corpus_text_grid_default_limit(),
        'page' => '',
        'page_param' => 'll_corpus_text_page',
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'title' => __('Texts', 'll-tools-text-domain'),
        'title_tr' => '',
        'title_en' => '',
        'title_de' => '',
        'description' => '',
        'description_tr' => '',
        'description_en' => '',
        'description_de' => '',
        'open_label' => __('Open text', 'll-tools-text-domain'),
        'open_label_tr' => '',
        'open_label_en' => '',
        'open_label_de' => '',
        'previous_label' => __('Previous', 'll-tools-text-domain'),
        'next_label' => __('Next', 'll-tools-text-domain'),
    ], is_array($atts) ? $atts : [], 'll_corpus_text_grid');

    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/css/content-lesson-pages.css', 'll-tools-content-lesson-pages', ['ll-tools-style']);
    }

    $atts['page'] = (string) ll_tools_corpus_text_grid_requested_page($atts);
    $query_result = ll_tools_get_corpus_text_grid_query_result($atts);
    $lessons = (array) ($query_result['lessons'] ?? []);
    if (empty($lessons)) {
        return '';
    }

    return ll_tools_corpus_text_grid_inline_styles()
        . '<div class="ll-corpus-text-grid">'
        . ll_tools_render_content_lesson_cards($lessons, [
            'title' => ll_tools_content_lesson_shortcode_localized_attribute($atts, 'title', (string) $atts['title']),
            'description' => ll_tools_content_lesson_shortcode_localized_attribute($atts, 'description', (string) $atts['description']),
            'context' => 'corpus-text-grid',
            'open_label' => ll_tools_content_lesson_shortcode_localized_attribute($atts, 'open_label', (string) $atts['open_label']),
        ])
        . ll_tools_render_corpus_text_grid_pagination($query_result, $atts)
        . '</div>';
}

function ll_tools_register_corpus_text_grid_shortcodes(): void {
    add_shortcode('ll_corpus_text_grid', 'll_tools_corpus_text_grid_shortcode');
    add_shortcode('ll_text_document_grid', 'll_tools_corpus_text_grid_shortcode');
}
add_action('init', 'll_tools_register_corpus_text_grid_shortcodes');

function ll_tools_render_content_lesson_related_vocab_links(array $items, array $args = []): string {
    if (empty($items)) {
        return '';
    }

    $title = isset($args['title']) ? (string) $args['title'] : __('Related Vocab Lessons', 'll-tools-text-domain');
    $description = isset($args['description']) ? (string) $args['description'] : '';

    ob_start();
    ?>
    <section class="ll-content-lessons-section ll-content-lessons-section--related-vocab">
        <div class="ll-content-lessons-section__head">
            <h2 class="ll-content-lessons-section__title"><?php echo esc_html($title); ?></h2>
            <?php if ($description !== '') : ?>
                <p class="ll-content-lessons-section__description"><?php echo esc_html($description); ?></p>
            <?php endif; ?>
        </div>
        <div class="ll-content-lesson-related-links" role="list">
            <?php foreach ($items as $item) : ?>
                <?php
                $item_label = isset($item['label']) ? (string) $item['label'] : '';
                $item_url = isset($item['url']) ? (string) $item['url'] : '';
                if ($item_label === '' || $item_url === '') {
                    continue;
                }
                ?>
                <a class="ll-content-lesson-related-link" role="listitem" href="<?php echo esc_url($item_url); ?>">
                    <span class="ll-content-lesson-related-link__label"><?php echo esc_html($item_label); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_content_lesson_template_include($template) {
    if (!is_singular('ll_content_lesson')) {
        return $template;
    }
    if (!function_exists('ll_tools_locate_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }

    $located = ll_tools_locate_template('content-lesson-template.php');
    return $located !== '' ? $located : $template;
}
add_filter('template_include', 'll_tools_content_lesson_template_include', 20);

function ll_tools_content_lesson_enforce_frontend_access(): void {
    if (!is_singular('ll_content_lesson')) {
        return;
    }

    $lesson_id = (int) get_queried_object_id();
    if ($lesson_id <= 0) {
        return;
    }

    if (function_exists('ll_tools_content_lesson_is_corpus_text') && ll_tools_content_lesson_is_corpus_text($lesson_id)) {
        return;
    }

    $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id($lesson_id)
        : 0;

    if ($wordset_id > 0 && (!function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset($wordset_id))) {
        return;
    }

    global $wp_query;
    if ($wp_query instanceof WP_Query) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
}
add_action('template_redirect', 'll_tools_content_lesson_enforce_frontend_access', 1);

function ll_tools_content_lesson_enqueue_assets(): void {
    $is_content_lesson = is_singular('ll_content_lesson');
    $is_vocab_lesson = is_singular('ll_vocab_lesson');
    $is_wordset_page = function_exists('ll_tools_is_wordset_page_context') && ll_tools_is_wordset_page_context();
    $is_corpus_grid_page = false;
    if (!$is_content_lesson && is_singular()) {
        $post = get_post();
        $post_content = $post instanceof WP_Post ? (string) $post->post_content : '';
        $is_corpus_grid_page = $post_content !== ''
            && (has_shortcode($post_content, 'll_corpus_text_grid') || has_shortcode($post_content, 'll_text_document_grid'));
    }

    if (!$is_content_lesson && !$is_vocab_lesson && !$is_wordset_page && !$is_corpus_grid_page) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/content-lesson-pages.css', 'll-tools-content-lesson-pages', ['ll-tools-style']);

    if (!$is_content_lesson) {
        return;
    }

    $lesson_id = (int) get_queried_object_id();
    $is_corpus_text = $lesson_id > 0
        && function_exists('ll_tools_content_lesson_is_corpus_text')
        && ll_tools_content_lesson_is_corpus_text($lesson_id);

    if ($is_corpus_text) {
        ll_enqueue_asset_by_timestamp('/js/text-document.js', 'll-tools-text-document', [], true);
    } else {
        ll_enqueue_asset_by_timestamp('/js/content-lesson-progress.js', 'll-tools-content-lesson-progress', [], true);
        wp_localize_script('ll-tools-content-lesson-progress', 'llToolsContentLessonProgress', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => 'll_tools_content_lesson_completion',
            'nonce' => wp_create_nonce('ll_tools_content_lesson_completion'),
            'i18n' => [
                'complete' => __('Completed', 'll-tools-text-domain'),
                'incomplete' => __('Mark complete', 'll-tools-text-domain'),
                'saving' => __('Saving...', 'll-tools-text-domain'),
                'saved' => __('Progress saved.', 'll-tools-text-domain'),
                'error' => __('Lesson progress could not be saved.', 'll-tools-text-domain'),
            ],
        ]);
    }

    if ($is_corpus_text
        && function_exists('ll_tools_current_user_can_manage_text_document_review_notes')
        && ll_tools_current_user_can_manage_text_document_review_notes($lesson_id)) {
        ll_enqueue_asset_by_timestamp('/js/text-document-review-notes.js', 'll-tools-text-document-review-notes', [], true);
        wp_localize_script('ll-tools-text-document-review-notes', 'llToolsTextDocumentReviewNotes', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => 'll_tools_save_text_document_review_note',
            'nonce' => wp_create_nonce('ll_text_document_review_note'),
            'i18n' => [
                'saving' => __('Saving review note...', 'll-tools-text-domain'),
                'saved' => __('Review note saved.', 'll-tools-text-domain'),
                'error' => __('Unable to save the review note.', 'll-tools-text-domain'),
            ],
        ]);
    }

    if ($is_corpus_text) {
        $media_url = function_exists('ll_tools_get_content_lesson_media_url')
            ? ll_tools_get_content_lesson_media_url($lesson_id)
            : '';
        $cues = function_exists('ll_tools_get_content_lesson_cues')
            ? ll_tools_get_content_lesson_cues($lesson_id)
            : [];
        if ($media_url === '' && empty($cues)) {
            return;
        }
    }

    ll_enqueue_asset_by_timestamp('/js/content-lesson-player.js', 'll-tools-content-lesson-player', [], true);
    wp_localize_script('ll-tools-content-lesson-player', 'llToolsContentLessonPlayer', [
        'i18n' => [
            'currentCue' => __('Current transcript line', 'll-tools-text-domain'),
            'transcriptRegion' => __('Lesson transcript', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'll_tools_content_lesson_enqueue_assets', 130);
