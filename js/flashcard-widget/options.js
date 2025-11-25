/**
 * flashcard-options.js
 *
 * Manages the configuration and dynamic adjustment of flashcard options based on user interactions and quiz performance.
 */
(function ($) {
    /**
     * FlashcardOptions Module
     *
     * Handles the logic for determining the number of options per flashcard round, enforcing limits, and adjusting based on user performance.
     */

    const IMAGE_SIZE_OPTIONS = ['small', 'medium', 'large'];
    const baseImageSize = (function () {
        const configured = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.imageSize) || 'small';
        return IMAGE_SIZE_OPTIONS.includes(configured) ? configured : 'small';
    })();

    function determineResponsiveImageSize(baseSize) {
        if (typeof window === 'undefined') {
            return baseSize;
        }

        const docEl = (typeof document !== 'undefined' && document.documentElement)
            ? document.documentElement
            : { clientWidth: 0, clientHeight: 0 };
        const viewportWidth = Math.max(0, window.innerWidth || docEl.clientWidth || 0);
        const viewportHeight = Math.max(0, window.innerHeight || docEl.clientHeight || 0);

        if (!viewportWidth || !viewportHeight) {
            return baseSize;
        }

        const minDimension = Math.min(viewportWidth, viewportHeight);
        const viewportArea = viewportWidth * viewportHeight;

        let adjusted = baseSize;

        if (adjusted === 'large' && (minDimension <= 420 || viewportArea <= 240000)) {
            adjusted = 'medium';
        }

        if ((adjusted === 'medium' && (minDimension <= 360 || viewportArea <= 180000))
            || (baseSize === 'large' && (minDimension <= 360 || viewportArea <= 180000))) {
            adjusted = 'small';
        }

        return adjusted;
    }

    function applyResponsiveImageSize() {
        const effectiveSize = determineResponsiveImageSize(baseImageSize);
        if (window.llToolsFlashcardsData) {
            window.llToolsFlashcardsData.baseImageSize = baseImageSize;
            window.llToolsFlashcardsData.effectiveImageSize = effectiveSize;
            window.llToolsFlashcardsData.imageSize = effectiveSize;
        }
        return effectiveSize;
    }

    (function scheduleResponsiveImageSize() {
        applyResponsiveImageSize();
        setTimeout(applyResponsiveImageSize, 0);

        if (typeof document !== 'undefined') {
            const reapply = applyResponsiveImageSize;
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', reapply);
            } else {
                setTimeout(reapply, 0);
            }
        }

        if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
            let resizeTimer = null;
            window.addEventListener('resize', function () {
                if (resizeTimer) clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    resizeTimer = null;
                    applyResponsiveImageSize();
                }, 150);
            });
        }
    })();

    const FlashcardOptions = (function () {
        // Constants for option limits
        const MINIMUM_NUMBER_OF_OPTIONS = 2;
        const MAXIMUM_NUMBER_OF_OPTIONS = (llToolsFlashcardsData.maxOptionsOverride) ? parseInt(llToolsFlashcardsData.maxOptionsOverride, 10) : 9;
        const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
        const MAX_ROWS = 3;
        const MAX_TEXT_CARD_WIDTH = 200;

        // Internal state
        let defaultNumberOfOptions = 2; // Default value for number of options
        let categoryOptionsCount = {}; // Tracks the number of options for each category

        /**
         * Helper function to get the maximum card size based on the plugin's imageSize setting.
         *
         * @returns {number} The maximum card size in pixels.
         */
        function getMaxCardSize() {
            switch (llToolsFlashcardsData.imageSize) {
                case 'medium':
                    return 200;
                case 'large':
                    return 250;
                default:
                    return 150;  // small
            }
        }

        /**
         * Ensures the number of options is within the defined minimum and maximum limits.
         *
         * @param {number} optionsCount - The desired number of options.
         * @param {string} categoryName - The name of the category.
         * @returns {number} The adjusted number of options.
         */
        function checkMinMax(optionsCount, categoryName) {
            let maxOptionsCount = MAXIMUM_NUMBER_OF_OPTIONS;

            // Use the per-category display mode (text limits differ)
            const mode = (typeof window.getCategoryDisplayMode === 'function')
                ? window.getCategoryDisplayMode(categoryName)
                : null;

            let promptType = null;
            let optionTypeFromConfig = mode;
            if (typeof window.getCategoryConfig === 'function') {
                try {
                    const cfg = window.getCategoryConfig(categoryName) || {};
                    promptType = cfg.prompt_type || null;
                    optionTypeFromConfig = cfg.option_type || cfg.mode || mode;
                } catch (_) { /* no-op */ }
            } else if (window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.categories)) {
                const cfg = window.llToolsFlashcardsData.categories.find(function (c) {
                    return c && c.name === categoryName;
                }) || {};
                promptType = cfg.prompt_type || null;
                optionTypeFromConfig = cfg.option_type || cfg.mode || mode;
            }

            if (mode === 'text' || mode === 'text_audio' || mode === 'text_title' || mode === 'text_translation') {
                maxOptionsCount = Math.min(maxOptionsCount, MAXIMUM_TEXT_OPTIONS);
            }

            const isImagePromptAudioOptions = (promptType === 'image') &&
                (optionTypeFromConfig === 'audio' || optionTypeFromConfig === 'text_audio');
            if (isImagePromptAudioOptions) {
                maxOptionsCount = Math.min(maxOptionsCount, 4);
            }

            // Also cap by how many words are actually available in that category
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
            // Learning mode uses its own counter
            if (window.LLFlashcards && window.LLFlashcards.State && window.LLFlashcards.State.isLearningMode) {
                wrongIndexes.length = 0;
                return window.LLFlashcards.State.learningModeOptionsCount || 2;
            }

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
            const $container = $('#ll-tools-flashcard');
            const $cards = $container.find('.flashcard-container');

            if ($cards.length < MINIMUM_NUMBER_OF_OPTIONS) {
                return true;
            }

            // Determine card width for the current mode
            const size = getMaxCardSize();
            const mode = (typeof window.getCurrentDisplayMode === 'function')
                ? window.getCurrentDisplayMode()
                : null;

            let cardWidth = (mode === 'text' || mode === 'text_audio' || mode === 'text_title' || mode === 'text_translation') ? MAX_TEXT_CARD_WIDTH : size;

            // Read the flex gap (fallback to 0)
            let gapValue = 0;
            if ($container.length && $container[0]) {
                const cs = window.getComputedStyle($container[0]);
                gapValue = parseInt(cs.getPropertyValue('gap'), 10);
                if (isNaN(gapValue)) gapValue = 0;
            }

            const containerWidth = Math.max(1, $container.innerWidth()); // avoid divide-by-zero
            const perRow = Math.max(1, Math.floor((containerWidth + gapValue) / (cardWidth + gapValue)));

            // Use the visible content area if present; otherwise fall back to the container
            const $contentArea = $('#ll-tools-flashcard-content');
            const $heightScope = $contentArea.length ? $contentArea : $container;

            // Estimate card height: use an existing card if present; else fall back to cardWidth (conservative)
            const sampleCard = $cards[0];
            const cardHeight = sampleCard ? $(sampleCard).outerHeight(true) : cardWidth;

            // How many rows actually fit vertically in the available space?
            const availableHeight = Math.max(1, $heightScope.innerHeight());
            const maxRowsByHeight = Math.max(1, Math.floor((availableHeight + gapValue) / (cardHeight + gapValue)));

            // Respect the tighter of the original MAX_ROWS and the height-based limit
            const isAudioLineLayout = $container.hasClass('audio-line-layout');
            const layoutMaxRows = isAudioLineLayout ? 4 : MAX_ROWS;
            const effectiveMaxRows = Math.min(layoutMaxRows, maxRowsByHeight);

            // If we were to add one more card, how many rows would we need?
            const rowsIfAdd = Math.ceil(($cards.length + 1) / perRow);

            // Only allow if we would still be within the effective max rows
            return rowsIfAdd <= effectiveMaxRows;
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
