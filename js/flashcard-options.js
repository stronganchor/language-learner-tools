(function($) {
    // Options Module
    const FlashcardOptions = (function() {
        // Constants for option limits
        const MINIMUM_NUMBER_OF_OPTIONS = 2;
        const MAXIMUM_NUMBER_OF_OPTIONS = 9;
        const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
        const MAX_ROWS = 3;
        const DEFAULT_CARD_PIXELS = 150;

        // Internal state
        let defaultNumberOfOptions = 2; // Default value for number of options
        let categoryOptionsCount = {}; // Tracks the number of options for each category
        let maxCardWidth = DEFAULT_CARD_PIXELS;
        let maxCardHeight = DEFAULT_CARD_PIXELS;

        // Helper: Check a value against min/max constraints
        function checkMinMax(optionsCount, categoryName) {
            let maxOptionsCount = MAXIMUM_NUMBER_OF_OPTIONS;
            if (llToolsFlashcardsData.displayMode === "text") {
                maxOptionsCount = MAXIMUM_TEXT_OPTIONS;
            }
            if (window.wordsByCategory[categoryName]) {
                maxOptionsCount = Math.min(maxOptionsCount, window.wordsByCategory[categoryName].length);
            }
            maxOptionsCount = Math.max(MINIMUM_NUMBER_OF_OPTIONS, maxOptionsCount);
            return Math.min(Math.max(MINIMUM_NUMBER_OF_OPTIONS, optionsCount), maxOptionsCount);
        }

        // Helper: Initialize option counts for all categories
        function initializeOptionsCount(numberOfOptions) {
            if (numberOfOptions) {
                defaultNumberOfOptions = numberOfOptions;
            }
            window.categoryNames.forEach(categoryName => {
                setInitialOptionsCount(categoryName);
            });
        }

        // Helper: Set the initial option count for a specific category
        function setInitialOptionsCount(categoryName) {
            let existingCount = categoryOptionsCount[categoryName];
            if (existingCount && existingCount === checkMinMax(existingCount, categoryName)) {
                return;
            }

            if (window.wordsByCategory[categoryName]) {
                categoryOptionsCount[categoryName] = checkMinMax(
                    Math.min(window.wordsByCategory[categoryName].length, defaultNumberOfOptions),
                    categoryName
                );
            } else {
                categoryOptionsCount[categoryName] = checkMinMax(defaultNumberOfOptions, categoryName);
            }
        }

        // Calculate number of options dynamically based on previous answers
        function calculateNumberOfOptions(wrongIndexes, isFirstRound, currentCategoryName) {
            let numberOfOptions = categoryOptionsCount[currentCategoryName];
            numberOfOptions = checkMinMax(numberOfOptions, currentCategoryName);

            if (wrongIndexes.length > 0) {
                // Show fewer options if the user got it wrong last round
                numberOfOptions--;
            } else if (!isFirstRound) {
                // Add more options if the user got the last card right on the first try
                numberOfOptions++;
            }

            wrongIndexes.length = 0; // Reset wrongIndexes for the next round
            categoryOptionsCount[currentCategoryName] = checkMinMax(numberOfOptions, currentCategoryName);
            return numberOfOptions;
        }

        // Determine if more cards can be added to the current round
        function canAddMoreCards() {
            const cards = $('.flashcard-container');
            if (cards.length < MINIMUM_NUMBER_OF_OPTIONS) {
                return true;
            }

            const container = $('#ll-tools-flashcard');
            const containerWidth = container.width();
            const containerHeight = container.height();

            const lastCard = cards.last();
            const cardWidth = maxCardWidth;
            const cardHeight = maxCardHeight;

            const containerStyle = window.getComputedStyle(container[0]);
            const gapValue = parseInt(containerStyle.getPropertyValue('gap'), 10);

            const cardsPerRow = Math.floor((containerWidth + gapValue) / (cardWidth + gapValue));
            const rows = Math.ceil(cards.length / cardsPerRow);

            if (rows > MAX_ROWS) {
                return false;
            }

            const cardsInLastRow = cards.length - (cardsPerRow * (rows - 1));
            const lastRowWidth = cardsInLastRow * (cardWidth + gapValue) - gapValue;
            const remainingWidth = containerWidth - lastRowWidth;

            const remainingHeight =
                containerHeight - (lastCard.offset().top + cardHeight - container.offset().top + (rows - 1) * gapValue);

            const thisIsTheLastRow = rows === MAX_ROWS || remainingHeight < cardHeight;

            return !(thisIsTheLastRow && remainingWidth < (cardWidth + gapValue));
        }

        // Expose functionality
        return {
            checkMinMax,
            initializeOptionsCount,
            calculateNumberOfOptions,
            canAddMoreCards,
            get categoryOptionsCount() {
                return categoryOptionsCount;
            },
            get maxCardWidth() {
                return maxCardWidth;
            },
            set maxCardWidth(value) {
                maxCardWidth = value;
            },
            get maxCardHeight() {
                return maxCardHeight;
            },
            set maxCardHeight(value) {
                maxCardHeight = value;
            },
        };
    })();

    // Expose FlashcardOptions globally
    window.FlashcardOptions = FlashcardOptions;
})(jQuery);
