(function (root, $) {
    'use strict';
    const State = {
        ROUNDS_PER_CATEGORY: 6,
        DEFAULT_DISPLAY_MODE: 'image',

        // dynamic state
        widgetActive: false,
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

        // Learning mode state
        isLearningMode: false,
        introducedWordIDs: [],
        wordIntroductionProgress: {},
        wordCorrectCounts: {},
        wordsToIntroduce: [],
        totalWordCount: 0,
        wrongAnswerQueue: [],
        hadWrongAnswerThisTurn: false,
        isIntroducingWord: false,
        currentIntroductionAudio: null,
        MIN_CORRECT_COUNT: 3,
        INITIAL_INTRODUCTION_COUNT: 2,
        AUDIO_REPETITIONS: 3,
        currentIntroductionRound: 0,
        learningModeOptionsCount: 2,
        learningModeConsecutiveCorrect: 0,
        wordsAnsweredSinceLastIntro: new Set(),
        lastWordShownId: null,
        learningModeRepetitionQueue: [],

        // Race condition guards
        audioSequenceRunning: false,
        quizRoundRunning: false,
        activeTimeouts: [],
        abortAllOperations: false,

        reset() {
            // Set abort flag FIRST to stop any in-flight operations
            this.abortAllOperations = true;

            // Clear timeouts immediately
            this.clearActiveTimeouts();

            // Set guards to prevent new operations
            this.audioSequenceRunning = false;
            this.quizRoundRunning = false;

            // Immediately hide learning progress bar to prevent flash
            if (typeof jQuery !== 'undefined') {
                jQuery('#ll-tools-learning-progress').hide().empty();
            }

            // Reset all state immediately (no setTimeout)
            this.widgetActive = false;
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

            // Reset learning mode state
            this.isLearningMode = false;
            this.introducedWordIDs = [];
            this.wordIntroductionProgress = {};
            this.wordCorrectCounts = {};
            this.wordsToIntroduce = [];
            this.totalWordCount = 0;
            this.wrongAnswerQueue = [];
            this.hadWrongAnswerThisTurn = false;
            this.isIntroducingWord = false;
            this.currentIntroductionAudio = null;
            this.currentIntroductionRound = 0;
            this.learningModeOptionsCount = 2;
            this.learningModeConsecutiveCorrect = 0;
            this.wordsAnsweredSinceLastIntro = new Set();
            this.lastWordShownId = null;
            this.learningModeRepetitionQueue = [];

            // Schedule clearing the abort flag after a short delay
            // This gives time for any in-flight checks to see the flag
            setTimeout(() => {
                this.abortAllOperations = false;
            }, 200);
        },

        clearActiveTimeouts() {
            this.activeTimeouts.forEach(id => clearTimeout(id));
            this.activeTimeouts = [];
        },

        addTimeout(timeoutId) {
            this.activeTimeouts.push(timeoutId);
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.State = State;

    // Legacy global aliases for modules that still read window.* directly
    root.wordsByCategory = State.wordsByCategory;
    root.categoryRoundCount = State.categoryRoundCount;
    root.categoryNames = State.categoryNames;

})(window, jQuery);