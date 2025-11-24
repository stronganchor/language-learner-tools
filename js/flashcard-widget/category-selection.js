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

    /**
     * Displays the category selection popup with checkboxes for each category.
     */
    function showCategorySelection() {
        // Clone the categories array so that the original order remains unchanged.
        var categories = llToolsFlashcardsData.categories.slice();

        // Sort categories by display name (using translation if available) with natural numeric sorting.
        categories.sort(function (a, b) {
            var nameA = (a.translation || a.name).toLowerCase();
            var nameB = (b.translation || b.name).toLowerCase();
            return nameA.localeCompare(nameB, undefined, { numeric: true });
        });

        var checkboxesContainer = $('#ll-tools-category-checkboxes');
        checkboxesContainer.empty();

        categories.forEach(function (category, index) {
            var displayName = category.translation || category.name;
            var checkboxId = 'category-' + category.slug;

            var checkbox = $('<div>').append(
                $('<input>', {
                    type: 'checkbox',
                    id: checkboxId,
                    value: category.name,
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
        syncWordsetFromDataset(this);
        $('#ll-tools-flashcard-popup').show();
        $('body').addClass('ll-tools-flashcard-open');

        // Prepare categoriesPreselected with untranslated names
        var preselectedCategories = llToolsFlashcardsData.categories.map(function (category) {
            return category.name; // Always use the untranslated name
        });

        if (llToolsFlashcardsData.categoriesPreselected || llToolsFlashcardsData.categories.length === 1) {
            $('#ll-tools-flashcard-quiz-popup').show();
            startWidget(preselectedCategories, llToolsFlashcardsData.quiz_mode);
        } else {
            $('#ll-tools-category-selection-popup').show();
            showCategorySelection();
        }
    });

    // Event handler for the close button on the category selection screen
    $('#ll-tools-close-category-selection').on('click', function () {
        $('#ll-tools-category-selection-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
    });

})(jQuery);
