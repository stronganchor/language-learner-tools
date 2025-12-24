(function (root, $) {
    'use strict';
    const { Util } = root.LLFlashcards;
    const { State } = root.LLFlashcards;
    const { Dom } = root.LLFlashcards;
    let optionMiniViz = null;

    function createImageCard(word) {
        const $c = $('<div>', {
            class: 'flashcard-container flashcard-size-' + root.llToolsFlashcardsData.imageSize,
            'data-word': word.title,
            'data-word-id': word.id,
            css: { display: 'none' }
        });
        $('<img>', { src: word.image, alt: '', 'aria-hidden': 'true', class: 'quiz-image', draggable: false })
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

    function toggleOptionPlaying($card, isPlaying) {
        if (!$card || typeof $card.toggleClass !== 'function') return;
        $card.toggleClass('playing', !!isPlaying);
        try { $card.find('.ll-audio-mini-visualizer').toggleClass('active', !!isPlaying); } catch (_) { /* no-op */ }
    }

    function playOptionAudio(word, $card) {
        const audioApi = root.FlashcardAudio;
        return new Promise((resolve) => {
            if (!audioApi || !word || !word.audio) { resolve(); return; }

            try { audioApi.pauseAllAudio(); } catch (_) { /* ignore */ }
            try {
                $('.flashcard-container.audio-option').removeClass('playing');
                $('.ll-audio-mini-visualizer').removeClass('active');
            } catch (_) { /* ignore */ }
            const audioEl = audioApi.createAudio ? audioApi.createAudio(word.audio, { type: 'option' }) : new Audio(word.audio);
            if (!audioEl) { resolve(); return; }
            const vizApi = root.LLFlashcards && root.LLFlashcards.AudioVisualizer;
            if (vizApi && typeof vizApi.createMiniVisualizer === 'function') {
                if (!optionMiniViz) {
                    optionMiniViz = vizApi.createMiniVisualizer();
                }
                const vizEl = $card && typeof $card.find === 'function' ? $card.find('.ll-audio-mini-visualizer')[0] : null;
                if (vizEl) {
                    optionMiniViz.attach(audioEl, vizEl);
                }
            }

            let settled = false;
            const finish = function () {
                if (settled) return;
                settled = true;
                toggleOptionPlaying($card, false);
                try {
                    audioEl.onended = null;
                    audioEl.onerror = null;
                } catch (_) { }
                resolve();
            };

            toggleOptionPlaying($card, true);

            // Ensure we always resolve even if playback fails
            audioEl.addEventListener && audioEl.addEventListener('ended', finish, { once: true });
            audioEl.addEventListener && audioEl.addEventListener('error', finish, { once: true });
            audioEl.onended = finish;
            audioEl.onerror = finish;

            try {
                const playPromise = audioApi.playAudio ? audioApi.playAudio(audioEl) : audioEl.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(finish);
                }
            } catch (_) {
                finish();
            }
        });
    }

    function createAudioCard(word, includeText, promptType) {
        const sizeClass = 'flashcard-size-' + root.llToolsFlashcardsData.imageSize;
        const isImagePrompt = (promptType === 'image');
        const classes = ['flashcard-container', 'audio-option'];
        if (!isImagePrompt) classes.push(sizeClass);
        if (includeText) classes.push('text-audio-option');
        const $c = $('<div>', {
            class: classes.join(' '),
            'data-word': word.title,
            'data-word-id': word.id,
            'data-audio-url': word.audio || '',
            css: { display: 'none' }
        });

        if (isImagePrompt) {
            $c.addClass('audio-line-option');
            if (!includeText) {
                $c.addClass('audio-line-option-audio-only');
            }
            $c.append($('<span>', { class: 'll-audio-option-bullet', 'aria-hidden': 'true' }));
        }

        const $btn = $('<button>', {
            type: 'button',
            class: 'll-audio-play',
            'aria-label': 'Play option audio'
        }).append($('<span>', { class: 'll-audio-play-icon', 'aria-hidden': 'true', text: '▶' }));
        $btn.on('click', function (e) {
            e.stopPropagation();
            playOptionAudio(word, $c);
        });
        $c.append($btn);

        const barCount = isImagePrompt
            ? (includeText ? 4 : 8)
            : 6;

        if (includeText && isImagePrompt) {
            const $viz = $('<div>', { class: 'll-audio-mini-visualizer', 'aria-hidden': 'true' });
            for (let i = 0; i < barCount; i++) {
                $('<span>', { class: 'bar', 'data-bar': i + 1 }).appendTo($viz);
            }
            $c.append($viz);
        }

        if (includeText) {
            const labelText = word.label || word.title || '';
            $('<div>', { class: 'quiz-text ll-audio-option-label', text: labelText }).appendTo($c);
        } else {
            const $viz = $('<div>', { class: 'll-audio-mini-visualizer', 'aria-hidden': 'true' });
            for (let i = 0; i < barCount; i++) {
                $('<span>', { class: 'bar', 'data-bar': i + 1 }).appendTo($viz);
            }
            $c.append($viz);
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
                ? createAudioCard(word, false, promptType)
                : (mode === 'text_audio'
                    ? createAudioCard(word, true, promptType)
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

    function installOptionGuards() {
        const selectors = '#ll-tools-flashcard .flashcard-container, #ll-tools-flashcard .flashcard-container img';
        $(document)
            .off('contextmenu.llFlashcardsBlock', selectors)
            .on('contextmenu.llFlashcardsBlock', selectors, function (e) {
                e.preventDefault();
            });

        $(document)
            .off('dragstart.llFlashcardsBlock', '#ll-tools-flashcard .flashcard-container img')
            .on('dragstart.llFlashcardsBlock', '#ll-tools-flashcard .flashcard-container img', function (e) {
                e.preventDefault();
            });
    }
    installOptionGuards();

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Cards = { appendWordToContainer, addClickEventToCard, playOptionAudio };
})(window, jQuery);
