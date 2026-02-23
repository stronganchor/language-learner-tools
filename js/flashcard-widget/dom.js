(function (root, $) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
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
        if (current && (current === states.LOADING || current === states.SWITCHING_MODE)) {
            return true;
        }
        return false;
    }

    function isQuizPopupVisibleForLoading() {
        const $popup = $('#ll-tools-flashcard-quiz-popup');
        return !!($popup.length && $popup.is(':visible'));
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
                    'll-tools-loading-animation--visualizer ll-tools-loading-animation--active ll-tools-loading-animation--js ll-tools-loading-animation--fallback'
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

    function setRepeatButtonInternal(state) {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        // In listening mode, hide the header repeat button entirely
        const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
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
        $btn.attr('aria-label', isPlaying ? 'Pause audio' : 'Play audio');
        $btn.show();
    }

    function disableRepeatButton() {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
        if (hideBtn) { $btn.hide(); updateStackForRepeat(false); return; }
        $btn.addClass('disabled').prop('disabled', true);
    }

    function enableRepeatButton() {
        const $btn = ensureRepeatButtonContent();
        if (!$btn || !$btn.length) return;
        const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
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
        restoreHeaderUI() {
            $('#ll-tools-flashcard-header').show();
            $('#ll-tools-learning-progress').hide();
            const selfCheckActive = !!(State && State.isSelfCheckMode);
            if (!selfCheckActive) {
                $('#ll-tools-category-stack, #ll-tools-category-display').show();
                Dom.setRepeatButton('play');
            }
            setSelfCheckLayout(selfCheckActive);
        },
        updateCategoryNameDisplay(name) {
            const $el = $('#ll-tools-category-display');
            if (!$el.length) return;
            const flashData = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
                ? root.llToolsFlashcardsData
                : {};
            const override = String(flashData.categoryDisplayOverride || flashData.category_display_override || '').trim();
            if (override) {
                $el.text(protectMaqafNoBreak(override));
                return;
            }
            if (!name) return;

            // Look up translation if available
            let displayName = name;
            if (flashData.categories) {
                const category = flashData.categories.find(c => c.name === name);
                if (category && category.translation) {
                    displayName = category.translation;
                }
            }

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
        updateSimpleProgress(currentCount, totalCount) {
            const $progress = $('#ll-tools-learning-progress');
            if (!$progress.length) return;

            const total = Math.max(0, parseInt(totalCount, 10) || 0);
            const current = Math.max(0, parseInt(currentCount, 10) || 0);
            const clampedCurrent = total > 0 ? Math.min(total, current) : 0;
            const percent = total > 0 ? Math.round((clampedCurrent / total) * 100) : 0;

            $progress.html(`
                <div class="learning-progress-bar simple-progress-bar">
                    <div class="learning-progress-fill simple-fill" style="width: ${percent}%"></div>
                </div>
            `).show();
        },
        showAutoplayBlockedOverlay() {
            // Check if overlay already exists
            let $overlay = $('#ll-tools-autoplay-overlay');
            if ($overlay.length) {
                $overlay.show();
                return;
            }

            // Create overlay
            $overlay = $('<div>', {
                id: 'll-tools-autoplay-overlay',
                class: 'll-tools-autoplay-overlay'
            });

            const $button = $('<button>', {
                class: 'll-tools-autoplay-button',
                'aria-label': 'Click to start quiz',
                html: `
                    <svg width="80" height="80" viewBox="0 0 80 80" fill="white">
                        <circle cx="40" cy="40" r="35" stroke="white" stroke-width="3" fill="rgba(255,255,255,0.1)"/>
                        <path d="M30 20 L30 60 L60 40 Z" fill="white"/>
                    </svg>
                `
            });

            const haltPropagation = function (event) {
                event.stopPropagation();
            };

            $overlay.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);
            $button.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);

            $button.on('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                // Clear the autoplay block flag
                if (root.FlashcardAudio && root.FlashcardAudio.clearAutoplayBlock) {
                    root.FlashcardAudio.clearAutoplayBlock();
                }

                // Hide overlay
                $overlay.fadeOut(300, function () {
                    $(this).remove();
                });
                $overlay.off('.llAutoOverlay');

                // Try to play the audio again only if the target audio hasn't succeeded yet
                const audioApi = root.FlashcardAudio;
                const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
                    ? audioApi.getCurrentTargetAudio()
                    : null;
                const alreadyPlayed = audioApi && typeof audioApi.getTargetAudioHasPlayed === 'function'
                    ? audioApi.getTargetAudioHasPlayed()
                    : false;
                if (audio && !alreadyPlayed) {
                    const playPromise = audio.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(e => console.error('Still cannot play:', e));
                    }
                }

                // Enable interactions
                $('#ll-tools-flashcard').css('pointer-events', 'auto');
                $('#ll-tools-flashcard-content').off('.llAutoplayKick');
            });

            $overlay.append($button);
            $('#ll-tools-flashcard-content').prepend($overlay);
            $overlay.fadeIn(300);
        },
        hideAutoplayBlockedOverlay() {
            const $overlay = $('#ll-tools-autoplay-overlay');
            $overlay.fadeOut(300, function () {
                $(this).remove();
            });
            $overlay.off('.llAutoOverlay');
            $('#ll-tools-flashcard-content').off('.llAutoplayKick');
            $('#ll-tools-flashcard').css('pointer-events', 'auto');
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
