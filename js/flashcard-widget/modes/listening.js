(function (root) {
    'use strict';

    const VALID_IMAGE_SIZES = ['small', 'medium', 'large'];

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
    const Dom = namespace.Dom || {};
    const Cards = namespace.Cards || {};
    const Results = namespace.Results || {};
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

    // Match learning-mode pause between repetitions
    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs
        : 800;

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

    function insertPlaceholder($container, opts) {
        const $jq = getJQuery();
        if (!$jq) return null;
        if (!$container || typeof $container.append !== 'function') return null;
        const options = opts || {};
        const baseClasses = ['flashcard-container', 'listening-placeholder'];
        // If we know this item is text-based, match the text card sizing; otherwise use configured image size
        if (options.textBased) {
            baseClasses.push('text-based');
        } else {
            const data = root.llToolsFlashcardsData || {};
            const configuredSize = (data && typeof data.imageSize === 'string') ? data.imageSize : 'small';
            const safeSize = VALID_IMAGE_SIZES.includes(configuredSize) ? configuredSize : 'small';
            baseClasses.push('flashcard-size-' + safeSize);
        }
        const $ph = $jq('<div>', {
            class: baseClasses.join(' '),
            css: { display: 'flex' }
        });
        // Overlay to hide visual content until reveal
        const $overlay = $jq('<div>', { class: 'listening-overlay' });
        $ph.append($overlay);
        $container.append($ph);
        return $ph;
    }

    // Insert a dynamically sized text label into the placeholder
    function renderTextIntoPlaceholder($ph, labelText) {
        const $jq = getJQuery();
        if (!$jq || !$ph || !$ph.length) return null;
        const $label = $jq('<div>', { text: labelText || '', class: 'quiz-text' });

        // Measure text to fit within the placeholder box
        // Use an off-DOM clone to avoid overlay/visualizer interference
        const $measure = $jq('<div>', { class: $ph.attr('class') })
            .css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'block' });
        const $measureLabel = $label.clone().appendTo($measure);
        $jq('body').append($measure);
        try {
            const boxH = $measure.innerHeight() - 15;
            const boxW = $measure.innerWidth() - 15;
            const fontFamily = getComputedStyle($measureLabel[0]).fontFamily || 'sans-serif';
            // Start a bit larger than standard text mode to make listening text more prominent
            for (let fs = 56; fs >= 14; fs--) {
                const w = (namespace.Util && typeof namespace.Util.measureTextWidth === 'function')
                    ? namespace.Util.measureTextWidth(labelText || '', fs + 'px ' + fontFamily)
                    : null;
                if (w && w > boxW) continue;
                $measureLabel.css({ fontSize: fs + 'px', lineHeight: fs + 'px', visibility: 'visible', position: 'relative' });
                if ($measureLabel.outerHeight() <= boxH) break;
            }
            // Apply the measured styles to the real label
            const styles = {
                fontSize: $measureLabel.css('font-size'),
                lineHeight: $measureLabel.css('line-height')
            };
            $label.css(styles);
        } catch (_) { /* no-op */ }
        $measure.remove();

        $ph.prepend($label);
        return $label;
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
        const hasImage = !!(target && target.image);
        // Build a wrapper so placeholder and visualizer act as a single flex item
        let $stack = null;
        if ($jq) {
            $stack = $jq('<div>', { class: 'listening-stack' });
        }
        const $ph = insertPlaceholder($stack || ($container || ($jq && $jq('#ll-tools-flashcard'))), { textBased: !hasImage });
        // Add a dedicated visualizer element BELOW the image/placeholder and ABOVE the controls
        if ($jq) {
            // Remove any existing instance (fresh per round)
            $jq('#ll-tools-listening-visualizer').remove();
            const $viz = $jq('<div>', {
                id: 'll-tools-listening-visualizer',
                class: 'll-tools-loading-animation ll-tools-loading-animation--visualizer',
                'aria-hidden': 'true'
            });
            if ($stack) {
                $stack.append($viz);
                ($container || $jq('#ll-tools-flashcard')).append($stack);
            } else {
                // Insert visualizer just after the placeholder box
                ($ph && typeof $ph.after === 'function') ? $ph.after($viz) : ($jq('#ll-tools-flashcard').append($viz));
            }
        }
        // Prepare the visualizer now that the element exists
        try {
            const viz = namespace.AudioVisualizer;
            if (viz && typeof viz.prepareForListening === 'function') viz.prepareForListening();
        } catch (_) {}
        // Hide header spinner if present
        if ($jq) $jq('#ll-tools-loading-animation').hide();
        // Ensure controls appear at the bottom (after placeholder and visualizer)
        ensureControls(utils);
        updateControlsState();

        loader.loadResourcesForWord(target, 'image').then(function () {
            // Pre-render hidden content inside placeholder for zero-layout-shift reveal
            try {
                if ($jq && $ph && target && !$ph.find('.quiz-image, .quiz-text').length) {
                    if (hasImage) {
                        const $img = $jq('<img>', { src: target.image, alt: target.title || '', class: 'quiz-image' });
                        $img.on('load', function () {
                            const fudge = 10;
                            if (this.naturalWidth > this.naturalHeight + fudge) $ph.addClass('landscape');
                            else if (this.naturalWidth + fudge < this.naturalHeight) $ph.addClass('portrait');
                        });
                        $ph.prepend($img);
                    } else {
                        renderTextIntoPlaceholder($ph, target.label || target.title || '');
                    }
                }
            } catch (_) {}

            Dom.disableRepeatButton && Dom.disableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Listening: playing audio');

            // Determine sequence: isolation -> introduction -> isolation when both available
            // Otherwise, use whatever is available 3 times. We always begin with a brief
            // countdown inside the gray box before revealing.
            const isoUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                ? audioApi.selectBestAudio(target, ['isolation']) : null;
            const introUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                ? audioApi.selectBestAudio(target, ['introduction']) : null;

            let sequence = [];
            if (isoUrl && introUrl && isoUrl !== introUrl) {
                sequence = [isoUrl, introUrl, isoUrl];
            } else if (introUrl) {
                sequence = [introUrl, introUrl, introUrl];
            } else if (isoUrl) {
                sequence = [isoUrl, isoUrl, isoUrl];
            } else {
                const fallbackUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                    ? audioApi.selectBestAudio(target, ['question'])
                    : (target && target.audio) || null;
                if (fallbackUrl) sequence = [fallbackUrl, fallbackUrl, fallbackUrl];
            }

            const followCurrentTarget = function () {
                const a = (audioApi && typeof audioApi.getCurrentTargetAudio === 'function')
                    ? audioApi.getCurrentTargetAudio() : null;
                if (a && audioVisualizer && typeof audioVisualizer.followAudio === 'function') {
                    audioVisualizer.followAudio(a);
                }
                try { if (a && !a.paused && Dom.setRepeatButton) Dom.setRepeatButton('stop'); } catch (_) {}
                return a;
            };

            const setAndPlayUntilEnd = function (url) {
                target.audio = url;
                const p = (audioApi && typeof audioApi.setTargetWordAudio === 'function')
                    ? audioApi.setTargetWordAudio(target)
                    : Promise.resolve();
                return Promise.resolve(p).then(function () {
                    const a = followCurrentTarget();
                    return new Promise(function (resolve) {
                        if (!a) { resolve(); return; }
                        a.onended = resolve;
                        a.addEventListener('error', resolve, { once: true });
                    });
                }).catch(function (e) {
                    console.warn('Listening: failed to set/play audio', e);
                    return Promise.resolve();
                });
            };

            const revealContent = function () {
                if (!$jq) return;
                const $overlay = $ph ? $ph.find('.listening-overlay') : $jq('#ll-tools-flashcard .listening-overlay');
                if ($overlay.length) {
                    $overlay.fadeOut(200, function () { $jq(this).remove(); });
                }
                try { $ph && $ph.addClass('listening-final'); } catch (_) {}
                Dom.hideLoading && Dom.hideLoading();
            };

            const scheduleAdvance = function () {
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
                }, 800);
                State.addTimeout(advanceTimeoutId);
            };

            // Countdown helper: show 3-2-1 inside overlay
            const startCountdown = function () {
                return new Promise(function (resolve) {
                    if (!$jq) { resolve(); return; }
                    const $overlay = $ph ? $ph.find('.listening-overlay') : $jq('#ll-tools-flashcard .listening-overlay');
                    if (!$overlay.length) { resolve(); return; }
                    let $cd = $overlay.find('.listening-countdown');
                    if (!$cd.length) {
                        $cd = $jq('<div>', { class: 'listening-countdown' });
                        $overlay.append($cd);
                    }
                    let n = 3;
                    const render = function () {
                        $cd.empty().append($jq('<span>', { class: 'digit', text: String(n) }));
                    };
                    render();
                    // Tick every ~900ms for a snappy feel
                    const step = function () {
                        if (State.listeningPaused) { resolve(); return; }
                        n -= 1;
                        if (n <= 0) {
                            // Brief hold on 1 then resolve
                            const tid = scheduleTimeout(utils, function () { resolve(); }, 350);
                            State.addTimeout && State.addTimeout(tid);
                        } else {
                            render();
                            const tid = scheduleTimeout(utils, step, 900);
                            State.addTimeout && State.addTimeout(tid);
                        }
                    };
                    const tid = scheduleTimeout(utils, step, 900);
                    State.addTimeout && State.addTimeout(tid);
                });
            };

            // Play a sequence of up to 3 audios. Always begin with a countdown while the
            // first audio (if any) is playing; reveal only when the countdown completes
            // and the first audio ends.
            if (!sequence.length) {
                // Nothing to play: just countdown then reveal
                startCountdown().then(function () {
                    revealContent();
                    const t = scheduleTimeout(utils, scheduleAdvance, 600);
                    State.addTimeout(t);
                });
            } else {
                // First, play the first audio. After it ends, run the countdown.
                setAndPlayUntilEnd(sequence[0]).catch(function () { }).then(function () {
                    return startCountdown();
                }).then(function () {
                    if (State.listeningPaused) return;
                    revealContent();
                    // Play remaining items sequentially with a learning-mode sized gap before the 3rd
                    const playRest = function (idx) {
                        if (idx >= sequence.length) {
                            const t = scheduleTimeout(utils, function () {
                                Dom.setRepeatButton && Dom.setRepeatButton('play');
                                scheduleAdvance();
                            }, 300);
                            State.addTimeout(t);
                            return;
                        }
                        setAndPlayUntilEnd(sequence[idx]).then(function () {
                            const delay = (idx === 1) ? INTRO_GAP_MS : 150; // gap between 2nd->3rd
                            const t = scheduleTimeout(utils, function () {
                                if (State.listeningPaused) return;
                                playRest(idx + 1);
                            }, delay);
                            State.addTimeout(t);
                        }).catch(function () { playRest(idx + 1); });
                    };
                    playRest(1);
                });
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
