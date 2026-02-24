<?php
// /includes/shortcodes/wordset-page-shortcode.php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_page_shortcode_maybe_enqueue_assets(): void {
    if (is_admin()) {
        return;
    }

    if (function_exists('ll_tools_is_wordset_page_context') && ll_tools_is_wordset_page_context()) {
        // Dedicated wordset routes enqueue via includes/pages/wordset-pages.php.
        return;
    }

    if (!is_singular()) {
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

    $has_wordset_shortcode = has_shortcode($content, 'wordset_page') || has_shortcode($content, 'll_wordset_page');
    if (!$has_wordset_shortcode) {
        return;
    }

    // Enqueue before wp_head on shortcode pages to avoid late CSS application / layout shift.
    if (function_exists('ll_tools_wordset_page_enqueue_styles')) {
        ll_tools_wordset_page_enqueue_styles();
    }
    if (function_exists('ll_tools_wordset_page_enqueue_scripts')) {
        ll_tools_wordset_page_enqueue_scripts();
    }
}
add_action('wp_enqueue_scripts', 'll_tools_wordset_page_shortcode_maybe_enqueue_assets');

function ll_tools_wordset_page_shortcode($atts = []): string {
    $atts = shortcode_atts([
        'wordset' => '',
        'slug' => '',
        'id' => '',
        'show_title' => '1',
        'preview_limit' => '2',
        'class' => '',
    ], $atts, 'wordset_page');

    $wordset = trim((string) $atts['wordset']);
    if ($wordset === '') {
        $wordset = trim((string) $atts['slug']);
    }
    if ($wordset === '') {
        $id = (int) $atts['id'];
        if ($id > 0) {
            $wordset = $id;
        }
    }

    if ($wordset === '' && function_exists('ll_tools_get_wordset_page_term')) {
        $context_wordset = ll_tools_get_wordset_page_term();
        if ($context_wordset && !is_wp_error($context_wordset)) {
            $wordset = (int) $context_wordset->term_id;
        }
    }

    if ($wordset === '' && function_exists('ll_tools_get_active_wordset_id')) {
        $active_wordset_id = (int) ll_tools_get_active_wordset_id();
        if ($active_wordset_id > 0) {
            $wordset = $active_wordset_id;
        }
    }

    $show_title_raw = strtolower(trim((string) $atts['show_title']));
    $show_title = !in_array($show_title_raw, ['0', 'false', 'no', 'off'], true);
    $preview_limit = max(1, (int) $atts['preview_limit']);

    $extra_classes = [];
    $class_parts = preg_split('/\s+/', trim((string) $atts['class']));
    if (is_array($class_parts)) {
        foreach ($class_parts as $class_part) {
            $class_part = sanitize_html_class($class_part);
            if ($class_part !== '') {
                $extra_classes[$class_part] = true;
            }
        }
    }

    if (function_exists('ll_tools_wordset_page_enqueue_styles')) {
        ll_tools_wordset_page_enqueue_styles();
    }

    if (!function_exists('ll_tools_render_wordset_page_content')) {
        return '';
    }

    $classes = array_keys($extra_classes);
    array_unshift($classes, 'll-wordset-page--shortcode');

    return ll_tools_render_wordset_page_content($wordset, [
        'show_title' => $show_title,
        'preview_limit' => $preview_limit,
        'extra_classes' => $classes,
        'wrapper_tag' => 'div',
    ]);
}
add_shortcode('wordset_page', 'll_tools_wordset_page_shortcode');
add_shortcode('ll_wordset_page', 'll_tools_wordset_page_shortcode');
