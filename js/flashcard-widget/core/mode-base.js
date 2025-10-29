(function (root) {
    'use strict';

    /**
     * ModeBase - Abstract base class for all flashcard modes
     *
     * All modes must implement this interface to work with the flashcard system
     */
    class ModeBase {
        constructor(config = {}) {
            this.config = config;
            this.variant = config.variant || null;
            this.state = {};
        }

        /**
         * Initialize mode - load data, set up state
         * @returns {Promise}
         */
        async initialize() {
            throw new Error('Mode must implement initialize()');
        }

        /**
         * Start the mode - begin first round/sequence
         * @returns {Promise}
         */
        async start() {
            throw new Error('Mode must implement start()');
        }

        /**
         * Cleanup when leaving mode
         * @returns {Promise}
         */
        async cleanup() {
            return Promise.resolve();
        }

        /**
         * Get next item to present (word, question, etc.)
         * @returns {Object|null}
         */
        getNextItem() {
            throw new Error('Mode must implement getNextItem()');
        }

        /**
         * Handle user interaction (click, answer, skip, etc.)
         * @param {string} action - Type of interaction
         * @param {*} data - Associated data
         * @returns {Promise}
         */
        async handleInteraction(action, data) {
            throw new Error('Mode must implement handleInteraction()');
        }

        /**
         * Check if mode can be switched from
         * @returns {boolean}
         */
        canSwitchFrom() {
            return true;
        }

        /**
         * Check if mode has completed
         * @returns {boolean}
         */
        isComplete() {
            return false;
        }

        /**
         * Get UI configuration for this mode
         * @returns {Object}
         */
        getDisplayConfig() {
            return {
                showRepeatButton: true,
                showProgressBar: false,
                showCategoryDisplay: true,
                showModeSwitcher: true,
                interactionType: 'click'
            };
        }

        /**
         * Get results data
         * @returns {Object}
         */
        getResults() {
            return {
                correctOnFirstTry: 0,
                incorrect: []
            };
        }

        /**
         * Get mode name for display
         * @returns {string}
         */
        getModeName() {
            return 'base';
        }
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.ModeBase = ModeBase;
})(window);