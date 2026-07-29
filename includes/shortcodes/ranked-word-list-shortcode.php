<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_RANKED_WORD_META_KEY')) {
    define('LL_TOOLS_RANKED_WORD_META_KEY', '_ll_tools_word_rank');
}
if (!defined('LL_TOOLS_RANKED_WORD_LIST_DEFAULT_PER_PAGE')) {
    define('LL_TOOLS_RANKED_WORD_LIST_DEFAULT_PER_PAGE', 50);
}
if (!defined('LL_TOOLS_RANKED_WORD_LIST_MAX_PER_PAGE')) {
    define('LL_TOOLS_RANKED_WORD_LIST_MAX_PER_PAGE', 100);
}
if (!defined('LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE')) {
    define('LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE', 100);
}
if (!defined('LL_TOOLS_RANKED_WORD_IMPORT_MAX_ROWS')) {
    define('LL_TOOLS_RANKED_WORD_IMPORT_MAX_ROWS', 200);
}

/**
 * Resolve the exact word-category used by a ranked list.
 *
 * @param mixed $category A word-category term ID or slug.
 * @return WP_Term|null
 */
function ll_tools_ranked_word_list_resolve_category($category): ?WP_Term {
    if ($category instanceof WP_Term) {
        return $category->taxonomy === 'word-category' ? $category : null;
    }

    $category = trim((string) $category);
    if ($category === '') {
        return null;
    }

    if (ctype_digit($category)) {
        $term = get_term((int) $category, 'word-category');
    } else {
        $term = get_term_by('slug', sanitize_title($category), 'word-category');
    }

    return ($term instanceof WP_Term && !is_wp_error($term)) ? $term : null;
}

/**
 * Resolve the exact wordset used by a ranked list.
 *
 * @param mixed $wordset A wordset term ID or slug.
 * @return WP_Term|null
 */
function ll_tools_ranked_word_list_resolve_wordset($wordset): ?WP_Term {
    if ($wordset instanceof WP_Term) {
        return $wordset->taxonomy === 'wordset' ? $wordset : null;
    }

    $wordset = trim((string) $wordset);
    if ($wordset === '') {
        return null;
    }

    if (ctype_digit($wordset)) {
        $term = get_term((int) $wordset, 'wordset');
    } else {
        $term = get_term_by('slug', sanitize_title($wordset), 'wordset');
    }

    return ($term instanceof WP_Term && !is_wp_error($term)) ? $term : null;
}

function ll_tools_ranked_word_list_normalize_per_page($per_page): int {
    $per_page = absint($per_page);
    if ($per_page <= 0) {
        $per_page = LL_TOOLS_RANKED_WORD_LIST_DEFAULT_PER_PAGE;
    }

    return min(LL_TOOLS_RANKED_WORD_LIST_MAX_PER_PAGE, max(1, $per_page));
}

/**
 * Build a stable, list-scoped page parameter.
 *
 * Different categories, page sizes, or explicit list IDs receive independent
 * query arguments. Repeated identical lists can opt into independent paging by
 * setting a distinct list_id attribute.
 */
function ll_tools_ranked_word_list_page_arg(
    int $category_id,
    int $per_page,
    string $list_id = '',
    int $wordset_id = 0
): string {
    $scope = 'category:' . max(0, $category_id)
        . '|wordset:' . max(0, $wordset_id)
        . '|per-page:' . ll_tools_ranked_word_list_normalize_per_page($per_page)
        . '|list:' . sanitize_key($list_id);

    return 'll_ranked_page_' . substr(md5($scope), 0, 12);
}

function ll_tools_ranked_word_list_current_page(string $page_arg): int {
    if ($page_arg === '' || !isset($_GET[$page_arg]) || is_array($_GET[$page_arg])) {
        return 1;
    }

    $page = absint(wp_unslash($_GET[$page_arg]));

    // Avoid pathological offsets from hostile query strings while retaining
    // ample room for genuinely large, paginated collections.
    return min(LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE, max(1, $page));
}

/**
 * Return the canonical bounded query shape for one ranked page.
 *
 * @return array<string,mixed>
 */
function ll_tools_ranked_word_list_query_args(
    WP_Term $category,
    WP_Term $wordset,
    int $per_page,
    int $page
): array {
    $per_page = ll_tools_ranked_word_list_normalize_per_page($per_page);
    $page = min(LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE, max(1, $page));

    return [
        'post_type'              => 'words',
        'post_status'            => 'publish',
        'posts_per_page'         => $per_page,
        'paged'                  => $page,
        'ignore_sticky_posts'    => true,
        'no_found_rows'          => false,
        'suppress_filters'       => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'tax_query'              => [
            'relation' => 'AND',
            [
                'taxonomy'         => 'word-category',
                'field'            => 'term_id',
                'terms'            => [(int) $category->term_id],
                'include_children' => false,
            ],
            [
                'taxonomy'         => 'wordset',
                'field'            => 'term_id',
                'terms'            => [(int) $wordset->term_id],
                'include_children' => false,
            ],
        ],
        'meta_query'             => [
            'rank_clause' => [
                'key'     => LL_TOOLS_RANKED_WORD_META_KEY,
                'value'   => 0,
                'compare' => '>',
                'type'    => 'UNSIGNED',
            ],
        ],
        'orderby'                => [
            'rank_clause' => 'ASC',
            'ID'          => 'ASC',
        ],
        'll_tools_ranked_word_list_query' => 1,
    ];
}

/**
 * Select one already-batched audio URL per word.
 *
 * @param int[] $word_ids
 * @return array<int,string>|WP_Error
 */
function ll_tools_ranked_word_list_audio_url_map(array $word_ids) {
    global $wpdb;

    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }
    if (count($word_ids) > LL_TOOLS_RANKED_WORD_LIST_MAX_PER_PAGE) {
        return new WP_Error(
            'll_ranked_word_audio_scope_too_large',
            __('The ranked audio page exceeds the configured safe limit.', 'll-tools-text-domain')
        );
    }

    $candidate_limit = max(1, min(20, (int) apply_filters(
        'll_tools_ranked_word_audio_candidates_per_word',
        8
    )));
    $subqueries = [];
    foreach ($word_ids as $word_id) {
        $subqueries[] = $wpdb->prepare(
            "(SELECT audio.ID, audio.post_parent, audio.post_author, audio.post_date
              FROM {$wpdb->posts} audio
              WHERE audio.post_type = 'word_audio'
                AND audio.post_status = 'publish'
                AND audio.post_parent = %d
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} audio_path
                    WHERE audio_path.post_id = audio.ID
                      AND audio_path.meta_key = 'audio_file_path'
                      AND audio_path.meta_value <> ''
                )
              ORDER BY post_date DESC, ID DESC
              LIMIT %d)",
            $word_id,
            $candidate_limit + 1
        );
    }
    $sql = 'SELECT candidates.ID, candidates.post_parent, candidates.post_author, candidates.post_date'
        . ' FROM (' . implode(' UNION ALL ', $subqueries) . ') candidates'
        . ' ORDER BY candidates.post_parent ASC, candidates.post_date DESC, candidates.ID DESC'
        . ' /* ll_tools_ranked_word_list_audio */';
    $audio_rows = $wpdb->get_results($sql);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'll_ranked_word_audio_query_failed',
            __('Ranked word audio could not be loaded completely.', 'll-tools-text-domain')
        );
    }
    if (count((array) $audio_rows) > count($word_ids) * ($candidate_limit + 1)) {
        return new WP_Error(
            'll_ranked_word_audio_query_incomplete',
            __('Ranked word audio exceeded the configured safe limit.', 'll-tools-text-domain')
        );
    }

    $audio_ids = array_values(array_filter(array_map(static function ($row): int {
        return isset($row->ID) ? (int) $row->ID : 0;
    }, (array) $audio_rows)));
    if (empty($audio_ids)) {
        return [];
    }
    $wpdb->last_error = '';
    update_meta_cache('post', $audio_ids);
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'll_ranked_word_audio_meta_failed',
            __('Ranked word audio details could not be loaded completely.', 'll-tools-text-domain')
        );
    }

    $recording_types_by_audio = [];
    $recording_terms = wp_get_object_terms(
        $audio_ids,
        'recording_type',
        ['fields' => 'all_with_object_id']
    );
    if (is_wp_error($recording_terms)) {
        return new WP_Error(
            'll_ranked_word_audio_terms_failed',
            __('Ranked word audio types could not be loaded completely.', 'll-tools-text-domain')
        );
    }
    foreach ((array) $recording_terms as $term) {
        $audio_id = isset($term->object_id) ? (int) $term->object_id : 0;
        $type = sanitize_key((string) ($term->slug ?? ''));
        if ($audio_id > 0 && $type !== '') {
            $recording_types_by_audio[$audio_id][$type] = true;
        }
    }

    $audio_by_word = [];
    $candidate_counts = [];
    foreach ((array) $audio_rows as $audio_row) {
        $audio_id = isset($audio_row->ID) ? (int) $audio_row->ID : 0;
        $word_id = isset($audio_row->post_parent) ? (int) $audio_row->post_parent : 0;
        if ($audio_id <= 0 || $word_id <= 0) {
            continue;
        }
        $candidate_counts[$word_id] = ($candidate_counts[$word_id] ?? 0) + 1;
        $audio_path = trim((string) get_post_meta($audio_id, 'audio_file_path', true));
        $audio_url = function_exists('ll_tools_word_grid_audio_url_from_path')
            ? ll_tools_word_grid_audio_url_from_path($audio_path)
            : ($audio_path !== '' ? site_url($audio_path) : '');
        if ($audio_url === '') {
            continue;
        }
        $types = array_keys($recording_types_by_audio[$audio_id] ?? []);
        if (empty($types)) {
            $types = [''];
        }
        $speaker_id = (int) get_post_meta($audio_id, 'speaker_user_id', true);
        if ($speaker_id <= 0) {
            $speaker_id = isset($audio_row->post_author)
                ? (int) $audio_row->post_author
                : 0;
        }
        foreach ($types as $type) {
            $audio_by_word[$word_id][] = [
                'id' => $audio_id,
                'url' => $audio_url,
                'recording_type' => $type,
                'speaker_user_id' => $speaker_id,
            ];
        }
    }

    $urls = [];

    foreach ($word_ids as $word_id) {
        $audio_files = isset($audio_by_word[$word_id]) && is_array($audio_by_word[$word_id])
            ? $audio_by_word[$word_id]
            : [];
        if (empty($audio_files)) {
            continue;
        }

        $preferred_speaker = function_exists('ll_tools_word_grid_get_preferred_speaker')
            ? (int) ll_tools_word_grid_get_preferred_speaker(
                $audio_files,
                ['isolation', 'introduction', 'question']
            )
            : 0;
        $selected = [];

        if (function_exists('ll_tools_word_grid_select_audio_entry')) {
            foreach (['isolation', 'introduction', 'question', 'in-sentence', 'sentence'] as $recording_type) {
                $selected = (array) ll_tools_word_grid_select_audio_entry(
                    $audio_files,
                    $recording_type,
                    $preferred_speaker
                );
                if (!empty($selected['url'])) {
                    break;
                }
            }
        }

        if (empty($selected['url'])) {
            foreach ($audio_files as $audio_file) {
                if (is_array($audio_file) && !empty($audio_file['url'])) {
                    $selected = $audio_file;
                    break;
                }
            }
        }

        $audio_url = isset($selected['url']) ? esc_url_raw((string) $selected['url']) : '';
        if ($audio_url !== '') {
            $urls[$word_id] = $audio_url;
        }
    }

    foreach ($word_ids as $word_id) {
        if (empty($urls[$word_id])
            && (int) ($candidate_counts[$word_id] ?? 0) > $candidate_limit
        ) {
            return new WP_Error(
                'll_ranked_word_audio_candidates_incomplete',
                __('Ranked word audio exceeded the per-word candidate limit.', 'll-tools-text-domain')
            );
        }
    }

    return $urls;
}

function ll_tools_ranked_word_list_render_audio_control(string $audio_url): string {
    $audio_url = esc_url_raw($audio_url);
    if ($audio_url === '') {
        return '<span class="ll-ranked-word-list__no-audio"><span aria-hidden="true">&mdash;</span>'
            . '<span class="screen-reader-text">'
            . esc_html__('No audio available', 'll-tools-text-domain')
            . '</span></span>';
    }

    $controls = function_exists('ll_word_audio_get_controls_data')
        ? (array) ll_word_audio_get_controls_data()
        : [
            'playLabel'  => __('Play audio', 'll-tools-text-domain'),
            'playIconUrl' => defined('LL_TOOLS_BASE_URL') ? LL_TOOLS_BASE_URL . 'media/play-symbol.svg' : '',
        ];
    $audio_id = function_exists('wp_unique_id')
        ? wp_unique_id('ll_ranked_word_audio_')
        : uniqid('ll_ranked_word_audio_');
    $play_label = (string) ($controls['playLabel'] ?? __('Play audio', 'll-tools-text-domain'));
    $play_icon_url = (string) ($controls['playIconUrl'] ?? '');

    $html = '<span class="ll-word-audio ll-ranked-word-list__audio-control">';
    $html .= '<button type="button" id="' . esc_attr($audio_id . '_button')
        . '" class="ll-word-audio__button" aria-controls="' . esc_attr($audio_id)
        . '" aria-label="' . esc_attr($play_label) . '" data-audio-id="' . esc_attr($audio_id) . '">';
    $html .= '<span class="ll-word-audio__icon" aria-hidden="true">';
    if ($play_icon_url !== '') {
        $html .= '<img id="' . esc_attr($audio_id . '_icon')
            . '" class="ll-word-audio__icon-image" src="' . esc_url($play_icon_url)
            . '" width="10" height="10" alt="" data-no-lazy="1" />';
    }
    $html .= '</span><span id="' . esc_attr($audio_id . '_label')
        . '" class="screen-reader-text ll-word-audio__label">' . esc_html($play_label) . '</span></button>';
    $html .= '<audio id="' . esc_attr($audio_id)
        . '" class="ll-word-audio__audio" preload="none" hidden src="' . esc_url($audio_url) . '"></audio>';
    $html .= '</span>';

    return $html;
}

/**
 * Resolve the current target word and translation text without changing the
 * global query.
 *
 * @return array{word_text:string,translation_text:string}
 */
function ll_tools_ranked_word_list_display_text(int $word_id): array {
    if (function_exists('ll_tools_word_grid_resolve_display_text')) {
        $display = (array) ll_tools_word_grid_resolve_display_text($word_id);
        return [
            'word_text'        => trim((string) ($display['word_text'] ?? '')),
            'translation_text' => trim((string) ($display['translation_text'] ?? '')),
        ];
    }

    $translation = (string) get_post_meta($word_id, 'word_translation', true);
    if ($translation === '') {
        $translation = (string) get_post_meta($word_id, 'word_english_meaning', true);
    }

    return [
        'word_text'        => trim((string) get_the_title($word_id)),
        'translation_text' => trim($translation),
    ];
}

function ll_tools_ranked_word_list_pagination(
    int $total_pages,
    int $current_page,
    string $page_arg,
    string $container_id
): string {
    if ($total_pages <= 1) {
        return '';
    }

    $placeholder = 987654321;
    $base = add_query_arg($page_arg, (string) $placeholder, remove_query_arg($page_arg));
    $base = str_replace((string) $placeholder, '%#%', esc_url($base));
    $links = paginate_links([
        'base'         => $base,
        'format'       => '',
        'current'      => max(1, $current_page),
        'total'        => max(1, $total_pages),
        'mid_size'     => 2,
        'end_size'     => 1,
        'type'         => 'list',
        'add_fragment' => '#' . sanitize_html_class($container_id),
        'prev_text'    => '<span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text">'
            . esc_html__('Previous page', 'll-tools-text-domain') . '</span>',
        'next_text'    => '<span class="screen-reader-text">'
            . esc_html__('Next page', 'll-tools-text-domain') . '</span><span aria-hidden="true">&rsaquo;</span>',
    ]);

    if (!is_string($links) || $links === '') {
        return '';
    }

    return '<nav class="ll-ranked-word-list__pagination" aria-label="'
        . esc_attr__('Ranked word list pages', 'll-tools-text-domain') . '">'
        . wp_kses_post($links) . '</nav>';
}

function ll_tools_ranked_word_list_enqueue_assets(): void {
    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp(
            '/css/ranked-word-list.css',
            'll-tools-ranked-word-list',
            ['ll-tools-style']
        );
    }
    if (function_exists('ll_enqueue_word_audio_js')) {
        ll_enqueue_word_audio_js();
    }
}

/**
 * Detect ordinary post content early enough for the feature stylesheet to be
 * printed in the document head. The render callback repeats the enqueue for
 * page-builder and programmatic shortcode use.
 */
function ll_tools_ranked_word_list_maybe_enqueue_assets(): void {
    if (is_admin()) {
        return;
    }

    $post = get_queried_object();
    if (!($post instanceof WP_Post) || !has_shortcode((string) $post->post_content, 'll_ranked_word_list')) {
        return;
    }

    ll_tools_ranked_word_list_enqueue_assets();
}
add_action('wp_enqueue_scripts', 'll_tools_ranked_word_list_maybe_enqueue_assets', 90);

/**
 * Render a bounded, paginated ranked collection of existing words.
 */
function ll_tools_ranked_word_list_shortcode($atts = []): string {
    global $wpdb;

    $atts = shortcode_atts([
        'category' => '',
        'wordset'  => '',
        'per_page' => LL_TOOLS_RANKED_WORD_LIST_DEFAULT_PER_PAGE,
        'list_id'  => '',
    ], (array) $atts, 'll_ranked_word_list');

    $category = ll_tools_ranked_word_list_resolve_category($atts['category']);
    $wordset = ll_tools_ranked_word_list_resolve_wordset($atts['wordset']);
    if (!$category instanceof WP_Term || !$wordset instanceof WP_Term) {
        return '';
    }
    if (function_exists('ll_tools_user_can_view_category')) {
        $category_complete = true;
        if (!ll_tools_user_can_view_category($category, 0, $category_complete) || !$category_complete) {
            return '';
        }
    }
    if (function_exists('ll_tools_user_can_view_wordset')) {
        $wordset_complete = true;
        if (!ll_tools_user_can_view_wordset($wordset, 0, $wordset_complete) || !$wordset_complete) {
            return '';
        }
    }
    if (function_exists('ll_tools_get_category_wordset_owner_id')) {
        $owner_complete = true;
        $owner_wordset_id = ll_tools_get_category_wordset_owner_id(
            $category,
            $owner_complete
        );
        if (!$owner_complete || ($owner_wordset_id > 0 && $owner_wordset_id !== (int) $wordset->term_id)) {
            return '';
        }
    }

    $per_page = ll_tools_ranked_word_list_normalize_per_page($atts['per_page']);
    $list_id = sanitize_key((string) $atts['list_id']);
    $page_arg = ll_tools_ranked_word_list_page_arg(
        (int) $category->term_id,
        $per_page,
        $list_id,
        (int) $wordset->term_id
    );
    $current_page = ll_tools_ranked_word_list_current_page($page_arg);
    $container_scope = $list_id !== ''
        ? $list_id
        : 'wordset-' . (int) $wordset->term_id
            . '-category-' . (int) $category->term_id . '-' . $per_page;
    $container_prefix = 'll-ranked-word-list-' . sanitize_html_class($container_scope) . '-';
    $container_id = function_exists('wp_unique_id')
        ? wp_unique_id($container_prefix)
        : uniqid($container_prefix);

    ll_tools_ranked_word_list_enqueue_assets();

    $wpdb->last_error = '';
    $query = new WP_Query(
        ll_tools_ranked_word_list_query_args(
            $category,
            $wordset,
            $per_page,
            $current_page
        )
    );
    if ($wpdb->last_error !== '') {
        return '<section id="' . esc_attr($container_id)
            . '" class="ll-ranked-word-list ll-ranked-word-list--error"><p>'
            . esc_html__('The ranked word list could not be loaded completely.', 'll-tools-text-domain')
            . '</p></section>';
    }
    $reported_total_pages = max(0, (int) $query->max_num_pages);
    $pagination_total_pages = min(
        LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE,
        $reported_total_pages
    );
    $pagination_truncated = $reported_total_pages > $pagination_total_pages;
    $word_posts = array_values(array_filter((array) $query->posts, static function ($post): bool {
        return $post instanceof WP_Post && $post->post_type === 'words';
    }));
    $word_ids = array_map(static function (WP_Post $post): int {
        return (int) $post->ID;
    }, $word_posts);
    $audio_urls = ll_tools_ranked_word_list_audio_url_map($word_ids);
    $audio_error = is_wp_error($audio_urls) ? $audio_urls : null;
    if (is_wp_error($audio_urls)) {
        $audio_urls = [];
    }

    $region_label = sprintf(
        /* translators: %s: word category name */
        __('Ranked words in %s', 'll-tools-text-domain'),
        (string) $category->name
    );

    $html = '<section id="' . esc_attr($container_id) . '" class="ll-ranked-word-list" data-category-id="'
        . esc_attr((string) $category->term_id) . '" data-wordset-id="'
        . esc_attr((string) $wordset->term_id) . '" aria-label="' . esc_attr($region_label) . '">';

    if (empty($word_posts)) {
        $html .= '<p class="ll-ranked-word-list__empty">'
            . esc_html__('No ranked words found.', 'll-tools-text-domain') . '</p></section>';
        return $html;
    }

    if ($audio_error instanceof WP_Error) {
        $html .= '<p class="ll-ranked-word-list__notice" role="status">'
            . esc_html($audio_error->get_error_message()) . '</p>';
    }
    if ($pagination_truncated) {
        $html .= '<p class="ll-ranked-word-list__notice" role="status">'
            . esc_html(
                sprintf(
                    /* translators: %d: maximum number of reachable ranked-list pages */
                    __('This ranked list is limited to the first %d pages.', 'll-tools-text-domain'),
                    LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE
                )
            )
            . '</p>';
    }
    $html .= '<div class="ll-ranked-word-list__table-wrap" tabindex="0" role="region" aria-label="'
        . esc_attr($region_label) . '">';
    $html .= '<table class="ll-ranked-word-list__table">';
    $html .= '<caption class="screen-reader-text">' . esc_html($region_label) . '</caption>';
    $html .= '<thead><tr>';
    $html .= '<th class="ll-ranked-word-list__rank-heading" scope="col">'
        . esc_html__('Rank', 'll-tools-text-domain') . '</th>';
    $html .= '<th scope="col">' . esc_html__('Word', 'll-tools-text-domain') . '</th>';
    $html .= '<th scope="col">' . esc_html__('Translation', 'll-tools-text-domain') . '</th>';
    $html .= '<th class="ll-ranked-word-list__audio-heading" scope="col">'
        . esc_html__('Audio', 'll-tools-text-domain') . '</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($word_posts as $word_post) {
        $word_id = (int) $word_post->ID;
        $rank = absint(get_post_meta($word_id, LL_TOOLS_RANKED_WORD_META_KEY, true));
        $display = ll_tools_ranked_word_list_display_text($word_id);
        $word_text = $display['word_text'] !== '' ? $display['word_text'] : (string) $word_post->post_title;
        $translation_text = $display['translation_text'];

        $html .= '<tr data-word-id="' . esc_attr((string) $word_id) . '">';
        $html .= '<td class="ll-ranked-word-list__rank" data-label="'
            . esc_attr__('Rank', 'll-tools-text-domain') . '">' . esc_html((string) $rank) . '</td>';
        $html .= '<td class="ll-ranked-word-list__word" data-label="'
            . esc_attr__('Word', 'll-tools-text-domain') . '">' . esc_html($word_text) . '</td>';
        $html .= '<td class="ll-ranked-word-list__translation" data-label="'
            . esc_attr__('Translation', 'll-tools-text-domain') . '">';
        if ($translation_text !== '') {
            $html .= esc_html($translation_text);
        } else {
            $html .= '<span class="ll-ranked-word-list__missing"><span aria-hidden="true">&mdash;</span>'
                . '<span class="screen-reader-text">'
                . esc_html__('No translation available', 'll-tools-text-domain')
                . '</span></span>';
        }
        $html .= '</td>';
        $html .= '<td class="ll-ranked-word-list__audio" data-label="'
            . esc_attr__('Audio', 'll-tools-text-domain') . '">'
            . ll_tools_ranked_word_list_render_audio_control((string) ($audio_urls[$word_id] ?? ''))
            . '</td></tr>';
    }

    $html .= '</tbody></table></div>';
    $html .= ll_tools_ranked_word_list_pagination(
        $pagination_total_pages,
        $current_page,
        $page_arg,
        $container_id
    );
    $html .= '</section>';

    return $html;
}
add_shortcode('ll_ranked_word_list', 'll_tools_ranked_word_list_shortcode');

/**
 * Resolve one importer row to an existing word without an all-site scan.
 *
 * @param array<string,mixed> $row
 * @return int|WP_Error
 */
function ll_tools_ranked_word_list_resolve_import_word(array $row, ?WP_Term $category = null) {
    global $wpdb;

    $word_id = absint($row['word_id'] ?? ($row['id'] ?? 0));
    if ($word_id > 0) {
        $word = get_post($word_id);
        if (!($word instanceof WP_Post) || $word->post_type !== 'words' || $word->post_status === 'trash') {
            return new WP_Error(
                'll_ranked_word_invalid_id',
                __('The row does not reference an existing word.', 'll-tools-text-domain')
            );
        }
        if ($category instanceof WP_Term && !has_term((int) $category->term_id, 'word-category', $word_id)) {
            return new WP_Error(
                'll_ranked_word_outside_category',
                __('The referenced word is not assigned to the selected category.', 'll-tools-text-domain')
            );
        }

        return $word_id;
    }

    $title = trim(sanitize_text_field((string) ($row['title'] ?? ($row['word'] ?? ''))));
    if ($title === '') {
        return new WP_Error(
            'll_ranked_word_missing_identity',
            __('Each row needs a word_id or title.', 'll-tools-text-domain')
        );
    }

    $query_args = [
        'post_type'              => 'words',
        'post_status'            => ['publish', 'draft', 'pending', 'private', 'future'],
        'title'                  => $title,
        'posts_per_page'         => 2,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'suppress_filters'       => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'll_tools_ranked_word_import_lookup' => 1,
    ];
    if ($category instanceof WP_Term) {
        $query_args['tax_query'] = [
            [
                'taxonomy'         => 'word-category',
                'field'            => 'term_id',
                'terms'            => [(int) $category->term_id],
                'include_children' => false,
            ],
        ];
    }

    $wpdb->last_error = '';
    $matches = array_values(array_map('intval', (new WP_Query($query_args))->posts));
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'll_ranked_word_lookup_failed',
            __('The existing-word lookup could not be read completely.', 'll-tools-text-domain')
        );
    }
    if (empty($matches)) {
        return new WP_Error(
            'll_ranked_word_not_found',
            __('No existing word matched the row title.', 'll-tools-text-domain')
        );
    }
    if (count($matches) > 1) {
        return new WP_Error(
            'll_ranked_word_ambiguous_title',
            __('More than one existing word matched the row title; use word_id.', 'll-tools-text-domain')
        );
    }

    return (int) $matches[0];
}

/**
 * Import one bounded batch of associative CSV-style rows.
 *
 * Rows accept rank plus word_id/id or title/word. This lower-level helper is
 * intentionally generic and does not read files, scan every word, or register
 * a public mutation endpoint. A UI, CLI, or migration calling it must perform
 * its own capability/nonce checks and submit bounded batches.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,mixed>            $args Optional category and dry_run.
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_import_ranked_word_rows(array $rows, array $args = []) {
    $args = wp_parse_args($args, [
        'category' => '',
        'dry_run'  => false,
        'max_rows' => LL_TOOLS_RANKED_WORD_IMPORT_MAX_ROWS,
    ]);
    $max_rows = min(
        LL_TOOLS_RANKED_WORD_IMPORT_MAX_ROWS,
        max(1, absint($args['max_rows']))
    );
    if (count($rows) > $max_rows) {
        return new WP_Error(
            'll_ranked_word_import_too_large',
            sprintf(
                /* translators: %d: maximum rows per import batch */
                __('Rank imports are limited to %d rows per batch.', 'll-tools-text-domain'),
                $max_rows
            )
        );
    }

    $category = null;
    if (
        !($args['category'] instanceof WP_Term)
        && !is_scalar($args['category'])
        && $args['category'] !== null
    ) {
        return new WP_Error(
            'll_ranked_word_import_invalid_category',
            __('The rank import category could not be found.', 'll-tools-text-domain')
        );
    }
    $has_category = $args['category'] instanceof WP_Term
        || trim((string) $args['category']) !== '';
    if ($has_category) {
        $category = ll_tools_ranked_word_list_resolve_category($args['category']);
        if (!$category instanceof WP_Term) {
            return new WP_Error(
                'll_ranked_word_import_invalid_category',
                __('The rank import category could not be found.', 'll-tools-text-domain')
            );
        }
    }

    $dry_run = (bool) $args['dry_run'];
    $result = [
        'total'        => count($rows),
        'processed'    => 0,
        'updated'      => 0,
        'unchanged'    => 0,
        'would_update' => 0,
        'failed'       => 0,
        'items'        => [],
        'errors'       => [],
    ];

    foreach (array_values($rows) as $index => $row) {
        $row_number = $index + 1;
        if (!is_array($row)) {
            $result['failed']++;
            $result['errors'][] = [
                'row'     => $row_number,
                'code'    => 'll_ranked_word_invalid_row',
                'message' => __('The import row must be an associative array.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $raw_rank = trim((string) ($row['rank'] ?? ''));
        if (!preg_match('/^[1-9][0-9]*$/', $raw_rank) || (float) $raw_rank > 2147483647) {
            $result['failed']++;
            $result['errors'][] = [
                'row'     => $row_number,
                'code'    => 'll_ranked_word_invalid_rank',
                'message' => __('Rank must be a positive whole number.', 'll-tools-text-domain'),
            ];
            continue;
        }
        $rank = (int) $raw_rank;

        $resolved = ll_tools_ranked_word_list_resolve_import_word($row, $category);
        if (is_wp_error($resolved)) {
            $result['failed']++;
            $result['errors'][] = [
                'row'     => $row_number,
                'code'    => $resolved->get_error_code(),
                'message' => $resolved->get_error_message(),
            ];
            continue;
        }

        $word_id = (int) $resolved;
        $current_rank = get_post_meta($word_id, LL_TOOLS_RANKED_WORD_META_KEY, true);
        if ($current_rank !== '' && (int) $current_rank === $rank) {
            $result['processed']++;
            $result['unchanged']++;
            $result['items'][] = [
                'row'     => $row_number,
                'word_id' => $word_id,
                'rank'    => $rank,
                'status'  => 'unchanged',
            ];
            continue;
        }

        if ($dry_run) {
            $result['processed']++;
            $result['would_update']++;
            $result['items'][] = [
                'row'     => $row_number,
                'word_id' => $word_id,
                'rank'    => $rank,
                'status'  => 'would_update',
            ];
            continue;
        }

        update_post_meta($word_id, LL_TOOLS_RANKED_WORD_META_KEY, $rank);
        $verified_rank = get_post_meta($word_id, LL_TOOLS_RANKED_WORD_META_KEY, true);
        if ((int) $verified_rank !== $rank) {
            $result['failed']++;
            $result['errors'][] = [
                'row'     => $row_number,
                'code'    => 'll_ranked_word_write_failed',
                'message' => __('The rank could not be verified after saving.', 'll-tools-text-domain'),
            ];
            continue;
        }

        $result['processed']++;
        $result['updated']++;
        $result['items'][] = [
            'row'     => $row_number,
            'word_id' => $word_id,
            'rank'    => $rank,
            'status'  => 'updated',
        ];
        do_action('ll_tools_ranked_word_rank_updated', $word_id, $rank, $current_rank);
    }

    return $result;
}
