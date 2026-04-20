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

function ll_tools_get_wordset_button_terms(bool $hide_empty = false): array {
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

    $visible_lookup = array_fill_keys($visible_term_ids, true);
    $filtered_terms = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $term_id = (int) $term->term_id;
        if ($term_id <= 0 || !isset($visible_lookup[$term_id])) {
            continue;
        }

        $filtered_terms[] = $term;
    }

    return $filtered_terms;
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

    $terms = ll_tools_get_wordset_button_terms(
        ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0')
    );
    if (empty($terms)) {
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
            <?php foreach ($terms as $term) : ?>
                <?php
                $url = function_exists('ll_tools_get_wordset_page_view_url')
                    ? (string) ll_tools_get_wordset_page_view_url($term)
                    : '';
                if ($url === '') {
                    continue;
                }
                ?>
                <li class="ll-wordset-buttons-shortcode__item">
                    <a class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-buttons-shortcode__button" href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($term->name); ?>
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

    return $html;
}
add_shortcode('wordset_buttons', 'll_tools_wordset_buttons_shortcode');
add_shortcode('ll_wordset_buttons', 'll_tools_wordset_buttons_shortcode');
