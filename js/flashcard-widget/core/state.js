(function (root) {
    'use strict';

    const State = {
        STATES: {
            IDLE: 'IDLE',
            LOADING: 'LOADING',
            QUIZ_READY: 'QUIZ_READY',
            SHOWING_QUESTION: 'SHOWING_QUESTION',
            INTRODUCING_WORDS: 'INTRODUCING_WORDS',
            PROCESSING_ANSWER: 'PROCESSING_ANSWER',
            SHOWING_RESULTS: 'SHOWING_RESULTS',
            SWITCHING_MODE: 'SWITCHING_MODE',
            CLOSING: 'CLOSING'
        },

        AUDIO_REPETITIONS: 3,

        currentFlowState: 'IDLE',
        _validTransitions: null,
        _stateListeners: [],

        widgetActive: false,
        categoryNames: [],
        wordsByCategory: {},
        categoryRoundCount: {},
        firstCategoryName: (root.llToolsFlashcardsData && root.llToolsFlashcardsData.firstCategoryName) || '',
        usedWordIDs: [],
        currentCategory: null,
        currentCategoryName: null,
        currentCategoryRoundCount: 0,
        isFirstRound: true,
        userClickedCorrectAnswer: false,

        isLearningMode: false,
        hadWrongAnswerThisTurn: false,

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
                [S.SHOWING_QUESTION]: [S.PROCESSING_ANSWER, S.SWITCHING_MODE, S.CLOSING],
                [S.INTRODUCING_WORDS]: [S.QUIZ_READY, S.SWITCHING_MODE, S.CLOSING],
                [S.PROCESSING_ANSWER]: [S.QUIZ_READY, S.SHOWING_RESULTS, S.SWITCHING_MODE, S.CLOSING],
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
         * Reset core state (mode-specific state is managed by mode classes)
         */
        reset() {
            this.abortAllOperations = true;
            this.clearActiveTimeouts();

            if (typeof jQuery !== 'undefined') {
                jQuery('#ll-tools-learning-progress').hide().empty();
            }

            this.widgetActive = false;
            this.usedWordIDs = [];
            this.categoryRoundCount = {};
            this.currentCategory = null;
            this.currentCategoryName = null;
            this.currentCategoryRoundCount = 0;
            this.isFirstRound = true;
            this.userClickedCorrectAnswer = false;
            this.hadWrongAnswerThisTurn = false;

            this.abortAllOperations = false;
        },

        /**
         * Add a timeout to track
         */
        addTimeout(timeoutId) {
            this.activeTimeouts.push(timeoutId);
        },

        /**
         * Clear all active timeouts
         */
        clearActiveTimeouts() {
            this.activeTimeouts.forEach(id => clearTimeout(id));
            this.activeTimeouts = [];
        },

        /**
         * Clear a specific timeout
         */
        clearTimeout(timeoutId) {
            clearTimeout(timeoutId);
            this.activeTimeouts = this.activeTimeouts.filter(id => id !== timeoutId);
        }
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.State = State;
})(window);