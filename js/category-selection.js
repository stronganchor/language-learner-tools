/**
 * category-selection.js
 *
 * Handles category selection interactions for the flashcard widget.
 */

(function($) {
    /**
     * Displays the category selection popup with checkboxes for each category.
     */
    function showCategorySelection() {
        var categories = llToolsFlashcardsData.categories;
        var preloadedCategory = llToolsFlashcardsData.firstCategoryName;

        var checkboxesContainer = $('#ll-tools-category-checkboxes');
        checkboxesContainer.empty();

        categories.forEach(function(category, index) {
            var displayName = category.translation || category.name;
            var checkboxId = 'category-' + category.slug;
        
            var checkbox = $('<div>').append(
                $('<input>', {
                    type: 'checkbox',
                    id: checkboxId,
                    value: category.name,
                    checked: index === 0, // Select only the first category by default
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

        $('#ll-tools-category-selection').show();
    }

    // Event handler for the "Uncheck All" button
    $('#ll-tools-uncheck-all').on('click', function() {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', false);
    });

    // Event handler for the "Check All" button
    $('#ll-tools-check-all').on('click', function() {
        $('#ll-tools-category-checkboxes input[type="checkbox"]').prop('checked', true);
    });

    // Event handler for the "Start Quiz" button
    $('#ll-tools-start-selected-quiz').on('click', function() {
        var selectedCategories = $('#ll-tools-category-checkboxes input[type="checkbox"]:checked').map(function() {
            return $(this).val(); // Pass untranslated category names
        }).get();

        if (selectedCategories.length > 0) {
            $('#ll-tools-category-selection-popup').hide();
            $('#ll-tools-flashcard-quiz-popup').show();
            initFlashcardWidget(selectedCategories);
        }
    });

    // Event handler to start the widget
    $('#ll-tools-start-flashcard').on('click', function() {
        $('#ll-tools-flashcard-popup').show();
        $('body').addClass('ll-tools-flashcard-open');

        // Prepare categoriesPreselected with untranslated names
        var preselectedCategories = llToolsFlashcardsData.categories.map(function(category) {
            return category.name; // Always use the untranslated name
        });

        if (llToolsFlashcardsData.categoriesPreselected || llToolsFlashcardsData.categories.length === 1) {
            $('#ll-tools-flashcard-quiz-popup').show();
            initFlashcardWidget(preselectedCategories);
        } else {
            $('#ll-tools-category-selection-popup').show();
            showCategorySelection();
        }
    });

    // Event handler for the close button on the category selection screen
    $('#ll-tools-close-category-selection').on('click', function() {
        $('#ll-tools-category-selection-popup').hide();
        $('#ll-tools-flashcard-popup').hide();
    });

})(jQuery);
