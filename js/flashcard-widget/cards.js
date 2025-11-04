(function (root, $) {
    'use strict';
    const { Util } = root.LLFlashcards;
    const { State } = root.LLFlashcards;
    const { Dom } = root.LLFlashcards;

    function createImageCard(word) {
        const $c = $('<div>', {
            class: 'flashcard-container flashcard-size-' + root.llToolsFlashcardsData.imageSize,
            'data-word': word.title, css: { display: 'none' }
        });
        try {
            // Apply current responsive bounding size without forcing square proportions.
            if (window.FlashcardOptions && typeof window.FlashcardOptions.getMaxCardSize === 'function') {
                const px = window.FlashcardOptions.getMaxCardSize();
                if (typeof px === 'number' && px > 0) {
                    $c.addClass('auto-fit');
                    $c.css({ width: 'auto', height: 'auto', maxWidth: px + 'px', maxHeight: px + 'px' });
                }
            }
        } catch (_) { /* no-op */ }
        $('<img>', { src: word.image, alt: word.title, class: 'quiz-image' })
            .on('load', function () {
                const fudge = 10;
                if (this.naturalWidth > this.naturalHeight + fudge) $c.addClass('landscape');
                else if (this.naturalWidth + fudge < this.naturalHeight) $c.addClass('portrait');
            })
            .appendTo($c);
        return $c;
    }

    function createTextCard(word) {
        const sizeClass = 'flashcard-size-' + root.llToolsFlashcardsData.imageSize;
        const $c = $('<div>', { class: `flashcard-container text-based ${sizeClass}`, 'data-word': word.title });
        try {
            // If responsive text sizing is active, apply before measuring text
            if (window.FlashcardOptions && typeof window.FlashcardOptions.getTextCardDimensions === 'function') {
                const dims = window.FlashcardOptions.getTextCardDimensions();
                if (dims && typeof dims.w === 'number' && typeof dims.h === 'number') {
                    $c.css({ width: dims.w + 'px', height: dims.h + 'px', maxWidth: dims.w + 'px', maxHeight: dims.h + 'px' });
                }
            }
        } catch (_) { /* no-op */ }
        const $label = $('<div>', { text: word.label, class: 'quiz-text' }).appendTo($c);

        $c.css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'block' }).appendTo('body');
        const boxH = $c.innerHeight() - 15, boxW = $c.innerWidth() - 15;
        const fontFamily = getComputedStyle($label[0]).fontFamily || 'sans-serif';
        for (let fs = 48; fs >= 12; fs--) {
            const w = Util.measureTextWidth(word.label || '', fs + 'px ' + fontFamily);
            if (w > boxW) continue;
            $label.css({ fontSize: fs + 'px', lineHeight: fs + 'px', visibility: 'visible', position: 'relative' });
            if ($label.outerHeight() <= boxH) break;
        }
        $c.detach().css({ position: '', top: '', left: '', visibility: '', display: 'none' });
        return $c;
    }

    function insertContainerAtRandom($c) {
        const $cards = $('#ll-tools-flashcard .flashcard-container');
        const idx = Math.floor(Math.random() * ($cards.length + 1));
        if (!$cards.length || idx >= $cards.length) $('#ll-tools-flashcard').append($c);
        else $c.insertBefore($cards.eq(idx));
        $c.fadeIn(200);
    }

    function appendWordToContainer(word) {
        const mode = root.LLFlashcards.Selection.getCurrentDisplayMode();
        const $card = (mode === 'image') ? createImageCard(word) : createTextCard(word);
        try {
            // Before insert, ensure size/space is recalculated so we don't overflow.
            if (window.FlashcardOptions && typeof window.FlashcardOptions.ensureResponsiveSize === 'function') {
                window.FlashcardOptions.ensureResponsiveSize(2);
            }
        } catch (_) { /* no-op */ }
        insertContainerAtRandom($card);
        try {
            // After insert, re-apply to include this card in the sizing pass.
            if (window.FlashcardOptions && typeof window.FlashcardOptions.ensureResponsiveSize === 'function') {
                window.FlashcardOptions.ensureResponsiveSize(2);
            }
        } catch (_) { /* no-op */ }
        return $card;
    }

    function addClickEventToCard($card, index, targetWord) {
        const mode = root.LLFlashcards.Selection.getCurrentDisplayMode();
        $card.off('click').on('click', function () {
            if (!root.FlashcardAudio.getTargetAudioHasPlayed()) return;

            const isCorrect = (mode === 'image')
                ? ($(this).find('img').attr('src') === targetWord.image)
                : ($(this).find('.quiz-text').text() === (targetWord.label || ''));

            if (isCorrect) root.LLFlashcards.Main.onCorrectAnswer(targetWord, $(this));
            else root.LLFlashcards.Main.onWrongAnswer(targetWord, index, $(this));
        });
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Cards = { appendWordToContainer, addClickEventToCard };
})(window, jQuery);
