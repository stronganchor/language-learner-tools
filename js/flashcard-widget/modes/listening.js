(function (root) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
    const Dom = namespace.Dom || {};
    const Cards = namespace.Cards || {};
    const Results = namespace.Results || {};
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

    function getJQuery() {
        if (root.jQuery) return root.jQuery;
        if (typeof window !== 'undefined' && window.jQuery) return window.jQuery;
        return null;
    }

    function scheduleTimeout(context, fn, delay) {
        if (context && typeof context.setGuardedTimeout === 'function') {
            return context.setGuardedTimeout(fn, delay);
        }
        return setTimeout(fn, delay);
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = true;
        State.listeningPaused = false;
        State.listeningLoop = State.listeningLoop === true; // preserve if toggled previously during session
        // Build a linear list of words across the selected categories in current order
        const all = [];
        if (State.categoryNames && State.wordsByCategory) {
            for (const name of State.categoryNames) {
                const list = State.wordsByCategory[name] || [];
                for (const w of list) all.push(w);
            }
        }
        State.wordsLinear = all;
        State.listenIndex = 0;
        return true;
    }

    function getChoiceCount() {
        // No choices in listening mode
        return 0;
    }

    function recordAnswerResult() {
        // No scoring in passive listening (for now)
        return {};
    }

    function selectTargetWord() {
        // Cycle deterministically for now
        if (!Array.isArray(State.wordsLinear)) {
            const all = [];
            if (State.categoryNames && State.wordsByCategory) {
                for (const name of State.categoryNames) {
                    const list = State.wordsByCategory[name] || [];
                    for (const w of list) all.push(w);
                }
            }
            State.wordsLinear = all;
            State.listenIndex = 0;
        }
        if (!State.wordsLinear.length) return null;
        const word = State.wordsLinear[State.listenIndex % State.wordsLinear.length];
        State.listenIndex++;
        return word;
    }

    function onFirstRoundStart() {
        initialize();
        return true;
    }

    function onCorrectAnswer() { return true; }
    function onWrongAnswer() { return true; }

    function toggleDisabled($jq, $btn, disabled) {
        if (!$jq || !$btn || !$btn.length) return;
        if (disabled) $btn.addClass('disabled').attr('aria-disabled', 'true');
        else $btn.removeClass('disabled').removeAttr('aria-disabled');
    }

    function updateControlsState() {
        const $jq = getJQuery();
        if (!$jq) return;
        const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
        let cur = Math.max(0, Math.min((State.listenIndex || 0) - 1, Math.max(0, total - 1)));
        const canBack = cur > 0; // never go before first
        const canFwd = !!State.listeningLoop || (cur < total - 1);
        toggleDisabled($jq, $jq('#ll-listen-back'), !canBack);
        toggleDisabled($jq, $jq('#ll-listen-forward'), !canFwd);
    }

    function ensureControls(utils) {
        const $jq = getJQuery();
        const $content = $jq ? $jq('#ll-tools-flashcard-content') : null;
        if (!$content || !$content.length) return;

        let $controls = $jq('#ll-tools-listening-controls');
        if (!$controls.length) {
            $controls = $jq('<div>', { id: 'll-tools-listening-controls', class: 'll-listening-controls' });

            const makeBtn = (id, label, svg) => $jq('<button>', {
                id,
                class: 'll-listen-btn',
                'aria-label': label,
                html: svg
            });

            const ICON_SIZE = 28;
            const color = '#333';

            const playSVG = `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 32 32" fill="${color}"><path d="M10 6v20l16-10z"/></svg>`;
            const pauseSVG = `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 32 32" fill="${color}"><rect x="8" y="6" width="6" height="20" rx="2"/><rect x="18" y="6" width="6" height="20" rx="2"/></svg>`;
            const backSVG = `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 32 32" fill="${color}"><path d="M18 8v16l-10-8 10-8z"/><rect x="22" y="6" width="2" height="20"/></svg>`;
            const fwdSVG = `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 32 32" fill="${color}"><path d="M14 8v16l10-8-10-8z"/><rect x="8" y="6" width="2" height="20"/></svg>`;
            const loopSVG = `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>`;

            const $pause = makeBtn('ll-listen-toggle', 'Pause / Play', pauseSVG).attr('data-state', 'playing');
            const $back = makeBtn('ll-listen-back', 'Back', backSVG);
            const $fwd = makeBtn('ll-listen-forward', 'Forward', fwdSVG);
            const $loop = makeBtn('ll-listen-loop', 'Loop', loopSVG);

            if (State.listeningLoop) $loop.addClass('active');

            $controls.append($pause, $back, $fwd, $loop);
            $content.append($controls);

            // Events
            $pause.on('click', function () {
                const audioApi = utils.FlashcardAudio || {};
                const audio = (typeof audioApi.getCurrentTargetAudio === 'function') ? audioApi.getCurrentTargetAudio() : null;
                const isPlaying = $jq(this).attr('data-state') === 'playing';
                State.listeningPaused = isPlaying;
                if (isPlaying) {
                    // Pause
                    try { audio && audio.pause && audio.pause(); } catch (_) {}
                    State.clearActiveTimeouts();
                    $jq(this).attr('data-state', 'paused').html(playSVG);
                } else {
                    // Resume
                    $jq(this).attr('data-state', 'playing').html(pauseSVG);
                    if (audio && audio.paused && !audio.ended) {
                        const p = audio.play();
                        if (p && typeof p.catch === 'function') p.catch(() => {});
                    } else {
                        // If no audio to resume (or already ended), reveal or advance
                        const $overlay = $jq('#ll-tools-flashcard .listening-overlay');
                        if ($overlay.length) {
                            $overlay.fadeOut(200, function () { $jq(this).remove(); });
                            $jq('#ll-tools-listening-visualizer').fadeOut(150);
                        }
                        State.forceTransitionTo(STATES.QUIZ_READY, 'Listening resume advance');
                        if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                    }
                }
                updateControlsState();
            });

            $back.on('click', function () {
                const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
                if (!total) return;
                // If already at first, do nothing
                if ((State.listenIndex || 0) <= 1) { updateControlsState(); return; }
                try { utils.FlashcardAudio && utils.FlashcardAudio.pauseAllAudio(); } catch (_) {}
                State.clearActiveTimeouts();
                // Move back one item relative to current selection
                State.listenIndex = (State.listenIndex - 2 + total) % total;
                State.forceTransitionTo(STATES.QUIZ_READY, 'Listening back');
                if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                updateControlsState();
            });

            $fwd.on('click', function () {
                try { utils.FlashcardAudio && utils.FlashcardAudio.pauseAllAudio(); } catch (_) {}
                State.clearActiveTimeouts();
                // If at the last word and loop is off, do nothing
                const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
                const cur = Math.max(0, Math.min((State.listenIndex || 0) - 1, Math.max(0, total - 1)));
                if (!State.listeningLoop && cur >= total - 1) { updateControlsState(); return; }
                // Current listenIndex already points at next; just move on
                State.forceTransitionTo(STATES.QUIZ_READY, 'Listening forward');
                if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                updateControlsState();
            });

            $loop.on('click', function () {
                State.listeningLoop = !State.listeningLoop;
                $jq(this).toggleClass('active', State.listeningLoop);
                updateControlsState();
            });
            updateControlsState();
        } else {
            $controls.show();
            updateControlsState();
        }
    }

    function insertPlaceholder($container) {
        const $jq = getJQuery();
        if (!$jq) return null;
        if (!$container || typeof $container.append !== 'function') return null;
        const $ph = $jq('<div>', {
            class: 'flashcard-container flashcard-size-large listening-placeholder',
            css: { display: 'flex' }
        });
        // Visualizer placeholder inside box
        const $viz = $jq('<div>', {
            id: 'll-tools-listening-visualizer',
            class: 'll-tools-loading-animation ll-tools-loading-animation--visualizer',
            'aria-hidden': 'true'
        });
        // Overlay to hide the image until reveal
        const $overlay = $jq('<div>', { class: 'listening-overlay' });
        $ph.append($viz, $overlay);
        $container.append($ph);
        return $ph;
    }

    function runRound(context) {
        const utils = context || {};
        const loader = (utils.FlashcardLoader && typeof utils.FlashcardLoader.loadResourcesForWord === 'function')
            ? utils.FlashcardLoader
            : FlashcardLoader;
        const audioApi = utils.FlashcardAudio || FlashcardAudio || {};
        const audioVisualizer = namespace.AudioVisualizer;
        const resultsApi = utils.Results || Results;
        const $container = utils.flashcardContainer;
        const $jq = getJQuery();

        if (!loader || typeof loader.loadResourcesForWord !== 'function') {
            console.warn('Listening mode loader unavailable');
            return false;
        }

        const target = selectTargetWord();
        if (!target) {
            if (State.isFirstRound) {
                if (typeof utils.showLoadingError === 'function') {
                    utils.showLoadingError();
                } else {
                    State.transitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                    resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                }
                return true;
            }
            State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
            resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
            return true;
        }

        const audioUrl = audioApi && typeof audioApi.selectBestAudio === 'function'
            ? audioApi.selectBestAudio(target, ['isolation', 'question', 'introduction'])
            : null;
        if (audioUrl) target.audio = audioUrl;

        State.isFirstRound = false;

        // Prepare UI: placeholder box + controls inside content area
        if ($container && typeof $container.empty === 'function') {
            $container.empty();
        } else if ($jq) {
            $jq('#ll-tools-flashcard').empty();
        }
        ensureControls(utils);
        const $ph = insertPlaceholder($container || ($jq && $jq('#ll-tools-flashcard')));
        try {
            const viz = namespace.AudioVisualizer;
            if (viz && typeof viz.prepareForListening === 'function') viz.prepareForListening();
        } catch (_) {}
        // Hide header spinner if present; visualizer will target box
        if ($jq) $jq('#ll-tools-loading-animation').hide();
        updateControlsState();

        loader.loadResourcesForWord(target, 'image').then(function () {
            const setAudioPromise = audioApi && typeof audioApi.setTargetWordAudio === 'function'
                ? audioApi.setTargetWordAudio(target)
                : Promise.resolve();

            Promise.resolve(setAudioPromise).catch(function (e) {
                console.warn('No target audio to set:', e);
                if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                    audioVisualizer.stop();
                }
            });

            Dom.disableRepeatButton && Dom.disableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Listening: playing audio');

            const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
                ? audioApi.getCurrentTargetAudio()
                : null;

            // Pre-render hidden image inside placeholder for zero-layout-shift reveal
            try {
                if ($jq && $ph && target && target.image && !$ph.find('img.quiz-image').length) {
                    const $img = $jq('<img>', { src: target.image, alt: target.title || '', class: 'quiz-image' });
                    $img.on('load', function () {
                        const fudge = 10;
                        if (this.naturalWidth > this.naturalHeight + fudge) $ph.addClass('landscape');
                        else if (this.naturalWidth + fudge < this.naturalHeight) $ph.addClass('portrait');
                    });
                    $ph.prepend($img);
                }
            } catch (_) {}

            if (audio) {
                if (audioVisualizer && typeof audioVisualizer.followAudio === 'function') {
                    audioVisualizer.followAudio(audio);
                }
                try {
                    if (!audio.paused && Dom.setRepeatButton) {
                        Dom.setRepeatButton('stop');
                    }
                } catch (_) { /* noop */ }

                audio.onended = function () {
                    const revealTimeoutId = scheduleTimeout(utils, function () {
                        if (State.listeningPaused) { return; }

                        // Reveal by removing the gray overlay; hide visualizer
                        if ($jq) {
                            const $overlay = $ph ? $ph.find('.listening-overlay') : $jq('#ll-tools-flashcard .listening-overlay');
                            if ($overlay.length) {
                                $overlay.fadeOut(200, function () { $jq(this).remove(); });
                            }
                            $jq('#ll-tools-listening-visualizer').fadeOut(150);
                            try { $ph && $ph.addClass('listening-final'); } catch (_) {}
                        }

                        Dom.hideLoading && Dom.hideLoading();
                        Dom.setRepeatButton && Dom.setRepeatButton('play');

                        const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
                        const isLast = total > 0 ? ((State.listenIndex || 0) >= total) : false;

                        const advanceTimeoutId = scheduleTimeout(utils, function () {
                            if (State.listeningPaused) return; // do not advance while paused
                            if (isLast) {
                                if (State.listeningLoop) {
                                    const $jq = getJQuery();
                                    const doRestart = function () {
                                        try {
                                            const Util = (root.LLFlashcards && root.LLFlashcards.Util) || {};
                                            if (Util && typeof Util.randomlySort === 'function') {
                                                State.wordsLinear = Util.randomlySort(State.wordsLinear || []);
                                            }
                                        } catch (_) {}
                                        State.listenIndex = 0;
                                        State.forceTransitionTo(STATES.QUIZ_READY, 'Loop listening');
                                        if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                                    };

                                    // Fade out to white then back in to signal restart
                                    if ($jq) {
                                        const $content = $jq('#ll-tools-flashcard-content');
                                        const $ov = $jq('<div>', { class: 'll-listening-loop-overlay' }).css({ display: 'none' });
                                        $content.append($ov);
                                        $ov.fadeIn(180, function () {
                                            doRestart();
                                            $ov.fadeOut(220, function () { $ov.remove(); });
                                        });
                                    } else {
                                        doRestart();
                                    }
                                } else {
                                    State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                                    resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                                }
                            } else {
                                State.forceTransitionTo(STATES.QUIZ_READY, 'Next listening item');
                                if (typeof utils.runQuizRound === 'function') {
                                    utils.runQuizRound();
                                } else if (typeof utils.startQuizRound === 'function') {
                                    utils.startQuizRound();
                                }
                            }
                        }, 1200);
                        State.addTimeout(advanceTimeoutId);
                    }, 600);
                    State.addTimeout(revealTimeoutId);
                };
                audio.addEventListener('error', function () {
                    if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                        audioVisualizer.stop();
                    }
                }, { once: true });
            } else {
                if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                    audioVisualizer.stop();
                }
                Dom.hideLoading && Dom.hideLoading();
                // Ensure placeholder + image exist
                try {
                    if ($jq && $ph && target && target.image && !$ph.find('img.quiz-image').length) {
                        const $img = $jq('<img>', { src: target.image, alt: target.title || '', class: 'quiz-image' });
                        $ph.prepend($img);
                    }
                    // Reveal immediately
                    const $overlay = $ph ? $ph.find('.listening-overlay') : $jq('#ll-tools-flashcard .listening-overlay');
                    if ($overlay && $overlay.length) $overlay.remove();
                    try { $ph && $ph.addClass('listening-final'); } catch (_) {}
                    $jq && $jq('#ll-tools-listening-visualizer').hide();
                } catch (_) {}
                const timeoutId = scheduleTimeout(utils, function () {
                    State.forceTransitionTo(STATES.QUIZ_READY, 'Advance listening (no audio)');
                    if (typeof utils.runQuizRound === 'function') {
                        utils.runQuizRound();
                    } else if (typeof utils.startQuizRound === 'function') {
                        utils.startQuizRound();
                    }
                }, 1200);
                State.addTimeout(timeoutId);
            }
        }).catch(function (err) {
            console.error('Error in listening run:', err);
            if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                audioVisualizer.stop();
            }
            State.forceTransitionTo(STATES.QUIZ_READY, 'Listening error recovery');
        });
        return true;
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    root.LLFlashcards.Modes.Listening = {
        initialize,
        getChoiceCount,
        recordAnswerResult,
        selectTargetWord,
        onFirstRoundStart,
        onCorrectAnswer,
        onWrongAnswer,
        runRound,
        getTotalCount: function () { return (State.wordsLinear || []).length; }
    };

})(window);
