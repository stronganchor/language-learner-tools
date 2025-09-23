(function (root, $) {
    'use strict';
    const State = {
        ROUNDS_PER_CATEGORY: 6,
        DEFAULT_DISPLAY_MODE: 'image',

        // dynamic state
        categoryNames: [],
        wordsByCategory: {},
        categoryRoundCount: {},
        firstCategoryName: (root.llToolsFlashcardsData && root.llToolsFlashcardsData.firstCategoryName) || '',
        usedWordIDs: [],
        wrongIndexes: [],
        currentCategory: null,
        currentCategoryName: null,
        currentCategoryRoundCount: 0,
        isFirstRound: true,
        categoryRepetitionQueues: {},
        userClickedCorrectAnswer: false,
        quizResults: { correctOnFirstTry: 0, incorrect: [] },

        reset() {
            this.usedWordIDs = [];
            this.categoryRoundCount = {};
            this.wrongIndexes = [];
            this.currentCategory = null;
            this.currentCategoryName = null;
            this.currentCategoryRoundCount = 0;
            this.isFirstRound = true;
            this.categoryRepetitionQueues = {};
            this.userClickedCorrectAnswer = false;
            this.quizResults = { correctOnFirstTry: 0, incorrect: [] };
        },
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.State = State;

    // Legacy global aliases for modules that still read window.* directly
    root.wordsByCategory = State.wordsByCategory;
    root.categoryRoundCount = State.categoryRoundCount;
    root.categoryNames = State.categoryNames;

})(window, jQuery);
