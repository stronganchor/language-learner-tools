/**
 * category-selection.js
 *
 * Handles category selection interactions for the flashcard widget.
 */
(function ($) {
    // Apply per-instance wordset settings from the nearest container
    function syncWordsetFromDataset(ctxEl) {
        var $container = ctxEl
            ? $(ctxEl).closest('.ll-tools-flashcard-container')
            : $('.ll-tools-flashcard-container').first();
        if (!$container.length) return;

        var cfgJson = $container.attr('data-ll-config');
        var cfg = {};
        try {
            cfg = cfgJson ? JSON.parse(cfgJson) : {};
        } catch (e) { cfg = {}; }

        // Merge config into the global data object expected by scripts
        window.llToolsFlashcardsData = Object.assign({}, window.llToolsFlashcardsData || {}, cfg);

        // Ensure wordset fields are also respected
        var dsWordset = $container.attr('data-wordset') || '';
        var dsFallback = $container.attr('data-wordset-fallback');
        window.llToolsFlashcardsData.wordset = dsWordset;
        if (typeof dsFallback !== 'undefined') {
            window.llToolsFlashcardsData.wordsetFallback = (dsFallback === '1' || dsFallback === 'true');
        }
    }

    syncWordsetFromDataset();

    function warmupVisualizerContext() {
        try {
            var ll = window.LLFlashcards || {};
            var viz = ll.AudioVisualizer;
            if (!viz || typeof viz.warmup !== 'function') { return; }
            var p = viz.warmup();
            if (p && typeof p.catch === 'function') {
                p.catch(function () { return false; });
            }
        } catch (_) { /* no-op */ }
    }

    // ---- tiny shim: safely call init no matter load order ----
    function startWidget(selectedCategories, mode) {  // ADD mode parameter
        // wait until either the namespaced or legacy global init is available
        (function wait() {
            if (window.LLFlashcards && window.LLFlashcards.Main && typeof window.LLFlashcards.Main.initFlashcardWidget === 'function') {
                window.LLFlashcards.Main.initFlashcardWidget(selectedCategories, mode);  // PASS mode through
            } else if (typeof window.initFlashcardWidget === 'function') {
                window.initFlashcardWidget(selectedCategories, mode);  // PASS mode through
            } else {
                setTimeout(wait, 30);
            }
        })();
    }

    // Also expose a legacy global stub so any inline callers don't explode.
    if (typeof window.initFlashcardWidget !== 'function') {
        window.initFlashcardWidget = function (selectedCategories, mode) {  // ADD mode parameter
            startWidget(selectedCategories, mode);  // PASS mode through
        };
    }

    var embedAutoStarted = false;

    function notifyEmbedReady() {
        try {
            var targetOrigin = window.location.origin;
            if (document.referrer) {
                try {
                    targetOrigin = new URL(document.referrer).origin;
                } catch (_) { /* ignore */ }
            }
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'll-embed-ready' }, targetOrigin);
            }
        } catch (e) {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'll-embed-ready' }, '*');
                }
            } catch (_) { /* ignore */ }
        }
    }

    function showEmbedAutoplayOverlay() {
        if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.showAutoplayBlockedOverlay === 'function') {
            window.LLFlashcards.Dom.showAutoplayBlockedOverlay();
        }
    }

    function getSortLocale() {
        var data = window.llToolsFlashcardsData || {};
        return String(data.sortLocale || document.documentElement.lang || '').trim();
    }

    var localeSort = (window.LLToolsLocaleSort && typeof window.LLToolsLocaleSort.compareText === 'function')
        ? window.LLToolsLocaleSort
        : null;

    function localeTextCompare(left, right) {
        if (localeSort) {
            return localeSort.compareText(left, right, getSortLocale());
        }
        var a = String(left || '');
        var b = String(right || '');
        if (a === b) { return 0; }
        try {
            return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
        } catch (_) {
            return a < b ? -1 : (a > b ? 1 : 0);
        }
    }

    function autoStartEmbedQuiz() {
        var data = window.llToolsFlashcardsData || {};
        if (!data.isEmbed || embedAutoStarted || !Array.isArray(data.categories)) return;

        embedAutoStarted = true;
        $('body').addClass('ll-tools-flashcard-open');
        $('#ll-tools-start-flashcard, #ll-tools-close-flashcard').remove();
        $('#ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup').show();

        var util = (window.LLFlashcards && window.LLFlashcards.Util) || {};
        var categories = data.categories.map(function (category) {
            if (util && typeof util.getCategorySelectionValue === 'function') {
                return util.getCategorySelectionValue(category);
            }
            return category.slug || category.name;
        }).filter(Boolean);
        if (!categories.length) return;

        showEmbedAutoplayOverlay();
        startWidget(categories, data.quiz_mode);
        notifyEmbedReady();
    }

    $(autoStartEmbedQuiz);

    /**
     * Displays the category selection popup with checkboxes for each category.
     */
    function showCategorySelection() {
        // Clone the categories array so that the original order remains unchanged.
        var categories = llToolsFlashcardsData.categories.slice();

        // Sort categories by display name (using translation if available) with natural numeric sorting.
        categories.sort(function (a, b) {
            var nameA = a.translation || a.name;
            var nameB = b.translation || b.name;
            return localeTextCompare(nameA, nameB);
        });

        var checkboxesContainer = $('#ll-tools-category-checkboxes');
        checkboxesContainer.empty();

        categories.forEach(function (category, index) {
            var displayName = category.translation || category.name;
            var checkboxId = 'category-' + category.slug;
            var checkboxValue = (window.LLFlashcards && window.LLFlashcards.Util && typeof window.LLFlashcards.Util.getCategorySelectionValue === 'function')
                ? window.LLFlashcards.Util.getCategorySelectionValue(category)
                : (category.slug || category.name);

            var checkbox = $('<div>').append(
                $('<input>', {
                    type: 'checkbox',
                    id: checkboxId,
                    value: checkboxValue,
                    checked: false,
                    'data-preloaded': index === 0 // Preload only the first category
                }),
                $('<label>', {
                    for: checkboxId,
                    text: displayName,
                    style: 'margin-left: 5px;'
                })
            );
            checkboxesContainer.append(checkbox);
        });

        // IMPORTANT: match the actual template id
        $('#ll-tools-category-selection-popup').show();
    }

    // Event handler for the "Uncheck All" button
    $('#ll-tools-uncheck-all').on('click', function () {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', false);
    });

    // Event handler for the "Check All" button
    $('#ll-tools-check-all').on('click', function () {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', true);
    });

    // Event handler for the "Start Quiz" button
    $('#ll-tools-start-selected-quiz').on('click', function () {
        warmupVisualizerContext();
        var selectedCategories = $('#ll-tools-category-checkboxes input[type="checkbox"]:checked').map(function () {
            return $(this).val();
        }).get();

        if (selectedCategories.length > 0) {
            $('#ll-tools-category-selection-popup').hide();
            $('#ll-tools-flashcard-quiz-popup').show();
            startWidget(selectedCategories, llToolsFlashcardsData.quiz_mode);
        }
    });

    // Event handler to start the widget
    $('#ll-tools-start-flashcard').on('click', function () {
        warmupVisualizerContext();
        syncWordsetFromDataset(this);
        $('body').addClass('ll-tools-flashcard-open');
        $('#ll-tools-flashcard-popup').show();

        // Prepare categoriesPreselected with stable category identifiers
        var preselectedCategories = llToolsFlashcardsData.categories.map(function (category) {
            if (window.LLFlashcards && window.LLFlashcards.Util && typeof window.LLFlashcards.Util.getCategorySelectionValue === 'function') {
                return window.LLFlashcards.Util.getCategorySelectionValue(category);
            }
            return category.slug || category.name;
        });

        if (llToolsFlashcardsData.categoriesPreselected || llToolsFlashcardsData.categories.length === 1) {
            $('#ll-tools-flashcard-quiz-popup').show();
            startWidget(preselectedCategories, llToolsFlashcardsData.quiz_mode);
        } else {
            $('#ll-tools-category-selection-popup').show();
            showCategorySelection();
        }
    });

    $('#ll-tools-close-flashcard').on('click.llFallbackClose', function (e) {
        e.preventDefault();
        try {
            if (window.LLFlashcards && window.LLFlashcards.Main && typeof window.LLFlashcards.Main.closeFlashcard === 'function') {
                window.LLFlashcards.Main.closeFlashcard();
                return;
            }
        } catch (_) {}

        try { $('#ll-tools-category-selection-popup').hide(); } catch (_) {}
        try { $('#ll-tools-flashcard-quiz-popup').hide(); } catch (_) {}
        try { $('#ll-tools-flashcard-popup').hide(); } catch (_) {}
        try {
            $('body').removeClass('ll-tools-flashcard-open ll-qpg-popup-active').css('overflow', '');
            $('html').css('overflow', '');
        } catch (_) {}
        try {
            document.body.classList.remove('ll-tools-flashcard-open', 'll-qpg-popup-active');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        } catch (_) {}
    });

    // Event handler for the close button on the category selection screen
    $('#ll-tools-close-category-selection').on('click', function () {
        $('#ll-tools-category-selection-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
        try {
            $('body').removeClass('ll-tools-flashcard-open').css('overflow', '');
            $('html').css('overflow', '');
        } catch (_) {}
        try {
            document.body.classList.remove('ll-tools-flashcard-open');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        } catch (_) {}
    });

})(jQuery);
