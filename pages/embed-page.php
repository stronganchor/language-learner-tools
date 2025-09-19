<?php
/*
 * Embed Flashcard Page Template
 * Renders a minimal, embeddable flashcard quiz for a specific word category.
 * This page is not indexed by search engines.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php wp_title(''); ?></title>
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
        // Updated section in /pages/embed-page.php
        $embed_category = get_query_var('embed_category');
        // Validate the category exists before using it
        $term = get_term_by('slug', $embed_category, 'word-category');
        if ($term && !is_wp_error($term)) {
            echo do_shortcode('[flashcard_widget category="' . esc_attr($embed_category) . '"]');
        } else {
            echo '<p>' . esc_html__('Invalid category specified.', 'll-tools-text-domain') . '</p>';
        }
        ?>
    </div>
    <?php wp_footer(); ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Remove default start/close controls
        $('#ll-tools-start-flashcard, #ll-tools-close-flashcard').remove();

        // Display quiz popup immediately
        $('#ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup').show();
        $('body').addClass('ll-tools-flashcard-open');

        // Launch directly into quiz
        var preselectedCategories = llToolsFlashcardsData.categories.map(function(cat) { return cat.name; });
        initFlashcardWidget(preselectedCategories);

        // Disable answer selection until audio has played
        $('#ll-tools-flashcard').css('pointer-events','none');
        var observer = new MutationObserver(function(mutations, obs) {
            var audioEl = document.querySelector('#ll-tools-flashcard audio');
            if (audioEl) {
                obs.disconnect();
                audioEl.addEventListener('timeupdate', function listener() {
                    if (this.currentTime > 0.4) {
                        $('#ll-tools-flashcard').css('pointer-events','auto');
                        audioEl.removeEventListener('timeupdate', listener);
                    }
                });
            }
        });
        observer.observe(document.querySelector('#ll-tools-flashcard'), { childList: true, subtree: true });
    });
    </script>
</body>
</html>
