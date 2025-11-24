(function (root, $) {
    'use strict';
    const { Util } = root.LLFlashcards;
    const { State } = root.LLFlashcards;
    const { Dom } = root.LLFlashcards;

    function createImageCard(word) {
        const $c = $('<div>', {
            class: 'flashcard-container flashcard-size-' + root.llToolsFlashcardsData.imageSize,
            'data-word': word.title,
            'data-word-id': word.id,
            css: { display: 'none' }
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
        const $c = $('<div>', { class: `flashcard-container text-based ${sizeClass}`, 'data-word': word.title, 'data-word-id': word.id });
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

    function playOptionAudio(word) {
        const audioApi = root.FlashcardAudio;
        if (!audioApi || !word || !word.audio) return;
        try { audioApi.pauseAllAudio(); } catch (_) { /* ignore */ }
        const audioEl = audioApi.createAudio ? audioApi.createAudio(word.audio, { type: 'option' }) : new Audio(word.audio);
        if (!audioEl) return;
        if (audioApi.playAudio) {
            audioApi.playAudio(audioEl).catch(() => { });
        } else if (audioEl.play) {
            audioEl.play().catch(() => { });
        }
    }

    function createAudioCard(word, includeText) {
        const sizeClass = 'flashcard-size-' + root.llToolsFlashcardsData.imageSize;
        const classes = ['flashcard-container', 'audio-option', sizeClass];
        if (includeText) classes.push('text-audio-option');
        const $c = $('<div>', {
            class: classes.join(' '),
            'data-word': word.title,
            'data-word-id': word.id,
            css: { display: 'none' }
        });
        const $btn = $('<button>', {
            type: 'button',
            class: 'll-audio-play',
            'aria-label': 'Play option audio'
        }).append($('<span>', { class: 'll-audio-play-icon', text: '▶' }));
        $btn.on('click', function (e) {
            e.stopPropagation();
            playOptionAudio(word);
        });
        $c.append($btn);

        if (includeText) {
            const labelText = word.label || word.title || '';
            $('<div>', { class: 'quiz-text', text: labelText }).appendTo($c);
        }
        return $c;
    }

    function insertContainerAtRandom($c) {
        const $cards = $('#ll-tools-flashcard .flashcard-container');
        const idx = Math.floor(Math.random() * ($cards.length + 1));
        if (!$cards.length || idx >= $cards.length) $('#ll-tools-flashcard').append($c);
        else $c.insertBefore($cards.eq(idx));
        $c.fadeIn(200);
    }

    function appendWordToContainer(word, optionType, promptType) {
        const mode = optionType || root.LLFlashcards.Selection.getCurrentDisplayMode();
        const isTextMode = (mode === 'text' || mode === 'text_title' || mode === 'text_translation');
        const $card = (mode === 'image')
            ? createImageCard(word)
            : (mode === 'audio'
                ? createAudioCard(word, false)
                : (mode === 'text_audio'
                    ? createAudioCard(word, true)
                    : (isTextMode ? createTextCard(word) : createTextCard(word))));
        insertContainerAtRandom($card);
        return $card;
    }

    function addClickEventToCard($card, index, targetWord, optionType, promptType) {
        const mode = optionType || root.LLFlashcards.Selection.getCurrentDisplayMode();
        const gateOnAudio = (promptType === 'audio');
        $card.off('click').on('click', function (e) {
            // Ignore clicks on the inline play button for audio options
            if ($(e.target).closest('.ll-audio-play').length) return;

            if (gateOnAudio && !root.FlashcardAudio.getTargetAudioHasPlayed()) return;

            const clickedId = String($(this).data('wordId') || $(this).attr('data-word-id') || '');
            const isCorrect = clickedId === String(targetWord.id);

            if (isCorrect) root.LLFlashcards.Main.onCorrectAnswer(targetWord, $(this));
            else root.LLFlashcards.Main.onWrongAnswer(targetWord, index, $(this));
        });
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Cards = { appendWordToContainer, addClickEventToCard };
})(window, jQuery);
