<?php
// /includes/pages/content-lesson-pages.php
if (!defined('WPINC')) { die; }

function ll_tools_content_lesson_media_label(string $media_type): string {
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

    return [
        'id' => (int) $lesson->ID,
        'title' => (string) get_the_title($lesson),
        'url' => (string) get_permalink($lesson),
        'excerpt' => ll_tools_get_content_lesson_excerpt($lesson),
        'media_type' => $media_type,
        'media_label' => ll_tools_content_lesson_media_label($media_type),
        'wordset_id' => $wordset_id,
        'category_ids' => $category_ids,
        'category_count' => count($category_ids),
    ];
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
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
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

    ob_start();
    ?>
    <section class="ll-content-lessons-section ll-content-lessons-section--<?php echo esc_attr($context); ?>">
        <div class="ll-content-lessons-section__head">
            <h2 class="ll-content-lessons-section__title"><?php echo esc_html($title); ?></h2>
            <?php if ($description !== '') : ?>
                <p class="ll-content-lessons-section__description"><?php echo esc_html($description); ?></p>
            <?php endif; ?>
        </div>
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

    $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
        ? ll_tools_get_content_lesson_wordset_id($lesson_id)
        : 0;
    if ($wordset_id <= 0) {
        return;
    }

    if (!function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset($wordset_id)) {
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

    if (!$is_content_lesson && !$is_vocab_lesson && !$is_wordset_page) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/content-lesson-pages.css', 'll-tools-content-lesson-pages', ['ll-tools-style']);

    if (!$is_content_lesson) {
        return;
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
