(function (root, $) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
    const Util = namespace.Util || {};
    const protectMaqafNoBreak = (namespace.Util && typeof namespace.Util.protectMaqafNoBreak === 'function')
        ? namespace.Util.protectMaqafNoBreak
        : function (value) { return (value === null || value === undefined) ? '' : String(value); };

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
    const loadingSettings = (function () {
        const cfg = (root && root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const showDelayRaw = parseInt(cfg.roundLoadingShowDelayMs, 10);
        const minVisibleRaw = parseInt(cfg.roundLoadingMinVisibleMs, 10);
        const showDelayMs = Number.isFinite(showDelayRaw) ? Math.max(0, Math.min(800, showDelayRaw)) : 140;
        const minVisibleMs = Number.isFinite(minVisibleRaw) ? Math.max(0, Math.min(1200, minVisibleRaw)) : 260;
        return {
            showDelayMs: showDelayMs,
            minVisibleMs: minVisibleMs
        };
    })();

    let repeatAudio = null;
    let repeatAudioListeners = [];
    let repeatMiniViz = null;
    let loadingShowTimer = null;
    let loadingHideTimer = null;
    let loadingRequested = false;
    let loadingVisible = false;
    let loadingVisibleAt = 0;
    let soundGateRequired = false;
    let soundGateWatchedAudio = null;
    let soundGateWatchedListeners = [];
    let soundGateResumeAudio = null;

    function getMessages() {
        return (root && root.llToolsFlashcardsMessages && typeof root.llToolsFlashcardsMessages === 'object')
            ? root.llToolsFlashcardsMessages
            : {};
    }

    function getMessage(key, fallback) {
        return (Util && typeof Util.getMessage === 'function')
            ? Util.getMessage(key, fallback)
            : String(fallback || '').trim();
    }

    function isSoundGateAudioMuted(audio) {
        if (!audio) {
            return false;
        }
        try {
            if (audio.muted) {
                return true;
            }
        } catch (_) { /* no-op */ }
        try {
            if (typeof audio.volume === 'number' && audio.volume <= 0.01) {
                return true;
            }
        } catch (_) { /* no-op */ }
        return false;
    }

    function createSoundGateIcon() {
        return [
            '<span class="ll-tools-sound-gate-icon" aria-hidden="true">',
            '<svg viewBox="0 0 80 80" focusable="false" aria-hidden="true">',
            '<circle class="ll-tools-sound-gate-ring" cx="40" cy="40" r="31"></circle>',
            '<path class="ll-tools-sound-gate-speaker" d="M22 47h9l14 11V22L31 33h-9z"></path>',
            '<path class="ll-tools-sound-gate-wave wave-1" d="M50 33c4 3 6 7 6 11s-2 8-6 11"></path>',
            '<path class="ll-tools-sound-gate-wave wave-2" d="M56 27c6 5 9 11 9 17s-3 12-9 17"></path>',
            '<path class="ll-tools-sound-gate-slash" d="M21 59L59 21"></path>',
            '</svg>',
            '</span>'
        ].join('');
    }

    function setSoundGateState(active) {
        const enabled = !!active;
        if (State) {
            State.soundGateActive = enabled;
        }
        $('#ll-tools-flashcard-quiz-popup').toggleClass('ll-sound-gate-active', enabled);
        $('#ll-tools-flashcard-content').toggleClass('ll-sound-gate-active', enabled);
    }

    function getFallbackSoundGateAudio() {
        const audioApi = root.FlashcardAudio;
        if (!audioApi || typeof audioApi.getCurrentTargetAudio !== 'function') {
            return null;
        }
        return audioApi.getCurrentTargetAudio();
    }

    function getSoundGateAudioForResume() {
        return soundGateResumeAudio || soundGateWatchedAudio || getFallbackSoundGateAudio();
    }

    function syncSoundGateButtonLabel() {
        const messages = getMessages();
        const label = String(messages.soundRequiredContinue || messages.soundRequiredResume || 'Turn on sound to continue the quiz.');
        const buttonLabel = String(messages.soundRequiredResume || 'Resume audio');
        const $overlay = $('#ll-tools-autoplay-overlay');
        if (!$overlay.length) {
            return;
        }
        $overlay.find('.ll-tools-autoplay-button')
            .attr('aria-label', buttonLabel)
            .find('.screen-reader-text')
            .text(label);
        $overlay.find('.ll-tools-sound-gate-live').text(label);
    }

    function ensureSoundGateOverlay() {
        let $overlay = $('#ll-tools-autoplay-overlay');
        if ($overlay.length) {
            syncSoundGateButtonLabel();
            return $overlay;
        }

        const messages = getMessages();
        const label = String(messages.soundRequiredContinue || messages.soundRequiredResume || 'Turn on sound to continue the quiz.');
        const buttonLabel = String(messages.soundRequiredResume || 'Resume audio');

        $overlay = $('<div>', {
            id: 'll-tools-autoplay-overlay',
            class: 'll-tools-autoplay-overlay ll-tools-sound-gate-overlay',
            role: 'presentation'
        });

        const $live = $('<div>', {
            class: 'll-tools-sound-gate-live screen-reader-text',
            role: 'status',
            'aria-live': 'assertive',
            text: label
        });

        const $button = $('<button>', {
            type: 'button',
            class: 'll-tools-autoplay-button ll-tools-sound-gate-button',
            'aria-label': buttonLabel,
            html: createSoundGateIcon() + '<span class="screen-reader-text">' + label + '</span>'
        });

        const haltPropagation = function (event) {
            event.stopPropagation();
        };

        const resumeQuizAudio = function (event) {
            const audioApi = root.FlashcardAudio;
            const audio = getSoundGateAudioForResume();
            event.preventDefault();
            event.stopPropagation();

            if (audioApi && typeof audioApi.clearAutoplayBlock === 'function') {
                audioApi.clearAutoplayBlock();
            }

            if (!audio) {
                return;
            }

            try { audio.muted = false; } catch (_) { /* no-op */ }
            try {
                if (typeof audio.volume === 'number' && audio.volume <= 0.01) {
                    audio.volume = 1;
                }
            } catch (_) { /* no-op */ }
            try {
                if (typeof audio.currentTime === 'number' && audio.currentTime > 0.01) {
                    audio.currentTime = 0;
                }
            } catch (_) { /* no-op */ }

            const playRequest = (audioApi && typeof audioApi.playAudio === 'function')
                ? audioApi.playAudio(audio)
                : audio.play();

            Promise.resolve(playRequest)
                .then(function () {
                    if (!isSoundGateAudioMuted(audio)) {
                        Dom.hideAutoplayBlockedOverlay();
                    }
                })
                .catch(function () {
                    setSoundGateState(true);
                });
        };

        $overlay.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);
        $button.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);
        $button.on('click.llAutoOverlay', resumeQuizAudio);

        $overlay.append($live, $button);
        $('#ll-tools-flashcard-content').prepend($overlay);
        return $overlay;
    }

    function setSoundGateOverlayVisible(visible) {
        const enabled = !!visible;
        const $overlay = enabled ? ensureSoundGateOverlay() : $('#ll-tools-autoplay-overlay');
        setSoundGateState(enabled);
        if (!$overlay.length) {
            return;
        }
        if (enabled) {
            syncSoundGateButtonLabel();
            $('#ll-tools-flashcard').css('pointer-events', 'none');
            $overlay.stop(true, true).fadeIn(180);
            return;
        }
        $overlay.stop(true, true).fadeOut(180, function () {
            $(this).remove();
        });
        $('#ll-tools-flashcard').css('pointer-events', 'auto');
    }

    function clearSoundGateWatch() {
        if (soundGateWatchedAudio && soundGateWatchedListeners.length) {
            soundGateWatchedListeners.forEach(function (item) {
                try { soundGateWatchedAudio.removeEventListener(item.type, item.handler); } catch (_) { /* no-op */ }
            });
        }
        soundGateWatchedAudio = null;
        soundGateWatchedListeners = [];
    }

    function watchSoundGateAudio(audio) {
        clearSoundGateWatch();
        if (!soundGateRequired || !audio) {
            return;
        }

        soundGateWatchedAudio = audio;
        soundGateResumeAudio = audio;

        const sync = function () {
            if (!soundGateRequired || !State || !State.widgetActive) {
                return;
            }
            if (isSoundGateAudioMuted(audio)) {
                try {
                    if (typeof audio.pause === 'function' && !audio.paused) {
                        audio.pause();
                    }
                } catch (_) { /* no-op */ }
                try {
                    if (typeof audio.currentTime === 'number' && audio.currentTime > 0.01) {
                        audio.currentTime = 0;
                    }
                } catch (_) { /* no-op */ }
                setSoundGateOverlayVisible(true);
                return;
            }

            const alreadyStarted = !!(
                (!audio.paused && !audio.ended) ||
                ((typeof audio.currentTime === 'number') && audio.currentTime > 0.02)
            );
            if (alreadyStarted) {
                setSoundGateOverlayVisible(false);
            }
        };

        soundGateWatchedListeners = [
            { type: 'volumechange', handler: sync },
            { type: 'play', handler: sync },
            { type: 'playing', handler: sync },
            { type: 'loadeddata', handler: sync },
            { type: 'canplay', handler: sync }
        ];

        soundGateWatchedListeners.forEach(function (item) {
            try { audio.addEventListener(item.type, item.handler, { passive: true }); }
            catch (_) { audio.addEventListener(item.type, item.handler); }
        });

        sync();
    }

    function clearLoadingShowTimer() {
        if (!loadingShowTimer) return;
        clearTimeout(loadingShowTimer);
        loadingShowTimer = null;
    }

    function clearLoadingHideTimer() {
        if (!loadingHideTimer) return;
        clearTimeout(loadingHideTimer);
        loadingHideTimer = null;
    }

    function shouldShowLoadingImmediately() {
        const states = (State && State.STATES) ? State.STATES : {};
        const current = (State && typeof State.getState === 'function') ? State.getState() : '';
        if (State && State.isFirstRound) {
            return true;
        }
        if (State && State.isGenderMode) {
            return true;
        }
        if (current && (current === states.LOADING || current === states.SWITCHING_MODE)) {
            return true;
        }
        return false;
    }

    function isQuizPopupVisibleForLoading() {
        const $popup = $('#ll-tools-flashcard-quiz-popup');
        return !!($popup.length && $popup.is(':visible'));
    }

    function shouldHideCategoryDisplay() {
        const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
        const override = String(flashData.categoryDisplayOverride || flashData.category_display_override || '').trim();
        if (override) {
            return false;
        }
        return !!(flashData.hideCategoryDisplay || flashData.hide_category_display);
    }

    function applyLoadingVisibility(visible, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const instant = !!opts.instant;
        const $popup = $('#ll-tools-flashcard-quiz-popup');
        const $body = $('body');
        const $content = $('#ll-tools-flashcard-content');
        const $listeningViz = $('#ll-tools-listening-visualizer');
        const $el = $('#ll-tools-loading-animation');

        // Keep loader at document root so it cannot be trapped under popup blur layers.
        if ($body.length && $el.length && $el.parent()[0] !== $body[0]) {
            $el.appendTo($body);
        }

        if (visible) {
            if ($el.length) {
                // This loader is the generic round-transition indicator.
                // Ensure leftover visualizer classes never leak into it.
                $el.removeClass(
                    'll-tools-loading-animation--visualizer ll-tools-loading-animation--active ll-tools-loading-animation--js ll-tools-loading-animation--fallback ll-tools-loading-animation--paused'
                );
                $el.find('.ll-tools-visualizer-bar').remove();
            }
            $content.removeClass('ll-round-loading');
            if ($popup.length) {
                if (instant) {
                    $popup.addClass('ll-round-loading-instant');
                } else {
                    $popup.removeClass('ll-round-loading-instant');
                }
                $popup.addClass('ll-round-loading-active').attr('aria-busy', 'true');
                if (instant) {
                    const clearInstant = function () {
                        try { $popup.removeClass('ll-round-loading-instant'); } catch (_) { /* no-op */ }
                    };
                    if (typeof root.requestAnimationFrame === 'function') {
                        root.requestAnimationFrame(clearInstant);
                    } else {
                        setTimeout(clearInstant, 0);
                    }
                }
            }
            if ($listeningViz.length) {
                // Keep listening visualizer in layout but hidden while loading
                // so countdown/audio rendering can restore it without reflow.
                $listeningViz.removeClass('countdown-active').css('visibility', 'hidden');
            }
            $el.css('display', 'block');
            return;
        }

        $popup.removeClass('ll-round-loading-active ll-round-loading-instant').removeAttr('aria-busy');
        $content.removeClass('ll-round-loading');
        $el.hide();
    }

    function waitForNextPaint() {
        return new Promise(function (resolve) {
            if (typeof root.requestAnimationFrame === 'function') {
                root.requestAnimationFrame(function () { resolve(); });
                return;
            }
            setTimeout(resolve, 16);
        });
    }

    function hideLoadingInternal(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const forceImmediate = !!opts.forceImmediate;
        const viz = namespace.AudioVisualizer;
        if (viz && typeof viz.stop === 'function') {
            viz.stop();
        }
        loadingRequested = false;
        clearLoadingShowTimer();

        return new Promise(function (resolve) {
            let settled = false;
            const settle = function () {
                if (settled) return;
                settled = true;
                waitForNextPaint().then(resolve);
            };

            const finishHide = function () {
                clearLoadingHideTimer();
                if (loadingRequested) {
                    settle();
                    return;
                }
                loadingVisible = false;
                loadingVisibleAt = 0;
                applyLoadingVisibility(false);
                settle();
            };

            if (!loadingVisible) {
                finishHide();
                return;
            }

            if (forceImmediate) {
                finishHide();
                return;
            }

            const elapsed = Math.max(0, Date.now() - (loadingVisibleAt || 0));
            const remaining = Math.max(0, loadingSettings.minVisibleMs - elapsed);
            clearLoadingHideTimer();
            if (remaining > 0) {
                loadingHideTimer = setTimeout(finishHide, remaining);
                return;
            }
            finishHide();
        });
    }

    function ensureRepeatButtonContent() {
        const $btn = $('#ll-tools-repeat-flashcard');
        if (!$btn.length) return null;
        if (!$btn.data('llRepeatUi')) {
            const $ui = $('<span>', { class: 'll-repeat-audio-ui' });
            const $iconWrap = $('<span>', { class: 'll-repeat-icon-wrap', 'aria-hidden': 'true' });
            $('<span>', { class: 'll-audio-play-icon', 'aria-hidden': 'true' })
                .append('<svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true"><path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/></svg>')
                .appendTo($iconWrap);
            const $viz = $('<div>', { class: 'll-audio-mini-visualizer', 'aria-hidden': 'true' });
            for (let i = 0; i < 6; i++) {
                $('<span>', { class: 'bar', 'data-bar': i + 1 }).appendTo($viz);
            }
            $ui.append($iconWrap, $viz);
            $btn.empty().append($ui);
            $btn.data('llRepeatUi', true);
        }
        return $btn;
    }

    function updateStackForRepeat(showBtn) {
        const $stack = $('#ll-tools-category-stack');
        if (!$stack.length) return;
        if (showBtn) {
            $stack.removeClass('ll-no-repeat-btn');
        } else {
            $stack.addClass('ll-no-repeat-btn');
        }
    }

    function shouldHideRepeatButton() {
        if (!State) {
            return false;
        }
        if (State.isListeningMode) {
            return true;
        }
        const promptType = State.currentPromptType || '';
        const hasPromptAudio = Util.promptTypeHasAudio
            ? Util.promptTypeHasAudio(promptType)
            : promptType === 'audio';
        return !hasPromptAudio;
    }

    function setRepeatButtonInternal(state) {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        const hideBtn = shouldHideRepeatButton();
        if (hideBtn) {
            $btn.hide();
            updateStackForRepeat(false);
            return;
        }
        updateStackForRepeat(true);
        const isPlaying = state === 'stop';
        $btn.toggleClass('play-mode', !isPlaying);
        $btn.toggleClass('stop-mode', isPlaying);
        $btn.toggleClass('ll-repeat-playing', isPlaying);
        $btn.find('.ll-audio-mini-visualizer').toggleClass('active', isPlaying);
        $btn.attr('aria-label', isPlaying ? getMessage('pauseAudio') : getMessage('playAudio'));
        $btn.show();
    }

    function disableRepeatButton() {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        const hideBtn = shouldHideRepeatButton();
        if (hideBtn) { $btn.hide(); updateStackForRepeat(false); return; }
        $btn.addClass('disabled').prop('disabled', true);
    }

    function enableRepeatButton() {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        const hideBtn = shouldHideRepeatButton();
        if (hideBtn) { $btn.hide(); updateStackForRepeat(false); return; }
        updateStackForRepeat(true);
        $btn.removeClass('disabled').prop('disabled', false).show();
    }

    function clearRepeatButtonBinding() {
        if (repeatAudio && repeatAudioListeners.length) {
            repeatAudioListeners.forEach(item => {
                try { repeatAudio.removeEventListener(item.type, item.handler); } catch (_) { /* no-op */ }
            });
        }
        repeatAudio = null;
        repeatAudioListeners = [];
        if (repeatMiniViz && typeof repeatMiniViz.stop === 'function') {
            repeatMiniViz.stop();
        }
        clearSoundGateWatch();
    }

    function bindRepeatButtonAudio(audio) {
        ensureRepeatButtonContent();
        clearRepeatButtonBinding();
        if (!audio) {
            setRepeatButtonInternal('play');
            return;
        }
        repeatAudio = audio;
        if (namespace.AudioVisualizer && typeof namespace.AudioVisualizer.createMiniVisualizer === 'function') {
            if (!repeatMiniViz) {
                repeatMiniViz = namespace.AudioVisualizer.createMiniVisualizer();
            }
            const $btn = $('#ll-tools-repeat-flashcard');
            const vizEl = $btn.length ? $btn.find('.ll-audio-mini-visualizer')[0] : null;
            if (vizEl) {
                repeatMiniViz.attach(audio, vizEl);
            }
        }
        const onPlay = function () { setRepeatButtonInternal('stop'); };
        const onStop = function () { setRepeatButtonInternal('play'); };
        repeatAudioListeners = [
            { type: 'play', handler: onPlay },
            { type: 'playing', handler: onPlay },
            { type: 'ended', handler: onStop },
            { type: 'pause', handler: onStop },
            { type: 'emptied', handler: onStop }
        ];
        repeatAudioListeners.forEach(item => {
            try { audio.addEventListener(item.type, item.handler, { passive: true }); }
            catch (_) { audio.addEventListener(item.type, item.handler); }
        });
        watchSoundGateAudio(audio);
        if (!audio.paused && !audio.ended) onPlay(); else onStop();
    }

    function setSelfCheckLayout(active) {
        const enabled = !!active;
        $('#ll-tools-flashcard-quiz-popup').toggleClass('ll-self-check-active', enabled);
        $('#ll-tools-flashcard-content').toggleClass('ll-self-check-active', enabled);
        $('#ll-tools-flashcard-header').toggleClass('ll-self-check-active', enabled);

        if (enabled) {
            $('#ll-tools-flashcard, #ll-tools-flashcard-content').removeClass('audio-line-layout audio-line-mode');
        }

        if (enabled) {
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-repeat-flashcard').hide();
        }
    }

    const Dom = {
        setRepeatButton: setRepeatButtonInternal,
        disableRepeatButton: disableRepeatButton,
        enableRepeatButton: enableRepeatButton,
        setSoundGateRequired(required) {
            soundGateRequired = !!required;
            if (State) {
                State.soundGateRequired = soundGateRequired;
            }
            if (!soundGateRequired) {
                soundGateResumeAudio = null;
                clearSoundGateWatch();
                setSoundGateOverlayVisible(false);
                return;
            }
            if (repeatAudio) {
                watchSoundGateAudio(repeatAudio);
            }
        },
        isSoundGateActive() {
            return !!(State && State.soundGateActive);
        },
        requestSoundGate(audio, options) {
            const opts = (options && typeof options === 'object') ? options : {};
            const audioType = audio && audio.__options && audio.__options.type
                ? String(audio.__options.type)
                : '';
            if (!soundGateRequired && !opts.force) {
                return false;
            }
            if (audio && (audio.__sessionId === -1 || audioType.indexOf('feedback') === 0)) {
                return false;
            }
            if (audio) {
                soundGateResumeAudio = audio;
            }
            setSoundGateOverlayVisible(true);
            return true;
        },
        restoreHeaderUI() {
            $('#ll-tools-flashcard-header').show();
            $('#ll-tools-learning-progress').hide();
            const selfCheckActive = !!(State && State.isSelfCheckMode);
            if (!selfCheckActive) {
                $('#ll-tools-category-stack').show();
                if (shouldHideCategoryDisplay()) {
                    $('#ll-tools-category-display').hide().text('');
                } else {
                    $('#ll-tools-category-display').show();
                }
                Dom.setRepeatButton('play');
            }
            setSelfCheckLayout(selfCheckActive);
        },
        updateCategoryNameDisplay(name) {
            const $el = $('#ll-tools-category-display');
            if (!$el.length) return;
            if (shouldHideCategoryDisplay()) {
                $el.text('').hide();
                return;
            }
            $el.show();
            const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
                ? root.llToolsFlashcardsData
                : {};
            const override = String(flashData.categoryDisplayOverride || flashData.category_display_override || '').trim();
            if (override) {
                $el.text(protectMaqafNoBreak(override));
                return;
            }
            if (!name) return;

            const displayName = (Util && typeof Util.getCategoryDisplayLabel === 'function')
                ? Util.getCategoryDisplayLabel(name, name)
                : name;

            $el.text(protectMaqafNoBreak(String(displayName)));
        },
        showLoading() {
            const viz = namespace.AudioVisualizer;
            if (viz && typeof viz.reset === 'function') viz.reset();

            loadingRequested = true;
            clearLoadingHideTimer();

            if (loadingVisible) {
                return;
            }

            if (loadingShowTimer) {
                return;
            }
            const startVisibleLoading = function (instant) {
                loadingShowTimer = null;
                if (!loadingRequested || loadingVisible) return;
                // Late async callbacks can request loading after the quiz popup closes.
                // Ignore those requests so the global loader cannot float on the page.
                if (!isQuizPopupVisibleForLoading()) {
                    loadingRequested = false;
                    loadingVisible = false;
                    loadingVisibleAt = 0;
                    applyLoadingVisibility(false);
                    return;
                }
                loadingVisible = true;
                loadingVisibleAt = Date.now();
                applyLoadingVisibility(true, { instant: !!instant });
            };

            const showImmediately = shouldShowLoadingImmediately();
            if (showImmediately || loadingSettings.showDelayMs <= 0) {
                startVisibleLoading(showImmediately);
                return;
            }

            loadingShowTimer = setTimeout(function () {
                startVisibleLoading(false);
            }, loadingSettings.showDelayMs);
        },
        hideLoading() {
            return hideLoadingInternal();
        },
        hideLoadingImmediately() {
            return hideLoadingInternal({ forceImmediate: true });
        },
        updateLearningProgress(introducedCount, totalCount, wordCorrectCounts, wordIntroductionProgress) {
            const $progress = $('#ll-tools-learning-progress');
            if (!$progress.length) return;

            // Calculate granular completion based on total correct answers (capped at 3 per word)
            const totalCorrectAnswers = Object.values(wordCorrectCounts).reduce((sum, count) => sum + Math.min(count, 3), 0);
            const maxPossibleAnswers = totalCount * 3;
            const completedPercentage = maxPossibleAnswers > 0 ? Math.round((totalCorrectAnswers / maxPossibleAnswers) * 100) : 0;

            // Calculate granular introduction progress (3 repetitions per word)
            const totalIntroductionRepetitions = Object.values(wordIntroductionProgress || {}).reduce((sum, count) => sum + Math.min(count, 3), 0);
            const maxPossibleIntroductions = totalCount * 3;
            const introducedPercentage = maxPossibleIntroductions > 0 ? Math.round((totalIntroductionRepetitions / maxPossibleIntroductions) * 100) : 0;

            $progress.html(`
                <div class="learning-progress-bar">
                    <div class="learning-progress-fill introduced-fill" style="width: ${introducedPercentage}%"></div>
                    <div class="learning-progress-fill completed-fill" style="width: ${completedPercentage}%"></div>
                </div>
            `).show();
        },
        updateSimpleProgress(currentCount, totalCount, options) {
            const $progress = $('#ll-tools-learning-progress');
            if (!$progress.length) return;
            const opts = (options && typeof options === 'object') ? options : {};

            const total = Math.max(0, parseInt(totalCount, 10) || 0);
            const current = Math.max(0, parseInt(currentCount, 10) || 0);
            const clampedCurrent = total > 0 ? Math.min(total, current) : 0;
            const rawPercent = total > 0 ? ((clampedCurrent / total) * 100) : 0;
            let displayPercent = Math.max(0, Math.min(100, rawPercent));
            const minDisplayRatio = parseFloat(opts.minDisplayRatio);
            if (Number.isFinite(minDisplayRatio)) {
                const minDisplayPercent = Math.max(0, Math.min(100, minDisplayRatio * 100));
                if (displayPercent < minDisplayPercent) {
                    displayPercent = minDisplayPercent;
                }
            }
            const widthPercent = Math.round(displayPercent * 100) / 100;
            const widthValue = String(widthPercent).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1') + '%';

            let $bar = $progress.find('.learning-progress-bar.simple-progress-bar');
            let $fill = $bar.find('.learning-progress-fill.simple-fill');

            if (!$bar.length || !$fill.length) {
                $progress.html(`
                    <div class="learning-progress-bar simple-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${total || 0}">
                        <div class="learning-progress-fill simple-fill" style="width: 0%"></div>
                    </div>
                `);
                $bar = $progress.find('.learning-progress-bar.simple-progress-bar');
                $fill = $bar.find('.learning-progress-fill.simple-fill');
            }

            $bar
                .attr('aria-valuemax', String(total || 0))
                .attr('aria-valuenow', String(clampedCurrent))
                .attr('aria-valuetext', total > 0 ? (String(clampedCurrent) + ' of ' + String(total)) : '0');

            $fill.css('width', widthValue);
            $progress.show();
        },
        showAutoplayBlockedOverlay(options) {
            const opts = (options && typeof options === 'object') ? options : {};
            const force = (typeof options === 'undefined') ? true : !!opts.force;
            if (!soundGateRequired && !force) {
                return false;
            }
            if (opts.audio) {
                soundGateResumeAudio = opts.audio;
            }
            setSoundGateOverlayVisible(true);
            return true;
        },
        hideAutoplayBlockedOverlay() {
            soundGateResumeAudio = null;
            $('#ll-tools-flashcard-content').off('.llAutoplayKick');
            setSoundGateOverlayVisible(false);
        },
        // Export icon generators for use in templates
        getPlayIconHTML() { return createPlayIcon(); },
        getStopIconHTML() { return createStopIcon(); },
        bindRepeatButtonAudio,
        clearRepeatButtonBinding,
        setSelfCheckLayout
    };

    // legacy alias some code expects:
    root.hideLoadingAnimation = Dom.hideLoading;

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Dom = Dom;
})(window, jQuery);
