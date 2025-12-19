(function (root, $) {
    'use strict';

    /**
     * State Module - Unified state management
     *
     * Combines quiz data storage with state machine flow control
     */
    const State = {
        // State Machine Constants
        STATES: {
            IDLE: 'idle',
            LOADING: 'loading',
            QUIZ_READY: 'quiz_ready',
            SHOWING_QUESTION: 'showing_question',
            INTRODUCING_WORDS: 'introducing_words',
            PROCESSING_ANSWER: 'processing_answer',
            SHOWING_RESULTS: 'showing_results',
            SWITCHING_MODE: 'switching_mode',
            CLOSING: 'closing'
        },

        // Current flow state
        currentFlowState: 'idle',

        // Valid state transitions
        _validTransitions: null, // Initialized below

        // State change listeners
        _stateListeners: [],

        // Constants
        ROUNDS_PER_CATEGORY: 6,
        DEFAULT_DISPLAY_MODE: 'image',
        MIN_CORRECT_COUNT: 3,
        INITIAL_INTRODUCTION_COUNT: 2,
        AUDIO_REPETITIONS: 3,

        // Quiz data state
        widgetActive: false,
        categoryNames: [],
        initialCategoryNames: [],
        wordsByCategory: {},
        categoryRoundCount: {},
        firstCategoryName: (root.llToolsFlashcardsData && root.llToolsFlashcardsData.firstCategoryName) || '',
        usedWordIDs: [],
        wrongIndexes: [],
        currentCategory: null,
        currentCategoryName: null,
        currentCategoryRoundCount: 0,
        isFirstRound: true,
        currentOptionType: 'image',
        currentPromptType: 'audio',
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        userClickedCorrectAnswer: false,
        quizResults: { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} },

        // Learning/listening mode data
        isLearningMode: false,
        isListeningMode: false,
        introducedWordIDs: [],
        wordIntroductionProgress: {},
        wordCorrectCounts: {},
        wordsToIntroduce: [],
        totalWordCount: 0,
        // Listening-mode layout cache to avoid flashes during skips
        listeningLastHeight: 0,
        listeningLastAspectRatio: 0,
        listeningCurrentTarget: null,
        listeningHistory: [],
        wrongAnswerQueue: [],
        hadWrongAnswerThisTurn: false,
        isIntroducingWord: false,
        currentIntroductionAudio: null,
        currentIntroductionRound: 0,
        learningModeOptionsCount: 2,
        learningModeConsecutiveCorrect: 0,
        wordsAnsweredSinceLastIntro: new Set(),
        lastWordShownId: null,
        learningModeRepetitionQueue: [],

        // Timeout management
        activeTimeouts: [],
        abortAllOperations: false,

        /**
         * Initialize valid transitions map
         */
        _initTransitions() {
            if (this._validTransitions) return;

            const S = this.STATES;
            this._validTransitions = {
                [S.IDLE]: [S.LOADING, S.CLOSING],
                [S.LOADING]: [S.QUIZ_READY, S.INTRODUCING_WORDS, S.SHOWING_RESULTS, S.SWITCHING_MODE, S.CLOSING],
                [S.QUIZ_READY]: [S.SHOWING_QUESTION, S.INTRODUCING_WORDS, S.SHOWING_RESULTS, S.SWITCHING_MODE, S.CLOSING],
                [S.SHOWING_QUESTION]: [S.PROCESSING_ANSWER, S.SWITCHING_MODE, S.CLOSING], // Added SWITCHING_MODE
                [S.INTRODUCING_WORDS]: [S.QUIZ_READY, S.SWITCHING_MODE, S.CLOSING], // Added SWITCHING_MODE
                [S.PROCESSING_ANSWER]: [S.QUIZ_READY, S.SHOWING_RESULTS, S.SWITCHING_MODE, S.CLOSING], // Added SWITCHING_MODE
                [S.SHOWING_RESULTS]: [S.SWITCHING_MODE, S.CLOSING, S.IDLE],
                [S.SWITCHING_MODE]: [S.LOADING, S.CLOSING],
                [S.CLOSING]: [S.IDLE]
            };
        },

        /**
         * Check if a state transition is valid
         */
        canTransitionTo(newState) {
            this._initTransitions();
            const allowed = this._validTransitions[this.currentFlowState] || [];
            return allowed.includes(newState);
        },

        /**
         * Transition to a new state
         */
        transitionTo(newState, reason) {
            this._initTransitions();

            if (!this.STATES[newState.toUpperCase()]) {
                console.error('State: Invalid state', newState);
                return false;
            }

            if (!this.canTransitionTo(newState)) {
                console.warn('State: Invalid transition from', this.currentFlowState, 'to', newState);
                return false;
            }

            const oldState = this.currentFlowState;
            this.currentFlowState = newState;

            console.log('State:', oldState, '→', newState, reason ? `(${reason})` : '');

            // Notify listeners
            this._stateListeners.forEach(listener => {
                try {
                    listener(newState, oldState, reason);
                } catch (e) {
                    console.error('State: Listener error', e);
                }
            });

            return true;
        },

        /**
         * Force transition (for emergency situations)
         */
        forceTransitionTo(newState, reason) {
            const oldState = this.currentFlowState;
            this.currentFlowState = newState;
            console.warn('State: FORCED transition', oldState, '→', newState, reason ? `(${reason})` : '');

            this._stateListeners.forEach(listener => {
                try {
                    listener(newState, oldState, reason);
                } catch (e) {
                    console.error('State: Listener error', e);
                }
            });
        },

        /**
         * Get current flow state
         */
        getFlowState() {
            return this.currentFlowState;
        },

        /**
         * Alias for getFlowState (for consistency with common API)
         */
        getState() {
            return this.currentFlowState;
        },

        /**
         * Check if in specific state
         */
        is(state) {
            return this.currentFlowState === state;
        },

        /**
         * Check if in any of the provided states
         */
        isAnyOf(states) {
            return states.includes(this.currentFlowState);
        },

        /**
         * Subscribe to state changes
         */
        onStateChange(callback) {
            this._stateListeners.push(callback);
            return () => {
                this._stateListeners = this._stateListeners.filter(l => l !== callback);
            };
        },

        /**
         * Convenience checks for common operations
         */
        canStartQuizRound() {
            return this.currentFlowState === this.STATES.QUIZ_READY;
        },

        canProcessAnswer() {
            return this.currentFlowState === this.STATES.SHOWING_QUESTION;
        },

        canIntroduceWords() {
            return this.isAnyOf([this.STATES.QUIZ_READY, this.STATES.LOADING]);
        },

        canSwitchMode() {
            // Can switch mode from any active state except CLOSING and IDLE
            return this.isAnyOf([
                this.STATES.LOADING,
                this.STATES.QUIZ_READY,
                this.STATES.SHOWING_QUESTION,
                this.STATES.INTRODUCING_WORDS,
                this.STATES.PROCESSING_ANSWER,
                this.STATES.SHOWING_RESULTS
            ]);
        },

        isIntroducing() {
            return this.currentFlowState === this.STATES.INTRODUCING_WORDS;
        },

        isActive() {
            return this.currentFlowState !== this.STATES.IDLE &&
                this.currentFlowState !== this.STATES.CLOSING;
        },

        /**
         * Reset all state
         */
        reset() {
            // Set abort flag FIRST
            this.abortAllOperations = true;

            // Clear timeouts immediately
            this.clearActiveTimeouts();

            // Hide learning progress bar
            if (typeof jQuery !== 'undefined') {
                jQuery('#ll-tools-learning-progress').hide().empty();
            }

            // Reset quiz data
            this.widgetActive = false;
            this.usedWordIDs = [];
            this.categoryRoundCount = {};
            this.completedCategories = {};
            this.starPlayCounts = {};
            this.wrongIndexes = [];
            this.currentCategory = null;
            this.currentCategoryName = null;
            this.firstCategoryName = '';
            this.currentCategoryRoundCount = 0;
            this.isFirstRound = true;
            this.currentOptionType = 'image';
            this.currentPromptType = 'audio';
            this.categoryRepetitionQueues = {};
            this.practiceForcedReplays = {};
            this.userClickedCorrectAnswer = false;
            this.quizResults = { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} };

            // Reset learning mode data
            this.isLearningMode = false;
            this.isListeningMode = false;
            this.wordsLinear = [];
            this.listenIndex = 0;
            this.listeningCurrentTarget = null;
            this.listeningHistory = [];
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

            // Clear abort flag after delay
            setTimeout(() => {
                this.abortAllOperations = false;
            }, 200);
        },

        /**
         * Clear all active timeouts
         */
        clearActiveTimeouts() {
            this.activeTimeouts.forEach(id => clearTimeout(id));
            this.activeTimeouts = [];
        },

        /**
         * Add a timeout to tracking
         */
        addTimeout(timeoutId) {
            this.activeTimeouts.push(timeoutId);
        }
    };

    // Initialize transitions
    State._initTransitions();

    // Expose globally
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.State = State;

    // Legacy global aliases for modules that still read window.* directly
    root.wordsByCategory = State.wordsByCategory;
    root.categoryRoundCount = State.categoryRoundCount;
    root.categoryNames = State.categoryNames;

})(window, jQuery);
