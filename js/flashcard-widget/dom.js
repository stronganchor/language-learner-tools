(function (root, $) {
    'use strict';

    // Inline SVG icons - larger size
    const playIconHTML = '<span class="icon-container"><svg width="32" height="32" viewBox="0 0 32 32" fill="currentColor"><path d="M10 6v20l16-10z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span>';
    const stopIconHTML = '<span class="icon-container"><svg width="32" height="32" viewBox="0 0 32 32" fill="currentColor"><rect x="8" y="8" width="16" height="16" rx="2" stroke="currentColor" stroke-width="2"/></svg></span>';

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
    };

    // legacy alias some code expects:
    root.hideLoadingAnimation = Dom.hideLoading;

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Dom = Dom;
})(window, jQuery);