(function (root, $) {
    'use strict';
    const Dom = {
        setRepeatButton(state) {
            const $btn = $('#ll-tools-repeat-flashcard');
            if (!$btn.length) return;
            const base = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.plugin_dir) || '';
            if (state === 'stop') {
                $btn.html('<span class="icon-container"><img src="' + base + 'media/stop-symbol.svg" alt="Stop"></span>');
                $btn.removeClass('play-mode').addClass('stop-mode');
            } else {
                $btn.html('<span class="icon-container"><img src="' + base + 'media/play-symbol.svg" alt="Play"></span>');
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
