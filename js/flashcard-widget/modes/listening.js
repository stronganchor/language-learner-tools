(function (root) {
    'use strict';

    const VALID_IMAGE_SIZES = ['small', 'medium', 'large'];

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
    const Dom = namespace.Dom || {};
    const Cards = namespace.Cards || {};
    const Results = namespace.Results || {};
    const Util = namespace.Util || {};
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

    // Match learning-mode pause between repetitions
    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs
        : 800;
    const REPEAT_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.listeningRepeatGapMs === 'number')
        ? root.llToolsFlashcardsData.listeningRepeatGapMs
        : 700;

    function getStarredLookup() {
        const prefs = root.llToolsStudyPrefs || {};
        const ids = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];
        const map = {};
        ids.forEach(function (id) {
            const n = parseInt(id, 10);
            if (n > 0) { map[n] = true; }
        });
        return map;
    }

    function getStarMode() {
        const prefs = root.llToolsStudyPrefs || {};
        const modeFromPrefs = prefs.starMode || prefs.star_mode;
        const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
        const mode = modeFromPrefs || modeFromFlash || 'weighted';
        return mode === 'only' ? 'only' : 'weighted';
    }

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

    // Helper: determine the base practice-mode text card dimensions (e.g., 250x150)
    function getPracticeTextBaseDims() {
        const $jq = getJQuery();
        if (!$jq) return { w: 250, h: 150, ratio: (250 / 150) };
        const $probe = $jq('<div>', { class: 'flashcard-container text-based' })
            .css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'block' })
            .appendTo('body');
        let w = Math.max(1, Math.round($probe.innerWidth()));
        let h = Math.max(1, Math.round($probe.innerHeight()));
        $probe.remove();
        if (!w || !h) { w = 250; h = 150; }
        return { w: w, h: h, ratio: w / h };
    }

    // Helper: apply a slightly larger, but same-aspect, size for text placeholders
    function applyTextPlaceholderSizing($ph) {
        const $jq = getJQuery();
        if (!$jq || !$ph || !$ph.length) return;
        const base = getPracticeTextBaseDims();
        const aspect = base.ratio || (250 / 150);

        // Target a modest scale-up over practice (single-option listening screen)
        const scale = 1.25; // ~25% larger than practice
        let targetWidth = Math.round(base.w * scale);

        try {
            const vw = Math.max(0, (typeof window !== 'undefined' ? window.innerWidth : 0));
            const vh = Math.max(0, (typeof window !== 'undefined' ? window.innerHeight : 0));
            // Cap width to viewport and a sensible desktop max, also respect vertical space
            const maxByVw = Math.floor(vw * 0.92);
            const maxByRule = 700; // match CSS cap used for listening placeholder
            const heightAllowance = Math.max(0, vh - 300); // mirrors CSS calc(100vh - 300px)
            const maxByVh = heightAllowance ? Math.floor(aspect * heightAllowance) : Number.POSITIVE_INFINITY;
            targetWidth = Math.max(1, Math.min(targetWidth, maxByVw || targetWidth, maxByRule, maxByVh));
        } catch (_) { /* no-op */ }

        // Apply width and fixed aspect ratio inline
        $ph.css({ width: targetWidth + 'px', 'aspect-ratio': base.w + ' / ' + base.h });
    }

    const LISTENING_RATIO_MIN = 0.55;
    const LISTENING_RATIO_MAX = 2.5;
    const LISTENING_RATIO_DEFAULT = 1;

    function clampAspectRatio(val) {
        const n = Number(val);
        if (!n || !isFinite(n) || n <= 0) return null;
        return Math.min(LISTENING_RATIO_MAX, Math.max(LISTENING_RATIO_MIN, n));
    }

    function getFallbackAspectRatio(hasImage) {
        const cached = clampAspectRatio(State.listeningLastAspectRatio);
        if (hasImage && cached) return cached;
        const base = getPracticeTextBaseDims();
        const baseRatio = clampAspectRatio(base.ratio || (250 / 150));
        return baseRatio || LISTENING_RATIO_DEFAULT;
    }

    function setFlashcardMinHeight(px) {
        const $jq = getJQuery();
        const h = Math.max(0, Math.round(px || 0));
        if ($jq && h > 0) {
            $jq('#ll-tools-flashcard').css('min-height', h + 'px');
        }
    }

    function refreshPlaceholderMetrics($ph) {
        const $jq = getJQuery();
        if (!$jq || !$ph || !$ph.length) return;
        try {
            const h = Math.round($ph.outerHeight());
            if (h > 0) {
                State.listeningLastHeight = h;
                setFlashcardMinHeight(h);
            }
            const w = Math.max(1, Math.round($ph.innerWidth()));
            const innerH = Math.max(1, Math.round($ph.innerHeight()));
            const ratio = clampAspectRatio(w && innerH ? (w / innerH) : null);
            if (ratio) State.listeningLastAspectRatio = ratio;
        } catch (_) { /* no-op */ }
    }

    function schedulePlaceholderMetrics($ph) {
        if (!$ph || !$ph.length) return;
        const raf = (typeof root !== 'undefined' && root.requestAnimationFrame)
            ? root.requestAnimationFrame
            : function (fn) { return setTimeout(fn, 0); };
        raf(function () { refreshPlaceholderMetrics($ph); });
    }

    // Track a resumable action for the current listening round so pause/play doesn't skip ahead
    const ListeningPlayback = (function () {
        let resumeFn = null;
        let roundToken = 0;

        function startNewRound() {
            roundToken += 1;
            resumeFn = null;
            return roundToken;
        }

        function setResume(fn, token) {
            if (token !== roundToken) return;
            if (typeof fn !== 'function') {
                resumeFn = null;
                return;
            }
            const capturedToken = token;
            resumeFn = function () {
                if (capturedToken !== roundToken) return false;
                fn();
                return true;
            };
        }

        function clear(token) {
            if (typeof token !== 'undefined' && token !== roundToken) return;
            resumeFn = null;
        }

        function resume() {
            if (typeof resumeFn !== 'function') return false;
            const fn = resumeFn;
            resumeFn = null;
            try {
                return !!fn();
            } catch (e) {
                console.error('Listening resume failed:', e);
                return false;
            }
        }

        return {
            startNewRound,
            setResume,
            clear,
            resume,
            getToken: function () { return roundToken; }
        };
    })();

    // Simple wake lock manager (best-effort)
    // Keeps the screen awake while listening mode is actively playing.
    const WakeLock = (function () {
        let sentinel = null;
        let boundVisibilityHandler = null;
        let unsubscribeStateListener = null;

        function isResultsPage() {
            try {
                return (State && State.getFlowState && State.STATES) ?
                    (State.getFlowState() === State.STATES.SHOWING_RESULTS) : false;
            } catch (_) { return false; }
        }

        function shouldHold() {
            try {
                // Hold only while actively playing in listening mode (SHOWING_QUESTION),
                // not paused, and definitely not on results.
                const isPlayingState = (State && State.getFlowState && State.STATES)
                    ? (State.getFlowState() === State.STATES.SHOWING_QUESTION)
                    : false;
                return !!(State && State.isListeningMode && isPlayingState && !State.listeningPaused && !isResultsPage());
            } catch (_) { return false; }
        }

        async function acquire() {
            if (!('wakeLock' in navigator)) return false;
            if (sentinel) return true;
            try {
                sentinel = await navigator.wakeLock.request('screen');
                if (sentinel && typeof sentinel.addEventListener === 'function') {
                    sentinel.addEventListener('release', function () {
                        sentinel = null;
                        // If we still should be holding when a release happens (e.g., tab switch), try to reacquire
                        if (shouldHold()) { acquire().catch(function () { /* no-op */ }); }
                    });
                }
                return true;
            } catch (err) {
                // Permission or unsupported scenario; fail silently
                return false;
            }
        }

        async function release() {
            try {
                if (sentinel && typeof sentinel.release === 'function') {
                    await sentinel.release();
                }
            } catch (_) { /* no-op */ }
            sentinel = null;
            return true;
        }

        async function update() {
            if (shouldHold()) {
                await acquire();
            } else if (sentinel) {
                await release();
            }
        }

        function bind() {
            if (typeof document !== 'undefined' && !boundVisibilityHandler) {
                boundVisibilityHandler = function () {
                    if (!document.hidden) {
                        // On visibility restore, try to reacquire if appropriate
                        update();
                    }
                };
                document.addEventListener('visibilitychange', boundVisibilityHandler);
            }

            if (State && typeof State.onStateChange === 'function' && !unsubscribeStateListener) {
                unsubscribeStateListener = State.onStateChange(function () {
                    // React to results page and other transitions
                    update();
                });
            }
        }

        function unbind() {
            if (boundVisibilityHandler && typeof document !== 'undefined') {
                document.removeEventListener('visibilitychange', boundVisibilityHandler);
            }
            boundVisibilityHandler = null;
            if (typeof unsubscribeStateListener === 'function') {
                try { unsubscribeStateListener(); } catch (_) { }
            }
            unsubscribeStateListener = null;
        }

        return {
            update,
            bind,
            unbind,
            release
        };
    })();

    // Track in-flight category loads so listening mode can wait for all selected categories
    const pendingCategoryLoads = {};

    function hasWordsReady() {
        return Array.isArray(State.wordsLinear) && State.wordsLinear.length > 0;
    }

    function getWordsetCacheKey() {
        const data = root.llToolsFlashcardsData || {};
        const ws = (typeof data.wordset !== 'undefined') ? data.wordset : '';
        const fallback = (typeof data.wordsetFallback === 'undefined') ? true : !!data.wordsetFallback;
        return String(ws || '') + '|' + (fallback ? '1' : '0');
    }

    function getCategoryCacheKey(name) {
        return getWordsetCacheKey() + '::' + String(name || '');
    }

    function rebuildWordsLinear() {
        const all = [];
        if (State.categoryNames && State.wordsByCategory) {
            for (const name of State.categoryNames) {
                const list = State.wordsByCategory[name] || [];
                for (const w of list) {
                    if (!w) continue;
                    try { if (w && !w.__categoryName) { w.__categoryName = name; } } catch (_) { /* no-op */ }
                    all.push(w);
                }
            }
        }

        const starredLookup = getStarredLookup();
        const starMode = getStarMode();
        const copies = [];
        all.forEach(function (w) {
            const isStarred = !!starredLookup[w.id];
            if (starMode === 'only' && !isStarred) return;
            const weight = (starMode === 'weighted' && isStarred) ? 2 : 1;
            for (let i = 0; i < weight; i++) {
                copies.push(w);
            }
        });

        // Build a sequence that honors weighting but avoids back-to-back duplicates when possible
        const seq = [];
        const remaining = copies.slice();
        while (remaining.length) {
            const lastId = seq.length ? seq[seq.length - 1].id : null;
            const nonRepeat = remaining.filter(item => item.id !== lastId);
            const pickFrom = nonRepeat.length ? nonRepeat : remaining;
            const chosen = pickFrom[Math.floor(Math.random() * pickFrom.length)];
            // remove a single instance of chosen
            const idx = remaining.indexOf(chosen);
            if (idx > -1) remaining.splice(idx, 1);
            seq.push(chosen);
        }

        State.wordsLinear = seq;
        State.totalWordCount = (State.wordsLinear || []).length || 0;
        return State.totalWordCount;
    }

    function isCategoryLoaded(name, loader) {
        const words = State.wordsByCategory && State.wordsByCategory[name];
        if (Array.isArray(words) && words.length > 0) return true;
        const loaded = loader && Array.isArray(loader.loadedCategories) ? loader.loadedCategories : [];
        const key = getCategoryCacheKey(name);
        return loaded.includes(key) || loaded.includes(name);
    }

    function ensureCategoryLoad(name, loader) {
        const key = getCategoryCacheKey(name);
        if (!name) return Promise.resolve('');
        if (pendingCategoryLoads[key]) return pendingCategoryLoads[key];
        if (isCategoryLoaded(name, loader)) return Promise.resolve(key);

        const promise = new Promise(function (resolve) {
            try {
                loader.loadResourcesForCategory(name, function () {
                    rebuildWordsLinear();
                    resolve(key);
                }, { earlyCallback: true });
            } catch (_) {
                resolve(key);
            }
        }).finally(function () {
            delete pendingCategoryLoads[key];
        });
        pendingCategoryLoads[key] = promise;
        return promise;
    }

    function queueAllSelectedCategories(loader) {
        if (!loader || typeof loader.loadResourcesForCategory !== 'function') return;
        const names = Array.isArray(State.categoryNames) ? State.categoryNames : [];
        names.forEach(function (name) {
            ensureCategoryLoad(name, loader);
        });
    }

    function hasPendingCategoryLoads(loader) {
        if (pendingCategoryLoads && Object.keys(pendingCategoryLoads).length > 0) return true;
        const names = Array.isArray(State.categoryNames) ? State.categoryNames : [];
        return names.some(function (name) {
            const words = State.wordsByCategory && State.wordsByCategory[name];
            if (Array.isArray(words) && words.length > 0) return false;
            return !isCategoryLoaded(name, loader);
        });
    }

    function waitForPendingCategoryLoads(loader) {
        queueAllSelectedCategories(loader);
        const pending = Object.values(pendingCategoryLoads || {});
        if (!pending.length) return Promise.resolve();
        return Promise.all(pending.map(function (p) { return Promise.resolve(p).catch(() => undefined); })).catch(function () { });
    }

    // Wait until at least one pending category finishes (or a timeout), instead of waiting for all.
    function waitForNextAvailableWords(loader, timeoutMs = 2500) {
        queueAllSelectedCategories(loader);
        if (hasWordsReady()) return Promise.resolve(true);

        const pending = Object.values(pendingCategoryLoads || {});
        if (!pending.length) return Promise.resolve(false);

        return new Promise(function (resolve) {
            let settled = false;
            const done = function (hasWords) {
                if (settled) return;
                settled = true;
                resolve(!!hasWords);
            };
            const checkNow = function () {
                rebuildWordsLinear();
                if (hasWordsReady()) {
                    done(true);
                } else if (!hasPendingCategoryLoads(loader)) {
                    done(false);
                }
            };

            pending.forEach(function (p) {
                Promise.resolve(p).then(checkNow).catch(checkNow);
            });

            setTimeout(function () {
                checkNow();
                if (!settled) done(hasWordsReady());
            }, Math.max(500, timeoutMs));

            // Safety in case nothing resolves
            setTimeout(function () { if (!settled) done(hasWordsReady()); }, Math.max(800, timeoutMs + 500));
            checkNow();
        });
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = true;
        State.listeningPaused = false;
        State.lastWordShownId = null;
        try { ListeningPlayback.clear(); } catch (_) { }
        State.listeningLoop = State.listeningLoop === true; // preserve if toggled previously during session
        Object.keys(pendingCategoryLoads).forEach(function (k) { delete pendingCategoryLoads[k]; });
        rebuildWordsLinear();
        queueAllSelectedCategories(FlashcardLoader);
        State.listenIndex = 0;
        // Start listening for state/visibility changes and ensure proper wake lock state
        try { WakeLock.bind(); WakeLock.update(); } catch (_) { }
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
        if (!Array.isArray(State.wordsLinear)) {
            rebuildWordsLinear();
            State.listenIndex = 0;
        }
        if (!State.wordsLinear.length) return null;

        let attempts = 0;
        let word = State.wordsLinear[State.listenIndex % State.wordsLinear.length];
        while (
            State.wordsLinear.length > 1 &&
            attempts < State.wordsLinear.length &&
            word &&
            State.lastWordShownId === word.id
        ) {
            State.listenIndex++;
            attempts++;
            word = State.wordsLinear[State.listenIndex % State.wordsLinear.length];
        }

        State.listenIndex++;
        if (word && word.id) {
            State.lastWordShownId = word.id;
        }
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
                const $viz = $jq('#ll-tools-listening-visualizer');
                const countdownActive = $viz.length && ($viz.hasClass('countdown-active') || $viz.find('.ll-tools-listening-countdown').length > 0);
                const isPlaying = $jq(this).attr('data-state') === 'playing';
                State.listeningPaused = isPlaying;
                if (isPlaying) {
                    // Pause
                    try { audio && audio.pause && audio.pause(); } catch (_) {}
                    State.clearActiveTimeouts();
                    $jq(this).attr('data-state', 'paused').html(playSVG);
                    try { WakeLock.update(); } catch (_) { }
                } else {
                    // Resume
                    $jq(this).attr('data-state', 'playing').html(pauseSVG);
                    const resumedFlow = ListeningPlayback.resume();
                    let resumedAudio = false;
                    if (!countdownActive && audio && audio.paused && !audio.ended) {
                        resumedAudio = true;
                        const p = audio.play();
                        if (p && typeof p.catch === 'function') p.catch(() => {});
                    }
                    if (!resumedFlow && !resumedAudio) {
                        // If nothing to resume, restart the current word instead of skipping ahead
                        const $overlay = $jq('#ll-tools-flashcard .listening-overlay');
                        if ($overlay.length) {
                            $overlay.fadeOut(200, function () { $jq(this).remove(); });
                        }
                        ListeningPlayback.clear();
                        State.listenIndex = Math.max(0, (State.listenIndex || 1) - 1);
                        State.forceTransitionTo(STATES.QUIZ_READY, 'Listening resume restart');
                        if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                    }
                    try { WakeLock.update(); } catch (_) { }
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
                try { WakeLock.update(); } catch (_) { }
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
                try { WakeLock.update(); } catch (_) { }
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
        // For listening mode, we want a single large, responsive square.
        // Keep 'text-based' for font styling, but avoid fixed flashcard-size classes.
        if (options.textBased) { baseClasses.push('text-based'); }
        const $ph = $jq('<div>', {
            class: baseClasses.join(' '),
            css: { display: 'flex' }
        });
        if (options.aspectRatio) {
            const ar = clampAspectRatio(options.aspectRatio);
            if (ar) $ph.css('aspect-ratio', ar);
        }
        // For text-only categories, size the placeholder slightly larger than practice,
        // but maintain the same aspect ratio as the practice text card.
        if (options.textBased) {
            applyTextPlaceholderSizing($ph);
        }
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

        // Measure text to fit within the placeholder box using the same aspect ratio
        // as practice mode's text card, but scaled slightly larger for listening.
        const base = getPracticeTextBaseDims();
        const ratio = base.ratio || (250 / 150);
        const phW = Math.max(1, Math.round($ph.innerWidth()));
        const phH = Math.max(1, Math.round(phW / ratio));

        // Off-DOM measurement container with constrained width to the placeholder width
        const $measure = $jq('<div>')
            .css({ position: 'absolute', top: -9999, left: -9999, visibility: 'hidden', display: 'block', width: phW + 'px' });
        const $measureLabel = $label.clone().appendTo($measure);
        $jq('body').append($measure);
        try {
            const boxH = phH - 15;
            const boxW = phW - 15;
            const fontFamily = getComputedStyle($measureLabel[0]).fontFamily || 'sans-serif';
            // Start a bit larger than standard text mode to make listening text more prominent
            for (let fs = 56; fs >= 14; fs--) {
                const w = (namespace.Util && typeof namespace.Util.measureTextWidth === 'function')
                    ? namespace.Util.measureTextWidth(labelText || '', fs + 'px ' + fontFamily)
                    : null;
                if (w && w > boxW) continue;
                $measureLabel.css({ fontSize: fs + 'px', lineHeight: fs + 'px', visibility: 'visible', position: 'relative', maxWidth: boxW + 'px' });
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
        const roundToken = ListeningPlayback.startNewRound();
        const setRoundResume = function (fn) { ListeningPlayback.setResume(fn, roundToken); };

        if ($jq) {
            $jq('#ll-tools-prompt').hide();
        }

        if ($jq && State.listeningLastHeight > 0) {
            setFlashcardMinHeight(State.listeningLastHeight);
        }

        if (!loader || typeof loader.loadResourcesForWord !== 'function') {
            console.warn('Listening mode loader unavailable');
            try { WakeLock.update(); } catch (_) { }
            return false;
        }

        // Build the listening sequence once per session; don't reshuffle every round
        if (!Array.isArray(State.wordsLinear) || !State.wordsLinear.length) {
            rebuildWordsLinear();
        }
        queueAllSelectedCategories(loader);

        if ((!State.wordsLinear || !State.wordsLinear.length) && hasPendingCategoryLoads(loader)) {
            if (Dom && typeof Dom.showLoading === 'function') Dom.showLoading();
            waitForNextAvailableWords(loader).then(function (hasWords) {
                rebuildWordsLinear();
                if (!hasWords || !State.wordsLinear || !State.wordsLinear.length) {
                    State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                    resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                    try { WakeLock.update(); } catch (_) { }
                    return;
                }
                State.forceTransitionTo(STATES.QUIZ_READY, 'Listening data loaded');
                if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                else if (typeof utils.startQuizRound === 'function') utils.startQuizRound();
            });
            try { WakeLock.update(); } catch (_) { }
            return true;
        }

        const target = selectTargetWord();
        State.listeningCurrentTarget = target || null;
        if (!target) {
            if (hasPendingCategoryLoads(loader)) {
                if (Dom && typeof Dom.showLoading === 'function') Dom.showLoading();
                waitForNextAvailableWords(loader).then(function (hasWords) {
                    rebuildWordsLinear();
                    if (!hasWords || !State.wordsLinear || !State.wordsLinear.length) {
                        State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                        resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                        try { WakeLock.update(); } catch (_) { }
                        return;
                    }
                    State.forceTransitionTo(STATES.QUIZ_READY, 'Listening delayed until data ready');
                    if (typeof utils.runQuizRound === 'function') utils.runQuizRound();
                    else if (typeof utils.startQuizRound === 'function') utils.startQuizRound();
                });
                return true;
            }
            if (State.isFirstRound) {
                if (typeof utils.showLoadingError === 'function') {
                    utils.showLoadingError();
                } else {
                    State.transitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                    resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                }
                try { WakeLock.update(); } catch (_) { }
                return true;
            }
            State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
            resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
            try { WakeLock.update(); } catch (_) { }
            return true;
        }

        // Update category header to reflect the current word's category
        (function updateHeaderForCurrentTarget() {
            try {
                let cat = (target && target.__categoryName) ? target.__categoryName : null;
                if (!cat && State && Array.isArray(State.categoryNames)) {
                    for (const name of State.categoryNames) {
                        const list = (State.wordsByCategory && State.wordsByCategory[name]) || [];
                        if (list && list.some(w => w && w.id === target.id)) { cat = name; break; }
                    }
                }
                if (cat) {
                    State.currentCategoryName = cat;
                    State.currentCategory = (State.wordsByCategory && State.wordsByCategory[cat]) || [];
                    if (Dom && typeof Dom.updateCategoryNameDisplay === 'function') {
                        Dom.updateCategoryNameDisplay(cat);
                    }
                }
            } catch (_) { /* no-op */ }
        })();

        try { WakeLock.update(); } catch (_) { }

        const selectionApi = root.LLFlashcards && root.LLFlashcards.Selection;
        const categoryConfig = selectionApi && typeof selectionApi.getCategoryConfig === 'function'
            ? selectionApi.getCategoryConfig(State.currentCategoryName || (target && target.__categoryName))
            : { prompt_type: 'audio', option_type: 'image' };
        const optionType = categoryConfig.option_type || 'image';
        const promptType = categoryConfig.prompt_type || 'audio';
        State.currentOptionType = optionType;
        State.currentPromptType = promptType;

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
        const promptIsImage = (promptType === 'image' && hasImage);
        const optionHasAudio = (optionType === 'audio' || optionType === 'text_audio' || promptType === 'audio');
        const showAnswerText = (/^text/.test(String(optionType || '')));
        const shouldUseVisualizerText = (!optionHasAudio && promptIsImage && showAnswerText);
        const answerLabel = target.label || target.title || '';
        const placeholderAspect = hasImage ? getFallbackAspectRatio(true) : null;
        // Build a wrapper so placeholder and visualizer act as a single flex item
        let $stack = null;
        if ($jq) {
            $stack = $jq('<div>', { class: 'listening-stack' });
        }
        const $ph = insertPlaceholder(
            $stack || ($container || ($jq && $jq('#ll-tools-flashcard'))),
            { textBased: !hasImage, aspectRatio: placeholderAspect }
        );
        if ($ph && promptIsImage) {
            try { $ph.find('.listening-overlay').remove(); } catch (_) { /* no-op */ }
        }
        if (placeholderAspect) {
            const ar = clampAspectRatio(placeholderAspect);
            if (ar) State.listeningLastAspectRatio = ar;
        }
        // Text strip between prompt and visualizer for audio+text listening
        let $text = null;
        if ($jq) {
            $jq('#ll-tools-listening-text').remove();
            if (showAnswerText && !shouldUseVisualizerText && !optionHasAudio) {
                $text = $jq('<div>', {
                    id: 'll-tools-listening-text',
                    class: 'listening-text',
                    text: answerLabel
                });
                $text.css('opacity', 0);
                ($stack || $container || $jq('#ll-tools-flashcard')).append($text);
            }
        }

        // Add a dedicated visualizer element BELOW the image/placeholder and ABOVE the controls
        let $viz = null;
        if ($jq) {
            // Remove any existing instance (fresh per round)
            $jq('#ll-tools-listening-visualizer').remove();
            $viz = $jq('<div>', {
                id: 'll-tools-listening-visualizer',
                class: 'll-tools-loading-animation ll-tools-loading-animation--visualizer',
                'aria-hidden': 'true'
            });
            // Reserve space by inserting the visualizer container even if hidden
            if (!optionHasAudio) {
                $viz.css('visibility', 'hidden');
            }
            if ($stack) {
                $stack.append($viz);
                ($container || $jq('#ll-tools-flashcard')).append($stack);
            } else {
                // Insert visualizer just after the placeholder box
                ($ph && typeof $ph.after === 'function') ? $ph.after($viz) : ($jq('#ll-tools-flashcard').append($viz));
            }
        }
        schedulePlaceholderMetrics($ph);
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

        loader.loadResourcesForWord(target, optionType, State.currentCategoryName, categoryConfig).then(function () {
            // Pre-render content inside placeholder for zero-layout-shift reveal
            try {
                if ($jq && $ph && target && !$ph.find('.quiz-image, .quiz-text').length) {
                    if (hasImage) {
                        const $img = $jq('<img>', { src: target.image, alt: '', 'aria-hidden': 'true', class: 'quiz-image' });
                        $img.on('load', function () {
                            const fudge = 10;
                            if (this.naturalWidth > this.naturalHeight + fudge) $ph.addClass('landscape');
                            else if (this.naturalWidth + fudge < this.naturalHeight) $ph.addClass('portrait');

                            // Set exact aspect ratio based on the loaded image dimensions
                            try {
                                const nw = Math.max(1, this.naturalWidth || 0);
                                const nh = Math.max(1, this.naturalHeight || 0);
                                if (nw && nh) {
                                    const exact = clampAspectRatio(nw / nh);
                                    if (exact) {
                                        $ph.css('aspect-ratio', exact);
                                        State.listeningLastAspectRatio = exact;
                                    } else {
                                        $ph.css('aspect-ratio', nw + ' / ' + nh);
                                    }
                                }
                            } catch (_) { /* no-op */ }
                            schedulePlaceholderMetrics($ph);
                        });
                        $ph.prepend($img);
                    } else {
                        renderTextIntoPlaceholder($ph, target.label || target.title || '');
                        schedulePlaceholderMetrics($ph);
                    }
                }
            } catch (_) {}

            Dom.disableRepeatButton && Dom.disableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Listening: playing audio');

            // Determine sequence. For image->audio (or audio+text), play intro then isolation only.
            const isoUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                ? audioApi.selectBestAudio(target, ['isolation']) : null;
            const introUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                ? audioApi.selectBestAudio(target, ['introduction']) : null;

            let sequence = [];
            const isImageAudioFlow = hasImage && (optionType === 'audio' || optionType === 'text_audio');
            const isAudioPromptFlow = (!promptIsImage && promptType === 'audio');
            if (isAudioPromptFlow) {
                const isolationClip = isoUrl || introUrl || ((audioApi && typeof audioApi.selectBestAudio === 'function')
                    ? audioApi.selectBestAudio(target, ['question'])
                    : (target && target.audio) || null);
                const introClip = introUrl || isolationClip;
                if (isolationClip) sequence.push(isolationClip);
                if (introClip) sequence.push(introClip);
                if (isolationClip) sequence.push(isolationClip);
                // Fall back to at least two plays
                if (sequence.length < 2 && isolationClip) sequence.push(isolationClip);
            } else {
                if (isoUrl && introUrl && isoUrl !== introUrl) {
                    sequence = [isoUrl, introUrl];
                } else if (introUrl) {
                    sequence = [introUrl, introUrl];
                } else if (isoUrl) {
                    sequence = [isoUrl, isoUrl];
                } else {
                    const fallbackUrl = (audioApi && typeof audioApi.selectBestAudio === 'function')
                        ? audioApi.selectBestAudio(target, ['question'])
                        : (target && target.audio) || null;
                    if (fallbackUrl) sequence = [fallbackUrl, fallbackUrl];
                }
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

            const scheduleAdvance = function (delayMs) {
                const advanceTimeoutId = scheduleTimeout(utils, function () {
                    if (State.listeningPaused) {
                        setRoundResume(function () { scheduleAdvance(delayMs); });
                        return;
                    }
                    const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
                    const atEnd = total > 0 ? ((State.listenIndex || 0) >= total) : true;
                    const pending = hasPendingCategoryLoads(loader);

                    if (atEnd && pending) {
                        if (Dom && typeof Dom.showLoading === 'function') Dom.showLoading();
                        waitForNextAvailableWords(loader).then(function (hasWords) {
                            rebuildWordsLinear();
                            const hasMore = hasWords && (State.wordsLinear || []).length > 0 && (State.listenIndex || 0) < (State.wordsLinear || []).length;
                            if (!hasMore && !hasPendingCategoryLoads(loader)) {
                                State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                                resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                                try { WakeLock.update(); } catch (_) { }
                                return;
                            }
                            State.forceTransitionTo(STATES.QUIZ_READY, 'Listening continue after preload');
                            if (typeof utils.runQuizRound === 'function') {
                                utils.runQuizRound();
                            } else if (typeof utils.startQuizRound === 'function') {
                                utils.startQuizRound();
                            }
                        });
                        return;
                    }

                    if (atEnd) {
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
                }, (typeof delayMs === 'number') ? delayMs : 800);
                State.addTimeout(advanceTimeoutId);
                setRoundResume(function () { scheduleAdvance(delayMs); });
            };

            const showVisualizerAnswerText = function () {
                if (!$jq || !$viz || !$viz.length || !shouldUseVisualizerText) return;
                let $label = $viz.find('.listening-visualizer-text');
                if (!$label.length) {
                    $label = $jq('<div>', { class: 'listening-visualizer-text', text: answerLabel });
                    $viz.append($label);
                } else {
                    $label.text(answerLabel);
                }
                $label.css('opacity', 1);
                $viz.css('visibility', 'visible');
            };

            const maybeShowAnswerText = function () {
                if (!$jq || !$ph || !showAnswerText) return;
                if (optionType === 'text') {
                    $ph.empty();
                }
                if (shouldUseVisualizerText) {
                    showVisualizerAnswerText();
                } else {
                    const existingText = $ph.find('.quiz-text');
                    if (existingText.length) {
                        existingText.text(answerLabel);
                        schedulePlaceholderMetrics($ph);
                    } else {
                        renderTextIntoPlaceholder($ph, answerLabel);
                        schedulePlaceholderMetrics($ph);
                    }
                }
            };

            // Countdown helper: show 3-2-1 to the side of the prompt
            const startCountdown = function () {
                return new Promise(function (resolve) {
                    if (!$jq) { resolve(); return; }
                    let $vizEl = ($viz && $viz.length) ? $viz : $jq('#ll-tools-listening-visualizer');
                    if (!$vizEl.length) {
                        $vizEl = $jq('<div>', { id: 'll-tools-listening-visualizer', class: 'll-tools-loading-animation ll-tools-loading-animation--visualizer' });
                        ($stack || $jq('#ll-tools-flashcard')).append($vizEl);
                    }
                    $viz = $vizEl;
                    $vizEl.addClass('countdown-active').css('visibility', 'visible');
                    const $bars = $vizEl.find('.ll-tools-visualizer-bar');
                    if ($bars && $bars.length) { $bars.css({ opacity: 0, display: 'none' }); }

                    let $cd = $vizEl.find('.ll-tools-listening-countdown');
                    if (!$cd.length) {
                        $cd = $jq('<div>', { class: 'll-tools-listening-countdown listening-countdown' });
                        $vizEl.append($cd);
                    }
                    const countdownState = { current: 3, done: false };
                    const render = function () {
                        $cd.empty().append($jq('<span>', { class: 'digit', text: String(countdownState.current) }));
                    };
                    const finish = function () {
                        if (countdownState.done) return;
                        countdownState.done = true;
                        setRoundResume(null);
                        if ($cd && $cd.length) { $cd.remove(); }
                        if ($vizEl && $vizEl.length) {
                            $vizEl.removeClass('countdown-active');
                            if ($bars && $bars.length) { $bars.css({ opacity: optionHasAudio ? 1 : 0, display: optionHasAudio ? '' : 'none' }); }
                            if (!optionHasAudio && !shouldUseVisualizerText) { $vizEl.css('visibility', 'hidden'); }
                        }
                        resolve();
                    };
                    const scheduleStep = function (delayMs) {
                        const delay = (typeof delayMs === 'number') ? delayMs : 900;
                        setRoundResume(function () { scheduleStep(delay); });
                        const tid = scheduleTimeout(utils, function () {
                            if (State.listeningPaused) {
                                setRoundResume(function () { scheduleStep(delay); });
                                return;
                            }
                            countdownState.current -= 1;
                            if (countdownState.current <= 0) {
                                const finTid = scheduleTimeout(utils, finish, 200);
                                State.addTimeout && State.addTimeout(finTid);
                                setRoundResume(function () { finish(); });
                                return;
                            }
                            render();
                            scheduleStep(900);
                        }, delay);
                        State.addTimeout && State.addTimeout(tid);
                    };
                    render();
                    scheduleStep(900);
                });
            };

            const playSequenceFrom = function (idx) {
                if (!sequence.length || idx >= sequence.length) {
                    const finishSequence = function () {
                        Dom.setRepeatButton && Dom.setRepeatButton('play');
                        scheduleAdvance();
                    };
                    const t = scheduleTimeout(utils, finishSequence, 300);
                    State.addTimeout(t);
                    setRoundResume(function () {
                        const tid = scheduleTimeout(utils, finishSequence, 200);
                        State.addTimeout && State.addTimeout(tid);
                    });
                    return;
                }
                setAndPlayUntilEnd(sequence[idx]).then(function () {
                    const isTwoClipImageAudio = (sequence.length === 2 && isImageAudioFlow);
                    const delay = isTwoClipImageAudio
                        ? INTRO_GAP_MS
                        : (isAudioPromptFlow && idx === 1) ? REPEAT_GAP_MS
                            : (idx === 1) ? INTRO_GAP_MS : 150; // default small gap
                    const goNext = function () {
                        if (State.listeningPaused) {
                            setRoundResume(function () { playSequenceFrom(idx + 1); });
                            return;
                        }
                        playSequenceFrom(idx + 1);
                    };
                    const t = scheduleTimeout(utils, goNext, delay);
                    State.addTimeout(t);
                    setRoundResume(function () { playSequenceFrom(idx + 1); });
                }).catch(function () { playSequenceFrom(idx + 1); });
            };

            const afterCountdown = function () {
                if (State.listeningPaused) {
                    setRoundResume(function () { afterCountdown(); });
                    return;
                }
                if (showAnswerText && $jq) {
                    if (shouldUseVisualizerText) {
                        showVisualizerAnswerText();
                    } else {
                        const $t = $jq('#ll-tools-listening-text');
                        if ($t.length) { $t.css('opacity', 1); }
                    }
                }
                revealContent();
                if (!optionHasAudio && $jq && !shouldUseVisualizerText) {
                    $jq('#ll-tools-listening-visualizer').hide();
                }
                if (optionHasAudio && sequence.length) {
                    playSequenceFrom(0);
                } else {
                    const finishNoAudio = function () {
                        Dom.setRepeatButton && Dom.setRepeatButton('play');
                        scheduleAdvance();
                    };
                    const t = scheduleTimeout(utils, finishNoAudio, 600);
                    State.addTimeout(t);
                    setRoundResume(function () {
                        const tid = scheduleTimeout(utils, finishNoAudio, 250);
                        State.addTimeout && State.addTimeout(tid);
                    });
                }
            };

            if (promptIsImage || !sequence.length) {
                const $viz = $jq ? $jq('#ll-tools-listening-visualizer') : null;
                if ($viz && $viz.length) {
                    const $bars = $viz.find('.ll-tools-visualizer-bar');
                    if ($bars && $bars.length) { $bars.css({ opacity: 0, display: 'none' }); }
                }
                const launchCountdown = function () {
                    setRoundResume(null);
                    startCountdown().then(afterCountdown);
                };
                const delayBeforeCountdown = scheduleTimeout(utils, launchCountdown, 700);
                State.addTimeout && State.addTimeout(delayBeforeCountdown);
                setRoundResume(function () {
                    const tid = scheduleTimeout(utils, launchCountdown, 250);
                    State.addTimeout && State.addTimeout(tid);
                });
            } else {
                // Audio-first flow: play once, then countdown, then finish sequence
                setAndPlayUntilEnd(sequence[0]).catch(function () { }).then(function () {
                    return startCountdown();
                }).then(function () {
                    const afterAudioPrompt = function () {
                        if (State.listeningPaused) {
                            setRoundResume(function () { afterAudioPrompt(); });
                            return;
                        }
                        if (showAnswerText) {
                            maybeShowAnswerText();
                        }
                        revealContent();
                        playSequenceFrom(1);
                    };
                    afterAudioPrompt();
                });
            }
        }).catch(function (err) {
            console.error('Error in listening run:', err);
            if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                audioVisualizer.stop();
            }
            State.forceTransitionTo(STATES.QUIZ_READY, 'Listening error recovery');
            try { WakeLock.update(); } catch (_) { }
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
