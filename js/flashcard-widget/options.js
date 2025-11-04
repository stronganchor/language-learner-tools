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
    const FlashcardOptions = (function () {
        // Constants for option limits
        const MINIMUM_NUMBER_OF_OPTIONS = 2;
        const MAXIMUM_NUMBER_OF_OPTIONS = (llToolsFlashcardsData.maxOptionsOverride) ? parseInt(llToolsFlashcardsData.maxOptionsOverride, 10) : 9;
        const MAXIMUM_TEXT_OPTIONS = 4; // Limit text-based quizzes to 4 options per round
        const MAX_ROWS = 3;
        const MAX_TEXT_CARD_WIDTH = 250;
        const TEXT_ASPECT = 150 / 250; // default text card height/width

        // Internal state
        let defaultNumberOfOptions = 2; // Default value for number of options
        let categoryOptionsCount = {}; // Tracks the number of options for each category
        let sizeOverridePx = null;     // When set, forces image card size (square)
        let textSizeOverride = null;   // When set, forces text card size {w,h}
        let lastMeasured = { w: 0, h: 0, gap: 0 }; // cache to avoid redundant recalcs
        let preferStacked = false;     // For image mode: force 1-per-row layout on portrait screens
        let lastPreferStacked = false;

        // ----- Layout helpers -----
        function getLayoutMetrics() {
            const $container = $('#ll-tools-flashcard');
            const $contentArea = $('#ll-tools-flashcard-content');
            if (!$container.length) return null;
            let gap = 0;
            if ($container[0]) {
                const cs = window.getComputedStyle($container[0]);
                const g = parseInt(cs.getPropertyValue('gap'), 10);
                gap = isNaN(g) ? 0 : g;
            }
            const cw = Math.max(1, $container.innerWidth());
            const ch = Math.max(1, ($contentArea.length ? $contentArea.innerHeight() : $(window).height()));
            return { $container, cw, ch, gap };
        }

        function getRowsAndPerRow(pxWidth, pxHeight) {
            const m = getLayoutMetrics();
            if (!m) return { perRow: 1, rows: 1 };
            const perRow = Math.max(1, Math.floor((m.cw + m.gap) / (pxWidth + m.gap)));
            const rows = Math.max(1, Math.floor((m.ch + m.gap) / (pxHeight + m.gap)));
            return { perRow, rows };
        }

        function preferredOptionCapForLayout(categoryName) {
            // Bias toward larger cards: if 4 items do not fit at the configured size
            // without shrinking, cap at 2 so we show two larger images instead of
            // four shrunken ones.
            const mode = (typeof window.getCategoryDisplayMode === 'function')
                ? window.getCategoryDisplayMode(categoryName)
                : null;
            if (mode === 'text') {
                // Text mode is capped elsewhere to 4 by design
                const dims = getTextCardDimensions();
                const stats = getRowsAndPerRow(dims.w, dims.h);
                const totalFit = stats.perRow * stats.rows;
                return Math.max(2, Math.min(4, totalFit));
            }

            // Image mode: use configured size, not the responsive override, to decide preference
            const px = getConfiguredCardSize();
            const stats = getRowsAndPerRow(px, px);
            const totalFit = stats.perRow * stats.rows;
            if (totalFit >= 4) return 4; // No need to shrink; 4 fits as-is
            if (totalFit >= 2) return 2; // Prefer two larger images
            return 2; // Always show at least 2; downstream will size to make it fit
        }

        /**
         * Helper function to get the maximum card size based on the plugin's imageSize setting.
         *
         * @returns {number} The maximum card size in pixels.
         */
        function getConfiguredCardSize() {
            switch (llToolsFlashcardsData.imageSize) {
                case 'medium': return 200;
                case 'large': return 250;
                default: return 150; // small
            }
        }

        function getMaxCardSize() {
            // If responsive override is set, prefer it for layout checks
            if (typeof sizeOverridePx === 'number' && sizeOverridePx > 0) return sizeOverridePx;
            return getConfiguredCardSize();
        }

        function getTextCardDimensions() {
            if (textSizeOverride && typeof textSizeOverride.w === 'number' && typeof textSizeOverride.h === 'number') {
                return { w: textSizeOverride.w, h: textSizeOverride.h };
            }
            return { w: MAX_TEXT_CARD_WIDTH, h: Math.round(MAX_TEXT_CARD_WIDTH * TEXT_ASPECT) };
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

            if (mode === 'text') {
                maxOptionsCount = Math.min(maxOptionsCount, MAXIMUM_TEXT_OPTIONS);
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

            // Layout-aware preference: cap options by what fits at configured size
            // to avoid over-shrinking images. If 4 does not fit at configured size,
            // prefer 2 larger cards instead of 4 small ones.
            try {
                const cap = preferredOptionCapForLayout(currentCategoryName);
                numberOfOptions = Math.min(numberOfOptions, cap);
            } catch (_) { /* no-op */ }

            categoryOptionsCount[currentCategoryName] = checkMinMax(numberOfOptions, currentCategoryName);
            return categoryOptionsCount[currentCategoryName];
        }

        /**
         * Determines whether more flashcard options can be added based on the current layout constraints.
         *
         * @returns {boolean} True if more cards can be added; otherwise, false.
         */
        function canAddMoreCards() {
            const $container = $('#ll-tools-flashcard');
            const $cards = $container.find('.flashcard-container');

            // Determine card width for the current mode
            ensureResponsiveSize(2); // Make sure at least 2 can fit when possible
            const size = getMaxCardSize();
            const mode = (typeof window.getCurrentDisplayMode === 'function')
                ? window.getCurrentDisplayMode()
                : null;

            let cardWidth = size;
            let cardHeight;
            if (mode === 'text') {
                // Always allow at least a second option in text mode; our responsive sizing
                // guarantees two will fit either side-by-side or stacked.
                if ($cards.length < 2) return true;
                const dims = getTextCardDimensions();
                cardWidth = dims.w;
                cardHeight = dims.h;
            }

            // Portrait screens + landscape images: prefer 2 larger options
            // Stop adding beyond two if we detect landscape-oriented images.
            try {
                const metrics = getLayoutMetrics();
                if (metrics && mode !== 'text') {
                    const viewportH = window.innerHeight || $(window).height();
                    const viewportW = window.innerWidth || $(window).width();
                    const isPortrait = viewportH > viewportW;
                    const isNarrow = metrics.cw <= 480;          // typical small phone width
                    if (isPortrait && isNarrow && $cards.length >= 2) {
                        let anyLandscape = false;
                        $cards.each(function () {
                            if (anyLandscape) return; // short-circuit
                            const $img = $(this).find('img.quiz-image');
                            if ($img.length) {
                                const el = $img[0];
                                const nw = el.naturalWidth || 0;
                                const nh = el.naturalHeight || 0;
                                if ((nw && nh && nw > nh) || $(this).hasClass('landscape')) {
                                    anyLandscape = true;
                                }
                            } else if ($(this).hasClass('landscape')) {
                                anyLandscape = true;
                            }
                        });
                        if (anyLandscape) return false;
                    }
                }
            } catch (_) { /* no-op */ }

            // Read the flex gap (fallback to 0)
            let gapValue = 0;
            if ($container.length && $container[0]) {
                const cs = window.getComputedStyle($container[0]);
                gapValue = parseInt(cs.getPropertyValue('gap'), 10);
                if (isNaN(gapValue)) gapValue = 0;
            }

            const containerWidth = Math.max(1, $container.innerWidth()); // avoid divide-by-zero
            const perRow = Math.max(1, Math.floor((containerWidth + gapValue) / (cardWidth + gapValue)));

            // Use the tallest currently-rendered card (if any) to estimate row height,
            // but never less than our bounding size for images.
            if (typeof cardHeight !== 'number') {
                const heights = [];
                $cards.each(function () { const h = $(this).outerHeight(true); if (h) heights.push(h); });
                const measuredMax = heights.length ? Math.max.apply(null, heights) : 0;
                const fallback = (mode === 'text') ? Math.round(cardWidth * TEXT_ASPECT) : size;
                cardHeight = Math.max(measuredMax || 0, fallback);
            }

            // Available vertical space: use the actual content area height (excludes header),
            // with a small safety margin to avoid bottom clipping on tiny screens.
            const $contentArea = $('#ll-tools-flashcard-content');
            const SAFETY = 12;
            const availableHeight = Math.max(1, ($contentArea.length ? $contentArea.innerHeight() : $(window).height()) - SAFETY);
            const maxRowsByHeight = Math.max(1, Math.floor((availableHeight + gapValue) / (cardHeight + gapValue)));

            // Respect the tighter of the original MAX_ROWS and the height-based limit
            const effectiveMaxRows = Math.min(MAX_ROWS, maxRowsByHeight);

            // If we were to add one more card, how many rows would we need?
            const rowsIfAdd = Math.ceil(($cards.length + 1) / perRow);

            // Only allow if we would still be within the effective max rows
            return rowsIfAdd <= effectiveMaxRows;
        }

        /**
         * Compute a responsive square card size that guarantees at least `minVisible`
         * cards can fit in the viewport without overflow for image mode.
         * Falls back to the configured size when already sufficient.
         */
        function computeResponsiveCardSize(minVisible = 2) {
            // Never shrink for listening mode here; handled in the listening module
            const State = (window.LLFlashcards && window.LLFlashcards.State) || {};
            if (State.isListeningMode) return null;

            const $container = $('#ll-tools-flashcard');
            const $popup = $('#ll-tools-flashcard-quiz-popup');
            if (!$container.length) return null;

            // Read gap from container flex layout
            let gap = 0;
            const cs = window.getComputedStyle($container[0]);
            const gapRaw = parseInt(cs.getPropertyValue('gap'), 10);
            if (!isNaN(gapRaw)) gap = gapRaw;

            const cw = Math.max(1, $container.innerWidth());
            const totalHeight = $popup.length ? $popup.innerHeight() : $(window).height();
            let headerHeight = 0;
            const $header = $('#ll-tools-flashcard-header');
            if ($header.length && $header.is(':visible')) headerHeight += $header.outerHeight(true);
            const $progress = $('#ll-tools-learning-progress');
            if ($progress.length && $progress.is(':visible')) headerHeight += $progress.outerHeight(true);
            const SAFETY = 18;
            const ch = Math.max(1, totalHeight - headerHeight - SAFETY);

            // Track measurements, but do not early-return — mode/category can change
            lastMeasured = { w: cw, h: ch, gap };

            // Start from configured size and only shrink as needed
            const configured = getConfiguredCardSize();
            const fitsAt = (px) => {
                const perRow = Math.max(1, Math.floor((cw + gap) / (px + gap)));
                const rows = Math.max(1, Math.floor((ch + gap) / (px + gap)));
                if (perRow >= minVisible && rows >= 1) return true;            // 2+ across
                if (perRow === 1 && rows >= minVisible) return true;            // 1 per row, 2+ rows
                if ((perRow * rows) >= minVisible) return true;                 // general
                return false;
            };

            // If configured size already shows 2 items, keep it (least aggressive)
            if (fitsAt(configured)) return null;

            // Preference 1: 1 per row, 2 rows (shrink height only)
            const twoRowsHeight = Math.floor((ch - gap) / 2);
            let candidate = Math.max(96, Math.min(configured, twoRowsHeight));
            if (fitsAt(candidate)) return candidate;

            // Preference 2: 2 across in 1 row (shrink width only)
            const twoAcrossWidth = Math.floor((cw - gap) / 2);
            candidate = Math.max(96, Math.min(configured, twoAcrossWidth));
            if (fitsAt(candidate)) return candidate;

            // Preference 3: progressively smaller until it fits
            const fallbacks = [Math.min(twoRowsHeight, twoAcrossWidth), 200, 180, 160, 150, 140, 128, 120, 110, 100, 96]
                .filter(v => typeof v === 'number' && v > 0);
            for (let fb of fallbacks) {
                const px = Math.max(96, Math.min(configured, fb));
                if (fitsAt(px)) return px;
            }
            // Final floor
            return 96;
        }

        /**
         * Apply or clear an inline size override for all current cards.
         */
        function applyCardSize(px, textDims) {
            const mode = (typeof window.getCurrentDisplayMode === 'function')
                ? window.getCurrentDisplayMode()
                : null;
            if (mode === 'text') {
                const $t = $('#ll-tools-flashcard .flashcard-container.text-based');
                if (!$t.length) return;
                if (textDims && typeof textDims.w === 'number' && typeof textDims.h === 'number') {
                    $t.css({ width: textDims.w + 'px', height: textDims.h + 'px', maxWidth: textDims.w + 'px', maxHeight: textDims.h + 'px' });
                } else {
                    $t.css({ width: '', height: '', maxWidth: '', maxHeight: '' });
                }
                return;
            }
            const $i = $('#ll-tools-flashcard .flashcard-container').not('.text-based');
            if (!$i.length) return;
            if (typeof px === 'number' && px > 0) {
                $i.addClass('auto-fit');
                if (preferStacked) {
                    const target = stackedSizePx || px;
                    $i.addClass('auto-fit-stacked').css({
                        flexBasis: '100%',
                        width: target + 'px',
                        maxWidth: target + 'px',
                        height: 'auto',
                        maxHeight: px + 'px',
                        margin: '0 auto'
                    });
                } else {
                    $i.removeClass('auto-fit-stacked').css({
                        flexBasis: '',
                        width: 'auto',
                        height: 'auto',
                        maxWidth: px + 'px',
                        maxHeight: px + 'px',
                        margin: ''
                    });
                }
            } else {
                $i.removeClass('auto-fit auto-fit-stacked');
                $i.css({ flexBasis: '', width: '', height: '', maxWidth: '', maxHeight: '', margin: '' });
            }
        }

        /**
         * Ensure we have a responsive size applied that allows at least 2 cards
         * to be visible in practice/learning modes.
         */
        function ensureResponsiveSize(minVisible = 2) {
            try {
                const State = (window.LLFlashcards && window.LLFlashcards.State) || {};
                if (State.isListeningMode) return; // handled in listening module
                adjustContentMaxHeight();
                preferStacked = false;

                const mode = (typeof window.getCurrentDisplayMode === 'function')
                    ? window.getCurrentDisplayMode()
                    : null;

                if (mode === 'text') {
                    // Clear image override when sizing text mode
                    sizeOverridePx = null;
                    const dims = computeResponsiveTextCardSize(minVisible);
                    if (dims === null) {
                        if (textSizeOverride) { textSizeOverride = null; applyCardSize(null, null); }
                    } else if (!textSizeOverride || textSizeOverride.w !== dims.w || textSizeOverride.h !== dims.h) {
                        textSizeOverride = dims;
                        applyCardSize(null, dims);
                    }
                    lastPreferStacked = false;
                    return;
                }

                // Clear text override when sizing image mode
                textSizeOverride = null;

                const viewportH = window.innerHeight || $(window).height();
                const viewportW = window.innerWidth || $(window).width();
                const metrics = getLayoutMetrics();

                let px = null;
                if (metrics && viewportH > viewportW && viewportW <= 640) {
                    const configured = getConfiguredCardSize();
                    const stackedW = Math.floor(metrics.cw - metrics.gap);
                    const stackedH = Math.floor((metrics.ch - metrics.gap) / 2);
                    const stackedPx = Math.max(96, Math.min(configured, stackedW, stackedH));
                    px = stackedPx;
                    preferStacked = true;
                    stackedSizePx = stackedPx;
                }

                if (!px) {
                    px = computeResponsiveCardSize(minVisible);
                    preferStacked = false;
                    stackedSizePx = null;
                }
                if (px === null) {
                    if (sizeOverridePx !== null) { sizeOverridePx = null; applyCardSize(null, null); }
                    lastPreferStacked = preferStacked;
                    return;
                }
                if (px !== sizeOverridePx || preferStacked || lastPreferStacked !== preferStacked) {
                    sizeOverridePx = px;
                    applyCardSize(px, null);
                }
                lastPreferStacked = preferStacked;
            } catch (e) { /* no-op */ }
        }

        function adjustContentMaxHeight() {
            try {
                const $popup = $('#ll-tools-flashcard-quiz-popup');
                const $content = $('#ll-tools-flashcard-content');
                const $header = $('#ll-tools-flashcard-header');
                const $progress = $('#ll-tools-learning-progress');
                if (!$content.length) return;
                const viewportH = $(window).height();
                const baseH = $popup.length ? Math.min($popup.innerHeight(), viewportH) : viewportH;
                let headerH = 0;
                if ($header.length && $header.is(':visible')) headerH += $header.outerHeight(true);
                if ($progress.length && $progress.is(':visible')) headerH += $progress.outerHeight(true);
                const SAFETY = 8;
                const targetH = Math.max(220, baseH - headerH - SAFETY);
                $content.css({ minHeight: targetH + 'px', maxHeight: targetH + 'px' });
            } catch (_) { /* no-op */ }
        }

        // Recalculate on resize with a light debounce
        let resizeTid = null;
        $(window).on('resize.llFlc', function () {
            if (resizeTid) clearTimeout(resizeTid);
            resizeTid = setTimeout(function () {
                adjustContentMaxHeight();
                ensureResponsiveSize(2);
            }, 120);
        });

        // Compute responsive width/height for text-based cards
        function computeResponsiveTextCardSize(minVisible = 2) {
            const State = (window.LLFlashcards && window.LLFlashcards.State) || {};
            if (State.isListeningMode) return null;

            const m = getLayoutMetrics();
            if (!m) return null;

            const defaultW = MAX_TEXT_CARD_WIDTH;
            const defaultH = Math.round(defaultW * TEXT_ASPECT);

            // Two stacked (1 per row)
            const hStack = Math.floor((m.ch - m.gap) / 2);
            const wStack = Math.floor(m.cw - m.gap);
            const stackH = Math.min(defaultH, Math.max(72, hStack));
            const stackW = Math.min(defaultW, Math.max(96, Math.min(wStack, Math.round(stackH / TEXT_ASPECT))));
            const finalStackH = Math.round(stackW * TEXT_ASPECT);

            // Two across (same row)
            const wAcross = Math.floor((m.cw - m.gap) / 2);
            const hAcross = Math.floor(m.ch - m.gap);
            const acrossW = Math.min(defaultW, Math.max(96, wAcross));
            const acrossH = Math.min(defaultH, Math.max(72, Math.min(hAcross, Math.round(acrossW * TEXT_ASPECT))));
            const finalAcrossW = Math.round(acrossH / TEXT_ASPECT);

            // If default fits either, keep default
            const defaultPerRow = Math.max(1, Math.floor((m.cw + m.gap) / (defaultW + m.gap)));
            const defaultRows = Math.max(1, Math.floor((m.ch + m.gap) / (defaultH + m.gap)));
            if (defaultPerRow >= 2 || defaultRows >= 2) return null;

            // Choose the option with larger height (readability), fallback to larger width
            const stackScore = finalStackH * 1000 + stackW;
            const acrossScore = acrossH * 1000 + finalAcrossW;
            if (stackScore >= acrossScore) return { w: stackW, h: finalStackH };
            return { w: finalAcrossW, h: acrossH };
        }

        // Expose functionality
        return {
            checkMinMax,
            initializeOptionsCount,
            calculateNumberOfOptions,
            canAddMoreCards,
            categoryOptionsCount,
            // Exported for potential external use
            ensureResponsiveSize,
            getMaxCardSize,
            getTextCardDimensions
        };
    })();

    // Expose FlashcardOptions globally
    window.FlashcardOptions = FlashcardOptions;
})(jQuery);
