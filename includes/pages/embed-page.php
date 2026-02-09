<?php
/*
 * Embed Flashcard Page Template
 * Renders a minimal, embeddable flashcard quiz for a specific word category.
 * This page is not indexed by search engines.
 */
$embed_category = get_query_var('embed_category');
$wordset = isset($_GET['wordset']) ? sanitize_text_field($_GET['wordset']) : '';
$mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'practice';

$term = $embed_category ? get_term_by('slug', $embed_category, 'word-category') : null;
$site_name = trim(wp_strip_all_tags((string) get_bloginfo('name')));

$quiz_page_title = '';
if ($term && !is_wp_error($term)) {
    if (function_exists('ll_tools_get_quiz_title_for_term')) {
        $quiz_page_title = ll_tools_get_quiz_title_for_term($term, true);
    } else {
        $quiz_page_title = sprintf(__('Quiz: %s', 'll-tools-text-domain'), $term->name);
        if ($site_name !== '') {
            $quiz_page_title .= ' | ' . $site_name;
        }
    }
} elseif ($site_name !== '') {
    $quiz_page_title = $site_name;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($quiz_page_title !== ''): ?>
        <?php
        add_filter('pre_get_document_title', function () use ($quiz_page_title) {
            return $quiz_page_title;
        });
        $document_title = $quiz_page_title;
        ?>
    <?php else: ?>
        <?php $document_title = function_exists('wp_get_document_title') ? wp_get_document_title() : wp_title('', false); ?>
    <?php endif; ?>
    <title><?php echo esc_html($document_title); ?></title>
    <?php if ($quiz_page_title !== ''): ?>
        <meta property="og:title" content="<?php echo esc_attr($quiz_page_title); ?>">
        <meta name="twitter:title" content="<?php echo esc_attr($quiz_page_title); ?>">
    <?php endif; ?>
    <?php wp_head(); ?>
    <style>
        /* Transparent background for embedded iframe quiz */
        html, body, #ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup {
            background: transparent !important;
        }
    </style>
    <script>
    // Hide the WP admin bar only when this page is inside an iframe
    (function () {
        if (window.top !== window.self) {
            var css = '#wpadminbar{display:none !important;} html{margin-top:0 !important;}';
            var style = document.createElement('style');
            style.type = 'text/css';
            style.appendChild(document.createTextNode(css));
            document.head.appendChild(style);
        }
    })();
    </script>
</head>
<body <?php body_class(); ?> style="background: transparent;">
    <div class="entry-content" style="display: flex; justify-content: center; align-items: center; min-height: 90vh;">
        <?php
        if ($term && !is_wp_error($term)) {
            if (empty($wordset) && function_exists('ll_get_default_wordset_id_for_category')) {
                $default_ws_id = ll_get_default_wordset_id_for_category($term->name, LL_TOOLS_MIN_WORDS_PER_QUIZ);
                if ($default_ws_id > 0) {
                    $ws_term = get_term($default_ws_id, 'wordset');
                    if ($ws_term && !is_wp_error($ws_term)) {
                        $wordset = $ws_term->slug;
                    }
                }
            }
            $shortcode = '[flashcard_widget category="' . esc_attr($embed_category) . '" embed="true"';
            if (!empty($wordset)) {
                $shortcode .= ' wordset="' . esc_attr($wordset) . '"';
            }
            if (!empty($mode) && in_array($mode, ['practice', 'learning', 'self-check', 'listening'], true)) {
                $shortcode .= ' quiz_mode="' . esc_attr($mode) . '"';
            }
            $shortcode .= ']';
            echo do_shortcode($shortcode);
        } else {
            echo '<p>' . esc_html__('Invalid category specified.', 'll-tools-text-domain') . '</p>';
        }
        ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
