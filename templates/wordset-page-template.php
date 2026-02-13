<?php
// /templates/wordset-page-template.php
if (!defined('WPINC')) { die; }

$wordset = function_exists('ll_tools_get_wordset_page_term') ? ll_tools_get_wordset_page_term() : null;
if (!$wordset || is_wp_error($wordset)) {
    status_header(404);
    nocache_headers();
    get_header();
    if (function_exists('ll_tools_render_wordset_page_content')) {
        echo ll_tools_render_wordset_page_content(null);
    } else {
        echo '<main class="ll-wordset-page ll-wordset-page--missing"><div class="ll-wordset-empty">';
        echo esc_html__('Word set not found.', 'll-tools-text-domain');
        echo '</div></main>';
    }
    get_footer();
    return;
}

$wp_query = $GLOBALS['wp_query'] ?? null;
if ($wp_query) {
    $wp_query->is_404 = false;
}
status_header(200);

$page_title = $wordset->name ?: get_bloginfo('name');
add_filter('pre_get_document_title', function () use ($page_title) {
    return $page_title;
});

get_header();
if (function_exists('ll_tools_render_wordset_page_content')) {
    echo ll_tools_render_wordset_page_content($wordset);
}
get_footer();
