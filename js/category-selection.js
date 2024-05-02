(function($) {
    function showCategorySelection() {
        var categories = llToolsFlashcardsData.categories;
        var preloadedCategory = llToolsFlashcardsData.firstCategoryName;

        var checkboxesContainer = $('#ll-tools-category-checkboxes');
        checkboxesContainer.empty();

        categories.forEach(function(category) {
            var modifiedCategory = category.toLowerCase().replace(/\s+/g, '-');
            var checkbox = $('<div>').append(
                $('<input>', {
                    type: 'checkbox',
                    id: 'category-' + modifiedCategory,
                    value: category,
                    checked: true,
                    'data-preloaded': category === preloadedCategory
                }),
                $('<label>', {
                    for: 'category-' + modifiedCategory,
                    text: category,
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
            return $(this).val();
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
        
        // Determine whether the user should select a category or not
        if (llToolsFlashcardsData.categoriesPreselected || llToolsFlashcardsData.categories.length === 1) {
            $('#ll-tools-flashcard-quiz-popup').show();
            initFlashcardWidget(llToolsFlashcardsData.categories);
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