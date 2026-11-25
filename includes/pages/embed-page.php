<?php
if (!defined('WPINC')) { die; }

/*
 * Embed Flashcard Page Template
 * Renders a minimal, embeddable flashcard quiz for a specific word category.
 * This page is not indexed by search engines.
 */
$embed_category = get_query_var('embed_category');
$wordset = isset($_GET['wordset']) ? sanitize_text_field($_GET['wordset']) : '';
$mode = isset($_GET['mode']) && is_string($_GET['mode']) ? sanitize_key($_GET['mode']) : 'practice';
$lesson_requested = array_key_exists('lesson_id', $_GET);
$lesson_id = $lesson_requested && is_scalar($_GET['lesson_id']) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $_GET['lesson_id']) ? (int) $_GET['lesson_id'] : 0;

$embed_context = function_exists('ll_tools_resolve_embed_quiz_context')
    ? ll_tools_resolve_embed_quiz_context((string) $embed_category, (string) $wordset)
    : [
        'term' => null,
        'wordset_term' => null,
        'wordset' => $wordset,
    ];

$wordset = isset($embed_context['wordset']) ? (string) $embed_context['wordset'] : $wordset;
$wordset_term = (isset($embed_context['wordset_term']) && $embed_context['wordset_term'] instanceof WP_Term)
    ? $embed_context['wordset_term']
    : null;
$term = (isset($embed_context['term']) && $embed_context['term'] instanceof WP_Term)
    ? $embed_context['term']
    : null;
$term_is_accessible = $term && !is_wp_error($term) && (!function_exists('ll_tools_user_can_view_category') || ll_tools_user_can_view_category($term));
if ($lesson_requested) {
    $term_is_accessible = $term_is_accessible && $lesson_id > 0 && $wordset_term instanceof WP_Term
        && ll_tools_vocab_lesson_activity_context_is_accessible($lesson_id, (int) $term->term_id, (int) $wordset_term->term_id);
    if ($term_is_accessible) {
        $lesson_available_modes = ll_tools_get_vocab_lesson_available_modes($lesson_id);
        if (!in_array($mode, $lesson_available_modes, true)) {
            $mode = (string) ($lesson_available_modes[0] ?? '');
        }
        $term_is_accessible = $mode !== '';
    }
}
$term = $term_is_accessible ? $term : null;
$site_name = trim(wp_strip_all_tags((string) get_bloginfo('name')));
$viewport_content = function_exists('ll_tools_get_locked_viewport_content')
    ? ll_tools_get_locked_viewport_content()
    : 'width=device-width, initial-scale=1';

if (!$term_is_accessible) {
    status_header(404);
    nocache_headers();
}

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
    <meta name="viewport" content="<?php echo esc_attr($viewport_content); ?>">
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
            $shortcode = '[flashcard_widget category="' . esc_attr((string) $term->slug) . '" embed="true"';
            if (!empty($wordset)) {
                $shortcode .= ' wordset="' . esc_attr($wordset) . '"';
            }
            if (!empty($mode) && in_array($mode, ['practice', 'learning', 'self-check', 'gender', 'listening'], true)) {
                $shortcode .= ' quiz_mode="' . esc_attr($mode) . '"';
            }
            if ($lesson_requested) { $shortcode .= ' lesson_id="' . esc_attr((string) $lesson_id) . '"'; }
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
