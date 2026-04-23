<?php
// /includes/shortcodes/wordset-page-shortcode.php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_page_content_has_shortcode(string $content): bool {
    if ($content === '') {
        return false;
    }

    return has_shortcode($content, 'wordset_page') || has_shortcode($content, 'll_wordset_page');
}

/**
 * Return the shortcode's explicit wordset reference, if one was supplied.
 *
 * @return int|string|null
 */
function ll_tools_wordset_page_shortcode_get_explicit_wordset_reference(array $atts) {
    $wordset = trim((string) ($atts['wordset'] ?? ''));
    if ($wordset !== '') {
        return $wordset;
    }

    $slug = trim((string) ($atts['slug'] ?? ''));
    if ($slug !== '') {
        return $slug;
    }

    $id = (int) ($atts['id'] ?? 0);
    if ($id > 0) {
        return $id;
    }

    return null;
}

function ll_tools_get_wordset_page_shortcode_target_term_for_post(WP_Post $post) {
    $content = isset($post->post_content) ? (string) $post->post_content : '';
    if (!ll_tools_wordset_page_content_has_shortcode($content)) {
        return null;
    }

    $regex = get_shortcode_regex(['wordset_page', 'll_wordset_page']);
    $found_explicit_reference = false;

    if (preg_match_all('/' . $regex . '/s', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tag = isset($match[2]) ? (string) $match[2] : '';
            if (!in_array($tag, ['wordset_page', 'll_wordset_page'], true)) {
                continue;
            }

            $atts = shortcode_parse_atts((string) ($match[3] ?? ''));
            if (!is_array($atts)) {
                $atts = [];
            }

            $reference = ll_tools_wordset_page_shortcode_get_explicit_wordset_reference($atts);
            if ($reference === null) {
                continue;
            }

            $found_explicit_reference = true;
            $term = function_exists('ll_tools_resolve_wordset_term')
                ? ll_tools_resolve_wordset_term($reference)
                : null;
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }
    }

    if ($found_explicit_reference) {
        return null;
    }

    $page_slug = sanitize_title((string) $post->post_name);
    if ($page_slug === '') {
        return null;
    }

    $term = get_term_by('slug', $page_slug, 'wordset');
    return ($term instanceof WP_Term && !is_wp_error($term)) ? $term : null;
}

function ll_tools_get_wordset_page_shortcode_legacy_redirect_url(): string {
    if (is_admin() || !is_singular('page')) {
        return '';
    }

    $request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($request_method, ['GET', 'HEAD'], true)) {
        return '';
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return '';
    }

    $wordset_term = ll_tools_get_wordset_page_shortcode_target_term_for_post($post);
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        return '';
    }

    $page_slug = sanitize_title((string) $post->post_name);
    $wordset_slug = sanitize_title((string) $wordset_term->slug);
    if ($page_slug === '' || $page_slug !== $wordset_slug) {
        return '';
    }

    if (!function_exists('ll_tools_get_wordset_page_view_url')) {
        return '';
    }

    $redirect_url = (string) ll_tools_get_wordset_page_view_url($wordset_term);
    if ($redirect_url === '' || strpos($redirect_url, 'll_wordset_page=') === false) {
        return '';
    }

    $query_args = isset($_GET) && is_array($_GET) ? wp_unslash($_GET) : [];
    unset(
        $query_args['ll_wordset_page'],
        $query_args['ll_wordset_view'],
        $query_args['page_id'],
        $query_args['p'],
        $query_args['pagename'],
        $query_args['name'],
        $query_args['post_type']
    );
    if (!empty($query_args)) {
        $redirect_url = (string) add_query_arg($query_args, $redirect_url);
    }

    $current_url = function_exists('ll_tools_wordset_page_current_url')
        ? ll_tools_wordset_page_current_url()
        : '';
    $current_url = function_exists('ll_tools_wordset_page_normalize_same_origin_url')
        ? ll_tools_wordset_page_normalize_same_origin_url($current_url)
        : (string) wp_validate_redirect($current_url, '');
    $normalized_redirect = function_exists('ll_tools_wordset_page_normalize_same_origin_url')
        ? ll_tools_wordset_page_normalize_same_origin_url($redirect_url)
        : (string) wp_validate_redirect($redirect_url, '');
    if ($normalized_redirect === '') {
        return '';
    }

    if ($current_url !== '' && untrailingslashit($current_url) === untrailingslashit($normalized_redirect)) {
        return '';
    }

    return $redirect_url;
}

function ll_tools_wordset_page_shortcode_maybe_redirect_legacy_page(): void {
    $redirect_url = ll_tools_get_wordset_page_shortcode_legacy_redirect_url();
    if ($redirect_url === '') {
        return;
    }

    wp_safe_redirect($redirect_url, 301, 'LL Tools Legacy Wordset Page');
    exit;
}
add_action('template_redirect', 'll_tools_wordset_page_shortcode_maybe_redirect_legacy_page', 2);

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
    if (!ll_tools_wordset_page_content_has_shortcode($content)) {
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

    $wordset = ll_tools_wordset_page_shortcode_get_explicit_wordset_reference($atts);
    if ($wordset === null) {
        $wordset = '';
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
