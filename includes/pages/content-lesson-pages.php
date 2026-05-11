<?php
// /includes/pages/content-lesson-pages.php
if (!defined('WPINC')) { die; }

function ll_tools_content_lesson_media_label(string $media_type, string $lesson_kind = 'standard'): string {
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

    return [
        'id' => (int) $lesson->ID,
        'title' => (string) get_the_title($lesson),
        'url' => (string) get_permalink($lesson),
        'excerpt' => ll_tools_get_content_lesson_excerpt($lesson),
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
    if ($collection === '') {
        return ['url' => '', 'label' => $label];
    }

    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);
    $collection_double = 'collection="' . $collection . '"';
    $collection_single = "collection='" . $collection . "'";
    foreach ($pages as $page) {
        if (!($page instanceof WP_Post)) {
            continue;
        }
        $content = (string) $page->post_content;
        if ($content === '' || (!has_shortcode($content, 'll_corpus_text_grid') && !has_shortcode($content, 'll_text_document_grid'))) {
            continue;
        }
        if (strpos($content, $collection_double) === false && strpos($content, $collection_single) === false) {
            continue;
        }
        $url = get_permalink($page);
        return ['url' => is_string($url) ? $url : '', 'label' => get_the_title($page) ?: $label];
    }

    return ['url' => '', 'label' => $label];
}

function ll_tools_get_content_lessons_for_wordset(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id)) {
        return [];
    }

    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => -1,
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

    $lessons = [];
    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $lessons[] = ll_tools_get_content_lesson_card_data($post);
    }

    return $lessons;
}

function ll_tools_get_content_lessons_for_vocab_lesson(int $wordset_id, int $category_id): array {
    $lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
    if ($category_id <= 0 || empty($lessons)) {
        return [];
    }

    $matches = [];
    foreach ($lessons as $lesson) {
        $category_ids = isset($lesson['category_ids']) && is_array($lesson['category_ids'])
            ? array_map('intval', $lesson['category_ids'])
            : [];
        if (in_array($category_id, $category_ids, true)) {
            $matches[] = $lesson;
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

function ll_tools_get_corpus_text_grid_lessons(array $args = []): array {
    $limit = isset($args['limit']) ? (int) $args['limit'] : -1;
    $limit = $limit > 0 ? $limit : -1;
    $collection = isset($args['collection']) ? sanitize_title((string) $args['collection']) : '';
    $source_author = isset($args['source_author']) ? sanitize_text_field((string) $args['source_author']) : '';
    $ids = [];
    if (!empty($args['ids'])) {
        $id_parts = preg_split('/[\s,]+/', (string) $args['ids']);
        $ids = is_array($id_parts)
            ? array_values(array_filter(array_map('absint', $id_parts)))
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
    if (!in_array($orderby, ['menu_order title', 'title', 'date', 'modified', 'post__in'], true)) {
        $orderby = 'menu_order title';
    }

    $query_args = [
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'orderby' => $orderby,
        'order' => (isset($args['order']) && strtoupper((string) $args['order']) === 'DESC') ? 'DESC' : 'ASC',
        'no_found_rows' => true,
        'meta_query' => $meta_query,
    ];
    if (!empty($ids)) {
        $query_args['post__in'] = $ids;
        $query_args['orderby'] = 'post__in';
    }

    $posts = get_posts($query_args);
    $lessons = [];
    foreach ((array) $posts as $post) {
        if ($post instanceof WP_Post) {
            $lessons[] = ll_tools_get_content_lesson_card_data($post);
        }
    }

    return $lessons;
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
        . '@media(max-width:820px){.ll-corpus-text-grid .ll-content-lessons-grid{grid-template-columns:1fr;}}'
        . '</style>';
}

function ll_tools_corpus_text_grid_shortcode($atts = []): string {
    $atts = shortcode_atts([
        'collection' => '',
        'source_author' => '',
        'ids' => '',
        'limit' => '-1',
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'title' => __('Texts', 'll-tools-text-domain'),
        'description' => '',
        'open_label' => __('Open text', 'll-tools-text-domain'),
    ], is_array($atts) ? $atts : [], 'll_corpus_text_grid');

    if (function_exists('ll_enqueue_asset_by_timestamp')) {
        ll_enqueue_asset_by_timestamp('/css/content-lesson-pages.css', 'll-tools-content-lesson-pages', ['ll-tools-style']);
    }

    $lessons = ll_tools_get_corpus_text_grid_lessons($atts);
    if (empty($lessons)) {
        return '';
    }

    return ll_tools_corpus_text_grid_inline_styles()
        . '<div class="ll-corpus-text-grid">'
        . ll_tools_render_content_lesson_cards($lessons, [
            'title' => (string) $atts['title'],
            'description' => (string) $atts['description'],
            'context' => 'corpus-text-grid',
            'open_label' => (string) $atts['open_label'],
        ])
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
