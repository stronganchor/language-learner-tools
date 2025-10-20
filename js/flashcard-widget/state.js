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

        // Track active introduction sequences to prevent race conditions
        activeIntroductionAudios: [],
        activeIntroductionTimers: [],
        isIntroductionSequenceRunning: false,

        reset() {
            // Cancel any active introductions first
            this.cancelActiveIntroductions();

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
            this.activeIntroductionAudios = [];
            this.activeIntroductionTimers = [];
            this.isIntroductionSequenceRunning = false;
        },

        cancelActiveIntroductions() {
            // Stop and clean up all active introduction audio objects
            this.activeIntroductionAudios.forEach(audio => {
                try {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.onended = null;
                    audio.onerror = null;
                    audio.ontimeupdate = null;
                    audio.src = '';
                } catch (e) {
                    // Ignore cleanup errors
                }
            });
            this.activeIntroductionAudios = [];

            // Clear all active timers
            this.activeIntroductionTimers.forEach(timerId => {
                try {
                    clearTimeout(timerId);
                } catch (e) {
                    // Ignore cleanup errors
                }
            });
            this.activeIntroductionTimers = [];

            this.isIntroductionSequenceRunning = false;
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.State = State;

    // Legacy global aliases for modules that still read window.* directly
    root.wordsByCategory = State.wordsByCategory;
    root.categoryRoundCount = State.categoryRoundCount;
    root.categoryNames = State.categoryNames;

})(window, jQuery);