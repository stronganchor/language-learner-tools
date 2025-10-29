/**
 * quiz-options.js
 *
 * Quiz mode specific options management
 */
(function (root) {
    'use strict';

    const QuizState = root.LLFlashcards.QuizState;

    const QuizOptions = {
        /**
         * Calculate number of options based on quiz performance
         */
        calculateNumberOfOptions(wrongIndexes, isFirstRound, currentCategoryName) {
            const FlashcardOptions = root.FlashcardOptions;

            let numberOfOptions = FlashcardOptions.categoryOptionsCount[currentCategoryName];
            numberOfOptions = FlashcardOptions.checkMinMax(numberOfOptions, currentCategoryName);

            if (wrongIndexes.length > 0) {
                numberOfOptions--;
            } else if (!isFirstRound) {
                numberOfOptions++;
            }

            wrongIndexes.length = 0;
            FlashcardOptions.categoryOptionsCount[currentCategoryName] =
                FlashcardOptions.checkMinMax(numberOfOptions, currentCategoryName);

            return numberOfOptions;
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.QuizOptions = QuizOptions;
})(window);