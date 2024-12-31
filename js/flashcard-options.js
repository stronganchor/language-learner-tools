/**
 * flashcard-options.js
 *
 * Manages the configuration and dynamic adjustment of flashcard options based on user interactions and quiz performance.
 */
(function($) {
    /**
     * FlashcardOptions Module
     *
     * Handles the logic for determining the number of options per flashcard round, enforcing limits, and adjusting based on user performance.
     */
    const FlashcardOptions = (function() {
        // Constants for option limits
        const MINIMUM_NUMBER_OF_OPTIONS = 2;
        const MAXIMUM_NUMBER_OF_OPTIONS = 9;
        const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
        const MAX_ROWS = 3;
        const MAX_CARD_PIXELS = 150;
        const MAX_TEXT_CARD_WIDTH = 250;

        // Internal state
        let defaultNumberOfOptions = 2; // Default value for number of options
        let categoryOptionsCount = {}; // Tracks the number of options for each category

        /**
         * Ensures the number of options is within the defined minimum and maximum limits.
         *
         * @param {number} optionsCount - The desired number of options.
         * @param {string} categoryName - The name of the category.
         * @returns {number} The adjusted number of options.
         */
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

        /**
         * Initializes the option counts for all categories based on the default number of options.
         *
         * @param {number} [numberOfOptions] - Optional initial number of options to set.
         */
        function initializeOptionsCount(numberOfOptions) {
            if (numberOfOptions) {
                defaultNumberOfOptions = numberOfOptions;
            }
            window.categoryNames.forEach(categoryName => {
                setInitialOptionsCount(categoryName);
            });
        }

        /**
         * Sets the initial option count for a specific category.
         *
         * @param {string} categoryName - The name of the category.
         */
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

        /**
         * Calculates the number of options for the current round based on user performance.
         *
         * @param {Array} wrongIndexes - Array tracking indexes of wrong answers.
         * @param {boolean} isFirstRound - Indicates if it's the first round of the quiz.
         * @param {string} currentCategoryName - The name of the current category.
         * @returns {number} The calculated number of options for the round.
         */
        function calculateNumberOfOptions(wrongIndexes, isFirstRound, currentCategoryName) {
            let numberOfOptions = categoryOptionsCount[currentCategoryName];
            numberOfOptions = checkMinMax(numberOfOptions, currentCategoryName);

            if (wrongIndexes.length > 0) {
                // Reduce the number of options if the user got answers wrong
                numberOfOptions--;
            } else if (!isFirstRound) {
                // Increase the number of options if the user consistently answers correctly
                numberOfOptions++;
            }

            wrongIndexes.length = 0; // Reset wrongIndexes for the next round
            categoryOptionsCount[currentCategoryName] = checkMinMax(numberOfOptions, currentCategoryName);
            return numberOfOptions;
        }

        /**
         * Determines whether more flashcard options can be added based on the current layout constraints.
         *
         * @returns {boolean} True if more cards can be added; otherwise, false.
         */
        function canAddMoreCards() {
            const cards = $('.flashcard-container');
            if (cards.length < MINIMUM_NUMBER_OF_OPTIONS) {
                return true;
            }

            const container = $('#ll-tools-flashcard');
            const containerWidth = container.width();
            const containerHeight = container.height();

            const lastCard = cards.last();
            let cardWidth = MAX_CARD_PIXELS; // Changed to let to allow reassignment
            const cardHeight = MAX_CARD_PIXELS;

            const displayMode = window.getCurrentDisplayMode();
            if (displayMode === 'text') {
                cardWidth = MAX_TEXT_CARD_WIDTH; // Reassigning cardWidth based on display mode
            }

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
            categoryOptionsCount,
        };
    })();

    // Expose FlashcardOptions globally
    window.FlashcardOptions = FlashcardOptions;
})(jQuery);
