(function (root, $) {
    'use strict';

    // Centralized icon configuration
    const ICON_CONFIG = {
        size: 32,
        color: '#000000', // Change this single value to update all icons
        strokeWidth: 2
    };

    // Icon generator functions
    function createPlayIcon() {
        return `<span class="icon-container"><svg width="${ICON_CONFIG.size}" height="${ICON_CONFIG.size}" viewBox="0 0 32 32" fill="${ICON_CONFIG.color}"><path d="M10 6v20l16-10z" stroke="${ICON_CONFIG.color}" stroke-width="${ICON_CONFIG.strokeWidth}" stroke-linejoin="round"/></svg></span>`;
    }

    function createStopIcon() {
        return `<span class="icon-container"><svg width="${ICON_CONFIG.size}" height="${ICON_CONFIG.size}" viewBox="0 0 32 32" fill="${ICON_CONFIG.color}"><rect x="8" y="8" width="16" height="16" rx="2" stroke="${ICON_CONFIG.color}" stroke-width="${ICON_CONFIG.strokeWidth}"/></svg></span>`;
    }

    const playIconHTML = createPlayIcon();
    const stopIconHTML = createStopIcon();

    const Dom = {
        setRepeatButton(state) {
            const $btn = $('#ll-tools-repeat-flashcard');
            if (!$btn.length) return;
            if (state === 'stop') {
                $btn.html(stopIconHTML);
                $btn.removeClass('play-mode').addClass('stop-mode');
            } else {
                $btn.html(playIconHTML);
                $btn.removeClass('stop-mode').addClass('play-mode');
            }
            $btn.show();
        },
        restoreHeaderUI() {
            $('#ll-tools-flashcard-header').show();
            $('#ll-tools-category-stack, #ll-tools-category-display').show();
            Dom.setRepeatButton('play');
        },
        updateCategoryNameDisplay(name) {
            if (!name) return;
            const $el = $('#ll-tools-category-display');
            if ($el.length) $el.text(String(name));
        },
        showLoading() { $('#ll-tools-loading-animation').show(); },
        hideLoading() { $('#ll-tools-loading-animation').hide(); },
        updateLearningProgress(introducedCount, totalCount, wordCorrectCounts) {
            const $progress = $('#ll-tools-learning-progress');
            if (!$progress.length) return;

            // Count how many words have reached the goal
            const completedWords = Object.values(wordCorrectCounts).filter(count => count >= 3).length;

            $progress.html(`
                <span class="progress-text">
                    Progress: ${completedWords}/${totalCount} complete |
                    ${introducedCount} introduced
                </span>
            `).show();
        },
        // Export icon generators for use in templates
        getPlayIconHTML() { return createPlayIcon(); },
        getStopIconHTML() { return createStopIcon(); }
    };

    // legacy alias some code expects:
    root.hideLoadingAnimation = Dom.hideLoading;

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Dom = Dom;
})(window, jQuery);