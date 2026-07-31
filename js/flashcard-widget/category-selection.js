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

    function activateQuizDialog(selector, opener) {
        try {
            var manager = window.LLToolsQuizDialog;
            if (manager && typeof manager.activate === 'function') {
                manager.activate(selector, { opener: opener || document.activeElement });
            }
        } catch (_) { /* no-op */ }
    }

    function deactivateQuizDialog() {
        try {
            var manager = window.LLToolsQuizDialog;
            if (manager && typeof manager.deactivate === 'function') {
                manager.deactivate();
            }
        } catch (_) { /* no-op */ }
    }

    // ---- tiny shim: safely call init no matter load order ----
    function startWidget(selectedCategories, mode) {  // ADD mode parameter
        // wait until either the namespaced or legacy global init is available
        return new Promise(function (resolve, reject) {
            (function wait() {
                var start = null;
                var startContext = window;
                if (window.LLFlashcards && window.LLFlashcards.Main && typeof window.LLFlashcards.Main.initFlashcardWidget === 'function') {
                    start = window.LLFlashcards.Main.initFlashcardWidget;
                    startContext = window.LLFlashcards.Main;
                } else if (typeof window.initFlashcardWidget === 'function') {
                    start = window.initFlashcardWidget;
                }
                if (!start) {
                    setTimeout(wait, 30);
                    return;
                }
                try {
                    Promise.resolve(start.call(startContext, selectedCategories, mode)).then(resolve, reject);
                } catch (error) {
                    reject(error);
                }
            })();
        });
    }

    // Also expose a legacy global stub so any inline callers don't explode.
    if (typeof window.initFlashcardWidget !== 'function') {
        window.initFlashcardWidget = function (selectedCategories, mode) {  // ADD mode parameter
            startWidget(selectedCategories, mode);  // PASS mode through
        };
    }

    var embedAutoStarted = false;

    function notifyEmbedState(type) {
        window.__LL_EMBED_STATE = type;
        try {
            var targetOrigin = window.location.origin;
            if (document.referrer) {
                try {
                    targetOrigin = new URL(document.referrer).origin;
                } catch (_) { /* ignore */ }
            }
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: type }, targetOrigin);
            }
        } catch (e) {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: type }, '*');
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
        activateQuizDialog('#ll-tools-flashcard-quiz-popup');

        var util = (window.LLFlashcards && window.LLFlashcards.Util) || {};
        var categories = data.categories.map(function (category) {
            if (util && typeof util.getCategorySelectionValue === 'function') {
                return util.getCategorySelectionValue(category);
            }
            return category.slug || category.name;
        }).filter(Boolean);
        if (!categories.length) return;

        showEmbedAutoplayOverlay();
        startWidget(categories, data.quiz_mode).then(function () {
            notifyEmbedState('ll-embed-ready');
        }).catch(function () {
            notifyEmbedState('ll-embed-error');
        });
    }

    $(autoStartEmbedQuiz);

    /**
     * Displays the category selection popup with checkboxes for each category.
     */
    function getCategorySelectionValue(category) {
        return (window.LLFlashcards && window.LLFlashcards.Util && typeof window.LLFlashcards.Util.getCategorySelectionValue === 'function')
            ? window.LLFlashcards.Util.getCategorySelectionValue(category)
            : (category.slug || category.name);
    }

    function getSelectedCategoryValues() {
        var selected = {};
        $('#ll-tools-category-checkboxes input[type="checkbox"]:checked').each(function () {
            selected[String($(this).val() || '')] = true;
        });
        return selected;
    }

    function updateCategoryCatalogControls() {
        var data = window.llToolsFlashcardsData || {};
        var catalog = data.categoryCatalog || {};
        var messages = window.llToolsFlashcardsMessages || {};
        var hasMore = !!catalog.hasMore;
        var $button = $('#ll-tools-load-more-categories');
        $button.prop('hidden', !hasMore).toggle(hasMore);
        if (!$button.prop('disabled')) {
            $button.text(messages.categoryCatalogLoadMore || $button.text());
        }
    }

    function showCategorySelection() {
        var selectedValues = getSelectedCategoryValues();
        // Clone the categories array so that the original order remains unchanged.
        var categories = Array.isArray(llToolsFlashcardsData.categories)
            ? llToolsFlashcardsData.categories.slice()
            : [];

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
            var checkboxValue = getCategorySelectionValue(category);

            var checkbox = $('<div>').append(
                $('<input>', {
                    type: 'checkbox',
                    id: checkboxId,
                    value: checkboxValue,
                    checked: !!selectedValues[String(checkboxValue || '')],
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
        updateCategoryCatalogControls();

        // IMPORTANT: match the actual template id
        $('#ll-tools-category-selection-popup').show();
        activateQuizDialog('#ll-tools-category-selection-popup');
    }

    // Event handler for the "Uncheck All" button
    $('#ll-tools-uncheck-all').on('click', function () {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', false);
    });

    // Event handler for the "Check All" button
    $('#ll-tools-check-all').on('click', function () {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', true);
    });

    $('#ll-tools-load-more-categories').on('click', function () {
        var data = window.llToolsFlashcardsData || {};
        var catalog = data.categoryCatalog || {};
        var messages = window.llToolsFlashcardsMessages || {};
        var $button = $(this);
        var $status = $('#ll-tools-category-catalog-status');
        if (!catalog.hasMore || $button.prop('disabled')) return;

        $button.prop('disabled', true).text(messages.categoryCatalogLoading || $button.text());
        $status.text(messages.categoryCatalogLoading || '');

        $.ajax({
            url: data.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'll_get_flashcard_category_catalog',
                offset: Math.max(0, parseInt(catalog.nextOffset, 10) || 0),
                wordset: data.wordset || '',
                wordset_fallback: data.wordsetFallback ? '1' : '0'
            }
        }).done(function (response) {
            var payload = response && response.success && response.data ? response.data : null;
            if (!payload || !Array.isArray(payload.categories)) {
                $status.text(messages.categoryCatalogError || messages.somethingWentWrong || '');
                return;
            }

            var existing = {};
            if (!Array.isArray(data.categories)) {
                data.categories = [];
            }
            (Array.isArray(data.categories) ? data.categories : []).forEach(function (category) {
                existing[String(category.id || category.slug || category.name || '')] = true;
            });
            payload.categories.forEach(function (category) {
                var key = String(category.id || category.slug || category.name || '');
                if (key && !existing[key]) {
                    data.categories.push(category);
                    existing[key] = true;
                }
            });
            data.categoryCatalog = payload.catalog || { hasMore: false, nextOffset: 0, pageSize: 0 };
            window.llToolsFlashcardsData = data;
            $status.text('');
            showCategorySelection();
        }).fail(function () {
            $status.text(messages.categoryCatalogError || messages.somethingWentWrong || '');
        }).always(function () {
            $button.prop('disabled', false);
            updateCategoryCatalogControls();
        });
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
            activateQuizDialog('#ll-tools-flashcard-quiz-popup');
            startWidget(selectedCategories, llToolsFlashcardsData.quiz_mode).catch(function () { /* main UI reports launch failures */ });
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
            activateQuizDialog('#ll-tools-flashcard-quiz-popup', this);
            startWidget(preselectedCategories, llToolsFlashcardsData.quiz_mode).catch(function () { /* main UI reports launch failures */ });
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
        deactivateQuizDialog();
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
        deactivateQuizDialog();
    });

})(jQuery);
