(function (root, $) {
    'use strict';
    const Util = root.LLFlashcards.Util || {};
    const { State } = root.LLFlashcards;
    const { Dom } = root.LLFlashcards;
    let optionMiniViz = null;
    let textCardResizeBound = false;
    let textCardResizeTimer = null;

    function sanitizeFontFamily(value) {
        let fontFamily = String(value || '').trim();
        fontFamily = fontFamily.replace(/[\r\n{};]/g, ' ').trim();
        if (fontFamily.length > 160) {
            fontFamily = fontFamily.slice(0, 160).trim();
        }
        return fontFamily;
    }

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

    function getMessage(key, fallback) {
        return (Util && typeof Util.getMessage === 'function')
            ? Util.getMessage(key, fallback)
            : String(fallback || '').trim();
    }

    function getAnswerOptionTextStyleConfig() {
        const raw = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object' && root.llToolsFlashcardsData.answerOptionTextStyle && typeof root.llToolsFlashcardsData.answerOptionTextStyle === 'object')
            ? root.llToolsFlashcardsData.answerOptionTextStyle
            : ((root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object' && root.llToolsFlashcardsData.answer_option_text_style && typeof root.llToolsFlashcardsData.answer_option_text_style === 'object')
                ? root.llToolsFlashcardsData.answer_option_text_style
                : {});

        let fontFamily = sanitizeFontFamily(raw.fontFamily || '');
        if (!fontFamily && root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object') {
            fontFamily = sanitizeFontFamily(root.llToolsFlashcardsData.quizFont || root.llToolsFlashcardsData.quiz_font || '');
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
            minFontSizePx: clampInt(raw.minFontSizePx, 10, 24, 10),
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
        const containers = [
            document.getElementById('ll-tools-flashcard-container'),
            document.getElementById('ll-tools-flashcard-popup'),
            document.getElementById('ll-tools-flashcard-quiz-popup'),
            document.getElementById('ll-tools-flashcard-content')
        ].filter(function (el, index, all) {
            return !!el && !!el.style && all.indexOf(el) === index;
        });
        if (!containers.length) {
            return;
        }

        containers.forEach(function (container) {
            if (cfg.fontFamily) {
                container.style.setProperty('--ll-answer-option-font-family', cfg.fontFamily);
            } else {
                container.style.removeProperty('--ll-answer-option-font-family');
            }
            container.style.setProperty('--ll-answer-option-font-weight', cfg.fontWeight);
            container.style.setProperty('--ll-answer-option-font-size-px', cfg.fontSizePx + 'px');
            container.style.setProperty('--ll-answer-option-text-line-height', String(cfg.lineHeightRatio));
            container.style.setProperty('--ll-answer-option-text-line-height-marked', String(cfg.lineHeightRatioWithDiacritics));
        });
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

    function applyTextCardLabelSize($label, fontSizePx, lineHeightRatio, noWrap) {
        if (!$label || !$label.length) {
            return;
        }
        const lineHeightPx = Math.round(fontSizePx * lineHeightRatio * 100) / 100;
        $label.css({
            fontSize: fontSizePx + 'px',
            lineHeight: lineHeightPx + 'px',
            visibility: 'visible',
            position: 'relative',
            whiteSpace: noWrap ? 'nowrap' : 'normal'
        });
    }

    function textCardLabelCanWrap(labelText) {
        return /\s/u.test(String(labelText || '').trim());
    }

    function measureTextCardLabel(labelEl, labelText, fontSizePx, lineHeightRatio, noWrap, availableWidth) {
        if (!labelEl || !document || !document.body) {
            return { width: 0, height: 0 };
        }

        const computed = root.getComputedStyle ? root.getComputedStyle(labelEl) : null;
        const lineHeightPx = Math.round(fontSizePx * lineHeightRatio * 100) / 100;
        const probe = document.createElement('div');
        probe.textContent = String(labelText || '');

        const dir = labelEl.getAttribute ? String(labelEl.getAttribute('dir') || '') : '';
        if (dir) {
            probe.setAttribute('dir', dir);
        }

        probe.style.position = 'absolute';
        probe.style.left = '-9999px';
        probe.style.top = '-9999px';
        probe.style.visibility = 'hidden';
        probe.style.pointerEvents = 'none';
        probe.style.boxSizing = 'border-box';
        probe.style.width = Math.max(1, availableWidth) + 'px';
        probe.style.maxWidth = Math.max(1, availableWidth) + 'px';
        probe.style.margin = '0';
        probe.style.padding = '0';
        probe.style.border = '0';
        probe.style.display = 'block';
        probe.style.whiteSpace = noWrap ? 'nowrap' : 'normal';
        probe.style.overflowWrap = 'normal';
        probe.style.wordBreak = 'normal';
        probe.style.hyphens = 'none';
        probe.style.fontSize = fontSizePx + 'px';
        probe.style.lineHeight = lineHeightPx + 'px';

        if (computed) {
            probe.style.fontFamily = computed.fontFamily || '';
            probe.style.fontWeight = computed.fontWeight || '';
            probe.style.fontStyle = computed.fontStyle || '';
            probe.style.fontVariant = computed.fontVariant || '';
            probe.style.fontStretch = computed.fontStretch || '';
            probe.style.letterSpacing = computed.letterSpacing || '';
            probe.style.textAlign = computed.textAlign || '';
            probe.style.direction = computed.direction || '';
        }

        document.body.appendChild(probe);

        const measured = {
            width: Math.max(0, Math.ceil(probe.scrollWidth || probe.getBoundingClientRect().width || 0)),
            height: Math.max(0, Math.ceil(probe.scrollHeight || probe.getBoundingClientRect().height || 0))
        };

        probe.remove();
        return measured;
    }

    function textCardLabelFits(labelEl, labelText, fontSizePx, lineHeightRatio, noWrap, availableWidth, availableHeight) {
        const measured = measureTextCardLabel(labelEl, labelText, fontSizePx, lineHeightRatio, noWrap, availableWidth);
        return measured.width <= Math.ceil(availableWidth) + 1 && measured.height <= Math.ceil(availableHeight) + 1;
    }

    function fitTextCardLabel($label, labelText, cfg, maxHeight) {
        if (!$label || !$label.length) {
            return false;
        }

        const labelEl = $label[0];
        const computed = root.getComputedStyle ? root.getComputedStyle(labelEl) : null;
        const paddingX = computed
            ? (parseFloat(computed.paddingLeft || '0') + parseFloat(computed.paddingRight || '0'))
            : 0;
        const paddingY = computed
            ? (parseFloat(computed.paddingTop || '0') + parseFloat(computed.paddingBottom || '0'))
            : 0;
        const availableWidth = Math.max(0, Math.floor((labelEl.clientWidth || 0) - paddingX));
        const availableHeight = Math.max(0, Math.floor(Math.min(labelEl.clientHeight || 0, maxHeight || 0) - paddingY));
        if (availableWidth < 40 || availableHeight < 16) {
            return false;
        }

        const lineHeightRatio = getAnswerOptionLineHeightRatio(labelText);
        const minFontSize = clampInt(cfg.minFontSizePx, 10, 24, 10);
        const startFontSize = Math.max(minFontSize, clampInt(cfg.fontSizePx, minFontSize, 72, 48));
        const noWrap = !textCardLabelCanWrap(labelText);

        if (textCardLabelFits(labelEl, labelText, startFontSize, lineHeightRatio, noWrap, availableWidth, availableHeight)) {
            applyTextCardLabelSize($label, startFontSize, lineHeightRatio, noWrap);
            return true;
        }

        let low = Math.round(minFontSize * 2);
        let high = Math.round(startFontSize * 2);
        let bestFontSize = minFontSize;

        while (low <= high) {
            const mid = Math.floor((low + high) / 2);
            const fontSizePx = mid / 2;
            if (textCardLabelFits(labelEl, labelText, fontSizePx, lineHeightRatio, noWrap, availableWidth, availableHeight)) {
                bestFontSize = fontSizePx;
                low = mid + 1;
            } else {
                high = mid - 1;
            }
        }

        // Keep whole words intact: single long words stay on one line, phrases can wrap.
        applyTextCardLabelSize($label, bestFontSize, lineHeightRatio, noWrap);
        return true;
    }

    function refitTextCard($card) {
        if (!$card || !$card.length || !$card.hasClass('ll-answer-option-text-card')) {
            return false;
        }
        const $label = $card.find('.quiz-text').first();
        if (!$label.length) {
            return false;
        }
        const cardWidth = Math.max(0, Math.floor($card.innerWidth() || 0));
        const labelText = String($label.text() || '');
        const cardHeight = Math.max(0, $card.innerHeight() - 15);
        if (cardWidth < 60 || cardHeight < 40) {
            return false;
        }
        applyAnswerOptionTextStyle($label, labelText);
        return fitTextCardLabel($label, labelText, getAnswerOptionTextStyleConfig(), cardHeight);
    }

    function refitTextAnswerOptionCards() {
        $('.flashcard-container.ll-answer-option-text-card').each(function () {
            refitTextCard($(this));
        });
    }

    function ensureTextCardResizeBinding() {
        if (textCardResizeBound || !root || typeof root.addEventListener !== 'function') {
            return;
        }
        root.addEventListener('resize', function () {
            if (textCardResizeTimer) {
                clearTimeout(textCardResizeTimer);
            }
            textCardResizeTimer = setTimeout(function () {
                textCardResizeTimer = null;
                refitTextAnswerOptionCards();
            }, 140);
        });
        textCardResizeBound = true;
    }

    function prepareTextAnswerOptionCardsForReveal() {
        const waitForFonts = function () {
            return new Promise(function (resolve) {
                if (!document || !document.fonts || !document.fonts.ready || typeof document.fonts.ready.then !== 'function') {
                    resolve();
                    return;
                }
                let settled = false;
                const finish = function () {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearTimeout(timerId);
                    resolve();
                };
                const timerId = setTimeout(finish, 120);
                document.fonts.ready.then(finish).catch(finish);
            });
        };
        const waitForLayout = function () {
            return new Promise(function (resolve) {
                if (typeof root.requestAnimationFrame === 'function') {
                    root.requestAnimationFrame(function () {
                        root.requestAnimationFrame(resolve);
                    });
                    return;
                }
                setTimeout(resolve, 0);
            });
        };

        return waitForFonts()
            .then(waitForLayout)
            .then(function () {
                refitTextAnswerOptionCards();
            });
    }

    function getImageCardCaptionText(word, optionType) {
        if (Util && typeof Util.getImageOptionCaption === 'function') {
            return Util.getImageOptionCaption(word, optionType);
        }
        if (String(optionType || '').trim().toLowerCase() !== 'image_text_translation') {
            return '';
        }
        return String((word && word.translation) || '').trim();
    }

    function createImageCard(word, optionType) {
        const hasCaptionMode = String(optionType || '').trim().toLowerCase() === 'image_text_translation';
        const captionText = hasCaptionMode ? getImageCardCaptionText(word, optionType) : '';
        const $c = $('<div>', {
            class: 'flashcard-container flashcard-size-' + root.llToolsFlashcardsData.imageSize + (hasCaptionMode ? ' ll-answer-option-image-caption-card' : ''),
            'data-word': word.title,
            'data-word-id': word.id,
            css: { display: 'none' }
        });

        const $imageHost = hasCaptionMode
            ? $('<div>', { class: 'll-answer-option-image-caption-media' }).appendTo($c)
            : $c;

        $('<img>', { src: word.image, alt: '', 'aria-hidden': 'true', class: 'quiz-image', draggable: false })
            .on('load', function () {
                const fudge = 10;
                if (this.naturalWidth > this.naturalHeight + fudge) $c.addClass('landscape');
                else if (this.naturalWidth + fudge < this.naturalHeight) $c.addClass('portrait');
            })
            .appendTo($imageHost);

        if (hasCaptionMode) {
            const $caption = $('<div>', {
                class: 'quiz-text ll-answer-option-image-caption',
                dir: 'auto'
            }).appendTo($c);
            if (captionText) {
                $caption.text(captionText);
                $c.addClass('ll-answer-option-image-caption-card--has-caption');
                applyAnswerOptionTextStyle($caption, captionText);
            } else {
                $caption.attr('aria-hidden', 'true');
            }
        }

        return $c;
    }

    function resolveCardLabelText(word, optionType, promptType) {
        const resolvedLabel = String((word && word.__resolvedOptionLabel) || '').trim();
        if (resolvedLabel) {
            return resolvedLabel;
        }
        if (Util && typeof Util.getEffectiveOptionLabel === 'function') {
            return Util.getEffectiveOptionLabel(word, optionType, promptType);
        }
        return word.label || word.title || '';
    }

    function createTextCard(word, optionType, promptType) {
        const sizeClass = 'flashcard-size-' + root.llToolsFlashcardsData.imageSize;
        const $c = $('<div>', { class: `flashcard-container text-based ll-answer-option-text-card ${sizeClass}`, 'data-word': word.title, 'data-word-id': word.id });
        const labelText = resolveCardLabelText(word, optionType, promptType);
        const $label = $('<div>', { text: labelText, class: 'quiz-text', dir: 'auto' }).appendTo($c);
        applyAnswerOptionTextStyle($label, labelText);

        $c.css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'flex' }).appendTo('body');
        const boxH = Math.max(0, $c.innerHeight() - 15);
        const cfg = getAnswerOptionTextStyleConfig();
        fitTextCardLabel($label, labelText, cfg, boxH);
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
        const isImagePrompt = Util.promptTypeHasImage ? Util.promptTypeHasImage(promptType) : (promptType === 'image');
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
            'aria-label': getMessage('playOptionAudio')
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
            const labelText = resolveCardLabelText(word, includeText ? 'text_audio' : 'audio', promptType);
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
    }

    function appendWordToContainer(word, optionType, promptType, ordered) {
        const mode = optionType || root.LLFlashcards.Selection.getCurrentDisplayMode();
        const isTextMode = (mode === 'text' || mode === 'text_title' || mode === 'text_translation');
        const $card = ((mode === 'image' || mode === 'image_text_translation'))
            ? createImageCard(word, mode)
            : (mode === 'audio'
                ? createAudioCard(word, false, promptType)
                : (mode === 'text_audio'
                    ? createAudioCard(word, true, promptType)
                    : (isTextMode ? createTextCard(word, mode, promptType) : createTextCard(word, mode, promptType))));
        if (ordered) {
            $('#ll-tools-flashcard').append($card);
        } else {
            insertContainerAtRandom($card);
        }
        return $card;
    }

    ensureTextCardResizeBinding();

    function addClickEventToCard($card, index, targetWord, optionType, promptType) {
        const gateOnAudio = Util.promptTypeHasAudio ? Util.promptTypeHasAudio(promptType) : (promptType === 'audio');
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
            if (root.LLFlashcards && root.LLFlashcards.State && root.LLFlashcards.State.soundGateActive) {
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
    applyAnswerOptionContainerCssVars();

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Cards = {
        appendWordToContainer,
        addClickEventToCard,
        playOptionAudio,
        refitTextAnswerOptionCards,
        prepareTextAnswerOptionCardsForReveal,
        applyAnswerOptionContainerCssVars,
        applyAnswerOptionTextStyle
    };
})(window, jQuery);
