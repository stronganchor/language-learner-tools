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
        $embed_category = get_query_var('embed_category');
        $wordset = isset($_GET['wordset']) ? sanitize_text_field($_GET['wordset']) : '';

        $term = get_term_by('slug', $embed_category, 'word-category');
        if ($term && !is_wp_error($term)) {
            $shortcode = '[flashcard_widget category="' . esc_attr($embed_category) . '" embed="true"';
            if (!empty($wordset)) {
                $shortcode .= ' wordset="' . esc_attr($wordset) . '"';
            }
            $shortcode .= ']';
            echo do_shortcode($shortcode);
        } else {
            echo '<p>' . esc_html__('Invalid category specified.', 'll-tools-text-domain') . '</p>';
        }
        ?>
    </div>
    <?php wp_footer(); ?>
    <script>
    (function(){
        // Prevent double-init no matter how many times this runs
        if (window.__llFlashcardInitOnce) return;
        window.__llFlashcardInitOnce = false;

        function selectedCategories() {
            var d = window.llToolsFlashcardsData;
            return (d && Array.isArray(d.categories)) ? d.categories.map(function(c){ return c.name; }) : [];
        }

        function showQuizUI() {
            var $ = window.jQuery;
            if (!$) return setTimeout(showQuizUI, 16);
            $('#ll-tools-start-flashcard, #ll-tools-close-flashcard').remove();
            $('#ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup').show();
            $('body').addClass('ll-tools-flashcard-open');
        }

        function waitAndInit() {
            if (window.__llFlashcardInitOnce) return;

            var hasNew   = window.LLFlashcards && LLFlashcards.Main && typeof LLFlashcards.Main.initFlashcardWidget === 'function';
            var hasLegacy = typeof window.initFlashcardWidget === 'function';
            var ready = (hasNew || hasLegacy) && window.jQuery && document.getElementById('ll-tools-flashcard');

            if (!ready) return setTimeout(waitAndInit, 30);

            window.__llFlashcardInitOnce = true;
            var cats = selectedCategories();

            if (hasNew) {
                LLFlashcards.Main.initFlashcardWidget(cats);
            } else {
                window.initFlashcardWidget(cats);
            }

            // Tell the parent that the embed is initialized so it can hide its spinner
            try {
                var targetOrigin = document.referrer ? new URL(document.referrer).origin : window.location.origin;
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'll-embed-ready' }, targetOrigin);
                }
            } catch (e) {
                // Fallback if referrer parsing is blocked
                try { window.parent && window.parent.postMessage({ type: 'll-embed-ready' }, '*'); } catch (_e) {}
            }

            // Lock pointer events briefly until audio plays a bit
            try {
                var $ = window.jQuery;
                $('#ll-tools-flashcard').css('pointer-events', 'none');
                var obs = new MutationObserver(function (_, observer) {
                    var audio = document.querySelector('#ll-tools-flashcard audio');
                    if (!audio) return;
                    observer.disconnect();
                    function onTU() {
                        if (this.currentTime > 0.4) {
                            $('#ll-tools-flashcard').css('pointer-events', 'auto');
                            audio.removeEventListener('timeupdate', onTU);
                        }
                    }
                    audio.addEventListener('timeupdate', onTU);
                });
                obs.observe(document.getElementById('ll-tools-flashcard'), { childList: true, subtree: true });
            } catch (_) {}
        }

        // Kick off
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function(){ showQuizUI(); waitAndInit(); });
        } else {
            showQuizUI(); waitAndInit();
        }
    })();
    </script>
</body>
</html>