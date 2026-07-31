<?php
if (!defined('WPINC')) { die; }

require_once __DIR__ . '/../legacy-content-lesson-contracts.php';

if (!defined('LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX')) {
    define('LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX', 100);
}
if (!defined('LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX')) {
    define('LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX', 100);
}
if (!defined('LL_TOOLS_CONTENT_LESSON_INDEX_CATEGORY_MAX')) {
    define('LL_TOOLS_CONTENT_LESSON_INDEX_CATEGORY_MAX', 50);
}

function ll_tools_content_lesson_index_resolve_wordset_id($wordset): int {
    if ((is_string($wordset) && trim($wordset) === '') || $wordset === null) {
        return ll_tools_get_legacy_lesson_default_wordset_id();
    }

    if (function_exists('ll_tools_resolve_wordset_term_id')) {
        $wordset_id = ll_tools_resolve_wordset_term_id($wordset);
    } else {
        $wordset_id = is_numeric($wordset) ? absint($wordset) : 0;
    }
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return 0;
    }

    return $wordset_id;
}

/**
 * @param mixed $raw
 * @return int[]
 */
function ll_tools_content_lesson_index_category_ids($raw): array {
    return array_slice(
        ll_tools_normalize_legacy_lesson_category_ids($raw),
        0,
        LL_TOOLS_CONTENT_LESSON_INDEX_CATEGORY_MAX
    );
}

function ll_tools_content_lesson_index_list_key(
    int $wordset_id,
    array $category_ids,
    string $requested_key = ''
): string {
    $requested_key = sanitize_key($requested_key);
    if ($requested_key !== '') {
        return substr($requested_key, 0, 32);
    }

    return substr(md5($wordset_id . ':' . implode(',', $category_ids)), 0, 12);
}

function ll_tools_content_lesson_index_page_query_arg(string $list_key): string {
    $list_key = sanitize_key($list_key);
    if ($list_key === '') {
        $list_key = 'default';
    }

    return 'll_lesson_page_' . substr($list_key, 0, 32);
}

function ll_tools_content_lesson_index_requested_page(string $query_arg): int {
    $raw_page = isset($_GET[$query_arg]) && is_scalar($_GET[$query_arg])
        ? absint(wp_unslash((string) $_GET[$query_arg]))
        : 1;

    return max(1, min(LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX, $raw_page));
}

/**
 * Keep index pagination stable when lessons match more than one exact category.
 */
function ll_tools_content_lesson_index_posts_distinct(
    string $distinct,
    WP_Query $query
): string {
    return $query->get('ll_tools_content_lesson_index_query')
        ? 'DISTINCT'
        : $distinct;
}
add_filter(
    'posts_distinct',
    'll_tools_content_lesson_index_posts_distinct',
    10,
    2
);

/**
 * Admit only structurally valid retained bridges into the compatibility index.
 *
 * Validation happens in SQL so OFFSET and the continuation row operate on the
 * same eligible sequence that is rendered. Runtime URL validation below then
 * fails the whole read closed if a permalink filter violates the local-source
 * contract; it never removes a row after pagination.
 */
function ll_tools_content_lesson_index_retained_source_where(
    string $where,
    WP_Query $query
): string {
    if (!$query->get(
        'll_tools_content_lesson_index_include_retained_sources'
    )) {
        return $where;
    }

    global $wpdb;

    return $where . $wpdb->prepare(
        " AND (
            NOT EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} AS ll_retained_marker_absent
                WHERE ll_retained_marker_absent.post_id = {$wpdb->posts}.ID
                  AND ll_retained_marker_absent.meta_key = %s
            )
            OR (
                (
                    SELECT COUNT(*)
                    FROM {$wpdb->postmeta} AS ll_retained_marker_count
                    WHERE ll_retained_marker_count.post_id = {$wpdb->posts}.ID
                      AND ll_retained_marker_count.meta_key = %s
                ) = 1
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} AS ll_retained_marker_valid
                    WHERE ll_retained_marker_valid.post_id = {$wpdb->posts}.ID
                      AND ll_retained_marker_valid.meta_key = %s
                      AND ll_retained_marker_valid.meta_value = %s
                )
                AND (
                    SELECT COUNT(*)
                    FROM {$wpdb->postmeta} AS ll_retained_source_count
                    WHERE ll_retained_source_count.post_id = {$wpdb->posts}.ID
                      AND ll_retained_source_count.meta_key = %s
                ) = 1
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} AS ll_retained_source_meta
                    INNER JOIN {$wpdb->posts} AS ll_retained_source_post
                        ON ll_retained_source_post.ID =
                            CAST(ll_retained_source_meta.meta_value AS UNSIGNED)
                    WHERE ll_retained_source_meta.post_id = {$wpdb->posts}.ID
                      AND ll_retained_source_meta.meta_key = %s
                      AND ll_retained_source_meta.meta_value REGEXP '^[0-9]+$'
                      AND CAST(
                          ll_retained_source_meta.meta_value AS UNSIGNED
                      ) > 0
                      AND ll_retained_source_post.post_type = %s
                      AND ll_retained_source_post.post_status = %s
                )
            )
        )",
        LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
        '1',
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
        'post',
        'publish'
    );
}
add_filter(
    'posts_where',
    'll_tools_content_lesson_index_retained_source_where',
    10,
    2
);

/**
 * Read one deterministic, hard-bounded page of published content lessons.
 *
 * @return array{
 *   posts:WP_Post[],
 *   wordset_id:int,
 *   category_ids:int[],
 *   page:int,
 *   per_page:int,
 *   has_more:bool
 * }|WP_Error
 */
function ll_tools_get_content_lesson_index_page(
    $wordset,
    array $category_ids = [],
    int $page = 1,
    int $per_page = 50,
    bool $include_retained_sources = false
) {
    global $wpdb;

    $wordset_id = ll_tools_content_lesson_index_resolve_wordset_id($wordset);
    if ($wordset_id <= 0) {
        return new WP_Error(
            'content_lesson_index_wordset_missing',
            __('Select a valid word set for the lesson index.', 'll-tools-text-domain')
        );
    }

    $visibility_complete = true;
    if (function_exists('ll_tools_user_can_view_wordset')
        && !ll_tools_user_can_view_wordset($wordset_id, 0, $visibility_complete)
    ) {
        return new WP_Error(
            $visibility_complete
                ? 'content_lesson_index_wordset_forbidden'
                : 'content_lesson_index_wordset_incomplete',
            __('This lesson index is not available.', 'll-tools-text-domain')
        );
    }

    $category_ids = ll_tools_content_lesson_index_category_ids($category_ids);
    $page = max(1, min(LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX, $page));
    $per_page = max(1, min(LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX, $per_page));
    $offset = ($page - 1) * $per_page;

    $meta_query = [
        'relation' => 'AND',
        [
            'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            'value' => (string) $wordset_id,
        ],
    ];
    if (!$include_retained_sources) {
        $meta_query[] =
            ll_tools_legacy_lesson_retained_source_catalog_exclusion();
    }
    $meta_query[] = [
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
    ];
    if (!empty($category_ids)) {
        $meta_query[] = [
            'key' => LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META,
            'value' => array_map('strval', $category_ids),
            'compare' => 'IN',
        ];
    }

    // Treat this query as its own completeness boundary instead of inheriting
    // an unrelated earlier database error from the request.
    $wpdb->last_error = '';
    $query = new WP_Query([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $per_page + 1,
        'offset' => $offset,
        'orderby' => [
            'menu_order' => 'ASC',
            'title' => 'ASC',
            'ID' => 'ASC',
        ],
        'order' => 'ASC',
        'ignore_sticky_posts' => true,
        'no_found_rows' => true,
        'cache_results' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'suppress_filters' => false,
        'meta_query' => $meta_query,
        'll_tools_content_lesson_index_query' => true,
        'll_tools_content_lesson_index_include_retained_sources' =>
            $include_retained_sources,
    ]);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'content_lesson_index_query_incomplete',
            __('The lesson index could not be read completely.', 'll-tools-text-domain')
        );
    }

    if ($include_retained_sources && function_exists('_prime_post_caches')) {
        $source_ids = [];
        foreach ((array) $query->posts as $post) {
            if (!($post instanceof WP_Post)
                || !ll_tools_legacy_lesson_has_retained_source_marker(
                    (int) $post->ID
                )
            ) {
                continue;
            }
            $source_values = get_post_meta(
                (int) $post->ID,
                LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
                false
            );
            if (count($source_values) === 1
                && is_scalar($source_values[0])
                && absint($source_values[0]) > 0
            ) {
                $source_ids[absint($source_values[0])] =
                    absint($source_values[0]);
            }
        }
        if ($source_ids !== []) {
            _prime_post_caches(array_values($source_ids), false, false);
        }
    }

    $posts = [];
    foreach ((array) $query->posts as $post) {
        if (!($post instanceof WP_Post)) {
            return new WP_Error(
                'content_lesson_index_query_incomplete',
                __('The lesson index could not be read completely.', 'll-tools-text-domain')
            );
        }
        if (ll_tools_legacy_lesson_has_retained_source_marker((int) $post->ID)
            && (!$include_retained_sources
                || !ll_tools_legacy_lesson_is_retained_source_target(
                    (int) $post->ID
                )
                || ll_tools_get_legacy_lesson_retained_source_url(
                    (int) $post->ID
                ) === '')
        ) {
            return new WP_Error(
                'content_lesson_index_query_incomplete',
                __('The lesson index could not be read completely.', 'll-tools-text-domain')
            );
        }
        $posts[] = $post;
    }
    $has_more = count($posts) > $per_page
        && $page < LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX;
    $posts = array_slice($posts, 0, $per_page);

    return [
        'posts' => $posts,
        'wordset_id' => $wordset_id,
        'category_ids' => $category_ids,
        'page' => $page,
        'per_page' => $per_page,
        'has_more' => $has_more,
    ];
}

function ll_tools_content_lesson_index_enqueue_assets(bool $with_progress = false): void {
    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    ll_enqueue_asset_by_timestamp(
        '/css/content-lesson-index.css',
        'll-tools-content-lesson-index',
        ['ll-tools-style']
    );

    if (!$with_progress) {
        return;
    }

    ll_enqueue_asset_by_timestamp(
        '/css/content-lesson-pages.css',
        'll-tools-content-lesson-pages',
        ['ll-tools-style']
    );
    ll_enqueue_asset_by_timestamp(
        '/js/content-lesson-progress.js',
        'll-tools-content-lesson-progress',
        [],
        true
    );
    static $localized = false;
    if (!$localized) {
        $localized = true;
        wp_localize_script(
            'll-tools-content-lesson-progress',
            'llToolsContentLessonProgress',
            [
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
            ]
        );
    }
}

function ll_tools_content_lesson_index_current_url(string $query_arg): string {
    $url = function_exists('ll_tools_get_current_request_url')
        ? ll_tools_get_current_request_url()
        : home_url('/');
    return (string) remove_query_arg($query_arg, $url);
}

/**
 * Read already-saved prerequisite IDs from the primed page meta cache.
 *
 * This intentionally does not call the full prerequisite getter, whose
 * same-wordset validation query is appropriate for editing and lesson-page
 * details but would become an N+1 query on a 100-card index page.
 *
 * @return int[]
 */
function ll_tools_content_lesson_index_prerequisite_ids(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [];
    }

    $raw = get_post_meta(
        $lesson_id,
        LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
        true
    );
    if (!is_array($raw)) {
        $raw = [];
    }
    $limit = function_exists('ll_tools_content_lesson_prerequisite_edge_limit')
        ? ll_tools_content_lesson_prerequisite_edge_limit($lesson_id)
        : 200;
    $limit = max(1, min(500, (int) $limit));
    $ids = [];
    foreach (array_slice($raw, 0, $limit) as $raw_id) {
        $prerequisite_id = absint($raw_id);
        if ($prerequisite_id > 0 && $prerequisite_id !== $lesson_id) {
            $ids[$prerequisite_id] = $prerequisite_id;
        }
    }
    return array_values($ids);
}

/**
 * Build only the fields used by an index card.
 *
 * The general content-lesson card helper also resolves validated prerequisite
 * IDs and other mixed-grid data. Avoid that broader hydration here because the
 * index already has a separate bounded, meta-cache-only prerequisite count.
 *
 * @return array{title:string,url:string,excerpt:string}
 */
function ll_tools_content_lesson_index_card_data(WP_Post $post): array {
    $lesson_id = (int) $post->ID;
    $fallback_title = (string) get_the_title($lesson_id);
    $fallback_excerpt = function_exists('ll_tools_get_content_lesson_excerpt')
        ? ll_tools_get_content_lesson_excerpt($post)
        : trim((string) $post->post_excerpt);

    return [
        'title' => function_exists('ll_tools_get_lesson_display_title')
            ? ll_tools_get_lesson_display_title(
                $post,
                ['fallback' => $fallback_title]
            )
            : $fallback_title,
        'url' => (string) get_permalink($lesson_id),
        'excerpt' => function_exists('ll_tools_get_lesson_display_excerpt')
            ? ll_tools_get_lesson_display_excerpt($post, $fallback_excerpt)
            : $fallback_excerpt,
    ];
}

function ll_tools_render_content_lesson_index(
    $atts = [],
    bool $include_retained_sources = false
): string {
    $atts = shortcode_atts(
        [
            'wordset' => '',
            'categories' => '',
            'per_page' => 50,
            'list_id' => '',
            'show_excerpt' => '1',
        ],
        is_array($atts) ? $atts : [],
        'll_content_lesson_index'
    );

    $wordset_id = ll_tools_content_lesson_index_resolve_wordset_id($atts['wordset']);
    $category_ids = ll_tools_content_lesson_index_category_ids($atts['categories']);
    $list_key = ll_tools_content_lesson_index_list_key(
        $wordset_id,
        $category_ids,
        (string) $atts['list_id']
    );
    $query_arg = ll_tools_content_lesson_index_page_query_arg($list_key);
    $page = ll_tools_content_lesson_index_requested_page($query_arg);
    $per_page_default = max(1, min(
        LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX,
        (int) apply_filters('ll_tools_content_lesson_index_per_page', 50, $wordset_id)
    ));
    $per_page = is_numeric($atts['per_page'])
        ? (int) $atts['per_page']
        : $per_page_default;
    $per_page = max(1, min(LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX, $per_page));
    $show_excerpt = !in_array(
        strtolower(trim((string) $atts['show_excerpt'])),
        ['0', 'false', 'no', 'off'],
        true
    );

    ll_tools_content_lesson_index_enqueue_assets(false);
    $result = ll_tools_get_content_lesson_index_page(
        $wordset_id,
        $category_ids,
        $page,
        $per_page,
        $include_retained_sources
    );
    if (is_wp_error($result)) {
        if ($result->get_error_code() === 'content_lesson_index_wordset_forbidden') {
            return '';
        }
        return '<p class="ll-content-lesson-index__notice" role="status">'
            . esc_html($result->get_error_message())
            . '</p>';
    }

    $posts = (array) $result['posts'];
    if (empty($posts)) {
        if ($page > 1) {
            $base_url = ll_tools_content_lesson_index_current_url($query_arg);
            $previous_url = $page === 2
                ? $base_url
                : add_query_arg($query_arg, $page - 1, $base_url);
            return '<section class="ll-content-lesson-index" data-wordset-id="'
                . esc_attr((string) $wordset_id)
                . '" data-page="' . esc_attr((string) $page) . '">'
                . '<p class="ll-content-lesson-index__notice" role="status">'
                . esc_html__('No lessons were found on this page.', 'll-tools-text-domain')
                . '</p><nav class="ll-content-lesson-index__pagination" aria-label="'
                . esc_attr__('Lesson pages', 'll-tools-text-domain') . '">'
                . '<a class="ll-content-lesson-index__page-link" href="'
                . esc_url($previous_url) . '">'
                . esc_html__('Previous', 'll-tools-text-domain')
                . '</a><span class="ll-content-lesson-index__page-number">'
                . sprintf(
                    /* translators: %d: current lesson-index page */
                    esc_html__('Page %d', 'll-tools-text-domain'),
                    $page
                )
                . '</span><span></span></nav></section>';
        }
        return '<p class="ll-content-lesson-index__notice" role="status">'
            . esc_html__('No lessons were found.', 'll-tools-text-domain')
            . '</p>';
    }

    $user_id = get_current_user_id();
    $completed_lookup = $user_id > 0 && function_exists('ll_tools_get_completed_content_lesson_ids')
        ? array_fill_keys(ll_tools_get_completed_content_lesson_ids($user_id), true)
        : [];
    $groups = [];
    foreach ($posts as $post) {
        $level = max(0, (int) $post->menu_order);
        if (!isset($groups[$level])) {
            $groups[$level] = [];
        }
        $groups[$level][] = $post;
    }

    static $render_instances = [];
    $render_instances[$list_key] = isset($render_instances[$list_key])
        ? ((int) $render_instances[$list_key]) + 1
        : 1;
    $heading_prefix = 'll-content-lesson-index-' . $list_key . '-'
        . $render_instances[$list_key] . '-' . $page;

    $html = '<section class="ll-content-lesson-index" data-wordset-id="'
        . esc_attr((string) $wordset_id)
        . '" data-page="' . esc_attr((string) $page) . '">';
    foreach ($groups as $level => $level_posts) {
        $heading_id = $heading_prefix . '-' . (int) $level;
        $level_label = $level > 0
            ? sprintf(
                /* translators: %d: lesson level number */
                __('Level %d', 'll-tools-text-domain'),
                $level
            )
            : __('Lessons', 'll-tools-text-domain');
        $html .= '<section class="ll-content-lesson-index__level" aria-labelledby="'
            . esc_attr($heading_id) . '">';
        $html .= '<h2 id="' . esc_attr($heading_id)
            . '" class="ll-content-lesson-index__level-title">'
            . esc_html($level_label) . '</h2>';
        $html .= '<ul class="ll-content-lesson-index__grid">';

        foreach ($level_posts as $post) {
            $lesson_id = (int) $post->ID;
            $card = ll_tools_content_lesson_index_card_data($post);
            $is_completed = !empty($completed_lookup[$lesson_id]);
            $prerequisite_ids = ll_tools_content_lesson_index_prerequisite_ids(
                $lesson_id
            );
            $prerequisite_count = count($prerequisite_ids);
            $completed_prerequisite_count = 0;
            foreach ($prerequisite_ids as $prerequisite_id) {
                if (!empty($completed_lookup[$prerequisite_id])) {
                    $completed_prerequisite_count++;
                }
            }
            $remaining_prerequisite_count = max(
                0,
                $prerequisite_count - $completed_prerequisite_count
            );
            $item_class = 'll-content-lesson-index__item';
            $lesson_state = 'open';
            $status = __('Open lesson', 'll-tools-text-domain');
            $status_class = '';
            $icon = '&#8594;';
            if ($is_completed) {
                $item_class .= ' is-completed';
                $lesson_state = 'completed';
                $status = __('Completed', 'll-tools-text-domain');
                $status_class = ' ll-content-lesson-index__meta--completed';
                $icon = '&#10003;';
            } elseif ($user_id > 0 && $remaining_prerequisite_count === 0) {
                $item_class .= ' is-ready';
                $lesson_state = 'ready';
                $status = __('Ready', 'll-tools-text-domain');
                $status_class = ' ll-content-lesson-index__meta--ready';
                $icon = '&#8594;';
            } elseif ($user_id > 0) {
                $item_class .= ' has-unmet-prerequisites';
                $lesson_state = 'prerequisites-incomplete';
                $prerequisite_label = sprintf(
                    /* translators: %s: number of prerequisite lessons */
                    _n(
                        '%s prerequisite',
                        '%s prerequisites',
                        $prerequisite_count,
                        'll-tools-text-domain'
                    ),
                    number_format_i18n($prerequisite_count)
                );
                $status = number_format_i18n($completed_prerequisite_count)
                    . ' / ' . $prerequisite_label;
                $status_class = ' ll-content-lesson-index__meta--incomplete';
                $icon = '!';
            }

            $html .= '<li class="' . esc_attr($item_class)
                . '" data-lesson-id="' . esc_attr((string) $lesson_id)
                . '" data-lesson-state="' . esc_attr($lesson_state) . '">';
            $html .= '<a class="ll-content-lesson-index__link" href="'
                . esc_url((string) ($card['url'] ?? '')) . '">';
            $html .= '<span class="ll-content-lesson-index__status-icon" aria-hidden="true">'
                . $icon . '</span>';
            $html .= '<span class="ll-content-lesson-index__body">';
            $html .= '<span class="ll-content-lesson-index__title">'
                . esc_html((string) ($card['title'] ?? '')) . '</span>';
            if ($show_excerpt && trim((string) ($card['excerpt'] ?? '')) !== '') {
                $html .= '<span class="ll-content-lesson-index__excerpt">'
                    . esc_html((string) $card['excerpt']) . '</span>';
            }
            if ($user_id > 0) {
                $html .= '<span class="ll-content-lesson-index__meta'
                    . esc_attr($status_class) . '">'
                    . esc_html($status);
                if ($lesson_state === 'prerequisites-incomplete') {
                    $html .= '<span class="screen-reader-text"> &mdash; '
                        . esc_html__('Not completed', 'll-tools-text-domain')
                        . '</span>';
                }
                $html .= '</span>';
            } elseif ($prerequisite_count > 0) {
                $prerequisite_label = sprintf(
                    /* translators: %s: number of prerequisite lessons */
                    _n(
                        '%s prerequisite',
                        '%s prerequisites',
                        $prerequisite_count,
                        'll-tools-text-domain'
                    ),
                    number_format_i18n($prerequisite_count)
                );
                $html .= '<span class="ll-content-lesson-index__meta">'
                    . esc_html($prerequisite_label) . '</span>';
            }
            if ($user_id <= 0) {
                $html .= '<span class="screen-reader-text"> &mdash; '
                    . esc_html($status) . '</span>';
            }
            $html .= '</span></a></li>';
        }
        $html .= '</ul></section>';
    }

    $has_previous = $page > 1;
    $has_next = !empty($result['has_more']);
    if ($has_previous || $has_next) {
        $base_url = ll_tools_content_lesson_index_current_url($query_arg);
        $html .= '<nav class="ll-content-lesson-index__pagination" aria-label="'
            . esc_attr__('Lesson pages', 'll-tools-text-domain') . '">';
        if ($has_previous) {
            $previous_url = $page === 2
                ? $base_url
                : add_query_arg($query_arg, $page - 1, $base_url);
            $html .= '<a class="ll-content-lesson-index__page-link" href="'
                . esc_url($previous_url) . '">'
                . esc_html__('Previous', 'll-tools-text-domain') . '</a>';
        } else {
            $html .= '<span></span>';
        }
        $html .= '<span class="ll-content-lesson-index__page-number">'
            . sprintf(
                /* translators: %d: current lesson-index page */
                esc_html__('Page %d', 'll-tools-text-domain'),
                $page
            )
            . '</span>';
        if ($has_next) {
            $html .= '<a class="ll-content-lesson-index__page-link" href="'
                . esc_url(add_query_arg($query_arg, $page + 1, $base_url))
                . '">' . esc_html__('Next', 'll-tools-text-domain') . '</a>';
        }
        $html .= '</nav>';
    }
    $html .= '</section>';

    return $html;
}

function ll_tools_render_content_lesson_index_shortcode(
    $atts = [],
    $content = null,
    string $tag = ''
): string {
    return ll_tools_render_content_lesson_index($atts, false);
}

function ll_tools_legacy_lesson_compat_target_id(int $source_id = 0): int {
    global $wpdb;

    if ($source_id <= 0) {
        $source_id = (int) get_the_ID();
    }
    if ($source_id <= 0) {
        return 0;
    }
    if (get_post_type($source_id) === 'll_content_lesson') {
        return get_post_status($source_id) === 'publish' ? $source_id : 0;
    }
    if (get_post_type($source_id) !== 'post') {
        return 0;
    }

    $wordset_id = ll_tools_get_legacy_lesson_default_wordset_id();
    $meta_query = [
        [
            'key' => LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
            'value' => (string) $source_id,
        ],
    ];
    if ($wordset_id > 0) {
        $meta_query = [
            'relation' => 'AND',
            $meta_query[0],
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
        ];
    }
    $wpdb->last_error = '';
    $target_ids = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => 2,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
        'suppress_filters' => false,
        'meta_query' => $meta_query,
    ]);
    if ($wpdb->last_error !== '') {
        return 0;
    }
    if (count($target_ids) !== 1) {
        return 0;
    }

    $target_id = (int) $target_ids[0];
    $target_wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id($target_id)
        : 0;
    if ($target_wordset_id <= 0
        || (function_exists('ll_tools_user_can_view_wordset')
            && !ll_tools_user_can_view_wordset($target_wordset_id))
    ) {
        return 0;
    }

    return $target_id;
}

function ll_tools_legacy_custom_header_shortcode(): string {
    $target_id = ll_tools_legacy_lesson_compat_target_id();
    if ($target_id <= 0) {
        return '';
    }

    ll_tools_content_lesson_index_enqueue_assets(true);
    $html = '<div class="ll-legacy-lesson-header">';
    if (function_exists('ll_tools_render_content_lesson_completion_control')) {
        $control = ll_tools_render_content_lesson_completion_control($target_id);
        if ($control !== '') {
            $html .= '<div class="ll-content-lesson-progress">' . $control . '</div>';
        }
    }
    if (function_exists('ll_tools_render_content_lesson_prerequisites')) {
        $html .= ll_tools_render_content_lesson_prerequisites($target_id);
    }
    $html .= '</div>';
    return $html;
}

function ll_tools_legacy_custom_footer_shortcode(): string {
    $target_id = ll_tools_legacy_lesson_compat_target_id();
    if ($target_id <= 0 || !function_exists('ll_tools_render_content_lesson_dependents')) {
        return '';
    }

    ll_tools_content_lesson_index_enqueue_assets(false);
    return '<div class="ll-legacy-lesson-footer">'
        . ll_tools_render_content_lesson_dependents($target_id)
        . '</div>';
}

function ll_tools_legacy_regex_linker_shortcode($atts = [], $content = null): string {
    $post_id = (int) get_the_ID();
    $processed = $post_id > 0
        ? trim((string) get_post_meta($post_id, '_processed_text_with_links', true))
        : '';
    if ($processed !== '') {
        return wp_kses_post(wpautop($processed));
    }

    $content = is_string($content) ? $content : '';
    return wp_kses_post(wpautop(do_shortcode($content)));
}

function ll_tools_legacy_signup_link_shortcode(): string {
    ll_tools_content_lesson_index_enqueue_assets(false);
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $display_name = $user instanceof WP_User
            ? sanitize_text_field((string) $user->display_name)
            : '';
        return '<p class="ll-legacy-lesson-signup">'
            . sprintf(
                /* translators: %s: signed-in user's display name */
                esc_html__('Signed in as %s.', 'll-tools-text-domain'),
                esc_html($display_name)
            )
            . '</p>';
    }

    $current_url = function_exists('ll_tools_get_current_request_url')
        ? ll_tools_get_current_request_url()
        : home_url('/');
    $login_url = function_exists('ll_tools_get_frontend_auth_url')
        ? ll_tools_get_frontend_auth_url($current_url, 'login')
        : wp_login_url($current_url);
    $registration_available = function_exists('ll_tools_is_learner_self_registration_available')
        ? ll_tools_is_learner_self_registration_available()
        : (bool) get_option('users_can_register', 0);

    if ($registration_available) {
        $register_url = function_exists('ll_tools_get_frontend_auth_url')
            ? ll_tools_get_frontend_auth_url($current_url, 'register')
            : wp_registration_url();
        $message = sprintf(
            /* translators: 1 and 2: login-link tags; 3 and 4: registration-link tags */
            __('%1$sLog in%2$s or %3$sregister%4$s to save completed lessons.', 'll-tools-text-domain'),
            '<a href="' . esc_url($login_url) . '">',
            '</a>',
            '<a href="' . esc_url($register_url) . '">',
            '</a>'
        );
    } else {
        $message = sprintf(
            /* translators: 1 and 2: login-link tags */
            __('%1$sLog in%2$s to save completed lessons.', 'll-tools-text-domain'),
            '<a href="' . esc_url($login_url) . '">',
            '</a>'
        );
    }

    return '<p class="ll-legacy-lesson-signup">' . wp_kses_post($message) . '</p>';
}

function ll_tools_legacy_display_prereq_tree_shortcode($atts = []): string {
    $atts = shortcode_atts(
        [
            'categories' => '4,10',
            'per_page' => 50,
        ],
        is_array($atts) ? $atts : [],
        'display_prereq_tree'
    );
    $wordset_id = ll_tools_get_legacy_lesson_default_wordset_id();
    if ($wordset_id <= 0) {
        return '';
    }

    $categories = ll_tools_content_lesson_index_category_ids($atts['categories']);
    $html = !is_user_logged_in() ? ll_tools_legacy_signup_link_shortcode() : '';
    $html .= ll_tools_render_content_lesson_index(
        [
            'wordset' => (string) $wordset_id,
            'categories' => implode(',', $categories),
            'per_page' => $atts['per_page'],
            'list_id' => 'legacy-' . substr(md5(implode(',', $categories)), 0, 12),
            'show_excerpt' => '1',
        ],
        true
    );
    return $html;
}

function ll_tools_register_content_lesson_index_shortcodes(): void {
    add_shortcode('ll_content_lesson_index', 'll_tools_render_content_lesson_index_shortcode');

    if (!shortcode_exists('display_prereq_tree')) {
        add_shortcode('display_prereq_tree', 'll_tools_legacy_display_prereq_tree_shortcode');
    }
    if (!shortcode_exists('custom_header')) {
        add_shortcode('custom_header', 'll_tools_legacy_custom_header_shortcode');
    }
    if (!shortcode_exists('custom_footer')) {
        add_shortcode('custom_footer', 'll_tools_legacy_custom_footer_shortcode');
    }
    if (!shortcode_exists('regex_linker')) {
        add_shortcode('regex_linker', 'll_tools_legacy_regex_linker_shortcode');
    }
    if (!shortcode_exists('signup_link')) {
        add_shortcode('signup_link', 'll_tools_legacy_signup_link_shortcode');
    }
}
add_action('init', 'll_tools_register_content_lesson_index_shortcodes', 20);

function ll_tools_content_lesson_index_maybe_enqueue_assets(): void {
    if (is_admin() || !is_singular()) {
        return;
    }
    $post = get_post();
    if (!($post instanceof WP_Post)) {
        return;
    }
    $content = (string) $post->post_content;
    $has_index = has_shortcode($content, 'll_content_lesson_index')
        || has_shortcode($content, 'display_prereq_tree')
        || has_shortcode($content, 'signup_link');
    $has_progress = has_shortcode($content, 'custom_header');
    $has_footer = has_shortcode($content, 'custom_footer');
    if (!$has_index && !$has_progress && !$has_footer) {
        return;
    }

    ll_tools_content_lesson_index_enqueue_assets($has_progress);
}
add_action('wp_enqueue_scripts', 'll_tools_content_lesson_index_maybe_enqueue_assets', 110);
