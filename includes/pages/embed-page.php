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
        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'standard';

        $term = get_term_by('slug', $embed_category, 'word-category');
        if ($term && !is_wp_error($term)) {
            $shortcode = '[flashcard_widget category="' . esc_attr($embed_category) . '" embed="true"';
            if (!empty($wordset)) {
                $shortcode .= ' wordset="' . esc_attr($wordset) . '"';
            }
            if (!empty($mode) && in_array($mode, ['standard', 'learning'])) {
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
    <script>
    (function(){
        // Prevent double-init no matter how many times this runs
        if (window.__llFlashcardInitOnce) return;
        window.__llFlashcardInitOnce = false;
        var quizInitialized = false;

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

        function initializeQuiz() {
            if (quizInitialized) return;

            var hasNew   = window.LLFlashcards && LLFlashcards.Main && typeof LLFlashcards.Main.initFlashcardWidget === 'function';
            var hasLegacy = typeof window.initFlashcardWidget === 'function';

            if (!hasNew && !hasLegacy) {
                console.error('Quiz initialization functions not found');
                return;
            }

            quizInitialized = true;
            var cats = selectedCategories();
            var mode = window.llToolsFlashcardsData?.quiz_mode || 'standard';

            console.log('Initializing quiz after user interaction', cats, mode);

            if (hasNew) {
                LLFlashcards.Main.initFlashcardWidget(cats, mode);
            } else {
                window.initFlashcardWidget(cats, mode);
            }

            // Tell parent we're ready
            try {
                var targetOrigin = document.referrer ? new URL(document.referrer).origin : window.location.origin;
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'll-embed-ready' }, targetOrigin);
                }
            } catch (e) {
                try { window.parent && window.parent.postMessage({ type: 'll-embed-ready' }, '*'); } catch (_e) {}
            }
        }

        // Show autoplay prompt overlay
        function showAutoplayPrompt() {
            var $ = window.jQuery;
            if (!$) return setTimeout(showAutoplayPrompt, 16);

            var $body = $('body');
            if (!$body.length) return setTimeout(showAutoplayPrompt, 16);

            // Check if overlay already exists
            if ($('#ll-tools-autoplay-overlay').length) return;

            var $overlay = $('<div>', {
                id: 'll-tools-autoplay-overlay',
                class: 'll-tools-autoplay-overlay'
            }).css({
                position: 'fixed',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                background: 'rgba(0, 0, 0, 0.5)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 999999,
                backdropFilter: 'blur(3px)'
            });

            var $button = $('<button>', {
                class: 'll-tools-autoplay-button',
                'aria-label': 'Click to start',
                html: '<svg width="120" height="120" viewBox="0 0 120 120" fill="white"><circle cx="60" cy="60" r="55" stroke="white" stroke-width="4" fill="rgba(255,255,255,0.15)"/><path d="M45 30 L45 90 L90 60 Z" fill="white"/></svg>'
            }).css({
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                background: 'transparent',
                border: 'none',
                cursor: 'pointer',
                padding: '20px'
            });

            // Add pulsing animation
            var style = $('<style>').text(`
                @keyframes llAutoplayPulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.85; transform: scale(1.08); }
                }
                .ll-tools-autoplay-button {
                    animation: llAutoplayPulse 2s ease-in-out infinite;
                }
                .ll-tools-autoplay-button:hover {
                    transform: scale(1.12) !important;
                    animation: none;
                }
                .ll-tools-autoplay-button:active {
                    transform: scale(0.95) !important;
                }
            `);
            $('head').append(style);

            $button.on('click', function() {
                console.log('Autoplay overlay clicked - starting quiz');
                $overlay.fadeOut(300, function() { $(this).remove(); });

                // Enable interactions
                $('#ll-tools-flashcard').css('pointer-events', 'auto');

                // Initialize the quiz NOW (not before)
                initializeQuiz();
            });

            $overlay.append($button);
            $body.append($overlay);

            // Initially disable interactions
            $('#ll-tools-flashcard').css('pointer-events', 'none');
        }

        function waitForDependencies() {
            if (window.__llFlashcardInitOnce) return;

            var ready = window.jQuery && document.getElementById('ll-tools-flashcard');

            if (!ready) return setTimeout(waitForDependencies, 30);

            window.__llFlashcardInitOnce = true;

            // DON'T initialize the quiz yet - just show the UI and overlay
            showQuizUI();
            showAutoplayPrompt();
        }

        // Kick off
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', waitForDependencies);
        } else {
            waitForDependencies();
        }
    })();
    </script>
</body>
</html>