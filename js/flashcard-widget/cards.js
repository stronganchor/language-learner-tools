(function (root, $) {
    'use strict';
    const { Util } = root.LLFlashcards;
    const { State } = root.LLFlashcards;
    const { Dom } = root.LLFlashcards;
    let optionMiniViz = null;

    function clampInt(value, min, max, fallback) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) {
            return fallback;
        }
        return Math.max(min, Math.min(max, parsed));
    }

    function clampNumber(value, min, max, fallback) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed)) {
            return fallback;
        }
        return Math.max(min, Math.min(max, parsed));
    }

    function getAnswerOptionTextStyleConfig() {
        const raw = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object' && root.llToolsFlashcardsData.answerOptionTextStyle && typeof root.llToolsFlashcardsData.answerOptionTextStyle === 'object')
            ? root.llToolsFlashcardsData.answerOptionTextStyle
            : ((root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object' && root.llToolsFlashcardsData.answer_option_text_style && typeof root.llToolsFlashcardsData.answer_option_text_style === 'object')
                ? root.llToolsFlashcardsData.answer_option_text_style
                : {});

        let fontFamily = String(raw.fontFamily || '').trim();
        fontFamily = fontFamily.replace(/[\r\n{};]/g, ' ').trim();
        if (fontFamily.length > 160) {
            fontFamily = fontFamily.slice(0, 160).trim();
        }

        let fontWeight = String(raw.fontWeight || '700').trim();
        if (!/^(400|500|600|700|800|900)$/.test(fontWeight)) {
            fontWeight = '700';
        }

        const lineHeightRatio = clampNumber(raw.lineHeightRatio, 1.05, 2.2, 1.22);
        const lineHeightRatioWithDiacritics = Math.max(
            lineHeightRatio,
            clampNumber(raw.lineHeightRatioWithDiacritics, 1.05, 2.4, 1.4)
        );

        return {
            fontFamily: fontFamily,
            fontWeight: fontWeight,
            fontSizePx: clampInt(raw.fontSizePx, 12, 72, 48),
            minFontSizePx: clampInt(raw.minFontSizePx, 10, 24, 12),
            lineHeightRatio: lineHeightRatio,
            lineHeightRatioWithDiacritics: lineHeightRatioWithDiacritics
        };
    }

    function textHasCombiningMarks(value) {
        return /[\u0300-\u036F\u0591-\u05C7\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06ED]/.test(String(value || ''));
    }

    function getAnswerOptionLineHeightRatio(text) {
        const cfg = getAnswerOptionTextStyleConfig();
        if (textHasCombiningMarks(text)) {
            return cfg.lineHeightRatioWithDiacritics;
        }
        return cfg.lineHeightRatio;
    }

    function applyAnswerOptionContainerCssVars() {
        const cfg = getAnswerOptionTextStyleConfig();
        const container = document.getElementById('ll-tools-flashcard-container') || document.getElementById('ll-tools-flashcard-popup');
        if (!container || !container.style) {
            return;
        }

        if (cfg.fontFamily) {
            container.style.setProperty('--ll-answer-option-font-family', cfg.fontFamily);
        } else {
            container.style.removeProperty('--ll-answer-option-font-family');
        }
        container.style.setProperty('--ll-answer-option-font-weight', cfg.fontWeight);
        container.style.setProperty('--ll-answer-option-font-size-px', cfg.fontSizePx + 'px');
        container.style.setProperty('--ll-answer-option-text-line-height', String(cfg.lineHeightRatio));
        container.style.setProperty('--ll-answer-option-text-line-height-marked', String(cfg.lineHeightRatioWithDiacritics));
    }

    function applyAnswerOptionTextStyle($label, text) {
        if (!$label || !$label.length) {
            return;
        }
        const cfg = getAnswerOptionTextStyleConfig();
        applyAnswerOptionContainerCssVars();

        if (cfg.fontFamily) {
            $label.css('font-family', cfg.fontFamily);
        } else {
            $label.css('font-family', '');
        }
        $label.css('font-weight', cfg.fontWeight);

        const lineHeightRatio = getAnswerOptionLineHeightRatio(text);
        $label.css('--ll-answer-option-line-height-ratio', String(lineHeightRatio));
        if (textHasCombiningMarks(text)) {
            $label.attr('data-ll-combining-marks', '1');
        } else {
            $label.removeAttr('data-ll-combining-marks');
        }
    }

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
        const labelText = word.label || word.title || '';
        const $label = $('<div>', { text: labelText, class: 'quiz-text', dir: 'auto' }).appendTo($c);
        applyAnswerOptionTextStyle($label, labelText);

        $c.css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'block' }).appendTo('body');
        const boxH = $c.innerHeight() - 15, boxW = $c.innerWidth() - 15;
        const computed = getComputedStyle($label[0]);
        const fontFamily = computed.fontFamily || 'sans-serif';
        const fontWeight = String(computed.fontWeight || getAnswerOptionTextStyleConfig().fontWeight || '700');
        const cfg = getAnswerOptionTextStyleConfig();
        const minFontSize = clampInt(cfg.minFontSizePx, 10, 24, 12);
        const startFontSize = Math.max(minFontSize, clampInt(cfg.fontSizePx, minFontSize, 72, 48));
        const lineHeightRatio = getAnswerOptionLineHeightRatio(labelText);
        let fitted = false;

        for (let fs = startFontSize; fs >= minFontSize; fs--) {
            const w = Util.measureTextWidth(labelText || '', fontWeight + ' ' + fs + 'px ' + fontFamily);
            if (w > boxW) continue;
            const lineHeightPx = Math.round(fs * lineHeightRatio * 100) / 100;
            $label.css({ fontSize: fs + 'px', lineHeight: lineHeightPx + 'px', visibility: 'visible', position: 'relative' });
            if ($label.outerHeight() <= boxH) {
                fitted = true;
                break;
            }
        }
        if (!fitted) {
            const fallbackLineHeightPx = Math.round(minFontSize * lineHeightRatio * 100) / 100;
            $label.css({
                fontSize: minFontSize + 'px',
                lineHeight: fallbackLineHeightPx + 'px',
                visibility: 'visible',
                position: 'relative'
            });
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
        }).append(
            $('<span>', { class: 'll-audio-play-icon', 'aria-hidden': 'true' }).append(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true"><path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/></svg>'
            )
        );
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
            const $label = $('<div>', { class: 'quiz-text ll-audio-option-label', text: labelText, dir: 'auto' }).appendTo($c);
            applyAnswerOptionTextStyle($label, labelText);
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

    function appendWordToContainer(word, optionType, promptType, ordered) {
        const mode = optionType || root.LLFlashcards.Selection.getCurrentDisplayMode();
        const isTextMode = (mode === 'text' || mode === 'text_title' || mode === 'text_translation');
        const $card = (mode === 'image')
            ? createImageCard(word)
            : (mode === 'audio'
                ? createAudioCard(word, false, promptType)
                : (mode === 'text_audio'
                    ? createAudioCard(word, true, promptType)
                    : (isTextMode ? createTextCard(word) : createTextCard(word))));
        if (ordered) {
            $('#ll-tools-flashcard').append($card);
            $card.fadeIn(200);
        } else {
            insertContainerAtRandom($card);
        }
        return $card;
    }

    function addClickEventToCard($card, index, targetWord, optionType, promptType) {
        const gateOnAudio = (promptType === 'audio');
        let lastPointerHandledAt = 0;
        const handleSelection = function (e, triggerType) {
            // Ignore clicks on the inline play button for audio options
            if ($(e.target).closest('.ll-audio-play').length) return;

            // On touch devices pointerup is followed by click; suppress duplicate handling.
            if (triggerType === 'click' && lastPointerHandledAt > 0 && (Date.now() - lastPointerHandledAt) < 450) {
                return;
            }

            const $clicked = $(this);
            const ariaDisabled = String($clicked.attr('aria-disabled') || '').toLowerCase();
            if ($clicked.hasClass('ll-option-disabled') || ariaDisabled === 'true') {
                return;
            }
            const isGenderMode = !!(root.LLFlashcards && root.LLFlashcards.State && root.LLFlashcards.State.isGenderMode);
            const isPracticeMode = !!(root.LLFlashcards && root.LLFlashcards.State) &&
                !root.LLFlashcards.State.isLearningMode &&
                !root.LLFlashcards.State.isListeningMode &&
                !root.LLFlashcards.State.isGenderMode &&
                !root.LLFlashcards.State.isSelfCheckMode;
            const shouldGateOnAudio = gateOnAudio && !isPracticeMode;
            if (shouldGateOnAudio && root.FlashcardAudio && !root.FlashcardAudio.getTargetAudioHasPlayed()) {
                if (!isGenderMode) return;
                const genderMode = root.LLFlashcards && root.LLFlashcards.Modes && root.LLFlashcards.Modes.Gender;
                const rapidTapGuardActive = !!(genderMode && typeof genderMode.isAnswerTapGuardActive === 'function' && genderMode.isAnswerTapGuardActive());
                if (rapidTapGuardActive) return;
            }

            const hasGenderAttrs = (
                typeof $clicked.attr('data-ll-gender-correct') !== 'undefined' ||
                typeof $clicked.attr('data-ll-gender-choice') !== 'undefined' ||
                typeof $clicked.attr('data-ll-gender-unknown') !== 'undefined'
            );
            if (isGenderMode && hasGenderAttrs && root.LLFlashcards.Main && typeof root.LLFlashcards.Main.onGenderAnswer === 'function') {
                const selectedValue = String($clicked.attr('data-ll-gender-choice') || '');
                const isCorrectGender = String($clicked.attr('data-ll-gender-correct') || '0') === '1';
                const isDontKnow = String($clicked.attr('data-ll-gender-unknown') || '0') === '1';
                root.LLFlashcards.Main.onGenderAnswer(targetWord, $clicked, {
                    isCorrect: isCorrectGender,
                    isDontKnow: isDontKnow,
                    selectedValue: selectedValue,
                    selectedLabel: String($clicked.attr('data-word') || ''),
                    optionIndex: index
                });
                return;
            }

            const clickedId = String($clicked.data('wordId') || $clicked.attr('data-word-id') || '');
            const isCorrect = clickedId === String(targetWord.id);

            if (isCorrect) root.LLFlashcards.Main.onCorrectAnswer(targetWord, $(this));
            else root.LLFlashcards.Main.onWrongAnswer(targetWord, index, $(this));
        };

        $card.off('.llCardSelect')
            .on('pointerup.llCardSelect', function (e) {
                lastPointerHandledAt = Date.now();
                handleSelection.call(this, e, 'pointerup');
            })
            .on('click.llCardSelect', function (e) {
                handleSelection.call(this, e, 'click');
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
