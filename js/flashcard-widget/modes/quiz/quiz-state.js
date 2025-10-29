(function (root) {
    'use strict';

    /**
     * QuizState - Manages state specific to quiz mode
     */
    const QuizState = {
        // Quiz-specific tracking
        wrongIndexes: [],
        categoryRepetitionQueues: {},
        quizResults: { correctOnFirstTry: 0, incorrect: [] },
        isFirstRound: true,

        reset() {
            this.wrongIndexes = [];
            this.categoryRepetitionQueues = {};
            this.quizResults = { correctOnFirstTry: 0, incorrect: [] };
            this.isFirstRound = true;
        },

        recordWrongAnswer(targetWord, categoryName, currentRoundCount) {
            const Util = root.LLFlashcards.Util;

            if (!this.quizResults.incorrect.includes(targetWord.id)) {
                this.quizResults.incorrect.push(targetWord.id);
            }

            this.categoryRepetitionQueues[categoryName] = this.categoryRepetitionQueues[categoryName] || [];
            const alreadyQueued = this.categoryRepetitionQueues[categoryName].some(
                item => item.wordData.id === targetWord.id
            );

            if (!alreadyQueued) {
                this.categoryRepetitionQueues[categoryName].push({
                    wordData: targetWord,
                    reappearRound: currentRoundCount + Util.randomInt(1, 3),
                });
            }
        },

        recordCorrectAnswer(targetWord, hadWrongAnswers, categoryName, currentRoundCount) {
            const Util = root.LLFlashcards.Util;

            if (hadWrongAnswers) {
                this.categoryRepetitionQueues[categoryName] = this.categoryRepetitionQueues[categoryName] || [];
                const alreadyQueued = this.categoryRepetitionQueues[categoryName].some(
                    item => item.wordData.id === targetWord.id
                );

                if (!alreadyQueued) {
                    this.categoryRepetitionQueues[categoryName].push({
                        wordData: targetWord,
                        reappearRound: currentRoundCount + Util.randomInt(1, 3),
                    });
                }
            }

            if (!this.quizResults.incorrect.includes(targetWord.id)) {
                this.quizResults.correctOnFirstTry += 1;
            }
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.QuizState = QuizState;
})(window);