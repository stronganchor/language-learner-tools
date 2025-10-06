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
        insertContainerAtRandom($card);
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
