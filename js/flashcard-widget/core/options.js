/**
 * flashcard-options.js
 *
 * Manages generic flashcard options configuration
 */
(function ($) {
    const FlashcardOptions = (function () {
        const MINIMUM_NUMBER_OF_OPTIONS = 2;
        const MAXIMUM_NUMBER_OF_OPTIONS = (llToolsFlashcardsData.maxOptionsOverride) ? parseInt(llToolsFlashcardsData.maxOptionsOverride, 10) : 9;
        const MAXIMUM_TEXT_OPTIONS = 4;
        const MAX_ROWS = 3;
        const MAX_TEXT_CARD_WIDTH = 250;

        let defaultNumberOfOptions = 2;
        let categoryOptionsCount = {};

        /**
         * Get maximum card size based on imageSize setting
         */
        function getMaxCardSize() {
            switch (llToolsFlashcardsData.imageSize) {
                case 'medium':
                    return 200;
                case 'large':
                    return 250;
                default:
                    return 150;
            }
        }

        /**
         * Ensure options count is within min/max limits
         */
        function checkMinMax(optionsCount, categoryName) {
            let maxOptionsCount = MAXIMUM_NUMBER_OF_OPTIONS;

            const mode = (typeof window.getCategoryDisplayMode === 'function')
                ? window.getCategoryDisplayMode(categoryName)
                : null;

            if (mode === 'text') {
                maxOptionsCount = Math.min(maxOptionsCount, MAXIMUM_TEXT_OPTIONS);
            }

            if (window.wordsByCategory && window.wordsByCategory[categoryName]) {
                maxOptionsCount = Math.min(maxOptionsCount, window.wordsByCategory[categoryName].length);
            }

            maxOptionsCount = Math.max(MINIMUM_NUMBER_OF_OPTIONS, maxOptionsCount);
            return Math.min(Math.max(MINIMUM_NUMBER_OF_OPTIONS, optionsCount), maxOptionsCount);
        }

        /**
         * Initialize option counts for all categories
         */
        function initializeOptionsCount(numberOfOptions) {
            if (numberOfOptions) {
                defaultNumberOfOptions = numberOfOptions;
            }
            if (window.categoryNames) {
                window.categoryNames.forEach(categoryName => {
                    setInitialOptionsCount(categoryName);
                });
            }
        }

        /**
         * Set initial option count for a category
         */
        function setInitialOptionsCount(categoryName) {
            let existingCount = categoryOptionsCount[categoryName];
            if (existingCount && existingCount === checkMinMax(existingCount, categoryName)) {
                return;
            }

            if (window.wordsByCategory && window.wordsByCategory[categoryName]) {
                categoryOptionsCount[categoryName] = checkMinMax(
                    Math.min(window.wordsByCategory[categoryName].length, defaultNumberOfOptions),
                    categoryName
                );
            } else {
                categoryOptionsCount[categoryName] = checkMinMax(defaultNumberOfOptions, categoryName);
            }
        }

        /**
         * Check if more cards can fit in the current layout
         */
        function canAddMoreCards() {
            const $container = $('#ll-tools-flashcard');
            const $cards = $container.find('.flashcard-container');

            const size = getMaxCardSize();
            const mode = (typeof window.getCurrentDisplayMode === 'function')
                ? window.getCurrentDisplayMode()
                : null;

            let cardWidth = (mode === 'text') ? MAX_TEXT_CARD_WIDTH : size;

            let gapValue = 0;
            if ($container.length && $container[0]) {
                const cs = window.getComputedStyle($container[0]);
                gapValue = parseInt(cs.getPropertyValue('gap'), 10);
                if (isNaN(gapValue)) gapValue = 0;
            }

            const containerWidth = Math.max(1, $container.innerWidth());
            const perRow = Math.max(1, Math.floor((containerWidth + gapValue) / (cardWidth + gapValue)));

            const $contentArea = $('#ll-tools-flashcard-content');
            const $heightScope = $contentArea.length ? $contentArea : $container;

            const sampleCard = $cards[0];
            const cardHeight = sampleCard ? $(sampleCard).outerHeight(true) : cardWidth;

            const availableHeight = Math.max(1, $heightScope.innerHeight());
            const maxRowsByHeight = Math.max(1, Math.floor((availableHeight + gapValue) / (cardHeight + gapValue)));

            const effectiveMaxRows = Math.min(MAX_ROWS, maxRowsByHeight);
            const rowsIfAdd = Math.ceil(($cards.length + 1) / perRow);

            return rowsIfAdd <= effectiveMaxRows;
        }

        return {
            checkMinMax,
            initializeOptionsCount,
            canAddMoreCards,
            categoryOptionsCount,
            MINIMUM_NUMBER_OF_OPTIONS,
            MAXIMUM_NUMBER_OF_OPTIONS,
            MAXIMUM_TEXT_OPTIONS
        };
    })();

    window.FlashcardOptions = FlashcardOptions;
})(jQuery);